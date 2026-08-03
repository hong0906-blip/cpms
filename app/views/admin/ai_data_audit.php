<?php
/**
 * 관리자용 AI 데이터 준비상태 읽기 전용 점검 화면.
 * PHP 5.6 호환.
 */

use App\Core\Auth;
use App\Core\Db;
use App\Services\AiDataAuditService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 font-bold text-red-700">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/AiDataAuditService.php';

$aiAuditService = new AiDataAuditService(Db::pdo());
$aiAudit = $aiAuditService->auditAll();
$aiAuditSections = isset($aiAudit['sections']) && is_array($aiAudit['sections']) ? $aiAudit['sections'] : array();

if (!function_exists('cpms_ai_audit_tone')) {
    function cpms_ai_audit_tone($status)
    {
        $tones = array(
            'excellent' => array('border' => '#a7f3d0', 'soft' => '#ecfdf5', 'text' => '#047857', 'solid' => '#059669'),
            'good' => array('border' => '#bfdbfe', 'soft' => '#eff6ff', 'text' => '#1d4ed8', 'solid' => '#2563eb'),
            'warning' => array('border' => '#fed7aa', 'soft' => '#fff7ed', 'text' => '#c2410c', 'solid' => '#ea580c'),
            'danger' => array('border' => '#fecaca', 'soft' => '#fef2f2', 'text' => '#b91c1c', 'solid' => '#dc2626'),
            'unavailable' => array('border' => '#d1d5db', 'soft' => '#f3f4f6', 'text' => '#4b5563', 'solid' => '#6b7280'),
        );
        return isset($tones[$status]) ? $tones[$status] : $tones['unavailable'];
    }
}

if (!function_exists('cpms_ai_audit_metric_tone')) {
    function cpms_ai_audit_metric_tone($judgement)
    {
        $judgement = trim((string)$judgement);
        if ($judgement === '양호' || $judgement === '준비 우수' || $judgement === '계절성 분석 가능') {
            return array('background' => '#ecfdf5', 'color' => '#047857', 'border' => '#a7f3d0');
        }
        if ($judgement === '확인' || $judgement === '준비 양호' || $judgement === '기본 예측 가능') {
            return array('background' => '#eff6ff', 'color' => '#1d4ed8', 'border' => '#bfdbfe');
        }
        if ($judgement === '보완 필요' || $judgement === '부분확보' || $judgement === '시범 예측 가능') {
            return array('background' => '#fff7ed', 'color' => '#c2410c', 'border' => '#fed7aa');
        }
        if ($judgement === '부족' || $judgement === '준비 부족' || $judgement === '학습자료 부족') {
            return array('background' => '#fef2f2', 'color' => '#b91c1c', 'border' => '#fecaca');
        }
        return array('background' => '#f3f4f6', 'color' => '#4b5563', 'border' => '#d1d5db');
    }
}

$overallStatus = isset($aiAudit['overall_status']) ? (string)$aiAudit['overall_status'] : 'unavailable';
$overallTone = cpms_ai_audit_tone($overallStatus);
$overallScore = isset($aiAudit['overall_score']) && $aiAudit['overall_score'] !== null ? (int)$aiAudit['overall_score'] : null;
$overallGrade = isset($aiAudit['overall_grade']) ? (string)$aiAudit['overall_grade'] : '확인 불가';
$minimumMonths = isset($aiAudit['minimum_learning_months']) ? (int)$aiAudit['minimum_learning_months'] : 0;
$globalWarnings = isset($aiAudit['global_warnings']) && is_array($aiAudit['global_warnings']) ? $aiAudit['global_warnings'] : array();
$globalRecommendations = isset($aiAudit['global_recommendations']) && is_array($aiAudit['global_recommendations']) ? $aiAudit['global_recommendations'] : array();
$dailySnapshot = isset($aiAudit['daily_snapshot']) && is_array($aiAudit['daily_snapshot']) ? $aiAudit['daily_snapshot'] : array();
$monthlyForecast = isset($aiAudit['monthly_forecast']) && is_array($aiAudit['monthly_forecast']) ? $aiAudit['monthly_forecast'] : array();
$inputReliability = isset($aiAudit['input_reliability']) && is_array($aiAudit['input_reliability']) ? $aiAudit['input_reliability'] : array();
$anomalyDetection = isset($aiAudit['anomaly_detection']) && is_array($aiAudit['anomaly_detection']) ? $aiAudit['anomaly_detection'] : array();
$profitRisk = isset($aiAudit['profit_risk']) && is_array($aiAudit['profit_risk']) ? $aiAudit['profit_risk'] : array();
$openAiExecutiveBrief = isset($aiAudit['openai_executive_brief']) && is_array($aiAudit['openai_executive_brief']) ? $aiAudit['openai_executive_brief'] : array();
?>

