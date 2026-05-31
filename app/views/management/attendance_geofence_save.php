<?php
use App\Core\Db;

require_once __DIR__ . '/../attendance/common.php';

if (!attendance_is_manager()) exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) exit;

$pdo = Db::pdo();
$settingsRoute = '?r=' . attendance_text('%EA%B4%80%EB%A6%AC') . '&tab=attendance&atab=settings';

if (!$pdo) {
    flash_set('danger', attendance_text('%EB%8D%B0%EC%9D%B4%ED%84%B0%EB%B2%A0%EC%9D%B4%EC%8A%A4%20%EC%97%B0%EA%B2%B0%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ' . $settingsRoute);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$locationType = isset($_POST['location_type']) ? trim((string)$_POST['location_type']) : 'office';
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$radius = isset($_POST['radius_m']) ? (int)$_POST['radius_m'] : 50;
$lat = attendance_parse_coordinate(isset($_POST['lat']) ? $_POST['lat'] : '');
$lng = attendance_parse_coordinate(isset($_POST['lng']) ? $_POST['lng'] : '');
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($name === '') {
    flash_set('danger', attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EB%AA%85%EC%9D%84%20%EC%9E%85%EB%A0%A5%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
    header('Location: ' . $settingsRoute);
    exit;
}
if ($locationType !== 'office' && $locationType !== 'field' && $locationType !== 'other') {
    $locationType = 'other';
}
if ($lat === null || $lng === null) {
    flash_set('danger', attendance_text('%EC%A7%80%EB%8F%84%EC%97%90%EC%84%9C%20%EC%A2%8C%ED%91%9C%EB%A5%BC%20%EC%B0%8D%EA%B1%B0%EB%82%98%20%EC%9C%84%EB%8F%84%2F%EA%B2%BD%EB%8F%84%EB%A5%BC%20%EC%98%AC%EB%B0%94%EB%A5%B4%EA%B2%8C%20%EC%9E%85%EB%A0%A5%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
    header('Location: ' . $settingsRoute);
    exit;
}
if ($radius <= 0) {
    $radius = 50;
}

$projectName = '';
if ($projectId > 0) {
    try {
        $stProject = $pdo->prepare("SELECT name FROM cpms_projects WHERE id = :id LIMIT 1");
        $stProject->execute(array(':id' => $projectId));
        $projectName = trim((string)$stProject->fetchColumn());
    } catch (Exception $e) {
        $projectName = '';
    }
}

$now = attendance_now();

try {
    if (!attendance_table_exists($pdo, 'cpms_attendance_geofences')) {
        throw new Exception(attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%20%ED%85%8C%EC%9D%B4%EB%B8%94%EC%9D%B4%20%EC%95%84%EC%A7%81%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.%20DB%20%EC%84%A4%EC%B9%98%2F%ED%99%95%EC%9D%B8%EC%9D%84%20%EB%A8%BC%EC%A0%80%20%EC%8B%A4%ED%96%89%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
    }
    if ($id > 0) {
        $st = $pdo->prepare("UPDATE cpms_attendance_geofences
                                SET name = :name,
                                    location_type = :location_type,
                                    project_id = :project_id,
                                    project_name = :project_name,
                                    lat = :lat,
                                    lng = :lng,
                                    radius_m = :radius_m,
                                    is_active = :is_active,
                                    updated_at = :updated_at
                              WHERE id = :id");
        $st->execute(array(
            ':name' => $name,
            ':location_type' => $locationType,
            ':project_id' => $projectId > 0 ? $projectId : null,
            ':project_name' => $projectName,
            ':lat' => $lat,
            ':lng' => $lng,
            ':radius_m' => $radius,
            ':is_active' => $isActive,
            ':updated_at' => $now,
            ':id' => $id
        ));
        flash_set('success', attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EA%B0%80%20%EC%88%98%EC%A0%95%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    } else {
        $st = $pdo->prepare("INSERT INTO cpms_attendance_geofences(name, location_type, project_id, project_name, lat, lng, radius_m, is_active, created_at, updated_at)
                             VALUES(:name, :location_type, :project_id, :project_name, :lat, :lng, :radius_m, :is_active, :created_at, :updated_at)");
        $st->execute(array(
            ':name' => $name,
            ':location_type' => $locationType,
            ':project_id' => $projectId > 0 ? $projectId : null,
            ':project_name' => $projectName,
            ':lat' => $lat,
            ':lng' => $lng,
            ':radius_m' => $radius,
            ':is_active' => $isActive,
            ':created_at' => $now,
            ':updated_at' => $now
        ));
        flash_set('success', attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EA%B0%80%20%EC%B6%94%EA%B0%80%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    }
    if ($isActive === 1) {
        $stSetting = $pdo->prepare("REPLACE INTO cpms_attendance_settings(setting_key, setting_value, updated_at) VALUES(:k, :v, :u)");
        $stSetting->execute(array(
            ':k' => 'attendance_geofence_enabled',
            ':v' => '1',
            ':u' => $now
        ));
    }
} catch (Exception $e) {
    flash_set('danger', $e->getMessage());
}

header('Location: ' . $settingsRoute);
exit;
