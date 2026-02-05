<?php
/**
 * C:\www\cpms\app\views\construction\tabs\gantt.php
 * - 공사: 공정표(간트) 탭
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

$rangeDays = (int)floor(($rangeEndTs - $rangeStartTs) / 86400);
if ($rangeDays < 1) $rangeDays = 1;

function clamp($v, $min, $max) {
    if ($v < $min) return $min;
    if ($v > $max) return $max;
    return $v;
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">공정표(간트)</h3>
            <div class="text-sm text-gray-600 mt-1">프로젝트 기간에 맞춰 공정을 배치하고, 일정 변경 시 이슈등록으로 공유합니다.</div>
            <div class="text-xs text-gray-500 mt-1">간트 기준 기간: <b><?php echo h(date('Y-m-d', $rangeStartTs)); ?></b> ~ <b><?php echo h(date('Y-m-d', $rangeEndTs)); ?></b></div>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" class="px-4 py-2 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 font-extrabold hover:bg-rose-100" data-modal-open="issueAdd">
                이슈등록
            </button>

            <?php if ($canEdit): ?>
                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/schedule_seed_from_template" class="inline">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <button type="submit" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">초안 생성(템플릿)</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($tasks) === 0): ?>
        <div class="p-4 rounded-2xl border border-gray-200 bg-gray-50 text-gray-700">
            아직 공정표가 없습니다. <b>초안 생성(템플릿)</b> 또는 아래에서 직접 추가하세요.
        </div>
    <?php endif; ?>

    <!-- 공정표 테이블 -->
    <div class="mt-4 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
            <tr class="text-left text-gray-500">
                <th class="py-2 pr-2">공정</th>
                <th class="py-2 pr-2">시작</th>
                <th class="py-2 pr-2">종료</th>
                <th class="py-2 pr-2">진행률</th>
                <th class="py-2 pr-2">간트</th>
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
                    $leftDays = clamp($leftDays, 0, $rangeDays);
                    $durDays  = clamp($durDays, 1, $rangeDays);
                    $leftPct  = ($leftDays / $rangeDays) * 100.0;
                    $widthPct = ($durDays / $rangeDays) * 100.0;
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
