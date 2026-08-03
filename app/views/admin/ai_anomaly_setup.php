<?php
/** 현장별 비용 이상징후 탐지 설치 및 실행 화면. PHP 5.6 compatible. */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiAnomalyDetectionService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/AiAnomalyDetectionService.php';

$anomalyPdo = null;
$anomalyInitializationFailed = false;
$anomalyStatus = array(
    'db_available'=>false,
    'run'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
    'result'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
    'installed'=>false,
    'latest_reliability'=>array('available'=>false,'reliability_date'=>'','target_ym'=>'','forecast_date'=>'','snapshot_date'=>'','project_count'=>0),
    'result_count'=>0,'project_count'=>0,'latest_analysis_date'=>'','last_calculated_at'=>'','latest_run'=>array()
);
try {
    $anomalyPdo = Db::pdo();
} catch (Exception $e) {
    $anomalyInitializationFailed = true;
    error_log('[AI Anomaly Setup] db initialization failed');
}

$anomalyActionResult = null;
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf'])?(string)$_POST['_csrf']:'';
    $action = isset($_POST['action'])?trim((string)$_POST['action']):'';
    if (!csrf_check($token)) {
        $anomalyActionResult = array('ok'=>false,'message'=>'보안 토큰이 올바르지 않습니다.');
    } else if ($action === 'install') {
        $anomalyActionResult = AiAnomalyDetectionService::installOrUpdate($anomalyPdo);
    } else if ($action === 'detect_latest') {
        $anomalyActionResult = AiAnomalyDetectionService::detectLatest($anomalyPdo,'MANUAL');
    } else {
        $anomalyActionResult = array('ok'=>false,'message'=>'요청값이 올바르지 않습니다.');
    }
}

if (!$anomalyInitializationFailed) {
    try {
        $loadedAnomalyStatus = AiAnomalyDetectionService::schemaStatus($anomalyPdo);
        if (is_array($loadedAnomalyStatus)) $anomalyStatus = array_merge($anomalyStatus,$loadedAnomalyStatus);
        else $anomalyInitializationFailed = true;
    } catch (Exception $e) {
        $anomalyInitializationFailed = true;
        error_log('[AI Anomaly Setup] status initialization failed');
    }
}
if (!isset($anomalyStatus['run']) || !is_array($anomalyStatus['run'])) $anomalyStatus['run'] = array();
if (!isset($anomalyStatus['result']) || !is_array($anomalyStatus['result'])) $anomalyStatus['result'] = array();
$anomalyStatus['run'] = array_merge(array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),$anomalyStatus['run']);
$anomalyStatus['result'] = array_merge(array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),$anomalyStatus['result']);
foreach (array('run','result') as $schemaKey) {
    if (!is_array($anomalyStatus[$schemaKey]['missing_columns'])) $anomalyStatus[$schemaKey]['missing_columns'] = array();
    if (!is_array($anomalyStatus[$schemaKey]['missing_indexes'])) $anomalyStatus[$schemaKey]['missing_indexes'] = array();
}
$anomalyLatestRun = isset($anomalyStatus['latest_run'])&&is_array($anomalyStatus['latest_run'])?$anomalyStatus['latest_run']:array();
$anomalyLatestReliability = isset($anomalyStatus['latest_reliability'])&&is_array($anomalyStatus['latest_reliability'])?$anomalyStatus['latest_reliability']:array();
$anomalyExecutable = !empty($anomalyStatus['installed']) && !empty($anomalyLatestReliability['available']);
?>