<style>
  .ai-audit-page { color:#0f172a; }
  .ai-audit-page * { box-sizing:border-box; }
  .ai-audit-hero { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; padding:24px; border:1px solid #dbeafe; border-radius:22px; background:linear-gradient(135deg,#ffffff 0%,#eff6ff 58%,#ecfeff 100%); box-shadow:0 14px 36px rgba(15,23,42,.07); }
  .ai-audit-hero h2 { margin:0; font-size:28px; line-height:1.25; font-weight:900; letter-spacing:-.03em; }
  .ai-audit-hero p { max-width:850px; margin:10px 0 0; color:#475569; font-size:14px; line-height:1.7; }
  .ai-audit-refresh { flex:0 0 auto; display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:10px 16px; border-radius:12px; background:#0f172a; color:#fff; text-decoration:none; font-weight:800; box-shadow:0 8px 18px rgba(15,23,42,.16); }
  .ai-audit-summary { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; margin-top:16px; }
  .ai-audit-summary-card, .ai-audit-panel, .ai-audit-section { border:1px solid #e2e8f0; border-radius:18px; background:#fff; box-shadow:0 8px 26px rgba(15,23,42,.05); }
  .ai-audit-summary-card { min-height:122px; padding:18px; }
  .ai-audit-summary-card .label { color:#64748b; font-size:12px; font-weight:800; }
  .ai-audit-summary-card .value { margin-top:9px; font-size:23px; line-height:1.25; font-weight:900; letter-spacing:-.03em; }
  .ai-audit-summary-card .sub { margin-top:7px; color:#64748b; font-size:12px; line-height:1.45; }
  .ai-audit-alert-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-top:16px; }
  .ai-audit-panel { padding:18px; }
  .ai-audit-panel h3 { margin:0; font-size:16px; font-weight:900; }
  .ai-audit-panel ul { margin:12px 0 0; padding-left:20px; color:#475569; font-size:13px; line-height:1.7; }
  .ai-audit-panel li + li { margin-top:4px; }
  .ai-audit-empty { margin-top:12px; color:#64748b; font-size:13px; }
  .ai-audit-section-list { display:grid; gap:16px; margin-top:16px; }
  .ai-audit-section { overflow:hidden; }
  .ai-audit-section-head { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; padding:20px; border-bottom:1px solid #e2e8f0; }
  .ai-audit-section-title { display:flex; align-items:center; flex-wrap:wrap; gap:10px; }
  .ai-audit-section-title h3 { margin:0; font-size:21px; font-weight:900; }
  .ai-audit-badge { display:inline-flex; align-items:center; min-height:28px; padding:5px 10px; border:1px solid; border-radius:999px; font-size:12px; font-weight:900; }
  .ai-audit-score { flex:0 0 auto; text-align:right; }
  .ai-audit-score strong { display:block; font-size:28px; line-height:1; font-weight:900; }
  .ai-audit-score span { display:block; margin-top:6px; font-size:12px; font-weight:800; }
  .ai-audit-facts { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
  .ai-audit-fact { min-width:0; }
  .ai-audit-fact span { display:block; color:#64748b; font-size:11px; font-weight:800; }
  .ai-audit-fact strong { display:block; overflow-wrap:anywhere; margin-top:5px; color:#1e293b; font-size:14px; font-weight:900; }
  .ai-audit-table-wrap { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .ai-audit-table { width:100%; min-width:820px; border-collapse:collapse; font-size:13px; }
  .ai-audit-table th { padding:12px 14px; background:#f8fafc; color:#475569; text-align:left; font-size:12px; font-weight:900; white-space:nowrap; }
  .ai-audit-table td { padding:13px 14px; border-top:1px solid #eef2f7; vertical-align:top; color:#334155; }
  .ai-audit-table td:first-child { color:#0f172a; font-weight:900; white-space:nowrap; }
  .ai-audit-table td:nth-child(3), .ai-audit-table td:nth-child(4) { white-space:nowrap; }
  .ai-audit-table .desc { min-width:300px; color:#64748b; line-height:1.55; }
  .ai-audit-detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; padding:16px 20px 20px; border-top:1px solid #eef2f7; }
  .ai-audit-detail { padding:14px; border:1px solid #e2e8f0; border-radius:14px; background:#fff; }
  .ai-audit-detail h4 { margin:0; font-size:13px; font-weight:900; }
  .ai-audit-detail ul { margin:9px 0 0; padding-left:18px; color:#475569; font-size:12px; line-height:1.65; }
  .ai-audit-highlight { margin:0 20px 16px; padding:13px 15px; border:1px solid #a7f3d0; border-radius:14px; background:#ecfdf5; color:#047857; font-size:13px; font-weight:800; line-height:1.55; }
  .ai-audit-history-link { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 20px 16px; padding:13px 15px; border:1px solid #bfdbfe; border-radius:14px; background:#eff6ff; color:#1e40af; font-size:13px; font-weight:800; line-height:1.55; }
  .ai-audit-history-link a { flex:0 0 auto; display:inline-flex; padding:8px 12px; border-radius:9px; background:#1d4ed8; color:#fff; text-decoration:none; font-weight:900; }
  .ai-audit-snapshot-panel { margin-top:16px;padding:20px;border:1px solid #a5f3fc;border-radius:18px;background:linear-gradient(135deg,#fff 0%,#ecfeff 100%);box-shadow:0 8px 26px rgba(15,23,42,.05); }
  .ai-audit-snapshot-head { display:flex;align-items:flex-start;justify-content:space-between;gap:14px; }
  .ai-audit-snapshot-head h3 { margin:0;font-size:18px;font-weight:900; }
  .ai-audit-snapshot-head p { margin:7px 0 0;color:#475569;font-size:13px;line-height:1.6; }
  .ai-audit-snapshot-link { display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 13px;border-radius:10px;background:#0e7490;color:#fff;text-decoration:none;font-weight:900;white-space:nowrap; }
  .ai-audit-snapshot-grid { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-top:14px; }
  .ai-audit-snapshot-item { padding:13px;border:1px solid #cffafe;border-radius:12px;background:#fff; }
  .ai-audit-snapshot-item span { display:block;color:#64748b;font-size:11px;font-weight:800; }
  .ai-audit-snapshot-item strong { display:block;margin-top:6px;font-size:14px;font-weight:900;overflow-wrap:anywhere; }
  .ai-audit-criteria { margin-top:16px; padding:20px; border:1px solid #cbd5e1; border-radius:18px; background:#fff; }
  .ai-audit-criteria h3 { margin:0; font-size:18px; font-weight:900; }
  .ai-audit-criteria-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-top:14px; }
  .ai-audit-criteria-item { padding:15px; border-radius:14px; background:#f8fafc; color:#475569; font-size:13px; line-height:1.65; }
  .ai-audit-criteria-item strong { display:block; margin-bottom:6px; color:#0f172a; }
  @media (max-width:1100px) { .ai-audit-summary { grid-template-columns:repeat(3,minmax(0,1fr)); } .ai-audit-facts { grid-template-columns:repeat(3,minmax(0,1fr)); }.ai-audit-snapshot-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:767px) {
    .ai-audit-hero { flex-direction:column; padding:18px; border-radius:18px; }
    .ai-audit-hero h2 { font-size:23px; }
    .ai-audit-refresh { width:100%; }
    .ai-audit-summary { grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px; }
    .ai-audit-summary-card { min-height:105px; padding:14px; border-radius:15px; }
    .ai-audit-summary-card:first-child { grid-column:span 2; }
    .ai-audit-alert-grid, .ai-audit-detail-grid, .ai-audit-criteria-grid { grid-template-columns:1fr; }
    .ai-audit-section-head { padding:16px; }
    .ai-audit-score strong { font-size:24px; }
    .ai-audit-facts { grid-template-columns:repeat(2,minmax(0,1fr)); padding:14px 16px; }
    .ai-audit-detail-grid { padding:14px 16px 16px; }
    .ai-audit-highlight, .ai-audit-history-link { margin:0 16px 14px; }
    .ai-audit-history-link { align-items:stretch; flex-direction:column; }
    .ai-audit-snapshot-head { flex-direction:column; }.ai-audit-snapshot-link { width:100%; }.ai-audit-snapshot-grid { grid-template-columns:1fr; }
  }
</style>

<div class="ai-audit-page">
  <section class="ai-audit-hero">
    <div>
      <h2>AI 데이터 준비상태 점검</h2>
      <p>이 화면은 향후 월말 투입비, 입력 신뢰도, 이상징후 및 적자 가능성 예측을 위해 현재 CPMS 데이터의 준비 상태를 확인합니다. 데이터를 수정하지 않고 읽기만 합니다.</p>
    </div>
    <a class="ai-audit-refresh" href="?r=admin%2Fai_data_audit">다시 점검</a>
  </section>

  <section class="ai-audit-summary" aria-label="점검 요약">
    <div class="ai-audit-summary-card" style="border-color:<?php echo h($overallTone['border']); ?>;background:<?php echo h($overallTone['soft']); ?>;">
      <div class="label">전체 준비 점수</div>
      <div class="value" style="color:<?php echo h($overallTone['text']); ?>;"><?php echo $overallScore === null ? '-' : h((string)$overallScore); ?><span style="font-size:14px;"> / 100점</span></div>
      <div class="sub">확인 가능한 영역의 가중치를 재계산한 결과</div>
    </div>
    <div class="ai-audit-summary-card">
      <div class="label">준비 등급</div>
      <div class="value" style="color:<?php echo h($overallTone['text']); ?>;"><?php echo h($overallGrade); ?></div>
      <div class="sub">점수는 AI 성능이 아니라 기록 준비도를 뜻합니다.</div>
    </div>
    <div class="ai-audit-summary-card">
      <div class="label">확인한 날짜·시간</div>
      <div class="value" style="font-size:18px;"><?php echo h(isset($aiAudit['checked_at']) ? $aiAudit['checked_at'] : '-'); ?></div>
      <div class="sub">다시 점검하면 현재 DB를 새로 집계합니다.</div>
    </div>
    <div class="ai-audit-summary-card">
      <div class="label">학습 가능 최소 기간</div>
      <div class="value"><?php echo h(number_format($minimumMonths)); ?>개월</div>
      <div class="sub"><?php echo h(isset($aiAudit['minimum_learning_judgement']) ? $aiAudit['minimum_learning_judgement'] : '데이터 없음'); ?></div>
    </div>
    <div class="ai-audit-summary-card">
      <div class="label">확인 불가 항목</div>
      <div class="value"><?php echo h(number_format(isset($aiAudit['unavailable_count']) ? (int)$aiAudit['unavailable_count'] : 0)); ?>개 영역</div>
      <div class="sub">확인 불가 영역은 전체 점수 가중치에서 제외됩니다.</div>
    </div>
  </section>

  <section class="ai-audit-alert-grid">
    <div class="ai-audit-panel" style="border-color:#fed7aa;background:#fffaf5;">
      <h3 style="color:#c2410c;">주요 경고</h3>
      <?php if (count($globalWarnings) === 0): ?>
        <div class="ai-audit-empty">현재 집계에서 주요 경고가 확인되지 않았습니다.</div>
      <?php else: ?>
        <ul><?php foreach ($globalWarnings as $warning): ?><li><?php echo h($warning); ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <div class="ai-audit-panel" style="border-color:#bfdbfe;background:#f8fbff;">
      <h3 style="color:#1d4ed8;">우선 보완사항</h3>
      <?php if (count($globalRecommendations) === 0): ?>
        <div class="ai-audit-empty">추가 권장사항이 없습니다.</div>
      <?php else: ?>
        <ul><?php foreach ($globalRecommendations as $recommendation): ?><li><?php echo h($recommendation); ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  </section>

  <section class="ai-audit-snapshot-panel">
    <div class="ai-audit-snapshot-head"><div><h3>현장별 일일 투입현황 스냅샷</h3><p><?php echo h(isset($dailySnapshot['message']) && $dailySnapshot['message'] !== '' ? $dailySnapshot['message'] : '일일 스냅샷 상태를 확인할 수 없습니다.'); ?> 이 항목은 초기 설치 직후 기존 AI 준비점수를 크게 바꾸지 않도록 점수 계산에는 아직 반영하지 않습니다.</p></div><a class="ai-audit-snapshot-link" href="?r=admin%2Fai_snapshot_setup">일일 스냅샷 설정</a></div>
    <div class="ai-audit-snapshot-grid">
      <div class="ai-audit-snapshot-item"><span>실행이력 테이블</span><strong><?php echo !empty($dailySnapshot['run_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>일일 스냅샷 테이블</span><strong><?php echo !empty($dailySnapshot['snapshot_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>저장된 날짜</span><strong><?php echo h(number_format(isset($dailySnapshot['snapshot_date_count']) ? (int)$dailySnapshot['snapshot_date_count'] : 0)); ?>일</strong></div>
      <div class="ai-audit-snapshot-item"><span>저장된 프로젝트</span><strong><?php echo h(number_format(isset($dailySnapshot['project_count']) ? (int)$dailySnapshot['project_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>첫 스냅샷</span><strong><?php echo h(isset($dailySnapshot['first_snapshot_date']) && $dailySnapshot['first_snapshot_date'] !== '' ? $dailySnapshot['first_snapshot_date'] : '-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 스냅샷</span><strong><?php echo h(isset($dailySnapshot['latest_snapshot_date']) && $dailySnapshot['latest_snapshot_date'] !== '' ? $dailySnapshot['latest_snapshot_date'] : '-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 실행 상태</span><strong><?php echo h(isset($dailySnapshot['latest_run_status']) && $dailySnapshot['latest_run_status'] !== '' ? $dailySnapshot['latest_run_status'] : '-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 실행 실패</span><strong><?php echo h(number_format(isset($dailySnapshot['latest_run_failure_count']) ? (int)$dailySnapshot['latest_run_failure_count'] : 0)); ?>건</strong></div>
    </div>
  </section>

  <section class="ai-audit-snapshot-panel" style="border-color:#bfdbfe;background:linear-gradient(135deg,#fff 0%,#eff6ff 100%);">
    <div class="ai-audit-snapshot-head"><div><h3>기본 월말 예상 투입비</h3><p><?php echo h(isset($monthlyForecast['message']) && $monthlyForecast['message'] !== '' ? $monthlyForecast['message'] : '기본 월말 예측 상태를 확인할 수 없습니다.'); ?> 이 항목은 초기 운영 중 기존 AI 준비점수를 크게 바꾸지 않도록 점수 계산에는 아직 반영하지 않습니다.</p></div><a class="ai-audit-snapshot-link" style="background:#1d4ed8;" href="?r=admin%2Fai_forecast_setup">기본 월말 예측 설정</a></div>
    <div class="ai-audit-snapshot-grid">
      <div class="ai-audit-snapshot-item"><span>실행이력 테이블</span><strong><?php echo !empty($monthlyForecast['run_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>예측 결과 테이블</span><strong><?php echo !empty($monthlyForecast['forecast_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>전체 예측 결과</span><strong><?php echo h(number_format(isset($monthlyForecast['result_count']) ? (int)$monthlyForecast['result_count'] : 0)); ?>건</strong></div>
      <div class="ai-audit-snapshot-item"><span>예측된 프로젝트</span><strong><?php echo h(number_format(isset($monthlyForecast['project_count']) ? (int)$monthlyForecast['project_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 예측일</span><strong><?php echo h(isset($monthlyForecast['latest_forecast_date']) && $monthlyForecast['latest_forecast_date'] !== '' ? $monthlyForecast['latest_forecast_date'] : '-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 실행 상태</span><strong><?php echo h(isset($monthlyForecast['latest_run_status']) && $monthlyForecast['latest_run_status'] !== '' ? $monthlyForecast['latest_run_status'] : '-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 자료 부족 현장</span><strong><?php echo h(number_format(isset($monthlyForecast['latest_run_insufficient_count']) ? (int)$monthlyForecast['latest_run_insufficient_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>점수 반영</span><strong>현재 미반영</strong></div>
    </div>
  </section>

  <section class="ai-audit-snapshot-panel" style="border-color:#ddd6fe;background:linear-gradient(135deg,#fff 0%,#f5f3ff 100%);">
    <div class="ai-audit-snapshot-head"><div><h3>투입비 입력 신뢰도 및 입력 지연 분석</h3><p><?php echo h(isset($inputReliability['message']) && $inputReliability['message'] !== '' ? $inputReliability['message'] : '입력 신뢰도 분석 상태를 확인할 수 없습니다.'); ?> 이 항목은 기존 AI 준비점수에는 아직 반영하지 않습니다.</p></div><a class="ai-audit-snapshot-link" style="background:#7c3aed;" href="?r=admin%2Fai_reliability_setup">입력 신뢰도 설정</a></div>
    <div class="ai-audit-snapshot-grid">
      <div class="ai-audit-snapshot-item"><span>실행이력 테이블</span><strong><?php echo !empty($inputReliability['run_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>신뢰도 결과 테이블</span><strong><?php echo !empty($inputReliability['result_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>전체 분석 결과</span><strong><?php echo h(number_format(isset($inputReliability['result_count']) ? (int)$inputReliability['result_count'] : 0)); ?>건</strong></div>
      <div class="ai-audit-snapshot-item"><span>분석된 프로젝트</span><strong><?php echo h(number_format(isset($inputReliability['project_count']) ? (int)$inputReliability['project_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 분석일</span><strong><?php echo h(isset($inputReliability['latest_analysis_date']) && $inputReliability['latest_analysis_date'] !== '' ? $inputReliability['latest_analysis_date'] : '-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>평균 신뢰도</span><strong><?php echo isset($inputReliability['average_score']) && $inputReliability['average_score'] !== null ? h(number_format((float)$inputReliability['average_score'], 1) . '점') : '-'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>자료 부족 현장</span><strong><?php echo h(number_format(isset($inputReliability['insufficient_count']) ? (int)$inputReliability['insufficient_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 실행 상태 / 실패</span><strong><?php echo h(isset($inputReliability['latest_run_status']) && $inputReliability['latest_run_status'] !== '' ? $inputReliability['latest_run_status'] : '-'); ?> / <?php echo h(number_format(isset($inputReliability['latest_run_failure_count']) ? (int)$inputReliability['latest_run_failure_count'] : 0)); ?>건</strong></div>
    </div>
  </section>

  <section class="ai-audit-snapshot-panel" style="border-color:#fed7aa;background:linear-gradient(135deg,#fff 0%,#fff7ed 100%);">
    <div class="ai-audit-snapshot-head"><div><h3>현장별 비용 이상징후 탐지</h3><p><?php echo h(isset($anomalyDetection['message']) && $anomalyDetection['message'] !== '' ? $anomalyDetection['message'] : '이상징후 탐지 상태를 확인할 수 없습니다.'); ?> 이 항목은 기존 AI 준비점수에는 아직 반영하지 않습니다.</p></div><a class="ai-audit-snapshot-link" style="background:#c2410c;" href="?r=admin%2Fai_anomaly_setup">이상징후 탐지 설정</a></div>
    <div class="ai-audit-snapshot-grid">
      <div class="ai-audit-snapshot-item"><span>실행이력 테이블</span><strong><?php echo !empty($anomalyDetection['run_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>탐지 결과 테이블</span><strong><?php echo !empty($anomalyDetection['result_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>전체 탐지 결과</span><strong><?php echo h(number_format(isset($anomalyDetection['result_count']) ? (int)$anomalyDetection['result_count'] : 0)); ?>건</strong></div>
      <div class="ai-audit-snapshot-item"><span>분석된 프로젝트</span><strong><?php echo h(number_format(isset($anomalyDetection['project_count']) ? (int)$anomalyDetection['project_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 분석일</span><strong><?php echo h(isset($anomalyDetection['latest_analysis_date']) && $anomalyDetection['latest_analysis_date'] !== '' ? $anomalyDetection['latest_analysis_date'] : '-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>정상 / 관심</span><strong><?php echo h(number_format(isset($anomalyDetection['normal_count']) ? (int)$anomalyDetection['normal_count'] : 0)); ?> / <?php echo h(number_format(isset($anomalyDetection['watch_count']) ? (int)$anomalyDetection['watch_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>주의 / 긴급 확인</span><strong><?php echo h(number_format(isset($anomalyDetection['warning_count']) ? (int)$anomalyDetection['warning_count'] : 0)); ?> / <?php echo h(number_format(isset($anomalyDetection['critical_count']) ? (int)$anomalyDetection['critical_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>판단자료 부족</span><strong><?php echo h(number_format(isset($anomalyDetection['insufficient_count']) ? (int)$anomalyDetection['insufficient_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 실행 상태 / 실패</span><strong><?php echo h(isset($anomalyDetection['latest_run_status']) && $anomalyDetection['latest_run_status'] !== '' ? $anomalyDetection['latest_run_status'] : '-'); ?> / <?php echo h(number_format(isset($anomalyDetection['latest_run_failure_count']) ? (int)$anomalyDetection['latest_run_failure_count'] : 0)); ?>건</strong></div>
    </div>
  </section>

  <section class="ai-audit-snapshot-panel" style="border-color:#fecaca;background:linear-gradient(135deg,#fff 0%,#fef2f2 100%);">
    <div class="ai-audit-snapshot-head"><div><h3>현장별 적자·원가율 위험 분석</h3><p><?php echo h(isset($profitRisk['message']) && $profitRisk['message'] !== '' ? $profitRisk['message'] : '적자·원가율 위험분석 상태를 확인할 수 없습니다.'); ?> 이 항목은 기존 AI 준비점수에는 아직 반영하지 않습니다.</p></div><a class="ai-audit-snapshot-link" style="background:#b91c1c;" href="?r=admin%2Fai_profit_risk_setup">적자·원가율 위험 설정</a></div>
    <div class="ai-audit-snapshot-grid">
      <div class="ai-audit-snapshot-item"><span>실행이력 테이블</span><strong><?php echo !empty($profitRisk['run_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>위험분석 결과 테이블</span><strong><?php echo !empty($profitRisk['result_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>전체 분석 결과</span><strong><?php echo h(number_format(isset($profitRisk['result_count']) ? (int)$profitRisk['result_count'] : 0)); ?>건</strong></div>
      <div class="ai-audit-snapshot-item"><span>분석된 프로젝트</span><strong><?php echo h(number_format(isset($profitRisk['project_count']) ? (int)$profitRisk['project_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 분석일</span><strong><?php echo h(isset($profitRisk['latest_analysis_date']) && $profitRisk['latest_analysis_date'] !== '' ? $profitRisk['latest_analysis_date'] : '-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>정상 / 관심</span><strong><?php echo h(number_format(isset($profitRisk['normal_count']) ? (int)$profitRisk['normal_count'] : 0)); ?> / <?php echo h(number_format(isset($profitRisk['watch_count']) ? (int)$profitRisk['watch_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>주의 / 적자 위험</span><strong><?php echo h(number_format(isset($profitRisk['warning_count']) ? (int)$profitRisk['warning_count'] : 0)); ?> / <?php echo h(number_format(isset($profitRisk['critical_count']) ? (int)$profitRisk['critical_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>판단자료 부족</span><strong><?php echo h(number_format(isset($profitRisk['insufficient_count']) ? (int)$profitRisk['insufficient_count'] : 0)); ?>개</strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 실행 상태 / 실패</span><strong><?php echo h(isset($profitRisk['latest_run_status']) && $profitRisk['latest_run_status'] !== '' ? $profitRisk['latest_run_status'] : '-'); ?> / <?php echo h(number_format(isset($profitRisk['latest_run_failure_count']) ? (int)$profitRisk['latest_run_failure_count'] : 0)); ?>건</strong></div>
    </div>
  </section>

  <section class="ai-audit-snapshot-panel" style="border-color:#c7d2fe;background:linear-gradient(135deg,#fff 0%,#eef2ff 100%);">
    <div class="ai-audit-snapshot-head"><div><h3>OpenAI 대표용 경영 브리핑</h3><p><?php echo h(isset($openAiExecutiveBrief['message']) && $openAiExecutiveBrief['message'] !== '' ? $openAiExecutiveBrief['message'] : 'OpenAI 경영 브리핑 상태를 확인할 수 없습니다.'); ?> 이 항목은 기존 AI 준비점수에는 반영하지 않습니다.</p></div><a class="ai-audit-snapshot-link" style="background:#4338ca;" href="?r=admin%2Fai_openai_setup">OpenAI 연결 설정</a></div>
    <div class="ai-audit-snapshot-grid">
      <div class="ai-audit-snapshot-item"><span>PHP cURL</span><strong><?php echo !empty($openAiExecutiveBrief['curl_available']) ? '사용 가능' : '확인 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>OpenAI API 키</span><strong><?php echo !empty($openAiExecutiveBrief['api_key_configured']) ? '설정됨' : '미설정'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>GPT 실행이력 테이블</span><strong><?php echo !empty($openAiExecutiveBrief['run_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>경영 브리핑 테이블</span><strong><?php echo !empty($openAiExecutiveBrief['brief_table_installed']) ? '설치 완료' : '미설치 또는 보완 필요'; ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>완료 / 실패 / 캐시</span><strong><?php echo h(number_format(isset($openAiExecutiveBrief['completed_count'])?(int)$openAiExecutiveBrief['completed_count']:0)); ?> / <?php echo h(number_format(isset($openAiExecutiveBrief['failed_count'])?(int)$openAiExecutiveBrief['failed_count']:0)); ?> / <?php echo h(number_format(isset($openAiExecutiveBrief['cached_count'])?(int)$openAiExecutiveBrief['cached_count']:0)); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 GPT 실행</span><strong><?php echo h(isset($openAiExecutiveBrief['latest_run_date'])&&$openAiExecutiveBrief['latest_run_date']!==''?$openAiExecutiveBrief['latest_run_date']:'-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>최근 상태 / 모델</span><strong><?php echo h(isset($openAiExecutiveBrief['latest_run_status'])&&$openAiExecutiveBrief['latest_run_status']!==''?$openAiExecutiveBrief['latest_run_status']:'-'); ?> / <?php echo h(isset($openAiExecutiveBrief['latest_model'])&&$openAiExecutiveBrief['latest_model']!==''?$openAiExecutiveBrief['latest_model']:'-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>최신 브리핑 생성일</span><strong><?php echo h(isset($openAiExecutiveBrief['latest_brief_date'])&&$openAiExecutiveBrief['latest_brief_date']!==''?$openAiExecutiveBrief['latest_brief_date']:'-'); ?></strong></div>
      <div class="ai-audit-snapshot-item"><span>최신 브리핑 대상 월</span><strong><?php echo h(isset($openAiExecutiveBrief['latest_brief_target_ym'])&&$openAiExecutiveBrief['latest_brief_target_ym']!==''?$openAiExecutiveBrief['latest_brief_target_ym']:'-'); ?></strong></div>
    </div>
  </section>

  <div class="ai-audit-section-list">
    <?php foreach ($aiAuditSections as $section): ?>
      <?php
        $sectionStatus = isset($section['status']) ? (string)$section['status'] : 'unavailable';
        $tone = cpms_ai_audit_tone($sectionStatus);
        $sectionScore = isset($section['score']) && $section['score'] !== null ? (int)$section['score'] : null;
        $sectionMetrics = isset($section['metrics']) && is_array($section['metrics']) ? $section['metrics'] : array();
        $sectionWarnings = isset($section['warnings']) && is_array($section['warnings']) ? $section['warnings'] : array();
        $sectionRecommendations = isset($section['recommendations']) && is_array($section['recommendations']) ? $section['recommendations'] : array();
        $sectionMissingTables = isset($section['missing_tables']) && is_array($section['missing_tables']) ? $section['missing_tables'] : array();
        $sectionMissingColumns = isset($section['missing_columns']) && is_array($section['missing_columns']) ? $section['missing_columns'] : array();
        $sectionHighlights = isset($section['highlights']) && is_array($section['highlights']) ? $section['highlights'] : array();
      ?>
      <section class="ai-audit-section" style="border-color:<?php echo h($tone['border']); ?>;">
        <header class="ai-audit-section-head" style="background:<?php echo h($tone['soft']); ?>;">
          <div>
            <div class="ai-audit-section-title">
              <h3><?php echo h(isset($section['label']) ? $section['label'] : '점검 영역'); ?></h3>
              <span class="ai-audit-badge" style="border-color:<?php echo h($tone['border']); ?>;background:#fff;color:<?php echo h($tone['text']); ?>;"><?php echo h(isset($section['grade']) ? $section['grade'] : '확인 불가'); ?></span>
            </div>
            <?php if (empty($section['available'])): ?>
              <div style="margin-top:8px;color:<?php echo h($tone['text']); ?>;font-size:13px;font-weight:800;"><?php echo h(isset($section['unavailable_reason']) ? $section['unavailable_reason'] : '자료 확인 불가'); ?></div>
            <?php endif; ?>
          </div>
          <div class="ai-audit-score" style="color:<?php echo h($tone['text']); ?>;">
            <strong><?php echo $sectionScore === null ? '-' : h((string)$sectionScore); ?></strong>
            <span><?php echo $sectionScore === null ? '확인 불가' : '/ 100점'; ?></span>
          </div>
        </header>

        <div class="ai-audit-facts">
          <div class="ai-audit-fact"><span>총 자료 수</span><strong><?php echo h(number_format(isset($section['row_count']) ? (int)$section['row_count'] : 0)); ?>건</strong></div>
          <div class="ai-audit-fact"><span>현장 수</span><strong><?php echo h(number_format(isset($section['project_count']) ? (int)$section['project_count'] : 0)); ?>개</strong></div>
          <div class="ai-audit-fact"><span>데이터 보유월</span><strong><?php echo h(number_format(isset($section['month_count']) ? (int)$section['month_count'] : 0)); ?>개월</strong></div>
          <div class="ai-audit-fact"><span>첫 데이터</span><strong><?php echo h(isset($section['first_date']) && $section['first_date'] !== '' ? $section['first_date'] : '-'); ?></strong></div>
          <div class="ai-audit-fact"><span>최근 데이터</span><strong><?php echo h(isset($section['last_date']) && $section['last_date'] !== '' ? $section['last_date'] : '-'); ?></strong></div>
        </div>

        <?php if (count($sectionHighlights) > 0): ?>
          <?php foreach ($sectionHighlights as $highlight): ?><div class="ai-audit-highlight"><?php echo h($highlight); ?></div><?php endforeach; ?>
        <?php endif; ?>

        <?php if (isset($section['history_setup_required']) && $section['history_setup_required']): ?>
          <div class="ai-audit-history-link"><span>통합 비용 입력·변경이력 구조를 설치하거나 확인해주세요.</span><a href="?r=admin%2Fai_data_setup">AI 데이터 이력 설정</a></div>
        <?php endif; ?>

        <div class="ai-audit-table-wrap">
          <table class="ai-audit-table">
            <thead><tr><th>점검항목</th><th>확인 결과</th><th>보유율</th><th>판정</th><th>설명</th></tr></thead>
            <tbody>
              <?php if (count($sectionMetrics) === 0): ?>
                <tr><td colspan="5" style="padding:24px;text-align:center;color:#64748b;">세부 집계를 확인할 수 없습니다.</td></tr>
              <?php else: ?>
                <?php foreach ($sectionMetrics as $metric): ?>
                  <?php $metricTone = cpms_ai_audit_metric_tone(isset($metric['judgement']) ? $metric['judgement'] : '확인 불가'); ?>
                  <tr>
                    <td><?php echo h(isset($metric['label']) ? $metric['label'] : '-'); ?></td>
                    <td><?php echo h(isset($metric['result']) ? $metric['result'] : '-'); ?></td>
                    <td><?php echo h(isset($metric['rate_label']) ? $metric['rate_label'] : '-'); ?></td>
                    <td><span class="ai-audit-badge" style="border-color:<?php echo h($metricTone['border']); ?>;background:<?php echo h($metricTone['background']); ?>;color:<?php echo h($metricTone['color']); ?>;"><?php echo h(isset($metric['judgement']) ? $metric['judgement'] : '확인 불가'); ?></span></td>
                    <td class="desc"><?php echo h(isset($metric['description']) ? $metric['description'] : ''); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="ai-audit-detail-grid">
          <div class="ai-audit-detail">
            <h4 style="color:#b45309;">주요 경고</h4>
            <?php if (count($sectionWarnings) === 0): ?><div class="ai-audit-empty">경고 없음</div><?php else: ?><ul><?php foreach ($sectionWarnings as $warning): ?><li><?php echo h($warning); ?></li><?php endforeach; ?></ul><?php endif; ?>
          </div>
          <div class="ai-audit-detail">
            <h4 style="color:#1d4ed8;">권장 보완사항</h4>
            <?php if (count($sectionRecommendations) === 0): ?><div class="ai-audit-empty">추가 권장사항 없음</div><?php else: ?><ul><?php foreach ($sectionRecommendations as $recommendation): ?><li><?php echo h($recommendation); ?></li><?php endforeach; ?></ul><?php endif; ?>
          </div>
          <?php if (count($sectionMissingTables) > 0): ?>
            <div class="ai-audit-detail"><h4>확인되지 않은 테이블</h4><ul><?php foreach ($sectionMissingTables as $table): ?><li><?php echo h($table); ?></li><?php endforeach; ?></ul></div>
          <?php endif; ?>
          <?php if (count($sectionMissingColumns) > 0): ?>
            <div class="ai-audit-detail"><h4>확인되지 않은 컬럼</h4><ul><?php foreach ($sectionMissingColumns as $column): ?><li><?php echo h($column); ?></li><?php endforeach; ?></ul></div>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <section class="ai-audit-criteria">
    <h3>점검 기준</h3>
    <div class="ai-audit-criteria-grid">
      <div class="ai-audit-criteria-item"><strong>점수 의미</strong>이 점수는 AI가 똑똑한 정도가 아니라 향후 예측에 사용할 날짜, 시각, 담당자, 변경 전후 이력이 얼마나 잘 기록되어 있는지를 뜻합니다. 90점 이상 준비 우수, 75~89점 준비 양호, 60~74점 보완 필요, 59점 이하 준비 부족입니다.</div>
      <div class="ai-audit-criteria-item"><strong>데이터 보유기간</strong>0개월은 데이터 없음, 1~2개월은 학습자료 부족, 3~5개월은 시범 예측 가능, 6~11개월은 기본 예측 가능, 12개월 이상은 계절성 분석 가능으로 판단합니다.</div>
      <div class="ai-audit-criteria-item"><strong>확인 불가 영역</strong>DB 연결 또는 핵심 테이블을 전혀 확인할 수 없는 영역은 0점으로 처리하지 않습니다. 확인 가능한 영역끼리 원래 가중치 비율을 다시 계산하며 상단에 일부 자료 확인 불가 경고를 표시합니다.</div>
      <div class="ai-audit-criteria-item"><strong>이번 단계 범위</strong>이 화면은 예측 결과가 아니라 준비상태 점검입니다. 데이터를 변경하거나 자동 보정하지 않으며 GPT API도 아직 연결하지 않았습니다.</div>
    </div>
  </section>
</div>
