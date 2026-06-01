<?php
require_once __DIR__ . '/template_helpers.php';
require_once __DIR__ . '/document_templates.php';

if (!function_exists('approval_unused_leave_date_korean')) {
    function approval_unused_leave_date_korean($value)
    {
        $ts = strtotime((string)$value);
        if (!$ts) {
            return (string)$value;
        }
        return date('Y', $ts) . approval_ko('%EB%85%84') . ' ' . ((int)date('n', $ts)) . approval_ko('%EC%9B%94') . ' ' . ((int)date('j', $ts)) . approval_ko('%EC%9D%BC');
    }
}

if (!function_exists('approval_unused_leave_line_by_order')) {
    function approval_unused_leave_line_by_order($lines, $order)
    {
        if (!is_array($lines)) {
            return array();
        }
        for ($i = 0; $i < count($lines); $i++) {
            if (isset($lines[$i]['line_order']) && (int)$lines[$i]['line_order'] === (int)$order) {
                return $lines[$i];
            }
        }
        return array();
    }
}

if (!function_exists('approval_unused_leave_employee_options')) {
    function approval_unused_leave_employee_options($employees)
    {
        $list = array();
        if (!is_array($employees)) {
            return $list;
        }
        for ($i = 0; $i < count($employees); $i++) {
            if (!isset($employees[$i]['id']) || (int)$employees[$i]['id'] <= 0) {
                continue;
            }
            $one = $employees[$i];
            $list[] = array(
                'id' => (int)$one['id'],
                'name' => isset($one['name']) ? (string)$one['name'] : '',
                'department' => isset($one['department']) ? (string)$one['department'] : '',
                'position' => isset($one['position']) ? (string)$one['position'] : '',
                'hire_date' => isset($one['hire_date']) ? (string)$one['hire_date'] : ''
            );
        }
        return $list;
    }
}

if (!function_exists('approval_unused_leave_render_sender_only_line')) {
    function approval_unused_leave_render_sender_only_line($mode, $data, $lines)
    {
        $senderLine = approval_unused_leave_line_by_order($lines, 1);
        $senderName = approval_doc_get($data, 'sender_name', '');
        $writerEmail = approval_doc_get($data, 'writer_email', '');
        $sentAt = approval_doc_get($data, 'sent_at', date('Y-m-d H:i:s'));

        echo '<div class="leave-approval-wrap" style="margin-bottom:20px">';
        echo '<table class="approval-line-table leave-approval-line" style="width:280px">';
        echo '<colgroup><col class="approval-side-col"><col style="width:100%"></colgroup>';
        echo '<tr><th rowspan="4">' . h(approval_ko('%EA%B2%B0%EC%9E%AC')) . '</th><th>' . h(approval_ko('%EB%B3%B4%EB%82%B8%EC%82%AC%EB%9E%8C')) . '</th></tr>';
        echo '<tr class="approval-sign-row">';
        if ($mode === 'edit') {
            approval_render_sign_cell(array(), array('is_drafter' => 1, 'writer_email' => $writerEmail));
        } else {
            approval_render_sign_cell($senderLine, array('writer_email' => $writerEmail));
        }
        echo '</tr>';
        echo '<tr class="approval-name-row">';
        approval_render_name_cell($senderName);
        echo '</tr>';
        echo '<tr class="approval-time-row">';
        if ($mode === 'edit') {
            echo '<td class="approval-time-cell">' . h($sentAt) . '</td>';
        } else {
            approval_render_time_cell($senderLine, array());
        }
        echo '</tr>';
        echo '</table>';
        echo '</div>';
    }
}

