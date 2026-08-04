<?php
/** Independent CEO Index section. PHP 5.6 compatible. */
use App\Core\Auth;
use App\Core\Db;
use App\Services\AiCeoIndexService;
use App\Services\AiCostForecastV2Service;
use App\Services\AiDailyPipelineService;
use App\Services\AiExecutiveChatService;
use App\Services\AiExecutiveBriefService;
use App\Services\AiInputCompletionPatternService;
use App\Services\AiMemoryService;

require_once __DIR__ . '/../../services/AiCeoIndexService.php';
require_once __DIR__ . '/../../services/AiCostForecastV2Service.php';
require_once __DIR__ . '/../../services/AiDailyPipelineService.php';
require_once __DIR__ . '/../../services/AiExecutiveChatService.php';
require_once __DIR__ . '/../../services/AiExecutiveBriefService.php';
require_once __DIR__ . '/../../services/AiMemoryService.php';

$ceoSectionAllowed=Auth::check()&&Auth::canAccessCeoIndex();
$ceoSectionDeveloper=Auth::check()&&Auth::isDevelopmentDepartment();
if(!$ceoSectionAllowed){http_response_code(403);echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 font-bold text-red-700">'.h('접근 권한이 없습니다.').'</div>';return;}
$ceoSectionPdo=null;$ceoSectionError=false;try{$ceoSectionPdo=Db::pdo();}catch(Exception $e){$ceoSectionError=true;error_log('[CEO Index] db initialization failed');}
$ceoTabs=array('overview'=>'종합현황','forecast'=>'투입비 예측','reliability'=>'입력 신뢰도','anomalies'=>'위험·이상징후','cost_risk'=>'원가율·적자 가능성','gpt_summary'=>'GPT 요약','gpt_chat'=>'GPT와 대화');
$ceoActiveTab=isset($_GET['tab'])?trim((string)$_GET['tab']):'overview';if(!isset($ceoTabs[$ceoActiveTab]))$ceoActiveTab='overview';
$ceoMonths=$ceoSectionPdo?AiCostForecastV2Service::availableMonths($ceoSectionPdo):array();$ceoTargetYm=isset($_GET['target_ym'])?trim((string)$_GET['target_ym']):'';if(!preg_match('/^\d{4}-\d{2}$/',$ceoTargetYm)||(!empty($ceoMonths)&&!in_array($ceoTargetYm,$ceoMonths,true)))$ceoTargetYm=count($ceoMonths)?$ceoMonths[0]:date('Y-m');
if(empty($_SESSION['_csrf'])){$bytes=function_exists('openssl_random_pseudo_bytes')?@openssl_random_pseudo_bytes(32):false;if(!is_string($bytes))$bytes=uniqid('',true).mt_rand();$_SESSION['_csrf']=hash('sha256',$bytes);}$ceoCsrf=(string)$_SESSION['_csrf'];$ceoActionResult=null;
if(isset($_SERVER['REQUEST_METHOD'])&&$_SERVER['REQUEST_METHOD']==='POST'){
    $token=isset($_POST['_csrf'])?(string)$_POST['_csrf']:'';$action=isset($_POST['action'])?trim((string)$_POST['action']):'';
    if(!csrf_check($token))$ceoActionResult=array('ok'=>false,'message'=>'보안 토큰이 올바르지 않습니다.');
    else if(in_array($action,array('install','run_pipeline','force_pipeline','generate_summary'),true)&&!$ceoSectionDeveloper)$ceoActionResult=array('ok'=>false,'message'=>'개발부서 전용 기능입니다.');
    else if(!$ceoSectionPdo)$ceoActionResult=array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
    else if($action==='install'){$a=AiDailyPipelineService::installOrUpdate($ceoSectionPdo);$b=AiExecutiveChatService::installOrUpdate($ceoSectionPdo);$c=AiMemoryService::installOrUpdate($ceoSectionPdo);$ceoActionResult=array('ok'=>!empty($a['ok'])&&!empty($b['ok'])&&!empty($c['ok']),'message'=>(isset($a['message'])?$a['message']:'').' '.(isset($b['message'])?$b['message']:'').' '.(isset($c['message'])?$c['message']:''));}
    else if($action==='run_pipeline'||$action==='force_pipeline')$ceoActionResult=AiDailyPipelineService::run($ceoSectionPdo,'MANUAL',$action==='force_pipeline');
    else if($action==='generate_summary')$ceoActionResult=AiExecutiveBriefService::generateLatest($ceoSectionPdo,'MANUAL',!empty($_POST['force']));
    else if($action==='new_thread'){$ceoActionResult=AiExecutiveChatService::createThread($ceoSectionPdo,isset($_POST['target_ym'])?$_POST['target_ym']:$ceoTargetYm);if(!empty($ceoActionResult['thread_id']))$_GET['thread_id']=$ceoActionResult['thread_id'];$ceoActiveTab='gpt_chat';}
    else if($action==='send_chat'){$ceoActionResult=AiExecutiveChatService::send($ceoSectionPdo,isset($_POST['thread_id'])?(int)$_POST['thread_id']:0,isset($_POST['question'])?$_POST['question']:'');$_GET['thread_id']=isset($_POST['thread_id'])?(int)$_POST['thread_id']:0;$ceoActiveTab='gpt_chat';}
    else if($action==='archive_thread'){$ok=AiExecutiveChatService::archiveThread($ceoSectionPdo,isset($_POST['thread_id'])?(int)$_POST['thread_id']:0);$ceoActionResult=array('ok'=>$ok,'message'=>$ok?'대화방을 보관했습니다.':'대화방을 보관하지 못했습니다.');$ceoActiveTab='gpt_chat';}
    else if($action==='save_memory'){$memoryData=$_POST;$memoryData['confirmed']=!empty($_POST['confirmed']);$ceoActionResult=AiMemoryService::saveMemory($ceoSectionPdo,$memoryData);$ceoActiveTab='gpt_chat';}
    else if($action==='review_memory'){$ceoActionResult=AiMemoryService::review($ceoSectionPdo,isset($_POST['memory_id'])?(int)$_POST['memory_id']:0,isset($_POST['decision'])?$_POST['decision']:'',isset($_POST['reason'])?$_POST['reason']:'');$ceoActiveTab='gpt_chat';}
    else if($action==='deactivate_memory'){$ceoActionResult=AiMemoryService::deactivate($ceoSectionPdo,isset($_POST['memory_id'])?(int)$_POST['memory_id']:0,isset($_POST['reason'])?$_POST['reason']:'');$ceoActiveTab='gpt_chat';}
    else if($action==='create_memory_version'){$memoryVersionData=$_POST;$memoryVersionData['confirmed']=!empty($_POST['confirmed']);$ceoActionResult=AiMemoryService::createVersion($ceoSectionPdo,isset($_POST['memory_id'])?(int)$_POST['memory_id']:0,$memoryVersionData);$ceoActiveTab='gpt_chat';}
    else if($action==='reactivate_memory'){$ceoActionResult=AiMemoryService::reactivate($ceoSectionPdo,isset($_POST['memory_id'])?(int)$_POST['memory_id']:0,isset($_POST['reason'])?$_POST['reason']:'');$ceoActiveTab='gpt_chat';}
    else $ceoActionResult=array('ok'=>false,'message'=>'요청값이 올바르지 않습니다.');
}
$ceoSummary=array('available'=>false);
$ceoIndexV2=array();
$ceoV2Ready=false;
$ceoLearning=array('month_count'=>0,'months'=>array(),'first_ym'=>'','last_ym'=>'','state'=>AiInputCompletionPatternService::learningState(0));
if($ceoSectionPdo){
    try{
        $ceoSummary=AiCostForecastV2Service::latestCompanySummary($ceoSectionPdo,$ceoTargetYm);
        $ceoIndexV2=AiCeoIndexService::latestNormalV2($ceoSectionPdo,$ceoTargetYm);
        $ceoV2Ready=!empty($ceoSummary['available'])&&isset($ceoSummary['calculation_version'])&&$ceoSummary['calculation_version']===AiCostForecastV2Service::CALCULATION_VERSION&&!empty($ceoIndexV2)&&isset($ceoIndexV2['calculation_version'])&&$ceoIndexV2['calculation_version']===AiCeoIndexService::V2_VERSION;
        if(!empty($ceoSummary['analysis_date']))$ceoLearning=AiInputCompletionPatternService::learningSummary($ceoSectionPdo,$ceoSummary['analysis_date'],$ceoTargetYm);
    }catch(Exception $e){
        $ceoSectionError=true;
        error_log('[CEO Index] view data initialization failed');
    }
}
if(!function_exists('cpms_ceo_v2_money')){function cpms_ceo_v2_money($value){return $value===null?'-':number_format((float)$value).'원';}}
if(!function_exists('cpms_ceo_v2_rate')){function cpms_ceo_v2_rate($value){return $value===null?'-':number_format((float)$value,1).'%';}}
if(!function_exists('cpms_ceo_v2_score')){function cpms_ceo_v2_score($value){return $value===null?'-':number_format((float)$value,1).'점';}}
if(!function_exists('cpms_ceo_v2_url')){function cpms_ceo_v2_url($tab,$ym,$extra=array()){$params=array('r'=>'ceo_index','tab'=>$tab,'target_ym'=>$ym);foreach($extra as $key=>$value)$params[$key]=$value;return '?'.http_build_query($params,'','&');}}
?>
<style>
.ceo-v2{color:#0f172a}.ceo-v2 *{box-sizing:border-box}.ceo-v2-hero,.ceo-v2-card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;box-shadow:0 8px 25px rgba(15,23,42,.05)}.ceo-v2-hero{padding:24px;background:linear-gradient(135deg,#f0fdf4,#eff6ff)}.ceo-v2-hero h2{margin:0;font-size:29px;font-weight:900}.ceo-v2-card{padding:19px;margin-top:15px}.ceo-v2-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.ceo-v2-kpi{padding:15px;border:1px solid #e2e8f0;border-radius:15px;background:#f8fafc}.ceo-v2-kpi small{display:block;color:#64748b;font-weight:800}.ceo-v2-kpi strong{display:block;margin-top:7px;font-size:20px}.ceo-v2-tabs{display:flex;gap:7px;overflow-x:auto;padding:8px 0}.ceo-v2-tabs a{white-space:nowrap;text-decoration:none;padding:10px 13px;border:1px solid #cbd5e1;border-radius:11px;color:#334155;font-weight:800}.ceo-v2-tabs a.active{background:#166534;color:#fff;border-color:#166534}.ceo-v2-actions{display:flex;gap:8px;flex-wrap:wrap}.ceo-v2-btn{border:0;border-radius:11px;padding:10px 14px;background:#166534;color:#fff;text-decoration:none;font-weight:900;cursor:pointer}.ceo-v2-btn.secondary{background:#fff;color:#334155;border:1px solid #cbd5e1}.ceo-v2-note{padding:13px 15px;border-radius:13px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;margin-top:12px}.ceo-v2-message{padding:13px 15px;border-radius:13px;margin-top:12px}.ceo-v2-message.ok{background:#ecfdf5;color:#047857}.ceo-v2-message.error{background:#fef2f2;color:#b91c1c}.ceo-v2-scroll{overflow-x:auto}.ceo-v2-table{width:100%;min-width:1000px;border-collapse:collapse}.ceo-v2-table th,.ceo-v2-table td{border:1px solid #e2e8f0;padding:10px;text-align:left;vertical-align:top}.ceo-v2-table th{background:#f8fafc;font-size:12px}.ceo-v2-badge{display:inline-flex;padding:4px 9px;border-radius:999px;background:#e2e8f0;font-weight:900;font-size:12px}.ceo-v2-list{margin:0;padding-left:20px}.ceo-v2-list li{margin:7px 0}.ceo-chat-layout{display:grid;grid-template-columns:280px minmax(0,1fr);gap:13px}.ceo-chat-messages{max-height:620px;overflow-y:auto;padding:10px;background:#f8fafc;border-radius:14px}.ceo-chat-msg{max-width:85%;padding:12px 14px;border-radius:15px;margin:8px 0;white-space:pre-wrap}.ceo-chat-msg.user{margin-left:auto;background:#166534;color:#fff}.ceo-chat-msg.assistant{background:#fff;border:1px solid #dbeafe}.ceo-chat-input{width:100%;min-height:90px;border:1px solid #cbd5e1;border-radius:12px;padding:12px}@media(max-width:900px){.ceo-v2-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ceo-chat-layout{grid-template-columns:1fr}}@media(max-width:600px){.ceo-v2-grid{grid-template-columns:1fr}.ceo-v2-hero{padding:18px}.ceo-v2-actions,.ceo-v2-actions form,.ceo-v2-btn{width:100%}}
</style>
<div class="ceo-v2">
 <section class="ceo-v2-hero"><h2>CEO Index</h2><p>입력 지연을 반영한 최종 투입비 예측과 현장 위험을 한곳에서 확인합니다. 현재 결과는 관리 참고자료이며 확정 손익이나 직원평가가 아닙니다.</p><div class="ceo-v2-actions"><form method="get"><input type="hidden" name="r" value="ceo_index"><input type="hidden" name="tab" value="<?php echo h($ceoActiveTab); ?>"><select name="target_ym" onchange="this.form.submit()" style="padding:10px;border:1px solid #cbd5e1;border-radius:10px"><?php if(count($ceoMonths)===0): ?><option value="<?php echo h($ceoTargetYm); ?>"><?php echo h($ceoTargetYm); ?></option><?php else:foreach($ceoMonths as $ym): ?><option value="<?php echo h($ym); ?>"<?php echo $ceoTargetYm===$ym?' selected':''; ?>><?php echo h($ym); ?></option><?php endforeach;endif; ?></select></form><?php if($ceoSectionDeveloper): ?><a class="ceo-v2-btn secondary" href="?r=admin%2Fai_pipeline_setup">개발 설정</a><?php endif; ?></div></section>
 <?php if($ceoActionResult): ?><div class="ceo-v2-message <?php echo !empty($ceoActionResult['ok'])?'ok':'error'; ?>"><?php echo h(isset($ceoActionResult['message'])?$ceoActionResult['message']:'요청을 처리했습니다.'); ?></div><?php endif; ?>
 <?php if($ceoSectionError||!$ceoSectionPdo): ?><div class="ceo-v2-message error">DB 연결 상태를 확인할 수 없습니다.</div><?php elseif(!$ceoV2Ready): ?><div class="ceo-v2-note">신규 투입비 예측이 아직 실행되지 않았습니다.<br>자동 파이프라인 또는 개발부서 수동 분석을 먼저 실행해주세요.</div><?php else: ?><div class="ceo-v2-note">현재 표시 자료는 <?php echo h($ceoSummary['analysis_date']); ?>에 계산된 정상 투입비 예측 결과입니다.</div><?php endif; ?>
 <?php require __DIR__ . '/_summary_bar.php'; ?>
 <?php require __DIR__ . '/_tabs.php'; ?>
 <?php $ceoPartial=__DIR__ . '/'.$ceoActiveTab.'.php'; if(is_file($ceoPartial))require $ceoPartial; ?>
</div>
