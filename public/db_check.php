<?php
/**
 * public/db_check.php
 * - DB 연결 진단 페이지 (임원만 접근)
 * - database.php 설정값으로 PDO 연결 시도
 * - 실패 시 정확한 에러 메시지 출력
 *
 * 사용 후 반드시 삭제 권장
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;

// 1) 로그인 체크
if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

// 2) 임원만 허용
if (Auth::userRole() !== 'executive') {
    http_response_code(403);
    echo '<h2 style="font-family:Arial">403 Forbidden</h2>';
    echo '<p style="font-family:Arial">임원 계정만 실행할 수 있습니다.</p>';
    exit;
}

$cfgFile = __DIR__ . '/../app/config/database.php';
$cfgExists = file_exists($cfgFile);
$cfg = $cfgExists ? require $cfgFile : null;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$tryMsg = '';
$ok = false;
$databases = array();

if (!$cfgExists) {
    $tryMsg = 'database.php 파일이 없습니다: /app/config/database.php';
} elseif (!is_array($cfg)) {
    $tryMsg = 'database.php 형식이 올바르지 않습니다. (return array(...) 형태여야 함)';
} else {
    $host = isset($cfg['host']) ? $cfg['host'] : '';
    $port = isset($cfg['port']) ? (int)$cfg['port'] : 3306;
    $db   = isset($cfg['dbname']) ? $cfg['dbname'] : '';
    $user = isset($cfg['user']) ? $cfg['user'] : '';
    $pass = isset($cfg['pass']) ? $cfg['pass'] : '';
    $ch   = isset($cfg['charset']) ? $cfg['charset'] : 'utf8mb4';

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=' . $ch;

    try {
        $pdo = new PDO($dsn, $user, $pass, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));

        $ok = true;
        $tryMsg = '✅ DB 연결 성공! DSN=' . $dsn;

        // DB 목록도 보여주기 (권한 없으면 여기서 예외 날 수 있음)
        try {
            $st = $pdo->query('SHOW DATABASES');
            $databases = $st->fetchAll();
        } catch (Exception $e2) {
            $databases = array();
        }

    } catch (Exception $e) {
        $tryMsg = '❌ DB 연결 실패: ' . $e->getMessage();
    }
}

?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>CPMS DB 연결 진단</title>
<style>
  body{font-family:Arial,sans-serif;background:#f6f7fb;margin:0;padding:24px}
  .wrap{max-width:1000px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
  .hd{padding:18px 20px;border-bottom:1px solid #eef2f7}
  .bd{padding:20px}
  .box{background:#f9fafb;border:1px solid #eef2f7;border-radius:14px;padding:16px}
  .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
  .bad{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}
  .danger{color:#b91c1c;font-weight:bold}
  table{width:100%;border-collapse:collapse;margin-top:10px}
  th,td{border-bottom:1px solid #eef2f7;padding:10px;text-align:left;font-size:13px}
  code{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;font-size:12px}
</style>
</head>
<body>
  <div class="wrap">
    <div class="hd">
      <h2 style="margin:0;">CPMS DB 연결 진단</h2>
      <div class="danger" style="margin-top:6px;">작업 끝나면 이 파일(db_check.php) 삭제하세요.</div>
    </div>
    <div class="bd">
      <div class="box <?php echo $ok ? 'ok' : 'bad'; ?>">
        <div style="font-weight:bold;"><?php echo h($tryMsg); ?></div>
        <div style="margin-top:10px;font-size:12px;color:#374151;">
          설정파일 존재: <b><?php echo $cfgExists ? 'YES' : 'NO'; ?></b><br>
          설정파일 경로: <code>/app/config/database.php</code>
        </div>
      </div>

      <h3 style="margin-top:18px;">현재 설정값(비번은 숨김)</h3>
      <div class="box">
        <?php if (is_array($cfg)): ?>
          <table>
            <tr><th>host</th><td><?php echo h(isset($cfg['host']) ? $cfg['host'] : ''); ?></td></tr>
            <tr><th>port</th><td><?php echo h(isset($cfg['port']) ? $cfg['port'] : ''); ?></td></tr>
            <tr><th>dbname</th><td><?php echo h(isset($cfg['dbname']) ? $cfg['dbname'] : ''); ?></td></tr>
            <tr><th>user</th><td><?php echo h(isset($cfg['user']) ? $cfg['user'] : ''); ?></td></tr>
            <tr><th>pass</th><td>(hidden)</td></tr>
            <tr><th>charset</th><td><?php echo h(isset($cfg['charset']) ? $cfg['charset'] : ''); ?></td></tr>
          </table>
        <?php else: ?>
          <div>설정값을 읽을 수 없습니다.</div>
        <?php endif; ?>
      </div>

      <?php if ($ok && !empty($databases)): ?>
        <h3 style="margin-top:18px;">서버에서 보이는 DB 목록</h3>
        <div class="box">
          <table>
            <tr><th>Database</th></tr>
            <?php foreach ($databases as $d): ?>
              <tr><td><?php echo h(isset($d['Database']) ? $d['Database'] : ''); ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endif; ?>

      <div style="margin-top:18px;font-size:13px;color:#374151;">
        <b>자주 나오는 실패 원인</b><br>
        - Access denied: user/pass가 틀림<br>
        - Unknown database: dbname이 틀림<br>
        - Connection refused / timed out: host/port가 틀리거나 방화벽/외부접속 차단<br>
        - could not find driver: 서버에 PDO MySQL 드라이버가 없음(이 경우 호스팅사 설정 필요)
      </div>
    </div>
  </div>
</body>
</html>