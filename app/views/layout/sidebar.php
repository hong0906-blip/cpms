<?php
/**
 * C:\www\cpms\app\views\layout\sidebar.php
 * - 좌하단 유저 표시: (임원) 임원 · 직급 / (직원) 부서 · 직급
 * - ✅ photo_path 있으면 좌하단 아바타에 사진 표시
 */

$route = isset($_GET['r']) ? (string)$_GET['r'] : '대시보드';
$selectedMenu = isset($selectedMenu) ? (string)$selectedMenu : $route;

$user = \App\Core\Auth::user();
$role = \App\Core\Auth::userRole();

$dashboardType = isset($dashboardType) ? (string)$dashboardType : (isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee');
if ($dashboardType !== 'employee' && $dashboardType !== 'executive') $dashboardType = 'employee';

// 이름 첫 글자(서버 mbstring 없을 때 대비)
$initial = '홍';
if ($user && isset($user['name']) && $user['name'] !== '') {
    if (function_exists('mb_substr')) {
        $initial = mb_substr($user['name'], 0, 1, 'UTF-8');
    } else {
        $initial = substr($user['name'], 0, 1);
    }
}
$userName = $user && isset($user['name']) ? $user['name'] : '홍길동';

// ✅ 사용자 사진 경로
$userPhoto = null;
if ($user && isset($user['photo_path']) && trim((string)$user['photo_path']) !== '') {
    $userPhoto = trim((string)$user['photo_path']);
}

// ==========================
// 부서/직급 표시 만들기
// - 임원: "임원 · 직급"
// - 직원: "부서 · 직급"
// ==========================
$dept = \App\Core\Auth::userDepartment();
$pos  = '';
if ($user && isset($user['position'])) $pos = (string)$user['position'];

$dept = trim((string)$dept);
$deptMap = array(
  '관리부' => '관리',
  '공무부' => '공무',
  '품질부' => '품질',
  '안전부' => '안전',
  '공사부' => '공사',
  '안전/보건' => '안전',
  '안전보건' => '안전',
);
if (isset($deptMap[$dept])) $dept = $deptMap[$dept];
if (substr($dept, -1) === '부') $dept = substr($dept, 0, -1);
$dept = trim($dept);

$pos = trim((string)$pos);

$parts = array();
if ($role === 'executive') $parts[] = '임원';
else $parts[] = ($dept !== '' ? $dept : '직원');
if ($pos !== '') $parts[] = $pos;
$userDept = implode(' · ', $parts);

$menuItems = array(
  array('id'=>'대시보드','label'=>'대시보드','icon'=>'layout-dashboard','gradient'=>'from-blue-500 to-cyan-500','iconBg'=>'bg-gradient-to-br from-blue-100 to-cyan-100','iconColor'=>'text-blue-600','hoverShadow'=>'hover:shadow-blue-200'),
  array('id'=>'전자결재','label'=>'전자결재','icon'=>'file-check','gradient'=>'from-indigo-500 to-purple-500','iconBg'=>'bg-gradient-to-br from-indigo-100 to-purple-100','iconColor'=>'text-indigo-600','hoverShadow'=>'hover:shadow-indigo-200'),
  array('id'=>'공무','label'=>'공무','icon'=>'scroll-text','gradient'=>'from-orange-500 to-amber-500','iconBg'=>'bg-gradient-to-br from-orange-100 to-amber-100','iconColor'=>'text-orange-600','hoverShadow'=>'hover:shadow-orange-200'),
  array('id'=>'관리','label'=>'관리','icon'=>'bar-chart-3','gradient'=>'from-emerald-500 to-teal-500','iconBg'=>'bg-gradient-to-br from-emerald-100 to-teal-100','iconColor'=>'text-emerald-600','hoverShadow'=>'hover:shadow-emerald-200'),
  array('id'=>'공사','label'=>'공사','icon'=>'hard-hat','gradient'=>'from-yellow-500 to-orange-500','iconBg'=>'bg-gradient-to-br from-yellow-100 to-orange-100','iconColor'=>'text-yellow-600','hoverShadow'=>'hover:shadow-yellow-200'),
  array('id'=>'안전/보건','label'=>'안전/보건','icon'=>'shield-alert','gradient'=>'from-red-500 to-rose-500','iconBg'=>'bg-gradient-to-br from-red-100 to-rose-100','iconColor'=>'text-red-600','hoverShadow'=>'hover:shadow-red-200'),
  array('id'=>'품질','label'=>'품질','icon'=>'award','gradient'=>'from-cyan-500 to-blue-500','iconBg'=>'bg-gradient-to-br from-cyan-100 to-blue-100','iconColor'=>'text-cyan-600','hoverShadow'=>'hover:shadow-cyan-200'),
);

// App.tsx 헤더 타이틀 로직
$pageTitle = $selectedMenu;
if ($selectedMenu === '대시보드') {
    $pageTitle = ($dashboardType === 'employee') ? '내 대시보드' : '업무 대시보드 (관리부)';
}
?>

<!-- Sidebar.tsx -->
<aside id="cpmsSidebar"
       data-collapsed="0"
       class="w-72 bg-gradient-to-b from-gray-50 to-white backdrop-blur-xl flex flex-col shadow-sm transition-all duration-300 relative">
  <!-- Toggle Button -->
  <button
    type="button"
    id="sidebarToggle"
    class="absolute -right-3 top-8 w-6 h-6 bg-white border-2 border-gray-200 rounded-full flex items-center justify-center hover:bg-blue-50 hover:border-blue-300 transition-all duration-300 shadow-md z-10"
    aria-label="toggle sidebar"
  >
    <i data-lucide="chevron-left" class="w-4 h-4 text-gray-600"></i>
  </button>

  <!-- Logo -->
  <div class="p-6 px-6 when-expanded">
    <div class="flex items-center gap-3">
      <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-600 flex items-center justify-center text-white font-black">
        CM
      </div>
      <span class="font-bold text-xl bg-gradient-to-r from-blue-600 to-cyan-600 bg-clip-text text-transparent">창명건설</span>
    </div>
  </div>

  <!-- Collapsed Logo -->
  <div class="p-6 px-4 when-collapsed">
    <div class="flex items-center justify-center">
      <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-600 flex items-center justify-center text-white font-black text-sm">
        CM
      </div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="flex-1 py-2 px-4 when-expanded">
    <ul class="space-y-2">
      <?php foreach ($menuItems as $it): ?>
        <?php $isSelected = ($selectedMenu === $it['id']); ?>
        <li>
          <a
            href="<?php echo h('?r=' . urlencode($it['id'])); ?>"
            class="w-full flex items-center gap-3 px-4 py-3.5 rounded-2xl transition-all duration-300 group relative
              <?php echo $isSelected
                ? ('bg-gradient-to-r ' . $it['gradient'] . ' text-white shadow-lg scale-[1.02]')
                : ('text-gray-700 hover:bg-white/80 hover:shadow-md ' . $it['hoverShadow']); ?>"
          >
            <div class="p-2 rounded-xl transition-all duration-300 <?php echo $isSelected ? 'bg-white/20' : $it['iconBg']; ?>">
              <i data-lucide="<?php echo h($it['icon']); ?>" class="w-5 h-5 <?php echo $isSelected ? 'text-white' : h($it['iconColor']); ?>"></i>
            </div>
            <span class="font-semibold"><?php echo h($it['label']); ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <!-- Navigation (Collapsed with Tooltip) -->
  <nav class="flex-1 py-2 px-2 when-collapsed">
    <ul class="space-y-2">
      <?php foreach ($menuItems as $it): ?>
        <?php $isSelected = ($selectedMenu === $it['id']); ?>
        <li>
          <a
            href="<?php echo h('?r=' . urlencode($it['id'])); ?>"
            class="w-full flex items-center justify-center px-2 py-3.5 rounded-2xl transition-all duration-300 group relative
              <?php echo $isSelected
                ? ('bg-gradient-to-r ' . $it['gradient'] . ' text-white shadow-lg scale-[1.02]')
                : ('text-gray-700 hover:bg-white/80 hover:shadow-md ' . $it['hoverShadow']); ?>"
            title="<?php echo h($it['label']); ?>"
          >
            <div class="p-2 rounded-xl transition-all duration-300 <?php echo $isSelected ? 'bg-white/20' : $it['iconBg']; ?>">
              <i data-lucide="<?php echo h($it['icon']); ?>" class="w-5 h-5 <?php echo $isSelected ? 'text-white' : h($it['iconColor']); ?>"></i>
            </div>

            <div class="absolute left-full ml-2 px-3 py-2 bg-gray-900 text-white text-sm font-semibold rounded-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap shadow-lg z-50">
              <?php echo h($it['label']); ?>
              <div class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-gray-900"></div>
            </div>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <!-- User Section (Expanded) -->
  <div class="p-4 m-4 bg-white rounded-2xl shadow-sm border border-gray-100 when-expanded">
    <div class="flex items-center gap-3">
      <div class="w-12 h-12 rounded-full flex items-center justify-center shadow-md overflow-hidden bg-gradient-to-br from-blue-500 to-cyan-500">
        <?php if ($userPhoto): ?>
          <img src="<?php echo h($userPhoto); ?>" alt="profile" class="w-full h-full object-cover">
        <?php else: ?>
          <span class="text-white font-bold"><?php echo h($initial); ?></span>
        <?php endif; ?>
      </div>
      <div class="flex-1">
        <div class="font-semibold text-gray-900"><?php echo h($userName); ?></div>
        <div class="text-xs text-gray-500"><?php echo h($userDept); ?></div>
      </div>
      <div class="w-2 h-2 bg-green-500 rounded-full shadow-sm shadow-green-500/50"></div>
    </div>
  </div>

  <!-- User Section (Collapsed with Tooltip) -->
  <div class="p-2 m-2 bg-white rounded-2xl shadow-sm border border-gray-100 flex justify-center when-collapsed">
    <div class="w-10 h-10 rounded-full flex items-center justify-center shadow-md relative group overflow-hidden bg-gradient-to-br from-blue-500 to-cyan-500">
      <?php if ($userPhoto): ?>
        <img src="<?php echo h($userPhoto); ?>" alt="profile" class="w-full h-full object-cover">
      <?php else: ?>
        <span class="text-white font-bold text-sm"><?php echo h($initial); ?></span>
      <?php endif; ?>

      <div class="w-2 h-2 bg-green-500 rounded-full absolute -top-0.5 -right-0.5 border-2 border-white shadow-sm shadow-green-500/50"></div>

      <div class="absolute left-full ml-2 px-3 py-2 bg-gray-900 text-white text-sm font-semibold rounded-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap shadow-lg z-50">
        <?php echo h($userName . ' (' . $userDept . ')'); ?>
        <div class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-gray-900"></div>
      </div>
    </div>
  </div>
</aside>

<!-- Right Content -->
<div class="flex-1 flex flex-col overflow-hidden">
  <header class="bg-white/70 backdrop-blur-xl border-b border-gray-200/50 px-8 py-4 flex items-center justify-between">
    <div class="flex items-center gap-4">
      <h1 class="text-2xl font-bold bg-gradient-to-r from-gray-900 to-gray-600 bg-clip-text text-transparent">
        <?php echo h($pageTitle); ?>
      </h1>
    </div>

    <div class="flex items-center gap-4">
      <?php if ($selectedMenu === '대시보드' && $role === 'executive'): ?>
        <div class="flex gap-1 bg-gray-100/80 backdrop-blur-sm rounded-2xl p-1 shadow-sm">
          <a href="<?php echo h('?r=대시보드&dv=employee'); ?>"
             class="px-5 py-2 rounded-xl text-sm font-semibold transition-all duration-300 <?php echo ($dashboardType === 'employee') ? 'bg-white text-blue-600 shadow-md shadow-blue-500/10' : 'text-gray-600 hover:text-gray-900'; ?>">
            직원용
          </a>
          <a href="<?php echo h('?r=대시보드&dv=executive'); ?>"
             class="px-5 py-2 rounded-xl text-sm font-semibold transition-all duration-300 <?php echo ($dashboardType === 'executive') ? 'bg-white text-blue-600 shadow-md shadow-blue-500/10' : 'text-gray-600 hover:text-gray-900'; ?>">
            임원용
          </a>
        </div>
      <?php endif; ?>

      <div class="flex items-center gap-3 text-sm bg-white/60 backdrop-blur-sm px-4 py-2 rounded-2xl border border-gray-200/50">
        <span class="font-semibold text-gray-900"><?php echo h($userName); ?></span>
        <span class="text-gray-300">|</span>
        <span class="text-blue-600 font-medium"><?php echo ($role === 'executive') ? '임원' : '직원'; ?></span>
        <span class="text-gray-300">|</span>
        <a href="?r=logout" class="text-gray-600 hover:text-red-600 font-medium transition-colors">로그아웃</a>
      </div>
    </div>
  </header>

  <main class="flex-1 overflow-y-auto overflow-x-hidden p-8">
    <?php if (!empty($flash) && is_array($flash)): ?>
      <?php $cls = ($flash['type'] === 'danger') ? 'bg-red-50 border-red-200 text-red-700' : 'bg-slate-50 border-slate-200 text-slate-700'; ?>
      <div class="mb-4 rounded-2xl border px-4 py-3 <?php echo h($cls); ?>">
        <?php echo h($flash['message']); ?>
      </div>
    <?php endif; ?>