<?php
require_once __DIR__.'/template_helpers.php';
if (!function_exists('approval_doc_get')) {
    function approval_doc_get($data, $key, $default)
    {
        if (isset($data[$key]) && trim((string)$data[$key]) !== '') { return (string)$data[$key]; }
        return $default;
    }
}
if (!function_exists('approval_doc_field')) {
    function approval_doc_field($mode, $name, $value, $class, $type='', $placeholder='')
    {
        $type = $type ? $type : 'text';
        $placeholder = $placeholder ? $placeholder : '';
        if ($mode === 'edit') { echo '<input type="'.h($type).'" name="'.h($name).'" value="'.h($value).'" class="'.$class.'" placeholder="'.h($placeholder).'">'; }
        else { echo '<span>'.nl2br(h($value !== '' ? $value : '-')).'</span>'; }
    }
}
if (!function_exists('approval_render_sign_cell')) {
    function approval_render_sign_cell($line)
    {
        $status = isset($line['line_status']) ? $line['line_status'] : 'WAITING';
        if ($status === 'APPROVED') {
            $sig = isset($line['sign_path']) ? trim((string)$line['sign_path']) : '';
            $abs = dirname(dirname(dirname(__DIR__))).'/'.$sig;
            if ($sig !== '' && is_file($abs)) { echo '<img src="../'.h($sig).'" class="doc-sign">'; }
            else { echo '<div>승인완료</div>'; }
            if (!empty($line['acted_at'])) { echo '<div class="doc-time">'.h($line['acted_at']).'</div>'; }
        } elseif ($status === 'REJECTED') {
            echo '<div class="text-red-700">반려</div>';
            if (!empty($line['acted_at'])) { echo '<div class="doc-time">'.h($line['acted_at']).'</div>'; }
        } else {
            echo '<div class="doc-time">대기중</div>';
        }
    }
}
if (!function_exists('approval_doc_format_amount')) {
    function approval_doc_format_amount($v)
    {
        $n = preg_replace('/[^0-9]/', '', (string)$v);
        if ($n === '') return '';
        return number_format((float)$n);
    }
}