<?php
/**
 * C:\www\cpms\app\views\construction\schedule_move.php
 * - 공사: 기존 공정 드래그 이동 시 날짜(start/end) 저장 전용 액션 (POST)
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(array('success' => false, 'message' => '로그인이 필요합니다.'));
    exit;
}

if (!Auth::canManageConstruction()) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => '권한이 없습니다.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => 'Method Not Allowed'));
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => '보안 토큰이 유효하지 않습니다.'));
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$startDate = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
$endDate = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';

if ($projectId <= 0 || $taskId <= 0) {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => '요청 값이 올바르지 않습니다.'));
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => '날짜 형식이 올바르지 않습니다.'));
    exit;
}

$startTs = strtotime($startDate . ' 00:00:00');
$endTs = strtotime($endDate . ' 00:00:00');
if ($startTs === false || $endTs === false) {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => '날짜 계산에 실패했습니다.'));
    exit;
}

if ($endTs < $startTs) {
    $endTs = $startTs;
    $endDate = date('Y-m-d', $endTs);
}

$pdo = Db::pdo();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => 'DB 연결 실패'));
    exit;
}

try {
    $st = $pdo->prepare('UPDATE cpms_schedule_tasks SET start_date = :sd, end_date = :ed WHERE id = :id AND project_id = :pid');
    $st->bindValue(':sd', $startDate, \PDO::PARAM_STR);
    $st->bindValue(':ed', $endDate, \PDO::PARAM_STR);
    $st->bindValue(':id', $taskId, \PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $st->execute();

    if ($st->rowCount() < 1) {
        echo json_encode(array('success' => false, 'message' => '변경 대상 공정을 찾지 못했습니다.'));
        exit;
    }

    echo json_encode(array(
        'success' => true,
        'message' => '저장됨',
        'start_date' => $startDate,
        'end_date' => $endDate
    ));
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => '저장 실패: ' . $e->getMessage()));
    exit;
}