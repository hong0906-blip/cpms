<?php
/**
 * 투입비 입력 신뢰도 및 입력 지연 분석 결과 화면.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiInputReliabilityService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/AiInputReliabilityService.php';

if (!function_exists('cpms_ai_reliability_money')) {
    function cpms_ai_reliability_money($value)
    {
        return number_format((float)$value) . '원';
    }
}

if (!function_exists('cpms_ai_reliability_score')) {
    function cpms_ai_reliability_score($value)
    {
        return $value === null || $value === '' ? '-' : number_format(round((float)$value)) . '점';
    }
}

if (!function_exists('cpms_ai_reliability_rate')) {
    function cpms_ai_reliability_rate($value)
    {
        return $value === null || $value === '' ? '-' : number_format((float)$value, 1) . '%';
    }
}

if (!function_exists('cpms_ai_reliability_grade_label')) {
    function cpms_ai_reliability_grade_label($value)
    {
        $labels = array('HIGH'=>'높음','GOOD'=>'양호','CAUTION'=>'주의','LOW'=>'낮음','INSUFFICIENT'=>'판단자료 부족');
        return isset($labels[$value]) ? $labels[$value] : (string)$value;
    }
}

if (!function_exists('cpms_ai_reliability_status_label')) {
    function cpms_ai_reliability_status_label($value)
    {
        $labels = array('READY'=>'분석 가능','LIMITED'=>'자료 제한적','INSUFFICIENT'=>'자료 부족');
        return isset($labels[$value]) ? $labels[$value] : (string)$value;
    }
}

if (!function_exists('cpms_ai_reliability_basis_label')) {
    function cpms_ai_reliability_basis_label($value)
    {
        $labels = array(
            'HISTORICAL_RATIO'=>'과거 입력완료율','RECENT_MEDIAN'=>'최근 완료월 중앙값',
            'LINEAR'=>'단순 기간 진행률','INSUFFICIENT'=>'예측자료 부족','MIXED'=>'비용별 혼합 방식'
        );
        return isset($labels[$value]) ? $labels[$value] : (string)$value;
    }
}

if (!function_exists('cpms_ai_reliability_category_label')) {
    function cpms_ai_reliability_category_label($value)
    {
        $labels = array(
            'labor'=>'노무비','outsourcing'=>'외주비','purchase'=>'구매품','material'=>'자재비',
            'equipment'=>'장비비','other_expense'=>'기타경비','safety'=>'안전관리비',
            'health'=>'보건비','other'=>'기타 투입비'
        );
        return isset($labels[$value]) ? $labels[$value] : (string)$value;
    }
}

if (!function_exists('cpms_ai_reliability_categories')) {
    function cpms_ai_reliability_categories($json)
    {
        $decoded = AiInputReliabilityService::decodeData($json);
        $allowedCategories = array('labor','outsourcing','purchase','material','equipment','other_expense','safety','health','other');
        $allowedFields = array(
            'label','expected','observed','current_amount','forecast_amount','basis_type','history_month_count',
            'latest_event_at','latest_event_age_days','event_count_30d','event_count_90d','average_input_lag_days','late_input_rate',
            'input_lag_sample_count','range_rate','forecast_change_rate','completeness_score','freshness_score',
            'history_score','input_timing_score','stability_score','score','grade','data_status','reasons','warnings'
        );
        $rows = array();
        foreach ($allowedCategories as $category) {
            if (!isset($decoded[$category]) || !is_array($decoded[$category])) continue;
            $safe = array();
            foreach ($allowedFields as $field) {
                if (!array_key_exists($field, $decoded[$category])) continue;
                $value = $decoded[$category][$field];
                if (is_scalar($value) || $value === null) $safe[$field] = $value;
                else if (($field === 'reasons' || $field === 'warnings') && is_array($value)) {
                    $safe[$field] = array();
                    foreach ($value as $message) {
                        if (!is_string($message) || trim($message) === '') continue;
                        $safe[$field][] = function_exists('mb_substr') ? mb_substr(trim($message), 0, 300, 'UTF-8') : substr(trim($message), 0, 300);
                        if (count($safe[$field]) >= 20) break;
                    }
                }
            }
            $rows[$category] = $safe;
        }
        return $rows;
    }
}

if (!function_exists('cpms_ai_reliability_messages')) {
    function cpms_ai_reliability_messages($json)
    {
        $decoded = AiInputReliabilityService::decodeData($json);
        $messages = array();
        foreach ($decoded as $value) {
            if (!is_string($value) || trim($value) === '') continue;
            $messages[] = function_exists('mb_substr') ? mb_substr(trim($value), 0, 300, 'UTF-8') : substr(trim($value), 0, 300);
            if (count($messages) >= 30) break;
        }
        return $messages;
    }
}

if (!function_exists('cpms_ai_reliability_actors')) {
    function cpms_ai_reliability_actors($json)
    {
        $decoded = AiInputReliabilityService::decodeData($json);
        $allowed = array('actor_name','event_count','average_input_lag_days','late_input_count','late_input_rate','main_cost_type','latest_event_at');
        $rows = array();
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;
            $safe = array();
            foreach ($allowed as $field) if (array_key_exists($field, $item) && (is_scalar($item[$field]) || $item[$field] === null)) $safe[$field] = $item[$field];
            if (!isset($safe['actor_name']) || trim((string)$safe['actor_name']) === '') continue;
            $rows[] = $safe;
            if (count($rows) >= 100) break;
        }
        return $rows;
    }
}

$reliabilityHistoryPdo = Db::pdo();
$reliabilityInstalled = AiInputReliabilityService::isInstalled($reliabilityHistoryPdo);
$latestContext = AiInputReliabilityService::latestResultContext($reliabilityHistoryPdo);
$filters = array(
    'analysis_date'=>isset($_GET['analysis_date']) ? trim((string)$_GET['analysis_date']) : '',
    'target_ym'=>isset($_GET['target_ym']) ? trim((string)$_GET['target_ym']) : '',
    'project_id'=>isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0,
    'project_status'=>isset($_GET['project_status']) ? trim((string)$_GET['project_status']) : '',
    'grade'=>isset($_GET['grade']) ? trim((string)$_GET['grade']) : '',
    'data_status'=>isset($_GET['data_status']) ? trim((string)$_GET['data_status']) : '',
    'q'=>isset($_GET['q']) ? trim((string)$_GET['q']) : ''
);
if ($filters['analysis_date'] === '' && isset($latestContext['analysis_date'])) $filters['analysis_date'] = $latestContext['analysis_date'];
if ($filters['target_ym'] === '' && isset($latestContext['target_ym'])) $filters['target_ym'] = $latestContext['target_ym'];
$reliabilityPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$reliabilityPerPage = 50;
$reliabilityTotal = $reliabilityInstalled ? AiInputReliabilityService::countResults($reliabilityHistoryPdo, $filters) : 0;
$reliabilityPages = max(1, (int)ceil($reliabilityTotal / $reliabilityPerPage));
if ($reliabilityPage > $reliabilityPages) $reliabilityPage = $reliabilityPages;
$reliabilityRows = $reliabilityInstalled ? AiInputReliabilityService::listResults($reliabilityHistoryPdo, $filters, $reliabilityPage, $reliabilityPerPage) : array();
$reliabilitySummary = $reliabilityInstalled ? AiInputReliabilityService::historySummary($reliabilityHistoryPdo, $filters) : array();
$reliabilityOptions = $reliabilityInstalled ? AiInputReliabilityService::historyOptions($reliabilityHistoryPdo) : array('projects'=>array(),'statuses'=>array(),'dates'=>array(),'months'=>array());
$pageParams = $_GET;
$pageParams['r'] = 'admin/ai_reliability_history';
?>

<style>
  .rh-wrap{color:#0f172a}.rh-wrap *{box-sizing:border-box}.rh-hero,.rh-card,.rh-filter,.rh-table-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}
  .rh-hero{padding:24px;background:linear-gradient(135deg,#fff 0%,#f5f3ff 100%)}.rh-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.rh-hero p{margin:9px 0 0;color:#475569;line-height:1.7}.rh-links{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px}.rh-link,.rh-btn{display:inline-flex;align-items:center;justify-content:center;min-height:39px;padding:8px 13px;border-radius:10px;background:#7c3aed;color:#fff;text-decoration:none;font-weight:800;border:0}.rh-link.secondary,.rh-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}
  .rh-note,.rh-warning{margin-top:14px;padding:14px 16px;border-radius:13px;font-size:13px;line-height:1.7}.rh-note{border:1px solid #ddd6fe;background:#f5f3ff;color:#5b21b6}.rh-warning{border:1px solid #fde68a;background:#fffbeb;color:#92400e}.rh-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.rh-card{padding:16px}.rh-label{font-size:11px;color:#64748b;font-weight:800}.rh-value{margin-top:7px;font-size:18px;font-weight:900;overflow-wrap:anywhere}
  .rh-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px;padding:17px}.rh-field label{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.rh-field input,.rh-field select{width:100%;height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.rh-actions{display:flex;align-items:flex-end;gap:7px}
  .rh-table-card{margin-top:14px;overflow:hidden}.rh-scroll{overflow-x:auto}.rh-table{width:100%;min-width:1760px;border-collapse:collapse;font-size:12px}.rh-table th,.rh-table td{padding:11px 9px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.rh-table th{background:#f8fafc;color:#475569;font-weight:900;white-space:nowrap}.rh-table td.money{text-align:right;white-space:nowrap}.rh-badge{display:inline-flex;padding:4px 8px;border-radius:999px;background:#e2e8f0;color:#334155;font-weight:900;white-space:nowrap}.rh-badge.HIGH{background:#dcfce7;color:#166534}.rh-badge.GOOD{background:#dbeafe;color:#1d4ed8}.rh-badge.CAUTION{background:#ffedd5;color:#c2410c}.rh-badge.LOW{background:#fee2e2;color:#b91c1c}.rh-badge.INSUFFICIENT{background:#e2e8f0;color:#475569}.rh-detail{min-width:1250px;margin-top:8px;padding:13px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}.rh-detail table{width:100%;border-collapse:collapse}.rh-detail th,.rh-detail td{padding:8px;border:1px solid #e2e8f0;vertical-align:top}.rh-detail ul{margin:7px 0;padding-left:20px}.rh-component{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;margin-bottom:11px}.rh-component div{padding:9px;border:1px solid #e2e8f0;border-radius:9px;background:#fff}.rh-actor-note{margin-top:9px;color:#7c2d12}.rh-pager{display:flex;justify-content:center;gap:8px;padding:15px}.rh-pager a,.rh-pager span{padding:8px 12px;border:1px solid #cbd5e1;border-radius:9px;text-decoration:none;color:#334155;background:#fff}.rh-pager .current{background:#7c3aed;color:#fff;border-color:#7c3aed}
  @media(max-width:1000px){.rh-summary,.rh-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.rh-component{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.rh-hero{padding:18px}.rh-summary,.rh-filter,.rh-component{grid-template-columns:1fr}.rh-links,.rh-links .rh-link,.rh-actions,.rh-actions .rh-btn{width:100%}}
</style>

<div class="rh-wrap">
  <section class="rh-hero">
    <h2>투입비 입력 신뢰도 및 입력 지연 분석</h2>
    <p>저장된 월말 예측, 일일 스냅샷, 통합 비용 이벤트를 이용해 현장별 자료의 충분성·최신성·입력시점·예측 안정성을 점검합니다.</p>
    <div class="rh-links"><a class="rh-link" href="?r=admin%2Fai_reliability_setup">입력 신뢰도 설정</a><a class="rh-link secondary" href="?r=admin%2Fai_forecast_history">기본 월말 예측 결과</a><a class="rh-link secondary" href="?r=admin%2Fai_data_history">통합 비용 이력</a><a class="rh-link secondary" href="?r=admin%2Fai_data_audit">AI 데이터 준비상태 점검</a></div>
  </section>

  <div class="rh-note"><strong>입력 신뢰도는 현재 데이터의 충분성·최신성·예측근거를 점검한 참고지표이며 확정금액이나 직원 평가점수가 아닙니다.</strong><br>초기에는 이벤트와 스냅샷 표본이 적어 자료 제한적 또는 자료 부족으로 표시될 수 있습니다.</div>
  <?php if (!$reliabilityInstalled): ?><div class="rh-warning"><strong>입력 신뢰도 분석 기능이 아직 설치되지 않았습니다.</strong><br><a href="?r=admin%2Fai_reliability_setup">입력 신뢰도 설정</a>에서 전용 테이블을 설치해주세요.</div><?php endif; ?>

  <section class="rh-summary">
    <?php
      $summaryCards = array(
        '분석 현장 수'=>number_format(isset($reliabilitySummary['project_count'])?(int)$reliabilitySummary['project_count']:0) . '개',
        '평균 신뢰도'=>cpms_ai_reliability_score(isset($reliabilitySummary['average_score'])?$reliabilitySummary['average_score']:null),
        '신뢰도 높음'=>number_format(isset($reliabilitySummary['high_count'])?(int)$reliabilitySummary['high_count']:0) . '개',
        '신뢰도 양호'=>number_format(isset($reliabilitySummary['good_count'])?(int)$reliabilitySummary['good_count']:0) . '개',
        '주의 필요'=>number_format(isset($reliabilitySummary['caution_count'])?(int)$reliabilitySummary['caution_count']:0) . '개',
        '신뢰도 낮음'=>number_format(isset($reliabilitySummary['low_count'])?(int)$reliabilitySummary['low_count']:0) . '개',
        '자료 부족'=>number_format(isset($reliabilitySummary['insufficient_count'])?(int)$reliabilitySummary['insufficient_count']:0) . '개',
        '마지막 계산시각'=>!empty($reliabilitySummary['last_calculated_at'])?$reliabilitySummary['last_calculated_at']:'-'
      );
      foreach ($summaryCards as $label=>$value):
    ?><div class="rh-card"><div class="rh-label"><?php echo h($label); ?></div><div class="rh-value"><?php echo h($value); ?></div></div><?php endforeach; ?>
  </section>

  <form class="rh-filter" method="get">
    <input type="hidden" name="r" value="admin/ai_reliability_history">
    <div class="rh-field"><label>분석일</label><select name="analysis_date"><option value="">전체</option><?php foreach ((array)$reliabilityOptions['dates'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['analysis_date']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="rh-field"><label>대상 월</label><select name="target_ym"><option value="">전체</option><?php foreach ((array)$reliabilityOptions['months'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['target_ym']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="rh-field"><label>프로젝트</label><select name="project_id"><option value="0">전체</option><?php foreach ((array)$reliabilityOptions['projects'] as $option): ?><option value="<?php echo h((int)$option['project_id']); ?>"<?php echo $filters['project_id']==(int)$option['project_id']?' selected':''; ?>><?php echo h($option['project_name']); ?></option><?php endforeach; ?></select></div>
    <div class="rh-field"><label>프로젝트 상태</label><select name="project_status"><option value="">전체</option><?php foreach ((array)$reliabilityOptions['statuses'] as $option): $value=isset($option['status'])?(string)$option['status']:''; ?><option value="<?php echo h($value); ?>"<?php echo $filters['project_status']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="rh-field"><label>신뢰도 등급</label><select name="grade"><option value="">전체</option><?php foreach (array('HIGH','GOOD','CAUTION','LOW','INSUFFICIENT') as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['grade']===$value?' selected':''; ?>><?php echo h(cpms_ai_reliability_grade_label($value)); ?></option><?php endforeach; ?></select></div>
    <div class="rh-field"><label>자료 상태</label><select name="data_status"><option value="">전체</option><?php foreach (array('READY','LIMITED','INSUFFICIENT') as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['data_status']===$value?' selected':''; ?>><?php echo h(cpms_ai_reliability_status_label($value)); ?></option><?php endforeach; ?></select></div>
    <div class="rh-field"><label>현장명 검색</label><input type="text" name="q" value="<?php echo h($filters['q']); ?>" maxlength="100" placeholder="현장명"></div>
    <div class="rh-actions"><button class="rh-btn" type="submit">조회</button><a class="rh-btn secondary" href="?r=admin%2Fai_reliability_history">초기화</a></div>
  </form>

  <section class="rh-table-card"><div class="rh-scroll"><table class="rh-table"><thead><tr><th>현장</th><th>상태</th><th>월말 예상</th><th>예상범위</th><th>신뢰도 점수</th><th>등급</th><th>완전성</th><th>최신성</th><th>과거근거</th><th>입력시점</th><th>안정성</th><th>스냅샷</th><th>최근 입력</th><th>상세</th></tr></thead><tbody>
  <?php if (count($reliabilityRows)===0): ?><tr><td colspan="14" style="padding:34px;text-align:center;color:#64748b;">조회된 입력 신뢰도 결과가 없습니다.</td></tr><?php else: foreach ($reliabilityRows as $row):
      $categoryRows=cpms_ai_reliability_categories(isset($row['category_reliability_data'])?$row['category_reliability_data']:'');
      $reasons=cpms_ai_reliability_messages(isset($row['reason_data'])?$row['reason_data']:'');
      $warnings=cpms_ai_reliability_messages(isset($row['warning_data'])?$row['warning_data']:'');
      $actors=cpms_ai_reliability_actors(isset($row['actor_input_data'])?$row['actor_input_data']:'');
  ?><tr>
    <td><strong><?php echo h($row['project_name_snapshot']); ?></strong><div style="color:#64748b;margin-top:3px;">#<?php echo h((int)$row['project_id']); ?> · <?php echo h($row['target_ym']); ?></div></td>
    <td><?php echo h($row['project_status_snapshot']); ?></td>
    <td class="money"><strong><?php echo h(cpms_ai_reliability_money($row['forecast_input_amount'])); ?></strong></td>
    <td class="money"><?php echo h(cpms_ai_reliability_money($row['forecast_low_amount'])); ?><br>~ <?php echo h(cpms_ai_reliability_money($row['forecast_high_amount'])); ?></td>
    <td><strong><?php echo h(cpms_ai_reliability_score($row['reliability_score'])); ?></strong><div style="color:#64748b;">가용 <?php echo h(cpms_ai_reliability_rate($row['available_weight'])); ?></div></td>
    <td><span class="rh-badge <?php echo h($row['reliability_grade']); ?>"><?php echo h(cpms_ai_reliability_grade_label($row['reliability_grade'])); ?></span></td>
    <td><?php echo h(cpms_ai_reliability_score($row['completeness_score'])); ?></td><td><?php echo h(cpms_ai_reliability_score($row['freshness_score'])); ?></td><td><?php echo h(cpms_ai_reliability_score($row['history_score'])); ?></td><td><?php echo h(cpms_ai_reliability_score($row['input_timing_score'])); ?></td><td><?php echo h(cpms_ai_reliability_score($row['stability_score'])); ?></td>
    <td><?php echo h(!empty($row['snapshot_date'])?$row['snapshot_date']:'-'); ?><div style="color:#64748b;"><?php echo $row['snapshot_age_days']!==null?h(number_format((int)$row['snapshot_age_days']) . '일 전'):'-'; ?></div></td>
    <td><?php echo h(!empty($row['latest_event_at'])?$row['latest_event_at']:'-'); ?><div style="color:#64748b;">30일 <?php echo h(number_format((int)$row['event_count_30d'])); ?>건</div></td>
    <td><details><summary>보기</summary><div class="rh-detail">
      <div class="rh-component">
        <div><strong>완전성 25%</strong><br><?php echo h(cpms_ai_reliability_score($row['completeness_score'])); ?><br><small>예상 <?php echo h((int)$row['expected_category_count']); ?> / 확인 <?php echo h((int)$row['observed_category_count']); ?> / 누락 <?php echo h((int)$row['missing_category_count']); ?></small></div>
        <div><strong>최신성 20%</strong><br><?php echo h(cpms_ai_reliability_score($row['freshness_score'])); ?><br><small>스냅샷·최근 이벤트</small></div>
        <div><strong>과거근거 20%</strong><br><?php echo h(cpms_ai_reliability_score($row['history_score'])); ?><br><small><?php echo h((int)$row['history_month_count']); ?>개월</small></div>
        <div><strong>입력시점 20%</strong><br><?php echo h(cpms_ai_reliability_score($row['input_timing_score'])); ?><br><small><?php echo h((int)$row['input_lag_sample_count']); ?>건 · 평균 <?php echo $row['average_input_lag_days']!==null?h(number_format((float)$row['average_input_lag_days'],1) . '일'):'-'; ?></small></div>
        <div><strong>안정성 15%</strong><br><?php echo h(cpms_ai_reliability_score($row['stability_score'])); ?><br><small>범위율 <?php echo h(cpms_ai_reliability_rate($row['forecast_range_rate'])); ?></small></div>
      </div>
      <table><thead><tr><th>비용 종류</th><th>현재/예상</th><th>예측근거</th><th>과거월</th><th>최근 이벤트</th><th>30일 입력</th><th>평균 지연</th><th>5일 초과율</th><th>신뢰도</th><th>안내</th></tr></thead><tbody>
      <?php foreach ($categoryRows as $category=>$item): ?><tr>
        <td><?php echo h(cpms_ai_reliability_category_label($category)); ?></td>
        <td class="money"><?php echo h(cpms_ai_reliability_money(isset($item['current_amount'])?$item['current_amount']:0)); ?><br>→ <?php echo h(cpms_ai_reliability_money(isset($item['forecast_amount'])?$item['forecast_amount']:0)); ?></td>
        <td><?php echo h(cpms_ai_reliability_basis_label(isset($item['basis_type'])?$item['basis_type']:'')); ?></td>
        <td><?php echo h(number_format(isset($item['history_month_count'])?(int)$item['history_month_count']:0)); ?>개월</td>
        <td><?php echo h(!empty($item['latest_event_at'])?$item['latest_event_at']:'-'); ?></td>
        <td><?php echo h(number_format(isset($item['event_count_30d'])?(int)$item['event_count_30d']:0)); ?>건</td>
        <td><?php echo isset($item['average_input_lag_days'])&&$item['average_input_lag_days']!==null?h(number_format((float)$item['average_input_lag_days'],1) . '일'):'-'; ?></td>
        <td><?php echo h(cpms_ai_reliability_rate(isset($item['late_input_rate'])?$item['late_input_rate']:null)); ?></td>
        <td><?php echo h(cpms_ai_reliability_score(isset($item['score'])?$item['score']:null)); ?></td>
        <td><?php if (!empty($item['reasons'])): ?><ul><?php foreach ($item['reasons'] as $message): ?><li><?php echo h($message); ?></li><?php endforeach; ?></ul><?php endif; ?><?php if (!empty($item['warnings'])): ?><ul style="color:#b45309;"><?php foreach ($item['warnings'] as $message): ?><li><?php echo h($message); ?></li><?php endforeach; ?></ul><?php endif; ?></td>
      </tr><?php endforeach; ?></tbody></table>
      <?php if (count($reasons)>0): ?><h4>판단 근거</h4><ul><?php foreach ($reasons as $message): ?><li><?php echo h($message); ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if (count($warnings)>0): ?><h4>확인사항</h4><ul style="color:#b45309;"><?php foreach ($warnings as $message): ?><li><?php echo h($message); ?></li><?php endforeach; ?></ul><?php endif; ?>
      <details style="margin-top:12px;"><summary>입력자별 절차 참고자료 (유효 표본 5건 이상)</summary>
        <div class="rh-actor-note">입력자별 자료는 입력 절차 개선을 위한 참고자료입니다. 직원 평가나 인사평가 자료로 직접 사용하지 않습니다.</div>
        <table style="margin-top:8px;"><thead><tr><th>입력자</th><th>유효 입력</th><th>평균 입력 지연</th><th>5일 초과</th><th>주요 비용</th><th>최근 입력</th></tr></thead><tbody>
        <?php if (count($actors)===0): ?><tr><td colspan="6">표시할 유효 표본이 없습니다.</td></tr><?php else: foreach ($actors as $actor): ?><tr><td><?php echo h($actor['actor_name']); ?></td><td><?php echo h(number_format(isset($actor['event_count'])?(int)$actor['event_count']:0)); ?>건</td><td><?php echo isset($actor['average_input_lag_days'])?h(number_format((float)$actor['average_input_lag_days'],1) . '일'):'-'; ?></td><td><?php echo h(number_format(isset($actor['late_input_count'])?(int)$actor['late_input_count']:0)); ?>건 / <?php echo h(cpms_ai_reliability_rate(isset($actor['late_input_rate'])?$actor['late_input_rate']:null)); ?></td><td><?php echo h(cpms_ai_reliability_category_label(isset($actor['main_cost_type'])?$actor['main_cost_type']:'')); ?></td><td><?php echo h(isset($actor['latest_event_at'])?$actor['latest_event_at']:'-'); ?></td></tr><?php endforeach; endif; ?>
        </tbody></table>
      </details>
      <div style="margin-top:10px;color:#64748b;">분석일 <?php echo h($row['analysis_date']); ?> · 최초 계산 <?php echo h($row['first_created_at']); ?> · 최근 계산 <?php echo h($row['last_calculated_at']); ?> · 계산 <?php echo h(number_format((int)$row['calculation_count'])); ?>회</div>
    </div></details></td>
  </tr><?php endforeach; endif; ?>
  </tbody></table></div>
  <?php if ($reliabilityPages>1): ?><nav class="rh-pager"><?php if ($reliabilityPage>1): $pageParams['page']=$reliabilityPage-1; ?><a href="?<?php echo h(http_build_query($pageParams)); ?>">이전</a><?php endif; ?><span class="current"><?php echo h($reliabilityPage); ?> / <?php echo h($reliabilityPages); ?></span><?php if ($reliabilityPage<$reliabilityPages): $pageParams['page']=$reliabilityPage+1; ?><a href="?<?php echo h(http_build_query($pageParams)); ?>">다음</a><?php endif; ?></nav><?php endif; ?>
  </section>
</div>
