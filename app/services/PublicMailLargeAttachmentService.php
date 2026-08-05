<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailLargeAttachmentService.php
 *
 * 네이버 메일 본문에 포함된 대용량 첨부 링크를 찾고, 파일을 서버 디스크에
 * 저장하지 않은 채 브라우저 또는 Google Drive 전송 함수로 전달합니다.
 * PHP 5.6 호환 코드입니다.
 */

namespace App\Services;

class PublicMailLargeAttachmentService
{
    const DEFAULT_CHUNK_SIZE = 4194304; // 4MB
    const MAX_REDIRECTS = 5;

    public function extractFromBody($html, $text)
    {
        $html = (string)$html;
        $text = (string)$text;
        $items = array();
        $seen = array();

        if ($html !== '') {
            if (preg_match_all('/<a\b[^>]*href\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $url = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]);
                    $label = trim(html_entity_decode(strip_tags($match[4]), ENT_QUOTES, 'UTF-8'));
                    $this->appendCandidate($items, $seen, $url, $label, $html);
                }
            }
        }

        $combined = $text . "\n" . strip_tags($html);
        if (preg_match_all('~https?://[^\s<>"\']+~i', $combined, $matches)) {
            foreach ($matches[0] as $url) {
                $this->appendCandidate($items, $seen, $url, '', $combined);
            }
        }

        return array_values($items);
    }

    private function appendCandidate(&$items, &$seen, $url, $label, $context)
    {
        $url = html_entity_decode(trim((string)$url), ENT_QUOTES, 'UTF-8');
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        $url = rtrim($url, ".,;)]}>\r\n\t ");
        if (!$this->isAllowedNaverUrl($url)) return;

        $lower = strtolower($url . ' ' . $label);
        $looksLarge = strpos($lower, 'bigfile') !== false
            || strpos($lower, 'large') !== false
            || strpos($lower, 'download') !== false
            || strpos($lower, 'attach') !== false
            || strpos($lower, '대용량') !== false
            || strpos($lower, '다운로드') !== false;
        if (!$looksLarge) return;

        $id = substr(sha1($url), 0, 20);
        if (isset($seen[$id])) return;
        $seen[$id] = true;

        $filename = $this->filenameFromLabelOrUrl($label, $url);
        $items[$id] = array(
            'part_id' => 'large_' . $id,
            'large_id' => $id,
            'filename' => $filename,
            'mime_type' => 'application/octet-stream',
            'size' => 0,
            'is_large' => true,
            'source_url' => $url,
            'expires_hint' => '네이버 대용량 첨부파일은 보관기간 또는 다운로드 횟수 제한이 적용될 수 있습니다.'
        );
    }

    public function isAllowedNaverUrl($url)
    {
        $parts = @parse_url((string)$url);
        if (!is_array($parts)) return false;
        $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower(rtrim((string)$parts['host'], '.')) : '';
        if ($host === '') return false;
        if ($scheme !== 'https' && $scheme !== 'http') return false;
        if ($scheme === 'http' && !($host === 'bigfile.mail.naver.com' || substr($host, -15) === '.mail.naver.com')) return false;
        if ($host === 'naver.com' || substr($host, -10) === '.naver.com') return true;
        if ($host === 'naver.net' || substr($host, -10) === '.naver.net') return true;
        return false;
    }

