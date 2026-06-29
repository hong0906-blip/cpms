<?php
/**
 * Public affairs collaboration app entry.
 * - Keeps the public affairs section from requiring the heavy collaboration view.
 * - Adds PHP 5.6 compatible fatal tracking for the normal app mode.
 */

use App\Core\Auth;
use App\Core\Db;

$GLOBALS['pa_collab_stage'] = 'app_start';

if (!function_exists('cpms_pa_collab_app_stage')) {
function cpms_pa_collab_app_stage($stage) {
    $GLOBALS['pa_collab_stage'] = (string)$stage;
}}

if (!function_exists('cpms_pa_collab_app_h')) {
function cpms_pa_collab_app_h($value) {
    if (function_exists('h')) return h($value);
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

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
    if (!$pdo || !cpms_pa_collab_app_table_exists($pdo, 'cpms_projects')) return 'check failed';
    try {
        return (string)(int)$pdo->query('SELECT COUNT(*) FROM cpms_projects')->fetchColumn();
    } catch (Exception $e) {
        return 'check failed';
    }
}}

if (!function_exists('cpms_pa_collab_app_bool_text')) {
function cpms_pa_collab_app_bool_text($value) {
    return $value ? 'OK' : 'CHECK';
}}

if (!function_exists('cpms_pa_collab_app_debug_allowed')) {
function cpms_pa_collab_app_debug_allowed() {
    if (!class_exists('App\\Core\\Auth')) return false;
    try {
        if (!\App\Core\Auth::check()) return false;
        if (\App\Core\Auth::isMaster()) return true;
        $role = (string)\App\Core\Auth::userRole();
        $dept = (string)\App\Core\Auth::userDepartment();
        $deptPublic = urldecode('%EA%B3%B5%EB%AC%B4');
        $deptManage = urldecode('%EA%B4%80%EB%A6%AC');
        return ($role === 'executive' || strpos($dept, $deptPublic) !== false || strpos($dept, $deptManage) !== false);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_pa_collab_app_error_html')) {
function cpms_pa_collab_app_error_html($title, $message, $details) {
    $debug = (isset($_GET['debug']) && (string)$_GET['debug'] === '1' && cpms_pa_collab_app_debug_allowed());
    $stage = isset($GLOBALS['pa_collab_stage']) ? (string)$GLOBALS['pa_collab_stage'] : '';
    $html = '<main class="pa-safe-wrap"><section class="pa-safe-card pa-safe-error">';
    $html .= '<h1 class="pa-safe-title">' . cpms_pa_collab_app_h($title) . '</h1>';
    $html .= '<div>' . cpms_pa_collab_app_h($message) . '</div>';
    if ($stage !== '') {
        $html .= '<div style="margin-top:10px;font-weight:900;">stage: ' . cpms_pa_collab_app_h($stage) . '</div>';
    }
    if ($debug && is_array($details)) {
        $html .= '<div style="margin-top:14px;padding:12px;border:1px solid #fecaca;background:#fff;border-radius:10px;color:#7f1d1d;">';
        foreach ($details as $key => $value) {
            $html .= '<div><b>' . cpms_pa_collab_app_h($key) . '</b>: ' . cpms_pa_collab_app_h($value) . '</div>';
        }
        $html .= '</div>';
    }
    $html .= '<div class="pa-safe-actions">';
    $html .= '<a class="pa-safe-btn primary" href="?r=public_affairs_collab&debug=1">Open debug=1</a>';
    $html .= '<a class="pa-safe-btn" href="?r=public_affairs_collab_trace" target="_blank">Open trace</a>';
    $html .= '<a class="pa-safe-btn" href="?r=public_affairs_collab&safe=1">Safe mode</a>';
    $html .= '<a class="pa-safe-btn" href="?r=public_affairs_collab_debug" target="_blank">Debug page</a>';
    $html .= '<a class="pa-safe-btn" href="?r=public_affairs_collab_repair" target="_blank">Repair storage</a>';
    $html .= '<a class="pa-safe-btn" href="?r=%EA%B3%B5%EB%AC%B4">Back to public affairs</a>';
    $html .= '</div></section></main>';
    return $html;
}}

cpms_pa_collab_app_stage('app_path_prepare');
$safeMode = (isset($_GET['safe']) && (string)$_GET['safe'] === '1');
$rootDir = dirname(dirname(dirname(__DIR__)));
$storageRoot = function_exists('cpms_storage_root') ? cpms_storage_root() : ($rootDir . '/storage');
$collabStorage = $storageRoot . '/public_affairs_collab';
$collaborationView = __DIR__ . '/collaboration.php';
$serviceFile = $rootDir . '/app/services/PublicAffairsCollaborationService.php';

$pdo = null;
try {
    cpms_pa_collab_app_stage('app_db_pdo');
    $pdo = Db::pdo();
} catch (Exception $e) {
    $pdo = null;
}

$paCollabHandlingRequest = true;
$paCollabBufferStarted = false;
register_shutdown_function(function() use (&$paCollabHandlingRequest, &$paCollabBufferStarted) {
    if (!$paCollabHandlingRequest) return;
    $error = error_get_last();
    if (!is_array($error) || !isset($error['type'])) return;
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
    if (!in_array((int)$error['type'], $fatalTypes, true)) return;
    if ($paCollabBufferStarted && ob_get_level() > 0) @ob_end_clean();
    if (function_exists('http_response_code')) http_response_code(200);
    echo cpms_pa_collab_app_error_html(
        '공무 협업툴 일반 화면을 여는 중 오류가 발생했습니다.',
        '일반 모드에서 서버 오류가 발생했습니다. debug=1 또는 trace에서 원인을 확인해주세요.',
        array(
            'stage' => isset($GLOBALS['pa_collab_stage']) ? (string)$GLOBALS['pa_collab_stage'] : '',
            'error_type' => isset($error['type']) ? (string)$error['type'] : '',
            'error_message' => isset($error['message']) ? (string)$error['message'] : '',
            'error_file' => isset($error['file']) ? (string)$error['file'] : '',
            'error_line' => isset($error['line']) ? (string)$error['line'] : '',
            'php_version' => PHP_VERSION,
            'user' => class_exists('App\\Core\\Auth') ? ((string)\App\Core\Auth::userName() . ' / ' . (string)\App\Core\Auth::userEmail()) : '',
        )
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
  <?php cpms_pa_collab_app_stage('safe_mode'); ?>
  <main class="pa-safe-wrap">
    <section class="pa-safe-card">
      <h1 class="pa-safe-title">공무 협업툴 안전 모드</h1>
      <div class="pa-safe-desc">전체 협업툴 화면을 불러오지 않고 접속, DB, 저장소 상태만 확인합니다.</div>
      <div class="pa-safe-grid">
        <div><b>login</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_bool_text(Auth::check())); ?></div>
        <div><b>user</b></div><div><?php echo cpms_pa_collab_app_h((string)Auth::userName() . ' / ' . (string)Auth::userEmail()); ?></div>
        <div><b>department / role</b></div><div><?php echo cpms_pa_collab_app_h((string)Auth::userDepartment() . ' / ' . (string)Auth::userRole()); ?></div>
        <div><b>DB</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_bool_text($pdo ? true : false)); ?></div>
        <div><b>cpms_projects</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_bool_text(cpms_pa_collab_app_table_exists($pdo, 'cpms_projects'))); ?></div>
        <div><b>employees</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_bool_text(cpms_pa_collab_app_table_exists($pdo, 'employees'))); ?></div>
        <div><b>project count</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_count_projects($pdo)); ?></div>
        <div><b>storage path</b></div><div><?php echo cpms_pa_collab_app_h($collabStorage); ?></div>
        <div><b>storage dir</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_bool_text(is_dir($collabStorage))); ?></div>
        <div><b>storage writable</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_bool_text(is_dir($collabStorage) && is_writable($collabStorage))); ?></div>
        <div><b>tasks.json</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_bool_text(is_file($collabStorage . '/tasks.json') && is_readable($collabStorage . '/tasks.json'))); ?></div>
        <div><b>settings.json</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_bool_text(is_file($collabStorage . '/settings.json') && is_readable($collabStorage . '/settings.json'))); ?></div>
        <div><b>CSS/JS</b></div><div><?php echo cpms_pa_collab_app_h(cpms_pa_collab_app_bool_text(is_file($rootDir . '/public/assets/css/public_affairs_collaboration.css') && is_file($rootDir . '/public/assets/js/public_affairs_collaboration.js'))); ?></div>
      </div>
      <div class="pa-safe-actions">
        <a class="pa-safe-btn primary" href="?r=public_affairs_collab">일반 모드로 열기</a>
        <a class="pa-safe-btn" href="?r=public_affairs_collab_trace" target="_blank">trace 열기</a>
        <a class="pa-safe-btn" href="?r=public_affairs_collab_debug" target="_blank">진단 페이지 열기</a>
        <a class="pa-safe-btn" href="?r=public_affairs_collab_repair" target="_blank">저장소 복구 실행</a>
        <a class="pa-safe-btn" href="?r=%EA%B3%B5%EB%AC%B4">공무 화면으로 돌아가기</a>
      </div>
    </section>
  </main>
