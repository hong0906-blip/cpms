<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/unit_price_parser.php';

if (!function_exists('cpms_contract_upload_redirect')) {
function cpms_contract_upload_redirect($projectId, $type, $message) {
    flash_set($type, $message);
    if ((int)$projectId > 0) {
        header('Location: ?r=project/detail&id=' . (int)$projectId);
    } else {
        header('Location: ?r=공무');
    }
    exit;
}
}

if (!function_exists('cpms_contract_upload_column_exists')) {
function cpms_contract_upload_column_exists($pdo, $table, $column) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', $column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}
}

if (!function_exists('cpms_contract_upload_current_user_id')) {
function cpms_contract_upload_current_user_id() {
    $user = Auth::user();
    if (is_array($user) && isset($user['id'])) return (int)$user['id'];
    return 0;
}
}

if (!function_exists('cpms_contract_upload_row_key')) {
function cpms_contract_upload_row_key($row) {
    $item = isset($row['item_name']) ? trim((string)$row['item_name']) : '';
    $spec = isset($row['spec']) ? trim((string)$row['spec']) : '';
    $unit = isset($row['unit']) ? trim((string)$row['unit']) : '';
    return $item . '|' . $spec . '|' . $unit;
}
}

if (!function_exists('cpms_contract_upload_store_history')) {
function cpms_contract_upload_store_history($pdo, $projectId, $uploadMode, $originalName, $storedName, $storedPath, $summary) {
    if (!cpms_contract_upload_column_exists($pdo, 'cpms_project_contract_change_files', 'project_id')) return;

    $columns = array();
    $holders = array();
    $values = array();

    $map = array(
        'project_id' => (int)$projectId,
        'original_name' => (string)$originalName,
        'stored_name' => (string)$storedName,
        'stored_path' => (string)$storedPath,
        'file_type' => ($uploadMode === 'unit_price_update' ? 'unit_price_update' : 'contract_only'),
        'uploaded_by' => cpms_contract_upload_current_user_id(),
        'uploaded_at' => date('Y-m-d H:i:s'),
        'applied_token' => '',
        'change_summary' => json_encode($summary, JSON_UNESCAPED_UNICODE)
    );

    foreach ($map as $column => $value) {
        if (!cpms_contract_upload_column_exists($pdo, 'cpms_project_contract_change_files', $column)) continue;
        array_push($columns, '`' . $column . '`');
        array_push($holders, ':' . $column);
        $values[':' . $column] = $value;
    }

    if (count($columns) === 0) return;

    try {
        $sql = "INSERT INTO cpms_project_contract_change_files (" . implode(',', $columns) . ") VALUES (" . implode(',', $holders) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($values);
    } catch (Exception $e) {
    }
}
}

