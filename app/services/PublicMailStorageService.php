<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailStorageService.php
 *
 * 네이버 메일 설정, 메일 목록, 처리상태, 전체수집 진행상태와
 * Google Drive 저장 메타데이터를 JSON으로 관리합니다.
 * 첨부파일 원본은 서버에 저장하지 않습니다. PHP 5.6 호환 코드입니다.
 */

namespace App\Services;

class PublicMailStorageService
{
    const VERSION = '1.7.5';
    const SETTINGS_FILE = 'settings.json';
    const MESSAGES_FILE = 'messages.json';
    const WORKFLOW_FILE = 'workflow.json';
    const SYNC_FILE = 'sync_state.json';
    const KEY_FILE = 'secret.key';
    const DRIVE_RECORDS_FILE = 'drive_records.json';
    const INDEX_FILE = 'mail_index.json';
    const BODY_CACHE_DIR = 'body_cache';
    const BODY_CACHE_VERSION = 8;

    public static function rootPath()
    {
        if (function_exists('cpms_storage_root')) {
            return rtrim((string)cpms_storage_root(), '/\\') . DIRECTORY_SEPARATOR . 'public_mail';
        }
        return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'public_mail';
    }

    public static function settingsDefaults()
    {
        return array(
            'enabled' => false,
            'provider' => 'naver',
            'username' => '',
            'encrypted_password' => '',
            'encrypted_cron_token' => '',
            'imap_host' => 'imap.naver.com',
            'imap_port' => 993,
            'imap_security' => 'ssl',
            'batch_size' => 100,
            'use_gpt_classifier' => false,
            'include_spam' => false,
            'include_trash' => false,
            'updated_at' => '',
            'updated_by' => '',
            'store_attachment_cache' => false
        );
    }

    public static function syncDefaults()
    {
        return array(
            'last_success_at' => '',
            'last_error_at' => '',
            'last_error' => '',
            'last_batch_count' => 0,
            'last_search_count' => 0,
            'last_mode' => '',
            'mailbox_total' => 0,
            'remaining_count' => 0,
            'completed_initial_sync' => false,
            'last_cron_at' => '',
            'last_cron_started_at' => '',
            'last_cron_finished_at' => '',
            'last_cron_result' => '',
            'last_cron_status' => '',
            'last_cron_duration_ms' => 0,
            'mailboxes' => array(),
            'full_import' => array(
                'active' => false,
                'paused' => false,
                'cancelled' => false,
                'started_at' => '',
                'finished_at' => '',
                'current_mailbox_index' => 0,
                'mailbox_order' => array(),
                'processed_count' => 0,
                'total_count' => 0,
                'remaining_count' => 0,
                'last_message' => ''
            ),
            'metadata_repair' => array(
                'active' => false,
                'paused' => false,
                'cancelled' => false,
                'started_at' => '',
                'finished_at' => '',
                'last_run_at' => '',
                'cursor' => 0,
                'total_count' => 0,
                'target_count' => 0,
                'processed_count' => 0,
                'repaired_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'remaining_count' => 0,
                'last_message' => '',
                'last_error' => ''
            )
        );
    }

    public static function ensureStorage()
    {
        $root = self::rootPath();
        if (!is_dir($root)) {
            if (!@mkdir($root, 0770, true) && !is_dir($root)) {
                throw new \RuntimeException('네이버 메일 저장 폴더를 만들 수 없습니다: ' . $root);
            }
        }

        $defaults = array(
            self::SETTINGS_FILE => self::settingsDefaults(),
            self::MESSAGES_FILE => array(),
            self::WORKFLOW_FILE => array(),
            self::SYNC_FILE => self::syncDefaults(),
            self::DRIVE_RECORDS_FILE => array(),
            self::INDEX_FILE => array()
        );
        foreach ($defaults as $fileName => $defaultValue) {
            $path = $root . DIRECTORY_SEPARATOR . $fileName;
            if (!is_file($path)) self::writeJsonFile($path, $defaultValue);
        }
        $bodyCacheDir = $root . DIRECTORY_SEPARATOR . self::BODY_CACHE_DIR;
        if (!is_dir($bodyCacheDir)) {
            if (!@mkdir($bodyCacheDir, 0770, true) && !is_dir($bodyCacheDir)) {
                throw new \RuntimeException('메일 본문 캐시 폴더를 만들 수 없습니다: ' . $bodyCacheDir);
            }
        }

        self::ensureEncryptionKey();
        self::ensureCronToken();
        self::cleanupLegacyAttachmentCaches();
        return $root;
    }

