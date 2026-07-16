<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../attendance/common.php';
@date_default_timezone_set('Asia/Seoul');
attendance_timezone();

$attendanceEmbeddedInExecutiveDashboard = !empty($cpmsAttendanceEmbeddedInExecutiveDashboard);
$canManageAttendance = (Auth::isMaster() || attendance_is_manager());
$canViewAttendanceDashboard = ($canManageAttendance || ($attendanceEmbeddedInExecutiveDashboard && Auth::userRole() === 'executive'));
if (!$canViewAttendanceDashboard) {
    echo attendance_text('%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
    return;
}

$routeManage = $attendanceEmbeddedInExecutiveDashboard ? '?r=dashboard_executive&exec_tab=attendanceManagement' : ('?r=' . attendance_text('%EA%B4%80%EB%A6%AC'));
$attendanceRouteName = $attendanceEmbeddedInExecutiveDashboard ? 'dashboard_executive' : attendance_text('%EA%B4%80%EB%A6%AC');

$pdo = Db::pdo();
$canViewAttendanceSettings = attendance_can_manage_settings($pdo);
$canEditAttendanceCells = attendance_can_edit_monthly_records($pdo);
$canViewAttendanceRequests = attendance_can_manage_requests($pdo);
$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
$tab = isset($_GET['atab']) ? (string)$_GET['atab'] : 'monthly';
if ($tab === 'settings' && !$canViewAttendanceSettings) $tab = 'monthly';
if ($tab === 'requests' && !$canViewAttendanceRequests) $tab = 'monthly';
$month = isset($_GET['month']) ? trim((string)$_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$quickFilter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : 'all';
if (!in_array($quickFilter, array('all', 'late', 'vacation', 'missing_checkout'), true)) $quickFilter = 'all';
$sort = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'position';
if (!in_array($sort, array('department', 'name', 'position'), true)) $sort = 'position';
$requestStatusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
if (!in_array($requestStatusFilter, array('all', 'pending', 'approved', 'rejected'), true)) $requestStatusFilter = 'all';
$requestDateFilter = isset($_GET['request_date']) ? trim((string)$_GET['request_date']) : $date;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestDateFilter)) $requestDateFilter = date('Y-m-d');
$settings = attendance_settings($pdo);
$geofence = attendance_geofence_settings($pdo);
$attendanceMonthlyToday = attendance_today();
$attendanceMonthlyNow = attendance_now();
$attendanceMonthlyNowTime = strlen($attendanceMonthlyNow) >= 19 ? substr($attendanceMonthlyNow, 11, 8) : date('H:i:s');
$attendanceMissingCheckoutCutoff = '18:00:00';
$attendanceWeeklyLimitMinutes = 52 * 60;
$monthStart = $month . '-01';
$monthStartTs = strtotime($monthStart);
if ($monthStartTs === false) {
    $month = date('Y-m');
    $monthStart = $month . '-01';
    $monthStartTs = strtotime($monthStart);
}
$monthEnd = date('Y-m-t', $monthStartTs);
$weekStartParam = isset($_GET['week_start']) ? trim((string)$_GET['week_start']) : '';
$weeklySelection = attendance_month_week_selection($month, $weekStartParam, $attendanceMonthlyToday);
$weekOptions = isset($weeklySelection['options']) && is_array($weeklySelection['options']) ? $weeklySelection['options'] : array();
$ws = isset($weeklySelection['start']) ? (string)$weeklySelection['start'] : $attendanceMonthlyToday;
$we = isset($weeklySelection['end']) ? (string)$weeklySelection['end'] : $attendanceMonthlyToday;
$weekLabel = isset($weeklySelection['label']) ? (string)$weeklySelection['label'] : '';
$weekRangeLabel = isset($weeklySelection['range_label']) ? (string)$weeklySelection['range_label'] : ($ws . ' ~ ' . $we);
$reportStart = $tab === 'weekly' ? $ws : $monthStart;
$reportEnd = $tab === 'weekly' ? $we : $monthEnd;
$reportStatusDate = $attendanceMonthlyToday;
if ($reportStatusDate < $reportStart) $reportStatusDate = $reportStart;
if ($reportStatusDate > $reportEnd) $reportStatusDate = $reportEnd;
if ($tab === 'weekly') {
    $reportStatusTs = strtotime($reportStatusDate);
    $reportStatusWeekday = $reportStatusTs !== false ? (int)date('N', $reportStatusTs) : 1;
    if ($reportStatusWeekday >= 6) {
        $reportStatusDate = date('Y-m-d', strtotime('-' . ($reportStatusWeekday - 5) . ' day', $reportStatusTs));
    }
}
$reportDayCount = 0;

$daily = array();
$reqs = array();
$emps = array();
$monthlyRecordMap = array();
$monthlyLeaveMap = array();
$monthlyRows = array();
$monthlyRowsAll = array();
$monthDates = array();
$monthlySummary = array(
    'total' => 0,
    'normal' => 0,
    'late' => 0,
    'vacation' => 0,
    'missing_checkout' => 0
);
$projectOptions = array();
$geofenceLocations = attendance_geofence_locations($pdo, true);
$editGeofenceId = isset($_GET['edit_geofence_id']) ? (int)$_GET['edit_geofence_id'] : 0;
$editGeofence = null;
$attendanceErrors = array();

if (!function_exists('cpms_column_exists')) {
    function cpms_column_exists($pdo, $table, $column)
    {
        try {
            $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($db === '') {
                return false;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
            $st->execute(array(':db' => $db, ':tbl' => $table, ':col' => $column));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('attendance_request_status_label')) {
    function attendance_request_status_label($status)
    {
        if ($status === 'pending') return attendance_text('%EC%8A%B9%EC%9D%B8%EB%8C%80%EA%B8%B0');
        if ($status === 'approved') return attendance_text('%EC%8A%B9%EC%9D%B8%EC%99%84%EB%A3%8C');
        if ($status === 'rejected') return attendance_text('%EB%B0%98%EB%A0%A4');
        return (string)$status;
    }
}

if (!function_exists('attendance_request_status_class')) {
    function attendance_request_status_class($status)
    {
        if ($status === 'pending') return 'bg-amber-50 text-amber-700 border-amber-200';
        if ($status === 'approved') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if ($status === 'rejected') return 'bg-rose-50 text-rose-700 border-rose-200';
        return 'bg-gray-50 text-gray-700 border-gray-200';
    }
}

if (!function_exists('attendance_request_type_label')) {
    function attendance_request_type_label($type)
    {
        if ($type === 'check_in') return attendance_text('%EC%B6%9C%EA%B7%BC%EC%8B%9C%EA%B0%84%20%EC%88%98%EC%A0%95');
        if ($type === 'check_out') return attendance_text('%ED%87%B4%EA%B7%BC%EC%8B%9C%EA%B0%84%20%EC%88%98%EC%A0%95');
        if ($type === 'both') return attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%88%98%EC%A0%95');
        return (string)$type;
    }
}

if (!function_exists('attendance_monthly_time')) {
    function attendance_monthly_time($value)
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (strlen($value) >= 16) return substr($value, 11, 5);
        return $value;
    }
}

if (!function_exists('attendance_monthly_is_late')) {
    function attendance_monthly_is_late($checkIn, $position)
    {
        return attendance_is_late_check_in_value($checkIn, $position);
    }
}

if (!function_exists('attendance_monthly_is_missing_checkout')) {
    function attendance_monthly_is_missing_checkout($workDate, $checkIn, $checkOut, $today, $nowTime, $cutoffTime)
    {
        $workDate = trim((string)$workDate);
        $checkIn = trim((string)$checkIn);
        $checkOut = trim((string)$checkOut);
        if ($workDate === '' || $checkIn === '' || $checkOut !== '') return false;
        if ($workDate < $today) return true;
        if ($workDate === $today && strcmp($nowTime, $cutoffTime) >= 0) return true;
        return false;
    }
}

if (!function_exists('attendance_monthly_date_value')) {
    function attendance_monthly_date_value($value)
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        $ts = strtotime($value);
        if ($ts === false) return '';
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('attendance_monthly_add_leave_day')) {
    function attendance_monthly_add_leave_day(&$map, $employeeId, $date, $label)
    {
        $employeeId = (int)$employeeId;
        $date = attendance_monthly_date_value($date);
        if ($employeeId <= 0 || $date === '') return;
        if (!isset($map[$employeeId])) $map[$employeeId] = array();
        if (!isset($map[$employeeId][$date])) {
            $map[$employeeId][$date] = array(
                'label' => trim((string)$label) !== '' ? trim((string)$label) : attendance_text('%ED%9C%B4%EA%B0%80')
            );
        }
    }
}

if (!function_exists('attendance_monthly_leave_half_label')) {
    function attendance_monthly_leave_half_label($label)
    {
        $label = trim((string)$label);
        if ($label === '') return '';
        $compact = str_replace(array(' ', "\t", "\r", "\n"), '', $label);
        $half = attendance_text('%EB%B0%98%EC%B0%A8');
        if (strpos($compact, $half) === false) return '';
        if (strpos($compact, attendance_text('%EC%98%A4%EC%A0%84')) !== false) return attendance_text('%EC%98%A4%EC%A0%84%EB%B0%98%EC%B0%A8');
        if (strpos($compact, attendance_text('%EC%98%A4%ED%9B%84')) !== false) return attendance_text('%EC%98%A4%ED%9B%84%EB%B0%98%EC%B0%A8');
        return $half;
    }
}

if (!function_exists('attendance_monthly_approval_leave_label')) {
    function attendance_monthly_approval_leave_label($content)
    {
        if (!is_array($content)) return attendance_text('%ED%9C%B4%EA%B0%80');
        $type = isset($content['request_type']) ? trim((string)$content['request_type']) : '';
        $typeEtc = isset($content['request_type_etc']) ? trim((string)$content['request_type_etc']) : '';
        if ($typeEtc !== '' && $type === attendance_text('%EA%B8%B0%ED%83%80')) return $typeEtc;
        $daysRaw = isset($content['leave_days']) ? trim((string)$content['leave_days']) : '';
        $daysRaw = str_replace(',', '', $daysRaw);
        if ($type !== '' && attendance_monthly_leave_half_label($type) === '' && $daysRaw !== '' && is_numeric($daysRaw) && (float)$daysRaw <= 0.5) {
            return attendance_text('%EB%B0%98%EC%B0%A8');
        }
        return $type !== '' ? $type : attendance_text('%ED%9C%B4%EA%B0%80');
    }
}

if (!function_exists('attendance_monthly_percent')) {
    function attendance_monthly_percent($value, $total)
    {
        $total = (int)$total;
        if ($total <= 0) return '0%';
        return number_format(((float)$value / (float)$total) * 100, 1) . '%';
    }
}

$positionEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'position') : false;
$hireDateEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'hire_date') : false;
$photoPathEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'photo_path') : false;
$employeeNoEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'employee_no') : false;
$workLocationEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'work_location') : false;
$isActiveEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'is_active') : false;
$reviewedByEnabled = $pdo ? cpms_column_exists($pdo, 'cpms_attendance_requests', 'reviewed_by') : false;

