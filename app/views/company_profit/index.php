<?php
$summary = (isset($companyProfitSummary) && is_array($companyProfitSummary)) ? $companyProfitSummary : array();
$filters = (isset($summary['filters']) && is_array($summary['filters'])) ? $summary['filters'] : array();
$totals = (isset($summary['totals']) && is_array($summary['totals'])) ? $summary['totals'] : array();
$projects = (isset($summary['projects']) && is_array($summary['projects'])) ? $summary['projects'] : array();
$periodRows = (isset($summary['period_rows']) && is_array($summary['period_rows'])) ? $summary['period_rows'] : array();
$overhead = (isset($summary['overhead']) && is_array($summary['overhead'])) ? $summary['overhead'] : array();
$errors = (isset($summary['errors']) && is_array($summary['errors'])) ? $summary['errors'] : array();

$scope = isset($filters['scope']) ? (string)$filters['scope'] : 'year';
$viewMode = isset($filters['view_mode']) ? (string)$filters['view_mode'] : 'monthly';
$selectedYear = isset($filters['year']) ? (int)$filters['year'] : (int)date('Y');
$selectedMonth = isset($filters['month']) ? (int)$filters['month'] : 0;
$selectedStatus = isset($filters['status']) ? (string)$filters['status'] : '';
$searchQuery = isset($filters['q']) ? (string)$filters['q'] : '';
$startMonth = isset($filters['start_month']) ? (string)$filters['start_month'] : sprintf('%04d-01', $selectedYear);
$endMonth = isset($filters['end_month']) ? (string)$filters['end_month'] : sprintf('%04d-12', $selectedYear);
$years = (isset($filters['years']) && is_array($filters['years'])) ? $filters['years'] : array((int)date('Y'));
$statusOptions = (isset($filters['status_options']) && is_array($filters['status_options'])) ? $filters['status_options'] : array();
?>

