<?php
/**
 * 공무 협업툴 메인 화면
 * - 공무 전용 업무 등록, 칸반/목록/내 업무 보기, 상세/댓글/첨부/변경이력 화면을 제공한다.
 */

use App\Core\Db;

require_once __DIR__ . '/../../services/PublicAffairsCollaborationService.php';

$pdo = Db::pdo();
$settings = cpms_public_affairs_collab_settings();
$projects = cpms_public_affairs_collab_fetch_projects($pdo);
$employees = cpms_public_affairs_collab_fetch_employees($pdo);
$currentEmployee = cpms_public_affairs_collab_current_employee($pdo);
$canManageCollab = cpms_public_affairs_collab_is_admin_user();
$canAccessCollab = cpms_public_affairs_collab_user_can_access_module($currentEmployee);

if (!function_exists('cpms_public_affairs_collab_view_url')) {
function cpms_public_affairs_collab_view_url($overrides) {
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

if (!function_exists('cpms_public_affairs_collab_selected')) {
function cpms_public_affairs_collab_selected($a, $b) {
    return ((string)$a === (string)$b) ? 'selected' : '';
}}

if (!function_exists('cpms_public_affairs_collab_checked_multi')) {
function cpms_public_affairs_collab_checked_multi($values, $value) {
    if (!is_array($values)) $values = array();
    return in_array((int)$value, array_map('intval', $values), true) ? 'selected' : '';
}}

if (!function_exists('cpms_public_affairs_collab_priority_class')) {
function cpms_public_affairs_collab_priority_class($priority) {
    if ($priority === '긴급') return 'bg-rose-50 text-rose-700 border-rose-200';
    if ($priority === '높음') return 'bg-orange-50 text-orange-700 border-orange-200';
    if ($priority === '낮음') return 'bg-slate-100 text-slate-600 border-slate-200';
    return 'bg-blue-50 text-blue-700 border-blue-200';
}}

if (!function_exists('cpms_public_affairs_collab_status_class')) {
function cpms_public_affairs_collab_status_class($status) {
    if ($status === '완료') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    if ($status === '반려') return 'bg-rose-50 text-rose-700 border-rose-200';
    if ($status === '보류') return 'bg-slate-100 text-slate-700 border-slate-200';
    if ($status === '결재대기' || $status === '검토중') return 'bg-indigo-50 text-indigo-700 border-indigo-200';
    if ($status === '자료대기') return 'bg-amber-50 text-amber-700 border-amber-200';
    if ($status === '진행중') return 'bg-blue-50 text-blue-700 border-blue-200';
    return 'bg-gray-100 text-gray-700 border-gray-200';
}}

if (!function_exists('cpms_public_affairs_collab_render_employee_options')) {
function cpms_public_affairs_collab_render_employee_options($employees, $selected, $multiple) {
    foreach ($employees as $employee) {
        $id = isset($employee['id']) ? (int)$employee['id'] : 0;
        if ($id <= 0) continue;
        $name = isset($employee['name']) ? (string)$employee['name'] : '-';
        $dept = isset($employee['department']) ? (string)$employee['department'] : '-';
        $pos = isset($employee['position']) && trim((string)$employee['position']) !== '' ? (string)$employee['position'] : '-';
        $sel = $multiple ? cpms_public_affairs_collab_checked_multi($selected, $id) : cpms_public_affairs_collab_selected($selected, $id);
        echo '<option value="' . (int)$id . '" ' . $sel . '>' . h($name . ' / ' . $dept . ' / ' . $pos) . '</option>';
    }
}}

if (!function_exists('cpms_public_affairs_collab_due_text')) {
function cpms_public_affairs_collab_due_text($task) {
    $due = isset($task['due_date']) ? trim((string)$task['due_date']) : '';
    if ($due === '') return '-';
    $time = isset($task['due_time']) ? trim((string)$task['due_time']) : '';
    return $due . ($time !== '' ? ' ' . $time : '');
}}

if (!$canAccessCollab) {
    ?>
    <div class="rounded-2xl border border-red-200 bg-red-50 text-red-700 p-5 font-bold">
        공무 협업툴 접근 권한이 없습니다. 공무/관리/임원 권한 또는 본인이 참여한 업무만 확인할 수 있습니다.
    </div>
    <?php
    return;
}

$subView = isset($_GET['view']) ? trim((string)$_GET['view']) : '';
$viewMode = isset($_GET['view_mode']) ? trim((string)$_GET['view_mode']) : 'kanban';
if ($viewMode !== 'kanban' && $viewMode !== 'list' && $viewMode !== 'my') $viewMode = 'kanban';

$allTasks = cpms_public_affairs_collab_list_tasks();
$visibleTasks = cpms_public_affairs_collab_visible_tasks($allTasks, $currentEmployee);
$summary = cpms_public_affairs_collab_summary($visibleTasks, $currentEmployee);
$filters = array(
    'project_id' => isset($_GET['project_id']) ? $_GET['project_id'] : '',
    'assignee_employee_id' => isset($_GET['assignee_employee_id']) ? $_GET['assignee_employee_id'] : '',
    'status' => isset($_GET['status']) ? $_GET['status'] : '',
    'priority' => isset($_GET['priority']) ? $_GET['priority'] : '',
    'task_type' => isset($_GET['task_type']) ? $_GET['task_type'] : '',
    'due_from' => isset($_GET['due_from']) ? $_GET['due_from'] : '',
    'due_to' => isset($_GET['due_to']) ? $_GET['due_to'] : '',
    'keyword' => isset($_GET['keyword']) ? $_GET['keyword'] : '',
);
$filteredTasks = cpms_public_affairs_collab_apply_filters($visibleTasks, $filters);
if ($viewMode === 'my') {
    $myOnly = array();
    foreach ($filteredTasks as $task) {
        $employeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
        $email = isset($currentEmployee['email']) ? strtolower(trim((string)$currentEmployee['email'])) : '';
        if (($employeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === $employeeId) ||
            ($email !== '' && isset($task['assignee_email']) && strtolower(trim((string)$task['assignee_email'])) === $email)) {
            $myOnly[] = $task;
        }
    }
    $filteredTasks = $myOnly;
}
$selectedTaskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
$selectedTask = $selectedTaskId > 0 ? cpms_public_affairs_collab_find_task($selectedTaskId) : null;
if (is_array($selectedTask) && !cpms_public_affairs_collab_user_can_view_task($selectedTask, $currentEmployee)) $selectedTask = null;
?>

<div class="space-y-6">
  <?php if ($subView === 'settings' && $canManageCollab): ?>
    <div class="bg-white/80 rounded-3xl border border-gray-100 shadow-lg shadow-gray-200/50 p-6">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-2xl font-extrabold text-gray-900">공무 협업툴 설정</h2>
          <div class="text-sm text-gray-500 mt-1">업무유형, 상태, 우선순위를 줄 단위로 관리합니다.</div>
        </div>
        <a href="?r=공무&tab=collaboration" class="px-4 py-3 rounded-2xl bg-white border border-gray-200 text-gray-700 font-bold">업무 화면</a>
      </div>

      <form method="post" action="?r=project/collaboration_action" class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="settings">
        <input type="hidden" name="return_url" value="?r=공무&tab=collaboration&view=settings">
        <div>
          <div class="text-sm font-bold text-gray-700 mb-2">업무유형</div>
          <textarea name="task_types" rows="16" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none"><?php echo h(implode("\n", $settings['task_types'])); ?></textarea>
        </div>
        <div>
          <div class="text-sm font-bold text-gray-700 mb-2">상태</div>
          <textarea name="statuses" rows="16" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none"><?php echo h(implode("\n", $settings['statuses'])); ?></textarea>
        </div>
        <div>
          <div class="text-sm font-bold text-gray-700 mb-2">우선순위</div>
          <textarea name="priorities" rows="16" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none"><?php echo h(implode("\n", $settings['priorities'])); ?></textarea>
        </div>
        <div class="lg:col-span-3 flex justify-end">
          <button type="submit" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">설정 저장</button>
        </div>
      </form>
    </div>
    <?php return; ?>
  <?php endif; ?>

  <?php if (!empty($flash) && is_array($flash)): ?>
    <?php
      $flashType = isset($flash['type']) ? (string)$flash['type'] : 'info';
      $flashClass = 'bg-blue-50 border-blue-200 text-blue-800';
      if ($flashType === 'success') $flashClass = 'bg-emerald-50 border-emerald-200 text-emerald-800';
      if ($flashType === 'error' || $flashType === 'danger') $flashClass = 'bg-red-50 border-red-200 text-red-800';
    ?>
    <div class="rounded-2xl border p-4 font-bold <?php echo h($flashClass); ?>"><?php echo h(isset($flash['message']) ? $flash['message'] : ''); ?></div>
  <?php endif; ?>

  <div class="flex items-center justify-between gap-3 flex-wrap">
    <div>
      <h2 class="text-2xl font-extrabold text-gray-900">공무 협업툴</h2>
      <div class="text-sm text-gray-500 mt-1">계약, 변경, 청구, 자료제출 업무를 한 곳에서 관리합니다.</div>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php if ($canManageCollab): ?>
        <a href="?r=공무&tab=collaboration&view=settings" class="px-4 py-3 rounded-2xl bg-white border border-gray-200 text-gray-700 font-bold">
          <i data-lucide="settings" class="w-4 h-4 inline"></i> 설정
        </a>
        <button type="button" data-modal-open="collabCreate" class="px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold shadow-lg">
          <i data-lucide="plus" class="w-5 h-5 inline"></i> 업무 등록
        </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
    <div class="p-5 rounded-2xl bg-white border border-gray-100 shadow-sm"><div class="text-sm text-gray-500 font-bold">전체 업무</div><div class="mt-2 text-3xl font-extrabold text-gray-900"><?php echo (int)$summary['all']; ?></div></div>
    <div class="p-5 rounded-2xl bg-white border border-gray-100 shadow-sm"><div class="text-sm text-gray-500 font-bold">내 업무</div><div class="mt-2 text-3xl font-extrabold text-blue-700"><?php echo (int)$summary['mine']; ?></div></div>
    <div class="p-5 rounded-2xl bg-white border border-gray-100 shadow-sm"><div class="text-sm text-gray-500 font-bold">오늘 마감</div><div class="mt-2 text-3xl font-extrabold text-amber-700"><?php echo (int)$summary['today']; ?></div></div>
    <div class="p-5 rounded-2xl bg-white border border-gray-100 shadow-sm"><div class="text-sm text-gray-500 font-bold">지연</div><div class="mt-2 text-3xl font-extrabold text-rose-700"><?php echo (int)$summary['delayed']; ?></div></div>
    <div class="p-5 rounded-2xl bg-white border border-gray-100 shadow-sm"><div class="text-sm text-gray-500 font-bold">완료</div><div class="mt-2 text-3xl font-extrabold text-emerald-700"><?php echo (int)$summary['done']; ?></div></div>
  </div>

  <div class="bg-white/80 rounded-3xl border border-gray-100 shadow-lg shadow-gray-200/50 p-5">
    <div class="flex flex-wrap gap-2 mb-5">
      <a href="<?php echo h(cpms_public_affairs_collab_view_url(array('view_mode' => 'kanban', 'task_id' => null))); ?>" class="px-4 py-3 rounded-2xl border font-extrabold <?php echo $viewMode === 'kanban' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-200'; ?>">칸반 보기</a>
      <a href="<?php echo h(cpms_public_affairs_collab_view_url(array('view_mode' => 'list', 'task_id' => null))); ?>" class="px-4 py-3 rounded-2xl border font-extrabold <?php echo $viewMode === 'list' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-200'; ?>">목록 보기</a>
      <a href="<?php echo h(cpms_public_affairs_collab_view_url(array('view_mode' => 'my', 'task_id' => null))); ?>" class="px-4 py-3 rounded-2xl border font-extrabold <?php echo $viewMode === 'my' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-200'; ?>">내 업무 보기</a>
    </div>

    <form method="get" action="" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
      <input type="hidden" name="r" value="공무">
      <input type="hidden" name="tab" value="collaboration">
      <input type="hidden" name="view_mode" value="<?php echo h($viewMode); ?>">
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">프로젝트</div>
        <select name="project_id" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
          <option value="">전체</option>
          <?php foreach ($projects as $project): ?>
            <option value="<?php echo (int)$project['id']; ?>" <?php echo cpms_public_affairs_collab_selected($filters['project_id'], isset($project['id']) ? $project['id'] : ''); ?>><?php echo h(isset($project['name']) ? $project['name'] : ''); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">담당자</div>
        <select name="assignee_employee_id" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
          <option value="">전체</option>
          <?php cpms_public_affairs_collab_render_employee_options($employees, $filters['assignee_employee_id'], false); ?>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">상태</div>
        <select name="status" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
          <option value="">전체</option>
          <?php foreach ($settings['statuses'] as $status): ?><option value="<?php echo h($status); ?>" <?php echo cpms_public_affairs_collab_selected($filters['status'], $status); ?>><?php echo h($status); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">우선순위</div>
        <select name="priority" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
          <option value="">전체</option>
          <?php foreach ($settings['priorities'] as $priority): ?><option value="<?php echo h($priority); ?>" <?php echo cpms_public_affairs_collab_selected($filters['priority'], $priority); ?>><?php echo h($priority); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">업무유형</div>
        <select name="task_type" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
          <option value="">전체</option>
          <?php foreach ($settings['task_types'] as $type): ?><option value="<?php echo h($type); ?>" <?php echo cpms_public_affairs_collab_selected($filters['task_type'], $type); ?>><?php echo h($type); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">마감일 시작</div>
        <input type="date" name="due_from" value="<?php echo h($filters['due_from']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">마감일 종료</div>
        <input type="date" name="due_to" value="<?php echo h($filters['due_to']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">키워드</div>
        <input name="keyword" value="<?php echo h($filters['keyword']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="제목, 내용, 프로젝트">
      </div>
      <div class="md:col-span-2 xl:col-span-4 flex flex-wrap justify-end gap-2">
        <a href="?r=공무&tab=collaboration&view_mode=<?php echo h($viewMode); ?>" class="px-4 py-3 rounded-2xl bg-white border border-gray-200 text-gray-700 font-bold">초기화</a>
        <button type="submit" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">검색</button>
      </div>
    </form>
  </div>

  <?php if (is_array($selectedTask)): ?>
    <?php
      $comments = cpms_public_affairs_collab_comments((int)$selectedTask['id']);
      $files = cpms_public_affairs_collab_files((int)$selectedTask['id']);
      $history = cpms_public_affairs_collab_history((int)$selectedTask['id']);
      $canEditSelected = cpms_public_affairs_collab_user_can_edit_task($selectedTask, $currentEmployee);
    ?>
    <div class="bg-white/90 rounded-3xl border border-blue-100 shadow-lg shadow-blue-100/40 p-6">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-0">
          <div class="flex flex-wrap gap-2 mb-2">
            <span class="px-3 py-1 rounded-full border text-sm font-bold <?php echo h(cpms_public_affairs_collab_status_class(isset($selectedTask['status']) ? $selectedTask['status'] : '')); ?>"><?php echo h(isset($selectedTask['status']) ? $selectedTask['status'] : '-'); ?></span>
            <span class="px-3 py-1 rounded-full border text-sm font-bold <?php echo h(cpms_public_affairs_collab_priority_class(isset($selectedTask['priority']) ? $selectedTask['priority'] : '')); ?>"><?php echo h(isset($selectedTask['priority']) ? $selectedTask['priority'] : '-'); ?></span>
            <?php if (cpms_public_affairs_collab_is_delayed($selectedTask)): ?><span class="px-3 py-1 rounded-full border text-sm font-bold bg-rose-600 text-white border-rose-600">지연</span><?php endif; ?>
          </div>
          <h3 class="text-2xl font-extrabold text-gray-900 break-words"><?php echo h(isset($selectedTask['title']) ? $selectedTask['title'] : ''); ?></h3>
          <div class="mt-2 text-sm text-gray-600"><?php echo h(isset($selectedTask['project_name']) ? $selectedTask['project_name'] : '-'); ?> · 담당자 <?php echo h(isset($selectedTask['assignee_name']) ? $selectedTask['assignee_name'] : '-'); ?> · 마감 <?php echo h(cpms_public_affairs_collab_due_text($selectedTask)); ?></div>
        </div>
        <a href="<?php echo h(cpms_public_affairs_collab_view_url(array('task_id' => null))); ?>" class="px-4 py-3 rounded-2xl bg-white border border-gray-200 text-gray-700 font-bold">닫기</a>
      </div>

      <div class="mt-6 grid grid-cols-1 xl:grid-cols-3 gap-5">
        <div class="xl:col-span-2 space-y-5">
          <form method="post" action="?r=project/collaboration_action" class="rounded-2xl border border-gray-100 bg-gray-50 p-5 space-y-4">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="task_id" value="<?php echo (int)$selectedTask['id']; ?>">
            <input type="hidden" name="return_url" value="<?php echo h(cpms_public_affairs_collab_view_url(array('task_id' => (int)$selectedTask['id']))); ?>">
            <input type="hidden" name="reference_employee_ids_present" value="1">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div class="md:col-span-2">
                <div class="text-sm font-bold text-gray-700 mb-1">제목</div>
                <input name="title" value="<?php echo h(isset($selectedTask['title']) ? $selectedTask['title'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
              </div>
              <div class="md:col-span-2">
                <div class="text-sm font-bold text-gray-700 mb-1">상세내용</div>
                <textarea name="content" rows="5" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white"><?php echo h(isset($selectedTask['content']) ? $selectedTask['content'] : ''); ?></textarea>
              </div>
              <div>
                <div class="text-sm font-bold text-gray-700 mb-1">업무유형</div>
                <select name="task_type" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                  <?php foreach ($settings['task_types'] as $type): ?><option value="<?php echo h($type); ?>" <?php echo cpms_public_affairs_collab_selected(isset($selectedTask['task_type']) ? $selectedTask['task_type'] : '', $type); ?>><?php echo h($type); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div>
                <div class="text-sm font-bold text-gray-700 mb-1">상태</div>
                <select name="status" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                  <?php foreach ($settings['statuses'] as $status): ?><option value="<?php echo h($status); ?>" <?php echo cpms_public_affairs_collab_selected(isset($selectedTask['status']) ? $selectedTask['status'] : '', $status); ?>><?php echo h($status); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div>
                <div class="text-sm font-bold text-gray-700 mb-1">담당자</div>
                <select name="assignee_employee_id" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                  <?php cpms_public_affairs_collab_render_employee_options($employees, isset($selectedTask['assignee_employee_id']) ? $selectedTask['assignee_employee_id'] : 0, false); ?>
                </select>
              </div>
              <div>
                <div class="text-sm font-bold text-gray-700 mb-1">우선순위</div>
                <select name="priority" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                  <?php foreach ($settings['priorities'] as $priority): ?><option value="<?php echo h($priority); ?>" <?php echo cpms_public_affairs_collab_selected(isset($selectedTask['priority']) ? $selectedTask['priority'] : '', $priority); ?>><?php echo h($priority); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div>
                <div class="text-sm font-bold text-gray-700 mb-1">마감일</div>
                <input type="date" name="due_date" value="<?php echo h(isset($selectedTask['due_date']) ? $selectedTask['due_date'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
              </div>
              <div>
                <div class="text-sm font-bold text-gray-700 mb-1">마감시간</div>
                <input type="time" name="due_time" value="<?php echo h(isset($selectedTask['due_time']) ? $selectedTask['due_time'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
              </div>
              <div>
                <div class="text-sm font-bold text-gray-700 mb-1">관련 금액</div>
                <input name="related_amount" value="<?php echo h(isset($selectedTask['related_amount']) ? $selectedTask['related_amount'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
              </div>
              <div>
                <div class="text-sm font-bold text-gray-700 mb-1">계약/공기 영향</div>
                <select name="contract_impact" <?php echo $canEditSelected ? '' : 'disabled'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                  <?php foreach (array('없음','있음','확인필요') as $impact): ?><option value="<?php echo h($impact); ?>" <?php echo cpms_public_affairs_collab_selected(isset($selectedTask['contract_impact']) ? $selectedTask['contract_impact'] : '', $impact); ?>><?php echo h($impact); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="md:col-span-2">
                <div class="text-sm font-bold text-gray-700 mb-1">관련 문서 링크</div>
                <input name="document_link" value="<?php echo h(isset($selectedTask['document_link']) ? $selectedTask['document_link'] : ''); ?>" <?php echo $canEditSelected ? '' : 'readonly'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
              </div>
              <div class="md:col-span-2">
                <div class="text-sm font-bold text-gray-700 mb-1">참조자</div>
                <select name="reference_employee_ids[]" multiple <?php echo $canEditSelected ? '' : 'disabled'; ?> class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white" style="min-height:130px;">
                  <?php cpms_public_affairs_collab_render_employee_options($employees, isset($selectedTask['reference_employee_ids']) ? $selectedTask['reference_employee_ids'] : array(), true); ?>
                </select>
              </div>
            </div>
            <?php if ($canEditSelected): ?>
              <div class="flex flex-wrap justify-between gap-2">
                <div class="flex flex-wrap gap-2">
                  <button type="submit" name="state_action" value="complete" class="px-4 py-3 rounded-2xl bg-emerald-600 text-white font-extrabold">완료 처리</button>
                  <button type="submit" name="state_action" value="reject" class="px-4 py-3 rounded-2xl bg-rose-600 text-white font-extrabold">반려 처리</button>
                  <button type="submit" name="state_action" value="hold" class="px-4 py-3 rounded-2xl bg-slate-700 text-white font-extrabold">보류 처리</button>
                </div>
                <button type="submit" name="action" value="update" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">변경 저장</button>
              </div>
            <?php endif; ?>
          </form>

          <div class="rounded-2xl border border-gray-100 bg-white p-5">
            <h4 class="text-xl font-extrabold text-gray-900 mb-4">댓글</h4>
            <div class="space-y-3">
              <?php if (count($comments) === 0): ?><div class="text-sm text-gray-500">등록된 댓글이 없습니다.</div><?php endif; ?>
              <?php foreach ($comments as $comment): ?>
                <div class="p-4 rounded-2xl bg-gray-50 border border-gray-100">
                  <div class="text-sm font-bold text-gray-700"><?php echo h(isset($comment['created_by_name']) ? $comment['created_by_name'] : '-'); ?> · <?php echo h(isset($comment['created_at']) ? $comment['created_at'] : ''); ?></div>
                  <div class="mt-2 text-gray-800 whitespace-pre-wrap"><?php echo h(isset($comment['content']) ? $comment['content'] : ''); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <form method="post" action="?r=project/collaboration_action" class="mt-4">
              <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="action" value="comment">
              <input type="hidden" name="task_id" value="<?php echo (int)$selectedTask['id']; ?>">
              <input type="hidden" name="return_url" value="<?php echo h(cpms_public_affairs_collab_view_url(array('task_id' => (int)$selectedTask['id']))); ?>">
              <textarea name="comment" rows="3" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="댓글을 입력하세요."></textarea>
              <div class="mt-2 flex justify-end"><button type="submit" class="px-4 py-3 rounded-2xl bg-blue-600 text-white font-extrabold">댓글 등록</button></div>
            </form>
          </div>
        </div>

        <div class="space-y-5">
          <div class="rounded-2xl border border-gray-100 bg-white p-5">
            <h4 class="text-xl font-extrabold text-gray-900 mb-4">첨부파일</h4>
            <div class="space-y-2">
              <?php if (count($files) === 0): ?><div class="text-sm text-gray-500">첨부파일이 없습니다.</div><?php endif; ?>
              <?php foreach ($files as $file): ?>
                <a href="?r=project/collaboration_file&id=<?php echo (int)$file['id']; ?>" class="block px-4 py-3 rounded-2xl border border-gray-100 bg-gray-50 text-gray-800 font-bold hover:bg-gray-100">
                  <i data-lucide="paperclip" class="w-4 h-4 inline"></i> <?php echo h(isset($file['original_name']) ? $file['original_name'] : 'file'); ?>
                </a>
              <?php endforeach; ?>
            </div>
            <form method="post" action="?r=project/collaboration_action" enctype="multipart/form-data" class="mt-4 space-y-3">
              <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="action" value="upload">
              <input type="hidden" name="task_id" value="<?php echo (int)$selectedTask['id']; ?>">
              <input type="hidden" name="return_url" value="<?php echo h(cpms_public_affairs_collab_view_url(array('task_id' => (int)$selectedTask['id']))); ?>">
              <input type="file" name="attachments[]" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
              <button type="submit" class="w-full px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">첨부 등록</button>
            </form>
          </div>

          <div class="rounded-2xl border border-gray-100 bg-white p-5">
            <h4 class="text-xl font-extrabold text-gray-900 mb-4">변경이력</h4>
            <div class="space-y-3 max-h-[520px] overflow-y-auto">
              <?php if (count($history) === 0): ?><div class="text-sm text-gray-500">변경이력이 없습니다.</div><?php endif; ?>
              <?php foreach ($history as $log): ?>
                <div class="p-3 rounded-2xl bg-gray-50 border border-gray-100">
                  <div class="text-sm font-extrabold text-gray-900"><?php echo h(isset($log['action']) ? $log['action'] : '-'); ?></div>
                  <div class="text-xs text-gray-500 mt-1"><?php echo h(isset($log['actor_name']) ? $log['actor_name'] : '-'); ?> · <?php echo h(isset($log['created_at']) ? $log['created_at'] : ''); ?></div>
                  <?php if (isset($log['old_value']) && (string)$log['old_value'] !== '' || isset($log['new_value']) && (string)$log['new_value'] !== ''): ?>
                    <div class="mt-2 text-xs text-gray-600 break-words"><?php echo h(isset($log['old_value']) ? $log['old_value'] : ''); ?> → <?php echo h(isset($log['new_value']) ? $log['new_value'] : ''); ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($viewMode === 'list' || $viewMode === 'my'): ?>
    <div class="bg-white/80 rounded-3xl border border-gray-100 shadow-lg shadow-gray-200/50 overflow-hidden">
      <div class="px-6 py-4 border-b border-gray-100 font-extrabold text-gray-900">업무 목록 <?php echo count($filteredTasks); ?>건</div>
      <div class="cpms-responsive-table-wrap">
        <table class="cpms-responsive-table text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="px-4 py-3 text-left">프로젝트명</th>
              <th class="px-4 py-3 text-left">업무유형</th>
              <th class="px-4 py-3 text-left">제목</th>
              <th class="px-4 py-3 text-left">요청자</th>
              <th class="px-4 py-3 text-left">담당자</th>
              <th class="px-4 py-3 text-left">상태</th>
              <th class="px-4 py-3 text-left">우선순위</th>
              <th class="px-4 py-3 text-left">마감일</th>
              <th class="px-4 py-3 text-left">등록일</th>
              <th class="px-4 py-3 text-left">최근수정일</th>
              <th class="px-4 py-3 text-left">버튼</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($filteredTasks) === 0): ?><tr><td colspan="11" class="px-4 py-8 text-center text-gray-500">조회된 업무가 없습니다.</td></tr><?php endif; ?>
            <?php foreach ($filteredTasks as $task): ?>
              <tr class="border-t border-gray-100 <?php echo cpms_public_affairs_collab_is_delayed($task) ? 'bg-rose-50/50' : ''; ?>">
                <td class="px-4 py-3"><?php echo h(isset($task['project_name']) ? $task['project_name'] : '-'); ?></td>
                <td class="px-4 py-3"><?php echo h(isset($task['task_type']) ? $task['task_type'] : '-'); ?></td>
                <td class="px-4 py-3 text-left" data-wrap="1"><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></td>
                <td class="px-4 py-3"><?php echo h(isset($task['requester_name']) ? $task['requester_name'] : '-'); ?></td>
                <td class="px-4 py-3"><?php echo h(isset($task['assignee_name']) ? $task['assignee_name'] : '-'); ?></td>
                <td class="px-4 py-3"><span class="px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_public_affairs_collab_status_class(isset($task['status']) ? $task['status'] : '')); ?>"><?php echo h(isset($task['status']) ? $task['status'] : '-'); ?></span></td>
                <td class="px-4 py-3"><span class="px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_public_affairs_collab_priority_class(isset($task['priority']) ? $task['priority'] : '')); ?>"><?php echo h(isset($task['priority']) ? $task['priority'] : '-'); ?></span></td>
                <td class="px-4 py-3"><?php echo h(cpms_public_affairs_collab_due_text($task)); ?><?php if (cpms_public_affairs_collab_is_delayed($task)): ?><div class="text-xs font-bold text-rose-700">지연</div><?php endif; ?></td>
                <td class="px-4 py-3"><?php echo h(isset($task['created_at']) ? substr((string)$task['created_at'], 0, 16) : '-'); ?></td>
                <td class="px-4 py-3"><?php echo h(isset($task['updated_at']) ? substr((string)$task['updated_at'], 0, 16) : '-'); ?></td>
                <td class="px-4 py-3"><a href="<?php echo h(cpms_public_affairs_collab_view_url(array('task_id' => isset($task['id']) ? (int)$task['id'] : 0))); ?>" class="px-3 py-2 rounded-xl bg-white border border-gray-200 font-bold">상세보기</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>
    <?php $groups = cpms_public_affairs_collab_group_by_status($filteredTasks, $settings['statuses']); ?>
    <div class="flex gap-4 overflow-x-auto pb-2">
      <?php foreach ($groups as $status => $items): ?>
        <div class="min-w-[300px] w-[300px] bg-white/80 rounded-3xl border border-gray-100 shadow-lg shadow-gray-200/40 p-4">
          <div class="flex items-center justify-between gap-2 mb-4">
            <div class="font-extrabold text-gray-900"><?php echo h($status); ?></div>
            <span class="px-3 py-1 rounded-full bg-gray-100 text-gray-700 text-sm font-bold"><?php echo count($items); ?>건</span>
          </div>
          <div class="space-y-3">
            <?php if (count($items) === 0): ?><div class="p-5 rounded-2xl border border-dashed border-gray-200 text-sm text-gray-500">업무 없음</div><?php endif; ?>
            <?php foreach ($items as $task): ?>
              <?php
                $isDelayed = cpms_public_affairs_collab_is_delayed($task);
                $isUrgent = isset($task['priority']) && (string)$task['priority'] === '긴급';
              ?>
              <div class="rounded-2xl border p-4 <?php echo $isDelayed ? 'border-rose-300 bg-rose-50' : ($isUrgent ? 'border-orange-300 bg-orange-50' : 'border-gray-100 bg-white'); ?>">
                <div class="flex items-center justify-between gap-2">
                  <span class="px-2 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_public_affairs_collab_priority_class(isset($task['priority']) ? $task['priority'] : '')); ?>"><?php echo h(isset($task['priority']) ? $task['priority'] : '-'); ?></span>
                  <?php if ($isDelayed): ?><span class="text-xs font-extrabold text-rose-700">지연</span><?php endif; ?>
                </div>
                <a href="<?php echo h(cpms_public_affairs_collab_view_url(array('task_id' => isset($task['id']) ? (int)$task['id'] : 0))); ?>" class="block mt-3 text-lg font-extrabold text-gray-900 leading-7 hover:text-blue-700"><?php echo h(isset($task['title']) ? $task['title'] : '-'); ?></a>
                <div class="mt-2 text-sm text-gray-600"><?php echo h(isset($task['project_name']) ? $task['project_name'] : '-'); ?></div>
                <div class="mt-1 text-sm text-gray-600">담당자: <?php echo h(isset($task['assignee_name']) ? $task['assignee_name'] : '-'); ?></div>
                <div class="mt-1 text-sm text-gray-500">마감: <?php echo h(cpms_public_affairs_collab_due_text($task)); ?></div>
                <?php if (cpms_public_affairs_collab_user_can_edit_task($task, $currentEmployee)): ?>
                  <form method="post" action="?r=project/collaboration_action" class="mt-3 flex gap-2">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="quick_update">
                    <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                    <input type="hidden" name="return_url" value="<?php echo h(cpms_public_affairs_collab_view_url(array())); ?>">
                    <select name="status" class="min-w-0 flex-1 px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm">
                      <?php foreach ($settings['statuses'] as $st): ?><option value="<?php echo h($st); ?>" <?php echo cpms_public_affairs_collab_selected(isset($task['status']) ? $task['status'] : '', $st); ?>><?php echo h($st); ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="px-3 py-2 rounded-xl bg-gray-900 text-white text-sm font-bold">변경</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($canManageCollab): ?>
<div id="modal-collabCreate" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" data-modal-close="collabCreate"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-5xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden" style="max-height:92vh;">
      <div class="p-6 border-b border-gray-100 flex items-center justify-between">
        <div>
          <h3 class="text-2xl font-extrabold text-gray-900">업무 등록</h3>
          <div class="text-sm text-gray-500 mt-1">공무 협업 업무를 등록하고 담당자를 지정합니다.</div>
        </div>
        <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="collabCreate">닫기</button>
      </div>
      <form method="post" action="?r=project/collaboration_action" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return_url" value="?r=공무&tab=collaboration">
        <div class="p-6 overflow-y-auto" style="max-height:calc(92vh - 170px);">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">프로젝트 선택 *</div>
              <select name="project_id" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                <option value="">선택하세요</option>
                <?php foreach ($projects as $project): ?><option value="<?php echo (int)$project['id']; ?>"><?php echo h(isset($project['name']) ? $project['name'] : ''); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">업무유형</div>
              <select name="task_type" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                <?php foreach ($settings['task_types'] as $type): ?><option value="<?php echo h($type); ?>"><?php echo h($type); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">제목 *</div>
              <input name="title" required class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="예: 변경계약 검토 요청">
            </div>
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">상세내용</div>
              <textarea name="content" rows="5" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="필요한 검토 내용과 요청사항을 적어주세요."></textarea>
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">요청자</div>
              <select name="requester_employee_id" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                <?php cpms_public_affairs_collab_render_employee_options($employees, isset($currentEmployee['id']) ? $currentEmployee['id'] : 0, false); ?>
              </select>
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">담당자 *</div>
              <select name="assignee_employee_id" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                <option value="">선택하세요</option>
                <?php cpms_public_affairs_collab_render_employee_options($employees, '', false); ?>
              </select>
            </div>
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">참조자</div>
              <select name="reference_employee_ids[]" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white" style="min-height:120px;">
                <?php cpms_public_affairs_collab_render_employee_options($employees, array(), true); ?>
              </select>
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">우선순위</div>
              <select name="priority" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                <?php foreach ($settings['priorities'] as $priority): ?><option value="<?php echo h($priority); ?>" <?php echo $priority === '보통' ? 'selected' : ''; ?>><?php echo h($priority); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">상태</div>
              <select name="status" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                <?php foreach ($settings['statuses'] as $status): ?><option value="<?php echo h($status); ?>"><?php echo h($status); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">마감일</div>
              <input type="date" name="due_date" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">마감시간</div>
              <input type="time" name="due_time" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">관련 금액</div>
              <input name="related_amount" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="숫자만 입력">
            </div>
            <div>
              <div class="text-sm font-bold text-gray-700 mb-1">계약/공기 영향 여부</div>
              <select name="contract_impact" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                <option value="없음">없음</option>
                <option value="있음">있음</option>
                <option value="확인필요">확인필요</option>
              </select>
            </div>
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">관련 문서 링크</div>
              <input name="document_link" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="문서 URL 또는 공유 링크">
            </div>
            <div class="md:col-span-2">
              <div class="text-sm font-bold text-gray-700 mb-1">첨부파일</div>
              <input type="file" name="attachments[]" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
            </div>
          </div>
        </div>
        <div class="p-6 border-t border-gray-100 flex justify-end gap-2">
          <button type="button" data-modal-close="collabCreate" class="px-4 py-3 rounded-2xl bg-white border border-gray-200 text-gray-700 font-bold">취소</button>
          <button type="submit" class="px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold">등록</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