if (!function_exists('approval_unused_leave_render_notice_line')) {
    function approval_unused_leave_render_notice_line($mode, $data, $lines)
    {
        $senderLine = approval_unused_leave_line_by_order($lines, 1);
        $receiverLine = approval_unused_leave_line_by_order($lines, 2);
        $senderName = approval_doc_get($data, 'sender_name', '');
        $receiverName = approval_doc_get($data, 'target_name', '');
        $writerEmail = approval_doc_get($data, 'writer_email', '');
        $sentAt = approval_doc_get($data, 'sent_at', date('Y-m-d H:i:s'));

        echo '<div class="leave-approval-wrap" style="margin-bottom:20px">';
        echo '<table class="approval-line-table leave-approval-line" style="width:410px">';
        echo '<colgroup><col class="approval-side-col"><col style="width:50%"><col style="width:50%"></colgroup>';
        echo '<tr><th rowspan="4">' . h(approval_ko('%EA%B2%B0%EC%9E%AC')) . '</th><th>' . h(approval_ko('%EB%B3%B4%EB%82%B8%EC%82%AC%EB%9E%8C')) . '</th><th>' . h(approval_ko('%EB%B0%9B%EB%8A%94%EC%82%AC%EB%9E%8C')) . '</th></tr>';
        echo '<tr class="approval-sign-row">';
        if ($mode === 'edit') {
            approval_render_sign_cell(array(), array('is_drafter' => 1, 'writer_email' => $writerEmail));
            echo '<td><div class="approval-sign-cell"><span class="doc-time">' . h(approval_ko('%EC%84%9C%EB%AA%85%20%EB%8C%80%EA%B8%B0')) . '</span></div></td>';
        } else {
            approval_render_sign_cell($senderLine, array('writer_email' => $writerEmail));
            approval_render_sign_cell($receiverLine, array());
        }
        echo '</tr>';
        echo '<tr class="approval-name-row">';
        approval_render_name_cell($senderName);
        approval_render_name_cell($receiverName);
        echo '</tr>';
        echo '<tr class="approval-time-row">';
        if ($mode === 'edit') {
            echo '<td class="approval-time-cell">' . h($sentAt) . '</td>';
            echo '<td class="approval-time-cell">' . h(approval_ko('%EB%8C%80%EA%B8%B0')) . '</td>';
        } else {
            approval_render_time_cell($senderLine, array());
            approval_render_time_cell($receiverLine, array());
        }
        echo '</tr>';
        echo '</table>';
        echo '</div>';
    }
}

if (!function_exists('approval_unused_leave_render_target_select')) {
    function approval_unused_leave_render_target_select($data, $approvalOptions)
    {
        $employees = isset($approvalOptions['employees']) ? approval_unused_leave_employee_options($approvalOptions['employees']) : array();
        $selectedId = (int)approval_doc_get($data, 'target_employee_id', '0');
        echo '<select name="target_employee_id" class="doc-select" id="unusedLeaveTargetSelect">';
        echo '<option value="">' . h(approval_ko('%EB%8C%80%EC%83%81%EC%9E%90%20%EC%84%A0%ED%83%9D')) . '</option>';
        for ($i = 0; $i < count($employees); $i++) {
            $one = $employees[$i];
            $label = $one['name'];
            if ($one['department'] !== '' || $one['position'] !== '') {
                $label .= ' / ' . $one['department'] . ' / ' . $one['position'];
            }
            $selected = ($selectedId > 0 && $selectedId === (int)$one['id']) ? ' selected="selected"' : '';
            echo '<option value="' . (int)$one['id'] . '" data-name="' . h($one['name']) . '" data-department="' . h($one['department']) . '" data-position="' . h($one['position']) . '" data-hire-date="' . h($one['hire_date']) . '"' . $selected . '>' . h($label) . '</option>';
        }
        echo '</select>';
    }
}

if (!function_exists('approval_unused_leave_render_identity_table')) {
    function approval_unused_leave_render_identity_table($mode, $data, $approvalOptions)
    {
        echo '<table style="margin-top:18px">';
        echo '<colgroup><col style="width:18%"><col style="width:32%"><col style="width:18%"><col style="width:32%"></colgroup>';
        echo '<tr>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EC%84%B1%EB%AA%85')) . '</th>';
        echo '<td>';
        if ($mode === 'edit') {
            approval_unused_leave_render_target_select($data, $approvalOptions);
            echo '<input type="hidden" name="target_name" value="' . h(approval_doc_get($data, 'target_name', '')) . '" id="unusedLeaveTargetName">';
        } else {
            echo h(approval_doc_get($data, 'target_name', '-'));
        }
        echo '</td>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EB%B6%80%EC%84%9C%EB%AA%85')) . '</th>';
        echo '<td>';
        if ($mode === 'edit') {
            echo '<span id="unusedLeaveTargetDepartmentText">' . h(approval_doc_get($data, 'target_department', '')) . '</span>';
            echo '<input type="hidden" name="target_department" value="' . h(approval_doc_get($data, 'target_department', '')) . '" id="unusedLeaveTargetDepartment">';
        } else {
            echo h(approval_doc_get($data, 'target_department', '-'));
        }
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EC%A7%81%EC%9C%84')) . '</th>';
        echo '<td>';
        if ($mode === 'edit') {
            echo '<span id="unusedLeaveTargetPositionText">' . h(approval_doc_get($data, 'target_position', '')) . '</span>';
            echo '<input type="hidden" name="target_position" value="' . h(approval_doc_get($data, 'target_position', '')) . '" id="unusedLeaveTargetPosition">';
        } else {
            echo h(approval_doc_get($data, 'target_position', '-'));
        }
        echo '</td>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EC%9E%85%EC%82%AC%EC%9D%BC')) . '</th>';
        echo '<td>';
        if ($mode === 'edit') {
            echo '<span id="unusedLeaveTargetHireDateText">' . h(approval_doc_get($data, 'target_hire_date', '')) . '</span>';
            echo '<input type="hidden" name="target_hire_date" value="' . h(approval_doc_get($data, 'target_hire_date', '')) . '" id="unusedLeaveTargetHireDate">';
        } else {
            echo h(approval_doc_get($data, 'target_hire_date', '-'));
        }
        echo '</td>';
        echo '</tr>';
        echo '</table>';
    }
}

