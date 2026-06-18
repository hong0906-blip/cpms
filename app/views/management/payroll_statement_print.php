<?php
/**
 * Payroll statement print view.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/CompanyPayrollAccessService.php';
require_once __DIR__ . '/../../services/PayrollStatementService.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$user = Auth::user();
if (!cpms_can_view_company_payroll($user, $pdo)) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$employeeKey = isset($_GET['employee_key']) ? trim((string)$_GET['employee_key']) : '';
$data = cpms_payroll_statement_data($year, $month, $employeeKey);
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>급여명세서 인쇄</title>
</head>
<body>
  <div class="actions">
    <a class="btn" href="javascript:window.print()">인쇄</a>
    <a class="btn" href="javascript:window.close()">닫기</a>
  </div>
  <?php if (empty($data['ok'])): ?>
    <div style="max-width:860px;margin:30px auto;padding:20px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;font-weight:700;"><?php echo h(isset($data['message']) ? $data['message'] : '급여명세서를 찾지 못했습니다.'); ?></div>
  <?php else: ?>
    <?php echo cpms_payroll_statement_render_html($data, true); ?>
  <?php endif; ?>
</body>
</html>
