<?php
/**
 * Company overhead management UI.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../../services/CompanyProfitAccessService.php';
require_once __DIR__ . '/../../../services/CompanyOverheadService.php';
require_once __DIR__ . '/../../../services/CompanyPayrollAccessService.php';
require_once __DIR__ . '/../../../services/CompanyFuelService.php';
require_once __DIR__ . '/../../../services/CompanyVehicleService.php';

$overheadPdo = Db::pdo();
$overheadUser = Auth::user();
$canViewCompanyOverhead = cpms_can_view_company_overhead($overheadUser, $overheadPdo);
$canEditCompanyOverhead = cpms_can_edit_company_overhead($overheadUser, $overheadPdo);
$canViewCompanyPayroll = cpms_can_view_company_payroll($overheadUser, $overheadPdo);
$canEditCompanyPayroll = cpms_can_edit_company_payroll($overheadUser, $overheadPdo);

$categories = cpms_company_overhead_categories();
$overheadSection = isset($_GET['oh']) ? trim((string)$_GET['oh']) : 'summary';
if ($overheadSection === '') $overheadSection = 'summary';
if (!$canViewCompanyOverhead && $canViewCompanyPayroll && $overheadSection === 'summary') $overheadSection = 'payroll';
if ($overheadSection !== 'summary' && !isset($categories[$overheadSection])) $overheadSection = 'summary';

if (!$canViewCompanyOverhead && !($overheadSection === 'payroll' && $canViewCompanyPayroll)) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다.</div>';
    return;
}

if ($overheadSection === 'payroll' && !$canViewCompanyPayroll) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">임직원 월급 관리 접근 권한이 없습니다.</div>';
    return;
}

$filters = cpms_company_overhead_normalize_filters($_GET);
if ($overheadSection === 'summary') {
    $filterYear = isset($filters['year']) ? (int)$filters['year'] : (int)date('Y');
    $filters['month'] = 0;
    $filters['start_month'] = sprintf('%04d-01', $filterYear);
    $filters['end_month'] = ($filterYear === (int)date('Y')) ? cpms_company_overhead_current_month() : sprintf('%04d-12', $filterYear);
    $filters['category'] = '';
    $filters['q'] = '';
} elseif ($overheadSection === 'lease') {
    $filterYear = isset($filters['year']) ? (int)$filters['year'] : (int)date('Y');
    $filterMonth = isset($filters['month']) ? (int)$filters['month'] : 0;
    if ($filterMonth < 1 || $filterMonth > 12) $filterMonth = ($filterYear === (int)date('Y')) ? (int)date('m') : 1;
    $filters['month'] = $filterMonth;
    $filters['start_month'] = sprintf('%04d-%02d', $filterYear, $filterMonth);
    $filters['end_month'] = $filters['start_month'];
    $filters['category'] = '';
    $filters['q'] = '';
}
$summary = array();
$listFilters = $filters;
if ($overheadSection !== 'summary') $listFilters['category'] = $overheadSection;
$items = array();
if ($overheadSection !== 'payroll') {
    $summary = cpms_company_overhead_monthly_summary($filters);
    if ($overheadSection !== 'fuel' && $overheadSection !== 'vehicles' && $overheadSection !== 'corporate_cards') $items = cpms_company_overhead_list($listFilters);
}

$editItem = null;
if ($canEditCompanyOverhead && $overheadSection !== 'summary' && $overheadSection !== 'payroll' && $overheadSection !== 'fuel' && $overheadSection !== 'vehicles' && $overheadSection !== 'corporate_cards' && isset($_GET['edit'])) {
    $editId = trim((string)$_GET['edit']);
    if ($editId !== '') {
        $editItem = cpms_company_overhead_find($overheadSection, $editId, isset($_GET['edit_year']) ? (int)$_GET['edit_year'] : 0, isset($_GET['edit_month']) ? (int)$_GET['edit_month'] : 0);
    }
}

if (!function_exists('cpms_overhead_view_money')) {
function cpms_overhead_view_money($amount) {
    return number_format((float)$amount);
}}

if (!function_exists('cpms_overhead_view_val')) {
function cpms_overhead_view_val($row, $key, $default) {
    if (is_array($row) && isset($row[$key])) return $row[$key];
    return $default;
}}
?>

<div class="space-y-5">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h3 class="text-2xl font-extrabold text-gray-900">총관리비</h3>
    </div>
    <div class="px-3 py-2 rounded-xl border <?php echo $canEditCompanyOverhead ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600'; ?> text-sm font-bold">
      <?php echo $canEditCompanyOverhead ? '등록/수정 가능' : '조회 전용'; ?>
    </div>
  </div>

  <?php require __DIR__ . '/_tabs.php'; ?>

  <?php if ($overheadSection !== 'payroll' && $overheadSection !== 'fuel' && $overheadSection !== 'vehicles' && $overheadSection !== 'corporate_cards'): ?>
  <form method="get" action="" class="bg-white border border-gray-200 rounded-2xl p-4">
    <input type="hidden" name="r" value="관리">
    <input type="hidden" name="tab" value="company_overhead">
    <input type="hidden" name="oh" value="<?php echo h($overheadSection); ?>">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">연도</span>
        <input type="number" name="year" min="2000" max="2100" value="<?php echo h((string)$filters['year']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <?php if ($overheadSection === 'lease'): ?>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">월</span>
          <select name="month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
            <?php for ($searchMonth = 1; $searchMonth <= 12; $searchMonth++): ?>
              <option value="<?php echo $searchMonth; ?>" <?php echo ((int)$filters['month'] === $searchMonth) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $searchMonth); ?></option>
            <?php endfor; ?>
          </select>
        </label>
      <?php endif; ?>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">조회</button>
        <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=<?php echo urlencode($overheadSection); ?>" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">초기화</a>
      </div>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($overheadSection === 'summary'): ?>
    <?php require __DIR__ . '/_summary_cards.php'; ?>
    <?php require __DIR__ . '/_bar_graph.php'; ?>
    <?php require __DIR__ . '/_monthly_table.php'; ?>
  <?php elseif ($overheadSection === 'payroll'): ?>
    <?php require __DIR__ . '/payroll.php'; ?>
  <?php elseif ($overheadSection === 'vehicles'): ?>
    <?php require __DIR__ . '/company_vehicles.php'; ?>
  <?php elseif ($overheadSection === 'fuel'): ?>
    <?php require __DIR__ . '/fuel.php'; ?>
  <?php elseif ($overheadSection === 'corporate_cards'): ?>
    <?php require __DIR__ . '/corporate_cards.php'; ?>
  <?php elseif ($overheadSection === 'lease'): ?>
    <?php require __DIR__ . '/lease.php'; ?>
  <?php else: ?>
    <?php require __DIR__ . '/form.php'; ?>
    <?php require __DIR__ . '/list.php'; ?>
  <?php endif; ?>
</div>
