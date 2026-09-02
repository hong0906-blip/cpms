<?php
/**
 * 일회성 공수 분쟁 조사 화면 (PHP 5.6 / SELECT ONLY)
 */
if (!\App\Core\Auth::check() || !\App\Core\Auth::isDevelopmentDepartment()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '접근 권한이 없습니다.';
    exit;
}
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');

function cpms_forensic_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function cpms_forensic_worker_key($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}
function cpms_forensic_table_columns($pdo, $tableName) {
    if (!$pdo || !preg_match('/^[a-zA-Z0-9_]+$/', (string)$tableName)) return array();
    try {
        $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name
                ORDER BY ORDINAL_POSITION";
        $st = $pdo->prepare($sql);
        $st->bindValue(':table_name', (string)$tableName, PDO::PARAM_STR);
        $st->execute();
        $result = array();
        while ($column = $st->fetchColumn()) $result[(string)$column] = true;
        return $result;
    } catch (Exception $e) {
        return array();
    }
}
function cpms_forensic_number($value) {
    if ($value === null || $value === '' || !is_numeric($value)) return '없음';
    return rtrim(rtrim(number_format(round((float)$value, 2), 2, '.', ''), '0'), '.');
}
function cpms_forensic_raw_value($row, $column) {
    if (!array_key_exists($column, $row)) return '컬럼 없음';
    if ($row[$column] === null) return 'NULL';
    if ((string)$row[$column] === '') return '(빈 문자열)';
    return (string)$row[$column];
}
function cpms_forensic_resolve_current($rows) {
    $resolved = null;
    $hasApplied = false;
    foreach ((array)$rows as $row) {
        $status = isset($row['status']) ? trim((string)$row['status']) : '';
        if ($status === 'applied' || $status === 'approved') {
            $resolved = array(
                'value' => isset($row['new_value']) && is_numeric($row['new_value']) ? (float)$row['new_value'] : 0.0,
                'is_deleted' => isset($row['is_deleted_entry']) && (int)$row['is_deleted_entry'] === 1,
                'source' => 'new_value (' . $status . ')', 'row' => $row
            );
            $hasApplied = true;
            continue;
        }
        if (!$hasApplied && isset($row['old_value']) && is_numeric($row['old_value'])) {
            $oldValue = (float)$row['old_value'];
            $resolved = array(
                'value' => $oldValue, 'is_deleted' => $oldValue <= 0,
                'source' => 'old_value 복원 (' . ($status !== '' ? $status : '상태 없음') . ')', 'row' => $row
            );
        }
    }
    return $resolved;
}
function cpms_forensic_resolve_20260805($rows) {
    $resolved = null;
    foreach ((array)$rows as $row) {
        $status = isset($row['status']) ? trim((string)$row['status']) : '';
        if ($status !== 'applied' && $status !== 'approved') continue;
        $resolved = array(
            'value' => isset($row['new_value']) && is_numeric($row['new_value']) ? (float)$row['new_value'] : 0.0,
            'is_deleted' => isset($row['is_deleted_entry']) && (int)$row['is_deleted_entry'] === 1,
            'source' => 'new_value (' . $status . ')', 'row' => $row
        );
    }
    return $resolved;
}
function cpms_forensic_final_value($attendanceValue, $override) {
    if (!is_array($override)) return $attendanceValue;
    if (!empty($override['is_deleted']) || !isset($override['value']) || (float)$override['value'] <= 0) return null;
    return (float)$override['value'];
}
function cpms_forensic_add_timeline(&$events, &$seen, $time, $text, $detail) {
    $time = trim((string)$time);
    if ($time === '') return;
    $key = $time . '|' . $text . '|' . $detail;
    if (isset($seen[$key])) return;
    $seen[$key] = true;
    $events[] = array('time' => $time, 'text' => $text, 'detail' => $detail);
}

