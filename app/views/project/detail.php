<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/contract_change_helper.php';

if (!function_exists('cpms_format_qty0')) {
function cpms_format_qty0($v) { if ($v === null || $v === '') return ''; if (!is_numeric((string)$v)) return h((string)$v); return number_format(round((float)$v), 0); }
}
if (!function_exists('cpms_format_price1')) {
function cpms_format_price1($v) { if ($v === null || $v === '') return ''; if (!is_numeric((string)$v)) return h((string)$v); return number_format(round((float)$v), 0); }
}
if (!function_exists('cpms_format_amount0')) {
function cpms_format_amount0($v) { if ($v === null || $v === '') return ''; if (!is_numeric((string)$v)) return h((string)$v); return number_format(round((float)$v), 0); }
}
if (!function_exists('cpms_project_detail_unit_price_value')) {
function cpms_project_detail_unit_price_value($row) {
    $unitPrice = (isset($row['unit_price']) && is_numeric((string)$row['unit_price'])) ? (float)$row['unit_price'] : 0.0;
    if (abs($unitPrice) > 0.0001) return $unitPrice;
    $material = (isset($row['material_unit_price']) && is_numeric((string)$row['material_unit_price'])) ? (float)$row['material_unit_price'] : 0.0;
    $labor = (isset($row['labor_unit_price']) && is_numeric((string)$row['labor_unit_price'])) ? (float)$row['labor_unit_price'] : 0.0;
    $expense = (isset($row['expense_unit_price']) && is_numeric((string)$row['expense_unit_price'])) ? (float)$row['expense_unit_price'] : 0.0;
    return $material + $labor + $expense;
}}

$pdo = Db::pdo();
if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>';
    return;
}

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다.</div>';
    return;
}

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editMode = (isset($_GET['edit']) && (string)$_GET['edit'] === '1');
if ($projectId <= 0) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">잘못된 프로젝트 ID입니다.</div>';
    return;
}

$project = null;
try {
    $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id");
    $st->bindValue(':id', $projectId, PDO::PARAM_INT);
    $st->execute();
    $project = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $project = null;
}
if (!$project) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">프로젝트를 찾을 수 없습니다.</div>';
    return;
}

$mainManager = null;
$subManagers = array();
$mainManagerId = 0;
$subManagerIds = array();
try {
    $stMembers = $pdo->prepare("
        SELECT pm.role, e.id, e.name, e.department, e.position
          FROM cpms_project_members pm
          JOIN employees e ON e.id = pm.employee_id
         WHERE pm.project_id = :pid
         ORDER BY pm.role, e.department, e.position, e.name
    ");
    $stMembers->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stMembers->execute();
    $memberRows = $stMembers->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($memberRows)) {
        foreach ($memberRows as $member) {
            if ((string)$member['role'] === 'main') {
                $mainManager = $member;
                $mainManagerId = (int)$member['id'];
            }
            if ((string)$member['role'] === 'sub') {
                array_push($subManagers, $member);
                array_push($subManagerIds, (int)$member['id']);
            }
        }
    }
} catch (Exception $e) {
}

$employees = array();
if ($editMode) {
    try {
        $stEmployees = $pdo->prepare("
            SELECT id, name, department, position
              FROM employees
             WHERE is_active = 1
             ORDER BY department, position, name
        ");
        $stEmployees->execute();
        $employees = $stEmployees->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($employees)) $employees = array();
    } catch (Exception $e) {
        $employees = array();
    }
    if ($mainManager && $mainManagerId > 0) {
        $hasMainEmployee = false;
        foreach ($employees as $employee) {
            if ((int)$employee['id'] === $mainManagerId) {
                $hasMainEmployee = true;
                break;
            }
        }
        if (!$hasMainEmployee) {
            $employees[count($employees)] = array(
                'id' => $mainManagerId,
                'name' => isset($mainManager['name']) ? $mainManager['name'] : '',
                'department' => isset($mainManager['department']) ? $mainManager['department'] : '',
                'position' => isset($mainManager['position']) ? $mainManager['position'] : ''
            );
        }
    }
}

