<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/unit_price_parser.php';
require_once __DIR__ . '/contract_change_helper.php';

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
    return cpms_contract_change_row_key($row);
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
    return (int)$pdo->lastInsertId();
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

if (!function_exists('cpms_contract_upload_store_change_logs')) {
function cpms_contract_upload_store_change_logs($pdo, $projectId, $contractItemId, $oldRow, $newRow, $badges) {
    if (!$pdo || !is_array($badges) || count($badges) === 0) return;
    if (!cpms_contract_change_table_exists($pdo, 'cpms_contract_change_logs')) return;

    try {
        $st = $pdo->prepare("INSERT INTO cpms_contract_change_logs
            (project_id, contract_item_id, change_type, item_name, spec, unit, old_quantity, new_quantity, old_unit_price, new_unit_price, created_by, created_at)
            VALUES
            (:project_id, :contract_item_id, :change_type, :item_name, :spec, :unit, :old_quantity, :new_quantity, :old_unit_price, :new_unit_price, :created_by, :created_at)");
        foreach ($badges as $badge) {
            if (!is_array($badge)) continue;
            $type = isset($badge['type']) ? (string)$badge['type'] : '';
            if ($type === '') continue;
            $rowForText = is_array($newRow) ? $newRow : (is_array($oldRow) ? $oldRow : array());
            $oldQty = is_array($oldRow) && isset($oldRow['qty']) ? $oldRow['qty'] : null;
            $newQty = is_array($newRow) && isset($newRow['qty']) ? $newRow['qty'] : null;
            $oldUnitPrice = is_array($oldRow) ? cpms_contract_change_unit_price_value($oldRow) : null;
            $newUnitPrice = is_array($newRow) ? cpms_contract_change_unit_price_value($newRow) : null;
            $createdBy = cpms_contract_upload_current_user_id();

            $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':contract_item_id', (int)$contractItemId > 0 ? (int)$contractItemId : null, (int)$contractItemId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(':change_type', $type);
            $st->bindValue(':item_name', isset($rowForText['item_name']) ? (string)$rowForText['item_name'] : null);
            $st->bindValue(':spec', isset($rowForText['spec']) ? (string)$rowForText['spec'] : null);
            $st->bindValue(':unit', isset($rowForText['unit']) ? (string)$rowForText['unit'] : null);
            $st->bindValue(':old_quantity', $oldQty !== null ? $oldQty : null, $oldQty !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':new_quantity', $newQty !== null ? $newQty : null, $newQty !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':old_unit_price', $oldUnitPrice !== null ? $oldUnitPrice : null, $oldUnitPrice !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':new_unit_price', $newUnitPrice !== null ? $newUnitPrice : null, $newUnitPrice !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':created_by', $createdBy > 0 ? $createdBy : null, $createdBy > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(':created_at', date('Y-m-d H:i:s'));
            $st->execute();
        }
    } catch (Exception $e) {
        error_log('[contract_upload] change log insert failed: ' . $e->getMessage());
    }
}
}

if (!function_exists('cpms_contract_upload_apply_unit_price_update')) {
function cpms_contract_upload_apply_unit_price_update($pdo, $projectId, $rows) {
    $summary = array(
        'updated' => 0,
        'inserted' => 0,
        'deactivated' => 0,
        'kept' => 0,
        'changed' => 0,
        'unit_price_changed' => 0,
        'quantity_increased' => 0,
        'quantity_decreased' => 0
    );

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
    foreach ($activeRows as $index => $row) {
        $key = cpms_contract_upload_row_key($row);
        if ($key === '||') continue;
        if (!isset($exactMap[$key])) $exactMap[$key] = array();
        array_push($exactMap[$key], $index);
    }

    $usedIndexes = array();

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

        $data = cpms_contract_upload_build_data($row, $availableColumns);

        if ($matchIndex >= 0 && isset($activeRows[$matchIndex])) {
            $usedIndexes[$matchIndex] = 1;
            $oldRow = $activeRows[$matchIndex];
            $badges = array();
            $oldUnitPrice = cpms_contract_change_unit_price_value($oldRow);
            $newUnitPrice = cpms_contract_change_unit_price_value($row);
            if (!cpms_contract_change_number_same($oldUnitPrice, $newUnitPrice)) {
                $summary['unit_price_changed']++;
                array_push($badges, cpms_contract_change_badge('UNIT_PRICE_CHANGED', '단가 변경', $oldUnitPrice, $newUnitPrice));
            }
            $oldQty = isset($oldRow['qty']) ? $oldRow['qty'] : null;
            $newQty = isset($row['qty']) ? $row['qty'] : null;
            if (!cpms_contract_change_number_same($oldQty, $newQty)) {
                $oldQtyNum = cpms_contract_change_number($oldQty);
                $newQtyNum = cpms_contract_change_number($newQty);
                if ($newQtyNum > $oldQtyNum) {
                    $summary['quantity_increased']++;
                    array_push($badges, cpms_contract_change_badge('QUANTITY_INCREASED', '수량 증가', $oldQty, $newQty));
                } else {
                    $summary['quantity_decreased']++;
                    array_push($badges, cpms_contract_change_badge('QUANTITY_DECREASED', '수량 감소', $oldQty, $newQty));
                }
            }
            cpms_contract_upload_update_planned_qty($pdo, (int)$activeRows[$matchIndex]['id'], isset($activeRows[$matchIndex]['qty']) ? $activeRows[$matchIndex]['qty'] : null, isset($data['qty']) ? $data['qty'] : null);
            cpms_contract_upload_update_row($pdo, $projectId, (int)$activeRows[$matchIndex]['id'], $data);
            cpms_contract_upload_store_change_logs($pdo, $projectId, (int)$activeRows[$matchIndex]['id'], $oldRow, $row, $badges);
            $summary['updated']++;
            if (count($badges) > 0) $summary['changed']++;
            else $summary['kept']++;
        } else {
            $newId = cpms_contract_upload_insert_row($pdo, $projectId, $data);
            cpms_contract_upload_store_change_logs($pdo, $projectId, $newId, null, $row, array(cpms_contract_change_badge('ADDED', '추가항목', null, null)));
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
            cpms_contract_upload_store_change_logs($pdo, $projectId, (int)$row['id'], $row, null, array(cpms_contract_change_badge('DELETED_SUSPECTED', '삭제 의심', null, null)));
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

$previewToken = isset($_POST['preview_token']) ? trim((string)$_POST['preview_token']) : '';
if ($uploadMode === 'unit_price_update' && $previewToken !== '') {
    if (!isset($_SESSION['unit_price_update'][$previewToken]) || !is_array($_SESSION['unit_price_update'][$previewToken])) {
        cpms_contract_upload_redirect($projectId, 'error', '미리보기 데이터가 만료되었습니다.');
    }
    $pack = $_SESSION['unit_price_update'][$previewToken];
    $packProjectId = isset($pack['project_id']) ? (int)$pack['project_id'] : 0;
    if ($packProjectId !== $projectId) {
        cpms_contract_upload_redirect($projectId, 'error', '미리보기 프로젝트 정보가 일치하지 않습니다.');
    }
    $rows = isset($pack['rows']) && is_array($pack['rows']) ? $pack['rows'] : array();
    if (count($rows) === 0) {
        cpms_contract_upload_redirect($projectId, 'error', '적용할 단가내역 데이터가 없습니다.');
    }
    $storedPath = isset($pack['stored_path']) ? (string)$pack['stored_path'] : '';
    $storedName = isset($pack['stored_name']) ? (string)$pack['stored_name'] : basename($storedPath);
    $originalName = isset($pack['file_name']) ? (string)$pack['file_name'] : $storedName;

    try {
        $pdo->beginTransaction();
        $summary = cpms_contract_upload_apply_unit_price_update($pdo, $projectId, $rows);
        $pdo->commit();
        cpms_contract_upload_store_history($pdo, $projectId, $uploadMode, $originalName, $storedName, $storedPath, $summary);
        unset($_SESSION['unit_price_update'][$previewToken]);
        cpms_contract_upload_redirect(
            $projectId,
            'success',
            '변경 단가내역서가 적용되었습니다. 변경 ' . (int)$summary['updated'] . '건 / 신규 ' . (int)$summary['inserted'] . '건 / 제외 ' . (int)$summary['deactivated'] . '건'
        );
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cpms_contract_upload_redirect($projectId, 'error', '변경 단가내역서 적용 실패: ' . $e->getMessage());
    }
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
