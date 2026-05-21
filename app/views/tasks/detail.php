<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$currentEmployee = cpms_tasks_current_employee($pdo);
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$task = cpms_tasks_find_task($pdo, $taskId);
$isModal = isset($_GET['modal']) && (string)$_GET['modal'] === '1';

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

$logs = cpms_tasks_fetch_logs($pdo, $taskId);
$files = cpms_tasks_fetch_files($pdo, $taskId);
$canChangeStatus = cpms_tasks_can_change_status($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
$canRevision = cpms_tasks_can_request_revision($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
$canCancel = cpms_tasks_can_cancel($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
$returnUrl = cpms_tasks_default_return_url();

ob_start();
?>
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <span class="px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_tasks_badge_class('priority', isset($task['priority']) ? $task['priority'] : 'normal')); ?>">
                    <?php echo h(cpms_tasks_priority_label(isset($task['priority']) ? $task['priority'] : 'normal')); ?>
                </span>
                <span class="px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_tasks_badge_class('status', cpms_tasks_is_delayed($task) ? 'delayed' : (isset($task['status']) ? $task['status'] : 'pending'))); ?>">
                    <?php echo h(cpms_tasks_display_status($task)); ?>
                </span>
                <span class="px-3 py-1 rounded-full border text-xs font-bold bg-slate-100 text-slate-700 border-slate-200">
                    <?php echo h(cpms_tasks_type_label(isset($task['task_type']) ? $task['task_type'] : 'general')); ?>
                </span>
                <?php if (isset($task['is_urgent']) && (int)$task['is_urgent'] === 1): ?>
                    <span class="px-3 py-1 rounded-full border text-xs font-bold bg-rose-50 text-rose-700 border-rose-200">🔥 긴급 요청</span>
                <?php endif; ?>
            </div>
            <h3 class="text-2xl font-extrabold text-gray-900"><?php echo h(isset($task['title']) ? $task['title'] : ''); ?></h3>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($canChangeStatus && !in_array(isset($task['status']) ? $task['status'] : '', array('progress', 'done', 'cancelled'), true)): ?>
                <form method="post" action="?r=tasks/update_status">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                    <input type="hidden" name="status" value="progress">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="px-4 py-2 rounded-2xl bg-blue-600 text-white font-bold">진행중</button>
                </form>
            <?php endif; ?>
            <?php if ($canChangeStatus && !in_array(isset($task['status']) ? $task['status'] : '', array('done', 'cancelled'), true)): ?>
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
            <div class="text-xs font-bold text-slate-500">마감일 / 시간</div>
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

    <?php if (count($files) > 0): ?>
        <div>
            <div class="text-sm font-extrabold text-gray-900 mb-2">첨부파일</div>
            <div class="space-y-2">
                <?php foreach ($files as $file): ?>
                    <a href="<?php echo h(cpms_tasks_file_url(isset($file['stored_path']) ? $file['stored_path'] : '')); ?>" target="_blank" class="flex items-center justify-between gap-3 p-3 rounded-2xl border border-gray-200 bg-white hover:bg-slate-50">
                        <span class="font-bold text-slate-800"><?php echo h(isset($file['original_name']) ? $file['original_name'] : '-'); ?></span>
                        <span class="text-xs text-slate-500">열기</span>
                    </a>
                <?php endforeach; ?>
            </div>
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

    <?php if ($canCancel && (!isset($task['status']) || !in_array((string)$task['status'], array('cancelled', 'done'), true))): ?>
        <div class="pt-3 border-t border-gray-200">
            <form method="post" action="?r=tasks/cancel" onsubmit="return confirm('이 업무 요청을 취소하시겠습니까?');" class="space-y-2">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                <textarea name="cancel_reason" rows="2" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="취소 사유를 남길 수 있습니다."></textarea>
                <button type="submit" class="px-4 py-2 rounded-2xl bg-rose-600 text-white font-bold">업무 요청 취소</button>
            </form>
        </div>
    <?php endif; ?>
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
