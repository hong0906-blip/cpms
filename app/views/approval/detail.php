<?php
use App\Core\Db; require_once __DIR__.'/_common.php'; require_once __DIR__.'/document_templates.php'; require_once __DIR__.'/template_style.php'; require_once __DIR__.'/template_proposal.php'; require_once __DIR__.'/template_leave.php';
$pdo=Db::pdo(); $id=isset($_GET['id'])?(int)$_GET['id']:0; $u=\App\Core\Auth::user();
$st=$pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1"); $st->execute(array(':id'=>$id)); $d=$st->fetch();
$st=$pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:id ORDER BY line_order"); $st->execute(array(':id'=>$id)); $lines=$st->fetchAll();
$content=approval_parse_content($d['content']); $canCancel=((int)$d['created_by_id']===(int)$u['id'] && in_array($d['doc_status'],array('PENDING','DRAFT')));
?><div class="space-y-4"><div class="no-print flex gap-2"><a href="javascript:history.back()" class="px-3 py-2 bg-gray-100 rounded">뒤로가기</a><a href="?r=approval_home" class="px-3 py-2 bg-gray-100 rounded">목록으로</a><a href="?r=approval_print&id=<?php echo $id;?>" class="px-3 py-2 bg-indigo-100 rounded">출력</a><?php if($canCancel){?><form method="post" action="?r=approval_cancel"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token());?>"><input type="hidden" name="id" value="<?php echo $id;?>"><button class="px-3 py-2 bg-rose-100 rounded">요청취소</button></form><?php }?></div>
<?php if($d['doc_status']==='REJECTED'){?><div class="bg-rose-50 border border-rose-200 rounded-2xl p-4">반려단계: <?php echo h($d['rejected_step']);?> / 반려일시: <?php echo h($d['updated_at']);?> / 반려사유: <?php echo h($d['reject_reason']);?></div><?php } ?>
<?php if($d['doc_type']==='leave'){ render_approval_leave_document($content,$lines,'view'); } else { render_approval_proposal_document($content,$lines,'view'); } ?>
</div>