    public function inspectRemote($url)
    {
        $url = trim((string)$url);
        if (!$this->isAllowedNaverUrl($url)) throw new \RuntimeException('허용되지 않은 대용량 첨부주소입니다.');
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL 기능을 사용할 수 없습니다.');

        $headers = array();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, self::MAX_REDIRECTS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headers) {
            $length = strlen($line);
            $position = strpos($line, ':');
            if ($position !== false) {
                $name = strtolower(trim(substr($line, 0, $position)));
                $value = trim(substr($line, $position + 1));
                $headers[$name] = $value;
            }
            return $length;
        });
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $length = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $http < 200 || $http >= 400) {
            // 일부 대용량 파일 서버는 HEAD 요청을 허용하지 않습니다.
            // 이 경우 실제 다운로드 단계에서 GET 요청으로 다시 확인합니다.
            if ($http === 405 || $http === 501) {
                return array(
                    'url' => $url,
                    'size' => 0,
                    'mime_type' => 'application/octet-stream',
                    'filename' => ''
                );
            }
            throw new \RuntimeException($this->remoteFailureMessage($http, $error));
        }
        if ($effective !== '' && !$this->isAllowedNaverUrl($effective)) throw new \RuntimeException('대용량 첨부주소가 허용되지 않은 위치로 이동했습니다.');

        $filename = '';
        if (isset($headers['content-disposition'])) $filename = $this->filenameFromDisposition($headers['content-disposition']);
        return array(
            'url' => $effective !== '' ? $effective : $url,
            'size' => $length > 0 ? $length : 0,
            'mime_type' => $type !== '' ? trim(explode(';', $type, 2)[0]) : 'application/octet-stream',
            'filename' => $filename
        );
    }

    public function streamRemote($url, $consumer, $chunkSize)
    {
        if (!is_callable($consumer)) throw new \InvalidArgumentException('파일 수신 함수가 올바르지 않습니다.');
        $info = $this->inspectRemote($url);
        $chunkSize = (int)$chunkSize;
        if ($chunkSize < 262144) $chunkSize = self::DEFAULT_CHUNK_SIZE;
        if ($chunkSize > 16777216) $chunkSize = 16777216;

        $offset = 0;
        $total = isset($info['size']) ? (int)$info['size'] : 0;
        if ($total > 0) {
            while ($offset < $total) {
                $end = min($total - 1, $offset + $chunkSize - 1);
                $range = $this->downloadRange($info['url'], $offset, $end);
                $data = isset($range['data']) ? (string)$range['data'] : '';
                $http = isset($range['http_code']) ? (int)$range['http_code'] : 0;
                if ($data === '') throw new \RuntimeException('대용량 첨부파일 수신이 중간에 중단되었습니다.');

                $requestedLength = ($end - $offset) + 1;
                // Range를 무시하고 전체 파일(HTTP 200)을 보내는 서버가 있습니다.
                // 첫 요청이라면 전체 내용을 한 번만 전달하고, 중복 다운로드를 막습니다.
                if ($http === 200) {
                    if ($offset !== 0) throw new \RuntimeException('대용량 파일 서버가 부분 다운로드를 지원하지 않습니다. 처음부터 다시 시도해주세요.');
                    call_user_func($consumer, $data, 0, strlen($data));
                    $offset = strlen($data);
                    return array_merge($info, array('bytes_streamed' => $offset, 'size' => $offset));
                }
                if (strlen($data) > $requestedLength) {
                    throw new \RuntimeException('대용량 첨부파일의 부분 다운로드 응답이 올바르지 않습니다.');
                }
                call_user_func($consumer, $data, $offset, $total);
                $offset += strlen($data);
            }
            return array_merge($info, array('bytes_streamed' => $offset));
        }

        $this->downloadWholeToCallback($info['url'], function ($data) use ($consumer, &$offset) {
            if ($data === '') return;
            call_user_func($consumer, $data, $offset, 0);
            $offset += strlen($data);
        });
        return array_merge($info, array('bytes_streamed' => $offset, 'size' => $offset));
    }

    private function downloadRange($url, $start, $end)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RANGE, (int)$start . '-' . (int)$end);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, self::MAX_REDIRECTS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        $data = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);
        if ($data === false || ($http !== 200 && $http !== 206)) throw new \RuntimeException($this->remoteFailureMessage($http, $error));
        if ($effective !== '' && !$this->isAllowedNaverUrl($effective)) throw new \RuntimeException('대용량 첨부주소가 허용되지 않은 위치로 이동했습니다.');
        return array('data' => (string)$data, 'http_code' => $http, 'effective_url' => $effective);
    }

    private function downloadWholeToCallback($url, $consumer)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, self::MAX_REDIRECTS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use ($consumer) {
            call_user_func($consumer, $data);
            return strlen($data);
        });
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);
        if ($ok === false || $http < 200 || $http >= 400) throw new \RuntimeException($this->remoteFailureMessage($http, $error));
        if ($effective !== '' && !$this->isAllowedNaverUrl($effective)) throw new \RuntimeException('대용량 첨부주소가 허용되지 않은 위치로 이동했습니다.');
    }

    private function remoteFailureMessage($http, $error)
    {
        $http = (int)$http;
        if ($http === 401 || $http === 403) return '대용량 첨부파일의 보관기간이 만료되었거나 다운로드 가능 횟수가 초과되었습니다.';
        if ($http === 404 || $http === 410) return '대용량 첨부파일의 보관기간이 만료되어 파일을 찾을 수 없습니다.';
        if ($http === 429) return '네이버의 다운로드 횟수 또는 호출 제한에 도달했습니다.';
        if ($http >= 500) return '네이버 대용량 파일 서버가 일시적으로 응답하지 않습니다.';
        return $error !== '' ? '대용량 첨부파일 연결 실패: ' . $error : '대용량 첨부파일을 다운로드할 수 없습니다.';
    }

    private function filenameFromDisposition($value)
    {
        $value = (string)$value;
        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $value, $m)) return $this->safeFilename(rawurldecode(trim($m[1], " \t\r\n\"'")));
        if (preg_match('/filename\s*=\s*(?:"([^"]+)"|([^;]+))/i', $value, $m)) return $this->safeFilename(trim($m[1] !== '' ? $m[1] : $m[2], " \t\r\n\"'"));
        return '';
    }

    private function filenameFromLabelOrUrl($label, $url)
    {
        $label = trim((string)$label);
        if ($label !== '' && $label !== '다운로드' && $label !== 'Download') return $this->safeFilename($label);
        $parts = @parse_url($url);
        $path = is_array($parts) && isset($parts['path']) ? $parts['path'] : '';
        $name = rawurldecode(basename($path));
        if ($name === '' || strpos($name, '.') === false) $name = '네이버_대용량_첨부파일';
        return $this->safeFilename($name);
    }

    private function safeFilename($filename)
    {
        $filename = trim((string)$filename);
        $filename = preg_replace('#[\\/:*?"<>|\x00-\x1F]+#u', '_', $filename);
        $filename = trim($filename, " .\t\r\n");
        if ($filename === '') $filename = 'attachment.bin';
        if (function_exists('mb_substr')) return mb_substr($filename, 0, 180, 'UTF-8');
        return substr($filename, 0, 180);
    }
}
