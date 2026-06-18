<?php
$tabBase = '?r=' . urlencode('관리') . '&tab=company_overhead';
?>
<div class="bg-white border border-gray-200 rounded-2xl p-3">
  <div class="flex flex-wrap gap-2">
    <?php if (!empty($canViewCompanyOverhead)): ?>
    <a href="<?php echo h($tabBase . '&oh=summary'); ?>" class="px-4 py-3 rounded-xl text-sm font-extrabold border <?php echo $overheadSection === 'summary' ? 'bg-emerald-700 border-emerald-700 text-white' : 'bg-white border-gray-200 text-gray-700'; ?>">요약</a>
    <?php endif; ?>
    <?php foreach ($categories as $catKey => $catMeta): ?>
      <?php if ($catKey !== 'payroll' && empty($canViewCompanyOverhead)): ?>
        <?php continue; ?>
      <?php endif; ?>
      <?php if ($catKey === 'payroll' && (empty($canViewCompanyPayroll))): ?>
        <?php continue; ?>
      <?php endif; ?>
      <a href="<?php echo h($tabBase . '&oh=' . urlencode($catKey)); ?>" class="px-4 py-3 rounded-xl text-sm font-extrabold border <?php echo $overheadSection === $catKey ? 'bg-emerald-700 border-emerald-700 text-white' : 'bg-white border-gray-200 text-gray-700'; ?>">
        <?php echo h($catMeta['label']); ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
