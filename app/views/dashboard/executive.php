<?php
/**
 * C:\www\cpms\app\views\dashboard\executive.php
 * - 기존 샘플 카드 유지 + ✅ 이슈 목록/상태처리 추가
 */

require_once __DIR__ . '/../partials/TaskList.php';

use App\Core\Db;

$user = \App\Core\Auth::user();
$pdo = Db::pdo();

// 임원 대시보드 이슈 목록(최근 20)
$issues = [];
if ($pdo) {
    try {
        $sql = "SELECT i.*, p.name AS project_name
                FROM cpms_project_issues i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                ORDER BY i.id DESC
                LIMIT 20";
        $st = $pdo->query($sql);
        $issues = $st->fetchAll();
    } catch (Exception $e) {
        $issues = [];
    }
}

// ✅ 안전사고 목록(최근 10)
$safetyIncidents = [];
if ($pdo) {
    try {
        $sql = "SELECT i.*, p.name AS project_name
                FROM cpms_safety_incidents i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                ORDER BY i.id DESC
                LIMIT 10";
        $st = $pdo->query($sql);
        $safetyIncidents = $st->fetchAll();
    } catch (Exception $e) {
        $safetyIncidents = [];
    }
}

$flash = flash_get();
?>

<div class="bg-gradient-to-r from-indigo-600 to-purple-500 rounded-3xl p-8 text-white shadow-xl shadow-indigo-500/20 mb-8">
    <div class="flex items-start gap-4">
        <div class="p-4 bg-white/20 rounded-3xl border border-white/20">
            <i data-lucide="layout-dashboard" class="w-8 h-8 text-yellow-200"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-3xl font-extrabold">임원 대시보드</h2>
            <p class="text-indigo-100 text-lg mt-2">전체 현황 및 이슈를 확인/처리합니다.</p>
        </div>
    </div>
</div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<!-- KPI 카드(샘플) -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">전체 프로젝트</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2">—</p>
            </div>
            <div class="p-4 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-2xl shadow-lg shadow-blue-500/30">
                <i data-lucide="folder" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">프로젝트 수는 공무에서 확인합니다.</p>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">미처리 이슈</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo (int)count($issues); ?></p>
            </div>
            <div class="p-4 bg-gradient-to-br from-rose-500 to-orange-500 rounded-2xl shadow-lg shadow-rose-500/30">
                <i data-lucide="alert-triangle" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">최근 20건 기준 표시</p>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">안전사고</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo (int)count($safetyIncidents); ?></p>
            </div>
            <div class="p-4 bg-gradient-to-br from-rose-600 to-red-500 rounded-2xl shadow-lg shadow-rose-500/30">
                <i data-lucide="shield-alert" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">최근 10건 기준 표시</p>
    </div>
</div>

<!-- ✅ 안전사고(임원) -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">안전사고(최근 10)</h3>
            <div class="text-sm text-gray-600 mt-1">공사에서 등록한 안전사고를 확인합니다.</div>
        </div>
        <a href="<?php echo h(base_url()); ?>/?r=안전/보건" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">안전 탭으로</a>
    </div>

    <?php if (count($safetyIncidents) === 0): ?>
        <div class="text-sm text-gray-600">등록된 안전사고가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($safetyIncidents as $it): ?>
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
                                프로젝트: <b><?php echo h($it['project_name']); ?></b>
                                · 등록: <?php echo h($it['created_by_name']); ?>
                                · 접수시간: <?php echo h($it['created_at']); ?>
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
        <div class="text-xs text-gray-500 mt-3">* 안전사고 상태 변경은 안전팀/임원이 안전 탭에서 처리합니다.</div>
    <?php endif; ?>
</div>

<!-- ✅ 이슈 목록 + 상태 처리 -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">이슈(최근 20)</h3>
            <div class="text-sm text-gray-600 mt-1">공사/공무에서 등록한 이슈를 확인하고 상태를 처리합니다.</div>
        </div>
        <a href="<?php echo h(base_url()); ?>/?r=공무/프로젝트" class="px-4 py-2 rounded-2xl bg-gray-100 text-gray-900 font-bold hover:bg-gray-200">공무로</a>
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
                                프로젝트: <b><?php echo h($it['project_name']); ?></b>
                                · 등록: <?php echo h($it['created_by_name']); ?>
                                · 등록일: <?php echo h($it['created_at']); ?>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-2">
                            <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>

                            <!-- 상태처리(임원 가능) -->
                            <form method="post" action="<?php echo h(base_url()); ?>/?r=dashboard/issue_update" class="flex items-center gap-2">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="issue_id" value="<?php echo (int)$it['id']; ?>">
                                <select name="status" class="px-3 py-2 rounded-2xl border border-gray-200 text-sm">
                                    <option value="처리중" <?php echo ($stt==='처리중')?'selected':''; ?>>처리중</option>
                                    <option value="처리완료" <?php echo ($stt==='처리완료')?'selected':''; ?>>처리완료</option>
                                </select>
                                <button type="submit" class="px-3 py-2 rounded-2xl bg-gray-900 text-white font-extrabold text-sm">변경</button>
                            </form>
                        </div>
                    </div>

                    <div class="mt-3">
                        <a class="text-sm font-bold text-indigo-600 hover:underline"
                           href="<?php echo h(base_url()); ?>/?r=공무/프로젝트상세&id=<?php echo (int)$it['project_id']; ?>">
                            프로젝트 상세로 이동
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-xs text-gray-500 mt-3">* 이슈 상태 변경은 임원 또는 등록자만 가능합니다(기존 정책).</div>
    <?php endif; ?>
</div>

<!-- 기존 TaskList(샘플) -->
<div class="mt-8">
    <?php render_task_list_sample(); ?>
</div>
