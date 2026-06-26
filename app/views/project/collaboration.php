<?php
/**
 * 공무 협업툴 독립 보드 화면
 * - 공무섹션 내부에서만 동작하는 업무카드/칸반/상세 패널 중심 협업툴 화면이다.
 * - 기존 나의할일, 공사 이슈, 프로젝트 이슈 화면과 연결하지 않는다.
 * - PHP 5.6 호환 문법만 사용한다.
 */

use App\Core\Db;

require_once __DIR__ . '/../../services/PublicAffairsCollaborationService.php';

$pdo = Db::pdo();
$settings = cpms_public_affairs_collab_settings();
$employees = cpms_public_affairs_collab_fetch_employees($pdo);
$currentEmployee = cpms_public_affairs_collab_current_employee($pdo);
$canManageCollab = cpms_public_affairs_collab_is_admin_user();
$canCreateCollab = cpms_public_affairs_collab_can_create_task();
$canAccessCollab = cpms_public_affairs_collab_user_can_access_module($currentEmployee);
$flash = isset($flash) ? $flash : flash_get();

if (!function_exists('pa_collab_url')) {
function pa_collab_url($overrides) {
    $params = $_GET;
    $params['r'] = '공무';
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
    ?>
    <div class="rounded-2xl border border-red-200 bg-red-50 text-red-700 p-5 font-bold">
        공무 협업툴 접근 권한이 없습니다. 로그인 후 이용해주세요.
    </div>
    <?php
    return;
}

$section = isset($_GET['section']) ? trim((string)$_GET['section']) : 'board';
$viewMode = isset($_GET['view_mode']) ? trim((string)$_GET['view_mode']) : 'board';
$quickFilter = isset($_GET['quick']) ? trim((string)$_GET['quick']) : 'hide_done';
if ($section === '') $section = 'board';
if (!in_array($section, array('board', 'pending', 'mine', 'today', 'delayed', 'done', 'all', 'settings'), true)) $section = 'board';
if (!in_array($viewMode, array('board', 'list', 'backlog'), true)) $viewMode = 'board';
if ($section === 'pending') { $viewMode = 'backlog'; $quickFilter = 'pending'; }
if ($section === 'mine') $quickFilter = 'mine';
if ($section === 'today') $quickFilter = 'today';
if ($section === 'delayed') $quickFilter = 'delayed';
if ($section === 'done') $quickFilter = 'done';
if ($section === 'all') $quickFilter = 'all';
if ($section === 'board' && !isset($_GET['quick'])) $quickFilter = 'hide_done';

$filters = array(
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

$allTasks = cpms_public_affairs_collab_list_tasks();
$visibleTasks = cpms_public_affairs_collab_visible_tasks($allTasks, $currentEmployee);
$filteredTasks = cpms_public_affairs_collab_apply_filters($visibleTasks, $filters);
$filteredTasks = cpms_public_affairs_collab_apply_quick_filter($filteredTasks, $quickFilter, $currentEmployee);
$summary = cpms_public_affairs_collab_summary($visibleTasks, $currentEmployee);
$taskCounts = cpms_public_affairs_collab_task_counts();
$selectedTaskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
$selectedTask = $selectedTaskId > 0 ? cpms_public_affairs_collab_find_task($selectedTaskId) : null;
if (is_array($selectedTask) && !cpms_public_affairs_collab_user_can_view_task($selectedTask, $currentEmployee)) $selectedTask = null;
$groups = cpms_public_affairs_collab_group_by_status($filteredTasks, $settings['statuses']);
$defaultAssigneeId = isset($settings['default_assignee_employee_id']) ? (int)$settings['default_assignee_employee_id'] : 0;

$quickLinks = array(
    'all' => '전체',
    'mine' => '내 담당',
    'today' => '오늘 마감',
    'delayed' => '지연',
    'urgent' => '긴급',
    'approval' => '결재대기',
    'contract' => '계약 영향',
    'schedule' => '공기 영향',
    'hide_done' => '완료 숨기기',
);

$sampleCards = array(
    array('task_no' => 'PA-0001', 'task_type' => '변경계약', 'title' => '변경계약 2차 내역 검토', 'project_name' => '샘플 현장 A', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '긴급', 'status' => '진행중', 'due_date' => date('Y-m-d'), 'due_time' => '17:00', 'contract_impact' => '있음', 'schedule_impact' => '확인필요'),
    array('task_no' => 'PA-0002', 'task_type' => '자료 제출', 'title' => '발주처 제출자료 취합', 'project_name' => '샘플 현장 B', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '높음', 'status' => '요청', 'due_date' => date('Y-m-d', strtotime('+1 day')), 'due_time' => '', 'contract_impact' => '없음', 'schedule_impact' => '없음'),
    array('task_no' => 'PA-0003', 'task_type' => '리스크 검토', 'title' => '공기연장 근거자료 정리', 'project_name' => '샘플 현장 C', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '보통', 'status' => '검토중', 'due_date' => date('Y-m-d', strtotime('+3 day')), 'due_time' => '', 'contract_impact' => '확인필요', 'schedule_impact' => '있음'),
    array('task_no' => 'PA-0004', 'task_type' => '기성/청구', 'title' => '기성청구 첨부자료 검토', 'project_name' => '샘플 현장 D', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '높음', 'status' => '자료대기', 'due_date' => date('Y-m-d', strtotime('+2 day')), 'due_time' => '10:00', 'contract_impact' => '없음', 'schedule_impact' => '없음'),
    array('task_no' => 'PA-0005', 'task_type' => '내역서 검토', 'title' => '협력업체 견적 비교', 'project_name' => '샘플 현장 E', 'assignee_name' => '담당자', 'requester_name' => '요청자', 'priority' => '보통', 'status' => '결재대기', 'due_date' => date('Y-m-d', strtotime('+5 day')), 'due_time' => '', 'contract_impact' => '확인필요', 'schedule_impact' => '없음'),
);
?>

<style>
.pa-board-app{--pa-bg:#f5f7fb;--pa-panel:#fff;--pa-ink:#172033;--pa-muted:#64748b;--pa-line:#dbe3ef;--pa-accent:#0f766e;--pa-accent-dark:#115e59;--pa-warn:#b45309;--pa-danger:#be123c;--pa-soft:#eef6f4;min-height:calc(100vh - 170px);margin:-6px -2px 0;border:1px solid var(--pa-line);border-radius:18px;background:var(--pa-bg);overflow:hidden;color:var(--pa-ink)}
.pa-board-layout{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:calc(100vh - 172px)}
.pa-sidebar{background:#111827;color:#d1d5db;padding:18px 14px;display:flex;flex-direction:column;gap:14px}
.pa-sidebar-title{font-weight:900;color:#fff;font-size:18px;letter-spacing:0}
.pa-sidebar-sub{font-size:12px;color:#9ca3af;margin-top:3px}
.pa-side-link{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-radius:10px;color:#d1d5db;font-weight:800;font-size:14px}
.pa-side-link:hover{background:#1f2937;color:#fff}
.pa-side-link.is-active{background:#0f766e;color:#fff}
.pa-side-count{font-size:11px;border-radius:999px;background:rgba(255,255,255,.12);padding:2px 7px}
.pa-main{min-width:0;padding:18px;display:flex;flex-direction:column;gap:14px}
.pa-topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
.pa-title{font-size:25px;font-weight:950;color:#111827;line-height:1.15}
.pa-desc{color:var(--pa-muted);font-weight:700;margin-top:4px}
.pa-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.pa-search{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--pa-line);border-radius:12px;padding:8px 10px;min-width:320px}
.pa-search input{border:0;outline:0;min-width:0;flex:1;font-weight:700;color:#111827}
.pa-btn{border:1px solid var(--pa-line);background:#fff;color:#273449;font-weight:900;border-radius:12px;padding:10px 13px;display:inline-flex;align-items:center;gap:7px}
.pa-btn:hover{border-color:#b6c3d4;background:#f8fafc}
.pa-btn-primary{background:var(--pa-accent);border-color:var(--pa-accent);color:#fff}
.pa-btn-primary:hover{background:var(--pa-accent-dark);border-color:var(--pa-accent-dark)}
.pa-btn-dark{background:#111827;border-color:#111827;color:#fff}
.pa-view-tabs{display:flex;gap:6px;background:#e9eef6;padding:4px;border-radius:12px}
.pa-view-tabs a{padding:8px 11px;border-radius:9px;font-weight:900;color:#475569}
.pa-view-tabs a.is-active{background:#fff;color:#111827;box-shadow:0 1px 2px rgba(15,23,42,.08)}
.pa-filterbar{background:#fff;border:1px solid var(--pa-line);border-radius:16px;padding:12px;display:flex;flex-direction:column;gap:10px}
.pa-quick{display:flex;gap:7px;overflow-x:auto;padding-bottom:2px}
.pa-chip{white-space:nowrap;border:1px solid #d7e1ef;background:#f8fafc;border-radius:999px;padding:8px 11px;font-size:13px;font-weight:900;color:#3f4c61}
.pa-chip.is-active{background:#0f766e;color:#fff;border-color:#0f766e}
.pa-filter-grid{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:8px}
.pa-field{border:1px solid var(--pa-line);border-radius:11px;background:#fff;padding:9px 10px;font-weight:750;color:#172033;min-width:0;width:100%}
.pa-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.pa-summary-card{background:#fff;border:1px solid var(--pa-line);border-radius:14px;padding:12px}
.pa-summary-card b{font-size:22px;display:block;color:#111827}
.pa-summary-card span{font-size:12px;color:#64748b;font-weight:900}
.pa-board-wrap{position:relative;min-height:500px}
.pa-kanban{display:flex;gap:12px;overflow-x:auto;padding-bottom:14px;min-height:560px}
.pa-column{flex:0 0 292px;background:#e9eef6;border:1px solid #d7e1ef;border-radius:16px;display:flex;flex-direction:column;max-height:calc(100vh - 360px);min-height:540px}
.pa-column-head{padding:12px 12px 10px;display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1px solid #d7e1ef}
.pa-column-title{font-weight:950;color:#1f2937}
.pa-column-meta{display:flex;gap:5px;align-items:center}
.pa-count,.pa-wip{border-radius:999px;background:#fff;color:#475569;font-size:12px;font-weight:900;padding:3px 8px}
.pa-column-body{padding:10px;display:flex;flex-direction:column;gap:9px;overflow-y:auto}
.pa-card{background:#fff;border:1px solid #d8e2ef;border-radius:13px;padding:11px;box-shadow:0 1px 0 rgba(15,23,42,.04);cursor:pointer}
.pa-card:hover{border-color:#93c5bd;box-shadow:0 8px 18px rgba(15,23,42,.08)}
.pa-card.is-urgent{border-left:5px solid #e11d48}
.pa-card.is-delayed{background:#fff1f2;border-color:#fecdd3}
.pa-card.is-done{background:#f8fafc;color:#64748b}
.pa-card.is-sample{opacity:.75;border-style:dashed}
.pa-card-top,.pa-card-foot{display:flex;align-items:center;justify-content:space-between;gap:8px}
.pa-no{font-size:12px;font-weight:950;color:#0f766e;background:#e6f5f2;border-radius:999px;padding:4px 8px}
.pa-type{font-size:11px;font-weight:900;color:#475569;background:#eef2f7;border-radius:999px;padding:4px 7px}
.pa-card-title{display:block;margin-top:8px;font-size:15px;line-height:1.35;font-weight:950;color:#111827}
.pa-card-meta{margin-top:8px;display:grid;gap:3px;font-size:12px;color:#526173;font-weight:800}
.pa-badges{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}
.pa-badge{border-radius:999px;padding:3px 7px;font-size:11px;font-weight:950;border:1px solid transparent}
.pa-priority-urgent{background:#ffe4e6;color:#be123c;border-color:#fecdd3}.pa-priority-high{background:#ffedd5;color:#c2410c;border-color:#fed7aa}.pa-priority-normal{background:#e0f2fe;color:#0369a1;border-color:#bae6fd}.pa-priority-low{background:#f1f5f9;color:#475569;border-color:#e2e8f0}
.pa-status-new{background:#f1f5f9;color:#475569}.pa-status-progress{background:#dcfce7;color:#166534}.pa-status-wait{background:#fef3c7;color:#92400e}.pa-status-approval{background:#e0e7ff;color:#3730a3}.pa-status-hold{background:#e5e7eb;color:#374151}.pa-status-reject{background:#ffe4e6;color:#be123c}.pa-status-done{background:#d1fae5;color:#047857}
.pa-impact{background:#fef2f2;color:#991b1b;border-color:#fecaca}.pa-today{background:#fef9c3;color:#854d0e;border-color:#fde68a}.pa-delayed{background:#be123c;color:#fff;border-color:#be123c}
.pa-card-select{margin-top:9px;display:flex;gap:6px}.pa-card-select select{min-width:0;flex:1;border:1px solid #d7e1ef;border-radius:9px;padding:7px;font-size:12px;background:#fff}.pa-card-select button{border-radius:9px;padding:7px 9px;font-size:12px;font-weight:900;background:#111827;color:#fff}
.pa-table-wrap{background:#fff;border:1px solid var(--pa-line);border-radius:16px;overflow:auto}.pa-table{width:100%;border-collapse:collapse;font-size:13px;min-width:1280px}.pa-table th{background:#f8fafc;color:#64748b;text-align:left;font-weight:950;padding:11px;border-bottom:1px solid var(--pa-line);white-space:nowrap}.pa-table td{padding:11px;border-bottom:1px solid #eef2f7;vertical-align:middle}
.pa-backlog{display:grid;gap:10px}.pa-backlog-row{background:#fff;border:1px solid var(--pa-line);border-radius:14px;padding:12px;display:grid;grid-template-columns:minmax(260px,1fr) 160px 190px 150px auto;gap:10px;align-items:center}
.pa-empty{background:#fff;border:1px dashed #cbd5e1;border-radius:16px;padding:28px;text-align:center;color:#64748b;font-weight:850}
.pa-detail-panel{position:fixed;top:0;right:0;bottom:0;width:min(960px,96vw);background:#fff;z-index:60;border-left:1px solid #d7e1ef;box-shadow:-18px 0 40px rgba(15,23,42,.18);display:flex;flex-direction:column}
.pa-detail-head{padding:16px 18px;border-bottom:1px solid #e5edf6;display:flex;justify-content:space-between;gap:12px;align-items:flex-start;background:#fbfdff}
.pa-detail-body{padding:16px 18px;overflow:auto}.pa-detail-grid{display:grid;grid-template-columns:minmax(0,1.35fr) 310px;gap:14px}.pa-panel-card{border:1px solid #e5edf6;border-radius:14px;padding:13px;background:#fff}.pa-panel-title{font-weight:950;color:#111827;margin-bottom:9px}.pa-prop{display:grid;gap:8px}.pa-prop-row{display:grid;grid-template-columns:94px minmax(0,1fr);gap:8px;font-size:13px}.pa-prop-row b{color:#64748b}.pa-comment,.pa-history,.pa-file{border:1px solid #edf2f7;background:#f8fafc;border-radius:12px;padding:10px;margin-top:8px}.pa-muted{color:#64748b}.pa-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.pa-form-grid .full{grid-column:1/-1}.pa-modal{position:fixed;inset:0;z-index:70;display:none}.pa-modal.is-open{display:block}.pa-modal-bg{position:absolute;inset:0;background:rgba(15,23,42,.55)}.pa-modal-box{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:min(900px,94vw);max-height:92vh;background:#fff;border-radius:18px;box-shadow:0 24px 80px rgba(15,23,42,.32);display:flex;flex-direction:column;overflow:hidden}.pa-modal-head{padding:16px 18px;border-bottom:1px solid #e5edf6;display:flex;justify-content:space-between}.pa-modal-body{padding:18px;overflow:auto}.pa-modal-foot{padding:14px 18px;border-top:1px solid #e5edf6;display:flex;justify-content:flex-end;gap:8px}
@media (max-width:900px){.pa-board-layout{grid-template-columns:1fr}.pa-sidebar{display:block}.pa-side-nav{display:flex;gap:6px;overflow-x:auto;margin-top:10px}.pa-side-link{white-space:nowrap}.pa-filter-grid{grid-template-columns:1fr 1fr}.pa-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.pa-kanban{display:block;overflow:visible}.pa-column{max-height:none;min-height:0;width:auto;margin-bottom:10px;flex:auto}.pa-column-body{max-height:none}.pa-detail-panel{width:100vw}.pa-detail-grid{grid-template-columns:1fr}.pa-backlog-row{grid-template-columns:1fr}.pa-search{min-width:0;width:100%}}
</style>

<div class="pa-board-app">
  <div class="pa-board-layout">
    <aside class="pa-sidebar">
      <div>
        <div class="pa-sidebar-title">공무 협업툴</div>
        <div class="pa-sidebar-sub">업무카드 전용 보드</div>
      </div>
      <nav class="pa-side-nav">
        <a class="pa-side-link <?php echo $section === 'board' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'board', 'view_mode' => 'board', 'quick' => 'hide_done', 'task_id' => null))); ?>">보드 <span class="pa-side-count"><?php echo (int)$summary['all']; ?></span></a>
        <a class="pa-side-link <?php echo $section === 'pending' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'pending', 'view_mode' => 'backlog', 'quick' => 'pending', 'task_id' => null))); ?>">대기 업무</a>
        <a class="pa-side-link <?php echo $section === 'mine' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'mine', 'view_mode' => 'board', 'quick' => 'mine', 'task_id' => null))); ?>">내 담당 <span class="pa-side-count"><?php echo (int)$summary['mine']; ?></span></a>
        <a class="pa-side-link <?php echo $section === 'today' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'today', 'view_mode' => 'board', 'quick' => 'today', 'task_id' => null))); ?>">오늘 마감 <span class="pa-side-count"><?php echo (int)$summary['today']; ?></span></a>
        <a class="pa-side-link <?php echo $section === 'delayed' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'delayed', 'view_mode' => 'board', 'quick' => 'delayed', 'task_id' => null))); ?>">지연 <span class="pa-side-count"><?php echo (int)$summary['delayed']; ?></span></a>
        <a class="pa-side-link <?php echo $section === 'done' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'done', 'view_mode' => 'board', 'quick' => 'done', 'task_id' => null))); ?>">완료 <span class="pa-side-count"><?php echo (int)$summary['done']; ?></span></a>
        <a class="pa-side-link <?php echo $section === 'all' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'all', 'view_mode' => 'board', 'quick' => 'all', 'task_id' => null))); ?>">모든 업무</a>
        <a class="pa-side-link <?php echo $section === 'settings' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('section' => 'settings', 'view' => 'settings', 'task_id' => null))); ?>">설정</a>
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

      <div class="pa-topbar">
        <div>
          <div class="pa-title">공무 협업툴</div>
          <div class="pa-desc">공무 업무카드 기반 협업보드</div>
        </div>
        <div class="pa-actions">
          <form method="get" action="" class="pa-search">
            <input type="hidden" name="r" value="공무">
            <input type="hidden" name="tab" value="collaboration">
            <input type="hidden" name="section" value="<?php echo h($section); ?>">
            <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
            <input type="hidden" name="quick" value="<?php echo h($quickFilter); ?>">
            <span class="pa-muted">검색</span>
            <input name="keyword" value="<?php echo h($filters['keyword']); ?>" placeholder="업무번호, 제목, 내용, 담당자">
          </form>
          <div class="pa-view-tabs">
            <a class="<?php echo $viewMode === 'board' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('view_mode' => 'board', 'section' => 'board', 'task_id' => null))); ?>">보드</a>
            <a class="<?php echo $viewMode === 'list' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('view_mode' => 'list', 'section' => 'all', 'quick' => 'all', 'task_id' => null))); ?>">목록</a>
            <a class="<?php echo $viewMode === 'backlog' ? 'is-active' : ''; ?>" href="<?php echo h(pa_collab_url(array('view_mode' => 'backlog', 'section' => 'pending', 'quick' => 'pending', 'task_id' => null))); ?>">대기 업무</a>
          </div>
          <?php if ($canCreateCollab): ?>
            <button type="button" class="pa-btn pa-btn-primary" data-pa-modal-open="create">업무 만들기</button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($section === 'settings'): ?>
        <section class="pa-panel-card">
          <div class="pa-panel-title">공무 협업툴 설정</div>
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
            <input type="hidden" name="r" value="공무">
            <input type="hidden" name="tab" value="collaboration">
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
                <?php $taskId = isset($task['id']) ? (int)$task['id'] : 0; ?>
                <tr>
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
                  <td><a class="pa-btn" href="<?php echo h(pa_collab_url(array('task_id' => $taskId))); ?>">열기</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </section>
        <?php elseif ($viewMode === 'backlog'): ?>
          <section class="pa-backlog">
            <?php if (count($filteredTasks) === 0): ?><div class="pa-empty">대기 업무가 없습니다.</div><?php endif; ?>
            <?php foreach ($filteredTasks as $task): ?>
              <?php $taskId = isset($task['id']) ? (int)$task['id'] : 0; $canEdit = cpms_public_affairs_collab_user_can_edit_task($task, $currentEmployee); ?>
              <div class="pa-backlog-row">
                <div><div><span class="pa-no"><?php echo h(cpms_public_affairs_collab_task_no($task)); ?></span> <span class="pa-type"><?php echo h(isset($task['task_type']) ? $task['task_type'] : '-'); ?></span></div><a class="pa-card-title" href="<?php echo h(pa_collab_url(array('task_id' => $taskId))); ?>"><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></a><div class="pa-muted"><?php echo h(isset($task['project_name']) ? $task['project_name'] : '-'); ?></div></div>
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
                      ?>
                      <div class="pa-card <?php echo $isUrgent ? 'is-urgent ' : ''; ?><?php echo $isDelayed ? 'is-delayed ' : ''; ?><?php echo $isDone ? 'is-done ' : ''; ?>" <?php echo $canEdit ? 'draggable="true"' : ''; ?> data-pa-task-id="<?php echo (int)$taskId; ?>">
                        <div class="pa-card-top"><span class="pa-no"><?php echo h($taskNo); ?></span><span class="pa-type"><?php echo h(isset($task['task_type']) ? $task['task_type'] : '-'); ?></span></div>
                        <a class="pa-card-title" href="<?php echo h(pa_collab_url(array('task_id' => $taskId))); ?>"><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></a>
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
                          <span class="pa-badge">댓글 <?php echo (int)$commentCount; ?></span><span class="pa-badge">첨부 <?php echo (int)$fileCount; ?></span>
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
              <button type="submit" name="state_action" value="reject" class="pa-btn">반려 처리</button>
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
        <input type="hidden" name="status" value="요청">
        <input type="hidden" name="return_url" value="?r=공무&tab=collaboration">
        <div class="pa-modal-body">
          <div class="pa-form-grid">
            <div><label class="pa-muted">업무유형</label><select name="task_type" class="pa-field"><?php foreach ($settings['task_types'] as $type): ?><option value="<?php echo h($type); ?>"><?php echo h($type); ?></option><?php endforeach; ?></select></div>
            <div><label class="pa-muted">현장명/프로젝트명 *</label><input name="project_name" required class="pa-field" placeholder="예: 평택 P4 공무"></div>
            <div class="full"><label class="pa-muted">제목 *</label><input name="title" required class="pa-field" placeholder="예: 변경계약 2차 내역 검토"></div>
            <div class="full"><label class="pa-muted">상세내용</label><textarea name="content" rows="5" class="pa-field" placeholder="필요한 검토 내용과 요청사항을 적어주세요."></textarea></div>
            <div><label class="pa-muted">요청자</label><select name="requester_employee_id" class="pa-field"><?php pa_employee_options($employees, isset($currentEmployee['id']) ? $currentEmployee['id'] : 0, false); ?></select></div>
            <div><label class="pa-muted">담당자 *</label><select name="assignee_employee_id" required class="pa-field"><option value="">선택하세요</option><?php pa_employee_options($employees, $defaultAssigneeId, false); ?></select></div>
            <div class="full"><label class="pa-muted">참조자</label><select name="reference_employee_ids[]" multiple class="pa-field" style="min-height:100px;"><?php pa_employee_options($employees, array(), true); ?></select></div>
            <div><label class="pa-muted">우선순위</label><select name="priority" class="pa-field"><?php foreach ($settings['priorities'] as $priority): ?><option value="<?php echo h($priority); ?>" <?php echo $priority === '보통' ? 'selected' : ''; ?>><?php echo h($priority); ?></option><?php endforeach; ?></select></div>
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

<script>
(function(){
  var modal = document.getElementById('paCreateModal');
  var openers = document.querySelectorAll('[data-pa-modal-open="create"]');
  for (var i = 0; i < openers.length; i++) {
    openers[i].onclick = function(){ if (modal) modal.className = modal.className + ' is-open'; };
  }
  var closers = document.querySelectorAll('[data-pa-modal-close="create"]');
  for (var j = 0; j < closers.length; j++) {
    closers[j].onclick = function(){ if (modal) modal.className = modal.className.replace(' is-open', ''); };
  }
  var draggedTaskId = '';
  var cards = document.querySelectorAll('[data-pa-task-id]');
  for (var c = 0; c < cards.length; c++) {
    cards[c].addEventListener('dragstart', function(ev){
      draggedTaskId = this.getAttribute('data-pa-task-id');
      if (ev.dataTransfer) ev.dataTransfer.setData('text/plain', draggedTaskId);
    });
  }
  var columns = document.querySelectorAll('[data-pa-drop-status]');
  for (var k = 0; k < columns.length; k++) {
    columns[k].addEventListener('dragover', function(ev){ ev.preventDefault(); });
    columns[k].addEventListener('drop', function(ev){
      ev.preventDefault();
      var taskId = draggedTaskId;
      if (!taskId && ev.dataTransfer) taskId = ev.dataTransfer.getData('text/plain');
      var status = this.getAttribute('data-pa-drop-status');
      var form = document.getElementById('paStatusMoveForm');
      if (!taskId || !status || !form) return;
      form.elements['task_id'].value = taskId;
      form.elements['status'].value = status;
      form.submit();
    });
  }
})();
</script>
