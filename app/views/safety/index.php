<?php
/**
 * safety/index.php
 * - safety/index 빈화면 복구
 * - PHP 5.6 호환 최소 안전 화면
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/safety_cost_helper.php';

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

$safetyCostProjects = array();
$selectedSafetyCostProjectId = isset($_GET['safety_pid']) ? (int)$_GET['safety_pid'] : 0;
$selectedSafetyCostProject = null;
$canManageSafetyCost = false;
$safetyCostItems = array();
$editSafetyCostId = isset($_GET['safety_cost_edit']) ? trim((string)$_GET['safety_cost_edit']) : '';
$editSafetyCost = null;
if ($pdo) {
    $safetyCostProjects = cpms_safety_cost_project_rows_for_user($pdo);
    if ($selectedSafetyCostProjectId <= 0) {
        foreach ($safetyCostProjects as $p) {
            if (isset($p['can_manage_safety_cost']) && (int)$p['can_manage_safety_cost'] === 1) {
                $selectedSafetyCostProjectId = (int)$p['id'];
                break;
            }
        }
        if ($selectedSafetyCostProjectId <= 0 && count($safetyCostProjects) > 0) {
            $selectedSafetyCostProjectId = (int)$safetyCostProjects[0]['id'];
        }
    }
    foreach ($safetyCostProjects as $p) {
        if ((int)$p['id'] === $selectedSafetyCostProjectId) {
            $selectedSafetyCostProject = $p;
            break;
        }
    }
    if ($selectedSafetyCostProjectId > 0 && is_array($selectedSafetyCostProject)) {
        $canManageSafetyCost = cpms_safety_cost_user_can_manage_project($pdo, $selectedSafetyCostProjectId);
        $safetyCostItems = cpms_safety_cost_project_items($selectedSafetyCostProjectId);
        if ($canManageSafetyCost && $editSafetyCostId !== '') {
            foreach ($safetyCostItems as $row) {
                if (isset($row['id']) && (string)$row['id'] === $editSafetyCostId) {
                    $editSafetyCost = $row;
                    break;
                }
            }
        }
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

<div class="mb-4 p-3 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 text-sm font-bold">SAFETY_INDEX_LOADED = 2026-safety-followup-vis<br>SAFETY_INCIDENT_ACTION_ROUTE = construction/safety_incident_action_save<br>SAFETY_INCIDENT_METHOD = POST</div>

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
                        <form method="post" action="?r=construction/safety_incident_action_save" class="mt-3">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="incident_id" value="<?php echo (int)$it['id']; ?>">
                            <input type="hidden" name="redirect" value="safety_home">
                            <div class="flex items-center gap-2 mb-2">
                                <select name="status" class="px-3 py-2 rounded-2xl border border-gray-200 text-sm">
                                    <option value="접수" <?php echo ($stt === '접수') ? 'selected' : ''; ?>>접수</option>
                                    <option value="처리중" <?php echo ($stt === '처리중') ? 'selected' : ''; ?>>처리중</option>
                                    <option value="처리완료" <?php echo ($stt === '처리완료') ? 'selected' : ''; ?>>처리완료</option>
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

<div id="safety-cost-section" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3 mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">안전관리비 사용내역</h3>
            <div class="text-sm text-gray-600 mt-1">안전관리비 원본 입력/수정/PDF 업로드는 이 안전섹션에서만 처리합니다.</div>
        </div>
        <?php if (count($safetyCostProjects) > 0): ?>
            <form method="get" action="" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="r" value="safety_home">
                <div>
                    <label class="text-xs font-bold text-gray-600">현장/프로젝트</label>
                    <select name="safety_pid" class="mt-1 px-3 py-2 rounded-xl border border-gray-300 min-w-[260px]">
                        <?php foreach ($safetyCostProjects as $projectOption): ?>
                            <option value="<?php echo (int)$projectOption['id']; ?>" <?php echo ((int)$projectOption['id'] === $selectedSafetyCostProjectId) ? 'selected' : ''; ?>>
                                <?php echo h(isset($projectOption['name']) ? $projectOption['name'] : ('현장 #' . (int)$projectOption['id'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold">조회</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (count($safetyCostProjects) === 0): ?>
        <div class="text-sm text-gray-600">조회 가능한 현장/프로젝트가 없습니다.</div>
    <?php else: ?>
        <?php if ($canManageSafetyCost): ?>
            <?php
            $editId = is_array($editSafetyCost) && isset($editSafetyCost['id']) ? (string)$editSafetyCost['id'] : '';
            $editUseDate = is_array($editSafetyCost) && isset($editSafetyCost['use_date']) ? (string)$editSafetyCost['use_date'] : date('Y-m-d');
            $editVendor = is_array($editSafetyCost) && isset($editSafetyCost['vendor_name']) ? (string)$editSafetyCost['vendor_name'] : '';
            $editItem = is_array($editSafetyCost) && isset($editSafetyCost['item_name']) ? (string)$editSafetyCost['item_name'] : '';
            $editContent = is_array($editSafetyCost) && isset($editSafetyCost['use_content']) ? (string)$editSafetyCost['use_content'] : '';
            $editAmount = is_array($editSafetyCost) && isset($editSafetyCost['amount']) ? (string)$editSafetyCost['amount'] : '';
            ?>
            <form method="post" action="<?php echo h(base_url()); ?>/?r=safety/safety_cost_save" enctype="multipart/form-data" class="mb-5 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$selectedSafetyCostProjectId; ?>">
                <input type="hidden" name="safety_cost_id" value="<?php echo h($editId); ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                    <div>
                        <label class="text-xs font-bold text-gray-600">사용일자</label>
                        <input type="date" name="use_date" value="<?php echo h($editUseDate); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white" required>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-600">거래처</label>
                        <input type="text" name="vendor_name" value="<?php echo h($editVendor); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white" placeholder="거래처">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-600">품목</label>
                        <input type="text" name="item_name" value="<?php echo h($editItem); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white" placeholder="품목">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-600">사용금액</label>
                        <input type="text" name="amount" value="<?php echo h($editAmount); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white text-right" placeholder="0">
                    </div>
                    <div class="md:col-span-2 xl:col-span-3">
                        <label class="text-xs font-bold text-gray-600">사용내용</label>
                        <input type="text" name="use_content" value="<?php echo h($editContent); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white" placeholder="사용내용">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-600">PDF 첨부</label>
                        <input type="file" name="pdf_file" accept=".pdf,application/pdf" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-white text-sm">
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="text-xs text-gray-500">
                        <?php if ($editId !== ''): ?>
                            기존 PDF를 유지하려면 파일을 새로 선택하지 마세요.
                        <?php else: ?>
                            PDF는 선택사항이며, 등록 후 공무 월별 투입비 상세내역에서 조회할 수 있습니다.
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <?php if ($editId !== ''): ?>
                            <a href="<?php echo h(base_url()); ?>/?r=safety_home&safety_pid=<?php echo (int)$selectedSafetyCostProjectId; ?>#safety-cost-section" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold text-sm">수정 취소</a>
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
            <table class="min-w-[980px] w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-bold">사용일자</th>
                        <th class="px-3 py-2 text-left font-bold">현장/프로젝트</th>
                        <th class="px-3 py-2 text-left font-bold">구분</th>
                        <th class="px-3 py-2 text-left font-bold">거래처</th>
                        <th class="px-3 py-2 text-left font-bold">품목/사용내용</th>
                        <th class="px-3 py-2 text-right font-bold">사용금액</th>
                        <th class="px-3 py-2 text-left font-bold">등록자</th>
                        <th class="px-3 py-2 text-left font-bold">PDF</th>
                        <th class="px-3 py-2 text-center font-bold">관리</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (count($safetyCostItems) === 0): ?>
                        <tr><td colspan="9" class="px-3 py-4 text-center text-gray-500">등록된 안전관리비 사용내역이 없습니다.</td></tr>
                    <?php else: ?>
                        <?php foreach ($safetyCostItems as $row): ?>
                            <?php
                            $rowId = isset($row['id']) ? (string)$row['id'] : '';
                            $rowProjectName = isset($row['project_name']) && trim((string)$row['project_name']) !== '' ? (string)$row['project_name'] : (isset($selectedSafetyCostProject['name']) ? (string)$selectedSafetyCostProject['name'] : '-');
                            $rowItem = isset($row['item_name']) ? trim((string)$row['item_name']) : '';
                            $rowContent = isset($row['use_content']) ? trim((string)$row['use_content']) : '';
                            $rowDesc = trim($rowItem . (($rowItem !== '' && $rowContent !== '') ? ' / ' : '') . $rowContent);
                            $rowRegistrant = isset($row['created_by_name']) && trim((string)$row['created_by_name']) !== '' ? (string)$row['created_by_name'] : (isset($row['created_by_email']) ? (string)$row['created_by_email'] : '-');
                            ?>
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap"><?php echo h(isset($row['use_date']) ? $row['use_date'] : ''); ?></td>
                                <td class="px-3 py-2"><?php echo h($rowProjectName); ?></td>
                                <td class="px-3 py-2 font-bold text-emerald-700">안전관리비</td>
                                <td class="px-3 py-2"><?php echo h(isset($row['vendor_name']) ? $row['vendor_name'] : ''); ?></td>
                                <td class="px-3 py-2"><?php echo h($rowDesc !== '' ? $rowDesc : '-'); ?></td>
                                <td class="px-3 py-2 text-right font-bold"><?php echo number_format(isset($row['amount']) ? (float)$row['amount'] : 0); ?></td>
                                <td class="px-3 py-2"><?php echo h($rowRegistrant); ?></td>
                                <td class="px-3 py-2"><?php echo cpms_safety_cost_pdf_links_html($row); ?></td>
                                <td class="px-3 py-2 text-center">
                                    <?php if ($canManageSafetyCost): ?>
                                        <div class="inline-flex flex-wrap justify-center gap-1">
                                            <a href="<?php echo h(base_url()); ?>/?r=safety_home&safety_pid=<?php echo (int)$selectedSafetyCostProjectId; ?>&safety_cost_edit=<?php echo h(rawurlencode($rowId)); ?>#safety-cost-section" class="px-2 py-1 rounded-lg border border-gray-300 bg-white text-xs font-bold">수정</a>
                                            <form method="post" action="<?php echo h(base_url()); ?>/?r=safety/safety_cost_delete" onsubmit="return confirm('삭제 처리할까요?');" style="display:inline;">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="project_id" value="<?php echo (int)$selectedSafetyCostProjectId; ?>">
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
