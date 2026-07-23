<?php
require_once __DIR__ . '/../../services/ApprovalDriveService.php';

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
    $teamRole = approval_ko('%ED%8C%80%EC%9E%A5');
    $showTeamLeaderSelect = ($mode === 'edit' && !empty($approvalOptions['requires_team_leader_select']));
    $teamLeaderCandidates = isset($approvalOptions['team_leader_candidates']) && is_array($approvalOptions['team_leader_candidates']) ? $approvalOptions['team_leader_candidates'] : array();
    $selectedTeamLeaderId = isset($approvalOptions['selected_team_leader_id']) ? (int)$approvalOptions['selected_team_leader_id'] : 0;

    $lineByRole = array();
    $orderedRoles = array();
    for ($i = 0; $i < count($lines); $i++) {
        $roleKey = isset($lines[$i]['role_type']) ? $lines[$i]['role_type'] : (isset($lines[$i]['role']) ? $lines[$i]['role'] : '');
        if ($roleKey !== '') {
            $lineByRole[$roleKey] = $lines[$i];
            $orderedRoles[] = $roleKey;
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
    if (count($orderedRoles) > 0) {
        $roleKeys = $orderedRoles;
    }
    if ($selectedTeamLeaderId <= 0 && isset($lineByRole[$teamRole]['approver_id'])) {
        $selectedTeamLeaderId = (int)$lineByRole[$teamRole]['approver_id'];
    }
    if ($showTeamLeaderSelect && !in_array($teamRole, $roleKeys, true)) {
        array_unshift($roleKeys, $teamRole);
    }

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
    $totalCols = count($roleKeys) + 1;
    if ($totalCols <= 0) {
        $totalCols = 1;
    }
    $colWidth = 100 / $totalCols;
    for ($i = 0; $i < $totalCols; $i++) {
        echo '<col style="width:' . number_format($colWidth, 2, '.', '') . '%">';
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
    for ($i = 0; $i < count($roleKeys); $i++) {
        $role = $roleKeys[$i];
        if ($role === $teamRole && $showTeamLeaderSelect) {
            approval_render_select_cell('construction_team_leader_id', $teamLeaderCandidates, $selectedTeamLeaderId, approval_ko('%ED%98%84%EC%9E%A5%20%ED%8C%80%EC%9E%A5%20%EC%84%A0%ED%83%9D'));
        } else {
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

    $lineMessages = array();
    $previewParts = array();
    for ($i = 0; $i < count($lines); $i++) {
        $lineRole = isset($lines[$i]['role_type']) ? $lines[$i]['role_type'] : (isset($lines[$i]['role']) ? $lines[$i]['role'] : '');
        $lineName = isset($lines[$i]['approver_name']) ? trim((string)$lines[$i]['approver_name']) : '';
        if ($lineName === '' && isset($lines[$i]['emp']) && is_array($lines[$i]['emp']) && isset($lines[$i]['emp']['name'])) {
            $lineName = trim((string)$lines[$i]['emp']['name']);
        }
        if ($lineName !== '') {
            $previewParts[] = approval_role_label($lineRole) . ' ' . $lineName;
        }
    }
    if (count($previewParts) > 0) {
        $lineMessages[] = approval_ko('%EC%9E%90%EB%8F%99%20%EC%83%9D%EC%84%B1%EB%90%9C%20%EA%B2%B0%EC%9E%AC%EB%9D%BC%EC%9D%B8') . ': ' . implode(' -> ', $previewParts);
    }
    if (isset($approvalOptions['line_messages']) && is_array($approvalOptions['line_messages'])) {
        $lineMessages = array_merge($lineMessages, $approvalOptions['line_messages']);
    }
    if (isset($approvalOptions['line_warnings']) && is_array($approvalOptions['line_warnings'])) {
        $lineMessages = array_merge($lineMessages, $approvalOptions['line_warnings']);
    }
    if (is_array($data)) {
        if (isset($data['approval_line_messages']) && is_array($data['approval_line_messages'])) {
            $lineMessages = array_merge($lineMessages, $data['approval_line_messages']);
        }
        if (isset($data['approval_line_warnings']) && is_array($data['approval_line_warnings'])) {
            $lineMessages = array_merge($lineMessages, $data['approval_line_warnings']);
        }
    }
    if (count($lineMessages) > 0) {
        echo '<div class="approval-reference-help" style="margin-top:8px">';
        for ($i = 0; $i < count($lineMessages); $i++) {
            echo '<div>' . h($lineMessages[$i]) . '</div>';
        }
        echo '</div>';
    }
    if ($mode === 'edit') {
        approval_render_reference_select($employees, array());
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
    echo '<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;2) ' . h(approval_ko('%EB%B0%9C%EC%A3%BC%EA%B8%88%EC%95%A1')) . ' : '; approval_doc_field($mode, 'contract_amount', approval_doc_format_amount(approval_doc_get($data, 'contract_amount', '')), 'doc-input doc-inline-input doc-money-input js-proposal-money-input', 'text', ''); echo ' ' . h(approval_ko('%EC%9B%90'));
    echo '<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;3) ' . h(approval_ko('%EC%84%A0%EA%B8%88%20%EC%A7%80%EA%B8%89%20%EC%9A%94%EC%B2%AD%EC%95%A1')) . ' : '; approval_doc_field($mode, 'advance_amount', approval_doc_format_amount(approval_doc_get($data, 'advance_amount', '')), 'doc-input doc-inline-input doc-money-input js-proposal-money-input', 'text', ''); echo ' ' . h(approval_ko('%EC%9B%90'));
    $specialNote = approval_doc_get($data, 'special_note', '');
    if ($specialNote === '') {
        $legacySpecialNotes = array();
        $legacySpecialNote1 = approval_doc_get($data, 'special_note_1', '');
        $legacySpecialNote2 = approval_doc_get($data, 'special_note_2', '');
        if ($legacySpecialNote1 !== '') {
            $legacySpecialNotes[] = $legacySpecialNote1;
        }
        if ($legacySpecialNote2 !== '') {
            $legacySpecialNotes[] = $legacySpecialNote2;
        }
        if (count($legacySpecialNotes) > 0) {
            $specialNote = implode("\n", $legacySpecialNotes);
        }
    }
    echo '<table class="proposal-special-note-table"><tr>';
    echo '<th>3. ' . h(approval_ko('%ED%8A%B9%EA%B8%B0%EC%82%AC%ED%95%AD')) . '</th>';
    echo '<td>';
    if ($mode === 'edit') {
        echo '<textarea name="special_note" class="doc-textarea proposal-special-note-textarea" rows="7">' . h($specialNote) . '</textarea>';
    } else {
        echo '<div class="proposal-special-note-value">' . h($specialNote !== '' ? $specialNote : '-') . '</div>';
    }
    echo '</td></tr></table>';
    echo '4. ' . h(approval_ko('%EC%A7%80%EA%B8%89%EC%9A%94%EC%B2%AD%EC%9D%BC')) . ' : '; approval_doc_field($mode, 'payment_request_date', approval_doc_get($data, 'payment_request_date', date('Y-m-d')), 'doc-input doc-inline-input', 'date', '');
    echo '<br>5. ' . h(approval_ko('%EC%98%88%EC%82%B0%ED%98%84%ED%99%A9')) . ' : '; approval_doc_field($mode, 'budget_status', approval_doc_format_amount_text(approval_doc_get($data, 'budget_status', '')), 'doc-input doc-inline-input js-proposal-money-text-input', 'text', '');
    echo '</div><div class="doc-attach">';
    $labels = array(
        'order_doc' => approval_ko('%EB%B0%9C%EC%A3%BC%EC%84%9C'),
        'business_license' => approval_ko('%EC%82%AC%EC%97%85%EC%9E%90%EB%93%B1%EB%A1%9D%EC%A6%9D'),
        'etc' => approval_ko('%EA%B8%B0%ED%83%80')
    );
    $attachedFileCount = 0;
    foreach ($labels as $fileKey => $fileLabel) {
        if (!isset($files[$fileKey]) || !is_array($files[$fileKey])) {
            continue;
        }
        if (isset($files[$fileKey]['original_name'])) {
            $attachedFileCount++;
        } else {
            $attachedFileCount += count($files[$fileKey]);
        }
    }
    echo '<div class="doc-attach-heading"><strong>' . h(approval_ko('%EC%B2%A8%EB%B6%80%EC%84%9C%EB%A5%98')) . '</strong>';
    if ($mode === 'edit') {
        echo '<span class="doc-attach-guide">' . h(approval_ko('%EB%93%B1%EB%A1%9D%ED%95%9C%20%ED%8C%8C%EC%9D%BC%EC%9D%80%20Google%20Drive%EC%97%90%20%EC%A0%80%EC%9E%A5%EB%90%A9%EB%8B%88%EB%8B%A4.')) . '</span>';
    } else {
        echo '<span class="doc-attach-count">' . h((string)$attachedFileCount) . h(approval_ko('%EA%B0%9C%20%EC%B2%A8%EB%B6%80')) . '</span>';
    }
    echo '</div>';
    foreach ($labels as $k => $lb) {
        $fileList = array();
        if (isset($files[$k]) && is_array($files[$k])) {
            if (isset($files[$k]['original_name'])) {
                $fileList[] = $files[$k];
            } else {
                $fileList = $files[$k];
            }
        }
        echo '<div class="attach-row' . (count($fileList) > 0 ? ' attach-row-present' : '') . '"><span class="attach-label">' . h($lb) . '</span>';
        if ($mode === 'edit') {
            echo '<div class="attach-file-picker">';
            echo '<input class="attach-file-input" type="file" name="' . h($k) . '_file[]" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.xls,.xlsx" multiple>';
            echo '<span class="attach-file-help">' . h('이미지, PDF, Excel(.xls, .xlsx) / 여러 파일 선택 가능') . '</span>';
            echo '</div>';
        } else {
            if (count($fileList) > 0) {
                echo '<div class="attach-file-list">';
                for ($fileIndex = 0; $fileIndex < count($fileList); $fileIndex++) {
                    $f = $fileList[$fileIndex];
                    if (!is_array($f)) {
                        continue;
                    }
                    $originalName = isset($f['original_name']) && is_scalar($f['original_name']) ? trim((string)$f['original_name']) : '';
                    if ($originalName === '') $originalName = approval_ko('%EC%B2%A8%EB%B6%80%ED%8C%8C%EC%9D%BC');
                    echo '<div class="attach-file-item"><span class="attach-file-name">' . h($originalName) . '</span>' . cpms_approval_drive_file_links_html($f) . '</div>';
                }
                echo '</div>';
            } else {
                echo '<span class="attach-empty">' . h(approval_ko('%EB%AF%B8%EC%B2%A8%EB%B6%80')) . '</span>';
            }
        }
        echo '</div>';
    }
    echo '</div></div>';
    if ($mode === 'edit') {
        echo '<script>(function(){';
        echo 'function formatMoney(value){var digits=String(value||"").replace(/[^0-9]/g,"");if(digits===""){return "";}digits=digits.replace(/^0+(?=[0-9])/g,"");return digits.replace(/\B(?=(\d{3})+(?!\d))/g,",");}';
        echo 'function formatMoneyText(value){return String(value||"").replace(/[0-9]+(?:,[0-9]+)*/g,function(part){return formatMoney(part);});}';
        echo 'function applyFormat(input,formatter){var before=input.value;var start=typeof input.selectionStart==="number"?input.selectionStart:before.length;var end=typeof input.selectionEnd==="number"?input.selectionEnd:start;var after=formatter(before);if(after===before){return;}var newStart=formatter(before.substring(0,start)).length;var newEnd=formatter(before.substring(0,end)).length;input.value=after;if(input.setSelectionRange){input.setSelectionRange(newStart,newEnd);}}';
        echo 'function bind(selector,formatter,useNumericKeyboard){var inputs=document.querySelectorAll(selector);for(var i=0;i<inputs.length;i++){(function(input){if(useNumericKeyboard){input.setAttribute("inputmode","numeric");}applyFormat(input,formatter);input.addEventListener("input",function(){applyFormat(input,formatter);});})(inputs[i]);}}';
        echo 'bind(".js-proposal-money-input",formatMoney,true);bind(".js-proposal-money-text-input",formatMoneyText,false);';
        echo '})();</script>';
    }
}
