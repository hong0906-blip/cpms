<?php
/**
 * partials/TaskList.php
 * - 할일 목록 (피그마 느낌)
 * - 사용: render_task_list($tasks)
 */
function render_task_list($tasks) {
    if (!is_array($tasks)) $tasks = array();
    ?>
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-extrabold text-gray-900">오늘 할일</h3>
            <div class="p-3 bg-gradient-to-br from-emerald-500 to-teal-500 rounded-2xl shadow-lg shadow-emerald-500/30">
                <i data-lucide="check-circle-2" class="w-5 h-5 text-white"></i>
            </div>
        </div>

        <div class="space-y-3">
            <?php foreach ($tasks as $t): ?>
                <?php
                $title = isset($t['title']) ? $t['title'] : '';
                $status = isset($t['status']) ? $t['status'] : 'pending';
                $meta = isset($t['meta']) ? $t['meta'] : '';
                $badgeClass = ($status === 'done')
                    ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                    : (($status === 'risk')
                        ? 'bg-red-50 text-red-700 border-red-100'
                        : 'bg-blue-50 text-blue-700 border-blue-100');
                $icon = ($status === 'done') ? 'check-circle-2' : (($status === 'risk') ? 'alert-circle' : 'clock');
                ?>
                <div class="flex items-start gap-3 p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="p-2 rounded-xl bg-gray-50">
                        <i data-lucide="<?php echo h($icon); ?>" class="w-5 h-5 text-gray-700"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-gray-900 truncate"><?php echo h($title); ?></div>
                        <?php if ($meta !== ''): ?>
                            <div class="text-xs text-gray-500 mt-1"><?php echo h($meta); ?></div>
                        <?php endif; ?>
                    </div>
                    <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badgeClass); ?>">
                        <?php echo ($status === 'done') ? '완료' : (($status === 'risk') ? '주의' : '진행'); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}