    public static function path($fileName)
    {
        return self::rootPath() . DIRECTORY_SEPARATOR . basename((string)$fileName);
    }

    public static function getSettings($includeSecrets)
    {
        self::ensureStorage();
        $saved = self::readJsonFile(self::path(self::SETTINGS_FILE), array());
        if (!is_array($saved)) $saved = array();
        $settings = array_merge(self::settingsDefaults(), $saved);
        if ($includeSecrets) {
            $settings['password'] = self::decryptSecret(isset($settings['encrypted_password']) ? $settings['encrypted_password'] : '');
            $settings['cron_token'] = self::decryptSecret(isset($settings['encrypted_cron_token']) ? $settings['encrypted_cron_token'] : '');
        }
        unset($settings['encrypted_password'], $settings['encrypted_cron_token']);
        return $settings;
    }

    public static function saveSettings($input, $updatedBy)
    {
        self::ensureStorage();
        $current = self::readJsonFile(self::path(self::SETTINGS_FILE), array());
        if (!is_array($current)) $current = array();
        $current = array_merge(self::settingsDefaults(), $current);

        $username = isset($input['username']) ? trim((string)$input['username']) : '';
        $password = isset($input['password']) ? trim((string)$input['password']) : '';
        $batchSize = isset($input['batch_size']) ? (int)$input['batch_size'] : 100;
        if ($batchSize < 20) $batchSize = 20;
        if ($batchSize > 200) $batchSize = 200;
        if ($username === '') throw new \InvalidArgumentException('네이버 아이디를 입력하세요.');

        $encryptedPassword = isset($current['encrypted_password']) ? (string)$current['encrypted_password'] : '';
        if ($password !== '') $encryptedPassword = self::encryptSecret($password);
        if ($encryptedPassword === '') throw new \InvalidArgumentException('애플리케이션 비밀번호를 입력하세요.');

        $encryptedCronToken = isset($current['encrypted_cron_token']) ? (string)$current['encrypted_cron_token'] : '';
        if ($encryptedCronToken === '') $encryptedCronToken = self::encryptSecret(self::generateToken());

        $settings = array(
            'enabled' => !empty($input['enabled']),
            'provider' => 'naver',
            'username' => $username,
            'encrypted_password' => $encryptedPassword,
            'encrypted_cron_token' => $encryptedCronToken,
            'imap_host' => 'imap.naver.com',
            'imap_port' => 993,
            'imap_security' => 'ssl',
            'batch_size' => $batchSize,
            'use_gpt_classifier' => !empty($input['use_gpt_classifier']),
            'include_spam' => !empty($input['include_spam']),
            'include_trash' => !empty($input['include_trash']),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => (string)$updatedBy,
            'store_attachment_cache' => false
        );
        self::writeJsonFile(self::path(self::SETTINGS_FILE), $settings);
        return self::getSettings(false);
    }

    public static function getCronToken()
    {
        self::ensureStorage();
        $saved = self::readJsonFile(self::path(self::SETTINGS_FILE), array());
        $encrypted = isset($saved['encrypted_cron_token']) ? (string)$saved['encrypted_cron_token'] : '';
        if ($encrypted === '') {
            self::ensureCronToken();
            $saved = self::readJsonFile(self::path(self::SETTINGS_FILE), array());
            $encrypted = isset($saved['encrypted_cron_token']) ? (string)$saved['encrypted_cron_token'] : '';
        }
        return self::decryptSecret($encrypted);
    }

