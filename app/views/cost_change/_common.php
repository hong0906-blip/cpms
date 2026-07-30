<?php
/**
 * 비용 변경 승인 화면 공통 도우미.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/CostChangeService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;

if (!function_exists('cpms_cost_change_redirect')) {
function cpms_cost_change_redirect($type, $message, $url)
{
    flash_set($type, $message);
    header('Location: ' . $url);
    exit;
}}

if (!function_exists('cpms_cost_change_require_login')) {
function cpms_cost_change_require_login()
{
    if (!Auth::check()) {
        header('Location: ?r=login');
        exit;
    }
}}

if (!function_exists('cpms_cost_change_require_installed')) {
function cpms_cost_change_require_installed($pdo)
{
    if (!CostChangeService::isInstalled($pdo)) {
        cpms_cost_change_redirect('error', '비용 변경 승인 초기설정이 필요합니다.', '?r=관리&tab=cost_change');
    }
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

