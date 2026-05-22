<?php
function render_approval_proposal_document($data, $lines, $mode, $files, $approvalOptions)
{
    $types = array(
        approval_ko('%ED%92%88%EC%9D%98'),
        approval_ko('%EC%A0%9C%EC%95%88'),
        approval_ko('%EB%B3%B4%EA%B3%A0'),
        approval_ko('%EA%B8%B0%ED%83%80')
    );
    $selectedType = approval_doc_get($data, 'draft_type', $types[0]);
    $writerEmail = approval_doc_get($data, 'writer_email', isset($approvalOptions['writer_email']) ? $approvalOptions['writer_email'] : '');
    $delegateLevel = approval_doc_get($data, 'delegate_level', 'none');
    $employees = isset($approvalOptions['employees']) ? $approvalOptions['employees'] : array();
    $pmCandidates = isset($approvalOptions['pm_candidates']) ? $approvalOptions['pm_candidates'] : array();
    $defaultPmId = isset($approvalOptions['default_pm_id']) ? (int)$approvalOptions['default_pm_id'] : 0;

    $lineByRole = array();
    for ($i = 0; $i < count($lines); $i++) {
        if (isset($lines[$i]['role_type'])) {
            $lineByRole[$lines[$i]['role_type']] = $lines[$i];
        }
    }
    $roleKeys = array(
        approval_ko('%EC%86%8C%EC%9E%A5'),
        'PM',
        approval_ko('%EA%B3%B5%EB%AC%B4'),
        approval_ko('%EA%B4%80%EB%A6%AC'),
        approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'),
        approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC')
    );

    echo '<div class="approval-paper proposal-paper">';
    echo '<div class="doc-title">' . h(approval_ko('%EA%B8%B0%EC%95%88%EC%84%9C')) . '</div>';
    echo '<table><tr><td style="width:34%;padding:0">';
    echo '<table>';
    echo '<tr><th>' . h(approval_ko('%EA%B8%B0%EC%95%88%EC%9D%BC%EC%9E%90')) . '</th><td>'; approval_doc_field($mode, 'draft_date', approval_doc_get($data, 'draft_date', date('Y-m-d')), 'doc-input', 'date', ''); echo '</td></tr>';
    echo '<tr><th>' . h(approval_ko('%EC%8B%9C%ED%96%89%EC%9D%BC%EC%9E%90')) . '</th><td>'; approval_doc_field($mode, 'effective_date', approval_doc_get($data, 'effective_date', date('Y-m-d')), 'doc-input', 'date', ''); echo '</td></tr>';
    echo '<tr><th>' . h(approval_ko('%EA%B8%B0%EC%95%88%EB%B6%80%EC%84%9C')) . '</th><td>'; approval_doc_field($mode, 'draft_department', approval_doc_get($data, 'draft_department', ''), 'doc-input', 'text', ''); echo '</td></tr>';
    echo '<tr><th>' . h(approval_ko('%EA%B8%B0%EC%95%88%EC%9E%90')) . '</th><td>'; approval_doc_field($mode, 'drafter_name', approval_doc_get($data, 'drafter_name', ''), 'doc-input', 'text', ''); echo '</td></tr>';
    echo '</table>';
    echo '</td><td style="width:66%;padding:0">';
    echo '<table class="approval-line-table proposal-approval-line"><colgroup><col class="approval-side-col">';
    for ($i = 0; $i < 7; $i++) {
        echo '<col style="width:14.28%">';
    }
    echo '</colgroup><tr><th rowspan="4">' . h(approval_ko('%EA%B2%B0%EC%9E%AC')) . '</th>';
    echo '<th>' . h(approval_ko('%EB%8B%B4%EB%8B%B9')) . '</th>';
    for ($i = 0; $i < count($roleKeys); $i++) {
        echo '<th>' . h(approval_role_label($roleKeys[$i])) . '</th>';
    }
    echo '</tr><tr class="approval-sign-row">';
    approval_render_sign_cell(array(), array('name' => approval_doc_get($data, 'drafter_name', '-'), 'is_drafter' => 1, 'writer_email' => $writerEmail));
    for ($i = 0; $i < count($roleKeys); $i++) {
        $role = $roleKeys[$i];
        approval_render_sign_cell(isset($lineByRole[$role]) ? $lineByRole[$role] : array(), array());
    }
    echo '</tr><tr class="approval-name-row">';
    approval_render_name_cell(approval_doc_get($data, 'drafter_name', '-'));
    if ($mode === 'edit') {
        approval_render_select_cell('sojang_id', isset($approvalOptions['site']) ? $approvalOptions['site'] : array(), '', approval_ko('%EC%86%8C%EC%9E%A5%20%EC%84%A0%ED%83%9D'));
        approval_render_select_cell('pm_id', $pmCandidates, $defaultPmId, 'PM');
        approval_render_select_cell('gongmu_id', isset($approvalOptions['gongmu']) ? $approvalOptions['gongmu'] : array(), '', approval_ko('%EA%B3%B5%EB%AC%B4%20%EC%84%A0%ED%83%9D'));
        approval_render_select_cell('manage_id', isset($approvalOptions['manage']) ? $approvalOptions['manage'] : array(), '', approval_ko('%EA%B4%80%EB%A6%AC%20%EC%84%A0%ED%83%9D'));
        approval_render_name_cell(isset($approvalOptions['vp']['name']) ? $approvalOptions['vp']['name'] : '-');
        approval_render_name_cell(isset($approvalOptions['ceo']['name']) ? $approvalOptions['ceo']['name'] : '-');
    } else {
        for ($i = 0; $i < count($roleKeys); $i++) {
            $role = $roleKeys[$i];
            approval_render_name_cell(isset($lineByRole[$role]['approver_name']) ? $lineByRole[$role]['approver_name'] : '-');
        }
    }
    echo '</tr><tr class="approval-time-row">';
    approval_render_time_cell(array(), array('is_drafter' => 1));
    for ($i = 0; $i < count($roleKeys); $i++) {
        $role = $roleKeys[$i];
        approval_render_time_cell(isset($lineByRole[$role]) ? $lineByRole[$role] : array(), array());
    }
    echo '</tr></table>';
    echo '</td></tr></table>';

    if ($mode === 'edit') {
        echo '<table><tr><th style="width:12%">' . h(approval_ko('%EC%A0%84%EA%B2%B0%20%EC%84%A0%ED%83%9D')) . '</th><td>';
        echo '<select name="delegate_level" class="doc-select" style="max-width:240px">';
        echo '<option value="none"' . ($delegateLevel === 'none' ? ' selected="selected"' : '') . '>' . h(approval_ko('%EC%97%86%EC%9D%8C')) . '</option>';
        echo '<option value="vp"' . ($delegateLevel === 'vp' ? ' selected="selected"' : '') . '>' . h(approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5%20%EC%A0%84%EA%B2%B0')) . '</option>';
        echo '<option value="ceo"' . ($delegateLevel === 'ceo' ? ' selected="selected"' : '') . '>' . h(approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC%20%EC%A0%84%EA%B2%B0')) . '</option>';
        echo '</select>';
        echo '</td></tr></table>';
        approval_render_reference_select($employees, array());
    } else {
        echo '<table><tr><th style="width:12%">' . h(approval_ko('%EC%A0%84%EA%B2%B0')) . '</th><td>' . h($delegateLevel === 'vp' ? approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5%20%EC%A0%84%EA%B2%B0') : ($delegateLevel === 'ceo' ? approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC%20%EC%A0%84%EA%B2%B0') : approval_ko('%EC%97%86%EC%9D%8C'))) . '</td></tr></table>';
    }

    echo '<table><tr><th style="width:12%">' . h(approval_ko('%EA%B8%B0%EC%95%88%EA%B5%AC%EB%B6%84')) . '</th><td>';
    if ($mode === 'edit') {
        for ($i = 0; $i < count($types); $i++) {
            echo '<label style="margin-right:10px"><input type="radio" name="draft_type" value="' . h($types[$i]) . '" ' . ($selectedType === $types[$i] ? 'checked="checked"' : '') . '>' . h($types[$i]) . '</label>';
        }
    } else {
        echo h($selectedType);
    }
    echo '</td></tr></table>';

    echo '<table><tr><th style="width:12%">' . h(approval_ko('%EC%A0%9C%EB%AA%A9')) . '</th><td>'; approval_doc_field($mode, 'title', approval_doc_get($data, 'title', ''), 'doc-input', 'text', ''); echo '</td></tr></table>';
    echo '<div class="doc-subline">';
    approval_doc_field($mode, 'headline', approval_doc_get($data, 'headline', ''), 'doc-input doc-inline-input', 'text', '');
    echo '<br>';
    approval_doc_field($mode, 'intro_text', approval_doc_get($data, 'intro_text', approval_ko('%EC%95%84%EB%9E%98%EC%99%80%20%EA%B0%99%EC%9D%B4%20%EA%B8%B0%EC%95%88%ED%95%98%EC%98%A4%EB%8B%88%20%EA%B2%80%ED%86%A0%ED%95%98%EC%8B%9C%EC%96%B4%20%EA%B2%B0%EC%9E%AC%ED%95%98%EC%97%AC%20%EC%A3%BC%EC%8B%9C%EA%B8%B0%20%EB%B0%94%EB%9E%8D%EB%8B%88%EB%8B%A4.')), 'doc-input', 'text', '');
    echo '<div style="text-align:center;margin:16px 0">- ' . h(approval_ko('%EB%8B%A4%20%EC%9D%8C')) . ' -</div>';
    echo '1. ' . h(approval_ko('%EC%82%AC%EC%9C%A0')) . ' : '; approval_doc_field($mode, 'reason', approval_doc_get($data, 'reason', ''), 'doc-input doc-inline-input', 'text', '');
    echo '<br>2. ' . h(approval_ko('%EB%82%B4%EC%9A%A9')) . ' : 1) ' . h(approval_ko('%EC%97%85%EC%B2%B4%EB%AA%85')) . ' : '; approval_doc_field($mode, 'company_name', approval_doc_get($data, 'company_name', ''), 'doc-input doc-inline-input', 'text', '');
    echo '<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;2) ' . h(approval_ko('%EB%B0%9C%EC%A3%BC%EA%B8%88%EC%95%A1')) . ' : '; approval_doc_field($mode, 'contract_amount', approval_doc_format_amount(approval_doc_get($data, 'contract_amount', '')), 'doc-input doc-inline-input doc-money-input', 'number', ''); echo ' ' . h(approval_ko('%EC%9B%90'));
    echo '<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;3) ' . h(approval_ko('%EC%84%A0%EA%B8%88%20%EC%A7%80%EA%B8%89%20%EC%9A%94%EC%B2%AD%EC%95%A1')) . ' : '; approval_doc_field($mode, 'advance_amount', approval_doc_format_amount(approval_doc_get($data, 'advance_amount', '')), 'doc-input doc-inline-input doc-money-input', 'number', ''); echo ' ' . h(approval_ko('%EC%9B%90'));
    echo '<br>3. ' . h(approval_ko('%ED%8A%B9%EA%B8%B0%EC%82%AC%ED%95%AD%201')) . ' : '; approval_doc_field($mode, 'special_note_1', approval_doc_get($data, 'special_note_1', ''), 'doc-input doc-inline-input', 'text', '');
    echo '<br>&nbsp;&nbsp;&nbsp;' . h(approval_ko('%ED%8A%B9%EA%B8%B0%EC%82%AC%ED%95%AD%202')) . ' : '; approval_doc_field($mode, 'special_note_2', approval_doc_get($data, 'special_note_2', ''), 'doc-input doc-inline-input', 'text', '');
    echo '<br>4. ' . h(approval_ko('%EC%A7%80%EA%B8%89%EC%9A%94%EC%B2%AD%EC%9D%BC')) . ' : '; approval_doc_field($mode, 'payment_request_date', approval_doc_get($data, 'payment_request_date', date('Y-m-d')), 'doc-input doc-inline-input', 'date', '');
    echo '<br>5. ' . h(approval_ko('%EC%98%88%EC%82%B0%ED%98%84%ED%99%A9')) . ' : '; approval_doc_field($mode, 'budget_status', approval_doc_get($data, 'budget_status', ''), 'doc-input doc-inline-input', 'text', '');
    echo '</div><div class="doc-attach">' . h(approval_ko('%EC%B2%A8%EB%B6%80%EC%84%9C%EB%A5%98'));
    $labels = array(
        'order_doc' => approval_ko('%EB%B0%9C%EC%A3%BC%EC%84%9C'),
        'business_license' => approval_ko('%EC%82%AC%EC%97%85%EC%9E%90%EB%93%B1%EB%A1%9D%EC%A6%9D'),
        'etc' => approval_ko('%EA%B8%B0%ED%83%80')
    );
    foreach ($labels as $k => $lb) {
        echo '<div class="attach-row">' . h($lb);
        if ($mode === 'edit') {
            echo ' <input type="file" name="' . h($k) . '_file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">';
        } else {
            $f = isset($files[$k]) ? $files[$k] : null;
            if ($f) {
                echo ' : ' . h($f['original_name']) . ' <a href="../' . h($f['file_path']) . '" target="_blank">' . h(approval_ko('%EB%B3%B4%EA%B8%B0')) . '</a> <a href="../' . h($f['file_path']) . '" download>' . h(approval_ko('%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C')) . '</a>';
            } else {
                echo ' : -';
            }
        }
        echo '</div>';
    }
    echo '</div></div>';
}
