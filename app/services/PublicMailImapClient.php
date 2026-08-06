<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailImapClient.php
 *
 * PHP IMAP 확장 없이 네이버 IMAP 서버와 직접 통신합니다.
 * BODY.PEEK만 사용하므로 네이버 원본메일의 읽음 상태를 바꾸지 않습니다.
 * PHP 5.6 호환 코드입니다.
 */

namespace App\Services;

class PublicMailImapClient
{
    private $host;
    private $port;
    private $timeout;
    private $socket;
    private $tagNumber;
    private $selectedMailbox;

    public function __construct($host, $port, $timeout)
    {
        $this->host = trim((string)$host);
        $this->port = (int)$port;
        $this->timeout = max(4, (int)$timeout);
        $this->socket = null;
        $this->tagNumber = 1;
        $this->selectedMailbox = '';
    }

    public function connect()
    {
        if (!extension_loaded('openssl')) throw new \RuntimeException('PHP OpenSSL 기능을 사용할 수 없습니다.');
        $context = stream_context_create(array('ssl' => array(
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $this->host, 'SNI_enabled' => true
        )));
        $errorNumber = 0; $errorMessage = '';
        $socket = @stream_socket_client('ssl://' . $this->host . ':' . $this->port, $errorNumber, $errorMessage, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) throw new \RuntimeException('네이버 IMAP 서버 연결에 실패했습니다: ' . $errorMessage);
        @stream_set_timeout($socket, $this->timeout);
        $greeting = @fgets($socket, 16384);
        if ($greeting === false || stripos($greeting, '* OK') !== 0) {
            @fclose($socket);
            throw new \RuntimeException('네이버 IMAP 서버의 시작 응답이 올바르지 않습니다.');
        }
        $this->socket = $socket;
        return true;
    }

