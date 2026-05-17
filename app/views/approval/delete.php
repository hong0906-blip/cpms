<?php
use App\Core\Db;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
csrf_validate();
$pdo = Db::pdo();
$u = \App\Core\Auth::user();
if (!$pdo || !$u) exit;

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$st = $pdo->prepare("SELECT id, created_by_id, doc_status FROM cpms_approval_documents WHERE id=:id LIMIT 1");
$st->execute(array(':id'=>$id));
$d = $st->fetch();
if (!$d) {
    flash_set('danger','문서를 찾을 수 없습니다.');
    header('Location: ?r=approval_home');
    exit;
}

$isAdmin = \App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole()==='executive';
$isOwner = ((int)$d['created_by_id'] === (int)$u['id']);
if (!$isOwner && !$isAdmin) {
    flash_set('danger','삭제 권한이 없습니다.');
    header('Location: ?r=approval_home');
    exit;
}
if ((string)$d['doc_status'] !== 'CANCELLED') {
    flash_set('danger','요청취소 상태 문서만 삭제할 수 있습니다.');
    header('Location: ?r=approval_detail&id='.$id);
    exit;
}

$paths = array();
$fs = $pdo->prepare("SELECT file_path, stored_path, saved_path FROM cpms_approval_files WHERE document_id=:id");
$fs->execute(array(':id'=>$id));
foreach ($fs->fetchAll() as $f) {
    foreach (array('file_path','stored_path','saved_path') as $k) {
        if (isset($f[$k]) && trim((string)$f[$k]) !== '') {
            $paths[] = trim((string)$f[$k]);
        }
    }
}

$pdo->beginTransaction();
try {
    $pdo->prepare("DELETE FROM cpms_approval_notifications WHERE document_id=:id")->execute(array(':id'=>$id));
    $pdo->prepare("DELETE FROM cpms_approval_leave_deductions WHERE document_id=:id")->execute(array(':id'=>$id));
    $pdo->prepare("DELETE FROM cpms_approval_logs WHERE document_id=:id")->execute(array(':id'=>$id));
    $pdo->prepare("DELETE FROM cpms_approval_files WHERE document_id=:id")->execute(array(':id'=>$id));
    $pdo->prepare("DELETE FROM cpms_approval_lines WHERE document_id=:id")->execute(array(':id'=>$id));
    $pdo->prepare("DELETE FROM cpms_approval_documents WHERE id=:id")->execute(array(':id'=>$id));
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    flash_set('danger','문서 삭제 중 오류가 발생했습니다.');
    header('Location: ?r=approval_detail&id='.$id);
    exit;
}

$root = realpath(__DIR__ . '/../../..');
for ($i=0; $i<count($paths); $i++) {
    $path = $paths[$i];
    $cand = $path;
    if ($root !== false && strpos($path, '/') !== 0 && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
        $cand = $root . '/' . ltrim($path, '/');
    }
    if (is_file($cand)) { @unlink($cand); }
}

flash_set('success','요청취소 문서를 삭제했습니다.');
header('Location: ?r=approval_home');