<style>
.cp-company-profit { color:#0f172a; }
.cp-company-profit .cp-filter { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; align-items:end; }
.cp-company-profit .cp-field label { display:block; margin-bottom:5px; font-size:12px; font-weight:800; color:#475569; }
.cp-company-profit .cp-field input,
.cp-company-profit .cp-field select { width:100%; border:1px solid #dbe3ef; border-radius:8px; padding:9px 10px; background:#fff; color:#0f172a; min-height:40px; }
.cp-company-profit .cp-action { display:flex; gap:8px; flex-wrap:wrap; }
.cp-company-profit .cp-btn { min-height:40px; border-radius:8px; padding:0 14px; display:inline-flex; align-items:center; gap:7px; font-weight:900; border:1px solid #2563eb; background:#2563eb; color:#fff; }
.cp-company-profit .cp-btn-sub { border-color:#dbe3ef; background:#fff; color:#334155; }
.cp-company-profit .cp-section { margin-top:18px; }
.cp-company-profit .cp-panel { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:16px; box-shadow:0 8px 22px rgba(15,23,42,.05); }
.cp-company-profit .cp-panel-title { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; }
.cp-company-profit .cp-panel-title h3 { margin:0; font-size:18px; font-weight:900; color:#0f172a; }
.cp-company-profit .cp-help { color:#64748b; font-size:12px; line-height:1.55; }
.cp-company-profit .cp-alert { border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:8px; padding:10px 12px; font-size:13px; font-weight:700; }
.cp-company-profit .cp-summary-grid { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:10px; }
.cp-company-profit .cp-summary-card { border:1px solid #e5e7eb; border-radius:8px; padding:14px; background:#fff; min-width:0; }
.cp-company-profit .cp-summary-card .label { font-size:12px; color:#64748b; font-weight:900; }
.cp-company-profit .cp-summary-card .value { margin-top:7px; font-size:20px; line-height:1.2; font-weight:900; color:#0f172a; overflow-wrap:anywhere; }
.cp-company-profit .cp-summary-card .sub { margin-top:6px; font-size:11px; color:#64748b; line-height:1.45; }
.cp-company-profit .cp-negative { color:#dc2626 !important; }
.cp-company-profit .cp-positive { color:#0369a1 !important; }
.cp-company-rate-normal { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
.cp-company-rate-warn { background:#fffbeb; color:#b45309; border-color:#fde68a; }
.cp-company-rate-danger { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
.cp-company-rate-loss { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.cp-company-profit .cp-rate-pill { display:inline-flex; align-items:center; justify-content:center; border:1px solid; border-radius:999px; padding:4px 8px; font-size:12px; font-weight:900; white-space:nowrap; }
.cp-company-profit .cp-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid #e5e7eb; border-radius:8px; background:#fff; }
.cp-company-profit table { width:100%; border-collapse:collapse; min-width:920px; font-size:13px; }
.cp-company-profit th { background:#f8fafc; color:#64748b; font-size:12px; text-align:left; padding:10px; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
.cp-company-profit td { padding:10px; border-bottom:1px solid #eef2f7; vertical-align:middle; white-space:nowrap; }
.cp-company-profit td.text-right, .cp-company-profit th.text-right { text-align:right; }
.cp-company-profit td[data-wrap="1"] { white-space:normal; min-width:180px; }
.cp-company-profit .cp-graph-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; padding-bottom:2px; }
.cp-company-profit .cp-bar-ledger { min-width:760px; display:flex; flex-direction:column; gap:12px; }
.cp-company-profit .cp-project-bar-row { display:grid; grid-template-columns:190px 1fr 105px 120px; gap:12px; align-items:center; }
.cp-company-profit .cp-project-name { font-weight:900; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cp-company-profit .cp-bars { display:flex; flex-direction:column; gap:6px; min-width:0; }
.cp-company-profit .cp-bar-line { display:grid; grid-template-columns:72px 1fr 112px; gap:8px; align-items:center; font-size:12px; color:#64748b; }
.cp-company-profit .cp-track { display:block; width:100%; height:13px; border-radius:999px; background:#eef2f7; overflow:hidden; }
.cp-company-profit .cp-fill { display:block; height:100%; border-radius:999px; min-width:2px; }
.cp-company-profit .cp-fill-sales { background:#2563eb; }
.cp-company-profit .cp-fill-cost { background:#f97316; }
.cp-company-profit .cp-period-chart { min-width:780px; width:100%; }
.cp-company-profit .cp-legend { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; color:#475569; font-size:12px; font-weight:800; }
.cp-company-profit .cp-legend span { display:inline-flex; align-items:center; gap:5px; }
.cp-company-profit .cp-dot { width:10px; height:10px; border-radius:2px; display:inline-block; }
@media (max-width: 1100px) {
  .cp-company-profit .cp-filter { grid-template-columns:repeat(3,minmax(0,1fr)); }
  .cp-company-profit .cp-summary-grid { grid-template-columns:repeat(3,minmax(0,1fr)); }
}
@media (max-width: 767px) {
  .cp-company-profit .cp-filter { grid-template-columns:1fr 1fr; }
  .cp-company-profit .cp-field-wide { grid-column:span 2; }
  .cp-company-profit .cp-action { grid-column:span 2; }
  .cp-company-profit .cp-summary-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .cp-company-profit .cp-summary-card { padding:12px; }
  .cp-company-profit .cp-summary-card .value { font-size:17px; }
  .cp-company-profit .cp-panel { padding:12px; }
  .cp-company-profit .cp-panel-title { display:block; }
}
</style>

<div class="cp-company-profit">
  <div class="cp-panel">
    <div class="cp-panel-title">
      <div>
        <h2 class="text-2xl font-extrabold text-gray-900">경영현황</h2>
      </div>
    </div>

    <form method="get" action="" class="cp-filter">
      <input type="hidden" name="r" value="company_profit">
      <div class="cp-field">
        <label>기간</label>
        <select name="scope">
          <option value="year" <?php echo $scope === 'year' ? 'selected' : ''; ?>>연도</option>
          <option value="all" <?php echo $scope === 'all' ? 'selected' : ''; ?>>전체기간</option>
        </select>
      </div>
      <div class="cp-field">
        <label>연도</label>
        <select name="year">
          <?php foreach ($years as $year): ?>
            <option value="<?php echo (int)$year; ?>" <?php echo ((int)$year === $selectedYear) ? 'selected' : ''; ?>><?php echo (int)$year; ?>년</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-field">
        <label>그래프 보기</label>
        <select name="view_mode">
          <option value="monthly" <?php echo $viewMode === 'monthly' ? 'selected' : ''; ?>>월별 보기</option>
          <option value="quarterly" <?php echo $viewMode === 'quarterly' ? 'selected' : ''; ?>>분기별 보기</option>
          <option value="yearly" <?php echo $viewMode === 'yearly' ? 'selected' : ''; ?>>연도별 보기</option>
        </select>
      </div>
      <div class="cp-action">
        <button type="submit" class="cp-btn"><i data-lucide="search"></i>조회</button>
        <a href="?r=company_profit" class="cp-btn cp-btn-sub"><i data-lucide="rotate-ccw"></i>초기화</a>
      </div>
    </form>
  </div>

  <?php if (count($errors) > 0): ?>
    <div class="cp-section cp-alert">
      <?php foreach ($errors as $msg): ?>
        <div><?php echo h($msg); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php require __DIR__ . '/_summary_cards.php'; ?>
  <?php require __DIR__ . '/_safety_cost_summary.php'; ?>
  <?php require __DIR__ . '/_period_graph.php'; ?>
  <?php require __DIR__ . '/_horizontal_graph.php'; ?>
  <?php require __DIR__ . '/_project_profit_rows.php'; ?>
</div>
