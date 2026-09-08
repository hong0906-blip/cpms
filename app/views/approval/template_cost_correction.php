<?php
/**
 * 파일경로: app/views/approval/template_cost_correction.php
 * 비용 수정/누락 전용 전자결재 문서 렌더러 (PHP 5.6)
 */
require_once __DIR__ . '/../../services/ApprovalDriveService.php';

if (!function_exists('approval_cost_correction_is_document')) {
function approval_cost_correction_is_document($data) {
    return is_array($data)
        && ((isset($data['cpms_cost_correction']) && (string)$data['cpms_cost_correction'] === '1')
            || (isset($data['cpms_cost_correction_marker']) && (string)$data['cpms_cost_correction_marker'] === 'COST_CORRECTION_V1'));
}}
if (!function_exists('approval_cost_correction_value')) {
function approval_cost_correction_value($data, $key, $default) {
    if (!is_array($data) || !isset($data[$key]) || !is_scalar($data[$key])) return $default;
    return (string)$data[$key];
}}
if (!function_exists('approval_cost_correction_line_role')) {
function approval_cost_correction_line_role($line) {
    if (!is_array($line)) return '';
    if (isset($line['role_type']) && trim((string)$line['role_type']) !== '') return trim((string)$line['role_type']);
    return isset($line['role']) ? trim((string)$line['role']) : '';
}}
if (!function_exists('approval_cost_correction_line_name')) {
function approval_cost_correction_line_name($line) {
    if (!is_array($line)) return '';
    if (isset($line['approver_name']) && trim((string)$line['approver_name']) !== '') return trim((string)$line['approver_name']);
    if (isset($line['emp']) && is_array($line['emp']) && isset($line['emp']['name'])) return trim((string)$line['emp']['name']);
    return '';
}}
if (!function_exists('approval_cost_correction_money_label')) {
function approval_cost_correction_money_label($value) {
    $raw = preg_replace('/[^0-9.\-]/', '', (string)$value);
    if ($raw === '' || !is_numeric($raw)) return '-';
    return number_format((float)$raw) . '원';
}}
if (!function_exists('approval_cost_correction_files')) {
function approval_cost_correction_files($files) {
    $result = array();
    if (!is_array($files)) return $result;
    foreach ($files as $rows) {
        if (is_array($rows) && isset($rows['id'])) { $result[] = $rows; continue; }
        if (!is_array($rows)) continue;
        for ($i=0;$i<count($rows);$i++) if (is_array($rows[$i])) $result[] = $rows[$i];
    }
    return $result;
}}

