<?php
use App\Core\Db;
require_once __DIR__.'/_common.php';
require_once __DIR__.'/document_templates.php';
require_once __DIR__.'/template_style.php';
require_once __DIR__.'/template_proposal.php';
require_once __DIR__.'/template_leave.php';

$pdo=Db::pdo();
$type=isset($_GET['type'])?trim((string)$_GET['type']):'proposal';
$isLeave=($type==='leave');
$u=\App\Core\Auth::user();
$dept=isset($u['department'])?trim((string)$u['department']):'';
$name=isset($u['name'])?trim((string)$u['name']):'';
$email=isset($u['email'])?trim((string)$u['email']):'';
$position=isset($u['position'])?trim((string)$u['position']):'';
$emps=array();
$warn=array();
if($pdo){
    $birthSel=approval_column_exists($pdo,'employees','birth_date')?'birth_date':"'' AS birth_date";
    $flags=array('approval_can_be_site_manager','approval_can_be_team_leader','approval_can_be_gongmu_approver','approval_can_be_manage_approver');
    foreach($flags as $fc){ if(!approval_column_exists($pdo,'employees',$fc)) $warn[]=$fc; }
    $sql="SELECT id,name,email,department,position,".$birthSel.",".(in_array('approval_can_be_site_manager',$warn)?"0":"approval_can_be_site_manager")." approval_can_be_site_manager,".(in_array('approval_can_be_team_leader',$warn)?"0":"approval_can_be_team_leader")." approval_can_be_team_leader,".(in_array('approval_can_be_gongmu_approver',$warn)?"0":"approval_can_be_gongmu_approver")." approval_can_be_gongmu_approver,".(in_array('approval_can_be_manage_approver',$warn)?"0":"approval_can_be_manage_approver")." approval_can_be_manage_approver FROM employees WHERE is_active=1 ORDER BY name";
    $emps=$pdo->query($sql)->fetchAll();
}
$site=array();$lead=array();$gong=array();$man=array();$vp=null;$ceo=null;$myBirth='';$deptLead=array();
foreach($emps as $e){ if((int)$e['id']==(int)$u['id'])$myBirth=(string)$e['birth_date']; if((int)$e['approval_can_be_site_manager']===1)$site[]=$e; if((int)$e['approval_can_be_team_leader']===1)$lead[]=$e; if((int)$e['approval_can_be_gongmu_approver']===1)$gong[]=$e; if((int)$e['approval_can_be_manage_approver']===1)$man[]=$e; if($e['position']==='부사장'&&!$vp)$vp=$e; if(in_array($e['position'],array('대표','대표이사'))&&!$ceo)$ceo=$e; if(approval_norm_dept($e['department'])===approval_norm_dept($dept) && (int)$e['approval_can_be_team_leader']===1)$deptLead[]=$e; }
if(count($site)===0){ foreach($emps as $e){ if(in_array(approval_norm_dept($e['department']),array('공사','공사팀'))&&in_array($e['position'],array('과장','차장','부장')))$site[]=$e; } if(count($site)>0)$warn[]='sojang_fallback'; }
if(count($lead)===0){ foreach($emps as $e){ if(in_array($e['position'],array('과장','차장','부장')))$lead[]=$e; } if(count($lead)>0)$warn[]='leader_fallback'; }
if(count($deptLead)===0)$deptLead=$lead;
if(count($gong)===0){ foreach($emps as $e){ if(in_array(approval_norm_dept($e['department']),array('공무','공무팀')))$gong[]=$e; } if(count($gong)>0)$warn[]='gongmu_fallback'; }
if(count($man)===0){ foreach($emps as $e){ if(in_array(approval_norm_dept($e['department']),array('관리','관리팀')))$man[]=$e; } if(count($man)>0)$warn[]='manage_fallback'; }
$init=array('birth_date'=>$myBirth,'draft_date'=>date('Y-m-d'),'effective_date'=>date('Y-m-d'),'draft_department'=>$dept,'drafter_name'=>$name,'title'=>'','department'=>$dept,'position'=>$position,'applicant_name'=>$name,'leave_start_date'=>date('Y-m-d'),'leave_end_date'=>date('Y-m-d'),'request_date'=>date('Y-m-d'),'applicant_sign_name'=>$name,'writer_email'=>$email,'applicant_email'=>$email);
?>
<div class="mb-4 flex items-center justify-between">
  <div class="flex gap-2">
    <a href="?r=approval_home" onclick="if(history.length>1){history.back();return false;}" class="px-4 py-2 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800">← 뒤로가기</a>
    <a href="?r=approval_home" class="px-4 py-2 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800">전자결재 목록</a>
  </div>
</div>
<form method="post" action="?r=approval_store" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?php echo h(csrf_token());?>">
<input type="hidden" name="doc_type" value="<?php echo $isLeave?'leave':'proposal';?>">
<?php if(in_array('sojang_fallback',$warn)){?><div>소장 결재자 역할이 설정되지 않아 공사팀 과장~부장을 임시 후보로 표시합니다. 관리 > 직원명부에서 소장 결재자를 설정해주세요.</div><?php }?>
<?php if(in_array('leader_fallback',$warn)||in_array('gongmu_fallback',$warn)||in_array('manage_fallback',$warn)){?><div>관리 > 직원명부에서 전자결재 역할을 설정해주세요.</div><?php }?>
<?php
$approvalOptions=array('site'=>$site,'gongmu'=>$gong,'manage'=>$man,'team_lead'=>$deptLead,'vp'=>$vp,'ceo'=>$ceo,'writer_email'=>$email);
if($isLeave){ render_approval_leave_document($init,array(), 'edit',$approvalOptions); } else { render_approval_proposal_document($init,array(),'edit',array(),$approvalOptions); }
?>

<div class="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-900">
  작성 내용을 확인한 뒤 전자결재 보내기를 누르면 결재라인 순서대로 요청이 전달됩니다.
  <?php if($isLeave){ ?><div class="mt-1">휴가기간과 신청구분을 다시 확인해주세요.</div><?php } else { ?><div class="mt-1">첨부서류와 결재라인을 다시 확인해주세요.</div><?php } ?>
</div>
<div class="mt-4 flex items-center justify-between gap-3">
  <div class="flex gap-2">
    <a href="?r=approval_home" onclick="if(history.length>1){history.back();return false;}" class="px-4 py-3 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800">← 뒤로가기</a>
    <a href="?r=approval_home" class="px-4 py-3 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800">목록으로</a>
  </div>
  <button type="submit" class="px-8 py-4 rounded-2xl bg-indigo-600 text-white font-extrabold shadow-lg">전자결재 보내기 →</button>
</div>
</form>