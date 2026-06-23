<?php
/**
 * Company overhead payroll UI.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../../services/CompanyPayrollAccessService.php';
require_once __DIR__ . '/../../../services/CompanyPayrollService.php';
require_once __DIR__ . '/../../../services/PayrollStatementService.php';

$payrollYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$payrollMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
if ($payrollMonth < 1 || $payrollMonth > 12) $payrollMonth = (int)date('m');
$payrollYm = cpms_company_payroll_normalize_year_month($payrollYear, $payrollMonth);
$payrollYear = (int)$payrollYm['year'];
$payrollMonth = (int)$payrollYm['month'];

$payrollFilter = array(
    'q' => '',
    'status' => '',
    'department' => '',
    'position' => '',
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

$previewToken = isset($_GET['preview_token']) ? trim((string)$_GET['preview_token']) : '';
$payrollPreview = ($canEditCompanyPayroll && $previewToken !== '') ? cpms_company_payroll_get_preview($previewToken) : null;
$payrollSecretInfo = cpms_company_payroll_secret_key_info();
$payrollCanRevealResident = cpms_can_reveal_payroll_resident_number($overheadUser, $overheadPdo);
$payrollCanRevealAccount = cpms_can_view_company_payroll($overheadUser, $overheadPdo);
$payrollCanGenerateStatement = cpms_can_generate_payroll_statement_pdf($overheadUser, $overheadPdo);
$payrollCanDownloadStatement = cpms_can_download_payroll_statement_pdf($overheadUser, $overheadPdo);
$payrollStatementResult = cpms_payroll_statement_load_month($payrollYear, $payrollMonth);
$payrollStatementItems = cpms_payroll_statement_item_map($payrollStatementResult);
$payrollStatementDueNotice = cpms_payroll_statement_run_due_notice($payrollYear, $payrollMonth);
$payrollStatementTemplateMeta = cpms_payroll_statement_template_load_meta();

if (!function_exists('cpms_payroll_view_statement_status')) {
function cpms_payroll_view_statement_status($item) {
    if (!is_array($item)) return '미생성';
    $status = isset($item['status']) ? (string)$item['status'] : '';
    if ($status === 'success') return '생성완료';
    if ($status === 'failed') return '실패';
    return '대기';
}}

if (!function_exists('cpms_payroll_view_account_text')) {
function cpms_payroll_view_account_text($employee) {
    if (!is_array($employee)) return '';
    $bank = isset($employee['bank_name']) ? trim((string)$employee['bank_name']) : '';
    $account = isset($employee['bank_account_masked']) ? trim((string)$employee['bank_account_masked']) : '';
    return trim($bank . ' ' . $account);
}}
?>

<div class="space-y-5">
  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="font-extrabold text-gray-900">임직원 월급 관리</div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold" data-modal-open="payrollUpload">급여대장 업로드</button>
        <?php if ($payrollCanGenerateStatement): ?>
          <button type="button" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold" data-modal-open="payrollTemplate">PDF 양식 업로드</button>
        <?php endif; ?>
        <button type="button" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold" data-modal-open="payrollStatementStatus">급여명세서 생성상태</button>
      </div>
    </div>
  </div>

  <div id="modal-payrollUpload" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-modal-close="payrollUpload"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-4xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;">
        <button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="payrollUpload">닫기</button>
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="font-extrabold text-gray-900">급여대장 업로드</div>
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
    </div>
  </div>

  <?php if ($payrollCanGenerateStatement): ?>
    <div id="modal-payrollTemplate" class="fixed inset-0 z-50 hidden">
      <div class="absolute inset-0 bg-black/40" data-modal-close="payrollTemplate"></div>
      <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-4xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;">
          <button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="payrollTemplate">닫기</button>
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div class="font-extrabold text-gray-900">급여명세서 PDF 양식 업로드</div>
          <?php if (is_array($payrollStatementTemplateMeta)): ?>
            <div class="text-xs text-gray-500 mt-2">
              현재 양식:
              <?php echo h(isset($payrollStatementTemplateMeta['uploaded_original_name']) ? (string)$payrollStatementTemplateMeta['uploaded_original_name'] : '-'); ?>
              /
              <?php echo h(isset($payrollStatementTemplateMeta['uploaded_at']) ? (string)$payrollStatementTemplateMeta['uploaded_at'] : '-'); ?>
              /
              시트 <?php echo h(isset($payrollStatementTemplateMeta['sheet_name']) ? (string)$payrollStatementTemplateMeta['sheet_name'] : '-'); ?>
            </div>
          <?php else: ?>
            <div class="text-xs text-amber-700 font-bold mt-2">아직 업로드된 급여명세서 PDF 양식이 없습니다. 없으면 기본 HTML 양식으로 생성됩니다.</div>
          <?php endif; ?>
        </div>
        <div class="px-3 py-2 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm font-bold">마스터/박지혜</div>
      </div>
      <form method="post" action="?r=management/payroll_statement_template_upload" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end mt-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="year" value="<?php echo h((string)$payrollYear); ?>">
        <input type="hidden" name="month" value="<?php echo h((string)$payrollMonth); ?>">
        <label class="block text-sm font-bold text-gray-700 md:col-span-4">
          <span class="block mb-2">급여명세서 양식 XLSX</span>
          <input type="file" name="statement_template_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="w-full px-3 py-3 rounded-xl border border-gray-300 bg-white">
        </label>
        <button type="submit" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold" onclick="return confirm('급여명세서 PDF 양식을 교체합니다. 선택 월의 기존 생성 결과는 history로 보관됩니다. 진행하시겠습니까?');">양식 업로드</button>
      </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (is_array($payrollPreview)): ?>
    <?php require __DIR__ . '/payroll_preview.php'; ?>
  <?php elseif ($previewToken !== ''): ?>
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 text-red-700 font-bold">미리보기 세션이 만료되었거나 찾을 수 없습니다. 다시 업로드해주세요.</div>
  <?php endif; ?>

  <div id="modal-payrollStatementStatus" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-modal-close="payrollStatementStatus"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-6xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;">
        <button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="payrollStatementStatus">닫기</button>
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="font-extrabold text-gray-900">급여명세서 생성 상태</div>
        <div class="text-sm text-gray-500 mt-1">
          선택 월 <?php echo h(sprintf('%04d/%02d', $payrollYear, $payrollMonth)); ?>
          <?php if (!empty($payrollSummary['has_data'])): ?>
            / 적용 기준 <?php echo h($payrollSummary['effective_year'] . '/' . $payrollSummary['effective_month']); ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <?php if ($payrollCanGenerateStatement): ?>
          <form method="post" action="?r=management/payroll_statement_generate">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="year" value="<?php echo h((string)$payrollYear); ?>">
            <input type="hidden" name="month" value="<?php echo h((string)$payrollMonth); ?>">
            <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">선택 월 전체 생성</button>
          </form>
          <form method="post" action="?r=management/payroll_statement_generate" onsubmit="return confirm('기존 생성 결과는 history로 보관하고 새 PDF를 생성합니다. 진행하시겠습니까?');">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="year" value="<?php echo h((string)$payrollYear); ?>">
            <input type="hidden" name="month" value="<?php echo h((string)$payrollMonth); ?>">
            <input type="hidden" name="force" value="1">
            <button type="submit" class="px-4 py-3 rounded-xl border border-amber-300 bg-amber-50 text-amber-800 font-extrabold">강제 재생성</button>
          </form>
        <?php endif; ?>
        <?php if ($payrollCanDownloadStatement && is_array($payrollStatementResult) && isset($payrollStatementResult['zip_drive_file_id']) && trim((string)$payrollStatementResult['zip_drive_file_id']) !== ''): ?>
          <a href="?r=management/payroll_statement_file&type=zip&action=download&year=<?php echo urlencode((string)$payrollYear); ?>&month=<?php echo urlencode((string)$payrollMonth); ?>" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">ZIP 다운로드</a>
        <?php endif; ?>
        <?php if (is_array($payrollStatementResult) && isset($payrollStatementResult['drive_folder_web_view_link']) && trim((string)$payrollStatementResult['drive_folder_web_view_link']) !== ''): ?>
          <a target="_blank" href="<?php echo h($payrollStatementResult['drive_folder_web_view_link']); ?>" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">Drive 폴더 보기</a>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($payrollStatementDueNotice && $payrollCanGenerateStatement): ?>
      <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800 font-bold">
        이번 달 급여명세서가 아직 생성되지 않았습니다. 지금 생성하시겠습니까?
      </div>
    <?php endif; ?>
    <div class="grid grid-cols-1 md:grid-cols-5 gap-3 mt-4">
      <div class="rounded-xl border border-gray-200 bg-slate-50 p-3">
        <div class="text-xs font-bold text-gray-500">생성 여부</div>
        <div class="font-extrabold mt-1 <?php echo is_array($payrollStatementResult) ? 'text-emerald-700' : 'text-gray-700'; ?>"><?php echo is_array($payrollStatementResult) ? '생성됨' : '미생성'; ?></div>
      </div>
      <div class="rounded-xl border border-gray-200 bg-slate-50 p-3">
        <div class="text-xs font-bold text-gray-500">생성일시</div>
        <div class="font-extrabold mt-1"><?php echo h(is_array($payrollStatementResult) && isset($payrollStatementResult['generated_at']) ? $payrollStatementResult['generated_at'] : '-'); ?></div>
      </div>
      <div class="rounded-xl border border-gray-200 bg-slate-50 p-3">
        <div class="text-xs font-bold text-gray-500">생성자</div>
        <div class="font-extrabold mt-1"><?php echo h(is_array($payrollStatementResult) && isset($payrollStatementResult['generated_by']) ? $payrollStatementResult['generated_by'] : '-'); ?></div>
      </div>
      <div class="rounded-xl border border-gray-200 bg-slate-50 p-3">
        <div class="text-xs font-bold text-gray-500">PDF 성공</div>
        <div class="font-extrabold mt-1"><?php echo h(is_array($payrollStatementResult) && isset($payrollStatementResult['success_count']) ? (string)(int)$payrollStatementResult['success_count'] : '0'); ?>건</div>
      </div>
      <div class="rounded-xl border border-gray-200 bg-slate-50 p-3">
        <div class="text-xs font-bold text-gray-500">PDF 실패</div>
        <div class="font-extrabold mt-1 <?php echo is_array($payrollStatementResult) && isset($payrollStatementResult['failed_count']) && (int)$payrollStatementResult['failed_count'] > 0 ? 'text-red-700' : ''; ?>"><?php echo h(is_array($payrollStatementResult) && isset($payrollStatementResult['failed_count']) ? (string)(int)$payrollStatementResult['failed_count'] : '0'); ?>건</div>
      </div>
    </div>
      </div>
    </div>
  </div>

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
      <table class="min-w-[3900px] w-full border-collapse text-xs">
        <thead>
          <tr class="bg-gray-50 text-gray-700">
            <th class="p-2 border">번호</th>
            <th class="p-2 border">재직</th>
            <th class="p-2 border">세후</th>
            <th class="p-2 border">소득세감면</th>
            <th class="p-2 border">사원명</th>
            <th class="p-2 border">직급</th>
            <th class="p-2 border">주민번호</th>
            <th class="p-2 border">은행명</th>
            <th class="p-2 border">계좌번호</th>
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
            <tr><td colspan="41" class="p-6 border text-center text-gray-500 font-bold">표시할 직원별 급여 데이터가 없습니다.</td></tr>
          <?php endif; ?>
          <?php foreach ($payrollEmployees as $employee): ?>
            <?php
              $employeeKey = isset($employee['employee_key']) ? (string)$employee['employee_key'] : '';
              $safeRowId = cpms_payroll_view_safe_id($employeeKey);
              $residentMasked = isset($employee['resident_masked']) ? (string)$employee['resident_masked'] : '';
              $accountMasked = isset($employee['bank_account_masked']) ? (string)$employee['bank_account_masked'] : cpms_company_payroll_mask_bank_account(isset($employee['bank_account']) ? $employee['bank_account'] : '');
              $statementItem = isset($payrollStatementItems[$employeeKey]) ? $payrollStatementItems[$employeeKey] : null;
              $statementUrl = '?r=management/payroll_statement&year=' . urlencode((string)$payrollYear) . '&month=' . urlencode((string)$payrollMonth) . '&employee_key=' . urlencode($employeeKey);
              $printUrl = '?r=management/payroll_statement_print&year=' . urlencode((string)$payrollYear) . '&month=' . urlencode((string)$payrollMonth) . '&employee_key=' . urlencode($employeeKey);
              $pdfUrl = '?r=management/payroll_statement_pdf&year=' . urlencode((string)$payrollYear) . '&month=' . urlencode((string)$payrollMonth) . '&employee_key=' . urlencode($employeeKey);
              $storedPdfDownloadUrl = '?r=management/payroll_statement_file&type=pdf&action=download&year=' . urlencode((string)$payrollYear) . '&month=' . urlencode((string)$payrollMonth) . '&employee_key=' . urlencode($employeeKey);
              $storedPdfViewUrl = '?r=management/payroll_statement_file&type=pdf&action=view&year=' . urlencode((string)$payrollYear) . '&month=' . urlencode((string)$payrollMonth) . '&employee_key=' . urlencode($employeeKey);
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
              <td class="p-2 border"><?php echo h(isset($employee['bank_name']) ? $employee['bank_name'] : ''); ?></td>
              <td class="p-2 border">
                <span id="account_<?php echo h($safeRowId); ?>"><?php echo h($accountMasked); ?></span>
                <?php if ($payrollCanRevealAccount): ?>
                  <div class="mt-1 flex gap-1">
                    <button type="button" class="payroll-account-reveal-btn px-2 py-1 rounded border border-gray-300 text-[11px] font-bold" data-key="<?php echo h($employeeKey); ?>" data-target="account_<?php echo h($safeRowId); ?>">마스킹 해제</button>
                    <button type="button" class="payroll-account-mask-btn px-2 py-1 rounded border border-gray-300 text-[11px] font-bold" data-target="account_<?php echo h($safeRowId); ?>" data-masked="<?php echo h($accountMasked); ?>">마스킹 생성</button>
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
                <div class="mb-1 font-bold <?php echo is_array($statementItem) && isset($statementItem['status']) && (string)$statementItem['status'] === 'failed' ? 'text-red-700' : 'text-gray-700'; ?>">
                  <?php echo h(cpms_payroll_view_statement_status($statementItem)); ?>
                </div>
                <div class="flex flex-wrap gap-1">
                  <a target="_blank" href="<?php echo h($statementUrl); ?>" class="px-2 py-1 rounded border border-gray-300 font-bold">보기</a>
                  <a target="_blank" href="<?php echo h($printUrl); ?>" class="px-2 py-1 rounded border border-gray-300 font-bold">출력</a>
                  <a target="_blank" href="<?php echo h($pdfUrl); ?>" class="px-2 py-1 rounded border border-gray-300 font-bold">PDF</a>
                  <?php if ($payrollCanDownloadStatement && is_array($statementItem) && isset($statementItem['status']) && (string)$statementItem['status'] === 'success'): ?>
                    <a target="_blank" href="<?php echo h($storedPdfViewUrl); ?>" class="px-2 py-1 rounded border border-emerald-300 text-emerald-700 font-bold">Drive</a>
                    <a href="<?php echo h($storedPdfDownloadUrl); ?>" class="px-2 py-1 rounded border border-emerald-300 text-emerald-700 font-bold">다운로드</a>
                  <?php endif; ?>
                  <?php if ($payrollCanGenerateStatement): ?>
                    <form method="post" action="?r=management/payroll_statement_generate" onsubmit="return confirm('이 직원의 급여명세서를 다시 생성하시겠습니까?');">
                      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                      <input type="hidden" name="year" value="<?php echo h((string)$payrollYear); ?>">
                      <input type="hidden" name="month" value="<?php echo h((string)$payrollMonth); ?>">
                      <input type="hidden" name="employee_key" value="<?php echo h($employeeKey); ?>">
                      <input type="hidden" name="force" value="1">
                      <button type="submit" class="px-2 py-1 rounded border border-amber-300 text-amber-700 font-bold">재생성</button>
                    </form>
                    <form method="post" action="?r=management/payroll_employee_delete" onsubmit="return confirm('선택한 월 기준 급여 버전에서 이 직원을 삭제합니다. 이전 월 기준은 보존됩니다. 진행하시겠습니까?');">
                      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                      <input type="hidden" name="year" value="<?php echo h((string)$payrollYear); ?>">
                      <input type="hidden" name="month" value="<?php echo h((string)$payrollMonth); ?>">
                      <input type="hidden" name="employee_key" value="<?php echo h($employeeKey); ?>">
                      <button type="submit" class="px-2 py-1 rounded border border-red-300 text-red-700 font-bold">삭제</button>
                    </form>
                  <?php endif; ?>
                </div>
                <?php if (is_array($statementItem) && isset($statementItem['error']) && trim((string)$statementItem['error']) !== ''): ?>
                  <div class="mt-1 text-[11px] text-red-700"><?php echo h($statementItem['error']); ?></div>
                <?php endif; ?>
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

<?php if ($payrollCanRevealAccount): ?>
<script>
(function () {
  var csrf = <?php echo json_encode(csrf_token()); ?>;
  var year = <?php echo json_encode((string)$payrollYear); ?>;
  var month = <?php echo json_encode(sprintf('%02d', $payrollMonth)); ?>;
  function postAccountReveal(button) {
    var key = button.getAttribute('data-key') || '';
    var targetId = button.getAttribute('data-target') || '';
    var target = document.getElementById(targetId);
    if (!key || !target) return;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '?r=management/payroll_bank_account_reveal', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
      if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
        var bank = data.bank_name || '';
        var account = data.bank_account || '';
        target.textContent = (bank ? bank + ' ' : '') + account;
      } else {
        alert(data && data.message ? data.message : '계좌번호 조회에 실패했습니다.');
      }
    };
    xhr.send('_csrf=' + encodeURIComponent(csrf) + '&year=' + encodeURIComponent(year) + '&month=' + encodeURIComponent(month) + '&employee_key=' + encodeURIComponent(key));
  }
  var revealButtons = document.querySelectorAll('.payroll-account-reveal-btn');
  for (var i = 0; i < revealButtons.length; i++) {
    revealButtons[i].onclick = function () { postAccountReveal(this); };
  }
  var maskButtons = document.querySelectorAll('.payroll-account-mask-btn');
  for (var j = 0; j < maskButtons.length; j++) {
    maskButtons[j].onclick = function () {
      var target = document.getElementById(this.getAttribute('data-target') || '');
      if (target) target.textContent = this.getAttribute('data-masked') || '';
    };
  }
})();
</script>
<?php endif; ?>
