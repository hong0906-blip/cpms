<?php
use App\Core\Db;

require_once __DIR__ . '/../attendance/common.php';

if (!attendance_is_manager()) exit;
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) exit;

$pdo = Db::pdo();
$now = attendance_now();
$settingsRoute = '?r=' . attendance_text('%EA%B4%80%EB%A6%AC') . '&tab=attendance&atab=settings';

$saveMap = array(
    'standard_weekly_hours' => isset($_POST['standard_weekly_hours']) ? trim((string)$_POST['standard_weekly_hours']) : '40',
    'max_weekly_hours' => isset($_POST['max_weekly_hours']) ? trim((string)$_POST['max_weekly_hours']) : '52',
    'daily_break_deduct_minutes' => isset($_POST['daily_break_deduct_minutes']) ? trim((string)$_POST['daily_break_deduct_minutes']) : '120',
    'attendance_geofence_enabled' => isset($_POST['attendance_geofence_enabled']) ? '1' : '0'
);

if ($saveMap['attendance_geofence_enabled'] === '1') {
    $activeLocations = attendance_geofence_locations($pdo, false);
    if (count($activeLocations) <= 0) {
        flash_set('danger', attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%9C%84%EC%B9%98%20%EC%A0%9C%ED%95%9C%EC%9D%84%20%EC%82%AC%EC%9A%A9%ED%95%98%EB%A0%A4%EB%A9%B4%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EB%A5%BC%20%EC%B5%9C%EC%86%8C%201%EA%B0%9C%20%EC%9D%B4%EC%83%81%20%EC%B6%94%EA%B0%80%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ' . $settingsRoute);
        exit;
    }
}

$st = $pdo->prepare("REPLACE INTO cpms_attendance_settings(setting_key,setting_value,updated_at) VALUES(:k,:v,:u)");
foreach ($saveMap as $k => $v) {
    $st->execute(array(':k' => $k, ':v' => $v, ':u' => $now));
}

flash_set('success', attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%84%A4%EC%A0%95%EC%9D%B4%20%EC%A0%80%EC%9E%A5%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
header('Location: ' . $settingsRoute);
exit;
