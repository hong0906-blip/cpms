<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailDriveService.php
 *
 * 네이버 메일 첨부파일을 CPMS 서버 디스크에 저장하지 않고 Google Drive의
 * 재개 가능한 업로드 세션으로 분할 전송합니다. PHP 5.6 호환 코드입니다.
 */

namespace App\Services;

$googleDriveHelperFile = __DIR__ . '/GoogleDriveHelper.php';
if (is_file($googleDriveHelperFile)) require_once $googleDriveHelperFile;
require_once __DIR__ . '/PublicMailStorageService.php';

class PublicMailDriveService
{
    const DRIVE_CHUNK_SIZE = 8388608; // 8MB, 256KB 배수

    public function isAvailable()
    {
        return function_exists('cpms_drive_get_access_token')
            && function_exists('cpms_drive_find_or_create_folder')
            && function_exists('cpms_drive_folder_id');
    }

    public function saveAttachment($message, $attachment, $projectId, $producer, $actor)
    {
        if (!$this->isAvailable()) throw new \RuntimeException('Google Drive 공통연동 파일을 찾을 수 없습니다.');
        if (!is_callable($producer)) throw new \InvalidArgumentException('첨부파일 전송 함수를 확인할 수 없습니다.');

        $filename = isset($attachment['filename']) ? trim((string)$attachment['filename']) : 'attachment.bin';
        $mimeType = isset($attachment['mime_type']) ? trim((string)$attachment['mime_type']) : 'application/octet-stream';
        if ($mimeType === '') $mimeType = 'application/octet-stream';
        $projectId = (int)$projectId;
        $folder = $this->resolveTargetFolder($projectId, $message, $actor);
        $session = $this->startResumableSession($filename, $mimeType, $folder['folder_id']);

        $buffer = '';
        $offset = 0;
        $uploadedBytes = 0;
        $lastResponse = null;
        $self = $this;
        call_user_func($producer, function ($data) use (&$buffer, &$offset, &$uploadedBytes, &$lastResponse, $session, $self) {
            $data = (string)$data;
            if ($data === '') return;
            $buffer .= $data;
            while (strlen($buffer) > self::DRIVE_CHUNK_SIZE) {
                $chunk = substr($buffer, 0, self::DRIVE_CHUNK_SIZE);
                $buffer = substr($buffer, self::DRIVE_CHUNK_SIZE);
                $lastResponse = $self->sendChunk($session, $chunk, $offset, null, false);
                $offset += strlen($chunk);
                $uploadedBytes += strlen($chunk);
            }
        });

        $total = $offset + strlen($buffer);
        if ($total <= 0) throw new \RuntimeException('Google Drive에 저장할 첨부파일 내용이 비어 있습니다.');
        $lastResponse = $this->sendChunk($session, $buffer, $offset, $total, true);
        $uploadedBytes += strlen($buffer);

        $file = is_array($lastResponse) && isset($lastResponse['json']) && is_array($lastResponse['json']) ? $lastResponse['json'] : array();
        if (empty($file['id'])) throw new \RuntimeException('Google Drive 업로드 완료정보를 확인하지 못했습니다.');

        $record = array(
            'record_id' => substr(sha1((isset($message['message_key']) ? $message['message_key'] : '') . '|' . (isset($attachment['part_id']) ? $attachment['part_id'] : '') . '|' . $file['id']), 0, 24),
            'message_key' => isset($message['message_key']) ? (string)$message['message_key'] : '',
            'mailbox' => isset($message['mailbox']) ? (string)$message['mailbox'] : '',
            'uid' => isset($message['uid']) ? (int)$message['uid'] : 0,
            'part_id' => isset($attachment['part_id']) ? (string)$attachment['part_id'] : '',
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $uploadedBytes,
            'project_id' => $projectId,
            'project_name' => isset($folder['project_name']) ? (string)$folder['project_name'] : '',
            'drive_file_id' => (string)$file['id'],
            'drive_folder_id' => (string)$folder['folder_id'],
            'drive_web_view_link' => isset($file['webViewLink']) ? (string)$file['webViewLink'] : '',
            'drive_web_content_link' => isset($file['webContentLink']) ? (string)$file['webContentLink'] : '',
            'saved_by' => (string)$actor,
            'saved_at' => date('Y-m-d H:i:s'),
            'server_file_saved' => false
        );
        PublicMailStorageService::saveDriveRecord($record);
        return $record;
    }

