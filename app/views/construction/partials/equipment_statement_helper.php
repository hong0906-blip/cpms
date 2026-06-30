<?php
/**
 * 장비 거래명세표 파일 helper
 * - cpms_equipment_usage 행에 거래명세표 파일 정보를 연결한다.
 * - PHP 5.6 호환
 */

if (!function_exists('cpms_equipment_statement_allowed_extensions')) {
function cpms_equipment_statement_allowed_extensions() {
    return array('pdf'=>true, 'jpg'=>true, 'jpeg'=>true, 'png'=>true, 'heic'=>true, 'heif'=>true);
}}

if (!function_exists('cpms_equipment_statement_allowed_mimes')) {
function cpms_equipment_statement_allowed_mimes() {
    return array(
        'application/pdf'=>true,
        'image/jpeg'=>true,
        'image/pjpeg'=>true,
        'image/png'=>true,
        'image/x-png'=>true,
        'image/heic'=>true,
        'image/heif'=>true,
        'image/heic-sequence'=>true,
        'image/heif-sequence'=>true,
        'application/octet-stream'=>true
    );
}}

if (!function_exists('cpms_equipment_statement_max_size')) {
function cpms_equipment_statement_max_size() {
    return 10 * 1024 * 1024;
}}

if (!function_exists('cpms_equipment_statement_column_exists')) {
function cpms_equipment_statement_column_exists($pdo, $column) {
    if (!$pdo || trim((string)$column) === '') return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM cpms_equipment_usage LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_equipment_statement_ensure_usage_columns')) {
function cpms_equipment_statement_ensure_usage_columns($pdo) {
    if (!$pdo) return false;
    $columns = array(
        'statement_original_name' => "ALTER TABLE cpms_equipment_usage ADD COLUMN statement_original_name VARCHAR(255) DEFAULT ''",
        'statement_stored_name' => "ALTER TABLE cpms_equipment_usage ADD COLUMN statement_stored_name VARCHAR(255) DEFAULT ''",
        'statement_stored_path' => "ALTER TABLE cpms_equipment_usage ADD COLUMN statement_stored_path VARCHAR(500) DEFAULT ''",
        'statement_mime_type' => "ALTER TABLE cpms_equipment_usage ADD COLUMN statement_mime_type VARCHAR(100) DEFAULT ''",
        'statement_file_size' => "ALTER TABLE cpms_equipment_usage ADD COLUMN statement_file_size INT NULL",
        'statement_uploaded_by' => "ALTER TABLE cpms_equipment_usage ADD COLUMN statement_uploaded_by INT NULL",
        'statement_uploaded_by_name' => "ALTER TABLE cpms_equipment_usage ADD COLUMN statement_uploaded_by_name VARCHAR(100) DEFAULT ''",
        'statement_uploaded_at' => "ALTER TABLE cpms_equipment_usage ADD COLUMN statement_uploaded_at DATETIME NULL"
    );
    foreach ($columns as $column => $sql) {
        if (!cpms_equipment_statement_column_exists($pdo, $column)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }
    return cpms_equipment_statement_column_exists($pdo, 'statement_stored_path');
}}

if (!function_exists('cpms_equipment_statement_upload_error_message')) {
function cpms_equipment_statement_upload_error_message($code) {
    $code = (int)$code;
    if ($code === 1 || $code === 2) return '파일 용량이 너무 큽니다.';
    if ($code === 3) return '파일이 일부만 업로드되었습니다.';
    if ($code === 6 || $code === 7 || $code === 8) return '서버에서 파일을 저장할 수 없습니다.';
    return '파일 업로드 오류가 발생했습니다.';
}}

if (!function_exists('cpms_equipment_statement_has_upload')) {
function cpms_equipment_statement_has_upload($fieldName) {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) return false;
    $noFile = defined('UPLOAD_ERR_NO_FILE') ? UPLOAD_ERR_NO_FILE : 4;
    $error = isset($_FILES[$fieldName]['error']) ? (int)$_FILES[$fieldName]['error'] : $noFile;
    return $error !== $noFile;
}}

if (!function_exists('cpms_equipment_statement_detect_mime')) {
function cpms_equipment_statement_detect_mime($tmpPath) {
    $tmpPath = (string)$tmpPath;
    if ($tmpPath === '' || !is_file($tmpPath)) return '';
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)@finfo_file($finfo, $tmpPath);
            @finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($tmpPath);
    }
    $mime = strtolower(trim($mime));
    if (strpos($mime, ';') !== false) {
        $parts = explode(';', $mime, 2);
        $mime = trim($parts[0]);
    }
    return $mime;
}}

