<?php
/**
 * 공무 협업툴 독립 보드 화면
 * - 공무섹션 내부에서만 동작하는 업무카드/칸반/상세 패널 중심 협업툴 화면이다.
 * - 기존 나의할일, 공사 이슈, 프로젝트 이슈 화면과 연결하지 않는다.
 * - PHP 5.6 호환 문법만 사용한다.
 */

use App\Core\Auth;
use App\Core\Db;

$GLOBALS['pa_collab_stage'] = 'collaboration_start';
$paCollabLoadErrors = array();
$paCollabDefaultSettings = array(
    'task_types' => array('계약 검토', '변경계약', '추가공사', '기성/청구', '내역서 검토', '실행내역 확인', '발주처 요청사항', '협력업체 요청사항', '자료 제출', '결재 요청', '회의 후속조치', '리스크 검토', '기타'),
    'statuses' => array('할 일', '진행중', '검토중', '대기', '완료', '보류'),
    'priorities' => array('긴급', '높음', '보통', '낮음'),
    'quick_filters' => array(),
    'card_fields' => array(),
    'default_assignee_employee_id' => 0,
);

if (!function_exists('pa_collab_safe_call')) {
function pa_collab_safe_call($functionName, $args, $default) {
    global $paCollabLoadErrors;
    if (!function_exists($functionName)) {
        $paCollabLoadErrors[] = $functionName . ' 함수를 찾을 수 없습니다.';
        return $default;
    }
    try {
        return call_user_func_array($functionName, $args);
    } catch (Exception $e) {
        $paCollabLoadErrors[] = $functionName . ': ' . $e->getMessage();
        return $default;
    }
}}

if (!function_exists('pa_collab_lower_safe')) {
function pa_collab_lower_safe($value) {
    if (function_exists('cpms_public_affairs_collab_lower')) {
        return cpms_public_affairs_collab_lower($value);
    }
    $value = trim((string)$value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}}

$GLOBALS['pa_collab_stage'] = 'collaboration_service_file_check';
$paCollabServiceFile = __DIR__ . '/../../services/PublicAffairsCollaborationService.php';
if (!is_file($paCollabServiceFile)) {
    ?>
    <div style="max-width:900px;margin:40px auto;padding:24px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:14px;font-family:Arial,'Malgun Gothic',sans-serif;">
      <h1 style="margin:0 0 10px;font-size:22px;">공무 협업툴을 열 수 없습니다</h1>
      <p>협업툴 서비스 파일을 찾을 수 없습니다. 진단 페이지에서 파일 상태를 확인해주세요.</p>
      <p><a href="?r=public_affairs_collab&safe=1">안전 모드</a> | <a href="?r=public_affairs_collab_debug" target="_blank">진단 페이지</a></p>
    </div>
    <?php
    return;
}

$GLOBALS['pa_collab_stage'] = 'collaboration_require_service';
require_once $paCollabServiceFile;
$GLOBALS['pa_collab_stage'] = 'collaboration_require_service_done';

$paCollabRequiredFunctions = array(
    'cpms_public_affairs_collab_settings',
    'cpms_public_affairs_collab_fetch_employees',
    'cpms_public_affairs_collab_fetch_projects',
    'cpms_public_affairs_collab_current_employee',
    'cpms_public_affairs_collab_is_admin_user',
    'cpms_public_affairs_collab_can_create_task',
    'cpms_public_affairs_collab_user_can_access_module',
    'cpms_public_affairs_collab_list_tasks',
    'cpms_public_affairs_collab_visible_tasks',
    'cpms_public_affairs_collab_project_spaces',
    'cpms_public_affairs_collab_find_project_space',
    'cpms_public_affairs_collab_project_home_summary',
    'cpms_public_affairs_collab_project_tasks',
    'cpms_public_affairs_collab_project_stats',
    'cpms_public_affairs_collab_project_activities',
    'cpms_public_affairs_collab_project_main_manager_id',
    'cpms_public_affairs_collab_apply_filters',
    'cpms_public_affairs_collab_apply_quick_filter',
    'cpms_public_affairs_collab_summary',
    'cpms_public_affairs_collab_task_counts',
    'cpms_public_affairs_collab_find_task',
    'cpms_public_affairs_collab_user_can_view_task',
    'cpms_public_affairs_collab_group_by_status',
    'cpms_public_affairs_collab_lower',
    'cpms_public_affairs_collab_official_project_name',
    'cpms_public_affairs_collab_task_no',
    'cpms_public_affairs_collab_task_ref_names',
    'cpms_public_affairs_collab_count_for_task',
    'cpms_public_affairs_collab_user_can_edit_task',
    'cpms_public_affairs_collab_is_delayed',
    'cpms_public_affairs_collab_is_due_today',
    'cpms_public_affairs_collab_comments',
    'cpms_public_affairs_collab_files',
    'cpms_public_affairs_collab_history',
);
foreach ($paCollabRequiredFunctions as $paCollabFunctionName) {
    if (!function_exists($paCollabFunctionName)) {
        ?>
        <div style="max-width:900px;margin:40px auto;padding:24px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:14px;font-family:Arial,'Malgun Gothic',sans-serif;">
          <h1 style="margin:0 0 10px;font-size:22px;">공무 협업툴을 여는 중 문제가 발생했습니다</h1>
          <p>협업툴 서비스 함수가 준비되지 않았습니다. 안전 모드 또는 진단 페이지에서 상태를 확인해주세요.</p>
          <p><a href="?r=public_affairs_collab&safe=1">안전 모드</a> | <a href="?r=public_affairs_collab_debug" target="_blank">진단 페이지</a></p>
        </div>
        <?php
        return;
    }
}

$GLOBALS['pa_collab_stage'] = 'collaboration_db_pdo';
$pdo = null;
try {
    $pdo = Db::pdo();
} catch (Exception $e) {
    $paCollabLoadErrors[] = 'DB 연결: ' . $e->getMessage();
}

$GLOBALS['pa_collab_stage'] = 'collaboration_settings';
$settings = pa_collab_safe_call('cpms_public_affairs_collab_settings', array(), $paCollabDefaultSettings);
if (!is_array($settings)) $settings = $paCollabDefaultSettings;
foreach ($paCollabDefaultSettings as $paCollabSettingKey => $paCollabSettingValue) {
    if (!isset($settings[$paCollabSettingKey])) $settings[$paCollabSettingKey] = $paCollabSettingValue;
}
$GLOBALS['pa_collab_stage'] = 'collaboration_fetch_employees';
$employees = pa_collab_safe_call('cpms_public_affairs_collab_fetch_employees', array($pdo), array());
$GLOBALS['pa_collab_stage'] = 'collaboration_fetch_projects';
$projects = pa_collab_safe_call('cpms_public_affairs_collab_fetch_projects', array($pdo), array());
$GLOBALS['pa_collab_stage'] = 'collaboration_current_employee';
$currentEmployee = pa_collab_safe_call('cpms_public_affairs_collab_current_employee', array($pdo), array(
    'id' => 0,
    'name' => (string)Auth::userName(),
    'email' => (string)Auth::userEmail(),
    'department' => (string)Auth::userDepartment(),
    'role' => (string)Auth::userRole(),
));
$GLOBALS['pa_collab_stage'] = 'collaboration_permissions';
$canManageCollab = (bool)pa_collab_safe_call('cpms_public_affairs_collab_is_admin_user', array(), false);
$canCreateCollab = (bool)pa_collab_safe_call('cpms_public_affairs_collab_can_create_task', array(), false);
$canAccessCollab = (bool)pa_collab_safe_call('cpms_public_affairs_collab_user_can_access_module', array($currentEmployee), true);
$paCollabRouteActive = (!empty($paCollabAutoOpen) || (isset($_GET['tab']) && $_GET['tab'] === 'collaboration'));
$flash = null;
if ($paCollabRouteActive) {
    $flash = isset($flash) ? $flash : flash_get();
}

if (!function_exists('pa_collab_url')) {
function pa_collab_url($overrides) {
    $params = $_GET;
    $params['r'] = 'public_affairs_collab';
    $params['tab'] = 'collaboration';
    if (is_array($overrides)) {
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                if (isset($params[$key])) unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }
    }
    return '?' . http_build_query($params);
}}

if (!function_exists('pa_selected')) {
function pa_selected($a, $b) {
    return ((string)$a === (string)$b) ? 'selected' : '';
}}

if (!function_exists('pa_checked')) {
function pa_checked($values, $value) {
    if (!is_array($values)) $values = array();
    return in_array((string)$value, array_map('strval', $values), true) ? 'checked' : '';
}}

if (!function_exists('pa_employee_options')) {
function pa_employee_options($employees, $selected, $multiple) {
    foreach ($employees as $employee) {
        $id = isset($employee['id']) ? (int)$employee['id'] : 0;
        if ($id <= 0) continue;
        $name = isset($employee['name']) ? (string)$employee['name'] : '-';
        $dept = isset($employee['department']) ? (string)$employee['department'] : '-';
        $position = isset($employee['position']) && trim((string)$employee['position']) !== '' ? (string)$employee['position'] : '-';
        if ($multiple) {
            $selectedText = '';
            if (is_array($selected)) {
                foreach ($selected as $selectedId) {
                    if ((int)$selectedId === $id) $selectedText = 'selected';
                }
            }
        } else {
            $selectedText = ((int)$selected === $id) ? 'selected' : '';
        }
        echo '<option value="' . (int)$id . '" ' . $selectedText . '>' . h($name . ' / ' . $dept . ' / ' . $position) . '</option>';
    }
}}

if (!function_exists('pa_status_class')) {
function pa_status_class($status) {
    if ($status === '완료') return 'pa-status-done';
    if ($status === '반려') return 'pa-status-reject';
    if ($status === '보류') return 'pa-status-hold';
    if ($status === '결재대기') return 'pa-status-approval';
    if ($status === '자료대기') return 'pa-status-wait';
    if ($status === '진행중') return 'pa-status-progress';
    return 'pa-status-new';
}}

if (!function_exists('pa_priority_class')) {
function pa_priority_class($priority) {
    if ($priority === '긴급') return 'pa-priority-urgent';
    if ($priority === '높음') return 'pa-priority-high';
    if ($priority === '낮음') return 'pa-priority-low';
    return 'pa-priority-normal';
}}

if (!function_exists('pa_due_text')) {
function pa_due_text($task) {
    $date = isset($task['due_date']) ? trim((string)$task['due_date']) : '';
    if ($date === '') return '-';
    $time = isset($task['due_time']) ? trim((string)$task['due_time']) : '';
    return $date . ($time !== '' ? ' ' . $time : '');
}}

if (!function_exists('pa_file_size')) {
function pa_file_size($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . 'KB';
    return $bytes . 'B';
}}

if (!function_exists('pa_project_card')) {
function pa_project_card($project) {
    // 공무 협업툴 프로젝트 홈: 기존 CPMS 프로젝트/가제 프로젝트를 Jira Space 카드처럼 보여준다.
    if (!is_array($project)) return;
    $projectId = isset($project['id']) ? (int)$project['id'] : 0;
    if ($projectId <= 0) return;
    $stats = isset($project['stats']) && is_array($project['stats']) ? $project['stats'] : array();
    $isDraft = isset($project['is_draft']) && (int)$project['is_draft'] === 1;
    $status = isset($project['status']) && trim((string)$project['status']) !== '' ? (string)$project['status'] : (isset($project['phase']) ? (string)$project['phase'] : '-');
    $client = isset($project['client']) ? (string)$project['client'] : '';
    $contractor = isset($project['contractor']) ? (string)$project['contractor'] : '';
    $manager = isset($project['manager_name']) && trim((string)$project['manager_name']) !== '' ? (string)$project['manager_name'] : '-';
    $period = (isset($project['start_date']) && trim((string)$project['start_date']) !== '' ? (string)$project['start_date'] : '-') . ' ~ ' . (isset($project['end_date']) && trim((string)$project['end_date']) !== '' ? (string)$project['end_date'] : '-');
    $last = isset($stats['last_activity_at']) && trim((string)$stats['last_activity_at']) !== '' ? substr((string)$stats['last_activity_at'], 0, 16) : '-';
    ?>
    <a class="pa-space-card <?php echo $isDraft ? 'is-draft' : 'is-official'; ?>" href="<?php echo h(pa_collab_url(array('space_project_id' => $projectId, 'section' => 'summary', 'quick' => 'hide_done', 'task_id' => null))); ?>">
      <div class="pa-space-card-top">
        <span class="pa-space-type <?php echo $isDraft ? 'is-draft' : 'is-official'; ?>"><?php echo $isDraft ? '가제' : '정식'; ?></span>
        <span class="pa-muted"><?php echo h($status); ?></span>
      </div>
      <div class="pa-space-name"><?php echo h(isset($project['name']) ? $project['name'] : '-'); ?></div>
      <?php if ($isDraft): ?><div class="pa-draft-note">정식 프로젝트 전환 전</div><?php endif; ?>
      <div class="pa-space-meta">
        <div>발주처 <?php echo h($client !== '' ? $client : '-'); ?></div>
        <div>시공사 <?php echo h($contractor !== '' ? $contractor : '-'); ?></div>
        <div>담당자 <?php echo h($manager); ?></div>
        <div>기간 <?php echo h($period); ?></div>
      </div>
      <div class="pa-space-stats">
        <span>전체 <?php echo isset($stats['total']) ? (int)$stats['total'] : 0; ?></span>
        <span>진행 <?php echo isset($stats['active']) ? (int)$stats['active'] : 0; ?></span>
        <span class="<?php echo (isset($stats['delayed']) && (int)$stats['delayed'] > 0) ? 'is-hot' : ''; ?>">지연 <?php echo isset($stats['delayed']) ? (int)$stats['delayed'] : 0; ?></span>
      </div>
      <div class="pa-space-last">마지막 활동 <?php echo h($last); ?></div>
    </a>
    <?php
}}

