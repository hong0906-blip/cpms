<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/unit_price_parser.php';
require_once __DIR__ . '/contract_change_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!function_exists('cpms_contract_change_preview_h')) {
function cpms_contract_change_preview_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
}

if (!function_exists('cpms_contract_change_preview_fail')) {
function cpms_contract_change_preview_fail($projectId, $message) {
    flash_set('error', $message);
    if ((int)$projectId > 0) {
        header('Location: ?r=project/detail&id=' . (int)$projectId);
    } else {
        header('Location: ?r=공무');
    }
    exit;
}
}

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($csrf)) {
    cpms_contract_change_preview_fail(0, '보안 토큰이 유효하지 않습니다.');
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) {
    cpms_contract_change_preview_fail(0, '잘못된 프로젝트 ID입니다.');
}

$uploadMode = isset($_POST['upload_mode']) ? trim((string)$_POST['upload_mode']) : 'unit_price_update';
if ($uploadMode !== 'unit_price_update' && $uploadMode !== 'unit_price_original' && $uploadMode !== 'unit_price_extra') $uploadMode = 'unit_price_update';
$manualVersionNo = isset($_POST['version_no']) ? (int)$_POST['version_no'] : 0;
if ($manualVersionNo < 0) $manualVersionNo = 0;
$additionalWorkTitle = isset($_POST['additional_work_title']) ? trim((string)$_POST['additional_work_title']) : '';
if ($uploadMode === 'unit_price_extra' && $additionalWorkTitle === '') {
    cpms_contract_change_preview_fail($projectId, '추가공사명을 입력해주세요.');
}

$pdo = Db::pdo();
if (!$pdo) {
    cpms_contract_change_preview_fail($projectId, 'DB 연결에 실패했습니다.');
}

try {
    $stProject = $pdo->prepare("SELECT id, name FROM cpms_projects WHERE id = :id LIMIT 1");
    $stProject->bindValue(':id', $projectId, PDO::PARAM_INT);
    $stProject->execute();
    $project = $stProject->fetch(PDO::FETCH_ASSOC);
    if (!is_array($project)) {
        cpms_contract_change_preview_fail(0, '프로젝트를 찾을 수 없습니다.');
    }
} catch (Exception $e) {
    cpms_contract_change_preview_fail(0, '프로젝트 확인에 실패했습니다.');
}

if (!isset($_FILES['contract_file']) || !is_array($_FILES['contract_file'])) {
    cpms_contract_change_preview_fail($projectId, '업로드할 변경 단가내역서가 없습니다.');
}

$file = $_FILES['contract_file'];
$errorCode = isset($file['error']) ? (int)$file['error'] : 999;
$tmpFile = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
$originalName = isset($file['name']) ? (string)$file['name'] : '';
$size = isset($file['size']) ? (int)$file['size'] : 0;

if ($errorCode !== UPLOAD_ERR_OK || $tmpFile === '' || !is_uploaded_file($tmpFile)) {
    cpms_contract_change_preview_fail($projectId, '파일 업로드에 실패했습니다.');
}
if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
    cpms_contract_change_preview_fail($projectId, '변경 단가내역서는 xlsx 파일만 업로드할 수 있습니다.');
}
if ($size <= 0 || $size > (30 * 1024 * 1024)) {
    cpms_contract_change_preview_fail($projectId, '파일 용량이 올바르지 않습니다. (최대 30MB)');
}

$parsed = cpms_project_parse_unit_price_xlsx($pdo, $tmpFile);
if (!is_array($parsed) || empty($parsed['ok'])) {
    cpms_contract_change_preview_fail($projectId, isset($parsed['message']) ? $parsed['message'] : '엑셀 파싱에 실패했습니다.');
}

$newRows = isset($parsed['rows']) && is_array($parsed['rows']) ? $parsed['rows'] : array();
if (count($newRows) === 0) {
    cpms_contract_change_preview_fail($projectId, '적용할 단가내역 데이터가 없습니다.');
}