<?php else: ?>
  <?php
    cpms_pa_collab_app_stage('collaboration_file_check');
    if (!is_file($collaborationView)) {
        echo cpms_pa_collab_app_error_html(
            '공무 협업툴을 찾을 수 없습니다.',
            '협업툴 화면 파일이 없습니다. 진단 페이지에서 파일 상태를 확인해주세요.',
            array('file' => $collaborationView)
        );
    } elseif (!is_file($serviceFile)) {
        cpms_pa_collab_app_stage('service_file_check');
        echo cpms_pa_collab_app_error_html(
            '공무 협업툴을 찾을 수 없습니다.',
            '협업툴 서비스 파일이 없습니다. 진단 페이지에서 파일 상태를 확인해주세요.',
            array('file' => $serviceFile)
        );
    } else {
        try {
            $_GET['tab'] = 'collaboration';
            $paCollabAutoOpen = true;
            ob_start();
            $paCollabBufferStarted = true;

            cpms_pa_collab_app_stage('service_require');
            require_once $serviceFile;
            cpms_pa_collab_app_stage('service_require_done');

            if (!function_exists('cpms_public_affairs_collab_bootstrap_storage')) {
                $paCollabContent = ob_get_clean();
                $paCollabBufferStarted = false;
                echo cpms_pa_collab_app_error_html(
                    '공무 협업툴 저장소를 준비하지 못했습니다.',
                    '저장소 초기화 함수를 찾을 수 없습니다.',
                    array('function' => 'cpms_public_affairs_collab_bootstrap_storage')
                );
            } else {
                cpms_pa_collab_app_stage('bootstrap_storage');
                $bootstrap = cpms_public_affairs_collab_bootstrap_storage(true);
                if (empty($bootstrap['ok'])) {
                    $paCollabContent = ob_get_clean();
                    $paCollabBufferStarted = false;
                    echo cpms_pa_collab_app_error_html(
                        '공무 협업툴 저장소를 준비하지 못했습니다.',
                        isset($bootstrap['message']) ? (string)$bootstrap['message'] : 'storage/public_affairs_collab 또는 기본 JSON 파일을 준비하지 못했습니다.',
                        $bootstrap
                    );
                } else {
                    cpms_pa_collab_app_stage('before_collaboration_include');
                    cpms_pa_collab_app_stage('collaboration_include_running');
                    require $collaborationView;
                    cpms_pa_collab_app_stage('collaboration_include_done');
                    $paCollabContent = ob_get_clean();
                    $paCollabBufferStarted = false;
                    if (trim((string)$paCollabContent) === '') {
                        echo cpms_pa_collab_app_error_html(
                            '공무 협업툴 화면을 표시할 수 없습니다.',
                            '협업툴 화면 출력이 비어 있습니다. trace에서 로딩 단계를 확인해주세요.',
                            array('file' => $collaborationView)
                        );
                    } else {
                        echo $paCollabContent;
                    }
                }
            }
        } catch (Exception $e) {
            if ($paCollabBufferStarted && ob_get_level() > 0) @ob_end_clean();
            $paCollabBufferStarted = false;
            echo cpms_pa_collab_app_error_html(
                '공무 협업툴을 여는 중 문제가 발생했습니다.',
                '저장소 권한 또는 설정 파일을 확인해주세요. 자세한 내용은 debug=1 또는 trace에서 확인할 수 있습니다.',
                array(
                    'stage' => isset($GLOBALS['pa_collab_stage']) ? (string)$GLOBALS['pa_collab_stage'] : '',
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                )
            );
        }
    }
    cpms_pa_collab_app_stage('output_done');
    $paCollabHandlingRequest = false;
  ?>
<?php endif; ?>
<?php
if ($safeMode) {
    cpms_pa_collab_app_stage('output_done');
    $paCollabHandlingRequest = false;
}
?>
</body>
</html>
