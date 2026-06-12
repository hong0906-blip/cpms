<?php
/**
 * safety/index.php
 * - 프로젝트 선택형 안전/보건 화면
 * - PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/safety_cost_helper.php';

$flash = flash_get();
$pdo = Db::pdo();

$safetyProjects = array();
$selectedProjectId = 0;
if (isset($_GET['pid'])) {
    $selectedProjectId = (int)$_GET['pid'];
} else if (isset($_GET['safety_pid'])) {
    $selectedProjectId = (int)$_GET['safety_pid'];
}

$activeSafetyTab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'safety_cost';
if ($activeSafetyTab !== 'safety_cost' && $activeSafetyTab !== 'incidents') {
    $activeSafetyTab = 'safety_cost';
}

$selectedProject = null;
$projectAccessDenied = false;
$canManageSafetyCost = false;
$canManageIncident = false;
$safetyCostItems = array();
$safetyCostSummary = null;
$editSafetyCostId = isset($_GET['safety_cost_edit']) ? trim((string)$_GET['safety_cost_edit']) : '';
$editSafetyCost = null;
$safetyIncidents = array();
$safetyLoadError = '';

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
        if (!is_array($checklistData)) $checklistData = array();
    } catch (Exception $e) {
        $checklistData = array();
    }
}

if ($pdo) {
    $safetyProjects = cpms_safety_cost_project_rows_for_user($pdo);
    if ($selectedProjectId <= 0 && count($safetyProjects) > 0) {
        $selectedProjectId = (int)$safetyProjects[0]['id'];
    }
    foreach ($safetyProjects as $projectRow) {
        if ((int)$projectRow['id'] === $selectedProjectId) {
            $selectedProject = $projectRow;
            break;
        }
    }
    if ($selectedProjectId > 0 && !is_array($selectedProject)) {
        $projectAccessDenied = true;
        $selectedProjectId = 0;
    }

    if ($selectedProjectId > 0 && is_array($selectedProject)) {
        $canManageSafetyCost = cpms_safety_cost_user_can_manage_project($pdo, $selectedProjectId);
        $canManageIncident = cpms_safety_incident_user_can_manage_project($pdo, $selectedProjectId);
        $safetyCostItems = cpms_safety_cost_project_items($selectedProjectId);
        $safetyCostSummary = cpms_safety_cost_summary($pdo, $selectedProjectId);
        if ($canManageSafetyCost && $editSafetyCostId !== '') {
            foreach ($safetyCostItems as $row) {
                if (isset($row['id']) && (string)$row['id'] === $editSafetyCostId) {
                    $editSafetyCost = $row;
                    break;
                }
            }
        }

        try {
            $st = $pdo->prepare("SELECT i.*, p.name AS project_name
                FROM cpms_safety_incidents i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                WHERE i.project_id = :pid
                ORDER BY i.id DESC
                LIMIT 100");
            $st->bindValue(':pid', $selectedProjectId, PDO::PARAM_INT);
            $st->execute();
            $safetyIncidents = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            if (!is_array($safetyIncidents)) $safetyIncidents = array();
        } catch (Exception $e) {
            $safetyLoadError = $e->getMessage();
            $safetyIncidents = array();
        }
    }
} else {
    $safetyLoadError = 'DB 연결이 없습니다.';
}

$selectedProjectName = is_array($selectedProject) && isset($selectedProject['name']) ? (string)$selectedProject['name'] : '';
$baseSafetyUrl = base_url() . '/?r=safety_home&pid=' . (int)$selectedProjectId;
?>

<div class="bg-gradient-to-r from-rose-600 to-orange-500 rounded-3xl p-8 text-white shadow-xl shadow-rose-500/20 mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-start gap-4">
            <div class="p-4 bg-white/20 rounded-3xl border border-white/20">
                <i data-lucide="shield" class="w-8 h-8 text-yellow-200"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-3xl font-extrabold">안전/보건</h2>
                <p class="text-rose-100 text-lg mt-2">프로젝트별 안전관리비와 안전사고를 관리합니다.</p>
            </div>
        </div>
    </div>
</div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type'] === 'success') ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<?php if (!$pdo): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>
<?php else: ?>
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
        <form method="get" action="" class="flex flex-col md:flex-row md:items-end gap-3">
            <input type="hidden" name="r" value="safety_home">
            <div class="flex-1 min-w-0">
                <label class="text-sm font-bold text-gray-700">프로젝트 선택</label>
                <select name="pid" class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200">
                    <?php if (count($safetyProjects) === 0): ?>
                        <option value="">조회 가능한 프로젝트 없음</option>
                    <?php else: ?>
                        <?php foreach ($safetyProjects as $projectOption): ?>
                            <option value="<?php echo (int)$projectOption['id']; ?>" <?php echo ((int)$projectOption['id'] === $selectedProjectId) ? 'selected' : ''; ?>>
                                <?php echo h(isset($projectOption['name']) ? $projectOption['name'] : ('프로젝트 #' . (int)$projectOption['id'])); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="md:w-56">
                <label class="text-sm font-bold text-gray-700">탭</label>
                <select name="tab" class="w-full mt-1 px-4 py-3 rounded-2xl border border-gray-200">
                    <option value="safety_cost" <?php echo ($activeSafetyTab === 'safety_cost') ? 'selected' : ''; ?>>안전관리비 사용내역</option>
                    <option value="incidents" <?php echo ($activeSafetyTab === 'incidents') ? 'selected' : ''; ?>>안전사고</option>
                </select>
            </div>
            <button type="submit" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-yellow-500 to-orange-500 text-white font-extrabold shadow-lg">보기</button>
        </form>
        <?php if ($selectedProjectName !== ''): ?>
            <div class="mt-4 text-xs text-gray-600">선택 프로젝트: <b><?php echo h($selectedProjectName); ?></b></div>
        <?php else: ?>
            <div class="mt-4 text-sm text-gray-600">프로젝트를 선택하면 안전관리비 사용내역과 안전사고를 확인할 수 있습니다.</div>
        <?php endif; ?>
    </div>

    <?php if ($projectAccessDenied): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 font-bold">해당 프로젝트의 안전 정보를 조회할 권한이 없습니다.</div>
    <?php elseif (count($safetyProjects) === 0): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-6 text-gray-600">조회 가능한 프로젝트가 없습니다.</div>
    <?php elseif ($selectedProjectId <= 0 || !is_array($selectedProject)): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-6 text-gray-600">프로젝트를 선택하면 안전관리비 사용내역과 안전사고를 확인할 수 있습니다.</div>
    <?php else: ?>
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="<?php echo h($baseSafetyUrl . '&tab=safety_cost'); ?>"
               class="px-4 py-2 rounded-2xl border font-extrabold <?php echo ($activeSafetyTab === 'safety_cost') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-900 border-gray-200 hover:bg-gray-50'; ?>">안전관리비 사용내역</a>
            <a href="<?php echo h($baseSafetyUrl . '&tab=incidents'); ?>"
               class="px-4 py-2 rounded-2xl border font-extrabold <?php echo ($activeSafetyTab === 'incidents') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-900 border-gray-200 hover:bg-gray-50'; ?>">안전사고</a>
        </div>

        <?php if ($activeSafetyTab === 'safety_cost'): ?>
            <div id="safety-cost-section" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
                <div class="mb-4">
                    <h3 class="text-xl font-extrabold text-gray-900">안전관리비 사용내역</h3>
                    <div class="text-sm text-gray-600 mt-1">안전관리비 원본 입력/수정/PDF 업로드는 이 안전섹션에서만 처리합니다.</div>
                </div>

                <?php if (is_array($safetyCostSummary)): ?>
                    <?php
                    $summaryLimitRate = isset($safetyCostSummary['limit_use_rate']) ? (float)$safetyCostSummary['limit_use_rate'] : 0.0;
                    $summaryTone = 'border-emerald-200 bg-emerald-50 text-emerald-700';
                    $summaryMessage = '안전관리비 사용 현황이 정상 범위입니다.';
                    if ($summaryLimitRate >= 100.0) {
                        $summaryTone = 'border-red-200 bg-red-50 text-red-700';
                        $summaryMessage = '110% 사용가능한도에 도달했거나 초과했습니다.';
                    } else if ($summaryLimitRate >= 80.0) {
                        $summaryTone = 'border-amber-200 bg-amber-50 text-amber-700';
                        $summaryMessage = '110% 사용가능한도의 80% 이상을 사용했습니다.';
                    }
                    ?>
                    <div class="mb-5 rounded-2xl border <?php echo h($summaryTone); ?> p-4">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-3">
                            <div>
                                <div class="text-sm font-extrabold">안전관리비 요약 현황</div>
                                <div class="text-xs mt-1"><?php echo h($summaryMessage); ?></div>
                            </div>
                            <div class="text-xs font-bold">선택 프로젝트 기준</div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                            <div class="rounded-xl border border-white/70 bg-white/80 p-3 min-w-0">
                                <div class="text-xs text-gray-500">안전관리비 총액</div>
                                <div class="mt-1 text-lg font-extrabold text-gray-900" style="overflow-wrap:anywhere;"><?php echo h(cpms_safety_cost_money_label($safetyCostSummary['contract_total'])); ?></div>
                            </div>
                            <div class="rounded-xl border border-white/70 bg-white/80 p-3 min-w-0">
                                <div class="text-xs text-gray-500">110% 사용가능한도</div>
                                <div class="mt-1 text-lg font-extrabold text-gray-900" style="overflow-wrap:anywhere;"><?php echo h(cpms_safety_cost_money_label($safetyCostSummary['limit_110'])); ?></div>
                            </div>
                            <div class="rounded-xl border border-white/70 bg-white/80 p-3 min-w-0">
                                <div class="text-xs text-gray-500">현재 사용금액</div>
                                <div class="mt-1 text-lg font-extrabold text-gray-900" style="overflow-wrap:anywhere;"><?php echo h(cpms_safety_cost_money_label($safetyCostSummary['used_total'])); ?></div>
                            </div>
                            <div class="rounded-xl border border-white/70 bg-white/80 p-3 min-w-0">
                                <div class="text-xs text-gray-500">남은금액</div>
                                <div class="mt-1 text-lg font-extrabold <?php echo ((float)$safetyCostSummary['remaining'] < 0) ? 'text-red-700' : 'text-gray-900'; ?>" style="overflow-wrap:anywhere;"><?php echo h(cpms_safety_cost_money_label($safetyCostSummary['remaining'])); ?></div>
                            </div>
                            <div class="rounded-xl border border-white/70 bg-white/80 p-3 min-w-0">
                                <div class="text-xs text-gray-500">사용률(총액 기준)</div>
                                <div class="mt-1 text-lg font-extrabold text-gray-900" style="overflow-wrap:anywhere;"><?php echo h(cpms_safety_cost_rate_label($safetyCostSummary['use_rate'])); ?></div>
                            </div>
                            <div class="rounded-xl border border-white/70 bg-white/80 p-3 min-w-0">
                                <div class="text-xs text-gray-500">남은 퍼센트(110% 한도)</div>
                                <div class="mt-1 text-lg font-extrabold <?php echo ((float)$safetyCostSummary['remaining_rate'] < 0) ? 'text-red-700' : 'text-gray-900'; ?>" style="overflow-wrap:anywhere;"><?php echo h(cpms_safety_cost_rate_label($safetyCostSummary['remaining_rate'])); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canManageSafetyCost): ?>
                    <?php
                    $editId = is_array($editSafetyCost) && isset($editSafetyCost['id']) ? (string)$editSafetyCost['id'] : '';
                    $editUseDate = is_array($editSafetyCost) && isset($editSafetyCost['use_date']) ? (string)$editSafetyCost['use_date'] : date('Y-m-d');
                    $editVendor = is_array($editSafetyCost) && isset($editSafetyCost['vendor_name']) ? (string)$editSafetyCost['vendor_name'] : '';
                    $editRepresentative = is_array($editSafetyCost) && isset($editSafetyCost['representative']) ? (string)$editSafetyCost['representative'] : '';
                    $editPhone = is_array($editSafetyCost) && isset($editSafetyCost['phone']) ? (string)$editSafetyCost['phone'] : '';
                    $editBizNo = is_array($editSafetyCost) && isset($editSafetyCost['biz_no']) ? (string)$editSafetyCost['biz_no'] : '';
                    $editContent = is_array($editSafetyCost) && isset($editSafetyCost['use_content']) ? (string)$editSafetyCost['use_content'] : (is_array($editSafetyCost) && isset($editSafetyCost['item_name']) ? (string)$editSafetyCost['item_name'] : '');
                    $editAmount = is_array($editSafetyCost) ? (string)cpms_safety_cost_row_amount($editSafetyCost) : '';
                    $editRemark = is_array($editSafetyCost) && isset($editSafetyCost['remark']) ? (string)$editSafetyCost['remark'] : '';
                    ?>
                    <form method="post" action="<?php echo h(base_url()); ?>/?r=safety/safety_cost_save" enctype="multipart/form-data" class="mb-5 rounded-2xl border border-gray-200 bg-gray-50 p-4" id="safetyCostForm">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="project_id" value="<?php echo (int)$selectedProjectId; ?>">
                        <input type="hidden" name="safety_cost_id" value="<?php echo h($editId); ?>">

                        <div class="mb-3 rounded-xl border border-gray-200 bg-white p-3 vendor-search-wrap">
                            <label class="text-sm font-bold text-gray-700">업체명 검색 자동완성</label>
                            <input type="text" class="mt-1 w-full px-3 py-2 border rounded-xl bg-white js-safety-vendor-search" placeholder="업체명 2글자 이상 입력">
                            <div class="vendor-suggest-list mt-2 hidden border border-gray-200 rounded-xl bg-white max-h-48 overflow-auto"></div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                            <div>
                                <label class="text-xs font-bold text-gray-600">사용일자</label>
                                <input type="date" name="use_date" value="<?php echo h($editUseDate); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white" required>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-600">업체명</label>
                                <input type="text" name="vendor_name" value="<?php echo h($editVendor); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white" required>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-600">대표자명</label>
                                <input type="text" name="representative" value="<?php echo h($editRepresentative); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-600">전화번호</label>
                                <input type="text" name="phone" value="<?php echo h($editPhone); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-600">사업자등록번호</label>
                                <input type="text" name="biz_no" value="<?php echo h($editBizNo); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white">
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-xs font-bold text-gray-600">품목 또는 사용내용</label>
                                <input type="text" name="use_content" value="<?php echo h($editContent); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white" required>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-600">공급가액</label>
                                <input type="text" name="amount" value="<?php echo h($editAmount); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white text-right" placeholder="0" required>
                            </div>
                            <div class="md:col-span-2 xl:col-span-3">
                                <label class="text-xs font-bold text-gray-600">비고</label>
                                <input type="text" name="remark" value="<?php echo h($editRemark); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-600">거래명세표 PDF</label>
                                <input type="file" name="pdf_file" accept=".pdf,application/pdf" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white text-sm">
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <div class="text-xs text-gray-500">
                                <?php echo ($editId !== '') ? '기존 PDF를 유지하려면 파일을 새로 선택하지 마세요.' : 'PDF는 선택사항이며, 등록 후 공무 월별 투입비 상세내역에서 조회할 수 있습니다.'; ?>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ($editId !== ''): ?>
                                    <a href="<?php echo h($baseSafetyUrl . '&tab=safety_cost#safety-cost-section'); ?>" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold text-sm">수정 취소</a>
                                <?php endif; ?>
                                <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold text-sm"><?php echo ($editId !== '') ? '수정 저장' : '등록'; ?></button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 font-bold">
                        이 현장의 안전관리비 등록/수정/삭제는 지정 안전관리자가 안전섹션에서 처리합니다.
                    </div>
                <?php endif; ?>

                <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                    <table class="min-w-[1280px] w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="px-3 py-2 text-left font-bold">사용일자</th>
                                <th class="px-3 py-2 text-left font-bold">업체명</th>
                                <th class="px-3 py-2 text-left font-bold">대표자명</th>
                                <th class="px-3 py-2 text-left font-bold">전화번호</th>
                                <th class="px-3 py-2 text-left font-bold">사업자등록번호</th>
                                <th class="px-3 py-2 text-left font-bold">품목/사용내용</th>
                                <th class="px-3 py-2 text-right font-bold">공급가액</th>
                                <th class="px-3 py-2 text-left font-bold">비고</th>
                                <th class="px-3 py-2 text-left font-bold">등록자</th>
                                <th class="px-3 py-2 text-left font-bold">PDF</th>
                                <th class="px-3 py-2 text-center font-bold">관리</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (count($safetyCostItems) === 0): ?>
                                <tr><td colspan="11" class="px-3 py-4 text-center text-gray-500">등록된 안전관리비 사용내역이 없습니다.</td></tr>
                            <?php else: ?>
                                <?php foreach ($safetyCostItems as $row): ?>
                                    <?php
                                    $rowId = isset($row['id']) ? (string)$row['id'] : '';
                                    $rowItem = isset($row['item_name']) ? trim((string)$row['item_name']) : '';
                                    $rowContent = isset($row['use_content']) ? trim((string)$row['use_content']) : '';
                                    $rowDesc = trim($rowItem . (($rowItem !== '' && $rowContent !== '') ? ' / ' : '') . $rowContent);
                                    $rowRegistrant = isset($row['created_by_name']) && trim((string)$row['created_by_name']) !== '' ? (string)$row['created_by_name'] : (isset($row['created_by_email']) ? (string)$row['created_by_email'] : '-');
                                    ?>
                                    <tr>
                                        <td class="px-3 py-2 whitespace-nowrap"><?php echo h(isset($row['use_date']) ? $row['use_date'] : ''); ?></td>
                                        <td class="px-3 py-2"><?php echo h(isset($row['vendor_name']) ? $row['vendor_name'] : ''); ?></td>
                                        <td class="px-3 py-2"><?php echo h(isset($row['representative']) ? $row['representative'] : ''); ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap"><?php echo h(isset($row['phone']) ? $row['phone'] : ''); ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap"><?php echo h(isset($row['biz_no']) ? $row['biz_no'] : ''); ?></td>
                                        <td class="px-3 py-2"><?php echo h($rowDesc !== '' ? $rowDesc : '-'); ?></td>
                                        <td class="px-3 py-2 text-right font-bold whitespace-nowrap"><?php echo number_format(cpms_safety_cost_row_amount($row)); ?></td>
                                        <td class="px-3 py-2"><?php echo h(isset($row['remark']) ? $row['remark'] : ''); ?></td>
                                        <td class="px-3 py-2"><?php echo h($rowRegistrant); ?></td>
                                        <td class="px-3 py-2"><?php echo cpms_safety_cost_pdf_links_html($row); ?></td>
                                        <td class="px-3 py-2 text-center">
                                            <?php if ($canManageSafetyCost): ?>
                                                <div class="inline-flex flex-wrap justify-center gap-1">
                                                    <a href="<?php echo h($baseSafetyUrl . '&tab=safety_cost&safety_cost_edit=' . rawurlencode($rowId) . '#safety-cost-section'); ?>" class="px-2 py-1 rounded-lg border border-gray-300 bg-white text-xs font-bold">수정</a>
                                                    <form method="post" action="<?php echo h(base_url()); ?>/?r=safety/safety_cost_delete" onsubmit="return confirm('삭제 처리할까요?');" style="display:inline;">
                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="project_id" value="<?php echo (int)$selectedProjectId; ?>">
                                                        <input type="hidden" name="safety_cost_id" value="<?php echo h($rowId); ?>">
                                                        <button type="submit" class="px-2 py-1 rounded-lg border border-red-200 bg-red-50 text-red-700 text-xs font-bold">삭제</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-400">조회</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div id="safety-incident-section" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-xl font-extrabold text-gray-900">안전사고</h3>
                        <div class="text-sm text-gray-600 mt-1">선택한 프로젝트의 안전사고만 표시합니다.</div>
                    </div>
                </div>

                <?php if ($canManageIncident): ?>
                    <form method="post" action="<?php echo h(base_url()); ?>/?r=safety/safety_incident_save" class="mb-5 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="project_id" value="<?php echo (int)$selectedProjectId; ?>">
                        <input type="hidden" name="redirect" value="safety_home">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <input name="title" required maxlength="200" class="w-full px-4 py-3 rounded-2xl border" placeholder="제목">
                            <input type="datetime-local" name="occurred_at" class="w-full px-4 py-3 rounded-2xl border">
                            <select name="severity" class="px-4 py-3 rounded-2xl border"><option>경미</option><option selected>보통</option><option>중대</option><option>긴급</option></select>
                            <select name="status" class="px-4 py-3 rounded-2xl border"><option selected>접수</option><option>처리중</option><option>처리완료</option></select>
                            <textarea name="description" class="w-full px-4 py-3 rounded-2xl border md:col-span-2" rows="4" placeholder="사고내용"></textarea>
                        </div>
                        <div class="mt-3 text-right">
                            <button class="px-4 py-3 rounded-2xl bg-rose-600 text-white font-extrabold">안전사고 등록</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($safetyLoadError !== ''): ?>
                    <div class="mb-3 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">안전사고 목록 조회 중 오류가 발생했습니다: <?php echo h($safetyLoadError); ?></div>
                <?php endif; ?>

                <?php if (count($safetyIncidents) === 0): ?>
                    <div class="text-sm text-gray-600">등록된 안전사고가 없습니다.</div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($safetyIncidents as $it): ?>
                            <?php
                            $incidentStatus = isset($it['status']) ? (string)$it['status'] : '접수';
                            $badge = ($incidentStatus === '처리완료') ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                                : (($incidentStatus === '처리중') ? 'bg-blue-50 text-blue-700 border-blue-100'
                                : 'bg-rose-50 text-rose-700 border-rose-100');
                            ?>
                            <div class="p-4 rounded-2xl border border-gray-100 bg-white">
                                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="font-extrabold text-gray-900"><?php echo h(isset($it['title']) ? $it['title'] : '-'); ?></div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            현장명: <?php echo h(isset($it['project_name']) ? $it['project_name'] : $selectedProjectName); ?>
                                            · 등록일: <?php echo h(isset($it['created_at']) ? $it['created_at'] : '-'); ?>
                                            <?php if (!empty($it['occurred_at'])): ?> · 발생: <?php echo h($it['occurred_at']); ?><?php endif; ?>
                                        </div>
                                        <div class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?php echo h(isset($it['description']) ? $it['description'] : ''); ?></div>
                                        <?php if (isset($it['action_note']) && trim((string)$it['action_note']) !== ''): ?>
                                            <div class="text-xs text-gray-700 mt-2">기존 후속조치: <?php echo nl2br(h($it['action_note'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($incidentStatus); ?></span>
                                </div>

                                <?php if ($canManageIncident): ?>
                                    <form method="post" action="?r=construction/safety_incident_action_save" class="mt-3">
                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="incident_id" value="<?php echo (int)$it['id']; ?>">
                                        <input type="hidden" name="project_id" value="<?php echo (int)$selectedProjectId; ?>">
                                        <input type="hidden" name="redirect" value="safety_home">
                                        <div class="flex flex-wrap items-center gap-2 mb-2">
                                            <select name="status" class="px-3 py-2 rounded-2xl border border-gray-200 text-sm">
                                                <option value="접수" <?php echo ($incidentStatus === '접수') ? 'selected' : ''; ?>>접수</option>
                                                <option value="처리중" <?php echo ($incidentStatus === '처리중') ? 'selected' : ''; ?>>처리중</option>
                                                <option value="처리완료" <?php echo ($incidentStatus === '처리완료') ? 'selected' : ''; ?>>처리완료</option>
                                            </select>
                                        </div>
                                        <textarea name="action_note" rows="3" class="w-full px-3 py-2 rounded-2xl border border-gray-200 text-sm" placeholder="후속조치 내용을 입력하세요."><?php echo h(isset($it['action_note']) ? $it['action_note'] : ''); ?></textarea>
                                        <button type="submit" class="mt-2 px-3 py-2 rounded-2xl bg-gray-900 text-white font-extrabold text-sm">후속조치 저장</button>
                                    </form>
                                <?php else: ?>
                                    <div class="text-xs text-gray-500 mt-3">후속조치 입력 권한이 없습니다.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

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

        <script>
        (function(){
            function hideSuggestList(listEl){ if(!listEl)return; listEl.innerHTML=''; if(listEl.className.indexOf('hidden')===-1) listEl.className += ' hidden'; listEl.style.display='none'; }
            function showSuggestList(listEl){ if(!listEl)return; listEl.className=listEl.className.replace(/\bhidden\b/g,'').replace(/\s+/g,' ').trim(); listEl.style.display='block'; }
            function fillSafetyVendor(formEl, row){
                if(!formEl || !row) return;
                if(formEl.elements['vendor_name']) formEl.elements['vendor_name'].value = row.vendor_name || '';
                if(formEl.elements['representative']) formEl.elements['representative'].value = row.representative || '';
                if(formEl.elements['phone']) formEl.elements['phone'].value = row.phone || '';
                if(formEl.elements['biz_no']) formEl.elements['biz_no'].value = row.biz_no || '';
            }
            function renderSuggestions(inputEl, rows){
                var wrap = inputEl ? inputEl.closest('.vendor-search-wrap') : null;
                var listEl = wrap ? wrap.querySelector('.vendor-suggest-list') : null;
                if(!listEl) return;
                listEl.innerHTML = '';
                if(!rows || !rows.length){
                    var empty = document.createElement('div');
                    empty.className = 'px-3 py-2 text-sm text-gray-500';
                    empty.textContent = '검색 결과 없음';
                    listEl.appendChild(empty);
                    showSuggestList(listEl);
                    return;
                }
                for(var i=0;i<rows.length;i++){
                    (function(row){
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'block w-full text-left px-3 py-2 border-b last:border-b-0 hover:bg-blue-50';
                        btn.textContent = (row.vendor_name || '') + (row.phone ? ' (' + row.phone + ')' : '');
                        btn.setAttribute('data-safety-vendor-item', '1');
                        btn.vendorData = row;
                        listEl.appendChild(btn);
                    })(rows[i]);
                }
                showSuggestList(listEl);
            }
            var timers = {};
            document.addEventListener('input', function(e){
                var inputEl = e.target;
                if(!inputEl || inputEl.className.indexOf('js-safety-vendor-search') === -1) return;
                var q = (inputEl.value || '').trim();
                var wrap = inputEl.closest('.vendor-search-wrap');
                var listEl = wrap ? wrap.querySelector('.vendor-suggest-list') : null;
                if(timers[inputEl]) clearTimeout(timers[inputEl]);
                if(q.length < 2){ hideSuggestList(listEl); return; }
                timers[inputEl] = setTimeout(function(){
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', '<?php echo h(base_url()); ?>/?r=construction/material_vendor_search&q=' + encodeURIComponent(q), true);
                    xhr.onreadystatechange = function(){
                        if(xhr.readyState !== 4) return;
                        var rows = [];
                        if(xhr.status === 200){
                            try {
                                var json = JSON.parse(xhr.responseText);
                                rows = (json && json.items) ? json.items : [];
                            } catch(err) {
                                rows = [];
                            }
                        }
                        renderSuggestions(inputEl, rows);
                    };
                    xhr.send();
                }, 250);
            });
            document.addEventListener('click', function(e){
                var target = e.target;
                if(target && target.getAttribute && target.getAttribute('data-safety-vendor-item') === '1'){
                    var wrap = target.closest('.vendor-search-wrap');
                    var inputEl = wrap ? wrap.querySelector('.js-safety-vendor-search') : null;
                    var formEl = target.closest('form');
                    fillSafetyVendor(formEl, target.vendorData || {});
                    if(inputEl) inputEl.value = (target.vendorData && target.vendorData.vendor_name) ? target.vendorData.vendor_name : '';
                    hideSuggestList(wrap ? wrap.querySelector('.vendor-suggest-list') : null);
                    return;
                }
                var lists = document.querySelectorAll('.vendor-search-wrap .vendor-suggest-list');
                for(var i=0;i<lists.length;i++){
                    if(!lists[i].contains(target)) hideSuggestList(lists[i]);
                }
            });
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>
