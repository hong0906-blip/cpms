<?php
/**
 * C:\www\cpms\app\views\construction\tabs\safety.php
 * - 공사: 안전사고 탭(프로젝트별)
 *
 * 사용 변수:
 * - $pdo (PDO)
 * - $pid (int)
 * - $canEdit (bool)
 */

$canEditSafety = isset($canEdit) ? (bool)$canEdit : false;

$incidents = array();
try {
    $st = $pdo->prepare("SELECT * FROM cpms_safety_incidents WHERE project_id = :pid ORDER BY id DESC LIMIT 50");
    $st->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $st->execute();
    $incidents = $st->fetchAll();
} catch (Exception $e) {
    $incidents = array();
}
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">안전사고</h3>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($canEditSafety): ?>
                <button type="button" class="px-4 py-2 rounded-2xl bg-rose-600 text-white font-extrabold" data-modal-open="safetyIncidentAdd">안전사고 등록</button>
            <?php endif; ?>
            <a href="<?php echo h(base_url()); ?>/?r=안전/보건" class="px-4 py-2 rounded-2xl bg-gray-100 text-gray-900 font-bold hover:bg-gray-200">안전 탭으로</a>
        </div>
    </div>

    <?php if (count($incidents) === 0): ?>
        <div class="text-sm text-gray-600">등록된 안전사고가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($incidents as $it): ?>
                <?php
                $stt = isset($it['status']) ? (string)$it['status'] : '접수';
                $badge = ($stt === '처리완료') ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                       : (($stt === '처리중') ? 'bg-blue-50 text-blue-700 border-blue-100'
                       : 'bg-rose-50 text-rose-700 border-rose-100');
                ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-extrabold text-gray-900 truncate"><?php echo h($it['title']); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                등록: <?php echo h($it['created_by_name']); ?> · <?php echo h($it['created_at']); ?>
                                <?php if (!empty($it['occurred_at'])): ?> · 발생: <?php echo h($it['occurred_at']); ?><?php endif; ?>
                            </div>
                            <?php if (!empty($it['description'])): ?>
                                <div class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?php echo h($it['description']); ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>
                    </div>
                    <?php if ($canEditSafety): ?>
                        <div class="cpms-construction-mobile-card-actions">
                            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/safety_incident_action_save" class="space-y-2">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="incident_id" value="<?php echo (int)$it['id']; ?>">
                                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                <input type="hidden" name="redirect" value="construction">
                                <label class="block text-xs font-extrabold text-gray-500">상태 수정</label>
                                <select name="status" class="px-4 py-3 rounded-2xl border border-gray-200">
                                    <option value="접수" <?php echo ($stt === '접수') ? 'selected' : ''; ?>>접수</option>
                                    <option value="처리중" <?php echo ($stt === '처리중') ? 'selected' : ''; ?>>처리중</option>
                                    <option value="처리완료" <?php echo ($stt === '처리완료') ? 'selected' : ''; ?>>처리완료</option>
                                </select>
                                <label class="block text-xs font-extrabold text-gray-500">댓글/후속조치</label>
                                <textarea name="action_note" rows="3" class="px-4 py-3 rounded-2xl border border-gray-200" placeholder="댓글 또는 후속조치를 입력하세요."><?php echo h(isset($it['action_note']) ? $it['action_note'] : ''); ?></textarea>
                                <button type="submit" class="px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">수정 저장</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
