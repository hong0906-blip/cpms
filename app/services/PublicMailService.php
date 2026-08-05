<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailService.php
 *
 * 공용메일 동기화, 조회, 본문/첨부파일 해석, Gmail 답장 연결을 담당합니다.
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

    public function getSettings($includePassword)
    {
        return PublicMailStorageService::getSettings($includePassword);
    }

    public function saveSettings($input, $updatedBy)
    {
        return PublicMailStorageService::saveSettings($input, $updatedBy);
    }

    public function testConnection($temporarySettings)
    {
        $settings = $this->mergeConnectionSettings($temporarySettings);
        $client = $this->createClient($settings);

        try {
            $client->connect();
            $client->login($settings['username'], $settings['password']);
            $mailbox = $client->selectMailbox('INBOX');
            return array(
                'ok' => true,
                'message' => '네이버 공용메일 연결이 정상입니다.',
                'mail_count' => isset($mailbox['exists']) ? (int)$mailbox['exists'] : 0
            );
        } finally {
            $client->logout();
        }
    }

    public function syncBatch($limit, $mode)
    {
        $settings = PublicMailStorageService::getSettings(true);
        if (empty($settings['enabled'])) {
            throw new \RuntimeException('공용메일 연동이 사용 상태가 아닙니다. 관리자 설정을 확인하세요.');
        }
        if (empty($settings['username']) || empty($settings['password'])) {
            throw new \RuntimeException('네이버 아이디 또는 애플리케이션 비밀번호가 설정되지 않았습니다.');
        }

        $limit = (int)$limit;
        if ($limit < 1) {
            $limit = isset($settings['batch_size']) ? (int)$settings['batch_size'] : 50;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $mode = $mode === 'new' ? 'new' : 'initial';
        $client = $this->createClient($settings);
        $existingMessages = PublicMailStorageService::getMessages();
        $projects = $this->getProjects();
        $newMessages = array();
        $gptUsed = 0;
        $gptLimitPerBatch = 3;
        $syncLock = PublicMailStorageService::acquireLock('sync');
        if ($syncLock === false) {
            throw new \RuntimeException('다른 사용자가 메일을 가져오는 중입니다. 잠시 후 다시 시도하세요.');
        }

        try {
            $client->connect();
            $client->login($settings['username'], $settings['password']);
            $mailboxInfo = $client->selectMailbox('INBOX');

            if ($mode === 'new') {
                $syncState = PublicMailStorageService::getSyncState();
                $lastUid = isset($syncState['last_uid']) ? (int)$syncState['last_uid'] : 0;
                $uids = $client->searchUidsAfter($lastUid);
            } else {
                $years = isset($settings['initial_years']) ? (int)$settings['initial_years'] : 1;
                if ($years < 1) {
                    $years = 1;
                }
                $sinceTimestamp = strtotime('-' . $years . ' year');
                $uids = $client->searchUidsSince($sinceTimestamp);
            }

            rsort($uids, SORT_NUMERIC);
            $missingUids = array();
            foreach ($uids as $uid) {
                $key = (string)(int)$uid;
                if (!isset($existingMessages[$key])) {
                    $missingUids[] = (int)$uid;
                    if (count($missingUids) >= $limit) {
                        break;
                    }
                }
            }

            foreach ($missingUids as $uid) {
                $headerData = $client->fetchHeader($uid);
                $message = $this->buildMessageFromHeader($headerData);
                $message['classification'] = $this->classifier->classify($message, $projects);

                if (
                    !isset($message['classification']['department'])
                    || $message['classification']['department'] === '미분류'
                    || (isset($message['classification']['department_score']) && (int)$message['classification']['department_score'] <= 1)
                ) {
                    try {
                        $previewRaw = $client->fetchTextPreview($uid, 32768);
                        $message['preview'] = $this->makePreviewText($previewRaw);
                        $message['classification'] = $this->classifier->classify($message, $projects);
                    } catch (\Exception $previewException) {
                        $message['preview'] = '';
                    }
                }

                if (!empty($settings['use_gpt_classifier']) && $this->isAmbiguousClassification($message['classification'])) {
                    if ($gptUsed < $gptLimitPerBatch) {
                        $gptClassification = $this->classifier->classifyAmbiguousWithGpt($message, $projects, $message['classification']);
                        if (is_array($gptClassification)) {
                            $message['classification'] = $gptClassification;
                            $gptUsed++;
                        } else {
                            $message['classification']['gpt_pending'] = true;
                        }
                    } else {
                        $message['classification']['gpt_pending'] = true;
                    }
                }

                $message['synced_at'] = date('Y-m-d H:i:s');
                $newMessages[(string)$uid] = $message;
            }

            if (!empty($newMessages)) {
                PublicMailStorageService::upsertMessages($newMessages);
            }

            $allKnownMessages = PublicMailStorageService::getMessages();
            $maximumUid = 0;
            foreach ($allKnownMessages as $knownUid => $knownMessage) {
                if ((int)$knownUid > $maximumUid) {
                    $maximumUid = (int)$knownUid;
                }
            }

            $remaining = 0;
            foreach ($uids as $uid) {
                if (!isset($allKnownMessages[(string)(int)$uid])) {
                    $remaining++;
                }
            }

            $previousState = PublicMailStorageService::getSyncState();
            $initialCompleted = ($mode === 'initial')
                ? ($remaining === 0)
                : (!empty($previousState['completed_initial_sync']));

            $state = PublicMailStorageService::saveSyncState(array(
                'last_success_at' => date('Y-m-d H:i:s'),
                'last_error_at' => '',
                'last_error' => '',
                'last_uid' => $maximumUid,
                'last_batch_count' => count($newMessages),
                'last_gpt_count' => $gptUsed,
                'last_search_count' => count($uids),
                'last_mode' => $mode,
                'mailbox_total' => isset($mailboxInfo['exists']) ? (int)$mailboxInfo['exists'] : 0,
                'remaining_count' => $remaining,
                'completed_initial_sync' => $initialCompleted
            ));

            return array(
                'ok' => true,
                'message' => count($newMessages) > 0
                    ? count($newMessages) . '개의 메일을 새로 가져왔습니다.'
                    : '새로 가져올 메일이 없습니다.',
                'added_count' => count($newMessages),
                'search_count' => count($uids),
                'remaining_count' => $remaining,
                'gpt_count' => $gptUsed,
                'state' => $state
            );
        } catch (\Exception $e) {
            PublicMailStorageService::saveSyncState(array(
                'last_error_at' => date('Y-m-d H:i:s'),
                'last_error' => $e->getMessage(),
                'last_mode' => $mode
            ));
            throw $e;
        } finally {
            $client->logout();
            PublicMailStorageService::releaseLock($syncLock);
        }
    }

    public function getMessageList($filters, $page, $perPage)
    {
        $messages = PublicMailStorageService::getMessages();
        $workflow = PublicMailStorageService::getWorkflow();
        $items = array();

        foreach ($messages as $uid => $message) {
            if (!is_array($message)) {
                continue;
            }

            $message['uid'] = (int)$uid;
            $message['workflow'] = isset($workflow[(string)$uid]) && is_array($workflow[(string)$uid])
                ? array_merge(PublicMailStorageService::getWorkflowForUid($uid), $workflow[(string)$uid])
                : PublicMailStorageService::getWorkflowForUid($uid);

            if ($this->matchesFilters($message, $filters)) {
                $items[] = $message;
            }
        }

        usort($items, array($this, 'compareMessageDateDesc'));

        $total = count($items);
        $page = max(1, (int)$page);
        $perPage = (int)$perPage;
        if ($perPage < 10) {
            $perPage = 30;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $pageCount = max(1, (int)ceil($total / $perPage));
        if ($page > $pageCount) {
            $page = $pageCount;
        }

        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return array(
            'items' => $paged,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'page_count' => $pageCount
        );
    }

    public function getDashboardCounts()
    {
        $messages = PublicMailStorageService::getMessages();
        $workflow = PublicMailStorageService::getWorkflow();
        $counts = array(
            'all' => 0,
            'unread' => 0,
            'urgent' => 0,
            'unclassified' => 0,
            'unassigned' => 0,
            'unfinished' => 0
        );

        foreach ($messages as $uid => $message) {
            if (!is_array($message)) {
                continue;
            }
            $counts['all']++;
            if (empty($message['is_seen'])) {
                $counts['unread']++;
            }

            $classification = isset($message['classification']) && is_array($message['classification'])
                ? $message['classification'] : array();
            if (isset($classification['priority']) && $classification['priority'] === '긴급') {
                $counts['urgent']++;
            }
            if (empty($classification['department']) || $classification['department'] === '미분류') {
                $counts['unclassified']++;
            }

            $itemWorkflow = isset($workflow[(string)$uid]) && is_array($workflow[(string)$uid])
                ? array_merge(PublicMailStorageService::getWorkflowForUid($uid), $workflow[(string)$uid])
                : PublicMailStorageService::getWorkflowForUid($uid);
            if (empty($itemWorkflow['assignee_id']) && empty($itemWorkflow['assignee_name'])) {
                $counts['unassigned']++;
            }
            if (!in_array($itemWorkflow['status'], array('처리완료', '발송완료'), true)) {
                $counts['unfinished']++;
            }
        }

        return $counts;
    }

    public function getMessageDetail($uid)
    {
        $uid = (int)$uid;
        $messages = PublicMailStorageService::getMessages();
        if ($uid <= 0 || !isset($messages[(string)$uid])) {
            throw new \RuntimeException('저장된 메일 정보를 찾을 수 없습니다.');
        }

        $settings = PublicMailStorageService::getSettings(true);
        $client = $this->createClient($settings);

        try {
            $client->connect();
            $client->login($settings['username'], $settings['password']);
            $client->selectMailbox('INBOX');
            $raw = $client->fetchRawMessage($uid, 31457280);
            $parsed = $this->parseRawMessage($raw);

            $detail = $messages[(string)$uid];
            $detail['uid'] = $uid;
            $detail['body_text'] = $parsed['body_text'];
            $detail['body_html'] = $parsed['body_html'];
            $detail['attachments'] = $parsed['attachments'];
            $detail['workflow'] = PublicMailStorageService::getWorkflowForUid($uid);

            return $detail;
        } finally {
            $client->logout();
        }
    }

    public function getAttachment($uid, $partId)
    {
        $uid = (int)$uid;
        $partId = trim((string)$partId);
        if ($uid <= 0 || $partId === '') {
            throw new \InvalidArgumentException('첨부파일 요청값이 올바르지 않습니다.');
        }

        $settings = PublicMailStorageService::getSettings(true);
        $client = $this->createClient($settings);

        try {
            $client->connect();
            $client->login($settings['username'], $settings['password']);
            $client->selectMailbox('INBOX');
            $raw = $client->fetchRawMessage($uid, 31457280);
            $parsed = $this->parseRawMessage($raw, true);

            foreach ($parsed['attachments'] as $attachment) {
                if (isset($attachment['part_id']) && (string)$attachment['part_id'] === $partId) {
                    return $attachment;
                }
            }

            throw new \RuntimeException('첨부파일을 찾을 수 없습니다.');
        } finally {
            $client->logout();
        }
    }

    public function updateWorkflow($uid, $changes, $updatedBy)
    {
        return PublicMailStorageService::updateWorkflow($uid, $changes, $updatedBy);
    }

    public function reclassify($uid)
    {
        $uid = (int)$uid;
        $messages = PublicMailStorageService::getMessages();
        if (!isset($messages[(string)$uid])) {
            throw new \RuntimeException('분류할 메일을 찾을 수 없습니다.');
        }

        $projects = $this->getProjects();
        $message = $messages[(string)$uid];
        $classification = $this->classifier->classify($message, $projects);
        $settings = PublicMailStorageService::getSettings(true);

        if (!empty($settings['use_gpt_classifier']) && $this->isAmbiguousClassification($classification)) {
            if (empty($message['preview'])) {
                try {
                    $client = $this->createClient($settings);
                    $client->connect();
                    $client->login($settings['username'], $settings['password']);
                    $client->selectMailbox('INBOX');
                    $message['preview'] = $this->makePreviewText($client->fetchTextPreview($uid, 32768));
                    $client->logout();
                } catch (\Exception $e) {
                    if (isset($client)) {
                        $client->logout();
                    }
                }
            }

            $gptClassification = $this->classifier->classifyAmbiguousWithGpt($message, $projects, $classification);
            if (is_array($gptClassification)) {
                $classification = $gptClassification;
            } else {
                $classification['gpt_pending'] = true;
            }
        }

        $messages[(string)$uid]['preview'] = isset($message['preview']) ? $message['preview'] : '';
        $messages[(string)$uid]['classification'] = $classification;
        PublicMailStorageService::saveMessages($messages);
        return $classification;
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
        $textBodies = array();
        $htmlBodies = array();
        $attachments = array();
        $this->collectMimeParts($root, $textBodies, $htmlBodies, $attachments);

        $bodyText = trim(implode("\n\n", $textBodies));
        $bodyHtml = trim(implode("<hr>", $htmlBodies));

        if ($bodyHtml === '' && $bodyText !== '') {
            $bodyHtml = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
        }
        if ($bodyText === '' && $bodyHtml !== '') {
            $bodyText = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8'));
        }

        return array(
            'body_text' => $bodyText,
            'body_html' => $this->sanitizeHtml($bodyHtml),
            'attachments' => $attachments,
            'headers' => isset($root['headers']) ? $root['headers'] : array()
        );
    }

    private function createClient($settings)
    {
        return new PublicMailImapClient(
            isset($settings['imap_host']) ? $settings['imap_host'] : 'imap.naver.com',
            isset($settings['imap_port']) ? $settings['imap_port'] : 993,
            20
        );
    }

    private function mergeConnectionSettings($temporarySettings)
    {
        $saved = PublicMailStorageService::getSettings(true);
        if (!is_array($temporarySettings)) {
            $temporarySettings = array();
        }

        foreach (array('username', 'password') as $field) {
            if (isset($temporarySettings[$field]) && trim((string)$temporarySettings[$field]) !== '') {
                $saved[$field] = trim((string)$temporarySettings[$field]);
            }
        }

        $saved['imap_host'] = 'imap.naver.com';
        $saved['imap_port'] = 993;

        if (empty($saved['username']) || empty($saved['password'])) {
            throw new \RuntimeException('네이버 아이디와 애플리케이션 비밀번호를 입력하세요.');
        }

        return $saved;
    }

    private function buildMessageFromHeader($headerData)
    {
        $headers = $this->parseHeaders(isset($headerData['header']) ? $headerData['header'] : '');
        $subject = $this->decodeHeader(isset($headers['subject']) ? $headers['subject'] : '');
        $fromText = $this->decodeHeader(isset($headers['from']) ? $headers['from'] : '');
        $toText = $this->decodeHeader(isset($headers['to']) ? $headers['to'] : '');
        $dateText = isset($headers['date']) ? trim((string)$headers['date']) : '';
        $timestamp = $dateText !== '' ? strtotime($dateText) : false;
        if ($timestamp === false) {
            $timestamp = time();
        }

        $flags = isset($headerData['flags']) && is_array($headerData['flags']) ? $headerData['flags'] : array();
        $isSeen = false;
        $isFlagged = false;
        foreach ($flags as $flag) {
            if (strcasecmp($flag, '\\Seen') === 0) {
                $isSeen = true;
            }
            if (strcasecmp($flag, '\\Flagged') === 0) {
                $isFlagged = true;
            }
        }

        return array(
            'uid' => isset($headerData['uid']) ? (int)$headerData['uid'] : 0,
            'message_id' => isset($headers['message-id']) ? trim((string)$headers['message-id']) : '',
            'subject' => $subject !== '' ? $subject : '(제목 없음)',
            'from_text' => $fromText,
            'from_email' => $this->extractEmail($fromText),
            'to_text' => $toText,
            'cc_text' => $this->decodeHeader(isset($headers['cc']) ? $headers['cc'] : ''),
            'date_text' => date('Y-m-d H:i:s', $timestamp),
            'timestamp' => (int)$timestamp,
            'size' => isset($headerData['size']) ? (int)$headerData['size'] : 0,
            'is_seen' => $isSeen,
            'is_flagged' => $isFlagged,
            'has_attachment' => false,
            'preview' => '',
            'classification' => array()
        );
    }

    private function matchesFilters($message, $filters)
    {
        if (!is_array($filters)) {
            return true;
        }

        $query = isset($filters['query']) ? trim((string)$filters['query']) : '';
        if ($query !== '') {
            $haystack = $this->lower(
                (isset($message['subject']) ? $message['subject'] : '') . ' '
                . (isset($message['from_text']) ? $message['from_text'] : '')
            );
            if (!$this->contains($haystack, $this->lower($query))) {
                return false;
            }
        }

        $classification = isset($message['classification']) && is_array($message['classification'])
            ? $message['classification'] : array();
        $workflow = isset($message['workflow']) && is_array($message['workflow'])
            ? $message['workflow'] : array();

        $period = isset($filters['period']) ? trim((string)$filters['period']) : '';
        $cutoff = 0;
        if ($period === '1m') {
            $cutoff = strtotime('-1 month');
        } elseif ($period === '3m') {
            $cutoff = strtotime('-3 months');
        } elseif ($period === '6m') {
            $cutoff = strtotime('-6 months');
        } elseif ($period === '1y') {
            $cutoff = strtotime('-1 year');
        }
        if ($cutoff > 0 && isset($message['timestamp']) && (int)$message['timestamp'] < $cutoff) {
            return false;
        }

        $department = isset($filters['department']) ? trim((string)$filters['department']) : '';
        if ($department !== '') {
            $actualDepartment = !empty($workflow['department'])
                ? (string)$workflow['department']
                : (isset($classification['department']) ? (string)$classification['department'] : '');
            if ($actualDepartment !== $department) {
                return false;
            }
        }

        $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
        if ($status !== '' && (!isset($workflow['status']) || (string)$workflow['status'] !== $status)) {
            return false;
        }

        $priority = isset($filters['priority']) ? trim((string)$filters['priority']) : '';
        if ($priority !== '') {
            $actualPriority = !empty($workflow['priority'])
                ? (string)$workflow['priority']
                : (isset($classification['priority']) ? (string)$classification['priority'] : '');
            if ($actualPriority !== $priority) {
                return false;
            }
        }

        $projectId = isset($filters['project_id']) ? trim((string)$filters['project_id']) : '';
        if ($projectId !== '') {
            $actualProjectId = !empty($workflow['project_id'])
                ? (string)$workflow['project_id']
                : (isset($classification['project_id']) ? (string)$classification['project_id'] : '');
            if ($actualProjectId !== $projectId) {
                return false;
            }
        }

        $assigneeId = isset($filters['assignee_id']) ? trim((string)$filters['assignee_id']) : '';
        if ($assigneeId !== '' && (!isset($workflow['assignee_id']) || (string)$workflow['assignee_id'] !== $assigneeId)) {
            return false;
        }

        $quick = isset($filters['quick']) ? trim((string)$filters['quick']) : '';
        if ($quick === 'unread' && !empty($message['is_seen'])) {
            return false;
        }
        if ($quick === 'urgent' && (!isset($classification['priority']) || $classification['priority'] !== '긴급')) {
            return false;
        }
        if ($quick === 'unclassified' && isset($classification['department']) && $classification['department'] !== '' && $classification['department'] !== '미분류') {
            return false;
        }
        if ($quick === 'unassigned' && (!empty($workflow['assignee_id']) || !empty($workflow['assignee_name']))) {
            return false;
        }
        if ($quick === 'unfinished' && isset($workflow['status']) && in_array($workflow['status'], array('처리완료', '발송완료'), true)) {
            return false;
        }

        return true;
    }

    private function isAmbiguousClassification($classification)
    {
        if (!is_array($classification)) {
            return true;
        }
        $department = isset($classification['department']) ? (string)$classification['department'] : '';
        $score = isset($classification['department_score']) ? (int)$classification['department_score'] : 0;
        return $department === '' || $department === '미분류' || $score <= 1;
    }

    public function compareMessageDateDesc($a, $b)
    {
        $aTime = isset($a['timestamp']) ? (int)$a['timestamp'] : 0;
        $bTime = isset($b['timestamp']) ? (int)$b['timestamp'] : 0;

        if ($aTime === $bTime) {
            return 0;
        }

        return $aTime > $bTime ? -1 : 1;
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

        if (!is_array($segments) || empty($segments)) {
            return $result;
        }

        $result['value'] = strtolower(trim(array_shift($segments)));
        foreach ($segments as $segment) {
            $position = strpos($segment, '=');
            if ($position === false) {
                continue;
            }
            $name = strtolower(trim(substr($segment, 0, $position)));
            $parameterValue = trim(substr($segment, $position + 1));
            $parameterValue = trim($parameterValue, " \t\r\n\"'");

            if (substr($name, -1) === '*') {
                $name = substr($name, 0, -1);
                $parameterValue = $this->decodeRfc2231Value($parameterValue);
            }

            if ($name !== '') {
                $result['params'][$name] = $parameterValue;
            }
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
                break;
            }
            if ($inside) {
                $current[] = $line;
            }
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
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        return $value;
    }

    private function decodeRfc2231Value($value)
    {
        $value = (string)$value;
        if (preg_match("/^([^']*)'[^']*'(.*)$/", $value, $matches)) {
            $charset = trim($matches[1]);
            $decoded = rawurldecode($matches[2]);
            return $this->convertToUtf8($decoded, $charset);
        }
        return rawurldecode($value);
    }

    private function convertToUtf8($value, $charset)
    {
        $value = (string)$value;
        $charset = trim((string)$charset);
        if ($charset === '' || strcasecmp($charset, 'UTF-8') === 0 || strcasecmp($charset, 'US-ASCII') === 0) {
            return $value;
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                return $converted;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $value;
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
        $text = (string)$rawText;
        if ($text === '') {
            return '';
        }

        if (stripos($text, 'Content-Transfer-Encoding: quoted-printable') !== false) {
            $text = quoted_printable_decode($text);
        }

        $text = preg_replace('/<[^>]+>/', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 1000, 'UTF-8');
        }

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
