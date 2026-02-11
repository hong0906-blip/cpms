<?php
/**
 * C:\www\cpms\app\views\dashboard\employee.php
 * - 기존 샘플 유지 + ✅ "내가 등록한 이슈" 목록 추가
 */

require_once __DIR__ . '/../partials/TaskList.php';
require_once __DIR__ . '/../partials/cost_metrics.php';

use App\Core\Db;

$user = \App\Core\Auth::user();
$pdo = Db::pdo();

$userEmail = '';
$userName  = '';
try {
    $userEmail = (string)\App\Core\Auth::userEmail();
    $userName  = (string)\App\Core\Auth::userName();
} catch (Exception $e) {
    $userEmail = '';
    $userName = '';
}

$myIssues = [];
if ($pdo && $userEmail !== '') {
    try {
        $sql = "SELECT i.*, p.name AS project_name
                FROM cpms_project_issues i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                WHERE i.created_by_email = :em
                ORDER BY i.id DESC
                LIMIT 20";
        $st = $pdo->prepare($sql);
        $st->bindValue(':em', $userEmail);
        $st->execute();
        $myIssues = $st->fetchAll();
    } catch (Exception $e) {
        $myIssues = [];
    }
}

// ✅ 내 프로젝트 안전사고(최근 10) - 공사팀이 "내 프로젝트만" 보는 흐름 보조용
$mySafety = [];
if ($pdo && $userEmail !== '') {
    try {
        // employees 테이블에서 내 employee_id 찾고, 멤버(main/sub) 프로젝트만 조회
        $eid = 0;
        $stE = $pdo->prepare("SELECT id FROM employees WHERE email = :em LIMIT 1");
        $stE->bindValue(':em', $userEmail);
        $stE->execute();
        $eid = (int)$stE->fetchColumn();

        if ($eid > 0) {
            $sql = "SELECT i.*, p.name AS project_name
                    FROM cpms_safety_incidents i
                    LEFT JOIN cpms_projects p ON p.id = i.project_id
                    JOIN cpms_project_members pm ON pm.project_id = i.project_id
                    WHERE pm.employee_id = :eid
                      AND pm.role IN ('main','sub')
                    ORDER BY i.id DESC
                    LIMIT 10";
            $st = $pdo->prepare($sql);
            $st->bindValue(':eid', $eid, PDO::PARAM_INT);
            $st->execute();
            $mySafety = $st->fetchAll();
        }
    } catch (Exception $e) {
        $mySafety = [];
    }
}


// ✅ 원가/공정 KPI(week/month)
$period = isset($_GET['period']) ? trim((string)$_GET['period']) : 'week';
if ($period !== 'month') $period = 'week';

$kpiRows = array();
if ($pdo && $userEmail !== '') {
    try {
        $eid2 = 0;
        $stE2 = $pdo->prepare("SELECT id FROM employees WHERE email = :em LIMIT 1");
        $stE2->bindValue(':em', $userEmail);
        $stE2->execute();
        $eid2 = (int)$stE2->fetchColumn();

        if ($eid2 > 0) {
            $sql = "SELECT p.id, p.name
                    FROM cpms_projects p
                    JOIN cpms_construction_roles r ON r.project_id = p.id
                    WHERE r.site_employee_id = :eid
                       OR r.safety_employee_id = :eid
                       OR r.quality_employee_id = :eid";
            $stP = $pdo->prepare($sql);
            $stP->bindValue(':eid', $eid2, PDO::PARAM_INT);
            $stP->execute();
            $ps = $stP->fetchAll();

            foreach ($ps as $pr) {
                $m = cpms_project_cost_metrics($pdo, (int)$pr['id'], $period);
                $m['project_id'] = (int)$pr['id'];
                $m['project_name'] = (string)$pr['name'];
                $kpiRows[] = $m;
            }

            usort($kpiRows, function($a, $b){
                $av = ($a['cost_rate'] === null) ? -1 : (float)$a['cost_rate'];
                $bv = ($b['cost_rate'] === null) ? -1 : (float)$b['cost_rate'];
                if ($av === $bv) return 0;
                return ($av > $bv) ? -1 : 1;
            });
        }
    } catch (Exception $e) {
        $kpiRows = array();
    }
}

$flash = flash_get();
?>

<div class="bg-gradient-to-r from-blue-600 to-cyan-500 rounded-3xl p-8 text-white shadow-xl shadow-blue-500/20 mb-8">
    <div class="flex items-start gap-4">
        <div class="p-4 bg-white/20 rounded-3xl border border-white/20">
            <i data-lucide="sparkles" class="w-8 h-8 text-yellow-200"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-3xl font-extrabold">대시보드</h2>
            <p class="text-blue-100 text-lg mt-2">
                <?php echo ($userName !== '') ? (h($userName) . '님, ') : ''; ?>오늘도 안전하게 진행하세요.
            </p>
        </div>
    </div>
</div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>


