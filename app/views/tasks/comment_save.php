<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', '잘못된 요청 방식입니다.');
    cpms_tasks_redirect_back();
}

if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰 검증에 실패했습니다.');
    cpms_tasks_redirect_back();
}

$pdo = Db::pdo();
$currentEmployee = cpms_tasks_current_employee($pdo);
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$parentCommentId = isset($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : 0;
$commentText = isset($_POST['comment_text']) ? trim((string)$_POST['comment_text']) : '';

if (!$pdo || $taskId <= 0 || $commentText === '') {
    flash_set('error', '댓글 등록에 필요한 값이 부족합니다.');
    cpms_tasks_redirect_back();
}

$task = cpms_tasks_find_task($pdo, $taskId);
if (!$task || !cpms_tasks_can_view($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    flash_set('error', '업무를 찾을 수 없거나 댓글 권한이 없습니다.');
    cpms_tasks_redirect_back();
}

if (!cpms_tasks_ensure_comment_schema($pdo)) {
    flash_set('error', '댓글 테이블 준비에 실패했습니다.');
    cpms_tasks_redirect_back();
}

$parentAuthorId = 0;
if ($parentCommentId > 0) {
    try {
        $stParent = $pdo->prepare("SELECT id, created_by FROM cpms_task_comments WHERE id = :id AND task_id = :task_id LIMIT 1");
        $stParent->execute(array(':id' => $parentCommentId, ':task_id' => $taskId));
        $parent = $stParent->fetch(PDO::FETCH_ASSOC);
        if ($parent) {
            $parentAuthorId = isset($parent['created_by']) ? (int)$parent['created_by'] : 0;
        } else {
            $parentCommentId = 0;
        }
    } catch (Exception $e) {
        $parentCommentId = 0;
        $parentAuthorId = 0;
    }
}

$actorId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
$actorName = isset($currentEmployee['name']) ? (string)$currentEmployee['name'] : (string)Auth::userName();
$actorEmail = isset($currentEmployee['email']) ? (string)$currentEmployee['email'] : (string)Auth::userEmail();
$actorPhoto = isset($currentEmployee['photo_path']) ? (string)$currentEmployee['photo_path'] : '';

try {
    $st = $pdo->prepare("INSERT INTO cpms_task_comments
        (task_id, parent_comment_id, comment_text, created_by, created_by_name, created_by_email, created_by_photo_path, created_at)
        VALUES (:task_id, :parent_comment_id, :comment_text, :created_by, :created_by_name, :created_by_email, :created_by_photo_path, :created_at)");
    $st->bindValue(':task_id', $taskId, PDO::PARAM_INT);
    if ($parentCommentId > 0) $st->bindValue(':parent_comment_id', $parentCommentId, PDO::PARAM_INT);
    else $st->bindValue(':parent_comment_id', null, PDO::PARAM_NULL);
    $st->bindValue(':comment_text', $commentText, PDO::PARAM_STR);
    if ($actorId > 0) $st->bindValue(':created_by', $actorId, PDO::PARAM_INT);
    else $st->bindValue(':created_by', null, PDO::PARAM_NULL);
    $st->bindValue(':created_by_name', $actorName, PDO::PARAM_STR);
    $st->bindValue(':created_by_email', $actorEmail, PDO::PARAM_STR);
    $st->bindValue(':created_by_photo_path', $actorPhoto, PDO::PARAM_STR);
    $st->bindValue(':created_at', cpms_tasks_now(), PDO::PARAM_STR);
    $st->execute();

    try {
        $log = $pdo->prepare("INSERT INTO cpms_task_logs (task_id, actor_employee_id, actor_name, action_type, message, created_at)
                              VALUES (:task_id, :actor_employee_id, :actor_name, 'commented', :message, :created_at)");
        $log->bindValue(':task_id', $taskId, PDO::PARAM_INT);
        if ($actorId > 0) $log->bindValue(':actor_employee_id', $actorId, PDO::PARAM_INT);
        else $log->bindValue(':actor_employee_id', null, PDO::PARAM_NULL);
        $log->bindValue(':actor_name', $actorName, PDO::PARAM_STR);
        $log->bindValue(':message', $commentText, PDO::PARAM_STR);
        $log->bindValue(':created_at', cpms_tasks_now(), PDO::PARAM_STR);
        $log->execute();
    } catch (Exception $e) {
    }

    cpms_tasks_send_comment_notifications($pdo, $task, $commentText, $currentEmployee, $parentAuthorId);
    flash_set('success', '댓글을 등록했습니다.');
} catch (Exception $e) {
    flash_set('error', '댓글 등록 실패: ' . $e->getMessage());
}

cpms_tasks_redirect_back();
