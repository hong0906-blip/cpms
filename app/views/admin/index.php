<?php
/**
 * C:\www\cpms\app\views\admin\index.php
 * - 관리부 탭 화면(직원명부/직영팀 설정/노무비 계산)
 * - PHP 5.6 호환
 */

use App\Core\Auth;

$canManage = Auth::canManageEmployees();
if (!$canManage) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (임원/관리 전용)</div>';
    return;
}

$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : '';
if ($tab === '') $tab = 'employees';

$tabs = array(
    'employees'     => array('label' => '직원명부', 'icon' => 'users'),
    'direct_team'   => array('label' => '직영팀 명부', 'icon' => 'clipboard-list'),
    'direct_rates'  => array('label' => '직영팀 설정', 'icon' => 'wallet'),
    'labor_calc'    => array('label' => '노무비 계산', 'icon' => 'calculator'),
);
if (!isset($tabs[$tab])) $tab = 'employees';

function admin_tab_url($tab) {
    // 메뉴(관리) 내부에서만 탭 이동
    return '?r=관리&tab=' . urlencode($tab);
}
?>

<div class="mb-6">
  <div class="text-sm text-gray-500">관리</div>
  <h2 class="text-2xl font-extrabold text-gray-900">관리부</h2>
  <div class="text-sm text-gray-500 mt-1">직원명부 / 직영팀 설정 / 노무비 계산을 한 화면에서 관리합니다.</div>
</div>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden mb-6">
  <div class="flex flex-wrap gap-2 p-3">
    <?php foreach ($tabs as $k => $t): ?>
      <?php $active = ($k === $tab); ?>
      <a href="<?php echo admin_tab_url($k); ?>"
         class="px-4 py-2 rounded-2xl border font-extrabold text-sm inline-flex items-center gap-2
                <?php echo $active ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'; ?>">
        <i data-lucide="<?php echo h($t['icon']); ?>" class="w-4 h-4"></i>
        <?php echo h($t['label']); ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php
// 탭별 화면 포함
$GLOBALS['__admin_embedded'] = true;

if ($tab === 'employees') {
    require __DIR__ . '/employees.php';
	} elseif ($tab === 'direct_team') {
	    require __DIR__ . '/direct_team.php';
} elseif ($tab === 'direct_rates') {
    require __DIR__ . '/direct_rates.php';
} else { // labor_calc
    require __DIR__ . '/labor_calc.php';
}

unset($GLOBALS['__admin_embedded']);
?>
