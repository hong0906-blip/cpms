<?php
/**
 * 관리 > 비용 변경 승인 웹 초기설정 처리.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';

cpms_cost_change_require_login();
if (!CostChangeService::canAdmin()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    cpms_cost_change_redirect('error', '보안 토큰이 올바르지 않습니다.', '?r=관리&tab=cost_change');
}

$pdo = Db::pdo();
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
if (!$pdo) cpms_cost_change_redirect('error', 'DB 연결에 실패했습니다.', '?r=관리&tab=cost_change');

if ($action === 'install') {
    $result = CostChangeService::installOrUpdate($pdo);
    cpms_cost_change_redirect(!empty($result['ok']) ? 'success' : 'error', isset($result['message']) ? $result['message'] : '초기설정 결과를 확인할 수 없습니다.', '?r=관리&tab=cost_change');
}

if ($action === 'approvers') {
    cpms_cost_change_require_installed($pdo);
    $result = CostChangeService::configureApprovers(
        $pdo,
        isset($_POST['first_approver_id']) ? (int)$_POST['first_approver_id'] : 0,
        isset($_POST['final_approver_id']) ? (int)$_POST['final_approver_id'] : 0
    );
    cpms_cost_change_redirect(!empty($result['ok']) ? 'success' : 'error', isset($result['message']) ? $result['message'] : '승인자 설정 결과를 확인할 수 없습니다.', '?r=관리&tab=cost_change');
}

if ($action === 'backfill') {
    cpms_cost_change_require_installed($pdo);
    $success = 0;
    $failed = 0;
    $stMeta = $pdo->prepare("INSERT IGNORE INTO cpms_cost_record_meta
        (target_type,target_id,project_id,actual_use_date,settlement_ym,manual_settlement_yn,manual_reason,quantity,unit_price,vendor_name_snapshot,item_name_snapshot,is_deleted,last_request_id,applied_data,created_at,updated_at)
        VALUES (:target_type,:target_id,:project_id,:actual_use_date,:settlement_ym,0,NULL,NULL,NULL,NULL,NULL,0,NULL,'{}',NOW(),NOW())");
    $sources = array(
        array('table'=>'cpms_material_usage', 'target_type'=>'material', 'cost_type'=>'material', 'date_column'=>'use_date'),
        array('table'=>'cpms_equipment_usage', 'target_type'=>'equipment', 'cost_type'=>'equipment', 'date_column'=>'use_date'),
        array('table'=>'cpms_outsourcing_costs', 'target_type'=>'outsourcing', 'cost_type'=>'outsourcing', 'date_column'=>'expense_date'),
        array('table'=>'cpms_daily_cost_entries', 'target_type'=>'daily_cost', 'cost_type'=>'daily_cost', 'date_column'=>'cost_date')
    );
    try {
        $pdo->beginTransaction();
        foreach ($sources as $source) {
            if (!CostChangeService::tableExists($pdo, $source['table'])) continue;
            $sql = "SELECT id,project_id," . $source['date_column'] . " AS actual_use_date";
            if ($source['table'] === 'cpms_daily_cost_entries') $sql .= ",cost_type";
            $sql .= " FROM " . $source['table'];
            $stRows = $pdo->query($sql);
            $rows = $stRows ? $stRows->fetchAll(PDO::FETCH_ASSOC) : array();
            foreach ($rows as $row) {
                try {
                    $costType = $source['cost_type'];
                    if ($source['table'] === 'cpms_daily_cost_entries' && isset($row['cost_type']) && (string)$row['cost_type'] === '노무') $costType = 'labor';
                    $ym = CostChangeService::settlementYm($costType, $row['actual_use_date']);
                    $stMeta->execute(array(
                        ':target_type'=>$source['target_type'],
                        ':target_id'=>(string)$row['id'],
                        ':project_id'=>(int)$row['project_id'],
                        ':actual_use_date'=>$row['actual_use_date'],
                        ':settlement_ym'=>$ym
                    ));
                    if ($stMeta->rowCount() > 0) $success++;
                } catch (Exception $rowError) {
                    $failed++;
                }
            }
        }
        if (CostChangeService::tableExists($pdo, 'cpms_labor_force_adjustments')) {
            $rows = $pdo->query("SELECT id,project_id,month FROM cpms_labor_force_adjustments")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                try {
                    $ym = CostChangeService::validYm($row['month']);
                    $stMeta->execute(array(
                        ':target_type'=>'labor_force', ':target_id'=>(string)$row['id'],
                        ':project_id'=>(int)$row['project_id'], ':actual_use_date'=>$ym . '-01', ':settlement_ym'=>$ym
                    ));
                    if ($stMeta->rowCount() > 0) $success++;
                } catch (Exception $rowError) {
                    $failed++;
                }
            }
        }
        $safetyHelper = __DIR__ . '/../safety/safety_cost_helper.php';
        if (is_file($safetyHelper)) require_once $safetyHelper;
        $safetyRows = function_exists('cpms_safety_cost_all_items') ? cpms_safety_cost_all_items() : array();
        foreach ($safetyRows as $row) {
            if (!is_array($row) || !isset($row['id']) || !isset($row['project_id'])) continue;
            try {
                $date = isset($row['use_date']) ? CostChangeService::validDate($row['use_date']) : '';
                $stMeta->execute(array(
                    ':target_type'=>'safety', ':target_id'=>(string)$row['id'],
                    ':project_id'=>(int)$row['project_id'], ':actual_use_date'=>$date !== '' ? $date : null,
                    ':settlement_ym'=>$date !== '' ? CostChangeService::settlementYm('safety', $date) : null
                ));
                if ($stMeta->rowCount() > 0) $success++;
            } catch (Exception $rowError) {
                $failed++;
            }
        }
        $pdo->commit();
        cpms_cost_change_redirect($failed > 0 ? 'error' : 'success', '기존자료 점진적 초기화 완료: 신규 메타 ' . $success . '건 / 실패 ' . $failed . '건. 원본 금액과 날짜는 변경하지 않았습니다.', '?r=관리&tab=cost_change');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cpms_cost_change_redirect('error', '기존자료 초기화 실패: ' . $e->getMessage(), '?r=관리&tab=cost_change');
    }
}

cpms_cost_change_redirect('error', '지원하지 않는 초기설정 작업입니다.', '?r=관리&tab=cost_change');

