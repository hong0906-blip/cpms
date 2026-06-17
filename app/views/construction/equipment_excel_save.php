<?php
/**
 * C:\www\cpms\app\views\construction\equipment_excel_save.php
 * - 장비비 엑셀 미리보기에서 선택한 행을 DB에 저장
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/equipment_gongsu_approval_helper.php';
require_once __DIR__ . '/partials/master_dedupe_helper.php';
require_once __DIR__ . '/../../services/EquipmentExcelImporter.php';
require_once __DIR__ . '/../../services/ConstructionDriveService.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.');
    header('Location: ?r=공사');
    exit;
}

function equipment_excel_save_redirect($projectId, $ym)
{
    return '?r=공사&pid=' . (int)$projectId . '&tab=equipment&equip_tab=input&ym=' . urlencode((string)$ym);
}

function equipment_excel_save_parse_money($value)
{
    $raw = trim((string)$value);
    if ($raw === '') return 0.0;
    $raw = str_replace(array(',', ' ', "\t", '원'), '', $raw);
    $raw = preg_replace('/[^0-9.\-]/', '', $raw);
    if ($raw === '' || $raw === '-' || $raw === '.' || !is_numeric($raw)) return 0.0;
    return (float)$raw;
}

function equipment_excel_save_normalize_biz_no($value)
{
    $digits = preg_replace('/[^0-9]/', '', (string)$value);
    if ($digits === '' || (int)$digits === 0) return '';
    if (strlen($digits) === 10) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 2) . '-' . substr($digits, 5);
    }
    return $digits;
}

function equipment_excel_save_ensure_log_table($pdo)
{
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_equipment_excel_import_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            base_ym CHAR(7) NOT NULL,
            original_name VARCHAR(255) DEFAULT '',
            stored_name VARCHAR(255) DEFAULT '',
            stored_path VARCHAR(500) DEFAULT '',
            total_count INT NOT NULL DEFAULT 0,
            saved_count INT NOT NULL DEFAULT 0,
            updated_count INT NOT NULL DEFAULT 0,
            skipped_count INT NOT NULL DEFAULT 0,
            error_count INT NOT NULL DEFAULT 0,
            total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            created_by_name VARCHAR(100) DEFAULT '',
            created_by_email VARCHAR(190) DEFAULT '',
            created_at DATETIME NOT NULL,
            KEY idx_project_ym (project_id, base_ym),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!cpms_construction_drive_column_exists($pdo, 'cpms_equipment_excel_import_logs', 'stored_path')) {
            try { $pdo->exec("ALTER TABLE cpms_equipment_excel_import_logs ADD COLUMN stored_path VARCHAR(500) DEFAULT '' AFTER stored_name"); } catch (Exception $e) {}
        }
        cpms_construction_drive_ensure_table_columns($pdo, 'cpms_equipment_excel_import_logs');
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function equipment_excel_save_upsert_preset($pdo, $row, $now)
{
    $vendorName = trim((string)(isset($row['vendor_name']) ? $row['vendor_name'] : ''));
    if ($vendorName === '') return;

    try {
        $stPreset = $pdo->prepare("INSERT INTO cpms_equipment_vendor_presets
            (vendor_name, category, representative, phone, biz_no, base_rate, remark, created_at, updated_at)
            VALUES
            (:vendor, :category, :rep, :phone, :biz_no, :base_rate, :remark, :now, :now)
            ON DUPLICATE KEY UPDATE
                category = VALUES(category),
                representative = VALUES(representative),
                phone = VALUES(phone),
                biz_no = VALUES(biz_no),
                base_rate = VALUES(base_rate),
                remark = VALUES(remark),
                updated_at = VALUES(updated_at)");
        $stPreset->bindValue(':vendor', $vendorName);
        $stPreset->bindValue(':category', isset($row['equipment_category']) ? (string)$row['equipment_category'] : '');
        $stPreset->bindValue(':rep', isset($row['representative']) ? (string)$row['representative'] : '');
        $stPreset->bindValue(':phone', isset($row['phone']) ? (string)$row['phone'] : '');
        $stPreset->bindValue(':biz_no', isset($row['business_no']) ? (string)$row['business_no'] : '');
        $stPreset->bindValue(':base_rate', isset($row['base_price']) ? (float)$row['base_price'] : 0);
        $stPreset->bindValue(':remark', isset($row['memo']) ? (string)$row['memo'] : '');
        $stPreset->bindValue(':now', $now);
        $stPreset->execute();
    } catch (Exception $e) {
        // 프리셋 저장 실패는 장비비 저장 자체를 막지 않는다.
    }
}

function equipment_excel_save_find_or_create_item($pdo, $importer, $projectId, $row, $now)
{
    $category = trim((string)(isset($row['equipment_category']) ? $row['equipment_category'] : ''));
    $vendorName = trim((string)(isset($row['vendor_name']) ? $row['vendor_name'] : ''));
    $spec = trim((string)(isset($row['equipment_spec']) ? $row['equipment_spec'] : ''));
    $representative = trim((string)(isset($row['representative']) ? $row['representative'] : ''));
    $phone = trim((string)(isset($row['phone']) ? $row['phone'] : ''));
    $bizNo = equipment_excel_save_normalize_biz_no(isset($row['business_no']) ? $row['business_no'] : '');
    $baseRate = isset($row['base_price']) ? (float)$row['base_price'] : 0.0;
    $remark = trim((string)(isset($row['memo']) ? $row['memo'] : ''));

    $existingItem = $importer->findExistingEquipment($projectId, $bizNo, $category, $spec, $vendorName, $baseRate);
    if (is_array($existingItem) && isset($existingItem['id'])) {
        $equipmentId = (int)$existingItem['id'];
        cpms_update_equipment_item_fill_blanks($pdo, $equipmentId, array(
            'representative' => $representative,
            'phone' => $phone,
            'biz_no' => $bizNo,
            'remark' => $remark
        ), $now);
        return $equipmentId;
    }

    $st = $pdo->prepare("INSERT INTO cpms_equipment_items
        (project_id, category, vendor_name, spec, representative, phone, biz_no, base_rate, remark, is_deleted, created_at, updated_at)
        VALUES
        (:pid, :category, :vendor, :spec, :rep, :phone, :biz_no, :base_rate, :remark, 0, :now, :now)");
    $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    $st->bindValue(':category', $category);
    $st->bindValue(':vendor', $vendorName);
    $st->bindValue(':spec', $spec);
    $st->bindValue(':rep', $representative);
    $st->bindValue(':phone', $phone);
    $st->bindValue(':biz_no', $bizNo);
    $st->bindValue(':base_rate', $baseRate);
    $st->bindValue(':remark', $remark);
    $st->bindValue(':now', $now);
    $st->execute();

    return (int)$pdo->lastInsertId();
}

function equipment_excel_save_usage_row($pdo, $projectId, $equipmentId, $row, $now)
{
    $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
    $baseRate = isset($row['base_price']) ? (float)$row['base_price'] : 0.0;
    if ($amount <= 0 || (int)$equipmentId <= 0) {
        return false;
    }

    if ($baseRate > 0) {
        $workUnit = $amount / $baseRate;
        $baseRateSnapshot = $baseRate;
    } else {
        $workUnit = 1.0;
        $baseRateSnapshot = $amount;
    }

    $memo = trim((string)(isset($row['memo']) ? $row['memo'] : ''));
    $useDate = isset($row['work_date']) ? (string)$row['work_date'] : '';

    $st = $pdo->prepare("INSERT INTO cpms_equipment_usage
        (project_id, equipment_id, use_date, work_unit, base_rate_snapshot, amount, is_manual_unit, memo, created_at)
        VALUES
        (:pid, :eid, :use_date, :work_unit, :base_rate, :amount, 0, :memo, :created_at)
        ON DUPLICATE KEY UPDATE
            work_unit = VALUES(work_unit),
            base_rate_snapshot = VALUES(base_rate_snapshot),
            amount = VALUES(amount),
            is_manual_unit = 0,
            memo = VALUES(memo)");
    $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    $st->bindValue(':eid', (int)$equipmentId, PDO::PARAM_INT);
    $st->bindValue(':use_date', $useDate);
    $st->bindValue(':work_unit', $workUnit);
    $st->bindValue(':base_rate', $baseRateSnapshot);
    $st->bindValue(':amount', $amount);
    $st->bindValue(':memo', $memo);
    $st->bindValue(':created_at', $now);
    $st->execute();

    return true;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$token = isset($_POST['equipment_excel_token']) ? trim((string)$_POST['equipment_excel_token']) : '';
$fallbackYm = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $fallbackYm)) $fallbackYm = date('Y-m');

if ($projectId <= 0 || $token === '' || !isset($_SESSION['equipment_excel_preview'][$token]) || !is_array($_SESSION['equipment_excel_preview'][$token])) {
    flash_set('error', '미리보기 데이터가 만료되었습니다. 엑셀을 다시 업로드해주세요.');
    header('Location: ' . equipment_excel_save_redirect($projectId, $fallbackYm));
    exit;
}

$preview = $_SESSION['equipment_excel_preview'][$token];
if (!isset($preview['project_id']) || (int)$preview['project_id'] !== (int)$projectId) {
    flash_set('error', '프로젝트 정보가 일치하지 않습니다.');
    header('Location: ' . equipment_excel_save_redirect($projectId, $fallbackYm));
    exit;
}

$ym = isset($preview['ym']) ? (string)$preview['ym'] : $fallbackYm;
$previewRows = isset($preview['rows']) && is_array($preview['rows']) ? $preview['rows'] : array();
$postedRows = isset($_POST['rows']) && is_array($_POST['rows']) ? $_POST['rows'] : array();

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ' . equipment_excel_save_redirect($projectId, $ym));
    exit;
}
cpms_equipment_gongsu_ensure_schema($pdo);
equipment_excel_save_ensure_log_table($pdo);

$importer = new EquipmentExcelImporter($pdo);
$savedCount = 0;
$updatedCount = 0;
$skippedCount = 0;
$errorCount = 0;
$totalAmount = 0.0;
$now = date('Y-m-d H:i:s');
$logId = 0;
$driveUploadResult = null;
$currentUser = Auth::user();
$currentUserId = (is_array($currentUser) && isset($currentUser['id'])) ? (int)$currentUser['id'] : 0;

try {
    $pdo->beginTransaction();

    foreach ($previewRows as $idx => $baseRow) {
        if (!is_array($baseRow) || !isset($baseRow['saveable']) || (int)$baseRow['saveable'] !== 1) {
            $skippedCount++;
            continue;
        }
        if (!isset($postedRows[$idx]) || !is_array($postedRows[$idx]) || !isset($postedRows[$idx]['include'])) {
            $skippedCount++;
            continue;
        }

        $postRow = $postedRows[$idx];
        $row = $baseRow;
        foreach (array('equipment_category', 'vendor_name', 'business_no', 'equipment_spec', 'representative', 'phone', 'memo') as $field) {
            if (isset($postRow[$field])) {
                $row[$field] = trim((string)$postRow[$field]);
            }
        }
        if (isset($postRow['base_price'])) {
            $row['base_price'] = equipment_excel_save_parse_money($postRow['base_price']);
        }
        if (isset($postRow['amount'])) {
            $row['amount'] = equipment_excel_save_parse_money($postRow['amount']);
        }
        $row['business_no'] = equipment_excel_save_normalize_biz_no(isset($row['business_no']) ? $row['business_no'] : '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', isset($row['work_date']) ? (string)$row['work_date'] : '')) {
            $errorCount++;
            continue;
        }
        if (trim((string)(isset($row['equipment_category']) ? $row['equipment_category'] : '')) === '') {
            $errorCount++;
            continue;
        }
        if (!isset($row['amount']) || (float)$row['amount'] <= 0) {
            $errorCount++;
            continue;
        }

        $equipmentId = equipment_excel_save_find_or_create_item($pdo, $importer, $projectId, $row, $now);
        if ($equipmentId <= 0) {
            $errorCount++;
            continue;
        }
        equipment_excel_save_upsert_preset($pdo, $row, $now);

        if (equipment_excel_save_usage_row($pdo, $projectId, $equipmentId, $row, $now)) {
            if (isset($baseRow['status_type']) && (string)$baseRow['status_type'] === 'update') {
                $updatedCount++;
            } else {
                $savedCount++;
            }
            $totalAmount += (float)$row['amount'];
        } else {
            $errorCount++;
        }
    }

    try {
        $summary = isset($preview['summary']) && is_array($preview['summary']) ? $preview['summary'] : array();
        $stLog = $pdo->prepare("INSERT INTO cpms_equipment_excel_import_logs
            (project_id, base_ym, original_name, stored_name, stored_path, total_count, saved_count, updated_count, skipped_count, error_count, total_amount, created_by_name, created_by_email, created_at)
            VALUES
            (:pid, :base_ym, :original_name, :stored_name, :stored_path, :total_count, :saved_count, :updated_count, :skipped_count, :error_count, :total_amount, :created_by_name, :created_by_email, :created_at)");
        $stLog->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $stLog->bindValue(':base_ym', $ym);
        $stLog->bindValue(':original_name', isset($preview['original_name']) ? (string)$preview['original_name'] : '');
        $stLog->bindValue(':stored_name', isset($preview['stored_name']) ? (string)$preview['stored_name'] : '');
        $stLog->bindValue(':stored_path', isset($preview['stored_path']) ? (string)$preview['stored_path'] : '');
        $stLog->bindValue(':total_count', (int)(isset($summary['total_count']) ? $summary['total_count'] : count($previewRows)), PDO::PARAM_INT);
        $stLog->bindValue(':saved_count', (int)$savedCount, PDO::PARAM_INT);
        $stLog->bindValue(':updated_count', (int)$updatedCount, PDO::PARAM_INT);
        $stLog->bindValue(':skipped_count', (int)$skippedCount, PDO::PARAM_INT);
        $stLog->bindValue(':error_count', (int)$errorCount, PDO::PARAM_INT);
        $stLog->bindValue(':total_amount', $totalAmount);
        $stLog->bindValue(':created_by_name', (string)Auth::userName());
        $stLog->bindValue(':created_by_email', (string)Auth::userEmail());
        $stLog->bindValue(':created_at', $now);
        $stLog->execute();
        $logId = (int)$pdo->lastInsertId();
    } catch (Exception $logException) {
        // 로그 실패는 저장 성공을 막지 않는다.
    }

    $pdo->commit();

    if ($logId > 0 && isset($preview['stored_path']) && trim((string)$preview['stored_path']) !== '' && is_file((string)$preview['stored_path'])) {
        $excelStoredPath = (string)$preview['stored_path'];
        $excelStoredName = isset($preview['stored_name']) ? (string)$preview['stored_name'] : basename($excelStoredPath);
        $excelOriginalName = isset($preview['original_name']) ? (string)$preview['original_name'] : $excelStoredName;
        $driveUploadResult = cpms_construction_drive_upload_local_file(
            $pdo,
            (int)$projectId,
            $excelStoredPath,
            $excelOriginalName,
            'equipment_excel',
            $ym,
            $now,
            array('date' => $ym . '-01'),
            $currentUser
        );
        if (is_array($driveUploadResult) && !empty($driveUploadResult['ok']) && isset($driveUploadResult['record']) && is_array($driveUploadResult['record'])) {
            $metaSave = cpms_construction_drive_apply_record_to_row(
                $pdo,
                'cpms_equipment_excel_import_logs',
                $logId,
                $driveUploadResult['record'],
                $currentUserId,
                array(
                    'section' => 'construction',
                    'project_id' => (int)$projectId,
                    'document_type' => 'equipment_excel',
                    'original_name' => $excelOriginalName,
                    'target_folder_id' => isset($driveUploadResult['record']['drive_folder_id']) ? (string)$driveUploadResult['record']['drive_folder_id'] : ''
                )
            );
            if (empty($metaSave['ok'])) {
                $driveUploadResult['ok'] = false;
                $driveUploadResult['message'] = isset($metaSave['message']) ? (string)$metaSave['message'] : 'Construction Drive metadata save failed.';
            }
        }
    }

    unset($_SESSION['equipment_excel_preview'][$token]);

    $successMessage = '장비비 엑셀 등록 완료: 신규 ' . (int)$savedCount . '건 / 업데이트 ' . (int)$updatedCount . '건 / 제외 ' . (int)$skippedCount . '건 / 오류 ' . (int)$errorCount . '건';
    if (is_array($driveUploadResult)) {
        $successMessage = cpms_construction_drive_flash_message($successMessage, $driveUploadResult);
    }
    flash_set('success', $successMessage);
    header('Location: ' . equipment_excel_save_redirect($projectId, $ym));
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash_set('error', '장비비 엑셀 저장 실패: ' . $e->getMessage());
    header('Location: ' . equipment_excel_save_redirect($projectId, $ym));
    exit;
}
