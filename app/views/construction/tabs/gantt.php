<?php
/**
 * C:\www\cpms\app\views\construction\tabs\gantt.php
 * - 공사: 공정표 탭
 *
 * 요구사항:
 * - 공정표는 공사팀과 임원만 수정/삭제 가능
 *
 * 사용 변수:
 * - $pdo (PDO)
 * - $pid (int)
 * - $projectRow (array)
 * - $canEdit (bool)
 */

// 태스크 목록
$tasks = array();
try {
    $st = $pdo->prepare("SELECT * FROM cpms_schedule_tasks WHERE project_id = :pid ORDER BY sort_order ASC, id ASC");
    $st->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $st->execute();
    $tasks = $st->fetchAll();
} catch (Exception $e) { $tasks = array(); }

// 내역서 공정(템플릿 후보)
$processes = array();
try {
    $stP = $pdo->prepare("
        SELECT
            TRIM(COALESCE(NULLIF(process_name, ''), item_name)) AS base_name,
            TRIM(spec) AS spec
        FROM cpms_project_unit_prices
        WHERE project_id = :pid
          AND COALESCE(NULLIF(process_name, ''), item_name) IS NOT NULL
          AND TRIM(COALESCE(NULLIF(process_name, ''), item_name)) <> ''
          AND spec IS NOT NULL
          AND TRIM(spec) <> ''
        ORDER BY base_name ASC, spec ASC
        LIMIT 300
    ");
    $stP->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $stP->execute();
    $rows = $stP->fetchAll();
    $grouped = array();
    $baseOrder = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $base = isset($row['base_name']) ? trim((string)$row['base_name']) : '';
            $spec = isset($row['spec']) ? trim((string)$row['spec']) : '';
            if ($base === '' || $spec === '') continue;
            if (!isset($grouped[$base])) {
                $grouped[$base] = array();
                $baseOrder[] = $base;
            }
            if (isset($grouped[$base][$spec])) continue;
            $grouped[$base][$spec] = true;
        }
    }
    foreach ($baseOrder as $base) {
        $specs = array_keys($grouped[$base]);
        if (count($specs) > 1) {
            foreach ($specs as $spec) {
                $processes[] = $base . ' (' . $spec . ')';
            }
        } else {
            $processes[] = $base;
        }
    }
} catch (Exception $e) {
    $processes = array();
}

// 간트 범위: 프로젝트 기간(있으면) 우선
$pStart = isset($projectRow['start_date']) ? trim((string)$projectRow['start_date']) : '';
$pEnd   = isset($projectRow['end_date']) ? trim((string)$projectRow['end_date']) : '';

function ymd_to_ts($ymd) {
    if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return 0;
    return strtotime($ymd . ' 00:00:00');
}

// 프로젝트 기간이 없으면 태스크 기간으로 대체
if ($pStart === '' || $pEnd === '') {
    $min = 0; $max = 0;
    foreach ($tasks as $t) {
        $sd = isset($t['start_date']) ? (string)$t['start_date'] : '';
        $ed = isset($t['end_date']) ? (string)$t['end_date'] : '';
        $ts1 = ymd_to_ts($sd);
        $ts2 = ymd_to_ts($ed);
        if ($ts1 > 0 && ($min === 0 || $ts1 < $min)) $min = $ts1;
        if ($ts2 > 0 && ($max === 0 || $ts2 > $max)) $max = $ts2;
    }
    if ($pStart === '' && $min > 0) $pStart = date('Y-m-d', $min);
    if ($pEnd === '' && $max > 0) $pEnd = date('Y-m-d', $max);
}

$rangeStartTs = ymd_to_ts($pStart);
$rangeEndTs   = ymd_to_ts($pEnd);
if ($rangeStartTs > 0 && $rangeEndTs > 0 && $rangeEndTs < $rangeStartTs) {
    // 역전 방지
    $tmp = $rangeStartTs; $rangeStartTs = $rangeEndTs; $rangeEndTs = $tmp;
}

// 범위가 없으면 30일짜리 임시
if ($rangeStartTs === 0 || $rangeEndTs === 0) {
    $rangeStartTs = strtotime(date('Y-m-d') . ' 00:00:00');
    $rangeEndTs = strtotime(date('Y-m-d', $rangeStartTs + 86400 * 30) . ' 00:00:00');
}

// 월 단위 보기(기본: 프로젝트 시작월)
$viewMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
$viewMonth = preg_match('/^\d{4}-\d{2}$/', $viewMonth) ? $viewMonth : '';
$baseStartTs = $rangeStartTs;
$baseEndTs = $rangeEndTs;
if ($viewMonth === '') {
    $viewMonth = date('Y-m', $baseStartTs);
}
$rangeStartTs = strtotime($viewMonth . '-01 00:00:00');
$rangeEndTs = strtotime(date('Y-m-t', $rangeStartTs) . ' 00:00:00');

$rangeDays = (int)floor(($rangeEndTs - $rangeStartTs) / 86400);
if ($rangeDays < 1) $rangeDays = 1;
$gridDays = $rangeDays + 1;

