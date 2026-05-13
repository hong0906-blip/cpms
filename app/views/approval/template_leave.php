<?php
function render_approval_leave_document($data, $lines, $mode)
{
    $rt = approval_doc_get($data,'request_type','연차');
    $start=approval_doc_get($data,'leave_start_date',date('Y-m-d')); $end=approval_doc_get($data,'leave_end_date',date('Y-m-d')); $days=approval_doc_get($data,'leave_days','1');
    $period=$start.' ~ '.$end.' / '.$days.'일';
    echo '<div class="approval-paper leave-paper"><div class="doc-title" style="letter-spacing:0;font-size:44px">휴가계</div>';
    echo '<div style="display:flex;justify-content:flex-end;margin-bottom:12px"><table style="width:320px"><tr><th rowspan="2" style="width:35px">결<br>재</th><th>팀장</th><th>부사장</th></tr><tr><td>'; approval_render_sign_cell(isset($lines[0])?$lines[0]:array()); echo '</td><td>'; approval_render_sign_cell(isset($lines[1])?$lines[1]:array()); echo '<div style="font-weight:bold;margin-top:3px">전결</div></td></tr></table></div>';
    echo '<table><tr><th style="width:78px">신 청<br>구 분</th><td>';
    echo (($rt==='연차')?'■':'□').'연차 &nbsp;&nbsp;'.(($rt==='결근')?'■':'□').'결근 &nbsp;&nbsp;'.((strpos($rt,'반차')!==false)?'■':'□').'반차(오전/오후) &nbsp;&nbsp;'.(($rt==='경조휴가')?'■':'□').'경조휴가<br>';
    echo (($rt==='공가')?'■':'□').'공가(예비군/민방위) &nbsp;&nbsp;'.(($rt==='기타')?'■':'□').'기타('.h(approval_doc_get($data,'request_type_etc','')).')';
    if($mode==='edit'){ echo '<div style="margin-top:5px">'; foreach(array('연차','결근','반차 오전','반차 오후','경조휴가','공가','기타') as $v){ echo '<label style="margin-right:8px"><input type="radio" name="request_type" value="'.h($v).'" '.($rt===$v?'checked="checked"':'').'>'.h($v).'</label>'; } echo '<input type="text" name="request_type_etc" value="'.h(approval_doc_get($data,'request_type_etc','')).'" placeholder="기타 사유" class="doc-input" style="max-width:160px"></div>'; }    
    echo '</td></tr><tr><th>소 속</th><td style="width:34%">'; approval_doc_field($mode,'department',approval_doc_get($data,'department','창명건설'),'doc-input'); echo '</td><th style="width:10%">직 위</th><td>'; approval_doc_field($mode,'position',approval_doc_get($data,'position',''),'doc-input'); echo '</td></tr>';
    echo '<tr><th>성 명</th><td>'; approval_doc_field($mode,'applicant_name',approval_doc_get($data,'applicant_name',''),'doc-input'); echo '</td><th>생년월일</th><td>'; approval_doc_field($mode,'birth_date',approval_doc_get($data,'birth_date',''),'doc-input','date'); echo '</td></tr>';
    echo '<tr><th>휴가기간</th><td colspan="3">'; if($mode==='edit'){ approval_doc_field($mode,'leave_start_date',$start,'doc-input','date'); echo ' ~ '; approval_doc_field($mode,'leave_end_date',$end,'doc-input','date'); echo ' / '; approval_doc_field($mode,'leave_days',$days,'doc-input','number'); echo '일'; } else { echo h($period);} echo '</td></tr>';
    echo '<tr><th>휴 가 사 유</th><td colspan="3" style="height:220px;vertical-align:top">'; if($mode==='edit'){echo '<textarea name="leave_reason" class="doc-textarea" style="min-height:120px">'.h(approval_doc_get($data,'leave_reason','')).'</textarea>';}else{echo nl2br(h(approval_doc_get($data,'leave_reason','-')));} echo '<div style="margin-top:55px;font-size:12px">'.h(approval_default_leave_agreement()).'</div></td></tr></table>';
    echo '<div style="text-align:center;margin:20px 0 10px 0">'; approval_doc_field($mode,'request_date',approval_doc_get($data,'request_date',date('Y-m-d')),'doc-input','date'); echo '</div>';
    $sig=approval_sign_path_by_email(isset($_SESSION['cpms_user']['email'])?$_SESSION['cpms_user']['email']:'');
    echo '<div style="text-align:right">위 신청인 &nbsp;'; approval_doc_field($mode,'applicant_sign_name',approval_doc_get($data,'applicant_sign_name',approval_doc_get($data,'applicant_name','')),'doc-input'); echo '&nbsp; (인 또는 서명)'; if($sig!==''){ echo '<img src="../'.h($sig).'" class="doc-sign" style="display:inline-block;vertical-align:middle">'; } else { echo '<span class="doc-time">서명 미등록</span>'; } echo '</div>';
    echo '<div style="text-align:center;font-size:40px;font-weight:700;margin-top:24px">주식회사 창명건설</div></div>';
}