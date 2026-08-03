<?php
/** AI daily pipeline setup. PHP 5.6 compatible. */
use App\Core\Auth;
use App\Core\Db;
use App\Services\AiDailyPipelineService;
use App\Services\AiExecutiveChatService;
use App\Services\AiInputCompletionPatternService;
use App\Services\AiCostForecastV2Service;
use App\Services\AiCeoIndexService;
use App\Services\AiExecutiveBriefService;
use App\Services\OpenAiResponsesClient;

require_once __DIR__ . '/../../services/AiDailyPipelineService.php';
require_once __DIR__ . '/../../services/AiExecutiveChatService.php';
require_once __DIR__ . '/../../services/AiExecutiveBriefService.php';

if (!Auth::check() || !Auth::isDevelopmentDepartment()) {
    http_response_code(403);
    echo '<div class="ap-message error">접근 권한이 없습니다.</div>';
    return;
}

$apPdo = null;
$apResult = null;
$apError = false;
try { $apPdo = Db::pdo(); } catch (Exception $e) { $apError = true; error_log('[AI Pipeline Setup] db unavailable'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        $apResult = array('ok'=>false, 'message'=>'요청을 확인하지 못했습니다. 다시 시도해주세요.');
    } elseif (!$apPdo) {
        $apResult = array('ok'=>false, 'message'=>'DB 연결 상태를 확인할 수 없습니다.');
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
        try {
            if ($action === 'install') {
                $apResult = AiDailyPipelineService::installOrUpdate($apPdo);
                if (!empty($apResult['ok'])) {
                    $chatInstall = AiExecutiveChatService::installOrUpdate($apPdo);
                    if (empty($chatInstall['ok'])) $apResult = $chatInstall;
                }
            } elseif ($action === 'run') {
                $apResult = AiDailyPipelineService::run($apPdo, 'MANUAL', false);
            } elseif ($action === 'force_run') {
                $apResult = AiDailyPipelineService::run($apPdo, 'MANUAL', true);
            } elseif ($action === 'rerun_gpt_summary') {
                $apResult = AiExecutiveBriefService::generateLatest($apPdo, 'MANUAL_GPT_SUMMARY', true);
            } elseif ($action === 'save_settings') {
                $minimum = isset($_POST['min_completion_rate']) ? (int)$_POST['min_completion_rate'] : 20;
                $chatSeconds = isset($_POST['chat_min_seconds']) ? (int)$_POST['chat_min_seconds'] : 3;
                $chatHourly = isset($_POST['chat_hourly_limit']) ? (int)$_POST['chat_hourly_limit'] : 30;
                $ok = AiInputCompletionPatternService::saveSettings($apPdo, 0, $minimum);
                $ok = AiExecutiveChatService::saveLimits($apPdo, $chatSeconds, $chatHourly) && $ok;
                $apResult = array('ok'=>$ok, 'message'=>$ok ? 'AI 분석 설정을 저장했습니다.' : 'AI 분석 설정을 저장하지 못했습니다.');
            }
        } catch (Exception $e) {
            error_log('[AI Pipeline Setup] action failed');
            $apResult = array('ok'=>false, 'message'=>'요청을 처리하지 못했습니다.');
        }
    }
}

