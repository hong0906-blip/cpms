<?php
/**
 * 비용 변경 증빙파일 보호된 보기/다운로드.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdo->prepare("SELECT f.*,r.id AS request_id FROM cpms_cost_change_files f INNER JOIN cpms_cost_change_requests r ON r.id=f.request_id WHERE f.id=:id AND f.is_deleted=0 LIMIT 1");
$st->execute(array(':id'=>$fileId));
$file = $st->fetch(PDO::FETCH_ASSOC);
if (!$file) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}
$request = CostChangeService::requestById($pdo, (int)$file['request_id']);
if (!$request || !CostChangeService::canViewRequest($pdo, $request)) {
    http_response_code(403);
    echo '파일을 열람할 권한이 없습니다.';
    exit;
}
$path = CostChangeService::resolveFilePath($file['stored_path']);
if ($path === '') {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}
$name = basename(str_replace('\\', '/', (string)$file['original_name']));
$name = str_replace(array("\r","\n",'"'), '', $name);
$mime = trim((string)$file['mime_type']);
if ($mime === '') $mime = 'application/octet-stream';
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';
$inlineMimes = array('application/pdf'=>true,'image/jpeg'=>true,'image/png'=>true,'image/gif'=>true,'image/webp'=>true,'text/plain'=>true);
$disposition = (!$download && isset($inlineMimes[$mime])) ? 'inline' : 'attachment';
while (ob_get_level() > 0) @ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;