$unitPrices = array();
try {
    $unitSql = "SELECT * FROM cpms_project_unit_prices WHERE project_id = :pid";
    if (cpms_contract_change_column_exists($pdo, 'cpms_project_unit_prices', 'is_active')) {
        $unitSql .= " AND (is_active = 1 OR is_active IS NULL)";
    }
    if (cpms_contract_change_column_exists($pdo, 'cpms_project_unit_prices', 'is_current')) {
        $unitSql .= " AND (is_current = 1 OR is_current IS NULL)";
    }
    $unitSql .= cpms_contract_change_column_exists($pdo, 'cpms_project_unit_prices', 'import_order') ? " ORDER BY COALESCE(import_order, id) ASC, id ASC" : " ORDER BY id ASC";
    $stUnits = $pdo->prepare($unitSql);
    $stUnits->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stUnits->execute();
    $unitPrices = $stUnits->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($unitPrices)) $unitPrices = array();
} catch (Exception $e) {
    $unitPrices = array();
}

$contractVersions = array();
try {
    if (cpms_contract_change_table_exists($pdo, 'cpms_contract_versions')) {
        $stVersions = $pdo->prepare("SELECT * FROM cpms_contract_versions WHERE project_id = :pid ORDER BY is_current DESC, id DESC");
        $stVersions->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stVersions->execute();
        $tmpVersions = $stVersions->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($tmpVersions)) $contractVersions = $tmpVersions;
    }
} catch (Exception $e) {
    $contractVersions = array();
}

$additionalWorks = array();
try {
    if (cpms_contract_change_table_exists($pdo, 'cpms_contract_additional_works')) {
        $stAdditional = $pdo->prepare("SELECT * FROM cpms_contract_additional_works WHERE project_id = :pid ORDER BY id DESC LIMIT 30");
        $stAdditional->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stAdditional->execute();
        $tmpAdditional = $stAdditional->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($tmpAdditional)) $additionalWorks = $tmpAdditional;
    }
} catch (Exception $e) {
    $additionalWorks = array();
}

$progressBillings = array();
$progressRecognizedTotal = 0.0;
$progressRequestedTotal = 0.0;
try {
    if (cpms_contract_change_table_exists($pdo, 'cpms_progress_billings')) {
        if (cpms_contract_change_column_exists($pdo, 'cpms_progress_billings', 'requested_amount') && cpms_contract_change_column_exists($pdo, 'cpms_progress_billings', 'recognized_amount')) {
            $stRepair = $pdo->prepare("UPDATE cpms_progress_billings
                                           SET recognized_amount = requested_amount
                                         WHERE project_id = :pid
                                           AND (recognized_amount IS NULL OR recognized_amount = 0)
                                           AND requested_amount > 0");
            $stRepair->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stRepair->execute();
        }
        if (cpms_contract_change_column_exists($pdo, 'cpms_progress_billings', 'progress_date') && cpms_contract_change_column_exists($pdo, 'cpms_progress_billings', 'created_at')) {
            $stDateRepair = $pdo->prepare("UPDATE cpms_progress_billings
                                              SET progress_date = DATE(created_at)
                                            WHERE project_id = :pid
                                              AND progress_date IS NULL
                                              AND created_at IS NOT NULL");
            $stDateRepair->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stDateRepair->execute();
        }
        $stProgress = $pdo->prepare("SELECT * FROM cpms_progress_billings WHERE project_id = :pid ORDER BY progress_date ASC, id ASC");
        $stProgress->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stProgress->execute();
        $tmpProgress = $stProgress->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($tmpProgress)) {
            $progressBillings = $tmpProgress;
            foreach ($progressBillings as $progressRow) {
                $recognizedOne = isset($progressRow['recognized_amount']) ? (float)$progressRow['recognized_amount'] : 0.0;
                $requestedOne = isset($progressRow['requested_amount']) ? (float)$progressRow['requested_amount'] : 0.0;
                if ($recognizedOne <= 0 && $requestedOne > 0) $recognizedOne = $requestedOne;
                if ($requestedOne <= 0 && $recognizedOne > 0) $requestedOne = $recognizedOne;
                $progressRecognizedTotal += $recognizedOne;
                $progressRequestedTotal += $requestedOne;
            }
        }
    }
} catch (Exception $e) {
    $progressBillings = array();
}

$contractChangeLogs = array();
try {
    if (cpms_contract_change_table_exists($pdo, 'cpms_contract_change_logs')) {
        $stLogs = $pdo->prepare("SELECT * FROM cpms_contract_change_logs WHERE project_id = :pid ORDER BY id DESC LIMIT 30");
        $stLogs->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stLogs->execute();
        $tmpLogs = $stLogs->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($tmpLogs)) $contractChangeLogs = $tmpLogs;
    }
} catch (Exception $e) {
    $contractChangeLogs = array();
}

