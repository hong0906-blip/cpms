<?php
/**
 * 현장별 일일 스냅샷 관리자 조회 화면.
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

if (!function_exists('cpms_ai_snapshot_money')) {
    function cpms_ai_snapshot_money($value)
    {
        if ($value === null || $value === '') return '-';
        return number_format((float)$value, 0) . '원';
    }
}
if (!function_exists('cpms_ai_snapshot_rate')) {
    function cpms_ai_snapshot_rate($value)
    {
        return ($value === null || $value === '') ? '-' : number_format((float)$value, 1) . '%';
    }
}
if (!function_exists('cpms_ai_snapshot_delta')) {
    function cpms_ai_snapshot_delta($current, $previous)
    {
        if ($previous === null || $previous === '') return '-';
        $delta = (float)$current - (float)$previous;
        if (abs($delta) < 0.01) return '0원';
        return ($delta > 0 ? '+' : '') . number_format($delta, 0) . '원';
    }
}
if (!function_exists('cpms_ai_snapshot_nested_text')) {
    function cpms_ai_snapshot_nested_text($value)
    {
        if (is_array($value)) {
            $parts = array();
            foreach ($value as $key=>$item) $parts[] = (is_string($key) ? $key . ': ' : '') . cpms_ai_snapshot_nested_text($item);
            return implode(' / ', $parts);
        }
        if (is_bool($value)) return $value ? '예' : '아니오';
        if ($value === null || $value === '') return '-';
        return (string)$value;
    }
}
if (!function_exists('cpms_ai_snapshot_safe_detail')) {
    function cpms_ai_snapshot_safe_detail($value, $type)
    {
        if (!is_array($value)) return array();
        $safe = array();
        if ($type === 'flags') {
            foreach (array('missing','warnings') as $listKey) {
                if (!isset($value[$listKey]) || !is_array($value[$listKey])) continue;
                $safe[$listKey] = array();
                foreach (array_slice($value[$listKey], 0, 30) as $item) {
                    if (!is_scalar($item)) continue;
                    $text = (string)$item;
                    $safe[$listKey][] = function_exists('mb_substr') ? mb_substr($text, 0, 200, 'UTF-8') : substr($text, 0, 200);
                }
            }
            if (isset($value['sources']) && is_array($value['sources'])) {
                $safe['sources'] = array();
                $sourceKeys = array('calculation','labor','material','equipment','outsourcing','sales','safety_health');
                foreach ($sourceKeys as $key) {
                    if (isset($value['sources'][$key]) && is_scalar($value['sources'][$key])) $safe['sources'][$key] = (string)$value['sources'][$key];
                }
            }
            return $safe;
        }
        if (isset($value['periods']) && is_array($value['periods'])) {
            $safe['periods'] = array();
            foreach (array('labor','sales','other_costs') as $periodKey) {
                if (!isset($value['periods'][$periodKey]) || !is_array($value['periods'][$periodKey])) continue;
                $safe['periods'][$periodKey] = array(
                    'start'=>isset($value['periods'][$periodKey]['start']) ? (string)$value['periods'][$periodKey]['start'] : '',
                    'end'=>isset($value['periods'][$periodKey]['end']) ? (string)$value['periods'][$periodKey]['end'] : ''
                );
            }
        }
        if (isset($value['outsourcing']) && is_array($value['outsourcing'])) {
            $safe['outsourcing'] = array(
                'labor_allocation'=>isset($value['outsourcing']['labor_allocation']) ? (float)$value['outsourcing']['labor_allocation'] : 0,
                'direct_input'=>isset($value['outsourcing']['direct_input']) ? (float)$value['outsourcing']['direct_input'] : 0
            );
        }
        foreach (array('sales_basis','company_overhead','cumulative_months') as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) $safe[$key] = $value[$key];
        }
        return $safe;
    }
}

$snapshotHistoryPdo = Db::pdo();
$snapshotHistoryInstalled = AiDailySnapshotService::isInstalled($snapshotHistoryPdo);
$latestSnapshotDate = AiDailySnapshotService::latestSnapshotDate($snapshotHistoryPdo);
$filters = array(
    'snapshot_date'=>isset($_GET['snapshot_date']) ? trim((string)$_GET['snapshot_date']) : $latestSnapshotDate,
    'target_ym'=>isset($_GET['target_ym']) ? trim((string)$_GET['target_ym']) : '',
    'project_id'=>isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0,
    'project_status'=>isset($_GET['project_status']) ? trim((string)$_GET['project_status']) : '',
    'q'=>isset($_GET['q']) ? trim((string)$_GET['q']) : ''
);
if (AiDailySnapshotService::validDate($filters['snapshot_date']) === '') $filters['snapshot_date'] = $latestSnapshotDate;
if (AiDailySnapshotService::validYm($filters['target_ym']) === '') $filters['target_ym'] = '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$options = AiDailySnapshotService::historyOptions($snapshotHistoryPdo);
$total = $snapshotHistoryInstalled ? AiDailySnapshotService::countSnapshots($snapshotHistoryPdo, $filters) : 0;
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$rows = $snapshotHistoryInstalled ? AiDailySnapshotService::listSnapshots($snapshotHistoryPdo, $filters, $page, $perPage) : array();
$summary = AiDailySnapshotService::historySummary($snapshotHistoryPdo, $filters);
$pageParams = $_GET;
if (!isset($pageParams['r'])) $pageParams['r'] = 'admin/ai_snapshot_history';
unset($pageParams['page']);
$pageBase = '?' . http_build_query($pageParams, '', '&');
?>

<style>
  .snapshot-history { color:#0f172a; }
  .snapshot-history * { box-sizing:border-box; }
  .sh-hero,.sh-card { border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 25px rgba(15,23,42,.05); }
  .sh-hero { display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:23px;background:linear-gradient(135deg,#fff 0%,#ecfeff 100%); }
  .sh-hero h2 { margin:0;font-size:27px;font-weight:900;letter-spacing:-.03em; }
  .sh-hero p { max-width:800px;margin:9px 0 0;color:#475569;font-size:14px;line-height:1.65; }
  .sh-links { display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end; }
  .sh-link { display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 13px;border-radius:10px;background:#0e7490;color:#fff;text-decoration:none;font-weight:900;white-space:nowrap; }
  .sh-link.secondary { border:1px solid #cbd5e1;background:#fff;color:#334155; }
  .sh-summary { display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:9px;margin-top:14px; }
  .sh-summary .sh-card { padding:14px;min-height:92px; }
  .sh-label { color:#64748b;font-size:11px;font-weight:800; }
  .sh-value { margin-top:8px;font-size:17px;font-weight:900;overflow-wrap:anywhere; }
  .sh-filter { margin-top:14px;padding:18px; }
  .sh-filter-grid { display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px; }
  .sh-field label { display:block;margin-bottom:6px;color:#475569;font-size:12px;font-weight:800; }
  .sh-field input,.sh-field select { width:100%;min-height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff; }
  .sh-actions { display:flex;align-items:end;gap:8px; }
  .sh-btn { min-height:40px;padding:8px 14px;border:0;border-radius:9px;background:#0e7490;color:#fff;text-decoration:none;font-weight:900;cursor:pointer; }
  .sh-btn.secondary { display:inline-flex;align-items:center;border:1px solid #cbd5e1;background:#fff;color:#334155; }
  .sh-table-card { margin-top:14px;overflow:hidden; }
  .sh-table-wrap { width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch; }
  .sh-table { width:100%;min-width:1980px;border-collapse:collapse;font-size:12px; }
  .sh-table th { padding:11px 9px;background:#f8fafc;color:#475569;text-align:left;font-weight:900;white-space:nowrap; }
  .sh-table td { padding:12px 9px;border-top:1px solid #eef2f7;vertical-align:top;color:#334155; }
  .sh-table td.money { text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums; }
  .sh-badge { display:inline-flex;padding:4px 8px;border-radius:999px;background:#ecfeff;color:#0e7490;font-size:11px;font-weight:900;white-space:nowrap; }
  .sh-detail summary { cursor:pointer;color:#0e7490;font-weight:900; }
  .sh-detail-grid { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:10px;min-width:600px; }
  .sh-detail-box { padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc; }
  .sh-detail-box h4 { margin:0 0 7px;font-size:12px; }
  .sh-detail-box table { width:100%;border-collapse:collapse; }
  .sh-detail-box th,.sh-detail-box td { padding:5px;border-top:1px solid #e2e8f0;text-align:left;font-size:11px;overflow-wrap:anywhere; }
  .sh-pagination { display:flex;align-items:center;justify-content:center;gap:8px;padding:16px;border-top:1px solid #e2e8f0; }
  .sh-page { padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;color:#334155;text-decoration:none;font-weight:800; }
  .sh-empty { padding:30px;text-align:center;color:#64748b; }
  .sh-warning { margin-top:14px;padding:18px;border:1px solid #fed7aa;border-radius:14px;background:#fff7ed;color:#9a3412;line-height:1.7; }
  @media (max-width:1300px) { .sh-summary { grid-template-columns:repeat(4,minmax(0,1fr)); }.sh-filter-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
  @media (max-width:680px) { .sh-hero { flex-direction:column;padding:18px; }.sh-links,.sh-link { width:100%; }.sh-summary { grid-template-columns:repeat(2,minmax(0,1fr)); }.sh-filter-grid { grid-template-columns:1fr; }.sh-actions { align-items:stretch; }.sh-actions .sh-btn { flex:1;text-align:center; } }
</style>

<div class="snapshot-history">
  <section class="sh-hero">
    <div><h2>현장별 일일 스냅샷 이력</h2><p>일일 스냅샷은 해당 날짜 기준 현장별 누적 금액을 기록합니다. 통합 비용 이력은 누가 언제 무엇을 입력·수정·삭제했는지 기록하며 두 자료의 역할이 다릅니다.</p></div>
    <div class="sh-links"><a class="sh-link" href="?r=admin%2Fai_snapshot_setup">일일 스냅샷 설정</a><a class="sh-link secondary" href="?r=admin%2Fai_data_history">통합 비용 입력·변경이력</a></div>
  </section>

  <?php if (!$snapshotHistoryInstalled): ?><div class="sh-warning"><strong>일일 스냅샷 테이블이 아직 설치되지 않았습니다.</strong><br><a href="?r=admin%2Fai_snapshot_setup">일일 스냅샷 설정</a>에서 전용 테이블을 설치한 뒤 오늘 자료부터 저장할 수 있습니다.</div><?php endif; ?>

  <section class="sh-summary" aria-label="스냅샷 요약">
    <?php
      $summaryCards = array(
          '저장된 현장'=>number_format((int)$summary['project_count']) . '개',
          '월 투입비 합계'=>cpms_ai_snapshot_money($summary['monthly_input_total']),
          '누적 투입비 합계'=>cpms_ai_snapshot_money($summary['cumulative_input_total']),
          '월 매출 합계'=>cpms_ai_snapshot_money($summary['monthly_sales_total']),
          '월 이익 합계'=>cpms_ai_snapshot_money($summary['monthly_profit_total']),
          '당일 비용 이벤트'=>number_format((int)$summary['event_count']) . '건',
          '누락 항목 현장'=>number_format((int)$summary['missing_project_count']) . '개',
          '마지막 저장'=>trim((string)$summary['last_captured_at']) !== '' ? $summary['last_captured_at'] : '-'
      );
      foreach ($summaryCards as $label=>$value):
    ?><div class="sh-card"><div class="sh-label"><?php echo h($label); ?></div><div class="sh-value"<?php echo $label === '마지막 저장' ? ' style="font-size:13px;"' : ''; ?>><?php echo h($value); ?></div></div><?php endforeach; ?>
  </section>

  <form method="get" class="sh-card sh-filter">
    <input type="hidden" name="r" value="admin/ai_snapshot_history">
    <div class="sh-filter-grid">
      <div class="sh-field"><label>스냅샷 날짜</label><select name="snapshot_date"><option value="">전체</option><?php foreach ($options['dates'] as $date): ?><option value="<?php echo h($date); ?>"<?php echo $filters['snapshot_date'] === $date ? ' selected' : ''; ?>><?php echo h($date); ?></option><?php endforeach; ?></select></div>
      <div class="sh-field"><label>대상 월</label><select name="target_ym"><option value="">전체</option><?php foreach ($options['months'] as $ym): ?><option value="<?php echo h($ym); ?>"<?php echo $filters['target_ym'] === $ym ? ' selected' : ''; ?>><?php echo h($ym); ?></option><?php endforeach; ?></select></div>
      <div class="sh-field"><label>프로젝트</label><select name="project_id"><option value="0">전체</option><?php foreach ($options['projects'] as $project): $pid=(int)$project['project_id']; ?><option value="<?php echo $pid; ?>"<?php echo $filters['project_id'] === $pid ? ' selected' : ''; ?>><?php echo h(trim((string)$project['project_name']) !== '' ? $project['project_name'] : '프로젝트 #' . $pid); ?></option><?php endforeach; ?></select></div>
      <div class="sh-field"><label>프로젝트 상태</label><select name="project_status"><option value="">전체</option><?php foreach ($options['statuses'] as $statusRow): $statusValue=isset($statusRow['status'])?(string)$statusRow['status']:''; ?><option value="<?php echo h($statusValue); ?>"<?php echo $filters['project_status'] === $statusValue ? ' selected' : ''; ?>><?php echo h($statusValue); ?></option><?php endforeach; ?></select></div>
      <div class="sh-field"><label>현장명 검색</label><input type="text" name="q" value="<?php echo h($filters['q']); ?>" maxlength="100" placeholder="현장명"></div>
      <div class="sh-actions"><button class="sh-btn" type="submit">조회</button><a class="sh-btn secondary" href="?r=admin%2Fai_snapshot_history">초기화</a></div>
    </div>
  </form>

  <section class="sh-card sh-table-card">
    <div class="sh-table-wrap"><table class="sh-table">
      <thead><tr><th>현장명</th><th>상태</th><th>계약금액</th><th>월 매출</th><th>노무비</th><th>외주비</th><th>구매품</th><th>자재비</th><th>장비비</th><th>기타경비</th><th>안전·보건비</th><th>월 총투입</th><th>전일 대비</th><th>월 원가율</th><th>오늘 입력</th><th>최근 입력시각</th><th>상세</th></tr></thead>
      <tbody>
      <?php if (count($rows) === 0): ?><tr><td colspan="17" class="sh-empty"><?php echo $snapshotHistoryInstalled ? '조건에 해당하는 스냅샷이 없습니다.' : '테이블 설치 후 오늘 자료부터 표시됩니다.'; ?></td></tr>
      <?php else: foreach ($rows as $row):
        $flags=cpms_ai_snapshot_safe_detail(AiDailySnapshotService::decodeData(isset($row['data_flags'])?$row['data_flags']:''),'flags');
        $detail=cpms_ai_snapshot_safe_detail(AiDailySnapshotService::decodeData(isset($row['detail_data'])?$row['detail_data']:''),'detail');
        $safetyHealth=(float)$row['safety_amount']+(float)$row['health_amount'];
        $deltaText=cpms_ai_snapshot_delta($row['monthly_input_amount'],$row['previous_monthly_input_amount']);
      ?>
        <tr>
          <td><strong><?php echo h(trim((string)$row['project_name_snapshot']) !== '' ? $row['project_name_snapshot'] : '프로젝트 #' . (int)$row['project_id']); ?></strong><div style="margin-top:4px;color:#64748b;white-space:nowrap;"><?php echo h($row['snapshot_date']); ?></div></td>
          <td><span class="sh-badge"><?php echo h(trim((string)$row['project_status_snapshot']) !== '' ? $row['project_status_snapshot'] : '-'); ?></span></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_money($row['contract_amount'])); ?></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_money($row['monthly_sales_amount'])); ?></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_money($row['labor_amount'])); ?></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_money($row['outsourcing_amount'])); ?></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_money($row['purchase_amount'])); ?></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_money($row['material_amount'])); ?></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_money($row['equipment_amount'])); ?></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_money($row['other_expense_amount'])); ?></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_money($safetyHealth)); ?></td>
          <td class="money"><strong><?php echo h(cpms_ai_snapshot_money($row['monthly_input_amount'])); ?></strong></td>
          <td class="money" style="color:<?php echo strpos($deltaText,'+')===0?'#b45309':(strpos($deltaText,'-')===0?'#047857':'#475569'); ?>;"><?php echo h($deltaText); ?></td>
          <td class="money"><?php echo h(cpms_ai_snapshot_rate($row['monthly_cost_rate'])); ?></td>
          <td class="money"><?php echo h(number_format((int)$row['today_event_count'])); ?>건</td>
          <td style="white-space:nowrap;"><?php echo h($row['latest_event_at'] !== null ? $row['latest_event_at'] : '-'); ?></td>
          <td><details class="sh-detail"><summary>보기</summary><div class="sh-detail-grid">
            <div class="sh-detail-box"><h4>저장 정보</h4><table><tr><th>대상 월</th><td><?php echo h($row['target_ym']); ?></td></tr><tr><th>누적 매출</th><td><?php echo h(cpms_ai_snapshot_money($row['cumulative_sales_amount'])); ?></td></tr><tr><th>누적 투입</th><td><?php echo h(cpms_ai_snapshot_money($row['cumulative_input_amount'])); ?></td></tr><tr><th>누적 원가율</th><td><?php echo h(cpms_ai_snapshot_rate($row['cumulative_cost_rate'])); ?></td></tr><tr><th>월 이벤트</th><td><?php echo h(number_format((int)$row['month_event_count'])); ?>건</td></tr><tr><th>재계산 횟수</th><td><?php echo h(number_format((int)$row['capture_count'])); ?>회</td></tr><tr><th>최초 저장</th><td><?php echo h($row['first_captured_at']); ?></td></tr><tr><th>최근 저장</th><td><?php echo h($row['last_captured_at']); ?></td></tr></table></div>
            <div class="sh-detail-box"><h4>세부 비용</h4><table><tr><th>안전관리비</th><td><?php echo h(cpms_ai_snapshot_money($row['safety_amount'])); ?></td></tr><tr><th>보건비</th><td><?php echo h(cpms_ai_snapshot_money($row['health_amount'])); ?></td></tr><tr><th>기타 투입비</th><td><?php echo h(cpms_ai_snapshot_money($row['other_amount'])); ?></td></tr><tr><th>월 이익</th><td><?php echo h(cpms_ai_snapshot_money($row['monthly_profit_amount'])); ?></td></tr><tr><th>누적 이익</th><td><?php echo h(cpms_ai_snapshot_money($row['cumulative_profit_amount'])); ?></td></tr></table></div>
            <div class="sh-detail-box"><h4>데이터 상태</h4><table><?php if (count($flags)===0): ?><tr><td>상태 자료 없음</td></tr><?php else: foreach ($flags as $key=>$value): ?><tr><th><?php echo h($key); ?></th><td><?php echo h(cpms_ai_snapshot_nested_text($value)); ?></td></tr><?php endforeach; endif; ?></table></div>
            <div class="sh-detail-box"><h4>계산 기준</h4><table><?php if (count($detail)===0): ?><tr><td>세부 자료 없음</td></tr><?php else: foreach ($detail as $key=>$value): ?><tr><th><?php echo h($key); ?></th><td><?php echo h(cpms_ai_snapshot_nested_text($value)); ?></td></tr><?php endforeach; endif; ?></table></div>
          </div></details></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
    <nav class="sh-pagination" aria-label="페이지 이동"><?php if ($page>1): ?><a class="sh-page" href="<?php echo h($pageBase . '&page=' . ($page-1)); ?>">이전</a><?php endif; ?><span style="font-weight:900;"><?php echo h(number_format($page)); ?> / <?php echo h(number_format($totalPages)); ?></span><?php if ($page<$totalPages): ?><a class="sh-page" href="<?php echo h($pageBase . '&page=' . ($page+1)); ?>">다음</a><?php endif; ?></nav>
  </section>
</div>
