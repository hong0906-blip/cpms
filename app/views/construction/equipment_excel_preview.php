<?php
/**
 * C:\www\cpms\app\views\construction\equipment_excel_preview.php
 * - 장비비 엑셀 업로드 후 미리보기 데이터 생성
 * - 실제 DB 저장은 equipment_excel_save.php에서 처리
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/equipment_gongsu_approval_helper.php';
require_once __DIR__ . '/../../services/EquipmentExcelImporter.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.');
    header('Location: ?r=공사');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$baseYm = isset($_POST['base_ym']) ? trim((string)$_POST['base_ym']) : '';
if ($baseYm === '') {
    $baseYm = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
}
if (!preg_match('/^\d{4}-\d{2}$/', $baseYm)) {
    $baseYm = date('Y-m');
}

function equipment_excel_preview_redirect($projectId, $ym, $token)
{
    $url = '?r=공사&pid=' . (int)$projectId . '&tab=equipment&equip_tab=input&ym=' . urlencode((string)$ym);
    if ($token !== '') {
        $url .= '&equipment_excel_token=' . urlencode((string)$token);
    }
    return $url;
}

function equipment_excel_preview_has_phpexcel()
{
    if (class_exists('PHPExcel_IOFactory')) return true;

    $root = dirname(dirname(dirname(__DIR__)));
    $candidates = array(
        $root . '/app/libraries/PHPExcel/PHPExcel.php',
        $root . '/app/libraries/PHPExcel/PHPExcel/IOFactory.php',
        $root . '/vendor/phpoffice/phpexcel/Classes/PHPExcel.php',
        $root . '/vendor/phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php'
    );
    foreach ($candidates as $file) {
        if (is_file($file)) {
            return true;
        }
    }

    return false;
}

if ($projectId <= 0) {
    flash_set('error', '프로젝트 정보가 올바르지 않습니다.');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}

if (!isset($_FILES['equipment_excel_file']) || !is_array($_FILES['equipment_excel_file'])) {
    flash_set('error', '업로드할 장비비 엑셀 파일을 선택해주세요.');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}

$file = $_FILES['equipment_excel_file'];
$errorCode = isset($file['error']) ? (int)$file['error'] : 4;
$tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
$originalName = isset($file['name']) ? basename((string)$file['name']) : '';
$fileSize = isset($file['size']) ? (int)$file['size'] : 0;
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
    flash_set('error', '엑셀 파일 업로드에 실패했습니다.');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}
if ($ext !== 'xlsx' && $ext !== 'xls') {
    flash_set('error', '장비비 엑셀은 .xlsx 또는 .xls 파일만 업로드할 수 있습니다.');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}
if ($ext === 'xls' && !equipment_excel_preview_has_phpexcel()) {
    flash_set('error', '.xls 파일은 현재 서버에서 미리보기를 만들 수 없습니다. 엑셀에서 .xlsx로 저장한 뒤 업로드해주세요.');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}
if ($fileSize <= 0 || $fileSize > (20 * 1024 * 1024)) {
    flash_set('error', '엑셀 파일은 20MB 이하만 업로드할 수 있습니다.');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}
cpms_equipment_gongsu_ensure_schema($pdo);

$cpmsRoot = dirname(dirname(dirname(__DIR__)));
$uploadDir = $cpmsRoot . '/uploads/construction/equipment_excel/' . (int)$projectId . '/' . $baseYm;
if (!cpms_ensure_dir($uploadDir)) {
    flash_set('error', '업로드 폴더를 만들 수 없습니다.');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}

$storedName = 'equipment_' . (int)$projectId . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
$storedPath = rtrim($uploadDir, '/\\') . '/' . $storedName;
if (!@move_uploaded_file($tmpName, $storedPath)) {
    flash_set('error', '업로드 파일 저장에 실패했습니다.');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}

$importer = new EquipmentExcelImporter($pdo);
$parseResult = $importer->parse($storedPath, $projectId, $baseYm);
if (!is_array($parseResult) || (isset($parseResult['error']) && $parseResult['error'] !== '')) {
    $message = is_array($parseResult) && isset($parseResult['error']) ? (string)$parseResult['error'] : '엑셀 파싱에 실패했습니다.';
    flash_set('error', $message);
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}

$rows = isset($parseResult['rows']) && is_array($parseResult['rows']) ? $parseResult['rows'] : array();
if (count($rows) <= 0) {
    flash_set('error', '엑셀에서 등록할 장비비 금액을 찾지 못했습니다. J~AE 날짜별 금액 영역을 확인해주세요.');
    header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, ''));
    exit;
}

$token = substr(md5(uniqid('', true)), 0, 20);
if (!isset($_SESSION['equipment_excel_preview']) || !is_array($_SESSION['equipment_excel_preview'])) {
    $_SESSION['equipment_excel_preview'] = array();
}
$_SESSION['equipment_excel_preview'][$token] = array(
    'project_id' => (int)$projectId,
    'ym' => $baseYm,
    'rows' => $rows,
    'summary' => isset($parseResult['summary']) ? $parseResult['summary'] : array(),
    'warnings' => isset($parseResult['warnings']) ? $parseResult['warnings'] : array(),
    'sheet_name' => isset($parseResult['sheet_name']) ? (string)$parseResult['sheet_name'] : '',
    'original_name' => $originalName,
    'stored_name' => $storedName,
    'stored_path' => $storedPath,
    'created_at' => time()
);

$summary = isset($parseResult['summary']) && is_array($parseResult['summary']) ? $parseResult['summary'] : array();
$message = '장비비 엑셀 미리보기를 만들었습니다. 등록 가능 '
    . (int)(isset($summary['valid_count']) ? $summary['valid_count'] : 0)
    . '건 / 오류 '
    . (int)(isset($summary['error_count']) ? $summary['error_count'] : 0)
    . '건 / 중복 '
    . (int)(isset($summary['duplicate_count']) ? $summary['duplicate_count'] : 0)
    . '건';
flash_set('success', $message);
header('Location: ' . equipment_excel_preview_redirect($projectId, $baseYm, $token));
exit;