if (!function_exists('pa_project_section')) {
function pa_project_section($title, $projects, $emptyText) {
    // 공무 협업툴 프로젝트 홈: 최근/즐겨찾기/정식/가제 Space 묶음 출력.
    ?>
    <section class="pa-space-section">
      <div class="pa-space-section-head">
        <h3><?php echo h($title); ?></h3>
        <span><?php echo is_array($projects) ? (int)count($projects) : 0; ?></span>
      </div>
      <?php if (!is_array($projects) || count($projects) === 0): ?>
        <div class="pa-empty"><?php echo h($emptyText); ?></div>
      <?php else: ?>
        <div class="pa-space-grid">
          <?php foreach ($projects as $project) pa_project_card($project); ?>
        </div>
      <?php endif; ?>
    </section>
    <?php
}}

if (!function_exists('pa_user_is_assignee')) {
function pa_user_is_assignee($task, $employee) {
    if (!is_array($task) || !is_array($employee)) return false;
    $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
    $employeeEmail = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
    if ($employeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === $employeeId) return true;
    if ($employeeEmail !== '' && isset($task['assignee_email']) && strtolower(trim((string)$task['assignee_email'])) === $employeeEmail) return true;
    return false;
}}

if (!$canAccessCollab) {
    $paCollabInitiallyOpen = !empty($paCollabAutoOpen);
    ?>
<link rel="stylesheet" href="<?php echo h(asset_url('assets/css/public_affairs_collaboration.css') . '?v=' . (string)@filemtime(dirname(dirname(dirname(__DIR__))) . '/public/assets/css/public_affairs_collaboration.css')); ?>">
<style>
/* 공무 협업툴 접속 fallback: 외부 CSS가 늦게 로드되거나 실패해도 전체화면 모달은 열리게 한다. */
.pa-collab-fullscreen{position:fixed;top:0;right:0;bottom:0;left:0;width:100vw;height:100vh;z-index:99999;display:none;background:rgba(15,23,42,.82);overflow:hidden}
.pa-collab-fullscreen.is-open{display:block}
body.pa-collab-open{overflow:hidden}
</style>

<div id="paCollabFullscreenModal"
     class="pa-collab-fullscreen<?php echo $paCollabInitiallyOpen ? ' is-open' : ''; ?>"
     role="dialog"
     aria-modal="true"
     aria-hidden="<?php echo $paCollabInitiallyOpen ? 'false' : 'true'; ?>"
     aria-label="공무 협업툴"
     data-pa-collab-modal
     data-pa-auto-open="<?php echo $paCollabInitiallyOpen ? '1' : '0'; ?>">
  <section class="pa-collab-shell">
    <header class="pa-collab-header">
      <div class="pa-collab-brand">
        <div>
          <div class="pa-collab-title">공무 협업툴</div>
          <div class="pa-collab-subtitle">공무 업무카드 기반 협업보드</div>
        </div>
      </div>
      <a href="?r=공무&tab=monthly_summary" class="pa-collab-close" data-pa-collab-close aria-label="공무 협업툴 닫기">닫기 ×</a>
    </header>
    <div class="pa-collab-body" style="padding:24px;overflow:auto;">
      <div class="rounded-2xl border border-red-200 bg-red-50 text-red-700 p-5 font-bold">
          공무 협업툴 접근 권한이 없습니다. 로그인 후 이용해주세요.
      </div>
    </div>
  </section>
</div>

<?php
  // 공무 협업툴 AJAX 화면 구성값: 상세패널/카드 갱신/상태 이동에서 공통으로 사용한다.
  $paCollabEmployeeOptions = array();
  foreach ($employees as $employee) {
      $paCollabEmployeeOptions[] = array(
          'id' => isset($employee['id']) ? (int)$employee['id'] : 0,
          'name' => isset($employee['name']) ? (string)$employee['name'] : '',
          'department' => isset($employee['department']) ? (string)$employee['department'] : '',
          'position' => isset($employee['position']) ? (string)$employee['position'] : '',
      );
  }
  $paCollabJsConfig = array(
      'csrf' => csrf_token(),
      'actionUrl' => '?r=project/collaboration_action',
      'fileUrl' => '?r=project/collaboration_file&id=',
      'statuses' => $settings['statuses'],
      'priorities' => $settings['priorities'],
      'taskTypes' => $settings['task_types'],
      'employees' => $paCollabEmployeeOptions,
      'impactOptions' => array('없음', '있음', '확인필요'),
  );
  $GLOBALS['pa_collab_stage'] = 'collaboration_js_config_access_denied';
  $paCollabJsonFlags = function_exists('cpms_public_affairs_collab_json_flags') ? cpms_public_affairs_collab_json_flags(false) : 0;
  $paCollabJsConfigJson = json_encode($paCollabJsConfig, $paCollabJsonFlags);
  if (!is_string($paCollabJsConfigJson) || $paCollabJsConfigJson === '') $paCollabJsConfigJson = '{}';
?>
<script>
window.paCollabConfig = <?php echo $paCollabJsConfigJson; ?>;
</script>
<script defer src="<?php echo h(asset_url('assets/js/public_affairs_collaboration.js') . '?v=' . (string)@filemtime(dirname(dirname(dirname(__DIR__))) . '/public/assets/js/public_affairs_collaboration.js')); ?>"></script>
<?php $GLOBALS['pa_collab_stage'] = 'collaboration_done'; ?>
    <?php
    return;
}

$spaceProjectId = isset($_GET['space_project_id']) ? (int)$_GET['space_project_id'] : (isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0);
$section = isset($_GET['section']) ? trim((string)$_GET['section']) : 'home';
$viewMode = isset($_GET['view_mode']) ? trim((string)$_GET['view_mode']) : 'board';
$quickFilter = isset($_GET['quick']) ? trim((string)$_GET['quick']) : 'hide_done';
if ($section === '') $section = 'home';
if (!in_array($section, array('home', 'summary', 'list', 'board', 'pending', 'mine', 'today', 'delayed', 'done', 'all', 'calendar', 'timeline', 'files', 'activity', 'reports', 'settings'), true)) $section = 'home';
if (!in_array($viewMode, array('board', 'list', 'backlog'), true)) $viewMode = 'board';
if ($section === 'list') $viewMode = 'list';
if ($section === 'pending') { $viewMode = 'backlog'; $quickFilter = 'pending'; }
if ($section === 'mine') $quickFilter = 'mine';
if ($section === 'today') $quickFilter = 'today';
if ($section === 'delayed') $quickFilter = 'delayed';
if ($section === 'done') $quickFilter = 'done';
if ($section === 'all') $quickFilter = 'all';
if ($section === 'board' && !isset($_GET['quick'])) $quickFilter = 'hide_done';

$filters = array(
    'project_id' => $spaceProjectId,
    'project_name' => isset($_GET['project_name']) ? $_GET['project_name'] : '',
    'assignee_employee_id' => isset($_GET['assignee_employee_id']) ? $_GET['assignee_employee_id'] : '',
    'requester_employee_id' => isset($_GET['requester_employee_id']) ? $_GET['requester_employee_id'] : '',
    'status' => isset($_GET['status']) ? $_GET['status'] : '',
    'priority' => isset($_GET['priority']) ? $_GET['priority'] : '',
    'task_type' => isset($_GET['task_type']) ? $_GET['task_type'] : '',
    'due_from' => isset($_GET['due_from']) ? $_GET['due_from'] : '',
    'due_to' => isset($_GET['due_to']) ? $_GET['due_to'] : '',
    'keyword' => isset($_GET['keyword']) ? $_GET['keyword'] : '',
);

$GLOBALS['pa_collab_stage'] = 'collaboration_load_tasks';
$allTasks = pa_collab_safe_call('cpms_public_affairs_collab_list_tasks', array(), array());
$GLOBALS['pa_collab_stage'] = 'collaboration_visible_tasks';
$visibleTasks = pa_collab_safe_call('cpms_public_affairs_collab_visible_tasks', array($allTasks, $currentEmployee), array());
$GLOBALS['pa_collab_stage'] = 'collaboration_project_spaces';
$spaceProjects = pa_collab_safe_call('cpms_public_affairs_collab_project_spaces', array($pdo, $projects, $visibleTasks), array());
$selectedSpace = pa_collab_safe_call('cpms_public_affairs_collab_find_project_space', array($spaceProjects, $spaceProjectId), null);
if ($spaceProjectId > 0 && !is_array($selectedSpace)) $spaceProjectId = 0;
if ($spaceProjectId <= 0 && $section !== 'settings') $section = 'home';
$filters['project_id'] = $spaceProjectId;
$GLOBALS['pa_collab_stage'] = 'collaboration_home_summary';
$projectHomeSummary = pa_collab_safe_call('cpms_public_affairs_collab_project_home_summary', array($spaceProjects), array());
$selectedProjectTasks = $spaceProjectId > 0 ? pa_collab_safe_call('cpms_public_affairs_collab_project_tasks', array($visibleTasks, $spaceProjectId), array()) : array();
$selectedProjectStats = pa_collab_safe_call('cpms_public_affairs_collab_project_stats', array($selectedProjectTasks), array());
$selectedProjectActivities = $spaceProjectId > 0 ? pa_collab_safe_call('cpms_public_affairs_collab_project_activities', array($spaceProjectId, 40), array()) : array();
$selectedProjectMainManagerId = $spaceProjectId > 0 ? (int)pa_collab_safe_call('cpms_public_affairs_collab_project_main_manager_id', array($pdo, $spaceProjectId), 0) : 0;
$projectKeyword = isset($_GET['project_keyword']) ? pa_collab_lower_safe(trim((string)$_GET['project_keyword'])) : '';
$homeProjects = array();
foreach ($spaceProjects as $space) {
    if (!is_array($space)) continue;
    if ($projectKeyword !== '') {
        $projectHaystack = pa_collab_lower_safe(
            (isset($space['name']) ? $space['name'] : '') . ' ' .
            (isset($space['client']) ? $space['client'] : '') . ' ' .
            (isset($space['contractor']) ? $space['contractor'] : '') . ' ' .
            (isset($space['manager_name']) ? $space['manager_name'] : '') . ' ' .
            (isset($space['phase']) ? $space['phase'] : '')
        );
        if (strpos($projectHaystack, $projectKeyword) === false) continue;
    }
    $homeProjects[] = $space;
}
$recentProjects = array_slice($homeProjects, 0, 8);
$favoriteProjects = array();
$officialProjects = array();
$draftProjects = array();
foreach ($homeProjects as $space) {
    if (isset($space['favorite']) && (int)$space['favorite'] === 1) $favoriteProjects[] = $space;
    if (isset($space['is_draft']) && (int)$space['is_draft'] === 1) $draftProjects[] = $space;
    else $officialProjects[] = $space;
}
$filteredTasks = pa_collab_safe_call('cpms_public_affairs_collab_apply_filters', array($visibleTasks, $filters), $visibleTasks);
$filteredTasks = pa_collab_safe_call('cpms_public_affairs_collab_apply_quick_filter', array($filteredTasks, $quickFilter, $currentEmployee), $filteredTasks);
$summary = pa_collab_safe_call('cpms_public_affairs_collab_summary', array($spaceProjectId > 0 ? $selectedProjectTasks : $visibleTasks, $currentEmployee), array());
$taskCounts = pa_collab_safe_call('cpms_public_affairs_collab_task_counts', array(), array());
$selectedTaskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
$selectedTask = $selectedTaskId > 0 ? pa_collab_safe_call('cpms_public_affairs_collab_find_task', array($selectedTaskId), null) : null;
if (is_array($selectedTask) && !pa_collab_safe_call('cpms_public_affairs_collab_user_can_view_task', array($selectedTask, $currentEmployee), false)) $selectedTask = null;
$groups = pa_collab_safe_call('cpms_public_affairs_collab_group_by_status', array($filteredTasks, $settings['statuses']), array());
$defaultAssigneeId = isset($settings['default_assignee_employee_id']) ? (int)$settings['default_assignee_employee_id'] : 0;
$paCollabInitiallyOpen = (!empty($paCollabAutoOpen) || $selectedTaskId > 0);

