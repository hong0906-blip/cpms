<?php
/**
 * C:\www\cpms\public\db_alter_employees.php
 * - DB 컬럼 추가를 웹에서 클릭으로 처리
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: text/html; charset=utf-8');

// 권한: 임원 or 관리부(관리)
if (!Auth::check() || !Auth::canManageEmployees()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    echo '<h2>DB 연결 실패</h2>';
    exit;
}

function h2($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// 현재 DB명
$dbName = '';
try {
    $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
} catch (Exception $e) {}

function columnExists($pdo, $dbName, $table, $column) {
    $sql = "SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME = :tbl
              AND COLUMN_NAME = :col";
    $st = $pdo->prepare($sql);
    $st->bindValue(':db', $dbName);
    $st->bindValue(':tbl', $table);
    $st->bindValue(':col', $column);
    $st->execute();
    return ((int)$st->fetchColumn() > 0);
}

$msg = '';
$type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        $type = 'error';
        $msg = '보안 토큰이 유효하지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        if ($action === 'add_position') {
            try {
                if ($dbName === '') throw new Exception('DB 이름을 가져오지 못했습니다.');

                if (columnExists($pdo, $dbName, 'employees', 'position')) {
                    $type = 'success';
                    $msg = '이미 position(직급) 컬럼이 존재합니다.';
                } else {
                    // MySQL 5.6: ADD COLUMN IF NOT EXISTS 없음 → 직접 체크 후 ALTER
                    $pdo->exec("ALTER TABLE employees ADD COLUMN position VARCHAR(20) NULL AFTER department");
                    $type = 'success';
                    $msg = 'position(직급) 컬럼을 추가했습니다.';
                }
            } catch (Exception $e) {
                $type = 'error';
                $msg = '실패: ' . $e->getMessage();
            }
        }
    }
}

// 상태 체크
$positionExists = false;
try {
    if ($dbName !== '') {
        $positionExists = columnExists($pdo, $dbName, 'employees', 'position');
    }
} catch (Exception $e) {}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>DB 컬럼 설정</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:20px; line-height:1.5;}
    .card{max-width:720px; border:1px solid #e5e7eb; border-radius:16px; padding:18px; background:#fff;}
    .row{display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap;}
    .btn{padding:10px 14px; border-radius:12px; border:1px solid #e5e7eb; background:#111827; color:#fff; cursor:pointer; font-weight:700;}
    .btn:disabled{opacity:.5; cursor:not-allowed;}
    .tag{padding:6px 10px; border-radius:999px; font-size:12px; font-weight:700;}
    .ok{background:#ecfdf5; color:#065f46; border:1px solid #d1fae5;}
    .no{background:#fff7ed; color:#9a3412; border:1px solid #fed7aa;}
    .msg{margin-top:12px; padding:12px; border-radius:12px; font-weight:700;}
    .msg.ok{background:#ecfdf5; border:1px solid #d1fae5; color:#065f46;}
    .msg.err{background:#fef2f2; border:1px solid #fecaca; color:#991b1b;}
    .small{color:#6b7280; font-size:13px;}
  </style>
</head>
<body>
  <div class="card">
    <h2 style="margin:0 0 8px;">직급( position ) 컬럼 설정</h2>
    <div class="small">employees 테이블에 직급 컬럼이 없으면 버튼 클릭으로 자동 추가합니다.</div>

    <div style="height:12px;"></div>

    <div class="row">
      <div>
        <div style="font-weight:800;">employees.position</div>
        <?php if ($positionExists): ?>
          <span class="tag ok">존재함</span>
        <?php else: ?>
          <span class="tag no">없음</span>
        <?php endif; ?>
      </div>

      <form method="post" style="margin:0;">
        <input type="hidden" name="_csrf" value="<?php echo h2(csrf_token()); ?>">
        <input type="hidden" name="action" value="add_position">
        <button class="btn" <?php echo $positionExists ? 'disabled' : ''; ?>>직급 컬럼 추가</button>
      </form>
    </div>

    <?php if ($msg !== ''): ?>
      <div class="msg <?php echo ($type === 'success') ? 'ok' : 'err'; ?>">
        <?php echo h2($msg); ?>
      </div>
    <?php endif; ?>

    <div style="height:10px;"></div>
    <div class="small">
      완료 후 직원명부에서 “직급” 드롭다운이 정상 저장되는지 확인하세요.
    </div>
  </div>
</body>
</html>