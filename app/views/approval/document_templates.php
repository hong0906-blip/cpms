<?php
require_once __DIR__ . '/template_helpers.php';
require_once __DIR__ . '/_common.php';

if (!function_exists('approval_doc_get')) {
    function approval_doc_get($data, $key, $default)
    {
        if (is_array($data) && isset($data[$key]) && trim((string)$data[$key]) !== '') {
            return (string)$data[$key];
        }
        return $default;
    }
}

if (!function_exists('approval_doc_field')) {
    function approval_doc_field($mode, $name, $value, $class, $type, $placeholder)
    {
        $type = $type ? $type : 'text';
        $placeholder = $placeholder ? $placeholder : '';
        if ($mode === 'edit') {
            echo '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '" class="' . h($class) . '" placeholder="' . h($placeholder) . '">';
        } else {
            echo '<span>' . nl2br(h($value !== '' ? $value : '-')) . '</span>';
        }
    }
}

if (!function_exists('approval_render_delegated_sign_cell')) {
    function approval_render_delegated_sign_cell($label = '')
    {
        $label = trim((string)$label);
        if ($label === '') {
            $label = approval_status_label('DELEGATED');
        }
        echo '<td class="approval-delegated-cell"><div class="approval-sign-cell"><span class="approval-delegated-status">' . h($label) . '</span></div></td>';
    }
}

if (!function_exists('approval_render_sign_cell')) {
    function approval_render_sign_cell($line, $opts)
    {
        $line = is_array($line) ? $line : array();
        $opts = is_array($opts) ? $opts : array();
        if (count($line) === 0 && empty($opts['is_drafter'])) {
            echo '<td><div class="approval-sign-cell"><span class="doc-time">-</span></div></td>';
            return;
        }
        $status = isset($line['line_status']) ? strtoupper((string)$line['line_status']) : '';
        $isApproved = ($status === 'APPROVED' || $status === 'SKIPPED');
        $isDelegated = ($status === 'DELEGATED' || (isset($line['is_delegated']) && (int)$line['is_delegated'] === 1));
        $isDrafter = isset($opts['is_drafter']) && (int)$opts['is_drafter'] === 1;
        $email = '';
        if ($isDrafter) {
            $email = isset($opts['writer_email']) ? (string)$opts['writer_email'] : '';
        } else {
            $email = isset($line['approver_email']) ? (string)$line['approver_email'] : '';
        }
        $sig = approval_sign_path_by_email($email);
        if ($isDelegated) {
            approval_render_delegated_sign_cell(approval_status_label('DELEGATED'));
            return;
        }
        echo '<td><div class="approval-sign-cell">';
        if (($isApproved || $isDrafter) && $sig !== '') {
            echo '<img src="' . h('../' . $sig) . '" class="doc-sign">';
        } else if ($isApproved || $isDrafter) {
            echo '<span class="doc-time">' . h(approval_ko('%EC%84%9C%EB%AA%85%EC%99%84%EB%A3%8C')) . '</span>';
        } else {
            echo '<span class="doc-time">' . h(approval_ko('%EC%84%9C%EB%AA%85%20%EB%8C%80%EA%B8%B0')) . '</span>';
        }
        echo '</div></td>';
    }
}

