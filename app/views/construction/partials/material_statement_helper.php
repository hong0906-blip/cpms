<?php
/**
 * 자재구입비 거래명세표 파일 helper
 * - PHP 5.6 호환
 */

if (!function_exists('cpms_material_statement_allowed_extensions')) {
function cpms_material_statement_allowed_extensions() {
    return array('pdf'=>true, 'jpg'=>true, 'jpeg'=>true, 'png'=>true, 'xlsx'=>true, 'xls'=>true);
}
}

if (!function_exists('cpms_material_statement_allowed_mimes')) {
function cpms_material_statement_allowed_mimes() {
    return array(
        'application/pdf'=>true,
        'image/jpeg'=>true,
        'image/pjpeg'=>true,
        'image/png'=>true,
        'image/x-png'=>true,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>true,
        'application/vnd.ms-excel'=>true,
        'application/vnd.ms-office'=>true,
        'application/msexcel'=>true,
        'application/x-msexcel'=>true,
        'application/zip'=>true,
        'application/x-zip'=>true,
        'application/x-zip-compressed'=>true,
        'application/octet-stream'=>true
    );
}
}

if (!function_exists('cpms_material_statement_max_size')) {
function cpms_material_statement_max_size() {
    return 10 * 1024 * 1024;
}
}

if (!function_exists('cpms_material_statement_table_exists')) {
function cpms_material_statement_table_exists($pdo) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', 'cpms_material_statement_files');
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}
}

