<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$setupResults = array();
cpms_tasks_ensure_schema($pdo, $setupResults);
$currentEmployee = cpms_tasks_current_employee($pdo);
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$task = cpms_tasks_find_task($pdo, $taskId);
$isModal = isset($_GET['modal']) && (string)$_GET['modal'] === '1';
$readOnlyMode = isset($_GET['readonly']) && (string)$_GET['readonly'] === '1';
$commentsInputAllowed = (!$readOnlyMode || (isset($_GET['commentable']) && (string)$_GET['commentable'] === '1'));

if (!$task || !cpms_tasks_can_view($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    if ($isModal) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-red-600">업무를 찾을 수 없거나 조회 권한이 없습니다.</div>';
        exit;
    }
    flash_set('danger', '업무를 찾을 수 없거나 조회 권한이 없습니다.');
    header('Location: ?r=대시보드');
    exit;
}

cpms_tasks_mark_read($pdo, $task, $currentEmployee);
$task = cpms_tasks_find_task($pdo, $taskId);

$logs = cpms_tasks_fetch_logs($pdo, $taskId);
$files = cpms_tasks_fetch_visible_files($pdo, $task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
$requestFiles = array();
$completeFiles = array();
for ($i = 0; $i < count($files); $i++) {
    if (cpms_tasks_file_effective_role($task, $files[$i]) === 'complete') {
        $completeFiles[count($completeFiles)] = $files[$i];
    } else {
        $requestFiles[count($requestFiles)] = $files[$i];
    }
}
$comments = cpms_tasks_fetch_comments($pdo, $taskId);
$canChangeStatus = cpms_tasks_can_change_status($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
$isMeetingTask = isset($task['task_type']) && (string)$task['task_type'] === 'meeting';
$canMeetingResponse = cpms_tasks_can_respond_meeting($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
$canCompleteMeeting = cpms_tasks_can_complete_meeting_after_response($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
$canRevision = cpms_tasks_can_request_revision($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
$canCancel = cpms_tasks_can_cancel($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
$currentEmployeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
$canEditPriority = (!$readOnlyMode && !$isMeetingTask && $currentEmployeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === $currentEmployeeId);
$hasTransferRequest = cpms_tasks_has_transfer_request($task);
$canTransfer = (!$readOnlyMode && cpms_tasks_can_transfer($task, $currentEmployeeId));
$transferEmployees = $canTransfer ? cpms_tasks_fetch_active_employees($pdo) : array();
$returnUrl = cpms_tasks_default_return_url();
if (isset($_GET['return_url'])) {
    $requestedReturnUrl = trim((string)$_GET['return_url']);
    if ($requestedReturnUrl !== '' && stripos($requestedReturnUrl, 'javascript:') !== 0 && strpos($requestedReturnUrl, "\n") === false && strpos($requestedReturnUrl, "\r") === false) {
        $returnUrl = $requestedReturnUrl;
    }
}
if ($readOnlyMode) {
    $canChangeStatus = false;
    $canMeetingResponse = false;
    $canCompleteMeeting = false;
    $canRevision = false;
    $canCancel = false;
}
if ($isModal && !$isMeetingTask) {
    $canChangeStatus = false;
    $canRevision = false;
    $canCancel = false;
}

ob_start();
?>
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-2" data-task-detail-badges>
                <span class="px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_tasks_badge_class('priority', isset($task['priority']) ? $task['priority'] : 'normal')); ?>" data-task-priority-badge>
                    <?php echo h(cpms_tasks_priority_label(isset($task['priority']) ? $task['priority'] : 'normal')); ?>
                </span>
                <span class="px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_tasks_badge_class('status', cpms_tasks_is_delayed($task) ? 'delayed' : (isset($task['status']) ? $task['status'] : 'pending'))); ?>" data-task-status-badge>
                    <?php echo h(cpms_tasks_display_status($task)); ?>
                </span>
                <span class="px-3 py-1 rounded-full border text-xs font-bold bg-slate-100 text-slate-700 border-slate-200">
                    <?php echo h(cpms_tasks_type_label(isset($task['task_type']) ? $task['task_type'] : 'general')); ?>
                </span>
                <?php if (isset($task['is_urgent']) && (int)$task['is_urgent'] === 1): ?>
                    <span class="px-3 py-1 rounded-full border text-xs font-bold bg-rose-50 text-rose-700 border-rose-200" data-task-urgent-chip>🔥 긴급 요청</span>
                <?php else: ?>
                    <span class="hidden px-3 py-1 rounded-full border text-xs font-bold bg-rose-50 text-rose-700 border-rose-200" data-task-urgent-chip>🔥 긴급 요청</span>
                <?php endif; ?>
            </div>
            <h3 class="text-2xl font-extrabold text-gray-900"><?php echo h(isset($task['title']) ? $task['title'] : ''); ?></h3>
            <?php if ($canEditPriority): ?>
                <form method="post" action="?r=task_priority_save" class="mt-3 flex flex-wrap items-center gap-2" data-task-priority-form>
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <select name="priority" class="px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm font-bold">
                        <?php foreach (cpms_tasks_priority_options() as $priorityValue => $priorityLabel): ?>
                            <option value="<?php echo h($priorityValue); ?>" <?php echo ((isset($task['priority']) ? (string)$task['priority'] : 'normal') === (string)$priorityValue) ? 'selected' : ''; ?>><?php echo h($priorityLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="px-3 py-2 rounded-xl bg-gray-900 text-white text-sm font-extrabold">중요도 저장</button>
                    <span class="text-xs font-bold text-gray-500" data-task-priority-message></span>
                </form>
            <?php endif; ?>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($isMeetingTask && $canMeetingResponse && !in_array(isset($task['status']) ? (string)$task['status'] : '', array('meeting_available', 'meeting_unavailable', 'cancelled'), true)): ?>
                <form method="post" action="?r=task_meeting_response" data-task-meeting-response-form data-task-id="<?php echo (int)$taskId; ?>">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                    <input type="hidden" name="response" value="available">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="px-4 py-2 rounded-2xl bg-emerald-600 text-white font-bold">참석가능</button>
                </form>
            <?php endif; ?>
            <?php if (!$isMeetingTask && $canChangeStatus && isset($task['status']) && (string)$task['status'] === 'pending'): ?>
                <form method="post" action="?r=task_progress" referrerpolicy="origin">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                    <input type="hidden" name="task_state" value="progress">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="px-4 py-2 rounded-2xl bg-blue-600 text-white font-bold">대기</button>
                </form>
            <?php endif; ?>
            <?php if ($isMeetingTask && $canCompleteMeeting): ?>
                <form method="post" action="?r=tasks/complete">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                    <input type="hidden" name="completed_memo" value="">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="px-4 py-2 rounded-2xl bg-emerald-600 text-white font-bold">완료</button>
                </form>
            <?php endif; ?>
            <?php if (!$isMeetingTask && $canChangeStatus && !in_array(isset($task['status']) ? $task['status'] : '', array('completion_pending', 'done', 'cancelled'), true)): ?>
                <button type="button"
                        data-task-complete-open
                        data-task-id="<?php echo (int)$taskId; ?>"
                        class="px-4 py-2 rounded-2xl bg-emerald-600 text-white font-bold">완료 처리</button>
            <?php endif; ?>
            <?php if ($canRevision && isset($task['status']) && (string)$task['status'] === 'done'): ?>
                <button type="button"
                        data-task-revision-open
                        data-task-id="<?php echo (int)$taskId; ?>"
                        data-task-due-date="<?php echo h(isset($task['due_date']) ? $task['due_date'] : ''); ?>"
                        data-task-due-time="<?php echo h(isset($task['due_time']) ? substr((string)$task['due_time'], 0, 5) : '18:00'); ?>"
                        class="px-4 py-2 rounded-2xl bg-amber-500 text-white font-bold">보완요청</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isMeetingTask && $canMeetingResponse && !in_array(isset($task['status']) ? (string)$task['status'] : '', array('meeting_available', 'meeting_unavailable', 'cancelled'), true)): ?>
        <form method="post" action="?r=task_meeting_response" class="p-4 rounded-2xl border border-rose-200 bg-rose-50 space-y-3" data-task-meeting-response-form data-task-id="<?php echo (int)$taskId; ?>">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
            <input type="hidden" name="response" value="unavailable">
            <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
            <div class="text-sm font-extrabold text-rose-800">참석불가능 사유</div>
            <textarea name="reason" rows="3" required class="w-full px-4 py-3 rounded-2xl border border-rose-200 bg-white"></textarea>
            <button type="submit" class="px-4 py-2 rounded-2xl bg-rose-600 text-white font-bold">참석불가능</button>
        </form>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200">
            <div class="text-xs font-bold text-slate-500">요청자</div>
            <div class="mt-1 font-bold text-slate-900"><?php echo h(isset($task['requester_name']) ? $task['requester_name'] : '-'); ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200">
            <div class="text-xs font-bold text-slate-500">담당자</div>
            <div class="mt-1 font-bold text-slate-900"><?php echo h(isset($task['assignee_name']) ? $task['assignee_name'] : '-'); ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200">
            <div class="text-xs font-bold text-slate-500">관련 현장</div>
            <div class="mt-1 font-bold text-slate-900"><?php echo h(isset($task['project_name']) && trim((string)$task['project_name']) !== '' ? $task['project_name'] : '-'); ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200">
            <div class="text-xs font-bold text-slate-500">관련 부서</div>
            <div class="mt-1 font-bold text-slate-900"><?php echo h(isset($task['department']) && trim((string)$task['department']) !== '' ? cpms_tasks_normalize_department($task['department']) : '-'); ?></div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 md:col-span-2">
            <div class="text-xs font-bold text-slate-500"><?php echo $isMeetingTask ? '회의 일자 / 시간' : '마감일 / 시간'; ?></div>
            <div class="mt-1 font-bold text-slate-900">
                <?php
                $dueText = '-';
                if (!empty($task['due_date'])) {
                    $dueText = (string)$task['due_date'];
                    if (!empty($task['due_time'])) $dueText .= ' ' . substr((string)$task['due_time'], 0, 5);
                }
                echo h($dueText);
                ?>
            </div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 md:col-span-2">
            <div class="text-xs font-bold text-slate-500">업무 내용</div>
            <div class="mt-2 whitespace-pre-line text-slate-800"><?php echo h(isset($task['content']) && trim((string)$task['content']) !== '' ? $task['content'] : '-'); ?></div>
        </div>
    </div>

    <?php if (count($requestFiles) > 0 || count($completeFiles) > 0): ?>
        <form method="post" action="?r=tasks/files_download" class="space-y-4">
            <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-gray-200 bg-white p-3">
                <div class="text-sm font-extrabold text-gray-900">첨부파일 <?php echo count($files); ?>개</div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="px-3 py-2 rounded-xl border border-gray-300 bg-white text-sm font-extrabold text-gray-700 hover:bg-gray-50">선택 다운로드</button>
                    <a href="?r=tasks/files_download&amp;task_id=<?php echo (int)$taskId; ?>&amp;mode=all" class="px-3 py-2 rounded-xl bg-gray-900 text-sm font-extrabold text-white hover:bg-gray-800">전체 다운로드</a>
                </div>
            </div>
            <?php if (count($requestFiles) > 0): ?>
                <div>
                    <div class="text-sm font-extrabold text-gray-900 mb-2">요청자가 올린 파일</div>
                    <div class="space-y-2">
                        <?php foreach ($requestFiles as $file): ?>
                            <div class="flex items-center gap-3 p-3 rounded-2xl border border-sky-200 bg-sky-50">
                                <input type="checkbox" name="file_ids[]" value="<?php echo (int)(isset($file['id']) ? $file['id'] : 0); ?>" class="w-4 h-4 shrink-0" aria-label="<?php echo h(isset($file['original_name']) ? $file['original_name'] : '파일'); ?> 선택">
                                <span class="min-w-0 flex-1 font-bold text-slate-800 break-all"><?php echo h(isset($file['original_name']) ? $file['original_name'] : '-'); ?></span>
                                <a href="<?php echo h(cpms_tasks_file_url($file)); ?>" target="_blank" class="shrink-0 text-xs font-bold text-sky-700 hover:underline">열기</a>
                                <a href="<?php echo h(cpms_tasks_file_url($file)); ?>&amp;download=1" class="shrink-0 px-3 py-1.5 rounded-lg bg-sky-700 text-xs font-extrabold text-white hover:bg-sky-800">다운로드</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (count($completeFiles) > 0): ?>
                <div>
                    <div class="text-sm font-extrabold text-gray-900 mb-2">완료 처리자가 올린 파일</div>
                    <div class="space-y-2">
                        <?php foreach ($completeFiles as $file): ?>
                            <div class="flex items-center gap-3 p-3 rounded-2xl border border-emerald-200 bg-emerald-50">
                                <input type="checkbox" name="file_ids[]" value="<?php echo (int)(isset($file['id']) ? $file['id'] : 0); ?>" class="w-4 h-4 shrink-0" aria-label="<?php echo h(isset($file['original_name']) ? $file['original_name'] : '파일'); ?> 선택">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-bold text-slate-800 break-all"><?php echo h(isset($file['original_name']) ? $file['original_name'] : '-'); ?></span>
                                    <?php if (isset($file['_task_assignee_name']) && trim((string)$file['_task_assignee_name']) !== ''): ?>
                                        <span class="mt-1 inline-flex px-2 py-0.5 rounded-full bg-white border border-emerald-200 text-xs font-extrabold text-emerald-700"><?php echo h($file['_task_assignee_name']); ?> 업로드</span>
                                    <?php endif; ?>
                                </span>
                                <a href="<?php echo h(cpms_tasks_file_url($file)); ?>" target="_blank" class="shrink-0 text-xs font-bold text-emerald-700 hover:underline">열기</a>
                                <a href="<?php echo h(cpms_tasks_file_url($file)); ?>&amp;download=1" class="shrink-0 px-3 py-1.5 rounded-lg bg-emerald-700 text-xs font-extrabold text-white hover:bg-emerald-800">다운로드</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if ($hasTransferRequest): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-3 py-1 rounded-full border border-amber-300 bg-white text-xs font-extrabold text-amber-800">업무담당자 변경요청</span>
                <span class="text-sm font-extrabold text-amber-900">
                    <?php echo h(isset($task['assignee_name']) ? $task['assignee_name'] : '-'); ?> → <?php echo h(isset($task['transfer_request_assignee_name']) ? $task['transfer_request_assignee_name'] : '-'); ?>
                </span>
            </div>
            <?php if (isset($task['transfer_request_reason']) && trim((string)$task['transfer_request_reason']) !== ''): ?>
                <div class="mt-2 text-sm text-amber-900 whitespace-pre-line">사유: <?php echo h($task['transfer_request_reason']); ?></div>
            <?php endif; ?>
            <div class="mt-2 text-xs font-bold text-amber-700">업무 요청자의 승인을 기다리고 있습니다.</div>
        </div>
    <?php endif; ?>

    <?php if ($canTransfer): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <div class="text-sm font-extrabold text-amber-900 mb-3">업무담당자 변경 요청</div>
            <form method="post" action="?r=tasks/transfer" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                <select name="assignee_employee_id" required class="px-4 py-3 rounded-2xl border border-amber-200 bg-white md:col-span-1">
                    <option value="">변경 담당자 선택</option>
                    <?php foreach ($transferEmployees as $employee): ?>
                        <?php if (isset($employee['id']) && (int)$employee['id'] === $currentEmployeeId) continue; ?>
                        <option value="<?php echo (int)$employee['id']; ?>"><?php echo h((isset($employee['name']) ? $employee['name'] : '-') . ' / ' . (isset($employee['department']) ? $employee['department'] : '-')); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="transfer_reason" class="px-4 py-3 rounded-2xl border border-amber-200 bg-white md:col-span-1" placeholder="변경 요청 사유">
                <button type="submit" class="px-4 py-3 rounded-2xl bg-amber-500 text-white font-extrabold md:col-span-1">요청</button>
            </form>
        </div>
    <?php endif; ?>

    <div data-task-comments="<?php echo (int)$taskId; ?>">
        <div class="flex items-center justify-between gap-3 mb-2">
            <div class="text-sm font-extrabold text-gray-900">댓글</div>
            <div class="text-xs text-gray-500"><span data-task-comments-count><?php echo count($comments); ?></span>건</div>
        </div>
        <?php cpms_tasks_render_comments($comments, $taskId, $returnUrl, $commentsInputAllowed); ?>
        <?php if ($commentsInputAllowed): ?>
        <form method="post" action="?r=task_comment_save" class="mt-4 rounded-2xl border border-gray-200 bg-slate-50 p-4 space-y-3" data-task-comment-form>
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
            <input type="hidden" name="parent_comment_id" value="0">
            <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
            <div class="text-sm font-bold text-gray-700">진행상태 댓글 입력</div>
            <textarea name="comment_text" rows="3" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white" placeholder="진행상태나 확인 내용을 입력하세요."></textarea>
            <div class="flex justify-end">
                <button type="submit" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">댓글 등록</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($canCancel && (!isset($task['status']) || !in_array((string)$task['status'], array('cancelled', 'done'), true))): ?>
        <div class="pt-3 border-t border-gray-200">
            <form method="post" action="?r=tasks/cancel" onsubmit="return confirm('<?php echo $isMeetingTask ? '이 회의 요청을 취소하시겠습니까?' : '이 업무 요청을 취소하시겠습니까?'; ?>');" class="space-y-2">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                <textarea name="cancel_reason" rows="2" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="취소 사유를 남길 수 있습니다."></textarea>
                <button type="submit" class="px-4 py-2 rounded-2xl bg-rose-600 text-white font-bold"><?php echo $isMeetingTask ? '회의 요청 취소' : '업무 요청 취소'; ?></button>
            </form>
        </div>
    <?php endif; ?>

    <div>
        <div class="text-sm font-extrabold text-gray-900 mb-2">처리 기록</div>
        <div class="space-y-2">
            <?php if (count($logs) === 0): ?>
                <div class="p-4 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500">등록된 처리 기록이 없습니다.</div>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <div class="p-4 rounded-2xl border border-gray-200 bg-slate-50">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="font-bold text-slate-900"><?php echo h(cpms_tasks_status_label(isset($log['action_type']) ? $log['action_type'] : 'commented')); ?></div>
                            <div class="text-xs text-slate-500"><?php echo h(isset($log['created_at']) ? $log['created_at'] : ''); ?></div>
                        </div>
                        <div class="text-sm text-slate-700 mt-1"><?php echo h(isset($log['actor_name']) && trim((string)$log['actor_name']) !== '' ? $log['actor_name'] : '-'); ?></div>
                        <?php if (isset($log['message']) && trim((string)$log['message']) !== ''): ?>
                            <div class="text-sm text-slate-700 mt-2 whitespace-pre-line"><?php echo h($log['message']); ?></div>
                        <?php endif; ?>
                        <?php if ((isset($log['old_status']) && $log['old_status'] !== null) || (isset($log['new_status']) && $log['new_status'] !== null)): ?>
                            <div class="text-xs text-slate-500 mt-2">
                                상태: <?php echo h(cpms_tasks_status_label(isset($log['old_status']) ? $log['old_status'] : '')); ?>
                                → <?php echo h(cpms_tasks_status_label(isset($log['new_status']) ? $log['new_status'] : '')); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$html = ob_get_clean();

if ($isModal) {
    echo $html;
    exit;
}
?>
<div class="max-w-5xl mx-auto bg-white rounded-3xl shadow-lg p-6 border border-gray-100">
    <?php echo $html; ?>
</div>
