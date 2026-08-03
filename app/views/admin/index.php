<?php
/**
 * C:\www\cpms\app\views\admin\index.php
 * - 관리 섹션 메인
 * - PHP 5.6 호환
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/CompanyProfitAccessService.php';
require_once __DIR__ . '/../../services/CompanyOverheadService.php';
require_once __DIR__ . '/../../services/CompanyPayrollAccessService.php';

$pdo = Db::pdo();
$user = Auth::user();
$canManage = (Auth::isMaster() || Auth::canManageEmployees());
$canAiDataAudit = (Auth::isDevelopmentDepartment() || Auth::canManageEmployees());
$canLaborManagement = cpms_is_management_department_user($pdo, $user);
$canViewCompanyOverhead = cpms_can_view_company_overhead($user, $pdo);
$canViewCompanyPayroll = cpms_can_view_company_payroll($user, $pdo);

if (!$canManage && !$canAiDataAudit && !$canLaborManagement && !$canViewCompanyOverhead && !$canViewCompanyPayroll) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. 관리부서 전용 화면입니다.</div>';
    return;
}

$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : '';
if ($tab === '') {
    if ($canManage) $tab = 'employees';
    else if ($canLaborManagement) $tab = 'labor_calc';
    else if ($canAiDataAudit) $tab = 'ai_data_audit';
    else $tab = 'company_overhead';
}

$tabs = array(
    'employees' => array('label' => '직원명부', 'icon' => 'users'),
    'workforce' => array('label' => '인력관리', 'icon' => 'hard-hat'),
    'labor_calc' => array('label' => '노무비 계산', 'icon' => 'calculator'),
    'attendance' => array('label' => '출퇴근·근태관리', 'icon' => 'clock-3'),
    'leave_management' => array('label' => '연차 관리', 'icon' => 'calendar-days'),
    'cost_change' => array('label' => '비용 변경 관리', 'icon' => 'history'),
);

if ($canViewCompanyOverhead || $canViewCompanyPayroll) {
    $tabs['company_overhead'] = array('label' => '총관리비', 'icon' => 'building-2');
}
if (Auth::isMaster()) {
    $tabs['drive_check'] = array('label' => 'Drive 점검', 'icon' => 'cloud');
    $tabs['data_archive'] = array('label' => urldecode('%EB%8D%B0%EC%9D%B4%ED%84%B0%20%EC%95%84%EC%B9%B4%EC%9D%B4%EB%B8%8C'), 'icon' => 'archive');
}
if (Auth::isMaster()) {
    $tabs['project_drive_sync'] = array('label' => '프로젝트 Drive 동기화', 'icon' => 'folder-sync');
}

if (!$canManage && $canLaborManagement) {
    $tabs = array(
        'labor_calc' => array('label' => '노무비 계산', 'icon' => 'calculator'),
    );
    if ($canViewCompanyOverhead || $canViewCompanyPayroll) {
        $tabs['company_overhead'] = array('label' => '총관리비', 'icon' => 'building-2');
    }
}

if (!$canManage && !$canLaborManagement && ($canViewCompanyOverhead || $canViewCompanyPayroll)) {
    $tabs = array(
        'company_overhead' => array('label' => '총관리비', 'icon' => 'building-2'),
    );
}

if (!$canManage && !$canLaborManagement && !$canViewCompanyOverhead && !$canViewCompanyPayroll) {
    $tabs = array();
}
if ($canAiDataAudit) {
    $tabs['ai_data_audit'] = array('label' => 'AI 데이터 점검', 'icon' => 'database-zap');
}

if (!isset($tabs[$tab])) {
    if ($canManage) $tab = 'employees';
    else if ($canLaborManagement) $tab = 'labor_calc';
    else if ($canAiDataAudit) $tab = 'ai_data_audit';
    else $tab = 'company_overhead';
}

if (!function_exists('admin_tab_url')) {
    function admin_tab_url($tab)
    {
        return '?r=관리&tab=' . urlencode($tab);
    }
}
?>

<div style="margin:0 0 16px 0; padding:12px; border:1px solid #e5e7eb; border-radius:12px; background:#fff;">
  <?php foreach ($tabs as $k => $t): ?>
    <?php $active = ($k === $tab); ?>
    <?php
      $tabHref = admin_tab_url($k);
      if ($k === 'ai_data_audit') {
          $tabHref = '?r=admin%2Fai_data_audit';
      }
      if ($k === 'company_overhead' && !$canViewCompanyOverhead && $canViewCompanyPayroll) {
          $tabHref .= '&oh=payroll';
      }
    ?>
    <a href="<?php echo $tabHref; ?>" style="display:inline-block;margin:4px 6px 4px 0;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:700;<?php echo $active ? 'background:#166534;color:#fff;border:1px solid #166534;' : 'background:#fff;color:#4b5563;border:1px solid #d1d5db;'; ?>">
      <?php echo h($t['label']); ?>
    </a>
  <?php endforeach; ?>
</div>

<?php
$GLOBALS['__admin_embedded'] = true;

if ($tab === 'employees') {
    require __DIR__ . '/employees.php';
} elseif ($tab === 'workforce') {
    require __DIR__ . '/workforce.php';
} elseif ($tab === 'labor_calc') {
    require __DIR__ . '/labor_calc.php';
} elseif ($tab === 'attendance') {
    require __DIR__ . '/attendance.php';
} elseif ($tab === 'leave_management') {
    require __DIR__ . '/leave_management.php';
} elseif ($tab === 'cost_change' && $canManage) {
    require __DIR__ . '/cost_change.php';
} elseif ($tab === 'ai_data_audit' && $canAiDataAudit) {
    require __DIR__ . '/ai_data_audit.php';
} elseif ($tab === 'company_overhead' && ($canViewCompanyOverhead || $canViewCompanyPayroll)) {
    if (!$canViewCompanyOverhead && $canViewCompanyPayroll && (!isset($_GET['oh']) || trim((string)$_GET['oh']) === '')) {
        $_GET['oh'] = 'payroll';
    }
    require __DIR__ . '/../management/overhead/index.php';
} elseif ($tab === 'drive_check' && Auth::isMaster()) {
    require __DIR__ . '/drive_check.php';
} elseif ($tab === 'data_archive' && Auth::isMaster()) {
    require __DIR__ . '/data_archive.php';
} elseif ($tab === 'project_drive_sync' && Auth::isMaster()) {
    require __DIR__ . '/project_drive_sync.php';
} else {
    require __DIR__ . '/labor_calc.php';
}

unset($GLOBALS['__admin_embedded']);
?>
