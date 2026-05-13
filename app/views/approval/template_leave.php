<?php
function render_approval_leave_document($data, $lines, $mode)
{
    $rt = approval_doc_get($data,'request_type','연차');
    echo '<div class="approval-paper leave-paper"><div style="position:relative;min-height:150px"><div class="doc-title" style="letter-spacing:0;font-size:48px">휴가계</div>';
    echo '<table style="width:390px;position:absolute;right:0;top:0"><tr><th rowspan="2" style="width:38px">결<br>재</th><th>팀장</th><th>PM</th><th>부사장</th><th>대표이사</th></tr><tr><td>'; approval_render_sign_cell(isset($lines[0])?$lines[0]:array()); echo '</td><td>'; approval_render_sign_cell(isset($lines[1])?$lines[1]:array()); echo '</td><td>'; approval_render_sign_cell(isset($lines[2])?$lines[2]:array()); echo '</td><td style="background:linear-gradient(135deg,transparent 49%,#777 50%,transparent 51%)">'; approval_render_sign_cell(isset($lines[3])?$lines[3]:array()); echo '</td></tr></table></div>';
    echo '<table><tr><th style="width:78px">신 청<br>구 분</th><td>';
    echo (($rt==='연차')?'■':'□').'연차 &nbsp;&nbsp;'.(($rt==='결근')?'■':'□').'결근 &nbsp;&nbsp;'.((strpos($rt,'반차')!==false)?'■':'□').'반차(오전/오후) &nbsp;&nbsp;'.(($rt==='경조휴가')?'■':'□').'경조휴가<br>';
    echo (($rt==='공가')?'■':'□').'공가(예비군/민방위) &nbsp;&nbsp;'.(($rt==='기타')?'■':'□').'기타('.h(approval_doc_get($data,'request_type_etc','')).')';
    echo '</td></tr><tr><th>소 속</th><td style="width:34%">'; approval_doc_field($mode,'department',approval_doc_get($data,'department','창명건설'),'doc-input'); echo '</td><th style="width:10%">직 위</th><td>'; approval_doc_field($mode,'position',approval_doc_get($data,'position',''),'doc-input'); echo '</td></tr>';
    echo '<tr><th>성 명</th><td>'; approval_doc_field($mode,'applicant_name',approval_doc_get($data,'applicant_name',''),'doc-input'); echo '</td><th>생년월일</th><td>'; approval_doc_field($mode,'birth_date',approval_doc_get($data,'birth_date',''),'doc-input'); echo '</td></tr>';
    echo '<tr><th>휴가기간</th><td colspan="3">'; approval_doc_field($mode,'leave_period_text',approval_doc_get($data,'leave_period_text',approval_doc_get($data,'leave_start_date','').' ~ '.approval_doc_get($data,'leave_end_date','')),'doc-input'); echo '</td></tr>';
    echo '<tr><th>휴 가 사 유</th><td colspan="3" style="height:260px;vertical-align:top">-<br>'; if($mode==='edit'){echo '<textarea name="leave_reason" class="doc-textarea" style="min-height:120px">'.h(approval_doc_get($data,'leave_reason','')).'</textarea>';}else{echo nl2br(h(approval_doc_get($data,'leave_reason','')));} echo '<div style="margin-top:55px;font-size:12px">'.h(approval_default_leave_agreement()).'</div></td></tr></table>';
    echo '<div style="text-align:center;margin:28px 0 20px 0">'; approval_doc_field($mode,'request_date',approval_doc_get($data,'request_date',date('Y-m-d')),'doc-input'); echo '</div>';
    echo '<div style="text-align:right">위 신청인 &nbsp;'; approval_doc_field($mode,'applicant_sign_name',approval_doc_get($data,'applicant_sign_name',approval_doc_get($data,'applicant_name','')),'doc-input'); echo '&nbsp; (인 또는 서명)</div>';
    echo '<div style="text-align:center;font-size:44px;font-weight:700;margin-top:34px">주식회사 창명건설</div>';
    if($mode==='edit'){ echo '<input type="hidden" name="request_type" value="'.h($rt).'"><input type="hidden" name="leave_start_date" value="'.h(approval_doc_get($data,'leave_start_date','')).'"><input type="hidden" name="leave_end_date" value="'.h(approval_doc_get($data,'leave_end_date','')).'"><input type="hidden" name="emergency_contact" value="'.h(approval_doc_get($data,'emergency_contact','')).'">'; }
    echo '</div>';
}