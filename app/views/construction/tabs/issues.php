<?php
/**
 * C:\www\cpms\app\views\construction\tabs\issues.php
 * - 공사: 이슈 탭
 *
 * 설명:
 * - 일정 지연/공사중지/자재 미입고 등 이슈가 생기면 "이슈등록"으로 임원/공무에 공유
 * - 이 탭은 기존 프로젝트 이슈 테이블(cpms_project_issues)을 그대로 보여줌
 *
 * 사용 변수:
 * - $pdo (PDO)
 * - $pid (int)
 */

// 프로젝트 이슈
$issues = array();
try {
    $st = $pdo->prepare("SELECT * FROM cpms_project_issues WHERE project_id = :pid ORDER BY id DESC LIMIT 50");
    $st->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $st->execute();
    $issues = $st->fetchAll();
} catch (Exception $e) {
    $issues = array();
}

// 이슈 댓글(간단히: 이슈별 최대 10개)
$commentsByIssue = array();
if (count($issues) > 0) {
    try {
        $ids = array();
        foreach ($issues as $it) { $ids[] = (int)$it['id']; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM cpms_project_issue_comments WHERE issue_id IN ($placeholders) ORDER BY id ASC";
        $stC = $pdo->prepare($sql);
        $i = 1;
        foreach ($ids as $id) { $stC->bindValue($i, $id, \PDO::PARAM_INT); $i++; }
        $stC->execute();
        $rows = $stC->fetchAll();
        foreach ($rows as $r) {
            $iid = isset($r['issue_id']) ? (int)$r['issue_id'] : 0;
            if ($iid <= 0) continue;
            if (!isset($commentsByIssue[$iid])) $commentsByIssue[$iid] = array();
            $commentsByIssue[$iid][] = $r;
        }
    } catch (Exception $e) {
        $commentsByIssue = array();
    }
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">이슈</h3>
            <div class="text-sm text-gray-600 mt-1">공정표 변경/기간 연장/공사중지 등 이슈를 등록해 공유합니다.</div>
        </div>
        <button type="button" class="px-4 py-2 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 font-extrabold hover:bg-rose-100" data-modal-open="issueAdd">
            이슈등록
        </button>
    </div>

    <?php if (count($issues) === 0): ?>
        <div class="text-sm text-gray-600">등록된 이슈가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($issues as $it): ?>
                <?php
                $stt = isset($it['status']) ? (string)$it['status'] : '처리중';
                $badge = ($stt === '처리완료') ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                       : 'bg-blue-50 text-blue-700 border-blue-100';
                ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-extrabold text-gray-900"><?php echo h($it['reason']); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                등록: <?php echo h($it['created_by_name']); ?> · <?php echo h($it['created_at']); ?>
                            </div>
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>
                    </div>

                    <?php $iid = (int)$it['id']; ?>
                    <?php if (isset($commentsByIssue[$iid]) && count($commentsByIssue[$iid]) > 0): ?>
                        <div class="mt-3 p-3 rounded-2xl bg-gray-50 border border-gray-100">
                            <div class="text-xs font-bold text-gray-600 mb-2">댓글</div>
                            <div class="space-y-2">
                                <?php $cnt = 0; foreach ($commentsByIssue[$iid] as $c): $cnt++; if ($cnt > 10) break; ?>
                                    <div class="text-sm text-gray-800">
                                        <b><?php echo h($c['created_by_name']); ?></b>
                                        <span class="text-xs text-gray-500">(<?php echo h($c['created_at']); ?>)</span>
                                        <div class="whitespace-pre-line"><?php echo h($c['comment_text']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 댓글 작성(공사 전용 라우트: 리다이렉트가 공사로) -->
                    <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/issue_comment_create" class="mt-3 flex flex-col md:flex-row md:items-center gap-2">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="issue_id" value="<?php echo (int)$iid; ?>">
                        <input name="comment_text" maxlength="255" required
                               class="flex-1 px-4 py-3 rounded-2xl border border-gray-200"
                               placeholder="댓글(공사/임원/공무)">
                        <button type="submit" class="px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">댓글 등록</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="mt-4 text-xs text-gray-500">* 이슈 상태 변경은 기존 로직(임원/등록자)에서 처리됩니다.</div>
</div>
