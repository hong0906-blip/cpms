<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';

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

if (!approval_is_document_owner($pdo, $d, $u)) {
    flash_set('danger', '본인이 작성한 문서만 요청취소할 수 있습니다.');
    header('Location: ?r=approval_detail&id=' . $id);
    exit;
}

if (!approval_can_cancel_document($d)) {
    flash_set('danger', '현재 상태에서는 취소할 수 없습니다.');
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
