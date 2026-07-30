<?php
/** 공사 > 외주비 입력 저장/수정/삭제 - PHP 5.6 호환 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/tabs/partials/outsourcing_data_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) { flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=공사'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$month = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'save';
$entryId = isset($_POST['entry_id']) ? (int)$_POST['entry_id'] : 0;
$redirect = '?r=공사&pid=' . $projectId . '&tab=outsourcing&outsourcing_tab=input';
if (preg_match('/^\d{4}-\d{2}$/', $month)) $redirect .= '&month=' . urlencode($month);

$pdo = Db::pdo();
if (!$pdo || $projectId <= 0 || !cpms_outsourcing_cost_ensure_table($pdo)) {
    flash_set('error', '외주비 테이블 또는 프로젝트 정보를 확인할 수 없습니다.');
    header('Location: ' . $redirect); exit;
}

try {
    $now = date('Y-m-d H:i:s');
    if ($action === 'delete') {
        if ($entryId <= 0) throw new Exception('삭제할 외주비 내역이 없습니다.');
        $st = $pdo->prepare("UPDATE cpms_outsourcing_costs SET is_deleted = 1, updated_at = :now WHERE id = :id AND project_id = :pid");
        $st->bindValue(':now', $now);
        $st->bindValue(':id', $entryId, PDO::PARAM_INT);
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->execute();
        flash_set('success', '외주비 입력 내역을 삭제했습니다.');
        header('Location: ' . $redirect); exit;
    }

    $expenseDate = cpms_outsourcing_valid_date(isset($_POST['expense_date']) ? $_POST['expense_date'] : '');
    $companyName = isset($_POST['company_name']) ? trim((string)$_POST['company_name']) : '';
    $representativeName = isset($_POST['representative_name']) ? trim((string)$_POST['representative_name']) : '';
    $businessNo = isset($_POST['business_no']) ? trim((string)$_POST['business_no']) : '';
    $contact = isset($_POST['contact']) ? trim((string)$_POST['contact']) : '';
    $amount = cpms_outsourcing_money(isset($_POST['amount']) ? $_POST['amount'] : '');
    $memo = isset($_POST['memo']) ? trim((string)$_POST['memo']) : '';
    if (function_exists('mb_substr')) $memo = mb_substr($memo, 0, 500, 'UTF-8');
    else $memo = substr($memo, 0, 500);
    if ($expenseDate === '') throw new Exception('일자를 올바르게 입력해주세요.');
    if ($companyName === '') throw new Exception('업체명을 입력해주세요.');
    if ($amount <= 0) throw new Exception('금액은 0보다 크게 입력해주세요.');

    if ($entryId > 0) {
        $sql = "UPDATE cpms_outsourcing_costs
                SET expense_date = :expense_date, category = '외주비', company_name = :company_name,
                    representative_name = :representative_name, business_no = :business_no,
                    contact = :contact, amount = :amount, memo = :memo, updated_at = :now
                WHERE id = :id AND project_id = :pid AND is_deleted = 0";
        $st = $pdo->prepare($sql);
        $st->bindValue(':id', $entryId, PDO::PARAM_INT);
    } else {
        $sql = "INSERT INTO cpms_outsourcing_costs
                (project_id, expense_date, category, company_name, representative_name, business_no, contact, amount, memo,
                 created_by_name, created_by_email, is_deleted, created_at, updated_at)
                VALUES (:pid, :expense_date, '외주비', :company_name, :representative_name, :business_no, :contact, :amount, :memo,
                 :created_by_name, :created_by_email, 0, :now, :now)";
        $st = $pdo->prepare($sql);
        $st->bindValue(':created_by_name', (string)Auth::userName());
        $st->bindValue(':created_by_email', (string)Auth::userEmail());
    }
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':expense_date', $expenseDate);
    $st->bindValue(':company_name', $companyName);
    $st->bindValue(':representative_name', $representativeName === '' ? null : $representativeName, $representativeName === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':business_no', $businessNo === '' ? null : $businessNo, $businessNo === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':contact', $contact === '' ? null : $contact, $contact === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':amount', number_format($amount, 2, '.', ''));
    $st->bindValue(':memo', $memo === '' ? null : $memo, $memo === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':now', $now);
    $st->execute();
    $savedEntryId = $entryId > 0 ? $entryId : (int)$pdo->lastInsertId();
    $uploadResult = cpms_outsourcing_file_store_uploads($pdo, 'attachments', $projectId, $savedEntryId, substr($expenseDate, 0, 7));
    $successMessage = $entryId > 0 ? '외주비 입력 내역을 수정했습니다.' : '외주비를 등록했습니다.';
    if (isset($uploadResult['has_file']) && $uploadResult['has_file']) {
        if (isset($uploadResult['ok']) && $uploadResult['ok']) {
            $successMessage .= ' ' . (isset($uploadResult['message']) ? $uploadResult['message'] : '');
        } else {
            flash_set('error', $successMessage . ' 다만 파일 첨부 실패: ' . (isset($uploadResult['message']) ? $uploadResult['message'] : '알 수 없는 오류'));
            header('Location: ' . $redirect);
            exit;
        }
    }
    flash_set('success', trim($successMessage));
} catch (Exception $e) {
    flash_set('error', '외주비 저장 실패: ' . $e->getMessage());
}
header('Location: ' . $redirect);
exit;
