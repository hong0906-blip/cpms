<?php
/**
 * Safety cost save action.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/safety_cost_helper.php';

use App\Core\Auth;
use App\Core\Db;

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
$redirect = '?r=safety_home&safety_pid=' . (int)$projectId . '#safety-cost-section';

if ($projectId <= 0) {
    flash_set('error', '현장/프로젝트를 선택해주세요.');
    header('Location: ?r=safety_home#safety-cost-section');
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
$itemName = trim((string)(isset($_POST['item_name']) ? $_POST['item_name'] : ''));
$useContent = trim((string)(isset($_POST['use_content']) ? $_POST['use_content'] : ''));
$amount = cpms_safety_cost_parse_amount(isset($_POST['amount']) ? $_POST['amount'] : '');
$now = date('Y-m-d H:i:s');
$userId = cpms_safety_cost_user_id();
$userName = (string)Auth::userName();
$userEmail = (string)Auth::userEmail();
$projectName = cpms_safety_cost_project_name($pdo, $projectId);

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
        $oldProjectId = isset($store['items'][$idx]['project_id']) ? (int)$store['items'][$idx]['project_id'] : 0;
        if ($oldProjectId > 0 && !cpms_safety_cost_user_can_manage_project($pdo, $oldProjectId)) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }
    } else {
        $recordId = cpms_safety_cost_new_id();
    }

    $uploadMessage = '';
    $upload = cpms_safety_cost_store_uploaded_pdf('pdf_file', $projectId, $recordId, $useDate, $uploadMessage);
    if (isset($upload['has_file']) && (int)$upload['has_file'] === 1 && empty($upload['ok'])) {
        flash_set('error', $uploadMessage !== '' ? $uploadMessage : 'PDF 업로드에 실패했습니다.');
        header('Location: ' . $redirect);
        exit;
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

    $base['id'] = $recordId;
    $base['project_id'] = $projectId;
    $base['project_name'] = $projectName;
    $base['use_date'] = $useDate;
    $base['category'] = '안전관리비';
    $base['vendor_name'] = $vendorName;
    $base['item_name'] = $itemName;
    $base['use_content'] = $useContent;
    $base['amount'] = $amount;
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
        flash_set('error', '안전관리비 사용내역 저장에 실패했습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    flash_set('success', $message);
    header('Location: ' . $redirect);
    exit;
} catch (Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}

