<?php
/**
 * 공무 협업툴 전용 앱 진입 화면
 * - 공무 index.php에서 무거운 협업툴을 무조건 require하지 않도록 분리한다.
 * - safe=1일 때는 collaboration.php를 불러오지 않고 최소 진단 화면만 보여준다.
 * - PHP 5.6 호환 문법만 사용한다.
 */

use App\Core\Auth;
use App\Core\Db;

if (!function_exists('cpms_pa_collab_app_table_exists')) {
function cpms_pa_collab_app_table_exists($pdo, $tableName) {
    if (!$pdo) return false;
    try {
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table_name");
        $st->execute(array(':db' => $dbName, ':table_name' => (string)$tableName));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_pa_collab_app_count_projects')) {
function cpms_pa_collab_app_count_projects($pdo) {
    if (!$pdo || !cpms_pa_collab_app_table_exists($pdo, 'cpms_projects')) return '확인 불가';
    try {
        return (string)(int)$pdo->query('SELECT COUNT(*) FROM cpms_projects')->fetchColumn();
    } catch (Exception $e) {
        return '확인 실패';
    }
}}

if (!function_exists('cpms_pa_collab_app_bool_text')) {
function cpms_pa_collab_app_bool_text($value) {
    return $value ? '정상' : '확인 필요';
}}

if (!function_exists('cpms_pa_collab_app_error_html')) {
function cpms_pa_collab_app_error_html($title, $message) {
    $title = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
    return '<main class="pa-safe-wrap"><section class="pa-safe-card pa-safe-error">'
        . '<h1 class="pa-safe-title">' . $title . '</h1>'
        . '<div>' . $message . '</div>'
        . '<div class="pa-safe-actions">'
        . '<a class="pa-safe-btn primary" href="?r=public_affairs_collab_repair" target="_blank">저장소 복구 실행</a>'
        . '<a class="pa-safe-btn primary" href="?r=public_affairs_collab&safe=1">안전 모드로 열기</a>'
        . '<a class="pa-safe-btn" href="?r=public_affairs_collab_debug" target="_blank">진단 페이지 열기</a>'
        . '<a class="pa-safe-btn" href="?r=공무">공무 화면으로 돌아가기</a>'
        . '</div></section></main>';
}}

$safeMode = (isset($_GET['safe']) && (string)$_GET['safe'] === '1');
$rootDir = dirname(dirname(dirname(__DIR__)));
$storageRoot = function_exists('cpms_storage_root') ? cpms_storage_root() : ($rootDir . '/storage');
$collabStorage = $storageRoot . '/public_affairs_collab';
$collaborationView = __DIR__ . '/collaboration.php';
$pdo = null;
try {
    $pdo = Db::pdo();
} catch (Exception $e) {
    $pdo = null;
}

$paCollabRenderingMain = false;
$paCollabBufferStarted = false;
register_shutdown_function(function() use (&$paCollabRenderingMain, &$paCollabBufferStarted) {
    if (!$paCollabRenderingMain) return;
    $error = error_get_last();
    if (!is_array($error) || !isset($error['type'])) return;
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
    if (!in_array((int)$error['type'], $fatalTypes, true)) return;
    if ($paCollabBufferStarted && ob_get_level() > 0) @ob_end_clean();
    if (function_exists('http_response_code')) http_response_code(200);
    echo cpms_pa_collab_app_error_html(
        '공무 협업툴을 여는 중 문제가 발생했습니다',
        '일반 모드 화면에서 서버 오류가 발생했습니다. 안전 모드 또는 진단 페이지에서 원인을 확인해주세요.'
    );
    echo '</body></html>';
});

?><!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>공무 협업툴</title>
  <style>
    body{margin:0;font-family:Arial,'Malgun Gothic',sans-serif;background:#f5f7fb;color:#172033}
    .pa-safe-wrap{max-width:980px;margin:0 auto;padding:36px 20px}
    .pa-safe-card{background:#fff;border:1px solid #dbe3ef;border-radius:14px;padding:22px;box-shadow:0 8px 24px rgba(15,23,42,.08)}
    .pa-safe-title{font-size:24px;font-weight:900;margin:0 0 8px}
    .pa-safe-desc{font-size:14px;color:#64748b;font-weight:700;margin-bottom:18px}
    .pa-safe-grid{display:grid;grid-template-columns:220px minmax(0,1fr);border-top:1px solid #eef2f7}
    .pa-safe-grid div{padding:10px 8px;border-bottom:1px solid #eef2f7;font-size:14px}
    .pa-safe-grid b{color:#334155}
    .pa-safe-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}
    .pa-safe-btn{display:inline-flex;text-decoration:none;border-radius:10px;padding:10px 14px;font-weight:900;border:1px solid #dbe3ef;color:#172033;background:#fff}
    .pa-safe-btn.primary{background:#0f766e;border-color:#0f766e;color:#fff}
    .pa-safe-error{border-color:#fecaca;background:#fff1f2;color:#991b1b}
  </style>
</head>
<body>
<?php if ($safeMode): ?>
  <main class="pa-safe-wrap">
    <section class="pa-safe-card">
      <h1 class="pa-safe-title">공무 협업툴 안전 모드</h1>
      <div class="pa-safe-desc">전체 협업툴 화면을 불러오지 않고 접속/저장소 상태만 확인합니다.</div>
      <div class="pa-safe-grid">
        <div><b>로그인</b></div><div><?php echo h(cpms_pa_collab_app_bool_text(Auth::check())); ?></div>
        <div><b>사용자</b></div><div><?php echo h((string)Auth::userName() . ' / ' . (string)Auth::userEmail()); ?></div>
        <div><b>부서/권한</b></div><div><?php echo h((string)Auth::userDepartment() . ' / ' . (string)Auth::userRole()); ?></div>
        <div><b>DB 연결</b></div><div><?php echo h(cpms_pa_collab_app_bool_text($pdo ? true : false)); ?></div>
        <div><b>프로젝트 테이블</b></div><div><?php echo h(cpms_pa_collab_app_bool_text(cpms_pa_collab_app_table_exists($pdo, 'cpms_projects'))); ?></div>
        <div><b>직원 테이블</b></div><div><?php echo h(cpms_pa_collab_app_bool_text(cpms_pa_collab_app_table_exists($pdo, 'employees'))); ?></div>
        <div><b>기존 프로젝트 수</b></div><div><?php echo h(cpms_pa_collab_app_count_projects($pdo)); ?></div>
        <div><b>저장소 경로</b></div><div><?php echo h($collabStorage); ?></div>
        <div><b>저장소 폴더</b></div><div><?php echo h(cpms_pa_collab_app_bool_text(is_dir($collabStorage))); ?></div>
        <div><b>저장소 쓰기</b></div><div><?php echo h(cpms_pa_collab_app_bool_text(is_dir($collabStorage) && is_writable($collabStorage))); ?></div>
        <div><b>tasks.json</b></div><div><?php echo h(cpms_pa_collab_app_bool_text(is_file($collabStorage . '/tasks.json') && is_readable($collabStorage . '/tasks.json'))); ?></div>
        <div><b>settings.json</b></div><div><?php echo h(cpms_pa_collab_app_bool_text(is_file($collabStorage . '/settings.json') && is_readable($collabStorage . '/settings.json'))); ?></div>
        <div><b>CSS/JS</b></div><div><?php echo h(cpms_pa_collab_app_bool_text(is_file($rootDir . '/public/assets/css/public_affairs_collaboration.css') && is_file($rootDir . '/public/assets/js/public_affairs_collaboration.js'))); ?></div>
      </div>
      <div class="pa-safe-actions">
        <a class="pa-safe-btn primary" href="?r=public_affairs_collab_repair" target="_blank">저장소 복구 실행</a>
        <a class="pa-safe-btn primary" href="?r=public_affairs_collab">일반 모드로 열기</a>
        <a class="pa-safe-btn" href="?r=public_affairs_collab_debug" target="_blank">진단 페이지 열기</a>
        <a class="pa-safe-btn" href="?r=공무">공무 화면으로 돌아가기</a>
      </div>
    </section>
  </main>
<?php else: ?>
  <?php
    if (!is_file($collaborationView)) {
        ?>
        <main class="pa-safe-wrap">
          <section class="pa-safe-card pa-safe-error">
            <h1 class="pa-safe-title">공무 협업툴을 열 수 없습니다</h1>
            <div>협업툴 화면 파일을 찾을 수 없습니다. 진단 페이지에서 파일 상태를 확인해주세요.</div>
            <div class="pa-safe-actions">
              <a class="pa-safe-btn primary" href="?r=public_affairs_collab&safe=1">안전 모드로 열기</a>
              <a class="pa-safe-btn" href="?r=public_affairs_collab_debug" target="_blank">진단 페이지 열기</a>
            </div>
          </section>
        </main>
        <?php
    } else {
        $paCollabServiceFile = $rootDir . '/app/services/PublicAffairsCollaborationService.php';
        if (!is_file($paCollabServiceFile)) {
            echo cpms_pa_collab_app_error_html(
                '공무 협업툴을 열 수 없습니다',
                '협업툴 서비스 파일을 찾을 수 없습니다. 진단 페이지에서 파일 상태를 확인해주세요.'
            );
        } else {
            try {
                $_GET['tab'] = 'collaboration';
                $paCollabAutoOpen = true;
                $paCollabRenderingMain = true;
                ob_start();
                $paCollabBufferStarted = true;
                require_once $paCollabServiceFile;
                if (!function_exists('cpms_public_affairs_collab_bootstrap_storage')) {
                    $paCollabContent = ob_get_clean();
                    $paCollabBufferStarted = false;
                    $paCollabRenderingMain = false;
                    echo cpms_pa_collab_app_error_html(
                        '공무 협업툴 저장소를 준비하지 못했습니다',
                        '저장소 초기화 함수를 찾을 수 없습니다. 진단 페이지에서 서비스 파일 상태를 확인해주세요.'
                    );
                } else {
                    $bootstrap = cpms_public_affairs_collab_bootstrap_storage(true);
                    if (empty($bootstrap['ok'])) {
                        $paCollabContent = ob_get_clean();
                        $paCollabBufferStarted = false;
                        $paCollabRenderingMain = false;
                        echo cpms_pa_collab_app_error_html(
                            '공무 협업툴 저장소를 준비하지 못했습니다',
                            isset($bootstrap['message']) ? (string)$bootstrap['message'] : 'storage/public_affairs_collab 폴더 또는 기본 JSON 파일을 만들 수 없습니다.'
                        );
                    } else {
                        require $collaborationView;
                        $paCollabContent = ob_get_clean();
                        $paCollabBufferStarted = false;
                        $paCollabRenderingMain = false;
                        if (trim((string)$paCollabContent) === '') {
                            echo cpms_pa_collab_app_error_html(
                                '공무 협업툴 화면을 표시할 수 없습니다',
                                '협업툴 화면 출력이 비어 있습니다. 진단 페이지에서 파일과 저장소 상태를 확인해주세요.'
                            );
                        } else {
                            echo $paCollabContent;
                        }
                    }
                }
            } catch (Exception $e) {
                $paCollabRenderingMain = false;
                if ($paCollabBufferStarted && ob_get_level() > 0) @ob_end_clean();
                echo cpms_pa_collab_app_error_html(
                    '공무 협업툴을 여는 중 문제가 발생했습니다',
                    '저장소 권한 또는 설정 파일을 확인해주세요. 자세한 내용은 진단 페이지에서 확인할 수 있습니다.'
                );
            }
        }
    }
  ?>
<?php endif; ?>
</body>
</html>
