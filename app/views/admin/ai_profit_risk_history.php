<?php
/** 현장별 적자·원가율 위험분석 결과 화면. PHP 5.6 compatible. */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiProfitRiskService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}
require_once __DIR__ . '/../../services/AiProfitRiskService.php';

if (!function_exists('cpms_profit_risk_money')) {
    function cpms_profit_risk_money($value) { return $value===null||$value===''?'-':number_format((float)$value) . '원'; }
}
if (!function_exists('cpms_profit_risk_rate')) {
    function cpms_profit_risk_rate($value, $unit) { return $value===null||$value===''?'-':number_format((float)$value,1) . $unit; }
}
if (!function_exists('cpms_profit_risk_grade_label')) {
    function cpms_profit_risk_grade_label($value)
    {
        $labels=array('NORMAL'=>'안정','WATCH'=>'관심','WARNING'=>'주의','CRITICAL'=>'적자 위험','INSUFFICIENT'=>'판단자료 부족');
        return isset($labels[$value])?$labels[$value]:(string)$value;
    }
}
if (!function_exists('cpms_profit_risk_confidence_label')) {
    function cpms_profit_risk_confidence_label($value)
    {
        $labels=array('HIGH'=>'높음','MEDIUM'=>'보통','LOW'=>'낮음');
        return isset($labels[$value])?$labels[$value]:(string)$value;
    }
}
if (!function_exists('cpms_profit_risk_sales_label')) {
    function cpms_profit_risk_sales_label($value)
    {
        $labels=array('CONFIRMED'=>'확정매출','MIXED'=>'확정+예상 혼합','ESTIMATED'=>'예상매출','MISSING'=>'기준 확인 불가');
        return isset($labels[$value])?$labels[$value]:(string)$value;
    }
}
if (!function_exists('cpms_profit_risk_messages')) {
    function cpms_profit_risk_messages($json, $limit)
    {
        $decoded=AiProfitRiskService::decodeData($json);
        $messages=array();
        foreach ($decoded as $value) {
            if (!is_string($value) || trim($value)==='') continue;
            $messages[]=function_exists('mb_substr')?mb_substr(trim($value),0,500,'UTF-8'):substr(trim($value),0,500);
            if (count($messages)>=$limit) break;
        }
        return $messages;
    }
}
if (!function_exists('cpms_profit_risk_factors')) {
    function cpms_profit_risk_factors($json)
    {
        $decoded=AiProfitRiskService::decodeData($json);
        $allowed=array('type','label','severity','confidence','title','observed_value','baseline_value','difference_value','unit','evidence','recommended_action');
        $rows=array();
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;
            $safe=array();
            foreach ($allowed as $field) {
                if (!array_key_exists($field,$item)) continue;
                if (is_scalar($item[$field]) || $item[$field]===null) $safe[$field]=$item[$field];
                else if ($field==='evidence' && is_array($item[$field])) {
                    $safe[$field]=array();
                    foreach ($item[$field] as $evidence) {
                        if (!is_string($evidence) || trim($evidence)==='') continue;
                        $safe[$field][]=function_exists('mb_substr')?mb_substr(trim($evidence),0,300,'UTF-8'):substr(trim($evidence),0,300);
                        if (count($safe[$field])>=10) break;
                    }
                }
            }
            if (empty($safe['type'])) continue;
            $rows[]=$safe;
            if (count($rows)>=50) break;
        }
        return $rows;
    }
}
if (!function_exists('cpms_profit_risk_factor_value')) {
    function cpms_profit_risk_factor_value($value, $unit)
    {
        if ($value===null || $value==='') return '-';
        if ($unit==='원') return cpms_profit_risk_money($value);
        if ($unit==='%' || $unit==='%p') return number_format((float)$value,1) . $unit;
        if ($unit==='점') return number_format((float)$value,1) . '점';
        return number_format((float)$value,1);
    }
}

