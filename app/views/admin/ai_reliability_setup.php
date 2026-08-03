<?php
/** 입력 신뢰도 설치 및 실행 화면. PHP 5.6 compatible. */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiInputReliabilityService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/AiInputReliabilityService.php';

$reliabilityPdo = null;
$reliabilityInitializationFailed = false;
$reliabilityStatus = array(
    'db_available'=>false,
    'run'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
    'result'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
    'installed'=>false,
    'latest_forecast'=>array('available'=>false,'forecast_date'=>'','target_ym'=>'','snapshot_date'=>'','project_count'=>0),
    'result_count'=>0,'project_count'=>0,'latest_analysis_date'=>'','last_calculated_at'=>'','latest_run'=>array()
);
try {
    $reliabilityPdo = Db::pdo();
} catch (Exception $e) {
    $reliabilityInitializationFailed = true;
    error_log('[AI Reliability Setup] db initialization failed');
}
$reliabilityActionResult = null;
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    if (!csrf_check($token)) {
        $reliabilityActionResult = array('ok'=>false,'message'=>'보안 토큰이 올바르지 않습니다.');
    } else if ($action === 'install') {
        $reliabilityActionResult = AiInputReliabilityService::installOrUpdate($reliabilityPdo);
    } else if ($action === 'calculate_latest') {
        $reliabilityActionResult = AiInputReliabilityService::calculateLatest($reliabilityPdo, 'MANUAL');
    } else {
        $reliabilityActionResult = array('ok'=>false,'message'=>'요청값이 올바르지 않습니다.');
    }
}

if (!$reliabilityInitializationFailed) {
    try {
        $loadedReliabilityStatus = AiInputReliabilityService::schemaStatus($reliabilityPdo);
        if (is_array($loadedReliabilityStatus)) {
            $reliabilityStatus = array_merge($reliabilityStatus, $loadedReliabilityStatus);
        } else {
            $reliabilityInitializationFailed = true;
        }
    } catch (Exception $e) {
        $reliabilityInitializationFailed = true;
        error_log('[AI Reliability Setup] status initialization failed');
    }
}
if (!isset($reliabilityStatus['run']) || !is_array($reliabilityStatus['run'])) $reliabilityStatus['run'] = array();
if (!isset($reliabilityStatus['result']) || !is_array($reliabilityStatus['result'])) $reliabilityStatus['result'] = array();
$reliabilityStatus['run'] = array_merge(array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()), $reliabilityStatus['run']);
$reliabilityStatus['result'] = array_merge(array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()), $reliabilityStatus['result']);
if (!is_array($reliabilityStatus['run']['missing_columns'])) $reliabilityStatus['run']['missing_columns'] = array();
if (!is_array($reliabilityStatus['run']['missing_indexes'])) $reliabilityStatus['run']['missing_indexes'] = array();
if (!is_array($reliabilityStatus['result']['missing_columns'])) $reliabilityStatus['result']['missing_columns'] = array();
if (!is_array($reliabilityStatus['result']['missing_indexes'])) $reliabilityStatus['result']['missing_indexes'] = array();

$reliabilityLatestRun = isset($reliabilityStatus['latest_run']) && is_array($reliabilityStatus['latest_run']) ? $reliabilityStatus['latest_run'] : array();
$reliabilityLatestForecast = isset($reliabilityStatus['latest_forecast']) && is_array($reliabilityStatus['latest_forecast']) ? $reliabilityStatus['latest_forecast'] : array();
$reliabilityExecutable = !empty($reliabilityStatus['installed']) && !empty($reliabilityLatestForecast['available']);
?>

