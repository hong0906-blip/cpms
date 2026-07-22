<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('danger', '잘못된 요청입니다.');
    cpms_tasks_redirect_back();
}
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    cpms_tasks_redirect_back();
}

$pdo = Db::pdo();
$setupResults = array();
cpms_tasks_ensure_schema($pdo, $setupResults);
$currentEmployee = cpms_tasks_current_employee($pdo);
$currentEmployeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
$content = trim((string)(isset($_POST['content']) ? $_POST['content'] : ''));
$message = trim((string)(isset($_POST['message']) ? $_POST['message'] : ''));

if ($title === '') {
    flash_set('danger', '업무 제목을 입력해주세요.');
    cpms_tasks_redirect_back();
}

$task = cpms_tasks_find_task($pdo, $taskId);
if (!$task || !cpms_tasks_can_update_content($task, $currentEmployeeId)) {
    flash_set('danger', '업무 수정 권한이 없습니다.');
    cpms_tasks_redirect_back();
}
if (isset($task['status']) && in_array((string)$task['status'], array('done', 'cancelled'), true)) {
    flash_set('danger', '완료 또는 취소된 업무는 수정할 수 없습니다.');
    cpms_tasks_redirect_back();
}

try {
    if (cpms_tasks_update_content($pdo, $task, $currentEmployee, $title, $content, $message, cpms_tasks_now())) {
        $savedFileCount = 0;
        if (isset($_FILES['attachments'])) {
            $savedFiles = cpms_tasks_save_uploaded_files($pdo, $taskId, $_FILES['attachments'], $currentEmployeeId, 'request');
            if (is_array($savedFiles) && count($savedFiles) > 0) {
                $savedFileCount = count($savedFiles);
                $groupKey = isset($task['group_key']) ? trim((string)$task['group_key']) : '';
                if (cpms_tasks_is_request_group_key($groupKey)) {
                    $groupSummary = cpms_tasks_completion_group_summary($pdo, $task);
                    $groupRows = isset($groupSummary['rows']) ? $groupSummary['rows'] : array();
                    if (is_array($groupRows)) {
                        for ($i = 0; $i < count($groupRows); $i++) {
                            $groupTaskId = isset($groupRows[$i]['id']) ? (int)$groupRows[$i]['id'] : 0;
                            if ($groupTaskId <= 0 || $groupTaskId === $taskId) continue;
                            cpms_tasks_copy_saved_files_to_task($pdo, $savedFiles, $groupTaskId, $currentEmployeeId, 'request');
                        }
                    }
                }
            }
        }
        if ($savedFileCount > 0) {
            flash_set('success', '업무내용을 수정하고 첨부파일 ' . $savedFileCount . '개를 추가했습니다.');
        } else {
            flash_set('success', '업무내용을 수정했습니다.');
        }
    } else {
        flash_set('danger', '업무내용 수정에 실패했습니다.');
    }
} catch (Exception $e) {
    flash_set('danger', '업무내용 수정에 실패했습니다.');
}

cpms_tasks_redirect_back();
