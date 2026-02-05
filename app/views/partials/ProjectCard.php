<?php
/**
 * partials/ProjectCard.php
 * - 프로젝트 카드 UI (피그마 느낌)
 * - 사용: render_project_card($project, $actions = true)
 */

function render_project_card($project, $actions = true) {
    $statusClassMap = array(
        '진행 중' => 'bg-gradient-to-r from-blue-500 to-cyan-500 text-white',
        '준비 중' => 'bg-gradient-to-r from-yellow-500 to-orange-500 text-white',
        '완료'    => 'bg-gradient-to-r from-emerald-500 to-teal-500 text-white',
        '보류'    => 'bg-gradient-to-r from-gray-500 to-slate-500 text-white',
    );

    $status = isset($project['status']) ? $project['status'] : '진행 중';
    $statusClass = isset($statusClassMap[$status]) ? $statusClassMap[$status] : $statusClassMap['진행 중'];

    $id = isset($project['id']) ? $project['id'] : '';
    $name = isset($project['name']) ? $project['name'] : '';
    $client = isset($project['client']) ? $project['client'] : '';
    $location = isset($project['location']) ? $project['location'] : '';
    $startDate = isset($project['startDate']) ? $project['startDate'] : '';
    $endDate = isset($project['endDate']) ? $project['endDate'] : '';
    $budget = isset($project['budget']) ? $project['budget'] : '';
    $manager = isset($project['manager']) ? $project['manager'] : '';
    $subManagers = isset($project['subManagers']) && is_array($project['subManagers']) ? $project['subManagers'] : array();
    $subsText = implode(', ', $subManagers);
    ?>
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2 mb-2">
                    <div class="p-2 rounded-2xl bg-gradient-to-br from-blue-100 to-cyan-100">
                        <i data-lucide="building-2" class="w-5 h-5 text-blue-600"></i>
                    </div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold <?php echo h($statusClass); ?>">
                        <?php echo h($status); ?>
                    </span>
                </div>
                <h3 class="text-lg font-extrabold text-gray-900 truncate"><?php echo h($name); ?></h3>
                <p class="text-sm text-gray-500 mt-1"><?php echo h($client); ?></p>
            </div>

            <?php if ($actions): ?>
                <div class="flex items-center gap-2">
                    <button type="button"
                            class="px-3 py-2 rounded-2xl bg-white border border-gray-200 hover:bg-gray-50 text-sm font-semibold"
                            data-modal-open="projectDetail"
                            data-project-id="<?php echo h($id); ?>">
                        <span class="inline-flex items-center gap-2">
                            <i data-lucide="file-text" class="w-4 h-4"></i> 상세
                        </span>
                    </button>
                    <button type="button"
                            class="px-3 py-2 rounded-2xl bg-white border border-gray-200 hover:bg-gray-50 text-sm font-semibold"
                            data-toast="edit:<?php echo h($id); ?>">
                        <span class="inline-flex items-center gap-2">
                            <i data-lucide="edit-2" class="w-4 h-4"></i> 수정
                        </span>
                    </button>
                    <button type="button"
                            class="px-3 py-2 rounded-2xl bg-white border border-gray-200 hover:bg-red-50 text-sm font-semibold text-red-600"
                            data-toast="delete:<?php echo h($id); ?>">
                        <span class="inline-flex items-center gap-2">
                            <i data-lucide="trash-2" class="w-4 h-4"></i> 삭제
                        </span>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div class="flex items-center gap-2 text-gray-700">
                <i data-lucide="map-pin" class="w-4 h-4 text-gray-400"></i>
                <span class="truncate"><?php echo h($location); ?></span>
            </div>
            <div class="flex items-center gap-2 text-gray-700">
                <i data-lucide="calendar" class="w-4 h-4 text-gray-400"></i>
                <span><?php echo h($startDate); ?> ~ <?php echo h($endDate); ?></span>
            </div>
            <div class="flex items-center gap-2 text-gray-700">
                <i data-lucide="dollar-sign" class="w-4 h-4 text-gray-400"></i>
                <span><?php echo h($budget); ?></span>
            </div>
            <div class="flex items-center gap-2 text-gray-700">
                <i data-lucide="user" class="w-4 h-4 text-gray-400"></i>
                <span>담당: <?php echo h($manager); ?></span>
            </div>
        </div>

        <?php if ($subsText !== ''): ?>
            <div class="mt-4 text-sm text-gray-600 flex items-center gap-2">
                <i data-lucide="users" class="w-4 h-4 text-gray-400"></i>
                <span class="truncate">부담당: <?php echo h($subsText); ?></span>
            </div>
        <?php endif; ?>
    </div>
    <?php
}