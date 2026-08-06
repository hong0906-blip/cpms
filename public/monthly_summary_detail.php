<?php
/**
 * 파일: public/monthly_summary_detail.php
 * 공무 > 월별 투입비 집계 상세 모달 지연 로딩 API
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/project/partials/monthly_cost_detail_helper.php';
require_once __DIR__ . '/../app/views/project/partials/monthly_summary_snapshot_helper.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cpms_monthly_summary_detail_response($status, $payload) {
    http_response_code((int)$status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) $json = '{"ok":false,"message":"응답을 만들지 못했습니다."}';
    echo $json;
    exit;
}

if (!Auth::check()) {
    cpms_monthly_summary_detail_response(401, array('ok'=>false, 'message'=>'로그인이 필요합니다.'));
}

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$ym = isset($_GET['ym']) ? trim((string)$_GET['ym']) : '';
$snapshotDate = isset($_GET['snapshot_date']) ? trim((string)$_GET['snapshot_date']) : '';
if ($projectId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $ym)) {
    cpms_monthly_summary_detail_response(400, array('ok'=>false, 'message'=>'조회 조건이 올바르지 않습니다.'));
}
if ($snapshotDate !== '' && cpms_monthly_summary_snapshot_valid_date($snapshotDate) === '') $snapshotDate = '';

$pdo = Db::pdo();
if (!$pdo) {
    cpms_monthly_summary_detail_response(500, array('ok'=>false, 'message'=>'DB 연결을 확인하지 못했습니다.'));
}

try {
    $st = $pdo->prepare("SELECT id,name FROM cpms_projects WHERE id=:project_id AND name NOT LIKE '(가제)%' LIMIT 1");
    $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $st->execute();
    $project = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($project)) {
        cpms_monthly_summary_detail_response(404, array('ok'=>false, 'message'=>'현장을 찾을 수 없습니다.'));
    }

    $totals = array();
    if ($snapshotDate !== '') {
        $snapshotMap = cpms_monthly_summary_snapshot_load_map($pdo, $snapshotDate);
        if (isset($snapshotMap[$projectId]) && is_array($snapshotMap[$projectId])) {
            $snapshot = $snapshotMap[$projectId];
            $totals = array(
                'labor' => cpms_monthly_summary_snapshot_amount($snapshot, 'labor_amount'),
                'equipment' => cpms_monthly_summary_snapshot_amount($snapshot, 'equipment_amount'),
                'material' => cpms_monthly_summary_snapshot_amount($snapshot, 'purchase_amount')
                    + cpms_monthly_summary_snapshot_amount($snapshot, 'material_amount')
                    + cpms_monthly_summary_snapshot_amount($snapshot, 'other_expense_amount'),
                'outsourcing' => cpms_monthly_summary_snapshot_amount($snapshot, 'outsourcing_amount'),
            );
        }
    }

    $monthPayload = cpms_monthly_cost_detail_month_payload($pdo, $projectId, (string)$project['name'], $ym, $totals);
    $change = $snapshotDate !== ''
        ? cpms_monthly_summary_project_snapshot_change($pdo, $projectId, $snapshotDate, $ym)
        : array(
            'snapshot_date'=>'',
            'previous_date'=>'',
            'deltas'=>array('labor'=>0.0,'equipment'=>0.0,'material'=>0.0,'outsourcing'=>0.0,'monthly_total'=>0.0),
            'target_ids'=>array(),
            'target_deltas'=>array(),
        );
    $monthPayload = cpms_monthly_summary_apply_detail_change_context($monthPayload, $change);

    cpms_monthly_summary_detail_response(200, array(
        'ok'=>true,
        'project_id'=>$projectId,
        'project_name'=>(string)$project['name'],
        'ym'=>$ym,
        'month'=>$monthPayload,
    ));
} catch (Exception $e) {
    error_log('[Monthly summary detail] ' . $e->getMessage());
    cpms_monthly_summary_detail_response(500, array('ok'=>false, 'message'=>'상세 내역을 불러오지 못했습니다.'));
}
