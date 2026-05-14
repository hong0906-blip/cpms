<?php
use App\Core\Db;
require_once __DIR__.'/_common.php';
require_once __DIR__.'/document_templates.php';
require_once __DIR__.'/template_style.php';
require_once __DIR__.'/template_proposal.php';
require_once __DIR__.'/template_leave.php';
$pdo=Db::pdo(); $id=isset($_GET['id'])?(int)$_GET['id']:0; $u=\App\Core\Auth::user();
$st=$pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1"); $st->execute(array(':id'=>$id)); $d=$st->fetch();
$st=$pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:id ORDER BY line_order"); $st->execute(array(':id'=>$id)); $lines=$st->fetchAll();
$content=approval_parse_content($d['content']);
$filesByType=array(); if($d && $d['doc_type']==='proposal'){ $fs=$pdo->prepare("SELECT * FROM cpms_approval_files WHERE document_id=:id ORDER BY id DESC"); $fs->execute(array(':id'=>$id)); foreach($fs->fetchAll() as $f){ $k=isset($f['file_type'])?$f['file_type']:''; if($k!=='' && !isset($filesByType[$k])){ $filesByType[$k]=$f; } } }
$canCancel=((int)$d['created_by_id']===(int)$u['id'] && in_array($d['doc_status'],array('PENDING','DRAFT')));
?><div class="space-y-4"><div class="no-print flex gap-2"><a href="javascript:history.back()" class="px-3 py-2 bg-gray-100 rounded">뒤로가기</a><a href="?r=approval_home" class="px-3 py-2 bg-gray-100 rounded">목록으로</a><a href="?r=approval_print&id=<?php echo $id;?>" class="px-3 py-2 bg-indigo-100 rounded">출력</a><?php if($canCancel){?><form method="post" action="?r=approval_cancel"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token());?>"><input type="hidden" name="id" value="<?php echo $id;?>"><button class="px-3 py-2 bg-rose-100 rounded">요청취소</button></form><?php }?></div>
<?php if($d['doc_status']==='REJECTED'){?><div class="bg-rose-50 border border-rose-200 rounded-2xl p-4">반려단계: <?php echo h($d['rejected_step']);?> / 반려일시: <?php echo h($d['updated_at']);?> / 반려사유: <?php echo h($d['reject_reason']);?></div><?php } ?>
<?php if($d['doc_type']==='leave'){ render_approval_leave_document($content,$lines,'view',array()); } else { render_approval_proposal_document($content,$lines,'view',$filesByType); } ?>
<?php if($d['doc_type']==='leave'){ $dd=$pdo->prepare("SELECT * FROM cpms_approval_leave_deductions WHERE document_id=:id LIMIT 1"); $dd->execute(array(':id'=>$id)); $dr=$dd->fetch(); ?><div class="bg-white rounded-2xl border p-4 mt-3"><h3>휴가 차감</h3><?php if($dr){ ?><div>휴가 차감 완료</div><div>종류: <?php echo h($dr['leave_type']);?> / 차감: <?php echo h((string)$dr['deduct_amount']);?></div><div>차감 전: <?php echo h((string)$dr['balance_before']);?> / 차감 후: <?php echo h((string)$dr['balance_after']);?></div><div>차감일시: <?php echo h($dr['deducted_at']);?></div><?php if((float)$dr['balance_after']<0){?><div class="text-rose-600">상태: 잔여 부족분 발생</div><?php } ?><div class="text-sm text-gray-600 mt-2"><?php echo h($dr['note']);?></div><?php } else { $hm=$pdo->prepare("SELECT id FROM cpms_approval_logs WHERE document_id=:id AND action_type='LEAVE_DEDUCT_SKIP' LIMIT 1"); $hm->execute(array(':id'=>$id)); if($hm->fetchColumn()){ ?><div>입사일이 없어 자동 차감하지 못했습니다.</div><?php } else if($d['doc_status']==='APPROVED'){ ?><div>이 휴가 종류는 자동 차감 대상이 아닙니다.</div><?php } else { ?><div>휴가 차감: 최종 승인 시 차감 예정</div><?php } } ?><div class="text-sm text-gray-500 mt-2">1년 미만 직원: 월차에서 차감됩니다. 1년 이상 직원: 연차에서 차감됩니다. 반차는 현재 기준에 따라 월차 또는 연차에서 0.5개 차감됩니다.</div></div><?php } ?>
</div>