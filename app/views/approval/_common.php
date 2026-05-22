<?php
use App\Core\Db;

if (!function_exists('approval_status_badge')) {
    function approval_status_badge($status)
    {
        $status = strtoupper((string)$status);
        $map = array(
            'PENDING' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'APPROVED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'COMPLETED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'REJECTED' => 'bg-rose-50 text-rose-700 border-rose-200',
            'CANCELLED' => 'bg-gray-100 text-gray-700 border-gray-300',
            'DRAFT' => 'bg-slate-100 text-slate-700 border-slate-300',
            'WAITING' => 'bg-amber-50 text-amber-700 border-amber-200'
        );
        return isset($map[$status]) ? $map[$status] : 'bg-gray-100 text-gray-700 border-gray-300';
    }
}

if (!function_exists('approval_status_label')) {
    function approval_status_label($status)
    {
        $status = strtoupper((string)$status);
        $map = array(
            'PENDING' => '진행중',
            'APPROVED' => '완료',
            'COMPLETED' => '완료',
            'REJECTED' => '반려',
            'CANCELLED' => '요청취소',
            'DRAFT' => '임시저장',
            'WAITING' => '대기'
        );
        return isset($map[$status]) ? $map[$status] : $status;
    }
}

if (!function_exists('approval_line_status_label')) {
    function approval_line_status_label($status)
    {
        $status = strtoupper((string)$status);
        $map = array(
            'WAITING' => '대기',
            'PENDING' => '진행중',
            'APPROVED' => '승인',
            'REJECTED' => '반려',
            'SKIPPED' => '건너뜀',
            'CANCELLED' => '요청취소'
        );
        return isset($map[$status]) ? $map[$status] : $status;
    }
}

if (!function_exists('approval_parse_content')) {
    function approval_parse_content($content)
    {
        $raw = trim((string)$content);
        if ($raw === '') { return array(); }
        $json = json_decode($raw, true);
        if (is_array($json)) { return $json; }
        return array('legacy_content' => $raw);
    }
}

if (!function_exists('approval_doc_label')) {
    function approval_doc_label($type)
    {
        $t = strtolower((string)$type);
        if ($t === 'leave') { return '휴가계'; }
        if ($t === 'proposal') { return '기안서'; }
        return trim((string)$type) === '' ? '문서' : (string)$type;
    }
}

if (!function_exists('approval_current_user_email')) {
    function approval_current_user_email($user)
    {
        if (!is_array($user) || !isset($user['email'])) { return ''; }
        return trim((string)$user['email']);
    }
}

if (!function_exists('approval_current_user_name')) {
    function approval_current_user_name($user)
    {
        if (!is_array($user) || !isset($user['name'])) { return ''; }
        return trim((string)$user['name']);
    }
}

if (!function_exists('approval_table_column_exists')) {
    function approval_table_column_exists($pdo, $table, $column)
    {
        if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') { return false; }
        try {
            $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($db === '') { return false; }
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
                if ($id > 0) { return $id; }
            } catch (Exception $e) {}
        }

        $name = approval_current_user_name($user);
        if ($pdo && $name !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM employees WHERE name=:name LIMIT 1");
                $st->execute(array(':name' => $name));
                $id = (int)$st->fetchColumn();
                if ($id > 0) { return $id; }
            } catch (Exception $e) {}
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
        if (!is_array($docRow) || !is_array($user)) { return false; }

        $isOwner = false;
        $uid = approval_current_employee_id($pdo, $user);
        $userName = approval_current_user_name($user);
        $userEmail = approval_current_user_email($user);

        if ($uid > 0 && isset($docRow['created_by_id']) && (int)$docRow['created_by_id'] === (int)$uid) {
            $isOwner = true;
        }

        if (!$isOwner && $userName !== '' && isset($docRow['created_by_name']) && trim((string)$docRow['created_by_name']) === $userName) {
            $isOwner = true;
        }

        if (!$isOwner && $userEmail !== '' && isset($docRow['created_by_email']) && trim((string)$docRow['created_by_email']) === $userEmail) {
            $isOwner = true;
        }

        return $isOwner;
    }
}

if (!function_exists('approval_is_admin_user')) {
    function approval_is_admin_user($user)
    {
        return \App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole() === 'executive';
    }
}

if (!function_exists('approval_can_cancel_document')) {
    function approval_can_cancel_document($docRow)
    {
        if (!is_array($docRow) || !isset($docRow['doc_status'])) { return false; }
        $status = strtoupper(trim((string)$docRow['doc_status']));
        return in_array($status, array('PENDING', 'DRAFT'), true);
    }
}

if (!function_exists('approval_can_delete_document')) {
    function approval_can_delete_document($pdo, $docRow, $user)
    {
        if (!is_array($docRow) || strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : ''))) !== 'CANCELLED') {
            return false;
        }
        if (approval_is_document_owner($pdo, $docRow, $user)) { return true; }
        return approval_is_admin_user($user);
    }
}

if (!function_exists('approval_document_title_by_view')) {
    function approval_document_title_by_view($view)
    {
        if ($view === 'cancelled') { return '취소문서'; }
        if ($view === 'completed') { return '완료된 문서'; }
        return '전자결재 진행문서';
    }
}

if (!function_exists('approval_document_empty_message')) {
    function approval_document_empty_message($view)
    {
        if ($view === 'cancelled') { return '취소된 문서가 없습니다.'; }
        if ($view === 'completed') { return '완료된 문서가 없습니다.'; }
        return '진행중인 문서가 없습니다.';
    }
}
