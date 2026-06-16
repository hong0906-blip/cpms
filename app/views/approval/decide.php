<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/template_helpers.php';
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/leave_balance_helpers.php';
require_once __DIR__ . '/../../services/ApprovalPdfService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}
csrf_validate();

$pdo = Db::pdo();
$u = \App\Core\Auth::user();
if (!$pdo || !$u) {
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$reason = isset($_POST['reject_reason']) ? trim((string)$_POST['reject_reason']) : '';
$uid = approval_current_employee_id($pdo, $u);
$userEmail = approval_current_user_email($u);
$userName = approval_current_user_name($u);
$actorName = $userName;
$actorEmail = $userEmail;

$docSt = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
$docSt->execute(array(':id' => $id));
$doc = $docSt->fetch(PDO::FETCH_ASSOC);
if (!$doc || strtoupper((string)$doc['doc_status']) !== 'PENDING') {
    flash_set('danger', approval_ko('%EC%B2%98%EB%A6%AC%ED%95%A0%20%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EC%B0%BE%EC%9D%84%20%EC%88%98%20%EC%97%86%EA%B1%B0%EB%82%98%20%EC%A7%84%ED%96%89%20%EC%83%81%ED%83%9C%EA%B0%80%20%EC%95%84%EB%8B%99%EB%8B%88%EB%8B%A4.'));
    header('Location: ?r=approval_detail&id=' . $id);
    exit;
}

$lineParts = array();
$lineParams = array(':d' => $id);
if ($uid > 0) {
    $lineParts[] = 'approver_id=:u';
    $lineParams[':u'] = $uid;
}
if ($userEmail !== '') {
    $lineParts[] = 'LOWER(TRIM(approver_email))=LOWER(TRIM(:email))';
    $lineParams[':email'] = $userEmail;
}
if ($userName !== '') {
    $lineParts[] = 'approver_name=:name';
    $lineParams[':name'] = $userName;
}
if (count($lineParts) === 0) {
    flash_set('danger', approval_ko('%EC%B2%98%EB%A6%AC%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ?r=approval_detail&id=' . $id);
    exit;
}
$sql = "SELECT * FROM cpms_approval_lines WHERE document_id=:d AND line_status='PENDING' AND (" . implode(' OR ', $lineParts) . ") LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute($lineParams);
$line = $st->fetch(PDO::FETCH_ASSOC);
if (!$line) {
    flash_set('danger', approval_ko('%EC%B2%98%EB%A6%AC%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ?r=approval_detail&id=' . $id);
    exit;
}

if ($action === 'reject' && $reason === '') {
    flash_set('danger', approval_ko('%EB%B0%98%EB%A0%A4%EC%82%AC%EC%9C%A0%EB%8A%94%20%ED%95%84%EC%88%98%EC%9E%85%EB%8B%88%EB%8B%A4.'));
    header('Location: ?r=approval_detail&id=' . $id);
    exit;
}

$hasLineDelegated = approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated');
$ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
$vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');

try {
    $pdo->beginTransaction();

    if ($action === 'approve') {
        if (isset($doc['doc_type']) && (string)$doc['doc_type'] === 'unused_leave_plan') {
            $content = approval_parse_content(isset($doc['content']) ? $doc['content'] : '');
            $planTotal = 0.0;
            $planFields = array('plan_notice_date', 'plan_total_days');
            for ($pf = 0; $pf < count($planFields); $pf++) {
                $field = $planFields[$pf];
                if (isset($_POST[$field])) {
                    $content[$field] = trim((string)$_POST[$field]);
                }
            }
            for ($i = 1; $i <= 3; $i++) {
                $periodKey = 'plan_period_' . $i;
                $daysKey = 'plan_days_' . $i;
                $content[$periodKey] = isset($_POST[$periodKey]) ? trim((string)$_POST[$periodKey]) : '';
                $content[$daysKey] = isset($_POST[$daysKey]) ? trim((string)$_POST[$daysKey]) : '';
                if ($content[$daysKey] !== '' && is_numeric($content[$daysKey])) {
                    $planTotal += (float)$content[$daysKey];
                }
            }
            if ((!isset($content['plan_total_days']) || trim((string)$content['plan_total_days']) === '') && $planTotal > 0) {
                $content['plan_total_days'] = ((abs($planTotal - (int)$planTotal) < 0.00001) ? (string)(int)$planTotal : number_format($planTotal, 1, '.', ''));
            }
            $content['receiver_signed_name'] = $actorName;
            $pdo->prepare("UPDATE cpms_approval_documents SET content=:content, updated_at=NOW() WHERE id=:id")
                ->execute(array(
                    ':content' => json_encode($content),
                    ':id' => $id
                ));
            $doc['content'] = json_encode($content);
        }
        $sign = approval_sign_path_by_email($actorEmail);
        $pdo->prepare("UPDATE cpms_approval_lines SET line_status='APPROVED', acted_at=NOW(), sign_path=:s WHERE id=:id AND line_status='PENDING'")
            ->execute(array(':s' => $sign, ':id' => $line['id']));

        $advance = approval_move_to_next_pending_line($pdo, $doc, $id, array('id' => $uid, 'name' => $actorName, 'email' => $actorEmail));
        $nextLine = isset($advance['next_line']) ? $advance['next_line'] : null;

        if ($nextLine) {
            $docStatus = 'PENDING';
            $step = isset($advance['step']) ? (int)$advance['step'] : (int)$nextLine['line_order'];
            try {
                $msg = approval_build_request_message(isset($doc['doc_type']) ? $doc['doc_type'] : '', isset($doc['title']) ? $doc['title'] : '', isset($doc['created_by_name']) ? $doc['created_by_name'] : '');
                approval_queue_notification($pdo, $id, 'REQUEST', $nextLine['approver_id'], $msg);
            } catch (Exception $e) {
            }
        } else {
            $docStatus = 'APPROVED';
            $step = isset($advance['step']) && (int)$advance['step'] > 0 ? (int)$advance['step'] : (int)$line['line_order'];
            $creatorId = isset($doc['created_by_id']) ? (int)$doc['created_by_id'] : 0;
            if ($creatorId > 0) {
                try {
                    approval_queue_notification($pdo, $id, 'FINAL_APPROVED', $creatorId, implode("\n", array('[CPMS ' . approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%B5%9C%EC%A2%85%EC%8A%B9%EC%9D%B8') . ']', '', approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%EC%97%90%EC%84%9C%20%ED%99%95%EC%9D%B8%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'))));
                } catch (Exception $e) {
                }
            }
        }
        $pdo->prepare("UPDATE cpms_approval_documents SET doc_status=:s,current_step_order=:o,updated_at=NOW() WHERE id=:id")
            ->execute(array(':s' => $docStatus, ':o' => $step, ':id' => $id));
        $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,created_at) VALUES (:d,:l,:a,:n,:e,'APPROVE',NOW())")
            ->execute(array(':d' => $id, ':l' => $line['id'], ':a' => $uid, ':n' => $actorName, ':e' => $actorEmail));
        if ($docStatus === 'APPROVED') {
            approval_deduct_leave_balance_on_final_approval($pdo, $id);
        }
    } else if ($action === 'reject') {
        $pdo->prepare("UPDATE cpms_approval_lines SET line_status='REJECTED', acted_at=NOW(), reject_reason=:r WHERE id=:id AND line_status='PENDING'")
            ->execute(array(':r' => $reason, ':id' => $line['id']));
        $pdo->prepare("UPDATE cpms_approval_documents SET doc_status='REJECTED', reject_reason=:r, rejected_step=:s, updated_at=NOW() WHERE id=:id")
            ->execute(array(':r' => $reason, ':s' => $line['role_type'], ':id' => $id));
        $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:l,:a,:n,:e,'REJECT',:r,NOW())")
            ->execute(array(':d' => $id, ':l' => $line['id'], ':a' => $uid, ':n' => $actorName, ':e' => $actorEmail, ':r' => $reason));
        $creatorId = isset($doc['created_by_id']) ? (int)$doc['created_by_id'] : 0;
        if ($creatorId > 0) {
            try {
                approval_queue_notification($pdo, $id, 'REJECTED', $creatorId, implode("\n", array('[CPMS ' . approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EB%B0%98%EB%A0%A4') . ']', approval_ko('%EB%B0%98%EB%A0%A4%EC%82%AC%EC%9C%A0') . ': ' . $reason, '', approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%EC%97%90%EC%84%9C%20%ED%99%95%EC%9D%B8%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'))));
            } catch (Exception $e) {
            }
        }
    }

    $pdo->commit();
    if ($action === 'approve' && isset($docStatus) && $docStatus === 'APPROVED') {
        try {
            cpms_approval_pdf_upload_completed_pdf($pdo, $id, $u);
        } catch (Exception $pdfException) {
            cpms_approval_pdf_log_failure(array(
                'user' => $u,
                'section' => 'approval_completed_pdf_exception',
                'approval_document_id' => $id,
                'document_type' => isset($doc['doc_type']) ? approval_doc_label($doc['doc_type']) : '',
                'project_id' => isset($doc['project_id']) ? (string)$doc['project_id'] : '',
                'message' => 'Completed PDF post-approval job failed: ' . $pdfException->getMessage()
            ));
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[approval_decide] ' . $e->getMessage());
    flash_set('danger', approval_ko('%EA%B2%B0%EC%9E%AC%20%EC%B2%98%EB%A6%AC%20%EC%A4%91%20%EC%98%A4%EB%A5%98%EA%B0%80%20%EB%B0%9C%EC%83%9D%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
}

header('Location: ?r=approval_detail&id=' . $id);
exit;
