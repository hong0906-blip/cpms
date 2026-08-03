<?php
/**
 * 통합 비용 입력·변경이력 관리자 조회 화면.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostDataEventService;

if (!Auth::check() || !(Auth::isDevelopmentDepartment() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '<div style="padding:16px;border:1px solid #fecaca;border-radius:14px;background:#fef2f2;color:#b91c1c;font-weight:800;">' . h('접근 권한이 없습니다.') . '</div>';
    return;
}

require_once __DIR__ . '/../../services/CostDataEventService.php';

if (!function_exists('cpms_cost_event_label')) {
    function cpms_cost_event_label($group, $value)
    {
        $labels = array(
            'cost' => array('labor'=>'노무비','material'=>'자재비','equipment'=>'장비비','outsourcing'=>'외주비','safety'=>'안전관리비','health'=>'보건비','other'=>'기타 비용'),
            'action' => array('CREATE'=>'신규','UPDATE'=>'수정','DELETE'=>'삭제','RESTORE'=>'복구','ADJUST'=>'조정'),
            'source' => array('DIRECT'=>'직접입력','EXCEL'=>'엑셀 업로드','ATTENDANCE'=>'출퇴근 연동','APPROVAL'=>'승인 완료 반영','ADMIN_FORCE'=>'관리자 강제입력','AUTO_CALC'=>'자동계산','SYSTEM'=>'시스템 반영'),
        );
        return isset($labels[$group]) && isset($labels[$group][$value]) ? $labels[$group][$value] : (string)$value;
    }
}
if (!function_exists('cpms_cost_event_money')) {
    function cpms_cost_event_money($value)
    {
        return ($value === null || $value === '') ? '-' : number_format((float)$value, 0) . '원';
    }
}
if (!function_exists('cpms_cost_event_snapshot_label')) {
    function cpms_cost_event_snapshot_label($key)
    {
        $labels = array(
            'project_id'=>'프로젝트 ID','material_id'=>'자재 ID','equipment_id'=>'장비 ID','use_date'=>'사용일','expense_date'=>'비용일',
            'actual_date'=>'실제 발생일','settlement_ym'=>'귀속월','old_settlement_ym'=>'기존 귀속월','new_settlement_ym'=>'변경 귀속월',
            'amount'=>'금액','old_amount'=>'변경 전 금액','new_amount'=>'변경 후 금액','work_unit'=>'공수','old_value'=>'변경 전 공수','new_value'=>'변경 후 공수',
            'base_rate_snapshot'=>'당시 단가','unit_price'=>'단가','quantity'=>'수량','is_manual_unit'=>'수동 공수','advance_yn'=>'선급 여부',
            'memo'=>'메모','remark'=>'비고','category'=>'구분','item_name'=>'품목','material_name'=>'자재명','equipment_name'=>'장비명',
            'vendor_name'=>'업체명','company_name'=>'업체명','use_content'=>'사용내용','status'=>'상태','reason'=>'사유','is_deleted'=>'삭제 여부',
            'is_deleted_entry'=>'삭제 입력 여부','month'=>'대상월','cost_date'=>'비용일','cost_type'=>'비용 종류','request_type'=>'요청 종류',
            'target_type'=>'대상 종류','target_id'=>'대상 ID','approval_stage'=>'승인 단계','approval_required_level'=>'승인 수준'
        );
        return isset($labels[$key]) ? $labels[$key] : $key;
    }
}

$historyPdo = Db::pdo();
$historyInstalled = CostDataEventService::isInstalled($historyPdo);
$defaultStart = date('Y-m-d', strtotime('-29 days'));
$filters = array(
    'start_date' => isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : $defaultStart,
    'end_date' => isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : date('Y-m-d'),
    'project_id' => isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0,
    'cost_type' => isset($_GET['cost_type']) ? trim((string)$_GET['cost_type']) : '',
    'event_action' => isset($_GET['event_action']) ? trim((string)$_GET['event_action']) : '',
    'source_type' => isset($_GET['source_type']) ? trim((string)$_GET['source_type']) : '',
    'actor_name' => isset($_GET['actor_name']) ? trim((string)$_GET['actor_name']) : '',
    'related_request_id' => isset($_GET['related_request_id']) ? (int)$_GET['related_request_id'] : 0,
);
if (CostDataEventService::validDate($filters['start_date']) === '') $filters['start_date'] = $defaultStart;
if (CostDataEventService::validDate($filters['end_date']) === '') $filters['end_date'] = date('Y-m-d');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$summary = CostDataEventService::summary($historyPdo);
$options = CostDataEventService::filterOptions($historyPdo);
$total = $historyInstalled ? CostDataEventService::countEvents($historyPdo, $filters) : 0;
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$events = $historyInstalled ? CostDataEventService::listEvents($historyPdo, $filters, $page, $perPage) : array();

$pageParams = $_GET;
unset($pageParams['page']);
$pageBase = '?' . http_build_query($pageParams, '', '&');
?>

<style>
  .event-history { color:#0f172a; }
  .event-history * { box-sizing:border-box; }
  .eh-hero, .eh-card { border:1px solid #e2e8f0; border-radius:18px; background:#fff; box-shadow:0 8px 25px rgba(15,23,42,.05); }
  .eh-hero { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:23px; background:linear-gradient(135deg,#fff 0%,#f0fdfa 100%); }
  .eh-hero h2 { margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em; }
  .eh-hero p { margin:9px 0 0;color:#475569;font-size:14px;line-height:1.6; }
  .eh-link { display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 14px;border-radius:11px;background:#0f766e;color:#fff;text-decoration:none;font-weight:900;white-space:nowrap; }
  .eh-summary { display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:9px;margin-top:14px; }
  .eh-summary .eh-card { padding:14px;min-height:94px; }
  .eh-label { color:#64748b;font-size:11px;font-weight:800; }
  .eh-value { margin-top:8px;font-size:20px;font-weight:900;overflow-wrap:anywhere; }
  .eh-filter { margin-top:14px;padding:18px; }
  .eh-filter-grid { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px; }
  .eh-field label { display:block;margin-bottom:6px;color:#475569;font-size:12px;font-weight:800; }
  .eh-field input,.eh-field select { width:100%;min-height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#0f172a; }
  .eh-actions { display:flex;gap:8px;align-items:end; }
  .eh-btn { min-height:40px;padding:8px 14px;border:0;border-radius:9px;background:#0f766e;color:#fff;text-decoration:none;font-weight:900;cursor:pointer; }
  .eh-btn.secondary { display:inline-flex;align-items:center;background:#fff;color:#334155;border:1px solid #cbd5e1; }
  .eh-table-card { margin-top:14px;overflow:hidden; }
  .eh-table-wrap { width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch; }
  .eh-table { width:100%;min-width:1480px;border-collapse:collapse;font-size:12px; }
  .eh-table th { padding:11px 10px;background:#f8fafc;color:#475569;text-align:left;font-weight:900;white-space:nowrap; }
  .eh-table td { padding:12px 10px;border-top:1px solid #eef2f7;vertical-align:top;color:#334155; }
  .eh-table td.money { text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums; }
  .eh-badge { display:inline-flex;padding:4px 8px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:11px;font-weight:900;white-space:nowrap; }
  .eh-detail summary { cursor:pointer;color:#0f766e;font-weight:900; }
  .eh-detail-grid { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:10px;min-width:540px; }
  .eh-detail-box { padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc; }
  .eh-detail-box h4 { margin:0 0 7px;font-size:12px; }
  .eh-detail-box table { width:100%;border-collapse:collapse; }
  .eh-detail-box th,.eh-detail-box td { padding:5px;border-top:1px solid #e2e8f0;text-align:left;font-size:11px;overflow-wrap:anywhere; }
  .eh-pagination { display:flex;align-items:center;justify-content:center;gap:8px;padding:16px;border-top:1px solid #e2e8f0; }
  .eh-page { padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;color:#334155;text-decoration:none;font-weight:800; }
  .eh-empty { padding:30px;text-align:center;color:#64748b; }
  .eh-warning { margin-top:14px;padding:18px;border:1px solid #fed7aa;border-radius:14px;background:#fff7ed;color:#9a3412;line-height:1.7; }
  @media (max-width:1200px) { .eh-summary { grid-template-columns:repeat(4,minmax(0,1fr)); } .eh-filter-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:650px) { .eh-hero { flex-direction:column;padding:18px; }.eh-link { width:100%; }.eh-summary { grid-template-columns:repeat(2,minmax(0,1fr)); }.eh-filter-grid { grid-template-columns:1fr; }.eh-actions { align-items:stretch; }.eh-actions .eh-btn { flex:1;text-align:center; } }
</style>

<div class="event-history">
  <section class="eh-hero">
    <div><h2>통합 비용 입력·변경이력</h2><p>앞으로 실제로 성공한 비용 등록·수정·삭제 및 최종 승인 반영 이벤트를 개인정보 없이 조회합니다.</p></div>
    <a class="eh-link" href="?r=admin%2Fai_data_setup">AI 데이터 이력 설정</a>
  </section>

  <?php if (!$historyInstalled): ?>
    <div class="eh-warning"><strong>통합 비용 이력 테이블이 아직 설치되지 않았습니다.</strong><br>기존 비용 입력 기능은 계속 정상 작동하며 이력 기록만 건너뜁니다. <a href="?r=admin%2Fai_data_setup">설치 화면으로 이동</a></div>
  <?php endif; ?>

  <section class="eh-summary" aria-label="이력 요약">
    <?php
      $summaryCards = array(
          '전체 이벤트' => number_format((int)$summary['total_count']) . '건',
          '오늘 이벤트' => number_format((int)$summary['today_count']) . '건',
          '최근 30일' => number_format((int)$summary['recent_30_count']) . '건',
          '신규 등록' => number_format((int)$summary['create_count']) . '건',
          '수정·조정' => number_format((int)$summary['update_count']) . '건',
          '삭제' => number_format((int)$summary['delete_count']) . '건',
          '최근 이벤트' => $summary['last_event_at'] !== '' ? $summary['last_event_at'] : '-'
      );
      foreach ($summaryCards as $label => $value):
    ?>
      <div class="eh-card"><div class="eh-label"><?php echo h($label); ?></div><div class="eh-value"<?php echo $label === '최근 이벤트' ? ' style="font-size:14px;"' : ''; ?>><?php echo h($value); ?></div></div>
    <?php endforeach; ?>
  </section>

  <form method="get" class="eh-card eh-filter">
    <input type="hidden" name="r" value="admin/ai_data_history">
    <div class="eh-filter-grid">
      <div class="eh-field"><label>시작일</label><input type="date" name="start_date" value="<?php echo h($filters['start_date']); ?>"></div>
      <div class="eh-field"><label>종료일</label><input type="date" name="end_date" value="<?php echo h($filters['end_date']); ?>"></div>
      <div class="eh-field"><label>프로젝트</label><select name="project_id"><option value="0">전체</option><?php foreach ($options['projects'] as $project): $pid = isset($project['project_id']) ? (int)$project['project_id'] : 0; ?><option value="<?php echo $pid; ?>"<?php echo $filters['project_id'] === $pid ? ' selected' : ''; ?>><?php echo h((isset($project['project_name']) && trim((string)$project['project_name']) !== '' ? $project['project_name'] : '프로젝트 #' . $pid)); ?></option><?php endforeach; ?></select></div>
      <div class="eh-field"><label>비용 종류</label><select name="cost_type"><option value="">전체</option><?php foreach ($options['cost_types'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['cost_type'] === $value ? ' selected' : ''; ?>><?php echo h(cpms_cost_event_label('cost', $value)); ?></option><?php endforeach; ?></select></div>
      <div class="eh-field"><label>처리 구분</label><select name="event_action"><option value="">전체</option><?php foreach ($options['event_actions'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['event_action'] === $value ? ' selected' : ''; ?>><?php echo h(cpms_cost_event_label('action', $value)); ?></option><?php endforeach; ?></select></div>
      <div class="eh-field"><label>입력 출처</label><select name="source_type"><option value="">전체</option><?php foreach ($options['source_types'] as $value): ?><option value="<?php echo h($value); ?>"<?php echo $filters['source_type'] === $value ? ' selected' : ''; ?>><?php echo h(cpms_cost_event_label('source', $value)); ?></option><?php endforeach; ?></select></div>
      <div class="eh-field"><label>처리자 이름</label><input type="text" name="actor_name" maxlength="100" value="<?php echo h($filters['actor_name']); ?>"></div>
      <div class="eh-field"><label>관련 승인 요청 ID</label><input type="number" min="1" name="related_request_id" value="<?php echo $filters['related_request_id'] > 0 ? h((string)$filters['related_request_id']) : ''; ?>"></div>
      <div class="eh-actions"><button type="submit" class="eh-btn">조회</button><a class="eh-btn secondary" href="?r=admin%2Fai_data_history">초기화</a></div>
    </div>
  </form>

  <section class="eh-card eh-table-card">
    <div style="padding:14px 16px;color:#475569;font-size:13px;font-weight:800;">조회 결과 <?php echo h(number_format($total)); ?>건 · 페이지당 50건</div>
    <div class="eh-table-wrap">
      <table class="eh-table">
        <thead><tr><th>처리일시</th><th>현장</th><th>비용 종류</th><th>처리</th><th>입력 출처</th><th>실제 발생일</th><th>귀속월</th><th>변경 전 금액</th><th>변경 후 금액</th><th>증감액</th><th>처리자</th><th>상세</th></tr></thead>
        <tbody>
        <?php if (count($events) === 0): ?>
          <tr><td colspan="12" class="eh-empty"><?php echo $historyInstalled ? '조회기간에 해당하는 이력이 없습니다.' : '테이블 설치 후 앞으로 발생하는 이벤트부터 표시됩니다.'; ?></td></tr>
        <?php else: foreach ($events as $event): ?>
          <?php $oldSnapshot = CostDataEventService::decodeSnapshot(isset($event['old_data']) ? $event['old_data'] : ''); $newSnapshot = CostDataEventService::decodeSnapshot(isset($event['new_data']) ? $event['new_data'] : ''); ?>
          <tr>
            <td style="white-space:nowrap;"><?php echo h($event['event_at']); ?></td>
            <td><?php echo h(trim((string)$event['project_name_snapshot']) !== '' ? $event['project_name_snapshot'] : ((int)$event['project_id'] > 0 ? '프로젝트 #' . (int)$event['project_id'] : '-')); ?></td>
            <td><span class="eh-badge"><?php echo h(cpms_cost_event_label('cost', $event['cost_type'])); ?></span></td>
            <td><span class="eh-badge"><?php echo h(cpms_cost_event_label('action', $event['event_action'])); ?></span></td>
            <td><span class="eh-badge"><?php echo h(cpms_cost_event_label('source', $event['source_type'])); ?></span></td>
            <td><?php echo h($event['actual_date'] !== null ? $event['actual_date'] : '-'); ?></td>
            <td><?php echo h($event['settlement_ym'] !== null ? $event['settlement_ym'] : '-'); ?></td>
            <td class="money"><?php echo h(cpms_cost_event_money($event['old_amount'])); ?></td>
            <td class="money"><?php echo h(cpms_cost_event_money($event['new_amount'])); ?></td>
            <td class="money" style="color:<?php echo (float)$event['delta_amount'] < 0 ? '#b91c1c' : ((float)$event['delta_amount'] > 0 ? '#047857' : '#475569'); ?>;"><?php echo h(cpms_cost_event_money($event['delta_amount'])); ?></td>
            <td><?php echo h(trim((string)$event['actor_name']) !== '' ? $event['actor_name'] : '-'); ?></td>
            <td>
              <details class="eh-detail"><summary>보기</summary>
                <div class="eh-detail-grid">
                  <div class="eh-detail-box"><h4>이벤트 정보</h4><table>
                    <tr><th>대상 종류</th><td><?php echo h($event['target_type']); ?></td></tr>
                    <tr><th>대상 ID</th><td><?php echo h($event['target_id'] !== null ? $event['target_id'] : '-'); ?></td></tr>
                    <tr><th>사유</th><td><?php echo h($event['reason'] !== null ? $event['reason'] : '-'); ?></td></tr>
                    <tr><th>승인 요청 ID</th><td><?php echo h($event['related_request_id'] !== null ? $event['related_request_id'] : '-'); ?></td></tr>
                  </table></div>
                  <div class="eh-detail-box"><h4>변경 전 자료</h4><?php if (count($oldSnapshot) === 0): ?><div style="color:#64748b;">자료 없음</div><?php else: ?><table><?php foreach ($oldSnapshot as $key => $value): ?><tr><th><?php echo h(cpms_cost_event_snapshot_label($key)); ?></th><td><?php echo h((string)$value); ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>
                  <div class="eh-detail-box"><h4>변경 후 자료</h4><?php if (count($newSnapshot) === 0): ?><div style="color:#64748b;">자료 없음</div><?php else: ?><table><?php foreach ($newSnapshot as $key => $value): ?><tr><th><?php echo h(cpms_cost_event_snapshot_label($key)); ?></th><td><?php echo h((string)$value); ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>
                </div>
              </details>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <nav class="eh-pagination" aria-label="페이지 이동">
      <?php if ($page > 1): ?><a class="eh-page" href="<?php echo h($pageBase . '&page=' . ($page - 1)); ?>">이전</a><?php endif; ?>
      <span style="font-weight:900;"><?php echo h(number_format($page)); ?> / <?php echo h(number_format($totalPages)); ?></span>
      <?php if ($page < $totalPages): ?><a class="eh-page" href="<?php echo h($pageBase . '&page=' . ($page + 1)); ?>">다음</a><?php endif; ?>
    </nav>
  </section>
</div>