    public function login($username, $password)
    {
        $this->assertConnected();
        $response = $this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password));
        if (!$response['ok']) throw new \RuntimeException('네이버 계정 로그인에 실패했습니다. IMAP 설정과 애플리케이션 비밀번호를 확인하세요.');
        return true;
    }

    public function listMailboxes()
    {
        $this->assertConnected();
        $response = $this->command('LIST "" "*"');
        if (!$response['ok']) throw new \RuntimeException('네이버 메일함 목록을 읽지 못했습니다.');
        $result = array();
        foreach ($response['lines'] as $line) {
            if (stripos($line, '* LIST ') !== 0) continue;
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:"((?:[^"\\\\]|\\\\.)*)"|NIL) (.+?)\r?\n?$/i', $line, $matches)) continue;
            $flagsText = trim((string)$matches[1]);
            $nameToken = trim((string)$matches[3]);
            $rawName = '';
            if (strlen($nameToken) >= 2 && substr($nameToken, 0, 1) === '"' && substr($nameToken, -1) === '"') {
                $rawName = substr($nameToken, 1, -1);
                $rawName = str_replace(array('\\"', '\\\\'), array('"', '\\'), $rawName);
            } else {
                $rawName = $nameToken;
            }
            if ($rawName === '') continue;
            $flags = $flagsText === '' ? array() : preg_split('/\s+/', $flagsText);
            $noSelect = false;
            foreach ($flags as $flag) if (strcasecmp($flag, '\\Noselect') === 0) $noSelect = true;
            $result[] = array(
                'raw_name' => $rawName,
                'display_name' => $this->decodeModifiedUtf7($rawName),
                'flags' => is_array($flags) ? $flags : array(),
                'selectable' => !$noSelect
            );
        }
        usort($result, array($this, 'compareMailboxNames'));
        return $result;
    }

    public function compareMailboxNames($a, $b)
    {
        $an = isset($a['raw_name']) ? (string)$a['raw_name'] : '';
        $bn = isset($b['raw_name']) ? (string)$b['raw_name'] : '';
        if (strcasecmp($an, 'INBOX') === 0) return -1;
        if (strcasecmp($bn, 'INBOX') === 0) return 1;
        return strcmp(isset($a['display_name']) ? $a['display_name'] : $an, isset($b['display_name']) ? $b['display_name'] : $bn);
    }

    public function selectMailbox($mailbox)
    {
        $this->assertConnected();
        $mailbox = trim((string)$mailbox);
        if ($mailbox === '') $mailbox = 'INBOX';
        $response = $this->command('SELECT ' . $this->quote($mailbox));
        if (!$response['ok']) throw new \RuntimeException('메일함을 열 수 없습니다: ' . $response['final']);
        $this->selectedMailbox = $mailbox;
        $exists = 0; $uidValidity = '';
        foreach ($response['lines'] as $line) {
            if (preg_match('/^\*\s+([0-9]+)\s+EXISTS\b/i', $line, $m)) $exists = (int)$m[1];
            if (preg_match('/\[UIDVALIDITY\s+([0-9]+)\]/i', $line, $m)) $uidValidity = (string)$m[1];
        }
        return array('exists' => $exists, 'uid_validity' => $uidValidity);
    }

    public function searchAllUids()
    {
        $this->assertMailboxSelected();
        $response = $this->command('UID SEARCH ALL');
        if (!$response['ok']) throw new \RuntimeException('전체메일 검색에 실패했습니다: ' . $response['final']);
        return $this->parseSearchResponse($response['lines']);
    }

    public function searchUidsSince($timestamp)
    {
        $this->assertMailboxSelected();
        $response = $this->command('UID SEARCH SINCE ' . date('d-M-Y', (int)$timestamp));
        if (!$response['ok']) throw new \RuntimeException('메일 검색에 실패했습니다: ' . $response['final']);
        return $this->parseSearchResponse($response['lines']);
    }

    public function searchUidsAfter($lastUid)
    {
        $this->assertMailboxSelected();
        $lastUid = (int)$lastUid;
        $response = $this->command('UID SEARCH UID ' . ($lastUid > 0 ? $lastUid + 1 : 1) . ':*');
        if (!$response['ok']) throw new \RuntimeException('새 메일 검색에 실패했습니다: ' . $response['final']);
        $uids = $this->parseSearchResponse($response['lines']);
        $filtered = array();
        foreach ($uids as $uid) if ((int)$uid > $lastUid) $filtered[] = (int)$uid;
        return $filtered;
    }

    /**
     * 메일의 MIME BODYSTRUCTURE 문자열만 읽습니다.
     * 첨부파일 본문은 내려받지 않으므로 상세화면 준비가 훨씬 가볍습니다.
     */
    public function fetchBodyStructure($uid)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid;
        if ($uid <= 0) throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        $response = $this->command('UID FETCH ' . $uid . ' (BODYSTRUCTURE)', 1048576);
        if (!$response['ok']) throw new \RuntimeException('메일 본문 구조를 읽지 못했습니다: UID ' . $uid);
        $text = implode('', isset($response['lines']) && is_array($response['lines']) ? $response['lines'] : array());
        $position = stripos($text, 'BODYSTRUCTURE');
        if ($position === false) throw new \RuntimeException('메일 본문 구조 응답을 찾지 못했습니다: UID ' . $uid);
        $open = strpos($text, '(', $position + strlen('BODYSTRUCTURE'));
        if ($open === false) throw new \RuntimeException('메일 본문 구조 시작점을 찾지 못했습니다: UID ' . $uid);
        $balanced = $this->extractBalancedParentheses($text, $open);
        if ($balanced === '') throw new \RuntimeException('메일 본문 구조를 해석할 수 없습니다: UID ' . $uid);
        return $balanced;
    }

    public function fetchHeader($uid)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid;
        if ($uid <= 0) throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        $command = 'UID FETCH ' . $uid . ' (UID FLAGS RFC822.SIZE BODY.PEEK[HEADER.FIELDS (MESSAGE-ID IN-REPLY-TO REFERENCES SUBJECT FROM TO CC DATE CONTENT-TYPE CONTENT-DISPOSITION)])';
        $response = $this->command($command);
        if (!$response['ok']) throw new \RuntimeException('메일 머리글을 읽지 못했습니다: UID ' . $uid);
        $headerText = isset($response['literals'][0]) ? (string)$response['literals'][0] : '';
        $flags = array(); $size = 0;
        foreach ($response['lines'] as $line) {
            if (preg_match('/FLAGS\s+\(([^)]*)\)/i', $line, $m)) $flags = trim($m[1]) === '' ? array() : preg_split('/\s+/', trim($m[1]));
            if (preg_match('/RFC822\.SIZE\s+([0-9]+)/i', $line, $m)) $size = (int)$m[1];
        }
        return array('uid' => $uid, 'flags' => is_array($flags) ? $flags : array(), 'size' => $size, 'header' => $headerText);
    }

    /**
     * 여러 UID의 SUBJECT 헤더만 한 번의 IMAP 명령으로 가져옵니다.
     * 본문과 첨부파일은 내려받지 않으며 작업 안정성을 위해 최대 10건으로 제한합니다.
     */
    public function fetchSubjectHeaders($uids)
    {
        $this->assertMailboxSelected();
        if (!is_array($uids)) $uids = array();
        $clean = array();
        foreach ($uids as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) $clean[$uid] = $uid;
            if (count($clean) >= 10) break;
        }
        $clean = array_values($clean);
        sort($clean, SORT_NUMERIC);
        if (empty($clean)) return array();

        $response = $this->command(
            'UID FETCH ' . implode(',', $clean) . ' (UID BODY.PEEK[HEADER.FIELDS (SUBJECT)])',
            1048576
        );
        if (!$response['ok']) throw new \RuntimeException('메일 제목 묶음을 읽지 못했습니다: ' . $response['final']);

        $result = array();
        $contexts = isset($response['literal_contexts']) && is_array($response['literal_contexts'])
            ? $response['literal_contexts'] : array();
        foreach ($contexts as $context) {
            if (!is_array($context)) continue;
            $line = isset($context['line']) ? (string)$context['line'] : '';
            $literal = isset($context['literal']) ? (string)$context['literal'] : '';
            if ($literal === '' || !preg_match('/\bUID\s+([0-9]+)/i', $line, $match)) continue;
            $result[(int)$match[1]] = $literal;
        }

        /* 일부 서버가 UID를 리터럴 직전 줄과 분리하는 경우 응답 순서로 보완합니다. */
        if (count($result) < count($clean) && !empty($response['literals'])) {
            $responseUids = array();
            foreach ($response['lines'] as $line) {
                if (preg_match('/\bUID\s+([0-9]+)/i', (string)$line, $match)) $responseUids[] = (int)$match[1];
            }
            foreach ($response['literals'] as $position => $literal) {
                if (isset($responseUids[$position]) && !isset($result[$responseUids[$position]])) {
                    $result[$responseUids[$position]] = (string)$literal;
                }
            }
        }
        return $result;
    }

    public function fetchRawPreview($uid, $maximumBytes)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid; $maximumBytes = (int)$maximumBytes;
        if ($uid <= 0) throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        if ($maximumBytes < 16384) $maximumBytes = 65536;
        if ($maximumBytes > 262144) $maximumBytes = 262144;
        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . $maximumBytes . '>)', $maximumBytes + 8192);
        return (!$response['ok'] || empty($response['literals'])) ? '' : (string)$response['literals'][0];
    }

    public function fetchRawMessage($uid, $maximumBytes)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid; $maximumBytes = (int)$maximumBytes;
        if ($uid <= 0) throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        if ($maximumBytes <= 0) $maximumBytes = 83886080;
        if ($maximumBytes > 104857600) $maximumBytes = 104857600;
        $response = $this->command('UID FETCH ' . $uid . ' (UID RFC822.SIZE BODY.PEEK[])', $maximumBytes);
        if (!$response['ok']) throw new \RuntimeException('메일 본문을 읽지 못했습니다: UID ' . $uid);
        $raw = isset($response['literals'][0]) ? (string)$response['literals'][0] : '';
        if ($raw === '') throw new \RuntimeException('메일 본문이 비어 있습니다: UID ' . $uid);
        return $raw;
    }

    public function fetchMimeHeader($uid, $partId)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid; $partId = $this->validatePartId($partId);
        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[' . $partId . '.MIME])', 262144);
        if (!$response['ok'] || empty($response['literals'])) throw new \RuntimeException('첨부파일 정보를 읽지 못했습니다.');
        return (string)$response['literals'][0];
    }

    public function fetchMimePart($uid, $partId, $maximumBytes)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid; $partId = $this->validatePartId($partId); $maximumBytes = (int)$maximumBytes;
        if ($uid <= 0) throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        if ($maximumBytes < 1048576) $maximumBytes = 104857600;
        if ($maximumBytes > 157286400) $maximumBytes = 157286400;
        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[' . $partId . '])', $maximumBytes);
        if (!$response['ok'] || empty($response['literals'])) throw new \RuntimeException('첨부파일 내용을 읽지 못했습니다.');
        return (string)$response['literals'][0];
    }

    /**
     * 첨부 MIME 부분을 지정한 범위만 읽습니다.
     * 대용량 첨부를 서버 디스크에 저장하지 않고 작은 조각으로 전달할 때 사용합니다.
     */
    public function fetchMimePartChunk($uid, $partId, $offset, $count)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid;
        $partId = $this->validatePartId($partId);
        $offset = max(0, (int)$offset);
        $count = (int)$count;
        if ($uid <= 0) throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        if ($count < 4096) $count = 1048576;
        if ($count > 8388608) $count = 8388608;
        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[' . $partId . ']<'. $offset . '.' . $count . '>)', $count + 65536);
        if (!$response['ok']) throw new \RuntimeException('첨부파일 조각을 읽지 못했습니다.');
        return empty($response['literals']) ? '' : (string)$response['literals'][0];
    }

    private function extractBalancedParentheses($text, $start)
    {
        $text = (string)$text;
        $length = strlen($text);
        $start = (int)$start;
        if ($start < 0 || $start >= $length || $text[$start] !== '(') return '';
        $depth = 0;
        $quoted = false;
        $escaped = false;
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($quoted) {
                if ($escaped) { $escaped = false; continue; }
                if ($char === '\\') { $escaped = true; continue; }
                if ($char === '"') $quoted = false;
                continue;
            }
            if ($char === '"') { $quoted = true; continue; }
            if ($char === '(') $depth++;
            if ($char === ')') {
                $depth--;
                if ($depth === 0) return substr($text, $start, $i - $start + 1);
            }
        }
        return '';
    }

    private function validatePartId($partId)
    {
        $partId = trim((string)$partId);
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)*$/', $partId)) throw new \InvalidArgumentException('첨부파일 위치값이 올바르지 않습니다.');
        return $partId;
    }

    public function logout()
    {
        if (is_resource($this->socket)) {
            try { $this->command('LOGOUT'); } catch (\Exception $e) {}
            @fclose($this->socket);
        }
        $this->socket = null; $this->selectedMailbox = '';
    }

    public function __destruct() { $this->logout(); }

    private function parseSearchResponse($lines)
    {
        $uids = array();
        foreach ($lines as $line) {
            if (stripos($line, '* SEARCH') !== 0) continue;
            $text = trim(substr($line, strlen('* SEARCH')));
            if ($text === '') continue;
            foreach (preg_split('/\s+/', $text) as $part) if ((int)$part > 0) $uids[] = (int)$part;
        }
        $uids = array_values(array_unique($uids)); sort($uids, SORT_NUMERIC); return $uids;
    }

    private function decodeModifiedUtf7($value)
    {
        $value = (string)$value;
        if (strpos($value, '&') === false) return $value;
        return preg_replace_callback('/&([^-]*)-/', function ($m) {
            if ($m[1] === '') return '&';
            $b64 = str_replace(',', '/', $m[1]);
            $pad = strlen($b64) % 4; if ($pad > 0) $b64 .= str_repeat('=', 4 - $pad);
            $raw = base64_decode($b64, true);
            if ($raw === false) return $m[0];
            if (function_exists('mb_convert_encoding')) return mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
                if ($converted !== false) return $converted;
            }
            return $m[0];
        }, $value);
    }

    private function command($command, $maximumLiteralBytes = 52428800)
    {
        $this->assertConnected();
        $tag = 'A' . str_pad((string)$this->tagNumber, 4, '0', STR_PAD_LEFT); $this->tagNumber++;
        if (@fwrite($this->socket, $tag . ' ' . $command . "\r\n") === false) throw new \RuntimeException('IMAP 명령을 전송하지 못했습니다.');
        return $this->readResponse($tag, $maximumLiteralBytes);
    }

    private function readResponse($tag, $maximumLiteralBytes)
    {
        $maximumLiteralBytes = (int)$maximumLiteralBytes > 0 ? (int)$maximumLiteralBytes : 52428800;
        $result = array('ok'=>false,'status'=>'','final'=>'','lines'=>array(),'literals'=>array(),'literal_contexts'=>array());
        while (!feof($this->socket)) {
            $line = @fgets($this->socket, 16384);
            if ($line === false) {
                $meta = @stream_get_meta_data($this->socket);
                if (is_array($meta) && !empty($meta['timed_out'])) throw new \RuntimeException('네이버 메일 서버 응답 시간이 초과되었습니다.');
                throw new \RuntimeException('네이버 메일 서버 응답을 읽지 못했습니다.');
            }
            $result['lines'][] = $line;
            if (preg_match('/\{([0-9]+)\}\r?\n$/', $line, $m)) {
                $length = (int)$m[1];
                if ($length > $maximumLiteralBytes) throw new \RuntimeException('메일 데이터가 허용 크기를 초과했습니다.');
                $literal = ''; $remaining = $length;
                while ($remaining > 0) {
                    $chunk = @fread($this->socket, min(8192, $remaining));
                    if ($chunk === false || $chunk === '') throw new \RuntimeException('메일 데이터를 끝까지 읽지 못했습니다.');
                    $literal .= $chunk; $remaining -= strlen($chunk);
                }
                $result['literals'][] = $literal;
                $result['literal_contexts'][] = array('line'=>$line, 'literal'=>$literal);
            }
            if (strpos($line, $tag . ' ') === 0) {
                $result['final'] = trim($line);
                if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line, $m)) {
                    $result['status'] = strtoupper($m[1]); $result['ok'] = $result['status'] === 'OK';
                }
                break;
            }
        }
        return $result;
    }

    private function quote($value)
    {
        $value = str_replace('\\', '\\\\', (string)$value);
        $value = str_replace('"', '\\"', $value);
        $value = str_replace(array("\r", "\n"), '', $value);
        return '"' . $value . '"';
    }

    private function assertConnected()
    {
        if (!is_resource($this->socket)) throw new \RuntimeException('IMAP 서버에 연결되지 않았습니다.');
    }

    private function assertMailboxSelected()
    {
        $this->assertConnected();
        if ($this->selectedMailbox === '') throw new \RuntimeException('메일함이 선택되지 않았습니다.');
    }
}
