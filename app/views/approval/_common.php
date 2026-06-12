<?php
use App\Core\Db;

if (!function_exists('approval_ko')) {
    function approval_ko($encoded)
    {
        return urldecode($encoded);
    }
}

if (!function_exists('approval_status_badge')) {
    function approval_status_badge($status)
    {
        $status = strtoupper((string)$status);
        $map = array(
            'DRAFT' => 'bg-slate-100 text-slate-700 border-slate-300',
            'PENDING' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'WAITING' => 'bg-amber-50 text-amber-700 border-amber-200',
            'APPROVED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'COMPLETED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'REJECTED' => 'bg-rose-50 text-rose-700 border-rose-200',
            'CANCELLED' => 'bg-gray-100 text-gray-700 border-gray-300',
            'SKIPPED' => 'bg-slate-100 text-slate-700 border-slate-300',
            'DELEGATED' => 'bg-zinc-100 text-zinc-800 border-zinc-300',
            'REFERENCE' => 'bg-cyan-50 text-cyan-700 border-cyan-200'
        );
        return isset($map[$status]) ? $map[$status] : 'bg-gray-100 text-gray-700 border-gray-300';
    }
}

if (!function_exists('approval_status_label')) {
    function approval_status_label($status)
    {
        $status = strtoupper(trim((string)$status));
        $map = array(
            'DRAFT' => approval_ko('%EC%9E%84%EC%8B%9C%EC%A0%80%EC%9E%A5'),
            'PENDING' => approval_ko('%EC%A7%84%ED%96%89%EC%A4%91'),
            'WAITING' => approval_ko('%EB%8C%80%EA%B8%B0'),
            'APPROVED' => approval_ko('%EC%99%84%EB%A3%8C'),
            'COMPLETED' => approval_ko('%EC%99%84%EB%A3%8C'),
            'REJECTED' => approval_ko('%EB%B0%98%EB%A0%A4'),
            'CANCELLED' => approval_ko('%EC%9A%94%EC%B2%AD%EC%B7%A8%EC%86%8C'),
            'SKIPPED' => approval_ko('%EA%B1%B4%EB%84%88%EB%9C%80'),
            'DELEGATED' => approval_ko('%EC%A0%84%EA%B2%B0'),
            'REFERENCE' => approval_ko('%EC%B0%B8%EC%A1%B0')
        );
        return isset($map[$status]) ? $map[$status] : $status;
    }
}

if (!function_exists('approval_line_status_label')) {
    function approval_line_status_label($status)
    {
        return approval_status_label($status);
    }
}

if (!function_exists('approval_role_label')) {
    function approval_role_label($role)
    {
        $role = trim((string)$role);
        $lower = strtolower($role);
        $map = array(
            'site_manager' => approval_ko('%EC%86%8C%EC%9E%A5'),
            'sojang' => approval_ko('%EC%86%8C%EC%9E%A5'),
            'pm' => 'PM',
            'gongmu' => approval_ko('%EA%B3%B5%EB%AC%B4'),
            'manage' => approval_ko('%EA%B4%80%EB%A6%AC'),
            'admin' => approval_ko('%EA%B4%80%EB%A6%AC'),
            'vp' => approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'),
            'ceo' => approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'),
            'team_lead' => approval_ko('%ED%8C%80%EC%9E%A5'),
            'leader' => approval_ko('%ED%8C%80%EC%9E%A5')
        );
        if (isset($map[$lower])) {
            return $map[$lower];
        }
        return $role === '' ? '-' : $role;
    }
}

