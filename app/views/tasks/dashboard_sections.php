<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/task_feed_helper.php';

if (!function_exists('cpms_render_task_action_link')) {
function cpms_render_task_action_link($item)
{
    if (isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1) {
        return '<button type="button" data-task-detail-open data-task-id="' . (int)$item['source_id'] . '" class="px-3 py-2 rounded-xl bg-white border border-gray-200 text-sm font-bold text-slate-700">상세</button>';
    }
    return '<a href="' . h(isset($item['action_url']) ? $item['action_url'] : '#') . '" class="px-3 py-2 rounded-xl bg-white border border-gray-200 text-sm font-bold text-slate-700">상세 이동</a>';
}}

if (!function_exists('cpms_executive_task_due_text')) {
function cpms_executive_task_due_text($item)
{
    $dueText = '-';
    if (isset($item['due_date']) && trim((string)$item['due_date']) !== '') {
        $dueText = (string)$item['due_date'];
        if (isset($item['due_time']) && trim((string)$item['due_time']) !== '') {
            $dueText .= ' ' . substr((string)$item['due_time'], 0, 5);
        }
    }
    return $dueText;
}}

if (!function_exists('cpms_executive_task_detail_button')) {
function cpms_executive_task_detail_button($item, $label)
{
    $label = trim((string)$label) !== '' ? (string)$label : '상세 보기';
    if (isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1) {
        return '<button type="button" data-exec-task-detail-open data-task-id="' . (int)$item['source_id'] . '" class="px-3 py-2 rounded-xl bg-gray-900 text-white text-sm font-extrabold">' . h($label) . '</button>';
    }
    $meta = array();
    if (isset($item['requester_name']) && trim((string)$item['requester_name']) !== '') $meta[] = '요청자: ' . (string)$item['requester_name'];
    if (isset($item['assignee_name']) && trim((string)$item['assignee_name']) !== '') $meta[] = '담당자: ' . (string)$item['assignee_name'];
    $dueText = cpms_executive_task_due_text($item);
    if ($dueText !== '-') $meta[] = '기한: ' . $dueText;
    return '<button type="button" data-exec-generic-detail-open'
        . ' data-title="' . h(isset($item['title']) ? $item['title'] : '-') . '"'
        . ' data-type="' . h(cpms_tasks_type_label(isset($item['task_type']) ? $item['task_type'] : 'general')) . '"'
        . ' data-status="' . h(isset($item['display_status']) ? $item['display_status'] : '-') . '"'
        . ' data-meta="' . h(implode(' / ', $meta)) . '"'
        . ' data-content="' . h(isset($item['content']) && trim((string)$item['content']) !== '' ? $item['content'] : '-') . '"'
        . ' class="px-3 py-2 rounded-xl bg-gray-900 text-white text-sm font-extrabold">' . h($label) . '</button>';
}}

if (!function_exists('cpms_executive_add_summary_item')) {
function cpms_executive_add_summary_item(&$bucket, &$seen, $item)
{
    $key = (isset($item['source_type']) ? (string)$item['source_type'] : 'task') . ':' . (isset($item['source_id']) ? (int)$item['source_id'] : 0);
    if (isset($seen[$key])) return;
    $seen[$key] = true;
    $bucket[count($bucket)] = $item;
}}

if (!function_exists('cpms_render_task_assignee_options')) {
function cpms_render_task_assignee_options($employees, $currentLeaveIndex)
{
    if (!is_array($employees)) return;
    foreach ($employees as $employee) {
        $employeeLeaveInfo = function_exists('approval_current_leave_info_from_index') ? approval_current_leave_info_from_index($currentLeaveIndex, $employee) : null;
        $employeeLeaveLabel = is_array($employeeLeaveInfo) && isset($employeeLeaveInfo['status_label']) ? (string)$employeeLeaveInfo['status_label'] : '';
        $employeeOptionName = isset($employee['name']) ? $employee['name'] : '-';
        if ($employeeLeaveLabel !== '') {
            $employeeOptionName .= ' (' . $employeeLeaveLabel . ')';
        }
        ?>
        <option value="<?php echo (int)$employee['id']; ?>" data-department="<?php echo h(isset($employee['department']) ? $employee['department'] : ''); ?>" data-on-leave="<?php echo is_array($employeeLeaveInfo) ? '1' : '0'; ?>" <?php echo is_array($employeeLeaveInfo) ? 'disabled="disabled"' : ''; ?>>
            <?php echo h($employeeOptionName . ' / ' . (isset($employee['department']) ? $employee['department'] : '-') . ' / ' . (isset($employee['position']) && trim((string)$employee['position']) !== '' ? $employee['position'] : '-')); ?>
        </option>
        <?php
    }
}}

if (!function_exists('cpms_render_feed_card')) {
function cpms_render_feed_card($item, $currentEmployeeId, $returnUrl, $requestedMode)
{
    $statusKey = cpms_tasks_is_delayed($item) ? 'delayed' : (isset($item['status']) ? $item['status'] : 'pending');
    $isMeetingTask = isset($item['task_type']) && (string)$item['task_type'] === 'meeting';
    $isPublicAffairsCollab = isset($item['source_type']) && (string)$item['source_type'] === 'public_affairs_collab';
    $canRespondMeeting = $isMeetingTask
        && !$requestedMode
        && (int)$currentEmployeeId > 0
        && isset($item['assignee_employee_id'])
        && (int)$item['assignee_employee_id'] === (int)$currentEmployeeId
        && (!isset($item['requester_employee_id']) || (int)$item['requester_employee_id'] !== (int)$currentEmployeeId)
        && !in_array(isset($item['status']) ? (string)$item['status'] : '', array('meeting_available', 'meeting_unavailable', 'cancelled'), true);
    $canCompleteMeeting = $isMeetingTask
        && !$requestedMode
        && (int)$currentEmployeeId > 0
        && isset($item['assignee_employee_id'])
        && (int)$item['assignee_employee_id'] === (int)$currentEmployeeId
        && in_array(isset($item['status']) ? (string)$item['status'] : '', array('meeting_available', 'meeting_unavailable'), true);
    $isConstructionSchedule = isset($item['source_type']) && (string)$item['source_type'] === 'construction_schedule';
    $hasProjectName = isset($item['project_name']) && trim((string)$item['project_name']) !== '';
    $dueText = '-';
    if (isset($item['due_date']) && trim((string)$item['due_date']) !== '') {
        $dueText = (string)$item['due_date'];
        if (isset($item['due_time']) && trim((string)$item['due_time']) !== '') {
            $dueText .= ' ' . substr((string)$item['due_time'], 0, 5);
        }
    }
    ?>
    <div class="min-w-[280px] max-w-[320px] p-4 rounded-3xl border border-gray-200 bg-white shadow-sm shadow-gray-100">
        <div class="flex items-center justify-between gap-2">
            <span class="px-3 py-1 rounded-full border text-xs font-bold bg-slate-100 text-slate-700 border-slate-200"><?php echo h(cpms_tasks_type_label(isset($item['task_type']) ? $item['task_type'] : 'general')); ?></span>
            <?php if (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1): ?>
                <span class="px-3 py-1 rounded-full border text-xs font-bold bg-rose-50 text-rose-700 border-rose-200">🔥 긴급</span>
            <?php endif; ?>
            <?php if ($isPublicAffairsCollab && isset($item['priority']) && trim((string)$item['priority']) !== ''): ?>
                <span class="px-3 py-1 rounded-full border text-xs font-bold bg-blue-50 text-blue-700 border-blue-200">우선순위 <?php echo h($item['priority']); ?></span>
            <?php endif; ?>
        </div>
        <div class="mt-3 text-lg font-extrabold text-slate-900 leading-7"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></div>
        <?php if ($hasProjectName): ?>
            <div class="mt-2 text-sm text-slate-600">현장명: <?php echo h($item['project_name']); ?></div>
        <?php endif; ?>
        <?php if (!$isConstructionSchedule): ?>
        <div class="mt-2 text-sm text-slate-600">
            <?php if ($requestedMode): ?>
                담당자: <?php echo h(isset($item['assignee_name']) ? $item['assignee_name'] : '-'); ?>
            <?php else: ?>
                요청자: <?php echo h(isset($item['requester_name']) ? $item['requester_name'] : '-'); ?>
            <?php endif; ?>
        </div>
        <div class="mt-1 text-sm text-slate-500"><?php echo $isMeetingTask ? '일시' : '마감'; ?>: <?php echo h($dueText); ?></div>
        <?php endif; ?>
        <?php if ($isConstructionSchedule): ?>
            <div class="mt-1 text-sm text-slate-500">공정일: <?php echo h($dueText); ?></div>
        <?php endif; ?>
        <div class="mt-1">
            <span class="px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_tasks_badge_class('status', $statusKey)); ?>"><?php echo h(isset($item['display_status']) ? $item['display_status'] : cpms_tasks_status_label(isset($item['status']) ? $item['status'] : 'pending')); ?></span>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <?php echo cpms_render_task_action_link($item); ?>
            <?php if ($canRespondMeeting): ?>
                <form method="post" action="?r=tasks/meeting_response" class="inline">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$item['source_id']; ?>">
                    <input type="hidden" name="response" value="available">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-sm font-bold">참석가능</button>
                </form>
                <button type="button" data-meeting-unavailable-open data-task-id="<?php echo (int)$item['source_id']; ?>" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-sm font-bold">참석불가능</button>
            <?php endif; ?>
            <?php if (!$isMeetingTask && isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1 && !$requestedMode && (int)$currentEmployeeId > 0 && isset($item['assignee_employee_id']) && (int)$item['assignee_employee_id'] === (int)$currentEmployeeId && isset($item['status']) && (string)$item['status'] === 'pending'): ?>
                <form method="post" action="?r=task_progress" class="inline" referrerpolicy="origin">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$item['source_id']; ?>">
                    <input type="hidden" name="task_state" value="progress">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="px-3 py-2 rounded-xl bg-blue-600 text-white text-sm font-bold">대기</button>
                </form>
            <?php endif; ?>
            <?php if (($canCompleteMeeting || !$isMeetingTask) && isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1 && !$requestedMode && (int)$currentEmployeeId > 0 && isset($item['assignee_employee_id']) && (int)$item['assignee_employee_id'] === (int)$currentEmployeeId && !in_array(isset($item['status']) ? $item['status'] : '', array('done', 'cancelled'), true)): ?>
                <button type="button" data-task-complete-open data-task-id="<?php echo (int)$item['source_id']; ?>" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-sm font-bold">완료</button>
            <?php endif; ?>
            <?php if (isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1 && $requestedMode && (int)$currentEmployeeId > 0 && isset($item['requester_employee_id']) && (int)$item['requester_employee_id'] === (int)$currentEmployeeId && isset($item['status']) && (string)$item['status'] === 'done'): ?>
                <button type="button" data-task-revision-open data-task-id="<?php echo (int)$item['source_id']; ?>" data-task-due-date="<?php echo h(isset($item['due_date']) ? $item['due_date'] : ''); ?>" data-task-due-time="<?php echo h(isset($item['due_time']) ? substr((string)$item['due_time'], 0, 5) : '18:00'); ?>" class="px-3 py-2 rounded-xl bg-amber-500 text-white text-sm font-bold">보완요청</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
}}

