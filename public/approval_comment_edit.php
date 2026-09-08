<?php
/*
 * 파일경로: public/approval_comment_edit.php
 * 기능: 전자결재 승인의견 수정 저장
 * - 승인의견을 작성한 결재자 본인만 수정 가능
 * - 화면에는 최신 의견만 표시
 * - 수정 전/후 내용은 COMMENT_EDIT 로그에 내부 이력으로 보관
 * - 승인/반려 결과, 서명, 승인시간은 변경하지 않음
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/approval/_common.php';

use App\Core\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?r=approval_home');
    exit;
}
csrf_validate();

$pdo = Db::pdo();
$user = \App\Core\Auth::user();
if (!$pdo || !$user) {
    header('Location: index.php');
    exit;
}

$documentId = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
$logId = isset($_POST['log_id']) ? (int)$_POST['log_id'] : 0;
$newComment = isset($_POST['approval_comment']) ? trim((string)$_POST['approval_comment']) : '';

if ($documentId <= 0 || $logId <= 0) {
    flash_set('danger', '수정할 승인의견을 찾을 수 없습니다.');
    header('Location: index.php?r=approval_home');
    exit;
}

if ($newComment === '') {
    flash_set('danger', '승인의견 내용을 입력해 주세요.');
    header('Location: index.php?r=approval_detail&id=' . $documentId);
    exit;
}

if (function_exists('mb_substr')) {
    $newComment = mb_substr($newComment, 0, 2000, 'UTF-8');
} else {
    $newComment = substr($newComment, 0, 2000);
}

$uid = approval_current_employee_id($pdo, $user);
$userEmail = strtolower(trim((string)approval_current_user_email($user)));
$userName = trim((string)approval_current_user_name($user));

if (!function_exists('approval_comment_edit_is_owner')) {
    function approval_comment_edit_is_owner($log, $uid, $userEmail, $userName)
    {
        if (!is_array($log)) return false;

        $actorId = isset($log['actor_id']) ? (int)$log['actor_id'] : 0;
        $actorEmail = isset($log['actor_email']) ? strtolower(trim((string)$log['actor_email'])) : '';
        $actorName = isset($log['actor_name']) ? trim((string)$log['actor_name']) : '';

        if ((int)$uid > 0 && $actorId > 0) {
            return ((int)$uid === $actorId);
        }
        if ($userEmail !== '' && $actorEmail !== '') {
            return ($userEmail === $actorEmail);
        }
        if ($actorId <= 0 && $actorEmail === '' && $userName !== '' && $actorName !== '') {
            return ($userName === $actorName);
        }
        return false;
    }
}

try {
    $docSt = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
    $docSt->execute(array(':id' => $documentId));
    $doc = $docSt->fetch(PDO::FETCH_ASSOC);
    if (!$doc || !approval_can_view_document($pdo, $doc, $user)) {
        flash_set('danger', '이 문서를 볼 권한이 없습니다.');
        header('Location: index.php?r=approval_home');
        exit;
    }

    if (!approval_table_exists($pdo, 'cpms_approval_logs')) {
        flash_set('danger', '승인의견 저장 이력을 확인할 수 없습니다.');
        header('Location: index.php?r=approval_detail&id=' . $documentId);
        exit;
    }

    $pdo->beginTransaction();

    $logSt = $pdo->prepare("SELECT * FROM cpms_approval_logs WHERE id=:log_id AND document_id=:document_id AND UPPER(COALESCE(action_type,''))='APPROVE' LIMIT 1 FOR UPDATE");
    $logSt->execute(array(':log_id' => $logId, ':document_id' => $documentId));
    $log = $logSt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        throw new Exception('approval_comment_not_found');
    }
    if (!approval_comment_edit_is_owner($log, $uid, $userEmail, $userName)) {
        throw new Exception('approval_comment_permission_denied');
    }

    $oldComment = isset($log['action_note']) ? trim((string)$log['action_note']) : '';
    if ($oldComment === '') {
        throw new Exception('approval_comment_empty');
    }

    if ($oldComment === $newComment) {
        $pdo->rollBack();
        flash_set('info', '변경된 승인의견 내용이 없습니다.');
        header('Location: index.php?r=approval_detail&id=' . $documentId);
        exit;
    }

    $historyPayload = array(
        'version' => 1,
        'source_log_id' => $logId,
        'previous_note' => $oldComment,
        'new_note' => $newComment
    );
    $historyJson = json_encode($historyPayload);
    if ($historyJson === false || $historyJson === '') {
        throw new Exception('approval_comment_history_encode_failed');
    }

    $historySt = $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:document_id,:line_id,:actor_id,:actor_name,:actor_email,'COMMENT_EDIT',:action_note,NOW())");
    $historySt->execute(array(
        ':document_id' => $documentId,
        ':line_id' => isset($log['line_id']) ? $log['line_id'] : null,
        ':actor_id' => $uid > 0 ? $uid : (isset($log['actor_id']) ? $log['actor_id'] : null),
        ':actor_name' => $userName !== '' ? $userName : (isset($log['actor_name']) ? $log['actor_name'] : ''),
        ':actor_email' => $userEmail !== '' ? $userEmail : (isset($log['actor_email']) ? $log['actor_email'] : ''),
        ':action_note' => $historyJson
    ));

    $updateSt = $pdo->prepare("UPDATE cpms_approval_logs SET action_note=:action_note WHERE id=:log_id AND document_id=:document_id AND UPPER(COALESCE(action_type,''))='APPROVE'");
    $updateSt->execute(array(
        ':action_note' => $newComment,
        ':log_id' => $logId,
        ':document_id' => $documentId
    ));

    if ($updateSt->rowCount() < 1) {
        throw new Exception('approval_comment_update_failed');
    }

    $pdo->commit();
    flash_set('success', '승인의견을 수정했습니다.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = '승인의견 수정 중 오류가 발생했습니다.';
    if ($e->getMessage() === 'approval_comment_permission_denied') {
        $message = '본인이 작성한 승인의견만 수정할 수 있습니다.';
    } else if ($e->getMessage() === 'approval_comment_not_found' || $e->getMessage() === 'approval_comment_empty') {
        $message = '수정할 승인의견을 찾을 수 없습니다.';
    }
    error_log('[approval_comment_edit] ' . $e->getMessage());
    flash_set('danger', $message);
}

header('Location: index.php?r=approval_detail&id=' . $documentId);
exit;
