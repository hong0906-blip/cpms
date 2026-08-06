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
    const VERSION = '1.7.19';
    const INDEX_VERSION = 7;
    const INDEX_FILE = 'mail_index.json';
    const LIVE_STATE_VERSION = 1;
    const LIVE_STATE_FILE = 'mail_live_state.json';

    private $memoryIndex = null;

    public function getIndex($forceRebuild)
    {
        if (!$forceRebuild && is_array($this->memoryIndex)) {
            return $this->applyTitleRefreshOverrides($this->memoryIndex);
        }

        PublicMailStorageService::ensureStorage();
        $path = PublicMailStorageService::path(self::INDEX_FILE);
        $saved = PublicMailStorageService::readJsonFile($path, array());

        if (!$forceRebuild && $this->isValidIndex($saved)) {
            $this->memoryIndex = $this->applyTitleRefreshOverrides($saved);
            return $this->memoryIndex;
        }

        $this->memoryIndex = self::rebuild();
        return $this->memoryIndex;
    }

    /**
     * 제목 복구 파일의 100여 건만 기존 색인 위치에 덮어씁니다.
     * mail_index.json 전체 재생성이나 디스크 저장은 하지 않습니다.
     */
    private function applyTitleRefreshOverrides($index)
    {
        if (!is_array($index) || !isset($index['items']) || !is_array($index['items'])) return $index;
        $subjectMap = PublicMailStorageService::getTitleRefreshSubjectMap();
        if (empty($subjectMap)) return $index;
        $positions = isset($index['positions']) && is_array($index['positions']) ? $index['positions'] : array();
        foreach ($subjectMap as $messageKey => $subject) {
            if (!isset($positions[$messageKey])) continue;
            $position = (int)$positions[$messageKey];
            if (!isset($index['items'][$position]) || !is_array($index['items'][$position])) continue;
            $item = $index['items'][$position];
            $item['subject'] = (string)$subject;
            $fromText = isset($item['from_text']) ? (string)$item['from_text'] : '';
            $toText = isset($item['to_text']) ? (string)$item['to_text'] : '';
            $preview = isset($item['preview']) ? (string)$item['preview'] : '';
            $item['search_text'] = self::lower((string)$subject . ' ' . $fromText . ' ' . $toText . ' ' . $preview);
            $index['items'][$position] = $item;
        }
        return $index;
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
            'index_updated_at' => isset($index['updated_at']) ? (string)$index['updated_at'] : '',
            'live_state' => $this->buildLiveClientState($index, $filters, 50)
        );
    }

    /**
     * 브라우저가 5초마다 읽는 아주 작은 상태파일입니다.
     * mail_index.json 전체를 매번 읽지 않고 변경번호만 확인합니다.
     */
    public function getLiveState($forceRefresh)
    {
        PublicMailStorageService::ensureStorage();
        $path = PublicMailStorageService::path(self::LIVE_STATE_FILE);
        if (!$forceRefresh) {
            $saved = PublicMailStorageService::readJsonFile($path, array());
            if (is_array($saved)
                && isset($saved['version']) && (int)$saved['version'] === self::LIVE_STATE_VERSION
                && !empty($saved['revision'])) {
                return $saved;
            }
        }

        $index = $this->getIndex(false);
        return self::writeLiveStateFromIndex($index);
    }

    /**
     * 현재 검색조건의 맨 앞 메일 키를 함께 내려줍니다.
     * 페이지가 2페이지이거나 검색 중이어도 새 메일 여부를 정확히 비교할 수 있습니다.
     */
    private function buildLiveClientState($index, $filters, $headLimit)
    {
        $headLimit = max(10, min(100, (int)$headLimit));
        $items = isset($index['items']) && is_array($index['items']) ? $index['items'] : array();
        $headKeys = array();
        $latestTimestamp = 0;
        foreach ($items as $item) {
            if (!is_array($item) || !$this->matchesFilters($item, $filters)) continue;
            if ($latestTimestamp <= 0) $latestTimestamp = isset($item['timestamp']) ? (int)$item['timestamp'] : 0;
            if (!empty($item['message_key'])) $headKeys[] = (string)$item['message_key'];
            if (count($headKeys) >= $headLimit) break;
        }
        $global = self::buildLiveStateFromIndex($index);
        return array(
            'version' => self::LIVE_STATE_VERSION,
            'revision' => isset($global['revision']) ? (string)$global['revision'] : '',
            'updated_at' => isset($global['updated_at']) ? (string)$global['updated_at'] : '',
            'item_count' => isset($global['item_count']) ? (int)$global['item_count'] : count($items),
            'latest_timestamp' => $latestTimestamp,
            'head_keys' => $headKeys
        );
    }

    /**
     * 변경번호가 달라졌을 때만 현재 검색조건의 앞부분을 비교합니다.
     * 새 메일 HTML은 별도 화면 조각에서 만들고 이 메서드는 데이터만 반환합니다.
     */
    public function getLiveUpdates($filters, $clientRevision, $knownHeadKeys, $clientLatestTimestamp, $limit)
    {
        $clientRevision = trim((string)$clientRevision);
        $clientLatestTimestamp = max(0, (int)$clientLatestTimestamp);
        $limit = max(1, min(20, (int)$limit));
        if (!is_array($knownHeadKeys)) $knownHeadKeys = array();

        $currentState = $this->getLiveState(false);
        $currentRevision = isset($currentState['revision']) ? (string)$currentState['revision'] : '';
        if ($clientRevision !== '' && $currentRevision !== '' && $clientRevision === $currentRevision) {
            return array(
                'changed'=>false,
                'revision'=>$currentRevision,
                'updated_at'=>isset($currentState['updated_at'])?(string)$currentState['updated_at']:'',
                'new_count'=>0,
                'new_items'=>array(),
                'head_keys'=>$knownHeadKeys,
                'latest_timestamp'=>$clientLatestTimestamp
            );
        }

        /* 같은 PHP 실행 안에서 색인이 갱신된 경우에도 최신 디스크 색인을 다시 읽습니다. */
        $this->memoryIndex = null;
        $index = $this->getIndex(false);
        $clientState = $this->buildLiveClientState($index, $filters, 50);
        $known = array();
        foreach ($knownHeadKeys as $key) {
            $key = trim((string)$key);
            if ($key !== '') $known[$key] = true;
        }

        $items = isset($index['items']) && is_array($index['items']) ? $index['items'] : array();
        $newItems = array();
        $newCount = 0;
        $matchedHead = 0;
        foreach ($items as $item) {
            if (!is_array($item) || !$this->matchesFilters($item, $filters)) continue;
            $matchedHead++;
            if ($matchedHead > 50) break;
            $key = isset($item['message_key']) ? trim((string)$item['message_key']) : '';
            if ($key === '' || isset($known[$key])) continue;

            /* 검색조건/업무상태 변경으로 오래된 행이 갑자기 새 메일처럼 보이는 것을 방지합니다. */
            $timestamp = isset($item['timestamp']) ? (int)$item['timestamp'] : 0;
            if ($clientLatestTimestamp > 0 && $timestamp + 5 < $clientLatestTimestamp) continue;

            $newCount++;
            if (count($newItems) < $limit) $newItems[] = $item;
        }

        return array(
            'changed'=>true,
            'revision'=>isset($clientState['revision'])?(string)$clientState['revision']:$currentRevision,
            'updated_at'=>isset($clientState['updated_at'])?(string)$clientState['updated_at']:'',
            'new_count'=>$newCount,
            'new_items'=>$newItems,
            'head_keys'=>isset($clientState['head_keys'])&&is_array($clientState['head_keys'])?$clientState['head_keys']:array(),
            'latest_timestamp'=>isset($clientState['latest_timestamp'])?(int)$clientState['latest_timestamp']:0
        );
    }

    private static function buildLiveStateFromIndex($index)
    {
        $items = isset($index['items']) && is_array($index['items']) ? $index['items'] : array();
        $head = array();
        $maximum = min(50, count($items));
        for ($i = 0; $i < $maximum; $i++) {
            if (is_array($items[$i]) && !empty($items[$i]['message_key'])) $head[] = (string)$items[$i]['message_key'];
        }
        $payload = array(
            'index_version'=>isset($index['index_version'])?(int)$index['index_version']:0,
            'updated_at'=>isset($index['updated_at'])?(string)$index['updated_at']:'',
            'item_count'=>count($items),
            'head_keys'=>$head,
            'source_signature'=>isset($index['source_signature'])&&is_array($index['source_signature'])?$index['source_signature']:array()
        );
        $encoded = json_encode($payload);
        if ($encoded === false) $encoded = serialize($payload);
        return array(
            'version'=>self::LIVE_STATE_VERSION,
            'package_version'=>self::VERSION,
            'revision'=>sha1((string)$encoded),
            'updated_at'=>isset($index['updated_at'])?(string)$index['updated_at']:'',
            'item_count'=>count($items),
            'latest_message_key'=>isset($head[0])?(string)$head[0]:'',
            'latest_timestamp'=>isset($items[0]['timestamp'])?(int)$items[0]['timestamp']:0
        );
    }

    private static function writeLiveStateFromIndex($index)
    {
        $state = self::buildLiveStateFromIndex($index);
        PublicMailStorageService::writeJsonFile(PublicMailStorageService::path(self::LIVE_STATE_FILE), $state);
        return $state;
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
        self::writeLiveStateFromIndex($index);
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
        self::writeLiveStateFromIndex($index);
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
