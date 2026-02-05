<?php
/**
 * safety/index.php
 * - SafetyHealthManagement.tsx 느낌(요청 리스트 + 첨부 UI + 체크리스트)
 */
require_once __DIR__ . '/../partials/SafetyChecklist.php';

use App\Core\Auth;
use App\Core\Db;

$role = Auth::userRole();
$dept = Auth::userDepartment();

// 안전 메뉴 접근은 원래 열려있지만, 안전사고 "상태변경"은 안전/임원만 가능
$canUpdateIncident = ($role === 'executive' || $dept === '안전');

// 안전사고 목록(최근 50)
$pdo = Db::pdo();
$safetyIncidents = array();
if ($pdo) {
    try {
        $sql = "SELECT i.*, p.name AS project_name
                FROM cpms_safety_incidents i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                ORDER BY i.id DESC
                LIMIT 50";
        $st = $pdo->query($sql);
        $safetyIncidents = $st->fetchAll();
    } catch (Exception $e) {
        $safetyIncidents = array();
    }
}

$checklist = get_safety_checklist_data();
$flash = flash_get();
?>

<div class="bg-gradient-to-r from-rose-600 to-orange-500 rounded-3xl p-8 text-white shadow-xl shadow-rose-500/20 mb-8">
    <div class="flex items-start gap-4">
        <div class="p-4 bg-white/20 rounded-3xl border border-white/20">
            <i data-lucide="shield" class="w-8 h-8 text-yellow-200"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-3xl font-extrabold">안전/보건</h2>
            <p class="text-rose-100 text-lg mt-2">안전사고 및 안전 점검을 관리합니다.</p>
        </div>
    </div>
</div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<!-- ✅ 안전사고 탭 -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">안전사고</h3>
            <div class="text-sm text-gray-600 mt-1">공사에서 등록한 안전사고가 여기로 모입니다. (최근 50건)</div>
        </div>
        <a href="<?php echo h(base_url()); ?>/?r=공사" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">공사로</a>
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

                        <div class="flex flex-col items-end gap-2">
                            <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>

                            <?php if ($canUpdateIncident): ?>
                                <form method="post" action="<?php echo h(base_url()); ?>/?r=safety/incident_update" class="flex items-center gap-2">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="incident_id" value="<?php echo (int)$it['id']; ?>">
                                    <select name="status" class="px-3 py-2 rounded-2xl border border-gray-200 text-sm">
                                        <option value="접수" <?php echo ($stt==='접수')?'selected':''; ?>>접수</option>
                                        <option value="처리중" <?php echo ($stt==='처리중')?'selected':''; ?>>처리중</option>
                                        <option value="처리완료" <?php echo ($stt==='처리완료')?'selected':''; ?>>처리완료</option>
                                    </select>
                                    <button type="submit" class="px-3 py-2 rounded-2xl bg-gray-900 text-white font-extrabold text-sm">변경</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!$canUpdateIncident): ?>
            <div class="text-xs text-gray-500 mt-3">* 상태 변경은 안전팀/임원만 가능합니다.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- 기존 안전 체크리스트(샘플) 유지 -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-extrabold text-gray-900">안전 체크리스트</h3>
            <div class="p-3 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-2xl shadow-lg shadow-indigo-500/30">
                <i data-lucide="check-square" class="w-5 h-5 text-white"></i>
            </div>
        </div>
        <div class="space-y-3">
            <?php foreach ($checklist as $row): ?>
                <label class="flex items-center gap-3 p-3 rounded-2xl border border-gray-100 bg-gray-50">
                    <input type="checkbox" <?php echo $row['done']?'checked':''; ?> class="w-5 h-5">
                    <div class="flex-1">
                        <div class="font-bold text-gray-900"><?php echo h($row['title']); ?></div>
                        <div class="text-xs text-gray-500"><?php echo h($row['meta']); ?></div>
                    </div>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-extrabold text-gray-900">첨부/기록(샘플)</h3>
            <div class="p-3 bg-gradient-to-br from-emerald-500 to-teal-500 rounded-2xl shadow-lg shadow-emerald-500/30">
                <i data-lucide="paperclip" class="w-5 h-5 text-white"></i>
            </div>
        </div>
        <div class="p-4 rounded-2xl border border-gray-200 bg-gray-50 text-gray-600 text-sm">
            (기존 샘플 UI 유지) 필요하면 다음 단계에서 “사진 업로드/첨부파일” 기능을 여기로 붙이면 됩니다.
        </div>
    </div>
</div>
