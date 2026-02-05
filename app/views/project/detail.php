<?php
/**
 * C:\www\cpms\app\views\project\detail.php
 * - 공무(프로젝트) 상세
 *
 * ✅ 이번 수정사항:
 * 1) 시공사(contractor) 표시 (발주처 옆)
 * 2) 예산 → 계약금액(contract_amount) 표시로 변경
 * 3) ✅ 계약서 업로드/다운로드 추가(파일로만 관리)
 *
 * PHP 5.6 호환
 */

use App\Core\Auth;
use App\Core\Db;

$pdo = Db::pdo();
if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>';
    return;
}

// 권한: 임원 또는 공무/관리
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (임원/공무/관리 전용)</div>';
    return;
}

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($projectId <= 0) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">잘못된 프로젝트 ID</div>';
    return;
}

// 프로젝트 조회
$project = null;
try {
    $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id");
    $st->bindValue(':id', $projectId, PDO::PARAM_INT);
    $st->execute();
    $project = $st->fetch();
} catch (Exception $e) {
    $project = null;
}

if (!$project) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">프로젝트를 찾을 수 없습니다.</div>';
    return;
}

// 담당자 조회
$mainManager = null;
$subManagers = array();

try {
    $sql = "SELECT pm.role, e.id, e.name, e.department, e.position
            FROM cpms_project_members pm
            JOIN employees e ON e.id = pm.employee_id
            WHERE pm.project_id = :pid
            ORDER BY pm.role, e.department, e.position, e.name";
    $st = $pdo->prepare($sql);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();
    $members = $st->fetchAll();

    foreach ($members as $m) {
        if ((string)$m['role'] === 'main') $mainManager = $m;
        if ((string)$m['role'] === 'sub') $subManagers[] = $m;
    }
} catch (Exception $e) {
    $mainManager = null;
    $subManagers = array();
}

// 단가표 조회
$unitPrices = array();
try {
    $st = $pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id = :pid ORDER BY id DESC");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();
    $unitPrices = $st->fetchAll();
} catch (Exception $e) {
    $unitPrices = array();
}

$flash = flash_get();

// ==============================
//  계약서(파일) 정보 (DB 변경 없이 파일로만 관리)
//  - 저장 위치: {cpms_root}/storage/contracts/{projectId}/
//  - meta.json에 원본 파일명/저장 파일명 저장
// ==============================
$cpmsRoot = dirname(dirname(dirname(__DIR__))); // cpms/
$contractDir = $cpmsRoot . '/storage/contracts/' . $projectId;
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

?>

