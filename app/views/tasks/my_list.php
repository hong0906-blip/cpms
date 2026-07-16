<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/task_feed_helper.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$setupResults = array();
cpms_tasks_ensure_schema($pdo, $setupResults);
$currentEmployee = cpms_tasks_current_employee($pdo);
$requestedTaskDate = isset($_GET['requested_task_date']) ? trim((string)$_GET['requested_task_date']) : cpms_tasks_today();
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedTaskDate)) $requestedTaskDate = cpms_tasks_today();
$feed = cpms_task_feed_for_employee($pdo, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0, isset($currentEmployee['email']) ? $currentEmployee['email'] : '', $currentEmployee);
$requested = cpms_task_feed_direct_tasks_requested_by_employee($pdo, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0, $requestedTaskDate);
$returnUrl = '?r=tasks/my_list&requested_task_date=' . urlencode($requestedTaskDate);
?>
<div class="space-y-8">
    <div class="bg-white rounded-3xl p-6 border border-gray-100">
        <h2 class="text-2xl font-extrabold text-gray-900">내가 받은 업무</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <tr class="border-b border-gray-200 text-left text-gray-500">
                    <th class="py-3 pr-4">구분</th>
                    <th class="py-3 pr-4">제목</th>
                    <th class="py-3 pr-4">요청자</th>
                    <th class="py-3 pr-4">마감</th>
                    <th class="py-3 pr-4">상태</th>
                    <th class="py-3">이동</th>
                </tr>
                <?php foreach ($feed as $item): ?>
                    <tr class="border-b border-gray-100">
                        <td class="py-3 pr-4"><?php echo h(cpms_tasks_type_label(isset($item['task_type']) ? $item['task_type'] : 'general')); ?></td>
                        <td class="py-3 pr-4 font-bold text-gray-900">
                            <?php echo h(isset($item['title']) ? $item['title'] : ''); ?>
                        </td>
                        <td class="py-3 pr-4"><?php echo h(isset($item['requester_name']) ? $item['requester_name'] : '-'); ?></td>
                        <td class="py-3 pr-4"><?php echo h(isset($item['due_date']) && $item['due_date'] !== '' ? $item['due_date'] . (isset($item['due_time']) && $item['due_time'] !== '' ? ' ' . substr((string)$item['due_time'], 0, 5) : '') : '-'); ?></td>
                        <td class="py-3 pr-4">
                            <?php echo h(isset($item['display_status']) ? $item['display_status'] : '-'); ?>
                            <?php if (isset($item['request_file_count']) && (int)$item['request_file_count'] > 0): ?>
                                <div class="mt-1 text-xs font-bold text-sky-700">[파일첨부되어있음]</div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3">
                            <a href="<?php echo h(isset($item['action_url']) ? $item['action_url'] : '#'); ?>" class="text-blue-600 font-bold">상세 이동</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-3xl p-6 border border-gray-100">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h2 class="text-2xl font-extrabold text-gray-900">내가 요청한 업무</h2>
            <form method="get" action="" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="r" value="tasks/my_list">
                <div>
                    <div class="text-xs font-bold text-gray-500 mb-1">요청일</div>
                    <input type="date" name="requested_task_date" value="<?php echo h($requestedTaskDate); ?>" class="px-3 py-2 rounded-2xl border border-gray-200">
                </div>
                <button type="submit" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-bold">조회</button>
            </form>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <tr class="border-b border-gray-200 text-left text-gray-500">
                    <th class="py-3 pr-4">담당자</th>
                    <th class="py-3 pr-4">제목</th>
                    <th class="py-3 pr-4">마감</th>
                    <th class="py-3 pr-4">상태</th>
                    <th class="py-3">이동</th>
                </tr>
                <?php foreach ($requested as $item): ?>
                    <tr class="border-b border-gray-100">
                        <td class="py-3 pr-4"><?php echo h(isset($item['assignee_name']) ? $item['assignee_name'] : '-'); ?></td>
                        <td class="py-3 pr-4 font-bold text-gray-900"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></td>
                        <td class="py-3 pr-4"><?php echo h(isset($item['due_date']) && $item['due_date'] !== '' ? $item['due_date'] . (isset($item['due_time']) && $item['due_time'] !== '' ? ' ' . substr((string)$item['due_time'], 0, 5) : '') : '-'); ?></td>
                        <td class="py-3 pr-4">
                            <?php echo h(isset($item['display_status']) ? $item['display_status'] : '-'); ?>
                            <?php if (cpms_tasks_has_transfer_request($item)): ?>
                                <div class="mt-1 text-xs font-extrabold text-amber-700">[업무담당자 변경요청: <?php echo h(isset($item['transfer_request_assignee_name']) ? $item['transfer_request_assignee_name'] : '-'); ?>]</div>
                            <?php endif; ?>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <?php echo cpms_tasks_read_status_badges_html($item, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0, true); ?>
                            </div>
                            <?php if (isset($item['assignee_statuses']) && is_array($item['assignee_statuses']) && count($item['assignee_statuses']) > 1): ?>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    <?php foreach ($item['assignee_statuses'] as $assigneeStatus): ?>
                                        <span class="px-2 py-1 rounded-full border border-slate-200 bg-slate-50 text-xs font-bold text-slate-700"><?php echo h(isset($assigneeStatus['name']) ? $assigneeStatus['name'] : '-'); ?> · <?php echo h(isset($assigneeStatus['status_label']) ? $assigneeStatus['status_label'] : '-'); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="<?php echo h(isset($item['action_url']) ? $item['action_url'] : '#'); ?>" class="text-blue-600 font-bold">상세 이동</a>
                                <?php if (isset($item['completion_group_ready']) && (int)$item['completion_group_ready'] === 1): ?>
                                    <form method="post" action="?r=tasks/completion_approve" class="inline-flex">
                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="task_id" value="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>">
                                        <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                                        <button type="submit" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-extrabold"><?php echo isset($item['assignee_count']) && (int)$item['assignee_count'] > 1 ? '전체 완료 승인' : '완료 승인'; ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (cpms_tasks_can_approve_transfer_request($item, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)): ?>
                                    <form method="post" action="?r=tasks/transfer_approve" class="inline-flex">
                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="task_id" value="<?php echo (int)(isset($item['source_id']) ? $item['source_id'] : 0); ?>">
                                        <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                                        <button type="submit" class="px-3 py-2 rounded-xl bg-amber-500 text-white text-xs font-extrabold">담당자 변경 승인</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
