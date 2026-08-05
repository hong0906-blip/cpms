<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailImapClient.php
 *
 * PHP IMAP 확장 없이 네이버 IMAP 서버와 직접 통신합니다.
 * 메일 읽음 상태를 바꾸지 않도록 BODY.PEEK 명령만 사용합니다.
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
        $this->timeout = (int)$timeout;
        $this->socket = null;
        $this->tagNumber = 1;
        $this->selectedMailbox = '';

        if ($this->timeout < 5) {
            $this->timeout = 15;
        }
    }

    public function connect()
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('PHP OpenSSL 기능을 사용할 수 없습니다.');
        }

        $context = stream_context_create(array(
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $this->host,
                'SNI_enabled' => true
            )
        ));

        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            'ssl://' . $this->host . ':' . $this->port,
            $errorNumber,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($socket)) {
            throw new \RuntimeException('네이버 IMAP 서버 연결에 실패했습니다: ' . $errorMessage);
        }

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

        if (!$response['ok']) {
            throw new \RuntimeException('네이버 계정 로그인에 실패했습니다. IMAP 설정과 애플리케이션 비밀번호를 확인하세요.');
        }

        return true;
    }

    public function selectMailbox($mailbox)
    {
        $this->assertConnected();
        $mailbox = trim((string)$mailbox);
        if ($mailbox === '') {
            $mailbox = 'INBOX';
        }

        $response = $this->command('SELECT ' . $this->quote($mailbox));
        if (!$response['ok']) {
            throw new \RuntimeException('받은메일함을 열 수 없습니다: ' . $response['final']);
        }

        $this->selectedMailbox = $mailbox;
        $exists = 0;
        $uidValidity = '';

        foreach ($response['lines'] as $line) {
            if (preg_match('/^\*\s+([0-9]+)\s+EXISTS\b/i', $line, $matches)) {
                $exists = (int)$matches[1];
            }
            if (preg_match('/\[UIDVALIDITY\s+([0-9]+)\]/i', $line, $matches)) {
                $uidValidity = (string)$matches[1];
            }
        }

        return array(
            'exists' => $exists,
            'uid_validity' => $uidValidity
        );
    }

    public function searchUidsSince($timestamp)
    {
        $this->assertMailboxSelected();
        $date = date('d-M-Y', (int)$timestamp);
        $response = $this->command('UID SEARCH SINCE ' . $date);

        if (!$response['ok']) {
            throw new \RuntimeException('메일 검색에 실패했습니다: ' . $response['final']);
        }

        return $this->parseSearchResponse($response['lines']);
    }

    public function searchUidsAfter($lastUid)
    {
        $this->assertMailboxSelected();
        $lastUid = (int)$lastUid;
        $startUid = $lastUid > 0 ? $lastUid + 1 : 1;
        $response = $this->command('UID SEARCH UID ' . $startUid . ':*');

        if (!$response['ok']) {
            throw new \RuntimeException('새 메일 검색에 실패했습니다: ' . $response['final']);
        }

        // IMAP 서버에 따라 마지막 UID보다 큰 값이 없을 때
        // 범위 끝값(*) 때문에 마지막 메일이 다시 포함될 수 있어 한 번 더 걸러냅니다.
        $uids = $this->parseSearchResponse($response['lines']);
        $filtered = array();
        foreach ($uids as $uid) {
            if ((int)$uid > $lastUid) {
                $filtered[] = (int)$uid;
            }
        }
        return $filtered;
    }

    public function fetchHeader($uid)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid;
        if ($uid <= 0) {
            throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        }

        $command = 'UID FETCH ' . $uid
            . ' (UID FLAGS RFC822.SIZE BODY.PEEK[HEADER.FIELDS '
            . '(MESSAGE-ID IN-REPLY-TO REFERENCES SUBJECT FROM TO CC DATE CONTENT-TYPE)])';
        $response = $this->command($command);

        if (!$response['ok']) {
            throw new \RuntimeException('메일 머리글을 읽지 못했습니다: UID ' . $uid);
        }

        $headerText = isset($response['literals'][0]) ? (string)$response['literals'][0] : '';
        $flags = array();
        $size = 0;

        foreach ($response['lines'] as $line) {
            if (preg_match('/FLAGS\s+\(([^)]*)\)/i', $line, $matches)) {
                $flagText = trim($matches[1]);
                $flags = $flagText === '' ? array() : preg_split('/\s+/', $flagText);
            }
            if (preg_match('/RFC822\.SIZE\s+([0-9]+)/i', $line, $matches)) {
                $size = (int)$matches[1];
            }
        }

        return array(
            'uid' => $uid,
            'flags' => is_array($flags) ? $flags : array(),
            'size' => $size,
            'header' => $headerText
        );
    }

    public function fetchTextPreview($uid, $maximumBytes)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid;
        $maximumBytes = (int)$maximumBytes;

        if ($uid <= 0) {
            throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        }
        if ($maximumBytes < 1024) {
            $maximumBytes = 32768;
        }
        if ($maximumBytes > 65536) {
            $maximumBytes = 65536;
        }

        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[TEXT]<0.' . $maximumBytes . '>)', $maximumBytes + 4096);
        if (!$response['ok'] || empty($response['literals'])) {
            return '';
        }

        return (string)$response['literals'][0];
    }

    public function fetchRawMessage($uid, $maximumBytes)
    {
        $this->assertMailboxSelected();
        $uid = (int)$uid;
        $maximumBytes = (int)$maximumBytes;

        if ($uid <= 0) {
            throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        }
        if ($maximumBytes <= 0) {
            $maximumBytes = 31457280;
        }

        $response = $this->command('UID FETCH ' . $uid . ' (UID RFC822.SIZE BODY.PEEK[])', $maximumBytes);
        if (!$response['ok']) {
            throw new \RuntimeException('메일 본문을 읽지 못했습니다: UID ' . $uid);
        }

        $raw = isset($response['literals'][0]) ? (string)$response['literals'][0] : '';
        if ($raw === '') {
            throw new \RuntimeException('메일 본문이 비어 있습니다: UID ' . $uid);
        }

        return $raw;
    }

    public function logout()
    {
        if (is_resource($this->socket)) {
            try {
                $this->command('LOGOUT');
            } catch (\Exception $e) {
                // 연결 종료 과정의 오류는 무시합니다.
            }
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->selectedMailbox = '';
    }

    public function __destruct()
    {
        $this->logout();
    }

    private function parseSearchResponse($lines)
    {
        $uids = array();

        foreach ($lines as $line) {
            if (stripos($line, '* SEARCH') !== 0) {
                continue;
            }

            $text = trim(substr($line, strlen('* SEARCH')));
            if ($text === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $text);
            foreach ($parts as $part) {
                $uid = (int)$part;
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }
        }

        $uids = array_values(array_unique($uids));
        sort($uids, SORT_NUMERIC);
        return $uids;
    }

    private function command($command, $maximumLiteralBytes = 52428800)
    {
        $this->assertConnected();
        $tag = 'A' . str_pad((string)$this->tagNumber, 4, '0', STR_PAD_LEFT);
        $this->tagNumber++;

        $written = @fwrite($this->socket, $tag . ' ' . $command . "\r\n");
        if ($written === false) {
            throw new \RuntimeException('IMAP 명령을 전송하지 못했습니다.');
        }

        return $this->readResponse($tag, $maximumLiteralBytes);
    }

    private function readResponse($tag, $maximumLiteralBytes)
    {
        $maximumLiteralBytes = isset($maximumLiteralBytes) ? (int)$maximumLiteralBytes : 52428800;
        if ($maximumLiteralBytes <= 0) {
            $maximumLiteralBytes = 52428800;
        }

        $result = array(
            'ok' => false,
            'status' => '',
            'final' => '',
            'lines' => array(),
            'literals' => array()
        );

        while (!feof($this->socket)) {
            $line = @fgets($this->socket, 16384);
            if ($line === false) {
                $meta = @stream_get_meta_data($this->socket);
                if (is_array($meta) && !empty($meta['timed_out'])) {
                    throw new \RuntimeException('네이버 메일 서버 응답 시간이 초과되었습니다.');
                }
                throw new \RuntimeException('네이버 메일 서버 응답을 읽지 못했습니다.');
            }

            $result['lines'][] = $line;

            if (preg_match('/\{([0-9]+)\}\r?\n$/', $line, $matches)) {
                $literalLength = (int)$matches[1];
                if ($literalLength > $maximumLiteralBytes) {
                    throw new \RuntimeException('메일 데이터가 허용 크기를 초과했습니다.');
                }

                $literal = '';
                $remaining = $literalLength;

                while ($remaining > 0) {
                    $readLength = $remaining > 8192 ? 8192 : $remaining;
                    $chunk = @fread($this->socket, $readLength);
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('메일 데이터를 끝까지 읽지 못했습니다.');
                    }
                    $literal .= $chunk;
                    $remaining -= strlen($chunk);
                }

                $result['literals'][] = $literal;
            }

            if (strpos($line, $tag . ' ') === 0) {
                $result['final'] = trim($line);
                if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line, $statusMatch)) {
                    $result['status'] = strtoupper($statusMatch[1]);
                    $result['ok'] = $result['status'] === 'OK';
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
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('IMAP 서버에 연결되지 않았습니다.');
        }
    }

    private function assertMailboxSelected()
    {
        $this->assertConnected();
        if ($this->selectedMailbox === '') {
            throw new \RuntimeException('메일함이 선택되지 않았습니다.');
        }
    }
}