if (!function_exists('cpms_equipment_statement_validate_upload')) {
function cpms_equipment_statement_validate_upload($file, &$message, &$ext, &$mime) {
    $message = '';
    $ext = '';
    $mime = '';
    if (!is_array($file)) {
        $message = '파일 정보가 올바르지 않습니다.';
        return false;
    }
    $okCode = defined('UPLOAD_ERR_OK') ? UPLOAD_ERR_OK : 0;
    $error = isset($file['error']) ? (int)$file['error'] : $okCode;
    if ($error !== $okCode) {
        $message = cpms_equipment_statement_upload_error_message($error);
        return false;
    }
    $originalName = isset($file['name']) ? (string)$file['name'] : '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = cpms_equipment_statement_allowed_extensions();
    if ($ext === '' || !isset($allowedExt[$ext])) {
        $message = '허용되지 않은 파일 형식입니다. pdf, jpg, jpeg, png, heic, heif만 업로드할 수 있습니다.';
        return false;
    }
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0) {
        $message = '빈 파일은 업로드할 수 없습니다.';
        return false;
    }
    if ($size > cpms_equipment_statement_max_size()) {
        $message = '거래명세표 파일은 10MB 이하만 업로드할 수 있습니다.';
        return false;
    }
    $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmpName === '' || !is_file($tmpName) || !is_uploaded_file($tmpName)) {
        $message = '정상적인 업로드 파일이 아닙니다.';
        return false;
    }
    $mime = cpms_equipment_statement_detect_mime($tmpName);
    if ($mime !== '') {
        $allowedMimes = cpms_equipment_statement_allowed_mimes();
        if (!isset($allowedMimes[$mime])) {
            $message = '허용되지 않은 파일 형식입니다.';
            return false;
        }
    }
    return true;
}}

if (!function_exists('cpms_equipment_statement_storage_dir')) {
function cpms_equipment_statement_storage_dir($projectId, $ym) {
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)$ym) ? (string)$ym : date('Y-m');
    return cpms_storage_root() . '/construction/equipment_statement/' . ((int)$projectId) . '/' . $ym;
}}

if (!function_exists('cpms_equipment_statement_safe_name')) {
function cpms_equipment_statement_safe_name($projectId, $usageId, $ext) {
    return 'equipment_statement_' . ((int)$projectId) . '_' . ((int)$usageId) . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . strtolower((string)$ext);
}}

