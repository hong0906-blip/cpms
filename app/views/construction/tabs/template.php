<?php
/**
 * C:\www\cpms\app\views\construction\tabs\template.php
 * - 공사: 템플릿 탭
 *
 * 요구사항:
 * 1) 공사팀이 템플릿 생성 버튼을 눌러서 생성
 *
 * 사용 변수:
 * - $pdo (PDO)
 * - $pid (int)
 */

use App\Core\Auth;

$role = Auth::userRole();
$dept = Auth::userDepartment();
$canGenerate = ($role === 'executive' || $dept === '공사');

// 템플릿 목록
$tpl = array();
try {
    $st = $pdo->prepare("SELECT * FROM cpms_process_templates WHERE project_id = :pid ORDER BY sort_order ASC, id ASC");
    $st->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $st->execute();
    $tpl = $st->fetchAll();
} catch (Exception $e) {
    $tpl = array();
}

// 단가표 존재 여부
$unitCount = 0;
try {
    $stU = $pdo->prepare("SELECT COUNT(*) FROM cpms_project_unit_prices WHERE project_id = :pid");
    $stU->bindValue(':pid', (int)$pid, \PDO::PARAM_INT);
    $stU->execute();
    $unitCount = (int)$stU->fetchColumn();
} catch (Exception $e) { $unitCount = 0; }
?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">템플릿</h3>
            <div class="text-sm text-gray-600 mt-1">공무에서 넘겨준 단가표(공정)로 공정 템플릿을 자동 생성합니다.</div>
        </div>
        <div class="p-3 bg-gradient-to-br from-yellow-500 to-orange-500 rounded-2xl shadow-lg shadow-yellow-500/30">
            <i data-lucide="layers" class="w-5 h-5 text-white"></i>
        </div>
    </div>

    <div class="flex flex-col md:flex-row md:items-center gap-3 mb-4">
        <div class="text-sm text-gray-700">
            단가표 행 수: <b><?php echo (int)$unitCount; ?></b>
            <?php if ($unitCount === 0): ?>
                <span class="text-rose-700 font-extrabold">(단가표가 없습니다 → 공무에서 업로드 필요)</span>
            <?php endif; ?>
        </div>

        <?php if ($canGenerate): ?>
            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/template_generate" class="md:ml-auto">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                <button type="submit" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">
                    템플릿 생성
                </button>
            </form>
        <?php else: ?>
            <div class="md:ml-auto text-sm text-gray-500">※ 생성 권한 없음(공사/임원만)</div>
        <?php endif; ?>
    </div>

    <?php if (count($tpl) === 0): ?>
        <div class="p-4 rounded-2xl border border-gray-200 bg-gray-50 text-gray-700">
            아직 템플릿이 없습니다. <b>템플릿 생성</b> 버튼을 눌러주세요.
        </div>
        <div class="mt-3 text-xs text-gray-500">
            * 현재 버전은 단가표의 <code>item_name</code> + <code>spec</code>을 "공정"으로 사용합니다.
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <?php foreach ($tpl as $t): ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white">
                    <div class="font-extrabold text-gray-900"><?php echo h($t['process_name']); ?></div>
                    <div class="text-xs text-gray-500 mt-1">정렬: <?php echo (int)$t['sort_order']; ?> · 생성: <?php echo h($t['created_at']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 flex flex-col md:flex-row md:items-center gap-3">
            <div class="text-xs text-gray-500">* 다음 단계: 템플릿을 기반으로 공정표(간트) 초안을 생성합니다.</div>

            <?php if ($canGenerate): ?>
                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/schedule_seed_from_template" class="md:ml-auto">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <button type="submit" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-extrabold shadow-lg">
                        공정표 초안 생성(템플릿 → 태스크)
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
