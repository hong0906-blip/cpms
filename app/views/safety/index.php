<?php
/**
 * safety/index.php
 * - safety/index 빈화면 복구
 * - PHP 5.6 호환 최소 안전 화면
 */

use App\Core\Auth;
use App\Core\Db;

$flash = flash_get();
$pdo = Db::pdo();

$role = (string)Auth::userRole();
$dept = (string)Auth::userDepartment();
$canUpdateIncident = false;
if (Auth::isMaster() || $role === 'executive' || $dept === '안전') {
    $canUpdateIncident = true;
}
if (!$canUpdateIncident && method_exists('App\\Core\\Auth', 'canManageConstruction')) {
    $canUpdateIncident = Auth::canManageConstruction();
}

$safetyIncidents = array();
$safetyLoadError = '';
if ($pdo) {
    try {
        $sql = "SELECT i.*, p.name AS project_name
                FROM cpms_safety_incidents i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                ORDER BY i.id DESC
                LIMIT 50";
        $st = $pdo->query($sql);
        $safetyIncidents = $st ? $st->fetchAll() : array();
    } catch (Exception $e) {
        $safetyLoadError = $e->getMessage();        
        $safetyIncidents = array();      
    }
} else {
    $safetyLoadError = 'DB 연결이 없습니다.'; 
}

$checklistData = array();
if (!function_exists('get_safety_checklist_data')) {
    $checklistPath = __DIR__ . '/../partials/SafetyChecklist.php';
    if (file_exists($checklistPath)) {
        require_once $checklistPath;
    }
}
if (function_exists('get_safety_checklist_data')) {
    try {
        $checklistData = get_safety_checklist_data();
        if (!is_array($checklistData)) {
            $checklistData = array();
        }
    } catch (Exception $e) {
        $checklistData = array();
    }
}
?>

<div class="bg-gradient-to-r from-rose-600 to-orange-500 rounded-3xl p-8 text-white shadow-xl shadow-rose-500/20 mb-8">
    <div class="flex items-start gap-4">
        <div class="p-4 bg-white/20 rounded-3xl border border-white/20">
            <i data-lucide="shield" class="w-8 h-8 text-yellow-200"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-3xl font-extrabold">안전/보건</h2>
            <p class="text-rose-100 text-lg mt-2">안전사고 및 후속조치를 관리합니다.</p>
        </div>
    </div>
</div>

<div class="mb-4 p-3 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 text-sm font-bold">SAFETY_Iible-01</div>NDEX_LOADED = 2026-safety-followup-vis

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type'] === 'success') ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">    
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">안전사고 목록</h3>
            <div class="text-sm text-gray-600 mt-1">최근 50건의 사고 현황 및 후속조치를 관리합니다.</div>
        </div>
    </div>

    <?php if ($safetyLoadError !== ''): ?>
        <div class="mb-3 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">안전사고 목록 조회 중 오류가 발생했습니다: <?php echo h($safetyLoadError); ?></div>
    <?php endif; ?>

    <?php if (count($safetyIncidents) === 0): ?>
        <div class="text-sm text-gray-600">등록된 안전사고가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($safetyIncidents as $it): ?>
                <?php $stt = isset($it['status']) ? (string)$it['status'] : '접수'; ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white">
                    <div class="font-extrabold text-gray-900"><?php echo h(isset($it['title']) ? $it['title'] : '-'); ?></div>
                    <div class="text-xs text-gray-500 mt-1">현장명: <?php echo h(isset($it['project_name']) ? $it['project_name'] : '-'); ?> · 등록일: <?php echo h(isset($it['created_at']) ? $it['created_at'] : '-'); ?></div>
                    <div class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?php echo h(isset($it['description']) ? $it['description'] : ''); ?></div>
                    <div class="text-xs text-gray-600 mt-2">현재 상태: <b><?php echo h($stt); ?></b></div>
                    <?php if (isset($it['action_note']) && trim((string)$it['action_note']) !== ''): ?>
                        <div class="text-xs text-gray-700 mt-2">기존 후속조치: <?php echo nl2br(h($it['action_note'])); ?></div>
                    <?php endif; ?>

                    <?php if ($canUpdateIncident): ?>
                        <form method="post" action="<?php echo h(base_url()); ?>/?r=safety/incident_update" class="mt-3">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="incident_id" value="<?php echo (int)$it['id']; ?>">
                            <input type="hidden" name="redirect" value="<?php echo h(base_url()); ?>/?r=safety_home">
                            <div class="flex items-center gap-2 mb-2">
                                <select name="status" class="px-3 py-2 rounded-2xl border border-gray-200 text-sm">
                                    <option value="접수" <?php echo ($stt === '접수') ? 'selected' : ''; ?>>접수</option>
                                    <option value="처리중" <?php echo ($stt === '처리중') ? 'selected' : ''; ?>>처리중</option>
                                    <option value="처리완료" <?php echo ($stt === '처리완료') ? 'selected' : ''; ?>>처리완료</option>
                                </select>
                            </div>
                            <textarea name="action_note" rows="3" class="w-full px-3 py-2 rounded-2xl border border-gray-200 text-sm" placeholder="후속조치 내용을 입력하세요."><?php echo h(isset($it['action_note']) ? $it['action_note'] : ''); ?></textarea>
                            <button type="submit" class="mt-2 px-3 py-2 rounded-2xl bg-gray-900 text-white font-extrabold text-sm">저장</button>
                        </form>
                    <?php else: ?>
                        <div class="text-xs text-gray-500 mt-3">후속조치 입력 권한이 없습니다.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <h3 class="text-xl font-extrabold text-gray-900 mb-3">안전 체크리스트</h3>
    <?php if (count($checklistData) === 0): ?>
        <div class="text-sm text-gray-500">표시할 체크리스트가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($checklistData as $row): ?>
                <div class="p-3 rounded-2xl border border-gray-100 bg-gray-50">
                    <div class="font-bold text-gray-900"><?php echo h(isset($row['title']) ? $row['title'] : '-'); ?></div>
                    <div class="text-xs text-gray-500"><?php echo h(isset($row['meta']) ? $row['meta'] : ''); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>