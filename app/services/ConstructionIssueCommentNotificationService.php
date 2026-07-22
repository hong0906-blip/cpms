<?php
/**
 * Construction issue comment Google Chat DM helpers.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_construction_issue_comment_table_exists')) {
function cpms_construction_issue_comment_table_exists($pdo, $tableName)
{
    if (!$pdo || trim((string)$tableName) === '') return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :table_name");
        $st->execute(array(':table_name' => (string)$tableName));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_construction_issue_comment_column_exists')) {
function cpms_construction_issue_comment_column_exists($pdo, $tableName, $columnName)
{
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '``', (string)$tableName) . "` LIKE :column_name");
        $st->execute(array(':column_name' => (string)$columnName));
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_construction_issue_comment_find')) {
function cpms_construction_issue_comment_find($pdo, $commentId)
{
    if (!$pdo || (int)$commentId <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT c.*, i.project_id, i.title AS issue_title, i.issue_kind, p.name AS project_name
            FROM cpms_project_issue_comments c
            JOIN cpms_project_issues i ON i.id = c.issue_id
            LEFT JOIN cpms_projects p ON p.id = i.project_id
            WHERE c.id = :comment_id LIMIT 1");
        $st->execute(array(':comment_id' => (int)$commentId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}}

if (!function_exists('cpms_construction_issue_comment_text')) {
function cpms_construction_issue_comment_text($row)
{
    if (!is_array($row)) return '';
    if (isset($row['comment_text']) && trim((string)$row['comment_text']) !== '') return trim((string)$row['comment_text']);
    if (isset($row['comment']) && trim((string)$row['comment']) !== '') return trim((string)$row['comment']);
    return '';
}}

if (!function_exists('cpms_construction_issue_comment_recipient_ids')) {
function cpms_construction_issue_comment_recipient_ids($pdo, $issueId, $projectId)
{
    $recipientIds = array();
    if (!$pdo || (int)$issueId <= 0 || (int)$projectId <= 0) return array();

    try {
        if (cpms_construction_issue_comment_table_exists($pdo, 'cpms_project_members')) {
            $st = $pdo->prepare("SELECT DISTINCT e.id
                FROM cpms_project_members pm
                JOIN employees e ON e.id = pm.employee_id
                WHERE pm.project_id = :project_id
                  AND LOWER(TRIM(pm.role)) IN ('main', 'sub')
                  AND e.is_active = 1");
            $st->execute(array(':project_id' => (int)$projectId));
            while ($employeeId = $st->fetchColumn()) {
                if ((int)$employeeId > 0) $recipientIds[(int)$employeeId] = (int)$employeeId;
            }
        }
    } catch (Exception $e) {
    }

    try {
        if (cpms_construction_issue_comment_table_exists($pdo, 'cpms_construction_roles')) {
            $st = $pdo->prepare("SELECT site_employee_id, safety_employee_id, quality_employee_id
                FROM cpms_construction_roles WHERE project_id = :project_id LIMIT 1");
            $st->execute(array(':project_id' => (int)$projectId));
            $roleRow = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($roleRow)) {
                $roleColumns = array('site_employee_id', 'safety_employee_id', 'quality_employee_id');
                for ($i = 0; $i < count($roleColumns); $i++) {
                    $employeeId = isset($roleRow[$roleColumns[$i]]) ? (int)$roleRow[$roleColumns[$i]] : 0;
                    if ($employeeId > 0) $recipientIds[$employeeId] = $employeeId;
                }
            }
        }
    } catch (Exception $e) {
    }

    try {
        if (cpms_construction_issue_comment_column_exists($pdo, 'cpms_project_issue_comments', 'created_by')) {
            $st = $pdo->prepare("SELECT DISTINCT e.id
                FROM cpms_project_issue_comments c
                JOIN employees e ON e.id = c.created_by
                WHERE c.issue_id = :issue_id AND e.is_active = 1");
            $st->execute(array(':issue_id' => (int)$issueId));
            while ($employeeId = $st->fetchColumn()) {
                if ((int)$employeeId > 0) $recipientIds[(int)$employeeId] = (int)$employeeId;
            }
        }
    } catch (Exception $e) {
    }

    try {
        if (cpms_construction_issue_comment_column_exists($pdo, 'cpms_project_issue_comments', 'created_by_email')) {
            $st = $pdo->prepare("SELECT DISTINCT e.id
                FROM cpms_project_issue_comments c
                JOIN employees e ON LOWER(TRIM(e.email)) = LOWER(TRIM(c.created_by_email))
                WHERE c.issue_id = :issue_id
                  AND c.created_by_email IS NOT NULL
                  AND TRIM(c.created_by_email) <> ''
                  AND e.is_active = 1");
            $st->execute(array(':issue_id' => (int)$issueId));
            while ($employeeId = $st->fetchColumn()) {
                if ((int)$employeeId > 0) $recipientIds[(int)$employeeId] = (int)$employeeId;
            }
        }
    } catch (Exception $e) {
    }

    if (count($recipientIds) > 0) {
        try {
            $activeIds = array();
            $idList = implode(',', array_map('intval', array_values($recipientIds)));
            $st = $pdo->query("SELECT id FROM employees WHERE is_active = 1 AND id IN (" . $idList . ")");
            while ($employeeId = $st->fetchColumn()) {
                if ((int)$employeeId > 0) $activeIds[(int)$employeeId] = (int)$employeeId;
            }
            $recipientIds = $activeIds;
        } catch (Exception $e) {
        }
    }

    ksort($recipientIds, SORT_NUMERIC);
    return array_values($recipientIds);
}}

if (!function_exists('cpms_construction_issue_comment_message')) {
function cpms_construction_issue_comment_message($row)
{
    $projectName = isset($row['project_name']) && trim((string)$row['project_name']) !== '' ? trim((string)$row['project_name']) : '현장 #' . (isset($row['project_id']) ? (int)$row['project_id'] : 0);
    $issueTitle = isset($row['issue_title']) && trim((string)$row['issue_title']) !== '' ? trim((string)$row['issue_title']) : '-';
    $writerName = isset($row['created_by_name']) && trim((string)$row['created_by_name']) !== '' ? trim((string)$row['created_by_name']) : (isset($row['created_by_email']) ? trim((string)$row['created_by_email']) : '사용자');
    $commentText = cpms_construction_issue_comment_text($row);
    if (function_exists('mb_strlen') && mb_strlen($commentText, 'UTF-8') > 1000) {
        $commentText = mb_substr($commentText, 0, 1000, 'UTF-8') . '...';
    }
    $issueKind = isset($row['issue_kind']) ? trim((string)$row['issue_kind']) : 'issue';
    $label = $issueKind === 'security' ? '보안사고' : '이슈';

    return implode("\n", array(
        '[CPMS 공사 ' . $label . ' 댓글]',
        '',
        '현장명 : ' . $projectName,
        $label . ' 제목 : ' . $issueTitle,
        '댓글 작성자 : ' . $writerName,
        '',
        '댓글 내용 :',
        $commentText !== '' ? $commentText : '-',
        '',
        '공사 > ' . $label . '에서 확인해주세요.'
    ));
}}

if (!function_exists('cpms_construction_issue_comment_was_attempted')) {
function cpms_construction_issue_comment_was_attempted($pdo, $commentId, $employeeId)
{
    if (!$pdo || !cpms_construction_issue_comment_table_exists($pdo, 'cpms_google_chat_notifications')) return false;
    try {
        $st = $pdo->prepare("SELECT id FROM cpms_google_chat_notifications
            WHERE source_type = 'CONSTRUCTION_ISSUE_COMMENT'
              AND source_id = :comment_id
              AND event_type = 'COMMENT_CREATED'
              AND receiver_employee_id = :employee_id
            LIMIT 1");
        $st->execute(array(':comment_id' => (int)$commentId, ':employee_id' => (int)$employeeId));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_construction_issue_comment_send_dm')) {
function cpms_construction_issue_comment_send_dm($pdo, $commentId, $skipAttempted)
{
    $result = array('recipients' => 0, 'attempted' => 0, 'success' => 0, 'failed' => 0, 'already' => 0);
    $row = cpms_construction_issue_comment_find($pdo, (int)$commentId);
    if (!$row || !function_exists('cpms_send_google_chat_to_employee')) return $result;

    $recipientIds = cpms_construction_issue_comment_recipient_ids(
        $pdo,
        isset($row['issue_id']) ? (int)$row['issue_id'] : 0,
        isset($row['project_id']) ? (int)$row['project_id'] : 0
    );
    $result['recipients'] = count($recipientIds);
    $message = cpms_construction_issue_comment_message($row);

    for ($i = 0; $i < count($recipientIds); $i++) {
        $employeeId = (int)$recipientIds[$i];
        if ($skipAttempted && cpms_construction_issue_comment_was_attempted($pdo, (int)$commentId, $employeeId)) {
            $result['already']++;
            continue;
        }
        $result['attempted']++;
        $ok = cpms_send_google_chat_to_employee($pdo, $employeeId, $message, (int)$commentId, 'COMMENT_CREATED', 'CONSTRUCTION_ISSUE_COMMENT');
        if ($ok) $result['success']++;
        else $result['failed']++;
    }
    return $result;
}}
