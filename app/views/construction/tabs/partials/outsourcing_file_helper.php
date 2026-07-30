<?php
/**
 * 공사 > 외주비 첨부파일 helper
 * - PDF, Excel(xls/xlsx/csv)
 * - PHP 5.6 호환
 */

if (!function_exists('cpms_outsourcing_file_table_exists')) {
function cpms_outsourcing_file_table_exists($pdo)
{
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', 'cpms_outsourcing_cost_files');
        $st->execute();
        return $st->fetch(PDO::FETCH_NUM) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_outsourcing_file_ensure_schema')) {
function cpms_outsourcing_file_ensure_schema($pdo)
{
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_outsourcing_cost_files (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            outsourcing_cost_id INT UNSIGNED NOT NULL,
            project_id INT UNSIGNED NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NULL,
            file_size INT UNSIGNED NULL,
            uploaded_by INT NULL,
            uploaded_by_name VARCHAR(100) NULL,
            uploaded_at DATETIME NOT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_outsourcing_file_cost (outsourcing_cost_id, is_deleted),
            KEY idx_outsourcing_file_project (project_id, is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        return true;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_outsourcing_file_allowed_extensions')) {
function cpms_outsourcing_file_allowed_extensions()
{
    return array('pdf'=>true, 'xls'=>true, 'xlsx'=>true, 'xlsm'=>true, 'xlsb'=>true, 'csv'=>true);
}}

if (!function_exists('cpms_outsourcing_file_allowed_mimes')) {
function cpms_outsourcing_file_allowed_mimes()
{
    return array(
        'application/pdf'=>true,
        'application/vnd.ms-excel'=>true,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>true,
        'application/vnd.ms-excel.sheet.macroenabled.12'=>true,
        'application/vnd.ms-excel.sheet.binary.macroenabled.12'=>true,
        'application/vnd.ms-office'=>true,
        'application/msexcel'=>true,
        'application/x-msexcel'=>true,
        'application/zip'=>true,
        'application/x-zip'=>true,
        'application/x-zip-compressed'=>true,
        'application/octet-stream'=>true,
        'text/csv'=>true,
        'text/plain'=>true,
        'application/csv'=>true
    );
}}

if (!function_exists('cpms_outsourcing_file_detect_mime')) {
function cpms_outsourcing_file_detect_mime($tmpPath)
{
    $mime = '';
    if ($tmpPath === '' || !is_file($tmpPath)) return $mime;
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

if (!function_exists('cpms_outsourcing_file_upload_rows')) {
function cpms_outsourcing_file_upload_rows($fieldName)
{
    $rows = array();
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) return $rows;
    $file = $_FILES[$fieldName];
    if (isset($file['name']) && is_array($file['name'])) {
        $count = count($file['name']);
        for ($i = 0; $i < $count; $i++) {
            $rows[] = array(
                'name'=>isset($file['name'][$i]) ? $file['name'][$i] : '',
                'type'=>isset($file['type'][$i]) ? $file['type'][$i] : '',
                'tmp_name'=>isset($file['tmp_name'][$i]) ? $file['tmp_name'][$i] : '',
                'error'=>isset($file['error'][$i]) ? $file['error'][$i] : 4,
                'size'=>isset($file['size'][$i]) ? $file['size'][$i] : 0
            );
        }
    } else {
        $rows[] = $file;
    }
    return $rows;
}}

if (!function_exists('cpms_outsourcing_file_storage_dir')) {
function cpms_outsourcing_file_storage_dir($projectId, $ym)
{
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) $ym = date('Y-m');
    return cpms_storage_root() . '/outsourcing/files/' . ((int)$projectId) . '/' . $ym;
}}

if (!function_exists('cpms_outsourcing_file_store_uploads')) {
function cpms_outsourcing_file_store_uploads($pdo, $fieldName, $projectId, $costId, $ym)
{
    $result = array('has_file'=>false, 'ok'=>true, 'saved_count'=>0, 'message'=>'');
    $uploads = cpms_outsourcing_file_upload_rows($fieldName);
    if (count($uploads) <= 0) return $result;
    if (!$pdo || !cpms_outsourcing_file_ensure_schema($pdo)) {
        return array('has_file'=>true, 'ok'=>false, 'saved_count'=>0, 'message'=>'첨부파일 테이블을 준비하지 못했습니다.');
    }

    $validRows = array();
    $allowedExtensions = cpms_outsourcing_file_allowed_extensions();
    $allowedMimes = cpms_outsourcing_file_allowed_mimes();
    $noFileCode = defined('UPLOAD_ERR_NO_FILE') ? UPLOAD_ERR_NO_FILE : 4;
    $okCode = defined('UPLOAD_ERR_OK') ? UPLOAD_ERR_OK : 0;
    foreach ($uploads as $upload) {
        $error = isset($upload['error']) ? (int)$upload['error'] : $noFileCode;
        if ($error === $noFileCode) continue;
        $result['has_file'] = true;
        if ($error !== $okCode) {
            return array('has_file'=>true, 'ok'=>false, 'saved_count'=>0, 'message'=>'파일 업로드 중 오류가 발생했습니다.');
        }
        $originalName = basename(str_replace('\\', '/', isset($upload['name']) ? (string)$upload['name'] : ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $size = isset($upload['size']) ? (int)$upload['size'] : 0;
        $tmpName = isset($upload['tmp_name']) ? (string)$upload['tmp_name'] : '';
        if ($extension === '' || !isset($allowedExtensions[$extension])) {
            return array('has_file'=>true, 'ok'=>false, 'saved_count'=>0, 'message'=>'PDF, XLS, XLSX, XLSM, XLSB, CSV 파일만 업로드할 수 있습니다.');
        }
        if ($size <= 0 || $size > 20 * 1024 * 1024) {
            return array('has_file'=>true, 'ok'=>false, 'saved_count'=>0, 'message'=>'첨부파일은 파일당 20MB 이하만 업로드할 수 있습니다.');
        }
        if ($tmpName === '' || !is_file($tmpName) || !is_uploaded_file($tmpName)) {
            return array('has_file'=>true, 'ok'=>false, 'saved_count'=>0, 'message'=>'정상적인 업로드 파일이 아닙니다.');
        }
        $mime = cpms_outsourcing_file_detect_mime($tmpName);
        if ($mime !== '' && !isset($allowedMimes[$mime])) {
            return array('has_file'=>true, 'ok'=>false, 'saved_count'=>0, 'message'=>'허용되지 않은 파일 형식입니다.');
        }
        $validRows[] = array('original_name'=>$originalName, 'extension'=>$extension, 'size'=>$size, 'tmp_name'=>$tmpName, 'mime'=>$mime);
    }
    if (!$result['has_file']) return $result;

    $storageDir = cpms_outsourcing_file_storage_dir($projectId, $ym);
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        return array('has_file'=>true, 'ok'=>false, 'saved_count'=>0, 'message'=>'첨부파일 저장 폴더를 만들지 못했습니다.');
    }

    $st = $pdo->prepare("INSERT INTO cpms_outsourcing_cost_files
        (outsourcing_cost_id, project_id, original_name, stored_name, stored_path, mime_type, file_size, uploaded_by, uploaded_by_name, uploaded_at, is_deleted)
        VALUES (:cost_id, :project_id, :original_name, :stored_name, :stored_path, :mime_type, :file_size, :uploaded_by, :uploaded_by_name, :uploaded_at, 0)");
    foreach ($validRows as $validRow) {
        $random = substr(sha1(uniqid((string)mt_rand(), true)), 0, 16);
        $storedName = 'outsourcing_' . (int)$costId . '_' . date('Ymd_His') . '_' . $random . '.' . $validRow['extension'];
        $absolutePath = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . $storedName;
        if (!@move_uploaded_file($validRow['tmp_name'], $absolutePath)) {
            return array('has_file'=>true, 'ok'=>false, 'saved_count'=>$result['saved_count'], 'message'=>'첨부파일을 저장하지 못했습니다.');
        }
        $relativePath = 'outsourcing/files/' . ((int)$projectId) . '/' . $ym . '/' . $storedName;
        try {
            $uploadedBy = method_exists('App\\Core\\Auth', 'id') ? (int)\App\Core\Auth::id() : 0;
            $st->bindValue(':cost_id', (int)$costId, PDO::PARAM_INT);
            $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':original_name', $validRow['original_name']);
            $st->bindValue(':stored_name', $storedName);
            $st->bindValue(':stored_path', $relativePath);
            $st->bindValue(':mime_type', $validRow['mime'] === '' ? null : $validRow['mime'], $validRow['mime'] === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $st->bindValue(':file_size', (int)$validRow['size'], PDO::PARAM_INT);
            $st->bindValue(':uploaded_by', $uploadedBy > 0 ? $uploadedBy : null, $uploadedBy > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(':uploaded_by_name', (string)\App\Core\Auth::userName());
            $st->bindValue(':uploaded_at', date('Y-m-d H:i:s'));
            $st->execute();
            $result['saved_count']++;
        } catch (Exception $e) {
            @unlink($absolutePath);
            return array('has_file'=>true, 'ok'=>false, 'saved_count'=>$result['saved_count'], 'message'=>'첨부파일 정보를 저장하지 못했습니다.');
        }
    }
    $result['message'] = $result['saved_count'] . '개 파일을 첨부했습니다.';
    return $result;
}}

if (!function_exists('cpms_outsourcing_files_by_cost_ids')) {
function cpms_outsourcing_files_by_cost_ids($pdo, $costIds)
{
    $map = array();
    if (!$pdo || !is_array($costIds) || count($costIds) <= 0 || !cpms_outsourcing_file_ensure_schema($pdo)) return $map;
    $ids = array();
    foreach ($costIds as $costId) {
        $costId = (int)$costId;
        if ($costId > 0) $ids[$costId] = $costId;
    }
    if (count($ids) <= 0) return $map;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_outsourcing_cost_files WHERE is_deleted = 0 AND outsourcing_cost_id IN (" . $placeholders . ") ORDER BY id ASC");
        $position = 1;
        foreach ($ids as $id) {
            $st->bindValue($position, $id, PDO::PARAM_INT);
            $position++;
        }
        $st->execute();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $costId = isset($row['outsourcing_cost_id']) ? (int)$row['outsourcing_cost_id'] : 0;
            if (!isset($map[$costId])) $map[$costId] = array();
            $map[$costId][] = $row;
        }
    } catch (Exception $e) {
        return array();
    }
    return $map;
}}

if (!function_exists('cpms_outsourcing_file_resolve_path')) {
function cpms_outsourcing_file_resolve_path($storedPath)
{
    $storedPath = ltrim(str_replace('\\', '/', trim((string)$storedPath)), '/');
    if ($storedPath === '' || strpos($storedPath, '..') !== false) return '';
    $root = rtrim(str_replace('\\', '/', cpms_storage_root()), '/');
    return str_replace('/', DIRECTORY_SEPARATOR, $root . '/' . $storedPath);
}}
