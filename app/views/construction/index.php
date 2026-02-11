<?php
/**
 * C:\www\cpms\app\views\construction\index.php
 * - 공사(뼈대):
 *   1) 내 프로젝트만 보기(공사팀 기준: main/sub 멤버)
 *   2) 담당 지정(안전/품질/현장)
 *   3) 템플릿 생성 버튼(단가표 공정 기반)
 *   4) 공정표(간트) 생성/수정/삭제 (공사팀+임원만)
 *   5) 안전사고 등록 버튼 → 안전팀/임원에서 안전사고 탭/대시보드 확인
 *
 * ✅ 추가:
 *   - 계약서 표시/다운로드(담당자(main/sub)+임원만 다운로드 가능: contract_download 라우트에서 강제 체크)
 *
 * PHP 5.6 호환
 */

use App\Core\Auth;
use App\Core\Db;

$role = Auth::userRole();
$dept = Auth::userDepartment();

// 공사 메뉴 접근: 공사 또는 임원
$allowed = ($role === 'executive' || $dept === '공사');
if (!$allowed) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (공사/임원 전용)</div>';
    return;
}

$pdo = Db::pdo();
if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>';
    return;
}

$userEmail = Auth::userEmail();

// 직원 ID 조회(공사 "내 프로젝트" 필터에 필요)
$employeeId = 0;
if ($role !== 'executive') {
    try {
        $stE = $pdo->prepare("SELECT id FROM employees WHERE email = :em LIMIT 1");
        $stE->bindValue(':em', (string)$userEmail);
        $stE->execute();
        $employeeId = (int)$stE->fetchColumn();
    } catch (Exception $e) {
        $employeeId = 0;
    }
    if ($employeeId <= 0) {
        echo '<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-2xl p-4 font-bold">직원명부(employees)에 현재 계정 이메일이 등록되어 있어야 공사 프로젝트를 조회할 수 있습니다.</div>';
        return;
    }
}

// 프로젝트 목록
$projects = array();
try {
    if ($role === 'executive') {
        $st = $pdo->query("SELECT * FROM cpms_projects ORDER BY id DESC");
        $projects = $st->fetchAll();
    } else {
        $sql = "SELECT DISTINCT p.*
                FROM cpms_projects p
                JOIN cpms_project_members pm ON pm.project_id = p.id
                WHERE pm.employee_id = :eid
                  AND LOWER(TRIM(pm.role)) IN ('main','sub')
                ORDER BY p.id DESC";
        $st = $pdo->prepare($sql);
        $st->bindValue(':eid', $employeeId, \PDO::PARAM_INT);
        $st->execute();
        $projects = $st->fetchAll();
    }
} catch (Exception $e) {
    $projects = array();
}

if (count($projects) === 0) {
    echo '<div class="bg-white rounded-2xl border border-gray-200 p-6 text-gray-600">조회 가능한 프로젝트가 없습니다. (공사팀은 본인/팀이 배정(main/sub)된 프로젝트만 보입니다.)</div>';
    return;
}

$selectedPid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
if ($selectedPid <= 0) {
    $selectedPid = (int)$projects[0]['id'];
}

// 선택 프로젝트 로드
$project = null;
foreach ($projects as $p) {
    if ((int)$p['id'] === $selectedPid) { $project = $p; break; }
}
if (!$project) {
    $project = $projects[0];
    $selectedPid = (int)$project['id'];
}

$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'gantt';
if ($tab === '') $tab = 'gantt';

$tabs = array(
    'roles'    => '담당지정',
    'gantt'    => '공정표',
    'labor'    => '노무비',
    'equipment'=> '장비',
    'materials'=> '자재구입비',
    'safety_cost' => '안전관리비',
    'cost_progress' => '원가/공정',
    'issues'   => '이슈',
    'safety'   => '안전사고',
);
if (!isset($tabs[$tab])) $tab = 'gantt';

$canEditSchedule = ($role === 'executive' || $dept === '공사'); // 요구사항: 공사팀+임원만

$flash = flash_get();

// ==============================
//  계약서(파일) 존재 여부 (DB 변경 없이 파일로만 관리)
//  - 저장 위치: {cpms_root}/storage/contracts/{projectId}/meta.json
// ==============================
$cpmsRoot = dirname(dirname(dirname(__DIR__))); // cpms/
$contractDir = $cpmsRoot . '/storage/contracts/' . $selectedPid;
$contractMetaFile = $contractDir . '/meta.json';
$hasContract = false;
$contractMeta = array();
if (is_file($contractMetaFile)) {
    $json = @file_get_contents($contractMetaFile);
    $tmp = @json_decode($json, true);
    if (is_array($tmp) && isset($tmp['stored_name'])) {
        $stored = basename((string)$tmp['stored_name']);
        $storedPath = $contractDir . '/' . $stored;
        if (is_file($storedPath)) {
            $hasContract = true;
            $contractMeta = $tmp;
        }
    }
}

