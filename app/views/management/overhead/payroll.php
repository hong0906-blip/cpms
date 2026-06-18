<?php
/**
 * Company overhead payroll UI.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../../services/CompanyPayrollAccessService.php';
require_once __DIR__ . '/../../../services/CompanyPayrollService.php';

$payrollYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$payrollMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
if ($payrollMonth < 1 || $payrollMonth > 12) $payrollMonth = (int)date('m');
$payrollYm = cpms_company_payroll_normalize_year_month($payrollYear, $payrollMonth);
$payrollYear = (int)$payrollYm['year'];
$payrollMonth = (int)$payrollYm['month'];

$payrollFilter = array(
    'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : '',
    'status' => isset($_GET['payroll_status']) ? trim((string)$_GET['payroll_status']) : '',
    'department' => isset($_GET['payroll_department']) ? trim((string)$_GET['payroll_department']) : '',
    'position' => isset($_GET['payroll_position']) ? trim((string)$_GET['payroll_position']) : '',
);

if (!function_exists('cpms_payroll_view_money')) {
function cpms_payroll_view_money($value) {
    return number_format((float)$value);
}}

if (!function_exists('cpms_payroll_view_safe_id')) {
function cpms_payroll_view_safe_id($value) {
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$value);
}}

$payrollSummary = cpms_company_payroll_month_summary($payrollYear, $payrollMonth);
$payrollVersion = isset($payrollSummary['version']) && is_array($payrollSummary['version']) ? cpms_company_payroll_public_version($payrollSummary['version']) : null;
$payrollAllEmployees = is_array($payrollVersion) && isset($payrollVersion['employees']) && is_array($payrollVersion['employees']) ? $payrollVersion['employees'] : array();
$payrollEmployees = cpms_company_payroll_filter_employees($payrollAllEmployees, $payrollFilter);

$payrollStatusOptions = array();
$payrollDepartmentOptions = array();
$payrollPositionOptions = array();
foreach ($payrollAllEmployees as $employeeOption) {
    if (!is_array($employeeOption)) continue;
    $statusOption = isset($employeeOption['status']) ? trim((string)$employeeOption['status']) : '';
    $departmentOption = isset($employeeOption['department']) ? trim((string)$employeeOption['department']) : '';
    $positionOption = isset($employeeOption['position']) ? trim((string)$employeeOption['position']) : '';
    if ($statusOption !== '') $payrollStatusOptions[$statusOption] = $statusOption;
    if ($departmentOption !== '') $payrollDepartmentOptions[$departmentOption] = $departmentOption;
    if ($positionOption !== '') $payrollPositionOptions[$positionOption] = $positionOption;
}

$previewToken = isset($_GET['preview_token']) ? trim((string)$_GET['preview_token']) : '';
$payrollPreview = ($canEditCompanyPayroll && $previewToken !== '') ? cpms_company_payroll_get_preview($previewToken) : null;
$payrollSecretInfo = cpms_company_payroll_secret_key_info();
$payrollCanRevealResident = cpms_can_reveal_payroll_resident_number($overheadUser, $overheadPdo);
?>

<div class="space-y-5">
  <form method="get" action="" class="bg-white border border-gray-200 rounded-2xl p-4">
    <input type="hidden" name="r" value="관리">
    <input type="hidden" name="tab" value="company_overhead">
    <input type="hidden" name="oh" value="payroll">
    <div class="grid grid-cols-1 md:grid-cols-7 gap-3 items-end">
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">기준연도</span>
        <input type="number" name="year" min="2000" max="2100" value="<?php echo h((string)$payrollYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">기준월</span>
        <select name="month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo ((int)$payrollMonth === $m) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $m); ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <div class="md:col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-3">
        <div class="text-xs font-bold text-emerald-700">적용 중인 급여 기준월</div>
        <div class="font-extrabold text-emerald-900">
          <?php if (!empty($payrollSummary['has_data'])): ?>
            <?php echo h($payrollSummary['effective_year'] . '년 ' . $payrollSummary['effective_month'] . '월 급여대장'); ?>
          <?php else: ?>
            등록된 급여 기준월 없음
          <?php endif; ?>
        </div>
      </div>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">검색어</span>
        <input type="text" name="q" value="<?php echo h($payrollFilter['q']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" placeholder="사원명/직급">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">재직 상태</span>
        <select name="payroll_status" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <option value="">전체</option>
          <?php foreach ($payrollStatusOptions as $option): ?>
            <option value="<?php echo h($option); ?>" <?php echo ($payrollFilter['status'] === $option) ? 'selected' : ''; ?>><?php echo h($option); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">직급</span>
        <select name="payroll_position" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <option value="">전체</option>
          <?php foreach ($payrollPositionOptions as $option2): ?>
            <option value="<?php echo h($option2); ?>" <?php echo ($payrollFilter['position'] === $option2) ? 'selected' : ''; ?>><?php echo h($option2); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-7 gap-3 items-end mt-3">
      <label class="block text-sm font-bold text-gray-700 md:col-span-2">
        <span class="block mb-2">부서</span>
        <select name="payroll_department" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <option value="">전체</option>
          <?php foreach ($payrollDepartmentOptions as $option3): ?>
            <option value="<?php echo h($option3); ?>" <?php echo ($payrollFilter['department'] === $option3) ? 'selected' : ''; ?>><?php echo h($option3); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="md:col-span-5 flex flex-wrap gap-2">
        <button type="submit" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">조회</button>
        <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=payroll" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">초기화</a>
      </div>
    </div>
  </form>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="font-extrabold text-gray-900">급여대장 업로드</div>
        <div class="text-sm text-gray-500 mt-1">업로드한 적용월부터 다음 급여대장 업로드 전까지 같은 급여 버전이 월별로 적용됩니다.</div>
        <?php if (empty($payrollSecretInfo['ok'])): ?>
          <div class="text-sm text-amber-700 font-bold mt-2">주민번호 복호화 키가 없어 마스킹 해제 기능은 비활성화됩니다. 목록과 합산은 정상 동작합니다.</div>
        <?php endif; ?>
      </div>
      <div class="px-3 py-2 rounded-xl border <?php echo $canEditCompanyPayroll ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600'; ?> text-sm font-bold">
        <?php echo $canEditCompanyPayroll ? '업로드 가능' : '조회 전용'; ?>
      </div>
    </div>
    <?php if ($canEditCompanyPayroll): ?>
      <form method="post" action="?r=management/payroll_upload_preview" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end mt-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">적용연도</span>
          <input type="number" name="apply_year" min="2000" max="2100" value="<?php echo h((string)$payrollYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">적용월</span>
          <select name="apply_month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
            <?php for ($am = 1; $am <= 12; $am++): ?>
              <option value="<?php echo $am; ?>" <?php echo ((int)$payrollMonth === $am) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $am); ?></option>
            <?php endfor; ?>
          </select>
        </label>
        <label class="block text-sm font-bold text-gray-700 md:col-span-2">
          <span class="block mb-2">급여대장 엑셀 파일</span>
          <input type="file" name="payroll_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="w-full px-3 py-3 rounded-xl border border-gray-300 bg-white">
        </label>
        <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">업로드 미리보기</button>
      </form>
    <?php else: ?>
      <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">대표/부사장은 조회 전용이며, 업로드는 마스터와 박지혜 계정만 가능합니다.</div>
    <?php endif; ?>
  </div>

  <?php if (is_array($payrollPreview)): ?>
    <?php require __DIR__ . '/payroll_preview.php'; ?>
  <?php elseif ($previewToken !== ''): ?>
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 text-red-700 font-bold">미리보기 세션이 만료되었거나 찾을 수 없습니다. 다시 업로드해주세요.</div>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="text-xs font-bold text-gray-500">선택 월</div>
      <div class="text-xl font-extrabold text-gray-900 mt-2"><?php echo h(sprintf('%04d/%02d', $payrollYear, $payrollMonth)); ?></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="text-xs font-bold text-gray-500">직원 수</div>
      <div class="text-xl font-extrabold text-gray-900 mt-2"><?php echo h((string)(isset($payrollSummary['employee_count']) ? (int)$payrollSummary['employee_count'] : 0)); ?>명</div>
    </div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="text-xs font-bold text-gray-500">실지급액 합계</div>
      <div class="text-xl font-extrabold text-emerald-700 mt-2"><?php echo h(cpms_payroll_view_money(isset($payrollSummary['total_net_pay']) ? $payrollSummary['total_net_pay'] : 0)); ?>원</div>
    </div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="text-xs font-bold text-gray-500">지급합계 합계</div>
      <div class="text-xl font-extrabold text-gray-900 mt-2"><?php echo h(cpms_payroll_view_money(isset($payrollSummary['total_gross_pay']) ? $payrollSummary['total_gross_pay'] : 0)); ?>원</div>
    </div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="text-xs font-bold text-gray-500">공제총액 합계</div>
      <div class="text-xl font-extrabold text-gray-900 mt-2"><?php echo h(cpms_payroll_view_money(isset($payrollSummary['total_deduction']) ? $payrollSummary['total_deduction'] : 0)); ?>원</div>
    </div>
  </div>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <div>
        <div class="font-extrabold text-gray-900">직원별 급여 목록</div>
        <div class="text-sm text-gray-500 mt-1">
          <?php if (!empty($payrollSummary['has_data'])): ?>
            <?php echo h(sprintf('%04d년 %02d월 급여 / 적용 기준: %s년 %s월 급여대장', $payrollYear, $payrollMonth, $payrollSummary['effective_year'], $payrollSummary['effective_month'])); ?>
          <?php else: ?>
            선택 월에 적용할 급여대장이 없습니다.
          <?php endif; ?>
        </div>
      </div>
      <div class="text-sm text-gray-500 font-bold">표시 <?php echo h((string)count($payrollEmployees)); ?>명</div>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-[3600px] w-full border-collapse text-xs">
        <thead>
          <tr class="bg-gray-50 text-gray-700">
            <th class="p-2 border">번호</th>
            <th class="p-2 border">재직</th>
            <th class="p-2 border">세후</th>
            <th class="p-2 border">소득세감면</th>
            <th class="p-2 border">사원명</th>
            <th class="p-2 border">직급</th>
            <th class="p-2 border">주민번호</th>
            <th class="p-2 border">생년월일</th>
            <th class="p-2 border">입사일</th>
            <th class="p-2 border text-right">기본급</th>
            <th class="p-2 border text-right">연장수당</th>
            <th class="p-2 border text-right">연차수당</th>
            <th class="p-2 border text-right">사원연금</th>
            <th class="p-2 border text-right">식대</th>
            <th class="p-2 border text-right">차량유지비</th>
            <th class="p-2 border text-right">연구수당</th>
            <th class="p-2 border text-right">육아수당</th>
            <th class="p-2 border text-right">연차수당2</th>
            <th class="p-2 border text-right">직책수당</th>
            <th class="p-2 border text-right">결근</th>
            <th class="p-2 border text-right">선급급여</th>
            <th class="p-2 border text-right">지급합계</th>
            <th class="p-2 border text-right">소득세</th>
            <th class="p-2 border text-right">지방소득세</th>
            <th class="p-2 border text-right">고용보험</th>
            <th class="p-2 border text-right">국민연금</th>
            <th class="p-2 border text-right">건강보험</th>
            <th class="p-2 border text-right">노인장기요양</th>
            <th class="p-2 border text-right">소득세정산</th>
            <th class="p-2 border text-right">지방세정산</th>
            <th class="p-2 border text-right">건강보험정산</th>
            <th class="p-2 border text-right">장기요양정산</th>
            <th class="p-2 border text-right">기타공제</th>
            <th class="p-2 border text-right">공제총액</th>
            <th class="p-2 border text-right">차인지급액</th>
            <th class="p-2 border">기타</th>
            <th class="p-2 border text-right">세전 연봉</th>
            <th class="p-2 border text-right">세후 연봉</th>
            <th class="p-2 border">급여명세서 보기/출력</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($payrollEmployees) === 0): ?>
            <tr><td colspan="39" class="p-6 border text-center text-gray-500 font-bold">표시할 직원별 급여 데이터가 없습니다.</td></tr>
          <?php endif; ?>
          <?php foreach ($payrollEmployees as $employee): ?>
            <?php
              $employeeKey = isset($employee['employee_key']) ? (string)$employee['employee_key'] : '';
              $safeRowId = cpms_payroll_view_safe_id($employeeKey);
              $residentMasked = isset($employee['resident_masked']) ? (string)$employee['resident_masked'] : '';
              $statementUrl = '?r=management/payroll_statement&year=' . urlencode((string)$payrollYear) . '&month=' . urlencode((string)$payrollMonth) . '&employee_key=' . urlencode($employeeKey);
              $printUrl = '?r=management/payroll_statement_print&year=' . urlencode((string)$payrollYear) . '&month=' . urlencode((string)$payrollMonth) . '&employee_key=' . urlencode($employeeKey);
              $pdfUrl = '?r=management/payroll_statement_pdf&year=' . urlencode((string)$payrollYear) . '&month=' . urlencode((string)$payrollMonth) . '&employee_key=' . urlencode($employeeKey);
            ?>
            <tr class="hover:bg-gray-50">
              <td class="p-2 border text-center"><?php echo h(isset($employee['no']) ? $employee['no'] : ''); ?></td>
              <td class="p-2 border text-center"><?php echo h(isset($employee['status']) ? $employee['status'] : ''); ?></td>
              <td class="p-2 border text-center"><?php echo h(isset($employee['after_tax_type']) ? $employee['after_tax_type'] : ''); ?></td>
              <td class="p-2 border text-center"><?php echo h(isset($employee['tax_reduction']) ? $employee['tax_reduction'] : ''); ?></td>
              <td class="p-2 border font-extrabold"><?php echo h(isset($employee['name']) ? $employee['name'] : ''); ?></td>
              <td class="p-2 border"><?php echo h(isset($employee['position']) ? $employee['position'] : ''); ?></td>
              <td class="p-2 border">
                <span id="resident_<?php echo h($safeRowId); ?>"><?php echo h($residentMasked); ?></span>
                <?php if ($payrollCanRevealResident && !empty($payrollSecretInfo['ok'])): ?>
                  <div class="mt-1 flex gap-1">
                    <button type="button" class="payroll-reveal-btn px-2 py-1 rounded border border-gray-300 text-[11px] font-bold" data-key="<?php echo h($employeeKey); ?>" data-target="resident_<?php echo h($safeRowId); ?>">마스킹 해제</button>
                    <button type="button" class="payroll-mask-btn px-2 py-1 rounded border border-gray-300 text-[11px] font-bold" data-target="resident_<?php echo h($safeRowId); ?>" data-masked="<?php echo h($residentMasked); ?>">마스킹 생성</button>
                  </div>
                <?php endif; ?>
              </td>
              <td class="p-2 border"><?php echo h(isset($employee['birth_date']) ? $employee['birth_date'] : ''); ?></td>
              <td class="p-2 border"><?php echo h(isset($employee['joined_at']) ? $employee['joined_at'] : ''); ?></td>
              <?php foreach (array('base_pay','overtime_pay','annual_leave_pay','employee_pension','meal_allowance','vehicle_allowance','research_allowance','childcare_allowance','annual_leave_pay_2','position_allowance','absence_deduction','advance_pay','gross_pay','income_tax','local_income_tax','employment_insurance','national_pension','health_insurance','long_term_care','income_tax_adjustment','local_tax_adjustment','health_insurance_adjustment','long_term_care_adjustment','other_deduction','total_deduction','net_pay') as $moneyKey): ?>
                <td class="p-2 border text-right <?php echo $moneyKey === 'net_pay' ? 'font-extrabold text-emerald-700' : ''; ?>"><?php echo h(cpms_payroll_view_money(isset($employee[$moneyKey]) ? $employee[$moneyKey] : 0)); ?></td>
              <?php endforeach; ?>
              <td class="p-2 border"><?php echo h(isset($employee['etc']) ? $employee['etc'] : ''); ?></td>
              <td class="p-2 border text-right"><?php echo h(cpms_payroll_view_money(isset($employee['annual_salary_before_tax']) ? $employee['annual_salary_before_tax'] : 0)); ?></td>
              <td class="p-2 border text-right"><?php echo h(cpms_payroll_view_money(isset($employee['annual_salary_after_tax']) ? $employee['annual_salary_after_tax'] : 0)); ?></td>
              <td class="p-2 border">
                <div class="flex flex-wrap gap-1">
                  <a target="_blank" href="<?php echo h($statementUrl); ?>" class="px-2 py-1 rounded border border-gray-300 font-bold">보기</a>
                  <a target="_blank" href="<?php echo h($printUrl); ?>" class="px-2 py-1 rounded border border-gray-300 font-bold">출력</a>
                  <a target="_blank" href="<?php echo h($pdfUrl); ?>" class="px-2 py-1 rounded border border-gray-300 font-bold">PDF</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($payrollCanRevealResident && !empty($payrollSecretInfo['ok'])): ?>
<script>
(function () {
  var csrf = <?php echo json_encode(csrf_token()); ?>;
  var year = <?php echo json_encode((string)$payrollYear); ?>;
  var month = <?php echo json_encode(sprintf('%02d', $payrollMonth)); ?>;
  function postReveal(button) {
    var key = button.getAttribute('data-key') || '';
    var targetId = button.getAttribute('data-target') || '';
    var target = document.getElementById(targetId);
    if (!key || !target) return;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '?r=management/payroll_resident_reveal', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
      if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
        target.textContent = data.resident_number || '';
      } else {
        alert(data && data.message ? data.message : '주민번호 조회에 실패했습니다.');
      }
    };
    xhr.send('_csrf=' + encodeURIComponent(csrf) + '&year=' + encodeURIComponent(year) + '&month=' + encodeURIComponent(month) + '&employee_key=' + encodeURIComponent(key));
  }
  var revealButtons = document.querySelectorAll('.payroll-reveal-btn');
  for (var i = 0; i < revealButtons.length; i++) {
    revealButtons[i].onclick = function () { postReveal(this); };
  }
  var maskButtons = document.querySelectorAll('.payroll-mask-btn');
  for (var j = 0; j < maskButtons.length; j++) {
    maskButtons[j].onclick = function () {
      var target = document.getElementById(this.getAttribute('data-target') || '');
      if (target) target.textContent = this.getAttribute('data-masked') || '';
    };
  }
})();
</script>
<?php endif; ?>
