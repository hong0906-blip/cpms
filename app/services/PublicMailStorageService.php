<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailStorageService.php
 *
 * 공용메일 설정, 메일 목록, 분류 및 처리상태를 JSON 파일로 안전하게 저장합니다.
 * PHP 5.6 호환 코드입니다.
 */

namespace App\Services;

class PublicMailStorageService
{
    const SETTINGS_FILE = 'settings.json';
    const MESSAGES_FILE = 'messages.json';
    const WORKFLOW_FILE = 'workflow.json';
    const SYNC_FILE = 'sync_state.json';
    const KEY_FILE = 'secret.key';

    public static function rootPath()
    {
        if (function_exists('cpms_storage_root')) {
            return rtrim((string)cpms_storage_root(), '/\\') . DIRECTORY_SEPARATOR . 'public_mail';
        }

        return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'public_mail';
    }

    public static function ensureStorage()
    {
        $root = self::rootPath();

        if (!is_dir($root)) {
            if (!@mkdir($root, 0770, true) && !is_dir($root)) {
                throw new \RuntimeException('공용메일 저장 폴더를 만들 수 없습니다: ' . $root);
            }
        }

        $defaults = array(
            self::SETTINGS_FILE => array(
                'enabled' => false,
                'provider' => 'naver',
                'username' => '',
                'encrypted_password' => '',
                'imap_host' => 'imap.naver.com',
                'imap_port' => 993,
                'imap_security' => 'ssl',
                'initial_years' => 1,
                'batch_size' => 50,
                'use_gpt_classifier' => false,
                'updated_at' => '',
                'updated_by' => ''
            ),
            self::MESSAGES_FILE => array(),
            self::WORKFLOW_FILE => array(),
            self::SYNC_FILE => array(
                'last_success_at' => '',
                'last_error_at' => '',
                'last_error' => '',
                'last_uid' => 0,
                'last_batch_count' => 0,
                'last_search_count' => 0,
                'last_mode' => '',
                'completed_initial_sync' => false
            )
        );

        foreach ($defaults as $fileName => $defaultValue) {
            $path = $root . DIRECTORY_SEPARATOR . $fileName;
            if (!is_file($path)) {
                self::writeJsonFile($path, $defaultValue);
            }
        }

        self::ensureEncryptionKey();

        return $root;
    }

    public static function getSettings($includePassword)
    {
        self::ensureStorage();
        $settings = self::readJsonFile(self::path(self::SETTINGS_FILE), array());

        if (!is_array($settings)) {
            $settings = array();
        }

        $defaults = array(
            'enabled' => false,
            'provider' => 'naver',
            'username' => '',
            'encrypted_password' => '',
            'imap_host' => 'imap.naver.com',
            'imap_port' => 993,
            'imap_security' => 'ssl',
            'initial_years' => 1,
            'batch_size' => 50,
            'use_gpt_classifier' => false,
            'updated_at' => '',
            'updated_by' => ''
        );

        $settings = array_merge($defaults, $settings);

        if ($includePassword) {
            $settings['password'] = self::decryptSecret(isset($settings['encrypted_password']) ? $settings['encrypted_password'] : '');
        }

        unset($settings['encrypted_password']);

        return $settings;
    }

    public static function saveSettings($input, $updatedBy)
    {
        self::ensureStorage();
        $current = self::readJsonFile(self::path(self::SETTINGS_FILE), array());
        if (!is_array($current)) {
            $current = array();
        }

        $username = isset($input['username']) ? trim((string)$input['username']) : '';
        $password = isset($input['password']) ? trim((string)$input['password']) : '';
        $years = isset($input['initial_years']) ? (int)$input['initial_years'] : 1;
        $batchSize = isset($input['batch_size']) ? (int)$input['batch_size'] : 50;

        if ($years < 1) {
            $years = 1;
        }
        if ($years > 10) {
            $years = 10;
        }
        if ($batchSize < 10) {
            $batchSize = 10;
        }
        if ($batchSize > 100) {
            $batchSize = 100;
        }

        if ($username === '') {
            throw new \InvalidArgumentException('네이버 아이디를 입력하세요.');
        }

        $encryptedPassword = isset($current['encrypted_password']) ? (string)$current['encrypted_password'] : '';
        if ($password !== '') {
            $encryptedPassword = self::encryptSecret($password);
        }
        if ($encryptedPassword === '') {
            throw new \InvalidArgumentException('애플리케이션 비밀번호를 입력하세요.');
        }

        $settings = array(
            'enabled' => !empty($input['enabled']),
            'provider' => 'naver',
            'username' => $username,
            'encrypted_password' => $encryptedPassword,
            'imap_host' => 'imap.naver.com',
            'imap_port' => 993,
            'imap_security' => 'ssl',
            'initial_years' => $years,
            'batch_size' => $batchSize,
            'use_gpt_classifier' => !empty($input['use_gpt_classifier']),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => (string)$updatedBy
        );

        self::writeJsonFile(self::path(self::SETTINGS_FILE), $settings);

        return self::getSettings(false);
    }

