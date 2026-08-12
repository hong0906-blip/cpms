<?php
$projectGraphMax = cpms_company_profit_max_value($projects, array('sales', 'input_cost'));
?>

<div class="cp-section cp-panel">
  <div class="cp-panel-title">
    <div>
      <h3>현장별 손익 가로 그래프</h3>
    </div>
    <div class="cp-help">80% 미만 정상 · 80~90% 주의 · 90~100% 위험 · 100% 이상 적자</div>
  </div>

  <?php if (count($projects) === 0): ?>
    <div class="cp-help">표시할 현장 데이터가 없습니다.</div>
  <?php else: ?>
    <div class="cp-graph-scroll">
      <div class="cp-bar-ledger">
        <?php foreach ($projects as $row): ?>
          <?php
          $sales = isset($row['sales']) ? (float)$row['sales'] : 0.0;
          $cost = isset($row['input_cost']) ? (float)$row['input_cost'] : 0.0;
          $net = isset($row['net_profit']) ? (float)$row['net_profit'] : 0.0;
          $salesPct = cpms_company_profit_safe_percent($sales, $projectGraphMax);
          $costPct = cpms_company_profit_safe_percent($cost, $projectGraphMax);
          $rateState = cpms_company_profit_rate_state(isset($row['cost_rate']) ? (float)$row['cost_rate'] : 0.0, isset($row['no_sales']) ? (int)$row['no_sales'] : 0);
          $isOverCost = empty($row['no_sales']) && isset($row['cost_rate']) && (float)$row['cost_rate'] > 100.0;
          ?>
          <div class="cp-project-bar-row">
            <div class="cp-project-name <?php echo $isOverCost ? 'cp-project-over-cost' : ''; ?>" title="<?php echo h(isset($row['name']) ? $row['name'] : ''); ?>">
              <?php if (isset($row['id']) && (int)$row['id'] > 0): ?>
                <a href="?r=construction_home&amp;pid=<?php echo (int)$row['id']; ?>&amp;tab=status" title="공사 섹션으로 이동"><?php echo h(isset($row['name']) ? $row['name'] : '-'); ?></a>
              <?php else: ?>
                <?php echo h(isset($row['name']) ? $row['name'] : '-'); ?>
              <?php endif; ?>
            </div>
            <div class="cp-bars">
              <div class="cp-bar-line">
                <span>확정매출</span>
                <span class="cp-track"><span class="cp-fill cp-fill-sales" style="width:<?php echo round($salesPct, 2); ?>%;"></span></span>
                <strong><?php echo h(cpms_company_profit_money($sales)); ?></strong>
              </div>
              <div class="cp-bar-line">
                <span>투입원가</span>
                <span class="cp-track"><span class="cp-fill cp-fill-cost" style="width:<?php echo round($costPct, 2); ?>%;"></span></span>
                <strong><?php echo h(cpms_company_profit_money($cost)); ?></strong>
              </div>
            </div>
            <div>
              <span class="cp-rate-pill <?php echo h($rateState['class']); ?>"><?php echo h(isset($row['cost_rate_label']) ? $row['cost_rate_label'] : '0%'); ?></span>
            </div>
            <div class="<?php echo $net < 0 ? 'cp-negative' : 'cp-positive'; ?>" style="font-weight:900;text-align:right;">
              <?php echo h(cpms_company_profit_money($net)); ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
