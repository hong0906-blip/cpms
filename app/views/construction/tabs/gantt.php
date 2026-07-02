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
if (!function_exists('cpms_gantt_unit_price_value')) {
function cpms_gantt_unit_price_value($row) {
    $unitPrice = (isset($row['unit_price']) && is_numeric((string)$row['unit_price'])) ? (float)$row['unit_price'] : 0.0;
    if (abs($unitPrice) > 0.0001) return $unitPrice;
    $material = (isset($row['material_unit_price']) && is_numeric((string)$row['material_unit_price'])) ? (float)$row['material_unit_price'] : 0.0;
    $labor = (isset($row['labor_unit_price']) && is_numeric((string)$row['labor_unit_price'])) ? (float)$row['labor_unit_price'] : 0.0;
    $expense = (isset($row['expense_unit_price']) && is_numeric((string)$row['expense_unit_price'])) ? (float)$row['expense_unit_price'] : 0.0;
    return $material + $labor + $expense;
}}

if (!function_exists('cpms_gantt_column_exists')) {
function cpms_gantt_column_exists($pdo, $table, $column) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', $column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

$hasGanttMaterialUnitPrice = cpms_gantt_column_exists($pdo, 'cpms_project_unit_prices', 'material_unit_price');
$hasGanttLaborUnitPrice = cpms_gantt_column_exists($pdo, 'cpms_project_unit_prices', 'labor_unit_price');
$hasGanttExpenseUnitPrice = cpms_gantt_column_exists($pdo, 'cpms_project_unit_prices', 'expense_unit_price');

require_once __DIR__ . '/../partials/schedule_auto_progress_helper.php';
require_once __DIR__ . '/../../../services/ConstructionDriveService.php';
cpms_construction_drive_ensure_table_columns($pdo, 'cpms_schedule_progress_photos');
cpms_schedule_apply_auto_progress($pdo, (int)$pid);
$debugAutoProgress = isset($_GET['debug_auto_progress']) && (string)$_GET['debug_auto_progress'] === '1';
$autoProgressDiagnostics = $debugAutoProgress ? cpms_schedule_auto_progress_diagnostics($pdo, (int)$pid) : array();

$tasks = array();
try {
    $st = $pdo->prepare("SELECT * FROM cpms_schedule_tasks WHERE project_id = :pid ORDER BY sort_order ASC, id ASC");
    $st->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $st->execute();
    $tasks = $st->fetchAll();
} catch (Exception $e) { $tasks = array(); }

// 작업내용 목록/상세
$workItems = array();
$workMap = array();
$workDetailMap = array();
$taskQtyMap = array();
try {
    $stW = $pdo->prepare("SELECT * FROM cpms_work_items WHERE project_id = :pid AND is_deleted = 0 ORDER BY sort_order ASC, id ASC");
    $stW->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $stW->execute();
    $workItems = $stW->fetchAll();
    if (is_array($workItems)) {
        foreach ($workItems as $w) {
            $wid = isset($w['id']) ? (int)$w['id'] : 0;
            if ($wid <= 0) continue;
            $workMap[$wid] = $w;
            $workDetailMap[$wid] = array(
                'id' => $wid,
                'title' => isset($w['title']) ? (string)$w['title'] : '',
                'description' => isset($w['description']) ? (string)$w['description'] : '',
                'lines' => array(),
                'total_amount' => 0
            );
        }
    }

    if (count($workMap) > 0) {
        $sqlWL = "SELECT l.work_id, l.unit_price_id, l.planned_qty, u.item_name, u.unit, u.qty, u.unit_price";
        $sqlWL .= $hasGanttMaterialUnitPrice ? ", u.material_unit_price" : ", NULL AS material_unit_price";
        $sqlWL .= $hasGanttLaborUnitPrice ? ", u.labor_unit_price" : ", NULL AS labor_unit_price";
        $sqlWL .= $hasGanttExpenseUnitPrice ? ", u.expense_unit_price" : ", NULL AS expense_unit_price";
        $sqlWL .= " FROM cpms_work_item_lines l INNER JOIN cpms_project_unit_prices u ON u.id = l.unit_price_id WHERE u.project_id = :pid ORDER BY l.work_id ASC, u.id ASC";
        $stWL = $pdo->prepare($sqlWL);
        $stWL->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
        $stWL->execute();
        $lineRows = $stWL->fetchAll();
        if (is_array($lineRows)) {
            foreach ($lineRows as $lr) {
                $wid = isset($lr['work_id']) ? (int)$lr['work_id'] : 0;
                if ($wid <= 0 || !isset($workDetailMap[$wid])) continue;
                $qtyRaw = (isset($lr['planned_qty']) && $lr['planned_qty'] !== null && $lr['planned_qty'] !== '') ? $lr['planned_qty'] : $lr['qty'];
                $qtyUsed = is_numeric((string)$qtyRaw) ? (float)$qtyRaw : 0;
                $unitPrice = cpms_gantt_unit_price_value($lr);
                $lineAmount = $qtyUsed * $unitPrice;
                $workDetailMap[$wid]['lines'][] = array(
                    'item_name' => isset($lr['item_name']) ? (string)$lr['item_name'] : '',
                    'unit' => isset($lr['unit']) ? (string)$lr['unit'] : '',
                    'qty_used' => $qtyUsed,
                    'unit_price' => $unitPrice,
                    'amount' => $lineAmount
                );
                $workDetailMap[$wid]['total_amount'] += $lineAmount;
                if (!isset($taskQtyMap[$wid])) $taskQtyMap[$wid] = 0;
                $taskQtyMap[$wid] += $qtyUsed;
            }
        }
    }
} catch (Exception $e) {
    $workItems = array();
    $workMap = array();
    $workDetailMap = array();  
    $taskQtyMap = array();
}

// 항목별 수량표/저장: 공정(work_id)별 내역서 항목 매핑 + 완료수량 맵
$taskItemMap = array();
$taskItemDoneMap = array();
try {
    $workLineMap = array();
    $sqlLine = "SELECT wil.work_id, wil.unit_price_id, wil.planned_qty, upl.item_name, upl.unit, upl.qty AS contract_qty, upl.unit_price";
    $sqlLine .= $hasGanttMaterialUnitPrice ? ", upl.material_unit_price" : ", NULL AS material_unit_price";
    $sqlLine .= $hasGanttLaborUnitPrice ? ", upl.labor_unit_price" : ", NULL AS labor_unit_price";
    $sqlLine .= $hasGanttExpenseUnitPrice ? ", upl.expense_unit_price" : ", NULL AS expense_unit_price";
    $sqlLine .= " FROM cpms_work_item_lines wil INNER JOIN cpms_project_unit_prices upl ON upl.id = wil.unit_price_id WHERE upl.project_id = :pid ORDER BY wil.work_id ASC, upl.id ASC";
    $stLine = $pdo->prepare($sqlLine);
    $stLine->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $stLine->execute();
    $lineRows = $stLine->fetchAll();
    if (is_array($lineRows)) {
        foreach ($lineRows as $lr) {
            $wid = isset($lr['work_id']) ? (int)$lr['work_id'] : 0;
            if ($wid <= 0) continue;
            if (!isset($workLineMap[$wid])) $workLineMap[$wid] = array();
            $qtyToShowRaw = (isset($lr['planned_qty']) && $lr['planned_qty'] !== null && $lr['planned_qty'] !== '')
                ? $lr['planned_qty']
                : (isset($lr['contract_qty']) ? $lr['contract_qty'] : 0);
            $qtyToShow = is_numeric((string)$qtyToShowRaw) ? (float)$qtyToShowRaw : 0;
            $workLineMap[$wid][] = array(
                'unit_price_id' => isset($lr['unit_price_id']) ? (int)$lr['unit_price_id'] : 0,
                'item_name' => isset($lr['item_name']) ? (string)$lr['item_name'] : '',
                'unit' => isset($lr['unit']) ? (string)$lr['unit'] : '',
                'contract_qty' => $qtyToShow
            );
        }
    }

    if (is_array($tasks)) {
        foreach ($tasks as $tt) {
            $taskIdVal = isset($tt['id']) ? (int)$tt['id'] : 0;
            $workIdVal = isset($tt['work_id']) ? (int)$tt['work_id'] : 0;
            if ($taskIdVal <= 0) continue;
            $taskItemMap[$taskIdVal] = ($workIdVal > 0 && isset($workLineMap[$workIdVal])) ? $workLineMap[$workIdVal] : array();
        }
    }

    try {
        $stTip = $pdo->prepare("
            SELECT task_id, unit_price_id, work_date, done_qty, is_auto, is_manual
            FROM cpms_schedule_task_item_progress
            WHERE project_id = :pid
        " );
        $stTip->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
        $stTip->execute();
        $tipRows = $stTip->fetchAll();
        if (is_array($tipRows)) {
            foreach ($tipRows as $tr) {
                $taskIdVal = isset($tr['task_id']) ? (int)$tr['task_id'] : 0;
                $unitIdVal = isset($tr['unit_price_id']) ? (int)$tr['unit_price_id'] : 0;
                $workDateVal = isset($tr['work_date']) ? (string)$tr['work_date'] : '';
                if ($taskIdVal <= 0 || $unitIdVal <= 0 || $workDateVal === '') continue;
                if (!isset($taskItemDoneMap[$taskIdVal])) $taskItemDoneMap[$taskIdVal] = array();
                if (!isset($taskItemDoneMap[$taskIdVal][$workDateVal])) $taskItemDoneMap[$taskIdVal][$workDateVal] = array();
                // 과거 공정 모달 자동완료 표시: NULL 저장값은 미입력으로 취급하기 위해 NULL 유지
                $taskItemDoneMap[$taskIdVal][$workDateVal][$unitIdVal] = array(
                    'done_qty' => (isset($tr['done_qty']) && $tr['done_qty'] !== null && $tr['done_qty'] !== '') ? (float)$tr['done_qty'] : null,
                    'is_auto' => isset($tr['is_auto']) ? (int)$tr['is_auto'] : 0,
                    'is_manual' => isset($tr['is_manual']) ? (int)$tr['is_manual'] : 0
                );
            }
        }
    } catch (Exception $eTip) {
        $taskItemDoneMap = array();
    }
} catch (Exception $e) {
    $taskItemMap = array();
    $taskItemDoneMap = array();
}

// 간트 범위: 프로젝트 기간(있으면) 우선
$pStart = isset($projectRow['start_date']) ? trim((string)$projectRow['start_date']) : '';
$pEnd   = isset($projectRow['end_date']) ? trim((string)$projectRow['end_date']) : '';

function ymd_to_ts($ymd) {
    if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return 0;
    return strtotime($ymd . ' 00:00:00');
}

function base_process_name($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    $name = preg_replace('/\s*\([^)]*\)\s*$/', '', $name);
    return trim((string)$name);
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
$viewMode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : '';
$viewMode = ($viewMode === 'edit') ? 'edit' : 'overview';
$ganttPanel = isset($_GET['gantt_panel']) ? trim((string)$_GET['gantt_panel']) : '';
if (!in_array($ganttPanel, array('overview', 'board', 'progress', 'work'), true)) $ganttPanel = '';
$baseStartTs = $rangeStartTs;
$baseEndTs = $rangeEndTs;
if ($viewMonth === '') {
    // 기본 월을 오늘로 변경(보기/수정 공통)
    $nowTs = strtotime(date('Y-m-d') . ' 00:00:00');
    if ($nowTs >= $baseStartTs && $nowTs <= $baseEndTs) {
        $viewMonth = date('Y-m', $nowTs);
    } else {
        $viewMonth = date('Y-m', $baseStartTs);
    }
}
$rangeStartTs = strtotime($viewMonth . '-01 00:00:00');
$rangeEndTs = strtotime(date('Y-m-t', $rangeStartTs) . ' 00:00:00');

$rangeDays = (int)floor(($rangeEndTs - $rangeStartTs) / 86400);
if ($rangeDays < 1) $rangeDays = 1;
$gridDays = $rangeDays + 1;
$todayYmd = date('Y-m-d');
$todayTs = ymd_to_ts($todayYmd);
$todayOffset = -1;
if ($todayTs >= $rangeStartTs && $todayTs <= $rangeEndTs) {
    $todayOffset = (int)floor(($todayTs - $rangeStartTs) / 86400);
}

// 공정 진행(수량/사진) 맵
$progressMap = array();
$progressPhotoMap = array();
try {
    $rangeStartYmd = date('Y-m-d', $rangeStartTs);
    $rangeEndYmd = date('Y-m-d', $rangeEndTs);
    $stProg = $pdo->prepare("
        SELECT id, task_id, work_date, total_qty, done_qty, is_auto, is_manual
        FROM cpms_schedule_progress
        WHERE project_id = :pid
          AND work_date BETWEEN :s AND :e
        ORDER BY work_date ASC, id ASC
    ");
    $stProg->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $stProg->bindValue(':s', $rangeStartYmd);
    $stProg->bindValue(':e', $rangeEndYmd);
    $stProg->execute();
    $progRows = $stProg->fetchAll();
    $progressIds = array();
    if (is_array($progRows)) {
        foreach ($progRows as $row) {
            $taskId = isset($row['task_id']) ? (int)$row['task_id'] : 0;
            $workDate = isset($row['work_date']) ? (string)$row['work_date'] : '';
            if ($taskId <= 0 || $workDate === '') continue;
            $key = $taskId . '|' . $workDate;
            $progressMap[$key] = array(
                'total_qty' => isset($row['total_qty']) ? $row['total_qty'] : null,
                'done_qty' => isset($row['done_qty']) ? $row['done_qty'] : null,
                'is_auto' => isset($row['is_auto']) ? (int)$row['is_auto'] : 0,
                'is_manual' => isset($row['is_manual']) ? (int)$row['is_manual'] : 0
            );
            if (isset($row['id'])) $progressIds[] = (int)$row['id'];
        }
    }
    if (count($progressIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($progressIds), '?'));
        $photoSelectColumns = array('id', 'progress_id', 'file_path', 'file_name');
        foreach (array('storage_type', 'drive_web_view_link', 'drive_web_content_link') as $photoExtraColumn) {
            if (cpms_construction_drive_column_exists($pdo, 'cpms_schedule_progress_photos', $photoExtraColumn)) {
                $photoSelectColumns[] = $photoExtraColumn;
            }
        }
        $stPhoto = $pdo->prepare("
            SELECT " . implode(', ', $photoSelectColumns) . "
            FROM cpms_schedule_progress_photos
            WHERE progress_id IN ($placeholders)
            ORDER BY id ASC
        ");
        foreach ($progressIds as $idx => $pidVal) {
            $stPhoto->bindValue($idx + 1, $pidVal, \PDO::PARAM_INT);
        }
        $stPhoto->execute();
        $photoRows = $stPhoto->fetchAll();
        if (is_array($photoRows)) {
            $progressIdToKey = array();
            foreach ($progRows as $row) {
                $taskId = isset($row['task_id']) ? (int)$row['task_id'] : 0;
                $workDate = isset($row['work_date']) ? (string)$row['work_date'] : '';
                $rid = isset($row['id']) ? (int)$row['id'] : 0;
                if ($taskId <= 0 || $workDate === '' || $rid <= 0) continue;
                $progressIdToKey[$rid] = $taskId . '|' . $workDate;
            }
            foreach ($photoRows as $prow) {
                $rid = isset($prow['progress_id']) ? (int)$prow['progress_id'] : 0;
                if ($rid <= 0 || !isset($progressIdToKey[$rid])) continue;
                $key = $progressIdToKey[$rid];
                if (!isset($progressPhotoMap[$key])) $progressPhotoMap[$key] = array();
                $photoId = isset($prow['id']) ? (int)$prow['id'] : 0;
                $filePath = isset($prow['file_path']) ? (string)$prow['file_path'] : '';
                $storageType = isset($prow['storage_type']) ? trim((string)$prow['storage_type']) : '';
                $driveViewLink = isset($prow['drive_web_view_link']) ? trim((string)$prow['drive_web_view_link']) : '';
                $driveContentLink = isset($prow['drive_web_content_link']) ? trim((string)$prow['drive_web_content_link']) : '';
                $viewUrl = $filePath;
                $downloadUrl = $filePath;
                if ($storageType === 'google_drive') {
                    $viewUrl = ($driveViewLink !== '' && $photoId > 0) ? base_url() . '/?r=construction/photo_file&id=' . (int)$photoId . '&view=1' : '';
                    $downloadUrl = ($driveContentLink !== '' && $photoId > 0) ? base_url() . '/?r=construction/photo_file&id=' . (int)$photoId . '&download=1' : '';
                }
                $progressPhotoMap[$key][] = array(
                    'id' => $photoId,
                    'file_path' => $filePath,
                    'file_name' => isset($prow['file_name']) ? (string)$prow['file_name'] : '',
                    'storage_type' => $storageType,
                    'view_url' => $viewUrl,
                    'download_url' => $downloadUrl
                );
            }
        }
    }
    // 이전 기간(현재 월 이전)의 마지막 진행 정보도 추가해서 남은 수량 계산에 사용
    $stPrev = $pdo->prepare("
        SELECT p.id, p.task_id, p.work_date, p.total_qty, p.done_qty, p.is_auto, p.is_manual
        FROM cpms_schedule_progress p
        INNER JOIN (
            SELECT task_id, MAX(work_date) AS max_date
            FROM cpms_schedule_progress
            WHERE project_id = :pid
              AND work_date < :s
            GROUP BY task_id
        ) t ON p.task_id = t.task_id AND p.work_date = t.max_date
        WHERE p.project_id = :pid
    ");
    $stPrev->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $stPrev->bindValue(':s', $rangeStartYmd);
    $stPrev->execute();
    $prevRows = $stPrev->fetchAll();
    if (is_array($prevRows)) {
        foreach ($prevRows as $row) {
            $taskId = isset($row['task_id']) ? (int)$row['task_id'] : 0;
            $workDate = isset($row['work_date']) ? (string)$row['work_date'] : '';
            if ($taskId <= 0 || $workDate === '') continue;
            $key = $taskId . '|' . $workDate;
            if (!isset($progressMap[$key])) {
                $progressMap[$key] = array(
                    'total_qty' => isset($row['total_qty']) ? $row['total_qty'] : null,
                    'done_qty' => isset($row['done_qty']) ? $row['done_qty'] : null,
                    'is_auto' => isset($row['is_auto']) ? (int)$row['is_auto'] : 0,
                    'is_manual' => isset($row['is_manual']) ? (int)$row['is_manual'] : 0
                );
            }
        }
    }    
} catch (Exception $e) {
    $progressMap = array();
    $progressPhotoMap = array();
}

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

function task_overlaps_range($sdTs, $edTs, $rangeStartTs, $rangeEndTs) {
    if ($sdTs <= 0 || $edTs <= 0) return true;
    if ($edTs < $rangeStartTs || $sdTs > $rangeEndTs) return false;
    return true;
}

function gantt_bar_metrics($sdTs, $edTs, $rangeStartTs, $rangeEndTs, $gridDays) {
    $leftPct = 0;
    $widthPct = 0;
    if ($sdTs > 0 && $edTs > 0) {
        $barStart = ($sdTs < $rangeStartTs) ? $rangeStartTs : $sdTs;
        $barEnd = ($edTs > $rangeEndTs) ? $rangeEndTs : $edTs;
        if ($barEnd >= $barStart) {
            $leftDays = (int)floor(($barStart - $rangeStartTs) / 86400);
            $durDays  = (int)floor(($barEnd - $barStart) / 86400) + 1;
            $leftDays = clamp($leftDays, 0, $gridDays - 1);
            $maxDur   = $gridDays - $leftDays;
            if ($maxDur < 1) $maxDur = 1;
            $durDays  = clamp($durDays, 1, $maxDur);
            $leftPct  = ($leftDays / $gridDays) * 100.0;
            $widthPct = ($durDays / $gridDays) * 100.0;
        }
    }
    return array($leftPct, $widthPct);
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">공정표</h3>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" id="ganttJumpToday" class="px-4 py-2 rounded-2xl border border-blue-200 text-blue-700 bg-blue-50 font-extrabold hover:bg-blue-100">
                오늘로 이동
            </button>
            <?php if ($canEdit): ?>
                <button type="button" class="px-4 py-2 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 font-extrabold hover:bg-rose-100" data-modal-open="issueAdd">
                    이슈등록
                </button>
            <?php endif; ?>
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
        <button type="button" class="gantt-tab px-4 py-2 rounded-2xl bg-gray-100 text-gray-700 font-extrabold" data-tab="work">작업</button>
        <button type="button" class="gantt-tab px-4 py-2 rounded-2xl bg-gray-100 text-gray-700 font-extrabold" data-tab="progress">현재 진행률</button>
    </div>
    <?php if ($debugAutoProgress): ?>
        <div class="mt-4 rounded-2xl border border-blue-100 bg-blue-50 p-4">
            <div class="font-extrabold text-blue-900 mb-2">자동 완료수량 진단</div>
            <div class="overflow-auto">
                <table class="min-w-[1100px] w-full text-xs border-collapse bg-white">
                    <thead>
                    <tr class="bg-blue-100 text-blue-900">
                        <th class="p-2 border text-right">task_id</th>
                        <th class="p-2 border text-left">작업명</th>
                        <th class="p-2 border">start_date</th>
                        <th class="p-2 border">end_date</th>
                        <th class="p-2 border">today</th>
                        <th class="p-2 border text-right">total_qty</th>
                        <th class="p-2 border text-right">duration_days</th>
                        <th class="p-2 border text-right">elapsed_days</th>
                        <th class="p-2 border text-right">daily_qty</th>
                        <th class="p-2 border text-right">auto_done_qty</th>
                        <th class="p-2 border text-right">manual_rows_count</th>
                        <th class="p-2 border text-right">auto_rows_count</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($autoProgressDiagnostics) === 0): ?>
                        <tr><td class="p-3 border text-center text-gray-500" colspan="12">진단 가능한 작업이 없습니다.</td></tr>
                    <?php else: ?>
                        <?php foreach ($autoProgressDiagnostics as $diag): ?>
                            <tr>
                                <td class="p-2 border text-right"><?php echo (int)$diag['task_id']; ?></td>
                                <td class="p-2 border"><?php echo h($diag['task_name']); ?></td>
                                <td class="p-2 border text-center"><?php echo h($diag['start_date']); ?></td>
                                <td class="p-2 border text-center"><?php echo h($diag['end_date']); ?></td>
                                <td class="p-2 border text-center"><?php echo h($diag['today']); ?></td>
                                <td class="p-2 border text-right"><?php echo number_format((float)$diag['total_qty'], 4); ?></td>
                                <td class="p-2 border text-right"><?php echo (int)$diag['duration_days']; ?></td>
                                <td class="p-2 border text-right"><?php echo (int)$diag['elapsed_days']; ?></td>
                                <td class="p-2 border text-right"><?php echo number_format((float)$diag['daily_qty'], 4); ?></td>
                                <td class="p-2 border text-right"><?php echo number_format((float)$diag['auto_done_qty'], 4); ?></td>
                                <td class="p-2 border text-right"><?php echo (int)$diag['manual_rows_count']; ?></td>
                                <td class="p-2 border text-right"><?php echo (int)$diag['auto_rows_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

        <!-- 공정표 보기 -->
    <div class="mt-6 overflow-x-auto gantt-tab-panel" data-tab-panel="overview">
        <!-- 공정표(보기) 날짜/그리드 정렬 수정 -->
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
                    <?php for ($i = 0; $i < count($rangeDates); $i++): ?>
                        <?php $isTodayCol = ($todayOffset === $i); ?>
                        <div class="gantt-cell gantt-cell-day <?php echo $isTodayCol ? 'gantt-cell-today' : ''; ?>" data-day-index="<?php echo (int)$i; ?>"><?php echo h($rangeDates[$i]); ?></div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <div class="mt-2 space-y-2 gantt-board-readonly"
             data-range-start="<?php echo h(date('Y-m-d', $rangeStartTs)); ?>"
             data-range-days="<?php echo (int)$gridDays; ?>"
             data-today-offset="<?php echo (int)$todayOffset; ?>"
             data-today-ymd="<?php echo h($todayYmd); ?>"
             style="--day-width:48px; --grid-days:<?php echo (int)$gridDays; ?>;">
            <?php foreach ($tasks as $t): ?>
                <?php
                $sd = isset($t['start_date']) ? (string)$t['start_date'] : '';
                $ed = isset($t['end_date']) ? (string)$t['end_date'] : '';
                $sdTs = ymd_to_ts($sd);
                $edTs = ymd_to_ts($ed);
                if ($sdTs > 0 && $edTs > 0 && $edTs < $sdTs) { $tmp = $sdTs; $sdTs = $edTs; $edTs = $tmp; }
                if (!task_overlaps_range($sdTs, $edTs, $rangeStartTs, $rangeEndTs)) continue;
                list($leftPct, $widthPct) = gantt_bar_metrics($sdTs, $edTs, $rangeStartTs, $rangeEndTs, $gridDays);
                $workId = isset($t['work_id']) ? (int)$t['work_id'] : 0;
                $taskQty = ($workId > 0 && isset($taskQtyMap[$workId])) ? $taskQtyMap[$workId] : 0;            
                ?>
                <div class="flex items-center gap-0 gantt-row"
                     data-task-id="<?php echo (int)$t['id']; ?>"                
                     data-task-name="<?php echo h($t['name']); ?>"
                     data-task-total-qty="<?php echo h($taskQty); ?>"
                     data-work-id="<?php echo (int)$workId; ?>">
                    <div class="gantt-dropzone gantt-dropzone-readonly relative h-11 shrink-0 border border-gray-100 rounded-xl bg-gray-50 overflow-hidden"
                         data-start="<?php echo h($sd); ?>"
                         data-end="<?php echo h($ed); ?>">
                        <?php if ($todayOffset >= 0): ?>
                            <div class="gantt-today-marker" style="left: calc(var(--day-width) * <?php echo (int)$todayOffset; ?>);"></div>
                        <?php endif; ?>
                         <?php if ($widthPct > 0): ?>
                            <div class="gantt-bar absolute inset-y-0 rounded-lg bg-gradient-to-r from-blue-600 to-cyan-500 text-white text-xs flex items-center px-2"
                                 style="left: <?php echo (float)$leftPct; ?>%; width: <?php echo (float)$widthPct; ?>%; min-width: 28px;">
                                <?php /* 공정표 바 텍스트 복구 */ ?>
                                <span class="gantt-bar-text"><?php echo h($t['name']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>


        <div id="ganttWorkDetail" class="mt-4 p-4 rounded-2xl border border-gray-200 bg-gray-50 hidden">
            <div class="text-sm font-extrabold text-gray-900" id="ganttWorkDetailTitle"></div>
            <div class="text-xs text-gray-600 mt-1" id="ganttWorkDetailDesc"></div>
            <div class="mt-3 overflow-auto">
                <table class="w-full text-xs border-collapse">
                    <thead><tr class="bg-white"><th class="p-2 border text-left">항목명</th><th class="p-2 border text-left">단위</th><th class="p-2 border text-right">수량</th><th class="p-2 border text-right">단가</th><th class="p-2 border text-right">금액</th></tr></thead>
                    <tbody id="ganttWorkDetailBody"></tbody>
                </table>
            </div>
            <div class="mt-2 text-right text-sm font-extrabold">합계: <span id="ganttWorkDetailSum">0</span></div>
        </div>
        </div>
    </div>

    <!-- 드래그형 간트 보드 -->
    <div class="mt-6 rounded-3xl border border-gray-200 bg-white p-4 gantt-tab-panel hidden" data-tab-panel="board">
        <div class="flex flex-col lg:flex-row gap-4">
            <div class="lg:w-56 shrink-0">
                <div class="text-sm font-extrabold text-gray-900">작업 목록</div>
                <div class="text-xs text-gray-500 mt-1">작업 탭에서 만든 작업내용을 드래그해 일정에 배치하세요.</div>
                <div class="mt-3 space-y-2 max-h-80 overflow-auto">
                    <?php if (count($workItems) === 0): ?>
                        <div class="text-xs text-gray-500">작업 목록이 없습니다.</div>
                    <?php else: ?>
                        <?php foreach ($workItems as $w): ?>
                            <div class="gantt-draggable px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-sm font-semibold cursor-move"
                                 draggable="true"
                                 data-work-id="<?php echo (int)$w['id']; ?>"
                                 data-work-title="<?php echo h($w['title']); ?>"
                                 data-task-name="<?php echo h($w['title']); ?>">
                                <?php echo h($w['title']); ?>
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

                    <!-- 간트 날짜/그리드 정렬 수정 -->                    
                    <div class="gantt-header"
                         style="--day-width:48px; --grid-days:<?php echo (int)$gridDays; ?>;">                    
                        <div class="gantt-header-spacer gantt-left-col shrink-0"></div>
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
                                <?php for ($i = 0; $i < count($rangeDates); $i++): ?>
                                    <?php $isTodayCol = ($todayOffset === $i); ?>
                                    <div class="gantt-cell gantt-cell-day <?php echo $isTodayCol ? 'gantt-cell-today' : ''; ?>" data-day-index="<?php echo (int)$i; ?>"><?php echo h($rangeDates[$i]); ?></div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mt-2 space-y-2 gantt-board"
                         data-range-start="<?php echo h(date('Y-m-d', $rangeStartTs)); ?>"
                         data-range-days="<?php echo (int)$gridDays; ?>"
                         data-project-id="<?php echo (int)$pid; ?>"
                         data-csrf="<?php echo h(csrf_token()); ?>"
                         data-today-offset="<?php echo (int)$todayOffset; ?>"
                         data-today-ymd="<?php echo h($todayYmd); ?>"
                         style="--day-width:48px; --grid-days:<?php echo (int)$gridDays; ?>;">
                        <?php foreach ($tasks as $t): ?>
                            <?php
                            $sd = isset($t['start_date']) ? (string)$t['start_date'] : '';
                            $ed = isset($t['end_date']) ? (string)$t['end_date'] : '';
                            $sdTs = ymd_to_ts($sd);
                            $edTs = ymd_to_ts($ed);
                            if ($sdTs > 0 && $edTs > 0 && $edTs < $sdTs) { $tmp = $sdTs; $sdTs = $edTs; $edTs = $tmp; }
                            if (!task_overlaps_range($sdTs, $edTs, $rangeStartTs, $rangeEndTs)) continue;
                            list($leftPct, $widthPct) = gantt_bar_metrics($sdTs, $edTs, $rangeStartTs, $rangeEndTs, $gridDays);
                            $workId = isset($t['work_id']) ? (int)$t['work_id'] : 0;
                $taskQty = ($workId > 0 && isset($taskQtyMap[$workId])) ? $taskQtyMap[$workId] : 0;                           
                            ?>
                            <div class="flex items-center gap-0 gantt-row"
                                 data-task-id="<?php echo (int)$t['id']; ?>"
                                 data-task-name="<?php echo h($t['name']); ?>"
                                 data-task-progress="<?php echo (int)$t['progress']; ?>"
                                 data-task-total-qty="<?php echo h($taskQty); ?>"
                                 data-work-id="<?php echo (int)$workId; ?>">
                                <div class="gantt-left-col shrink-0 text-sm font-semibold text-gray-800 truncate flex items-center gap-2">
                                    <span class="truncate"><?php echo h($t['name']); ?></span>
                                    <?php if ($canEdit): ?>
                                        <button type="button"
                                                class="gantt-delete text-xs px-2 py-1 rounded-lg border border-rose-200 text-rose-700 bg-rose-50"
                                                data-task-id="<?php echo (int)$t['id']; ?>">
                                            삭제
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="gantt-dropzone relative h-11 shrink-0 border border-gray-100 rounded-xl bg-gray-50 overflow-hidden"
                                     data-start="<?php echo h($sd); ?>"
                                     data-end="<?php echo h($ed); ?>">
                                    <?php if ($todayOffset >= 0): ?>
                                        <div class="gantt-today-marker" style="left: calc(var(--day-width) * <?php echo (int)$todayOffset; ?>);"></div>
                                    <?php endif; ?>
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
                                <div class="gantt-left-col shrink-0 text-sm text-gray-500">+ 드래그해 공정 추가</div>
                                <?php if ($todayOffset >= 0): ?>
                                    <div class="gantt-dropzone relative h-11 shrink-0 border border-dashed border-gray-200 rounded-xl bg-white overflow-hidden">
                                        <div class="gantt-today-marker" style="left: calc(var(--day-width) * <?php echo (int)$todayOffset; ?>);"></div>
                                    </div>
                                <?php else: ?>
                                    <div class="gantt-dropzone relative h-11 shrink-0 border border-dashed border-gray-200 rounded-xl bg-white overflow-hidden"></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 text-xs text-gray-500">
            팁: 공정을 드래그해 일정에 추가하고, 바를 드래그해 이동하거나 양쪽 핸들로 기간을 조절할 수 있습니다.
        </div>
        <div id="ganttSaveNotice" class="mt-2 text-xs hidden"></div>        
    </div>

    <!-- 공정표 테이블 -->
    <div class="mt-6 overflow-x-auto gantt-tab-panel hidden" data-tab-panel="progress">
        <table class="min-w-full text-sm">
            <thead>
            <tr class="text-left text-gray-500">
                <th class="py-2 pr-2">공정</th>
                <th class="py-2 pr-2">시작</th>
                <th class="py-2 pr-2">종료</th>
                <th class="py-2 pr-2">진행률</th> <!-- 현재진행률 일정컬럼 삭제 -->
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
                if (!task_overlaps_range($sdTs, $edTs, $rangeStartTs, $rangeEndTs)) continue;
                $pr = isset($t['progress']) ? (int)$t['progress'] : 0;
                // 과거 공정 자동 100%
                $displayPr = $pr;
                if (($pr === 0 || !isset($t['progress']) || $t['progress'] === null || $t['progress'] === '') && $edTs > 0 && $edTs < $todayTs) {
                    $displayPr = 100;
                }
                if ($displayPr < 0) $displayPr = 0; if ($displayPr > 100) $displayPr = 100;
                ?>
                <tr class="border-t border-gray-100">
                    <?php if ($canEdit): ?>
                        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/schedule_save">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                            <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                            <input type="hidden" name="month" value="<?php echo h($viewMonth); ?>">
                            <input type="hidden" name="mode" value="edit">
                            <input type="hidden" name="work_id" value="<?php echo (int)(isset($t['work_id']) ? $t['work_id'] : 0); ?>">                             
                            <td class="py-2 pr-2">
                                <input name="name" value="<?php echo h($t['name']); ?>" class="w-64 px-3 py-2 rounded-2xl border border-gray-200">
                            </td>
                            <td class="py-2 pr-2"><input type="date" name="start_date" value="<?php echo h($sd); ?>" class="px-3 py-2 rounded-2xl border border-gray-200"></td>
                            <td class="py-2 pr-2"><input type="date" name="end_date" value="<?php echo h($ed); ?>" class="px-3 py-2 rounded-2xl border border-gray-200"></td>
                            <td class="py-2 pr-2">
                                <input type="number" name="progress" min="0" max="100" value="<?php echo (int)$displayPr; ?>" class="w-24 px-3 py-2 rounded-2xl border border-gray-200">%
                            </td>
                            <!-- 현재진행률 일정컬럼 삭제 -->
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
                        <td class="py-2 pr-2 text-gray-700"><?php echo (int)$displayPr; ?>%</td> <!-- 현재진행률 일정컬럼 삭제 -->
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-6 gantt-tab-panel hidden" data-tab-panel="work">
        <?php require __DIR__ . '/work.php'; ?>
    </div>

    <?php if (!$canEdit): ?>
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
                <input type="hidden" id="ganttProgressTaskId" value="">
                <input type="hidden" id="ganttProgressTaskDateInput" value="">                
                <input type="hidden" id="ganttProgressInputMode" value="manual">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs font-bold text-gray-500">전체 수량</label>
                        <input id="ganttTotalQty" type="number" min="0" class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 w-full bg-gray-50 text-gray-700" placeholder="예: 120" readonly>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">완료 수량</label>
                        <input id="ganttDoneQty" type="number" min="0" class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 w-full bg-gray-50 text-gray-700" placeholder="항목 합계" readonly>
                        <div id="ganttDoneQtyHint" class="mt-1 text-xs text-gray-500"></div>
                        <div id="ganttProgressSourceStatus" class="mt-1 text-xs font-bold text-gray-600"></div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">남은 수량</label>
                        <div class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 w-full bg-gray-50 text-gray-700 font-extrabold" id="ganttRemainQty">0</div>
                    </div>
                </div>

                <!-- 항목별 수량표/저장 -->
                <div>
                    <div class="text-xs font-bold text-gray-500 mb-2">내역서 항목별 수량</div>
                    <div class="overflow-auto border border-gray-200 rounded-2xl">
                        <table class="w-full text-xs border-collapse">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="p-2 border text-left">항목명</th>
                                    <th class="p-2 border text-right">수량(계약수량)</th>
                                    <th class="p-2 border text-right">완료수량</th>
                                    <th class="p-2 border text-right">남은수량</th>
                                </tr>
                            </thead>
                            <tbody id="ganttItemQtyBody"></tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 font-bold">
                            <input type="checkbox" id="ganttAutoDistributionToggle" class="rounded border-gray-300" checked>
                            자동 분배 제안 사용
                        </label>
                    </div>
                    <div id="ganttShiftInfo" class="text-xs text-gray-600">이동 범위: 오늘 ~ 종료일 (과거 데이터 이동 금지)</div>
                    <?php if ($canEdit): ?>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" id="ganttShiftPlus1" class="px-3 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-extrabold">일정 +1일 밀기</button>
                            <button type="button" id="ganttShiftMinus1" class="px-3 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-extrabold">일정 -1일 당기기</button>
                            <input type="number" id="ganttShiftDaysInput" class="px-3 py-2 rounded-xl border border-gray-200 w-24 text-sm" value="1" min="1" step="1">
                            <button type="button" id="ganttShiftApplyPlus" class="px-3 py-2 rounded-xl border border-blue-200 bg-blue-50 text-blue-700 text-sm font-extrabold">N일 밀기</button>
                            <button type="button" id="ganttShiftApplyMinus" class="px-3 py-2 rounded-xl border border-blue-200 bg-blue-50 text-blue-700 text-sm font-extrabold">N일 당기기</button>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">작업 사진 업로드</label>
                    <input id="ganttPhotoInput" type="file" multiple class="mt-2 block w-full text-sm text-gray-700">
                    <div id="ganttPhotoList" class="mt-3 space-y-2 text-sm text-gray-700"></div>
                    <div class="text-xs text-gray-500 mt-2">업로드한 사진은 아래에서 바로 확인 및 다운로드할 수 있습니다.</div>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <button type="button" class="px-4 py-2 rounded-2xl border border-gray-200 text-gray-700 font-extrabold" data-modal-close="ganttProgress">닫기</button>
                    <?php if ($canEdit): ?>
                        <button type="button" id="ganttProgressSave" class="px-5 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">저장</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
  /* 간트 날짜/그리드 정렬 수정: 헤더/바디 좌측 라벨 폭을 동일 기준으로 통일 */
  .gantt-left-col { width: calc(14rem + 0.5rem); padding-right: 0.5rem; box-sizing: border-box; }  
  .gantt-header { display: flex; align-items: stretch; width: auto; }
  .gantt-header-spacer { flex: 0 0 auto; }
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
  .gantt-cell-today { background: #dbeafe; border: 2px solid #2563eb; color: #1e3a8a; }
  .gantt-header-row:last-child .gantt-cell { border-bottom: none; }
  .gantt-header-row .gantt-cell:last-child { border-right: none; }
  .gantt-dropzone {
    min-width: calc(var(--day-width) * var(--grid-days));
    background-size: var(--day-width) 100%;
    width: calc(var(--day-width) * var(--grid-days));    
    background-origin: border-box; /* 간트 날짜/그리드 정렬 수정 */    
    background-image: repeating-linear-gradient(
      to right,
      rgba(148,163,184,0.35) 0,
      rgba(148,163,184,0.35) 1px,
      transparent 1px,
      transparent calc(var(--day-width))
    );
    box-sizing: border-box;
  }
  .gantt-today-marker {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #2563eb;
    opacity: 0.7;
    pointer-events: none;
    z-index: 1;    
  }
  .gantt-bar { cursor: grab; }
    /* 공정표 바 텍스트 복구 */
  .gantt-bar-text {
    display: block;
    width: 100%;
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    pointer-events: none;
  }
  .gantt-bar.dragging { opacity: 0.7; cursor: grabbing; }
  .gantt-board-readonly .gantt-bar { cursor: default; }
  /* 공정표(보기) 좌측 영역 제거 */
  [data-tab-panel="overview"] .gantt-header { display: block; width: 100%; }
  [data-tab-panel="overview"] .gantt-header-rows {
    width: 100%;
    min-width: calc(var(--day-width) * var(--grid-days));
  }
  [data-tab-panel="overview"] .gantt-row { display: block; }
  [data-tab-panel="overview"] .gantt-dropzone-readonly {
    width: 100%;
    min-width: calc(var(--day-width) * var(--grid-days));
  }
  /* 공정표(보기) 날짜/그리드 정렬 수정 */
  .gantt-dropzone-readonly {
    flex: 0 0 auto;
    width: calc(var(--day-width) * var(--grid-days));
  }  
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
  var progressMap = <?php echo json_encode($progressMap, JSON_UNESCAPED_UNICODE); ?>;
  var progressPhotoMap = <?php echo json_encode($progressPhotoMap, JSON_UNESCAPED_UNICODE); ?>;
  var taskItemMap = <?php echo json_encode($taskItemMap, JSON_UNESCAPED_UNICODE); ?>; // 항목별 수량표/저장
  var taskItemDoneMap = <?php echo json_encode($taskItemDoneMap, JSON_UNESCAPED_UNICODE); ?>; // 항목별 수량표/저장
  var todayYmd = rangeSource.getAttribute('data-today-ymd') || '';
  var todayOffset = parseInt(rangeSource.getAttribute('data-today-offset'), 10);
  var initialMode = '<?php echo h($viewMode); ?>';
  var initialPanel = '<?php echo h($ganttPanel); ?>';
  if (isNaN(todayOffset)) todayOffset = -1;
  // 공정표 자동저장 후 dirty 상태: 수정 데이터 변경 여부(보기 탭 최신화 트리거)
  if (typeof window.cpmsGanttDirty === 'undefined') window.cpmsGanttDirty = false;
  if (typeof window.cpmsGanttRefreshTs === 'undefined') window.cpmsGanttRefreshTs = 0;

    function shouldKeepEditMode(){
    if (initialMode === 'edit') return true;
    return getActiveTab() === 'board';
  }

  function getCurrentMonthParam(){
    var params = new URLSearchParams(window.location.search);
    return params.get('month') || '<?php echo h($viewMonth); ?>' || '';
  }

  function getActiveTab(){
    var activePanel = document.querySelector('.gantt-tab-panel:not(.hidden)');
    if (!activePanel) return initialPanel || ((initialMode === 'edit') ? 'board' : 'overview');
    return activePanel.getAttribute('data-tab-panel') || 'overview';
  }

  function buildGanttUrl(mode, refreshTs){
    var params = new URLSearchParams(window.location.search);
    params.set('r', '공사');
    if (projectId) params.set('pid', projectId);
    params.set('tab', 'gantt');
    var month = getCurrentMonthParam(); // month 유지
    if (month) params.set('month', month);
    if (mode === 'edit') params.set('mode', 'edit');
    else params.delete('mode');
    if (mode === 'work') params.set('gantt_panel', 'work');
    else {
      params.delete('gantt_panel');
      params.delete('work_id');
      params.delete('work_modal');
      params.delete('work_view');
    }
    // 캐시 방지 refresh 파라미터
    if (refreshTs) params.set('refresh', String(refreshTs));
    else params.delete('refresh');
    return window.location.pathname + '?' + params.toString();
  }

  /* 저장 후 탭 유지: 현재 활성 탭/월 파라미터를 유지해서 동일 상태로 새로고침 */
  function reloadWithState(mode){    
    var activeTab = getActiveTab();
    var keepEditMode = (mode === 'edit') || (mode !== 'overview' && activeTab === 'board');
    window.location.href = buildGanttUrl(keepEditMode ? 'edit' : 'overview', window.cpmsGanttRefreshTs || 0);
  }

  function ymdToTs(ymd){
    if (!ymd) return 0;
    var parts = ymd.split('-');
    if (parts.length !== 3) return 0;
    return new Date(parseInt(parts[0],10), parseInt(parts[1],10)-1, parseInt(parts[2],10)).getTime() / 1000;
  }
  function pad2(n){
    return (n < 10 ? '0' : '') + n;
  }
  function tsToYmd(ts){
    var d = new Date(ts * 1000);
    var y = d.getFullYear();
    var m = pad2(d.getMonth()+1);
    var day = pad2(d.getDate());
    return y + '-' + m + '-' + day;
  }
  function ymdAddDays(ymd, days){
    var ts = ymdToTs(ymd);
    if (!ts) return '';
    return tsToYmd(ts + (days * 86400));
  }

  function normalizeInt(val){
    var num = parseFloat(val);
    if (isNaN(num) || num < 0) return 0;
    return Math.round(num);
  }

  function getTaskRowById(taskId){
    if (!taskId) return null;
    return document.querySelector('.gantt-row[data-task-id="' + taskId + '"]');
  }

  function getTaskWorkId(taskId){
    var row = getTaskRowById(taskId);
    if (!row) return 0;
    return parseInt(row.getAttribute('data-work-id') || '0', 10) || 0;
  }

  function getTaskDateRange(taskId){
    var row = getTaskRowById(taskId);
    var zone = row ? row.querySelector('.gantt-dropzone') : null;
    if (!zone) return { start: '', end: '' };
    return {
      start: zone.getAttribute('data-start') || '',
      end: zone.getAttribute('data-end') || ''
    };
  }
  var rangeStartTs = ymdToTs(rangeStart);

  function clamp(v, min, max){
    return Math.max(min, Math.min(max, v));
  }

  function showSaveNotice(msg, ok){
    var el = document.getElementById('ganttSaveNotice');
    if (!el) return;
    el.className = 'mt-2 text-xs ' + (ok ? 'text-emerald-700' : 'text-rose-700');
    el.textContent = msg;
    el.classList.remove('hidden');
    setTimeout(function(){ if (el) el.classList.add('hidden'); }, 1800);
  }

  // 기존 공정 드래그 자동 저장
  function saveTaskMove(taskId, startDate, endDate){
    var fd = new FormData();
    fd.append('_csrf', csrfToken || '');
    fd.append('project_id', projectId || '0');
    fd.append('task_id', taskId || '0');
    fd.append('start_date', startDate || '');
    fd.append('end_date', endDate || '');
    fd.append('month', getCurrentMonthParam());
    fd.append('mode', 'edit');

    return fetch('?r=construction/schedule_move', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(res){
        var status = res.status;
        return res.text().then(function(text){
          var data = null;
          try { data = JSON.parse(text); } catch (e) {}
          if (!res.ok) {
            var msg = (data && data.message) ? data.message : ('HTTP ' + status);
            return { ok:false, message:msg, http_status:status, parse_error:!data, raw_text:text };
          }
          if (!data) {
            console.log('schedule_move non-json response:', text ? text.substring(0, 200) : '');
            return { ok:false, message:'서버 응답이 JSON이 아닙니다.', parse_error:true, raw_text:text };
          }
          return data;
        });
      });
  }

  function saveTask(taskId, name, startDate, endDate, progress, workId){
    var fd = new FormData();
    if (!board) return;
    fd.append('_csrf', csrfToken || '');
    fd.append('project_id', projectId || '0');
    fd.append('task_id', taskId || '0');
    fd.append('name', name || '');
    fd.append('start_date', startDate || '');
    fd.append('end_date', endDate || '');
    fd.append('progress', progress || '0');
    fd.append('month', getCurrentMonthParam());
    fd.append('work_id', workId || '0');
    fd.append('mode', 'edit');

    fetch('?r=construction/schedule_save', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function(){ reloadWithState('edit'); })
      .catch(function(){ reloadWithState('edit'); });
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
    }).then(function(){ reloadWithState('edit'); })
      .catch(function(){ reloadWithState('edit'); });
  }

  var ganttDayWidth = 48;

  function getMeasuredDayWidth(){
    var dayCell = document.querySelector('.gantt-header .gantt-cell-day');
    if (dayCell) {
      var measured = dayCell.getBoundingClientRect().width;
      if (measured && measured > 0) return measured;
    }
    var root = rangeSource;
    var fromStyle = root ? parseFloat(window.getComputedStyle(root).getPropertyValue('--day-width')) : 0;
    if (!isNaN(fromStyle) && fromStyle > 0) return fromStyle;
    return 48;
  }

  function getTaskSpanByDate(startDate, endDate){
    var startTs = ymdToTs(startDate || '');
    var endTs = ymdToTs(endDate || '');
    if (!startTs || !endTs) return null;
    var barStart = Math.max(startTs, rangeStartTs);
    var barEnd = Math.min(endTs, rangeStartTs + ((gridDays - 1) * 86400));
    if (barEnd < barStart) return null;
    var leftDays = Math.floor((barStart - rangeStartTs) / 86400);
    var duration = Math.floor((barEnd - barStart) / 86400) + 1;
    leftDays = clamp(leftDays, 0, gridDays - 1);
    duration = clamp(duration, 1, gridDays - leftDays);
    return { leftDays: leftDays, duration: duration };
  }

  function setBarPosition(bar, leftDays, duration){
    if (!bar) return;
    bar.style.left = (leftDays * ganttDayWidth) + 'px';
    bar.style.width = (duration * ganttDayWidth) + 'px';
  }

  function dayFromOffset(offsetX){
    var day = Math.floor(offsetX / ganttDayWidth);
    return clamp(day, 0, gridDays - 1);
  }

  // 리사이즈 시 간트 바 재정렬(날짜 고정)
  function recalcGanttLayout(){
    ganttDayWidth = getMeasuredDayWidth();
    var gridWidth = ganttDayWidth * gridDays;

    document.querySelectorAll('.gantt-header, .gantt-board, .gantt-board-readonly').forEach(function(el){
      el.style.setProperty('--day-width', ganttDayWidth + 'px');
    });

    document.querySelectorAll('.gantt-dropzone').forEach(function(zone){
      zone.style.width = gridWidth + 'px';
      zone.style.minWidth = gridWidth + 'px';
      var bar = zone.querySelector('.gantt-bar');
      if (!bar) return;
      var span = getTaskSpanByDate(zone.getAttribute('data-start'), zone.getAttribute('data-end'));
      if (!span) {
        bar.style.display = 'none';
        return;
      }
      bar.style.display = '';
      setBarPosition(bar, span.leftDays, span.duration);
    });
  }

  if (board) {
    // 드래그 드롭 공정 추가 수정: 수정 탭(보드) 패널 범위에서만 이벤트를 바인딩한다.
    var boardPanel = board.closest('.gantt-tab-panel[data-tab-panel="board"]');
    var dragName = '';
    var dragWorkId = '0';
    var dragEl = null;
    var draggableEls = boardPanel ? boardPanel.querySelectorAll('.gantt-draggable') : [];
    draggableEls.forEach(function(el){
      el.addEventListener('dragstart', function(e){
        dragName = el.getAttribute('data-work-title') || el.getAttribute('data-task-name') || el.textContent.trim();
        dragWorkId = el.getAttribute('data-work-id') || '0';
        dragEl = el;
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'copy';
          e.dataTransfer.setData('text/plain', dragName);
        }
      });
    });

    var dropZones = boardPanel ? boardPanel.querySelectorAll('.gantt-dropzone') : [];
    dropZones.forEach(function(zone){
      // 드래그 드롭 공정 추가 수정: 일부 브라우저에서 drop 가능 상태를 보장.
      zone.addEventListener('dragenter', function(e){ e.preventDefault(); });
      zone.addEventListener('dragover', function(e){ e.preventDefault(); });
      zone.addEventListener('drop', function(e){
        e.preventDefault();
        // 드래그 드롭 공정 추가 수정: dataTransfer 미지원/제한 환경까지 fallback 처리.
        var droppedName = '';
        if (e.dataTransfer) {
          droppedName = e.dataTransfer.getData('text/plain') || '';
        }
        if (!droppedName) droppedName = dragName || '';
        if (!droppedName && dragEl) {
          droppedName = dragEl.getAttribute('data-work-title') || dragEl.getAttribute('data-task-name') || dragEl.textContent.trim() || '';
        }
        if (!droppedName) return;
        var zoneRect = zone.getBoundingClientRect();
        var offsetX = e.clientX - zoneRect.left;
        var leftDays = dayFromOffset(offsetX);
        var startTs = rangeStartTs + (leftDays * 86400);
        var endTs = startTs + (3 * 86400);
        saveTask(0, droppedName, tsToYmd(startTs), tsToYmd(endTs), 0, dragWorkId || 0);
        if (dragEl && dragEl.parentNode) {
          dragEl.parentNode.removeChild(dragEl);
        }
        dragName = '';
        dragWorkId = '0';
        dragEl = null;
      });
    });
  }

  if (board) {
    var savingTaskIds = {};
    var dragState = {
      isDragging: false,
      currentDragTask: null,
      dragMode: '',
      dragStartX: 0,
      origLeft: 0,
      origWidth: 0,
      moved: false,
      originalStartDate: '',
      originalEndDate: ''
    };

    // 드래그 상태 초기화 + 반복 드래그 먹통 방지
    function cleanupDragState(){
      if (dragState.currentDragTask && dragState.currentDragTask.bar) {
        var cleanupBar = dragState.currentDragTask.bar;
        cleanupBar.classList.remove('dragging');
        cleanupBar.classList.remove('saving');
        cleanupBar.style.pointerEvents = '';
      }
      dragState.isDragging = false;
      dragState.currentDragTask = null;
      dragState.dragMode = '';
      dragState.dragStartX = 0;
      dragState.origLeft = 0;
      dragState.origWidth = 0;
      dragState.moved = false;
      dragState.originalStartDate = '';
      dragState.originalEndDate = '';
      document.removeEventListener('mousemove', onBoardMouseMove);
      document.removeEventListener('mouseup', onBoardMouseUp);
    }

    function startDragForTask(taskObj, mode, startX){
      if (!taskObj || !taskObj.bar || !taskObj.zone || !taskObj.taskId) return false;
      if (savingTaskIds[taskObj.taskId]) {
        showSaveNotice('저장 중인 공정입니다. 잠시 후 다시 시도해주세요.', false);
        return false;
      }
      dragState.isDragging = true;
      dragState.currentDragTask = taskObj;
      dragState.dragMode = mode;
      dragState.dragStartX = startX;
      dragState.origLeft = parseFloat(taskObj.bar.style.left || '0');
      dragState.origWidth = parseFloat(taskObj.bar.style.width || (ganttDayWidth + ''));
      dragState.moved = false;
      dragState.originalStartDate = taskObj.zone.getAttribute('data-start') || '';
      dragState.originalEndDate = taskObj.zone.getAttribute('data-end') || '';
      if (isNaN(dragState.origLeft)) dragState.origLeft = 0;
      if (isNaN(dragState.origWidth) || dragState.origWidth <= 0) dragState.origWidth = ganttDayWidth;
      taskObj.bar.classList.add('dragging');
      document.addEventListener('mousemove', onBoardMouseMove);
      document.addEventListener('mouseup', onBoardMouseUp);
      return true;
    }

    function getTaskObjFromElement(el){
      if (!el) return null;
      var bar = el.closest('.gantt-bar');
      if (!bar || !board.contains(bar)) return null;
      var row = bar.closest('.gantt-row');
      var zone = bar.closest('.gantt-dropzone');
      var taskId = row ? (parseInt(row.getAttribute('data-task-id') || '0', 10) || 0) : 0;
      if (!zone || taskId <= 0) return null;
      return { bar: bar, row: row, zone: zone, taskId: taskId };
    }

    function onBoardMouseMove(e){
      if (!dragState.isDragging || !dragState.currentDragTask) return;
      var taskObj = dragState.currentDragTask;
      var bar = taskObj.bar;
      var delta = e.clientX - dragState.dragStartX;
      if (Math.abs(delta) > 2) dragState.moved = true;

      var minWidthPx = ganttDayWidth;
      var maxLeftPx = (gridDays * ganttDayWidth) - dragState.origWidth;
      if (dragState.dragMode === 'drag') {
        var rawLeft = dragState.origLeft + delta;
        var rawIndex = rawLeft / ganttDayWidth;
        var snapIndex = clamp(Math.round(rawIndex), 0, Math.round(maxLeftPx / ganttDayWidth));
        bar.style.left = (snapIndex * ganttDayWidth) + 'px';
      } else if (dragState.dragMode === 'left') {
        var rightEdge = dragState.origLeft + dragState.origWidth;
        var newLeftL = clamp(dragState.origLeft + delta, 0, rightEdge - minWidthPx);
        var snapLeft = clamp(Math.round(newLeftL / ganttDayWidth), 0, gridDays - 1);
        var snappedLeftPx = snapLeft * ganttDayWidth;
        var newWidthL = rightEdge - snappedLeftPx;
        var snapDurL = Math.max(1, Math.round(newWidthL / ganttDayWidth));
        bar.style.left = snappedLeftPx + 'px';
        bar.style.width = (snapDurL * ganttDayWidth) + 'px';
      } else if (dragState.dragMode === 'right') {
        var maxWidth = (gridDays * ganttDayWidth) - dragState.origLeft;
        var newWidth = clamp(dragState.origWidth + delta, minWidthPx, maxWidth);
        var snapDur = Math.max(1, Math.round(newWidth / ganttDayWidth));
        bar.style.width = (snapDur * ganttDayWidth) + 'px';
      }
    }

    function onBoardMouseUp(){
      if (!dragState.isDragging || !dragState.currentDragTask) return;
      var taskObj = dragState.currentDragTask;
      var bar = taskObj.bar;
      var zone = taskObj.zone;
      var safeTaskId = taskObj.taskId;

      var leftPx = parseFloat(bar.style.left || '0');
      var widthPx = parseFloat(bar.style.width || (ganttDayWidth + ''));
      var leftDays = clamp(Math.round(leftPx / ganttDayWidth), 0, gridDays - 1);
      var durDays = Math.max(1, Math.round(widthPx / ganttDayWidth));
      durDays = clamp(durDays, 1, gridDays - leftDays);
      setBarPosition(bar, leftDays, durDays);

      if (!dragState.moved) {
        cleanupDragState();
        return;
      }

      var newStartDate = tsToYmd(rangeStartTs + (leftDays * 86400));
      var newEndDate = tsToYmd(rangeStartTs + ((leftDays + durDays - 1) * 86400));
      var originalStartDate = dragState.originalStartDate;
      var originalEndDate = dragState.originalEndDate;

      // task별 자동저장 상태
      savingTaskIds[safeTaskId] = true;
      bar.classList.add('saving');
      bar.style.pointerEvents = 'none';
      showSaveNotice('저장 중...', true);
      if (window.console && console.log) {
        console.log('schedule_move request', { task_id: safeTaskId, start_date: newStartDate, end_date: newEndDate, url: '?r=construction/schedule_move' });
      }

      function restoreOriginalPosition(){
        var restoreSpan = getTaskSpanByDate(originalStartDate, originalEndDate);
        if (restoreSpan) setBarPosition(bar, restoreSpan.leftDays, restoreSpan.duration);
        if (zone) {
          zone.setAttribute('data-start', originalStartDate);
          zone.setAttribute('data-end', originalEndDate);
        }
        bar.setAttribute('data-start-date', originalStartDate);
        bar.setAttribute('data-end-date', originalEndDate);        
      }

      function finalizeAfterSave(){
        delete savingTaskIds[safeTaskId];
        cleanupDragState();
      }

      saveTaskMove(safeTaskId, newStartDate, newEndDate).then(function(resp){
        if (resp && resp.ok) {
          var appliedStart = resp.start_date || newStartDate;
          var appliedEnd = resp.end_date || newEndDate;
          // 저장 성공 후 data 날짜 갱신
          zone.setAttribute('data-start', appliedStart);
          zone.setAttribute('data-end', appliedEnd);
          bar.setAttribute('data-start-date', appliedStart);
          bar.setAttribute('data-end-date', appliedEnd);
          // 공정표 자동저장 후 dirty 상태 + 캐시방지 timestamp 갱신
          window.cpmsGanttDirty = true;
          window.cpmsGanttRefreshTs = Date.now();          
          showSaveNotice('저장됨', true);
        } else {
          restoreOriginalPosition();
          showSaveNotice('저장 실패: ' + ((resp && resp.message) ? resp.message : '알 수 없는 오류'), false);
          if (window.console && console.log && resp && resp.raw_text) console.log('schedule_move fail response:', String(resp.raw_text).substring(0, 300));
        }
        finalizeAfterSave();
      }).catch(function(err){
        // 저장 실패 원위치 복구
        restoreOriginalPosition();
        showSaveNotice('저장 실패: 네트워크 오류', false);
        if (window.console && console.log) console.log('schedule_move network error:', err);
        finalizeAfterSave();
      });
    }

    // 이벤트 위임 방식
    board.addEventListener('mousedown', function(e){
      var taskObj = getTaskObjFromElement(e.target);
      if (!taskObj) return;
      if (dragState.isDragging) return;
      if (e.target.classList.contains('gantt-handle-left')) {
        e.preventDefault();
        startDragForTask(taskObj, 'left', e.clientX);
        return;
      }
      if (e.target.classList.contains('gantt-handle-right')) {
        e.preventDefault();
        startDragForTask(taskObj, 'right', e.clientX);
        return;
      }
      if (e.target.closest('.gantt-handle')) return;
      e.preventDefault();
      startDragForTask(taskObj, 'drag', e.clientX);      
    });

    // 수정탭 모달 비활성: 공정표 수정 탭(.gantt-board)에서는 진행 입력 모달을 열지 않음
  }

  function renderPhotoList(listEl, photos){
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!photos || !photos.length) return;
    photos.forEach(function(photo){
      var url = photo.view_url || photo.file_path || '';
      var downloadUrl = photo.download_url || '';
      var name = photo.file_name || '사진';
      if (!url && !downloadUrl) return;
      var row = document.createElement('div');
      row.className = 'flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-3 py-2';
      var linksHtml = '<div class="flex items-center gap-2">';
      if (url) {
        linksHtml += '<a class="text-blue-700 underline text-xs" href="' + url + '" target="_blank" rel="noopener">보기</a>';
      }
      if (downloadUrl) {
        linksHtml += '<a class="text-blue-700 underline text-xs" href="' + downloadUrl + '" download>다운로드</a>';
      }
      linksHtml += '</div>';
      row.innerHTML = '<span class="truncate">' + name + '</span>' + linksHtml;
      listEl.appendChild(row);
    });
  }

  function toNumber(val){
    if (val === null || typeof val === 'undefined' || val === '') return null;
    var num = parseFloat(String(val).replace(/,/g, ''));
    if (isNaN(num)) return null;
    return num;
  }

  function formatQty0(val){
    var num = toNumber(val);
    if (num === null) return '';
    return String(Math.round(num));
  }

  function getTaskBaseTotal(taskId, fallbackTotal){
    if (!taskId) return toNumber(fallbackTotal);
    var baseTotal = toNumber(fallbackTotal);
    if (baseTotal !== null && baseTotal > 0) return baseTotal;
    var foundDate = '';
    Object.keys(progressMap || {}).forEach(function(key){
      if (!Object.prototype.hasOwnProperty.call(progressMap, key)) return;
      var parts = key.split('|');
      if (!parts.length || parts[0] !== taskId) return;
      var date = parts.slice(1).join('|');
      var entry = progressMap[key] || {};
      var total = toNumber(entry.total_qty);
      if (total === null) return;
      if (foundDate === '' || date < foundDate) {
        foundDate = date;
        baseTotal = total;
      }
    });
    return baseTotal;
  }

  function getDoneBefore(taskId, taskDate){
    if (!progressMap || !taskId || !taskDate) return 0;
    var sum = 0;
    Object.keys(progressMap).forEach(function(key){
      if (!Object.prototype.hasOwnProperty.call(progressMap, key)) return;
      var parts = key.split('|');
      if (!parts.length || parts[0] !== taskId) return;
      var date = parts.slice(1).join('|');
      if (!date || date >= taskDate) return;
      var entry = progressMap[key] || {};
      var done = toNumber(entry.done_qty);
      if (done === null) return;
      sum += done;
    });
    return sum;
  }

  function getTaskDailyEntries(taskId){
    var out = [];
    Object.keys(progressMap || {}).forEach(function(key){
      if (!Object.prototype.hasOwnProperty.call(progressMap, key)) return;
      var parts = key.split('|');
      if (!parts.length || parts[0] !== taskId) return;
      var date = parts.slice(1).join('|');
      var entry = progressMap[key] || {};
      var done = toNumber(entry.done_qty);
      out.push({ date: date, done: done });
    });
    return out.sort(function(a,b){ return a.date < b.date ? -1 : (a.date > b.date ? 1 : 0); });
  }

  function suggestAutoQty(taskId, taskDate, baseTotal){
    var range = getTaskDateRange(taskId);
    if (!range.start || !range.end || !taskDate || !baseTotal || taskDate < todayYmd) return null;
    var remainingTotal = normalizeInt(baseTotal);
    var manualMap = {};
    getTaskDailyEntries(taskId).forEach(function(item){
      if (item.done === null) return;
      if (item.date < todayYmd) {
        remainingTotal -= normalizeInt(item.done);
      } else {
        manualMap[item.date] = normalizeInt(item.done);
      }
    });
    if (remainingTotal < 0) remainingTotal = 0;

    var dates = [];
    var cur = (range.start > todayYmd) ? range.start : todayYmd;
    while (cur && cur <= range.end) {
      dates.push(cur);
      cur = ymdAddDays(cur, 1);
    }
    if (!dates.length || dates.indexOf(taskDate) === -1) return null;

    var freeDays = [];
    var lockedTotal = 0;
    dates.forEach(function(d){
      if (Object.prototype.hasOwnProperty.call(manualMap, d)) lockedTotal += manualMap[d];
      else freeDays.push(d);
    });
    var allocatable = remainingTotal - lockedTotal;
    if (allocatable < 0) allocatable = 0;
    if (Object.prototype.hasOwnProperty.call(manualMap, taskDate)) return manualMap[taskDate];
    if (!freeDays.length) return 0;
    var base = Math.floor(allocatable / freeDays.length);
    var remain = allocatable - (base * freeDays.length);
    for (var i = 0; i < freeDays.length; i++) {
      var d = freeDays[i];
      var val = base;
      if (i === freeDays.length - 1) val += remain;
      if (d === taskDate) return val;
    }
    return null;
  }
  
  var currentProgressContext = {
    taskId: '',
    taskDate: '',
    baseTotal: null,
    doneBefore: 0
  };

  function escapeHtml(str){
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function renderTaskItemTable(taskId){
    var bodyEl = document.getElementById('ganttItemQtyBody');
    var doneEl = document.getElementById('ganttDoneQty');
    var totalEl = document.getElementById('ganttTotalQty');
    if (!bodyEl) return;
    var workId = getTaskWorkId(taskId);    
    var rows = (taskItemMap && taskItemMap[taskId]) ? taskItemMap[taskId] : [];
    var taskDate = currentProgressContext && currentProgressContext.taskDate ? currentProgressContext.taskDate : '';
    var doneMapByDate = (taskItemDoneMap && taskItemDoneMap[taskId]) ? taskItemDoneMap[taskId] : {};
    var doneMap = (taskDate && doneMapByDate && doneMapByDate[taskDate]) ? doneMapByDate[taskDate] : {};
    bodyEl.innerHTML = '';
    var taskRange = getTaskDateRange(taskId);
    // 과거 공정 모달 자동완료 표시: 종료일이 오늘 00:00:00 이전이면 모달 기본 완료수량을 총수량으로 표시
    var isPastTask = !!(taskRange && taskRange.end && todayYmd && taskRange.end < todayYmd);    
    var totalQtySum = 0;
    var doneQtySum = 0;

    if (!rows || !rows.length) {
      if (workId > 0) {
        bodyEl.innerHTML = '<tr><td class="p-3 border text-center text-gray-500" colspan="4">작업 탭에 연결된 내역서 항목이 없습니다.</td></tr>';
      } else {
        // 모달에 작업-내역서 항목 표시
        bodyEl.innerHTML = '<tr><td class="p-3 border text-center text-amber-700 bg-amber-50" colspan="4">연결된 작업내용이 없습니다(작업 탭에서 작업을 만들어 연결해주세요).</td></tr>';
      }
      if (totalEl) totalEl.value = '0';
      if (doneEl) doneEl.value = '0';
      return;
    }

    rows.forEach(function(item){
      var uid = parseInt(item.unit_price_id, 10) || 0;
      var contractQty = toNumber(item.contract_qty);
      if (contractQty === null) contractQty = 0;
      var hasSavedDoneQty = false;
      var doneQty = 0;
      if (doneMap && Object.prototype.hasOwnProperty.call(doneMap, uid)) {
        var itemSaved = doneMap[uid];
        var savedDoneQty = toNumber((itemSaved && typeof itemSaved === 'object') ? itemSaved.done_qty : itemSaved);
        if (savedDoneQty !== null) {
          hasSavedDoneQty = true;
          doneQty = savedDoneQty;
        }
      }
      if (!hasSavedDoneQty && isPastTask) doneQty = contractQty;
      if (doneQty < 0) doneQty = 0;
      var remain = contractQty - doneQty;
      if (remain < 0) remain = 0;
      totalQtySum += contractQty;
      doneQtySum += doneQty;

      var tr = document.createElement('tr');
      tr.innerHTML = '<td class="p-2 border">' + escapeHtml(item.item_name || '') + '</td>' +
        '<td class="p-2 border text-right">' + formatQty0(contractQty) + '</td>' +
        '<td class="p-2 border text-right"><input type="number" min="0" step="1" class="gantt-item-done w-24 px-2 py-1 border border-gray-200 rounded text-right" data-unit-price-id="' + uid + '" data-contract-qty="' + contractQty + '" value="' + formatQty0(doneQty) + '"></td>' +
        '<td class="p-2 border text-right"><span class="gantt-item-remain">' + formatQty0(remain) + '</span></td>';
      bodyEl.appendChild(tr);
    });

    if (totalEl) totalEl.value = formatQty0(totalQtySum);
    if (doneEl) doneEl.value = formatQty0(doneQtySum);

    bodyEl.querySelectorAll('.gantt-item-done').forEach(function(input){
      input.addEventListener('input', function(){
        var contractQty = toNumber(input.getAttribute('data-contract-qty'));
        if (contractQty === null) contractQty = 0;
        var val = toNumber(input.value);
        if (val === null || val < 0) val = 0;
        if (contractQty > 0 && val > contractQty) val = contractQty;
        input.value = formatQty0(val);
        var remainEl = input.closest('tr').querySelector('.gantt-item-remain');
        if (remainEl) remainEl.textContent = formatQty0(contractQty - val);

        var doneTotal = 0;
        bodyEl.querySelectorAll('.gantt-item-done').forEach(function(inp){
          var iv = toNumber(inp.value);
          doneTotal += (iv === null ? 0 : iv);
        });
        if (doneEl) doneEl.value = formatQty0(doneTotal);
        updateRemainQty();
      });
    });
  }

  function openProgress(taskId, taskName, taskDate, totalQty){
    var taskIdEl = document.getElementById('ganttProgressTaskId');
    if (taskIdEl) taskIdEl.value = taskId || '';
    var nameEl = document.getElementById('ganttProgressTaskName');
    var dateEl = document.getElementById('ganttProgressTaskDate');
    if (nameEl) nameEl.textContent = taskName;
    if (dateEl) dateEl.textContent = taskDate;

    var dateInputEl = document.getElementById('ganttProgressTaskDateInput');
    if (dateInputEl) dateInputEl.value = taskDate || '';

    var totalEl = document.getElementById('ganttTotalQty');
    var doneEl = document.getElementById('ganttDoneQty');
    var modeEl = document.getElementById('ganttProgressInputMode');
    var hintEl = document.getElementById('ganttDoneQtyHint');
    var sourceEl = document.getElementById('ganttProgressSourceStatus');
    var autoToggleEl = document.getElementById('ganttAutoDistributionToggle');
    var mapKey = taskId + '|' + taskDate;
    var saved = (progressMap && progressMap[mapKey]) ? progressMap[mapKey] : null;
    var baseTotal = getTaskBaseTotal(taskId, totalQty);
    var totalVal = (baseTotal !== null && typeof baseTotal !== 'undefined') ? formatQty0(baseTotal) : '';
    var doneVal = '0';
    var inputMode = 'manual';
    if (autoToggleEl) {
      var savedAuto = window.localStorage ? window.localStorage.getItem('cpms:auto:' + taskId) : null;
      autoToggleEl.checked = (savedAuto !== 'off');
    }
    if (saved && saved.done_qty !== null && typeof saved.done_qty !== 'undefined' && saved.done_qty !== '') {
      doneVal = formatQty0(saved.done_qty);
      inputMode = 'manual';
      if (hintEl) hintEl.textContent = '저장된 완료수량입니다.';
      if (sourceEl) {
        if (parseInt(saved.is_manual || 0, 10) === 1) sourceEl.textContent = '수동 수정';
        else if (parseInt(saved.is_auto || 0, 10) === 1) sourceEl.textContent = '자동 반영';
        else sourceEl.textContent = '저장값';
      }
    } else {
      if (sourceEl) sourceEl.textContent = '';
      var useAuto = autoToggleEl ? !!autoToggleEl.checked : true;
      var suggested = useAuto ? suggestAutoQty(taskId, taskDate, baseTotal) : null;
      if (suggested !== null) {
        doneVal = formatQty0(suggested);
        inputMode = 'auto';
        if (hintEl) hintEl.textContent = '자동 제안값(저장 전 수정 가능)';
      } else if (hintEl) {
        hintEl.textContent = '';
      }
    }
    if (modeEl) modeEl.value = inputMode;    
    if (totalEl) totalEl.value = totalVal;
    if (doneEl) doneEl.value = doneVal;

    currentProgressContext.taskId = taskId;
    currentProgressContext.taskDate = taskDate;
    currentProgressContext.baseTotal = baseTotal;
    currentProgressContext.doneBefore = 0;
    renderTaskItemTable(taskId);
    var shiftInfoEl = document.getElementById('ganttShiftInfo');
    var taskRange = getTaskDateRange(taskId);
    if (shiftInfoEl) {
      var shiftStart = (taskRange.start && taskRange.start > todayYmd) ? taskRange.start : todayYmd;
      var shiftEnd = taskRange.end || '-';
      shiftInfoEl.textContent = '이동 범위: ' + shiftStart + ' ~ ' + shiftEnd + ' (과거 데이터 이동 금지, 충돌 시 합치기)';
    }
    updateRemainQty();

    var listEl = document.getElementById('ganttPhotoList');
    if (listEl) {
      var photos = (progressPhotoMap && progressPhotoMap[mapKey]) ? progressPhotoMap[mapKey] : [];
      renderPhotoList(listEl, photos);
    }
    var inputEl = document.getElementById('ganttPhotoInput');
    if (inputEl) inputEl.value = '';

    openModal('ganttProgress');
  }

  function getRowTotalQty(row){
    if (!row) return '';
    var qty = row.getAttribute('data-task-total-qty');
    if (qty === null || typeof qty === 'undefined') return '';
    return qty;
  }

  if (readOnlyBoard) {
    readOnlyBoard.querySelectorAll('.gantt-bar').forEach(function(bar){
      bar.addEventListener('click', function(e){
        var zone = bar.closest('.gantt-dropzone');
        var row = bar.closest('.gantt-row');
        if (!zone || !row) return;
        var taskName = row.getAttribute('data-task-name') || '';
        var zoneRect = zone.getBoundingClientRect();
        var offsetX = e.clientX - zoneRect.left;
        var leftDays = dayFromOffset(offsetX);
        var dateTs = rangeStartTs + (leftDays * 86400);
        var taskId = row.getAttribute('data-task-id') || '';
        openProgress(taskId, taskName, tsToYmd(dateTs), getRowTotalQty(row));
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

  var recalcQueued = false;
  function requestRecalcGanttLayout(){
    if (recalcQueued) return;
    recalcQueued = true;
    window.requestAnimationFrame(function(){
      recalcQueued = false;
      recalcGanttLayout();
    });
  }

  if (typeof ResizeObserver !== 'undefined') {
    var resizeObserver = new ResizeObserver(function(){
      requestRecalcGanttLayout();
    });
    document.querySelectorAll('.gantt-header, .gantt-board, .gantt-board-readonly').forEach(function(el){
      resizeObserver.observe(el);
    });
  }
  window.addEventListener('resize', requestRecalcGanttLayout);
  requestRecalcGanttLayout();

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
      // 공정표 보기 탭 최신화: 자동저장 이후 overview 클릭 시 서버 재렌더링
      if (target === 'overview' && window.cpmsGanttDirty === true) {
        window.location.href = buildGanttUrl('overview', window.cpmsGanttRefreshTs || Date.now());
        return;
      }
      if (target === 'board') {
        history.replaceState(null, '', buildGanttUrl('edit', window.cpmsGanttRefreshTs || 0));
      } else if (target === 'work') {
        history.replaceState(null, '', buildGanttUrl('work', window.cpmsGanttRefreshTs || 0));
      } else {
        history.replaceState(null, '', buildGanttUrl('overview', window.cpmsGanttRefreshTs || 0));
      }
      requestRecalcGanttLayout();
    });
  });

  if (initialPanel) {
    var initialTabBtn = document.querySelector('.gantt-tab[data-tab="' + initialPanel + '"]');
    if (initialTabBtn) initialTabBtn.click();
  } else if (initialMode === 'edit') {
    var boardTabBtn = document.querySelector('.gantt-tab[data-tab="board"]');
    if (boardTabBtn) boardTabBtn.click();
  }

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
    var baseTotal = (currentProgressContext && currentProgressContext.baseTotal !== null) ? currentProgressContext.baseTotal : toNumber(totalEl.value);
    var doneNow = toNumber(doneEl.value);
    if (doneNow === null) doneNow = 0;
    var doneBefore = (currentProgressContext && typeof currentProgressContext.doneBefore === 'number') ? currentProgressContext.doneBefore : 0;
    if (baseTotal === null || typeof baseTotal === 'undefined' || baseTotal === '') {
      remainEl.textContent = '-';
      return;
    }
    var remain = baseTotal - (doneBefore + doneNow);
    if (remain < 0) {
      remainEl.textContent = '-';
      return;
    }
    remainEl.textContent = formatQty0(remain);
  }

  document.querySelectorAll('.gantt-progress-date').forEach(function(btn){
    btn.addEventListener('click', function(){
      var taskName = btn.getAttribute('data-task-name') || '';
      var taskDate = btn.getAttribute('data-task-date') || '';
      var taskId = btn.getAttribute('data-task-id') || '';
      var totalQty = btn.getAttribute('data-task-total-qty') || '';
      openProgress(taskId, taskName, taskDate, totalQty);
    });
  });

  var totalQtyInput = document.getElementById('ganttTotalQty');
  if (totalQtyInput) totalQtyInput.addEventListener('input', updateRemainQty);
  var doneQtyInput = document.getElementById('ganttDoneQty');
  if (doneQtyInput) doneQtyInput.addEventListener('input', function(){
    var modeEl = document.getElementById('ganttProgressInputMode');
    var hintEl = document.getElementById('ganttDoneQtyHint');
    if (modeEl) modeEl.value = 'manual';
    if (hintEl && doneQtyInput.value !== '') hintEl.textContent = '수동 입력값(자동 덮어쓰기 금지)';
    updateRemainQty();
  });

  var autoDistributionToggle = document.getElementById('ganttAutoDistributionToggle');
  if (autoDistributionToggle) {
    autoDistributionToggle.addEventListener('change', function(){
      var taskIdEl = document.getElementById('ganttProgressTaskId');
      var taskId = taskIdEl ? taskIdEl.value : '';
      if (taskId && window.localStorage) {
        window.localStorage.setItem('cpms:auto:' + taskId, autoDistributionToggle.checked ? 'on' : 'off');
      }
      openProgress(
        taskId,
        document.getElementById('ganttProgressTaskName') ? document.getElementById('ganttProgressTaskName').textContent : '',
        document.getElementById('ganttProgressTaskDateInput') ? document.getElementById('ganttProgressTaskDateInput').value : '',
        document.getElementById('ganttTotalQty') ? document.getElementById('ganttTotalQty').value : ''
      );
    });
  }

  function applyScheduleShift(dayDelta){
    if (!board) return;
    var taskIdEl = document.getElementById('ganttProgressTaskId');
    var taskDateEl = document.getElementById('ganttProgressTaskDateInput');
    var taskId = taskIdEl ? taskIdEl.value : '';
    var taskDate = taskDateEl ? taskDateEl.value : '';
    if (!taskId || !taskDate || !dayDelta) return;
    if (!confirm('선택 공정의 오늘~미래 입력값을 ' + dayDelta + '일 이동합니다. 충돌 날짜는 합산됩니다. 계속할까요?')) return;

    var fd = new FormData();
    fd.append('_csrf', csrfToken || '');
    fd.append('project_id', projectId || '0');
    fd.append('task_id', taskId);
    fd.append('work_date', taskDate);
    fd.append('action', 'shift');
    fd.append('shift_days', String(dayDelta));
    fd.append('shift_from', todayYmd || taskDate);
    fetch('?r=construction/schedule_progress_save', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function(){ reloadWithState(); }) // 저장 후 탭 유지
      .catch(function(){ reloadWithState(); });
  }

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

  var shiftPlusBtn = document.getElementById('ganttShiftPlus1');
  if (shiftPlusBtn) shiftPlusBtn.addEventListener('click', function(){ applyScheduleShift(1); });
  var shiftMinusBtn = document.getElementById('ganttShiftMinus1');
  if (shiftMinusBtn) shiftMinusBtn.addEventListener('click', function(){ applyScheduleShift(-1); });
  var shiftApplyPlusBtn = document.getElementById('ganttShiftApplyPlus');
  if (shiftApplyPlusBtn) shiftApplyPlusBtn.addEventListener('click', function(){
    var daysEl = document.getElementById('ganttShiftDaysInput');
    var days = daysEl ? parseInt(daysEl.value, 10) : 0;
    if (!days || days < 1) return;
    applyScheduleShift(days);
  });
  var shiftApplyMinusBtn = document.getElementById('ganttShiftApplyMinus');
  if (shiftApplyMinusBtn) shiftApplyMinusBtn.addEventListener('click', function(){
    var daysEl = document.getElementById('ganttShiftDaysInput');
    var days = daysEl ? parseInt(daysEl.value, 10) : 0;
    if (!days || days < 1) return;
    applyScheduleShift(-days);
  });

  var progressSaveBtn = document.getElementById('ganttProgressSave');
  if (progressSaveBtn) {
    progressSaveBtn.addEventListener('click', function(){
      if (!board) return;
      var taskIdEl = document.getElementById('ganttProgressTaskId');
      var dateInputEl = document.getElementById('ganttProgressTaskDateInput');
      var totalEl = document.getElementById('ganttTotalQty');
      var doneEl = document.getElementById('ganttDoneQty');
      var taskId = taskIdEl ? taskIdEl.value : '';
      var taskDate = dateInputEl ? dateInputEl.value : '';
      if (!taskId || !taskDate) {
        alert('작업 정보가 없습니다.');
        return;
      }
      var fd = new FormData();
      fd.append('_csrf', csrfToken || '');
      fd.append('project_id', projectId || '0');
      fd.append('task_id', taskId);
      fd.append('work_date', taskDate);
      fd.append('total_qty', totalEl ? totalEl.value : '');
      fd.append('done_qty', doneEl ? doneEl.value : '');
      var modeEl = document.getElementById('ganttProgressInputMode');
      var autoEl = document.getElementById('ganttAutoDistributionToggle');
      fd.append('input_mode', modeEl ? modeEl.value : 'manual');
      fd.append('auto_distribution', autoEl && autoEl.checked ? '1' : '0');
      var itemDoneMap = {};
      var itemRows = document.querySelectorAll('#ganttItemQtyBody .gantt-item-done');
      itemRows.forEach(function(inp){
        var uid = inp.getAttribute('data-unit-price-id') || '';
        if (!uid) return;
        itemDoneMap[uid] = inp.value || '0';
      });
      Object.keys(itemDoneMap).forEach(function(uid){
        fd.append('item_done_qty[' + uid + ']', itemDoneMap[uid]);
      });
      if (photoInput && photoInput.files && photoInput.files.length > 0) {
        Array.prototype.forEach.call(photoInput.files, function(file){
          fd.append('photos[]', file);
        });
      }

      fetch('?r=construction/schedule_task_item_progress_save', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      }).then(function(){ reloadWithState(); }) // 저장 후 탭 유지
        .catch(function(){ reloadWithState(); });
    });
  }

  var jumpTodayBtn = document.getElementById('ganttJumpToday');
  if (jumpTodayBtn) {
    jumpTodayBtn.addEventListener('click', function(){
      if (!todayYmd) return;
      var month = todayYmd.slice(0, 7);
      var params = new URLSearchParams(window.location.search);
      params.set('r', '공사');
      if (projectId) params.set('pid', projectId);
      params.set('tab', 'gantt');
      params.set('month', month);
      if (shouldKeepEditMode()) params.set('mode', 'edit');
      else params.delete('mode');
      window.location.search = params.toString();
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
      if (shouldKeepEditMode()) params.set('mode', 'edit');
      else params.delete('mode');
      window.location.search = params.toString(); 
    });
  });
})();
</script>
