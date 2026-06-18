<?php
$cardRows = array();
$cardRows[] = array('label' => '선택 기간 총관리비', 'amount' => isset($summary['total']) ? (float)$summary['total'] : 0.0, 'strong' => true);
foreach ($categories as $catKey => $catMeta) {
    $amount = isset($summary['categories'][$catKey]['amount']) ? (float)$summary['categories'][$catKey]['amount'] : 0.0;
    $cardRows[] = array('label' => $catMeta['label'] . ' 합계', 'amount' => $amount, 'strong' => false);
}
?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
  <?php foreach ($cardRows as $card): ?>
    <div class="bg-white border <?php echo !empty($card['strong']) ? 'border-emerald-200' : 'border-gray-200'; ?> rounded-2xl p-4">
      <div class="text-sm text-gray-500 font-bold"><?php echo h($card['label']); ?></div>
      <div class="mt-2 text-2xl font-extrabold <?php echo !empty($card['strong']) ? 'text-emerald-700' : 'text-gray-900'; ?>"><?php echo h(cpms_overhead_view_money($card['amount'])); ?>원</div>
    </div>
  <?php endforeach; ?>
</div>