if (!function_exists('approval_render_select_cell')) {
    function approval_render_select_cell($name, $list, $selected, $placeholder)
    {
        $list = is_array($list) ? $list : array();
        echo '<td class="approval-name-cell"><select name="' . h($name) . '" class="doc-select">';
        echo '<option value="">' . h($placeholder) . '</option>';
        for ($i = 0; $i < count($list); $i++) {
            $e = $list[$i];
            $id = isset($e['id']) ? (int)$e['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $nm = isset($e['name']) ? $e['name'] : '';
            $dept = isset($e['department']) ? trim((string)$e['department']) : '';
            $pos = isset($e['position']) ? trim((string)$e['position']) : '';
            $label = $nm;
            if ($dept !== '' || $pos !== '') {
                $label .= ' / ' . $dept . ' / ' . $pos;
            }
            $sel = ((string)$selected !== '' && (int)$selected === $id) ? ' selected="selected"' : '';
            echo '<option value="' . $id . '"' . $sel . '>' . h($label) . '</option>';
        }
        echo '</select></td>';
    }
}

if (!function_exists('approval_render_name_cell')) {
    function approval_render_name_cell($name)
    {
        $display = approval_display_name_only($name);
        if ($display === '') {
            $display = '-';
        }
        echo '<td class="approval-name-cell">' . h($display) . '</td>';
    }
}

if (!function_exists('approval_render_time_cell')) {
    function approval_render_time_cell($line, $opts)
    {
        $line = is_array($line) ? $line : array();
        $opts = is_array($opts) ? $opts : array();
        if (count($line) === 0 && empty($opts['is_drafter']) && empty($opts['is_delegated'])) {
            echo '<td class="approval-time-cell">-</td>';
            return;
        }
        $status = isset($line['line_status']) ? strtoupper((string)$line['line_status']) : 'WAITING';
        $isDelegated = ($status === 'DELEGATED' || (isset($line['is_delegated']) && (int)$line['is_delegated'] === 1) || !empty($opts['is_delegated']));
        $time = !empty($line['acted_at']) ? $line['acted_at'] : '';
        echo '<td class="approval-time-cell">';
        if (!empty($opts['is_drafter'])) {
            echo '-';
        } else if ($isDelegated) {
            echo '<span class="approval-delegated-status">' . h(approval_status_label('DELEGATED')) . '</span>';
            $note = approval_line_auto_note($line);
            if ($note !== '') {
                echo '<br><span class="doc-time">' . h($note) . '</span>';
            }
            if ($time !== '') {
                echo '<br><span class="doc-time">' . h($time) . '</span>';
            }
        } else if ($status === 'APPROVED') {
            echo h($time !== '' ? $time : approval_status_label('APPROVED'));
        } else if ($status === 'REJECTED') {
            echo h(approval_status_label('REJECTED'));
            if ($time !== '') {
                echo ' ' . h($time);
            }
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
        if ($n === '') {
            return '';
        }
        $n = ltrim($n, '0');
        if ($n === '') {
            return '0';
        }
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $n);
    }
}

if (!function_exists('approval_doc_format_amount_text_match')) {
    function approval_doc_format_amount_text_match($matches)
    {
        return approval_doc_format_amount(isset($matches[0]) ? $matches[0] : '');
    }
}

if (!function_exists('approval_doc_format_amount_text')) {
    function approval_doc_format_amount_text($v)
    {
        return preg_replace_callback('/[0-9]+(?:,[0-9]+)*/', 'approval_doc_format_amount_text_match', (string)$v);
    }
}

if (!function_exists('approval_render_reference_select')) {
    function approval_render_reference_select($employees, $selectedIds)
    {
        $employees = is_array($employees) ? $employees : array();
        $selectedIds = is_array($selectedIds) ? $selectedIds : array();
        echo '<div class="approval-reference-box">';
        echo '<div class="approval-reference-title">' . h(approval_ko('%EC%B0%B8%EC%A1%B0')) . '</div>';
        echo '<select name="reference_employee_ids[]" multiple="multiple" class="doc-select approval-reference-select">';
        for ($i = 0; $i < count($employees); $i++) {
            $e = $employees[$i];
            $id = isset($e['id']) ? (int)$e['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $label = (isset($e['name']) ? $e['name'] : '');
            $dept = isset($e['department']) ? trim((string)$e['department']) : '';
            $pos = isset($e['position']) ? trim((string)$e['position']) : '';
            if ($dept !== '' || $pos !== '') {
                $label .= ' / ' . $dept . ' / ' . $pos;
            }
            $sel = in_array($id, $selectedIds) ? ' selected="selected"' : '';
            echo '<option value="' . $id . '"' . $sel . '>' . h($label) . '</option>';
        }
        echo '</select>';
        echo '<div class="approval-reference-help">' . h(approval_ko('%EC%B0%B8%EC%A1%B0%EC%9E%90%EB%8A%94%20%EA%B2%B0%EC%9E%AC%ED%95%98%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EA%B2%B0%EC%9E%AC%20%EC%99%84%EB%A3%8C%20%ED%9B%84%20%EC%99%84%EB%A3%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C%EC%97%90%EC%84%9C%20%ED%99%95%EC%9D%B8%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.')) . '</div>';
        echo '</div>';
    }
}
