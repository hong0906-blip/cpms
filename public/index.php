<?php
/**
 * C:\www\cpms\public\index.php
 * - Router
 *
 * ✅ 수정사항(요청한 것만)
 * 1) 관리 섹션 404 해결: ?r=관리, ?r=관리자 둘 다 admin/index로 연결
 * 2) 관리 화면에서 사용하는 admin/... 저장 라우트 연결
 */

require_once __DIR__ . '/../app/bootstrap.php';

$route = isset($_GET['r']) ? trim($_GET['r']) : '대시보드';
if ($route === '') $route = '대시보드';

// ==========================
//  세션 유지용 Ping
//  - footer.php에서 주기적으로 호출해서 세션 만료(자동로그아웃)를 방지
// ==========================
$dashboardType = isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee';
$publicAffairsRouteName = urldecode('%EA%B3%B5%EB%AC%B4');
$publicAffairsCollabTitle = urldecode('%EA%B3%B5%EB%AC%B4%20%ED%98%91%EC%97%85%ED%88%B4');

if (isset($_GET['_clt']) && trim((string)$_GET['_clt']) !== '' && function_exists('cpms_try_chat_link_login')) {
    $cpmsChatLinkTarget = cpms_try_chat_link_login($route);
    if ($cpmsChatLinkTarget !== '') {
        header('Location: ' . $cpmsChatLinkTarget);
        exit;
    }
}

