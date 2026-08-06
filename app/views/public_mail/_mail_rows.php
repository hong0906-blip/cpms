<?php
/**
 * 파일 경로: C:\www\cpms\app\views\public_mail\_mail_rows.php
 *
 * 최초 메일 목록과 실시간으로 추가되는 메일이 같은 디자인을 사용하도록 만든 공통 행 화면입니다.
 * PHP 5.6 호환 코드입니다.
 */

if (!isset($items) || !is_array($items)) $items = array();
if (!isset($selectedMessageKey)) $selectedMessageKey = '';
if (!isset($esc) || !is_callable($esc)) {
    $esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
}
if (!isset($buildUrl) || !is_callable($buildUrl)) {
    $buildUrl = function ($changes) {
        $query = array('page'=>1);
        foreach ($changes as $key => $value) {
            if ($value === null || $value === '') unset($query[$key]);
            else $query[$key] = $value;
        }
        return 'public_mail.php?' . http_build_query($query, '', '&');
    };
}
?>
<?php foreach ($items as $message): ?>
    <?php
    $classification = isset($message['classification']) && is_array($message['classification']) ? $message['classification'] : array();
    $workflow = isset($message['workflow']) && is_array($message['workflow']) ? $message['workflow'] : array();
    $department = !empty($workflow['department']) ? $workflow['department'] : (isset($classification['department']) ? $classification['department'] : '미분류');
    $priority = !empty($workflow['priority']) ? $workflow['priority'] : (isset($classification['priority']) ? $classification['priority'] : '보통');
    $projectName = !empty($workflow['project_name']) ? $workflow['project_name'] : (isset($classification['project_name']) ? $classification['project_name'] : '');
    $messageMailboxType = isset($message['mailbox_type']) ? (string)$message['mailbox_type'] : '';
    $addressText = $messageMailboxType === 'sent'
        ? (isset($message['to_text']) && trim((string)$message['to_text']) !== '' ? (string)$message['to_text'] : '수신자 정보 없음')
        : (isset($message['from_text']) ? (string)$message['from_text'] : '발신자 정보 없음');
    $addressLabel = $messageMailboxType === 'sent' ? '받는 사람' : '보낸 사람';
    $messageKey = isset($message['message_key']) ? (string)$message['message_key'] : '';
    $rowUrl = $buildUrl(array('message' => $messageKey));
    ?>
    <a data-no-loading="1"
       data-mail-open
       data-live-mail-row
       data-message-key="<?php echo call_user_func($esc, $messageKey); ?>"
       class="pm-mail-row pm-mail-row-wide <?php echo (string)$selectedMessageKey === $messageKey ? 'is-selected' : ''; ?> <?php echo empty($message['is_seen']) ? 'is-unread' : ''; ?>"
       href="<?php echo call_user_func($esc, $rowUrl); ?>">
        <div class="pm-mail-row-top">
            <span class="pm-mobile-address"><?php echo call_user_func($esc, $addressText); ?></span>
            <span class="pm-badge pm-badge-<?php echo $priority === '긴급' ? 'danger' : ($priority === '높음' ? 'warning' : 'neutral'); ?>"><?php echo call_user_func($esc, $priority); ?></span>
            <span class="pm-department"><?php echo call_user_func($esc, $department); ?></span>
            <time><?php echo call_user_func($esc, substr(isset($message['date_text']) ? $message['date_text'] : '', 5, 11)); ?></time>
        </div>
        <div class="pm-subject"><?php echo call_user_func($esc, isset($message['subject']) ? $message['subject'] : '(제목 없음)'); ?></div>
        <div class="pm-mail-meta">
            <span class="pm-address-label"><?php echo call_user_func($esc, $addressLabel); ?></span>
            <span><?php echo call_user_func($esc, $addressText); ?></span>
            <span class="pm-mailbox-chip"><?php echo call_user_func($esc, isset($message['mailbox_name']) ? $message['mailbox_name'] : '받은메일함'); ?></span>
            <?php if ($projectName !== ''): ?><span class="pm-project-chip"><?php echo call_user_func($esc, $projectName); ?></span><?php endif; ?>
        </div>
        <div class="pm-preview">
            <?php echo call_user_func($esc, !empty($message['preview']) ? $message['preview'] : '본문 미리보기를 준비 중입니다.'); ?>
        </div>
        <div class="pm-mail-status">
            <span><?php echo call_user_func($esc, isset($workflow['status']) ? $workflow['status'] : '미확인'); ?></span>
            <span><?php echo call_user_func($esc, !empty($workflow['assignee_name']) ? $workflow['assignee_name'] : '담당자 없음'); ?></span>
        </div>
    </a>
<?php endforeach; ?>
