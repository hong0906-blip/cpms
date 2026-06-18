<?php
/**
 * Reveal one employee resident number after server-side permission check.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/CompanyPayrollAccessService.php';
require_once __DIR__ . '/../../services/CompanyPayrollService.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo cpms_company_payroll_json_encode(array('ok' => false, 'message' => '로그인이 필요합니다.'));
    exit;
}

$pdo = Db::pdo();
$user = Auth::user();
if (!cpms_can_reveal_payroll_resident_number($user, $pdo)) {
    http_response_code(403);
    echo cpms_company_payroll_json_encode(array('ok' => false, 'message' => '주민번호 조회 권한이 없습니다.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    http_response_code(400);
    echo cpms_company_payroll_json_encode(array('ok' => false, 'message' => '보안 토큰이 올바르지 않습니다.'));
    exit;
}

$year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
$month = isset($_POST['month']) ? (int)$_POST['month'] : (int)date('m');
$employeeKey = isset($_POST['employee_key']) ? trim((string)$_POST['employee_key']) : '';
$result = cpms_company_payroll_reveal_resident($year, $month, $employeeKey, $user);
if (empty($result['ok'])) {
    http_response_code(400);
}
echo cpms_company_payroll_json_encode($result);
exit;