if (!function_exists('cpms_contract_upload_build_data')) {
function cpms_contract_upload_build_data($row, $columns) {
    $source = array(
        'item_name' => isset($row['item_name']) ? trim((string)$row['item_name']) : '',
        'spec' => isset($row['spec']) ? trim((string)$row['spec']) : '',
        'unit' => isset($row['unit']) ? trim((string)$row['unit']) : '',
        'qty' => isset($row['qty']) ? $row['qty'] : null,
        'unit_price' => isset($row['unit_price']) ? $row['unit_price'] : null,
        'labor_unit_price' => isset($row['labor_unit_price']) ? $row['labor_unit_price'] : null,
        'material_unit_price' => isset($row['material_unit_price']) ? $row['material_unit_price'] : null,
        'expense_unit_price' => isset($row['expense_unit_price']) ? $row['expense_unit_price'] : null,
        'amount' => isset($row['amount']) ? $row['amount'] : null,
        'source_row' => isset($row['source_row']) ? (int)$row['source_row'] : null,
        'import_order' => isset($row['import_order']) ? (int)$row['import_order'] : null,
        'is_safety' => isset($row['is_safety']) ? (int)$row['is_safety'] : 0,
        'remark' => isset($row['remark']) ? trim((string)$row['remark']) : ''
    );
    $partsTotal = 0.0;
    foreach (array('material_unit_price', 'labor_unit_price', 'expense_unit_price') as $partColumn) {
        if (isset($source[$partColumn]) && is_numeric((string)$source[$partColumn])) {
            $partsTotal += (float)$source[$partColumn];
        }
    }
    if ((!isset($source['unit_price']) || $source['unit_price'] === null || $source['unit_price'] === '' || (is_numeric((string)$source['unit_price']) && abs((float)$source['unit_price']) < 0.0001)) && abs($partsTotal) > 0.0001) {
        $source['unit_price'] = $partsTotal;
    }
    $data = array();
    foreach ($source as $column => $value) {
        if (isset($columns[$column])) $data[$column] = $value;
    }
    if (isset($columns['is_active'])) $data['is_active'] = 1;
    if (isset($columns['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');
    return $data;
}
}

if (!function_exists('cpms_contract_upload_update_row')) {
function cpms_contract_upload_update_row($pdo, $projectId, $rowId, $data) {
    $sets = array();
    $params = array(':id' => (int)$rowId, ':project_id' => (int)$projectId);
    foreach ($data as $column => $value) {
        array_push($sets, '`' . $column . '` = :' . $column);
        $params[':' . $column] = $value;
    }
    $sql = "UPDATE cpms_project_unit_prices SET " . implode(', ', $sets) . " WHERE id = :id AND project_id = :project_id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
}
}

if (!function_exists('cpms_contract_upload_insert_row')) {
function cpms_contract_upload_insert_row($pdo, $projectId, $data) {
    $columns = array('project_id');
    $holders = array(':project_id');
    $params = array(':project_id' => (int)$projectId);
    foreach ($data as $column => $value) {
        array_push($columns, '`' . $column . '`');
        array_push($holders, ':' . $column);
        $params[':' . $column] = $value;
    }
    $sql = "INSERT INTO cpms_project_unit_prices (" . implode(',', $columns) . ") VALUES (" . implode(',', $holders) . ")";
    $st = $pdo->prepare($sql);
    $st->execute($params);
}
}

if (!function_exists('cpms_contract_upload_update_planned_qty')) {
function cpms_contract_upload_update_planned_qty($pdo, $unitPriceId, $oldQty, $newQty) {
    if (!is_numeric((string)$newQty)) return;
    $params = array(':uid' => (int)$unitPriceId, ':new_qty' => (float)$newQty);
    $where = "unit_price_id = :uid AND planned_qty IS NULL";
    if (is_numeric((string)$oldQty)) {
        $where = "unit_price_id = :uid AND (planned_qty IS NULL OR ABS(planned_qty - :old_qty) < 0.0001)";
        $params[':old_qty'] = (float)$oldQty;
    }
    $sql = "UPDATE cpms_work_item_lines SET planned_qty = :new_qty WHERE " . $where;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
    } catch (Exception $e) {
        error_log('[contract_upload] planned_qty update failed: ' . $e->getMessage());
    }
}
}

if (!function_exists('cpms_contract_upload_apply_unit_price_update')) {
function cpms_contract_upload_apply_unit_price_update($pdo, $projectId, $rows) {
    $summary = array('updated' => 0, 'inserted' => 0, 'deactivated' => 0);

    $requiredColumns = array('item_name', 'spec', 'unit', 'qty', 'unit_price');
    $availableColumns = array();
    foreach (array('item_name', 'spec', 'unit', 'qty', 'unit_price', 'labor_unit_price', 'material_unit_price', 'expense_unit_price', 'amount', 'source_row', 'import_order', 'is_safety', 'remark', 'is_active', 'updated_at') as $column) {
        if (cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', $column)) {
            $availableColumns[$column] = true;
        }
    }
    foreach ($requiredColumns as $column) {
        if (!isset($availableColumns[$column])) {
            throw new Exception('cpms_project_unit_prices.' . $column . ' 컬럼이 없습니다.');
        }
    }

    $stOld = $pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id = :pid ORDER BY id ASC");
    $stOld->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stOld->execute();
    $existingRows = $stOld->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($existingRows)) $existingRows = array();

    $activeRows = array();
    foreach ($existingRows as $row) {
        if (isset($availableColumns['is_active']) && isset($row['is_active']) && (int)$row['is_active'] === 0) continue;
        array_push($activeRows, $row);
    }

    $exactMap = array();
    $activeIndexes = array();
    foreach ($activeRows as $index => $row) {
        $key = cpms_contract_upload_row_key($row);
        if (!isset($exactMap[$key])) $exactMap[$key] = array();
        array_push($exactMap[$key], $index);
        array_push($activeIndexes, $index);
    }

    $usedIndexes = array();
    $sequencePointer = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $key = cpms_contract_upload_row_key($row);
        if ($key === '||') continue;

        $matchIndex = -1;
        if (isset($exactMap[$key])) {
            foreach ($exactMap[$key] as $candidate) {
                if (!isset($usedIndexes[$candidate])) {
                    $matchIndex = (int)$candidate;
                    break;
                }
            }
        }

        if ($matchIndex < 0) {
            while ($sequencePointer < count($activeIndexes) && isset($usedIndexes[$activeIndexes[$sequencePointer]])) {
                $sequencePointer++;
            }
            if ($sequencePointer < count($activeIndexes)) {
                $matchIndex = (int)$activeIndexes[$sequencePointer];
                $sequencePointer++;
            }
        }

        $data = cpms_contract_upload_build_data($row, $availableColumns);

        if ($matchIndex >= 0 && isset($activeRows[$matchIndex])) {
            $usedIndexes[$matchIndex] = 1;
            cpms_contract_upload_update_planned_qty($pdo, (int)$activeRows[$matchIndex]['id'], isset($activeRows[$matchIndex]['qty']) ? $activeRows[$matchIndex]['qty'] : null, isset($data['qty']) ? $data['qty'] : null);
            cpms_contract_upload_update_row($pdo, $projectId, (int)$activeRows[$matchIndex]['id'], $data);
            $summary['updated']++;
        } else {
            cpms_contract_upload_insert_row($pdo, $projectId, $data);
            $summary['inserted']++;
        }
    }

    if (isset($availableColumns['is_active'])) {
        $stDeactivate = $pdo->prepare("UPDATE cpms_project_unit_prices SET is_active = 0" . (isset($availableColumns['updated_at']) ? ", updated_at = :updated_at" : "") . " WHERE id = :id AND project_id = :pid");
        foreach ($activeRows as $index => $row) {
            if (isset($usedIndexes[$index])) continue;
            if (isset($availableColumns['updated_at'])) $stDeactivate->bindValue(':updated_at', date('Y-m-d H:i:s'));
            $stDeactivate->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
            $stDeactivate->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stDeactivate->execute();
            $summary['deactivated']++;
        }
    }

    return $summary;
}
}

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($csrf)) {
    cpms_contract_upload_redirect(0, 'error', '보안 토큰이 유효하지 않습니다.');
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$uploadMode = isset($_POST['upload_mode']) ? trim((string)$_POST['upload_mode']) : 'contract_only';
if ($uploadMode !== 'unit_price_update') $uploadMode = 'contract_only';

if ($projectId <= 0) {
    cpms_contract_upload_redirect(0, 'error', '잘못된 프로젝트 ID입니다.');
}

$pdo = Db::pdo();
if (!$pdo) {
    cpms_contract_upload_redirect($projectId, 'error', 'DB 연결에 실패했습니다.');
}

try {
    $stProject = $pdo->prepare("SELECT id FROM cpms_projects WHERE id = :id LIMIT 1");
    $stProject->bindValue(':id', $projectId, PDO::PARAM_INT);
    $stProject->execute();
    if (!$stProject->fetch()) {
        cpms_contract_upload_redirect(0, 'error', '프로젝트를 찾을 수 없습니다.');
    }
} catch (Exception $e) {
    cpms_contract_upload_redirect(0, 'error', '프로젝트 확인에 실패했습니다.');
}

if (!isset($_FILES['contract_file']) || !is_array($_FILES['contract_file'])) {
    cpms_contract_upload_redirect($projectId, 'error', '업로드할 파일이 없습니다.');
}

$file = $_FILES['contract_file'];
$errorCode = isset($file['error']) ? (int)$file['error'] : 999;
$tmpFile = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
$originalName = isset($file['name']) ? (string)$file['name'] : '';
$size = isset($file['size']) ? (int)$file['size'] : 0;

if ($errorCode !== UPLOAD_ERR_OK || $tmpFile === '' || !is_uploaded_file($tmpFile)) {
    cpms_contract_upload_redirect($projectId, 'error', '파일 업로드에 실패했습니다.');
}

$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedContractExt = array('pdf', 'hwp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png');
if ($uploadMode === 'unit_price_update') {
    if ($ext !== 'xlsx') {
        cpms_contract_upload_redirect($projectId, 'error', '변경 단가내역서는 xlsx 파일만 업로드할 수 있습니다.');
    }
} else {
    if ($ext === '' || !in_array($ext, $allowedContractExt, true)) {
        cpms_contract_upload_redirect($projectId, 'error', '허용되지 않는 파일 형식입니다.');
    }
}

$maxBytes = 30 * 1024 * 1024;
if ($size <= 0 || $size > $maxBytes) {
    cpms_contract_upload_redirect($projectId, 'error', '파일 용량이 올바르지 않습니다. (최대 30MB)');
}

$cpmsRoot = dirname(dirname(dirname(__DIR__)));
$baseDir = $cpmsRoot . '/storage/contracts/' . $projectId;
$targetDir = ($uploadMode === 'unit_price_update') ? ($baseDir . '/changes') : $baseDir;
if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);
if (!is_dir($targetDir)) {
    cpms_contract_upload_redirect($projectId, 'error', '업로드 폴더를 생성할 수 없습니다.');
}