    private function resolveTargetFolder($projectId, $message, $actor)
    {
        $context = array('section'=>'naver_mail','document_type'=>'mail_attachment','project_id'=>$projectId,'user'=>$actor);
        $parentId = '';
        $projectName = '';

        if ($projectId > 0) {
            $project = $this->loadProject($projectId);
            if (is_array($project)) {
                $projectName = isset($project['name']) ? (string)$project['name'] : '';
                if (!empty($project['drive_folder_id'])) $parentId = trim((string)$project['drive_folder_id']);
                if ($parentId === '' && !empty($project['drive_folders_json'])) {
                    $decoded = @json_decode((string)$project['drive_folders_json'], true);
                    if (is_array($decoded)) {
                        if (!empty($decoded['project_folder_id'])) $parentId = trim((string)$decoded['project_folder_id']);
                        elseif (!empty($decoded['folders']['project'])) $parentId = trim((string)$decoded['folders']['project']);
                    }
                }
            }
        }

        if ($parentId === '') {
            $parentId = trim((string)cpms_drive_folder_id('project_root'));
            if ($parentId === '') $parentId = trim((string)cpms_drive_folder_id('system_backup'));
            if ($parentId === '') throw new \RuntimeException('Google Drive의 기준 폴더가 설정되어 있지 않습니다.');
            $root = cpms_drive_find_or_create_folder('네이버메일', $parentId, $context);
            if (empty($root['ok']) || empty($root['file']['id'])) throw new \RuntimeException('Google Drive 네이버메일 폴더를 준비하지 못했습니다.');
            $parentId = (string)$root['file']['id'];
            $unclassified = cpms_drive_find_or_create_folder('미분류', $parentId, $context);
            if (empty($unclassified['ok']) || empty($unclassified['file']['id'])) throw new \RuntimeException('Google Drive 미분류 폴더를 준비하지 못했습니다.');
            $parentId = (string)$unclassified['file']['id'];
        } else {
            $mailFolder = cpms_drive_find_or_create_folder('네이버메일', $parentId, $context);
            if (empty($mailFolder['ok']) || empty($mailFolder['file']['id'])) throw new \RuntimeException('프로젝트의 네이버메일 폴더를 준비하지 못했습니다.');
            $parentId = (string)$mailFolder['file']['id'];
        }

        $year = isset($message['timestamp']) && (int)$message['timestamp'] > 0 ? date('Y', (int)$message['timestamp']) : date('Y');
        $month = isset($message['timestamp']) && (int)$message['timestamp'] > 0 ? date('m', (int)$message['timestamp']) : date('m');
        $yearFolder = cpms_drive_find_or_create_folder($year, $parentId, $context);
        if (empty($yearFolder['ok']) || empty($yearFolder['file']['id'])) throw new \RuntimeException('Google Drive 연도 폴더를 준비하지 못했습니다.');
        $monthFolder = cpms_drive_find_or_create_folder($month, (string)$yearFolder['file']['id'], $context);
        if (empty($monthFolder['ok']) || empty($monthFolder['file']['id'])) throw new \RuntimeException('Google Drive 월 폴더를 준비하지 못했습니다.');

        return array('folder_id'=>(string)$monthFolder['file']['id'],'project_name'=>$projectName);
    }