if ($pdo) {
    $posSel = $positionEnabled ? 'position' : "'' AS position";
    $hireSel = $hireDateEnabled ? 'hire_date' : 'NULL AS hire_date';
    $photoSel = $photoPathEnabled ? 'photo_path' : "'' AS photo_path";
    $employeeNoSel = $employeeNoEnabled ? 'employee_no' : "'' AS employee_no";
    $workLocationSel = $workLocationEnabled ? 'work_location' : "'' AS work_location";
    $activeSel = $isActiveEnabled ? 'is_active' : '1 AS is_active';
    $employeeWhere = array();
    if ($isActiveEnabled) {
        $employeeWhere[] = "(is_active IS NULL OR is_active = 1)";
    }
    $employeeOrder = "position ASC, " . ($hireDateEnabled ? "CASE WHEN hire_date IS NULL OR CAST(hire_date AS CHAR) = '' THEN 1 ELSE 0 END ASC, hire_date ASC, " : "") . "name ASC, id ASC";
    if ($sort === 'name') {
        $employeeOrder = "name ASC, id ASC";
    } else if ($sort === 'department') {
        $employeeOrder = "department ASC, name ASC, id ASC";
    }

    try {
        $employeeSql = "SELECT id,email,name,department," . $posSel . "," . $hireSel . "," . $photoSel . "," . $employeeNoSel . "," . $workLocationSel . "," . $activeSel . " FROM employees";
        if (count($employeeWhere) > 0) {
            $employeeSql .= " WHERE " . implode(" AND ", $employeeWhere);
        }
        $employeeSql .= " ORDER BY " . $employeeOrder;
        $emps = $pdo->query($employeeSql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($emps)) $emps = array();
        $emps = attendance_filter_representative_rows($emps);
        if ($sort === 'position' && function_exists('attendance_compare_employee_position')) {
            usort($emps, 'attendance_compare_employee_position');
        }
    } catch (Exception $e) {
        $attendanceErrors[] = attendance_text('%EC%A7%81%EC%9B%90%20%EB%AA%A9%EB%A1%9D%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.') . ' ' . $e->getMessage();
    }

    try {
        $projectSql = "SELECT id, name FROM cpms_projects";
        $projectWhere = array("name NOT LIKE '(가제)%'");
        if (cpms_column_exists($pdo, 'cpms_projects', 'is_deleted')) {
            $projectWhere[] = "is_deleted = 0";
        }
        $projectSql .= " WHERE " . implode(" AND ", $projectWhere);
        $projectSql .= " ORDER BY name ASC";
        $projectOptions = $pdo->query($projectSql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($projectOptions)) $projectOptions = array();
    } catch (Exception $e) {
        $projectOptions = array();
    }

    try {
        $dailyActiveWhere = $isActiveEnabled ? " AND (e.is_active IS NULL OR e.is_active = 1)" : "";
        $st = $pdo->prepare("SELECT e.name,e.department," . ($positionEnabled ? 'e.position' : "'' AS position") . ",a.* FROM cpms_attendance_records a JOIN employees e ON e.id=a.employee_id WHERE a.work_date=:d" . $dailyActiveWhere . " ORDER BY e.name ASC");
        $st->execute(array(':d' => $date));
        $daily = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($daily)) $daily = array();
        $daily = attendance_filter_representative_rows($daily);
    } catch (Exception $e) {
        $attendanceErrors[] = attendance_text('%EC%9D%BC%EC%9D%BC%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%98%84%ED%99%A9%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.') . ' ' . $e->getMessage();
    }

    if ($tab === 'requests') {
        try {
            $selectReviewer = $reviewedByEnabled ? ', reviewer.name AS reviewer_name' : ", '' AS reviewer_name";
            $joinReviewer = $reviewedByEnabled ? ' LEFT JOIN employees reviewer ON reviewer.id = r.reviewed_by' : '';
            $where = array();
            $params = array();
            if ($requestStatusFilter === 'all') {
                $where[] = "r.status = 'pending'";
            } else if ($requestStatusFilter === 'pending') {
                $where[] = "r.status = 'pending'";
            } else {
                $where[] = 'r.request_date = :request_date';
                $params[':request_date'] = $requestDateFilter;
                $where[] = 'r.status = :status';
                $params[':status'] = $requestStatusFilter;
            }
            $representativeNeedle = '%' . attendance_text('%EB%8C%80%ED%91%9C') . '%';
            if ($positionEnabled) {
                $where[] = "(COALESCE(e.position, '') NOT LIKE :rep_pos AND COALESCE(e.name, '') NOT LIKE :rep_name)";
                $params[':rep_pos'] = $representativeNeedle;
                $params[':rep_name'] = $representativeNeedle;
            } else {
                $where[] = "COALESCE(e.name, '') NOT LIKE :rep_name";
                $params[':rep_name'] = $representativeNeedle;
            }
            $sql = "SELECT r.*, e.name, e.department, " . ($positionEnabled ? 'e.position' : "'' AS position") . $selectReviewer . "
                    FROM cpms_attendance_requests r
                    JOIN employees e ON e.id = r.employee_id
                    " . $joinReviewer . "
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY r.id DESC
                    LIMIT 200";
            $stReq = $pdo->prepare($sql);
            $stReq->execute($params);
            $reqs = $stReq->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($reqs)) $reqs = array();
            $reqs = attendance_filter_representative_rows($reqs);
        } catch (Exception $e) {
            $attendanceErrors[] = attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%9A%94%EC%B2%AD%20%EB%AA%A9%EB%A1%9D%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.') . ' ' . $e->getMessage();
        }
    }

    if ($tab === 'monthly' || $tab === 'weekly') {
        $reportCursorTs = strtotime($reportStart);
        $reportEndTs = strtotime($reportEnd);
        while ($reportCursorTs !== false && $reportEndTs !== false && $reportCursorTs <= $reportEndTs) {
            $dateKey = date('Y-m-d', $reportCursorTs);
            $dayNo = (int)date('j', $reportCursorTs);
            $weekIndex = (int)date('w', $reportCursorTs);
            $weekLabels = array(
                attendance_text('%EC%9D%BC'),
                attendance_text('%EC%9B%94'),
                attendance_text('%ED%99%94'),
                attendance_text('%EC%88%98'),
                attendance_text('%EB%AA%A9'),
                attendance_text('%EA%B8%88'),
                attendance_text('%ED%86%A0')
            );
            $monthDates[$dateKey] = array(
                'date' => $dateKey,
                'day' => $dayNo,
                'month' => (int)date('n', $reportCursorTs),
                'week' => isset($weekLabels[$weekIndex]) ? $weekLabels[$weekIndex] : '',
                'weekend' => ($weekIndex === 0 || $weekIndex === 6)
            );
            $reportDayCount++;
            $reportCursorTs = strtotime('+1 day', $reportCursorTs);
        }

        try {
            $stMonth = $pdo->prepare("SELECT * FROM cpms_attendance_records WHERE work_date BETWEEN :s AND :e ORDER BY work_date ASC, employee_id ASC");
            $stMonth->execute(array(':s' => $reportStart, ':e' => $reportEnd));
            $monthRecords = $stMonth->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($monthRecords)) $monthRecords = array();
            foreach ($monthRecords as $recordRow) {
                $employeeId = isset($recordRow['employee_id']) ? (int)$recordRow['employee_id'] : 0;
                $workDate = isset($recordRow['work_date']) ? (string)$recordRow['work_date'] : '';
                if ($employeeId <= 0 || $workDate === '') continue;
                if (!isset($monthlyRecordMap[$employeeId])) $monthlyRecordMap[$employeeId] = array();
                $monthlyRecordMap[$employeeId][$workDate] = $recordRow;
            }
        } catch (Exception $e) {
            $loadErrorLabel = $tab === 'weekly'
                ? attendance_text('%EC%A3%BC%EA%B0%84%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%98%84%ED%99%A9%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.')
                : attendance_text('%EC%9B%94%EA%B0%84%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%98%84%ED%99%A9%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            $attendanceErrors[] = $loadErrorLabel . ' ' . $e->getMessage();
        }

        if (attendance_table_exists($pdo, 'cpms_leave_records')) {
            try {
                $stLeave = $pdo->prepare("SELECT employee_id, leave_date, leave_type, leave_amount FROM cpms_leave_records WHERE leave_date BETWEEN :s AND :e ORDER BY leave_date ASC, employee_id ASC");
                $stLeave->execute(array(':s' => $reportStart, ':e' => $reportEnd));
                $leaveRows = $stLeave->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($leaveRows)) $leaveRows = array();
                foreach ($leaveRows as $leaveRow) {
                    $leaveTypeLabel = isset($leaveRow['leave_type']) ? trim((string)$leaveRow['leave_type']) : '';
                    $leaveAmount = isset($leaveRow['leave_amount']) ? (float)$leaveRow['leave_amount'] : 0.0;
                    if ($leaveAmount > 0 && $leaveAmount <= 0.5 && attendance_monthly_leave_half_label($leaveTypeLabel) === '') {
                        $leaveTypeLabel = attendance_text('%EB%B0%98%EC%B0%A8');
                    }
                    attendance_monthly_add_leave_day(
                        $monthlyLeaveMap,
                        isset($leaveRow['employee_id']) ? (int)$leaveRow['employee_id'] : 0,
                        isset($leaveRow['leave_date']) ? $leaveRow['leave_date'] : '',
                        $leaveTypeLabel
                    );
                }
            } catch (Exception $e) {
            }
        }

        if (attendance_table_exists($pdo, 'cpms_approval_documents')) {
            try {
                $approvalEmployeeIdSelect = cpms_column_exists($pdo, 'cpms_approval_documents', 'created_by_id') ? 'created_by_id' : '0 AS created_by_id';
                $approvalContentSelect = cpms_column_exists($pdo, 'cpms_approval_documents', 'content') ? 'content' : "'' AS content";
                $stApprovalLeave = $pdo->query("SELECT " . $approvalEmployeeIdSelect . ", " . $approvalContentSelect . " FROM cpms_approval_documents WHERE doc_type='leave' AND UPPER(COALESCE(doc_status,'')) IN ('APPROVED','COMPLETED') ORDER BY id DESC");
                $approvalLeaveRows = $stApprovalLeave ? $stApprovalLeave->fetchAll(PDO::FETCH_ASSOC) : array();
                if (!is_array($approvalLeaveRows)) $approvalLeaveRows = array();
                foreach ($approvalLeaveRows as $approvalLeaveRow) {
                    $employeeId = isset($approvalLeaveRow['created_by_id']) ? (int)$approvalLeaveRow['created_by_id'] : 0;
                    if ($employeeId <= 0) continue;
                    $content = array();
                    $rawContent = isset($approvalLeaveRow['content']) ? trim((string)$approvalLeaveRow['content']) : '';
                    if ($rawContent !== '') {
                        $decodedContent = json_decode($rawContent, true);
                        if (is_array($decodedContent)) $content = $decodedContent;
                    }
                    $leaveStart = attendance_monthly_date_value(isset($content['leave_start_date']) ? $content['leave_start_date'] : '');
                    $leaveEnd = attendance_monthly_date_value(isset($content['leave_end_date']) ? $content['leave_end_date'] : '');
                    if ($leaveStart === '' || $leaveEnd === '') continue;
                    if ($leaveEnd < $reportStart || $leaveStart > $reportEnd) continue;
                    $cursorTs = strtotime($leaveStart < $reportStart ? $reportStart : $leaveStart);
                    $endTs = strtotime($leaveEnd > $reportEnd ? $reportEnd : $leaveEnd);
                    while ($cursorTs !== false && $endTs !== false && $cursorTs <= $endTs) {
                        $weekNo = (int)date('N', $cursorTs);
                        if ($weekNo < 6) {
                            attendance_monthly_add_leave_day($monthlyLeaveMap, $employeeId, date('Y-m-d', $cursorTs), attendance_monthly_approval_leave_label($content));
                        }
                        $cursorTs = strtotime('+1 day', $cursorTs);
                    }
                }
            } catch (Exception $e) {
            }
        }

        foreach ($emps as $empRow) {
            $employeeId = isset($empRow['id']) ? (int)$empRow['id'] : 0;
            if ($employeeId <= 0) continue;
            $cells = array();
            $rowStats = array(
                'work_days' => 0,
                'work_minutes' => 0,
                'normal' => 0,
                'late' => 0,
                'vacation' => 0,
                'missing_checkout' => 0
            );
            foreach ($monthDates as $dateInfo) {
                $dateKey = isset($dateInfo['date']) ? (string)$dateInfo['date'] : '';
                if ($dateKey === '') continue;
                $cell = array(
                    'status' => 'none',
                    'label' => '-',
                    'check_in' => '',
                    'check_out' => '',
                    'alert' => false
                );
                $leaveInfo = (isset($monthlyLeaveMap[$employeeId]) && isset($monthlyLeaveMap[$employeeId][$dateKey])) ? $monthlyLeaveMap[$employeeId][$dateKey] : null;
                $leaveLabel = is_array($leaveInfo) && isset($leaveInfo['label']) ? trim((string)$leaveInfo['label']) : '';
                $halfLeaveLabel = attendance_monthly_leave_half_label($leaveLabel);
                $hasRecord = (isset($monthlyRecordMap[$employeeId]) && isset($monthlyRecordMap[$employeeId][$dateKey]));
                if ($leaveInfo !== null && ($halfLeaveLabel === '' || !$hasRecord)) {
                    $cell['status'] = 'vacation';
                    $cell['label'] = $halfLeaveLabel !== '' ? $halfLeaveLabel : attendance_text('%ED%9C%B4%EA%B0%80');
                    $rowStats['vacation']++;
                } else if ($hasRecord) {
                    $record = $monthlyRecordMap[$employeeId][$dateKey];
                    $checkIn = isset($record['check_in']) ? trim((string)$record['check_in']) : '';
                    $checkOut = isset($record['check_out']) ? trim((string)$record['check_out']) : '';
                    $cell['check_in'] = attendance_monthly_time($checkIn);
                    $cell['check_out'] = attendance_monthly_time($checkOut);
                    $rowStats['work_minutes'] += isset($record['work_minutes']) ? max(0, (int)$record['work_minutes']) : 0;
                    if ($checkIn !== '') $rowStats['work_days']++;
                    if ($halfLeaveLabel !== '') {
                        $cell['status'] = 'vacation';
                        $cell['label'] = $halfLeaveLabel;
                        $rowStats['vacation']++;
                        if (attendance_monthly_is_missing_checkout($dateKey, $checkIn, $checkOut, $attendanceMonthlyToday, $attendanceMonthlyNowTime, $attendanceMissingCheckoutCutoff)) {
                            $cell['status'] = 'missing_checkout';
                            $cell['label'] = attendance_text('%EB%AF%B8%ED%87%B4%EA%B7%BC');
                            $cell['alert'] = true;
                            $rowStats['missing_checkout']++;
                        }
                    } else if (attendance_monthly_is_missing_checkout($dateKey, $checkIn, $checkOut, $attendanceMonthlyToday, $attendanceMonthlyNowTime, $attendanceMissingCheckoutCutoff)) {
                        $cell['status'] = 'missing_checkout';
                        $cell['label'] = attendance_text('%EB%AF%B8%ED%87%B4%EA%B7%BC');
                        $cell['alert'] = true;
                        $rowStats['missing_checkout']++;
                    } else if ($checkIn !== '' && attendance_monthly_is_late($checkIn, isset($empRow['position']) ? $empRow['position'] : '')) {
                        $cell['status'] = 'late';
                        $cell['label'] = attendance_text('%EC%A7%80%EA%B0%81');
                        $cell['alert'] = true;
                        $rowStats['late']++;
                    } else if ($checkIn !== '') {
                        $cell['status'] = 'normal';
                        $cell['label'] = attendance_text('%EC%A0%95%EC%83%81');
                        $rowStats['normal']++;
                    }
                }
                $cells[$dateKey] = $cell;
            }

            $row = array(
                'employee' => $empRow,
                'cells' => $cells,
                'stats' => $rowStats,
                'basis_status' => isset($cells[$reportStatusDate]) && isset($cells[$reportStatusDate]['status']) ? (string)$cells[$reportStatusDate]['status'] : 'none'
            );
            $monthlyRowsAll[count($monthlyRowsAll)] = $row;
        }

        $monthlySummary['total'] = count($monthlyRowsAll);
        foreach ($monthlyRowsAll as $monthlyRow) {
            if ($tab === 'weekly') {
                $weeklyStats = isset($monthlyRow['stats']) && is_array($monthlyRow['stats']) ? $monthlyRow['stats'] : array();
                $monthlySummary['normal'] += isset($weeklyStats['normal']) ? (int)$weeklyStats['normal'] : 0;
                $monthlySummary['late'] += isset($weeklyStats['late']) ? (int)$weeklyStats['late'] : 0;
                $monthlySummary['vacation'] += isset($weeklyStats['vacation']) ? (int)$weeklyStats['vacation'] : 0;
                $monthlySummary['missing_checkout'] += isset($weeklyStats['missing_checkout']) ? (int)$weeklyStats['missing_checkout'] : 0;
            } else {
                $basisStatus = isset($monthlyRow['basis_status']) ? (string)$monthlyRow['basis_status'] : 'none';
                if ($basisStatus === 'normal') $monthlySummary['normal']++;
                if ($basisStatus === 'late') $monthlySummary['late']++;
                if ($basisStatus === 'vacation') $monthlySummary['vacation']++;
                if ($basisStatus === 'missing_checkout') $monthlySummary['missing_checkout']++;
            }
        }

        foreach ($monthlyRowsAll as $monthlyRow) {
            $basisStatus = isset($monthlyRow['basis_status']) ? (string)$monthlyRow['basis_status'] : 'none';
            $filterStats = isset($monthlyRow['stats']) && is_array($monthlyRow['stats']) ? $monthlyRow['stats'] : array();
            $includeRow = true;
            if ($tab === 'weekly') {
                if ($quickFilter === 'late' && (!isset($filterStats['late']) || (int)$filterStats['late'] < 1)) $includeRow = false;
                if ($quickFilter === 'vacation' && (!isset($filterStats['vacation']) || (int)$filterStats['vacation'] < 1)) $includeRow = false;
                if ($quickFilter === 'missing_checkout' && (!isset($filterStats['missing_checkout']) || (int)$filterStats['missing_checkout'] < 1)) $includeRow = false;
            } else {
                if ($quickFilter === 'late' && $basisStatus !== 'late') $includeRow = false;
                if ($quickFilter === 'vacation' && $basisStatus !== 'vacation') $includeRow = false;
                if ($quickFilter === 'missing_checkout' && $basisStatus !== 'missing_checkout') $includeRow = false;
            }
            if ($includeRow) $monthlyRows[count($monthlyRows)] = $monthlyRow;
        }
    }
}

