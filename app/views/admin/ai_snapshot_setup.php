<?php
/**
 * 일일 스냅샷 설치 및 수동 실행 화면.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiDailySnapshotService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/AiDailySnapshotService.php';

$snapshotPdo = Db::pdo();
$snapshotActionResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    if (!csrf_check($token)) {
        $snapshotActionResult = array('ok'=>false, 'message'=>'보안 토큰이 올바르지 않습니다.');
    } else if ($action === 'install') {
        $snapshotActionResult = AiDailySnapshotService::installOrUpdate($snapshotPdo);
    } else if ($action === 'capture_today') {
        $snapshotActionResult = AiDailySnapshotService::captureToday($snapshotPdo, 'MANUAL');
    } else {
        $snapshotActionResult = array('ok'=>false, 'message'=>'요청값이 올바르지 않습니다.');
    }
}

$snapshotStatus = AiDailySnapshotService::schemaStatus($snapshotPdo);
$today = AiDailySnapshotService::businessToday();
$todaySnapshotCount = !empty($snapshotStatus['installed'])
    ? AiDailySnapshotService::countSnapshots($snapshotPdo, array('snapshot_date'=>$today))
    : 0;
$latestRun = isset($snapshotStatus['latest_run']) && is_array($snapshotStatus['latest_run']) ? $snapshotStatus['latest_run'] : array();
?>

<style>
  .snapshot-setup { color:#0f172a; }
  .snapshot-setup * { box-sizing:border-box; }
  .ss-hero,.ss-card { border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05); }
  .ss-hero { padding:24px;background:linear-gradient(135deg,#fff 0%,#eef2ff 100%); }
  .ss-hero h2 { margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em; }
  .ss-hero p { max-width:930px;margin:10px 0 0;color:#475569;font-size:14px;line-height:1.7; }
  .ss-grid { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-top:15px; }
  .ss-card { padding:17px; }
  .ss-label { color:#64748b;font-size:11px;font-weight:800; }
  .ss-value { margin-top:8px;font-size:20px;font-weight:900;overflow-wrap:anywhere; }
  .ss-section { margin-top:15px;padding:20px; }
  .ss-section h3 { margin:0 0 14px;font-size:18px;font-weight:900; }
  .ss-message { margin-top:15px;padding:14px 16px;border-radius:13px;font-size:14px;font-weight:800;line-height:1.6; }
  .ss-message.ok { border:1px solid #a7f3d0;background:#ecfdf5;color:#047857; }
  .ss-message.error { border:1px solid #fecaca;background:#fef2f2;color:#b91c1c; }
  .ss-two { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px; }
  .ss-box { padding:15px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc; }
  .ss-box h4 { margin:0 0 9px;font-size:14px;font-weight:900; }
  .ss-list { margin:0;padding-left:19px;color:#475569;font-size:13px;line-height:1.7; }
  .ss-actions { display:flex;flex-wrap:wrap;gap:9px;margin-top:15px; }
  .ss-btn { display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:0;border-radius:11px;background:#4f46e5;color:#fff;text-decoration:none;font-weight:900;cursor:pointer; }
  .ss-btn.secondary { border:1px solid #cbd5e1;background:#fff;color:#334155; }
  .ss-code { max-height:330px;overflow:auto;padding:15px;border-radius:12px;background:#0f172a;color:#dbeafe;font:12px/1.6 Consolas,monospace;white-space:pre; }
  .ss-note { margin-top:13px;padding:14px;border:1px solid #fde68a;border-radius:13px;background:#fffbeb;color:#92400e;font-size:13px;line-height:1.7; }
  @media (max-width:1000px) { .ss-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:640px) { .ss-hero { padding:18px; }.ss-hero h2 { font-size:23px; }.ss-grid,.ss-two { grid-template-columns:1fr; }.ss-actions,.ss-actions form { width:100%; }.ss-actions .ss-btn { width:100%; } }
</style>

<div class="snapshot-setup">
  <section class="ss-hero">
    <h2>일일 스냅샷 설정</h2>
    <p>현장별 월 투입비·누적 투입비·매출의 하루 종료 시점 상태를 스냅샷 전용 테이블에 저장합니다. 기존 비용자료와 계산식을 변경하지 않으며, 과거 날짜를 현재 자료로 임의 생성하지 않습니다.</p>
  </section>

  <?php if (is_array($snapshotActionResult)): ?>
    <div class="ss-message <?php echo !empty($snapshotActionResult['ok']) ? 'ok' : 'error'; ?>">
      <?php echo h(isset($snapshotActionResult['message']) ? $snapshotActionResult['message'] : '처리 결과를 확인할 수 없습니다.'); ?>
      <?php if (isset($snapshotActionResult['status'])): ?>
        <div style="margin-top:5px;font-weight:700;">상태 <?php echo h($snapshotActionResult['status']); ?> · 대상 <?php echo h(number_format(isset($snapshotActionResult['projects']) ? (int)$snapshotActionResult['projects'] : 0)); ?>개 · 성공 <?php echo h(number_format(isset($snapshotActionResult['success']) ? (int)$snapshotActionResult['success'] : 0)); ?>개 · 실패 <?php echo h(number_format(isset($snapshotActionResult['failed']) ? (int)$snapshotActionResult['failed'] : 0)); ?>개</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <section class="ss-grid" aria-label="스냅샷 상태">
    <div class="ss-card"><div class="ss-label">DB 연결</div><div class="ss-value"><?php echo !empty($snapshotStatus['db_available']) ? '확인' : '확인 불가'; ?></div></div>
    <div class="ss-card"><div class="ss-label">실행이력 테이블</div><div class="ss-value" style="color:<?php echo !empty($snapshotStatus['run']['installed']) ? '#047857' : '#c2410c'; ?>;"><?php echo !empty($snapshotStatus['run']['installed']) ? '설치 완료' : (!empty($snapshotStatus['run']['table_exists']) ? '구조 보완 필요' : '미설치'); ?></div></div>
    <div class="ss-card"><div class="ss-label">일일 스냅샷 테이블</div><div class="ss-value" style="color:<?php echo !empty($snapshotStatus['snapshot']['installed']) ? '#047857' : '#c2410c'; ?>;"><?php echo !empty($snapshotStatus['snapshot']['installed']) ? '설치 완료' : (!empty($snapshotStatus['snapshot']['table_exists']) ? '구조 보완 필요' : '미설치'); ?></div></div>
    <div class="ss-card"><div class="ss-label">오늘 저장 현장</div><div class="ss-value"><?php echo h(number_format($todaySnapshotCount)); ?>개</div></div>
    <div class="ss-card"><div class="ss-label">저장 날짜 수</div><div class="ss-value"><?php echo h(number_format(isset($snapshotStatus['snapshot_date_count']) ? (int)$snapshotStatus['snapshot_date_count'] : 0)); ?>일</div></div>
    <div class="ss-card"><div class="ss-label">저장 프로젝트 수</div><div class="ss-value"><?php echo h(number_format(isset($snapshotStatus['project_count']) ? (int)$snapshotStatus['project_count'] : 0)); ?>개</div></div>
    <div class="ss-card"><div class="ss-label">최근 실행 상태</div><div class="ss-value"><?php echo h(isset($latestRun['run_status']) ? $latestRun['run_status'] : '-'); ?></div></div>
    <div class="ss-card"><div class="ss-label">최근 실행일시</div><div class="ss-value" style="font-size:15px;"><?php echo h(isset($latestRun['started_at']) ? $latestRun['started_at'] : '-'); ?></div></div>
    <div class="ss-card"><div class="ss-label">최근 성공 현장</div><div class="ss-value"><?php echo h(number_format(isset($latestRun['success_count']) ? (int)$latestRun['success_count'] : 0)); ?>개</div></div>
    <div class="ss-card"><div class="ss-label">최근 실패 현장</div><div class="ss-value"><?php echo h(number_format(isset($latestRun['failure_count']) ? (int)$latestRun['failure_count'] : 0)); ?>개</div></div>
  </section>

  <section class="ss-card ss-section">
    <h3>설치 및 오늘 스냅샷 실행</h3>
    <div class="ss-two">
      <div class="ss-box"><h4>실행이력 구조</h4><div class="ss-label">누락 컬럼 <?php echo h(number_format(count($snapshotStatus['run']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($snapshotStatus['run']['missing_indexes']))); ?>개</div></div>
      <div class="ss-box"><h4>일일 스냅샷 구조</h4><div class="ss-label">누락 컬럼 <?php echo h(number_format(count($snapshotStatus['snapshot']['missing_columns']))); ?>개 · 누락 인덱스 <?php echo h(number_format(count($snapshotStatus['snapshot']['missing_indexes']))); ?>개</div></div>
    </div>
    <div class="ss-actions">
      <form method="post" action="?r=admin%2Fai_snapshot_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="install"><button class="ss-btn" type="submit">설치/확인</button></form>
      <form method="post" action="?r=admin%2Fai_snapshot_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="capture_today"><button class="ss-btn" type="submit"<?php echo empty($snapshotStatus['installed']) ? ' disabled style="opacity:.45;cursor:not-allowed;"' : ''; ?>>오늘 스냅샷 생성</button></form>
      <form method="post" action="?r=admin%2Fai_snapshot_setup"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="capture_today"><button class="ss-btn secondary" type="submit"<?php echo empty($snapshotStatus['installed']) ? ' disabled style="opacity:.45;cursor:not-allowed;"' : ''; ?>>오늘 스냅샷 다시 계산</button></form>
      <a class="ss-btn secondary" href="?r=admin%2Fai_snapshot_history">스냅샷 이력 보기</a>
      <a class="ss-btn secondary" href="?r=admin%2Fai_forecast_setup">기본 월말 예측 설정</a>
      <a class="ss-btn secondary" href="?r=admin%2Fai_reliability_setup">입력 신뢰도 설정</a>
      <a class="ss-btn secondary" href="?r=admin%2Fai_anomaly_setup">이상징후 탐지 설정</a>
      <a class="ss-btn secondary" href="?r=admin%2Fai_profit_risk_setup">적자·원가율 위험 설정</a>
      <a class="ss-btn secondary" href="?r=admin%2Fai_data_audit">AI 데이터 준비상태 점검</a>
    </div>
    <div class="ss-note">GET 요청은 상태만 확인하며 설치나 집계를 실행하지 않습니다. 같은 날짜를 다시 실행하면 새 행을 만들지 않고 최신 상태로 갱신하며 최초 저장시각은 유지합니다.</div>
  </section>

  <section class="ss-card ss-section">
    <h3>예약 실행 안내</h3>
    <p style="margin:0;color:#475569;font-size:13px;line-height:1.7;">서버 예약작업을 사용할 수 있는 경우 <strong>scripts/ai_daily_snapshot.php</strong>를 하루 한 번 실행하도록 설정할 수 있습니다. 권장 시각은 매일 23:50입니다. 이 화면은 서버 운영체제별 예약 명령을 자동 등록하지 않으며, 관리자 수동 버튼만으로도 사용할 수 있습니다.</p>
  </section>

  <details class="ss-card ss-section"><summary style="cursor:pointer;font-weight:900;">설치 예정 테이블 구조 보기</summary><pre class="ss-code"><?php echo h(AiDailySnapshotService::createRunTableSql() . ";\n\n" . AiDailySnapshotService::createSnapshotTableSql() . ';'); ?></pre></details>
</div>
