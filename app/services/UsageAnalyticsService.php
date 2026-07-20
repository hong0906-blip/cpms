<?php
/**
 * C:\www\cpms\app\services\UsageAnalyticsService.php
 * - CPMS 사용기록 저장, 설치/업데이트, 통계 조회 및 보관 로그 정리 서비스
 * - PHP 5.6 호환
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use PDO;

class UsageAnalyticsService
{
    const SESSION_TABLE = 'cpms_usage_sessions';
    const EVENT_TABLE = 'cpms_usage_events';
    const SESSION_TIMEOUT_SECONDS = 1800;
    const ONLINE_SECONDS = 600;
    const LAST_ACTIVITY_WRITE_SECONDS = 60;
    const DUPLICATE_VIEW_SECONDS = 10;
    const DETAIL_RETENTION_DAYS = 180;

    private static $installed = null;
    private static $requestRecorded = false;

    private static function nowDateTime()
    {
        return new \DateTime('now', new \DateTimeZone('Asia/Seoul'));
    }

    private static function execute($pdo, $sql, $params)
    {
        $statement = $pdo->prepare($sql);
        $statement->execute(is_array($params) ? $params : array());
        return $statement;
    }

    private static function logError($context, $exception)
    {
        $message = ($exception instanceof \Exception) ? $exception->getMessage() : (string)$exception;
        error_log('[CPMS usage analytics] ' . (string)$context . ': ' . $message);
    }

    private static function databaseName($pdo)
    {
        $statement = self::execute($pdo, 'SELECT DATABASE()', array());
        return (string)$statement->fetchColumn();
    }

    private static function tableExists($pdo, $tableName)
    {
        $databaseName = self::databaseName($pdo);
        if ($databaseName === '') return false;

        $sql = "SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :database_name AND TABLE_NAME = :table_name";
        $statement = self::execute($pdo, $sql, array(
            ':database_name' => $databaseName,
            ':table_name' => (string)$tableName,
        ));
        return ((int)$statement->fetchColumn() > 0);
    }

    private static function columnExists($pdo, $tableName, $columnName)
    {
        $databaseName = self::databaseName($pdo);
        if ($databaseName === '') return false;

        $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :database_name
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = :column_name";
        $statement = self::execute($pdo, $sql, array(
            ':database_name' => $databaseName,
            ':table_name' => (string)$tableName,
            ':column_name' => (string)$columnName,
        ));
        return ((int)$statement->fetchColumn() > 0);
    }

    private static function indexExists($pdo, $tableName, $indexName)
    {
        $databaseName = self::databaseName($pdo);
        if ($databaseName === '') return false;

        $sql = "SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = :database_name
                  AND TABLE_NAME = :table_name
                  AND INDEX_NAME = :index_name";
        $statement = self::execute($pdo, $sql, array(
            ':database_name' => $databaseName,
            ':table_name' => (string)$tableName,
            ':index_name' => (string)$indexName,
        ));
        return ((int)$statement->fetchColumn() > 0);
    }

    public static function isInstalled($pdo)
    {
        if (self::$installed !== null) return self::$installed;
        if (isset($_SESSION['_cpms_usage_table_check']) && is_array($_SESSION['_cpms_usage_table_check'])) {
            $cached = $_SESSION['_cpms_usage_table_check'];
            $checkedAt = isset($cached['checked_at']) ? (int)$cached['checked_at'] : 0;
            if ($checkedAt > 0 && (time() - $checkedAt) < 60 && isset($cached['installed'])) {
                self::$installed = !empty($cached['installed']);
                return self::$installed;
            }
        }
        if (!$pdo) {
            self::$installed = false;
            return false;
        }

        try {
            self::$installed = self::tableExists($pdo, self::SESSION_TABLE)
                && self::tableExists($pdo, self::EVENT_TABLE);
        } catch (\Exception $e) {
            self::logError('installation check', $e);
            self::$installed = false;
        }

        $_SESSION['_cpms_usage_table_check'] = array(
            'checked_at' => time(),
            'installed' => self::$installed ? 1 : 0,
        );

        return self::$installed;
    }

    public static function installOrUpdate($pdo)
    {
        $result = array('ok' => false, 'message' => '', 'details' => array());
        if (!$pdo) {
            $result['message'] = 'DB 연결을 확인할 수 없어 사용기록 기능을 설치하지 못했습니다.';
            return $result;
        }

        try {
            $sessionCreateSql = "CREATE TABLE IF NOT EXISTS " . self::SESSION_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                employee_id INT UNSIGNED NOT NULL DEFAULT 0,
                email VARCHAR(190) NOT NULL DEFAULT '',
                employee_name VARCHAR(100) NOT NULL DEFAULT '',
                department VARCHAR(80) NOT NULL DEFAULT '',
                position VARCHAR(80) NOT NULL DEFAULT '',
                session_hash CHAR(64) NOT NULL,
                started_at DATETIME NOT NULL,
                last_activity_at DATETIME NOT NULL,
                request_count INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            self::execute($pdo, $sessionCreateSql, array());

            $eventCreateSql = "CREATE TABLE IF NOT EXISTS " . self::EVENT_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                employee_id INT UNSIGNED NOT NULL DEFAULT 0,
                email VARCHAR(190) NOT NULL DEFAULT '',
                employee_name VARCHAR(100) NOT NULL DEFAULT '',
                department VARCHAR(80) NOT NULL DEFAULT '',
                position VARCHAR(80) NOT NULL DEFAULT '',
                event_type VARCHAR(30) NOT NULL DEFAULT 'menu_view',
                menu_key VARCHAR(80) NOT NULL DEFAULT '',
                menu_name VARCHAR(100) NOT NULL DEFAULT '',
                route_name VARCHAR(190) NOT NULL DEFAULT '',
                tab_key VARCHAR(100) NOT NULL DEFAULT '',
                tab_name VARCHAR(120) NOT NULL DEFAULT '',
                action_name VARCHAR(80) NOT NULL DEFAULT '',
                event_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            self::execute($pdo, $eventCreateSql, array());

            if (!self::columnExists($pdo, self::SESSION_TABLE, 'id')) {
                self::execute($pdo, 'ALTER TABLE ' . self::SESSION_TABLE . ' ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST', array());
                $result['details'][] = self::SESSION_TABLE . '.id 컬럼 추가';
            }
            if (!self::columnExists($pdo, self::EVENT_TABLE, 'id')) {
                self::execute($pdo, 'ALTER TABLE ' . self::EVENT_TABLE . ' ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST', array());
                $result['details'][] = self::EVENT_TABLE . '.id 컬럼 추가';
            }

            $sessionColumns = array(
                'employee_id' => "INT UNSIGNED NOT NULL DEFAULT 0",
                'email' => "VARCHAR(190) NOT NULL DEFAULT ''",
                'employee_name' => "VARCHAR(100) NOT NULL DEFAULT ''",
                'department' => "VARCHAR(80) NOT NULL DEFAULT ''",
                'position' => "VARCHAR(80) NOT NULL DEFAULT ''",
                'session_hash' => "CHAR(64) NULL",
                'started_at' => "DATETIME NULL",
                'last_activity_at' => "DATETIME NULL",
                'request_count' => "INT UNSIGNED NOT NULL DEFAULT 1",
                'created_at' => "DATETIME NULL",
            );
            $eventColumns = array(
                'session_id' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                'employee_id' => "INT UNSIGNED NOT NULL DEFAULT 0",
                'email' => "VARCHAR(190) NOT NULL DEFAULT ''",
                'employee_name' => "VARCHAR(100) NOT NULL DEFAULT ''",
                'department' => "VARCHAR(80) NOT NULL DEFAULT ''",
                'position' => "VARCHAR(80) NOT NULL DEFAULT ''",
                'event_type' => "VARCHAR(30) NOT NULL DEFAULT 'menu_view'",
                'menu_key' => "VARCHAR(80) NOT NULL DEFAULT ''",
                'menu_name' => "VARCHAR(100) NOT NULL DEFAULT ''",
                'route_name' => "VARCHAR(190) NOT NULL DEFAULT ''",
                'tab_key' => "VARCHAR(100) NOT NULL DEFAULT ''",
                'tab_name' => "VARCHAR(120) NOT NULL DEFAULT ''",
                'action_name' => "VARCHAR(80) NOT NULL DEFAULT ''",
                'event_at' => "DATETIME NULL",
            );

            foreach ($sessionColumns as $columnName => $definition) {
                if (!self::columnExists($pdo, self::SESSION_TABLE, $columnName)) {
                    self::execute($pdo, 'ALTER TABLE ' . self::SESSION_TABLE . ' ADD COLUMN ' . $columnName . ' ' . $definition, array());
                    $result['details'][] = self::SESSION_TABLE . '.' . $columnName . ' 컬럼 추가';
                }
            }
            foreach ($eventColumns as $columnName => $definition) {
                if (!self::columnExists($pdo, self::EVENT_TABLE, $columnName)) {
                    self::execute($pdo, 'ALTER TABLE ' . self::EVENT_TABLE . ' ADD COLUMN ' . $columnName . ' ' . $definition, array());
                    $result['details'][] = self::EVENT_TABLE . '.' . $columnName . ' 컬럼 추가';
                }
            }

            $indexes = array(
                array(self::SESSION_TABLE, 'idx_usage_sessions_started_at', 'started_at'),
                array(self::SESSION_TABLE, 'idx_usage_sessions_last_activity', 'last_activity_at'),
                array(self::SESSION_TABLE, 'idx_usage_sessions_employee', 'employee_id'),
                array(self::SESSION_TABLE, 'idx_usage_sessions_email', 'email'),
                array(self::SESSION_TABLE, 'idx_usage_sessions_hash', 'session_hash'),
                array(self::EVENT_TABLE, 'idx_usage_events_event_at', 'event_at'),
                array(self::EVENT_TABLE, 'idx_usage_events_employee', 'employee_id'),
                array(self::EVENT_TABLE, 'idx_usage_events_email', 'email'),
                array(self::EVENT_TABLE, 'idx_usage_events_menu', 'menu_key'),
                array(self::EVENT_TABLE, 'idx_usage_events_tab', 'tab_key'),
                array(self::EVENT_TABLE, 'idx_usage_events_session', 'session_id'),
                array(self::EVENT_TABLE, 'idx_usage_events_time_menu', 'event_at, menu_key'),
            );
            foreach ($indexes as $indexSpec) {
                if (!self::indexExists($pdo, $indexSpec[0], $indexSpec[1])) {
                    self::execute($pdo, 'ALTER TABLE ' . $indexSpec[0] . ' ADD INDEX ' . $indexSpec[1] . ' (' . $indexSpec[2] . ')', array());
                    $result['details'][] = $indexSpec[1] . ' 인덱스 추가';
                }
            }

            self::$installed = true;
            $_SESSION['_cpms_usage_table_check'] = array('checked_at' => time(), 'installed' => 1);
            $result['ok'] = true;
            $result['message'] = count($result['details']) > 0
                ? '사용기록 기능 설치/업데이트가 완료되었습니다.'
                : '사용기록 기능이 이미 최신 상태입니다.';
        } catch (\Exception $e) {
            self::logError('install or update', $e);
            self::$installed = false;
            $_SESSION['_cpms_usage_table_check'] = array('checked_at' => time(), 'installed' => 0);
            $result['message'] = '사용기록 기능 설치/업데이트에 실패했습니다. 오류 내용은 서버 로그에 기록되었습니다.';
        }

        return $result;
    }

    public static function knownMenus()
    {
        return array(
            'dashboard' => '대시보드',
            'scheduler' => '스케줄러',
            'employees' => '임직원',
            'notice' => '공지사항',
            'approval' => '전자결재',
            'public_affairs' => '공무',
            'management' => '관리',
            'construction' => '공사',
            'safety' => '안전/보건',
            'quality' => '품질',
            'company_profit' => '경영현황',
            'usage_analytics' => '사용현황 분석',
        );
    }

    public static function knownTabs($menuKey)
    {
        $tabs = array(
            'dashboard' => array('employee' => '직원 대시보드', 'executive' => '임원 대시보드'),
            'scheduler' => array('month' => '월별', 'week' => '주차별'),
            'employees' => array('directory' => '임직원 명부'),
            'notice' => array('board' => '공지사항 목록'),
            'approval' => array('active' => '진행중', 'rejected' => '반려', 'cancelled' => '취소', 'completed' => '완료', 'create' => '작성', 'detail' => '상세'),
            'public_affairs' => array(
                'monthly_summary' => '월별 투입비 집계',
                'project_manage' => '프로젝트 관리',
                'collaboration' => '공무 협업툴',
                'collaboration_summary' => '협업 Summary',
                'collaboration_board' => '협업 Board',
                'collaboration_list' => '협업 List',
                'collaboration_calendar' => '협업 Calendar',
                'collaboration_timeline' => '협업 Timeline',
                'collaboration_files' => '협업 Files',
                'collaboration_activity' => '협업 Activity',
                'collaboration_reports' => '협업 Reports',
                'collaboration_settings' => '협업 Settings',
            ),
            'management' => array(
                'employees' => '직원명부',
                'workforce' => '인력관리',
                'labor_calc' => '노무비 계산',
                'attendance' => '출퇴근·근태관리',
                'leave_management' => '연차 관리',
                'company_overhead' => '총관리비',
                'drive_check' => 'Drive 점검',
                'data_archive' => '데이터 아카이브',
                'project_drive_sync' => '프로젝트 Drive 동기화',
            ),
            'construction' => array(
                'status' => '상황',
                'monthly_input' => '투입비 상세',
                'roles' => '담당지정',
                'gantt' => '공정표',
                'daily_status' => '일별 현황',
                'labor' => '노무비',
                'outsourcing' => '외주비',
                'equipment' => '장비',
                'materials' => '자재구입비',
                'issues' => '이슈',
                'security' => '보안사고',
                'safety' => '안전사고',
            ),
            'safety' => array('safety_cost' => '안전관리비 사용내역', 'incidents' => '안전사고', 'samsung_portal' => '삼성 상생협력포탈'),
            'quality' => array('file_list' => '품질 파일 관리'),
            'company_profit' => array('summary' => '경영현황 요약'),
            'usage_analytics' => array('overview' => '사용현황 개요', 'user_detail' => '직원 상세 활동'),
        );

        return isset($tabs[$menuKey]) ? $tabs[$menuKey] : array();
    }

    private static function excludedRoute($route, $get)
    {
        $route = strtolower(trim((string)$route));
        $exact = array(
            'ping', 'login', 'logout', 'portal_entry',
            'usage_analytics/setup', 'usage_analytics/export', 'usage_analytics/cleanup',
            'public_affairs_collab_debug', 'public_affairs_collab_repair', 'public_affairs_collab_trace',
        );
        if (in_array($route, $exact, true)) return true;
        if (strpos($route, 'db_setup_') === 0) return true;
        if (strpos($route, 'ajax/') === 0) return true;
        if (strpos($route, 'debug') !== false || strpos($route, 'repair') !== false || strpos($route, 'recovery') !== false) return true;
        if (strpos($route, 'autosave') !== false || strpos($route, 'auto_save') !== false) return true;
        if (is_array($get) && isset($get['fragment']) && trim((string)$get['fragment']) !== '') return true;
        return false;
    }

    private static function resolveMenu($route)
    {
        $route = trim((string)$route);
        $lowerRoute = strtolower($route);

        if ($lowerRoute === 'attendance/check_in' || $lowerRoute === 'attendance/check_out') return array('key' => 'dashboard', 'name' => '대시보드');
        if ($lowerRoute === 'usage_analytics' || strpos($lowerRoute, 'usage_analytics/') === 0) return array('key' => 'usage_analytics', 'name' => '사용현황 분석');
        if ($route === '대시보드' || strpos($lowerRoute, 'dashboard') === 0 || strpos($lowerRoute, 'tasks/') === 0 || strpos($lowerRoute, 'request/') === 0) return array('key' => 'dashboard', 'name' => '대시보드');
        if ($route === '스케줄러' || $lowerRoute === 'scheduler') return array('key' => 'scheduler', 'name' => '스케줄러');
        if ($route === '임직원' || strpos($lowerRoute, 'employees') === 0 || strpos($lowerRoute, 'employee_') === 0) return array('key' => 'employees', 'name' => '임직원');
        if ($route === '공지사항' || strpos($lowerRoute, 'notice') !== false || strpos($lowerRoute, 'birthday_comment') !== false) return array('key' => 'notice', 'name' => '공지사항');
        if ($route === '전자결재' || strpos($lowerRoute, 'approval') === 0) return array('key' => 'approval', 'name' => '전자결재');
        if ($route === '공무' || strpos($lowerRoute, 'project/') === 0 || strpos($lowerRoute, 'public_affairs') === 0) return array('key' => 'public_affairs', 'name' => '공무');
        if ($route === '관리' || strpos($lowerRoute, 'admin/') === 0 || strpos($lowerRoute, 'management/') === 0 || strpos($lowerRoute, 'attendance/') === 0) return array('key' => 'management', 'name' => '관리');
        if ($route === '공사' || strpos($lowerRoute, 'construction/') === 0 || $lowerRoute === 'construction_home') return array('key' => 'construction', 'name' => '공사');
        if ($route === '안전/보건' || strpos($lowerRoute, 'safety/') === 0 || $lowerRoute === 'safety_home') return array('key' => 'safety', 'name' => '안전/보건');
        if ($route === '품질' || $lowerRoute === 'quality_home' || strpos($lowerRoute, 'quality/') === 0) return array('key' => 'quality', 'name' => '품질');
        if ($route === '경영현황' || strpos($lowerRoute, 'company_profit') === 0 || strpos($lowerRoute, 'management_profit') === 0) return array('key' => 'company_profit', 'name' => '경영현황');
        return null;
    }

    private static function inferTabFromRoute($menuKey, $route)
    {
        $route = strtolower((string)$route);
        if ($menuKey === 'management') {
            if (strpos($route, 'workforce') !== false) return 'workforce';
            if (strpos($route, 'labor') !== false) return 'labor_calc';
            if (strpos($route, 'leave') !== false) return 'leave_management';
            if (strpos($route, 'attendance') !== false) return 'attendance';
            if (strpos($route, 'overhead') !== false || strpos($route, 'payroll') !== false || strpos($route, 'fuel') !== false || strpos($route, 'vehicle') !== false || strpos($route, 'lease') !== false || strpos($route, 'corporate_card') !== false) return 'company_overhead';
            if (strpos($route, 'employees') !== false) return 'employees';
        }
        if ($menuKey === 'construction') {
            if (strpos($route, 'labor') !== false) return 'labor';
            if (strpos($route, 'outsourcing') !== false) return 'outsourcing';
            if (strpos($route, 'equipment') !== false) return 'equipment';
            if (strpos($route, 'material') !== false) return 'materials';
            if (strpos($route, 'issue') !== false) return 'issues';
            if (strpos($route, 'security') !== false) return 'security';
            if (strpos($route, 'safety') !== false) return 'safety';
            if (strpos($route, 'daily') !== false) return 'daily_status';
            if (strpos($route, 'schedule') !== false || strpos($route, 'work_item') !== false) return 'gantt';
            if (strpos($route, 'roles') !== false) return 'roles';
            if (strpos($route, 'monthly') !== false) return 'monthly_input';
        }
        if ($menuKey === 'public_affairs') {
            if (strpos($route, 'collaboration') !== false || strpos($route, 'public_affairs_collab') !== false) return 'collaboration';
            if (strpos($route, 'monthly') !== false) return 'monthly_summary';
            if (strpos($route, 'project') !== false || strpos($route, 'contract') !== false || strpos($route, 'unit_price') !== false) return 'project_manage';
        }
        if ($menuKey === 'approval') {
            if (strpos($route, 'create') !== false) return 'create';
            if (strpos($route, 'detail') !== false || strpos($route, 'file') !== false || strpos($route, 'print') !== false) return 'detail';
        }
        if ($menuKey === 'safety') {
            if (strpos($route, 'incident') !== false) return 'incidents';
            if (strpos($route, 'samsung_portal') !== false) return 'samsung_portal';
            return 'safety_cost';
        }
        return '';
    }

    private static function resolveTab($menuKey, $route, $get)
    {
        $tabs = self::knownTabs($menuKey);
        $tabKey = '';

        if (is_array($get) && isset($get['tab'])) $tabKey = trim((string)$get['tab']);
        if ($menuKey === 'dashboard') {
            $dashboardType = isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee';
            $tabKey = $dashboardType === 'executive' ? 'executive' : 'employee';
        }
        if ($menuKey === 'approval' && is_array($get) && isset($get['view'])) $tabKey = trim((string)$get['view']);
        if ($menuKey === 'scheduler' && is_array($get) && isset($get['view'])) $tabKey = trim((string)$get['view']);
        if ($menuKey === 'public_affairs' && (strpos(strtolower((string)$route), 'collab') !== false || $tabKey === 'collaboration')) {
            $section = is_array($get) && isset($get['section']) ? trim((string)$get['section']) : '';
            if ($section !== '' && isset($tabs['collaboration_' . $section])) $tabKey = 'collaboration_' . $section;
            else $tabKey = 'collaboration';
        }
        if ($menuKey === 'usage_analytics') {
            $tabKey = is_array($get) && isset($get['user_id']) && (int)$get['user_id'] > 0 ? 'user_detail' : 'overview';
        }

        if ($tabKey === '' || !isset($tabs[$tabKey])) $tabKey = self::inferTabFromRoute($menuKey, $route);
        if ($tabKey === '' || !isset($tabs[$tabKey])) {
            $keys = array_keys($tabs);
            $tabKey = count($keys) > 0 ? (string)$keys[0] : '';
        }

        return array('key' => $tabKey, 'name' => isset($tabs[$tabKey]) ? $tabs[$tabKey] : '');
    }

    private static function actionName($route, $requestMethod)
    {
        $route = strtolower((string)$route);
        if ($route === 'attendance/check_in') return '출근 버튼 입력';
        if ($route === 'attendance/check_out') return '퇴근 버튼 입력';
        if (strpos($route, 'reject') !== false) return '반려';
        if (strpos($route, 'delete') !== false) return '삭제';
        if (strpos($route, 'cancel') !== false) return '취소';
        if (strpos($route, 'decide') !== false) return '처리';
        if (strpos($route, 'approve') !== false) return '승인';
        if (strpos($route, 'download') !== false || strpos($route, '/file') !== false || substr($route, -5) === '_file') return '다운로드';
        if (strpos($route, 'export') !== false) return '내보내기';
        if (strpos($route, 'upload') !== false || strpos($route, 'import') !== false) return '업로드';
        if (strpos($route, 'create') !== false || strpos($route, '_add') !== false) return '등록';
        if (strpos($route, 'update') !== false || strpos($route, 'edit') !== false) return '수정';
        if (strpos($route, 'complete') !== false) return '완료';
        if (strpos($route, 'save') !== false || strpos($route, 'store') !== false) return '저장';
        return strtoupper((string)$requestMethod) === 'POST' ? '실행' : '';
    }

    private static function insertEvent($pdo, $values)
    {
        $sql = "INSERT INTO " . self::EVENT_TABLE . "
                (session_id, employee_id, email, employee_name, department, position,
                 event_type, menu_key, menu_name, route_name, tab_key, tab_name, action_name, event_at)
                VALUES
                (:session_id, :employee_id, :email, :employee_name, :department, :position,
                 :event_type, :menu_key, :menu_name, :route_name, :tab_key, :tab_name, :action_name, :event_at)";
        self::execute($pdo, $sql, $values);
    }

    private static function excludedEmployeeNames()
    {
        return array('노준형', '이호상');
    }

    private static function isExcludedEmployeeName($name)
    {
        return in_array(trim((string)$name), self::excludedEmployeeNames(), true);
    }

    private static function appendExcludedEmployeeFilter(&$where, &$params, $nameField, $prefix)
    {
        $placeholders = array();
        foreach (self::excludedEmployeeNames() as $index => $name) {
            $key = ':' . $prefix . '_excluded_name_' . $index;
            $placeholders[] = $key;
            $params[$key] = $name;
        }
        if (count($placeholders) > 0) {
            $where[] = "COALESCE(TRIM(" . $nameField . "), '') NOT IN (" . implode(', ', $placeholders) . ')';
        }
    }

    public static function recordRequest($route, $requestMethod, $get)
    {
        if (self::$requestRecorded) return;
        self::$requestRecorded = true;
        if (self::excludedRoute($route, $get)) return;

        try {
            if (!Auth::check()) return;
            if (strpos(strtolower((string)$route), 'usage_analytics') === 0 && !Auth::canAccessUsageAnalytics()) return;

            $pdo = Db::pdo();
            if (!$pdo || !self::isInstalled($pdo)) return;

            $user = Auth::user();
            if (!is_array($user)) return;

            $sessionId = session_id();
            if ($sessionId === '') return;

            $now = self::nowDateTime();
            $nowText = $now->format('Y-m-d H:i:s');
            $nowTimestamp = (int)$now->format('U');
            $sessionHash = hash('sha256', (string)$sessionId);
            $employeeId = isset($user['id']) ? (int)$user['id'] : 0;
            $email = isset($user['email']) ? strtolower(trim((string)$user['email'])) : '';
            $employeeName = isset($user['name']) ? trim((string)$user['name']) : '';
            $department = isset($user['department']) ? trim((string)$user['department']) : '';
            $position = isset($user['position']) ? trim((string)$user['position']) : '';
            if ($email === '') return;
            if (self::isExcludedEmployeeName($employeeName)) return;

            $state = isset($_SESSION['_cpms_usage_analytics']) && is_array($_SESSION['_cpms_usage_analytics'])
                ? $_SESSION['_cpms_usage_analytics']
                : array();
            $stateSessionId = isset($state['usage_session_id']) ? (int)$state['usage_session_id'] : 0;
            $previousRequestAt = isset($state['last_request_at']) ? (int)$state['last_request_at'] : 0;
            $sameIdentity = isset($state['session_hash'], $state['email'])
                && (string)$state['session_hash'] === $sessionHash
                && strtolower((string)$state['email']) === $email;
            $timedOut = $previousRequestAt > 0 && ($nowTimestamp - $previousRequestAt) >= self::SESSION_TIMEOUT_SECONDS;
            $newUsageSession = false;

            if (!$sameIdentity || $stateSessionId <= 0 || $timedOut) {
                $stateSessionId = 0;
                if (!$timedOut) {
                    $cutoff = clone $now;
                    $cutoff->modify('-' . self::SESSION_TIMEOUT_SECONDS . ' seconds');
                    $findSql = "SELECT id FROM " . self::SESSION_TABLE . "
                                WHERE session_hash = :session_hash
                                  AND LOWER(email) = :email
                                  AND last_activity_at >= :cutoff
                                ORDER BY id DESC LIMIT 1";
                    $findStatement = self::execute($pdo, $findSql, array(
                        ':session_hash' => $sessionHash,
                        ':email' => $email,
                        ':cutoff' => $cutoff->format('Y-m-d H:i:s'),
                    ));
                    $stateSessionId = (int)$findStatement->fetchColumn();
                }

                if ($stateSessionId <= 0) {
                    $insertSql = "INSERT INTO " . self::SESSION_TABLE . "
                                  (employee_id, email, employee_name, department, position, session_hash,
                                   started_at, last_activity_at, request_count, created_at)
                                  VALUES
                                  (:employee_id, :email, :employee_name, :department, :position, :session_hash,
                                   :started_at, :last_activity_at, 1, :created_at)";
                    self::execute($pdo, $insertSql, array(
                        ':employee_id' => $employeeId,
                        ':email' => $email,
                        ':employee_name' => $employeeName,
                        ':department' => $department,
                        ':position' => $position,
                        ':session_hash' => $sessionHash,
                        ':started_at' => $nowText,
                        ':last_activity_at' => $nowText,
                        ':created_at' => $nowText,
                    ));
                    $stateSessionId = (int)$pdo->lastInsertId();
                    $newUsageSession = true;
                }

                $state = array(
                    'usage_session_id' => $stateSessionId,
                    'session_hash' => $sessionHash,
                    'email' => $email,
                    'last_request_at' => $nowTimestamp,
                    'last_db_touch_at' => $nowTimestamp,
                    'pending_requests' => 0,
                );
            } else {
                $state['last_request_at'] = $nowTimestamp;
                $state['pending_requests'] = isset($state['pending_requests']) ? ((int)$state['pending_requests'] + 1) : 1;
                $lastDbTouchAt = isset($state['last_db_touch_at']) ? (int)$state['last_db_touch_at'] : 0;
                if (($nowTimestamp - $lastDbTouchAt) >= self::LAST_ACTIVITY_WRITE_SECONDS) {
                    $pendingRequests = max(1, (int)$state['pending_requests']);
                    $touchSql = "UPDATE " . self::SESSION_TABLE . "
                                 SET last_activity_at = :last_activity_at,
                                     request_count = request_count + :request_count
                                 WHERE id = :id";
                    $touchStatement = $pdo->prepare($touchSql);
                    $touchStatement->bindValue(':last_activity_at', $nowText);
                    $touchStatement->bindValue(':request_count', $pendingRequests, PDO::PARAM_INT);
                    $touchStatement->bindValue(':id', $stateSessionId, PDO::PARAM_INT);
                    $touchStatement->execute();
                    $state['last_db_touch_at'] = $nowTimestamp;
                    $state['pending_requests'] = 0;
                }
            }
            $_SESSION['_cpms_usage_analytics'] = $state;

            if ($newUsageSession) {
                self::insertEvent($pdo, array(
                    ':session_id' => $stateSessionId,
                    ':employee_id' => $employeeId,
                    ':email' => $email,
                    ':employee_name' => $employeeName,
                    ':department' => $department,
                    ':position' => $position,
                    ':event_type' => 'session_start',
                    ':menu_key' => '',
                    ':menu_name' => '',
                    ':route_name' => '',
                    ':tab_key' => '',
                    ':tab_name' => '',
                    ':action_name' => '접속 시작',
                    ':event_at' => $nowText,
                ));
            }

            $menu = self::resolveMenu($route);
            if (!is_array($menu)) return;
            $tab = self::resolveTab($menu['key'], $route, $get);
            $actionName = self::actionName($route, $requestMethod);
            $eventType = $actionName !== '' ? 'action' : 'menu_view';

            if ($eventType === 'menu_view') {
                $duplicateCutoff = clone $now;
                $duplicateCutoff->modify('-' . self::DUPLICATE_VIEW_SECONDS . ' seconds');
                $duplicateSql = "SELECT id FROM " . self::EVENT_TABLE . "
                                 WHERE LOWER(email) = :email
                                   AND event_type = 'menu_view'
                                   AND menu_key = :menu_key
                                   AND tab_key = :tab_key
                                   AND event_at >= :event_at
                                 ORDER BY id DESC LIMIT 1";
                $duplicateStatement = self::execute($pdo, $duplicateSql, array(
                    ':email' => $email,
                    ':menu_key' => $menu['key'],
                    ':tab_key' => $tab['key'],
                    ':event_at' => $duplicateCutoff->format('Y-m-d H:i:s'),
                ));
                if ((int)$duplicateStatement->fetchColumn() > 0) return;
            }

            self::insertEvent($pdo, array(
                ':session_id' => $stateSessionId,
                ':employee_id' => $employeeId,
                ':email' => $email,
                ':employee_name' => $employeeName,
                ':department' => $department,
                ':position' => $position,
                ':event_type' => $eventType,
                ':menu_key' => $menu['key'],
                ':menu_name' => $menu['name'],
                ':route_name' => substr((string)$route, 0, 190),
                ':tab_key' => $tab['key'],
                ':tab_name' => $tab['name'],
                ':action_name' => $actionName,
                ':event_at' => $nowText,
            ));
        } catch (\Exception $e) {
            self::logError('record request', $e);
        }
    }

    private static function limitText($value, $length)
    {
        $value = trim((string)$value);
        $value = str_replace(array("\r", "\n", "\t", "\0"), ' ', $value);
        if (function_exists('mb_substr')) return mb_substr($value, 0, (int)$length, 'UTF-8');
        return substr($value, 0, (int)$length);
    }

    private static function validDate($value)
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $parts = explode('-', $value);
        return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
    }

    public static function filters($input)
    {
        if (!is_array($input)) $input = array();
        $now = self::nowDateTime();
        $today = $now->format('Y-m-d');
        $period = isset($input['period']) ? trim((string)$input['period']) : '7d';
        if (!in_array($period, array('today', '7d', '30d', 'month', 'custom'), true)) $period = '7d';

        $endDate = $today;
        $startDate = $today;
        if ($period === '7d') {
            $start = clone $now;
            $start->modify('-6 days');
            $startDate = $start->format('Y-m-d');
        } else if ($period === '30d') {
            $start = clone $now;
            $start->modify('-29 days');
            $startDate = $start->format('Y-m-d');
        } else if ($period === 'month') {
            $startDate = $now->format('Y-m-01');
        } else if ($period === 'custom') {
            $candidateStart = isset($input['date_from']) ? (string)$input['date_from'] : '';
            $candidateEnd = isset($input['date_to']) ? (string)$input['date_to'] : '';
            if (self::validDate($candidateStart) && self::validDate($candidateEnd) && $candidateStart <= $candidateEnd && $candidateStart <= $today) {
                $startDate = $candidateStart;
                $endDate = $candidateEnd > $today ? $today : $candidateEnd;
            } else {
                $period = '7d';
                $start = clone $now;
                $start->modify('-6 days');
                $startDate = $start->format('Y-m-d');
                $endDate = $today;
            }
        }

        $startObject = new \DateTime($startDate . ' 00:00:00', new \DateTimeZone('Asia/Seoul'));
        $endObject = new \DateTime($endDate . ' 00:00:00', new \DateTimeZone('Asia/Seoul'));
        $days = (int)$startObject->diff($endObject)->days + 1;
        if ($days > 366) {
            $startObject = clone $endObject;
            $startObject->modify('-365 days');
            $startDate = $startObject->format('Y-m-d');
            $days = 366;
        }
        $endExclusive = clone $endObject;
        $endExclusive->modify('+1 day');
        $previousEnd = clone $startObject;
        $previousStart = clone $previousEnd;
        $previousStart->modify('-' . $days . ' days');

        $menu = isset($input['menu']) ? self::limitText($input['menu'], 80) : '';
        $knownMenus = self::knownMenus();
        if ($menu !== '' && !isset($knownMenus[$menu])) $menu = '';
        $tab = isset($input['tab']) ? self::limitText($input['tab'], 100) : '';
        if ($tab !== '') {
            $knownTabs = $menu !== '' ? self::knownTabs($menu) : array();
            if (!isset($knownTabs[$tab])) $tab = '';
        }

        $online = isset($input['online']) ? (string)$input['online'] : 'all';
        if (!in_array($online, array('all', 'yes', 'no'), true)) $online = 'all';
        $connected = isset($input['connected']) ? (string)$input['connected'] : 'all';
        if (!in_array($connected, array('all', 'yes', 'no'), true)) $connected = 'all';
        $sort = isset($input['sort']) ? (string)$input['sort'] : 'activity_desc';
        if (!in_array($sort, array('access_desc', 'activity_desc', 'last_asc', 'name'), true)) $sort = 'activity_desc';

        $labels = array('today' => '오늘', '7d' => '최근 7일', '30d' => '최근 30일', 'month' => '이번 달', 'custom' => '사용자 지정 기간');
        return array(
            'period' => $period,
            'period_label' => isset($labels[$period]) ? $labels[$period] : '최근 7일',
            'date_from' => $startDate,
            'date_to' => $endDate,
            'start_at' => $startObject->format('Y-m-d H:i:s'),
            'end_at' => $endExclusive->format('Y-m-d H:i:s'),
            'previous_start_at' => $previousStart->format('Y-m-d H:i:s'),
            'previous_end_at' => $previousEnd->format('Y-m-d H:i:s'),
            'days' => $days,
            'q' => isset($input['q']) ? self::limitText($input['q'], 50) : '',
            'department' => isset($input['department']) ? Auth::normalizeDepartmentValue(self::limitText($input['department'], 80)) : '',
            'position' => isset($input['position']) ? self::limitText($input['position'], 80) : '',
            'menu' => $menu,
            'tab' => $tab,
            'online' => $online,
            'connected' => $connected,
            'sort' => $sort,
        );
    }

    private static function likeValue($value)
    {
        $value = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string)$value);
        return '%' . $value . '%';
    }

    private static function appendPeopleFilters(&$where, &$params, $filters, $fields, $prefix)
    {
        self::appendExcludedEmployeeFilter($where, $params, $fields['name'], $prefix);
        if ($filters['q'] !== '') {
            $key = ':' . $prefix . '_q';
            $where[] = $fields['name'] . " LIKE " . $key . " ESCAPE '\\\\'";
            $params[$key] = self::likeValue($filters['q']);
        }
        if ($filters['department'] !== '') {
            $key = ':' . $prefix . '_department';
            $where[] = $fields['department'] . ' = ' . $key;
            $params[$key] = $filters['department'];
        }
        if ($filters['position'] !== '') {
            $key = ':' . $prefix . '_position';
            $where[] = $fields['position'] . ' = ' . $key;
            $params[$key] = $filters['position'];
        }
    }

    private static function fetchAll($pdo, $sql, $params)
    {
        $statement = self::execute($pdo, $sql, $params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private static function sessionAggregate($pdo, $startAt, $endAt, $filters, $applyPeopleFilters)
    {
        $where = array('started_at >= :session_start', 'started_at < :session_end', "TRIM(email) <> ''");
        $params = array(':session_start' => $startAt, ':session_end' => $endAt);
        if ($applyPeopleFilters) {
            self::appendPeopleFilters($where, $params, $filters, array(
                'name' => 'employee_name', 'department' => 'department', 'position' => 'position'
            ), 'session_people');
        } else {
            self::appendExcludedEmployeeFilter($where, $params, 'employee_name', 'session');
        }
        $sql = "SELECT LOWER(TRIM(email)) AS email_key,
                       COUNT(*) AS connection_count,
                       MAX(last_activity_at) AS last_activity_at
                FROM " . self::SESSION_TABLE . "
                WHERE " . implode(' AND ', $where) . "
                GROUP BY LOWER(TRIM(email))";
        return self::fetchAll($pdo, $sql, $params);
    }

    private static function onlineAggregate($pdo, $cutoffAt, $endAt)
    {
        $where = array(
            'last_activity_at >= :online_cutoff',
            'last_activity_at < :online_end',
            "TRIM(email) <> ''",
        );
        $params = array(
            ':online_cutoff' => $cutoffAt,
            ':online_end' => $endAt,
        );
        self::appendExcludedEmployeeFilter($where, $params, 'employee_name', 'online');
        $sql = "SELECT LOWER(TRIM(email)) AS email_key,
                       COUNT(*) AS connection_count,
                       MAX(last_activity_at) AS last_activity_at
                FROM " . self::SESSION_TABLE . "
                WHERE " . implode(' AND ', $where) . "
                GROUP BY LOWER(TRIM(email))";
        return self::fetchAll($pdo, $sql, $params);
    }

    private static function eventAggregate($pdo, $startAt, $endAt, $filters, $applyMenuFilters, $applyPeopleFilters)
    {
        $where = array('event_at >= :event_start', 'event_at < :event_end', "event_type <> 'session_start'", "TRIM(email) <> ''");
        $params = array(':event_start' => $startAt, ':event_end' => $endAt);
        if ($applyMenuFilters && $filters['menu'] !== '') {
            $where[] = 'menu_key = :event_menu';
            $params[':event_menu'] = $filters['menu'];
        }
        if ($applyMenuFilters && $filters['tab'] !== '') {
            $where[] = 'tab_key = :event_tab';
            $params[':event_tab'] = $filters['tab'];
        }
        if ($applyPeopleFilters) {
            self::appendPeopleFilters($where, $params, $filters, array(
                'name' => 'employee_name', 'department' => 'department', 'position' => 'position'
            ), 'event_people');
        } else {
            self::appendExcludedEmployeeFilter($where, $params, 'employee_name', 'event');
        }
        $sql = "SELECT LOWER(TRIM(email)) AS email_key,
                       COUNT(*) AS activity_count,
                       COUNT(DISTINCT CASE WHEN menu_key <> '' THEN menu_key ELSE NULL END) AS menu_count,
                       MAX(event_at) AS last_event_at
                FROM " . self::EVENT_TABLE . "
                WHERE " . implode(' AND ', $where) . "
                GROUP BY LOWER(TRIM(email))";
        return self::fetchAll($pdo, $sql, $params);
    }

    private static function rowsByEmail($rows)
    {
        $result = array();
        foreach ($rows as $row) {
            $key = isset($row['email_key']) ? strtolower(trim((string)$row['email_key'])) : '';
            if ($key !== '') $result[$key] = $row;
        }
        return $result;
    }

    private static function containsText($haystack, $needle)
    {
        if ($needle === '') return true;
        if (function_exists('mb_stripos')) return mb_stripos((string)$haystack, (string)$needle, 0, 'UTF-8') !== false;
        return stripos((string)$haystack, (string)$needle) !== false;
    }

    private static function sortEmployeeRows(&$rows, $sort)
    {
        usort($rows, function ($a, $b) use ($sort) {
            if ($sort === 'name') return strcmp((string)$a['name'], (string)$b['name']);
            if ($sort === 'last_asc') {
                $aValue = (string)$a['last_activity_at'];
                $bValue = (string)$b['last_activity_at'];
                if ($aValue === $bValue) return strcmp((string)$a['name'], (string)$b['name']);
                if ($aValue === '') return -1;
                if ($bValue === '') return 1;
                return strcmp($aValue, $bValue);
            }
            $field = $sort === 'access_desc' ? 'period_connections' : 'period_activities';
            if ((int)$a[$field] === (int)$b[$field]) return strcmp((string)$a['name'], (string)$b['name']);
            return (int)$a[$field] > (int)$b[$field] ? -1 : 1;
        });
    }

    private static function menuStatRows($pdo, $startAt, $endAt, $filters, $prefix)
    {
        $where = array('event_at >= :' . $prefix . '_start', 'event_at < :' . $prefix . '_end', "event_type <> 'session_start'", "menu_key <> ''");
        $params = array(
            ':' . $prefix . '_start' => $startAt,
            ':' . $prefix . '_end' => $endAt,
        );
        self::appendPeopleFilters($where, $params, $filters, array(
            'name' => 'employee_name', 'department' => 'department', 'position' => 'position'
        ), $prefix . '_people');

        $sql = "SELECT menu_key, MAX(menu_name) AS menu_name,
                       COUNT(*) AS usage_count,
                       COUNT(DISTINCT LOWER(TRIM(email))) AS user_count,
                       MAX(event_at) AS last_used_at
                FROM " . self::EVENT_TABLE . "
                WHERE " . implode(' AND ', $where) . "
                GROUP BY menu_key";
        return self::fetchAll($pdo, $sql, $params);
    }

    private static function buildMenuStats($pdo, $filters)
    {
        $currentRows = self::menuStatRows($pdo, $filters['start_at'], $filters['end_at'], $filters, 'menu_current');
        $previousRows = self::menuStatRows($pdo, $filters['previous_start_at'], $filters['previous_end_at'], $filters, 'menu_previous');
        $currentMap = array();
        $previousMap = array();
        foreach ($currentRows as $row) {
            $key = isset($row['menu_key']) ? (string)$row['menu_key'] : '';
            if ($key !== '') $currentMap[$key] = $row;
        }
        foreach ($previousRows as $row) {
            $key = isset($row['menu_key']) ? (string)$row['menu_key'] : '';
            if ($key !== '') $previousMap[$key] = $row;
        }

        $knownMenus = self::knownMenus();
        foreach ($currentMap as $key => $row) {
            if (!isset($knownMenus[$key])) $knownMenus[$key] = isset($row['menu_name']) ? (string)$row['menu_name'] : $key;
        }

        $total = 0;
        foreach ($knownMenus as $key => $label) {
            $total += isset($currentMap[$key]['usage_count']) ? (int)$currentMap[$key]['usage_count'] : 0;
        }
        $stats = array();
        foreach ($knownMenus as $key => $label) {
            $count = isset($currentMap[$key]['usage_count']) ? (int)$currentMap[$key]['usage_count'] : 0;
            $users = isset($currentMap[$key]['user_count']) ? (int)$currentMap[$key]['user_count'] : 0;
            $previous = isset($previousMap[$key]['usage_count']) ? (int)$previousMap[$key]['usage_count'] : 0;
            if ($previous > 0) $change = round((($count - $previous) / $previous) * 100, 1);
            else $change = $count > 0 ? 100.0 : 0.0;
            $stats[] = array(
                'menu_key' => $key,
                'menu_name' => $label,
                'usage_count' => $count,
                'user_count' => $users,
                'average_per_user' => $users > 0 ? round($count / $users, 1) : 0,
                'ratio' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                'previous_count' => $previous,
                'change_percent' => $change,
                'last_used_at' => isset($currentMap[$key]['last_used_at']) ? (string)$currentMap[$key]['last_used_at'] : '',
            );
        }
        usort($stats, function ($a, $b) {
            if ((int)$a['usage_count'] === (int)$b['usage_count']) return strcmp((string)$a['menu_name'], (string)$b['menu_name']);
            return (int)$a['usage_count'] > (int)$b['usage_count'] ? -1 : 1;
        });
        for ($i = 0; $i < count($stats); $i++) $stats[$i]['rank'] = $i + 1;
        return $stats;
    }

    private static function buildTabStats($pdo, $filters, $menuKey)
    {
        $where = array(
            'event_at >= :tab_start', 'event_at < :tab_end',
            "event_type <> 'session_start'", 'menu_key = :tab_menu', "tab_key <> ''"
        );
        $params = array(
            ':tab_start' => $filters['start_at'],
            ':tab_end' => $filters['end_at'],
            ':tab_menu' => $menuKey,
        );
        self::appendPeopleFilters($where, $params, $filters, array(
            'name' => 'employee_name', 'department' => 'department', 'position' => 'position'
        ), 'tab_people');
        $sql = "SELECT tab_key, MAX(tab_name) AS tab_name,
                       COUNT(*) AS usage_count,
                       COUNT(DISTINCT LOWER(TRIM(email))) AS user_count,
                       MAX(event_at) AS last_used_at
                FROM " . self::EVENT_TABLE . "
                WHERE " . implode(' AND ', $where) . "
                GROUP BY tab_key";
        $rows = self::fetchAll($pdo, $sql, $params);
        $rowMap = array();
        foreach ($rows as $row) {
            $key = isset($row['tab_key']) ? (string)$row['tab_key'] : '';
            if ($key !== '') $rowMap[$key] = $row;
        }
        $knownTabs = self::knownTabs($menuKey);
        foreach ($rowMap as $key => $row) {
            if (!isset($knownTabs[$key])) $knownTabs[$key] = isset($row['tab_name']) ? (string)$row['tab_name'] : $key;
        }
        $total = 0;
        foreach ($knownTabs as $key => $label) $total += isset($rowMap[$key]['usage_count']) ? (int)$rowMap[$key]['usage_count'] : 0;
        $stats = array();
        foreach ($knownTabs as $key => $label) {
            $count = isset($rowMap[$key]['usage_count']) ? (int)$rowMap[$key]['usage_count'] : 0;
            $stats[] = array(
                'tab_key' => $key,
                'tab_name' => $label,
                'usage_count' => $count,
                'user_count' => isset($rowMap[$key]['user_count']) ? (int)$rowMap[$key]['user_count'] : 0,
                'last_used_at' => isset($rowMap[$key]['last_used_at']) ? (string)$rowMap[$key]['last_used_at'] : '',
                'ratio' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            );
        }
        usort($stats, function ($a, $b) {
            if ((int)$a['usage_count'] === (int)$b['usage_count']) return strcmp((string)$a['tab_name'], (string)$b['tab_name']);
            return (int)$a['usage_count'] > (int)$b['usage_count'] ? -1 : 1;
        });
        return $stats;
    }

    private static function buildTrend($pdo, $filters)
    {
        $sessionWhere = array('started_at >= :trend_session_start', 'started_at < :trend_session_end');
        $sessionParams = array(':trend_session_start' => $filters['start_at'], ':trend_session_end' => $filters['end_at']);
        self::appendPeopleFilters($sessionWhere, $sessionParams, $filters, array(
            'name' => 'employee_name', 'department' => 'department', 'position' => 'position'
        ), 'trend_session_people');
        $sessionSql = "SELECT DATE(started_at) AS usage_date,
                              COUNT(DISTINCT LOWER(TRIM(email))) AS user_count,
                              COUNT(*) AS connection_count
                       FROM " . self::SESSION_TABLE . "
                       WHERE " . implode(' AND ', $sessionWhere) . "
                       GROUP BY DATE(started_at)";
        $sessionRows = self::fetchAll($pdo, $sessionSql, $sessionParams);

        $eventWhere = array('event_at >= :trend_event_start', 'event_at < :trend_event_end', "event_type <> 'session_start'");
        $eventParams = array(':trend_event_start' => $filters['start_at'], ':trend_event_end' => $filters['end_at']);
        if ($filters['menu'] !== '') {
            $eventWhere[] = 'menu_key = :trend_menu';
            $eventParams[':trend_menu'] = $filters['menu'];
        }
        if ($filters['tab'] !== '') {
            $eventWhere[] = 'tab_key = :trend_tab';
            $eventParams[':trend_tab'] = $filters['tab'];
        }
        self::appendPeopleFilters($eventWhere, $eventParams, $filters, array(
            'name' => 'employee_name', 'department' => 'department', 'position' => 'position'
        ), 'trend_event_people');
        $eventSql = "SELECT DATE(event_at) AS usage_date, COUNT(*) AS activity_count
                     FROM " . self::EVENT_TABLE . "
                     WHERE " . implode(' AND ', $eventWhere) . "
                     GROUP BY DATE(event_at)";
        $eventRows = self::fetchAll($pdo, $eventSql, $eventParams);

        $map = array();
        foreach ($sessionRows as $row) {
            $date = (string)$row['usage_date'];
            $map[$date] = array(
                'date' => $date,
                'users' => (int)$row['user_count'],
                'connections' => (int)$row['connection_count'],
                'activities' => 0,
            );
        }
        foreach ($eventRows as $row) {
            $date = (string)$row['usage_date'];
            if (!isset($map[$date])) $map[$date] = array('date' => $date, 'users' => 0, 'connections' => 0, 'activities' => 0);
            $map[$date]['activities'] = (int)$row['activity_count'];
        }

        $result = array();
        $cursor = new \DateTime($filters['date_from'] . ' 00:00:00', new \DateTimeZone('Asia/Seoul'));
        $end = new \DateTime($filters['date_to'] . ' 00:00:00', new \DateTimeZone('Asia/Seoul'));
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $result[] = isset($map[$date]) ? $map[$date] : array('date' => $date, 'users' => 0, 'connections' => 0, 'activities' => 0);
            $cursor->modify('+1 day');
        }
        return $result;
    }

    private static function buildDepartmentStats($employeeRows)
    {
        $map = array();
        foreach ($employeeRows as $row) {
            $department = trim((string)$row['department']);
            if ($department === '') $department = '미지정';
            if (!isset($map[$department])) {
                $map[$department] = array(
                    'department' => $department,
                    'employee_count' => 0,
                    'active_user_count' => 0,
                    'connection_count' => 0,
                    'activity_count' => 0,
                );
            }
            $map[$department]['employee_count']++;
            if ((int)$row['period_connections'] > 0 || (int)$row['period_activities'] > 0) $map[$department]['active_user_count']++;
            $map[$department]['connection_count'] += (int)$row['period_connections'];
            $map[$department]['activity_count'] += (int)$row['period_activities'];
        }
        $rows = array_values($map);
        usort($rows, function ($a, $b) {
            if ((int)$a['activity_count'] === (int)$b['activity_count']) return strcmp((string)$a['department'], (string)$b['department']);
            return (int)$a['activity_count'] > (int)$b['activity_count'] ? -1 : 1;
        });
        return $rows;
    }

    public static function dashboard($pdo, $filters)
    {
        if (!$pdo || !self::isInstalled($pdo)) return array();

        $employeePositionSelect = self::columnExists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
        $employeeRoleSelect = self::columnExists($pdo, 'employees', 'role') ? 'role' : "'employee' AS role";
        $employeesSql = "SELECT id, email, name, department, " . $employeePositionSelect . ", " . $employeeRoleSelect . "
                         FROM employees
                         WHERE is_active = 1
                         ORDER BY name ASC
                         LIMIT 2000";
        $employees = self::fetchAll($pdo, $employeesSql, array());
        $includedEmployees = array();
        foreach ($employees as $employee) {
            $employeeName = isset($employee['name']) ? $employee['name'] : '';
            if (!self::isExcludedEmployeeName($employeeName)) $includedEmployees[] = $employee;
        }
        $employees = $includedEmployees;
        $now = self::nowDateTime();
        $todayStart = $now->format('Y-m-d') . ' 00:00:00';
        $todayEndObject = new \DateTime($todayStart, new \DateTimeZone('Asia/Seoul'));
        $todayEndObject->modify('+1 day');
        $todayEnd = $todayEndObject->format('Y-m-d H:i:s');
        $monthStart = $now->format('Y-m-01') . ' 00:00:00';
        $historyStartObject = clone $now;
        $historyStartObject->modify('-10 years');
        $onlineCutoffObject = clone $now;
        $onlineCutoffObject->modify('-' . self::ONLINE_SECONDS . ' seconds');

        $emptyFilters = $filters;
        $emptyFilters['q'] = '';
        $emptyFilters['department'] = '';
        $emptyFilters['position'] = '';
        $emptyFilters['menu'] = '';
        $emptyFilters['tab'] = '';

        $periodSessions = self::rowsByEmail(self::sessionAggregate($pdo, $filters['start_at'], $filters['end_at'], $emptyFilters, false));
        $periodEvents = self::rowsByEmail(self::eventAggregate($pdo, $filters['start_at'], $filters['end_at'], $filters, true, false));
        $todaySessions = self::rowsByEmail(self::sessionAggregate($pdo, $todayStart, $todayEnd, $emptyFilters, false));
        $todayEvents = self::rowsByEmail(self::eventAggregate($pdo, $todayStart, $todayEnd, $emptyFilters, false, false));
        $monthSessions = self::rowsByEmail(self::sessionAggregate($pdo, $monthStart, $todayEnd, $emptyFilters, false));
        $lastSessions = self::rowsByEmail(self::sessionAggregate($pdo, $historyStartObject->format('Y-m-d H:i:s'), $todayEnd, $emptyFilters, false));
        $onlineSessions = self::rowsByEmail(self::onlineAggregate($pdo, $onlineCutoffObject->format('Y-m-d H:i:s'), $todayEnd));

        $allEmployeeRows = array();
        $todayConnected = array();
        $todayNotConnected = array();
        $accountUnregistered = array();
        $summary = array(
            'today_connected_users' => 0,
            'today_not_connected_users' => 0,
            'currently_online_users' => 0,
            'today_connection_count' => 0,
            'today_activity_count' => 0,
            'month_active_users' => 0,
        );

        foreach ($employees as $employee) {
            $email = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
            $employee['department'] = Auth::normalizeDepartmentValue(isset($employee['department']) ? $employee['department'] : '');
            $lastActivity = $email !== '' && isset($lastSessions[$email]['last_activity_at']) ? (string)$lastSessions[$email]['last_activity_at'] : '';
            $todayConnections = $email !== '' && isset($todaySessions[$email]['connection_count']) ? (int)$todaySessions[$email]['connection_count'] : 0;
            $periodConnections = $email !== '' && isset($periodSessions[$email]['connection_count']) ? (int)$periodSessions[$email]['connection_count'] : 0;
            $periodActivities = $email !== '' && isset($periodEvents[$email]['activity_count']) ? (int)$periodEvents[$email]['activity_count'] : 0;
            $menuCount = $email !== '' && isset($periodEvents[$email]['menu_count']) ? (int)$periodEvents[$email]['menu_count'] : 0;
            $todayActivities = $email !== '' && isset($todayEvents[$email]['activity_count']) ? (int)$todayEvents[$email]['activity_count'] : 0;
            $isOnline = $email !== '' && isset($onlineSessions[$email]);
            $daysSinceLast = null;
            if ($lastActivity !== '') {
                try {
                    $lastObject = new \DateTime($lastActivity, new \DateTimeZone('Asia/Seoul'));
                    $daysSinceLast = (int)$lastObject->diff($now)->days;
                } catch (\Exception $e) {
                    $daysSinceLast = null;
                }
            }

            $row = array(
                'id' => isset($employee['id']) ? (int)$employee['id'] : 0,
                'email' => $email,
                'name' => isset($employee['name']) ? (string)$employee['name'] : '',
                'department' => isset($employee['department']) ? (string)$employee['department'] : '',
                'position' => isset($employee['position']) ? (string)$employee['position'] : '',
                'role' => isset($employee['role']) ? (string)$employee['role'] : '',
                'today_connections' => $todayConnections,
                'period_connections' => $periodConnections,
                'period_activities' => $periodActivities,
                'today_activities' => $todayActivities,
                'menu_count' => $menuCount,
                'last_activity_at' => $lastActivity,
                'days_since_last' => $daysSinceLast,
                'is_online' => $isOnline,
                'is_connected_period' => $periodConnections > 0,
            );
            $allEmployeeRows[] = $row;

            if ($email === '') {
                $accountUnregistered[] = $row;
                continue;
            }
            if ($todayConnections > 0) $todayConnected[] = $row;
            else $todayNotConnected[] = $row;
            if ($isOnline) $summary['currently_online_users']++;
            if (isset($monthSessions[$email])) $summary['month_active_users']++;
            $summary['today_connection_count'] += $todayConnections;
            $summary['today_activity_count'] += $todayActivities;
        }
        $summary['today_connected_users'] = count($todayConnected);
        $summary['today_not_connected_users'] = count($todayNotConnected);

        $employeeRows = array();
        foreach ($allEmployeeRows as $row) {
            if ($filters['q'] !== '' && !self::containsText($row['name'], $filters['q'])) continue;
            if ($filters['department'] !== '' && (string)$row['department'] !== $filters['department']) continue;
            if ($filters['position'] !== '' && (string)$row['position'] !== $filters['position']) continue;
            if ($filters['online'] === 'yes' && !$row['is_online']) continue;
            if ($filters['online'] === 'no' && $row['is_online']) continue;
            if ($filters['connected'] === 'yes' && !$row['is_connected_period']) continue;
            if ($filters['connected'] === 'no' && $row['is_connected_period']) continue;
            $employeeRows[] = $row;
        }
        self::sortEmployeeRows($employeeRows, $filters['sort']);
        if (count($employeeRows) > 500) $employeeRows = array_slice($employeeRows, 0, 500);

        $mostConnections = null;
        $mostActivities = null;
        foreach ($allEmployeeRows as $row) {
            if ($row['email'] === '') continue;
            if ($mostConnections === null || (int)$row['period_connections'] > (int)$mostConnections['period_connections']) $mostConnections = $row;
            if ($mostActivities === null || (int)$row['period_activities'] > (int)$mostActivities['period_activities']) $mostActivities = $row;
        }

        $departments = array();
        $positions = array();
        foreach ($employees as $employee) {
            $department = Auth::normalizeDepartmentValue(isset($employee['department']) ? $employee['department'] : '');
            $position = isset($employee['position']) ? trim((string)$employee['position']) : '';
            if ($department !== '') $departments[$department] = $department;
            if ($position !== '') $positions[$position] = $position;
        }
        natcasesort($departments);
        natcasesort($positions);

        $menuStats = self::buildMenuStats($pdo, $filters);
        $selectedMenu = $filters['menu'];
        if ($selectedMenu === '') {
            foreach ($menuStats as $menuRow) {
                if ((int)$menuRow['usage_count'] > 0) {
                    $selectedMenu = (string)$menuRow['menu_key'];
                    break;
                }
            }
        }
        if ($selectedMenu === '') $selectedMenu = 'dashboard';
        $tabStats = self::buildTabStats($pdo, $filters, $selectedMenu);

        return array(
            'summary' => $summary,
            'employee_rows' => $employeeRows,
            'today_connected' => $todayConnected,
            'today_not_connected' => $todayNotConnected,
            'account_unregistered' => $accountUnregistered,
            'most_connections' => $mostConnections,
            'most_activities' => $mostActivities,
            'menu_stats' => $menuStats,
            'selected_menu' => $selectedMenu,
            'tab_stats' => $tabStats,
            'trend' => self::buildTrend($pdo, $filters),
            'department_stats' => self::buildDepartmentStats($allEmployeeRows),
            'departments' => array_values($departments),
            'positions' => array_values($positions),
            'known_menus' => self::knownMenus(),
            'known_tabs' => self::knownTabs($filters['menu']),
        );
    }

    private static function employeeIdentityWhere($employeeId, $email, $idKey, $emailKey, &$params)
    {
        $params[$idKey] = (int)$employeeId;
        $params[$emailKey] = strtolower(trim((string)$email));
        return '(employee_id = ' . $idKey . ' OR LOWER(TRIM(email)) = ' . $emailKey . ')';
    }

    public static function userDetail($pdo, $employeeId, $filters, $input)
    {
        $employeeId = (int)$employeeId;
        if (!$pdo || $employeeId <= 0 || !self::isInstalled($pdo)) return null;

        $detailPositionSelect = self::columnExists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
        $detailRoleSelect = self::columnExists($pdo, 'employees', 'role') ? 'role' : "'employee' AS role";
        $employeeStatement = self::execute($pdo,
            'SELECT id, email, name, department, ' . $detailPositionSelect . ', ' . $detailRoleSelect . ', is_active FROM employees WHERE id = :employee_id LIMIT 1',
            array(':employee_id' => $employeeId)
        );
        $employee = $employeeStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employee)) return null;
        if (self::isExcludedEmployeeName(isset($employee['name']) ? $employee['name'] : '')) return null;
        $employee['department'] = Auth::normalizeDepartmentValue(isset($employee['department']) ? $employee['department'] : '');

        $email = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
        $now = self::nowDateTime();
        $todayStart = $now->format('Y-m-d') . ' 00:00:00';
        $todayEndObject = new \DateTime($todayStart, new \DateTimeZone('Asia/Seoul'));
        $todayEndObject->modify('+1 day');
        $todayEnd = $todayEndObject->format('Y-m-d H:i:s');
        $historyStartObject = clone $now;
        $historyStartObject->modify('-10 years');
        $onlineCutoffObject = clone $now;
        $onlineCutoffObject->modify('-' . self::ONLINE_SECONDS . ' seconds');

        $sessionParams = array(':detail_session_start' => $todayStart, ':detail_session_end' => $todayEnd);
        $sessionIdentity = self::employeeIdentityWhere($employeeId, $email, ':detail_session_id', ':detail_session_email', $sessionParams);
        $sessionSql = "SELECT COUNT(*) FROM " . self::SESSION_TABLE . "
                       WHERE started_at >= :detail_session_start
                         AND started_at < :detail_session_end
                         AND " . $sessionIdentity;
        $todayConnections = (int)self::execute($pdo, $sessionSql, $sessionParams)->fetchColumn();

        $lastParams = array(':detail_history_start' => $historyStartObject->format('Y-m-d H:i:s'));
        $lastIdentity = self::employeeIdentityWhere($employeeId, $email, ':detail_history_id', ':detail_history_email', $lastParams);
        $lastSql = "SELECT MAX(last_activity_at) FROM " . self::SESSION_TABLE . "
                    WHERE started_at >= :detail_history_start AND " . $lastIdentity;
        $lastActivityAt = (string)self::execute($pdo, $lastSql, $lastParams)->fetchColumn();

        $onlineParams = array(':detail_online_cutoff' => $onlineCutoffObject->format('Y-m-d H:i:s'));
        $onlineIdentity = self::employeeIdentityWhere($employeeId, $email, ':detail_online_id', ':detail_online_email', $onlineParams);
        $onlineSql = "SELECT COUNT(*) FROM " . self::SESSION_TABLE . "
                      WHERE last_activity_at >= :detail_online_cutoff AND " . $onlineIdentity;
        $isOnline = (int)self::execute($pdo, $onlineSql, $onlineParams)->fetchColumn() > 0;

        $menuParams = array(':detail_menu_start' => $filters['start_at'], ':detail_menu_end' => $filters['end_at']);
        $menuIdentity = self::employeeIdentityWhere($employeeId, $email, ':detail_menu_id', ':detail_menu_email', $menuParams);
        $menuSql = "SELECT menu_key, MAX(menu_name) AS menu_name, COUNT(*) AS usage_count
                    FROM " . self::EVENT_TABLE . "
                    WHERE event_at >= :detail_menu_start
                      AND event_at < :detail_menu_end
                      AND event_type <> 'session_start'
                      AND menu_key <> ''
                      AND " . $menuIdentity . "
                    GROUP BY menu_key
                    ORDER BY usage_count DESC, menu_name ASC
                    LIMIT 10";
        $frequentMenus = self::fetchAll($pdo, $menuSql, $menuParams);

        $tabParams = array(':detail_tab_start' => $filters['start_at'], ':detail_tab_end' => $filters['end_at']);
        $tabIdentity = self::employeeIdentityWhere($employeeId, $email, ':detail_tab_id', ':detail_tab_email', $tabParams);
        $tabSql = "SELECT menu_key, tab_key, MAX(tab_name) AS tab_name, MAX(menu_name) AS menu_name, COUNT(*) AS usage_count
                   FROM " . self::EVENT_TABLE . "
                   WHERE event_at >= :detail_tab_start
                     AND event_at < :detail_tab_end
                     AND event_type <> 'session_start'
                     AND tab_key <> ''
                     AND " . $tabIdentity . "
                   GROUP BY menu_key, tab_key
                   ORDER BY usage_count DESC, tab_name ASC
                   LIMIT 10";
        $frequentTabs = self::fetchAll($pdo, $tabSql, $tabParams);

        $page = is_array($input) && isset($input['detail_page']) ? (int)$input['detail_page'] : 1;
        if ($page < 1) $page = 1;
        if ($page > 10000) $page = 10000;
        $perPage = 25;
        $eventWhere = array('event_at >= :detail_event_start', 'event_at < :detail_event_end');
        $eventParams = array(':detail_event_start' => $filters['start_at'], ':detail_event_end' => $filters['end_at']);
        $eventWhere[] = self::employeeIdentityWhere($employeeId, $email, ':detail_event_id', ':detail_event_email', $eventParams);
        if ($filters['menu'] !== '') {
            $eventWhere[] = 'menu_key = :detail_event_menu';
            $eventParams[':detail_event_menu'] = $filters['menu'];
        }
        if ($filters['tab'] !== '') {
            $eventWhere[] = 'tab_key = :detail_event_tab';
            $eventParams[':detail_event_tab'] = $filters['tab'];
        }

        $countSql = "SELECT COUNT(*) FROM " . self::EVENT_TABLE . " WHERE " . implode(' AND ', $eventWhere);
        $totalEvents = (int)self::execute($pdo, $countSql, $eventParams)->fetchColumn();
        $totalPages = max(1, (int)ceil($totalEvents / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        $eventSql = "SELECT id, event_type, menu_name, tab_name, action_name, route_name, event_at
                     FROM " . self::EVENT_TABLE . "
                     WHERE " . implode(' AND ', $eventWhere) . "
                     ORDER BY event_at DESC, id DESC
                     LIMIT :detail_limit OFFSET :detail_offset";
        $eventStatement = $pdo->prepare($eventSql);
        foreach ($eventParams as $key => $value) $eventStatement->bindValue($key, $value);
        $eventStatement->bindValue(':detail_limit', $perPage, PDO::PARAM_INT);
        $eventStatement->bindValue(':detail_offset', $offset, PDO::PARAM_INT);
        $eventStatement->execute();
        $events = $eventStatement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($events)) $events = array();

        return array(
            'employee' => $employee,
            'today_connections' => $todayConnections,
            'last_activity_at' => $lastActivityAt,
            'is_online' => $isOnline,
            'frequent_menus' => $frequentMenus,
            'frequent_tabs' => $frequentTabs,
            'events' => $events,
            'page' => $page,
            'per_page' => $perPage,
            'total_events' => $totalEvents,
            'total_pages' => $totalPages,
        );
    }

    public static function exportEvents($pdo, $filters)
    {
        if (!$pdo || !self::isInstalled($pdo)) return array();
        $where = array('event_at >= :export_start', 'event_at < :export_end');
        $params = array(':export_start' => $filters['start_at'], ':export_end' => $filters['end_at']);
        if ($filters['menu'] !== '') {
            $where[] = 'menu_key = :export_menu';
            $params[':export_menu'] = $filters['menu'];
        }
        if ($filters['tab'] !== '') {
            $where[] = 'tab_key = :export_tab';
            $params[':export_tab'] = $filters['tab'];
        }
        self::appendPeopleFilters($where, $params, $filters, array(
            'name' => 'employee_name', 'department' => 'department', 'position' => 'position'
        ), 'export_people');
        $sql = "SELECT event_at, employee_name, email, department, position,
                       event_type, menu_name, tab_name, action_name, route_name
                FROM " . self::EVENT_TABLE . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY event_at DESC, id DESC
                LIMIT 10000";
        return self::fetchAll($pdo, $sql, $params);
    }

    public static function cleanupOldEvents($pdo, $days)
    {
        $days = (int)$days;
        if ($days < self::DETAIL_RETENTION_DAYS) $days = self::DETAIL_RETENTION_DAYS;
        $result = array('ok' => false, 'deleted_count' => 0, 'cutoff' => '', 'message' => '');
        if (!$pdo || !self::isInstalled($pdo)) {
            $result['message'] = '사용기록 테이블이 설치되어 있지 않습니다.';
            return $result;
        }

        try {
            $cutoff = self::nowDateTime();
            $cutoff->modify('-' . $days . ' days');
            $cutoffText = $cutoff->format('Y-m-d H:i:s');
            $statement = self::execute($pdo,
                'DELETE FROM ' . self::EVENT_TABLE . ' WHERE event_at < :cutoff',
                array(':cutoff' => $cutoffText)
            );
            $deletedCount = (int)$statement->rowCount();
            $result['ok'] = true;
            $result['deleted_count'] = $deletedCount;
            $result['cutoff'] = $cutoffText;
            $result['message'] = $cutoffText . ' 이전 상세 활동기록 ' . number_format($deletedCount) . '건을 정리했습니다.';
        } catch (\Exception $e) {
            self::logError('cleanup old events', $e);
            $result['message'] = '상세 로그 정리에 실패했습니다. 오류 내용은 서버 로그에 기록되었습니다.';
        }
        return $result;
    }

    public static function retentionCutoffText()
    {
        $cutoff = self::nowDateTime();
        $cutoff->modify('-' . self::DETAIL_RETENTION_DAYS . ' days');
        return $cutoff->format('Y-m-d H:i:s');
    }
}
