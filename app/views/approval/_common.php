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
        return $type === '' ? approval_ko('%EB%AC%B8%EC%84%9C') : (string)$type;
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

if (!function_exists('approval_can_view_document')) {
    function approval_can_view_document($pdo, $docRow, $user)
    {
        if (!is_array($docRow) || !isset($docRow['id'])) {
            return false;
        }
        if (approval_is_master_user()) {
            return true;
        }
        if (approval_is_document_owner($pdo, $docRow, $user)) {
            return true;
        }
        $documentId = (int)$docRow['id'];
        if (approval_is_line_approver($pdo, $documentId, $user)) {
            return true;
        }
        $status = strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : '')));
        if (($status === 'APPROVED' || $status === 'COMPLETED') && approval_is_document_reference($pdo, $documentId, $user)) {
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
