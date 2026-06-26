<?php
/**
 * 공무 협업툴 액션 처리
 * - 업무 등록/수정, 상태 변경, 댓글, 첨부파일, 설정 저장을 처리한다.
 * - 새 MySQL 테이블 없이 PublicAffairsCollaborationService의 JSON 저장소를 사용한다.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/PublicAffairsCollaborationService.php';

if (!function_exists('cpms_public_affairs_collab_action_wants_json')) {
function cpms_public_affairs_collab_action_wants_json() {
    if (isset($_POST['pa_ajax']) && (string)$_POST['pa_ajax'] === '1') return true;
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) return true;
    return false;
}}

if (!function_exists('cpms_public_affairs_collab_action_json')) {
function cpms_public_affairs_collab_action_json($ok, $message, $extra) {
    if (!is_array($extra)) $extra = array();
    $payload = array_merge(array(
        'ok' => $ok ? true : false,
        'message' => (string)$message,
    ), $extra);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}}

if (!Auth::check()) {
    if (cpms_public_affairs_collab_action_wants_json()) {
        cpms_public_affairs_collab_action_json(false, '로그인이 필요합니다.', array('redirect_url' => '?r=login'));
    }
    header('Location: ?r=login');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?r=공무&tab=collaboration'); exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    if (cpms_public_affairs_collab_action_wants_json()) {
        cpms_public_affairs_collab_action_json(false, '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.', array());
    }
    flash_set('error', '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.');
    header('Location: ?r=공무&tab=collaboration');
    exit;
}

if (!function_exists('cpms_public_affairs_collab_action_return_url')) {
function cpms_public_affairs_collab_action_return_url() {
    $returnUrl = isset($_POST['return_url']) ? trim((string)$_POST['return_url']) : '';
    if ($returnUrl !== '' && substr($returnUrl, 0, 1) === '?') return $returnUrl;
    return '?r=공무&tab=collaboration';
}}

if (!function_exists('cpms_public_affairs_collab_action_finish')) {
function cpms_public_affairs_collab_action_finish($ok, $message, $fallbackUrl, $extra = array()) {
    if (!is_array($extra)) $extra = array();
    $url = cpms_public_affairs_collab_action_return_url();
    if ($url === '') $url = $fallbackUrl;
    if (cpms_public_affairs_collab_action_wants_json()) {
        $extra['redirect_url'] = $url;
        cpms_public_affairs_collab_action_json($ok, $message, $extra);
    }
    flash_set($ok ? 'success' : 'error', $message);
    header('Location: ' . $url);
    exit;
}}

if (!function_exists('cpms_public_affairs_collab_action_task_extra')) {
function cpms_public_affairs_collab_action_task_extra($taskId) {
    $payload = cpms_public_affairs_collab_task_payload($taskId);
    if (!is_array($payload)) return array('task_id' => (int)$taskId);
    $actor = isset($GLOBALS['actor']) && is_array($GLOBALS['actor']) ? $GLOBALS['actor'] : array();
    $payload['can_edit'] = isset($payload['task']) && is_array($payload['task']) && cpms_public_affairs_collab_user_can_edit_task($payload['task'], $actor) ? 1 : 0;
    $payload['task_id'] = (int)$taskId;
    return $payload;
}}

$pdo = Db::pdo();
$actor = cpms_public_affairs_collab_current_employee($pdo);
$projects = cpms_public_affairs_collab_fetch_projects($pdo);
$employees = cpms_public_affairs_collab_fetch_employees($pdo);
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;

if ($action === 'create') {
    // 공무 협업툴 업무카드 생성: 독립 보드 안에서 새 카드를 만든다.
    if (!cpms_public_affairs_collab_can_create_task()) {
        cpms_public_affairs_collab_action_finish(false, '공무 협업툴 업무 등록 권한이 없습니다.', '?r=공무&tab=collaboration');
    }
    $result = cpms_public_affairs_collab_create_task($pdo, $_POST, $_FILES, $actor, $projects, $employees);
    $returnUrl = '?r=공무&tab=collaboration';
    if (isset($result['task_id']) && (int)$result['task_id'] > 0) {
        $_POST['return_url'] = '?r=공무&tab=collaboration&task_id=' . (int)$result['task_id'];
    }
    $extra = array();
    if (!empty($result['ok']) && isset($result['task_id'])) $extra = cpms_public_affairs_collab_action_task_extra((int)$result['task_id']);
    cpms_public_affairs_collab_action_finish(!empty($result['ok']), isset($result['message']) ? $result['message'] : '', $returnUrl, $extra);
}

if ($action === 'settings') {
    // 공무 협업툴 설정: 업무유형/상태/우선순위/카드 표시/빠른 필터를 JSON 설정으로 저장한다.
    if (!cpms_public_affairs_collab_is_admin_user()) {
        cpms_public_affairs_collab_action_finish(false, '설정 저장 권한이 없습니다.', '?r=공무&tab=collaboration&view=settings');
    }
    $currentSettings = cpms_public_affairs_collab_settings();
    $settings = array(
        'task_types' => isset($_POST['task_types']) ? $_POST['task_types'] : '',
        'statuses' => isset($_POST['statuses']) ? $_POST['statuses'] : '',
        'priorities' => isset($_POST['priorities']) ? $_POST['priorities'] : '',
        'quick_filters' => isset($_POST['quick_filters']) ? $_POST['quick_filters'] : '',
        'card_fields' => isset($_POST['card_fields']) ? $_POST['card_fields'] : '',
        'status_transition_rules' => isset($currentSettings['status_transition_rules']) ? $currentSettings['status_transition_rules'] : array(),
        'default_assignee_employee_id' => isset($_POST['default_assignee_employee_id']) ? (int)$_POST['default_assignee_employee_id'] : 0,
    );
    $ok = cpms_public_affairs_collab_save_settings($settings);
    cpms_public_affairs_collab_action_finish($ok, $ok ? '공무 협업툴 설정이 저장되었습니다.' : '설정 저장에 실패했습니다.', '?r=공무&tab=collaboration&view=settings');
}

if ($taskId <= 0) {
    cpms_public_affairs_collab_action_finish(false, '업무 정보가 올바르지 않습니다.', '?r=공무&tab=collaboration');
}

$task = cpms_public_affairs_collab_find_task($taskId);
if (!is_array($task)) {
    cpms_public_affairs_collab_action_finish(false, '업무를 찾을 수 없습니다.', '?r=공무&tab=collaboration');
}
if (!cpms_public_affairs_collab_user_can_view_task($task, $actor)) {
    cpms_public_affairs_collab_action_finish(false, '해당 업무를 볼 권한이 없습니다.', '?r=공무&tab=collaboration');
}

if ($action === 'detail') {
    // 공무 협업툴 상세패널: 페이지 이동 없이 업무카드 상세를 AJAX로 조회한다.
    cpms_public_affairs_collab_action_finish(true, '업무 상세를 불러왔습니다.', '?r=공무&tab=collaboration&task_id=' . $taskId, cpms_public_affairs_collab_action_task_extra($taskId));
}

if ($action === 'update' || $action === 'quick_update') {
    // 공무 협업툴 업무 상세: 상태/담당자/우선순위/마감일 등 기본정보를 수정한다.
    if (!cpms_public_affairs_collab_user_can_edit_task($task, $actor)) {
        cpms_public_affairs_collab_action_finish(false, '업무 수정 권한이 없습니다.', '?r=공무&tab=collaboration&task_id=' . $taskId);
    }
    $stateAction = isset($_POST['state_action']) ? trim((string)$_POST['state_action']) : '';
    if ($stateAction === 'complete') $_POST['status'] = '완료';
    if ($stateAction === 'reject') $_POST['status'] = '반려';
    if ($stateAction === 'hold') $_POST['status'] = '보류';
    $result = cpms_public_affairs_collab_update_task($taskId, $_POST, $actor, $projects, $employees);
    $extra = !empty($result['ok']) ? cpms_public_affairs_collab_action_task_extra($taskId) : array('task_id' => $taskId);
    cpms_public_affairs_collab_action_finish(!empty($result['ok']), isset($result['message']) ? $result['message'] : '', '?r=공무&tab=collaboration&task_id=' . $taskId, $extra);
}

if ($action === 'complete' || $action === 'reject' || $action === 'hold') {
    // 공무 협업툴 업무 상세: 완료/반려/보류 빠른 처리 버튼.
    if (!cpms_public_affairs_collab_user_can_edit_task($task, $actor)) {
        cpms_public_affairs_collab_action_finish(false, '업무 상태 변경 권한이 없습니다.', '?r=공무&tab=collaboration&task_id=' . $taskId);
    }
    if ($action === 'complete') $_POST['status'] = '완료';
    if ($action === 'reject') $_POST['status'] = '반려';
    if ($action === 'hold') $_POST['status'] = '보류';
    $result = cpms_public_affairs_collab_update_task($taskId, $_POST, $actor, $projects, $employees);
    $extra = !empty($result['ok']) ? cpms_public_affairs_collab_action_task_extra($taskId) : array('task_id' => $taskId);
    cpms_public_affairs_collab_action_finish(!empty($result['ok']), isset($result['message']) ? $result['message'] : '', '?r=공무&tab=collaboration&task_id=' . $taskId, $extra);
}

if ($action === 'comment') {
    // 공무 협업툴 업무 상세: 댓글 등록과 변경이력 기록.
    $result = cpms_public_affairs_collab_add_comment($task, isset($_POST['comment']) ? $_POST['comment'] : '', $actor);
    $extra = !empty($result['ok']) ? cpms_public_affairs_collab_action_task_extra($taskId) : array('task_id' => $taskId);
    cpms_public_affairs_collab_action_finish(!empty($result['ok']), isset($result['message']) ? $result['message'] : '', '?r=공무&tab=collaboration&task_id=' . $taskId, $extra);
}

if ($action === 'upload') {
    // 공무 협업툴 업무 상세: 업무별 첨부파일 업로드.
    if (!cpms_public_affairs_collab_user_can_edit_task($task, $actor)) {
        cpms_public_affairs_collab_action_finish(false, '첨부파일 등록 권한이 없습니다.', '?r=공무&tab=collaboration&task_id=' . $taskId);
    }
    $saved = cpms_public_affairs_collab_save_uploaded_files($task, isset($_FILES['attachments']) ? $_FILES['attachments'] : null, $actor);
    $count = is_array($saved) ? count($saved) : 0;
    $extra = $count > 0 ? cpms_public_affairs_collab_action_task_extra($taskId) : array('task_id' => $taskId);
    $extra['saved_files'] = $saved;
    cpms_public_affairs_collab_action_finish($count > 0, $count > 0 ? '첨부파일이 등록되었습니다.' : '등록된 첨부파일이 없습니다. 파일 형식 또는 용량을 확인해주세요.', '?r=공무&tab=collaboration&task_id=' . $taskId, $extra);
}

cpms_public_affairs_collab_action_finish(false, '처리할 수 없는 요청입니다.', '?r=공무&tab=collaboration');
