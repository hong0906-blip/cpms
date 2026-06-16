<?php
/**
 * Electronic approval completed-document PDF helpers.
 * PHP 5.6 compatible. Reuses ApprovalDriveService and GoogleDriveHelper.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';
require_once __DIR__ . '/ApprovalDriveService.php';
require_once __DIR__ . '/../views/approval/_common.php';
require_once __DIR__ . '/../views/approval/document_templates.php';
require_once __DIR__ . '/../views/approval/template_proposal.php';
require_once __DIR__ . '/../views/approval/template_leave.php';
require_once __DIR__ . '/../views/approval/template_unused_leave.php';

if (!function_exists('cpms_approval_pdf_document_columns')) {
function cpms_approval_pdf_document_columns() {
    return array(
        'completed_pdf_storage_type' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_storage_type VARCHAR(30) NULL",
        'completed_pdf_drive_file_id' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_drive_file_id VARCHAR(128) NULL",
        'completed_pdf_drive_folder_id' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_drive_folder_id VARCHAR(128) NULL",
        'completed_pdf_drive_web_view_link' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_drive_web_view_link TEXT NULL",
        'completed_pdf_drive_web_content_link' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_drive_web_content_link TEXT NULL",
        'completed_pdf_name' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_name VARCHAR(255) NULL",
        'completed_pdf_mime_type' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_mime_type VARCHAR(190) NULL",
        'completed_pdf_size' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_size BIGINT NULL",
        'completed_pdf_uploaded_at' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_uploaded_at DATETIME NULL",
        'completed_pdf_upload_status' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_upload_status VARCHAR(30) NULL",
        'completed_pdf_upload_error' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_upload_error TEXT NULL"
    );
}}

if (!function_exists('cpms_approval_pdf_ensure_document_columns')) {
function cpms_approval_pdf_ensure_document_columns($pdo) {
    if (!$pdo || !cpms_approval_drive_table_exists($pdo, 'cpms_approval_documents')) return false;
    $ok = true;
    $columns = cpms_approval_pdf_document_columns();
    foreach ($columns as $column => $sql) {
        if (cpms_approval_drive_column_exists($pdo, 'cpms_approval_documents', $column)) continue;
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            $ok = false;
            cpms_approval_pdf_log_failure(array(
                'section' => 'approval_completed_pdf_schema',
                'message' => 'Completed PDF column creation failed: ' . $column . ' / ' . $e->getMessage()
            ));
        }
    }
    return $ok;
}}

if (!function_exists('cpms_approval_pdf_log_failure')) {
function cpms_approval_pdf_log_failure($context) {
    if (!is_array($context)) $context = array();
    if (!isset($context['section']) || trim((string)$context['section']) === '') {
        $context['section'] = 'approval_completed_pdf';
    }
    if (isset($context['message'])) {
        $context['message'] = cpms_drive_redact_text((string)$context['message']);
    }
    return cpms_drive_log_upload_failure($context);
}}

if (!function_exists('cpms_approval_pdf_exec_disabled')) {
function cpms_approval_pdf_exec_disabled($functionName) {
    $functionName = strtolower(trim((string)$functionName));
    if ($functionName === '') return true;
    $disabled = strtolower((string)ini_get('disable_functions'));
    if ($disabled === '') return false;
    $items = explode(',', $disabled);
    for ($i = 0; $i < count($items); $i++) {
        if (trim($items[$i]) === $functionName) return true;
    }
    return false;
}}

if (!function_exists('cpms_approval_pdf_exec_available')) {
function cpms_approval_pdf_exec_available() {
    return function_exists('exec') && !cpms_approval_pdf_exec_disabled('exec');
}}

if (!function_exists('cpms_approval_pdf_tool_candidates')) {
function cpms_approval_pdf_tool_candidates() {
    $candidates = array();
    $configured = cpms_drive_config('wkhtmltopdf_path');
    if (trim((string)$configured) !== '') $candidates[] = trim((string)$configured);
    $envPath = function_exists('getenv') ? getenv('WKHTMLTOPDF_PATH') : '';
    if (trim((string)$envPath) !== '') $candidates[] = trim((string)$envPath);
    $candidates[] = '/usr/bin/wkhtmltopdf';
    $candidates[] = '/usr/local/bin/wkhtmltopdf';
    $candidates[] = '/bin/wkhtmltopdf';
    $candidates[] = 'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltopdf.exe';
    $candidates[] = 'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltopdf.exe';
    return $candidates;
}}

if (!function_exists('cpms_approval_pdf_find_wkhtmltopdf')) {
function cpms_approval_pdf_find_wkhtmltopdf() {
    static $found = null;
    if ($found !== null) return $found;

    $found = array('ok' => false, 'path' => '', 'message' => '');
    if (!cpms_approval_pdf_exec_available()) {
        $found['message'] = 'PHP exec function is not available.';
        return $found;
    }

    $candidates = cpms_approval_pdf_tool_candidates();
    for ($i = 0; $i < count($candidates); $i++) {
        $candidate = trim((string)$candidates[$i]);
        if ($candidate !== '' && is_file($candidate)) {
            $found = array('ok' => true, 'path' => $candidate, 'message' => 'wkhtmltopdf found.');
            return $found;
        }
    }

    $output = array();
    $code = 1;
    $command = (DIRECTORY_SEPARATOR === '\\') ? 'where wkhtmltopdf' : 'command -v wkhtmltopdf';
    @exec($command . ' 2>&1', $output, $code);
    if ((int)$code === 0 && is_array($output) && count($output) > 0) {
        for ($j = 0; $j < count($output); $j++) {
            $path = trim((string)$output[$j]);
            if ($path !== '' && (is_file($path) || strpos($path, 'wkhtmltopdf') !== false)) {
                $found = array('ok' => true, 'path' => $path, 'message' => 'wkhtmltopdf found in PATH.');
                return $found;
            }
        }
    }

    $found['message'] = 'wkhtmltopdf executable was not found.';
    return $found;
}}

if (!function_exists('cpms_approval_pdf_is_available')) {
function cpms_approval_pdf_is_available() {
    return cpms_approval_pdf_find_wkhtmltopdf();
}}

if (!function_exists('cpms_approval_pdf_temp_dir')) {
function cpms_approval_pdf_temp_dir() {
    return cpms_drive_storage_root() . '/tmp/approval_pdf';
}}

if (!function_exists('cpms_approval_pdf_ensure_temp_dir')) {
function cpms_approval_pdf_ensure_temp_dir() {
    $dir = cpms_approval_pdf_temp_dir();
    if (!cpms_drive_ensure_dir($dir)) {
        return array('ok' => false, 'path' => $dir, 'message' => 'Approval PDF temp directory could not be created.');
    }
    if (!is_writable($dir)) {
        return array('ok' => false, 'path' => $dir, 'message' => 'Approval PDF temp directory is not writable.');
    }
    return array('ok' => true, 'path' => $dir, 'message' => 'Approval PDF temp directory is writable.');
}}

if (!function_exists('cpms_approval_pdf_temp_path')) {
function cpms_approval_pdf_temp_path($baseName, $extension) {
    $dirResult = cpms_approval_pdf_ensure_temp_dir();
    if (empty($dirResult['ok'])) return '';
    $extension = ltrim(trim((string)$extension), '.');
    if ($extension === '') $extension = 'tmp';
    $baseName = cpms_drive_sanitize_file_name($baseName, 140);
    $baseName = preg_replace('/\.' . preg_quote($extension, '/') . '$/i', '', $baseName);
    if ($baseName === '') $baseName = 'approval_pdf';
    $path = rtrim($dirResult['path'], '/\\') . '/' . $baseName . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $extension;
    return $path;
}}

if (!function_exists('cpms_approval_pdf_cleanup_temp_file')) {
function cpms_approval_pdf_cleanup_temp_file($path) {
    $path = trim((string)$path);
    if ($path === '') return true;
    $real = realpath($path);
    if ($real === false) return true;
    $root = realpath(cpms_approval_pdf_temp_dir());
    if ($root === false) return false;
    $realNorm = str_replace('\\', '/', $real);
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
    if (strpos($realNorm, $rootNorm) !== 0) return false;
    return @unlink($real);
}}

if (!function_exists('cpms_approval_pdf_path_to_file_uri')) {
function cpms_approval_pdf_path_to_file_uri($path) {
    $real = realpath($path);
    if ($real === false) $real = $path;
    $real = str_replace('\\', '/', $real);
    if (preg_match('/^[A-Za-z]:\//', $real)) {
        return 'file:///' . $real;
    }
    return 'file://' . $real;
}}

if (!function_exists('cpms_approval_pdf_public_base_uri')) {
function cpms_approval_pdf_public_base_uri() {
    $publicDir = dirname(dirname(__DIR__)) . '/public';
    return rtrim(cpms_approval_pdf_path_to_file_uri($publicDir), '/') . '/';
}}

if (!function_exists('cpms_approval_pdf_fetch_document')) {
function cpms_approval_pdf_fetch_document($pdo, $approvalId) {
    if (!$pdo || (int)$approvalId <= 0) return null;
    $st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
    $st->execute(array(':id' => (int)$approvalId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}}

if (!function_exists('cpms_approval_pdf_fetch_lines')) {
function cpms_approval_pdf_fetch_lines($pdo, $approvalId) {
    $rows = array();
    if (!$pdo || (int)$approvalId <= 0) return $rows;
    $st = $pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:id ORDER BY line_order");
    $st->execute(array(':id' => (int)$approvalId));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_approval_pdf_fetch_files_by_type')) {
function cpms_approval_pdf_fetch_files_by_type($pdo, $approvalId, $docType) {
    $filesByType = array();
    if (!$pdo || (int)$approvalId <= 0 || (string)$docType !== 'proposal') return $filesByType;
    if (!approval_table_exists($pdo, 'cpms_approval_files')) return $filesByType;
    $fs = $pdo->prepare("SELECT * FROM cpms_approval_files WHERE document_id=:id ORDER BY id DESC");
    $fs->execute(array(':id' => (int)$approvalId));
    $fileRows = $fs->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($fileRows)) return $filesByType;
    for ($i = 0; $i < count($fileRows); $i++) {
        $k = isset($fileRows[$i]['file_type']) ? $fileRows[$i]['file_type'] : '';
        if ($k !== '' && !isset($filesByType[$k])) {
            $filesByType[$k] = $fileRows[$i];
        }
    }
    return $filesByType;
}}

if (!function_exists('cpms_approval_pdf_render_template_style')) {
function cpms_approval_pdf_render_template_style() {
    ob_start();
    require __DIR__ . '/../views/approval/template_style.php';
    $style = ob_get_clean();
    return $style;
}}

if (!function_exists('cpms_approval_pdf_build_html')) {
function cpms_approval_pdf_build_html($pdo, $approvalId) {
    $doc = cpms_approval_pdf_fetch_document($pdo, $approvalId);
    if (!$doc) {
        return array('ok' => false, 'html' => '', 'doc' => null, 'content' => array(), 'message' => 'Approval document was not found.');
    }
    $content = approval_parse_content(isset($doc['content']) ? $doc['content'] : '');
    $lines = cpms_approval_pdf_fetch_lines($pdo, $approvalId);
    $filesByType = cpms_approval_pdf_fetch_files_by_type($pdo, $approvalId, isset($doc['doc_type']) ? $doc['doc_type'] : '');

    ob_start();
    if (isset($doc['doc_type']) && $doc['doc_type'] === 'leave') {
        render_approval_leave_document($content, $lines, 'print', array());
    } else if (isset($doc['doc_type']) && $doc['doc_type'] === 'unused_leave_notice') {
        render_approval_unused_leave_notice_document($content, $lines, 'print', array());
    } else if (isset($doc['doc_type']) && $doc['doc_type'] === 'unused_leave_plan') {
        render_approval_unused_leave_plan_document($content, $lines, 'print', array());
    } else {
        render_approval_proposal_document($content, $lines, 'print', $filesByType, array());
    }
    $body = ob_get_clean();

    $html = '<!doctype html>' . "\n";
    $html .= '<html><head><meta charset="utf-8">';
    $html .= '<base href="' . h(cpms_approval_pdf_public_base_uri()) . '">';
    $html .= '<title>' . h(approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C')) . '</title>';
    $html .= cpms_approval_pdf_render_template_style();
    $html .= '<style>html,body{margin:0;padding:0;background:#fff;color:#111;font-family:"Malgun Gothic","Noto Sans CJK KR","NanumGothic",sans-serif}.no-print{display:none!important}.approval-paper{page-break-inside:avoid;box-sizing:border-box}</style>';
    $html .= '</head><body>' . $body . '</body></html>';

    return array('ok' => true, 'html' => $html, 'doc' => $doc, 'content' => $content, 'message' => '');
}}

if (!function_exists('cpms_approval_pdf_validate_file')) {
function cpms_approval_pdf_validate_file($path) {
    $path = trim((string)$path);
    if ($path === '' || !is_file($path)) {
        return array('ok' => false, 'message' => 'PDF file was not created.', 'size' => 0);
    }
    $size = (int)@filesize($path);
    if ($size < 1024) {
        return array('ok' => false, 'message' => 'Generated PDF file is too small.', 'size' => $size);
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return array('ok' => false, 'message' => 'Generated PDF file could not be read.', 'size' => $size);
    }
    $head = @fread($fh, 4);
    @fclose($fh);
    if ($head !== '%PDF') {
        return array('ok' => false, 'message' => 'Generated file is not a PDF.', 'size' => $size);
    }
    return array('ok' => true, 'message' => 'PDF file created.', 'size' => $size);
}}

if (!function_exists('cpms_approval_pdf_run_wkhtmltopdf')) {
function cpms_approval_pdf_run_wkhtmltopdf($htmlPath, $pdfPath, $toolPath) {
    $common = ' --encoding ' . escapeshellarg('utf-8')
        . ' --print-media-type'
        . ' --quiet'
        . ' --margin-top ' . escapeshellarg('8mm')
        . ' --margin-right ' . escapeshellarg('8mm')
        . ' --margin-bottom ' . escapeshellarg('8mm')
        . ' --margin-left ' . escapeshellarg('8mm');
    $attempts = array(
        escapeshellarg($toolPath) . $common . ' --enable-local-file-access ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath),
        escapeshellarg($toolPath) . $common . ' ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath)
    );
    $lastOutput = '';
    $lastCode = 1;
    for ($i = 0; $i < count($attempts); $i++) {
        if (is_file($pdfPath)) @unlink($pdfPath);
        $output = array();
        $code = 1;
        @exec($attempts[$i] . ' 2>&1', $output, $code);
        $lastOutput = is_array($output) ? implode("\n", $output) : '';
        $lastCode = (int)$code;
        if ($lastCode === 0) {
            $valid = cpms_approval_pdf_validate_file($pdfPath);
            if (!empty($valid['ok'])) {
                return array('ok' => true, 'message' => $valid['message'], 'size' => (int)$valid['size'], 'output' => '', 'exit_code' => 0);
            }
            $lastOutput = $valid['message'] . "\n" . $lastOutput;
        }
        if ($i === 0 && stripos($lastOutput, 'unknown') === false && stripos($lastOutput, 'unrecognized') === false) {
            break;
        }
    }
    $message = 'wkhtmltopdf failed.';
    if ($lastOutput !== '') $message .= ' ' . cpms_drive_redact_text($lastOutput);
    return array('ok' => false, 'message' => $message, 'size' => 0, 'output' => $lastOutput, 'exit_code' => $lastCode);
}}

if (!function_exists('cpms_approval_pdf_create_from_html')) {
function cpms_approval_pdf_create_from_html($html, $pdfName, $context) {
    if (!is_array($context)) $context = array();
    $dir = cpms_approval_pdf_ensure_temp_dir();
    if (empty($dir['ok'])) {
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => $dir['message']);
    }
    $tool = cpms_approval_pdf_is_available();
    if (empty($tool['ok'])) {
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => $tool['message']);
    }
    $htmlPath = cpms_approval_pdf_temp_path($pdfName, 'html');
    $pdfPath = cpms_approval_pdf_temp_path($pdfName, 'pdf');
    if ($htmlPath === '' || $pdfPath === '') {
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => 'Approval PDF temp paths could not be prepared.');
    }
    if (@file_put_contents($htmlPath, (string)$html, LOCK_EX) === false) {
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => 'Approval PDF source HTML could not be written.');
    }

    $run = cpms_approval_pdf_run_wkhtmltopdf($htmlPath, $pdfPath, $tool['path']);
    cpms_approval_pdf_cleanup_temp_file($htmlPath);
    if (empty($run['ok'])) {
        cpms_approval_pdf_cleanup_temp_file($pdfPath);
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => isset($run['message']) ? $run['message'] : 'PDF generation failed.');
    }
    return array('ok' => true, 'path' => $pdfPath, 'name' => $pdfName, 'mime_type' => 'application/pdf', 'size' => (int)$run['size'], 'message' => 'PDF file created.', 'tool' => $tool['path']);
}}

if (!function_exists('cpms_approval_pdf_completed_date')) {
function cpms_approval_pdf_completed_date($docRow, $content) {
    $candidates = array();
    if (is_array($content)) {
        foreach (array('completed_at', 'approved_at', 'updated_at') as $key) {
            if (isset($content[$key])) $candidates[] = $content[$key];
        }
    }
    if (is_array($docRow)) {
        foreach (array('updated_at', 'created_at') as $key) {
            if (isset($docRow[$key])) $candidates[] = $docRow[$key];
        }
    }
    for ($i = 0; $i < count($candidates); $i++) {
        $ts = strtotime((string)$candidates[$i]);
        if ($ts !== false) return date('Y-m-d', $ts);
    }
    return date('Y-m-d');
}}

if (!function_exists('cpms_approval_pdf_document_number')) {
function cpms_approval_pdf_document_number($docRow, $completedDate) {
    $id = is_array($docRow) && isset($docRow['id']) ? (int)$docRow['id'] : 0;
    $ts = strtotime((string)$completedDate);
    $year = $ts !== false ? date('Y', $ts) : date('Y');
    return 'APR-' . $year . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}}

if (!function_exists('cpms_approval_pdf_build_file_name')) {
function cpms_approval_pdf_build_file_name($docRow, $content) {
    $completedDate = cpms_approval_pdf_completed_date($docRow, $content);
    $folderInfo = cpms_approval_drive_document_folder(isset($docRow['doc_type']) ? $docRow['doc_type'] : '', $content);
    $drafter = cpms_approval_drive_drafter_name($docRow, $content);
    $docNo = cpms_approval_pdf_document_number($docRow, $completedDate);
    $parts = array(
        $completedDate,
        urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC'),
        urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C'),
        $folderInfo['label'],
        $drafter,
        $docNo,
        date('His') . '_' . mt_rand(1000, 9999)
    );
    return cpms_drive_sanitize_file_name(implode('_', $parts) . '.pdf', 180);
}}

if (!function_exists('cpms_approval_pdf_context')) {
function cpms_approval_pdf_context($docRow, $content, $userContext, $stage) {
    $docRow = is_array($docRow) ? $docRow : array();
    $content = is_array($content) ? $content : array();
    $folderInfo = cpms_approval_drive_document_folder(isset($docRow['doc_type']) ? $docRow['doc_type'] : '', $content);
    $projectId = cpms_approval_drive_project_id($docRow, $content);
    $docId = isset($docRow['id']) ? (int)$docRow['id'] : 0;
    $section = 'approval_completed_pdf';
    $stage = trim((string)$stage);
    if ($stage !== '') $section .= '_' . $stage;
    return array(
        'user' => $userContext,
        'uploaded_by' => $userContext,
        'section' => $section,
        'approval_document_id' => $docId,
        'approval_id' => $docId,
        'document_type' => $folderInfo['label'],
        'project_id' => $projectId > 0 ? $projectId : '',
        'original_name' => cpms_approval_pdf_document_number($docRow, cpms_approval_pdf_completed_date($docRow, $content)),
        'target_folder_id' => cpms_drive_folder_id('approval')
    );
}}

if (!function_exists('cpms_approval_pdf_create_file')) {
function cpms_approval_pdf_create_file($pdo, $approvalId) {
    $build = cpms_approval_pdf_build_html($pdo, $approvalId);
    if (empty($build['ok'])) {
        return array('ok' => false, 'path' => '', 'name' => '', 'size' => 0, 'message' => isset($build['message']) ? $build['message'] : 'Approval PDF HTML build failed.', 'doc' => null, 'content' => array());
    }
    $fileName = cpms_approval_pdf_build_file_name($build['doc'], $build['content']);
    $pdf = cpms_approval_pdf_create_from_html($build['html'], $fileName, cpms_approval_pdf_context($build['doc'], $build['content'], null, 'generate'));
    $pdf['doc'] = $build['doc'];
    $pdf['content'] = $build['content'];
    return $pdf;
}}

if (!function_exists('cpms_approval_pdf_update_document')) {
function cpms_approval_pdf_update_document($pdo, $approvalId, $fields) {
    if (!$pdo || (int)$approvalId <= 0 || !is_array($fields) || count($fields) === 0) {
        return array('ok' => false, 'message' => 'Invalid completed PDF document update.');
    }
    $sets = array();
    $params = array(':id' => (int)$approvalId);
    foreach ($fields as $column => $value) {
        if (!cpms_approval_drive_column_exists($pdo, 'cpms_approval_documents', $column)) continue;
        $param = ':' . $column;
        $sets[] = $column . '=' . $param;
        $params[$param] = $value;
    }
    if (count($sets) === 0) {
        return array('ok' => false, 'message' => 'No completed PDF columns are available.');
    }
    try {
        $sql = "UPDATE cpms_approval_documents SET " . implode(',', $sets) . " WHERE id=:id";
        $pdo->prepare($sql)->execute($params);
        return array('ok' => true, 'message' => '');
    } catch (Exception $e) {
        return array('ok' => false, 'message' => $e->getMessage());
    }
}}

if (!function_exists('cpms_approval_pdf_mark_failed')) {
function cpms_approval_pdf_mark_failed($pdo, $approvalId, $message) {
    cpms_approval_pdf_ensure_document_columns($pdo);
    $message = cpms_drive_redact_text((string)$message);
    return cpms_approval_pdf_update_document($pdo, $approvalId, array(
        'completed_pdf_upload_status' => 'failed',
        'completed_pdf_upload_error' => $message
    ));
}}

if (!function_exists('cpms_approval_pdf_save_drive_record')) {
function cpms_approval_pdf_save_drive_record($pdo, $approvalId, $record) {
    if (!$pdo || (int)$approvalId <= 0 || !is_array($record)) {
        return array('ok' => false, 'message' => 'Invalid completed PDF Drive record.');
    }
    cpms_approval_pdf_ensure_document_columns($pdo);
    $fields = array(
        'completed_pdf_storage_type' => 'google_drive',
        'completed_pdf_drive_file_id' => isset($record['drive_file_id']) ? (string)$record['drive_file_id'] : '',
        'completed_pdf_drive_folder_id' => isset($record['drive_folder_id']) ? (string)$record['drive_folder_id'] : '',
        'completed_pdf_drive_web_view_link' => isset($record['drive_web_view_link']) ? (string)$record['drive_web_view_link'] : '',
        'completed_pdf_drive_web_content_link' => isset($record['drive_web_content_link']) ? (string)$record['drive_web_content_link'] : '',
        'completed_pdf_name' => isset($record['stored_name']) ? (string)$record['stored_name'] : '',
        'completed_pdf_mime_type' => isset($record['mime_type']) ? (string)$record['mime_type'] : 'application/pdf',
        'completed_pdf_size' => (isset($record['size']) && $record['size'] !== '') ? (int)$record['size'] : 0,
        'completed_pdf_uploaded_at' => isset($record['uploaded_at']) ? (string)$record['uploaded_at'] : date('Y-m-d H:i:s'),
        'completed_pdf_upload_status' => 'uploaded',
        'completed_pdf_upload_error' => ''
    );
    return cpms_approval_pdf_update_document($pdo, $approvalId, $fields);
}}

if (!function_exists('cpms_approval_pdf_upload_completed_pdf')) {
function cpms_approval_pdf_upload_completed_pdf($pdo, $approvalId, $userContext) {
    $result = array('ok' => false, 'skipped' => false, 'message' => '', 'record' => array());
    $approvalId = (int)$approvalId;
    if (!$pdo || $approvalId <= 0) {
        $result['message'] = 'Invalid approval document ID.';
        return $result;
    }

    cpms_approval_pdf_ensure_document_columns($pdo);
    $doc = cpms_approval_pdf_fetch_document($pdo, $approvalId);
    if (!$doc) {
        $result['message'] = 'Approval document was not found.';
        return $result;
    }
    $content = approval_parse_content(isset($doc['content']) ? $doc['content'] : '');
    if (isset($doc['completed_pdf_drive_file_id']) && trim((string)$doc['completed_pdf_drive_file_id']) !== '') {
        $result['ok'] = true;
        $result['skipped'] = true;
        $result['message'] = 'Completed PDF already uploaded.';
        return $result;
    }

    $context = cpms_approval_pdf_context($doc, $content, $userContext, 'generate');
    $pdf = cpms_approval_pdf_create_file($pdo, $approvalId);
    if (empty($pdf['ok'])) {
        $message = isset($pdf['message']) ? $pdf['message'] : 'Completed PDF generation failed.';
        cpms_approval_pdf_mark_failed($pdo, $approvalId, $message);
        cpms_approval_pdf_log_failure(array_merge($context, array('message' => 'PDF generation stage failed: ' . $message)));
        $result['message'] = $message;
        return $result;
    }

    $doc = isset($pdf['doc']) && is_array($pdf['doc']) ? $pdf['doc'] : $doc;
    $content = isset($pdf['content']) && is_array($pdf['content']) ? $pdf['content'] : $content;
    $completedDate = cpms_approval_pdf_completed_date($doc, $content);
    $year = (int)substr($completedDate, 0, 4);
    if ($year <= 0) $year = (int)date('Y');
    $folderContext = cpms_approval_pdf_context($doc, $content, $userContext, 'drive');
    $folder = cpms_drive_ensure_approval_folder($year, 'completed', $folderContext);
    if (empty($folder['ok'])) {
        $message = isset($folder['message']) ? $folder['message'] : 'Completed PDF Drive folder preparation failed.';
        cpms_approval_pdf_mark_failed($pdo, $approvalId, $message);
        cpms_approval_pdf_cleanup_temp_file($pdf['path']);
        $result['message'] = $message;
        return $result;
    }

    $folderContext['target_folder_id'] = (string)$folder['folder_id'];
    $folderContext['drive_folder_id'] = (string)$folder['folder_id'];
    $folderContext['original_name'] = isset($pdf['name']) ? (string)$pdf['name'] : '';
    $folderContext['stored_name'] = isset($pdf['name']) ? (string)$pdf['name'] : '';
    $folderContext['mime_type'] = 'application/pdf';
    $folderContext['size'] = isset($pdf['size']) ? (int)$pdf['size'] : 0;
    $folderContext['local_temp_path'] = isset($pdf['path']) ? (string)$pdf['path'] : '';
    $upload = cpms_drive_upload_file($pdf['path'], $pdf['name'], (string)$folder['folder_id'], 'application/pdf', $folderContext);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message = isset($upload['message']) ? $upload['message'] : 'Completed PDF Drive upload failed.';
        cpms_approval_pdf_mark_failed($pdo, $approvalId, $message);
        cpms_approval_pdf_cleanup_temp_file($pdf['path']);
        $result['message'] = $message;
        return $result;
    }

    $folderContext['uploaded_at'] = date('Y-m-d H:i:s');
    $record = cpms_drive_build_file_record($upload['file'], $folderContext);
    $record['upload_status'] = 'uploaded';
    $save = cpms_approval_pdf_save_drive_record($pdo, $approvalId, $record);
    if (empty($save['ok'])) {
        $delete = cpms_drive_delete_file($record['drive_file_id'], array_merge($folderContext, array(
            'section' => 'approval_completed_pdf_db_save',
            'message' => 'Deleting completed PDF because CPMS metadata save failed.'
        )));
        if (empty($delete['ok'])) {
            cpms_approval_pdf_log_failure(array_merge($folderContext, array(
                'section' => 'approval_completed_pdf_db_save',
                'message' => 'Completed PDF Drive upload succeeded, CPMS save failed, and cleanup delete failed: ' . (isset($save['message']) ? $save['message'] : '')
            )));
        } else {
            cpms_approval_pdf_log_failure(array_merge($folderContext, array(
                'section' => 'approval_completed_pdf_db_save',
                'message' => 'Completed PDF Drive upload succeeded but CPMS metadata save failed. Uploaded Drive file was deleted. ' . (isset($save['message']) ? $save['message'] : '')
            )));
        }
        cpms_approval_pdf_cleanup_temp_file($pdf['path']);
        $result['message'] = isset($save['message']) ? $save['message'] : 'Completed PDF metadata save failed.';
        return $result;
    }

    cpms_approval_pdf_cleanup_temp_file($pdf['path']);
    $result['ok'] = true;
    $result['message'] = 'Completed PDF uploaded.';
    $result['record'] = $record;
    return $result;
}}

if (!function_exists('cpms_approval_pdf_h')) {
function cpms_approval_pdf_h($value) {
    if (function_exists('h')) return h($value);
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_approval_pdf_links_html')) {
function cpms_approval_pdf_links_html($docRow) {
    if (!is_array($docRow) || !isset($docRow['id'])) return '';
    $status = strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : '')));
    if (!in_array($status, array('APPROVED', 'COMPLETED'), true)) return '';

    $id = (int)$docRow['id'];
    $fileId = isset($docRow['completed_pdf_drive_file_id']) ? trim((string)$docRow['completed_pdf_drive_file_id']) : '';
    $view = isset($docRow['completed_pdf_drive_web_view_link']) ? trim((string)$docRow['completed_pdf_drive_web_view_link']) : '';
    $download = isset($docRow['completed_pdf_drive_web_content_link']) ? trim((string)$docRow['completed_pdf_drive_web_content_link']) : '';
    $uploadStatus = isset($docRow['completed_pdf_upload_status']) ? strtolower(trim((string)$docRow['completed_pdf_upload_status'])) : '';
    $title = cpms_approval_pdf_h(urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20PDF'));
    $html = '<div class="no-print bg-white rounded-2xl border p-4">';
    $html .= '<div class="flex flex-wrap gap-2 items-center justify-between">';
    $html .= '<div class="font-extrabold text-gray-900">' . $title . '</div><div class="flex flex-wrap gap-2 items-center">';
    if ($fileId !== '' && $view !== '') {
        $html .= '<a href="' . cpms_approval_pdf_h('?r=approval_completed_pdf&id=' . $id) . '" target="_blank" class="px-3 py-2 bg-indigo-100 rounded">' . cpms_approval_pdf_h(urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20PDF%20%EB%B3%B4%EA%B8%B0')) . '</a>';
        if ($download !== '') {
            $html .= '<a href="' . cpms_approval_pdf_h('?r=approval_completed_pdf&id=' . $id . '&download=1') . '" class="px-3 py-2 bg-gray-100 rounded">' . cpms_approval_pdf_h(urldecode('%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C')) . '</a>';
        }
    } else if ($uploadStatus === 'failed') {
        $html .= '<span class="text-sm text-rose-700 font-bold">' . cpms_approval_pdf_h(urldecode('%50%44%46%20%EC%83%9D%EC%84%B1%20%EC%8B%A4%ED%8C%A8%20%2D%20%EA%B4%80%EB%A6%AC%EC%9E%90%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94')) . '</span>';
    } else {
        $html .= '<span class="text-sm text-gray-500">' . cpms_approval_pdf_h(urldecode('%50%44%46%20%EB%AF%B8%EC%83%9D%EC%84%B1')) . '</span>';
    }
    $html .= '</div></div></div>';
    return $html;
}}

if (!function_exists('cpms_approval_pdf_run_admin_check')) {
function cpms_approval_pdf_run_admin_check($userContext) {
    $result = array(
        'ok' => false,
        'tool' => array('ok' => false, 'message' => '', 'path' => ''),
        'temp_path' => array('ok' => false, 'message' => '', 'path' => ''),
        'create' => array('ok' => false, 'message' => ''),
        'approval_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'cleanup' => array('ok' => false, 'message' => ''),
        'test_file' => array()
    );

    $tool = cpms_approval_pdf_is_available();
    $result['tool'] = array(
        'ok' => !empty($tool['ok']),
        'message' => isset($tool['message']) ? $tool['message'] : '',
        'path' => isset($tool['path']) ? $tool['path'] : ''
    );
    $temp = cpms_approval_pdf_ensure_temp_dir();
    $result['temp_path'] = array(
        'ok' => !empty($temp['ok']),
        'message' => isset($temp['message']) ? $temp['message'] : '',
        'path' => isset($temp['path']) ? $temp['path'] : ''
    );
    if (empty($tool['ok']) || empty($temp['ok'])) {
        $result['create']['message'] = empty($tool['ok']) ? $result['tool']['message'] : $result['temp_path']['message'];
        return $result;
    }

    $testName = 'CPMS_Approval_Completed_PDF_Check_' . date('Ymd_His') . '.pdf';
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:"Malgun Gothic","Noto Sans CJK KR","NanumGothic",sans-serif;font-size:16px}.box{border:1px solid #111;padding:20px;width:720px}</style></head><body><div class="box"><h1>CPMS ' . cpms_approval_pdf_h(urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20%50%44%46%20%EC%A0%90%EA%B2%80')) . '</h1><p>' . cpms_approval_pdf_h(urldecode('%ED%95%9C%EA%B8%80%20%ED%91%9C%EC%8B%9C%EC%99%80%20%50%44%46%20%EC%83%9D%EC%84%B1%20%EC%97%AC%EB%B6%80%EB%A5%BC%20%ED%99%95%EC%9D%B8%ED%95%A9%EB%8B%88%EB%8B%A4.')) . '</p><p>' . cpms_approval_pdf_h(date('Y-m-d H:i:s')) . '</p></div></body></html>';
    $context = array(
        'user' => $userContext,
        'section' => 'admin_drive_check_completed_pdf',
        'document_type' => cpms_drive_approval_folder_name('completed'),
        'original_name' => $testName,
        'target_folder_id' => cpms_drive_folder_id('approval')
    );
    $pdf = cpms_approval_pdf_create_from_html($html, $testName, $context);
    $result['create'] = array(
        'ok' => !empty($pdf['ok']),
        'message' => isset($pdf['message']) ? $pdf['message'] : '',
        'path' => isset($pdf['path']) ? $pdf['path'] : '',
        'size' => isset($pdf['size']) ? (int)$pdf['size'] : 0
    );
    if (empty($pdf['ok'])) return $result;

    $folder = cpms_drive_ensure_approval_folder((int)date('Y'), 'completed', $context);
    $result['approval_folder'] = array(
        'ok' => !empty($folder['ok']),
        'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0,
        'message' => isset($folder['message']) ? $folder['message'] : ''
    );
    if (!empty($folder['ok']) && isset($folder['folder_id']) && trim((string)$folder['folder_id']) !== '') {
        $context['target_folder_id'] = (string)$folder['folder_id'];
        $context['drive_folder_id'] = (string)$folder['folder_id'];
        $context['mime_type'] = 'application/pdf';
        $context['size'] = isset($pdf['size']) ? (int)$pdf['size'] : 0;
        $upload = cpms_drive_upload_file($pdf['path'], $testName, (string)$folder['folder_id'], 'application/pdf', $context);
        $result['upload'] = array(
            'ok' => !empty($upload['ok']),
            'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0,
            'message' => isset($upload['message']) ? $upload['message'] : ''
        );
        if (!empty($upload['ok']) && isset($upload['file']) && is_array($upload['file'])) {
            $result['test_file'] = array(
                'id' => isset($upload['file']['id']) ? (string)$upload['file']['id'] : '',
                'name' => isset($upload['file']['name']) ? (string)$upload['file']['name'] : '',
                'webViewLink' => isset($upload['file']['webViewLink']) ? (string)$upload['file']['webViewLink'] : ''
            );
            $delete = cpms_drive_delete_file($result['test_file']['id'], $context);
            $result['delete'] = array(
                'ok' => !empty($delete['ok']),
                'http_code' => isset($delete['http_code']) ? (int)$delete['http_code'] : 0,
                'message' => isset($delete['message']) ? $delete['message'] : ''
            );
        }
    }

    $cleanup = cpms_approval_pdf_cleanup_temp_file($pdf['path']);
    $result['cleanup'] = array(
        'ok' => $cleanup ? true : false,
        'message' => $cleanup ? 'Temporary PDF file deleted.' : 'Temporary PDF file could not be deleted.'
    );
    $result['ok'] = (!empty($result['tool']['ok']) && !empty($result['temp_path']['ok']) && !empty($result['create']['ok']) && !empty($result['approval_folder']['ok']) && !empty($result['upload']['ok']) && !empty($result['delete']['ok']) && !empty($result['cleanup']['ok']));
    return $result;
}}
