<?php
/**
 * Corporate card upload preview action.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/CompanyProfitAccessService.php';
require_once __DIR__ . '/../../services/CompanyOverheadService.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$user = Auth::user();
if (!cpms_can_edit_company_overhead($user, $pdo)) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=corporate_cards');
    exit;
}

$year = cpms_company_overhead_normalize_upload_year(isset($_POST['apply_year']) ? $_POST['apply_year'] : date('Y'));
$month = cpms_company_overhead_normalize_upload_month(isset($_POST['apply_month']) ? $_POST['apply_month'] : date('m'));
$file = isset($_FILES['card_file']) ? $_FILES['card_file'] : null;
$result = cpms_company_overhead_create_card_preview($year, $month, $file, $user);

if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? (string)$result['message'] : '법인카드 업로드 미리보기를 생성하지 못했습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=corporate_cards&year=' . urlencode((string)$year) . '&month=' . urlencode((string)$month));
    exit;
}

flash_set('success', '법인카드 업로드 미리보기가 생성되었습니다. 확인 후 확정 저장해주세요.');
header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=corporate_cards&year=' . urlencode((string)$result['year']) . '&month=' . urlencode((string)(int)$result['month']) . '&card_preview_token=' . urlencode($result['token']));
exit;