if (!function_exists('approval_unused_leave_render_notice_footer')) {
    function approval_unused_leave_render_notice_footer($mode, $data)
    {
        $sentAt = approval_doc_get($data, 'sent_at', date('Y-m-d H:i:s'));
        echo '<div class="leave-request-date-big" style="margin-top:84px">' . h(approval_unused_leave_date_korean($sentAt)) . '</div>';
        echo '<div style="text-align:center;font-size:34px;font-weight:700;margin-top:90px">' . h(approval_ko('%28%EC%A3%BC%29%EC%B0%BD%EB%AA%85%EA%B1%B4%EC%84%A4')) . '</div>';
        echo '<div style="text-align:center;font-size:28px;font-weight:700;margin-top:10px">' . h(approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC%20%EB%85%B8%EC%9A%B1%ED%98%95')) . '</div>';
        if ($mode === 'edit') {
            echo '<input type="hidden" name="sent_at" value="' . h($sentAt) . '">';
            echo '<input type="hidden" name="sender_name" value="' . h(approval_doc_get($data, 'sender_name', '')) . '">';
        }
    }
}

if (!function_exists('approval_unused_leave_render_plan_rows')) {
    function approval_unused_leave_render_plan_rows($mode, $data)
    {
        for ($i = 1; $i <= 3; $i++) {
            $periodKey = 'plan_period_' . $i;
            $daysKey = 'plan_days_' . $i;
            echo '<tr>';
            echo '<td>' . ($i === 1 ? h(approval_ko('%EC%97%B0%EC%B0%A8')) : '&nbsp;') . '</td>';
            echo '<td>';
            if ($mode === 'approve_edit') {
                echo '<input type="text" name="' . h($periodKey) . '" value="' . h(approval_doc_get($data, $periodKey, '')) . '" class="doc-input" placeholder="2026-06-01 ~ 2026-06-03">';
            } else {
                echo h(approval_doc_get($data, $periodKey, ''));
            }
            echo '</td>';
            echo '<td>';
            if ($mode === 'approve_edit') {
                echo '<input type="number" step="0.5" min="0" name="' . h($daysKey) . '" value="' . h(approval_doc_get($data, $daysKey, '')) . '" class="doc-input">';
            } else {
                echo h(approval_doc_get($data, $daysKey, ''));
            }
            echo '</td>';
            echo '<td></td>';
            echo '</tr>';
        }
    }
}

if (!function_exists('approval_unused_leave_render_autofill_script')) {
    function approval_unused_leave_render_autofill_script()
    {
        echo '<script>(function(){var select=document.getElementById("unusedLeaveTargetSelect");if(!select){return;}var nameInput=document.getElementById("unusedLeaveTargetName");var deptInput=document.getElementById("unusedLeaveTargetDepartment");var posInput=document.getElementById("unusedLeaveTargetPosition");var hireInput=document.getElementById("unusedLeaveTargetHireDate");var deptText=document.getElementById("unusedLeaveTargetDepartmentText");var posText=document.getElementById("unusedLeaveTargetPositionText");var hireText=document.getElementById("unusedLeaveTargetHireDateText");function sync(){var opt=select.options[select.selectedIndex];var name="";var dept="";var pos="";var hire="";if(opt&&opt.value!==""){name=opt.getAttribute("data-name")||"";dept=opt.getAttribute("data-department")||"";pos=opt.getAttribute("data-position")||"";hire=opt.getAttribute("data-hire-date")||"";}if(nameInput){nameInput.value=name;}if(deptInput){deptInput.value=dept;}if(posInput){posInput.value=pos;}if(hireInput){hireInput.value=hire;}if(deptText){deptText.innerHTML=dept;}if(posText){posText.innerHTML=pos;}if(hireText){hireText.innerHTML=hire;}}if(select.addEventListener){select.addEventListener("change",sync,false);}else if(select.attachEvent){select.attachEvent("onchange",sync);}sync();})();</script>';
    }
}

if (!function_exists('approval_unused_leave_notice_body')) {
    function approval_unused_leave_notice_body($data)
    {
        $used = approval_doc_get($data, 'used_leave_days', '0');
        $unused = approval_doc_get($data, 'unused_leave_days', '0');
        $grant = approval_doc_get($data, 'annual_granted_days', '0');

        $line1 = approval_ko('%EC%83%81%EA%B8%B0%EC%9D%B8%EC%9D%80%20%ED%98%84%EC%9E%AC%20') . $grant . approval_ko('%EC%9D%BC%EC%9D%98%20%EC%97%B0%EC%B0%A8%20%EC%A4%91%20%5B%20') . $used . approval_ko('%20%5D%EC%9D%BC%EC%9D%98%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EC%82%AC%EC%9A%A9%ED%95%98%EC%97%AC%2C%20%EC%82%AC%EC%9A%A9%EA%B8%B0%EA%B0%84%EA%B9%8C%EC%A7%80%20%5B%20') . $unused . approval_ko('%20%5D%EC%9D%BC%EC%9D%98%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EC%B6%94%EA%B0%80%20%EC%82%AC%EC%9A%A9%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        $line2 = approval_ko('%EC%83%81%EA%B8%B0%EC%9D%B8%EC%9D%80%2010%EC%9D%BC%20%EC%9D%B4%EB%82%B4%EC%97%90%20%ED%96%A5%ED%9B%84%206%EA%B0%9C%EC%9B%94%20%EA%B0%84%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%20%EC%8B%9C%EA%B8%B0%EB%A5%BC%20%EC%A0%95%ED%95%98%EC%97%AC%20%ED%9A%8C%EC%82%AC%EB%A1%9C%20%ED%86%B5%EB%B3%B4%ED%95%B4%EC%A3%BC%EC%8B%9C%EA%B8%B0%20%EB%B0%94%EB%9E%8D%EB%8B%88%EB%8B%A4.');
        $line3 = approval_ko('%EB%A7%8C%EC%95%BD%2C%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%20%EC%82%AC%EC%9A%A9%20%EC%8B%9C%EA%B8%B0%EB%A5%BC%20%ED%86%B5%EB%B3%B4%ED%95%98%EC%A7%80%20%EC%95%8A%EB%8A%94%EB%8B%A4%EB%A9%B4%2C%20%ED%9A%8C%EC%82%AC%EB%8A%94%20%EA%B7%BC%EB%A1%9C%EA%B8%B0%EC%A4%80%EB%B2%95%EC%97%90%20%EA%B7%BC%EA%B1%B0%ED%95%98%EC%97%AC%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%20%EC%82%AC%EC%9A%A9%EA%B8%B0%EA%B0%84%20%EB%A7%88%EC%A7%80%EB%A7%89%202%EA%B0%9C%EC%9B%94%20%EC%82%AC%EC%9D%B4%EC%9D%98%20%EC%9D%BC%EC%9E%90%EB%A5%BC%20%EC%9E%84%EC%9D%98%EB%A1%9C%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%20%EC%82%AC%EC%9A%A9%EC%9D%BC%EB%A1%9C%20%EC%A7%80%EC%A0%95%ED%95%98%EC%97%AC%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B8%B0%EA%B0%84%20%EC%A2%85%EB%A3%8C%EC%9D%BC%202%EA%B0%9C%EC%9B%94%20%EC%A0%84%EA%B9%8C%EC%A7%80%20%ED%86%B5%EB%B3%B4%ED%95%98%EB%8F%84%EB%A1%9D%20%ED%95%98%EA%B2%A0%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        $line4 = approval_ko('%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EC%9D%BC%EC%9D%84%20%EC%A7%80%EC%A0%95%ED%95%98%EC%A7%80%20%EC%95%8A%EA%B3%A0%2C%20%ED%9A%8C%EC%82%AC%EA%B0%80%20%EC%A7%80%EC%A0%95%ED%95%9C%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EC%9D%BC%EC%97%90%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EC%82%AC%EC%9A%A9%ED%95%98%EC%A7%80%20%EC%95%8A%EB%8A%94%20%EA%B2%BD%EC%9A%B0%2C');
        $line5 = approval_ko('%EA%B7%BC%EB%A1%9C%EA%B8%B0%EC%A4%80%EB%B2%95%EC%97%90%20%EB%94%B0%EB%9D%BC%20%ED%95%B4%EB%8B%B9%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EB%8A%94%20%EC%86%8C%EB%A9%B8%ED%95%98%EB%A9%B0%2C%20%EC%88%98%EB%8B%B9%EB%8F%84%20%EC%A7%80%EA%B8%89%EB%90%98%EC%A7%80%20%EC%95%8A%EC%9D%8C%EC%97%90%20%EC%9C%A0%EC%9D%98%ED%95%98%EC%8B%9C%EA%B8%B0%20%EB%B0%94%EB%9E%8D%EB%8B%88%EB%8B%A4.');
        $line6 = approval_ko('%EC%9C%84%EC%99%80%20%EA%B0%99%EC%9D%B4%20%EC%97%B0%EC%B0%A8%EC%82%AC%EC%9A%A9%EC%B4%89%EC%A7%84%EC%A0%9C%EB%8F%84%EC%97%90%20%EC%9D%98%EA%B1%B0%ED%95%98%EC%97%AC%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%20%EC%82%AC%EC%9A%A9%EC%9D%84%20%EC%B4%89%EA%B5%AC%ED%95%A9%EB%8B%88%EB%8B%A4.');

        return array($line1, $line2, $line3, $line4, $line5, $line6);
    }
}

if (!function_exists('render_approval_unused_leave_notice_document')) {
    function render_approval_unused_leave_notice_document($data, $lines, $mode, $approvalOptions)
    {
        $bodyLines = approval_unused_leave_notice_body($data);

        echo '<div class="approval-paper leave-paper" style="padding:30px 32px 34px 32px">';
        echo '<div class="doc-title" style="letter-spacing:0;font-size:34px;border-bottom:0;margin-bottom:18px;padding-top:24px;padding-bottom:18px">' . h(approval_ko('%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EC%B4%89%EA%B5%AC%EC%84%9C')) . '</div>';
        approval_unused_leave_render_notice_line($mode, $data, $lines);
        approval_unused_leave_render_identity_table($mode, $data, $approvalOptions);

        echo '<table style="margin-top:26px">';
        echo '<colgroup><col style="width:28%"><col style="width:44%"><col style="width:16%"><col style="width:12%"></colgroup>';
        echo '<tr><th style="background:#f1f1f1">' . h(approval_ko('%EC%97%B0%EC%B0%A8%20%EB%B0%9C%EC%83%9D%EC%8B%9C%EC%A0%90')) . '</th><td colspan="3">' . h(approval_doc_get($data, 'annual_occurrence_label', '-')) . '</td></tr>';
        echo '<tr><th style="background:#f1f1f1">' . h(approval_ko('%EC%97%B0%EC%B0%A8%20%EB%B0%9C%EC%83%9D%EC%9D%BC%EC%88%98')) . '<br>(' . h(approval_ko('%EC%B6%94%EA%B0%80%2F%EA%B3%B5%EC%A0%9C%EC%9D%BC%EC%88%98%20%ED%8F%AC%ED%95%A8')) . ')</th><td colspan="3">' . h(approval_doc_get($data, 'annual_granted_days', '-')) . '</td></tr>';
        echo '<tr><th style="background:#f1f1f1">' . h(approval_ko('%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B8%B0%EA%B0%84')) . '</th><td colspan="3">' . h(approval_doc_get($data, 'annual_usable_period', '-')) . '</td></tr>';
        echo '<tr><th style="background:#f1f1f1">' . h(approval_ko('%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%EC%9D%BC%EC%88%98')) . '</th><td>' . h(approval_doc_get($data, 'used_leave_days', '-')) . '</td><th style="background:#f1f1f1">' . h(approval_ko('%EC%9E%94%EC%97%AC%20%EC%97%B0%EC%B0%A8%EC%9D%BC%EC%88%98')) . '</th><td>' . h(approval_doc_get($data, 'unused_leave_days', '-')) . '</td></tr>';
        echo '</table>';

        echo '<div style="margin-top:42px;font-size:17px;line-height:2.35;text-align:center;font-weight:700">';
        echo h($bodyLines[0]);
        echo '<br>' . h($bodyLines[1]);
        echo '</div>';

        echo '<div style="margin-top:72px;font-size:16px;line-height:2.6;text-align:center">';
        echo h($bodyLines[2]);
        echo '<br><br>';
        echo h($bodyLines[3]);
        echo '<br>';
        echo h($bodyLines[4]);
        echo '<br><br>';
        echo h($bodyLines[5]);
        echo '</div>';

        approval_unused_leave_render_notice_footer($mode, $data);
        if ($mode === 'edit') {
            approval_unused_leave_render_autofill_script();
        }
        echo '</div>';
    }
}

if (!function_exists('render_approval_unused_leave_plan_document')) {
    function render_approval_unused_leave_plan_document($data, $lines, $mode, $approvalOptions)
    {
        $editableRecipient = ($mode === 'approve_edit');
        $receiverLine = approval_unused_leave_line_by_order($lines, 2);
        $receiverSignedName = approval_doc_get($data, 'receiver_signed_name', approval_doc_get($data, 'target_name', ''));
        $receiverSignPath = '';
        if (isset($receiverLine['approver_email'])) {
            $receiverSignPath = approval_sign_path_by_email($receiverLine['approver_email']);
        }

        echo '<div class="approval-paper leave-paper" style="padding:30px 32px 34px 32px">';
        echo '<div class="doc-title" style="letter-spacing:0;font-size:34px;border-bottom:0;margin-bottom:18px;padding-top:24px;padding-bottom:18px">' . h(approval_ko('%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B3%84%ED%9A%8D%EC%84%9C')) . '</div>';
        approval_unused_leave_render_sender_only_line($mode, $data, $lines);
        approval_unused_leave_render_identity_table($mode === 'edit' ? 'edit' : 'view', $data, $approvalOptions);

        echo '<table style="margin-top:24px">';
        echo '<tr><td colspan="6" style="padding:28px 16px;text-align:center;border-left:0;border-right:0;font-size:17px;line-height:2.2">';
        echo '[ ';
        if ($mode === 'edit') {
            echo '<input type="date" name="plan_notice_date" value="' . h(approval_doc_get($data, 'plan_notice_date', '')) . '" class="doc-input doc-inline-input" style="min-width:180px">';
        } else {
            echo h(approval_doc_get($data, 'plan_notice_date', ''));
        }
        echo ' ]' . h(approval_ko('%EC%9D%BC%EC%97%90%20%ED%86%B5%EC%A7%80%EB%B0%9B%EC%9D%80%20%E2%80%98%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%EC%9D%BC%EC%88%98%E2%80%99%20%EB%B0%8F%20%E2%80%98%ED%9C%B4%EA%B0%80%EC%82%AC%EC%9A%A9%20%EC%8B%9C%EA%B8%B0%EC%A7%80%EC%A0%95%20%ED%86%B5%EB%B3%B4%E2%80%99%EC%97%90%20%EC%9D%98%EA%B1%B0%ED%95%98%EC%97%AC%20%EC%95%84%EB%9E%98%EC%99%80%20%EA%B0%99%EC%9D%B4')) . '<br>' . h(approval_ko('%EB%B3%B8%EC%9D%B8%EC%9D%98%20%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B3%84%ED%9A%8D%EC%84%9C%EB%A5%BC%20%EC%A0%9C%EC%B6%9C%ED%95%A9%EB%8B%88%EB%8B%A4.'));
        echo '</td></tr>';
        echo '<tr><td colspan="6" style="padding:20px 12px;text-align:center;border-left:0;border-right:0;font-size:22px;font-weight:700">- ' . h(approval_ko('%EC%95%84%20%20%EB%9E%98')) . ' -</td></tr>';
        echo '</table>';

        echo '<table style="margin-top:16px">';
        echo '<colgroup><col style="width:20%"><col style="width:30%"><col style="width:14%"><col style="width:14%"><col style="width:14%"></colgroup>';
        echo '<tr>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EC%97%B0%EC%B0%A8%20%EB%B0%9C%EC%83%9D%EC%9D%BC%EC%9E%90')) . '</th>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B8%B0%EA%B0%84')) . '</th>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EB%B0%9C%EC%83%9D%EC%97%B0%EC%B0%A8')) . '<br>(A)</th>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EC%82%AC%EC%9A%A9%EC%97%B0%EC%B0%A8')) . '<br>(B)</th>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EB%AF%B8%EC%82%AC%EC%9A%A9')) . '<br>(A - B)</th>';
        echo '</tr>';
        echo '<tr style="height:64px">';
        echo '<td>' . h(approval_doc_get($data, 'annual_grant_date', '-')) . '</td>';
        echo '<td>' . h(approval_doc_get($data, 'annual_usable_period', '-')) . '</td>';
        echo '<td>' . h(approval_doc_get($data, 'annual_granted_days', '-')) . '</td>';
        echo '<td>' . h(approval_doc_get($data, 'used_leave_days', '-')) . '</td>';
        echo '<td>' . h(approval_doc_get($data, 'unused_leave_days', '-')) . '</td>';
        echo '</tr>';
        echo '<tr style="height:64px"><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>';
        echo '<tr style="height:64px"><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>';
        echo '</table>';

        echo '<table style="margin-top:26px">';
        echo '<colgroup><col style="width:18%"><col style="width:50%"><col style="width:16%"><col style="width:16%"></colgroup>';
        echo '<tr>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EA%B5%AC%EB%B6%84')) . '</th>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EA%B8%B0%EA%B0%84')) . '</th>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EC%9D%BC%EC%88%98')) . '</th>';
        echo '<th style="background:#f1f1f1">' . h(approval_ko('%EB%B9%84%EA%B3%A0')) . '</th>';
        echo '</tr>';
        approval_unused_leave_render_plan_rows($mode, $data);
        echo '<tr>';
        echo '<td style="font-weight:700">' . h(approval_ko('%ED%95%A9%EA%B3%84')) . '</td>';
        echo '<td></td>';
        echo '<td>';
        if ($editableRecipient) {
            echo '<input type="number" step="0.5" min="0" name="plan_total_days" value="' . h(approval_doc_get($data, 'plan_total_days', '')) . '" class="doc-input">';
        } else {
            echo h(approval_doc_get($data, 'plan_total_days', ''));
        }
        echo '</td>';
        echo '<td></td>';
        echo '</tr>';
        echo '</table>';

        echo '<div class="leave-request-date-big" style="margin-top:64px">' . h(approval_unused_leave_date_korean(approval_doc_get($data, 'sent_at', date('Y-m-d H:i:s')))) . '</div>';

        echo '<div style="display:flex;justify-content:flex-end;align-items:flex-end;gap:12px;font-size:22px;font-weight:700;margin-top:40px">';
        echo '<span>' . h(approval_ko('%EC%83%81%EA%B8%B0%20%EB%B3%B8%EC%9D%B8')) . ' :</span>';
        echo '<span class="leave-applicant-name" style="min-width:110px">' . h($receiverSignedName) . '</span>';
        echo '<span class="leave-sign-wrap"><span class="leave-sign-text">(' . h(approval_ko('%EC%9D%B8')) . ')</span>';
        if ($receiverSignPath !== '' && !$editableRecipient && $mode !== 'edit') {
            echo '<img src="' . h('../' . $receiverSignPath) . '" class="leave-sign-overlay">';
        } else {
            echo '<span class="doc-time leave-sign-empty">' . h(approval_ko('%EC%84%9C%EB%AA%85%20%EB%8C%80%EA%B8%B0')) . '</span>';
        }
        echo '</span>';
        echo '</div>';

        echo '<div style="text-align:center;font-size:34px;font-weight:700;margin-top:110px">' . h(approval_ko('%28%EC%A3%BC%29%EC%B0%BD%EB%AA%85%EA%B1%B4%EC%84%A4%20%EA%B7%80%EC%A4%91')) . '</div>';

        if ($mode === 'edit') {
            echo '<input type="hidden" name="sent_at" value="' . h(approval_doc_get($data, 'sent_at', date('Y-m-d H:i:s'))) . '">';
            echo '<input type="hidden" name="sender_name" value="' . h(approval_doc_get($data, 'sender_name', '')) . '">';
            approval_unused_leave_render_autofill_script();
        }

        echo '</div>';
    }
}
