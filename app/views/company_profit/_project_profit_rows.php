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
            $projectId = isset($row['id']) ? (int)$row['id'] : 0;
            $projectName = isset($row['name']) ? (string)$row['name'] : '-';
            $isOverCost = empty($row['no_sales']) && isset($row['cost_rate']) && (float)$row['cost_rate'] > 100.0;
            ?>
            <tr>
              <td data-wrap="1" class="<?php echo $isOverCost ? 'cp-project-over-cost' : ''; ?>">
                <strong>
                  <?php if ($projectId > 0): ?>
                    <a class="cp-project-link" href="?r=construction_home&amp;pid=<?php echo $projectId; ?>&amp;tab=status" title="공사 섹션으로 이동"><?php echo h($projectName); ?></a>
                  <?php else: ?>
                    <?php echo h($projectName); ?>
                  <?php endif; ?>
                </strong>
                <?php if ($error !== ''): ?><div class="cp-negative" style="font-size:12px;font-weight:800;"><?php echo h($error); ?></div><?php endif; ?>
              </td>
              <td class="text-right"><button type="button" class="cp-detail-trigger" data-cp-detail-type="sales" data-cp-project-id="<?php echo $projectId; ?>" data-cp-title="<?php echo h($projectName); ?> 매출 상세" aria-haspopup="dialog"><?php echo h(cpms_company_profit_money($sales)); ?></button></td>
              <td class="text-right"><button type="button" class="cp-detail-trigger" data-cp-detail-type="cost" data-cp-project-id="<?php echo $projectId; ?>" data-cp-title="<?php echo h($projectName); ?> 투입원가 상세" aria-haspopup="dialog"><?php echo h(cpms_company_profit_money($cost)); ?></button></td>
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

<?php
$companyProfitDetailData = array();
foreach ($projects as $detailRow) {
    $detailProjectId = isset($detailRow['id']) ? (int)$detailRow['id'] : 0;
    $detailMonthly = (isset($detailRow['monthly']) && is_array($detailRow['monthly'])) ? $detailRow['monthly'] : array();
    $detailPayload = array('sales' => array(), 'cost' => array());
    foreach ($detailMonthly as $detailYm => $monthRow) {
        if (!is_array($monthRow)) continue;
        $detailYear = substr((string)$detailYm, 2, 2);
        $detailMonth = (int)substr((string)$detailYm, 5, 2);
        $hasConfirmed = !empty($monthRow['has_confirmed']);
        $detailAmount = $hasConfirmed
            ? (isset($monthRow['confirmed_sales']) ? (float)$monthRow['confirmed_sales'] : 0.0)
            : (isset($monthRow['expected_sales']) ? (float)$monthRow['expected_sales'] : 0.0);
        if ($hasConfirmed || abs($detailAmount) >= 0.0001) {
            $detailPayload['sales'][] = array(
                'label' => $detailYear . '년 ' . $detailMonth . '월',
                'amount' => $detailAmount,
                'type' => $hasConfirmed ? 'confirmed' : 'expected',
                'count' => $hasConfirmed && !empty($monthRow['confirmed_rows']) ? (int)$monthRow['confirmed_rows'] : 0,
            );
        }

        $monthInputCost = isset($monthRow['input_cost']) ? (float)$monthRow['input_cost'] : 0.0;
        if (abs($monthInputCost) < 0.0001) continue;
        $detailComponents = array(
            array('label' => '노무비', 'amount' => isset($monthRow['labor']) ? (float)$monthRow['labor'] : 0.0),
            array('label' => '자재비', 'amount' => isset($monthRow['material_cost']) ? (float)$monthRow['material_cost'] : 0.0),
            array('label' => '외주비', 'amount' => isset($monthRow['outsourcing']) ? (float)$monthRow['outsourcing'] : 0.0),
            array('label' => '장비비', 'amount' => isset($monthRow['equipment']) ? (float)$monthRow['equipment'] : 0.0),
        );
        $optionalComponents = array(
            '구매품' => isset($monthRow['purchase_cost']) ? (float)$monthRow['purchase_cost'] : 0.0,
            '기타경비' => isset($monthRow['other_cost']) ? (float)$monthRow['other_cost'] : 0.0,
            '안전관리비' => isset($monthRow['safety_cost']) ? (float)$monthRow['safety_cost'] : 0.0,
            '월 보정' => isset($monthRow['deduction']) ? (float)$monthRow['deduction'] : 0.0,
        );
        foreach ($optionalComponents as $componentLabel => $componentAmount) {
            if (abs($componentAmount) >= 0.0001) $detailComponents[] = array('label' => $componentLabel, 'amount' => $componentAmount);
        }
        $detailPayload['cost'][] = array(
            'label' => $detailYear . '년 ' . $detailMonth . '월 투입원가',
            'amount' => $monthInputCost,
            'components' => $detailComponents,
        );
    }
    $companyProfitDetailData['p' . $detailProjectId] = $detailPayload;
}
$companyProfitJsonOptions = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
if (defined('JSON_UNESCAPED_UNICODE')) $companyProfitJsonOptions |= JSON_UNESCAPED_UNICODE;
$companyProfitDetailJson = json_encode($companyProfitDetailData, $companyProfitJsonOptions);
if (!is_string($companyProfitDetailJson)) $companyProfitDetailJson = '{}';
?>
<script type="application/json" id="cpDetailData"><?php echo $companyProfitDetailJson; ?></script>