$random = bin2hex(openssl_random_pseudo_bytes(8));
$prefix = ($uploadMode === 'unit_price_update') ? 'unit_price_update_' : 'contract_';
$storedName = $prefix . date('Ymd_His') . '_' . $random . '.' . $ext;
$storedPath = $targetDir . '/' . $storedName;

if (!@move_uploaded_file($tmpFile, $storedPath)) {
    cpms_contract_upload_redirect($projectId, 'error', '파일 저장에 실패했습니다.');
}

if ($uploadMode === 'contract_only') {
    $metaFile = $baseDir . '/meta.json';
    if (is_file($metaFile)) {
        $oldJson = @file_get_contents($metaFile);
        $oldMeta = @json_decode($oldJson, true);
        if (is_array($oldMeta) && isset($oldMeta['stored_name'])) {
            $oldStored = basename((string)$oldMeta['stored_name']);
            $oldPath = $baseDir . '/' . $oldStored;
            if (is_file($oldPath)) @unlink($oldPath);
        }
        @unlink($metaFile);
    }

    if ($targetDir !== $baseDir) {
        $storedPath = $baseDir . '/' . $storedName;
        @rename($targetDir . '/' . $storedName, $storedPath);
    }

    $meta = array(
        'project_id' => $projectId,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => Auth::userEmail()
    );
    @file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    cpms_contract_upload_store_history($pdo, $projectId, $uploadMode, $originalName, $storedName, $storedPath, array('message' => 'stored'));
    cpms_contract_upload_redirect($projectId, 'success', '계약서 파일이 업로드되었습니다.');
}

try {
    $parsed = cpms_project_parse_unit_price_xlsx($pdo, $storedPath);
    if (!is_array($parsed) || empty($parsed['ok'])) {
        throw new Exception(isset($parsed['message']) ? $parsed['message'] : '엑셀 파싱에 실패했습니다.');
    }

    $rows = isset($parsed['rows']) && is_array($parsed['rows']) ? $parsed['rows'] : array();
    if (count($rows) === 0) {
        throw new Exception('적용할 단가내역 데이터가 없습니다.');
    }

    $pdo->beginTransaction();
    $summary = cpms_contract_upload_apply_unit_price_update($pdo, $projectId, $rows);
    $pdo->commit();

    cpms_contract_upload_store_history($pdo, $projectId, $uploadMode, $originalName, $storedName, $storedPath, $summary);

    cpms_contract_upload_redirect(
        $projectId,
        'success',
        '변경 단가내역서가 적용되었습니다. 변경 ' . (int)$summary['updated'] . '건 / 신규 ' . (int)$summary['inserted'] . '건 / 제외 ' . (int)$summary['deactivated'] . '건'
    );
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cpms_contract_upload_redirect($projectId, 'error', '변경 단가내역서 적용 실패: ' . $e->getMessage());
}