$quickLinks = array(
    'all' => '전체',
    'mine' => '내 담당',
    'today' => '오늘 마감',
    'delayed' => '지연',
    'urgent' => '긴급',
    'approval' => '검토중',
    'contract' => '계약 영향',
    'schedule' => '공기 영향',
    'hide_done' => '완료 숨기기',
);

$sampleCards = array(
    array('task_no' => 'PA-0001', 'task_type' => '변경계약', 'title' => '변경계약 2차 내역 검토', 'project_name' => '샘플 현장 A', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '긴급', 'status' => '진행중', 'due_date' => date('Y-m-d'), 'due_time' => '17:00', 'contract_impact' => '있음', 'schedule_impact' => '확인필요'),
    array('task_no' => 'PA-0002', 'task_type' => '자료 제출', 'title' => '발주처 제출자료 취합', 'project_name' => '샘플 현장 B', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '높음', 'status' => '할 일', 'due_date' => date('Y-m-d', strtotime('+1 day')), 'due_time' => '', 'contract_impact' => '없음', 'schedule_impact' => '없음'),
    array('task_no' => 'PA-0003', 'task_type' => '리스크 검토', 'title' => '공기연장 근거자료 정리', 'project_name' => '샘플 현장 C', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '보통', 'status' => '검토중', 'due_date' => date('Y-m-d', strtotime('+3 day')), 'due_time' => '', 'contract_impact' => '확인필요', 'schedule_impact' => '있음'),
    array('task_no' => 'PA-0004', 'task_type' => '기성/청구', 'title' => '기성청구 첨부자료 검토', 'project_name' => '샘플 현장 D', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '높음', 'status' => '대기', 'due_date' => date('Y-m-d', strtotime('+2 day')), 'due_time' => '10:00', 'contract_impact' => '없음', 'schedule_impact' => '없음'),
    array('task_no' => 'PA-0005', 'task_type' => '내역서 검토', 'title' => '협력업체 견적 비교', 'project_name' => '샘플 현장 E', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '보통', 'status' => '검토중', 'due_date' => date('Y-m-d', strtotime('+5 day')), 'due_time' => '', 'contract_impact' => '확인필요', 'schedule_impact' => '없음'),
);

// 공무 협업툴 Calendar/Timeline: 같은 업무 JSON을 마감일 월간 보기와 기간 막대 보기로 재구성한다.
$calendarMonth = isset($_GET['calendar_month']) ? trim((string)$_GET['calendar_month']) : date('Y-m');
if (!preg_match('/^[0-9]{4}-[0-9]{2}$/', $calendarMonth)) $calendarMonth = date('Y-m');
$calendarFirstTs = strtotime($calendarMonth . '-01');
if ($calendarFirstTs === false) {
    $calendarMonth = date('Y-m');
    $calendarFirstTs = strtotime($calendarMonth . '-01');
}
$calendarDaysInMonth = (int)date('t', $calendarFirstTs);
$calendarStartWeekday = (int)date('w', $calendarFirstTs);
$calendarPrevMonth = date('Y-m', strtotime('-1 month', $calendarFirstTs));
$calendarNextMonth = date('Y-m', strtotime('+1 month', $calendarFirstTs));
$calendarTasksByDate = array();
$calendarNoDueTasks = array();
foreach ($filteredTasks as $task) {
    if (!is_array($task)) continue;
    $dueDate = isset($task['due_date']) ? trim((string)$task['due_date']) : '';
    if ($dueDate !== '' && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $dueDate)) {
        if (substr($dueDate, 0, 7) === $calendarMonth) {
            if (!isset($calendarTasksByDate[$dueDate])) $calendarTasksByDate[$dueDate] = array();
            $calendarTasksByDate[$dueDate][] = $task;
        }
    } else {
        $calendarNoDueTasks[] = $task;
    }
}

$timelineRows = array();
$timelineUnknownTasks = array();
$timelineRangeStartTs = false;
$timelineRangeEndTs = false;
foreach ($filteredTasks as $task) {
    if (!is_array($task)) continue;
    $startDate = isset($task['start_date']) ? trim((string)$task['start_date']) : '';
    $endDate = isset($task['due_date']) ? trim((string)$task['due_date']) : '';
    if ($startDate === '' && $endDate === '') {
        $timelineUnknownTasks[] = $task;
        continue;
    }
    if ($startDate === '') $startDate = $endDate;
    if ($endDate === '') $endDate = $startDate;
    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($startTs === false || $endTs === false) {
        $timelineUnknownTasks[] = $task;
        continue;
    }
    if ($endTs < $startTs) {
        $tmpTs = $startTs;
        $startTs = $endTs;
        $endTs = $tmpTs;
        $tmpDate = $startDate;
        $startDate = $endDate;
        $endDate = $tmpDate;
    }
    if ($timelineRangeStartTs === false || $startTs < $timelineRangeStartTs) $timelineRangeStartTs = $startTs;
    if ($timelineRangeEndTs === false || $endTs > $timelineRangeEndTs) $timelineRangeEndTs = $endTs;
    $timelineRows[] = array('task' => $task, 'start_date' => $startDate, 'end_date' => $endDate, 'start_ts' => $startTs, 'end_ts' => $endTs);
}
if ($timelineRangeStartTs === false) $timelineRangeStartTs = strtotime(date('Y-m-d'));
if ($timelineRangeEndTs === false) $timelineRangeEndTs = strtotime('+7 day', $timelineRangeStartTs);
$timelineRangeStartTs = strtotime('-1 day', $timelineRangeStartTs);
$timelineRangeEndTs = strtotime('+1 day', $timelineRangeEndTs);
$timelineTotalDays = max(1, (int)floor(($timelineRangeEndTs - $timelineRangeStartTs) / 86400) + 1);
$timelineTodayLeft = (int)round(max(0, min(100, ((strtotime(date('Y-m-d')) - $timelineRangeStartTs) / 86400) * 100 / $timelineTotalDays)));
for ($i = 0; $i < count($timelineRows); $i++) {
    $left = (($timelineRows[$i]['start_ts'] - $timelineRangeStartTs) / 86400) * 100 / $timelineTotalDays;
    $width = ((($timelineRows[$i]['end_ts'] - $timelineRows[$i]['start_ts']) / 86400) + 1) * 100 / $timelineTotalDays;
    $timelineRows[$i]['left_pct'] = max(0, min(98, round($left, 2)));
    $timelineRows[$i]['width_pct'] = max(3, min(100 - $timelineRows[$i]['left_pct'], round($width, 2)));
}
$GLOBALS['pa_collab_stage'] = 'collaboration_render_header';
?>

<link rel="stylesheet" href="<?php echo h(asset_url('assets/css/public_affairs_collaboration.css') . '?v=' . (string)@filemtime(dirname(dirname(dirname(__DIR__))) . '/public/assets/css/public_affairs_collaboration.css')); ?>">
<style>
/* 공무 협업툴 접속 fallback: 외부 CSS가 늦게 로드되거나 실패해도 전체화면 모달은 열리게 한다. */
.pa-collab-fullscreen{position:fixed;top:0;right:0;bottom:0;left:0;width:100vw;height:100vh;z-index:99999;display:none;background:rgba(15,23,42,.82);overflow:hidden}
.pa-collab-fullscreen.is-open{display:block}
body.pa-collab-open{overflow:hidden}
</style>

<div id="paCollabFullscreenModal"
     class="pa-collab-fullscreen<?php echo $paCollabInitiallyOpen ? ' is-open' : ''; ?>"
     role="dialog"
     aria-modal="true"
     aria-hidden="<?php echo $paCollabInitiallyOpen ? 'false' : 'true'; ?>"
     aria-label="공무 협업툴"
     data-pa-collab-modal
     data-pa-auto-open="<?php echo $paCollabInitiallyOpen ? '1' : '0'; ?>">
  <section class="pa-collab-shell">
    <header class="pa-collab-header">
      <div class="pa-collab-brand">
        <button type="button" class="pa-collab-menu-button" data-pa-menu-toggle aria-label="공무 협업툴 내부 메뉴">☰</button>
        <div>
          <div class="pa-collab-title">공무 협업툴</div>
          <div class="pa-collab-subtitle">공무 업무카드 기반 협업보드</div>
        </div>
      </div>
      <div class="pa-collab-header-actions">
        <form method="get" action="" class="pa-search pa-collab-header-search">
          <input type="hidden" name="r" value="public_affairs_collab">
          <input type="hidden" name="tab" value="collaboration">
          <input type="hidden" name="space_project_id" value="<?php echo (int)$spaceProjectId; ?>">
          <input type="hidden" name="section" value="<?php echo h($section); ?>">
          <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
          <input type="hidden" name="quick" value="<?php echo h($quickFilter); ?>">
          <span class="pa-muted">검색</span>
          <input name="keyword" value="<?php echo h($filters['keyword']); ?>" placeholder="업무번호, 제목, 내용, 담당자">
          <button type="button" class="pa-search-clear" data-pa-search-clear title="검색 초기화">초기화</button>
          <button type="submit" class="pa-search-submit" title="검색">검색</button>
        </form>
        <div class="pa-view-tabs">
          <a class="<?php echo $section === 'home' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'home', 'space_project_id' => null, 'task_id' => null))); ?>">프로젝트 홈</a>
          <?php if (is_array($selectedSpace)): ?>
            <a class="<?php echo $section === 'summary' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'summary', 'view_mode' => 'board', 'task_id' => null))); ?>">Summary</a>
            <a class="<?php echo $section === 'board' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('view_mode' => 'board', 'section' => 'board', 'task_id' => null))); ?>">Board</a>
            <a class="<?php echo $section === 'list' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('view_mode' => 'list', 'section' => 'list', 'quick' => 'all', 'task_id' => null))); ?>">List</a>
            <a class="<?php echo $section === 'settings' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'settings', 'view' => 'settings', 'task_id' => null))); ?>">Settings</a>
          <?php endif; ?>
        </div>
        <?php if ($canCreateCollab): ?>
          <button type="button" class="pa-btn" data-pa-modal-open="project">프로젝트 만들기</button>
        <?php endif; ?>
        <?php if ($canCreateCollab && is_array($selectedSpace)): ?>
          <button type="button" class="pa-btn pa-btn-primary" data-pa-modal-open="create">업무 만들기</button>
        <?php endif; ?>
        <a class="pa-btn" href="?r=public_affairs_collab#public-affairs-collaboration" target="_blank" rel="noopener" title="공무 협업툴 새 창으로 열기">새 창으로 열기</a>
        <a href="?r=공무&tab=monthly_summary" class="pa-collab-close" data-pa-collab-close aria-label="공무 협업툴 닫기">닫기 ×</a>
      </div>
    </header>
    <div class="pa-collab-body">
