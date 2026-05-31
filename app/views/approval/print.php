<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/document_templates.php';
require_once __DIR__ . '/template_proposal.php';
require_once __DIR__ . '/template_leave.php';
require_once __DIR__ . '/template_unused_leave.php';

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

$st = $pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:id ORDER BY line_order");
$st->execute(array(':id' => $id));
$lines = $st->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($lines)) {
    $lines = array();
}
$content = approval_parse_content(isset($d['content']) ? $d['content'] : '');
$filesByType = array();
if (isset($d['doc_type']) && $d['doc_type'] === 'proposal' && approval_table_exists($pdo, 'cpms_approval_files')) {
    $fs = $pdo->prepare("SELECT * FROM cpms_approval_files WHERE document_id=:id ORDER BY id DESC");
    $fs->execute(array(':id' => $id));
    $fileRows = $fs->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($fileRows)) {
        for ($i = 0; $i < count($fileRows); $i++) {
            $k = isset($fileRows[$i]['file_type']) ? $fileRows[$i]['file_type'] : '';
            if ($k !== '' && !isset($filesByType[$k])) {
                $filesByType[$k] = $fileRows[$i];
            }
        }
    }
}
?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo h(approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%B6%9C%EB%A0%A5')); ?></title>
    <?php require __DIR__ . '/template_style.php'; ?>
</head>
<body>
<div class="no-print" style="padding:10px"><button onclick="window.print()"><?php echo h(approval_ko('%EC%9D%B8%EC%87%84')); ?></button></div>
<?php
if (isset($d['doc_type']) && $d['doc_type'] === 'leave') {
    render_approval_leave_document($content, $lines, 'print', array());
} else if (isset($d['doc_type']) && $d['doc_type'] === 'unused_leave_notice') {
    render_approval_unused_leave_notice_document($content, $lines, 'print', array());
} else if (isset($d['doc_type']) && $d['doc_type'] === 'unused_leave_plan') {
    render_approval_unused_leave_plan_document($content, $lines, 'print', array());
} else {
    render_approval_proposal_document($content, $lines, 'print', $filesByType, array());
}
?>
</body>
</html>
