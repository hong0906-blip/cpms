<?php
/**
 * 비용 변경 승인 요청서 작성/반려 후 재요청 화면.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();

$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$targetType = isset($_GET['target_type']) ? trim((string)$_GET['target_type']) : '';
$targetId = isset($_GET['target_id']) ? trim((string)$_GET['target_id']) : '';
$requestType = isset($_GET['request_type']) ? strtoupper(trim((string)$_GET['request_type'])) : CostChangeService::REQUEST_MODIFY;
$parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
$returnUrl = cpms_cost_change_return_url(isset($_GET['return_url']) ? $_GET['return_url'] : '', '?r=cost_change/my');
$allowedTargetTypes = array('material','equipment','outsourcing','labor_force','safety','daily_cost');
$allowedRequestTypes = array(CostChangeService::REQUEST_MODIFY, CostChangeService::REQUEST_ADD, CostChangeService::REQUEST_MONTH_MOVE, CostChangeService::REQUEST_DELETE);
if (!in_array($targetType, $allowedTargetTypes, true) || !in_array($requestType, $allowedRequestTypes, true)) {
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 font-bold">요청 종류가 올바르지 않습니다.</div>';
    return;
}

$parentRequest = null;
$formData = array();
$oldFiles = array();
if ($parentId > 0) {
    $parentRequest = CostChangeService::requestById($pdo, $parentId);
    if (!$parentRequest || !CostChangeService::isRequester($parentRequest) || (string)$parentRequest['status'] !== CostChangeService::STATUS_REJECTED) {
        http_response_code(403);
        echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 font-bold">재요청할 수 없는 요청입니다.</div>';
        return;
    }
    $projectId = (int)$parentRequest['project_id'];
    $targetType = (string)$parentRequest['target_type'];
    $targetId = (string)$parentRequest['target_id'];
    $requestType = (string)$parentRequest['request_type'];
    $formData = CostChangeService::jsonDecode($parentRequest['requested_data']);
    $oldFiles = CostChangeService::files($pdo, $parentId);
}

if ($projectId <= 0 || !CostChangeService::canManageProject($pdo, $projectId, $targetType)) {
    http_response_code(403);
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 font-bold">이 현장의 비용 변경 요청 권한이 없습니다.</div>';
    return;
}

$target = null;
if ($requestType !== CostChangeService::REQUEST_ADD) {
    $target = CostChangeService::loadTarget($pdo, $targetType, $targetId, $projectId);
    if (!$target) {
        echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 font-bold">대상 원본자료를 찾을 수 없습니다.</div>';
        return;
    }
    $activeRequest = CostChangeService::activeRequest($pdo, $targetType, $targetId);
    if ($activeRequest && (!$parentRequest || (int)$activeRequest['id'] !== (int)$parentRequest['id'])) {
        echo '<div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-800 font-bold">같은 자료에 처리 중인 요청이 있습니다. 요청번호 ' . h($activeRequest['request_no']) . '</div>';
        return;
    }
}

if (count($formData) === 0) {
    $formData = is_array($target) ? $target : array(
        'project_id'=>$projectId,
        'target_type'=>$targetType,
        'cost_type'=>$targetType === 'labor_force' ? 'labor' : $targetType,
        'category'=>CostChangeService::costTypeLabel($targetType),
        'use_date'=>date('Y-m-d'),
        'settlement_ym'=>CostChangeService::settlementYm($targetType, date('Y-m-d')),
        'vendor_name'=>'',
        'item_name'=>'',
        'quantity'=>1,
        'unit_price'=>0,
        'amount'=>0,
        'memo'=>'',
        'master_id'=>isset($_GET['master_id']) ? (int)$_GET['master_id'] : 0
    );
}
unset($formData['native']);
$costType = isset($formData['cost_type']) ? (string)$formData['cost_type'] : ($targetType === 'labor_force' ? 'labor' : $targetType);
$projectName = CostChangeService::projectName($pdo, $projectId);
$useDate = isset($formData['use_date']) ? (string)$formData['use_date'] : date('Y-m-d');
$settlementYm = isset($formData['settlement_ym']) ? CostChangeService::validYm($formData['settlement_ym']) : CostChangeService::settlementYm($costType, $useDate);
$lockInfo = CostChangeService::lockInfo($costType, $useDate, $settlementYm, date('Y-m-d'));
$isDelete = $requestType === CostChangeService::REQUEST_DELETE;
$isMonthMove = $requestType === CostChangeService::REQUEST_MONTH_MOVE;
$safetyCategories = array('안전관리비','보호구 구입비','교육비','검진비','기타 안전·보건 비용');
?>

<div class="max-w-5xl mx-auto space-y-5">
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
        <h2 class="text-2xl font-extrabold text-gray-900"><?php echo h(CostChangeService::requestTypeLabel($requestType)); ?> 승인 요청</h2>
        <p class="mt-2 text-sm text-amber-800 font-bold">마감된 기간의 자료입니다. 수정하려면 비용 변경 승인이 필요합니다.</p>
        <div class="mt-3 text-sm text-gray-700">승인선: 요청자 → 박원덕 전무 1차 승인 → 부사장 최종 승인</div>
    </div>

    <form method="post" action="?r=cost_change/store" enctype="multipart/form-data" class="rounded-2xl border border-gray-200 bg-white p-5 space-y-5">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
        <input type="hidden" name="target_type" value="<?php echo h($targetType); ?>">
        <input type="hidden" name="target_id" value="<?php echo h($targetId); ?>">
        <input type="hidden" name="request_type" value="<?php echo h($requestType); ?>">
        <input type="hidden" name="cost_type" value="<?php echo h($costType); ?>">
        <input type="hidden" name="master_id" value="<?php echo (int)(isset($formData['master_id']) ? $formData['master_id'] : 0); ?>">
        <input type="hidden" name="parent_id" value="<?php echo (int)$parentId; ?>">
        <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 rounded-xl bg-gray-50 p-4">
            <div><div class="text-xs text-gray-500">현장명</div><div class="font-bold"><?php echo h($projectName); ?></div></div>
            <div><div class="text-xs text-gray-500">요청부서</div><div class="font-bold"><?php echo h(Auth::userDepartment()); ?></div></div>
            <div><div class="text-xs text-gray-500">요청자</div><div class="font-bold"><?php echo h(Auth::userName()); ?></div></div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-sm font-bold">비용 구분</span>
                <?php if ($targetType === 'safety'): ?>
                    <select name="category" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" <?php echo $isDelete || $isMonthMove ? 'disabled' : ''; ?>>
                        <?php foreach ($safetyCategories as $category): ?>
                            <option value="<?php echo h($category); ?>" <?php echo (isset($formData['category']) && (string)$formData['category'] === $category) ? 'selected' : ''; ?>><?php echo h($category); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isDelete || $isMonthMove): ?><input type="hidden" name="category" value="<?php echo h(isset($formData['category']) ? $formData['category'] : '안전관리비'); ?>"><?php endif; ?>
                <?php else: ?>
                    <input type="text" name="category" value="<?php echo h(isset($formData['category']) ? $formData['category'] : CostChangeService::costTypeLabel($costType)); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" <?php echo $isDelete || $isMonthMove ? 'readonly' : ''; ?>>
                <?php endif; ?>
            </label>
            <label class="block">
                <span class="text-sm font-bold">실제 사용일자</span>
                <input type="date" id="costChangeUseDate" name="use_date" value="<?php echo h($useDate); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" required <?php echo $isDelete || $isMonthMove ? 'readonly' : ''; ?>>
            </label>
        </div>

        <div id="costChangeMonthInfo" class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm">
            <div class="font-extrabold text-blue-800" data-month-title><?php echo h($lockInfo['settlement_ym'] . '월분'); ?></div>
            <div class="mt-1 text-blue-700" data-month-period>적용기간 <?php echo h($lockInfo['period_start'] . ' ~ ' . $lockInfo['period_end']); ?></div>
            <div class="mt-1 font-bold <?php echo $lockInfo['locked'] ? 'text-red-700' : 'text-emerald-700'; ?>" data-month-lock><?php echo $lockInfo['locked'] ? '마감된 기간 / 승인 필요' : '현재 입력 가능 / 일반 저장 가능'; ?></div>
        </div>

        <?php if ($isMonthMove): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-bold">기존 귀속월</span>
                    <input type="month" value="<?php echo h(isset($target['settlement_ym']) ? $target['settlement_ym'] : $settlementYm); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 bg-gray-100" readonly>
                </label>
                <label class="block">
                    <span class="text-sm font-bold">변경할 귀속월</span>
                    <input type="month" name="new_settlement_ym" value="<?php echo h($settlementYm); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" required>
                </label>
            </div>
        <?php else: ?>
            <input type="hidden" name="new_settlement_ym" id="costChangeSettlementYm" value="<?php echo h($settlementYm); ?>">
        <?php endif; ?>

        <?php if (!$isDelete && !$isMonthMove): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-sm font-bold">업체명</span>
                <input type="text" name="vendor_name" value="<?php echo h(isset($formData['vendor_name']) ? $formData['vendor_name'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300">
            </label>
            <label class="block">
                <span class="text-sm font-bold">품명 또는 작업내용</span>
                <input type="text" name="item_name" value="<?php echo h(isset($formData['item_name']) ? $formData['item_name'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300">
            </label>
            <label class="block">
                <span class="text-sm font-bold">수량</span>
                <input type="number" name="quantity" min="0" step="0.0001" value="<?php echo h(isset($formData['quantity']) ? $formData['quantity'] : 1); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300">
            </label>
            <label class="block">
                <span class="text-sm font-bold">단가</span>
                <input type="number" name="unit_price" min="0" step="0.01" value="<?php echo h(isset($formData['unit_price']) ? $formData['unit_price'] : 0); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300">
            </label>
            <label class="block">
                <span class="text-sm font-bold">금액</span>
                <input type="number" name="amount" step="0.01" value="<?php echo h(isset($formData['amount']) ? $formData['amount'] : 0); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" required>
            </label>
            <label class="block">
                <span class="text-sm font-bold">비고</span>
                <input type="text" name="memo" value="<?php echo h(isset($formData['memo']) ? $formData['memo'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300">
            </label>
        </div>
        <?php else: ?>
            <input type="hidden" name="vendor_name" value="<?php echo h(isset($formData['vendor_name']) ? $formData['vendor_name'] : ''); ?>">
            <input type="hidden" name="item_name" value="<?php echo h(isset($formData['item_name']) ? $formData['item_name'] : ''); ?>">
            <input type="hidden" name="quantity" value="<?php echo h(isset($formData['quantity']) ? $formData['quantity'] : 1); ?>">
            <input type="hidden" name="unit_price" value="<?php echo h(isset($formData['unit_price']) ? $formData['unit_price'] : 0); ?>">
            <input type="hidden" name="amount" value="<?php echo h(isset($formData['amount']) ? $formData['amount'] : 0); ?>">
            <input type="hidden" name="memo" value="<?php echo h(isset($formData['memo']) ? $formData['memo'] : ''); ?>">
        <?php endif; ?>

        <label class="block">
            <span class="text-sm font-bold"><?php echo $isDelete ? '삭제 사유' : ($requestType === CostChangeService::REQUEST_ADD ? '추가 사유' : '변경 사유'); ?></span>
            <textarea name="reason" rows="4" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" required><?php echo h($parentRequest ? $parentRequest['reason'] : ''); ?></textarea>
        </label>

        <?php if (count($oldFiles) > 0): ?>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
            <div class="text-sm font-extrabold">기존 반려 요청 첨부파일</div>
            <div class="mt-2 flex flex-wrap gap-2">
                <?php foreach ($oldFiles as $file): ?>
                    <label class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-bold">
                        <input type="checkbox" name="inherit_file_ids[]" value="<?php echo (int)$file['id']; ?>" checked>
                        <a href="?r=cost_change/file&id=<?php echo (int)$file['id']; ?>&download=1"><?php echo h($file['original_name']); ?></a>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="mt-2 text-xs text-gray-500">재요청에 유지할 파일만 체크하고, 새 증빙자료를 추가할 수 있습니다. 원래 반려 요청의 파일 이력은 삭제되지 않습니다.</p>
        </div>
        <?php endif; ?>

        <label class="block">
            <span class="text-sm font-bold">증빙자료 첨부 · 여러 파일 가능</span>
            <input type="file" name="evidence_files[]" multiple accept=".pdf,.xls,.xlsx,.xlsm,.csv,.jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.hwp,.hwpx,.doc,.docx,.ppt,.pptx,.txt" class="mt-1 block w-full px-3 py-2 rounded-xl border border-gray-300 bg-white">
            <span class="mt-1 block text-xs text-gray-500">파일당 20MB 이하. 실행파일과 위험 MIME은 차단됩니다.</span>
        </label>

        <div class="flex flex-wrap justify-end gap-2">
            <a href="<?php echo h($returnUrl); ?>" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold">취소</a>
            <button type="submit" class="px-5 py-2 rounded-xl bg-blue-700 text-white font-extrabold"><?php echo $parentRequest ? '수정 후 재요청' : '승인 요청 보내기'; ?></button>
        </div>
    </form>
</div>

<script>
(function(){
    var input = document.getElementById('costChangeUseDate');
    var ymInput = document.getElementById('costChangeSettlementYm');
    var box = document.getElementById('costChangeMonthInfo');
    var labor = <?php echo CostChangeService::isLaborType($costType) ? 'true' : 'false'; ?>;
    if (!input || !box) return;
    function pad(v){ return v < 10 ? '0' + v : String(v); }
    function shiftMonth(year, month, delta) {
        var d = new Date(year, month - 1 + delta, 1);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1);
    }
    function render(){
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input.value || '');
        if (!m) return;
        var y = parseInt(m[1],10), mon = parseInt(m[2],10), day = parseInt(m[3],10);
        var ym = y + '-' + pad(mon);
        if (!labor && day >= 26) ym = shiftMonth(y, mon, 1);
        var start, end;
        if (labor) {
            start = ym + '-01';
            var endDate = new Date(parseInt(ym.substr(0,4),10), parseInt(ym.substr(5,2),10), 0);
            end = ym + '-' + pad(endDate.getDate());
        } else {
            var prev = shiftMonth(parseInt(ym.substr(0,4),10), parseInt(ym.substr(5,2),10), -1);
            start = prev + '-26';
            end = ym + '-25';
        }
        var now = new Date();
        var todayYm = now.getFullYear() + '-' + pad(now.getMonth()+1);
        if (!labor && now.getDate() >= 26) todayYm = shiftMonth(now.getFullYear(), now.getMonth()+1, 1);
        box.querySelector('[data-month-title]').textContent = ym + '월분';
        box.querySelector('[data-month-period]').textContent = '적용기간 ' + start + ' ~ ' + end;
        var lock = box.querySelector('[data-month-lock]');
        var locked = ym !== todayYm;
        lock.textContent = locked ? '마감된 기간 / 승인 필요' : '현재 입력 가능 / 일반 저장 가능';
        lock.className = 'mt-1 font-bold ' + (locked ? 'text-red-700' : 'text-emerald-700');
        if (ymInput) ymInput.value = ym;
    }
    input.addEventListener('change', render);
    render();
})();
</script>
