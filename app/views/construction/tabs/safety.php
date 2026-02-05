<?php
/**
 * C:\www\cpms\app\views\construction\tabs\safety.php
 * - 공사: 안전사고 탭(프로젝트별)
 *
 * 사용 변수:
 * - $pdo (PDO)
 * - $pid (int)
 */

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
            <div class="text-sm text-gray-600 mt-1">공사에서 등록한 안전사고를 프로젝트 기준으로 봅니다.</div>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" class="px-4 py-2 rounded-2xl bg-rose-600 text-white font-extrabold" data-modal-open="safetyIncidentAdd">안전사고 등록</button>
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
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="mt-4 text-xs text-gray-500">* 상태 변경은 안전팀/임원이 안전 메뉴에서 처리합니다.</div>
</div>
