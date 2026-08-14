<?php
/**
 * Safety cost save action.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/safety_cost_helper.php';
require_once __DIR__ . '/../../services/CostChangeService.php';
require_once __DIR__ . '/../../services/CostDataEventService.php';
require_once __DIR__ . '/../../services/VendorService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;
use App\Services\CostDataEventService;
use App\Services\VendorService;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=safety_home');
    exit;
}

$pdo = Db::pdo();
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$recordId = isset($_POST['safety_cost_id']) ? trim((string)$_POST['safety_cost_id']) : '';
$redirect = '?r=safety_home&pid=' . (int)$projectId . '&tab=safety_cost#safety-cost-section';

if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

if ($projectId <= 0) {
    flash_set('error', '현장/프로젝트를 선택해주세요.');
    header('Location: ?r=safety_home');
    exit;
}
if (!cpms_safety_cost_user_can_manage_project($pdo, $projectId)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$useDate = cpms_safety_cost_valid_date(isset($_POST['use_date']) ? (string)$_POST['use_date'] : '');
if ($useDate === '') {
    flash_set('error', '사용일자를 올바르게 입력해주세요.');
    header('Location: ' . $redirect);
    exit;
}

$vendorName = trim((string)(isset($_POST['vendor_name']) ? $_POST['vendor_name'] : ''));
$representative = trim((string)(isset($_POST['representative']) ? $_POST['representative'] : ''));
$phone = trim((string)(isset($_POST['phone']) ? $_POST['phone'] : ''));
$bizNo = trim((string)(isset($_POST['biz_no']) ? $_POST['biz_no'] : ''));
$itemName = trim((string)(isset($_POST['item_name']) ? $_POST['item_name'] : ''));
$useContent = trim((string)(isset($_POST['use_content']) ? $_POST['use_content'] : ''));
$remark = trim((string)(isset($_POST['remark']) ? $_POST['remark'] : ''));
$category = trim((string)(isset($_POST['category']) ? $_POST['category'] : '안전관리비'));
$allowedCategories = array('안전관리비','보호구 구입비','교육비','검진비','기타 안전·보건 비용');
if (!in_array($category, $allowedCategories, true)) $category = '안전관리비';
if ($itemName === '') $itemName = $useContent;
if ($vendorName === '' || $useContent === '') {
    flash_set('error', '업체명과 품목 또는 사용내용을 입력해주세요.');
    header('Location: ' . $redirect);
    exit;
}
VendorService::bootstrap($pdo, true);
$resolvedVendorId = VendorService::selectedVendorId($pdo, isset($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : 0, $vendorName);
if ($resolvedVendorId <= 0) {
    flash_set('error', '업체명 자동검색에서 등록된 업체를 선택해주세요. 업체명을 직접 입력해서는 저장할 수 없습니다.');
    header('Location: ' . $redirect);
    exit;
}
$amountInput = isset($_POST['amount']) ? trim((string)$_POST['amount']) : '';
$amountNumberText = str_replace(array(',', ' '), '', $amountInput);
if ($amountInput === '' || !preg_match('/^\d+(\.\d+)?$/', $amountNumberText)) {
    flash_set('error', '공급가액은 숫자와 콤마만 입력해주세요.');
    header('Location: ' . $redirect);
    exit;
}
$amount = cpms_safety_cost_parse_amount($amountInput);
if ($amountInput === '' || $amount <= 0) {
    flash_set('error', '공급가액은 0원보다 큰 숫자로 입력해주세요.');
    header('Location: ' . $redirect);
    exit;
}
$now = date('Y-m-d H:i:s');
$userId = cpms_safety_cost_user_id();
$userName = (string)Auth::userName();
$userEmail = (string)Auth::userEmail();
$projectName = cpms_safety_cost_project_name($pdo, $projectId);
$uploadedStoredPath = '';
$driveUploadResult = null;
$driveUploadedRecord = null;

try {
    $store = cpms_safety_cost_read_store();
    if (!isset($store['items']) || !is_array($store['items'])) $store['items'] = array();

    $idx = -1;
    if ($recordId !== '') {
        foreach ($store['items'] as $i => $row) {
            if (is_array($row) && isset($row['id']) && (string)$row['id'] === $recordId) {
                $idx = $i;
                break;
            }
        }
        if ($idx < 0) {
            flash_set('error', '수정할 안전관리비 사용내역을 찾을 수 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }
        $oldUseDate = isset($store['items'][$idx]['use_date']) ? (string)$store['items'][$idx]['use_date'] : '';
        $oldYm = CostChangeService::effectiveSettlementYm($pdo, 'safety', $recordId, 'safety', $oldUseDate);
        $oldLock = CostChangeService::lockInfo('safety', $oldUseDate, $oldYm, date('Y-m-d'));
        if (!empty($oldLock['locked'])) {
            flash_set('error', '마감된 기간의 자료입니다. 수정하려면 비용 변경 승인이 필요합니다.');
            header('Location: ' . $redirect);
            exit;
        }
        $oldProjectId = isset($store['items'][$idx]['project_id']) ? (int)$store['items'][$idx]['project_id'] : 0;
        if ($oldProjectId > 0 && !cpms_safety_cost_user_can_manage_project($pdo, $oldProjectId)) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }
    } else {
        $recordId = cpms_safety_cost_new_id();
    }
    $destinationLock = CostChangeService::lockInfo('safety', $useDate, '', date('Y-m-d'));
    if (!empty($destinationLock['locked'])) {
        flash_set('error', '마감된 기간의 자료입니다. 추가 또는 수정하려면 비용 변경 승인이 필요합니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $uploadMessage = '';
    $upload = cpms_safety_cost_store_uploaded_pdf('pdf_file', $projectId, $recordId, $useDate, $uploadMessage);
    if (isset($upload['has_file']) && (int)$upload['has_file'] === 1 && empty($upload['ok'])) {
        flash_set('error', $uploadMessage !== '' ? $uploadMessage : 'PDF 업로드에 실패했습니다.');
        header('Location: ' . $redirect);
        exit;
    }
    if (isset($upload['ok']) && (int)$upload['ok'] === 1 && isset($upload['stored_path'])) {
        $uploadedStoredPath = (string)$upload['stored_path'];
    }

    $base = array();
    if ($idx >= 0 && isset($store['items'][$idx]) && is_array($store['items'][$idx])) {
        $base = $store['items'][$idx];
    } else {
        $base = array(
            'id' => $recordId,
            'created_at' => $now,
            'created_by' => $userId,
            'created_by_name' => $userName,
            'created_by_email' => $userEmail
        );
    }
    $eventOldRow = $idx >= 0 ? $base : array();

    $base['id'] = $recordId;
    $base['project_id'] = $projectId;
    $base['project_name'] = $projectName;
    $base['use_date'] = $useDate;
    $base['category'] = $category;
    $base['vendor_id'] = $resolvedVendorId;
    $base['vendor_name'] = $vendorName;
    $base['representative'] = $representative;
    $base['phone'] = $phone;
    $base['biz_no'] = $bizNo;
    $base['item_name'] = $itemName;
    $base['use_content'] = $useContent;
    $base['remark'] = $remark;
    $base['amount'] = $amount;
    $base['supply_amount'] = $amount;
    $base['status'] = 'active';
    $base['is_deleted'] = 0;
    $base['updated_at'] = $now;
    $base['updated_by'] = $userId;
    $base['updated_by_name'] = $userName;
    $base['updated_by_email'] = $userEmail;

    if (isset($upload['ok']) && (int)$upload['ok'] === 1) {
        $base['pdf'] = array(
            'original_name' => isset($upload['original_name']) ? (string)$upload['original_name'] : '',
            'stored_name' => isset($upload['stored_name']) ? (string)$upload['stored_name'] : '',
            'stored_path' => isset($upload['stored_path']) ? (string)$upload['stored_path'] : '',
            'file_size' => isset($upload['file_size']) ? (int)$upload['file_size'] : 0,
            'mime_type' => 'application/pdf',
            'uploaded_at' => $now,
            'uploaded_by' => $userId,
            'uploaded_by_name' => $userName
        );
        if (function_exists('cpms_safety_health_drive_upload_local_file')) {
            $localPath = cpms_safety_cost_resolve_path(isset($upload['stored_path']) ? (string)$upload['stored_path'] : '');
            $driveUploadResult = cpms_safety_health_drive_upload_local_file(
                $pdo,
                $projectId,
                $localPath,
                isset($upload['original_name']) ? (string)$upload['original_name'] : '',
                'safety_cost_pdf',
                $useDate,
                $now,
                array('date' => $useDate, 'project_name' => $projectName),
                Auth::user()
            );
            if (is_array($driveUploadResult) && isset($driveUploadResult['record']) && is_array($driveUploadResult['record'])) {
                $driveUploadedRecord = $driveUploadResult['record'];
                $base['pdf'] = array_merge($base['pdf'], cpms_safety_health_drive_record_values($driveUploadedRecord, $userId));
            }
        }
    } else if (!isset($base['pdf']) || !is_array($base['pdf'])) {
        $base['pdf'] = array();
    }

    if ($idx >= 0) {
        $store['items'][$idx] = $base;
        $message = '안전관리비 사용내역을 수정했습니다.';
    } else {
        $store['items'][count($store['items'])] = $base;
        $message = '안전관리비 사용내역을 등록했습니다.';
    }

    if (!cpms_safety_cost_write_store($store)) {
        if ($uploadedStoredPath !== '') {
            $uploadedFile = cpms_safety_cost_resolve_path($uploadedStoredPath);
            if ($uploadedFile !== '') @unlink($uploadedFile);
        }
        if (is_array($driveUploadedRecord) && function_exists('cpms_safety_health_drive_delete_uploaded_record')) {
            cpms_safety_health_drive_delete_uploaded_record($driveUploadedRecord, array(
                'section' => 'safety_health',
                'project_id' => $projectId,
                'is_common_file' => '0',
                'original_name' => isset($driveUploadedRecord['original_name']) ? (string)$driveUploadedRecord['original_name'] : '',
                'message' => 'Safety cost metadata save failed after Drive upload.'
            ));
        }
        flash_set('error', '안전관리비 사용내역 저장에 실패했습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    CostDataEventService::recordChange($pdo, array(
        'project_id' => $projectId,
        'project_name_snapshot' => $projectName,
        'cost_type' => $category,
        'target_type' => 'safety_cost',
        'target_id' => (string)$recordId,
        'event_action' => $idx >= 0 ? 'UPDATE' : 'CREATE',
        'source_type' => 'DIRECT',
        'actual_date' => $useDate,
        'settlement_ym' => CostChangeService::settlementYm('safety', $useDate),
        'old_amount' => $idx >= 0 && isset($eventOldRow['amount']) ? $eventOldRow['amount'] : null,
        'new_amount' => $amount,
        'old_data' => $eventOldRow,
        'new_data' => $base,
        'reason' => $remark,
        'source_file' => __FILE__,
    ));

    if (is_array($driveUploadResult) && empty($driveUploadResult['ok'])) {
        $message = cpms_safety_health_drive_flash_message($message, $driveUploadResult);
    }
    flash_set('success', $message);
    header('Location: ' . $redirect);
    exit;
} catch (Exception $e) {
    if ($uploadedStoredPath !== '') {
        $uploadedFile = cpms_safety_cost_resolve_path($uploadedStoredPath);
        if ($uploadedFile !== '') @unlink($uploadedFile);
    }
    if (is_array($driveUploadedRecord) && function_exists('cpms_safety_health_drive_delete_uploaded_record')) {
        cpms_safety_health_drive_delete_uploaded_record($driveUploadedRecord, array(
            'section' => 'safety_health',
            'project_id' => $projectId,
            'is_common_file' => '0',
            'original_name' => isset($driveUploadedRecord['original_name']) ? (string)$driveUploadedRecord['original_name'] : '',
            'message' => 'Safety cost save exception after Drive upload: ' . $e->getMessage()
        ));
    }
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
