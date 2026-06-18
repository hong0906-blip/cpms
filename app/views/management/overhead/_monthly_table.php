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
        <?php foreach ($summary['months'] as $monthRow): ?>
          <tr>
            <td class="p-3 border-b border-gray-100"><?php echo h($monthRow['year']); ?></td>
            <td class="p-3 border-b border-gray-100"><?php echo h($monthRow['month']); ?></td>
            <?php foreach ($categories as $catKey => $catMeta): ?>
              <td class="p-3 border-b border-gray-100 text-right"><?php echo h(cpms_overhead_view_money(isset($monthRow['categories'][$catKey]) ? $monthRow['categories'][$catKey] : 0)); ?></td>
            <?php endforeach; ?>
            <td class="p-3 border-b border-gray-100 text-right font-extrabold"><?php echo h(cpms_overhead_view_money($monthRow['total'])); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