if ($editGeofenceId > 0) {
    foreach ($geofenceLocations as $geofenceRow) {
        if ((int)$geofenceRow['id'] === $editGeofenceId) {
            $editGeofence = $geofenceRow;
            break;
        }
    }
}

$totalRequests = 0;
$pendingRequests = 0;
$approvedRequests = 0;
$rejectedRequests = 0;
$filteredReqs = $reqs;
if ($pdo && $tab === 'requests') {
    try {
        $representativeNeedle = '%' . attendance_text('%EB%8C%80%ED%91%9C') . '%';
        $countSql = "SELECT r.status, COUNT(*) AS cnt
                       FROM cpms_attendance_requests r
                       JOIN employees e ON e.id = r.employee_id
                      WHERE (r.status='pending' OR r.request_date = :request_date)";
        $countParams = array(':request_date' => $requestDateFilter);
        if ($positionEnabled) {
            $countSql .= " AND COALESCE(e.position, '') NOT LIKE :rep_pos AND COALESCE(e.name, '') NOT LIKE :rep_name";
            $countParams[':rep_pos'] = $representativeNeedle;
            $countParams[':rep_name'] = $representativeNeedle;
        } else {
            $countSql .= " AND COALESCE(e.name, '') NOT LIKE :rep_name";
            $countParams[':rep_name'] = $representativeNeedle;
        }
        $countSql .= " GROUP BY r.status";
        $stCount = $pdo->prepare($countSql);
        $stCount->execute($countParams);
        $countRows = $stCount->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($countRows)) {
            foreach ($countRows as $countRow) {
                $statusKey = isset($countRow['status']) ? (string)$countRow['status'] : '';
                $cnt = isset($countRow['cnt']) ? (int)$countRow['cnt'] : 0;
                if ($statusKey === 'pending') $pendingRequests = $cnt;
                if ($statusKey === 'approved') $approvedRequests = $cnt;
                if ($statusKey === 'rejected') $rejectedRequests = $cnt;
            }
        }
    } catch (Exception $e) {
    }
}
$totalRequests = count($filteredReqs);
$requestReturnUrl = $routeManage . '&tab=attendance&atab=requests&status=' . urlencode($requestStatusFilter) . '&request_date=' . urlencode($requestDateFilter);
$monthlyReturnUrl = $routeManage . '&tab=attendance&atab=monthly&month=' . urlencode($month) . '&sort=' . urlencode($sort) . '&filter=' . urlencode($quickFilter);
$weeklyReturnUrl = $routeManage . '&tab=attendance&atab=weekly&month=' . urlencode($month) . '&week_start=' . urlencode($ws) . '&sort=' . urlencode($sort) . '&filter=' . urlencode($quickFilter);
$attendanceReportReturnUrl = $tab === 'weekly' ? $weeklyReturnUrl : $monthlyReturnUrl;
$reportFilterBaseUrl = $routeManage . '&tab=attendance&atab=' . ($tab === 'weekly' ? 'weekly' : 'monthly') . '&month=' . urlencode($month);
if ($tab === 'weekly') $reportFilterBaseUrl .= '&week_start=' . urlencode($ws);
$reportFilterBaseUrl .= '&sort=' . urlencode($sort);
$attendanceExcelUrl = '?r=management/attendance_export&atab=' . ($tab === 'weekly' ? 'weekly' : 'monthly') . '&month=' . urlencode($month) . '&sort=' . urlencode($sort) . '&filter=' . urlencode($quickFilter);
if ($tab === 'weekly') $attendanceExcelUrl .= '&week_start=' . urlencode($ws);
$isWeeklySummary = ($tab === 'weekly');
$attendanceSummaryDenominator = $isWeeklySummary
    ? ((int)$monthlySummary['normal'] + (int)$monthlySummary['late'] + (int)$monthlySummary['vacation'] + (int)$monthlySummary['missing_checkout'])
    : (int)$monthlySummary['total'];
$attendanceSummaryCountUnit = $isWeeklySummary ? attendance_text('%ED%9A%8C') : attendance_text('%EB%AA%85');
$attendanceSummaryVacationUnit = $isWeeklySummary ? attendance_text('%EC%9D%BC') : attendance_text('%EB%AA%85');
$attendanceSummaryBasisText = $isWeeklySummary
    ? (($weekLabel !== '' ? $weekLabel . ' · ' : '') . attendance_text('%EC%A3%BC%EA%B0%84%20%EC%A0%84%EC%B2%B4%20%ED%95%A9%EC%82%B0'))
    : ($reportStatusDate . ' ' . attendance_text('%EA%B8%B0%EC%A4%80'));
if (!empty($cpmsAttendanceDataOnly)) return;
?>

<?php if (!$attendanceEmbeddedInExecutiveDashboard): ?>
<div class='mb-4 flex gap-2 flex-wrap'>
    <a class='px-3 py-2 rounded-2xl border bg-white' href='<?php echo h($routeManage); ?>'><?php echo h(attendance_text('%EA%B4%80%EB%A6%AC%EB%B6%80%20%EB%A9%94%EC%9D%B8')); ?></a>
    <a class='px-3 py-2 rounded-2xl border bg-white' href='<?php echo h($routeManage . '&tab=employees'); ?>'><?php echo h(attendance_text('%EC%A7%81%EC%9B%90%EB%AA%85%EB%B6%80')); ?></a>
</div>
<?php endif; ?>