$incidentProjectName = '25~26년 기흥화성 경영계획 대응공사 中 가스룸 전환공사';
$defaultMonth = '2026-07';
$defaultWorkerName = '김경태';
$defaultWorkDate = '2026-07-20';
$windowStart = '2026-07-23 09:35:09';
$windowEnd = '2026-08-05 23:59:59';
$historicalCommit = 'c83f41f9da5871a422057d95f3136b57d80bdfe3';
$restorationCommit = 'f12f3c8f504418b323d1494919357a2411ca7598';

$month = isset($_GET['month']) ? trim((string)$_GET['month']) : $defaultMonth;
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = $defaultMonth;
$workerName = isset($_GET['worker_name']) ? trim((string)$_GET['worker_name']) : $defaultWorkerName;
if ($workerName === '') $workerName = $defaultWorkerName;
$workerName = function_exists('mb_substr') ? mb_substr($workerName, 0, 100, 'UTF-8') : substr($workerName, 0, 100);
$workDate = isset($_GET['work_date']) ? trim((string)$_GET['work_date']) : $defaultWorkDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)
    || !checkdate((int)substr($workDate, 5, 2), (int)substr($workDate, 8, 2), (int)substr($workDate, 0, 4))) {
    $workDate = $defaultWorkDate;
}

$pdo = null;
$projects = array();
$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$selectedProject = null;
$overrideColumns = array();
$displayColumns = array(
    'id', 'worker_key', 'worker_name', 'work_date', 'month', 'old_value', 'new_value',
    'is_deleted_entry', 'status', 'reason', 'requested_by', 'requested_by_name',
    'requested_by_email', 'created_at', 'updated_at', 'first_approver_name',
    'first_approved_at', 'second_approver_name', 'second_approved_at', 'approved_at',
    'final_approved_at', 'rejected_by_name', 'rejected_by_email', 'rejected_at',
    'reject_reason', 'approval_stage', 'approval_required_level'
);
$availableDisplayColumns = array();
$rawRows = array();
$errors = array();

