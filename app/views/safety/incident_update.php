<?php
/**
 * C:\www\cpms\app\views\safety\incident_update.php
 * - 안전사고 상태/후속조치 저장 액션(POST)
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

$redirect = isset($_POST['redirect']) && trim((string)$_POST['redirect']) !== '' ? trim((string)$_POST['redirect']) : '?r=안전/보건';

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', '안전사고 후속조치 저장 실패: 잘못된 요청 방식입니다.');
    header('Location: ' . $redirect);
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '안전사고 후속조치 저장 실패: 보안 토큰 오류입니다.');
    header('Location: ' . $redirect);
    exit;
}

$role = Auth::userRole();
$dept = Auth::userDepartment();
$can = ($dept === '안전' || $role === 'executive' || $role === 'master');
if (!$can && method_exists('App\Core\Auth', 'canManageConstruction')) $can = Auth::canManageConstruction();
if (!$can) {
    flash_set('error', '안전사고 후속조치 저장 실패: 권한이 없습니다.');
    header('Location: ' . $redirect);
    exit;
}

$id = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$actionNote = isset($_POST['action_note']) ? trim((string)$_POST['action_note']) : '';
if ($id <= 0 || $status === '') {
    flash_set('error', '안전사고 후속조치 저장 실패: 필수값이 누락되었습니다.');
    header('Location: ' . $redirect);
    exit;
}
$allowed = array('접수', '처리중', '처리완료');
if (!in_array($status, $allowed, true)) {
    flash_set('error', '안전사고 후속조치 저장 실패: 허용되지 않은 상태값입니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', '안전사고 후속조치 저장 실패: DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

try {
    // 안전사고 후속조치 컬럼 보강
    $cols = array(
      'action_note' => "ALTER TABLE cpms_safety_incidents ADD COLUMN action_note TEXT NULL",
      'action_by' => "ALTER TABLE cpms_safety_incidents ADD COLUMN action_by INT NULL",
      'action_by_name' => "ALTER TABLE cpms_safety_incidents ADD COLUMN action_by_name VARCHAR(80) NULL",
      'action_at' => "ALTER TABLE cpms_safety_incidents ADD COLUMN action_at DATETIME NULL"
    );
    foreach ($cols as $c => $sql) {
      $q = $pdo->query("SHOW COLUMNS FROM cpms_safety_incidents LIKE '".$c."'");
      if (!$q || !$q->fetch()) $pdo->exec($sql);
    }

    $uid = null;
    if (method_exists('App\Core\Auth', 'id')) { $tmp = Auth::id(); if ($tmp !== null && $tmp !== '' && $tmp !== false) $uid = (int)$tmp; }
    $uname = method_exists('App\Core\Auth', 'userName') ? trim((string)Auth::userName()) : '';
    if ($uname === '') $uname = (string)Auth::userEmail();

    if ($actionNote !== '') {
        $st = $pdo->prepare("UPDATE cpms_safety_incidents
            SET status = :st, action_note = :an, action_by = :ab, action_by_name = :abn, action_at = NOW(), updated_at = NOW()
            WHERE id = :id");
        $st->bindValue(':st', $status, PDO::PARAM_STR);
        $st->bindValue(':an', $actionNote, PDO::PARAM_STR);
        $st->bindValue(':ab', $uid, $uid !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $st->bindValue(':abn', $uname !== '' ? $uname : null, $uname !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
    } else {
        $st = $pdo->prepare("UPDATE cpms_safety_incidents SET status = :st, updated_at = NOW() WHERE id = :id");
        $st->bindValue(':st', $status, PDO::PARAM_STR);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
    }
    $st->execute();
    flash_set('success', '안전사고 후속조치를 저장했습니다.');    
} catch (Exception $e) {
    flash_set('error', '안전사고 후속조치 저장 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;