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
if (!cpms_progress_statement_schema_ready($pdo)) {
    echo '<div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900 font-bold">기성내역서 DB 설치가 필요합니다.';
    if (\App\Core\Auth::isMaster()) echo ' <a class="underline" href="' . h(base_url()) . '/db_setup_progress_statements.php">설치·점검 페이지</a>';
    echo '</div>';
    return;
}

$psProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$psYm = isset($_GET['target_ym']) ? trim((string)$_GET['target_ym']) : '';
$psRound = isset($_GET['progress_round']) ? (int)$_GET['progress_round'] : 0;
$psSubmitter = isset($_GET['submitter']) ? trim((string)$_GET['submitter']) : '';
$psDateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$psDateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$psFocusId = isset($_GET['statement_id']) ? (int)$_GET['statement_id'] : 0;
$psCardFilter = isset($_GET['ps_card']) ? trim((string)$_GET['ps_card']) : 'pending';
if (!in_array($psCardFilter, array('pending','resubmitted','month_approved','rejected','drive_failed'), true)) $psCardFilter = 'pending';

$psProjects = array();
try {
    $psProjectSt = $pdo->query("SELECT id,name FROM cpms_projects ORDER BY name ASC,id ASC");
    $psProjects = $psProjectSt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($psProjects)) $psProjects = array();
} catch (Exception $e) { $psProjects = array(); }

$psWhere = array('1=1');
$psParams = array();
if ($psProjectId > 0) { $psWhere[] = 's.project_id=:project_id'; $psParams[':project_id'] = $psProjectId; }
if (preg_match('/^(\d{4})-(\d{2})$/', $psYm, $psMatch)) {
    $psWhere[] = 's.target_year=:target_year AND s.target_month=:target_month';
    $psParams[':target_year'] = (int)$psMatch[1]; $psParams[':target_month'] = (int)$psMatch[2];
}
if ($psRound > 0) { $psWhere[] = 's.progress_round=:progress_round'; $psParams[':progress_round'] = $psRound; }
if ($psSubmitter !== '') { $psWhere[] = 's.submitted_by_name LIKE :submitter'; $psParams[':submitter'] = '%' . $psSubmitter . '%'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $psDateFrom)) { $psWhere[] = 's.submitted_at>=:date_from'; $psParams[':date_from'] = $psDateFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $psDateTo)) { $psWhere[] = 's.submitted_at<=:date_to'; $psParams[':date_to'] = $psDateTo . ' 23:59:59'; }

$psRows = array();
try {
    $psSql = "SELECT s.*,p.name project_name,f.id current_file_id,f.original_file_name,f.version_no,
        (SELECT COUNT(*) FROM cpms_progress_statement_comments c WHERE c.statement_id=s.id) comment_count,
        IF(s.status='approved' AND s.approved_at>=DATE_FORMAT(NOW(),'%Y-%m-01'),1,0) is_month_approved
        FROM cpms_progress_statements s
        JOIN cpms_projects p ON p.id=s.project_id
        LEFT JOIN cpms_progress_statement_files f ON f.id=s.latest_file_id
        WHERE " . implode(' AND ', $psWhere) . "
        ORDER BY FIELD(s.status,'pending','resubmitted','rejected','approved'),s.submitted_at DESC LIMIT 500";
    $psSt = $pdo->prepare($psSql);
    $psSt->execute($psParams);
    $psRows = $psSt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($psRows)) $psRows = array();
} catch (Exception $e) { $psRows = array(); }

$psStatementIds = array();
foreach ($psRows as $psMapRow) $psStatementIds[] = (int)$psMapRow['id'];
$psRelated = cpms_progress_statement_related_maps($pdo, $psStatementIds);