<div class="pa-board-app">
  <div class="pa-board-layout">
    <aside class="pa-sidebar">
      <div>
        <div class="pa-sidebar-title">공무 협업툴</div>
        <div class="pa-sidebar-sub">업무카드 전용 보드</div>
      </div>
      <nav class="pa-side-nav">
        <a class="pa-side-link <?php echo $section === 'home' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'home', 'space_project_id' => null, 'task_id' => null))); ?>">프로젝트 홈 <span class="pa-side-count"><?php echo (int)$projectHomeSummary['total']; ?></span></a>
        <?php if (is_array($selectedSpace)): ?>
          <div class="pa-side-project"><?php echo h(isset($selectedSpace['name']) ? $selectedSpace['name'] : '-'); ?></div>
          <a class="pa-side-link <?php echo $section === 'summary' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'summary', 'view_mode' => 'board', 'task_id' => null))); ?>">Summary</a>
          <a class="pa-side-link <?php echo $section === 'list' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'list', 'view_mode' => 'list', 'quick' => 'all', 'task_id' => null))); ?>">List</a>
          <a class="pa-side-link <?php echo $section === 'board' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'board', 'view_mode' => 'board', 'quick' => 'hide_done', 'task_id' => null))); ?>">Board <span class="pa-side-count"><?php echo (int)$summary['all']; ?></span></a>
          <a class="pa-side-link <?php echo $section === 'calendar' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'calendar', 'view_mode' => 'board', 'task_id' => null))); ?>">Calendar</a>
          <a class="pa-side-link <?php echo $section === 'timeline' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'timeline', 'view_mode' => 'board', 'task_id' => null))); ?>">Timeline</a>
          <a class="pa-side-link <?php echo $section === 'files' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'files', 'view_mode' => 'board', 'task_id' => null))); ?>">Files</a>
          <a class="pa-side-link <?php echo $section === 'activity' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'activity', 'view_mode' => 'board', 'task_id' => null))); ?>">Activity</a>
          <a class="pa-side-link <?php echo $section === 'reports' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'reports', 'view_mode' => 'board', 'task_id' => null))); ?>">Reports</a>
          <a class="pa-side-link <?php echo $section === 'settings' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'settings', 'view' => 'settings', 'task_id' => null))); ?>">Settings</a>
        <?php else: ?>
          <div class="pa-muted" style="font-size:12px;padding:8px 10px;">프로젝트를 선택하면 Summary/List/Board가 열립니다.</div>
        <?php endif; ?>
      </nav>
    </aside>

    <main class="pa-main">
      <?php if (!empty($flash) && is_array($flash)): ?>
        <?php
          $flashType = isset($flash['type']) ? (string)$flash['type'] : 'info';
          $flashClass = 'border-blue-200 bg-blue-50 text-blue-800';
          if ($flashType === 'success') $flashClass = 'border-emerald-200 bg-emerald-50 text-emerald-800';
          if ($flashType === 'error' || $flashType === 'danger') $flashClass = 'border-red-200 bg-red-50 text-red-800';
        ?>
        <div class="border rounded-2xl p-4 font-bold <?php echo h($flashClass); ?>"><?php echo h(isset($flash['message']) ? $flash['message'] : ''); ?></div>
      <?php endif; ?>

      <?php if ($section === 'home'): ?>
        <section class="pa-project-home">
          <div class="pa-home-hero">
            <div>
              <div class="pa-title">공무 협업툴 프로젝트 홈</div>
              <div class="pa-desc">기존 CPMS 공무 프로젝트를 Space로 사용하고, 계약 전 업무는 "(가제)" Space로 먼저 시작합니다.</div>
            </div>
            <form method="get" action="" class="pa-home-search">
              <input type="hidden" name="r" value="public_affairs_collab">
              <input type="hidden" name="tab" value="collaboration">
              <input type="hidden" name="section" value="home">
              <input class="pa-field" name="project_keyword" value="<?php echo h(isset($_GET['project_keyword']) ? (string)$_GET['project_keyword'] : ''); ?>" placeholder="프로젝트명, 발주처, 담당자 검색">
              <button type="submit" class="pa-btn pa-btn-dark">검색</button>
              <?php if ($canCreateCollab): ?><button type="button" class="pa-btn pa-btn-primary" data-pa-modal-open="project">프로젝트 만들기</button><?php endif; ?>
            </form>
          </div>

          <div class="pa-summary">
            <div class="pa-summary-card"><span>전체 프로젝트</span><b><?php echo (int)$projectHomeSummary['total']; ?></b></div>
            <div class="pa-summary-card"><span>정식 프로젝트</span><b><?php echo (int)$projectHomeSummary['official']; ?></b></div>
            <div class="pa-summary-card"><span>가제 프로젝트</span><b><?php echo (int)$projectHomeSummary['draft']; ?></b></div>
            <div class="pa-summary-card"><span>지연 업무 있는 프로젝트</span><b><?php echo (int)$projectHomeSummary['delayed_projects']; ?></b></div>
            <div class="pa-summary-card"><span>오늘 마감 업무</span><b><?php echo (int)$projectHomeSummary['today_tasks']; ?></b></div>
          </div>

          <?php pa_project_section('최근 프로젝트', $recentProjects, '표시할 최근 프로젝트가 없습니다.'); ?>
          <?php pa_project_section('즐겨찾기', $favoriteProjects, '즐겨찾기한 프로젝트가 없습니다.'); ?>
          <?php pa_project_section('정식 프로젝트', $officialProjects, '정식 프로젝트가 없습니다.'); ?>
          <?php pa_project_section('가제 프로젝트', $draftProjects, '가제 프로젝트가 없습니다. 프로젝트 만들기로 계약 전 업무를 시작할 수 있습니다.'); ?>
        </section>
      <?php elseif ($section === 'settings'): ?>
        <section class="pa-panel-card">
          <div class="pa-panel-title">공무 협업툴 설정</div>
          <?php if (is_array($selectedSpace)): ?>
            <div class="pa-panel-card" style="margin-bottom:14px;">
              <div class="pa-panel-title">프로젝트 Settings</div>
              <div class="pa-desc"><?php echo h(isset($selectedSpace['name']) ? $selectedSpace['name'] : '-'); ?> · <?php echo (isset($selectedSpace['is_draft']) && (int)$selectedSpace['is_draft'] === 1) ? '가제 프로젝트' : '정식 프로젝트'; ?></div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <a class="pa-btn" href="<?php echo h('?r=project/detail&id=' . (int)$spaceProjectId); ?>" target="_blank" rel="noopener">공무 프로젝트 상세보기</a>
                <a class="pa-btn" href="<?php echo h(pa_collab_url(array('section' => 'summary', 'task_id' => null))); ?>">Summary로 돌아가기</a>
              </div>
              <?php if ($canManageCollab && isset($selectedSpace['is_draft']) && (int)$selectedSpace['is_draft'] === 1): ?>
                <form method="post" action="?r=project/collaboration_action" class="pa-form-grid" style="margin-top:14px;">
                  <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                  <input type="hidden" name="action" value="project_convert">
                  <input type="hidden" name="project_id" value="<?php echo (int)$spaceProjectId; ?>">
                  <input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array('section' => 'settings'))); ?>">
                  <div class="full"><div class="pa-draft-banner">정식 전환 시 "(가제)" prefix가 제거되고, 이후 경영현황/공사섹션 흐름에 포함됩니다.</div></div>
                  <div><label class="pa-muted">프로젝트명</label><input name="name" class="pa-field" value="<?php echo h(cpms_public_affairs_collab_official_project_name(isset($selectedSpace['name']) ? $selectedSpace['name'] : '')); ?>"></div>
                  <div><label class="pa-muted">상태</label><select name="status" class="pa-field"><option value="계약중">계약중</option><option value="진행중">진행중</option><option value="대기중">대기중</option></select></div>
                  <div><label class="pa-muted">발주처</label><input name="client" class="pa-field" value="<?php echo h(isset($selectedSpace['client']) ? $selectedSpace['client'] : ''); ?>"></div>
                  <div><label class="pa-muted">시공사</label><input name="contractor" class="pa-field" value="<?php echo h(isset($selectedSpace['contractor']) ? $selectedSpace['contractor'] : ''); ?>"></div>
                  <div><label class="pa-muted">공사 시작일</label><input type="date" name="start_date" class="pa-field" value="<?php echo h(isset($selectedSpace['start_date']) ? $selectedSpace['start_date'] : ''); ?>"></div>
                  <div><label class="pa-muted">공사 종료일</label><input type="date" name="end_date" class="pa-field" value="<?php echo h(isset($selectedSpace['end_date']) ? $selectedSpace['end_date'] : ''); ?>"></div>
                  <div><label class="pa-muted">계약금액</label><input name="contract_amount" class="pa-field" value="<?php echo h(isset($selectedSpace['contract_amount']) ? $selectedSpace['contract_amount'] : ''); ?>"></div>
                  <div><label class="pa-muted">공사 담당자</label><select name="main_manager_id" class="pa-field"><?php pa_employee_options($employees, $selectedProjectMainManagerId, false); ?></select></div>
                  <div class="full" style="display:flex;justify-content:flex-end;"><button type="submit" class="pa-btn pa-btn-primary">정식 프로젝트 전환</button></div>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (!$canManageCollab): ?>
            <div class="pa-empty">설정은 관리자만 변경할 수 있습니다.</div>
          <?php else: ?>
            <form method="post" action="?r=project/collaboration_action" class="pa-form-grid">
              <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="action" value="settings">
              <input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array('section' => 'settings', 'view' => 'settings'))); ?>">
              <div>
                <div class="pa-panel-title">업무유형 관리</div>
                <textarea name="task_types" rows="10" class="pa-field"><?php echo h(implode("\n", $settings['task_types'])); ?></textarea>
              </div>
              <div>
                <div class="pa-panel-title">상태 컬럼 관리</div>
                <textarea name="statuses" rows="10" class="pa-field"><?php echo h(implode("\n", $settings['statuses'])); ?></textarea>
              </div>
              <div>
                <div class="pa-panel-title">우선순위 관리</div>
                <textarea name="priorities" rows="7" class="pa-field"><?php echo h(implode("\n", $settings['priorities'])); ?></textarea>
              </div>
              <div>
                <div class="pa-panel-title">기본 담당자 설정</div>
                <select name="default_assignee_employee_id" class="pa-field">
                  <option value="0">지정 안 함</option>
                  <?php pa_employee_options($employees, $defaultAssigneeId, false); ?>
                </select>
              </div>
              <div>
                <div class="pa-panel-title">카드 표시 필드 설정</div>
                <textarea name="card_fields" rows="7" class="pa-field"><?php echo h(implode("\n", $settings['card_fields'])); ?></textarea>
              </div>
              <div>
                <div class="pa-panel-title">빠른 필터 설정</div>
                <textarea name="quick_filters" rows="7" class="pa-field"><?php echo h(implode("\n", $settings['quick_filters'])); ?></textarea>
              </div>
              <div class="full" style="display:flex;justify-content:flex-end;gap:8px;">
                <a class="pa-btn" href="<?php echo h(pa_collab_url(array('section' => 'board', 'view' => null))); ?>">보드로 돌아가기</a>
                <button type="submit" class="pa-btn pa-btn-primary">설정 저장</button>
              </div>
            </form>
          <?php endif; ?>
        </section>
      <?php else: ?>
        <?php if (is_array($selectedSpace)): ?>
          <section class="pa-space-header">
            <div>
              <div class="pa-space-title-line">
                <span class="pa-space-type <?php echo (isset($selectedSpace['is_draft']) && (int)$selectedSpace['is_draft'] === 1) ? 'is-draft' : 'is-official'; ?>"><?php echo (isset($selectedSpace['is_draft']) && (int)$selectedSpace['is_draft'] === 1) ? '가제' : '정식'; ?></span>
                <h2><?php echo h(isset($selectedSpace['name']) ? $selectedSpace['name'] : '-'); ?></h2>
              </div>
              <div class="pa-desc">
                <?php echo h(isset($selectedSpace['client']) && trim((string)$selectedSpace['client']) !== '' ? $selectedSpace['client'] : '발주처 미입력'); ?>
                · 담당 <?php echo h(isset($selectedSpace['manager_name']) && trim((string)$selectedSpace['manager_name']) !== '' ? $selectedSpace['manager_name'] : '-'); ?>
                · 기간 <?php echo h((isset($selectedSpace['start_date']) && trim((string)$selectedSpace['start_date']) !== '' ? $selectedSpace['start_date'] : '-') . ' ~ ' . (isset($selectedSpace['end_date']) && trim((string)$selectedSpace['end_date']) !== '' ? $selectedSpace['end_date'] : '-')); ?>
              </div>
              <?php if (isset($selectedSpace['is_draft']) && (int)$selectedSpace['is_draft'] === 1): ?>
                <div class="pa-draft-banner">이 프로젝트는 아직 정식 프로젝트가 아닙니다. 정식 전환 전에는 경영현황/공사섹션에 반영되지 않습니다.</div>
              <?php endif; ?>
            </div>
            <div class="pa-space-header-actions">
              <a class="pa-btn" href="<?php echo h('?r=project/detail&id=' . (int)$spaceProjectId); ?>" target="_blank" rel="noopener">프로젝트 상세</a>
              <?php if (isset($selectedSpace['is_draft']) && (int)$selectedSpace['is_draft'] === 1): ?>
                <a class="pa-btn pa-btn-primary" href="<?php echo h(pa_collab_url(array('section' => 'settings', 'task_id' => null))); ?>">정식 전환</a>
              <?php endif; ?>
            </div>
          </section>

          <nav class="pa-project-tabs">
            <?php foreach (array('summary'=>'Summary','list'=>'List','board'=>'Board','calendar'=>'Calendar','timeline'=>'Timeline','files'=>'Files','activity'=>'Activity','reports'=>'Reports','settings'=>'Settings') as $tabKey => $tabLabel): ?>
              <a class="<?php echo $section === $tabKey ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => $tabKey, 'view_mode' => ($tabKey === 'list' ? 'list' : 'board'), 'quick' => ($tabKey === 'list' ? 'all' : 'hide_done'), 'task_id' => null))); ?>"><?php echo h($tabLabel); ?></a>
            <?php endforeach; ?>
          </nav>
        <?php endif; ?>

        <?php if ($section === 'summary'): ?>
          <div class="pa-summary">
            <div class="pa-summary-card"><span>전체 업무</span><b><?php echo (int)$selectedProjectStats['total']; ?></b></div>
            <div class="pa-summary-card"><span>완료 업무</span><b><?php echo (int)$selectedProjectStats['done']; ?></b></div>
            <div class="pa-summary-card"><span>진행중 업무</span><b><?php echo (int)$selectedProjectStats['active']; ?></b></div>
            <div class="pa-summary-card"><span>지연 업무</span><b><?php echo (int)$selectedProjectStats['delayed']; ?></b></div>
            <div class="pa-summary-card"><span>오늘 마감</span><b><?php echo (int)$selectedProjectStats['today']; ?></b></div>
          </div>
          <section class="pa-summary-grid">
            <div class="pa-panel-card">
              <div class="pa-panel-title">상태별 요약</div>
              <?php foreach ($settings['statuses'] as $status): $count = isset($selectedProjectStats['by_status'][$status]) ? (int)$selectedProjectStats['by_status'][$status] : 0; ?>
                <div class="pa-report-row"><span><?php echo h($status); ?></span><b><?php echo $count; ?></b><i style="width:<?php echo $selectedProjectStats['total'] > 0 ? min(100, round($count * 100 / $selectedProjectStats['total'])) : 0; ?>%"></i></div>
              <?php endforeach; ?>
            </div>
            <div class="pa-panel-card">
              <div class="pa-panel-title">우선순위별 요약</div>
              <?php foreach ($settings['priorities'] as $priority): $count = isset($selectedProjectStats['by_priority'][$priority]) ? (int)$selectedProjectStats['by_priority'][$priority] : 0; ?>
                <div class="pa-report-row"><span><?php echo h($priority); ?></span><b><?php echo $count; ?></b><i style="width:<?php echo $selectedProjectStats['total'] > 0 ? min(100, round($count * 100 / $selectedProjectStats['total'])) : 0; ?>%"></i></div>
              <?php endforeach; ?>
            </div>
            <div class="pa-panel-card">
              <div class="pa-panel-title">최근 활동</div>
              <?php if (count($selectedProjectActivities) === 0): ?><div class="pa-muted">프로젝트 활동이 없습니다.</div><?php endif; ?>
              <?php foreach (array_slice($selectedProjectActivities, 0, 8) as $activity): ?><div class="pa-history"><b><?php echo h(isset($activity['action']) ? $activity['action'] : '-'); ?></b><div class="pa-muted"><?php echo h(isset($activity['actor_name']) ? $activity['actor_name'] : '-'); ?> · <?php echo h(isset($activity['created_at']) ? $activity['created_at'] : ''); ?></div></div><?php endforeach; ?>
            </div>
            <div class="pa-panel-card">
              <div class="pa-panel-title">마감/영향 업무</div>
              <div class="pa-prop">
                <div class="pa-prop-row"><b>이번 주 마감</b><span><?php echo (int)$selectedProjectStats['week']; ?>건</span></div>
                <div class="pa-prop-row"><b>계약 영향</b><span><?php echo (int)$selectedProjectStats['contract_impact']; ?>건</span></div>
                <div class="pa-prop-row"><b>공기 영향</b><span><?php echo (int)$selectedProjectStats['schedule_impact']; ?>건</span></div>
              </div>
            </div>
          </section>
        <?php elseif ($section === 'calendar'): ?>
          <section class="pa-panel-card">
            <div class="pa-panel-title">Calendar</div>
            <div class="pa-calendar-toolbar">
              <a class="pa-btn" href="<?php echo h(pa_collab_url(array('section' => 'calendar', 'calendar_month' => $calendarPrevMonth, 'task_id' => null))); ?>">이전 월</a>
              <strong><?php echo h(date('Y년 m월', $calendarFirstTs)); ?></strong>
              <a class="pa-btn" href="<?php echo h(pa_collab_url(array('section' => 'calendar', 'calendar_month' => date('Y-m'), 'task_id' => null))); ?>">오늘</a>
              <a class="pa-btn" href="<?php echo h(pa_collab_url(array('section' => 'calendar', 'calendar_month' => $calendarNextMonth, 'task_id' => null))); ?>">다음 월</a>
            </div>
            <div class="pa-calendar-month">
              <?php foreach (array('일','월','화','수','목','금','토') as $weekday): ?><div class="pa-calendar-weekday"><?php echo h($weekday); ?></div><?php endforeach; ?>
              <?php for ($blank = 0; $blank < $calendarStartWeekday; $blank++): ?><div class="pa-calendar-day is-empty"></div><?php endfor; ?>
              <?php for ($day = 1; $day <= $calendarDaysInMonth; $day++): ?>
                <?php $dateKey = $calendarMonth . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT); $dayTasks = isset($calendarTasksByDate[$dateKey]) ? $calendarTasksByDate[$dateKey] : array(); ?>
                <div class="pa-calendar-day <?php echo $dateKey === date('Y-m-d') ? 'is-today' : ''; ?>">
                  <div class="pa-calendar-date"><b><?php echo (int)$day; ?></b><span><?php echo count($dayTasks); ?>건</span></div>
                  <?php foreach ($dayTasks as $task): ?>
                    <a data-pa-detail-link class="pa-calendar-task <?php echo pa_priority_class(isset($task['priority']) ? $task['priority'] : ''); ?>" href="<?php echo h(pa_collab_url(array('task_id' => isset($task['id']) ? (int)$task['id'] : 0))); ?>">
                      <b><?php echo h(cpms_public_affairs_collab_task_no($task)); ?></b>
                      <span><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endfor; ?>
            </div>
            <?php if (count($calendarNoDueTasks) > 0): ?>
              <div class="pa-calendar-nodue">
                <div class="pa-panel-title">마감일 없는 업무</div>
                <?php foreach ($calendarNoDueTasks as $task): ?>
                  <a data-pa-detail-link class="pa-calendar-task" href="<?php echo h(pa_collab_url(array('task_id' => isset($task['id']) ? (int)$task['id'] : 0))); ?>"><b><?php echo h(cpms_public_affairs_collab_task_no($task)); ?></b><span><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></span></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        <?php elseif ($section === 'timeline'): ?>
          <section class="pa-panel-card">
            <div class="pa-panel-title">Timeline</div>
            <?php if (count($timelineRows) === 0 && count($timelineUnknownTasks) === 0): ?>
              <div class="pa-empty">표시할 일정 업무가 없습니다.</div>
            <?php else: ?>
              <div class="pa-timeline-chart">
                <div class="pa-timeline-head">
                  <div>업무</div>
                  <div class="pa-timeline-scale">
                    <span><?php echo h(date('Y-m-d', $timelineRangeStartTs)); ?></span>
                    <i style="left:<?php echo (int)$timelineTodayLeft; ?>%"></i>
                    <span><?php echo h(date('Y-m-d', $timelineRangeEndTs)); ?></span>
                  </div>
                </div>
                <?php foreach ($timelineRows as $row): $task = $row['task']; $isDelayed = cpms_public_affairs_collab_is_delayed($task); ?>
                  <div class="pa-timeline-chart-row">
                    <a data-pa-detail-link class="pa-timeline-label" href="<?php echo h(pa_collab_url(array('task_id' => isset($task['id']) ? (int)$task['id'] : 0))); ?>">
                      <b><?php echo h(cpms_public_affairs_collab_task_no($task)); ?></b>
                      <span><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></span>
                      <em><?php echo h(isset($task['assignee_name']) ? $task['assignee_name'] : '-'); ?></em>
                    </a>
                    <div class="pa-timeline-track">
                      <a data-pa-detail-link class="pa-timeline-bar <?php echo $isDelayed ? 'is-delayed' : ''; ?>" style="left:<?php echo h($row['left_pct']); ?>%;width:<?php echo h($row['width_pct']); ?>%;" href="<?php echo h(pa_collab_url(array('task_id' => isset($task['id']) ? (int)$task['id'] : 0))); ?>">
                        <span><?php echo h($row['start_date'] . ' ~ ' . $row['end_date']); ?></span>
                      </a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if (count($timelineUnknownTasks) > 0): ?>
                <div class="pa-timeline-unknown">
                  <div class="pa-panel-title">일정 미정 업무</div>
                  <?php foreach ($timelineUnknownTasks as $task): ?>
                    <a data-pa-detail-link class="pa-calendar-task" href="<?php echo h(pa_collab_url(array('task_id' => isset($task['id']) ? (int)$task['id'] : 0))); ?>"><b><?php echo h(cpms_public_affairs_collab_task_no($task)); ?></b><span><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></span></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </section>
        <?php elseif ($section === 'files'): ?>
          <section class="pa-table-wrap"><table class="pa-table"><thead><tr><th>업무번호</th><th>파일명</th><th>업로드자</th><th>업로드일시</th><th>크기</th><th>다운로드</th></tr></thead><tbody>
            <?php $fileShown = 0; foreach ($selectedProjectTasks as $task): foreach (cpms_public_affairs_collab_files(isset($task['id']) ? (int)$task['id'] : 0) as $file): $fileShown++; ?><tr><td><?php echo h(cpms_public_affairs_collab_task_no($task)); ?></td><td><?php echo h(isset($file['original_name']) ? $file['original_name'] : 'file'); ?></td><td><?php echo h(isset($file['uploaded_by_name']) ? $file['uploaded_by_name'] : '-'); ?></td><td><?php echo h(isset($file['uploaded_at']) ? $file['uploaded_at'] : '-'); ?></td><td><?php echo h(pa_file_size(isset($file['file_size']) ? $file['file_size'] : 0)); ?></td><td><a class="pa-btn" href="?r=project/collaboration_file&id=<?php echo (int)$file['id']; ?>">다운로드</a></td></tr><?php endforeach; endforeach; ?>
            <?php if ($fileShown === 0): ?><tr><td colspan="6" class="pa-muted">프로젝트 파일이 없습니다.</td></tr><?php endif; ?>
          </tbody></table></section>
        <?php elseif ($section === 'activity'): ?>
          <section class="pa-panel-card">
            <div class="pa-panel-title">Activity</div>
            <?php if (count($selectedProjectActivities) === 0): ?><div class="pa-muted">프로젝트 활동이 없습니다.</div><?php endif; ?>
            <?php foreach ($selectedProjectActivities as $activity): ?><div class="pa-history"><b><?php echo h(isset($activity['action']) ? $activity['action'] : '-'); ?></b><div class="pa-muted"><?php echo h(isset($activity['actor_name']) ? $activity['actor_name'] : '-'); ?> · <?php echo h(isset($activity['created_at']) ? $activity['created_at'] : ''); ?></div><div><?php echo h(isset($activity['message']) ? $activity['message'] : ''); ?></div></div><?php endforeach; ?>
          </section>
        <?php elseif ($section === 'reports'): ?>
          <section class="pa-summary-grid">
            <div class="pa-panel-card"><div class="pa-panel-title">완료율</div><div class="pa-big-number"><?php echo $selectedProjectStats['total'] > 0 ? (int)round($selectedProjectStats['done'] * 100 / $selectedProjectStats['total']) : 0; ?>%</div></div>
            <div class="pa-panel-card"><div class="pa-panel-title">지연 업무</div><div class="pa-big-number"><?php echo (int)$selectedProjectStats['delayed']; ?></div></div>
            <div class="pa-panel-card"><div class="pa-panel-title">담당자별 업무 수</div><?php foreach ($selectedProjectStats['by_assignee'] as $name => $count): ?><div class="pa-report-row"><span><?php echo h($name); ?></span><b><?php echo (int)$count; ?></b><i style="width:<?php echo $selectedProjectStats['total'] > 0 ? min(100, round($count * 100 / $selectedProjectStats['total'])) : 0; ?>%"></i></div><?php endforeach; ?></div>
            <div class="pa-panel-card"><div class="pa-panel-title">계약/공기 영향</div><div class="pa-prop"><div class="pa-prop-row"><b>계약 영향</b><span><?php echo (int)$selectedProjectStats['contract_impact']; ?>건</span></div><div class="pa-prop-row"><b>공기 영향</b><span><?php echo (int)$selectedProjectStats['schedule_impact']; ?>건</span></div></div></div>
          </section>
        <?php else: ?>
        <div class="pa-summary">
          <div class="pa-summary-card"><span>전체 업무</span><b><?php echo (int)$summary['all']; ?></b></div>
          <div class="pa-summary-card"><span>내 담당</span><b><?php echo (int)$summary['mine']; ?></b></div>
          <div class="pa-summary-card"><span>오늘 마감</span><b><?php echo (int)$summary['today']; ?></b></div>
          <div class="pa-summary-card"><span>지연</span><b><?php echo (int)$summary['delayed']; ?></b></div>
          <div class="pa-summary-card"><span>완료</span><b><?php echo (int)$summary['done']; ?></b></div>
        </div>

        <section class="pa-filterbar">
          <div class="pa-quick">
            <?php foreach ($quickLinks as $quickKey => $quickLabel): ?>
              <a class="pa-chip <?php echo $quickFilter === $quickKey ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('quick' => $quickKey, 'section' => 'board', 'task_id' => null))); ?>"><?php echo h($quickLabel); ?></a>
            <?php endforeach; ?>
          </div>
          <form method="get" action="" class="pa-filter-grid">
            <input type="hidden" name="r" value="public_affairs_collab">
            <input type="hidden" name="tab" value="collaboration">
            <input type="hidden" name="space_project_id" value="<?php echo (int)$spaceProjectId; ?>">
            <input type="hidden" name="section" value="<?php echo h($section); ?>">
            <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
            <input type="hidden" name="quick" value="<?php echo h($quickFilter); ?>">
            <input class="pa-field" name="project_name" value="<?php echo h($filters['project_name']); ?>" placeholder="현장명/프로젝트명">
            <select class="pa-field" name="assignee_employee_id"><option value="">담당자 전체</option><?php pa_employee_options($employees, $filters['assignee_employee_id'], false); ?></select>
            <select class="pa-field" name="requester_employee_id"><option value="">요청자 전체</option><?php pa_employee_options($employees, $filters['requester_employee_id'], false); ?></select>
            <select class="pa-field" name="task_type"><option value="">업무유형 전체</option><?php foreach ($settings['task_types'] as $type): ?><option value="<?php echo h($type); ?>" <?php echo pa_selected($filters['task_type'], $type); ?>><?php echo h($type); ?></option><?php endforeach; ?></select>
            <select class="pa-field" name="priority"><option value="">우선순위 전체</option><?php foreach ($settings['priorities'] as $priority): ?><option value="<?php echo h($priority); ?>" <?php echo pa_selected($filters['priority'], $priority); ?>><?php echo h($priority); ?></option><?php endforeach; ?></select>
            <select class="pa-field" name="status"><option value="">상태 전체</option><?php foreach ($settings['statuses'] as $status): ?><option value="<?php echo h($status); ?>" <?php echo pa_selected($filters['status'], $status); ?>><?php echo h($status); ?></option><?php endforeach; ?></select>
            <input class="pa-field" type="date" name="due_to" value="<?php echo h($filters['due_to']); ?>">
            <button type="submit" class="pa-btn pa-btn-dark">필터 적용</button>
          </form>
        </section>

        <?php if ($viewMode === 'list'): ?>
          <section class="pa-table-wrap">
            <table class="pa-table">
              <thead><tr><th>업무번호</th><th>업무유형</th><th>제목</th><th>현장명/프로젝트명</th><th>요청자</th><th>담당자</th><th>상태</th><th>우선순위</th><th>마감일</th><th>댓글</th><th>첨부</th><th>계약 영향</th><th>공기 영향</th><th>생성일시</th><th>수정일시</th><th>상세</th></tr></thead>
              <tbody>
              <?php if (count($filteredTasks) === 0): ?><tr><td colspan="16" class="pa-muted">조회된 업무카드가 없습니다.</td></tr><?php endif; ?>
              <?php foreach ($filteredTasks as $task): ?>
                <?php
                  $taskId = isset($task['id']) ? (int)$task['id'] : 0;
                  $taskNo = cpms_public_affairs_collab_task_no($task);
                  $searchText = $taskNo . ' ' . (isset($task['title']) ? $task['title'] : '') . ' ' . (isset($task['content']) ? $task['content'] : '') . ' ' . (isset($task['project_name']) ? $task['project_name'] : '') . ' ' . (isset($task['task_type']) ? $task['task_type'] : '') . ' ' . (isset($task['assignee_name']) ? $task['assignee_name'] : '') . ' ' . (isset($task['requester_name']) ? $task['requester_name'] : '') . ' ' . cpms_public_affairs_collab_task_ref_names($task);
                ?>
                <tr data-pa-list-task-id="<?php echo (int)$taskId; ?>" data-pa-search="<?php echo h($searchText); ?>">
                  <td><b><?php echo h(cpms_public_affairs_collab_task_no($task)); ?></b></td>
                  <td><?php echo h(isset($task['task_type']) ? $task['task_type'] : '-'); ?></td>
                  <td><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></td>
                  <td><?php echo h(isset($task['project_name']) ? $task['project_name'] : '-'); ?></td>
                  <td><?php echo h(isset($task['requester_name']) ? $task['requester_name'] : '-'); ?></td>
                  <td><?php echo h(isset($task['assignee_name']) ? $task['assignee_name'] : '-'); ?></td>
                  <td><span class="pa-badge <?php echo h(pa_status_class(isset($task['status']) ? $task['status'] : '')); ?>"><?php echo h(isset($task['status']) ? $task['status'] : '-'); ?></span></td>
                  <td><span class="pa-badge <?php echo h(pa_priority_class(isset($task['priority']) ? $task['priority'] : '')); ?>"><?php echo h(isset($task['priority']) ? $task['priority'] : '-'); ?></span></td>
                  <td><?php echo h(pa_due_text($task)); ?></td>
                  <td><?php echo (int)cpms_public_affairs_collab_count_for_task($taskCounts, $taskId, 'comments'); ?></td>
                  <td><?php echo (int)cpms_public_affairs_collab_count_for_task($taskCounts, $taskId, 'files'); ?></td>
                  <td><?php echo h(isset($task['contract_impact']) ? $task['contract_impact'] : '없음'); ?></td>
                  <td><?php echo h(isset($task['schedule_impact']) ? $task['schedule_impact'] : '없음'); ?></td>
                  <td><?php echo h(isset($task['created_at']) ? substr((string)$task['created_at'], 0, 16) : '-'); ?></td>
                  <td><?php echo h(isset($task['updated_at']) ? substr((string)$task['updated_at'], 0, 16) : '-'); ?></td>
                  <td><a class="pa-btn" data-pa-detail-link href="<?php echo h(pa_collab_url(array('task_id' => $taskId))); ?>">열기</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </section>
        <?php elseif ($viewMode === 'backlog'): ?>
          <section class="pa-backlog">
            <?php if (count($filteredTasks) === 0): ?><div class="pa-empty">대기 업무가 없습니다.</div><?php endif; ?>
            <?php foreach ($filteredTasks as $task): ?>
              <?php
                $taskId = isset($task['id']) ? (int)$task['id'] : 0;
                $taskNo = cpms_public_affairs_collab_task_no($task);
                $canEdit = cpms_public_affairs_collab_user_can_edit_task($task, $currentEmployee);
                $searchText = $taskNo . ' ' . (isset($task['title']) ? $task['title'] : '') . ' ' . (isset($task['content']) ? $task['content'] : '') . ' ' . (isset($task['project_name']) ? $task['project_name'] : '') . ' ' . (isset($task['task_type']) ? $task['task_type'] : '') . ' ' . (isset($task['assignee_name']) ? $task['assignee_name'] : '') . ' ' . (isset($task['requester_name']) ? $task['requester_name'] : '') . ' ' . cpms_public_affairs_collab_task_ref_names($task);
              ?>
              <div class="pa-backlog-row" data-pa-list-task-id="<?php echo (int)$taskId; ?>" data-pa-search="<?php echo h($searchText); ?>">
                <div><div><span class="pa-no"><?php echo h($taskNo); ?></span> <span class="pa-type"><?php echo h(isset($task['task_type']) ? $task['task_type'] : '-'); ?></span></div><a class="pa-card-title" data-pa-detail-link href="<?php echo h(pa_collab_url(array('task_id' => $taskId))); ?>"><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></a><div class="pa-muted"><?php echo h(isset($task['project_name']) ? $task['project_name'] : '-'); ?></div></div>
                <?php if ($canEdit): ?>
                  <form method="post" action="?r=project/collaboration_action" class="pa-card-select" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="quick_update"><input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>"><input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array())); ?>">
                    <select name="priority"><?php foreach ($settings['priorities'] as $priority): ?><option value="<?php echo h($priority); ?>" <?php echo pa_selected(isset($task['priority']) ? $task['priority'] : '', $priority); ?>><?php echo h($priority); ?></option><?php endforeach; ?></select><button type="submit">우선순위</button>
                  </form>
                  <form method="post" action="?r=project/collaboration_action" class="pa-card-select" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="quick_update"><input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>"><input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array())); ?>">
                    <select name="assignee_employee_id"><?php pa_employee_options($employees, isset($task['assignee_employee_id']) ? $task['assignee_employee_id'] : 0, false); ?></select><button type="submit">담당</button>
                  </form>
                  <form method="post" action="?r=project/collaboration_action" class="pa-card-select" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="quick_update"><input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>"><input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array())); ?>">
                    <input type="date" name="due_date" value="<?php echo h(isset($task['due_date']) ? $task['due_date'] : ''); ?>" class="pa-field"><button type="submit">마감</button>
                  </form>
                  <form method="post" action="?r=project/collaboration_action" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="quick_update"><input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>"><input type="hidden" name="status" value="진행중"><input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array())); ?>"><button class="pa-btn pa-btn-primary" type="submit">진행중으로 이동</button>
                  </form>
                <?php else: ?>
                  <div class="pa-muted">조회/댓글 가능</div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </section>
        <?php else: ?>
          <section class="pa-board-wrap">
            <?php if (count($visibleTasks) === 0): ?>
              <div class="pa-empty" style="margin-bottom:12px;">아직 저장된 업무카드가 없습니다. 아래 카드는 저장되지 않는 샘플 미리보기입니다.</div>
            <?php endif; ?>
            <div class="pa-kanban">
              <?php foreach ($groups as $status => $items): ?>
                <div class="pa-column" data-pa-drop-status="<?php echo h($status); ?>">
                  <div class="pa-column-head">
                    <div class="pa-column-title"><?php echo h($status); ?></div>
                    <div class="pa-column-meta"><span class="pa-count"><?php echo count($items); ?></span><span class="pa-wip">WIP <?php echo count($items); ?></span></div>
                  </div>
                  <div class="pa-column-body">
                    <?php if (count($items) === 0): ?><div class="pa-empty" style="padding:18px;">카드 없음</div><?php endif; ?>
                    <?php foreach ($items as $task): ?>
                      <?php
                        $taskId = isset($task['id']) ? (int)$task['id'] : 0;
                        $taskNo = cpms_public_affairs_collab_task_no($task);
                        $isDelayed = cpms_public_affairs_collab_is_delayed($task);
                        $isToday = cpms_public_affairs_collab_is_due_today($task);
                        $isUrgent = isset($task['priority']) && (string)$task['priority'] === '긴급';
                        $isDone = isset($task['status']) && (string)$task['status'] === '완료';
                        $canEdit = cpms_public_affairs_collab_user_can_edit_task($task, $currentEmployee);
                        $commentCount = cpms_public_affairs_collab_count_for_task($taskCounts, $taskId, 'comments');
                        $fileCount = cpms_public_affairs_collab_count_for_task($taskCounts, $taskId, 'files');
                        $searchText = $taskNo . ' ' . (isset($task['title']) ? $task['title'] : '') . ' ' . (isset($task['content']) ? $task['content'] : '') . ' ' . (isset($task['project_name']) ? $task['project_name'] : '') . ' ' . (isset($task['task_type']) ? $task['task_type'] : '') . ' ' . (isset($task['assignee_name']) ? $task['assignee_name'] : '') . ' ' . (isset($task['requester_name']) ? $task['requester_name'] : '') . ' ' . cpms_public_affairs_collab_task_ref_names($task);
                      ?>
                      <div class="pa-card <?php echo $isUrgent ? 'is-urgent ' : ''; ?><?php echo $isDelayed ? 'is-delayed ' : ''; ?><?php echo $isDone ? 'is-done ' : ''; ?>" <?php echo $canEdit ? 'draggable="true"' : ''; ?> data-pa-task-id="<?php echo (int)$taskId; ?>" data-pa-task-no="<?php echo h($taskNo); ?>" data-pa-status="<?php echo h(isset($task['status']) ? $task['status'] : ''); ?>" data-pa-can-edit="<?php echo $canEdit ? '1' : '0'; ?>" data-pa-search="<?php echo h($searchText); ?>">
                        <div class="pa-card-top"><span class="pa-no"><?php echo h($taskNo); ?></span><span class="pa-type"><?php echo h(isset($task['task_type']) ? $task['task_type'] : '-'); ?></span></div>
                        <a class="pa-card-title" data-pa-detail-link href="<?php echo h(pa_collab_url(array('task_id' => $taskId))); ?>"><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></a>
                        <div class="pa-card-meta">
                          <div><?php echo h(isset($task['project_name']) ? $task['project_name'] : '-'); ?></div>
                          <div>담당 <?php echo h(isset($task['assignee_name']) ? $task['assignee_name'] : '-'); ?> · 요청 <?php echo h(isset($task['requester_name']) ? $task['requester_name'] : '-'); ?></div>
                          <div>마감 <?php echo h(pa_due_text($task)); ?></div>
                        </div>
                        <div class="pa-badges">
                          <span class="pa-badge <?php echo h(pa_priority_class(isset($task['priority']) ? $task['priority'] : '')); ?>"><?php echo h(isset($task['priority']) ? $task['priority'] : '-'); ?></span>
                          <?php if ($isToday): ?><span class="pa-badge pa-today">오늘 마감</span><?php endif; ?>
                          <?php if ($isDelayed): ?><span class="pa-badge pa-delayed">지연</span><?php endif; ?>
                          <?php if (isset($task['contract_impact']) && (string)$task['contract_impact'] !== '없음'): ?><span class="pa-badge pa-impact">계약 <?php echo h($task['contract_impact']); ?></span><?php endif; ?>
                          <?php if (isset($task['schedule_impact']) && (string)$task['schedule_impact'] !== '없음'): ?><span class="pa-badge pa-impact">공기 <?php echo h($task['schedule_impact']); ?></span><?php endif; ?>
                          <span class="pa-badge" data-pa-comment-count>댓글 <?php echo (int)$commentCount; ?></span><span class="pa-badge" data-pa-file-count>첨부 <?php echo (int)$fileCount; ?></span>
                        </div>
                        <?php if ($canEdit): ?>
                          <form method="post" action="?r=project/collaboration_action" class="pa-card-select">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="quick_update"><input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>"><input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array())); ?>">
                            <select name="status"><?php foreach ($settings['statuses'] as $st): ?><option value="<?php echo h($st); ?>" <?php echo pa_selected(isset($task['status']) ? $task['status'] : '', $st); ?>><?php echo h($st); ?></option><?php endforeach; ?></select><button type="submit">이동</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                    <?php if (count($visibleTasks) === 0 && $status !== '완료'): ?>
                      <?php foreach ($sampleCards as $sample): if ($sample['status'] !== $status) continue; ?>
                        <div class="pa-card is-sample <?php echo $sample['priority'] === '긴급' ? 'is-urgent' : ''; ?>">
                          <div class="pa-card-top"><span class="pa-no"><?php echo h($sample['task_no']); ?></span><span class="pa-type"><?php echo h($sample['task_type']); ?></span></div>
                          <div class="pa-card-title"><?php echo h($sample['title']); ?></div>
                          <div class="pa-card-meta"><div><?php echo h($sample['project_name']); ?></div><div>담당 <?php echo h($sample['assignee_name']); ?> · 요청 <?php echo h($sample['requester_name']); ?></div><div>마감 <?php echo h(pa_due_text($sample)); ?></div></div>
                          <div class="pa-badges"><span class="pa-badge <?php echo h(pa_priority_class($sample['priority'])); ?>"><?php echo h($sample['priority']); ?></span><span class="pa-badge pa-today">샘플</span></div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>
      <?php endif; ?>
    </main>
  </div>
