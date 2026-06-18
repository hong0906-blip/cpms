<?php
/**
 * Company overhead save action.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

csrf_validate();

$category = isset($_POST['category']) ? trim((string)$_POST['category']) : '';
$id = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
$file = (isset($_FILES['attachment']) && is_array($_FILES['attachment'])) ? $_FILES['attachment'] : null;

if ($category === 'payroll') {
    flash_set('danger', '임직원 월급은 급여대장 업로드/확정 방식으로만 저장할 수 있습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll');
    exit;
}

if ($id !== '') {
    $result = cpms_company_overhead_update($category, $id, $_POST, $file, $user);
} else {
    $result = cpms_company_overhead_add($category, $_POST, $file, $user);
}
if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);

if (!empty($result['ok'])) {
    $type = 'success';
    $message = isset($result['message']) ? (string)$result['message'] : '저장되었습니다.';
} else {
    $type = 'danger';
    $message = isset($result['message']) ? (string)$result['message'] : '저장하지 못했습니다.';
}
flash_set($type, $message);

$year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
$month = isset($_POST['month']) ? (int)$_POST['month'] : 0;
$url = '?r=' . urlencode('관리') . '&tab=company_overhead&oh=' . urlencode($category) . '&year=' . urlencode((string)$year);
if ($month > 0) $url .= '&month=' . urlencode((string)$month);
header('Location: ' . $url);
exit;
