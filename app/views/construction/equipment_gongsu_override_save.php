<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/equipment_gongsu_approval_helper.php';
require_once __DIR__ . '/../../services/CostChangeService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;

function cpms_equipment_gongsu_json($ok, $message, $extra) {
    header('Content-Type: application/json; charset=utf-8');
    $payload = is_array($extra) ? $extra : array();
    $payload['ok'] = $ok ? true : false;
    $payload['message'] = (string)$message;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cpms_equipment_gongsu_json(false, 'POST 요청만 허용됩니다.', array());
if (!Auth::check()) cpms_equipment_gongsu_json(false, '로그인이 필요합니다.', array());
if (!Auth::canManageConstruction() && !Auth::isMaster() && Auth::userRole() !== 'executive') cpms_equipment_gongsu_json(false, '권한이 없습니다.', array());
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) cpms_equipment_gongsu_json(false, 'CSRF 검증에 실패했습니다.', array());

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$usageId = isset($_POST['equipment_usage_id']) ? (int)$_POST['equipment_usage_id'] : 0;
$newRaw = isset($_POST['new_value']) ? trim((string)$_POST['new_value']) : '';
$reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';

if ($projectId <= 0 || $usageId <= 0) cpms_equipment_gongsu_json(false, '요청 정보가 올바르지 않습니다.', array());
if ($newRaw === '' || !is_numeric($newRaw)) cpms_equipment_gongsu_json(false, '변경 장비공수는 숫자여야 합니다.', array());
$newValue = (float)number_format((float)$newRaw, 2, '.', '');
if ($newValue < 0) cpms_equipment_gongsu_json(false, '변경 장비공수는 0 이상이어야 합니다.', array());
if ($newValue >= 1.2 && $reason === '') cpms_equipment_gongsu_json(false, '1.2 이상 장비공수 수정은 요청 사유가 필요합니다.', array());

$pdo = Db::pdo();
if (!$pdo) cpms_equipment_gongsu_json(false, 'DB 연결 실패', array());
cpms_equipment_gongsu_ensure_schema($pdo);

