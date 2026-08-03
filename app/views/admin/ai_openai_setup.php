<?php
/** OpenAI Responses API and executive briefing setup. PHP 5.6 compatible. */

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

$openAiPdo = null;
$openAiInitializationFailed = false;
$openAiStatus = array(
    'db_available'=>false,'curl_available'=>function_exists('curl_init'),'api_key_configured'=>false,'api_key_source'=>'NONE',
    'model'=>OpenAiResponsesClient::DEFAULT_MODEL,'qa_model'=>OpenAiResponsesClient::DEFAULT_MODEL,
    'reasoning_effort'=>OpenAiResponsesClient::DEFAULT_REASONING_EFFORT,'qa_reasoning_effort'=>OpenAiResponsesClient::DEFAULT_REASONING_EFFORT,
    'max_output_tokens'=>1800,'qa_max_output_tokens'=>1400,'timeout_seconds'=>60,'connect_timeout_seconds'=>10,
    'schema_version'=>OpenAiResponsesClient::DEFAULT_SCHEMA_VERSION,
    'run'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
    'brief'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
    'installed'=>false,'latest_risk'=>array('available'=>false,'analysis_date'=>'','target_ym'=>'','project_count'=>0),
    'completed_count'=>0,'failed_count'=>0,'cached_count'=>0,'latest_run'=>array(),'brief_count'=>0,'brief_project_count'=>0,'latest_brief'=>array()
);
try { $openAiPdo = Db::pdo(); } catch (Exception $e) { $openAiInitializationFailed=true; error_log('[AI OpenAI Setup] db initialization failed'); }

$openAiActionResult = null;
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']==='POST') {
    $token=isset($_POST['_csrf'])?(string)$_POST['_csrf']:'';
    $action=isset($_POST['action'])?trim((string)$_POST['action']):'';
    if (!csrf_check($token)) {
        $openAiActionResult=array('ok'=>false,'message'=>'보안 토큰이 올바르지 않습니다.');
    } else if ($action==='install') {
        if (!$openAiPdo) $openAiActionResult=array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        else {
            try { $openAiActionResult=AiExecutiveBriefService::installOrUpdate($openAiPdo); }
            catch (Exception $e) { $openAiActionResult=array('ok'=>false,'message'=>'OpenAI 경영 브리핑 테이블 설치 또는 확인에 실패했습니다.'); error_log('[AI OpenAI Setup] install failed'); }
        }
    } else if ($action==='test_connection') {
        try { $openAiActionResult=OpenAiResponsesClient::testConnection(); }
        catch (Exception $e) { $openAiActionResult=array('ok'=>false,'message'=>'OpenAI 연결을 확인하지 못했습니다.'); error_log('[OpenAI] task=CONNECTION_TEST status=FAILED'); }
    } else {
        $openAiActionResult=array('ok'=>false,'message'=>'요청값이 올바르지 않습니다.');
    }
}

if (!$openAiInitializationFailed) {
    try {
        $loaded=AiExecutiveBriefService::schemaStatus($openAiPdo);
        if (is_array($loaded)) $openAiStatus=array_merge($openAiStatus,$loaded); else $openAiInitializationFailed=true;
    } catch (Exception $e) { $openAiInitializationFailed=true; error_log('[AI OpenAI Setup] status initialization failed'); }
}
foreach (array('run','brief') as $schemaKey) {
    if (!isset($openAiStatus[$schemaKey]) || !is_array($openAiStatus[$schemaKey])) $openAiStatus[$schemaKey]=array();
    $openAiStatus[$schemaKey]=array_merge(array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),$openAiStatus[$schemaKey]);
    if (!is_array($openAiStatus[$schemaKey]['missing_columns'])) $openAiStatus[$schemaKey]['missing_columns']=array();
    if (!is_array($openAiStatus[$schemaKey]['missing_indexes'])) $openAiStatus[$schemaKey]['missing_indexes']=array();
}
$openAiLatestRun=isset($openAiStatus['latest_run'])&&is_array($openAiStatus['latest_run'])?$openAiStatus['latest_run']:array();
if (empty($_SESSION['_csrf'])) {
    $openAiRandom=function_exists('openssl_random_pseudo_bytes')?@openssl_random_pseudo_bytes(32):false;
    if (!is_string($openAiRandom) || strlen($openAiRandom)<16) $openAiRandom=uniqid((string)mt_rand(),true) . microtime(true) . session_id();
    $_SESSION['_csrf']=hash('sha256',$openAiRandom);
}
$openAiCsrfToken=isset($_SESSION['_csrf'])?(string)$_SESSION['_csrf']:'';
$openAiSourceLabels=array('ENV'=>'환경변수 사용','LOCAL'=>'로컬 비밀설정 사용','NONE'=>'설정 없음');
$openAiSource=isset($openAiStatus['api_key_source'])?(string)$openAiStatus['api_key_source']:'NONE';
?>

