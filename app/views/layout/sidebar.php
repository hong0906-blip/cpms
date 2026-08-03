<?php
/**
 * C:\www\cpms\app\views\layout\sidebar.php
 */

$dashboardMenu = '대시보드';
$employeeDirectoryMenu = '임직원';
$noticeMenu = '공지사항';
$approvalMenu = '전자결재';
$workMenu = '공무';
$manageMenu = '관리';
$constructionMenu = '공사';
$safetyMenu = '안전/보건';
$qualityMenu = '품질';
$companyProfitMenu = '경영현황';
$representativeManagementMenu = '대표 경영현황';
$ceoIndexMenu = 'CEO Index';
$usageAnalyticsMenu = '사용현황 분석';
$schedulerMenu = urldecode('%EC%8A%A4%EC%BC%80%EC%A4%84%EB%9F%AC');

$route = isset($_GET['r']) ? (string)$_GET['r'] : $dashboardMenu;
$selectedMenu = isset($selectedMenu) ? (string)$selectedMenu : $route;

$user = \App\Core\Auth::user();
$role = \App\Core\Auth::userRole();
$displayRole = method_exists('App\\Core\\Auth', 'userStoredRole') ? \App\Core\Auth::userStoredRole() : $role;
require_once __DIR__ . '/../../services/CompanyProfitAccessService.php';
require_once __DIR__ . '/../../services/CompanyPayrollAccessService.php';
require_once __DIR__ . '/../../services/RepresentativeManagementService.php';
$sidebarPdo = \App\Core\Db::pdo();
$canViewCompanyProfitMenu = cpms_can_view_company_profit($user, $sidebarPdo);
$isMasterUser = \App\Core\Auth::isMaster();
if ($isMasterUser) $canViewCompanyProfitMenu = true;
$canViewCompanyOverheadMenu = cpms_can_view_company_overhead($user, $sidebarPdo);
$canViewCompanyPayrollMenu = cpms_can_view_company_payroll($user, $sidebarPdo);
$canAccessUsageAnalytics = \App\Core\Auth::canAccessUsageAnalytics();
$canViewRepresentativeManagement = cpms_can_view_representative_management($sidebarPdo, $user);
$canAccessCeoIndex = method_exists('App\\Core\\Auth', 'canAccessCeoIndex') ? \App\Core\Auth::canAccessCeoIndex() : false;

