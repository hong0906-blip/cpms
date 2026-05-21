<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/tasks/helpers.php';

use App\Core\Auth;
use App\Core\Db;

if (!(Auth::isMaster() || Auth::canManageEmployees() || Auth::userRole() === 'executive')) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$pdo = Db::pdo();
$results = array();
cpms_tasks_ensure_schema($pdo, $results);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>CPMS 업무 요청 DB 설치/확인</title>
<style>
body{font-family:Arial,sans-serif;margin:20px;color:#111;background:#f8fafc}
.wrap{max-width:1100px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.06)}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;font-size:13px}
.ok{color:#166534;font-weight:bold}
.bad{color:#b91c1c;font-weight:bold}
</style>
</head>
<body>
<div class="wrap">
    <h2 style="margin:0 0 8px 0;">CPMS 업무 요청 DB 설치/확인</h2>
    <p style="margin:0 0 16px 0;color:#475569;">나의 할일 / 업무요청 기능에서 사용하는 테이블과 업로드 폴더를 확인하거나 생성합니다.</p>
    <table>
        <tr>
            <th>구분</th>
            <th>대상</th>
            <th>결과</th>
            <th>메시지</th>
        </tr>
        <?php foreach ($results as $row): ?>
            <tr>
                <td><?php echo h($row['type']); ?></td>
                <td><?php echo h($row['name']); ?></td>
                <td class="<?php echo $row['ok'] ? 'ok' : 'bad'; ?>"><?php echo $row['ok'] ? '성공' : '실패'; ?></td>
                <td><?php echo h($row['msg']); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="margin-top:16px;"><a href="?r=대시보드">대시보드로 이동</a></p>
</div>
</body>
</html>
