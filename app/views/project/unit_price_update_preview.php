<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/unit_price_parser.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('cpms_unit_price_update_preview_out')) {
function cpms_unit_price_update_preview_out($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
}

if (!function_exists('cpms_unit_price_update_preview_key')) {
function cpms_unit_price_update_preview_key($row) {
    $item = isset($row['item_name']) ? trim((string)$row['item_name']) : '';
    $spec = isset($row['spec']) ? trim((string)$row['spec']) : '';
    $unit = isset($row['unit']) ? trim((string)$row['unit']) : '';
    return $item . '|' . $spec . '|' . $unit;
}
}

if (!function_exists('cpms_unit_price_update_preview_num_same')) {
function cpms_unit_price_update_preview_num_same($a, $b) {
    if (($a === null || $a === '') && ($b === null || $b === '')) return true;
    if (is_numeric((string)$a) && is_numeric((string)$b)) {
        return (abs(((float)$a) - ((float)$b)) < 0.0001);
    }
    return ((string)$a === (string)$b);
}
}

if (!function_exists('cpms_unit_price_update_preview_text_same')) {
function cpms_unit_price_update_preview_text_same($a, $b) {
    return (trim((string)$a) === trim((string)$b));
}
}

if (!Auth::check()) cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '로그인 필요'));

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '권한이 없습니다.'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cpms_unit_price_update_preview_out(array('ok' => false, 'message' => 'Method Not Allowed'));
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '보안 토큰 오류'));

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '프로젝트 ID 오류'));
if (!isset($_FILES['xlsx']) || !is_array($_FILES['xlsx'])) cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '파일이 없습니다.'));

$tmp = isset($_FILES['xlsx']['tmp_name']) ? (string)$_FILES['xlsx']['tmp_name'] : '';
$name = isset($_FILES['xlsx']['name']) ? (string)$_FILES['xlsx']['name'] : '';
if ($tmp === '' || !is_uploaded_file($tmp)) cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '업로드 실패'));
if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '.xlsx만 가능'));

$cpmsRoot = dirname(dirname(dirname(__DIR__)));
$pdo = Db::pdo();
if (!$pdo) cpms_unit_price_update_preview_out(array('ok' => false, 'message' => 'DB 연결 실패'));

try {
    $stProject = $pdo->prepare("SELECT id FROM cpms_projects WHERE id=:pid LIMIT 1");
    $stProject->execute(array(':pid' => $projectId));
    if (!$stProject->fetch()) cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '프로젝트를 찾을 수 없습니다.'));
} catch (Exception $e) {
    error_log('[unit_price_update_preview] project check failed: ' . $e->getMessage());
    cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '프로젝트 확인 실패: ' . $e->getMessage()));
}

$parsed = cpms_project_parse_unit_price_xlsx($pdo, $tmp);
if (!$parsed['ok']) cpms_unit_price_update_preview_out(array('ok' => false, 'message' => $parsed['message']));

$newRows = $parsed['rows'];
$oldRows = array();

try {
    $st = $pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id=:pid");
    $st->execute(array(':pid' => $projectId));
    $oldFetch = $st->fetchAll();
    if (is_array($oldFetch)) {
        foreach ($oldFetch as $r) array_push($oldRows, $r);
    }
} catch (Exception $e) {
    error_log('[unit_price_update_preview] old unit price load failed: ' . $e->getMessage());
    cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '기존 단가내역 조회 실패: ' . $e->getMessage()));
}

$oldMap = array();
foreach ($oldRows as $r) $oldMap[cpms_unit_price_update_preview_key($r)] = $r;

$seen = array();
$changes = array();
$summary = array('kept' => 0, 'changed' => 0, 'inserted' => 0, 'excluded' => 0);

