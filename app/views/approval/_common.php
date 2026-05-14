<?php
use App\Core\Db;

if (!function_exists('approval_status_badge')) {
    function approval_status_badge($status)
    {
        $status = strtoupper((string)$status);
        $map = array(
            'PENDING' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'APPROVED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'REJECTED' => 'bg-rose-50 text-rose-700 border-rose-200',
            'CANCELLED' => 'bg-gray-100 text-gray-700 border-gray-200',
            'DRAFT' => 'bg-amber-50 text-amber-700 border-amber-200'
        );
        return isset($map[$status]) ? $map[$status] : 'bg-gray-100 text-gray-700 border-gray-200';
    }
}
if (!function_exists('approval_parse_content')) {
    function approval_parse_content($content)
    {
        $raw = trim((string)$content);
        if ($raw === '') return array();
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
        return array('legacy_content' => $raw);
    }
}
if (!function_exists('approval_doc_label')) {
    function approval_doc_label($type)
    {
        $t = strtolower((string)$type);
        if ($t === 'leave') return '휴가계';
        return '기안서';
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
if (!function_exists('approval_current_employee_id')) {
    function approval_current_employee_id($pdo, $user)
    {
        if (is_array($user) && isset($user['id']) && (int)$user['id'] > 0) {
            return (int)$user['id'];
        }

        $email = approval_current_user_email($user);
        if ($pdo && $email !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM employees WHERE email=:email LIMIT 1");
                $st->execute(array(':email'=>$email));
                $id = (int)$st->fetchColumn();
                if ($id > 0) { return $id; }
            } catch (Exception $e) {}
        }

        $name = approval_current_user_name($user);
        if ($pdo && $name !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM employees WHERE name=:name LIMIT 1");
                $st->execute(array(':name'=>$name));
                $id = (int)$st->fetchColumn();
                if ($id > 0) { return $id; }
            } catch (Exception $e) {}
        }

        return 0;
    }
}