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
    $currentStatus = isset($item['status']) ? (string)$item['status'] : '';
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
    $isRequester = ((int)$currentEmployeeId > 0 && isset($item['requester_employee_id']) && (int)$item['requester_employee_id'] === (int)$currentEmployeeId);
    $canRequesterAct = $requestedMode && $isRequester && $currentStatus === 'completion_pending';
    $canEditDue = $requestedMode && $isRequester && !in_array($currentStatus, array('done', 'cancelled'), true);
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
        <div class="mt-2 flex flex-wrap gap-2">
            <?php if (!$requestedMode && isset($item['request_file_count']) && (int)$item['request_file_count'] > 0): ?>
                <span class="px-2.5 py-1 rounded-full bg-sky-50 text-sky-700 border border-sky-200 text-xs font-extrabold">[파일첨부되어있음]</span>
            <?php endif; ?>
            <?php if ($requestedMode && isset($item['read_at']) && trim((string)$item['read_at']) !== ''): ?>
                <span class="px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 text-xs font-extrabold">[요청 읽음]</span>
            <?php endif; ?>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <?php echo cpms_render_task_action_link($item); ?>
            <?php if ($canRespondMeeting): ?>
                <form method="post" action="?r=task_meeting_response" class="inline" data-task-meeting-response-form data-task-id="<?php echo (int)$item['source_id']; ?>">
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
            <?php if (($canCompleteMeeting || !$isMeetingTask) && isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1 && !$requestedMode && (int)$currentEmployeeId > 0 && isset($item['assignee_employee_id']) && (int)$item['assignee_employee_id'] === (int)$currentEmployeeId && !in_array(isset($item['status']) ? $item['status'] : '', array('completion_pending', 'done', 'cancelled'), true)): ?>
                <button type="button" data-task-complete-open data-task-id="<?php echo (int)$item['source_id']; ?>" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-sm font-bold">완료</button>
            <?php endif; ?>
            <?php if ($canEditDue && isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1): ?>
                <button type="button" data-task-due-open data-task-id="<?php echo (int)$item['source_id']; ?>" data-task-due-date="<?php echo h(isset($item['due_date']) ? $item['due_date'] : ''); ?>" data-task-due-time="<?php echo h(isset($item['due_time']) ? substr((string)$item['due_time'], 0, 5) : '18:00'); ?>" class="px-3 py-2 rounded-xl bg-white border border-gray-200 text-slate-700 text-sm font-bold">마감 수정</button>
            <?php endif; ?>
            <?php if ($canRequesterAct && isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1): ?>
                <form method="post" action="?r=tasks/completion_approve" class="inline">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$item['source_id']; ?>">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-sm font-bold">완료 승인</button>
                </form>
                <button type="button" data-task-completion-reject-open data-task-id="<?php echo (int)$item['source_id']; ?>" data-task-due-date="<?php echo h(isset($item['due_date']) ? $item['due_date'] : ''); ?>" data-task-due-time="<?php echo h(isset($item['due_time']) ? substr((string)$item['due_time'], 0, 5) : '18:00'); ?>" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-sm font-bold">반려</button>
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

if (!function_exists('cpms_task_kanban_priority_rank')) {
function cpms_task_kanban_priority_rank($priority)
{
    $priority = (string)$priority;
    if ($priority === 'urgent') return 0;
    if ($priority === 'high') return 1;
    if ($priority === 'normal') return 2;
    if ($priority === 'low') return 3;
    return 2;
}}

if (!function_exists('cpms_task_kanban_sort')) {
function cpms_task_kanban_sort($a, $b)
{
    $aUrgent = (isset($a['is_urgent']) && (int)$a['is_urgent'] === 1) || (isset($a['priority']) && (string)$a['priority'] === 'urgent');
    $bUrgent = (isset($b['is_urgent']) && (int)$b['is_urgent'] === 1) || (isset($b['priority']) && (string)$b['priority'] === 'urgent');
    if ($aUrgent !== $bUrgent) return $aUrgent ? -1 : 1;

    $aDelayed = cpms_tasks_is_delayed($a);
    $bDelayed = cpms_tasks_is_delayed($b);
    if ($aDelayed !== $bDelayed) return $aDelayed ? -1 : 1;

    $aPriority = cpms_task_kanban_priority_rank(isset($a['priority']) ? $a['priority'] : 'normal');
    $bPriority = cpms_task_kanban_priority_rank(isset($b['priority']) ? $b['priority'] : 'normal');
    if ($aPriority !== $bPriority) return ($aPriority < $bPriority) ? -1 : 1;

    $aCreated = isset($a['created_at']) ? strtotime((string)$a['created_at']) : false;
    $bCreated = isset($b['created_at']) ? strtotime((string)$b['created_at']) : false;
    if ($aCreated !== false && $bCreated !== false && $aCreated !== $bCreated) return ($aCreated < $bCreated) ? -1 : 1;
    if ($aCreated !== false && $bCreated === false) return -1;
    if ($aCreated === false && $bCreated !== false) return 1;

    $aId = isset($a['source_id']) ? (int)$a['source_id'] : 0;
    $bId = isset($b['source_id']) ? (int)$b['source_id'] : 0;
    if ($aId === $bId) return 0;
    return ($aId < $bId) ? -1 : 1;
}}

if (!function_exists('cpms_task_kanban_lane_key')) {
function cpms_task_kanban_lane_key($item)
{
    $status = isset($item['status']) ? (string)$item['status'] : 'pending';
    if ($status === 'done' || $status === 'completed') return 'done';
    if ($status === 'completion_pending') return 'completion_pending';
    if ($status === 'rejected' || $status === 'cancelled' || $status === 'meeting_unavailable') return 'rejected';
    if (in_array($status, array('progress', 'processing', 'revision', 'meeting_available'), true)) return 'progress';
    return 'pending';
}}

if (!function_exists('cpms_task_kanban_should_include')) {
function cpms_task_kanban_should_include($item)
{
    if (!is_array($item)) return false;
    $sourceType = isset($item['source_type']) ? (string)$item['source_type'] : '';
    return ($sourceType === 'task' && isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1);
}}

if (!function_exists('cpms_task_kanban_unique_key')) {
function cpms_task_kanban_unique_key($item)
{
    if (!is_array($item)) return '';
    $groupKey = isset($item['group_key']) ? trim((string)$item['group_key']) : '';
    if ($groupKey !== '' && strpos($groupKey, 'task_request:') !== 0) return 'group:' . $groupKey;
    return (isset($item['source_type']) ? (string)$item['source_type'] : 'task') . ':' . (isset($item['source_id']) ? (int)$item['source_id'] : 0);
}}