if (!function_exists('cpms_public_affairs_collab_debug_table_exists')) {
function cpms_public_affairs_collab_debug_table_exists($pdo, $tableName) {
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

if ($route === 'public_affairs_collab_debug') {
    header('Content-Type: text/plain; charset=utf-8');
    $rootDir = dirname(__DIR__);
    $storageRoot = function_exists('cpms_storage_root') ? cpms_storage_root() : ($rootDir . '/storage');
    $collabStorage = $storageRoot . '/public_affairs_collab';
    $serviceFile = $rootDir . '/app/services/PublicAffairsCollaborationService.php';
    $appFile = $rootDir . '/app/views/project/collaboration_app.php';
    $safeFile = $rootDir . '/app/views/project/collaboration_safe.php';
    $viewFile = $rootDir . '/app/views/project/collaboration.php';
    $actionFile = $rootDir . '/app/views/project/collaboration_action.php';
    $fileView = $rootDir . '/app/views/project/collaboration_file.php';
    $cssFile = $rootDir . '/public/assets/css/public_affairs_collaboration.css';
    $jsFile = $rootDir . '/public/assets/js/public_affairs_collaboration.js';
    $tasksFile = $collabStorage . '/tasks.json';
    $settingsFile = $collabStorage . '/settings.json';

    $authClassExists = class_exists('\\App\\Core\\Auth');
    $authCheck = false;
    $authEmail = '';
    $authName = '';
    $authDepartment = '';
    $authRole = '';
    $authError = '';
    if ($authClassExists) {
        try {
            $authCheck = \App\Core\Auth::check() ? true : false;
            $authEmail = (string)\App\Core\Auth::userEmail();
            $authName = (string)\App\Core\Auth::userName();
            $authDepartment = (string)\App\Core\Auth::userDepartment();
            $authRole = (string)\App\Core\Auth::userRole();
        } catch (Exception $e) {
            $authError = $e->getMessage();
        }
    }

    echo "[PUBLIC AFFAIRS COLLAB DEBUG]\n";
    echo "time=" . date('Y-m-d H:i:s') . "\n";
    echo "php.version=" . PHP_VERSION . "\n";
    echo "php.error_log=" . (string)ini_get('error_log') . "\n";
    echo "class.Auth=" . ($authClassExists ? 'true' : 'false') . "\n";
    echo "auth.check=" . ($authCheck ? 'true' : 'false') . "\n";
    if ($authError !== '') echo "auth.error=" . $authError . "\n";
    echo "auth.email=" . $authEmail . "\n";
    echo "auth.name=" . $authName . "\n";
    echo "auth.department=" . $authDepartment . "\n";
    echo "auth.role=" . $authRole . "\n";

    $pdo = null;
    $dbError = '';
    try {
        $pdo = \App\Core\Db::pdo();
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
    echo "db.connected=" . ($pdo ? 'true' : 'false') . "\n";
    if ($dbError !== '') echo "db.error=" . $dbError . "\n";
    echo "table.cpms_projects=" . (cpms_public_affairs_collab_debug_table_exists($pdo, 'cpms_projects') ? 'true' : 'false') . "\n";
    echo "table.employees=" . (cpms_public_affairs_collab_debug_table_exists($pdo, 'employees') ? 'true' : 'false') . "\n";

    echo "file.service=" . (is_file($serviceFile) ? 'true' : 'false') . " " . $serviceFile . "\n";
    echo "file.collaboration_app=" . (is_file($appFile) ? 'true' : 'false') . " " . $appFile . "\n";
    echo "file.collaboration_safe=" . (is_file($safeFile) ? 'true' : 'false') . " " . $safeFile . "\n";
    echo "file.collaboration_view=" . (is_file($viewFile) ? 'true' : 'false') . " " . $viewFile . "\n";
    echo "file.action=" . (is_file($actionFile) ? 'true' : 'false') . " " . $actionFile . "\n";
    echo "file.download=" . (is_file($fileView) ? 'true' : 'false') . " " . $fileView . "\n";
    echo "file.css=" . (is_file($cssFile) ? 'true' : 'false') . " " . $cssFile . "\n";
    echo "file.js=" . (is_file($jsFile) ? 'true' : 'false') . " " . $jsFile . "\n";
    echo "storage.root=" . $storageRoot . "\n";
    echo "storage.root.exists=" . (is_dir($storageRoot) ? 'true' : 'false') . "\n";
    echo "storage.root.writable=" . (is_dir($storageRoot) && is_writable($storageRoot) ? 'true' : 'false') . "\n";
    echo "storage.collab.exists=" . (is_dir($collabStorage) ? 'true' : 'false') . "\n";
    echo "storage.collab.writable=" . (is_dir($collabStorage) && is_writable($collabStorage) ? 'true' : 'false') . "\n";
    echo "json.tasks.exists=" . (is_file($tasksFile) ? 'true' : 'false') . "\n";
    echo "json.tasks.readable=" . (is_file($tasksFile) && is_readable($tasksFile) ? 'true' : 'false') . "\n";
    echo "json.settings.exists=" . (is_file($settingsFile) ? 'true' : 'false') . "\n";
    echo "json.settings.readable=" . (is_file($settingsFile) && is_readable($settingsFile) ? 'true' : 'false') . "\n";
    echo "repair.url=?r=public_affairs_collab_repair\n";
    echo "trace.url=?r=public_affairs_collab_trace\n";

    $bootstrapAvailable = false;
    $bootstrapResult = array('ok' => false, 'message' => '서비스 파일을 불러오지 않았습니다.');
    $debugLoadingService = false;
    register_shutdown_function(function() use (&$debugLoadingService) {
        if (!$debugLoadingService) return;
        $error = error_get_last();
        if (!is_array($error) || !isset($error['type'])) return;
        if (!in_array((int)$error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) return;
        echo "bootstrap.available=false\n";
        echo "bootstrap.check.result=FAIL\n";
        echo "bootstrap.message=서비스 파일을 불러오는 중 서버 오류가 발생했습니다.\n";
    });
    if (is_file($serviceFile)) {
        try {
            $debugLoadingService = true;
            require_once $serviceFile;
            $debugLoadingService = false;
            $bootstrapAvailable = function_exists('cpms_public_affairs_collab_bootstrap_storage');
            if ($bootstrapAvailable) {
                $bootstrapResult = cpms_public_affairs_collab_bootstrap_storage(false);
            }
        } catch (Exception $e) {
            $debugLoadingService = false;
            $bootstrapResult = array('ok' => false, 'message' => $e->getMessage());
        }
    }
    echo "bootstrap.available=" . ($bootstrapAvailable ? 'true' : 'false') . "\n";
    echo "bootstrap.check.result=" . (!empty($bootstrapResult['ok']) ? 'OK' : 'FAIL') . "\n";
    echo "bootstrap.message=" . (isset($bootstrapResult['message']) ? (string)$bootstrapResult['message'] : '') . "\n";

    $projectCount = 'unknown';
    if ($pdo && cpms_public_affairs_collab_debug_table_exists($pdo, 'cpms_projects')) {
        try {
            $projectCount = (string)(int)$pdo->query('SELECT COUNT(*) FROM cpms_projects')->fetchColumn();
        } catch (Exception $e) {
            $projectCount = 'error: ' . $e->getMessage();
        }
    }
    echo "projects.count=" . $projectCount . "\n";
    exit;
}

if ($route === 'public_affairs_collab_repair') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "[PUBLIC AFFAIRS COLLAB REPAIR]\n";
    $rootDir = dirname(__DIR__);
    $serviceFile = $rootDir . '/app/services/PublicAffairsCollaborationService.php';
    $storageRoot = function_exists('cpms_storage_root') ? cpms_storage_root() : ($rootDir . '/storage');
    $collabStorage = $storageRoot . '/public_affairs_collab';

    $authOk = false;
    $allowed = false;
    try {
        $authOk = \App\Core\Auth::check() ? true : false;
        $role = (string)\App\Core\Auth::userRole();
        $dept = (string)\App\Core\Auth::userDepartment();
        $allowed = (\App\Core\Auth::isMaster() || $role === 'executive' || strpos($dept, '공무') !== false);
    } catch (Exception $e) {
        echo "auth.error=" . $e->getMessage() . "\n";
    }
    echo "auth.check=" . ($authOk ? 'true' : 'false') . "\n";
    echo "auth.allowed=" . ($allowed ? 'true' : 'false') . "\n";
    if (!$authOk || !$allowed) {
        echo "result=FAIL\n";
        echo "message=공무 협업툴 저장소 복구 권한이 없습니다.\n";
        exit;
    }
    if (!is_file($serviceFile)) {
        echo "file.service=false " . $serviceFile . "\n";
        echo "result=FAIL\n";
        echo "message=서비스 파일을 찾을 수 없습니다.\n";
        exit;
    }

    $repairLoadingService = false;
    register_shutdown_function(function() use (&$repairLoadingService) {
        if (!$repairLoadingService) return;
        $error = error_get_last();
        if (!is_array($error) || !isset($error['type'])) return;
        if (!in_array((int)$error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) return;
        echo "result=FAIL\n";
        echo "message=서비스 파일을 불러오는 중 서버 오류가 발생했습니다.\n";
    });
    try {
        $repairLoadingService = true;
        require_once $serviceFile;
        $repairLoadingService = false;
        if (!function_exists('cpms_public_affairs_collab_bootstrap_storage')) {
            echo "bootstrap.available=false\n";
            echo "result=FAIL\n";
            echo "message=bootstrap 함수를 찾을 수 없습니다.\n";
            exit;
        }
        $result = cpms_public_affairs_collab_bootstrap_storage(true);
        echo "storage.root=" . $storageRoot . "\n";
        echo "storage.root.exists=" . (is_dir($storageRoot) ? 'true' : 'false') . "\n";
        echo "storage.root.writable=" . (is_dir($storageRoot) && is_writable($storageRoot) ? 'true' : 'false') . "\n";
        echo "storage.collab=" . $collabStorage . "\n";
        echo "storage.collab.exists=" . (is_dir($collabStorage) ? 'true' : 'false') . "\n";
        echo "storage.collab.writable=" . (is_dir($collabStorage) && is_writable($collabStorage) ? 'true' : 'false') . "\n";
        $created = isset($result['created']) && is_array($result['created']) ? $result['created'] : array();
        foreach (array('tasks.json', 'comments.json', 'attachments.json', 'history.json', 'settings.json', 'collab_project_meta.json', 'project_activity.json') as $fileName) {
            echo "created." . $fileName . "=" . (!empty($created[$fileName]) ? 'true' : 'false') . "\n";
            echo "file." . $fileName . ".exists=" . (is_file($collabStorage . '/' . $fileName) ? 'true' : 'false') . "\n";
        }
        echo "result=" . (!empty($result['ok']) ? 'OK' : 'FAIL') . "\n";
        echo "message=" . (isset($result['message']) ? (string)$result['message'] : '') . "\n";
    } catch (Exception $e) {
        $repairLoadingService = false;
        echo "result=FAIL\n";
        echo "message=" . $e->getMessage() . "\n";
    }
    exit;
}

if ($route === 'public_affairs_collab_trace') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "[PUBLIC AFFAIRS COLLAB TRACE]\n";
    echo "time=" . date('Y-m-d H:i:s') . "\n";
    echo "php.version=" . PHP_VERSION . "\n";

    $traceActive = true;
    $traceStage = 'trace_start';
    register_shutdown_function(function() use (&$traceActive, &$traceStage) {
        if (!$traceActive) return;
        $error = error_get_last();
        if (!is_array($error) || !isset($error['type'])) return;
        if (!in_array((int)$error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) return;
        echo "fatal.stage=" . $traceStage . "\n";
        echo "fatal.type=" . (isset($error['type']) ? (string)$error['type'] : '') . "\n";
        echo "fatal.message=" . (isset($error['message']) ? (string)$error['message'] : '') . "\n";
        echo "fatal.file=" . (isset($error['file']) ? (string)$error['file'] : '') . "\n";
        echo "fatal.line=" . (isset($error['line']) ? (string)$error['line'] : '') . "\n";
        echo "result=FAIL\n";
    });

    if (!function_exists('cpms_public_affairs_collab_trace_line')) {
    function cpms_public_affairs_collab_trace_line($step, $label, $status, $extra) {
        echo 'step.' . str_pad((string)$step, 2, '0', STR_PAD_LEFT) . ' ' . $label . ' = ' . $status;
        if ($extra !== '') echo ' ' . $extra;
        echo "\n";
    }}

    if (!function_exists('cpms_public_affairs_collab_trace_fail')) {
    function cpms_public_affairs_collab_trace_fail($step, $label, $message) {
        cpms_public_affairs_collab_trace_line($step, $label, 'FAIL', 'message=' . str_replace(array("\r", "\n"), ' ', (string)$message));
        echo "result=FAIL\n";
        exit;
    }}

    $rootDir = dirname(__DIR__);
    $serviceFile = $rootDir . '/app/services/PublicAffairsCollaborationService.php';
    $traceStage = 'step.01 bootstrap_loaded';
    cpms_public_affairs_collab_trace_line(1, 'bootstrap.php loaded', 'OK', '');

    $traceStage = 'step.02 auth';
    $authOk = false;
    $allowed = false;
    try {
        $authOk = \App\Core\Auth::check() ? true : false;
        $role = (string)\App\Core\Auth::userRole();
        $dept = (string)\App\Core\Auth::userDepartment();
        $deptPublic = urldecode('%EA%B3%B5%EB%AC%B4');
        $deptManage = urldecode('%EA%B4%80%EB%A6%AC');
        $allowed = (\App\Core\Auth::isMaster() || $role === 'executive' || strpos($dept, $deptPublic) !== false || strpos($dept, $deptManage) !== false);
        cpms_public_affairs_collab_trace_line(2, 'auth', ($authOk ? 'OK' : 'FAIL'), 'allowed=' . ($allowed ? 'true' : 'false') . ' user=' . (string)\App\Core\Auth::userEmail());
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(2, 'auth', $e->getMessage());
    }
    if (!$authOk || !$allowed) {
        cpms_public_affairs_collab_trace_fail(2, 'auth', 'permission denied');
    }

    $traceStage = 'step.03 db';
    $pdo = null;
    try {
        $pdo = \App\Core\Db::pdo();
        cpms_public_affairs_collab_trace_line(3, 'db', 'OK', '');
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(3, 'db', $e->getMessage());
    }

    $traceStage = 'step.04 service_file_exists';
    if (!is_file($serviceFile)) {
        cpms_public_affairs_collab_trace_fail(4, 'service file exists', $serviceFile);
    }
    cpms_public_affairs_collab_trace_line(4, 'service file exists', 'OK', $serviceFile);

    $traceStage = 'step.05 require_service';
    try {
        require_once $serviceFile;
        cpms_public_affairs_collab_trace_line(5, 'require service', 'OK', '');
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(5, 'require service', $e->getMessage());
    }

    $traceStage = 'step.06 bootstrap_storage';
    if (!function_exists('cpms_public_affairs_collab_bootstrap_storage')) {
        cpms_public_affairs_collab_trace_fail(6, 'bootstrap storage', 'function missing');
    }
    try {
        $bootstrap = cpms_public_affairs_collab_bootstrap_storage(true);
        if (empty($bootstrap['ok'])) {
            cpms_public_affairs_collab_trace_fail(6, 'bootstrap storage', isset($bootstrap['message']) ? (string)$bootstrap['message'] : 'bootstrap failed');
        }
        cpms_public_affairs_collab_trace_line(6, 'bootstrap storage', 'OK', '');
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(6, 'bootstrap storage', $e->getMessage());
    }

    $traceStage = 'step.07 settings';
    try {
        $settings = cpms_public_affairs_collab_settings();
        cpms_public_affairs_collab_trace_line(7, 'settings', 'OK', 'statuses=' . (isset($settings['statuses']) && is_array($settings['statuses']) ? (string)count($settings['statuses']) : '0'));
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(7, 'settings', $e->getMessage());
    }

    $traceStage = 'step.08 fetch_employees';
    try {
        $employees = cpms_public_affairs_collab_fetch_employees($pdo);
        cpms_public_affairs_collab_trace_line(8, 'fetch employees', 'OK', 'count=' . (is_array($employees) ? (string)count($employees) : '0'));
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(8, 'fetch employees', $e->getMessage());
    }

    $traceStage = 'step.09 fetch_projects';
    try {
        $projects = cpms_public_affairs_collab_fetch_projects($pdo);
        cpms_public_affairs_collab_trace_line(9, 'fetch projects', 'OK', 'count=' . (is_array($projects) ? (string)count($projects) : '0'));
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(9, 'fetch projects', $e->getMessage());
    }

    $traceStage = 'step.10 current_employee';
    try {
        $currentEmployee = cpms_public_affairs_collab_current_employee($pdo);
        cpms_public_affairs_collab_trace_line(10, 'current employee', 'OK', 'id=' . (isset($currentEmployee['id']) ? (string)$currentEmployee['id'] : '0'));
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(10, 'current employee', $e->getMessage());
    }

    $traceStage = 'step.11 list_tasks';
    try {
        $tasks = cpms_public_affairs_collab_list_tasks();
        cpms_public_affairs_collab_trace_line(11, 'list tasks', 'OK', 'count=' . (is_array($tasks) ? (string)count($tasks) : '0'));
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(11, 'list tasks', $e->getMessage());
    }

    $traceStage = 'step.12 visible_tasks';
    try {
        $visibleTasks = cpms_public_affairs_collab_visible_tasks($tasks, $currentEmployee);
        cpms_public_affairs_collab_trace_line(12, 'visible tasks', 'OK', 'count=' . (is_array($visibleTasks) ? (string)count($visibleTasks) : '0'));
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(12, 'visible tasks', $e->getMessage());
    }

    $traceStage = 'step.13 project_spaces';
    try {
        $spaces = cpms_public_affairs_collab_project_spaces($pdo, $projects, $visibleTasks);
        cpms_public_affairs_collab_trace_line(13, 'project spaces', 'OK', 'count=' . (is_array($spaces) ? (string)count($spaces) : '0'));
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(13, 'project spaces', $e->getMessage());
    }

    $traceStage = 'step.14 home_summary';
    try {
        $summary = cpms_public_affairs_collab_project_home_summary($spaces);
        cpms_public_affairs_collab_trace_line(14, 'home summary', 'OK', 'keys=' . (is_array($summary) ? (string)count($summary) : '0'));
    } catch (Exception $e) {
        cpms_public_affairs_collab_trace_fail(14, 'home summary', $e->getMessage());
    }

    $traceStage = 'step.15 selected_project';
    if (is_array($spaces) && count($spaces) > 0 && function_exists('cpms_public_affairs_collab_find_project_space')) {
        $firstSpace = reset($spaces);
        $firstProjectId = (is_array($firstSpace) && isset($firstSpace['id'])) ? (int)$firstSpace['id'] : 0;
        $selected = $firstProjectId > 0 ? cpms_public_affairs_collab_find_project_space($spaces, $firstProjectId) : null;
        cpms_public_affairs_collab_trace_line(15, 'selected project', (is_array($selected) ? 'OK' : 'skip'), 'project_id=' . (string)$firstProjectId);
    } else {
        cpms_public_affairs_collab_trace_line(15, 'selected project', 'skip', '');
    }

    $traceActive = false;
    echo "result=OK\n";
    exit;
}

if ($route === 'public_affairs_collab' || $route === 'public_affairs_collaboration' || $route === $publicAffairsCollabTitle || ($route === $publicAffairsRouteName && isset($_GET['tab']) && (string)$_GET['tab'] === 'collaboration')) {
    $paCollabAuthOk = false;
    try {
        $paCollabAuthOk = \App\Core\Auth::check() ? true : false;
    } catch (Exception $e) {
        $paCollabAuthOk = false;
    }
    if (!$paCollabAuthOk) {
        cpms_redirect_to_portal_login(cpms_current_absolute_url());
    }
    $paCollabAppFile = __DIR__ . '/../app/views/project/collaboration_app.php';
    $paCollabSafeFile = __DIR__ . '/../app/views/project/collaboration_safe.php';
    if (isset($_GET['safe']) && (string)$_GET['safe'] === '1') {
        require $paCollabSafeFile;
        exit;
    }
    if (!is_file($paCollabAppFile)) {
        require $paCollabSafeFile;
        exit;
    }
    require $paCollabAppFile;
    exit;
}

if ($route === 'tasks/my_list') {
    if (!\App\Core\Auth::check()) {
        cpms_redirect_to_portal_login(cpms_current_absolute_url());
    }
    \App\Core\View::render('tasks/my_list', array(
        'title' => urldecode('%EB%82%98%EC%9D%98%20%ED%95%A0%EC%9D%BC'),
        'selectedMenu' => urldecode('%EB%8C%80%EC%8B%9C%EB%B3%B4%EB%93%9C'),
        'dashboardType' => $dashboardType,
    ));
    exit;
}

if ($route === 'tasks/executive_summary') {
    if (!\App\Core\Auth::check()) {
        cpms_redirect_to_portal_login(cpms_current_absolute_url());
    }
    if (!(\App\Core\Auth::isMaster() || \App\Core\Auth::userRole() === 'executive' || \App\Core\Auth::canManageEmployees())) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
    \App\Core\View::render('tasks/executive_summary', array(
        'title' => urldecode('%EB%B6%80%EC%84%9C%EB%B3%84%20%EC%97%85%EB%AC%B4%20%ED%98%84%ED%99%A9'),
        'selectedMenu' => urldecode('%EB%8C%80%EC%8B%9C%EB%B3%B4%EB%93%9C'),
        'dashboardType' => $dashboardType,
    ));
    exit;
}

if ($route === 'ping') {
    $_SESSION['_cpms_ping_at'] = time();
    \App\Core\Auth::check();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo 'OK';
    exit;
}

// ==========================
//  ✅ 관리 섹션 404 방지(호환)
// ==========================
if ($route === '관리자') {
    $route = '관리';
}


// ==========================
//  ASCII 우회 라우트 (Bad Request 한글 URL 방지)
//  - dashboard_executive ASCII 라우트
//  - safety_home ASCII 라우트
// ==========================
$dashboardRouteName = urldecode('%EB%8C%80%EC%8B%9C%EB%B3%B4%EB%93%9C');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($route === 'dashboard' || $route === $dashboardRouteName)) {
    $targetDashboardRoute = 'dashboard_employee';
    if (isset($_GET['dv']) && (string)$_GET['dv'] === 'executive') {
        $targetDashboardRoute = 'dashboard_executive';
    } else if (isset($_GET['dv']) && (string)$_GET['dv'] === 'employee') {
        $targetDashboardRoute = 'dashboard_employee';
    } else if (isset($_SESSION['dashboardType']) && (string)$_SESSION['dashboardType'] === 'executive') {
        $targetDashboardRoute = 'dashboard_executive';
    }
    $redirectParams = array('r' => $targetDashboardRoute);
    foreach ($_GET as $redirectKey => $redirectValue) {
        if ($redirectKey === 'r' || $redirectKey === 'dv') continue;
        $redirectParams[$redirectKey] = $redirectValue;
    }
    header('Location: ?' . http_build_query($redirectParams, '', '&'));
    exit;
}
if ($route === 'dashboard') {
    $route = '대시보드';
}
if ($route === 'dashboard_executive') {
    $_SESSION['dashboardType'] = 'executive';
    $route = '대시보드';
}
if ($route === 'dashboard_employee') {
    $_SESSION['dashboardType'] = 'employee';
    $route = '대시보드';
}
if ($route === 'notice' || $route === 'notices') {
    $route = '공지사항';
}
if ($route === 'safety_home') {
    $route = '안전/보건';
}

if ($route === 'quality_home') {
    $route = 'quality_home';
}

if ($route === 'company_profit' || $route === 'management_profit' || $route === 'company_profit_home' || $route === '회사손익') {
    $route = '경영현황';
}
if ($route === 'company_overhead' || $route === 'management_overhead' || $route === 'management/overhead' || $route === 'management/company_overhead' || $route === '총관리비') {
    $_GET['tab'] = 'company_overhead';
    $route = '관리';
}

if ($route === 'construction_home') {
    $route = '공사';
}
if ($route === '공무/프로젝트상세' || $route === 'project_view') {
    $route = 'project/detail';
}
if ($route === '공무/프로젝트수정' || $route === 'project/update' || $route === 'project_update') {
    $route = 'project/project_update';
}
if ($route === 'approval_home') { $route = '전자결재'; }
if ($route === 'approval_active') {
    $_GET['view'] = 'active';
    $route = '전자결재';
}
if ($route === 'approval_cancelled') {
    $_GET['view'] = 'cancelled';
    $route = '전자결재';
}
if ($route === 'approval_completed') {
    $_GET['view'] = 'completed';
    $route = '전자결재';
}
if ($route === 'estimate_home' || $route === '견적단가 추천') {
    $route = '견적관리';
}
if ($route === 'estimate/write') {
    $_GET['tab'] = 'write';
    $route = '견적관리';
}
if ($route === 'estimate/search') {
    $_GET['tab'] = 'search';
    $route = '견적관리';
}
if ($route === 'estimate/history') {
    $_GET['tab'] = 'history';
    $route = '견적관리';
}
if ($route === 'estimate/bid_result') {
    $_GET['tab'] = 'bid_result';
    $route = '견적관리';
}

$cpmsDeptForRestrictedRoute = '';
if (\App\Core\Auth::check()) {
    $cpmsDeptForRestrictedRoute = trim((string)\App\Core\Auth::userDepartment());
    $cpmsDeptRestrictedMap = array(
        '공무부' => '공무',
        '공무팀' => '공무',
    );
    if (isset($cpmsDeptRestrictedMap[$cpmsDeptForRestrictedRoute])) $cpmsDeptForRestrictedRoute = $cpmsDeptRestrictedMap[$cpmsDeptForRestrictedRoute];
}
$cpmsIsRestrictedManagementRoute = ($route === '관리' || strpos($route, 'admin/') === 0 || strpos($route, 'management/') === 0);
if ($cpmsDeptForRestrictedRoute === '공무' && !\App\Core\Auth::isMaster() && ($cpmsIsRestrictedManagementRoute || $route === '경영현황')) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

// ==========================
//  액션(POST 처리) 라우트 먼저
// ==========================
if ($route === 'admin/employees_save') {
    require_once __DIR__ . '/../app/views/admin/employees_save.php';
    exit;
}
if ($route === 'admin/employees_upload') {
    require_once __DIR__ . '/../app/views/admin/employees_upload.php';
    exit;
}
if ($route === 'admin/employees_columns_save') {
    require_once __DIR__ . '/../app/views/admin/employees_columns_save.php';
    exit;
}
if ($route === 'admin/workforce_save') {
    require_once __DIR__ . '/../app/views/admin/workforce_save.php';
    exit;
}
if ($route === 'admin/workforce_delete') {
    require_once __DIR__ . '/../app/views/admin/workforce_delete.php';
    exit;
}
if ($route === 'admin/workforce_import_preview') {
    require_once __DIR__ . '/../app/views/admin/workforce_import_preview.php';
    exit;
}
if ($route === 'admin/workforce_import_process') {
    require_once __DIR__ . '/../app/views/admin/workforce_import_process.php';
    exit;
}
if ($route === 'ajax/workforce_search') {
    require_once __DIR__ . '/../app/views/ajax/workforce_search.php';
    exit;
}
if ($route === 'ajax/workforce_get') {
    require_once __DIR__ . '/../app/views/ajax/workforce_get.php';
    exit;
}
if ($route === 'ajax/workforce_match_shiftee') {
    require_once __DIR__ . '/../app/views/ajax/workforce_match_shiftee.php';
    exit;
}

if ($route === 'approval_google_chat_settings') { require_once __DIR__ . '/../app/views/approval/google_chat_settings.php'; exit; }
if ($route === 'approval_google_chat_settings_save') { require_once __DIR__ . '/../app/views/approval/google_chat_settings_save.php'; exit; }
if ($route === 'approval_google_chat_employee_dm_create') { require_once __DIR__ . '/../app/views/approval/google_chat_employee_dm_create.php'; exit; }
if ($route === 'approval_google_chat_employee_test') { require_once __DIR__ . '/../app/views/approval/google_chat_employee_test.php'; exit; }
if ($route === 'google_chat_event') { require_once __DIR__ . '/../app/views/approval/google_chat_event.php'; exit; }

// ==========================
//  관리(노무비) 관련 액션(POST 처리)
// ==========================
if ($route === 'admin/direct_rates_save') {
    require_once __DIR__ . '/../app/views/admin/direct_rates_save.php';
    exit;
}
if ($route === 'admin/direct_team_save') {
    require_once __DIR__ . '/../app/views/admin/direct_team_save.php';
    exit;
}
if ($route === 'admin/labor_entries_save') {
    require_once __DIR__ . '/../app/views/admin/labor_entries_save.php';
    exit;
}
if ($route === 'admin/labor_consultant_setup') {
    require_once __DIR__ . '/../app/views/admin/labor_consultant_setup.php';
    exit;
}
if ($route === 'admin/labor_consultant_template_upload') {
    require_once __DIR__ . '/../app/views/admin/labor_consultant_template_upload.php';
    exit;
}
if ($route === 'admin/labor_consultant_export') {
    require_once __DIR__ . '/../app/views/admin/labor_consultant_export.php';
    exit;
}

if ($route === 'db_setup_estimate') {
    require_once __DIR__ . '/db_setup_estimate.php';
    exit;
}

if ($route === 'db_setup_estimate_versions') {
    require_once __DIR__ . '/db_setup_estimate_versions.php';
    exit;
}

if ($route === 'estimate/estimate_save') {
    require_once __DIR__ . '/../app/views/estimate/estimate_save.php';
    exit;
}
if ($route === 'estimate/price_import_preview') {
    require_once __DIR__ . '/../app/views/estimate/price_import_preview.php';
    exit;
}
if ($route === 'estimate/price_import_apply') {
    require_once __DIR__ . '/../app/views/estimate/price_import_apply.php';
    exit;
}
if ($route === 'estimate/price_recommend') {
    require_once __DIR__ . '/../app/views/estimate/price_recommend.php';
    exit;
}
if ($route === 'estimate/item_search') {
    require_once __DIR__ . '/../app/views/estimate/item_search.php';
    exit;
}
if ($route === 'estimate/bid_result_save') {
    require_once __DIR__ . '/../app/views/estimate/bid_result_save.php';
    exit;
}
if ($route === 'estimate/export_xlsx') {
    require_once __DIR__ . '/../app/views/estimate/export_xlsx.php';
    exit;
}


if ($route === 'db_setup_project_monthly') {
    $dept = (string)\App\Core\Auth::userDepartment();
    $role = (string)\App\Core\Auth::userRole();
    $ok = \App\Core\Auth::isMaster() || $role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부';
    if (!$ok) { http_response_code(403); echo '403 Forbidden'; exit; }
    require_once __DIR__ . '/db_setup_project_monthly.php';
    exit;
}

// ==========================
//  공무(프로젝트) 액션(POST 처리)
// ==========================
if ($route === 'project/project_save') {
    require_once __DIR__ . '/../app/views/project/project_save.php';
    exit;
}

/**
 * ✅ [추가] 프로젝트 수정 저장(POST)
 * - debug_project_update=1 쿼리는 project_update.php에서 실패 원인 JSON을 반환
 * - app/views/project/project_update.php
 */
if ($route === 'project/project_update' || $route === 'project/project_edit_save' || $route === 'project_edit_save') {
    require_once __DIR__ . '/../app/views/project/project_update.php';
    exit;
}

/**
 * ✅ [추가] 프로젝트 삭제(POST)
 * - app/views/project/project_delete.php
 */
if ($route === 'project/project_delete') {
    require_once __DIR__ . '/../app/views/project/project_delete.php';
    exit;
}
if ($route === 'project/monthly_deduction_save') {
    require_once __DIR__ . '/../app/views/project/monthly_deduction_save.php';
    exit;
}
if ($route === 'project/monthly_deduction_delete') {
    require_once __DIR__ . '/../app/views/project/monthly_deduction_delete.php';
    exit;
}
if ($route === 'project/monthly_summary_remark_save') {
    require_once __DIR__ . '/../app/views/project/monthly_summary_remark_save.php';
    exit;
}


/**
 * ✅ [추가] 프로젝트 생성 모달에서 엑셀 업로드 → 미리보기(JSON)
 * - app/views/project/project_create_preview.php
 */
if ($route === 'project/project_create_preview') {
    require_once __DIR__ . '/../app/views/project/project_create_preview.php';
    exit;
}
if ($route === 'project/unit_price_update_preview') {
    require_once __DIR__ . '/../app/views/project/unit_price_update_preview.php';
    exit;
}
if ($route === 'project/contract_change_preview') {
    require_once __DIR__ . '/../app/views/project/contract_change_preview.php';
    exit;
}

/**
 * ✅ [추가] 계약서 업로드(프로젝트 상세에서 업로드)
 * - app/views/project/contract_upload.php
 */
if ($route === 'project/contract_upload') {
    require_once __DIR__ . '/../app/views/project/contract_upload.php';
    exit;
}

/**
 * ✅ [추가] 계약서 다운로드(권한 체크 후 다운로드)
 * - app/views/project/contract_download.php
 */
if ($route === 'project/contract_download') {
    require_once __DIR__ . '/../app/views/project/contract_download.php';
    exit;
}

if ($route === 'project/additional_work_save') {
    require_once __DIR__ . '/../app/views/project/additional_work_save.php';
    exit;
}
if ($route === 'project/progress_save') {
    require_once __DIR__ . '/../app/views/project/progress_save.php';
    exit;
}
if ($route === 'project/progress_download') {
    require_once __DIR__ . '/../app/views/project/progress_download.php';
    exit;
}
if ($route === 'project/public_affairs_file') {
    require_once __DIR__ . '/../app/views/project/public_affairs_file.php';
    exit;
}
if ($route === 'project/collaboration_action') {
    require_once __DIR__ . '/../app/views/project/collaboration_action.php';
    exit;
}
if ($route === 'project/collaboration_file') {
    require_once __DIR__ . '/../app/views/project/collaboration_file.php';
    exit;
}

if ($route === 'project/unit_price_add') {
    require_once __DIR__ . '/../app/views/project/unit_price_add.php';
    exit;
}
if ($route === 'project/unit_price_delete') {
    require_once __DIR__ . '/../app/views/project/unit_price_delete.php';
    exit;
}
if ($route === 'project/unit_price_bulk_delete') {
    require_once __DIR__ . '/../app/views/project/unit_price_bulk_delete.php';
    exit;
}
if ($route === 'project/unit_price_import_preview') {
    require_once __DIR__ . '/../app/views/project/unit_price_import_preview.php';
    exit;
}
if ($route === 'project/unit_price_import_apply') {
    require_once __DIR__ . '/../app/views/project/unit_price_import_apply.php';
    exit;
}

if ($route === 'project/header_mapping_save') {
    require_once __DIR__ . '/../app/views/project/header_mapping_save.php';
    exit;
}


if ($route === 'project/unit_price_toggle_safety') {
    require_once __DIR__ . '/../app/views/project/unit_price_toggle_safety.php';
    exit;
}

/**
 * ==========================
 * ✅ 이슈(등록/상태/댓글) 액션(POST 처리)
 * ==========================
 */
if ($route === 'project/issue_create') {
    require_once __DIR__ . '/../app/views/project/issue_create.php';
    exit;
}
if ($route === 'project/issue_comment_create') {
    require_once __DIR__ . '/../app/views/project/issue_comment_create.php';
    exit;
}
if ($route === 'project/issue_update') {
    require_once __DIR__ . '/../app/views/project/issue_update.php';
    exit;
}

// ==========================
//  공사(Construction) 액션(POST 처리)
// ==========================
if ($route === 'construction/roles_save') {
    require_once __DIR__ . '/../app/views/construction/roles_save.php';
    exit;
}
if ($route === 'construction/template_generate') {
    require_once __DIR__ . '/../app/views/construction/template_generate.php';
    exit;
}
if ($route === 'construction/schedule_seed_from_template') {
    require_once __DIR__ . '/../app/views/construction/schedule_seed_from_template.php';
    exit;
}
if ($route === 'construction/schedule_save') {
    require_once __DIR__ . '/../app/views/construction/schedule_save.php';
    exit;
}
if ($route === 'construction/schedule_move') {
    require_once __DIR__ . '/../app/views/construction/schedule_move.php';
    exit;
}
if ($route === 'construction/schedule_delete') {
    require_once __DIR__ . '/../app/views/construction/schedule_delete.php';
    exit;
}
if ($route === 'construction/schedule_progress_save') {
    require_once __DIR__ . '/../app/views/construction/schedule_progress_save.php';
    exit;
}
if ($route === 'construction/schedule_task_item_progress_save') {
    require_once __DIR__ . '/../app/views/construction/schedule_task_item_progress_save.php';
    exit;
}
if ($route === 'construction/safety_incident_create') {
    require_once __DIR__ . '/../app/views/construction/safety_incident_create.php';
    exit;
}

if ($route === 'construction/issue_status_save') {
    require_once __DIR__ . '/../app/views/construction/issue_status_save.php';
    exit;
}
if ($route === 'construction/labor_worker_add') {
    require_once __DIR__ . '/../app/views/construction/labor_worker_add.php';
    exit;
}
if ($route === 'construction/labor_worker_delete') {
    require_once __DIR__ . '/../app/views/construction/labor_worker_delete.php';
    exit;
}
// 인원작성 저장 기능
if ($route === 'construction/labor_workers_save') {
    require_once __DIR__ . '/../app/views/construction/labor_workers_save.php';
    exit;
}
if ($route === 'construction/labor_force_save') {
    require_once __DIR__ . '/../app/views/construction/labor_force_save.php';
    exit;
}
if ($route === 'construction/labor_sheet_download') {
    require_once __DIR__ . '/../app/views/construction/labor_sheet_download.php';
    exit;
}


if ($route === 'construction/labor_cell_save') {
    // [변경] 구버전 labor_cell_save 차단/통합: 새 액션으로 일원화
    require_once __DIR__ . '/../app/views/construction/labor_gongsu_override_save.php';
    exit;
}
// [변경] JSON action layout 차단: action 파일만 실행 후 즉시 종료
if ($route === 'construction/labor_gongsu_override_save') {
    require_once __DIR__ . '/../app/views/construction/labor_gongsu_override_save.php';
    exit;
}
if ($route === 'construction/labor_gongsu_override_decide') {
    require_once __DIR__ . '/../app/views/construction/labor_gongsu_override_decide.php';
    exit;
}
if ($route === 'request/create') {
    require_once __DIR__ . '/../app/views/request/create.php';
    exit;
}
if ($route === 'request/decide') {
    require_once __DIR__ . '/../app/views/request/decide.php';
    exit;
}
if ($route === 'tasks/create') {
    require_once __DIR__ . '/../app/views/tasks/create.php';
    exit;
}
if ($route === 'tasks/update_status') {
    require_once __DIR__ . '/../app/views/tasks/update_status.php';
    exit;
}
if ($route === 'tasks/priority_update' || $route === 'task_priority_update' || $route === 'task_priority_save') {
    require_once __DIR__ . '/../app/views/tasks/priority_update.php';
    exit;
}
if ($route === 'task_update_status') {
    require_once __DIR__ . '/../app/views/tasks/update_status.php';
    exit;
}
if ($route === 'task_progress') {
    require_once __DIR__ . '/../app/views/tasks/update_status.php';
    exit;
}
if ($route === 'tasks/meeting_response' || $route === 'task_meeting_response') {
    require_once __DIR__ . '/../app/views/tasks/meeting_response.php';
    exit;
}
if ($route === 'tasks/complete') {
    require_once __DIR__ . '/../app/views/tasks/complete.php';
    exit;
}
if ($route === 'tasks/transfer') {
    require_once __DIR__ . '/../app/views/tasks/transfer.php';
    exit;
}
if ($route === 'tasks/revision') {
    require_once __DIR__ . '/../app/views/tasks/revision.php';
    exit;
}
if ($route === 'tasks/cancel') {
    require_once __DIR__ . '/../app/views/tasks/cancel.php';
    exit;
}
if ($route === 'tasks/comment_save' || $route === 'task_comment_save') {
    require_once __DIR__ . '/../app/views/tasks/comment_save.php';
    exit;
}
if ($route === 'tasks/delayed_notify') {
    require_once __DIR__ . '/../app/views/tasks/delayed_notify.php';
    exit;
}
if ($route === 'tasks/detail') {
    require_once __DIR__ . '/../app/views/tasks/detail.php';
    exit;
}
if ($route === 'tasks/file') {
    require_once __DIR__ . '/../app/views/tasks/file.php';
    exit;
}
if ($route === 'notice_save' || $route === 'dashboard_notice_save') {
    require_once __DIR__ . '/../app/views/dashboard/notice_save.php';
    exit;
}
if ($route === 'db_setup_tasks') {
    require_once __DIR__ . '/db_setup_tasks.php';
    exit;
}

// 공사 페이지 전용 이슈 등록/댓글(리다이렉트가 공사로 돌아오게)
if ($route === 'construction/issue_save') {
    require_once __DIR__ . '/../app/views/construction/issue_save.php';
    exit;
}
if ($route === 'construction/issue_create') {
    require_once __DIR__ . '/../app/views/construction/issue_create.php';
    exit;
}
if ($route === 'construction/issue_comment_create') {
    require_once __DIR__ . '/../app/views/construction/issue_comment_create.php';
    exit;
}
// [변경] issue_update 경로 폐기: issue_state_save 새 상태변경 action으로 Apache 400 우회
if ($route === 'construction/issue_state_save') {
    require_once __DIR__ . '/../app/views/construction/issue_state_save.php';
    exit;
}
if ($route === 'construction/issue_update') {
    require_once __DIR__ . '/../app/views/construction/issue_update.php';
    exit;
}


if ($route === 'construction/daily_work_save') {
    require_once __DIR__ . '/../app/views/construction/daily_work_save.php';
    exit;
}
if ($route === 'construction/daily_cost_save') {
    require_once __DIR__ . '/../app/views/construction/daily_cost_save.php';
    exit;
}
if ($route === 'construction/recognized_save') {
    require_once __DIR__ . '/../app/views/construction/recognized_save.php';
    exit;
}
if ($route === 'construction/target_cost_rate_save') {
    require_once __DIR__ . '/../app/views/construction/target_cost_rate_save.php';
    exit;
}
if ($route === 'construction/target_cost_rate_decide') {
    require_once __DIR__ . '/../app/views/construction/target_cost_rate_decide.php';
    exit;
}
if ($route === 'construction/sample_c5_seed') {
    require_once __DIR__ . '/../app/views/construction/sample_c5_seed.php';
    exit;
}

if ($route === 'construction/equipment_item_save') {
    require_once __DIR__ . '/../app/views/construction/equipment_item_save.php';
    exit;
}
if ($route === 'construction/equipment_excel_preview') {
    require_once __DIR__ . '/../app/views/construction/equipment_excel_preview.php';
    exit;
}
if ($route === 'construction/equipment_excel_save') {
    require_once __DIR__ . '/../app/views/construction/equipment_excel_save.php';
    exit;
}
if ($route === 'construction/equipment_item_delete') {
    require_once __DIR__ . '/../app/views/construction/equipment_item_delete.php';
    exit;
}
if ($route === 'construction/equipment_usage_save') {
    require_once __DIR__ . '/../app/views/construction/equipment_usage_save.php';
    exit;
}
if ($route === 'construction/equipment_usage_edit_save') {
    require_once __DIR__ . '/../app/views/construction/equipment_usage_update.php';
    exit;
}
if ($route === 'construction/equipment_usage_update') {
    require_once __DIR__ . '/../app/views/construction/equipment_usage_update.php';
    exit;
}
if ($route === 'construction/equipment_gongsu_override_save') {
    require_once __DIR__ . '/../app/views/construction/equipment_gongsu_override_save.php';
    exit;
}
if ($route === 'construction/equipment_gongsu_override_decide') {
    require_once __DIR__ . '/../app/views/construction/equipment_gongsu_override_decide.php';
    exit;
}
if ($route === 'construction/equipment_usage_delete') {
    require_once __DIR__ . '/../app/views/construction/equipment_usage_delete.php';
    exit;
}
if ($route === 'construction/equipment_statement_download') {
    require_once __DIR__ . '/../app/views/construction/equipment_statement_download.php';
    exit;
}
if ($route === 'construction/material_item_save') {
    require_once __DIR__ . '/../app/views/construction/material_item_save.php';
    exit;
}
if ($route === 'construction/material_item_delete') {
    require_once __DIR__ . '/../app/views/construction/material_item_delete.php';
    exit;
}
if ($route === 'construction/material_usage_save') {
    require_once __DIR__ . '/../app/views/construction/material_usage_save.php';
    exit;
}
if ($route === 'construction/material_usage_edit_save') {
    require_once __DIR__ . '/../app/views/construction/material_usage_update.php';
    exit;
}
if ($route === 'construction/material_usage_update') {
    require_once __DIR__ . '/../app/views/construction/material_usage_update.php';
    exit;
}
if ($route === 'construction/material_statement_download') {
    require_once __DIR__ . '/../app/views/construction/material_statement_download.php';
    exit;
}
if ($route === 'construction/photo_file') {
    require_once __DIR__ . '/../app/views/construction/photo_file.php';
    exit;
}

if ($route === 'construction/equipment_vendor_search') {
    require_once __DIR__ . '/../app/views/construction/equipment_vendor_search.php';
    exit;
}
if ($route === 'construction/material_vendor_search') {
    require_once __DIR__ . '/../app/views/construction/material_vendor_search.php';
    exit;
}
if ($route === 'construction/material_usage_delete') {
    require_once __DIR__ . '/../app/views/construction/material_usage_delete.php';
    exit;
}

if ($route === 'construction/work_item_save') {
    require_once __DIR__ . '/../app/views/construction/work_item_save.php';
    exit;
}
if ($route === 'construction/work_item_delete') {
    require_once __DIR__ . '/../app/views/construction/work_item_delete.php';
    exit;
}
if ($route === 'construction/work_item_line_save') {
    require_once __DIR__ . '/../app/views/construction/work_item_line_save.php';
    exit;
}
if ($route === 'construction/work_item_line_delete') {
    require_once __DIR__ . '/../app/views/construction/work_item_line_delete.php';
    exit;
}
// ==========================
//  안전(안전사고) 액션(POST 처리)
// ==========================
if ($route === 'safety/safety_incident_save') {
    require_once __DIR__ . '/../app/views/safety/safety_incident_save.php';
    exit;
}
if ($route === 'safety/safety_cost_save') {
    require_once __DIR__ . '/../app/views/safety/safety_cost_save.php';
    exit;
}
if ($route === 'safety/safety_cost_delete') {
    require_once __DIR__ . '/../app/views/safety/safety_cost_delete.php';
    exit;
}
if ($route === 'safety/safety_cost_download') {
    require_once __DIR__ . '/../app/views/safety/safety_cost_download.php';
    exit;
}
if ($route === 'safety/samsung_portal_upload') {
    require_once __DIR__ . '/../app/views/safety/safety_cost_helper.php';
    cpms_samsung_portal_handle_upload_request(\App\Core\Db::pdo());
    exit;
}
if ($route === 'safety/samsung_portal_save') {
    require_once __DIR__ . '/../app/views/safety/safety_cost_helper.php';
    cpms_samsung_portal_handle_save_request(\App\Core\Db::pdo());
    exit;
}
if ($route === 'safety/samsung_portal_delete') {
    require_once __DIR__ . '/../app/views/safety/safety_cost_helper.php';
    cpms_samsung_portal_handle_delete_request(\App\Core\Db::pdo());
    exit;
}
if ($route === 'safety/samsung_portal_health_upload') {
    require_once __DIR__ . '/../app/views/safety/safety_cost_helper.php';
    cpms_samsung_portal_handle_health_upload_request(\App\Core\Db::pdo());
    exit;
}
if ($route === 'safety/samsung_portal_health_download') {
    require_once __DIR__ . '/../app/views/safety/safety_cost_helper.php';
    cpms_samsung_portal_handle_health_download_request(\App\Core\Db::pdo());
    exit;
}
if ($route === 'safety/incident_update') {
    require_once __DIR__ . '/../app/views/safety/incident_update.php';
    exit;
}
if ($route === 'quality/file_upload') {
    require_once __DIR__ . '/../app/views/quality/file_upload.php';
    exit;
}
if ($route === 'quality/file_download') {
    require_once __DIR__ . '/../app/views/quality/file_download.php';
    exit;
}
if ($route === 'construction/safety_incident_action_save') {
    require_once __DIR__ . '/../app/views/construction/safety_incident_action_save.php';
    exit;
}



if ($route === 'approval_store') { require_once __DIR__ . '/../app/views/approval/store.php'; exit; }
if ($route === 'approval_decide') { require_once __DIR__ . '/../app/views/approval/decide.php'; exit; }
if ($route === 'approval_cancel') { require_once __DIR__ . '/../app/views/approval/cancel.php'; exit; }
if ($route === 'approval_delete') { require_once __DIR__ . '/../app/views/approval/delete.php'; exit; }
if ($route === 'approval_file') { require_once __DIR__ . '/../app/views/approval/file.php'; exit; }
if ($route === 'approval_completed_pdf') { require_once __DIR__ . '/../app/views/approval/completed_pdf.php'; exit; }
if ($route === 'db_setup_approval') { require_once __DIR__ . '/db_setup_approval.php'; exit; }
if ($route === 'attendance/check_in') { require_once __DIR__ . '/../app/views/attendance/check_in.php'; exit; }
if ($route === 'attendance/check_out') { require_once __DIR__ . '/../app/views/attendance/check_out.php'; exit; }
if ($route === 'attendance/request_save') { require_once __DIR__ . '/../app/views/attendance/request_save.php'; exit; }
if ($route === 'management/attendance') { require_once __DIR__ . '/../app/views/management/attendance.php'; exit; }
if ($route === 'management/attendance_request_approve') { require_once __DIR__ . '/../app/views/management/attendance_request_approve.php'; exit; }
if ($route === 'management/attendance_request_reject') { require_once __DIR__ . '/../app/views/management/attendance_request_reject.php'; exit; }
if ($route === 'management/attendance_record_save') { require_once __DIR__ . '/../app/views/management/attendance_record_save.php'; exit; }
if ($route === 'management/leave_save') { require_once __DIR__ . '/../app/views/management/leave_save.php'; exit; }
if ($route === 'management/leave_delete') { require_once __DIR__ . '/../app/views/management/leave_delete.php'; exit; }
if ($route === 'management/attendance_settings_save') { require_once __DIR__ . '/../app/views/management/attendance_settings_save.php'; exit; }
if ($route === 'management/attendance_geofence_save') { require_once __DIR__ . '/../app/views/management/attendance_geofence_save.php'; exit; }
if ($route === 'management/attendance_geofence_delete') { require_once __DIR__ . '/../app/views/management/attendance_geofence_delete.php'; exit; }
if ($route === 'management/overhead_save') { require_once __DIR__ . '/../app/views/management/overhead_save.php'; exit; }
if ($route === 'management/overhead_delete') { require_once __DIR__ . '/../app/views/management/overhead_delete.php'; exit; }
if ($route === 'management/lease_upload') { require_once __DIR__ . '/../app/views/management/lease_upload.php'; exit; }
if ($route === 'management/lease_upload_preview') { require_once __DIR__ . '/../app/views/management/lease_upload_preview.php'; exit; }
if ($route === 'management/lease_upload_confirm') { require_once __DIR__ . '/../app/views/management/lease_upload_confirm.php'; exit; }
if ($route === 'management/corporate_card_upload_preview') { require_once __DIR__ . '/../app/views/management/corporate_card_upload_preview.php'; exit; }
if ($route === 'management/corporate_card_upload_confirm') { require_once __DIR__ . '/../app/views/management/corporate_card_upload_confirm.php'; exit; }
if ($route === 'management/fuel_upload_preview') { require_once __DIR__ . '/../app/views/management/fuel_upload_preview.php'; exit; }
if ($route === 'management/fuel_upload_confirm') { require_once __DIR__ . '/../app/views/management/fuel_upload_confirm.php'; exit; }
if ($route === 'management/fuel_delete') { require_once __DIR__ . '/../app/views/management/fuel_delete.php'; exit; }
if ($route === 'management/fuel_vehicle_match_save') { require_once __DIR__ . '/../app/views/management/fuel_vehicle_match_save.php'; exit; }
if ($route === 'management/company_vehicle_upload_preview') { require_once __DIR__ . '/../app/views/management/company_vehicle_upload_preview.php'; exit; }
if ($route === 'management/company_vehicle_upload_confirm') { require_once __DIR__ . '/../app/views/management/company_vehicle_upload_confirm.php'; exit; }
if ($route === 'management/company_vehicle_save') { require_once __DIR__ . '/../app/views/management/company_vehicle_save.php'; exit; }
if ($route === 'management/company_vehicle_payment_update') { require_once __DIR__ . '/../app/views/management/company_vehicle_payment_update.php'; exit; }
if ($route === 'management/company_vehicle_driver_update') { require_once __DIR__ . '/../app/views/management/company_vehicle_driver_update.php'; exit; }
if ($route === 'management/company_vehicle_inspection_advance') { require_once __DIR__ . '/../app/views/management/company_vehicle_inspection_advance.php'; exit; }
if ($route === 'management/company_vehicle_delete') { require_once __DIR__ . '/../app/views/management/company_vehicle_delete.php'; exit; }
if ($route === 'management/payroll_upload_preview') { require_once __DIR__ . '/../app/views/management/payroll_upload_preview.php'; exit; }
if ($route === 'management/payroll_upload_confirm') { require_once __DIR__ . '/../app/views/management/payroll_upload_confirm.php'; exit; }
if ($route === 'management/payroll_manual_total_save') { require_once __DIR__ . '/../app/views/management/payroll_manual_total_save.php'; exit; }
if ($route === 'management/payroll_employee_delete') { require_once __DIR__ . '/../app/views/management/payroll_employee_delete.php'; exit; }
if ($route === 'management/payroll_resident_reveal') { require_once __DIR__ . '/../app/views/management/payroll_resident_reveal.php'; exit; }
if ($route === 'management/payroll_bank_account_reveal') { require_once __DIR__ . '/../app/views/management/payroll_bank_account_reveal.php'; exit; }
if ($route === 'management/payroll_statement') { require_once __DIR__ . '/../app/views/management/payroll_statement.php'; exit; }
if ($route === 'management/payroll_statement_print') { require_once __DIR__ . '/../app/views/management/payroll_statement_print.php'; exit; }
if ($route === 'management/payroll_statement_pdf') { require_once __DIR__ . '/../app/views/management/payroll_statement_pdf.php'; exit; }
if ($route === 'management/payroll_statement_generate') { require_once __DIR__ . '/../app/views/management/payroll_statement_generate.php'; exit; }
if ($route === 'management/payroll_statement_template_upload') { require_once __DIR__ . '/../app/views/management/payroll_statement_template_upload.php'; exit; }
if ($route === 'management/payroll_statement_file') { require_once __DIR__ . '/../app/views/management/payroll_statement_file.php'; exit; }
// db_setup_attendance 라우트
if ($route === 'db_setup_attendance') {
    if (!(\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees())) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
    require_once __DIR__ . '/db_setup_attendance.php';
    exit;
}
// ==========================
//  로그인/로그아웃
// ==========================
if ($route === 'login') {
    $loginReturnUrl = isset($_GET['return']) ? cpms_safe_internal_redirect_url((string)$_GET['return'], '?r=대시보드') : '?r=대시보드';
    if (\App\Core\Auth::check()) {
        header('Location: ' . $loginReturnUrl);
        exit;
    }
    cpms_redirect_to_portal_login(cpms_current_absolute_url());
}
if ($route === 'logout') {
    \App\Core\Auth::logout();
    cpms_redirect_to_portal_login(cpms_current_absolute_url());
}

// ==========================
//  로그인 체크
// ==========================
if (!\App\Core\Auth::check()) {
    cpms_redirect_to_portal_login(cpms_current_absolute_url());
}

// ==========================
//  대시보드 타입(직원/임원)
// ==========================
if (isset($_GET['dv'])) {
    $dv = (string)$_GET['dv'];
    if ($dv === 'employee' || $dv === 'executive') {
        $_SESSION['dashboardType'] = $dv;
    }
}
$dashboardType = isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee';

// ==========================
//  견적관리 직접 URL 접근 차단
// ==========================
if ($route === '견적관리' && !\App\Core\Auth::canAccessEstimate()) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

// ==========================
//  경영현황 직접 URL 접근 차단
// ==========================
if ($route === '경영현황') {
    require_once __DIR__ . '/../app/services/CompanyProfitAccessService.php';
    $companyProfitPdo = \App\Core\Db::pdo();
    if (!cpms_can_view_company_profit(\App\Core\Auth::user(), $companyProfitPdo)) {
        http_response_code(403);
        echo '접근 권한이 없습니다.';
        exit;
    }

    require_once __DIR__ . '/../app/services/CompanyProfitSummaryService.php';
    $companyProfitCacheKey = '';
    if (is_array($_GET)) {
        $cacheParams = $_GET;
        ksort($cacheParams);
        $companyProfitCacheKey = md5('company_profit_period_v2:' . serialize($cacheParams));
    }
    $companyProfitSummary = null;
    if ($companyProfitCacheKey !== '' && isset($_SESSION['_company_profit_cache'][$companyProfitCacheKey]) && is_array($_SESSION['_company_profit_cache'][$companyProfitCacheKey])) {
        $cached = $_SESSION['_company_profit_cache'][$companyProfitCacheKey];
        if (isset($cached['time']) && isset($cached['data']) && (time() - (int)$cached['time']) <= 60 && is_array($cached['data'])) {
            $companyProfitSummary = $cached['data'];
        }
    }
    if (!is_array($companyProfitSummary)) {
        $companyProfitSummary = cpms_company_profit_build_dashboard($companyProfitPdo, $_GET);
        if ($companyProfitCacheKey !== '') {
            if (!isset($_SESSION['_company_profit_cache']) || !is_array($_SESSION['_company_profit_cache'])) $_SESSION['_company_profit_cache'] = array();
            $_SESSION['_company_profit_cache'][$companyProfitCacheKey] = array('time' => time(), 'data' => $companyProfitSummary);
            if (count($_SESSION['_company_profit_cache']) > 8) {
                array_shift($_SESSION['_company_profit_cache']);
            }
        }
    }
    \App\Core\View::render('company_profit/index', array(
        'title' => '경영현황',
        'selectedMenu' => '경영현황',
        'dashboardType' => $dashboardType,
        'companyProfitSummary' => $companyProfitSummary,
    ));
    exit;
}


// ==========================
//  관리 라우트 강제 진단(debug_route=1)
// ==========================
if ($route === '관리' && isset($_GET['debug_route']) && (string)$_GET['debug_route'] === '1') {
    if (\App\Core\Auth::check() && (\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees())) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "[ROUTE DEBUG]\n";
        echo 'route=' . $route . "\n";
        echo 'view=admin/index' . "\n";
        echo 'selectedMenu=관리' . "\n";
        echo 'Auth::isMaster()=' . (\App\Core\Auth::isMaster() ? 'true' : 'false') . "\n";
        echo 'Auth::canManageEmployees()=' . (\App\Core\Auth::canManageEmployees() ? 'true' : 'false') . "\n";
        echo '__FILE__=' . __FILE__ . "\n";
        exit;
    }
}

// ==========================
//  화면 매핑
// ==========================
$views = array(
    '공지사항'    => 'notices/index',
    '공무'      => 'project/index',
    '공사'      => 'construction/index',
    '안전/보건' => 'safety/index',
    '품질'      => 'quality/index',
    '전자결재'  => 'approval/index',
    '관리'      => 'admin/index',
    '견적관리'  => 'estimate/index',
);

// ==========================
//  대시보드
// ==========================
if ($route === '대시보드') {
    $role = \App\Core\Auth::userRole();
    if ($role === 'executive' && $dashboardType === 'executive') {
        $view = 'dashboard/executive';
    } else {
        $view = 'dashboard/employee';
    }

    $viewData = array(
        'title' => '대시보드',
        'selectedMenu' => '대시보드',
        'dashboardType' => $dashboardType,
    );
    if ($view === 'dashboard/executive' && isset($_GET['fragment']) && (string)$_GET['fragment'] === 'project_cost_summary') {
        $viewData['hideLayout'] = true;
        $viewData['projectCostFragmentOnly'] = true;
    }

    \App\Core\View::render($view, $viewData);
    exit;
}

// ==========================
//  공무(프로젝트) 서브 페이지
// ==========================
if ($route === 'project/detail') {
    \App\Core\View::render('project/detail', array(
        'title' => '프로젝트 상세',
        'selectedMenu' => '공무',
        'dashboardType' => $dashboardType,
    ));
    exit;
}
if ($route === 'project/header_mapping') {
    \App\Core\View::render('project/header_mapping', array(
        'title' => '단가표 헤더 매핑',
        'selectedMenu' => '공무',
        'dashboardType' => $dashboardType,
    ));
    exit;
}
if ($route === 'quality_home') {
    \App\Core\View::render('quality/index', array(
        'title' => urldecode('%ED%92%88%EC%A7%88'),
        'selectedMenu' => urldecode('%ED%92%88%EC%A7%88'),
        'dashboardType' => $dashboardType,
    ));
    exit;
}
if ($route === 'admin/workforce_form') {
    \App\Core\View::render('admin/workforce_form', array(
        'title' => '인력관리',
        'selectedMenu' => '관리',
        'dashboardType' => $dashboardType,
    ));
    exit;
}
if ($route === 'admin/workforce_upload') {
    \App\Core\View::render('admin/workforce_upload', array(
        'title' => '인력관리 엑셀 업로드',
        'selectedMenu' => '관리',
        'dashboardType' => $dashboardType,
    ));
    exit;
}

if ($route === 'approval_create') { \App\Core\View::render('approval/create', array('title'=>'전자결재 작성','selectedMenu'=>'전자결재','dashboardType'=>$dashboardType)); exit; }
if ($route === 'approval_detail') { \App\Core\View::render('approval/detail', array('title'=>'전자결재 상세','selectedMenu'=>'전자결재','dashboardType'=>$dashboardType)); exit; }
if ($route === 'approval_print') { require_once __DIR__ . '/../app/views/approval/print.php'; exit; }
if ($route === 'approval_download_excel') { require_once __DIR__ . '/../app/views/approval/download_excel.php'; exit; }
if ($route === 'approval_google_holiday_sync') { \App\Core\View::render('approval/google_holiday_sync', array('title'=>'공휴일 동기화','selectedMenu'=>'전자결재','dashboardType'=>$dashboardType)); exit; }
// ==========================
//  일반 메뉴
// ==========================
$view = isset($views[$route]) ? $views[$route] : 'placeholder/index';

\App\Core\View::render($view, array(
    'title' => $route,
    'selectedMenu' => $route,
    'dashboardType' => $dashboardType,
));
