<?php
/** Representative executive briefing screen. PHP 5.6 compatible. */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiExecutiveBriefService;
use App\Services\OpenAiResponsesClient;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/OpenAiResponsesClient.php';
require_once __DIR__ . '/../../services/AiExecutiveBriefService.php';

if (!function_exists('cpms_executive_brief_money')) {
    function cpms_executive_brief_money($value) { return $value===null?'-':number_format((float)$value) . '원'; }
}
if (!function_exists('cpms_executive_brief_rate')) {
    function cpms_executive_brief_rate($value) { return $value===null?'-':number_format((float)$value,1) . '%'; }
}
if (!function_exists('cpms_executive_brief_metric_value')) {
    function cpms_executive_brief_metric_value($metric) {
        if (!is_array($metric)) return '-';
        $value=isset($metric['value'])?$metric['value']:null; $unit=isset($metric['unit'])?(string)$metric['unit']:'';
        if ($value===null) return '-';
        return is_numeric($value)?number_format((float)$value) . $unit:(string)$value . $unit;
    }
}
if (!function_exists('cpms_executive_brief_original_value')) {
    function cpms_executive_brief_original_value($project,$suffix) {
        $money=array('monthly_sales_amount','monthly_forecast_input_amount','monthly_forecast_profit_amount','cumulative_projected_profit_amount');
        $rates=array('monthly_forecast_cost_rate','cumulative_projected_cost_rate','contract_input_utilization_rate');
        if (!is_array($project)||!array_key_exists($suffix,$project)) return '-';
        if (in_array($suffix,$money,true)) return cpms_executive_brief_money($project[$suffix]);
        if (in_array($suffix,$rates,true)) return cpms_executive_brief_rate($project[$suffix]);
        if (in_array($suffix,array('reliability_score','anomaly_score','risk_score'),true)) return $project[$suffix]===null?'-':number_format((float)$project[$suffix],1) . '점';
        return trim((string)$project[$suffix])!==''?(string)$project[$suffix]:'-';
    }
}