</div>

<form id="paStatusMoveForm" method="post" action="?r=project/collaboration_action" style="display:none;">
  <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
  <input type="hidden" name="action" value="quick_update">
  <input type="hidden" name="task_id" value="">
  <input type="hidden" name="status" value="">
  <input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array())); ?>">
</form>

<?php if (is_array($selectedTask)): ?>
  <?php
    $selectedTaskId = isset($selectedTask['id']) ? (int)$selectedTask['id'] : 0;
    $selectedTaskNo = cpms_public_affairs_collab_task_no($selectedTask);
    $comments = cpms_public_affairs_collab_comments($selectedTaskId);
    $files = cpms_public_affairs_collab_files($selectedTaskId);
    $history = cpms_public_affairs_collab_history($selectedTaskId);
    $canEditSelected = cpms_public_affairs_collab_user_can_edit_task($selectedTask, $currentEmployee);
  ?>
  <aside class="pa-detail-panel">
    <div class="pa-detail-head">
      <div>
        <div><span class="pa-no"><?php echo h($selectedTaskNo); ?></span> <span class="pa-badge <?php echo h(pa_status_class(isset($selectedTask['status']) ? $selectedTask['status'] : '')); ?>"><?php echo h(isset($selectedTask['status']) ? $selectedTask['status'] : '-'); ?></span> <span class="pa-badge <?php echo h(pa_priority_class(isset($selectedTask['priority']) ? $selectedTask['priority'] : '')); ?>"><?php echo h(isset($selectedTask['priority']) ? $selectedTask['priority'] : '-'); ?></span></div>
        <div class="pa-title" style="font-size:21px;margin-top:8px;"><?php echo h(isset($selectedTask['title']) ? $selectedTask['title'] : '-'); ?></div>
      </div>
      <a class="pa-btn" href="<?php echo h(pa_collab_url(array('task_id' => null))); ?>">닫기</a>
    </div>
    <div class="pa-detail-body">
      <form method="post" action="?r=project/collaboration_action" class="pa-detail-grid">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="task_id" value="<?php echo (int)$selectedTaskId; ?>">
        <input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array('task_id' => $selectedTaskId))); ?>">
        <input type="hidden" name="reference_employee_ids_present" value="1">
        <div class="pa-panel-card">
          <div class="pa-panel-title"><?php echo h($selectedTaskNo); ?> 상세내용</div>
          <div class="pa-form-grid">
            <div class="full"><input name="title" value="<?php echo h(isset($selectedTask['title']) ? $selectedTask['title'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="pa-field"></div>
            <div class="full"><textarea name="content" rows="8" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="pa-field" placeholder="상세내용"><?php echo h(isset($selectedTask['content']) ? $selectedTask['content'] : ''); ?></textarea></div>
            <div class="full"><input name="document_link" value="<?php echo h(isset($selectedTask['document_link']) ? $selectedTask['document_link'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="pa-field" placeholder="관련 문서 링크"></div>
          </div>
          <?php if ($canEditSelected): ?>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;flex-wrap:wrap;">
              <button type="submit" name="state_action" value="complete" class="pa-btn pa-btn-primary">완료 처리</button>
              <?php if (in_array('반려', $settings['statuses'], true)): ?>
              <button type="submit" name="state_action" value="reject" class="pa-btn">반려 처리</button>
              <?php endif; ?>
              <button type="submit" name="state_action" value="hold" class="pa-btn">보류 처리</button>
              <button type="submit" class="pa-btn pa-btn-dark">변경 저장</button>
            </div>
          <?php endif; ?>
        </div>
        <div class="pa-panel-card">
          <div class="pa-panel-title">속성</div>
          <div class="pa-prop">
            <div class="pa-prop-row"><b>업무번호</b><span><?php echo h($selectedTaskNo); ?></span></div>
            <div class="pa-prop-row"><b>담당자</b><span><select name="assignee_employee_id" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="pa-field"><?php pa_employee_options($employees, isset($selectedTask['assignee_employee_id']) ? $selectedTask['assignee_employee_id'] : 0, false); ?></select></span></div>
            <div class="pa-prop-row"><b>요청자</b><span><?php echo h(isset($selectedTask['requester_name']) ? $selectedTask['requester_name'] : '-'); ?></span></div>
            <div class="pa-prop-row"><b>참조자</b><span><select name="reference_employee_ids[]" multiple <?php echo $canEditSelected ? '' : 'disabled'; ?> class="pa-field" style="min-height:82px;"><?php pa_employee_options($employees, isset($selectedTask['reference_employee_ids']) ? $selectedTask['reference_employee_ids'] : array(), true); ?></select></span></div>
            <div class="pa-prop-row"><b>업무유형</b><span><select name="task_type" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="pa-field"><?php foreach ($settings['task_types'] as $type): ?><option value="<?php echo h($type); ?>" <?php echo pa_selected(isset($selectedTask['task_type']) ? $selectedTask['task_type'] : '', $type); ?>><?php echo h($type); ?></option><?php endforeach; ?></select></span></div>
            <div class="pa-prop-row"><b>현장명</b><span><input name="project_name" value="<?php echo h(isset($selectedTask['project_name']) ? $selectedTask['project_name'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="pa-field"></span></div>
            <div class="pa-prop-row"><b>상태</b><span><select name="status" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="pa-field"><?php foreach ($settings['statuses'] as $status): ?><option value="<?php echo h($status); ?>" <?php echo pa_selected(isset($selectedTask['status']) ? $selectedTask['status'] : '', $status); ?>><?php echo h($status); ?></option><?php endforeach; ?></select></span></div>
            <div class="pa-prop-row"><b>우선순위</b><span><select name="priority" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="pa-field"><?php foreach ($settings['priorities'] as $priority): ?><option value="<?php echo h($priority); ?>" <?php echo pa_selected(isset($selectedTask['priority']) ? $selectedTask['priority'] : '', $priority); ?>><?php echo h($priority); ?></option><?php endforeach; ?></select></span></div>
            <div class="pa-prop-row"><b>시작일</b><span><input type="date" name="start_date" value="<?php echo h(isset($selectedTask['start_date']) ? $selectedTask['start_date'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="pa-field"></span></div>
            <div class="pa-prop-row"><b>마감일</b><span><input type="date" name="due_date" value="<?php echo h(isset($selectedTask['due_date']) ? $selectedTask['due_date'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="pa-field"></span></div>
            <div class="pa-prop-row"><b>마감시간</b><span><input type="time" name="due_time" value="<?php echo h(isset($selectedTask['due_time']) ? $selectedTask['due_time'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="pa-field"></span></div>
            <div class="pa-prop-row"><b>관련 금액</b><span><input name="related_amount" value="<?php echo h(isset($selectedTask['related_amount']) ? $selectedTask['related_amount'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="pa-field"></span></div>
            <div class="pa-prop-row"><b>계약 영향</b><span><select name="contract_impact" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="pa-field"><?php foreach (array('없음','있음','확인필요') as $impact): ?><option value="<?php echo h($impact); ?>" <?php echo pa_selected(isset($selectedTask['contract_impact']) ? $selectedTask['contract_impact'] : '없음', $impact); ?>><?php echo h($impact); ?></option><?php endforeach; ?></select></span></div>
            <div class="pa-prop-row"><b>공기 영향</b><span><select name="schedule_impact" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="pa-field"><?php foreach (array('없음','있음','확인필요') as $impact): ?><option value="<?php echo h($impact); ?>" <?php echo pa_selected(isset($selectedTask['schedule_impact']) ? $selectedTask['schedule_impact'] : '없음', $impact); ?>><?php echo h($impact); ?></option><?php endforeach; ?></select></span></div>
            <div class="pa-prop-row"><b>생성일시</b><span><?php echo h(isset($selectedTask['created_at']) ? $selectedTask['created_at'] : '-'); ?></span></div>
            <div class="pa-prop-row"><b>수정일시</b><span><?php echo h(isset($selectedTask['updated_at']) ? $selectedTask['updated_at'] : '-'); ?></span></div>
            <div class="pa-prop-row"><b>완료일시</b><span><?php echo h(isset($selectedTask['completed_at']) && trim((string)$selectedTask['completed_at']) !== '' ? $selectedTask['completed_at'] : '-'); ?></span></div>
          </div>
        </div>
      </form>

      <div class="pa-detail-grid" style="margin-top:14px;">
        <div>
          <div class="pa-panel-card">
            <div class="pa-panel-title"><?php echo h($selectedTaskNo); ?> 댓글</div>
            <form method="post" action="?r=project/collaboration_action">
              <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="comment"><input type="hidden" name="task_id" value="<?php echo (int)$selectedTaskId; ?>"><input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array('task_id' => $selectedTaskId))); ?>">
              <textarea name="comment" rows="3" class="pa-field" placeholder="@담당자 확인 부탁드립니다."></textarea>
              <div style="display:flex;justify-content:flex-end;margin-top:8px;"><button type="submit" class="pa-btn pa-btn-primary">댓글 등록</button></div>
            </form>
            <?php if (count($comments) === 0): ?><div class="pa-muted" style="margin-top:10px;">댓글이 없습니다.</div><?php endif; ?>
            <?php foreach ($comments as $comment): ?><div class="pa-comment"><b><?php echo h(isset($comment['created_by_name']) ? $comment['created_by_name'] : '-'); ?></b> <span class="pa-muted"><?php echo h(isset($comment['created_at']) ? $comment['created_at'] : ''); ?></span><div style="white-space:pre-wrap;margin-top:5px;"><?php echo h(isset($comment['content']) ? $comment['content'] : ''); ?></div></div><?php endforeach; ?>
          </div>
          <div class="pa-panel-card" style="margin-top:12px;">
            <div class="pa-panel-title"><?php echo h($selectedTaskNo); ?> 첨부파일</div>
            <?php foreach ($files as $file): ?><a class="pa-file" style="display:block;" href="?r=project/collaboration_file&id=<?php echo (int)$file['id']; ?>"><b><?php echo h(isset($file['original_name']) ? $file['original_name'] : 'file'); ?></b><div class="pa-muted"><?php echo h(isset($file['uploaded_by_name']) ? $file['uploaded_by_name'] : '-'); ?> · <?php echo h(isset($file['uploaded_at']) ? $file['uploaded_at'] : ''); ?> · <?php echo h(pa_file_size(isset($file['file_size']) ? $file['file_size'] : 0)); ?></div></a><?php endforeach; ?>
            <?php if (count($files) === 0): ?><div class="pa-muted">첨부파일이 없습니다.</div><?php endif; ?>
            <?php if ($canEditSelected): ?><form method="post" action="?r=project/collaboration_action" enctype="multipart/form-data" style="margin-top:10px;"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="task_id" value="<?php echo (int)$selectedTaskId; ?>"><input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array('task_id' => $selectedTaskId))); ?>"><input type="file" name="attachments[]" multiple class="pa-field"><button type="submit" class="pa-btn pa-btn-dark" style="margin-top:8px;">첨부 등록</button></form><?php endif; ?>
          </div>
        </div>
        <div class="pa-panel-card">
          <div class="pa-panel-title"><?php echo h($selectedTaskNo); ?> 변경이력</div>
          <?php if (count($history) === 0): ?><div class="pa-muted">변경이력이 없습니다.</div><?php endif; ?>
          <?php foreach ($history as $log): ?><div class="pa-history"><b><?php echo h(isset($log['action']) ? $log['action'] : '-'); ?></b><div class="pa-muted"><?php echo h(isset($log['actor_name']) ? $log['actor_name'] : '-'); ?> · <?php echo h(isset($log['created_at']) ? $log['created_at'] : ''); ?></div><div style="font-size:12px;margin-top:5px;word-break:break-all;"><?php echo h(isset($log['old_value']) ? $log['old_value'] : ''); ?> → <?php echo h(isset($log['new_value']) ? $log['new_value'] : ''); ?></div></div><?php endforeach; ?>
        </div>
      </div>
    </div>
  </aside>
