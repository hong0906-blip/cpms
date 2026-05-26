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

if (!function_exists('cpms_render_feed_card')) {
function cpms_render_feed_card($item, $currentEmployeeId, $returnUrl, $requestedMode)
{
    $statusKey = cpms_tasks_is_delayed($item) ? 'delayed' : (isset($item['status']) ? $item['status'] : 'pending');
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
        <div class="mt-1 text-sm text-slate-500">마감: <?php echo h($dueText); ?></div>
        <?php endif; ?>
        <?php if ($isConstructionSchedule): ?>
            <div class="mt-1 text-sm text-slate-500">공정일: <?php echo h($dueText); ?></div>
        <?php endif; ?>
        <div class="mt-1">
            <span class="px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_tasks_badge_class('status', $statusKey)); ?>"><?php echo h(isset($item['display_status']) ? $item['display_status'] : cpms_tasks_status_label(isset($item['status']) ? $item['status'] : 'pending')); ?></span>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <?php echo cpms_render_task_action_link($item); ?>
            <?php if (isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1 && !$requestedMode && (int)$currentEmployeeId > 0 && isset($item['assignee_employee_id']) && (int)$item['assignee_employee_id'] === (int)$currentEmployeeId && !in_array(isset($item['status']) ? $item['status'] : '', array('progress', 'done', 'cancelled'), true)): ?>
                <form method="post" action="?r=tasks/update_status" class="inline">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="task_id" value="<?php echo (int)$item['source_id']; ?>">
                    <input type="hidden" name="status" value="progress">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <button type="submit" class="px-3 py-2 rounded-xl bg-blue-600 text-white text-sm font-bold">진행중</button>
                </form>
            <?php endif; ?>
            <?php if (isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1 && !$requestedMode && (int)$currentEmployeeId > 0 && isset($item['assignee_employee_id']) && (int)$item['assignee_employee_id'] === (int)$currentEmployeeId && !in_array(isset($item['status']) ? $item['status'] : '', array('done', 'cancelled'), true)): ?>
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
                <div class="text-sm text-gray-500 mt-1"><?php echo h($description); ?></div>
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

if (!function_exists('cpms_render_employee_task_dashboard')) {
function cpms_render_employee_task_dashboard($pdo)
{
    $currentEmployee = cpms_tasks_current_employee($pdo);
    if ((int)$currentEmployee['id'] <= 0) return;

    $feed = cpms_task_feed_for_employee($pdo, (int)$currentEmployee['id'], isset($currentEmployee['email']) ? $currentEmployee['email'] : '', $currentEmployee);
    $requested = cpms_task_feed_direct_tasks_requested_by_employee($pdo, (int)$currentEmployee['id']);
    $employees = cpms_tasks_fetch_active_employees($pdo);
    $projects = cpms_tasks_fetch_projects($pdo);
    $returnUrl = cpms_tasks_default_return_url();

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
        $isConstructionSchedule = isset($item['source_type']) && (string)$item['source_type'] === 'construction_schedule';
        if (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1) {
            $summary['urgent']++;
            $urgentItems[count($urgentItems)] = $item;
        }
        if (cpms_tasks_is_due_today($item)) {
            if (!$isConstructionSchedule) {
                $summary['today']++;
                $todayItems[count($todayItems)] = $item;
            }
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
    ?>
    <div id="cpmsEmployeeTasksPanel" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
        <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-2xl font-extrabold text-gray-900">나의 할일</h2>
                    <button type="button" id="cpmsEmployeeTasksToggle" class="px-3 py-2 rounded-2xl bg-white border border-gray-200 text-sm font-bold text-gray-700">숨기기 ▲</button>
                </div>
                <div data-cpms-employee-task-body>
                <div class="text-sm text-gray-600 mt-1">업무 요청, 승인 요청, 마감 임박 업무를 한 곳에서 확인하고 바로 처리할 수 있습니다.</div>
                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                    <span class="px-3 py-2 rounded-full bg-slate-100 text-slate-700 font-bold">전체 <?php echo (int)$summary['all']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-rose-50 text-rose-700 font-bold">긴급 <?php echo (int)$summary['urgent']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-amber-50 text-amber-700 font-bold">오늘마감 <?php echo (int)$summary['today']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-blue-50 text-blue-700 font-bold">진행중 <?php echo (int)$summary['progress']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-red-50 text-red-700 font-bold">지연 <?php echo (int)$summary['delayed']; ?>건</span>
                    <span class="px-3 py-2 rounded-full bg-indigo-50 text-indigo-700 font-bold">승인대기 <?php echo (int)$summary['approval']; ?>건</span>
                </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="?r=tasks/my_list" class="px-4 py-3 rounded-2xl bg-white border border-gray-200 text-gray-700 font-bold">전체 보기</a>
                <button type="button" data-modal-open="taskCreate" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold shadow-lg">업무 요청</button>
            </div>
        </div>

        <div data-cpms-employee-task-body class="mt-6 space-y-5">
            <?php cpms_render_feed_lane('긴급', '긴급 요청으로 표시된 업무입니다.', 'bg-rose-50 text-rose-700', $urgentItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <?php cpms_render_feed_lane('오늘 마감', '오늘 안에 챙기면 좋은 업무입니다.', 'bg-amber-50 text-amber-700', $todayItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <?php cpms_render_feed_lane('진행중', '이미 착수했거나 보완 중인 업무입니다.', 'bg-blue-50 text-blue-700', $progressItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <?php cpms_render_feed_lane('전자결재/승인', '기존 승인 기능도 함께 보여드립니다.', 'bg-indigo-50 text-indigo-700', $approvalItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <?php cpms_render_feed_lane('지연', '마감이 지나 지원이 필요할 수 있는 업무입니다.', 'bg-red-50 text-red-700', $delayedItems, (int)$currentEmployee['id'], $returnUrl, false); ?>
            <?php cpms_render_feed_lane('내가 요청한 업무', '요청 후 진행 상황을 계속 확인할 수 있습니다.', 'bg-slate-100 text-slate-700', $requested, (int)$currentEmployee['id'], $returnUrl, true); ?>
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
    </script>

    <div id="modal-taskCreate" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40" data-modal-close="taskCreate"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-4xl rounded-3xl bg-white shadow-2xl border border-gray-100 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div>
                        <div class="text-2xl font-extrabold text-gray-900">업무 요청</div>
                        <div class="text-sm text-gray-500 mt-1">개인이 개인에게, 또는 부서와 관계없이 자유롭게 요청할 수 있습니다.</div>
                    </div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="taskCreate">닫기</button>
                </div>
                <form method="post" action="?r=tasks/create" enctype="multipart/form-data" class="p-6 space-y-5">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">업무 제목</div>
                            <input type="text" name="title" required class="w-full px-4 py-3 rounded-2xl border border-gray-200">
                        </div>
                        <div class="md:col-span-2">
                            <div class="text-sm font-bold text-gray-700 mb-1">업무 내용</div>
                            <textarea name="content" rows="4" class="w-full px-4 py-3 rounded-2xl border border-gray-200"></textarea>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">담당자 검색</div>
                            <input type="text" id="taskAssigneeSearch" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="이름 / 부서 / 직책 검색">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-1">담당자</div>
                            <select name="assignee_employee_id" id="taskAssigneeSelect" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                                <option value="">담당자 선택</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo (int)$employee['id']; ?>" data-department="<?php echo h(isset($employee['department']) ? $employee['department'] : ''); ?>">
                                        <?php echo h((isset($employee['name']) ? $employee['name'] : '-') . ' / ' . (isset($employee['department']) ? $employee['department'] : '-') . ' / ' . (isset($employee['position']) && trim((string)$employee['position']) !== '' ? $employee['position'] : '-')); ?>
                                    </option>
                                <?php endforeach; ?>
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
        var departmentSelect = document.getElementById('taskDepartmentSelect');
        var taskDetailBody = document.getElementById('taskDetailBody');
        var completeTaskId = document.getElementById('taskCompleteTaskId');
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

        if (assigneeSearch && assigneeSelect) {
            assigneeSearch.addEventListener('input', function(){
                var keyword = assigneeSearch.value.replace(/^\s+|\s+$/g, '').toLowerCase();
                var options = assigneeSelect.options;
                for (var i = 0; i < options.length; i++) {
                    var option = options[i];
                    if (!option.value) continue;
                    var matched = keyword === '' || option.text.toLowerCase().indexOf(keyword) >= 0;
                    option.hidden = !matched;
                }
            });
            assigneeSelect.addEventListener('change', function(){
                var selected = assigneeSelect.options[assigneeSelect.selectedIndex];
                if (!selected) return;
                if (departmentSelect && departmentSelect.value === '') {
                    var dept = selected.getAttribute('data-department') || '';
                    departmentSelect.value = dept;
                }
            });
        }

        function openCompleteModal(taskId) {
            if (completeTaskId) completeTaskId.value = taskId;
            var modal = document.getElementById('modal-taskComplete');
            if (modal) modal.classList.remove('hidden');
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
                xhr.open('GET', '?r=tasks/detail&id=' + encodeURIComponent(taskId) + '&modal=1', true);
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
    $departmentOptions = array_merge(array('전체'), cpms_tasks_department_options());
    ?>
    <div id="cpmsExecutiveTasksPanel" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
        <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-extrabold text-gray-900">부서별 업무 현황</h2>
                <div class="text-sm text-gray-600 mt-1">업무가 몰린 곳과 마감 임박 업무를 함께 보며 지원이 필요한 지점을 빠르게 확인할 수 있습니다.</div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" id="cpmsExecutiveTasksToggle" class="px-4 py-3 rounded-2xl bg-white border border-gray-200 text-gray-700 font-bold">숨기기 ▲</button>
                <a href="?r=tasks/executive_summary" class="px-4 py-3 rounded-2xl bg-white border border-gray-200 text-gray-700 font-bold">요약 보기</a>
            </div>
        </div>

        <div data-cpms-executive-task-body>
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mt-6">
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200"><div class="text-xs text-slate-500 font-bold">오늘 할일</div><div class="mt-2 text-3xl font-extrabold text-slate-900"><?php echo (int)$summaryData['summary']['today']; ?></div></div>
            <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200"><div class="text-xs text-rose-500 font-bold">긴급 요청</div><div class="mt-2 text-3xl font-extrabold text-rose-700"><?php echo (int)$summaryData['summary']['urgent']; ?></div></div>
            <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200"><div class="text-xs text-amber-500 font-bold">마감 임박</div><div class="mt-2 text-3xl font-extrabold text-amber-700"><?php echo (int)$summaryData['summary']['due_soon']; ?></div></div>
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200"><div class="text-xs text-red-500 font-bold">지연 업무</div><div class="mt-2 text-3xl font-extrabold text-red-700"><?php echo (int)$summaryData['summary']['delayed']; ?></div></div>
            <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200"><div class="text-xs text-emerald-500 font-bold">완료</div><div class="mt-2 text-3xl font-extrabold text-emerald-700"><?php echo (int)$summaryData['summary']['done']; ?></div></div>
            <div class="p-4 rounded-2xl bg-blue-50 border border-blue-200"><div class="text-xs text-blue-500 font-bold">승인대기</div><div class="mt-2 text-3xl font-extrabold text-blue-700"><?php echo (int)$summaryData['summary']['approval_pending']; ?></div></div>
        </div>

        <div class="mt-6">
            <div class="text-sm font-bold text-gray-700 mb-2">부서 필터</div>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($departmentOptions as $departmentName): ?>
                    <?php
                    $isSelected = ($selectedDepartment === $departmentName);
                    $url = '?r=대시보드&dv=executive';
                    if ($departmentName !== '전체') $url .= '&task_department=' . urlencode($departmentName);
                    ?>
                    <a href="<?php echo h($url); ?>" class="px-4 py-2 rounded-2xl font-bold <?php echo $isSelected ? 'bg-gray-900 text-white' : 'bg-white border border-gray-200 text-gray-700'; ?>">
                        <?php echo h($departmentName); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-6">
            <h3 class="text-xl font-extrabold text-gray-900 mb-4">부서별 현황</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($summaryData['departments'] as $departmentName => $departmentMetrics): ?>
                    <?php if ($selectedDepartment !== '전체' && $departmentName !== $selectedDepartment) continue; ?>
                    <div class="p-5 rounded-3xl border border-gray-200 bg-white shadow-sm">
                        <div class="text-xl font-extrabold text-gray-900"><?php echo h($departmentName); ?></div>
                        <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                            <div class="p-3 rounded-2xl bg-slate-50">오늘 할일 <b class="block mt-1 text-lg"><?php echo (int)$departmentMetrics['today']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-emerald-50">완료 <b class="block mt-1 text-lg text-emerald-700"><?php echo (int)$departmentMetrics['done']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-blue-50">진행중 <b class="block mt-1 text-lg text-blue-700"><?php echo (int)$departmentMetrics['progress']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-red-50">지연 <b class="block mt-1 text-lg text-red-700"><?php echo (int)$departmentMetrics['delayed']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-rose-50">긴급 <b class="block mt-1 text-lg text-rose-700"><?php echo (int)$departmentMetrics['urgent']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-amber-50">마감임박 <b class="block mt-1 text-lg text-amber-700"><?php echo (int)$departmentMetrics['due_soon']; ?>건</b></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-8">
            <h3 class="text-xl font-extrabold text-gray-900 mb-4">직원별 현황</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($summaryData['employees'] as $employeeRow): ?>
                    <?php
                    $employee = isset($employeeRow['employee']) ? $employeeRow['employee'] : array();
                    $metrics = isset($employeeRow['metrics']) ? $employeeRow['metrics'] : array();
                    $feed = isset($employeeRow['feed']) ? $employeeRow['feed'] : array();
                    $modalId = 'executiveTaskEmployee' . (int)$employee['id'];
                    ?>
                    <button type="button" data-modal-open="<?php echo h($modalId); ?>" class="text-left p-5 rounded-3xl border border-gray-200 bg-white shadow-sm hover:shadow-lg transition relative group">
                        <div class="text-xl font-extrabold text-gray-900"><?php echo h(isset($employee['name']) ? $employee['name'] : '-'); ?></div>
                        <div class="text-sm text-gray-500 mt-1"><?php echo h((isset($employee['department']) ? $employee['department'] : '-') . ' / ' . (isset($employee['position']) && trim((string)$employee['position']) !== '' ? $employee['position'] : '-')); ?></div>
                        <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                            <div class="p-3 rounded-2xl bg-slate-50">오늘 할일 <b class="block mt-1 text-lg"><?php echo (int)$metrics['today']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-emerald-50">완료 <b class="block mt-1 text-lg text-emerald-700"><?php echo (int)$metrics['done']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-blue-50">진행중 <b class="block mt-1 text-lg text-blue-700"><?php echo (int)$metrics['progress']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-red-50">지연 <b class="block mt-1 text-lg text-red-700"><?php echo (int)$metrics['delayed']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-rose-50">긴급 <b class="block mt-1 text-lg text-rose-700"><?php echo (int)$metrics['urgent']; ?>건</b></div>
                            <div class="p-3 rounded-2xl bg-amber-50">마감임박 <b class="block mt-1 text-lg text-amber-700"><?php echo (int)$metrics['due_soon']; ?>건</b></div>
                        </div>

                        <div class="hidden xl:block absolute left-0 right-0 top-full mt-2 p-4 rounded-2xl bg-white border border-gray-200 shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition z-30">
                            <div class="font-extrabold text-gray-900 mb-2">업무 미리보기</div>
                            <?php if (count($feed) === 0): ?>
                                <div class="text-sm text-gray-500">표시할 업무가 없습니다.</div>
                            <?php else: ?>
                                <div class="space-y-2">
                                    <?php $previewCount = 0; foreach ($feed as $item): $previewCount++; if ($previewCount > 5) break; ?>
                                        <div class="text-sm text-gray-700">
                                            <b><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></b>
                                            <span class="text-xs text-gray-500"> / <?php echo h(isset($item['due_time']) && $item['due_time'] !== '' ? substr((string)$item['due_time'], 0, 5) : '-'); ?> / <?php echo h(isset($item['display_status']) ? $item['display_status'] : '-'); ?><?php echo (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1) ? ' / 긴급' : ''; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </button>

                    <div id="modal-<?php echo h($modalId); ?>" class="fixed inset-0 z-50 hidden">
                        <div class="absolute inset-0 bg-black/40" data-modal-close="<?php echo h($modalId); ?>"></div>
                        <div class="absolute inset-0 flex items-center justify-center p-4">
                            <div class="w-full max-w-4xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                                    <div>
                                        <div class="text-2xl font-extrabold text-gray-900"><?php echo h(isset($employee['name']) ? $employee['name'] : '-'); ?> 업무 현황</div>
                                        <div class="text-sm text-gray-500 mt-1"><?php echo h((isset($employee['department']) ? $employee['department'] : '-') . ' / ' . (isset($employee['position']) ? $employee['position'] : '-')); ?></div>
                                    </div>
                                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-modal-close="<?php echo h($modalId); ?>">닫기</button>
                                </div>
                                <div class="p-6 overflow-y-auto max-h-[74vh]">
                                    <?php if (count($feed) === 0): ?>
                                        <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500">표시할 업무가 없습니다.</div>
                                    <?php else: ?>
                                        <div class="space-y-3">
                                            <?php foreach ($feed as $item): ?>
                                                <div class="p-4 rounded-2xl border border-gray-200 bg-slate-50">
                                                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                                                        <div class="min-w-0">
                                                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                                                <span class="px-3 py-1 rounded-full border text-xs font-bold bg-slate-100 text-slate-700 border-slate-200"><?php echo h(cpms_tasks_type_label(isset($item['task_type']) ? $item['task_type'] : 'general')); ?></span>
                                                                <span class="px-3 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_tasks_badge_class('status', cpms_tasks_is_delayed($item) ? 'delayed' : (isset($item['status']) ? $item['status'] : 'pending'))); ?>"><?php echo h(isset($item['display_status']) ? $item['display_status'] : '-'); ?></span>
                                                                <?php if (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1): ?><span class="px-3 py-1 rounded-full border text-xs font-bold bg-rose-50 text-rose-700 border-rose-200">🔥 긴급</span><?php endif; ?>
                                                            </div>
                                                            <div class="font-extrabold text-gray-900"><?php echo h(isset($item['title']) ? $item['title'] : ''); ?></div>
                                                            <div class="text-sm text-gray-600 mt-1">요청자: <?php echo h(isset($item['requester_name']) ? $item['requester_name'] : '-'); ?></div>
                                                            <div class="text-sm text-gray-500 mt-1">마감: <?php echo h(isset($item['due_date']) && $item['due_date'] !== '' ? $item['due_date'] . (isset($item['due_time']) && $item['due_time'] !== '' ? ' ' . substr((string)$item['due_time'], 0, 5) : '') : '-'); ?></div>
                                                        </div>
                                                        <div>
                                                            <?php if (isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1): ?>
                                                                <a href="?r=tasks/detail&id=<?php echo (int)$item['source_id']; ?>" class="px-3 py-2 rounded-xl bg-white border border-gray-200 text-sm font-bold text-slate-700">상세 이동</a>
                                                            <?php else: ?>
                                                                <a href="<?php echo h(isset($item['action_url']) ? $item['action_url'] : '#'); ?>" class="px-3 py-2 rounded-xl bg-white border border-gray-200 text-sm font-bold text-slate-700">상세 이동</a>
                                                            <?php endif; ?>
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
            </div>
        </div>
        </div>
    </div>
    <script>
    (function(){
        var key = 'cpms_executive_tasks_collapsed';
        var toggle = document.getElementById('cpmsExecutiveTasksToggle');
        var body = document.querySelector('[data-cpms-executive-task-body]');
        if (!toggle || !body) return;
        function readState() {
            try { return window.localStorage && localStorage.getItem(key) === '1'; } catch (e) { return false; }
        }
        function saveState(collapsed) {
            try { if (window.localStorage) localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {}
        }
        function applyState(collapsed) {
            if (collapsed) body.classList.add('hidden');
            else body.classList.remove('hidden');
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
    </script>
    <?php
}}