$profitRiskHistoryPdo=null;
$profitRiskHistoryDbFailed=false;
try { $profitRiskHistoryPdo=Db::pdo(); } catch (Exception $e) { $profitRiskHistoryDbFailed=true; error_log('[AI Profit Risk History] db initialization failed'); }
$profitRiskInstalled=false;
$latestContext=array('analysis_date'=>'','target_ym'=>'');
if (!$profitRiskHistoryDbFailed && $profitRiskHistoryPdo) {
    try {
        $profitRiskInstalled=AiProfitRiskService::isInstalled($profitRiskHistoryPdo);
        if ($profitRiskInstalled) $latestContext=AiProfitRiskService::latestResultContext($profitRiskHistoryPdo);
    } catch (Exception $e) { $profitRiskHistoryDbFailed=true; }
}
$filters=array(
    'analysis_date'=>isset($_GET['analysis_date'])?trim((string)$_GET['analysis_date']):'',
    'target_ym'=>isset($_GET['target_ym'])?trim((string)$_GET['target_ym']):'',
    'project_id'=>isset($_GET['project_id'])?(int)$_GET['project_id']:0,
    'project_status'=>isset($_GET['project_status'])?trim((string)$_GET['project_status']):'',
    'risk_grade'=>isset($_GET['risk_grade'])?trim((string)$_GET['risk_grade']):'',
    'confidence_level'=>isset($_GET['confidence_level'])?trim((string)$_GET['confidence_level']):'',
    'sales_basis'=>isset($_GET['sales_basis'])?trim((string)$_GET['sales_basis']):'',
    'primary_risk_type'=>isset($_GET['primary_risk_type'])?trim((string)$_GET['primary_risk_type']):'',
    'q'=>isset($_GET['q'])?trim((string)$_GET['q']):''
);
if ($filters['analysis_date']==='' && !empty($latestContext['analysis_date'])) $filters['analysis_date']=$latestContext['analysis_date'];
if ($filters['target_ym']==='' && !empty($latestContext['target_ym'])) $filters['target_ym']=$latestContext['target_ym'];
$profitRiskPage=isset($_GET['page'])?max(1,(int)$_GET['page']):1;
$profitRiskPerPage=50;
$profitRiskTotal=0;
$profitRiskRows=array();
$profitRiskSummary=array('project_count'=>0,'normal_count'=>0,'watch_count'=>0,'warning_count'=>0,'critical_count'=>0,'insufficient_count'=>0,'monthly_sales_total'=>0,'monthly_input_total'=>0,'monthly_profit_total'=>0,'cumulative_profit_total'=>0,'last_calculated_at'=>'');
$profitRiskOptions=array('projects'=>array(),'statuses'=>array(),'dates'=>array(),'months'=>array());
if ($profitRiskInstalled && !$profitRiskHistoryDbFailed) {
    try {
        $profitRiskTotal=AiProfitRiskService::countResults($profitRiskHistoryPdo,$filters);
        $profitRiskSummary=AiProfitRiskService::historySummary($profitRiskHistoryPdo,$filters);
        $profitRiskOptions=AiProfitRiskService::historyOptions($profitRiskHistoryPdo);
    } catch (Exception $e) { $profitRiskHistoryDbFailed=true; }
}
$profitRiskPages=max(1,(int)ceil($profitRiskTotal/$profitRiskPerPage));
if ($profitRiskPage>$profitRiskPages) $profitRiskPage=$profitRiskPages;
if ($profitRiskInstalled && !$profitRiskHistoryDbFailed) {
    try { $profitRiskRows=AiProfitRiskService::listResults($profitRiskHistoryPdo,$filters,$profitRiskPage,$profitRiskPerPage); } catch (Exception $e) { $profitRiskRows=array(); $profitRiskHistoryDbFailed=true; }
}
$pageParams=$_GET;
$pageParams['r']='admin/ai_profit_risk_history';
?>

