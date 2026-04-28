<?php
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole(); $dept = Auth::userDepartment();
if (!($role === 'executive' || $dept === '공사')) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error','보안 토큰 오류'); header('Location: ?r=공사'); exit; }
$pid = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$wid = isset($_POST['work_id']) ? (int)$_POST['work_id'] : 0;
$redir = '?r=공사&pid=' . $pid . '&tab=work';
$pdo = Db::pdo(); if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ' . $redir); exit; }
try {
    $st = $pdo->prepare("UPDATE cpms_work_items SET is_deleted = 1, updated_at = NOW() WHERE id = :id AND project_id = :pid");
    $st->bindValue(':id', $wid, PDO::PARAM_INT); $st->bindValue(':pid', $pid, PDO::PARAM_INT); $st->execute();
    flash_set('success','작업이 삭제되었습니다.');
} catch (Exception $e) { flash_set('error','삭제 실패: '.$e->getMessage()); }
header('Location: ' . $redir); exit;