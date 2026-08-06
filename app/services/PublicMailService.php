<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailService.php
 *
 * 네이버 전체 메일함 자동수집, 조회, 본문/첨부파일 해석, Gmail 연결을 담당합니다.
 * PHP 5.6 호환 코드입니다.
 */

namespace App\Services;

require_once __DIR__ . '/PublicMailStorageService.php';
require_once __DIR__ . '/PublicMailImapClient.php';
require_once __DIR__ . '/PublicMailClassifierService.php';
require_once __DIR__ . '/PublicMailLargeAttachmentService.php';
require_once __DIR__ . '/PublicMailDriveService.php';
require_once __DIR__ . '/PublicMailIndexService.php';

class PublicMailService
{
    const VERSION = '1.7.5';
    private $storage;
    private $classifier;
    private $largeAttachmentService;
    private $driveService;
    private $indexService;

    public function __construct()
    {
        $this->storage = new PublicMailStorageService();
        $this->classifier = new PublicMailClassifierService();
        $this->largeAttachmentService = new PublicMailLargeAttachmentService();
        $this->driveService = new PublicMailDriveService();
        $this->indexService = new PublicMailIndexService();
        PublicMailStorageService::ensureStorage();
    }

    public function getSettings($includeSecrets)
    {
        return PublicMailStorageService::getSettings($includeSecrets);
    }

    public function saveSettings($input, $updatedBy)
    {
        return PublicMailStorageService::saveSettings($input, $updatedBy);
    }

    public function getCronInfo($baseUrl)
    {
        $baseUrl = rtrim((string)$baseUrl, '/');
        $token = PublicMailStorageService::getCronToken();
        $state = $this->getSyncState();
        return array(
            'token' => $token,
            'url' => $baseUrl . '/cron/naver_mail_sync.php',
            'header_name' => 'X-CPMS-Mail-Key',
            'header_value' => $token,
            'recommended_interval' => '1분',
            'last_cron_at' => isset($state['last_cron_at']) ? $state['last_cron_at'] : '',
            'last_cron_result' => isset($state['last_cron_result']) ? $state['last_cron_result'] : '',
            'browser_auto_sync' => false
        );
    }

    public function regenerateCronToken($updatedBy)
    {
        return PublicMailStorageService::regenerateCronToken($updatedBy);
    }

    public function verifyCronToken($token)
    {
        $expected = PublicMailStorageService::getCronToken();
        $token = trim((string)$token);
        if ($expected === '' || $token === '') return false;
        return function_exists('hash_equals') ? hash_equals($expected, $token) : ($expected === $token);
    }

    public function testConnection($temporarySettings)
    {
        $settings = $this->mergeConnectionSettings($temporarySettings);
        $client = $this->createClient($settings);
        try {
            $client->connect();
            $client->login($settings['username'], $settings['password']);
            $mailboxes = $this->discoverMailboxes($client, $settings);
            $inboxCount = 0;
            foreach ($mailboxes as $mailbox) {
                if (strcasecmp($mailbox['raw_name'], 'INBOX') === 0) {
                    $info = $client->selectMailbox($mailbox['raw_name']);
                    $inboxCount = isset($info['exists']) ? (int)$info['exists'] : 0;
                    break;
                }
            }
            return array('ok'=>true,'message'=>'네이버 공용메일 연결이 정상입니다.','mail_count'=>$inboxCount,'mailbox_count'=>count($mailboxes));
        } finally {
            $client->logout();
        }
    }

    public function startFullImport()
    {
        $settings = $this->requireEnabledSettings();
        $lock = PublicMailStorageService::acquireLock('sync');
        if ($lock === false) throw new \RuntimeException('다른 사용자가 메일을 가져오는 중입니다. 잠시 후 다시 시도하세요.');
        $client = $this->createClient($settings);
        try {
            $client->connect();
            $client->login($settings['username'], $settings['password']);
            $mailboxes = $this->discoverMailboxes($client, $settings);
            if (empty($mailboxes)) throw new \RuntimeException('가져올 수 있는 네이버 메일함을 찾지 못했습니다.');
            $mailboxStates = array();
            $order = array();
            $total = 0;
            foreach ($mailboxes as $mailbox) {
                try {
                    $info = $client->selectMailbox($mailbox['raw_name']);
                    $count = isset($info['exists']) ? (int)$info['exists'] : 0;
                } catch (\Exception $ignored) {
                    $count = 0;
                }
                $id = $this->mailboxStateId($mailbox['raw_name']);
                $order[] = $id;
                $mailboxStates[$id] = array(
                    'raw_name'=>$mailbox['raw_name'], 'display_name'=>$mailbox['display_name'],
                    'total_count'=>$count, 'imported_count'=>0, 'remaining_count'=>$count,
                    'last_uid'=>0, 'completed'=>($count === 0), 'last_error'=>'', 'mailbox_type'=>isset($mailbox['mailbox_type'])?$mailbox['mailbox_type']:$this->detectMailboxType($mailbox['raw_name'],$mailbox['display_name'],isset($mailbox['flags'])?$mailbox['flags']:array())
                );
                $total += $count;
            }
            $state = PublicMailStorageService::saveSyncState(array(
                'mailboxes'=>$mailboxStates,
                'remaining_count'=>$total,
                'mailbox_total'=>$total,
                'completed_initial_sync'=>($total === 0),
                'full_import'=>array(
                    'active'=>($total > 0), 'paused'=>false, 'cancelled'=>false,
                    'started_at'=>date('Y-m-d H:i:s'), 'finished_at'=>$total === 0 ? date('Y-m-d H:i:s') : '',
                    'current_mailbox_index'=>0, 'mailbox_order'=>$order,
                    'processed_count'=>0, 'total_count'=>$total, 'remaining_count'=>$total,
                    'last_message'=>$total > 0 ? '전체 메일 가져오기를 시작했습니다.' : '가져올 메일이 없습니다.'
                )
            ));
            return array('ok'=>true,'message'=>$total > 0 ? '전체 메일 가져오기를 시작했습니다.' : '가져올 메일이 없습니다.','state'=>$state);
        } finally {
            $client->logout();
            PublicMailStorageService::releaseLock($lock);
        }
    }

    public function controlFullImport($command)
    {
        $state = PublicMailStorageService::getSyncState();
        $full = $state['full_import'];
        $command = trim((string)$command);
        if ($command === 'pause') {
            $full['paused'] = true; $full['last_message'] = '전체 메일 가져오기를 일시중지했습니다.';
        } elseif ($command === 'resume') {
            if (!empty($full['cancelled']) || empty($full['mailbox_order'])) return $this->startFullImport();
            $full['active'] = true; $full['paused'] = false; $full['cancelled'] = false; $full['last_message'] = '전체 메일 가져오기를 다시 시작했습니다.';
        } elseif ($command === 'cancel') {
            $full['active'] = false; $full['paused'] = false; $full['cancelled'] = true; $full['finished_at'] = date('Y-m-d H:i:s'); $full['last_message'] = '전체 메일 가져오기를 취소했습니다.';
        } else {
            throw new \InvalidArgumentException('전체메일 작업 명령이 올바르지 않습니다.');
        }
        $state = PublicMailStorageService::saveSyncState(array('full_import'=>$full));
        return array('ok'=>true,'message'=>$full['last_message'],'state'=>$state);
    }

    public function runAutomationTick($limit)
    {
        $state = PublicMailStorageService::getSyncState();
        if (!empty($state['full_import']['active']) && empty($state['full_import']['paused'])) $result = $this->syncFullImportBatch($limit);
        else $result = $this->syncNewBatch($limit);

        /*
         * 깨진 한글 복구는 직원 브라우저가 아니라 외부 cron에서만 조금씩 처리합니다.
         * 사용자가 설정 화면에서 한 번 시작하면 metadata_repair 상태가 active로 유지되고,
         * 매 cron 호출마다 다음 묶음을 이어서 처리한 뒤 대상이 끝나면 자동 종료됩니다.
         */
        $result['repaired_count'] = 0;
        $result['repair_processed_count'] = 0;
        try {
            $repairState = PublicMailStorageService::getSyncState();
            if (!empty($repairState['metadata_repair']['active']) && empty($repairState['metadata_repair']['paused'])) {
                $repairResult = $this->runMetadataRepairBatch(20, 20);
                $result['repaired_count'] = isset($repairResult['repaired_count']) ? (int)$repairResult['repaired_count'] : 0;
                $result['repair_processed_count'] = isset($repairResult['processed_count']) ? (int)$repairResult['processed_count'] : 0;
                $result['repair_state'] = isset($repairResult['state']) ? $repairResult['state'] : array();
            }
        } catch (\Exception $repairError) {
            PublicMailStorageService::saveSyncState(array('metadata_repair'=>array(
                'last_run_at'=>date('Y-m-d H:i:s'),
                'last_error'=>$repairError->getMessage(),
                'last_message'=>'복구 작업 중 오류가 발생했습니다. 다음 자동호출에서 다시 이어갑니다.'
            )));
            $result['repair_error'] = $repairError->getMessage();
        }

        $latestState = PublicMailStorageService::getSyncState();
        if (empty($latestState['metadata_repair']['active'])) {
            try {
                $prepared = $this->cacheUncachedBodiesBatch(1);
                $result['body_cached_count'] = isset($prepared['cached_count']) ? (int)$prepared['cached_count'] : 0;
            } catch (\Exception $ignored) { $result['body_cached_count'] = 0; }
        } else {
            $result['body_cached_count'] = 0;
        }
        if (!empty($result['repaired_count'])) {
            $result['message'] = (isset($result['message']) ? (string)$result['message'] : '자동동기화 완료') . ' / 깨진 한글 ' . (int)$result['repaired_count'] . '건 복구';
        }
        $result['state'] = PublicMailStorageService::getSyncState();
        return $result;
    }

    public function syncBatch($limit, $mode)
    {
        if ($mode === 'initial') {
            $state = PublicMailStorageService::getSyncState();
            if (empty($state['full_import']['active']) && empty($state['full_import']['mailbox_order'])) $this->startFullImport();
            return $this->syncFullImportBatch($limit);
        }
        return $this->syncNewBatch($limit);
    }

    public function syncFullImportBatch($limit)
    {
        $settings = $this->requireEnabledSettings();
        $limit = $this->normalizeBatchLimit($limit, $settings);
        $lock = PublicMailStorageService::acquireLock('sync');
        if ($lock === false) throw new \RuntimeException('다른 사용자가 메일을 가져오는 중입니다. 잠시 후 다시 시도하세요.');
        $client = $this->createClient($settings);
        try {
            $state = PublicMailStorageService::getSyncState();
            $full = $state['full_import'];
            if (empty($full['active'])) return array('ok'=>true,'message'=>'전체메일 가져오기가 실행 중이 아닙니다.','added_count'=>0,'state'=>$state);
            if (!empty($full['paused'])) return array('ok'=>true,'message'=>'전체메일 가져오기가 일시중지되어 있습니다.','added_count'=>0,'state'=>$state);
            $client->connect();
            $client->login($settings['username'], $settings['password']);
            $messages = PublicMailStorageService::getMessages();
            $projects = $this->getProjects();
            $order = isset($full['mailbox_order']) && is_array($full['mailbox_order']) ? $full['mailbox_order'] : array();
            $index = isset($full['current_mailbox_index']) ? (int)$full['current_mailbox_index'] : 0;
            $mailboxes = isset($state['mailboxes']) && is_array($state['mailboxes']) ? $state['mailboxes'] : array();
            $added = 0; $gptUsed = 0; $lastMessage = '';

            while ($index < count($order) && $added < $limit) {
                $id = $order[$index];
                if (!isset($mailboxes[$id]) || !is_array($mailboxes[$id])) { $index++; continue; }
                $box = $mailboxes[$id];
                if (!empty($box['completed'])) { $index++; continue; }
                try {
                    $client->selectMailbox($box['raw_name']);
                    $uids = $client->searchAllUids();
                    rsort($uids, SORT_NUMERIC);
                    $missing = array();
                    foreach ($uids as $uid) {
                        $key = PublicMailStorageService::messageKey($box['raw_name'], $uid);
                        if (!isset($messages[$key])) {
                            $missing[] = (int)$uid;
                            if (count($missing) >= ($limit - $added)) break;
                        }
                    }
                    foreach ($missing as $uid) {
                        $message = $this->fetchAndBuildMessage($client, $box, $uid, $projects, $settings, $gptUsed);
                        $key = $message['message_key'];
                        $messages[$key] = $message;
                        $added++;
                    }
                    if (!empty($missing)) PublicMailStorageService::saveMessages($messages);
                    $imported = 0; $maxUid = 0;
                    foreach ($uids as $uid) {
                        if ((int)$uid > $maxUid) $maxUid = (int)$uid;
                        if (isset($messages[PublicMailStorageService::messageKey($box['raw_name'], $uid)])) $imported++;
                    }
                    $box['total_count'] = count($uids);
                    $box['imported_count'] = $imported;
                    $box['remaining_count'] = max(0, count($uids) - $imported);
                    $box['last_uid'] = $maxUid;
                    $box['completed'] = $box['remaining_count'] === 0;
                    $box['last_error'] = '';
                    $mailboxes[$id] = $box;
                    $lastMessage = $box['display_name'] . ' 메일함을 가져오는 중입니다.';
                    if ($box['completed']) $index++;
                    if ($added >= $limit) break;
                } catch (\Exception $boxError) {
                    $box['last_error'] = $boxError->getMessage();
                    $box['completed'] = true;
                    $mailboxes[$id] = $box;
                    $index++;
                }
            }

            $progress = $this->calculateImportProgress($mailboxes);
            $completed = $index >= count($order) || $progress['remaining_count'] <= 0;
            $full['current_mailbox_index'] = $index;
            $full['processed_count'] = $progress['processed_count'];
            $full['total_count'] = $progress['total_count'];
            $full['remaining_count'] = $progress['remaining_count'];
            $full['active'] = !$completed;
            $full['finished_at'] = $completed ? date('Y-m-d H:i:s') : '';
            $full['last_message'] = $completed ? '전체 메일 가져오기가 완료되었습니다.' : $lastMessage;
            $state = PublicMailStorageService::saveSyncState(array(
                'last_success_at'=>date('Y-m-d H:i:s'),'last_error_at'=>'','last_error'=>'',
                'last_batch_count'=>$added,'last_gpt_count'=>$gptUsed,'last_mode'=>'full_import',
                'mailboxes'=>$mailboxes,'mailbox_total'=>$progress['total_count'],
                'remaining_count'=>$progress['remaining_count'],'completed_initial_sync'=>$completed,
                'full_import'=>$full
            ));
            return array('ok'=>true,'message'=>$full['last_message'],'added_count'=>$added,'remaining_count'=>$progress['remaining_count'],'state'=>$state);
        } catch (\Exception $e) {
            PublicMailStorageService::saveSyncState(array('last_error_at'=>date('Y-m-d H:i:s'),'last_error'=>$e->getMessage(),'last_mode'=>'full_import'));
            throw $e;
        } finally {
            $client->logout();
            PublicMailStorageService::releaseLock($lock);
        }
    }

