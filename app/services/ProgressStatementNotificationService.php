<?php
/**
 * C:\www\cpms\app\services\ProgressStatementNotificationService.php
 * 기성내역서 이벤트별 Google Chat 개인 DM 수신자 선정·발송·이력 저장 서비스.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/ProgressStatementService.php';
require_once __DIR__ . '/../views/common/chat_notification_helpers.php';
require_once __DIR__ . '/../views/approval/google_chat_helpers.php';

if (!function_exists('cpms_progress_statement_notification_recipients')) {
function cpms_progress_statement_notification_recipients($pdo, $projectId) {
    $rows = array();
    if (!$pdo) return $rows;
    try {
        $sql = "SELECT DISTINCT e.id, e.name, e.email, e.department, e.position, e.role,
                       e.google_chat_enabled, e.google_chat_dm_space_name
                  FROM employees e
                  LEFT JOIN cpms_project_members pm ON pm.employee_id=e.id AND pm.project_id=:project_id
                 WHERE e.is_active=1
                   AND (
                     (pm.project_id IS NOT NULL AND LOWER(TRIM(pm.role)) IN ('main','sub'))
                     OR TRIM(e.department) IN ('공무','공무부','공무팀')
                     OR e.role='executive'
                     OR e.position IN ('부사장','임원','대표','대표이사')
                   )
                 ORDER BY e.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute(array(':project_id' => (int)$projectId));
        $found = $st->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($found)) {
            foreach ($found as $row) $rows[(int)$row['id']] = $row;
        }
    } catch (Exception $e) {
        error_log('[progress_statement_chat] recipients: ' . $e->getMessage());
    }
    return array_values($rows);
}}

if (!function_exists('cpms_progress_statement_notification_message')) {
function cpms_progress_statement_notification_message($row, $eventType, $actor, $extra) {
    $titles = array(
        'submitted' => '제출', 'resubmitted' => '재제출', 'approved' => '승인', 'rejected' => '반려',
        'commented' => '댓글', 'drive_upload_failed' => 'Drive 업로드 실패',
        'drive_retry_success' => 'Drive 재업로드 성공'
    );
    $title = isset($titles[$eventType]) ? $titles[$eventType] : cpms_progress_statement_event_label($eventType);
    $lines = array(
        '[CPMS 기성내역서 ' . $title . ']',
        '',
        '현장명 : ' . (isset($row['project_name']) ? $row['project_name'] : ''),
        '기성연월 : ' . (int)$row['target_year'] . '년 ' . (int)$row['target_month'] . '월',
        '기성차수 : ' . (int)$row['progress_round'] . '차',
        '처리자 : ' . (isset($actor['name']) ? $actor['name'] : ''),
        '현재상태 : ' . cpms_progress_statement_status_label(isset($row['status']) ? $row['status'] : '')
    );
    if ($eventType === 'rejected' && isset($extra['reject_reason'])) {
        $lines[] = '';
        $lines[] = '반려사유 :';
        $lines[] = (string)$extra['reject_reason'];
        $lines[] = '';
        $lines[] = '수정 후 다시 제출해주세요.';
    }
    if ($eventType === 'commented' && isset($extra['comment'])) {
        $comment = trim((string)$extra['comment']);
        if (function_exists('mb_substr')) $comment = mb_substr($comment, 0, 300, 'UTF-8');
        else $comment = substr($comment, 0, 300);
        $lines[] = '';
        $lines[] = '댓글 : ' . $comment;
    }
    if (strpos($eventType, 'drive_') === 0 && isset($extra['drive_message'])) {
        $lines[] = 'Drive 저장 : ' . (string)$extra['drive_message'];
    }
    if ($eventType === 'approved' && isset($extra['drive_message'])) {
        $lines[] = 'Drive 저장 : ' . (string)$extra['drive_message'];
    }
    $url = base_url() . '/?r=공무&tab=progress_statement_review&statement_id=' . (int)$row['id'];
    $lines[] = '';
    $lines[] = $url;
    return implode("\n", $lines);
}}

if (!function_exists('cpms_progress_statement_notify')) {
function cpms_progress_statement_notify($pdo, $statementId, $eventType, $actor, $extra) {
    try {
        if ((string)approval_google_chat_setting($pdo, 'google_chat_enabled', '0') !== '1') return;
        $row = cpms_progress_statement_find($pdo, $statementId, false);
        if (!is_array($row)) return;
        $message = cpms_progress_statement_notification_message($row, $eventType, $actor, is_array($extra) ? $extra : array());
        $recipients = cpms_progress_statement_notification_recipients($pdo, (int)$row['project_id']);
        foreach ($recipients as $recipient) {
            $space = isset($recipient['google_chat_dm_space_name']) ? trim((string)$recipient['google_chat_dm_space_name']) : '';
            if ((int)$recipient['google_chat_enabled'] !== 1 || $space === '' || strpos($space, 'spaces/') !== 0) continue;
            $sendText = function_exists('cpms_chat_login_append_missing_tokens') ? cpms_chat_login_append_missing_tokens($message, (int)$recipient['id']) : $message;
            $ok = approval_google_chat_send_message($pdo, $space, $sendText);
            $error = $ok ? null : approval_google_chat_get_last_error();
            cpms_google_chat_log_notification($pdo, array(
                'source_type' => 'progress_statement', 'source_id' => (int)$statementId, 'event_type' => (string)$eventType,
                'receiver_employee_id' => (int)$recipient['id'], 'receiver_name' => (string)$recipient['name'],
                'receiver_email' => (string)$recipient['email'], 'dm_space_name' => $space, 'message_text' => $sendText,
                'send_status' => $ok ? 'SUCCESS' : 'FAILED', 'error_message' => $error,
                'sent_at' => $ok ? date('Y-m-d H:i:s') : null
            ));
        }
    } catch (Exception $e) {
        error_log('[progress_statement_chat] notify: ' . $e->getMessage());
    }
}}
