<?php
/**
 * 통합 비용 이력 최초 설치/구조 확인 화면.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostDataEventService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div class="ai-event-message error">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/CostDataEventService.php';

$eventPdo = Db::pdo();
$setupResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    if (!csrf_check($token)) {
        $setupResult = array('ok' => false, 'message' => '보안 토큰이 올바르지 않습니다.');
    } else if ($action !== 'install') {
        $setupResult = array('ok' => false, 'message' => '설치 요청값이 올바르지 않습니다.');
    } else {
        $setupResult = CostDataEventService::installOrUpdate($eventPdo);
    }
}
$setupStatus = CostDataEventService::schemaStatus($eventPdo);
$setupColumns = CostDataEventService::requiredColumns();
$setupIndexes = CostDataEventService::requiredIndexes();
?>

<style>
  .ai-event-page { color:#0f172a; }
  .ai-event-page * { box-sizing:border-box; }
  .ai-event-hero, .ai-event-card { border:1px solid #e2e8f0; border-radius:18px; background:#fff; box-shadow:0 8px 26px rgba(15,23,42,.05); }
  .ai-event-hero { padding:24px; background:linear-gradient(135deg,#fff 0%,#eff6ff 100%); }
  .ai-event-hero h2 { margin:0; font-size:27px; font-weight:900; letter-spacing:-.03em; }
  .ai-event-hero p { max-width:900px; margin:10px 0 0; color:#475569; font-size:14px; line-height:1.7; }
  .ai-event-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:16px; }
  .ai-event-card { padding:18px; }
  .ai-event-label { color:#64748b; font-size:12px; font-weight:800; }
  .ai-event-value { margin-top:8px; font-size:21px; font-weight:900; overflow-wrap:anywhere; }
  .ai-event-section { margin-top:16px; padding:20px; }
  .ai-event-section h3 { margin:0 0 14px; font-size:18px; font-weight:900; }
  .ai-event-message { margin-top:16px; padding:14px 16px; border-radius:13px; font-size:14px; font-weight:800; }
  .ai-event-message.ok { border:1px solid #a7f3d0; background:#ecfdf5; color:#047857; }
  .ai-event-message.error { border:1px solid #fecaca; background:#fef2f2; color:#b91c1c; }
  .ai-event-list { margin:0; padding-left:20px; color:#475569; font-size:13px; line-height:1.7; }
  .ai-event-actions { display:flex; flex-wrap:wrap; gap:9px; margin-top:16px; }
  .ai-event-btn { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:10px 15px; border:0; border-radius:11px; background:#1d4ed8; color:#fff; text-decoration:none; font-weight:900; cursor:pointer; }
  .ai-event-btn.secondary { border:1px solid #cbd5e1; background:#fff; color:#334155; }
  .ai-event-code { max-height:360px; overflow:auto; padding:16px; border:1px solid #e2e8f0; border-radius:12px; background:#0f172a; color:#dbeafe; font:12px/1.6 Consolas,monospace; white-space:pre; }
  .ai-event-two { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
  @media (max-width:900px) { .ai-event-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:640px) { .ai-event-hero { padding:18px; } .ai-event-hero h2 { font-size:23px; } .ai-event-grid, .ai-event-two { grid-template-columns:1fr; } .ai-event-actions .ai-event-btn { width:100%; } }
</style>

<div class="ai-event-page">
  <section class="ai-event-hero">
    <h2>AI 데이터 이력 설정</h2>
    <p>이 기능은 앞으로 발생하는 비용 입력·수정·삭제 이력을 저장합니다. 과거자료의 입력시간이나 담당자를 임의 생성하지 않습니다. 설치 대상은 <strong>cpms_cost_data_events</strong> 한 개이며 기존 비용 테이블은 변경하지 않습니다.</p>
  </section>

  <?php if (is_array($setupResult)): ?>
    <div class="ai-event-message <?php echo !empty($setupResult['ok']) ? 'ok' : 'error'; ?>"><?php echo h(isset($setupResult['message']) ? $setupResult['message'] : '처리 결과를 확인할 수 없습니다.'); ?></div>
  <?php endif; ?>

  <section class="ai-event-grid" aria-label="설치 상태">
    <div class="ai-event-card"><div class="ai-event-label">DB 연결</div><div class="ai-event-value"><?php echo !empty($setupStatus['db_available']) ? '확인' : '확인 불가'; ?></div></div>
    <div class="ai-event-card"><div class="ai-event-label">테이블 상태</div><div class="ai-event-value" style="color:<?php echo !empty($setupStatus['installed']) ? '#047857' : '#c2410c'; ?>;"><?php echo !empty($setupStatus['installed']) ? '설치 완료' : (!empty($setupStatus['table_exists']) ? '구조 보완 필요' : '미설치'); ?></div></div>
    <div class="ai-event-card"><div class="ai-event-label">현재 이벤트</div><div class="ai-event-value"><?php echo h(number_format(isset($setupStatus['row_count']) ? (int)$setupStatus['row_count'] : 0)); ?>건</div></div>
    <div class="ai-event-card"><div class="ai-event-label">최근 이벤트</div><div class="ai-event-value" style="font-size:16px;"><?php echo h(!empty($setupStatus['last_event_at']) ? $setupStatus['last_event_at'] : '-'); ?></div></div>
  </section>

  <section class="ai-event-card ai-event-section">
    <h3>구조 확인</h3>
    <div class="ai-event-two">
      <div>
        <div class="ai-event-label" style="margin-bottom:8px;">필수 컬럼 <?php echo h(number_format(count($setupColumns))); ?>개</div>
        <?php if (count($setupStatus['missing_columns']) === 0): ?>
          <div class="ai-event-message ok" style="margin:0;">필수 컬럼이 모두 확인됐습니다.</div>
        <?php else: ?>
          <ul class="ai-event-list"><?php foreach ($setupStatus['missing_columns'] as $column): ?><li><?php echo h($column); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
      <div>
        <div class="ai-event-label" style="margin-bottom:8px;">필수 인덱스 <?php echo h(number_format(count($setupIndexes))); ?>개</div>
        <?php if (count($setupStatus['missing_indexes']) === 0): ?>
          <div class="ai-event-message ok" style="margin:0;">필수 인덱스가 모두 확인됐습니다.</div>
        <?php else: ?>
          <ul class="ai-event-list"><?php foreach ($setupStatus['missing_indexes'] as $index): ?><li><?php echo h($index); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>

    <form method="post" action="?r=admin%2Fai_data_setup" class="ai-event-actions">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="action" value="install">
      <button type="submit" class="ai-event-btn">설치/확인</button>
      <a class="ai-event-btn secondary" href="?r=admin%2Fai_data_history">통합 이력 보기</a>
      <a class="ai-event-btn secondary" href="?r=admin%2Fai_data_audit">AI 데이터 준비상태 점검</a>
    </form>
    <p style="margin:12px 0 0;color:#64748b;font-size:12px;line-height:1.6;">GET 요청은 상태만 조회합니다. 테이블 설치 또는 구조 확인은 위 버튼의 관리자 POST 요청에서만 실행됩니다.</p>
  </section>

  <details class="ai-event-card ai-event-section">
    <summary style="cursor:pointer;font-weight:900;">설치 예정 테이블 구조 보기</summary>
    <pre class="ai-event-code"><?php echo h(CostDataEventService::createTableSql()); ?></pre>
  </details>
</div>