$oldRows = array();
$currentActiveCount = 0;
try {
    $stOld = $pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id = :pid ORDER BY id ASC");
    $stOld->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stOld->execute();
    $tmpRows = $stOld->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($tmpRows)) $oldRows = $tmpRows;
    foreach ($oldRows as $oldRowForCount) {
        if (!is_array($oldRowForCount)) continue;
        if (isset($oldRowForCount['is_active']) && (int)$oldRowForCount['is_active'] === 0) continue;
        if (isset($oldRowForCount['is_current']) && (int)$oldRowForCount['is_current'] === 0) continue;
        $currentActiveCount++;
    }
} catch (Exception $e) {
    cpms_contract_change_preview_fail($projectId, '기존 단가내역 조회에 실패했습니다.');
}

$changes = array();
$excluded = array();
$summary = array('kept'=>0, 'changed'=>0, 'inserted'=>0, 'excluded'=>0, 'unit_price_changed'=>0, 'amount_changed'=>0, 'quantity_increased'=>0, 'quantity_decreased'=>0, 'duplicate_possible'=>0);
if ($uploadMode === 'unit_price_update') {
    $comparison = cpms_contract_change_compare_rows($oldRows, $newRows);
    $changes = isset($comparison['changes']) && is_array($comparison['changes']) ? $comparison['changes'] : array();
    $excluded = isset($comparison['excluded']) && is_array($comparison['excluded']) ? $comparison['excluded'] : array();
    $summary = isset($comparison['summary']) && is_array($comparison['summary']) ? $comparison['summary'] : $summary;
} else if ($uploadMode === 'unit_price_extra') {
    $matchData = cpms_contract_change_build_match_maps($oldRows);
    $usedOld = array();
    foreach ($newRows as $newRow) {
        if (!is_array($newRow)) continue;
        $badges = array(cpms_contract_change_badge('ADDED', '추가항목', null, null));
        $match = cpms_contract_change_pick_match($matchData, $newRow, $usedOld);
        if (isset($match['index']) && (int)$match['index'] >= 0) {
            array_push($badges, cpms_contract_change_badge('DUPLICATE_POSSIBLE', '중복 가능성', null, null));
            $summary['duplicate_possible']++;
        }
        $summary['inserted']++;
        array_push($changes, array('status'=>'추가항목', 'old_id'=>0, 'old_row'=>null, 'row'=>$newRow, 'badges'=>$badges));
    }
} else {
    foreach ($newRows as $newRow) {
        if (!is_array($newRow)) continue;
        $summary['inserted']++;
        array_push($changes, array('status'=>isset($newRow['preview_status']) ? $newRow['preview_status'] : '정상', 'old_id'=>0, 'old_row'=>null, 'row'=>$newRow, 'badges'=>array()));
    }
}

$cpmsRoot = dirname(dirname(dirname(__DIR__)));
$storageSubDir = 'changes';
if ($uploadMode === 'unit_price_original') $storageSubDir = 'versions';
else if ($uploadMode === 'unit_price_extra') $storageSubDir = 'extras';
$changeDir = $cpmsRoot . '/storage/contracts/' . $projectId . '/' . $storageSubDir;
if (!is_dir($changeDir)) @mkdir($changeDir, 0775, true);
if (!is_dir($changeDir)) {
    cpms_contract_change_preview_fail($projectId, '업로드 폴더를 생성할 수 없습니다.');
}

$previewToken = bin2hex(openssl_random_pseudo_bytes(16));
$storedPrefix = 'unit_price_update_preview_';
if ($uploadMode === 'unit_price_original') $storedPrefix = 'unit_price_original_preview_';
else if ($uploadMode === 'unit_price_extra') $storedPrefix = 'unit_price_extra_preview_';
$storedName = $storedPrefix . date('Ymd_His') . '_' . $previewToken . '.xlsx';
$storedPath = $changeDir . '/' . $storedName;
if (!@move_uploaded_file($tmpFile, $storedPath)) {
    cpms_contract_change_preview_fail($projectId, '미리보기 파일 임시 저장에 실패했습니다.');
}