// 간트 날짜 라벨
$rangeDates = array();
$rangeYears = array();
$rangeMonths = array();
$monthOptions = array();
for ($i = 0; $i <= $rangeDays; $i++) {
    $ts = $rangeStartTs + ($i * 86400);
    $rangeDates[] = date('d', $ts);
    $rangeYears[] = date('Y', $ts);
    $rangeMonths[] = date('m', $ts);
}
// 월 옵션
$tmpTs = $baseStartTs;
while ($tmpTs <= $baseEndTs) {
    $monthKey = date('Y-m', $tmpTs);
    if (!isset($monthOptions[$monthKey])) {
        $monthOptions[$monthKey] = array(
            'label' => date('Y년 m월', $tmpTs),
            'start' => $monthKey . '-01',
        );
    }
    $tmpTs = strtotime(date('Y-m-01', $tmpTs) . ' +1 month');
}

function clamp($v, $min, $max) {
    if ($v < $min) return $min;
    if ($v > $max) return $max;
    return $v;
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">공정표</h3>
            <div class="text-sm text-gray-600 mt-1">프로젝트 기간에 맞춰 공정을 배치하고, 일정 변경 시 이슈등록으로 공유합니다.</div>
            <div class="text-xs text-gray-500 mt-1">공정표 기준 기간: <b><?php echo h(date('Y-m-d', $rangeStartTs)); ?></b> ~ <b><?php echo h(date('Y-m-d', $rangeEndTs)); ?></b></div>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" class="px-4 py-2 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 font-extrabold hover:bg-rose-100" data-modal-open="issueAdd">
                이슈등록
            </button>
        </div>
    </div>

    <?php if (count($tasks) === 0): ?>
        <div class="p-4 rounded-2xl border border-gray-200 bg-gray-50 text-gray-700">
            아직 공정표가 없습니다. <b>초안 생성(템플릿)</b> 또는 아래에서 직접 추가하세요.
        </div>
    <?php endif; ?>

    <div class="flex items-center gap-2 mt-6">
        <button type="button" class="gantt-tab px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold" data-tab="overview">공정표</button>
        <button type="button" class="gantt-tab px-4 py-2 rounded-2xl bg-gray-100 text-gray-700 font-extrabold" data-tab="board">공정표 수정</button>
        <button type="button" class="gantt-tab px-4 py-2 rounded-2xl bg-gray-100 text-gray-700 font-extrabold" data-tab="progress">현재 진행률</button>
    </div>

        <!-- 공정표 보기 -->
    <div class="mt-6 overflow-x-auto gantt-tab-panel" data-tab-panel="overview">
        <div class="flex items-center justify-between mb-2">
            <div class="text-xs text-gray-500">월 선택</div>
            <select class="gantt-month-select border border-gray-200 rounded-xl px-3 py-2 text-sm"
                    data-project-id="<?php echo (int)$pid; ?>"
                    data-tab="gantt">
                <?php foreach ($monthOptions as $key => $opt): ?>
                    <option value="<?php echo h($key); ?>" <?php echo ($key === $viewMonth) ? 'selected' : ''; ?>>
                        <?php echo h($opt['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="gantt-header"
             style="--day-width:48px; --grid-days:<?php echo (int)$gridDays; ?>;">
            <div class="gantt-header-spacer w-56 shrink-0"></div>
            <div class="gantt-header-rows">
                <div class="gantt-header-row">
                    <?php
                    $cur = $rangeYears[0];
                    $span = 0;
                    for ($i = 0; $i < count($rangeYears); $i++) {
                        if ($rangeYears[$i] !== $cur) {
                            echo '<div class="gantt-cell gantt-cell-year" style="width: calc(var(--day-width) * ' . $span . ');">' . h($cur) . '</div>';
                            $cur = $rangeYears[$i];
                            $span = 1;
                        } else {
                            $span++;
                        }
                    }
                    echo '<div class="gantt-cell gantt-cell-year" style="width: calc(var(--day-width) * ' . $span . ');">' . h($cur) . '</div>';
                    ?>
                </div>
                <div class="gantt-header-row">
                    <?php
                    $cur = $rangeMonths[0];
                    $span = 0;
                    for ($i = 0; $i < count($rangeMonths); $i++) {
                        if ($rangeMonths[$i] !== $cur) {
                            echo '<div class="gantt-cell gantt-cell-month" style="width: calc(var(--day-width) * ' . $span . ');">' . h($cur) . '</div>';
                            $cur = $rangeMonths[$i];
                            $span = 1;
                        } else {
                            $span++;
                        }
                    }
                    echo '<div class="gantt-cell gantt-cell-month" style="width: calc(var(--day-width) * ' . $span . ');">' . h($cur) . '</div>';
                    ?>
                </div>
                <div class="gantt-header-row">
                    <?php foreach ($rangeDates as $d): ?>
                        <div class="gantt-cell gantt-cell-day"><?php echo h($d); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="mt-2 space-y-2 gantt-board-readonly"
             data-range-start="<?php echo h(date('Y-m-d', $rangeStartTs)); ?>"
             data-range-days="<?php echo (int)$gridDays; ?>"
             style="--day-width:48px; --grid-days:<?php echo (int)$gridDays; ?>;">
            <?php foreach ($tasks as $t): ?>
                <?php
                $sd = isset($t['start_date']) ? (string)$t['start_date'] : '';
                $ed = isset($t['end_date']) ? (string)$t['end_date'] : '';
                $sdTs = ymd_to_ts($sd);
                $edTs = ymd_to_ts($ed);
                if ($sdTs > 0 && $edTs > 0 && $edTs < $sdTs) { $tmp = $sdTs; $sdTs = $edTs; $edTs = $tmp; }
                $leftPct = 0;
                $widthPct = 0;
                if ($sdTs > 0 && $edTs > 0) {
                    $leftDays = (int)floor(($sdTs - $rangeStartTs) / 86400);
                    $durDays  = (int)floor(($edTs - $sdTs) / 86400) + 1;
                    $leftDays = clamp($leftDays, 0, $gridDays - 1);
                    $maxDur   = $gridDays - $leftDays;
                    if ($maxDur < 1) $maxDur = 1;
                    $durDays  = clamp($durDays, 1, $maxDur);
                    $leftPct  = ($leftDays / $gridDays) * 100.0;
                    $widthPct = ($durDays / $gridDays) * 100.0;
                }
                ?>
                <div class="flex items-center gap-0 gantt-row"
                     data-task-name="<?php echo h($t['name']); ?>">
                    <div class="w-56 shrink-0 text-sm font-semibold text-gray-800 truncate pr-2">
                        <span class="truncate"><?php echo h($t['name']); ?></span>
                    </div>
                    <div class="gantt-dropzone relative h-11 flex-1 border border-gray-100 rounded-xl bg-gray-50 overflow-hidden"
                         data-start="<?php echo h($sd); ?>"
                         data-end="<?php echo h($ed); ?>">
                        <?php if ($widthPct > 0): ?>
                            <div class="gantt-bar absolute inset-y-0 rounded-lg bg-gradient-to-r from-blue-600 to-cyan-500 text-white text-xs flex items-center px-2"
                                 style="left: <?php echo (float)$leftPct; ?>%; width: <?php echo (float)$widthPct; ?>%; min-width: 28px;">
                                <span class="truncate"><?php echo h($t['name']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="text-xs text-gray-500 mt-3">공정표의 날짜 칸(네모칸)을 클릭하면 작업 수량과 사진을 등록할 수 있습니다.</div>
    </div>

    <!-- 드래그형 간트 보드 -->
    <div class="mt-6 rounded-3xl border border-gray-200 bg-white p-4 gantt-tab-panel hidden" data-tab-panel="board">
        <div class="flex flex-col lg:flex-row gap-4">
            <div class="lg:w-56 shrink-0">
                <div class="text-sm font-extrabold text-gray-900">내역서 공정</div>
                <div class="text-xs text-gray-500 mt-1">내역서에서 읽은 공정을 드래그해 일정에 배치하세요.</div>
                <div class="mt-3 space-y-2 max-h-80 overflow-auto">
                    <?php if (count($processes) === 0): ?>
                        <div class="text-xs text-gray-500">내역서 공정이 없습니다.</div>
                    <?php else: ?>
                        <?php foreach ($processes as $pname): ?>
                            <div class="gantt-draggable px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-sm font-semibold cursor-move"
                                 draggable="true"
                                 data-task-name="<?php echo h($pname); ?>">
                                <?php echo h($pname); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex-1 overflow-x-auto">
                <div class="min-w-max">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs text-gray-500">월 선택</div>
                        <select class="gantt-month-select border border-gray-200 rounded-xl px-3 py-2 text-sm"
                                data-project-id="<?php echo (int)$pid; ?>"
                                data-tab="gantt">
                            <?php foreach ($monthOptions as $key => $opt): ?>
                                <option value="<?php echo h($key); ?>" <?php echo ($key === $viewMonth) ? 'selected' : ''; ?>>
                                    <?php echo h($opt['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="gantt-header"
                         style="--day-width:48px; --grid-days:<?php echo (int)$gridDays; ?>;">
                        <div class="gantt-header-spacer w-56 shrink-0"></div>
                        <div class="gantt-header-rows">
                            <div class="gantt-header-row">
                                <?php
                                $cur = $rangeYears[0];
                                $span = 0;
                                for ($i = 0; $i < count($rangeYears); $i++) {
                                    if ($rangeYears[$i] !== $cur) {
                                        echo '<div class="gantt-cell gantt-cell-year" style="width: calc(var(--day-width) * ' . $span . ');">' . h($cur) . '</div>';
                                        $cur = $rangeYears[$i];
                                        $span = 1;
                                    } else {
                                        $span++;
                                    }
                                }
                                echo '<div class="gantt-cell gantt-cell-year" style="width: calc(var(--day-width) * ' . $span . ');">' . h($cur) . '</div>';
                                ?>
                            </div>
                            <div class="gantt-header-row">
                                <?php
                                $cur = $rangeMonths[0];
                                $span = 0;
                                for ($i = 0; $i < count($rangeMonths); $i++) {
                                    if ($rangeMonths[$i] !== $cur) {
                                        echo '<div class="gantt-cell gantt-cell-month" style="width: calc(var(--day-width) * ' . $span . ');">' . h($cur) . '</div>';
                                        $cur = $rangeMonths[$i];
                                        $span = 1;
                                    } else {
                                        $span++;
                                    }
                                }
                                echo '<div class="gantt-cell gantt-cell-month" style="width: calc(var(--day-width) * ' . $span . ');">' . h($cur) . '</div>';
                                ?>
                            </div>
                            <div class="gantt-header-row">
                                <?php foreach ($rangeDates as $d): ?>
                                    <div class="gantt-cell gantt-cell-day"><?php echo h($d); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mt-2 space-y-2 gantt-board"
                         data-range-start="<?php echo h(date('Y-m-d', $rangeStartTs)); ?>"
                         data-range-days="<?php echo (int)$gridDays; ?>"
                         data-project-id="<?php echo (int)$pid; ?>"
                         data-csrf="<?php echo h(csrf_token()); ?>"
                         style="--day-width:48px; --grid-days:<?php echo (int)$gridDays; ?>;">
                        <?php foreach ($tasks as $t): ?>
                            <?php
                            $sd = isset($t['start_date']) ? (string)$t['start_date'] : '';
                            $ed = isset($t['end_date']) ? (string)$t['end_date'] : '';
                            $sdTs = ymd_to_ts($sd);
                            $edTs = ymd_to_ts($ed);
                            if ($sdTs > 0 && $edTs > 0 && $edTs < $sdTs) { $tmp = $sdTs; $sdTs = $edTs; $edTs = $tmp; }
                            $leftPct = 0;
                            $widthPct = 0;
                            if ($sdTs > 0 && $edTs > 0) {
                                $leftDays = (int)floor(($sdTs - $rangeStartTs) / 86400);
                                $durDays  = (int)floor(($edTs - $sdTs) / 86400) + 1;
                                $leftDays = clamp($leftDays, 0, $gridDays - 1);
                                $maxDur   = $gridDays - $leftDays;
                                if ($maxDur < 1) $maxDur = 1;
                                $durDays  = clamp($durDays, 1, $maxDur);
                                $leftPct  = ($leftDays / $gridDays) * 100.0;
                                $widthPct = ($durDays / $gridDays) * 100.0;
                            }
                            ?>
                            <div class="flex items-center gap-0 gantt-row"
                                 data-task-id="<?php echo (int)$t['id']; ?>"
                                 data-task-name="<?php echo h($t['name']); ?>"
                                 data-task-progress="<?php echo (int)$t['progress']; ?>">
                                <div class="w-56 shrink-0 text-sm font-semibold text-gray-800 truncate pr-2 flex items-center gap-2">
                                    <span class="truncate"><?php echo h($t['name']); ?></span>
                                    <?php if ($canEdit): ?>
                                        <button type="button"
                                                class="gantt-delete text-xs px-2 py-1 rounded-lg border border-rose-200 text-rose-700 bg-rose-50"
                                                data-task-id="<?php echo (int)$t['id']; ?>">
                                            삭제
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="gantt-dropzone relative h-11 flex-1 border border-gray-100 rounded-xl bg-gray-50 overflow-hidden"
                                     data-start="<?php echo h($sd); ?>"
                                     data-end="<?php echo h($ed); ?>">
                                    <div class="gantt-bar absolute inset-y-0 rounded-lg bg-gradient-to-r from-blue-600 to-cyan-500 text-white text-xs flex items-center px-2"
                                         style="left: <?php echo (float)$leftPct; ?>%; width: <?php echo (float)$widthPct; ?>%; min-width: 28px;"
                                         draggable="true">
                                        <span class="truncate"><?php echo h($t['name']); ?></span>
                                        <span class="gantt-handle gantt-handle-left"></span>
                                        <span class="gantt-handle gantt-handle-right"></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($canEdit): ?>
                            <div class="flex items-center gap-0 gantt-row gantt-new-row" data-task-id="0">
                                <div class="w-56 shrink-0 text-sm text-gray-500 pr-2">+ 드래그해 공정 추가</div>
                                <div class="gantt-dropzone relative h-11 flex-1 border border-dashed border-gray-200 rounded-xl bg-white overflow-hidden"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 text-xs text-gray-500">
            팁: 공정을 드래그해 일정에 추가하고, 바를 드래그해 이동하거나 양쪽 핸들로 기간을 조절할 수 있습니다.
        </div>
    </div>

    <!-- 공정표 테이블 -->
    <div class="mt-6 overflow-x-auto gantt-tab-panel hidden" data-tab-panel="progress">
        <table class="min-w-full text-sm">
            <thead>
            <tr class="text-left text-gray-500">
                <th class="py-2 pr-2">공정</th>
                <th class="py-2 pr-2">시작</th>
                <th class="py-2 pr-2">종료</th>
                <th class="py-2 pr-2">진행률</th>
                <th class="py-2 pr-2">일정</th>
                <?php if ($canEdit): ?><th class="py-2">작업</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $t): ?>
                <?php
                $sd = isset($t['start_date']) ? (string)$t['start_date'] : '';
                $ed = isset($t['end_date']) ? (string)$t['end_date'] : '';
                $sdTs = ymd_to_ts($sd);
                $edTs = ymd_to_ts($ed);
                if ($sdTs > 0 && $edTs > 0 && $edTs < $sdTs) { $tmp = $sdTs; $sdTs = $edTs; $edTs = $tmp; }

                // bar 계산(기간 없으면 0)
                $leftPct = 0;
                $widthPct = 0;
                if ($sdTs > 0 && $edTs > 0) {
                    $leftDays = (int)floor(($sdTs - $rangeStartTs) / 86400);
                    $durDays  = (int)floor(($edTs - $sdTs) / 86400) + 1;
                    $leftDays = clamp($leftDays, 0, $gridDays - 1);
                    $maxDur   = $gridDays - $leftDays;
                    if ($maxDur < 1) $maxDur = 1;
                    $durDays  = clamp($durDays, 1, $maxDur);
                    $leftPct  = ($leftDays / $gridDays) * 100.0;
                    $widthPct = ($durDays / $gridDays) * 100.0;
                }
                $pr = isset($t['progress']) ? (int)$t['progress'] : 0;
                if ($pr < 0) $pr = 0; if ($pr > 100) $pr = 100;
                ?>
                <tr class="border-t border-gray-100">
                    <?php if ($canEdit): ?>
                        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/schedule_save">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                            <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                            <td class="py-2 pr-2">
                                <input name="name" value="<?php echo h($t['name']); ?>" class="w-64 px-3 py-2 rounded-2xl border border-gray-200">
                            </td>
                            <td class="py-2 pr-2"><input type="date" name="start_date" value="<?php echo h($sd); ?>" class="px-3 py-2 rounded-2xl border border-gray-200"></td>
                            <td class="py-2 pr-2"><input type="date" name="end_date" value="<?php echo h($ed); ?>" class="px-3 py-2 rounded-2xl border border-gray-200"></td>
                            <td class="py-2 pr-2">
                                <input type="number" name="progress" min="0" max="100" value="<?php echo (int)$pr; ?>" class="w-24 px-3 py-2 rounded-2xl border border-gray-200">%
                            </td>
                            <td class="py-2 pr-2">
                                <div class="h-4 rounded-full bg-gray-100 overflow-hidden relative" style="min-width:260px">
                                    <?php if ($widthPct > 0): ?>
                                        <div class="absolute top-0 bottom-0 rounded-full bg-gradient-to-r from-blue-600 to-cyan-500" style="left: <?php echo (float)$leftPct; ?>%; width: <?php echo (float)$widthPct; ?>%;"></div>
                                        <div class="absolute top-0 bottom-0 rounded-full bg-black/20" style="left: <?php echo (float)$leftPct; ?>%; width: <?php echo (float)$widthPct * ($pr/100.0); ?>%;"></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <button type="submit" class="px-3 py-2 rounded-2xl bg-gray-900 text-white text-xs font-extrabold">저장</button>
                                </form>

                                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/schedule_delete" onsubmit="return confirm('삭제할까요?');">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                    <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                                    <button type="submit" class="px-3 py-2 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-extrabold">삭제</button>
                                </form>
                                </div>
                            </td>
                    <?php else: ?>
                        <td class="py-2 pr-2 font-extrabold text-gray-900"><?php echo h($t['name']); ?></td>
                        <td class="py-2 pr-2 text-gray-700"><?php echo h($sd); ?></td>
                        <td class="py-2 pr-2 text-gray-700"><?php echo h($ed); ?></td>
                        <td class="py-2 pr-2 text-gray-700"><?php echo (int)$pr; ?>%</td>
                        <td class="py-2 pr-2">
                            <div class="h-4 rounded-full bg-gray-100 overflow-hidden relative" style="min-width:260px">
                                <?php if ($widthPct > 0): ?>
                                    <div class="absolute top-0 bottom-0 rounded-full bg-gradient-to-r from-blue-600 to-cyan-500" style="left: <?php echo (float)$leftPct; ?>%; width: <?php echo (float)$widthPct; ?>%;"></div>
                                    <div class="absolute top-0 bottom-0 rounded-full bg-black/20" style="left: <?php echo (float)$leftPct; ?>%; width: <?php echo (float)$widthPct * ($pr/100.0); ?>%;"></div>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 새 태스크 추가 -->
    <?php if ($canEdit): ?>
        <div class="mt-6 p-4 rounded-2xl border border-gray-200 bg-gray-50">
            <div class="font-extrabold text-gray-900 mb-3">공정 추가</div>
            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/schedule_save" class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                <input type="hidden" name="task_id" value="0">

                <input name="name" class="md:col-span-2 px-4 py-3 rounded-2xl border border-gray-200" placeholder="공정명(예: 골조공사)">
                <input type="date" name="start_date" class="px-4 py-3 rounded-2xl border border-gray-200">
                <input type="date" name="end_date" class="px-4 py-3 rounded-2xl border border-gray-200">
                <div class="flex items-center gap-2">
                    <input type="number" name="progress" min="0" max="100" value="0" class="w-24 px-4 py-3 rounded-2xl border border-gray-200">%
                    <button type="submit" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-extrabold">추가</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="mt-4 text-sm text-gray-500">※ 수정/삭제 권한이 없습니다. (공사/임원만)</div>
    <?php endif; ?>

</div>

<!-- 공정 진행 입력 모달 -->
<div id="modal-ganttProgress" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-modal-close="ganttProgress"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-extrabold text-gray-900">공정 진행 입력</h3>
                    <div class="text-xs text-gray-500 mt-1">
                        <span id="ganttProgressTaskName"></span>
                        <span class="mx-2">·</span>
                        <span id="ganttProgressTaskDate"></span>
                    </div>
                </div>
                <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="ganttProgress">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="p-6 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs font-bold text-gray-500">전체 수량</label>
                        <input id="ganttTotalQty" type="number" min="0" class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 w-full" placeholder="예: 120">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">완료 수량</label>
                        <input id="ganttDoneQty" type="number" min="0" class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 w-full" placeholder="예: 80">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">남은 수량</label>
                        <div class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 w-full bg-gray-50 text-gray-700 font-extrabold" id="ganttRemainQty">0</div>
                    </div>
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">작업 사진 업로드</label>
                    <input id="ganttPhotoInput" type="file" multiple class="mt-2 block w-full text-sm text-gray-700">
                    <div id="ganttPhotoList" class="mt-3 space-y-2 text-sm text-gray-700"></div>
                    <div class="text-xs text-gray-500 mt-2">업로드한 사진은 아래에서 바로 확인 및 다운로드할 수 있습니다.</div>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <button type="button" class="px-4 py-2 rounded-2xl border border-gray-200 text-gray-700 font-extrabold" data-modal-close="ganttProgress">닫기</button>
                    <button type="button" class="px-5 py-2 rounded-2xl bg-gray-900 text-white font-extrabold" data-modal-close="ganttProgress">저장</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
  .gantt-header { display: flex; align-items: stretch; width: auto; }
  .gantt-header-spacer { flex: 0 0 14rem; }
  .gantt-header-rows { width: calc(var(--day-width) * var(--grid-days)); border: 1px solid #e5e7eb; border-radius: 16px; overflow: hidden; }
  .gantt-header-row { display: flex; }
  .gantt-cell {
    width: var(--day-width);
    box-sizing: border-box;
    text-align: center;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    padding: 6px 0;
    font-weight: 700;
    color: #334155;
  }
  .gantt-cell-year { background: #eef2ff; font-size: 16px; }
  .gantt-cell-month { background: #f1f5f9; font-size: 15px; }
  .gantt-cell-day { background: #f8fafc; font-size: 14px; }
  .gantt-header-row:last-child .gantt-cell { border-bottom: none; }
  .gantt-header-row .gantt-cell:last-child { border-right: none; }
  .gantt-dropzone {
    min-width: calc(var(--day-width) * var(--grid-days));
    background-size: var(--day-width) 100%;
    background-image: repeating-linear-gradient(
      to right,
      rgba(148,163,184,0.35) 0,
      rgba(148,163,184,0.35) 1px,
      transparent 1px,
      transparent calc(var(--day-width))
    );
  }
  .gantt-bar { cursor: grab; }
  .gantt-bar.dragging { opacity: 0.7; cursor: grabbing; }
  .gantt-board-readonly .gantt-bar { cursor: default; }
  .gantt-handle {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 8px;
    cursor: ew-resize;
  }
  .gantt-handle-left { left: -2px; }
  .gantt-handle-right { right: -2px; }
</style>

<script>
(function(){
  var board = document.querySelector('.gantt-board');
  var readOnlyBoard = document.querySelector('.gantt-board-readonly');
  if (!board && !readOnlyBoard) return;
  var rangeSource = board || readOnlyBoard;
  var rangeStart = rangeSource.getAttribute('data-range-start');
  var gridDays = parseInt(rangeSource.getAttribute('data-range-days'), 10) || 1;
  var projectId = board ? board.getAttribute('data-project-id') : null;
  var csrfToken = board ? board.getAttribute('data-csrf') : null;

  function ymdToTs(ymd){
    if (!ymd) return 0;
    var parts = ymd.split('-');
    if (parts.length !== 3) return 0;
    return Date.UTC(parseInt(parts[0],10), parseInt(parts[1],10)-1, parseInt(parts[2],10)) / 1000;
  }
  function pad2(n){
    return (n < 10 ? '0' : '') + n;
  }
  function tsToYmd(ts){
    var d = new Date(ts * 1000);
    var y = d.getUTCFullYear();
    var m = pad2(d.getUTCMonth()+1);
    var day = pad2(d.getUTCDate());
    return y + '-' + m + '-' + day;
  }
  var rangeStartTs = ymdToTs(rangeStart);

  function clamp(v, min, max){
    return Math.max(min, Math.min(max, v));
  }

  function saveTask(taskId, name, startDate, endDate, progress){
    var fd = new FormData();
    if (!board) return;
    fd.append('_csrf', csrfToken || '');
    fd.append('project_id', projectId || '0');
    fd.append('task_id', taskId || '0');
    fd.append('name', name || '');
    fd.append('start_date', startDate || '');
    fd.append('end_date', endDate || '');
    fd.append('progress', progress || '0');

    fetch('?r=construction/schedule_save', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function(){ window.location.reload(); })
      .catch(function(){ window.location.reload(); });
  }

  function deleteTask(taskId){
    if (!board) return;
    var fd = new FormData();
    fd.append('_csrf', csrfToken || '');
    fd.append('project_id', projectId || '0');
    fd.append('task_id', taskId || '0');
    fetch('?r=construction/schedule_delete', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function(){ window.location.reload(); })
      .catch(function(){ window.location.reload(); });
  }

  function dayFromOffset(offsetX, zoneWidth){
    var pct = clamp(offsetX / zoneWidth, 0, 1);
    return Math.floor(pct * gridDays);
  }

  function setBarPosition(bar, leftDays, duration){
    var leftPct = (leftDays / gridDays) * 100;
    var widthPct = (duration / gridDays) * 100;
    bar.style.left = leftPct + '%';
    bar.style.width = widthPct + '%';
  }

  if (board) {
    var dragName = '';
    var dragEl = null;
    board.querySelectorAll('.gantt-draggable').forEach(function(el){
      el.addEventListener('dragstart', function(e){
        dragName = el.getAttribute('data-task-name') || el.textContent.trim();
        dragEl = el;
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'copy';
          e.dataTransfer.setData('text/plain', dragName);
        }
      });
    });

    board.querySelectorAll('.gantt-dropzone').forEach(function(zone){
      zone.addEventListener('dragover', function(e){ e.preventDefault(); });
      zone.addEventListener('drop', function(e){
        e.preventDefault();
        var droppedName = '';
        if (e.dataTransfer) {
          droppedName = e.dataTransfer.getData('text/plain') || '';
        }
        if (!droppedName) droppedName = dragName || '';
        if (!droppedName) return;
        var zoneRect = zone.getBoundingClientRect();
        var offsetX = e.clientX - zoneRect.left;
        var leftDays = dayFromOffset(offsetX, zoneRect.width);
        var startTs = rangeStartTs + (leftDays * 86400);
        var endTs = startTs + (3 * 86400);
        saveTask(0, droppedName, tsToYmd(startTs), tsToYmd(endTs), 0);
        if (dragEl && dragEl.parentNode) {
          dragEl.parentNode.removeChild(dragEl);
        }
        dragName = '';
        dragEl = null;
      });
    });
  }

  if (board) {
    board.querySelectorAll('.gantt-bar').forEach(function(bar){
    var row = bar.closest('.gantt-row');
    var zone = bar.closest('.gantt-dropzone');
    var taskId = row ? row.getAttribute('data-task-id') : '0';
    var taskName = row ? row.getAttribute('data-task-name') : '';
    var progress = row ? row.getAttribute('data-task-progress') : '0';

    var startDate = zone ? zone.getAttribute('data-start') : '';
    var endDate = zone ? zone.getAttribute('data-end') : '';

    var dragging = false;
    var resizing = null;
    var startX = 0;
    var origLeft = 0;
    var origWidth = 0;

    function onMouseMove(e){
      if (!dragging && !resizing) return;
      var zoneRect = zone.getBoundingClientRect();
      var delta = e.clientX - startX;
      var pctDelta = delta / zoneRect.width;
      if (dragging) {
        var newLeft = clamp(origLeft + pctDelta, 0, 1 - origWidth);
        bar.style.left = (newLeft * 100) + '%';
      } else if (resizing === 'left') {
        var newLeftL = clamp(origLeft + pctDelta, 0, origLeft + origWidth - (1 / gridDays));
        var newWidthL = (origLeft + origWidth) - newLeftL;
        bar.style.left = (newLeftL * 100) + '%';
        bar.style.width = (newWidthL * 100) + '%';
      } else if (resizing === 'right') {
        var newWidth = clamp(origWidth + pctDelta, (1 / gridDays), 1 - origLeft);
        bar.style.width = (newWidth * 100) + '%';
      }
    }

    function onMouseUp(){
      if (!dragging && !resizing) return;
      var zoneRect = zone.getBoundingClientRect();
      var leftPct = parseFloat(bar.style.left || '0') / 100;
      var widthPct = parseFloat(bar.style.width || '0') / 100;
      var leftDays = Math.round(leftPct * gridDays);
      var durDays = Math.max(1, Math.round(widthPct * gridDays));
      var startTs = rangeStartTs + (leftDays * 86400);
      var endTs = startTs + ((durDays - 1) * 86400);
      saveTask(taskId, taskName, tsToYmd(startTs), tsToYmd(endTs), progress);
      dragging = false;
      resizing = null;
      bar.classList.remove('dragging');
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);
    }

    bar.addEventListener('mousedown', function(e){
      if (e.target.classList.contains('gantt-handle')) return;
      dragging = true;
      startX = e.clientX;
      bar.classList.add('dragging');
      origLeft = parseFloat(bar.style.left || '0') / 100;
      origWidth = parseFloat(bar.style.width || '0') / 100;
      document.addEventListener('mousemove', onMouseMove);
      document.addEventListener('mouseup', onMouseUp);
    });

    bar.querySelectorAll('.gantt-handle').forEach(function(handle){
      handle.addEventListener('mousedown', function(e){
        e.stopPropagation();
        resizing = handle.classList.contains('gantt-handle-left') ? 'left' : 'right';
        startX = e.clientX;
        bar.classList.add('dragging');
        origLeft = parseFloat(bar.style.left || '0') / 100;
        origWidth = parseFloat(bar.style.width || '0') / 100;
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
      });
    });
    });
  }

  function openProgress(taskName, taskDate){
    var nameEl = document.getElementById('ganttProgressTaskName');
    var dateEl = document.getElementById('ganttProgressTaskDate');
    if (nameEl) nameEl.textContent = taskName;
    if (dateEl) dateEl.textContent = taskDate;

    var totalEl = document.getElementById('ganttTotalQty');
    var doneEl = document.getElementById('ganttDoneQty');
    if (totalEl) totalEl.value = '';
    if (doneEl) doneEl.value = '';
    updateRemainQty();

    var listEl = document.getElementById('ganttPhotoList');
    if (listEl) listEl.innerHTML = '';
    var inputEl = document.getElementById('ganttPhotoInput');
    if (inputEl) inputEl.value = '';

    openModal('ganttProgress');
  }

  if (readOnlyBoard) {
    readOnlyBoard.querySelectorAll('.gantt-dropzone').forEach(function(zone){
      zone.addEventListener('click', function(e){
        if (e.target.closest('.gantt-bar')) return;
        var row = zone.closest('.gantt-row');
        var taskName = row ? (row.getAttribute('data-task-name') || '') : '';
        var zoneRect = zone.getBoundingClientRect();
        var offsetX = e.clientX - zoneRect.left;
        var leftDays = dayFromOffset(offsetX, zoneRect.width);
        var dateTs = rangeStartTs + (leftDays * 86400);
        openProgress(taskName, tsToYmd(dateTs));
      });
    });
  }

  if (board) {
    board.querySelectorAll('.gantt-delete').forEach(function(btn){
      btn.addEventListener('click', function(){
        var taskId = btn.getAttribute('data-task-id') || '0';
        if (!taskId || taskId === '0') return;
        if (!confirm('이 공정을 삭제할까요?')) return;
        deleteTask(taskId);
      });
    });
  }

  document.querySelectorAll('.gantt-tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      var target = btn.getAttribute('data-tab');
      document.querySelectorAll('.gantt-tab').forEach(function(t){
        t.classList.remove('bg-gray-900', 'text-white');
        t.classList.add('bg-gray-100', 'text-gray-700');
      });
      btn.classList.add('bg-gray-900', 'text-white');
      btn.classList.remove('bg-gray-100', 'text-gray-700');
      document.querySelectorAll('.gantt-tab-panel').forEach(function(panel){
        if (panel.getAttribute('data-tab-panel') === target) {
          panel.classList.remove('hidden');
        } else {
          panel.classList.add('hidden');
        }
      });
    });
  });

  function openModal(key){
    var modal = document.getElementById('modal-' + key);
    if (modal) modal.classList.remove('hidden');
  }
  function closeModal(key){
    var modal = document.getElementById('modal-' + key);
    if (modal) modal.classList.add('hidden');
  }

  document.querySelectorAll('[data-modal-open]').forEach(function(btn){
    btn.addEventListener('click', function(){
      openModal(btn.getAttribute('data-modal-open'));
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach(function(btn){
    btn.addEventListener('click', function(){
      closeModal(btn.getAttribute('data-modal-close'));
    });
  });

  function updateRemainQty(){
    var totalEl = document.getElementById('ganttTotalQty');
    var doneEl = document.getElementById('ganttDoneQty');
    var remainEl = document.getElementById('ganttRemainQty');
    if (!totalEl || !doneEl || !remainEl) return;
    var total = parseFloat(totalEl.value || '0');
    var done = parseFloat(doneEl.value || '0');
    if (isNaN(total)) total = 0;
    if (isNaN(done)) done = 0;
    var remain = total - done;
    if (remain < 0) remain = 0;
    remainEl.textContent = remain.toString();
  }

  document.querySelectorAll('.gantt-progress-date').forEach(function(btn){
    btn.addEventListener('click', function(){
      var taskName = btn.getAttribute('data-task-name') || '';
      var taskDate = btn.getAttribute('data-task-date') || '';
      openProgress(taskName, taskDate);
    });
  });

  var totalQtyInput = document.getElementById('ganttTotalQty');
  if (totalQtyInput) totalQtyInput.addEventListener('input', updateRemainQty);
  var doneQtyInput = document.getElementById('ganttDoneQty');
  if (doneQtyInput) doneQtyInput.addEventListener('input', updateRemainQty);

  var photoInput = document.getElementById('ganttPhotoInput');
  if (photoInput) {
    photoInput.addEventListener('change', function(){
      var listEl = document.getElementById('ganttPhotoList');
      if (!listEl) return;
      listEl.innerHTML = '';
      if (!photoInput.files || photoInput.files.length === 0) return;
      Array.prototype.forEach.call(photoInput.files, function(file){
        var url = URL.createObjectURL(file);
        var row = document.createElement('div');
        row.className = 'flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-3 py-2';
        row.innerHTML = '<span class="truncate">' + file.name + '</span>' +
          '<div class="flex items-center gap-2">' +
          '<a class="text-blue-700 underline text-xs" href="' + url + '" target="_blank" rel="noopener">보기</a>' +
          '<a class="text-blue-700 underline text-xs" href="' + url + '" download="' + file.name + '">다운로드</a>' +
          '</div>';
        listEl.appendChild(row);
      });
    });
  }

  document.querySelectorAll('.gantt-month-select').forEach(function(monthSelect){
    monthSelect.addEventListener('change', function(){
      var chosen = monthSelect.value;
      if (!chosen) return;
      var pid = monthSelect.getAttribute('data-project-id') || '';
      var tab = monthSelect.getAttribute('data-tab') || 'gantt';
      var params = new URLSearchParams(window.location.search);
      params.set('r', '공사');
      if (pid) params.set('pid', pid);
      params.set('tab', tab);
      params.set('month', chosen);
      window.location.search = params.toString();
    });
  });
})();
</script>