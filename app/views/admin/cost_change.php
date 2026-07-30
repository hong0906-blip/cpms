<?php
/**
 * 관리 > 비용 변경 관리.
 * 초기설정/기존자료 점검과 전체 변경이력 조회 전용 화면.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/../../services/CostChangeService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;

$costChangePdo = Db::pdo();
$costChangeInstalled = CostChangeService::isInstalled($costChangePdo);
$costChangeApprovers = $costChangeInstalled ? CostChangeService::resolveApprovers($costChangePdo) : array('ok'=>false, 'first'=>null, 'final'=>null, 'message'=>'초기설정 필요');
$firstCandidates = array();
$finalCandidates = array();
if ($costChangePdo) {
    try {
        $st = $costChangePdo->query("SELECT id,name,email,department,position FROM employees WHERE is_active=1 AND name='박원덕' AND position LIKE '%전무%' ORDER BY id ASC");
        $firstCandidates = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        $st = $costChangePdo->query("SELECT id,name,email,department,position FROM employees WHERE is_active=1 AND position LIKE '%부사장%' ORDER BY id ASC");
        $finalCandidates = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $firstCandidates = array();
        $finalCandidates = array();
    }
}
$firstSelected = isset($costChangeApprovers['first']['id']) ? (int)$costChangeApprovers['first']['id'] : 0;
$finalSelected = isset($costChangeApprovers['final']['id']) ? (int)$costChangeApprovers['final']['id'] : 0;
?>

<div class="space-y-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-gray-900">비용 변경 관리</h2>
                <p class="mt-1 text-sm text-gray-500">스키마와 고정 승인자를 웹에서 설정하고 전체 변경이력을 조회합니다. 이 화면에서는 이력을 수정하거나 삭제할 수 없습니다.</p>
            </div>
            <a href="?r=cost_change/manage" class="inline-flex px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-bold">전체 변경이력 조회</a>
        </div>
    </div>

    <div class="rounded-2xl border <?php echo $costChangeInstalled ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'; ?> p-5">
        <div class="font-extrabold <?php echo $costChangeInstalled ? 'text-emerald-800' : 'text-amber-800'; ?>">
            데이터 구조: <?php echo $costChangeInstalled ? '설치됨' : '초기설정 필요'; ?>
        </div>
        <p class="mt-1 text-sm text-gray-700">기존 비용자료는 변경하지 않습니다. 귀속월은 조회 시 날짜 기준으로 계산하며, 승인으로 수동 이동된 건만 별도 메타 기록을 사용합니다.</p>
        <form method="post" action="?r=cost_change/setup" class="mt-3">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="install">
            <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-700 text-white font-bold">구조 확인/초기화 실행</button>
        </form>
    </div>

    <?php if ($costChangeInstalled): ?>
    <div class="rounded-2xl border <?php echo !empty($costChangeApprovers['ok']) ? 'border-emerald-200' : 'border-red-200'; ?> bg-white p-5">
        <h3 class="text-lg font-extrabold">고정 승인선 연결</h3>
        <p class="mt-1 text-sm text-gray-500">이름 문자열로 승인하지 않고 선택한 직원 ID를 요청서에 저장합니다. 1차 승인자는 박원덕 전무 계정만, 최종 승인자는 부사장 직급의 활성 계정만 저장됩니다.</p>
        <form method="post" action="?r=cost_change/setup" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="approvers">
            <label class="block">
                <span class="text-sm font-bold">1차 승인자 · 공사PM 박원덕 전무</span>
                <select name="first_approver_id" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" required>
                    <option value="">직원 계정 선택</option>
                    <?php foreach ($firstCandidates as $employee): ?>
                        <option value="<?php echo (int)$employee['id']; ?>" <?php echo (int)$employee['id'] === $firstSelected ? 'selected' : ''; ?>>
                            <?php echo h($employee['name'] . ' / ' . $employee['department'] . ' / ' . $employee['position'] . ' / ' . $employee['email']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-bold">최종 승인자 · 부사장</span>
                <select name="final_approver_id" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" required>
                    <option value="">직원 계정 선택</option>
                    <?php foreach ($finalCandidates as $employee): ?>
                        <option value="<?php echo (int)$employee['id']; ?>" <?php echo (int)$employee['id'] === $finalSelected ? 'selected' : ''; ?>>
                            <?php echo h($employee['name'] . ' / ' . $employee['department'] . ' / ' . $employee['position'] . ' / ' . $employee['email']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="md:col-span-2 flex flex-wrap items-center gap-3">
                <button type="submit" class="px-4 py-2 rounded-xl bg-blue-700 text-white font-bold">고정 승인선 저장</button>
                <span class="text-sm <?php echo !empty($costChangeApprovers['ok']) ? 'text-emerald-700' : 'text-red-700'; ?> font-bold"><?php echo h($costChangeApprovers['message']); ?></span>
            </div>
        </form>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <h3 class="text-lg font-extrabold">기존자료 귀속월 점검</h3>
        <p class="mt-1 text-sm text-gray-500">기존자료를 일괄 수정하지 않고 대상건수와 계산 기준만 점검합니다. 필요할 때 귀속월 메타를 점진적으로 생성할 수 있습니다.</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <a href="?r=cost_change/setup_preview" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold text-sm">대상건수·예상결과 미리보기</a>
            <form method="post" action="?r=cost_change/setup" onsubmit="return confirm('기존자료의 계산된 귀속월 메타를 생성할까요? 원본 금액과 날짜는 변경하지 않습니다.');">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="backfill">
                <button type="submit" class="px-4 py-2 rounded-xl border border-blue-300 bg-blue-50 text-blue-700 font-bold text-sm">점진적 초기화 실행</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

