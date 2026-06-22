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
$summary = array();
$listFilters = $filters;
if ($overheadSection !== 'summary') $listFilters['category'] = $overheadSection;
$items = array();
if ($overheadSection !== 'payroll') {
    $summary = cpms_company_overhead_monthly_summary($filters);
    if ($overheadSection !== 'fuel' && $overheadSection !== 'vehicles') $items = cpms_company_overhead_list($listFilters);
}

$editItem = null;
if ($canEditCompanyOverhead && $overheadSection !== 'summary' && $overheadSection !== 'payroll' && $overheadSection !== 'fuel' && $overheadSection !== 'vehicles' && isset($_GET['edit'])) {
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
      <div class="text-sm text-gray-500">관리 / 총관리비</div>
      <h3 class="text-2xl font-extrabold text-gray-900">총관리비</h3>
      <div class="text-sm text-gray-500 mt-1">회사 전체 손익 계산에만 포함되는 월별 회사관리비입니다.</div>
    </div>
    <div class="px-3 py-2 rounded-xl border <?php echo $canEditCompanyOverhead ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600'; ?> text-sm font-bold">
      <?php echo $canEditCompanyOverhead ? '등록/수정 가능' : '조회 전용'; ?>
    </div>
  </div>

  <?php require __DIR__ . '/_tabs.php'; ?>

  <?php if ($overheadSection !== 'payroll' && $overheadSection !== 'fuel' && $overheadSection !== 'vehicles'): ?>
  <form method="get" action="" class="bg-white border border-gray-200 rounded-2xl p-4">
    <input type="hidden" name="r" value="관리">
    <input type="hidden" name="tab" value="company_overhead">
    <input type="hidden" name="oh" value="<?php echo h($overheadSection); ?>">
    <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">연도</span>
        <input type="number" name="year" min="2000" max="2100" value="<?php echo h((string)$filters['year']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">월</span>
        <select name="month" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <option value="0">전체</option>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo ((int)$filters['month'] === $m) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $m); ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">시작월</span>
        <input type="month" name="start_month" value="<?php echo h($filters['start_month']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">종료월</span>
        <input type="month" name="end_month" value="<?php echo h($filters['end_month']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">구분</span>
        <select name="category" class="w-full px-3 py-3 rounded-xl border border-gray-300">
          <option value="">전체</option>
          <?php foreach ($categories as $catKey => $catMeta): ?>
            <option value="<?php echo h($catKey); ?>" <?php echo ($filters['category'] === $catKey) ? 'selected' : ''; ?>><?php echo h($catMeta['label']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block text-sm font-bold text-gray-700">
        <span class="block mb-2">검색어</span>
        <input type="text" name="q" value="<?php echo h($filters['q']); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" placeholder="항목/지급처/메모">
      </label>
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
      <button type="submit" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">조회</button>
      <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=<?php echo urlencode($overheadSection); ?>" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">초기화</a>
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
  <?php else: ?>
    <?php require __DIR__ . '/form.php'; ?>
    <?php require __DIR__ . '/list.php'; ?>
  <?php endif; ?>
</div>
