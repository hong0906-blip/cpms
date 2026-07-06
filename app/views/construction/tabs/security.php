<?php
/**
 * 공사: 보안사고 탭
 * - 이슈등록과 동일한 양식/댓글/상태 흐름 사용
 * - cpms_project_issues.issue_kind = security 로 분리
 * - PHP 5.6 호환
 */

$canEditSecurityIssue = isset($canEdit) ? (bool)$canEdit : false;

function cpms_construction_security_tab_column_exists($pdo, $table, $column)
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

$securityIssues = array();
try {
    if (cpms_construction_security_tab_column_exists($pdo, 'cpms_project_issues', 'issue_kind')) {
        $st = $pdo->prepare("SELECT * FROM cpms_project_issues WHERE project_id = :pid AND issue_kind = 'security' ORDER BY id DESC LIMIT 50");
        $st->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
        $st->execute();
        $securityIssues = $st->fetchAll();
    }
} catch (Exception $e) {
    $securityIssues = array();
}

$commentsBySecurityIssue = array();
if (count($securityIssues) > 0) {
    try {
        $ids = array();
        foreach ($securityIssues as $it) { $ids[] = (int)$it['id']; }
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
            if (!isset($commentsBySecurityIssue[$iid])) $commentsBySecurityIssue[$iid] = array();
            $commentsBySecurityIssue[$iid][] = $r;
        }
    } catch (Exception $e) {
        $commentsBySecurityIssue = array();
    }
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">보안사고</h3>
        </div>
        <?php if ($canEditSecurityIssue): ?>
            <button type="button" class="px-4 py-2 rounded-2xl bg-slate-50 border border-slate-200 text-slate-800 font-extrabold hover:bg-slate-100" data-modal-open="securityIssueAdd">
                보안사고 등록
            </button>
        <?php endif; ?>
    </div>

    <?php if (count($securityIssues) === 0): ?>
        <div class="text-sm text-gray-600">등록된 보안사고가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($securityIssues as $it): ?>
                <?php
                $stt = isset($it['status']) ? (string)$it['status'] : '접수';
                $badge = ($stt === '처리완료') ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                       : (($stt === '처리중') ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-rose-50 text-rose-700 border-rose-100');
                ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-extrabold text-gray-900"><?php echo h(isset($it['title']) && trim((string)$it['title'])!=='' ? $it['title'] : (isset($it['reason'])?$it['reason']:'-')); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                등록: <?php echo h(isset($it['created_by_name']) ? $it['created_by_name'] : ''); ?> · <?php echo h(isset($it['created_at']) ? $it['created_at'] : ''); ?>
                            </div>
                            <?php if (isset($it['description']) && trim((string)$it['description']) !== ''): ?>
                                <div class="mt-3 text-sm text-gray-700 whitespace-pre-line"><?php echo h($it['description']); ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>
                    </div>

                    <?php $iid = (int)$it['id']; ?>
                    <?php if (isset($commentsBySecurityIssue[$iid]) && count($commentsBySecurityIssue[$iid]) > 0): ?>
                        <div class="mt-3 p-3 rounded-2xl bg-gray-50 border border-gray-100">
                            <div class="text-xs font-bold text-gray-600 mb-2">댓글</div>
                            <div class="space-y-2">
                                <?php $cnt = 0; foreach ($commentsBySecurityIssue[$iid] as $c): $cnt++; if ($cnt > 10) break; ?>
                                    <div class="text-sm text-gray-800">
                                        <b><?php echo h(isset($c['created_by_name']) ? $c['created_by_name'] : ''); ?></b>
                                        <span class="text-xs text-gray-500">(<?php echo h(isset($c['created_at']) ? $c['created_at'] : ''); ?>)</span>
                                        <div class="whitespace-pre-line"><?php echo h(isset($c['comment_text']) ? $c['comment_text'] : ''); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($canEditSecurityIssue): ?>
                        <div class="cpms-construction-mobile-card-actions">
                            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/issue_state_save" class="space-y-2">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="issue_id" value="<?php echo (int)$iid; ?>">
                                <input type="hidden" name="redirect" value="construction_security">
                                <label class="block text-xs font-extrabold text-gray-500">상태 수정</label>
                                <select name="status" class="px-4 py-3 rounded-2xl border border-gray-200">
                                    <option value="접수" <?php echo ($stt === '접수') ? 'selected' : ''; ?>>접수</option>
                                    <option value="처리중" <?php echo ($stt === '처리중') ? 'selected' : ''; ?>>처리중</option>
                                    <option value="처리완료" <?php echo ($stt === '처리완료') ? 'selected' : ''; ?>>처리완료</option>
                                </select>
                                <button type="submit" class="px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">수정 저장</button>
                            </form>
                        </div>

                        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/issue_comment_create" class="mt-3 flex flex-col md:flex-row md:items-center gap-2">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="issue_id" value="<?php echo (int)$iid; ?>">
                            <input type="hidden" name="redirect" value="construction_security">
                            <input name="comment_text" maxlength="255" required
                                   class="flex-1 px-4 py-3 rounded-2xl border border-gray-200"
                                   placeholder="댓글(공사/임원)">
                            <button type="submit" class="px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">댓글 등록</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
