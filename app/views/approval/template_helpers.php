<?php
if (!function_exists('approval_default_leave_agreement')) {
    function approval_default_leave_agreement()
    {
        return '상기 본인은 위와 같이 연차휴가를 신청하며, 퇴사 시 연차휴가를 정산하여 본인이 사용할 수 있는 연차휴가를 초과하여 사용한 일수에 대해서는 금전으로 환산하여 마지막 급여 또는 퇴직금과 상계하여 지급받는 것을 동의합니다.';
    }
}
if (!function_exists('approval_sign_path_by_email')) {
    function approval_sign_path_by_email($email)
    {
        $prefix = explode('@', (string)$email);
        $name = isset($prefix[0]) ? trim($prefix[0]) : '';
        if ($name === '') { return ''; }
        $rel = 'storage/signatures/'.$name.'.png';
        $abs = dirname(dirname(dirname(__DIR__))).'/'.$rel;
        return is_file($abs) ? $rel : '';
    }
}
if (!function_exists('approval_column_exists')) {
    function approval_column_exists($pdo, $table, $column)
    {
        if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') { return false; }
        try {
            $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column";
            $st = $pdo->prepare($sql);
            $st->execute(array(':table' => (string)$table, ':column' => (string)$column));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}
if (!function_exists('approval_norm_dept')) {
    function approval_norm_dept($d)
    {
        $d=trim((string)$d);
        if($d==='공사부')$d='공사';
        if($d==='공무부')$d='공무';
        if($d==='관리부')$d='관리';
        return $d;
    }
}