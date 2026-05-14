<?php
function render_approval_leave_document($data, $lines, $mode, $approvalOptions)
{
    $rt = approval_doc_get($data,'request_type','연차');
    $start=approval_doc_get($data,'leave_start_date',date('Y-m-d')); $end=approval_doc_get($data,'leave_end_date',date('Y-m-d')); $days=approval_doc_get($data,'leave_days','1');
    $period=$start.' ~ '.$end.' / '.$days.'일';
    $applicantEmail = approval_doc_get($data,'applicant_email',approval_doc_get($data,'writer_email',''));
    $sig=approval_sign_path_by_email($applicantEmail);    
    echo '<div class="approval-paper leave-paper"><div class="doc-title" style="letter-spacing:0;font-size:44px">휴가계</div>';
    echo '<div style="display:flex;justify-content:flex-end;margin-bottom:12px"><table class="approval-line-table" style="width:440px"><tr><th rowspan="4">결재</th><th>팀장</th><th>부사장</th><th>대표이사</th></tr><tr class="approval-sign-row">';
    approval_render_sign_cell(isset($lines[0])?$lines[0]:array(), array('name'=>isset($lines[0]['approver_name'])?$lines[0]['approver_name']:'-'));
    approval_render_sign_cell(isset($lines[1])?$lines[1]:array(), array('name'=>isset($lines[1]['approver_name'])?$lines[1]['approver_name']:'-'));
    approval_render_sign_cell(array(), array('name'=>'대표이사','is_delegated'=>1));
    echo '</tr><tr class="approval-name-row">';
    if($mode==='edit'){ approval_render_select_cell('team_lead_id',isset($approvalOptions['team_lead'])?$approvalOptions['team_lead']:array(),'', '팀장 선택'); } else { approval_render_name_cell(isset($lines[0]['approver_name'])?$lines[0]['approver_name']:'-'); }
    approval_render_name_cell(isset($approvalOptions['vp']['name'])?$approvalOptions['vp']['name']:(isset($lines[1]['approver_name'])?$lines[1]['approver_name']:'-'));
    approval_render_name_cell('대표이사');
    echo '</tr><tr class="approval-time-row">';
    approval_render_time_cell(isset($lines[0])?$lines[0]:array(), array());
    approval_render_time_cell(isset($lines[1])?$lines[1]:array(), array());
    approval_render_time_cell(array(), array('is_delegated'=>1));
    echo '</tr></table></div>';
    echo '<table><tr><th style="width:78px">신청구분</th><td>';
    if($mode==='edit'){ foreach(array('연차','결근','반차 오전','반차 오후','경조휴가','공가','기타') as $v){ echo '<label style="margin-right:8px"><input type="radio" name="request_type" value="'.h($v).'" '.($rt===$v?'checked="checked"':'').'>'.h($v).'</label>'; } echo '<input type="text" name="request_type_etc" value="'.h(approval_doc_get($data,'request_type_etc','')).'" placeholder="기타" class="doc-input doc-inline-input" style="max-width:120px">'; }
    else { echo (($rt==='연차')?'■':'□').'연차 '.(($rt==='결근')?'■':'□').'결근 '.((strpos($rt,'반차')!==false)?'■':'□').'반차(오전/오후) '.(($rt==='경조휴가')?'■':'□').'경조휴가<br>'.(($rt==='공가')?'■':'□').'공가(예비군/민방위) '.(($rt==='기타')?'■':'□').'기타('.h(approval_doc_get($data,'request_type_etc','')).')'; }
    echo '</td></tr>';
    echo '<tr><th>소속</th><td>'; approval_doc_field($mode,'department',approval_doc_get($data,'department','창명건설'),'doc-input'); echo '</td><th>직위</th><td>'; approval_doc_field($mode,'position',approval_doc_get($data,'position',''),'doc-input'); echo '</td></tr>';
    echo '<tr><th>성명</th><td>'; approval_doc_field($mode,'applicant_name',approval_doc_get($data,'applicant_name',''),'doc-input'); echo '</td><th>생년월일</th><td>'; approval_doc_field($mode,'birth_date',approval_doc_get($data,'birth_date',''),'doc-input','date'); echo '</td></tr>';
    echo '<tr><th>휴가기간</th><td colspan="3">'; if($mode==='edit'){ approval_doc_field($mode,'leave_start_date',$start,'doc-input doc-inline-input','date'); echo ' ~ '; approval_doc_field($mode,'leave_end_date',$end,'doc-input doc-inline-input','date'); echo ' / <input type="number" name="leave_days" value="'.h($days).'" class="doc-input doc-inline-input doc-money-input" readonly="readonly">일'; } else { echo h($period);} echo '</td></tr>';
    echo '<tr><th>휴가사유</th><td colspan="3" style="height:220px;vertical-align:top">'; if($mode==='edit'){echo '<textarea name="leave_reason" class="doc-textarea" style="min-height:120px">'.h(approval_doc_get($data,'leave_reason','')).'</textarea>';}else{echo nl2br(h(approval_doc_get($data,'leave_reason','-')));} echo '<div style="margin-top:55px;font-size:12px">'.h(approval_default_leave_agreement()).'</div></td></tr></table>';
    echo '<div style="text-align:center;margin:20px 0 10px 0">'; approval_doc_field($mode,'request_date',approval_doc_get($data,'request_date',date('Y-m-d')),'doc-input doc-inline-input','date'); echo '</div>';
    echo '<div style="text-align:right">위 신청인 '; approval_doc_field($mode,'applicant_sign_name',approval_doc_get($data,'applicant_sign_name',approval_doc_get($data,'applicant_name','')),'doc-input doc-inline-input'); echo ' (인 또는 서명) '; if($sig!==''){ echo '<img src="'.h('../'.$sig).'" class="doc-sign" style="display:inline-block;vertical-align:middle">'; } else { echo '<span class="doc-time">서명 미등록</span>'; } echo '</div>';
    if($mode==='edit'){ echo '<script>(function(){var s=document.querySelector("input[name=leave_start_date]");var e=document.querySelector("input[name=leave_end_date]");var d=document.querySelector("input[name=leave_days]");if(!s||!e||!d){return;}function c(){if(s.value===""||e.value===""){return;}var sv=new Date(s.value+"T00:00:00");var ev=new Date(e.value+"T00:00:00");if(ev.getTime()<sv.getTime()){alert("종료일은 시작일보다 빠를 수 없습니다.");d.value="";return;}var days=0;var cur=new Date(sv.getTime());while(cur.getTime()<=ev.getTime()){var w=cur.getDay();if(w!==0&&w!==6){days++;}cur.setDate(cur.getDate()+1);}d.value=days;}s.onchange=c;e.onchange=c;c();})();</script>'; }
    echo '<div style="text-align:center;font-size:40px;font-weight:700;margin-top:24px">주식회사 창명건설</div></div>';
}