$dashboardType = isset($dashboardType) ? (string)$dashboardType : (isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee');
if ($dashboardType !== 'employee' && $dashboardType !== 'executive') $dashboardType = 'employee';
$canSwitchDashboardViews = method_exists('App\\Core\\Auth', 'canSwitchDashboardViews')
  ? \App\Core\Auth::canSwitchDashboardViews()
  : false;
$dashboardHref = ($role === 'executive') ? '?r=dashboard_executive' : '?r=dashboard_employee';
if ($canSwitchDashboardViews && $dashboardType === 'employee') $dashboardHref = '?r=dashboard_employee';
$googleEmailIcon = 'https://www.google.com/s2/favicons?domain=mail.google.com&sz=64';
$googleChatIcon = 'https://www.google.com/s2/favicons?domain=chat.google.com&sz=64';

$initial = '?';
if ($user && isset($user['name']) && $user['name'] !== '') {
    if (function_exists('mb_substr')) {
        $initial = mb_substr($user['name'], 0, 1, 'UTF-8');
    } else {
        $initial = substr($user['name'], 0, 1);
    }
}
$userName = $user && isset($user['name']) ? $user['name'] : '사용자';

$userPhoto = null;
if ($user && isset($user['photo_path']) && trim((string)$user['photo_path']) !== '') {
    $userPhoto = trim((string)$user['photo_path']);
}

$dept = trim((string)\App\Core\Auth::userDepartment());
$pos = ($user && isset($user['position'])) ? trim((string)$user['position']) : '';
$deptMap = array(
  '관리부' => '관리',
  '공무부' => '공무',
  '공무팀' => '공무',
  '공무부서' => '공무',
  '개발부' => '개발',
  '개발팀' => '개발',
  '개발부서' => '개발',
  '품질부' => '품질',
  '안전부' => '안전',
  '공사부' => '공사',
  '안전보건' => '안전',
  '안전/보건' => '안전',
);
if (isset($deptMap[$dept])) $dept = $deptMap[$dept];
if (substr($dept, -1) === '부') $dept = substr($dept, 0, -1);
$isPublicAffairsDept = ($dept === '공무' && !$isMasterUser);
$canViewPublicAffairsMobileMenu = method_exists('App\\Core\\Auth', 'canAccessPublicAffairsMobile')
  ? \App\Core\Auth::canAccessPublicAffairsMobile()
  : ($dept === '공무' || $canAccessUsageAnalytics);

$parts = array();
if ($displayRole === 'executive') $parts[] = '임원';
else $parts[] = ($dept !== '' ? $dept : '직원');
if ($pos !== '') $parts[] = $pos;
$userDept = implode(' · ', $parts);

$googleShortcutMenuItems = array(
  array('id'=>'google_email_shortcut','label'=>'이메일 바로가기','href'=>'https://mail.google.com/','target'=>'_blank','rel'=>'noopener noreferrer','iconImg'=>$googleEmailIcon,'iconAlt'=>'Gmail','gradient'=>'from-red-500 to-amber-500','itemBg'=>'bg-rose-50/60','iconBg'=>'bg-white','iconColor'=>'text-red-600','hoverShadow'=>'hover:shadow-red-100'),
  array('id'=>'google_chat_shortcut','label'=>'채팅 바로가기','href'=>'https://chat.google.com/','target'=>'_blank','rel'=>'noopener noreferrer','iconImg'=>$googleChatIcon,'iconAlt'=>'Google Chat','gradient'=>'from-emerald-500 to-blue-500','itemBg'=>'bg-emerald-50/60','iconBg'=>'bg-white','iconColor'=>'text-emerald-600','hoverShadow'=>'hover:shadow-emerald-100'),
);

$menuItems = array(
  array('id'=>$dashboardMenu,'label'=>$dashboardMenu,'icon'=>'layout-dashboard','gradient'=>'from-blue-500 to-cyan-500','iconBg'=>'bg-gradient-to-br from-blue-100 to-cyan-100','iconColor'=>'text-blue-600','hoverShadow'=>'hover:shadow-blue-200'),
  array('id'=>$schedulerMenu,'label'=>$schedulerMenu,'href'=>'?r=scheduler','icon'=>'calendar-days','gradient'=>'from-teal-500 to-cyan-500','iconBg'=>'bg-gradient-to-br from-teal-100 to-cyan-100','iconColor'=>'text-teal-600','hoverShadow'=>'hover:shadow-teal-200'),
  array('id'=>$employeeDirectoryMenu,'label'=>$employeeDirectoryMenu,'href'=>'?r=employees_directory','icon'=>'users','gradient'=>'from-slate-700 to-gray-600','iconBg'=>'bg-gradient-to-br from-slate-100 to-gray-100','iconColor'=>'text-slate-700','hoverShadow'=>'hover:shadow-slate-200'),
  array('id'=>$noticeMenu,'label'=>$noticeMenu,'icon'=>'megaphone','gradient'=>'from-sky-500 to-blue-500','iconBg'=>'bg-gradient-to-br from-sky-100 to-blue-100','iconColor'=>'text-sky-600','hoverShadow'=>'hover:shadow-sky-200'),
  array('id'=>$approvalMenu,'label'=>$approvalMenu,'icon'=>'file-check','gradient'=>'from-indigo-500 to-purple-500','iconBg'=>'bg-gradient-to-br from-indigo-100 to-purple-100','iconColor'=>'text-indigo-600','hoverShadow'=>'hover:shadow-indigo-200'),
  array('id'=>$workMenu,'label'=>$workMenu,'icon'=>'scroll-text','gradient'=>'from-orange-500 to-amber-500','iconBg'=>'bg-gradient-to-br from-orange-100 to-amber-100','iconColor'=>'text-orange-600','hoverShadow'=>'hover:shadow-orange-200'),
  array('id'=>$manageMenu,'label'=>$manageMenu,'icon'=>'bar-chart-3','gradient'=>'from-emerald-500 to-teal-500','iconBg'=>'bg-gradient-to-br from-emerald-100 to-teal-100','iconColor'=>'text-emerald-600','hoverShadow'=>'hover:shadow-emerald-200'),
  array('id'=>$constructionMenu,'label'=>$constructionMenu,'icon'=>'hard-hat','gradient'=>'from-yellow-500 to-orange-500','iconBg'=>'bg-gradient-to-br from-yellow-100 to-orange-100','iconColor'=>'text-yellow-600','hoverShadow'=>'hover:shadow-yellow-200'),
  array('id'=>$safetyMenu,'label'=>$safetyMenu,'icon'=>'shield-alert','gradient'=>'from-red-500 to-rose-500','iconBg'=>'bg-gradient-to-br from-red-100 to-rose-100','iconColor'=>'text-red-600','hoverShadow'=>'hover:shadow-red-200'),
  array('id'=>$qualityMenu,'label'=>$qualityMenu,'icon'=>'award','gradient'=>'from-cyan-500 to-blue-500','iconBg'=>'bg-gradient-to-br from-cyan-100 to-blue-100','iconColor'=>'text-cyan-600','hoverShadow'=>'hover:shadow-cyan-200'),
);
if ($canAccessCeoIndex) {
    array_splice($menuItems, 1, 0, array(array('id'=>$ceoIndexMenu,'label'=>$ceoIndexMenu,'href'=>'?r=ceo_index','icon'=>'gauge','gradient'=>'from-indigo-700 to-blue-600','iconBg'=>'bg-gradient-to-br from-indigo-100 to-blue-100','iconColor'=>'text-indigo-700','hoverShadow'=>'hover:shadow-indigo-200')));
}
if ($isPublicAffairsDept) {
    $filteredMenuItems = array();
    foreach ($menuItems as $menuItem) {
        if (isset($menuItem['id']) && $menuItem['id'] === $manageMenu) continue;
        $filteredMenuItems[] = $menuItem;
    }
    $menuItems = $filteredMenuItems;
}
if ($canViewCompanyProfitMenu && !$isPublicAffairsDept) {
    $menuItems[] = array('id'=>$companyProfitMenu,'label'=>$companyProfitMenu,'icon'=>'line-chart','gradient'=>'from-slate-700 to-blue-600','iconBg'=>'bg-gradient-to-br from-slate-100 to-blue-100','iconColor'=>'text-slate-700','hoverShadow'=>'hover:shadow-slate-200');
}
if ($canViewRepresentativeManagement) {
    $menuItems[] = array('id'=>$representativeManagementMenu,'label'=>$representativeManagementMenu,'href'=>'?r=representative_management','icon'=>'briefcase-business','gradient'=>'from-indigo-700 to-cyan-600','iconBg'=>'bg-gradient-to-br from-indigo-100 to-cyan-100','iconColor'=>'text-indigo-700','hoverShadow'=>'hover:shadow-indigo-200');
}
if ($canAccessUsageAnalytics) {
    $menuItems[] = array('id'=>$usageAnalyticsMenu,'label'=>$usageAnalyticsMenu,'href'=>'?r=usage_analytics','icon'=>'activity','gradient'=>'from-violet-600 to-indigo-500','iconBg'=>'bg-gradient-to-br from-violet-100 to-indigo-100','iconColor'=>'text-violet-700','hoverShadow'=>'hover:shadow-violet-200');
}
foreach ($googleShortcutMenuItems as $googleShortcutMenuItem) {
    $menuItems[] = $googleShortcutMenuItem;
}

$pageTitle = $selectedMenu;
if ($selectedMenu === $dashboardMenu) {
    $pageTitle = ($dashboardType === 'employee') ? '직원 대시보드' : '임원 대시보드';
}
?>

<aside id="cpmsSidebar"
       data-collapsed="0"
       class="w-72 bg-gradient-to-b from-gray-50 to-white backdrop-blur-xl flex flex-col shadow-sm transition-all duration-300 relative">
  <button
    type="button"
    id="sidebarToggle"
    class="absolute -right-3 top-8 w-6 h-6 bg-white border-2 border-gray-200 rounded-full flex items-center justify-center hover:bg-blue-50 hover:border-blue-300 transition-all duration-300 shadow-md z-10"
    aria-label="toggle sidebar"
  >
    <i data-lucide="chevron-left" class="w-4 h-4 text-gray-600"></i>
  </button>

  <div class="p-6 px-6 when-expanded">
    <a href="<?php echo h($dashboardHref); ?>" class="flex items-center gap-3 group" aria-label="대시보드로 이동">
      <img src="<?php echo h(base_url()); ?>/assets/img/logo.png" alt="logo" class="w-12 h-12 rounded-2xl object-contain bg-white border border-gray-100 p-1">
      <span class="font-bold text-xl bg-gradient-to-r from-blue-600 to-cyan-600 bg-clip-text text-transparent">창명건설</span>
    </a>
  </div>

  <div class="p-6 px-4 when-collapsed">
    <a href="<?php echo h($dashboardHref); ?>" class="flex items-center justify-center group" aria-label="대시보드로 이동" title="대시보드">
      <img src="<?php echo h(base_url()); ?>/assets/img/logo.png" alt="logo" class="w-10 h-10 rounded-2xl object-contain bg-white border border-gray-100 p-1">
    </a>
  </div>

  <nav class="flex-1 py-1 px-4 when-expanded">
    <ul class="space-y-1">
      <?php foreach ($menuItems as $it): ?>
        <?php $isSelected = ($selectedMenu === $it['id']); ?>
        <?php $itemHref = isset($it['href']) ? (string)$it['href'] : (($it['id'] === $dashboardMenu) ? $dashboardHref : ('?r=' . urlencode($it['id']))); ?>
        <?php $itemTarget = isset($it['target']) ? trim((string)$it['target']) : ''; ?>
        <?php $itemRel = isset($it['rel']) ? trim((string)$it['rel']) : ''; ?>
        <li>
          <a
            href="<?php echo h($itemHref); ?>"
            <?php if ($itemTarget !== ''): ?>target="<?php echo h($itemTarget); ?>"<?php endif; ?>
            <?php if ($itemRel !== ''): ?>rel="<?php echo h($itemRel); ?>"<?php endif; ?>
            class="w-full flex items-center gap-3 px-4 py-2 rounded-2xl transition-all duration-300 group relative
              <?php echo $isSelected
                ? ('bg-gradient-to-r ' . $it['gradient'] . ' text-white shadow-lg scale-[1.02]')
                : ('text-gray-700 ' . (isset($it['itemBg']) ? $it['itemBg'] . ' ' : '') . 'hover:bg-white/80 hover:shadow-md ' . $it['hoverShadow']); ?>"
          >
            <div class="p-1.5 rounded-xl transition-all duration-300 <?php echo $isSelected ? 'bg-white/20' : $it['iconBg']; ?>">
              <?php if (isset($it['iconImg']) && trim((string)$it['iconImg']) !== ''): ?>
                <img src="<?php echo h((string)$it['iconImg']); ?>" alt="<?php echo h(isset($it['iconAlt']) ? (string)$it['iconAlt'] : (string)$it['label']); ?>" class="w-5 h-5 object-contain">
              <?php else: ?>
                <i data-lucide="<?php echo h($it['icon']); ?>" class="w-5 h-5 <?php echo $isSelected ? 'text-white' : h($it['iconColor']); ?>"></i>
              <?php endif; ?>
            </div>
            <span class="font-semibold"><?php echo h($it['label']); ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <nav class="flex-1 py-1 px-2 when-collapsed">
    <ul class="space-y-1">
      <?php foreach ($menuItems as $it): ?>
        <?php $isSelected = ($selectedMenu === $it['id']); ?>
        <?php $itemHref = isset($it['href']) ? (string)$it['href'] : (($it['id'] === $dashboardMenu) ? $dashboardHref : ('?r=' . urlencode($it['id']))); ?>
        <?php $itemTarget = isset($it['target']) ? trim((string)$it['target']) : ''; ?>
        <?php $itemRel = isset($it['rel']) ? trim((string)$it['rel']) : ''; ?>
        <li>
          <a
            href="<?php echo h($itemHref); ?>"
            <?php if ($itemTarget !== ''): ?>target="<?php echo h($itemTarget); ?>"<?php endif; ?>
            <?php if ($itemRel !== ''): ?>rel="<?php echo h($itemRel); ?>"<?php endif; ?>
            class="w-full flex items-center justify-center px-2 py-2 rounded-2xl transition-all duration-300 group relative
              <?php echo $isSelected
                ? ('bg-gradient-to-r ' . $it['gradient'] . ' text-white shadow-lg scale-[1.02]')
                : ('text-gray-700 ' . (isset($it['itemBg']) ? $it['itemBg'] . ' ' : '') . 'hover:bg-white/80 hover:shadow-md ' . $it['hoverShadow']); ?>"
            title="<?php echo h($it['label']); ?>"
          >
            <div class="p-1.5 rounded-xl transition-all duration-300 <?php echo $isSelected ? 'bg-white/20' : $it['iconBg']; ?>">
              <?php if (isset($it['iconImg']) && trim((string)$it['iconImg']) !== ''): ?>
                <img src="<?php echo h((string)$it['iconImg']); ?>" alt="<?php echo h(isset($it['iconAlt']) ? (string)$it['iconAlt'] : (string)$it['label']); ?>" class="w-5 h-5 object-contain">
              <?php else: ?>
                <i data-lucide="<?php echo h($it['icon']); ?>" class="w-5 h-5 <?php echo $isSelected ? 'text-white' : h($it['iconColor']); ?>"></i>
              <?php endif; ?>
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

<div id="cpmsContentShell" class="flex-1 flex flex-col overflow-hidden">
  <header id="cpmsContentHeader" class="bg-white/70 backdrop-blur-xl border-b border-gray-200/50 px-8 py-4 flex items-center justify-between">
    <div class="flex items-center gap-4">
      <h1 class="text-2xl font-bold bg-gradient-to-r from-gray-900 to-gray-600 bg-clip-text text-transparent">
        <?php echo h($pageTitle); ?>
      </h1>
    </div>

    <div class="flex items-center gap-4">
      <button
        type="button"
        id="cpmsGuideTourStart"
        class="cpms-guide-start"
        aria-label="현재 화면 사용법 보기"
        title="현재 화면 사용법 보기"
      >
        <span class="cpms-guide-start__mark" aria-hidden="true">?</span>
        <span class="cpms-guide-start__label">사용법</span>
      </button>

      <?php if ($selectedMenu === $dashboardMenu && $canSwitchDashboardViews): ?>
        <div class="flex gap-1 bg-gray-100/80 backdrop-blur-sm rounded-2xl p-1 shadow-sm">
          <a href="<?php echo h('?r=dashboard_employee'); ?>"
             class="px-5 py-2 rounded-xl text-sm font-semibold transition-all duration-300 <?php echo ($dashboardType === 'employee') ? 'bg-white text-blue-600 shadow-md shadow-blue-500/10' : 'text-gray-600 hover:text-gray-900'; ?>">
            직원용
          </a>
          <a href="<?php echo h('?r=dashboard_executive'); ?>"
             class="px-5 py-2 rounded-xl text-sm font-semibold transition-all duration-300 <?php echo ($dashboardType === 'executive') ? 'bg-white text-blue-600 shadow-md shadow-blue-500/10' : 'text-gray-600 hover:text-gray-900'; ?>">
            임원용
          </a>
        </div>
      <?php endif; ?>

      <div class="cpms-user-chip flex items-center gap-3 text-sm bg-white/60 backdrop-blur-sm px-4 py-2 rounded-2xl border border-gray-200/50">
        <span class="font-semibold text-gray-900"><?php echo h($userName); ?></span>
        <span class="text-gray-300">|</span>
        <span class="text-blue-600 font-medium"><?php echo ($displayRole === 'executive') ? '임원' : '직원'; ?></span>
        <span class="text-gray-300">|</span>
        <a href="?r=logout" class="text-gray-600 hover:text-red-600 font-medium transition-colors">로그아웃</a>
      </div>
    </div>
  </header>

  <?php
    $canViewSafetyMobileMenu = false;
    if ($sidebarPdo) {
      $safetyHelper = __DIR__ . '/../safety/safety_cost_helper.php';
      if (file_exists($safetyHelper)) {
        require_once $safetyHelper;
        if (function_exists('cpms_safety_cost_project_rows_for_user')) {
          $canViewSafetyMobileMenu = count(cpms_safety_cost_project_rows_for_user($sidebarPdo)) > 0;
        }
      }
    }

    $mobileNavItems = array(
      array('menu' => 'dashboard', 'label' => '대시보드', 'icon' => 'layout-dashboard', 'href' => $dashboardHref),
      array('menu' => 'scheduler', 'label' => $schedulerMenu, 'icon' => 'calendar-days', 'href' => '?r=scheduler'),
      array('menu' => 'employees', 'label' => '임직원', 'icon' => 'users', 'href' => '?r=employees_directory'),
      array('menu' => 'notice', 'label' => $noticeMenu, 'icon' => 'megaphone', 'href' => '?r=notice'),
      array('menu' => 'approval', 'label' => '전자결재', 'icon' => 'file-check-2', 'href' => '?r=approval_home&view=active'),
    );
    if ($canAccessCeoIndex) {
      array_splice($mobileNavItems, 1, 0, array(array('menu'=>'ceo_index','label'=>'CEO Index','icon'=>'gauge','href'=>'?r=ceo_index')));
    }
    if ($canViewPublicAffairsMobileMenu) {
      $mobileNavItems[] = array('menu' => 'public_affairs', 'label' => '공무', 'icon' => 'scroll-text', 'href' => '?r=' . urlencode($workMenu) . '&tab=monthly_summary');
    }
    if (\App\Core\Auth::canAccessConstruction()) {
      $mobileNavItems[] = array('menu' => 'construction', 'label' => '공사', 'icon' => 'hard-hat', 'href' => '?r=construction_home&tab=status');
    }
    if (\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees()) {
      $mobileNavItems[] = array('menu' => 'management', 'label' => '관리', 'icon' => 'bar-chart-3', 'href' => '?r=' . urlencode('관리'));
    }
    if ($canViewSafetyMobileMenu) {
      $mobileNavItems[] = array('menu' => 'safety', 'label' => '안전/보건', 'icon' => 'shield-alert', 'href' => '?r=safety_home');
    }
    if ($canViewCompanyProfitMenu && !$isPublicAffairsDept) {
      $mobileNavItems[] = array('menu' => 'company_profit', 'label' => '경영현황', 'icon' => 'line-chart', 'href' => '?r=company_profit');
    }
    if ($canViewRepresentativeManagement) {
      $mobileNavItems[] = array('menu' => 'representative_management', 'label' => '대표 경영현황', 'icon' => 'briefcase-business', 'href' => '?r=representative_management');
    }
    if ($canAccessUsageAnalytics) {
      $mobileNavItems[] = array('menu' => 'usage_analytics', 'label' => '사용현황 분석', 'icon' => 'activity', 'href' => '?r=usage_analytics');
    }
  ?>
  <nav class="cpms-mobile-bottom-nav" aria-label="mobile main menu">
    <?php foreach ($mobileNavItems as $mobileItem): ?>
      <?php
        $mobileIsActive = false;
        if ($mobileItem['menu'] === 'representative_management' && $selectedMenu === $representativeManagementMenu) $mobileIsActive = true;
        else if ($mobileItem['menu'] === 'ceo_index' && $selectedMenu === $ceoIndexMenu) $mobileIsActive = true;
        else if ($mobileItem['menu'] === 'company_profit' && $selectedMenu === $companyProfitMenu) $mobileIsActive = true;
        else if ($mobileItem['menu'] === 'dashboard' && $selectedMenu === $dashboardMenu) $mobileIsActive = true;
        else if (isset($mobileItem['label']) && (string)$selectedMenu === (string)$mobileItem['label']) $mobileIsActive = true;
      ?>
      <a href="<?php echo h($mobileItem['href']); ?>" class="<?php echo $mobileIsActive ? 'is-active' : ''; ?>">
        <i data-lucide="<?php echo h($mobileItem['icon']); ?>"></i>
        <span><?php echo h($mobileItem['label']); ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <main id="cpmsContentMain" class="flex-1 overflow-y-auto overflow-x-hidden p-8">
    <?php if (!empty($flash) && is_array($flash)): ?>
      <?php $cls = ($flash['type'] === 'danger') ? 'bg-red-50 border-red-200 text-red-700' : 'bg-slate-50 border-slate-200 text-slate-700'; ?>
      <div class="mb-4 rounded-2xl border px-4 py-3 <?php echo h($cls); ?>">
        <?php echo h($flash['message']); ?>
      </div>
    <?php endif; ?>
