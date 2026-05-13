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
    function approval_render_sign_cell($line, $opts)
    {
        $line = is_array($line) ? $line : array();
        $opts = is_array($opts) ? $opts : array();
        $name = isset($opts['name']) ? (string)$opts['name'] : (isset($line['approver_name']) ? (string)$line['approver_name'] : '-');        
        $status = isset($line['line_status']) ? $line['line_status'] : 'WAITING';
        $time = !empty($line['acted_at']) ? $line['acted_at'] : '';
        echo '<td class="approval-sign-cell">';    
        if ($status === 'APPROVED') {
            $sig = isset($line['sign_path']) ? trim((string)$line['sign_path']) : '';
            $abs = dirname(dirname(dirname(__DIR__))).'/'.$sig;
            if ($sig !== '' && is_file($abs)) { echo '<img src="'.h('../'.$sig).'" class="doc-sign">'; }
            else { echo '<div class="doc-time">사인 미등록</div>'; }
        } elseif (!empty($opts['is_delegated'])) {
            echo '<div class="doc-time">전결</div>';
        } else {
            echo '<div class="doc-time">-</div>';
        }
        echo '</td>';
    }
}
if (!function_exists('approval_render_name_cell')) {
    function approval_render_name_cell($name)
    {
        $display = approval_display_name_only($name);
        if ($display === '') { $display = '-'; }
        echo '<td class="approval-name-cell">'.h($display).'</td>';
    }
}
if (!function_exists('approval_render_time_cell')) {
    function approval_render_time_cell($line, $opts)
    {
        $line = is_array($line) ? $line : array();
        $opts = is_array($opts) ? $opts : array();
        $status = isset($line['line_status']) ? $line['line_status'] : 'WAITING';
        $time = !empty($line['acted_at']) ? $line['acted_at'] : '';
        echo '<td class="approval-time-cell">';
        if (!empty($opts['is_drafter']) || !empty($opts['is_delegated'])) {
            echo '-';
        } elseif ($status === 'APPROVED') {
            echo h($time !== '' ? $time : '승인완료');
        } elseif ($status === 'REJECTED') {
            echo '반려';
            if ($time !== '') { echo ' '.h($time); }
        } else {
            echo '대기중';
        }
        echo '</td>';   
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