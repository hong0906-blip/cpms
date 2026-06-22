<?php
/**
 * Corporate card overhead UI.
 * PHP 5.6 compatible.
 */

$cardYear = isset($filters['year']) ? (int)$filters['year'] : (int)date('Y');
$cardMonth = isset($filters['month']) && (int)$filters['month'] > 0 ? (int)$filters['month'] : (int)date('m');
$cardPreviewToken = isset($_GET['card_preview_token']) ? trim((string)$_GET['card_preview_token']) : '';
$cardPreview = ($canEditCompanyOverhead && $cardPreviewToken !== '') ? cpms_company_overhead_get_card_preview($cardPreviewToken) : null;
$items = cpms_company_overhead_load_month('corporate_cards', $cardYear, $cardMonth, false);
$cardGroups = cpms_company_overhead_group_card_items($items);
$cardTotal = cpms_company_overhead_sum_record($items);
$cardTransactionCount = count($items);

if (!function_exists('cpms_overhead_card_money')) {
function cpms_overhead_card_money($value) {
    return number_format((float)$value);
}}

if (!function_exists('cpms_overhead_card_val')) {
function cpms_overhead_card_val($row, $key) {
    if (is_array($row) && isset($row[$key])) return $row[$key];
    return '';
}}

if (!function_exists('cpms_overhead_card_detail_sort')) {
function cpms_overhead_card_detail_sort($a, $b) {
    $ad = (isset($a['occurred_at']) ? (string)$a['occurred_at'] : '') . ' ' . (isset($a['used_time']) ? (string)$a['used_time'] : '');
    $bd = (isset($b['occurred_at']) ? (string)$b['occurred_at'] : '') . ' ' . (isset($b['used_time']) ? (string)$b['used_time'] : '');
    if ($ad === $bd) return 0;
    return ($ad < $bd) ? 1 : -1;
}}
?>

<form method="get" action="" class="bg-white border border-gray-200 rounded-2xl p-4">
  <input type="hidden" name="r" value="관리">
  <input type="hidden" name="tab" value="company_overhead">
  <input type="hidden" name="oh" value="corporate_cards">
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
    <label class="block text-sm font-bold text-gray-700">
      <span class="block mb-2">연도</span>
      <input type="number" name="year" min="2000" max="2100" value="<?php echo h((string)$cardYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
    </label>
    <label class="block text-sm font-bold text-gray-700">
      <span class="block mb-2">월</span>
      <select name="month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?php echo $m; ?>" <?php echo ((int)$cardMonth === $m) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $m); ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <div class="md:col-span-2 flex flex-wrap gap-2">
      <button type="submit" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">조회</button>
    </div>
  </div>
</form>

<div class="grid grid-cols-1 md:grid-cols-3 gap-3">
  <div class="bg-white border border-emerald-200 rounded-2xl p-4">
    <div class="text-xs font-bold text-gray-500">총합계금액</div>
    <div class="mt-2 text-2xl font-extrabold text-emerald-700"><?php echo h(cpms_overhead_card_money($cardTotal)); ?>원</div>
  </div>
  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="text-xs font-bold text-gray-500">카드 수</div>
    <div class="mt-2 text-2xl font-extrabold"><?php echo h((string)count($cardGroups)); ?>개</div>
  </div>
  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="text-xs font-bold text-gray-500">사용 건수</div>
    <div class="mt-2 text-2xl font-extrabold"><?php echo h((string)$cardTransactionCount); ?>건</div>
  </div>
</div>

