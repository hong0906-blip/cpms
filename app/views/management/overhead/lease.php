<?php
$activeCategory = 'lease';
$formItem = is_array($editItem) ? $editItem : array();
$isLeaseEditing = is_array($editItem);
$formYear = cpms_overhead_view_val($formItem, 'year', (string)$filters['year']);
$formMonth = cpms_overhead_view_val($formItem, 'month', ($filters['month'] > 0 ? sprintf('%02d', (int)$filters['month']) : date('m')));
$leaseApplyMonth = ($filters['month'] > 0) ? (int)$filters['month'] : 1;
$leasePreviewToken = isset($_GET['lease_preview_token']) ? trim((string)$_GET['lease_preview_token']) : '';
$leasePreview = ($canEditCompanyOverhead && $leasePreviewToken !== '') ? cpms_company_overhead_get_lease_preview($leasePreviewToken) : null;

if (!function_exists('cpms_overhead_lease_val')) {
function cpms_overhead_lease_val($row, $key) {
    if (is_array($row) && isset($row[$key])) return $row[$key];
    return '';
}}

if (!function_exists('cpms_overhead_lease_money_input')) {
function cpms_overhead_lease_money_input($value) {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $amount = cpms_company_overhead_numeric_value($raw);
    if ($amount <= 0) return '';
    return number_format($amount, 0, '.', '');
}}

if (!function_exists('cpms_overhead_lease_money_label')) {
function cpms_overhead_lease_money_label($value) {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $amount = cpms_company_overhead_numeric_value($raw);
    if ($amount <= 0) return '';
    return number_format($amount);
}}

if (!function_exists('cpms_overhead_lease_total')) {
function cpms_overhead_lease_total($row) {
    $rent = isset($row['amount']) ? cpms_company_overhead_numeric_value($row['amount']) : 0.0;
    $maintenanceFee = isset($row['maintenance_fee']) ? cpms_company_overhead_numeric_value($row['maintenance_fee']) : 0.0;
    return $rent + $maintenanceFee;
}}

if (!function_exists('cpms_overhead_lease_form_id')) {
function cpms_overhead_lease_form_id($row) {
    $seed = (isset($row['id']) ? (string)$row['id'] : '') . '-' . (isset($row['year']) ? (string)$row['year'] : '') . '-' . (isset($row['month']) ? (string)$row['month'] : '');
    return 'lease-row-' . md5($seed);
}}
?>

<div class="bg-white border border-gray-200 rounded-2xl p-4">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <div class="font-extrabold text-gray-900">임대차 엑셀 업로드</div>
      <div class="text-sm text-gray-500 mt-1">법인임대차 관리대장 .xlsx 양식을 적용연도 월별 데이터로 반영합니다.</div>
    </div>
    <div class="px-3 py-2 rounded-xl border <?php echo $canEditCompanyOverhead ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600'; ?> text-sm font-bold">
      <?php echo $canEditCompanyOverhead ? '업로드 가능' : '조회 전용'; ?>
    </div>
  </div>
  <?php if ($canEditCompanyOverhead): ?>
    <form method="post" action="?r=management/lease_upload_preview" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end mt-4">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">적용연도</span>
        <input type="number" name="apply_year" min="2000" max="2100" value="<?php echo h((string)$filters['year']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">적용 시작월</span>
        <select name="apply_month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <?php for ($am = 1; $am <= 12; $am++): ?>
            <option value="<?php echo $am; ?>" <?php echo ((int)$leaseApplyMonth === $am) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $am); ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="block text-sm font-bold text-gray-700 md:col-span-2">
        <span class="block mb-2">임대차 엑셀 파일</span>
        <input type="file" name="lease_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="w-full px-3 py-3 rounded-xl border border-gray-300 bg-white">
      </label>
      <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">업로드 미리보기</button>
    </form>
  <?php else: ?>
    <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">조회 권한만 있어 엑셀 업로드는 사용할 수 없습니다.</div>
  <?php endif; ?>
</div>