<style>
  .rs-wrap{color:#0f172a}.rs-wrap *{box-sizing:border-box}.rs-hero,.rs-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}
  .rs-hero{padding:24px;background:linear-gradient(135deg,#fff 0%,#f5f3ff 100%)}.rs-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.rs-hero p{max-width:980px;margin:10px 0 0;color:#475569;font-size:14px;line-height:1.75}
  .rs-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-top:15px}.rs-card{padding:17px}.rs-label{color:#64748b;font-size:11px;font-weight:800}.rs-value{margin-top:8px;font-size:18px;font-weight:900;overflow-wrap:anywhere}.rs-message{margin-top:15px;padding:14px 16px;border-radius:13px;font-size:14px;font-weight:800;line-height:1.65}.rs-message.ok{border:1px solid #a7f3d0;background:#ecfdf5;color:#047857}.rs-message.error{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c}
  .rs-section{margin-top:15px;padding:20px}.rs-section h3{margin:0 0 14px;font-size:18px;font-weight:900}.rs-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:15px}.rs-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:0;border-radius:11px;background:#6d28d9;color:#fff;text-decoration:none;font-weight:900;cursor:pointer}.rs-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}.rs-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.rs-box{padding:15px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc}.rs-box h4{margin:0 0 8px;font-size:14px}.rs-note{margin-top:14px;padding:14px;border:1px solid #fde68a;border-radius:13px;background:#fffbeb;color:#92400e;font-size:13px;line-height:1.75}.rs-code{max-height:330px;overflow:auto;padding:15px;border-radius:12px;background:#0f172a;color:#ede9fe;font:12px/1.6 Consolas,monospace;white-space:pre}
  @media(max-width:1000px){.rs-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.rs-hero{padding:18px}.rs-grid,.rs-two{grid-template-columns:1fr}.rs-actions,.rs-actions form,.rs-actions .rs-btn{width:100%}}
</style>

<div class="rs-wrap">
  <section class="rs-hero"><h2>입력 신뢰도 설정</h2><p>저장된 최신 월말 예측, 해당 예측이 사용한 일일 스냅샷, 최근 90일 통합 비용 이벤트로 데이터의 충분성·최신성·입력 지연·예측 안정성을 계산합니다. <strong>입력 신뢰도는 확정금액이나 직원 평가점수가 아닙니다.</strong></p></section>

  <?php if ($reliabilityInitializationFailed): ?><div class="rs-message error">입력 신뢰도 상태를 불러오지 못했습니다.</div><?php elseif (empty($reliabilityStatus['db_available'])): ?><div class="rs-message error">DB 연결 상태를 확인할 수 없습니다.</div><?php elseif (empty($reliabilityStatus['installed'])): ?><div class="rs-note">입력 신뢰도 테이블이 아직 설치되지 않았습니다.</div><?php endif; ?>

  <?php if (is_array($reliabilityActionResult)): ?><div class="rs-message <?php echo !empty($reliabilityActionResult['ok'])?'ok':'error'; ?>"><?php echo h(isset($reliabilityActionResult['message'])?$reliabilityActionResult['message']:'처리 결과를 확인할 수 없습니다.'); ?><?php if (isset($reliabilityActionResult['status'])): ?><div style="margin-top:5px;font-weight:700;">상태 <?php echo h($reliabilityActionResult['status']); ?> · 대상 <?php echo h(number_format((int)$reliabilityActionResult['projects'])); ?>개 · 성공 <?php echo h(number_format((int)$reliabilityActionResult['success'])); ?>개 · 판단자료 부족 <?php echo h(number_format((int)$reliabilityActionResult['insufficient'])); ?>개 · 실패 <?php echo h(number_format((int)$reliabilityActionResult['failed'])); ?>개</div><?php endif; ?></div><?php endif; ?>

  <section class="rs-grid" aria-label="입력 신뢰도 상태">
    <div class="rs-card"><div class="rs-label">DB 연결</div><div class="rs-value"><?php echo !empty($reliabilityStatus['db_available'])?'확인':'확인 불가'; ?></div></div>
    <div class="rs-card"><div class="rs-label">실행이력 테이블</div><div class="rs-value"><?php echo !empty($reliabilityStatus['run']['installed'])?'설치 완료':(!empty($reliabilityStatus['run']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="rs-card"><div class="rs-label">신뢰도 결과 테이블</div><div class="rs-value"><?php echo !empty($reliabilityStatus['result']['installed'])?'설치 완료':(!empty($reliabilityStatus['result']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="rs-card"><div class="rs-label">최신 예측일</div><div class="rs-value"><?php echo h(!empty($reliabilityLatestForecast['forecast_date'])?$reliabilityLatestForecast['forecast_date']:'-'); ?></div></div>
    <div class="rs-card"><div class="rs-label">최신 스냅샷</div><div class="rs-value"><?php echo h(!empty($reliabilityLatestForecast['snapshot_date'])?$reliabilityLatestForecast['snapshot_date']:'-'); ?></div></div>
    <div class="rs-card"><div class="rs-label">대상 월</div><div class="rs-value"><?php echo h(!empty($reliabilityLatestForecast['target_ym'])?$reliabilityLatestForecast['target_ym']:'-'); ?></div></div>
    <div class="rs-card"><div class="rs-label">계산 가능</div><div class="rs-value" style="color:<?php echo $reliabilityExecutable?'#047857':'#c2410c'; ?>"><?php echo $reliabilityExecutable?'가능':'준비 필요'; ?></div></div>
    <div class="rs-card"><div class="rs-label">최근 실행 상태</div><div class="rs-value"><?php echo h(isset($reliabilityLatestRun['run_status'])?$reliabilityLatestRun['run_status']:'-'); ?></div></div>
    <div class="rs-card"><div class="rs-label">계산 현장</div><div class="rs-value"><?php echo h(number_format(isset($reliabilityLatestRun['success_count'])?(int)$reliabilityLatestRun['success_count']:0)); ?>개</div></div>
    <div class="rs-card"><div class="rs-label">평균 신뢰도</div><div class="rs-value"><?php echo isset($reliabilityLatestRun['average_score'])&&$reliabilityLatestRun['average_score']!==null?h(number_format((float)$reliabilityLatestRun['average_score'],1) . '점'):'-'; ?></div></div>
    <div class="rs-card"><div class="rs-label">높음 / 양호</div><div class="rs-value"><?php echo h(number_format(isset($reliabilityLatestRun['high_count'])?(int)$reliabilityLatestRun['high_count']:0)); ?> / <?php echo h(number_format(isset($reliabilityLatestRun['good_count'])?(int)$reliabilityLatestRun['good_count']:0)); ?></div></div>
    <div class="rs-card"><div class="rs-label">주의 / 낮음</div><div class="rs-value"><?php echo h(number_format(isset($reliabilityLatestRun['caution_count'])?(int)$reliabilityLatestRun['caution_count']:0)); ?> / <?php echo h(number_format(isset($reliabilityLatestRun['low_count'])?(int)$reliabilityLatestRun['low_count']:0)); ?></div></div>
    <div class="rs-card"><div class="rs-label">판단자료 부족</div><div class="rs-value"><?php echo h(number_format(isset($reliabilityLatestRun['insufficient_count'])?(int)$reliabilityLatestRun['insufficient_count']:0)); ?>개</div></div>
    <div class="rs-card"><div class="rs-label">최근 실행일</div><div class="rs-value" style="font-size:14px"><?php echo h(isset($reliabilityLatestRun['started_at'])?$reliabilityLatestRun['started_at']:'-'); ?></div></div>
  </section>

  <section class="rs-card rs-section"><h3>설치 및 최신 예측 기준 계산</h3><div class="rs-two"><div class="rs-box"><h4>실행이력 구조</h4><div class="rs-label">누락 컬럼 <?php echo h(number_format(count($reliabilityStatus['run']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($reliabilityStatus['run']['missing_indexes']))); ?>개</div></div><div class="rs-box"><h4>결과 구조</h4><div class="rs-label">누락 컬럼 <?php echo h(number_format(count($reliabilityStatus['result']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($reliabilityStatus['result']['missing_indexes']))); ?>개</div></div></div>
    <div class="rs-actions">
      <form method="post" action="?r=admin%2Fai_reliability_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="install"><button class="rs-btn" type="submit">설치/확인</button></form>
      <form method="post" action="?r=admin%2Fai_reliability_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="calculate_latest"><button class="rs-btn" type="submit"<?php echo !$reliabilityExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>최신 예측 기준 신뢰도 계산</button></form>
      <form method="post" action="?r=admin%2Fai_reliability_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="calculate_latest"><button class="rs-btn secondary" type="submit"<?php echo !$reliabilityExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>신뢰도 다시 계산</button></form>
      <a class="rs-btn secondary" href="?r=admin%2Fai_reliability_history">신뢰도 결과 보기</a><a class="rs-btn secondary" href="?r=admin%2Fai_forecast_setup">기본 월말 예측 설정</a><a class="rs-btn secondary" href="?r=admin%2Fai_snapshot_setup">일일 스냅샷 설정</a><a class="rs-btn secondary" href="?r=admin%2Fai_data_audit">AI 데이터 준비상태 점검</a>
    </div>
    <?php if (empty($reliabilityLatestForecast['available'])): ?><div class="rs-note">입력 신뢰도를 계산하려면 먼저 월말 예측을 실행해주세요.</div><?php else: ?><div class="rs-note">실행 순서: 1. 일일 스냅샷 생성 → 2. 월말 예측 실행 → 3. 입력 신뢰도 계산. GET 요청은 상태만 확인하며 설치나 계산을 실행하지 않습니다.</div><?php endif; ?>
  </section>

  <section class="rs-card rs-section"><h3>계산 기준</h3><div class="rs-two"><div class="rs-box"><h4>구성요소와 가중치</h4><div class="rs-label">입력항목 충족도 25%, 자료 최신성 20%, 과거 예측근거 20%, 입력 지연상태 20%, 예측 안정성 15%. 사용할 수 없는 항목은 0점 처리하지 않고 가중치를 다시 계산합니다.</div></div><div class="rs-box"><h4>초기 운영 안내</h4><div class="rs-label">입력자료가 쌓이는 초기 단계입니다. 입력 이력과 스냅샷이 쌓이면 신뢰도가 자동으로 정교해집니다. 처리자 통계는 절차 개선용이며 순위나 인사평가로 사용하지 않습니다.</div></div></div></section>
  <section class="rs-card rs-section"><h3>독립 실행 안내</h3><p style="margin:0;color:#475569;font-size:13px;line-height:1.75;">자동 예약작업은 설정하지 않았습니다. 향후 예약 실행 시 <strong>일일 스냅샷 → 월말 예측 → 입력 신뢰도</strong> 순서로 각각의 CLI 파일을 독립 실행할 수 있습니다.</p></section>
  <details class="rs-card rs-section"><summary style="cursor:pointer;font-weight:900">설치 예정 테이블 구조 보기</summary><pre class="rs-code"><?php echo h(AiInputReliabilityService::createRunTableSql() . ";\n\n" . AiInputReliabilityService::createResultTableSql() . ';'); ?></pre></details>
</div>
