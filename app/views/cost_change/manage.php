<?php
/**
 * 관리섹션 비용 변경 전체이력 조회 전용 화면.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
if (!CostChangeService::canAdmin()) {
    http_response_code(403);
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 font-bold">관리자 조회 권한이 없습니다.</div>';
    return;
}
$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);

$filters = array(
    'date_from'=>isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '',
    'date_to'=>isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '',
    'project_id'=>isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0,
    'requester'=>isset($_GET['requester']) ? trim((string)$_GET['requester']) : '',
    'department'=>isset($_GET['department']) ? trim((string)$_GET['department']) : '',
    'cost_type'=>isset($_GET['cost_type']) ? trim((string)$_GET['cost_type']) : '',
    'request_type'=>isset($_GET['request_type']) ? trim((string)$_GET['request_type']) : '',
    'approver'=>isset($_GET['approver']) ? trim((string)$_GET['approver']) : '',
    'status'=>isset($_GET['status']) ? trim((string)$_GET['status']) : '',
    'approval_status'=>isset($_GET['approval_status']) ? trim((string)$_GET['approval_status']) : '',
    'process_result'=>isset($_GET['process_result']) ? trim((string)$_GET['process_result']) : '',
    'settlement_ym'=>isset($_GET['settlement_ym']) ? trim((string)$_GET['settlement_ym']) : ''
);
$where = array('1=1');
$params = array();
if (CostChangeService::validDate($filters['date_from']) !== '') { $where[] = 'created_at>=:date_from'; $params[':date_from']=$filters['date_from'] . ' 00:00:00'; }
if (CostChangeService::validDate($filters['date_to']) !== '') { $where[] = 'created_at<=:date_to'; $params[':date_to']=$filters['date_to'] . ' 23:59:59'; }
if ($filters['project_id'] > 0) { $where[] = 'project_id=:project_id'; $params[':project_id']=$filters['project_id']; }
if ($filters['requester'] !== '') { $where[] = '(requester_name LIKE :requester OR requester_email LIKE :requester)'; $params[':requester']='%' . $filters['requester'] . '%'; }
if ($filters['department'] !== '') { $where[] = 'request_department LIKE :department'; $params[':department']='%' . $filters['department'] . '%'; }
if ($filters['cost_type'] !== '') { $where[] = 'cost_type=:cost_type'; $params[':cost_type']=$filters['cost_type']; }
if ($filters['request_type'] !== '') { $where[] = 'request_type=:request_type'; $params[':request_type']=$filters['request_type']; }
if ($filters['approver'] !== '') { $where[] = '(first_approver_name LIKE :approver OR final_approver_name LIKE :approver OR rejected_by_name LIKE :approver)'; $params[':approver']='%' . $filters['approver'] . '%'; }
if ($filters['status'] !== '') { $where[] = 'status=:status'; $params[':status']=$filters['status']; }
if ($filters['approval_status'] === 'FIRST_PENDING') $where[] = "status='FIRST_PENDING'";
if ($filters['approval_status'] === 'FINAL_PENDING') $where[] = "status='FINAL_PENDING'";
if ($filters['approval_status'] === 'FIRST_APPROVED') $where[] = "first_result='APPROVED'";
if ($filters['approval_status'] === 'FINAL_APPROVED') $where[] = "final_result='APPROVED'";
if ($filters['approval_status'] === 'REJECTED') $where[] = "status='REJECTED'";
if (in_array($filters['process_result'], array('COMPLETED','FAILED','CANCELLED'), true)) {
    $where[] = 'status=:process_result';
    $params[':process_result'] = $filters['process_result'];
}
if (CostChangeService::validYm($filters['settlement_ym']) !== '') { $where[] = 'new_settlement_ym=:settlement_ym'; $params[':settlement_ym']=$filters['settlement_ym']; }
try {
    $st = $pdo->prepare("SELECT * FROM cpms_cost_change_requests WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC,id DESC LIMIT 2000");
    $st->execute($params);
    $requests = $st->fetchAll(PDO::FETCH_ASSOC);
    $projects = $pdo->query("SELECT id,name FROM cpms_projects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $requests = array();
    $projects = array();
}
$exportQuery = $_GET;
$exportQuery['r'] = 'cost_change/export';
?>

<div class="space-y-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div><h2 class="text-2xl font-extrabold">비용 변경 관리</h2><p class="mt-1 text-sm text-gray-500">전체 현장 승인·반려·자동반영 이력 조회 전용입니다.</p></div>
        <div class="flex gap-2"><a href="?r=관리&tab=cost_change" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold">초기설정</a><a href="?<?php echo h(http_build_query($exportQuery, '', '&')); ?>" class="px-4 py-2 rounded-xl bg-emerald-700 text-white font-bold">엑셀 내려받기</a></div>
    </div>
    <form method="get" class="rounded-2xl border border-gray-200 bg-white p-4 grid grid-cols-1 md:grid-cols-3 xl:grid-cols-5 gap-3">
        <input type="hidden" name="r" value="cost_change/manage">
        <input type="date" name="date_from" value="<?php echo h($filters['date_from']); ?>" class="px-3 py-2 rounded-xl border" title="시작일">
        <input type="date" name="date_to" value="<?php echo h($filters['date_to']); ?>" class="px-3 py-2 rounded-xl border" title="종료일">
        <select name="project_id" class="px-3 py-2 rounded-xl border"><option value="">전체 현장</option><?php foreach ($projects as $project): ?><option value="<?php echo (int)$project['id']; ?>" <?php echo (int)$project['id'] === (int)$filters['project_id'] ? 'selected' : ''; ?>><?php echo h($project['name']); ?></option><?php endforeach; ?></select>
        <input type="text" name="requester" value="<?php echo h($filters['requester']); ?>" placeholder="요청자" class="px-3 py-2 rounded-xl border">
        <input type="text" name="department" value="<?php echo h($filters['department']); ?>" placeholder="요청부서" class="px-3 py-2 rounded-xl border">
        <select name="cost_type" class="px-3 py-2 rounded-xl border"><option value="">전체 비용</option><?php foreach (array('labor','material','outsourcing','equipment','safety','daily_cost') as $type): ?><option value="<?php echo h($type); ?>" <?php echo $filters['cost_type'] === $type ? 'selected' : ''; ?>><?php echo h(CostChangeService::costTypeLabel($type)); ?></option><?php endforeach; ?></select>
        <select name="request_type" class="px-3 py-2 rounded-xl border"><option value="">전체 요청종류</option><?php foreach (array('MODIFY','ADD','MONTH_MOVE','DELETE') as $type): ?><option value="<?php echo h($type); ?>" <?php echo $filters['request_type'] === $type ? 'selected' : ''; ?>><?php echo h(CostChangeService::requestTypeLabel($type)); ?></option><?php endforeach; ?></select>
        <input type="text" name="approver" value="<?php echo h($filters['approver']); ?>" placeholder="승인자" class="px-3 py-2 rounded-xl border">
        <select name="approval_status" class="px-3 py-2 rounded-xl border">
            <option value="">전체 승인 상태</option>
            <?php foreach (array('FIRST_PENDING'=>'1차 승인 대기','FINAL_PENDING'=>'최종 승인 대기','FIRST_APPROVED'=>'1차 승인 완료','FINAL_APPROVED'=>'최종 승인 완료','REJECTED'=>'반려') as $value=>$label): ?>
                <option value="<?php echo h($value); ?>" <?php echo $filters['approval_status'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="process_result" class="px-3 py-2 rounded-xl border">
            <option value="">전체 처리 결과</option>
            <?php foreach (array('COMPLETED','FAILED','CANCELLED') as $status): ?>
                <option value="<?php echo h($status); ?>" <?php echo $filters['process_result'] === $status ? 'selected' : ''; ?>><?php echo h(CostChangeService::statusLabel($status)); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="month" name="settlement_ym" value="<?php echo h($filters['settlement_ym']); ?>" class="px-3 py-2 rounded-xl border" title="귀속월">
        <div class="xl:col-span-5 flex justify-end gap-2"><a href="?r=cost_change/manage" class="px-4 py-2 rounded-xl border border-gray-300 font-bold">초기화</a><button class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold">조회</button></div>
    </form>
    <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
        <table class="min-w-[1450px] w-full text-sm">
            <thead class="bg-gray-50"><tr><th class="p-3 text-left">처리일</th><th class="p-3 text-left">현장</th><th class="p-3 text-left">비용</th><th class="p-3 text-left">요청 종류</th><th class="p-3 text-right">변경 전</th><th class="p-3 text-right">변경 후</th><th class="p-3 text-left">요청자</th><th class="p-3 text-left">1차 결과</th><th class="p-3 text-left">최종 결과</th><th class="p-3 text-left">처리 결과</th><th class="p-3 text-center">상세</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (count($requests) === 0): ?><tr><td colspan="11" class="p-6 text-center text-gray-500">조회 결과가 없습니다.</td></tr><?php endif; ?>
                <?php foreach ($requests as $request): ?>
                <tr>
                    <td class="p-3 whitespace-nowrap"><?php echo h($request['applied_at'] !== null ? $request['applied_at'] : $request['updated_at']); ?></td>
                    <td class="p-3 font-bold"><?php echo h($request['project_name']); ?></td><td class="p-3"><?php echo h(CostChangeService::costTypeLabel($request['cost_type'])); ?></td><td class="p-3"><?php echo h(CostChangeService::requestTypeLabel($request['request_type'])); ?></td>
                    <td class="p-3 text-right"><?php echo h(cpms_cost_change_money($request['old_amount'])); ?></td><td class="p-3 text-right font-bold"><?php echo h(cpms_cost_change_money($request['new_amount'])); ?></td>
                    <td class="p-3"><?php echo h($request['requester_name']); ?></td><td class="p-3"><?php echo h($request['first_result'] !== null ? $request['first_result'] : '-'); ?></td><td class="p-3"><?php echo h($request['final_result'] !== null ? $request['final_result'] : '-'); ?></td>
                    <td class="p-3"><span class="px-2 py-1 rounded-full border text-xs font-bold <?php echo h(CostChangeService::statusClass($request['status'])); ?>"><?php echo h(CostChangeService::statusLabel($request['status'])); ?></span></td>
                    <td class="p-3 text-center"><a href="?r=cost_change/detail&id=<?php echo (int)$request['id']; ?>" class="px-3 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 font-bold">상세</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
