<?php
/** 현장별 비용 이상징후 탐지 결과 화면. PHP 5.6 compatible. */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiAnomalyDetectionService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/AiAnomalyDetectionService.php';

if (!function_exists('cpms_ai_anomaly_money')) {
    function cpms_ai_anomaly_money($value) { return $value===null||$value===''?'-':number_format((float)$value) . '원'; }
}
if (!function_exists('cpms_ai_anomaly_rate')) {
    function cpms_ai_anomaly_rate($value) { return $value===null||$value===''?'-':number_format((float)$value,1) . '%'; }
}
if (!function_exists('cpms_ai_anomaly_value')) {
    function cpms_ai_anomaly_value($type, $value, $position)
    {
        if ($value===null || $value==='') return '-';
        if (in_array($type,array('SNAPSHOT_STALE','NO_RECENT_INPUT'),true)) return number_format((float)$value,1) . '일';
        if (in_array($type,array('BULK_BACKFILL','REPEATED_CORRECTION'),true)) return number_format((float)$value) . '건';
        if (in_array($type,array('FORECAST_RANGE_EXPANSION','CATEGORY_MIX_SHIFT'),true)) return number_format((float)$value,1) . ($position==='difference'?'%p':'%');
        return cpms_ai_anomaly_money($value);
    }
}
if (!function_exists('cpms_ai_anomaly_grade_label')) {
    function cpms_ai_anomaly_grade_label($value)
    {
        $labels=array('NORMAL'=>'정상','WATCH'=>'관심','WARNING'=>'주의','CRITICAL'=>'긴급 확인','INSUFFICIENT'=>'판단자료 부족');
        return isset($labels[$value])?$labels[$value]:(string)$value;
    }
}
if (!function_exists('cpms_ai_anomaly_confidence_label')) {
    function cpms_ai_anomaly_confidence_label($value)
    {
        $labels=array('HIGH'=>'높음','MEDIUM'=>'보통','LOW'=>'낮음');
        return isset($labels[$value])?$labels[$value]:(string)$value;
    }
}
if (!function_exists('cpms_ai_anomaly_data_status_label')) {
    function cpms_ai_anomaly_data_status_label($value)
    {
        $labels=array('READY'=>'분석 가능','LIMITED'=>'자료 제한적','INSUFFICIENT'=>'자료 부족');
        return isset($labels[$value])?$labels[$value]:(string)$value;
    }
}
if (!function_exists('cpms_ai_anomaly_type_label')) {
    function cpms_ai_anomaly_type_label($value)
    {
        $labels=AiAnomalyDetectionService::anomalyLabels();
        return isset($labels[$value])?$labels[$value]:(string)$value;
    }
}
if (!function_exists('cpms_ai_anomaly_category_label')) {
    function cpms_ai_anomaly_category_label($value)
    {
        $definitions=AiAnomalyDetectionService::categoryDefinitions();
        return isset($definitions[$value])?$definitions[$value]['label']:(string)$value;
    }
}
if (!function_exists('cpms_ai_anomaly_messages')) {
    function cpms_ai_anomaly_messages($json, $limit)
    {
        $decoded=AiAnomalyDetectionService::decodeData($json);
        $messages=array();
        foreach ($decoded as $value) {
            if (!is_string($value) || trim($value)==='') continue;
            $messages[]=function_exists('mb_substr')?mb_substr(trim($value),0,500,'UTF-8'):substr(trim($value),0,500);
            if (count($messages)>=$limit) break;
        }
        return $messages;
    }
}
if (!function_exists('cpms_ai_anomaly_safe_rows')) {
    function cpms_ai_anomaly_safe_rows($json)
    {
        $decoded=AiAnomalyDetectionService::decodeData($json);
        $allowed=array('type','label','category','category_label','severity','confidence','title','summary','observed_value','baseline_value','difference_value','deviation_rate','evidence','recommended_action');
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

$anomalyHistoryPdo=null;
$anomalyHistoryDbFailed=false;
try { $anomalyHistoryPdo=Db::pdo(); } catch (Exception $e) { $anomalyHistoryDbFailed=true; error_log('[AI Anomaly History] db initialization failed'); }
$anomalyInstalled=false;
$latestContext=array('analysis_date'=>'','target_ym'=>'');
if (!$anomalyHistoryDbFailed && $anomalyHistoryPdo) {
    try {
        $anomalyInstalled=AiAnomalyDetectionService::isInstalled($anomalyHistoryPdo);
        if ($anomalyInstalled) $latestContext=AiAnomalyDetectionService::latestResultContext($anomalyHistoryPdo);
    } catch (Exception $e) { $anomalyHistoryDbFailed=true; }
}
$filters=array(
    'analysis_date'=>isset($_GET['analysis_date'])?trim((string)$_GET['analysis_date']):'',
    'target_ym'=>isset($_GET['target_ym'])?trim((string)$_GET['target_ym']):'',
    'project_id'=>isset($_GET['project_id'])?(int)$_GET['project_id']:0,
    'project_status'=>isset($_GET['project_status'])?trim((string)$_GET['project_status']):'',
    'grade'=>isset($_GET['grade'])?trim((string)$_GET['grade']):'',
    'anomaly_type'=>isset($_GET['anomaly_type'])?trim((string)$_GET['anomaly_type']):'',
    'data_status'=>isset($_GET['data_status'])?trim((string)$_GET['data_status']):'',
    'q'=>isset($_GET['q'])?trim((string)$_GET['q']):''
);
if ($filters['analysis_date']==='' && !empty($latestContext['analysis_date'])) $filters['analysis_date']=$latestContext['analysis_date'];
if ($filters['target_ym']==='' && !empty($latestContext['target_ym'])) $filters['target_ym']=$latestContext['target_ym'];
$anomalyPage=isset($_GET['page'])?max(1,(int)$_GET['page']):1;
$anomalyPerPage=50;
$anomalyTotal=0;
$anomalyRows=array();
$anomalySummary=array('project_count'=>0,'normal_count'=>0,'watch_count'=>0,'warning_count'=>0,'critical_count'=>0,'insufficient_count'=>0,'anomaly_count'=>0,'last_calculated_at'=>'');
$anomalyOptions=array('projects'=>array(),'statuses'=>array(),'dates'=>array(),'months'=>array());
if ($anomalyInstalled && !$anomalyHistoryDbFailed) {
    try {
        $anomalyTotal=AiAnomalyDetectionService::countResults($anomalyHistoryPdo,$filters);
        $anomalySummary=AiAnomalyDetectionService::historySummary($anomalyHistoryPdo,$filters);
        $anomalyOptions=AiAnomalyDetectionService::historyOptions($anomalyHistoryPdo);
    } catch (Exception $e) { $anomalyHistoryDbFailed=true; }
}
$anomalyPages=max(1,(int)ceil($anomalyTotal/$anomalyPerPage));
if ($anomalyPage>$anomalyPages) $anomalyPage=$anomalyPages;
if ($anomalyInstalled && !$anomalyHistoryDbFailed) {
    try { $anomalyRows=AiAnomalyDetectionService::listResults($anomalyHistoryPdo,$filters,$anomalyPage,$anomalyPerPage); } catch (Exception $e) { $anomalyRows=array(); $anomalyHistoryDbFailed=true; }
}
$pageParams=$_GET;
$pageParams['r']='admin/ai_anomaly_history';
?>

<style>
  .ah-wrap{color:#0f172a}.ah-wrap *{box-sizing:border-box}.ah-hero,.ah-card,.ah-filter,.ah-table-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}
  .ah-hero{padding:24px;background:linear-gradient(135deg,#fff 0%,#fff7ed 100%)}.ah-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.ah-hero p{margin:9px 0 0;color:#475569;line-height:1.7}.ah-links{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px}.ah-link,.ah-btn{display:inline-flex;align-items:center;justify-content:center;min-height:39px;padding:8px 13px;border-radius:10px;background:#c2410c;color:#fff;text-decoration:none;font-weight:800;border:0}.ah-link.secondary,.ah-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}
  .ah-note,.ah-warning{margin-top:14px;padding:14px 16px;border-radius:13px;font-size:13px;line-height:1.7}.ah-note{border:1px solid #fed7aa;background:#fff7ed;color:#9a3412}.ah-warning{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c}.ah-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.ah-card{padding:16px}.ah-label{font-size:11px;color:#64748b;font-weight:800}.ah-value{margin-top:7px;font-size:18px;font-weight:900;overflow-wrap:anywhere}
  .ah-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px;padding:17px}.ah-field label{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.ah-field input,.ah-field select{width:100%;height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.ah-actions{display:flex;align-items:flex-end;gap:7px}
  .ah-table-card{margin-top:14px;overflow:hidden}.ah-scroll{overflow-x:auto}.ah-table{width:100%;min-width:1480px;border-collapse:collapse;font-size:12px}.ah-table th,.ah-table td{padding:11px 9px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.ah-table th{background:#f8fafc;color:#475569;font-weight:900;white-space:nowrap}.ah-table td.money{text-align:right;white-space:nowrap}.ah-badge{display:inline-flex;padding:4px 8px;border-radius:999px;background:#e2e8f0;color:#334155;font-weight:900;white-space:nowrap}.ah-badge.NORMAL{background:#dcfce7;color:#166534}.ah-badge.WATCH{background:#dbeafe;color:#1d4ed8}.ah-badge.WARNING{background:#ffedd5;color:#c2410c}.ah-badge.CRITICAL{background:#fee2e2;color:#b91c1c}.ah-badge.INSUFFICIENT{background:#e2e8f0;color:#475569}.ah-detail{min-width:1050px;margin-top:8px;padding:13px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}.ah-detail table{width:100%;border-collapse:collapse}.ah-detail th,.ah-detail td{padding:8px;border:1px solid #e2e8f0;vertical-align:top}.ah-detail ul{margin:7px 0;padding-left:20px}.ah-pager{display:flex;justify-content:center;gap:8px;padding:15px}.ah-pager a,.ah-pager span{padding:8px 12px;border:1px solid #cbd5e1;border-radius:9px;text-decoration:none;color:#334155;background:#fff}.ah-pager .current{background:#c2410c;color:#fff;border-color:#c2410c}
  @media(max-width:1000px){.ah-summary,.ah-filter{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.ah-hero{padding:18px}.ah-summary,.ah-filter{grid-template-columns:1fr}.ah-links,.ah-links .ah-link,.ah-actions,.ah-actions .ah-btn{width:100%}}
</style>

<div class="ah-wrap">
  <section class="ah-hero"><h2>현장별 비용 이상징후 탐지 결과</h2><p>저장된 입력 신뢰도와 비교자료에서 확인이 필요한 변화를 찾은 관리자용 참고 화면입니다.</p><div class="ah-links"><a class="ah-link" href="?r=admin%2Fai_anomaly_setup">이상징후 탐지 설정</a><a class="ah-link secondary" href="?r=admin%2Fai_ceo_index">CEO Index</a><a class="ah-link secondary" href="?r=admin%2Fai_profit_risk_history">적자·원가율 위험 결과</a><a class="ah-link secondary" href="?r=admin%2Fai_reliability_history">입력 신뢰도 결과</a><a class="ah-link secondary" href="?r=admin%2Fai_forecast_history">기본 월말 예측 결과</a><a class="ah-link secondary" href="?r=admin%2Fai_snapshot_history">일일 스냅샷 이력</a></div></section>
  <div class="ah-note"><strong>이상징후는 확인이 필요한 데이터 변화입니다. 실제 오류, 부정행위 또는 문제를 확정하는 결과가 아닙니다.</strong><br>공정단계 변화, 엑셀 일괄입력, 정상적인 정산으로도 동일한 변화가 나타날 수 있습니다. 이상징후 점수는 확인 우선순위이며 문제 발생 가능성이나 손실률을 의미하지 않습니다.</div>
  <?php if ($anomalyHistoryDbFailed): ?><div class="ah-warning">이상징후 결과를 불러오지 못했습니다. DB 연결 상태를 확인해주세요.</div><?php elseif (!$anomalyInstalled): ?><div class="ah-warning"><strong>이상징후 탐지 기능이 아직 설치되지 않았습니다.</strong><br><a href="?r=admin%2Fai_anomaly_setup">이상징후 탐지 설정</a>에서 전용 테이블을 설치해주세요.</div><?php endif; ?>

  <section class="ah-summary">
    <?php $summaryCards=array(
      '분석 현장 수'=>number_format(isset($anomalySummary['project_count'])?(int)$anomalySummary['project_count']:0) . '개',
      '정상 현장'=>number_format(isset($anomalySummary['normal_count'])?(int)$anomalySummary['normal_count']:0) . '개',
      '관심 현장'=>number_format(isset($anomalySummary['watch_count'])?(int)$anomalySummary['watch_count']:0) . '개',
      '주의 현장'=>number_format(isset($anomalySummary['warning_count'])?(int)$anomalySummary['warning_count']:0) . '개',
      '긴급 확인 현장'=>number_format(isset($anomalySummary['critical_count'])?(int)$anomalySummary['critical_count']:0) . '개',
      '판단자료 부족'=>number_format(isset($anomalySummary['insufficient_count'])?(int)$anomalySummary['insufficient_count']:0) . '개',
      '이상징후 총건수'=>number_format(isset($anomalySummary['anomaly_count'])?(int)$anomalySummary['anomaly_count']:0) . '건',
      '최근 탐지시각'=>!empty($anomalySummary['last_calculated_at'])?$anomalySummary['last_calculated_at']:'-'
    ); foreach ($summaryCards as $label=>$value): ?><div class="ah-card"><div class="ah-label"><?php echo h($label); ?></div><div class="ah-value"><?php echo h($value); ?></div></div><?php endforeach; ?>
  </section>

  <form class="ah-filter" method="get"><input type="hidden" name="r" value="admin/ai_anomaly_history">
    <div class="ah-field"><label>분석일</label><select name="analysis_date"><option value="">전체</option><?php foreach ((array)$anomalyOptions['dates'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['analysis_date']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="ah-field"><label>대상 월</label><select name="target_ym"><option value="">전체</option><?php foreach ((array)$anomalyOptions['months'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['target_ym']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="ah-field"><label>프로젝트</label><select name="project_id"><option value="0">전체</option><?php foreach ((array)$anomalyOptions['projects'] as $option): ?><option value="<?php echo h((int)$option['project_id']); ?>"<?php echo $filters['project_id']==(int)$option['project_id']?' selected':''; ?>><?php echo h(isset($option['project_name'])?$option['project_name']:''); ?></option><?php endforeach; ?></select></div>
    <div class="ah-field"><label>프로젝트 상태</label><select name="project_status"><option value="">전체</option><?php foreach ((array)$anomalyOptions['statuses'] as $option): $value=isset($option['status'])?(string)$option['status']:''; ?><option value="<?php echo h($value); ?>"<?php echo $filters['project_status']===$value?' selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select></div>
    <div class="ah-field"><label>종합등급</label><select name="grade"><option value="">전체</option><?php foreach (array('CRITICAL','WARNING','WATCH','INSUFFICIENT','NORMAL') as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['grade']===$value?' selected':''; ?>><?php echo h(cpms_ai_anomaly_grade_label($value)); ?></option><?php endforeach; ?></select></div>
    <div class="ah-field"><label>대표 이상징후</label><select name="anomaly_type"><option value="">전체</option><?php foreach (AiAnomalyDetectionService::anomalyLabels() as $value=>$label): ?><option value="<?php echo h($value); ?>"<?php echo $filters['anomaly_type']===$value?' selected':''; ?>><?php echo h($label); ?></option><?php endforeach; ?></select></div>
    <div class="ah-field"><label>자료 상태</label><select name="data_status"><option value="">전체</option><?php foreach (array('READY','LIMITED','INSUFFICIENT') as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['data_status']===$value?' selected':''; ?>><?php echo h(cpms_ai_anomaly_data_status_label($value)); ?></option><?php endforeach; ?></select></div>
    <div class="ah-field"><label>현장명 검색</label><input type="text" name="q" value="<?php echo h($filters['q']); ?>" maxlength="100" placeholder="현장명"></div>
    <div class="ah-actions"><button class="ah-btn" type="submit">조회</button><a class="ah-btn secondary" href="?r=admin%2Fai_anomaly_history">초기화</a></div>
  </form>

  <section class="ah-table-card"><div class="ah-scroll"><table class="ah-table"><thead><tr><th>현장</th><th>상태</th><th>월말 예상 투입비</th><th>입력 신뢰도</th><th>이상징후 점수</th><th>종합등급</th><th>이상징후 건수</th><th>최고 중요도</th><th>대표 이상징후</th><th>최신 스냅샷</th><th>최근 입력일</th><th>상세</th></tr></thead><tbody>
  <?php if (count($anomalyRows)===0): ?><tr><td colspan="12" style="padding:34px;text-align:center;color:#64748b;">조회된 이상징후 탐지 결과가 없습니다.</td></tr><?php else: foreach ($anomalyRows as $row):
    $anomalyDetails=cpms_ai_anomaly_safe_rows(isset($row['anomaly_data'])?$row['anomaly_data']:'');
    $recommendations=cpms_ai_anomaly_messages(isset($row['recommendation_data'])?$row['recommendation_data']:'',30);
    $warnings=cpms_ai_anomaly_messages(isset($row['warning_data'])?$row['warning_data']:'',30);
  ?><tr>
    <td><strong><?php echo h($row['project_name_snapshot']); ?></strong><div style="color:#64748b;margin-top:3px;">#<?php echo h((int)$row['project_id']); ?> · <?php echo h($row['target_ym']); ?></div></td>
    <td><?php echo h($row['project_status_snapshot']); ?></td>
    <td class="money"><strong><?php echo h(cpms_ai_anomaly_money($row['forecast_input_amount'])); ?></strong></td>
    <td><?php echo $row['reliability_score']!==null?h(number_format((float)$row['reliability_score'],1) . '점'):'-'; ?><div style="color:#64748b;"><?php echo h($row['reliability_grade']); ?></div></td>
    <td><strong><?php echo $row['anomaly_score']!==null?h(number_format((float)$row['anomaly_score'],1) . '점'):'-'; ?></strong></td>
    <td><span class="ah-badge <?php echo h($row['anomaly_grade']); ?>"><?php echo h(cpms_ai_anomaly_grade_label($row['anomaly_grade'])); ?></span></td>
    <td><?php echo h(number_format((int)$row['anomaly_count'])); ?>건</td>
    <td><?php echo $row['highest_severity']!==''?'<span class="ah-badge ' . h($row['highest_severity']) . '">' . h(cpms_ai_anomaly_grade_label($row['highest_severity'])) . '</span>':'-'; ?></td>
    <td><?php echo h(!empty($row['primary_anomaly_type'])?cpms_ai_anomaly_type_label($row['primary_anomaly_type']):'-'); ?></td>
    <td><?php echo h(!empty($row['snapshot_date'])?$row['snapshot_date']:'-'); ?><div style="color:#64748b;"><?php echo $row['snapshot_age_days']!==null?h((int)$row['snapshot_age_days'] . '일 전'):'-'; ?></div></td>
    <td><?php echo h(!empty($row['latest_event_at'])?$row['latest_event_at']:'-'); ?></td>
    <td><details><summary>보기</summary><div class="ah-detail">
      <h4>현장 요약</h4><table><tr><th>현재 투입비</th><td><?php echo h(cpms_ai_anomaly_money($row['current_input_amount'])); ?></td><th>월말 예상</th><td><?php echo h(cpms_ai_anomaly_money($row['forecast_input_amount'])); ?></td></tr><tr><th>예상범위</th><td><?php echo h(cpms_ai_anomaly_money($row['forecast_low_amount'])); ?> ~ <?php echo h(cpms_ai_anomaly_money($row['forecast_high_amount'])); ?></td><th>입력 신뢰도</th><td><?php echo $row['reliability_score']!==null?h(number_format((float)$row['reliability_score'],1) . '점'):'-'; ?> / <?php echo h($row['reliability_grade']); ?></td></tr><tr><th>이상징후 점수</th><td><?php echo $row['anomaly_score']!==null?h(number_format((float)$row['anomaly_score'],1) . '점'):'-'; ?></td><th>종합등급</th><td><?php echo h(cpms_ai_anomaly_grade_label($row['anomaly_grade'])); ?></td></tr><tr><th>자료 상태</th><td><?php echo h(cpms_ai_anomaly_data_status_label($row['data_status'])); ?></td><th>신뢰수준</th><td><?php echo h(cpms_ai_anomaly_confidence_label($row['confidence_level'])); ?></td></tr><tr><th>최신 스냅샷</th><td><?php echo h(!empty($row['snapshot_date'])?$row['snapshot_date']:'-'); ?></td><th>최근 비용 입력일</th><td><?php echo h(!empty($row['latest_event_at'])?$row['latest_event_at']:'-'); ?></td></tr></table>
      <h4>이상징후별 상세</h4><table><thead><tr><th>이상징후</th><th>비용 종류</th><th>중요도</th><th>신뢰수준</th><th>현재값</th><th>비교값</th><th>차이</th><th>변화율</th><th>판단 근거</th><th>확인 권장사항</th></tr></thead><tbody>
      <?php if (count($anomalyDetails)===0): ?><tr><td colspan="10">표시할 이상징후가 없습니다.</td></tr><?php else: foreach ($anomalyDetails as $item): $itemType=isset($item['type'])?(string)$item['type']:''; ?><tr><td><strong><?php echo h(isset($item['label'])?$item['label']:cpms_ai_anomaly_type_label($itemType)); ?></strong><br><small><?php echo h(isset($item['summary'])?$item['summary']:''); ?></small></td><td><?php echo h(!empty($item['category'])?cpms_ai_anomaly_category_label($item['category']):'-'); ?></td><td><?php echo h(cpms_ai_anomaly_grade_label(isset($item['severity'])?$item['severity']:'')); ?></td><td><?php echo h(cpms_ai_anomaly_confidence_label(isset($item['confidence'])?$item['confidence']:'')); ?></td><td><?php echo h(cpms_ai_anomaly_value($itemType,isset($item['observed_value'])?$item['observed_value']:null,'observed')); ?></td><td><?php echo h(cpms_ai_anomaly_value($itemType,isset($item['baseline_value'])?$item['baseline_value']:null,'baseline')); ?></td><td><?php echo h(cpms_ai_anomaly_value($itemType,isset($item['difference_value'])?$item['difference_value']:null,'difference')); ?></td><td><?php echo h(cpms_ai_anomaly_rate(isset($item['deviation_rate'])?$item['deviation_rate']:null)); ?></td><td><?php if (!empty($item['evidence'])): ?><ul><?php foreach ($item['evidence'] as $evidence): ?><li><?php echo h($evidence); ?></li><?php endforeach; ?></ul><?php else: ?>-<?php endif; ?></td><td><?php echo h(isset($item['recommended_action'])?$item['recommended_action']:'-'); ?></td></tr><?php endforeach; endif; ?></tbody></table>
      <?php if (count($recommendations)>0): ?><h4>권장 확인사항</h4><ul><?php foreach ($recommendations as $message): ?><li><?php echo h($message); ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if (count($warnings)>0): ?><h4>자료 안내</h4><ul style="color:#b45309;"><?php foreach ($warnings as $message): ?><li><?php echo h($message); ?></li><?php endforeach; ?></ul><?php endif; ?>
      <div style="margin-top:10px;color:#64748b;">분석일 <?php echo h($row['analysis_date']); ?> · 최초 계산 <?php echo h($row['first_created_at']); ?> · 최근 계산 <?php echo h($row['last_calculated_at']); ?> · 계산 <?php echo h(number_format((int)$row['calculation_count'])); ?>회</div>
    </div></details></td>
  </tr><?php endforeach; endif; ?>
  </tbody></table></div><?php if ($anomalyPages>1): ?><nav class="ah-pager"><?php if ($anomalyPage>1): $pageParams['page']=$anomalyPage-1; ?><a href="?<?php echo h(http_build_query($pageParams)); ?>">이전</a><?php endif; ?><span class="current"><?php echo h($anomalyPage); ?> / <?php echo h($anomalyPages); ?></span><?php if ($anomalyPage<$anomalyPages): $pageParams['page']=$anomalyPage+1; ?><a href="?<?php echo h(http_build_query($pageParams)); ?>">다음</a><?php endif; ?></nav><?php endif; ?></section>
</div>
