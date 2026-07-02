<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/helpers/quantity_remaining_helper.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole(); $dept = Auth::userDepartment();
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error','보안 토큰 오류'); header('Location: ?r=공사'); exit; }
$pid = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$wid = isset($_POST['work_id']) ? (int)$_POST['work_id'] : 0;
$uid = isset($_POST['unit_price_id']) ? (int)$_POST['unit_price_id'] : 0;
$pqRaw = isset($_POST['planned_qty']) ? trim((string)$_POST['planned_qty']) : '';
$note = isset($_POST['note']) ? trim((string)$_POST['note']) : '';
$pq = ($pqRaw !== '' && is_numeric($pqRaw)) ? (float)$pqRaw : null;
$redir = '?r=공사&pid=' . $pid . '&tab=gantt&gantt_panel=work&work_id=' . $wid;
$pdo = Db::pdo(); if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ' . $redir); exit; }
try {
    $checkMap = array();
    $checkMap[$uid] = $pq;
    $quantityValidation = cpms_validate_work_item_line_quantities($pdo, $pid, $wid, $checkMap);
    if (empty($quantityValidation['ok'])) {
        flash_set('error', isset($quantityValidation['message']) ? $quantityValidation['message'] : '남은 수량보다 큰 수량은 입력할 수 없습니다.');
        header('Location: ' . $redir);
        exit;
    }
    $st = $pdo->prepare("INSERT INTO cpms_work_item_lines(work_id,unit_price_id,planned_qty,note,created_at) VALUES(:wid,:uid,:pq,:note,NOW()) ON DUPLICATE KEY UPDATE planned_qty=VALUES(planned_qty), note=VALUES(note)");
    $st->bindValue(':wid', $wid, PDO::PARAM_INT); $st->bindValue(':uid', $uid, PDO::PARAM_INT); $st->bindValue(':pq', $pq, $pq !== null ? PDO::PARAM_STR : PDO::PARAM_NULL); $st->bindValue(':note', $note !== '' ? $note : null, $note !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL); $st->execute();
    flash_set('success','작업 항목이 저장되었습니다.');
} catch (Exception $e) { flash_set('error','저장 실패: '.$e->getMessage()); }
header('Location: ' . $redir); exit;
