<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/leave_balance_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }
csrf_validate();

$pdo = Db::pdo();
$u = \App\Core\Auth::user();
if (!$pdo || !$u) { exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    flash_set('danger', '문서를 찾을 수 없습니다.');
    header('Location: ?r=approval_home&view=active');
    exit;
}

$uid = approval_current_employee_id($pdo, $u);
$authUserId = (is_array($u) && isset($u['id'])) ? (int)$u['id'] : 0;
$currentUserName = approval_current_user_name($u);
$currentUserEmail = approval_current_user_email($u);

$st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
$st->execute(array(':id' => $id));
$d = $st->fetch(PDO::FETCH_ASSOC);

error_log('[approval_cancel] doc_id=' . $id);
error_log('[approval_cancel] auth_user_id=' . $authUserId);
error_log('[approval_cancel] employee_id=' . $uid);
error_log('[approval_cancel] created_by_id=' . ($d && isset($d['created_by_id']) ? (int)$d['created_by_id'] : 0));
error_log('[approval_cancel] created_by_name=' . ($d && isset($d['created_by_name']) ? trim((string)$d['created_by_name']) : ''));
error_log('[approval_cancel] current_user_name=' . $currentUserName);

if (!$d) {
    flash_set('danger', '문서를 찾을 수 없습니다.');
    header('Location: ?r=approval_home&view=active');
    exit;
}

$canCancelOwnPendingDocument = approval_is_document_owner($pdo, $d, $u) && approval_can_cancel_document($d);
$canCancelApprovedLeave = approval_can_cancel_approved_leave($pdo, $d, $u);

if ($canCancelApprovedLeave) {
    try {
        $pdo->beginTransaction();

        $lockedSt = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1 FOR UPDATE");
        $lockedSt->execute(array(':id' => $id));
        $lockedDocument = $lockedSt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedDocument || !approval_can_cancel_approved_leave($pdo, $lockedDocument, $u)) {
            throw new Exception('approved_leave_cancel_not_allowed');
        }

        $actor = array(
            'id' => $uid,
            'name' => $currentUserName,
            'email' => $currentUserEmail
        );
        $restoreResult = approval_restore_leave_balance_on_approved_cancellation($pdo, $id, $actor);
        if (!isset($restoreResult['ok']) || (int)$restoreResult['ok'] !== 1) {
            throw new Exception('leave_restore_failed:' . (isset($restoreResult['message']) ? $restoreResult['message'] : 'unknown'));
        }

        $cancelSt = $pdo->prepare("UPDATE cpms_approval_documents SET doc_status='CANCELLED', updated_at=NOW() WHERE id=:id AND UPPER(COALESCE(doc_status,'')) IN ('APPROVED','COMPLETED')");
        $cancelSt->execute(array(':id' => $id));
        if ((int)$cancelSt->rowCount() !== 1) {
            throw new Exception('approved_leave_status_changed');
        }

        $cancelNote = approval_ko('%EC%8A%B9%EC%9D%B8%20%EC%B7%A8%EC%86%8C%20%EB%B0%8F%20%ED%9C%B4%EA%B0%80%20%EB%B3%B5%EA%B5%AC');
        if (isset($restoreResult['message']) && trim((string)$restoreResult['message']) !== '') {
            $cancelNote .= ' - ' . trim((string)$restoreResult['message']);
        }
        $logSt = $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:a,:n,:e,'APPROVED_LEAVE_CANCEL',:note,NOW())");
        $logSt->execute(array(
            ':d' => $id,
            ':a' => $uid,
            ':n' => $currentUserName,
            ':e' => $currentUserEmail,
            ':note' => $cancelNote
        ));

        $pdo->commit();
        if (isset($restoreResult['restored']) && (int)$restoreResult['restored'] === 1) {
            flash_set('success', approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84%20%EC%8A%B9%EC%9D%B8%EC%9D%84%20%EC%B7%A8%EC%86%8C%ED%95%98%EA%B3%A0%20%EC%B0%A8%EA%B0%90%EB%90%9C%20%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EB%B3%B5%EA%B5%AC%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        } else {
            flash_set('success', approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84%20%EC%8A%B9%EC%9D%B8%EC%9D%84%20%EC%B7%A8%EC%86%8C%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EC%9E%90%EB%8F%99%20%EC%B0%A8%EA%B0%90%20%EA%B8%B0%EB%A1%9D%EC%9D%B4%20%EC%97%86%EC%96%B4%20%EC%9E%94%EC%95%A1%20%EB%B3%80%EA%B2%BD%EC%9D%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        }
        header('Location: ?r=approval_home&view=cancelled');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[approval_cancel] approved leave cancellation failed: ' . $e->getMessage());
        flash_set('danger', approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84%20%EC%8A%B9%EC%9D%B8%20%EC%B7%A8%EC%86%8C%20%EC%B2%98%EB%A6%AC%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        header('Location: ?r=approval_detail&id=' . $id);
        exit;
    }
}

if (!$canCancelOwnPendingDocument) {
    flash_set('danger', approval_ko('%EC%9D%B4%20%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EC%B7%A8%EC%86%8C%ED%95%A0%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ?r=approval_detail&id=' . $id);
    exit;
}

$pdo->prepare("UPDATE cpms_approval_documents SET doc_status='CANCELLED', updated_at=NOW() WHERE id=:id")
    ->execute(array(':id' => $id));

$pdo->prepare("INSERT INTO cpms_approval_logs (document_id, actor_id, actor_name, actor_email, action_type, created_at) VALUES (:d, :a, :n, :e, 'CANCEL', NOW())")
    ->execute(array(
        ':d' => $id,
        ':a' => $uid,
        ':n' => $currentUserName,
        ':e' => $currentUserEmail
    ));

flash_set('success', '요청을 취소했습니다.');
header('Location: ?r=approval_home&view=cancelled');
exit;
