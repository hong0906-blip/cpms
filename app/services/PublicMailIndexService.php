<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailIndexService.php
 *
 * 네이버 메일 목록 화면을 빠르게 열기 위한 가벼운 색인을 관리합니다.
 * 원본 메일과 첨부파일은 저장하지 않고, 목록·통계에 필요한 값만 JSON으로 보관합니다.
 * PHP 5.6 호환 코드입니다.
 */

namespace App\Services;

require_once __DIR__ . '/PublicMailStorageService.php';

class PublicMailIndexService
{
    const VERSION = '1.7.16';
    const INDEX_VERSION = 6;
    const INDEX_FILE = 'mail_index.json';

    private $memoryIndex = null;

    public function getIndex($forceRebuild)
    {
        if (!$forceRebuild && is_array($this->memoryIndex)) {
            return $this->memoryIndex;
        }

        PublicMailStorageService::ensureStorage();
        $path = PublicMailStorageService::path(self::INDEX_FILE);
        $saved = PublicMailStorageService::readJsonFile($path, array());

        if (!$forceRebuild && $this->isValidIndex($saved)) {
            $this->memoryIndex = $saved;
            return $saved;
        }

        $this->memoryIndex = self::rebuild();
        return $this->memoryIndex;
    }

    public function getMessageList($filters, $page, $perPage)
    {
        $index = $this->getIndex(false);
        $items = isset($index['items']) && is_array($index['items']) ? $index['items'] : array();
        $matched = array();

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if ($this->matchesFilters($item, $filters)) $matched[] = $item;
        }

        $total = count($matched);
        $page = max(1, (int)$page);
        $perPage = (int)$perPage;
        if ($perPage < 10) $perPage = 30;
        if ($perPage > 100) $perPage = 100;
        $pageCount = max(1, (int)ceil($total / $perPage));
        if ($page > $pageCount) $page = $pageCount;

        return array(
            'items' => array_slice($matched, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'page_count' => $pageCount,
            'index_updated_at' => isset($index['updated_at']) ? (string)$index['updated_at'] : ''
        );
    }

    public function getDashboardCounts()
    {
        $index = $this->getIndex(false);
        $defaults = array('all'=>0,'unread'=>0,'urgent'=>0,'unclassified'=>0,'unassigned'=>0,'unfinished'=>0);
        return isset($index['counts']) && is_array($index['counts'])
            ? array_merge($defaults, $index['counts'])
            : $defaults;
    }

    public function getMessage($messageKey)
    {
        $messageKey = trim((string)$messageKey);
        if ($messageKey === '') return null;
        $index = $this->getIndex(false);
        $items = isset($index['items']) && is_array($index['items']) ? $index['items'] : array();
        $positions = isset($index['positions']) && is_array($index['positions']) ? $index['positions'] : array();

        if (isset($positions[$messageKey])) {
            $position = (int)$positions[$messageKey];
            if (isset($items[$position]) && is_array($items[$position])) return $items[$position];
        }

        foreach ($items as $item) {
            if (is_array($item) && isset($item['message_key']) && (string)$item['message_key'] === $messageKey) return $item;
        }
        return null;
    }

    public function getStatus()
    {
        $path = PublicMailStorageService::path(self::INDEX_FILE);
        $index = $this->getIndex(false);
        return array(
            'version' => isset($index['index_version']) ? (int)$index['index_version'] : 0,
            'package_version' => isset($index['package_version']) ? (string)$index['package_version'] : '',
            'updated_at' => isset($index['updated_at']) ? (string)$index['updated_at'] : '',
            'item_count' => isset($index['items']) && is_array($index['items']) ? count($index['items']) : 0,
            'file_exists' => is_file($path),
            'file_size' => is_file($path) ? (int)@filesize($path) : 0,
            'writable' => is_dir(dirname($path)) && is_writable(dirname($path))
        );
    }

