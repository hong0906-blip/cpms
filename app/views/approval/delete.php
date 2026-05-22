<?php
use App\Core\Db;

if (!function_exists('approval_delete_table_exists')) {
    function approval_delete_table_exists($pdo, $table)
    {
        if (!$pdo || trim((string)$table) === '') { return false; }
        try {
            $sql = "SHOW TABLES LIKE :table";
            $st = $pdo->prepare($sql);
            $st->execute(array(':table' => $table));
            return $st->fetchColumn() ? true : false;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_delete_column_exists')) {
    function approval_delete_column_exists($pdo, $table, $column)
    {
        if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') { return false; }
        try {
            $sql = "SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE :column";
            $st = $pdo->prepare($sql);
            $st->execute(array(':column' => $column));
            return $st->fetchColumn() ? true : false;
        } catch (Exception $e) {
            return false;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }
csrf_validate();

require_once __DIR__ . '/_common.php';

$pdo = Db::pdo();
$u = \App\Core\Auth::user();
if (!$pdo || !$u) { exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    flash_set('danger', '문서를 찾을 수 없습니다.');
    header('Location: ?r=approval_home&view=cancelled');
    exit;
}

$paths = array();

try {
    $st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
    $st->execute(array(':id' => $id));
    $d = $st->fetch(PDO::FETCH_ASSOC);

    if (!$d) {
        flash_set('danger', '문서를 찾을 수 없습니다.');
        header('Location: ?r=approval_home&view=cancelled');
        exit;
    }

    if (!approval_can_delete_document($pdo, $d, $u)) {
        flash_set('danger', '취소문서만 삭제할 수 있으며 작성자 본인 또는 관리자만 삭제할 수 있습니다.');
        header('Location: ?r=approval_home&view=cancelled');
        exit;
    }

    if (strtoupper(trim((string)$d['doc_status'])) !== 'CANCELLED') {
        flash_set('danger', '취소된 문서만 삭제할 수 있습니다.');
        header('Location: ?r=approval_detail&id=' . $id);
        exit;
    }

    if (approval_delete_table_exists($pdo, 'cpms_approval_files') && approval_delete_column_exists($pdo, 'cpms_approval_files', 'file_path')) {
        $fs = $pdo->prepare("SELECT file_path FROM cpms_approval_files WHERE document_id=:id");
        $fs->execute(array(':id' => $id));
        $files = $fs->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($files)) {
            for ($i = 0; $i < count($files); $i++) {
                if (isset($files[$i]['file_path']) && trim((string)$files[$i]['file_path']) !== '') {
                    $paths[] = trim((string)$files[$i]['file_path']);
                }
            }
        }
    }

    $pdo->beginTransaction();

    $deleteTables = array(
        'cpms_approval_notifications',
        'cpms_approval_leave_deductions',
        'cpms_approval_logs',
        'cpms_approval_files',
        'cpms_approval_lines'
    );

    for ($i = 0; $i < count($deleteTables); $i++) {
        $table = $deleteTables[$i];
        if (!approval_delete_table_exists($pdo, $table)) { continue; }
        if (!approval_delete_column_exists($pdo, $table, 'document_id')) { continue; }
        $sql = "DELETE FROM `" . str_replace('`', '', $table) . "` WHERE document_id=:id";
        $pdo->prepare($sql)->execute(array(':id' => $id));
    }

    $pdo->prepare("DELETE FROM cpms_approval_documents WHERE id=:id")->execute(array(':id' => $id));

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash_set('danger', '문서 삭제 중 오류가 발생했습니다.');
    header('Location: ?r=approval_home&view=cancelled');
    exit;
}

$root = realpath(__DIR__ . '/../../..');
for ($i = 0; $i < count($paths); $i++) {
    $path = $paths[$i];
    $cand = $path;
    if ($root !== false && strpos($path, '/') !== 0 && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
        $cand = $root . '/' . ltrim($path, '/');
    }
    if (is_file($cand)) { @unlink($cand); }
}

flash_set('success', '취소문서를 삭제했습니다.');
header('Location: ?r=approval_home&view=cancelled');
exit;