$psSummary = array('pending'=>0,'resubmitted'=>0,'month_approved'=>0,'rejected'=>0,'drive_failed'=>0);
try {
    $psSum = $pdo->query("SELECT SUM(status='pending') pending_count,SUM(status='resubmitted') resubmitted_count,
        SUM(status='approved' AND approved_at>=DATE_FORMAT(NOW(),'%Y-%m-01')) month_approved_count,
        SUM(status='rejected') rejected_count,SUM(drive_upload_status='failed') drive_failed_count
        FROM cpms_progress_statements")->fetch(PDO::FETCH_ASSOC);
    if (is_array($psSum)) {
        $psSummary = array('pending'=>(int)$psSum['pending_count'],'resubmitted'=>(int)$psSum['resubmitted_count'],
            'month_approved'=>(int)$psSum['month_approved_count'],'rejected'=>(int)$psSum['rejected_count'],
            'drive_failed'=>(int)$psSum['drive_failed_count']);
    }
} catch (Exception $e) {}

$psCurrentParams = $_GET;
$psCurrentParams['r'] = '공무';
$psCurrentParams['tab'] = 'progress_statement_review';
$psCurrentUrl = '?' . http_build_query($psCurrentParams, '', '&');
$psCards = array(
    array('검토대기','pending','bg-blue-50 text-blue-800 border-blue-200'),
    array('재검토대기','resubmitted','bg-orange-50 text-orange-800 border-orange-200'),
    array('이번 달 승인','month_approved','bg-emerald-50 text-emerald-800 border-emerald-200'),
    array('반려','rejected','bg-red-50 text-red-800 border-red-200'),
    array('Drive 저장 실패','drive_failed','bg-red-100 text-red-900 border-red-300')
);
?>
<style>
.cpms-ps-card { transition:transform .18s ease,box-shadow .18s ease,opacity .18s ease; }
.cpms-ps-card[aria-pressed="true"] { transform:translateY(-3px); box-shadow:0 12px 25px rgba(15,23,42,.13); outline:3px solid rgba(37,99,235,.16); }
.cpms-ps-review-item { transition:opacity .18s ease,transform .18s ease; }
.cpms-ps-review-item.cpms-ps-enter { opacity:0; transform:translateY(10px); }
</style>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5" id="cpmsPsCards">
<?php foreach ($psCards as $psCard): ?>
<button type="button" data-ps-card="<?php echo h($psCard[1]); ?>" aria-pressed="<?php echo $psFocusId<=0 && $psCardFilter===$psCard[1]?'true':'false'; ?>" class="cpms-ps-card rounded-2xl border p-4 text-left <?php echo h($psCard[2]); ?>">
    <div class="text-sm font-bold"><?php echo h($psCard[0]); ?></div>
    <div class="text-2xl font-extrabold"><?php echo (int)$psSummary[$psCard[1]]; ?>건</div>
</button>
<?php endforeach; ?>
</div>

<form method="get" class="bg-white rounded-3xl border p-4 mb-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-2">
    <input type="hidden" name="r" value="공무"><input type="hidden" name="tab" value="progress_statement_review">
    <input type="hidden" name="ps_card" id="cpmsPsCardInput" value="<?php echo h($psCardFilter); ?>">
    <select name="project_id" class="px-3 py-2 rounded-xl border bg-white">
        <option value="0">전체 현장</option>
        <?php foreach ($psProjects as $psProject): ?><option value="<?php echo (int)$psProject['id']; ?>" <?php echo $psProjectId===(int)$psProject['id']?'selected':''; ?>><?php echo h($psProject['name']); ?></option><?php endforeach; ?>
    </select>
    <input type="month" name="target_ym" value="<?php echo h($psYm); ?>" class="px-3 py-2 rounded-xl border">
    <input type="number" name="progress_round" value="<?php echo $psRound>0?(int)$psRound:''; ?>" placeholder="기성 차수" class="px-3 py-2 rounded-xl border">
    <input name="submitter" value="<?php echo h($psSubmitter); ?>" placeholder="제출자" class="px-3 py-2 rounded-xl border">
    <input type="date" name="date_from" value="<?php echo h($psDateFrom); ?>" class="px-3 py-2 rounded-xl border">
    <input type="date" name="date_to" value="<?php echo h($psDateTo); ?>" class="px-3 py-2 rounded-xl border">
    <button class="md:col-span-2 lg:col-span-6 px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">검색조건 적용</button>
</form>

<div id="cpmsPsReviewList" class="space-y-4" data-initial-card="<?php echo h($psCardFilter); ?>" data-focus-id="<?php echo (int)$psFocusId; ?>">
    <div id="cpmsPsEmpty" class="hidden rounded-2xl border bg-white p-6 text-gray-500">선택한 상태에 해당하는 기성내역서가 없습니다.</div>
<?php foreach ($psRows as $psRow):
    $psStatementId = (int)$psRow['id'];
    $psFiles = isset($psRelated['files'][$psStatementId]) ? $psRelated['files'][$psStatementId] : array();
    $psComments = isset($psRelated['comments'][$psStatementId]) ? $psRelated['comments'][$psStatementId] : array();
    $psHistories = isset($psRelated['histories'][$psStatementId]) ? $psRelated['histories'][$psStatementId] : array();
?>
<article id="statement-<?php echo $psStatementId; ?>" class="cpms-ps-review-item bg-white rounded-3xl border p-5 shadow-sm"
    data-status="<?php echo h($psRow['status']); ?>" data-drive-status="<?php echo h($psRow['drive_upload_status']); ?>" data-month-approved="<?php echo (int)$psRow['is_month_approved']; ?>">
    <div class="flex flex-col md:flex-row md:justify-between gap-3">
        <div><div class="flex flex-wrap gap-2"><span class="px-3 py-1 rounded-full border text-xs font-extrabold <?php echo h(cpms_progress_statement_status_class($psRow['status'])); ?>"><?php echo h(cpms_progress_statement_status_label($psRow['status'])); ?></span><?php if($psRow['drive_upload_status']==='failed'):?><span class="px-3 py-1 rounded-full bg-red-800 text-white text-xs font-extrabold">Drive 저장 실패</span><?php endif;?></div>
        <h3 class="mt-2 text-lg font-extrabold"><?php echo h($psRow['project_name']); ?> · <?php echo h($psRow['title']); ?></h3>
        <div class="text-sm text-gray-500"><?php echo (int)$psRow['target_year']; ?>년 <?php echo (int)$psRow['target_month']; ?>월 · <?php echo (int)$psRow['progress_round']; ?>차 · <?php echo h($psRow['submitted_by_name']); ?> · <?php echo h($psRow['submitted_at']); ?></div></div>
        <a class="self-start px-4 py-2 rounded-2xl border font-bold" data-cpms-no-loading="1" download href="?r=project/progress_statement_file&file_id=<?php echo (int)$psRow['current_file_id']; ?>">현재 파일</a>
    </div>
    <?php if(trim((string)$psRow['submit_message'])!==''):?><div class="mt-3 rounded-2xl bg-gray-50 p-3 whitespace-pre-line text-sm"><b>전달사항</b><br><?php echo h($psRow['submit_message']); ?></div><?php endif;?>
    <?php if($psRow['status']==='rejected'):?><div class="mt-3 rounded-2xl border border-red-200 bg-red-50 p-3 text-red-800 whitespace-pre-line"><b>반려사유</b><br><?php echo h($psRow['reject_reason']); ?></div><?php endif;?>
    <?php if($psRow['drive_upload_status']==='failed'):?><div class="mt-3 rounded-2xl border border-red-300 bg-red-50 p-3 text-red-900 break-words"><b>Drive 저장 실패</b><br><?php echo h($psRow['drive_error_message']); ?><?php if(cpms_progress_statement_can_review()):?><form method="post" action="?r=project/progress_statement_drive_retry" class="mt-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo $psStatementId; ?>"><input type="hidden" name="return_url" value="<?php echo h($psCurrentUrl); ?>"><button class="px-3 py-2 rounded-xl bg-red-800 text-white font-bold">Drive 다시 업로드</button></form><?php endif;?></div><?php endif;?>
    <?php if($psRow['drive_upload_status']==='uploaded'&&trim((string)$psRow['drive_web_view_link'])!==''):?><a href="<?php echo h($psRow['drive_web_view_link']); ?>" target="_blank" rel="noopener" class="inline-flex mt-3 px-4 py-2 rounded-2xl bg-emerald-50 text-emerald-800 border border-emerald-200 font-bold">Google Drive에서 보기</a><?php endif;?>
    <?php if(in_array($psRow['status'],array('pending','resubmitted'),true)&&cpms_progress_statement_can_review()):?>
    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
        <form method="post" action="?r=project/progress_statement_action"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo $psStatementId; ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_url" value="<?php echo h($psCurrentUrl); ?>"><button class="w-full px-4 py-3 rounded-2xl bg-emerald-600 text-white font-extrabold">승인</button></form>
        <form method="post" action="?r=project/progress_statement_action" class="flex flex-col sm:flex-row gap-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo $psStatementId; ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_url" value="<?php echo h($psCurrentUrl); ?>"><input name="reject_reason" required placeholder="반려사유 필수" class="flex-1 px-3 py-3 rounded-2xl border border-red-200"><button class="px-4 py-3 rounded-2xl bg-red-600 text-white font-extrabold">반려</button></form>
    </div><?php endif;?>
    <details class="mt-4 rounded-2xl border p-4" <?php echo $psFocusId===$psStatementId?'open':'';?>>
        <summary class="font-extrabold cursor-pointer">상세 · 파일 <?php echo count($psFiles); ?>개 · 댓글 <?php echo count($psComments); ?>개 · 이력 <?php echo count($psHistories); ?>개</summary>
        <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4 text-sm">
            <div><b>전체 파일</b><?php foreach($psFiles as $psFile):?><a class="block mt-2 text-blue-700 break-all" data-cpms-no-loading="1" download href="?r=project/progress_statement_file&file_id=<?php echo (int)$psFile['id']; ?>">v<?php echo (int)$psFile['version_no']; ?> <?php echo h($psFile['original_file_name']); ?> · <?php echo h($psFile['uploaded_at']); ?></a><?php endforeach;?></div>
            <div class="lg:col-span-2"><b>댓글</b><div class="mt-2"><?php cpms_progress_statement_render_comments($psComments,$psStatementId,$psCurrentUrl); ?></div></div>
            <div><b>처리이력</b><?php cpms_progress_statement_render_histories($psHistories); ?></div>
        </div>
        <form method="post" action="?r=project/progress_statement_comment_save" class="mt-4 flex flex-col md:flex-row gap-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="statement_id" value="<?php echo $psStatementId; ?>"><input type="hidden" name="parent_comment_id" value="0"><input type="hidden" name="return_url" value="<?php echo h($psCurrentUrl); ?>"><textarea name="comment_text" required maxlength="2000" rows="2" class="flex-1 px-3 py-2 rounded-2xl border" placeholder="댓글 입력"></textarea><button class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">댓글 등록</button></form>
    </details>
</article>
<?php endforeach; ?>
</div>

<script>
(function(){
    var list = document.getElementById('cpmsPsReviewList');
    if (!list) return;
    var cards = document.querySelectorAll('[data-ps-card]');
    var items = list.querySelectorAll('.cpms-ps-review-item');
    var empty = document.getElementById('cpmsPsEmpty');
    var input = document.getElementById('cpmsPsCardInput');
    var focusId = parseInt(list.getAttribute('data-focus-id') || '0', 10);
    function matches(item, key) {
        if (key === 'focus') return item.id === 'statement-' + focusId;
        if (key === 'drive_failed') return item.getAttribute('data-drive-status') === 'failed';
        if (key === 'month_approved') return item.getAttribute('data-month-approved') === '1';
        return item.getAttribute('data-status') === key;
    }
    function applyFilter(key, smooth, updateUrl) {
        var visibleCount = 0;
        for (var i=0;i<items.length;i++) {
            var show = matches(items[i], key);
            if (show) {
                visibleCount++;
                items[i].hidden = false;
                if (smooth) {
                    items[i].classList.add('cpms-ps-enter');
                    (function(item){ window.setTimeout(function(){ item.classList.remove('cpms-ps-enter'); }, 20); })(items[i]);
                } else {
                    items[i].classList.remove('cpms-ps-enter');
                }
            } else {
                items[i].hidden = true;
            }
        }
        if (empty) empty.classList.toggle('hidden', visibleCount !== 0);
        for (var c=0;c<cards.length;c++) cards[c].setAttribute('aria-pressed', cards[c].getAttribute('data-ps-card') === key ? 'true' : 'false');
        if (input && key !== 'focus') input.value = key;
        if (updateUrl && window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('ps_card', key);
            url.searchParams.delete('statement_id');
            window.history.replaceState({}, '', url.toString());
            var returnInputs = document.querySelectorAll('input[name="return_url"]');
            for (var r=0;r<returnInputs.length;r++) returnInputs[r].value = url.search;
        }
        if (smooth && list.scrollIntoView) list.scrollIntoView({behavior:'smooth',block:'start'});
    }
    for (var c=0;c<cards.length;c++) {
        cards[c].addEventListener('click', function(){ focusId=0; applyFilter(this.getAttribute('data-ps-card'), true, true); });
    }
    if (focusId > 0 && document.getElementById('statement-' + focusId)) {
        applyFilter('focus', false, false);
        window.setTimeout(function(){ document.getElementById('statement-' + focusId).scrollIntoView({behavior:'smooth',block:'start'}); }, 80);
    } else {
        applyFilter(list.getAttribute('data-initial-card') || 'pending', false, false);
    }
})();
</script>
