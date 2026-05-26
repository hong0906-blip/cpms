<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../attendance/common.php';

$canManageAttendance = (Auth::isMaster() || attendance_is_manager());
if (!$canManageAttendance) {
    echo attendance_text('%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
    return;
}

$routeManage = '?r=' . attendance_text('%EA%B4%80%EB%A6%AC');
$routeDbSetup = '?r=db_setup_attendance';

$pdo = Db::pdo();
$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
$tab = isset($_GET['atab']) ? (string)$_GET['atab'] : 'daily';
$requestStatusFilter = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
$settings = attendance_settings($pdo);
$geofence = attendance_geofence_settings($pdo);
list($ws, $we) = attendance_week_range($date);

$daily = array();
$reqs = array();
$weekly = array();
$emps = array();
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

$positionEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'position') : false;
$hireDateEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'hire_date') : false;
$reviewedByEnabled = $pdo ? cpms_column_exists($pdo, 'cpms_attendance_requests', 'reviewed_by') : false;

if ($pdo) {
    $posSel = $positionEnabled ? 'position' : "'' AS position";
    $hireSel = $hireDateEnabled ? 'hire_date' : 'NULL AS hire_date';

    try {
        $emps = $pdo->query("SELECT id,name,department," . $posSel . "," . $hireSel . " FROM employees ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($emps)) $emps = array();
    } catch (Exception $e) {
        $attendanceErrors[] = attendance_text('%EC%A7%81%EC%9B%90%20%EB%AA%A9%EB%A1%9D%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.') . ' ' . $e->getMessage();
    }

    try {
        $projectSql = "SELECT id, name FROM cpms_projects";
        if (cpms_column_exists($pdo, 'cpms_projects', 'is_deleted')) {
            $projectSql .= " WHERE is_deleted = 0";
        }
        $projectSql .= " ORDER BY name ASC";
        $projectOptions = $pdo->query($projectSql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($projectOptions)) $projectOptions = array();
    } catch (Exception $e) {
        $projectOptions = array();
    }

    try {
        $st = $pdo->prepare("SELECT e.name,e.department," . ($positionEnabled ? 'e.position' : "'' AS position") . ",a.* FROM cpms_attendance_records a JOIN employees e ON e.id=a.employee_id WHERE a.work_date=:d ORDER BY e.name ASC");
        $st->execute(array(':d' => $date));
        $daily = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($daily)) $daily = array();
    } catch (Exception $e) {
        $attendanceErrors[] = attendance_text('%EC%9D%BC%EC%9D%BC%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%98%84%ED%99%A9%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.') . ' ' . $e->getMessage();
    }

    try {
        $selectReviewer = $reviewedByEnabled ? ', reviewer.name AS reviewer_name' : ", '' AS reviewer_name";
        $joinReviewer = $reviewedByEnabled ? ' LEFT JOIN employees reviewer ON reviewer.id = r.reviewed_by' : '';
        $sql = "SELECT r.*, e.name, e.department, " . ($positionEnabled ? 'e.position' : "'' AS position") . $selectReviewer . "
                FROM cpms_attendance_requests r
                JOIN employees e ON e.id = r.employee_id
                " . $joinReviewer . "
                ORDER BY r.id DESC
                LIMIT 100";
        $reqs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($reqs)) $reqs = array();
    } catch (Exception $e) {
        $attendanceErrors[] = attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%9A%94%EC%B2%AD%20%EB%AA%A9%EB%A1%9D%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.') . ' ' . $e->getMessage();
    }

    try {
        $st2 = $pdo->prepare("SELECT e.id,e.name,e.department,COALESCE(SUM(a.work_minutes),0) AS m
                              FROM employees e
                              LEFT JOIN cpms_attendance_records a
                                ON a.employee_id=e.id
                               AND a.work_date BETWEEN :s AND :e
                              GROUP BY e.id,e.name,e.department
                              ORDER BY m DESC, e.name ASC");
        $st2->execute(array(':s' => $ws, ':e' => $we));
        $weekly = $st2->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($weekly)) $weekly = array();
    } catch (Exception $e) {
        $attendanceErrors[] = attendance_text('%EC%A3%BC%EA%B0%84%20%ED%98%84%ED%99%A9%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.') . ' ' . $e->getMessage();
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

$totalRequests = count($reqs);
$pendingRequests = 0;
$approvedRequests = 0;
$rejectedRequests = 0;
$filteredReqs = array();
foreach ($reqs as $rq) {
    $stVal = isset($rq['status']) ? (string)$rq['status'] : '';
    if ($stVal === 'pending') $pendingRequests++;
    if ($stVal === 'approved') $approvedRequests++;
    if ($stVal === 'rejected') $rejectedRequests++;
    if ($requestStatusFilter === 'all' || $requestStatusFilter === '' || $requestStatusFilter === $stVal) {
        $filteredReqs[] = $rq;
    }
}
?>

<div class='mb-4 flex gap-2 flex-wrap'>
    <a class='px-3 py-2 rounded-2xl border bg-white' href='<?php echo h($routeManage); ?>'><?php echo h(attendance_text('%EA%B4%80%EB%A6%AC%EB%B6%80%20%EB%A9%94%EC%9D%B8')); ?></a>
    <a class='px-3 py-2 rounded-2xl border bg-white' href='<?php echo h($routeManage . '&tab=employees'); ?>'><?php echo h(attendance_text('%EC%A7%81%EC%9B%90%EB%AA%85%EB%B6%80')); ?></a>
    <a class='px-3 py-2 rounded-2xl border bg-white' href='<?php echo h($routeDbSetup); ?>'><?php echo h(attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20DB%20%EC%84%A4%EC%B9%98%2F%ED%99%95%EC%9D%B8')); ?></a>
</div>

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

<div class='bg-white/80 rounded-3xl shadow p-5 border border-gray-100 mb-4'>
    <h3 class='text-xl font-extrabold mb-4'><?php echo h(attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%2F%20%EA%B7%BC%ED%83%9C%EA%B4%80%EB%A6%AC')); ?></h3>

    <div class='mb-4 flex flex-wrap gap-2'>
        <a class='px-3 py-2 rounded-xl border <?php echo $tab==='daily'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=daily'); ?>'><?php echo h(attendance_text('%EC%9D%BC%EC%9D%BC%20%ED%98%84%ED%99%A9')); ?></a>
        <a class='px-3 py-2 rounded-xl border <?php echo $tab==='requests'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests'); ?>'><?php echo h(attendance_text('%EC%9A%94%EC%B2%AD%20%EA%B4%80%EB%A6%AC')); ?></a>
        <a class='px-3 py-2 rounded-xl border <?php echo $tab==='weekly'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=weekly'); ?>'><?php echo h(attendance_text('%EC%A3%BC%EA%B0%84%20%ED%98%84%ED%99%A9')); ?></a>
        <a class='px-3 py-2 rounded-xl border <?php echo $tab==='settings'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=settings'); ?>'><?php echo h(attendance_text('%EC%84%A4%EC%A0%95')); ?></a>
    </div>

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
            <div class='rounded-xl border p-3 bg-gray-50'><div class='text-gray-500'><?php echo h(attendance_text('%EC%A0%84%EC%B2%B4%20%EC%9A%94%EC%B2%AD')); ?></div><div class='text-xl font-bold'><?php echo (int)$totalRequests; ?>건</div></div>
            <div class='rounded-xl border p-3 bg-amber-50'><div class='text-amber-700'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8%EB%8C%80%EA%B8%B0')); ?></div><div class='text-xl font-bold'><?php echo (int)$pendingRequests; ?>건</div></div>
            <div class='rounded-xl border p-3 bg-emerald-50'><div class='text-emerald-700'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8%EC%99%84%EB%A3%8C')); ?></div><div class='text-xl font-bold'><?php echo (int)$approvedRequests; ?>건</div></div>
            <div class='rounded-xl border p-3 bg-rose-50'><div class='text-rose-700'><?php echo h(attendance_text('%EB%B0%98%EB%A0%A4')); ?></div><div class='text-xl font-bold'><?php echo (int)$rejectedRequests; ?>건</div></div>
        </div>

        <div class='mb-4 flex gap-2 text-sm flex-wrap'>
            <a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='all'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests&status=all'); ?>'><?php echo h(attendance_text('%EC%A0%84%EC%B2%B4')); ?></a>
            <a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='pending'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests&status=pending'); ?>'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8%EB%8C%80%EA%B8%B0')); ?></a>
            <a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='approved'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests&status=approved'); ?>'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8%EC%99%84%EB%A3%8C')); ?></a>
            <a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='rejected'?'bg-gray-900 text-white':'bg-white';?>' href='<?php echo h($routeManage . '&tab=attendance&atab=requests&status=rejected'); ?>'><?php echo h(attendance_text('%EB%B0%98%EB%A0%A4')); ?></a>
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
                            <button type='submit' class='px-3 py-1 rounded-lg bg-emerald-600 text-white'><?php echo h(attendance_text('%EC%8A%B9%EC%9D%B8')); ?></button>
                        </form>
                        <form method='post' action='?r=management/attendance_request_reject' style='display:inline-flex;gap:6px;align-items:center;'>
                            <input type='hidden' name='_csrf' value='<?php echo h(csrf_token()); ?>'>
                            <input type='hidden' name='id' value='<?php echo isset($r['id'])?(int)$r['id']:0; ?>'>
                            <input type='text' name='reject_reason' required placeholder='<?php echo h(attendance_text('%EB%B0%98%EB%A0%A4%20%EC%82%AC%EC%9C%A0')); ?>' class='px-2 py-1 rounded-lg border'>
                            <button type='submit' class='px-3 py-1 rounded-lg bg-rose-600 text-white'><?php echo h(attendance_text('%EB%B0%98%EB%A0%A4')); ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if(count($filteredReqs)===0): ?><div class='text-sm text-gray-500'><?php echo h(attendance_text('%EC%A1%B0%EA%B1%B4%EC%97%90%20%EB%A7%9E%EB%8A%94%20%EC%9A%94%EC%B2%AD%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></div><?php endif; ?>
    <?php endif; ?>

    <?php if($tab==='weekly'): ?>
        <div class='overflow-x-auto rounded-2xl border border-gray-200'>
            <table class='min-w-full text-sm'>
                <tr class='bg-gray-50'>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EC%A7%81%EC%9B%90%EB%AA%85')); ?></th>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EB%B6%80%EC%84%9C')); ?></th>
                    <th class='p-3 text-left border-b'><?php echo h(attendance_text('%EC%A3%BC%EA%B0%84%20%EB%88%84%EC%A0%81')); ?></th>
                </tr>
                <?php foreach($weekly as $r): ?>
                    <tr class='border-b last:border-b-0'>
                        <td class='p-3'><?php echo h(isset($r['name'])?$r['name']:''); ?></td>
                        <td class='p-3'><?php echo h(isset($r['department'])?$r['department']:''); ?></td>
                        <td class='p-3'><?php echo number_format(((float)(isset($r['m'])?$r['m']:0))/60,2); ?>h</td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <?php if($tab==='settings'): ?>
        <div class='space-y-6'>
            <form method='post' action='?r=management/attendance_settings_save' class='rounded-3xl border border-gray-200 bg-gray-50 p-5 space-y-6'>
                <input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'>

                <div class='flex flex-wrap items-center justify-between gap-3'>
                    <div>
                        <h4 class='text-xl font-extrabold text-gray-900'><?php echo h(attendance_text('%EA%B8%B0%EB%B3%B8%20%EA%B7%BC%ED%83%9C%20%EC%84%A4%EC%A0%95')); ?></h4>
                        <div class='text-sm text-gray-600 mt-1'><?php echo h(attendance_text('%EA%B7%BC%EB%AC%B4%20%EC%8B%9C%EA%B0%84%EA%B3%BC%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%9C%84%EC%B9%98%20%EC%A0%9C%ED%95%9C%20%EC%82%AC%EC%9A%A9%20%EC%97%AC%EB%B6%80%EB%A5%BC%20%EC%84%A4%EC%A0%95%ED%95%A9%EB%8B%88%EB%8B%A4.')); ?></div>
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
                        <div class='text-sm text-blue-800 mt-1'><?php echo h(attendance_text('%EC%82%AC%EB%AC%B4%EC%8B%A4%2C%20%ED%98%84%EC%9E%A5%2C%20%EA%B8%B0%ED%83%80%20%EC%9E%A5%EC%86%8C%EB%A5%BC%20%EC%97%AC%EB%9F%AC%20%EA%B0%9C%20%EB%93%B1%EB%A1%9D%ED%95%B4%EB%91%90%EA%B3%A0%20%ED%95%B4%EB%8B%B9%20%EB%B0%98%EA%B2%BD%20%EC%95%88%EC%97%90%EC%84%9C%EB%A7%8C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%EC%9D%84%20%ED%97%88%EC%9A%A9%ED%95%A9%EB%8B%88%EB%8B%A4.')); ?></div>
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