<style>
  .prh-wrap{color:#0f172a}.prh-wrap *{box-sizing:border-box}.prh-hero,.prh-card,.prh-filter,.prh-table-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}
  .prh-hero{padding:24px;background:linear-gradient(135deg,#fff 0%,#fef2f2 100%)}.prh-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.prh-hero p{margin:9px 0 0;color:#475569;line-height:1.7}.prh-links{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px}.prh-link,.prh-btn{display:inline-flex;align-items:center;justify-content:center;min-height:39px;padding:8px 13px;border-radius:10px;background:#b91c1c;color:#fff;text-decoration:none;font-weight:800;border:0}.prh-link.secondary,.prh-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}
  .prh-note,.prh-warning{margin-top:14px;padding:14px 16px;border-radius:13px;font-size:13px;line-height:1.7}.prh-note{border:1px solid #fecaca;background:#fef2f2;color:#991b1b}.prh-warning{border:1px solid #fde68a;background:#fffbeb;color:#92400e}.prh-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.prh-card{padding:16px}.prh-label{font-size:11px;color:#64748b;font-weight:800}.prh-value{margin-top:7px;font-size:18px;font-weight:900;overflow-wrap:anywhere}
  .prh-filter{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:14px;padding:17px}.prh-field label{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.prh-field input,.prh-field select{width:100%;height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.prh-actions{display:flex;align-items:flex-end;gap:7px}
  .prh-table-card{margin-top:14px;overflow:hidden}.prh-scroll{overflow-x:auto}.prh-table{width:100%;min-width:1900px;border-collapse:collapse;font-size:12px}.prh-table th,.prh-table td{padding:11px 9px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.prh-table th{background:#f8fafc;color:#475569;font-weight:900;white-space:nowrap}.prh-table td.money{text-align:right;white-space:nowrap}.prh-badge{display:inline-flex;padding:4px 8px;border-radius:999px;background:#e2e8f0;color:#334155;font-weight:900;white-space:nowrap}.prh-badge.NORMAL{background:#dcfce7;color:#166534}.prh-badge.WATCH{background:#dbeafe;color:#1d4ed8}.prh-badge.WARNING{background:#ffedd5;color:#c2410c}.prh-badge.CRITICAL{background:#fee2e2;color:#b91c1c}.prh-badge.INSUFFICIENT{background:#e2e8f0;color:#475569}.prh-detail{min-width:1100px;margin-top:8px;padding:13px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}.prh-detail table{width:100%;border-collapse:collapse}.prh-detail th,.prh-detail td{padding:8px;border:1px solid #e2e8f0;vertical-align:top}.prh-detail ul{margin:7px 0;padding-left:20px}.prh-pager{display:flex;justify-content:center;gap:8px;padding:15px}.prh-pager a,.prh-pager span{padding:8px 12px;border:1px solid #cbd5e1;border-radius:9px;text-decoration:none;color:#334155;background:#fff}.prh-pager .current{background:#b91c1c;color:#fff;border-color:#b91c1c}
  @media(max-width:1100px){.prh-summary,.prh-filter{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.prh-hero{padding:18px}.prh-summary,.prh-filter{grid-template-columns:1fr}.prh-links,.prh-links .prh-link,.prh-actions,.prh-actions .prh-btn{width:100%}}
</style>

<div class="prh-wrap">
  <section class="prh-hero"><h2>현장별 적자·원가율 위험분석 결과</h2><p>저장된 매출·스냅샷·월말 예측을 이용한 관리자용 관리 참고자료입니다.</p><div class="prh-links"><a class="prh-link" href="?r=admin%2Fai_profit_risk_setup">위험분석 설정</a><a class="prh-link secondary" href="?r=admin%2Fai_executive_brief">대표용 경영 브리핑</a><a class="prh-link secondary" href="?r=admin%2Fai_anomaly_history">이상징후 결과</a><a class="prh-link secondary" href="?r=admin%2Fai_reliability_history">입력 신뢰도 결과</a><a class="prh-link secondary" href="?r=admin%2Fai_forecast_history">월말 예측 결과</a></div></section>
  <div class="prh-note"><strong>현재 결과는 입력된 매출과 월말 예상 투입비를 이용한 관리 참고자료이며 확정손익이 아닙니다.</strong><br>향후 잔여 공사비와 변경계약은 별도로 확인해야 합니다. 위험등급은 확인 우선순위이며 현장이나 책임자에 대한 평가가 아닙니다.</div>
  <?php if ($profitRiskHistoryDbFailed): ?><div class="prh-warning">위험분석 결과를 불러오지 못했습니다. DB 연결 상태를 확인해주세요.</div><?php elseif (!$profitRiskInstalled): ?><div class="prh-warning"><strong>적자·원가율 위험분석 기능이 아직 설치되지 않았습니다.</strong><br><a href="?r=admin%2Fai_profit_risk_setup">적자·원가율 위험 설정</a>에서 전용 테이블을 설치해주세요.</div><?php endif; ?>

  <section class="prh-summary"><?php $summaryCards=array(
    '분석 현장 수'=>number_format((int)$profitRiskSummary['project_count']) . '개','안정 현장'=>number_format((int)$profitRiskSummary['normal_count']) . '개',
    '관심 현장'=>number_format((int)$profitRiskSummary['watch_count']) . '개','주의 현장'=>number_format((int)$profitRiskSummary['warning_count']) . '개',
    '적자 위험 현장'=>number_format((int)$profitRiskSummary['critical_count']) . '개','판단자료 부족'=>number_format((int)$profitRiskSummary['insufficient_count']) . '개',
    '월 예상매출 합계'=>number_format((float)$profitRiskSummary['monthly_sales_total']) . '원','월 예상투입비 합계'=>number_format((float)$profitRiskSummary['monthly_input_total']) . '원',
    '월 예상손익 합계'=>number_format((float)$profitRiskSummary['monthly_profit_total']) . '원','누적 예상손익 합계'=>number_format((float)$profitRiskSummary['cumulative_profit_total']) . '원',
    '최근 분석시각'=>!empty($profitRiskSummary['last_calculated_at'])?$profitRiskSummary['last_calculated_at']:'-'
  ); foreach($summaryCards as $label=>$value): ?><div class="prh-card"><div class="prh-label"><?php echo h($label); ?></div><div class="prh-value"><?php echo h($value); ?></div></div><?php endforeach; ?></section>

  <form class="prh-filter" method="get"><input type="hidden" name="r" value="admin/ai_profit_risk_history">
    <div class="prh-field"><label>분석일</label><select name="analysis_date"><option value="">전체</option><?php foreach((array)$profitRiskOptions['dates'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['analysis_date']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="prh-field"><label>대상 월</label><select name="target_ym"><option value="">전체</option><?php foreach((array)$profitRiskOptions['months'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['target_ym']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="prh-field"><label>프로젝트</label><select name="project_id"><option value="0">전체</option><?php foreach((array)$profitRiskOptions['projects'] as $option): ?><option value="<?php echo h((int)$option['project_id']); ?>"<?php echo $filters['project_id']==(int)$option['project_id']?' selected':''; ?>><?php echo h(isset($option['project_name'])?$option['project_name']:''); ?></option><?php endforeach; ?></select></div>
    <div class="prh-field"><label>프로젝트 상태</label><select name="project_status"><option value="">전체</option><?php foreach((array)$profitRiskOptions['statuses'] as $option): $value=isset($option['status'])?(string)$option['status']:''; ?><option value="<?php echo h($value); ?>"<?php echo $filters['project_status']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="prh-field"><label>위험등급</label><select name="risk_grade"><option value="">전체</option><?php foreach(array('CRITICAL','WARNING','WATCH','INSUFFICIENT','NORMAL') as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['risk_grade']===$value?' selected':''; ?>><?php echo h(cpms_profit_risk_grade_label($value)); ?></option><?php endforeach; ?></select></div>
    <div class="prh-field"><label>분석 신뢰수준</label><select name="confidence_level"><option value="">전체</option><?php foreach(array('HIGH','MEDIUM','LOW') as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['confidence_level']===$value?' selected':''; ?>><?php echo h(cpms_profit_risk_confidence_label($value)); ?></option><?php endforeach; ?></select></div>
    <div class="prh-field"><label>매출기준</label><select name="sales_basis"><option value="">전체</option><?php foreach(array('CONFIRMED','MIXED','ESTIMATED','MISSING') as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['sales_basis']===$value?' selected':''; ?>><?php echo h(cpms_profit_risk_sales_label($value)); ?></option><?php endforeach; ?></select></div>
    <div class="prh-field"><label>대표 위험종류</label><select name="primary_risk_type"><option value="">전체</option><?php foreach(AiProfitRiskService::riskLabels() as $value=>$label): ?><option value="<?php echo h($value); ?>"<?php echo $filters['primary_risk_type']===$value?' selected':''; ?>><?php echo h($label); ?></option><?php endforeach; ?></select></div>
    <div class="prh-field"><label>현장명 검색</label><input type="text" name="q" value="<?php echo h($filters['q']); ?>" maxlength="100" placeholder="현장명"></div>
    <div class="prh-actions"><button class="prh-btn" type="submit">조회</button><a class="prh-btn secondary" href="?r=admin%2Fai_profit_risk_history">초기화</a></div>
  </form>

  <section class="prh-table-card"><div class="prh-scroll"><table class="prh-table"><thead><tr><th>현장</th><th>상태</th><th>월 예상매출</th><th>월 예상투입비</th><th>월 예상손익</th><th>월 원가율</th><th>누적 원가율</th><th>계약 대비 투입률</th><th>입력 신뢰도</th><th>이상징후</th><th>위험점수</th><th>위험등급</th><th>분석 신뢰수준</th><th>매출기준</th><th>상세</th></tr></thead><tbody>
  <?php if(count($profitRiskRows)===0): ?><tr><td colspan="15" style="padding:34px;text-align:center;color:#64748b;">조회된 위험분석 결과가 없습니다.</td></tr><?php else: foreach($profitRiskRows as $row):
    $factors=cpms_profit_risk_factors(isset($row['risk_factor_data'])?$row['risk_factor_data']:'');
    $recommendations=cpms_profit_risk_messages(isset($row['recommendation_data'])?$row['recommendation_data']:'',30);
    $warnings=cpms_profit_risk_messages(isset($row['warning_data'])?$row['warning_data']:'',30);
  ?><tr>
    <td><strong><?php echo h($row['project_name_snapshot']); ?></strong><div style="color:#64748b;margin-top:3px;">#<?php echo h((int)$row['project_id']); ?> · <?php echo h($row['target_ym']); ?></div></td><td><?php echo h($row['project_status_snapshot']); ?></td>
    <td class="money"><?php echo h(cpms_profit_risk_money($row['monthly_sales_amount'])); ?></td><td class="money"><?php echo h(cpms_profit_risk_money($row['monthly_forecast_input_amount'])); ?></td><td class="money"><strong><?php echo h(cpms_profit_risk_money($row['monthly_forecast_profit_amount'])); ?></strong></td>
    <td><?php echo h(cpms_profit_risk_rate($row['monthly_forecast_cost_rate'],'%')); ?></td><td><?php echo h(cpms_profit_risk_rate($row['cumulative_projected_cost_rate'],'%')); ?></td><td><?php echo h(cpms_profit_risk_rate($row['contract_input_utilization_rate'],'%')); ?></td>
    <td><?php echo $row['reliability_score']!==null?h(number_format((float)$row['reliability_score'],1) . '점'):'-'; ?></td><td><?php echo h(!empty($row['anomaly_grade'])?$row['anomaly_grade']:'-'); ?></td><td><?php echo $row['risk_score']!==null?h(number_format((float)$row['risk_score'],1) . '점'):'-'; ?></td>
    <td><span class="prh-badge <?php echo h($row['risk_grade']); ?>"><?php echo h(cpms_profit_risk_grade_label($row['risk_grade'])); ?></span></td><td><?php echo h(cpms_profit_risk_confidence_label($row['confidence_level'])); ?></td><td><?php echo h(cpms_profit_risk_sales_label($row['sales_basis'])); ?></td>
    <td><details><summary>보기</summary><div class="prh-detail">
      <h4>월 예상정보</h4><table><tr><th>월 예상매출</th><td><?php echo h(cpms_profit_risk_money($row['monthly_sales_amount'])); ?></td><th>월 현재 투입비</th><td><?php echo h(cpms_profit_risk_money($row['monthly_current_input_amount'])); ?></td><th>월 예상 투입비</th><td><?php echo h(cpms_profit_risk_money($row['monthly_forecast_input_amount'])); ?></td></tr><tr><th>예상범위</th><td><?php echo h(cpms_profit_risk_money($row['monthly_forecast_low_amount'])); ?> ~ <?php echo h(cpms_profit_risk_money($row['monthly_forecast_high_amount'])); ?></td><th>월 예상손익</th><td><?php echo h(cpms_profit_risk_money($row['monthly_forecast_profit_amount'])); ?></td><th>원가율 / 이익률</th><td><?php echo h(cpms_profit_risk_rate($row['monthly_forecast_cost_rate'],'%')); ?> / <?php echo h(cpms_profit_risk_rate($row['monthly_forecast_margin_rate'],'%')); ?></td></tr></table>
      <h4>누적 예상정보</h4><table><tr><th>누적매출</th><td><?php echo h(cpms_profit_risk_money($row['cumulative_sales_amount'])); ?></td><th>누적 현재 투입비</th><td><?php echo h(cpms_profit_risk_money($row['cumulative_current_input_amount'])); ?></td><th>누적 예상 투입비</th><td><?php echo h(cpms_profit_risk_money($row['cumulative_projected_input_amount'])); ?></td></tr><tr><th>누적 예상손익</th><td><?php echo h(cpms_profit_risk_money($row['cumulative_projected_profit_amount'])); ?></td><th>누적 예상원가율</th><td><?php echo h(cpms_profit_risk_rate($row['cumulative_projected_cost_rate'],'%')); ?></td><th>누적 예상이익률</th><td><?php echo h(cpms_profit_risk_rate($row['cumulative_projected_margin_rate'],'%')); ?></td></tr></table>
      <h4>계약 비교</h4><table><tr><th>계약금액</th><td><?php echo h(cpms_profit_risk_money($row['contract_amount'])); ?></td><th>계약 대비 누적 예상투입률</th><td><?php echo h(cpms_profit_risk_rate($row['contract_input_utilization_rate'],'%')); ?></td><th>투입 후 계약잔여금액</th><td><?php echo h(cpms_profit_risk_money($row['contract_remaining_after_input'])); ?></td></tr></table><p style="color:#92400e;">현재 누적 투입비와 이번 달 예상 투입비를 계약금액과 비교한 참고값이며 향후 잔여 공사비는 포함되지 않았습니다.</p>
      <h4>데이터 상태</h4><table><tr><th>입력 신뢰도</th><td><?php echo $row['reliability_score']!==null?h(number_format((float)$row['reliability_score'],1) . '점'):'-'; ?> / <?php echo h($row['reliability_grade']); ?></td><th>이상징후 등급</th><td><?php echo h(!empty($row['anomaly_grade'])?$row['anomaly_grade']:'-'); ?></td><th>매출기준</th><td><?php echo h(cpms_profit_risk_sales_label($row['sales_basis'])); ?></td></tr><tr><th>분석 신뢰수준</th><td><?php echo h(cpms_profit_risk_confidence_label($row['confidence_level'])); ?></td><th>스냅샷 날짜</th><td><?php echo h(!empty($row['snapshot_date'])?$row['snapshot_date']:'-'); ?></td><th>예측 날짜</th><td><?php echo h(!empty($row['forecast_date'])?$row['forecast_date']:'-'); ?></td></tr></table>
      <h4>위험요소</h4><table><thead><tr><th>위험 이름</th><th>중요도</th><th>신뢰수준</th><th>현재값</th><th>비교기준</th><th>차이</th><th>판단근거</th><th>확인 권장사항</th></tr></thead><tbody><?php if(count($factors)===0): ?><tr><td colspan="8">표시할 위험요소가 없습니다.</td></tr><?php else: foreach($factors as $factor): $unit=isset($factor['unit'])?(string)$factor['unit']:''; ?><tr><td><strong><?php echo h(isset($factor['label'])?$factor['label']:'-'); ?></strong><br><small><?php echo h(isset($factor['title'])?$factor['title']:''); ?></small></td><td><?php echo h(cpms_profit_risk_grade_label(isset($factor['severity'])?$factor['severity']:'')); ?></td><td><?php echo h(cpms_profit_risk_confidence_label(isset($factor['confidence'])?$factor['confidence']:'')); ?></td><td><?php echo h(cpms_profit_risk_factor_value(isset($factor['observed_value'])?$factor['observed_value']:null,$unit)); ?></td><td><?php echo h(cpms_profit_risk_factor_value(isset($factor['baseline_value'])?$factor['baseline_value']:null,$unit)); ?></td><td><?php echo h(cpms_profit_risk_factor_value(isset($factor['difference_value'])?$factor['difference_value']:null,$unit)); ?></td><td><?php if(!empty($factor['evidence'])): ?><ul><?php foreach($factor['evidence'] as $evidence): ?><li><?php echo h($evidence); ?></li><?php endforeach; ?></ul><?php else: ?>-<?php endif; ?></td><td><?php echo h(isset($factor['recommended_action'])?$factor['recommended_action']:'-'); ?></td></tr><?php endforeach; endif; ?></tbody></table>
      <?php if(count($recommendations)>0): ?><h4>확인 권장사항</h4><ul><?php foreach($recommendations as $message): ?><li><?php echo h($message); ?></li><?php endforeach; ?></ul><?php endif; ?><?php if(count($warnings)>0): ?><h4>필수 안내</h4><ul style="color:#92400e;"><?php foreach($warnings as $message): ?><li><?php echo h($message); ?></li><?php endforeach; ?></ul><?php endif; ?>
      <div style="margin-top:10px;color:#64748b;">분석일 <?php echo h($row['analysis_date']); ?> · 최초 계산 <?php echo h($row['first_created_at']); ?> · 최근 계산 <?php echo h($row['last_calculated_at']); ?> · 계산 <?php echo h(number_format((int)$row['calculation_count'])); ?>회</div>
    </div></details></td>
  </tr><?php endforeach; endif; ?></tbody></table></div><?php if($profitRiskPages>1): ?><nav class="prh-pager"><?php if($profitRiskPage>1): $pageParams['page']=$profitRiskPage-1; ?><a href="?<?php echo h(http_build_query($pageParams)); ?>">이전</a><?php endif; ?><span class="current"><?php echo h($profitRiskPage); ?> / <?php echo h($profitRiskPages); ?></span><?php if($profitRiskPage<$profitRiskPages): $pageParams['page']=$profitRiskPage+1; ?><a href="?<?php echo h(http_build_query($pageParams)); ?>">다음</a><?php endif; ?></nav><?php endif; ?></section>
</div>
