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

class PublicMailService
{
    private $storage;
    private $classifier;

    public function __construct()
    {
        $this->storage = new PublicMailStorageService();
        $this->classifier = new PublicMailClassifierService();
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
        return array(
            'token' => $token,
            'url' => $baseUrl . '/cron/naver_mail_sync.php?key=' . rawurlencode($token),
            'recommended_interval' => '1~5분',
            'last_cron_at' => isset($this->getSyncState()['last_cron_at']) ? $this->getSyncState()['last_cron_at'] : ''
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
                    'last_uid'=>0, 'completed'=>($count === 0), 'last_error'=>''
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
        if (!empty($state['full_import']['active']) && empty($state['full_import']['paused'])) {
            return $this->syncFullImportBatch($limit);
        }
        return $this->syncNewBatch($limit);
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
                    $mailboxes[$id] = array('raw_name'=>$box['raw_name'],'display_name'=>$box['display_name'],'total_count'=>0,'imported_count'=>0,'remaining_count'=>0,'last_uid'=>0,'completed'=>false,'last_error'=>'');
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
        $messages = PublicMailStorageService::getMessages();
        $workflow = PublicMailStorageService::getWorkflow();
        $items = array();
        foreach ($messages as $messageKey => $message) {
            if (!is_array($message)) continue;
            $parsed = PublicMailStorageService::parseMessageKey($messageKey);
            $message['message_key'] = (string)$messageKey;
            $message['uid'] = isset($message['uid']) ? (int)$message['uid'] : (int)$parsed['uid'];
            $message['mailbox'] = isset($message['mailbox']) ? (string)$message['mailbox'] : (string)$parsed['mailbox'];
            $message['mailbox_name'] = isset($message['mailbox_name']) ? (string)$message['mailbox_name'] : ($message['mailbox'] === 'INBOX' ? '받은메일함' : $message['mailbox']);
            $message['workflow'] = $this->workflowFromMap($workflow, $messageKey);
            $message = $this->normalizeStoredMessage($message);
            if ($this->matchesFilters($message, $filters)) $items[] = $message;
        }
        usort($items, array($this, 'compareMessageDateDesc'));
        $total = count($items); $page = max(1, (int)$page); $perPage = (int)$perPage;
        if ($perPage < 10) $perPage = 30; if ($perPage > 100) $perPage = 100;
        $pageCount = max(1, (int)ceil($total / $perPage)); if ($page > $pageCount) $page = $pageCount;
        return array('items'=>array_slice($items, ($page-1)*$perPage, $perPage),'total'=>$total,'page'=>$page,'per_page'=>$perPage,'page_count'=>$pageCount);
    }

    public function getDashboardCounts()
    {
        $messages = PublicMailStorageService::getMessages(); $workflow = PublicMailStorageService::getWorkflow();
        $counts = array('all'=>0,'unread'=>0,'urgent'=>0,'unclassified'=>0,'unassigned'=>0,'unfinished'=>0);
        foreach ($messages as $key=>$message) {
            if (!is_array($message)) continue; $counts['all']++;
            if (empty($message['is_seen'])) $counts['unread']++;
            $classification = isset($message['classification']) && is_array($message['classification']) ? $message['classification'] : array();
            $itemWorkflow = $this->workflowFromMap($workflow, $key);
            if (isset($classification['priority']) && $classification['priority']==='긴급') $counts['urgent']++;
            if (empty($classification['department']) || $classification['department']==='미분류') $counts['unclassified']++;
            if (empty($itemWorkflow['assignee_id']) && empty($itemWorkflow['assignee_name'])) $counts['unassigned']++;
            if (!in_array($itemWorkflow['status'], array('처리완료','발송완료'), true)) $counts['unfinished']++;
        }
        return $counts;
    }

    public function getMessageDetail($messageKey)
    {
        $messageKey = (string)$messageKey;
        $messages = PublicMailStorageService::getMessages();
        if (!isset($messages[$messageKey])) throw new \RuntimeException('저장된 메일 정보를 찾을 수 없습니다.');
        $parsedKey = PublicMailStorageService::parseMessageKey($messageKey);
        $message = $messages[$messageKey];
        $mailbox = isset($message['mailbox']) ? (string)$message['mailbox'] : $parsedKey['mailbox'];
        $uid = isset($message['uid']) ? (int)$message['uid'] : (int)$parsedKey['uid'];
        $raw = PublicMailStorageService::getCachedRawMessage($messageKey, 1800);
        if ($raw === '') {
            $settings = PublicMailStorageService::getSettings(true); $client = $this->createClient($settings);
            try {
                $client->connect(); $client->login($settings['username'],$settings['password']); $client->selectMailbox($mailbox);
                $raw = $client->fetchRawMessage($uid, $this->rawFetchLimitForMessage($message));
                PublicMailStorageService::saveCachedRawMessage($messageKey, $raw);
            } finally { $client->logout(); }
        }
        $parsed = $this->parseRawMessage($raw);
        $detail = $this->normalizeStoredMessage($message);
        $detail['message_key']=$messageKey; $detail['uid']=$uid; $detail['mailbox']=$mailbox;
        $detail['mailbox_name']=isset($message['mailbox_name'])?$message['mailbox_name']:($mailbox==='INBOX'?'받은메일함':$mailbox);
        $detail['body_text']=$parsed['body_text']; $detail['body_html']=$parsed['body_html']; $detail['attachments']=$parsed['attachments'];
        $detail['workflow']=PublicMailStorageService::getWorkflowForKey($messageKey);
        foreach (array('subject','from_text','from_email','to_text','cc_text') as $field) if (isset($parsed[$field]) && trim((string)$parsed[$field])!=='') { $detail[$field]=$parsed[$field]; $messages[$messageKey][$field]=$parsed[$field]; }
        $preview=$this->makePreviewText($parsed['body_text']); if ($preview!=='') { $detail['preview']=$preview; $messages[$messageKey]['preview']=$preview; }
        $messages[$messageKey]['has_attachment']=!empty($parsed['attachments']); PublicMailStorageService::saveMessages($messages);
        return $detail;
    }

    public function getAttachment($messageKey, $partId)
    {
        $messageKey=(string)$messageKey; $partId=trim((string)$partId);
        $messages=PublicMailStorageService::getMessages();
        if (!isset($messages[$messageKey])) throw new \RuntimeException('메일 정보를 찾을 수 없습니다.');
        $cached=PublicMailStorageService::getCachedAttachment($messageKey,$partId,21600); if (is_array($cached)) return $cached;
        $parsedKey=PublicMailStorageService::parseMessageKey($messageKey); $message=$messages[$messageKey];
        $mailbox=isset($message['mailbox'])?(string)$message['mailbox']:$parsedKey['mailbox']; $uid=isset($message['uid'])?(int)$message['uid']:(int)$parsedKey['uid'];
        $settings=PublicMailStorageService::getSettings(true); $client=$this->createClient($settings);
        try {
            $client->connect(); $client->login($settings['username'],$settings['password']); $client->selectMailbox($mailbox);
            $mimeHeader=$client->fetchMimeHeader($uid,$partId); $encodedBody=$client->fetchMimePart($uid,$partId,157286400);
        } finally { $client->logout(); }
        $headers=$this->parseHeaders($mimeHeader);
        $contentType=isset($headers['content-type'])?$headers['content-type']:'application/octet-stream';
        $disposition=isset($headers['content-disposition'])?$headers['content-disposition']:'';
        $typeInfo=$this->parseHeaderWithParameters($contentType); $dispInfo=$this->parseHeaderWithParameters($disposition);
        $filename=''; if (isset($dispInfo['params']['filename'])) $filename=$dispInfo['params']['filename']; elseif (isset($typeInfo['params']['name'])) $filename=$typeInfo['params']['name'];
        $filename=$this->safeFilename($this->decodeHeader(trim((string)$filename," \t\r\n\"'")));
        $encoding=isset($headers['content-transfer-encoding'])?strtolower(trim((string)$headers['content-transfer-encoding'])):'';
        $content=$this->decodeBody($encodedBody,$encoding);
        if ($content==='') throw new \RuntimeException('첨부파일 내용이 비어 있습니다.');
        $attachment=array('part_id'=>$partId,'filename'=>$filename,'mime_type'=>isset($typeInfo['value'])&&$typeInfo['value']!==''?strtolower($typeInfo['value']):'application/octet-stream','size'=>strlen($content),'content'=>$content);
        PublicMailStorageService::saveCachedAttachment($messageKey,$partId,$attachment);
        return $attachment;
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
        $pdo = $this->getPdoSafely();
        if (!$pdo) {
            return array();
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

            return $result;
        } catch (\Exception $e) {
            return array();
        }
    }

    public function getProjects()
    {
        $pdo = $this->getPdoSafely();
        if (!$pdo) {
            return array();
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
                    return $result;
                }
            } catch (\Exception $e) {
                // 다음 후보 테이블을 확인합니다.
            }
        }

