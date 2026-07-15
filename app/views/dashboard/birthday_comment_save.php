<?php
/** Birthday congratulation comment action. PHP 5.6 compatible. */

require_once __DIR__ . '/notice_board.php';

if (!\App\Core\Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$dashboardType = isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee';
$birthdayCommentFallbackUrl = ($dashboardType === 'executive') ? '?r=dashboard_executive' : '?r=dashboard_employee';
$birthdayCommentReturnUrl = isset($_POST['return_url'])
    ? cpms_safe_internal_redirect_url((string)$_POST['return_url'], $birthdayCommentFallbackUrl)
    : $birthdayCommentFallbackUrl;

if (!function_exists('cpms_birthday_comment_redirect')) {
function cpms_birthday_comment_redirect($returnUrl, $type, $message) {
    cpms_dashboard_birthday_comment_flash_set($type, $message);
    header('Location: ' . $returnUrl);
    exit;
}}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '잘못된 요청 방식입니다.');
}
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '보안 토큰이 올바르지 않습니다. 다시 시도해주세요.');
}

$pdo = \App\Core\Db::pdo();
$birthdayCommentId = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
$birthdayEmployeeId = isset($_POST['birthday_employee_id']) ? (int)$_POST['birthday_employee_id'] : 0;
$birthdayCommentPostedText = isset($_POST['comment_text']) ? trim((string)$_POST['comment_text']) : '';
$birthdayCommentText = cpms_dashboard_birthday_comment_decode_storage($birthdayCommentPostedText);
$birthdayCommentStorageText = cpms_dashboard_birthday_comment_encode_storage($birthdayCommentText);
if (!$pdo || $birthdayEmployeeId <= 0 || $birthdayCommentText === '') {
    cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '생일 축하 댓글을 입력해주세요.');
}
if (function_exists('mb_strlen')) {
    if (mb_strlen($birthdayCommentText, 'UTF-8') > 500) {
        cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '댓글은 500자 이내로 입력해주세요.');
    }
} else {
    $birthdayCharacterCount = preg_match_all('/./us', $birthdayCommentText, $birthdayCharacterMatches);
    if ($birthdayCharacterCount === false) $birthdayCharacterCount = strlen($birthdayCommentText);
    if ($birthdayCharacterCount > 500) {
        cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '댓글은 500자 이내로 입력해주세요.');
    }
}

$today = cpms_dashboard_birthday_today();
$todayBirthdays = cpms_dashboard_birthday_today_employees($pdo, $today);
$birthdayRecipient = null;
foreach ($todayBirthdays as $todayBirthday) {
    if (isset($todayBirthday['id']) && (int)$todayBirthday['id'] === $birthdayEmployeeId) {
        $birthdayRecipient = $todayBirthday;
        break;
    }
}
if (!is_array($birthdayRecipient)) {
    cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '오늘 생일자인 직원을 찾을 수 없습니다.');
}
if (!cpms_dashboard_birthday_comment_schema($pdo)) {
    cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '댓글 저장 공간을 준비하지 못했습니다.');
}

$birthdayCommentAuthor = cpms_dashboard_birthday_current_employee($pdo);
$birthdayCommentAuthorId = isset($birthdayCommentAuthor['id']) ? (int)$birthdayCommentAuthor['id'] : 0;
$birthdayCommentAuthorName = isset($birthdayCommentAuthor['name']) && trim((string)$birthdayCommentAuthor['name']) !== ''
    ? trim((string)$birthdayCommentAuthor['name'])
    : (string)\App\Core\Auth::userName();
if (trim((string)$birthdayCommentAuthorName) === '') $birthdayCommentAuthorName = '작성자';
$birthdayCommentAuthorEmail = isset($birthdayCommentAuthor['email']) ? trim((string)$birthdayCommentAuthor['email']) : (string)\App\Core\Auth::userEmail();
$birthdayCommentAuthorPhoto = isset($birthdayCommentAuthor['photo_path']) ? trim((string)$birthdayCommentAuthor['photo_path']) : '';
$birthdayCommentIsEdit = ($birthdayCommentId > 0);

if ($birthdayCommentIsEdit) {
    try {
        $existingStatement = $pdo->prepare("SELECT id, celebration_date, birthday_employee_id, created_by_employee_id, created_by_email FROM cpms_birthday_comments WHERE id=:id LIMIT 1");
        $existingStatement->bindValue(':id', $birthdayCommentId, PDO::PARAM_INT);
        $existingStatement->execute();
        $existingComment = $existingStatement->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $existingComment = false;
        error_log('[birthday_comment] edit lookup error: ' . $e->getMessage());
    }
    if (!is_array($existingComment)
        || (string)$existingComment['celebration_date'] !== (string)$today
        || (int)$existingComment['birthday_employee_id'] !== $birthdayEmployeeId) {
        cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '수정할 생일 축하 댓글을 찾을 수 없습니다.');
    }

    $existingAuthorId = isset($existingComment['created_by_employee_id']) ? (int)$existingComment['created_by_employee_id'] : 0;
    $existingAuthorEmail = isset($existingComment['created_by_email']) ? strtolower(trim((string)$existingComment['created_by_email'])) : '';
    $currentAuthorEmail = strtolower(trim((string)$birthdayCommentAuthorEmail));
    $canEditComment = false;
    if ($birthdayCommentAuthorId > 0 && $existingAuthorId === $birthdayCommentAuthorId) {
        $canEditComment = true;
    } elseif ($existingAuthorId <= 0 && $currentAuthorEmail !== '' && $existingAuthorEmail === $currentAuthorEmail) {
        $canEditComment = true;
    }
    if (!$canEditComment) {
        cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '본인이 작성한 댓글만 수정할 수 있습니다.');
    }
}

