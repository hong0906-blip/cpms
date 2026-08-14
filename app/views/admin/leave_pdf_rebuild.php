<?php
/**
 * Rebuild approved leave PDFs in small batches and replace the Drive copies.
 * PHP 5.6 compatible.
 */
use App\Core\Auth;
use App\Core\Db;

$cpmsLeavePdfJsonSent = false;
$cpmsLeavePdfBufferLevel = ob_get_level();
$cpmsLeavePdfCurrentDocumentId = 0;
$cpmsLeavePdfCurrentStage = 'bootstrap';
$cpmsLeavePdfMemoryReserve = str_repeat('R', 65536);
ob_start();

if (!function_exists('cpms_leave_pdf_json_clean')) {
    function cpms_leave_pdf_json_clean($value)
    {
        if (is_array($value)) {
            $clean = array();
            foreach ($value as $key => $item) {
                $cleanKey = is_string($key) ? cpms_leave_pdf_json_clean($key) : $key;
                $clean[$cleanKey] = cpms_leave_pdf_json_clean($item);
            }
            return $clean;
        }
        if (!is_string($value)) return $value;
        if (function_exists('mb_check_encoding') && @mb_check_encoding($value, 'UTF-8')) return $value;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) return $converted;
        }
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value);
    }
}

if (!function_exists('cpms_leave_pdf_json_response')) {
    function cpms_leave_pdf_json_response($payload, $statusCode)
    {
        global $cpmsLeavePdfJsonSent, $cpmsLeavePdfBufferLevel;
        $payload = cpms_leave_pdf_json_clean($payload);
        $options = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0;
        $json = json_encode($payload, $options);
        if ($json === false || $json === '') {
            $json = '{"ok":false,"message":"PDF rebuild response encoding failed."}';
            $statusCode = 500;
        }
        while (ob_get_level() > $cpmsLeavePdfBufferLevel) @ob_end_clean();
        $cpmsLeavePdfJsonSent = true;
        if (!headers_sent()) {
            http_response_code((int)$statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo $json;
        exit;
    }
}

register_shutdown_function(function () use (&$cpmsLeavePdfJsonSent, $cpmsLeavePdfBufferLevel, &$cpmsLeavePdfCurrentDocumentId, &$cpmsLeavePdfCurrentStage, &$cpmsLeavePdfMemoryReserve) {
    if ($cpmsLeavePdfJsonSent) return;
    $error = error_get_last();
    if (!is_array($error) || !isset($error['type']) || !in_array((int)$error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) return;
    $cpmsLeavePdfMemoryReserve = null;
    while (ob_get_level() > $cpmsLeavePdfBufferLevel) @ob_end_clean();
    $fatalMessage = isset($error['message']) ? cpms_leave_pdf_json_clean((string)$error['message']) : 'fatal error';
    if (strlen($fatalMessage) > 700) $fatalMessage = substr($fatalMessage, 0, 700) . '...';
    $fatalFile = isset($error['file']) ? basename((string)$error['file']) : '';
    $fatalLine = isset($error['line']) ? (int)$error['line'] : 0;
    $logDir = dirname(dirname(dirname(__DIR__))) . '/storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    $logLine = '[' . date('Y-m-d H:i:s') . '] document_id=' . (int)$cpmsLeavePdfCurrentDocumentId . ' stage=' . $cpmsLeavePdfCurrentStage . ' type=' . (int)$error['type'] . ' file=' . $fatalFile . ' line=' . $fatalLine . ' message=' . str_replace(array("\r", "\n"), ' ', $fatalMessage) . "\n";
    @file_put_contents($logDir . '/leave_pdf_rebuild.log', $logLine, FILE_APPEND | LOCK_EX);
    error_log('[leave_pdf_rebuild] ' . $fatalMessage);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    $response = array(
        'ok' => false,
        'message' => '문서 #' . (int)$cpmsLeavePdfCurrentDocumentId . ' PDF 재생성 중 서버 오류가 발생했습니다 [' . $cpmsLeavePdfCurrentStage . ']: ' . $fatalMessage . ($fatalFile !== '' ? ' (' . $fatalFile . ':' . $fatalLine . ')' : '')
    );
    $json = json_encode(cpms_leave_pdf_json_clean($response), defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
    echo ($json !== false && $json !== '') ? $json : '{"ok":false,"message":"PDF 재생성 중 서버 오류가 발생했습니다."}';
});

require_once __DIR__ . '/../approval/_common.php';
require_once __DIR__ . '/leave_management_helpers.php';
require_once __DIR__ . '/../../services/ApprovalPdfService.php';
$cpmsLeavePdfCurrentStage = 'request_validation';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_leave_pdf_json_response(array('ok' => false, 'message' => 'Method not allowed.'), 405);
}

$csrfToken = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!function_exists('csrf_check') || !csrf_check($csrfToken)) {
    cpms_leave_pdf_json_response(array('ok' => false, 'message' => '요청 시간이 만료되었습니다. 화면을 새로고침한 후 다시 시도해주세요.'), 403);
}
$pdo = Db::pdo();
$user = Auth::user();
if (!$pdo || !$user || !cpms_leave_can_access_management($pdo, $user)) {
    cpms_leave_pdf_json_response(array('ok' => false, 'message' => 'Permission denied.'), 403);
}

if (function_exists('session_write_close')) @session_write_close();
@ignore_user_abort(true);
@set_time_limit(240);

$cursor = isset($_POST['cursor']) ? (int)$_POST['cursor'] : 0;
$limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 1;
if ($limit < 1 || $limit > 2) $limit = 1;

if (!cpms_approval_pdf_ensure_document_columns($pdo)) {
    cpms_leave_pdf_json_response(array('ok' => false, 'message' => '전자결재 PDF DB 컬럼 설치/확인이 필요합니다.'), 500);
}
$cpmsLeavePdfCurrentStage = 'document_query';

$total = 0;
try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM cpms_approval_documents WHERE doc_type='leave' AND UPPER(COALESCE(doc_status,'')) IN ('APPROVED','COMPLETED')")->fetchColumn();
} catch (Exception $countException) {
    cpms_leave_pdf_json_response(array('ok' => false, 'message' => 'Approved leave PDF count failed.'), 500);
}

$st = $pdo->prepare("SELECT id FROM cpms_approval_documents WHERE doc_type='leave' AND UPPER(COALESCE(doc_status,'')) IN ('APPROVED','COMPLETED') AND id>:cursor ORDER BY id ASC LIMIT " . $limit);
$st->execute(array(':cursor' => $cursor));
$documents = $st->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($documents)) $documents = array();

