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
try {
    $stOld = $pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id = :pid ORDER BY id ASC");
    $stOld->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stOld->execute();
    $tmpRows = $stOld->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($tmpRows)) $oldRows = $tmpRows;
} catch (Exception $e) {
    cpms_contract_change_preview_fail($projectId, '기존 단가내역 조회에 실패했습니다.');
}

$comparison = cpms_contract_change_compare_rows($oldRows, $newRows);
$changes = isset($comparison['changes']) && is_array($comparison['changes']) ? $comparison['changes'] : array();
$excluded = isset($comparison['excluded']) && is_array($comparison['excluded']) ? $comparison['excluded'] : array();
$summary = isset($comparison['summary']) && is_array($comparison['summary']) ? $comparison['summary'] : array();

$cpmsRoot = dirname(dirname(dirname(__DIR__)));
$changeDir = $cpmsRoot . '/storage/contracts/' . $projectId . '/changes';
if (!is_dir($changeDir)) @mkdir($changeDir, 0775, true);
if (!is_dir($changeDir)) {
    cpms_contract_change_preview_fail($projectId, '업로드 폴더를 생성할 수 없습니다.');
}

$previewToken = bin2hex(openssl_random_pseudo_bytes(16));
$storedName = 'unit_price_update_preview_' . date('Ymd_His') . '_' . $previewToken . '.xlsx';
$storedPath = $changeDir . '/' . $storedName;
if (!@move_uploaded_file($tmpFile, $storedPath)) {
    cpms_contract_change_preview_fail($projectId, '미리보기 파일 임시 저장에 실패했습니다.');
}

if (!isset($_SESSION['unit_price_update']) || !is_array($_SESSION['unit_price_update'])) {
    $_SESSION['unit_price_update'] = array();
}
$_SESSION['unit_price_update'][$previewToken] = array(
    'project_id' => $projectId,
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
$incCount = isset($summary['quantity_increased']) ? (int)$summary['quantity_increased'] : 0;
$decCount = isset($summary['quantity_decreased']) ? (int)$summary['quantity_decreased'] : 0;
$excludedCount = isset($summary['excluded']) ? (int)$summary['excluded'] : 0;
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>변경 단가내역서 미리보기</title>
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
    <h2>변경 단가내역서 미리보기</h2>
    <div class="muted">프로젝트: <b><?php echo cpms_contract_change_preview_h(isset($project['name']) ? $project['name'] : ''); ?></b> / 파일: <b><?php echo cpms_contract_change_preview_h($originalName); ?></b></div>
    <div class="summary">
        <span>추가항목 <?php echo $insertedCount; ?>건</span>
        <span>단가 변경 <?php echo $priceCount; ?>건</span>
        <span>수량 증가 <?php echo $incCount; ?>건</span>
        <span>수량 감소 <?php echo $decCount; ?>건</span>
        <span>변경 합계 <?php echo $changedCount; ?>건</span>
        <span>삭제 의심 <?php echo $excludedCount; ?>건</span>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?php echo cpms_contract_change_preview_h(base_url()); ?>/?r=project/detail&id=<?php echo (int)$projectId; ?>">돌아가기</a>
        <form method="post" action="<?php echo cpms_contract_change_preview_h(base_url()); ?>/?r=project/contract_upload" style="margin:0">
            <input type="hidden" name="_csrf" value="<?php echo cpms_contract_change_preview_h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
            <input type="hidden" name="upload_mode" value="unit_price_update">
            <input type="hidden" name="preview_token" value="<?php echo cpms_contract_change_preview_h($previewToken); ?>">
            <button type="submit" class="btn btn-primary">변경 단가내역 적용</button>
        </form>
    </div>

    <table>
        <thead>
        <tr>
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
