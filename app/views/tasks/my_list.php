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
$currentEmployee = cpms_tasks_current_employee($pdo);
$feed = cpms_task_feed_for_employee($pdo, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0, isset($currentEmployee['email']) ? $currentEmployee['email'] : '', $currentEmployee);
$requested = cpms_task_feed_direct_tasks_requested_by_employee($pdo, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0);
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
                        <td class="py-3 pr-4 font-bold text-gray-900"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></td>
                        <td class="py-3 pr-4"><?php echo h(isset($item['requester_name']) ? $item['requester_name'] : '-'); ?></td>
                        <td class="py-3 pr-4"><?php echo h(isset($item['due_date']) && $item['due_date'] !== '' ? $item['due_date'] . (isset($item['due_time']) && $item['due_time'] !== '' ? ' ' . substr((string)$item['due_time'], 0, 5) : '') : '-'); ?></td>
                        <td class="py-3 pr-4"><?php echo h(isset($item['display_status']) ? $item['display_status'] : '-'); ?></td>
                        <td class="py-3">
                            <a href="<?php echo h(isset($item['action_url']) ? $item['action_url'] : '#'); ?>" class="text-blue-600 font-bold">상세 이동</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-3xl p-6 border border-gray-100">
        <h2 class="text-2xl font-extrabold text-gray-900">내가 요청한 업무</h2>
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
                        <td class="py-3 pr-4"><?php echo h(isset($item['display_status']) ? $item['display_status'] : '-'); ?></td>
                        <td class="py-3">
                            <a href="<?php echo h(isset($item['action_url']) ? $item['action_url'] : '#'); ?>" class="text-blue-600 font-bold">상세 이동</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
