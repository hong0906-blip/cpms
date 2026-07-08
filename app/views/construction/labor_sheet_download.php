<?php
/**
 * - 공사: 노무비 공수 다운로드(GET)
 * - 프로젝트/월 기준 엑셀용 HTML 다운로드
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$projectId = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
$selectedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
$laborSort = isset($_GET['labor_sort']) ? trim((string)$_GET['labor_sort']) : 'name';
$laborSortAllowed = array('name', 'job_type', 'output_days', 'total_gongsu', 'wage_rate', 'company');
if (!in_array($laborSort, $laborSortAllowed, true)) $laborSort = 'name';
$laborSortDir = isset($_GET['labor_sort_dir']) ? trim((string)$_GET['labor_sort_dir']) : 'asc';
if ($laborSortDir !== 'desc') $laborSortDir = 'asc';

if ($projectId <= 0 || $selectedMonth === '') {
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

$role = Auth::userRole();
$dept = Auth::userDepartment();

// 공사/임원만 허용
if (!Auth::canManageConstruction()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$pdo = Db::pdo();
if (!$pdo) { http_response_code(500); echo 'DB Error'; exit; }

// 프로젝트 조회
try {
    $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $projectId, PDO::PARAM_INT);
    $st->execute();
    $projectRow = $st->fetch();
} catch (Exception $e) {
    $projectRow = false;
}

if (!$projectRow) { http_response_code(404); echo 'Not Found'; exit; }

// 공사 담당(현장) 조회
$siteName = '';
try {
    $stR = $pdo->prepare("SELECT site_manager_id FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
    $stR->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $stR->execute();
    $siteId = (int)$stR->fetchColumn();
    if ($siteId > 0) {
        $stN = $pdo->prepare("SELECT name FROM employees WHERE id = :id LIMIT 1");
        $stN->bindValue(':id', $siteId, \PDO::PARAM_INT);
        $stN->execute();
        $siteName = (string)$stN->fetchColumn();
    }
} catch (Exception $e) {
    $siteName = '';
}

// 월 범위 계산
$periodStart = $selectedMonth . '-01';
try {
    $periodEndObj = new DateTime($periodStart);
    $periodEndObj->modify('last day of this month');
    $periodEnd = $periodEndObj->format('Y-m-d');
} catch (Exception $e) {
    $periodEnd = $periodStart;
}

$fileName = '노무비_공수_' . $selectedMonth . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

?>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo h($fileName); ?></title>
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 4px; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
<?php
require_once __DIR__ . '/tabs/partials/labor_data_loader.php';
$directTeamMembers = cpms_load_direct_team_members($pdo);
$gongsuData = cpms_load_gongsu_data($pdo, isset($projectRow['name']) ? $projectRow['name'] : '', $selectedMonth);
$attendanceWorkers = isset($gongsuData['all_workers']) ? $gongsuData['all_workers'] : (isset($gongsuData['workers']) ? $gongsuData['workers'] : array());
$excludedWorkers = isset($gongsuData['excluded_workers']) ? $gongsuData['excluded_workers'] : array();
$attendanceGongsuMap = isset($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
$attendanceGongsuUnit = isset($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array();
$attendanceOutputDays = isset($gongsuData['output_days']) ? $gongsuData['output_days'] : array();
$projectId = isset($projectId) ? (int)$projectId : 0;
$overrideDataset = function_exists('cpms_apply_labor_overrides_to_dataset')
    ? cpms_apply_labor_overrides_to_dataset($attendanceGongsuMap, $attendanceOutputDays, $attendanceGongsuUnit, $projectId, $selectedMonth)
    : array(
        'gongsu_map' => $attendanceGongsuMap,
        'output_days' => $attendanceOutputDays,
        'gongsu_unit' => $attendanceGongsuUnit,
    );
$attendanceGongsuMap = isset($overrideDataset['gongsu_map']) && is_array($overrideDataset['gongsu_map']) ? $overrideDataset['gongsu_map'] : array();
$attendanceOutputDays = isset($overrideDataset['output_days']) && is_array($overrideDataset['output_days']) ? $overrideDataset['output_days'] : array();
$attendanceGongsuUnit = isset($overrideDataset['gongsu_unit']) && is_array($overrideDataset['gongsu_unit']) ? $overrideDataset['gongsu_unit'] : array();
cpms_cleanup_project_labor_workers($pdo, $projectId, $excludedWorkers); // 장비기사 기존 기록 삭제(soft delete)
cpms_sync_project_labor_workers_from_attendance($pdo, $projectId, $attendanceWorkers); // 장비기사 제외
$projectLaborWorkers = cpms_load_project_labor_workers($pdo, $projectId);
$workerRows = cpms_build_project_worker_rows($projectLaborWorkers, $directTeamMembers);
$laborWorkerMonthMap = function_exists('cpms_load_project_labor_worker_month_map') ? cpms_load_project_labor_worker_month_map($pdo, $projectId, $selectedMonth) : array();
if (is_array($workerRows) && is_array($laborWorkerMonthMap) && count($laborWorkerMonthMap) > 0) {
    foreach ($workerRows as $workerRowIndex => $workerRow) {
        $laborWorkerId = isset($workerRow['id']) ? (int)$workerRow['id'] : 0;
        $isMonthAssigned = ($laborWorkerId > 0 && isset($laborWorkerMonthMap[$laborWorkerId])) ? 1 : 0;
        $workerRows[$workerRowIndex]['month_assigned'] = $isMonthAssigned;
        if (isset($workerRows[$workerRowIndex]['data']) && is_array($workerRows[$workerRowIndex]['data'])) {
            $workerRows[$workerRowIndex]['data']['month_assigned'] = $isMonthAssigned;
        }
    }
}
$timesheetWorkers = cpms_build_timesheet_workers($workerRows);
// 공수 월별 출력일수 필터(월별 only): 선택 월 output_days > 0 인 사람만 다운로드 표에 포함
if (is_array($timesheetWorkers)) {
    $filteredTimesheetWorkers = array();
    foreach ($timesheetWorkers as $worker) {
        $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
        $workerKey = cpms_normalize_worker_key($workerName);
        if ($workerKey === '') continue;
        $workerOutputDays = isset($attendanceOutputDays[$workerKey]) ? (int)$attendanceOutputDays[$workerKey] : 0;
        $isMonthAssigned = (isset($worker['month_assigned']) && (int)$worker['month_assigned'] === 1);
        if ($workerOutputDays <= 0 && !$isMonthAssigned) continue;
        $filteredTimesheetWorkers[] = $worker;
    }
    $timesheetWorkers = $filteredTimesheetWorkers;
}
if (function_exists('cpms_sort_labor_workers')) {
    $timesheetWorkers = cpms_sort_labor_workers($timesheetWorkers, $laborSort, $laborSortDir, $attendanceGongsuMap, $attendanceOutputDays, $selectedMonth);
}
$timesheetRows = count($timesheetWorkers);
if ($timesheetRows < 1) $timesheetRows = 1;
$laborSheetDownloadMode = true;
require __DIR__ . '/tabs/partials/labor_sheet_table.php';
?>
</body>
</html>
