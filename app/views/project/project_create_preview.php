<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/unit_price_parser.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('cpms_project_create_preview_out')) {
function cpms_project_create_preview_out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}}

if (!Auth::check()) cpms_project_create_preview_out(array('ok' => 0, 'message' => '로그인이 필요합니다.'));

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) cpms_project_create_preview_out(array('ok' => 0, 'message' => '권한이 없습니다.'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cpms_project_create_preview_out(array('ok' => 0, 'message' => 'Method Not Allowed'));

$csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($csrf)) cpms_project_create_preview_out(array('ok' => 0, 'message' => '보안 토큰이 유효하지 않습니다.'));

$fileKey = null;
if (isset($_FILES['excel']) && is_array($_FILES['excel'])) $fileKey = 'excel';
if ($fileKey === null && isset($_FILES['xlsx']) && is_array($_FILES['xlsx'])) $fileKey = 'xlsx';
if ($fileKey === null) cpms_project_create_preview_out(array('ok' => 0, 'message' => '업로드 파일이 없습니다.'));

$file = $_FILES[$fileKey];
$tmpFile = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
$fileName = isset($file['name']) ? (string)$file['name'] : '';
$errorCode = isset($file['error']) ? (int)$file['error'] : 999;

if ($errorCode !== UPLOAD_ERR_OK || $tmpFile === '' || !is_uploaded_file($tmpFile)) {
    cpms_project_create_preview_out(array('ok' => 0, 'message' => '업로드에 실패했습니다.'));
}
if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'xlsx') {
    cpms_project_create_preview_out(array('ok' => 0, 'message' => '.xlsx 파일만 업로드할 수 있습니다.'));
}

$pdo = Db::pdo();
if (!$pdo) cpms_project_create_preview_out(array('ok' => 0, 'message' => 'DB 연결 실패'));

$parsed = cpms_project_parse_unit_price_xlsx($pdo, $tmpFile);
if (!is_array($parsed) || empty($parsed['ok'])) {
    cpms_project_create_preview_out(array(
        'ok' => 0,
        'message' => isset($parsed['message']) ? $parsed['message'] : '엑셀 파싱에 실패했습니다.'
    ));
}

$rows = isset($parsed['rows']) && is_array($parsed['rows']) ? $parsed['rows'] : array();
if (count($rows) === 0) {
    cpms_project_create_preview_out(array('ok' => 0, 'message' => '가져올 단가내역 데이터가 없습니다.'));
}

$token = bin2hex(openssl_random_pseudo_bytes(16));
if (!isset($_SESSION['project_create_unit_price']) || !is_array($_SESSION['project_create_unit_price'])) {
    $_SESSION['project_create_unit_price'] = array();
}
$_SESSION['project_create_unit_price'][$token] = array(
    'created_at' => time(),
    'file_name' => $fileName,
    'rows' => $rows,
    'detected_columns' => isset($parsed['detected_columns']) ? $parsed['detected_columns'] : array(),
    'sheet_name' => isset($parsed['sheet_name']) ? $parsed['sheet_name'] : '',
    'header_end_row' => isset($parsed['header_end_row']) ? (int)$parsed['header_end_row'] : 0,
    'data_start_row' => isset($parsed['data_start_row']) ? (int)$parsed['data_start_row'] : 0,
    'debug' => isset($parsed['debug']) ? $parsed['debug'] : array()
);

cpms_project_create_preview_out(array(
    'ok' => 1,
    'token' => $token,
    'rows' => $rows,
    'detected_columns' => isset($parsed['detected_columns']) ? $parsed['detected_columns'] : array(),
    'sheet_name' => isset($parsed['sheet_name']) ? $parsed['sheet_name'] : '',
    'header_end_row' => isset($parsed['header_end_row']) ? (int)$parsed['header_end_row'] : 0,
    'data_start_row' => isset($parsed['data_start_row']) ? (int)$parsed['data_start_row'] : 0,
    'debug' => isset($parsed['debug']) ? $parsed['debug'] : array()
));