<!-- ✅ 프로젝트별 원가/공정 KPI -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">프로젝트별 원가/공정 KPI</h3>
            <div class="text-sm text-gray-600 mt-1">담당(현장/안전/품질) 프로젝트만 표시 · 원가율 높은 순</div>
        </div>
        <form method="get" class="flex items-center gap-2">
            <input type="hidden" name="r" value="대시보드">
            <input type="hidden" name="dv" value="employee">
            <select name="period" onchange="this.form.submit()" class="px-3 py-2 rounded-2xl border border-gray-200 text-sm">
                <option value="week" <?php echo ($period==='week')?'selected':''; ?>>week</option>
                <option value="month" <?php echo ($period==='month')?'selected':''; ?>>month</option>
            </select>
        </form>
    </div>

    <?php if (count($kpiRows) === 0): ?>
        <div class="text-sm text-gray-600">표시할 프로젝트가 없습니다.</div>
    <?php else: ?>
        <div class="overflow-x-auto rounded-2xl border border-gray-200">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="p-3 text-left font-extrabold">프로젝트</th>
                    <th class="p-3 text-center font-extrabold">원가율</th>
                    <th class="p-3 text-center font-extrabold">공정률</th>
                    <th class="p-3 text-center font-extrabold">노무/자재/안전 차이</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($kpiRows as $r): ?>
                    <tr class="border-t border-gray-100">
                        <td class="p-3">
                            <a class="font-bold text-indigo-600 hover:underline" href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$r['project_id']; ?>&tab=cost_progress&sub=summary&period=<?php echo h($period); ?>"><?php echo h($r['project_name']); ?></a>
                        </td>
                        <td class="p-3 text-center"><?php echo h($r['cost_rate_label']); ?><?php if ($r['cost_rate_note'] !== ''): ?> (<?php echo h($r['cost_rate_note']); ?>)<?php endif; ?></td>
                        <td class="p-3 text-center"><?php echo number_format((float)$r['progress_rate'], 2); ?>%</td>
                        <td class="p-3 text-center"><?php echo number_format((float)$r['variance_labor']); ?> / <?php echo number_format((float)$r['variance_material']); ?> / <?php echo number_format((float)$r['variance_safety']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- 기존 샘플 카드 -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">오늘 할 일</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2">—</p>
            </div>
            <div class="p-4 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-2xl shadow-lg shadow-indigo-500/30">
                <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">샘플 카드(확장 예정)</p>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">이슈(내가 등록)</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo (int)count($myIssues); ?></p>
            </div>
            <div class="p-4 bg-gradient-to-br from-rose-500 to-orange-500 rounded-2xl shadow-lg shadow-rose-500/30">
                <i data-lucide="alert-circle" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">최근 20건 기준 표시</p>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">안전사고(내 프로젝트)</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo (int)count($mySafety); ?></p>
            </div>
            <div class="p-4 bg-gradient-to-br from-rose-600 to-red-500 rounded-2xl shadow-lg shadow-rose-500/30">
                <i data-lucide="shield-alert" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">최근 10건 기준 표시</p>
    </div>
</div>

<!-- ✅ 내 프로젝트 안전사고(요약) -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">안전사고(내 프로젝트 · 최근 10)</h3>
            <div class="text-sm text-gray-600 mt-1">공사에서 등록한 안전사고가 있으면 여기서도 확인됩니다.</div>
        </div>
        <a href="<?php echo h(base_url()); ?>/?r=안전/보건" class="px-4 py-2 rounded-2xl bg-gray-100 text-gray-900 font-bold hover:bg-gray-200">안전 탭</a>
    </div>

    <?php if ($userEmail === ''): ?>
        <div class="text-sm text-rose-700 font-bold">로그인 사용자 이메일이 없어 조회할 수 없습니다.</div>
    <?php elseif (count($mySafety) === 0): ?>
        <div class="text-sm text-gray-600">내 프로젝트의 안전사고가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($mySafety as $it): ?>
                <?php
                // ✅ 오타 수정: "$<?php" → "<?php" (이 1개만 수정)
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
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ✅ 내가 등록한 이슈 -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">내가 등록한 이슈(최근 20)</h3>
            <div class="text-sm text-gray-600 mt-1">내가 등록한 이슈만 보여줍니다.</div>
        </div>
        <a href="<?php echo h(base_url()); ?>/?r=공사" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">공사로</a>
    </div>

    <?php if ($userEmail === ''): ?>
        <div class="text-sm text-rose-700 font-bold">로그인 사용자 이메일이 없어 조회할 수 없습니다.</div>
    <?php elseif (count($myIssues) === 0): ?>
        <div class="text-sm text-gray-600">내가 등록한 이슈가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($myIssues as $it): ?>
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
                                · 등록일: <?php echo h($it['created_at']); ?>
                            </div>
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>
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
        <div class="text-xs text-gray-500 mt-3">* 상태 변경은 임원 또는 등록자만 가능합니다(기존 정책).</div>
    <?php endif; ?>
</div>

<!-- 기존 TaskList(샘플) -->
<div class="mt-8">
    <?php render_task_list_sample(); ?>
</div>
