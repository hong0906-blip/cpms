<?php
/**
 * C:\www\cpms\app\views\admin\index.php
 * - 관리부 탭 화면(직원명부/직영팀 설정/노무비 계산/출퇴근)
 * - PHP 5.6 호환
 */

use App\Core\Auth;

$canManage = (Auth::isMaster() || Auth::canManageEmployees());
if (!$canManage) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (임원/관리 전용)</div>';
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
    'attendance'   => array('label' => '출퇴근/근태관리', 'icon' => 'clock-3'),
);
if (!isset($tabs[$tab])) {
    $tab = 'employees';
}

function admin_tab_url($tab)
{
    return '?r=관리&tab=' . urlencode($tab);
}
?>

<details style="margin:0 0 12px 0;">
  <summary style="cursor:pointer;color:#555;font-size:12px;">관리부 디버그 정보 보기</summary>
  <div style="background:#fef3c7;border:1px solid #d97706;color:#7c2d12;padding:10px 12px;border-radius:8px;margin-top:8px;font-size:12px;line-height:1.6;">
    <div>ADMIN_INDEX_LOADED = 2026-관리탭-강제진단-01</div>
    <div>__FILE__: <?php echo h(__FILE__); ?></div>
    <div>$_GET['r']: <?php echo h(isset($_GET['r']) ? $_GET['r'] : ''); ?></div>
    <div>$_GET['tab']: <?php echo h(isset($_GET['tab']) ? $_GET['tab'] : ''); ?></div>
    <div>최종 $tab: <?php echo h($tab); ?></div>
    <div>$tabs keys: <?php echo h(implode(', ', array_keys($tabs))); ?></div>
    <div>Auth::isMaster(): <?php echo Auth::isMaster() ? 'true' : 'false'; ?></div>
    <div>Auth::canManageEmployees(): <?php echo Auth::canManageEmployees() ? 'true' : 'false'; ?></div>
  </div>
</details>

<div class="mb-6">
  <div class="text-sm text-gray-500">관리</div>
  <h2 class="text-2xl font-extrabold text-gray-900">관리부</h2>
  <div class="text-sm text-gray-500 mt-1">직원명부 / 직영팀 설정 / 노무비 계산 / 출퇴근·근태를 한 화면에서 관리합니다.</div>
</div>

<?php // 관리부 탭 UI 단순 버튼화 ?>
<div style="margin:0 0 16px 0; padding:12px; border:1px solid #e5e7eb; border-radius:12px; background:#fff;">
  <?php foreach ($tabs as $k => $t): ?>
    <?php $active = ($k === $tab); ?>
    <a href="<?php echo admin_tab_url($k); ?>" style="display:inline-block;margin:4px 6px 4px 0;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:700;<?php echo $active ? 'background:#166534;color:#fff;border:1px solid #166534;' : 'background:#fff;color:#4b5563;border:1px solid #d1d5db;'; ?>">
      <?php echo h($t['label']); ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($canManage): ?>
<!-- 긴급 바로가기 -->
<div style="background:#fee2e2;border:2px solid #b91c1c;color:#7f1d1d;padding:12px 14px;border-radius:10px;margin:0 0 16px 0;">
  <div style="font-weight:800;margin-bottom:8px;">긴급 바로가기</div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;">
    <a href="?r=관리&amp;tab=employees" style="display:inline-block;padding:8px 12px;border-radius:8px;background:#991b1b;color:#fff;font-weight:700;">직원명부 바로가기</a>
    <a href="?r=관리&amp;tab=attendance" style="display:inline-block;padding:8px 12px;border-radius:8px;background:#b91c1c;color:#fff;font-weight:700;">출퇴근/근태관리 바로가기</a>
    <a href="?r=db_setup_attendance" style="display:inline-block;padding:8px 12px;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:700;">출퇴근 DB 설정 바로가기</a>
  </div>
</div>
<?php endif; ?>

<?php
// 직원명부/출퇴근 탭 include 재확인
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
