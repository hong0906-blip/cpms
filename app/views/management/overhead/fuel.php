<?php
/**
 * Company overhead fuel UI.
 * PHP 5.6 compatible.
 */

$fuelYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$fuelMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$fuelYm = cpms_company_fuel_normalize_year_month($fuelYear, $fuelMonth);
$fuelYear = (int)$fuelYm['year'];
$fuelMonth = (int)$fuelYm['month'];
$fuelPreviewToken = isset($_GET['preview_token']) ? trim((string)$_GET['preview_token']) : '';
$fuelPreview = ($canEditCompanyOverhead && $fuelPreviewToken !== '') ? cpms_company_fuel_get_preview($fuelPreviewToken) : null;
$fuelData = cpms_company_fuel_load_month($fuelYear, $fuelMonth);
$fuelItemsAll = (is_array($fuelData) && isset($fuelData['items']) && is_array($fuelData['items'])) ? $fuelData['items'] : array();
$fuelItemsAll = cpms_company_fuel_refresh_matches($fuelItemsAll, isset($overheadPdo) ? $overheadPdo : null);
$fuelFilters = array(
    'name' => isset($_GET['fuel_name']) ? trim((string)$_GET['fuel_name']) : '',
    'vehicle_number' => isset($_GET['fuel_vehicle_number']) ? trim((string)$_GET['fuel_vehicle_number']) : '',
    'product_name' => isset($_GET['fuel_product_name']) ? trim((string)$_GET['fuel_product_name']) : '',
    'matched_type' => isset($_GET['fuel_matched_type']) ? trim((string)$_GET['fuel_matched_type']) : '',
    'q' => isset($_GET['fuel_q']) ? trim((string)$_GET['fuel_q']) : '',
);
$fuelItems = cpms_company_fuel_filter_items($fuelItemsAll, $fuelFilters);
$fuelSummary = cpms_company_fuel_summary_from_items($fuelItemsAll);

if (!function_exists('cpms_fuel_view_money')) {
function cpms_fuel_view_money($value) {
    $value = (float)$value;
    if (floor($value) == $value) return number_format($value, 0);
    return number_format($value, 2);
}}

if (!function_exists('cpms_fuel_view_number')) {
function cpms_fuel_view_number($value) {
    $value = (float)$value;
    $text = number_format($value, 3, '.', '');
    $text = rtrim(rtrim($text, '0'), '.');
    return $text === '' ? '0' : $text;
}}

if (!function_exists('cpms_fuel_matched_label')) {
function cpms_fuel_matched_label($type) {
    $type = (string)$type;
    if ($type === 'employee') return '직원';
    if ($type === 'company_vehicle') return '회사차량';
    if ($type === 'vehicle_number') return '미매칭';
    return '알 수 없음';
}}

if (!function_exists('cpms_fuel_group_items_by_name')) {
function cpms_fuel_group_items_by_name($items) {
    $groups = array();
    if (!is_array($items)) $items = array();
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $name = isset($item['display_name']) ? trim((string)$item['display_name']) : '';
        $vehicle = isset($item['vehicle_number']) ? trim((string)$item['vehicle_number']) : '';
        if ($name === '') $name = $vehicle !== '' ? $vehicle : '알 수 없음';
        $key = $name;
        if (!isset($groups[$key])) {
            $groups[$key] = array(
                'display_name' => $name,
                'vehicles' => array(),
                'items' => array(),
                'row_count' => 0,
                'total_supply_amount' => 0.0,
                'total_vat' => 0.0,
                'total_amount' => 0.0,
                'matched_types' => array(),
            );
        }
        if ($vehicle !== '') $groups[$key]['vehicles'][cpms_normalize_vehicle_number($vehicle)] = $vehicle;
        $matchedType = isset($item['matched_type']) ? (string)$item['matched_type'] : '';
        if ($matchedType !== '') $groups[$key]['matched_types'][$matchedType] = true;
        $groups[$key]['items'][] = $item;
        $groups[$key]['row_count']++;
        $groups[$key]['total_supply_amount'] += isset($item['supply_amount']) ? (float)$item['supply_amount'] : 0.0;
        $groups[$key]['total_vat'] += isset($item['vat']) ? (float)$item['vat'] : 0.0;
        $groups[$key]['total_amount'] += isset($item['total_amount']) ? (float)$item['total_amount'] : 0.0;
    }
    uasort($groups, 'cpms_fuel_group_sort');
    return $groups;
}}