    public static function rebuild($messages = null, $workflow = null)
    {
        PublicMailStorageService::ensureStorage();
        if (!is_array($messages)) $messages = PublicMailStorageService::getMessages();
        if (!is_array($workflow)) $workflow = PublicMailStorageService::getWorkflow();

        $items = array();
        $counts = array('all'=>0,'unread'=>0,'urgent'=>0,'unclassified'=>0,'unassigned'=>0,'unfinished'=>0);

        foreach ($messages as $messageKey => $message) {
            if (!is_array($message)) continue;
            $item = self::buildItem((string)$messageKey, $message, $workflow);
            $items[] = $item;

            $counts['all']++;
            if (empty($item['is_seen'])) $counts['unread']++;
            $classification = isset($item['classification']) && is_array($item['classification']) ? $item['classification'] : array();
            $itemWorkflow = isset($item['workflow']) && is_array($item['workflow']) ? $item['workflow'] : array();
            $effectivePriority = !empty($itemWorkflow['priority']) ? (string)$itemWorkflow['priority'] : (isset($classification['priority']) ? (string)$classification['priority'] : '보통');
            $effectiveDepartment = !empty($itemWorkflow['department']) ? (string)$itemWorkflow['department'] : (isset($classification['department']) ? (string)$classification['department'] : '');
            if ($effectivePriority === '긴급') $counts['urgent']++;
            if ($effectiveDepartment === '' || $effectiveDepartment === '미분류') $counts['unclassified']++;
            if (empty($itemWorkflow['assignee_id']) && empty($itemWorkflow['assignee_name'])) $counts['unassigned']++;
            if (!isset($itemWorkflow['status']) || !in_array($itemWorkflow['status'], array('처리완료','발송완료'), true)) $counts['unfinished']++;
        }

        usort($items, array(__CLASS__, 'compareItems'));
        $positions = array();
        foreach ($items as $position => $item) {
            if (isset($item['message_key'])) $positions[(string)$item['message_key']] = (int)$position;
        }

        $index = array(
            'index_version' => self::INDEX_VERSION,
            'package_version' => self::VERSION,
            'updated_at' => date('Y-m-d H:i:s'),
            'source_signature' => self::sourceSignature(),
            'counts' => $counts,
            'positions' => $positions,
            'items' => $items
        );
        PublicMailStorageService::writeJsonFile(PublicMailStorageService::path(self::INDEX_FILE), $index);
        return $index;
    }