if (!function_exists('cpms_render_task_kanban_card')) {
function cpms_render_task_kanban_card($item, $currentEmployeeId)
{
    $priority = isset($item['priority']) ? (string)$item['priority'] : 'normal';
    $isUrgent = (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1) || $priority === 'urgent';
    $isDelayed = cpms_tasks_is_delayed($item);
    $statusKey = $isDelayed ? 'delayed' : (isset($item['status']) ? $item['status'] : 'pending');
    $laneKey = cpms_task_kanban_lane_key($item);
    $dueText = '-';
    if (isset($item['due_date']) && trim((string)$item['due_date']) !== '') {
        $dueText = (string)$item['due_date'];
        if (isset($item['due_time']) && trim((string)$item['due_time']) !== '') $dueText .= ' ' . substr((string)$item['due_time'], 0, 5);
    }
    $canDrag = isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1
        && isset($item['assignee_employee_id']) && (int)$item['assignee_employee_id'] === (int)$currentEmployeeId;
    $isMeetingTask = isset($item['task_type']) && (string)$item['task_type'] === 'meeting';
    $canStatusAction = $canDrag && !$isMeetingTask;
    $personLabel = '요청자';
    $personName = isset($item['requester_name']) ? $item['requester_name'] : '-';
    if ((int)$currentEmployeeId > 0 && isset($item['requester_employee_id']) && (int)$item['requester_employee_id'] === (int)$currentEmployeeId && isset($item['assignee_name']) && trim((string)$item['assignee_name']) !== '') {
        $personLabel = '담당자';
        $personName = $item['assignee_name'];
    }
    ?>
    <article class="cpms-kanban-card rounded-2xl border border-gray-200 bg-white p-4 shadow-sm shadow-gray-100"
             data-kanban-card
             data-task-id="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>"
             data-kanban-status="<?php echo h($laneKey); ?>"
             data-kanban-priority="<?php echo h($priority); ?>"
             data-kanban-priority-rank="<?php echo (int)cpms_task_kanban_priority_rank($priority); ?>"
             data-kanban-is-urgent="<?php echo $isUrgent ? '1' : '0'; ?>"
             data-kanban-delayed="<?php echo $isDelayed ? '1' : '0'; ?>"
             data-kanban-created="<?php echo h(isset($item['created_at']) ? $item['created_at'] : ''); ?>"
             draggable="<?php echo $canDrag ? 'true' : 'false'; ?>">
        <div class="flex items-start justify-between gap-2">
            <span class="px-2.5 py-1 rounded-full border text-xs font-extrabold <?php echo h(cpms_tasks_badge_class('priority', $priority)); ?>" data-kanban-priority-badge><?php echo h(cpms_tasks_priority_label($priority)); ?></span>
            <span class="px-2.5 py-1 rounded-full border text-xs font-extrabold <?php echo h(cpms_tasks_badge_class('status', $statusKey)); ?>" data-kanban-status-badge><?php echo h(isset($item['display_status']) ? $item['display_status'] : cpms_tasks_status_label(isset($item['status']) ? $item['status'] : 'pending')); ?></span>
        </div>
        <div class="mt-3 text-base font-extrabold text-slate-900 leading-6 break-words"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></div>
        <div class="mt-2 text-sm text-slate-600"><?php echo h($personLabel); ?>: <?php echo h($personName); ?></div>
        <div class="mt-1 text-sm text-slate-500">마감: <?php echo h($dueText); ?></div>
        <div class="mt-3 flex flex-wrap gap-2" data-kanban-flags>
            <?php if ($isUrgent): ?>
                <span class="px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-xs font-extrabold" data-kanban-urgent-chip>긴급</span>
            <?php endif; ?>
            <?php if ($isDelayed): ?>
                <span class="px-2.5 py-1 rounded-full bg-red-50 text-red-700 border border-red-200 text-xs font-extrabold">지연</span>
            <?php endif; ?>
            <?php if (isset($item['request_file_count']) && (int)$item['request_file_count'] > 0): ?>
                <span class="px-2.5 py-1 rounded-full bg-sky-50 text-sky-700 border border-sky-200 text-xs font-extrabold">[파일첨부되어있음]</span>
            <?php endif; ?>
            <?php if (isset($item['complete_file_count']) && (int)$item['complete_file_count'] > 0): ?>
                <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-extrabold">완료파일</span>
            <?php endif; ?>
        </div>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <?php if ($canStatusAction): ?>
                <button type="button" data-kanban-status-action="progress" data-task-id="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>" class="px-3 py-2 rounded-xl bg-blue-600 text-white text-sm font-extrabold <?php echo $laneKey === 'progress' || $laneKey === 'completion_pending' || $laneKey === 'done' ? 'hidden' : ''; ?>">진행중</button>
                <button type="button" data-kanban-status-action="done" data-task-id="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-sm font-extrabold <?php echo $laneKey === 'completion_pending' || $laneKey === 'done' ? 'hidden' : ''; ?>">완료 요청</button>
            <?php endif; ?>
            <button type="button" data-task-detail-open data-task-id="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>" class="px-3 py-2 rounded-xl bg-gray-900 text-white text-sm font-extrabold">상세</button>
        </div>
    </article>
    <?php
}}

if (!function_exists('cpms_render_task_kanban_lane')) {
function cpms_render_task_kanban_lane($laneKey, $title, $items, $currentEmployeeId)
{
    ?>
    <section class="cpms-kanban-lane rounded-2xl border border-gray-200 bg-slate-50 p-4 min-h-[280px]" data-kanban-lane="<?php echo h($laneKey); ?>">
        <div class="flex items-center justify-between gap-3 mb-4">
            <h3 class="text-lg font-extrabold text-gray-900"><?php echo h($title); ?></h3>
            <span class="px-3 py-1 rounded-full bg-white border border-gray-200 text-sm font-extrabold text-gray-700" data-kanban-count><?php echo count($items); ?>건</span>
        </div>
        <div class="space-y-3 min-h-[180px]" data-kanban-drop="<?php echo h($laneKey); ?>">
            <?php if (count($items) === 0): ?>
                <div class="p-4 rounded-2xl border border-dashed border-gray-300 bg-white text-sm text-gray-500" data-kanban-empty>표시할 업무가 없습니다.</div>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <?php cpms_render_task_kanban_card($item, $currentEmployeeId); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php
}}

if (!function_exists('cpms_render_requested_task_kanban_card')) {
function cpms_render_requested_task_kanban_card($item, $currentEmployeeId, $returnUrl)
{
    $priority = isset($item['priority']) ? (string)$item['priority'] : 'normal';
    $isUrgent = (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1) || $priority === 'urgent';
    $isDelayed = cpms_tasks_is_delayed($item);
    $statusKey = $isDelayed ? 'delayed' : (isset($item['status']) ? $item['status'] : 'pending');
    $dueText = '-';
    if (isset($item['due_date']) && trim((string)$item['due_date']) !== '') {
        $dueText = (string)$item['due_date'];
        if (isset($item['due_time']) && trim((string)$item['due_time']) !== '') $dueText .= ' ' . substr((string)$item['due_time'], 0, 5);
    }
    $isRead = isset($item['read_at']) && trim((string)$item['read_at']) !== '';
    $currentStatus = isset($item['status']) ? (string)$item['status'] : '';
    $isRequester = ((int)$currentEmployeeId > 0 && isset($item['requester_employee_id']) && (int)$item['requester_employee_id'] === (int)$currentEmployeeId);
    $canRequesterAct = $isRequester && $currentStatus === 'completion_pending';
    $canEditDue = $isRequester && !in_array($currentStatus, array('done', 'cancelled'), true);
    ?>
    <article class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm shadow-gray-100" data-requested-task-card draggable="false">
        <div class="flex items-start justify-between gap-2">
            <span class="px-2.5 py-1 rounded-full border text-xs font-extrabold <?php echo h(cpms_tasks_badge_class('priority', $priority)); ?>"><?php echo h(cpms_tasks_priority_label($priority)); ?></span>
            <span class="px-2.5 py-1 rounded-full border text-xs font-extrabold <?php echo h(cpms_tasks_badge_class('status', $statusKey)); ?>"><?php echo h(isset($item['display_status']) ? $item['display_status'] : cpms_tasks_status_label(isset($item['status']) ? $item['status'] : 'pending')); ?></span>
        </div>
        <div class="mt-3 text-base font-extrabold text-slate-900 leading-6 break-words"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></div>
        <?php if (isset($item['project_name']) && trim((string)$item['project_name']) !== ''): ?>
            <div class="mt-2 text-sm text-slate-600">현장명: <?php echo h($item['project_name']); ?></div>
        <?php endif; ?>
        <div class="mt-2 text-sm text-slate-600">담당자: <?php echo h(isset($item['assignee_name']) && trim((string)$item['assignee_name']) !== '' ? $item['assignee_name'] : '-'); ?></div>
        <div class="mt-1 text-sm text-slate-500">마감: <?php echo h($dueText); ?></div>
        <div class="mt-3 flex flex-wrap gap-2">
            <?php if ($isUrgent): ?>
                <span class="px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-xs font-extrabold">긴급</span>
            <?php endif; ?>
            <?php if ($isDelayed): ?>
                <span class="px-2.5 py-1 rounded-full bg-red-50 text-red-700 border border-red-200 text-xs font-extrabold">지연</span>
            <?php endif; ?>
            <?php if ($isRead): ?>
                <span class="px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 text-xs font-extrabold">[요청 읽음]</span>
            <?php endif; ?>
            <?php if (isset($item['request_file_count']) && (int)$item['request_file_count'] > 0): ?>
                <span class="px-2.5 py-1 rounded-full bg-sky-50 text-sky-700 border border-sky-200 text-xs font-extrabold">[파일첨부되어있음]</span>
            <?php endif; ?>
        </div>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button type="button" data-task-detail-open data-task-id="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>" class="px-3 py-2 rounded-xl bg-gray-900 text-white text-sm font-extrabold">상세</button>
            <?php if ($canEditDue): ?>
                <button type="button" data-task-due-open data-task-id="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>" data-task-due-date="<?php echo h(isset($item['due_date']) ? $item['due_date'] : ''); ?>" data-task-due-time="<?php echo h(isset($item['due_time']) ? substr((string)$item['due_time'], 0, 5) : '18:00'); ?>" class="px-3 py-2 rounded-xl bg-white border border-gray-200 text-slate-700 text-sm font-extrabold">마감 수정</button>
            <?php endif; ?>
            <?php if ($canRequesterAct): ?>
                <form method="post" action="?r=tasks/completion_approve" class="inline-flex">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-sm font-extrabold">완료 승인</button>
                </form>
                <button type="button" data-task-completion-reject-open data-task-id="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>" data-task-due-date="<?php echo h(isset($item['due_date']) ? $item['due_date'] : ''); ?>" data-task-due-time="<?php echo h(isset($item['due_time']) ? substr((string)$item['due_time'], 0, 5) : '18:00'); ?>" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-sm font-extrabold">반려</button>
            <?php endif; ?>
        </div>
    </article>
    <?php
}}