<?php endif; ?>

<?php if ($canCreateCollab): ?>
  <div class="pa-modal" id="paCreateModal">
    <div class="pa-modal-bg" data-pa-modal-close="create"></div>
    <div class="pa-modal-box">
      <div class="pa-modal-head"><div><div class="pa-title" style="font-size:21px;">업무 만들기</div><div class="pa-desc">새 공무 업무카드를 생성합니다.</div></div><button type="button" class="pa-btn" data-pa-modal-close="create">닫기</button></div>
      <form method="post" action="?r=project/collaboration_action" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="status" value="할 일">
        <input type="hidden" name="project_id" value="<?php echo (int)$spaceProjectId; ?>">
        <input type="hidden" name="project_name" value="<?php echo h(is_array($selectedSpace) && isset($selectedSpace['name']) ? $selectedSpace['name'] : ''); ?>">
        <input type="hidden" name="return_url" value="<?php echo h(pa_collab_url(array('section' => 'board', 'task_id' => null))); ?>">
        <div class="pa-modal-body">
          <div class="pa-form-grid">
            <div><label class="pa-muted">업무유형</label><select name="task_type" class="pa-field"><?php foreach ($settings['task_types'] as $type): ?><option value="<?php echo h($type); ?>"><?php echo h($type); ?></option><?php endforeach; ?></select></div>
            <div><label class="pa-muted">프로젝트 Space</label><input class="pa-field" value="<?php echo h(is_array($selectedSpace) && isset($selectedSpace['name']) ? $selectedSpace['name'] : ''); ?>" readonly></div>
            <div class="full"><label class="pa-muted">제목 *</label><input name="title" required class="pa-field" placeholder="예: 변경계약 2차 내역 검토"></div>
            <div class="full"><label class="pa-muted">상세내용</label><textarea name="content" rows="5" class="pa-field" placeholder="필요한 검토 내용과 요청사항을 적어주세요."></textarea></div>
            <div><label class="pa-muted">요청자</label><select name="requester_employee_id" class="pa-field"><?php pa_employee_options($employees, isset($currentEmployee['id']) ? $currentEmployee['id'] : 0, false); ?></select></div>
            <div><label class="pa-muted">담당자 *</label><select name="assignee_employee_id" required class="pa-field"><option value="">선택하세요</option><?php pa_employee_options($employees, $defaultAssigneeId, false); ?></select></div>
            <div class="full"><label class="pa-muted">참조자</label><select name="reference_employee_ids[]" multiple class="pa-field" style="min-height:100px;"><?php pa_employee_options($employees, array(), true); ?></select></div>
            <div><label class="pa-muted">우선순위</label><select name="priority" class="pa-field"><?php foreach ($settings['priorities'] as $priority): ?><option value="<?php echo h($priority); ?>" <?php echo $priority === '보통' ? 'selected' : ''; ?>><?php echo h($priority); ?></option><?php endforeach; ?></select></div>
            <div><label class="pa-muted">시작일</label><input type="date" name="start_date" class="pa-field"></div>
            <div><label class="pa-muted">마감일</label><input type="date" name="due_date" class="pa-field"></div>
            <div><label class="pa-muted">마감시간</label><input type="time" name="due_time" class="pa-field"></div>
            <div><label class="pa-muted">관련 금액</label><input name="related_amount" class="pa-field" placeholder="숫자만 입력"></div>
            <div><label class="pa-muted">계약 영향 여부</label><select name="contract_impact" class="pa-field"><option value="없음">없음</option><option value="있음">있음</option><option value="확인필요">확인필요</option></select></div>
            <div><label class="pa-muted">공기 영향 여부</label><select name="schedule_impact" class="pa-field"><option value="없음">없음</option><option value="있음">있음</option><option value="확인필요">확인필요</option></select></div>
            <div class="full"><label class="pa-muted">관련 문서 링크</label><input name="document_link" class="pa-field" placeholder="문서 URL 또는 공유 링크"></div>
            <div class="full"><label class="pa-muted">첨부파일</label><input type="file" name="attachments[]" multiple class="pa-field"></div>
          </div>
        </div>
        <div class="pa-modal-foot"><button type="button" class="pa-btn" data-pa-modal-close="create">취소</button><button type="submit" class="pa-btn pa-btn-primary">업무카드 생성</button></div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($canCreateCollab): ?>
  <div class="pa-modal" id="paProjectModal">
    <div class="pa-modal-bg" data-pa-modal-close="project"></div>
    <div class="pa-modal-box">
      <div class="pa-modal-head"><div><div class="pa-title" style="font-size:21px;">프로젝트 만들기</div><div class="pa-desc">계약 전/입찰 단계 업무를 "(가제)" 프로젝트 Space로 시작합니다.</div></div><button type="button" class="pa-btn" data-pa-modal-close="project">닫기</button></div>
      <form method="post" action="?r=project/collaboration_action">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="project_create">
        <input type="hidden" name="return_url" value="?r=public_affairs_collab&section=home">
        <div class="pa-modal-body">
          <div class="pa-form-grid">
            <div class="full"><label class="pa-muted">프로젝트명 *</label><input name="project_name" required class="pa-field" placeholder="예: 삼성전자 FAB 배관공사 검토"></div>
            <div class="full"><label class="pa-muted">설명</label><textarea name="description" rows="4" class="pa-field" placeholder="입찰/계약 검토 배경, 협업 목적을 적어주세요."></textarea></div>
            <div><label class="pa-muted">발주처/거래처</label><input name="client" class="pa-field"></div>
            <div><label class="pa-muted">담당자</label><select name="manager_employee_id" class="pa-field"><?php pa_employee_options($employees, isset($currentEmployee['id']) ? $currentEmployee['id'] : 0, false); ?></select></div>
            <div><label class="pa-muted">시작 예정일</label><input type="date" name="start_date" class="pa-field"></div>
            <div><label class="pa-muted">종료 예정일</label><input type="date" name="end_date" class="pa-field"></div>
            <div><label class="pa-muted">단계</label><select name="phase" class="pa-field"><option value="입찰검토">입찰검토</option><option value="견적중">견적중</option><option value="계약검토">계약검토</option><option value="보류">보류</option><option value="정식전환대기">정식전환대기</option></select></div>
            <label class="pa-check"><input type="checkbox" name="favorite" value="1"> 즐겨찾기에 추가</label>
          </div>
        </div>
        <div class="pa-modal-foot"><button type="button" class="pa-btn" data-pa-modal-close="project">취소</button><button type="submit" class="pa-btn pa-btn-primary">가제 프로젝트 생성</button></div>
      </form>
    </div>
  </div>
