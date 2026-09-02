<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * [업무요청 - 퇴사자 자동 완료]
 *
 * 이미 퇴사(비활성) 처리된 직원에게 남아 있는 미완료 업무를 자동 완료합니다.
 * - 대상: 담당자 employees.is_active = 0
 * - 제외: 이미 완료(done), 취소(cancelled)된 업무
 * - 처리 사유는 completed_memo 및 업무 로그에 남깁니다.
 * - 그룹 업무라도 다른 재직 담당자의 업무까지 같이 완료하지 않습니다.
 *
 * 이 함수는 기존 deferred_sync가 실행될 때 함께 호출되므로,
 * 과거에 퇴사자가 남긴 진행중/대기 업무도 자동으로 정리됩니다.
 * PHP 5.6 호환 문법만 사용합니다.
 */
function cpms_tasks_complete_inactive_assignee_tasks($pdo)
{
    $result = array(
        'checked' => 0,
        'completed' => 0,
        'failed' => 0
    );

    if (!$pdo) return $result;

    /* 필요한 테이블/컬럼이 없는 구형 환경에서는 기존 기능에 영향 없이 종료 */
    if (!cpms_tasks_table_exists($pdo, 'cpms_tasks')) return $result;
    if (!cpms_tasks_table_exists($pdo, 'employees')) return $result;
    if (!cpms_tasks_column_exists($pdo, 'employees', 'is_active')) return $result;
    if (!cpms_tasks_column_exists($pdo, 'cpms_tasks', 'assignee_employee_id')) return $result;
    if (!cpms_tasks_column_exists($pdo, 'cpms_tasks', 'status')) return $result;

    $now = cpms_tasks_now();
    $memo = '담당자 퇴사로 자동 완료 처리';
    $systemActor = array(
        'id' => 0,
        'name' => 'CPMS 자동처리',
        'email' => ''
    );

    try {
        /*
         * 한 번에 최대 500건만 처리합니다.
         * 일반적인 사용에서는 충분하며, 혹시 과거 데이터가 많아도
         * 다음 자동 호출에서 나머지가 계속 처리됩니다.
         */
        $sql = "SELECT t.id, t.status, t.assignee_employee_id, t.assignee_name
                FROM cpms_tasks t
                INNER JOIN employees e ON e.id = t.assignee_employee_id
                WHERE e.is_active = 0
                  AND t.assignee_employee_id IS NOT NULL
                  AND t.assignee_employee_id > 0
                  AND (t.status IS NULL OR t.status NOT IN ('done', 'cancelled'))
                ORDER BY t.id ASC
                LIMIT 500";

        $st = $pdo->query($sql);
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($rows)) $rows = array();

        $result['checked'] = count($rows);
        if (count($rows) === 0) return $result;

        $updateSql = "UPDATE cpms_tasks
                      SET status = 'done',
                          completed_at = :completed_at,
                          completed_by = NULL,
                          completed_memo = :completed_memo,
                          updated_at = :updated_at
                      WHERE id = :id
                        AND (status IS NULL OR status NOT IN ('done', 'cancelled'))";
        $update = $pdo->prepare($updateSql);

        for ($i = 0; $i < count($rows); $i++) {
            $taskId = isset($rows[$i]['id']) ? (int)$rows[$i]['id'] : 0;
            if ($taskId <= 0) continue;

            $oldStatus = isset($rows[$i]['status']) ? (string)$rows[$i]['status'] : '';
            $assigneeName = isset($rows[$i]['assignee_name']) ? trim((string)$rows[$i]['assignee_name']) : '';
            $logMessage = $memo;
            if ($assigneeName !== '') $logMessage .= ' - 담당자: ' . $assigneeName;

            try {
                $ok = $update->execute(array(
                    ':completed_at' => $now,
                    ':completed_memo' => $memo,
                    ':updated_at' => $now,
                    ':id' => $taskId
                ));

                if (!$ok) {
                    $result['failed']++;
                    continue;
                }

                /* 다른 요청에서 먼저 완료된 경우에는 중복 로그를 남기지 않음 */
                if ($update->rowCount() < 1) continue;

                $result['completed']++;

                /* 기존 업무 이력 기능을 그대로 이용해 자동 처리 이유를 남김 */
                if (function_exists('cpms_tasks_insert_log')) {
                    cpms_tasks_insert_log(
                        $pdo,
                        $taskId,
                        $systemActor,
                        'auto_completed_inactive_assignee',
                        $logMessage,
                        $oldStatus !== '' ? $oldStatus : null,
                        'done'
                    );
                }
            } catch (Exception $taskException) {
                $result['failed']++;
                error_log('[tasks_deferred_sync] inactive assignee task auto-complete failed. task_id=' . $taskId . ' message=' . $taskException->getMessage());
            }
        }
    } catch (Exception $e) {
        /* 자동 정리 실패가 기존 알림/Drive 동기화 기능을 막지 않도록 오류만 기록 */
        error_log('[tasks_deferred_sync] inactive assignee cleanup failed: ' . $e->getMessage());
    }

    return $result;
}

function cpms_tasks_deferred_json($payload, $statusCode)
{
    http_response_code((int)$statusCode);
    echo json_encode($payload);
    exit;
}

if (!Auth::check()) {
    cpms_tasks_deferred_json(array('ok' => false, 'message' => 'Login required.'), 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_tasks_deferred_json(array('ok' => false, 'message' => 'Method not allowed.'), 405);
}
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    cpms_tasks_deferred_json(array('ok' => false, 'message' => 'Invalid security token.'), 403);
}

$pdo = Db::pdo();
$currentEmployee = cpms_tasks_current_employee($pdo);
$currentEmployeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
if (!$pdo || $currentEmployeeId <= 0) {
    cpms_tasks_deferred_json(array('ok' => false, 'message' => 'Employee was not found.'), 404);
}

$setupResults = array();
if (!cpms_tasks_schema_ready_quick($pdo)) cpms_tasks_ensure_schema($pdo, $setupResults);
if (function_exists('session_write_close')) @session_write_close();
@ignore_user_abort(true);
@set_time_limit(240);

/* [업무요청] 퇴사한 담당자의 미완료 업무를 먼저 자동 완료 처리 */
$inactiveAssigneeCleanup = cpms_tasks_complete_inactive_assignee_tasks($pdo);

/* 기존 deferred_sync 기능은 그대로 유지 */
$notificationResult = cpms_tasks_process_deferred_notifications($pdo, 10);
$driveResult = cpms_tasks_process_pending_drive_files($pdo, $currentEmployeeId, 3);
$remaining = (isset($driveResult['remaining']) ? (int)$driveResult['remaining'] : 0)
    + (isset($notificationResult['remaining']) ? (int)$notificationResult['remaining'] : 0);

cpms_tasks_deferred_json(array(
    'ok' => true,
    'remaining' => $remaining,
    'drive' => $driveResult,
    'notifications' => $notificationResult,
    'inactive_assignee_cleanup' => $inactiveAssigneeCleanup
), 200);