if (!isset($_SESSION['unit_price_update']) || !is_array($_SESSION['unit_price_update'])) {
    $_SESSION['unit_price_update'] = array();
}
$_SESSION['unit_price_update'][$previewToken] = array(
    'project_id' => $projectId,
    'upload_mode' => $uploadMode,
    'version_no' => $manualVersionNo,
    'additional_work_title' => $additionalWorkTitle,
    'file_name' => $originalName,
    'stored_name' => $storedName,
    'stored_path' => $storedPath,
    'created_at' => time(),
    'rows' => $newRows,
    'changes' => $changes,
    'excluded' => $excluded,
    'summary' => $summary
);

$changedCount = isset($summary['changed']) ? (int)$summary['changed'] : 0;
$insertedCount = isset($summary['inserted']) ? (int)$summary['inserted'] : 0;
$priceCount = isset($summary['unit_price_changed']) ? (int)$summary['unit_price_changed'] : 0;
$amountCount = isset($summary['amount_changed']) ? (int)$summary['amount_changed'] : 0;
$incCount = isset($summary['quantity_increased']) ? (int)$summary['quantity_increased'] : 0;
$decCount = isset($summary['quantity_decreased']) ? (int)$summary['quantity_decreased'] : 0;
$excludedCount = isset($summary['excluded']) ? (int)$summary['excluded'] : 0;
$duplicateCount = isset($summary['duplicate_possible']) ? (int)$summary['duplicate_possible'] : 0;
$previewTitle = '변경계약 내역서 미리보기';
if ($uploadMode === 'unit_price_original') $previewTitle = '당초 내역서 미리보기';
else if ($uploadMode === 'unit_price_extra') $previewTitle = '추가공사 내역서 미리보기';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo cpms_contract_change_preview_h($previewTitle); ?></title>
<style>
body{font-family:Arial,'Noto Sans KR',sans-serif;background:#f6f7fb;margin:0;padding:24px;color:#111827}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;max-width:1360px;margin:0 auto}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}
.btn{padding:11px 14px;border-radius:12px;border:0;text-decoration:none;font-weight:800;cursor:pointer}
.btn-primary{background:#111827;color:#fff}
.btn-ghost{background:#f3f4f6;color:#111827}
.summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.summary span{background:#f9fafb;border:1px solid #e5e7eb;border-radius:999px;padding:8px 10px;font-size:13px;font-weight:800}
table{width:100%;border-collapse:collapse;margin-top:12px;background:#fff}
th,td{border-top:1px solid #e5e7eb;padding:9px;font-size:13px;text-align:left;vertical-align:top}
th{background:#f9fafb;color:#4b5563}
.num{text-align:right;white-space:nowrap}
.cpms-change-badge{display:inline-flex;align-items:center;margin:2px 4px 2px 0;padding:4px 8px;border-radius:999px;background:#fef3c7;color:#111827;border:1px solid #f59e0b;font-weight:700;white-space:nowrap}
.muted{color:#6b7280}
</style>
</head>
<body>
<div class="card">
    <h2><?php echo cpms_contract_change_preview_h($previewTitle); ?></h2>
    <div class="muted">프로젝트: <b><?php echo cpms_contract_change_preview_h(isset($project['name']) ? $project['name'] : ''); ?></b> / 파일: <b><?php echo cpms_contract_change_preview_h($originalName); ?></b></div>
    <?php if ($uploadMode === 'unit_price_original' && $currentActiveCount > 0): ?>
        <div style="margin-top:12px;padding:12px;border-radius:12px;background:#fef3c7;border:1px solid #f59e0b;color:#111827;font-weight:800;">
            이미 당초 내역서가 있습니다. 적용하면 기존 당초 내역은 이력으로 남기고 현재 적용 내역이 갱신됩니다.
        </div>
    <?php endif; ?>
    <div class="summary">
        <span>추가항목 <?php echo $insertedCount; ?>건</span>
        <span>단가 변경 <?php echo $priceCount; ?>건</span>
        <span>금액 변경 <?php echo $amountCount; ?>건</span>
        <span>수량 증가 <?php echo $incCount; ?>건</span>
        <span>수량 감소 <?php echo $decCount; ?>건</span>
        <span>변경 합계 <?php echo $changedCount; ?>건</span>
        <span>삭제 의심 <?php echo $excludedCount; ?>건</span>
        <?php if ($duplicateCount > 0): ?><span>중복 가능성 <?php echo $duplicateCount; ?>건</span><?php endif; ?>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?php echo cpms_contract_change_preview_h(base_url()); ?>/?r=project/detail&id=<?php echo (int)$projectId; ?>">돌아가기</a>
        <form method="post" action="<?php echo cpms_contract_change_preview_h(base_url()); ?>/?r=project/contract_upload" style="margin:0">
            <input type="hidden" name="_csrf" value="<?php echo cpms_contract_change_preview_h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
            <input type="hidden" name="upload_mode" value="<?php echo cpms_contract_change_preview_h($uploadMode); ?>">
            <input type="hidden" name="preview_token" value="<?php echo cpms_contract_change_preview_h($previewToken); ?>">
            <button type="submit" class="btn btn-primary">내역서 적용</button>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th>공종그룹</th>
            <th>세부공종</th>
            <th>위치</th>
            <th>품명</th>
            <th>규격</th>
            <th>단위</th>
            <th class="num">수량</th>
            <th class="num">합계단가</th>
            <th class="num">금액</th>
            <th>변경내용</th>
            <th>품명</th>
            <th>규격</th>
            <th>단위</th>
            <th class="num">기존 수량</th>
            <th class="num">변경 수량</th>
            <th class="num">기존 단가</th>
            <th class="num">변경 단가</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($changes as $change): ?>
            <?php
            $row = isset($change['row']) && is_array($change['row']) ? $change['row'] : array();
            $oldRow = isset($change['old_row']) && is_array($change['old_row']) ? $change['old_row'] : array();
            $badges = isset($change['badges']) && is_array($change['badges']) ? $change['badges'] : array();
            ?>
            <tr>
                <td><?php echo cpms_contract_change_preview_h(isset($row['trade_group']) ? $row['trade_group'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($row['sub_trade']) ? $row['sub_trade'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($row['location_name']) ? $row['location_name'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($row['item_name']) ? $row['item_name'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($row['spec']) ? $row['spec'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($row['unit']) ? $row['unit'] : ''); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(isset($row['qty']) ? $row['qty'] : '')); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(cpms_contract_change_unit_price_value($row))); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(isset($row['amount']) ? $row['amount'] : '')); ?></td>
                <td><?php echo cpms_contract_change_render_badges($badges); ?><?php if (count($badges) === 0): ?><span class="muted">유지</span><?php endif; ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($row['item_name']) ? $row['item_name'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($row['spec']) ? $row['spec'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($row['unit']) ? $row['unit'] : ''); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(isset($oldRow['qty']) ? $oldRow['qty'] : '')); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(isset($row['qty']) ? $row['qty'] : '')); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(count($oldRow) > 0 ? cpms_contract_change_unit_price_value($oldRow) : '')); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(cpms_contract_change_unit_price_value($row))); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($excluded as $oldRow): ?>
            <tr>
                <td><?php echo cpms_contract_change_preview_h(isset($oldRow['trade_group']) ? $oldRow['trade_group'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($oldRow['sub_trade']) ? $oldRow['sub_trade'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($oldRow['location_name']) ? $oldRow['location_name'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($oldRow['item_name']) ? $oldRow['item_name'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($oldRow['spec']) ? $oldRow['spec'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($oldRow['unit']) ? $oldRow['unit'] : ''); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(isset($oldRow['qty']) ? $oldRow['qty'] : '')); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(cpms_contract_change_unit_price_value($oldRow))); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(isset($oldRow['amount']) ? $oldRow['amount'] : '')); ?></td>
                <td><?php echo cpms_contract_change_render_badges(isset($oldRow['badges']) ? $oldRow['badges'] : array()); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($oldRow['item_name']) ? $oldRow['item_name'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($oldRow['spec']) ? $oldRow['spec'] : ''); ?></td>
                <td><?php echo cpms_contract_change_preview_h(isset($oldRow['unit']) ? $oldRow['unit'] : ''); ?></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(isset($oldRow['qty']) ? $oldRow['qty'] : '')); ?></td>
                <td class="num"></td>
                <td class="num"><?php echo cpms_contract_change_preview_h(cpms_contract_change_fmt(cpms_contract_change_unit_price_value($oldRow))); ?></td>
                <td class="num"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