    public function syncNewBatch($limit)
    {
        $settings = $this->requireEnabledSettings();
        $limit = $this->normalizeBatchLimit($limit, $settings);
        $lock = PublicMailStorageService::acquireLock('sync');
        if ($lock === false) throw new \RuntimeException('다른 사용자가 메일을 가져오는 중입니다. 잠시 후 다시 시도하세요.');
        $client = $this->createClient($settings);
        try {
            $client->connect(); $client->login($settings['username'], $settings['password']);
            $state = PublicMailStorageService::getSyncState();
            $mailboxes = isset($state['mailboxes']) && is_array($state['mailboxes']) ? $state['mailboxes'] : array();
            if (empty($mailboxes)) {
                foreach ($this->discoverMailboxes($client, $settings) as $box) {
                    $id = $this->mailboxStateId($box['raw_name']);
                    $mailboxes[$id] = array('raw_name'=>$box['raw_name'],'display_name'=>$box['display_name'],'total_count'=>0,'imported_count'=>0,'remaining_count'=>0,'last_uid'=>0,'completed'=>false,'last_error'=>'','mailbox_type'=>isset($box['mailbox_type'])?$box['mailbox_type']:$this->detectMailboxType($box['raw_name'],$box['display_name'],isset($box['flags'])?$box['flags']:array()));
                }
            }
            $messages = PublicMailStorageService::getMessages();
            $projects = $this->getProjects();
            $added = 0; $gptUsed = 0; $searched = 0;
            foreach ($mailboxes as $id => $box) {
                if ($added >= $limit) break;
                try {
                    $info = $client->selectMailbox($box['raw_name']);
                    $lastUid = isset($box['last_uid']) ? (int)$box['last_uid'] : 0;
                    if ($lastUid <= 0) $lastUid = $this->maximumKnownUid($messages, $box['raw_name']);
                    $uids = $client->searchUidsAfter($lastUid);
                    if ($lastUid <= 0) rsort($uids, SORT_NUMERIC); else sort($uids, SORT_NUMERIC);
                    $searched += count($uids);
                    $newLastUid = $lastUid;
                    foreach ($uids as $uid) {
                        if ($added >= $limit) break;
                        $key = PublicMailStorageService::messageKey($box['raw_name'], $uid);
                        if (isset($messages[$key])) { if ((int)$uid > $newLastUid) $newLastUid = (int)$uid; continue; }
                        $message = $this->fetchAndBuildMessage($client, $box, $uid, $projects, $settings, $gptUsed);
                        $messages[$key] = $message; $added++;
                        if ((int)$uid > $newLastUid) $newLastUid = (int)$uid;
                    }
                    $box['last_uid'] = $newLastUid;
                    $box['total_count'] = isset($info['exists']) ? (int)$info['exists'] : (isset($box['total_count']) ? (int)$box['total_count'] : 0);
                    $box['imported_count'] = $this->countKnownMailboxMessages($messages, $box['raw_name']);
                    $box['remaining_count'] = max(0, $box['total_count'] - $box['imported_count']);
                    $box['last_error'] = '';
                    $mailboxes[$id] = $box;
                } catch (\Exception $boxError) {
                    $box['last_error'] = $boxError->getMessage(); $mailboxes[$id] = $box;
                }
            }
            if ($added > 0) PublicMailStorageService::saveMessages($messages);
            $progress = $this->calculateImportProgress($mailboxes);
            $state = PublicMailStorageService::saveSyncState(array(
                'last_success_at'=>date('Y-m-d H:i:s'),'last_error_at'=>'','last_error'=>'',
                'last_batch_count'=>$added,'last_gpt_count'=>$gptUsed,'last_search_count'=>$searched,
                'last_mode'=>'new','mailboxes'=>$mailboxes,'mailbox_total'=>$progress['total_count'],
                'remaining_count'=>isset($state['full_import']['remaining_count']) ? (int)$state['full_import']['remaining_count'] : 0
            ));
            return array('ok'=>true,'message'=>$added > 0 ? $added . '개의 새 메일을 가져왔습니다.' : '새로 가져올 메일이 없습니다.','added_count'=>$added,'search_count'=>$searched,'state'=>$state);
        } catch (\Exception $e) {
            PublicMailStorageService::saveSyncState(array('last_error_at'=>date('Y-m-d H:i:s'),'last_error'=>$e->getMessage(),'last_mode'=>'new'));
            throw $e;
        } finally {
            $client->logout(); PublicMailStorageService::releaseLock($lock);
        }
    }

    public function getMessageList($filters, $page, $perPage)
    {
        return $this->indexService->getMessageList($filters, $page, $perPage);
    }

    public function getDashboardCounts()
    {
        return $this->indexService->getDashboardCounts();
    }

    /**
     * 저장된 목록정보와 본문 캐시만 읽습니다.
     * 이 함수는 네이버 IMAP에 접속하지 않으므로 메일 선택 화면이 즉시 열립니다.
     */
    public function getMessageShell($messageKey)
    {
        $messageKey = trim((string)$messageKey);
        $message = $this->indexService->getMessage($messageKey);
        if (!is_array($message)) {
            $messages = PublicMailStorageService::getMessages();
            if (!isset($messages[$messageKey]) || !is_array($messages[$messageKey])) {
                throw new \RuntimeException('저장된 메일 정보를 찾을 수 없습니다.');
            }
            $message = $messages[$messageKey];
        }
        $parsedKey = PublicMailStorageService::parseMessageKey($messageKey);
        $detail = $this->normalizeStoredMessage($message);
        $detail['message_key'] = $messageKey;
        $detail['uid'] = isset($message['uid']) ? (int)$message['uid'] : (int)$parsedKey['uid'];
        $detail['mailbox'] = isset($message['mailbox']) ? (string)$message['mailbox'] : (string)$parsedKey['mailbox'];
        $detail['mailbox_name'] = isset($message['mailbox_name']) ? (string)$message['mailbox_name'] : ($detail['mailbox'] === 'INBOX' ? '받은메일함' : $detail['mailbox']);
        $detail['mailbox_type'] = isset($message['mailbox_type']) ? (string)$message['mailbox_type'] : $this->detectMailboxType($detail['mailbox'], $detail['mailbox_name'], array());
        $detail['workflow'] = isset($message['workflow']) && is_array($message['workflow']) ? $message['workflow'] : PublicMailStorageService::getWorkflowForKey($messageKey);
        $detail['drive_records'] = PublicMailStorageService::getDriveRecordsForMessage($messageKey);
        $cache = PublicMailStorageService::getBodyCache($messageKey);
        $detail['body_cache_ready'] = is_array($cache);
        $detail['body_html'] = is_array($cache) && isset($cache['body_html']) ? (string)$cache['body_html'] : '';
        $detail['body_text'] = is_array($cache) && isset($cache['body_text']) ? (string)$cache['body_text'] : '';
        $detail['attachments'] = is_array($cache) && isset($cache['attachments']) && is_array($cache['attachments']) ? $cache['attachments'] : array();
        $detail['inline_images'] = is_array($cache) && isset($cache['inline_images']) && is_array($cache['inline_images']) ? $cache['inline_images'] : array();
        $detail['external_image_count'] = is_array($cache) && isset($cache['external_image_count']) ? (int)$cache['external_image_count'] : 0;
        $detail['body_cache_updated_at'] = is_array($cache) && isset($cache['cached_at']) ? (string)$cache['cached_at'] : '';
        return $detail;
    }

    /**
     * 본문 캐시가 있으면 즉시 반환하고, 없을 때만 네이버에서 본문 구조를 읽어 캐시합니다.
     * 첨부파일 원본과 EML 원문은 서버 디스크에 저장하지 않습니다.
     */
    public function getMessageDetail($messageKey)
    {
        $messageKey = trim((string)$messageKey);
        $cache = PublicMailStorageService::getBodyCache($messageKey);
        if (!is_array($cache)) $this->buildBodyCache($messageKey, false);
        return $this->getMessageShell($messageKey);
    }

    public function rebuildBodyCache($messageKey)
    {
        PublicMailStorageService::deleteBodyCache($messageKey);
        $this->buildBodyCache($messageKey, true);
        return $this->getMessageShell($messageKey);
    }

    /**
     * 외부 예약호출이 한 번 실행될 때 최근 메일부터 소량의 본문 캐시를 준비합니다.
     * 전체메일 수집을 방해하지 않도록 최대 2건으로 제한합니다.
     */
    public function cacheUncachedBodiesBatch($limit)
    {
        $limit = max(1, min(2, (int)$limit));
        $keys = PublicMailStorageService::getUncachedMessageKeys($limit);
        $done = 0; $errors = array();
        foreach ($keys as $key) {
            try { $this->buildBodyCache($key, false); $done++; }
            catch (\Exception $e) { $errors[] = $e->getMessage(); }
        }
        return array('cached_count'=>$done, 'errors'=>$errors);
    }

    public function getInlineImageDescriptor($messageKey, $partId)
    {
        $cache = PublicMailStorageService::getBodyCache($messageKey);
        if (!is_array($cache)) $cache = $this->buildBodyCache($messageKey, false);
        $images = isset($cache['inline_images']) && is_array($cache['inline_images']) ? $cache['inline_images'] : array();
        foreach ($images as $image) {
            if (!is_array($image)) continue;
            if (isset($image['part_id']) && (string)$image['part_id'] === (string)$partId) return $image;
        }
        throw new \RuntimeException('메일 본문 이미지를 찾을 수 없습니다.');
    }

    public function streamInlineImage($messageKey, $partId, $consumer)
    {
        $descriptor = $this->getInlineImageDescriptor($messageKey, $partId);
        $mime = isset($descriptor['mime_type']) ? strtolower((string)$descriptor['mime_type']) : '';
        if (strpos($mime, 'image/') !== 0) throw new \RuntimeException('이미지 형식이 올바르지 않습니다.');
        return $this->streamRegularMimePart($messageKey, $partId, $descriptor, $consumer);
    }

    /**
     * 한 메일의 CID 이미지를 IMAP 연결 한 번으로 묶어서 읽습니다.
     * 이미지 원본은 서버 디스크에 저장하지 않고 응답 메모리에서만 data URI로 전달합니다.
     */
    public function getInlineImageBundle($messageKey, $requestedPartIds)
    {
        $messageKey = trim((string)$messageKey);
        if (!is_array($requestedPartIds)) $requestedPartIds = array();
        $wanted = array();
        foreach ($requestedPartIds as $partId) {
            $partId = trim((string)$partId);
            if (preg_match('/^[0-9]+(?:\.[0-9]+)*$/', $partId) && !isset($wanted[$partId])) $wanted[$partId] = true;
            if (count($wanted) >= 25) break;
        }
        if ($messageKey === '' || empty($wanted)) return array('items'=>array(),'failed'=>array());

        $cache = PublicMailStorageService::getBodyCache($messageKey);
        if (!is_array($cache)) $cache = $this->buildBodyCache($messageKey, false);
        $descriptors = array();
        $images = isset($cache['inline_images']) && is_array($cache['inline_images']) ? $cache['inline_images'] : array();
        foreach ($images as $image) {
            if (!is_array($image) || empty($image['part_id'])) continue;
            $partId = (string)$image['part_id'];
            if (isset($wanted[$partId])) $descriptors[$partId] = $image;
        }
        if (empty($descriptors)) return array('items'=>array(),'failed'=>array_keys($wanted));

        $messages = PublicMailStorageService::getMessages();
        if (!isset($messages[$messageKey]) || !is_array($messages[$messageKey])) throw new \RuntimeException('메일 정보를 찾을 수 없습니다.');
        $message = $messages[$messageKey];
        $parsed = PublicMailStorageService::parseMessageKey($messageKey);
        $mailbox = isset($message['mailbox']) ? (string)$message['mailbox'] : (string)$parsed['mailbox'];
        $uid = isset($message['uid']) ? (int)$message['uid'] : (int)$parsed['uid'];
        $settings = $this->requireEnabledSettings();
        $client = $this->createClient($settings, 12);
        $items = array(); $failed = array(); $totalBytes = 0; $maximumTotal = 12582912;
        try {
            $client->connect(); $client->login($settings['username'], $settings['password']); $client->selectMailbox($mailbox);
            foreach ($descriptors as $partId => $descriptor) {
                try {
                    $declared = isset($descriptor['size']) ? (int)$descriptor['size'] : 0;
                    $maximum = $declared > 0 ? max(1048576, min(8388608, $declared * 2 + 65536)) : 4194304;
                    $encoded = $client->fetchMimePart($uid, $partId, $maximum);
                    $encoding = isset($descriptor['transfer_encoding']) ? strtolower((string)$descriptor['transfer_encoding']) : '';
                    $decoded = $this->decodeMimeTransferContent($encoded, $encoding);
                    if ($decoded === '') throw new \RuntimeException('이미지 내용이 비어 있습니다.');
                    $totalBytes += strlen($decoded);
                    if ($totalBytes > $maximumTotal) throw new \RuntimeException('인라인 이미지 묶음 크기가 안전한도를 초과했습니다.');
                    $mime = isset($descriptor['mime_type']) ? strtolower((string)$descriptor['mime_type']) : 'image/octet-stream';
                    if (strpos($mime, 'image/') !== 0) throw new \RuntimeException('이미지 형식이 아닙니다.');
                    $items[$partId] = 'data:' . $mime . ';base64,' . base64_encode($decoded);
                } catch (\Exception $imageError) {
                    $failed[] = $partId;
                }
            }
        } finally { $client->logout(); }
        foreach ($wanted as $partId => $unused) if (!isset($items[$partId]) && !in_array($partId, $failed, true)) $failed[] = $partId;
        return array('items'=>$items,'failed'=>$failed);
    }

