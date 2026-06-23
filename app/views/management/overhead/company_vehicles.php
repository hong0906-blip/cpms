<?php
/**
 * Company vehicle overhead UI.
 * PHP 5.6 compatible.
 */

$vehicleYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($vehicleYear < 2026) $vehicleYear = 2026;
$vehiclePreviewToken = isset($_GET['vehicle_preview_token']) ? trim((string)$_GET['vehicle_preview_token']) : '';
$vehiclePreview = ($canEditCompanyOverhead && $vehiclePreviewToken !== '') ? cpms_company_vehicle_get_preview($vehiclePreviewToken) : null;
$companyVehicles = cpms_company_vehicle_load_all(false);
$vehicleSummary = cpms_company_vehicle_year_summary($vehicleYear);
$vehicleYears = cpms_company_vehicle_year_options($vehicleYear);
$vehicleData = cpms_company_vehicle_read_data();
$editVehicle = null;
if ($canEditCompanyOverhead && isset($_GET['edit_vehicle'])) {
    $editVehicle = cpms_company_vehicle_find(trim((string)$_GET['edit_vehicle']));
}
$vehicleFields = cpms_company_vehicle_fields();
$vehicleHiddenFieldKeys = array(
    'sequence' => true,
    'primary_manager' => true,
    'secondary_manager' => true,
    'site_name' => true,
    'corporate_number' => true,
    'insurer' => true,
    'insurance_premium' => true,
    'age_limit' => true,
    'driver_limit' => true,
    'vehicle_type' => true,
    'interest_rate' => true,
    'paid_count' => true,
    'total_count' => true,
    'payment_day' => true,
    'principal_amount' => true,
    'total_amount' => true,
    'extra_note' => true,
    'sales_person' => true,
);
$vehicleMonthDefault = ((int)date('Y') === (int)$vehicleYear) ? (int)date('m') : 1;
if ($vehicleMonthDefault < 1 || $vehicleMonthDefault > 12) $vehicleMonthDefault = 1;

if (!function_exists('cpms_vehicle_view_money')) {
function cpms_vehicle_view_money($value) {
    $value = (float)$value;
    if (floor($value) == $value) return number_format($value, 0);
    return number_format($value, 2);
}}

if (!function_exists('cpms_vehicle_view_value')) {
function cpms_vehicle_view_value($row, $key, $default) {
    if (is_array($row) && isset($row[$key])) return $row[$key];
    return $default;
}}

if (!function_exists('cpms_vehicle_view_field_value')) {
function cpms_vehicle_view_field_value($vehicle, $key, $field) {
    $value = cpms_vehicle_view_value($vehicle, $key, '');
    $type = isset($field['type']) ? (string)$field['type'] : 'text';
    if (($type === 'money' || $type === 'number') && trim((string)$value) !== '') return cpms_vehicle_view_money($value);
    return (string)$value;
}}

if (!function_exists('cpms_vehicle_view_safe_id')) {
function cpms_vehicle_view_safe_id($value) {
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$value);
}}
?>