if (!function_exists('cpms_render_requested_task_kanban_lane')) {
function cpms_render_requested_task_kanban_lane($items, $currentEmployeeId, $requestedTaskDate, $dashboardHiddenInputs, $returnUrl)
{
    if (!is_array($items)) $items = array();
    if (!is_array($dashboardHiddenInputs)) $dashboardHiddenInputs = array();
    ?>
    <section class="rounded-2xl border border-gray-200 bg-slate-50 p-4 min-h-[280px]" data-requested-task-lane>
        <div class="flex items-center justify-between gap-3 mb-4">
            <h3 class="text-lg font-extrabold text-gray-900">내가 요청한 업무</h3>
            <span class="px-3 py-1 rounded-full bg-white border border-gray-200 text-sm font-extrabold text-gray-700"><?php echo count($items); ?>건</span>
        </div>
        <form method="get" action="" class="mb-3 flex items-center gap-2">
            <?php foreach ($dashboardHiddenInputs as $dashboardHiddenName => $dashboardHiddenValue): ?>
                <input type="hidden" name="<?php echo h($dashboardHiddenName); ?>" value="<?php echo h($dashboardHiddenValue); ?>">
            <?php endforeach; ?>
            <input type="date" name="requested_task_date" value="<?php echo h($requestedTaskDate); ?>" class="min-w-0 flex-1 px-3 py-2 rounded-xl border border-gray-200 text-sm">
            <button type="submit" class="px-3 py-2 rounded-xl bg-gray-900 text-white text-sm font-extrabold">조회</button>
        </form>
        <div class="space-y-3 min-h-[180px]">
            <?php if (count($items) === 0): ?>
                <div class="p-4 rounded-2xl border border-dashed border-gray-300 bg-white text-sm text-gray-500">표시할 업무가 없습니다.</div>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <?php cpms_render_requested_task_kanban_card($item, $currentEmployeeId, $returnUrl); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
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
        && !in_array(isset($item['status']) ? (string)$item['status'] : '', array('completion_pending', 'done', 'cancelled'), true);
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
        <?php if (isset($item['request_file_count']) && (int)$item['request_file_count'] > 0): ?>
            <div class="mt-3">
                <span class="px-2.5 py-1 rounded-full bg-sky-50 text-sky-700 border border-sky-200 text-xs font-extrabold">[파일첨부되어있음]</span>
            </div>
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
                <form method="post" action="?r=task_meeting_response" class="inline-flex" data-task-meeting-response-form data-task-id="<?php echo (int)$item['source_id']; ?>">
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
                                    <input type="checkbox" name="assignee_employee_id" id="taskAssignToMeToggle" value="<?php echo (int)$currentEmployee['id']; ?>" class="w-4 h-4">
                                    나에게
                                </label>
                            </div>
                        <?php endif; ?>
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
                            <div class="text-sm font-bold text-gray-700 mb-1">중요도</div>
                            <select name="priority" id="taskPrioritySelect" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <?php foreach (cpms_tasks_priority_options() as $priorityValue => $priorityLabel): ?>
                                    <option value="<?php echo h($priorityValue); ?>" <?php echo ($priorityValue === 'normal') ? 'selected' : ''; ?>><?php echo h($priorityLabel); ?></option>
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
                            <div class="text-xs text-gray-500 mt-1">회의 요청자는 자동으로 참석자에 포함됩니다.</div>
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
    <script>
    (function(){
        var taskDueDate = document.getElementById('taskDueDate');
        var taskDueTime = document.getElementById('taskDueTime');
        var taskUrgentToggle = document.getElementById('taskUrgentToggle');
        var taskPrioritySelect = document.getElementById('taskPrioritySelect');
        var assigneeSearch = document.getElementById('taskAssigneeSearch');
        var assigneeSelect = document.getElementById('taskAssigneeSelect');
        var meetingAssigneeSearch = document.getElementById('meetingAssigneeSearch');
        var meetingAssigneeSelect = document.getElementById('meetingAssigneeSelect');
        var assigneeSelected = document.getElementById('taskAssigneeSelected');
        var meetingAssigneeSelected = document.getElementById('meetingAssigneeSelected');
        var assignToMeToggle = document.getElementById('taskAssignToMeToggle');
        var departmentSelect = document.getElementById('taskDepartmentSelect');
        var onLeaveMessage = <?php echo json_encode(approval_ko('%EC%84%A0%ED%83%9D%ED%95%9C%20%EB%8B%B4%EB%8B%B9%EC%9E%90%EB%8A%94%20%ED%98%84%EC%9E%AC%20%ED%9C%B4%EA%B0%80%EC%A4%91%EC%9D%B4%EB%AF%80%EB%A1%9C%20%EC%97%85%EB%AC%B4%EC%9A%94%EC%B2%AD%EC%9D%84%20%ED%95%A0%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?>;

        function todayString() {
            var now = new Date();
            var month = String(now.getMonth() + 1);
            var day = String(now.getDate());
            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;
            return now.getFullYear() + '-' + month + '-' + day;
        }
        function applyUrgentDefaults() {
            if (taskDueDate) taskDueDate.value = todayString();
            if (taskDueTime && !taskDueTime.value) taskDueTime.value = '18:00';
        }
        if (taskUrgentToggle) {
            taskUrgentToggle.addEventListener('change', function(){
                if (!taskUrgentToggle.checked) return;
                if (taskPrioritySelect) taskPrioritySelect.value = 'urgent';
                applyUrgentDefaults();
            });
        }
        if (taskPrioritySelect) {
            taskPrioritySelect.addEventListener('change', function(){
                if (taskPrioritySelect.value !== 'urgent') return;
                if (taskUrgentToggle) taskUrgentToggle.checked = true;
                applyUrgentDefaults();
            });
        }
        function selectedOptions(select) {
            var selected = [];
            if (!select || !select.options) return selected;
            for (var i = 0; i < select.options.length; i++) {
                if (select.options[i].selected && select.options[i].value) selected[selected.length] = select.options[i];
            }
            return selected;
        }
        function dispatchChange(select) {
            var eventObj = document.createEvent('HTMLEvents');
            eventObj.initEvent('change', true, false);
            select.dispatchEvent(eventObj);
        }
        function filterOptions(searchInput, select) {
            if (!select || !select.options) return;
            var keyword = searchInput ? searchInput.value.replace(/^\s+|\s+$/g, '').toLowerCase() : '';
            for (var i = 0; i < select.options.length; i++) {
                var option = select.options[i];
                if (!option.value) continue;
                var matched = keyword === '' || option.text.toLowerCase().indexOf(keyword) >= 0;
                option.hidden = !(matched || option.selected);
            }
        }
        function renderChips(select, wrap, emptyText) {
            if (!wrap) return;
            wrap.innerHTML = '';
            var selected = selectedOptions(select);
            if (selected.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'w-full px-3 py-2 rounded-xl bg-gray-50 border border-dashed border-gray-200 text-gray-500';
                empty.textContent = emptyText;
                wrap.appendChild(empty);
                return;
            }
            for (var i = 0; i < selected.length; i++) {
                var chip = document.createElement('span');
                chip.className = 'inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-blue-50 border border-blue-200 text-blue-800 font-bold';
                var label = document.createElement('span');
                label.textContent = (selected[i].text || '').replace(/^\s+|\s+$/g, '');
                chip.appendChild(label);
                var removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'px-2 py-1 rounded-lg bg-white border border-blue-200 text-blue-700';
                removeButton.setAttribute('data-assignee-remove', selected[i].value);
                removeButton.textContent = '삭제';
                chip.appendChild(removeButton);
                wrap.appendChild(chip);
            }
        }
        function findOption(select, value) {
            if (!select || !select.options) return null;
            value = String(value);
            for (var i = 0; i < select.options.length; i++) {
                if (String(select.options[i].value) === value) return select.options[i];
            }
            return null;
        }
        function setupPicker(searchInput, select, targetDepartmentSelect, emptyMessage, wrap, wrapEmptyText, fallbackCheckbox) {
            if (!select) return;
            if (searchInput) searchInput.addEventListener('input', function(){ filterOptions(searchInput, select); renderChips(select, wrap, wrapEmptyText); });
            select.addEventListener('mousedown', function(e){
                var option = e.target && e.target.tagName === 'OPTION' ? e.target : null;
                if (!option || !option.value || option.disabled) return;
                e.preventDefault();
                select.focus();
                option.selected = !option.selected;
                dispatchChange(select);
            });
            select.addEventListener('change', function(){
                var selected = selectedOptions(select);
                var firstAvailable = null;
                for (var i = 0; i < selected.length; i++) {
                    if (selected[i].getAttribute('data-on-leave') === '1') {
                        alert(onLeaveMessage);
                        selected[i].selected = false;
                        continue;
                    }
                    if (!firstAvailable) firstAvailable = selected[i];
                }
                if (targetDepartmentSelect && targetDepartmentSelect.value === '' && firstAvailable) targetDepartmentSelect.value = firstAvailable.getAttribute('data-department') || '';
                filterOptions(searchInput, select);
                renderChips(select, wrap, wrapEmptyText);
            });
            if (wrap) {
                wrap.addEventListener('click', function(e){
                    var button = e.target && e.target.closest ? e.target.closest('[data-assignee-remove]') : null;
                    if (!button) return;
                    e.preventDefault();
                    var value = button.getAttribute('data-assignee-remove');
                    var option = findOption(select, value);
                    if (option) option.selected = false;
                    if (fallbackCheckbox && String(value) === String(fallbackCheckbox.value)) fallbackCheckbox.checked = false;
                    dispatchChange(select);
                });
            }
            if (select.form) {
                select.form.addEventListener('submit', function(e){
                    var selected = selectedOptions(select);
                    var hasFallback = fallbackCheckbox && fallbackCheckbox.checked && fallbackCheckbox.value;
                    if (selected.length === 0 && !hasFallback) {
                        e.preventDefault();
                        alert(emptyMessage);
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
            filterOptions(searchInput, select);
            renderChips(select, wrap, wrapEmptyText);
        }
        if (assignToMeToggle && assigneeSelect) {
            assignToMeToggle.addEventListener('change', function(){
                var option = findOption(assigneeSelect, assignToMeToggle.value);
                if (!option || option.disabled) return;
                if (assignToMeToggle.checked) {
                    option.selected = true;
                    option.setAttribute('data-selected-by-me', '1');
                } else if (option.getAttribute('data-selected-by-me') === '1') {
                    option.selected = false;
                    option.removeAttribute('data-selected-by-me');
                }
                dispatchChange(assigneeSelect);
            });
        }
        setupPicker(assigneeSearch, assigneeSelect, departmentSelect, '담당자를 선택해주세요.', assigneeSelected, '선택된 담당자가 없습니다.', assignToMeToggle);
        setupPicker(meetingAssigneeSearch, meetingAssigneeSelect, null, '참석자를 선택해주세요.', meetingAssigneeSelected, '선택된 참석자가 없습니다.', null);
    })();
    </script>
    <?php
}}

if (!function_exists('cpms_render_employee_task_dashboard')) {
function cpms_render_employee_task_dashboard($pdo, $options = array())
{
    if (!is_array($options)) $options = array();
    $setupResults = array();
    cpms_tasks_ensure_schema($pdo, $setupResults);
    $currentEmployee = cpms_tasks_current_employee($pdo);
    if ((int)$currentEmployee['id'] <= 0) return;

    $feed = cpms_task_feed_for_employee($pdo, (int)$currentEmployee['id'], isset($currentEmployee['email']) ? $currentEmployee['email'] : '', $currentEmployee);
    $requestedTaskDate = isset($_GET['requested_task_date']) ? trim((string)$_GET['requested_task_date']) : cpms_tasks_today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedTaskDate)) $requestedTaskDate = cpms_tasks_today();
    $requested = cpms_task_feed_direct_tasks_requested_by_employee($pdo, (int)$currentEmployee['id'], $requestedTaskDate);
    $completedRequested = cpms_task_feed_completed_requests_for_employee($pdo, (int)$currentEmployee['id']);
    $employees = cpms_tasks_fetch_active_employees($pdo);
    $projects = cpms_tasks_fetch_projects($pdo);
    $returnUrl = isset($options['return_url']) ? trim((string)$options['return_url']) : '';
    if ($returnUrl === '') $returnUrl = cpms_tasks_default_return_url();
    if (isset($options['form_hidden_inputs']) && is_array($options['form_hidden_inputs'])) {
        $dashboardHiddenInputs = $options['form_hidden_inputs'];
    } else {
        $dashboardHiddenInputs = array('r' => 'dashboard_employee');
        $currentDashboardRoute = isset($_GET['r']) ? trim((string)$_GET['r']) : '';
        if ($currentDashboardRoute === 'dashboard_executive') {
            $dashboardHiddenInputs = array('r' => 'dashboard_executive');
            $currentExecTab = isset($_GET['exec_tab']) ? trim((string)$_GET['exec_tab']) : 'myTasks';
            if ($currentExecTab !== '') $dashboardHiddenInputs['exec_tab'] = $currentExecTab;
        }
    }
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
    $kanbanLanes = array(
        'pending' => array(),
        'progress' => array(),
        'completion_pending' => array(),
        'done' => array(),
        'rejected' => array(),
    );

    $kanbanSeen = array();
    foreach ($feed as $item) {
        if (cpms_task_kanban_should_include($item)) {
            $laneKey = cpms_task_kanban_lane_key($item);
            if (!isset($kanbanLanes[$laneKey])) $laneKey = 'pending';
            $uniqueKey = cpms_task_kanban_unique_key($item);
            if ($uniqueKey === '' || !isset($kanbanSeen[$uniqueKey])) {
                if ($uniqueKey !== '') $kanbanSeen[$uniqueKey] = true;
                $kanbanLanes[$laneKey][count($kanbanLanes[$laneKey])] = $item;
            }
        }
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
    for ($i = 0; $i < count($completedRequested); $i++) {
        $requestedDone = $completedRequested[$i];
        if (!cpms_task_kanban_should_include($requestedDone)) continue;
        $uniqueKey = cpms_task_kanban_unique_key($requestedDone);
        if ($uniqueKey !== '' && isset($kanbanSeen[$uniqueKey])) continue;
        if ($uniqueKey !== '') $kanbanSeen[$uniqueKey] = true;
        $kanbanLanes['done'][count($kanbanLanes['done'])] = $requestedDone;
    }
    foreach ($kanbanLanes as $kanbanLaneKey => $kanbanItems) {
        usort($kanbanLanes[$kanbanLaneKey], 'cpms_task_kanban_sort');
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

        <div data-cpms-employee-task-body class="mt-6 space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-5 gap-4" data-task-kanban-board data-csrf="<?php echo h(csrf_token()); ?>">
                <?php cpms_render_requested_task_kanban_lane($requested, (int)$currentEmployee['id'], $requestedTaskDate, $dashboardHiddenInputs, $returnUrl); ?>
                <?php cpms_render_task_kanban_lane('pending', '대기중', $kanbanLanes['pending'], (int)$currentEmployee['id']); ?>
                <?php cpms_render_task_kanban_lane('progress', '진행중', $kanbanLanes['progress'], (int)$currentEmployee['id']); ?>
                <?php cpms_render_task_kanban_lane('completion_pending', '완료 대기중', $kanbanLanes['completion_pending'], (int)$currentEmployee['id']); ?>
                <?php cpms_render_task_kanban_lane('done', '완료', $kanbanLanes['done'], (int)$currentEmployee['id']); ?>
            </div>
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
                            <div class="text-sm font-bold text-gray-700 mb-1">중요도</div>
                            <select name="priority" id="taskPrioritySelect" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <?php foreach (cpms_tasks_priority_options() as $priorityValue => $priorityLabel): ?>
                                    <option value="<?php echo h($priorityValue); ?>" <?php echo ($priorityValue === 'normal') ? 'selected' : ''; ?>><?php echo h($priorityLabel); ?></option>
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
            <div class="w-full max-w-4xl max-h-[90vh] rounded-3xl bg-white shadow-2xl border border-gray-100 overflow-hidden flex flex-col">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="text-2xl font-extrabold text-gray-900">완료 처리</div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="taskComplete">닫기</button>
                </div>
                <form method="post" action="?r=tasks/complete" enctype="multipart/form-data" class="p-6 space-y-4 overflow-y-auto">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" id="taskCompleteTaskId" value="">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-2">업무 상세</div>
                        <div id="taskCompleteDetailBody" class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                            <div class="text-sm text-gray-500">업무 정보를 불러오는 중입니다.</div>
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-1">처리 내용 댓글</div>
                        <textarea name="completed_memo" rows="4" required class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="어떻게 처리했는지 남겨주세요."></textarea>
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
                <form method="post" action="?r=task_meeting_response" class="p-6 space-y-4" data-task-meeting-response-form>
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

    <div id="modal-taskCompletionReject" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="taskCompletionReject"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-3xl bg-white shadow-2xl border border-gray-100 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="text-2xl font-extrabold text-gray-900">완료 반려</div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="taskCompletionReject">닫기</button>
                </div>
                <form method="post" action="?r=tasks/completion_reject" class="p-6 space-y-4">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" id="taskCompletionRejectTaskId" value="">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-1">피드백</div>
                        <textarea name="feedback" rows="4" required class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="보완해야 할 내용을 남겨주세요."></textarea>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">새 마감일</div>
                            <input type="date" name="due_date" id="taskCompletionRejectDueDate" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">새 마감시간</div>
                            <input type="time" name="due_time" id="taskCompletionRejectDueTime" value="18:00" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-3 rounded-2xl border border-gray-200 font-bold" data-modal-close="taskCompletionReject">취소</button>
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-rose-600 text-white font-extrabold">반려</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="modal-taskDueUpdate" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="taskDueUpdate"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-3xl bg-white shadow-2xl border border-gray-100 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="text-2xl font-extrabold text-gray-900">마감 수정</div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="taskDueUpdate">닫기</button>
                </div>
                <form method="post" action="?r=tasks/due_update" class="p-6 space-y-4">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" id="taskDueUpdateTaskId" value="">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">마감일</div>
                            <input type="date" name="due_date" id="taskDueUpdateDueDate" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">마감시간</div>
                            <input type="time" name="due_time" id="taskDueUpdateDueTime" value="18:00" class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-1">메모</div>
                        <textarea name="message" rows="3" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="마감 변경 사유를 남길 수 있습니다."></textarea>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-3 rounded-2xl border border-gray-200 font-bold" data-modal-close="taskDueUpdate">취소</button>
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">저장</button>
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
        var taskPrioritySelect = document.getElementById('taskPrioritySelect');
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
        var taskCompleteDetailBody = document.getElementById('taskCompleteDetailBody');
        var completeTaskId = document.getElementById('taskCompleteTaskId');
        var meetingUnavailableTaskId = document.getElementById('meetingUnavailableTaskId');
        var revisionTaskId = document.getElementById('taskRevisionTaskId');
        var revisionDueDate = document.getElementById('taskRevisionDueDate');
        var revisionDueTime = document.getElementById('taskRevisionDueTime');
        var completionRejectTaskId = document.getElementById('taskCompletionRejectTaskId');
        var completionRejectDueDate = document.getElementById('taskCompletionRejectDueDate');
        var completionRejectDueTime = document.getElementById('taskCompletionRejectDueTime');
        var dueUpdateTaskId = document.getElementById('taskDueUpdateTaskId');
        var dueUpdateDueDate = document.getElementById('taskDueUpdateDueDate');
        var dueUpdateDueTime = document.getElementById('taskDueUpdateDueTime');

        function todayString() {
            var now = new Date();
            var month = (now.getMonth() + 1).toString();
            var day = now.getDate().toString();
            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;
            return now.getFullYear() + '-' + month + '-' + day;
        }

        function applyUrgentDefaults() {
            if (taskDueDate) taskDueDate.value = todayString();
            if (taskDueTime && !taskDueTime.value) taskDueTime.value = '18:00';
        }

        if (taskUrgentToggle) {
            taskUrgentToggle.addEventListener('change', function(){
                if (!taskUrgentToggle.checked) return;
                if (taskPrioritySelect) taskPrioritySelect.value = 'urgent';
                applyUrgentDefaults();
            });
        }
        if (taskPrioritySelect) {
            taskPrioritySelect.addEventListener('change', function(){
                if (taskPrioritySelect.value !== 'urgent') return;
                if (taskUrgentToggle) taskUrgentToggle.checked = true;
                applyUrgentDefaults();
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
            if (modal) {
                var memo = modal.querySelector ? modal.querySelector('textarea[name="completed_memo"]') : null;
                var files = modal.querySelector ? modal.querySelector('input[type="file"]') : null;
                if (memo) memo.value = '';
                if (files) files.value = '';
            }
            if (taskCompleteDetailBody) {
                taskCompleteDetailBody.innerHTML = '<div class="text-sm text-gray-500">업무 정보를 불러오는 중입니다.</div>';
                var xhr = new XMLHttpRequest();
                var detailUrl = '?r=tasks/detail&id=' + encodeURIComponent(taskId) + '&modal=1&readonly=1';
                detailUrl += '&return_url=' + encodeURIComponent(window.location.pathname + window.location.search);
                xhr.open('GET', detailUrl, true);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState !== 4) return;
                    if (xhr.status >= 200 && xhr.status < 300) taskCompleteDetailBody.innerHTML = xhr.responseText;
                    else taskCompleteDetailBody.innerHTML = '<div class="text-sm text-red-600">업무 정보를 불러오지 못했습니다.</div>';
                    if (window.lucide) { try { lucide.createIcons(); } catch (err) {} }
                };
                xhr.send(null);
            }
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

        function openCompletionRejectModal(taskId, dueDate, dueTime) {
            if (completionRejectTaskId) completionRejectTaskId.value = taskId;
            if (completionRejectDueDate) completionRejectDueDate.value = dueDate || '';
            if (completionRejectDueTime) completionRejectDueTime.value = dueTime || '18:00';
            var modal = document.getElementById('modal-taskCompletionReject');
            if (modal) {
                var feedback = modal.querySelector ? modal.querySelector('textarea[name="feedback"]') : null;
                if (feedback) feedback.value = '';
                modal.classList.remove('hidden');
            }
        }

        function openDueUpdateModal(taskId, dueDate, dueTime) {
            if (dueUpdateTaskId) dueUpdateTaskId.value = taskId;
            if (dueUpdateDueDate) dueUpdateDueDate.value = dueDate || '';
            if (dueUpdateDueTime) dueUpdateDueTime.value = dueTime || '18:00';
            var modal = document.getElementById('modal-taskDueUpdate');
            if (modal) {
                var message = modal.querySelector ? modal.querySelector('textarea[name="message"]') : null;
                if (message) message.value = '';
                modal.classList.remove('hidden');
            }
        }

        function encodeFormData(form) {
            var pairs = [];
            var elements = form ? form.elements : [];
            for (var i = 0; i < elements.length; i++) {
                var el = elements[i];
                if (!el.name || el.disabled) continue;
                if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
                pairs[pairs.length] = encodeURIComponent(el.name) + '=' + encodeURIComponent(el.value);
            }
            return pairs.join('&');
        }

        function parseJsonResponseText(text) {
            text = text || '';
            try { return JSON.parse(text || '{}'); } catch (err) {}
            var start = text.indexOf('{');
            var end = text.lastIndexOf('}');
            if (start >= 0 && end > start) {
                try { return JSON.parse(text.substring(start, end + 1)); } catch (err2) {}
            }
            return null;
        }

        function decodePostPart(value) {
            value = String(value || '').replace(/\+/g, ' ');
            try { return decodeURIComponent(value); } catch (err) { return value; }
        }

        function postEncoded(url, body, callback) {
            var frameName = 'cpmsTaskPostFrame' + String(new Date().getTime()) + String(Math.floor(Math.random() * 100000));
            var iframe = document.createElement('iframe');
            iframe.name = frameName;
            iframe.style.display = 'none';
            iframe.setAttribute('aria-hidden', 'true');

            var form = document.createElement('form');
            form.method = 'post';
            form.action = url;
            form.target = frameName;
            form.enctype = 'application/x-www-form-urlencoded';
            form.acceptCharset = 'UTF-8';
            form.style.display = 'none';

            var parts = String(body || '').split('&');
            for (var i = 0; i < parts.length; i++) {
                if (parts[i] === '') continue;
                var eq = parts[i].indexOf('=');
                var name = eq >= 0 ? parts[i].substring(0, eq) : parts[i];
                var value = eq >= 0 ? parts[i].substring(eq + 1) : '';
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = decodePostPart(name);
                input.value = decodePostPart(value);
                form.appendChild(input);
            }

            var submitted = false;
            var completed = false;
            iframe.onload = function() {
                if (!submitted || completed) return;
                var rawText = '';
                try {
                    var doc = iframe.contentDocument || (iframe.contentWindow ? iframe.contentWindow.document : null);
                    if (doc && doc.body) rawText = doc.body.textContent || doc.body.innerText || '';
                } catch (err) {
                    rawText = '';
                }
                var response = parseJsonResponseText(rawText || '');
                if (!rawText && !response) return;
                completed = true;
                var statusCode = ajaxOk(response) ? 200 : 400;
                callback(statusCode, response, rawText || '');
                setTimeout(function(){
                    try { if (form.parentNode) form.parentNode.removeChild(form); } catch (err2) {}
                    try { if (iframe.parentNode) iframe.parentNode.removeChild(iframe); } catch (err3) {}
                }, 0);
            };

            document.body.appendChild(iframe);
            document.body.appendChild(form);
            submitted = true;
            try {
                form.submit();
            } catch (err4) {
                callback(0, null, err4 && err4.message ? err4.message : '');
            }
        }

        function ajaxOk(response) {
            return !!(response && (response.ok === true || response.ok === 1 || response.ok === '1'));
        }

        function ajaxMessage(response, fallback, statusCode, rawText) {
            if (response && response.message) return response.message;
            rawText = rawText || '';
            if (rawText) {
                var plain = rawText.replace(/<script[\s\S]*?<\/script>/gi, ' ')
                    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
                    .replace(/<[^>]+>/g, ' ')
                    .replace(/\s+/g, ' ')
                    .replace(/^\s+|\s+$/g, '');
                if (plain) return fallback + ' 서버응답: ' + plain.substring(0, 160);
            }
            if (statusCode || statusCode === 0) return fallback + ' HTTP ' + statusCode;
            return fallback;
        }

        function setupKanbanDragDropLegacy() {
            var board = document.querySelector('[data-task-kanban-board]');
            if (!board) return;
            var csrf = board.getAttribute('data-csrf') || '';
            var dragged = null;
            board.addEventListener('dragstart', function(e){
                var card = e.target && e.target.closest ? e.target.closest('[data-kanban-card]') : null;
                if (!card || card.getAttribute('draggable') !== 'true') return;
                dragged = card;
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', card.getAttribute('data-task-id') || '');
                }
                card.classList.add('opacity-60');
            });
            board.addEventListener('dragend', function(){
                if (dragged) dragged.classList.remove('opacity-60');
                dragged = null;
            });
            board.addEventListener('dragover', function(e){
                var drop = e.target && e.target.closest ? e.target.closest('[data-kanban-drop]') : null;
                if (!drop || !dragged) return;
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                drop.classList.add('ring-2', 'ring-blue-300');
            });
            board.addEventListener('dragleave', function(e){
                var drop = e.target && e.target.closest ? e.target.closest('[data-kanban-drop]') : null;
                if (drop) drop.classList.remove('ring-2', 'ring-blue-300');
            });
            board.addEventListener('drop', function(e){
                var drop = e.target && e.target.closest ? e.target.closest('[data-kanban-drop]') : null;
                if (!drop || !dragged) return;
                e.preventDefault();
                drop.classList.remove('ring-2', 'ring-blue-300');
                var taskId = dragged.getAttribute('data-task-id') || '';
                var status = drop.getAttribute('data-kanban-drop') || '';
                if (!taskId || !status) return;
                var empty = drop.querySelector('[data-kanban-empty]');
                if (empty) empty.parentNode.removeChild(empty);
                drop.appendChild(dragged);
                postEncoded('?r=task_progress', '_csrf=' + encodeURIComponent(csrf) + '&ajax=1&task_id=' + encodeURIComponent(taskId) + '&status=' + encodeURIComponent(status), function(statusCode, response, rawText){
                    if (statusCode < 200 || statusCode >= 300 || !ajaxOk(response)) {
                        alert(ajaxMessage(response, '업무 상태 변경에 실패했습니다.', statusCode, rawText));
                        return;
                    }
                    return;
                });
            });
        }

        function getKanbanBoard() {
            return document.querySelector('[data-task-kanban-board]');
        }

        function getKanbanDropFromTarget(target) {
            if (!target || !target.closest) return null;
            var drop = target.closest('[data-kanban-drop]');
            if (drop) return drop;
            var lane = target.closest('[data-kanban-lane]');
            return lane ? lane.querySelector('[data-kanban-drop]') : null;
        }

        function clearKanbanDropHighlights() {
            var board = getKanbanBoard();
            if (!board) return;
            var drops = board.querySelectorAll('[data-kanban-drop]');
            for (var i = 0; i < drops.length; i++) {
                drops[i].classList.remove('ring-2', 'ring-blue-300');
            }
        }

        function removeKanbanEmpty(drop) {
            if (!drop) return;
            var empty = drop.querySelector('[data-kanban-empty]');
            if (empty && empty.parentNode) empty.parentNode.removeChild(empty);
        }

        function ensureKanbanEmpty(drop) {
            if (!drop) return;
            if (drop.querySelector('[data-kanban-card]') || drop.querySelector('[data-kanban-empty]')) return;
            var empty = document.createElement('div');
            empty.className = 'p-4 rounded-2xl border border-dashed border-gray-300 bg-white text-sm text-gray-500';
            empty.setAttribute('data-kanban-empty', '');
            empty.textContent = '표시할 업무가 없습니다.';
            drop.appendChild(empty);
        }

        function updateKanbanLaneCount(drop) {
            if (!drop || !drop.closest) return;
            var lane = drop.closest('[data-kanban-lane]');
            var countNode = lane ? lane.querySelector('[data-kanban-count]') : null;
            if (countNode) countNode.textContent = drop.querySelectorAll('[data-kanban-card]').length + '건';
        }

        function updateKanbanCounts(a, b) {
            updateKanbanLaneCount(a);
            if (b && b !== a) updateKanbanLaneCount(b);
        }

        function kanbanPriorityRank(priority) {
            priority = String(priority || 'normal');
            if (priority === 'urgent') return 0;
            if (priority === 'high') return 1;
            if (priority === 'normal') return 2;
            if (priority === 'low') return 3;
            return 2;
        }

        function kanbanCardSortValue(card) {
            var urgent = card && card.getAttribute('data-kanban-is-urgent') === '1' ? 0 : 1;
            var delayed = card && card.getAttribute('data-kanban-delayed') === '1' ? 0 : 1;
            var rank = parseInt(card ? (card.getAttribute('data-kanban-priority-rank') || '2') : '2', 10);
            if (isNaN(rank)) rank = 2;
            var created = card ? (card.getAttribute('data-kanban-created') || '') : '';
            var createdTs = created ? Date.parse(created.replace(' ', 'T')) : 0;
            if (isNaN(createdTs)) createdTs = 0;
            var id = parseInt(card ? (card.getAttribute('data-task-id') || '0') : '0', 10);
            if (isNaN(id)) id = 0;
            return [urgent, delayed, rank, createdTs || 9999999999999, id];
        }

        function compareKanbanCards(a, b) {
            var av = kanbanCardSortValue(a);
            var bv = kanbanCardSortValue(b);
            for (var i = 0; i < av.length; i++) {
                if (av[i] < bv[i]) return -1;
                if (av[i] > bv[i]) return 1;
            }
            return 0;
        }

        function sortKanbanDrop(drop) {
            if (!drop) return;
            var cards = [];
            var nodes = drop.querySelectorAll('[data-kanban-card]');
            for (var i = 0; i < nodes.length; i++) cards[cards.length] = nodes[i];
            cards.sort(compareKanbanCards);
            for (var j = 0; j < cards.length; j++) drop.appendChild(cards[j]);
            ensureKanbanEmpty(drop);
        }

        function moveKanbanCard(card, drop) {
            if (!card || !drop) return null;
            var oldDrop = card.parentNode;
            removeKanbanEmpty(drop);
            drop.appendChild(card);
            ensureKanbanEmpty(oldDrop);
            updateKanbanCounts(oldDrop, drop);
            sortKanbanDrop(drop);
            return oldDrop;
        }

        function restoreKanbanCard(card, originDrop, beforeNode, currentDrop) {
            if (!card || !originDrop) return;
            removeKanbanEmpty(originDrop);
            if (beforeNode && beforeNode.parentNode === originDrop) originDrop.insertBefore(card, beforeNode);
            else originDrop.appendChild(card);
            ensureKanbanEmpty(currentDrop);
            updateKanbanCounts(originDrop, currentDrop);
        }

        function findKanbanDropByLane(laneKey) {
            var board = getKanbanBoard();
            if (!board) return null;
            var drops = board.querySelectorAll('[data-kanban-drop]');
            for (var i = 0; i < drops.length; i++) {
                if ((drops[i].getAttribute('data-kanban-drop') || '') === String(laneKey)) return drops[i];
            }
            return null;
        }

        function findKanbanCard(taskId) {
            var board = getKanbanBoard();
            if (!board) return null;
            var cards = board.querySelectorAll('[data-kanban-card]');
            for (var i = 0; i < cards.length; i++) {
                if ((cards[i].getAttribute('data-task-id') || '') === String(taskId)) return cards[i];
            }
            return null;
        }

        function updateKanbanCardStatus(card, response, laneKey) {
            if (!card) return;
            if (laneKey) card.setAttribute('data-kanban-status', String(laneKey));
            var badge = card.querySelector('[data-kanban-status-badge]');
            if (badge && response) {
                if (response.status_class) {
                    badge.className = 'px-2.5 py-1 rounded-full border text-xs font-extrabold ' + response.status_class;
                }
                if (response.display_status || response.status_label) {
                    badge.textContent = response.display_status || response.status_label;
                }
            }
            updateKanbanCardActions(card, laneKey || card.getAttribute('data-kanban-status') || '');
            var detailBadges = document.querySelectorAll('[data-task-status-badge]');
            for (var i = 0; i < detailBadges.length; i++) {
                if (response && response.status_class) detailBadges[i].className = 'px-3 py-1 rounded-full border text-xs font-bold ' + response.status_class;
                if (response && (response.display_status || response.status_label)) detailBadges[i].textContent = response.display_status || response.status_label;
            }
        }

        function updateKanbanCardActions(card, laneKey) {
            if (!card) return;
            laneKey = String(laneKey || '');
            var buttons = card.querySelectorAll('[data-kanban-status-action]');
            for (var i = 0; i < buttons.length; i++) {
                var target = buttons[i].getAttribute('data-kanban-status-action') || '';
                var hide = laneKey === 'done' || laneKey === 'completion_pending' || target === laneKey;
                if (hide) buttons[i].classList.add('hidden');
                else buttons[i].classList.remove('hidden');
            }
        }

        function applyKanbanResponse(taskId, response, fallbackLane) {
            var card = findKanbanCard(taskId);
            if (!card) return;
            var laneKey = response && response.lane_key ? response.lane_key : fallbackLane;
            var drop = laneKey ? findKanbanDropByLane(laneKey) : null;
            if (drop && card.parentNode !== drop) moveKanbanCard(card, drop);
            updateKanbanCardStatus(card, response, laneKey);
            if (card.parentNode) sortKanbanDrop(card.parentNode);
        }

        function setKanbanCardBusy(card, busy) {
            if (!card) return;
            var buttons = card.querySelectorAll('[data-kanban-status-action]');
            for (var i = 0; i < buttons.length; i++) buttons[i].disabled = !!busy;
        }

        function submitKanbanStatusChange(card, status) {
            if (!card || !status) return;
            if (status === 'done' || status === 'completion_pending') {
                openCompleteModal(card.getAttribute('data-task-id') || '');
                return;
            }
            var targetDrop = findKanbanDropByLane(status);
            if (!targetDrop || card.parentNode === targetDrop) return;
            var board = getKanbanBoard();
            var csrf = board ? (board.getAttribute('data-csrf') || '') : '';
            var oldDrop = card.parentNode;
            var oldNext = card.nextSibling;
            var taskId = card.getAttribute('data-task-id') || '';
            if (!taskId) return;
            setKanbanCardBusy(card, true);
            moveKanbanCard(card, targetDrop);
            postEncoded('?r=task_progress', '_csrf=' + encodeURIComponent(csrf) + '&ajax=1&task_id=' + encodeURIComponent(taskId) + '&status=' + encodeURIComponent(status), function(statusCode, response, rawText){
                setKanbanCardBusy(card, false);
                if (statusCode < 200 || statusCode >= 300 || !ajaxOk(response)) {
                    restoreKanbanCard(card, oldDrop, oldNext, targetDrop);
                    alert(ajaxMessage(response, '업무변경에 실패했습니다.', statusCode, rawText));
                    return;
                }
                applyKanbanResponse(taskId, response, status);
            });
        }

        function updateTaskDetailPriority(response) {
            if (!response) return;
            var badges = document.querySelectorAll('[data-task-priority-badge]');
            for (var i = 0; i < badges.length; i++) {
                if (response.priority_class) badges[i].className = 'px-3 py-1 rounded-full border text-xs font-bold ' + response.priority_class;
                if (response.priority_label) badges[i].textContent = response.priority_label;
            }
            var urgentChips = document.querySelectorAll('[data-task-urgent-chip]');
            for (var j = 0; j < urgentChips.length; j++) {
                if (String(response.is_urgent || '0') === '1') urgentChips[j].classList.remove('hidden');
                else urgentChips[j].classList.add('hidden');
            }
        }

        function updateKanbanCardPriority(card, response) {
            if (!card || !response) return;
            var priority = response.priority || 'normal';
            card.setAttribute('data-kanban-priority', priority);
            card.setAttribute('data-kanban-priority-rank', String(kanbanPriorityRank(priority)));
            card.setAttribute('data-kanban-is-urgent', String(response.is_urgent || '0') === '1' ? '1' : '0');
            var badge = card.querySelector('[data-kanban-priority-badge]');
            if (badge) {
                if (response.priority_class) badge.className = 'px-2.5 py-1 rounded-full border text-xs font-extrabold ' + response.priority_class;
                if (response.priority_label) badge.textContent = response.priority_label;
            }
            var flags = card.querySelector('[data-kanban-flags]');
            var urgentChip = card.querySelector('[data-kanban-urgent-chip]');
            if (String(response.is_urgent || '0') === '1') {
                if (!urgentChip && flags) {
                    urgentChip = document.createElement('span');
                    urgentChip.className = 'px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-xs font-extrabold';
                    urgentChip.setAttribute('data-kanban-urgent-chip', '');
                    urgentChip.textContent = '긴급';
                    flags.insertBefore(urgentChip, flags.firstChild);
                }
            } else if (urgentChip && urgentChip.parentNode) {
                urgentChip.parentNode.removeChild(urgentChip);
            }
            if (card.parentNode) sortKanbanDrop(card.parentNode);
        }

        function applyPriorityResponse(taskId, response) {
            updateTaskDetailPriority(response);
            var card = findKanbanCard(taskId || (response && response.task_id ? response.task_id : ''));
            if (card) updateKanbanCardPriority(card, response);
        }

        function setupKanbanDragDrop() {
            var board = getKanbanBoard();
            if (!board) return;
            var csrf = board.getAttribute('data-csrf') || '';
            var dragged = null;
            var originDrop = null;
            var originNext = null;
            board.addEventListener('dragstart', function(e){
                var card = e.target && e.target.closest ? e.target.closest('[data-kanban-card]') : null;
                if (!card || card.getAttribute('draggable') !== 'true') return;
                dragged = card;
                originDrop = card.parentNode;
                originNext = card.nextSibling;
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', card.getAttribute('data-task-id') || '');
                }
                card.classList.add('opacity-60');
            });
            board.addEventListener('dragend', function(){
                if (dragged) dragged.classList.remove('opacity-60');
                clearKanbanDropHighlights();
                dragged = null;
                originDrop = null;
                originNext = null;
            });
            board.addEventListener('dragover', function(e){
                var drop = getKanbanDropFromTarget(e.target);
                if (!drop || !dragged) return;
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                clearKanbanDropHighlights();
                drop.classList.add('ring-2', 'ring-blue-300');
            });
            board.addEventListener('drop', function(e){
                var drop = getKanbanDropFromTarget(e.target);
                if (!drop || !dragged) return;
                e.preventDefault();
                clearKanbanDropHighlights();
                var card = dragged;
                var oldDrop = originDrop;
                var oldNext = originNext;
                var taskId = card.getAttribute('data-task-id') || '';
                var status = drop.getAttribute('data-kanban-drop') || '';
                if (!taskId || !status || oldDrop === drop) return;
                submitKanbanStatusChange(card, status);
            });
            board.addEventListener('click', function(e){
                var actionButton = e.target && e.target.closest ? e.target.closest('[data-kanban-status-action]') : null;
                if (!actionButton) return;
                e.preventDefault();
                var card = actionButton.closest ? actionButton.closest('[data-kanban-card]') : null;
                submitKanbanStatusChange(card, actionButton.getAttribute('data-kanban-status-action') || '');
            });
        }

        setupKanbanDragDrop();

        document.addEventListener('submit', function(e){
            var meetingResponseForm = e.target && e.target.closest ? e.target.closest('[data-task-meeting-response-form]') : null;
            if (meetingResponseForm) {
                e.preventDefault();
                var meetingButton = meetingResponseForm.querySelector('button[type="submit"]');
                if (meetingButton) meetingButton.disabled = true;
                postEncoded(meetingResponseForm.getAttribute('action') || '?r=task_meeting_response', encodeFormData(meetingResponseForm) + '&ajax=1', function(statusCode, response, rawText){
                    if (meetingButton) meetingButton.disabled = false;
                    if (statusCode < 200 || statusCode >= 300 || !ajaxOk(response)) {
                        alert(ajaxMessage(response, '회의 응답 처리에 실패했습니다.', statusCode, rawText));
                        return;
                    }
                    var taskId = meetingResponseForm.getAttribute('data-task-id') || '';
                    if (!taskId) {
                        var taskInput = meetingResponseForm.querySelector('input[name="task_id"]');
                        if (taskInput) taskId = taskInput.value;
                    }
                    applyKanbanResponse(taskId, response, response && response.lane_key ? response.lane_key : '');
                    var savedMeetingHtml = '<div class="px-4 py-3 rounded-2xl bg-emerald-50 text-emerald-700 text-sm font-extrabold">회의 응답이 저장되었습니다.</div>';
                    if (taskDetailBody && taskDetailBody.contains && taskDetailBody.contains(meetingResponseForm)) {
                        var responseForms = taskDetailBody.querySelectorAll('[data-task-meeting-response-form]');
                        for (var i = 0; i < responseForms.length; i++) {
                            responseForms[i].innerHTML = savedMeetingHtml;
                        }
                    } else {
                        meetingResponseForm.innerHTML = savedMeetingHtml;
                    }
                    if (taskDetailBody && typeof response.detail_html !== 'undefined') {
                        taskDetailBody.innerHTML = response.detail_html;
                        if (window.lucide) { try { lucide.createIcons(); } catch (err) {} }
                    }
                });
                return;
            }

            var commentForm = e.target && e.target.closest ? e.target.closest('[data-task-comment-form]') : null;
            if (commentForm) {
                e.preventDefault();
                var submitButton = commentForm.querySelector('button[type="submit"]');
                if (submitButton) submitButton.disabled = true;
                postEncoded(commentForm.getAttribute('action') || '?r=task_comment_save', encodeFormData(commentForm) + '&ajax=1', function(statusCode, response, rawText){
                    if (submitButton) submitButton.disabled = false;
                    if (statusCode < 200 || statusCode >= 300 || !ajaxOk(response)) {
                        alert(ajaxMessage(response, '댓글 등록에 실패했습니다.', statusCode, rawText));
                        return;
                    }
                    var wrap = commentForm.closest ? commentForm.closest('[data-task-comments]') : null;
                    if (wrap) {
                        var oldList = wrap.querySelector('[data-task-comments-list]');
                        if (oldList && typeof response.comments_html !== 'undefined') {
                            oldList.outerHTML = response.comments_html;
                        }
                        var countNode = wrap.querySelector('[data-task-comments-count]');
                        if (countNode && typeof response.comment_count !== 'undefined') countNode.textContent = response.comment_count;
                    }
                    commentForm.reset();
                    if (window.lucide) { try { lucide.createIcons(); } catch (err) {} }
                });
                return;
            }

            var priorityForm = e.target && e.target.closest ? e.target.closest('[data-task-priority-form]') : null;
            if (priorityForm) {
                e.preventDefault();
                var message = priorityForm.querySelector('[data-task-priority-message]');
                if (message) message.textContent = '저장 중...';
                postEncoded(priorityForm.getAttribute('action') || '?r=task_priority_save', encodeFormData(priorityForm) + '&ajax=1', function(statusCode, response, rawText){
                    if (statusCode < 200 || statusCode >= 300 || !ajaxOk(response)) {
                        if (message) message.textContent = ajaxMessage(response, '저장 실패', statusCode, rawText);
                        return;
                    }
                    var badge = document.querySelector('[data-task-priority-badge]');
                    if (badge) {
                        badge.className = 'px-3 py-1 rounded-full border text-xs font-bold ' + (response.priority_class || '');
                        badge.textContent = response.priority_label || '보통';
                    }
                    var taskInput = priorityForm.querySelector('input[name="task_id"]');
                    applyPriorityResponse(taskInput ? taskInput.value : '', response);
                    if (message) message.textContent = '저장되었습니다.';
                });
                return;
            }
        });

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

            var rejectButton = e.target && e.target.closest ? e.target.closest('[data-task-completion-reject-open]') : null;
            if (rejectButton) {
                e.preventDefault();
                openCompletionRejectModal(
                    rejectButton.getAttribute('data-task-id'),
                    rejectButton.getAttribute('data-task-due-date'),
                    rejectButton.getAttribute('data-task-due-time')
                );
                return;
            }

            var dueButton = e.target && e.target.closest ? e.target.closest('[data-task-due-open]') : null;
            if (dueButton) {
                e.preventDefault();
                openDueUpdateModal(
                    dueButton.getAttribute('data-task-id'),
                    dueButton.getAttribute('data-task-due-date'),
                    dueButton.getAttribute('data-task-due-time')
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
    if ($selectedDepartment === '임원') $selectedDepartment = '기타';
    $allDepartmentLabel = urldecode('%EC%A0%84%EC%B2%B4');
    $isAllDepartmentSelected = (!isset($_GET['task_department']) || trim((string)$_GET['task_department']) === '' || $selectedDepartment === $allDepartmentLabel);
    if ($isAllDepartmentSelected) {
        $selectedDepartment = $allDepartmentLabel;
        $departmentOptions = array_merge(array($allDepartmentLabel), cpms_tasks_department_options());
        ?>
        <div id="cpmsExecutiveTasksPanel" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
            <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold text-gray-900">부서별 업무 현황</h2>
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
                        if ($departmentName !== $allDepartmentLabel) $url .= '&task_department=' . urlencode($departmentName);
                        ?>
                        <a href="<?php echo h($url); ?>" class="px-4 py-2 rounded-2xl font-bold <?php echo $isSelected ? 'bg-gray-900 text-white' : 'bg-white border border-gray-200 text-gray-700'; ?>">
                            <?php echo h($departmentLabel); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mt-6 p-6 rounded-2xl border border-dashed border-gray-300 bg-slate-50 text-sm font-bold text-gray-600">
                부서를 선택하면 해당 부서 인원의 업무 현황을 불러옵니다.
            </div>
        </div>
        <?php
        return;
    }
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

                    <div id="panel-<?php echo h($modalId); ?>" class="hidden mt-2 mb-4 rounded-2xl border border-gray-200 bg-white shadow-sm" data-cpms-employee-panel style="max-height:78vh;overflow-y:auto;">
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
        function resetPanel(panel) {
            if (!panel) return;
            panel.classList.add('hidden');
        }
        function positionPanel(panel, trigger) {
            if (!panel || !trigger) return;
            if (trigger.parentNode && panel.parentNode && trigger.nextSibling !== panel) {
                trigger.parentNode.insertBefore(panel, trigger.nextSibling);
            }
            panel.classList.remove('hidden');
        }
        function closeAll(exceptId) {
            var panels = document.querySelectorAll('[data-cpms-employee-panel]');
            for (var i = 0; i < panels.length; i++) {
                if (exceptId && panels[i].id === 'panel-' + exceptId) continue;
                resetPanel(panels[i]);
            }
        }
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].addEventListener('click', function(){
                var key = this.getAttribute('data-cpms-employee-toggle');
                var panel = document.getElementById('panel-' + key);
                if (!panel) return;
                var willOpen = panel.classList.contains('hidden');
                closeAll(willOpen ? key : null);
                if (willOpen) positionPanel(panel, this);
                else resetPanel(panel);
            });
        }
        for (var j = 0; j < closeButtons.length; j++) {
            closeButtons[j].addEventListener('click', function(e){
                e.preventDefault();
                var key = this.getAttribute('data-cpms-employee-close');
                var panel = document.getElementById('panel-' + key);
                if (panel) resetPanel(panel);
            });
        }
        document.addEventListener('click', function(e){
            var panelTarget = e.target && e.target.closest ? e.target.closest('[data-cpms-employee-panel]') : null;
            var toggleTarget = e.target && e.target.closest ? e.target.closest('[data-cpms-employee-toggle]') : null;
            if (panelTarget || toggleTarget) return;
            closeAll(null);
        });
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
            var detailUrl = '?r=tasks/detail&id=' + encodeURIComponent(taskId) + '&modal=1&readonly=1&commentable=1';
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
