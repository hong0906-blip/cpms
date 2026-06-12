<?php
function render_approval_leave_document($data, $lines, $mode, $approvalOptions)
{
    $requestTypes = array(
        approval_ko('%EC%97%B0%EC%B0%A8'),
        approval_ko('%EC%9B%94%EC%B0%A8'),
        approval_ko('%EA%B2%B0%EA%B7%BC'),
        approval_ko('%EB%B0%98%EC%B0%A8%20%EC%98%A4%EC%A0%84'),
        approval_ko('%EB%B0%98%EC%B0%A8%20%EC%98%A4%ED%9B%84'),
        approval_ko('%EA%B2%BD%EC%A1%B0%ED%9C%B4%EA%B0%80'),
        approval_ko('%EA%B3%B5%EA%B0%80'),
        approval_ko('%EA%B8%B0%ED%83%80')
    );
    $rt = approval_doc_get($data, 'request_type', $requestTypes[0]);
    $start = approval_doc_get($data, 'leave_start_date', date('Y-m-d'));
    $end = approval_doc_get($data, 'leave_end_date', date('Y-m-d'));
    $days = approval_doc_get($data, 'leave_days', '1');
    $period = $start . ' ~ ' . $end . ' / ' . $days . approval_ko('%EC%9D%BC');
    $applicantEmail = approval_doc_get($data, 'applicant_email', approval_doc_get($data, 'writer_email', ''));
    $sig = approval_sign_path_by_email($applicantEmail);
    $requestDate = approval_doc_get($data, 'request_date', date('Y-m-d'));

    $lineByRole = array();
    for ($i = 0; $i < count($lines); $i++) {
        if (isset($lines[$i]['role_type'])) {
            $lineByRole[$lines[$i]['role_type']] = $lines[$i];
        }
    }

    $teamRole = approval_ko('%ED%8C%80%EC%9E%A5');
    $manageRole = approval_ko('%EA%B4%80%EB%A6%AC');
    $gongmuRole = approval_ko('%EA%B3%B5%EB%AC%B4');
    $pmRole = 'PM';
    $vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');
    $ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
    $hasPm = false;
    if (isset($approvalOptions['leave_pm']) && is_array($approvalOptions['leave_pm']) && isset($approvalOptions['leave_pm']['id'])) {
        $hasPm = true;
    }
    if (isset($lineByRole[$pmRole])) {
        $hasPm = true;
    }

    $displayRoles = array();
    if (isset($lineByRole[$manageRole])) {
        $displayRoles[] = $manageRole;
    }
    if (isset($lineByRole[$gongmuRole])) {
        $displayRoles[] = $gongmuRole;
    }
    $displayRoles[] = $teamRole;
    if ($hasPm) {
        $displayRoles[] = $pmRole;
    }
    $displayRoles[] = $vpRole;
    $displayRoles[] = $ceoRole;
    $dynamicWidth = (count($displayRoles) * 120 + 40) . 'px';
    $ratio = 100 / count($displayRoles);

    echo '<div class="approval-paper leave-paper"><div class="doc-title" style="letter-spacing:0;font-size:44px">' . h(approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84')) . '</div>';
    echo '<div class="leave-approval-wrap"><table class="approval-line-table leave-approval-line" style="width:' . $dynamicWidth . '"><colgroup><col class="approval-side-col">';
    for ($i = 0; $i < count($displayRoles); $i++) {
        echo '<col style="width:' . number_format($ratio, 2, '.', '') . '%">';
    }
    echo '</colgroup><tr><th rowspan="4">' . h(approval_ko('%EA%B2%B0%EC%9E%AC')) . '</th>';
    for ($i = 0; $i < count($displayRoles); $i++) {
        echo '<th>' . h(approval_role_label($displayRoles[$i])) . '</th>';
    }
    echo '</tr><tr class="approval-sign-row">';
    for ($i = 0; $i < count($displayRoles); $i++) {
        $role = $displayRoles[$i];
        $roleLine = isset($lineByRole[$role]) ? $lineByRole[$role] : array();
        if ($role === $ceoRole && $mode === 'edit') {
            approval_render_delegated_sign_cell(approval_status_label('DELEGATED'));
            continue;
        }
        approval_render_sign_cell($roleLine, array());
    }
    echo '</tr><tr class="approval-name-row">';
    for ($i = 0; $i < count($displayRoles); $i++) {
        $role = $displayRoles[$i];
        if ($role === $teamRole && $mode === 'edit') {
            approval_render_select_cell('team_lead_id', isset($approvalOptions['team_lead']) ? $approvalOptions['team_lead'] : array(), '', approval_ko('%ED%8C%80%EC%9E%A5%20%EC%84%A0%ED%83%9D'));
        } else if ($role === $pmRole) {
            $pmName = isset($lineByRole[$pmRole]['approver_name']) ? $lineByRole[$pmRole]['approver_name'] : '';
            if ($pmName === '' && isset($approvalOptions['leave_pm']['name'])) {
                $pmName = $approvalOptions['leave_pm']['name'];
            }
            approval_render_name_cell($pmName !== '' ? $pmName : '-');
            if ($mode === 'edit' && isset($approvalOptions['leave_pm']['id'])) {
                echo '<input type="hidden" name="leave_pm_id" value="' . (int)$approvalOptions['leave_pm']['id'] . '">';
            }
        } else if ($role === $vpRole) {
            approval_render_name_cell(isset($approvalOptions['vp']['name']) ? $approvalOptions['vp']['name'] : (isset($lineByRole[$vpRole]['approver_name']) ? $lineByRole[$vpRole]['approver_name'] : '-'));
        } else if ($role === $ceoRole) {
            $ceoName = isset($approvalOptions['ceo']['name']) ? $approvalOptions['ceo']['name'] : (isset($lineByRole[$ceoRole]['approver_name']) ? $lineByRole[$ceoRole]['approver_name'] : '-');
            $ceoDelegated = ($mode === 'edit' || approval_line_is_delegated_status(isset($lineByRole[$ceoRole]) ? $lineByRole[$ceoRole] : array()));
            approval_render_name_cell($ceoName . ($ceoDelegated ? ' (' . approval_status_label('DELEGATED') . ')' : ''));
        } else {
            approval_render_name_cell(isset($lineByRole[$role]['approver_name']) ? $lineByRole[$role]['approver_name'] : '-');
        }
    }
    echo '</tr><tr class="approval-time-row">';
    for ($i = 0; $i < count($displayRoles); $i++) {
        $role = $displayRoles[$i];
        if ($role === $ceoRole && $mode === 'edit') {
            approval_render_time_cell(isset($lineByRole[$role]) ? $lineByRole[$role] : array(), array('is_delegated' => 1));
            continue;
        }
        approval_render_time_cell(isset($lineByRole[$role]) ? $lineByRole[$role] : array(), array());
    }
    echo '</tr></table></div>';

    if ($mode === 'edit') {
        approval_render_reference_select(isset($approvalOptions['employees']) ? $approvalOptions['employees'] : array(), array());
    }

    echo '<table><tr><th style="width:78px">' . h(approval_ko('%EC%8B%A0%EC%B2%AD%EA%B5%AC%EB%B6%84')) . '</th><td colspan="3">';
    if ($mode === 'edit') {
        for ($i = 0; $i < count($requestTypes); $i++) {
            echo '<label style="margin-right:8px"><input type="radio" name="request_type" value="' . h($requestTypes[$i]) . '" ' . ($rt === $requestTypes[$i] ? 'checked="checked"' : '') . '>' . h($requestTypes[$i]) . '</label>';
        }
        echo '<input type="text" name="request_type_etc" value="' . h(approval_doc_get($data, 'request_type_etc', '')) . '" placeholder="' . h(approval_ko('%EA%B8%B0%ED%83%80')) . '" class="doc-input doc-inline-input" style="max-width:120px">';
    } else {
        echo h($rt);
        if ($rt === approval_ko('%EA%B8%B0%ED%83%80')) {
            echo ' (' . h(approval_doc_get($data, 'request_type_etc', '')) . ')';
        }
    }
    echo '</td></tr>';
    echo '<tr><th>' . h(approval_ko('%EC%86%8C%EC%86%8D')) . '</th><td>'; approval_doc_field($mode, 'department', approval_doc_get($data, 'department', ''), 'doc-input', 'text', ''); echo '</td><th>' . h(approval_ko('%EC%A7%81%EC%9C%84')) . '</th><td>'; approval_doc_field($mode, 'position', approval_doc_get($data, 'position', ''), 'doc-input', 'text', ''); echo '</td></tr>';
    echo '<tr><th>' . h(approval_ko('%EC%84%B1%EB%AA%85')) . '</th><td>'; approval_doc_field($mode, 'applicant_name', approval_doc_get($data, 'applicant_name', ''), 'doc-input', 'text', ''); echo '</td><th>' . h(approval_ko('%EC%83%9D%EB%85%84%EC%9B%94%EC%9D%BC')) . '</th><td>'; approval_doc_field($mode, 'birth_date', approval_doc_get($data, 'birth_date', ''), 'doc-input', 'date', ''); echo '</td></tr>';
    echo '<tr><th>' . h(approval_ko('%ED%9C%B4%EA%B0%80%EA%B8%B0%EA%B0%84')) . '</th><td colspan="3">';
    if ($mode === 'edit') {
        approval_doc_field($mode, 'leave_start_date', $start, 'doc-input doc-inline-input', 'date', '');
        echo ' ~ ';
        approval_doc_field($mode, 'leave_end_date', $end, 'doc-input doc-inline-input', 'date', '');
        echo ' / <input type="number" name="leave_days" value="' . h($days) . '" class="doc-input doc-inline-input doc-money-input" readonly="readonly"> ' . h(approval_ko('%EC%9D%BC'));
    } else {
        echo h($period);
    }
    echo '</td></tr>';
    echo '<tr><th>' . h(approval_ko('%ED%9C%B4%EA%B0%80%EC%82%AC%EC%9C%A0')) . '</th><td colspan="3" style="height:220px;vertical-align:top">';
    if ($mode === 'edit') {
        echo '<textarea name="leave_reason" class="doc-textarea" style="min-height:120px">' . h(approval_doc_get($data, 'leave_reason', '')) . '</textarea>';
    } else {
        echo nl2br(h(approval_doc_get($data, 'leave_reason', '-')));
    }
    echo '<div style="margin-top:55px;font-size:12px">' . h(approval_default_leave_agreement()) . '</div></td></tr></table>';
    echo '<div class="leave-request-date-big">' . h($requestDate) . '</div>';
    if ($mode === 'edit') {
        echo '<input type="hidden" name="request_date" value="' . h($requestDate) . '">';
    }
    echo '<div class="leave-applicant-line"><span class="leave-applicant-label">' . h(approval_ko('%EC%8B%A0%EC%B2%AD%EC%9D%B8')) . '</span> ';
    if ($mode === 'edit') {
        echo '<input type="text" name="applicant_sign_name" value="' . h(approval_doc_get($data, 'applicant_sign_name', approval_doc_get($data, 'applicant_name', ''))) . '" class="doc-input doc-inline-input leave-applicant-name-input">';
    } else {
        echo '<span class="leave-applicant-name">' . h(approval_doc_get($data, 'applicant_sign_name', approval_doc_get($data, 'applicant_name', ''))) . '</span>';
    }
    echo ' <span class="leave-sign-wrap"><span class="leave-sign-text">(' . h(approval_ko('%EC%9D%B8%20%EB%98%90%EB%8A%94%20%EC%84%9C%EB%AA%85')) . ')</span>';
    if ($sig !== '') {
        echo '<img src="' . h('../' . $sig) . '" class="leave-sign-overlay">';
    } else {
        echo '<span class="doc-time leave-sign-empty">' . h(approval_ko('%EC%84%9C%EB%AA%85%20%EB%AF%B8%EB%93%B1%EB%A1%9D')) . '</span>';
    }
    echo '</span></div>';
    if ($mode === 'edit') {
        echo '<script>(function(){var s=document.querySelector("input[name=leave_start_date]");var e=document.querySelector("input[name=leave_end_date]");var d=document.querySelector("input[name=leave_days]");if(!s||!e||!d){return;}function c(){if(s.value===""||e.value===""){return;}var sv=new Date(s.value+"T00:00:00");var ev=new Date(e.value+"T00:00:00");if(ev.getTime()<sv.getTime()){alert("' . h(approval_ko('%EC%A2%85%EB%A3%8C%EC%9D%BC%EC%9D%80%20%EC%8B%9C%EC%9E%91%EC%9D%BC%EB%B3%B4%EB%8B%A4%20%EB%B9%A0%EB%A5%BC%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')) . '");d.value="";return;}var days=0;var cur=new Date(sv.getTime());while(cur.getTime()<=ev.getTime()){var w=cur.getDay();if(w!==0&&w!==6){days++;}cur.setDate(cur.getDate()+1);}d.value=days;}s.onchange=c;e.onchange=c;c();})();</script>';
    }
    echo '<div style="text-align:center;font-size:40px;font-weight:700;margin-top:24px">' . h(approval_ko('%EC%A3%BC%EC%8B%9D%ED%9A%8C%EC%82%AC%20%EC%B0%BD%EB%AA%85%EA%B1%B4%EC%84%A4')) . '</div></div>';
}
