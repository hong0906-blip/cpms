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
    const VERSION = '1.7.16';
    const SETTINGS_FILE = 'settings.json';
    const MESSAGES_FILE = 'messages.json';
    const WORKFLOW_FILE = 'workflow.json';
    const SYNC_FILE = 'sync_state.json';
    const KEY_FILE = 'secret.key';
    const DRIVE_RECORDS_FILE = 'drive_records.json';
    const INDEX_FILE = 'mail_index.json';
    const TITLE_REFRESH_QUEUE_FILE = 'title_refresh_queue.json';
    const TITLE_REFRESH_UPDATES_FILE = 'title_refresh_updates.json';
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
                'status' => 'idle',
                'last_run_at' => '',
                'last_run_result' => '',
                'last_run_processed_count' => 0,
                'last_run_repaired_count' => 0,
                'last_http_ping_at' => '',
                'last_http_status' => '',
                'lock_status' => 'idle',
                'lock_acquired_at' => '',
                'lock_released_at' => '',
                'heartbeat_at' => '',
                'queue_version' => 2,
                'target_keys' => array(),
                'message_attempts' => array(),
                'cursor' => 0,
                'total_count' => 0,
                'target_count' => 0,
                'processed_count' => 0,
                'repaired_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'remaining_count' => 0,
                'batch_size_current' => 50,
                'recommended_batch_size' => 50,
                'retry_count' => 0,
                'consecutive_errors' => 0,
                'last_run_duration_ms' => 0,
                'last_checkpoint_at' => '',
                'last_index_refresh_processed' => 0,
                'last_error_code' => '',
                'last_message' => '',
                'last_error' => ''
            ),
            'title_normalization' => array(
                'enabled' => false,
                'status' => 'disabled_for_speed',
                'last_run_at' => '',
                'checked_count' => 0,
                'changed_count' => 0,
                'unresolved_count' => 0,
                'last_message' => '일반 메일 화면에서는 제목 보정 작업을 실행하지 않습니다.'
            ),
            'title_refresh' => array(
                'active' => false,
                'paused' => false,
                'cancelled' => false,
                'status' => 'ready',
                'phase' => 'idle',
                'started_at' => '',
                'finished_at' => '',
                'last_run_at' => '',
                'worker_heartbeat_at' => '',
                'queue_version' => 1,
                'cursor' => 0,
                'merge_cursor' => 0,
                'retry_cursor' => -1,
                'retry_count' => 0,
                'consecutive_errors' => 0,
                'total_count' => 0,
                'processed_count' => 0,
                'updated_count' => 0,
                'merged_count' => 0,
                'failed_count' => 0,
                'remaining_count' => 0,
                'last_batch_count' => 0,
                'mode' => 'businesson_broken_only',
                'target_name' => '비즈니스온·스마트빌 깨진 제목',
                'sender_domain' => 'businesson.co.kr',
                'related_count' => 0,
                'broken_count' => 0,
                'normal_count' => 0,
                'skipped_count' => 0,
                'current_position' => -1,
                'current_mailbox' => '',
                'current_uid' => 0,
                'inflight' => array(),
                'skipped_items' => array(),
                'last_error_code' => '',
                'last_message' => '비즈니스온·스마트빌 깨진 제목 복구를 아직 시작하지 않았습니다.',
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
            self::INDEX_FILE => array(),
            self::TITLE_REFRESH_QUEUE_FILE => array(
                'version' => 1,
                'created_at' => '',
                'total_count' => 0,
                'items' => array()
            ),
            self::TITLE_REFRESH_UPDATES_FILE => array(
                'version' => 1,
                'updated_at' => '',
                'items' => array()
            )
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


    /**
     * 원본 제목 재수집 대기열을 읽습니다.
     * 대기열은 작업 시작 시 한 번만 만들고 이후에는 수정하지 않습니다.
     */
    public static function getTitleRefreshQueue()
    {
        self::ensureStorage();
        $default = array('version'=>1,'created_at'=>'','total_count'=>0,'items'=>array());
        $queue = self::readJsonFile(self::path(self::TITLE_REFRESH_QUEUE_FILE), $default);
        if (!is_array($queue)) $queue = $default;
        if (!isset($queue['items']) || !is_array($queue['items'])) $queue['items'] = array();
        $queue['total_count'] = count($queue['items']);
        return $queue;
    }

    public static function saveTitleRefreshQueue($items)
    {
        self::ensureStorage();
        if (!is_array($items)) $items = array();
        $queue = array(
            'version'=>1,
            'created_at'=>date('Y-m-d H:i:s'),
            'total_count'=>count($items),
            'items'=>array_values($items)
        );
        self::writeJsonFile(self::path(self::TITLE_REFRESH_QUEUE_FILE), $queue);
        return $queue;
    }

    public static function getTitleRefreshUpdates()
    {
        self::ensureStorage();
        $default = array('version'=>1,'updated_at'=>'','items'=>array());
        $updates = self::readJsonFile(self::path(self::TITLE_REFRESH_UPDATES_FILE), $default);
        if (!is_array($updates)) $updates = $default;
        if (!isset($updates['items']) || !is_array($updates['items'])) $updates['items'] = array();
        return $updates;
    }

    public static function saveTitleRefreshUpdates($items)
    {
        self::ensureStorage();
        if (!is_array($items)) $items = array();
        $updates = array(
            'version'=>1,
            'updated_at'=>date('Y-m-d H:i:s'),
            'items'=>$items
        );
        self::writeJsonFile(self::path(self::TITLE_REFRESH_UPDATES_FILE), $updates);
        return $updates;
    }

    public static function clearTitleRefreshWorkFiles()
    {
        self::ensureStorage();
        self::writeJsonFile(self::path(self::TITLE_REFRESH_QUEUE_FILE), array(
            'version'=>1,'created_at'=>'','total_count'=>0,'items'=>array()
        ));
        self::writeJsonFile(self::path(self::TITLE_REFRESH_UPDATES_FILE), array(
            'version'=>1,'updated_at'=>'','items'=>array()
        ));
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
        if (!is_array($messages)) return array();

        /*
         * v1.7.12: 평상시 메일 화면에서는 저장된 값을 그대로 반환합니다.
         * 전체 제목 변환이나 네이버 재조회는 연동 설정의 전용 작업에서만 실행합니다.
         */
        return $messages;
    }

    public static function saveMessages($messages)
    {
        self::ensureStorage();
        if (!is_array($messages)) $messages = array();
        self::writeJsonFile(self::path(self::MESSAGES_FILE), $messages);
        self::refreshIndexSafely($messages, null);
    }

    /**
     * 대량 작업 중간 체크포인트용 저장입니다.
     * messages.json만 안전하게 저장하고, 목록 색인은 묶음이 끝날 때 갱신합니다.
     */
    public static function saveMessagesCheckpoint($messages)
    {
        self::ensureStorage();
        if (!is_array($messages)) $messages = array();
        self::writeJsonFile(self::path(self::MESSAGES_FILE), $messages);
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
        $state['metadata_repair'] = self::sanitizeUtf8Value($state['metadata_repair']);
        $state['title_normalization'] = array_merge($defaults['title_normalization'], isset($saved['title_normalization']) && is_array($saved['title_normalization']) ? $saved['title_normalization'] : array());
        $state['title_normalization'] = self::sanitizeUtf8Value($state['title_normalization']);
        $state['title_refresh'] = array_merge($defaults['title_refresh'], isset($saved['title_refresh']) && is_array($saved['title_refresh']) ? $saved['title_refresh'] : array());
        $state['title_refresh'] = self::sanitizeUtf8Value($state['title_refresh']);
        if (!isset($state['mailboxes']) || !is_array($state['mailboxes'])) $state['mailboxes'] = array();

        /*
         * flock은 PHP 프로세스가 끝나면 자동 해제됩니다. 다만 이전 실행이 남긴
         * lock_status 값은 화면에 계속 보일 수 있으므로 실제 파일 잠금과 비교해 정리합니다.
         */
        $lockInfo = self::getOperationLockStatus('metadata_repair');
        $state['metadata_repair']['lock_is_active'] = !empty($lockInfo['locked']);
        $state['metadata_repair']['lock_file_age_seconds'] = isset($lockInfo['age_seconds']) ? (int)$lockInfo['age_seconds'] : 0;
        $lockAt = isset($state['metadata_repair']['lock_acquired_at']) ? strtotime((string)$state['metadata_repair']['lock_acquired_at']) : false;
        if (empty($lockInfo['locked']) && $lockAt !== false && (time() - $lockAt) > 90 && $state['metadata_repair']['lock_status'] !== 'idle') {
            $state['metadata_repair']['lock_status'] = 'idle';
            $state['metadata_repair']['lock_acquired_at'] = '';
            $state['metadata_repair']['lock_released_at'] = date('Y-m-d H:i:s');
            $saved['metadata_repair'] = $state['metadata_repair'];
            self::writeJsonFile(self::path(self::SYNC_FILE), array_merge(self::syncDefaults(), $saved));
        }
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
        if (isset($changes['title_normalization']) && is_array($changes['title_normalization'])) {
            $changes['title_normalization'] = array_merge($state['title_normalization'], $changes['title_normalization']);
        }
        if (isset($changes['title_refresh']) && is_array($changes['title_refresh'])) {
            $changes['title_refresh'] = array_merge($state['title_refresh'], $changes['title_refresh']);
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
        self::clearTitleRefreshWorkFiles();
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
     * 메일 제목과 주소 표시문자에 남은 RFC 2047, CP949, EUC-KR, 이중변환 흔적을
     * 네이버 재접속 없이 CPMS 서버 안에서만 정리합니다. PHP 5.6 호환 코드입니다.
     */
    public static function normalizeMailText($value)
    {
        $value = trim((string)$value);
        if ($value === '') return '';

        $value = preg_replace('/\?=\s+=\?/', '?==?', $value);
        $manual = preg_replace_callback('/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/', function ($matches) {
            $charset = isset($matches[1]) ? (string)$matches[1] : '';
            $mode = isset($matches[2]) ? strtoupper((string)$matches[2]) : 'Q';
            $payload = isset($matches[3]) ? (string)$matches[3] : '';
            if ($mode === 'B') {
                $raw = base64_decode($payload, true);
                if ($raw === false) $raw = '';
            } else {
                $raw = quoted_printable_decode(str_replace('_', ' ', $payload));
            }
            return PublicMailStorageService::mailBytesToUtf8($raw, $charset);
        }, $value);

        $candidate = is_string($manual) && $manual !== '' ? $manual : $value;
        if (strpos($candidate, '=?') !== false && function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($candidate, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false && $decoded !== '') $candidate = $decoded;
        }
        if (strpos($candidate, '=?') !== false && function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($candidate);
            if ($decoded !== false && $decoded !== '') $candidate = $decoded;
        }

        $candidate = self::mailBytesToUtf8($candidate, '');
        $candidate = self::repairMailMojibake($candidate);
        $candidate = self::sanitizeText($candidate, 0);
        return trim((string)$candidate);
    }

    /** 저장/조회되는 메일 배열의 제목만 안전하게 로컬 보정합니다. */
    public static function normalizeMessageSubjectsInArray($messages)
    {
        if (!is_array($messages)) return array();
        foreach ($messages as $messageKey => $message) {
            if (!is_array($message)) continue;
            $subject = isset($message['subject']) ? (string)$message['subject'] : '';
            $normalized = self::normalizeMailText($subject);
            if ($normalized === '' && trim($subject) === '') $normalized = '(제목 없음)';
            if ($normalized !== '') $messages[$messageKey]['subject'] = $normalized;
        }
        return $messages;
    }

    /** 로컬 보정 후에도 원본 재확인이 필요한 제목인지 판단합니다. */
    public static function looksBrokenMailText($value)
    {
        $value = trim((string)$value);
        if ($value === '') return false;
        if (!self::isValidMailUtf8($value)) return true;
        if (strpos($value, '=?') !== false || strpos($value, "\xEF\xBF\xBD") !== false) return true;
        if (preg_match('/(?:Ã.|Â.|ì.|ë.|ê.|ã.|ð.|þ.|æ.|å.)/u', $value)) return true;
        $hangul = preg_match_all('/[가-힣]/u', $value, $m);
        $cjk = preg_match_all('/[一-龥]/u', $value, $m);
        if ($hangul >= 3 && $cjk >= 1) return true;
        $repaired = self::repairMailMojibake($value);
        return $repaired !== $value && self::mailTextQualityScore($repaired) >= self::mailTextQualityScore($value) + 6;
    }

    private static function normalizeMailCharset($charset)
    {
        $charset = strtoupper(trim((string)$charset, " \t\r\n\"'"));
        $charset = str_replace('_', '-', $charset);
        $map = array(
            'KS-C-5601-1987'=>'CP949','KS-C-5601-1989'=>'CP949','KSC5601'=>'CP949',
            'KSC-5601'=>'CP949','WINDOWS-949'=>'CP949','X-WINDOWS-949'=>'CP949',
            'MS949'=>'CP949','CP-949'=>'CP949','UHC'=>'CP949','UTF8'=>'UTF-8'
        );
        return isset($map[$charset]) ? $map[$charset] : $charset;
    }

    private static function isValidMailUtf8($value)
    {
        return $value === '' || @preg_match('//u', (string)$value) === 1;
    }

    private static function mailBytesToUtf8($value, $charset)
    {
        $value = (string)$value;
        if ($value === '') return '';
        $charset = self::normalizeMailCharset($charset);
        if (($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') && self::isValidMailUtf8($value)) {
            return self::repairMailMojibake($value);
        }

        $sources = array();
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') $sources[] = $charset;
        foreach (array('CP949','EUC-KR','UTF-8','ISO-8859-1','WINDOWS-1252') as $source) {
            if (!in_array($source, $sources, true)) $sources[] = $source;
        }

        $best = self::isValidMailUtf8($value) ? self::repairMailMojibake($value) : '';
        $bestScore = $best !== '' ? self::mailTextQualityScore($best) : -999999;
        foreach ($sources as $source) {
            $converted = false;
            if (function_exists('iconv')) $converted = @iconv($source, 'UTF-8//IGNORE', $value);
            if (($converted === false || $converted === '') && function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', $source);
            }
            if ($converted === false || $converted === '' || !self::isValidMailUtf8($converted)) continue;
            $converted = self::repairMailMojibake($converted);
            $score = self::mailTextQualityScore($converted);
            if ($score > $bestScore) { $best = $converted; $bestScore = $score; }
        }
        return $best !== '' ? $best : self::sanitizeText($value, 0);
    }

    private static function repairMailMojibake($value)
    {
        $value = (string)$value;
        if ($value === '' || !self::isValidMailUtf8($value)) return $value;
        $candidates = array($value);
        $seen = array($value => true);
        $frontier = array($value);

        for ($depth = 0; $depth < 2; $depth++) {
            $next = array();
            foreach ($frontier as $current) {
                foreach (array('ISO-8859-1','WINDOWS-1252','CP949','EUC-KR') as $target) {
                    if (!function_exists('iconv')) continue;
                    $bytes = @iconv('UTF-8', $target . '//IGNORE', $current);
                    if ($bytes === false || $bytes === '') continue;
                    foreach (array('UTF-8','CP949','EUC-KR') as $source) {
                        $decoded = @iconv($source, 'UTF-8//IGNORE', $bytes);
                        if ($decoded === false || $decoded === '' || !self::isValidMailUtf8($decoded)) continue;
                        $decoded = trim((string)$decoded);
                        if ($decoded === '' || isset($seen[$decoded])) continue;
                        $seen[$decoded] = true;
                        $candidates[] = $decoded;
                        $next[] = $decoded;
                    }
                }
            }
            $frontier = $next;
            if (empty($frontier)) break;
        }

        $best = $value;
        $baseScore = self::mailTextQualityScore($value);
        $bestScore = $baseScore;
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || !self::isValidMailUtf8($candidate)) continue;
            $score = self::mailTextQualityScore($candidate);
            if ($score > $bestScore) { $best = $candidate; $bestScore = $score; }
        }
        return ($best !== $value && $bestScore >= $baseScore + 6) ? $best : $value;
    }

    private static function mailTextQualityScore($value)
    {
        $value = (string)$value;
        if ($value === '') return 0;
        $score = 0;
        $hangul = preg_match_all('/[가-힣]/u', $value, $m);
        $jamo = preg_match_all('/[ㄱ-ㅎㅏ-ㅣ]/u', $value, $m);
        $cjk = preg_match_all('/[一-龥]/u', $value, $m);
        $replacement = substr_count($value, "\xEF\xBF\xBD");
        $control = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value, $m);
        $mojibake = preg_match_all('/(?:Ã.|Â.|ì.|ë.|ê.|ã.|ð.|þ.|æ.|å.)/u', $value, $m);
        $encodedWord = substr_count($value, '=?');
        $score += (int)$hangul * 3;
        $score += (int)$jamo;
        $score -= (int)$cjk * 2;
        $score -= (int)$replacement * 20;
        $score -= (int)$control * 20;
        $score -= (int)$mojibake * 7;
        $score -= (int)$encodedWord * 12;
        if (preg_match('/[가-힣]{2,}/u', $value)) $score += 8;
        if (preg_match('/(메일|세금|계산서|발행|안내|현장|공사|요청|첨부|주식회사|담당자|견적|계약|입금|회의)/u', $value)) $score += 10;
        return $score;
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

    /** 실제 flock 상태를 읽습니다. lock 파일이 존재하는 것과 실행 중인 것은 다릅니다. */
    public static function getOperationLockStatus($name)
    {
        self::ensureStorage();
        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)$name);
        if ($safeName === '') $safeName = 'operation';
        $path = self::path($safeName . '.lock');
        $result = array('locked'=>false,'file_exists'=>is_file($path),'age_seconds'=>0,'content'=>'');
        if (is_file($path)) {
            $mtime = @filemtime($path);
            if ($mtime !== false) $result['age_seconds'] = max(0, time() - (int)$mtime);
            $content = @file_get_contents($path);
            if ($content !== false) $result['content'] = trim((string)$content);
        }
        $handle = @fopen($path, 'c+');
        if (!$handle) return $result;
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            $result['locked'] = false;
            @flock($handle, LOCK_UN);
        } else {
            $result['locked'] = true;
        }
        @fclose($handle);
        return $result;
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

    /**
     * 네이버 원문에 잘못된 바이트가 섞여 있어도 상태/메일 JSON 전체 저장이 중단되지 않게 정리합니다.
     */
    public static function sanitizeUtf8Value($value)
    {
        if (is_string($value)) return self::sanitizeText($value, 0);
        if (is_array($value)) {
            $clean = array();
            foreach ($value as $key => $item) {
                $cleanKey = is_string($key) ? self::sanitizeText($key, 0) : $key;
                $clean[$cleanKey] = self::sanitizeUtf8Value($item);
            }
            return $clean;
        }
        if (is_object($value)) return self::sanitizeUtf8Value(get_object_vars($value));
        return $value;
    }

    public static function sanitizeText($value, $maxLength)
    {
        $value = (string)$value;
        if ($value === '') return '';
        if (!(function_exists('mb_check_encoding') && @mb_check_encoding($value, 'UTF-8'))) {
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
                if ($converted !== false) $value = $converted;
            }
            if (!(function_exists('mb_check_encoding') && @mb_check_encoding($value, 'UTF-8'))) {
                $result = '';
                $length = strlen($value);
                for ($i = 0; $i < $length; $i++) {
                    $b1 = ord($value[$i]);
                    if ($b1 <= 0x7F) {
                        if ($b1 === 9 || $b1 === 10 || $b1 === 13 || $b1 >= 32) $result .= $value[$i];
                        continue;
                    }
                    $seqLength = 0;
                    if ($b1 >= 0xC2 && $b1 <= 0xDF) $seqLength = 2;
                    elseif ($b1 >= 0xE0 && $b1 <= 0xEF) $seqLength = 3;
                    elseif ($b1 >= 0xF0 && $b1 <= 0xF4) $seqLength = 4;
                    if ($seqLength === 0 || ($i + $seqLength) > $length) continue;
                    $valid = true;
                    for ($j = 1; $j < $seqLength; $j++) {
                        $bj = ord($value[$i + $j]);
                        if ($bj < 0x80 || $bj > 0xBF) { $valid = false; break; }
                    }
                    if ($valid && $seqLength === 3) {
                        $b2 = ord($value[$i + 1]);
                        if (($b1 === 0xE0 && $b2 < 0xA0) || ($b1 === 0xED && $b2 > 0x9F)) $valid = false;
                    }
                    if ($valid && $seqLength === 4) {
                        $b2 = ord($value[$i + 1]);
                        if (($b1 === 0xF0 && $b2 < 0x90) || ($b1 === 0xF4 && $b2 > 0x8F)) $valid = false;
                    }
                    if ($valid) {
                        $result .= substr($value, $i, $seqLength);
                        $i += $seqLength - 1;
                    }
                }
                $value = $result;
            }
        }
        if ((int)$maxLength > 0) {
            if (function_exists('mb_substr')) $value = mb_substr($value, 0, (int)$maxLength, 'UTF-8');
            else $value = substr($value, 0, (int)$maxLength);
        }
        return $value;
    }

    public static function writeJsonFile($path, $value)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) throw new \RuntimeException('저장 폴더를 만들 수 없습니다: ' . $dir);
        $value = self::sanitizeUtf8Value($value);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) $json = json_encode($value);
        if ($json === false) {
            $jsonError = function_exists('json_last_error_msg') ? json_last_error_msg() : (string)json_last_error();
            @error_log('[CPMS Public Mail] JSON file encode failed: ' . $path . ' / ' . $jsonError);
            throw new \RuntimeException('JSON 데이터 변환에 실패했습니다.');
        }
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