<div id="cpDetailModal" class="cp-detail-modal" hidden>
  <div class="cp-detail-modal__backdrop" data-cp-modal-close="1"></div>
  <div class="cp-detail-modal__panel" role="dialog" aria-modal="true" aria-labelledby="cpDetailModalTitle">
    <div class="cp-detail-modal__head">
      <div><h3 id="cpDetailModalTitle">상세 내역</h3><p>조회기간에 반영된 월별 금액입니다.</p></div>
      <button type="button" class="cp-detail-modal__close" data-cp-modal-close="1" aria-label="닫기">&times;</button>
    </div>
    <div id="cpDetailModalBody" class="cp-detail-modal__body"></div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('cpDetailModal');
  var title = document.getElementById('cpDetailModalTitle');
  var body = document.getElementById('cpDetailModalBody');
  var dataElement = document.getElementById('cpDetailData');
  var detailData = {};
  var lastTrigger = null;
  if (!modal || !title || !body || !dataElement) return;
  try {
    detailData = JSON.parse(dataElement.textContent || dataElement.innerText || '{}');
  } catch (error) {
    detailData = {};
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function money(value) {
    var amount = Math.round(Number(value) || 0);
    return String(amount).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '원';
  }

  function renderSales(rows) {
    var html = '<div class="cp-detail-list">';
    var i;
    if (!rows || !rows.length) return html + '<div class="cp-detail-empty">조회기간 내 매출 내역이 없습니다.</div></div>';
    for (i = 0; i < rows.length; i++) {
      var row = rows[i] || {};
      var confirmed = row.type === 'confirmed';
      html += '<div class="cp-detail-row"><div class="cp-detail-row__main"><div class="cp-detail-row__title">';
      html += '<span>' + escapeHtml(row.label) + '</span>';
      html += confirmed
        ? '<span class="cp-detail-badge cp-detail-badge--confirmed">기성</span>'
        : '<span class="cp-detail-badge cp-detail-badge--expected">예상매출</span>';
      if (confirmed && Number(row.count) > 0) html += '<span class="cp-help">' + Number(row.count) + '건</span>';
      html += '</div><div class="cp-detail-row__amount">' + money(row.amount) + '</div></div></div>';
    }
    return html + '</div>';
  }

  function renderCost(rows) {
    var html = '<div class="cp-detail-list">';
    var i;
    var j;
    if (!rows || !rows.length) return html + '<div class="cp-detail-empty">조회기간 내 투입원가 내역이 없습니다.</div></div>';
    for (i = 0; i < rows.length; i++) {
      var row = rows[i] || {};
      var components = row.components || [];
      html += '<div class="cp-detail-row"><div class="cp-detail-row__main">';
      html += '<div class="cp-detail-row__title"><span>' + escapeHtml(row.label) + '</span></div>';
      html += '<div class="cp-detail-row__amount">' + money(row.amount) + '</div></div><div class="cp-detail-components">';
      for (j = 0; j < components.length; j++) {
        html += '<span class="cp-detail-component">' + escapeHtml(components[j].label) + ' ' + money(components[j].amount) + '</span>';
      }
      html += '</div></div>';
    }
    return html + '</div>';
  }

  function closeModal() {
    modal.setAttribute('hidden', 'hidden');
    document.body.className = document.body.className.replace(/\s*cp-detail-modal-open/g, '');
    body.innerHTML = '';
    if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
  }

  function openModal(trigger) {
    var detailType = trigger.getAttribute('data-cp-detail-type');
    var projectId = trigger.getAttribute('data-cp-project-id');
    var projectData = detailData['p' + projectId] || {};
    if (detailType !== 'sales' && detailType !== 'cost') return;
    lastTrigger = trigger;
    title.textContent = trigger.getAttribute('data-cp-title') || '상세 내역';
    body.innerHTML = detailType === 'sales' ? renderSales(projectData.sales) : renderCost(projectData.cost);
    modal.removeAttribute('hidden');
    if (document.body.className.indexOf('cp-detail-modal-open') < 0) document.body.className += ' cp-detail-modal-open';
    var closeButton = modal.querySelector('.cp-detail-modal__close');
    if (closeButton) closeButton.focus();
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    while (target && target !== document && !target.getAttribute('data-cp-detail-type') && !target.getAttribute('data-cp-modal-close')) target = target.parentNode;
    if (!target || target === document) return;
    if (target.getAttribute('data-cp-detail-type')) openModal(target);
    if (target.getAttribute('data-cp-modal-close')) closeModal();
  });
  document.addEventListener('keydown', function (event) {
    if ((event.key === 'Escape' || event.keyCode === 27) && !modal.hasAttribute('hidden')) closeModal();
  });
}());
</script>