    public static function getMessages()
    {
        self::ensureStorage();
        $messages = self::readJsonFile(self::path(self::MESSAGES_FILE), array());
        return is_array($messages) ? $messages : array();
    }

    public static function saveMessages($messages)
    {
        self::ensureStorage();
        if (!is_array($messages)) {
            $messages = array();
        }
        self::writeJsonFile(self::path(self::MESSAGES_FILE), $messages);
    }

    public static function upsertMessages($newMessages)
    {
        $messages = self::getMessages();

        foreach ($newMessages as $uid => $message) {
            $key = (string)$uid;
            if (isset($messages[$key]) && is_array($messages[$key])) {
                $messages[$key] = array_merge($messages[$key], $message);
            } else {
                $messages[$key] = $message;
            }
        }

        self::saveMessages($messages);
        return $messages;
    }

    public static function getWorkflow()
    {
        self::ensureStorage();
        $workflow = self::readJsonFile(self::path(self::WORKFLOW_FILE), array());
        return is_array($workflow) ? $workflow : array();
    }

    public static function getWorkflowForUid($uid)
    {
        $workflow = self::getWorkflow();
        $key = (string)(int)$uid;

        $default = array(
            'department' => '',
            'project_id' => '',
            'project_name' => '',
            'assignee_id' => '',
            'assignee_name' => '',
            'status' => '미확인',
            'priority' => '보통',
            'important' => false,
            'memo' => '',
            'reply_completed' => false,
            'reply_completed_at' => '',
            'reply_completed_by' => '',
            'updated_at' => '',
            'updated_by' => ''
        );

        if (!isset($workflow[$key]) || !is_array($workflow[$key])) {
            return $default;
        }

        return array_merge($default, $workflow[$key]);
    }

