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
$canAiDataAudit = Auth::isDevelopmentDepartment();
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
    'employees' => array('label' => '임직원 명부', 'icon' => 'users'),
    'direct_team' => array('label' => '직영팀 명부', 'icon' => 'users'),
    'vendors' => array('label' => '업체관리', 'icon' => 'building-2'),
    'monthly_input' => array('label' => '투입비 상세', 'icon' => 'receipt'),
    'workforce' => array('label' => '인력관리', 'icon' => 'hard-hat'),
    'labor_calc' => array('label' => '노무비 계산', 'icon' => 'calculator'),
    'attendance' => array('label' => '출퇴근·근태관리', 'icon' => 'clock-3'),
    'leave_management' => array('label' => '연차 관리', 'icon' => 'calendar-days'),
    'cost_change' => array('label' => '비용 변경 관리', 'icon' => 'history'),
);

if ($canViewCompanyOverhead || $canViewCompanyPayroll) {
    $tabs['company_overhead'] = array('label' => '총관리비', 'icon' => 'building-2');
}
if ($canAiDataAudit) {
    $tabs['drive_check'] = array('label' => 'Drive 점검', 'icon' => 'cloud');
    $tabs['data_archive'] = array('label' => urldecode('%EB%8D%B0%EC%9D%B4%ED%84%B0%20%EC%95%84%EC%B9%B4%EC%9D%B4%EB%B8%8C'), 'icon' => 'archive');
}
if ($canAiDataAudit) {
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
    $tabs['ai_data_setup'] = array('label' => 'AI 데이터 이력 설정', 'icon' => 'database-cog');
    $tabs['ai_data_history'] = array('label' => '통합 비용 이력', 'icon' => 'list-history');
    $tabs['ai_data_governance'] = array('label' => 'AI 데이터 원천·현장유형', 'icon' => 'database-zap');
    $tabs['ai_snapshot_setup'] = array('label' => '일일 스냅샷 설정', 'icon' => 'calendar-cog');
    $tabs['ai_snapshot_history'] = array('label' => '일일 스냅샷 이력', 'icon' => 'calendar-range');
    $tabs['ai_forecast_setup'] = array('label' => '기본 월말 예측 설정', 'icon' => 'chart-no-axes-combined');
    $tabs['ai_forecast_history'] = array('label' => '기본 월말 예측 결과', 'icon' => 'chart-spline');
    $tabs['ai_forecast_accuracy'] = array('label' => '예측 정확도', 'icon' => 'chart-no-axes-combined');
    $tabs['ai_reliability_setup'] = array('label' => '입력 신뢰도 설정', 'icon' => 'shield-check');
    $tabs['ai_reliability_history'] = array('label' => '입력 신뢰도 결과', 'icon' => 'activity');
    $tabs['ai_anomaly_setup'] = array('label' => '이상징후 탐지 설정', 'icon' => 'scan-search');
    $tabs['ai_anomaly_history'] = array('label' => '이상징후 탐지 결과', 'icon' => 'triangle-alert');
    $tabs['ai_profit_risk_setup'] = array('label' => '적자·원가율 위험 설정', 'icon' => 'badge-alert');
    $tabs['ai_profit_risk_history'] = array('label' => '적자·원가율 위험 결과', 'icon' => 'chart-no-axes-column-increasing');
    $tabs['ai_openai_setup'] = array('label' => 'OpenAI 연결 설정', 'icon' => 'plug-zap');
    $tabs['ai_executive_brief'] = array('label' => '대표용 경영 브리핑', 'icon' => 'briefcase-business');
    $tabs['ai_ceo_index'] = array('label' => 'CEO Index 진단', 'icon' => 'gauge');
    $tabs['ai_executive_qa_history'] = array('label' => '대표 질문 이력', 'icon' => 'messages-square');
    $tabs['ai_pipeline_setup'] = array('label' => 'AI 자동 분석 설정', 'icon' => 'workflow');
    $tabs['ai_pipeline_history'] = array('label' => 'AI 자동 분석 이력', 'icon' => 'list-checks');
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
  <?php $adminAiGroupOpen = false; ?>
  <?php foreach ($tabs as $k => $t): ?>
    <?php if (!$adminAiGroupOpen && strpos($k, 'ai_') === 0): $adminAiGroupOpen = true; ?>
      <details<?php echo strpos($tab, 'ai_') === 0 ? ' open' : ''; ?> style="margin-top:10px;padding-top:10px;border-top:1px solid #e5e7eb;">
        <summary style="cursor:pointer;font-weight:800;color:#334155;margin-bottom:6px;">AI·시스템 관리</summary>
        <div>
    <?php endif; ?>
    <?php $active = ($k === $tab); ?>
    <?php
      $tabHref = admin_tab_url($k);
      if ($k === 'ai_data_audit') {
          $tabHref = '?r=admin%2Fai_data_audit';
      }
      if ($k === 'ai_data_setup') {
          $tabHref = '?r=admin%2Fai_data_setup';
      }
      if ($k === 'ai_data_history') {
          $tabHref = '?r=admin%2Fai_data_history';
      }
      if ($k === 'ai_snapshot_setup') {
          $tabHref = '?r=admin%2Fai_snapshot_setup';
      }
      if ($k === 'ai_snapshot_history') {
          $tabHref = '?r=admin%2Fai_snapshot_history';
      }
      if ($k === 'ai_forecast_setup') {
          $tabHref = '?r=admin%2Fai_forecast_setup';
      }
      if ($k === 'ai_forecast_history') {
          $tabHref = '?r=admin%2Fai_forecast_history';
      }
      if ($k === 'ai_reliability_setup') {
          $tabHref = '?r=admin%2Fai_reliability_setup';
      }
      if ($k === 'ai_reliability_history') {
          $tabHref = '?r=admin%2Fai_reliability_history';
      }
      if ($k === 'ai_anomaly_setup') {
          $tabHref = '?r=admin%2Fai_anomaly_setup';
      }
      if ($k === 'ai_anomaly_history') {
          $tabHref = '?r=admin%2Fai_anomaly_history';
      }
      if ($k === 'ai_profit_risk_setup') {
          $tabHref = '?r=admin%2Fai_profit_risk_setup';
      }
      if ($k === 'ai_profit_risk_history') {
          $tabHref = '?r=admin%2Fai_profit_risk_history';
      }
      if ($k === 'ai_openai_setup') {
          $tabHref = '?r=admin%2Fai_openai_setup';
      }
      if ($k === 'ai_executive_brief') {
          $tabHref = '?r=admin%2Fai_executive_brief';
      }
      if ($k === 'ai_ceo_index') {
          $tabHref = '?r=admin%2Fai_ceo_index';
      }
      if ($k === 'ai_executive_qa_history') {
          $tabHref = '?r=admin%2Fai_executive_qa_history';
      }
      if ($k === 'ai_pipeline_setup') {
          $tabHref = '?r=admin%2Fai_pipeline_setup';
      }
      if ($k === 'ai_pipeline_history') {
          $tabHref = '?r=admin%2Fai_pipeline_history';
      }
      if ($k === 'company_overhead' && !$canViewCompanyOverhead && $canViewCompanyPayroll) {
          $tabHref .= '&oh=payroll';
      }
    ?>
    <a href="<?php echo $tabHref; ?>" style="display:inline-block;margin:4px 6px 4px 0;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:700;<?php echo $active ? 'background:#166534;color:#fff;border:1px solid #166534;' : 'background:#fff;color:#4b5563;border:1px solid #d1d5db;'; ?>">
      <?php echo h($t['label']); ?>
    </a>
  <?php endforeach; ?>
  <?php if ($adminAiGroupOpen): ?></div></details><?php endif; ?>
</div>

<?php
$GLOBALS['__admin_embedded'] = true;

if ($tab === 'employees') {
    require __DIR__ . '/employees.php';
} elseif ($tab === 'direct_team' && $canManage) {
    require __DIR__ . '/direct_team.php';
} elseif ($tab === 'vendors' && $canManage) {
    require __DIR__ . '/vendors.php';
} elseif ($tab === 'monthly_input' && $canManage) {
    require __DIR__ . '/monthly_input.php';
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
} elseif ($tab === 'ai_data_setup' && $canAiDataAudit) {
    require __DIR__ . '/ai_data_setup.php';
} elseif ($tab === 'ai_data_history' && $canAiDataAudit) {
    require __DIR__ . '/ai_data_history.php';
} elseif ($tab === 'ai_data_governance' && $canAiDataAudit) {
    require __DIR__ . '/ai_data_governance.php';
} elseif ($tab === 'ai_snapshot_setup' && $canAiDataAudit) {
    require __DIR__ . '/ai_snapshot_setup.php';
} elseif ($tab === 'ai_snapshot_history' && $canAiDataAudit) {
    require __DIR__ . '/ai_snapshot_history.php';
} elseif ($tab === 'ai_forecast_setup' && $canAiDataAudit) {
    require __DIR__ . '/ai_forecast_setup.php';
} elseif ($tab === 'ai_forecast_history' && $canAiDataAudit) {
    require __DIR__ . '/ai_forecast_history.php';
} elseif ($tab === 'ai_forecast_accuracy' && $canAiDataAudit) {
    require __DIR__ . '/ai_forecast_accuracy.php';
} elseif ($tab === 'ai_reliability_setup' && $canAiDataAudit) {
    require __DIR__ . '/ai_reliability_setup.php';
} elseif ($tab === 'ai_reliability_history' && $canAiDataAudit) {
    require __DIR__ . '/ai_reliability_history.php';
} elseif ($tab === 'ai_anomaly_setup' && $canAiDataAudit) {
    require __DIR__ . '/ai_anomaly_setup.php';
} elseif ($tab === 'ai_anomaly_history' && $canAiDataAudit) {
    require __DIR__ . '/ai_anomaly_history.php';
} elseif ($tab === 'ai_profit_risk_setup' && $canAiDataAudit) {
    require __DIR__ . '/ai_profit_risk_setup.php';
} elseif ($tab === 'ai_profit_risk_history' && $canAiDataAudit) {
    require __DIR__ . '/ai_profit_risk_history.php';
} elseif ($tab === 'ai_openai_setup' && $canAiDataAudit) {
    require __DIR__ . '/ai_openai_setup.php';
} elseif ($tab === 'ai_executive_brief' && $canAiDataAudit) {
    require __DIR__ . '/ai_executive_brief.php';
} elseif ($tab === 'ai_ceo_index' && $canAiDataAudit) {
    require __DIR__ . '/ai_ceo_index.php';
} elseif ($tab === 'ai_executive_qa_history' && $canAiDataAudit) {
    require __DIR__ . '/ai_executive_qa_history.php';
} elseif ($tab === 'ai_pipeline_setup' && $canAiDataAudit) {
    require __DIR__ . '/ai_pipeline_setup.php';
} elseif ($tab === 'ai_pipeline_history' && $canAiDataAudit) {
    require __DIR__ . '/ai_pipeline_history.php';
} elseif ($tab === 'company_overhead' && ($canViewCompanyOverhead || $canViewCompanyPayroll)) {
    if (!$canViewCompanyOverhead && $canViewCompanyPayroll && (!isset($_GET['oh']) || trim((string)$_GET['oh']) === '')) {
        $_GET['oh'] = 'payroll';
    }
    require __DIR__ . '/../management/overhead/index.php';
} elseif ($tab === 'drive_check' && $canAiDataAudit) {
    require __DIR__ . '/drive_check.php';
} elseif ($tab === 'data_archive' && $canAiDataAudit) {
    require __DIR__ . '/data_archive.php';
} elseif ($tab === 'project_drive_sync' && $canAiDataAudit) {
    require __DIR__ . '/project_drive_sync.php';
} else {
    require __DIR__ . '/labor_calc.php';
}

unset($GLOBALS['__admin_embedded']);
?>
