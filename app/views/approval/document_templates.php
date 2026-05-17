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
        $status = isset($line['line_status']) ? (string)$line['line_status'] : '';
        $isApproved = ($status === 'APPROVED' || $status === 'SKIPPED');
        $isDelegated = ($status === 'DELEGATED');
        $isDrafter = isset($opts['is_drafter']) && (int)$opts['is_drafter'] === 1;
        $email = '';
        if ($isDrafter) { $email = isset($opts['writer_email']) ? (string)$opts['writer_email'] : ''; }
        else { $email = isset($line['approver_email']) ? (string)$line['approver_email'] : ''; }
        $sig = approval_sign_path_by_email($email);
        echo '<td><div class="approval-sign-cell">';
        if (($isApproved || $isDelegated || $isDrafter) && $sig !== '') {
            echo '<img src="'.h('../'.$sig).'" class="doc-sign">';
        } else if ($isDelegated) {
            echo '<span class="doc-time">전결</span>';
        } else {
            echo '<span class="doc-time">사인 미등록</span>';
        }
        echo '</div></td>';
    }
}
if (!function_exists('approval_render_select_cell')) {
    function approval_render_select_cell($name, $list, $selected, $placeholder)
    {
        $list = is_array($list) ? $list : array();
        echo '<td class="approval-name-cell"><select name="'.h($name).'" class="doc-select">';
        echo '<option value="">'.h($placeholder).'</option>';
        foreach ($list as $e) {
            $id = isset($e['id']) ? (int)$e['id'] : 0;
            if ($id <= 0) { continue; }
            $nm = isset($e['name']) ? $e['name'] : '';
            $sel = ((string)$selected !== '' && (int)$selected === $id) ? ' selected="selected"' : '';
            echo '<option value="'.$id.'"'.$sel.'>'.h($nm).'</option>';
        }
        echo '</select></td>';
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
            echo h(approval_line_status_label($status));
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