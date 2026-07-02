<?php
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole(); $dept = Auth::userDepartment();
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error','보안 토큰 오류'); header('Location: ?r=공사'); exit; }
$pid = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$wid = isset($_POST['work_id']) ? (int)$_POST['work_id'] : 0;
$uid = isset($_POST['unit_price_id']) ? (int)$_POST['unit_price_id'] : 0;
$redir = '?r=공사&pid=' . $pid . '&tab=gantt&gantt_panel=work&work_id=' . $wid;
$pdo = Db::pdo(); if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ' . $redir); exit; }
try {
    $st = $pdo->prepare("DELETE FROM cpms_work_item_lines WHERE work_id=:wid AND unit_price_id=:uid");
    $st->bindValue(':wid', $wid, PDO::PARAM_INT); $st->bindValue(':uid', $uid, PDO::PARAM_INT); $st->execute();
    flash_set('success','작업 항목이 삭제되었습니다.');
} catch (Exception $e) { flash_set('error','삭제 실패: '.$e->getMessage()); }
header('Location: ' . $redir); exit;