if (!function_exists('cpms_render_feed_lane')) {
function cpms_render_feed_lane($title, $description, $colorClass, $items, $currentEmployeeId, $returnUrl, $requestedMode)
{
    ?>
    <div class="rounded-3xl border border-gray-200 bg-white p-5">
        <div class="flex items-center justify-between gap-3 mb-4">
            <div>
                <h3 class="text-xl font-extrabold text-gray-900"><?php echo h($title); ?></h3>
                <?php if (trim((string)$description) !== ''): ?>
                    <div class="text-sm text-gray-500 mt-1"><?php echo h($description); ?></div>
                <?php endif; ?>
            </div>
            <span class="px-3 py-1 rounded-full text-sm font-bold <?php echo h($colorClass); ?>"><?php echo count($items); ?>건</span>
        </div>
        <?php if (count($items) === 0): ?>
            <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500">표시할 업무가 없습니다.</div>
        <?php else: ?>
            <div class="flex gap-4 overflow-x-auto pb-2">
                <?php foreach ($items as $item): ?>
                    <?php cpms_render_feed_card($item, $currentEmployeeId, $returnUrl, $requestedMode); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}}

if (!function_exists('cpms_mobile_task_due_text')) {
function cpms_mobile_task_due_text($item)
{
    $dueText = '-';
    if (isset($item['due_date']) && trim((string)$item['due_date']) !== '') {
        $dueText = (string)$item['due_date'];
        if (isset($item['due_time']) && trim((string)$item['due_time']) !== '') {
            $dueText .= ' ' . substr((string)$item['due_time'], 0, 5);
        }
    }
    return $dueText;
}}

if (!function_exists('cpms_render_mobile_today_task_row')) {
function cpms_render_mobile_today_task_row($item)
{
    $statusKey = cpms_tasks_is_delayed($item) ? 'delayed' : (isset($item['status']) ? $item['status'] : 'pending');
    $isMeetingTask = isset($item['task_type']) && (string)$item['task_type'] === 'meeting';
    ?>
    <button type="button" data-modal-open="mobileTasks" class="cpms-mobile-task-list-button">
        <span class="cpms-mobile-task-list-main">
            <span class="cpms-mobile-task-list-title"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></span>
            <span class="cpms-mobile-task-list-meta"><?php echo h($isMeetingTask ? '일시' : '마감'); ?> <?php echo h(cpms_mobile_task_due_text($item)); ?></span>
        </span>
        <span class="cpms-mobile-task-list-status <?php echo h(cpms_tasks_badge_class('status', $statusKey)); ?>"><?php echo h(isset($item['display_status']) ? $item['display_status'] : cpms_tasks_status_label(isset($item['status']) ? $item['status'] : 'pending')); ?></span>
    </button>
    <?php
}}

if (!function_exists('cpms_render_mobile_task_card')) {
function cpms_render_mobile_task_card($item, $currentEmployeeId, $returnUrl)
{
    $statusKey = cpms_tasks_is_delayed($item) ? 'delayed' : (isset($item['status']) ? $item['status'] : 'pending');
    $isMeetingTask = isset($item['task_type']) && (string)$item['task_type'] === 'meeting';
    $isDirectTask = isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1;
    $isConstructionSchedule = isset($item['source_type']) && (string)$item['source_type'] === 'construction_schedule';
    $isAssignedToCurrent = (int)$currentEmployeeId > 0 && isset($item['assignee_employee_id']) && (int)$item['assignee_employee_id'] === (int)$currentEmployeeId;
    $canRespondMeeting = $isMeetingTask
        && $isAssignedToCurrent
        && (!isset($item['requester_employee_id']) || (int)$item['requester_employee_id'] !== (int)$currentEmployeeId)
        && !in_array(isset($item['status']) ? (string)$item['status'] : '', array('meeting_available', 'meeting_unavailable', 'cancelled'), true);
    $canCompleteMeeting = $isMeetingTask
        && $isAssignedToCurrent
        && in_array(isset($item['status']) ? (string)$item['status'] : '', array('meeting_available', 'meeting_unavailable'), true);
    $canStartTask = !$isMeetingTask
        && $isDirectTask
        && $isAssignedToCurrent
        && isset($item['status'])
        && (string)$item['status'] === 'pending';
    $canCompleteTask = ($canCompleteMeeting || !$isMeetingTask)
        && $isDirectTask
        && $isAssignedToCurrent
        && !in_array(isset($item['status']) ? (string)$item['status'] : '', array('done', 'cancelled'), true);
    $detailUrl = isset($item['action_url']) ? (string)$item['action_url'] : '#';
    ?>
    <div class="cpms-mobile-task-card">
        <div class="flex items-start justify-between gap-2">
            <span class="cpms-mobile-task-chip"><?php echo h(cpms_tasks_type_label(isset($item['task_type']) ? $item['task_type'] : 'general')); ?></span>
            <span class="cpms-mobile-task-status <?php echo h(cpms_tasks_badge_class('status', $statusKey)); ?>"><?php echo h(isset($item['display_status']) ? $item['display_status'] : cpms_tasks_status_label(isset($item['status']) ? $item['status'] : 'pending')); ?></span>
        </div>
        <div class="mt-3 text-base font-extrabold text-slate-900 leading-6 break-words"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></div>
        <?php if (isset($item['project_name']) && trim((string)$item['project_name']) !== ''): ?>
            <div class="mt-2 text-sm text-slate-600">현장명: <?php echo h($item['project_name']); ?></div>
        <?php endif; ?>
        <?php if (!$isConstructionSchedule): ?>
            <div class="mt-2 text-sm text-slate-600">요청자: <?php echo h(isset($item['requester_name']) ? $item['requester_name'] : '-'); ?></div>
            <div class="mt-1 text-sm text-slate-500"><?php echo h($isMeetingTask ? '일시' : '마감'); ?>: <?php echo h(cpms_mobile_task_due_text($item)); ?></div>
        <?php else: ?>
            <div class="mt-2 text-sm text-slate-500">공정일: <?php echo h(cpms_mobile_task_due_text($item)); ?></div>
        <?php endif; ?>
        <div class="mt-4 flex flex-wrap gap-2">
            <?php if ($isDirectTask): ?>
                <button type="button" data-task-detail-open data-task-id="<?php echo (int)$item['source_id']; ?>" class="cpms-mobile-task-button border border-gray-200 bg-white text-slate-700">확인</button>
            <?php else: ?>
                <a href="<?php echo h($detailUrl); ?>" class="cpms-mobile-task-button border border-gray-200 bg-white text-slate-700">확인</a>
            <?php endif; ?>
            <?php if ($canStartTask): ?>
                <form method="post" action="?r=task_progress" class="inline-flex" referrerpolicy="origin">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$item['source_id']; ?>">
                    <input type="hidden" name="task_state" value="progress">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="cpms-mobile-task-button bg-blue-600 text-white">대기</button>
                </form>
            <?php endif; ?>
            <?php if ($canRespondMeeting): ?>
                <form method="post" action="?r=tasks/meeting_response" class="inline-flex">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$item['source_id']; ?>">
                    <input type="hidden" name="response" value="available">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="cpms-mobile-task-button bg-emerald-600 text-white">참석가능</button>
                </form>
                <button type="button" data-meeting-unavailable-open data-task-id="<?php echo (int)$item['source_id']; ?>" class="cpms-mobile-task-button bg-rose-600 text-white">참석불가</button>
            <?php endif; ?>
            <?php if ($canCompleteTask): ?>
                <button type="button" data-task-complete-open data-task-id="<?php echo (int)$item['source_id']; ?>" class="cpms-mobile-task-button bg-slate-900 text-white">완료</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
}}

