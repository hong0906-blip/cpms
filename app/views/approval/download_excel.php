<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';

$pdo = Db::pdo();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$u = \App\Core\Auth::user();

$st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
$st->execute(array(':id' => $id));
$d = $st->fetch(PDO::FETCH_ASSOC);
if (!$d || !approval_can_view_document($pdo, $d, $u)) {
    http_response_code(403);
    exit(approval_ko('%EC%9D%B4%20%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EB%B3%BC%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
}

if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
    exit('PhpSpreadsheet not installed');
}

exit('TODO');
