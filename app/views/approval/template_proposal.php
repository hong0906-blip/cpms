<?php
function render_approval_proposal_document($data, $lines, $mode, $files, $approvalOptions)
{
    $types = array('품의','제안','보고','기타');
    $selectedType=approval_doc_get($data,'draft_type','품의');
    echo '<div class="approval-paper proposal-paper">';
    echo '<div class="doc-title">기안서</div>';
    echo '<table><tr><td style="width:44%;padding:0">';
    echo '<table><tr><th>기안일자</th><td>'; approval_doc_field($mode,'draft_date',approval_doc_get($data,'draft_date',date('Y-m-d')),'doc-input','date'); echo '</td></tr>';
    echo '<tr><th>시행일자</th><td>'; approval_doc_field($mode,'effective_date',approval_doc_get($data,'effective_date',date('Y-m-d')),'doc-input','date'); echo '</td></tr>';
    echo '<tr><th>기안부서</th><td>'; approval_doc_field($mode,'draft_department',approval_doc_get($data,'draft_department',''),'doc-input'); echo '</td></tr>';
    echo '<tr><th>기 안 자</th><td>'; approval_doc_field($mode,'drafter_name',approval_doc_get($data,'drafter_name',''),'doc-input'); echo '</td></tr></table>';
    echo '</td><td style="width:56%;padding:0">';
    echo '<table class="approval-line-table proposal-approval-line"><colgroup><col class="approval-side-col"><col style="width:16.66%"><col style="width:16.66%"><col style="width:16.66%"><col style="width:16.66%"><col style="width:16.66%"><col style="width:16.66%"></colgroup><tr><th rowspan="4">결재</th><th>담당</th><th>소장</th><th>공무</th><th>관리</th><th>부사장</th><th>대표이사</th></tr>';
    echo '<tr class="approval-sign-row">';
    $writerEmail = approval_doc_get($data,'writer_email',isset($approvalOptions['writer_email'])?$approvalOptions['writer_email']:'');
    approval_render_sign_cell(array(), array('name'=>approval_doc_get($data,'drafter_name','-'),'is_drafter'=>1,'writer_email'=>$writerEmail));
    approval_render_sign_cell(isset($lines[0])?$lines[0]:array(), array('name'=>isset($lines[0]['approver_name'])?$lines[0]['approver_name']:'-'));
    approval_render_sign_cell(isset($lines[1])?$lines[1]:array(), array('name'=>isset($lines[1]['approver_name'])?$lines[1]['approver_name']:'-'));
    approval_render_sign_cell(isset($lines[2])?$lines[2]:array(), array('name'=>isset($lines[2]['approver_name'])?$lines[2]['approver_name']:'-'));
    approval_render_sign_cell(isset($lines[3])?$lines[3]:array(), array('name'=>isset($lines[3]['approver_name'])?$lines[3]['approver_name']:'-'));
    approval_render_sign_cell(isset($lines[4])?$lines[4]:array(), array('name'=>isset($lines[4]['approver_name'])?$lines[4]['approver_name']:'-'));
    echo '</tr><tr class="approval-name-row">';
    approval_render_name_cell(approval_doc_get($data,'drafter_name','-'));
    if($mode==='edit'){ approval_render_select_cell('sojang_id',isset($approvalOptions['site'])?$approvalOptions['site']:array(),'', '소장 선택'); } else { approval_render_name_cell(isset($lines[0]['approver_name'])?$lines[0]['approver_name']:'-'); }
    if($mode==='edit'){ approval_render_select_cell('gongmu_id',isset($approvalOptions['gongmu'])?$approvalOptions['gongmu']:array(),'', '공무 선택'); } else { approval_render_name_cell(isset($lines[1]['approver_name'])?$lines[1]['approver_name']:'-'); }
    if($mode==='edit'){ approval_render_select_cell('manage_id',isset($approvalOptions['manage'])?$approvalOptions['manage']:array(),'', '관리 선택'); } else { approval_render_name_cell(isset($lines[2]['approver_name'])?$lines[2]['approver_name']:'-'); }
    approval_render_name_cell(isset($approvalOptions['vp']['name'])?$approvalOptions['vp']['name']:(isset($lines[3]['approver_name'])?$lines[3]['approver_name']:'-'));
    approval_render_name_cell(isset($approvalOptions['ceo']['name'])?$approvalOptions['ceo']['name']:(isset($lines[4]['approver_name'])?$lines[4]['approver_name']:'-'));
    echo '</tr><tr class="approval-time-row">';
    approval_render_time_cell(array(), array('is_drafter'=>1));
    approval_render_time_cell(isset($lines[0])?$lines[0]:array(), array());
    approval_render_time_cell(isset($lines[1])?$lines[1]:array(), array());
    approval_render_time_cell(isset($lines[2])?$lines[2]:array(), array());
    approval_render_time_cell(isset($lines[3])?$lines[3]:array(), array());
    approval_render_time_cell(isset($lines[4])?$lines[4]:array(), array());    
    echo '</tr></table>';
    echo '</td></tr></table>';
    echo '<table><tr><th style="width:12%">기안구분</th><td>';
    if($mode==='edit'){ foreach($types as $t){ echo '<label style="margin-right:10px"><input type="radio" name="draft_type" value="'.h($t).'" '.($selectedType===$t?'checked="checked"':'').'>'.h($t).'</label>'; } }
    else { foreach($types as $t){ $mark=$selectedType===$t?'■':'□'; echo $mark.' '.h($t).' &nbsp;&nbsp;'; } }    
    echo '</td></tr></table>';
    echo '<table><tr><th style="width:12%">제 목</th><td>'; approval_doc_field($mode,'title',approval_doc_get($data,'title',''),'doc-input','text'); echo '</td></tr></table>';
    echo '<div class="doc-subline">'; approval_doc_field($mode,'headline',approval_doc_get($data,'headline',''),'doc-input doc-inline-input'); echo '<br>';
    approval_doc_field($mode,'intro_text',approval_doc_get($data,'intro_text','아래와 같이 기안하오니 검토 후 재가하여 주시기 바랍니다.'),'doc-input');
    echo '<div style="text-align:center;margin:16px 0">- 아 래 -</div>';
    echo '1. 사유 : '; approval_doc_field($mode,'reason',approval_doc_get($data,'reason',''),'doc-input doc-inline-input');
    echo '<br>2. 내용 : 1) 업체명 : '; approval_doc_field($mode,'company_name',approval_doc_get($data,'company_name',''),'doc-input doc-inline-input');
    echo '<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;2) 발주금액 : ₩ '; approval_doc_field($mode,'contract_amount',approval_doc_format_amount(approval_doc_get($data,'contract_amount','')),'doc-input doc-inline-input doc-money-input','number'); echo ' 원';
    echo '<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;3) 선급금 지급 요청액 : ₩ '; approval_doc_field($mode,'advance_amount',approval_doc_format_amount(approval_doc_get($data,'advance_amount','')),'doc-input doc-inline-input doc-money-input','number'); echo ' 원';
    echo '<br>3. 특기사항 1 : '; approval_doc_field($mode,'special_note_1',approval_doc_get($data,'special_note_1',''),'doc-input doc-inline-input');
    echo '<br>&nbsp;&nbsp;&nbsp;특기사항 2 : '; approval_doc_field($mode,'special_note_2',approval_doc_get($data,'special_note_2',''),'doc-input doc-inline-input');
    echo '<br>4. 지급 요청일 : '; approval_doc_field($mode,'payment_request_date',approval_doc_get($data,'payment_request_date',date('Y-m-d')),'doc-input doc-inline-input','date');
    echo '<br>5. 예산현황 : '; approval_doc_field($mode,'budget_status',approval_doc_get($data,'budget_status',''),'doc-input doc-inline-input');
    echo '</div><div class="doc-attach">※ 첨부서류';
    $labels = array('order_doc'=>'발주서','business_license'=>'사업자 등록증','etc'=>'기타');
    foreach($labels as $k=>$lb){
        echo '<div class="attach-row">'.h($lb);
        if($mode==='edit'){ echo ' <input type="file" name="'.h($k).'_file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">'; }
        else { $f = isset($files[$k]) ? $files[$k] : null; if($f){ echo ' : '.h($f['original_name']).' <a href="../'.h($f['file_path']).'" target="_blank">보기</a> <a href="../'.h($f['file_path']).'" download>다운로드</a>'; } else { echo ' : -'; } }
        echo '</div>';
    }
    echo '</div></div>';
}