try {
    $pdo->beginTransaction();
    if ($birthdayCommentIsEdit) {
        $st = $pdo->prepare("UPDATE cpms_birthday_comments SET comment_text=:comment_text WHERE id=:id AND celebration_date=:celebration_date AND birthday_employee_id=:birthday_employee_id");
        $st->bindValue(':comment_text', $birthdayCommentStorageText, PDO::PARAM_STR);
        $st->bindValue(':id', $birthdayCommentId, PDO::PARAM_INT);
        $st->bindValue(':celebration_date', $today, PDO::PARAM_STR);
        $st->bindValue(':birthday_employee_id', $birthdayEmployeeId, PDO::PARAM_INT);
        $st->execute();
    } else {
        $st = $pdo->prepare("INSERT INTO cpms_birthday_comments
            (celebration_date, birthday_employee_id, comment_text, created_by_employee_id, created_by_name, created_by_email, created_by_photo_path, created_at)
            VALUES (:celebration_date, :birthday_employee_id, :comment_text, :created_by_employee_id, :created_by_name, :created_by_email, :created_by_photo_path, :created_at)");
        $st->bindValue(':celebration_date', $today, PDO::PARAM_STR);
        $st->bindValue(':birthday_employee_id', $birthdayEmployeeId, PDO::PARAM_INT);
        $st->bindValue(':comment_text', $birthdayCommentStorageText, PDO::PARAM_STR);
        if ($birthdayCommentAuthorId > 0) $st->bindValue(':created_by_employee_id', $birthdayCommentAuthorId, PDO::PARAM_INT);
        else $st->bindValue(':created_by_employee_id', null, PDO::PARAM_NULL);
        $st->bindValue(':created_by_name', $birthdayCommentAuthorName, PDO::PARAM_STR);
        $st->bindValue(':created_by_email', $birthdayCommentAuthorEmail, PDO::PARAM_STR);
        $st->bindValue(':created_by_photo_path', $birthdayCommentAuthorPhoto, PDO::PARAM_STR);
        $st->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $st->execute();
        $birthdayCommentId = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[birthday_comment] save error: ' . $e->getMessage());
    cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'error', '생일 축하 댓글을 저장하지 못했습니다.');
}

$birthdayRecipientName = isset($birthdayRecipient['name']) ? trim((string)$birthdayRecipient['name']) : '';
$birthdayDmLines = array(
    $birthdayCommentIsEdit ? '[CPMS 생일 축하 메시지 수정]' : '[CPMS 생일 축하 메시지]',
    '',
    '받는 사람 : ' . ($birthdayRecipientName !== '' ? $birthdayRecipientName : '-'),
    '작성자 : ' . ($birthdayCommentAuthorName !== '' ? $birthdayCommentAuthorName : '-'),
    ($birthdayCommentIsEdit ? '수정 내용 : ' : '축하 내용 : ') . $birthdayCommentText
);
$birthdayDmSent = false;
if (function_exists('cpms_send_google_chat_to_employee')) {
    $birthdayDmSent = cpms_send_google_chat_to_employee(
        $pdo,
        $birthdayEmployeeId,
        implode("\n", $birthdayDmLines),
        $birthdayCommentId,
        $birthdayCommentIsEdit ? 'BIRTHDAY_COMMENT_UPDATED' : 'BIRTHDAY_COMMENTED',
        'BIRTHDAY_COMMENT'
    );
}

if ($birthdayDmSent) {
    $birthdaySuccessMessage = $birthdayCommentIsEdit
        ? '생일 축하 댓글을 수정하고 생일자에게 개인 DM을 보냈습니다.'
        : '생일 축하 댓글을 등록하고 생일자에게 개인 DM을 보냈습니다.';
    cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'success', $birthdaySuccessMessage);
}
$birthdayWarningMessage = $birthdayCommentIsEdit
    ? '댓글은 수정했지만 개인 DM을 보내지 못했습니다. 생일자의 Google Chat 설정을 확인해주세요.'
    : '댓글은 등록했지만 개인 DM을 보내지 못했습니다. 생일자의 Google Chat 설정을 확인해주세요.';
cpms_birthday_comment_redirect($birthdayCommentReturnUrl, 'warning', $birthdayWarningMessage);