<style>
  .as-wrap{color:#0f172a}.as-wrap *{box-sizing:border-box}.as-hero,.as-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}
  .as-hero{padding:24px;background:linear-gradient(135deg,#fff 0%,#fff7ed 100%)}.as-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.as-hero p{max-width:1000px;margin:10px 0 0;color:#475569;font-size:14px;line-height:1.75}
  .as-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-top:15px}.as-card{padding:17px}.as-label{color:#64748b;font-size:11px;font-weight:800}.as-value{margin-top:8px;font-size:18px;font-weight:900;overflow-wrap:anywhere}.as-message{margin-top:15px;padding:14px 16px;border-radius:13px;font-size:14px;font-weight:800;line-height:1.65}.as-message.ok{border:1px solid #a7f3d0;background:#ecfdf5;color:#047857}.as-message.error{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c}
  .as-section{margin-top:15px;padding:20px}.as-section h3{margin:0 0 14px;font-size:18px;font-weight:900}.as-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:15px}.as-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:0;border-radius:11px;background:#c2410c;color:#fff;text-decoration:none;font-weight:900;cursor:pointer}.as-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}.as-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.as-box{padding:15px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc}.as-box h4{margin:0 0 8px;font-size:14px}.as-note{margin-top:14px;padding:14px;border:1px solid #fde68a;border-radius:13px;background:#fffbeb;color:#92400e;font-size:13px;line-height:1.75}.as-code{max-height:330px;overflow:auto;padding:15px;border-radius:12px;background:#0f172a;color:#ffedd5;font:12px/1.6 Consolas,monospace;white-space:pre}
  .as-grade.NORMAL{color:#166534}.as-grade.WATCH{color:#1d4ed8}.as-grade.WARNING{color:#c2410c}.as-grade.CRITICAL{color:#b91c1c}.as-grade.INSUFFICIENT{color:#64748b}
  @media(max-width:1000px){.as-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.as-hero{padding:18px}.as-grid,.as-two{grid-template-columns:1fr}.as-actions,.as-actions form,.as-actions .as-btn{width:100%}}
</style>

<div class="as-wrap">
  <section class="as-hero"><h2>이상징후 탐지 설정</h2><p>저장된 입력 신뢰도, 월말 예측, 일일 스냅샷, 통합 비용 이벤트를 비교해 관리자가 확인할 데이터 변화를 찾습니다. <strong>이상징후는 실제 오류, 부정행위 또는 문제를 확정하는 결과가 아닙니다.</strong></p></section>

  <?php if ($anomalyInitializationFailed): ?><div class="as-message error">이상징후 탐지 상태를 불러오지 못했습니다.</div><?php elseif (empty($anomalyStatus['db_available'])): ?><div class="as-message error">DB 연결 상태를 확인할 수 없습니다.</div><?php elseif (empty($anomalyStatus['installed'])): ?><div class="as-note">이상징후 탐지 테이블이 아직 설치되지 않았습니다.</div><?php endif; ?>
  <?php if (is_array($anomalyActionResult)): ?><div class="as-message <?php echo !empty($anomalyActionResult['ok'])?'ok':'error'; ?>"><?php echo h(isset($anomalyActionResult['message'])?$anomalyActionResult['message']:'처리 결과를 확인할 수 없습니다.'); ?><?php if (isset($anomalyActionResult['status'])): ?><div style="margin-top:5px;font-weight:700;">상태 <?php echo h($anomalyActionResult['status']); ?> · 대상 <?php echo h(number_format((int)$anomalyActionResult['projects'])); ?>개 · 정상 <?php echo h(number_format((int)$anomalyActionResult['normal'])); ?>개 · 관심 <?php echo h(number_format((int)$anomalyActionResult['watch'])); ?>개 · 주의 <?php echo h(number_format((int)$anomalyActionResult['warning'])); ?>개 · 긴급 확인 <?php echo h(number_format((int)$anomalyActionResult['critical'])); ?>개 · 실패 <?php echo h(number_format((int)$anomalyActionResult['failed'])); ?>개</div><?php endif; ?></div><?php endif; ?>

  <section class="as-grid" aria-label="이상징후 탐지 상태">
    <div class="as-card"><div class="as-label">DB 연결</div><div class="as-value"><?php echo !empty($anomalyStatus['db_available'])?'확인':'확인 불가'; ?></div></div>
    <div class="as-card"><div class="as-label">실행이력 테이블</div><div class="as-value"><?php echo !empty($anomalyStatus['run']['installed'])?'설치 완료':(!empty($anomalyStatus['run']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="as-card"><div class="as-label">탐지 결과 테이블</div><div class="as-value"><?php echo !empty($anomalyStatus['result']['installed'])?'설치 완료':(!empty($anomalyStatus['result']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="as-card"><div class="as-label">최신 스냅샷</div><div class="as-value"><?php echo h(!empty($anomalyLatestReliability['snapshot_date'])?$anomalyLatestReliability['snapshot_date']:'-'); ?></div></div>
    <div class="as-card"><div class="as-label">최신 예측일</div><div class="as-value"><?php echo h(!empty($anomalyLatestReliability['forecast_date'])?$anomalyLatestReliability['forecast_date']:'-'); ?></div></div>
    <div class="as-card"><div class="as-label">최신 신뢰도 분석일</div><div class="as-value"><?php echo h(!empty($anomalyLatestReliability['reliability_date'])?$anomalyLatestReliability['reliability_date']:'-'); ?></div></div>
    <div class="as-card"><div class="as-label">대상 월</div><div class="as-value"><?php echo h(!empty($anomalyLatestReliability['target_ym'])?$anomalyLatestReliability['target_ym']:'-'); ?></div></div>
    <div class="as-card"><div class="as-label">탐지 실행 가능</div><div class="as-value" style="color:<?php echo $anomalyExecutable?'#047857':'#c2410c'; ?>"><?php echo $anomalyExecutable?'가능':'준비 필요'; ?></div></div>
    <div class="as-card"><div class="as-label">최근 실행 상태</div><div class="as-value"><?php echo h(isset($anomalyLatestRun['run_status'])?$anomalyLatestRun['run_status']:'-'); ?></div></div>
    <div class="as-card"><div class="as-label">분석 현장</div><div class="as-value"><?php echo h(number_format(isset($anomalyLatestRun['success_count'])?(int)$anomalyLatestRun['success_count']:0)); ?>개</div></div>
    <div class="as-card"><div class="as-label">정상 / 관심</div><div class="as-value"><span class="as-grade NORMAL"><?php echo h(number_format(isset($anomalyLatestRun['normal_count'])?(int)$anomalyLatestRun['normal_count']:0)); ?></span> / <span class="as-grade WATCH"><?php echo h(number_format(isset($anomalyLatestRun['watch_count'])?(int)$anomalyLatestRun['watch_count']:0)); ?></span></div></div>
    <div class="as-card"><div class="as-label">주의 / 긴급 확인</div><div class="as-value"><span class="as-grade WARNING"><?php echo h(number_format(isset($anomalyLatestRun['warning_count'])?(int)$anomalyLatestRun['warning_count']:0)); ?></span> / <span class="as-grade CRITICAL"><?php echo h(number_format(isset($anomalyLatestRun['critical_count'])?(int)$anomalyLatestRun['critical_count']:0)); ?></span></div></div>
    <div class="as-card"><div class="as-label">판단자료 부족</div><div class="as-value as-grade INSUFFICIENT"><?php echo h(number_format(isset($anomalyLatestRun['insufficient_count'])?(int)$anomalyLatestRun['insufficient_count']:0)); ?>개</div></div>
    <div class="as-card"><div class="as-label">발견된 이상징후</div><div class="as-value"><?php echo h(number_format(isset($anomalyLatestRun['detected_anomaly_count'])?(int)$anomalyLatestRun['detected_anomaly_count']:0)); ?>건</div></div>
    <div class="as-card"><div class="as-label">최근 실행일</div><div class="as-value" style="font-size:14px"><?php echo h(isset($anomalyLatestRun['started_at'])?$anomalyLatestRun['started_at']:'-'); ?></div></div>
    <div class="as-card"><div class="as-label">최근 실행 실패</div><div class="as-value"><?php echo h(number_format(isset($anomalyLatestRun['failure_count'])?(int)$anomalyLatestRun['failure_count']:0)); ?>건</div></div>
  </section>

  <section class="as-card as-section"><h3>설치 및 최신 신뢰도 기준 탐지</h3><div class="as-two"><div class="as-box"><h4>실행이력 구조</h4><div class="as-label">누락 컬럼 <?php echo h(number_format(count($anomalyStatus['run']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($anomalyStatus['run']['missing_indexes']))); ?>개</div></div><div class="as-box"><h4>결과 구조</h4><div class="as-label">누락 컬럼 <?php echo h(number_format(count($anomalyStatus['result']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($anomalyStatus['result']['missing_indexes']))); ?>개</div></div></div>
    <div class="as-actions">
      <form method="post" action="?r=admin%2Fai_anomaly_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="install"><button class="as-btn" type="submit">설치/확인</button></form>
      <form method="post" action="?r=admin%2Fai_anomaly_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="detect_latest"><button class="as-btn" type="submit"<?php echo !$anomalyExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>최신 신뢰도 기준 이상징후 탐지</button></form>
      <form method="post" action="?r=admin%2Fai_anomaly_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="detect_latest"><button class="as-btn secondary" type="submit"<?php echo !$anomalyExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>이상징후 다시 탐지</button></form>
      <a class="as-btn secondary" href="?r=admin%2Fai_anomaly_history">이상징후 결과 보기</a><a class="as-btn secondary" href="?r=admin%2Fai_profit_risk_setup">적자·원가율 위험 설정</a><a class="as-btn secondary" href="?r=admin%2Fai_reliability_setup">입력 신뢰도 설정</a><a class="as-btn secondary" href="?r=admin%2Fai_forecast_setup">기본 월말 예측 설정</a><a class="as-btn secondary" href="?r=admin%2Fai_snapshot_setup">일일 스냅샷 설정</a><a class="as-btn secondary" href="?r=admin%2Fai_data_audit">AI 데이터 준비상태 점검</a>
    </div>
    <?php if (empty($anomalyLatestReliability['available'])): ?><div class="as-note">이상징후를 탐지하려면 먼저 입력 신뢰도를 계산해주세요.</div><?php else: ?><div class="as-note">실행 순서: 1. 일일 스냅샷 생성 → 2. 월말 예측 실행 → 3. 입력 신뢰도 계산 → 4. 이상징후 탐지. GET 요청은 상태만 확인하며 설치나 탐지를 실행하지 않습니다.</div><?php endif; ?>
  </section>

  <section class="as-card as-section"><h3>탐지 기준 안내</h3><div class="as-two"><div class="as-box"><h4>비교 규칙</h4><div class="as-label">스냅샷 최신성, 장기 미입력, 예상항목 미입력, 총투입비·비용항목 증감, 예측 급변·범위 확대, 과거일자 집중입력, 반복 수정·삭제, 비용 구성 변화를 확인합니다.</div></div><div class="as-box"><h4>해석 주의</h4><div class="as-label">작은 금액은 절대금액 최소기준으로 제외합니다. 공정단계 변화, 엑셀 일괄입력, 정상 정산으로도 같은 변화가 나타날 수 있으며 직원 평가나 순위를 만들지 않습니다.</div></div></div></section>
  <section class="as-card as-section"><h3>독립 실행 안내</h3><p style="margin:0;color:#475569;font-size:13px;line-height:1.75;">자동 예약작업은 설정하지 않았습니다. 향후 예약 실행 시 <strong>일일 스냅샷 → 월말 예측 → 입력 신뢰도 → 이상징후 탐지</strong> 순서로 각 CLI 파일을 독립 실행할 수 있습니다.</p></section>
  <details class="as-card as-section"><summary style="cursor:pointer;font-weight:900">설치 예정 테이블 구조 보기</summary><pre class="as-code"><?php echo h(AiAnomalyDetectionService::createRunTableSql() . ";\n\n" . AiAnomalyDetectionService::createResultTableSql() . ';'); ?></pre></details>
</div>
