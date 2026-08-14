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
        'completed_pdf_year' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_year VARCHAR(4) NULL",
        'completed_pdf_month' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_month VARCHAR(2) NULL",
        'completed_pdf_year_folder_id' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_year_folder_id VARCHAR(128) NULL",
        'completed_pdf_type_folder_id' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_type_folder_id VARCHAR(128) NULL",
        'completed_pdf_month_folder_id' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_month_folder_id VARCHAR(128) NULL",
        'completed_pdf_name' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_name VARCHAR(255) NULL",
        'completed_pdf_mime_type' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_mime_type VARCHAR(190) NULL",
        'completed_pdf_size' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_size BIGINT NULL",
        'completed_pdf_uploaded_at' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_uploaded_at DATETIME NULL",
        'completed_pdf_upload_status' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_upload_status VARCHAR(30) NULL",
        'completed_pdf_upload_error' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_upload_error TEXT NULL",
        'completed_pdf_render_version' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_render_version INT NULL"
    );
}}

if (!function_exists('cpms_approval_pdf_render_version')) {
function cpms_approval_pdf_render_version() {
    return 10;
}}

if (!function_exists('cpms_approval_pdf_ensure_document_columns')) {
function cpms_approval_pdf_ensure_document_columns($pdo) {
    if (!$pdo || !cpms_approval_drive_table_exists($pdo, 'cpms_approval_documents')) return false;
    $ok = true;
    $columns = cpms_approval_pdf_document_columns();
    $changed = false;
    $existingColumns = function_exists('approval_table_columns') ? approval_table_columns($pdo, 'cpms_approval_documents', false) : array();
    foreach ($columns as $column => $sql) {
        $columnExists = count($existingColumns) > 0 ? isset($existingColumns[$column]) : cpms_approval_drive_column_exists($pdo, 'cpms_approval_documents', $column);
        if ($columnExists) continue;
        try {
            $pdo->exec($sql);
            $changed = true;
        } catch (Exception $e) {
            $ok = false;
            cpms_approval_pdf_log_failure(array(
                'section' => 'approval_completed_pdf_schema',
                'message' => 'Completed PDF column creation failed: ' . $column . ' / ' . $e->getMessage()
            ));
        }
    }
    if ($changed && function_exists('approval_table_columns')) approval_table_columns($pdo, 'cpms_approval_documents', true);
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

if (!function_exists('cpms_approval_pdf_root')) {
function cpms_approval_pdf_root() {
    return dirname(dirname(__DIR__));
}}

if (!function_exists('cpms_approval_pdf_mpdf_path')) {
function cpms_approval_pdf_mpdf_path() {
    return cpms_approval_pdf_root() . '/app/lib/mpdf/mpdf.php';
}}

if (!function_exists('cpms_approval_pdf_mpdf_temp_dir')) {
function cpms_approval_pdf_mpdf_temp_dir() {
    return cpms_drive_storage_root() . '/tmp/mpdf';
}}

if (!function_exists('cpms_approval_pdf_ensure_mpdf_temp_dir')) {
function cpms_approval_pdf_ensure_mpdf_temp_dir() {
    $dir = cpms_approval_pdf_mpdf_temp_dir();
    if (!cpms_drive_ensure_dir($dir)) {
        return array('ok' => false, 'path' => $dir, 'message' => 'mPDF temp directory could not be created.');
    }
    if (!is_writable($dir)) {
        return array('ok' => false, 'path' => $dir, 'message' => 'mPDF temp directory is not writable.');
    }
    return array('ok' => true, 'path' => $dir, 'message' => 'mPDF temp directory is writable.');
}}

if (!function_exists('cpms_approval_pdf_load_mpdf')) {
function cpms_approval_pdf_load_mpdf($context) {
    static $loaded = null;
    if (is_array($loaded)) return $loaded;
    if (!is_array($context)) $context = array();

    $path = cpms_approval_pdf_mpdf_path();
    $result = array(
        'ok' => false,
        'path' => $path,
        'class_loaded' => false,
        'message' => ''
    );
    if (!is_file($path)) {
        $result['message'] = 'mPDF core file was not found: app/lib/mpdf/mpdf.php';
        cpms_approval_pdf_log_failure(array_merge($context, array(
            'section' => 'approval_completed_pdf_mpdf_file_missing',
            'message' => $result['message']
        )));
        $loaded = $result;
        return $result;
    }

    $temp = cpms_approval_pdf_ensure_mpdf_temp_dir();
    if (!empty($temp['ok']) && !defined('_MPDF_TEMP_PATH')) {
        define('_MPDF_TEMP_PATH', rtrim($temp['path'], '/\\') . '/');
    }

    require_once $path;
    if (!class_exists('mPDF', false)) {
        $result['message'] = 'mPDF class was not loaded from app/lib/mpdf/mpdf.php';
        cpms_approval_pdf_log_failure(array_merge($context, array(
            'section' => 'approval_completed_pdf_mpdf_load',
            'message' => $result['message']
        )));
        $loaded = $result;
        return $result;
    }

    $result['ok'] = true;
    $result['class_loaded'] = true;
    $result['message'] = 'mPDF library is available.';
    $loaded = $result;
    return $result;
}}

if (!function_exists('cpms_approval_pdf_mpdf_is_available')) {
function cpms_approval_pdf_mpdf_is_available($context) {
    return cpms_approval_pdf_load_mpdf($context);
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
    $mpdf = cpms_approval_pdf_mpdf_is_available(array('section' => 'approval_completed_pdf_tool_check'));
    if (!empty($mpdf['ok'])) {
        $mpdf['engine'] = 'mpdf';
        return $mpdf;
    }
    $wk = cpms_approval_pdf_find_wkhtmltopdf();
    $wk['engine'] = 'wkhtmltopdf';
    if (empty($wk['ok'])) {
        $wk['message'] = 'mPDF unavailable: ' . (isset($mpdf['message']) ? $mpdf['message'] : '') . ' / wkhtmltopdf unavailable: ' . (isset($wk['message']) ? $wk['message'] : '');
    }
    return $wk;
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

if (!function_exists('cpms_approval_pdf_cache_dir')) {
function cpms_approval_pdf_cache_dir() {
    return cpms_drive_storage_root() . '/cache/approval_completed_pdf';
}}

if (!function_exists('cpms_approval_pdf_cache_path')) {
function cpms_approval_pdf_cache_path($fileId) {
    $fileId = trim((string)$fileId);
    if ($fileId === '') return '';
    return rtrim(cpms_approval_pdf_cache_dir(), '/\\') . '/' . sha1($fileId) . '.pdf';
}}

if (!function_exists('cpms_approval_pdf_cache_get_path')) {
function cpms_approval_pdf_cache_get_path($fileId, $expectedSize) {
    $path = cpms_approval_pdf_cache_path($fileId);
    if ($path === '' || !is_file($path) || !is_readable($path)) return '';
    $size = (int)@filesize($path);
    $expectedSize = (int)$expectedSize;
    if ($size < 1024 || ($expectedSize > 0 && $size !== $expectedSize)) {
        @unlink($path);
        return '';
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    $head = @fread($fh, 4);
    @fclose($fh);
    if ($head !== '%PDF') {
        @unlink($path);
        return '';
    }
    return $path;
}}

if (!function_exists('cpms_approval_pdf_cache_commit')) {
function cpms_approval_pdf_cache_commit($fileId, $tempPath, $expectedSize) {
    $targetPath = cpms_approval_pdf_cache_path($fileId);
    $tempPath = trim((string)$tempPath);
    if ($targetPath === '' || $tempPath === '' || !is_file($tempPath)) return '';

    $existing = cpms_approval_pdf_cache_get_path($fileId, $expectedSize);
    if ($existing !== '') {
        @unlink($tempPath);
        return $existing;
    }
    if (is_file($targetPath)) @unlink($targetPath);
    if (!@rename($tempPath, $targetPath)) {
        @unlink($tempPath);
        return cpms_approval_pdf_cache_get_path($fileId, $expectedSize);
    }
    @chmod($targetPath, 0600);
    return cpms_approval_pdf_cache_get_path($fileId, $expectedSize);
}}

if (!function_exists('cpms_approval_pdf_cache_store_file')) {
function cpms_approval_pdf_cache_store_file($fileId, $sourcePath) {
    $sourcePath = trim((string)$sourcePath);
    if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) return '';
    $size = (int)@filesize($sourcePath);
    if ($size < 1024) return '';
    $existing = cpms_approval_pdf_cache_get_path($fileId, $size);
    if ($existing !== '') return $existing;
    $dir = cpms_approval_pdf_cache_dir();
    if (!cpms_drive_ensure_dir($dir) || !is_writable($dir)) return '';
    $targetPath = cpms_approval_pdf_cache_path($fileId);
    if ($targetPath === '') return '';
    $tempPath = $targetPath . '.' . uniqid('tmp_', true);
    if (!@copy($sourcePath, $tempPath)) return '';
    return cpms_approval_pdf_cache_commit($fileId, $tempPath, $size);
}}

if (!function_exists('cpms_approval_pdf_cache_store_content')) {
function cpms_approval_pdf_cache_store_content($fileId, $content) {
    $content = (string)$content;
    $size = strlen($content);
    if ($size < 1024 || substr($content, 0, 4) !== '%PDF') return '';
    $existing = cpms_approval_pdf_cache_get_path($fileId, $size);
    if ($existing !== '') return $existing;
    $dir = cpms_approval_pdf_cache_dir();
    if (!cpms_drive_ensure_dir($dir) || !is_writable($dir)) return '';
    $targetPath = cpms_approval_pdf_cache_path($fileId);
    if ($targetPath === '') return '';
    $tempPath = $targetPath . '.' . uniqid('tmp_', true);
    $written = @file_put_contents($tempPath, $content, LOCK_EX);
    if ($written === false || (int)$written !== $size) {
        @unlink($tempPath);
        return '';
    }
    return cpms_approval_pdf_cache_commit($fileId, $tempPath, $size);
}}

if (!function_exists('cpms_approval_pdf_cache_delete')) {
function cpms_approval_pdf_cache_delete($fileId) {
    $path = cpms_approval_pdf_cache_path($fileId);
    if ($path === '' || !is_file($path)) return true;
    return @unlink($path);
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
    if (!$pdo || (int)$approvalId <= 0 || !approval_is_proposal_doc_type($docType)) return $filesByType;
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

if (!function_exists('cpms_approval_pdf_embedded_image_data_uri')) {
function cpms_approval_pdf_embedded_image_data_uri($realPath, $mime) {
    $realPath = trim((string)$realPath);
    $mime = strtolower(trim((string)$mime));
    if ($realPath === '' || !is_file($realPath)) return '';

    $bytes = @file_get_contents($realPath);
    if ($bytes === false || $bytes === '') return '';
    $rawDataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);

    // Old mPDF versions can stop rendering the rest of a table when a large
    // transparent PNG is embedded. Convert approval signatures to a compact,
    // non-alpha JPEG before handing the HTML to mPDF. Keep the raw image as a
    // compatibility fallback when GD or the source decoder is unavailable.
    if (!function_exists('getimagesize') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return $rawDataUri;
    }
    $size = @getimagesize($realPath);
    if (!is_array($size) || !isset($size[0]) || !isset($size[1])) return $rawDataUri;
    $sourceWidth = (int)$size[0];
    $sourceHeight = (int)$size[1];
    if ($sourceWidth <= 0 || $sourceHeight <= 0) return $rawDataUri;

    $source = false;
    if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $source = @imagecreatefrompng($realPath);
    } else if (($mime === 'image/jpeg' || $mime === 'image/jpg') && function_exists('imagecreatefromjpeg')) {
        $source = @imagecreatefromjpeg($realPath);
    } else if ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
        $source = @imagecreatefromgif($realPath);
    }
    if (!$source) return $rawDataUri;

    $maxWidth = 480;
    $maxHeight = 240;
    $scale = min(1, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
    $targetWidth = max(1, (int)floor($sourceWidth * $scale));
    $targetHeight = max(1, (int)floor($sourceHeight * $scale));
    $target = @imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$target) {
        @imagedestroy($source);
        return $rawDataUri;
    }
    $white = @imagecolorallocate($target, 255, 255, 255);
    @imagefill($target, 0, 0, $white);
    @imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

    ob_start();
    $encoded = @imagejpeg($target, null, 88);
    $normalizedBytes = ob_get_clean();
    @imagedestroy($target);
    @imagedestroy($source);
    if (!$encoded || $normalizedBytes === false || $normalizedBytes === '') return $rawDataUri;
    return 'data:image/jpeg;base64,' . base64_encode($normalizedBytes);
}}

if (!function_exists('cpms_approval_pdf_embed_local_images')) {
function cpms_approval_pdf_embed_local_images($html) {
    $root = realpath(cpms_approval_pdf_root());
    $publicRoot = realpath(cpms_approval_pdf_root() . '/public');
    if ($root === false || $publicRoot === false) return (string)$html;
    $rootNormalized = rtrim(str_replace('\\', '/', $root), '/') . '/';

    return preg_replace_callback('/(<img\b[^>]*\bsrc=["\'])([^"\']+)(["\'])/i', function ($matches) use ($rootNormalized, $publicRoot) {
        $src = isset($matches[2]) ? trim((string)$matches[2]) : '';
        if ($src === '' || strpos($src, 'data:') === 0 || preg_match('#^https?://#i', $src)) {
            return $matches[0];
        }
        $decodedSrc = rawurldecode(preg_replace('/[?#].*$/', '', $src));
        $candidate = str_replace('\\', '/', $publicRoot . '/' . $decodedSrc);
        $realPath = realpath($candidate);
        if ($realPath === false || !is_file($realPath)) return $matches[0];
        $realNormalized = str_replace('\\', '/', $realPath);
        if (strpos($realNormalized, $rootNormalized) !== 0) return $matches[0];
        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $mimeMap = array(
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        );
        if (!isset($mimeMap[$extension])) return $matches[0];
        $dataUri = cpms_approval_pdf_embedded_image_data_uri($realPath, $mimeMap[$extension]);
        if ($dataUri === '') return $matches[0];
        return $matches[1] . $dataUri . $matches[3];
    }, (string)$html);
}}

if (!function_exists('cpms_approval_pdf_leave_sign_html')) {
function cpms_approval_pdf_leave_sign_html($line, $email, $alwaysShow) {
    $line = is_array($line) ? $line : array();
    $status = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
    if ($status === 'CEO_APPROVED') {
        return '<span style="font-size:11px;font-weight:bold;color:#111">' . h(approval_status_label('CEO_APPROVED')) . '</span>';
    }
    $isDelegated = ($status === 'DELEGATED' || (isset($line['is_delegated']) && (int)$line['is_delegated'] === 1));
    if ($isDelegated) {
        return '<span style="font-size:11px;font-weight:bold;color:#111">' . h(approval_status_label('DELEGATED')) . '</span>';
    }
    $approved = ($status === 'APPROVED' || $status === 'SKIPPED' || $alwaysShow);
    $path = approval_sign_path_from_line($line, $email);
    if ($approved && $path !== '') {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $canNormalize = function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
        if (($extension === 'png' && (!$canNormalize || !function_exists('imagecreatefrompng')))
            || ($extension === 'gif' && (!$canNormalize || !function_exists('imagecreatefromgif')))
            || $extension === 'webp') {
            return '<span style="font-size:10px;color:#444">' . h(approval_ko('%EC%84%9C%EB%AA%85%EC%99%84%EB%A3%8C')) . '</span>';
        }
        // mPDF 6 calculates table widths from an image's natural pixel size
        // before applying max-width. A fixed PDF height keeps large signature
        // sources from expanding the table and pushing the document body away.
        return '<img src="' . h('../' . $path) . '" style="display:inline-block;width:auto;height:8mm;vertical-align:middle">';
    }
    if ($approved) {
        return '<span style="font-size:10px;color:#444">' . h(approval_ko('%EC%84%9C%EB%AA%85%EC%99%84%EB%A3%8C')) . '</span>';
    }
    return '<span style="font-size:10px;color:#666">' . h(approval_line_status_label($status !== '' ? $status : 'WAITING')) . '</span>';
}}

if (!function_exists('cpms_approval_pdf_render_leave_body')) {
function cpms_approval_pdf_render_leave_body($data, $lines) {
    $data = is_array($data) ? $data : array();
    $lines = is_array($lines) ? $lines : array();
    $requestType = approval_doc_get($data, 'request_type', approval_ko('%EC%97%B0%EC%B0%A8'));
    $requestTypeEtc = approval_doc_get($data, 'request_type_etc', '');
    if ($requestTypeEtc !== '' && $requestType === approval_ko('%EA%B8%B0%ED%83%80')) {
        $requestType .= ' (' . $requestTypeEtc . ')';
    }
    $startDate = approval_doc_get($data, 'leave_start_date', '');
    $endDate = approval_doc_get($data, 'leave_end_date', '');
    $leaveDays = approval_doc_get($data, 'leave_days', '');
    $period = trim($startDate . ($endDate !== '' ? ' ~ ' . $endDate : ''));
    if ($leaveDays !== '') $period .= ' / ' . $leaveDays . approval_ko('%EC%9D%BC');
    $requestDate = approval_doc_get($data, 'request_date', '');
    $applicantName = approval_doc_get($data, 'applicant_sign_name', approval_doc_get($data, 'applicant_name', ''));
    $applicantEmail = approval_doc_get($data, 'applicant_email', approval_doc_get($data, 'writer_email', ''));
    $applicantSign = cpms_approval_pdf_leave_sign_html(array(), $applicantEmail, true);

    ob_start();
    echo '<div lang="ko" style="width:100%;color:#000;font-family:UHC,Malgun Gothic,Arial,sans-serif;font-size:12px">';
    echo '<div style="border:2px solid #000;padding:16px">';
    echo '<div style="text-align:center;font-size:32px;line-height:1.3;font-weight:bold;border-bottom:3px solid #000;padding:0 0 10px 0;margin:0 0 12px 0">' . h(approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84')) . '</div>';

    echo '<table style="width:100%;border-collapse:collapse;table-layout:fixed;margin:0 0 12px 0">';
    echo '<tr><th rowspan="4" style="width:34px;border:1px solid #000;padding:4px;text-align:center">' . h(approval_ko('%EA%B2%B0%EC%9E%AC')) . '</th>';
    if (count($lines) === 0) {
        echo '<th style="border:1px solid #000;padding:4px;text-align:center">-</th></tr>';
        echo '<tr><td style="height:46px;border:1px solid #000;text-align:center">-</td></tr>';
        echo '<tr><td style="border:1px solid #000;padding:4px;text-align:center">-</td></tr>';
        echo '<tr><td style="border:1px solid #000;padding:4px;text-align:center">-</td></tr>';
    } else {
        for ($i = 0; $i < count($lines); $i++) {
            $role = isset($lines[$i]['role_type']) ? $lines[$i]['role_type'] : (isset($lines[$i]['role']) ? $lines[$i]['role'] : '');
            echo '<th style="border:1px solid #000;padding:4px;text-align:center;font-size:11px">' . h(approval_role_label($role)) . '</th>';
        }
        echo '</tr><tr>';
        for ($i = 0; $i < count($lines); $i++) {
            $lineEmail = isset($lines[$i]['approver_email']) ? (string)$lines[$i]['approver_email'] : '';
            echo '<td style="height:46px;border:1px solid #000;padding:2px;text-align:center;vertical-align:middle">' . cpms_approval_pdf_leave_sign_html($lines[$i], $lineEmail, false) . '</td>';
        }
        echo '</tr><tr>';
        for ($i = 0; $i < count($lines); $i++) {
            $lineName = isset($lines[$i]['approver_name']) ? approval_display_name_only($lines[$i]['approver_name']) : '';
            echo '<td style="border:1px solid #000;padding:4px;text-align:center;font-weight:bold;font-size:11px">' . h($lineName !== '' ? $lineName : '-') . '</td>';
        }
        echo '</tr><tr>';
        for ($i = 0; $i < count($lines); $i++) {
            $actedAt = isset($lines[$i]['acted_at']) ? trim((string)$lines[$i]['acted_at']) : '';
            $lineStatus = isset($lines[$i]['line_status']) ? strtoupper(trim((string)$lines[$i]['line_status'])) : '';
            $timeText = $actedAt !== '' ? $actedAt : approval_line_status_label($lineStatus !== '' ? $lineStatus : 'WAITING');
            echo '<td style="border:1px solid #000;padding:4px;text-align:center;font-size:9px;color:#333">' . h($timeText) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';

    echo '<table style="width:100%;border-collapse:collapse;table-layout:fixed;color:#000">';
    echo '<tr><th style="width:82px;border:1px solid #000;padding:7px;text-align:center;background:#f2f2f2">' . h(approval_ko('%EC%8B%A0%EC%B2%AD%EA%B5%AC%EB%B6%84')) . '</th><td colspan="3" style="border:1px solid #000;padding:7px">' . h($requestType) . '</td></tr>';
    echo '<tr><th style="border:1px solid #000;padding:7px;text-align:center;background:#f2f2f2">' . h(approval_ko('%EC%86%8C%EC%86%8D')) . '</th><td style="border:1px solid #000;padding:7px">' . h(approval_doc_get($data, 'department', '-')) . '</td><th style="width:82px;border:1px solid #000;padding:7px;text-align:center;background:#f2f2f2">' . h(approval_ko('%EC%A7%81%EC%9C%84')) . '</th><td style="border:1px solid #000;padding:7px">' . h(approval_doc_get($data, 'position', '-')) . '</td></tr>';
    echo '<tr><th style="border:1px solid #000;padding:7px;text-align:center;background:#f2f2f2">' . h(approval_ko('%EC%84%B1%EB%AA%85')) . '</th><td style="border:1px solid #000;padding:7px">' . h(approval_doc_get($data, 'applicant_name', '-')) . '</td><th style="border:1px solid #000;padding:7px;text-align:center;background:#f2f2f2">' . h(approval_ko('%EC%83%9D%EB%85%84%EC%9B%94%EC%9D%BC')) . '</th><td style="border:1px solid #000;padding:7px">' . h(approval_doc_get($data, 'birth_date', '-')) . '</td></tr>';
    echo '<tr><th style="border:1px solid #000;padding:7px;text-align:center;background:#f2f2f2">' . h(approval_ko('%ED%9C%B4%EA%B0%80%EA%B8%B0%EA%B0%84')) . '</th><td colspan="3" style="border:1px solid #000;padding:7px">' . h($period !== '' ? $period : '-') . '</td></tr>';
    echo '<tr><th style="border:1px solid #000;padding:7px;text-align:center;background:#f2f2f2">' . h(approval_ko('%ED%9C%B4%EA%B0%80%EC%82%AC%EC%9C%A0')) . '</th><td colspan="3" style="height:180px;border:1px solid #000;padding:10px;vertical-align:top;line-height:1.6">' . nl2br(h(approval_doc_get($data, 'leave_reason', '-'))) . '<div style="margin-top:48px;font-size:11px;line-height:1.5">' . h(approval_default_leave_agreement()) . '</div></td></tr>';
    echo '</table>';

    echo '<div style="text-align:center;font-size:22px;font-weight:bold;margin:28px 0 18px 0">' . h($requestDate !== '' ? $requestDate : date('Y-m-d')) . '</div>';
    echo '<table style="width:100%;border-collapse:collapse"><tr><td style="border:0;text-align:right;font-size:17px;font-weight:bold;vertical-align:middle">' . h(approval_ko('%EC%8B%A0%EC%B2%AD%EC%9D%B8')) . '&nbsp;&nbsp;' . h($applicantName !== '' ? $applicantName : '-') . '&nbsp;&nbsp;</td><td style="width:115px;height:44px;border:0;text-align:center;vertical-align:middle">' . $applicantSign . '</td><td style="width:100px;border:0;text-align:left;font-size:12px">(' . h(approval_ko('%EC%9D%B8%20%EB%98%90%EB%8A%94%20%EC%84%9C%EB%AA%85')) . ')</td></tr></table>';
    echo '<div style="text-align:center;font-size:26px;font-weight:bold;margin-top:24px">' . h(approval_ko('%EC%A3%BC%EC%8B%9D%ED%9A%8C%EC%82%AC%20%EC%B0%BD%EB%AA%85%EA%B1%B4%EC%84%A4')) . '</div>';
    echo '</div></div>';
    return ob_get_clean();
}}

if (!function_exists('cpms_approval_pdf_safe_style')) {
function cpms_approval_pdf_safe_style() {
    return '<style>'
        . '@page{margin:5mm}'
        . 'html,body{margin:0;padding:0;background:#fff;color:#111;font-family:unbatang,"Malgun Gothic","Noto Sans CJK KR",sans-serif;font-size:9.5px;line-height:1.45}'
        . '.pdf-document{border:0.35mm solid #111;padding:4.5mm;height:276mm;min-height:276mm;box-sizing:border-box}'
        . '.pdf-title{text-align:center;font-size:10mm;font-weight:bold;letter-spacing:0;border-bottom:0.65mm solid #111;padding:1.5mm 0 3.5mm 0;margin:0 0 2.5mm 0}'
        . '.pdf-table{width:100%;border-collapse:collapse;table-layout:fixed;margin:0 0 2.2mm 0;page-break-inside:auto}'
        . '.pdf-table th,.pdf-table td{border:0.3mm solid #222;padding:1.2mm 1.6mm;vertical-align:middle;word-wrap:break-word}'
        . '.pdf-table th{background:#fff;text-align:center;font-weight:bold}'
        . '.pdf-approval-table{page-break-inside:avoid;table-layout:fixed;font-size:7.5px}'
        . '.pdf-approval-table th,.pdf-approval-table td{text-align:center;padding:0.7mm;line-height:1.2}'
        . '.pdf-approval-table .pdf-sign-cell{height:11mm;vertical-align:middle}'
        . '.pdf-approval-table .pdf-sign-cell img{display:inline-block;max-width:21mm;max-height:9mm;width:auto;height:auto;vertical-align:middle}'
        . '.pdf-approval-table .pdf-name-cell{font-weight:bold}'
        . '.pdf-approval-table .pdf-time-cell{height:9mm;font-size:6px;color:#444}'
        . '.pdf-approval-side-top{border-bottom:0!important}'
        . '.pdf-approval-side-middle{border-top:0!important;border-bottom:0!important;font-size:9px;font-weight:bold}'
        . '.pdf-approval-side-bottom{border-top:0!important}'
        . '.pdf-line-messages{font-size:7px;color:#34495e;line-height:1.45;margin:1.5mm 0 2mm 0}'
        . '.pdf-leave-approval{margin-bottom:2.5mm}'
        . '.pdf-leave-form th{width:18mm}'
        . '.pdf-leave-form td{font-size:8.5px}'
        . '.pdf-leave-reason{height:44mm;vertical-align:top!important;line-height:1.65}'
        . '.pdf-date{text-align:center;font-size:8mm;font-weight:bold;line-height:1.2;margin:8mm 0 5mm 0}'
        . '.pdf-applicant-table{width:72%;margin-left:28%;border-collapse:collapse;table-layout:fixed}'
        . '.pdf-applicant-table td{border:0;padding:0;text-align:center;font-size:5mm;font-weight:bold;vertical-align:middle;white-space:nowrap}'
        . '.pdf-applicant-table .pdf-applicant-sign{width:30mm;height:13mm}'
        . '.pdf-applicant-table .pdf-applicant-sign img{display:inline-block;max-width:28mm;max-height:12mm;width:auto;height:auto;vertical-align:middle}'
        . '.pdf-company{text-align:center;font-size:9mm;font-weight:bold;margin-top:5mm}'
        . '.pdf-proposal-head-layout{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:2mm}'
        . '.pdf-proposal-head-layout>tbody>tr>td{border:0;padding:0;vertical-align:top}'
        . '.pdf-proposal-meta{margin:0}'
        . '.pdf-proposal-meta th{width:20mm}'
        . '.pdf-proposal-approval{margin:0}'
        . '.pdf-proposal-small-table{margin-bottom:1.5mm}'
        . '.pdf-proposal-small-table th{width:23mm}'
        . '.pdf-proposal-body{font-size:9px;line-height:1.85;padding:1mm 0}'
        . '.pdf-proposal-next{text-align:center;margin:3mm 0}'
        . '.pdf-proposal-note{margin:2mm 0}'
        . '.pdf-proposal-note th{width:26mm;vertical-align:top;padding-top:2mm}'
        . '.pdf-proposal-note td{height:30mm;vertical-align:top;white-space:pre-wrap;line-height:1.6}'
        . '.pdf-attachments{border:0.35mm solid #333;padding:2.5mm;margin-top:7mm;line-height:1.65;page-break-inside:avoid}'
        . '.pdf-attachment-row{border-top:0.2mm solid #bbb;padding-top:1mm;margin-top:1mm}'
        . '.pdf-bold{font-weight:bold}'
        . '</style>';
}}

if (!function_exists('cpms_approval_pdf_render_approval_table')) {
function cpms_approval_pdf_render_approval_table($lines, $drafter) {
    $lines = is_array($lines) ? $lines : array();
    $drafter = is_array($drafter) ? $drafter : array();
    $cells = array();
    if (count($drafter) > 0) {
        $cells[] = array(
            'role_type' => approval_ko('%EB%8B%B4%EB%8B%B9'),
            'approver_name' => isset($drafter['name']) ? (string)$drafter['name'] : '',
            'approver_email' => isset($drafter['email']) ? (string)$drafter['email'] : '',
            'line_status' => 'APPROVED',
            'acted_at' => '-'
        );
    }
    for ($i = 0; $i < count($lines); $i++) {
        if (is_array($lines[$i])) $cells[] = $lines[$i];
    }
    if (count($cells) === 0) {
        $cells[] = array('role_type' => '-', 'approver_name' => '-', 'line_status' => '', 'acted_at' => '-');
    }

    // Match the on-screen horizontal signature matrix without rowspan. Legacy
    // mPDF can drop everything after a rowspan that contains signature images.
    $html = '<table class="pdf-table pdf-approval-table">';
    $html .= '<tr><th class="pdf-approval-side-top" style="width:6mm"></th>';
    for ($i = 0; $i < count($cells); $i++) {
        $role = isset($cells[$i]['role_type']) ? $cells[$i]['role_type'] : (isset($cells[$i]['role']) ? $cells[$i]['role'] : '');
        $html .= '<th>' . h(approval_role_label($role)) . '</th>';
    }
    $html .= '</tr><tr><th class="pdf-approval-side-middle">' . h(approval_ko('%EA%B2%B0')) . '</th>';
    for ($i = 0; $i < count($cells); $i++) {
        $email = isset($cells[$i]['approver_email']) ? (string)$cells[$i]['approver_email'] : '';
        $html .= '<td class="pdf-sign-cell">' . cpms_approval_pdf_leave_sign_html($cells[$i], $email, false) . '</td>';
    }
    $html .= '</tr><tr><th class="pdf-approval-side-middle">' . h(approval_ko('%EC%9E%AC')) . '</th>';
    for ($i = 0; $i < count($cells); $i++) {
        $name = isset($cells[$i]['approver_name']) ? approval_display_name_only($cells[$i]['approver_name']) : '';
        $html .= '<td class="pdf-name-cell">' . h($name !== '' ? $name : '-') . '</td>';
    }
    $html .= '</tr><tr><th class="pdf-approval-side-bottom"></th>';
    for ($i = 0; $i < count($cells); $i++) {
        $actedAt = isset($cells[$i]['acted_at']) ? trim((string)$cells[$i]['acted_at']) : '';
        $status = isset($cells[$i]['line_status']) ? strtoupper(trim((string)$cells[$i]['line_status'])) : '';
        $timeText = $actedAt !== '' ? $actedAt : approval_line_status_label($status !== '' ? $status : 'WAITING');
        if ($status === 'CEO_APPROVED' || $status === 'CEO_DIRECT_APPROVE') {
            $timeText = approval_ko('%EB%8C%80%ED%91%9C%EC%8A%B9%EC%9D%B8') . ($actedAt !== '' ? '<br>' . h($actedAt) : '');
        } else if ($status === 'DELEGATED') {
            $note = isset($cells[$i]['reject_reason']) ? trim((string)$cells[$i]['reject_reason']) : '';
            $timeText = approval_status_label('DELEGATED');
            if ($note !== '') $timeText .= '<br>' . h($note);
            if ($actedAt !== '') $timeText .= '<br>' . h($actedAt);
        }
        $html .= '<td class="pdf-time-cell">' . (($status === 'CEO_APPROVED' || $status === 'CEO_DIRECT_APPROVE' || $status === 'DELEGATED') ? $timeText : h($timeText)) . '</td>';
    }
    $html .= '</tr></table>';
    return $html;
}}

if (!function_exists('cpms_approval_pdf_render_line_messages')) {
function cpms_approval_pdf_render_line_messages($data, $lines) {
    $messages = array();
    $parts = array();
    $data = is_array($data) ? $data : array();
    $lines = is_array($lines) ? $lines : array();
    for ($i = 0; $i < count($lines); $i++) {
        if (!is_array($lines[$i])) continue;
        $role = isset($lines[$i]['role_type']) ? $lines[$i]['role_type'] : (isset($lines[$i]['role']) ? $lines[$i]['role'] : '');
        $name = isset($lines[$i]['approver_name']) ? approval_display_name_only($lines[$i]['approver_name']) : '';
        if ($name !== '') $parts[] = approval_role_label($role) . ' ' . $name;
    }
    if (count($parts) > 0) {
        $messages[] = approval_ko('%EC%9E%90%EB%8F%99%20%EC%83%9D%EC%84%B1%EB%90%9C%20%EA%B2%B0%EC%9E%AC%EB%9D%BC%EC%9D%B8') . ': ' . implode(' -> ', $parts);
    }
    foreach (array('approval_line_messages', 'approval_line_warnings') as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) continue;
        for ($i = 0; $i < count($data[$key]); $i++) {
            $message = trim((string)$data[$key][$i]);
            if ($message !== '' && !in_array($message, $messages, true)) $messages[] = $message;
        }
    }
    if (count($messages) === 0) return '';
    $html = '<div class="pdf-line-messages">';
    for ($i = 0; $i < count($messages); $i++) $html .= '<div>' . h($messages[$i]) . '</div>';
    return $html . '</div>';
}}

if (!function_exists('cpms_approval_pdf_render_safe_leave_body')) {
function cpms_approval_pdf_render_safe_leave_body($data, $lines) {
    $data = is_array($data) ? $data : array();
    $lines = is_array($lines) ? $lines : array();
    $requestType = approval_doc_get($data, 'request_type', approval_ko('%EC%97%B0%EC%B0%A8'));
    $requestTypeEtc = approval_doc_get($data, 'request_type_etc', '');
    if ($requestType === approval_ko('%EA%B8%B0%ED%83%80') && $requestTypeEtc !== '') $requestType .= ' (' . $requestTypeEtc . ')';
    $startDate = approval_doc_get($data, 'leave_start_date', '');
    $endDate = approval_doc_get($data, 'leave_end_date', '');
    $days = approval_doc_get($data, 'leave_days', '');
    $period = trim($startDate . ($endDate !== '' ? ' ~ ' . $endDate : ''));
    if ($days !== '') $period .= ' / ' . $days . approval_ko('%EC%9D%BC');
    $applicantName = approval_doc_get($data, 'applicant_sign_name', approval_doc_get($data, 'applicant_name', '-'));
    $applicantEmail = approval_doc_get($data, 'applicant_email', approval_doc_get($data, 'writer_email', ''));
    $applicantSign = cpms_approval_pdf_leave_sign_html(array(), $applicantEmail, true);
    $requestDate = approval_doc_get($data, 'request_date', '');
    if ($requestDate === '') $requestDate = date('Y-m-d');
    $approvalWidth = count($lines) * 14 + 8;
    if ($approvalWidth < 50) $approvalWidth = 50;
    if ($approvalWidth > 100) $approvalWidth = 100;
    $approvalMargin = 100 - $approvalWidth;

    $html = '<div class="pdf-document pdf-leave-document">';
    $html .= '<div class="pdf-title">' . h(approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84')) . '</div>';
    $html .= '<div class="pdf-leave-approval" style="width:' . $approvalWidth . '%;margin-left:' . $approvalMargin . '%">' . cpms_approval_pdf_render_approval_table($lines, array()) . '</div>';
    $html .= cpms_approval_pdf_render_line_messages($data, $lines);
    $html .= '<table class="pdf-table pdf-leave-form">';
    $html .= '<tr><th>' . h(approval_ko('%EC%8B%A0%EC%B2%AD%EA%B5%AC%EB%B6%84')) . '</th><td colspan="3">' . h($requestType) . '</td></tr>';
    $html .= '<tr><th>' . h(approval_ko('%EC%86%8C%EC%86%8D')) . '</th><td>' . h(approval_doc_get($data, 'department', '-')) . '</td><th>' . h(approval_ko('%EC%A7%81%EC%9C%84')) . '</th><td>' . h(approval_doc_get($data, 'position', '-')) . '</td></tr>';
    $html .= '<tr><th>' . h(approval_ko('%EC%84%B1%EB%AA%85')) . '</th><td>' . h(approval_doc_get($data, 'applicant_name', '-')) . '</td><th>' . h(approval_ko('%EC%83%9D%EB%85%84%EC%9B%94%EC%9D%BC')) . '</th><td>' . h(approval_doc_get($data, 'birth_date', '-')) . '</td></tr>';
    $html .= '<tr><th>' . h(approval_ko('%ED%9C%B4%EA%B0%80%EA%B8%B0%EA%B0%84')) . '</th><td colspan="3">' . h($period !== '' ? $period : '-') . '</td></tr>';
    $html .= '<tr><th>' . h(approval_ko('%ED%9C%B4%EA%B0%80%EC%82%AC%EC%9C%A0')) . '</th><td colspan="3" class="pdf-leave-reason">' . nl2br(h(approval_doc_get($data, 'leave_reason', '-'))) . '<div style="margin-top:12mm">' . h(approval_default_leave_agreement()) . '</div></td></tr>';
    $html .= '</table>';
    $html .= '<div class="pdf-date">' . h($requestDate) . '</div>';
    $html .= '<table class="pdf-applicant-table"><tr><td style="width:46mm">' . h(approval_ko('%EC%8B%A0%EC%B2%AD%EC%9D%B8')) . '&nbsp;&nbsp;' . h($applicantName) . '</td><td class="pdf-applicant-sign">' . $applicantSign . '</td><td style="width:28mm;font-size:3.8mm">(' . h(approval_ko('%EC%9D%B8%20%EB%98%90%EB%8A%94%20%EC%84%9C%EB%AA%85')) . ')</td></tr></table>';
    $html .= '<div class="pdf-company">' . h(approval_ko('%EC%A3%BC%EC%8B%9D%ED%9A%8C%EC%82%AC%20%EC%B0%BD%EB%AA%85%EA%B1%B4%EC%84%A4')) . '</div>';
    $html .= '</div>';
    return $html;
}}

if (!function_exists('cpms_approval_pdf_attachment_names')) {
function cpms_approval_pdf_attachment_names($filesByType) {
    $names = array();
    $filesByType = is_array($filesByType) ? $filesByType : array();
    foreach ($filesByType as $fileValue) {
        $list = (is_array($fileValue) && isset($fileValue['original_name'])) ? array($fileValue) : $fileValue;
        if (!is_array($list)) continue;
        for ($i = 0; $i < count($list); $i++) {
            if (!is_array($list[$i])) continue;
            $name = isset($list[$i]['original_name']) ? trim((string)$list[$i]['original_name']) : '';
            if ($name !== '') $names[] = $name;
        }
    }
    return $names;
}}

if (!function_exists('cpms_approval_pdf_render_safe_proposal_body')) {
function cpms_approval_pdf_render_safe_proposal_body($data, $lines, $filesByType) {
    $data = is_array($data) ? $data : array();
    $lines = is_array($lines) ? $lines : array();
    $filesByType = is_array($filesByType) ? $filesByType : array();
    $drafter = array(
        'name' => approval_doc_get($data, 'drafter_name', '-'),
        'email' => approval_doc_get($data, 'writer_email', '')
    );
    $specialNote = approval_doc_get($data, 'special_note', '');
    if ($specialNote === '') {
        $legacyNotes = array();
        foreach (array('special_note_1', 'special_note_2') as $legacyKey) {
            $legacyValue = approval_doc_get($data, $legacyKey, '');
            if ($legacyValue !== '') $legacyNotes[] = $legacyValue;
        }
        if (count($legacyNotes) > 0) $specialNote = implode("\n", $legacyNotes);
    }
    $headline = approval_doc_get($data, 'headline', '');
    $introText = approval_doc_get($data, 'intro_text', approval_ko('%EC%95%84%EB%9E%98%EC%99%80%20%EA%B0%99%EC%9D%B4%20%EA%B8%B0%EC%95%88%ED%95%98%EC%98%A4%EB%8B%88%20%EA%B2%80%ED%86%A0%ED%95%98%EC%8B%9C%EC%96%B4%20%EA%B2%B0%EC%9E%AC%ED%95%98%EC%97%AC%20%EC%A3%BC%EC%8B%9C%EA%B8%B0%20%EB%B0%94%EB%9E%8D%EB%8B%88%EB%8B%A4.'));
    $html = '<div class="pdf-document pdf-proposal-document">';
    $html .= '<div class="pdf-title">' . h(approval_ko('%EA%B8%B0%EC%95%88%EC%84%9C')) . '</div>';
    $html .= '<table class="pdf-proposal-head-layout"><tr><td style="width:34%;padding-right:1.5mm">';
    $html .= '<table class="pdf-table pdf-proposal-meta">';
    $html .= '<tr><th>' . h(approval_ko('%EA%B8%B0%EC%95%88%EC%9D%BC%EC%9E%90')) . '</th><td>' . h(approval_doc_get($data, 'draft_date', '-')) . '</td></tr>';
    $html .= '<tr><th>' . h(approval_ko('%EC%8B%9C%ED%96%89%EC%9D%BC%EC%9E%90')) . '</th><td>' . h(approval_doc_get($data, 'effective_date', '-')) . '</td></tr>';
    $html .= '<tr><th>' . h(approval_ko('%EA%B8%B0%EC%95%88%EB%B6%80%EC%84%9C')) . '</th><td>' . h(approval_doc_get($data, 'draft_department', '-')) . '</td></tr>';
    $html .= '<tr><th>' . h(approval_ko('%EA%B8%B0%EC%95%88%EC%9E%90')) . '</th><td>' . h(approval_doc_get($data, 'drafter_name', '-')) . '</td></tr>';
    $html .= '</table></td><td style="width:66%;padding-left:1.5mm"><div class="pdf-proposal-approval">';
    $html .= cpms_approval_pdf_render_approval_table($lines, $drafter);
    $html .= '</div></td></tr></table>';
    $html .= cpms_approval_pdf_render_line_messages($data, $lines);
    $html .= '<table class="pdf-table pdf-proposal-small-table"><tr><th>' . h(approval_ko('%EA%B8%B0%EC%95%88%EA%B5%AC%EB%B6%84')) . '</th><td>' . h(approval_doc_get($data, 'draft_type', approval_ko('%ED%92%88%EC%9D%98'))) . '</td></tr></table>';
    $html .= '<table class="pdf-table pdf-proposal-small-table"><tr><th>' . h(approval_ko('%EC%A0%9C%EB%AA%A9')) . '</th><td class="pdf-bold">' . h(approval_doc_get($data, 'title', '-')) . '</td></tr></table>';
    $html .= '<div class="pdf-proposal-body">';
    if ($headline !== '') $html .= nl2br(h($headline)) . '<br>';
    $html .= nl2br(h($introText));
    $html .= '<div class="pdf-proposal-next">- ' . h(approval_ko('%EB%8B%A4%20%EC%9D%8C')) . ' -</div>';
    $html .= '1. ' . h(approval_ko('%EC%82%AC%EC%9C%A0')) . ' : ' . nl2br(h(approval_doc_get($data, 'reason', '-'))) . '<br>';
    $html .= '2. ' . h(approval_ko('%EB%82%B4%EC%9A%A9')) . ' : 1) ' . h(approval_ko('%EC%97%85%EC%B2%B4%EB%AA%85')) . ' : ' . h(approval_doc_get($data, 'company_name', '-')) . '<br>';
    $html .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;2) ' . h(approval_ko('%EB%B0%9C%EC%A3%BC%EA%B8%88%EC%95%A1')) . ' : ' . h(approval_doc_format_amount(approval_doc_get($data, 'contract_amount', ''))) . ' ' . h(approval_ko('%EC%9B%90')) . '<br>';
    $html .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;3) ' . h(approval_ko('%EC%84%A0%EA%B8%88%20%EC%A7%80%EA%B8%89%20%EC%9A%94%EC%B2%AD%EC%95%A1')) . ' : ' . h(approval_doc_format_amount(approval_doc_get($data, 'advance_amount', ''))) . ' ' . h(approval_ko('%EC%9B%90'));
    $html .= '<table class="pdf-table pdf-proposal-note"><tr><th>3. ' . h(approval_ko('%ED%8A%B9%EA%B8%B0%EC%82%AC%ED%95%AD')) . '</th><td>' . h($specialNote !== '' ? $specialNote : '-') . '</td></tr></table>';
    $html .= '4. ' . h(approval_ko('%EC%A7%80%EA%B8%89%EC%9A%94%EC%B2%AD%EC%9D%BC')) . ' : ' . h(approval_doc_get($data, 'payment_request_date', '-')) . '<br>';
    $html .= '5. ' . h(approval_ko('%EC%98%88%EC%82%B0%ED%98%84%ED%99%A9')) . ' : ' . nl2br(h(approval_doc_format_amount_text(approval_doc_get($data, 'budget_status', '-'))));
    $html .= '</div>';
    $labels = array(
        'order_doc' => approval_ko('%EB%B0%9C%EC%A3%BC%EC%84%9C'),
        'business_license' => approval_ko('%EC%82%AC%EC%97%85%EC%9E%90%EB%93%B1%EB%A1%9D%EC%A6%9D'),
        'etc' => approval_ko('%EA%B8%B0%ED%83%80')
    );
    $html .= '<div class="pdf-attachments"><strong>' . h(approval_ko('%EC%B2%A8%EB%B6%80%EC%84%9C%EB%A5%98')) . '</strong>';
    foreach ($labels as $fileType => $fileLabel) {
        $names = array();
        $fileValue = isset($filesByType[$fileType]) ? $filesByType[$fileType] : array();
        $fileList = (is_array($fileValue) && isset($fileValue['original_name'])) ? array($fileValue) : $fileValue;
        if (is_array($fileList)) {
            for ($i = 0; $i < count($fileList); $i++) {
                if (is_array($fileList[$i]) && isset($fileList[$i]['original_name']) && trim((string)$fileList[$i]['original_name']) !== '') {
                    $names[] = trim((string)$fileList[$i]['original_name']);
                }
            }
        }
        $html .= '<div class="pdf-attachment-row"><strong>' . h($fileLabel) . '</strong>: ' . h(count($names) > 0 ? implode(', ', $names) : approval_ko('%EB%AF%B8%EC%B2%A8%EB%B6%80')) . '</div>';
    }
    $html .= '</div></div>';
    return $html;
}}

if (!function_exists('cpms_approval_pdf_render_document_body')) {
function cpms_approval_pdf_render_document_body($docType, $content, $lines, $filesByType) {
    $docType = strtolower(trim((string)$docType));
    $content = is_array($content) ? $content : array();
    $lines = is_array($lines) ? $lines : array();
    $filesByType = is_array($filesByType) ? $filesByType : array();
    if ($docType === 'leave') {
        return cpms_approval_pdf_render_safe_leave_body($content, $lines);
    }
    if (approval_is_proposal_doc_type($docType)) {
        return cpms_approval_pdf_render_safe_proposal_body($content, $lines, $filesByType);
    }

    ob_start();
    if ($docType === 'unused_leave_notice') {
        render_approval_unused_leave_notice_document($content, $lines, 'print', array());
    } else if ($docType === 'unused_leave_plan') {
        render_approval_unused_leave_plan_document($content, $lines, 'print', array());
    } else {
        render_approval_proposal_document($content, $lines, 'print', $filesByType, array());
    }
    return ob_get_clean();
}}

if (!function_exists('cpms_approval_pdf_build_html')) {
function cpms_approval_pdf_build_html($pdo, $approvalId) {
    $doc = cpms_approval_pdf_fetch_document($pdo, $approvalId);
    if (!$doc) {
        return array('ok' => false, 'html' => '', 'doc' => null, 'content' => array(), 'message' => 'Approval document was not found.');
    }
    $docType = isset($doc['doc_type']) ? (string)$doc['doc_type'] : '';
    $content = approval_parse_content(isset($doc['content']) ? $doc['content'] : '');
    $lines = cpms_approval_pdf_fetch_lines($pdo, $approvalId);
    $filesByType = cpms_approval_pdf_fetch_files_by_type($pdo, $approvalId, $docType);
    $body = cpms_approval_pdf_render_document_body($docType, $content, $lines, $filesByType);
    $body = cpms_approval_pdf_embed_local_images($body);

    $html = '<!doctype html>' . "\n";
    $html .= '<html lang="ko"><head><meta charset="utf-8">';
    $html .= '<base href="' . h(cpms_approval_pdf_public_base_uri()) . '">';
    $html .= '<title>' . h(approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C')) . '</title>';
    if ($docType === 'leave' || approval_is_proposal_doc_type($docType)) {
        $html .= cpms_approval_pdf_safe_style();
    } else {
        $html .= cpms_approval_pdf_render_template_style();
        $html .= '<style>html,body{margin:0;padding:0;background:#fff;color:#111;font-family:unbatang,"Malgun Gothic","Noto Sans CJK KR",sans-serif}.no-print{display:none!important}.approval-paper{page-break-inside:auto;box-sizing:border-box}</style>';
    }
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

if (!function_exists('cpms_approval_pdf_page_count')) {
function cpms_approval_pdf_page_count($path) {
    $path = trim((string)$path);
    if ($path === '' || !is_file($path)) return 0;
    $content = @file_get_contents($path);
    if ($content === false || $content === '') return 0;
    $matches = array();
    $count = preg_match_all('/\/Type\s*\/Page\b/', $content, $matches);
    return $count !== false ? (int)$count : 0;
}}

if (!function_exists('cpms_approval_pdf_run_wkhtmltopdf')) {
function cpms_approval_pdf_run_wkhtmltopdf($htmlPath, $pdfPath, $toolPath, $context = null) {
    if (!is_array($context)) $context = array();
    $pageFormat = isset($context['page_format']) ? strtoupper(trim((string)$context['page_format'])) : 'A4';
    $pageSize = (strpos($pageFormat, 'A3') === 0) ? 'A3' : 'A4';
    $orientation = (substr($pageFormat, -2) === '-L') ? 'Landscape' : 'Portrait';
    $pageWidth = isset($context['page_width_mm']) ? (float)$context['page_width_mm'] : 0.0;
    $pageHeight = isset($context['page_height_mm']) ? (float)$context['page_height_mm'] : 0.0;
    $customPage = ($pageWidth >= 50 && $pageHeight >= 50 && $pageWidth <= 5000 && $pageHeight <= 5000);
    $pageArguments = $customPage
        ? (' --page-width ' . escapeshellarg(number_format($pageWidth, 2, '.', '') . 'mm')
            . ' --page-height ' . escapeshellarg(number_format($pageHeight, 2, '.', '') . 'mm'))
        : (' --page-size ' . escapeshellarg($pageSize)
            . ' --orientation ' . escapeshellarg($orientation));
    $common = ' --encoding ' . escapeshellarg('utf-8')
        . ' --print-media-type'
        . ' --quiet'
        . $pageArguments
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

if (!function_exists('cpms_approval_pdf_run_mpdf')) {
function cpms_approval_pdf_run_mpdf($html, $pdfPath, $context) {
    if (!is_array($context)) $context = array();
    $mpdfInfo = cpms_approval_pdf_mpdf_is_available($context);
    if (empty($mpdfInfo['ok'])) {
        return array('ok' => false, 'message' => isset($mpdfInfo['message']) ? $mpdfInfo['message'] : 'mPDF is not available.', 'size' => 0);
    }
    $temp = cpms_approval_pdf_ensure_mpdf_temp_dir();
    if (empty($temp['ok'])) {
        cpms_approval_pdf_log_failure(array_merge($context, array(
            'section' => 'approval_completed_pdf_mpdf_temp',
            'message' => isset($temp['message']) ? $temp['message'] : 'mPDF temp directory failed.'
        )));
        return array('ok' => false, 'message' => isset($temp['message']) ? $temp['message'] : 'mPDF temp directory failed.', 'size' => 0);
    }

    try {
        if (is_file($pdfPath)) @unlink($pdfPath);
        $pageFormat = isset($context['page_format']) ? strtoupper(trim((string)$context['page_format'])) : 'A4';
        if (!in_array($pageFormat, array('A4', 'A4-L', 'A3', 'A3-L'), true)) $pageFormat = 'A4';
        $pageWidth = isset($context['page_width_mm']) ? (float)$context['page_width_mm'] : 0.0;
        $pageHeight = isset($context['page_height_mm']) ? (float)$context['page_height_mm'] : 0.0;
        $customPage = ($pageWidth >= 50 && $pageHeight >= 50 && $pageWidth <= 5000 && $pageHeight <= 5000);
        $mpdfPageFormat = $customPage ? array($pageWidth, $pageHeight) : $pageFormat;
        $pdfTitle = isset($context['pdf_title']) ? trim((string)$context['pdf_title']) : '';
        if ($pdfTitle === '') $pdfTitle = approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C');
        // The non-embedded Adobe UHC font can render Hangul as empty boxes in
        // browser PDF viewers. Korean mode with -aCJK forces mPDF's embedded
        // UnBatang font while avoiding the expensive automatic script scan.
        $mpdf = new mPDF('ko-aCJK', $mpdfPageFormat, 0, 'unbatang');
        $mpdf->tempDir = $temp['path'];
        if (property_exists($mpdf, 'autoScriptToLang')) $mpdf->autoScriptToLang = false;
        if (property_exists($mpdf, 'autoLangToFont')) $mpdf->autoLangToFont = false;
        if (property_exists($mpdf, 'autoArabic')) $mpdf->autoArabic = false;
        if (property_exists($mpdf, 'autoVietnamese')) $mpdf->autoVietnamese = false;
        if (property_exists($mpdf, 'useSubstitutions')) $mpdf->useSubstitutions = false;
        if (property_exists($mpdf, 'percentSubset')) $mpdf->percentSubset = 100;
        // The approval matrix avoids rowspan, and signature bitmaps are
        // normalized before this point, so use the standard table engine.
        if (property_exists($mpdf, 'simpleTables')) $mpdf->simpleTables = false;
        if (!empty($context['single_page'])) {
            $mpdf->shrink_tables_to_fit = 2;
            $mpdf->keep_table_proportions = true;
            $mpdf->packTableData = true;
            if (method_exists($mpdf, 'SetAutoPageBreak')) {
                $mpdf->SetAutoPageBreak(false, 0);
            }
        }
        if (method_exists($mpdf, 'SetDisplayMode')) {
            $mpdf->SetDisplayMode('fullpage');
        }
        if (method_exists($mpdf, 'SetTitle')) {
            $mpdf->SetTitle($pdfTitle);
        }
        $mpdf->WriteHTML((string)$html);
        $mpdf->Output($pdfPath, 'F');
    } catch (Exception $e) {
        cpms_approval_pdf_log_failure(array_merge($context, array(
            'section' => 'approval_completed_pdf_pdf_create',
            'message' => 'mPDF PDF creation failed: ' . $e->getMessage()
        )));
        return array('ok' => false, 'message' => 'mPDF PDF creation failed: ' . $e->getMessage(), 'size' => 0);
    }

    $valid = cpms_approval_pdf_validate_file($pdfPath);
    if (empty($valid['ok'])) {
        cpms_approval_pdf_log_failure(array_merge($context, array(
            'section' => 'approval_completed_pdf_pdf_validate',
            'message' => isset($valid['message']) ? $valid['message'] : 'Generated PDF validation failed.'
        )));
        return array('ok' => false, 'message' => isset($valid['message']) ? $valid['message'] : 'Generated PDF validation failed.', 'size' => isset($valid['size']) ? (int)$valid['size'] : 0);
    }
    if (!empty($context['single_page'])) {
        $pageCount = cpms_approval_pdf_page_count($pdfPath);
        if ($pageCount > 1) {
            cpms_approval_pdf_cleanup_temp_file($pdfPath);
            return array('ok' => false, 'message' => 'PDF가 ' . $pageCount . '장으로 분할되어 업로드를 중단했습니다.', 'size' => 0);
        }
    }
    return array('ok' => true, 'message' => 'PDF file created by mPDF.', 'size' => (int)$valid['size'], 'engine' => 'mpdf');
}}

if (!function_exists('cpms_approval_pdf_create_from_html')) {
function cpms_approval_pdf_create_from_html($html, $pdfName, $context) {
    if (!is_array($context)) $context = array();
    $dir = cpms_approval_pdf_ensure_temp_dir();
    if (empty($dir['ok'])) {
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => $dir['message']);
    }
    $pdfPath = cpms_approval_pdf_temp_path($pdfName, 'pdf');
    if ($pdfPath === '') {
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => 'Approval PDF temp paths could not be prepared.');
    }

    $mpdf = cpms_approval_pdf_mpdf_is_available($context);
    if (!empty($mpdf['ok'])) {
        $run = cpms_approval_pdf_run_mpdf($html, $pdfPath, $context);
        if (!empty($run['ok'])) {
            return array('ok' => true, 'path' => $pdfPath, 'name' => $pdfName, 'mime_type' => 'application/pdf', 'size' => (int)$run['size'], 'message' => 'PDF file created by mPDF.', 'tool' => isset($mpdf['path']) ? $mpdf['path'] : '', 'engine' => 'mpdf');
        }
        cpms_approval_pdf_cleanup_temp_file($pdfPath);
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => isset($run['size']) ? (int)$run['size'] : 0, 'message' => isset($run['message']) ? $run['message'] : 'mPDF PDF generation failed.');
    }

    $tool = cpms_approval_pdf_find_wkhtmltopdf();
    if (empty($tool['ok'])) {
        cpms_approval_pdf_cleanup_temp_file($pdfPath);
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => 'mPDF unavailable: ' . (isset($mpdf['message']) ? $mpdf['message'] : '') . ' / wkhtmltopdf unavailable: ' . (isset($tool['message']) ? $tool['message'] : ''));
    }
    $htmlPath = cpms_approval_pdf_temp_path($pdfName, 'html');
    if ($htmlPath === '') {
        cpms_approval_pdf_cleanup_temp_file($pdfPath);
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => 'Approval PDF source HTML temp path could not be prepared.');
    }
    if (@file_put_contents($htmlPath, (string)$html, LOCK_EX) === false) {
        cpms_approval_pdf_cleanup_temp_file($pdfPath);
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => 'Approval PDF source HTML could not be written.');
    }

    $run = cpms_approval_pdf_run_wkhtmltopdf($htmlPath, $pdfPath, $tool['path'], $context);
    cpms_approval_pdf_cleanup_temp_file($htmlPath);
    if (empty($run['ok'])) {
        cpms_approval_pdf_cleanup_temp_file($pdfPath);
        return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => isset($run['message']) ? $run['message'] : 'PDF generation failed.');
    }
    if (!empty($context['single_page'])) {
        $pageCount = cpms_approval_pdf_page_count($pdfPath);
        if ($pageCount > 1) {
            cpms_approval_pdf_cleanup_temp_file($pdfPath);
            return array('ok' => false, 'path' => '', 'name' => $pdfName, 'size' => 0, 'message' => 'PDF가 ' . $pageCount . '장으로 분할되어 업로드를 중단했습니다.');
        }
    }
    return array('ok' => true, 'path' => $pdfPath, 'name' => $pdfName, 'mime_type' => 'application/pdf', 'size' => (int)$run['size'], 'message' => 'PDF file created by wkhtmltopdf.', 'tool' => $tool['path'], 'engine' => 'wkhtmltopdf');
}}

if (!function_exists('cpms_approval_pdf_completed_date')) {
function cpms_approval_pdf_completed_date($docRow, $content, $context = null) {
    $candidates = array();
    if (is_array($content)) {
        foreach (array('completed_at', 'completed_date', 'final_approved_at', 'last_approved_at', '_final_approved_at', 'approved_at', 'updated_at') as $key) {
            if (isset($content[$key])) $candidates[] = $content[$key];
        }
    }
    if (is_array($docRow)) {
        foreach (array('completed_at', 'approved_at', 'updated_at', 'created_at') as $key) {
            if (isset($docRow[$key])) $candidates[] = $docRow[$key];
        }
    }
    $invalidRaw = '';
    for ($i = 0; $i < count($candidates); $i++) {
        $raw = trim((string)$candidates[$i]);
        if ($raw === '') continue;
        $parsed = function_exists('cpms_approval_drive_parse_date_value') ? cpms_approval_drive_parse_date_value($raw) : '';
        if ($parsed !== '') return $parsed;
        $ts = strtotime($raw);
        if ($ts !== false) return date('Y-m-d', $ts);
        if ($invalidRaw === '') $invalidRaw = $raw;
    }
    if ($invalidRaw !== '' && is_array($context)) {
        cpms_approval_pdf_log_failure(array_merge($context, array(
            'message' => urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%9B%94%20%EA%B0%92%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94') . ': ' . $invalidRaw
        )));
    }
    return date('Y-m-d');
}}

if (!function_exists('cpms_approval_pdf_final_approved_at')) {
function cpms_approval_pdf_final_approved_at($pdo, $approvalId) {
    if (!$pdo || (int)$approvalId <= 0) return '';
    try {
        $st = $pdo->prepare("SELECT acted_at FROM cpms_approval_lines WHERE document_id=:id AND UPPER(COALESCE(line_status,'')) IN ('APPROVED','CEO_APPROVED') AND acted_at IS NOT NULL AND acted_at<>'' ORDER BY line_order DESC, acted_at DESC LIMIT 1");
        $st->execute(array(':id' => (int)$approvalId));
        $value = $st->fetchColumn();
        return trim((string)$value);
    } catch (Exception $e) {
        return '';
    }
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
    $docType = isset($docRow['doc_type']) ? trim((string)$docRow['doc_type']) : '';
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
        'document_code' => $docType,
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
        'completed_pdf_year' => isset($record['completed_pdf_year']) ? (string)$record['completed_pdf_year'] : (isset($record['document_year']) ? (string)$record['document_year'] : ''),
        'completed_pdf_month' => isset($record['completed_pdf_month']) ? (string)$record['completed_pdf_month'] : (isset($record['document_month']) ? (string)$record['document_month'] : ''),
        'completed_pdf_year_folder_id' => isset($record['completed_pdf_year_folder_id']) ? (string)$record['completed_pdf_year_folder_id'] : (isset($record['drive_year_folder_id']) ? (string)$record['drive_year_folder_id'] : ''),
        'completed_pdf_type_folder_id' => isset($record['completed_pdf_type_folder_id']) ? (string)$record['completed_pdf_type_folder_id'] : (isset($record['drive_type_folder_id']) ? (string)$record['drive_type_folder_id'] : ''),
        'completed_pdf_month_folder_id' => isset($record['completed_pdf_month_folder_id']) ? (string)$record['completed_pdf_month_folder_id'] : (isset($record['drive_month_folder_id']) ? (string)$record['drive_month_folder_id'] : ''),
        'completed_pdf_name' => isset($record['stored_name']) ? (string)$record['stored_name'] : '',
        'completed_pdf_mime_type' => isset($record['mime_type']) ? (string)$record['mime_type'] : 'application/pdf',
        'completed_pdf_size' => (isset($record['size']) && $record['size'] !== '') ? (int)$record['size'] : 0,
        'completed_pdf_uploaded_at' => isset($record['uploaded_at']) ? (string)$record['uploaded_at'] : date('Y-m-d H:i:s'),
        'completed_pdf_upload_status' => 'uploaded',
        'completed_pdf_upload_error' => '',
        'completed_pdf_render_version' => cpms_approval_pdf_render_version()
    );
    return cpms_approval_pdf_update_document($pdo, $approvalId, $fields);
}}

if (!function_exists('cpms_approval_pdf_upload_completed_pdf')) {
function cpms_approval_pdf_upload_completed_pdf($pdo, $approvalId, $userContext, $options = null) {
    $options = is_array($options) ? $options : array();
    $forceRegenerate = !empty($options['force_regenerate']);
    $result = array('ok' => false, 'skipped' => false, 'replaced' => false, 'old_cleanup_failed' => false, 'message' => '', 'record' => array());
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
    $previousFileId = isset($doc['completed_pdf_drive_file_id']) ? trim((string)$doc['completed_pdf_drive_file_id']) : '';
    if ($previousFileId !== '' && !$forceRegenerate) {
        $result['ok'] = true;
        $result['skipped'] = true;
        $result['message'] = 'Completed PDF already uploaded.';
        return $result;
    }

    $context = cpms_approval_pdf_context($doc, $content, $userContext, 'generate');
    $pdf = cpms_approval_pdf_create_file($pdo, $approvalId);
    if (empty($pdf['ok'])) {
        $message = isset($pdf['message']) ? $pdf['message'] : 'Completed PDF generation failed.';
        if ($forceRegenerate && $previousFileId !== '') {
            cpms_approval_pdf_update_document($pdo, $approvalId, array('completed_pdf_upload_error' => cpms_drive_redact_text($message)));
        } else {
            cpms_approval_pdf_mark_failed($pdo, $approvalId, $message);
        }
        cpms_approval_pdf_log_failure(array_merge($context, array('message' => 'PDF generation stage failed: ' . $message)));
        $result['message'] = $message;
        return $result;
    }
    // A generated PDF is temporary only. This shutdown guard also removes it
    // if an unexpected exception interrupts the Drive stage.
    register_shutdown_function('cpms_approval_pdf_cleanup_temp_file', $pdf['path']);
    $doc = isset($pdf['doc']) && is_array($pdf['doc']) ? $pdf['doc'] : $doc;
    $content = isset($pdf['content']) && is_array($pdf['content']) ? $pdf['content'] : $content;
    $finalApprovedAt = cpms_approval_pdf_final_approved_at($pdo, $approvalId);
    if ($finalApprovedAt !== '') $content['_final_approved_at'] = $finalApprovedAt;
    $folderContext = cpms_approval_pdf_context($doc, $content, $userContext, 'drive');
    $completedDate = cpms_approval_pdf_completed_date($doc, $content, $folderContext);
    $year = (int)substr($completedDate, 0, 4);
    if ($year <= 0) $year = (int)date('Y');
    $folderContext['completed_date'] = $completedDate;
    $folderContext['fallback_date'] = date('Y-m-d H:i:s');
    $folder = cpms_drive_ensure_approval_folder($year, 'completed', $folderContext);
    if (empty($folder['ok'])) {
        $message = isset($folder['message']) ? $folder['message'] : 'Completed PDF Drive folder preparation failed.';
        if ($forceRegenerate && $previousFileId !== '') {
            cpms_approval_pdf_update_document($pdo, $approvalId, array('completed_pdf_upload_error' => cpms_drive_redact_text($message)));
        } else {
            cpms_approval_pdf_mark_failed($pdo, $approvalId, $message);
        }
        cpms_approval_pdf_cleanup_temp_file($pdf['path']);
        $result['message'] = $message;
        return $result;
    }

    $folderContext['target_folder_id'] = (string)$folder['folder_id'];
    $folderContext['drive_folder_id'] = (string)$folder['folder_id'];
    $folderContext['document_year'] = isset($folder['year']) ? sprintf('%04d', (int)$folder['year']) : substr($completedDate, 0, 4);
    $folderContext['document_month'] = isset($folder['month']) ? (string)$folder['month'] : substr($completedDate, 5, 2);
    $folderContext['drive_year_folder_id'] = isset($folder['year_folder_id']) ? (string)$folder['year_folder_id'] : '';
    $folderContext['drive_type_folder_id'] = isset($folder['type_folder_id']) ? (string)$folder['type_folder_id'] : '';
    $folderContext['drive_month_folder_id'] = isset($folder['month_folder_id']) ? (string)$folder['month_folder_id'] : '';
    $folderContext['completed_pdf_year'] = $folderContext['document_year'];
    $folderContext['completed_pdf_month'] = $folderContext['document_month'];
    $folderContext['completed_pdf_year_folder_id'] = $folderContext['drive_year_folder_id'];
    $folderContext['completed_pdf_type_folder_id'] = $folderContext['drive_type_folder_id'];
    $folderContext['completed_pdf_month_folder_id'] = $folderContext['drive_month_folder_id'];
    $folderContext['original_name'] = isset($pdf['name']) ? (string)$pdf['name'] : '';
    $folderContext['stored_name'] = isset($pdf['name']) ? (string)$pdf['name'] : '';
    $folderContext['mime_type'] = 'application/pdf';
    $folderContext['size'] = isset($pdf['size']) ? (int)$pdf['size'] : 0;
    $folderContext['local_temp_path'] = isset($pdf['path']) ? (string)$pdf['path'] : '';
    $upload = cpms_drive_upload_file($pdf['path'], $pdf['name'], (string)$folder['folder_id'], 'application/pdf', $folderContext);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message = isset($upload['message']) ? $upload['message'] : 'Completed PDF Drive upload failed.';
        if ($forceRegenerate && $previousFileId !== '') {
            cpms_approval_pdf_update_document($pdo, $approvalId, array('completed_pdf_upload_error' => cpms_drive_redact_text($message)));
        } else {
            cpms_approval_pdf_mark_failed($pdo, $approvalId, $message);
        }
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

    // Keep a protected local copy keyed by the immutable Drive file ID. This
    // removes the Drive round trip from normal preview/download requests while
    // the route still performs its regular document permission check.
    $cachedPath = cpms_approval_pdf_cache_store_file($record['drive_file_id'], $pdf['path']);
    if ($cachedPath === '') {
        cpms_approval_pdf_log_failure(array_merge($folderContext, array(
            'section' => 'approval_completed_pdf_cache',
            'message' => 'Completed PDF uploaded, but the local download cache could not be stored.'
        )));
    }

    if ($forceRegenerate && $previousFileId !== '' && $previousFileId !== (string)$record['drive_file_id']) {
        cpms_approval_pdf_cache_delete($previousFileId);
        $deleteOld = cpms_drive_delete_file($previousFileId, array_merge($folderContext, array(
            'section' => 'approval_completed_pdf_replace_cleanup',
            'message' => 'Deleting the previous completed PDF after replacement.'
        )));
        if (empty($deleteOld['ok'])) {
            $result['old_cleanup_failed'] = true;
            cpms_approval_pdf_log_failure(array_merge($folderContext, array(
                'section' => 'approval_completed_pdf_replace_cleanup',
                'message' => 'New completed PDF was saved, but the previous Drive PDF could not be deleted.'
            )));
        }
        $result['replaced'] = true;
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
    $renderVersion = isset($docRow['completed_pdf_render_version']) ? (int)$docRow['completed_pdf_render_version'] : 0;
    $needsCurrentRender = ($fileId !== '' && $renderVersion < cpms_approval_pdf_render_version());
    $uploadStatus = isset($docRow['completed_pdf_upload_status']) ? strtolower(trim((string)$docRow['completed_pdf_upload_status'])) : '';
    $title = cpms_approval_pdf_h(urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20PDF'));
    $html = '<div class="no-print bg-white rounded-2xl border p-4">';
    $html .= '<div class="flex flex-wrap gap-2 items-center justify-between">';
    $html .= '<div class="font-extrabold text-gray-900">' . $title . '</div><div class="flex flex-wrap gap-2 items-center">';
    if ($fileId !== '' && !$needsCurrentRender) {
        $html .= '<a href="' . cpms_approval_pdf_h('?r=approval_completed_pdf&id=' . $id) . '" target="_blank" class="px-3 py-2 bg-indigo-100 rounded">' . cpms_approval_pdf_h(urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C%20PDF%20%EB%B3%B4%EA%B8%B0')) . '</a>';
        $html .= '<a href="' . cpms_approval_pdf_h('?r=approval_completed_pdf&id=' . $id . '&download=1') . '" class="px-3 py-2 bg-gray-100 rounded">' . cpms_approval_pdf_h(urldecode('%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C')) . '</a>';
    } else if ($needsCurrentRender || in_array($uploadStatus, array('pending', 'processing'), true)) {
        $html .= '<span class="text-sm text-indigo-700 font-bold">' . cpms_approval_pdf_h(urldecode('%50%44%46%20%EC%9E%AC%EC%83%9D%EC%84%B1%20%EC%A4%91')) . '</span>';
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
        'mpdf_file' => array('ok' => false, 'message' => '', 'path' => ''),
        'mpdf_load' => array('ok' => false, 'message' => ''),
        'mbstring' => array('ok' => false, 'message' => ''),
        'gd' => array('ok' => false, 'message' => ''),
        'mpdf_temp' => array('ok' => false, 'message' => '', 'path' => ''),
        'wkhtmltopdf' => array('ok' => false, 'message' => '', 'path' => ''),
        'tool' => array('ok' => false, 'message' => '', 'path' => ''),
        'temp_path' => array('ok' => false, 'message' => '', 'path' => ''),
        'create' => array('ok' => false, 'message' => ''),
        'validate_size' => array('ok' => false, 'message' => ''),
        'validate_header' => array('ok' => false, 'message' => ''),
        'approval_year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_type_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_month_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'approval_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'cleanup' => array('ok' => false, 'message' => ''),
        'test_file' => array()
    );

    $mpdfPath = cpms_approval_pdf_mpdf_path();
    $result['mpdf_file'] = array(
        'ok' => is_file($mpdfPath),
        'message' => is_file($mpdfPath) ? 'mPDF core file exists.' : 'mPDF core file is missing.',
        'path' => $mpdfPath
    );
    $mpdf = cpms_approval_pdf_mpdf_is_available(array('user' => $userContext, 'section' => 'admin_drive_check_completed_pdf'));
    $result['mpdf_load'] = array(
        'ok' => !empty($mpdf['ok']),
        'message' => isset($mpdf['message']) ? $mpdf['message'] : ''
    );
    $result['mbstring'] = array(
        'ok' => extension_loaded('mbstring'),
        'message' => extension_loaded('mbstring') ? 'PHP mbstring extension is available.' : 'PHP mbstring extension is not available.'
    );
    $result['gd'] = array(
        'ok' => extension_loaded('gd'),
        'message' => extension_loaded('gd') ? 'PHP GD extension is available.' : 'PHP GD extension is not available.'
    );
    $mpdfTemp = cpms_approval_pdf_ensure_mpdf_temp_dir();
    $result['mpdf_temp'] = array(
        'ok' => !empty($mpdfTemp['ok']),
        'message' => isset($mpdfTemp['message']) ? $mpdfTemp['message'] : '',
        'path' => isset($mpdfTemp['path']) ? $mpdfTemp['path'] : ''
    );
    $wk = cpms_approval_pdf_find_wkhtmltopdf();
    $result['wkhtmltopdf'] = array(
        'ok' => !empty($wk['ok']),
        'message' => isset($wk['message']) ? $wk['message'] : '',
        'path' => isset($wk['path']) ? $wk['path'] : ''
    );
    $tool = !empty($mpdf['ok']) ? $mpdf : $wk;
    $toolMessage = !empty($mpdf['ok']) ? 'mPDF will be used first.' : ('mPDF unavailable. ' . (isset($wk['message']) ? $wk['message'] : ''));
    $result['tool'] = array('ok' => !empty($tool['ok']), 'message' => $toolMessage, 'path' => isset($tool['path']) ? $tool['path'] : '');
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
        'target_folder_id' => cpms_drive_folder_id('approval'),
        'completed_date' => date('Y-m-d'),
        'fallback_date' => date('Y-m-d H:i:s')
    );
    $pdf = cpms_approval_pdf_create_from_html($html, $testName, $context);
    $result['create'] = array(
        'ok' => !empty($pdf['ok']),
        'message' => (isset($pdf['message']) ? $pdf['message'] : '') . (isset($pdf['engine']) && trim((string)$pdf['engine']) !== '' ? ' / engine=' . $pdf['engine'] : ''),
        'path' => isset($pdf['path']) ? $pdf['path'] : '',
        'size' => isset($pdf['size']) ? (int)$pdf['size'] : 0
    );
    if (empty($pdf['ok'])) return $result;

    $size = (isset($pdf['path']) && is_file($pdf['path'])) ? (int)@filesize($pdf['path']) : 0;
    $result['validate_size'] = array(
        'ok' => $size > 0,
        'message' => $size > 0 ? ('Generated PDF size is ' . $size . ' bytes.') : 'Generated PDF file is empty.'
    );
    $head = '';
    $fh = @fopen($pdf['path'], 'rb');
    if ($fh) {
        $head = @fread($fh, 4);
        @fclose($fh);
    }
    $result['validate_header'] = array(
        'ok' => ($head === '%PDF'),
        'message' => ($head === '%PDF') ? 'Generated PDF header is valid.' : 'Generated PDF header is not %PDF.'
    );
    if (empty($result['validate_size']['ok']) || empty($result['validate_header']['ok'])) {
        cpms_approval_pdf_cleanup_temp_file($pdf['path']);
        return $result;
    }

    $folder = cpms_drive_ensure_approval_folder((int)date('Y'), 'completed', $context);
    $result['approval_folder'] = array(
        'ok' => !empty($folder['ok']),
        'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0,
        'message' => isset($folder['message']) ? $folder['message'] : ''
    );
    $result['approval_year_folder'] = array(
        'ok' => (!empty($folder['ok']) && isset($folder['year_folder_id']) && trim((string)$folder['year_folder_id']) !== ''),
        'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0,
        'message' => !empty($folder['ok']) ? ('Completed PDF year folder is ready: ' . (isset($folder['year']) ? (string)$folder['year'] : date('Y')) . '.') : (isset($folder['message']) ? $folder['message'] : '')
    );
    $result['approval_type_folder'] = array(
        'ok' => (!empty($folder['ok']) && isset($folder['type_folder_id']) && trim((string)$folder['type_folder_id']) !== ''),
        'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0,
        'message' => !empty($folder['ok']) ? ('Completed PDF type folder is ready: ' . cpms_drive_approval_folder_name('completed') . '.') : (isset($folder['message']) ? $folder['message'] : '')
    );
    $result['approval_month_folder'] = array(
        'ok' => (!empty($folder['ok']) && isset($folder['month_folder_id']) && trim((string)$folder['month_folder_id']) !== ''),
        'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0,
        'message' => !empty($folder['ok']) ? ('Completed PDF month folder is ready: ' . (isset($folder['month']) ? (string)$folder['month'] : date('m')) . '.') : (isset($folder['message']) ? $folder['message'] : '')
    );
    if (!empty($folder['ok']) && isset($folder['folder_id']) && trim((string)$folder['folder_id']) !== '') {
        $context['target_folder_id'] = (string)$folder['folder_id'];
        $context['drive_folder_id'] = (string)$folder['folder_id'];
        $context['document_year'] = isset($folder['year']) ? sprintf('%04d', (int)$folder['year']) : date('Y');
        $context['document_month'] = isset($folder['month']) ? (string)$folder['month'] : date('m');
        $context['drive_year_folder_id'] = isset($folder['year_folder_id']) ? (string)$folder['year_folder_id'] : '';
        $context['drive_type_folder_id'] = isset($folder['type_folder_id']) ? (string)$folder['type_folder_id'] : '';
        $context['drive_month_folder_id'] = isset($folder['month_folder_id']) ? (string)$folder['month_folder_id'] : '';
        $context['completed_pdf_year'] = $context['document_year'];
        $context['completed_pdf_month'] = $context['document_month'];
        $context['completed_pdf_year_folder_id'] = $context['drive_year_folder_id'];
        $context['completed_pdf_type_folder_id'] = $context['drive_type_folder_id'];
        $context['completed_pdf_month_folder_id'] = $context['drive_month_folder_id'];
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
            if ($result['test_file']['id'] !== '') {
                $delete = cpms_drive_delete_file($result['test_file']['id'], $context);
                $result['delete'] = array(
                    'ok' => !empty($delete['ok']),
                    'http_code' => isset($delete['http_code']) ? (int)$delete['http_code'] : 0,
                    'message' => (isset($delete['message']) ? $delete['message'] : '') . ' / file_id=' . $result['test_file']['id']
                );
            } else {
                $result['delete'] = array('ok' => false, 'http_code' => 0, 'message' => 'Upload response did not include a Drive file ID.');
            }
        }
    }

    $cleanup = cpms_approval_pdf_cleanup_temp_file($pdf['path']);
    $result['cleanup'] = array(
        'ok' => $cleanup ? true : false,
        'message' => $cleanup ? 'Temporary PDF file deleted.' : 'Temporary PDF file could not be deleted.'
    );
    $result['ok'] = (!empty($result['mpdf_file']['ok']) && !empty($result['mpdf_load']['ok']) && !empty($result['mpdf_temp']['ok']) && !empty($result['temp_path']['ok']) && !empty($result['create']['ok']) && !empty($result['validate_size']['ok']) && !empty($result['validate_header']['ok']) && !empty($result['approval_year_folder']['ok']) && !empty($result['approval_type_folder']['ok']) && !empty($result['approval_month_folder']['ok']) && !empty($result['upload']['ok']) && !empty($result['delete']['ok']) && !empty($result['cleanup']['ok']));
    return $result;
}}
