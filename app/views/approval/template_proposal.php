<?php
function render_approval_proposal_document($data, $lines, $mode)
{
    $types = array('품의','제안','보고','기타');
    $selectedType=approval_doc_get($data,'draft_type','품의');    
    echo '<div class="approval-paper proposal-paper">';
    echo '<div class="doc-title">기안서 / 품의서</div>';
    echo '<table><tr><td style="width:56%;padding:0">';
    echo '<table><tr><th>기안일자</th><td>'; approval_doc_field($mode,'draft_date',approval_doc_get($data,'draft_date',date('Y-m-d')),'doc-input','date'); echo '</td></tr>';
    echo '<tr><th>시행일자</th><td>'; approval_doc_field($mode,'effective_date',approval_doc_get($data,'effective_date',date('Y-m-d')),'doc-input','date'); echo '</td></tr>';
    echo '<tr><th>기안부서</th><td>'; approval_doc_field($mode,'draft_department',approval_doc_get($data,'draft_department',''),'doc-input'); echo '</td></tr>';
    echo '<tr><th>기 안 자</th><td>'; approval_doc_field($mode,'drafter_name',approval_doc_get($data,'drafter_name',''),'doc-input'); echo '</td></tr></table>';
    echo '</td><td style="width:44%;padding:0">';
    echo '<table class="approval-line-table"><tr><th colspan="7">결 재</th></tr><tr><th rowspan="2">구분</th><th>담당</th><th>소장</th><th>공무</th><th>관리</th><th>부사장</th><th>대표이사</th></tr><tr><th>'.h(approval_doc_get($data,'drafter_name','-')).'</th><td>'; approval_render_sign_cell(isset($lines[0])?$lines[0]:array()); echo '</td><td>'; approval_render_sign_cell(isset($lines[1])?$lines[1]:array()); echo '</td><td>'; approval_render_sign_cell(isset($lines[2])?$lines[2]:array()); echo '</td><td>'; approval_render_sign_cell(isset($lines[3])?$lines[3]:array()); echo '</td><td>'; approval_render_sign_cell(isset($lines[4])?$lines[4]:array()); echo '</td></tr></table>';
    echo '</td></tr></table>';
    echo '<table><tr><th style="width:12%">기안구분</th><td>';
    foreach($types as $t){ $mark=$selectedType===$t?'■':'□'; echo $mark.' '.h($t).' &nbsp;&nbsp;'; }
    if($mode==='edit'){ foreach($types as $t){ echo '<label style="margin-right:10px"><input type="radio" name="draft_type" value="'.h($t).'" '.($selectedType===$t?'checked="checked"':'').'>'.h($t).'</label>'; } }
    echo '</td></tr></table>';
    echo '<table><tr><th style="width:12%">제 목</th><td>'; approval_doc_field($mode,'title',approval_doc_get($data,'title',''),'doc-input','text','예: ○○현장 자재 구매 및 선급금 지급 요청 건'); echo '</td></tr></table>';
    echo '<div class="doc-subline" style="padding:20px 14px 0 14px">[ <span style="color:#d00;font-weight:bold">'; approval_doc_field($mode,'headline',approval_doc_get($data,'headline',''),'doc-input','text','예: [ ○○현장 ] 자재 구매 및 납품을 위한 발주를'); echo '</span> ]<br>';
    approval_doc_field($mode,'intro_text',approval_doc_get($data,'intro_text','아래와 같이 품의하오니 검토 후 재가하여 주시기 바랍니다.'),'doc-input');
    echo '<div style="text-align:center;margin:24px 0">- 아 래 -</div>';
    echo '1. 사  유 : '; approval_doc_field($mode,'reason',approval_doc_get($data,'reason',''),'doc-input','text','예: 자재 구매 및 선급금 지급 요청 건');
    echo '<br>2. 내  용 :<br>&nbsp;&nbsp;&nbsp;1) 업체명 : '; approval_doc_field($mode,'company_name',approval_doc_get($data,'company_name',''),'doc-input','text','예: (주)○○산업');
    echo '<br>&nbsp;&nbsp;&nbsp;2) 발주(계약)금액 : ₩ '; approval_doc_field($mode,'contract_amount',approval_doc_format_amount(approval_doc_get($data,'contract_amount','')),'doc-input','number','예: 44000000'); echo ' 원 (V.A.T 별도)';
    echo '<br>&nbsp;&nbsp;&nbsp;3) 선급금지급 요청액 : ₩ '; approval_doc_field($mode,'advance_amount',approval_doc_format_amount(approval_doc_get($data,'advance_amount','')),'doc-input','number','예: 12000000'); echo ' 원 (V.A.T 별도)';
    echo '<br><br>3. 특기사항<br>&nbsp;&nbsp;&nbsp;1) '; approval_doc_field($mode,'special_note_1',approval_doc_get($data,'special_note_1',''),'doc-input','text','예: 제작기간 3주 소요');
    echo '<br>&nbsp;&nbsp;&nbsp;2) '; approval_doc_field($mode,'special_note_2',approval_doc_get($data,'special_note_2',''),'doc-input');
    echo '<br>4. 지급 요청일 : '; approval_doc_field($mode,'payment_request_date',approval_doc_get($data,'payment_request_date',date('Y-m-d')),'doc-input','date');
    echo '<br>5. '; approval_doc_field($mode,'budget_status',approval_doc_get($data,'budget_status','예산현황(도급대비)      * 직접비 기준'),'doc-input');
    echo '</div><div class="doc-attach">※ 첨부서류 : 1. '; approval_doc_field($mode,'attached_doc_1',approval_doc_get($data,'attached_doc_1',''),'doc-input');
    echo '<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;2. '; approval_doc_field($mode,'attached_doc_2',approval_doc_get($data,'attached_doc_2',''),'doc-input');
    echo '<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'; approval_doc_field($mode,'attached_doc_note',approval_doc_get($data,'attached_doc_note',''),'doc-input');
    echo '</div></div>';
}