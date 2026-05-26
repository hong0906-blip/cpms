<?php
/**
 * C:\www\cpms\app\views\admin\index.php
 * - 관리부 탭 화면(직원명부/직영팀 설정/노무비 계산/출퇴근)
 * - PHP 5.6 호환
 */

use App\Core\Auth;

$canManage = (Auth::isMaster() || Auth::canManageEmployees());
if (!$canManage) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (임원/관리부 전용)</div>';
    return;
}

$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : '';
if ($tab === '') {
    $tab = 'employees';
}

$tabs = array(
    'employees'    => array('label' => '직원명부', 'icon' => 'users'),
    'direct_team'  => array('label' => '직영팀 명부', 'icon' => 'clipboard-list'),
    'direct_rates' => array('label' => '직영팀 설정', 'icon' => 'wallet'),
    'labor_calc'   => array('label' => '노무비 계산', 'icon' => 'calculator'),
    'attendance'   => array('label' => '출퇴근·근태관리', 'icon' => 'clock-3'),
);
if (!isset($tabs[$tab])) {
    $tab = 'employees';
}

function admin_tab_url($tab)
{
    return '?r=관리&tab=' . urlencode($tab);
}
?>

<div class="mb-6">
  <div class="text-sm text-gray-500">관리</div>
  <h2 class="text-2xl font-extrabold text-gray-900">관리부</h2>
  <div class="text-sm text-gray-500 mt-1">직원명부 / 직영팀 설정 / 노무비 계산 / 출퇴근·근태를 한 화면에서 관리합니다.</div>
</div>

<div style="margin:0 0 16px 0; padding:12px; border:1px solid #e5e7eb; border-radius:12px; background:#fff;">
  <?php foreach ($tabs as $k => $t): ?>
    <?php $active = ($k === $tab); ?>
    <a href="<?php echo admin_tab_url($k); ?>" style="display:inline-block;margin:4px 6px 4px 0;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:700;<?php echo $active ? 'background:#166534;color:#fff;border:1px solid #166534;' : 'background:#fff;color:#4b5563;border:1px solid #d1d5db;'; ?>">
      <?php echo h($t['label']); ?>
    </a>
  <?php endforeach; ?>
</div>

<?php
$GLOBALS['__admin_embedded'] = true;

if ($tab === 'employees') {
    require __DIR__ . '/employees.php';
} elseif ($tab === 'direct_team') {
    require __DIR__ . '/direct_team.php';
} elseif ($tab === 'direct_rates') {
    require __DIR__ . '/direct_rates.php';
} elseif ($tab === 'labor_calc') {
    require __DIR__ . '/labor_calc.php';
} elseif ($tab === 'attendance') {
    require __DIR__ . '/attendance.php';
} else {
    require __DIR__ . '/employees.php';
}

unset($GLOBALS['__admin_embedded']);
?>