$briefPdo=null; $briefInitializationFailed=false;
$briefStatus=array(
    'db_available'=>false,'curl_available'=>function_exists('curl_init'),'api_key_configured'=>false,'api_key_source'=>'NONE','model'=>OpenAiResponsesClient::DEFAULT_MODEL,
    'run'=>array('installed'=>false),'brief'=>array('installed'=>false),'installed'=>false,
    'latest_risk'=>array('available'=>false,'analysis_date'=>'','target_ym'=>'','project_count'=>0),'latest_run'=>array(),'latest_brief'=>array()
);
try { $briefPdo=Db::pdo(); } catch (Exception $e) { $briefInitializationFailed=true; error_log('[AI Executive Brief] db initialization failed'); }
$briefActionResult=null;
if (isset($_SERVER['REQUEST_METHOD'])&&$_SERVER['REQUEST_METHOD']==='POST') {
    $token=isset($_POST['_csrf'])?(string)$_POST['_csrf']:'';
    $action=isset($_POST['action'])?trim((string)$_POST['action']):'';
    if (!csrf_check($token)) $briefActionResult=array('ok'=>false,'message'=>'보안 토큰이 올바르지 않습니다.');
    else if ($action==='generate' || $action==='regenerate') {
        if ($action==='regenerate' && (!isset($_POST['confirm_regenerate']) || (string)$_POST['confirm_regenerate']!=='1')) {
            $briefActionResult=array('ok'=>false,'message'=>'다시 생성 확인 항목을 선택해주세요.');
        } else if (!$briefPdo) $briefActionResult=array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        else {
            try { $briefActionResult=AiExecutiveBriefService::generateLatest($briefPdo,'MANUAL',$action==='regenerate'); }
            catch (Exception $e) { $briefActionResult=array('ok'=>false,'message'=>'경영 브리핑 요청을 처리하지 못했습니다.'); error_log('[OpenAI] task=EXECUTIVE_BRIEF status=FAILED'); }
        }
    } else $briefActionResult=array('ok'=>false,'message'=>'요청값이 올바르지 않습니다.');
}
if (!$briefInitializationFailed) {
    try { $loaded=AiExecutiveBriefService::schemaStatus($briefPdo); if(is_array($loaded))$briefStatus=array_merge($briefStatus,$loaded); else $briefInitializationFailed=true; }
    catch (Exception $e) { $briefInitializationFailed=true; error_log('[AI Executive Brief] status initialization failed'); }
}
$brief=isset($briefStatus['latest_brief'])&&is_array($briefStatus['latest_brief'])?$briefStatus['latest_brief']:array();
$riskContext=isset($briefStatus['latest_risk'])&&is_array($briefStatus['latest_risk'])?$briefStatus['latest_risk']:array();
$keyMetrics=AiExecutiveBriefService::decodeData(isset($brief['key_metrics_data'])?$brief['key_metrics_data']:'');
$topRisks=AiExecutiveBriefService::decodeData(isset($brief['top_risks_data'])?$brief['top_risks_data']:'');
$positiveSignals=AiExecutiveBriefService::decodeData(isset($brief['positive_signals_data'])?$brief['positive_signals_data']:'');
$checkToday=AiExecutiveBriefService::decodeData(isset($brief['check_today_data'])?$brief['check_today_data']:'');
$limitations=AiExecutiveBriefService::decodeData(isset($brief['data_limitations_data'])?$brief['data_limitations_data']:'');
$sourceSummary=AiExecutiveBriefService::decodeData(isset($brief['source_summary_data'])?$brief['source_summary_data']:'');
$originalProjects=AiExecutiveBriefService::briefOriginalProjects($brief);
$companyMetrics=array();
if(isset($sourceSummary['company_metrics'])&&is_array($sourceSummary['company_metrics'])) foreach($sourceSummary['company_metrics'] as $metric) if(is_array($metric)&&isset($metric['metric_id'])) $companyMetrics[(string)$metric['metric_id']]=$metric;
$briefExecutable=!empty($briefStatus['installed'])&&!empty($briefStatus['api_key_configured'])&&!empty($briefStatus['curl_available'])&&!empty($riskContext['available']);
if (empty($_SESSION['_csrf'])) {
    $briefRandom=function_exists('openssl_random_pseudo_bytes')?@openssl_random_pseudo_bytes(32):false;
    if (!is_string($briefRandom) || strlen($briefRandom)<16) $briefRandom=uniqid((string)mt_rand(),true) . microtime(true) . session_id();
    $_SESSION['_csrf']=hash('sha256',$briefRandom);
}
$briefCsrfToken=isset($_SESSION['_csrf'])?(string)$_SESSION['_csrf']:'';
?>