    public static function regenerateCronToken($updatedBy)
    {
        self::ensureStorage();
        $saved = self::readJsonFile(self::path(self::SETTINGS_FILE), array());
        if (!is_array($saved)) $saved = array();
        $token = self::generateToken();
        $saved['encrypted_cron_token'] = self::encryptSecret($token);
        $saved['updated_at'] = date('Y-m-d H:i:s');
        $saved['updated_by'] = (string)$updatedBy;
        self::writeJsonFile(self::path(self::SETTINGS_FILE), array_merge(self::settingsDefaults(), $saved));
        return $token;
    }

    private static function ensureCronToken()
    {
        $path = self::path(self::SETTINGS_FILE);
        $saved = self::readJsonFile($path, array());
        if (!is_array($saved)) $saved = array();
        if (!empty($saved['encrypted_cron_token'])) return;
        $saved = array_merge(self::settingsDefaults(), $saved);
        $saved['encrypted_cron_token'] = self::encryptSecret(self::generateToken());
        self::writeJsonFile($path, $saved);
    }

    private static function generateToken()
    {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes(32, $strong);
        if ($bytes === false || strlen($bytes) < 32) throw new \RuntimeException('자동동기화 보안키를 생성할 수 없습니다.');
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function messageKey($mailbox, $uid)
    {
        $mailbox = trim((string)$mailbox);
        if ($mailbox === '') $mailbox = 'INBOX';
        $uid = (int)$uid;
        if (strcasecmp($mailbox, 'INBOX') === 0) return (string)$uid;
        $encoded = rtrim(strtr(base64_encode($mailbox), '+/', '-_'), '=');
        return 'm_' . $encoded . '_' . $uid;
    }

    public static function parseMessageKey($messageKey)
    {
        $messageKey = trim((string)$messageKey);
        if (preg_match('/^[0-9]+$/', $messageKey)) {
            return array('mailbox' => 'INBOX', 'uid' => (int)$messageKey, 'message_key' => $messageKey);
        }
        if (preg_match('/^m_([A-Za-z0-9_-]+)_([0-9]+)$/', $messageKey, $matches)) {
            $encoded = strtr($matches[1], '-_', '+/');
            $padding = strlen($encoded) % 4;
            if ($padding > 0) $encoded .= str_repeat('=', 4 - $padding);
            $mailbox = base64_decode($encoded, true);
            if ($mailbox !== false && $mailbox !== '') {
                return array('mailbox' => $mailbox, 'uid' => (int)$matches[2], 'message_key' => $messageKey);
            }
        }
        return array('mailbox' => 'INBOX', 'uid' => 0, 'message_key' => $messageKey);
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
        if (!is_array($messages)) $messages = array();
        self::writeJsonFile(self::path(self::MESSAGES_FILE), $messages);
        self::refreshIndexSafely($messages, null);
    }

    public static function upsertMessages($newMessages)
    {
        $messages = self::getMessages();
        foreach ($newMessages as $messageKey => $message) {
            $key = (string)$messageKey;
            $messages[$key] = isset($messages[$key]) && is_array($messages[$key])
                ? array_merge($messages[$key], $message)
                : $message;
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

    public static function getWorkflowForKey($messageKey)
    {
        $workflow = self::getWorkflow();
        $key = (string)$messageKey;
        $default = array(
            'department' => '', 'project_id' => '', 'project_name' => '',
            'assignee_id' => '', 'assignee_name' => '', 'status' => '미확인',
            'priority' => '보통', 'important' => false, 'memo' => '',
            'reply_completed' => false, 'reply_completed_at' => '',
            'reply_completed_by' => '', 'updated_at' => '', 'updated_by' => ''
        );
        return isset($workflow[$key]) && is_array($workflow[$key])
            ? array_merge($default, $workflow[$key]) : $default;
    }

    public static function updateWorkflow($messageKey, $changes, $updatedBy)
    {
        $parsed = self::parseMessageKey($messageKey);
        if ((int)$parsed['uid'] <= 0) throw new \InvalidArgumentException('메일 식별값이 올바르지 않습니다.');
        $workflow = self::getWorkflow();
        $key = (string)$parsed['message_key'];
        $default = array(
            'department' => '', 'project_id' => '', 'project_name' => '',
            'assignee_id' => '', 'assignee_name' => '', 'status' => '미확인',
            'priority' => '보통', 'important' => false, 'memo' => '',
            'reply_completed' => false, 'reply_completed_at' => '',
            'reply_completed_by' => '', 'updated_at' => '', 'updated_by' => ''
        );
        $current = isset($workflow[$key]) && is_array($workflow[$key]) ? array_merge($default, $workflow[$key]) : $default;
        $allowed = array('department','project_id','project_name','assignee_id','assignee_name','status','priority','important','memo','reply_completed','reply_completed_at','reply_completed_by');
        foreach ($allowed as $field) if (array_key_exists($field, $changes)) $current[$field] = $changes[$field];
        $current['updated_at'] = date('Y-m-d H:i:s');
        $current['updated_by'] = (string)$updatedBy;
        $workflow[$key] = $current;
        self::writeJsonFile(self::path(self::WORKFLOW_FILE), $workflow);
        self::refreshIndexSafely(null, $workflow);
        return $current;
    }

    public static function getSyncState()
    {
        self::ensureStorage();
        $saved = self::readJsonFile(self::path(self::SYNC_FILE), array());
        if (!is_array($saved)) $saved = array();
        $state = array_merge(self::syncDefaults(), $saved);
        $defaults = self::syncDefaults();
        $state['full_import'] = array_merge($defaults['full_import'], isset($saved['full_import']) && is_array($saved['full_import']) ? $saved['full_import'] : array());
        $state['metadata_repair'] = array_merge($defaults['metadata_repair'], isset($saved['metadata_repair']) && is_array($saved['metadata_repair']) ? $saved['metadata_repair'] : array());
        if (!isset($state['mailboxes']) || !is_array($state['mailboxes'])) $state['mailboxes'] = array();
        return $state;
    }

    public static function saveSyncState($changes)
    {
        $state = self::getSyncState();
        if (!is_array($changes)) $changes = array();
        if (isset($changes['full_import']) && is_array($changes['full_import'])) {
            $changes['full_import'] = array_merge($state['full_import'], $changes['full_import']);
        }
        if (isset($changes['metadata_repair']) && is_array($changes['metadata_repair'])) {
            $changes['metadata_repair'] = array_merge($state['metadata_repair'], $changes['metadata_repair']);
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
        self::writeJsonFile(self::path(self::DRIVE_RECORDS_FILE), array());
        self::writeJsonFile(self::path(self::SYNC_FILE), self::syncDefaults());
        self::writeJsonFile(self::path(self::INDEX_FILE), array());
        self::cleanupDirectory(self::rootPath() . DIRECTORY_SEPARATOR . 'raw_cache');
        self::cleanupDirectory(self::rootPath() . DIRECTORY_SEPARATOR . 'attachment_cache');
        self::cleanupDirectory(self::rootPath() . DIRECTORY_SEPARATOR . self::BODY_CACHE_DIR);
    }

    public static function getCachedRawMessage($messageKey, $maxAgeSeconds)
    {
        return '';
    }

    public static function saveCachedRawMessage($messageKey, $raw)
    {
        return false;
    }

    public static function getCachedAttachment($messageKey, $partId, $maxAgeSeconds)
    {
        return null;
    }

    public static function saveCachedAttachment($messageKey, $partId, $attachment)
    {
        return false;
    }

    private static function rawCachePath($messageKey)
    {
        return self::rootPath() . DIRECTORY_SEPARATOR . 'raw_cache' . DIRECTORY_SEPARATOR . sha1((string)$messageKey) . '.eml';
    }

    private static function attachmentCachePath($messageKey, $partId)
    {
        $name = sha1((string)$messageKey . '|' . (string)$partId);
        return self::rootPath() . DIRECTORY_SEPARATOR . 'attachment_cache' . DIRECTORY_SEPARATOR . $name;
    }

    private static function readFreshCache($path, $maxAgeSeconds)
    {
        $maxAgeSeconds = max(60, (int)$maxAgeSeconds);
        if (!is_file($path)) return '';
        $modified = @filemtime($path);
        if ($modified === false || time() - (int)$modified > $maxAgeSeconds) {
            @unlink($path);
            return '';
        }
        $content = @file_get_contents($path);
        return is_string($content) ? $content : '';
    }

    private static function writeCache($path, $content)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return false;
        $temp = $path . '.tmp.' . uniqid('', true);
        if (@file_put_contents($temp, $content, LOCK_EX) === false) return false;
        @chmod($temp, 0660);
        if (!@rename($temp, $path)) { @unlink($temp); return false; }
        return true;
    }

    private static function cleanupCache($directoryName, $maxAgeSeconds, $maximumDeletes)
    {
        $dir = self::rootPath() . DIRECTORY_SEPARATOR . $directoryName;
        if (!is_dir($dir)) return 0;
        $files = @glob($dir . DIRECTORY_SEPARATOR . '*');
        if (!is_array($files)) return 0;
        $deleted = 0;
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $modified = @filemtime($file);
            if ($modified !== false && time() - (int)$modified > (int)$maxAgeSeconds) {
                if (@unlink($file)) $deleted++;
                if ($deleted >= (int)$maximumDeletes) break;
            }
        }
        return $deleted;
    }

    private static function cleanupDirectory($dir)
    {
        if (!is_dir($dir)) return;
        $files = @glob($dir . DIRECTORY_SEPARATOR . '*');
        if (is_array($files)) foreach ($files as $file) if (is_file($file)) @unlink($file);
    }


    /**
     * 메일 본문 표시용 캐시를 읽습니다.
     * 첨부파일 원본이나 EML 원문은 저장하지 않습니다.
     */
    public static function getBodyCache($messageKey)
    {
        self::ensureStorage();
        $path = self::bodyCachePath($messageKey);
        $cache = self::readJsonFile($path, array());
        if (!is_array($cache) || empty($cache['message_key'])) return null;

        /*
         * v1.7에서 만든 본문 캐시는 버전값이 없지만 본문 자체는 이미 저장되어 있습니다.
         * 이를 무조건 폐기하면 메일을 열 때마다 네이버 IMAP에 다시 연결되어 매우 느려집니다.
         * 파일 원본을 다시 받지 않고 저장된 HTML만 현재 형식으로 조용히 변환합니다.
         */
        $version = isset($cache['cache_version']) ? (int)$cache['cache_version'] : 0;
        $bodyHtml = isset($cache['body_html']) ? (string)$cache['body_html'] : '';
        $needsLocalUpgrade = ($version !== self::BODY_CACHE_VERSION)
            || strpos($bodyHtml, 'data-pm-external-src=') !== false
            || (strpos($bodyHtml, 'action=inline_image') !== false && strpos($bodyHtml, 'data-pm-inline-part=') === false);

        if ($needsLocalUpgrade) {
            $cache = self::upgradeBodyCacheLocally($messageKey, $cache);
        }
        return $cache;
    }

    private static function upgradeBodyCacheLocally($messageKey, $cache)
    {
        if (!is_array($cache)) return null;
        $html = isset($cache['body_html']) ? (string)$cache['body_html'] : '';
        $original = $html;

        // style/script 내용이 일반 글자로 노출되는 구버전 캐시를 서버 안에서 정리합니다.
        $html = preg_replace('#<(style|script|noscript|template|title)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('/<!--\[if.*?<!\[endif\]-->/is', '', $html);
        for ($i = 0; $i < 6; $i++) {
            $cleaned = preg_replace('/^\s*(?:(?:img\s*,\s*a\s+img|body|table|td|p|\.ExternalClass|#outlook)[^{\r\n]{0,160})\{[^{}]{0,3000}\}\s*/is', '', $html);
            if ($cleaned === $html) break;
            $html = $cleaned;
        }

        // v1.7의 차단 이미지 속성을 실제 지연로딩 이미지로 변환합니다.
        $html = preg_replace_callback('/\sdata-pm-external-src=("[^"]*"|\'[^\']*\')/i', function ($matches) {
            return ' src=' . $matches[1] . ' loading="lazy" decoding="async" referrerpolicy="no-referrer"';
        }, $html);
        $html = str_replace(array('pm-external-image is-blocked', ' is-blocked'), array('pm-external-image', ''), $html);

        // 기존 CID 이미지 URL을 한 번의 묶음 요청에 사용할 data 속성으로 바꿉니다.
        $placeholder = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
        $html = preg_replace_callback('#<img\b([^>]*?)\bsrc=("|\')([^"\']*public_mail_action\.php\?action=inline_image[^"\']*)\2([^>]*)>#is', function ($matches) use ($placeholder) {
            $url = html_entity_decode((string)$matches[3], ENT_QUOTES, 'UTF-8');
            $partId = '';
            if (preg_match('/(?:^|[?&])part_id=([^&]+)/', $url, $partMatch)) $partId = rawurldecode($partMatch[1]);
            if ($partId === '') return $matches[0];
            $before = trim((string)$matches[1]);
            $after = trim((string)$matches[4]);
            $attrs = trim($before . ' ' . $after);
            $attrs = preg_replace('/\sdata-pm-inline-(?:part|src)=("[^"]*"|\'[^\']*\')/i', '', $attrs);
            return '<img ' . $attrs . ' src="' . $placeholder . '" data-pm-inline-part="' . htmlspecialchars($partId, ENT_QUOTES, 'UTF-8') . '" data-pm-inline-src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" loading="lazy" decoding="async">';
        }, $html);

        $cache['body_html'] = $html;
        $cache['cache_version'] = self::BODY_CACHE_VERSION;
        $cache['source'] = isset($cache['source']) ? (string)$cache['source'] . '+local_upgrade_v172' : 'local_upgrade_v172';
        $cache['upgraded_at'] = date('Y-m-d H:i:s');

        // 내용이 같더라도 버전값을 기록해 다음 열람에서는 파일을 다시 검사하지 않습니다.
        self::writeJsonFile(self::bodyCachePath($messageKey), $cache);
        return $cache;
    }

    public static function hasBodyCache($messageKey)
    {
        return is_array(self::getBodyCache($messageKey));
    }

    public static function saveBodyCache($messageKey, $cache)
    {
        self::ensureStorage();
        $messageKey = trim((string)$messageKey);
        if ($messageKey === '') throw new \InvalidArgumentException('메일 본문 캐시 식별값이 비어 있습니다.');
        if (!is_array($cache)) $cache = array();
        $cache['message_key'] = $messageKey;
        $cache['cache_version'] = self::BODY_CACHE_VERSION;
        $cache['cached_at'] = date('Y-m-d H:i:s');
        self::writeJsonFile(self::bodyCachePath($messageKey), $cache);
        return $cache;
    }

    public static function deleteBodyCache($messageKey)
    {
        self::ensureStorage();
        $path = self::bodyCachePath($messageKey);
        return !is_file($path) || @unlink($path);
    }

    public static function bodyCachePath($messageKey)
    {
        $safe = sha1((string)$messageKey);
        return self::rootPath() . DIRECTORY_SEPARATOR . self::BODY_CACHE_DIR . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    public static function getUncachedMessageKeys($limit)
    {
        $limit = max(1, min(20, (int)$limit));
        $messages = self::getMessages();
        $rows = array();
        foreach ($messages as $key => $message) {
            if (!is_array($message)) continue;
            // 본문 캐시 파일이 있으면 버전값과 관계없이 네이버에서 다시 받지 않습니다.
            // 실제 열람 시 getBodyCache()가 저장된 HTML만 현재 형식으로 빠르게 변환합니다.
            if (is_file(self::bodyCachePath($key))) continue;
            $rows[] = array(
                'key' => (string)$key,
                'timestamp' => isset($message['timestamp']) ? (int)$message['timestamp'] : 0
            );
        }
        usort($rows, array(__CLASS__, 'compareUncachedMessageRows'));
        $result = array();
        foreach ($rows as $row) {
            $result[] = $row['key'];
            if (count($result) >= $limit) break;
        }
        return $result;
    }

    public static function compareUncachedMessageRows($a, $b)
    {
        $at = isset($a['timestamp']) ? (int)$a['timestamp'] : 0;
        $bt = isset($b['timestamp']) ? (int)$b['timestamp'] : 0;
        if ($at === $bt) return strcmp(isset($b['key']) ? $b['key'] : '', isset($a['key']) ? $a['key'] : '');
        return $at > $bt ? -1 : 1;
    }



    public static function getBodyCacheStats()
    {
        self::ensureStorage();
        $messages = self::getMessages();
        $total = 0; $cached = 0; $missing = 0; $legacy = 0;
        foreach ($messages as $key => $message) {
            if (!is_array($message)) continue;
            $total++;
            $path = self::bodyCachePath($key);
            if (!is_file($path)) { $missing++; continue; }
            $cached++;
            $raw = self::readJsonFile($path, array());
            $version = is_array($raw) && isset($raw['cache_version']) ? (int)$raw['cache_version'] : 0;
            if ($version !== self::BODY_CACHE_VERSION) $legacy++;
        }
        $dir = self::rootPath() . DIRECTORY_SEPARATOR . self::BODY_CACHE_DIR;
        return array(
            'storage_writable' => is_dir($dir) && is_writable($dir),
            'total_messages' => $total,
            'cached_messages' => $cached,
            'missing_messages' => $missing,
            'legacy_messages' => $legacy,
            'cache_version' => self::BODY_CACHE_VERSION
        );
    }

    public static function getDriveRecords()
    {
        self::ensureStorage();
        $records = self::readJsonFile(self::path(self::DRIVE_RECORDS_FILE), array());
        return is_array($records) ? $records : array();
    }

    public static function getDriveRecordsForMessage($messageKey)
    {
        $messageKey = (string)$messageKey;
        $records = self::getDriveRecords();
        $matched = array();
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            if (isset($record['message_key']) && (string)$record['message_key'] === $messageKey) $matched[] = $record;
        }
        return $matched;
    }

    public static function saveDriveRecord($record)
    {
        if (!is_array($record) || empty($record['record_id'])) throw new \InvalidArgumentException('Google Drive 저장기록이 올바르지 않습니다.');
        $records = self::getDriveRecords();
        $records[(string)$record['record_id']] = $record;
        self::writeJsonFile(self::path(self::DRIVE_RECORDS_FILE), $records);
        return $record;
    }

    public static function findDriveRecord($messageKey, $partId)
    {
        $records = self::getDriveRecords();
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            if (isset($record['message_key'], $record['part_id']) && (string)$record['message_key'] === (string)$messageKey && (string)$record['part_id'] === (string)$partId) return $record;
        }
        return null;
    }

    public static function cleanupLegacyAttachmentCaches()
    {
        $root = self::rootPath();
        foreach (array('attachment_cache','raw_cache') as $name) {
            $dir = $root . DIRECTORY_SEPARATOR . $name;
            if (is_dir($dir)) self::cleanupDirectory($dir);
        }
    }


    /**
     * 메일 목록/처리상태가 바뀐 경우 가벼운 화면 색인을 갱신합니다.
     * 색인 생성 실패가 메일 저장 자체를 막지 않도록 예외는 내부에서 처리합니다.
     */
    public static function refreshIndexSafely($messages, $workflow)
    {
        try {
            require_once __DIR__ . '/PublicMailIndexService.php';
            if (!class_exists('App\Services\PublicMailIndexService')) return null;
            if (!is_array($messages)) $messages = self::getMessages();
            if (!is_array($workflow)) $workflow = self::getWorkflow();
            return PublicMailIndexService::rebuildSafely($messages, $workflow);
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function acquireLock($name)
    {
        self::ensureStorage();
        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)$name);
        if ($safeName === '') $safeName = 'operation';
        $handle = @fopen(self::path($safeName . '.lock'), 'c+');
        if (!$handle) throw new \RuntimeException('네이버 메일 작업 잠금 파일을 만들 수 없습니다.');
        if (!@flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return false; }
        @ftruncate($handle, 0);
        @fwrite($handle, date('Y-m-d H:i:s') . ' pid=' . (function_exists('getmypid') ? (int)getmypid() : 0));
        @fflush($handle);
        return $handle;
    }

    public static function releaseLock($handle)
    {
        if (is_resource($handle)) { @flock($handle, LOCK_UN); @fclose($handle); }
    }

    public static function readJsonFile($path, $default)
    {
        if (!is_file($path)) return $default;
        $fp = @fopen($path, 'rb');
        if (!$fp) return $default;
        @flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);
        if ($content === false || trim($content) === '') return $default;
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function writeJsonFile($path, $value)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) throw new \RuntimeException('저장 폴더를 만들 수 없습니다: ' . $dir);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) throw new \RuntimeException('JSON 데이터 변환에 실패했습니다.');
        $temp = $path . '.tmp.' . uniqid('', true);
        $fp = @fopen($temp, 'wb');
        if (!$fp) throw new \RuntimeException('임시 저장 파일을 만들 수 없습니다.');
        if (!@flock($fp, LOCK_EX)) { fclose($fp); @unlink($temp); throw new \RuntimeException('저장 파일 잠금에 실패했습니다.'); }
        $written = fwrite($fp, $json);
        fflush($fp); @flock($fp, LOCK_UN); fclose($fp);
        if ($written === false) { @unlink($temp); throw new \RuntimeException('설정 파일 저장에 실패했습니다.'); }
        @chmod($temp, 0660);
        if (!@rename($temp, $path)) { @unlink($temp); throw new \RuntimeException('저장 파일 교체에 실패했습니다.'); }
    }