<style>
  .oas-wrap{color:#0f172a}.oas-wrap *{box-sizing:border-box}.oas-hero,.oas-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}
  .oas-hero{padding:24px;background:linear-gradient(135deg,#fff 0%,#eef2ff 100%)}.oas-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.oas-hero p{max-width:1080px;margin:9px 0 0;color:#475569;line-height:1.75}
  .oas-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-top:15px}.oas-card{padding:17px}.oas-label{color:#64748b;font-size:11px;font-weight:800}.oas-value{margin-top:8px;font-size:18px;font-weight:900;overflow-wrap:anywhere}
  .oas-section{margin-top:15px;padding:20px}.oas-section h3{margin:0 0 12px;font-size:18px;font-weight:900}.oas-message,.oas-note{margin-top:14px;padding:14px 16px;border-radius:13px;font-size:13px;line-height:1.7}.oas-message.ok{border:1px solid #a7f3d0;background:#ecfdf5;color:#047857}.oas-message.error{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c}.oas-note{border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3}
  .oas-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:15px}.oas-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:0;border-radius:11px;background:#4338ca;color:#fff;text-decoration:none;font-weight:900;cursor:pointer}.oas-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}.oas-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.oas-box{padding:15px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc}.oas-box h4{margin:0 0 8px}.oas-code{max-height:330px;overflow:auto;padding:15px;border-radius:12px;background:#0f172a;color:#c7d2fe;font:12px/1.6 Consolas,monospace;white-space:pre}
  @media(max-width:1000px){.oas-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.oas-hero{padding:18px}.oas-grid,.oas-two{grid-template-columns:1fr}.oas-actions,.oas-actions form,.oas-actions .oas-btn{width:100%}}
</style>

<div class="oas-wrap">
  <section class="oas-hero"><h2>OpenAI 연결 설정</h2><p>PHP가 계산한 최신 적자·원가율 위험자료를 OpenAI Responses API가 대표용 문장으로 설명하도록 연결합니다. API는 이 화면의 POST 버튼 또는 CLI에서만 호출되며, GPT는 계산값을 변경하지 않습니다.</p></section>
  <?php if($openAiInitializationFailed): ?><div class="oas-message error">OpenAI 연결 상태를 불러오지 못했습니다.</div><?php elseif(empty($openAiStatus['db_available'])): ?><div class="oas-message error">DB 연결 상태를 확인할 수 없습니다. 설치 화면은 계속 사용할 수 있습니다.</div><?php elseif(empty($openAiStatus['installed'])): ?><div class="oas-note">OpenAI 경영 브리핑 테이블이 아직 설치되지 않았습니다.</div><?php endif; ?>
  <?php if(empty($openAiStatus['curl_available'])): ?><div class="oas-message error">서버의 PHP cURL 기능을 확인해주세요.</div><?php endif; ?>
  <?php if(empty($openAiStatus['api_key_configured'])): ?><div class="oas-note">OpenAI API 키가 아직 설정되지 않았습니다. 서버 환경변수 <strong>OPENAI_API_KEY</strong> 또는 Git에서 제외된 <strong>app/config/openai.local.php</strong>를 사용해주세요.</div><?php endif; ?>
  <?php if(strtolower((string)$openAiStatus['model'])==='gpt-5-mini'||strtolower((string)$openAiStatus['qa_model'])==='gpt-5-mini'): ?><div class="oas-note">현재 이전 기본 모델이 설정되어 있습니다. GPT-5.6 Terra 사용을 권장합니다.</div><?php endif; ?>
  <?php if(is_array($openAiActionResult)): ?><div class="oas-message <?php echo !empty($openAiActionResult['ok'])?'ok':'error'; ?>"><?php echo h(isset($openAiActionResult['message'])?$openAiActionResult['message']:'처리 결과를 확인할 수 없습니다.'); ?><?php if(isset($openAiActionResult['elapsed_ms'])): ?><div>모델 <?php echo h(isset($openAiActionResult['model'])?$openAiActionResult['model']:'-'); ?> · 응답시간 <?php echo h(number_format((int)$openAiActionResult['elapsed_ms'])); ?>ms · HTTP <?php echo h(isset($openAiActionResult['http_status'])&&$openAiActionResult['http_status']!==null?(int)$openAiActionResult['http_status']:'-'); ?> · 응답 ID <?php echo !empty($openAiActionResult['response_id'])?'확인':'없음'; ?></div><?php endif; ?></div><?php endif; ?>

  <section class="oas-grid" aria-label="OpenAI 설정 상태">
    <div class="oas-card"><div class="oas-label">DB 연결</div><div class="oas-value"><?php echo !empty($openAiStatus['db_available'])?'확인':'확인 불가'; ?></div></div>
    <div class="oas-card"><div class="oas-label">PHP cURL</div><div class="oas-value"><?php echo !empty($openAiStatus['curl_available'])?'사용 가능':'확인 필요'; ?></div></div>
    <div class="oas-card"><div class="oas-label">API 키</div><div class="oas-value"><?php echo !empty($openAiStatus['api_key_configured'])?'설정됨':'미설정'; ?></div></div>
    <div class="oas-card"><div class="oas-label">API 키 출처</div><div class="oas-value"><?php echo h(isset($openAiSourceLabels[$openAiSource])?$openAiSourceLabels[$openAiSource]:'설정 없음'); ?></div></div>
    <div class="oas-card"><div class="oas-label">사용 모델</div><div class="oas-value"><?php echo h($openAiStatus['model']); ?></div></div>
    <div class="oas-card"><div class="oas-label">질문 모델</div><div class="oas-value"><?php echo h($openAiStatus['qa_model']); ?></div></div>
    <div class="oas-card"><div class="oas-label">브리핑 / 질문 추론</div><div class="oas-value"><?php echo h($openAiStatus['reasoning_effort']); ?> / <?php echo h($openAiStatus['qa_reasoning_effort']); ?></div></div>
    <div class="oas-card"><div class="oas-label">최대 출력 토큰</div><div class="oas-value"><?php echo h(number_format((int)$openAiStatus['max_output_tokens'])); ?></div></div>
    <div class="oas-card"><div class="oas-label">연결 / 전체 제한시간</div><div class="oas-value"><?php echo h((int)$openAiStatus['connect_timeout_seconds']); ?>초 / <?php echo h((int)$openAiStatus['timeout_seconds']); ?>초</div></div>
    <div class="oas-card"><div class="oas-label">스키마 버전</div><div class="oas-value"><?php echo h($openAiStatus['schema_version']); ?></div></div>
    <div class="oas-card"><div class="oas-label">GPT 실행이력 테이블</div><div class="oas-value"><?php echo !empty($openAiStatus['run']['installed'])?'설치 완료':(!empty($openAiStatus['run']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="oas-card"><div class="oas-label">경영 브리핑 테이블</div><div class="oas-value"><?php echo !empty($openAiStatus['brief']['installed'])?'설치 완료':(!empty($openAiStatus['brief']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="oas-card"><div class="oas-label">완료 / 실패 / 캐시</div><div class="oas-value"><?php echo h((int)$openAiStatus['completed_count']); ?> / <?php echo h((int)$openAiStatus['failed_count']); ?> / <?php echo h((int)$openAiStatus['cached_count']); ?></div></div>
    <div class="oas-card"><div class="oas-label">저장된 브리핑</div><div class="oas-value"><?php echo h(number_format((int)$openAiStatus['brief_count'])); ?>건</div></div>
    <div class="oas-card"><div class="oas-label">최근 호출상태</div><div class="oas-value"><?php echo h(isset($openAiLatestRun['run_status'])?$openAiLatestRun['run_status']:'-'); ?></div></div>
    <div class="oas-card"><div class="oas-label">최근 HTTP / 모델</div><div class="oas-value"><?php echo h(isset($openAiLatestRun['http_status'])&&$openAiLatestRun['http_status']!==null?$openAiLatestRun['http_status']:'-'); ?> / <?php echo h(isset($openAiLatestRun['model_name'])?$openAiLatestRun['model_name']:'-'); ?></div></div>
    <div class="oas-card"><div class="oas-label">최근 토큰 사용량</div><div class="oas-value"><?php echo h(isset($openAiLatestRun['total_token_count'])&&$openAiLatestRun['total_token_count']!==null?number_format((int)$openAiLatestRun['total_token_count']):'-'); ?></div></div>
    <div class="oas-card"><div class="oas-label">최근 호출일</div><div class="oas-value"><?php echo h(isset($openAiLatestRun['started_at'])?$openAiLatestRun['started_at']:'-'); ?></div></div>
  </section>

  <?php if(!empty($openAiLatestRun['error_summary'])): ?><div class="oas-message error">최근 오류: <?php echo h($openAiLatestRun['error_summary']); ?></div><?php endif; ?>
  <section class="oas-card oas-section"><h3>설치 및 연결 확인</h3><div class="oas-two"><div class="oas-box"><h4>실행이력 구조</h4><div class="oas-label">누락 컬럼 <?php echo h(count($openAiStatus['run']['missing_columns'])); ?>개 · 누락 인덱스 <?php echo h(count($openAiStatus['run']['missing_indexes'])); ?>개</div></div><div class="oas-box"><h4>브리핑 구조</h4><div class="oas-label">누락 컬럼 <?php echo h(count($openAiStatus['brief']['missing_columns'])); ?>개 · 누락 인덱스 <?php echo h(count($openAiStatus['brief']['missing_indexes'])); ?>개</div></div></div>
    <div class="oas-actions">
      <form method="post" action="?r=admin%2Fai_openai_setup"><input type="hidden" name="_csrf" value="<?php echo h($openAiCsrfToken); ?>"><input type="hidden" name="action" value="install"><button class="oas-btn" type="submit">설치/확인</button></form>
      <form method="post" action="?r=admin%2Fai_openai_setup"><input type="hidden" name="_csrf" value="<?php echo h($openAiCsrfToken); ?>"><input type="hidden" name="action" value="test_connection"><button class="oas-btn" type="submit"<?php echo empty($openAiStatus['api_key_configured'])||empty($openAiStatus['curl_available'])?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>OpenAI 연결 테스트</button></form>
      <a class="oas-btn secondary" href="?r=admin%2Fai_ceo_index">CEO Index</a><a class="oas-btn secondary" href="?r=admin%2Fai_executive_brief">경영 브리핑 화면</a><a class="oas-btn secondary" href="?r=admin%2Fai_profit_risk_history">적자·원가율 위험 결과</a><a class="oas-btn secondary" href="?r=admin%2Fai_data_audit">AI 데이터 준비상태 점검</a>
    </div>
    <div class="oas-note">연결 테스트에는 회사자료를 보내지 않습니다. API 키 값, Authorization 헤더, 전체 API 응답은 화면이나 실행이력에 저장하지 않습니다.</div>
  </section>
  <details class="oas-card oas-section"><summary style="cursor:pointer;font-weight:900">설치 예정 테이블 구조 보기</summary><pre class="oas-code"><?php echo h(AiExecutiveBriefService::createRunTableSql() . ";\n\n" . AiExecutiveBriefService::createBriefTableSql() . ';'); ?></pre></details>
</div>
