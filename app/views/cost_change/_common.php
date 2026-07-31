<?php
/**
 * 비용 변경 승인 화면 공통 도우미.
 * - 레이아웃 출력 이후 redirect가 실패해 빈 화면이 되는 문제 방지
 * - 비용 변경 화면의 치명적 오류를 사용자에게 보이는 안내로 전환
 * - PHP 5.6 호환
 */

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;

require_once __DIR__ . '/../../bootstrap.php';

if (!function_exists('cpms_cost_change_visible_panel')) {
function cpms_cost_change_visible_panel($title, $message, $url, $buttonLabel, $detail)
{
    $title = trim((string)$title);
    $message = trim((string)$message);
    $url = trim((string)$url);
    $buttonLabel = trim((string)$buttonLabel);
    $detail = trim((string)$detail);

    if ($title === '') $title = '비용 변경 화면을 열 수 없습니다.';
    if ($buttonLabel === '') $buttonLabel = '돌아가기';

    echo '<div style="max-width:900px;margin:24px auto;padding:24px;border:1px solid #fecaca;border-radius:16px;background:#fff7ed;color:#7c2d12;font-family:Arial,\'Noto Sans KR\',sans-serif;box-sizing:border-box;">';
    echo '<div style="font-size:21px;font-weight:800;margin-bottom:10px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div style="font-size:14px;line-height:1.7;color:#7c2d12;white-space:pre-wrap;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';

    if ($detail !== '') {
        echo '<details style="margin-top:14px;padding:12px;border:1px solid #fed7aa;border-radius:10px;background:#ffffff;">';
        echo '<summary style="cursor:pointer;font-weight:700;">개발자 확인정보</summary>';
        echo '<pre style="margin:10px 0 0;white-space:pre-wrap;word-break:break-all;font-size:12px;color:#7f1d1d;">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>';
        echo '</details>';
    }

    if ($url !== '') {
        echo '<div style="margin-top:18px;">';
        echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:10px 16px;border-radius:10px;background:#1f2937;color:#ffffff;text-decoration:none;font-size:14px;font-weight:800;">' . htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') . '</a>';
        echo '</div>';
    }

    echo '</div>';
}}

if (!defined('CPMS_COST_CHANGE_FATAL_HANDLER_REGISTERED')) {
    define('CPMS_COST_CHANGE_FATAL_HANDLER_REGISTERED', 1);

    register_shutdown_function(function () {
        $error = error_get_last();
        if (!is_array($error) || !isset($error['type'])) return;

        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        if (!in_array((int)$error['type'], $fatalTypes, true)) return;

        $route = isset($_GET['r']) ? trim((string)$_GET['r']) : '';
        $tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : '';
        $file = isset($error['file']) ? str_replace('\\', '/', (string)$error['file']) : '';

        $isCostChangeRequest = (strpos($route, 'cost_change/') === 0)
            || (($route === '관리' || $route === '관리자' || $route === 'admin') && $tab === 'cost_change')
            || strpos($file, '/cost_change/') !== false
            || substr($file, -21) === '/CostChangeService.php';

        if (!$isCostChangeRequest) return;

        $detail = (isset($error['message']) ? (string)$error['message'] : '알 수 없는 오류')
            . "\n파일: " . $file
            . "\n줄: " . (isset($error['line']) ? (int)$error['line'] : 0);

        error_log('[CPMS cost change fatal] ' . str_replace("\n", ' | ', $detail));

        $showDetail = false;
        if (class_exists('App\\Core\\Auth', false)) {
            try {
                $showDetail = Auth::isMaster() || trim((string)Auth::userDepartment()) === '개발';
            } catch (Exception $e) {
                $showDetail = false;
            }
        }

        if (!headers_sent()) http_response_code(500);

        cpms_cost_change_visible_panel(
            '비용 변경 화면에서 서버 오류가 발생했습니다.',
            '빈 화면으로 종료되지 않도록 오류를 표시했습니다. 개발부서 또는 서버 관리자에게 아래 내용을 전달해주세요.',
            '?r=관리&tab=cost_change',
            '비용 변경 관리로 이동',
            $showDetail ? $detail : ''
        );
    });
}

require_once __DIR__ . '/../../services/CostChangeService.php';

/*
 * 중요: PHP의 use 별칭은 선언된 현재 파일에서만 유효하다.
 * my.php, setup.php, approvals.php 등 다른 비용 변경 파일은
 * _common.php를 require해도 Auth / Db / CostChangeService 별칭을 상속받지 못한다.
 *
 * 기존 비용 변경 파일 전체를 일일이 수정하지 않고도 PHP 5.6에서
 * 동일하게 동작하도록 공통 파일에서 짧은 클래스명을 전역 별칭으로 연결한다.
 */
if (!class_exists('Auth', false) && class_exists('App\\Core\\Auth')) {
    class_alias('App\\Core\\Auth', 'Auth');
}

if (!class_exists('Db', false) && class_exists('App\\Core\\Db')) {
    class_alias('App\\Core\\Db', 'Db');
}

if (!class_exists('CostChangeService', false) && class_exists('App\\Services\\CostChangeService')) {
    class_alias('App\\Services\\CostChangeService', 'CostChangeService');
}

