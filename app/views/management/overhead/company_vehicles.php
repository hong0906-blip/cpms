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
?>

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
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="font-extrabold text-gray-900">회사차량 엑셀 업로드</div>
        <div class="text-sm text-gray-500 mt-1">법인차량 관리대장 양식의 .xlsx 파일을 기준월 잔여금액으로 읽습니다.</div>
      </div>
      <div class="px-3 py-2 rounded-xl border <?php echo $canEditCompanyOverhead ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600'; ?> text-sm font-bold">
        <?php echo $canEditCompanyOverhead ? '업로드 가능' : '조회 전용'; ?>
      </div>
    </div>
    <?php if ($canEditCompanyOverhead): ?>
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
    <?php else: ?>
      <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">조회 권한만 있어 엑셀 업로드는 사용할 수 없습니다.</div>
    <?php endif; ?>
  </div>

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

  <details class="bg-white border border-gray-200 rounded-2xl p-4" <?php echo is_array($editVehicle) ? 'open' : ''; ?>>
    <summary class="cursor-pointer font-extrabold text-gray-900"><?php echo is_array($editVehicle) ? '회사차량 수정' : '차량 추가'; ?></summary>
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
  </details>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
      <div>
        <div class="font-extrabold text-gray-900">회사차량 목록</div>
        <div class="text-sm text-gray-500 mt-1"><?php echo h((string)$vehicleYear); ?>년 월별 납입/잔여금액 기준</div>
      </div>
    </div>
    <?php if (count($companyVehicles) === 0): ?>
      <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-center text-gray-500 font-bold">표시할 회사차량 데이터가 없습니다.</div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-[3600px] w-full border-collapse text-xs">
          <thead>
            <tr class="bg-gray-50 text-gray-700">
              <?php foreach ($vehicleFields as $field): ?>
                <th class="p-2 border text-left"><?php echo h($field['label']); ?></th>
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
              ?>
              <tr class="hover:bg-gray-50 align-top">
                <?php foreach ($vehicleFields as $fieldKey => $field): ?>
                  <?php $cellType = isset($field['type']) ? (string)$field['type'] : 'text'; ?>
                  <td class="p-2 border <?php echo ($cellType === 'money' || $cellType === 'number' || $cellType === 'int') ? 'text-right' : 'text-left'; ?>">
                    <?php echo h(cpms_vehicle_view_field_value($vehicle, $fieldKey, $field)); ?>
                  </td>
                <?php endforeach; ?>
                <td class="p-2 border">
                  <?php if ($canEditCompanyOverhead): ?>
                    <div class="flex flex-wrap gap-2">
                      <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=vehicles&year=<?php echo urlencode((string)$vehicleYear); ?>&edit_vehicle=<?php echo urlencode($vehicleId); ?>" class="px-3 py-2 rounded-lg border border-gray-300 font-bold">수정</a>
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
                    <span class="text-gray-400">조회</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <td colspan="<?php echo count($vehicleFields) + 1; ?>" class="p-3 border bg-slate-50">
                  <div class="grid grid-cols-1 xl:grid-cols-12 gap-2">
                    <?php if (count($scheduleRows) === 0): ?>
                      <div class="xl:col-span-12 text-sm text-gray-500 font-bold">선택 연도 납입 스케줄 없음</div>
                    <?php else: ?>
                      <?php foreach ($scheduleRows as $schedule): ?>
                        <div class="rounded-xl border border-gray-200 bg-white p-3">
                          <div class="font-extrabold text-gray-900"><?php echo h($schedule['month']); ?>월</div>
                          <div class="mt-1 text-xs text-gray-500">납입</div>
                          <div class="font-bold text-emerald-700"><?php echo h(cpms_vehicle_view_money($schedule['payment_amount'])); ?>원</div>
                          <div class="mt-1 text-xs text-gray-500">잔여</div>
                          <div class="font-bold"><?php echo h(cpms_vehicle_view_money($schedule['remaining_amount'])); ?>원</div>
                          <div class="mt-1 text-xs text-gray-500">운전자</div>
                          <div class="font-bold"><?php echo h($schedule['driver_name'] !== '' ? $schedule['driver_name'] : '-'); ?></div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                  <?php if ($canEditCompanyOverhead): ?>
                    <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-3">
                      <form method="post" action="?r=management/company_vehicle_payment_update" class="grid grid-cols-1 md:grid-cols-5 gap-2 items-end rounded-xl border border-gray-200 bg-white p-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo h($vehicleId); ?>">
                        <input type="hidden" name="redirect_year" value="<?php echo h((string)$vehicleYear); ?>">
                        <label class="block text-xs font-bold text-gray-700">
                          <span class="block mb-1">적용연도</span>
                          <input type="number" name="effective_year" min="2026" max="2200" value="<?php echo h((string)$vehicleYear); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300">
                        </label>
                        <label class="block text-xs font-bold text-gray-700">
                          <span class="block mb-1">적용월</span>
                          <select name="effective_month" class="w-full px-3 py-2 rounded-lg border border-gray-300">
                            <?php for ($um = 1; $um <= 12; $um++): ?>
                              <option value="<?php echo $um; ?>" <?php echo ($vehicleMonthDefault === $um) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $um); ?></option>
                            <?php endfor; ?>
                          </select>
                        </label>
                        <label class="block text-xs font-bold text-gray-700 md:col-span-2">
                          <span class="block mb-1">월 납입금액</span>
                          <input type="text" name="monthly_payment" value="<?php echo h(cpms_vehicle_view_money($currentPayment)); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300">
                        </label>
                        <button type="submit" class="px-3 py-2 rounded-lg bg-gray-900 text-white font-bold">금액 수정</button>
                      </form>
                      <form method="post" action="?r=management/company_vehicle_driver_update" class="grid grid-cols-1 md:grid-cols-5 gap-2 items-end rounded-xl border border-gray-200 bg-white p-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo h($vehicleId); ?>">
                        <input type="hidden" name="redirect_year" value="<?php echo h((string)$vehicleYear); ?>">
                        <label class="block text-xs font-bold text-gray-700">
                          <span class="block mb-1">적용연도</span>
                          <input type="number" name="effective_year" min="2026" max="2200" value="<?php echo h((string)$vehicleYear); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300">
                        </label>
                        <label class="block text-xs font-bold text-gray-700">
                          <span class="block mb-1">적용월</span>
                          <select name="effective_month" class="w-full px-3 py-2 rounded-lg border border-gray-300">
                            <?php for ($dm = 1; $dm <= 12; $dm++): ?>
                              <option value="<?php echo $dm; ?>" <?php echo ($vehicleMonthDefault === $dm) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $dm); ?></option>
                            <?php endfor; ?>
                          </select>
                        </label>
                        <label class="block text-xs font-bold text-gray-700 md:col-span-2">
                          <span class="block mb-1">운전자</span>
                          <input type="text" name="driver_name" value="<?php echo h($currentDriver); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300">
                        </label>
                        <button type="submit" class="px-3 py-2 rounded-lg bg-gray-900 text-white font-bold">운전자 수정</button>
                      </form>
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
