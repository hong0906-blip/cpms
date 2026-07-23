<?php
/**
 * C:\www\cpms\app\views\construction\tabs\progress_statement.php
 * 공사 섹션 프로젝트별 기성내역서 제출·재제출·조회 탭.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../../services/ProgressStatementService.php';
$psActor = cpms_progress_statement_actor($pdo);
$psCanSubmit = cpms_progress_statement_can_submit($pdo, $pid, $psActor);
$psRows = array();
$psCounts = array('pending'=>0,'resubmitted'=>0,'approved'=>0,'rejected'=>0);
if (cpms_progress_statement_schema_ready($pdo)) {
    try {
        $psSt = $pdo->prepare("SELECT s.*, f.original_file_name, f.id AS current_file_id, f.version_no,
            (SELECT COUNT(*) FROM cpms_progress_statement_comments c WHERE c.statement_id=s.id) AS comment_count
            FROM cpms_progress_statements s
            LEFT JOIN cpms_progress_statement_files f ON f.id=s.latest_file_id
            WHERE s.project_id=:project_id ORDER BY s.target_year DESC,s.target_month DESC,s.progress_round DESC,s.id DESC");
        $psSt->execute(array(':project_id'=>(int)$pid));
        $psRows = $psSt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($psRows)) $psRows = array();
        foreach ($psRows as $psCountRow) {
            if (isset($psCounts[$psCountRow['status']])) $psCounts[$psCountRow['status']]++;
        }
    } catch (Exception $e) { $psRows = array(); }
}
?>
<?php if (!cpms_progress_statement_schema_ready($pdo)): ?>
<div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
    <div class="font-extrabold">기성내역서 DB 설치가 필요합니다.</div>
    <?php if (\App\Core\Auth::isMaster()): ?><a class="inline-block mt-3 font-bold underline" href="<?php echo h(base_url()); ?>/db_setup_progress_statements.php">웹 설치·점검 페이지 열기</a><?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
<?php
$psSummary = array('pending'=>array('검토대기','bg-blue-50 text-blue-800'),'resubmitted'=>array('재검토대기','bg-orange-50 text-orange-800'),'approved'=>array('승인완료','bg-emerald-50 text-emerald-800'),'rejected'=>array('반려','bg-red-50 text-red-800'));
foreach ($psSummary as $psKey=>$psInfo):
?>
<div class="rounded-2xl border border-gray-100 p-4 <?php echo h($psInfo[1]); ?>"><div class="text-sm font-bold"><?php echo h($psInfo[0]); ?></div><div class="text-2xl font-extrabold mt-1"><?php echo (int)$psCounts[$psKey]; ?>건</div></div>
<?php endforeach; ?>
</div>

<?php if ($psCanSubmit): ?>
<div class="bg-white rounded-3xl border border-gray-100 shadow-lg shadow-gray-200/40 p-5 mb-6">
    <h3 class="text-xl font-extrabold mb-4">기성내역서 제출</h3>
    <form method="post" action="?r=construction/progress_statement_upload" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
        <label class="text-sm font-bold">대상 연도<input type="number" name="target_year" min="2000" max="2100" value="<?php echo h(date('Y')); ?>" required class="mt-1 w-full px-4 py-3 rounded-2xl border"></label>
        <label class="text-sm font-bold">대상 월<select name="target_month" required class="mt-1 w-full px-4 py-3 rounded-2xl border bg-white"><?php for ($psM=1;$psM<=12;$psM++): ?><option value="<?php echo $psM; ?>" <?php echo $psM===(int)date('n')?'selected':''; ?>><?php echo $psM; ?>월</option><?php endfor; ?></select></label>
        <label class="text-sm font-bold">기성 차수<input type="number" name="progress_round" min="1" max="999" required class="mt-1 w-full px-4 py-3 rounded-2xl border"></label>
        <label class="text-sm font-bold">제목<input type="text" name="title" maxlength="200" required class="mt-1 w-full px-4 py-3 rounded-2xl border"></label>
        <label class="text-sm font-bold md:col-span-2 lg:col-span-3">전달사항<textarea name="submit_message" rows="3" class="mt-1 w-full px-4 py-3 rounded-2xl border"></textarea></label>
        <label class="text-sm font-bold">Excel 파일<input type="file" name="statement_file" accept=".xls,.xlsx" required class="mt-1 w-full px-3 py-3 rounded-2xl border bg-white"><span class="block text-xs text-gray-500 mt-1">xls/xlsx, 최대 30MB</span></label>
        <div class="md:col-span-2 lg:col-span-4"><button type="submit" class="w-full md:w-auto px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold">검토 요청</button></div>
    </form>
</div>
<?php else: ?>
<div class="mb-5 rounded-2xl border border-gray-200 bg-gray-50 p-4 text-gray-600">조회는 가능하지만, 제출은 이 프로젝트의 main/sub 담당자만 할 수 있습니다.</div>
<?php endif; ?>

<div class="space-y-4">
<?php if (count($psRows)===0): ?><div class="rounded-2xl border bg-white p-6 text-gray-500">제출된 기성내역서가 없습니다.</div><?php endif; ?>
<?php foreach ($psRows as $psRow): $psFiles=cpms_progress_statement_files($pdo,$psRow['id']); $psComments=cpms_progress_statement_comments($pdo,$psRow['id']); $psHistories=cpms_progress_statement_histories($pdo,$psRow['id']); ?>
<article class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5">
    <div class="flex flex-col md:flex-row md:justify-between gap-3">
        <div class="min-w-0"><div class="flex flex-wrap gap-2 items-center"><span class="px-3 py-1 rounded-full border text-xs font-extrabold <?php echo h(cpms_progress_statement_status_class($psRow['status'])); ?>"><?php echo h(cpms_progress_statement_status_label($psRow['status'])); ?></span>
        <?php if ($psRow['drive_upload_status']==='failed'): ?><span class="px-3 py-1 rounded-full bg-red-800 text-white text-xs font-extrabold">Drive 저장 실패</span><?php endif; ?></div>
        <h3 class="mt-2 text-lg font-extrabold break-words"><?php echo h($psRow['title']); ?></h3>
        <div class="text-sm text-gray-500 mt-1"><?php echo (int)$psRow['target_year']; ?>년 <?php echo (int)$psRow['target_month']; ?>월 · <?php echo (int)$psRow['progress_round']; ?>차 · 제출 <?php echo h($psRow['submitted_at']); ?></div></div>
        <a class="shrink-0 self-start px-4 py-2 rounded-2xl border font-bold" href="?r=project/progress_statement_file&file_id=<?php echo (int)$psRow['current_file_id']; ?>">현재 파일 다운로드</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 text-sm">
        <div class="rounded-2xl bg-gray-50 p-3"><b>현재 파일</b><div class="break-all mt-1"><?php echo h($psRow['original_file_name']); ?> (v<?php echo (int)$psRow['version_no']; ?>)</div></div>
        <div class="rounded-2xl bg-gray-50 p-3"><b>최근 처리</b><div class="mt-1"><?php echo h($psRow['reviewed_by_name']?$psRow['reviewed_by_name']:'-'); ?> · <?php echo h($psRow['reviewed_at']?$psRow['reviewed_at']:'-'); ?></div></div>
        <div class="rounded-2xl bg-gray-50 p-3"><b>댓글</b><div class="mt-1"><?php echo (int)$psRow['comment_count']; ?>개</div></div>
    </div>
    <?php if ($psRow['status']==='rejected'): ?><div class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-red-800"><b>반려사유</b><div class="mt-1 whitespace-pre-line"><?php echo h($psRow['reject_reason']); ?></div></div>
    <?php if ($psCanSubmit): ?><form method="post" action="?r=construction/progress_statement_resubmit" enctype="multipart/form-data" class="mt-4 rounded-2xl border p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo (int)$psRow['id']; ?>"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
        <label class="text-sm font-bold">수정 Excel 파일(필수)<input type="file" name="statement_file" accept=".xls,.xlsx" required class="mt-1 w-full px-3 py-3 rounded-2xl border"></label>
        <label class="text-sm font-bold">전달사항<textarea name="submit_message" rows="2" class="mt-1 w-full px-3 py-3 rounded-2xl border"></textarea></label>
        <button class="md:col-span-2 px-4 py-3 rounded-2xl bg-orange-600 text-white font-extrabold" type="submit">새 파일로 재제출</button>
    </form><?php endif; ?><?php endif; ?>
    <?php if ($psRow['drive_upload_status']==='uploaded' && trim((string)$psRow['drive_web_view_link'])!==''): ?><a href="<?php echo h($psRow['drive_web_view_link']); ?>" target="_blank" rel="noopener" class="inline-flex mt-4 px-4 py-2 rounded-2xl bg-emerald-50 text-emerald-800 border border-emerald-200 font-bold">Google Drive에서 보기</a><?php endif; ?>
    <details class="mt-4 rounded-2xl border p-4"><summary class="font-extrabold cursor-pointer">이전 파일·댓글·처리이력</summary>
        <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4 text-sm">
            <div><b>파일 버전</b><?php foreach ($psFiles as $psFile): ?><a class="block mt-2 text-blue-700 break-all" href="?r=project/progress_statement_file&file_id=<?php echo (int)$psFile['id']; ?>">v<?php echo (int)$psFile['version_no']; ?> <?php echo h($psFile['original_file_name']); ?></a><?php endforeach; ?></div>
            <div><b>댓글</b><?php foreach ($psComments as $psComment): ?><div class="mt-2 rounded-xl bg-gray-50 p-2"><span class="text-xs text-gray-500"><?php echo h($psComment['author_name']); ?> · <?php echo h($psComment['created_at']); ?></span><div class="whitespace-pre-line"><?php echo h($psComment['comment_text']); ?></div></div><?php endforeach; ?></div>
            <div><b>처리이력</b><?php foreach ($psHistories as $psHistory): ?><div class="mt-2"><span class="font-bold"><?php echo h(cpms_progress_statement_event_label($psHistory['event_type'])); ?></span> · <?php echo h($psHistory['actor_name']); ?> · <?php echo h($psHistory['created_at']); ?></div><?php endforeach; ?></div>
        </div>
        <?php if (cpms_progress_statement_can_comment($pdo,$pid,$psActor)): ?><form method="post" action="?r=project/progress_statement_comment_save" class="mt-4 flex flex-col md:flex-row gap-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo (int)$psRow['id']; ?>"><input type="hidden" name="return_url" value="?r=공사&pid=<?php echo (int)$pid; ?>&tab=progress_statement"><textarea name="comment_text" required maxlength="2000" rows="2" class="flex-1 px-3 py-2 rounded-2xl border" placeholder="댓글 입력"></textarea><button class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">댓글 등록</button></form><?php endif; ?>
    </details>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