if (!function_exists('cpms_render_task_request_modals')) {
function cpms_render_task_request_modals($pdo, $returnUrl)
{
    $currentEmployee = cpms_tasks_current_employee($pdo);
    $employees = cpms_tasks_fetch_active_employees($pdo);
    $projects = cpms_tasks_fetch_projects($pdo);
    $currentLeaveIndex = function_exists('approval_current_leave_index') ? approval_current_leave_index($pdo, cpms_tasks_today()) : array('by_id' => array(), 'by_email' => array(), 'by_name' => array(), 'people' => array());
    $returnUrl = trim((string)$returnUrl) !== '' ? (string)$returnUrl : cpms_tasks_default_return_url();
    ?>
    <div id="modal-taskCreate" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="taskCreate"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="text-2xl font-extrabold text-gray-900">업무 요청</div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="taskCreate">닫기</button>
                </div>
                <form method="post" action="?r=tasks/create" enctype="multipart/form-data" class="p-6 space-y-5">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <input type="hidden" name="task_kind" value="task">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">업무 제목</div>
                            <input type="text" name="title" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">업무 내용</div>
                            <textarea name="content" rows="4" class="w-full px-4 py-3 rounded-2xl border border-gray-200"></textarea>
                        </div>
                        <?php if (isset($currentEmployee['id']) && (int)$currentEmployee['id'] > 0): ?>
                            <div class="md:col-span-2">
                                <label class="inline-flex items-center gap-3 px-4 py-3 rounded-2xl bg-blue-50 border border-blue-200 text-blue-700 font-bold">
                                    <input type="checkbox" name="assignee_employee_id" value="<?php echo (int)$currentEmployee['id']; ?>" class="w-4 h-4">
                                    나에게
                                </label>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">담당자</div>
                            <select name="assignee_employee_ids[]" multiple size="8" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <?php cpms_render_task_assignee_options($employees, $currentLeaveIndex); ?>
                            </select>
                            <div class="text-xs text-gray-500 mt-1">여러 명 선택 시 Ctrl 키를 누른 상태에서 선택하세요.</div>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">관련 현장</div>
                            <select name="project_id" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <option value="0">선택 안함</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo (int)$project['id']; ?>"><?php echo h(isset($project['name']) ? $project['name'] : '-'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">관련 부서</div>
                            <select name="department" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <option value="">담당자 부서 사용</option>
                                <?php foreach (cpms_tasks_department_options() as $department): ?>
                                    <option value="<?php echo h($department); ?>"><?php echo h($department); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">마감일</div>
                            <input type="date" name="due_date" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">마감시간</div>
                            <input type="time" name="due_time" value="18:00" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-3 px-4 py-3 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 font-bold">
                                <input type="checkbox" name="is_urgent" class="w-4 h-4">
                                긴급 요청
                            </label>
                        </div>
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">첨부파일</div>
                            <input type="file" name="attachments[]" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-3 rounded-2xl border border-gray-200 font-bold" data-modal-close="taskCreate">취소</button>
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">업무 요청 등록</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="modal-meetingCreate" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="meetingCreate"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="text-2xl font-extrabold text-gray-900">회의 요청</div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="meetingCreate">닫기</button>
                </div>
                <form method="post" action="?r=tasks/create" enctype="multipart/form-data" class="p-6 space-y-5">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <input type="hidden" name="task_kind" value="meeting">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">회의 제목</div>
                            <input type="text" name="title" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">회의 내용</div>
                            <textarea name="content" rows="4" class="w-full px-4 py-3 rounded-2xl border border-gray-200"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">참석자</div>
                            <select name="assignee_employee_ids[]" required multiple size="8" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <?php cpms_render_task_assignee_options($employees, $currentLeaveIndex); ?>
                            </select>
                            <div class="text-xs text-gray-500 mt-1">회의 요청자는 자동으로 참석자에 포함됩니다.</div>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">회의 일자</div>
                            <input type="date" name="meeting_date" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">회의 시간</div>
                            <input type="time" name="meeting_time" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">관련 현장</div>
                            <select name="project_id" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <option value="0">선택 안함</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo (int)$project['id']; ?>"><?php echo h(isset($project['name']) ? $project['name'] : '-'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">첨부파일</div>
                            <input type="file" name="attachments[]" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-3 rounded-2xl border border-gray-200 font-bold" data-modal-close="meetingCreate">취소</button>
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold">회의 요청 등록</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}}

if (!function_exists('cpms_render_employee_task_dashboard')) {
function cpms_render_employee_task_dashboard($pdo)
{
    $currentEmployee = cpms_tasks_current_employee($pdo);
    if ((int)$currentEmployee['id'] <= 0) return;

    $feed = cpms_task_feed_for_employee($pdo, (int)$currentEmployee['id'], isset($currentEmployee['email']) ? $currentEmployee['email'] : '', $currentEmployee);
    $requestedTaskDate = isset($_GET['requested_task_date']) ? trim((string)$_GET['requested_task_date']) : cpms_tasks_today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedTaskDate)) $requestedTaskDate = cpms_tasks_today();
    $requested = cpms_task_feed_direct_tasks_requested_by_employee($pdo, (int)$currentEmployee['id'], $requestedTaskDate);
    $employees = cpms_tasks_fetch_active_employees($pdo);
    $projects = cpms_tasks_fetch_projects($pdo);
    $returnUrl = cpms_tasks_default_return_url();
    $currentLeaveIndex = function_exists('approval_current_leave_index') ? approval_current_leave_index($pdo, cpms_tasks_today()) : array('by_id' => array(), 'by_email' => array(), 'by_name' => array(), 'people' => array());

    $summary = array(
        'all' => count($feed),
        'urgent' => 0,
        'today' => 0,
        'progress' => 0,
        'delayed' => 0,
        'approval' => 0,
    );
    $urgentItems = array();
    $todayItems = array();
    $progressItems = array();
    $approvalItems = array();
    $delayedItems = array();

    foreach ($feed as $item) {
        if (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1) {
            $summary['urgent']++;
            $urgentItems[count($urgentItems)] = $item;
        }
        if (cpms_task_feed_counts_as_today($item)) {
            $summary['today']++;
            $todayItems[count($todayItems)] = $item;
        }
        if (isset($item['status']) && in_array((string)$item['status'], array('progress', 'revision'), true)) {
            $summary['progress']++;
            $progressItems[count($progressItems)] = $item;
        }
        if (cpms_tasks_is_delayed($item)) {
            $summary['delayed']++;
            $delayedItems[count($delayedItems)] = $item;
        }
        if (isset($item['source_type']) && in_array((string)$item['source_type'], array('approval', 'labor_gongsu', 'equipment_gongsu', 'attendance'), true)) {
            $summary['approval']++;
            $approvalItems[count($approvalItems)] = $item;
        }
    }
    $mobileTodayLimit = 4;
    $mobileTodayItems = array_slice($todayItems, 0, $mobileTodayLimit);
    ?>
    <style>
    .cpms-mobile-task-actions,
    .cpms-mobile-task-today {
        display: none;
    }
    @media (max-width: 767px) {
        #cpmsEmployeeTasksPanel {
            padding: 16px !important;
            margin-bottom: 18px !important;
            border-radius: 20px !important;
        }
        #cpmsEmployeeTasksPanel .cpms-mobile-task-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin-top: 14px;
        }
        #cpmsEmployeeTasksPanel .cpms-mobile-task-action {
            min-height: 54px;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-size: 12px;
            font-weight: 800;
            line-height: 1.15;
            white-space: normal;
        }
        #cpmsEmployeeTasksPanel .cpms-mobile-task-action i {
            width: 18px;
            height: 18px;
        }
        #cpmsEmployeeTasksPanel .cpms-mobile-task-today {
            display: block;
            margin-top: 14px;
        }
        .cpms-mobile-task-list-button {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            text-align: left;
        }
        .cpms-mobile-task-list-main {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .cpms-mobile-task-list-title {
            color: #111827;
            font-size: 14px;
            font-weight: 800;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .cpms-mobile-task-list-meta {
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
        }
        .cpms-mobile-task-list-status,
        .cpms-mobile-task-status {
            flex: 0 0 auto;
            border-width: 1px;
            border-style: solid;
            border-radius: 999px;
            padding: 5px 8px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }
        #modal-mobileTasks .cpms-mobile-task-modal-shell {
            align-items: flex-end;
            padding: 0;
        }
        #modal-mobileTasks .cpms-mobile-task-modal-panel {
            width: 100%;
            max-height: 88vh;
            overflow-y: auto;
            border-radius: 20px 20px 0 0;
            background: #fff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 -18px 60px rgba(15, 23, 42, .22);
        }
        #modal-mobileTasks .cpms-mobile-task-card {
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #f8fafc;
        }
        #modal-mobileTasks .cpms-mobile-task-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 5px 8px;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 11px;
            font-weight: 800;
        }
        #modal-mobileTasks .cpms-mobile-task-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border-radius: 12px;
            padding: 9px 12px;
            font-size: 13px;
            font-weight: 800;
            line-height: 1.1;
        }
    }
    @media (max-width: 380px) {
        #cpmsEmployeeTasksPanel .cpms-mobile-task-actions {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (min-width: 768px) {
        #modal-mobileTasks {
            display: none !important;
        }
    }
    </style>
    <div id="cpmsEmployeeTasksPanel" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
        <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-2xl font-extrabold text-gray-900">나의 할일</h2>
                    <button type="button" id="cpmsEmployeeTasksToggle" class="px-3 py-2 rounded-2xl bg-white border border-gray-200 text-sm font-bold text-gray-700">숨기기 ▲</button>
                </div>
                <div data-cpms-employee-task-body class="cpms-task-summary">
                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                    <span class="px-3 py-2 rounded-full bg-slate-100 text-slate-700 font-bold">전체 <?php echo (int)$summary['all']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-rose-50 text-rose-700 font-bold">긴급 <?php echo (int)$summary['urgent']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-amber-50 text-amber-700 font-bold">오늘 할일 <?php echo (int)$summary['today']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-blue-50 text-blue-700 font-bold">진행중 <?php echo (int)$summary['progress']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-red-50 text-red-700 font-bold">지연 <?php echo (int)$summary['delayed']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-indigo-50 text-indigo-700 font-bold">승인대기 <?php echo (int)$summary['approval']; ?>건</span>
                </div>
                </div>
                <div class="cpms-mobile-task-actions">
                    <button type="button" data-modal-open="taskCreate" class="cpms-mobile-task-action bg-gray-900 text-white">
                        <i data-lucide="send"></i>
                        <span>업무요청</span>
                    </button>
                    <button type="button" data-modal-open="meetingCreate" class="cpms-mobile-task-action bg-blue-600 text-white">
                        <i data-lucide="calendar-plus"></i>
                        <span>회의요청</span>
                    </button>
                    <button type="button" data-modal-open="mobileTasks" class="cpms-mobile-task-action bg-white border border-gray-200 text-gray-800">
                        <i data-lucide="list-checks"></i>
                        <span>나의 할일</span>
                    </button>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="?r=tasks/my_list" class="px-4 py-3 rounded-2xl bg-white border border-gray-200 text-gray-700 font-bold">전체 보기</a>
                <button type="button" data-modal-open="meetingCreate" class="px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold shadow-lg">회의 요청</button>
                <button type="button" data-modal-open="taskCreate" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold shadow-lg">업무 요청</button>
            </div>
        </div>

        <div class="cpms-mobile-task-today">
            <div class="flex items-center justify-between gap-2 mb-2">
                <div class="text-sm font-extrabold text-gray-900">오늘 할일</div>
                <button type="button" data-modal-open="mobileTasks" class="text-xs font-extrabold text-blue-700">전체 보기</button>
            </div>
            <?php if (count($mobileTodayItems) === 0): ?>
                <button type="button" data-modal-open="mobileTasks" class="cpms-mobile-task-list-button">
                    <span class="cpms-mobile-task-list-main">
                        <span class="cpms-mobile-task-list-title">오늘 할일이 없습니다.</span>
                        <span class="cpms-mobile-task-list-meta">나의 할일을 눌러 전체 요청을 확인하세요.</span>
                    </span>
                </button>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($mobileTodayItems as $mobileTodayItem): ?>
                        <?php cpms_render_mobile_today_task_row($mobileTodayItem); ?>
                    <?php endforeach; ?>
                </div>
                <?php if (count($todayItems) > $mobileTodayLimit): ?>
                    <button type="button" data-modal-open="mobileTasks" class="mt-2 w-full py-2 rounded-xl bg-slate-100 text-slate-700 text-sm font-extrabold">
                        외 <?php echo (int)(count($todayItems) - $mobileTodayLimit); ?>건 더 보기
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div data-cpms-employee-task-body class="mt-6 space-y-5">
            <?php cpms_render_feed_lane('긴급', '', 'bg-rose-50 text-rose-700', $urgentItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <?php cpms_render_feed_lane('오늘 할일', '', 'bg-amber-50 text-amber-700', $todayItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <?php cpms_render_feed_lane('진행중', '', 'bg-blue-50 text-blue-700', $progressItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <?php cpms_render_feed_lane('전자결재/승인', '', 'bg-indigo-50 text-indigo-700', $approvalItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <?php cpms_render_feed_lane('지연', '', 'bg-red-50 text-red-700', $delayedItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <div class="rounded-3xl border border-gray-200 bg-white p-5">
                <form method="get" action="" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="r" value="대시보드">
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-1">내가 요청한 업무 일자</div>
                        <input type="date" name="requested_task_date" value="<?php echo h($requestedTaskDate); ?>" class="px-4 py-3 rounded-2xl border border-gray-200">
                    </div>
                    <button type="submit" class="px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">조회</button>
                </form>
            </div>
            <?php cpms_render_feed_lane('내가 요청한 업무', '', 'bg-slate-100 text-slate-700', $requested, (int)$currentEmployee['id'], $returnUrl, true); ?>
        </div>
    </div>

    <div id="modal-mobileTasks" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="mobileTasks"></div>
        <div class="absolute inset-0 flex cpms-mobile-task-modal-shell">
            <div class="cpms-mobile-task-modal-panel">
                <div class="sticky top-0 z-10 flex items-center justify-between gap-3 px-5 py-4 bg-white border-b border-gray-100">
                    <div>
                        <div class="text-xl font-extrabold text-gray-900">나의 할일</div>
                        <div class="text-xs font-bold text-gray-500 mt-1">오늘 <?php echo (int)$summary['today']; ?>건 · 전체 <?php echo (int)$summary['all']; ?>건</div>
                    </div>
                    <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-extrabold text-gray-700" data-modal-close="mobileTasks">닫기</button>
                </div>
                <div class="p-4 space-y-3">
                    <?php if (count($feed) === 0): ?>
                        <div class="p-5 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500">표시할 할일이 없습니다.</div>
                    <?php else: ?>
                        <?php foreach ($feed as $mobileTaskItem): ?>
                            <?php cpms_render_mobile_task_card($mobileTaskItem, (int)$currentEmployee['id'], $returnUrl); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var key = 'cpms_employee_tasks_collapsed';
        var toggle = document.getElementById('cpmsEmployeeTasksToggle');
        var bodies = document.querySelectorAll('[data-cpms-employee-task-body]');
        if (!toggle || !bodies || bodies.length === 0) return;
        function readState() {
            try { return window.localStorage && localStorage.getItem(key) === '1'; } catch (e) { return false; }
        }
        function saveState(collapsed) {
            try { if (window.localStorage) localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {}
        }
        function applyState(collapsed) {
            for (var i = 0; i < bodies.length; i++) {
                if (collapsed) bodies[i].classList.add('hidden');
                else bodies[i].classList.remove('hidden');
            }
            toggle.textContent = collapsed ? '보기 ▼' : '숨기기 ▲';
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
        var collapsed = readState();
        applyState(collapsed);
        toggle.addEventListener('click', function(){
            collapsed = !collapsed;
            applyState(collapsed);
            saveState(collapsed);
        });
    })();
    (function(){
        var toggles = document.querySelectorAll('[data-cpms-employee-toggle]');
        var closeButtons = document.querySelectorAll('[data-cpms-employee-close]');
        function closeAll(exceptId) {
            var panels = document.querySelectorAll('[data-cpms-employee-panel]');
            for (var i = 0; i < panels.length; i++) {
                if (exceptId && panels[i].id === 'panel-' + exceptId) continue;
                panels[i].classList.add('hidden');
            }
        }
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].addEventListener('click', function(){
                var key = this.getAttribute('data-cpms-employee-toggle');
                var panel = document.getElementById('panel-' + key);
                if (!panel) return;
                var willOpen = panel.classList.contains('hidden');
                closeAll(willOpen ? key : null);
                if (willOpen) panel.classList.remove('hidden');
                else panel.classList.add('hidden');
            });
        }
        for (var j = 0; j < closeButtons.length; j++) {
            closeButtons[j].addEventListener('click', function(e){
                e.preventDefault();
                var key = this.getAttribute('data-cpms-employee-close');
                var panel = document.getElementById('panel-' + key);
                if (panel) panel.classList.add('hidden');
            });
        }
    })();
    </script>

    <div id="modal-taskCreate" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="taskCreate"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div>
                        <div class="text-2xl font-extrabold text-gray-900">업무 요청</div>
                    </div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="taskCreate">닫기</button>
                </div>
                <form method="post" action="?r=tasks/create" enctype="multipart/form-data" class="p-6 space-y-5">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <input type="hidden" name="task_kind" value="task">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">업무 제목</div>
                            <input type="text" name="title" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">업무 내용</div>
                            <textarea name="content" rows="4" class="w-full px-4 py-3 rounded-2xl border border-gray-200"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-3 px-4 py-3 rounded-2xl bg-blue-50 border border-blue-200 text-blue-700 font-bold">
                                <input type="checkbox" name="assignee_employee_id" id="taskAssignToMeToggle" value="<?php echo (int)$currentEmployee['id']; ?>" class="w-4 h-4">
                                나에게
                            </label>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">담당자 검색</div>
                            <input type="text" id="taskAssigneeSearch" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="이름 / 부서 / 직책 검색">
                            <div id="taskAssigneeSelected" class="mt-2 flex flex-wrap gap-2 text-sm"></div>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">담당자</div>
                            <select name="assignee_employee_ids[]" id="taskAssigneeSelect" multiple size="8" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <?php cpms_render_task_assignee_options($employees, $currentLeaveIndex); ?>
                            </select>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">관련 현장</div>
                            <select name="project_id" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <option value="0">선택 안함</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo (int)$project['id']; ?>"><?php echo h(isset($project['name']) ? $project['name'] : '-'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">관련 부서</div>
                            <select name="department" id="taskDepartmentSelect" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <option value="">담당자 부서 사용</option>
                                <?php foreach (cpms_tasks_department_options() as $department): ?>
                                    <option value="<?php echo h($department); ?>"><?php echo h($department); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">마감일</div>
                            <input type="date" name="due_date" id="taskDueDate" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">마감시간</div>
                            <input type="time" name="due_time" id="taskDueTime" value="18:00" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-3 px-4 py-3 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 font-bold">
                                <input type="checkbox" name="is_urgent" id="taskUrgentToggle" class="w-4 h-4">
                                긴급 요청
                            </label>
                        </div>
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">첨부파일</div>
                            <input type="file" name="attachments[]" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-3 rounded-2xl border border-gray-200 font-bold" data-modal-close="taskCreate">취소</button>
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">업무 요청 등록</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="modal-meetingCreate" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="meetingCreate"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div>
                        <div class="text-2xl font-extrabold text-gray-900">회의 요청</div>
                    </div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="meetingCreate">닫기</button>
                </div>
                <form method="post" action="?r=tasks/create" enctype="multipart/form-data" class="p-6 space-y-5">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <input type="hidden" name="task_kind" value="meeting">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">회의 제목</div>
                            <input type="text" name="title" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">회의 내용</div>
                            <textarea name="content" rows="4" class="w-full px-4 py-3 rounded-2xl border border-gray-200"></textarea>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">참석자 검색</div>
                            <input type="text" id="meetingAssigneeSearch" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="이름 / 부서 / 직책 검색">
                            <div id="meetingAssigneeSelected" class="mt-2 flex flex-wrap gap-2 text-sm"></div>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">참석자</div>
                            <select name="assignee_employee_ids[]" id="meetingAssigneeSelect" required multiple size="8" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <?php cpms_render_task_assignee_options($employees, $currentLeaveIndex); ?>
                            </select>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">회의 일자</div>
                            <input type="date" name="meeting_date" id="meetingDate" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">회의 시간</div>
                            <input type="time" name="meeting_time" id="meetingTime" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">관련 현장</div>
                            <select name="project_id" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <option value="0">선택 안함</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo (int)$project['id']; ?>"><?php echo h(isset($project['name']) ? $project['name'] : '-'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">첨부파일</div>
                            <input type="file" name="attachments[]" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-3 rounded-2xl border border-gray-200 font-bold" data-modal-close="meetingCreate">취소</button>
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold">회의 요청 등록</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="modal-taskDetail" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="taskDetail"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-4xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="text-2xl font-extrabold text-gray-900">업무 상세</div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="taskDetail">닫기</button>
                </div>
                <div id="taskDetailBody" class="p-6 overflow-y-auto max-h-[74vh]">
                    <div class="text-sm text-gray-500">업무 정보를 불러오는 중입니다.</div>
                </div>
            </div>
        </div>
    </div>

    <div id="modal-taskComplete" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="taskComplete"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-3xl bg-white shadow-2xl border border-gray-100 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="text-2xl font-extrabold text-gray-900">완료 처리</div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="taskComplete">닫기</button>
                </div>
                <form method="post" action="?r=tasks/complete" enctype="multipart/form-data" class="p-6 space-y-4">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" id="taskCompleteTaskId" value="">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-1">완료 메모</div>
                        <textarea name="completed_memo" rows="4" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="처리 내용을 남겨주세요."></textarea>
                    </div>
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-1">첨부파일</div>
                        <input type="file" name="attachments[]" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-3 rounded-2xl border border-gray-200 font-bold" data-modal-close="taskComplete">취소</button>
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-emerald-600 text-white font-extrabold">완료 처리</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="modal-meetingUnavailable" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="meetingUnavailable"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-3xl bg-white shadow-2xl border border-gray-100 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="text-2xl font-extrabold text-gray-900">참석불가능</div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="meetingUnavailable">닫기</button>
                </div>
                <form method="post" action="?r=tasks/meeting_response" class="p-6 space-y-4">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" id="meetingUnavailableTaskId" value="">
                    <input type="hidden" name="response" value="unavailable">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-1">사유</div>
                        <textarea name="reason" rows="4" required class="w-full px-4 py-3 rounded-2xl border border-gray-200"></textarea>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-3 rounded-2xl border border-gray-200 font-bold" data-modal-close="meetingUnavailable">취소</button>
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-rose-600 text-white font-extrabold">저장</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="modal-taskRevision" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="taskRevision"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-3xl bg-white shadow-2xl border border-gray-100 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="text-2xl font-extrabold text-gray-900">보완요청</div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="taskRevision">닫기</button>
                </div>
                <form method="post" action="?r=tasks/revision" class="p-6 space-y-4">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" id="taskRevisionTaskId" value="">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-1">보완 요청 내용</div>
                        <textarea name="revision_message" rows="4" required class="w-full px-4 py-3 rounded-2xl border border-gray-200"></textarea>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">재마감일</div>
                            <input type="date" name="due_date" id="taskRevisionDueDate" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">재마감시간</div>
                            <input type="time" name="due_time" id="taskRevisionDueTime" value="18:00" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-3 rounded-2xl border border-gray-200 font-bold" data-modal-close="taskRevision">취소</button>
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-amber-500 text-white font-extrabold">보완 요청 저장</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var taskDueDate = document.getElementById('taskDueDate');
        var taskDueTime = document.getElementById('taskDueTime');
        var taskUrgentToggle = document.getElementById('taskUrgentToggle');
        var assigneeSearch = document.getElementById('taskAssigneeSearch');
        var assigneeSelect = document.getElementById('taskAssigneeSelect');
        var meetingAssigneeSearch = document.getElementById('meetingAssigneeSearch');
        var meetingAssigneeSelect = document.getElementById('meetingAssigneeSelect');
        var assigneeSelected = document.getElementById('taskAssigneeSelected');
        var meetingAssigneeSelected = document.getElementById('meetingAssigneeSelected');
        var assignToMeToggle = document.getElementById('taskAssignToMeToggle');
        var departmentSelect = document.getElementById('taskDepartmentSelect');
        var onLeaveMessage = <?php echo json_encode(approval_ko('%EC%84%A0%ED%83%9D%ED%95%9C%20%EB%8B%B4%EB%8B%B9%EC%9E%90%EB%8A%94%20%ED%98%84%EC%9E%AC%20%ED%9C%B4%EA%B0%80%EC%A4%91%EC%9D%B4%EB%AF%80%EB%A1%9C%20%EC%97%85%EB%AC%B4%EC%9A%94%EC%B2%AD%EC%9D%84%20%ED%95%A0%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?>;
        var taskDetailBody = document.getElementById('taskDetailBody');
        var completeTaskId = document.getElementById('taskCompleteTaskId');
        var meetingUnavailableTaskId = document.getElementById('meetingUnavailableTaskId');
        var revisionTaskId = document.getElementById('taskRevisionTaskId');
        var revisionDueDate = document.getElementById('taskRevisionDueDate');
        var revisionDueTime = document.getElementById('taskRevisionDueTime');

        function todayString() {
            var now = new Date();
            var month = (now.getMonth() + 1).toString();
            var day = now.getDate().toString();
            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;
            return now.getFullYear() + '-' + month + '-' + day;
        }

        if (taskUrgentToggle) {
            taskUrgentToggle.addEventListener('change', function(){
                if (!taskUrgentToggle.checked) return;
                if (taskDueDate) taskDueDate.value = todayString();
                if (taskDueTime && !taskDueTime.value) taskDueTime.value = '18:00';
            });
        }

        function selectedAssigneeOptions(select) {
            var selected = [];
            if (!select || !select.options) return selected;
            for (var i = 0; i < select.options.length; i++) {
                if (select.options[i].selected && select.options[i].value) {
                    selected[selected.length] = select.options[i];
                }
            }
            return selected;
        }

        function assigneeOptionLabel(option) {
            if (!option) return '';
            return (option.text || '').replace(/^\s+|\s+$/g, '');
        }

        function dispatchAssigneeChange(select) {
            var eventObj = document.createEvent('HTMLEvents');
            eventObj.initEvent('change', true, false);
            select.dispatchEvent(eventObj);
        }

        function findAssigneeOptionByValue(select, value) {
            if (!select || !select.options) return null;
            value = String(value);
            for (var i = 0; i < select.options.length; i++) {
                if (String(select.options[i].value) === value) return select.options[i];
            }
            return null;
        }

        function applyAssigneeSearchFilter(searchInput, select) {
            if (!select || !select.options) return;
            var keyword = searchInput ? searchInput.value.replace(/^\s+|\s+$/g, '').toLowerCase() : '';
            var options = select.options;
            for (var i = 0; i < options.length; i++) {
                var option = options[i];
                if (!option.value) continue;
                var matched = keyword === '' || option.text.toLowerCase().indexOf(keyword) >= 0;
                option.hidden = !(matched || option.selected);
            }
        }

        function renderSelectedAssignees(select, selectedWrap, emptyText) {
            if (!selectedWrap) return;
            selectedWrap.innerHTML = '';
            var selected = selectedAssigneeOptions(select);
            if (selected.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'w-full px-3 py-2 rounded-xl bg-gray-50 border border-dashed border-gray-200 text-gray-500';
                empty.textContent = emptyText || '선택된 담당자가 없습니다.';
                selectedWrap.appendChild(empty);
                return;
            }
            for (var i = 0; i < selected.length; i++) {
                var chip = document.createElement('span');
                chip.className = 'inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-blue-50 border border-blue-200 text-blue-800 font-bold';

                var label = document.createElement('span');
                label.textContent = assigneeOptionLabel(selected[i]);
                chip.appendChild(label);

                var removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'px-2 py-1 rounded-lg bg-white border border-blue-200 text-blue-700';
                removeButton.setAttribute('data-assignee-remove', selected[i].value);
                removeButton.textContent = '삭제';
                chip.appendChild(removeButton);

                selectedWrap.appendChild(chip);
            }
        }

        function setupAssigneePicker(searchInput, select, targetDepartmentSelect, emptyMessage, selectedWrap, selectedEmptyText, fallbackCheckbox) {
            if (!select) return;
            if (searchInput) {
                searchInput.addEventListener('input', function(){
                    applyAssigneeSearchFilter(searchInput, select);
                    renderSelectedAssignees(select, selectedWrap, selectedEmptyText);
                });
            }

            select.addEventListener('mousedown', function(e){
                var option = e.target && e.target.tagName === 'OPTION' ? e.target : null;
                if (!option || !option.value || option.disabled) return;
                e.preventDefault();
                select.focus();
                option.selected = !option.selected;
                dispatchAssigneeChange(select);
            });

            select.addEventListener('change', function(){
                var selected = selectedAssigneeOptions(select);
                var firstAvailable = null;
                for (var i = 0; i < selected.length; i++) {
                    if (selected[i].getAttribute('data-on-leave') === '1') {
                        alert(onLeaveMessage);
                        selected[i].selected = false;
                        continue;
                    }
                    if (!firstAvailable) firstAvailable = selected[i];
                }
                if (targetDepartmentSelect && targetDepartmentSelect.value === '' && firstAvailable) {
                    var dept = firstAvailable.getAttribute('data-department') || '';
                    targetDepartmentSelect.value = dept;
                }
                applyAssigneeSearchFilter(searchInput, select);
                renderSelectedAssignees(select, selectedWrap, selectedEmptyText);
            });

            if (selectedWrap) {
                selectedWrap.addEventListener('click', function(e){
                    var removeButton = e.target && e.target.closest ? e.target.closest('[data-assignee-remove]') : null;
                    if (!removeButton) return;
                    e.preventDefault();
                    var removeValue = removeButton.getAttribute('data-assignee-remove');
                    for (var i = 0; i < select.options.length; i++) {
                        if (select.options[i].value === removeValue) {
                            select.options[i].selected = false;
                            break;
                        }
                    }
                    if (fallbackCheckbox && String(removeValue) === String(fallbackCheckbox.value)) {
                        fallbackCheckbox.checked = false;
                    }
                    dispatchAssigneeChange(select);
                });
            }

            if (select.form) {
                select.form.addEventListener('submit', function(e){
                    var selected = selectedAssigneeOptions(select);
                    var hasFallback = fallbackCheckbox && fallbackCheckbox.checked && fallbackCheckbox.value;
                    if (selected.length === 0 && !hasFallback) {
                        e.preventDefault();
                        alert(emptyMessage || '담당자를 선택해주세요.');
                        return;
                    }
                    for (var i = 0; i < selected.length; i++) {
                        if (selected[i].getAttribute('data-on-leave') === '1') {
                            e.preventDefault();
                            alert(onLeaveMessage);
                            selected[i].selected = false;
                            return;
                        }
                    }
                });
            }
            applyAssigneeSearchFilter(searchInput, select);
            renderSelectedAssignees(select, selectedWrap, selectedEmptyText);
        }

        if (assignToMeToggle && assigneeSelect) {
            assignToMeToggle.addEventListener('change', function(){
                var myOption = findAssigneeOptionByValue(assigneeSelect, assignToMeToggle.value);
                if (myOption && !myOption.disabled) {
                    if (assignToMeToggle.checked) {
                        myOption.selected = true;
                        myOption.setAttribute('data-selected-by-me', '1');
                    } else if (myOption.getAttribute('data-selected-by-me') === '1') {
                        myOption.selected = false;
                        myOption.removeAttribute('data-selected-by-me');
                    }
                    dispatchAssigneeChange(assigneeSelect);
                }
            });
        }

        setupAssigneePicker(assigneeSearch, assigneeSelect, departmentSelect, '담당자를 선택해주세요.', assigneeSelected, '선택된 담당자가 없습니다.', assignToMeToggle);
        setupAssigneePicker(meetingAssigneeSearch, meetingAssigneeSelect, null, '참석자를 선택해주세요.', meetingAssigneeSelected, '선택된 참석자가 없습니다.', null);

        function openCompleteModal(taskId) {
            if (completeTaskId) completeTaskId.value = taskId;
            var modal = document.getElementById('modal-taskComplete');
            if (modal) modal.classList.remove('hidden');
        }

        function openMeetingUnavailableModal(taskId) {
            if (meetingUnavailableTaskId) meetingUnavailableTaskId.value = taskId;
            var modal = document.getElementById('modal-meetingUnavailable');
            if (modal) {
                var reason = modal.querySelector ? modal.querySelector('textarea[name="reason"]') : null;
                if (reason) reason.value = '';
                modal.classList.remove('hidden');
            }
        }

        function openRevisionModal(taskId, dueDate, dueTime) {
            if (revisionTaskId) revisionTaskId.value = taskId;
            if (revisionDueDate) revisionDueDate.value = dueDate || '';
            if (revisionDueTime) revisionDueTime.value = dueTime || '18:00';
            var modal = document.getElementById('modal-taskRevision');
            if (modal) modal.classList.remove('hidden');
        }

        document.addEventListener('click', function(e){
            var detailButton = e.target && e.target.closest ? e.target.closest('[data-task-detail-open]') : null;
            if (detailButton) {
                e.preventDefault();
                var taskId = detailButton.getAttribute('data-task-id');
                var detailModal = document.getElementById('modal-taskDetail');
                if (taskDetailBody) taskDetailBody.innerHTML = '<div class="text-sm text-gray-500">업무 정보를 불러오는 중입니다.</div>';
                if (detailModal) detailModal.classList.remove('hidden');
                var xhr = new XMLHttpRequest();
                var detailUrl = '?r=tasks/detail&id=' + encodeURIComponent(taskId) + '&modal=1';
                if (detailButton.getAttribute('data-task-readonly') === '1') detailUrl += '&readonly=1';
                detailUrl += '&return_url=' + encodeURIComponent(window.location.pathname + window.location.search);
                xhr.open('GET', detailUrl, true);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState !== 4) return;
                    if (!taskDetailBody) return;
                    if (xhr.status >= 200 && xhr.status < 300) taskDetailBody.innerHTML = xhr.responseText;
                    else taskDetailBody.innerHTML = '<div class="text-sm text-red-600">업무 정보를 불러오지 못했습니다.</div>';
                    if (window.lucide) { try { lucide.createIcons(); } catch (err) {} }
                };
                xhr.send(null);
                return;
            }

            var completeButton = e.target && e.target.closest ? e.target.closest('[data-task-complete-open]') : null;
            if (completeButton) {
                e.preventDefault();
                openCompleteModal(completeButton.getAttribute('data-task-id'));
                return;
            }

            var meetingUnavailableButton = e.target && e.target.closest ? e.target.closest('[data-meeting-unavailable-open]') : null;
            if (meetingUnavailableButton) {
                e.preventDefault();
                openMeetingUnavailableModal(meetingUnavailableButton.getAttribute('data-task-id'));
                return;
            }

            var revisionButton = e.target && e.target.closest ? e.target.closest('[data-task-revision-open]') : null;
            if (revisionButton) {
                e.preventDefault();
                openRevisionModal(
                    revisionButton.getAttribute('data-task-id'),
                    revisionButton.getAttribute('data-task-due-date'),
                    revisionButton.getAttribute('data-task-due-time')
                );
                return;
            }
        });
    })();
    </script>
    <?php
}}