<?php endif; ?>

    </div>
  </section>
</div>

<?php
  // 공무 협업툴 접속/상세패널 공통 설정: 외부 JS가 로드되면 AJAX/상세 갱신에 사용한다.
  $paCollabEmployeeOptions = array();
  foreach ($employees as $employee) {
      $paCollabEmployeeOptions[] = array(
          'id' => isset($employee['id']) ? (int)$employee['id'] : 0,
          'name' => isset($employee['name']) ? (string)$employee['name'] : '',
          'department' => isset($employee['department']) ? (string)$employee['department'] : '',
          'position' => isset($employee['position']) ? (string)$employee['position'] : '',
      );
  }
  $paCollabJsConfig = array(
      'csrf' => csrf_token(),
      'actionUrl' => '?r=project/collaboration_action',
      'fileUrl' => '?r=project/collaboration_file&id=',
      'statuses' => $settings['statuses'],
      'priorities' => $settings['priorities'],
      'taskTypes' => $settings['task_types'],
      'employees' => $paCollabEmployeeOptions,
      'impactOptions' => array('없음', '있음', '확인필요'),
  );
  $GLOBALS['pa_collab_stage'] = 'collaboration_js_config';
  $paCollabJsonFlags = function_exists('cpms_public_affairs_collab_json_flags') ? cpms_public_affairs_collab_json_flags(false) : 0;
  $paCollabJsConfigJson = json_encode($paCollabJsConfig, $paCollabJsonFlags);
  if (!is_string($paCollabJsConfigJson) || $paCollabJsConfigJson === '') $paCollabJsConfigJson = '{}';