<style>
  .eb-wrap{color:#0f172a}.eb-wrap *{box-sizing:border-box}.eb-hero,.eb-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}.eb-hero{padding:25px;background:linear-gradient(135deg,#fff 0%,#ecfeff 100%)}.eb-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.eb-hero p{margin:9px 0 0;color:#475569;line-height:1.75}
  .eb-links,.eb-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:15px}.eb-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:0;border-radius:11px;background:#0f766e;color:#fff;text-decoration:none;font-weight:900;cursor:pointer}.eb-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}.eb-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-top:15px}.eb-card{padding:17px}.eb-label{font-size:11px;color:#64748b;font-weight:800}.eb-value{margin-top:7px;font-size:18px;font-weight:900;overflow-wrap:anywhere}.eb-message,.eb-note{margin-top:14px;padding:14px 16px;border-radius:13px;line-height:1.7}.eb-message.ok{border:1px solid #a7f3d0;background:#ecfdf5;color:#047857}.eb-message.error{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c}.eb-note{border:1px solid #99f6e4;background:#f0fdfa;color:#115e59;font-size:13px}
  .eb-section{margin-top:15px;padding:21px}.eb-section h3{margin:0 0 12px;font-size:19px}.eb-headline{font-size:25px;line-height:1.35;font-weight:900}.eb-summary{color:#334155;line-height:1.9;white-space:pre-line}.eb-list{margin:0;padding-left:21px}.eb-list li{margin:7px 0;line-height:1.65}.eb-risk{margin-top:11px;padding:16px;border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc}.eb-risk h4{margin:0 0 6px}.eb-badge{display:inline-flex;padding:4px 9px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:900}.eb-badge.CRITICAL{background:#fee2e2;color:#b91c1c}.eb-badge.WARNING{background:#ffedd5;color:#c2410c}.eb-badge.WATCH{background:#dbeafe;color:#1d4ed8}.eb-badge.INSUFFICIENT{background:#e2e8f0;color:#475569}.eb-scroll{overflow-x:auto}.eb-table{width:100%;min-width:800px;border-collapse:collapse}.eb-table th,.eb-table td{padding:10px;border:1px solid #e2e8f0;text-align:left;vertical-align:top}.eb-table th{background:#f8fafc;color:#475569;font-size:12px}.eb-original{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;margin-top:12px}.eb-original div{padding:9px;border:1px solid #e2e8f0;border-radius:9px;background:#fff;font-size:12px}.eb-original strong{display:block;color:#64748b;font-size:10px;margin-bottom:4px}.eb-confirm{display:flex;align-items:center;gap:7px;color:#92400e;font-size:12px}.eb-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  @media(max-width:1000px){.eb-grid,.eb-original{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.eb-hero{padding:18px}.eb-grid,.eb-original,.eb-two{grid-template-columns:1fr}.eb-actions,.eb-actions form,.eb-actions .eb-btn,.eb-links,.eb-links .eb-btn{width:100%}}
</style>

<div class="eb-wrap">
  <section class="eb-hero"><h2>대표용 경영 브리핑</h2><p>저장된 최신 적자·원가율 위험분석 결과를 OpenAI가 대표가 읽기 쉬운 문장으로 설명합니다. PHP 계산결과가 원본이며 GPT 설명은 보조자료입니다.</p><div class="eb-links"><a class="eb-btn secondary" href="?r=admin%2Fai_openai_setup">OpenAI 연결 설정</a><a class="eb-btn secondary" href="?r=admin%2Fai_profit_risk_history">적자·원가율 위험 결과</a></div></section>
  <div class="eb-note"><strong>현재 브리핑은 기본 통계 예측과 CPMS 입력자료를 설명한 관리 참고자료이며 확정 회계자료 또는 최종 손익이 아닙니다.</strong></div>
  <?php if($briefInitializationFailed): ?><div class="eb-message error">경영 브리핑 상태를 불러오지 못했습니다.</div><?php elseif(empty($briefStatus['db_available'])): ?><div class="eb-message error">DB 연결 상태를 확인할 수 없습니다.</div><?php elseif(empty($briefStatus['installed'])): ?><div class="eb-message error">OpenAI 경영 브리핑 테이블이 아직 설치되지 않았습니다. OpenAI 연결 설정에서 설치해주세요.</div><?php elseif(empty($riskContext['available'])): ?><div class="eb-message error">경영 브리핑을 생성하려면 먼저 적자·원가율 위험분석을 실행해주세요.</div><?php elseif(empty($briefStatus['api_key_configured'])): ?><div class="eb-message error">OpenAI API 키가 설정되지 않았습니다.</div><?php elseif(empty($briefStatus['curl_available'])): ?><div class="eb-message error">서버의 PHP cURL 기능을 확인해주세요.</div><?php endif; ?>
  <?php if(is_array($briefActionResult)): ?><div class="eb-message <?php echo !empty($briefActionResult['ok'])?'ok':'error'; ?>"><?php echo h(isset($briefActionResult['message'])?$briefActionResult['message']:'처리 결과를 확인할 수 없습니다.'); ?><?php if(!empty($briefActionResult['cached'])): ?> (저장된 결과 사용)<?php endif; ?></div><?php endif; ?>

  <section class="eb-grid">
    <div class="eb-card"><div class="eb-label">최신 위험분석일</div><div class="eb-value"><?php echo h(!empty($riskContext['analysis_date'])?$riskContext['analysis_date']:'-'); ?></div></div>
    <div class="eb-card"><div class="eb-label">대상 월</div><div class="eb-value"><?php echo h(!empty($riskContext['target_ym'])?$riskContext['target_ym']:'-'); ?></div></div>
    <div class="eb-card"><div class="eb-label">API 연결상태</div><div class="eb-value"><?php echo !empty($briefStatus['api_key_configured'])&& !empty($briefStatus['curl_available'])?'준비됨':'설정 필요'; ?></div></div>
    <div class="eb-card"><div class="eb-label">사용 모델</div><div class="eb-value"><?php echo h($briefStatus['model']); ?></div></div>
    <div class="eb-card"><div class="eb-label">분석 현장 수</div><div class="eb-value"><?php echo h(number_format(isset($riskContext['project_count'])?(int)$riskContext['project_count']:0)); ?>개</div></div>
    <div class="eb-card"><div class="eb-label">브리핑 생성 가능</div><div class="eb-value"><?php echo $briefExecutable?'가능':'준비 필요'; ?></div></div>
    <div class="eb-card"><div class="eb-label">마지막 생성시각</div><div class="eb-value"><?php echo h(isset($brief['generated_at'])?$brief['generated_at']:'-'); ?></div></div>
    <div class="eb-card"><div class="eb-label">저장된 회사상태</div><div class="eb-value"><?php echo h(isset($brief['company_status'])?$brief['company_status']:'-'); ?></div></div>
  </section>

  <section class="eb-card eb-section"><h3>브리핑 생성</h3><div class="eb-actions">
    <form method="post" action="?r=admin%2Fai_executive_brief"><input type="hidden" name="_csrf" value="<?php echo h($briefCsrfToken); ?>"><input type="hidden" name="action" value="generate"><button class="eb-btn" type="submit"<?php echo !$briefExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>최신 위험분석으로 브리핑 생성</button></form>
    <form method="post" action="?r=admin%2Fai_executive_brief"><input type="hidden" name="_csrf" value="<?php echo h($briefCsrfToken); ?>"><input type="hidden" name="action" value="regenerate"><label class="eb-confirm"><input type="checkbox" name="confirm_regenerate" value="1"> API 사용량 추가 발생 확인</label><button class="eb-btn secondary" type="submit"<?php echo !$briefExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>다시 생성</button></form>
    <a class="eb-btn secondary" href="?r=admin%2Fai_executive_brief">저장된 최신 브리핑 보기</a>
  </div><div class="eb-note">동일한 원본자료·스키마·모델의 기본 생성 요청은 저장된 결과를 사용합니다. 다시 생성하면 OpenAI API 사용량이 추가로 발생할 수 있습니다.</div></section>

  <?php if(!empty($brief)): ?>
    <section class="eb-card eb-section"><div class="eb-label">오늘의 제목</div><div class="eb-headline"><?php echo h($brief['headline']); ?></div><h3 style="margin-top:20px">대표 요약</h3><div class="eb-summary"><?php echo h($brief['executive_summary']); ?></div></section>
    <section class="eb-card eb-section"><h3>주요 지표</h3><div class="eb-scroll"><table class="eb-table"><thead><tr><th>지표</th><th>PHP 원본값</th><th>GPT 설명</th></tr></thead><tbody><?php if(count($keyMetrics)===0): ?><tr><td colspan="3">표시할 주요 지표가 없습니다.</td></tr><?php else: foreach($keyMetrics as $metric): $metricId=isset($metric['metric_id'])?(string)$metric['metric_id']:''; $original=isset($companyMetrics[$metricId])?$companyMetrics[$metricId]:array(); ?><tr><td><strong><?php echo h(isset($metric['label'])?$metric['label']:(isset($original['label'])?$original['label']:'-')); ?></strong><br><small><?php echo h($metricId); ?></small></td><td><?php echo h(cpms_executive_brief_metric_value($original)); ?></td><td><?php echo h(isset($metric['interpretation'])?$metric['interpretation']:'-'); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
    <section class="eb-card eb-section"><h3>우선 확인 현장</h3><?php if(count($topRisks)===0): ?><div class="eb-label">우선 확인 현장이 별도로 제시되지 않았습니다.</div><?php else: foreach($topRisks as $risk): $projectId=isset($risk['project_id'])?(int)$risk['project_id']:0; $original=isset($originalProjects[$projectId])?$originalProjects[$projectId]:array(); ?><article class="eb-risk"><h4><?php echo h(isset($risk['project_name'])?$risk['project_name']:'-'); ?> <span class="eb-badge <?php echo h(isset($risk['severity'])?$risk['severity']:''); ?>"><?php echo h(isset($risk['severity'])?$risk['severity']:'-'); ?></span></h4><div class="eb-label"><?php echo h(isset($risk['risk_type'])?$risk['risk_type']:'-'); ?> · <?php echo h(isset($risk['title'])?$risk['title']:''); ?></div><p><?php echo h(isset($risk['explanation'])?$risk['explanation']:''); ?></p><div class="eb-original"><?php foreach(array('monthly_sales_amount'=>'월 예상매출','monthly_forecast_input_amount'=>'월 예상투입비','monthly_forecast_profit_amount'=>'월 예상손익','monthly_forecast_cost_rate'=>'월 예상원가율','cumulative_projected_cost_rate'=>'누적 예상원가율','reliability_score'=>'입력 신뢰도','anomaly_grade'=>'이상징후 등급','risk_grade'=>'위험등급') as $key=>$label): ?><div><strong><?php echo h($label); ?></strong><?php echo h(cpms_executive_brief_original_value($original,$key)); ?></div><?php endforeach; ?></div><?php if(isset($risk['recommended_actions'])&&is_array($risk['recommended_actions'])&&count($risk['recommended_actions'])>0): ?><h4 style="margin-top:13px">확인 권장사항</h4><ul class="eb-list"><?php foreach($risk['recommended_actions'] as $item): ?><li><?php echo h($item); ?></li><?php endforeach; ?></ul><?php endif; ?></article><?php endforeach; endif; ?></section>
    <section class="eb-two"><div class="eb-card eb-section"><h3>오늘 확인할 사항</h3><ul class="eb-list"><?php if(count($checkToday)===0): ?><li>별도 제안이 없습니다.</li><?php else: foreach($checkToday as $item): ?><li><?php echo h($item); ?></li><?php endforeach; endif; ?></ul></div><div class="eb-card eb-section"><h3>긍정적인 신호</h3><ul class="eb-list"><?php if(count($positiveSignals)===0): ?><li>표시할 항목이 없습니다.</li><?php else: foreach($positiveSignals as $item): ?><li><?php echo h($item); ?></li><?php endforeach; endif; ?></ul></div></section>
    <section class="eb-card eb-section"><h3>자료 한계</h3><ul class="eb-list"><?php if(count($limitations)===0): ?><li>추가로 표시된 자료 한계가 없습니다.</li><?php else: foreach($limitations as $item): ?><li><?php echo h($item); ?></li><?php endforeach; endif; ?></ul><h3 style="margin-top:18px">필수 안내</h3><p><?php echo h(isset($brief['disclaimer'])?$brief['disclaimer']:''); ?></p><div class="eb-note">이 브리핑은 CPMS에 입력된 자료와 통계 예측결과를 GPT가 설명한 관리 참고자료입니다.<br>확정 회계자료 또는 최종 손익을 의미하지 않습니다.</div></section>
  <?php else: ?><section class="eb-card eb-section"><h3>저장된 브리핑 없음</h3><p class="eb-label">OpenAI 연결 후 최신 위험분석을 이용해 경영 브리핑을 생성할 수 있습니다.</p></section><?php endif; ?>
</div>
