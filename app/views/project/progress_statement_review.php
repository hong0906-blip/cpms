<?php
/**
 * C:\www\cpms\app\views\project\progress_statement_review.php
 * 공무 섹션의 전체 현장 기성내역서 검토·검색·승인·반려 화면.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../services/ProgressStatementService.php';
$psActor = cpms_progress_statement_actor($pdo);
if (!\App\Core\Auth::isMaster() && !cpms_progress_statement_is_public_affairs() && !cpms_progress_statement_is_executive()) {
    http_response_code(403);
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-800 font-bold">기성내역서 전체 검토 화면 접근 권한이 없습니다.</div>';
    return;
}
$psReady = cpms_progress_statement_schema_ready($pdo);
if (!$psReady) {
    echo '<div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900 font-bold">기성내역서 DB 설치가 필요합니다.';
    if (\App\Core\Auth::isMaster()) echo ' <a class="underline" href="' . h(base_url()) . '/db_setup_progress_statements.php">설치·점검 페이지</a>';
    echo '</div>';
    return;
}
$psStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$psProject = isset($_GET['project_name']) ? trim((string)$_GET['project_name']) : '';
$psYm = isset($_GET['target_ym']) ? trim((string)$_GET['target_ym']) : '';
$psRound = isset($_GET['progress_round']) ? (int)$_GET['progress_round'] : 0;
$psSubmitter = isset($_GET['submitter']) ? trim((string)$_GET['submitter']) : '';
$psDateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$psDateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$psWhere=array('1=1'); $psParams=array();
if (in_array($psStatus,array('pending','resubmitted','approved','rejected'),true)) {$psWhere[]='s.status=:status';$psParams[':status']=$psStatus;}
if ($psProject!=='') {$psWhere[]='p.name LIKE :project_name';$psParams[':project_name']='%'.$psProject.'%';}
if (preg_match('/^(\d{4})-(\d{2})$/',$psYm,$psMatch)) {$psWhere[]='s.target_year=:target_year AND s.target_month=:target_month';$psParams[':target_year']=(int)$psMatch[1];$psParams[':target_month']=(int)$psMatch[2];}
if ($psRound>0) {$psWhere[]='s.progress_round=:progress_round';$psParams[':progress_round']=$psRound;}
if ($psSubmitter!=='') {$psWhere[]='s.submitted_by_name LIKE :submitter';$psParams[':submitter']='%'.$psSubmitter.'%';}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$psDateFrom)) {$psWhere[]='s.submitted_at>=:date_from';$psParams[':date_from']=$psDateFrom.' 00:00:00';}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$psDateTo)) {$psWhere[]='s.submitted_at<=:date_to';$psParams[':date_to']=$psDateTo.' 23:59:59';}
$psRows=array();
try {$psSql="SELECT s.*,p.name project_name,f.id current_file_id,f.original_file_name,f.version_no,
 (SELECT COUNT(*) FROM cpms_progress_statement_comments c WHERE c.statement_id=s.id) comment_count
 FROM cpms_progress_statements s JOIN cpms_projects p ON p.id=s.project_id LEFT JOIN cpms_progress_statement_files f ON f.id=s.latest_file_id
 WHERE ".implode(' AND ',$psWhere)." ORDER BY FIELD(s.status,'pending','resubmitted','rejected','approved'),s.submitted_at DESC LIMIT 500";
$psSt=$pdo->prepare($psSql);$psSt->execute($psParams);$psRows=$psSt->fetchAll(PDO::FETCH_ASSOC);if(!is_array($psRows))$psRows=array();}catch(Exception $e){$psRows=array();}
$psSummary=array('pending'=>0,'resubmitted'=>0,'month_approved'=>0,'rejected'=>0,'drive_failed'=>0);
try {$psSum=$pdo->query("SELECT
 SUM(status='pending') pending_count,SUM(status='resubmitted') resubmitted_count,
 SUM(status='approved' AND approved_at>=DATE_FORMAT(NOW(),'%Y-%m-01')) month_approved_count,
 SUM(status='rejected') rejected_count,SUM(drive_upload_status='failed') drive_failed_count FROM cpms_progress_statements")->fetch(PDO::FETCH_ASSOC);
if(is_array($psSum)){$psSummary=array('pending'=>(int)$psSum['pending_count'],'resubmitted'=>(int)$psSum['resubmitted_count'],'month_approved'=>(int)$psSum['month_approved_count'],'rejected'=>(int)$psSum['rejected_count'],'drive_failed'=>(int)$psSum['drive_failed_count']);}}catch(Exception $e){}
$psCurrentUrl='?'.http_build_query($_GET);
?>
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
<?php foreach (array(array('검토대기','pending','bg-blue-50 text-blue-800'),array('재검토대기','resubmitted','bg-orange-50 text-orange-800'),array('이번 달 승인','month_approved','bg-emerald-50 text-emerald-800'),array('반려','rejected','bg-red-50 text-red-800'),array('Drive 저장 실패','drive_failed','bg-red-100 text-red-900')) as $psCard): ?>
<div class="rounded-2xl border p-4 <?php echo h($psCard[2]); ?>"><div class="text-sm font-bold"><?php echo h($psCard[0]); ?></div><div class="text-2xl font-extrabold"><?php echo (int)$psSummary[$psCard[1]]; ?>건</div></div><?php endforeach; ?></div>
<form method="get" class="bg-white rounded-3xl border p-4 mb-5 grid grid-cols-2 lg:grid-cols-7 gap-2">
<input type="hidden" name="r" value="공무"><input type="hidden" name="tab" value="progress_statement_review">
<input name="project_name" value="<?php echo h($psProject); ?>" placeholder="현장명" class="px-3 py-2 rounded-xl border">
<input type="month" name="target_ym" value="<?php echo h($psYm); ?>" class="px-3 py-2 rounded-xl border">
<input type="number" name="progress_round" value="<?php echo $psRound>0?(int)$psRound:''; ?>" placeholder="차수" class="px-3 py-2 rounded-xl border">
<input name="submitter" value="<?php echo h($psSubmitter); ?>" placeholder="제출자" class="px-3 py-2 rounded-xl border">
<select name="status" class="px-3 py-2 rounded-xl border bg-white"><option value="">전체 상태</option><?php foreach(array('pending','resubmitted','approved','rejected') as $psOpt):?><option value="<?php echo h($psOpt); ?>" <?php echo $psStatus===$psOpt?'selected':''; ?>><?php echo h(cpms_progress_statement_status_label($psOpt)); ?></option><?php endforeach;?></select>
<input type="date" name="date_from" value="<?php echo h($psDateFrom); ?>" class="px-3 py-2 rounded-xl border"><input type="date" name="date_to" value="<?php echo h($psDateTo); ?>" class="px-3 py-2 rounded-xl border">
<button class="col-span-2 lg:col-span-7 px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">검색·필터</button></form>
<div class="space-y-4">
<?php if(count($psRows)===0):?><div class="rounded-2xl border bg-white p-6 text-gray-500">조건에 맞는 기성내역서가 없습니다.</div><?php endif;?>
<?php foreach($psRows as $psRow):$psFiles=cpms_progress_statement_files($pdo,$psRow['id']);$psComments=cpms_progress_statement_comments($pdo,$psRow['id']);$psHistories=cpms_progress_statement_histories($pdo,$psRow['id']);?>
<article id="statement-<?php echo (int)$psRow['id']; ?>" class="bg-white rounded-3xl border p-5 shadow-sm">
<div class="flex flex-col md:flex-row md:justify-between gap-3"><div><div class="flex flex-wrap gap-2"><span class="px-3 py-1 rounded-full border text-xs font-extrabold <?php echo h(cpms_progress_statement_status_class($psRow['status'])); ?>"><?php echo h(cpms_progress_statement_status_label($psRow['status'])); ?></span><?php if($psRow['drive_upload_status']==='failed'):?><span class="px-3 py-1 rounded-full bg-red-800 text-white text-xs font-extrabold">Drive 저장 실패</span><?php endif;?></div>
<h3 class="mt-2 text-lg font-extrabold"><?php echo h($psRow['project_name']); ?> · <?php echo h($psRow['title']); ?></h3><div class="text-sm text-gray-500"><?php echo (int)$psRow['target_year']; ?>년 <?php echo (int)$psRow['target_month']; ?>월 · <?php echo (int)$psRow['progress_round']; ?>차 · <?php echo h($psRow['submitted_by_name']); ?> · <?php echo h($psRow['submitted_at']); ?></div></div>
<a class="self-start px-4 py-2 rounded-2xl border font-bold" href="?r=project/progress_statement_file&file_id=<?php echo (int)$psRow['current_file_id']; ?>">현재 파일</a></div>
<?php if(trim((string)$psRow['submit_message'])!==''):?><div class="mt-3 rounded-2xl bg-gray-50 p-3 whitespace-pre-line text-sm"><b>전달사항</b><br><?php echo h($psRow['submit_message']); ?></div><?php endif;?>
<?php if($psRow['status']==='rejected'):?><div class="mt-3 rounded-2xl border border-red-200 bg-red-50 p-3 text-red-800 whitespace-pre-line"><b>반려사유</b><br><?php echo h($psRow['reject_reason']); ?></div><?php endif;?>
<?php if($psRow['drive_upload_status']==='failed'):?><div class="mt-3 rounded-2xl border border-red-300 bg-red-50 p-3 text-red-900 break-words"><b>Drive 저장 실패</b><br><?php echo h($psRow['drive_error_message']); ?><?php if(cpms_progress_statement_can_review()):?><form method="post" action="?r=project/progress_statement_drive_retry" class="mt-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo (int)$psRow['id']; ?>"><input type="hidden" name="return_url" value="<?php echo h($psCurrentUrl); ?>"><button class="px-3 py-2 rounded-xl bg-red-800 text-white font-bold">Drive 다시 업로드</button></form><?php endif;?></div><?php endif;?>
<?php if($psRow['drive_upload_status']==='uploaded'&&trim((string)$psRow['drive_web_view_link'])!==''):?><a href="<?php echo h($psRow['drive_web_view_link']); ?>" target="_blank" rel="noopener" class="inline-flex mt-3 px-4 py-2 rounded-2xl bg-emerald-50 text-emerald-800 border border-emerald-200 font-bold">Google Drive에서 보기</a><?php endif;?>
<?php if(in_array($psRow['status'],array('pending','resubmitted'),true)&&cpms_progress_statement_can_review()):?><div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3"><form method="post" action="?r=project/progress_statement_action"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo (int)$psRow['id']; ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_url" value="<?php echo h($psCurrentUrl); ?>"><button class="w-full px-4 py-3 rounded-2xl bg-emerald-600 text-white font-extrabold">승인</button></form>
<form method="post" action="?r=project/progress_statement_action" class="flex flex-col sm:flex-row gap-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo (int)$psRow['id']; ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_url" value="<?php echo h($psCurrentUrl); ?>"><input name="reject_reason" required placeholder="반려사유 필수" class="flex-1 px-3 py-3 rounded-2xl border border-red-200"><button class="px-4 py-3 rounded-2xl bg-red-600 text-white font-extrabold">반려</button></form></div><?php endif;?>
<details class="mt-4 rounded-2xl border p-4" <?php echo isset($_GET['statement_id'])&&(int)$_GET['statement_id']===(int)$psRow['id']?'open':'';?>><summary class="font-extrabold cursor-pointer">상세 · 파일 <?php echo count($psFiles); ?>개 · 댓글 <?php echo count($psComments); ?>개 · 이력 <?php echo count($psHistories); ?>개</summary>
<div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4 text-sm"><div><b>전체 파일</b><?php foreach($psFiles as $psFile):?><a class="block mt-2 text-blue-700 break-all" href="?r=project/progress_statement_file&file_id=<?php echo (int)$psFile['id']; ?>">v<?php echo (int)$psFile['version_no']; ?> <?php echo h($psFile['original_file_name']); ?> · <?php echo h($psFile['uploaded_at']); ?></a><?php endforeach;?></div>
<div><b>댓글</b><?php foreach($psComments as $psComment):?><div class="mt-2 rounded-xl bg-gray-50 p-2"><span class="text-xs text-gray-500"><?php echo h($psComment['author_name']); ?> · <?php echo h($psComment['created_at']); ?></span><div class="whitespace-pre-line"><?php echo h($psComment['comment_text']); ?></div></div><?php endforeach;?></div>
<div><b>처리이력</b><?php foreach($psHistories as $psHistory):?><div class="mt-2"><b><?php echo h(cpms_progress_statement_event_label($psHistory['event_type'])); ?></b> · <?php echo h($psHistory['actor_name']); ?> · <?php echo h($psHistory['created_at']); ?><div class="text-gray-500 whitespace-pre-line"><?php echo h($psHistory['description']); ?></div></div><?php endforeach;?></div></div>
<form method="post" action="?r=project/progress_statement_comment_save" class="mt-4 flex flex-col md:flex-row gap-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo (int)$psRow['id']; ?>"><input type="hidden" name="return_url" value="<?php echo h($psCurrentUrl); ?>"><textarea name="comment_text" required maxlength="2000" rows="2" class="flex-1 px-3 py-2 rounded-2xl border" placeholder="댓글 입력"></textarea><button class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">댓글 등록</button></form></details></article>
<?php endforeach;?></div>
