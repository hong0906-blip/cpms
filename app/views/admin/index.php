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

<?php if ($canManage): ?>
<!-- 관리부 실제 렌더링 확인 - 확인 후 제거 가능 -->
<div style="background:#fef3c7;border:2px solid #dc2626;color:#7f1d1d;padding:12px 14px;border-radius:10px;margin:0 0 16px 0;font-size:13px;line-height:1.6;">
  <div style="font-weight:800;margin-bottom:6px;">ADMIN_INDEX_LOADED = 2026-관리탭-강제진단-01</div>
  <div style="font-weight:700;">ADMIN_INDEX_VERSION = 2026-관리탭-강제진단-01</div>
  <!-- OPcache/서버 캐시 확인 문구 -->
  <div style="margin:6px 0 10px 0;font-weight:700;">이 문구가 화면에 안 보이면 PHP가 최신 파일을 실행하지 않는 것입니다. OPcache/서버 캐시/다른 경로 실행을 확인하세요.</div>
  <div>__FILE__: <?php echo h(__FILE__); ?></div>
  <div>$_SERVER['SCRIPT_FILENAME']: <?php echo h(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : ''); ?></div>
  <div>$_GET['r']: <?php echo h(isset($_GET['r']) ? $_GET['r'] : ''); ?></div>
  <div>$_GET['tab']: <?php echo h(isset($_GET['tab']) ? $_GET['tab'] : ''); ?></div>
  <div>최종 $tab: <?php echo h($tab); ?></div>
  <div>$tabs keys: <?php echo h(implode(', ', array_keys($tabs))); ?></div>
  <div>Auth::userEmail(): <?php echo h((string)Auth::userEmail()); ?></div>
  <div>Auth::userRole(): <?php echo h((string)Auth::userRole()); ?></div>
  <div>Auth::userDepartment(): <?php echo h((string)Auth::userDepartment()); ?></div>
  <div>Auth::isMaster(): <?php echo Auth::isMaster() ? 'true' : 'false'; ?></div>
  <div>Auth::canManageEmployees(): <?php echo Auth::canManageEmployees() ? 'true' : 'false'; ?></div>
</div>
<?php endif; ?>

<div class="mb-6">
  <div class="text-sm text-gray-500">관리</div>
  <h2 class="text-2xl font-extrabold text-gray-900">관리부</h2>
  <div class="text-sm text-gray-500 mt-1">직원명부 / 직영팀 설정 / 노무비 계산 / 출퇴근·근태를 한 화면에서 관리합니다.</div>
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
