<?php
$barKeys = array(
    array('key' => 'sales', 'label' => '확정매출', 'color' => '#2563eb'),
    array('key' => 'total_input_cost', 'label' => '총 투입원가', 'color' => '#f97316'),
    array('key' => 'overhead', 'label' => '총 관리비', 'color' => '#64748b'),
    array('key' => 'net_profit', 'label' => '확정순이익', 'color' => '#14b8a6'),
);
$countPeriods = count($periodRows);
?>

<div class="cp-section cp-panel">
  <div class="cp-panel-title">
    <div>
      <h3>회사 손익 추이</h3>
      <div class="cp-help">막대는 금액, 선은 총 원가율입니다. 총 투입원가는 현장 투입원가와 총관리비를 합산합니다.</div>
    </div>
    <div class="cp-help">오른쪽 축 기준: 총 원가율</div>
  </div>

  <?php if ($countPeriods === 0): ?>
    <div class="cp-help">표시할 기간 데이터가 없습니다.</div>
  <?php else: ?>
    <?php
    $minAmount = 0.0;
    $maxAmount = 0.0;
    $maxRate = 0.0;
    foreach ($periodRows as $row) {
        foreach ($barKeys as $meta) {
            $value = isset($row[$meta['key']]) ? (float)$row[$meta['key']] : 0.0;
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
          $barWidth = 12;
          $offsets = array(-25, -9, 7, 23);
          ?>
          <?php foreach ($barKeys as $barIdx => $meta): ?>
            <?php
            $value = isset($row[$meta['key']]) ? (float)$row[$meta['key']] : 0.0;
            $valueY = $top + (($maxAmount - $value) / $rangeAmount) * $plotHeight;
            $rectX = $centerX + $offsets[$barIdx];
            if ($value >= 0) {
                $rectY = $valueY;
                $rectHeight = $zeroY - $valueY;
            } else {
                $rectY = $zeroY;
                $rectHeight = $valueY - $zeroY;
            }
            if ($rectHeight < 2) $rectHeight = 2;
            $fill = ($meta['key'] === 'net_profit' && $value < 0) ? '#dc2626' : $meta['color'];
            ?>
            <rect x="<?php echo round($rectX, 2); ?>" y="<?php echo round($rectY, 2); ?>" width="<?php echo (int)$barWidth; ?>" height="<?php echo round($rectHeight, 2); ?>" rx="3" fill="<?php echo h($fill); ?>">
              <title><?php echo h($row['label'] . ' ' . $meta['label'] . ': ' . cpms_company_profit_money($value)); ?></title>
            </rect>
          <?php endforeach; ?>
          <?php
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
    <div class="cp-legend">
      <?php foreach ($barKeys as $meta): ?>
        <span><i class="cp-dot" style="background:<?php echo h($meta['color']); ?>"></i><?php echo h($meta['label']); ?></span>
      <?php endforeach; ?>
      <span><i class="cp-dot" style="background:#dc2626;border-radius:999px;"></i>총 원가율 선</span>
    </div>
  <?php endif; ?>
</div>