if (!function_exists('approval_parse_content')) {
    function approval_parse_content($content)
    {
        $raw = trim((string)$content);
        if ($raw === '') {
            return array();
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
        return array('legacy_content' => $raw);
    }
}

if (!function_exists('approval_doc_label')) {
    function approval_doc_label($type)
    {
        $type = strtolower(trim((string)$type));
        if ($type === 'leave') {
            return approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84');
        }
        if ($type === 'proposal') {
            return approval_ko('%EA%B8%B0%EC%95%88%EC%84%9C');
        }
        if ($type === 'unused_leave_notice') {
            return approval_ko('%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EC%B4%89%EA%B5%AC%EC%84%9C');
        }
        if ($type === 'unused_leave_plan') {
            return approval_ko('%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B3%84%ED%9A%8D%EC%84%9C');
        }
        return $type === '' ? approval_ko('%EB%AC%B8%EC%84%9C') : (string)$type;
    }
}

if (!function_exists('approval_is_management_only_doc_type')) {
    function approval_is_management_only_doc_type($type)
    {
        $type = strtolower(trim((string)$type));
        return in_array($type, array('unused_leave_notice', 'unused_leave_plan'), true);
    }
}

if (!function_exists('approval_auto_delegate_target_doc_type')) {
    function approval_auto_delegate_target_doc_type($type)
    {
        $type = strtolower(trim((string)$type));
        return in_array($type, array('leave', 'proposal'), true);
    }
}

if (!function_exists('approval_normalize_compare_text')) {
    function approval_normalize_compare_text($value)
    {
        $value = trim((string)$value);
        $value = str_replace(array(' ', "\t", "\r", "\n", '-', '_', '/', '.', ','), '', $value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

if (!function_exists('approval_employee_identity_matches')) {
    function approval_employee_identity_matches($employee, $id, $email, $name)
    {
        if (!is_array($employee)) {
            return false;
        }
        if ((int)$id > 0 && isset($employee['id']) && (int)$employee['id'] === (int)$id) {
            return true;
        }
        if (trim((string)$email) !== '' && isset($employee['email']) && strtolower(trim((string)$employee['email'])) === strtolower(trim((string)$email))) {
            return true;
        }
        if (trim((string)$name) !== '' && isset($employee['name']) && trim((string)$employee['name']) === trim((string)$name)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('approval_line_matches_employee')) {
    function approval_line_matches_employee($line, $employee)
    {
        if (!is_array($line) || !is_array($employee)) {
            return false;
        }
        $id = isset($line['approver_id']) ? (int)$line['approver_id'] : (isset($line['emp']['id']) ? (int)$line['emp']['id'] : 0);
        $email = isset($line['approver_email']) ? (string)$line['approver_email'] : (isset($line['emp']['email']) ? (string)$line['emp']['email'] : '');
        $name = isset($line['approver_name']) ? (string)$line['approver_name'] : (isset($line['emp']['name']) ? (string)$line['emp']['name'] : '');
        return approval_employee_identity_matches($employee, $id, $email, $name);
    }
}

if (!function_exists('approval_role_is_vp')) {
    function approval_role_is_vp($role)
    {
        $roleNorm = approval_normalize_compare_text(approval_role_label($role));
        $vpNorm = approval_normalize_compare_text(approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'));
        return ($roleNorm === 'vp' || $roleNorm === $vpNorm);
    }
}

if (!function_exists('approval_role_is_ceo')) {
    function approval_role_is_ceo($role)
    {
        $roleNorm = approval_normalize_compare_text(approval_role_label($role));
        $ceoNorm = approval_normalize_compare_text(approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'));
        $ceoShortNorm = approval_normalize_compare_text(approval_ko('%EB%8C%80%ED%91%9C'));
        return ($roleNorm === 'ceo' || $roleNorm === $ceoNorm || $roleNorm === $ceoShortNorm);
    }
}

if (!function_exists('approval_role_is_team_or_pm')) {
    function approval_role_is_team_or_pm($role)
    {
        $roleNorm = approval_normalize_compare_text(approval_role_label($role));
        $teamNorm = approval_normalize_compare_text(approval_ko('%ED%8C%80%EC%9E%A5'));
        return ($roleNorm === 'pm' || $roleNorm === $teamNorm);
    }
}

if (!function_exists('approval_employee_text_blob')) {
    function approval_employee_text_blob($employee)
    {
        if (!is_array($employee)) {
            return '';
        }
        $parts = array();
        $keys = array('name', 'position', 'role', 'department');
        for ($i = 0; $i < count($keys); $i++) {
            if (isset($employee[$keys[$i]]) && trim((string)$employee[$keys[$i]]) !== '') {
                $parts[] = (string)$employee[$keys[$i]];
            }
        }
        return approval_normalize_compare_text(implode(' ', $parts));
    }
}

if (!function_exists('approval_employee_is_vp')) {
    function approval_employee_is_vp($employee)
    {
        $blob = approval_employee_text_blob($employee);
        if ($blob === '') {
            return false;
        }
        $words = array(
            approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'),
            'vicepresident',
            'vicepres',
            'vp'
        );
        for ($i = 0; $i < count($words); $i++) {
            $w = approval_normalize_compare_text($words[$i]);
            if ($w !== '' && strpos($blob, $w) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('approval_employee_is_executive')) {
    function approval_employee_is_executive($employee)
    {
        $blob = approval_employee_text_blob($employee);
        if ($blob === '') {
            return false;
        }
        $words = array(
            'executive',
            'ceo',
            'president',
            'vp',
            'vicepresident',
            approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'),
            approval_ko('%EB%8C%80%ED%91%9C'),
            approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'),
            approval_ko('%EC%83%81%EB%AC%B4'),
            approval_ko('%EC%A0%84%EB%AC%B4'),
            approval_ko('%EC%9D%B4%EC%82%AC')
        );
        for ($i = 0; $i < count($words); $i++) {
            $w = approval_normalize_compare_text($words[$i]);
            if ($w !== '' && strpos($blob, $w) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('approval_is_employee_on_leave')) {
    function approval_is_employee_on_leave($pdo, $employeeId, $baseDate)
    {
        $employeeId = (int)$employeeId;
        if (!$pdo || $employeeId <= 0 || !approval_table_exists($pdo, 'cpms_leave_records')) {
            return false;
        }
        $baseDate = trim((string)$baseDate);
        $ts = strtotime($baseDate);
        if (!$ts) {
            $baseDate = date('Y-m-d');
        } else {
            $baseDate = date('Y-m-d', $ts);
        }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM cpms_leave_records WHERE employee_id=:employee_id AND leave_date=:leave_date AND COALESCE(leave_amount,0) > 0");
            $st->execute(array(':employee_id' => $employeeId, ':leave_date' => $baseDate));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_auto_delegate_reason_label')) {
    function approval_auto_delegate_reason_label($reasonCode)
    {
        $reasonCode = trim((string)$reasonCode);
        $map = array(
            'higher_position' => approval_ko('%EC%83%81%EC%9C%84%20%EC%A7%81%EA%B8%89%20%EA%B8%B0%EC%95%88%EC%9C%BC%EB%A1%9C%20%EC%9D%B8%ED%95%9C%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'on_leave' => approval_ko('%ED%9C%B4%EA%B0%80%EC%9E%90%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'self' => approval_ko('%EB%B3%B8%EC%9D%B8%20%EA%B2%B0%EC%9E%AC%EB%8B%A8%EA%B3%84%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'previous_step' => approval_ko('%EC%9D%B4%EC%A0%84%20%EB%8B%A8%EA%B3%84%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'vp_leave_ceo_proxy' => approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5%20%ED%9C%B4%EA%B0%80%EB%A1%9C%20%EB%8C%80%ED%91%9C%20%EB%8C%80%EB%A6%AC%20%EA%B2%B0%EC%9E%AC'),
            'leave_ceo_default' => approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84%20%EB%8C%80%ED%91%9C%20%EB%8B%A8%EA%B3%84%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'auto' => approval_ko('%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0')
        );
        return isset($map[$reasonCode]) ? $map[$reasonCode] : approval_ko('%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0');
    }
}

if (!function_exists('approval_auto_delegate_note')) {
    function approval_auto_delegate_note($line, $reason)
    {
        $role = '';
        $name = '';
        if (is_array($line)) {
            $role = isset($line['role_type']) ? (string)$line['role_type'] : (isset($line['role']) ? (string)$line['role'] : '');
            $name = isset($line['approver_name']) ? (string)$line['approver_name'] : (isset($line['emp']['name']) ? (string)$line['emp']['name'] : '');
        }
        $label = approval_role_label($role);
        $parts = array();
        if ($label !== '-' && $label !== '') {
            $parts[] = $label;
        }
        if (trim($name) !== '') {
            $parts[] = trim($name);
        }
        $prefix = count($parts) > 0 ? implode(' / ', $parts) . ' - ' : '';
        return $prefix . trim((string)$reason);
    }
}

if (!function_exists('approval_line_auto_note')) {
    function approval_line_auto_note($line)
    {
        if (!is_array($line) || !isset($line['reject_reason'])) {
            return '';
        }
        return trim((string)$line['reject_reason']);
    }
}

if (!function_exists('approval_line_is_delegated_status')) {
    function approval_line_is_delegated_status($line)
    {
        if (!is_array($line)) {
            return false;
        }
        $status = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
        return ($status === 'DELEGATED' || (isset($line['is_delegated']) && (int)$line['is_delegated'] === 1));
    }
}

if (!function_exists('approval_insert_auto_delegate_log')) {
    function approval_insert_auto_delegate_log($pdo, $documentId, $lineId, $line, $reason, $actor)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_logs')) {
            return;
        }
        $actorId = 0;
        $actorName = approval_ko('%EC%8B%9C%EC%8A%A4%ED%85%9C');
        $actorEmail = '';
        if (is_array($actor)) {
            if (isset($actor['id'])) {
                $actorId = (int)$actor['id'];
            }
            if (isset($actor['name']) && trim((string)$actor['name']) !== '') {
                $actorName = trim((string)$actor['name']);
            }
            if (isset($actor['email'])) {
                $actorEmail = trim((string)$actor['email']);
            }
        }
        $note = approval_auto_delegate_note($line, $reason);
        try {
            $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:l,:a,:n,:e,'DELEGATED',:m,NOW())")
                ->execute(array(':d' => (int)$documentId, ':l' => ((int)$lineId > 0 ? (int)$lineId : null), ':a' => $actorId, ':n' => $actorName, ':e' => $actorEmail, ':m' => $note));
        } catch (Exception $e) {
        }
    }
}

if (!function_exists('approval_mark_line_delegated')) {
    function approval_mark_line_delegated($pdo, $documentId, $line, $reason, $actor, $delegatedByRole)
    {
        if (!$pdo || !is_array($line) || !isset($line['id']) || (int)$line['id'] <= 0) {
            return;
        }
        $lineId = (int)$line['id'];
        $oldStatus = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
        $sets = array("line_status='DELEGATED'");
        $params = array(':id' => $lineId);
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'acted_at')) {
            $sets[] = 'acted_at=NOW()';
        }
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
            $sets[] = 'is_delegated=1';
        }
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'reject_reason')) {
            $sets[] = 'reject_reason=:reason_note';
            $params[':reason_note'] = trim((string)$reason);
        }
        if ($delegatedByRole !== null && approval_table_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role')) {
            $sets[] = 'delegated_by_role=:delegated_by_role';
            $params[':delegated_by_role'] = $delegatedByRole;
        }
        try {
            $pdo->prepare("UPDATE cpms_approval_lines SET " . implode(',', $sets) . " WHERE id=:id")->execute($params);
            if ($oldStatus !== 'DELEGATED') {
                approval_insert_auto_delegate_log($pdo, $documentId, $lineId, $line, $reason, $actor);
            }
        } catch (Exception $e) {
        }
    }
}

if (!function_exists('approval_document_requires_ceo_for_vp_leave')) {
    function approval_document_requires_ceo_for_vp_leave($pdo, $docType, $lines, $baseDate)
    {
        if (!approval_auto_delegate_target_doc_type($docType) || !is_array($lines)) {
            return false;
        }
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $role = isset($line['role_type']) ? (string)$line['role_type'] : (isset($line['role']) ? (string)$line['role'] : '');
            if (!approval_role_is_vp($role)) {
                continue;
            }
            $status = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
            if (in_array($status, array('APPROVED', 'REJECTED'), true)) {
                continue;
            }
            $employeeId = isset($line['approver_id']) ? (int)$line['approver_id'] : (isset($line['emp']['id']) ? (int)$line['emp']['id'] : 0);
            if (approval_is_employee_on_leave($pdo, $employeeId, $baseDate)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('approval_force_ceo_waiting_for_vp_leave')) {
    function approval_force_ceo_waiting_for_vp_leave($pdo, $documentId, $lines)
    {
        if (!$pdo || (int)$documentId <= 0 || !is_array($lines)) {
            return;
        }
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (!isset($line['id']) || !approval_role_is_ceo(isset($line['role_type']) ? $line['role_type'] : '')) {
                continue;
            }
            $status = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
            if (in_array($status, array('APPROVED', 'REJECTED'), true)) {
                continue;
            }
            $sets = array("line_status='WAITING'");
            $params = array(':id' => (int)$line['id']);
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
                $sets[] = 'is_delegated=0';
            }
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role')) {
                $sets[] = 'delegated_by_role=NULL';
            }
            try {
                $pdo->prepare("UPDATE cpms_approval_lines SET " . implode(',', $sets) . " WHERE id=:id")->execute($params);
            } catch (Exception $e) {
            }
        }
    }
}

if (!function_exists('approval_move_to_next_pending_line')) {
    function approval_move_to_next_pending_line($pdo, $docRow, $documentId, $actor)
    {
        $result = array('doc_status' => 'APPROVED', 'step' => 0, 'next_line' => null);
        if (!$pdo || (int)$documentId <= 0) {
            return $result;
        }
        $docType = isset($docRow['doc_type']) ? (string)$docRow['doc_type'] : '';
        $baseDate = date('Y-m-d');
        $ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
        $vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:d ORDER BY line_order ASC");
            $st->execute(array(':d' => (int)$documentId));
            $lines = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($lines)) {
                $lines = array();
            }
        } catch (Exception $e) {
            $lines = array();
        }
        $limit = count($lines);
        $forceCeo = approval_document_requires_ceo_for_vp_leave($pdo, $docType, $lines, $baseDate);
        if ($forceCeo) {
            approval_force_ceo_waiting_for_vp_leave($pdo, $documentId, $lines);
            try {
                $st = $pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:d ORDER BY line_order ASC");
                $st->execute(array(':d' => (int)$documentId));
                $lines = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($lines)) {
                    $lines = array();
                }
            } catch (Exception $e) {
                $lines = array();
            }
        }
        for ($i = 0; $i < count($lines) && $i < $limit; $i++) {
            $line = $lines[$i];
            $status = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
            if ($status !== 'WAITING') {
                continue;
            }
            $role = isset($line['role_type']) ? (string)$line['role_type'] : '';
            $employeeId = isset($line['approver_id']) ? (int)$line['approver_id'] : 0;
            if (approval_auto_delegate_target_doc_type($docType) && approval_role_is_vp($role) && approval_is_employee_on_leave($pdo, $employeeId, $baseDate)) {
                approval_mark_line_delegated($pdo, $documentId, $line, approval_auto_delegate_reason_label('vp_leave_ceo_proxy'), $actor, $ceoRole);
                continue;
            }
            if ($docType === 'leave' && approval_role_is_ceo($role) && !$forceCeo) {
                approval_mark_line_delegated($pdo, $documentId, $line, approval_auto_delegate_reason_label('leave_ceo_default'), $actor, $vpRole);
                continue;
            }
            if (approval_line_is_delegated_status($line)) {
                $note = approval_line_auto_note($line);
                if ($note === '') {
                    $note = approval_auto_delegate_reason_label('auto');
                }
                approval_mark_line_delegated($pdo, $documentId, $line, $note, $actor, isset($line['delegated_by_role']) ? $line['delegated_by_role'] : null);
                continue;
            }
            if (approval_auto_delegate_target_doc_type($docType) && approval_is_employee_on_leave($pdo, $employeeId, $baseDate)) {
                approval_mark_line_delegated($pdo, $documentId, $line, approval_auto_delegate_reason_label('on_leave'), $actor, null);
                continue;
            }
            $sets = array("line_status='PENDING'");
            $params = array(':id' => (int)$line['id']);
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
                $sets[] = 'is_delegated=0';
            }
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role')) {
                $sets[] = 'delegated_by_role=NULL';
            }
            $pdo->prepare("UPDATE cpms_approval_lines SET " . implode(',', $sets) . " WHERE id=:id")->execute($params);
            $line['line_status'] = 'PENDING';
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
                $line['is_delegated'] = 0;
            }
            $result['doc_status'] = 'PENDING';
            $result['step'] = isset($line['line_order']) ? (int)$line['line_order'] : 0;
            $result['next_line'] = $line;
            return $result;
        }
        if (count($lines) > 0) {
            $last = $lines[count($lines) - 1];
            $result['step'] = isset($last['line_order']) ? (int)$last['line_order'] : 0;
        }
        return $result;
    }
}

if (!function_exists('approval_current_user_email')) {
    function approval_current_user_email($user)
    {
        if (!is_array($user) || !isset($user['email'])) {
            return '';
        }
        return trim((string)$user['email']);
    }
}

if (!function_exists('approval_current_user_name')) {
    function approval_current_user_name($user)
    {
        if (!is_array($user) || !isset($user['name'])) {
            return '';
        }
        return trim((string)$user['name']);
    }
}

if (!function_exists('approval_table_exists')) {
    function approval_table_exists($pdo, $table)
    {
        if (!$pdo || trim((string)$table) === '') {
            return false;
        }
        try {
            $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($db === '') {
                return false;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl");
            $st->execute(array(':db' => $db, ':tbl' => $table));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_table_column_exists')) {
    function approval_table_column_exists($pdo, $table, $column)
    {
        if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') {
            return false;
        }
        try {
            $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($db === '') {
                return false;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
            $st->execute(array(':db' => $db, ':tbl' => $table, ':col' => $column));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_current_employee_id')) {
    function approval_current_employee_id($pdo, $user)
    {
        if (is_array($user) && isset($user['employee_id']) && (int)$user['employee_id'] > 0) {
            return (int)$user['employee_id'];
        }

        $email = approval_current_user_email($user);
        if ($pdo && $email !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM employees WHERE email=:email LIMIT 1");
                $st->execute(array(':email' => $email));
                $id = (int)$st->fetchColumn();
                if ($id > 0) {
                    return $id;
                }
            } catch (Exception $e) {
            }
        }

        $name = approval_current_user_name($user);
        if ($pdo && $name !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM employees WHERE name=:name LIMIT 1");
                $st->execute(array(':name' => $name));
                $id = (int)$st->fetchColumn();
                if ($id > 0) {
                    return $id;
                }
            } catch (Exception $e) {
            }
        }

        if (is_array($user) && isset($user['id']) && (int)$user['id'] > 0) {
            return (int)$user['id'];
        }

        return 0;
    }
}

if (!function_exists('approval_is_document_owner')) {
    function approval_is_document_owner($pdo, $docRow, $user)
    {
        if (!is_array($docRow) || !is_array($user)) {
            return false;
        }

        $uid = approval_current_employee_id($pdo, $user);
        $userName = approval_current_user_name($user);
        $userEmail = approval_current_user_email($user);

        if ($uid > 0 && isset($docRow['created_by_id']) && (int)$docRow['created_by_id'] === (int)$uid) {
            return true;
        }
        if ($userName !== '' && isset($docRow['created_by_name']) && trim((string)$docRow['created_by_name']) === $userName) {
            return true;
        }
        if ($userEmail !== '' && isset($docRow['created_by_email']) && trim((string)$docRow['created_by_email']) === $userEmail) {
            return true;
        }
        return false;
    }
}

if (!function_exists('approval_is_line_approver')) {
    function approval_is_line_approver($pdo, $documentId, $user)
    {
        if (!$pdo || (int)$documentId <= 0) {
            return false;
        }
        $uid = approval_current_employee_id($pdo, $user);
        $userEmail = approval_current_user_email($user);
        $userName = approval_current_user_name($user);
        $parts = array();
        $params = array(':document_id' => (int)$documentId);
        if ($uid > 0) {
            $parts[] = 'approver_id = :uid';
            $params[':uid'] = $uid;
        }
        if ($userEmail !== '') {
            $parts[] = 'LOWER(TRIM(approver_email)) = LOWER(TRIM(:email))';
            $params[':email'] = $userEmail;
        }
        if ($userName !== '') {
            $parts[] = 'approver_name = :name';
            $params[':name'] = $userName;
        }
        if (count($parts) === 0) {
            return false;
        }
        try {
            $sql = "SELECT COUNT(*) FROM cpms_approval_lines WHERE document_id=:document_id AND (" . implode(' OR ', $parts) . ")";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_is_document_reference')) {
    function approval_is_document_reference($pdo, $documentId, $user)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_references')) {
            return false;
        }
        $uid = approval_current_employee_id($pdo, $user);
        $userEmail = approval_current_user_email($user);
        $userName = approval_current_user_name($user);
        $parts = array();
        $params = array(':document_id' => (int)$documentId);
        if ($uid > 0) {
            $parts[] = 'employee_id = :uid';
            $params[':uid'] = $uid;
        }
        if ($userEmail !== '') {
            $parts[] = 'LOWER(TRIM(employee_email)) = LOWER(TRIM(:email))';
            $params[':email'] = $userEmail;
        }
        if ($userName !== '') {
            $parts[] = 'employee_name = :name';
            $params[':name'] = $userName;
        }
        if (count($parts) === 0) {
            return false;
        }
        try {
            $sql = "SELECT COUNT(*) FROM cpms_approval_references WHERE document_id=:document_id AND (" . implode(' OR ', $parts) . ")";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_is_master_user')) {
    function approval_is_master_user()
    {
        return \App\Core\Auth::isMaster();
    }
}

if (!function_exists('approval_is_admin_user')) {
    function approval_is_admin_user($user)
    {
        return \App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole() === 'executive';
    }
}

if (!function_exists('approval_is_management_department_value')) {
    function approval_is_management_department_value($dept)
    {
        $dept = trim((string)$dept);
        if ($dept === '관리' || $dept === '관리부' || $dept === '관리팀') {
            return true;
        }
        return false;
    }
}

if (!function_exists('approval_is_management_department_user')) {
    function approval_is_management_department_user($pdo, $user)
    {
        if (\App\Core\Auth::isMaster()) {
            return true;
        }
        if (is_array($user) && isset($user['department']) && approval_is_management_department_value($user['department'])) {
            return true;
        }
        if (!$pdo || !is_array($user)) {
            return false;
        }
        try {
            $parts = array();
            $params = array();
            if (isset($user['id']) && (int)$user['id'] > 0) {
                $parts[count($parts)] = 'id=:id';
                $params[':id'] = (int)$user['id'];
            }
            if (isset($user['email']) && trim((string)$user['email']) !== '') {
                $parts[count($parts)] = 'LOWER(TRIM(email))=LOWER(TRIM(:email))';
                $params[':email'] = trim((string)$user['email']);
            }
            if (isset($user['name']) && trim((string)$user['name']) !== '') {
                $parts[count($parts)] = 'name=:name';
                $params[':name'] = trim((string)$user['name']);
            }
            if (count($parts) === 0) {
                return false;
            }
            $sql = "SELECT department FROM employees WHERE " . implode(' OR ', $parts) . " LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $dept = $st->fetchColumn();
            return approval_is_management_department_value($dept);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_can_view_document')) {
    function approval_can_view_document($pdo, $docRow, $user)
    {
        if (!is_array($docRow) || !isset($docRow['id'])) {
            return false;
        }
        if (approval_is_management_only_doc_type(isset($docRow['doc_type']) ? $docRow['doc_type'] : '')) {
            return approval_is_management_department_user($pdo, $user);
        }
        if (approval_is_master_user()) {
            return true;
        }
        $status = strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : '')));
        if (in_array($status, array('APPROVED', 'COMPLETED'), true) && approval_is_management_department_user($pdo, $user)) {
            return true;
        }
        if (approval_is_document_owner($pdo, $docRow, $user)) {
            return true;
        }
        $documentId = (int)$docRow['id'];
        if (approval_is_line_approver($pdo, $documentId, $user)) {
            return true;
        }
        if (approval_is_document_reference($pdo, $documentId, $user)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('approval_can_cancel_document')) {
    function approval_can_cancel_document($docRow)
    {
        if (!is_array($docRow) || !isset($docRow['doc_status'])) {
            return false;
        }
        $status = strtoupper(trim((string)$docRow['doc_status']));
        return in_array($status, array('PENDING', 'DRAFT'), true);
    }
}

if (!function_exists('approval_can_delete_document')) {
    function approval_can_delete_document($pdo, $docRow, $user)
    {
        if (!is_array($docRow)) {
            return false;
        }
        $status = strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : '')));
        if ($status !== 'CANCELLED') {
            return false;
        }
        if (approval_is_document_owner($pdo, $docRow, $user)) {
            return true;
        }
        return approval_is_master_user();
    }
}

if (!function_exists('approval_fetch_references')) {
    function approval_fetch_references($pdo, $documentId)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_references')) {
            return array();
        }
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_approval_references WHERE document_id=:id ORDER BY employee_name ASC, id ASC");
            $st->execute(array(':id' => (int)$documentId));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }
}

if (!function_exists('approval_document_title_by_view')) {
    function approval_document_title_by_view($view)
    {
        if ($view === 'cancelled') {
            return approval_ko('%EC%B7%A8%EC%86%8C%EB%AC%B8%EC%84%9C');
        }
        if ($view === 'completed') {
            return approval_ko('%EC%99%84%EB%A3%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C');
        }
        return approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%A7%84%ED%96%89%EB%AC%B8%EC%84%9C');
    }
}

if (!function_exists('approval_document_empty_message')) {
    function approval_document_empty_message($view)
    {
        if ($view === 'cancelled') {
            return approval_ko('%EC%B7%A8%EC%86%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        }
        if ($view === 'completed') {
            return approval_ko('%EC%99%84%EB%A3%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        }
        return approval_ko('%EC%A7%84%ED%96%89%EC%A4%91%EC%9D%B8%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
    }
}
