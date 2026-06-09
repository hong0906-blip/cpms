<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/helpers/quantity_remaining_helper.php';
use App\Core\Auth;
use App\Core\Db;
if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole(); $dept = Auth::userDepartment();
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error','보안 토큰 오류'); header('Location: ?r=공사'); exit; }
$pid = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$wid = isset($_POST['work_id']) ? (int)$_POST['work_id'] : 0;
$title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
$desc = trim((string)(isset($_POST['description']) ? $_POST['description'] : ''));
$selected = isset($_POST['selected_unit_price_ids']) && is_array($_POST['selected_unit_price_ids']) ? $_POST['selected_unit_price_ids'] : array();
$plannedMap = isset($_POST['planned_qty_map']) && is_array($_POST['planned_qty_map']) ? $_POST['planned_qty_map'] : array();
$redir = '?r=공사&pid=' . $pid . '&tab=work';
if ($pid <= 0 || $title === '') { flash_set('error','프로젝트/작업명 확인'); header('Location: ' . $redir); exit; }
if (mb_strlen($title, 'UTF-8') > 200) $title = mb_substr($title, 0, 200, 'UTF-8');
$pdo = Db::pdo(); if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ' . $redir); exit; }

$oldPlannedMap = array();
if ($wid > 0) {
    try {
        $stOldLines = $pdo->prepare("SELECT unit_price_id, planned_qty FROM cpms_work_item_lines WHERE work_id = :wid");
        $stOldLines->bindValue(':wid', $wid, PDO::PARAM_INT);
        $stOldLines->execute();
        $oldLines = $stOldLines->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($oldLines)) {
            foreach ($oldLines as $oldLine) {
                $oldUid = isset($oldLine['unit_price_id']) ? (int)$oldLine['unit_price_id'] : 0;
                if ($oldUid > 0) $oldPlannedMap[$oldUid] = isset($oldLine['planned_qty']) ? $oldLine['planned_qty'] : null;
            }
        }
    } catch (Exception $eOld) {
        $oldPlannedMap = array();
    }
}

$candidatePlannedMap = array();
$seenUnitMap = array();
foreach ($selected as $uidRaw) {
    $uid = (int)$uidRaw;
    if ($uid <= 0) continue;
    if (isset($seenUnitMap[$uid])) continue;
    $seenUnitMap[$uid] = 1;
    $pqRaw = isset($plannedMap[$uid]) ? trim((string)$plannedMap[$uid]) : (isset($oldPlannedMap[$uid]) ? trim((string)$oldPlannedMap[$uid]) : '');
    $candidatePlannedMap[$uid] = ($pqRaw !== '' && is_numeric($pqRaw)) ? (float)$pqRaw : null;
}

$quantityValidation = cpms_validate_work_item_line_quantities($pdo, $pid, $wid, $candidatePlannedMap);
if (empty($quantityValidation['ok'])) {
    flash_set('error', isset($quantityValidation['message']) ? $quantityValidation['message'] : '남은 수량보다 큰 수량은 입력할 수 없습니다.');
    header('Location: ' . $redir . ($wid > 0 ? '&work_id=' . $wid : ''));
    exit;
}

try {
    if ($wid > 0) {
        $st = $pdo->prepare("UPDATE cpms_work_items SET title=:t, description=:d, updated_at=NOW() WHERE id=:id AND project_id=:pid");
        $st->bindValue(':t', $title); $st->bindValue(':d', $desc !== '' ? $desc : null, $desc !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':id', $wid, PDO::PARAM_INT); $st->bindValue(':pid', $pid, PDO::PARAM_INT); $st->execute();
    } else {
        $ord = 0;
        try { $o = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM cpms_work_items WHERE project_id=:pid"); $o->bindValue(':pid',$pid,PDO::PARAM_INT); $o->execute(); $ord = (int)$o->fetchColumn(); } catch (Exception $e) {}
        $st = $pdo->prepare("INSERT INTO cpms_work_items(project_id,title,description,sort_order,is_deleted,created_at,updated_at) VALUES(:pid,:t,:d,:o,0,NOW(),NOW())");
        $st->bindValue(':pid', $pid, PDO::PARAM_INT); $st->bindValue(':t', $title); $st->bindValue(':d', $desc !== '' ? $desc : null, $desc !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL); $st->bindValue(':o', $ord, PDO::PARAM_INT); $st->execute();
        $wid = (int)$pdo->lastInsertId();
    }
    if ($wid > 0) {
        $pdo->prepare("DELETE FROM cpms_work_item_lines WHERE work_id = :wid")->execute(array(':wid' => $wid));
        $ins = $pdo->prepare("INSERT INTO cpms_work_item_lines(work_id, unit_price_id, planned_qty, note, created_at) VALUES(:wid,:uid,:pq,:note,NOW())");
        foreach ($candidatePlannedMap as $uid => $pq) {
            $uid = (int)$uid; if ($uid <= 0) continue;
            $ins->bindValue(':wid', $wid, PDO::PARAM_INT);
            $ins->bindValue(':uid', $uid, PDO::PARAM_INT);
            $ins->bindValue(':pq', $pq, $pq !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $ins->bindValue(':note', null, PDO::PARAM_NULL);
            $ins->execute();
        }
    }
    flash_set('success','작업이 저장되었습니다.');
    header('Location: ' . $redir . '&work_id=' . $wid);
    exit;
} catch (Exception $e) {
    flash_set('error','저장 실패: ' . $e->getMessage()); header('Location: ' . $redir); exit;
}
