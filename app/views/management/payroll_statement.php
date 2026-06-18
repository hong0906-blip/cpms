<?php
/**
 * Payroll statement standalone view.
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
$canReveal = cpms_can_reveal_payroll_resident_number($user, $pdo);
$secretInfo = cpms_company_payroll_secret_key_info();
$masked = (!empty($data['ok']) && isset($data['employee']['resident_masked'])) ? (string)$data['employee']['resident_masked'] : '';
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>급여명세서</title>
</head>
<body>
  <div class="actions">
    <a class="btn" href="javascript:history.back()">뒤로</a>
    <a class="btn btn-primary" target="_blank" href="?r=management/payroll_statement_print&year=<?php echo urlencode((string)$year); ?>&month=<?php echo urlencode((string)$month); ?>&employee_key=<?php echo urlencode($employeeKey); ?>">인쇄 화면</a>
    <a class="btn" target="_blank" href="?r=management/payroll_statement_pdf&year=<?php echo urlencode((string)$year); ?>&month=<?php echo urlencode((string)$month); ?>&employee_key=<?php echo urlencode($employeeKey); ?>">PDF</a>
    <?php if ($canReveal && !empty($secretInfo['ok']) && !empty($data['ok'])): ?>
      <button type="button" class="btn" id="statementRevealBtn">마스킹 해제</button>
      <button type="button" class="btn" id="statementMaskBtn">마스킹 생성</button>
    <?php endif; ?>
  </div>
  <?php if (empty($data['ok'])): ?>
    <div style="max-width:860px;margin:30px auto;padding:20px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;font-weight:700;"><?php echo h(isset($data['message']) ? $data['message'] : '급여명세서를 찾지 못했습니다.'); ?></div>
  <?php else: ?>
    <?php echo cpms_payroll_statement_render_html($data, false); ?>
  <?php endif; ?>

  <?php if ($canReveal && !empty($secretInfo['ok']) && !empty($data['ok'])): ?>
  <script>
  (function () {
    var csrf = <?php echo json_encode(csrf_token()); ?>;
    var year = <?php echo json_encode((string)$year); ?>;
    var month = <?php echo json_encode(sprintf('%02d', $month)); ?>;
    var employeeKey = <?php echo json_encode($employeeKey); ?>;
    var masked = <?php echo json_encode($masked); ?>;
    var revealBtn = document.getElementById('statementRevealBtn');
    var maskBtn = document.getElementById('statementMaskBtn');
    function residentTarget() { return document.getElementById('statement_resident'); }
    if (revealBtn) {
      revealBtn.onclick = function () {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '?r=management/payroll_resident_reveal', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onreadystatechange = function () {
          if (xhr.readyState !== 4) return;
          var data = null;
          try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
          if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
            var target = residentTarget();
            if (target) target.textContent = data.resident_number || '';
          } else {
            alert(data && data.message ? data.message : '주민번호 조회에 실패했습니다.');
          }
        };
        xhr.send('_csrf=' + encodeURIComponent(csrf) + '&year=' + encodeURIComponent(year) + '&month=' + encodeURIComponent(month) + '&employee_key=' + encodeURIComponent(employeeKey));
      };
    }
    if (maskBtn) {
      maskBtn.onclick = function () {
        var target = residentTarget();
        if (target) target.textContent = masked;
      };
    }
  })();
  </script>
  <?php endif; ?>
</body>
</html>
