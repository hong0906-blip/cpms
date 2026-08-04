<?php
/**
 * C:\www\cpms\app\views\project\project_save.php
 * - 프로젝트 생성(공사 담당자 1명 + 서브담당자 여러명 저장)
 *
 * ✅ 수정사항:
 * 1) 시공사(contractor) 저장
 * 2) 계약금액(contract_amount) 저장 (예산→계약금액 변경)
 * 3) ✅ 공사 섹션 담당자 미지정 방지:
 *    - cpms_construction_roles.site_employee_id = 메인 담당자 자동 반영(있으면 업데이트, 없으면 생성)
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/GoogleDriveHelper.php';
require_once __DIR__ . '/../../services/PublicAffairsDriveService.php';
require_once __DIR__ . '/../../services/AiProjectTypeService.php';

use App\Core\Auth;
use App\Core\Db;

if (!function_exists('cpms_project_save_column_exists')) {
function cpms_project_save_column_exists($pdo, $table, $column) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', $column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_project_save_number_or_null')) {
function cpms_project_save_number_or_null($value) {
    if ($value === null || $value === '') return null;
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
    if ($clean === '' || $clean === '-' || $clean === '.' || $clean === '-.') return null;
    if (!is_numeric($clean)) return null;
    return (float)$clean;
}}

if (!function_exists('cpms_project_save_normalize_status')) {
function cpms_project_save_normalize_status($status) {
    $status = trim((string)$status);
    if ($status === '' || $status === '진행 중') return '진행중';
    if ($status === '대기중' || $status === '입찰검토' || $status === '가제' || $status === '정식전환대기') return '입찰 진행중';
    if (in_array($status, array('입찰 진행중', '계약중', '진행중', '정산완료'), true)) return $status;
    return '진행중';
}}

if (!function_exists('cpms_project_save_date_or_null')) {
function cpms_project_save_date_or_null($date) {
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;
    return null;
}}

if (!function_exists('cpms_project_save_ensure_settlement_column')) {
function cpms_project_save_ensure_settlement_column($pdo) {
    if (!$pdo) return false;
    if (cpms_project_save_column_exists($pdo, 'cpms_projects', 'settlement_completed_at')) return true;
    try {
        $pdo->exec("ALTER TABLE `cpms_projects` ADD COLUMN `settlement_completed_at` DATE NULL AFTER `status`");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE `cpms_projects` ADD COLUMN `settlement_completed_at` DATE NULL");
        } catch (Exception $e2) {
        }
    }
    return cpms_project_save_column_exists($pdo, 'cpms_projects', 'settlement_completed_at');
}}

if (!function_exists('cpms_project_save_manage_url')) {
function cpms_project_save_manage_url($projectId, $status) {
    $status = cpms_project_save_normalize_status($status);
    return '?r=%EA%B3%B5%EB%AC%B4&tab=project_manage&project_status=' . rawurlencode($status) . '&created_project_id=' . (int)$projectId;
}}

if (!function_exists('cpms_project_save_mark_drive_pending')) {
function cpms_project_save_mark_drive_pending($pdo, $projectId) {
    if (!$pdo || (int)$projectId <= 0) return;
    $setParts = array();
    if (cpms_project_save_column_exists($pdo, 'cpms_projects', 'drive_status')) {
        array_push($setParts, 'drive_status = :drive_status');
    }
    if (cpms_project_save_column_exists($pdo, 'cpms_projects', 'drive_error_message')) {
        array_push($setParts, 'drive_error_message = :drive_error_message');
    }
    if (cpms_project_save_column_exists($pdo, 'cpms_projects', 'drive_updated_at')) {
        array_push($setParts, 'drive_updated_at = :drive_updated_at');
    }
    if (count($setParts) <= 0) return;
    try {
        $st = $pdo->prepare("UPDATE cpms_projects SET " . implode(', ', $setParts) . " WHERE id = :project_id");
        if (in_array('drive_status = :drive_status', $setParts)) $st->bindValue(':drive_status', 'pending');
        if (in_array('drive_error_message = :drive_error_message', $setParts)) $st->bindValue(':drive_error_message', '');
        if (in_array('drive_updated_at = :drive_updated_at', $setParts)) $st->bindValue(':drive_updated_at', date('Y-m-d H:i:s'));
        $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
    } catch (Exception $e) {
    }
}}

if (!Auth::check()) { header('Location: ?r=login'); exit; }

// 권한: 임원 또는 공무/관리
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공무');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=공무');
    exit;
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : 'create';
if ($action !== 'create') $action = 'create';

$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$client = isset($_POST['client']) ? trim((string)$_POST['client']) : '';
$contractor = isset($_POST['contractor']) ? trim((string)$_POST['contractor']) : '';
$location = isset($_POST['location']) ? trim((string)$_POST['location']) : '';
$start_date = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
$end_date = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '진행 중';
$status = cpms_project_save_normalize_status($status);
$settlementCompletedAt = isset($_POST['settlement_completed_at']) ? trim((string)$_POST['settlement_completed_at']) : '';
$contract_amount = isset($_POST['contract_amount']) ? trim((string)$_POST['contract_amount']) : '';
$hasProjectTypeInput = array_key_exists('project_type_id', $_POST);
$projectTypeId = $hasProjectTypeInput ? (int)$_POST['project_type_id'] : 0;

$mainManagerId = isset($_POST['main_manager_id']) ? (int)$_POST['main_manager_id'] : 0;
$postedSubManagerIds = isset($_POST['sub_manager_ids']) && is_array($_POST['sub_manager_ids']) ? $_POST['sub_manager_ids'] : array();
$subManagerIds = array();
$seenSubManagerIds = array();
foreach ($postedSubManagerIds as $postedSubManagerId) {
    $subManagerId = (int)$postedSubManagerId;
    if ($subManagerId <= 0 || $subManagerId === $mainManagerId || isset($seenSubManagerIds[$subManagerId])) continue;
    $seenSubManagerIds[$subManagerId] = true;
    $subManagerIds[] = $subManagerId;
}
$unitPriceToken = isset($_POST['unit_price_token']) ? trim((string)$_POST['unit_price_token']) : '';
$projectCreateUnitPricePackForDrive = null;

if ($name === '' || $mainManagerId <= 0) {
    flash_set('error', '프로젝트명/공사 담당자는 필수입니다.');
    header('Location: ?r=공무');
    exit;
}

// 계약금액 숫자만
$contractAmountVal = null;
if ($contract_amount !== '') {
    $clean = preg_replace('/[^0-9]/', '', $contract_amount);
    if ($clean !== '') $contractAmountVal = (int)$clean;
}

// 날짜 값 검증(간단)
$startVal = ($start_date !== '') ? $start_date : null;
$endVal = ($end_date !== '') ? $end_date : null;
$hasSettlementColumn = cpms_project_save_ensure_settlement_column($pdo);
$settlementCompletedAtVal = null;
if ($hasSettlementColumn && $status === '정산완료') {
    $settlementCompletedAtVal = cpms_project_save_date_or_null($settlementCompletedAt);
    if ($settlementCompletedAtVal === null) $settlementCompletedAtVal = cpms_project_save_date_or_null($endVal);
    if ($settlementCompletedAtVal === null) $settlementCompletedAtVal = date('Y-m-d');
}
if (count($subManagerIds) > 4) {
    flash_set('error', '서브 담당자는 최대 4명까지 지정할 수 있습니다.');
    header('Location: ?r=공무');
    exit;
}

try {
    $pdo->beginTransaction();

    $insertColumns = array('name', 'client', 'contractor', 'location', 'start_date', 'end_date', 'contract_amount', 'status');
    $insertValues = array(':name', ':client', ':contractor', ':loc', ':sd', ':ed', ':ca', ':status');
    if ($hasSettlementColumn) {
        $insertColumns[] = 'settlement_completed_at';
        $insertValues[] = ':settlement_completed_at';
    }
    $sql = "INSERT INTO cpms_projects(" . implode(', ', $insertColumns) . ")
            VALUES(" . implode(', ', $insertValues) . ")";
    $st = $pdo->prepare($sql);
    $st->bindValue(':name', $name);
    $st->bindValue(':client', $client);
    $st->bindValue(':contractor', $contractor);
    $st->bindValue(':loc', $location);
    $st->bindValue(':sd', $startVal);
    $st->bindValue(':ed', $endVal);
    $st->bindValue(':ca', $contractAmountVal);
    $st->bindValue(':status', $status);
    if ($hasSettlementColumn) {
        if ($settlementCompletedAtVal === null) $st->bindValue(':settlement_completed_at', null, PDO::PARAM_NULL);
        else $st->bindValue(':settlement_completed_at', $settlementCompletedAtVal);
    }
    $st->execute();

    $projectId = (int)$pdo->lastInsertId();

    // 담당자 저장
    $stMem = $pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, :role)");

    // main 1명
    $stMem->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stMem->bindValue(':eid', $mainManagerId, PDO::PARAM_INT);
    $stMem->bindValue(':role', 'main');
    $stMem->execute();

    // sub 여러명(중복/메인과 동일이면 제외)
    $seen = array();
    $seen[$mainManagerId] = true;

    foreach ($subManagerIds as $sid) {
        $eid = (int)$sid;
        if ($eid <= 0) continue;
        if (isset($seen[$eid])) continue;
        $seen[$eid] = true;

        $stMem->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stMem->bindValue(':eid', $eid, PDO::PARAM_INT);
        $stMem->bindValue(':role', 'sub');
        $stMem->execute();
    }

 // 단가내역서(엑셀) 저장: 미리보기 토큰 기준 세션 데이터 반영
    if ($unitPriceToken !== '' && isset($_SESSION['project_create_unit_price'][$unitPriceToken])) {
        $pack = $_SESSION['project_create_unit_price'][$unitPriceToken];
        $projectCreateUnitPricePackForDrive = $pack;
        $rows = (isset($pack['rows']) && is_array($pack['rows'])) ? $pack['rows'] : array();

        try {
            $tableOk = false;
            $chk = $pdo->query("SHOW TABLES LIKE 'cpms_project_unit_prices'");
            if ($chk && $chk->fetchColumn()) $tableOk = true;

            if ($tableOk && count($rows) > 0) {
                $availableColumns = array();
                foreach (array('item_name', 'spec', 'unit', 'qty', 'unit_price', 'material_unit_price', 'labor_unit_price', 'expense_unit_price', 'amount', 'source_row', 'import_order', 'remark', 'is_safety') as $column) {
                    if (cpms_project_save_column_exists($pdo, 'cpms_project_unit_prices', $column)) {
                        $availableColumns[$column] = true;
                    }
                }

                $insertColumns = array('project_id');
                $insertHolders = array(':project_id');
                foreach (array('item_name', 'spec', 'unit', 'qty', 'material_unit_price', 'labor_unit_price', 'expense_unit_price', 'unit_price', 'amount', 'source_row', 'import_order', 'remark', 'is_safety') as $column) {
                    if (isset($availableColumns[$column])) {
                        array_push($insertColumns, '`' . $column . '`');
                        array_push($insertHolders, ':' . $column);
                    }
                }
                $stUp = $pdo->prepare("INSERT INTO cpms_project_unit_prices (" . implode(',', $insertColumns) . ") VALUES (" . implode(',', $insertHolders) . ")");

                foreach ($rows as $r) {
                    $item = isset($r['item_name']) ? trim((string)$r['item_name']) : '';
                    if ($item === '') continue;

                    $spec = isset($r['spec']) ? trim((string)$r['spec']) : '';
                    $unit = isset($r['unit']) ? trim((string)$r['unit']) : '';
                    if ($unit === '') continue;
                    $qty = cpms_project_save_number_or_null(isset($r['qty']) ? $r['qty'] : null);
                    $unitPrice = cpms_project_save_number_or_null(isset($r['unit_price']) ? $r['unit_price'] : (isset($r['total_unit_price']) ? $r['total_unit_price'] : null));
                    $materialUnitPrice = cpms_project_save_number_or_null(isset($r['material_unit_price']) ? $r['material_unit_price'] : null);
                    $laborUnitPrice = cpms_project_save_number_or_null(isset($r['labor_unit_price']) ? $r['labor_unit_price'] : null);
                    $expenseUnitPrice = cpms_project_save_number_or_null(isset($r['expense_unit_price']) ? $r['expense_unit_price'] : null);
                    $partsUnitPrice = (float)$materialUnitPrice + (float)$laborUnitPrice + (float)$expenseUnitPrice;
                    if (($unitPrice === null || abs((float)$unitPrice) < 0.0001) && abs($partsUnitPrice) > 0.0001) $unitPrice = $partsUnitPrice;
                    if ($qty === null || $unitPrice === null) continue;

                    $values = array(
                        'item_name' => $item,
                        'spec' => $spec,
                        'unit' => $unit,
                        'qty' => $qty,
                        'material_unit_price' => $materialUnitPrice,
                        'labor_unit_price' => $laborUnitPrice,
                        'expense_unit_price' => $expenseUnitPrice,
                        'unit_price' => $unitPrice,
                        'amount' => cpms_project_save_number_or_null(isset($r['amount']) ? $r['amount'] : null),
                        'source_row' => isset($r['source_row']) ? (int)$r['source_row'] : null,
                        'import_order' => isset($r['import_order']) ? (int)$r['import_order'] : null,
                        'remark' => isset($r['remark']) ? trim((string)$r['remark']) : '',
                        'is_safety' => isset($r['is_safety']) ? (int)$r['is_safety'] : 0
                    );

                    $stUp->bindValue(':project_id', $projectId, PDO::PARAM_INT);
                    foreach ($values as $column => $value) {
                        if (!isset($availableColumns[$column])) continue;
                        $stUp->bindValue(':' . $column, $value);
                    }
                    $stUp->execute();
                }
            }
        } catch (Exception $e) {
            // 단가 테이블이 없거나 실패해도 프로젝트 생성은 계속 진행
        }

    }

    /**
     * ✅ 공사 담당(현장) 자동 반영
     * - 공사 뼈대(MVP)에서 담당자 미지정으로 보이는 문제 방지
     * - cpms_construction_roles 테이블이 있을 때만 반영
     */
    try {
        $chk = $pdo->prepare("SELECT project_id FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
        $chk->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $chk->execute();
        $exists = $chk->fetchColumn() ? true : false;

        if ($exists) {
            $up = $pdo->prepare("UPDATE cpms_construction_roles SET site_employee_id = :sid WHERE project_id = :pid");
            $up->bindValue(':sid', $mainManagerId, PDO::PARAM_INT);
            $up->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $up->execute();
        } else {
            $ins = $pdo->prepare("INSERT INTO cpms_construction_roles(project_id, site_employee_id) VALUES(:pid, :sid)");
            $ins->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $ins->bindValue(':sid', $mainManagerId, PDO::PARAM_INT);
            $ins->execute();
        }
    } catch (Exception $e) {
        // 공사 테이블이 아직 없으면 무시(프로젝트 생성 자체는 성공해야 함)
    }

    if ($hasProjectTypeInput && \App\Services\AiProjectTypeService::isInstalled($pdo)) {
        $typeResult = \App\Services\AiProjectTypeService::assignProject($pdo, $projectId, $projectTypeId, '프로젝트 생성 화면에서 지정');
        if (empty($typeResult['ok'])) throw new Exception(isset($typeResult['message']) ? $typeResult['message'] : '현장유형 저장 실패');
    }
    $pdo->commit();
    if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);

    $driveSync = null;
    $driveResult = null;
    $driveSaved = false;
    $unitPriceDriveUpload = null;

    if (is_array($projectCreateUnitPricePackForDrive)) {
        $driveSync = cpms_drive_sync_project_after_create($pdo, $projectId, $name, Auth::user(), 'project_create');
        $driveResult = isset($driveSync['drive_result']) ? $driveSync['drive_result'] : null;
        $driveSaved = isset($driveSync['saved']) ? (bool)$driveSync['saved'] : false;
        $sourcePath = isset($projectCreateUnitPricePackForDrive['stored_path']) ? trim((string)$projectCreateUnitPricePackForDrive['stored_path']) : '';
        $originalUnitPriceName = isset($projectCreateUnitPricePackForDrive['file_name']) ? (string)$projectCreateUnitPricePackForDrive['file_name'] : '';
        if ($sourcePath !== '' && is_file($sourcePath)) {
            $versionsDir = cpms_storage_root() . '/contracts/' . (int)$projectId . '/versions';
            if (cpms_ensure_dir($versionsDir)) {
                $ext = strtolower(pathinfo($originalUnitPriceName, PATHINFO_EXTENSION));
                if ($ext === '') $ext = 'xlsx';
                $finalStoredName = 'unit_price_original_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
                $finalPath = rtrim($versionsDir, '/\\') . '/' . $finalStoredName;
                if (@rename($sourcePath, $finalPath)) {
                    $sourcePath = $finalPath;
                }
            }
            $unitPriceDriveUpload = cpms_public_affairs_drive_upload_local_file($pdo, $projectId, $sourcePath, $originalUnitPriceName, 'unit_price_original', ($startVal !== null ? $startVal : date('Y-m-d')), ($startVal !== null ? $startVal : date('Y-m-d')), array('date' => ($startVal !== null ? $startVal : date('Y-m-d'))), Auth::user());
            $driveRecord = (is_array($unitPriceDriveUpload) && isset($unitPriceDriveUpload['record']) && is_array($unitPriceDriveUpload['record'])) ? $unitPriceDriveUpload['record'] : array();
            $user = Auth::user();
            $userId = (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0;
            $historySave = cpms_public_affairs_drive_insert_history_record(
                $pdo,
                $projectId,
                'unit_price_original',
                $originalUnitPriceName,
                basename($sourcePath),
                $sourcePath,
                array('message' => 'project_create_unit_price', 'rows' => isset($projectCreateUnitPricePackForDrive['rows']) && is_array($projectCreateUnitPricePackForDrive['rows']) ? count($projectCreateUnitPricePackForDrive['rows']) : 0),
                $driveRecord,
                $userId
            );
            if (!empty($unitPriceDriveUpload['ok']) && empty($historySave['ok']) && isset($driveRecord['drive_file_id']) && trim((string)$driveRecord['drive_file_id']) !== '') {
                cpms_drive_delete_file((string)$driveRecord['drive_file_id'], array(
                    'section' => 'public_affairs',
                    'project_id' => $projectId,
                    'document_type' => isset($driveRecord['document_type']) ? $driveRecord['document_type'] : 'unit_price_original',
                    'original_name' => $originalUnitPriceName,
                    'target_folder_id' => isset($driveRecord['drive_folder_id']) ? $driveRecord['drive_folder_id'] : '',
                    'message' => isset($historySave['message']) ? $historySave['message'] : 'Project create unit price history save failed after Drive upload.'
                ));
                $unitPriceDriveUpload['ok'] = false;
                $unitPriceDriveUpload['message'] = isset($historySave['message']) ? $historySave['message'] : 'Project create unit price history save failed after Drive upload.';
            }
        }
    } else {
        cpms_project_save_mark_drive_pending($pdo, $projectId);
    }

    if ($unitPriceToken !== '' && isset($_SESSION['project_create_unit_price'][$unitPriceToken])) {
        unset($_SESSION['project_create_unit_price'][$unitPriceToken]);
    }

    if (!is_array($projectCreateUnitPricePackForDrive)) {
        $message = '프로젝트가 생성되었습니다.';
    } else if (is_array($driveResult) && !empty($driveResult['ok']) && $driveSaved) {
        $message = '프로젝트가 생성되었습니다. Google Drive 프로젝트 폴더도 준비되었습니다.';
    } else {
        $message = '프로젝트가 생성되었습니다. Google Drive 폴더 생성은 실패 상태로 기록했으니 관리자가 점검 후 다시 처리할 수 있습니다.';
    }
    if (is_array($unitPriceDriveUpload) && empty($unitPriceDriveUpload['ok'])) {
        $message = cpms_public_affairs_drive_flash_message($message, $unitPriceDriveUpload);
    }
    flash_set('success', $message);
    header('Location: ' . cpms_project_save_manage_url($projectId, $status));
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ?r=공무');
    exit;
}