foreach ($newRows as $nr) {
    if (!is_array($nr)) continue;
    $k = cpms_unit_price_update_preview_key($nr);
    if ($k === '||') continue;

    if (isset($oldMap[$k])) {
        $seen[$k] = 1;
        $or = $oldMap[$k];
        $changed = false;
        $changed = $changed || !cpms_unit_price_update_preview_num_same(isset($or['qty']) ? $or['qty'] : null, isset($nr['qty']) ? $nr['qty'] : null);
        $changed = $changed || !cpms_unit_price_update_preview_num_same(isset($or['unit_price']) ? $or['unit_price'] : null, isset($nr['unit_price']) ? $nr['unit_price'] : null);
        $changed = $changed || !cpms_unit_price_update_preview_num_same(isset($or['labor_unit_price']) ? $or['labor_unit_price'] : null, isset($nr['labor_unit_price']) ? $nr['labor_unit_price'] : null);
        $changed = $changed || !cpms_unit_price_update_preview_num_same(isset($or['material_unit_price']) ? $or['material_unit_price'] : null, isset($nr['material_unit_price']) ? $nr['material_unit_price'] : null);
        $changed = $changed || !cpms_unit_price_update_preview_num_same(isset($or['expense_unit_price']) ? $or['expense_unit_price'] : null, isset($nr['expense_unit_price']) ? $nr['expense_unit_price'] : null);
        $changed = $changed || !cpms_unit_price_update_preview_text_same(isset($or['remark']) ? $or['remark'] : '', isset($nr['remark']) ? $nr['remark'] : '');
        if ($changed) $summary['changed']++;
        else $summary['kept']++;
        array_push($changes, array(
            'status' => ($changed ? '변경' : '유지'),
            'old_id' => (int)$or['id'],
            'old_row' => $or,
            'row' => $nr,
            'diff' => array(
                'qty' => array('old' => isset($or['qty']) ? $or['qty'] : null, 'new' => isset($nr['qty']) ? $nr['qty'] : null),
                'unit_price' => array('old' => isset($or['unit_price']) ? $or['unit_price'] : null, 'new' => isset($nr['unit_price']) ? $nr['unit_price'] : null),
                'material_unit_price' => array('old' => isset($or['material_unit_price']) ? $or['material_unit_price'] : null, 'new' => isset($nr['material_unit_price']) ? $nr['material_unit_price'] : null),
                'labor_unit_price' => array('old' => isset($or['labor_unit_price']) ? $or['labor_unit_price'] : null, 'new' => isset($nr['labor_unit_price']) ? $nr['labor_unit_price'] : null),
                'expense_unit_price' => array('old' => isset($or['expense_unit_price']) ? $or['expense_unit_price'] : null, 'new' => isset($nr['expense_unit_price']) ? $nr['expense_unit_price'] : null)
            )
        ));
    } else {
        $summary['inserted']++;
        array_push($changes, array('status' => '신규', 'old_id' => 0, 'row' => $nr));
    }
}

$excluded = array();
foreach ($oldRows as $or) {
    $k = cpms_unit_price_update_preview_key($or);
    if (isset($seen[$k])) continue;
    if (isset($or['is_active']) && (int)$or['is_active'] === 0) continue;
    $summary['excluded']++;
    array_push($excluded, $or);
}

$token = bin2hex(openssl_random_pseudo_bytes(16));
if (!isset($_SESSION['unit_price_update']) || !is_array($_SESSION['unit_price_update'])) $_SESSION['unit_price_update'] = array();

$changeDir = $cpmsRoot . '/storage/contracts/' . $projectId . '/changes';
if (!is_dir($changeDir)) @mkdir($changeDir, 0777, true);
$tmpStored = $changeDir . '/tmp_' . $token . '.xlsx';
if (!@move_uploaded_file($tmp, $tmpStored)) {
    error_log('[unit_price_update_preview] move uploaded file failed: ' . $tmpStored);
    cpms_unit_price_update_preview_out(array('ok' => false, 'message' => '미리보기 파일 임시 저장 실패'));
}

$_SESSION['unit_price_update'][$token] = array(
    'project_id' => $projectId,
    'file_name' => $name,
    'created_at' => time(),
    'rows' => $newRows,
    'stored_path' => $tmpStored,
    'summary' => $summary,
    'debug' => isset($parsed['debug']) ? $parsed['debug'] : array()
);

cpms_unit_price_update_preview_out(array(
    'ok' => true,
    'token' => $token,
    'changes' => $changes,
    'excluded' => $excluded,
    'summary' => $summary,
    'detected_columns' => isset($parsed['detected_columns']) ? $parsed['detected_columns'] : array(),
    'sheet_name' => isset($parsed['sheet_name']) ? $parsed['sheet_name'] : '',
    'header_end_row' => isset($parsed['header_end_row']) ? (int)$parsed['header_end_row'] : 0,
    'data_start_row' => isset($parsed['data_start_row']) ? (int)$parsed['data_start_row'] : 0,
    'debug' => isset($parsed['debug']) ? $parsed['debug'] : array()
));