?>
<script>
window.paCollabConfig = <?php echo $paCollabJsConfigJson; ?>;
(function(){
  // 공무 협업툴 접속 fallback: 외부 JS가 로드되지 않아도 탭/버튼으로 모달을 열고 닫는다.
  var hashValue = '#public-affairs-collaboration';
  function hasClass(el, name){ return el && (' ' + el.className + ' ').indexOf(' ' + name + ' ') > -1; }
  function addClass(el, name){ if (el && !hasClass(el, name)) el.className = el.className + ' ' + name; }
  function removeClass(el, name){ if (el) el.className = (' ' + el.className + ' ').replace(' ' + name + ' ', ' ').replace(/^\s+|\s+$/g, ''); }
  function closest(el, attr){
    while (el && el !== document) {
      if (el.getAttribute && el.getAttribute(attr) !== null) return el;
      el = el.parentNode;
    }
    return null;
  }
  function modal(){ return document.getElementById('paCollabFullscreenModal'); }
  function openModal(updateHash){
    var m = modal(); if (!m) return;
    if (m.parentNode !== document.body) document.body.appendChild(m);
    addClass(m, 'is-open');
    m.setAttribute('aria-hidden', 'false');
    m.style.display = 'block';
    addClass(document.body, 'pa-collab-open');
    if (updateHash && window.location.hash !== hashValue) {
      if (window.history && window.history.pushState) window.history.pushState(null, '', hashValue);
      else window.location.hash = hashValue;
    }
  }
  function closeModal(updateHash){
    var m = modal(); if (!m) return;
    removeClass(m, 'is-open');
    m.setAttribute('aria-hidden', 'true');
    m.style.display = '';
    removeClass(document.body, 'pa-collab-open');
    if (updateHash && window.location.hash === hashValue && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
  }
  function bind(target, eventName, handler) {
    if (target && target.addEventListener) target.addEventListener(eventName, handler, false);
    else if (target && target.attachEvent) target.attachEvent('on' + eventName, handler);
  }
  bind(document, 'click', function(ev){
    ev = ev || window.event;
    var target = ev.target || ev.srcElement;
    if (closest(target, 'data-pa-collab-open')) {
      openModal(true);
      if (ev.preventDefault) ev.preventDefault();
      return false;
    }
    if (closest(target, 'data-pa-collab-close')) {
      closeModal(true);
      if (ev.preventDefault) ev.preventDefault();
      return false;
    }
  });
  bind(document, 'keydown', function(ev){
    ev = ev || window.event;
    var key = ev.key || ev.keyCode;
    if ((key === 'Escape' || key === 27) && modal() && hasClass(modal(), 'is-open')) closeModal(true);
  });
  if ((modal() && modal().getAttribute('data-pa-auto-open') === '1') || window.location.hash === hashValue) openModal(false);
})();
</script>
<script defer src="<?php echo h(asset_url('assets/js/public_affairs_collaboration.js') . '?v=' . (string)@filemtime(dirname(dirname(dirname(__DIR__))) . '/public/assets/js/public_affairs_collaboration.js')); ?>"></script>
