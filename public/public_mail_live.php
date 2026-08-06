<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_live.php
 *
 * 열린 메일 목록에서 새로고침 없이 새 메일을 표시하기 위한 읽기 전용 주소입니다.
 * 네이버 IMAP에는 접속하지 않고 작은 mail_live_state.json과 mail_index.json만 확인합니다.
 * PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.19.1
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
if (ob_get_level() === 0) @ob_start();

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailIndexService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailIndexService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::requireLogin();
if (function_exists('session_write_close')) @session_write_close();

try {
    $mailboxType = isset($_GET['mailbox_type']) ? trim((string)$_GET['mailbox_type']) : '';
    if (!in_array($mailboxType, array('', 'inbox', 'sent'), true)) $mailboxType = '';
    $period = isset($_GET['period']) ? trim((string)$_GET['period']) : '1y';
    if (!in_array($period, array('1m','3m','6m','1y','all'), true)) $period = '1y';
    $filters = array(
        'query'=>isset($_GET['query']) ? trim((string)$_GET['query']) : '',
        'period'=>$period,
        'mailbox_type'=>$mailboxType
    );

    $knownKeys = array();
    $knownText = isset($_GET['known_keys']) ? trim((string)$_GET['known_keys']) : '';
    if ($knownText !== '') {
        foreach (explode(',', $knownText) as $key) {
            $key = trim((string)$key);
            if ($key !== '') $knownKeys[$key] = $key;
            if (count($knownKeys) >= 50) break;
        }
    }
    $knownKeys = array_values($knownKeys);

    $indexService = new PublicMailIndexService();
    if (!method_exists($indexService, 'getLiveUpdates')) {
        PublicMailWebHelper::jsonResponse(array(
            'ok'=>false,
            'retryable'=>true,
            'message'=>'새 메일 자동표시 파일이 일부만 적용되었습니다. 관리자에게 패치 재적용을 요청해 주세요.'
        ), 503);
    }
    $result = $indexService->getLiveUpdates(
        $filters,
        isset($_GET['revision']) ? (string)$_GET['revision'] : '',
        $knownKeys,
        isset($_GET['latest_timestamp']) ? (int)$_GET['latest_timestamp'] : 0,
        20
    );

    $html = '';
    $newItems = isset($result['new_items']) && is_array($result['new_items']) ? $result['new_items'] : array();
    if (!empty($newItems)) {
        $items = $newItems;
        $selectedMessageKey = '';
        $esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
        $queryBase = array('page'=>1);
        if ($mailboxType !== '') $queryBase['mailbox_type'] = $mailboxType;
        if ($period !== '') $queryBase['period'] = $period;
        if ($filters['query'] !== '') $queryBase['query'] = $filters['query'];
        $buildUrl = function ($changes) use ($queryBase) {
            $query = $queryBase;
            foreach ($changes as $key => $value) {
                if ($value === null || $value === '') unset($query[$key]);
                else $query[$key] = $value;
            }
            return 'public_mail.php?' . http_build_query($query, '', '&');
        };
        ob_start();
        include __DIR__ . '/../app/views/public_mail/_mail_rows.php';
        $html = (string)ob_get_clean();
    }

    PublicMailWebHelper::jsonResponse(array(
        'ok'=>true,
        'changed'=>!empty($result['changed']),
        'revision'=>isset($result['revision'])?(string)$result['revision']:'',
        'updated_at'=>isset($result['updated_at'])?(string)$result['updated_at']:'',
        'new_count'=>isset($result['new_count'])?(int)$result['new_count']:0,
        'html'=>$html,
        'head_keys'=>isset($result['head_keys'])&&is_array($result['head_keys'])?$result['head_keys']:array(),
        'latest_timestamp'=>isset($result['latest_timestamp'])?(int)$result['latest_timestamp']:0
    ), 200);
} catch (Exception $e) {
    PublicMailWebHelper::jsonResponse(array(
        'ok'=>false,
        'retryable'=>true,
        'message'=>'새 메일 자동표시 연결을 잠시 쉬었다가 다시 확인합니다.'
    ), 503);
}
