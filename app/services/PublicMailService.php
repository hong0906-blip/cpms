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
    const VERSION = '1.7.19.1';
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
        /*
         * v1.7.12: 자동호출은 새 메일 수집과 본문 캐시 준비만 담당합니다.
         * 기존 제목 재수집은 연동 설정의 일반 페이지 요청에서만 실행합니다.
         */
        $state = PublicMailStorageService::getSyncState();
        if (!empty($state['full_import']['active']) && empty($state['full_import']['paused'])) {
            $result = $this->syncFullImportBatch($limit);
        } else {
            $result = $this->syncNewBatch($limit);
        }

        try {
            $prepared = $this->cacheUncachedBodiesBatch(10);
            $result['body_cached_count'] = isset($prepared['cached_count']) ? (int)$prepared['cached_count'] : 0;
        } catch (\Exception $ignored) {
            $result['body_cached_count'] = 0;
        }
        $result['title_refresh'] = 'settings_only';
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
            $client->connect();
            $client->login($settings['username'], $settings['password']);
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
            $failures = isset($state['new_message_failures']) && is_array($state['new_message_failures']) ? $state['new_message_failures'] : array();
            $recovery = isset($state['recent_mail_recovery']) && is_array($state['recent_mail_recovery']) ? $state['recent_mail_recovery'] : array();
            $recovery = array_merge(array(
                'active'=>false,'since_timestamp'=>0,'started_at'=>'','finished_at'=>'','last_run_at'=>'',
                'checked_count'=>0,'added_count'=>0,'failed_count'=>0,'remaining_count'=>0,
                'last_message'=>'최근 누락 메일 재확인이 아직 실행되지 않았습니다.'
            ), $recovery);

            /* 이전 PHP 실행이 메일 한 건에서 끊겼다면 같은 UID를 반복하지 않고 즉시 격리합니다. */
            $inflight = isset($state['new_message_inflight']) && is_array($state['new_message_inflight']) ? $state['new_message_inflight'] : array();
            if (!empty($inflight['message_key'])) {
                $staleKey = (string)$inflight['message_key'];
                $staleMailbox = isset($inflight['mailbox']) ? (string)$inflight['mailbox'] : 'INBOX';
                $staleUid = isset($inflight['uid']) ? (int)$inflight['uid'] : 0;
                if (!isset($messages[$staleKey]) && $staleUid > 0) {
                    $failures[$staleKey] = array(
                        'message_key'=>$staleKey,'mailbox'=>$staleMailbox,'uid'=>$staleUid,'attempts'=>99,
                        'status'=>'quarantined_fatal','last_error'=>'이전 실행이 이 메일을 처리하는 중 종료되어 자동 격리했습니다.',
                        'last_failed_at'=>date('Y-m-d H:i:s')
                    );
                    foreach ($mailboxes as $boxId => $boxState) {
                        if (isset($boxState['raw_name']) && (string)$boxState['raw_name'] === $staleMailbox) {
                            $mailboxes[$boxId]['last_uid'] = max(isset($boxState['last_uid'])?(int)$boxState['last_uid']:0, $staleUid);
                            break;
                        }
                    }
                }
                PublicMailStorageService::saveSyncState(array(
                    'mailboxes'=>$mailboxes,'new_message_inflight'=>array(),'new_message_failures'=>$failures
                ));
            }

            $added = 0; $gptUsed = 0; $searched = 0; $failedThisRun = 0;
            $recoveryChecked = 0; $recoveryAdded = 0; $recoveryMissingFound = 0; $scannedAllMailboxes = true;
            $sinceTimestamp = isset($recovery['since_timestamp']) ? (int)$recovery['since_timestamp'] : 0;
            if (!empty($recovery['active']) && $sinceTimestamp <= 0) $sinceTimestamp = time() - 172800;

            foreach ($mailboxes as $id => $box) {
                if ($added >= $limit) { $scannedAllMailboxes = false; break; }
                try {
                    $info = $client->selectMailbox($box['raw_name']);
                    $lastUid = isset($box['last_uid']) ? (int)$box['last_uid'] : 0;
                    if ($lastUid <= 0) $lastUid = $this->maximumKnownUid($messages, $box['raw_name']);

                    $retryUids = array();
                    foreach ($failures as $failure) {
                        if (!is_array($failure) || !isset($failure['mailbox']) || (string)$failure['mailbox'] !== (string)$box['raw_name']) continue;
                        if (isset($failure['status']) && strpos((string)$failure['status'], 'quarantined') === 0) continue;
                        if (!empty($failure['uid'])) $retryUids[(int)$failure['uid']] = (int)$failure['uid'];
                    }

                    $recentUids = array();
                    if (!empty($recovery['active'])) {
                        $recentUids = $client->searchUidsSince($sinceTimestamp);
                        $recoveryChecked += count($recentUids);
                    }
                    $afterUids = $client->searchUidsAfter($lastUid);
                    $searched += count($afterUids);

                    $candidateMap = array();
                    foreach ($retryUids as $uid) $candidateMap[(int)$uid] = (int)$uid;
                    foreach ($recentUids as $uid) $candidateMap[(int)$uid] = (int)$uid;
                    foreach ($afterUids as $uid) $candidateMap[(int)$uid] = (int)$uid;
                    $uids = array_values($candidateMap);
                    sort($uids, SORT_NUMERIC);
                    $newLastUid = $lastUid;

                    foreach ($uids as $uid) {
                        if ($added >= $limit) { $scannedAllMailboxes = false; break; }
                        $uid = (int)$uid;
                        $key = PublicMailStorageService::messageKey($box['raw_name'], $uid);
                        if (isset($messages[$key])) {
                            if ($uid > $newLastUid) $newLastUid = $uid;
                            if (isset($failures[$key])) unset($failures[$key]);
                            continue;
                        }
                        if (isset($failures[$key]['status']) && strpos((string)$failures[$key]['status'], 'quarantined') === 0) {
                            if ($uid > $newLastUid) $newLastUid = $uid;
                            continue;
                        }
                        if (!empty($recovery['active']) && in_array($uid, $recentUids, true)) $recoveryMissingFound++;

                        PublicMailStorageService::saveSyncState(array(
                            'new_message_inflight'=>array(
                                'message_key'=>$key,'mailbox'=>(string)$box['raw_name'],'uid'=>$uid,
                                'started_at'=>date('Y-m-d H:i:s')
                            ),
                            'new_message_failures'=>$failures,
                            'mailboxes'=>$mailboxes
                        ));

                        try {
                            $message = $this->fetchAndBuildMessage($client, $box, $uid, $projects, $settings, $gptUsed, true);
                            $messages[$key] = $message;
                            $added++;
                            if (!empty($recovery['active']) && in_array($uid, $recentUids, true)) $recoveryAdded++;
                            if (isset($failures[$key])) unset($failures[$key]);
                        } catch (\Exception $messageError) {
                            $oldAttempts = isset($failures[$key]['attempts']) ? (int)$failures[$key]['attempts'] : 0;
                            $attempts = $oldAttempts + 1;
                            $status = $attempts >= 2 ? 'quarantined_after_retry' : 'retry';
                            $failures[$key] = array(
                                'message_key'=>$key,'mailbox'=>(string)$box['raw_name'],'uid'=>$uid,'attempts'=>$attempts,
                                'status'=>$status,
                                'last_error'=>PublicMailStorageService::sanitizeText($messageError->getMessage(), 500),
                                'last_failed_at'=>date('Y-m-d H:i:s')
                            );
                            $failedThisRun++;
                        }

                        if ($uid > $newLastUid) $newLastUid = $uid;
                        PublicMailStorageService::saveSyncState(array(
                            'new_message_inflight'=>array(),'new_message_failures'=>$failures
                        ));
                    }

                    $box['last_uid'] = $newLastUid;
                    $box['total_count'] = isset($info['exists']) ? (int)$info['exists'] : (isset($box['total_count']) ? (int)$box['total_count'] : 0);
                    $box['imported_count'] = $this->countKnownMailboxMessages($messages, $box['raw_name']);
                    $box['remaining_count'] = max(0, $box['total_count'] - $box['imported_count']);
                    $box['last_error'] = '';
                    $mailboxes[$id] = $box;
                } catch (\Exception $boxError) {
                    $box['last_error'] = PublicMailStorageService::sanitizeText($boxError->getMessage(), 500);
                    $mailboxes[$id] = $box;
                }
            }

            if ($added > 0) PublicMailStorageService::saveMessages($messages);

            if (!empty($recovery['active'])) {
                $recovery['last_run_at'] = date('Y-m-d H:i:s');
                $recovery['checked_count'] = (int)$recovery['checked_count'] + $recoveryChecked;
                $recovery['added_count'] = (int)$recovery['added_count'] + $recoveryAdded;
                $quarantinedCount = 0;
                foreach ($failures as $failure) {
                    if (is_array($failure) && isset($failure['status']) && strpos((string)$failure['status'], 'quarantined') === 0) $quarantinedCount++;
                }
                $recovery['failed_count'] = $quarantinedCount;
                if ($scannedAllMailboxes && $recoveryMissingFound === 0) {
                    $recovery['active'] = false;
                    $recovery['finished_at'] = date('Y-m-d H:i:s');
                    $recovery['remaining_count'] = 0;
                    $recovery['last_message'] = '최근 48시간 누락 메일 재확인을 완료했습니다. ' . number_format((int)$recovery['added_count']) . '건을 추가했습니다.';
                } else {
                    $recovery['remaining_count'] = max(0, $recoveryMissingFound - $recoveryAdded);
                    $recovery['last_message'] = '최근 48시간 누락 메일을 확인 중입니다. 이번 실행에서 ' . number_format($recoveryAdded) . '건을 추가했습니다.';
                }
            }

            $progress = $this->calculateImportProgress($mailboxes);
            $state = PublicMailStorageService::saveSyncState(array(
                'last_success_at'=>date('Y-m-d H:i:s'),'last_error_at'=>'','last_error'=>'',
                'last_batch_count'=>$added,'last_gpt_count'=>$gptUsed,'last_search_count'=>$searched,
                'last_mode'=>'new','mailboxes'=>$mailboxes,'mailbox_total'=>$progress['total_count'],
                'remaining_count'=>isset($state['full_import']['remaining_count']) ? (int)$state['full_import']['remaining_count'] : 0,
                'new_message_inflight'=>array(),'new_message_failures'=>$failures,
                'recent_mail_recovery'=>$recovery
            ));
            $messageText = $added > 0 ? $added . '개의 새 메일을 가져왔습니다.' : '새로 가져올 메일이 없습니다.';
            if ($failedThisRun > 0) $messageText .= ' 읽지 못한 ' . $failedThisRun . '건은 격리하거나 다음 실행에서 한 번 더 확인합니다.';
            return array('ok'=>true,'message'=>$messageText,'added_count'=>$added,'failed_count'=>$failedThisRun,'search_count'=>$searched,'state'=>$state);
        } catch (\Exception $e) {
            PublicMailStorageService::saveSyncState(array('last_error_at'=>date('Y-m-d H:i:s'),'last_error'=>$e->getMessage(),'last_mode'=>'new','new_message_inflight'=>array()));
            throw $e;
        } finally {
            $client->logout();
            PublicMailStorageService::releaseLock($lock);
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
        $detail['body_document_html'] = is_array($cache) && isset($cache['body_document_html']) ? (string)$cache['body_document_html'] : '';
        $detail['body_document_ready'] = is_array($cache) && !empty($cache['body_document_html']);
        $detail['body_html_source'] = is_array($cache) && isset($cache['body_html_source']) ? (string)$cache['body_html_source'] : '';
        $detail['html_part_count'] = is_array($cache) && isset($cache['html_part_count']) ? (int)$cache['html_part_count'] : 0;
        $detail['raw_message_bytes'] = is_array($cache) && isset($cache['raw_message_bytes']) ? (int)$cache['raw_message_bytes'] : 0;
        $detail['loose_html_candidate_count'] = is_array($cache) && isset($cache['loose_html_candidate_count']) ? (int)$cache['loose_html_candidate_count'] : 0;
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
     * 한 번 연결한 네이버 세션을 재사용하되 실행시간을 제한하기 위해 최대 10건으로 제한합니다.
     */
    public function cacheUncachedBodiesBatch($limit)
    {
        $limit = max(1, min(10, (int)$limit));
        $keys = PublicMailStorageService::getUncachedMessageKeys($limit);
        if (empty($keys)) return array('cached_count'=>0, 'errors'=>array());
        $settings = $this->requireEnabledSettings();
        $client = $this->createClient($settings);
        $messages = PublicMailStorageService::getMessages();
        $updates = array();
        $done = 0; $errors = array();
        $selectedMailbox = '';
        $batchLock = PublicMailStorageService::acquireLock('body_cache_batch');
        if ($batchLock === false) return array('cached_count'=>0, 'errors'=>array());
        try {
            $client->connect();
            $client->login($settings['username'], $settings['password']);
            foreach ($keys as $key) {
                try {
                    if (!isset($messages[$key]) || !is_array($messages[$key])) throw new \RuntimeException('저장된 메일 정보를 찾을 수 없습니다.');
                    $message = $messages[$key];
                    $parsed = PublicMailStorageService::parseMessageKey($key);
                    $mailbox = isset($message['mailbox']) ? (string)$message['mailbox'] : (string)$parsed['mailbox'];
                    if ($selectedMailbox !== $mailbox) {
                        $client->selectMailbox($mailbox);
                        $selectedMailbox = $mailbox;
                    }
                    $cache = $this->buildBodyCache($key, false, $client, $message);
                    $updates[$key] = $this->applyBodyCacheMetadataToMessage($message, $cache);
                    $done++;
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } finally {
            $client->logout();
            PublicMailStorageService::releaseLock($batchLock);
        }
        if (!empty($updates)) {
            $latestMessages = PublicMailStorageService::getMessages();
            foreach ($updates as $key => $messageUpdate) {
                $latestMessages[$key] = isset($latestMessages[$key]) && is_array($latestMessages[$key])
                    ? array_merge($latestMessages[$key], $messageUpdate) : $messageUpdate;
            }
            PublicMailStorageService::saveMessagesCheckpoint($latestMessages);
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

    public function streamAttachment($messageKey,$partId,$consumer,$descriptor=null)
    {
        if (!is_callable($consumer)) throw new \InvalidArgumentException('첨부파일 수신 함수가 올바르지 않습니다.');
        if (!is_array($descriptor)) $descriptor=$this->getAttachmentDescriptor($messageKey,$partId);
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
        $declaredSize=isset($descriptor['size'])?max(0,(int)$descriptor['size']):0;
        $maximumEncodedBytes=$declaredSize>0?min(209715200,max(8388608,$declaredSize*2+1048576)):209715200;
        $offset=0; $requestSize=1048576; $carry=''; $decodedOffset=0;
        try {
            $client->connect(); $client->login($settings['username'],$settings['password']); $client->selectMailbox($mailbox);
            while (true) {
                $encoded=$client->fetchMimePartChunk($uid,$partId,$offset,$requestSize);
                if ($encoded==='') break;
                $encodedLength=strlen($encoded);
                $offset+=$encodedLength;
                if ($offset>$maximumEncodedBytes) throw new \RuntimeException('첨부파일 크기가 안전한 스트리밍 한도를 초과했습니다.');
                if ($encoding==='base64') {
                    $clean=$carry.preg_replace('/\s+/','',$encoded);
                    $usable=strlen($clean)-strlen($clean)%4;
                    $decodeText=substr($clean,0,$usable); $carry=substr($clean,$usable);
                    $decoded=$decodeText!==''?base64_decode($decodeText,true):'';
                    if ($decoded===false) throw new \RuntimeException('파일 Base64 해제에 실패했습니다.');
                } elseif ($encoding==='quoted-printable') {
                    $clean=$carry.$encoded; $carry='';
                    if (preg_match('/(=\r|=[0-9A-Fa-f])$/',$clean,$tailMatch)) {
                        $carry=$tailMatch[1];
                        $clean=substr($clean,0,-strlen($carry));
                    }
                    $decoded=quoted_printable_decode($clean);
                } else $decoded=$encoded;
                if ($decoded!=='') { call_user_func($consumer,$decoded,$decodedOffset,0); $decodedOffset+=strlen($decoded); }

                /* 마지막 조각이 요청 크기보다 작아도 다음 위치를 한 번 더 확인합니다. */
            }
            if ($carry!=='') {
                $decoded=$encoding==='base64'?base64_decode($carry,true):quoted_printable_decode($carry);
                if ($decoded===false) throw new \RuntimeException('첨부파일의 마지막 인코딩을 해제하지 못했습니다.');
                if ($decoded!=='') { call_user_func($consumer,$decoded,$decodedOffset,0); $decodedOffset+=strlen($decoded); }
            }
        } finally { $client->logout(); }
        if ($decodedOffset<=0) throw new \RuntimeException('파일 내용이 비어 있습니다.');
        return array('bytes_streamed'=>$decodedOffset,'encoded_bytes_read'=>$offset);
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


    /**
     * 네이버 원본 제목 재수집 작업을 등록합니다.
     *
     * v1.7.19.1부터는 스마트빌 메일만 한 건씩 처리하고 messages.json은 마지막에 한 번만 저장합니다.
     * 작업 시작 시 작은 대기열을 한 번 만들고, 제목 10건씩 별도 업데이트 파일에 모은 뒤
     * 모든 수집이 끝났을 때 messages.json과 검색 색인을 한 번만 갱신합니다.
     */
    /**
     * 스마트빌에서 보낸 전자세금계산서 메일만 골라 제목을 다시 가져옵니다.
     * 전체 메일을 대상으로 하지 않으므로 일반 메일과 메일 화면 속도에는 영향이 없습니다.
     */
    public function startSmartBillTitleRefresh()
    {
        /* 기존에 수집한 정상 제목은 작은 보정 파일에 그대로 보존합니다. */
        $oldUpdates = PublicMailStorageService::getTitleRefreshUpdates();
        $oldUpdateItems = isset($oldUpdates['items']) && is_array($oldUpdates['items']) ? $oldUpdates['items'] : array();

        $messages = PublicMailStorageService::getMessages();
        $items = array();
        $relatedCount = 0;
        $normalCount = 0;

        foreach ($messages as $messageKey => $message) {
            if (!is_array($message) || !$this->isBusinessOnMessage($message)) continue;
            $relatedCount++;

            $oldSubject = isset($message['subject']) ? (string)$message['subject'] : '';
            if (!$this->isBrokenBusinessOnSubject($oldSubject)) {
                $normalCount++;
                continue;
            }

            $parsed = PublicMailStorageService::parseMessageKey($messageKey);
            $mailbox = isset($message['mailbox']) && trim((string)$message['mailbox']) !== ''
                ? (string)$message['mailbox'] : (string)$parsed['mailbox'];
            $uid = isset($message['uid']) ? (int)$message['uid'] : (int)$parsed['uid'];
            if ($mailbox === '' || $uid <= 0) continue;

            $items[] = array(
                'message_key'=>(string)$messageKey,
                'mailbox'=>$mailbox,
                'uid'=>$uid,
                'old_subject'=>$oldSubject,
                'old_score'=>$this->businessOnSubjectScore($oldSubject),
                'from_text'=>isset($message['from_text']) ? (string)$message['from_text'] : '',
                'from_email'=>isset($message['from_email']) ? (string)$message['from_email'] : ''
            );
        }

        usort($items, function ($a, $b) {
            $am = isset($a['mailbox']) ? (string)$a['mailbox'] : '';
            $bm = isset($b['mailbox']) ? (string)$b['mailbox'] : '';
            $ainbox = strcasecmp($am, 'INBOX') === 0;
            $binbox = strcasecmp($bm, 'INBOX') === 0;
            if ($ainbox && !$binbox) return -1;
            if (!$ainbox && $binbox) return 1;
            $mailboxCompare = strcmp($am, $bm);
            if ($mailboxCompare !== 0) return $mailboxCompare;
            $au = isset($a['uid']) ? (int)$a['uid'] : 0;
            $bu = isset($b['uid']) ? (int)$b['uid'] : 0;
            if ($au === $bu) return 0;
            return $au < $bu ? -1 : 1;
        });

        $queue = PublicMailStorageService::saveTitleRefreshQueue($items);
        /* 기존 정상 제목 후보는 지우지 않습니다. */

        $total = isset($queue['total_count']) ? (int)$queue['total_count'] : count($items);
        $refresh = array(
            'active'=>$total > 0,
            'paused'=>false,
            'cancelled'=>false,
            'status'=>$total > 0 ? 'running' : 'completed',
            'phase'=>$total > 0 ? 'collecting' : 'completed',
            'mode'=>'businesson_broken_only',
            'target_name'=>'비즈니스온·스마트빌 깨진 제목',
            'sender_domain'=>'businesson.co.kr',
            'related_count'=>$relatedCount,
            'broken_count'=>$total,
            'normal_count'=>$normalCount,
            'skipped_count'=>0,
            'started_at'=>date('Y-m-d H:i:s'),
            'finished_at'=>$total > 0 ? '' : date('Y-m-d H:i:s'),
            'last_run_at'=>'',
            'worker_heartbeat_at'=>'',
            'queue_version'=>3,
            'cursor'=>0,
            'merge_cursor'=>0,
            'retry_cursor'=>-1,
            'retry_count'=>0,
            'consecutive_errors'=>0,
            'total_count'=>$total,
            'processed_count'=>0,
            'updated_count'=>count($oldUpdateItems),
            'merged_count'=>count($oldUpdateItems),
            'applied_count'=>count($oldUpdateItems),
            'failed_count'=>0,
            'remaining_count'=>$total,
            'last_batch_count'=>0,
            'current_position'=>-1,
            'current_mailbox'=>'',
            'current_uid'=>0,
            'inflight'=>array(),
            'skipped_items'=>array(),
            'last_error_code'=>'',
            'last_result_reason'=>'',
            'last_old_subject_preview'=>'',
            'last_candidate_subject_preview'=>'',
            'last_message'=>$total > 0
                ? 'mailing@businesson.co.kr 관련 메일 ' . number_format($relatedCount) . '건 중 깨진 제목 ' . number_format($total) . '건만 확인합니다. 정상 제목 ' . number_format($normalCount) . '건은 그대로 유지합니다.'
                : 'mailing@businesson.co.kr 관련 메일 ' . number_format($relatedCount) . '건을 확인했으며, 복구가 필요한 깨진 제목은 없습니다.',
            'last_error'=>''
        );

        $state = PublicMailStorageService::saveSyncState(array(
            'title_normalization'=>array(
                'enabled'=>false,
                'status'=>'disabled_for_speed',
                'last_message'=>'평상시 메일 화면에서는 제목 복구 작업을 실행하지 않습니다.'
            ),
            'metadata_repair'=>array(
                'active'=>false,
                'paused'=>false,
                'cancelled'=>true,
                'status'=>'replaced_by_businesson_refresh',
                'remaining_count'=>0,
                'last_error'=>''
            ),
            'title_refresh'=>$refresh
        ));

        return array('ok'=>true,'message'=>$refresh['last_message'],'state'=>$state);
    }

    /** 이전 화면과 즐겨찾기 주소도 스마트빌 전용 복구로 연결합니다. */
    public function startOriginalTitleRefresh()
    {
        return $this->startSmartBillTitleRefresh();
    }

    public function controlOriginalTitleRefresh($command)
    {
        $command = trim((string)$command);
        $state = PublicMailStorageService::getSyncState();
        $refresh = isset($state['title_refresh']) && is_array($state['title_refresh'])
            ? $state['title_refresh'] : array();

        if ($command === 'pause') {
            $refresh['active'] = true;
            $refresh['paused'] = true;
            $refresh['status'] = 'paused';
            $refresh['last_message'] = '비즈니스온 깨진 제목 복구를 일시중지했습니다.';
        } elseif ($command === 'resume') {
            $queue = PublicMailStorageService::getTitleRefreshQueue();
            if (empty($queue['items'])) return $this->startSmartBillTitleRefresh();

            $refresh['active'] = true;
            $refresh['paused'] = false;
            $refresh['cancelled'] = false;
            if (empty($refresh['phase']) || $refresh['phase'] === 'idle' || $refresh['phase'] === 'completed') {
                $refresh['phase'] = 'collecting';
            }
            $refresh['status'] = $refresh['phase'] === 'merging' ? 'merging' : 'running';
            $refresh['last_error'] = '';
            $refresh['last_error_code'] = '';
            $refresh['consecutive_errors'] = 0;
            $refresh['last_message'] = '비즈니스온 깨진 제목 복구를 다시 시작했습니다.';
        } elseif ($command === 'cancel') {
            /* 수집된 제목은 작은 보정 파일에 그대로 남아 즉시 표시됩니다. */
            $this->mergeCollectedTitleRefreshUpdates(false);
            $refresh = PublicMailStorageService::getSyncState();
            $refresh = isset($refresh['title_refresh']) && is_array($refresh['title_refresh'])
                ? $refresh['title_refresh'] : array();
            $refresh['active'] = false;
            $refresh['paused'] = false;
            $refresh['cancelled'] = true;
            $refresh['status'] = 'cancelled';
            $refresh['phase'] = 'cancelled';
            $refresh['finished_at'] = date('Y-m-d H:i:s');
            $refresh['last_message'] = '비즈니스온 깨진 제목 복구를 취소했습니다. 지금까지 복구된 제목은 적용했습니다.';
        } else {
            throw new \InvalidArgumentException('제목 재수집 명령이 올바르지 않습니다.');
        }

        $state = PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));
        return array('ok'=>true,'message'=>$refresh['last_message'],'state'=>$state);
    }

    /**
     * 설정 화면 전용 초경량 작업자입니다.
     * 한 번에 같은 메일함의 제목 최대 10건만 조회하며 messages.json은 건드리지 않습니다.
     */
    public function processOriginalTitleRefreshWorkerStep($limit)
    {
        /* 스마트빌 대상이 많지 않으므로 반드시 한 번에 한 건만 처리합니다. */
        $limit = 1;
        $lock = PublicMailStorageService::acquireLock('title_refresh_worker');
        if ($lock === false) {
            return array(
                'ok'=>false,
                'retryable'=>true,
                'retry_after'=>3,
                'error_code'=>'worker_busy',
                'message'=>'다른 비즈니스온 제목 작업이 마무리 중입니다. 3초 후 다시 확인합니다.',
                'refresh'=>$this->compactOriginalTitleRefreshState()
            );
        }

        try {
            $state = PublicMailStorageService::getSyncState();
            $refresh = isset($state['title_refresh']) && is_array($state['title_refresh'])
                ? $state['title_refresh'] : array();

            if (empty($refresh['active'])) {
                return array(
                    'ok'=>true,
                    'completed'=>isset($refresh['status']) && $refresh['status'] === 'completed',
                    'message'=>'비즈니스온 깨진 제목 복구가 실행 중이 아닙니다.',
                    'refresh'=>$this->compactOriginalTitleRefreshState($refresh)
                );
            }
            if (!empty($refresh['paused'])) {
                return array(
                    'ok'=>true,
                    'paused'=>true,
                    'message'=>'비즈니스온 깨진 제목 복구가 일시중지되어 있습니다.',
                    'refresh'=>$this->compactOriginalTitleRefreshState($refresh)
                );
            }

            $phase = isset($refresh['phase']) ? (string)$refresh['phase'] : 'collecting';
            if ($phase === 'merging') return $this->mergeCollectedTitleRefreshUpdates(true);

            /*
             * 이전 요청이 PHP/FastCGI 종료로 응답 없이 끊겼더라도 같은 UID를 반복하지 않습니다.
             * 네이버 접속 전에 cursor를 먼저 저장하므로 다음 요청에서는 해당 1건만 실패로 정리합니다.
             */
            if (!empty($refresh['inflight']) && is_array($refresh['inflight'])) {
                $inflight = $refresh['inflight'];
                $skipped = isset($refresh['skipped_items']) && is_array($refresh['skipped_items'])
                    ? $refresh['skipped_items'] : array();
                $skipped[] = array(
                    'position'=>isset($inflight['position']) ? (int)$inflight['position'] : -1,
                    'mailbox'=>isset($inflight['mailbox']) ? (string)$inflight['mailbox'] : '',
                    'uid'=>isset($inflight['uid']) ? (int)$inflight['uid'] : 0,
                    'reason'=>'previous_request_ended_without_response',
                    'failed_at'=>date('Y-m-d H:i:s')
                );
                if (count($skipped) > 50) $skipped = array_slice($skipped, -50);
                $refresh['skipped_items'] = $skipped;
                $refresh['failed_count'] = (int)$refresh['failed_count'] + 1;
                $refresh['skipped_count'] = isset($refresh['skipped_count']) ? (int)$refresh['skipped_count'] + 1 : 1;
                $refresh['inflight'] = array();
                $refresh['current_position'] = -1;
                $refresh['current_mailbox'] = '';
                $refresh['current_uid'] = 0;
                $refresh['last_error_code'] = 'smartbill_mail_skipped_after_empty_response';
                $refresh['last_error'] = '이전 요청에서 서버 응답이 끊긴 비즈니스온 깨진 제목 1건을 건너뛰었습니다.';
                $refresh['last_message'] = '응답을 끊었던 비즈니스온 메일 1건만 자동으로 건너뛰었습니다. 다음 메일부터 계속합니다.';
                $refresh['last_run_at'] = date('Y-m-d H:i:s');
                $refresh['worker_heartbeat_at'] = $refresh['last_run_at'];
                PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));
                return array(
                    'ok'=>true,
                    'retryable'=>false,
                    'completed'=>false,
                    'message'=>$refresh['last_message'],
                    'refresh'=>$this->compactOriginalTitleRefreshState($refresh)
                );
            }

            $queue = PublicMailStorageService::getTitleRefreshQueue();
            $items = isset($queue['items']) && is_array($queue['items']) ? $queue['items'] : array();
            $total = count($items);
            $cursor = isset($refresh['cursor']) ? max(0, (int)$refresh['cursor']) : 0;

            if ($total === 0) {
                $refresh['active'] = false;
                $refresh['paused'] = false;
                $refresh['status'] = 'completed';
                $refresh['phase'] = 'completed';
                $refresh['total_count'] = 0;
                $refresh['remaining_count'] = 0;
                $refresh['finished_at'] = date('Y-m-d H:i:s');
                $refresh['last_message'] = '복구할 비즈니스온 깨진 제목이 없습니다.';
                PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));
                return array('ok'=>true,'completed'=>true,'message'=>$refresh['last_message'],'refresh'=>$this->compactOriginalTitleRefreshState($refresh));
            }

            if ($cursor >= $total) {
                $refresh['phase'] = 'merging';
                $refresh['status'] = 'merging';
                $refresh['remaining_count'] = 0;
                $refresh['last_message'] = '비즈니스온 깨진 제목 확인을 마쳤습니다. 복구된 제목을 메일 목록에 적용합니다.';
                PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));
                return $this->mergeCollectedTitleRefreshUpdates(true);
            }

            $candidate = isset($items[$cursor]) && is_array($items[$cursor]) ? $items[$cursor] : array();
            $messageKey = isset($candidate['message_key']) ? (string)$candidate['message_key'] : '';
            $mailbox = isset($candidate['mailbox']) ? (string)$candidate['mailbox'] : 'INBOX';
            $uid = isset($candidate['uid']) ? (int)$candidate['uid'] : 0;
            $oldSubject = isset($candidate['old_subject']) ? (string)$candidate['old_subject'] : '';

            if ($messageKey === '' || $mailbox === '' || $uid <= 0) {
                $refresh['cursor'] = $cursor + 1;
                $refresh['processed_count'] = (int)$refresh['processed_count'] + 1;
                $refresh['failed_count'] = (int)$refresh['failed_count'] + 1;
                $refresh['remaining_count'] = max(0, $total - (int)$refresh['cursor']);
                $refresh['last_message'] = '식별값이 없는 비즈니스온 메일 1건을 건너뛰었습니다.';
                $refresh['last_run_at'] = date('Y-m-d H:i:s');
                PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));
                return array('ok'=>true,'message'=>$refresh['last_message'],'refresh'=>$this->compactOriginalTitleRefreshState($refresh));
            }

            /* 서버가 중간에 죽어도 다시 같은 메일에 갇히지 않도록 먼저 다음 위치를 저장합니다. */
            $refresh['inflight'] = array(
                'position'=>$cursor,
                'message_key'=>$messageKey,
                'mailbox'=>$mailbox,
                'uid'=>$uid,
                'started_at'=>date('Y-m-d H:i:s')
            );
            $refresh['current_position'] = $cursor;
            $refresh['current_mailbox'] = $mailbox;
            $refresh['current_uid'] = $uid;
            $refresh['cursor'] = $cursor + 1;
            $refresh['processed_count'] = (int)$refresh['processed_count'] + 1;
            $refresh['remaining_count'] = max(0, $total - (int)$refresh['cursor']);
            $refresh['last_batch_count'] = 1;
            $refresh['last_run_at'] = date('Y-m-d H:i:s');
            $refresh['worker_heartbeat_at'] = $refresh['last_run_at'];
            $refresh['status'] = 'running';
            $refresh['phase'] = 'collecting';
            $refresh['last_message'] = '비즈니스온 깨진 제목 ' . number_format($cursor + 1) . ' / ' . number_format($total) . ' 제목을 확인하고 있습니다.';
            PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));

            $settings = $this->requireEnabledSettings();
            $client = $this->createClient($settings, 5);
            $headerText = '';
            try {
                $client->connect();
                $client->login($settings['username'], $settings['password']);
                $client->selectMailbox($mailbox);
                $headerText = $client->fetchSingleSubjectHeader($uid);
            } catch (\Exception $e) {
                $refresh['inflight'] = array();
                $refresh['current_position'] = -1;
                $refresh['current_mailbox'] = '';
                $refresh['current_uid'] = 0;
                $refresh['failed_count'] = (int)$refresh['failed_count'] + 1;
                $refresh['skipped_count'] = isset($refresh['skipped_count']) ? (int)$refresh['skipped_count'] + 1 : 1;
                $refresh['last_error_code'] = 'businesson_single_mail_error';
                $refresh['last_error'] = PublicMailStorageService::sanitizeText($e->getMessage(), 500);
                $refresh['last_message'] = '비즈니스온 원본 제목 1건을 읽지 못해 건너뛰고 다음 메일로 계속합니다.';
                $refresh['last_run_at'] = date('Y-m-d H:i:s');
                PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));
                return array('ok'=>true,'retryable'=>false,'message'=>$refresh['last_message'],'refresh'=>$this->compactOriginalTitleRefreshState($refresh));
            } finally {
                try { $client->logout(); } catch (\Exception $ignored) {}
            }

            $headers = $this->parseHeaders($headerText);
            $rawFreshSubject = isset($headers['subject']) ? (string)$headers['subject'] : '';
            $decodeDetails = $this->decodeSmartBillSubjectDetails($rawFreshSubject);
            $freshSubject = isset($decodeDetails['subject'])
                ? PublicMailStorageService::normalizeMailText((string)$decodeDetails['subject']) : '';

            $updates = PublicMailStorageService::getTitleRefreshUpdates();
            $updateItems = isset($updates['items']) && is_array($updates['items']) ? $updates['items'] : array();
            $oldScore = isset($candidate['old_score']) ? (int)$candidate['old_score'] : $this->businessOnSubjectScore($oldSubject);
            $freshScore = $this->businessOnSubjectScore($freshSubject);
            $oldClearlyBroken = $this->isClearlyBrokenBusinessOnSubject($oldSubject);
            $freshUsable = $this->isUsableBusinessOnSubjectCandidate($freshSubject);
            $freshStillBroken = $this->isClearlyBrokenBusinessOnSubject($freshSubject);

            /*
             * 기존 제목이 사진처럼 명백히 깨졌다면 모호한 점수 차이를 요구하지 않습니다.
             * 후보가 정상 UTF-8이고 한글 또는 전자세금계산서 문구가 확인되면 즉시 교체합니다.
             */
            $isImproved = $freshSubject !== ''
                && $freshSubject !== $oldSubject
                && $freshUsable
                && !$freshStillBroken
                && ($oldClearlyBroken || $freshScore >= ($oldScore + 8));

            $refresh['last_old_subject_preview'] = $this->shortBusinessOnSubjectPreview($oldSubject);
            $refresh['last_candidate_subject_preview'] = $this->shortBusinessOnSubjectPreview($freshSubject);

            if ($isImproved) {
                $updateItems[$messageKey] = array(
                    'subject'=>$freshSubject,
                    'refreshed_at'=>date('Y-m-d H:i:s'),
                    'source'=>'businesson_worker_v17191',
                    'old_score'=>$oldScore,
                    'new_score'=>$freshScore,
                    'old_was_clearly_broken'=>$oldClearlyBroken ? 1 : 0
                );
                PublicMailStorageService::saveTitleRefreshUpdates($updateItems);
                $refresh['updated_count'] = count($updateItems);
                $refresh['last_error_code'] = '';
                $refresh['last_result_reason'] = '복구 완료';
                $refresh['last_error'] = '';
                $refresh['last_message'] = '비즈니스온 깨진 제목 1건을 정상 제목으로 복구했습니다. 누적 ' . number_format(count($updateItems)) . '건입니다.';
            } else {
                $refresh['skipped_count'] = isset($refresh['skipped_count']) ? (int)$refresh['skipped_count'] + 1 : 1;
                $reason = '후보 판정 보류';
                $errorCode = 'businesson_candidate_unusable';
                if (trim($rawFreshSubject) === '') {
                    $reason = '원본 제목 없음';
                    $errorCode = 'businesson_subject_empty';
                } elseif ($freshSubject === '') {
                    $reason = '원본 제목 해석 실패';
                    $errorCode = 'businesson_subject_decode_failed';
                } elseif ($freshSubject === $oldSubject) {
                    $reason = '원본 후보가 기존 깨진 제목과 동일';
                    $errorCode = 'businesson_candidate_same';
                } elseif ($freshStillBroken) {
                    $reason = '원본 후보도 깨진 문자로 해석됨';
                    $errorCode = 'businesson_candidate_still_broken';
                } elseif (!$freshUsable) {
                    $reason = '후보를 정상 한글 제목으로 확인하지 못함';
                    $errorCode = 'businesson_candidate_unusable';
                }
                $refresh['last_error_code'] = $errorCode;
                $refresh['last_result_reason'] = $reason;
                $refresh['last_error'] = "결과: " . $reason
                    . "\n기존: " . $refresh['last_old_subject_preview']
                    . "\n후보: " . ($refresh['last_candidate_subject_preview'] !== '' ? $refresh['last_candidate_subject_preview'] : '(없음)');
                $refresh['last_message'] = $reason . '으로 1건은 기존 제목을 유지하고 다음 메일로 계속합니다.';
            }

            $refresh['inflight'] = array();
            $refresh['current_position'] = -1;
            $refresh['current_mailbox'] = '';
            $refresh['current_uid'] = 0;
            $refresh['last_run_at'] = date('Y-m-d H:i:s');
            $refresh['worker_heartbeat_at'] = $refresh['last_run_at'];

            if ((int)$refresh['cursor'] >= $total) {
                $refresh['phase'] = 'merging';
                $refresh['status'] = 'merging';
                $refresh['last_message'] = '비즈니스온 깨진 제목 확인을 마쳤습니다. 복구된 제목을 한 번만 적용합니다.';
            }

            PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));
            return array('ok'=>true,'retryable'=>false,'completed'=>false,'message'=>$refresh['last_message'],'refresh'=>$this->compactOriginalTitleRefreshState($refresh));
        } finally {
            PublicMailStorageService::releaseLock($lock);
        }
    }

    private function handleOriginalTitleRefreshWorkerError($refresh, $cursor, $total, $exception)
    {
        $sameRetry = isset($refresh['retry_cursor']) && (int)$refresh['retry_cursor'] === (int)$cursor;
        $attempt = $sameRetry ? ((int)$refresh['retry_count'] + 1) : 1;
        $message = PublicMailStorageService::sanitizeText($exception->getMessage(), 500);

        if ($attempt >= 2) {
            $refresh['cursor'] = (int)$cursor + 1;
            $refresh['processed_count'] = (int)$refresh['processed_count'] + 1;
            $refresh['failed_count'] = (int)$refresh['failed_count'] + 1;
            $refresh['remaining_count'] = max(0, (int)$total - (int)$refresh['cursor']);
            $refresh['retry_cursor'] = -1;
            $refresh['retry_count'] = 0;
            $refresh['consecutive_errors'] = 0;
            $refresh['status'] = 'running';
            $refresh['phase'] = 'collecting';
            $refresh['last_error_code'] = 'mail_skipped_after_retry';
            $refresh['last_error'] = $message;
            $refresh['last_message'] = '같은 제목을 두 번 읽지 못해 1건만 건너뛰고 다음 제목으로 계속 진행합니다.';
            $refresh['last_run_at'] = date('Y-m-d H:i:s');
            $refresh['worker_heartbeat_at'] = $refresh['last_run_at'];
            PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));

            return array(
                'ok'=>true,
                'retryable'=>false,
                'message'=>$refresh['last_message'],
                'refresh'=>$this->compactOriginalTitleRefreshState($refresh)
            );
        }

        $refresh['retry_cursor'] = (int)$cursor;
        $refresh['retry_count'] = $attempt;
        $refresh['consecutive_errors'] = (int)$refresh['consecutive_errors'] + 1;
        $refresh['status'] = 'retrying';
        $refresh['phase'] = 'collecting';
        $refresh['last_error_code'] = 'imap_temporary_error';
        $refresh['last_error'] = $message;
        $refresh['last_message'] = '네이버 연결이 잠시 끊겼습니다. 저장된 위치부터 10초 후 자동으로 다시 시도합니다.';
        $refresh['last_run_at'] = date('Y-m-d H:i:s');
        $refresh['worker_heartbeat_at'] = $refresh['last_run_at'];
        PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));

        return array(
            'ok'=>false,
            'retryable'=>true,
            'retry_after'=>10,
            'error_code'=>'imap_temporary_error',
            'message'=>$refresh['last_message'],
            'refresh'=>$this->compactOriginalTitleRefreshState($refresh)
        );
    }

    /**
     * 네이버 연결 없이 로컬 제목 업데이트만 합칩니다.
     * 200건 단위로 메모리에서 적용하되 messages.json은 마지막에 한 번만 저장합니다.
     */
    /**
     * v1.7.19.1: 정상 제목은 title_refresh_updates.json에서 즉시 목록·상세에 덮어씁니다.
     * messages.json 5,559건 전체 저장과 mail_index.json 전체 재생성은 하지 않습니다.
     */
    private function mergeCollectedTitleRefreshUpdates($markCompleted)
    {
        $state = PublicMailStorageService::getSyncState();
        $refresh = isset($state['title_refresh']) && is_array($state['title_refresh']) ? $state['title_refresh'] : array();
        $updates = PublicMailStorageService::getTitleRefreshUpdates();
        $updateItems = isset($updates['items']) && is_array($updates['items']) ? $updates['items'] : array();
        $applied = 0;
        foreach ($updateItems as $item) {
            if (is_array($item) && isset($item['subject']) && trim((string)$item['subject']) !== '') $applied++;
        }
        $refresh['updated_count'] = $applied;
        $refresh['merged_count'] = $applied;
        $refresh['applied_count'] = $applied;
        $refresh['last_run_at'] = date('Y-m-d H:i:s');
        $refresh['worker_heartbeat_at'] = $refresh['last_run_at'];
        $refresh['last_error'] = '';
        $refresh['last_error_code'] = '';
        $refresh['retry_cursor'] = -1;
        $refresh['retry_count'] = 0;
        $refresh['consecutive_errors'] = 0;

        if ($markCompleted) {
            $refresh['active'] = false;
            $refresh['paused'] = false;
            $refresh['status'] = 'completed';
            $refresh['phase'] = 'completed';
            $refresh['remaining_count'] = 0;
            $refresh['finished_at'] = date('Y-m-d H:i:s');
            $refresh['last_message'] = '비즈니스온 깨진 제목 복구를 완료했습니다. 정상 제목 ' . number_format($applied) . '건은 작은 보정 파일에서 메일 목록과 상세화면에 즉시 적용됩니다.';
        } else {
            $refresh['last_message'] = '지금까지 확보한 정상 제목 ' . number_format($applied) . '건을 메일 화면에 즉시 적용했습니다.';
        }
        PublicMailStorageService::saveSyncState(array('title_refresh'=>$refresh));
        return array('ok'=>true,'completed'=>(bool)$markCompleted,'retryable'=>false,'message'=>$refresh['last_message'],'refresh'=>$this->compactOriginalTitleRefreshState($refresh));
    }

    private function compactOriginalTitleRefreshState($refresh = null)
    {
        if (!is_array($refresh)) {
            $state = PublicMailStorageService::getSyncState();
            $refresh = isset($state['title_refresh']) && is_array($state['title_refresh'])
                ? $state['title_refresh'] : array();
        }

        $fields = array(
            'active','paused','cancelled','status','phase','started_at','finished_at',
            'last_run_at','worker_heartbeat_at','cursor','total_count','processed_count',
            'updated_count','merged_count','applied_count','failed_count','remaining_count','last_batch_count',
            'retry_count','mode','target_name','sender_domain','related_count','broken_count','normal_count','skipped_count','current_position','current_mailbox','current_uid',
            'inflight','skipped_items','last_error_code','last_result_reason',
            'last_old_subject_preview','last_candidate_subject_preview','last_message','last_error'
        );
        $result = array();
        foreach ($fields as $field) {
            if (array_key_exists($field, $refresh)) $result[$field] = $refresh[$field];
        }
        return $result;
    }

    /**
     * v1.7.12 구형 일반 POST 처리 호환용입니다.
     * 오래된 캐시 화면이 호출해도 새 초경량 작업자 10건만 실행합니다.
     */
    public function processOriginalTitleRefreshBatch($limit)
    {
        return $this->processOriginalTitleRefreshWorkerStep(min(10, max(1, (int)$limit)));
    }

    /** v1.7.11의 구형 버튼이 남아 있어도 새 원본 제목 재수집을 시작합니다. */
    public function normalizeStoredMailTitles()
    {
        return $this->startSmartBillTitleRefresh();
    }

    /** 구형 전체 자동복구 상태를 안전하게 종료합니다. */
    public function disableLegacyMetadataRepair()
    {
        $state = PublicMailStorageService::saveSyncState(array('metadata_repair'=>array(
            'active'=>false,'paused'=>false,'cancelled'=>true,'status'=>'replaced_by_smartbill_refresh',
            'remaining_count'=>0,'target_keys'=>array(),'message_attempts'=>array(),'last_error'=>'',
            'last_message'=>'구형 전체 자동복구를 종료했습니다. 기존 제목은 원본 제목 재수집에서 처리합니다.'
        )));
        return array('ok'=>true,'message'=>'구형 전체 자동복구를 종료했습니다.','state'=>$state);
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
        $hasHtmlBody = !empty($htmlBodies);
        if ($bodyHtml === '' && $bodyText !== '') $bodyHtml = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
        if ($bodyText === '' && $bodyHtml !== '') $bodyText = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8'));
        $rootHeaders = isset($root['headers']) && is_array($root['headers']) ? $root['headers'] : array();
        $fromText = $this->decodeHeader(isset($rootHeaders['from']) ? $rootHeaders['from'] : '');
        $fromEmail = $this->extractEmail($fromText);
        $rawSubject = isset($rootHeaders['subject']) ? $rootHeaders['subject'] : '';
        $decodedSubject = $this->isBusinessOnSenderText($fromText, $fromEmail)
            ? $this->decodeSmartBillSubject($rawSubject) : $this->decodeHeader($rawSubject);
        return array(
            'body_text'=>$bodyText,'body_html_raw'=>$bodyHtml,'has_html_body'=>$hasHtmlBody,'body_html'=>$this->sanitizeHtml($bodyHtml),'attachments'=>$attachments,'headers'=>$rootHeaders,
            'subject'=>$decodedSubject,
            'from_text'=>$fromText,'from_email'=>$fromEmail,
            'to_text'=>$this->decodeHeader(isset($rootHeaders['to'])?$rootHeaders['to']:''),
            'cc_text'=>$this->decodeHeader(isset($rootHeaders['cc'])?$rootHeaders['cc']:'')
        );
    }

    /**
     * MIME BODYSTRUCTURE를 이용해 본문과 첨부 메타정보만 가져옵니다.
     * 첨부파일 원본은 다운로드하지 않으므로 큰 메일도 상세화면을 빠르게 준비할 수 있습니다.
     */
    private function buildBodyCache($messageKey, $force, $activeClient = null, $messageOverride = null)
    {
        $messageKey = trim((string)$messageKey);
        if (!$force) {
            $cached = PublicMailStorageService::getBodyCache($messageKey);
            if (is_array($cached)) return $cached;
        }
        $persistMessage = !is_array($messageOverride);
        $messages = array();
        if ($persistMessage) {
            $messages = PublicMailStorageService::getMessages();
            if (!isset($messages[$messageKey]) || !is_array($messages[$messageKey])) throw new \RuntimeException('저장된 메일 정보를 찾을 수 없습니다.');
            $message = $messages[$messageKey];
        } else {
            $message = $messageOverride;
        }
        $parsedKey = PublicMailStorageService::parseMessageKey($messageKey);
        $mailbox = isset($message['mailbox']) ? (string)$message['mailbox'] : (string)$parsedKey['mailbox'];
        $uid = isset($message['uid']) ? (int)$message['uid'] : (int)$parsedKey['uid'];
        if ($uid <= 0) throw new \RuntimeException('메일 UID가 올바르지 않습니다.');

        $ownsClient = !is_object($activeClient);
        if ($ownsClient) {
            $settings = $this->requireEnabledSettings();
            $client = $this->createClient($settings);
        } else {
            $client = $activeClient;
        }
        $bodyHtmlRaw = ''; $bodyText = ''; $attachments = array(); $inlineImages = array();
        $rawFallbackAttempted = false;
        $bodyHtmlSource = '';
        $htmlPartCount = 0;
        $rawMessageBytes = 0;
        $looseHtmlCandidateCount = 0;
        $messageSize = isset($message['size']) ? (int)$message['size'] : 0;
        try {
            if ($ownsClient) {
                $client->connect();
                $client->login($settings['username'], $settings['password']);
                $client->selectMailbox($mailbox);
            }
            if ($messageSize <= 0) {
                $headerData = $client->fetchHeader($uid);
                $messageSize = isset($headerData['size']) ? (int)$headerData['size'] : 0;
            }

            try {
                $structureText = $client->fetchBodyStructure($uid);
                $structure = $this->parseBodyStructure($structureText);
                $parts = array();
                $this->flattenBodyStructure($structure, '', $parts);
                $htmlParts = array(); $textParts = array();
                foreach ($parts as $part) {
                    if (!is_array($part)) continue;
                    $mime = isset($part['mime_type']) ? strtolower((string)$part['mime_type']) : '';
                    /* 네이버는 disposition과 관계없이 표시 가능한 HTML 파트를 본문으로 사용합니다. */
                    $partFilename = isset($part['filename']) ? (string)$part['filename'] : '';
                    if ($this->isHtmlMimeCandidate($mime, $partFilename)) $htmlParts[] = $part;
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
                    if ($mime === 'text/plain') $textParts[] = $part;
                }
                /*
                 * 일부 메일은 첫 번째 text 파트를 빈 자리표시자로 두고 실제 본문을 뒤 파트에 둡니다.
                 * 첫 파트만 고정 선택하면 BODYSTRUCTURE 조회는 성공해도 빈 본문 캐시가 만들어집니다.
                */
                foreach (array_slice($htmlParts, 0, 5) as $htmlPart) {
                    $candidateHtml = $this->fetchTextBodyPart($client, $uid, $htmlPart);
                    if ($this->mailHtmlHasRenderableContent($candidateHtml)) {
                        $bodyHtmlRaw = $candidateHtml;
                        $bodyHtmlSource = 'bodystructure_html';
                        break;
                    }
                }
                $htmlPartCount = count($htmlParts);
                if (!$this->mailHtmlHasRenderableContent($bodyHtmlRaw)) {
                    foreach (array_slice($textParts, 0, 5) as $textPart) {
                        $candidateText = $this->fetchTextBodyPart($client, $uid, $textPart);
                        if (trim($candidateText) !== '') { $bodyText = $candidateText; break; }
                    }
                }
            } catch (\Exception $structureError) {
                // 아래의 원문 앞부분 대체 조회에서 다시 해석합니다.
            }

            /*
             * BODYSTRUCTURE 해석이 예외 없이 끝났더라도 본문 파트를 찾지 못하거나 빈 파트를
             * 선택할 수 있습니다. 이 경우에도 원문 앞부분을 읽어야 빈 캐시가 고착되지 않습니다.
             */
            if (!$this->mailBodyHasContent($bodyHtmlRaw, $bodyText)) {
                $rawFallbackAttempted = true;
                $previewRaw = '';
                if ($messageSize > 0 && $messageSize <= 33554432) {
                    try {
                        $previewRaw = $client->fetchRawMessage($uid, $messageSize + 262144);
                    } catch (\Exception $fullRawError) {
                        $previewRaw = '';
                    }
                }
                if ($previewRaw === '') $previewRaw = $client->fetchRawPreview($uid, 262144);
                if ($previewRaw !== '') {
                    $rawMessageBytes = strlen($previewRaw);
                    $fallback = $this->parseRawMessage($previewRaw, false);
                    $fallbackHtml = $this->extractHtmlBodyLoosely($previewRaw, $looseHtmlCandidateCount);
                    $fallbackHtmlSource = $this->mailHtmlHasRenderableContent($fallbackHtml) ? 'raw_loose_html' : '';
                    if (!$this->mailHtmlHasRenderableContent($fallbackHtml)) {
                        $fallbackHtml = $this->extractHtmlBodyFromRaw($previewRaw);
                        if ($this->mailHtmlHasRenderableContent($fallbackHtml)) $fallbackHtmlSource = 'raw_html';
                    }
                    if (!$this->mailHtmlHasRenderableContent($fallbackHtml)) {
                        $fallbackHtml = !empty($fallback['has_html_body']) && isset($fallback['body_html_raw'])
                            ? (string)$fallback['body_html_raw'] : '';
                        if ($this->mailHtmlHasRenderableContent($fallbackHtml)) $fallbackHtmlSource = 'parsed_raw_html';
                    }
                    if ($this->mailHtmlHasRenderableContent($fallbackHtml)) {
                        $bodyHtmlRaw = $fallbackHtml;
                        $bodyHtmlSource = $fallbackHtmlSource;
                    }
                    if (trim($bodyText) === '' && isset($fallback['body_text'])) {
                        $bodyText = (string)$fallback['body_text'];
                    }
                    if (empty($attachments) && isset($fallback['attachments']) && is_array($fallback['attachments'])) {
                        $attachments = $fallback['attachments'];
                    }
                }
            }
        } finally {
            if ($ownsClient) $client->logout();
        }

        /* 목록 미리보기가 있는데 이번 원문 조회만 비었다면 일시 오류이므로 빈 캐시로 확정하지 않습니다. */
        if (!$this->mailBodyHasContent($bodyHtmlRaw, $bodyText)
            && isset($message['preview']) && trim((string)$message['preview']) !== '') {
            throw new \RuntimeException('메일 원문을 끝까지 읽지 못했습니다. 잠시 후 다시 시도하세요.');
        }

        $bodyText = $this->repairMojibake($bodyText);
        if ($bodyText === '' && $bodyHtmlRaw !== '') $bodyText = $this->htmlBodyToReadableText($bodyHtmlRaw);
        if (!$this->mailHtmlHasRenderableContent($bodyHtmlRaw) && $bodyText !== '') {
            $bodyHtmlRaw = '<div class="pm-plain-mail">' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</div>';
            $bodyHtmlSource = 'text_fallback';
        }

        $inlineMap = array();
        foreach ($inlineImages as $image) {
            $cid = isset($image['content_id']) ? $this->normalizeContentId($image['content_id']) : '';
            if ($cid !== '') $inlineMap[$cid] = $image;
        }
        $bodyDocumentHtml = $this->buildMailBodyDocument($bodyHtmlRaw, $bodyText, $messageKey, $inlineMap);
        $externalCount = 0;
        $bodyHtml = $this->sanitizeHtml($bodyHtmlRaw, $messageKey, $inlineMap, $externalCount);
        if ($bodyHtml === '' && $bodyText !== '') $bodyHtml = '<div class="pm-plain-mail">' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</div>';

        $large = $this->largeAttachmentService->extractFromBody($bodyHtmlRaw, $bodyText);
        foreach ($large as $largeAttachment) $attachments[] = $largeAttachment;

        $cache = array(
            'body_html'=>$bodyHtml,
            'body_text'=>$bodyText,
            'body_document_html'=>$bodyDocumentHtml,
            'attachments'=>$attachments,
            'inline_images'=>$inlineImages,
            'external_image_count'=>(int)$externalCount,
            'source'=>$rawFallbackAttempted ? 'imap_bodystructure_v2+raw_fallback' : 'imap_bodystructure_v2',
            'body_html_source'=>$bodyHtmlSource,
            'body_html_bytes'=>strlen($bodyHtmlRaw),
            'body_text_bytes'=>strlen($bodyText),
            'html_part_count'=>(int)$htmlPartCount,
            'raw_message_bytes'=>(int)$rawMessageBytes,
            'loose_html_candidate_count'=>(int)$looseHtmlCandidateCount,
            'body_empty_confirmed'=>(!$this->mailBodyHasContent($bodyHtml, $bodyText) && $rawFallbackAttempted),
            'mailbox'=>$mailbox,
            'uid'=>$uid
        );
        $cache = PublicMailStorageService::saveBodyCache($messageKey, $cache);
        if (!$persistMessage) return $cache;

        // IMAP 조회 중 새 메일 동기화가 끝났다면 최신 목록을 다시 합쳐 오래된 스냅샷으로 덮어쓰지 않습니다.
        $latestMessages = PublicMailStorageService::getMessages();
        if (isset($latestMessages[$messageKey]) && is_array($latestMessages[$messageKey])) $messages = $latestMessages;
        $messages[$messageKey] = $this->applyBodyCacheMetadataToMessage($messages[$messageKey], $cache);
        // 본문 열람 경로에서는 목록 색인 전체 재생성을 생략하여 응답을 즉시 반환합니다.
        // 목록 색인은 다음 메일 동기화 때 갱신되고, 본문/첨부 정보는 위 캐시에서 바로 사용합니다.
        PublicMailStorageService::saveMessagesCheckpoint($messages);
        return $cache;
    }

    private function applyBodyCacheMetadataToMessage($message, $cache)
    {
        if (!is_array($message)) $message = array();
        if (!is_array($cache)) return $message;
        $bodyText = isset($cache['body_text']) ? (string)$cache['body_text'] : '';
        $bodyHtml = isset($cache['body_html']) ? (string)$cache['body_html'] : '';
        $preview = $this->makePreviewText($bodyText !== '' ? $bodyText : strip_tags($bodyHtml));
        if ($preview !== '') $message['preview'] = $preview;
        $attachments = isset($cache['attachments']) && is_array($cache['attachments']) ? $cache['attachments'] : array();
        $message['body_cached'] = true;
        $message['body_cache_pending'] = false;
        $message['body_cache_error'] = '';
        $message['body_cache_version'] = PublicMailStorageService::BODY_CACHE_VERSION;
        $message['body_cache_updated_at'] = isset($cache['cached_at']) ? (string)$cache['cached_at'] : date('Y-m-d H:i:s');
        $message['has_attachment'] = !empty($attachments);
        $message['large_attachments'] = array();
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || empty($attachment['large_id'])) continue;
            $message['large_attachments'][(string)$attachment['large_id']] = $attachment;
        }
        return $message;
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

    private function mailBodyHasContent($bodyHtml, $bodyText)
    {
        if (trim((string)$bodyText) !== '') return true;
        return $this->mailHtmlHasRenderableContent($bodyHtml);
    }

    private function mailHtmlHasRenderableContent($bodyHtml)
    {
        $bodyHtml = trim((string)$bodyHtml);
        if ($bodyHtml === '') return false;
        /* head/style의 CSS 문자열을 본문 내용으로 오인하지 않습니다. */
        $visibleHtml = preg_replace('#<(head|style|script|noscript|template|title)[^>]*>.*?</\1>#is', '', $bodyHtml);
        $visibleText = html_entity_decode(strip_tags($visibleHtml), ENT_QUOTES, 'UTF-8');
        if (trim(preg_replace('/\s+/u', ' ', $visibleText)) !== '') return true;
        /* 이미지 또는 외부 프레임 한 개로 구성된 안내메일도 정상 본문으로 봅니다. */
        return preg_match('/<(?:img|iframe|frame)\b/i', $visibleHtml) === 1;
    }

    private function isHtmlMimeCandidate($mimeType, $filename)
    {
        $mimeType = strtolower(trim((string)$mimeType));
        if (in_array($mimeType, array('text/html','text/x-html','application/xhtml+xml'), true)) return true;
        $filename = strtolower(trim((string)$filename));
        return $filename !== '' && preg_match('/\.x?html?$/i', $filename) === 1;
    }

    /** 표준 MIME 트리 해석이 실패해도 원문에서 HTML 섹션을 직접 찾아 복구합니다. */
    private function extractHtmlBodyLoosely($raw, &$candidateCount)
    {
        $raw = (string)$raw;
        $candidateCount = 0;
        if ($raw === '') return '';
        $pattern = '/(?:^|\r?\n)Content-Type\s*:\s*(?:text\/(?:x-)?html|application\/xhtml\+xml)\b/i';
        $matches = array();
        if (!preg_match_all($pattern, $raw, $matches, PREG_OFFSET_CAPTURE) || empty($matches[0])) return '';
        $candidates = array();
        $rawLength = strlen($raw);
        foreach ($matches[0] as $contentTypeMatch) {
            $headerStart = isset($contentTypeMatch[1]) ? (int)$contentTypeMatch[1] : 0;
            $crlfEnd = strpos($raw, "\r\n\r\n", $headerStart);
            $lfEnd = strpos($raw, "\n\n", $headerStart);
            if ($crlfEnd === false && $lfEnd === false) continue;
            if ($crlfEnd !== false && ($lfEnd === false || $crlfEnd <= $lfEnd)) {
                $headerEnd = $crlfEnd;
                $bodyStart = $crlfEnd + 4;
            } else {
                $headerEnd = $lfEnd;
                $bodyStart = $lfEnd + 2;
            }
            if ($headerEnd - $headerStart > 65536) continue;
            $headers = substr($raw, $headerStart, $headerEnd - $headerStart);
            $bodyEnd = $rawLength;
            $boundaryMatch = array();
            if (preg_match('/\r?\n--[A-Za-z0-9\'()+_,.\/:=?-]{6,}(?:--)?[ \t]*(?:\r?\n|$)/', $raw, $boundaryMatch, PREG_OFFSET_CAPTURE, $bodyStart)) {
                $bodyEnd = isset($boundaryMatch[0][1]) ? (int)$boundaryMatch[0][1] : $rawLength;
            }
            if ($bodyEnd <= $bodyStart) continue;
            $encodedBody = substr($raw, $bodyStart, $bodyEnd - $bodyStart);
            $encoding = '';
            if (preg_match('/Content-Transfer-Encoding\s*:\s*([^\r\n]+)/i', $headers, $encodingMatch)) {
                $encoding = strtolower(trim((string)$encodingMatch[1]));
            }
            $decodedBody = $this->decodeBody($encodedBody, $encoding);
            if ($decodedBody === '') continue;
            $charset = '';
            if (preg_match('/charset\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^;\s\r\n]+))/i', $headers, $charsetMatch)) {
                if (!empty($charsetMatch[1])) $charset = $charsetMatch[1];
                elseif (!empty($charsetMatch[2])) $charset = $charsetMatch[2];
                elseif (!empty($charsetMatch[3])) $charset = $charsetMatch[3];
            }
            $html = $this->convertToUtf8($decodedBody, $charset);
            if (!$this->mailHtmlHasRenderableContent($html)) continue;
            $candidateCount++;
            $score = 100 + min(50, (int)(strlen($html) / 2048));
            if (preg_match('/<(?:html|body)\b/i', $html)) $score += 40;
            if (preg_match('/<table\b/i', $html)) $score += 30;
            if (preg_match('/<img\b/i', $html)) $score += 20;
            $candidates[] = array('score'=>$score, 'html'=>$html);
        }
        if (empty($candidates)) return '';
        usort($candidates, array($this, 'compareRawHtmlCandidateDesc'));
        return isset($candidates[0]['html']) ? (string)$candidates[0]['html'] : '';
    }

    /** MIME 선언이 잘못된 발송메일에서도 실제 HTML 마크업을 찾아 복구합니다. */
    private function extractHtmlBodyFromRaw($raw)
    {
        $root = $this->parseMimeEntity((string)$raw, '1', true);
        $candidates = array();
        $this->collectRawHtmlCandidates($root, $candidates);
        if (empty($candidates)) return '';
        usort($candidates, array($this, 'compareRawHtmlCandidateDesc'));
        return isset($candidates[0]['html']) ? (string)$candidates[0]['html'] : '';
    }

    private function collectRawHtmlCandidates($entity, &$candidates)
    {
        if (!is_array($entity)) return;
        if (!empty($entity['children']) && is_array($entity['children'])) {
            foreach ($entity['children'] as $child) $this->collectRawHtmlCandidates($child, $candidates);
            return;
        }
        $content = isset($entity['content']) ? (string)$entity['content'] : '';
        if ($content === '') return;
        $charset = isset($entity['charset']) ? (string)$entity['charset'] : '';
        $content = $this->convertToUtf8($content, $charset);
        if (!preg_match('/<(?:!doctype\s+html|html|body|table|img|style|div)\b/i', $content)) return;
        if (!$this->mailHtmlHasRenderableContent($content)) return;
        $mime = isset($entity['mime_type']) ? strtolower((string)$entity['mime_type']) : '';
        $filename = isset($entity['filename']) ? (string)$entity['filename'] : '';
        $score = $this->isHtmlMimeCandidate($mime, $filename) ? 100 : 0;
        if (preg_match('/<(?:html|body)\b/i', $content)) $score += 40;
        if (preg_match('/<table\b/i', $content)) $score += 25;
        if (preg_match('/<img\b/i', $content)) $score += 20;
        if (preg_match('/<style\b/i', $content)) $score += 10;
        $score += min(30, (int)(strlen($content) / 2048));
        $candidates[] = array('score'=>$score, 'html'=>$content);
    }

    public function compareRawHtmlCandidateDesc($left, $right)
    {
        $leftScore = isset($left['score']) ? (int)$left['score'] : 0;
        $rightScore = isset($right['score']) ? (int)$right['score'] : 0;
        if ($leftScore === $rightScore) return 0;
        return $leftScore > $rightScore ? -1 : 1;
    }

    private function htmlBodyToReadableText($bodyHtml)
    {
        $text = $this->ensureUtf8((string)$bodyHtml, '');
        $text = preg_replace('#<(style|script|noscript|template|title)[^>]*>.*?</\1>#is', '', $text);
        $text = preg_replace('#<(br|hr)\b[^>]*>#i', "\n", $text);
        $text = preg_replace('#</(p|div|li|tr|h[1-6]|blockquote|pre)>#i', "\n", $text);
        $text = preg_replace('#</(td|th)>#i', "\t", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($this->repairMojibake($text));
    }

    /**
     * 네이버에서 받은 메일 HTML의 표·색상·CSS를 유지한 표시 전용 문서를 만듭니다.
     * 실행 가능한 요소만 제거하고, 실제 출력은 sandbox iframe 안에서 수행합니다.
     */
    private function buildMailBodyDocument($bodyHtml, $bodyText, $messageKey, $inlineMap)
    {
        $bodyHtml = $this->ensureUtf8((string)$bodyHtml, '');
        if (trim($bodyHtml) === '') {
            $text = trim((string)$bodyText);
            if ($text === '') $text = '표시할 메일 본문이 없습니다.';
            return '<!doctype html><html><head><meta charset="UTF-8"><meta http-equiv="Content-Security-Policy" content="'
                . htmlspecialchars($this->mailBodyContentSecurityPolicy(), ENT_QUOTES, 'UTF-8')
                . '"></head><body><div style="white-space:pre-wrap">'
                . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div></body></html>';
        }

        if (!class_exists('DOMDocument')) {
            return $this->buildMailBodyDocumentFallback($bodyHtml, $bodyText, $messageKey, $inlineMap);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $bodyHtml);
        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return $this->buildMailBodyDocumentFallback($bodyHtml, $bodyText, $messageKey, $inlineMap);
        }

        $documentChildren = array();
        for ($documentIndex = 0; $documentIndex < $dom->childNodes->length; $documentIndex++) {
            $documentChildren[] = $dom->childNodes->item($documentIndex);
        }
        foreach ($documentChildren as $documentChild) {
            if ($documentChild && $documentChild->nodeType === XML_PI_NODE && $documentChild->parentNode) {
                $documentChild->parentNode->removeChild($documentChild);
            }
        }

        foreach (array('script','object','embed','applet') as $tagName) {
            $nodes = array();
            foreach ($dom->getElementsByTagName($tagName) as $node) $nodes[] = $node;
            foreach ($nodes as $node) if ($node->parentNode) $node->parentNode->removeChild($node);
        }

        /* 폼 자체만 제거하고 내부 안내 문구와 표는 그대로 남깁니다. */
        $forms = array();
        foreach ($dom->getElementsByTagName('form') as $formNode) $forms[] = $formNode;
        foreach ($forms as $formNode) {
            if (!$formNode->parentNode) continue;
            while ($formNode->firstChild) $formNode->parentNode->insertBefore($formNode->firstChild, $formNode);
            $formNode->parentNode->removeChild($formNode);
        }
        foreach (array('input','button','textarea','select','option') as $tagName) {
            $nodes = array();
            foreach ($dom->getElementsByTagName($tagName) as $node) $nodes[] = $node;
            foreach ($nodes as $node) if ($node->parentNode) $node->parentNode->removeChild($node);
        }

        $allElements = array();
        foreach ($dom->getElementsByTagName('*') as $element) $allElements[] = $element;
        foreach ($allElements as $element) {
            if (!$element->hasAttributes()) continue;
            $attributeNames = array();
            foreach ($element->attributes as $attribute) $attributeNames[] = $attribute->name;
            foreach ($attributeNames as $attributeName) {
                $lowerName = strtolower((string)$attributeName);
                if (strpos($lowerName, 'on') === 0 || in_array($lowerName, array('srcdoc','formaction','action'), true)) {
                    $element->removeAttribute($attributeName);
                } elseif ($lowerName === 'style') {
                    $element->setAttribute($attributeName, $this->sanitizeMailDocumentCss(
                        (string)$element->getAttribute($attributeName), $messageKey, $inlineMap
                    ));
                } elseif ($lowerName === 'background') {
                    $background = trim((string)$element->getAttribute($attributeName));
                    if (stripos($background, 'cid:') === 0) {
                        $backgroundCid = $this->normalizeContentId($background);
                        if (isset($inlineMap[$backgroundCid]) && !empty($inlineMap[$backgroundCid]['part_id'])) {
                            $element->setAttribute($attributeName, 'public_mail_action.php?action=inline_image&message_key=' . rawurlencode($messageKey)
                                . '&part_id=' . rawurlencode((string)$inlineMap[$backgroundCid]['part_id']));
                        } else {
                            $element->removeAttribute($attributeName);
                        }
                    }
                }
            }
        }

        $bases = array();
        foreach ($dom->getElementsByTagName('base') as $baseNode) $bases[] = $baseNode;
        foreach ($bases as $baseNode) if ($baseNode->parentNode) $baseNode->parentNode->removeChild($baseNode);
        $metas = array();
        foreach ($dom->getElementsByTagName('meta') as $metaNode) $metas[] = $metaNode;
        foreach ($metas as $metaNode) {
            $httpEquiv = strtolower(trim((string)$metaNode->getAttribute('http-equiv')));
            if ($metaNode->hasAttribute('charset') || in_array($httpEquiv, array('refresh','content-security-policy','content-type'), true)) {
                if ($metaNode->parentNode) $metaNode->parentNode->removeChild($metaNode);
            }
        }

        foreach ($dom->getElementsByTagName('style') as $styleNode) {
            $styleNode->nodeValue = $this->sanitizeMailDocumentCss(
                (string)$styleNode->nodeValue, $messageKey, $inlineMap
            );
        }

        foreach ($dom->getElementsByTagName('a') as $linkNode) {
            $href = trim((string)$linkNode->getAttribute('href'));
            if (!$this->isSafeMailLink($href)) $linkNode->removeAttribute('href');
            else {
                $linkNode->setAttribute('target', '_blank');
                $linkNode->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }

        $links = array();
        foreach ($dom->getElementsByTagName('link') as $linkNode) $links[] = $linkNode;
        foreach ($links as $linkNode) {
            $rel = strtolower(trim((string)$linkNode->getAttribute('rel')));
            $href = trim((string)$linkNode->getAttribute('href'));
            if ($rel !== 'stylesheet' || !preg_match('#^(https?:)?//#i', $href)) {
                if ($linkNode->parentNode) $linkNode->parentNode->removeChild($linkNode);
            }
        }

        foreach ($dom->getElementsByTagName('img') as $imageNode) {
            $src = trim((string)$imageNode->getAttribute('src'));
            if (strpos($src, '//') === 0) $src = 'https:' . $src;
            if (stripos($src, 'cid:') === 0) {
                $cid = $this->normalizeContentId($src);
                if (isset($inlineMap[$cid]) && is_array($inlineMap[$cid]) && !empty($inlineMap[$cid]['part_id'])) {
                    $src = 'public_mail_action.php?action=inline_image&message_key=' . rawurlencode($messageKey)
                        . '&part_id=' . rawurlencode((string)$inlineMap[$cid]['part_id']);
                } else {
                    $src = '';
                }
            } elseif (!preg_match('#^https?://#i', $src)
                && !preg_match('#^data:image/(?:png|jpeg|jpg|gif|webp|svg\+xml)(?:;base64)?,#i', $src)) {
                $src = '';
            }
            if ($src === '') $imageNode->removeAttribute('src');
            else {
                $imageNode->setAttribute('src', $src);
                $imageNode->setAttribute('referrerpolicy', 'no-referrer');
            }
        }

        /* 원문이 외부 문서를 프레임으로 구성한 경우에도 상위 sandbox 제한 안에서 그대로 표시합니다. */
        foreach (array('iframe','frame') as $frameTagName) {
            $frameNodes = array();
            foreach ($dom->getElementsByTagName($frameTagName) as $frameNode) $frameNodes[] = $frameNode;
            foreach ($frameNodes as $frameNode) {
                $frameSrc = trim((string)$frameNode->getAttribute('src'));
                if (strpos($frameSrc, '//') === 0) $frameSrc = 'https:' . $frameSrc;
                if (!preg_match('#^https?://#i', $frameSrc)) $frameNode->removeAttribute('src');
                else $frameNode->setAttribute('src', $frameSrc);
                $frameNode->setAttribute('sandbox', '');
                $frameNode->setAttribute('referrerpolicy', 'no-referrer');
            }
        }

        $headNodes = $dom->getElementsByTagName('head');
        $head = $headNodes->length > 0 ? $headNodes->item(0) : null;
        if (!$head) {
            $head = $dom->createElement('head');
            if ($dom->documentElement) $dom->documentElement->insertBefore($head, $dom->documentElement->firstChild);
        }
        if ($head) {
            $metaPolicy = $dom->createElement('meta');
            $metaPolicy->setAttribute('http-equiv', 'Content-Security-Policy');
            $metaPolicy->setAttribute('content', $this->mailBodyContentSecurityPolicy());
            $head->insertBefore($metaPolicy, $head->firstChild);
            $metaCharset = $dom->createElement('meta');
            $metaCharset->setAttribute('charset', 'UTF-8');
            $head->insertBefore($metaCharset, $head->firstChild);
        }

        $output = $dom->saveHTML();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return is_string($output) ? $output : '';
    }

    private function buildMailBodyDocumentFallback($bodyHtml, $bodyText, $messageKey, $inlineMap)
    {
        $html = preg_replace('#<(script|object|embed|applet)[^>]*>.*?</\1>#is', '', (string)$bodyHtml);
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/\s(?:srcdoc|formaction|action)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $self = $this;
        $html = preg_replace_callback('/\bsrc\s*=\s*(["\'])cid:([^"\']+)\1/i', function ($matches) use ($self, $messageKey, $inlineMap) {
            $cid = $self->normalizeContentId(isset($matches[2]) ? $matches[2] : '');
            if (!isset($inlineMap[$cid]) || empty($inlineMap[$cid]['part_id'])) return '';
            $src = 'public_mail_action.php?action=inline_image&message_key=' . rawurlencode($messageKey)
                . '&part_id=' . rawurlencode((string)$inlineMap[$cid]['part_id']);
            return 'src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"';
        }, $html);
        $html = $this->sanitizeMailDocumentCss($html, $messageKey, $inlineMap);
        $securityMeta = '<meta http-equiv="Content-Security-Policy" content="'
            . htmlspecialchars($this->mailBodyContentSecurityPolicy(), ENT_QUOTES, 'UTF-8') . '">';
        if (preg_match('/<head\b[^>]*>/i', $html)) {
            return preg_replace('/(<head\b[^>]*>)/i', '$1<meta charset="UTF-8">' . $securityMeta, $html, 1);
        }
        if (preg_match('/<html\b[^>]*>/i', $html)) {
            return preg_replace('/(<html\b[^>]*>)/i', '$1<head><meta charset="UTF-8">' . $securityMeta . '</head>', $html, 1);
        }
        return '<!doctype html><html><head><meta charset="UTF-8">' . $securityMeta . '</head><body>' . $html . '</body></html>';
    }

    private function mailBodyContentSecurityPolicy()
    {
        return "default-src 'none'; img-src 'self' data: https: http:; style-src 'unsafe-inline' https: http:; font-src data: https: http:; script-src 'none'; connect-src 'none'; frame-src https: http:; object-src 'none'; base-uri 'none'; form-action 'none'";
    }

    private function sanitizeMailDocumentCss($css, $messageKey, $inlineMap)
    {
        $css = preg_replace('/expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding\s*:/i', '', (string)$css);
        return preg_replace_callback('/url\(\s*(["\']?)cid:([^"\')]+)\1\s*\)/i', function ($matches) use ($messageKey, $inlineMap) {
            $cid = $this->normalizeContentId(isset($matches[2]) ? $matches[2] : '');
            if (!isset($inlineMap[$cid]) || empty($inlineMap[$cid]['part_id'])) return 'none';
            $src = 'public_mail_action.php?action=inline_image&message_key=' . rawurlencode($messageKey)
                . '&part_id=' . rawurlencode((string)$inlineMap[$cid]['part_id']);
            return 'url("' . $src . '")';
        }, $css);
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
        /* 일부 발송시스템은 본문 HTML에도 name/filename을 넣으므로 attachment로 오인하지 않습니다. */
        $isHtmlBody = $this->isHtmlMimeCandidate($mime, $filename);
        $isAttachment = !$isInlineImage && !$isHtmlBody && ($filename !== '' || $disposition === 'attachment');
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

    private function fetchAndBuildMessage($client,$box,$uid,$projects,$settings,&$gptUsed,$prepareBody=false)
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
        if ($prepareBody) {
            try {
                $bodyCache = $this->buildBodyCache($message['message_key'], false, $client, $message);
                $message = $this->applyBodyCacheMetadataToMessage($message, $bodyCache);
            } catch (\Exception $bodyError) {
                $message['body_cache_pending'] = true;
                $message['body_cache_error'] = PublicMailStorageService::sanitizeText($bodyError->getMessage(), 300);
            }
        }
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
        $fromText=PublicMailStorageService::normalizeMailText($this->decodeHeader(isset($headers['from'])?$headers['from']:''));
        $fromEmail=$this->extractEmail($fromText);
        $rawSubject=isset($headers['subject'])?$headers['subject']:'';
        $subject=PublicMailStorageService::normalizeMailText(
            $this->isBusinessOnSenderText($fromText,$fromEmail)
                ? $this->decodeSmartBillSubject($rawSubject) : $this->decodeHeader($rawSubject)
        );
        $toText=PublicMailStorageService::normalizeMailText($this->decodeHeader(isset($headers['to'])?$headers['to']:''));
        $dateText=isset($headers['date'])?trim((string)$headers['date']):''; $timestamp=$dateText!==''?strtotime($dateText):false; if ($timestamp===false) $timestamp=time();
        $flags=isset($headerData['flags'])&&is_array($headerData['flags'])?$headerData['flags']:array(); $isSeen=false; $isFlagged=false;
        foreach ($flags as $flag) { if (strcasecmp($flag,'\\Seen')===0) $isSeen=true; if (strcasecmp($flag,'\\Flagged')===0) $isFlagged=true; }
        $uid=isset($headerData['uid'])?(int)$headerData['uid']:0; $key=PublicMailStorageService::messageKey($mailbox,$uid);
        return array(
            'message_key'=>$key,'uid'=>$uid,'mailbox'=>$mailbox,'mailbox_name'=>$mailboxName,'mailbox_type'=>$this->detectMailboxType($mailbox,$mailboxName,array()),
            'message_id'=>isset($headers['message-id'])?trim((string)$headers['message-id']):'',
            'subject'=>$subject!==''?$subject:'(제목 없음)','from_text'=>$fromText,'from_email'=>$fromEmail,
            'to_text'=>$toText,'cc_text'=>PublicMailStorageService::normalizeMailText($this->decodeHeader(isset($headers['cc'])?$headers['cc']:'')),
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

        /* 이름이 붙은 text/html도 메일 클라이언트에서는 본문으로 표시될 수 있습니다. */
        $isHtmlBody = $this->isHtmlMimeCandidate($mimeType, $filename);
        $isAttachment = !$isHtmlBody && ($filename !== '' || $entity['disposition'] === 'attachment');
        if ($isAttachment) {
            if ($includeAttachmentContent) {
                $entity['content'] = $decoded;
            }
        } else {
            if (strpos($mimeType, 'text/') === 0) {
                $entity['content'] = $this->convertToUtf8($decoded, $charset);
            } elseif ($includeAttachmentContent) {
                $entity['content'] = $decoded;
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
        $isHtmlBody = $this->isHtmlMimeCandidate($mimeType, $filename);
        $isAttachment = !$isHtmlBody && ($filename !== '' || $disposition === 'attachment');

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

    /** 저장된 발신자 정보로 비즈니스온·스마트빌 메일을 찾습니다. */
    private function isBusinessOnMessage($message)
    {
        if (!is_array($message)) return false;
        $fromText = isset($message['from_text']) ? (string)$message['from_text'] : '';
        $fromEmail = isset($message['from_email']) ? (string)$message['from_email'] : '';
        return $this->isBusinessOnSenderText($fromText, $fromEmail);
    }

    /** 구형 호출 호환용입니다. */
    private function isSmartBillMessage($message)
    {
        return $this->isBusinessOnMessage($message);
    }

    private function isBusinessOnSenderText($fromText, $fromEmail)
    {
        $haystack = $this->lower(trim((string)$fromText . ' ' . (string)$fromEmail));
        if ($haystack === '') return false;
        return $this->contains($haystack, 'mailing@businesson.co.kr')
            || $this->contains($haystack, '@businesson.co.kr')
            || $this->contains($haystack, '.businesson.co.kr')
            || $this->contains($haystack, '스마트빌')
            || $this->contains($haystack, 'smartbill')
            || $this->contains($haystack, '@smartbill.co.kr')
            || $this->contains($haystack, '.smartbill.co.kr');
    }

    /** 구형 호출 호환용입니다. */
    private function isSmartBillSenderText($fromText, $fromEmail)
    {
        return $this->isBusinessOnSenderText($fromText, $fromEmail);
    }

    /**
     * 정상 제목은 네이버에 다시 요청하지 않습니다.
     * 사진처럼 한자/이상문자 덩어리, MIME 표식, 대체문자와 제어문자가 보이면 복구 대상으로 봅니다.
     */
    private function isBrokenBusinessOnSubject($value)
    {
        if ($this->isClearlyBrokenBusinessOnSubject($value)) return true;
        return $this->businessOnSubjectScore($value) < -5;
    }

    /** 기존 제목이 명백하게 깨졌는지 강하게 판정합니다. */
    private function isClearlyBrokenBusinessOnSubject($value)
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '(제목 없음)') return true;
        if (!@preg_match('//u', $value)) return true;
        if (strpos($value, '=?') !== false || strpos($value, "\xEF\xBF\xBD") !== false) return true;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) return true;
        if (preg_match('/(?:Ã.|Â.|ì.|ë.|ê.|ã.|ð.|þ.|æ.|å.)/u', $value)) return true;
        if (preg_match('/[譴翌絃滅霓關轄]{2,}/u', $value)) return true;

        $hangul = preg_match_all('/[가-힣]/u', $value, $matches);
        $cjk = preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $value, $matches);
        $letters = preg_match_all('/[A-Za-z가-힣\x{4E00}-\x{9FFF}]/u', $value, $matches);
        if ((int)$cjk >= 4 && (int)$hangul <= 3) return true;
        if ((int)$cjk >= 5 && (int)$cjk > ((int)$hangul * 2)) return true;
        if ((int)$letters > 0 && (int)$cjk >= 4 && ((int)$cjk / (int)$letters) >= 0.22) return true;
        if ((int)$cjk >= 3 && !preg_match('/(전자세금계산서|세금계산서|계산서|발행|발행취소|수신|승인|스마트빌|국세청)/u', $value)) return true;
        return false;
    }

    /** 복구 후보가 실제로 사용할 수 있는 정상 한글 제목인지 확인합니다. */
    private function isUsableBusinessOnSubjectCandidate($value)
    {
        $value = trim((string)$value);
        if ($value === '' || !@preg_match('//u', $value)) return false;
        if (strpos($value, '=?') !== false || strpos($value, "\xEF\xBF\xBD") !== false) return false;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) return false;
        if ($this->isClearlyBrokenBusinessOnSubject($value)) return false;
        $hangul = preg_match_all('/[가-힣]/u', $value, $matches);
        if ((int)$hangul >= 2) return true;
        return preg_match('/(전자세금계산서|세금계산서|발행|발행취소|스마트빌|국세청|주식회사)/u', $value) === 1;
    }

    /** 외부 호출은 기존 이름을 유지하고 내부 상세 후보 선택기를 사용합니다. */
    private function decodeSmartBillSubject($value)
    {
        $details = $this->decodeSmartBillSubjectDetails($value);
        return isset($details['subject']) ? (string)$details['subject'] : '';
    }

    /**
     * MIME/CP949/EUC-KR/UTF-8 이중변환 후보를 두 차례 확장한 뒤 가장 정상적인 제목을 선택합니다.
     */
    private function decodeSmartBillSubjectDetails($value)
    {
        $value = trim((string)$value);
        if ($value === '') return array('subject'=>'','score'=>-1000000,'candidates'=>array());
        $value = preg_replace("/\r?\n[ \t]+/", ' ', $value);
        $value = str_ireplace(
            array('KS_C_5601-1987','KS-C-5601-1987','KS_C_5601','KSC5601','EUC_KR','EUC-KR','X-WINDOWS-949','WINDOWS-949','MS949'),
            array('CP949','CP949','CP949','CP949','CP949','CP949','CP949','CP949','CP949'),
            $value
        );
        $value = preg_replace('/\?=\s+(?==\?)/', '?=', $value);

        $candidates = array();
        $this->appendBusinessOnSubjectCandidate($candidates, $value, 'raw');
        $this->appendBusinessOnSubjectCandidate($candidates, $this->decodeHeader($value), 'generic');

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false) $this->appendBusinessOnSubjectCandidate($candidates, $decoded, 'iconv_mime');
        }
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if ($decoded !== false) $this->appendBusinessOnSubjectCandidate($candidates, $decoded, 'mb_mime');
        }

        $self = $this;
        $manual = preg_replace_callback('/=\?([^?]+)\?([bBqQ])\?(.*?)\?=/s', function ($matches) use ($self) {
            $charset = isset($matches[1]) ? (string)$matches[1] : '';
            $mode = isset($matches[2]) ? strtoupper((string)$matches[2]) : 'Q';
            $payload = isset($matches[3]) ? (string)$matches[3] : '';
            if ($mode === 'B') {
                $raw = base64_decode(preg_replace('/\s+/', '', $payload), true);
                if ($raw === false) $raw = '';
            } else {
                $raw = quoted_printable_decode(str_replace('_', ' ', $payload));
            }
            return $self->bestSmartBillBytesToUtf8($raw, $charset);
        }, $value);
        if (is_string($manual)) $this->appendBusinessOnSubjectCandidate($candidates, $manual, 'manual_mime');

        /* 각 후보를 최대 두 번 역변환하여 이중 인코딩도 복구합니다. */
        for ($round = 0; $round < 2; $round++) {
            $snapshot = $candidates;
            foreach ($snapshot as $entry) {
                $candidate = isset($entry['value']) ? (string)$entry['value'] : '';
                $source = isset($entry['source']) ? (string)$entry['source'] : 'candidate';
                if ($candidate === '') continue;
                $this->appendBusinessOnSubjectCandidate($candidates, $this->repairMojibake($candidate), $source . '_repair');
                foreach ($this->businessOnReverseTranscodeCandidates($candidate) as $reverse) {
                    $this->appendBusinessOnSubjectCandidate($candidates, $reverse, $source . '_reverse');
                }
                if (count($candidates) >= 96) break 2;
            }
        }

        $best = '';
        $bestScore = -1000000;
        $debug = array();
        foreach ($candidates as $entry) {
            $candidate = isset($entry['value']) ? trim((string)$entry['value']) : '';
            if ($candidate === '') continue;
            $score = $this->businessOnSubjectScore($candidate);
            if ($this->isUsableBusinessOnSubjectCandidate($candidate)) $score += 250;
            elseif ($this->isClearlyBrokenBusinessOnSubject($candidate)) $score -= 200;
            $debug[] = array('subject'=>$this->shortBusinessOnSubjectPreview($candidate),'score'=>$score,'source'=>isset($entry['source'])?$entry['source']:'');
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }
        usort($debug, function ($a, $b) {
            $as = isset($a['score']) ? (int)$a['score'] : -1000000;
            $bs = isset($b['score']) ? (int)$b['score'] : -1000000;
            if ($as === $bs) return 0;
            return $as > $bs ? -1 : 1;
        });
        if (count($debug) > 5) $debug = array_slice($debug, 0, 5);
        return array('subject'=>$best,'score'=>$bestScore,'candidates'=>$debug);
    }

    private function appendBusinessOnSubjectCandidate(&$candidates, $value, $source)
    {
        $value = (string)$value;
        if ($value === '') return;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        if (!@preg_match('//u', $value)) return;
        $value = PublicMailStorageService::normalizeMailText(trim($value));
        if ($value === '') return;
        $key = sha1($value);
        if (!isset($candidates[$key])) {
            $candidates[$key] = array('value'=>$value,'source'=>(string)$source);
        }
    }

    /** UTF-8 문자열을 CP949/EUC-KR 바이트로 되돌려 다시 UTF-8로 읽는 역변환 후보입니다. */
    private function businessOnReverseTranscodeCandidates($value)
    {
        $value = (string)$value;
        $result = array();
        if ($value === '' || !$this->isValidUtf8($value) || !function_exists('iconv')) return $result;
        foreach (array('CP949','EUC-KR','ISO-8859-1','WINDOWS-1252') as $target) {
            $bytes = @iconv('UTF-8', $target . '//IGNORE', $value);
            if ($bytes === false || $bytes === '') continue;
            if ($this->isValidUtf8($bytes)) $result[$bytes] = $bytes;
            foreach (array('UTF-8','CP949','EUC-KR','ISO-8859-1','WINDOWS-1252') as $source) {
                $decoded = @iconv($source, 'UTF-8//IGNORE', $bytes);
                if ($decoded !== false && $decoded !== '' && $this->isValidUtf8($decoded)) {
                    $result[$decoded] = $decoded;
                }
            }
        }
        return array_values($result);
    }

    private function bestSmartBillBytesToUtf8($raw, $declaredCharset)
    {
        $raw = (string)$raw;
        if ($raw === '') return '';
        $candidates = array();
        $declared = $this->normalizeCharset($declaredCharset);
        foreach (array($declared, 'CP949', 'EUC-KR', 'UTF-8', 'ISO-8859-1', 'WINDOWS-1252') as $charset) {
            $charset = trim((string)$charset);
            if ($charset === '') continue;
            $converted = $this->convertToUtf8($raw, $charset);
            if ($converted !== '') $candidates[$converted] = $converted;
        }
        if (@preg_match('//u', $raw)) $candidates[$raw] = $raw;

        $expanded = $candidates;
        foreach ($candidates as $candidate) {
            $expanded[$this->repairMojibake($candidate)] = $this->repairMojibake($candidate);
            foreach ($this->businessOnReverseTranscodeCandidates($candidate) as $reverse) $expanded[$reverse] = $reverse;
        }

        $best = '';
        $bestScore = -1000000;
        foreach ($expanded as $candidate) {
            $candidate = PublicMailStorageService::normalizeMailText(trim((string)$candidate));
            if ($candidate === '') continue;
            $score = $this->businessOnSubjectScore($candidate);
            if ($this->isUsableBusinessOnSubjectCandidate($candidate)) $score += 250;
            if ($score > $bestScore) { $best = $candidate; $bestScore = $score; }
        }
        return $best;
    }

    private function businessOnSubjectScore($value)
    {
        $value = trim((string)$value);
        if ($value === '') return -1000000;
        if (!@preg_match('//u', $value)) return -1000000;
        $score = 0;
        $hangul = preg_match_all('/[가-힣]/u', $value, $matches);
        $cjk = preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $value, $matches);
        $replacement = substr_count($value, "\xEF\xBF\xBD");
        $control = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value, $matches);
        $mojibake = preg_match_all('/(?:Ã.|Â.|ì.|ë.|ê.|ã.|ð.|þ.|æ.|å.)/u', $value, $matches);
        $score += (int)$hangul * 7;
        $score -= (int)$cjk * 9;
        $score -= (int)$replacement * 80;
        $score -= (int)$control * 80;
        $score -= (int)$mojibake * 25;
        $score -= substr_count($value, '=?') * 60;
        if (preg_match('/(스마트빌|전자세금계산서|세금계산서|계산서|국세청|발행|발행취소|승인|공급가액|작성일자|수신|주식회사)/u', $value)) $score += 140;
        if (preg_match('/[가-힣]{3,}/u', $value)) $score += 40;
        if (preg_match('/\[[^\]]*(전자세금계산서|세금계산서|발행|발행취소)[^\]]*\]/u', $value)) $score += 80;
        return $score;
    }

    private function shortBusinessOnSubjectPreview($value)
    {
        $value = PublicMailStorageService::sanitizeText((string)$value, 500);
        $value = preg_replace('/\s+/', ' ', trim($value));
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > 140 ? mb_substr($value, 0, 140, 'UTF-8') . '…' : $value;
        }
        return strlen($value) > 280 ? substr($value, 0, 280) . '…' : $value;
    }

    /** 구형 내부 이름 호환용입니다. */
    private function smartBillSubjectScore($value)
    {
        return $this->businessOnSubjectScore($value);
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
            return PublicMailStorageService::normalizeMailText($this->repairMojibake($this->ensureUtf8($manual, '')));
        }

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false && $decoded !== '' && strpos($decoded, '=?') === false) {
                return PublicMailStorageService::normalizeMailText($this->repairMojibake($this->ensureUtf8($decoded, '')));
            }
        }
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if ($decoded !== false && $decoded !== '' && strpos($decoded, '=?') === false) {
                return PublicMailStorageService::normalizeMailText($this->repairMojibake($this->ensureUtf8($decoded, '')));
            }
        }

        return PublicMailStorageService::normalizeMailText($this->repairMojibake($this->ensureUtf8(is_string($manual) ? $manual : $value, '')));
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
        $html = $this->extractMailHtmlBodyFragment($html);
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

    /** 완전한 HTML 문서를 div 안에 중첩하지 않고 body 내용과 인라인 스타일만 안전하게 꺼냅니다. */
    private function extractMailHtmlBodyFragment($html)
    {
        $html = (string)$html;
        $bodyMatch = array();
        if (!preg_match('#<body\b([^>]*)>(.*)</body\s*>#is', $html, $bodyMatch)) return $html;
        $bodyAttributes = isset($bodyMatch[1]) ? (string)$bodyMatch[1] : '';
        $bodyContent = isset($bodyMatch[2]) ? (string)$bodyMatch[2] : '';
        $bodyStyle = '';
        $styleMatch = array();
        if (preg_match('/\bstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/is', $bodyAttributes, $styleMatch)) {
            if (isset($styleMatch[1]) && $styleMatch[1] !== '') $bodyStyle = (string)$styleMatch[1];
            elseif (isset($styleMatch[2])) $bodyStyle = (string)$styleMatch[2];
        }
        $bodyStyle = $this->sanitizeInlineStyle($bodyStyle);
        if ($bodyStyle === '') return $bodyContent;
        return '<div style="' . htmlspecialchars($bodyStyle, ENT_QUOTES, 'UTF-8') . '">' . $bodyContent . '</div>';
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
        $allowed = array('width','min-width','max-width','height','min-height','max-height','text-align','vertical-align','background','background-color','color','font-size','font-family','font-weight','font-style','text-decoration','white-space','border','border-top','border-right','border-bottom','border-left','border-color','border-width','border-style','border-collapse','border-spacing','border-radius','table-layout','box-sizing','list-style','padding','padding-top','padding-right','padding-bottom','padding-left','margin','margin-top','margin-right','margin-bottom','margin-left','display','line-height','word-break','word-wrap','overflow-wrap');
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
        /* v1.7.12: 이미 저장된 제목과 주소를 상세화면에서 다시 변환하지 않습니다. */
        foreach (array('subject','from_text','to_text','cc_text') as $field) {
            if (isset($message[$field])) $message[$field] = (string)$message[$field];
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


    /** 저장된 메일 중 실제 복구가 필요한 메일 키만 빠르게 뽑습니다. */
    private function buildMetadataRepairTargetKeys($messages)
    {
        $targetKeys = array();
        if (!is_array($messages)) return $targetKeys;
        foreach ($messages as $messageKey => $message) {
            if ($this->messageNeedsMetadataRepair($message)) $targetKeys[] = (string)$messageKey;
        }
        return $targetKeys;
    }

    /**
     * v1.7.8에서 진행 중이던 전체목록 방식 작업을 v1.7.11 대상목록 방식으로 자동 변환합니다.
     * 이미 복구된 메일은 다시 네이버에 요청하지 않고 남아 있는 깨진 메일만 대기열에 넣습니다.
     */
    private function prepareMetadataRepairQueue($repair, $messages)
    {
        if (!is_array($repair)) $repair = array();
        $queueVersion = isset($repair['queue_version']) ? (int)$repair['queue_version'] : 0;
        $targetKeys = isset($repair['target_keys']) && is_array($repair['target_keys']) ? array_values($repair['target_keys']) : array();

        if ($queueVersion >= 2 && !empty($targetKeys)) {
            $repair['target_keys'] = $targetKeys;
            $repair['cursor'] = max(0, min(count($targetKeys), isset($repair['cursor']) ? (int)$repair['cursor'] : 0));
            $repair['remaining_count'] = max(0, count($targetKeys) - (int)$repair['cursor']);
            return $repair;
        }

        $targetKeys = $this->buildMetadataRepairTargetKeys($messages);
        /*
         * 구버전의 failed_count 대상은 아직 깨진 상태라 새 대상목록에 다시 들어옵니다.
         * 따라서 이미 완료된 값은 실제 복구 완료 건수만 사용해야 진행률이 100%에서 끝납니다.
         */
        $alreadyCompleted = max(0, isset($repair['repaired_count']) ? (int)$repair['repaired_count'] : 0);
        $targetCount = $alreadyCompleted + count($targetKeys);

        $repair['queue_version'] = 2;
        $repair['target_keys'] = $targetKeys;
        $repair['message_attempts'] = array();
        $repair['cursor'] = 0;
        $repair['target_count'] = $targetCount;
        $repair['total_count'] = $targetCount;
        $repair['processed_count'] = $alreadyCompleted;
        $repair['failed_count'] = 0;
        $repair['remaining_count'] = count($targetKeys);
        $repair['batch_size_current'] = isset($repair['batch_size_current']) ? max(30, min(100, (int)$repair['batch_size_current'])) : 50;
        $repair['recommended_batch_size'] = $repair['batch_size_current'];
        $repair['last_checkpoint_at'] = date('Y-m-d H:i:s');
        if (!empty($repair['active'])) {
            $repair['last_message'] = '기존 복구 진행상태를 고속 처리 방식으로 변환했습니다. 남은 깨진 메일부터 자동으로 이어집니다.';
        }
        return $repair;
    }

    /** 깨진 메일 전체 복구 작업을 등록합니다. 개발부서 연동 설정 화면이 열려 있으면 브라우저가 이어서 처리합니다. */
    public function startMetadataRepair()
    {
        $existingState = PublicMailStorageService::getSyncState();
        if (!empty($existingState['metadata_repair']['active'])) {
            $messages = PublicMailStorageService::getMessages();
            $existing = $this->prepareMetadataRepairQueue($existingState['metadata_repair'], $messages);
            $state = PublicMailStorageService::saveSyncState(array('metadata_repair'=>$existing));
            $message = !empty($existing['paused'])
                ? '깨진 메일 복구가 일시중지되어 있습니다. [다시 시작]을 누르면 이어집니다.'
                : '깨진 메일 전체 복구가 이미 진행 중입니다. 저장된 위치부터 고속 자동처리로 이어집니다.';
            return array('ok'=>true,'message'=>$message,'target_count'=>isset($existing['target_count'])?(int)$existing['target_count']:0,'state'=>$state);
        }

        $messages = PublicMailStorageService::getMessages();
        $targetKeys = $this->buildMetadataRepairTargetKeys($messages);
        $targets = count($targetKeys);
        $repair = array(
            'active' => $targets > 0,
            'paused' => false,
            'cancelled' => false,
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => $targets > 0 ? '' : date('Y-m-d H:i:s'),
            'status' => $targets > 0 ? 'active' : 'completed',
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
            'target_keys' => $targetKeys,
            'message_attempts' => array(),
            'cursor' => 0,
            'total_count' => $targets,
            'target_count' => $targets,
            'processed_count' => 0,
            'repaired_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'remaining_count' => $targets,
            'batch_size_current' => 50,
            'recommended_batch_size' => 50,
            'retry_count' => 0,
            'consecutive_errors' => 0,
            'last_run_duration_ms' => 0,
            'last_checkpoint_at' => '',
            'last_index_refresh_processed' => 0,
            'last_error_code' => '',
            'last_message' => $targets > 0
                ? '깨진 메일 전체 복구를 등록했습니다. 50건부터 시작해 서버 상태에 따라 최대 100건까지 자동으로 빠르게 처리합니다.'
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
            $repair['status'] = 'paused';
            $repair['last_message'] = '깨진 메일 복구를 일시중지했습니다.';
        } elseif ($command === 'resume') {
            $messages = PublicMailStorageService::getMessages();
            $repair = $this->prepareMetadataRepairQueue($repair, $messages);
            if (!empty($repair['cancelled']) || (int)$repair['remaining_count'] <= 0) return $this->startMetadataRepair();
            $repair['active'] = true;
            $repair['paused'] = false;
            $repair['cancelled'] = false;
            $repair['status'] = 'active';
            $repair['last_error'] = '';
            $repair['last_error_code'] = '';
            $repair['last_message'] = '깨진 메일 복구를 저장된 위치부터 다시 시작했습니다.';
        } elseif ($command === 'cancel') {
            $repair['active'] = false;
            $repair['paused'] = false;
            $repair['cancelled'] = true;
            $repair['status'] = 'cancelled';
            $repair['finished_at'] = date('Y-m-d H:i:s');
            $repair['last_message'] = '깨진 메일 복구를 취소했습니다.';
        } else {
            throw new \InvalidArgumentException('깨진 메일 복구 명령이 올바르지 않습니다.');
        }
        $state = PublicMailStorageService::saveSyncState(array('metadata_repair'=>$repair));
        return array('ok'=>true,'message'=>$repair['last_message'],'state'=>$state);
    }

    /**
     * 깨진 메일 대상목록만 처리합니다.
     * 한 번에 30~100건, 최대 16초 안에서 동작하며 10건마다 안전 체크포인트를 저장합니다.
     */
    public function runMetadataRepairBatch($limit, $maximumSeconds)
    {
        $limit = max(30, min(100, (int)$limit));
        $maximumSeconds = max(6, min(16, (int)$maximumSeconds));
        $started = microtime(true);
        $state = PublicMailStorageService::getSyncState();
        $repair = $state['metadata_repair'];

        if (empty($repair['active'])) {
            return array('ok'=>true,'message'=>'깨진 메일 복구가 실행 중이 아닙니다.','processed_count'=>0,'repaired_count'=>0,'failed_count'=>0,'duration_ms'=>0,'recommended_batch_size'=>50,'state'=>$state);
        }
        if (!empty($repair['paused'])) {
            return array('ok'=>true,'message'=>'깨진 메일 복구가 일시중지되어 있습니다.','processed_count'=>0,'repaired_count'=>0,'failed_count'=>0,'duration_ms'=>0,'recommended_batch_size'=>50,'state'=>$state);
        }

        $repairLock = PublicMailStorageService::acquireLock('metadata_repair');
        if ($repairLock === false) {
            $state = PublicMailStorageService::saveSyncState(array('metadata_repair'=>array(
                'lock_status'=>'busy',
                'last_run_result'=>'다른 복구 작업이 실행 중입니다.',
                'last_message'=>'다른 복구 작업이 실행 중입니다. 잠시 후 자동으로 다시 시도합니다.'
            )));
            return array('ok'=>true,'retryable'=>true,'error_code'=>'busy','message'=>'다른 복구 작업이 실행 중입니다. 잠시 후 자동으로 다시 시도합니다.','processed_count'=>0,'repaired_count'=>0,'failed_count'=>0,'duration_ms'=>0,'recommended_batch_size'=>30,'state'=>$state);
        }

        $processedThisRun = 0;
        $repairedThisRun = 0;
        $skippedThisRun = 0;
        $failedThisRun = 0;
        $client = null;
        $changed = false;
        $messagesChangedThisRun = false;
        $retryable = false;
        $runError = '';
        $errorCode = '';
        $state = PublicMailStorageService::getSyncState();
        $repair = $state['metadata_repair'];

        try {
            $messages = PublicMailStorageService::getMessages();
            $repair = $this->prepareMetadataRepairQueue($repair, $messages);
            $targetKeys = isset($repair['target_keys']) && is_array($repair['target_keys']) ? array_values($repair['target_keys']) : array();
            $queueTotal = count($targetKeys);
            $cursor = max(0, min($queueTotal, isset($repair['cursor']) ? (int)$repair['cursor'] : 0));
            $attempts = isset($repair['message_attempts']) && is_array($repair['message_attempts']) ? $repair['message_attempts'] : array();

            $baseProcessed = isset($repair['processed_count']) ? (int)$repair['processed_count'] : 0;
            $baseRepaired = isset($repair['repaired_count']) ? (int)$repair['repaired_count'] : 0;
            $baseSkipped = isset($repair['skipped_count']) ? (int)$repair['skipped_count'] : 0;
            $baseFailed = isset($repair['failed_count']) ? (int)$repair['failed_count'] : 0;

            if ($cursor >= $queueTotal) {
                $repair['active'] = false;
                $repair['paused'] = false;
                $repair['status'] = 'completed';
                $repair['remaining_count'] = 0;
                $repair['finished_at'] = date('Y-m-d H:i:s');
                $repair['last_message'] = '깨진 메일 전체 복구를 완료했습니다.';
            } else {
                $settings = PublicMailStorageService::getSettings(true);
                $client = $this->createClient($settings);
                $client->connect();
                $client->login($settings['username'], $settings['password']);

                $repair['status'] = 'running';
                $repair['lock_status'] = 'locked';
                $repair['lock_acquired_at'] = date('Y-m-d H:i:s');
                $repair['heartbeat_at'] = date('Y-m-d H:i:s');
                $repair['last_error'] = '';
                $repair['last_error_code'] = '';
                $repair['batch_size_current'] = $limit;
                PublicMailStorageService::saveSyncState(array('metadata_repair'=>$repair));

                $selected = '';
                $checkpointCount = 0;
                $lastCheckpointTime = microtime(true);

                while ($cursor < $queueTotal && $processedThisRun < $limit) {
                    if ((microtime(true) - $started) >= $maximumSeconds) break;

                    $key = (string)$targetKeys[$cursor];
                    $message = isset($messages[$key]) && is_array($messages[$key]) ? $messages[$key] : array();

                    if (!$this->messageNeedsMetadataRepair($message)) {
                        $cursor++;
                        $processedThisRun++;
                        $skippedThisRun++;
                        $checkpointCount++;
                    } else {
                        try {
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

                            unset($attempts[$key]);
                            $cursor++;
                            $processedThisRun++;
                            $repairedThisRun++;
                            $checkpointCount++;
                            $changed = true;
                            $messagesChangedThisRun = true;
                        } catch (\Exception $messageError) {
                            $messageErrorText = PublicMailStorageService::sanitizeText($messageError->getMessage(), 500);
                            $attempts[$key] = isset($attempts[$key]) ? ((int)$attempts[$key] + 1) : 1;
                            if ((int)$attempts[$key] < 2) {
                                $retryable = true;
                                $errorCode = 'temporary_mail_error';
                                $runError = $messageErrorText;
                                break;
                            }

                            $messages[$key]['metadata_repair_failed'] = true;
                            $messages[$key]['metadata_repair_error'] = $messageErrorText;
                            $messages[$key]['metadata_repair_attempted_at'] = date('Y-m-d H:i:s');
                            unset($attempts[$key]);
                            $cursor++;
                            $processedThisRun++;
                            $failedThisRun++;
                            $checkpointCount++;
                            $changed = true;
                            $messagesChangedThisRun = true;
                        }
                    }

                    if ($checkpointCount >= 10 || (microtime(true) - $lastCheckpointTime) >= 3) {
                        if ($changed) {
                            PublicMailStorageService::saveMessagesCheckpoint($messages);
                            $changed = false;
                        }
                        $repair['cursor'] = $cursor;
                        $repair['processed_count'] = $baseProcessed + $processedThisRun;
                        $repair['repaired_count'] = $baseRepaired + $repairedThisRun;
                        $repair['skipped_count'] = $baseSkipped + $skippedThisRun;
                        $repair['failed_count'] = $baseFailed + $failedThisRun;
                        $repair['remaining_count'] = max(0, $queueTotal - $cursor);
                        $repair['message_attempts'] = $attempts;
                        $repair['heartbeat_at'] = date('Y-m-d H:i:s');
                        $repair['last_checkpoint_at'] = date('Y-m-d H:i:s');
                        PublicMailStorageService::saveSyncState(array('metadata_repair'=>$repair));
                        $checkpointCount = 0;
                        $lastCheckpointTime = microtime(true);
                    }
                }

                if ($changed) PublicMailStorageService::saveMessagesCheckpoint($messages);
                /*
                 * 5천 건 이상 색인을 매 묶음마다 다시 만들면 FastCGI가 응답을 끊을 수 있습니다.
                 * 200건마다 또는 마지막 묶음에서만 색인을 갱신해 속도와 안정성을 함께 확보합니다.
                 */
                $lastIndexRefreshProcessed = isset($repair['last_index_refresh_processed']) ? (int)$repair['last_index_refresh_processed'] : 0;
                $currentTotalProcessed = $baseProcessed + $processedThisRun;
                $shouldRefreshIndex = $messagesChangedThisRun && (($currentTotalProcessed - $lastIndexRefreshProcessed) >= 200 || $cursor >= $queueTotal);
                if ($shouldRefreshIndex) {
                    PublicMailStorageService::refreshIndexSafely($messages, null);
                    $repair['last_index_refresh_processed'] = $currentTotalProcessed;
                }

                $repair['cursor'] = $cursor;
                $repair['processed_count'] = $baseProcessed + $processedThisRun;
                $repair['repaired_count'] = $baseRepaired + $repairedThisRun;
                $repair['skipped_count'] = $baseSkipped + $skippedThisRun;
                $repair['failed_count'] = $baseFailed + $failedThisRun;
                $repair['remaining_count'] = max(0, $queueTotal - $cursor);
                $repair['message_attempts'] = $attempts;
                $repair['last_run_at'] = date('Y-m-d H:i:s');
                $repair['last_run_processed_count'] = $processedThisRun;
                $repair['last_run_repaired_count'] = $repairedThisRun;
                $repair['last_checkpoint_at'] = date('Y-m-d H:i:s');

                if ($retryable) {
                    $repair['active'] = true;
                    $repair['status'] = 'retry_wait';
                    $repair['retry_count'] = (isset($repair['retry_count']) ? (int)$repair['retry_count'] : 0) + 1;
                    $repair['consecutive_errors'] = (isset($repair['consecutive_errors']) ? (int)$repair['consecutive_errors'] : 0) + 1;
                    $repair['last_error'] = $runError;
                    $repair['last_error_code'] = $errorCode;
                    $repair['last_message'] = '네이버 연결이 잠시 불안정합니다. 저장된 위치부터 자동으로 다시 시도합니다.';
                } elseif ($cursor >= $queueTotal) {
                    $repair['active'] = false;
                    $repair['paused'] = false;
                    $repair['status'] = 'completed';
                    $repair['remaining_count'] = 0;
                    $repair['finished_at'] = date('Y-m-d H:i:s');
                    $repair['retry_count'] = 0;
                    $repair['consecutive_errors'] = 0;
                    $repair['last_error'] = $failedThisRun > 0 ? $failedThisRun . '건은 세 번 시도했지만 원본 헤더를 읽지 못했습니다.' : '';
                    $repair['last_error_code'] = '';
                    $repair['last_message'] = '깨진 메일 전체 복구를 완료했습니다. 복구 ' . number_format((int)$repair['repaired_count']) . '건, 확인 실패 ' . number_format((int)$repair['failed_count']) . '건입니다.';
                } else {
                    $repair['active'] = true;
                    $repair['status'] = 'active';
                    $repair['retry_count'] = 0;
                    $repair['consecutive_errors'] = 0;
                    $repair['last_error'] = $failedThisRun > 0 ? $failedThisRun . '건은 세 번 시도했지만 원본 헤더를 읽지 못했습니다.' : '';
                    $repair['last_error_code'] = '';
                    $repair['last_message'] = '이번 실행에서 ' . $processedThisRun . '건을 확인하고 ' . $repairedThisRun . '건을 복구했습니다. 남은 복구 대상은 ' . number_format($repair['remaining_count']) . '건입니다.';
                }
            }
        } catch (\Exception $e) {
            $retryable = true;
            $runError = PublicMailStorageService::sanitizeText($e->getMessage(), 500);
            $errorCode = 'temporary_connection';
            $repair['active'] = true;
            $repair['status'] = 'retry_wait';
            $repair['last_run_at'] = date('Y-m-d H:i:s');
            $repair['last_run_processed_count'] = $processedThisRun;
            $repair['last_run_repaired_count'] = $repairedThisRun;
            $repair['retry_count'] = (isset($repair['retry_count']) ? (int)$repair['retry_count'] : 0) + 1;
            $repair['consecutive_errors'] = (isset($repair['consecutive_errors']) ? (int)$repair['consecutive_errors'] : 0) + 1;
            $repair['last_error'] = $runError;
            $repair['last_error_code'] = $errorCode;
            $repair['last_message'] = '서버 또는 네이버 연결이 잠시 불안정합니다. 작업은 멈추지 않고 자동으로 다시 시도합니다.';
        } finally {
            if ($client !== null) {
                try { $client->logout(); } catch (\Exception $ignored) {}
            }

            $durationMs = (int)round((microtime(true) - $started) * 1000);
            $recommended = $limit;
            if ($retryable || $durationMs >= 11000) {
                $recommended = max(30, $limit - 20);
            } elseif ($processedThisRun >= $limit && $durationMs <= 6000) {
                $recommended = min(100, $limit + 25);
            }
            $repair['batch_size_current'] = $limit;
            $repair['recommended_batch_size'] = $recommended;
            $repair['last_run_duration_ms'] = $durationMs;
            $repair['last_run_result'] = isset($repair['last_message']) ? PublicMailStorageService::sanitizeText($repair['last_message'], 1000) : '';
            $repair['last_error'] = isset($repair['last_error']) ? PublicMailStorageService::sanitizeText($repair['last_error'], 1000) : '';
            $repair['lock_status'] = 'idle';
            $repair['lock_acquired_at'] = '';
            $repair['lock_released_at'] = date('Y-m-d H:i:s');
            $repair['heartbeat_at'] = date('Y-m-d H:i:s');
            try {
                $state = PublicMailStorageService::saveSyncState(array('metadata_repair'=>$repair));
            } catch (\Exception $stateSaveError) {
                @error_log('[CPMS Public Mail] metadata repair state save failed: ' . PublicMailStorageService::sanitizeText($stateSaveError->getMessage(), 500));
                $retryable = true;
                $errorCode = 'state_save_error';
                $repair['last_message'] = '복구 진행상태 저장이 잠시 지연되었습니다. 저장된 체크포인트부터 자동으로 다시 시도합니다.';
                $state = PublicMailStorageService::getSyncState();
            }
            PublicMailStorageService::releaseLock($repairLock);
        }

        return array(
            'ok'=>true,
            'retryable'=>$retryable,
            'error_code'=>$errorCode,
            'message'=>isset($repair['last_message'])?$repair['last_message']:'',
            'processed_count'=>$processedThisRun,
            'repaired_count'=>$repairedThisRun,
            'failed_count'=>$failedThisRun,
            'duration_ms'=>isset($durationMs)?$durationMs:0,
            'recommended_batch_size'=>isset($recommended)?$recommended:50,
            'retry_count'=>isset($repair['retry_count'])?(int)$repair['retry_count']:0,
            'state'=>$state
        );
    }

    /** 이전 호환용: 수동 호출이 들어오면 대기열을 시작하고 첫 묶음만 처리합니다. */
    public function repairBrokenMetadataBatch($limit)
    {
        $state = PublicMailStorageService::getSyncState();
        if (empty($state['metadata_repair']['active'])) $this->startMetadataRepair();
        $result = $this->runMetadataRepairBatch(max(30, (int)$limit), 14);
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
