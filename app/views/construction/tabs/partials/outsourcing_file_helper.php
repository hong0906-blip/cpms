<?php
/**
 * 파일: C:\www\cpms\app\views\construction\tabs\partials\outsourcing_file_helper.php
 * 공사 > 외주비 첨부파일
 * - Google Drive 저장
 * - PDF / Excel / JPG / JPEG / PNG / WEBP
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../../../services/GoogleDriveHelper.php';

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

if (!function_exists('cpms_outsourcing_file_column_exists')) {
function cpms_outsourcing_file_column_exists($pdo, $column)
{
    if (!$pdo || trim((string)$column) === '') return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM cpms_outsourcing_cost_files LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch(PDO::FETCH_ASSOC) ? true : false;
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
            stored_name VARCHAR(255) NOT NULL DEFAULT '',
            stored_path VARCHAR(500) NOT NULL DEFAULT '',
            mime_type VARCHAR(120) NULL,
            file_size INT UNSIGNED NULL,
            storage_type VARCHAR(30) NULL,
            drive_file_id VARCHAR(128) NULL,
            drive_folder_id VARCHAR(128) NULL,
            drive_web_view_link TEXT NULL,
            drive_web_content_link TEXT NULL,
            upload_status VARCHAR(30) NULL,
            uploaded_by INT NULL,
            uploaded_by_name VARCHAR(100) NULL,
            uploaded_at DATETIME NOT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_outsourcing_file_cost (outsourcing_cost_id, is_deleted),
            KEY idx_outsourcing_file_project (project_id, is_deleted),
            KEY idx_outsourcing_drive_file (drive_file_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $adds = array(
            'storage_type' => "ALTER TABLE cpms_outsourcing_cost_files ADD COLUMN storage_type VARCHAR(30) NULL AFTER file_size",
            'drive_file_id' => "ALTER TABLE cpms_outsourcing_cost_files ADD COLUMN drive_file_id VARCHAR(128) NULL AFTER storage_type",
            'drive_folder_id' => "ALTER TABLE cpms_outsourcing_cost_files ADD COLUMN drive_folder_id VARCHAR(128) NULL AFTER drive_file_id",
            'drive_web_view_link' => "ALTER TABLE cpms_outsourcing_cost_files ADD COLUMN drive_web_view_link TEXT NULL AFTER drive_folder_id",
            'drive_web_content_link' => "ALTER TABLE cpms_outsourcing_cost_files ADD COLUMN drive_web_content_link TEXT NULL AFTER drive_web_view_link",
            'upload_status' => "ALTER TABLE cpms_outsourcing_cost_files ADD COLUMN upload_status VARCHAR(30) NULL AFTER drive_web_content_link"
        );
        foreach ($adds as $column => $sql) {
            if (!cpms_outsourcing_file_column_exists($pdo, $column)) $pdo->exec($sql);
        }
        return true;
    } catch (Exception $e) {
        error_log('[OutsourcingFile] schema: ' . $e->getMessage());
        return false;
    }
}}

if (!function_exists('cpms_outsourcing_file_allowed_extensions')) {
function cpms_outsourcing_file_allowed_extensions()
{
    return array('pdf'=>true, 'xls'=>true, 'xlsx'=>true, 'xlsm'=>true, 'xlsb'=>true, 'csv'=>true, 'jpg'=>true, 'jpeg'=>true, 'png'=>true, 'webp'=>true);
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
        'application/csv'=>true,
        'image/jpeg'=>true,
        'image/png'=>true,
        'image/webp'=>true
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
    if ($mime === '' && function_exists('mime_content_type')) $mime = (string)@mime_content_type($tmpPath);
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

if (!function_exists('cpms_outsourcing_file_validate_upload')) {
function cpms_outsourcing_file_validate_upload($upload)
{
    $noFileCode = defined('UPLOAD_ERR_NO_FILE') ? UPLOAD_ERR_NO_FILE : 4;
    $okCode = defined('UPLOAD_ERR_OK') ? UPLOAD_ERR_OK : 0;
    $error = isset($upload['error']) ? (int)$upload['error'] : $noFileCode;
    if ($error === $noFileCode) return array('ok'=>false, 'no_file'=>true, 'message'=>'파일이 없습니다.');
    if ($error !== $okCode) return array('ok'=>false, 'no_file'=>false, 'message'=>'파일 업로드 중 오류가 발생했습니다. 오류코드: ' . $error);

    $originalName = basename(str_replace('\\', '/', isset($upload['name']) ? (string)$upload['name'] : ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $size = isset($upload['size']) ? (int)$upload['size'] : 0;
    $tmpName = isset($upload['tmp_name']) ? (string)$upload['tmp_name'] : '';
    $allowedExtensions = cpms_outsourcing_file_allowed_extensions();
    if ($extension === '' || !isset($allowedExtensions[$extension])) {
        return array('ok'=>false, 'no_file'=>false, 'message'=>'PDF, Excel, JPG, JPEG, PNG, WEBP 파일만 업로드할 수 있습니다.');
    }
    if ($size <= 0 || $size > 20 * 1024 * 1024) return array('ok'=>false, 'no_file'=>false, 'message'=>'첨부파일은 파일당 20MB 이하만 업로드할 수 있습니다.');
    if ($tmpName === '' || !is_file($tmpName) || !is_uploaded_file($tmpName)) return array('ok'=>false, 'no_file'=>false, 'message'=>'정상적인 업로드 파일이 아닙니다.');
    $mime = cpms_outsourcing_file_detect_mime($tmpName);
    $allowedMimes = cpms_outsourcing_file_allowed_mimes();
    if ($mime !== '' && !isset($allowedMimes[$mime])) return array('ok'=>false, 'no_file'=>false, 'message'=>'허용되지 않은 파일 형식입니다: ' . $mime);
    return array('ok'=>true, 'no_file'=>false, 'original_name'=>$originalName, 'extension'=>$extension, 'size'=>$size, 'tmp_name'=>$tmpName, 'mime'=>$mime);
}}

if (!function_exists('cpms_outsourcing_drive_project_row')) {
function cpms_outsourcing_drive_project_row($pdo, $projectId)
{
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id=:id LIMIT 1");
        $st->bindValue(':id', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}}

if (!function_exists('cpms_outsourcing_drive_target_folder')) {
function cpms_outsourcing_drive_target_folder($pdo, $projectId, $ym, $userContext, $originalName)
{
    $projectId = (int)$projectId;
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) $ym = date('Y-m');
    $project = cpms_outsourcing_drive_project_row($pdo, $projectId);
    if (!is_array($project)) return array('ok'=>false, 'message'=>'프로젝트 정보를 찾을 수 없습니다.');

    $projectName = isset($project['name']) ? trim((string)$project['name']) : '';
    $projectFolderId = isset($project['drive_folder_id']) ? trim((string)$project['drive_folder_id']) : '';
    if ($projectFolderId === '' && function_exists('cpms_drive_sync_project_after_create')) {
        @cpms_drive_sync_project_after_create($pdo, $projectId, $projectName, $userContext, 'outsourcing_upload');
        $project = cpms_outsourcing_drive_project_row($pdo, $projectId);
        $projectFolderId = is_array($project) && isset($project['drive_folder_id']) ? trim((string)$project['drive_folder_id']) : '';
    }
    if ($projectFolderId === '') return array('ok'=>false, 'message'=>'프로젝트 Google Drive 폴더가 준비되지 않았습니다.');

    $driveData = array();
    if (isset($project['drive_folders_json']) && trim((string)$project['drive_folders_json']) !== '') {
        $decoded = @json_decode((string)$project['drive_folders_json'], true);
        if (is_array($decoded)) $driveData = $decoded;
    }
    $constructionFolderId = '';
    if (isset($driveData['folders']) && is_array($driveData['folders']) && isset($driveData['folders']['construction'])) {
        $constructionFolderId = trim((string)$driveData['folders']['construction']);
    }

    $context = array('user'=>$userContext, 'section'=>'construction_outsourcing', 'project_id'=>$projectId, 'original_name'=>$originalName);
    if ($constructionFolderId === '') {
        $construction = cpms_drive_find_or_create_folder('03_공사', $projectFolderId, $context);
        if (empty($construction['ok']) || !isset($construction['file']['id'])) return array('ok'=>false, 'message'=>isset($construction['message']) ? $construction['message'] : '공사 Drive 폴더를 준비하지 못했습니다.');
        $constructionFolderId = (string)$construction['file']['id'];
    }
    $outsourcing = cpms_drive_find_or_create_folder('외주비', $constructionFolderId, $context);
    if (empty($outsourcing['ok']) || !isset($outsourcing['file']['id'])) return array('ok'=>false, 'message'=>isset($outsourcing['message']) ? $outsourcing['message'] : '외주비 Drive 폴더를 준비하지 못했습니다.');

    $parts = explode('-', $ym);
    $year = $parts[0];
    $month = $parts[1];
    $yearFolder = cpms_drive_find_or_create_folder($year, (string)$outsourcing['file']['id'], $context);
    if (empty($yearFolder['ok']) || !isset($yearFolder['file']['id'])) return array('ok'=>false, 'message'=>isset($yearFolder['message']) ? $yearFolder['message'] : '외주비 연도 폴더를 준비하지 못했습니다.');
    $monthFolder = cpms_drive_find_or_create_folder($month, (string)$yearFolder['file']['id'], $context);
    if (empty($monthFolder['ok']) || !isset($monthFolder['file']['id'])) return array('ok'=>false, 'message'=>isset($monthFolder['message']) ? $monthFolder['message'] : '외주비 월 폴더를 준비하지 못했습니다.');
    return array('ok'=>true, 'folder_id'=>(string)$monthFolder['file']['id'], 'project_name'=>$projectName);
}}

if (!function_exists('cpms_outsourcing_file_store_one_drive')) {
function cpms_outsourcing_file_store_one_drive($pdo, $upload, $projectId, $costId, $ym)
{
    if (!$pdo || !cpms_outsourcing_file_ensure_schema($pdo)) return array('ok'=>false, 'message'=>'첨부파일 테이블을 준비하지 못했습니다.');
    $valid = cpms_outsourcing_file_validate_upload($upload);
    if (empty($valid['ok'])) return $valid;

    $userContext = array(
        'id'=>method_exists('App\\Core\\Auth', 'id') ? (int)\App\Core\Auth::id() : 0,
        'name'=>(string)\App\Core\Auth::userName(),
        'email'=>(string)\App\Core\Auth::userEmail()
    );
    $target = cpms_outsourcing_drive_target_folder($pdo, $projectId, $ym, $userContext, $valid['original_name']);
    if (empty($target['ok'])) return array('ok'=>false, 'message'=>isset($target['message']) ? $target['message'] : 'Google Drive 폴더 준비에 실패했습니다.');

    $driveName = date('Y-m-d_His') . '_외주비_' . (isset($target['project_name']) ? $target['project_name'] : '') . '_' . $valid['original_name'];
    if (function_exists('cpms_drive_sanitize_file_name')) $driveName = cpms_drive_sanitize_file_name($driveName, 180);
    $context = array('user'=>$userContext, 'section'=>'construction_outsourcing', 'project_id'=>(int)$projectId, 'document_type'=>'외주비', 'original_name'=>$valid['original_name'], 'target_folder_id'=>$target['folder_id']);
    $drive = cpms_drive_upload_file($valid['tmp_name'], $driveName, $target['folder_id'], $valid['mime'], $context);
    if (empty($drive['ok']) || !isset($drive['file']) || !is_array($drive['file']) || empty($drive['file']['id'])) {
        return array('ok'=>false, 'message'=>isset($drive['message']) ? $drive['message'] : 'Google Drive 업로드에 실패했습니다.');
    }
    $file = $drive['file'];
    try {
        $st = $pdo->prepare("INSERT INTO cpms_outsourcing_cost_files
            (outsourcing_cost_id, project_id, original_name, stored_name, stored_path, mime_type, file_size, storage_type, drive_file_id, drive_folder_id, drive_web_view_link, drive_web_content_link, upload_status, uploaded_by, uploaded_by_name, uploaded_at, is_deleted)
            VALUES (:cost_id,:project_id,:original_name,:stored_name,'',:mime_type,:file_size,'google_drive',:drive_file_id,:drive_folder_id,:view_link,:content_link,'uploaded',:uploaded_by,:uploaded_by_name,:uploaded_at,0)");
        $st->execute(array(
            ':cost_id'=>(int)$costId,
            ':project_id'=>(int)$projectId,
            ':original_name'=>$valid['original_name'],
            ':stored_name'=>isset($file['name']) ? (string)$file['name'] : $driveName,
            ':mime_type'=>$valid['mime'] !== '' ? $valid['mime'] : null,
            ':file_size'=>(int)$valid['size'],
            ':drive_file_id'=>(string)$file['id'],
            ':drive_folder_id'=>(string)$target['folder_id'],
            ':view_link'=>isset($file['webViewLink']) ? (string)$file['webViewLink'] : '',
            ':content_link'=>isset($file['webContentLink']) ? (string)$file['webContentLink'] : '',
            ':uploaded_by'=>isset($userContext['id']) && $userContext['id'] > 0 ? (int)$userContext['id'] : null,
            ':uploaded_by_name'=>(string)$userContext['name'],
            ':uploaded_at'=>date('Y-m-d H:i:s')
        ));
        return array('ok'=>true, 'file_id'=>(int)$pdo->lastInsertId(), 'original_name'=>$valid['original_name'], 'drive_file_id'=>(string)$file['id'], 'message'=>'Google Drive 업로드 완료');
    } catch (Exception $e) {
        cpms_drive_delete_file((string)$file['id'], $context);
        return array('ok'=>false, 'message'=>'업로드는 되었지만 파일정보 저장에 실패했습니다.');
    }
}}

if (!function_exists('cpms_outsourcing_file_store_uploads')) {
function cpms_outsourcing_file_store_uploads($pdo, $fieldName, $projectId, $costId, $ym)
{
    $result = array('has_file'=>false, 'ok'=>true, 'saved_count'=>0, 'message'=>'');
    $uploads = cpms_outsourcing_file_upload_rows($fieldName);
    foreach ($uploads as $upload) {
        $error = isset($upload['error']) ? (int)$upload['error'] : 4;
        if ($error === 4) continue;
        $result['has_file'] = true;
        $one = cpms_outsourcing_file_store_one_drive($pdo, $upload, $projectId, $costId, $ym);
        if (empty($one['ok'])) {
            $result['ok'] = false;
            $result['message'] = isset($one['message']) ? $one['message'] : '파일 업로드 실패';
            return $result;
        }
        $result['saved_count']++;
    }
    if ($result['has_file']) $result['message'] = $result['saved_count'] . '개 파일을 Google Drive에 첨부했습니다.';
    return $result;
}}

if (!function_exists('cpms_outsourcing_files_by_cost_ids')) {
function cpms_outsourcing_files_by_cost_ids($pdo, $costIds)
{
    $map = array();
    if (!$pdo || !is_array($costIds) || count($costIds) <= 0 || !cpms_outsourcing_file_ensure_schema($pdo)) return $map;
    $ids = array();
    foreach ($costIds as $costId) { $costId=(int)$costId; if ($costId>0) $ids[$costId]=$costId; }
    if (count($ids) <= 0) return $map;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_outsourcing_cost_files WHERE is_deleted=0 AND outsourcing_cost_id IN (".$placeholders.") ORDER BY id ASC");
        $pos=1; foreach ($ids as $id) { $st->bindValue($pos,$id,PDO::PARAM_INT); $pos++; }
        $st->execute();
        while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
            $costId=isset($row['outsourcing_cost_id'])?(int)$row['outsourcing_cost_id']:0;
            if (!isset($map[$costId])) $map[$costId]=array();
            $map[$costId][]=$row;
        }
    } catch (Exception $e) { return array(); }
    return $map;
}}

if (!function_exists('cpms_outsourcing_file_resolve_path')) {
function cpms_outsourcing_file_resolve_path($storedPath)
{
    $storedPath=ltrim(str_replace('\\','/',trim((string)$storedPath)),'/');
    if ($storedPath==='' || strpos($storedPath,'..')!==false) return '';
    $root=rtrim(str_replace('\\','/',cpms_storage_root()),'/');
    return str_replace('/',DIRECTORY_SEPARATOR,$root.'/'.$storedPath);
}}

if (!function_exists('cpms_outsourcing_file_delete_record')) {
function cpms_outsourcing_file_delete_record($pdo, $row)
{
    if (!$pdo || !is_array($row) || empty($row['id'])) return array('ok'=>false,'message'=>'삭제할 파일을 찾을 수 없습니다.');
    $storageType=isset($row['storage_type'])?trim((string)$row['storage_type']):'';
    if ($storageType==='google_drive' && !empty($row['drive_file_id'])) {
        $context=array('section'=>'construction_outsourcing','project_id'=>isset($row['project_id'])?(int)$row['project_id']:0,'original_name'=>isset($row['original_name'])?$row['original_name']:'');
        $del=cpms_drive_delete_file((string)$row['drive_file_id'],$context);
        if (empty($del['ok'])) return array('ok'=>false,'message'=>isset($del['message'])?$del['message']:'Google Drive 파일 삭제 실패');
    } else if (!empty($row['stored_path'])) {
        $path=cpms_outsourcing_file_resolve_path($row['stored_path']);
        if ($path!=='' && is_file($path)) @unlink($path);
    }
    try {
        $st=$pdo->prepare("UPDATE cpms_outsourcing_cost_files SET is_deleted=1, upload_status='deleted' WHERE id=:id");
        $st->bindValue(':id',(int)$row['id'],PDO::PARAM_INT);
        $st->execute();
        return array('ok'=>true,'message'=>'첨부파일을 삭제했습니다.');
    } catch (Exception $e) { return array('ok'=>false,'message'=>'파일 삭제정보 저장에 실패했습니다.'); }
}}