    public static function rebuildSafely($messages = null, $workflow = null)
    {
        try {
            return self::rebuild($messages, $workflow);
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function compareItems($a, $b)
    {
        $at = isset($a['timestamp']) ? (int)$a['timestamp'] : 0;
        $bt = isset($b['timestamp']) ? (int)$b['timestamp'] : 0;
        if ($at === $bt) {
            $ak = isset($a['message_key']) ? (string)$a['message_key'] : '';
            $bk = isset($b['message_key']) ? (string)$b['message_key'] : '';
            return strcmp($bk, $ak);
        }
        return $at > $bt ? -1 : 1;
    }

    private function isValidIndex($index)
    {
        if (!is_array($index)) return false;
        if (!isset($index['index_version']) || (int)$index['index_version'] !== self::INDEX_VERSION) return false;
        if (!isset($index['items']) || !is_array($index['items'])) return false;
        if (!isset($index['source_signature']) || !is_array($index['source_signature'])) return false;
        if ($index['source_signature'] !== self::sourceSignature()) {
            $state = PublicMailStorageService::getSyncState();
            /* 제목 재수집 중에는 마지막 완성 색인을 그대로 사용해 메뉴 진입 속도를 유지합니다. */
            if (empty($state['title_refresh']['active'])) return false;
        }
        return true;
    }

    private static function sourceSignature()
    {
        return array(
            'messages' => self::fileSignature(PublicMailStorageService::path(PublicMailStorageService::MESSAGES_FILE)),
            'workflow' => self::fileSignature(PublicMailStorageService::path(PublicMailStorageService::WORKFLOW_FILE))
        );
    }

    private static function fileSignature($path)
    {
        clearstatcache(true, $path);
        return array(
            'size' => is_file($path) ? (int)@filesize($path) : 0,
            'mtime' => is_file($path) ? (int)@filemtime($path) : 0
        );
    }

    private static function buildItem($messageKey, $message, $workflowMap)
    {
        $parsed = PublicMailStorageService::parseMessageKey($messageKey);
        $mailbox = isset($message['mailbox']) && trim((string)$message['mailbox']) !== '' ? (string)$message['mailbox'] : (string)$parsed['mailbox'];
        if ($mailbox === '') $mailbox = 'INBOX';
        $mailboxName = isset($message['mailbox_name']) && trim((string)$message['mailbox_name']) !== ''
            ? (string)$message['mailbox_name'] : (strcasecmp($mailbox, 'INBOX') === 0 ? '받은메일함' : $mailbox);
        $mailboxType = isset($message['mailbox_type']) && trim((string)$message['mailbox_type']) !== ''
            ? (string)$message['mailbox_type'] : self::detectMailboxType($mailbox, $mailboxName);
        $timestamp = isset($message['timestamp']) ? (int)$message['timestamp'] : 0;
        $dateText = isset($message['date_text']) ? (string)$message['date_text'] : (isset($message['date']) ? (string)$message['date'] : '');
        if ($timestamp <= 0 && $dateText !== '') {
            $parsedTime = @strtotime($dateText);
            if ($parsedTime !== false) $timestamp = (int)$parsedTime;
        }
        if ($dateText === '' && $timestamp > 0) $dateText = date('Y-m-d H:i:s', $timestamp);

        $classification = isset($message['classification']) && is_array($message['classification']) ? $message['classification'] : array();
        $workflow = self::workflowFromMap($workflowMap, $messageKey);
        $subject = isset($message['subject']) ? trim((string)$message['subject']) : '';
        if ($subject === '') $subject = '(제목 없음)';
        $fromText = isset($message['from_text']) ? trim((string)$message['from_text']) : '';
        $toText = isset($message['to_text']) ? trim((string)$message['to_text']) : '';
        $ccText = isset($message['cc_text']) ? trim((string)$message['cc_text']) : '';
        $preview = isset($message['preview']) ? trim((string)$message['preview']) : '';

        return array(
            'message_key' => $messageKey,
            'uid' => isset($message['uid']) ? (int)$message['uid'] : (int)$parsed['uid'],
            'mailbox' => $mailbox,
            'mailbox_name' => $mailboxName,
            'mailbox_type' => $mailboxType,
            'timestamp' => $timestamp,
            'date_text' => $dateText,
            'subject' => $subject,
            'from_text' => $fromText,
            'from_email' => isset($message['from_email']) ? (string)$message['from_email'] : '',
            'to_text' => $toText,
            'cc_text' => $ccText,
            'preview' => $preview,
            'is_seen' => !empty($message['is_seen']),
            'size' => isset($message['size']) ? (int)$message['size'] : 0,
            'classification' => $classification,
            'workflow' => $workflow,
            'search_text' => self::lower($subject . ' ' . $fromText . ' ' . (isset($message['to_text']) ? (string)$message['to_text'] : '') . ' ' . $preview)
        );
    }

    /** 제목 한 건만 바뀐 경우 전체 색인을 다시 계산하지 않고 해당 항목만 갱신합니다. */
    public function refreshMessage($messageKey, $message)
    {
        $messageKey = trim((string)$messageKey);
        if ($messageKey === '' || !is_array($message)) return null;
        PublicMailStorageService::ensureStorage();
        $path = PublicMailStorageService::path(self::INDEX_FILE);
        $index = PublicMailStorageService::readJsonFile($path, array());
        if (!is_array($index) || !isset($index['items']) || !is_array($index['items']) || !isset($index['index_version']) || (int)$index['index_version'] !== self::INDEX_VERSION) {
            $this->memoryIndex = self::rebuild();
            return $this->memoryIndex;
        }

        $workflowMap = PublicMailStorageService::getWorkflow();
        $item = self::buildItem($messageKey, $message, $workflowMap);
        $positions = isset($index['positions']) && is_array($index['positions']) ? $index['positions'] : array();
        if (isset($positions[$messageKey]) && isset($index['items'][(int)$positions[$messageKey]])) {
            $index['items'][(int)$positions[$messageKey]] = $item;
        } else {
            $index['items'][] = $item;
            usort($index['items'], array(__CLASS__, 'compareItems'));
        }
        $index['positions'] = array();
        foreach ($index['items'] as $position => $current) {
            if (is_array($current) && isset($current['message_key'])) $index['positions'][(string)$current['message_key']] = (int)$position;
        }
        $index['updated_at'] = date('Y-m-d H:i:s');
        $index['package_version'] = self::VERSION;
        $index['source_signature'] = self::sourceSignature();
        PublicMailStorageService::writeJsonFile($path, $index);
        $this->memoryIndex = $index;
        return $item;
    }

    private static function workflowFromMap($workflowMap, $messageKey)
    {
        $default = array(
            'department'=>'','project_id'=>'','project_name'=>'','assignee_id'=>'','assignee_name'=>'',
            'status'=>'미확인','priority'=>'보통','important'=>false,'memo'=>'',
            'reply_completed'=>false,'reply_completed_at'=>'','reply_completed_by'=>'',
            'updated_at'=>'','updated_by'=>''
        );
        return isset($workflowMap[$messageKey]) && is_array($workflowMap[$messageKey])
            ? array_merge($default, $workflowMap[$messageKey]) : $default;
    }

    private static function detectMailboxType($mailbox, $displayName)
    {
        $value = self::lower((string)$mailbox . ' ' . (string)$displayName);
        if (strpos($value, 'sent') !== false || strpos($value, self::lower('보낸')) !== false) return 'sent';
        if (strpos($value, 'inbox') !== false || strpos($value, self::lower('받은')) !== false) return 'inbox';
        return 'custom';
    }

    private function matchesFilters($message, $filters)
    {
        if (!is_array($filters)) return true;
        $query = isset($filters['query']) ? trim((string)$filters['query']) : '';
        if ($query !== '') {
            $haystack = isset($message['search_text']) ? (string)$message['search_text'] : self::lower((isset($message['subject'])?$message['subject']:'').' '.(isset($message['from_text'])?$message['from_text']:'').' '.(isset($message['preview'])?$message['preview']:''));
            if (strpos($haystack, self::lower($query)) === false) return false;
        }
        $classification = isset($message['classification']) && is_array($message['classification']) ? $message['classification'] : array();
        $workflow = isset($message['workflow']) && is_array($message['workflow']) ? $message['workflow'] : array();
        $period = isset($filters['period']) ? trim((string)$filters['period']) : '';
        $cutoff = 0;
        if ($period === '1m') $cutoff = strtotime('-1 month');
        elseif ($period === '3m') $cutoff = strtotime('-3 months');
        elseif ($period === '6m') $cutoff = strtotime('-6 months');
        elseif ($period === '1y') $cutoff = strtotime('-1 year');
        if ($cutoff > 0 && isset($message['timestamp']) && (int)$message['timestamp'] < $cutoff) return false;

        $mailbox = isset($filters['mailbox']) ? trim((string)$filters['mailbox']) : '';
        if ($mailbox !== '' && (!isset($message['mailbox']) || (string)$message['mailbox'] !== $mailbox)) return false;
        $mailboxType = isset($filters['mailbox_type']) ? trim((string)$filters['mailbox_type']) : '';
        if ($mailboxType !== '' && (!isset($message['mailbox_type']) || (string)$message['mailbox_type'] !== $mailboxType)) return false;
        $department = isset($filters['department']) ? trim((string)$filters['department']) : '';
        if ($department !== '') {
            $actual = !empty($workflow['department']) ? $workflow['department'] : (isset($classification['department']) ? $classification['department'] : '');
            if ($actual !== $department) return false;
        }
        $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
        if ($status !== '' && (!isset($workflow['status']) || $workflow['status'] !== $status)) return false;
        $priority = isset($filters['priority']) ? trim((string)$filters['priority']) : '';
        if ($priority !== '') {
            $actualPriority = !empty($workflow['priority']) ? $workflow['priority'] : (isset($classification['priority']) ? $classification['priority'] : '');
            if ($actualPriority !== $priority) return false;
        }
        $projectId = isset($filters['project_id']) ? trim((string)$filters['project_id']) : '';
        if ($projectId !== '') {
            $actualProject = !empty($workflow['project_id']) ? (string)$workflow['project_id'] : (isset($classification['project_id']) ? (string)$classification['project_id'] : '');
            if ($actualProject !== $projectId) return false;
        }
        $assigneeId = isset($filters['assignee_id']) ? trim((string)$filters['assignee_id']) : '';
        if ($assigneeId !== '' && (!isset($workflow['assignee_id']) || (string)$workflow['assignee_id'] !== $assigneeId)) return false;

        $quick = isset($filters['quick']) ? trim((string)$filters['quick']) : '';
        if ($quick === 'unread' && !empty($message['is_seen'])) return false;
        $effectivePriority = !empty($workflow['priority']) ? $workflow['priority'] : (isset($classification['priority']) ? $classification['priority'] : '');
        $effectiveDepartment = !empty($workflow['department']) ? $workflow['department'] : (isset($classification['department']) ? $classification['department'] : '');
        if ($quick === 'urgent' && $effectivePriority !== '긴급') return false;
        if ($quick === 'unclassified' && $effectiveDepartment !== '' && $effectiveDepartment !== '미분류') return false;
        if ($quick === 'unassigned' && (!empty($workflow['assignee_id']) || !empty($workflow['assignee_name']))) return false;
        if ($quick === 'unfinished' && isset($workflow['status']) && in_array($workflow['status'], array('처리완료','발송완료'), true)) return false;
        return true;
    }

    private static function lower($value)
    {
        $value = (string)$value;
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