<?php if(!$hireDateEnabled): ?>
    <div class='mb-4 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900'>
        <?php echo h(attendance_text('%EC%A7%81%EC%9B%90%20hire_date%20%EC%BB%AC%EB%9F%BC%EC%9D%B4%20%EC%97%86%EC%96%B4%20%EC%97%B0%EC%B0%A8%20%EC%9E%90%EB%8F%99%20%EA%B3%84%EC%82%B0%EC%9D%B4%20%EC%9D%BC%EB%B6%80%20%EC%A0%9C%ED%95%9C%EB%90%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?>
    </div>
<?php endif; ?>

<?php foreach($attendanceErrors as $e): ?>
    <div class='mb-3 rounded-2xl border border-rose-300 bg-rose-50 px-4 py-3 text-rose-800'><?php echo h($e); ?></div>
<?php endforeach; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'reject_reason_required'): ?>
    <div class='mb-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-800'><?php echo h(attendance_text('%EB%B0%98%EB%A0%A4%20%EC%82%AC%EC%9C%A0%EB%A5%BC%20%EC%9E%85%EB%A0%A5%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.')); ?></div>
<?php endif; ?>

<style>
.cpms-attendance-dashboard{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:18px;margin-bottom:16px;color:#0f172a}
.cpms-attendance-header{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}
.cpms-attendance-title{display:flex;align-items:center;gap:14px;min-width:0}
.cpms-attendance-title h3{font-size:28px;line-height:1.2;font-weight:900;margin:0;color:#020617;letter-spacing:0}
.cpms-attendance-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.cpms-attendance-action{height:42px;display:inline-flex;align-items:center;gap:8px;border:1px solid #dbe3ef;background:#fff;color:#0f172a;border-radius:8px;padding:0 14px;font-weight:900;text-decoration:none;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.cpms-attendance-action-primary{border-color:#081a86;background:#071a98;color:#fff;box-shadow:0 8px 18px rgba(7,26,152,.18)}
.cpms-attendance-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px 0}
.cpms-attendance-tab{display:inline-flex;align-items:center;gap:8px;border:1px solid #dbe3ef;background:#fff;color:#475569;border-radius:8px;padding:9px 13px;font-weight:900;text-decoration:none}
.cpms-attendance-tab.is-active{background:#0b1f8f;color:#fff;border-color:#0b1f8f}
.cpms-attendance-panel{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px;box-shadow:0 8px 24px rgba(15,23,42,.05)}
.cpms-attendance-filterbar{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
.cpms-attendance-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.cpms-attendance-select{height:44px;min-width:170px;border:1px solid #dbe3ef;border-radius:8px;background:#fff;color:#0f172a;font-weight:900;padding:0 36px 0 12px}
.cpms-attendance-quick{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.cpms-attendance-chip{display:inline-flex;align-items:center;justify-content:center;min-height:36px;border:0;background:#f1f5f9;color:#334155;border-radius:999px;padding:0 18px;font-weight:900;text-decoration:none}
.cpms-attendance-chip.is-active{background:#071a98;color:#fff;box-shadow:0 6px 12px rgba(7,26,152,.16)}
.cpms-attendance-legend{display:flex;align-items:center;gap:16px;border:1px solid #e5e7eb;background:#fff;border-radius:8px;padding:10px 12px;font-size:12px;font-weight:900;color:#475569;white-space:nowrap}
.cpms-attendance-dot{width:9px;height:9px;border-radius:50%;display:inline-block;margin-right:5px}
.cpms-attendance-dot.normal{background:#10b981}.cpms-attendance-dot.late{background:#f97316}.cpms-attendance-dot.vacation{background:#3b82f6}.cpms-attendance-dot.missing{background:#ef4444}
.cpms-attendance-summary{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:14px;margin:12px 0 18px}
.cpms-attendance-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px;display:grid;grid-template-columns:58px minmax(0,1fr);align-items:center;gap:12px;box-shadow:0 8px 18px rgba(15,23,42,.05)}
.cpms-attendance-card-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center}
.cpms-attendance-card-icon.total{background:#eaf2ff;color:#2563eb}.cpms-attendance-card-icon.normal{background:#dcfce7;color:#16a34a}.cpms-attendance-card-icon.late{background:#ffedd5;color:#f97316}.cpms-attendance-card-icon.vacation{background:#dbeafe;color:#2563eb}.cpms-attendance-card-icon.missing{background:#ffe4e6;color:#e11d48}
.cpms-attendance-card-title{font-size:13px;font-weight:900;color:#0f172a;margin-bottom:5px}
.cpms-attendance-card-value{font-size:26px;font-weight:950;color:#020617;line-height:1.1}
.cpms-attendance-card-sub{font-size:12px;color:#64748b;font-weight:800;margin-top:6px}
.cpms-attendance-table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:8px;background:#fff;max-width:100%}
.cpms-attendance-month-table{border-collapse:separate;border-spacing:0;min-width:1280px;width:100%;font-size:12px}
.cpms-attendance-month-table.cpms-attendance-week-table{min-width:1480px}
.cpms-attendance-month-table th,.cpms-attendance-month-table td{border-right:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;vertical-align:middle}
.cpms-attendance-month-table th{background:#f8fafc;color:#475569;font-weight:950;height:42px;text-align:center;white-space:nowrap}
.cpms-attendance-month-table tr:last-child td{border-bottom:0}.cpms-attendance-month-table th:last-child,.cpms-attendance-month-table td:last-child{border-right:0}
.cpms-attendance-emp-head{position:sticky;left:0;z-index:3;min-width:230px;background:#f8fafc!important}
.cpms-attendance-emp-cell{position:sticky;left:0;z-index:2;background:#fff;min-width:230px;padding:12px}
.cpms-attendance-emp-card{display:grid;grid-template-columns:44px minmax(0,1fr);gap:10px;align-items:center}
.cpms-attendance-avatar{width:42px;height:42px;border-radius:50%;background:#e0f2fe;color:#0369a1;display:flex;align-items:center;justify-content:center;font-weight:950;overflow:hidden;border:1px solid #dbeafe}
.cpms-attendance-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.cpms-attendance-code{font-size:11px;color:#94a3b8;font-weight:900}
.cpms-attendance-name{font-size:14px;color:#0f172a;font-weight:950;line-height:1.2;margin-top:2px}
.cpms-attendance-dept{font-size:11px;color:#64748b;font-weight:800;margin-top:2px}
.cpms-attendance-emp-summary{grid-column:1/-1;font-size:11px;color:#334155;font-weight:900;margin-top:4px}
.cpms-attendance-week-table .cpms-attendance-emp-head{min-width:780px;width:780px}
.cpms-attendance-week-table .cpms-attendance-emp-cell{min-width:780px;width:780px;padding:8px}
.cpms-attendance-week-card-v2{display:grid;grid-template-columns:190px 92px 118px 70px 70px 70px 118px;gap:6px;align-items:stretch;box-sizing:border-box}
.cpms-attendance-week-identity-v2{display:grid;grid-template-columns:38px minmax(0,1fr);gap:7px;align-items:center;min-width:0;border:1px solid #dbe3ef;border-radius:8px;background:#f8fafc;padding:5px 7px;box-sizing:border-box}
.cpms-attendance-week-identity-v2 .cpms-attendance-avatar{width:36px;height:36px;align-self:center}
.cpms-attendance-week-person{display:flex;flex-direction:column;justify-content:center;min-width:0;padding:3px 5px;text-align:left}
.cpms-attendance-week-metric{min-width:0;border:1px solid #dbe3ef;border-radius:8px;background:#fff;overflow:hidden;text-align:center;display:grid;grid-template-rows:24px minmax(42px,auto);box-sizing:border-box}
.cpms-attendance-week-metric-label{display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#475569;font-size:10px;font-weight:950;padding:3px 5px}
.cpms-attendance-week-metric-value{display:flex;align-items:center;justify-content:center;color:#0f172a;font-size:13px;font-weight:950;line-height:1.25;padding:6px 5px;word-break:keep-all}
.cpms-attendance-week-metric.is-hours .cpms-attendance-week-metric-label{background:#eaf2ff;color:#1d4ed8}
.cpms-attendance-week-metric.is-over .cpms-attendance-week-metric-label{background:#fee2e2;color:#b91c1c}
.cpms-attendance-week-metric.is-over .cpms-attendance-week-metric-value{background:#fff1f2;color:#dc2626}
.cpms-attendance-week-metric.is-safe .cpms-attendance-week-metric-value{color:#64748b}
.cpms-attendance-day-head.weekend{background:#fff1f2;color:#ef4444}
.cpms-attendance-day-cell{width:56px;min-width:56px;height:86px;text-align:center;background:#fff;padding:6px 4px}
.cpms-attendance-day-cell.status-normal{background:#f0fdf4}.cpms-attendance-day-cell.status-late{background:#fff7ed}.cpms-attendance-day-cell.status-vacation{background:#eff6ff}.cpms-attendance-day-cell.status-missing_checkout{background:#fff1f2}.cpms-attendance-day-cell.status-none{background:#fff}
.cpms-attendance-day-cell.is-editable{cursor:pointer;transition:box-shadow .16s ease,transform .16s ease}
.cpms-attendance-day-cell.is-editable:hover{box-shadow:inset 0 0 0 2px #1d4ed8;transform:translateY(-1px)}
.cpms-attendance-time{line-height:1.35;color:#0f172a;font-weight:800;min-height:31px}
.cpms-attendance-empty{color:#94a3b8;font-weight:900;margin-top:10px}
.cpms-attendance-badge{display:inline-flex;align-items:center;gap:3px;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:950;margin-top:5px}
.cpms-attendance-badge.status-normal{background:#dcfce7;color:#166534}.cpms-attendance-badge.status-late{background:#ffedd5;color:#c2410c}.cpms-attendance-badge.status-vacation{background:#dbeafe;color:#1d4ed8}.cpms-attendance-badge.status-missing_checkout{background:#fee2e2;color:#dc2626}
.cpms-attendance-alert{width:13px;height:13px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#ef4444;color:#fff;font-size:9px;line-height:1}
.cpms-attendance-alert span{color:#fff}
.cpms-attendance-empty-state{padding:28px;text-align:center;color:#64748b;font-weight:900}
.cpms-attendance-modal{position:fixed;inset:0;z-index:80;display:none}
.cpms-attendance-modal.is-open{display:block}
.cpms-attendance-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.42)}
.cpms-attendance-modal-shell{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:24px}
.cpms-attendance-modal-card{width:100%;max-width:820px;max-height:92vh;overflow:auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 22px 60px rgba(15,23,42,.22);padding:22px}
.cpms-attendance-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}
.cpms-attendance-modal-title{font-size:20px;font-weight:950;color:#0f172a}
.cpms-attendance-modal-sub{font-size:13px;color:#64748b;font-weight:800;margin-top:4px}
.cpms-attendance-close{width:36px;height:36px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;color:#334155;font-weight:950}
.cpms-attendance-time-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.cpms-attendance-time-box{border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;padding:14px}
.cpms-attendance-time-label{font-size:13px;font-weight:950;color:#0f172a;margin-bottom:10px}
.cpms-attendance-time-input{width:100%;height:48px;border:1px solid #dbe3ef;border-radius:12px;background:#fff;color:#0f172a;font-size:17px;font-weight:950;padding:0 14px}
.cpms-attendance-time-hint{font-size:12px;color:#64748b;font-weight:800;margin-top:8px}
.cpms-attendance-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
.cpms-attendance-modal-actions button{height:40px;border-radius:8px;padding:0 16px;font-weight:950}
.cpms-attendance-cancel{border:1px solid #dbe3ef;background:#fff;color:#334155}
.cpms-attendance-save{border:0;background:#071a98;color:#fff}
@media (max-width:1200px){.cpms-attendance-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.cpms-attendance-filterbar{display:block}.cpms-attendance-legend{margin-top:12px;display:inline-flex}.cpms-attendance-header{align-items:flex-start;flex-direction:column}.cpms-attendance-actions{justify-content:flex-start}}
@media (max-width:900px){.cpms-attendance-time-grid{grid-template-columns:1fr}.cpms-attendance-modal-shell{padding:12px}}
</style>

<div class='cpms-attendance-dashboard'>
    <div class='cpms-attendance-header'>
        <div class='cpms-attendance-title'>
            <h3><?php echo h(attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EA%B7%BC%ED%83%9C%EA%B4%80%EB%A6%AC')); ?></h3>
        </div>
        <div class='cpms-attendance-actions'>
            <?php if($tab === 'monthly' || $tab === 'weekly'): ?>
                <a class='cpms-attendance-action cpms-attendance-action-primary' href='<?php echo h($attendanceExcelUrl); ?>'><i data-lucide='download' class='w-4 h-4'></i><?php echo h(attendance_text('%EC%97%91%EC%85%80%20%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C')); ?></a>
            <?php else: ?>
                <button type='button' class='cpms-attendance-action' disabled><i data-lucide='download' class='w-4 h-4'></i><?php echo h(attendance_text('%EC%97%91%EC%85%80%20%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C')); ?></button>
            <?php endif; ?>
            <button type='button' class='cpms-attendance-action'><i data-lucide='upload' class='w-4 h-4'></i><?php echo h(attendance_text('%EC%97%85%EB%A1%9C%EB%93%9C')); ?></button>
        </div>
    </div>

    <div class='cpms-attendance-tabs'>
        <a class='cpms-attendance-tab <?php echo $tab==='monthly'?'is-active':'';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=monthly&month=' . urlencode($month) . '&sort=' . urlencode($sort)); ?>'><?php echo h(attendance_text('%EC%9B%94%EA%B0%84%20%ED%98%84%ED%99%A9')); ?></a>
        <?php if($canViewAttendanceRequests): ?>
            <a class='cpms-attendance-tab <?php echo $tab==='requests'?'is-active':'';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests'); ?>'><?php echo h(attendance_text('%EC%9A%94%EC%B2%AD%20%EA%B4%80%EB%A6%AC')); ?></a>
        <?php endif; ?>
        <a class='cpms-attendance-tab <?php echo $tab==='daily'?'is-active':'';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=daily'); ?>'><?php echo h(attendance_text('%EC%9D%BC%EC%9D%BC%20%ED%98%84%ED%99%A9')); ?></a>
        <a class='cpms-attendance-tab <?php echo $tab==='weekly'?'is-active':'';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=weekly&month=' . urlencode($month) . '&week_start=' . urlencode($ws) . '&sort=' . urlencode($sort)); ?>'><?php echo h(attendance_text('%EC%A3%BC%EA%B0%84%20%ED%98%84%ED%99%A9')); ?></a>
        <?php if($canViewAttendanceSettings): ?>
            <a class='cpms-attendance-tab <?php echo $tab==='settings'?'is-active':'';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=settings'); ?>'><?php echo h(attendance_text('%EC%84%A4%EC%A0%95')); ?></a>
        <?php endif; ?>
    </div>

    <?php if($tab==='monthly' || $tab==='weekly'): ?>
        <div class='cpms-attendance-panel'>
            <div class='cpms-attendance-filterbar'>
                <div>
                    <form method='get' action='' class='cpms-attendance-controls'>
                        <input type='hidden' name='r' value='<?php echo h($attendanceRouteName); ?>'>
                        <?php if ($attendanceEmbeddedInExecutiveDashboard): ?><input type='hidden' name='exec_tab' value='attendanceManagement'><?php endif; ?>
                        <input type='hidden' name='tab' value='attendance'>
                        <input type='hidden' name='atab' value='<?php echo $tab === 'weekly' ? 'weekly' : 'monthly'; ?>'>
                        <input type='hidden' name='filter' value='<?php echo h($quickFilter); ?>'>
                        <input type='month' name='month' value='<?php echo h($month); ?>' class='cpms-attendance-select' onchange='this.form.submit()'>
                        <?php if($tab === 'weekly'): ?>
                            <select name='week_start' class='cpms-attendance-select' onchange='this.form.submit()'>
                                <?php foreach($weekOptions as $weekOption): ?>
                                    <?php
                                        $optionStart = isset($weekOption['start']) ? (string)$weekOption['start'] : '';
                                        $optionLabel = isset($weekOption['label']) ? (string)$weekOption['label'] : '';
                                        $optionRange = isset($weekOption['range_label']) ? (string)$weekOption['range_label'] : '';
                                    ?>
                                    <option value='<?php echo h($optionStart); ?>' <?php echo $optionStart === $ws ? 'selected' : ''; ?>><?php echo h($optionLabel . ' (' . $optionRange . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <select name='sort' class='cpms-attendance-select' onchange='this.form.submit()'>
                            <option value='position' <?php echo $sort === 'position' ? 'selected' : ''; ?>><?php echo h(attendance_text('%EC%A7%81%EA%B8%89%EB%B3%84')); ?></option>
                            <option value='name' <?php echo $sort === 'name' ? 'selected' : ''; ?>><?php echo h(attendance_text('%EC%9D%B4%EB%A6%84%EC%88%9C')); ?></option>
                            <option value='department' <?php echo $sort === 'department' ? 'selected' : ''; ?>><?php echo h(attendance_text('%EB%B6%80%EC%84%9C%EB%B3%84')); ?></option>
                        </select>
                    </form>
                    <div class='cpms-attendance-quick'>
                        <a class='cpms-attendance-chip <?php echo $quickFilter==='all'?'is-active':'';?>' href='<?php echo h($reportFilterBaseUrl . '&filter=all'); ?>'><?php echo h(attendance_text('%EC%A0%84%EC%B2%B4')); ?></a>
                        <a class='cpms-attendance-chip <?php echo $quickFilter==='late'?'is-active':'';?>' href='<?php echo h($reportFilterBaseUrl . '&filter=late'); ?>'><?php echo h(attendance_text('%EC%A7%80%EA%B0%81%EC%9E%90%EB%A7%8C')); ?></a>
                        <a class='cpms-attendance-chip <?php echo $quickFilter==='vacation'?'is-active':'';?>' href='<?php echo h($reportFilterBaseUrl . '&filter=vacation'); ?>'><?php echo h(attendance_text('%ED%9C%B4%EA%B0%80%EC%9E%90%EB%A7%8C')); ?></a>
                        <a class='cpms-attendance-chip <?php echo $quickFilter==='missing_checkout'?'is-active':'';?>' href='<?php echo h($reportFilterBaseUrl . '&filter=missing_checkout'); ?>'><?php echo h(attendance_text('%EB%AF%B8%ED%87%B4%EA%B7%BC%EC%9E%90%EB%A7%8C')); ?></a>
                    </div>
                    <?php if($tab === 'weekly'): ?>
                        <div class='text-xs text-slate-500 mt-2'><?php echo h(($weekLabel !== '' ? $weekLabel . ' · ' : '') . $weekRangeLabel); ?> 전체의 정상출근, 지각, 휴가, 미퇴근 횟수를 합산합니다. 오늘 미퇴근은 18:00 이후부터 표시됩니다.</div>
                    <?php else: ?>
                        <div class='text-xs text-slate-500 mt-2'><?php echo h($reportStatusDate); ?> 기준으로 정상출근, 지각, 휴가, 미퇴근을 표시합니다. 오늘 미퇴근은 18:00 이후부터 표시됩니다.</div>
                    <?php endif; ?>
                </div>
                <div class='cpms-attendance-legend'>
                    <span><i class='cpms-attendance-dot normal'></i><?php echo h(attendance_text('%EC%A0%95%EC%83%81')); ?></span>
                    <span><i class='cpms-attendance-dot late'></i><?php echo h(attendance_text('%EC%A7%80%EA%B0%81')); ?></span>
                    <span><i class='cpms-attendance-dot vacation'></i><?php echo h(attendance_text('%ED%9C%B4%EA%B0%80')); ?></span>
                    <span><i class='cpms-attendance-dot missing'></i><?php echo h(attendance_text('%EB%AF%B8%ED%87%B4%EA%B7%BC')); ?></span>
                </div>
            </div>

            <div class='cpms-attendance-summary'>
                <div class='cpms-attendance-card'>
                    <div class='cpms-attendance-card-icon total'><i data-lucide='users' class='w-7 h-7'></i></div>
                    <div>
                        <div class='cpms-attendance-card-title'><?php echo h(attendance_text('%EC%B4%9D%20%EC%9D%B8%EC%9B%90')); ?></div>
                        <div class='cpms-attendance-card-value'><?php echo (int)$monthlySummary['total']; ?><?php echo h(attendance_text('%EB%AA%85')); ?></div>
                        <div class='cpms-attendance-card-sub'><?php echo h($attendanceSummaryBasisText); ?></div>
                    </div>
                </div>
                <div class='cpms-attendance-card'>
                    <div class='cpms-attendance-card-icon normal'><i data-lucide='shield-check' class='w-7 h-7'></i></div>
                    <div>
                        <div class='cpms-attendance-card-title'><?php echo h(attendance_text('%EC%A0%95%EC%83%81%20%EC%B6%9C%EA%B7%BC')); ?></div>
                        <div class='cpms-attendance-card-value'><?php echo (int)$monthlySummary['normal']; ?><?php echo h($attendanceSummaryCountUnit); ?></div>
                        <div class='cpms-attendance-card-sub'><?php echo h(attendance_monthly_percent($monthlySummary['normal'], $attendanceSummaryDenominator)); ?></div>
                    </div>
                </div>
                <div class='cpms-attendance-card'>
                    <div class='cpms-attendance-card-icon late'><i data-lucide='clock' class='w-7 h-7'></i></div>
                    <div>
                        <div class='cpms-attendance-card-title'><?php echo h(attendance_text('%EC%A7%80%EA%B0%81')); ?></div>
                        <div class='cpms-attendance-card-value'><?php echo (int)$monthlySummary['late']; ?><?php echo h($attendanceSummaryCountUnit); ?></div>
                        <div class='cpms-attendance-card-sub'><?php echo h(attendance_monthly_percent($monthlySummary['late'], $attendanceSummaryDenominator)); ?></div>
                    </div>
                </div>
                <div class='cpms-attendance-card'>
                    <div class='cpms-attendance-card-icon vacation'><i data-lucide='umbrella' class='w-7 h-7'></i></div>
                    <div>
                        <div class='cpms-attendance-card-title'><?php echo h(attendance_text('%ED%9C%B4%EA%B0%80')); ?></div>
                        <div class='cpms-attendance-card-value'><?php echo (int)$monthlySummary['vacation']; ?><?php echo h($attendanceSummaryVacationUnit); ?></div>
                        <div class='cpms-attendance-card-sub'><?php echo h(attendance_monthly_percent($monthlySummary['vacation'], $attendanceSummaryDenominator)); ?></div>
                    </div>
                </div>
                <div class='cpms-attendance-card'>
                    <div class='cpms-attendance-card-icon missing'><i data-lucide='log-out' class='w-7 h-7'></i></div>
                    <div>
                        <div class='cpms-attendance-card-title'><?php echo h(attendance_text('%EB%AF%B8%ED%87%B4%EA%B7%BC')); ?></div>
                        <div class='cpms-attendance-card-value'><?php echo (int)$monthlySummary['missing_checkout']; ?><?php echo h($attendanceSummaryCountUnit); ?></div>
                        <div class='cpms-attendance-card-sub'><?php echo h(attendance_monthly_percent($monthlySummary['missing_checkout'], $attendanceSummaryDenominator)); ?></div>
                    </div>
                </div>
            </div>

            <div class='cpms-attendance-table-wrap'>
                <table class='cpms-attendance-month-table<?php echo $tab === 'weekly' ? ' cpms-attendance-week-table' : ''; ?>'>
                    <thead>
                        <tr>
                            <th class='cpms-attendance-emp-head'><?php echo h(attendance_text('%EC%A7%81%EC%9B%90%20%EC%A0%95%EB%B3%B4')); ?></th>
                            <?php foreach($monthDates as $dateInfo): ?>
                                <th class='cpms-attendance-day-head <?php echo !empty($dateInfo['weekend']) ? 'weekend' : ''; ?>'><?php if($tab === 'weekly'): ?><?php echo isset($dateInfo['month']) ? (int)$dateInfo['month'] : 0; ?>/<?php endif; ?><?php echo (int)$dateInfo['day']; ?>/<?php echo h($dateInfo['week']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($monthlyRows as $monthlyRow): ?>
                            <?php
                                $employee = isset($monthlyRow['employee']) ? $monthlyRow['employee'] : array();
                                $stats = isset($monthlyRow['stats']) ? $monthlyRow['stats'] : array();
                                $employeeName = isset($employee['name']) ? (string)$employee['name'] : '';
                                $initial = function_exists('mb_substr') ? mb_substr($employeeName, 0, 1, 'UTF-8') : substr($employeeName, 0, 1);
                                $photoPath = isset($employee['photo_path']) ? trim((string)$employee['photo_path']) : '';
                            ?>
                            <tr>
                                <td class='cpms-attendance-emp-cell'>
                                    <?php if($tab === 'weekly'): ?>
                                        <?php
                                            $employeeNo = isset($employee['employee_no']) ? trim((string)$employee['employee_no']) : '';
                                            $workLocation = isset($employee['work_location']) ? trim((string)$employee['work_location']) : '';
                                            $weeklyMinutes = isset($stats['work_minutes']) ? max(0, (int)$stats['work_minutes']) : 0;
                                            $isOver52 = $weeklyMinutes > $attendanceWeeklyLimitMinutes;
                                        ?>
                                        <div class='cpms-attendance-week-card-v2' data-weekly-employee-summary='1'>
                                            <div class='cpms-attendance-week-identity-v2'>
                                                <div class='cpms-attendance-avatar'>
                                                    <?php if($photoPath !== ''): ?><img src='<?php echo h($photoPath); ?>' alt=''><?php else: ?><span><?php echo h($initial !== '' ? $initial : '?'); ?></span><?php endif; ?>
                                                </div>
                                                <div class='cpms-attendance-week-person'>
                                                    <div class='cpms-attendance-code'><?php echo h($employeeNo !== '' ? $employeeNo : '-'); ?></div>
                                                    <div class='cpms-attendance-name'><?php echo h($employeeName !== '' ? $employeeName : '-'); ?></div>
                                                    <div class='cpms-attendance-dept'><?php echo h(isset($employee['department']) && trim((string)$employee['department']) !== '' ? $employee['department'] : '-'); ?><?php if(isset($employee['position']) && trim((string)$employee['position']) !== ''): ?> / <?php echo h($employee['position']); ?><?php endif; ?></div>
                                                </div>
                                            </div>
                                            <div class='cpms-attendance-week-metric'>
                                                <div class='cpms-attendance-week-metric-label'><?php echo h(attendance_text('%EA%B7%BC%EB%AC%B4%EC%A7%80')); ?></div>
                                                <div class='cpms-attendance-week-metric-value'><?php echo h($workLocation !== '' ? $workLocation : '-'); ?></div>
                                            </div>
                                            <div class='cpms-attendance-week-metric is-hours'>
                                                <div class='cpms-attendance-week-metric-label'><?php echo h(attendance_text('%EC%A3%BC%EA%B0%84%20%EB%88%84%EC%A0%81')); ?></div>
                                                <div class='cpms-attendance-week-metric-value'><?php echo h(attendance_hm($weeklyMinutes)); ?></div>
                                            </div>
                                            <div class='cpms-attendance-week-metric'>
                                                <div class='cpms-attendance-week-metric-label'><?php echo h(attendance_text('%EC%B6%9C%EA%B7%BC')); ?></div>
                                                <div class='cpms-attendance-week-metric-value'><?php echo isset($stats['work_days']) ? (int)$stats['work_days'] : 0; ?><?php echo h(attendance_text('%EC%9D%BC')); ?></div>
                                            </div>
                                            <div class='cpms-attendance-week-metric'>
                                                <div class='cpms-attendance-week-metric-label'><?php echo h(attendance_text('%EC%A7%80%EA%B0%81')); ?></div>
                                                <div class='cpms-attendance-week-metric-value'><?php echo isset($stats['late']) ? (int)$stats['late'] : 0; ?><?php echo h(attendance_text('%ED%9A%8C')); ?></div>
                                            </div>
                                            <div class='cpms-attendance-week-metric'>
                                                <div class='cpms-attendance-week-metric-label'><?php echo h(attendance_text('%ED%9C%B4%EA%B0%80')); ?></div>
                                                <div class='cpms-attendance-week-metric-value'><?php echo isset($stats['vacation']) ? (int)$stats['vacation'] : 0; ?><?php echo h(attendance_text('%EC%9D%BC')); ?></div>
                                            </div>
                                            <div class='cpms-attendance-week-metric <?php echo $isOver52 ? 'is-over' : 'is-safe'; ?>'>
                                                <div class='cpms-attendance-week-metric-label'><?php echo h(attendance_text('52%EC%8B%9C%EA%B0%84')); ?></div>
                                                <div class='cpms-attendance-week-metric-value'><?php echo h($isOver52 ? attendance_hm($weeklyMinutes) . ' ' . attendance_text('%EC%B4%88%EA%B3%BC') : attendance_text('%EC%A0%95%EC%83%81')); ?></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <?php $employeeNo = isset($employee['employee_no']) ? trim((string)$employee['employee_no']) : ''; ?>
                                        <div class='cpms-attendance-emp-card'>
                                            <div class='cpms-attendance-avatar'>
                                                <?php if($photoPath !== ''): ?><img src='<?php echo h($photoPath); ?>' alt=''><?php else: ?><span><?php echo h($initial !== '' ? $initial : '?'); ?></span><?php endif; ?>
                                            </div>
                                            <div>
                                                <div class='cpms-attendance-code'><?php echo h($employeeNo !== '' ? $employeeNo : '-'); ?></div>
                                                <div class='cpms-attendance-name'><?php echo h($employeeName !== '' ? $employeeName : '-'); ?></div>
                                                <div class='cpms-attendance-dept'><?php echo h(isset($employee['department']) && trim((string)$employee['department']) !== '' ? $employee['department'] : '-'); ?><?php if(isset($employee['position']) && trim((string)$employee['position']) !== ''): ?> / <?php echo h($employee['position']); ?><?php endif; ?></div>
                                            </div>
                                            <div class='cpms-attendance-emp-summary'>
                                                <?php echo h(attendance_text('%EC%B6%9C%EA%B7%BC')); ?> <?php echo isset($stats['work_days']) ? (int)$stats['work_days'] : 0; ?><?php echo h(attendance_text('%EC%9D%BC')); ?>
                                                &middot; <?php echo h(attendance_text('%EC%A7%80%EA%B0%81')); ?> <?php echo isset($stats['late']) ? (int)$stats['late'] : 0; ?><?php echo h(attendance_text('%ED%9A%8C')); ?>
                                                &middot; <?php echo h(attendance_text('%ED%9C%B4%EA%B0%80')); ?> <?php echo isset($stats['vacation']) ? (int)$stats['vacation'] : 0; ?><?php echo h(attendance_text('%EC%9D%BC')); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php foreach($monthDates as $dateInfo): ?>
                                    <?php
                                        $dateKey = isset($dateInfo['date']) ? $dateInfo['date'] : '';
                                        $cell = (isset($monthlyRow['cells']) && isset($monthlyRow['cells'][$dateKey])) ? $monthlyRow['cells'][$dateKey] : array('status'=>'none','label'=>'-','check_in'=>'','check_out'=>'','alert'=>false);
                                        $statusClass = isset($cell['status']) ? (string)$cell['status'] : 'none';
                                        $cellEmployeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
                                        $cellCheckIn = isset($cell['check_in']) ? (string)$cell['check_in'] : '';
                                        $cellCheckOut = isset($cell['check_out']) ? (string)$cell['check_out'] : '';
                                    ?>
                                    <td class='cpms-attendance-day-cell status-<?php echo h($statusClass); ?><?php echo $canEditAttendanceCells ? ' is-editable' : ''; ?>'
                                        <?php if($canEditAttendanceCells): ?>
                                            data-attendance-cell-edit='1'
                                            data-employee-id='<?php echo (int)$cellEmployeeId; ?>'
                                            data-employee-name='<?php echo h($employeeName); ?>'
                                            data-work-date='<?php echo h($dateKey); ?>'
                                            data-check-in='<?php echo h($cellCheckIn); ?>'
                                            data-check-out='<?php echo h($cellCheckOut); ?>'
                                        <?php endif; ?>>
                                        <?php if($statusClass === 'none'): ?>
                                            <div class='cpms-attendance-empty'>-</div>
                                        <?php else: ?>
                                            <div class='cpms-attendance-time'>
                                                <?php if(isset($cell['check_in']) && $cell['check_in'] !== ''): ?><?php echo h($cell['check_in']); ?><?php else: ?>&nbsp;<?php endif; ?><br>
                                                <?php if(isset($cell['check_out']) && $cell['check_out'] !== ''): ?><?php echo h($cell['check_out']); ?><?php else: ?><?php echo $statusClass === 'vacation' ? '&nbsp;' : '-'; ?><?php endif; ?>
                                            </div>
                                            <span class='cpms-attendance-badge status-<?php echo h($statusClass); ?>'>
                                                <?php echo h(isset($cell['label']) ? $cell['label'] : '-'); ?>
                                                <?php if(!empty($cell['alert'])): ?><span class='cpms-attendance-alert'><span>!</span></span><?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(count($monthlyRows) === 0): ?>
                            <tr>
                                <td class='cpms-attendance-empty-state' colspan='<?php echo (int)$reportDayCount + 1; ?>'><?php echo h(attendance_text('%EC%A1%B0%EA%B1%B4%EC%97%90%20%EB%A7%9E%EB%8A%94%20%EA%B7%BC%ED%83%9C%20%EB%8D%B0%EC%9D%B4%ED%84%B0%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if($canEditAttendanceCells): ?>
                <div class='cpms-attendance-modal' id='attendanceCellEditModal' aria-hidden='true'>
                    <div class='cpms-attendance-modal-backdrop' data-attendance-modal-close='1'></div>
                    <div class='cpms-attendance-modal-shell'>
                        <div class='cpms-attendance-modal-card'>
                            <form method='post' action='?r=management/attendance_record_save' id='attendanceCellEditForm'>
                                <input type='hidden' name='_csrf' value='<?php echo h(csrf_token()); ?>'>
                                <input type='hidden' name='employee_id' id='attendanceEditEmployeeId' value=''>
                                <input type='hidden' name='work_date' id='attendanceEditWorkDate' value=''>
                                <input type='hidden' name='return_url' value='<?php echo h($attendanceReportReturnUrl); ?>'>
                                <div class='cpms-attendance-modal-head'>
                                    <div>
                                        <div class='cpms-attendance-modal-title'><?php echo h(attendance_text('%EA%B7%BC%ED%83%9C%20%EC%8B%9C%EA%B0%84%20%EC%88%98%EC%A0%95')); ?></div>
                                        <div class='cpms-attendance-modal-sub' id='attendanceEditSub'></div>
                                    </div>
                                    <button type='button' class='cpms-attendance-close' data-attendance-modal-close='1'>x</button>
                                </div>
                                <div class='cpms-attendance-time-grid'>
                                    <div class='cpms-attendance-time-box'>
                                        <div class='cpms-attendance-time-label'><?php echo h(attendance_text('%EC%B6%9C%EA%B7%BC%EC%8B%9C%EA%B0%84')); ?></div>
                                        <input type='time' name='check_in_time' id='attendanceEditCheckIn' value='08:00' required class='cpms-attendance-time-input w-full px-4 py-3 rounded-2xl border border-gray-200'>
                                        <div class='cpms-attendance-time-hint'>08:00</div>
                                    </div>
                                    <div class='cpms-attendance-time-box'>
                                        <div class='cpms-attendance-time-label'><?php echo h(attendance_text('%ED%87%B4%EA%B7%BC%EC%8B%9C%EA%B0%84')); ?></div>
                                        <input type='time' name='check_out_time' id='attendanceEditCheckOut' value='' class='cpms-attendance-time-input w-full px-4 py-3 rounded-2xl border border-gray-200'>
                                        <div class='cpms-attendance-time-hint'>비워두면 출근중으로 저장됩니다.</div>
                                        <button type='button' id='attendanceClearCheckOut' class='mt-2 px-3 py-2 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-sm font-extrabold'>퇴근 취소</button>
                                    </div>
                                </div>
                                <div class='cpms-attendance-modal-actions'>
                                    <button type='button' class='cpms-attendance-cancel' data-attendance-modal-close='1'><?php echo h(attendance_text('%EB%8B%AB%EA%B8%B0')); ?></button>
                                    <button type='submit' class='cpms-attendance-save'><?php echo h(attendance_text('%EC%A0%80%EC%9E%A5')); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <script>
                (function(){
                    var modal = document.getElementById('attendanceCellEditModal');
                    var form = document.getElementById('attendanceCellEditForm');
                    if (!modal || !form) return;
                    var employeeInput = document.getElementById('attendanceEditEmployeeId');
                    var dateInput = document.getElementById('attendanceEditWorkDate');
                    var checkInInput = document.getElementById('attendanceEditCheckIn');
                    var checkOutInput = document.getElementById('attendanceEditCheckOut');
                    var clearCheckOutButton = document.getElementById('attendanceClearCheckOut');
                    var sub = document.getElementById('attendanceEditSub');
                    function closestCell(target) {
                        while (target && target !== document) {
                            if (target.getAttribute && target.getAttribute('data-attendance-cell-edit') === '1') return target;
                            target = target.parentNode;
                        }
                        return null;
                    }
                    function closeModal() {
                        modal.className = modal.className.replace(/\s?is-open/g, '');
                        modal.setAttribute('aria-hidden', 'true');
                    }
                    function openModal(cell) {
                        employeeInput.value = cell.getAttribute('data-employee-id') || '';
                        dateInput.value = cell.getAttribute('data-work-date') || '';
                        checkInInput.value = cell.getAttribute('data-check-in') || '08:00';
                        checkOutInput.value = cell.getAttribute('data-check-out') || '';
                        if (sub) sub.textContent = (cell.getAttribute('data-employee-name') || '-') + ' / ' + (cell.getAttribute('data-work-date') || '');
                        modal.className += ' is-open';
                        modal.setAttribute('aria-hidden', 'false');
                    }
                    if (clearCheckOutButton) {
                        clearCheckOutButton.addEventListener('click', function(){
                            checkOutInput.value = '';
                            checkOutInput.focus();
                        });
                    }
                    document.addEventListener('click', function(event){
                        var closeTarget = event.target.getAttribute ? event.target.getAttribute('data-attendance-modal-close') : '';
                        if (closeTarget === '1') {
                            closeModal();
                            return;
                        }
                        var cell = closestCell(event.target);
                        if (cell) openModal(cell);
                    });
                    document.addEventListener('keydown', function(event){
                        if (event.keyCode === 27) closeModal();
                    });
                })();
                </script>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if($tab==='daily'): ?>
        <div class='overflow-x-auto rounded-2xl border border-gray-200'>
            <table class='min-w-full text-sm'>
                <tr class='bg-gray-50'>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EC%A7%81%EC%9B%90%EB%AA%85')); ?></th>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EB%B6%80%EC%84%9C')); ?></th>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EC%A7%81%EC%B1%85')); ?></th>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EC%B6%9C%EA%B7%BC%EC%8B%9C%EA%B0%84')); ?></th>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%ED%87%B4%EA%B7%BC%EC%8B%9C%EA%B0%84')); ?></th>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EC%83%81%ED%83%9C')); ?></th>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EA%B7%BC%EB%AC%B4%EC%8B%9C%EA%B0%84')); ?></th>
                </tr>
                <?php foreach($daily as $r): ?>
                    <tr class='border-b last:border-b-0'>
                        <td class='p-3'><?php echo h(isset($r['name'])?$r['name']:''); ?></td>
                        <td class='p-3'><?php echo h(isset($r['department'])?$r['department']:''); ?></td>
                        <td class='p-3'><?php echo h(isset($r['position'])?$r['position']:''); ?></td>
                        <td class='p-3'><?php echo h(isset($r['check_in']) && $r['check_in'] ? $r['check_in'] : '-'); ?></td>
                        <td class='p-3'><?php echo h(isset($r['check_out']) && $r['check_out'] ? $r['check_out'] : '-'); ?></td>
                        <td class='p-3'><?php echo h(isset($r['status'])?$r['status']:'-'); ?></td>
                        <td class='p-3'><?php echo isset($r['work_minutes']) ? number_format(((float)$r['work_minutes'])/60,2) . 'h' : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <?php if($tab==='requests'): ?>
        <div class='grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-sm'>
            <div class='rounded-xl border p-3 bg-gray-50'><div class='text-gray-500'>표시 요청</div><div class='text-xl font-bold'><?php echo (int)$totalRequests; ?>건</div></div>
            <div class='rounded-xl border p-3 bg-amber-50'><div class='text-amber-700'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8%EB%8C%80%EA%B8%B0')); ?></div><div class='text-xl font-bold'><?php echo (int)$pendingRequests; ?>건</div></div>
            <div class='rounded-xl border p-3 bg-emerald-50'><div class='text-emerald-700'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8%EC%99%84%EB%A3%8C')); ?></div><div class='text-xl font-bold'><?php echo (int)$approvedRequests; ?>건</div></div>
            <div class='rounded-xl border p-3 bg-rose-50'><div class='text-rose-700'><?php echo h(attendance_text('%EB%B0%98%EB%A0%A4')); ?></div><div class='text-xl font-bold'><?php echo (int)$rejectedRequests; ?>건</div></div>
        </div>

        <form method='get' action='' class='mb-3 rounded-2xl border border-gray-200 bg-gray-50 p-4'>
            <input type='hidden' name='r' value='<?php echo h($attendanceRouteName); ?>'>
            <?php if ($attendanceEmbeddedInExecutiveDashboard): ?><input type='hidden' name='exec_tab' value='attendanceManagement'><?php endif; ?>
            <input type='hidden' name='tab' value='attendance'>
            <input type='hidden' name='atab' value='requests'>
            <div class='grid grid-cols-1 md:grid-cols-4 gap-3 items-end'>
                <label class='block text-sm font-bold text-gray-700'>
                    <span class='block mb-1'>요청 날짜</span>
                    <input type='date' name='request_date' value='<?php echo h($requestDateFilter); ?>' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white'>
                </label>
                <label class='block text-sm font-bold text-gray-700'>
                    <span class='block mb-1'>상태</span>
                    <select name='status' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white'>
                        <option value='all' <?php echo $requestStatusFilter === 'all' ? 'selected' : ''; ?>>전체</option>
                        <option value='pending' <?php echo $requestStatusFilter === 'pending' ? 'selected' : ''; ?>>승인대기</option>
                        <option value='approved' <?php echo $requestStatusFilter === 'approved' ? 'selected' : ''; ?>>승인완료</option>
                        <option value='rejected' <?php echo $requestStatusFilter === 'rejected' ? 'selected' : ''; ?>>반려</option>
                    </select>
                </label>
                <div class='md:col-span-2 flex flex-wrap gap-2'>
                    <button type='submit' class='px-4 py-2 rounded-xl bg-gray-900 text-white font-bold'>조회</button>
                    <a class='px-4 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 font-bold' href='<?php echo h($routeManage . '&tab=attendance&atab=requests&request_date=' . urlencode(date('Y-m-d'))); ?>'>오늘</a>
                </div>
            </div>
        </form>

        <div class='mb-4 flex gap-2 text-sm flex-wrap'>
            <a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='all'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests&status=all&request_date=' . urlencode($requestDateFilter)); ?>'><?php echo h(attendance_text('%EC%A0%84%EC%B2%B4')); ?></a>
            <a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='pending'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests&status=pending&request_date=' . urlencode($requestDateFilter)); ?>'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8%EB%8C%80%EA%B8%B0')); ?></a>
            <a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='approved'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests&status=approved&request_date=' . urlencode($requestDateFilter)); ?>'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8%EC%99%84%EB%A3%8C')); ?></a>
            <a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='rejected'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests&status=rejected&request_date=' . urlencode($requestDateFilter)); ?>'><?php echo h(attendance_text('%EB%B0%98%EB%A0%A4')); ?></a>
        </div>

        <?php foreach($filteredReqs as $r): $st=isset($r['status'])?(string)$r['status']:''; ?>
            <div class='rounded-2xl border border-gray-200 p-4 mb-3 bg-white shadow-sm'>
                <div class='flex items-center justify-between mb-2 gap-3'>
                    <div class='font-bold'><?php echo h(attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%88%98%EC%A0%95%20%EC%9A%94%EC%B2%AD')); ?> #<?php echo isset($r['id'])?(int)$r['id']:0; ?></div>
                    <span class='px-2 py-1 text-xs rounded-full border <?php echo attendance_request_status_class($st); ?>'><?php echo h(attendance_request_status_label($st)); ?></span>
                </div>
                <div class='grid md:grid-cols-2 gap-2 text-sm'>
                    <div><b><?php echo h(attendance_text('%EC%A7%81%EC%9B%90%EB%AA%85')); ?></b> : <?php echo h(isset($r['name'])?$r['name']:'-'); ?> / <?php echo h(isset($r['department'])?$r['department']:'-'); ?> / <?php echo h(isset($r['position'])?$r['position']:'-'); ?></div>
                    <div><b><?php echo h(attendance_text('%EC%9A%94%EC%B2%AD%EC%9D%BC%EC%8B%9C')); ?></b> : <?php echo h(isset($r['created_at'])?$r['created_at']:'-'); ?></div>
                    <div><b><?php echo h(attendance_text('%EC%9A%94%EC%B2%AD%20%EB%82%A0%EC%A7%9C')); ?></b> : <?php echo h(isset($r['request_date'])?$r['request_date']:'-'); ?></div>
                    <div><b><?php echo h(attendance_text('%EC%9A%94%EC%B2%AD%20%EA%B5%AC%EB%B6%84')); ?></b> : <?php echo h(attendance_request_type_label(isset($r['request_type'])?$r['request_type']:'')); ?></div>
                    <div><b><?php echo h(attendance_text('%EC%9A%94%EC%B2%AD%20%EC%B6%9C%EA%B7%BC%EC%8B%9C%EA%B0%84')); ?></b> : <?php echo h(isset($r['requested_check_in'])?$r['requested_check_in']:'-'); ?></div>
                    <div><b><?php echo h(attendance_text('%EC%9A%94%EC%B2%AD%20%ED%87%B4%EA%B7%BC%EC%8B%9C%EA%B0%84')); ?></b> : <?php echo h(isset($r['requested_check_out'])?$r['requested_check_out']:'-'); ?></div>
                    <div class='md:col-span-2'><b><?php echo h(attendance_text('%EC%9A%94%EC%B2%AD%20%EC%82%AC%EC%9C%A0')); ?></b> : <?php echo h(isset($r['reason'])?$r['reason']:'-'); ?></div>
                    <div><b><?php echo h(attendance_text('%EC%B2%98%EB%A6%AC%EC%9E%90')); ?></b> : <?php echo h(isset($r['reviewer_name'])&&$r['reviewer_name']!==''?$r['reviewer_name']:(isset($r['reviewed_by'])?$r['reviewed_by']:'-')); ?></div>
                    <div><b><?php echo h(attendance_text('%EC%B2%98%EB%A6%AC%EC%9D%BC%EC%8B%9C')); ?></b> : <?php echo h(isset($r['reviewed_at'])?$r['reviewed_at']:'-'); ?></div>
                    <div class='md:col-span-2'><b><?php echo h(attendance_text('%EB%B0%98%EB%A0%A4%EC%82%AC%EC%9C%A0')); ?></b> : <?php echo h(isset($r['reject_reason'])&&$r['reject_reason']!==''?$r['reject_reason']:'-'); ?></div>
                </div>
                <?php if($canManageAttendance && $st==='pending'): ?>
                    <div class='mt-3 flex flex-wrap items-center gap-2'>
                        <form method='post' action='?r=management/attendance_request_approve' style='display:inline-block;'>
                            <input type='hidden' name='_csrf' value='<?php echo h(csrf_token()); ?>'>
                            <input type='hidden' name='id' value='<?php echo isset($r['id'])?(int)$r['id']:0; ?>'>
                            <input type='hidden' name='return_url' value='<?php echo h($requestReturnUrl); ?>'>
                            <button type='submit' class='px-3 py-1 rounded-lg bg-emerald-600 text-white'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8')); ?></button>
                        </form>
                        <form method='post' action='?r=management/attendance_request_reject' style='display:inline-flex;gap:6px;align-items:center;'>
                            <input type='hidden' name='_csrf' value='<?php echo h(csrf_token()); ?>'>
                            <input type='hidden' name='id' value='<?php echo isset($r['id'])?(int)$r['id']:0; ?>'>
                            <input type='hidden' name='return_url' value='<?php echo h($requestReturnUrl); ?>'>
                            <input type='text' name='reject_reason' required placeholder='<?php echo h(attendance_text('%EB%B0%98%EB%A0%A4%20%EC%82%AC%EC%9C%A0')); ?>' class='px-2 py-1 rounded-lg border'>
                            <button type='submit' class='px-3 py-1 rounded-lg bg-rose-600 text-white'><?php echo h(attendance_text('%EB%B0%98%EB%A0%A4')); ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if(count($filteredReqs)===0): ?><div class='text-sm text-gray-500'><?php echo h(attendance_text('%EC%A1%B0%EA%B1%B4%EC%97%90%20%EB%A7%9E%EB%8A%94%20%EC%9A%94%EC%B2%AD%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></div><?php endif; ?>
    <?php endif; ?>

    <?php if($tab==='settings'): ?>
        <div class='space-y-6'>
            <form method='post' action='?r=management/attendance_settings_save' class='rounded-3xl border border-gray-200 bg-gray-50 p-5 space-y-6'>
                <input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'>

                <div class='flex flex-wrap items-center justify-between gap-3'>
                    <div>
                        <h4 class='text-xl font-extrabold text-gray-900'><?php echo h(attendance_text('%EA%B8%B0%EB%B3%B8%20%EA%B7%BC%ED%83%9C%20%EC%84%A4%EC%A0%95')); ?></h4>
                    </div>
                    <label class='inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-blue-200 font-bold text-blue-900'>
                        <input type='checkbox' name='attendance_geofence_enabled' value='1' <?php echo !empty($geofence['enabled']) ? 'checked' : ''; ?>>
                        <span><?php echo h(attendance_text('%EC%9C%84%EC%B9%98%20%EC%A0%9C%ED%95%9C%20%EC%82%AC%EC%9A%A9')); ?></span>
                    </label>
                </div>

                <div class='grid grid-cols-1 md:grid-cols-3 gap-4'>
                    <div class='rounded-2xl border border-gray-200 bg-white p-4'>
                        <div class='text-sm text-gray-500 mb-1'><?php echo h(attendance_text('%EC%A3%BC%20%EA%B8%B0%EB%B3%B8%20%EA%B7%BC%EB%AC%B4%EC%8B%9C%EA%B0%84')); ?></div>
                        <input class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='number' name='standard_weekly_hours' value='<?php echo h(isset($settings['standard_weekly_hours']) ? $settings['standard_weekly_hours'] : '40');?>'>
                    </div>
                    <div class='rounded-2xl border border-gray-200 bg-white p-4'>
                        <div class='text-sm text-gray-500 mb-1'><?php echo h(attendance_text('%EC%A3%BC%20%EC%B5%9C%EB%8C%80%20%EA%B7%BC%EB%AC%B4%EC%8B%9C%EA%B0%84')); ?></div>
                        <input class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='number' name='max_weekly_hours' value='<?php echo h(isset($settings['max_weekly_hours']) ? $settings['max_weekly_hours'] : '52');?>'>
                    </div>
                    <div class='rounded-2xl border border-gray-200 bg-white p-4'>
                        <div class='text-sm text-gray-500 mb-1'><?php echo h(attendance_text('%EC%9D%BC%20%EA%B3%B5%EC%A0%9C%20%EC%8B%9C%EA%B0%84%28%EB%B6%84%29')); ?></div>
                        <input class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='number' name='daily_break_deduct_minutes' value='<?php echo h(isset($settings['daily_break_deduct_minutes']) ? $settings['daily_break_deduct_minutes'] : '120');?>'>
                    </div>
                </div>

                <div class='flex justify-end'>
                    <button class='px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold'><?php echo h(attendance_text('%EA%B8%B0%EB%B3%B8%20%EC%84%A4%EC%A0%95%20%EC%A0%80%EC%9E%A5')); ?></button>
                </div>
            </form>

            <div class='rounded-3xl border border-blue-100 bg-blue-50/60 p-5'>
                <div class='flex flex-wrap items-center justify-between gap-3 mb-4'>
                    <div>
                        <h4 class='text-xl font-extrabold text-gray-900'><?php echo h(attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%20%EA%B4%80%EB%A6%AC')); ?></h4>
                    </div>
                    <div class='text-sm font-bold text-blue-900'>
                        <?php echo h(attendance_text('%ED%98%84%EC%9E%AC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98')); ?> <?php echo number_format(count($geofenceLocations)); ?><?php echo h(attendance_text('%EA%B0%9C')); ?>
                    </div>
                </div>

                <div class='overflow-x-auto rounded-2xl border border-blue-100 bg-white mb-5'>
                    <table class='min-w-full text-sm'>
                        <tr class='bg-blue-50 text-blue-900'>
                            <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EC%9C%84%EC%B9%98%EB%AA%85')); ?></th>
                            <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EA%B5%AC%EB%B6%84')); ?></th>
                            <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EA%B4%80%EB%A0%A8%20%ED%98%84%EC%9E%A5')); ?></th>
                            <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EC%A2%8C%ED%91%9C')); ?></th>
                            <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EB%B0%98%EA%B2%BD')); ?></th>
                            <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EC%83%81%ED%83%9C')); ?></th>
                            <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EA%B4%80%EB%A6%AC')); ?></th>
                        </tr>
                        <?php if (count($geofenceLocations) <= 0): ?>
                            <tr>
                                <td colspan='7' class='p-4 text-center text-gray-500'><?php echo h(attendance_text('%EB%93%B1%EB%A1%9D%EB%90%9C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EA%B0%80%20%EC%95%84%EC%A7%81%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($geofenceLocations as $location): ?>
                                <tr class='border-b last:border-b-0'>
                                    <td class='p-3 font-bold text-gray-900'><?php echo h(isset($location['name']) ? $location['name'] : ''); ?></td>
                                    <td class='p-3'><?php echo h(attendance_geofence_type_label(isset($location['location_type']) ? $location['location_type'] : 'office')); ?></td>
                                    <td class='p-3'><?php echo h(isset($location['project_name']) && trim((string)$location['project_name']) !== '' ? $location['project_name'] : '-'); ?></td>
                                    <td class='p-3 text-xs text-gray-600'><?php echo h(number_format((float)$location['lat'], 7)); ?> / <?php echo h(number_format((float)$location['lng'], 7)); ?></td>
                                    <td class='p-3'><?php echo number_format((float)$location['radius_m']); ?>m</td>
                                    <td class='p-3'><?php echo ((int)$location['is_active'] === 1) ? h(attendance_text('%EC%82%AC%EC%9A%A9%20%EC%A4%91')) : h(attendance_text('%EB%B9%84%ED%99%9C%EC%84%B1')); ?></td>
                                    <td class='p-3'>
                                        <div class='flex flex-wrap gap-2'>
                                            <a class='px-3 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-800 font-bold' href='<?php echo h($routeManage . '&tab=attendance&atab=settings&edit_geofence_id=' . (int)$location['id']); ?>'><?php echo h(attendance_text('%EC%88%98%EC%A0%95')); ?></a>
                                            <form method='post' action='?r=management/attendance_geofence_delete' onsubmit='return confirm("<?php echo h(attendance_text('%EC%9D%B4%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EB%A5%BC%20%EC%82%AD%EC%A0%9C%ED%95%A0%EA%B9%8C%EC%9A%94%3F')); ?>");'>
                                                <input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'>
                                                <input type='hidden' name='id' value='<?php echo (int)$location['id']; ?>'>
                                                <button type='submit' class='px-3 py-1 rounded-lg border border-rose-200 bg-rose-50 text-rose-700 font-bold'><?php echo h(attendance_text('%EC%82%AD%EC%A0%9C')); ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>

                <form method='post' action='?r=management/attendance_geofence_save' class='space-y-5'>
                    <input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'>
                    <input type='hidden' name='id' value='<?php echo $editGeofence ? (int)$editGeofence['id'] : 0; ?>'>

                    <div class='grid grid-cols-1 md:grid-cols-4 gap-4'>
                        <div class='md:col-span-2'>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EB%AA%85')); ?></div>
                            <input id='attendanceGeofenceName' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='text' name='name' value='<?php echo h($editGeofence ? $editGeofence['name'] : ''); ?>' placeholder='<?php echo h(attendance_text('%EC%98%88%3A%20%EB%B3%B8%EC%82%AC%2C%20%ED%98%89%EB%A0%A5%20%EC%82%AC%EB%AC%B4%EC%8B%A4%2C%20%ED%8F%89%ED%83%9D%20%ED%98%84%EC%9E%A5')); ?>' required>
                        </div>
                        <div>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%EA%B5%AC%EB%B6%84')); ?></div>
                            <select name='location_type' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white'>
                                <?php $selectedType = $editGeofence ? $editGeofence['location_type'] : 'office'; ?>
                                <option value='office' <?php echo $selectedType === 'office' ? 'selected' : ''; ?>><?php echo h(attendance_text('%EC%82%AC%EB%AC%B4%EC%8B%A4')); ?></option>
                                <option value='field' <?php echo $selectedType === 'field' ? 'selected' : ''; ?>><?php echo h(attendance_text('%ED%98%84%EC%9E%A5')); ?></option>
                                <option value='other' <?php echo $selectedType === 'other' ? 'selected' : ''; ?>><?php echo h(attendance_text('%EA%B8%B0%ED%83%80')); ?></option>
                            </select>
                        </div>
                        <div>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%ED%97%88%EC%9A%A9%20%EB%B0%98%EA%B2%BD%28m%29')); ?></div>
                            <input id='attendanceGeofenceRadius' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='number' min='1' step='1' name='radius_m' value='<?php echo h($editGeofence ? (string)$editGeofence['radius_m'] : '50'); ?>' required>
                        </div>
                    </div>

                    <div class='grid grid-cols-1 md:grid-cols-3 gap-4'>
                        <div>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%EA%B4%80%EB%A0%A8%20%ED%98%84%EC%9E%A5')); ?></div>
                            <select name='project_id' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white'>
                                <option value='0'><?php echo h(attendance_text('%EC%84%A0%ED%83%9D%20%EC%95%88%ED%95%A8')); ?></option>
                                <?php $selectedProjectId = $editGeofence ? (int)$editGeofence['project_id'] : 0; ?>
                                <?php foreach ($projectOptions as $projectRow): ?>
                                    <option value='<?php echo (int)$projectRow['id']; ?>' <?php echo ((int)$projectRow['id'] === $selectedProjectId) ? 'selected' : ''; ?>><?php echo h($projectRow['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%EC%9C%84%EB%8F%84')); ?></div>
                            <input id='attendanceGeofenceLat' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='text' name='lat' value='<?php echo h($editGeofence ? (string)$editGeofence['lat'] : ''); ?>' placeholder='37.5665000' required>
                        </div>
                        <div>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%EA%B2%BD%EB%8F%84')); ?></div>
                            <input id='attendanceGeofenceLng' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='text' name='lng' value='<?php echo h($editGeofence ? (string)$editGeofence['lng'] : ''); ?>' placeholder='126.9780000' required>
                        </div>
                    </div>

                    <div class='flex flex-wrap items-center gap-3'>
                        <label class='inline-flex items-center gap-2 text-sm font-bold text-gray-700'>
                            <input type='checkbox' name='is_active' value='1' <?php echo (!$editGeofence || (int)$editGeofence['is_active'] === 1) ? 'checked' : ''; ?>>
                            <span><?php echo h(attendance_text('%EC%82%AC%EC%9A%A9%20%EC%A4%91%20%EC%9C%84%EC%B9%98%EB%A1%9C%20%EB%91%90%EA%B8%B0')); ?></span>
                        </label>
                        <button type='button' id='attendanceUseCurrentLocation' class='px-4 py-2 rounded-xl border border-blue-200 bg-white text-blue-800 font-bold'><?php echo h(attendance_text('%ED%98%84%EC%9E%AC%20%EC%9C%84%EC%B9%98%EB%A1%9C%20%EC%B1%84%EC%9A%B0%EA%B8%B0')); ?></button>
                        <?php if ($editGeofence): ?>
                            <a class='px-4 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 font-bold' href='<?php echo h($routeManage . '&tab=attendance&atab=settings'); ?>'><?php echo h(attendance_text('%EC%83%88%20%EC%9C%84%EC%B9%98%20%EC%B6%94%EA%B0%80%EB%A1%9C%20%EC%A0%84%ED%99%98')); ?></a>
                        <?php endif; ?>
                    </div>

                    <div id='attendanceGeofenceMap' style='height:360px;border-radius:24px;border:1px solid #bfdbfe;background:#e5e7eb;overflow:hidden;'></div>
                    <div id='attendanceGeofenceHelp' class='mt-3 text-sm text-gray-600'><?php echo h(attendance_text('%EC%A7%80%EB%8F%84%EB%A5%BC%20%ED%81%B4%EB%A6%AD%ED%95%98%EC%97%AC%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%A2%8C%ED%91%9C%EB%A5%BC%20%EC%A0%95%ED%95%98%EC%84%B8%EC%9A%94.')); ?></div>

                    <div class='flex justify-end'>
                        <button class='px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold'><?php echo h($editGeofence ? attendance_text('%EC%9C%84%EC%B9%98%20%EC%88%98%EC%A0%95') : attendance_text('%EC%9C%84%EC%B9%98%20%EC%B6%94%EA%B0%80')); ?></button>
                    </div>
                </form>
            </div>

            <link rel='stylesheet' href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' crossorigin=''>
            <script src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js' crossorigin=''></script>
            <script>
            (function(){
                try{
                    if(typeof L === 'undefined') return;
                    var latInput = document.getElementById('attendanceGeofenceLat');
                    var lngInput = document.getElementById('attendanceGeofenceLng');
                    var radiusInput = document.getElementById('attendanceGeofenceRadius');
                    var help = document.getElementById('attendanceGeofenceHelp');
                    var locations = <?php echo json_encode($geofenceLocations); ?>;
                    var defaultLat = parseFloat(latInput && latInput.value ? latInput.value : '37.5665');
                    var defaultLng = parseFloat(lngInput && lngInput.value ? lngInput.value : '126.9780');
                    if(isNaN(defaultLat) && locations.length){ defaultLat = parseFloat(locations[0].lat); }
                    if(isNaN(defaultLng) && locations.length){ defaultLng = parseFloat(locations[0].lng); }
                    if(isNaN(defaultLat)) defaultLat = 37.5665;
                    if(isNaN(defaultLng)) defaultLng = 126.9780;
                    var map = L.map('attendanceGeofenceMap');
                    map.setView([defaultLat, defaultLng], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap'
                    }).addTo(map);
                    for(var i=0;i<locations.length;i++){
                        var item = locations[i];
                        if(!item || item.lat === null || item.lng === null) continue;
                        var markerText = (item.name || '') + ' / ' + (item.location_type || '') + ' / ' + (item.radius_m || 50) + 'm';
                        L.marker([parseFloat(item.lat), parseFloat(item.lng)]).addTo(map).bindPopup(markerText);
                        L.circle([parseFloat(item.lat), parseFloat(item.lng)], {
                            radius: parseFloat(item.radius_m || 50),
                            color: ((parseInt(item.is_active, 10) === 1) ? '#2563eb' : '#94a3b8'),
                            fillColor: ((parseInt(item.is_active, 10) === 1) ? '#60a5fa' : '#cbd5e1'),
                            fillOpacity: 0.14
                        }).addTo(map);
                    }
                    var draftMarker = null;
                    var draftCircle = null;
                    function currentRadius(){
                        var value = radiusInput ? parseFloat(radiusInput.value || '50') : 50;
                        if(isNaN(value) || value <= 0) value = 50;
                        return value;
                    }
                    function setDraftPoint(lat, lng, moveMap){
                        if(latInput) latInput.value = lat.toFixed(7);
                        if(lngInput) lngInput.value = lng.toFixed(7);
                        if(draftMarker){ map.removeLayer(draftMarker); }
                        if(draftCircle){ map.removeLayer(draftCircle); }
                        draftMarker = L.marker([lat, lng]).addTo(map);
                        draftCircle = L.circle([lat, lng], {
                            radius: currentRadius(),
                            color: '#0f172a',
                            fillColor: '#38bdf8',
                            fillOpacity: 0.18
                        }).addTo(map);
                        if(moveMap){ map.setView([lat, lng], 17); }
                        if(help){ help.innerHTML = '선택 좌표: ' + lat.toFixed(7) + ', ' + lng.toFixed(7) + ' / 반경 ' + currentRadius() + 'm'; }
                    }
                    map.on('click', function(e){
                        setDraftPoint(e.latlng.lat, e.latlng.lng, false);
                    });
                    if(radiusInput){
                        radiusInput.addEventListener('input', function(){
                            if(draftCircle){ draftCircle.setRadius(currentRadius()); }
                            if(help && latInput && lngInput && latInput.value !== '' && lngInput.value !== ''){
                                help.innerHTML = '선택 좌표: ' + latInput.value + ', ' + lngInput.value + ' / 반경 ' + currentRadius() + 'm';
                            }
                        });
                    }
                    var currentBtn = document.getElementById('attendanceUseCurrentLocation');
                    if(currentBtn){
                        currentBtn.addEventListener('click', function(){
                            if(!navigator.geolocation){
                                alert('브라우저에서 현재 위치 확인을 지원하지 않습니다.');
                                return;
                            }
                            navigator.geolocation.getCurrentPosition(function(pos){
                                setDraftPoint(pos.coords.latitude, pos.coords.longitude, true);
                            }, function(){
                                alert('현재 위치를 가져오지 못했습니다.');
                            }, {
                                enableHighAccuracy: true,
                                timeout: 12000,
                                maximumAge: 0
                            });
                        });
                    }
                    if(latInput && lngInput && latInput.value !== '' && lngInput.value !== '' && !isNaN(parseFloat(latInput.value)) && !isNaN(parseFloat(lngInput.value))){
                        setDraftPoint(parseFloat(latInput.value), parseFloat(lngInput.value), true);
                    }
                }catch(e){}
            })();
            </script>
            <?php if (false): ?>
            <form method='post' action='?r=management/attendance_settings_save' class='space-y-6'>
                <input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'>

                <div class='grid grid-cols-1 md:grid-cols-3 gap-4'>
                    <div class='rounded-2xl border border-gray-200 bg-gray-50 p-4'>
                        <div class='text-sm text-gray-500 mb-1'><?php echo h(attendance_text('%EC%A3%BC%20%EA%B8%B0%EB%B3%B8%20%EA%B7%BC%EB%AC%B4%EC%8B%9C%EA%B0%84')); ?></div>
                        <input class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='number' name='standard_weekly_hours' value='<?php echo h(isset($settings['standard_weekly_hours']) ? $settings['standard_weekly_hours'] : '40');?>'>
                    </div>
                    <div class='rounded-2xl border border-gray-200 bg-gray-50 p-4'>
                        <div class='text-sm text-gray-500 mb-1'><?php echo h(attendance_text('%EC%A3%BC%20%EC%B5%9C%EB%8C%80%20%EA%B7%BC%EB%AC%B4%EC%8B%9C%EA%B0%84')); ?></div>
                        <input class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='number' name='max_weekly_hours' value='<?php echo h(isset($settings['max_weekly_hours']) ? $settings['max_weekly_hours'] : '52');?>'>
                    </div>
                    <div class='rounded-2xl border border-gray-200 bg-gray-50 p-4'>
                        <div class='text-sm text-gray-500 mb-1'><?php echo h(attendance_text('%EC%9D%BC%20%EA%B3%B5%EC%A0%9C%20%EC%8B%9C%EA%B0%84%28%EB%B6%84%29')); ?></div>
                        <input class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='number' name='daily_break_deduct_minutes' value='<?php echo h(isset($settings['daily_break_deduct_minutes']) ? $settings['daily_break_deduct_minutes'] : '120');?>'>
                    </div>
                </div>

                <div class='rounded-3xl border border-blue-100 bg-blue-50/60 p-5'>
                    <div class='flex flex-wrap items-center justify-between gap-3 mb-4'>
                        <div>
                            <h4 class='text-xl font-extrabold text-gray-900'><?php echo h(attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%9C%84%EC%B9%98%20%EC%84%A4%EC%A0%95')); ?></h4>
                            <div class='text-sm text-blue-800 mt-1'><?php echo h(attendance_text('%EA%B4%80%EB%A6%AC%ED%8C%80%EC%97%90%EC%84%9C%20%EC%A7%80%EB%8F%84%EC%97%90%20%EA%B8%B0%EC%A4%80%20%EC%A2%8C%ED%91%9C%EB%A5%BC%20%EC%B0%8D%EC%9C%BC%EB%A9%B4%20%EC%A7%81%EC%9B%90%EC%9D%80%20%ED%95%B4%EB%8B%B9%20%EC%9C%84%EC%B9%98%20%EB%B0%98%EA%B2%BD%20%EC%95%88%EC%97%90%EC%84%9C%EB%A7%8C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%EC%9D%84%20%EB%93%B1%EB%A1%9D%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></div>
                        </div>
                        <label class='inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-blue-200 font-bold text-blue-900'>
                            <input type='checkbox' name='attendance_geofence_enabled' value='1' <?php echo !empty($geofence['enabled']) ? 'checked' : ''; ?>>
                            <span><?php echo h(attendance_text('%EC%9C%84%EC%B9%98%20%EC%A0%9C%ED%95%9C%20%EC%82%AC%EC%9A%A9')); ?></span>
                        </label>
                    </div>

                    <div class='grid grid-cols-1 md:grid-cols-3 gap-4 mb-4'>
                        <div class='md:col-span-2'>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%EA%B8%B0%EC%A4%80%20%EC%9C%84%EC%B9%98%EB%AA%85')); ?></div>
                            <input class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='text' name='attendance_geofence_name' value='<?php echo h(isset($settings['attendance_geofence_name']) ? $settings['attendance_geofence_name'] : '');?>' placeholder='<?php echo h(attendance_text('%EC%98%88%3A%20%EB%B3%B8%EC%82%AC%20%EC%B6%9C%EC%9E%85%EA%B5%AC%2C%20%ED%98%84%EC%9E%A5%20%EC%82%AC%EB%AC%B4%EC%8B%A4')); ?>'>
                        </div>
                        <div>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%ED%97%88%EC%9A%A9%20%EB%B0%98%EA%B2%BD%28m%29')); ?></div>
                            <input id='attendanceGeofenceRadius' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='number' min='1' step='1' name='attendance_geofence_radius_m' value='<?php echo h(isset($settings['attendance_geofence_radius_m']) && (string)$settings['attendance_geofence_radius_m'] !== '' ? $settings['attendance_geofence_radius_m'] : '50');?>'>
                        </div>
                    </div>

                    <div class='grid grid-cols-1 md:grid-cols-2 gap-4 mb-4'>
                        <div>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%EC%9C%84%EB%8F%84')); ?></div>
                            <input id='attendanceGeofenceLat' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='text' name='attendance_geofence_lat' value='<?php echo h(isset($settings['attendance_geofence_lat']) ? $settings['attendance_geofence_lat'] : '');?>' placeholder='37.5665'>
                        </div>
                        <div>
                            <div class='text-sm text-gray-600 mb-1'><?php echo h(attendance_text('%EA%B2%BD%EB%8F%84')); ?></div>
                            <input id='attendanceGeofenceLng' class='w-full px-3 py-2 rounded-xl border border-gray-200 bg-white' type='text' name='attendance_geofence_lng' value='<?php echo h(isset($settings['attendance_geofence_lng']) ? $settings['attendance_geofence_lng'] : '');?>' placeholder='126.9780'>
                        </div>
                    </div>

                    <div class='flex flex-wrap items-center gap-2 mb-3'>
                        <button type='button' id='attendanceUseCurrentLocation' class='px-4 py-2 rounded-xl border border-blue-200 bg-white text-blue-800 font-bold'><?php echo h(attendance_text('%ED%98%84%EC%9E%AC%20%EC%9C%84%EC%B9%98%EB%A1%9C%20%EC%B1%84%EC%9A%B0%EA%B8%B0')); ?></button>
                        <div class='text-sm text-gray-500'><?php echo h(attendance_text('%EC%A7%80%EB%8F%84%EB%A5%BC%20%ED%81%B4%EB%A6%AD%ED%95%98%EB%A9%B4%20%EC%A2%8C%ED%91%9C%EA%B0%80%20%EC%9E%90%EB%8F%99%EC%9C%BC%EB%A1%9C%20%EC%9E%85%EB%A0%A5%EB%90%A9%EB%8B%88%EB%8B%A4.%20%EA%B8%B0%EB%B3%B8%20%EB%B0%98%EA%B2%BD%EC%9D%80%2050m%EC%9E%85%EB%8B%88%EB%8B%A4.')); ?></div>
                    </div>

                    <div id='attendanceGeofenceMap' style='height:360px;border-radius:24px;border:1px solid #bfdbfe;background:#e5e7eb;overflow:hidden;'></div>
                    <div id='attendanceGeofenceHelp' class='mt-3 text-sm text-gray-600'><?php echo h(attendance_text('%EC%A7%80%EB%8F%84%EB%A5%BC%20%ED%81%B4%EB%A6%AD%ED%95%B4%EC%84%9C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EA%B8%B0%EC%A4%80%20%EC%A2%8C%ED%91%9C%EB%A5%BC%20%EC%A7%80%EC%A0%95%ED%95%98%EC%84%B8%EC%9A%94.')); ?></div>
                </div>

                <div class='flex justify-end'>
                    <button class='px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold'><?php echo h(attendance_text('%EC%84%A4%EC%A0%95%20%EC%A0%80%EC%9E%A5')); ?></button>
                </div>
            </form>

            <link rel='stylesheet' href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' crossorigin=''>
            <script src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js' crossorigin=''></script>
            <script>
            (function(){
                try{
                    if(typeof L === 'undefined') return;
                    var latInput = document.getElementById('attendanceGeofenceLat');
                    var lngInput = document.getElementById('attendanceGeofenceLng');
                    var radiusInput = document.getElementById('attendanceGeofenceRadius');
                    var help = document.getElementById('attendanceGeofenceHelp');
                    var defaultLat = parseFloat(latInput && latInput.value ? latInput.value : '37.5665');
                    var defaultLng = parseFloat(lngInput && lngInput.value ? lngInput.value : '126.9780');
                    if(isNaN(defaultLat)) defaultLat = 37.5665;
                    if(isNaN(defaultLng)) defaultLng = 126.9780;
                    var map = L.map('attendanceGeofenceMap');
                    map.setView([defaultLat, defaultLng], 16);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap'
                    }).addTo(map);
                    var marker = null;
                    var circle = null;
                    function currentRadius(){
                        var value = radiusInput ? parseFloat(radiusInput.value || '50') : 50;
                        if(isNaN(value) || value <= 0) value = 50;
                        return value;
                    }
                    function setPoint(lat, lng, moveMap){
                        if(latInput) latInput.value = lat.toFixed(7);
                        if(lngInput) lngInput.value = lng.toFixed(7);
                        if(marker){ map.removeLayer(marker); }
                        if(circle){ map.removeLayer(circle); }
                        marker = L.marker([lat, lng]).addTo(map);
                        circle = L.circle([lat, lng], {radius: currentRadius(), color: '#2563eb', fillColor: '#60a5fa', fillOpacity: 0.18}).addTo(map);
                        if(moveMap){ map.setView([lat, lng], 17); }
                        if(help){ help.innerHTML = '선택 좌표: ' + lat.toFixed(7) + ', ' + lng.toFixed(7) + ' / 반경 ' + currentRadius() + 'm'; }
                    }
                    map.on('click', function(e){
                        setPoint(e.latlng.lat, e.latlng.lng, false);
                    });
                    if(radiusInput){
                        radiusInput.addEventListener('input', function(){
                            if(circle){ circle.setRadius(currentRadius()); }
                            if(help && latInput && lngInput && latInput.value !== '' && lngInput.value !== ''){
                                help.innerHTML = '선택 좌표: ' + latInput.value + ', ' + lngInput.value + ' / 반경 ' + currentRadius() + 'm';
                            }
                        });
                    }
                    var currentBtn = document.getElementById('attendanceUseCurrentLocation');
                    if(currentBtn){
                        currentBtn.addEventListener('click', function(){
                            if(!navigator.geolocation){
                                alert('이 브라우저에서는 위치 확인을 지원하지 않습니다.');
                                return;
                            }
                            navigator.geolocation.getCurrentPosition(function(pos){
                                setPoint(pos.coords.latitude, pos.coords.longitude, true);
                            }, function(){
                                alert('현재 위치를 가져오지 못했습니다.');
                            }, {
                                enableHighAccuracy: true,
                                timeout: 12000,
                                maximumAge: 0
                            });
                        });
                    }
                    if(latInput && lngInput && latInput.value !== '' && lngInput.value !== '' && !isNaN(parseFloat(latInput.value)) && !isNaN(parseFloat(lngInput.value))){
                        setPoint(parseFloat(latInput.value), parseFloat(lngInput.value), true);
                    }
                }catch(e){}
            })();
            </script>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