<?php if (is_array($leasePreview)): ?>
  <?php
    $leasePreviewParsed = isset($leasePreview['parsed']) && is_array($leasePreview['parsed']) ? $leasePreview['parsed'] : array();
    $leasePreviewRows = isset($leasePreviewParsed['rows']) && is_array($leasePreviewParsed['rows']) ? $leasePreviewParsed['rows'] : array();
    $leasePreviewMonths = isset($leasePreview['months']) && is_array($leasePreview['months']) ? $leasePreview['months'] : array();
    $leasePreviewMonthRows = isset($leasePreview['month_rows']) && is_array($leasePreview['month_rows']) ? $leasePreview['month_rows'] : array();
    $leasePreviewApplyCount = 0;
    foreach ($leasePreviewMonthRows as $leasePreviewMonthItems) {
        if (is_array($leasePreviewMonthItems)) $leasePreviewApplyCount += count($leasePreviewMonthItems);
    }
  ?>
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
    <form method="post" action="?r=management/lease_upload_confirm" onsubmit="return confirm('임대차 미리보기 결과를 월별 데이터로 확정 저장합니다. 진행하시겠습니까?');">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="preview_token" value="<?php echo h($leasePreviewToken); ?>">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div class="font-extrabold text-amber-900">업로드 미리보기</div>
          <div class="text-sm text-amber-800 mt-1">
            적용 시작: <?php echo h(isset($leasePreview['year']) ? $leasePreview['year'] : ''); ?>년 <?php echo h(isset($leasePreview['month']) ? $leasePreview['month'] : ''); ?>월 /
            파일: <?php echo h(isset($leasePreview['uploaded_original_name']) ? $leasePreview['uploaded_original_name'] : ''); ?> /
            유효 항목: <?php echo h(isset($leasePreview['active_count']) ? (string)(int)$leasePreview['active_count'] : '0'); ?>건 /
            월별 생성: <?php echo h((string)$leasePreviewApplyCount); ?>건
          </div>
          <div class="text-sm text-amber-800 mt-1">반영 월: <?php echo h(count($leasePreviewMonths) > 0 ? implode(', ', $leasePreviewMonths) : '-'); ?></div>
        </div>
        <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">확정 저장</button>
      </div>
    </form>
    <?php if (count($leasePreviewRows) > 0): ?>
      <div class="mt-4 cpms-responsive-table-wrap">
        <table class="cpms-responsive-table text-xs bg-white">
          <thead>
            <tr>
              <th class="text-left p-3 border-b border-amber-200 bg-amber-100">구분</th>
              <th class="text-left p-3 border-b border-amber-200 bg-amber-100">주소</th>
              <th class="text-right p-3 border-b border-amber-200 bg-amber-100">월세</th>
              <th class="text-left p-3 border-b border-amber-200 bg-amber-100">계약기간</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($leasePreviewRows, 0, 12) as $previewRow): ?>
              <tr>
                <td class="p-3 border-b border-amber-100 font-bold"><?php echo h(cpms_overhead_lease_val($previewRow, 'title')); ?></td>
                <td class="p-3 border-b border-amber-100" data-wrap="1"><?php echo h(cpms_overhead_lease_val($previewRow, 'address')); ?></td>
                <td class="p-3 border-b border-amber-100 text-right"><?php echo h(cpms_overhead_lease_money_label(cpms_overhead_lease_val($previewRow, 'amount'))); ?></td>
                <td class="p-3 border-b border-amber-100"><?php echo h(cpms_overhead_lease_val($previewRow, 'source_contract_period')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php elseif ($leasePreviewToken !== ''): ?>
  <div class="bg-red-50 border border-red-200 rounded-2xl p-4 text-red-700 font-bold">미리보기 세션이 만료되었거나 찾을 수 없습니다. 다시 업로드해주세요.</div>
<?php endif; ?>

<div class="bg-white border border-gray-200 rounded-2xl p-4">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <div>
      <div class="font-extrabold text-gray-900">임대차 관리</div>
      <div class="text-sm text-gray-500">숙소와 사무실 임대차를 같은 양식으로 관리합니다.</div>
    </div>
    <?php if ($isLeaseEditing): ?>
      <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=lease" class="px-3 py-2 rounded-xl border border-gray-300 text-sm font-bold">수정 취소</a>
    <?php endif; ?>
  </div>

  <?php if (!$canEditCompanyOverhead): ?>
    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">조회 권한만 있어 등록/수정/삭제는 사용할 수 없습니다.</div>
  <?php else: ?>
    <form method="post" action="?r=management/overhead_save" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="category" value="lease">
      <input type="hidden" name="id" value="<?php echo h(cpms_overhead_lease_val($formItem, 'id')); ?>">
      <input type="hidden" name="original_year" value="<?php echo h(cpms_overhead_lease_val($formItem, 'year')); ?>">
      <input type="hidden" name="original_month" value="<?php echo h(cpms_overhead_lease_val($formItem, 'month')); ?>">
      <input type="hidden" name="lease_group_id" value="<?php echo h(cpms_overhead_lease_val($formItem, 'lease_group_id')); ?>">
      <input type="hidden" name="source_contract_period" value="<?php echo h(cpms_overhead_lease_val($formItem, 'source_contract_period')); ?>">

      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2"><?php echo $isLeaseEditing ? '기준연도' : '시작연도'; ?></span>
          <input type="number" name="year" min="2000" max="2100" value="<?php echo h($formYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" required>
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2"><?php echo $isLeaseEditing ? '기준월' : '시작월'; ?></span>
          <select name="month" class="w-full px-3 py-3 rounded-xl border border-gray-300" required>
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ((int)$formMonth === $m) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $m); ?></option>
            <?php endfor; ?>
          </select>
        </label>
        <label class="block text-sm font-bold text-gray-700 md:col-span-2">
          <span class="block mb-2">구분</span>
          <input type="text" name="title" value="<?php echo h(cpms_overhead_lease_val($formItem, 'title')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" required>
        </label>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <label class="block text-sm font-bold text-gray-700 md:col-span-2">
          <span class="block mb-2">주소</span>
          <input type="text" name="address" value="<?php echo h(cpms_overhead_lease_val($formItem, 'address')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">정</span>
          <input type="text" name="manager_primary" value="<?php echo h(cpms_overhead_lease_val($formItem, 'manager_primary')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">부</span>
          <input type="text" name="manager_secondary" value="<?php echo h(cpms_overhead_lease_val($formItem, 'manager_secondary')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">보증금</span>
          <input type="text" name="deposit" value="<?php echo h(cpms_overhead_lease_money_input(cpms_overhead_lease_val($formItem, 'deposit'))); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" placeholder="1000000">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">지급일</span>
          <input type="text" name="payment_due" value="<?php echo h(cpms_overhead_lease_val($formItem, 'payment_due')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">월세(vat포함)</span>
          <input type="text" name="amount" value="<?php echo h(cpms_overhead_lease_money_input(cpms_overhead_lease_val($formItem, 'amount'))); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" required>
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">관리비</span>
          <input type="text" name="maintenance_fee" value="<?php echo h(cpms_overhead_lease_money_input(cpms_overhead_lease_val($formItem, 'maintenance_fee'))); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">계약기간</span>
          <input type="text" name="contract_period" value="<?php echo h(cpms_overhead_lease_val($formItem, 'contract_period')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">사무실 복구의무</span>
          <input type="text" name="restoration_obligation" value="<?php echo h(cpms_overhead_lease_val($formItem, 'restoration_obligation')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">임대인</span>
          <input type="text" name="landlord" value="<?php echo h(cpms_overhead_lease_val($formItem, 'landlord')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">자동이체일</span>
          <input type="text" name="auto_transfer_day" value="<?php echo h(cpms_overhead_lease_val($formItem, 'auto_transfer_day')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">입금방법</span>
          <input type="text" name="payment_method" value="<?php echo h(cpms_overhead_lease_val($formItem, 'payment_method')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">사용 직원</span>
          <input type="text" name="employee_name" value="<?php echo h(cpms_overhead_lease_val($formItem, 'employee_name')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
      </div>

      <button type="submit" class="px-5 py-3 rounded-xl bg-emerald-700 text-white font-extrabold"><?php echo $isLeaseEditing ? '수정 저장' : '신규 추가'; ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="bg-white border border-gray-200 rounded-2xl p-4">
  <div class="font-extrabold text-gray-900 mb-3">임대차 목록</div>
  <div class="cpms-responsive-table-wrap">
    <table class="cpms-responsive-table text-xs">
      <thead>
        <tr>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">연월</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">구분</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">주소</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">정</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">부</th>
          <th class="text-right p-3 border-b border-gray-200 bg-gray-50">보증금</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">지급일</th>
          <th class="text-right p-3 border-b border-gray-200 bg-gray-50">월세(vat포함)</th>
          <th class="text-right p-3 border-b border-gray-200 bg-gray-50">관리비</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">계약기간</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">사무실 복구의무</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">임대인</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">자동이체일</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">입금방법</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">사용 직원</th>
          <th class="text-right p-3 border-b border-gray-200 bg-gray-50">월 합계</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">관리</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($items) === 0): ?>
          <tr><td colspan="17" class="p-4 text-center text-gray-500">등록된 임대차가 없습니다.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $row): ?>
          <?php
            $rowYear = isset($row['year']) ? (string)$row['year'] : '';
            $rowMonth = isset($row['month']) ? (string)$row['month'] : '';
            $rowId = isset($row['id']) ? (string)$row['id'] : '';
            $rowFormId = cpms_overhead_lease_form_id($row);
          ?>
          <tr>
            <td class="p-3 border-b border-gray-100"><?php echo h($rowYear . '/' . $rowMonth); ?></td>
            <td class="p-3 border-b border-gray-100 font-bold text-gray-900" data-wrap="1"><?php echo h(cpms_overhead_lease_val($row, 'title')); ?></td>
            <td class="p-3 border-b border-gray-100" data-wrap="1"><?php echo h(cpms_overhead_lease_val($row, 'address')); ?></td>
            <td class="p-3 border-b border-gray-100"><?php echo h(cpms_overhead_lease_val($row, 'manager_primary')); ?></td>
            <td class="p-3 border-b border-gray-100"><?php echo h(cpms_overhead_lease_val($row, 'manager_secondary')); ?></td>
            <td class="p-3 border-b border-gray-100 text-right"><?php echo h(cpms_overhead_lease_money_label(cpms_overhead_lease_val($row, 'deposit'))); ?></td>
            <td class="p-3 border-b border-gray-100"><?php echo h(cpms_overhead_lease_val($row, 'payment_due')); ?></td>
            <td class="p-3 border-b border-gray-100 text-right font-bold"><?php echo h(cpms_overhead_lease_money_label(cpms_overhead_lease_val($row, 'amount'))); ?></td>
            <td class="p-3 border-b border-gray-100 text-right">
              <?php if ($canEditCompanyOverhead): ?>
                <input form="<?php echo h($rowFormId); ?>" type="text" name="maintenance_fee" value="<?php echo h(cpms_overhead_lease_money_input(cpms_overhead_lease_val($row, 'maintenance_fee'))); ?>" class="w-28 px-2 py-2 rounded-lg border border-gray-300 text-right">
              <?php else: ?>
                <?php echo h(cpms_overhead_lease_money_label(cpms_overhead_lease_val($row, 'maintenance_fee'))); ?>
              <?php endif; ?>
            </td>
            <td class="p-3 border-b border-gray-100">
              <?php if ($canEditCompanyOverhead): ?>
                <input form="<?php echo h($rowFormId); ?>" type="text" name="contract_period" value="<?php echo h(cpms_overhead_lease_val($row, 'contract_period')); ?>" class="w-40 px-2 py-2 rounded-lg border border-gray-300">
              <?php else: ?>
                <?php echo h(cpms_overhead_lease_val($row, 'contract_period')); ?>
              <?php endif; ?>
            </td>
            <td class="p-3 border-b border-gray-100" data-wrap="1"><?php echo h(cpms_overhead_lease_val($row, 'restoration_obligation')); ?></td>
            <td class="p-3 border-b border-gray-100"><?php echo h(cpms_overhead_lease_val($row, 'landlord')); ?></td>
            <td class="p-3 border-b border-gray-100"><?php echo h(cpms_overhead_lease_val($row, 'auto_transfer_day')); ?></td>
            <td class="p-3 border-b border-gray-100" data-wrap="1"><?php echo h(cpms_overhead_lease_val($row, 'payment_method')); ?></td>
            <td class="p-3 border-b border-gray-100" data-wrap="1"><?php echo h(cpms_overhead_lease_val($row, 'employee_name')); ?></td>
            <td class="p-3 border-b border-gray-100 text-right font-extrabold"><?php echo h(cpms_overhead_view_money(cpms_overhead_lease_total($row))); ?></td>
            <td class="p-3 border-b border-gray-100">
              <?php if ($canEditCompanyOverhead): ?>
                <div class="flex flex-wrap gap-2">
                  <form id="<?php echo h($rowFormId); ?>" method="post" action="?r=management/overhead_save">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="category" value="lease">
                    <input type="hidden" name="id" value="<?php echo h($rowId); ?>">
                    <input type="hidden" name="original_year" value="<?php echo h($rowYear); ?>">
                    <input type="hidden" name="original_month" value="<?php echo h($rowMonth); ?>">
                    <input type="hidden" name="year" value="<?php echo h($rowYear); ?>">
                    <input type="hidden" name="month" value="<?php echo h($rowMonth); ?>">
                    <input type="hidden" name="amount" value="<?php echo h(cpms_overhead_lease_money_input(cpms_overhead_lease_val($row, 'amount'))); ?>">
                    <button type="submit" class="px-3 py-2 rounded-lg bg-emerald-700 text-white font-bold">저장</button>
                  </form>
                  <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=lease&edit=<?php echo urlencode($rowId); ?>&edit_year=<?php echo urlencode($rowYear); ?>&edit_month=<?php echo urlencode($rowMonth); ?>" class="px-3 py-2 rounded-lg border border-gray-300 font-bold">수정</a>
                  <form method="post" action="?r=management/overhead_delete" onsubmit="return confirm('삭제하시겠습니까?');">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="category" value="lease">
                    <input type="hidden" name="id" value="<?php echo h($rowId); ?>">
                    <input type="hidden" name="year" value="<?php echo h($rowYear); ?>">
                    <input type="hidden" name="month" value="<?php echo h($rowMonth); ?>">
                    <button type="submit" class="px-3 py-2 rounded-lg border border-red-200 text-red-700 font-bold">삭제</button>
                  </form>
                </div>
              <?php else: ?>
                <span class="text-gray-400">조회</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
