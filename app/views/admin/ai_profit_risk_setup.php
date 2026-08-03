<?php
/** 적자·원가율 위험분석 설치 및 실행 화면. PHP 5.6 compatible. */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiProfitRiskService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/AiProfitRiskService.php';

$profitRiskPdo = null;
$profitRiskInitializationFailed = false;
$profitRiskStatus = array(
    'db_available'=>false,
    'run'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
    'result'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
    'installed'=>false,
    'latest_forecast'=>array('available'=>false,'forecast_date'=>'','target_ym'=>'','snapshot_date'=>'','project_count'=>0),
    'latest_reliability_date'=>'','latest_anomaly_date'=>'',
    'result_count'=>0,'project_count'=>0,'latest_analysis_date'=>'','last_calculated_at'=>'','latest_run'=>array()
);
try {
    $profitRiskPdo = Db::pdo();
} catch (Exception $e) {
    $profitRiskInitializationFailed = true;
    error_log('[AI Profit Risk Setup] db initialization failed');
}

$profitRiskActionResult = null;
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']==='POST') {
    $token = isset($_POST['_csrf'])?(string)$_POST['_csrf']:'';
    $action = isset($_POST['action'])?trim((string)$_POST['action']):'';
    if (!csrf_check($token)) {
        $profitRiskActionResult = array('ok'=>false,'message'=>'보안 토큰이 올바르지 않습니다.');
    } else if ($action==='install' || $action==='analyze_latest') {
        try {
            if (!$profitRiskPdo) {
                $profitRiskActionResult = array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
            } else if ($action==='install') {
                $profitRiskActionResult = AiProfitRiskService::installOrUpdate($profitRiskPdo);
            } else {
                $profitRiskActionResult = AiProfitRiskService::analyzeLatest($profitRiskPdo,'MANUAL');
            }
        } catch (Exception $e) {
            $profitRiskActionResult = array('ok'=>false,'message'=>'요청을 처리하지 못했습니다. DB 상태를 확인해주세요.');
            error_log('[AI Profit Risk Setup] action failed');
        }
    } else {
        $profitRiskActionResult = array('ok'=>false,'message'=>'요청값이 올바르지 않습니다.');
    }
}

if (!$profitRiskInitializationFailed) {
    try {
        $loadedProfitRiskStatus = AiProfitRiskService::schemaStatus($profitRiskPdo);
        if (is_array($loadedProfitRiskStatus)) $profitRiskStatus = array_merge($profitRiskStatus,$loadedProfitRiskStatus);
        else $profitRiskInitializationFailed = true;
    } catch (Exception $e) {
        $profitRiskInitializationFailed = true;
        error_log('[AI Profit Risk Setup] status initialization failed');
    }
}
foreach (array('run','result') as $schemaKey) {
    if (!isset($profitRiskStatus[$schemaKey]) || !is_array($profitRiskStatus[$schemaKey])) $profitRiskStatus[$schemaKey] = array();
    $profitRiskStatus[$schemaKey] = array_merge(array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),$profitRiskStatus[$schemaKey]);
    if (!is_array($profitRiskStatus[$schemaKey]['missing_columns'])) $profitRiskStatus[$schemaKey]['missing_columns'] = array();
    if (!is_array($profitRiskStatus[$schemaKey]['missing_indexes'])) $profitRiskStatus[$schemaKey]['missing_indexes'] = array();
}
$profitRiskLatestRun = isset($profitRiskStatus['latest_run'])&&is_array($profitRiskStatus['latest_run'])?$profitRiskStatus['latest_run']:array();
$profitRiskLatestForecast = isset($profitRiskStatus['latest_forecast'])&&is_array($profitRiskStatus['latest_forecast'])?$profitRiskStatus['latest_forecast']:array();
$profitRiskExecutable = !empty($profitRiskStatus['installed']) && !empty($profitRiskLatestForecast['available']);
if (empty($_SESSION['_csrf'])) {
    $profitRiskRandom = '';
    if (function_exists('openssl_random_pseudo_bytes')) $profitRiskRandom = @openssl_random_pseudo_bytes(32);
    if (!is_string($profitRiskRandom) || strlen($profitRiskRandom) < 16) {
        $profitRiskRandom = uniqid((string)mt_rand(), true) . microtime(true) . session_id();
    }
    $_SESSION['_csrf'] = hash('sha256', $profitRiskRandom);
}
$profitRiskCsrfToken = isset($_SESSION['_csrf']) ? (string)$_SESSION['_csrf'] : '';
?>

