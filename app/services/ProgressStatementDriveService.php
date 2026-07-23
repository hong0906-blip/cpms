<?php
/**
 * C:\www\cpms\app\services\ProgressStatementDriveService.php
 * 승인된 기성내역서를 기존 공무 Google Drive 기성/연도/월 폴더에 저장하는 서비스.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/ProgressStatementService.php';
require_once __DIR__ . '/PublicAffairsDriveService.php';

if (!function_exists('cpms_progress_statement_drive_upload')) {
function cpms_progress_statement_drive_upload($pdo, $statementId, $actor, $isRetry) {
    $row = cpms_progress_statement_find($pdo, $statementId, false);
    if (!is_array($row)) return array('ok' => false, 'message' => '기성내역서를 찾을 수 없습니다.');
    if ((string)$row['status'] !== 'approved') return array('ok' => false, 'message' => '승인완료 건만 Drive에 저장할 수 있습니다.');
    if (trim((string)$row['drive_file_id']) !== '' && (string)$row['drive_upload_status'] === 'uploaded') {
        return array('ok' => true, 'message' => '이미 Drive 저장이 완료된 파일입니다.', 'already' => true);
    }
    $path = isset($row['current_server_file_path']) ? (string)$row['current_server_file_path'] : '';
    if ($path === '' || !is_file($path)) {
        $result = array('ok' => false, 'message' => '서버에 승인 대상 파일이 없습니다.');
    } else {
        try {
            $monthValue = sprintf('%04d-%02d', (int)$row['target_year'], (int)$row['target_month']);
            $driveName = '[승인]_' . (string)$row['project_name'] . '_' . (int)$row['target_year'] . '년' .
                sprintf('%02d', (int)$row['target_month']) . '월_' . (int)$row['progress_round'] . '차기성_' .
                (string)$row['current_original_file_name'];
            $drive = cpms_public_affairs_drive_upload_local_file(
                $pdo, (int)$row['project_id'], $path, (string)$row['current_original_file_name'],
                'progress_statement', $monthValue, $monthValue . '-01',
                array('date' => $monthValue . '-01', 'project_name' => (string)$row['project_name'], 'drive_name' => $driveName), $actor
            );
            if (!empty($drive['ok'])) {
                $record = isset($drive['record']) && is_array($drive['record']) ? $drive['record'] : array();
                $result = array(
                    'ok' => true,
                    'message' => 'Drive 저장이 완료되었습니다.',
                    'file_id' => isset($record['drive_file_id']) ? (string)$record['drive_file_id'] : '',
                    'file_name' => isset($record['stored_name']) ? (string)$record['stored_name'] : '',
                    'web_view_link' => isset($record['drive_web_view_link']) ? (string)$record['drive_web_view_link'] : ''
                );
            } else {
                $result = array('ok' => false, 'message' => isset($drive['message']) ? (string)$drive['message'] : 'Drive API 업로드에 실패했습니다.');
            }
        } catch (Exception $e) {
            $result = array('ok' => false, 'message' => 'Drive 처리 중 오류가 발생했습니다: ' . $e->getMessage());
        }
    }

    $eventType = $isRetry ? ($result['ok'] ? 'drive_retry_success' : 'drive_retry_failed') : ($result['ok'] ? 'drive_upload_success' : 'drive_upload_failed');
    try {
        $pdo->beginTransaction();
        if ($result['ok']) {
            $st = $pdo->prepare("UPDATE cpms_progress_statements SET drive_upload_status='uploaded', drive_file_id=:file_id,
                drive_file_name=:file_name, drive_web_view_link=:web_view_link, drive_uploaded_at=:uploaded_at,
                drive_error_message=NULL, updated_at=:updated_at WHERE id=:id AND status='approved'");
            $st->execute(array(':file_id' => $result['file_id'], ':file_name' => $result['file_name'],
                ':web_view_link' => $result['web_view_link'], ':uploaded_at' => date('Y-m-d H:i:s'),
                ':updated_at' => date('Y-m-d H:i:s'), ':id' => (int)$statementId));
        } else {
            $st = $pdo->prepare("UPDATE cpms_progress_statements SET drive_upload_status='failed',
                drive_error_message=:error_message, updated_at=:updated_at WHERE id=:id AND status='approved'");
            $st->execute(array(':error_message' => substr((string)$result['message'], 0, 4000),
                ':updated_at' => date('Y-m-d H:i:s'), ':id' => (int)$statementId));
        }
        cpms_progress_statement_add_history($pdo, $statementId, $eventType, 'approved', 'approved', $actor, $result['message']);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $result['ok'] = false;
        $result['message'] = 'Drive 결과 저장 실패: ' . $e->getMessage();
    }
    $result['event_type'] = $eventType;
    return $result;
}}
