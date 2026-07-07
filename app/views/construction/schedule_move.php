<?php
/**
 * C:\www\cpms\app\views\construction\schedule_move.php
 * - schedule_move JSON 저장
 * - 기존 공정 드래그 자동 저장 전용 액션 (POST)
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/schedule_auto_progress_helper.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: application/json; charset=utf-8');

function schedule_move_json_exit($ok, $message, $extra, $code)
{
    if (!headers_sent()) {
        http_response_code((int)$code);
    }
    $resp = array('ok' => (bool)$ok, 'message' => (string)$message);
    if (is_array($extra)) {
        foreach ($extra as $k => $v) {
            $resp[$k] = $v;
        }
    }
    echo json_encode($resp);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        schedule_move_json_exit(false, 'POST 요청만 허용됩니다.', array(), 405);
    }

    if (!Auth::check()) {
        schedule_move_json_exit(false, '로그인이 필요합니다.', array(), 401);
    }

    if (!Auth::canManageConstruction()) {
        schedule_move_json_exit(false, '권한이 없습니다.', array(), 403);
    }

    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        schedule_move_json_exit(false, '보안 토큰이 유효하지 않습니다.', array(), 400);
    }

    $projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $startDate = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
    $endDate = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';

    if ($projectId <= 0) {
        schedule_move_json_exit(false, 'project_id 값이 올바르지 않습니다.', array(), 400);
    }
    if ($taskId <= 0) {
        schedule_move_json_exit(false, 'task_id 값이 올바르지 않습니다.', array(), 400);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        schedule_move_json_exit(false, '날짜 형식은 YYYY-MM-DD만 허용됩니다.', array(), 400);
    }

    $startTs = strtotime($startDate . ' 00:00:00');
    $endTs = strtotime($endDate . ' 00:00:00');
    if ($startTs === false || $endTs === false) {
        schedule_move_json_exit(false, '날짜 값이 유효하지 않습니다.', array(), 400);
    }

    if ($endTs < $startTs) {
        $endTs = $startTs;
        $endDate = date('Y-m-d', $endTs);
    }

    $pdo = Db::pdo();
    if (!$pdo) {
        schedule_move_json_exit(false, 'DB 연결 실패', array(), 500);
    }

    $check = $pdo->prepare('SELECT id FROM cpms_schedule_tasks WHERE id = :task_id AND project_id = :project_id LIMIT 1');
    $check->bindValue(':task_id', $taskId, \PDO::PARAM_INT);
    $check->bindValue(':project_id', $projectId, \PDO::PARAM_INT);
    $check->execute();
    $exists = $check->fetch();
    if (!$exists) {
        schedule_move_json_exit(false, '해당 프로젝트의 공정을 찾지 못했습니다.', array(), 404);
    }

    $st = $pdo->prepare('UPDATE cpms_schedule_tasks SET start_date = :start_date, end_date = :end_date WHERE id = :task_id AND project_id = :project_id');
    $st->bindValue(':start_date', $startDate, \PDO::PARAM_STR);
    $st->bindValue(':end_date', $endDate, \PDO::PARAM_STR);
    $st->bindValue(':task_id', $taskId, \PDO::PARAM_INT);
    $st->bindValue(':project_id', $projectId, \PDO::PARAM_INT);
    $st->execute();
    cpms_schedule_apply_auto_progress($pdo, $projectId);

    schedule_move_json_exit(true, '저장되었습니다.', array(
        'task_id' => $taskId,
        'start_date' => $startDate,
        'end_date' => $endDate
    ), 200);
} catch (Exception $e) {
    schedule_move_json_exit(false, '저장 실패: ' . $e->getMessage(), array(), 200);
}
