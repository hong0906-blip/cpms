<?php
/**
 * C:\www\cpms\app\views\construction\progress_statement_upload.php
 * 공사담당자의 기성내역서 최초 제출 POST 처리.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/ProgressStatementService.php';
require_once __DIR__ . '/../../services/ProgressStatementNotificationService.php';

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=공사'); exit;
}
$pdo = Db::pdo();
$actor = cpms_progress_statement_actor($pdo);
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$returnUrl = '?r=공사&pid=' . $projectId . '&tab=progress_statement';
$fileInfo = null;
try {
    if (!$pdo || !cpms_progress_statement_schema_ready($pdo)) throw new Exception('기성내역서 DB 설치·점검을 먼저 실행해주세요.');
    if (!cpms_progress_statement_project_exists($pdo, $projectId)) throw new Exception('존재하지 않는 프로젝트입니다.');
    if (!cpms_progress_statement_can_submit($pdo, $projectId, $actor)) throw new Exception('본인이 main 또는 sub 담당자로 배정된 프로젝트만 제출할 수 있습니다.');
    $year = isset($_POST['target_year']) ? (int)$_POST['target_year'] : 0;
    $month = isset($_POST['target_month']) ? (int)$_POST['target_month'] : 0;
    $round = isset($_POST['progress_round']) ? (int)$_POST['progress_round'] : 0;
    $title = trim(isset($_POST['title']) ? (string)$_POST['title'] : '');
    $message = trim(isset($_POST['submit_message']) ? (string)$_POST['submit_message'] : '');
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12 || $round <= 0 || $round > 999) throw new Exception('기성 연월과 차수를 올바르게 입력해주세요.');
    if ($title === '') throw new Exception('제목을 입력해주세요.');
    if (function_exists('mb_strlen') && mb_strlen($title, 'UTF-8') > 200) throw new Exception('제목은 200자 이하로 입력해주세요.');
    $fileInfo = cpms_progress_statement_store_upload($projectId, 'statement_file');
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    $duplicate = $pdo->prepare("SELECT id FROM cpms_progress_statements WHERE project_id=:project_id AND target_year=:target_year AND target_month=:target_month AND progress_round=:progress_round LIMIT 1 FOR UPDATE");
    $duplicate->execute(array(':project_id' => $projectId, ':target_year' => $year, ':target_month' => $month, ':progress_round' => $round));
    if ($duplicate->fetchColumn()) throw new Exception('같은 현장·기성연월·차수의 제출 건이 이미 존재합니다.');
    $st = $pdo->prepare("INSERT INTO cpms_progress_statements
        (project_id,target_year,target_month,progress_round,title,submit_message,status,submitted_by,submitted_by_name,submitted_by_email,submitted_at,drive_upload_status,created_at,updated_at)
        VALUES (:project_id,:target_year,:target_month,:progress_round,:title,:submit_message,'pending',:submitted_by,:submitted_by_name,:submitted_by_email,:submitted_at,'not_started',:created_at,:updated_at)");
    $st->execute(array(':project_id'=>$projectId, ':target_year'=>$year, ':target_month'=>$month, ':progress_round'=>$round,
        ':title'=>$title, ':submit_message'=>$message, ':submitted_by'=>$actor['id'] > 0 ? $actor['id'] : null,
        ':submitted_by_name'=>$actor['name'], ':submitted_by_email'=>$actor['email'], ':submitted_at'=>$now, ':created_at'=>$now, ':updated_at'=>$now));
    $statementId = (int)$pdo->lastInsertId();
    $fileId = cpms_progress_statement_insert_file($pdo, $statementId, 1, $fileInfo, $actor, 'initial');
    $up = $pdo->prepare("UPDATE cpms_progress_statements SET latest_file_id=:file_id WHERE id=:id");
    $up->execute(array(':file_id'=>$fileId, ':id'=>$statementId));
    cpms_progress_statement_add_history($pdo, $statementId, 'submitted', '', 'pending', $actor, $message);
    $pdo->commit();
    flash_set('success', '기성내역서가 검토대기로 제출되었습니다.');
    cpms_progress_statement_flush_redirect($returnUrl);
    cpms_progress_statement_notify($pdo, $statementId, 'submitted', $actor, array());
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    cpms_progress_statement_remove_uncommitted_file($fileInfo);
    flash_set('error', $e->getMessage());
}
header('Location: ' . $returnUrl); exit;