<div class='bg-white/80 rounded-3xl shadow p-5 border border-gray-100 mb-4'>
    <h3 class='text-xl font-extrabold'><?php echo h(attendance_text('%ED%9C%B4%EA%B0%80%20%EB%93%B1%EB%A1%9D%28%EA%B4%80%EB%A6%AC%EB%B6%80%29')); ?></h3>
    <form method='post' action='?r=management/leave_save' class='space-y-2'>
        <input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'>
        <select name='employee_id' required class='w-full px-3 py-2 rounded-xl border border-gray-200'>
            <option value=''><?php echo h(attendance_text('%EC%A7%81%EC%9B%90%20%EC%84%A0%ED%83%9D')); ?></option>
            <?php foreach($emps as $e): ?>
                <option value='<?php echo (int)$e['id'];?>'><?php echo h($e['name']);?></option>
            <?php endforeach; ?>
        </select>
        <input type='date' name='leave_date' value='<?php echo h($date);?>' required class='w-full px-3 py-2 rounded-xl border border-gray-200'>
        <select name='leave_type' required class='w-full px-3 py-2 rounded-xl border border-gray-200'>
            <option value='월차'><?php echo h(attendance_text('%EC%9B%94%EC%B0%A8')); ?></option>
            <option value='연차'><?php echo h(attendance_text('%EC%97%B0%EC%B0%A8')); ?></option>
            <option value='월차반차'><?php echo h(attendance_text('%EC%9B%94%EC%B0%A8%EB%B0%98%EC%B0%A8')); ?></option>
            <option value='연차반차'><?php echo h(attendance_text('%EC%97%B0%EC%B0%A8%EB%B0%98%EC%B0%A8')); ?></option>
            <option value='대체휴무'><?php echo h(attendance_text('%EB%8C%80%EC%B2%B4%ED%9C%B4%EB%AC%B4')); ?></option>
            <option value='기타휴무'><?php echo h(attendance_text('%EA%B8%B0%ED%83%80%ED%9C%B4%EB%AC%B4')); ?></option>
        </select>
        <input type='number' step='0.5' min='0' name='leave_amount' placeholder='<?php echo h(attendance_text('%ED%9C%B4%EA%B0%80%20%EC%9D%BC%EC%88%98%28%EB%B9%84%EC%9A%B0%EB%A9%B4%20%EC%9E%90%EB%8F%99%29')); ?>' class='w-full px-3 py-2 rounded-xl border border-gray-200'>
        <input type='text' name='reason' placeholder='<?php echo h(attendance_text('%EC%82%AC%EC%9C%A0')); ?>' class='w-full px-3 py-2 rounded-xl border border-gray-200'>
        <button class='px-3 py-2 rounded-xl bg-blue-600 text-white'><?php echo h(attendance_text('%EB%93%B1%EB%A1%9D')); ?></button>
    </form>
    <div class='text-xs text-gray-500 mt-2'><?php echo h(attendance_text('%EB%B0%98%EC%B0%A8%20%EC%B0%A8%EA%B0%90%EC%9D%80%20%EC%9B%94%EC%B0%A8%EB%B0%98%EC%B0%A8%20%2F%20%EC%97%B0%EC%B0%A8%EB%B0%98%EC%B0%A8%EB%A5%BC%20%EC%84%A0%ED%83%9D%ED%95%98%EB%A9%B4%200.5%EC%9D%BC%EB%A1%9C%20%EB%B0%98%EC%98%81%EB%90%A9%EB%8B%88%EB%8B%A4.')); ?></div>
</div>