if (!function_exists('cpms_fuel_group_sort')) {
function cpms_fuel_group_sort($a, $b) {
    $an = isset($a['display_name']) ? (string)$a['display_name'] : '';
    $bn = isset($b['display_name']) ? (string)$b['display_name'] : '';
    if ($an === $bn) return 0;
    return ($an < $bn) ? -1 : 1;
}}

$fuelGroups = cpms_fuel_group_items_by_name($fuelItems);
?>

<div class="space-y-5">
  <form method="get" action="" class="bg-white border border-gray-200 rounded-2xl p-4">
    <input type="hidden" name="r" value="관리">
    <input type="hidden" name="tab" value="company_overhead">
    <input type="hidden" name="oh" value="fuel">
    <div class="grid grid-cols-1 md:grid-cols-7 gap-3 items-end">
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">적용연도</span>
        <input type="number" name="year" min="2000" max="2100" value="<?php echo h((string)$fuelYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">적용월</span>
        <select name="month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo ((int)$fuelMonth === $m) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $m); ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">이름</span>
        <input type="text" name="fuel_name" value="<?php echo h($fuelFilters['name']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">차량번호</span>
        <input type="text" name="fuel_vehicle_number" value="<?php echo h($fuelFilters['vehicle_number']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">상품명</span>
        <input type="text" name="fuel_product_name" value="<?php echo h($fuelFilters['product_name']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">매칭상태</span>
        <select name="fuel_matched_type" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <option value="">전체</option>
          <?php foreach (array('employee' => '직원', 'company_vehicle' => '회사차량', 'vehicle_number' => '미매칭', 'unknown' => '알 수 없음') as $mtKey => $mtLabel): ?>
            <option value="<?php echo h($mtKey); ?>" <?php echo $fuelFilters['matched_type'] === $mtKey ? 'selected' : ''; ?>><?php echo h($mtLabel); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">검색어</span>
        <input type="text" name="fuel_q" value="<?php echo h($fuelFilters['q']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
      <button type="submit" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">조회</button>
      <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=fuel&year=<?php echo urlencode((string)$fuelYear); ?>&month=<?php echo urlencode((string)$fuelMonth); ?>" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">필터 초기화</a>
    </div>
  </form>

  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-7 gap-3">
    <div class="bg-white border border-emerald-200 rounded-2xl p-4"><div class="text-xs font-bold text-gray-500">총 합계금액</div><div class="mt-2 text-xl font-extrabold text-emerald-700"><?php echo h(cpms_fuel_view_money($fuelSummary['total_amount'])); ?>원</div></div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4"><div class="text-xs font-bold text-gray-500">총 공급가액</div><div class="mt-2 text-xl font-extrabold"><?php echo h(cpms_fuel_view_money($fuelSummary['total_supply_amount'])); ?>원</div></div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4"><div class="text-xs font-bold text-gray-500">총 부가세</div><div class="mt-2 text-xl font-extrabold"><?php echo h(cpms_fuel_view_money($fuelSummary['total_vat'])); ?>원</div></div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4"><div class="text-xs font-bold text-gray-500">거래 건수</div><div class="mt-2 text-xl font-extrabold"><?php echo h((string)$fuelSummary['row_count']); ?>건</div></div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4"><div class="text-xs font-bold text-gray-500">차량 수</div><div class="mt-2 text-xl font-extrabold"><?php echo h((string)$fuelSummary['vehicle_count']); ?>대</div></div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4"><div class="text-xs font-bold text-gray-500">직원 매칭 수</div><div class="mt-2 text-xl font-extrabold"><?php echo h((string)$fuelSummary['employee_matched_vehicle_count']); ?>대</div></div>
    <div class="bg-white border border-gray-200 rounded-2xl p-4"><div class="text-xs font-bold text-gray-500">미매칭 차량 수</div><div class="mt-2 text-xl font-extrabold <?php echo ((int)$fuelSummary['unmatched_vehicle_count'] > 0) ? 'text-amber-700' : ''; ?>"><?php echo h((string)$fuelSummary['unmatched_vehicle_count']); ?>대</div></div>
  </div>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="font-extrabold text-gray-900">주유비 엑셀 업로드</div>
        <div class="text-sm text-gray-500 mt-1">A:I, K:S 좌우 거래명세서 표를 함께 읽고 차량번호 기준으로 직원명부와 매칭합니다.</div>
      </div>
      <div class="px-3 py-2 rounded-xl border <?php echo $canEditCompanyOverhead ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600'; ?> text-sm font-bold">
        <?php echo $canEditCompanyOverhead ? '업로드 가능' : '조회 전용'; ?>
      </div>
    </div>
    <?php if ($canEditCompanyOverhead): ?>
      <form method="post" action="?r=management/fuel_upload_preview" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end mt-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">적용연도</span>
          <input type="number" name="apply_year" min="2000" max="2100" value="<?php echo h((string)$fuelYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">적용월</span>
          <select name="apply_month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
            <?php for ($am = 1; $am <= 12; $am++): ?>
              <option value="<?php echo $am; ?>" <?php echo ((int)$fuelMonth === $am) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $am); ?></option>
            <?php endfor; ?>
          </select>
        </label>
        <label class="block text-sm font-bold text-gray-700 md:col-span-2">
          <span class="block mb-2">주유비 엑셀 파일</span>
          <input type="file" name="fuel_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="w-full px-3 py-3 rounded-xl border border-gray-300 bg-white">
        </label>
        <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">업로드 미리보기</button>
      </form>
    <?php else: ?>
      <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">조회 권한만 있어 엑셀 업로드와 삭제는 사용할 수 없습니다.</div>
    <?php endif; ?>
  </div>

  <?php if (is_array($fuelPreview)): ?>
    <?php
      $previewParsed = isset($fuelPreview['parsed']) && is_array($fuelPreview['parsed']) ? $fuelPreview['parsed'] : array();
      $previewItems = isset($previewParsed['items']) && is_array($previewParsed['items']) ? $previewParsed['items'] : array();
      $previewErrors = isset($previewParsed['errors']) && is_array($previewParsed['errors']) ? $previewParsed['errors'] : array();
      $existingFuelData = cpms_company_fuel_load_month(isset($fuelPreview['year']) ? $fuelPreview['year'] : $fuelYear, isset($fuelPreview['month']) ? $fuelPreview['month'] : $fuelMonth);
    ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
      <form method="post" action="?r=management/fuel_upload_confirm" onsubmit="return confirm('이 미리보기 결과를 주유비 데이터로 확정 저장합니다. 같은 적용월의 기존 데이터는 history로 백업됩니다. 진행하시겠습니까?');">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="preview_token" value="<?php echo h($fuelPreviewToken); ?>">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="font-extrabold text-amber-900">업로드 미리보기</div>
            <div class="text-sm text-amber-800 mt-1">
              적용월: <?php echo h(isset($fuelPreview['year']) ? $fuelPreview['year'] : ''); ?>년 <?php echo h(isset($fuelPreview['month']) ? $fuelPreview['month'] : ''); ?>월 /
              파일: <?php echo h(isset($fuelPreview['uploaded_original_name']) ? $fuelPreview['uploaded_original_name'] : ''); ?> /
              거래 <?php echo h((string)count($previewItems)); ?>건 /
              합계 <?php echo h(cpms_fuel_view_money(isset($previewParsed['total_amount']) ? $previewParsed['total_amount'] : 0)); ?>원
            </div>
            <?php if (is_array($existingFuelData)): ?>
              <div class="text-sm text-red-700 font-bold mt-2">이미 같은 적용월의 주유비 데이터가 있습니다. 확정 저장하면 기존 JSON은 history로 백업되고 새 데이터로 교체됩니다.</div>
            <?php endif; ?>
            <?php if (!empty($previewParsed['unmatched_vehicle_numbers'])): ?>
              <div class="text-sm text-amber-800 mt-2">미매칭 차량번호: <?php echo h(implode(', ', $previewParsed['unmatched_vehicle_numbers'])); ?></div>
            <?php endif; ?>
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
    </div>
  <?php elseif ($fuelPreviewToken !== ''): ?>
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 text-red-700 font-bold">미리보기 세션이 만료되었거나 찾을 수 없습니다. 다시 업로드해주세요.</div>
  <?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
      <div>
        <div class="font-extrabold text-gray-900">주유비 목록</div>
        <div class="text-sm text-gray-500 mt-1">
          <?php echo h(sprintf('%04d/%02d', $fuelYear, $fuelMonth)); ?>
          <?php if (is_array($fuelData)): ?>
            / 업로드: <?php echo h(isset($fuelData['uploaded_at']) ? $fuelData['uploaded_at'] : '-'); ?>
            / 파일: <?php echo h(isset($fuelData['uploaded_original_name']) ? $fuelData['uploaded_original_name'] : '-'); ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <?php if (is_array($fuelData) && !empty($fuelData['uploaded_drive_web_view_link'])): ?>
          <a target="_blank" rel="noopener" href="<?php echo h($fuelData['uploaded_drive_web_view_link']); ?>" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">원본 Drive 보기</a>
        <?php endif; ?>
        <?php if ($canEditCompanyOverhead && is_array($fuelData)): ?>
          <form method="post" action="?r=management/fuel_delete" onsubmit="return confirm('선택 월 주유비 데이터를 삭제합니다. 현재 JSON은 history로 백업됩니다. 진행하시겠습니까?');">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="year" value="<?php echo h((string)$fuelYear); ?>">
            <input type="hidden" name="month" value="<?php echo h((string)$fuelMonth); ?>">
            <button type="submit" class="px-4 py-3 rounded-xl border border-red-200 text-red-700 font-extrabold">선택 월 삭제</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php if (count($fuelItems) === 0): ?>
      <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-center text-gray-500 font-bold">표시할 주유비 데이터가 없습니다.</div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($fuelGroups as $fuelGroup): ?>
          <?php
            $groupVehicles = isset($fuelGroup['vehicles']) && is_array($fuelGroup['vehicles']) ? array_values($fuelGroup['vehicles']) : array();
            $groupItems = isset($fuelGroup['items']) && is_array($fuelGroup['items']) ? $fuelGroup['items'] : array();
            $groupMatchedLabels = array();
            if (isset($fuelGroup['matched_types']) && is_array($fuelGroup['matched_types'])) {
                foreach ($fuelGroup['matched_types'] as $mt => $unused) $groupMatchedLabels[] = cpms_fuel_matched_label($mt);
            }
          ?>
          <section class="border border-gray-200 rounded-2xl overflow-hidden bg-white">
            <div class="p-4 bg-slate-50 border-b border-gray-200">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div class="text-lg font-extrabold text-gray-900"><?php echo h(isset($fuelGroup['display_name']) ? $fuelGroup['display_name'] : ''); ?></div>
                  <div class="mt-1 text-sm text-gray-600">차량번호: <?php echo h(count($groupVehicles) > 0 ? implode(', ', $groupVehicles) : '-'); ?></div>
                  <div class="mt-1 text-xs text-gray-500">매칭상태: <?php echo h(count($groupMatchedLabels) > 0 ? implode(', ', $groupMatchedLabels) : '-'); ?></div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-right">
                  <div class="rounded-xl border border-gray-200 bg-white px-3 py-2"><div class="text-xs text-gray-500 font-bold">거래</div><div class="font-extrabold"><?php echo h((string)(isset($fuelGroup['row_count']) ? $fuelGroup['row_count'] : count($groupItems))); ?>건</div></div>
                  <div class="rounded-xl border border-gray-200 bg-white px-3 py-2"><div class="text-xs text-gray-500 font-bold">공급가액</div><div class="font-extrabold"><?php echo h(cpms_fuel_view_money(isset($fuelGroup['total_supply_amount']) ? $fuelGroup['total_supply_amount'] : 0)); ?>원</div></div>
                  <div class="rounded-xl border border-gray-200 bg-white px-3 py-2"><div class="text-xs text-gray-500 font-bold">부가세</div><div class="font-extrabold"><?php echo h(cpms_fuel_view_money(isset($fuelGroup['total_vat']) ? $fuelGroup['total_vat'] : 0)); ?>원</div></div>
                  <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2"><div class="text-xs text-emerald-700 font-bold">합계금액</div><div class="font-extrabold text-emerald-700"><?php echo h(cpms_fuel_view_money(isset($fuelGroup['total_amount']) ? $fuelGroup['total_amount'] : 0)); ?>원</div></div>
                </div>
              </div>
            </div>
            <div class="overflow-x-auto">
              <table class="min-w-[1200px] w-full border-collapse text-xs">
                <thead>
                  <tr class="bg-gray-50 text-gray-700">
                    <th class="p-2 border text-left">날짜</th>
                    <th class="p-2 border text-left">차량번호</th>
                    <th class="p-2 border text-left">상품명</th>
                    <th class="p-2 border text-left">단위</th>
                    <th class="p-2 border text-right">수량</th>
                    <th class="p-2 border text-right">단가</th>
                    <th class="p-2 border text-right">공급가액</th>
                    <th class="p-2 border text-right">부가세</th>
                    <th class="p-2 border text-right">합계금액</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($groupItems as $fuelRow): ?>
                    <tr class="hover:bg-gray-50">
                      <td class="p-2 border"><?php echo h(isset($fuelRow['date']) ? $fuelRow['date'] : ''); ?></td>
                      <td class="p-2 border"><?php echo h(isset($fuelRow['vehicle_number']) ? $fuelRow['vehicle_number'] : ''); ?></td>
                      <td class="p-2 border"><?php echo h(isset($fuelRow['product_name']) ? $fuelRow['product_name'] : ''); ?></td>
                      <td class="p-2 border"><?php echo h(isset($fuelRow['unit']) ? $fuelRow['unit'] : ''); ?></td>
                      <td class="p-2 border text-right"><?php echo h(cpms_fuel_view_number(isset($fuelRow['quantity']) ? $fuelRow['quantity'] : 0)); ?></td>
                      <td class="p-2 border text-right"><?php echo h(cpms_fuel_view_money(isset($fuelRow['unit_price']) ? $fuelRow['unit_price'] : 0)); ?></td>
                      <td class="p-2 border text-right"><?php echo h(cpms_fuel_view_money(isset($fuelRow['supply_amount']) ? $fuelRow['supply_amount'] : 0)); ?></td>
                      <td class="p-2 border text-right"><?php echo h(cpms_fuel_view_money(isset($fuelRow['vat']) ? $fuelRow['vat'] : 0)); ?></td>
                      <td class="p-2 border text-right font-extrabold text-emerald-700"><?php echo h(cpms_fuel_view_money(isset($fuelRow['total_amount']) ? $fuelRow['total_amount'] : 0)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
