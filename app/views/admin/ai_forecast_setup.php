<?php
/**
 * 기본 월말 예상 투입비 설치 및 실행 화면.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiMonthlyForecastService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/AiMonthlyForecastService.php';

$forecastPdo = Db::pdo();
$forecastActionResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    if (!csrf_check($token)) {
        $forecastActionResult = array('ok'=>false,'message'=>'보안 토큰이 올바르지 않습니다.');
    } else if ($action === 'install') {
        $forecastActionResult = AiMonthlyForecastService::installOrUpdate($forecastPdo);
    } else if ($action === 'forecast_latest') {
        $forecastActionResult = AiMonthlyForecastService::forecastLatest($forecastPdo, 'MANUAL');
    } else {
        $forecastActionResult = array('ok'=>false,'message'=>'요청값이 올바르지 않습니다.');
    }
}

$forecastStatus = AiMonthlyForecastService::schemaStatus($forecastPdo);
$forecastLatestRun = isset($forecastStatus['latest_run']) && is_array($forecastStatus['latest_run']) ? $forecastStatus['latest_run'] : array();
$forecastLatestSnapshot = isset($forecastStatus['latest_snapshot']) && is_array($forecastStatus['latest_snapshot']) ? $forecastStatus['latest_snapshot'] : array();
$forecastExecutable = !empty($forecastStatus['installed']) && !empty($forecastLatestSnapshot['available']);
?>

<style>
  .forecast-setup{color:#0f172a}.forecast-setup *{box-sizing:border-box}
  .fs-hero,.fs-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05)}
  .fs-hero{padding:24px;background:linear-gradient(135deg,#fff 0%,#f0fdf4 100%)}.fs-hero h2{margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em}.fs-hero p{max-width:940px;margin:10px 0 0;color:#475569;font-size:14px;line-height:1.7}
  .fs-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-top:15px}.fs-card{padding:17px}.fs-label{color:#64748b;font-size:11px;font-weight:800}.fs-value{margin-top:8px;font-size:19px;font-weight:900;overflow-wrap:anywhere}
  .fs-message{margin-top:15px;padding:14px 16px;border-radius:13px;font-size:14px;font-weight:800;line-height:1.6}.fs-message.ok{border:1px solid #a7f3d0;background:#ecfdf5;color:#047857}.fs-message.error{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c}
  .fs-section{margin-top:15px;padding:20px}.fs-section h3{margin:0 0 14px;font-size:18px;font-weight:900}.fs-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:15px}.fs-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:0;border-radius:11px;background:#15803d;color:#fff;text-decoration:none;font-weight:900;cursor:pointer}.fs-btn.secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}
  .fs-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.fs-box{padding:15px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc}.fs-box h4{margin:0 0 8px;font-size:14px}.fs-note{margin-top:14px;padding:14px;border:1px solid #fde68a;border-radius:13px;background:#fffbeb;color:#92400e;font-size:13px;line-height:1.7}.fs-code{max-height:330px;overflow:auto;padding:15px;border-radius:12px;background:#0f172a;color:#dcfce7;font:12px/1.6 Consolas,monospace;white-space:pre}
  @media(max-width:1000px){.fs-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.fs-hero{padding:18px}.fs-grid,.fs-two{grid-template-columns:1fr}.fs-actions,.fs-actions form,.fs-actions .fs-btn{width:100%}}
</style>

<div class="forecast-setup">
  <section class="fs-hero"><h2>기본 월말 예측 설정</h2><p>가장 최근 일일 스냅샷과 동일 현장의 과거 완료월 스냅샷을 이용해 이번 달 최종 투입비를 PHP 통계 계산으로 추정합니다. 현재 결과는 참고용 기초 예측이며 GPT API, 입력 신뢰도, 담당자별 입력 지연은 사용하지 않습니다.</p></section>

  <?php if (is_array($forecastActionResult)): ?><div class="fs-message <?php echo !empty($forecastActionResult['ok'])?'ok':'error'; ?>"><?php echo h(isset($forecastActionResult['message'])?$forecastActionResult['message']:'처리 결과를 확인할 수 없습니다.'); ?><?php if (isset($forecastActionResult['status'])): ?><div style="margin-top:5px;font-weight:700;">상태 <?php echo h($forecastActionResult['status']); ?> · 대상 <?php echo h(number_format((int)$forecastActionResult['projects'])); ?>개 · 성공 <?php echo h(number_format((int)$forecastActionResult['success'])); ?>개 · 자료 부족 <?php echo h(number_format((int)$forecastActionResult['insufficient'])); ?>개 · 실패 <?php echo h(number_format((int)$forecastActionResult['failed'])); ?>개</div><?php endif; ?></div><?php endif; ?>

  <section class="fs-grid" aria-label="예측 상태">
    <div class="fs-card"><div class="fs-label">DB 연결</div><div class="fs-value"><?php echo !empty($forecastStatus['db_available'])?'확인':'확인 불가'; ?></div></div>
    <div class="fs-card"><div class="fs-label">실행이력 테이블</div><div class="fs-value"><?php echo !empty($forecastStatus['run']['installed'])?'설치 완료':(!empty($forecastStatus['run']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="fs-card"><div class="fs-label">예측 결과 테이블</div><div class="fs-value"><?php echo !empty($forecastStatus['forecast']['installed'])?'설치 완료':(!empty($forecastStatus['forecast']['table_exists'])?'구조 보완 필요':'미설치'); ?></div></div>
    <div class="fs-card"><div class="fs-label">최신 스냅샷</div><div class="fs-value"><?php echo h(!empty($forecastLatestSnapshot['snapshot_date'])?$forecastLatestSnapshot['snapshot_date']:'-'); ?></div></div>
    <div class="fs-card"><div class="fs-label">예측 대상 월</div><div class="fs-value"><?php echo h(!empty($forecastLatestSnapshot['target_ym'])?$forecastLatestSnapshot['target_ym']:'-'); ?></div></div>
    <div class="fs-card"><div class="fs-label">실행 가능</div><div class="fs-value" style="color:<?php echo $forecastExecutable?'#047857':'#c2410c'; ?>"><?php echo $forecastExecutable?'가능':'준비 필요'; ?></div></div>
    <div class="fs-card"><div class="fs-label">최근 실행 상태</div><div class="fs-value"><?php echo h(isset($forecastLatestRun['run_status'])?$forecastLatestRun['run_status']:'-'); ?></div></div>
    <div class="fs-card"><div class="fs-label">최근 실행일</div><div class="fs-value" style="font-size:14px"><?php echo h(isset($forecastLatestRun['started_at'])?$forecastLatestRun['started_at']:'-'); ?></div></div>
    <div class="fs-card"><div class="fs-label">예측 현장</div><div class="fs-value"><?php echo h(number_format(isset($forecastLatestRun['success_count'])?(int)$forecastLatestRun['success_count']:0)); ?>개</div></div>
    <div class="fs-card"><div class="fs-label">자료 부족 현장</div><div class="fs-value"><?php echo h(number_format(isset($forecastLatestRun['insufficient_count'])?(int)$forecastLatestRun['insufficient_count']:0)); ?>개</div></div>
    <div class="fs-card"><div class="fs-label">현재 투입비 합계</div><div class="fs-value" style="font-size:15px"><?php echo h(number_format(isset($forecastLatestRun['current_input_total'])?(float)$forecastLatestRun['current_input_total']:0)); ?>원</div></div>
    <div class="fs-card"><div class="fs-label">예상 투입비 합계</div><div class="fs-value" style="font-size:15px"><?php echo h(number_format(isset($forecastLatestRun['forecast_input_total'])?(float)$forecastLatestRun['forecast_input_total']:0)); ?>원</div></div>
    <div class="fs-card"><div class="fs-label">예상 하한 합계</div><div class="fs-value" style="font-size:15px"><?php echo h(number_format(isset($forecastLatestRun['forecast_low_total'])?(float)$forecastLatestRun['forecast_low_total']:0)); ?>원</div></div>
    <div class="fs-card"><div class="fs-label">예상 상한 합계</div><div class="fs-value" style="font-size:15px"><?php echo h(number_format(isset($forecastLatestRun['forecast_high_total'])?(float)$forecastLatestRun['forecast_high_total']:0)); ?>원</div></div>
  </section>

  <section class="fs-card fs-section"><h3>설치 및 최신 스냅샷 예측</h3><div class="fs-two"><div class="fs-box"><h4>실행이력 구조</h4><div class="fs-label">누락 컬럼 <?php echo h(number_format(count($forecastStatus['run']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($forecastStatus['run']['missing_indexes']))); ?>개</div></div><div class="fs-box"><h4>예측 결과 구조</h4><div class="fs-label">누락 컬럼 <?php echo h(number_format(count($forecastStatus['forecast']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($forecastStatus['forecast']['missing_indexes']))); ?>개</div></div></div>
    <div class="fs-actions">
      <form method="post" action="?r=admin%2Fai_forecast_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="install"><button class="fs-btn" type="submit">설치/확인</button></form>
      <form method="post" action="?r=admin%2Fai_forecast_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="forecast_latest"><button class="fs-btn" type="submit"<?php echo !$forecastExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>최신 스냅샷으로 예측 실행</button></form>
      <form method="post" action="?r=admin%2Fai_forecast_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="forecast_latest"><button class="fs-btn secondary" type="submit"<?php echo !$forecastExecutable?' disabled style="opacity:.45;cursor:not-allowed"':''; ?>>예측 다시 계산</button></form>
      <a class="fs-btn secondary" href="?r=admin%2Fai_forecast_history">예측 결과 보기</a><a class="fs-btn secondary" href="?r=admin%2Fai_profit_risk_setup">적자·원가율 위험 설정</a><a class="fs-btn secondary" href="?r=admin%2Fai_reliability_setup">입력 신뢰도 설정</a><a class="fs-btn secondary" href="?r=admin%2Fai_anomaly_setup">이상징후 탐지 설정</a><a class="fs-btn secondary" href="?r=admin%2Fai_snapshot_setup">일일 스냅샷 설정</a><a class="fs-btn secondary" href="?r=admin%2Fai_data_audit">AI 데이터 준비상태 점검</a>
    </div>
    <?php if (empty($forecastLatestSnapshot['available'])): ?><div class="fs-note">월말 예측을 실행하려면 먼저 오늘 스냅샷을 생성해주세요.</div><?php else: ?><div class="fs-note">실행 순서: 1. 일일 스냅샷 생성 → 2. 월말 예측 실행. 같은 날짜와 대상 월을 다시 계산하면 기존 결과를 최신 값으로 갱신합니다.</div><?php endif; ?>
  </section>

  <section class="fs-card fs-section"><h3>예측 기준</h3><div class="fs-two"><div class="fs-box"><h4>비용별 우선순위</h4><div class="fs-label">HISTORICAL_RATIO → RECENT_MEDIAN → LINEAR → INSUFFICIENT 순으로 비용 종류별 독립 적용합니다.</div></div><div class="fs-box"><h4>초기 운영 안내</h4><div class="fs-label">스냅샷이 3개월 미만이면 최근 중앙값·단순 진행률·자료 부족 방식이 주로 사용됩니다. 이는 오류가 아닙니다.</div></div></div></section>
  <details class="fs-card fs-section"><summary style="cursor:pointer;font-weight:900">설치 예정 테이블 구조 보기</summary><pre class="fs-code"><?php echo h(AiMonthlyForecastService::createRunTableSql() . ";\n\n" . AiMonthlyForecastService::createForecastTableSql() . ';'); ?></pre></details>
</div>
