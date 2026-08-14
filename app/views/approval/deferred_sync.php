<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../services/ApprovalDriveService.php';
require_once __DIR__ . '/../../services/ApprovalPdfService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'message' => 'Method not allowed.'));
    exit;
}

csrf_validate();
$pdo = Db::pdo();
$user = \App\Core\Auth::user();
$documentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!$pdo || !$user || $documentId <= 0) {
    http_response_code(404);
    echo json_encode(array('ok' => false, 'message' => 'Approval document was not found.'));
    exit;
}

$st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
$st->execute(array(':id' => $documentId));
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc || !approval_can_view_document($pdo, $doc, $user)) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => 'Permission denied.'));
    exit;
}

$lockDir = cpms_drive_storage_root() . '/locks/approval_drive';
$lockHandle = null;
if (cpms_drive_ensure_dir($lockDir)) {
    $lockHandle = @fopen($lockDir . '/document_' . $documentId . '.lock', 'c');
}
if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if ($lockHandle) @fclose($lockHandle);
    echo json_encode(array('ok' => true, 'busy' => true, 'message' => 'Drive backup is already running.'));
    exit;
}

if (function_exists('session_write_close')) @session_write_close();
@ignore_user_abort(true);
@set_time_limit(240);

$result = array(
    'ok' => true,
    'busy' => false,
    'attachments' => array('ok' => true, 'processed' => 0, 'uploaded' => 0, 'failed' => 0),
    'completed_pdf' => array('ok' => true, 'skipped' => true),
    'completed_pdf_cache' => array('ok' => true, 'skipped' => true)
);

try {
    $result['attachments'] = cpms_approval_drive_process_pending_files($pdo, $doc, $user, 20);

    $docSt = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
    $docSt->execute(array(':id' => $documentId));
    $currentDoc = $docSt->fetch(PDO::FETCH_ASSOC);
    $docStatus = $currentDoc && isset($currentDoc['doc_status']) ? strtoupper(trim((string)$currentDoc['doc_status'])) : '';
    $pdfFileId = $currentDoc && isset($currentDoc['completed_pdf_drive_file_id']) ? trim((string)$currentDoc['completed_pdf_drive_file_id']) : '';
    $pdfStatus = $currentDoc && isset($currentDoc['completed_pdf_upload_status']) ? strtolower(trim((string)$currentDoc['completed_pdf_upload_status'])) : '';
    $pdfVersion = $currentDoc && isset($currentDoc['completed_pdf_render_version']) ? (int)$currentDoc['completed_pdf_render_version'] : 0;
    $forcePdfRegenerate = ($pdfFileId !== '' && $pdfVersion < cpms_approval_pdf_render_version());
    if (in_array($docStatus, array('APPROVED', 'COMPLETED'), true)
        && (($pdfFileId === '' && in_array($pdfStatus, array('', 'pending', 'processing', 'failed'), true)) || $forcePdfRegenerate)) {
        if (!$forcePdfRegenerate) {
            cpms_approval_pdf_update_document($pdo, $documentId, array('completed_pdf_upload_status' => 'processing'));
        }
        $result['completed_pdf'] = cpms_approval_pdf_upload_completed_pdf($pdo, $documentId, $user, array('force_regenerate' => $forcePdfRegenerate));
    } else if (in_array($docStatus, array('APPROVED', 'COMPLETED'), true) && $pdfFileId !== '') {
        $pdfExpectedSize = isset($currentDoc['completed_pdf_size']) ? (int)$currentDoc['completed_pdf_size'] : 0;
        if (cpms_approval_pdf_cache_get_path($pdfFileId, $pdfExpectedSize) === '') {
            $cacheDownload = cpms_drive_download_file($pdfFileId);
            $cacheContent = (is_array($cacheDownload) && !empty($cacheDownload['ok']) && isset($cacheDownload['content']))
                ? (string)$cacheDownload['content']
                : '';
            $cachePath = cpms_approval_pdf_cache_store_content($pdfFileId, $cacheContent);
            if ($cachePath !== '') {
                $result['completed_pdf_cache'] = array('ok' => true, 'skipped' => false);
            } else {
                $result['completed_pdf_cache'] = array('ok' => false, 'skipped' => false);
                cpms_approval_pdf_log_failure(array(
                    'user' => $user,
                    'section' => 'approval_completed_pdf_cache_warm',
                    'approval_document_id' => $documentId,
                    'message' => is_array($cacheDownload) && isset($cacheDownload['message'])
                        ? (string)$cacheDownload['message']
                        : 'Completed PDF cache warm failed.'
                ));
            }
        }
    }
    if (empty($result['attachments']['ok']) || empty($result['completed_pdf']['ok'])) $result['ok'] = false;
} catch (Exception $e) {
    $result['ok'] = false;
    $result['message'] = 'Deferred Drive backup failed.';
    cpms_drive_log_upload_failure(array(
        'user' => $user,
        'section' => 'approval_deferred_sync',
        'approval_document_id' => $documentId,
        'message' => $e->getMessage()
    ));
}

@flock($lockHandle, LOCK_UN);
@fclose($lockHandle);
echo json_encode($result);
exit;
