<?php
/**
 * - 공사: 노무비 공수 다운로드(GET)
 * - 프로젝트/월 기준 엑셀용 HTML 다운로드
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$projectId = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
$selectedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';

if ($projectId <= 0 || $selectedMonth === '') {
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

$role = Auth::userRole();
$dept = Auth::userDepartment();

// 공사/임원만 허용
if (!($role === 'executive' || $dept === '공사')) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$pdo = Db::pdo();
if (!$pdo) { http_response_code(500); echo 'DB Error'; exit; }

// 프로젝트 조회
try {
    $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $projectId, PDO::PARAM_INT);
    $st->execute();
    $projectRow = $st->fetch();
} catch (Exception $e) {
    $projectRow = false;
}

if (!$projectRow) { http_response_code(404); echo 'Not Found'; exit; }

// 공사 담당(현장) 조회
$siteName = '';
try {
    $stR = $pdo->prepare("SELECT site_manager_id FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
    $stR->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $stR->execute();
    $siteId = (int)$stR->fetchColumn();
    if ($siteId > 0) {
        $stN = $pdo->prepare("SELECT name FROM employees WHERE id = :id LIMIT 1");
        $stN->bindValue(':id', $siteId, \PDO::PARAM_INT);
        $stN->execute();
        $siteName = (string)$stN->fetchColumn();
    }
} catch (Exception $e) {
    $siteName = '';
}

// 월 범위 계산
$periodStart = $selectedMonth . '-01';
try {
    $periodEndObj = new DateTime($periodStart);
    $periodEndObj->modify('last day of this month');
    $periodEnd = $periodEndObj->format('Y-m-d');
} catch (Exception $e) {
    $periodEnd = $periodStart;
}

$fileName = '노무비_공수_' . $selectedMonth . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

?>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo h($fileName); ?></title>
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 4px; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
<?php
require __DIR__ . '/tabs/partials/labor_sheet_table.php';
?>
</body>
</html>