<div class="bg-white border border-gray-200 rounded-2xl p-4">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <div class="font-extrabold text-gray-900">법인카드 엑셀 업로드</div>
      <div class="text-sm text-gray-500 mt-1">매입(카드/페이) .xls 또는 .xlsx 파일을 선택 월 데이터로 미리보기 후 확정 저장합니다.</div>
    </div>
    <div class="px-3 py-2 rounded-xl border <?php echo $canEditCompanyOverhead ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600'; ?> text-sm font-bold">
      <?php echo $canEditCompanyOverhead ? '업로드 가능' : '조회 전용'; ?>
    </div>
  </div>
  <?php if ($canEditCompanyOverhead): ?>
    <form method="post" action="?r=management/corporate_card_upload_preview" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end mt-4">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">적용연도</span>
        <input type="number" name="apply_year" min="2000" max="2100" value="<?php echo h((string)$cardYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">적용월</span>
        <select name="apply_month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo ((int)$cardMonth === $m) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $m); ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="block text-sm font-bold text-gray-700 md:col-span-2">
        <span class="block mb-2">법인카드 엑셀 파일</span>
        <input type="file" name="card_file" accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="w-full px-3 py-3 rounded-xl border border-gray-300 bg-white">
      </label>
      <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">업로드 미리보기</button>
    </form>
  <?php else: ?>
    <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">조회 권한만 있어 엑셀 업로드는 사용할 수 없습니다.</div>
  <?php endif; ?>
</div>

<?php if (is_array($cardPreview)): ?>
  <?php
    $previewParsed = isset($cardPreview['parsed']) && is_array($cardPreview['parsed']) ? $cardPreview['parsed'] : array();
    $previewItems = isset($previewParsed['items']) && is_array($previewParsed['items']) ? $previewParsed['items'] : array();
    $previewGroups = isset($cardPreview['groups']) && is_array($cardPreview['groups']) ? $cardPreview['groups'] : array();
    $previewTotal = cpms_company_overhead_sum_record($previewItems);
    $existingCount = count(cpms_company_overhead_load_month('corporate_cards', isset($cardPreview['year']) ? $cardPreview['year'] : $cardYear, isset($cardPreview['month']) ? $cardPreview['month'] : $cardMonth, false));
  ?>
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
    <form method="post" action="?r=management/corporate_card_upload_confirm" onsubmit="return confirm('미리보기 결과를 법인카드 월별 데이터로 확정 저장합니다. 같은 월의 기존 법인카드 데이터는 새 업로드 내용으로 교체됩니다. 진행하시겠습니까?');">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="preview_token" value="<?php echo h($cardPreviewToken); ?>">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div class="font-extrabold text-amber-900">업로드 미리보기</div>
          <div class="text-sm text-amber-800 mt-1">
            적용월: <?php echo h(isset($cardPreview['year']) ? $cardPreview['year'] : ''); ?>년 <?php echo h(isset($cardPreview['month']) ? $cardPreview['month'] : ''); ?>월 /
            파일: <?php echo h(isset($cardPreview['uploaded_original_name']) ? $cardPreview['uploaded_original_name'] : ''); ?> /
            사용 건수: <?php echo h((string)count($previewItems)); ?>건 /
            카드 수: <?php echo h((string)count($previewGroups)); ?>개 /
            합계: <?php echo h(cpms_overhead_card_money($previewTotal)); ?>원
          </div>
          <?php if ($existingCount > 0): ?>
            <div class="text-sm text-red-700 font-bold mt-2">같은 적용월에 기존 법인카드 데이터 <?php echo h((string)$existingCount); ?>건이 있습니다. 확정 저장하면 새 업로드 내용으로 교체됩니다.</div>
          <?php endif; ?>
        </div>
        <button type="submit" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold">확정 저장</button>
      </div>
    </form>
  </div>
<?php elseif ($cardPreviewToken !== ''): ?>
  <div class="bg-red-50 border border-red-200 rounded-2xl p-4 text-red-700 font-bold">미리보기 세션이 만료되었거나 찾을 수 없습니다. 다시 업로드해주세요.</div>
<?php endif; ?>