$flash = flash_get();

$cpmsRoot = dirname(dirname(dirname(__DIR__)));
$contractDir = $cpmsRoot . '/storage/contracts/' . $projectId;
$contractMetaFile = $contractDir . '/meta.json';
$hasContract = false;
$contractMeta = array();
if (is_file($contractMetaFile)) {
    $json = @file_get_contents($contractMetaFile);
    $tmp = @json_decode($json, true);
    if (is_array($tmp) && isset($tmp['stored_name'])) {
        $stored = basename((string)$tmp['stored_name']);
        if (is_file($contractDir . '/' . $stored)) {
            $hasContract = true;
            $contractMeta = $tmp;
        }
    }
}
?>

<style>
.cpms-change-badge{display:inline-flex;align-items:center;margin:2px 4px 2px 0;padding:4px 8px;border-radius:999px;background:#fef3c7;color:#111827;border:1px solid #f59e0b;font-weight:700;white-space:nowrap}
</style>

<div class="flex items-start justify-between gap-3 mb-6">
    <div>
        <div class="text-sm text-gray-500">프로젝트 상세</div>
        <h2 class="text-2xl font-extrabold text-gray-900"><?php echo h($project['name']); ?></h2>
        <div class="text-sm text-gray-600 mt-1">
            <?php echo h((string)$project['client']); ?>
            <?php if ((string)$project['client'] !== '' && (string)$project['contractor'] !== ''): ?> / <?php endif; ?>
            <?php echo h((string)$project['contractor']); ?>
            <?php if ((((string)$project['client'] !== '') || ((string)$project['contractor'] !== '')) && (string)$project['location'] !== ''): ?> / <?php endif; ?>
            <?php echo h((string)$project['location']); ?>
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
        <a href="<?php echo h(base_url() . '/?r=project/detail&id=' . $projectId . '&edit=1#project-edit-form'); ?>"
           class="px-5 py-3 rounded-2xl bg-gradient-to-r from-amber-400 to-orange-500 text-white font-extrabold shadow-lg hover:shadow-xl transition">
            프로젝트 수정하기
        </a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type'] === 'success') ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<?php if ($editMode): ?>
<div id="project-edit-form" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-gray-100">
        <div class="font-extrabold text-gray-900">프로젝트 기본정보 수정</div>
        <div class="text-sm text-gray-500 mt-1">상세보기 화면에서 프로젝트 정보와 담당자를 바로 수정합니다.</div>
    </div>
    <form method="post" action="<?php echo h(base_url()); ?>/?r=project_edit_save" class="p-6 space-y-5">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <div class="text-sm font-bold text-gray-700 mb-1">프로젝트명 *</div>
                <input name="name" required value="<?php echo h((string)$project['name']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
                <div class="text-sm font-bold text-gray-700 mb-1">발주처</div>
                <input name="client" value="<?php echo h((string)$project['client']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
                <div class="text-sm font-bold text-gray-700 mb-1">시공사</div>
                <input name="contractor" value="<?php echo h((string)$project['contractor']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
                <div class="text-sm font-bold text-gray-700 mb-1">상태</div>
                <select name="status" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
                    <option value="계약중" <?php echo ((string)$project['status'] === '계약중') ? 'selected' : ''; ?>>계약중</option>
                    <option value="대기중" <?php echo ((string)$project['status'] === '대기중') ? 'selected' : ''; ?>>대기중</option>
                    <option value="진행중" <?php echo ((string)$project['status'] === '진행중') ? 'selected' : ''; ?>>진행중</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <div class="text-sm font-bold text-gray-700 mb-1">현장 위치</div>
                <input name="location" value="<?php echo h((string)$project['location']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
                <div class="text-sm font-bold text-gray-700 mb-1">공사 시작일</div>
                <input type="date" name="start_date" value="<?php echo h((string)$project['start_date']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div>
                <div class="text-sm font-bold text-gray-700 mb-1">공사 종료일</div>
                <input type="date" name="end_date" value="<?php echo h((string)$project['end_date']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div class="md:col-span-2">
                <div class="text-sm font-bold text-gray-700 mb-1">계약금액</div>
                <input name="contract_amount" value="<?php echo h((string)$project['contract_amount']); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
            </div>
            <div class="md:col-span-2">
                <div class="text-sm font-bold text-gray-700 mb-1">공사 담당자(메인) *</div>
                <select name="main_manager_id" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
                    <option value="">선택하세요</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?php echo (int)$employee['id']; ?>" <?php echo ((int)$employee['id'] === $mainManagerId) ? 'selected' : ''; ?>>
                            <?php echo h((string)$employee['department'] . ' / ' . (string)$employee['position'] . ' / ' . (string)$employee['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <div class="text-sm font-bold text-gray-700 mb-1">부담당자(서브)</div>
                <select name="sub_manager_ids[]" multiple class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none" style="min-height:120px;">
                    <?php foreach ($employees as $employee): ?>
                        <?php $selectedSub = in_array((int)$employee['id'], $subManagerIds, true); ?>
                        <option value="<?php echo (int)$employee['id']; ?>" <?php echo $selectedSub ? 'selected' : ''; ?>>
                            <?php echo h((string)$employee['department'] . ' / ' . (string)$employee['position'] . ' / ' . (string)$employee['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="text-xs text-gray-500 mt-2">PC에서는 Ctrl 또는 Cmd를 누른 채 여러 명을 선택할 수 있습니다.</div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="<?php echo h(base_url()); ?>/?r=project/detail&id=<?php echo (int)$projectId; ?>"
               class="px-5 py-3 rounded-2xl border border-gray-200 bg-white font-extrabold">
                취소
            </a>
            <button type="submit" class="px-6 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">
                수정 저장
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <div class="font-extrabold text-gray-900">계약서 파일 보관</div>
                <div class="text-xs text-gray-500 mt-1">PDF/HWP/DOC/JPG 파일은 보관만 됩니다.</div>
            </div>
            <?php if ($hasContract): ?>
            <a href="<?php echo h(base_url()); ?>/?r=project/contract_download&id=<?php echo (int)$projectId; ?>"
               class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold shadow hover:shadow-lg transition">
                다운로드
            </a>
            <?php endif; ?>
        </div>
        <div class="p-6">
            <?php if ($hasContract): ?>
                <div class="text-sm text-gray-700 mb-4">
                    <div class="font-extrabold text-gray-900">보관 중인 파일</div>
                    <div class="text-gray-600 mt-1"><?php echo h(isset($contractMeta['original_name']) ? $contractMeta['original_name'] : 'contract'); ?></div>
                    <?php if (isset($contractMeta['uploaded_at']) && $contractMeta['uploaded_at']): ?>
                        <div class="text-xs text-gray-500 mt-1">업로드일: <?php echo h($contractMeta['uploaded_at']); ?></div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="text-sm text-gray-600 mb-4">아직 보관된 계약서 파일이 없습니다.</div>
            <?php endif; ?>

            <form method="post" action="<?php echo h(base_url()); ?>/?r=project/contract_upload" enctype="multipart/form-data" class="flex flex-col md:flex-row md:items-center gap-3">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                <input type="hidden" name="upload_mode" value="contract_only">
                <input type="file" name="contract_file" accept=".pdf,.hwp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png" class="flex-1 px-4 py-3 rounded-2xl border border-gray-200 bg-white" required>
                <button type="submit" class="px-6 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-extrabold shadow">
                    업로드
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <div class="font-extrabold text-gray-900">당초 내역서 업로드</div>
            <div class="text-xs text-gray-500 mt-1">CPMS 표준 양식 xlsx만 등록합니다. 현재 적용 내역서가 있으면 덮어쓰지 않습니다.</div>
        </div>
        <div class="p-6">
            <form method="post" action="<?php echo h(base_url()); ?>/?r=project/contract_upload" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                <input type="hidden" name="upload_mode" value="unit_price_original">
                <input type="file" name="contract_file" accept=".xlsx" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white" required>
                <button type="submit" class="px-6 py-3 rounded-2xl bg-gray-900 text-white font-extrabold shadow">
                    당초 내역서 저장
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <div class="font-extrabold text-gray-900">변경 단가내역서 업로드</div>
            <div class="text-xs text-gray-500 mt-1">변경된 엑셀 단가내역서를 업로드하면 공무 단가표와 공사 작업 탭의 내역서 항목이 갱신됩니다. 기존 공정표 연결을 유지하기 위해 기존 단가 ID는 최대한 유지합니다.</div>
        </div>
        <div class="p-6">
            <form method="post" action="<?php echo h(base_url()); ?>/?r=project/contract_change_preview" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                <input type="hidden" name="upload_mode" value="unit_price_update">
                <input type="file" name="contract_file" accept=".xlsx" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white" required>
                <button type="submit" class="px-6 py-3 rounded-2xl bg-gradient-to-r from-amber-500 to-orange-500 text-white font-extrabold shadow">
                    변경내용 미리보기
                </button>
            </form>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <div class="font-extrabold text-gray-900">내역서 버전 이력</div>
                <div class="text-xs text-gray-500 mt-1">당초/변경계약/추가공사 파일을 덮어쓰지 않고 보존합니다.</div>
            </div>
            <div class="text-xs text-gray-500">총 <?php echo count($contractVersions); ?>건</div>
        </div>
        <div class="p-6">
            <?php if (count($contractVersions) === 0): ?>
                <div class="text-sm text-gray-600">등록된 내역서 버전이 없습니다.</div>
            <?php else: ?>
                <div class="overflow-x-auto rounded-2xl border border-gray-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-3 py-2 text-left font-extrabold">구분</th>
                            <th class="px-3 py-2 text-left font-extrabold">상태</th>
                            <th class="px-3 py-2 text-left font-extrabold">파일</th>
                            <th class="px-3 py-2 text-left font-extrabold">적용일</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                        <?php foreach ($contractVersions as $versionRow): ?>
                            <tr>
                                <td class="px-3 py-2 font-bold text-gray-900"><?php echo h(isset($versionRow['title']) ? $versionRow['title'] : ''); ?></td>
                                <td class="px-3 py-2">
                                    <?php if (isset($versionRow['is_current']) && (int)$versionRow['is_current'] === 1): ?>
                                        <span class="px-2 py-1 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 text-xs font-extrabold">현재 적용</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 rounded-xl bg-gray-50 text-gray-600 border border-gray-100 text-xs font-bold">보존</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-gray-700"><?php echo h(isset($versionRow['original_name']) ? $versionRow['original_name'] : ''); ?></td>
                                <td class="px-3 py-2 text-gray-600"><?php echo h(isset($versionRow['applied_at']) ? $versionRow['applied_at'] : ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <div class="font-extrabold text-gray-900">추가공사 등록</div>
            <div class="text-xs text-gray-500 mt-1">승인 상태와 관련 품의/요청을 별도로 관리합니다.</div>
        </div>
        <div class="p-6">
            <form method="post" action="<?php echo h(base_url()); ?>/?r=project/additional_work_save" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                <input name="title" required maxlength="255" class="px-4 py-3 rounded-2xl border border-gray-200" placeholder="추가공사명">
                <input type="date" name="occurred_on" class="px-4 py-3 rounded-2xl border border-gray-200">
                <input name="request_ref" maxlength="255" class="px-4 py-3 rounded-2xl border border-gray-200" placeholder="관련 품의/요청">
                <select name="status" class="px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                    <?php foreach (array('승인 전','승인 완료','계약 반영 완료','보류','반려') as $statusOption): ?>
                        <option value="<?php echo h($statusOption); ?>"><?php echo h($statusOption); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="file" name="attachment_file" accept=".pdf,.hwp,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="md:col-span-2 px-4 py-3 rounded-2xl border border-gray-200 bg-white">
                <textarea name="remark" rows="2" class="md:col-span-2 px-4 py-3 rounded-2xl border border-gray-200" placeholder="비고"></textarea>
                <button type="submit" class="md:col-span-2 px-6 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">
                    추가공사 등록
                </button>
            </form>

            <?php if (count($additionalWorks) > 0): ?>
                <div class="mt-5 space-y-2">
                    <?php foreach ($additionalWorks as $additionalRow): ?>
                        <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="font-extrabold text-gray-900"><?php echo h(isset($additionalRow['title']) ? $additionalRow['title'] : ''); ?></div>
                                <span class="px-2 py-1 rounded-xl bg-amber-50 text-amber-800 border border-amber-100 text-xs font-extrabold"><?php echo h(isset($additionalRow['status']) ? $additionalRow['status'] : ''); ?></span>
                            </div>
                            <div class="mt-1 text-xs text-gray-500"><?php echo h(isset($additionalRow['occurred_on']) ? $additionalRow['occurred_on'] : ''); ?> <?php echo h(isset($additionalRow['request_ref']) ? $additionalRow['request_ref'] : ''); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <div>
            <div class="font-extrabold text-gray-900">기성관리</div>
            <div class="text-xs text-gray-500 mt-1">기성금액을 확정매출로 저장하고 월별 집계에 반영합니다.</div>
        </div>
        <div class="text-right text-sm">
            <div class="font-extrabold text-gray-900">누적 확정매출 <?php echo cpms_format_amount0($progressRecognizedTotal); ?></div>
            <div class="text-xs text-gray-500">누적 기성금액 <?php echo cpms_format_amount0($progressRequestedTotal); ?></div>
        </div>
    </div>
    <div class="p-6">
        <form method="post" action="<?php echo h(base_url()); ?>/?r=project/progress_save" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-6 gap-3">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
            <input type="hidden" name="action" value="create">
            <input name="round_label" required maxlength="100" class="px-4 py-3 rounded-2xl border border-gray-200" placeholder="1회 기성">
            <input type="date" name="progress_date" required class="px-4 py-3 rounded-2xl border border-gray-200">
            <input name="recognized_amount" required class="px-4 py-3 rounded-2xl border border-gray-200" placeholder="기성금액(확정매출)">
            <input type="file" name="attachment_file" accept=".pdf,.hwp,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="md:col-span-2 px-4 py-3 rounded-2xl border border-gray-200 bg-white">
            <textarea name="remark" rows="2" class="md:col-span-5 px-4 py-3 rounded-2xl border border-gray-200" placeholder="비고"></textarea>
            <button type="submit" class="px-6 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">등록</button>
        </form>
        <?php if (count($progressBillings) > 0): ?>
            <div class="mt-5 overflow-x-auto rounded-2xl border border-gray-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-extrabold">회차</th>
                        <th class="px-3 py-2 text-left font-extrabold">기성일자</th>
                        <th class="px-3 py-2 text-right font-extrabold">기성금액(확정매출)</th>
                        <th class="px-3 py-2 text-left font-extrabold">첨부</th>
                        <th class="px-3 py-2 text-left font-extrabold">비고</th>
                        <th class="px-3 py-2 text-center font-extrabold">관리</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php foreach ($progressBillings as $progressRow): ?>
                        <?php $progressFormId = 'progressUpdateForm' . (int)$progressRow['id']; ?>
                        <?php
                        $progressDisplayAmount = isset($progressRow['recognized_amount']) ? (float)$progressRow['recognized_amount'] : 0.0;
                        if ($progressDisplayAmount <= 0 && isset($progressRow['requested_amount'])) $progressDisplayAmount = (float)$progressRow['requested_amount'];
                        ?>
                        <tr>
                            <td class="px-3 py-2">
                                <input form="<?php echo h($progressFormId); ?>" name="round_label" required maxlength="100" class="w-28 px-2 py-1 rounded-lg border border-gray-200 font-bold" value="<?php echo h(isset($progressRow['round_label']) ? $progressRow['round_label'] : ''); ?>">
                            </td>
                            <td class="px-3 py-2">
                                <input form="<?php echo h($progressFormId); ?>" type="date" name="progress_date" required class="w-36 px-2 py-1 rounded-lg border border-gray-200" value="<?php echo h(isset($progressRow['progress_date']) ? $progressRow['progress_date'] : ''); ?>">
                            </td>
                            <td class="px-3 py-2 text-right">
                                <input form="<?php echo h($progressFormId); ?>" name="recognized_amount" required class="w-36 px-2 py-1 rounded-lg border border-gray-200 text-right" value="<?php echo h(cpms_format_amount0($progressDisplayAmount)); ?>">
                            </td>
                            <td class="px-3 py-2">
                                <?php if (isset($progressRow['attachment_stored_path']) && trim((string)$progressRow['attachment_stored_path']) !== ''): ?>
                                    <a class="text-blue-700 font-bold" href="<?php echo h(base_url()); ?>/?r=project/progress_download&id=<?php echo (int)$progressRow['id']; ?>">다운로드</a>
                                <?php endif; ?>
                                <input form="<?php echo h($progressFormId); ?>" type="file" name="attachment_file" accept=".pdf,.hwp,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="mt-1 block w-40 text-xs">
                            </td>
                            <td class="px-3 py-2">
                                <input form="<?php echo h($progressFormId); ?>" name="remark" class="w-44 px-2 py-1 rounded-lg border border-gray-200" value="<?php echo h(isset($progressRow['remark']) ? $progressRow['remark'] : ''); ?>">
                            </td>
                            <td class="px-3 py-2">
                                <form id="<?php echo h($progressFormId); ?>" method="post" action="<?php echo h(base_url()); ?>/?r=project/progress_save" enctype="multipart/form-data" class="flex items-center justify-center gap-1">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                                    <input type="hidden" name="progress_id" value="<?php echo (int)$progressRow['id']; ?>">
                                    <button type="submit" name="action" value="update" class="px-3 py-1 rounded-lg bg-blue-600 text-white text-xs font-bold">수정</button>
                                    <button type="submit" name="action" value="delete" formnovalidate class="px-3 py-1 rounded-lg bg-red-600 text-white text-xs font-bold" onclick="return confirm('삭제할까요?');">삭제</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (count($contractChangeLogs) > 0): ?>
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <div class="font-extrabold text-gray-900">최근 변경계약 변경내용</div>
        <div class="text-xs text-gray-500">최근 <?php echo (int)count($contractChangeLogs); ?>건</div>
    </div>
    <div class="p-6 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
            <tr class="text-left text-gray-600">
                <th class="px-3 py-2 font-extrabold">변경내용</th>
                <th class="px-3 py-2 font-extrabold">품명</th>
                <th class="px-3 py-2 font-extrabold">규격</th>
                <th class="px-3 py-2 font-extrabold">단위</th>
                <th class="px-3 py-2 font-extrabold">변경일</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($contractChangeLogs as $logRow): ?>
                <tr>
                    <td class="px-3 py-2"><?php echo cpms_contract_change_render_badges(cpms_contract_change_badges_from_log($logRow)); ?></td>
                    <td class="px-3 py-2"><?php echo h(isset($logRow['item_name']) ? $logRow['item_name'] : ''); ?></td>
                    <td class="px-3 py-2"><?php echo h(isset($logRow['spec']) ? $logRow['spec'] : ''); ?></td>
                    <td class="px-3 py-2"><?php echo h(isset($logRow['unit']) ? $logRow['unit'] : ''); ?></td>
                    <td class="px-3 py-2"><?php echo h(isset($logRow['created_at']) ? $logRow['created_at'] : ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <div class="font-extrabold text-gray-900">기본 정보</div>
        </div>
        <div class="p-6 text-sm text-gray-700 space-y-3">
            <div class="flex items-center justify-between gap-2"><div class="text-gray-500">발주처</div><div class="font-bold"><?php echo h((string)$project['client']); ?></div></div>
            <div class="flex items-center justify-between gap-2"><div class="text-gray-500">시공사</div><div class="font-bold"><?php echo h((string)$project['contractor']); ?></div></div>
            <div class="flex items-center justify-between gap-2"><div class="text-gray-500">현장 위치</div><div class="font-bold text-right"><?php echo h((string)$project['location']); ?></div></div>
            <div class="flex items-center justify-between gap-2"><div class="text-gray-500">기간</div><div class="font-bold"><?php echo h((string)$project['start_date']); ?> ~ <?php echo h((string)$project['end_date']); ?></div></div>
            <div class="flex items-center justify-between gap-2"><div class="text-gray-500">상태</div><div class="font-bold"><?php echo h((string)$project['status']); ?></div></div>
            <div class="flex items-center justify-between gap-2"><div class="text-gray-500">계약금액</div><div class="font-bold"><?php echo cpms_format_amount0($project['contract_amount']); ?></div></div>
        </div>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <div class="font-extrabold text-gray-900">담당자</div>
        </div>
        <div class="p-6 text-sm text-gray-700 space-y-3">
            <div>
                <div class="text-gray-500 font-bold">메인</div>
                <div class="mt-1 font-extrabold text-gray-900"><?php echo $mainManager ? h((string)$mainManager['name']) : '미지정'; ?></div>
            </div>
            <div>
                <div class="text-gray-500 font-bold">서브</div>
                <div class="mt-1 space-y-1">
                    <?php if (count($subManagers) === 0): ?>
                        <div class="text-gray-500">미지정</div>
                    <?php else: ?>
                        <?php foreach ($subManagers as $subManager): ?>
                            <div class="font-bold text-gray-900"><?php echo h((string)$subManager['name']); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <div class="font-extrabold text-gray-900">내역 안내</div>
        </div>
        <div class="p-6 text-sm text-gray-700">
            <div class="text-gray-600">변경 단가내역서를 적용하면 아래 단가표와 공사 작업 탭, 공정표에서 최신 항목명/기본수량/단가를 읽어 표시합니다.</div>
        </div>
    </div>
</div>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <div class="font-extrabold text-gray-900">단가 내역표</div>
        <div class="text-xs text-gray-500">총 <?php echo is_array($unitPrices) ? count($unitPrices) : 0; ?>건</div>
    </div>

    <div class="p-6">
        <?php if (!is_array($unitPrices) || count($unitPrices) === 0): ?>
            <div class="text-sm text-gray-600">단가 내역이 없습니다.</div>
        <?php else: ?>
            <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-100">
                    <tr class="text-left text-gray-600">
                        <th class="px-3 py-2 font-extrabold">공종그룹</th>
                        <th class="px-3 py-2 font-extrabold">세부공종</th>
                        <th class="px-3 py-2 font-extrabold">위치</th>
                        <th class="px-3 py-2 font-extrabold">품명</th>
                        <th class="px-3 py-2 font-extrabold">규격</th>
                        <th class="px-3 py-2 font-extrabold">단위</th>
                        <th class="px-3 py-2 font-extrabold">기본수량</th>
                        <th class="px-3 py-2 font-extrabold">자재단가</th>
                        <th class="px-3 py-2 font-extrabold">노무단가</th>
                        <th class="px-3 py-2 font-extrabold">경비단가</th>
                        <th class="px-3 py-2 font-extrabold">안전단가</th>
                        <th class="px-3 py-2 font-extrabold">합계단가</th>
                        <th class="px-3 py-2 font-extrabold">안전항목</th>
                        <th class="px-3 py-2 font-extrabold">비고</th>
                        <th class="px-3 py-2 font-extrabold">관리</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php foreach ($unitPrices as $row): ?>
                        <tr>
                            <td class="px-3 py-2"><?php echo h(isset($row['trade_group']) ? (string)$row['trade_group'] : ''); ?></td>
                            <td class="px-3 py-2"><?php echo h(isset($row['sub_trade']) ? (string)$row['sub_trade'] : ''); ?></td>
                            <td class="px-3 py-2"><?php echo h(isset($row['location_name']) ? (string)$row['location_name'] : ''); ?></td>
                            <td class="px-3 py-2"><?php echo h((string)$row['item_name']); ?></td>
                            <td class="px-3 py-2"><?php echo h((string)$row['spec']); ?></td>
                            <td class="px-3 py-2"><?php echo h((string)$row['unit']); ?></td>
                            <td class="px-3 py-2"><?php echo cpms_format_qty0($row['qty']); ?></td>
                            <td class="px-3 py-2"><?php echo cpms_format_price1(isset($row['material_unit_price']) ? $row['material_unit_price'] : ''); ?></td>
                            <td class="px-3 py-2"><?php echo cpms_format_price1(isset($row['labor_unit_price']) ? $row['labor_unit_price'] : ''); ?></td>
                            <td class="px-3 py-2"><?php echo cpms_format_price1(isset($row['expense_unit_price']) ? $row['expense_unit_price'] : ''); ?></td>
                            <td class="px-3 py-2"><?php echo cpms_format_price1(isset($row['safety_unit_price']) ? $row['safety_unit_price'] : ''); ?></td>
                            <td class="px-3 py-2"><?php echo cpms_format_price1(cpms_project_detail_unit_price_value($row)); ?></td>
                            <td class="px-3 py-2">
                                <form method="post" action="<?php echo h(base_url()); ?>/?r=project/unit_price_toggle_safety" style="margin:0;">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" class="px-3 py-1 rounded-xl border <?php echo ((int)$row['is_safety'] === 1) ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-gray-50 border-gray-200 text-gray-700'; ?> font-bold">
                                        <?php echo ((int)$row['is_safety'] === 1) ? '안전항목' : '일반항목'; ?>
                                    </button>
                                </form>
                            </td>
                            <td class="px-3 py-2"><?php echo h((string)$row['remark']); ?></td>
                            <td class="px-3 py-2">
                                <form method="post" action="<?php echo h(base_url()); ?>/?r=project/unit_price_delete" style="margin:0;">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
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
        <?php endif; ?>
    </div>
</div>