$apStatus = array('db_available'=>(bool)$apPdo, 'installed'=>false, 'latest_run'=>array(), 'run_count'=>0, 'settings'=>array('min_completion_rate'=>20));
$apChatStatus = array('installed'=>false,'min_seconds'=>3,'hourly_limit'=>30);
$apPatternStatus = array('installed'=>false);
$apForecastStatus = array('installed'=>false);
$apCeoStatus = array('installed'=>false);
$apCanGptSummary = false;
$apGptRuns = array();
if ($apPdo) {
    try {
        $apStatus = AiDailyPipelineService::schemaStatus($apPdo);
        $apChatStatus = AiExecutiveChatService::schemaStatus($apPdo);
        $apPatternStatus['installed'] = AiInputCompletionPatternService::isInstalled($apPdo);
        $apForecastStatus['installed'] = AiCostForecastV2Service::isInstalled($apPdo);
        $apCeoStatus['installed'] = AiCeoIndexService::isV2Installed($apPdo);
        $apLatestForecast = AiCostForecastV2Service::latestCompanySummary($apPdo);
        $apLatestCeo = AiCeoIndexService::latestNormalV2($apPdo);
        $apCanGptSummary = !empty($apLatestForecast['available']) && isset($apLatestForecast['calculation_version']) && $apLatestForecast['calculation_version'] === AiCostForecastV2Service::CALCULATION_VERSION && !empty($apLatestCeo) && isset($apLatestCeo['calculation_version']) && $apLatestCeo['calculation_version'] === AiCeoIndexService::V2_VERSION && OpenAiResponsesClient::hasApiKey() && function_exists('curl_init') && AiExecutiveBriefService::isInstalled($apPdo);
        $apGptRuns = AiExecutiveBriefService::recentRuns($apPdo,10);
    } catch (Exception $e) { $apError = true; error_log('[AI Pipeline Setup] status failed'); }
}
$apLatest = isset($apStatus['latest_run']) && is_array($apStatus['latest_run']) ? $apStatus['latest_run'] : array();
$apCsrf = csrf_token();
?>
<style>
.ap-wrap{max-width:1200px;margin:0 auto;color:#172033}.ap-hero,.ap-card{background:#fff;border:1px solid #e3e8ef;border-radius:18px;padding:20px;box-shadow:0 8px 24px rgba(15,23,42,.05)}.ap-hero{margin-bottom:16px}.ap-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}.ap-label{font-size:12px;color:#64748b}.ap-value{font-size:22px;font-weight:800;margin-top:6px}.ap-message{padding:12px 14px;border-radius:12px;margin:12px 0;background:#eff6ff;color:#1d4ed8}.ap-message.error{background:#fff1f2;color:#be123c}.ap-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.ap-btn{border:0;border-radius:10px;background:#1d4ed8;color:#fff;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none}.ap-btn.secondary{background:#eef2ff;color:#3730a3}.ap-btn.danger{background:#b91c1c}.ap-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.ap-form label{font-size:13px;font-weight:700}.ap-form input{display:block;width:100%;margin-top:6px;border:1px solid #cbd5e1;border-radius:9px;padding:9px}.ap-note{font-size:13px;color:#64748b;line-height:1.65}.ap-ok{color:#15803d}.ap-no{color:#b91c1c}@media(max-width:850px){.ap-grid,.ap-form{grid-template-columns:1fr 1fr}}@media(max-width:520px){.ap-grid,.ap-form{grid-template-columns:1fr}}
</style>
<div class="ap-wrap">
 <section class="ap-hero"><h2>AI 자동 분석 파이프라인 설정</h2><p>일일 스냅샷부터 입력완료 패턴, V2 예측, 위험분석, CEO Index V2와 GPT 요약을 순서대로 실행합니다. 일반 업무화면에서는 자동 실행하지 않습니다.</p>
 <?php if ($apResult): ?><div class="ap-message<?php echo empty($apResult['ok'])?' error':''; ?>"><?php echo h(isset($apResult['message'])?$apResult['message']:'요청을 처리했습니다.'); ?><?php if(empty($apResult['ok'])&&!empty($apResult['error_code'])): ?><br><small>오류코드: <?php echo h($apResult['error_code']); ?></small><?php endif; ?></div><?php endif; ?>
 <?php if ($apError || !$apPdo): ?><div class="ap-message error">DB 연결 상태를 확인할 수 없습니다.</div><?php elseif (empty($apStatus['installed'])): ?><div class="ap-message">AI 자동 분석 파이프라인 테이블이 아직 설치되지 않았습니다.</div><?php endif; ?>
 <div class="ap-actions"><form method="post"><input type="hidden" name="_csrf" value="<?php echo h($apCsrf); ?>"><input type="hidden" name="action" value="install"><button class="ap-btn" type="submit">설치/확인</button></form><form method="post"><input type="hidden" name="_csrf" value="<?php echo h($apCsrf); ?>"><input type="hidden" name="action" value="run"><button class="ap-btn secondary" type="submit"<?php echo empty($apStatus['installed'])?' disabled':''; ?>>오늘 분석 실행</button></form><form method="post"><input type="hidden" name="_csrf" value="<?php echo h($apCsrf); ?>"><input type="hidden" name="action" value="force_run"><button class="ap-btn danger" type="submit"<?php echo empty($apStatus['installed'])?' disabled':''; ?>>오늘 강제 재실행</button></form><form method="post"><input type="hidden" name="_csrf" value="<?php echo h($apCsrf); ?>"><input type="hidden" name="action" value="rerun_gpt_summary"><button class="ap-btn secondary" type="submit"<?php echo !$apCanGptSummary?' disabled':''; ?>>GPT 요약만 다시 실행</button></form><a class="ap-btn secondary" href="?r=admin%2Fai_pipeline_history">실행 이력</a><a class="ap-btn secondary" href="?r=ceo_index">CEO Index</a></div><?php if(!$apCanGptSummary): ?><p class="ap-note">GPT 요약 단독 실행에는 최신 정상 투입비 예측·CEO Index 자료, API 설정 및 PHP cURL이 필요합니다.</p><?php endif; ?></section>
 <div class="ap-grid"><div class="ap-card"><div class="ap-label">파이프라인</div><div class="ap-value <?php echo !empty($apStatus['installed'])?'ap-ok':'ap-no'; ?>"><?php echo !empty($apStatus['installed'])?'설치 완료':'미설치'; ?></div></div><div class="ap-card"><div class="ap-label">입력완료 패턴</div><div class="ap-value"><?php echo !empty($apPatternStatus['installed'])?'설치':'미설치'; ?></div></div><div class="ap-card"><div class="ap-label">V2 예측</div><div class="ap-value"><?php echo !empty($apForecastStatus['installed'])?'설치':'미설치'; ?></div></div><div class="ap-card"><div class="ap-label">CEO Index V2 / GPT 대화</div><div class="ap-value"><?php echo !empty($apCeoStatus['installed'])&&!empty($apChatStatus['installed'])?'설치':'확인 필요'; ?></div></div></div>
 <section class="ap-card"><h3>최근 실행</h3><div class="ap-grid"><div><div class="ap-label">상태</div><div class="ap-value"><?php echo h(isset($apLatest['run_status'])?$apLatest['run_status']:'-'); ?></div></div><div><div class="ap-label">실행일</div><div class="ap-value"><?php echo h(isset($apLatest['run_date'])?$apLatest['run_date']:'-'); ?></div></div><div><div class="ap-label">마지막 성공 단계</div><div class="ap-value"><?php echo h(isset($apLatest['last_success_step'])?$apLatest['last_success_step']:'-'); ?></div></div><div><div class="ap-label">실패 단계</div><div class="ap-value"><?php echo h(isset($apLatest['failed_step'])?$apLatest['failed_step']:'-'); ?></div></div></div></section>
 <section class="ap-card" style="margin-top:16px"><h3>분석 설정</h3><form method="post" class="ap-form"><input type="hidden" name="_csrf" value="<?php echo h($apCsrf); ?>"><input type="hidden" name="action" value="save_settings"><label>직접 투영 최소 완료율(%)<input type="number" min="1" max="100" name="min_completion_rate" value="<?php echo h($apStatus['settings']['min_completion_rate']); ?>"></label><label>GPT 질문 최소 간격(초)<input type="number" min="1" max="60" name="chat_min_seconds" value="<?php echo h($apChatStatus['min_seconds']); ?>"></label><label>GPT 시간당 질문 수<input type="number" min="1" max="200" name="chat_hourly_limit" value="<?php echo h($apChatStatus['hourly_limit']); ?>"></label><div><button class="ap-btn" type="submit">설정 저장</button></div></form><p class="ap-note">노무비는 해당 월 말일, 그 외 투입비는 해당 월 이십오일에 최종 마감되며 추가 대기일 없이 학습자료로 사용할 수 있습니다. GPT는 저장된 근거자료만 설명하며 store=false로 호출됩니다.</p></section>
 <section class="ap-card" style="margin-top:16px"><h3>예약 실행 안내</h3><p class="ap-note">서버 예약작업을 사용할 수 있는 경우 <code>tools/ai_daily_pipeline_job.php</code>를 하루 한 번 실행하도록 설정할 수 있습니다. 서버 운영체제별 명령과 절대경로는 자동 등록하거나 화면에 표시하지 않습니다. 웹호스팅 예약작업 설정은 서버 관리자 화면에서 확인해주세요.</p></section>
 <section class="ap-card" style="margin-top:16px"><h3>최근 GPT 요약 실행이력</h3><div style="overflow-x:auto"><table style="width:100%;min-width:850px;border-collapse:collapse"><thead><tr><th>실행시각</th><th>대상 월</th><th>실행 구분</th><th>상태</th><th>모델</th><th>실행자</th><th>완료시각</th><th>오류코드</th><th>안전 오류요약</th></tr></thead><tbody><?php if(count($apGptRuns)===0): ?><tr><td colspan="9">표시할 GPT 요약 실행이력이 없습니다.</td></tr><?php else:foreach($apGptRuns as $run): ?><tr><td><?php echo h($run['started_at']); ?></td><td><?php echo h($run['target_ym']); ?></td><td><?php echo h($run['trigger_type']==='MANUAL_GPT_SUMMARY'?'GPT 요약 단독 실행':$run['trigger_type']); ?></td><td><?php echo h($run['run_status']); ?></td><td><?php echo h($run['model_name']); ?></td><td><?php echo h(trim((string)$run['actor_name'])!==''?$run['actor_name']:'시스템'); ?></td><td><?php echo h($run['finished_at']); ?></td><td><?php echo h($run['error_code']); ?></td><td><?php echo h($run['error_summary']); ?></td></tr><?php endforeach;endif; ?></tbody></table></div></section>
</div>