<div class="bg-white border border-gray-200 rounded-2xl p-4">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
      <div class="font-extrabold text-gray-900">카드별 사용내역</div>
      <div class="text-sm text-gray-500 mt-1"><?php echo h(sprintf('%04d/%02d', $cardYear, $cardMonth)); ?></div>
    </div>
  </div>

  <?php if (count($cardGroups) === 0): ?>
    <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-center text-gray-500 font-bold">표시할 법인카드 사용내역이 없습니다.</div>
  <?php else: ?>
    <div class="space-y-3" id="corporateCardGroups">
      <?php foreach ($cardGroups as $group): ?>
        <?php
          $detailId = 'card-detail-' . md5(isset($group['key']) ? (string)$group['key'] : uniqid('', true));
          $groupItems = isset($group['items']) && is_array($group['items']) ? $group['items'] : array();
          usort($groupItems, 'cpms_overhead_card_detail_sort');
        ?>
        <div class="border border-gray-200 rounded-2xl bg-white overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-3 p-4">
            <div>
              <div class="font-extrabold text-gray-900"><?php echo h(isset($group['user_name']) ? $group['user_name'] : '-'); ?></div>
              <div class="text-xs text-gray-500 mt-1">
                <?php echo h(cpms_company_overhead_card_display_number(isset($group['card_number']) ? $group['card_number'] : '')); ?>
                <?php if (!empty($group['card_alias'])): ?> · <?php echo h($group['card_alias']); ?><?php endif; ?>
                · <?php echo h(isset($group['count']) ? (string)(int)$group['count'] : '0'); ?>건
              </div>
            </div>
            <div class="flex items-center gap-3">
              <div class="text-right">
                <div class="text-xs font-bold text-gray-500">총합계금액</div>
                <div class="text-lg font-extrabold text-emerald-700"><?php echo h(cpms_overhead_card_money(isset($group['total']) ? $group['total'] : 0)); ?>원</div>
              </div>
              <button type="button" class="px-3 py-2 rounded-xl border border-gray-300 text-sm font-bold" data-card-toggle="<?php echo h($detailId); ?>" data-open-label="접기" data-closed-label="보이기">보이기</button>
            </div>
          </div>
          <div id="<?php echo h($detailId); ?>" class="hidden border-t border-gray-200">
            <div class="cpms-responsive-table-wrap">
              <table class="cpms-responsive-table text-xs">
                <thead>
                  <tr>
                    <th class="text-left p-3 border-b border-gray-200 bg-gray-50">사용일자</th>
                    <th class="text-left p-3 border-b border-gray-200 bg-gray-50">사용시간</th>
                    <th class="text-left p-3 border-b border-gray-200 bg-gray-50">사용처</th>
                    <th class="text-right p-3 border-b border-gray-200 bg-gray-50">사용금액</th>
                    <th class="text-left p-3 border-b border-gray-200 bg-gray-50">내용</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($groupItems as $item): ?>
                    <tr>
                      <td class="p-3 border-b border-gray-100"><?php echo h(cpms_overhead_card_val($item, 'occurred_at')); ?></td>
                      <td class="p-3 border-b border-gray-100"><?php echo h(cpms_overhead_card_val($item, 'used_time')); ?></td>
                      <td class="p-3 border-b border-gray-100" data-wrap="1"><?php echo h(cpms_overhead_card_val($item, 'vendor')); ?></td>
                      <td class="p-3 border-b border-gray-100 text-right font-bold"><?php echo h(cpms_overhead_card_money(cpms_overhead_card_val($item, 'amount'))); ?></td>
                      <td class="p-3 border-b border-gray-100" data-wrap="1"><?php echo h(cpms_overhead_card_val($item, 'content')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var buttons = document.querySelectorAll('[data-card-toggle]');
  for (var i = 0; i < buttons.length; i++) {
    buttons[i].addEventListener('click', function(){
      var id = this.getAttribute('data-card-toggle');
      var panel = document.getElementById(id);
      if (!panel) return;
      var isHidden = panel.className.indexOf('hidden') !== -1;
      panel.className = isHidden ? panel.className.replace(/\bhidden\b/g, '') : (panel.className + ' hidden');
      this.textContent = isHidden ? (this.getAttribute('data-open-label') || '접기') : (this.getAttribute('data-closed-label') || '보이기');
    });
  }
})();
</script>
