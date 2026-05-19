<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/unit_price_parser.php';
use App\Core\Auth; use App\Core\Db;
header('Content-Type: application/json; charset=utf-8');
if (!Auth::check()) { echo json_encode(array('ok'=>false,'message'=>'로그인 필요')); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(array('ok'=>false,'message'=>'Method Not Allowed')); exit; }
if (!csrf_check(isset($_POST['_csrf'])?(string)$_POST['_csrf']:'')) { echo json_encode(array('ok'=>false,'message'=>'보안 토큰 오류')); exit; }
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) { echo json_encode(array('ok'=>false,'message'=>'프로젝트 ID 오류')); exit; }
if (!isset($_FILES['xlsx']) || !is_array($_FILES['xlsx'])) { echo json_encode(array('ok'=>false,'message'=>'파일이 없습니다.')); exit; }
$tmp = isset($_FILES['xlsx']['tmp_name']) ? (string)$_FILES['xlsx']['tmp_name'] : '';
$name = isset($_FILES['xlsx']['name']) ? (string)$_FILES['xlsx']['name'] : '';
if ($tmp === '' || !is_uploaded_file($tmp)) { echo json_encode(array('ok'=>false,'message'=>'업로드 실패')); exit; }
if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') { echo json_encode(array('ok'=>false,'message'=>'.xlsx만 가능')); exit; }
$cpmsRoot = dirname(dirname(dirname(__DIR__)));
$pdo = Db::pdo();
$parsed = cpms_project_parse_unit_price_xlsx($pdo, $tmp);
if (!$parsed['ok']) { echo json_encode(array('ok'=>false,'message'=>$parsed['message'])); exit; }
$newRows = $parsed['rows'];
$oldRows = array();
$st = $pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id=:pid");
$st->execute(array(':pid'=>$projectId));
foreach ($st->fetchAll() as $r) { $oldRows[] = $r; }
$oldMap = array();
foreach ($oldRows as $r) { $oldMap[trim((string)$r['item_name']).'|'.trim((string)$r['spec']).'|'.trim((string)$r['unit'])] = $r; }
$seen = array(); $changes = array();
foreach ($newRows as $nr) {
 $k = trim((string)$nr['item_name']).'|'.trim((string)$nr['spec']).'|'.trim((string)$nr['unit']);
 if (isset($oldMap[$k])) {
  $seen[$k]=1; $or=$oldMap[$k];
  $changed = ((string)$or['qty'] !== (string)$nr['qty']) || ((string)$or['unit_price'] !== (string)$nr['unit_price']) || ((string)$or['labor_unit_price'] !== (string)$nr['labor_unit_price']) || ((string)$or['material_unit_price'] !== (string)$nr['material_unit_price']) || ((string)$or['safety_unit_price'] !== (string)$nr['safety_unit_price']) || ((string)$or['remark'] !== (string)$nr['remark']) || ((int)$or['is_safety'] !== (int)$nr['is_safety']);
  $changes[] = array('status'=>$changed ? '변경':'유지','old_id'=>(int)$or['id'],'row'=>$nr);
 } else { $changes[] = array('status'=>'신규','old_id'=>0,'row'=>$nr); }
}
$excluded = array();
foreach ($oldRows as $or) {
 $k = trim((string)$or['item_name']).'|'.trim((string)$or['spec']).'|'.trim((string)$or['unit']);
 if (!isset($seen[$k])) $excluded[] = $or;
}
$token = bin2hex(openssl_random_pseudo_bytes(16));
if (!isset($_SESSION['unit_price_update']) || !is_array($_SESSION['unit_price_update'])) $_SESSION['unit_price_update'] = array();
$changeDir = $cpmsRoot . '/storage/contracts/' . $projectId . '/changes';
if (!is_dir($changeDir)) @mkdir($changeDir, 0777, true);
$tmpStored = $changeDir . '/tmp_' . $token . '.xlsx';
@move_uploaded_file($tmp, $tmpStored);
$_SESSION['unit_price_update'][$token] = array('project_id'=>$projectId,'file_name'=>$name,'created_at'=>time(),'rows'=>$newRows,'stored_path'=>$tmpStored);
echo json_encode(array('ok'=>true,'token'=>$token,'changes'=>$changes,'excluded'=>$excluded));