try {
    $st = $pdo->prepare("SELECT u.*, e.vendor_name, e.spec, e.base_rate FROM cpms_equipment_usage u INNER JOIN cpms_equipment_items e ON e.id=u.equipment_id AND e.project_id=u.project_id WHERE u.id=:id AND u.project_id=:pid LIMIT 1");
    $st->execute(array(':id'=>$usageId, ':pid'=>$projectId));
    $usage = $st->fetch(PDO::FETCH_ASSOC);
    if (!$usage) cpms_equipment_gongsu_json(false, '장비 사용일자를 찾을 수 없습니다.', array());

    $oldValue = isset($usage['work_unit']) && is_numeric((string)$usage['work_unit']) ? (float)$usage['work_unit'] : 1.0;
    $equipmentId = isset($usage['equipment_id']) ? (int)$usage['equipment_id'] : 0;
    $useDate = isset($usage['use_date']) ? (string)$usage['use_date'] : '';
    if ($equipmentId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate)) cpms_equipment_gongsu_json(false, '장비 사용 정보가 올바르지 않습니다.', array());
    $settlementYm = CostChangeService::effectiveSettlementYm($pdo, 'equipment', (string)$usageId, 'equipment', $useDate);
    $lockInfo = CostChangeService::lockInfo('equipment', $useDate, $settlementYm, date('Y-m-d'));
    if (!empty($lockInfo['locked'])) {
        cpms_equipment_gongsu_json(false, '마감된 기간의 자료입니다. 장비비 목록의 수정 승인 요청을 이용해주세요.', array());
    }

    if ($newValue < 1.2) {
        cpms_equipment_gongsu_apply_usage($pdo, $usageId, $newValue);
        cpms_equipment_gongsu_json(true, '장비공수가 수정되었습니다.', array('mode'=>'applied', 'value'=>cpms_equipment_gongsu_format($newValue)));
    }

    $stPending = $pdo->prepare("SELECT id FROM cpms_equipment_gongsu_overrides WHERE equipment_usage_id=:usage_id AND status='pending' LIMIT 1");
    $stPending->execute(array(':usage_id'=>$usageId));
    if ($stPending->fetch()) {
        cpms_equipment_gongsu_json(false, '이미 승인대기 중인 장비공수 수정 요청이 있습니다.', array());
    }

    $director = function_exists('cpms_labor_find_director_approver') ? cpms_labor_find_director_approver($pdo) : null;
    if (!$director) cpms_equipment_gongsu_json(false, '공사PM 승인자를 직원명부에서 찾을 수 없습니다.', array());

    $user = Auth::user();
    $userId = (is_array($user) && isset($user['id']) && is_numeric($user['id'])) ? (int)$user['id'] : null;
    $userName = (is_array($user) && isset($user['name'])) ? trim((string)$user['name']) : '';
    $userEmail = method_exists('App\\Core\\Auth', 'userEmail') ? trim((string)Auth::userEmail()) : '';
    $approvalLevel = ($newValue >= 1.4) ? 'DIRECTOR_THEN_VP' : 'DIRECTOR_ONLY';
    $now = date('Y-m-d H:i:s');

    $sql = "INSERT INTO cpms_equipment_gongsu_overrides
        (project_id, equipment_usage_id, equipment_id, use_date, old_value, new_value, reason, status, approval_required_level, approval_stage, current_approver_employee_id, current_approver_name, current_approver_email, first_approver_employee_id, first_approver_name, first_approver_email, requested_by, requested_by_name, requested_by_email, created_at, updated_at)
        VALUES
        (:pid, :usage_id, :equipment_id, :use_date, :old_value, :new_value, :reason, 'pending', :level, 'DIRECTOR_PENDING', :cur_id, :cur_name, :cur_email, :first_id, :first_name, :first_email, :requested_by, :requested_name, :requested_email, :created_at, :updated_at)";
    $ins = $pdo->prepare($sql);
    $ins->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $ins->bindValue(':usage_id', $usageId, PDO::PARAM_INT);
    $ins->bindValue(':equipment_id', $equipmentId, PDO::PARAM_INT);
    $ins->bindValue(':use_date', $useDate);
    $ins->bindValue(':old_value', $oldValue);
    $ins->bindValue(':new_value', $newValue);
    $ins->bindValue(':reason', $reason);
    $ins->bindValue(':level', $approvalLevel);
    $ins->bindValue(':cur_id', (int)$director['id'], PDO::PARAM_INT);
    $ins->bindValue(':cur_name', isset($director['name']) ? (string)$director['name'] : '');
    $ins->bindValue(':cur_email', isset($director['email']) ? (string)$director['email'] : '');
    $ins->bindValue(':first_id', (int)$director['id'], PDO::PARAM_INT);
    $ins->bindValue(':first_name', isset($director['name']) ? (string)$director['name'] : '');
    $ins->bindValue(':first_email', isset($director['email']) ? (string)$director['email'] : '');
    $ins->bindValue(':requested_by', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $ins->bindValue(':requested_name', $userName !== '' ? $userName : null, $userName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $ins->bindValue(':requested_email', $userEmail !== '' ? $userEmail : null, $userEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $ins->bindValue(':created_at', $now);
    $ins->bindValue(':updated_at', $now);
    $ins->execute();

    $overrideId = (int)$pdo->lastInsertId();
    cpms_equipment_gongsu_send_notification($pdo, $overrideId, 'DIRECTOR_REQUEST');
    cpms_equipment_gongsu_json(true, '장비공수 수정 승인 요청을 보냈습니다.', array('mode'=>'pending', 'pending_value'=>cpms_equipment_gongsu_format($newValue)));
} catch (Exception $e) {
    cpms_equipment_gongsu_json(false, '저장 실패: ' . $e->getMessage(), array());
}
?>
