<?php
use App\Core\Db;

require_once __DIR__ . '/../attendance/common.php';

$pdo = Db::pdo();
$settingsFallbackRoute = '?r=' . attendance_text('%EA%B4%80%EB%A6%AC') . '&tab=attendance&atab=monthly';
if (!attendance_can_manage_settings($pdo)) {
    header('Location: ' . $settingsFallbackRoute);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) exit;

$settingsRoute = '?r=' . attendance_text('%EA%B4%80%EB%A6%AC') . '&tab=attendance&atab=settings';
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$pdo || $id <= 0) {
    flash_set('danger', attendance_text('%EC%82%AD%EC%A0%9C%ED%95%A0%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EB%A5%BC%20%EC%B0%BE%EC%9D%84%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ' . $settingsRoute);
    exit;
}

try {
    $st = $pdo->prepare("DELETE FROM cpms_attendance_geofences WHERE id = :id");
    $st->execute(array(':id' => $id));
    flash_set('success', attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EA%B0%80%20%EC%82%AD%EC%A0%9C%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
} catch (Exception $e) {
    flash_set('danger', $e->getMessage());
}

header('Location: ' . $settingsRoute);
exit;
