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