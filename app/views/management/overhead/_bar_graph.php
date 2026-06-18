<?php
$maxBar = 0.0;
foreach ($summary['months'] as $monthRow) {
    if ((float)$monthRow['total'] > $maxBar) $maxBar = (float)$monthRow['total'];
}
?>
<div class="bg-white border border-gray-200 rounded-2xl p-4">
  <div class="font-extrabold text-gray-900 mb-3">월별 총관리비 그래프</div>
  <div class="flex items-end gap-2 overflow-x-auto min-h-44 pb-2">
    <?php foreach ($summary['months'] as $monthRow): ?>
      <?php $height = $maxBar > 0 ? max(8, round(((float)$monthRow['total'] / $maxBar) * 128)) : 8; ?>
      <div class="min-w-16 flex-1 flex flex-col items-center justify-end gap-2">
        <div class="text-xs font-bold text-gray-600"><?php echo h(cpms_overhead_view_money($monthRow['total'])); ?></div>
        <div class="w-full max-w-16 rounded-t-xl bg-emerald-600" style="height:<?php echo (int)$height; ?>px;"></div>
        <div class="text-xs text-gray-500"><?php echo h(substr($monthRow['ym'], 5, 2)); ?>월</div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
