<?php
$previewPublic = array();
if (isset($payrollPreview['parsed']) && is_array($payrollPreview['parsed'])) {
    $previewPublic = cpms_company_payroll_public_version($payrollPreview['parsed']);
}
$previewEmployees = isset($previewPublic['employees']) && is_array($previewPublic['employees']) ? $previewPublic['employees'] : array();
$previewYear = isset($payrollPreview['effective_year']) ? (string)$payrollPreview['effective_year'] : '';
$previewMonth = isset($payrollPreview['effective_month']) ? (string)$payrollPreview['effective_month'] : '';
$previewOriginalName = isset($payrollPreview['uploaded_original_name']) ? (string)$payrollPreview['uploaded_original_name'] : '';
$existingPreviewVersion = ($previewYear !== '' && $previewMonth !== '') ? cpms_company_payroll_load_version($previewYear, $previewMonth) : null;
?>
<div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
  <form id="payrollPreviewConfirmForm" method="post" action="?r=management/payroll_upload_confirm" onsubmit="return confirm('선택한 직원만 급여 기준월로 확정 저장합니다. 진행하시겠습니까?');">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <div class="font-extrabold text-amber-900">업로드 미리보기</div>
      <div class="text-sm text-amber-800 mt-1">
        적용 기준: <?php echo h($previewYear); ?>년 <?php echo h($previewMonth); ?>월 /
        파일: <?php echo h($previewOriginalName); ?> /
        직원 <?php echo h((string)count($previewEmployees)); ?>명
      </div>
      <?php if (is_array($existingPreviewVersion)): ?>
        <div class="text-sm text-red-700 font-bold mt-2">이미 같은 적용월 급여 버전이 있습니다. 확정 저장 시 기존 버전은 history 폴더에 백업한 뒤 교체됩니다.</div>
      <?php endif; ?>
      <div class="text-sm text-amber-800 font-bold mt-2">체크된 직원만 새 급여 기준월에 저장됩니다. 업로드 파일에 없거나 체크 해제한 직원은 이 기준월부터 제외됩니다.</div>
    </div>
    <div>
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="preview_token" value="<?php echo h($previewToken); ?>">
      <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">선택 인원 확정 저장</button>
    </div>
  </div>
  <div class="mt-4 overflow-x-auto">
    <table class="min-w-[1800px] w-full border-collapse text-xs bg-white">
      <thead>
        <tr class="bg-amber-100 text-amber-950">
          <th class="p-2 border">
            <label class="inline-flex items-center gap-1">
              <input type="checkbox" id="payrollPreviewSelectAll" checked>
              <span>선택</span>
            </label>
          </th>
          <th class="p-2 border">번호</th>
          <th class="p-2 border">재직</th>
          <th class="p-2 border">사원명</th>
          <th class="p-2 border">직급</th>
          <th class="p-2 border">주민번호</th>
          <th class="p-2 border">은행명</th>
          <th class="p-2 border">계좌번호</th>
          <th class="p-2 border text-right">지급합계</th>
          <th class="p-2 border text-right">공제총액</th>
          <th class="p-2 border text-right">차인지급액</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($previewEmployees) === 0): ?>
          <tr><td colspan="11" class="p-4 border text-center text-gray-500 font-bold">직원 데이터가 없습니다. 사원명 컬럼이 채워진 행만 저장됩니다.</td></tr>
        <?php endif; ?>
        <?php foreach ($previewEmployees as $previewEmployee): ?>
          <tr>
            <td class="p-2 border text-center">
              <input type="checkbox" class="payroll-preview-employee-check" name="employee_keys[]" value="<?php echo h(isset($previewEmployee['employee_key']) ? $previewEmployee['employee_key'] : ''); ?>" checked>
            </td>
            <td class="p-2 border text-center"><?php echo h(isset($previewEmployee['no']) ? $previewEmployee['no'] : ''); ?></td>
            <td class="p-2 border text-center"><?php echo h(isset($previewEmployee['status']) ? $previewEmployee['status'] : ''); ?></td>
            <td class="p-2 border font-bold"><?php echo h(isset($previewEmployee['name']) ? $previewEmployee['name'] : ''); ?></td>
            <td class="p-2 border"><?php echo h(isset($previewEmployee['position']) ? $previewEmployee['position'] : ''); ?></td>
            <td class="p-2 border"><?php echo h(isset($previewEmployee['resident_masked']) ? $previewEmployee['resident_masked'] : ''); ?></td>
            <td class="p-2 border"><?php echo h(isset($previewEmployee['bank_name']) ? $previewEmployee['bank_name'] : ''); ?></td>
            <td class="p-2 border"><?php echo h(isset($previewEmployee['bank_account_masked']) ? $previewEmployee['bank_account_masked'] : ''); ?></td>
            <td class="p-2 border text-right"><?php echo h(cpms_payroll_view_money(isset($previewEmployee['gross_pay']) ? $previewEmployee['gross_pay'] : 0)); ?></td>
            <td class="p-2 border text-right"><?php echo h(cpms_payroll_view_money(isset($previewEmployee['total_deduction']) ? $previewEmployee['total_deduction'] : 0)); ?></td>
            <td class="p-2 border text-right font-extrabold"><?php echo h(cpms_payroll_view_money(isset($previewEmployee['net_pay']) ? $previewEmployee['net_pay'] : 0)); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </form>
</div>

<script>
(function () {
  var all = document.getElementById('payrollPreviewSelectAll');
  var checks = document.querySelectorAll('.payroll-preview-employee-check');
  if (all) {
    all.onclick = function () {
      for (var i = 0; i < checks.length; i++) checks[i].checked = all.checked;
    };
  }
})();
</script>