if (!function_exists('cpms_render_executive_task_dashboard')) {
function cpms_render_executive_task_dashboard($pdo)
{
    if (!$pdo || !(App\Core\Auth::isMaster() || App\Core\Auth::userRole() === 'executive' || App\Core\Auth::canManageEmployees())) return;
    $selectedDepartment = isset($_GET['task_department']) ? trim((string)$_GET['task_department']) : '전체';
    if ($selectedDepartment === '') $selectedDepartment = '전체';
    $summaryData = cpms_task_feed_for_executive($pdo, array('department' => $selectedDepartment));
    $currentLeaveIndex = function_exists('approval_current_leave_index') ? approval_current_leave_index($pdo, cpms_tasks_today()) : array('by_id' => array(), 'by_email' => array(), 'by_name' => array(), 'people' => array());
    $departmentOptions = array_merge(array('전체'), cpms_tasks_department_options());
    $executiveSummaryCards = array(
        'today' => array('label' => '오늘 할일', 'card_class' => 'bg-slate-50 border-slate-200 hover:border-slate-300', 'label_class' => 'text-slate-500', 'count_class' => 'text-slate-900', 'items' => array()),
        'urgent' => array('label' => '긴급 요청', 'card_class' => 'bg-rose-50 border-rose-200 hover:border-rose-300', 'label_class' => 'text-rose-500', 'count_class' => 'text-rose-700', 'items' => array()),
        'due_soon' => array('label' => '마감 임박', 'card_class' => 'bg-amber-50 border-amber-200 hover:border-amber-300', 'label_class' => 'text-amber-500', 'count_class' => 'text-amber-700', 'items' => array()),
        'delayed' => array('label' => '지연 업무', 'card_class' => 'bg-red-50 border-red-200 hover:border-red-300', 'label_class' => 'text-red-500', 'count_class' => 'text-red-700', 'items' => array()),
        'done' => array('label' => '완료', 'card_class' => 'bg-emerald-50 border-emerald-200 hover:border-emerald-300', 'label_class' => 'text-emerald-500', 'count_class' => 'text-emerald-700', 'items' => array()),
        'approval_pending' => array('label' => '승인대기', 'card_class' => 'bg-blue-50 border-blue-200 hover:border-blue-300', 'label_class' => 'text-blue-500', 'count_class' => 'text-blue-700', 'items' => array())
    );
    $executiveSummarySeen = array();
    foreach ($executiveSummaryCards as $summaryKey => $summaryCard) {
        $executiveSummarySeen[$summaryKey] = array();
    }
    if (isset($summaryData['employees']) && is_array($summaryData['employees'])) {
        foreach ($summaryData['employees'] as $employeeRow) {
            $feed = isset($employeeRow['feed']) && is_array($employeeRow['feed']) ? $employeeRow['feed'] : array();
            foreach ($feed as $item) {
                if (cpms_task_feed_counts_as_today($item)) {
                    cpms_executive_add_summary_item($executiveSummaryCards['today']['items'], $executiveSummarySeen['today'], $item);
                }
                if (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1) {
                    cpms_executive_add_summary_item($executiveSummaryCards['urgent']['items'], $executiveSummarySeen['urgent'], $item);
                }
                if (cpms_tasks_is_due_soon($item)) {
                    cpms_executive_add_summary_item($executiveSummaryCards['due_soon']['items'], $executiveSummarySeen['due_soon'], $item);
                }
                if (cpms_tasks_is_delayed($item)) {
                    cpms_executive_add_summary_item($executiveSummaryCards['delayed']['items'], $executiveSummarySeen['delayed'], $item);
                }
                if (isset($item['status']) && (string)$item['status'] === 'done') {
                    cpms_executive_add_summary_item($executiveSummaryCards['done']['items'], $executiveSummarySeen['done'], $item);
                }
                $sourceType = isset($item['source_type']) ? (string)$item['source_type'] : '';
                if (in_array($sourceType, array('approval', 'labor_gongsu', 'equipment_gongsu', 'attendance'), true)) {
                    cpms_executive_add_summary_item($executiveSummaryCards['approval_pending']['items'], $executiveSummarySeen['approval_pending'], $item);
                }
            }
        }
    }
    ?>
    <div id="cpmsExecutiveTasksPanel" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
        <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-extrabold text-gray-900">부서별 업무 현황</h2>
            </div>
        </div>

        <div data-cpms-executive-task-body>
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mt-6">
            <?php foreach ($executiveSummaryCards as $summaryKey => $summaryCard): ?>
                <button type="button" data-exec-summary-open="<?php echo h($summaryKey); ?>" class="p-4 rounded-2xl border text-left transition shadow-sm <?php echo h($summaryCard['card_class']); ?>">
                    <div class="text-xs font-bold <?php echo h($summaryCard['label_class']); ?>"><?php echo h($summaryCard['label']); ?></div>
                    <div class="mt-2 text-3xl font-extrabold <?php echo h($summaryCard['count_class']); ?>"><?php echo count($summaryCard['items']); ?></div>
                </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($executiveSummaryCards as $summaryKey => $summaryCard): ?>
            <div id="modal-execSummary-<?php echo h($summaryKey); ?>" class="fixed inset-0 z-50 hidden">
                <div class="absolute inset-0 bg-black/40" data-exec-summary-close="<?php echo h($summaryKey); ?>"></div>
                <div class="absolute inset-0 flex items-center justify-center p-4">
                    <div class="w-full max-w-5xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                        <div class="flex items-center justify-between gap-4 px-6 py-5 border-b border-gray-100">
                            <div>
                                <div class="text-2xl font-extrabold text-gray-900"><?php echo h($summaryCard['label']); ?></div>
                                <div class="text-sm text-gray-500 mt-1"><?php echo count($summaryCard['items']); ?>건</div>
                            </div>
                            <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-bold text-gray-700 hover:bg-gray-50" data-exec-summary-close="<?php echo h($summaryKey); ?>">닫기</button>
                        </div>
                        <div class="p-5 overflow-y-auto max-h-[72vh]">
                            <?php if (count($summaryCard['items']) === 0): ?>
                                <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500">표시할 업무가 없습니다.</div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($summaryCard['items'] as $item): ?>
                                        <div class="p-4 rounded-2xl border border-gray-200 bg-slate-50">
                                            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2 mb-2">
                                                        <span class="cpms-chip px-3 py-1 rounded-full border text-xs font-bold bg-slate-100 text-slate-700 border-slate-200"><?php echo h(cpms_tasks_type_label(isset($item['task_type']) ? $item['task_type'] : 'general')); ?></span>
                                                        <span class="cpms-chip px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_tasks_badge_class('status', cpms_tasks_is_delayed($item) ? 'delayed' : (isset($item['status']) ? $item['status'] : 'pending'))); ?>"><?php echo h(isset($item['display_status']) ? $item['display_status'] : '-'); ?></span>
                                                        <?php if (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1): ?><span class="cpms-chip px-3 py-1 rounded-full border text-xs font-bold bg-rose-50 text-rose-700 border-rose-200">긴급</span><?php endif; ?>
                                                    </div>
                                                    <div class="font-extrabold text-gray-900 break-words"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></div>
                                                    <div class="text-sm text-gray-600 mt-1">
                                                        요청자: <?php echo h(isset($item['requester_name']) ? $item['requester_name'] : '-'); ?>
                                                        / 담당자: <?php echo h(isset($item['assignee_name']) ? $item['assignee_name'] : '-'); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500 mt-1">마감: <?php echo h(cpms_executive_task_due_text($item)); ?></div>
                                                    <?php if (isset($item['content']) && trim((string)$item['content']) !== ''): ?>
                                                        <div class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?php echo h(cpms_tasks_text_excerpt($item['content'], 120)); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="shrink-0">
                                                    <?php echo cpms_executive_task_detail_button($item, '상세 보기'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div id="modal-execGenericDetail" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/40" data-exec-generic-detail-close></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-3xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                    <div class="flex items-center justify-between gap-4 px-6 py-5 border-b border-gray-100">
                        <div>
                            <div class="text-2xl font-extrabold text-gray-900" id="execGenericDetailTitle">상세 보기</div>
                            <div class="text-sm text-gray-500 mt-1" id="execGenericDetailMeta"></div>
                        </div>
                        <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-bold text-gray-700 hover:bg-gray-50" data-exec-generic-detail-close>닫기</button>
                    </div>
                    <div class="p-6 overflow-y-auto max-h-[72vh] space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-3 py-1 rounded-full border text-xs font-bold bg-slate-100 text-slate-700 border-slate-200" id="execGenericDetailType"></span>
                            <span class="px-3 py-1 rounded-full border text-xs font-bold bg-blue-50 text-blue-700 border-blue-100" id="execGenericDetailStatus"></span>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                            <div class="text-xs font-bold text-gray-500">내용</div>
                            <div class="mt-2 text-sm text-gray-800 whitespace-pre-line" id="execGenericDetailContent"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="modal-execTaskDetail" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/40" data-exec-task-detail-close></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-4xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                    <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                        <div class="text-2xl font-extrabold text-gray-900">업무 상세</div>
                        <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-bold text-gray-700 hover:bg-gray-50" data-exec-task-detail-close>닫기</button>
                    </div>
                    <div id="execTaskDetailBody" class="p-6 overflow-y-auto max-h-[74vh]">
                        <div class="text-sm text-gray-500">업무 정보를 불러오는 중입니다.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6">
            <div class="text-sm font-bold text-gray-700 mb-2">부서 필터</div>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($departmentOptions as $departmentName): ?>
                    <?php
                    $isSelected = ($selectedDepartment === $departmentName);
                    $departmentLabel = (($departmentName === '기타') ? '임원' : $departmentName);
                    $url = '?r=dashboard_executive&exec_tab=department';
                    if ($departmentName !== '전체') $url .= '&task_department=' . urlencode($departmentName);
                    ?>
                    <a href="<?php echo h($url); ?>" class="px-4 py-2 rounded-2xl font-bold <?php echo $isSelected ? 'bg-gray-900 text-white' : 'bg-white border border-gray-200 text-gray-700'; ?>">
                        <?php echo h($departmentLabel); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-6">
            <h3 class="text-xl font-extrabold text-gray-900 mb-4">부서별 현황</h3>
            <div class="space-y-3">
                <?php foreach ($summaryData['departments'] as $departmentName => $departmentMetrics): ?>
                    <?php if ($selectedDepartment !== '전체' && $departmentName !== $selectedDepartment) continue; ?>
                    <?php
                    $departmentLabel = ($departmentName === '기타') ? '임원' : $departmentName;
                    $departmentUrl = '?r=dashboard_executive&exec_tab=department&task_department=' . urlencode($departmentName);
                    ?>
                    <a href="<?php echo h($departmentUrl); ?>" class="block px-4 py-3 rounded-2xl border border-gray-200 bg-white shadow-sm hover:border-gray-300 hover:shadow-md transition">
                        <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-3">
                            <div class="text-lg font-extrabold text-gray-900"><?php echo h($departmentLabel); ?></div>
                            <div class="cpms-chip-row text-sm">
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-50 font-bold">오늘 할일 <b class="text-base"><?php echo (int)$departmentMetrics['today']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-emerald-50 text-emerald-700 font-bold">완료 <b class="text-base"><?php echo (int)$departmentMetrics['done']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-blue-50 text-blue-700 font-bold">진행중 <b class="text-base"><?php echo (int)$departmentMetrics['progress']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 font-bold">지연 <b class="text-base"><?php echo (int)$departmentMetrics['delayed']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-rose-50 text-rose-700 font-bold">긴급 <b class="text-base"><?php echo (int)$departmentMetrics['urgent']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-amber-50 text-amber-700 font-bold">마감 임박 <b class="text-base"><?php echo (int)$departmentMetrics['due_soon']; ?>건</b></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($selectedDepartment !== '전체'): ?>
            <div class="mt-8">
                <h3 class="text-xl font-extrabold text-gray-900 mb-4">직원별 현황</h3>
                <div class="space-y-3" data-cpms-executive-employee-list>
                    <?php foreach ($summaryData['employees'] as $employeeRow): ?>
                    <?php
                    $employee = isset($employeeRow['employee']) ? $employeeRow['employee'] : array();
                    $metrics = isset($employeeRow['metrics']) ? $employeeRow['metrics'] : array();
                    $feed = isset($employeeRow['feed']) ? $employeeRow['feed'] : array();
                    $modalId = 'executiveTaskEmployee' . (int)$employee['id'];
                    $employeeLeaveInfo = function_exists('approval_current_leave_info_from_index') ? approval_current_leave_info_from_index($currentLeaveIndex, $employee) : null;
                    $employeeLeaveLabel = is_array($employeeLeaveInfo) && isset($employeeLeaveInfo['status_label']) ? (string)$employeeLeaveInfo['status_label'] : '';
                    $employeeDepartmentLabel = (isset($employee['department']) && trim((string)$employee['department']) !== '') ? (($employee['department'] === '기타') ? '임원' : $employee['department']) : '-';
                    ?>
                    <button type="button" data-cpms-employee-toggle="<?php echo h($modalId); ?>" class="w-full text-left px-4 py-3 rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md hover:border-gray-300 transition">
                        <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="text-lg font-extrabold text-gray-900 break-words"><?php echo h(isset($employee['name']) ? $employee['name'] : '-'); ?></div>
                                    <?php if ($employeeLeaveLabel !== ''): ?>
                                        <span class="cpms-chip inline-flex items-center px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 text-xs font-extrabold whitespace-nowrap"><?php echo h($employeeLeaveLabel); ?></span>
                                    <?php endif; ?>
                                    <span class="cpms-chip px-2 py-1 rounded-full bg-slate-100 text-slate-700 text-xs font-bold"><?php echo h($employeeDepartmentLabel); ?></span>
                                </div>
                                <div class="text-sm text-gray-500 mt-1"><?php echo h((isset($employee['position']) && trim((string)$employee['position']) !== '') ? $employee['position'] : '-'); ?></div>
                            </div>
                            <div class="cpms-chip-row text-sm">
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-50 font-bold">오늘 할일 <b class="text-base"><?php echo (int)$metrics['today']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-emerald-50 text-emerald-700 font-bold">완료 <b class="text-base"><?php echo (int)$metrics['done']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-blue-50 text-blue-700 font-bold">진행중 <b class="text-base"><?php echo (int)$metrics['progress']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 font-bold">지연 <b class="text-base"><?php echo (int)$metrics['delayed']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-rose-50 text-rose-700 font-bold">긴급 <b class="text-base"><?php echo (int)$metrics['urgent']; ?>건</b></span>
                                <span class="cpms-chip inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-amber-50 text-amber-700 font-bold">마감 임박 <b class="text-base"><?php echo (int)$metrics['due_soon']; ?>건</b></span>
                            </div>
                        </div>
                    </button>

                    <div id="panel-<?php echo h($modalId); ?>" class="hidden mt-2 mb-4 rounded-2xl border border-gray-200 bg-white shadow-sm" data-cpms-employee-panel>
                        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                            <div>
                                <div class="text-xl font-extrabold text-gray-900"><?php echo h(isset($employee['name']) ? $employee['name'] : '-'); ?> 업무 현황</div>
                                <?php if ($employeeLeaveLabel !== ''): ?>
                                    <div class="mt-1"><span class="cpms-chip inline-flex items-center px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 text-xs font-extrabold whitespace-nowrap"><?php echo h($employeeLeaveLabel); ?></span></div>
                                <?php endif; ?>
                                <div class="text-sm text-gray-500 mt-1"><?php echo h($employeeDepartmentLabel . ' / ' . ((isset($employee['position']) && trim((string)$employee['position']) !== '') ? $employee['position'] : '-')); ?></div>
                            </div>
                            <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-bold text-gray-700 hover:bg-gray-50" data-cpms-employee-close="<?php echo h($modalId); ?>">닫기</button>
                        </div>
                        <div class="p-5">
                            <?php if (count($feed) === 0): ?>
                                <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500">표시할 업무가 없습니다.</div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($feed as $item): ?>
                                        <div class="p-4 rounded-2xl border border-gray-200 bg-slate-50">
                                            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2 mb-2">
                                                        <span class="cpms-chip px-3 py-1 rounded-full border text-xs font-bold bg-slate-100 text-slate-700 border-slate-200"><?php echo h(cpms_tasks_type_label(isset($item['task_type']) ? $item['task_type'] : 'general')); ?></span>
                                                        <span class="cpms-chip px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_tasks_badge_class('status', cpms_tasks_is_delayed($item) ? 'delayed' : (isset($item['status']) ? $item['status'] : 'pending'))); ?>"><?php echo h(isset($item['display_status']) ? $item['display_status'] : '-'); ?></span>
                                                        <?php if (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1): ?><span class="cpms-chip px-3 py-1 rounded-full border text-xs font-bold bg-rose-50 text-rose-700 border-rose-200">긴급</span><?php endif; ?>
                                                    </div>
                                                    <div class="font-extrabold text-gray-900"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></div>
                                                    <div class="text-sm text-gray-600 mt-1">요청자: <?php echo h(isset($item['requester_name']) ? $item['requester_name'] : '-'); ?></div>
                                                    <div class="text-sm text-gray-500 mt-1">마감: <?php echo h(isset($item['due_date']) && $item['due_date'] !== '' ? $item['due_date'] . (isset($item['due_time']) && $item['due_time'] !== '' ? ' ' . substr((string)$item['due_time'], 0, 5) : '') : '-'); ?></div>
                                                </div>
                                                <div>
                                                    <?php echo cpms_executive_task_detail_button($item, '상세 보기'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </div>
    <script>
    (function(){
        var toggles = document.querySelectorAll('[data-cpms-employee-toggle]');
        var closeButtons = document.querySelectorAll('[data-cpms-employee-close]');
        function closeAll(exceptId) {
            var panels = document.querySelectorAll('[data-cpms-employee-panel]');
            for (var i = 0; i < panels.length; i++) {
                if (exceptId && panels[i].id === 'panel-' + exceptId) continue;
                panels[i].classList.add('hidden');
            }
        }
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].addEventListener('click', function(){
                var key = this.getAttribute('data-cpms-employee-toggle');
                var panel = document.getElementById('panel-' + key);
                if (!panel) return;
                var willOpen = panel.classList.contains('hidden');
                closeAll(willOpen ? key : null);
                if (willOpen) panel.classList.remove('hidden');
                else panel.classList.add('hidden');
            });
        }
        for (var j = 0; j < closeButtons.length; j++) {
            closeButtons[j].addEventListener('click', function(e){
                e.preventDefault();
                var key = this.getAttribute('data-cpms-employee-close');
                var panel = document.getElementById('panel-' + key);
                if (panel) panel.classList.add('hidden');
            });
        }
    })();
    (function(){
        function openSummary(key) {
            var modal = document.getElementById('modal-execSummary-' + key);
            if (modal) modal.classList.remove('hidden');
        }
        function closeSummary(key) {
            var modal = document.getElementById('modal-execSummary-' + key);
            if (modal) modal.classList.add('hidden');
        }
        function setText(id, value) {
            var node = document.getElementById(id);
            if (node) node.textContent = value || '-';
        }
        function openGenericDetail(button) {
            setText('execGenericDetailTitle', button.getAttribute('data-title'));
            setText('execGenericDetailMeta', button.getAttribute('data-meta'));
            setText('execGenericDetailType', button.getAttribute('data-type'));
            setText('execGenericDetailStatus', button.getAttribute('data-status'));
            setText('execGenericDetailContent', button.getAttribute('data-content'));
            var modal = document.getElementById('modal-execGenericDetail');
            if (modal) modal.classList.remove('hidden');
        }
        function closeGenericDetail() {
            var modal = document.getElementById('modal-execGenericDetail');
            if (modal) modal.classList.add('hidden');
        }
        function openTaskDetail(taskId) {
            var body = document.getElementById('execTaskDetailBody');
            var modal = document.getElementById('modal-execTaskDetail');
            if (body) body.innerHTML = '<div class="text-sm text-gray-500">업무 정보를 불러오는 중입니다.</div>';
            if (modal) modal.classList.remove('hidden');
            var xhr = new XMLHttpRequest();
            var detailUrl = '?r=tasks/detail&id=' + encodeURIComponent(taskId) + '&modal=1&readonly=1';
            detailUrl += '&return_url=' + encodeURIComponent(window.location.pathname + window.location.search);
            xhr.open('GET', detailUrl, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                if (!body) return;
                if (xhr.status >= 200 && xhr.status < 300) body.innerHTML = xhr.responseText;
                else body.innerHTML = '<div class="text-sm text-red-600">업무 정보를 불러오지 못했습니다.</div>';
                if (window.lucide) { try { lucide.createIcons(); } catch (err) {} }
            };
            xhr.send(null);
        }
        function closeTaskDetail() {
            var modal = document.getElementById('modal-execTaskDetail');
            if (modal) modal.classList.add('hidden');
        }

        document.addEventListener('click', function(e){
            var openButton = e.target && e.target.closest ? e.target.closest('[data-exec-summary-open]') : null;
            if (openButton) {
                e.preventDefault();
                openSummary(openButton.getAttribute('data-exec-summary-open'));
                return;
            }

            var closeButton = e.target && e.target.closest ? e.target.closest('[data-exec-summary-close]') : null;
            if (closeButton) {
                e.preventDefault();
                closeSummary(closeButton.getAttribute('data-exec-summary-close'));
                return;
            }

            var detailButton = e.target && e.target.closest ? e.target.closest('[data-exec-generic-detail-open]') : null;
            if (detailButton) {
                e.preventDefault();
                openGenericDetail(detailButton);
                return;
            }

            var taskDetailButton = e.target && e.target.closest ? e.target.closest('[data-exec-task-detail-open]') : null;
            if (taskDetailButton) {
                e.preventDefault();
                openTaskDetail(taskDetailButton.getAttribute('data-task-id'));
                return;
            }

            var detailCloseButton = e.target && e.target.closest ? e.target.closest('[data-exec-generic-detail-close]') : null;
            if (detailCloseButton) {
                e.preventDefault();
                closeGenericDetail();
                return;
            }

            var taskDetailCloseButton = e.target && e.target.closest ? e.target.closest('[data-exec-task-detail-close]') : null;
            if (taskDetailCloseButton) {
                e.preventDefault();
                closeTaskDetail();
                return;
            }
        });
    })();
    </script>
    <?php
}}
