<?php
/**
 * partials/SafetyChecklist.php
 * - 체크리스트 UI (안전/품질에서 공통 활용)
 * - 사용: render_checklist($title, $items)
 */
function render_checklist($title, $items) {
    if (!is_array($items)) $items = array();
    ?>
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-extrabold text-gray-900"><?php echo h($title); ?></h3>
            <div class="p-3 bg-gradient-to-br from-red-500 to-rose-500 rounded-2xl shadow-lg shadow-red-500/30">
                <i data-lucide="shield-alert" class="w-5 h-5 text-white"></i>
            </div>
        </div>

        <div class="space-y-3">
            <?php foreach ($items as $it): ?>
                <label class="flex items-start gap-3 p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition cursor-pointer">
                    <input type="checkbox" class="mt-1 w-4 h-4">
                    <div class="flex-1">
                        <div class="font-bold text-gray-900"><?php echo h($it); ?></div>
                        <div class="text-xs text-gray-500 mt-1">점검 후 체크</div>
                    </div>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}