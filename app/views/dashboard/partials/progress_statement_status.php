<?php
/**
 * C:\www\cpms\app\views\dashboard\partials\progress_statement_status.php
 * 임원 대시보드 전체 현장 기성내역서 상태 조회 탭.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../../services/ProgressStatementService.php';
$epsReady = cpms_progress_statement_schema_ready($pdo);
if (!$epsReady) {
    echo '<div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900">기성내역서 DB가 아직 설치되지 않았습니다.</div>';
    return;
}
$epsStatus=isset($_GET['ps_status'])?trim((string)$_GET['ps_status']):'';
$epsProject=isset($_GET['ps_project'])?trim((string)$_GET['ps_project']):'';
$epsWhere=array('1=1');$epsParams=array();
if(in_array($epsStatus,array('pending','resubmitted','approved','rejected','drive_failed'),true)){
    if($epsStatus==='drive_failed')$epsWhere[]="s.drive_upload_status='failed'";
    else{$epsWhere[]='s.status=:status';$epsParams[':status']=$epsStatus;}
}
if($epsProject!==''){$epsWhere[]='p.name LIKE :project';$epsParams[':project']='%'.$epsProject.'%';}
$epsRows=array();$epsSummary=array('pending'=>0,'resubmitted'=>0,'approved_month'=>0,'rejected'=>0,'drive_failed'=>0);
try{
    $epsSt=$pdo->prepare("SELECT s.*,p.name project_name,
      DATEDIFF(NOW(),s.submitted_at) waiting_days,
      (SELECT comment_text FROM cpms_progress_statement_comments c WHERE c.statement_id=s.id ORDER BY c.created_at DESC,c.id DESC LIMIT 1) latest_comment
      FROM cpms_progress_statements s JOIN cpms_projects p ON p.id=s.project_id WHERE ".implode(' AND ',$epsWhere)." ORDER BY s.submitted_at DESC LIMIT 500");
    $epsSt->execute($epsParams);$epsRows=$epsSt->fetchAll(PDO::FETCH_ASSOC);if(!is_array($epsRows))$epsRows=array();
    $epsSum=$pdo->query("SELECT SUM(status='pending') pending_count,SUM(status='resubmitted') resubmitted_count,
      SUM(status='approved' AND approved_at>=DATE_FORMAT(NOW(),'%Y-%m-01')) approved_month_count,
      SUM(status='rejected') rejected_count,SUM(drive_upload_status='failed') drive_failed_count FROM cpms_progress_statements")->fetch(PDO::FETCH_ASSOC);
    if(is_array($epsSum))$epsSummary=array('pending'=>(int)$epsSum['pending_count'],'resubmitted'=>(int)$epsSum['resubmitted_count'],'approved_month'=>(int)$epsSum['approved_month_count'],'rejected'=>(int)$epsSum['rejected_count'],'drive_failed'=>(int)$epsSum['drive_failed_count']);
}catch(Exception $e){$epsRows=array();}
?>
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
<?php foreach(array(array('전체 검토대기','pending','bg-blue-50 text-blue-800'),array('전체 재검토대기','resubmitted','bg-orange-50 text-orange-800'),array('이번 달 승인','approved_month','bg-emerald-50 text-emerald-800'),array('현재 반려','rejected','bg-red-50 text-red-800'),array('Drive 저장 실패','drive_failed','bg-red-100 text-red-900')) as $epsCard):?>
<div class="rounded-2xl border p-4 <?php echo h($epsCard[2]); ?>"><div class="text-sm font-bold"><?php echo h($epsCard[0]); ?></div><div class="text-2xl font-extrabold"><?php echo (int)$epsSummary[$epsCard[1]]; ?>건</div></div><?php endforeach;?></div>
<form method="get" class="rounded-3xl border bg-white p-4 mb-5 flex flex-col md:flex-row gap-2"><input type="hidden" name="r" value="dashboard_executive"><input type="hidden" name="exec_tab" value="progressStatements"><input name="ps_project" value="<?php echo h($epsProject); ?>" placeholder="현장명 검색" class="flex-1 px-3 py-2 rounded-xl border"><select name="ps_status" class="px-3 py-2 rounded-xl border bg-white"><option value="">전체 상태</option><?php foreach(array('pending','resubmitted','approved','rejected','drive_failed') as $epsOpt):?><option value="<?php echo h($epsOpt); ?>" <?php echo $epsStatus===$epsOpt?'selected':''; ?>><?php echo h($epsOpt==='drive_failed'?'Drive 저장 실패':cpms_progress_statement_status_label($epsOpt)); ?></option><?php endforeach;?></select><button class="px-4 py-2 rounded-xl bg-gray-900 text-white font-extrabold">검색</button></form>
<div class="bg-white rounded-3xl border overflow-hidden"><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50"><tr><th class="p-3 text-left">현장명</th><th class="p-3">기성연월/차수</th><th class="p-3">제출자/최근 제출</th><th class="p-3">상태</th><th class="p-3">대기일수</th><th class="p-3">최근 처리자</th><th class="p-3">최근 댓글</th><th class="p-3">Drive</th><th class="p-3">상세</th></tr></thead><tbody>
<?php if(count($epsRows)===0):?><tr><td colspan="9" class="p-6 text-center text-gray-500">표시할 기성내역서가 없습니다.</td></tr><?php endif;?>
<?php foreach($epsRows as $epsRow):$epsWaiting=in_array($epsRow['status'],array('pending','resubmitted'),true)?max(0,(int)$epsRow['waiting_days']):null;$epsWaitClass=$epsWaiting!==null&&$epsWaiting>=4?'bg-red-100 text-red-800':($epsWaiting!==null&&$epsWaiting>=2?'bg-amber-100 text-amber-800':'bg-gray-100 text-gray-700');?>
<tr class="border-t <?php echo $epsWaiting!==null&&$epsWaiting>=4?'bg-red-50/50':''; ?>"><td class="p-3 font-bold whitespace-nowrap"><?php echo h($epsRow['project_name']); ?></td><td class="p-3 text-center whitespace-nowrap"><?php echo (int)$epsRow['target_year']; ?>-<?php echo sprintf('%02d',(int)$epsRow['target_month']); ?><br><?php echo (int)$epsRow['progress_round']; ?>차</td><td class="p-3 text-center whitespace-nowrap"><?php echo h($epsRow['submitted_by_name']); ?><br><span class="text-xs text-gray-500"><?php echo h($epsRow['submitted_at']); ?></span></td><td class="p-3 text-center"><span class="inline-flex px-2 py-1 rounded-full border text-xs font-bold <?php echo h(cpms_progress_statement_status_class($epsRow['status'])); ?>"><?php echo h(cpms_progress_statement_status_label($epsRow['status'])); ?></span></td><td class="p-3 text-center"><?php if($epsWaiting===null):?>-<?php else:?><span class="px-2 py-1 rounded-full font-bold <?php echo h($epsWaitClass); ?>"><?php echo $epsWaiting; ?>일</span><?php endif;?></td><td class="p-3 text-center"><?php echo h($epsRow['reviewed_by_name']?$epsRow['reviewed_by_name']:'-'); ?></td><td class="p-3 max-w-xs truncate"><?php echo h($epsRow['latest_comment']?$epsRow['latest_comment']:'-'); ?></td><td class="p-3 text-center"><?php echo $epsRow['drive_upload_status']==='uploaded'?'<span class="text-emerald-700 font-bold">완료</span>':($epsRow['drive_upload_status']==='failed'?'<span class="text-red-800 font-extrabold">실패</span>':'-'); ?></td><td class="p-3 text-center"><a class="font-bold text-blue-700" href="?r=공무&tab=progress_statement_review&statement_id=<?php echo (int)$epsRow['id']; ?>">상세보기</a></td></tr><?php endforeach;?></tbody></table></div></div>