if (!function_exists('render_approval_cost_correction_document')) {
function render_approval_cost_correction_document($data, $lines, $mode, $files, $options) {
    $data = is_array($data) ? $data : array();
    $lines = is_array($lines) ? $lines : array();
    $options = is_array($options) ? $options : array();
    $edit = ($mode === 'edit');
    $projects = isset($options['projects']) && is_array($options['projects']) ? $options['projects'] : array();
    $writerEmail = approval_cost_correction_value($data, 'writer_email', isset($options['writer_email']) ? $options['writer_email'] : '');

    $formType = approval_cost_correction_value($data, 'correction_form_type', 'labor');
    if (!in_array($formType,array('labor','equipment','outsourcing','input'),true)) $formType='labor';
    $requestMode = strtoupper(approval_cost_correction_value($data,'correction_mode','ADD')) === 'MODIFY' ? 'MODIFY' : 'ADD';
    $projectId = (int)approval_cost_correction_value($data,'project_id','0');
    $projectName = approval_cost_correction_value($data,'project_name','');
    $draftDate = approval_cost_correction_value($data,'draft_date',date('Y-m-d'));
    $draftDepartment = approval_cost_correction_value($data,'draft_department','');
    $drafterName = approval_cost_correction_value($data,'drafter_name','');
    $useDate = approval_cost_correction_value($data,'use_date',date('Y-m-d'));
    $category = approval_cost_correction_value($data,'cost_category','자재비');
    $vendorId = (int)approval_cost_correction_value($data,'vendor_id','0');
    $vendorName = approval_cost_correction_value($data,'vendor_name','');
    $workerName = approval_cost_correction_value($data,'worker_name','');
    $currentGongsu = approval_cost_correction_value($data,'current_gongsu','');
    $requestedGongsu = approval_cost_correction_value($data,'requested_gongsu','1.0');
    $targetId = approval_cost_correction_value($data,'target_id','');
    $itemName = approval_cost_correction_value($data,'item_name','');
    $quantity = approval_cost_correction_value($data,'quantity','1');
    $unitPrice = approval_cost_correction_value($data,'unit_price','');
    $amount = approval_cost_correction_value($data,'amount','');
    $memo = approval_cost_correction_value($data,'memo','');
    $reason = approval_cost_correction_value($data,'reason','');
    $settlementYm = approval_cost_correction_value($data,'settlement_ym','');
    $autoStatus = approval_cost_correction_value($data,'auto_apply_status','');
    $changeAmount = (float)approval_cost_correction_value($data,'change_amount','0');
    $ceoRequired = ((int)approval_cost_correction_value($data,'ceo_approval_required','0') === 1);
    $ceoPreview = isset($options['ceo_preview']) && is_array($options['ceo_preview']) ? $options['ceo_preview'] : array();
    $ceoPreviewName = isset($ceoPreview['name']) ? trim((string)$ceoPreview['name']) : '';
    $laborChanges = isset($data['labor_changes']) && is_array($data['labor_changes']) ? $data['labor_changes'] : array();
    if (count($laborChanges) === 0 && $workerName !== '') {
        $laborChanges[] = array('worker_name'=>$workerName,'current_gongsu'=>$currentGongsu,'requested_gongsu'=>$requestedGongsu);
    }

    $roles=array(); $lineByRole=array();
    for($i=0;$i<count($lines);$i++){
        $role=approval_cost_correction_line_role($lines[$i]);
        if($role==='')continue;
        $roles[]=$role; $lineByRole[$role]=$lines[$i];
    }

    echo '<style>';
    echo '.cost-correction-paper .cc-input,.cost-correction-paper .cc-select{width:100%;border:0;border-bottom:1px solid #888;background:transparent;font-size:13px;padding:4px 2px}.cost-correction-paper .cc-select{background:#fff}.cost-correction-paper .cc-textarea{width:100%;border:0;background:transparent;min-height:100px;resize:vertical;line-height:1.6}.cost-correction-paper .cc-radios{display:flex;flex-wrap:wrap;gap:10px}.cost-correction-paper .cc-radios label{white-space:nowrap}.cost-correction-paper .cc-hidden{display:none!important}.cost-correction-paper .cc-change-notice{margin-top:10px;padding:10px 12px;border:1px solid #dbeafe;background:#eff6ff;border-radius:10px;font-size:12px;font-weight:800;color:#1e3a8a}.cost-correction-paper .cc-change-notice.cc-ceo-required{border-color:#fecaca;background:#fff1f2;color:#9f1239}.cost-correction-paper .cc-search{position:relative}.cost-correction-paper .cc-suggestions{display:none;position:absolute;left:0;right:0;top:100%;z-index:99;max-height:230px;overflow:auto;border:1px solid #999;background:#fff;box-shadow:0 10px 22px rgba(0,0,0,.14)}.cost-correction-paper .cc-suggestion{display:block;width:100%;border:0;border-bottom:1px solid #eee;background:#fff;text-align:left;padding:8px 10px;font-size:12px;cursor:pointer}.cost-correction-paper .cc-suggestion:hover{background:#eff6ff}.cost-correction-paper .cc-help{font-size:11px;color:#64748b;margin-top:4px}.cost-correction-paper .cc-section{font-weight:900;text-align:center;background:#f5f5f5}.cost-correction-paper .cc-reason{height:145px;vertical-align:top}.cost-correction-paper .cc-status{font-weight:900;color:#047857}.cost-correction-paper .cc-view{white-space:pre-wrap;line-height:1.6}.cost-correction-paper .cc-target-note{font-size:11px;color:#475569;margin-top:4px}.cost-correction-paper .cc-attach-list{display:flex;flex-direction:column;gap:6px}.cost-correction-paper .cc-attach-row{display:flex;justify-content:space-between;align-items:center;gap:10px;border:1px solid #ddd;background:#fafafa;padding:6px 8px}.cost-correction-paper .cc-labor-wrap{padding:8px}.cost-correction-paper .cc-labor-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:7px}.cost-correction-paper .cc-labor-table{width:100%;border-collapse:collapse;table-layout:fixed}.cost-correction-paper .cc-labor-table th,.cost-correction-paper .cc-labor-table td{padding:6px 5px;text-align:center;font-size:12px}.cost-correction-paper .cc-labor-table .cc-worker-name{text-align:left;font-weight:700}.cost-correction-paper .cc-labor-selected{border:1px solid #d1d5db}.cost-correction-paper .cc-labor-status{padding:14px 8px;color:#64748b;font-size:12px}.cost-correction-paper .cc-worker-search{width:100%;max-width:none}.cost-correction-paper .cc-labor-count{font-size:11px;color:#475569;margin-left:auto}.cost-correction-paper .cc-worker-option-meta{display:block;margin-top:2px;color:#64748b;font-size:11px}.cost-correction-paper .cc-remove-worker{border:1px solid #fecaca;background:#fff;color:#dc2626;border-radius:7px;padding:4px 9px;font-size:11px;font-weight:800;cursor:pointer}.cost-correction-paper .cc-remove-worker:hover{background:#fef2f2}';
    echo '</style>';

    echo '<div class="approval-paper proposal-paper cost-correction-paper">';
    echo '<div class="doc-title">비용 수정/누락 신청</div>';

    echo '<table><tr><td style="width:36%;padding:0"><table>';
    echo '<tr><th style="width:90px">신청일자</th><td>'.h($draftDate).'</td></tr>';
    echo '<tr><th>신청부서</th><td>'.h($draftDepartment!==''?$draftDepartment:'-').'</td></tr>';
    echo '<tr><th>신청자</th><td>'.h($drafterName!==''?$drafterName:'-').'</td></tr>';
    echo '<tr><th>대상현장</th><td id="cc_project_name_preview">'.h($projectName!==''?$projectName:($edit?'선택 전':'-')).'</td></tr>';
    echo '</table></td><td style="width:64%;padding:0">';
    echo '<table class="approval-line-table" id="cc_approval_line_table"><colgroup><col class="approval-side-col">';
    $cols=count($roles)+1; if($cols<2)$cols=2; for($i=0;$i<$cols;$i++)echo '<col>';
    if($edit)echo '<col class="cc-ceo-approval-cell cc-hidden">';
    echo '</colgroup><tr><th rowspan="4">결<br>재</th><th>담당</th>';
    for($i=0;$i<count($roles);$i++)echo '<th>'.h(approval_role_label($roles[$i])).'</th>';
    if($edit)echo '<th class="cc-ceo-approval-cell cc-hidden">대표</th>';
    echo '</tr><tr class="approval-sign-row">';
    approval_render_sign_cell(array(),array('name'=>$drafterName!==''?$drafterName:'-','is_drafter'=>1,'writer_email'=>$writerEmail));
    for($i=0;$i<count($roles);$i++){ $r=$roles[$i]; approval_render_sign_cell(isset($lineByRole[$r])?$lineByRole[$r]:array(),array()); }
    if($edit)echo '<td class="cc-ceo-approval-cell cc-hidden"><div class="approval-sign-cell"><span class="doc-time">서명 대기</span></div></td>';
    echo '</tr><tr class="approval-name-row">';
    approval_render_name_cell($drafterName!==''?$drafterName:'-');
    for($i=0;$i<count($roles);$i++){ $r=$roles[$i]; approval_render_name_cell(approval_cost_correction_line_name(isset($lineByRole[$r])?$lineByRole[$r]:array())); }
    if($edit)echo '<td class="approval-name-cell cc-ceo-approval-cell cc-hidden">'.h($ceoPreviewName!==''?$ceoPreviewName:'대표 설정 필요').'</td>';
    echo '</tr><tr class="approval-time-row">';
    approval_render_time_cell(array(),array('is_drafter'=>1));
    for($i=0;$i<count($roles);$i++){ $r=$roles[$i]; approval_render_time_cell(isset($lineByRole[$r])?$lineByRole[$r]:array(),array()); }
    if($edit)echo '<td class="approval-time-cell cc-ceo-approval-cell cc-hidden">-</td>';
    echo '</tr></table></td></tr></table>';

    echo '<table style="margin-top:12px">';
    echo '<tr><th style="width:14%">신청구분</th><td>';
    $labels=array('labor'=>'노무비','equipment'=>'장비비','outsourcing'=>'외주비','input'=>'자재비·안전관리비·기타경비·구매품');
    if($edit){ echo '<div class="cc-radios">'; foreach($labels as $k=>$label)echo '<label><input type="radio" name="form_type" value="'.h($k).'"'.($formType===$k?' checked':'').'> '.h($label).'</label>'; echo '</div>'; }
    else echo '<b>'.h(isset($labels[$formType])?$labels[$formType]:'-').'</b>';
    echo '</td></tr>';
    echo '<tr><th>처리구분</th><td>';
    if($edit) echo '<div class="cc-radios"><label><input type="radio" name="request_mode" value="ADD"'.($requestMode==='ADD'?' checked':'').'> 누락자료 추가</label><label><input type="radio" name="request_mode" value="MODIFY"'.($requestMode==='MODIFY'?' checked':'').'> 기존자료 수정</label></div>';
    else echo h($requestMode==='MODIFY'?'기존자료 수정':'누락자료 추가');
    echo '</td></tr>';
    echo '<tr><th>현장</th><td>';
    if($edit){ echo '<select name="project_id" id="cc_project_id" class="cc-select" required><option value="">현장을 선택해주세요</option>'; for($i=0;$i<count($projects);$i++){ $pid=isset($projects[$i]['id'])?(int)$projects[$i]['id']:0; $pn=isset($projects[$i]['name'])?trim((string)$projects[$i]['name']):''; if($pid>0&&$pn!=='')echo '<option value="'.$pid.'"'.($pid===$projectId?' selected':'').'>'.h($pn).'</option>'; } echo '</select>'; }
    else echo h($projectName!==''?$projectName:'-');
    echo '</td></tr></table>';

    if(!$edit && $requestMode==='MODIFY') {
        echo '<table style="margin-top:8px"><tr><th style="width:14%">수정 원본</th><td>'.h($targetId!==''?'원본자료 #'.$targetId:'-').'</td></tr></table>';
    }

    echo '<table style="margin-top:8px"><tr><th colspan="4" class="cc-section">신청 내용</th></tr>';
    if($edit){
        echo '<tbody id="cc_labor_fields" class="'.($formType==='labor'?'':'cc-hidden').'">';
        echo '<tr><th style="width:14%">수정 신청 날짜</th><td style="width:36%"><input type="date" name="use_date" id="cc_labor_date" class="cc-input" value="'.h($useDate).'"></td><th style="width:14%">근로자 선택</th><td><div class="cc-search"><input type="text" id="cc_worker_search" class="cc-input cc-worker-search" autocomplete="off" placeholder="근로자 이름 검색 후 선택"><div id="cc_worker_suggestions" class="cc-suggestions"></div></div><div class="cc-help">해당 월 노무비 인원에서 이름을 검색해 한 명씩 추가합니다.</div></td></tr>';
        echo '<tr><td colspan="4" class="cc-labor-wrap"><div class="cc-labor-toolbar"><span class="cc-help" style="margin:0">선택한 근로자만 아래 목록에 표시됩니다.</span><span id="cc_labor_count" class="cc-labor-count">선택 0명</span></div><div class="cc-labor-selected"><table class="cc-labor-table"><colgroup><col><col style="width:120px"><col style="width:150px"><col style="width:80px"></colgroup><thead><tr><th>근로자</th><th>현재 공수</th><th>변경 공수</th><th>삭제</th></tr></thead><tbody id="cc_labor_worker_rows"><tr><td colspan="4" class="cc-labor-status">현장과 수정 신청 날짜를 선택한 뒤 위에서 근로자를 검색해 추가해주세요.</td></tr></tbody></table></div></td></tr></tbody>';

        echo '<tbody id="cc_cost_fields" class="'.($formType!=='labor'?'':'cc-hidden').'">';
        echo '<tr id="cc_category_row" class="'.($formType==='input'?'':'cc-hidden').'"><th style="width:14%">비용구분</th><td colspan="3"><select name="category" id="cc_category" class="cc-select">'; foreach(array('자재비','안전관리비','기타경비','구매품') as $c)echo '<option value="'.h($c).'"'.($category===$c?' selected':'').'>'.h($c).'</option>'; echo '</select></td></tr>';
        echo '<tr><th>업체</th><td colspan="3"><div class="cc-search"><input type="hidden" name="vendor_id" id="cc_vendor_id" value="'.$vendorId.'"><input type="text" id="cc_vendor_search" class="cc-input" autocomplete="off" value="'.h($vendorName).'" placeholder="업체명 또는 사업자번호 검색"><div id="cc_vendor_suggestions" class="cc-suggestions"></div></div><div class="cc-help">업체관리 등록업체만 선택할 수 있습니다. 직접 입력한 업체명은 저장되지 않습니다.</div></td></tr>';
        echo '<tr><th>수정 신청 날짜</th><td><input type="date" name="use_date_cost" id="cc_cost_date" class="cc-input" value="'.h($useDate).'"></td><th id="cc_item_label">품목/내용</th><td><input type="text" name="item_name" id="cc_item_name" class="cc-input" value="'.h($itemName).'"></td></tr>';
        echo '<tr id="cc_modify_wrap" class="'.($requestMode==='MODIFY'?'':'cc-hidden').'"><th>수정 원본</th><td colspan="3"><select name="target_id" id="cc_target_id" class="cc-select"><option value="">원본자료를 선택해주세요</option></select><div id="cc_target_note" class="cc-target-note">현장, 비용구분, 수정 신청 날짜를 선택하면 해당 마감기간의 기존자료를 불러옵니다.</div></td></tr>';
        echo '<tr id="cc_quantity_row"><th id="cc_quantity_label">수량</th><td><input type="number" step="0.01" min="0.01" name="quantity" id="cc_quantity" class="cc-input" value="'.h($quantity!==''?$quantity:'1').'"></td><th>단가</th><td><input type="text" inputmode="numeric" name="unit_price" id="cc_unit_price" class="cc-input js-cc-money" value="'.h($unitPrice).'"></td></tr>';
        echo '<tr><th>금액</th><td colspan="3"><input type="text" inputmode="numeric" name="amount" id="cc_amount" class="cc-input js-cc-money" value="'.h($amount).'"></td></tr>';
        echo '<tr><th>비고/상세내용</th><td colspan="3"><textarea name="memo" id="cc_memo" class="cc-textarea" style="min-height:65px">'.h($memo).'</textarea></td></tr></tbody>';
    } else {
        if($formType==='labor'){
            echo '<tr><th style="width:14%">수정 신청 날짜</th><td>'.h($useDate!==''?$useDate:'-').'</td><th style="width:14%">신청인원</th><td>'.h(count($laborChanges).'명').'</td></tr>';
            echo '<tr><td colspan="4" style="padding:8px"><table class="cc-labor-table"><colgroup><col><col style="width:150px"><col style="width:150px"></colgroup><thead><tr><th>근로자</th><th>현재 공수</th><th>변경 공수</th></tr></thead><tbody>';
            if(count($laborChanges)===0){ echo '<tr><td colspan="3">-</td></tr>'; }
            else { for($lc=0;$lc<count($laborChanges);$lc++){ $change=$laborChanges[$lc]; $ln=isset($change['worker_name'])?(string)$change['worker_name']:''; $lo=isset($change['current_gongsu'])?(float)$change['current_gongsu']:0; $lnw=isset($change['requested_gongsu'])?(float)$change['requested_gongsu']:0; echo '<tr><td class="cc-worker-name">'.h($ln).'</td><td>'.h(number_format($lo,1).' 공수').'</td><td><b>'.h(number_format($lnw,1).' 공수').'</b></td></tr>'; } }
            echo '</tbody></table></td></tr>';
        } else {
            if($formType==='input')echo '<tr><th style="width:14%">비용구분</th><td colspan="3">'.h($category!==''?$category:'-').'</td></tr>';
            echo '<tr><th style="width:14%">업체</th><td colspan="3">'.h($vendorName!==''?$vendorName:'-').'</td></tr>';
            echo '<tr><th>수정 신청 날짜</th><td>'.h($useDate!==''?$useDate:'-').'</td><th>내용</th><td>'.h($itemName!==''?$itemName:'-').'</td></tr>';
            if($formType!=='outsourcing')echo '<tr><th>수량/공수</th><td>'.h($quantity).'</td><th>단가</th><td>'.h(approval_cost_correction_money_label($unitPrice)).'</td></tr>';
            echo '<tr><th>금액</th><td colspan="3"><b>'.h(approval_cost_correction_money_label($amount)).'</b></td></tr>';
            echo '<tr><th>비고/상세내용</th><td colspan="3"><div class="cc-view">'.nl2br(h($memo!==''?$memo:'-')).'</div></td></tr>';
        }
    }
    echo '</table>';

    echo '<table style="margin-top:8px"><tr><th style="width:14%">수정/누락 사유</th><td class="cc-reason">';
    if($edit)echo '<textarea name="reason" id="cc_reason" class="cc-textarea" required>'.h($reason).'</textarea>'; else echo '<div class="cc-view">'.nl2br(h($reason!==''?$reason:'-')).'</div>';
    echo '</td></tr>';
    if(!$edit)echo '<tr><th>신청 변동금액</th><td><b>'.h(approval_cost_correction_money_label($changeAmount)).'</b>'.($ceoRequired?' · 100만원 이상 대표 결재 포함':'').'</td></tr>';
    if(!$edit&&$settlementYm!=='')echo '<tr><th>반영 마감월</th><td>'.h($settlementYm).'</td></tr>';
    if(!$edit&&$autoStatus!==''){ $st=$autoStatus==='APPLIED'?'최종승인 후 공사자료 자동 반영 완료':($autoStatus==='PENDING'?'최종승인 대기':$autoStatus); echo '<tr><th>자동반영</th><td class="cc-status">'.h($st).'</td></tr>'; }
    echo '</table>';
    if($edit)echo '<div id="cc_change_amount_notice" class="cc-change-notice">현재 신청 변동금액: 0원 · 100만원 이상이면 대표 결재가 자동 추가됩니다.</div>';

    echo '<div class="doc-attach"><div class="doc-attach-heading"><b>증빙자료</b></div>';
    if($edit){ echo '<input type="file" name="evidence[]" id="cc_evidence" multiple accept=".pdf,.xls,.xlsx,.xlsm,.csv,.jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.hwp,.hwpx,.doc,.docx,.ppt,.pptx,.txt"><div class="cc-help">최대 20개, 파일당 20MB 이하.</div>'; }
    else { $flat=approval_cost_correction_files($files); if(count($flat)===0)echo '<div class="attach-empty">첨부된 증빙자료가 없습니다.</div>'; else { echo '<div class="cc-attach-list">'; for($i=0;$i<count($flat);$i++){ $f=$flat[$i]; $fn=isset($f['original_name'])?(string)$f['original_name']:'증빙자료'; echo '<div class="cc-attach-row"><span>'.h($fn).'</span><span>'.cpms_approval_drive_file_links_html($f).'</span></div>'; } echo '</div>'; } }
    echo '</div>';
    if($edit)echo '<div class="cc-help" style="margin-top:12px;font-weight:700">결재선: 담당 → 팀장 → 관리 → 공사PM → 부사장 · 신청 변동금액 100만원 이상이면 대표 자동 추가 (신청자가 팀장이면 팀장 단계 자동 제외)</div>';
    echo '</div>';
}}
