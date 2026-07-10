<?php
$projectTotalSales = isset($totals['sales']) ? (float)$totals['sales'] : 0.0;
$projectTotalCost = isset($totals['project_input_cost']) ? (float)$totals['project_input_cost'] : 0.0;
$projectTotalTarget = isset($totals['target_amount']) ? (float)$totals['target_amount'] : 0.0;
$projectTotalNet = $projectTotalSales - $projectTotalCost;
if (function_exists('cpms_company_profit_cost_rate_info')) {
    $projectTotalRateInfo = cpms_company_profit_cost_rate_info($projectTotalSales, $projectTotalCost);
} else if ($projectTotalSales > 0) {
    $projectTotalRate = ($projectTotalCost / $projectTotalSales) * 100;
    $projectTotalRateInfo = array('cost_rate' => $projectTotalRate, 'cost_rate_label' => number_format($projectTotalRate, 1) . '%', 'no_sales' => 0);
} else if ($projectTotalCost > 0) {
    $projectTotalRateInfo = array('cost_rate' => 999.0, 'cost_rate_label' => '매출 없음', 'no_sales' => 1);
} else {
    $projectTotalRateInfo = array('cost_rate' => 0.0, 'cost_rate_label' => '0%', 'no_sales' => 0);
}
$projectTotalRateState = cpms_company_profit_rate_state(
    isset($projectTotalRateInfo['cost_rate']) ? (float)$projectTotalRateInfo['cost_rate'] : 0.0,
    isset($projectTotalRateInfo['no_sales']) ? (int)$projectTotalRateInfo['no_sales'] : 0
);
?>

<div class="cp-section cp-panel">
  <div class="cp-panel-title">
    <div>
      <h3>현장별 상세 목록</h3>
    </div>
    <div class="cp-help">총 <?php echo (int)count($projects); ?>개 현장</div>
  </div>

  <div class="cp-table-wrap">
    <table>
      <thead>
        <tr>
          <th>현장명</th>
          <th class="text-right">확정매출</th>
          <th class="text-right">투입원가</th>
          <th class="text-right">투입목표 금액</th>
          <th class="text-right">원가율</th>
          <th class="text-right">순이익</th>
          <th>진행상태</th>
          <th>매출기준</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($projects) === 0): ?>
          <tr><td colspan="8" data-wrap="1">표시할 현장이 없습니다.</td></tr>
        <?php else: ?>
          <?php foreach ($projects as $row): ?>
            <?php
            $sales = isset($row['sales']) ? (float)$row['sales'] : 0.0;
            $cost = isset($row['input_cost']) ? (float)$row['input_cost'] : 0.0;
            $target = isset($row['target_amount']) ? (float)$row['target_amount'] : 0.0;
            $net = isset($row['net_profit']) ? (float)$row['net_profit'] : 0.0;
            $rateState = cpms_company_profit_rate_state(isset($row['cost_rate']) ? (float)$row['cost_rate'] : 0.0, isset($row['no_sales']) ? (int)$row['no_sales'] : 0);
            $basisLabel = cpms_company_profit_sales_basis_label(isset($row['basis']) ? $row['basis'] : '');
            $error = isset($row['error']) ? trim((string)$row['error']) : '';
            ?>
            <tr>
              <td data-wrap="1">
                <strong>
                  <?php if (isset($row['id']) && (int)$row['id'] > 0): ?>
                    <a href="?r=project/detail&amp;id=<?php echo (int)$row['id']; ?>"><?php echo h(isset($row['name']) ? $row['name'] : '-'); ?></a>
                  <?php else: ?>
                    <?php echo h(isset($row['name']) ? $row['name'] : '-'); ?>
                  <?php endif; ?>
                </strong>
                <?php if ($error !== ''): ?><div class="cp-negative" style="font-size:12px;font-weight:800;"><?php echo h($error); ?></div><?php endif; ?>
              </td>
              <td class="text-right"><?php echo h(cpms_company_profit_money($sales)); ?></td>
              <td class="text-right"><?php echo h(cpms_company_profit_money($cost)); ?></td>
              <td class="text-right"><?php echo h(cpms_company_profit_money($target)); ?></td>
              <td class="text-right"><span class="cp-rate-pill <?php echo h($rateState['class']); ?>"><?php echo h(isset($row['cost_rate_label']) ? $row['cost_rate_label'] : '0%'); ?></span></td>
              <td class="text-right <?php echo $net < 0 ? 'cp-negative' : 'cp-positive'; ?>" style="font-weight:900;"><?php echo h(cpms_company_profit_money($net)); ?></td>
              <td><?php echo h(isset($row['status']) && (string)$row['status'] !== '' ? $row['status'] : '-'); ?></td>
              <td><?php echo h($basisLabel); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <?php if (count($projects) > 0): ?>
        <tfoot>
          <tr class="cp-total-row">
            <th data-wrap="1">합계</th>
            <td class="text-right"><?php echo h(cpms_company_profit_money($projectTotalSales)); ?></td>
            <td class="text-right"><?php echo h(cpms_company_profit_money($projectTotalCost)); ?></td>
            <td class="text-right"><?php echo h(cpms_company_profit_money($projectTotalTarget)); ?></td>
            <td class="text-right"><span class="cp-rate-pill <?php echo h($projectTotalRateState['class']); ?>"><?php echo h(isset($projectTotalRateInfo['cost_rate_label']) ? $projectTotalRateInfo['cost_rate_label'] : '0%'); ?></span></td>
            <td class="text-right <?php echo $projectTotalNet < 0 ? 'cp-negative' : 'cp-positive'; ?>"><?php echo h(cpms_company_profit_money($projectTotalNet)); ?></td>
            <td>-</td>
            <td>-</td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