    public static function updateWorkflow($uid, $changes, $updatedBy)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            throw new \InvalidArgumentException('메일 UID가 올바르지 않습니다.');
        }

        $workflow = self::getWorkflow();
        $key = (string)$uid;
        $current = self::getWorkflowForUid($uid);

        $allowed = array(
            'department', 'project_id', 'project_name', 'assignee_id', 'assignee_name',
            'status', 'priority', 'important', 'memo', 'reply_completed',
            'reply_completed_at', 'reply_completed_by'
        );

        foreach ($allowed as $field) {
            if (array_key_exists($field, $changes)) {
                $current[$field] = $changes[$field];
            }
        }

        $current['updated_at'] = date('Y-m-d H:i:s');
        $current['updated_by'] = (string)$updatedBy;
        $workflow[$key] = $current;

        self::writeJsonFile(self::path(self::WORKFLOW_FILE), $workflow);
        return $current;
    }

    public static function getSyncState()
    {
        self::ensureStorage();
        $state = self::readJsonFile(self::path(self::SYNC_FILE), array());
        return is_array($state) ? $state : array();
    }

    public static function saveSyncState($changes)
    {
        $state = self::getSyncState();
        if (!is_array($changes)) {
            $changes = array();
        }
        $state = array_merge($state, $changes);
        self::writeJsonFile(self::path(self::SYNC_FILE), $state);
        return $state;
    }

    public static function resetMailData()
    {
        self::ensureStorage();
        self::writeJsonFile(self::path(self::MESSAGES_FILE), array());
        self::writeJsonFile(self::path(self::WORKFLOW_FILE), array());
        self::writeJsonFile(self::path(self::SYNC_FILE), array(
            'last_success_at' => '',
            'last_error_at' => '',
            'last_error' => '',
            'last_uid' => 0,
            'last_batch_count' => 0,
            'last_search_count' => 0,
            'last_mode' => '',
            'completed_initial_sync' => false
        ));
    }

    public static function path($fileName)
    {
        return self::rootPath() . DIRECTORY_SEPARATOR . basename((string)$fileName);
    }

    /**
     * 같은 시간에 여러 사용자가 동기화를 실행하지 못하도록 잠금 파일을 잡습니다.
     * 반환된 파일 손잡이는 작업 종료 후 releaseLock()으로 반드시 해제해야 합니다.
     */
    public static function acquireLock($name)
    {
        self::ensureStorage();
        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)$name);
        if ($safeName === '') {
            $safeName = 'operation';
        }

        $handle = @fopen(self::path($safeName . '.lock'), 'c+');
        if (!$handle) {
            throw new \RuntimeException('공용메일 작업 잠금 파일을 만들 수 없습니다.');
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        @ftruncate($handle, 0);
        @fwrite($handle, date('Y-m-d H:i:s') . ' pid=' . (function_exists('getmypid') ? (int)getmypid() : 0));
        @fflush($handle);
        return $handle;
    }

    public static function releaseLock($handle)
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public static function readJsonFile($path, $default)
    {
        if (!is_file($path)) {
            return $default;
        }

        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return $default;
        }

        @flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);

        if ($content === false || trim($content) === '') {
            return $default;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function writeJsonFile($path, $value)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new \RuntimeException('저장 폴더를 만들 수 없습니다: ' . $dir);
            }
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('JSON 데이터 변환에 실패했습니다.');
        }

        $tempPath = $path . '.tmp.' . uniqid('', true);
        $fp = @fopen($tempPath, 'wb');
        if (!$fp) {
            throw new \RuntimeException('임시 저장 파일을 만들 수 없습니다.');
        }

        if (!@flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tempPath);
            throw new \RuntimeException('저장 파일 잠금에 실패했습니다.');
        }

        $written = fwrite($fp, $json);
        fflush($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);

        if ($written === false) {
            @unlink($tempPath);
            throw new \RuntimeException('설정 파일 저장에 실패했습니다.');
        }

        @chmod($tempPath, 0660);

        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new \RuntimeException('저장 파일 교체에 실패했습니다.');
        }
    }

    private static function ensureEncryptionKey()
    {
        $path = self::path(self::KEY_FILE);
        if (is_file($path)) {
            return;
        }

        $strong = false;
        $bytes = openssl_random_pseudo_bytes(32, $strong);
        if ($bytes === false || strlen($bytes) < 32) {
            throw new \RuntimeException('암호화 키를 생성할 수 없습니다.');
        }

        if (@file_put_contents($path, base64_encode($bytes), LOCK_EX) === false) {
            throw new \RuntimeException('암호화 키를 저장할 수 없습니다.');
        }
        @chmod($path, 0600);
    }

    private static function getEncryptionKey()
    {
        self::ensureEncryptionKey();
        $encoded = trim((string)@file_get_contents(self::path(self::KEY_FILE)));
        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('공용메일 암호화 키가 손상되었습니다.');
        }

        return $key;
    }

    private static function encryptSecret($plainText)
    {
        $plainText = (string)$plainText;
        if ($plainText === '') {
            return '';
        }

        $key = self::getEncryptionKey();
        $strong = false;
        $iv = openssl_random_pseudo_bytes(16, $strong);
        if ($iv === false || strlen($iv) !== 16) {
            throw new \RuntimeException('암호화 초기값을 생성할 수 없습니다.');
        }

        $cipherText = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) {
            throw new \RuntimeException('애플리케이션 비밀번호 암호화에 실패했습니다.');
        }

        $mac = hash_hmac('sha256', $iv . $cipherText, $key, true);
        return base64_encode($iv . $mac . $cipherText);
    }

    private static function decryptSecret($encoded)
    {
        $encoded = trim((string)$encoded);
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 49) {
            throw new \RuntimeException('저장된 애플리케이션 비밀번호 형식이 올바르지 않습니다.');
        }

        $key = self::getEncryptionKey();
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipherText = substr($raw, 48);
        $expected = hash_hmac('sha256', $iv . $cipherText, $key, true);

        $valid = function_exists('hash_equals') ? hash_equals($expected, $mac) : ($expected === $mac);
        if (!$valid) {
            throw new \RuntimeException('저장된 애플리케이션 비밀번호 검증에 실패했습니다.');
        }

        $plainText = openssl_decrypt($cipherText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plainText === false) {
            throw new \RuntimeException('애플리케이션 비밀번호 복호화에 실패했습니다.');
        }

        return $plainText;
    }
}