if (!function_exists('cpms_material_statement_column_exists')) {
function cpms_material_statement_column_exists($pdo, $column) {
    if (!$pdo || $column === '') return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM cpms_material_statement_files LIKE :col");
        $st->bindValue(':col', $column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}
}

if (!function_exists('cpms_material_statement_index_exists')) {
function cpms_material_statement_index_exists($pdo, $indexName) {
    if (!$pdo || $indexName === '') return false;
    try {
        $st = $pdo->prepare("SHOW INDEX FROM cpms_material_statement_files WHERE Key_name = :idx");
        $st->bindValue(':idx', $indexName);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}
}

if (!function_exists('cpms_material_statement_ensure_schema')) {
function cpms_material_statement_ensure_schema($pdo) {
    if (!$pdo) return false;

    $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_material_statement_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        material_id INT NULL,
        material_usage_id INT NULL,
        use_date DATE NULL,
        ym VARCHAR(7) NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        stored_path VARCHAR(500) NOT NULL,
        mime_type VARCHAR(100) NULL,
        file_size INT NULL,
        uploaded_by INT NULL,
        uploaded_by_name VARCHAR(100) NULL,
        uploaded_at DATETIME NOT NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        deleted_by INT NULL,
        deleted_at DATETIME NULL,
        INDEX idx_project_id (project_id),
        INDEX idx_material_id (material_id),
        INDEX idx_material_usage_id (material_usage_id),
        INDEX idx_ym (ym),
        INDEX idx_use_date (use_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = array(
        'project_id'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN project_id INT NOT NULL DEFAULT 0 AFTER id",
        'material_id'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN material_id INT NULL AFTER project_id",
        'material_usage_id'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN material_usage_id INT NULL AFTER material_id",
        'use_date'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN use_date DATE NULL AFTER material_usage_id",
        'ym'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN ym VARCHAR(7) NULL AFTER use_date",
        'original_name'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN original_name VARCHAR(255) NOT NULL DEFAULT '' AFTER ym",
        'stored_name'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN stored_name VARCHAR(255) NOT NULL DEFAULT '' AFTER original_name",
        'stored_path'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN stored_path VARCHAR(500) NOT NULL DEFAULT '' AFTER stored_name",
        'mime_type'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN mime_type VARCHAR(100) NULL AFTER stored_path",
        'file_size'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN file_size INT NULL AFTER mime_type",
        'uploaded_by'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN uploaded_by INT NULL AFTER file_size",
        'uploaded_by_name'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN uploaded_by_name VARCHAR(100) NULL AFTER uploaded_by",
        'uploaded_at'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN uploaded_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER uploaded_by_name",
        'is_deleted'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER uploaded_at",
        'deleted_by'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN deleted_by INT NULL AFTER is_deleted",
        'deleted_at'=>"ALTER TABLE cpms_material_statement_files ADD COLUMN deleted_at DATETIME NULL AFTER deleted_by"
    );
    foreach ($columns as $column => $sql) {
        if (!cpms_material_statement_column_exists($pdo, $column)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }

    $indexes = array(
        'idx_project_id'=>"ALTER TABLE cpms_material_statement_files ADD INDEX idx_project_id (project_id)",
        'idx_material_id'=>"ALTER TABLE cpms_material_statement_files ADD INDEX idx_material_id (material_id)",
        'idx_material_usage_id'=>"ALTER TABLE cpms_material_statement_files ADD INDEX idx_material_usage_id (material_usage_id)",
        'idx_ym'=>"ALTER TABLE cpms_material_statement_files ADD INDEX idx_ym (ym)",
        'idx_use_date'=>"ALTER TABLE cpms_material_statement_files ADD INDEX idx_use_date (use_date)"
    );
    foreach ($indexes as $indexName => $sqlIndex) {
        if (!cpms_material_statement_index_exists($pdo, $indexName)) {
            try { $pdo->exec($sqlIndex); } catch (Exception $e) {}
        }
    }

    return true;
}
}

if (!function_exists('cpms_material_statement_schema_ready')) {
function cpms_material_statement_schema_ready($pdo) {
    if (!cpms_material_statement_table_exists($pdo)) return false;
    $required = array('id', 'project_id', 'material_id', 'material_usage_id', 'use_date', 'ym', 'original_name', 'stored_name', 'stored_path', 'mime_type', 'file_size', 'uploaded_by', 'uploaded_by_name', 'uploaded_at', 'is_deleted', 'deleted_by', 'deleted_at');
    foreach ($required as $column) {
        if (!cpms_material_statement_column_exists($pdo, $column)) return false;
    }
    return true;
}
}

if (!function_exists('cpms_material_statement_upload_error_message')) {
function cpms_material_statement_upload_error_message($code) {
    $code = (int)$code;
    if ($code === 1 || $code === 2) return '파일 용량이 너무 큽니다.';
    if ($code === 3) return '파일이 일부만 업로드되었습니다.';
    if ($code === 6 || $code === 7 || $code === 8) return '서버에서 파일을 저장할 수 없습니다.';
    return '파일 업로드 오류가 발생했습니다.';
}
}

if (!function_exists('cpms_material_statement_detect_mime')) {
function cpms_material_statement_detect_mime($tmpPath) {
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
}
}

if (!function_exists('cpms_material_statement_has_upload')) {
function cpms_material_statement_has_upload($fieldName) {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) return false;
    $file = $_FILES[$fieldName];
    $noFile = defined('UPLOAD_ERR_NO_FILE') ? UPLOAD_ERR_NO_FILE : 4;
    $error = isset($file['error']) ? (int)$file['error'] : $noFile;
    return $error !== $noFile;
}
}

if (!function_exists('cpms_material_statement_validate_upload')) {
function cpms_material_statement_validate_upload($file, &$message, &$ext, &$mime) {
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
        $message = cpms_material_statement_upload_error_message($error);
        return false;
    }

    $originalName = isset($file['name']) ? (string)$file['name'] : '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = cpms_material_statement_allowed_extensions();
    if ($ext === '' || !isset($allowedExt[$ext])) {
        $message = '허용되지 않은 파일 형식입니다. pdf, jpg, jpeg, png, xlsx, xls만 업로드할 수 있습니다.';
        return false;
    }

    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0) {
        $message = '빈 파일은 업로드할 수 없습니다.';
        return false;
    }
    if ($size > cpms_material_statement_max_size()) {
        $message = '거래명세표 파일은 10MB 이하만 업로드할 수 있습니다.';
        return false;
    }

    $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmpName === '' || !is_file($tmpName)) {
        $message = '업로드된 임시 파일을 찾을 수 없습니다.';
        return false;
    }
    if (!is_uploaded_file($tmpName)) {
        $message = '정상적인 업로드 파일이 아닙니다.';
        return false;
    }

    $mime = cpms_material_statement_detect_mime($tmpName);
    if ($mime !== '') {
        $allowedMimes = cpms_material_statement_allowed_mimes();
        if (!isset($allowedMimes[$mime])) {
            $message = '허용되지 않은 MIME 형식입니다.';
            return false;
        }
    }

    return true;
}
}

if (!function_exists('cpms_material_statement_normalize_dept')) {
function cpms_material_statement_normalize_dept($dept) {
    $dept = trim((string)$dept);
    $map = array(
        '관리부'=>'관리',
        '관리팀'=>'관리',
        '공무부'=>'공무',
        '공무팀'=>'공무',
        '공사부'=>'공사',
        '공사팀'=>'공사'
    );
    if (isset($map[$dept])) return $map[$dept];
    return $dept;
}
}