    private function decodeMimeTransferContent($content, $encoding)
    {
        $content = (string)$content;
        $encoding = strtolower(trim((string)$encoding));
        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $content), true);
            return $decoded === false ? '' : $decoded;
        }
        if ($encoding === 'quoted-printable') return quoted_printable_decode($content);
        return $content;
    }

    /**
     * 구버전 호환용 소용량 첨부 반환 함수입니다.
     * 대용량 파일은 메모리에 전체 적재하지 않도록 streamAttachment()를 사용해야 합니다.
     */
    public function getAttachment($messageKey, $partId)
    {
        $buffer='';
        $maximumBuffer=52428800; // 50MB 안전한도
        $descriptor=$this->getAttachmentDescriptor($messageKey,$partId);
        if (!empty($descriptor['is_large']) || (!empty($descriptor['size']) && (int)$descriptor['size']>$maximumBuffer)) {
            throw new \RuntimeException('대용량 첨부파일은 스트리밍 다운로드 방식을 사용해야 합니다.');
        }
        $this->streamAttachment($messageKey,$partId,function($chunk) use (&$buffer,$maximumBuffer){
            if (strlen($buffer)+strlen($chunk)>$maximumBuffer) throw new \RuntimeException('첨부파일이 메모리 안전한도를 초과했습니다.');
            $buffer.=$chunk;
        });
        $descriptor['content']=$buffer;
        $descriptor['size']=strlen($buffer);
        return $descriptor;
    }

    public function getAttachmentDescriptor($messageKey,$partId,$inspectRemote=true)
    {
        $messageKey=(string)$messageKey; $partId=trim((string)$partId);
        $messages=PublicMailStorageService::getMessages();
        if (!isset($messages[$messageKey])) throw new \RuntimeException('메일 정보를 찾을 수 없습니다.');
        $message=$messages[$messageKey];
        if (strpos($partId,'large_')===0) {
            $id=substr($partId,6);
            if (empty($message['large_attachments'][$id])) {
                $this->getMessageDetail($messageKey);
                $messages=PublicMailStorageService::getMessages(); $message=$messages[$messageKey];
            }
            if (empty($message['large_attachments'][$id])) throw new \RuntimeException('대용량 첨부파일 정보를 찾을 수 없습니다.');
            $attachment=$message['large_attachments'][$id];
            if (empty($attachment['source_url']) || !$this->largeAttachmentService->isAllowedNaverUrl($attachment['source_url'])) {
                throw new \RuntimeException('대용량 첨부주소가 올바르지 않습니다.');
            }
            if ($inspectRemote) {
                $info=$this->largeAttachmentService->inspectRemote($attachment['source_url']);
                if (!empty($info['filename'])) $attachment['filename']=$info['filename'];
                if (!empty($info['mime_type'])) $attachment['mime_type']=$info['mime_type'];
                if (!empty($info['size'])) $attachment['size']=$info['size'];
                if (!empty($info['url'])) $attachment['source_url']=$info['url'];
            }
            return $attachment;
        }
        $bodyCache = PublicMailStorageService::getBodyCache($messageKey);
        if (is_array($bodyCache) && isset($bodyCache['attachments']) && is_array($bodyCache['attachments'])) {
            foreach ($bodyCache['attachments'] as $cachedAttachment) {
                if (!is_array($cachedAttachment) || !isset($cachedAttachment['part_id'])) continue;
                if ((string)$cachedAttachment['part_id'] === (string)$partId && empty($cachedAttachment['is_large'])) {
                    $cachedAttachment['filename'] = $this->safeFilename(isset($cachedAttachment['filename']) ? $cachedAttachment['filename'] : 'attachment.bin');
                    $cachedAttachment['mime_type'] = !empty($cachedAttachment['mime_type']) ? strtolower((string)$cachedAttachment['mime_type']) : 'application/octet-stream';
                    $cachedAttachment['transfer_encoding'] = isset($cachedAttachment['transfer_encoding']) ? strtolower((string)$cachedAttachment['transfer_encoding']) : '';
                    return $cachedAttachment;
                }
            }
        }
        $parsedKey=PublicMailStorageService::parseMessageKey($messageKey);
        $mailbox=isset($message['mailbox'])?(string)$message['mailbox']:$parsedKey['mailbox'];
        $uid=isset($message['uid'])?(int)$message['uid']:(int)$parsedKey['uid'];
        $settings=PublicMailStorageService::getSettings(true); $client=$this->createClient($settings);
        try {
            $client->connect(); $client->login($settings['username'],$settings['password']); $client->selectMailbox($mailbox);
            $mimeHeader=$client->fetchMimeHeader($uid,$partId);
        } finally { $client->logout(); }
        $headers=$this->parseHeaders($mimeHeader);
        $typeInfo=$this->parseHeaderWithParameters(isset($headers['content-type'])?$headers['content-type']:'application/octet-stream');
        $dispInfo=$this->parseHeaderWithParameters(isset($headers['content-disposition'])?$headers['content-disposition']:'');
        $filename=isset($dispInfo['params']['filename'])?$dispInfo['params']['filename']:(isset($typeInfo['params']['name'])?$typeInfo['params']['name']:'attachment.bin');
        return array('part_id'=>$partId,'filename'=>$this->safeFilename($this->decodeHeader(trim((string)$filename," \t\r\n\"'"))),'mime_type'=>isset($typeInfo['value'])&&$typeInfo['value']!==''?strtolower($typeInfo['value']):'application/octet-stream','size'=>0,'is_large'=>false,'transfer_encoding'=>isset($headers['content-transfer-encoding'])?strtolower(trim((string)$headers['content-transfer-encoding'])):'');
    }

    public function streamAttachment($messageKey,$partId,$consumer)
    {
        if (!is_callable($consumer)) throw new \InvalidArgumentException('첨부파일 수신 함수가 올바르지 않습니다.');
        $descriptor=$this->getAttachmentDescriptor($messageKey,$partId);
        if (!empty($descriptor['is_large'])) return $this->largeAttachmentService->streamRemote($descriptor['source_url'],$consumer,4194304);
        return $this->streamRegularMimePart($messageKey,$partId,$descriptor,$consumer);
    }

    private function streamRegularMimePart($messageKey,$partId,$descriptor,$consumer)
    {
        if (!is_callable($consumer)) throw new \InvalidArgumentException('파일 수신 함수가 올바르지 않습니다.');
        $messages=PublicMailStorageService::getMessages();
        if (!isset($messages[(string)$messageKey])) throw new \RuntimeException('메일 정보를 찾을 수 없습니다.');
        $message=$messages[(string)$messageKey];
        $parsedKey=PublicMailStorageService::parseMessageKey($messageKey);
        $mailbox=isset($message['mailbox'])?(string)$message['mailbox']:$parsedKey['mailbox'];
        $uid=isset($message['uid'])?(int)$message['uid']:(int)$parsedKey['uid'];
        $settings=PublicMailStorageService::getSettings(true); $client=$this->createClient($settings);
        $encoding=isset($descriptor['transfer_encoding'])?strtolower((string)$descriptor['transfer_encoding']):'';
        $offset=0; $requestSize=1048576; $carry=''; $decodedOffset=0;
        try {
            $client->connect(); $client->login($settings['username'],$settings['password']); $client->selectMailbox($mailbox);
            while (true) {
                $encoded=$client->fetchMimePartChunk($uid,$partId,$offset,$requestSize);
                if ($encoded==='') break;
                $offset+=strlen($encoded);
                if ($encoding==='base64') {
                    $clean=$carry.preg_replace('/\s+/','',$encoded);
                    $usable=strlen($clean)-strlen($clean)%4;
                    $decodeText=substr($clean,0,$usable); $carry=substr($clean,$usable);
                    $decoded=$decodeText!==''?base64_decode($decodeText,true):'';
                    if ($decoded===false) throw new \RuntimeException('파일 Base64 해제에 실패했습니다.');
                } elseif ($encoding==='quoted-printable') {
                    $clean=$carry.$encoded; $carry='';
                    $tail='';
                    if (substr($clean,-1)==='=') { $tail='='; $clean=substr($clean,0,-1); }
                    elseif (substr($clean,-2)==="=\r") { $tail="=\r"; $clean=substr($clean,0,-2); }
                    $carry=$tail; $decoded=quoted_printable_decode($clean);
                } else $decoded=$encoded;
                if ($decoded!=='') { call_user_func($consumer,$decoded,$decodedOffset,0); $decodedOffset+=strlen($decoded); }
                if (strlen($encoded)<$requestSize) break;
            }
            if ($carry!=='') {
                $decoded=$encoding==='base64'?base64_decode($carry,true):quoted_printable_decode($carry);
                if ($decoded!==false&&$decoded!=='') { call_user_func($consumer,$decoded,$decodedOffset,0); $decodedOffset+=strlen($decoded); }
            }
        } finally { $client->logout(); }
        if ($decodedOffset<=0) throw new \RuntimeException('파일 내용이 비어 있습니다.');
        return array('bytes_streamed'=>$decodedOffset);
    }

    public function saveAttachmentToDrive($messageKey,$partId,$projectId,$actor)
    {
        $messages=PublicMailStorageService::getMessages();
        if (!isset($messages[(string)$messageKey])) throw new \RuntimeException('메일 정보를 찾을 수 없습니다.');
        $message=$messages[(string)$messageKey]; $message['message_key']=(string)$messageKey;
        $attachment=$this->getAttachmentDescriptor($messageKey,$partId,false);
        $self=$this;
        return $this->driveService->saveAttachment($message,$attachment,$projectId,function($sink) use ($self,$messageKey,$partId){
            $self->streamAttachment($messageKey,$partId,function($chunk) use ($sink){ call_user_func($sink,$chunk); });
        },$actor);
    }

    public function getDriveRecord($messageKey,$partId)
    {
        return PublicMailStorageService::findDriveRecord($messageKey,$partId);
    }

    public function updateWorkflow($messageKey, $changes, $updatedBy)
    {
        return PublicMailStorageService::updateWorkflow($messageKey,$changes,$updatedBy);
    }

    public function reclassify($messageKey)
    {
        $messageKey=(string)$messageKey; $messages=PublicMailStorageService::getMessages();
        if (!isset($messages[$messageKey])) throw new \RuntimeException('분류할 메일을 찾을 수 없습니다.');
        $message=$messages[$messageKey]; $classification=$this->classifier->classify($message,$this->getProjects());
        $messages[$messageKey]['classification']=$classification; PublicMailStorageService::saveMessages($messages); return $classification;
    }
    public function getEmployees()
    {
        static $cache = null;
        if (is_array($cache)) return $cache;
        $pdo = $this->getPdoSafely();
        if (!$pdo) {
            $cache = array();
            return $cache;
        }

        try {
            $statement = $pdo->query('SELECT * FROM employees ORDER BY name ASC');
            $rows = $statement ? $statement->fetchAll(\PDO::FETCH_ASSOC) : array();
            $result = array();

            foreach ($rows as $row) {
                $id = $this->firstValue($row, array('id', 'employee_id', 'emp_id'));
                $name = $this->firstValue($row, array('name', 'employee_name', 'user_name'));
                $email = $this->firstValue($row, array('email', 'google_email', 'work_email'));
                $department = $this->firstValue($row, array('department', 'dept', 'team'));

                if ($name === '') {
                    continue;
                }

                $result[] = array(
                    'id' => $id,
                    'name' => $name,
                    'email' => $email,
                    'department' => $department
                );
            }

            $cache = $result;
            return $cache;
        } catch (\Exception $e) {
            $cache = array();
            return $cache;
        }
    }

    public function getProjects()
    {
        static $cache = null;
        if (is_array($cache)) return $cache;
        $pdo = $this->getPdoSafely();
        if (!$pdo) {
            $cache = array();
            return $cache;
        }

        $tables = array('cpms_projects', 'projects');
        foreach ($tables as $table) {
            try {
                $statement = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id DESC');
                $rows = $statement ? $statement->fetchAll(\PDO::FETCH_ASSOC) : array();
                $result = array();

                foreach ($rows as $row) {
                    $id = $this->firstValue($row, array('id', 'project_id'));
                    $name = $this->firstValue($row, array('name', 'project_name', 'title', 'site_name'));
                    $code = $this->firstValue($row, array('code', 'project_code', 'site_code'));

                    if ($name === '') {
                        continue;
                    }

                    $result[] = array(
                        'id' => $id,
                        'name' => $name,
                        'code' => $code
                    );
                }

                if (!empty($result)) {
                    $cache = $result;
                    return $cache;
                }
            } catch (\Exception $e) {
                // 다음 후보 테이블을 확인합니다.
            }
        }

        $cache = array();
        return $cache;
    }

    public function buildGmailComposeUrl($message, $currentGoogleEmail, $mode)
    {
        $mode = $mode === 'new' ? 'new' : 'reply';
        $to = '';
        $subject = '';
        $body = '';

        if ($mode === 'reply' && is_array($message)) {
            $to = isset($message['from_email']) ? (string)$message['from_email'] : '';
            $subject = isset($message['subject']) ? (string)$message['subject'] : '';
            if (stripos($subject, 'RE:') !== 0) {
                $subject = 'RE: ' . $subject;
            }

            $body = "안녕하세요.\n\n아래 메일과 관련하여 회신드립니다.\n\n[답변 내용을 입력해 주세요.]\n\n"
                . "----- 기존 메일 정보 -----\n"
                . '보낸 사람: ' . (isset($message['from_text']) ? $message['from_text'] : '') . "\n"
                . '수신일: ' . (isset($message['date_text']) ? $message['date_text'] : '') . "\n"
                . '제목: ' . (isset($message['subject']) ? $message['subject'] : '') . "\n";
        }

        $query = array(
            'view' => 'cm',
            'fs' => '1',
            'to' => $to,
            'su' => $subject,
            'body' => $body
        );

        if (trim((string)$currentGoogleEmail) !== '') {
            $query['authuser'] = trim((string)$currentGoogleEmail);
        }

        return 'https://mail.google.com/mail/?' . http_build_query($query, '', '&');
    }


    public function getIndexStatus()
    {
        return $this->indexService->getStatus();
    }

    public function rebuildIndex()
    {
        return PublicMailIndexService::rebuild();
    }

    public function getSyncState()
    {
        return PublicMailStorageService::getSyncState();
    }

    public function getBodyCacheStats()
    {
        return PublicMailStorageService::getBodyCacheStats();
    }

    public function resetMailData()
    {
        PublicMailStorageService::resetMailData();
    }

    public function parseRawMessage($raw, $includeAttachmentContent = false)
    {
        $includeAttachmentContent = !empty($includeAttachmentContent);
        $root = $this->parseMimeEntity((string)$raw, '1', $includeAttachmentContent);
        $textBodies = array(); $htmlBodies = array(); $attachments = array();
        $this->collectMimeParts($root, $textBodies, $htmlBodies, $attachments);
        $bodyText = trim(implode("\n\n", $textBodies));
        $bodyHtml = trim(implode("<hr>", $htmlBodies));
        if ($bodyHtml === '' && $bodyText !== '') $bodyHtml = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
        if ($bodyText === '' && $bodyHtml !== '') $bodyText = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8'));
        $rootHeaders = isset($root['headers']) && is_array($root['headers']) ? $root['headers'] : array();
        $fromText = $this->decodeHeader(isset($rootHeaders['from']) ? $rootHeaders['from'] : '');
        return array(
            'body_text'=>$bodyText,'body_html_raw'=>$bodyHtml,'body_html'=>$this->sanitizeHtml($bodyHtml),'attachments'=>$attachments,'headers'=>$rootHeaders,
            'subject'=>$this->decodeHeader(isset($rootHeaders['subject'])?$rootHeaders['subject']:''),
            'from_text'=>$fromText,'from_email'=>$this->extractEmail($fromText),
            'to_text'=>$this->decodeHeader(isset($rootHeaders['to'])?$rootHeaders['to']:''),
            'cc_text'=>$this->decodeHeader(isset($rootHeaders['cc'])?$rootHeaders['cc']:'')
        );
    }

    /**
     * MIME BODYSTRUCTURE를 이용해 본문과 첨부 메타정보만 가져옵니다.
     * 첨부파일 원본은 다운로드하지 않으므로 큰 메일도 상세화면을 빠르게 준비할 수 있습니다.
     */
    private function buildBodyCache($messageKey, $force)
    {
        $messageKey = trim((string)$messageKey);
        if (!$force) {
            $cached = PublicMailStorageService::getBodyCache($messageKey);
            if (is_array($cached)) return $cached;
        }
        $messages = PublicMailStorageService::getMessages();
        if (!isset($messages[$messageKey]) || !is_array($messages[$messageKey])) throw new \RuntimeException('저장된 메일 정보를 찾을 수 없습니다.');
        $message = $messages[$messageKey];
        $parsedKey = PublicMailStorageService::parseMessageKey($messageKey);
        $mailbox = isset($message['mailbox']) ? (string)$message['mailbox'] : (string)$parsedKey['mailbox'];
        $uid = isset($message['uid']) ? (int)$message['uid'] : (int)$parsedKey['uid'];
        if ($uid <= 0) throw new \RuntimeException('메일 UID가 올바르지 않습니다.');

        $settings = $this->requireEnabledSettings();
        $client = $this->createClient($settings);
        $bodyHtmlRaw = ''; $bodyText = ''; $attachments = array(); $inlineImages = array();
        $headerFields = array();
        try {
            $client->connect();
            $client->login($settings['username'], $settings['password']);
            $client->selectMailbox($mailbox);
            $headerData = $client->fetchHeader($uid);
            $fresh = $this->buildMessageFromHeader($headerData, $mailbox, isset($message['mailbox_name']) ? $message['mailbox_name'] : $mailbox);
            foreach (array('subject','from_text','from_email','to_text','cc_text','date_text','timestamp','message_id') as $field) {
                if (isset($fresh[$field])) $headerFields[$field] = $fresh[$field];
            }

            try {
                $structureText = $client->fetchBodyStructure($uid);
                $structure = $this->parseBodyStructure($structureText);
                $parts = array();
                $this->flattenBodyStructure($structure, '', $parts);
                $htmlPart = null; $textPart = null;
                foreach ($parts as $part) {
                    if (!is_array($part)) continue;
                    $mime = isset($part['mime_type']) ? strtolower((string)$part['mime_type']) : '';
                    if (!empty($part['is_inline_image'])) {
                        $inlineImages[] = array(
                            'part_id'=>(string)$part['part_id'],
                            'content_id'=>isset($part['content_id'])?(string)$part['content_id']:'',
                            'filename'=>isset($part['filename'])?(string)$part['filename']:'',
                            'mime_type'=>$mime !== '' ? $mime : 'image/octet-stream',
                            'size'=>isset($part['size'])?(int)$part['size']:0,
                            'transfer_encoding'=>isset($part['transfer_encoding'])?(string)$part['transfer_encoding']:''
                        );
                        continue;
                    }
                    if (!empty($part['is_attachment'])) {
                        $attachments[] = array(
                            'part_id'=>(string)$part['part_id'],
                            'filename'=>$this->safeFilename(isset($part['filename'])&&$part['filename']!==''?$part['filename']:('attachment_'.str_replace('.','_',(string)$part['part_id']))),
                            'mime_type'=>$mime !== '' ? $mime : 'application/octet-stream',
                            'size'=>isset($part['size'])?(int)$part['size']:0,
                            'is_large'=>false,
                            'transfer_encoding'=>isset($part['transfer_encoding'])?(string)$part['transfer_encoding']:''
                        );
                        continue;
                    }
                    if ($mime === 'text/html' && $htmlPart === null) $htmlPart = $part;
                    if ($mime === 'text/plain' && $textPart === null) $textPart = $part;
                }
                if (is_array($htmlPart)) $bodyHtmlRaw = $this->fetchTextBodyPart($client, $uid, $htmlPart);
                if (is_array($textPart)) $bodyText = $this->fetchTextBodyPart($client, $uid, $textPart);
            } catch (\Exception $structureError) {
                // 일부 오래된 메일은 BODYSTRUCTURE가 비정상입니다. 본문 앞부분만 읽어 안전하게 대체합니다.
                $previewRaw = $client->fetchRawPreview($uid, 262144);
                if ($previewRaw !== '') {
                    $fallback = $this->parseRawMessage($previewRaw, false);
                    $bodyHtmlRaw = isset($fallback['body_html_raw']) ? (string)$fallback['body_html_raw'] : '';
                    $bodyText = isset($fallback['body_text']) ? (string)$fallback['body_text'] : '';
                    $attachments = isset($fallback['attachments']) && is_array($fallback['attachments']) ? $fallback['attachments'] : array();
                }
            }
        } finally {
            $client->logout();
        }

        $bodyText = $this->repairMojibake($bodyText);
        if ($bodyText === '' && $bodyHtmlRaw !== '') $bodyText = $this->repairMojibake($this->makePreviewText(strip_tags($bodyHtmlRaw)));
        if ($bodyHtmlRaw === '' && $bodyText !== '') $bodyHtmlRaw = '<div class="pm-plain-mail">' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</div>';

        $inlineMap = array();
        foreach ($inlineImages as $image) {
            $cid = isset($image['content_id']) ? $this->normalizeContentId($image['content_id']) : '';
            if ($cid !== '') $inlineMap[$cid] = $image;
        }
        $externalCount = 0;
        $bodyHtml = $this->sanitizeHtml($bodyHtmlRaw, $messageKey, $inlineMap, $externalCount);
        if ($bodyHtml === '' && $bodyText !== '') $bodyHtml = '<div class="pm-plain-mail">' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</div>';

        $large = $this->largeAttachmentService->extractFromBody($bodyHtmlRaw, $bodyText);
        foreach ($large as $largeAttachment) $attachments[] = $largeAttachment;

        $cache = array(
            'body_html'=>$bodyHtml,
            'body_text'=>$bodyText,
            'attachments'=>$attachments,
            'inline_images'=>$inlineImages,
            'external_image_count'=>(int)$externalCount,
            'source'=>'imap_bodystructure_v2',
            'mailbox'=>$mailbox,
            'uid'=>$uid
        );
        $cache = PublicMailStorageService::saveBodyCache($messageKey, $cache);

        foreach ($headerFields as $field=>$value) if ($value !== '') $messages[$messageKey][$field] = $value;
        $preview = $this->makePreviewText($bodyText !== '' ? $bodyText : strip_tags($bodyHtml));
        if ($preview !== '') $messages[$messageKey]['preview'] = $preview;
        $messages[$messageKey]['body_cached'] = true;
        $messages[$messageKey]['body_cache_version'] = PublicMailStorageService::BODY_CACHE_VERSION;
        $messages[$messageKey]['body_cache_updated_at'] = isset($cache['cached_at']) ? $cache['cached_at'] : date('Y-m-d H:i:s');
        $messages[$messageKey]['has_attachment'] = !empty($attachments);
        $messages[$messageKey]['large_attachments'] = array();
        foreach ($large as $largeAttachment) if (isset($largeAttachment['large_id'])) $messages[$messageKey]['large_attachments'][$largeAttachment['large_id']] = $largeAttachment;
        PublicMailStorageService::saveMessages($messages);
        return $cache;
    }

    private function fetchTextBodyPart($client, $uid, $part)
    {
        $partId = isset($part['part_id']) ? (string)$part['part_id'] : '';
        if ($partId === '') return '';
        $size = isset($part['size']) ? (int)$part['size'] : 0;
        $maximum = max(1048576, min(12582912, $size > 0 ? $size + 65536 : 8388608));
        $raw = $client->fetchMimePart($uid, $partId, $maximum);
        $encoding = isset($part['transfer_encoding']) ? strtolower((string)$part['transfer_encoding']) : '';
        $decoded = $this->decodeBody($raw, $encoding);
        return $this->convertToUtf8($decoded, isset($part['charset']) ? (string)$part['charset'] : '');
    }

    private function parseBodyStructure($text)
    {
        $position = 0;
        $value = $this->parseBodyStructureValue((string)$text, $position);
        if (!is_array($value)) throw new \RuntimeException('메일 본문 구조가 올바르지 않습니다.');
        return $value;
    }

    private function parseBodyStructureValue($text, &$position)
    {
        $length = strlen($text);
        while ($position < $length && preg_match('/\s/', $text[$position])) $position++;
        if ($position >= $length) return null;
        if ($text[$position] === '(') {
            $position++; $list = array();
            while ($position < $length) {
                while ($position < $length && preg_match('/\s/', $text[$position])) $position++;
                if ($position < $length && $text[$position] === ')') { $position++; break; }
                $list[] = $this->parseBodyStructureValue($text, $position);
            }
            return $list;
        }
        if ($text[$position] === '"') {
            $position++; $value = ''; $escaped = false;
            while ($position < $length) {
                $char = $text[$position++];
                if ($escaped) { $value .= $char; $escaped = false; continue; }
                if ($char === '\\') { $escaped = true; continue; }
                if ($char === '"') break;
                $value .= $char;
            }
            return $value;
        }
        $start = $position;
        while ($position < $length && !preg_match('/[\s()]/', $text[$position])) $position++;
        $atom = substr($text, $start, $position - $start);
        if (strcasecmp($atom, 'NIL') === 0) return null;
        if ($atom !== '' && ctype_digit($atom)) return (int)$atom;
        return $atom;
    }

    private function flattenBodyStructure($node, $prefix, &$parts)
    {
        if (!is_array($node) || empty($node)) return;
        if (isset($node[0]) && is_array($node[0])) {
            $childIndex = 1;
            foreach ($node as $child) {
                if (!is_array($child)) break;
                $partId = $prefix === '' ? (string)$childIndex : $prefix . '.' . $childIndex;
                $this->flattenBodyStructure($child, $partId, $parts);
                $childIndex++;
            }
            return;
        }
        $type = isset($node[0]) ? strtolower((string)$node[0]) : 'application';
        $subtype = isset($node[1]) ? strtolower((string)$node[1]) : 'octet-stream';
        $params = $this->bodyStructureParameters(isset($node[2]) ? $node[2] : array());
        $contentId = isset($node[3]) ? $this->normalizeContentId((string)$node[3]) : '';
        $encoding = isset($node[5]) ? strtolower((string)$node[5]) : '';
        $size = isset($node[6]) ? (int)$node[6] : 0;
        $disposition = ''; $dispositionParams = array();
        for ($i=7; $i<count($node); $i++) {
            if (!is_array($node[$i]) || empty($node[$i]) || is_array($node[$i][0])) continue;
            $candidate = strtolower((string)$node[$i][0]);
            if ($candidate === 'inline' || $candidate === 'attachment') {
                $disposition = $candidate;
                $dispositionParams = $this->bodyStructureParameters(isset($node[$i][1]) ? $node[$i][1] : array());
                break;
            }
        }
        $filename = '';
        if (isset($dispositionParams['filename*'])) $filename = $dispositionParams['filename*'];
        elseif (isset($dispositionParams['filename'])) $filename = $dispositionParams['filename'];
        elseif (isset($params['name*'])) $filename = $params['name*'];
        elseif (isset($params['name'])) $filename = $params['name'];
        $filename = $this->decodeHeader($this->decodeRfc2231Value((string)$filename));
        $mime = $type . '/' . $subtype;
        $isInlineImage = $type === 'image' && ($contentId !== '' || $disposition === 'inline') && $disposition !== 'attachment';
        $isAttachment = !$isInlineImage && ($filename !== '' || $disposition === 'attachment');
        $parts[] = array(
            'part_id'=>$prefix === '' ? '1' : $prefix,
            'mime_type'=>$mime,
            'charset'=>isset($params['charset'])?(string)$params['charset']:'',
            'content_id'=>$contentId,
            'transfer_encoding'=>$encoding,
            'size'=>$size,
            'disposition'=>$disposition,
            'filename'=>$filename,
            'is_inline_image'=>$isInlineImage,
            'is_attachment'=>$isAttachment
        );
    }

    private function bodyStructureParameters($value)
    {
        $result = array();
        if (!is_array($value)) return $result;
        for ($i=0; $i+1<count($value); $i+=2) {
            $key = strtolower(trim((string)$value[$i]));
            if ($key === '') continue;
            $result[$key] = is_scalar($value[$i+1]) ? (string)$value[$i+1] : '';
        }
        return $result;
    }

    private function normalizeContentId($value)
    {
        $value = trim((string)$value);
        if (stripos($value, 'cid:') === 0) $value = substr($value, 4);
        return strtolower(trim($value, "<> \t\r\n\"'"));
    }

    private function requireEnabledSettings()
    {
        $settings=PublicMailStorageService::getSettings(true);
        if (empty($settings['enabled'])) throw new \RuntimeException('네이버 메일 연동이 사용 상태가 아닙니다. 관리자 설정을 확인하세요.');
        if (empty($settings['username']) || empty($settings['password'])) throw new \RuntimeException('네이버 아이디 또는 애플리케이션 비밀번호가 설정되지 않았습니다.');
        return $settings;
    }

    private function normalizeBatchLimit($limit,$settings)
    {
        $limit=(int)$limit; if ($limit<1) $limit=isset($settings['batch_size'])?(int)$settings['batch_size']:100;
        if ($limit<20) $limit=20; if ($limit>200) $limit=200; return $limit;
    }

    private function createClient($settings, $timeout = 20)
    {
        $timeout = max(5, min(30, (int)$timeout));
        return new PublicMailImapClient(isset($settings['imap_host'])?$settings['imap_host']:'imap.naver.com',isset($settings['imap_port'])?$settings['imap_port']:993,$timeout);
    }

    private function mergeConnectionSettings($temporarySettings)
    {
        $saved=PublicMailStorageService::getSettings(true); if (!is_array($temporarySettings)) $temporarySettings=array();
        foreach (array('username','password') as $field) if (isset($temporarySettings[$field]) && trim((string)$temporarySettings[$field])!=='') $saved[$field]=trim((string)$temporarySettings[$field]);
        $saved['imap_host']='imap.naver.com'; $saved['imap_port']=993;
        if (empty($saved['username'])||empty($saved['password'])) throw new \RuntimeException('네이버 아이디와 애플리케이션 비밀번호를 입력하세요.');
        return $saved;
    }

    private function discoverMailboxes($client,$settings)
    {
        $listed=$client->listMailboxes(); $result=array();
        foreach ($listed as $box) {
            if (empty($box['selectable'])) continue;
            $raw=isset($box['raw_name'])?(string)$box['raw_name']:''; $display=isset($box['display_name'])?(string)$box['display_name']:$raw;
            if ($raw==='') continue;
            $flags=isset($box['flags'])&&is_array($box['flags'])?$box['flags']:array();
            $isSpam=$this->mailboxMatches($raw,$display,$flags,array('\\Junk','spam','스팸'));
            $isTrash=$this->mailboxMatches($raw,$display,$flags,array('\\Trash','trash','휴지통','삭제'));
            if ($isSpam && empty($settings['include_spam'])) continue;
            if ($isTrash && empty($settings['include_trash'])) continue;
            $result[]=array('raw_name'=>$raw,'display_name'=>$display,'flags'=>$flags,'mailbox_type'=>$this->detectMailboxType($raw,$display,$flags));
        }
        if (empty($result)) $result[]=array('raw_name'=>'INBOX','display_name'=>'받은메일함','flags'=>array(),'mailbox_type'=>'inbox');
        return $result;
    }

    private function mailboxMatches($raw,$display,$flags,$terms)
    {
        $haystack=strtolower((string)$raw.' '.(string)$display.' '.implode(' ',$flags));
        foreach ($terms as $term) if (strpos($haystack,strtolower($term))!==false) return true;
        return false;
    }

    private function detectMailboxType($raw,$display,$flags)
    {
        $haystack=$this->lower((string)$raw.' '.(string)$display.' '.implode(' ',is_array($flags)?$flags:array()));
        if ($this->contains($haystack,'\\sent')||$this->contains($haystack,'sent')||$this->contains($haystack,'보낸')||$this->contains($haystack,'보냄')) return 'sent';
        if ($this->contains($haystack,'draft')||$this->contains($haystack,'임시')) return 'draft';
        if ($this->contains($haystack,'spam')||$this->contains($haystack,'junk')||$this->contains($haystack,'스팸')) return 'spam';
        if ($this->contains($haystack,'trash')||$this->contains($haystack,'휴지통')||$this->contains($haystack,'삭제')) return 'trash';
        if (strcasecmp((string)$raw,'INBOX')===0||$this->contains($haystack,'받은')) return 'inbox';
        return 'custom';
    }

    private function mailboxStateId($rawName)
    {
        return sha1((string)$rawName);
    }

    private function fetchAndBuildMessage($client,$box,$uid,$projects,$settings,&$gptUsed)
    {
        $header=$client->fetchHeader($uid); $message=$this->buildMessageFromHeader($header,$box['raw_name'],$box['display_name']);
        try {
            $previewRaw=$client->fetchRawPreview($uid,65536);
            if ($previewRaw!=='') {
                $parsed=$this->parseRawMessage($previewRaw,false);
                $message['preview']=$this->makePreviewText(isset($parsed['body_text'])?$parsed['body_text']:'');
                $message['has_attachment']=!empty($parsed['attachments'])||$this->rawMessageLooksLikeAttachment($previewRaw);
            }
        } catch (\Exception $ignored) { $message['preview']=''; }
        $message['classification']=$this->classifier->classify($message,$projects);
        if (!empty($settings['use_gpt_classifier']) && $this->isAmbiguousClassification($message['classification']) && $gptUsed<3) {
            $gpt=$this->classifier->classifyAmbiguousWithGpt($message,$projects,$message['classification']);
            if (is_array($gpt)) { $message['classification']=$gpt; $gptUsed++; } else $message['classification']['gpt_pending']=true;
        }
        $message['synced_at']=date('Y-m-d H:i:s'); return $message;
    }

    private function buildMessageFromHeader($headerData,$mailbox,$mailboxName)
    {
        $headers=$this->parseHeaders(isset($headerData['header'])?$headerData['header']:'');
        $subject=$this->decodeHeader(isset($headers['subject'])?$headers['subject']:'');
        $fromText=$this->decodeHeader(isset($headers['from'])?$headers['from']:'');
        $toText=$this->decodeHeader(isset($headers['to'])?$headers['to']:'');
        $dateText=isset($headers['date'])?trim((string)$headers['date']):''; $timestamp=$dateText!==''?strtotime($dateText):false; if ($timestamp===false) $timestamp=time();
        $flags=isset($headerData['flags'])&&is_array($headerData['flags'])?$headerData['flags']:array(); $isSeen=false; $isFlagged=false;
        foreach ($flags as $flag) { if (strcasecmp($flag,'\\Seen')===0) $isSeen=true; if (strcasecmp($flag,'\\Flagged')===0) $isFlagged=true; }
        $uid=isset($headerData['uid'])?(int)$headerData['uid']:0; $key=PublicMailStorageService::messageKey($mailbox,$uid);
        return array(
            'message_key'=>$key,'uid'=>$uid,'mailbox'=>$mailbox,'mailbox_name'=>$mailboxName,'mailbox_type'=>$this->detectMailboxType($mailbox,$mailboxName,array()),
            'message_id'=>isset($headers['message-id'])?trim((string)$headers['message-id']):'',
            'subject'=>$subject!==''?$subject:'(제목 없음)','from_text'=>$fromText,'from_email'=>$this->extractEmail($fromText),
            'to_text'=>$toText,'cc_text'=>$this->decodeHeader(isset($headers['cc'])?$headers['cc']:''),
            'date_text'=>date('Y-m-d H:i:s',$timestamp),'timestamp'=>(int)$timestamp,'size'=>isset($headerData['size'])?(int)$headerData['size']:0,
            'is_seen'=>$isSeen,'is_flagged'=>$isFlagged,'has_attachment'=>$this->headerLooksLikeAttachment($headers),'preview'=>'','classification'=>array()
        );
    }

    private function calculateImportProgress($mailboxes)
    {
        $total=0; $processed=0; $remaining=0;
        foreach ($mailboxes as $box) { if (!is_array($box)) continue; $t=isset($box['total_count'])?(int)$box['total_count']:0; $i=isset($box['imported_count'])?(int)$box['imported_count']:0; $total+=$t; $processed+=min($t,$i); $remaining+=max(0,$t-$i); }
        return array('total_count'=>$total,'processed_count'=>$processed,'remaining_count'=>$remaining);
    }

    private function maximumKnownUid($messages,$mailbox)
    {
        $max=0; foreach ($messages as $key=>$message) { if (!is_array($message)) continue; $parsed=PublicMailStorageService::parseMessageKey($key); $box=isset($message['mailbox'])?$message['mailbox']:$parsed['mailbox']; if ((string)$box!==(string)$mailbox) continue; $uid=isset($message['uid'])?(int)$message['uid']:(int)$parsed['uid']; if ($uid>$max) $max=$uid; } return $max;
    }

    private function countKnownMailboxMessages($messages,$mailbox)
    {
        $count=0; foreach ($messages as $key=>$message) { if (!is_array($message)) continue; $parsed=PublicMailStorageService::parseMessageKey($key); $box=isset($message['mailbox'])?$message['mailbox']:$parsed['mailbox']; if ((string)$box===(string)$mailbox) $count++; } return $count;
    }

    private function matchesFilters($message,$filters)
    {
        if (!is_array($filters)) return true;
        $query=isset($filters['query'])?trim((string)$filters['query']):'';
        if ($query!=='') {
            $haystack=$this->lower((isset($message['subject'])?$message['subject']:'').' '.(isset($message['from_text'])?$message['from_text']:'').' '.(isset($message['preview'])?$message['preview']:''));
            if (!$this->contains($haystack,$this->lower($query))) return false;
        }
        $classification=isset($message['classification'])&&is_array($message['classification'])?$message['classification']:array();
        $workflow=isset($message['workflow'])&&is_array($message['workflow'])?$message['workflow']:array();
        $period=isset($filters['period'])?trim((string)$filters['period']):''; $cutoff=0;
        if ($period==='1m') $cutoff=strtotime('-1 month'); elseif ($period==='3m') $cutoff=strtotime('-3 months'); elseif ($period==='6m') $cutoff=strtotime('-6 months'); elseif ($period==='1y') $cutoff=strtotime('-1 year');
        if ($cutoff>0 && isset($message['timestamp']) && (int)$message['timestamp']<$cutoff) return false;
        $mailbox=isset($filters['mailbox'])?trim((string)$filters['mailbox']):''; if ($mailbox!=='' && (!isset($message['mailbox']) || (string)$message['mailbox']!==$mailbox)) return false;
        $mailboxType=isset($filters['mailbox_type'])?trim((string)$filters['mailbox_type']):''; if ($mailboxType!=='' && (!isset($message['mailbox_type']) || (string)$message['mailbox_type']!==$mailboxType)) return false;
        $department=isset($filters['department'])?trim((string)$filters['department']):''; if ($department!=='') { $actual=!empty($workflow['department'])?$workflow['department']:(isset($classification['department'])?$classification['department']:''); if ($actual!==$department) return false; }
        $status=isset($filters['status'])?trim((string)$filters['status']):''; if ($status!=='' && (!isset($workflow['status']) || $workflow['status']!==$status)) return false;
        $priority=isset($filters['priority'])?trim((string)$filters['priority']):''; if ($priority!=='') { $actual=!empty($workflow['priority'])?$workflow['priority']:(isset($classification['priority'])?$classification['priority']:''); if ($actual!==$priority) return false; }
        $projectId=isset($filters['project_id'])?trim((string)$filters['project_id']):''; if ($projectId!=='') { $actual=!empty($workflow['project_id'])?(string)$workflow['project_id']:(isset($classification['project_id'])?(string)$classification['project_id']:''); if ($actual!==$projectId) return false; }
        $assigneeId=isset($filters['assignee_id'])?trim((string)$filters['assignee_id']):''; if ($assigneeId!=='' && (!isset($workflow['assignee_id']) || (string)$workflow['assignee_id']!==$assigneeId)) return false;
        $quick=isset($filters['quick'])?trim((string)$filters['quick']):'';
        if ($quick==='unread'&&!empty($message['is_seen'])) return false;
        if ($quick==='urgent'&&(!isset($classification['priority'])||$classification['priority']!=='긴급')) return false;
        if ($quick==='unclassified'&&isset($classification['department'])&&$classification['department']!==''&&$classification['department']!=='미분류') return false;
        if ($quick==='unassigned'&&(!empty($workflow['assignee_id'])||!empty($workflow['assignee_name']))) return false;
        if ($quick==='unfinished'&&isset($workflow['status'])&&in_array($workflow['status'],array('처리완료','발송완료'),true)) return false;
        return true;
    }

    private function isAmbiguousClassification($classification)
    {
        if (!is_array($classification)) return true; $department=isset($classification['department'])?(string)$classification['department']:''; $score=isset($classification['department_score'])?(int)$classification['department_score']:0;
        return $department===''||$department==='미분류'||$score<=1;
    }

    public function compareMessageDateDesc($a,$b)
    {
        $at=isset($a['timestamp'])?(int)$a['timestamp']:0; $bt=isset($b['timestamp'])?(int)$b['timestamp']:0; if ($at===$bt) return 0; return $at>$bt?-1:1;
    }
    private function parseMimeEntity($raw, $partId, $includeAttachmentContent)
    {
        list($headerText, $body) = $this->splitHeaderBody($raw);
        $headers = $this->parseHeaders($headerText);
        $contentType = isset($headers['content-type']) ? $headers['content-type'] : 'text/plain; charset=UTF-8';
        $contentDisposition = isset($headers['content-disposition']) ? $headers['content-disposition'] : '';
        $transferEncoding = isset($headers['content-transfer-encoding']) ? strtolower(trim($headers['content-transfer-encoding'])) : '';

        $typeInfo = $this->parseHeaderWithParameters($contentType);
        $dispositionInfo = $this->parseHeaderWithParameters($contentDisposition);
        $mimeType = strtolower($typeInfo['value'] !== '' ? $typeInfo['value'] : 'text/plain');
        $charset = isset($typeInfo['params']['charset']) ? trim($typeInfo['params']['charset'], " \t\r\n\"'") : '';
        $boundary = isset($typeInfo['params']['boundary']) ? trim($typeInfo['params']['boundary'], " \t\r\n\"'") : '';
        $filename = '';

        if (isset($dispositionInfo['params']['filename'])) {
            $filename = $dispositionInfo['params']['filename'];
        } elseif (isset($typeInfo['params']['name'])) {
            $filename = $typeInfo['params']['name'];
        }
        $filename = $this->decodeHeader(trim((string)$filename, " \t\r\n\"'"));

        $entity = array(
            'part_id' => (string)$partId,
            'headers' => $headers,
            'mime_type' => $mimeType,
            'charset' => $charset,
            'disposition' => strtolower($dispositionInfo['value']),
            'filename' => $filename,
            'content' => '',
            'size' => 0,
            'children' => array()
        );

        if (strpos($mimeType, 'multipart/') === 0 && $boundary !== '') {
            $parts = $this->splitMultipartBody($body, $boundary);
            $index = 1;
            foreach ($parts as $partRaw) {
                $childPartId = $partId === '1' ? (string)$index : $partId . '.' . $index;
                $entity['children'][] = $this->parseMimeEntity($partRaw, $childPartId, $includeAttachmentContent);
                $index++;
            }
            return $entity;
        }

        $decoded = $this->decodeBody($body, $transferEncoding);
        $entity['size'] = strlen($decoded);

        $isAttachment = $filename !== '' || $entity['disposition'] === 'attachment';
        if ($isAttachment) {
            if ($includeAttachmentContent) {
                $entity['content'] = $decoded;
            }
        } else {
            if (strpos($mimeType, 'text/') === 0) {
                $entity['content'] = $this->convertToUtf8($decoded, $charset);
            }
        }

        return $entity;
    }

    private function collectMimeParts($entity, &$textBodies, &$htmlBodies, &$attachments)
    {
        if (!empty($entity['children'])) {
            foreach ($entity['children'] as $child) {
                $this->collectMimeParts($child, $textBodies, $htmlBodies, $attachments);
            }
            return;
        }

        $filename = isset($entity['filename']) ? (string)$entity['filename'] : '';
        $disposition = isset($entity['disposition']) ? (string)$entity['disposition'] : '';
        $mimeType = isset($entity['mime_type']) ? (string)$entity['mime_type'] : 'application/octet-stream';
        $isAttachment = $filename !== '' || $disposition === 'attachment';

        if ($isAttachment) {
            if ($filename === '') {
                $filename = 'attachment_' . str_replace('.', '_', (string)$entity['part_id']);
            }
            $attachments[] = array(
                'part_id' => (string)$entity['part_id'],
                'filename' => $this->safeFilename($filename),
                'mime_type' => $mimeType,
                'size' => isset($entity['size']) ? (int)$entity['size'] : 0,
                'content' => isset($entity['content']) ? $entity['content'] : ''
            );
            return;
        }

        if ($mimeType === 'text/plain' && trim((string)$entity['content']) !== '') {
            $textBodies[] = trim((string)$entity['content']);
        } elseif ($mimeType === 'text/html' && trim((string)$entity['content']) !== '') {
            $htmlBodies[] = trim((string)$entity['content']);
        }
    }

    private function parseHeaders($headerText)
    {
        $headerText = preg_replace("/\r?\n[ \t]+/", ' ', (string)$headerText);
        $lines = preg_split('/\r?\n/', $headerText);
        $headers = array();

        foreach ($lines as $line) {
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $position)));
            $value = trim(substr($line, $position + 1));
            if ($name === '') {
                continue;
            }
            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function parseHeaderWithParameters($value)
    {
        $segments = preg_split('/;(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/', (string)$value);
        $result = array('value' => '', 'params' => array());
        if (!is_array($segments) || empty($segments)) return $result;
        $result['value'] = strtolower(trim(array_shift($segments)));
        $continuations = array();
        $continuationEncoded = array();

        foreach ($segments as $segment) {
            $position = strpos($segment, '=');
            if ($position === false) continue;
            $name = strtolower(trim(substr($segment, 0, $position)));
            $parameterValue = trim(substr($segment, $position + 1));
            $parameterValue = trim($parameterValue, " \t\r\n\"'");
            if ($name === '') continue;

            if (preg_match('/^(.+)\*([0-9]+)(\*)?$/', $name, $matches)) {
                $baseName = $matches[1];
                $index = (int)$matches[2];
                if (!isset($continuations[$baseName])) $continuations[$baseName] = array();
                $continuations[$baseName][$index] = $parameterValue;
                if (!empty($matches[3])) $continuationEncoded[$baseName] = true;
                continue;
            }

            if (substr($name, -1) === '*') {
                $name = substr($name, 0, -1);
                $parameterValue = $this->decodeRfc2231Value($parameterValue);
            }
            $result['params'][$name] = $this->decodeHeader($parameterValue);
        }

        foreach ($continuations as $baseName => $pieces) {
            ksort($pieces, SORT_NUMERIC);
            $combined = implode('', $pieces);
            if (!empty($continuationEncoded[$baseName]) || strpos($combined, "''") !== false) {
                $combined = $this->decodeRfc2231Value($combined);
            } else {
                $combined = $this->ensureUtf8(rawurldecode($combined), '');
            }
            $result['params'][$baseName] = $this->decodeHeader($combined);
        }
        return $result;
    }

    private function splitHeaderBody($raw)
    {
        $position = strpos($raw, "\r\n\r\n");
        $separatorLength = 4;

        if ($position === false) {
            $position = strpos($raw, "\n\n");
            $separatorLength = 2;
        }

        if ($position === false) {
            return array($raw, '');
        }

        return array(substr($raw, 0, $position), substr($raw, $position + $separatorLength));
    }

    private function splitMultipartBody($body, $boundary)
    {
        $delimiter = '--' . $boundary;
        $endDelimiter = $delimiter . '--';
        $lines = preg_split('/\r?\n/', (string)$body);
        $parts = array();
        $current = array();
        $inside = false;

        $closed = false;
        foreach ($lines as $line) {
            if ($line === $delimiter) {
                if ($inside && !empty($current)) {
                    $parts[] = implode("\r\n", $current);
                }
                $current = array();
                $inside = true;
                continue;
            }
            if ($line === $endDelimiter) {
                if ($inside && !empty($current)) {
                    $parts[] = implode("\r\n", $current);
                }
                $closed = true;
                break;
            }
            if ($inside) {
                $current[] = $line;
            }
        }
        // 미리보기용 부분 수신은 마지막 boundary가 잘릴 수 있으므로 현재 part도 사용합니다.
        if (!$closed && $inside && !empty($current)) {
            $parts[] = implode("\r\n", $current);
        }

        return $parts;
    }

    private function decodeBody($body, $encoding)
    {
        $encoding = strtolower(trim((string)$encoding));
        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', (string)$body), true);
            return $decoded === false ? '' : $decoded;
        }
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode((string)$body);
        }
        return (string)$body;
    }

    private function decodeHeader($value)
    {
        $value = trim((string)$value);
        if ($value === '') return '';

        // 여러 줄로 나뉜 RFC 2047 encoded-word를 먼저 이어 붙입니다.
        $value = preg_replace('/\?=\s+=\?/', '?==?', $value);

        // RFC 2047 encoded-word를 직접 해석합니다. 정상 UTF-8은 다시 변환하지 않고,
        // 잘못된 CP949/EUC-KR 이중변환 흔적이 있을 때만 점수 기반으로 복구합니다.
        $self = $this;
        $manual = preg_replace_callback('/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/', function ($matches) use ($self) {
            $charset = isset($matches[1]) ? $matches[1] : '';
            $mode = isset($matches[2]) ? strtoupper($matches[2]) : 'Q';
            $payload = isset($matches[3]) ? $matches[3] : '';
            if ($mode === 'B') {
                $raw = base64_decode($payload, true);
                if ($raw === false) $raw = '';
            } else {
                $raw = quoted_printable_decode(str_replace('_', ' ', $payload));
            }
            return $self->repairMojibake($self->convertToUtf8($raw, $charset));
        }, $value);
        if (is_string($manual) && $manual !== '' && strpos($manual, '=?') === false) {
            return $this->repairMojibake($this->ensureUtf8($manual, ''));
        }

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false && $decoded !== '' && strpos($decoded, '=?') === false) {
                return $this->repairMojibake($this->ensureUtf8($decoded, ''));
            }
        }
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if ($decoded !== false && $decoded !== '' && strpos($decoded, '=?') === false) {
                return $this->repairMojibake($this->ensureUtf8($decoded, ''));
            }
        }

        return $this->repairMojibake($this->ensureUtf8(is_string($manual) ? $manual : $value, ''));
    }

    private function decodeRfc2231Value($value)
    {
        $value = (string)$value;
        if (preg_match("/^([^']*)'[^']*'(.*)$/", $value, $matches)) {
            return $this->convertToUtf8(rawurldecode($matches[2]), trim($matches[1]));
        }
        return $this->ensureUtf8(rawurldecode($value), '');
    }

    private function normalizeCharset($charset)
    {
        $charset = strtoupper(trim((string)$charset, " \t\r\n\"'"));
        $charset = str_replace('_', '-', $charset);
        $map = array(
            'KS-C-5601-1987' => 'CP949',
            'KS-C-5601-1989' => 'CP949',
            'KSC5601' => 'CP949',
            'KSC-5601' => 'CP949',
            'WINDOWS-949' => 'CP949',
            'X-WINDOWS-949' => 'CP949',
            'MS949' => 'CP949',
            'CP-949' => 'CP949',
            'UHC' => 'CP949',
            'EUC-KR' => 'EUC-KR',
            'UTF8' => 'UTF-8'
        );
        return isset($map[$charset]) ? $map[$charset] : $charset;
    }

    private function isValidUtf8($value)
    {
        return $value === '' || @preg_match('//u', (string)$value) === 1;
    }

    private function ensureUtf8($value, $declaredCharset)
    {
        $value = (string)$value;
        if ($value === '') return '';
        $declaredCharset = $this->normalizeCharset($declaredCharset);
        if (($declaredCharset === '' || $declaredCharset === 'UTF-8' || $declaredCharset === 'US-ASCII') && $this->isValidUtf8($value)) {
            return $value;
        }
        return $this->convertToUtf8($value, $declaredCharset);
    }

    private function convertToUtf8($value, $charset)
    {
        $value = (string)$value;
        if ($value === '') return '';
        $charset = $this->normalizeCharset($charset);

        if (($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') && $this->isValidUtf8($value)) {
            return $this->repairMojibake($value);
        }

        $candidates = array();
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') $candidates[] = $charset;
        foreach (array('CP949','EUC-KR','ISO-8859-1') as $candidate) {
            if (!in_array($candidate, $candidates, true)) $candidates[] = $candidate;
        }

        $best = '';
        $bestScore = -999999;
        // 선언된 문자셋이 틀렸더라도 원본 바이트가 이미 정상 UTF-8이면 후보로 유지합니다.
        if ($this->isValidUtf8($value)) {
            $best = $this->repairMojibake($value);
            $bestScore = $this->textQualityScore($best);
        }
        foreach ($candidates as $candidate) {
            $converted = false;
            if (function_exists('iconv')) $converted = @iconv($candidate, 'UTF-8//IGNORE', $value);
            if (($converted === false || $converted === '') && function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', $candidate);
            }
            if ($converted === false || $converted === '' || !$this->isValidUtf8($converted)) continue;
            $converted = $this->repairMojibake($converted);
            $score = $this->textQualityScore($converted);
            if ($score > $bestScore) { $best = $converted; $bestScore = $score; }
        }
        if ($best !== '') return $best;

        if ($this->isValidUtf8($value)) return $this->repairMojibake($value);
        return preg_replace('/[\x80-\xFF]/', '?', $value);
    }

    /** UTF-8/CP949/EUC-KR 이중변환으로 깨진 문자열을 품질점수로 안전하게 복구합니다. */
    private function repairMojibake($value)
    {
        $value = (string)$value;
        if ($value === '' || !$this->isValidUtf8($value)) return $value;
        $candidates = array($value);
        foreach (array('CP949','EUC-KR','ISO-8859-1','WINDOWS-1252') as $target) {
            if (!function_exists('iconv')) continue;
            $bytes = @iconv('UTF-8', $target . '//IGNORE', $value);
            if ($bytes !== false && $bytes !== '' && $this->isValidUtf8($bytes)) $candidates[] = $bytes;
            if ($bytes !== false && $bytes !== '') {
                foreach (array('CP949','EUC-KR','UTF-8') as $source) {
                    $decoded = @iconv($source, 'UTF-8//IGNORE', $bytes);
                    if ($decoded !== false && $decoded !== '' && $this->isValidUtf8($decoded)) $candidates[] = $decoded;
                }
            }
        }
        $best = $value;
        $baseScore = $this->textQualityScore($value);
        $bestScore = $baseScore;
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || !$this->isValidUtf8($candidate)) continue;
            $score = $this->textQualityScore($candidate);
            if ($score > $bestScore) { $best = $candidate; $bestScore = $score; }
        }
        return ($best !== $value && $bestScore >= $baseScore + 6) ? $best : $value;
    }

    private function textQualityScore($value)
    {
        $value = (string)$value;
        if ($value === '') return 0;
        $score = 0;
        $hangul = preg_match_all('/[가-힣]/u', $value, $m);
        $jamo = preg_match_all('/[ㄱ-ㅎㅏ-ㅣ]/u', $value, $m);
        $cjk = preg_match_all('/[一-龥]/u', $value, $m);
        $replacement = substr_count($value, "\xEF\xBF\xBD");
        $question = substr_count($value, '?');
        $control = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value, $m);
        $latinMojibake = preg_match_all('/(?:Ã.|Â.|ì.|ë.|ê.|ð.|þ.|æ.|å.)/u', $value, $m);
        $encodedWord = substr_count($value, '=?');
        $score += (int)$hangul * 3;
        $score += (int)$jamo;
        $score -= (int)$cjk * 2;
        $score -= (int)$replacement * 20;
        $score -= (int)$control * 20;
        $score -= (int)$latinMojibake * 5;
        $score -= (int)$encodedWord * 12;
        if ($question > 5) $score -= ($question - 5) * 2;
        if (preg_match('/[가-힣]{2,}/u', $value)) $score += 8;
        if (preg_match('/(메일|세금|계산서|발행|안내|현장|공사|요청|첨부|주식회사|담당자)/u', $value)) $score += 10;
        return $score;
    }

    private function looksBrokenText($value)
    {
        $value = trim((string)$value);
        if ($value === '') return false;
        if (!$this->isValidUtf8($value) || strpos($value, '=?') !== false || strpos($value, "\xEF\xBF\xBD") !== false) return true;
        $hangulCount = preg_match_all('/[가-힣]/u', $value, $matches);
        $cjkCount = preg_match_all('/[一-龥]/u', $value, $matches);
        if ($hangulCount >= 4 && $cjkCount >= 1) return true;
        $repaired = $this->repairMojibake($value);
        return $repaired !== $value && $this->textQualityScore($repaired) >= $this->textQualityScore($value) + 6;
    }

    private function sanitizeHtml($html, $messageKey = '', $inlineMap = array(), &$externalCount = null)
    {
        $html = $this->ensureUtf8((string)$html, '');
        // style/script 태그는 내부 내용까지 제거하여 CSS 코드가 본문에 노출되지 않게 합니다.
        $html = preg_replace('#<(style|script|noscript|template|title)[^>]*>.*?</\\1>#is', '', $html);
        $html = preg_replace('/<!--\\[if.*?<!\\[endif\\]-->/is', '', $html);
        $externalCount = 0;
        if ($html === '') return '';
        if (!class_exists('DOMDocument')) {
            return $this->sanitizeHtmlFallback($html, $messageKey, $inlineMap, $externalCount);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!DOCTYPE html><html><body><div id="pm-mail-root">' . $html . '</div></body></html>';
        $options = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) $options |= LIBXML_HTML_NOIMPLIED;
        if (defined('LIBXML_HTML_NODEFDTD')) $options |= LIBXML_HTML_NODEFDTD;
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped, $options);
        if (!$loaded) {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
            return $this->sanitizeHtmlFallback($html, $messageKey, $inlineMap, $externalCount);
        }
        $documentChildren = array();
        for ($documentIndex = 0; $documentIndex < $dom->childNodes->length; $documentIndex++) {
            $documentChildren[] = $dom->childNodes->item($documentIndex);
        }
        foreach ($documentChildren as $child) {
            if ($child && $child->nodeType === XML_PI_NODE && $child->parentNode) $child->parentNode->removeChild($child);
        }
        $root = $dom->getElementById('pm-mail-root');
        if (!$root) {
            $nodes = $dom->getElementsByTagName('div');
            $root = $nodes->length > 0 ? $nodes->item(0) : null;
        }
        if (!$root) {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
            return $this->sanitizeHtmlFallback($html, $messageKey, $inlineMap, $externalCount);
        }

        $this->sanitizeDomChildren($root, $messageKey, is_array($inlineMap)?$inlineMap:array(), $externalCount);
        $output = '';
        foreach ($root->childNodes as $child) $output .= $dom->saveHTML($child);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        return trim($output);
    }

    private function sanitizeDomChildren($parent, $messageKey, $inlineMap, &$externalCount)
    {
        $allowedTags = array('p','br','div','span','strong','b','em','i','u','s','ul','ol','li','table','thead','tbody','tfoot','tr','th','td','colgroup','col','hr','blockquote','pre','code','h1','h2','h3','h4','h5','h6','a','img','center','font','small','big','sup','sub');
        $dangerousTags = array('script','style','noscript','template','title','iframe','object','embed','form','input','button','textarea','select','option','meta','link','base','svg','math','video','audio','canvas');
        $children = array();
        foreach ($parent->childNodes as $child) $children[] = $child;
        foreach ($children as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) { $parent->removeChild($node); continue; }
            if ($node->nodeType === XML_TEXT_NODE) { $node->nodeValue = $this->repairMojibake((string)$node->nodeValue); continue; }
            if ($node->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($node->nodeName);
            if (in_array($tag, $dangerousTags, true)) { $parent->removeChild($node); continue; }
            if (!in_array($tag, $allowedTags, true)) {
                while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                $parent->removeChild($node); continue;
            }
            $this->sanitizeDomElement($node, $tag, $messageKey, $inlineMap, $externalCount);
            if ($node->parentNode) $this->sanitizeDomChildren($node, $messageKey, $inlineMap, $externalCount);
        }
    }

    private function sanitizeDomElement($node, $tag, $messageKey, $inlineMap, &$externalCount)
    {
        $attrs = array();
        if ($node->hasAttributes()) foreach ($node->attributes as $attr) $attrs[] = $attr->name;
        $genericAllowed = array('title','width','height','align','valign','colspan','rowspan','cellpadding','cellspacing','border','bgcolor','alt','style','face','size');
        foreach ($attrs as $name) {
            $lower = strtolower($name); $value = $node->getAttribute($name);
            if (strpos($lower, 'on') === 0 || in_array($lower, array('srcset','formaction','poster','background','id','class'), true)) { $node->removeAttribute($name); continue; }
            if ($lower === 'style') { $style = $this->sanitizeInlineStyle($value); if ($style === '') $node->removeAttribute($name); else $node->setAttribute('style', $style); continue; }
            if ($tag === 'a' && $lower === 'href') continue;
            if ($tag === 'img' && $lower === 'src') continue;
            if (!in_array($lower, $genericAllowed, true)) $node->removeAttribute($name);
        }
        if ($tag === 'a') {
            $href = trim((string)$node->getAttribute('href'));
            if (!$this->isSafeMailLink($href)) $node->removeAttribute('href');
            else { $node->setAttribute('target','_blank'); $node->setAttribute('rel','noopener noreferrer nofollow'); }
        }
        if ($tag === 'img') {
            $src = trim((string)$node->getAttribute('src'));
            if (strpos($src, '//') === 0) $src = 'https:' . $src;
            $alt = $this->repairMojibake(trim((string)$node->getAttribute('alt')));
            $width = (int)preg_replace('/[^0-9]/','',(string)$node->getAttribute('width'));
            $height = (int)preg_replace('/[^0-9]/','',(string)$node->getAttribute('height'));
            $style = (string)$node->getAttribute('style');
            if (stripos($src, 'cid:') === 0) {
                $cid = $this->normalizeContentId($src);
                if (isset($inlineMap[$cid]) && is_array($inlineMap[$cid]) && !empty($inlineMap[$cid]['part_id'])) {
                    $url = 'public_mail_action.php?action=inline_image&message_key=' . rawurlencode($messageKey) . '&part_id=' . rawurlencode($inlineMap[$cid]['part_id']);
                    $node->setAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
                    $node->setAttribute('data-pm-inline-part', (string)$inlineMap[$cid]['part_id']);
                    $node->setAttribute('data-pm-inline-src', $url);
                    $node->setAttribute('class', 'pm-inline-image'); $node->setAttribute('loading', 'lazy'); $node->setAttribute('decoding', 'async');
                    if ($alt === '' && !empty($inlineMap[$cid]['filename'])) $alt = (string)$inlineMap[$cid]['filename'];
                    if ($alt !== '') $node->setAttribute('alt', $alt);
                } else { if ($node->parentNode) $node->parentNode->removeChild($node); return; }
            } elseif (preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $src) && strlen($src) <= 2097152) {
                $node->setAttribute('class','pm-inline-image'); $node->setAttribute('loading','lazy'); $node->setAttribute('decoding','async');
            } elseif (preg_match('#^https?://#i', $src)) {
                if ($this->isTrackingImage($src, $width, $height, $style)) { if ($node->parentNode) $node->parentNode->removeChild($node); return; }
                $externalCount++;
                $node->setAttribute('src', $src); $node->setAttribute('class','pm-external-image'); $node->setAttribute('loading','lazy'); $node->setAttribute('decoding','async'); $node->setAttribute('referrerpolicy','no-referrer');
                if ($alt !== '') $node->setAttribute('alt',$alt);
            } else { if ($node->parentNode) $node->parentNode->removeChild($node); return; }
        }
    }

    private function isTrackingImage($src, $width, $height, $style)
    {
        if (($width > 0 && $width <= 2) || ($height > 0 && $height <= 2)) return true;
        if (preg_match('/(?:width|height)\s*:\s*(?:0|1|2)px/i', (string)$style)) return true;
        return preg_match('/(?:pixel|tracking|track(?:er)?|open(?:ed)?[._-]?(?:gif|png)?|beacon|spacer\.gif|transparent\.gif|1x1)/i', (string)$src) === 1;
    }

    private function sanitizeInlineStyle($style)
    {
        $allowed = array('width','min-width','max-width','height','min-height','max-height','text-align','vertical-align','background','background-color','color','font-size','font-family','font-weight','font-style','text-decoration','white-space','border','border-top','border-right','border-bottom','border-left','border-color','border-width','border-style','border-collapse','border-spacing','padding','padding-top','padding-right','padding-bottom','padding-left','margin','margin-top','margin-right','margin-bottom','margin-left','display','line-height','word-break','word-wrap','overflow-wrap');
        $safe = array();
        foreach (explode(';',(string)$style) as $declaration) {
            $pos = strpos($declaration, ':'); if ($pos === false) continue;
            $name = strtolower(trim(substr($declaration,0,$pos))); $value = trim(substr($declaration,$pos+1));
            if (!in_array($name,$allowed,true) || $value==='') continue;
            if (preg_match('/url\s*\(|expression\s*\(|javascript:|behavior\s*:|@import|-moz-binding/i',$value)) continue;
            if (strlen($value)>300) continue;
            $safe[] = $name . ':' . $value;
        }
        return implode(';', $safe);
    }

    private function isSafeMailLink($href)
    {
        $href = trim((string)$href);
        if ($href === '') return false;
        return preg_match('#^(https?://|mailto:|tel:)#i', $href) === 1;
    }

    private function sanitizeHtmlFallback($html, $messageKey, $inlineMap, &$externalCount)
    {
        $html = preg_replace('#<(script|style|noscript|template|title|iframe|object|embed|form|input|button|meta|link)[^>]*>.*?</\1>#is','',$html);
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i','',$html);
        $self = $this;
        $html = preg_replace_callback('#<img\b([^>]*)>#is', function($m) use ($self,$messageKey,$inlineMap,&$externalCount){
            $attrs=$m[1]; $src=''; $alt='';
            if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is',$attrs,$sm)) $src=trim($sm[2]);
            if (preg_match('/\balt\s*=\s*(["\'])(.*?)\1/is',$attrs,$am)) $alt=trim($am[2]);
            if (stripos($src,'cid:')===0) {
                $cid=$self->normalizeContentId($src);
                if (isset($inlineMap[$cid])&&!empty($inlineMap[$cid]['part_id'])) {
                    $partId=(string)$inlineMap[$cid]['part_id'];
                    $fallback='public_mail_action.php?action=inline_image&message_key='.rawurlencode($messageKey).'&part_id='.rawurlencode($partId);
                    return '<img class="pm-inline-image" loading="lazy" decoding="async" alt="'.htmlspecialchars($alt,ENT_QUOTES,'UTF-8').'" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" data-pm-inline-part="'.htmlspecialchars($partId,ENT_QUOTES,'UTF-8').'" data-pm-inline-src="'.htmlspecialchars($fallback,ENT_QUOTES,'UTF-8').'">';
                }
            }
            if (preg_match('#^https?://#i',$src)) {
                $width=0; $height=0;
                if (preg_match('/\bwidth\s*=\s*(["\']?)([0-9]+)\1/i',$attrs,$wm)) $width=(int)$wm[2];
                if (preg_match('/\bheight\s*=\s*(["\']?)([0-9]+)\1/i',$attrs,$hm)) $height=(int)$hm[2];
                if (($width>0&&$width<=2)||($height>0&&$height<=2)||preg_match('/(pixel|track|open\.gif|beacon)/i',$src)) return '';
                $width=0; $height=0; $style='';
                if (preg_match('/\bwidth\s*=\s*(["\']?)([0-9]+)\1/i',$attrs,$wm)) $width=(int)$wm[2];
                if (preg_match('/\bheight\s*=\s*(["\']?)([0-9]+)\1/i',$attrs,$hm)) $height=(int)$hm[2];
                if (preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/is',$attrs,$stm)) $style=$stm[2];
                if ($self->isTrackingImage($src,$width,$height,$style)) return '';
                $externalCount++;
                return '<img class="pm-external-image" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="'.htmlspecialchars($alt,ENT_QUOTES,'UTF-8').'" src="'.htmlspecialchars($src,ENT_QUOTES,'UTF-8').'">';
            }
            return '';
        },$html);
        $allowed='<p><br><div><span><strong><b><em><i><u><s><ul><ol><li><table><thead><tbody><tfoot><tr><th><td><colgroup><col><hr><blockquote><pre><code><h1><h2><h3><h4><h5><h6><a><img><center><font><small><big><sup><sub>';
        return strip_tags($html,$allowed);
    }

    private function makePreviewText($rawText)
    {
        $text = $this->repairMojibake($this->ensureUtf8((string)$rawText, ''));
        if ($text === '') return '';
        $text = preg_replace('/<[^>]+>/', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if (function_exists('mb_substr')) return mb_substr($text, 0, 1000, 'UTF-8');
        return substr($text, 0, 1000);
    }

    private function extractEmail($text)
    {
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', (string)$text, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', (string)$text, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function safeFilename($filename)
    {
        $filename = str_replace(array("\r", "\n", "\0", '/', '\\'), '_', (string)$filename);
        $filename = trim($filename);
        if ($filename === '') {
            return 'attachment.bin';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($filename, 0, 180, 'UTF-8');
        }
        return substr($filename, 0, 180);
    }


    private function workflowFromMap($workflow, $messageKey)
    {
        $default = array(
            'department'=>'','project_id'=>'','project_name'=>'','assignee_id'=>'','assignee_name'=>'',
            'status'=>'미확인','priority'=>'보통','important'=>false,'memo'=>'',
            'reply_completed'=>false,'reply_completed_at'=>'','reply_completed_by'=>'',
            'updated_at'=>'','updated_by'=>''
        );
        $key = (string)$messageKey;
        return isset($workflow[$key]) && is_array($workflow[$key])
            ? array_merge($default, $workflow[$key]) : $default;
    }

    private function normalizeStoredMessage($message)
    {
        if (!is_array($message)) return array();
        foreach (array('subject','from_text','to_text','cc_text') as $field) {
            if (isset($message[$field])) $message[$field] = $this->decodeHeader($message[$field]);
        }
        if (isset($message['preview'])) $message['preview'] = $this->makePreviewText($message['preview']);
        if (isset($message['from_text'])) $message['from_email'] = $this->extractEmail($message['from_text']);
        if (empty($message['mailbox'])) $message['mailbox'] = 'INBOX';
        if (empty($message['mailbox_name'])) $message['mailbox_name'] = $message['mailbox'] === 'INBOX' ? '받은메일함' : $message['mailbox'];
        if (empty($message['mailbox_type'])) $message['mailbox_type'] = $this->detectMailboxType($message['mailbox'],$message['mailbox_name'],array());
        if (empty($message['message_key']) && isset($message['uid'])) $message['message_key'] = PublicMailStorageService::messageKey($message['mailbox'], (int)$message['uid']);
        return $message;
    }

    private function rawFetchLimitForMessage($message)
    {
        $size = is_array($message) && isset($message['size']) ? (int)$message['size'] : 0;
        $limit = $size > 0 ? $size + 1048576 : 52428800;
        if ($limit < 31457280) $limit = 31457280;
        if ($limit > 104857600) $limit = 104857600;
        return $limit;
    }

    private function headerLooksLikeAttachment($headers)
    {
        if (!is_array($headers)) return false;
        $contentType = isset($headers['content-type']) ? strtolower((string)$headers['content-type']) : '';
        $disposition = isset($headers['content-disposition']) ? strtolower((string)$headers['content-disposition']) : '';
        return strpos($contentType, 'multipart/mixed') !== false
            || strpos($contentType, 'name=') !== false
            || strpos($disposition, 'attachment') !== false
            || strpos($disposition, 'filename=') !== false;
    }

    private function rawMessageLooksLikeAttachment($raw)
    {
        $raw = strtolower(substr((string)$raw, 0, 131072));
        return strpos($raw, 'content-disposition: attachment') !== false
            || strpos($raw, 'filename=') !== false
            || strpos($raw, 'content-type: multipart/mixed') !== false;
    }


    /** 깨진 메일 전체 복구 작업을 한 번 등록합니다. 실제 복구는 외부 cron이 이어서 처리합니다. */
    public function startMetadataRepair()
    {
        $existingState = PublicMailStorageService::getSyncState();
        if (!empty($existingState['metadata_repair']['active'])) {
            $existing = $existingState['metadata_repair'];
            $message = !empty($existing['paused'])
                ? '깨진 메일 복구가 일시중지되어 있습니다. [다시 시작]을 누르면 이어집니다.'
                : '깨진 메일 전체 복구가 이미 진행 중입니다. 외부 자동동기화가 계속 이어서 처리합니다.';
            return array('ok'=>true,'message'=>$message,'target_count'=>isset($existing['target_count'])?(int)$existing['target_count']:0,'state'=>$existingState);
        }
        $messages = PublicMailStorageService::getMessages();
        $total = count($messages);
        $targets = 0;
        foreach ($messages as $message) {
            if ($this->messageNeedsMetadataRepair($message)) $targets++;
        }
        $repair = array(
            'active' => $targets > 0,
            'paused' => false,
            'cancelled' => false,
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => $targets > 0 ? '' : date('Y-m-d H:i:s'),
            'last_run_at' => '',
            'cursor' => $targets > 0 ? 0 : $total,
            'total_count' => $total,
            'target_count' => $targets,
            'processed_count' => $targets > 0 ? 0 : $total,
            'repaired_count' => 0,
            'skipped_count' => $targets > 0 ? 0 : $total,
            'failed_count' => 0,
            'remaining_count' => $targets > 0 ? $total : 0,
            'last_message' => $targets > 0
                ? '깨진 메일 전체 복구를 등록했습니다. 외부 자동동기화가 1분마다 이어서 처리합니다.'
                : '복구가 필요한 깨진 메일을 찾지 못했습니다.',
            'last_error' => ''
        );
        $state = PublicMailStorageService::saveSyncState(array('metadata_repair'=>$repair));
        return array('ok'=>true,'message'=>$repair['last_message'],'target_count'=>$targets,'state'=>$state);
    }

    /** 깨진 메일 복구 작업의 일시중지, 다시 시작, 취소를 처리합니다. */
    public function controlMetadataRepair($command)
    {
        $state = PublicMailStorageService::getSyncState();
        $repair = $state['metadata_repair'];
        $command = trim((string)$command);
        if ($command === 'pause') {
            $repair['paused'] = true;
            $repair['last_message'] = '깨진 메일 복구를 일시중지했습니다.';
        } elseif ($command === 'resume') {
            if (!empty($repair['cancelled']) || (int)$repair['cursor'] >= (int)$repair['total_count']) return $this->startMetadataRepair();
            $repair['active'] = true;
            $repair['paused'] = false;
            $repair['cancelled'] = false;
            $repair['last_message'] = '깨진 메일 복구를 다시 시작했습니다.';
        } elseif ($command === 'cancel') {
            $repair['active'] = false;
            $repair['paused'] = false;
            $repair['cancelled'] = true;
            $repair['finished_at'] = date('Y-m-d H:i:s');
            $repair['last_message'] = '깨진 메일 복구를 취소했습니다.';
        } else {
            throw new \InvalidArgumentException('깨진 메일 복구 명령이 올바르지 않습니다.');
        }
        $state = PublicMailStorageService::saveSyncState(array('metadata_repair'=>$repair));
        return array('ok'=>true,'message'=>$repair['last_message'],'state'=>$state);
    }

    /**
     * 외부 cron 한 번당 깨진 메일을 제한된 수만 복구합니다.
     * 본문 전체는 받지 않고 헤더만 다시 읽으므로 일반 메일 화면의 속도에 영향을 주지 않습니다.
     */
    public function runMetadataRepairBatch($limit, $maximumSeconds)
    {
        $limit = max(1, min(50, (int)$limit));
        $maximumSeconds = max(5, min(25, (int)$maximumSeconds));
        $started = microtime(true);
        $state = PublicMailStorageService::getSyncState();
        $repair = $state['metadata_repair'];
        if (empty($repair['active'])) return array('ok'=>true,'message'=>'깨진 메일 복구가 실행 중이 아닙니다.','processed_count'=>0,'repaired_count'=>0,'state'=>$state);
        if (!empty($repair['paused'])) return array('ok'=>true,'message'=>'깨진 메일 복구가 일시중지되어 있습니다.','processed_count'=>0,'repaired_count'=>0,'state'=>$state);
        $repairLock = PublicMailStorageService::acquireLock('metadata_repair');
        if ($repairLock === false) return array('ok'=>true,'message'=>'다른 자동호출이 깨진 메일을 복구 중입니다. 다음 호출에서 이어집니다.','processed_count'=>0,'repaired_count'=>0,'state'=>$state);

        $messages = PublicMailStorageService::getMessages();
        $keys = array_keys($messages);
        $total = count($keys);
        $cursor = max(0, min($total, (int)$repair['cursor']));
        $processedThisRun = 0;
        $repairedThisRun = 0;
        $skippedThisRun = 0;
        $failedThisRun = 0;
        $settings = PublicMailStorageService::getSettings(true);
        $client = null;
        $selected = '';
        $changed = false;

        try {
            while ($cursor < $total && $processedThisRun < $limit) {
                if ((microtime(true) - $started) >= $maximumSeconds) break;
                $key = (string)$keys[$cursor];
                $cursor++;
                $processedThisRun++;
                $message = isset($messages[$key]) && is_array($messages[$key]) ? $messages[$key] : array();
                if (!$this->messageNeedsMetadataRepair($message)) {
                    $skippedThisRun++;
                    continue;
                }

                try {
                    if ($client === null) {
                        $client = $this->createClient($settings);
                        $client->connect();
                        $client->login($settings['username'], $settings['password']);
                    }
                    $parsed = PublicMailStorageService::parseMessageKey($key);
                    $mailbox = isset($message['mailbox']) && trim((string)$message['mailbox']) !== '' ? (string)$message['mailbox'] : $parsed['mailbox'];
                    $uid = isset($message['uid']) ? (int)$message['uid'] : (int)$parsed['uid'];
                    if ($selected !== $mailbox) {
                        $client->selectMailbox($mailbox);
                        $selected = $mailbox;
                    }
                    $displayName = isset($message['mailbox_name']) && trim((string)$message['mailbox_name']) !== ''
                        ? (string)$message['mailbox_name'] : ($mailbox === 'INBOX' ? '받은메일함' : $mailbox);
                    $header = $client->fetchHeader($uid);
                    $fresh = $this->buildMessageFromHeader($header, $mailbox, $displayName);

                    $bodyCache = PublicMailStorageService::getBodyCache($key);
                    if (is_array($bodyCache) && !empty($bodyCache['body_text'])) {
                        $fresh['preview'] = $this->repairMojibake($this->makePreviewText((string)$bodyCache['body_text']));
                        $fresh['has_attachment'] = isset($bodyCache['attachments']) && !empty($bodyCache['attachments']);
                    } else {
                        $fresh['preview'] = $this->repairMojibake(isset($message['preview']) ? (string)$message['preview'] : '');
                        $fresh['has_attachment'] = !empty($message['has_attachment']);
                    }

                    $fresh['classification'] = isset($message['classification']) ? $message['classification'] : array();
                    $fresh['synced_at'] = isset($message['synced_at']) ? $message['synced_at'] : date('Y-m-d H:i:s');
                    $messages[$key] = array_merge($message, $fresh);
                    if (is_array($bodyCache)) {
                        $messages[$key]['body_cached'] = true;
                        $messages[$key]['body_cache_version'] = PublicMailStorageService::BODY_CACHE_VERSION;
                        $messages[$key]['body_cache_updated_at'] = isset($bodyCache['cached_at']) ? (string)$bodyCache['cached_at'] : '';
                    }
                    $messages[$key]['metadata_repaired_at'] = date('Y-m-d H:i:s');
                    $messages[$key]['metadata_repair_failed'] = false;
                    $messages[$key]['metadata_repair_error'] = '';
                    $repairedThisRun++;
                    $changed = true;
                } catch (\Exception $messageError) {
                    $messages[$key]['metadata_repair_failed'] = true;
                    $messages[$key]['metadata_repair_error'] = substr($messageError->getMessage(), 0, 500);
                    $messages[$key]['metadata_repair_attempted_at'] = date('Y-m-d H:i:s');
                    $failedThisRun++;
                    $changed = true;
                }
            }
        } finally {
            if ($client !== null) $client->logout();
        }

        if ($changed) PublicMailStorageService::saveMessages($messages);
        $repair['cursor'] = $cursor;
        $repair['total_count'] = $total;
        $repair['processed_count'] = min($total, (int)$repair['processed_count'] + $processedThisRun);
        $repair['repaired_count'] = (int)$repair['repaired_count'] + $repairedThisRun;
        $repair['skipped_count'] = (int)$repair['skipped_count'] + $skippedThisRun;
        $repair['failed_count'] = (int)$repair['failed_count'] + $failedThisRun;
        $repair['remaining_count'] = max(0, $total - $cursor);
        $repair['last_run_at'] = date('Y-m-d H:i:s');
        $repair['last_error'] = $failedThisRun > 0 ? $failedThisRun . '건은 원본 헤더를 읽지 못했습니다.' : '';

        if ($cursor >= $total) {
            $repair['active'] = false;
            $repair['paused'] = false;
            $repair['finished_at'] = date('Y-m-d H:i:s');
            $repair['last_message'] = '깨진 메일 전체 복구를 완료했습니다. 복구 ' . number_format((int)$repair['repaired_count']) . '건, 확인 실패 ' . number_format((int)$repair['failed_count']) . '건입니다.';
        } else {
            $repair['last_message'] = '이번 자동호출에서 ' . $processedThisRun . '건을 확인하고 ' . $repairedThisRun . '건을 복구했습니다. 남은 확인 대상은 ' . number_format($repair['remaining_count']) . '건입니다.';
        }
        $state = PublicMailStorageService::saveSyncState(array('metadata_repair'=>$repair));
        PublicMailStorageService::releaseLock($repairLock);
        return array('ok'=>true,'message'=>$repair['last_message'],'processed_count'=>$processedThisRun,'repaired_count'=>$repairedThisRun,'failed_count'=>$failedThisRun,'state'=>$state);
    }

    /** 이전 호환용: 수동 호출이 들어오면 대기열을 시작하고 첫 묶음만 처리합니다. */
    public function repairBrokenMetadataBatch($limit)
    {
        $state = PublicMailStorageService::getSyncState();
        if (empty($state['metadata_repair']['active'])) $this->startMetadataRepair();
        $result = $this->runMetadataRepairBatch($limit, 20);
        return isset($result['repaired_count']) ? (int)$result['repaired_count'] : 0;
    }

    /** 저장된 메일의 제목, 주소, 미리보기 중 하나라도 깨졌는지 확인합니다. */
    private function messageNeedsMetadataRepair($message)
    {
        if (!is_array($message)) return false;
        $fields = array(
            isset($message['subject']) ? (string)$message['subject'] : '',
            isset($message['from_text']) ? (string)$message['from_text'] : '',
            isset($message['to_text']) ? (string)$message['to_text'] : '',
            isset($message['preview']) ? (string)$message['preview'] : ''
        );
        if (trim($fields[0]) === '' || trim($fields[3]) === '') return true;
        foreach ($fields as $fieldValue) if ($this->looksBrokenText($fieldValue)) return true;
        return false;
    }

    private function getPdoSafely()
    {
        try {
            if (class_exists('\\App\\Core\\Db')) {
                return \App\Core\Db::pdo();
            }
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }

    private function firstValue($row, $candidates)
    {
        foreach ($candidates as $candidate) {
            if (isset($row[$candidate]) && trim((string)$row[$candidate]) !== '') {
                return trim((string)$row[$candidate]);
            }
        }
        return '';
    }

    private function contains($haystack, $needle)
    {
        if ($needle === '') {
            return false;
        }
        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle, 0, 'UTF-8') !== false;
        }
        return strpos($haystack, $needle) !== false;
    }

    private function lower($value)
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower((string)$value, 'UTF-8');
        }
        return strtolower((string)$value);
    }
}