if (!function_exists('cpms_cost_change_redirect')) {
function cpms_cost_change_redirect($type, $message, $url)
{
    flash_set($type, $message);

    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    cpms_cost_change_visible_panel(
        $type === 'success' ? '처리가 완료되었습니다.' : '확인이 필요합니다.',
        (string)$message,
        (string)$url,
        '계속',
        ''
    );
    exit;
}}

if (!function_exists('cpms_cost_change_require_login')) {
function cpms_cost_change_require_login()
{
    if (Auth::check()) return true;

    $loginUrl = '?r=login&return=' . urlencode(cpms_current_absolute_url());

    if (!headers_sent()) {
        header('Location: ' . $loginUrl);
        exit;
    }

    cpms_cost_change_visible_panel(
        '로그인이 필요합니다.',
        '로그인 세션이 만료되었습니다. 다시 로그인한 뒤 이용해주세요.',
        $loginUrl,
        '로그인',
        ''
    );
    exit;
}}

if (!function_exists('cpms_cost_change_require_installed')) {
function cpms_cost_change_require_installed($pdo)
{
    if (!$pdo) {
        cpms_cost_change_visible_panel(
            '데이터베이스에 연결할 수 없습니다.',
            '비용 변경 승인 데이터를 조회할 수 없습니다. DB 연결 설정과 MySQL 실행 상태를 확인해주세요.',
            '?r=관리&tab=cost_change',
            '비용 변경 관리로 이동',
            ''
        );
        exit;
    }

    if (CostChangeService::isInstalled($pdo)) return true;

    $message = "비용 변경 승인용 데이터 구조가 아직 생성되지 않았습니다.\n"
        . "관리 → 비용 변경 관리에서 ‘구조 확인/초기화 실행’을 한 번 실행해주세요.";

    if (!headers_sent()) {
        cpms_cost_change_redirect('error', $message, '?r=관리&tab=cost_change');
    }

    cpms_cost_change_visible_panel(
        '비용 변경 승인 초기설정이 필요합니다.',
        $message,
        '?r=관리&tab=cost_change',
        '초기설정 화면으로 이동',
        ''
    );
    exit;
}}

if (!function_exists('cpms_cost_change_return_url')) {
function cpms_cost_change_return_url($value, $fallback)
{
    return cpms_safe_internal_redirect_url((string)$value, (string)$fallback);
}}

if (!function_exists('cpms_cost_change_money')) {
function cpms_cost_change_money($value)
{
    return number_format((float)$value) . '원';
}}

if (!function_exists('cpms_cost_change_date_label')) {
function cpms_cost_change_date_label($value)
{
    $value = trim((string)$value);
    return $value === '' ? '-' : $value;
}}

if (!function_exists('cpms_cost_change_diff_rows')) {
function cpms_cost_change_diff_rows($oldData, $newData)
{
    $labels = array(
        'use_date'=>'실제 사용일자',
        'settlement_ym'=>'귀속월',
        'category'=>'비용 구분',
        'vendor_name'=>'업체명',
        'item_name'=>'품명/작업내용',
        'quantity'=>'수량',
        'unit_price'=>'단가',
        'amount'=>'금액',
        'memo'=>'비고'
    );
    $rows = array();
    foreach ($labels as $key => $label) {
        $old = isset($oldData[$key]) ? $oldData[$key] : '';
        $new = isset($newData[$key]) ? $newData[$key] : '';
        if ($key === 'amount' || $key === 'unit_price') {
            $oldLabel = cpms_cost_change_money($old);
            $newLabel = cpms_cost_change_money($new);
        } else {
            $oldLabel = (string)$old;
            $newLabel = (string)$new;
        }
        $rows[] = array(
            'key'=>$key,
            'label'=>$label,
            'old'=>$oldLabel === '' ? '-' : $oldLabel,
            'new'=>$newLabel === '' ? '-' : $newLabel,
            'changed'=>((string)$old !== (string)$new)
        );
    }
    return $rows;
}}

if (!function_exists('cpms_cost_change_target_url')) {
function cpms_cost_change_target_url($request)
{
    if (!is_array($request)) return '?r=dashboard_employee';
    $projectId = isset($request['project_id']) ? (int)$request['project_id'] : 0;
    $targetType = isset($request['target_type']) ? (string)$request['target_type'] : '';
    if ($targetType === 'safety') return '?r=safety_home&pid=' . $projectId . '&tab=safety_cost#safety-cost-section';
    $tabMap = array(
        'material'=>'materials',
        'equipment'=>'equipment',
        'outsourcing'=>'outsourcing',
        'labor_force'=>'labor',
        'daily_cost'=>'cost_progress'
    );
    $tab = isset($tabMap[$targetType]) ? $tabMap[$targetType] : 'status';
    return '?r=construction_home&pid=' . $projectId . '&tab=' . urlencode($tab);
}}

if (!function_exists('cpms_cost_change_project_options')) {
function cpms_cost_change_project_options($pdo)
{
    $rows = array();
    if (!$pdo) return $rows;
    try {
        $st = $pdo->query("SELECT id,name FROM cpms_projects WHERE name NOT LIKE '(가제)%' ORDER BY name ASC,id ASC");
        $loaded = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        foreach ($loaded as $row) {
            if (!CostChangeService::canManageProject($pdo, (int)$row['id'], 'material')
                && !CostChangeService::canManageProject($pdo, (int)$row['id'], 'safety')) continue;
            $rows[] = $row;
        }
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}