if (!function_exists('cpms_equipment_statement_current_user_id')) {
function cpms_equipment_statement_current_user_id() {
    if (!class_exists('App\\Core\\Auth')) return 0;
    $user = \App\Core\Auth::user();
    return (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0;
}}

if (!function_exists('cpms_equipment_statement_user_can_download')) {
function cpms_equipment_statement_user_can_download($pdo, $projectId) {
    if (!class_exists('App\\Core\\Auth')) return false;
    if (!\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;
    if (method_exists('App\\Core\\Auth', 'canAccessConstruction') && \App\Core\Auth::canAccessConstruction()) return true;

    $role = \App\Core\Auth::userRole();
    $email = (string)\App\Core\Auth::userEmail();
    if (function_exists('cpms_is_project_member_or_executive')) {
        if (cpms_is_project_member_or_executive($pdo, (int)$projectId, $role, $email)) return true;
    }
    return false;
}}

if (!function_exists('cpms_equipment_statement_store_uploaded_file_for_usage_rows')) {
function cpms_equipment_statement_store_uploaded_file_for_usage_rows($pdo, $fieldName, $projectId, $equipmentId, $usageRows, $ym) {
    $result = array('has_file'=>false, 'ok'=>true, 'message'=>'', 'updated'=>0);
    if (!cpms_equipment_statement_has_upload($fieldName)) return $result;
    $result['has_file'] = true;
    if (!$pdo) {
        $result['ok'] = false;
        $result['message'] = 'DB 연결 실패';
        return $result;
    }
    if (!is_array($usageRows) || count($usageRows) <= 0) {
        $result['ok'] = false;
        $result['message'] = '사용일자를 선택해야 거래명세표를 첨부할 수 있습니다.';
        return $result;
    }
    if (!cpms_equipment_statement_ensure_usage_columns($pdo)) {
        $result['ok'] = false;
        $result['message'] = '장비 사용내역 파일 컬럼을 준비하지 못했습니다.';
        return $result;
    }
    $file = $_FILES[$fieldName];
    $message = '';
    $ext = '';
    $mime = '';
    if (!cpms_equipment_statement_validate_upload($file, $message, $ext, $mime)) {
        $result['ok'] = false;
        $result['message'] = $message;
        return $result;
    }

    $firstUsageId = 0;
    foreach ($usageRows as $usageRow) {
        if (is_array($usageRow) && isset($usageRow['id']) && (int)$usageRow['id'] > 0) {
            $firstUsageId = (int)$usageRow['id'];
            break;
        }
    }
    if ($firstUsageId <= 0) {
        $result['ok'] = false;
        $result['message'] = '사용내역 ID를 확인할 수 없습니다.';
        return $result;
    }
    $dir = cpms_equipment_statement_storage_dir($projectId, $ym);
    if (!cpms_ensure_dir($dir)) {
        $result['ok'] = false;
        $result['message'] = '거래명세표 저장 폴더를 만들 수 없습니다.';
        return $result;
    }
    $storedName = cpms_equipment_statement_safe_name($projectId, $firstUsageId, $ext);
    $storedPath = rtrim($dir, '/\\') . '/' . $storedName;
    $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if (!@move_uploaded_file($tmpName, $storedPath)) {
        $result['ok'] = false;
        $result['message'] = '거래명세표 파일 저장에 실패했습니다.';
        return $result;
    }
    @chmod($storedPath, 0644);

    $originalName = isset($file['name']) ? basename((string)$file['name']) : '';
    if ($originalName === '') $originalName = 'equipment_statement.' . $ext;
    $fileSize = is_file($storedPath) ? (int)filesize($storedPath) : (int)$file['size'];
    $uploadedBy = cpms_equipment_statement_current_user_id();
    $uploadedByName = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userName() : '';
    $now = date('Y-m-d H:i:s');

    try {
        $st = $pdo->prepare("UPDATE cpms_equipment_usage
            SET statement_original_name = :original_name,
                statement_stored_name = :stored_name,
                statement_stored_path = :stored_path,
                statement_mime_type = :mime_type,
                statement_file_size = :file_size,
                statement_uploaded_by = :uploaded_by,
                statement_uploaded_by_name = :uploaded_by_name,
                statement_uploaded_at = :uploaded_at
            WHERE id = :id
              AND project_id = :project_id
              AND equipment_id = :equipment_id");
        $updated = 0;
        foreach ($usageRows as $row) {
            if (!is_array($row) || !isset($row['id']) || (int)$row['id'] <= 0) continue;
            $st->bindValue(':original_name', $originalName);
            $st->bindValue(':stored_name', $storedName);
            $st->bindValue(':stored_path', $storedPath);
            $st->bindValue(':mime_type', $mime);
            $st->bindValue(':file_size', $fileSize, PDO::PARAM_INT);
            if ($uploadedBy > 0) $st->bindValue(':uploaded_by', $uploadedBy, PDO::PARAM_INT);
            else $st->bindValue(':uploaded_by', null, PDO::PARAM_NULL);
            $st->bindValue(':uploaded_by_name', $uploadedByName);
            $st->bindValue(':uploaded_at', $now);
            $st->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
            $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':equipment_id', (int)$equipmentId, PDO::PARAM_INT);
            $st->execute();
            $updated += (int)$st->rowCount();
        }
        if ($updated <= 0) {
            @unlink($storedPath);
            $result['ok'] = false;
            $result['message'] = '거래명세표를 연결할 사용내역이 없습니다.';
            return $result;
        }
        $result['updated'] = $updated;
        $result['message'] = '거래명세표를 첨부했습니다.';
        return $result;
    } catch (Exception $e) {
        @unlink($storedPath);
        $result['ok'] = false;
        $result['message'] = '거래명세표 DB 저장 실패: ' . $e->getMessage();
        return $result;
    }
}}

if (!function_exists('cpms_equipment_statement_resolve_path')) {
function cpms_equipment_statement_resolve_path($storedPath) {
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return '';
    $real = realpath($storedPath);
    if ($real === false || !is_file($real)) return '';
    $root = realpath(cpms_storage_root());
    if ($root === false) return '';
    $realNorm = str_replace('\\', '/', $real);
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    if (strcasecmp($realNorm, $rootNorm) === 0) return $real;
    if (stripos($realNorm, $rootNorm . '/') !== 0) return '';
    return $real;
}}

if (!function_exists('cpms_equipment_statement_content_type')) {
function cpms_equipment_statement_content_type($fileName, $mimeType) {
    $mimeType = strtolower(trim((string)$mimeType));
    $allowed = cpms_equipment_statement_allowed_mimes();
    if ($mimeType !== '' && isset($allowed[$mimeType]) && $mimeType !== 'application/octet-stream') return $mimeType;
    $ext = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'application/pdf';
    if ($ext === 'png') return 'image/png';
    if ($ext === 'heic') return 'image/heic';
    if ($ext === 'heif') return 'image/heif';
    return 'image/jpeg';
}}
