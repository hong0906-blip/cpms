<?php
/**
 * C:\www\cpms\app\views\project\progress_statement_file.php
 * 기성내역서 파일 버전 다운로드 GET 처리(로그인·현장 권한 재검사).
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/ProgressStatementService.php';

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo 'Method Not Allowed'; exit; }
$pdo = Db::pdo();
$actor = cpms_progress_statement_actor($pdo);
$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
try {
    if (!$pdo || !cpms_progress_statement_schema_ready($pdo)) throw new Exception('기성내역서 DB가 준비되지 않았습니다.');
    $st = $pdo->prepare("SELECT f.*, s.project_id FROM cpms_progress_statement_files f JOIN cpms_progress_statements s ON s.id=f.statement_id WHERE f.id=:id LIMIT 1");
    $st->execute(array(':id'=>$fileId));
    $file = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($file)) throw new Exception('파일 버전을 찾을 수 없습니다.');
    if (!cpms_progress_statement_can_view_project($pdo, (int)$file['project_id'], $actor)) {
        http_response_code(403); echo '403 Forbidden'; exit;
    }
    $path = (string)$file['server_file_path'];
    if ($path === '' || !is_file($path)) throw new Exception('서버에서 파일을 찾을 수 없습니다.');
    $name = basename(str_replace(array("\r","\n"), '', (string)$file['original_file_name']));
    if ($name === '') $name = 'progress_statement.xlsx';
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    header('Content-Type: ' . ((string)$file['mime_type'] !== '' ? (string)$file['mime_type'] : 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    @set_time_limit(0);
    while (ob_get_level() > 0) @ob_end_clean();
    $handle = @fopen($path, 'rb');
    if (!$handle) throw new Exception('파일을 열 수 없습니다.');
    while (!feof($handle)) {
        echo fread($handle, 1048576);
        @flush();
    }
    fclose($handle);
    exit;
} catch (Exception $e) {
    http_response_code(404); echo h($e->getMessage()); exit;
}
