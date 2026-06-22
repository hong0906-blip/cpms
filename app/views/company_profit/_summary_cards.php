<?php
$netProfit = isset($totals['net_profit']) ? (float)$totals['net_profit'] : 0.0;
$totalSales = isset($totals['sales']) ? (float)$totals['sales'] : 0.0;
$projectInputCost = isset($totals['project_input_cost']) ? (float)$totals['project_input_cost'] : 0.0;
$totalOverhead = isset($totals['overhead']) ? (float)$totals['overhead'] : 0.0;
$totalInputCost = isset($totals['total_input_cost']) ? (float)$totals['total_input_cost'] : 0.0;
$targetAmount = isset($totals['target_amount']) ? (float)$totals['target_amount'] : 0.0;
$costRateLabel = isset($totals['cost_rate_label']) ? (string)$totals['cost_rate_label'] : '0%';
$overheadHasData = !empty($overhead['has_data']);
?>

<div class="cp-section cp-summary-grid">
  <div class="cp-summary-card">
    <div class="label">확정순이익</div>
    <div class="value <?php echo $netProfit < 0 ? 'cp-negative' : 'cp-positive'; ?>"><?php echo h(cpms_company_profit_money($netProfit)); ?></div>
    <div class="sub">총 확정 매출 - 총 투입원가</div>
  </div>
  <div class="cp-summary-card">
    <div class="label">총 확정 매출</div>
    <div class="value"><?php echo h(cpms_company_profit_money($totalSales)); ?></div>
    <div class="sub">기성 입력 월은 기성금액, 미입력 월은 예상매출</div>
  </div>
  <div class="cp-summary-card">
    <div class="label">총 투입원가</div>
    <div class="value"><?php echo h(cpms_company_profit_money($totalInputCost)); ?></div>
    <div class="sub">현장 투입원가 <?php echo h(cpms_company_profit_money($projectInputCost)); ?> + 총관리비 <?php echo h(cpms_company_profit_money($totalOverhead)); ?></div>
  </div>
  <div class="cp-summary-card">
    <div class="label">총 관리비</div>
    <div class="value"><?php echo h(cpms_company_profit_money($totalOverhead)); ?></div>
    <div class="sub">
      임직원 월급, 회사차량, 임대차, 법인카드, 주유비 등을 합산한 금액입니다.
      <?php if (!$overheadHasData): ?><br><strong>총관리비 데이터 미등록</strong><?php endif; ?>
    </div>
  </div>
  <div class="cp-summary-card">
    <div class="label">총 원가율</div>
    <div class="value"><?php echo h($costRateLabel); ?></div>
    <div class="sub">회사 전체 원가율만 총관리비 포함 기준</div>
  </div>
  <div class="cp-summary-card">
    <div class="label">총 투입목표 금액</div>
    <div class="value"><?php echo h(cpms_company_profit_money($targetAmount)); ?></div>
    <div class="sub">전체 현장 투입목표 금액 합계</div>
  </div>
</div>

<?php if (!$overheadHasData): ?>
  <div class="cp-section cp-alert">총관리비 데이터 미등록: 이번 화면에서는 총관리비를 0원으로 계산했습니다.</div>
<?php endif; ?>