if (!function_exists('cpms_material_statement_user_can_download')) {
function cpms_material_statement_user_can_download($pdo, $projectId) {
    if (!class_exists('App\\Core\\Auth')) return false;
    if (!\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;

    $dept = cpms_material_statement_normalize_dept(\App\Core\Auth::userDepartment());
    if ($dept === '관리' || $dept === '공무') return true;

    if (method_exists('App\\Core\\Auth', 'canAccessConstruction') && \App\Core\Auth::canAccessConstruction()) return true;

    $role = \App\Core\Auth::userRole();
    $email = (string)\App\Core\Auth::userEmail();
    if (function_exists('cpms_is_project_member_or_executive')) {
        if (cpms_is_project_member_or_executive($pdo, (int)$projectId, $role, $email)) return true;
    }

    return false;
}
}

if (!function_exists('cpms_material_statement_storage_dir')) {
function cpms_material_statement_storage_dir($projectId, $ym) {
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)$ym) ? (string)$ym : date('Y-m');
    return cpms_storage_root() . '/materials/statements/' . ((int)$projectId) . '/' . $ym;
}
}

if (!function_exists('cpms_material_statement_safe_name')) {
function cpms_material_statement_safe_name($projectId, $materialUsageId, $ext) {
    $random = substr(md5(uniqid('', true)), 0, 8);
    return 'statement_' . ((int)$projectId) . '_' . ((int)$materialUsageId) . '_' . date('Ymd_His') . '_' . $random . '.' . strtolower((string)$ext);
}
}

if (!function_exists('cpms_material_statement_current_user_id')) {
function cpms_material_statement_current_user_id() {
    if (!class_exists('App\\Core\\Auth')) return 0;
    $user = \App\Core\Auth::user();
    if (is_array($user) && isset($user['id'])) return (int)$user['id'];
    return 0;
}
}

if (!function_exists('cpms_material_statement_store_uploaded_file_for_usage_rows')) {
function cpms_material_statement_store_uploaded_file_for_usage_rows($pdo, $fieldName, $projectId, $materialId, $usageRows, $ym) {
    $result = array('has_file'=>false, 'ok'=>true, 'message'=>'', 'inserted'=>0);

    if (!cpms_material_statement_has_upload($fieldName)) return $result;
    $result['has_file'] = true;

    if (!$pdo) {
        $result['ok'] = false;
        $result['message'] = 'DB 연결 실패';
        return $result;
    }
    if (!cpms_material_statement_schema_ready($pdo)) {
        $result['ok'] = false;
        $result['message'] = '거래명세표 파일 테이블이 준비되지 않았습니다. 공사 DB 설정에서 자재구입비 테이블 생성/확인을 실행해주세요.';
        return $result;
    }
    if (!is_array($usageRows) || count($usageRows) <= 0) {
        $result['ok'] = false;
        $result['message'] = '사용일자를 선택해야 거래명세표를 첨부할 수 있습니다.';
        return $result;
    }

    $file = $_FILES[$fieldName];
    $message = '';
    $ext = '';
    $mime = '';
    if (!cpms_material_statement_validate_upload($file, $message, $ext, $mime)) {
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

    $dir = cpms_material_statement_storage_dir($projectId, $ym);
    if (!cpms_ensure_dir($dir)) {
        $result['ok'] = false;
        $result['message'] = '거래명세표 저장 폴더를 만들 수 없습니다.';
        return $result;
    }

    $storedName = cpms_material_statement_safe_name($projectId, $firstUsageId, $ext);
    $storedPath = rtrim($dir, '/\\') . '/' . $storedName;
    $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';

    if (!@move_uploaded_file($tmpName, $storedPath)) {
        $result['ok'] = false;
        $result['message'] = '거래명세표 파일 저장에 실패했습니다.';
        return $result;
    }
    @chmod($storedPath, 0644);

    $originalName = isset($file['name']) ? basename((string)$file['name']) : '';
    if ($originalName === '') $originalName = 'statement.' . $ext;
    $fileSize = is_file($storedPath) ? (int)filesize($storedPath) : (int)$file['size'];
    $uploadedBy = cpms_material_statement_current_user_id();
    $uploadedByName = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userName() : '';
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("INSERT INTO cpms_material_statement_files
            (project_id, material_id, material_usage_id, use_date, ym, original_name, stored_name, stored_path, mime_type, file_size, uploaded_by, uploaded_by_name, uploaded_at, is_deleted)
            VALUES
            (:project_id, :material_id, :material_usage_id, :use_date, :ym, :original_name, :stored_name, :stored_path, :mime_type, :file_size, :uploaded_by, :uploaded_by_name, :uploaded_at, 0)");

        $inserted = 0;
        foreach ($usageRows as $row) {
            if (!is_array($row)) continue;
            $usageId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($usageId <= 0) continue;
            $useDate = isset($row['use_date']) ? (string)$row['use_date'] : null;
            $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':material_id', (int)$materialId, PDO::PARAM_INT);
            $st->bindValue(':material_usage_id', $usageId, PDO::PARAM_INT);
            if ($useDate === '' || $useDate === null) {
                $st->bindValue(':use_date', null, PDO::PARAM_NULL);
            } else {
                $st->bindValue(':use_date', $useDate);
            }
            $st->bindValue(':ym', (string)$ym);
            $st->bindValue(':original_name', $originalName);
            $st->bindValue(':stored_name', $storedName);
            $st->bindValue(':stored_path', $storedPath);
            $st->bindValue(':mime_type', $mime);
            $st->bindValue(':file_size', $fileSize, PDO::PARAM_INT);
            if ($uploadedBy > 0) {
                $st->bindValue(':uploaded_by', $uploadedBy, PDO::PARAM_INT);
            } else {
                $st->bindValue(':uploaded_by', null, PDO::PARAM_NULL);
            }
            $st->bindValue(':uploaded_by_name', $uploadedByName);
            $st->bindValue(':uploaded_at', $now);
            $st->execute();
            $inserted++;
        }

        if ($inserted <= 0) {
            $pdo->rollBack();
            @unlink($storedPath);
            $result['ok'] = false;
            $result['message'] = '거래명세표를 연결할 사용내역이 없습니다.';
            return $result;
        }

        $pdo->commit();
        $result['inserted'] = $inserted;
        $result['message'] = '거래명세표를 첨부했습니다.';
        return $result;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($storedPath);
        $result['ok'] = false;
        $result['message'] = '거래명세표 DB 저장 실패: ' . $e->getMessage();
        return $result;
    }
}
}

