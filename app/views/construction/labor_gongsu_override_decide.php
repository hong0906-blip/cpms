<?php
// 공수 승인 처리 / 공수 반려 처리
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

function cpms_labor_decide_redirect($type, $message) {
    flash_set($type, $message);
    header('Location: ' . base_url() . '/?r=대시보드&dv=executive');
    exit;
}

function cpms_labor_decide_ensure_columns($pdo) {
    if (!$pdo) return false;
    if (!cpms_ensure_labor_override_table($pdo)) return false;

    $cols = array();
    try {
        $st = $pdo->query("SHOW COLUMNS FROM cpms_labor_gongsu_overrides");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['Field'])) $cols[(string)$row['Field']] = true;
        }
    } catch (Exception $e) {
        return false;
    }

    $adds = array(
        'approved_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approved_by INT NULL AFTER requested_by",
        'approved_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
        'rejected_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN rejected_by INT NULL AFTER approved_at",
        'rejected_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by",
        'reject_reason' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN reject_reason VARCHAR(255) NULL AFTER rejected_at",
        'updated_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN updated_at DATETIME NOT NULL AFTER reject_reason"
    );

    foreach ($adds as $col => $sql) {
        if (!isset($cols[$col])) {
            $pdo->exec($sql);
        }
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_labor_decide_redirect('error', '잘못된 요청 방식입니다.');
}

if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    cpms_labor_decide_redirect('error', 'CSRF 검증에 실패했습니다.');
}

$allowed = false;
if (Auth::isMaster()) $allowed = true;
if (Auth::userRole() === 'executive') $allowed = true;
if (Auth::canManageEmployees()) $allowed = true;

if (!$allowed) {
    cpms_labor_decide_redirect('error', '권한이 없습니다.');
}

$overrideId = isset($_POST['override_id']) ? (int)$_POST['override_id'] : 0;
$decision = isset($_POST['decision']) ? strtolower(trim((string)$_POST['decision'])) : '';
$rejectReason = isset($_POST['reject_reason']) ? trim((string)$_POST['reject_reason']) : '';

if ($overrideId <= 0) cpms_labor_decide_redirect('error', '요청 ID가 올바르지 않습니다.');
if ($decision !== 'approve' && $decision !== 'reject') cpms_labor_decide_redirect('error', '처리 유형이 올바르지 않습니다.');
if ($decision === 'reject' && $rejectReason === '') cpms_labor_decide_redirect('error', '반려 사유를 입력하세요.');

$pdo = Db::pdo();
if (!$pdo) cpms_labor_decide_redirect('error', 'DB 연결에 실패했습니다.');
if (!cpms_labor_decide_ensure_columns($pdo)) cpms_labor_decide_redirect('error', '테이블 준비에 실패했습니다.');

$user = Auth::user();
$userId = (is_array($user) && isset($user['id']) && is_numeric($user['id'])) ? (int)$user['id'] : null;

try {
    $pdo->beginTransaction();

    $stRow = $pdo->prepare("SELECT id, status FROM cpms_labor_gongsu_overrides WHERE id = :id FOR UPDATE");
    $stRow->bindValue(':id', $overrideId, PDO::PARAM_INT);
    $stRow->execute();
    $row = $stRow->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        cpms_labor_decide_redirect('error', '대상 요청을 찾을 수 없습니다.');
    }

    if ((string)$row['status'] !== 'pending') {
        $pdo->rollBack();
        cpms_labor_decide_redirect('error', '이미 처리된 요청입니다.');
    }

    if ($decision === 'approve') {
        $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides
            SET status='approved', approved_by=:uid, approved_at=NOW(), updated_at=NOW()
            WHERE id=:id AND status='pending'");
        $st->bindValue(':uid', $userId, ($userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $st->bindValue(':id', $overrideId, PDO::PARAM_INT);
        $st->execute();
        $pdo->commit();
        cpms_labor_decide_redirect('success', '공수 수정 요청을 승인했습니다.');
    }

    $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides
        SET status='rejected', reject_reason=:reject_reason, rejected_by=:uid, rejected_at=NOW(), updated_at=NOW()
        WHERE id=:id AND status='pending'");
    $st->bindValue(':reject_reason', $rejectReason, PDO::PARAM_STR);
    $st->bindValue(':uid', $userId, ($userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
    $st->bindValue(':id', $overrideId, PDO::PARAM_INT);
    $st->execute();
    $pdo->commit();
    cpms_labor_decide_redirect('success', '공수 수정 요청을 반려했습니다.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cpms_labor_decide_redirect('error', '처리 실패: ' . $e->getMessage());
}