<?php
/**
 * C:\www\cpms\app\views\admin\workforce_import_process.php
 * - 인력관리 엑셀 미리보기 확인 후 실제 DB 저장
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/WorkerImportService.php';
require_once __DIR__ . '/../../services/ExcelWorkerImporter.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!(Auth::isMaster() || Auth::canManageEmployees())) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
csrf_validate();

$token = isset($_POST['import_token']) ? trim((string)$_POST['import_token']) : '';
if ($token === '' || !isset($_SESSION['worker_import_preview'][$token]) || !is_array($_SESSION['worker_import_preview'][$token])) {
    flash_set('danger', '미리보기 데이터가 만료되었습니다. 엑셀을 다시 업로드해주세요.');
    header('Location: ?r=관리&tab=workforce');
    exit;
}

$preview = $_SESSION['worker_import_preview'][$token];
$filePath = isset($preview['file_path']) ? (string)$preview['file_path'] : '';
$originalFilename = isset($preview['original_filename']) ? (string)$preview['original_filename'] : '';
$storedFilename = isset($preview['stored_filename']) ? (string)$preview['stored_filename'] : '';
$defaultAgencyName = isset($_POST['default_agency_name']) ? trim((string)$_POST['default_agency_name']) : '';
$mapping = isset($_POST['mapping']) && is_array($_POST['mapping']) ? $_POST['mapping'] : (isset($preview['mapping']) ? $preview['mapping'] : array());
$updateDuplicate = isset($_POST['update_duplicate']) && (string)$_POST['update_duplicate'] === '1';

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('danger', 'DB 연결 실패');
    header('Location: ?r=관리&tab=workforce');
    exit;
}

$user = Auth::user();
$userId = (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0;

$importer = new ExcelWorkerImporter();
$mapping = $importer->normalizeMapping($mapping);
$service = new WorkerImportService($pdo);
$result = $service->process($filePath, $mapping, $defaultAgencyName, $updateDuplicate, $userId, $originalFilename, $storedFilename);

unset($_SESSION['worker_import_preview'][$token]);

if (!is_array($result) || empty($result['ok'])) {
    flash_set('danger', is_array($result) && isset($result['message']) ? $result['message'] : 'import 처리에 실패했습니다.');
    header('Location: ?r=관리&tab=workforce');
    exit;
}

error_log('[workforce_import] batch_id=' . (int)$result['batch_id'] . ' user=' . (string)Auth::userEmail());
flash_set('success', 'import 완료: 신규 ' . (int)$result['success_rows'] . '건 / 업데이트 ' . (int)$result['update_rows'] . '건 / 제외 ' . (int)$result['skip_rows'] . '건 / 오류 ' . (int)$result['error_rows'] . '건');
header('Location: ?r=관리&tab=workforce');
exit;