    private function startResumableSession($filename, $mimeType, $folderId)
    {
        $token = cpms_drive_get_access_token();
        if (empty($token['ok']) || empty($token['access_token'])) throw new \RuntimeException('Google Drive 인증에 실패했습니다: ' . (isset($token['message']) ? $token['message'] : ''));
        $metadata = array('name'=>$this->safeName($filename),'parents'=>array((string)$folderId));
        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id,name,mimeType,size,parents,webViewLink,webContentLink';
        $headers = array();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $token['access_token'],
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $mimeType
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headers) {
            $length = strlen($line);
            $position = strpos($line, ':');
            if ($position !== false) $headers[strtolower(trim(substr($line,0,$position)))] = trim(substr($line,$position+1));
            return $length;
        });
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $http < 200 || $http >= 300 || empty($headers['location'])) {
            throw new \RuntimeException('Google Drive 업로드 세션을 만들지 못했습니다: ' . ($error !== '' ? $error : 'HTTP ' . $http));
        }
        return array('url'=>(string)$headers['location'],'token'=>(string)$token['access_token']);
    }

    private function sendChunk($session, $chunk, $offset, $total, $final)
    {
        $chunk = (string)$chunk;
        $length = strlen($chunk);
        if ($length <= 0) throw new \RuntimeException('Google Drive 전송 조각이 비어 있습니다.');
        $end = (int)$offset + $length - 1;
        $rangeTotal = $final ? (string)(int)$total : '*';
        // 대용량 파일 전송이 1시간을 넘길 수 있으므로 조각마다 유효한 토큰을 확인합니다.
        $tokenValue = isset($session['token']) ? (string)$session['token'] : '';
        $freshToken = cpms_drive_get_access_token();
        if (is_array($freshToken) && !empty($freshToken['ok']) && !empty($freshToken['access_token'])) {
            $tokenValue = (string)$freshToken['access_token'];
        }
        if ($tokenValue === '') throw new \RuntimeException('Google Drive 인증 토큰을 확인할 수 없습니다.');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $session['url']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $tokenValue,
            'Content-Type: application/octet-stream',
            'Content-Length: ' . $length,
            'Content-Range: bytes ' . (int)$offset . '-' . $end . '/' . $rangeTotal
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || !in_array($http, array(200,201,308), true)) {
            throw new \RuntimeException('Google Drive 파일 전송에 실패했습니다: ' . ($error !== '' ? $error : 'HTTP ' . $http));
        }
        $json = is_string($body) && $body !== '' ? @json_decode($body, true) : array();
        if ($final && !in_array($http, array(200,201), true)) throw new \RuntimeException('Google Drive 파일 전송이 완료되지 않았습니다.');
        return array('http_code'=>$http,'json'=>is_array($json)?$json:array(),'body'=>(string)$body);
    }

    private function loadProject($projectId)
    {
        try {
            $pdo = \App\Core\Db::pdo();
            foreach (array('cpms_projects','projects') as $table) {
                try {
                    $st = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id = :id LIMIT 1');
                    $st->execute(array(':id'=>(int)$projectId));
                    $row = $st->fetch(\PDO::FETCH_ASSOC);
                    if (is_array($row)) {
                        return array(
                            'name'=>isset($row['name'])?(string)$row['name']:(isset($row['project_name'])?(string)$row['project_name']:''),
                            'drive_folder_id'=>isset($row['drive_folder_id'])?(string)$row['drive_folder_id']:'',
                            'drive_folders_json'=>isset($row['drive_folders_json'])?(string)$row['drive_folders_json']:''
                        );
                    }
                } catch (\Exception $ignored) {}
            }
        } catch (\Exception $ignored) {}
        return null;
    }

    private function safeName($name)
    {
        if (function_exists('cpms_drive_sanitize_file_name')) return cpms_drive_sanitize_file_name($name, 180);
        $name = preg_replace('#[\\/:*?"<>|\x00-\x1F]+#u', '_', trim((string)$name));
        return $name !== '' ? $name : 'attachment.bin';
    }
}
