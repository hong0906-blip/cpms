<?php
$safetyCost = (isset($summary['safety_cost']) && is_array($summary['safety_cost'])) ? $summary['safety_cost'] : array();
$safetyLimitRate = isset($safetyCost['limit_use_rate']) ? (float)$safetyCost['limit_use_rate'] : 0.0;
$safetyTone = 'cp-company-rate-normal';
$safetyMessage = '전체 현장 안전관리비 사용 현황이 정상 범위입니다.';
if ($safetyLimitRate >= 100.0) {
    $safetyTone = 'cp-company-rate-loss';
    $safetyMessage = '전체 현장 110% 사용가능한도에 도달했거나 초과했습니다.';
} else if ($safetyLimitRate >= 80.0) {
    $safetyTone = 'cp-company-rate-warn';
    $safetyMessage = '전체 현장 110% 사용가능한도의 80% 이상을 사용했습니다.';
}
if (!function_exists('cpms_company_profit_safety_money_label')) {
function cpms_company_profit_safety_money_label($value) {
    if (function_exists('cpms_safety_cost_money_label')) return cpms_safety_cost_money_label($value);
    return number_format((float)round((float)$value)) . '원';
}}
if (!function_exists('cpms_company_profit_safety_rate_label')) {
function cpms_company_profit_safety_rate_label($value) {
    if (function_exists('cpms_safety_cost_rate_label')) return cpms_safety_cost_rate_label($value);
    return number_format((float)$value, 1) . '%';
}}
?>

<div class="cp-section cp-panel">
  <div class="cp-panel-title">
    <div>
      <h3>안전관리비 총 사용내역서</h3>
      <div class="cp-help">전체 현장 총집계 기준</div>
    </div>
    <span class="cp-rate-pill <?php echo h($safetyTone); ?>"><?php echo h($safetyMessage); ?></span>
  </div>
  <div class="cp-summary-grid">
    <div class="cp-summary-card">
      <div class="label">대상 현장</div>
      <div class="value"><?php echo number_format(isset($safetyCost['project_count']) ? (int)$safetyCost['project_count'] : 0); ?>개</div>
    </div>
    <div class="cp-summary-card">
      <div class="label">안전관리비 총액</div>
      <div class="value"><?php echo h(cpms_company_profit_safety_money_label(isset($safetyCost['contract_total']) ? $safetyCost['contract_total'] : 0)); ?></div>
    </div>
    <div class="cp-summary-card">
      <div class="label">110% 사용가능한도</div>
      <div class="value"><?php echo h(cpms_company_profit_safety_money_label(isset($safetyCost['limit_110']) ? $safetyCost['limit_110'] : 0)); ?></div>
    </div>
    <div class="cp-summary-card">
      <div class="label">현재 사용금액</div>
      <div class="value"><?php echo h(cpms_company_profit_safety_money_label(isset($safetyCost['used_total']) ? $safetyCost['used_total'] : 0)); ?></div>
    </div>
    <div class="cp-summary-card">
      <div class="label">남은금액</div>
      <div class="value <?php echo ((isset($safetyCost['remaining']) ? (float)$safetyCost['remaining'] : 0.0) < 0) ? 'cp-negative' : ''; ?>"><?php echo h(cpms_company_profit_safety_money_label(isset($safetyCost['remaining']) ? $safetyCost['remaining'] : 0)); ?></div>
      <div class="sub">남은 퍼센트 <?php echo h(cpms_company_profit_safety_rate_label(isset($safetyCost['remaining_rate']) ? $safetyCost['remaining_rate'] : 0)); ?></div>
    </div>
    <div class="cp-summary-card">
      <div class="label">사용률</div>
      <div class="value"><?php echo h(cpms_company_profit_safety_rate_label(isset($safetyCost['use_rate']) ? $safetyCost['use_rate'] : 0)); ?></div>
      <div class="sub">110% 한도 대비 <?php echo h(cpms_company_profit_safety_rate_label(isset($safetyCost['limit_use_rate']) ? $safetyCost['limit_use_rate'] : 0)); ?></div>
    </div>
  </div>
</div>
