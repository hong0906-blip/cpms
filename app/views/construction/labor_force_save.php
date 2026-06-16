<?php
/**
 * - 공사: 노무비 월별 강제입력 저장
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/tabs/partials/labor_data_loader.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!(Auth::isMaster() || Auth::userRole() === 'executive')) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$month = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
$laborTab = isset($_POST['labor_tab']) ? trim((string)$_POST['labor_tab']) : 'timesheet';
if ($laborTab === '') $laborTab = 'timesheet';

$redirect = '?r=공사&pid=' . (int)$projectId . '&tab=labor&labor_tab=' . urlencode($laborTab);
if ($month !== '') $redirect .= '&month=' . urlencode($month);

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

if ($projectId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    flash_set('error', '프로젝트 또는 월 정보가 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$amount = cpms_labor_force_parse_amount(isset($_POST['amount']) ? $_POST['amount'] : '');
$memo = isset($_POST['memo']) ? trim((string)$_POST['memo']) : '';
if (strlen($memo) > 255) {
    $memo = substr($memo, 0, 255);
}

$pdo = Db::pdo();
if (!$pdo || !cpms_ensure_labor_force_adjustments_table($pdo)) {
    flash_set('error', '노무비 강제입력 테이블을 확인할 수 없습니다.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $now = date('Y-m-d H:i:s');
    $user = Auth::user();
    $userId = (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0;
    $userName = (string)Auth::userName();
    $userEmail = (string)Auth::userEmail();

    $st = $pdo->prepare("INSERT INTO cpms_labor_force_adjustments
            (project_id, month, amount, memo, updated_by, updated_by_name, updated_by_email, created_at, updated_at)
            VALUES (:pid, :month, :amount, :memo, :uid, :uname, :uemail, :now, :now)
            ON DUPLICATE KEY UPDATE
                amount = VALUES(amount),
                memo = VALUES(memo),
                updated_by = VALUES(updated_by),
                updated_by_name = VALUES(updated_by_name),
                updated_by_email = VALUES(updated_by_email),
                updated_at = VALUES(updated_at)");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':month', $month);
    $st->bindValue(':amount', $amount);
    $st->bindValue(':memo', $memo);
    if ($userId > 0) $st->bindValue(':uid', $userId, PDO::PARAM_INT);
    else $st->bindValue(':uid', null, PDO::PARAM_NULL);
    $st->bindValue(':uname', $userName !== '' ? $userName : null, $userName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':uemail', $userEmail !== '' ? $userEmail : null, $userEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':now', $now);
    $st->execute();

    flash_set('success', '노무비 강제입력 금액을 저장했습니다.');
    header('Location: ' . $redirect);
    exit;
} catch (Exception $e) {
    flash_set('error', '노무비 강제입력 저장 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