<div class="flex items-start justify-between gap-3 mb-6">
    <div>
        <div class="text-sm text-gray-500">프로젝트 상세</div>
        <h2 class="text-2xl font-extrabold text-gray-900"><?php echo h($project['name']); ?></h2>

        <div class="text-sm text-gray-600 mt-1">
            <?php echo h($project['client']); ?>
            <?php if ((string)$project['client'] !== '' && (string)$project['contractor'] !== ''): ?> · <?php endif; ?>
            <?php echo h($project['contractor']); ?>

            <?php if (((string)$project['client'] !== '' || (string)$project['contractor'] !== '') && (string)$project['location'] !== ''): ?>
                ·
            <?php endif; ?>

            <?php echo h($project['location']); ?>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <a href="<?php echo h(base_url()); ?>/?r=공무"
           class="px-4 py-2 rounded-2xl bg-gray-100 text-gray-900 font-bold hover:bg-gray-200 transition">
            ← 목록
        </a>

        <a href="<?php echo h(base_url()); ?>/?r=project/header_mapping"
           class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-bold shadow hover:shadow-lg transition">
            헤더 매핑 설정
        </a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<!-- 계약서 업로드/다운로드 -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <div class="font-extrabold text-gray-900">계약서</div>
        <div class="text-xs text-gray-500">프로젝트 계약서 파일 업로드/확인</div>
    </div>
    <div class="p-6">
        <?php if ($hasContract): ?>
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="text-sm text-gray-700">
                    <div class="font-extrabold text-gray-900">업로드된 계약서</div>
                    <div class="text-gray-600 mt-1">파일명: <b><?php echo h(isset($contractMeta['original_name']) ? $contractMeta['original_name'] : 'contract'); ?></b></div>
                    <?php if (isset($contractMeta['uploaded_at']) && $contractMeta['uploaded_at']): ?>
                        <div class="text-xs text-gray-500 mt-1">업로드일: <?php echo h($contractMeta['uploaded_at']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2">
                    <a href="<?php echo h(base_url()); ?>/?r=project/contract_download&id=<?php echo (int)$projectId; ?>"
                       class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold shadow hover:shadow-lg transition">
                        다운로드
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="text-sm text-gray-600">아직 업로드된 계약서가 없습니다.</div>
        <?php endif; ?>

        <div class="mt-5 pt-5 border-t border-gray-100">
            <div class="text-sm font-extrabold text-gray-900 mb-2">계약서 업로드</div>
            <div class="text-xs text-gray-500 mb-3">* 새로 업로드하면 기존 계약서는 교체됩니다. (PDF/문서/이미지 가능)</div>
            <form method="post" action="<?php echo h(base_url()); ?>/?r=project/contract_upload" enctype="multipart/form-data" class="flex flex-col md:flex-row md:items-center gap-3">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                <input type="file" name="contract_file" accept=".pdf,.hwp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png"
                       class="flex-1 px-4 py-3 rounded-2xl border border-gray-200 bg-white" required>
                <button type="submit" class="px-6 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-extrabold shadow">업로드</button>
            </form>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
    <!-- 기본 정보 -->
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <div class="font-extrabold text-gray-900">기본 정보</div>
        </div>
        <div class="p-6 text-sm text-gray-700 space-y-3">
            <div class="flex items-center justify-between gap-2">
                <div class="text-gray-500">발주처</div>
                <div class="font-bold"><?php echo h($project['client']); ?></div>
            </div>

            <div class="flex items-center justify-between gap-2">
                <div class="text-gray-500">시공사</div>
                <div class="font-bold"><?php echo h($project['contractor']); ?></div>
            </div>

            <div class="flex items-center justify-between gap-2">
                <div class="text-gray-500">현장 위치</div>
                <div class="font-bold text-right"><?php echo h($project['location']); ?></div>
            </div>

            <div class="flex items-center justify-between gap-2">
                <div class="text-gray-500">기간</div>
                <div class="font-bold"><?php echo h($project['start_date']); ?> ~ <?php echo h($project['end_date']); ?></div>
            </div>

            <div class="flex items-center justify-between gap-2">
                <div class="text-gray-500">상태</div>
                <div class="font-bold"><?php echo h($project['status']); ?></div>
            </div>

            <div class="flex items-center justify-between gap-2">
                <div class="text-gray-500">계약금액</div>
                <div class="font-bold"><?php echo h($project['contract_amount']); ?></div>
            </div>
        </div>
    </div>

    <!-- 담당자 -->
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <div class="font-extrabold text-gray-900">담당자</div>
        </div>
        <div class="p-6 text-sm text-gray-700 space-y-3">
            <div>
                <div class="text-gray-500 font-bold">메인</div>
                <div class="mt-1 font-extrabold text-gray-900">
                    <?php echo $mainManager ? h($mainManager['name']) : '미지정'; ?>
                </div>
            </div>

            <div>
                <div class="text-gray-500 font-bold">서브</div>
                <div class="mt-1 space-y-1">
                    <?php if (!$subManagers || count($subManagers) === 0): ?>
                        <div class="text-gray-500">미지정</div>
                    <?php else: ?>
                        <?php foreach ($subManagers as $sm): ?>
                            <div class="font-bold text-gray-900"><?php echo h($sm['name']); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 단가표 안내 -->
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <div class="font-extrabold text-gray-900">단가표</div>
        </div>
        <div class="p-6 text-sm text-gray-700">
            <div class="text-gray-600">단가표 항목을 기반으로 공사 템플릿/공정표가 생성됩니다.</div>
        </div>
    </div>
</div>

<!-- 단가표 목록 -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <div class="font-extrabold text-gray-900">단가표 항목</div>
        <div class="text-xs text-gray-500">총 <?php echo is_array($unitPrices)?count($unitPrices):0; ?>건</div>
    </div>

    <div class="p-6">
        <?php if (!is_array($unitPrices) || count($unitPrices) === 0): ?>
            <div class="text-sm text-gray-600">단가표 항목이 없습니다. (엑셀 업로드/적용을 확인하세요)</div>
        <?php else: ?>
            <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-100">
                    <tr class="text-left text-gray-600">
                        <th class="px-3 py-2 font-extrabold">품명</th>
                        <th class="px-3 py-2 font-extrabold">규격</th>
                        <th class="px-3 py-2 font-extrabold">단위</th>
                        <th class="px-3 py-2 font-extrabold">수량</th>
                        <th class="px-3 py-2 font-extrabold">합계단가</th>
                        <th class="px-3 py-2 font-extrabold">비고</th>
                        <th class="px-3 py-2 font-extrabold">관리</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php foreach ($unitPrices as $r): ?>
                        <tr>
                            <td class="px-3 py-2"><?php echo h($r['item_name']); ?></td>
                            <td class="px-3 py-2"><?php echo h($r['spec']); ?></td>
                            <td class="px-3 py-2"><?php echo h($r['unit']); ?></td>
                            <td class="px-3 py-2"><?php echo h($r['qty']); ?></td>
                            <td class="px-3 py-2"><?php echo h($r['unit_price']); ?></td>
                            <td class="px-3 py-2"><?php echo h($r['remark']); ?></td>
                            <td class="px-3 py-2">
                                <form method="post" action="<?php echo h(base_url()); ?>/?r=project/unit_price_delete" style="margin:0;">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" class="px-3 py-2 rounded-2xl bg-red-50 border border-red-200 text-red-700 font-extrabold hover:bg-red-100">
                                        삭제
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="text-xs text-gray-500 mt-2">
                * 엑셀 업로드는 “미리보기”에서 확인 후 “적용”을 누를 때 DB에 저장됩니다.
            </div>
        <?php endif; ?>
    </div>
</div>