try {
    $pdo = \App\Core\Db::pdo();
    if (!$pdo) throw new Exception('CPMS DB 연결을 확인할 수 없습니다.');
    $projectSt = $pdo->query("SELECT id, name FROM cpms_projects WHERE name NOT LIKE '(가제)%' ORDER BY name ASC, id ASC");
    $projects = $projectSt ? $projectSt->fetchAll(PDO::FETCH_ASSOC) : array();
    if (!is_array($projects)) $projects = array();
    if ($selectedProjectId <= 0) {
        foreach ($projects as $projectOption) {
            if (isset($projectOption['name']) && (string)$projectOption['name'] === $incidentProjectName) {
                $selectedProjectId = (int)$projectOption['id'];
                break;
            }
        }
    }
    foreach ($projects as $projectOption) {
        if ((int)$projectOption['id'] === $selectedProjectId) {
            $selectedProject = $projectOption;
            break;
        }
    }
    if ($selectedProjectId > 0 && !$selectedProject) {
        $selectedProjectId = 0;
        $errors[] = '선택한 현장을 확인할 수 없습니다.';
    }

    $overrideColumns = cpms_forensic_table_columns($pdo, 'cpms_labor_gongsu_overrides');
    foreach ($displayColumns as $displayColumn) {
        if (isset($overrideColumns[$displayColumn])) $availableDisplayColumns[] = $displayColumn;
    }
    $requiredColumns = array('project_id', 'worker_key', 'worker_name', 'work_date');
    $canReadRows = $selectedProjectId > 0;
    foreach ($requiredColumns as $requiredColumn) {
        if (!isset($overrideColumns[$requiredColumn])) $canReadRows = false;
    }
    if ($selectedProjectId > 0 && count($overrideColumns) === 0) {
        $errors[] = 'cpms_labor_gongsu_overrides 테이블을 찾을 수 없습니다.';
    } elseif ($selectedProjectId > 0 && !$canReadRows) {
        $errors[] = '공수 조정 테이블의 필수 컬럼을 확인할 수 없습니다.';
    }
    if ($canReadRows) {
        $queryColumns = $availableDisplayColumns;
        foreach ($requiredColumns as $requiredColumn) {
            if (!in_array($requiredColumn, $queryColumns, true)) $queryColumns[] = $requiredColumn;
        }
        foreach (array('project_id', 'status', 'old_value', 'new_value', 'is_deleted_entry', 'created_at', 'updated_at', 'approved_at', 'first_approved_at', 'second_approved_at', 'final_approved_at', 'rejected_at') as $analysisColumn) {
            if (isset($overrideColumns[$analysisColumn]) && !in_array($analysisColumn, $queryColumns, true)) $queryColumns[] = $analysisColumn;
        }
        $quotedColumns = array();
        foreach ($queryColumns as $queryColumn) $quotedColumns[] = '`' . $queryColumn . '`';
        $orderParts = array();
        if (isset($overrideColumns['created_at'])) $orderParts[] = '`created_at` ASC';
        if (isset($overrideColumns['id'])) $orderParts[] = '`id` ASC';
        if (count($orderParts) === 0) $orderParts[] = '`work_date` ASC';
        $monthWhere = isset($overrideColumns['month']) ? ' AND `month` = :month' : '';
        $sql = 'SELECT ' . implode(', ', $quotedColumns) . '
                FROM `cpms_labor_gongsu_overrides`
                WHERE `project_id` = :project_id AND `work_date` = :work_date
                  AND (`worker_name` = :worker_name OR `worker_key` = :worker_key)' . $monthWhere . '
                ORDER BY ' . implode(', ', $orderParts);
        $st = $pdo->prepare($sql);
        $st->bindValue(':project_id', $selectedProjectId, PDO::PARAM_INT);
        $st->bindValue(':work_date', $workDate, PDO::PARAM_STR);
        $st->bindValue(':worker_name', $workerName, PDO::PARAM_STR);
        $st->bindValue(':worker_key', cpms_forensic_worker_key($workerName), PDO::PARAM_STR);
        if (isset($overrideColumns['month'])) $st->bindValue(':month', $month, PDO::PARAM_STR);
        $st->execute();
        $rawRows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rawRows)) $rawRows = array();
    }
} catch (Exception $e) {
    $errors[] = $e->getMessage();
}

$workerKey = cpms_forensic_worker_key($workerName);
if (count($rawRows) > 0 && isset($rawRows[0]['worker_key']) && trim((string)$rawRows[0]['worker_key']) !== '') {
    $workerKey = trim((string)$rawRows[0]['worker_key']);
}
$attendanceValue = null;
$attendanceStart = '';
$attendanceEnd = '';
$attendanceError = '';
if ($pdo && $selectedProject && $month !== '') {
    try {
        require_once __DIR__ . '/../construction/tabs/partials/labor_data_loader.php';
        $gongsuData = cpms_load_gongsu_data($pdo, isset($selectedProject['name']) ? (string)$selectedProject['name'] : '', $month);
        $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
        $timeMap = isset($gongsuData['time_map']) && is_array($gongsuData['time_map']) ? $gongsuData['time_map'] : array();
        if (isset($gongsuMap[$workerKey]) && isset($gongsuMap[$workerKey][$workDate]) && is_numeric($gongsuMap[$workerKey][$workDate])) {
            $attendanceValue = (float)$gongsuMap[$workerKey][$workDate];
        }
        if (isset($timeMap[$workerKey]) && isset($timeMap[$workerKey][$workDate]) && is_array($timeMap[$workerKey][$workDate])) {
            $attendanceStart = isset($timeMap[$workerKey][$workDate]['start']) ? trim((string)$timeMap[$workerKey][$workDate]['start']) : '';
            $attendanceEnd = isset($timeMap[$workerKey][$workDate]['end']) ? trim((string)$timeMap[$workerKey][$workDate]['end']) : '';
        }
    } catch (Exception $e) {
        $attendanceError = $e->getMessage();
    }
}