// ===== 공통 데이터(담당 지정 표시용) =====
$roleRow = null;
try {
    $stR = $pdo->prepare("SELECT * FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
    $stR->bindValue(':pid', $selectedPid, \PDO::PARAM_INT);
    $stR->execute();
    $roleRow = $stR->fetch();
} catch (Exception $e) {
    $roleRow = null;
}

$siteId = $roleRow && isset($roleRow['site_employee_id']) ? (int)$roleRow['site_employee_id'] : 0;
$safetyId = $roleRow && isset($roleRow['safety_employee_id']) ? (int)$roleRow['safety_employee_id'] : 0;
$qualityId = $roleRow && isset($roleRow['quality_employee_id']) ? (int)$roleRow['quality_employee_id'] : 0;

$siteName = '';
$safetyName = '';
$qualityName = '';

try {
    $ids = array();
    if ($siteId > 0) $ids[] = $siteId;
    if ($safetyId > 0) $ids[] = $safetyId;
    if ($qualityId > 0) $ids[] = $qualityId;

    if (count($ids) > 0) {
        $in = implode(',', array_map('intval', $ids));
        $stN = $pdo->query("SELECT id, name FROM employees WHERE id IN ($in)");
        $rows = $stN->fetchAll();
        $map = array();
        foreach ($rows as $r) $map[(int)$r['id']] = (string)$r['name'];

        if ($siteId > 0 && isset($map[$siteId])) $siteName = $map[$siteId];
        if ($safetyId > 0 && isset($map[$safetyId])) $safetyName = $map[$safetyId];
        if ($qualityId > 0 && isset($map[$qualityId])) $qualityName = $map[$qualityId];
    }
} catch (Exception $e) {}

?>

<div class="flex items-start justify-between gap-3 mb-6">
    <div class="min-w-0">
        <div class="text-sm text-gray-500">공사 뼈대</div>
        <h2 class="text-2xl font-extrabold text-gray-900">공사 관리</h2>
        <div class="text-sm text-gray-600 mt-1 truncate">
            선택 프로젝트: <b><?php echo h($project['name']); ?></b>
        </div>
        <div class="text-xs text-gray-500 mt-1">
            기간: <?php echo h($project['start_date']); ?> ~ <?php echo h($project['end_date']); ?> · 상태: <?php echo h($project['status']); ?>
        </div>

        <div class="text-xs text-gray-500 mt-1">
            계약서:
            <?php if ($hasContract): ?>
                <b>있음</b>
                <?php if (isset($contractMeta['original_name']) && $contractMeta['original_name'] !== ''): ?>
                    <span class="text-gray-400">(<?php echo h($contractMeta['original_name']); ?>)</span>
                <?php endif; ?>
                ·
                <a href="<?php echo h(base_url()); ?>/?r=project/contract_download&id=<?php echo (int)$selectedPid; ?>"
                   class="font-extrabold text-blue-700 hover:underline">다운로드</a>
            <?php else: ?>
                <b>없음</b>
            <?php endif; ?>
        </div>

    </div>

    <div class="flex items-center gap-2">
        <a href="<?php echo h(base_url()); ?>/db_setup_construction.php"
           class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-bold shadow hover:shadow-lg transition">
            DB 설정
        </a>

        <button type="button"
                class="px-5 py-3 rounded-2xl bg-gradient-to-r from-red-500 to-rose-500 text-white font-extrabold shadow-lg hover:shadow-xl transition"
                data-modal-open="safetyIncidentAdd">
            <span class="inline-flex items-center gap-2">
                <i data-lucide="siren" class="w-5 h-5"></i> 안전사고
            </span>
        </button>
    </div>
</div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<!-- 프로젝트 선택 -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <form method="get" action="" class="flex flex-col md:flex-row md:items-end gap-3">
        <input type="hidden" name="r" value="공사">
        <div class="flex-1 min-w-0">
            <label class="text-sm font-bold text-gray-700">프로젝트 선택</label>
            <select name="pid" class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200">
                <?php foreach ($projects as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$p['id']===$selectedPid)?'selected':''; ?>>
                        <?php echo h($p['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="text-sm font-bold text-gray-700">탭</label>
            <select name="tab" class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200">
                <?php foreach ($tabs as $k => $label): ?>
                    <option value="<?php echo h($k); ?>" <?php echo ($k===$tab)?'selected':''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-yellow-500 to-orange-500 text-white font-extrabold shadow-lg">
            보기
        </button>
    </form>

    <div class="mt-4 text-xs text-gray-600">
        담당(현장/안전/품질):
        <b><?php echo h($siteName !== '' ? $siteName : '미지정'); ?></b> /
        <b><?php echo h($safetyName !== '' ? $safetyName : '미지정'); ?></b> /
        <b><?php echo h($qualityName !== '' ? $qualityName : '미지정'); ?></b>
    </div>
</div>

<!-- 탭 메뉴(빠른 전환) -->
<div class="flex flex-wrap gap-2 mb-6">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$selectedPid; ?>&tab=<?php echo h($k); ?>"
           class="px-4 py-2 rounded-2xl border font-extrabold <?php echo ($k===$tab)?'bg-gray-900 text-white border-gray-900':'bg-white text-gray-900 border-gray-200 hover:bg-gray-50'; ?>">
            <?php echo h($label); ?>
        </a>
    <?php endforeach; ?>
</div>

<?php
// 탭 렌더
$tabFile = __DIR__ . '/tabs/' . $tab . '.php';
if (!file_exists($tabFile)) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">탭 파일이 없습니다: ' . h($tab) . '</div>';
} else {
    // 탭에서 쓸 변수 전달
    $pid = $selectedPid;
    $projectRow = $project;
    $canEdit = $canEditSchedule;
    require $tabFile;
}
?>