$result = array(
    'ok' => true,
    'total' => $total,
    'processed' => 0,
    'succeeded' => 0,
    'failed' => 0,
    'next_cursor' => $cursor,
    'done' => false,
    'items' => array()
);

$lockDir = cpms_drive_storage_root() . '/locks/approval_drive';
cpms_drive_ensure_dir($lockDir);

for ($i = 0; $i < count($documents); $i++) {
    $documentId = isset($documents[$i]['id']) ? (int)$documents[$i]['id'] : 0;
    if ($documentId <= 0) continue;
    $cpmsLeavePdfCurrentDocumentId = $documentId;
    $cpmsLeavePdfCurrentStage = 'lock';
    $result['processed']++;
    $result['next_cursor'] = $documentId;

    $lockHandle = @fopen($lockDir . '/document_' . $documentId . '.lock', 'c');
    if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if ($lockHandle) @fclose($lockHandle);
        $result['failed']++;
        $result['items'][] = array('id' => $documentId, 'ok' => false, 'message' => 'PDF rebuild is already running.');
        continue;
    }

    try {
        $cpmsLeavePdfCurrentStage = 'pdf_generate_and_drive_replace';
        $rebuild = cpms_approval_pdf_upload_completed_pdf($pdo, $documentId, $user, array('force_regenerate' => true));
        $cpmsLeavePdfCurrentStage = 'result_prepare';
        if (!empty($rebuild['ok'])) {
            $result['succeeded']++;
        } else {
            $result['failed']++;
        }
        $result['items'][] = array(
            'id' => $documentId,
            'ok' => !empty($rebuild['ok']),
            'message' => isset($rebuild['message']) ? cpms_drive_redact_text($rebuild['message']) : '',
            'old_cleanup_failed' => !empty($rebuild['old_cleanup_failed'])
        );
    } catch (Exception $e) {
        $result['failed']++;
        $result['items'][] = array('id' => $documentId, 'ok' => false, 'message' => 'PDF rebuild failed.');
        cpms_approval_pdf_log_failure(array(
            'user' => $user,
            'section' => 'approval_leave_pdf_rebuild',
            'approval_document_id' => $documentId,
            'message' => $e->getMessage()
        ));
    }

    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
    $cpmsLeavePdfCurrentStage = 'next_document';
}

if (count($documents) < $limit) {
    $result['done'] = true;
} else {
    $nextSt = $pdo->prepare("SELECT id FROM cpms_approval_documents WHERE doc_type='leave' AND UPPER(COALESCE(doc_status,'')) IN ('APPROVED','COMPLETED') AND id>:cursor ORDER BY id ASC LIMIT 1");
    $nextSt->execute(array(':cursor' => (int)$result['next_cursor']));
    $result['done'] = !$nextSt->fetchColumn();
}

$cpmsLeavePdfCurrentStage = 'response';
$cpmsLeavePdfMemoryReserve = null;
cpms_leave_pdf_json_response($result, 200);