        return array();
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


    public function getSyncState()
    {
        return PublicMailStorageService::getSyncState();
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
            'body_text'=>$bodyText,'body_html'=>$this->sanitizeHtml($bodyHtml),'attachments'=>$attachments,'headers'=>$rootHeaders,
            'subject'=>$this->decodeHeader(isset($rootHeaders['subject'])?$rootHeaders['subject']:''),
            'from_text'=>$fromText,'from_email'=>$this->extractEmail($fromText),
            'to_text'=>$this->decodeHeader(isset($rootHeaders['to'])?$rootHeaders['to']:''),
            'cc_text'=>$this->decodeHeader(isset($rootHeaders['cc'])?$rootHeaders['cc']:'')
        );
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

    private function createClient($settings)
    {
        return new PublicMailImapClient(isset($settings['imap_host'])?$settings['imap_host']:'imap.naver.com',isset($settings['imap_port'])?$settings['imap_port']:993,20);
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
            $result[]=array('raw_name'=>$raw,'display_name'=>$display,'flags'=>$flags);
        }
        if (empty($result)) $result[]=array('raw_name'=>'INBOX','display_name'=>'받은메일함','flags'=>array());
        return $result;
    }

    private function mailboxMatches($raw,$display,$flags,$terms)
    {
        $haystack=strtolower((string)$raw.' '.(string)$display.' '.implode(' ',$flags));
        foreach ($terms as $term) if (strpos($haystack,strtolower($term))!==false) return true;
        return false;
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
            'message_key'=>$key,'uid'=>$uid,'mailbox'=>$mailbox,'mailbox_name'=>$mailboxName,
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

        // PHP 서버의 iconv가 KS_C_5601-1987을 그대로 반환하는 경우가 있어
        // encoded-word를 직접 해석한 뒤 UTF-8로 변환합니다.
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
            return $self->convertToUtf8($raw, $charset);
        }, $value);
        if (is_string($manual) && $manual !== '' && strpos($manual, '=?') === false) {
            return $this->ensureUtf8($manual, '');
        }

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false && $decoded !== '' && strpos($decoded, '=?') === false) {
                return $this->ensureUtf8($decoded, '');
            }
        }
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if ($decoded !== false && $decoded !== '' && strpos($decoded, '=?') === false) {
                return $this->ensureUtf8($decoded, '');
            }
        }

        return $this->ensureUtf8(is_string($manual) ? $manual : $value, '');
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
            return $value;
        }

        $candidates = array();
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') $candidates[] = $charset;
        foreach (array('CP949','EUC-KR','ISO-8859-1') as $candidate) {
            if (!in_array($candidate, $candidates, true)) $candidates[] = $candidate;
        }

        foreach ($candidates as $candidate) {
            if (function_exists('iconv')) {
                $converted = @iconv($candidate, 'UTF-8//IGNORE', $value);
                if ($converted !== false && $converted !== '' && $this->isValidUtf8($converted)) return $converted;
            }
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', $candidate);
                if ($converted !== false && $converted !== '' && $this->isValidUtf8($converted)) return $converted;
            }
        }

        // 화면 전체가 깨지는 것을 막기 위한 마지막 안전장치
        if ($this->isValidUtf8($value)) return $value;
        return preg_replace('/[\x80-\xFF]/', '?', $value);
    }

    private function sanitizeHtml($html)
    {
        $html = (string)$html;
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link)[^>]*/?>#is', '', $html);

        // 외부 이미지와 링크는 열람만으로 추적 요청이 발생할 수 있으므로 제거합니다.
        // 링크의 글자와 이미지의 대체문구는 가능한 한 본문에 남깁니다.
        $html = preg_replace_callback('#<img\b[^>]*\balt\s*=\s*(["\'])(.*?)\1[^>]*>#is', function ($matches) {
            return isset($matches[2]) && trim((string)$matches[2]) !== ''
                ? ' [이미지: ' . htmlspecialchars(strip_tags($matches[2]), ENT_QUOTES, 'UTF-8') . '] '
                : ' [외부 이미지 제거] ';
        }, $html);
        $html = preg_replace('#<img\b[^>]*>#is', ' [외부 이미지 제거] ', $html);
        $html = preg_replace('#</?a\b[^>]*>#is', '', $html);
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/\s(style|class|id|src|srcset|background)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        $allowed = '<p><br><div><span><strong><b><em><i><u><ul><ol><li><table><thead><tbody><tr><th><td><hr><blockquote><pre><code><h1><h2><h3><h4><h5><h6>';
        return strip_tags($html, $allowed);
    }

    private function makePreviewText($rawText)
    {
        $text = $this->ensureUtf8((string)$rawText, '');
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


    /** 기존 버전에서 저장된 깨진 제목 또는 빈 미리보기를 조금씩 복구합니다. */
    public function repairBrokenMetadataBatch($limit)
    {
        $limit=max(1,min(20,(int)$limit)); $messages=PublicMailStorageService::getMessages(); $targets=array();
        foreach ($messages as $key=>$message) {
            if (!is_array($message)) continue; $subject=isset($message['subject'])?(string)$message['subject']:''; $preview=isset($message['preview'])?(string)$message['preview']:'';
            $broken=$preview===''||strpos($subject,'=?')!==false||!$this->isValidUtf8($subject)||substr_count($preview,'?')>12;
            if ($broken) { $targets[]=(string)$key; if (count($targets)>=$limit) break; }
        }
        if (empty($targets)) return 0;
        $settings=PublicMailStorageService::getSettings(true); $client=$this->createClient($settings); $repaired=0; $selected='';
        try {
            $client->connect(); $client->login($settings['username'],$settings['password']);
            foreach ($targets as $key) {
                try {
                    $parsed=PublicMailStorageService::parseMessageKey($key); $message=$messages[$key];
                    $mailbox=isset($message['mailbox'])?(string)$message['mailbox']:$parsed['mailbox']; $uid=isset($message['uid'])?(int)$message['uid']:(int)$parsed['uid'];
                    if ($selected!==$mailbox) { $client->selectMailbox($mailbox); $selected=$mailbox; }
                    $box=array('raw_name'=>$mailbox,'display_name'=>isset($message['mailbox_name'])?$message['mailbox_name']:($mailbox==='INBOX'?'받은메일함':$mailbox));
                    $header=$client->fetchHeader($uid); $fresh=$this->buildMessageFromHeader($header,$box['raw_name'],$box['display_name']);
                    $previewRaw=$client->fetchRawPreview($uid,65536);
                    if ($previewRaw!=='') { $body=$this->parseRawMessage($previewRaw,false); $fresh['preview']=$this->makePreviewText($body['body_text']); $fresh['has_attachment']=!empty($body['attachments'])||$this->rawMessageLooksLikeAttachment($previewRaw); }
                    $fresh['classification']=isset($message['classification'])?$message['classification']:array(); $fresh['synced_at']=isset($message['synced_at'])?$message['synced_at']:date('Y-m-d H:i:s');
                    $messages[$key]=array_merge($message,$fresh); $repaired++;
                } catch (\Exception $ignored) {}
            }
        } finally { $client->logout(); }
        if ($repaired>0) PublicMailStorageService::saveMessages($messages); return $repaired;
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