<style>
  .prs-wrap{color:#0f172a}.prs-wrap *{box-sizing:border-box}.prs-hero,.prs-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}
  .prs-hero{padding:24px;background:linear-gradient(135deg,#fff 0%,#fef2f2 100%)}.prs-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.prs-hero p{max-width:1050px;margin:10px 0 0;color:#475569;font-size:14px;line-height:1.75}
  .prs-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-top:15px}.prs-card{padding:17px}.prs-label{color:#64748b;font-size:11px;font-weight:800}.prs-value{margin-top:8px;font-size:18px;font-weight:900;overflow-wrap:anywhere}.prs-message{margin-top:15px;padding:14px 16px;border-radius:13px;font-size:14px;font-weight:800;line-height:1.65}.prs-message.ok{border:1px solid #a7f3d0;background:#ecfdf5;color:#047857}.prs-message.error{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c}
  .prs-section{margin-top:15px;padding:20px}.prs-section h3{margin:0 0 14px;font-size:18px;font-weight:900}.prs-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:15px}.prs-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:0;border-radius:11px;background:#b91c1c;color:#fff;text-decoration:none;font-weight:900;cursor:pointer}.prs-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}.prs-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.prs-box{padding:15px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc}.prs-box h4{margin:0 0 8px;font-size:14px}.prs-note{margin-top:14px;padding:14px;border:1px solid #fde68a;border-radius:13px;background:#fffbeb;color:#92400e;font-size:13px;line-height:1.75}.prs-code{max-height:330px;overflow:auto;padding:15px;border-radius:12px;background:#0f172a;color:#fee2e2;font:12px/1.6 Consolas,monospace;white-space:pre}
  .prs-grade.NORMAL{color:#166534}.prs-grade.WATCH{color:#1d4ed8}.prs-grade.WARNING{color:#c2410c}.prs-grade.CRITICAL{color:#b91c1c}.prs-grade.INSUFFICIENT{color:#64748b}
  @media(max-width:1000px){.prs-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.prs-hero{padding:18px}.prs-grid,.prs-two{grid-template-columns:1fr}.prs-actions,.prs-actions form,.prs-actions .prs-btn{width:100%}}
</style>

<div class="prs-wrap">
  <section class="prs-hero"><h2>적자·원가율 위험 설정</h2><p>저장된 월말 예측, 일일 스냅샷, 입력 신뢰도와 이상징후 결과로 관리 확인 우선순위를 계산합니다. <strong>현재 결과는 확정손익이나 준공손익이 아니며 향후 잔여 공사비를 포함하지 않습니다.</strong></p></section>

  <?php if ($profitRiskInitializationFailed): ?><div class="prs-message error">적자·원가율 위험분석 상태를 불러오지 못했습니다.</div><?php elseif (empty($profitRiskStatus['db_available'])): ?><div class="prs-message error">DB 연결 상태를 확인할 수 없습니다.</div><?php elseif (empty($profitRiskStatus['installed'])): ?><div class="prs-note">적자·원가율 위험분석 테이블이 아직 설치되지 않았습니다.</div><?php endif; ?>
  <?php if (is_array($profitRiskActionResult)): ?><div class="prs-message <?php echo !empty($profitRiskActionResult['ok'])?'ok':'error'; ?>"><?php echo h(isset($profitRiskActionResult['message'])?$profitRiskActionResult['message']:'처리 결과를 확인할 수 없습니다.'); ?><?php if (isset($profitRiskActionResult['status'])): ?><div style="margin-top:5px;font-weight:700;">상태 <?php echo h($profitRiskActionResult['status']); ?> · 대상 <?php echo h(number_format((int)$profitRiskActionResult['projects'])); ?>개 · 안정 <?php echo h(number_format((int)$profitRiskActionResult['normal'])); ?>개 · 관심 <?php echo h(number_format((int)$profitRiskActionResult['watch'])); ?>개 · 주의 <?php echo h(number_format((int)$profitRiskActionResult['warning'])); ?>개 · 적자 위험 <?php echo h(number_format((int)$profitRiskActionResult['critical'])); ?>개 · 실패 <?php echo h(number_format((int)$profitRiskActionResult['failed'])); ?>개</div><?php endif; ?></div><?php endif; ?>

  <section class="prs-grid" aria-label="적자 원가율 위험분석 상태">
    <div class="prs-card"><div class="prs-label">DB 연결</div><div class="prs-value"><?php echo !empty($profitRiskStatus['db_available'])?'확인':'확인 불가'; ?></div></div>
    <div class="prs-card"><div class="prs-label">실행이력 테이블</div><div class="prs-value"><?php echo !empty($profitRiskStatus['run']['installed'])?'설치 완료':(!empty($profitRiskStatus['run']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="prs-card"><div class="prs-label">위험결과 테이블</div><div class="prs-value"><?php echo !empty($profitRiskStatus['result']['installed'])?'설치 완료':(!empty($profitRiskStatus['result']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="prs-card"><div class="prs-label">최신 스냅샷</div><div class="prs-value"><?php echo h(!empty($profitRiskLatestForecast['snapshot_date'])?$profitRiskLatestForecast['snapshot_date']:'-'); ?></div></div>
    <div class="prs-card"><div class="prs-label">최신 예측일</div><div class="prs-value"><?php echo h(!empty($profitRiskLatestForecast['forecast_date'])?$profitRiskLatestForecast['forecast_date']:'-'); ?></div></div>
    <div class="prs-card"><div class="prs-label">최근 신뢰도 분석일</div><div class="prs-value"><?php echo h(!empty($profitRiskStatus['latest_reliability_date'])?$profitRiskStatus['latest_reliability_date']:'-'); ?></div></div>
    <div class="prs-card"><div class="prs-label">최근 이상징후 분석일</div><div class="prs-value"><?php echo h(!empty($profitRiskStatus['latest_anomaly_date'])?$profitRiskStatus['latest_anomaly_date']:'-'); ?></div></div>
    <div class="prs-card"><div class="prs-label">대상 월</div><div class="prs-value"><?php echo h(!empty($profitRiskLatestForecast['target_ym'])?$profitRiskLatestForecast['target_ym']:'-'); ?></div></div>
    <div class="prs-card"><div class="prs-label">위험분석 실행 가능</div><div class="prs-value" style="color:<?php echo $profitRiskExecutable?'#047857':'#c2410c'; ?>"><?php echo $profitRiskExecutable?'가능':'준비 필요'; ?></div></div>
    <div class="prs-card"><div class="prs-label">최근 실행 상태</div><div class="prs-value"><?php echo h(isset($profitRiskLatestRun['run_status'])?$profitRiskLatestRun['run_status']:'-'); ?></div></div>
    <div class="prs-card"><div class="prs-label">분석 현장</div><div class="prs-value"><?php echo h(number_format(isset($profitRiskLatestRun['success_count'])?(int)$profitRiskLatestRun['success_count']:0)); ?>개</div></div>
    <div class="prs-card"><div class="prs-label">안정 / 관심</div><div class="prs-value"><span class="prs-grade NORMAL"><?php echo h(number_format(isset($profitRiskLatestRun['normal_count'])?(int)$profitRiskLatestRun['normal_count']:0)); ?></span> / <span class="prs-grade WATCH"><?php echo h(number_format(isset($profitRiskLatestRun['watch_count'])?(int)$profitRiskLatestRun['watch_count']:0)); ?></span></div></div>
    <div class="prs-card"><div class="prs-label">주의 / 적자 위험</div><div class="prs-value"><span class="prs-grade WARNING"><?php echo h(number_format(isset($profitRiskLatestRun['warning_count'])?(int)$profitRiskLatestRun['warning_count']:0)); ?></span> / <span class="prs-grade CRITICAL"><?php echo h(number_format(isset($profitRiskLatestRun['critical_count'])?(int)$profitRiskLatestRun['critical_count']:0)); ?></span></div></div>
    <div class="prs-card"><div class="prs-label">판단자료 부족</div><div class="prs-value prs-grade INSUFFICIENT"><?php echo h(number_format(isset($profitRiskLatestRun['insufficient_count'])?(int)$profitRiskLatestRun['insufficient_count']:0)); ?>개</div></div>
    <div class="prs-card"><div class="prs-label">월 예상매출 합계</div><div class="prs-value"><?php echo h(number_format(isset($profitRiskLatestRun['monthly_sales_total'])?(float)$profitRiskLatestRun['monthly_sales_total']:0)); ?>원</div></div>
    <div class="prs-card"><div class="prs-label">월 예상투입비 합계</div><div class="prs-value"><?php echo h(number_format(isset($profitRiskLatestRun['monthly_forecast_input_total'])?(float)$profitRiskLatestRun['monthly_forecast_input_total']:0)); ?>원</div></div>
    <div class="prs-card"><div class="prs-label">월 예상손익 합계</div><div class="prs-value"><?php echo h(number_format(isset($profitRiskLatestRun['monthly_forecast_profit_total'])?(float)$profitRiskLatestRun['monthly_forecast_profit_total']:0)); ?>원</div></div>
  </section>

  <section class="prs-card prs-section"><h3>설치 및 최신 자료 기준 분석</h3><div class="prs-two"><div class="prs-box"><h4>실행이력 구조</h4><div class="prs-label">누락 컬럼 <?php echo h(number_format(count($profitRiskStatus['run']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($profitRiskStatus['run']['missing_indexes']))); ?>개</div></div><div class="prs-box"><h4>결과 구조</h4><div class="prs-label">누락 컬럼 <?php echo h(number_format(count($profitRiskStatus['result']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($profitRiskStatus['result']['missing_indexes']))); ?>개</div></div></div>
    <div class="prs-actions">
      <form method="post" action="?r=admin%2Fai_profit_risk_setup"><input type="hidden" name="_csrf" value="<?php echo h($profitRiskCsrfToken); ?>"><input type="hidden" name="action" value="install"><button class="prs-btn" type="submit">설치/확인</button></form>
      <form method="post" action="?r=admin%2Fai_profit_risk_setup"><input type="hidden" name="_csrf" value="<?php echo h($profitRiskCsrfToken); ?>"><input type="hidden" name="action" value="analyze_latest"><button class="prs-btn" type="submit"<?php echo !$profitRiskExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>최신 자료 기준 위험분석</button></form>
      <form method="post" action="?r=admin%2Fai_profit_risk_setup"><input type="hidden" name="_csrf" value="<?php echo h($profitRiskCsrfToken); ?>"><input type="hidden" name="action" value="analyze_latest"><button class="prs-btn secondary" type="submit"<?php echo !$profitRiskExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>위험분석 다시 실행</button></form>
      <a class="prs-btn secondary" href="?r=admin%2Fai_profit_risk_history">위험분석 결과 보기</a><a class="prs-btn secondary" href="?r=admin%2Fai_openai_setup">OpenAI 연결 설정</a><a class="prs-btn secondary" href="?r=admin%2Fai_executive_brief">대표용 경영 브리핑</a><a class="prs-btn secondary" href="?r=admin%2Fai_anomaly_setup">이상징후 탐지 설정</a><a class="prs-btn secondary" href="?r=admin%2Fai_reliability_setup">입력 신뢰도 설정</a><a class="prs-btn secondary" href="?r=admin%2Fai_forecast_setup">기본 월말 예측 설정</a><a class="prs-btn secondary" href="?r=admin%2Fai_snapshot_setup">일일 스냅샷 설정</a><a class="prs-btn secondary" href="?r=admin%2Fai_data_audit">AI 데이터 준비상태 점검</a>
    </div>
    <?php if (empty($profitRiskLatestForecast['available'])): ?><div class="prs-note">적자·원가율 위험을 분석하려면 먼저 월말 예측을 실행해주세요.</div><?php else: ?><div class="prs-note">실행 순서: 1. 일일 스냅샷 생성 → 2. 기본 월말 예측 실행 → 3. 입력 신뢰도 계산 → 4. 이상징후 탐지 → 5. 적자·원가율 위험분석. 이상징후 결과는 보조근거이며 재무위험을 자동 확정하지 않습니다.</div><?php endif; ?>
  </section>
  <section class="prs-card prs-section"><h3>분석 기준 안내</h3><div class="prs-two"><div class="prs-box"><h4>손익·원가율</h4><div class="prs-label">스냅샷 매출과 월말 예상 투입비를 사용합니다. 누적 예상투입비는 누적 현재금액에서 이번 달 현재금액을 빼고 이번 달 예상금액으로 교체합니다.</div></div><div class="prs-box"><h4>계약 비교</h4><div class="prs-label">계약금액 대비 현재 누적 투입 수준입니다. 향후 잔여 공사비와 변경계약은 별도로 확인해야 합니다.</div></div></div></section>
  <section class="prs-card prs-section"><h3>독립 실행 안내</h3><p style="margin:0;color:#475569;font-size:13px;line-height:1.75;">자동 예약작업은 설정하지 않았습니다. 향후 예약 실행 시 <strong>일일 스냅샷 → 월말 예측 → 입력 신뢰도 → 이상징후 탐지 → 적자·원가율 위험분석</strong> 순서로 각 CLI 파일을 독립 실행할 수 있습니다.</p></section>
  <details class="prs-card prs-section"><summary style="cursor:pointer;font-weight:900">설치 예정 테이블 구조 보기</summary><pre class="prs-code"><?php echo h(AiProfitRiskService::createRunTableSql() . ";\n\n" . AiProfitRiskService::createResultTableSql() . ';'); ?></pre></details>
</div>
