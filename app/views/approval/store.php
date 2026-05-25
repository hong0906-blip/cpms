<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/template_helpers.php';
require_once __DIR__ . '/notification_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}
csrf_validate();

$pdo = Db::pdo();
$user = \App\Core\Auth::user();
if (!$pdo || !$user) {
    exit;
}

if (!function_exists('approval_store_column_exists')) {
    function approval_store_column_exists($pdo, $table, $column)
    {
        return approval_table_column_exists($pdo, $table, $column);
    }
}

if (!function_exists('approval_store_employee')) {
    function approval_store_employee($pdo, $id)
    {
        if ((int)$id <= 0) {
            return null;
        }
        $st = $pdo->prepare("SELECT id,name,email,department,position FROM employees WHERE id=:id AND is_active=1 LIMIT 1");
        $st->execute(array(':id' => (int)$id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('approval_store_employee_by_name')) {
    function approval_store_employee_by_name($pdo, $name)
    {
        $st = $pdo->prepare("SELECT id,name,email,department,position FROM employees WHERE name=:name AND is_active=1 LIMIT 1");
        $st->execute(array(':name' => $name));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('approval_store_project_root')) {
    function approval_store_project_root()
    {
        $root = realpath(__DIR__ . '/../../..');
        if ($root && is_dir($root . '/app') && is_dir($root . '/public')) {
            return $root;
        }
        return dirname(dirname(dirname(__DIR__)));
    }
}

if (!function_exists('approval_store_upload_error_message')) {
    function approval_store_upload_error_message($code)
    {
        switch ((int)$code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return approval_ko('%EC%97%85%EB%A1%9C%EB%93%9C%20%ED%97%88%EC%9A%A9%20%EC%9A%A9%EB%9F%89%EC%9D%84%20%EC%B4%88%EA%B3%BC%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            case UPLOAD_ERR_PARTIAL:
                return approval_ko('%ED%8C%8C%EC%9D%BC%EC%9D%B4%20%EC%9D%BC%EB%B6%80%EB%A7%8C%20%EC%97%85%EB%A1%9C%EB%93%9C%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            case UPLOAD_ERR_NO_FILE:
                return approval_ko('%ED%8C%8C%EC%9D%BC%EC%9D%B4%20%EC%84%A0%ED%83%9D%EB%90%98%EC%A7%80%20%EC%95%8A%EC%95%98%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            default:
                return approval_ko('%ED%8C%8C%EC%9D%BC%20%EC%97%85%EB%A1%9C%EB%93%9C%20%EC%98%A4%EB%A5%98%EA%B0%80%20%EB%B0%9C%EC%83%9D%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        }
    }
}

$creatorEmployeeId = approval_current_employee_id($pdo, $user);
$creatorName = approval_current_user_name($user);
$creatorEmail = approval_current_user_email($user);
$docType = isset($_POST['doc_type']) ? trim((string)$_POST['doc_type']) : 'proposal';
if ($docType !== 'leave') {
    $docType = 'proposal';
}

$vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');
$ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
$siteRole = approval_ko('%EC%86%8C%EC%9E%A5');
$teamRole = approval_ko('%ED%8C%80%EC%9E%A5');
$gongmuRole = approval_ko('%EA%B3%B5%EB%AC%B4');
$manageRole = approval_ko('%EA%B4%80%EB%A6%AC');
$pmRole = 'PM';
$parkName = approval_ko('%EB%B0%95%EC%9B%90%EB%8D%95');
$goName = approval_ko('%EA%B3%A0%EC%98%81%EC%84%B1');

$vp = approval_store_employee_by_name($pdo, $vpRole);
if (!$vp) {
    $st = $pdo->prepare("SELECT id,name,email,department,position FROM employees WHERE is_active=1 AND position=:pos LIMIT 1");
    $st->execute(array(':pos' => $vpRole));
    $vp = $st->fetch(PDO::FETCH_ASSOC);
}
$ceo = null;
$st = $pdo->prepare("SELECT id,name,email,department,position FROM employees WHERE is_active=1 AND (position=:p1 OR position=:p2) LIMIT 1");
$st->execute(array(':p1' => $ceoRole, ':p2' => approval_ko('%EB%8C%80%ED%91%9C')));
$ceo = $st->fetch(PDO::FETCH_ASSOC);
if (!$vp || !$ceo) {
    flash_set('danger', approval_ko('%EC%A7%81%EC%9B%90%EB%AA%85%EB%B6%80%EC%97%90%EC%84%9C%20%EB%B6%80%EC%82%AC%EC%9E%A5%20%EB%98%90%EB%8A%94%20%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC%EA%B0%80%20%ED%99%95%EC%9D%B8%EB%90%98%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ?r=approval_create&type=' . $docType);
    exit;
}

$contentData = array();
$title = '';
$lines = array();
$delegateLevel = 'none';

if ($docType === 'leave') {
    $leadId = isset($_POST['team_lead_id']) ? (int)$_POST['team_lead_id'] : 0;
    $lead = approval_store_employee($pdo, $leadId);
    if (!$lead) {
        flash_set('danger', approval_ko('%ED%8C%80%EC%9E%A5%20%EA%B2%B0%EC%9E%AC%EC%9E%90%EB%A5%BC%20%EC%84%A0%ED%83%9D%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=leave');
        exit;
    }
    $start = isset($_POST['leave_start_date']) ? trim((string)$_POST['leave_start_date']) : '';
    $end = isset($_POST['leave_end_date']) ? trim((string)$_POST['leave_end_date']) : '';
    if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
        flash_set('danger', approval_ko('%ED%9C%B4%EA%B0%80%20%EA%B8%B0%EA%B0%84%EC%9D%84%20%EB%8B%A4%EC%8B%9C%20%ED%99%95%EC%9D%B8%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=leave');
        exit;
    }
    $days = isset($_POST['leave_days']) ? trim((string)$_POST['leave_days']) : '';
    if ($days === '' || (float)$days <= 0) {
        $days = (string)(floor((strtotime($end) - strtotime($start)) / 86400) + 1);
    }
    $department = isset($_POST['department']) ? trim((string)$_POST['department']) : '';
    if ($creatorEmployeeId > 0) {
        try {
            $me = approval_store_employee($pdo, $creatorEmployeeId);
            if ($me) {
                if (isset($me['department'])) {
                    $department = trim((string)$me['department']);
                }
                if (isset($me['position'])) {
                    $_POST['position'] = trim((string)$me['position']);
                }
                if (isset($me['name']) && trim((string)$me['name']) !== '') {
                    $_POST['applicant_name'] = trim((string)$me['name']);
                }
            }
        } catch (Exception $e) {
        }
    }
    $normDept = approval_norm_dept($department);
    $leavePm = null;
    if ($normDept === approval_ko('%EA%B3%B5%EC%82%AC') || $normDept === approval_ko('%EC%95%88%EC%A0%84')) {
        $leavePm = approval_store_employee_by_name($pdo, $parkName);
    } else if ($normDept === approval_ko('%EA%B3%B5%EB%AC%B4')) {
        $leavePm = approval_store_employee_by_name($pdo, $goName);
    }
    $contentData = array(
        'request_type' => isset($_POST['request_type']) ? trim((string)$_POST['request_type']) : '',
        'request_type_etc' => isset($_POST['request_type_etc']) ? trim((string)$_POST['request_type_etc']) : '',
        'department' => $department,
        'position' => isset($_POST['position']) ? trim((string)$_POST['position']) : '',
        'applicant_name' => isset($_POST['applicant_name']) ? trim((string)$_POST['applicant_name']) : $creatorName,
        'birth_date' => isset($_POST['birth_date']) ? trim((string)$_POST['birth_date']) : '',
        'leave_start_date' => $start,
        'leave_end_date' => $end,
        'leave_days' => $days,
        'leave_reason' => isset($_POST['leave_reason']) ? trim((string)$_POST['leave_reason']) : '',
        'request_date' => isset($_POST['request_date']) ? trim((string)$_POST['request_date']) : date('Y-m-d'),
        'applicant_sign_name' => isset($_POST['applicant_sign_name']) ? trim((string)$_POST['applicant_sign_name']) : $creatorName,
        'applicant_email' => $creatorEmail,
        'writer_email' => $creatorEmail,
        'ceo_name' => isset($ceo['name']) ? $ceo['name'] : '',
        'delegate_level' => 'ceo'
    );
    $title = approval_doc_label('leave') . ' - ' . $contentData['applicant_name'];
    $lines[] = array('role' => $teamRole, 'emp' => $lead, 'delegated' => 0);
    if ($leavePm) {
        $lines[] = array('role' => $pmRole, 'emp' => $leavePm, 'delegated' => 0);
    }
    $lines[] = array('role' => $vpRole, 'emp' => $vp, 'delegated' => 0);
    $lines[] = array('role' => $ceoRole, 'emp' => $ceo, 'delegated' => 1, 'delegated_by_role' => $vpRole);
    $delegateLevel = 'ceo';
} else {
    $sojang = approval_store_employee($pdo, isset($_POST['sojang_id']) ? (int)$_POST['sojang_id'] : 0);
    $pm = approval_store_employee($pdo, isset($_POST['pm_id']) ? (int)$_POST['pm_id'] : 0);
    $gongmu = approval_store_employee($pdo, isset($_POST['gongmu_id']) ? (int)$_POST['gongmu_id'] : 0);
    $manage = approval_store_employee($pdo, isset($_POST['manage_id']) ? (int)$_POST['manage_id'] : 0);
    if (!$sojang || !$gongmu || !$manage) {
        flash_set('danger', approval_ko('%EC%86%8C%EC%9E%A5%2F%EA%B3%B5%EB%AC%B4%2F%EA%B4%80%EB%A6%AC%20%EA%B2%B0%EC%9E%AC%EC%9E%90%EB%A5%BC%20%EC%84%A0%ED%83%9D%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=proposal');
        exit;
    }
    $delegateLevel = isset($_POST['delegate_level']) ? trim((string)$_POST['delegate_level']) : 'none';
    if (!in_array($delegateLevel, array('none', 'vp', 'ceo'), true)) {
        $delegateLevel = 'none';
    }
    $contentData = array(
        'draft_date' => isset($_POST['draft_date']) ? trim((string)$_POST['draft_date']) : '',
        'effective_date' => isset($_POST['effective_date']) ? trim((string)$_POST['effective_date']) : '',
        'draft_department' => isset($_POST['draft_department']) ? trim((string)$_POST['draft_department']) : '',
        'drafter_name' => isset($_POST['drafter_name']) ? trim((string)$_POST['drafter_name']) : $creatorName,
        'draft_type' => isset($_POST['draft_type']) ? trim((string)$_POST['draft_type']) : '',
        'title' => isset($_POST['title']) ? trim((string)$_POST['title']) : '',
        'headline' => isset($_POST['headline']) ? trim((string)$_POST['headline']) : '',
        'intro_text' => isset($_POST['intro_text']) ? trim((string)$_POST['intro_text']) : '',
        'reason' => isset($_POST['reason']) ? trim((string)$_POST['reason']) : '',
        'company_name' => isset($_POST['company_name']) ? trim((string)$_POST['company_name']) : '',
        'contract_amount' => isset($_POST['contract_amount']) ? trim((string)$_POST['contract_amount']) : '',
        'advance_amount' => isset($_POST['advance_amount']) ? trim((string)$_POST['advance_amount']) : '',
        'special_note_1' => isset($_POST['special_note_1']) ? trim((string)$_POST['special_note_1']) : '',
        'special_note_2' => isset($_POST['special_note_2']) ? trim((string)$_POST['special_note_2']) : '',
        'payment_request_date' => isset($_POST['payment_request_date']) ? trim((string)$_POST['payment_request_date']) : '',
        'budget_status' => isset($_POST['budget_status']) ? trim((string)$_POST['budget_status']) : '',
        'writer_name' => isset($_POST['drafter_name']) ? trim((string)$_POST['drafter_name']) : $creatorName,
        'writer_email' => $creatorEmail,
        'delegate_level' => $delegateLevel
    );
    $title = $contentData['title'] !== '' ? $contentData['title'] : approval_doc_label('proposal');
    $lines[] = array('role' => $siteRole, 'emp' => $sojang, 'delegated' => 0);
    if ($pm) {
        $lines[] = array('role' => $pmRole, 'emp' => $pm, 'delegated' => 0);
    }
    $lines[] = array('role' => $gongmuRole, 'emp' => $gongmu, 'delegated' => 0);
    $lines[] = array('role' => $manageRole, 'emp' => $manage, 'delegated' => 0);
    $lines[] = array('role' => $vpRole, 'emp' => $vp, 'delegated' => ($delegateLevel === 'vp') ? 1 : 0, 'delegated_by_role' => $manageRole);
    $lines[] = array('role' => $ceoRole, 'emp' => $ceo, 'delegated' => ($delegateLevel === 'vp' || $delegateLevel === 'ceo') ? 1 : 0, 'delegated_by_role' => ($delegateLevel === 'vp' ? $manageRole : $vpRole));
}

$hasCreatorEmail = approval_store_column_exists($pdo, 'cpms_approval_documents', 'created_by_email');
$hasDelegateLevel = approval_store_column_exists($pdo, 'cpms_approval_documents', 'delegate_level');
$hasLineDelegated = approval_store_column_exists($pdo, 'cpms_approval_lines', 'is_delegated');
$hasLineDelegatedBy = approval_store_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role');

try {
    $pdo->beginTransaction();

    $docColumns = array('doc_type', 'title', 'content', 'doc_status', 'current_step_order', 'created_by_id', 'created_by_name', 'created_at', 'updated_at');
    $docValues = array(':t', ':ti', ':c', "'PENDING'", '1', ':uid', ':un', 'NOW()', 'NOW()');
    $docParams = array(':t' => $docType, ':ti' => $title, ':c' => json_encode($contentData), ':uid' => $creatorEmployeeId, ':un' => $creatorName);
    if ($hasCreatorEmail) {
        $docColumns[] = 'created_by_email';
        $docValues[] = ':ue';
        $docParams[':ue'] = $creatorEmail;
    }
    if ($hasDelegateLevel) {
        $docColumns[] = 'delegate_level';
        $docValues[] = ':delegate_level';
        $docParams[':delegate_level'] = $delegateLevel;
    }
    $sql = "INSERT INTO cpms_approval_documents (" . implode(',', $docColumns) . ") VALUES (" . implode(',', $docValues) . ")";
    $pdo->prepare($sql)->execute($docParams);
    $did = (int)$pdo->lastInsertId();

    $prepared = array();
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        $emp = $line['emp'];
        $isDelegated = isset($line['delegated']) && (int)$line['delegated'] === 1;
        if ($docType === 'leave' && isset($line['role']) && (string)$line['role'] === $ceoRole) {
            $isDelegated = true;
        }
        $isSelfApprover = false;
        if (!$isDelegated) {
            if ($creatorEmployeeId > 0 && (int)$emp['id'] === (int)$creatorEmployeeId) {
                $isSelfApprover = true;
            } else if ($creatorEmail !== '' && isset($emp['email']) && strtolower(trim((string)$emp['email'])) === strtolower($creatorEmail)) {
                $isSelfApprover = true;
            }
        }
        $status = $isDelegated ? 'DELEGATED' : ($isSelfApprover ? 'SKIPPED' : 'WAITING');
        $prepared[] = array(
            'order' => $i + 1,
            'role' => $line['role'],
            'emp' => $emp,
            'status' => $status,
            'delegated' => $isDelegated ? 1 : 0,
            'delegated_by_role' => isset($line['delegated_by_role']) ? $line['delegated_by_role'] : null
        );
    }

    $first = -1;
    for ($i = 0; $i < count($prepared); $i++) {
        if ($prepared[$i]['status'] === 'WAITING') {
            $first = $i;
            break;
        }
    }
    if ($first >= 0) {
        $prepared[$first]['status'] = 'PENDING';
    }

    for ($i = 0; $i < count($prepared); $i++) {
        $emp = $prepared[$i]['emp'];
        $cols = array('document_id', 'line_order', 'role_type', 'approver_id', 'approver_name', 'approver_email', 'line_status');
        $marks = array(':d', ':o', ':r', ':aid', ':an', ':ae', ':st');
        $params = array(':d' => $did, ':o' => $prepared[$i]['order'], ':r' => $prepared[$i]['role'], ':aid' => $emp['id'], ':an' => $emp['name'], ':ae' => $emp['email'], ':st' => $prepared[$i]['status']);
        if ($hasLineDelegated) {
            $cols[] = 'is_delegated';
            $marks[] = ':is_delegated';
            $params[':is_delegated'] = $prepared[$i]['delegated'];
        }
        if ($hasLineDelegatedBy) {
            $cols[] = 'delegated_by_role';
            $marks[] = ':delegated_by_role';
            $params[':delegated_by_role'] = $prepared[$i]['delegated_by_role'];
        }
        $pdo->prepare("INSERT INTO cpms_approval_lines (" . implode(',', $cols) . ") VALUES (" . implode(',', $marks) . ")")->execute($params);
        if ($prepared[$i]['status'] === 'SKIPPED' || $prepared[$i]['status'] === 'DELEGATED') {
            $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,NULL,:a,:n,:e,:type,:m,NOW())")
                ->execute(array(':d' => $did, ':a' => $creatorEmployeeId, ':n' => $creatorName, ':e' => $creatorEmail, ':type' => $prepared[$i]['status'], ':m' => approval_line_status_label($prepared[$i]['status'])));
        }
    }

    if ($first < 0) {
        $pdo->prepare("UPDATE cpms_approval_documents SET doc_status='APPROVED', updated_at=NOW() WHERE id=:id")->execute(array(':id' => $did));
    } else {
        for ($i = 0; $i < count($prepared); $i++) {
            if ($prepared[$i]['status'] === 'PENDING') {
                try {
                    $msg = approval_build_request_message($docType, $title, $creatorName);
                    approval_queue_notification($pdo, $did, 'REQUEST', $prepared[$i]['emp']['id'], $msg);
                } catch (Exception $e) {
                }
                break;
            }
        }
    }

    if (approval_table_exists($pdo, 'cpms_approval_references')) {
        $selectedRefs = isset($_POST['reference_employee_ids']) && is_array($_POST['reference_employee_ids']) ? $_POST['reference_employee_ids'] : array();
        $lineEmployeeIds = array();
        for ($i = 0; $i < count($prepared); $i++) {
            $lineEmployeeIds[] = (int)$prepared[$i]['emp']['id'];
        }
        $seen = array();
        for ($i = 0; $i < count($selectedRefs); $i++) {
            $rid = (int)$selectedRefs[$i];
            if ($rid <= 0 || isset($seen[$rid])) {
                continue;
            }
            $seen[$rid] = 1;
            if ($creatorEmployeeId > 0 && $rid === (int)$creatorEmployeeId) {
                continue;
            }
            if (in_array($rid, $lineEmployeeIds, true)) {
                continue;
            }
            $refEmp = approval_store_employee($pdo, $rid);
            if (!$refEmp) {
                continue;
            }
            $pdo->prepare("INSERT INTO cpms_approval_references (document_id,employee_id,employee_name,employee_email,employee_department,created_at) VALUES (:d,:eid,:en,:ee,:ed,NOW())")
                ->execute(array(':d' => $did, ':eid' => $rid, ':en' => $refEmp['name'], ':ee' => $refEmp['email'], ':ed' => isset($refEmp['department']) ? $refEmp['department'] : null));
        }
    }

    $uploadWarn = array();
    if ($docType === 'proposal') {
        $allow = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf');
        $labels = array(
            'order_doc' => array(approval_ko('%EB%B0%9C%EC%A3%BC%EC%84%9C'), 'order_doc_file'),
            'business_license' => array(approval_ko('%EC%82%AC%EC%97%85%EC%9E%90%EB%93%B1%EB%A1%9D%EC%A6%9D'), 'business_license_file'),
            'etc' => array(approval_ko('%EA%B8%B0%ED%83%80'), 'etc_file')
        );
        $root = approval_store_project_root();
        $base = rtrim($root, '/\\') . '/storage/approvals/' . $did . '/files';
        if (!is_dir($base)) {
            @mkdir($base, 0777, true);
        }
        foreach ($labels as $ft => $meta) {
            $fname = $meta[1];
            if (!isset($_FILES[$fname]) || !isset($_FILES[$fname]['tmp_name']) || $_FILES[$fname]['tmp_name'] === '') {
                continue;
            }
            if ((int)$_FILES[$fname]['error'] !== UPLOAD_ERR_OK) {
                $uploadWarn[] = $meta[0] . ' ' . approval_store_upload_error_message($_FILES[$fname]['error']);
                continue;
            }
            $orig = (string)$_FILES[$fname]['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allow, true)) {
                $uploadWarn[] = $meta[0] . ' ' . approval_ko('%ED%97%88%EC%9A%A9%EB%90%98%EC%A7%80%20%EC%95%8A%EB%8A%94%20%ED%99%95%EC%9E%A5%EC%9E%90%EC%9E%85%EB%8B%88%EB%8B%A4.');
                continue;
            }
            $saved = $ft . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $ext;
            $dest = $base . '/' . $saved;
            if (!@move_uploaded_file($_FILES[$fname]['tmp_name'], $dest)) {
                $uploadWarn[] = $meta[0] . ' ' . approval_ko('%EC%A0%80%EC%9E%A5%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
                continue;
            }
            $rel = 'storage/approvals/' . $did . '/files/' . $saved;
            $pdo->prepare("INSERT INTO cpms_approval_files (document_id,original_name,saved_name,file_path,file_label,file_type,created_at) VALUES (?,?,?,?,?,?,NOW())")
                ->execute(array($did, $orig, $saved, $rel, $meta[0], $ft));
        }
    }

    $pdo->commit();
    if (count($uploadWarn) > 0) {
        flash_set('danger', implode(', ', $uploadWarn));
    }
    header('Location: ?r=approval_detail&id=' . $did);
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[approval_store] ' . $e->getMessage());
    flash_set('danger', approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%A0%80%EC%9E%A5%20%EC%A4%91%20%EC%98%A4%EB%A5%98%EA%B0%80%20%EB%B0%9C%EC%83%9D%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20DB%20%EC%84%A4%EC%B9%98%2F%ED%99%95%EC%9D%B8%EC%9D%84%20%EB%A8%BC%EC%A0%80%20%EC%8B%A4%ED%96%89%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
    header('Location: ?r=approval_create&type=' . $docType);
    exit;
}
