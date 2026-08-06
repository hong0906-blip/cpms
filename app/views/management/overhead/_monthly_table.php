<?php
/**
 * 파일: C:\www\cpms\app\views\management\overhead\_monthly_table.php
 *
 * 총관리비 > 요약 > 월별 합계 표
 * - 월별 금액 표시
 * - 표 맨 아래에 조회기간 전체 합계(계) 표시
 * - PHP 5.6 호환
 */

$monthlyTableMonths = isset($summary['months']) && is_array($summary['months'])
    ? $summary['months']
    : array();

$monthlyTableCategoryTotals = array();
foreach ($categories as $catKey => $catMeta) {
    $monthlyTableCategoryTotals[$catKey] = 0.0;
}

$monthlyTableGrandTotal = 0.0;
foreach ($monthlyTableMonths as $monthRow) {
    foreach ($categories as $catKey => $catMeta) {
        $categoryAmount = isset($monthRow['categories'][$catKey])
            ? (float)$monthRow['categories'][$catKey]
            : 0.0;
        $monthlyTableCategoryTotals[$catKey] += $categoryAmount;
    }

    $monthlyTableGrandTotal += isset($monthRow['total'])
        ? (float)$monthRow['total']
        : 0.0;
}
?>

<div class="bg-white border border-gray-200 rounded-2xl p-4">
  <div class="font-extrabold text-gray-900 mb-3">월별 합계</div>
  <div class="cpms-responsive-table-wrap">
    <table class="cpms-responsive-table text-sm">
      <thead>
        <tr>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">연도</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">월</th>
          <?php foreach ($categories as $catMeta): ?>
            <th class="text-right p-3 border-b border-gray-200 bg-gray-50"><?php echo h($catMeta['label']); ?></th>
          <?php endforeach; ?>
          <th class="text-right p-3 border-b border-gray-200 bg-gray-50">월 합계</th>
        </tr>
      </thead>

      <tbody>
        <?php if (count($monthlyTableMonths) > 0): ?>
          <?php foreach ($monthlyTableMonths as $monthRow): ?>
            <tr>
              <td class="p-3 border-b border-gray-100"><?php echo h($monthRow['year']); ?></td>
              <td class="p-3 border-b border-gray-100"><?php echo h($monthRow['month']); ?></td>
              <?php foreach ($categories as $catKey => $catMeta): ?>
                <td class="p-3 border-b border-gray-100 text-right">
                  <?php echo h(cpms_overhead_view_money(isset($monthRow['categories'][$catKey]) ? $monthRow['categories'][$catKey] : 0)); ?>
                </td>
              <?php endforeach; ?>
              <td class="p-3 border-b border-gray-100 text-right font-extrabold">
                <?php echo h(cpms_overhead_view_money(isset($monthRow['total']) ? $monthRow['total'] : 0)); ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="<?php echo h((string)(count($categories) + 3)); ?>" class="p-8 text-center text-gray-500">
              조회된 월별 관리비 내역이 없습니다.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>

      <tfoot>
        <tr class="bg-emerald-50">
          <th colspan="2" class="p-4 border-t-2 border-emerald-200 text-left text-emerald-900 font-extrabold">
            계
          </th>
          <?php foreach ($categories as $catKey => $catMeta): ?>
            <th class="p-4 border-t-2 border-emerald-200 text-right text-emerald-900 font-extrabold">
              <?php echo h(cpms_overhead_view_money(isset($monthlyTableCategoryTotals[$catKey]) ? $monthlyTableCategoryTotals[$catKey] : 0)); ?>
            </th>
          <?php endforeach; ?>
          <th class="p-4 border-t-2 border-emerald-300 text-right text-emerald-800 font-black text-base">
            <?php echo h(cpms_overhead_view_money($monthlyTableGrandTotal)); ?>
          </th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