    private static function ensureEncryptionKey()
    {
        $path = self::path(self::KEY_FILE);
        if (is_file($path)) return;
        $strong = false;
        $bytes = openssl_random_pseudo_bytes(32, $strong);
        if ($bytes === false || strlen($bytes) < 32) throw new \RuntimeException('암호화 키를 생성할 수 없습니다.');
        if (@file_put_contents($path, base64_encode($bytes), LOCK_EX) === false) throw new \RuntimeException('암호화 키를 저장할 수 없습니다.');
        @chmod($path, 0600);
    }

    private static function getEncryptionKey()
    {
        self::ensureEncryptionKey();
        $encoded = trim((string)@file_get_contents(self::path(self::KEY_FILE)));
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) throw new \RuntimeException('네이버 메일 암호화 키가 손상되었습니다.');
        return $key;
    }

    private static function encryptSecret($plainText)
    {
        $plainText = (string)$plainText;
        if ($plainText === '') return '';
        $key = self::getEncryptionKey();
        $strong = false;
        $iv = openssl_random_pseudo_bytes(16, $strong);
        if ($iv === false || strlen($iv) !== 16) throw new \RuntimeException('암호화 초기값을 생성할 수 없습니다.');
        $cipherText = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) throw new \RuntimeException('보안정보 암호화에 실패했습니다.');
        $mac = hash_hmac('sha256', $iv . $cipherText, $key, true);
        return base64_encode($iv . $mac . $cipherText);
    }

    private static function decryptSecret($encoded)
    {
        $encoded = trim((string)$encoded);
        if ($encoded === '') return '';
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 49) throw new \RuntimeException('저장된 보안정보 형식이 올바르지 않습니다.');
        $key = self::getEncryptionKey();
        $iv = substr($raw, 0, 16); $mac = substr($raw, 16, 32); $cipherText = substr($raw, 48);
        $expected = hash_hmac('sha256', $iv . $cipherText, $key, true);
        $valid = function_exists('hash_equals') ? hash_equals($expected, $mac) : ($expected === $mac);
        if (!$valid) throw new \RuntimeException('저장된 보안정보 검증에 실패했습니다.');
        $plain = openssl_decrypt($cipherText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) throw new \RuntimeException('보안정보 복호화에 실패했습니다.');
        return $plain;
    }
}
