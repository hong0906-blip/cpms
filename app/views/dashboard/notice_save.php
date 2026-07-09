<?php
/**
 * Dashboard notice save/delete action.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/notice_board.php';

if (!\App\Core\Auth::check()) {
    cpms_redirect_to_portal_login(cpms_current_absolute_url());
}

$returnUrl = isset($_POST['return_url']) ? (string)$_POST['return_url'] : '?r=notices';
$returnUrl = cpms_safe_internal_redirect_url($returnUrl, '?r=notices');

if (!cpms_dashboard_notice_can_manage()) {
    cpms_dashboard_notice_flash_set('error', cpms_dashboard_notice_label('forbidden'));
    header('Location: ' . $returnUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnUrl);
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    cpms_dashboard_notice_flash_set('error', 'CSRF');
    header('Location: ' . $returnUrl);
    exit;
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

if ($action === 'delete') {
    $id = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
    cpms_dashboard_notice_delete_item($id);
    cpms_dashboard_notice_flash_set('success', cpms_dashboard_notice_label('deleted'));
    header('Location: ' . $returnUrl);
    exit;
}

$title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
$content = isset($_POST['content']) ? trim((string)$_POST['content']) : '';

if ($title === '' || $content === '') {
    cpms_dashboard_notice_flash_set('error', cpms_dashboard_notice_label('invalid'));
    header('Location: ' . $returnUrl);
    exit;
}

$input = array(
    'id' => isset($_POST['id']) ? trim((string)$_POST['id']) : '',
    'title' => $title,
    'content' => $content,
    'is_active' => isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0,
    'is_pinned' => isset($_POST['is_pinned']) ? (int)$_POST['is_pinned'] : 0
);

$saveResult = cpms_dashboard_notice_save_item($input);
if (is_array($saveResult)
    && !empty($saveResult['ok'])
    && !empty($saveResult['created'])
    && isset($saveResult['item'])
    && is_array($saveResult['item'])
    && isset($saveResult['item']['is_active'])
    && (int)$saveResult['item']['is_active'] === 1) {
    try {
        $pdo = \App\Core\Db::pdo();
        cpms_dashboard_notice_send_created_dm($pdo, $saveResult['item']);
    } catch (Exception $e) {
        error_log('[dashboard_notice_chat] ' . $e->getMessage());
    }
}
cpms_dashboard_notice_flash_set('success', cpms_dashboard_notice_label('saved'));
header('Location: ' . $returnUrl);
exit;
?>