$currentOverride = cpms_forensic_resolve_current($rawRows);
$historicalOverride = cpms_forensic_resolve_20260805($rawRows);
$currentFinalValue = cpms_forensic_final_value($attendanceValue, $currentOverride);
$historicalFinalValue = cpms_forensic_final_value($attendanceValue, $historicalOverride);

$timeline = array();
$timelineSeen = array();
$hasRejected = false;
$hasPositiveOldValue = false;
$rejectedRow = null;
$additionalEvents = array();
foreach ($rawRows as $rowIndex => $row) {
    $rowLabel = isset($row['id']) ? '#'.(string)$row['id'] : '#'.($rowIndex + 1);
    if (isset($row['old_value']) && is_numeric($row['old_value']) && (float)$row['old_value'] > 0) {
        $hasPositiveOldValue = true;
        cpms_forensic_add_timeline($timeline, $timelineSeen, $workDate, '기존 공수 ' . cpms_forensic_number($row['old_value']) . ' 존재', $rowLabel . ' old_value');
    }
    if (isset($row['created_at']) && trim((string)$row['created_at']) !== '') {
        $requestText = cpms_forensic_number(isset($row['old_value']) ? $row['old_value'] : null) . ' → ' . cpms_forensic_number(isset($row['new_value']) ? $row['new_value'] : null) . ' 변경 요청';
        if (isset($row['is_deleted_entry']) && (int)$row['is_deleted_entry'] === 1) $requestText = '공수 삭제 요청';
        cpms_forensic_add_timeline($timeline, $timelineSeen, $row['created_at'], $requestText, $rowLabel . (isset($row['requested_by_name']) && $row['requested_by_name'] !== '' ? ' / ' . $row['requested_by_name'] : ''));
    }
    if (isset($row['first_approved_at']) && trim((string)$row['first_approved_at']) !== '') {
        cpms_forensic_add_timeline($timeline, $timelineSeen, $row['first_approved_at'], '1차 승인', $rowLabel . (isset($row['first_approver_name']) && $row['first_approver_name'] !== '' ? ' / ' . $row['first_approver_name'] : ''));
    }
    if (isset($row['second_approved_at']) && trim((string)$row['second_approved_at']) !== '') {
        cpms_forensic_add_timeline($timeline, $timelineSeen, $row['second_approved_at'], '2차 승인', $rowLabel . (isset($row['second_approver_name']) && $row['second_approver_name'] !== '' ? ' / ' . $row['second_approver_name'] : ''));
    }
    if (isset($row['final_approved_at']) && trim((string)$row['final_approved_at']) !== '') {
        cpms_forensic_add_timeline($timeline, $timelineSeen, $row['final_approved_at'], '최종 승인', $rowLabel);
    } elseif (isset($row['approved_at']) && trim((string)$row['approved_at']) !== '') {
        cpms_forensic_add_timeline($timeline, $timelineSeen, $row['approved_at'], '승인', $rowLabel);
    }
    if (isset($row['status']) && (string)$row['status'] === 'rejected') $hasRejected = true;
    if (isset($row['rejected_at']) && trim((string)$row['rejected_at']) !== '') {
        $hasRejected = true;
        $rejectedRow = $row;
        cpms_forensic_add_timeline($timeline, $timelineSeen, $row['rejected_at'], '최종 반려', $rowLabel . (isset($row['rejected_by_name']) && $row['rejected_by_name'] !== '' ? ' / ' . $row['rejected_by_name'] : ''));
    }

    $createdAt = isset($row['created_at']) ? (string)$row['created_at'] : '';
    if ($createdAt > $windowStart && $createdAt <= $windowEnd) $additionalEvents[] = $createdAt . ' ' . $rowLabel . ' 추가 변경 요청';
    foreach (array('first_approved_at' => '재승인(1차)', 'second_approved_at' => '재승인(2차)', 'approved_at' => '재승인', 'final_approved_at' => '최종 재승인') as $eventColumn => $eventLabel) {
        $eventTime = isset($row[$eventColumn]) ? (string)$row[$eventColumn] : '';
        if ($eventTime > $windowStart && $eventTime <= $windowEnd) $additionalEvents[] = $eventTime . ' ' . $rowLabel . ' ' . $eventLabel;
    }
    $rejectedAt = isset($row['rejected_at']) ? (string)$row['rejected_at'] : '';
    if ($rejectedAt > $windowStart && $rejectedAt <= $windowEnd) $additionalEvents[] = $rejectedAt . ' ' . $rowLabel . ' 추가 반려';
    $changedAt = $createdAt !== '' ? $createdAt : (isset($row['updated_at']) ? (string)$row['updated_at'] : '');
    if ($changedAt > $windowStart && $changedAt <= $windowEnd && isset($row['is_deleted_entry']) && (int)$row['is_deleted_entry'] === 1) {
        $additionalEvents[] = $changedAt . ' ' . $rowLabel . ' 공수 삭제';
    } elseif ($changedAt > $windowStart && $changedAt <= $windowEnd && isset($row['new_value']) && is_numeric($row['new_value']) && (float)$row['new_value'] <= 0) {
        $additionalEvents[] = $changedAt . ' ' . $rowLabel . ' 0으로 변경';
    }
}
usort($timeline, function($a, $b) {
    if ($a['time'] === $b['time']) return 0;
    return ($a['time'] < $b['time']) ? -1 : 1;
});
$additionalEvents = array_values(array_unique($additionalEvents));
sort($additionalEvents);