if (!function_exists('cpms_material_statement_files_by_usage_ids')) {
function cpms_material_statement_files_by_usage_ids($pdo, $usageIds) {
    $map = array();
    if (!$pdo || !is_array($usageIds) || count($usageIds) <= 0) return $map;
    if (!cpms_material_statement_schema_ready($pdo)) return $map;

    $unique = array();
    foreach ($usageIds as $id) {
        $id = (int)$id;
        if ($id > 0) $unique[$id] = $id;
    }
    if (count($unique) <= 0) return $map;

    $ids = array_values($unique);
    $chunks = array_chunk($ids, 200);
    foreach ($chunks as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        if ($in === '') continue;
        try {
            $st = $pdo->query("SELECT * FROM cpms_material_statement_files WHERE is_deleted = 0 AND material_usage_id IN (" . $in . ") ORDER BY id ASC");
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            foreach ($rows as $row) {
                $usageId = isset($row['material_usage_id']) ? (int)$row['material_usage_id'] : 0;
                if ($usageId <= 0) continue;
                if (!isset($map[$usageId])) $map[$usageId] = array();
                $map[$usageId][count($map[$usageId])] = $row;
            }
        } catch (Exception $e) {
        }
    }
    return $map;
}
}

if (!function_exists('cpms_material_statement_resolve_path')) {
function cpms_material_statement_resolve_path($storedPath) {
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return '';

    $path = $storedPath;
    $isWindowsAbs = preg_match('/^[A-Za-z]:[\/\\\\]/', $path) ? true : false;
    $isUnixAbs = substr($path, 0, 1) === '/';
    $isUnc = substr($path, 0, 2) === '\\\\';
    if (!$isWindowsAbs && !$isUnixAbs && !$isUnc) {
        $path = dirname(cpms_storage_root()) . '/' . ltrim($path, '/\\');
    }

    $real = realpath($path);
    $root = realpath(cpms_storage_root() . '/materials/statements');
    if ($real === false || $root === false) return '';

    $realNorm = str_replace('\\', '/', $real);
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    if (strcasecmp($realNorm, $rootNorm) === 0) return $real;
    if (stripos($realNorm, $rootNorm . '/') !== 0) return '';

    return $real;
}
}

if (!function_exists('cpms_material_statement_content_type')) {
function cpms_material_statement_content_type($fileName, $mimeType) {
    $mimeType = strtolower(trim((string)$mimeType));
    $allowedMimes = cpms_material_statement_allowed_mimes();
    if ($mimeType !== '' && isset($allowedMimes[$mimeType]) && $mimeType !== 'application/octet-stream') {
        return $mimeType;
    }
    $ext = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'application/pdf';
    if ($ext === 'jpg' || $ext === 'jpeg') return 'image/jpeg';
    if ($ext === 'png') return 'image/png';
    if ($ext === 'xlsx') return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    if ($ext === 'xls') return 'application/vnd.ms-excel';
    return 'application/octet-stream';
}
}
