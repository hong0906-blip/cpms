<?php
/**
 * C:\www\cpms\public\db_setup_issue.php
 * - 이슈/댓글 테이블 생성(웹 클릭)
 * - PHP 5.6 호환
 * - 구버전 MySQL TIMESTAMP 제약 대응: CURRENT_TIMESTAMP는 각 테이블당 1개만 사용
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: text/html; charset=utf-8');

if (!Auth::check()) { header('Location: ?r=login'); exit; }

// 임원 또는 공무/관리만 실행 가능
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }

$pdo = Db::pdo();
if (!$pdo) { echo '<h2 style="font-family:Arial">DB 연결 실패</h2>'; exit; }

function h2($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function execSql($pdo, $sql) { $pdo->exec($sql); }

$msg = ''; $type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        $type = 'error';
        $msg = '보안 토큰이 유효하지 않습니다.';
    } else {
        try {
            execSql($pdo, "CREATE TABLE IF NOT EXISTS cpms_project_issues (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                reason VARCHAR(255) NOT NULL,
                created_by_name VARCHAR(100) NOT NULL,
                created_by_email VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(20) NOT NULL DEFAULT '처리중',
                KEY idx_project (project_id),
                KEY idx_status (status),
                KEY idx_created_by (created_by_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            execSql($pdo, "CREATE TABLE IF NOT EXISTS cpms_project_issue_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                issue_id INT NOT NULL,
                comment_text VARCHAR(255) NOT NULL,
                created_by_name VARCHAR(100) NOT NULL,
                created_by_email VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_issue (issue_id),
                KEY idx_created_by (created_by_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $type = 'ok';
            $msg = '이슈/댓글 테이블 생성(확인) 완료';
        } catch (Exception $e) {
            $type = 'error';
            $msg = '실행 실패: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>이슈 DB 설정</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f6f7fb; margin:0; padding:24px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px; max-width:980px; }
        .row { display:flex; gap:12px; flex-wrap:wrap; }
        .btn { padding:12px 14px; border-radius:12px; border:0; cursor:pointer; font-weight:700; background:#111827; color:#fff; }
        .msg-ok { margin:12px 0; padding:10px 12px; border-radius:12px; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
        .msg-err { margin:12px 0; padding:10px 12px; border-radius:12px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        code { background:#f3f4f6; padding:2px 6px; border-radius:8px; }
        a { color:#2563eb; font-weight:700; text-decoration:none; }
        .muted { color:#6b7280; font-size:13px; margin-top:6px; }
    </style>
</head>
<body>
<div class="card">
    <h2>이슈/댓글 DB 설정</h2>
    <p>아래 버튼을 누르면 <code>cpms_project_issues</code>, <code>cpms_project_issue_comments</code> 테이블을 생성/확인합니다.</p>

    <?php if ($msg !== ''): ?>
        <div class="<?php echo ($type === 'ok') ? 'msg-ok' : 'msg-err'; ?>">
            <?php echo h2($msg); ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?php echo h2(csrf_token()); ?>">
            <button class="btn" type="submit">이슈/댓글 테이블 생성(확인)</button>
        </form>
    </div>

    <div class="muted">
        * 운영 반영 후 보안을 위해 이 파일(<code>public/db_setup_issue.php</code>) 삭제를 권장합니다.
    </div>

    <hr style="border:none;border-top:1px solid #e5e7eb; margin:16px 0;">
    <p>
        공무 메뉴로 이동: <a href="<?php echo h2(base_url()); ?>/?r=공무">공무(프로젝트)</a><br>
        대시보드: <a href="<?php echo h2(base_url()); ?>/?r=대시보드">대시보드</a>
    </p>
</div>
</body>
</html>