$judgmentTitle = '현재 보존된 기록만으로는 판단 불가';
$judgmentClass = 'neutral';
$judgmentReasons = array();
if (count($additionalEvents) > 0) {
    $judgmentTitle = '사용자 변경 기록 존재';
    $judgmentClass = 'warn';
    $judgmentReasons = $additionalEvents;
} elseif ($hasPositiveOldValue && $hasRejected && $historicalFinalValue === null && $currentFinalValue !== null
    && is_array($currentOverride) && strpos($currentOverride['source'], 'old_value') === 0) {
    $judgmentTitle = '시스템 오류 가능성 매우 높음';
    $judgmentClass = 'danger';
    $judgmentReasons[] = '보존된 old_value에서 변경 요청 당시 기존 공수가 확인됩니다.';
    $judgmentReasons[] = '변경 요청의 최종 상태가 rejected입니다.';
    $judgmentReasons[] = '2026-07-23 09:35:09 이후 2026-08-05까지 추가 요청·삭제·0변경·재승인 기록이 없습니다.';
    $judgmentReasons[] = '2026-08-05 코드는 rejected 행을 로드하지 않아 출퇴근 원본이 없으면 해당 날짜가 빈칸이 됩니다.';
    $judgmentReasons[] = '2026-08-12 코드에서 rejected 행의 old_value 복원 로직이 추가되었습니다.';
} else {
    if (count($rawRows) === 0) $judgmentReasons[] = '해당 셀의 공수 조정 행이 현재 DB에 보존되어 있지 않습니다.';
    if (!$hasPositiveOldValue) $judgmentReasons[] = '기존 공수가 있었음을 확인할 old_value 근거가 부족합니다.';
    if (!$hasRejected) $judgmentReasons[] = '반려 상태 또는 반려 시각 근거가 부족합니다.';
    if ($historicalFinalValue !== null) $judgmentReasons[] = '2026-08-05 코드 기준 계산값이 없음으로 재현되지 않습니다.';
    if ($currentFinalValue === null) $judgmentReasons[] = '현재 최종 표시값을 확인할 수 없습니다.';
    if (count($judgmentReasons) === 0) $judgmentReasons[] = '현재 보존된 필드만으로 원인을 단정할 수 없습니다.';
}