<style>
.cpms-vehicle-table { width:100%; min-width:0; border-collapse:collapse; table-layout:fixed; font-size:12px; line-height:1.25; }
.cpms-vehicle-table th,
.cpms-vehicle-table td { padding:5px 5px; vertical-align:top; white-space:normal; word-break:keep-all; overflow-wrap:anywhere; overflow:visible; text-overflow:clip; }
.cpms-vehicle-table th { padding:5px 5px; font-size:12px; font-weight:900; white-space:normal; word-break:keep-all; overflow:visible; text-overflow:clip; }
.cpms-vehicle-table th[data-vehicle-col="vehicle_name"],
.cpms-vehicle-table td[data-vehicle-col="vehicle_name"] { width:5%; }
.cpms-vehicle-table th[data-vehicle-col="vehicle_number"],
.cpms-vehicle-table td[data-vehicle-col="vehicle_number"] { width:6%; }
.cpms-vehicle-table th[data-vehicle-col="acquired_at"],
.cpms-vehicle-table td[data-vehicle-col="acquired_at"] { width:6%; }
.cpms-vehicle-table th[data-vehicle-col="driver_name"],
.cpms-vehicle-table td[data-vehicle-col="driver_name"] { width:5%; }
.cpms-vehicle-table th[data-vehicle-col="inspection_period"],
.cpms-vehicle-table td[data-vehicle-col="inspection_period"],
.cpms-vehicle-table th[data-vehicle-col="finance_period"],
.cpms-vehicle-table td[data-vehicle-col="finance_period"],
.cpms-vehicle-table th[data-vehicle-col="insurance_period"],
.cpms-vehicle-table td[data-vehicle-col="insurance_period"],
.cpms-vehicle-table th[data-vehicle-col="schedule_period"],
.cpms-vehicle-table td[data-vehicle-col="schedule_period"] { width:7%; }
.cpms-vehicle-table th[data-vehicle-col="note"],
.cpms-vehicle-table td[data-vehicle-col="note"] { width:6%; }
.cpms-vehicle-table th[data-vehicle-col="remaining_amount"],
.cpms-vehicle-table td[data-vehicle-col="remaining_amount"],
.cpms-vehicle-table th[data-vehicle-col="monthly_payment"],
.cpms-vehicle-table td[data-vehicle-col="monthly_payment"],
.cpms-vehicle-table th[data-vehicle-col="previous_insurance_premium"],
.cpms-vehicle-table td[data-vehicle-col="previous_insurance_premium"],
.cpms-vehicle-table th[data-vehicle-col="cancellation_penalty"],
.cpms-vehicle-table td[data-vehicle-col="cancellation_penalty"] { width:7%; }
.cpms-vehicle-table th[data-vehicle-col="toll_device_card"],
.cpms-vehicle-table td[data-vehicle-col="toll_device_card"] { width:8%; }
.cpms-vehicle-table th:last-child,
.cpms-vehicle-table td[data-vehicle-actions="1"] { width:10%; max-width:none; }
.cpms-vehicle-table td[data-vehicle-actions="1"] .flex { gap:3px; }
.cpms-vehicle-table td[data-vehicle-actions="1"] a,
.cpms-vehicle-table td[data-vehicle-actions="1"] button { padding:4px 6px; border-radius:6px; font-size:10px; line-height:1.2; }
.cpms-vehicle-table td.cpms-vehicle-modal-cell { padding:0; border:0; height:0; overflow:visible; white-space:normal; }
.cpms-vehicle-month-grid { display:grid; width:100%; grid-template-columns:repeat(auto-fit,minmax(165px,1fr)); gap:8px; max-width:none; }
.cpms-vehicle-month-card { min-width:0; min-height:92px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; padding:9px 10px; font-size:15px; line-height:1.24; color:#111827; }
.cpms-vehicle-month-card .month { font-size:16px; font-weight:900; color:#111827; margin-bottom:2px; }
.cpms-vehicle-month-card .label { color:#6b7280; font-weight:800; }
.cpms-vehicle-month-card .value { font-weight:900; overflow:visible; text-overflow:clip; white-space:normal; overflow-wrap:anywhere; }
.cpms-vehicle-month-card .pay { color:#047857; }
@media (max-width: 900px) {
  .cpms-vehicle-table { min-width:1200px; }
}
</style>

<div class="space-y-5">
  <form method="get" action="" class="bg-white border border-gray-200 rounded-2xl p-4">
    <input type="hidden" name="r" value="관리">
    <input type="hidden" name="tab" value="company_overhead">
    <input type="hidden" name="oh" value="vehicles">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">조회연도</span>
        <select name="year" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <?php foreach ($vehicleYears as $vy): ?>
            <option value="<?php echo h((string)$vy); ?>" <?php echo ((int)$vehicleYear === (int)$vy) ? 'selected' : ''; ?>><?php echo h((string)$vy); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="md:col-span-3 flex flex-wrap gap-2">
        <button type="submit" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">조회</button>
        <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=vehicles" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">초기화</a>
      </div>
    </div>
  </form>

  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
    <div class="bg-white border border-emerald-200 rounded-2xl p-4">
      <div class="text-sm text-gray-500 font-bold"><?php echo h((string)$vehicleYear); ?>년 회사차량 납입 합계</div>
      <div class="mt-2 text-2xl font-extrabold text-emerald-700"><?php echo h(cpms_vehicle_view_money(isset($vehicleSummary['total']) ? $vehicleSummary['total'] : 0)); ?>원</div>
    </div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="text-sm text-gray-500 font-bold">등록 차량</div>
      <div class="mt-2 text-2xl font-extrabold text-gray-900"><?php echo h((string)count($companyVehicles)); ?>대</div>
    </div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="text-sm text-gray-500 font-bold">업로드 기준월</div>
      <div class="mt-2 text-2xl font-extrabold text-gray-900"><?php echo h(isset($vehicleData['base_ym']) && $vehicleData['base_ym'] !== '' ? $vehicleData['base_ym'] : '-'); ?></div>
    </div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="text-sm text-gray-500 font-bold">원본 파일</div>
      <div class="mt-2 text-sm font-extrabold text-gray-900 break-words"><?php echo h(isset($vehicleData['uploaded_original_name']) && $vehicleData['uploaded_original_name'] !== '' ? $vehicleData['uploaded_original_name'] : '-'); ?></div>
    </div>
  </div>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="font-extrabold text-gray-900">회사차량 관리</div>
      <?php if ($canEditCompanyOverhead): ?>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold" data-modal-open="vehicleForm">신규추가</button>
          <button type="button" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold" data-modal-open="vehicleUpload">엑셀 업로드</button>
          <?php if (is_array($editVehicle)): ?>
            <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=vehicles&year=<?php echo urlencode((string)$vehicleYear); ?>" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">수정 취소</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 text-slate-600 text-sm font-bold">조회 전용</div>
      <?php endif; ?>
    </div>
    <?php if (!$canEditCompanyOverhead): ?>
      <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">조회 권한만 있어 등록/수정/삭제와 엑셀 업로드는 사용할 수 없습니다.</div>
    <?php endif; ?>
  </div>

  <?php if ($canEditCompanyOverhead): ?>
    <div id="modal-vehicleUpload" class="fixed inset-0 z-50 hidden">
      <div class="absolute inset-0 bg-black/40" data-modal-close="vehicleUpload"></div>
      <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-3xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;">
          <button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="vehicleUpload">닫기</button>
          <div class="font-extrabold text-gray-900">회사차량 엑셀 업로드</div>
          <form method="post" action="?r=management/company_vehicle_upload_preview" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end mt-4">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <label class="block text-sm font-bold text-gray-700">
              <span class="block mb-2">기준연도</span>
              <input type="number" name="base_year" min="2026" max="2200" value="<?php echo h((string)$vehicleYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
            </label>
            <label class="block text-sm font-bold text-gray-700">
              <span class="block mb-2">기준월</span>
              <select name="base_month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
                <?php for ($bm = 1; $bm <= 12; $bm++): ?>
                  <option value="<?php echo $bm; ?>" <?php echo ((int)$vehicleMonthDefault === $bm) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $bm); ?></option>
                <?php endfor; ?>
              </select>
            </label>
            <label class="block text-sm font-bold text-gray-700 md:col-span-2">
              <span class="block mb-2">회사차량 엑셀 파일</span>
              <input type="file" name="vehicle_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="w-full px-3 py-3 rounded-xl border border-gray-300 bg-white">
            </label>
            <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">업로드 미리보기</button>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (is_array($vehiclePreview)): ?>
    <?php
      $previewParsed = isset($vehiclePreview['parsed']) && is_array($vehiclePreview['parsed']) ? $vehiclePreview['parsed'] : array();
      $previewVehicles = isset($previewParsed['vehicles']) && is_array($previewParsed['vehicles']) ? $previewParsed['vehicles'] : array();
      $previewErrors = isset($previewParsed['errors']) && is_array($previewParsed['errors']) ? $previewParsed['errors'] : array();
    ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
      <form method="post" action="?r=management/company_vehicle_upload_confirm" onsubmit="return confirm('미리보기 결과를 회사차량 데이터로 확정 저장합니다. 진행하시겠습니까?');">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="vehicle_preview_token" value="<?php echo h($vehiclePreviewToken); ?>">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="font-extrabold text-amber-900">업로드 미리보기</div>
            <div class="text-sm text-amber-800 mt-1">
              기준월: <?php echo h(isset($vehiclePreview['base_ym']) ? $vehiclePreview['base_ym'] : ''); ?> /
              파일: <?php echo h(isset($vehiclePreview['uploaded_original_name']) ? $vehiclePreview['uploaded_original_name'] : ''); ?> /
              차량 <?php echo h((string)count($previewVehicles)); ?>대
            </div>
          </div>
          <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">확정 저장</button>
        </div>
      </form>
      <?php if (count($previewErrors) > 0): ?>
        <div class="mt-3 rounded-xl border border-amber-300 bg-white p-3">
          <div class="font-bold text-amber-800 mb-2">파싱 오류/보정</div>
          <ul class="list-disc pl-5 text-sm text-amber-800 space-y-1">
            <?php foreach (array_slice($previewErrors, 0, 30) as $err): ?><li><?php echo h($err); ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <div class="mt-3 overflow-x-auto">
        <table class="min-w-[1200px] w-full border-collapse text-xs bg-white">
          <thead>
            <tr class="bg-gray-50 text-gray-700">
              <th class="p-2 border text-left">순서</th>
              <th class="p-2 border text-left">차량명</th>
              <th class="p-2 border text-left">차량번호</th>
              <th class="p-2 border text-left">운전자</th>
              <th class="p-2 border text-left">검사유효기간</th>
              <th class="p-2 border text-left">할부기간</th>
              <th class="p-2 border text-right">잔여 금액</th>
              <th class="p-2 border text-right">월 납입금액</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($previewVehicles, 0, 20) as $pv): ?>
              <tr>
                <td class="p-2 border"><?php echo h(cpms_vehicle_view_value($pv, 'sequence', '')); ?></td>
                <td class="p-2 border"><?php echo h(cpms_vehicle_view_value($pv, 'vehicle_name', '')); ?></td>
                <td class="p-2 border"><?php echo h(cpms_vehicle_view_value($pv, 'vehicle_number', '')); ?></td>
                <td class="p-2 border"><?php echo h(cpms_vehicle_view_value($pv, 'driver_name', '')); ?></td>
                <td class="p-2 border"><?php echo h(cpms_vehicle_view_value($pv, 'inspection_period', '')); ?></td>
                <td class="p-2 border"><?php echo h(cpms_vehicle_view_value($pv, 'finance_period', '')); ?></td>
                <td class="p-2 border text-right"><?php echo h(cpms_vehicle_view_money(cpms_vehicle_view_value($pv, 'remaining_amount', 0))); ?></td>
                <td class="p-2 border text-right"><?php echo h(cpms_vehicle_view_money(cpms_vehicle_view_value($pv, 'monthly_payment', 0))); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php elseif ($vehiclePreviewToken !== ''): ?>
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 text-red-700 font-bold">미리보기 세션이 만료되었거나 찾을 수 없습니다. 다시 업로드해주세요.</div>
  <?php endif; ?>

  <?php if ($canEditCompanyOverhead): ?>
  <div id="modal-vehicleForm" class="fixed inset-0 z-50 <?php echo is_array($editVehicle) ? '' : 'hidden'; ?>">
    <div class="absolute inset-0 bg-black/40" data-modal-close="vehicleForm"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-6xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;">
        <?php if (is_array($editVehicle)): ?>
          <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=vehicles&year=<?php echo urlencode((string)$vehicleYear); ?>" class="absolute right-4 top-4 px-3 py-1 border rounded-xl">닫기</a>
        <?php else: ?>
          <button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="vehicleForm">닫기</button>
        <?php endif; ?>
        <div class="font-extrabold text-gray-900"><?php echo is_array($editVehicle) ? '회사차량 수정' : '회사차량 신규추가'; ?></div>
    <?php if (!$canEditCompanyOverhead): ?>
      <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">조회 권한만 있어 등록/수정/삭제는 사용할 수 없습니다.</div>
    <?php else: ?>
      <?php
        $formVehicle = is_array($editVehicle) ? $editVehicle : array();
        $formBaseYm = cpms_vehicle_view_value($formVehicle, 'baseline_ym', sprintf('%04d-%02d', $vehicleYear, $vehicleMonthDefault));
        $formBaseYear = cpms_company_vehicle_ym_valid($formBaseYm) ? substr($formBaseYm, 0, 4) : (string)$vehicleYear;
        $formBaseMonth = cpms_company_vehicle_ym_valid($formBaseYm) ? substr($formBaseYm, 5, 2) : sprintf('%02d', $vehicleMonthDefault);
      ?>
      <form method="post" action="?r=management/company_vehicle_save" class="mt-4 space-y-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo h(cpms_vehicle_view_value($formVehicle, 'id', '')); ?>">
        <input type="hidden" name="redirect_year" value="<?php echo h((string)$vehicleYear); ?>">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <label class="block text-sm font-bold text-gray-700">
            <span class="block mb-2">잔여금액 기준연도</span>
            <input type="number" name="base_year" min="2026" max="2200" value="<?php echo h($formBaseYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          </label>
          <label class="block text-sm font-bold text-gray-700">
            <span class="block mb-2">잔여금액 기준월</span>
            <select name="base_month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
              <?php for ($fm = 1; $fm <= 12; $fm++): ?>
                <option value="<?php echo $fm; ?>" <?php echo ((int)$formBaseMonth === $fm) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $fm); ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <?php if (is_array($editVehicle)): ?>
            <div class="md:col-span-2 flex items-end">
              <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=vehicles&year=<?php echo urlencode((string)$vehicleYear); ?>" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">수정 취소</a>
            </div>
          <?php endif; ?>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <?php foreach ($vehicleFields as $fieldKey => $field): ?>
            <?php
              $fieldType = isset($field['type']) ? (string)$field['type'] : 'text';
              $inputValue = cpms_vehicle_view_value($formVehicle, $fieldKey, '');
              if (($fieldType === 'money' || $fieldType === 'number') && trim((string)$inputValue) !== '') $inputValue = cpms_vehicle_view_money($inputValue);
            ?>
            <label class="block text-sm font-bold text-gray-700">
              <span class="block mb-2"><?php echo h($field['label']); ?></span>
              <input type="text" name="<?php echo h($fieldKey); ?>" value="<?php echo h($inputValue); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
            </label>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="px-5 py-3 rounded-xl bg-emerald-700 text-white font-extrabold"><?php echo is_array($editVehicle) ? '수정 저장' : '차량 추가'; ?></button>
      </form>
    <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
      <div>
        <div class="font-extrabold text-gray-900">회사차량 목록</div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="px-3 py-2 rounded-xl border border-gray-300 text-sm font-bold" data-vehicle-cols="hide">전체 숨기기</button>
        <button type="button" class="px-3 py-2 rounded-xl border border-gray-300 text-sm font-bold" data-vehicle-cols="show">전체 보이기</button>
      </div>
    </div>
    <?php if (count($companyVehicles) === 0): ?>
      <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-center text-gray-500 font-bold">표시할 회사차량 데이터가 없습니다.</div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="cpms-vehicle-table">
          <thead>
            <tr class="bg-gray-50 text-gray-700">
              <?php foreach ($vehicleFields as $fieldKey => $field): ?>
                <?php $vehicleFieldHidden = isset($vehicleHiddenFieldKeys[$fieldKey]); ?>
                <th class="p-2 border text-left <?php echo $vehicleFieldHidden ? 'cpms-vehicle-toggle-col hidden' : ''; ?>" data-vehicle-col="<?php echo h($fieldKey); ?>"><?php echo h($field['label']); ?></th>
              <?php endforeach; ?>
              <th class="p-2 border text-left">관리</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($companyVehicles as $vehicle): ?>
              <?php
                $vehicleId = cpms_vehicle_view_value($vehicle, 'id', '');
                $scheduleRows = cpms_company_vehicle_schedule_for_year($vehicle, $vehicleYear);
                $currentYm = sprintf('%04d-%02d', $vehicleYear, $vehicleMonthDefault);
                $currentPayment = cpms_company_vehicle_payment_for_month($vehicle, $currentYm);
                $currentDriver = cpms_company_vehicle_driver_for_month($vehicle, $currentYm);
                $vehicleModalSafeId = cpms_vehicle_view_safe_id($vehicleId);
                if ($vehicleModalSafeId === '') $vehicleModalSafeId = 'vehicle_' . md5($vehicleId);
                $vehicleScheduleModalKey = 'vehicleSchedule_' . $vehicleModalSafeId;
                $vehiclePaymentModalKey = 'vehiclePayment_' . $vehicleModalSafeId;
                $vehicleDriverModalKey = 'vehicleDriver_' . $vehicleModalSafeId;
              ?>
              <tr class="hover:bg-gray-50 align-top">
                <?php foreach ($vehicleFields as $fieldKey => $field): ?>
                  <?php $cellType = isset($field['type']) ? (string)$field['type'] : 'text'; ?>
                  <?php $vehicleFieldHidden = isset($vehicleHiddenFieldKeys[$fieldKey]); ?>
                  <td class="p-2 border <?php echo ($cellType === 'money' || $cellType === 'number' || $cellType === 'int') ? 'text-right' : 'text-left'; ?> <?php echo $vehicleFieldHidden ? 'cpms-vehicle-toggle-col hidden' : ''; ?>" data-vehicle-col="<?php echo h($fieldKey); ?>">
                    <?php echo h(cpms_vehicle_view_field_value($vehicle, $fieldKey, $field)); ?>
                  </td>
                <?php endforeach; ?>
                <td class="p-2 border" data-vehicle-actions="1">
                  <?php if ($canEditCompanyOverhead): ?>
                    <div class="flex flex-wrap gap-2">
                      <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=vehicles&year=<?php echo urlencode((string)$vehicleYear); ?>&edit_vehicle=<?php echo urlencode($vehicleId); ?>" class="px-3 py-2 rounded-lg border border-gray-300 font-bold">수정</a>
                      <button type="button" class="px-3 py-2 rounded-lg border border-emerald-200 text-emerald-700 font-bold" data-modal-open="<?php echo h($vehicleScheduleModalKey); ?>">납입스케줄</button>
                      <button type="button" class="px-3 py-2 rounded-lg border border-blue-200 text-blue-700 font-bold" data-modal-open="<?php echo h($vehiclePaymentModalKey); ?>">금액수정</button>
                      <button type="button" class="px-3 py-2 rounded-lg border border-indigo-200 text-indigo-700 font-bold" data-modal-open="<?php echo h($vehicleDriverModalKey); ?>">운전자수정</button>
                      <form method="post" action="?r=management/company_vehicle_inspection_advance" onsubmit="return confirm('검사유효기간을 다음 주기로 변경합니다. 진행하시겠습니까?');">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo h($vehicleId); ?>">
                        <input type="hidden" name="year" value="<?php echo h((string)$vehicleYear); ?>">
                        <button type="submit" class="px-3 py-2 rounded-lg border border-emerald-200 text-emerald-700 font-bold">검사</button>
                      </form>
                      <form method="post" action="?r=management/company_vehicle_delete" onsubmit="return confirm('회사차량을 삭제하시겠습니까?');">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo h($vehicleId); ?>">
                        <input type="hidden" name="year" value="<?php echo h((string)$vehicleYear); ?>">
                        <button type="submit" class="px-3 py-2 rounded-lg border border-red-200 text-red-700 font-bold">삭제</button>
                      </form>
                    </div>
                  <?php else: ?>
                    <div class="flex flex-wrap gap-2">
                      <button type="button" class="px-3 py-2 rounded-lg border border-emerald-200 text-emerald-700 font-bold" data-modal-open="<?php echo h($vehicleScheduleModalKey); ?>">납입스케줄</button>
                      <span class="text-gray-400">조회</span>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <td colspan="<?php echo count($vehicleFields) + 1; ?>" class="cpms-vehicle-modal-cell">
                  <div id="modal-<?php echo h($vehicleScheduleModalKey); ?>" class="fixed inset-0 z-50 hidden">
                    <div class="absolute inset-0 bg-black/40" data-modal-close="<?php echo h($vehicleScheduleModalKey); ?>"></div>
                    <div class="absolute inset-0 flex items-center justify-center p-4">
                      <div class="bg-white rounded-3xl p-6" style="width:100%;max-width:96vw;max-height:90vh;overflow-y:auto;position:relative;">
                        <button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="<?php echo h($vehicleScheduleModalKey); ?>">닫기</button>
                        <div class="font-extrabold text-gray-900"><?php echo h((string)$vehicleYear); ?>년 납입스케줄</div>
                        <div class="mt-1 text-sm text-gray-500 font-bold"><?php echo h(cpms_vehicle_view_field_value($vehicle, 'vehicle_name', isset($vehicleFields['vehicle_name']) ? $vehicleFields['vehicle_name'] : array())); ?> / <?php echo h(cpms_vehicle_view_field_value($vehicle, 'vehicle_number', isset($vehicleFields['vehicle_number']) ? $vehicleFields['vehicle_number'] : array())); ?></div>
                        <div class="mt-5 cpms-vehicle-month-grid">
                          <?php if (count($scheduleRows) === 0): ?>
                            <div class="text-sm text-gray-500 font-bold">선택 연도 납입 스케줄 없음</div>
                          <?php else: ?>
                            <?php foreach ($scheduleRows as $schedule): ?>
                              <div class="cpms-vehicle-month-card">
                                <div class="month"><?php echo h($schedule['month']); ?>월</div>
                                <div><span class="label">납입</span> <span class="value pay"><?php echo h(cpms_vehicle_view_money($schedule['payment_amount'])); ?></span></div>
                                <div><span class="label">잔여</span> <span class="value"><?php echo h(cpms_vehicle_view_money($schedule['remaining_amount'])); ?></span></div>
                                <div class="value"><span class="label">운전</span> <?php echo h($schedule['driver_name'] !== '' ? $schedule['driver_name'] : '-'); ?></div>
                              </div>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                  <?php if ($canEditCompanyOverhead): ?>
                    <div id="modal-<?php echo h($vehiclePaymentModalKey); ?>" class="fixed inset-0 z-50 hidden">
                      <div class="absolute inset-0 bg-black/40" data-modal-close="<?php echo h($vehiclePaymentModalKey); ?>"></div>
                      <div class="absolute inset-0 flex items-center justify-center p-4">
                        <div class="w-full max-w-xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;">
                          <button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="<?php echo h($vehiclePaymentModalKey); ?>">닫기</button>
                          <div class="font-extrabold text-gray-900">월 납입금액 수정</div>
                          <div class="mt-1 text-sm text-gray-500 font-bold"><?php echo h(cpms_vehicle_view_field_value($vehicle, 'vehicle_name', isset($vehicleFields['vehicle_name']) ? $vehicleFields['vehicle_name'] : array())); ?> / <?php echo h(cpms_vehicle_view_field_value($vehicle, 'vehicle_number', isset($vehicleFields['vehicle_number']) ? $vehicleFields['vehicle_number'] : array())); ?></div>
                          <form method="post" action="?r=management/company_vehicle_payment_update" class="mt-5 space-y-4">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php echo h($vehicleId); ?>">
                            <input type="hidden" name="redirect_year" value="<?php echo h((string)$vehicleYear); ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                              <label class="block text-sm font-bold text-gray-700">
                                <span class="block mb-2">적용연도</span>
                                <input type="number" name="effective_year" min="2026" max="2200" value="<?php echo h((string)$vehicleYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
                              </label>
                              <label class="block text-sm font-bold text-gray-700">
                                <span class="block mb-2">적용월</span>
                                <select name="effective_month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
                                  <?php for ($um = 1; $um <= 12; $um++): ?>
                                    <option value="<?php echo $um; ?>" <?php echo ($vehicleMonthDefault === $um) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $um); ?></option>
                                  <?php endfor; ?>
                                </select>
                              </label>
                            </div>
                            <label class="block text-sm font-bold text-gray-700">
                              <span class="block mb-2">월 납입금액</span>
                              <input type="text" name="monthly_payment" value="<?php echo h(cpms_vehicle_view_money($currentPayment)); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
                            </label>
                            <div class="flex justify-end gap-2">
                              <button type="button" class="px-4 py-3 rounded-xl border border-gray-300 font-bold" data-modal-close="<?php echo h($vehiclePaymentModalKey); ?>">취소</button>
                              <button type="submit" class="px-5 py-3 rounded-xl bg-gray-900 text-white font-extrabold">금액 수정</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>
                    <div id="modal-<?php echo h($vehicleDriverModalKey); ?>" class="fixed inset-0 z-50 hidden">
                      <div class="absolute inset-0 bg-black/40" data-modal-close="<?php echo h($vehicleDriverModalKey); ?>"></div>
                      <div class="absolute inset-0 flex items-center justify-center p-4">
                        <div class="w-full max-w-xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;">
                          <button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="<?php echo h($vehicleDriverModalKey); ?>">닫기</button>
                          <div class="font-extrabold text-gray-900">운전자 수정</div>
                          <div class="mt-1 text-sm text-gray-500 font-bold"><?php echo h(cpms_vehicle_view_field_value($vehicle, 'vehicle_name', isset($vehicleFields['vehicle_name']) ? $vehicleFields['vehicle_name'] : array())); ?> / <?php echo h(cpms_vehicle_view_field_value($vehicle, 'vehicle_number', isset($vehicleFields['vehicle_number']) ? $vehicleFields['vehicle_number'] : array())); ?></div>
                          <form method="post" action="?r=management/company_vehicle_driver_update" class="mt-5 space-y-4">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php echo h($vehicleId); ?>">
                            <input type="hidden" name="redirect_year" value="<?php echo h((string)$vehicleYear); ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                              <label class="block text-sm font-bold text-gray-700">
                                <span class="block mb-2">적용연도</span>
                                <input type="number" name="effective_year" min="2026" max="2200" value="<?php echo h((string)$vehicleYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
                              </label>
                              <label class="block text-sm font-bold text-gray-700">
                                <span class="block mb-2">적용월</span>
                                <select name="effective_month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
                                  <?php for ($dm = 1; $dm <= 12; $dm++): ?>
                                    <option value="<?php echo $dm; ?>" <?php echo ($vehicleMonthDefault === $dm) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $dm); ?></option>
                                  <?php endfor; ?>
                                </select>
                              </label>
                            </div>
                            <label class="block text-sm font-bold text-gray-700">
                              <span class="block mb-2">운전자</span>
                              <input type="text" name="driver_name" value="<?php echo h($currentDriver); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
                            </label>
                            <div class="flex justify-end gap-2">
                              <button type="button" class="px-4 py-3 rounded-xl border border-gray-300 font-bold" data-modal-close="<?php echo h($vehicleDriverModalKey); ?>">취소</button>
                              <button type="submit" class="px-5 py-3 rounded-xl bg-gray-900 text-white font-extrabold">운전자 수정</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  function openModal(name) {
    var modal = document.getElementById('modal-' + name);
    if (modal) modal.classList.remove('hidden');
  }
  function closeModal(name) {
    var modal = document.getElementById('modal-' + name);
    if (modal) modal.classList.add('hidden');
  }

  var modalOpenButtons = document.querySelectorAll('[data-modal-open]');
  for (var m = 0; m < modalOpenButtons.length; m++) {
    modalOpenButtons[m].onclick = function () {
      openModal(this.getAttribute('data-modal-open') || '');
    };
  }
  var modalCloseButtons = document.querySelectorAll('[data-modal-close]');
  for (var n = 0; n < modalCloseButtons.length; n++) {
    modalCloseButtons[n].onclick = function () {
      closeModal(this.getAttribute('data-modal-close') || '');
    };
  }

  function setVehicleColumns(show) {
    var cells = document.querySelectorAll('.cpms-vehicle-toggle-col');
    for (var i = 0; i < cells.length; i++) {
      if (show) cells[i].classList.remove('hidden');
      else cells[i].classList.add('hidden');
    }
  }
  var buttons = document.querySelectorAll('[data-vehicle-cols]');
  for (var j = 0; j < buttons.length; j++) {
    buttons[j].onclick = function () {
      setVehicleColumns((this.getAttribute('data-vehicle-cols') || '') === 'show');
    };
  }
})();
</script>
