<?php
/*
 * 파일경로: app/views/company_profit/_period_graph.php
 * 화면: 경영현황 > 회사 손익 추이 그래프
 *
 * 표시 기준
 * - 확정매출: 파란색 막대
 * - 비용: 하나의 누적 막대
 *   · 아래 회색 = 총 관리비
 *   · 위 주황색 = 현장 투입원가
 * - 확정순이익: 청록색(음수는 빨간색) 막대
 * - 총 원가율: 빨간색 선
 *
 * 주의
 * - 총 투입원가 계산 방식은 변경하지 않습니다.
 * - 그래프에서 기존 '총 투입원가' 단독 막대를 없애고
 *   '총 관리비 + 현장 투입원가'를 하나의 누적 막대로 표시합니다.
 */

$legendKeys = array(
    array('key' => 'sales', 'label' => '확정매출', 'color' => '#2563eb'),
    array('key' => 'project_input_cost', 'label' => '현장 투입원가', 'color' => '#f97316'),
    array('key' => 'overhead', 'label' => '총 관리비', 'color' => '#64748b'),
    array('key' => 'net_profit', 'label' => '확정순이익', 'color' => '#14b8a6'),
);
$countPeriods = count($periodRows);
?>

<div class="cp-section cp-panel">
  <div class="cp-panel-title">
    <div>
      <h3>회사 손익 추이</h3>
    </div>
  </div>

  <?php if ($countPeriods === 0): ?>
    <div class="cp-help">표시할 기간 데이터가 없습니다.</div>
  <?php else: ?>
    <?php
    /* 그래프 Y축 범위를 계산합니다. */
    $minAmount = 0.0;
    $maxAmount = 0.0;
    $maxRate = 0.0;

    foreach ($periodRows as $row) {
        $sales = isset($row['sales']) ? (float)$row['sales'] : 0.0;
        $overhead = isset($row['overhead']) ? (float)$row['overhead'] : 0.0;

        if (isset($row['project_input_cost'])) {
            $projectInputCost = (float)$row['project_input_cost'];
        } else {
            /* 이전 데이터 호환용: 현장 투입원가가 없으면 총 투입원가 - 관리비로 계산합니다. */
            $totalInputCostForFallback = isset($row['total_input_cost']) ? (float)$row['total_input_cost'] : 0.0;
            $projectInputCost = $totalInputCostForFallback - $overhead;
        }

        if (isset($row['total_input_cost'])) {
            $totalInputCost = (float)$row['total_input_cost'];
        } else {
            $totalInputCost = $projectInputCost + $overhead;
        }

        $netProfit = isset($row['net_profit']) ? (float)$row['net_profit'] : 0.0;

        $amountValues = array($sales, $totalInputCost, $netProfit);
        foreach ($amountValues as $value) {
            if ($value < $minAmount) $minAmount = $value;
            if ($value > $maxAmount) $maxAmount = $value;
        }

        if (empty($row['no_sales'])) {
            $rate = isset($row['cost_rate']) ? (float)$row['cost_rate'] : 0.0;
            if ($rate > $maxRate) $maxRate = $rate;
        }
    }

    if ($maxAmount <= $minAmount) $maxAmount = $minAmount + 1;
    $rateMax = max(120, ceil(min(max($maxRate, 1), 150) / 10) * 10);

    $groupWidth = 116;
    $svgWidth = max(780, 125 + ($countPeriods * $groupWidth));
    $svgHeight = 360;
    $left = 58;
    $right = 64;
    $top = 34;
    $bottom = 62;
    $plotWidth = $svgWidth - $left - $right;
    $plotHeight = $svgHeight - $top - $bottom;
    $rangeAmount = $maxAmount - $minAmount;
    $zeroY = $top + (($maxAmount - 0) / $rangeAmount) * $plotHeight;

    if ($zeroY < $top) $zeroY = $top;
    if ($zeroY > ($top + $plotHeight)) $zeroY = $top + $plotHeight;

    $ratePoints = array();
    ?>

    <div class="cp-graph-scroll">
      <svg class="cp-period-chart" viewBox="0 0 <?php echo (int)$svgWidth; ?> <?php echo (int)$svgHeight; ?>" role="img" aria-label="회사 손익 기간 그래프">
        <rect x="0" y="0" width="<?php echo (int)$svgWidth; ?>" height="<?php echo (int)$svgHeight; ?>" fill="#ffffff"></rect>

        <line x1="<?php echo (int)$left; ?>" y1="<?php echo round($top, 2); ?>" x2="<?php echo (int)$left; ?>" y2="<?php echo round($top + $plotHeight, 2); ?>" stroke="#cbd5e1" stroke-width="1"></line>
        <line x1="<?php echo (int)$left; ?>" y1="<?php echo round($zeroY, 2); ?>" x2="<?php echo round($left + $plotWidth, 2); ?>" y2="<?php echo round($zeroY, 2); ?>" stroke="#94a3b8" stroke-width="1"></line>
        <line x1="<?php echo round($left + $plotWidth, 2); ?>" y1="<?php echo round($top, 2); ?>" x2="<?php echo round($left + $plotWidth, 2); ?>" y2="<?php echo round($top + $plotHeight, 2); ?>" stroke="#cbd5e1" stroke-width="1"></line>

        <text x="<?php echo (int)$left; ?>" y="18" fill="#64748b" font-size="12" font-weight="800">금액</text>
        <text x="<?php echo round($left + $plotWidth - 52, 2); ?>" y="18" fill="#dc2626" font-size="12" font-weight="900">총 원가율</text>

        <?php for ($i = 0; $i <= 4; $i++): ?>
          <?php
          $gy = $top + ($plotHeight / 4) * $i;
          $amountValue = $maxAmount - (($rangeAmount / 4) * $i);
          $rateValue = $rateMax - (($rateMax / 4) * $i);
          ?>
          <line x1="<?php echo (int)$left; ?>" y1="<?php echo round($gy, 2); ?>" x2="<?php echo round($left + $plotWidth, 2); ?>" y2="<?php echo round($gy, 2); ?>" stroke="#eef2f7" stroke-width="1"></line>
          <text x="<?php echo (int)($left - 8); ?>" y="<?php echo round($gy + 4, 2); ?>" text-anchor="end" fill="#94a3b8" font-size="10"><?php echo h(number_format($amountValue / 1000000, 0)); ?>M</text>
          <text x="<?php echo round($left + $plotWidth + 8, 2); ?>" y="<?php echo round($gy + 4, 2); ?>" fill="#dc2626" font-size="10"><?php echo h(number_format($rateValue, 0)); ?>%</text>
        <?php endfor; ?>

        <?php foreach ($periodRows as $idx => $row): ?>
          <?php
          $centerX = $left + ($idx * $groupWidth) + ($groupWidth / 2);
          $barWidth = 14;

          /*
           * 한 달에 막대 3개만 표시합니다.
           * 1) 확정매출
           * 2) 총 관리비 + 현장 투입원가 누적 막대
           * 3) 확정순이익
           */
          $salesX = $centerX - 25;
          $costX = $centerX - 7;
          $profitX = $centerX + 11;

          $sales = isset($row['sales']) ? (float)$row['sales'] : 0.0;
          $overhead = isset($row['overhead']) ? (float)$row['overhead'] : 0.0;

          if (isset($row['project_input_cost'])) {
              $projectInputCost = (float)$row['project_input_cost'];
          } else {
              $totalInputCostForFallback = isset($row['total_input_cost']) ? (float)$row['total_input_cost'] : 0.0;
              $projectInputCost = $totalInputCostForFallback - $overhead;
          }

          if (isset($row['total_input_cost'])) {
              $totalInputCost = (float)$row['total_input_cost'];
          } else {
              $totalInputCost = $projectInputCost + $overhead;
          }

          $netProfit = isset($row['net_profit']) ? (float)$row['net_profit'] : 0.0;

          /* 확정매출 막대 */
          $salesY = $top + (($maxAmount - $sales) / $rangeAmount) * $plotHeight;
          if ($sales >= 0) {
              $salesRectY = $salesY;
              $salesRectHeight = $zeroY - $salesY;
          } else {
              $salesRectY = $zeroY;
              $salesRectHeight = $salesY - $zeroY;
          }
          if ($salesRectHeight < 2) $salesRectHeight = 2;

          /* 확정순이익 막대 */
          $profitY = $top + (($maxAmount - $netProfit) / $rangeAmount) * $plotHeight;
          if ($netProfit >= 0) {
              $profitRectY = $profitY;
              $profitRectHeight = $zeroY - $profitY;
          } else {
              $profitRectY = $zeroY;
              $profitRectHeight = $profitY - $zeroY;
          }
          if ($profitRectHeight < 2) $profitRectHeight = 2;
          $profitFill = ($netProfit < 0) ? '#dc2626' : '#14b8a6';

          /*
           * 비용 누적 막대
           * 아래 회색: 총 관리비
           * 위 주황: 현장 투입원가
           * 전체 높이: 총 투입원가
           */
          $overheadTopValue = $overhead;
          $totalCostTopValue = $overhead + $projectInputCost;

          /* 총 투입원가 필드가 있으면 계산 오차 방지를 위해 실제 총액을 기준으로 상단을 맞춥니다. */
          if (isset($row['total_input_cost'])) {
              $totalCostTopValue = $totalInputCost;
          }

          $overheadTopY = $top + (($maxAmount - $overheadTopValue) / $rangeAmount) * $plotHeight;
          $totalCostTopY = $top + (($maxAmount - $totalCostTopValue) / $rangeAmount) * $plotHeight;

          $overheadRectY = $overheadTopY;
          $overheadRectHeight = $zeroY - $overheadTopY;
          if ($overheadRectHeight < 0) $overheadRectHeight = 0;

          $projectRectY = $totalCostTopY;
          $projectRectHeight = $overheadTopY - $totalCostTopY;
          if ($projectRectHeight < 0) $projectRectHeight = 0;
          ?>

          <!-- 확정매출 -->
          <rect x="<?php echo round($salesX, 2); ?>" y="<?php echo round($salesRectY, 2); ?>" width="<?php echo (int)$barWidth; ?>" height="<?php echo round($salesRectHeight, 2); ?>" rx="3" fill="#2563eb">
            <title><?php echo h($row['label'] . ' 확정매출: ' . cpms_company_profit_money($sales)); ?></title>
          </rect>

          <!-- 비용 누적 막대: 아래 총 관리비 -->
          <?php if ($overheadRectHeight > 0): ?>
            <rect x="<?php echo round($costX, 2); ?>" y="<?php echo round($overheadRectY, 2); ?>" width="<?php echo (int)$barWidth; ?>" height="<?php echo round($overheadRectHeight, 2); ?>" rx="3" fill="#64748b">
              <title><?php echo h($row['label'] . ' 총 관리비: ' . cpms_company_profit_money($overhead)); ?></title>
            </rect>
          <?php endif; ?>

          <!-- 비용 누적 막대: 위 현장 투입원가 -->
          <?php if ($projectRectHeight > 0): ?>
            <rect x="<?php echo round($costX, 2); ?>" y="<?php echo round($projectRectY, 2); ?>" width="<?php echo (int)$barWidth; ?>" height="<?php echo round($projectRectHeight, 2); ?>" rx="3" fill="#f97316">
              <title><?php echo h($row['label'] . ' 현장 투입원가: ' . cpms_company_profit_money($projectInputCost)); ?></title>
            </rect>
          <?php endif; ?>

          <!-- 두 비용 구간 경계를 조금 더 또렷하게 표시 -->
          <?php if ($overheadRectHeight > 0 && $projectRectHeight > 0): ?>
            <line x1="<?php echo round($costX + 1, 2); ?>" y1="<?php echo round($overheadTopY, 2); ?>" x2="<?php echo round($costX + $barWidth - 1, 2); ?>" y2="<?php echo round($overheadTopY, 2); ?>" stroke="#ffffff" stroke-width="1"></line>
          <?php endif; ?>

          <!-- 확정순이익 -->
          <rect x="<?php echo round($profitX, 2); ?>" y="<?php echo round($profitRectY, 2); ?>" width="<?php echo (int)$barWidth; ?>" height="<?php echo round($profitRectHeight, 2); ?>" rx="3" fill="<?php echo h($profitFill); ?>">
            <title><?php echo h($row['label'] . ' 확정순이익: ' . cpms_company_profit_money($netProfit)); ?></title>
          </rect>

          <?php
          /* 총 원가율 선 위치 계산 */
          $rate = empty($row['no_sales']) ? (float)$row['cost_rate'] : 0.0;
          if ($rate > $rateMax) $rate = $rateMax;
          $rateY = $top + (($rateMax - $rate) / $rateMax) * $plotHeight - 8;
          if ($rateY < $top) $rateY = $top;
          if ($rateY > ($top + $plotHeight)) $rateY = $top + $plotHeight;
          $ratePoints[] = round($centerX, 2) . ',' . round($rateY, 2);
          ?>

          <text x="<?php echo round($centerX, 2); ?>" y="<?php echo (int)($svgHeight - 34); ?>" text-anchor="middle" fill="#475569" font-size="11" font-weight="800"><?php echo h($row['label']); ?></text>
          <text x="<?php echo round($centerX, 2); ?>" y="<?php echo (int)($svgHeight - 18); ?>" text-anchor="middle" fill="#dc2626" font-size="10" font-weight="900"><?php echo h(isset($row['cost_rate_label']) ? $row['cost_rate_label'] : '0%'); ?></text>
        <?php endforeach; ?>

        <!-- 총 원가율 선 -->
        <polyline points="<?php echo h(implode(' ', $ratePoints)); ?>" fill="none" stroke="#dc2626" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>

        <?php foreach ($periodRows as $idx => $row): ?>
          <?php
          $centerX = $left + ($idx * $groupWidth) + ($groupWidth / 2);
          $rate = empty($row['no_sales']) ? (float)$row['cost_rate'] : 0.0;
          if ($rate > $rateMax) $rate = $rateMax;
          $rateY = $top + (($rateMax - $rate) / $rateMax) * $plotHeight - 8;
          if ($rateY < $top) $rateY = $top;
          if ($rateY > ($top + $plotHeight)) $rateY = $top + $plotHeight;
          ?>
          <circle cx="<?php echo round($centerX, 2); ?>" cy="<?php echo round($rateY, 2); ?>" r="4.5" fill="#fff" stroke="#dc2626" stroke-width="3">
            <title><?php echo h($row['label'] . ' 총 원가율: ' . (isset($row['cost_rate_label']) ? $row['cost_rate_label'] : '0%')); ?></title>
          </circle>
        <?php endforeach; ?>
      </svg>
    </div>

    <!-- 범례 -->
    <div class="cp-legend">
      <?php foreach ($legendKeys as $meta): ?>
        <span><i class="cp-dot" style="background:<?php echo h($meta['color']); ?>"></i><?php echo h($meta['label']); ?></span>
      <?php endforeach; ?>
      <span><i class="cp-dot" style="background:#dc2626;border-radius:999px;"></i>총 원가율 선</span>
    </div>
  <?php endif; ?>
</div>