$missingColumns = array_values(array_diff($displayColumns, $availableDisplayColumns));
$githubBase = 'https://github.com/hong0906-blip/cpms';
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>공수 데이터 원인 검증</title>
  <style>
    body{margin:0;background:#f4f6f8;color:#172033;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Malgun Gothic",sans-serif;font-size:14px}.wrap{max-width:1500px;margin:0 auto;padding:28px}.head{display:flex;justify-content:space-between;gap:16px;align-items:center}.head h1{margin:0;font-size:28px}.back{color:#315b9b;text-decoration:none}.alert,.card,.verdict{background:#fff;border:1px solid #d9e0e8;border-radius:12px;padding:18px;margin-top:16px;box-shadow:0 2px 8px rgba(30,45,70,.05)}.alert{border-color:#e8b04a;background:#fff8e8;font-weight:700;color:#7a4c00}.readonly{display:inline-block;margin-left:8px;padding:3px 8px;border-radius:999px;background:#173b6c;color:#fff;font-size:12px}.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:12px;align-items:end}.field label{display:block;font-weight:700;margin-bottom:6px}.field input,.field select{box-sizing:border-box;width:100%;height:40px;border:1px solid #bbc6d3;border-radius:7px;padding:0 10px;background:#fff}.button{height:40px;border:0;border-radius:7px;padding:0 18px;background:#173b6c;color:#fff;font-weight:700;cursor:pointer}.card h2{margin:0 0 14px;font-size:20px}.card h3{margin:18px 0 10px;font-size:16px}.meta{color:#5b6779;font-size:13px}.scroll{overflow:auto;border:1px solid #d9e0e8;border-radius:8px}table{border-collapse:collapse;width:100%;min-width:1200px}th,td{padding:9px 10px;border-right:1px solid #e2e7ed;border-bottom:1px solid #e2e7ed;text-align:left;vertical-align:top;white-space:nowrap}th{position:sticky;top:0;background:#edf2f7;color:#324158}.empty{padding:24px;text-align:center;color:#6b7280}.timeline{list-style:none;padding:0;margin:0}.timeline li{display:grid;grid-template-columns:190px 1fr;gap:16px;padding:10px 0;border-bottom:1px solid #edf0f4}.timeline time{font-weight:700;color:#334d72}.timeline strong{display:block}.big-ok{padding:18px;border-radius:8px;background:#eaf7ef;color:#176038;font-size:18px;font-weight:800}.facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.fact{border:1px solid #e0e5eb;border-radius:8px;padding:14px;background:#fafbfd}.fact b{display:block;margin-bottom:7px;color:#40506a}.value{font-size:22px;font-weight:800;color:#173b6c}.codebox{background:#101827;color:#e6edf7;border-radius:8px;padding:14px;line-height:1.7;overflow:auto}.codebox a{color:#8fc5ff}.verdict h2{font-size:24px;margin:0 0 12px}.verdict.danger{border:2px solid #c93636;background:#fff5f5}.verdict.warn{border:2px solid #df8a1d;background:#fff9ef}.verdict.neutral{border:2px solid #7a8798}.verdict ul{margin-bottom:0}.error{background:#fff1f0;border:1px solid #efaca6;color:#9d1c14;padding:10px;border-radius:7px;margin-top:8px}.note{padding:10px 12px;border-left:4px solid #7688a3;background:#f2f5f9}.missing{color:#8a4b00}@media(max-width:900px){.wrap{padding:16px}.filters,.facts{grid-template-columns:1fr}.timeline li{grid-template-columns:1fr;gap:4px}.head{align-items:flex-start;flex-direction:column}}
  </style>
</head>
<body><div class="wrap">
  <div class="head"><h1>공수 데이터 원인 검증 <span class="readonly">SELECT ONLY</span></h1><a class="back" href="?r=대시보드">대시보드로 돌아가기</a></div>
  <div class="alert">읽기 전용 조사 화면입니다. 이 화면에서는 어떠한 데이터도 수정하지 않습니다.</div>

  <form class="card filters" method="get" action="">
    <input type="hidden" name="r" value="admin/labor_forensic_check">
    <div class="field"><label for="project_id">현장 선택</label><select id="project_id" name="project_id" required><option value="">현장을 선택하세요</option><?php foreach ($projects as $projectOption): ?><option value="<?php echo (int)$projectOption['id']; ?>"<?php echo (int)$projectOption['id'] === $selectedProjectId ? ' selected' : ''; ?>><?php echo cpms_forensic_h($projectOption['name']); ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="month">대상 월</label><input id="month" name="month" type="month" value="<?php echo cpms_forensic_h($month); ?>" required></div>
    <div class="field"><label for="worker_name">근로자 이름</label><input id="worker_name" name="worker_name" value="<?php echo cpms_forensic_h($workerName); ?>" required></div>
    <div class="field"><label for="work_date">작업일</label><input id="work_date" name="work_date" type="date" value="<?php echo cpms_forensic_h($workDate); ?>" required></div>
    <button class="button" type="submit">조회</button>
  </form>
  <?php foreach ($errors as $error): ?><div class="error"><?php echo cpms_forensic_h($error); ?></div><?php endforeach; ?>

  <section class="card"><h2>1. 원본 공수 변경이력</h2>
    <p class="meta">현장 #<?php echo (int)$selectedProjectId; ?> · <?php echo cpms_forensic_h($selectedProject ? $selectedProject['name'] : '선택 안 됨'); ?> · <?php echo cpms_forensic_h($workerName); ?> · <?php echo cpms_forensic_h($workDate); ?> / DB에 실제 존재하는 컬럼만 표시</p>
    <?php if (count($rawRows) === 0): ?><div class="empty">조건에 일치하는 변경이력이 없습니다.</div><?php else: ?><div class="scroll"><table><thead><tr><?php foreach ($availableDisplayColumns as $column): ?><th><?php echo cpms_forensic_h($column); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($rawRows as $row): ?><tr><?php foreach ($availableDisplayColumns as $column): ?><td><?php echo cpms_forensic_h(cpms_forensic_raw_value($row, $column)); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php if (count($missingColumns) > 0): ?><p class="meta missing">DB에 없어 제외된 컬럼: <?php echo cpms_forensic_h(implode(', ', $missingColumns)); ?></p><?php endif; ?>
  </section>

  <section class="card"><h2>2. 사건 타임라인</h2>
    <?php if (count($timeline) === 0): ?><div class="empty">자동 정리할 이력이 없습니다.</div><?php else: ?><ol class="timeline"><?php foreach ($timeline as $event): ?><li><time><?php echo cpms_forensic_h($event['time']); ?></time><div><strong><?php echo cpms_forensic_h($event['text']); ?></strong><span class="meta"><?php echo cpms_forensic_h($event['detail']); ?></span></div></li><?php endforeach; ?></ol><?php endif; ?>
    <h3>2026-07-23 09:35:09 ~ 2026-08-05 23:59:59 동일 현장/근로자/작업일 추가 변경 확인</h3>
    <?php if (count($rawRows) === 0): ?><div class="empty">추가 변경 여부를 확인할 보존 행이 없습니다.</div><?php elseif (count($additionalEvents) === 0): ?><div class="big-ok">7월 23일 반려 이후 8월 5일까지 추가 공수 변경 기록 없음</div><?php else: ?><ul><?php foreach ($additionalEvents as $additionalEvent): ?><li><?php echo cpms_forensic_h($additionalEvent); ?></li><?php endforeach; ?></ul><?php endif; ?>
  </section>

  <section class="card"><h2>3. 현재 공수 계산 근거</h2><div class="facts">
    <div class="fact"><b>출퇴근 원본</b><div>출근시간: <?php echo cpms_forensic_h($attendanceStart !== '' ? $attendanceStart : '없음'); ?></div><div>퇴근시간: <?php echo cpms_forensic_h($attendanceEnd !== '' ? $attendanceEnd : '없음'); ?></div><div>출퇴근 기반 공수: <strong><?php echo cpms_forensic_h(cpms_forensic_number($attendanceValue)); ?></strong></div><?php if ($attendanceError !== ''): ?><div class="meta">조회 오류: <?php echo cpms_forensic_h($attendanceError); ?></div><?php endif; ?></div>
    <div class="fact"><b>공수 조정 데이터</b><?php if (is_array($currentOverride)): ?><div>적용 근거: <?php echo cpms_forensic_h($currentOverride['source']); ?></div><div>적용값: <strong><?php echo cpms_forensic_h(cpms_forensic_number($currentOverride['value'])); ?></strong></div><div>삭제 표시: <?php echo !empty($currentOverride['is_deleted']) ? '예' : '아니오'; ?></div><?php else: ?><div>현재 적용할 조정 데이터 없음</div><?php endif; ?></div>
    <div class="fact"><b>현재 CPMS 로직 기준</b><div class="value">최종 표시 공수 = <?php echo cpms_forensic_h(cpms_forensic_number($currentFinalValue)); ?></div></div>
  </div></section>

  <section class="card"><h2>4. 2026-08-05 실제 코드 기준 재현</h2>
    <div class="codebox">GitHub 기준 커밋: <a href="<?php echo cpms_forensic_h($githubBase . '/commit/' . $historicalCommit); ?>" target="_blank" rel="noopener"><?php echo cpms_forensic_h($historicalCommit); ?></a> (2026-08-05 18:22:12 +0900)<br>당시 cpms_load_labor_overrides: status IN ('applied','approved')만 SELECT<br>당시 공수 화면/엑셀: cpms_apply_labor_overrides_to_dataset의 동일한 공수 맵 적용<br>old_value 복원 추가 커밋: <a href="<?php echo cpms_forensic_h($githubBase . '/commit/' . $restorationCommit); ?>" target="_blank" rel="noopener"><?php echo cpms_forensic_h($restorationCommit); ?></a> (2026-08-12 13:22:30 +0900)</div>
    <div class="facts" style="margin-top:12px">
      <div class="fact"><b>DB 상태</b><div>status = <?php echo cpms_forensic_h($rejectedRow && isset($rejectedRow['status']) ? $rejectedRow['status'] : '확인 불가'); ?></div><div>old_value = <?php echo cpms_forensic_h($rejectedRow ? cpms_forensic_number(isset($rejectedRow['old_value']) ? $rejectedRow['old_value'] : null) : '확인 불가'); ?></div><div>new_value = <?php echo cpms_forensic_h($rejectedRow ? cpms_forensic_number(isset($rejectedRow['new_value']) ? $rejectedRow['new_value'] : null) : '확인 불가'); ?></div></div>
      <div class="fact"><b>2026-08-05 로직</b><div>인정 상태: applied / approved</div><div class="value">당시 화면 계산 결과: <?php echo cpms_forensic_h(cpms_forensic_number($historicalFinalValue)); ?></div></div>
      <div class="fact"><b>현재 로직</b><div>rejected 등의 old_value 복원</div><div class="value">현재 화면 계산 결과: <?php echo cpms_forensic_h(cpms_forensic_number($currentFinalValue)); ?></div></div>
    </div>
    <p class="note">당시 계산값은 2026-08-05 코드의 상태 필터와 현재 보존 DB 행을 적용한 재현값입니다. 당시 DB 스냅샷이 아니므로 현재 보존되지 않은 과거 변경은 확인할 수 없습니다.</p>
  </section>

  <section class="verdict <?php echo cpms_forensic_h($judgmentClass); ?>"><h2><?php echo cpms_forensic_h($judgmentTitle); ?></h2><ul><?php foreach ($judgmentReasons as $reason): ?><li><?php echo cpms_forensic_h($reason); ?></li><?php endforeach; ?></ul></section>
</div></body></html>
