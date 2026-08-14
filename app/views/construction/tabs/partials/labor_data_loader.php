<?php
/**
 * 공사 > 노무비 > 공수/비용배분/인원작성 공통 데이터 로더
 * - attendance/admin_gongsu 데이터 연동
 * - PHP 5.6 호환
 */

if (!function_exists('cpms_normalize_worker_key')) {
    function cpms_normalize_worker_key($name) {
        $name = trim((string)$name);
        if ($name === '') return '';
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($name, 'UTF-8');
        }
        return strtolower($name);
    }
}

if (!function_exists('cpms_labor_load_workforce_services')) {
    function cpms_labor_load_workforce_services() {
        $path = __DIR__ . '/../../../../services/WorkerRepository.php';
        if (is_file($path)) {
            require_once $path;
        }
        return class_exists('WorkerRepository');
    }
}

if (!function_exists('cpms_labor_cache_key')) {
    function cpms_labor_cache_key($pdo, $suffix) {
        $prefix = 'nopdo';
        if ($pdo && function_exists('spl_object_hash')) {
            $prefix = spl_object_hash($pdo);
        }
        return $prefix . ':' . (string)$suffix;
    }
}

if (!function_exists('cpms_normalize_site_key')) {
    function cpms_normalize_site_key($name) {
        $name = trim((string)$name);
        if ($name === '') return '';

        $name = str_ireplace(
            array('retrofit', 'green', 'samsung'),
            array('리트로핏', '그린', '삼성'),
            $name
        );

        if (function_exists('mb_strtolower')) {
            $name = mb_strtolower($name, 'UTF-8');
        } else {
            $name = strtolower($name);
        }

        $normalized = @preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
        if ($normalized === null) {
            $normalized = str_replace(array(' ', "\r", "\n", "\t", '-', '_', '.', ',', '(', ')', '[', ']', '&', '/'), '', $name);
        }
        $normalized = str_replace(array('공사', '현장', '프로젝트'), '', $normalized);
        return trim((string)$normalized);
    }
}

if (!function_exists('cpms_site_match_score')) {
    function cpms_site_match_score($projectName, $siteName) {
        $projectKey = cpms_normalize_site_key($projectName);
        $siteKey = cpms_normalize_site_key($siteName);
        if ($projectKey === '' || $siteKey === '') return 0.0;
        if ($projectKey === $siteKey) return 100.0;

        $projectLen = function_exists('mb_strlen') ? mb_strlen($projectKey, 'UTF-8') : strlen($projectKey);
        $siteLen = function_exists('mb_strlen') ? mb_strlen($siteKey, 'UTF-8') : strlen($siteKey);
        if ($projectLen <= 0 || $siteLen <= 0) return 0.0;
        $minLen = min($projectLen, $siteLen);
        $maxLen = max($projectLen, $siteLen);

        $contains = false;
        if (function_exists('mb_strpos')) {
            $contains = (mb_strpos($projectKey, $siteKey, 0, 'UTF-8') !== false || mb_strpos($siteKey, $projectKey, 0, 'UTF-8') !== false);
        } else {
            $contains = (strpos($projectKey, $siteKey) !== false || strpos($siteKey, $projectKey) !== false);
        }
        if ($contains && $minLen >= 4) {
            $ratio = ($maxLen > 0) ? ($minLen / $maxLen) : 0;
            if ($ratio >= 0.55) return 92.0 + ($ratio * 7.0);
            return 82.0 + ($ratio * 8.0);
        }

        $percent = 0.0;
        similar_text($projectKey, $siteKey, $percent);
        return (float)$percent;
    }
}

if (!function_exists('cpms_pick_best_site_match')) {
    function cpms_pick_best_site_match($rows, $projectName, $nameField, $idField) {
        $best = array('id' => 0, 'name' => '', 'score' => 0.0);
        if (!is_array($rows) || count($rows) === 0) return $best;

        foreach ($rows as $row) {
            $siteName = isset($row[$nameField]) ? trim((string)$row[$nameField]) : '';
            if ($siteName === '') continue;
            $score = cpms_site_match_score($projectName, $siteName);
            if ($score > (float)$best['score']) {
                $best = array(
                    'id' => ($idField !== '' && isset($row[$idField])) ? (int)$row[$idField] : 0,
                    'name' => $siteName,
                    'score' => $score,
                );
            }
        }

        if ((float)$best['score'] < 82.0) {
            return array('id' => 0, 'name' => '', 'score' => (float)$best['score']);
        }
        return $best;
    }
}

if (!function_exists('cpms_table_exists_labor')) {
    function cpms_table_exists_labor($pdo, $table) {
        static $cache = array();
        static $dbNameCache = array();
        if (!$pdo || trim((string)$table) === '') return false;
        $cacheKey = cpms_labor_cache_key($pdo, 'table:' . (string)$table);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            $pdoKey = cpms_labor_cache_key($pdo, 'db');
            if (!isset($dbNameCache[$pdoKey])) {
                $dbNameCache[$pdoKey] = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            }
            $dbName = (string)$dbNameCache[$pdoKey];
            if ($dbName === '') return false;
            $sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl";
            $st = $pdo->prepare($sql);
            $st->bindValue(':db', $dbName);
            $st->bindValue(':tbl', $table);
            $st->execute();
            $cache[$cacheKey] = ((int)$st->fetchColumn() > 0);
            return $cache[$cacheKey];
        } catch (Exception $e) {
            $cache[$cacheKey] = false;
            return false;
        }
    }
}

if (!function_exists('cpms_table_columns')) {
    function cpms_table_columns($pdo, $table) {
        static $cache = array();
        if (!$pdo || trim((string)$table) === '') return array();
        $cacheKey = cpms_labor_cache_key($pdo, 'columns:' . (string)$table);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$table`");
            $st->execute();
            $rows = $st->fetchAll();
            $cols = array();
            foreach ($rows as $row) {
                if (isset($row['Field'])) {
                    $cols[] = (string)$row['Field'];
                }
            }
            $cache[$cacheKey] = $cols;
            return $cols;
        } catch (Exception $e) {
            $cache[$cacheKey] = array();
            return array();
        }
    }
}

if (!function_exists('cpms_find_attendance_site_match')) {
    function cpms_find_attendance_site_match($pdo, $projectName) {
        $empty = array('id' => 0, 'name' => '', 'score' => 0.0);
        if (!$pdo || trim((string)$projectName) === '') return $empty;
        static $cache = array();
        $cacheKey = cpms_labor_cache_key($pdo, 'attendance-site:' . trim((string)$projectName));
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

        try {
            $st = $pdo->prepare("SELECT id, name FROM sites WHERE name = :name AND active = 1 ORDER BY id DESC LIMIT 1");
            $st->bindValue(':name', $projectName);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && isset($row['id'])) {
                $cache[$cacheKey] = array('id' => (int)$row['id'], 'name' => isset($row['name']) ? (string)$row['name'] : $projectName, 'score' => 100.0);
                return $cache[$cacheKey];
            }
        } catch (Exception $e) {
        }

        try {
            $stAll = $pdo->prepare("SELECT id, name FROM sites WHERE active = 1 ORDER BY id DESC");
            $stAll->execute();
            $rows = $stAll->fetchAll(PDO::FETCH_ASSOC);
            $cache[$cacheKey] = cpms_pick_best_site_match($rows, $projectName, 'name', 'id');
            return $cache[$cacheKey];
        } catch (Exception $e) {
            $cache[$cacheKey] = $empty;
            return $empty;
        }
    }
}

if (!function_exists('cpms_resolve_gongsu_site_name')) {
    function cpms_resolve_gongsu_site_name($pdo, $table, $siteColumn, $projectName) {
        $projectName = trim((string)$projectName);
        if (!$pdo || $table === '' || $siteColumn === '' || $projectName === '') return $projectName;
        static $cache = array();
        $cacheKey = cpms_labor_cache_key($pdo, 'gongsu-site:' . $table . ':' . $siteColumn . ':' . $projectName);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

        try {
            $sql = "SELECT `" . $siteColumn . "` AS site_name FROM `" . $table . "` WHERE `" . $siteColumn . "` = :site LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->bindValue(':site', $projectName);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && isset($row['site_name']) && trim((string)$row['site_name']) !== '') {
                $cache[$cacheKey] = trim((string)$row['site_name']);
                return $cache[$cacheKey];
            }
        } catch (Exception $e) {
        }

        try {
            $sqlAll = "SELECT DISTINCT `" . $siteColumn . "` AS site_name FROM `" . $table . "` WHERE `" . $siteColumn . "` IS NOT NULL AND `" . $siteColumn . "` <> '' LIMIT 500";
            $stAll = $pdo->prepare($sqlAll);
            $stAll->execute();
            $rows = $stAll->fetchAll(PDO::FETCH_ASSOC);
            $best = cpms_pick_best_site_match($rows, $projectName, 'site_name', '');
            if (isset($best['name']) && trim((string)$best['name']) !== '') {
                $cache[$cacheKey] = trim((string)$best['name']);
                return $cache[$cacheKey];
            }
        } catch (Exception $e) {
        }

        $cache[$cacheKey] = $projectName;
        return $projectName;
    }
}

if (!function_exists('cpms_create_pdo_from_array')) {
    /**
     * attendance(DB) 연결용 PDO 생성 (배열 설정 기반)
     * - PHP 5.6 호환
     * - 주의: CPMS 자체 DB가 아니라 "근로자 시프티(attendance)" DB에만 사용
     */
    function cpms_create_pdo_from_array($cfg) {
        if (!is_array($cfg)) return null;

        $host = isset($cfg['host']) ? (string)$cfg['host'] : '127.0.0.1';
        $port = isset($cfg['port']) ? (int)$cfg['port'] : 3306;
        $db   = isset($cfg['dbname']) ? (string)$cfg['dbname'] : '';
        $user = isset($cfg['user']) ? (string)$cfg['user'] : '';
        $pass = isset($cfg['pass']) ? (string)$cfg['pass'] : '';
        $ch   = isset($cfg['charset']) ? (string)$cfg['charset'] : 'utf8';

        if ($db === '' || $user === '') return null;

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=' . $ch;
        try {
            return new PDO($dsn, $user, $pass, array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('cpms_parse_attendance_db_php')) {
    /**
     * attendance/db.php 파일을 "실행하지 않고" DB 접속정보만 읽어옵니다.
     * - 이유: attendance/db.php 는 실행 시 mysqli 연결/exit 등을 할 수 있어 CPMS 화면을 깨뜨릴 수 있음
     */
    function cpms_parse_attendance_db_php($dbPhpPath) {
        if ($dbPhpPath === '' || !file_exists($dbPhpPath)) return null;

        $txt = @file_get_contents($dbPhpPath);
        if ($txt === false || $txt === '') return null;

        $cfg = array();

        // $DB_HOST = 'localhost';
        // $DB_USER = 'user';
        // $DB_PASS = 'pass';
        // $DB_NAME = 'dbname';
        $patterns = array(
            'host'  => '/\\$DB_HOST\\s*=\\s*[\\\'\\"]([^\\\'\\"]*)[\\\'\\"]\\s*;/',
            'user'  => '/\\$DB_USER\\s*=\\s*[\\\'\\"]([^\\\'\\"]*)[\\\'\\"]\\s*;/',
            'pass'  => '/\\$DB_PASS\\s*=\\s*[\\\'\\"]([^\\\'\\"]*)[\\\'\\"]\\s*;/',
            'dbname'=> '/\\$DB_NAME\\s*=\\s*[\\\'\\"]([^\\\'\\"]*)[\\\'\\"]\\s*;/',
            'port'  => '/\\$DB_PORT\\s*=\\s*([0-9]+)\\s*;/',
        );

        foreach ($patterns as $k => $re) {
            if (preg_match($re, $txt, $m)) {
                $cfg[$k] = $m[1];
            }
        }

        // 기본값
        if (!isset($cfg['port']) || (int)$cfg['port'] <= 0) $cfg['port'] = 3306;
        if (!isset($cfg['charset']) || $cfg['charset'] === '') $cfg['charset'] = 'utf8';

        // 필수값 체크
        if (!isset($cfg['host']) || !isset($cfg['user']) || !isset($cfg['pass']) || !isset($cfg['dbname'])) {
            return null;
        }
        if (trim((string)$cfg['dbname']) === '' || trim((string)$cfg['user']) === '') return null;

        return $cfg;
    }
}

if (!function_exists('cpms_create_pdo_from_config')) {
    function cpms_create_pdo_from_config($cfgFile) {
        if ($cfgFile === '' || !file_exists($cfgFile)) return null;

        // 설정 파일은 "배열을 return" 하는 형태만 지원합니다.
        // (attendance/db.php 같은 실행형 파일은 cpms_parse_attendance_db_php로 별도 처리)
        $cfg = require $cfgFile;
        if (!is_array($cfg)) return null;

        return cpms_create_pdo_from_array($cfg);
    }
}

if (!function_exists('cpms_load_attendance_pdo')) {
    function cpms_load_attendance_pdo() {
        static $attendancePdo = false;
        static $resolved = false;
        if ($resolved) return ($attendancePdo instanceof PDO) ? $attendancePdo : null;
        $resolved = true;

        // CPMS 기준으로 attendance 경로를 추정 (같은 서버/상위폴더에 있다고 가정)
        $roots = array();
        $cpmsRoot = realpath(__DIR__ . '/../../../../..'); // .../cpms
        if ($cpmsRoot) {
            $baseRoot = dirname($cpmsRoot); // .../www
            $roots[] = $baseRoot . '/attendance';
        }
        // 흔한 고정 경로도 추가
        $roots[] = '/www/attendance';

        // 1) attendance 기본 파일(db.php)을 "파싱"해서 접속정보 추출 (실행 금지)
        foreach ($roots as $root) {
            $dbPhp = rtrim($root, '/') . '/db.php';
            if (!file_exists($dbPhp)) continue;

            $cfg = cpms_parse_attendance_db_php($dbPhp);
            if (is_array($cfg)) {
                $attendancePdo = cpms_create_pdo_from_array($cfg);
                if ($attendancePdo instanceof PDO) return $attendancePdo;
            }
        }

        // 2) (선택) 배열을 return 하는 설정 파일이 있다면 그것도 지원
        $configFiles = array(
            'app/config/database.php',
            'app/config/db.php',
            'config/database.php',
        );

        foreach ($roots as $root) {
            foreach ($configFiles as $rel) {
                $cfgFile = rtrim($root, '/') . '/' . $rel;
                if (!file_exists($cfgFile)) continue;
                $attendancePdo = cpms_create_pdo_from_config($cfgFile);
                if ($attendancePdo instanceof PDO) return $attendancePdo;
            }
        }

        return null;
    }
}

if (!function_exists('cpms_map_gongsu_columns')) {
    function cpms_map_gongsu_columns($columns) {
        $colMap = array();
        $lower = array();
        foreach ($columns as $col) {
            $lower[strtolower($col)] = $col;
        }

        $aliases = array(
            'site' => array('site_name', 'site', 'project_name', 'project', 'site_nm', 'project_nm', 'site_title'),
            'name' => array('name', 'worker_name', 'employee_name', 'worker', 'person_name', 'member_name'),
            'date' => array('work_date', 'attendance_date', 'date', 'workday', 'gongsu_date', 'workday_date'),
            'gongsu' => array('total_gongsu', 'gongsu', 'man_days', 'total_man_days', 'man_day', 'work_days', 'work_day'),
            'printed' => array('printed', 'print_yn', 'printed_yn', 'output_yn', 'is_printed', 'print_flag'),
            'role' => array('role', 'job', 'position', 'duty', 'work_type', 'type', 'category', 'worker_role'),
            'start_time' => array('start_time_phone', 'start_time', 'clock_in', 'check_in', 'in_time', 'start_dt'),
            'end_time' => array('stop_time_phone', 'end_time', 'clock_out', 'check_out', 'out_time', 'stop_dt'),
            );

        foreach ($aliases as $key => $list) {
            foreach ($list as $alias) {
                $aliasLower = strtolower($alias);
                if (isset($lower[$aliasLower])) {
                    $colMap[$key] = $lower[$aliasLower];
                    break;
                }
            }
        }

        if (!isset($colMap['site']) || !isset($colMap['name']) || !isset($colMap['date']) || !isset($colMap['gongsu'])) {
            return array();
        }
        return $colMap;
    }
}

if (!function_exists('cpms_find_role_column')) {
    function cpms_find_role_column($columns) {
        $lower = array();
        foreach ($columns as $col) {
            $lower[strtolower((string)$col)] = (string)$col;
        }
        $aliases = array('role', 'job', 'position', 'duty', 'work_type', 'type', 'category', 'worker_role');
        foreach ($aliases as $alias) {
            $k = strtolower($alias);
            if (isset($lower[$k])) return $lower[$k];
        }
        return '';
    }
}

if (!function_exists('cpms_normalize_role_value')) {
    function cpms_normalize_role_value($value) {
        $value = trim((string)$value);
        if ($value === '') return '';
        $value = preg_replace('/\s+/u', '', $value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

if (!function_exists('cpms_is_excluded_equipment_driver_role')) {
    function cpms_is_excluded_equipment_driver_role($roleValue) {
        $normalized = cpms_normalize_role_value($roleValue);
        if ($normalized === '') return false;
        // 장비기사 제외
        return ($normalized === '장비기사');
    }
}

if (!function_exists('cpms_find_gongsu_table')) {
    function cpms_find_gongsu_table($pdo) {
        static $cache = array();
        if (!$pdo) return array();
        $cacheKey = cpms_labor_cache_key($pdo, 'gongsu-table');
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        $dbName = '';
        try {
            $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        } catch (Exception $e) {
            $dbName = '';
        }
        if ($dbName === '') {
            $cache[$cacheKey] = array();
            return array();
        }

        $candidates = array('admin_gongsu', 'attendance_gongsu', 'gongsu', 'gongsu_entries', 'attendance_entries');
        try {
            $st = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME LIKE :pattern");
            $st->bindValue(':db', $dbName);
            $st->bindValue(':pattern', '%gongsu%');
            $st->execute();
            $rows = $st->fetchAll(\PDO::FETCH_COLUMN);
            if (is_array($rows)) {
                $candidates = array_merge($rows, $candidates);
            }
        } catch (Exception $e) {
        }

        $seen = array();
        foreach ($candidates as $table) {
            $table = (string)$table;
            if ($table === '' || isset($seen[$table])) continue;
            $seen[$table] = true;
            if (!cpms_table_exists_labor($pdo, $table)) continue;
            $columns = cpms_table_columns($pdo, $table);
            if (count($columns) === 0) continue;
            $map = cpms_map_gongsu_columns($columns);
            if (count($map) === 0) continue;
            $cache[$cacheKey] = array('table' => $table, 'columns' => $map);
            return $cache[$cacheKey];
        }
        $cache[$cacheKey] = array();
        return array();
    }
}

if (!function_exists('cpms_is_printed_value')) {
    function cpms_is_printed_value($value) {
        if ($value === null) return true;
        $v = trim((string)$value);
        if ($v === '') return false;
        $upper = strtoupper($v);
        $truthy = array('1', 'Y', 'YES', 'TRUE', 'PRINT', 'PRINTED', 'OK', '완료', '출력', '출력완료');
        return in_array($upper, $truthy, true);
    }
}

if (!function_exists('cpms_parse_gongsu_value')) {
    function cpms_parse_gongsu_value($value) {
        if ($value === null) return null;
        $v = trim((string)$value);
        if ($v === '') return null;
        $v = str_replace(',', '', $v);
        if (!is_numeric($v)) return null;
        return (float)$v;
    }
}

if (!function_exists('cpms_att_overtime_minutes_after_17')) {
    /**
     * attendance/admin_gongsu.php 와 동일한 규칙: 17시 이후 근로분 계산
     * - start_dt, stop_dt: 'Y-m-d H:i:s' 형태(또는 strtotime 가능한 문자열)
     */
    function cpms_att_overtime_minutes_after_17($start_dt, $stop_dt) {
        if (!$start_dt || !$stop_dt) return 0;

        $start_ts = strtotime($start_dt);
        $stop_ts  = strtotime($stop_dt);
        if ($start_ts === false || $stop_ts === false || $stop_ts <= $start_ts) return 0;

        $ymd = date('Y-m-d', $start_ts);
        $cut_ts = strtotime($ymd . ' 17:00:00');
        if ($cut_ts === false) return 0;

        if ($stop_ts <= $cut_ts) return 0;

        $ot_start = ($start_ts > $cut_ts) ? $start_ts : $cut_ts;
        $mins = (int)floor(($stop_ts - $ot_start) / 60);
        if ($mins < 0) $mins = 0;
        return $mins;
    }
}

if (!function_exists('cpms_att_calc_gongsu')) {
    /**
     * attendance/admin_gongsu.php 와 동일한 규칙: 공수 계산
     * 1) 총근로시간 <= 270분 => 0.5
     * 2) 총근로시간 > 270분 => 1.0
     * 3) 17시 이후 1시간마다 +0.1
     */
    function cpms_att_calc_gongsu($total_minutes, $overtime_minutes) {
        $total_minutes = (int)$total_minutes;
        $overtime_minutes = (int)$overtime_minutes;

        $base = ($total_minutes <= 270) ? 0.5 : 1.0;

        $ot_hours = (int)floor($overtime_minutes / 60);
        $ot_add = $ot_hours * 0.1;

        // float 오차 보정
        return round($base + $ot_add, 2);
    }
}

if (!function_exists('cpms_att_calc_total_minutes_fallback')) {
    /**
     * total_minutes가 비어있을 때, start/stop로 분 계산(보조용)
     */
    function cpms_att_calc_total_minutes_fallback($start_dt, $stop_dt) {
        if (!$start_dt || !$stop_dt) return null;
        $start_ts = strtotime($start_dt);
        $stop_ts  = strtotime($stop_dt);
        if ($start_ts === false || $stop_ts === false || $stop_ts <= $start_ts) return null;
        return (int)floor(($stop_ts - $start_ts) / 60);
    }
}

if (!function_exists('cpms_load_gongsu_data_from_attendance_records')) {
    /**
     * (핵심) attendance DB에서 직접 읽어서 CPMS 노무비(공수/인원작성)에 넣을 데이터로 변환
     * - 현장명(sites.name) = 프로젝트명(projectRow['name']) 이면 그 현장(site_id)만 사용
     * - 날짜(DATE(start_time_phone)) + 이름(name) 일치 시 공수탭 날짜칸에 자동 입력
     * - status='done' (퇴근완료) 인 기록만 공수로 인정 (아니면 빈칸 유지)
     */
    function cpms_load_gongsu_data_from_attendance_records($attendancePdo, $projectName, $selectedMonth) {
        $result = array(
            'workers' => array(),
            'all_workers' => array(),
            'gongsu_map' => array(),
            'gongsu_unit' => array(),
            'output_days' => array(),
            'excluded_workers' => array(),
            'role_map' => array(),
            'time_map' => array(),
            );

        if (!$attendancePdo || $projectName === '' || $selectedMonth === '') return $result;
        static $cache = array();
        $cacheKey = cpms_labor_cache_key($attendancePdo, 'attendance-records:' . trim((string)$projectName) . ':' . trim((string)$selectedMonth));
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

        // 테이블 존재 확인 (attendance, sites)
        if (!cpms_table_exists_labor($attendancePdo, 'sites') || !cpms_table_exists_labor($attendancePdo, 'attendance')) {
            $cache[$cacheKey] = $result;
            return $result;
        }

        // 1) 프로젝트명과 같은 현장(site) 찾기
        $siteId = 0;
        $siteMatch = cpms_find_attendance_site_match($attendancePdo, $projectName);
        if (is_array($siteMatch) && isset($siteMatch['id'])) $siteId = (int)$siteMatch['id'];
        if ($siteId <= 0) {
            $cache[$cacheKey] = $result;
            return $result;
        }

        // 2) 월 범위 계산
        $monthStart = $selectedMonth . '-01 00:00:00';
        $monthEnd = '';
        try {
            $dt = new DateTime($selectedMonth . '-01');
            $dt->modify('+1 month');
            $monthEnd = $dt->format('Y-m-01') . ' 00:00:00';
        } catch (Exception $e) {
            $monthEnd = $selectedMonth . '-31 23:59:59';
        }

        $attendanceColumns = cpms_table_columns($attendancePdo, 'attendance');
        $roleColumn = cpms_find_role_column($attendanceColumns);

        // 3) 전체 이름 목록 (월과 무관)
        $allWorkers = array();
        $excludedWorkers = array();
        $roleMap = array();
        try {
            // 인원작성 누적(출력자만): done 이력이 1회 이상 있는 사람만 누적 목록에 포함
            $sqlAll = "SELECT DISTINCT name";
            if ($roleColumn !== '') {
                $sqlAll .= ", `" . $roleColumn . "` AS role_value";
            }
            $sqlAll .= " FROM attendance WHERE site_id = :sid AND status = 'done' ORDER BY name ASC";
            $stAll = $attendancePdo->prepare($sqlAll);
            $stAll->bindValue(':sid', $siteId, PDO::PARAM_INT);
            $stAll->execute();
            $rowsAll = $stAll->fetchAll();
            foreach ($rowsAll as $rowAll) {
                $nameAll = isset($rowAll['name']) ? trim((string)$rowAll['name']) : '';
                if ($nameAll === '') continue;
                $keyAll = cpms_normalize_worker_key($nameAll);
                if ($keyAll === '') continue;
                if ($roleColumn !== '' && cpms_is_excluded_equipment_driver_role(isset($rowAll['role_value']) ? $rowAll['role_value'] : '')) {
                    if (!isset($excludedWorkers[$keyAll])) $excludedWorkers[$keyAll] = $nameAll; // 장비기사 제외
                    continue;
                }
                $allWorkers[$keyAll] = $nameAll;
                if ($roleColumn !== '') {
                    $roleValueAll = isset($rowAll['role_value']) ? trim((string)$rowAll['role_value']) : '';
                    if ($roleValueAll !== '' && !isset($roleMap[$keyAll])) $roleMap[$keyAll] = $roleValueAll;
                }
            }
        } catch (Exception $e) {
            $allWorkers = array();
        }

        // 4) 출근기록 조회 (이름 목록 + 공수 자동 계산)
        $rows = array();
        try {
            $sql = "SELECT name, start_time_phone, stop_time_phone, total_minutes, status";
            if ($roleColumn !== '') {
                $sql .= ", `" . $roleColumn . "` AS role_value";
            }
            $sql .= "
                    FROM attendance
                    WHERE site_id = :sid
                      AND start_time_phone >= :start
                      AND start_time_phone < :end
                    ORDER BY start_time_phone ASC";
            $st = $attendancePdo->prepare($sql);
            $st->bindValue(':sid', $siteId, PDO::PARAM_INT);
            $st->bindValue(':start', $monthStart);
            $st->bindValue(':end', $monthEnd);
            $st->execute();
            $rows = $st->fetchAll();
        } catch (Exception $e) {
            $rows = array();
        }

        $workers = array();           // key => name
        $gongsuMap = array();         // key => [Y-m-d => gongsu]
        $timeMap = array();           // key => [Y-m-d => 출근/퇴근시간]
        $outputDaysSet = array();     // key => [Y-m-d => true]
        $sumGongsu = array();         // key => float sum

        foreach ($rows as $row) {
            $name = isset($row['name']) ? trim((string)$row['name']) : '';
            if ($name === '') continue;

            $key = cpms_normalize_worker_key($name);
            if ($key === '') continue;
            if ($roleColumn !== '' && cpms_is_excluded_equipment_driver_role(isset($row['role_value']) ? $row['role_value'] : '')) {
                if (!isset($excludedWorkers[$key])) $excludedWorkers[$key] = $name; // 장비기사 제외
                continue;
            }

            // 3-1) 인원작성 탭용 이름 목록은 "기록이 존재하면" 일단 포함
            if (!isset($workers[$key])) $workers[$key] = $name;
            if ($roleColumn !== '') {
                $roleValue = isset($row['role_value']) ? trim((string)$row['role_value']) : '';
                if ($roleValue !== '' && !isset($roleMap[$key])) $roleMap[$key] = $roleValue;
            }

            $startPhone = isset($row['start_time_phone']) ? (string)$row['start_time_phone'] : '';
            $stopPhone  = isset($row['stop_time_phone']) ? (string)$row['stop_time_phone'] : '';
            if ($startPhone === '') continue;

            // 날짜키(Y-m-d)
            $startTs = strtotime($startPhone);
            if ($startTs === false) continue;
            $dateKey = date('Y-m-d', $startTs);
            if (strpos($dateKey, $selectedMonth) !== 0) continue;
            if (!isset($timeMap[$key])) $timeMap[$key] = array();
            if (!isset($timeMap[$key][$dateKey])) {
                $timeMap[$key][$dateKey] = array('start' => $startPhone, 'end' => $stopPhone);
            } else {
                $oldStart = isset($timeMap[$key][$dateKey]['start']) ? (string)$timeMap[$key][$dateKey]['start'] : '';
                $oldEnd = isset($timeMap[$key][$dateKey]['end']) ? (string)$timeMap[$key][$dateKey]['end'] : '';
                if ($oldStart === '' || strtotime($startPhone) < strtotime($oldStart)) $timeMap[$key][$dateKey]['start'] = $startPhone;
                if ($stopPhone !== '' && ($oldEnd === '' || strtotime($stopPhone) > strtotime($oldEnd))) $timeMap[$key][$dateKey]['end'] = $stopPhone;
            }

            // 3-2) 공수탭 자동 입력은 "퇴근완료(done)"만
            $status = isset($row['status']) ? (string)$row['status'] : '';
            if ($status !== 'done') {
                // done이 아니면 날짜칸은 빈칸 유지
                continue;
            }

            // 무효(invalid) 같은 값이 있으면 제외
            if ($status === 'invalid') continue;

            // 총근로분
            $totalMinutes = isset($row['total_minutes']) ? $row['total_minutes'] : null;
            if ($totalMinutes === null || $totalMinutes === '') {
                $fallback = cpms_att_calc_total_minutes_fallback($startPhone, $stopPhone);
                if ($fallback === null) continue;
                $totalMinutes = $fallback;
            }

            // 17시 이후 근로분
            $otMinutes = cpms_att_overtime_minutes_after_17($startPhone, $stopPhone);

            // 공수 계산
            $gongsu = cpms_att_calc_gongsu($totalMinutes, $otMinutes);
            if ($gongsu <= 0) continue;

            if (!isset($gongsuMap[$key])) $gongsuMap[$key] = array();
            if (!isset($gongsuMap[$key][$dateKey])) $gongsuMap[$key][$dateKey] = 0.0;
            $gongsuMap[$key][$dateKey] = round(((float)$gongsuMap[$key][$dateKey]) + (float)$gongsu, 2);

            if (!isset($outputDaysSet[$key])) $outputDaysSet[$key] = array();
            $outputDaysSet[$key][$dateKey] = true;

            if (!isset($sumGongsu[$key])) $sumGongsu[$key] = 0.0;
            $sumGongsu[$key] = round(((float)$sumGongsu[$key]) + (float)$gongsu, 2);
        }

        // 5) 출력일수(=done인 날짜 수) 및 공수단위(평균공수) 구성
        $outputDays = array();
        $gongsuUnit = array();
        foreach ($workers as $key => $nm) {
            $days = 0;
            if (isset($outputDaysSet[$key]) && is_array($outputDaysSet[$key])) {
                $days = count($outputDaysSet[$key]);
            }
            $outputDays[$key] = (int)$days;

            if ($days > 0 && isset($sumGongsu[$key])) {
                $gongsuUnit[$key] = round(((float)$sumGongsu[$key]) / $days, 2);
            } else {
                $gongsuUnit[$key] = 0.0;
            }
        }

        $result['workers'] = array_values($workers);
        if (count($allWorkers) > 0) {
            $result['all_workers'] = array_values($allWorkers);
        } else {
            $result['all_workers'] = array_values($workers);
        }
        $result['gongsu_map'] = $gongsuMap;
        $result['gongsu_unit'] = $gongsuUnit;
        $result['output_days'] = $outputDays;
        $result['excluded_workers'] = array_values($excludedWorkers);
        $result['role_map'] = $roleMap;
        $result['time_map'] = $timeMap;
        $cache[$cacheKey] = $result;

        return $result;
    }
}

if (!function_exists('cpms_load_gongsu_data')) {
    function cpms_load_gongsu_data($pdo, $projectName, $selectedMonth) {
        $result = array(
            'workers' => array(),
            'all_workers' => array(),
            'gongsu_map' => array(),
            'gongsu_unit' => array(),
            'output_days' => array(),
            'excluded_workers' => array(),
            'role_map' => array(),
            'time_map' => array(),
        );

        $projectName = trim((string)$projectName);
        if ($projectName === '' || $selectedMonth === '') return $result;
        if (!$pdo) $pdo = null;
        static $cache = array();
        $cachePdo = $pdo;

        $info = array();
        if ($pdo) {
            $info = cpms_find_gongsu_table($pdo);
        }
        if (count($info) === 0) {
            // 1순위: attendance 쪽에 gongsu 전용 테이블이 있다면 그걸 사용
            // 2순위: 없다면 attendance(attendance 테이블)에서 직접 공수 계산해서 가져오기
            $attendancePdo = cpms_load_attendance_pdo();
            if ($attendancePdo) {
                $info = cpms_find_gongsu_table($attendancePdo);
                if (count($info) > 0) {
                    $pdo = $attendancePdo;
                    $cachePdo = $attendancePdo;
                } else {
                    // (현장명=프로젝트명) 기준으로 attendance 기록을 읽어서 공수/인원작성 자동매핑
                    $cacheKey = cpms_labor_cache_key($attendancePdo, 'gongsu-data:' . $projectName . ':' . $selectedMonth);
                    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
                    $cache[$cacheKey] = cpms_load_gongsu_data_from_attendance_records($attendancePdo, $projectName, $selectedMonth);
                    return $cache[$cacheKey];
                }
            }
        }
        if (count($info) === 0 || !$pdo) return $result;
        $cacheKey = cpms_labor_cache_key($cachePdo, 'gongsu-data:' . $projectName . ':' . $selectedMonth);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

        $table = $info['table'];
        $cols = $info['columns'];
        $siteNameForQuery = cpms_resolve_gongsu_site_name($pdo, $table, $cols['site'], $projectName);

        $sql = "SELECT `" . $cols['site'] . "` AS site_name,
                       `" . $cols['name'] . "` AS worker_name,
                       `" . $cols['date'] . "` AS work_date,
                       `" . $cols['gongsu'] . "` AS gongsu_value";
        if (isset($cols['printed'])) {
            $sql .= ", `" . $cols['printed'] . "` AS printed_value";
        }
        if (isset($cols['role'])) {
            $sql .= ", `" . $cols['role'] . "` AS role_value";
        }
        if (isset($cols['start_time'])) {
            $sql .= ", `" . $cols['start_time'] . "` AS start_time_value";
        }
        if (isset($cols['end_time'])) {
            $sql .= ", `" . $cols['end_time'] . "` AS end_time_value";
        }
        $sql .= " FROM `" . $table . "` WHERE `" . $cols['site'] . "` = :site AND `" . $cols['date'] . "` LIKE :month";

        try {
            $st = $pdo->prepare($sql);
            $st->bindValue(':site', $siteNameForQuery);
            $st->bindValue(':month', $selectedMonth . '%');
            $st->execute();
            $rows = $st->fetchAll();
        } catch (Exception $e) {
            $rows = array();
        }

        $workers = array();
        $allWorkers = array();
        $excludedWorkers = array();
        $roleMap = array();
        $gongsuMap = array();
        $timeMap = array();
        $gongsuUnit = array();
        $outputDays = array();

        foreach ($rows as $row) {
            $workerName = isset($row['worker_name']) ? trim((string)$row['worker_name']) : '';
            if ($workerName === '') continue;
            if (isset($cols['role']) && cpms_is_excluded_equipment_driver_role(isset($row['role_value']) ? $row['role_value'] : '')) {
                $excludeKey = cpms_normalize_worker_key($workerName);
                if ($excludeKey !== '' && !isset($excludedWorkers[$excludeKey])) $excludedWorkers[$excludeKey] = $workerName; // 장비기사 제외
                continue;
            }
            if (isset($row['printed_value']) && !cpms_is_printed_value($row['printed_value'])) {
                continue;
            }

            $workDate = isset($row['work_date']) ? (string)$row['work_date'] : '';
            $workDate = trim($workDate);
            if ($workDate === '') continue;
            try {
                $dateObj = new DateTime($workDate);
                $workDate = $dateObj->format('Y-m-d');
            } catch (Exception $e) {
                continue;
            }
            if (strpos($workDate, $selectedMonth) !== 0) continue;

            $gongsuValue = cpms_parse_gongsu_value(isset($row['gongsu_value']) ? $row['gongsu_value'] : null);
            if ($gongsuValue === null) continue;

            $key = cpms_normalize_worker_key($workerName);
            if ($key === '') continue;

            if (!isset($workers[$key])) $workers[$key] = $workerName;
            if (isset($cols['role'])) {
                $roleValue = isset($row['role_value']) ? trim((string)$row['role_value']) : '';
                if ($roleValue !== '' && !isset($roleMap[$key])) $roleMap[$key] = $roleValue;
            }
            if (!isset($gongsuMap[$key])) $gongsuMap[$key] = array();

            $gongsuMap[$key][$workDate] = $gongsuValue;
            $startTimeValue = isset($row['start_time_value']) ? trim((string)$row['start_time_value']) : '';
            $endTimeValue = isset($row['end_time_value']) ? trim((string)$row['end_time_value']) : '';
            if ($startTimeValue !== '' || $endTimeValue !== '') {
                if (!isset($timeMap[$key])) $timeMap[$key] = array();
                $timeMap[$key][$workDate] = array('start' => $startTimeValue, 'end' => $endTimeValue);
            }

            if (!isset($outputDays[$key])) $outputDays[$key] = 0;
            $outputDays[$key] += 1;

            if (!isset($gongsuUnit[$key]) && $gongsuValue > 0) {
                $gongsuUnit[$key] = $gongsuValue;
            }
        }

        // 전체 이름 목록은 월과 무관하게 한 번 더 조회
        if (isset($cols['printed'])) {
            $sqlAll = "SELECT `" . $cols['name'] . "` AS worker_name, `" . $cols['printed'] . "` AS printed_value
                       FROM `" . $table . "`
                       WHERE `" . $cols['site'] . "` = :site";
        } else {
            $sqlAll = "SELECT `" . $cols['name'] . "` AS worker_name
                       FROM `" . $table . "`
                       WHERE `" . $cols['site'] . "` = :site";
        }
        if (isset($cols['role'])) {
            $sqlAll = str_replace(" FROM `" . $table . "`", ", `" . $cols['role'] . "` AS role_value FROM `" . $table . "`", $sqlAll);
        }
        try {
            $stAll = $pdo->prepare($sqlAll);
            $stAll->bindValue(':site', $siteNameForQuery);
            $stAll->execute();
            $rowsAll = $stAll->fetchAll();
            foreach ($rowsAll as $rowAll) {
                if (isset($rowAll['printed_value']) && !cpms_is_printed_value($rowAll['printed_value'])) {
                    continue;
                }
                $workerName = isset($rowAll['worker_name']) ? trim((string)$rowAll['worker_name']) : '';
                if ($workerName === '') continue;
                $key = cpms_normalize_worker_key($workerName);
                if ($key === '') continue;
                if (isset($cols['role']) && cpms_is_excluded_equipment_driver_role(isset($rowAll['role_value']) ? $rowAll['role_value'] : '')) {
                    if (!isset($excludedWorkers[$key])) $excludedWorkers[$key] = $workerName; // 장비기사 제외
                    continue;
                }
                if (!isset($allWorkers[$key])) $allWorkers[$key] = $workerName;
                if (isset($cols['role'])) {
                    $roleValueAll = isset($rowAll['role_value']) ? trim((string)$rowAll['role_value']) : '';
                    if ($roleValueAll !== '' && !isset($roleMap[$key])) $roleMap[$key] = $roleValueAll;
                }
            }
        } catch (Exception $e) {
            $allWorkers = array();
        }

        $result['workers'] = array_values($workers);
        $result['all_workers'] = count($allWorkers) > 0 ? array_values($allWorkers) : array_values($workers);
        $result['gongsu_map'] = $gongsuMap;
        $result['gongsu_unit'] = $gongsuUnit;
        $result['output_days'] = $outputDays;
        $result['excluded_workers'] = array_values($excludedWorkers);
        $result['role_map'] = $roleMap;
        $result['time_map'] = $timeMap;

        $cache[$cacheKey] = $result;
        return $result;
    }
}

if (!function_exists('cpms_load_direct_team_members')) {
    function cpms_load_direct_team_members($pdo) {
        $members = array();
        if (!$pdo) return $members;
        static $cache = array();
        $cacheKey = cpms_labor_cache_key($pdo, 'direct-team-members');
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            if (cpms_table_exists_labor($pdo, 'direct_team_members')) {
                $st = $pdo->prepare("SELECT * FROM direct_team_members ORDER BY id ASC");
                $st->execute();
                $members = $st->fetchAll();
            }
        } catch (Exception $e) {
            $members = array();
        }
        $cache[$cacheKey] = $members;
        return $members;
    }
}

if (!function_exists('cpms_direct_team_salary_allocations')) {
    /**
     * 선택 월에 직영팀이 배정된 모든 현장의 실제 출역일수를 합산해 월급제 일 단가를 계산합니다.
     */
    function cpms_direct_team_salary_allocations($pdo, $directTeamMembers, $selectedMonth) {
        $result = array();
        static $cache = array();
        $selectedMonth = trim((string)$selectedMonth);
        if (!$pdo || !is_array($directTeamMembers) || !preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) return $result;
        $cacheKey = cpms_labor_cache_key($pdo, 'direct-salary-allocation:' . $selectedMonth);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        if (!cpms_table_exists_labor($pdo, 'direct_team_members') || !cpms_table_exists_labor($pdo, 'cpms_project_labor_workers') || !cpms_table_exists_labor($pdo, 'cpms_projects')) return $result;

        $memberMap = array();
        foreach ($directTeamMembers as $member) {
            $memberId = isset($member['id']) ? (int)$member['id'] : 0;
            $monthlySalary = isset($member['monthly_salary']) ? (int)$member['monthly_salary'] : 0;
            $name = isset($member['name']) ? trim((string)$member['name']) : '';
            // 예전 직영팀 단가제 인원(monthly_salary=0)은 월급 N/1 계산 대상이 아닙니다.
            if ($memberId <= 0 || $monthlySalary <= 0 || $name === '') continue;
            $memberMap[$memberId] = array('name'=>$name, 'monthly_salary'=>$monthlySalary);
            $result[$memberId] = array('monthly_salary'=>$monthlySalary, 'total_output_days'=>0, 'daily_rate'=>0.0, 'project_days'=>array());
        }
        if (count($memberMap) === 0) {
            $cache[$cacheKey] = $result;
            return $result;
        }

        try {
            $hasMonthTable = cpms_table_exists_labor($pdo, 'cpms_project_labor_worker_months');
            $sql = "SELECT DISTINCT plw.direct_member_id, p.id AS project_id, p.name AS project_name
                    FROM cpms_project_labor_workers plw
                    INNER JOIN cpms_projects p ON p.id = plw.project_id";
            if ($hasMonthTable) {
                $sql .= " INNER JOIN cpms_project_labor_worker_months pwm
                            ON pwm.project_id = plw.project_id
                           AND pwm.labor_worker_id = plw.id
                           AND pwm.month = :month
                           AND pwm.is_deleted = 0";
            }
            $sql .= " WHERE plw.direct_member_id IS NOT NULL
                        AND plw.direct_member_id > 0
                        AND plw.is_deleted = 0
                      ORDER BY p.id ASC, plw.direct_member_id ASC";
            $st = $pdo->prepare($sql);
            if ($hasMonthTable) $st->bindValue(':month', $selectedMonth);
            $st->execute();
            $assignments = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($assignments)) $assignments = array();

            $projects = array();
            foreach ($assignments as $assignment) {
                $memberId = isset($assignment['direct_member_id']) ? (int)$assignment['direct_member_id'] : 0;
                $projectId = isset($assignment['project_id']) ? (int)$assignment['project_id'] : 0;
                if (!isset($memberMap[$memberId]) || $projectId <= 0) continue;
                if (!isset($projects[$projectId])) {
                    $projects[$projectId] = array('name'=>isset($assignment['project_name']) ? (string)$assignment['project_name'] : '', 'member_ids'=>array());
                }
                $projects[$projectId]['member_ids'][$memberId] = $memberId;
            }

            foreach ($projects as $projectId => $projectInfo) {
                $gongsuData = cpms_load_gongsu_data($pdo, isset($projectInfo['name']) ? $projectInfo['name'] : '', $selectedMonth);
                $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
                $outputDays = isset($gongsuData['output_days']) && is_array($gongsuData['output_days']) ? $gongsuData['output_days'] : array();
                $gongsuUnit = isset($gongsuData['gongsu_unit']) && is_array($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array();
                if (function_exists('cpms_apply_labor_overrides_to_dataset')) {
                    $overridden = cpms_apply_labor_overrides_to_dataset($gongsuMap, $outputDays, $gongsuUnit, (int)$projectId, $selectedMonth);
                    if (isset($overridden['gongsu_map']) && is_array($overridden['gongsu_map'])) $gongsuMap = $overridden['gongsu_map'];
                }
                foreach ($projectInfo['member_ids'] as $memberId) {
                    $workerKey = cpms_normalize_worker_key($memberMap[$memberId]['name']);
                    $dailyMap = $workerKey !== '' && isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey]) ? $gongsuMap[$workerKey] : array();
                    $days = 0;
                    foreach ($dailyMap as $dateKey => $gongsuValue) {
                        if (strpos((string)$dateKey, $selectedMonth . '-') !== 0 || !is_numeric($gongsuValue) || (float)$gongsuValue <= 0) continue;
                        $days++;
                    }
                    if ($days > 0) {
                        $result[$memberId]['project_days'][(int)$projectId] = $days;
                        $result[$memberId]['total_output_days'] += $days;
                    }
                }
            }
        } catch (Exception $e) {
            $cache[$cacheKey] = $result;
            return $result;
        }

        foreach ($result as $memberId => $allocation) {
            $days = isset($allocation['total_output_days']) ? (int)$allocation['total_output_days'] : 0;
            $salary = isset($allocation['monthly_salary']) ? (int)$allocation['monthly_salary'] : 0;
            $result[$memberId]['daily_rate'] = $days > 0 ? ((float)$salary / (float)$days) : 0.0;
        }
        $cache[$cacheKey] = $result;
        return $result;
    }
}

if (!function_exists('cpms_apply_direct_team_salary_allocations')) {
    function cpms_apply_direct_team_salary_allocations($pdo, $directTeamMembers, $selectedMonth) {
        if (!is_array($directTeamMembers)) return array();
        $allocations = cpms_direct_team_salary_allocations($pdo, $directTeamMembers, $selectedMonth);
        foreach ($directTeamMembers as $index => $member) {
            $memberId = isset($member['id']) ? (int)$member['id'] : 0;
            if ($memberId <= 0 || !isset($allocations[$memberId])) continue;
            $allocation = $allocations[$memberId];
            $directTeamMembers[$index]['salary_allocation_mode'] = 1;
            $directTeamMembers[$index]['salary_total_output_days'] = isset($allocation['total_output_days']) ? (int)$allocation['total_output_days'] : 0;
            $directTeamMembers[$index]['salary_daily_rate'] = isset($allocation['daily_rate']) ? (float)$allocation['daily_rate'] : 0.0;
            $directTeamMembers[$index]['salary_project_days'] = isset($allocation['project_days']) ? $allocation['project_days'] : array();
        }
        return $directTeamMembers;
    }
}

if (!function_exists('cpms_parse_labor_wage_value')) {
    function cpms_parse_labor_wage_value($value) {
        $raw = trim((string)$value);
        if ($raw === '') return 0.0;
        $raw = str_replace(',', '', $raw);
        if (!is_numeric($raw)) return 0.0;
        return (float)$raw;
    }
}

if (!function_exists('cpms_resolve_labor_wage_rate')) {
    function cpms_resolve_labor_wage_rate($worker) {
        if (!is_array($worker)) return 0.0;
        if (isset($worker['salary_allocation_mode']) && (int)$worker['salary_allocation_mode'] === 1) {
            $salaryRate = isset($worker['salary_daily_rate']) ? (float)$worker['salary_daily_rate'] : 0.0;
            return $salaryRate > 0 ? $salaryRate : 0.0;
        }
        $depositRateRaw = isset($worker['deposit_rate']) ? (string)$worker['deposit_rate'] : '';
        $dailyWageRaw = isset($worker['daily_wage']) ? (string)$worker['daily_wage'] : '';
        $depositRate = cpms_parse_labor_wage_value($depositRateRaw);
        $dailyWage = cpms_parse_labor_wage_value($dailyWageRaw);

        if ($depositRate > 0) {
            if ($depositRate < 1000 && $dailyWage >= 1000) {
                return $dailyWage;
            }
            return $depositRate;
        }
        if ($dailyWage > 0) return $dailyWage;
        return 0.0;
    }
}

if (!function_exists('cpms_ensure_project_labor_workers_table')) {
    function cpms_ensure_project_labor_workers_table($pdo) {
        if (!$pdo) return false;
        $table = 'cpms_project_labor_workers';
        try {
            if (!cpms_table_exists_labor($pdo, $table)) {
                // 인원작성 저장 기능: 프로젝트별 임금/계좌 정보 저장 컬럼 포함
                $pdo->exec("CREATE TABLE cpms_project_labor_workers (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    project_id INT UNSIGNED NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    source VARCHAR(20) NOT NULL DEFAULT 'manual',
                    direct_member_id INT UNSIGNED NULL,
                    worker_id INT NULL,
                    worker_name_snapshot VARCHAR(100) NULL,
                    agency_name_snapshot VARCHAR(100) NULL,
                    job_type_snapshot VARCHAR(100) NULL,
                    daily_wage_snapshot INT NOT NULL DEFAULT 0,
                    source_type VARCHAR(30) NOT NULL DEFAULT 'manual',
                    matched_status VARCHAR(30) NOT NULL DEFAULT 'manual',
                    resident_no VARCHAR(30) NULL,
                    phone VARCHAR(30) NULL,
                    address VARCHAR(255) NULL,
                    deposit_rate INT NOT NULL DEFAULT 0,
                    bank_account VARCHAR(50) NULL,
                    bank_name VARCHAR(50) NULL,
                    account_holder VARCHAR(50) NULL,
                    company_name VARCHAR(80) NULL,
                    remark VARCHAR(255) NULL,
                    is_outsourcing TINYINT(1) NOT NULL DEFAULT 0,
                    legacy_outsourcing_ratio TINYINT UNSIGNED NULL DEFAULT NULL,
                    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    KEY idx_project_labor_project_name(project_id, name),
                    KEY idx_project_labor_worker_id(worker_id),
                    KEY idx_project_labor_match(project_id, matched_status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            }

            // 인원작성 저장 기능: 기존 테이블 누락 컬럼 자동 보강
            $cols = cpms_table_columns($pdo, $table);
            $colMap = array();
            foreach ($cols as $c) $colMap[(string)$c] = true;
            $addCols = array(
                'worker_id' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN worker_id INT NULL AFTER direct_member_id",
                'worker_name_snapshot' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN worker_name_snapshot VARCHAR(100) NULL AFTER worker_id",
                'agency_name_snapshot' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN agency_name_snapshot VARCHAR(100) NULL AFTER worker_name_snapshot",
                'job_type_snapshot' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN job_type_snapshot VARCHAR(100) NULL AFTER agency_name_snapshot",
                'daily_wage_snapshot' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN daily_wage_snapshot INT NOT NULL DEFAULT 0 AFTER job_type_snapshot",
                'source_type' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN source_type VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER daily_wage_snapshot",
                'matched_status' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN matched_status VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER source_type",
                'resident_no' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN resident_no VARCHAR(30) NULL AFTER direct_member_id",
                'phone' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN phone VARCHAR(30) NULL AFTER resident_no",
                'address' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN address VARCHAR(255) NULL AFTER phone",
                'deposit_rate' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN deposit_rate INT NOT NULL DEFAULT 0 AFTER address",
                'bank_account' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN bank_account VARCHAR(50) NULL AFTER deposit_rate",
                'bank_name' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN bank_name VARCHAR(50) NULL AFTER bank_account",
                'account_holder' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN account_holder VARCHAR(50) NULL AFTER bank_name",
                'company_name' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN company_name VARCHAR(80) NULL AFTER account_holder",
                'remark' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN remark VARCHAR(255) NULL AFTER company_name",
                'is_outsourcing' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN is_outsourcing TINYINT(1) NOT NULL DEFAULT 0 AFTER company_name",
                // 파일: app/views/construction/tabs/partials/labor_data_loader.php
                // 월별 비율 도입 뒤에도 과거 월의 기존 외주 여부가 바뀌지 않도록 최초 호환 기준값을 보존합니다.
                'legacy_outsourcing_ratio' => "ALTER TABLE cpms_project_labor_workers ADD COLUMN legacy_outsourcing_ratio TINYINT UNSIGNED NULL DEFAULT NULL AFTER is_outsourcing",
            );
            foreach ($addCols as $col => $sql) {
                if (isset($colMap[$col])) continue;
                $pdo->exec($sql);
            }
            $idxMap = array();
            try {
                $stIdx = $pdo->query("SHOW INDEX FROM cpms_project_labor_workers");
                while ($idxRow = $stIdx->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($idxRow['Key_name'])) $idxMap[(string)$idxRow['Key_name']] = true;
                }
            } catch (Exception $e) {
                $idxMap = array();
            }
            if (!isset($idxMap['idx_project_labor_worker_id'])) {
                $pdo->exec("ALTER TABLE cpms_project_labor_workers ADD KEY idx_project_labor_worker_id(worker_id)");
            }
            if (!isset($idxMap['idx_project_labor_match'])) {
                $pdo->exec("ALTER TABLE cpms_project_labor_workers ADD KEY idx_project_labor_match(project_id, matched_status)");
            }
            // 동명이인을 서로 다른 인력 마스터 ID로 연결할 수 있도록 과거 이름 유일키를 일반 인덱스로 전환합니다.
            if (isset($idxMap['uk_project_labor_workers'])) {
                $pdo->exec("ALTER TABLE cpms_project_labor_workers DROP INDEX uk_project_labor_workers");
                unset($idxMap['uk_project_labor_workers']);
            }
            if (!isset($idxMap['idx_project_labor_project_name'])) {
                $pdo->exec("ALTER TABLE cpms_project_labor_workers ADD KEY idx_project_labor_project_name(project_id, name)");
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cpms_load_project_labor_workers')) {
    function cpms_load_project_labor_workers($pdo, $projectId) {
        $rows = array();
        if (!$pdo || $projectId <= 0) return $rows;
        if (!cpms_ensure_project_labor_workers_table($pdo)) return $rows;
        static $cache = array();
        $cacheKey = cpms_labor_cache_key($pdo, 'project-labor-workers:' . (int)$projectId);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_project_labor_workers WHERE project_id = :pid AND is_deleted = 0 ORDER BY id ASC");
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll();
        } catch (Exception $e) {
            $rows = array();
        }
        $cache[$cacheKey] = $rows;
        return $rows;
    }
}

if (!function_exists('cpms_ensure_project_labor_worker_months_table')) {
    function cpms_ensure_project_labor_worker_months_table($pdo) {
        if (!$pdo) return false;
        static $ensured = array();
        $ensureKey = cpms_labor_cache_key($pdo, 'project-labor-worker-months-schema');
        if (isset($ensured[$ensureKey])) return $ensured[$ensureKey];
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_labor_worker_months (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                labor_worker_id INT UNSIGNED NOT NULL,
                month CHAR(7) NOT NULL,
                outsourcing_ratio TINYINT UNSIGNED NOT NULL DEFAULT 0,
                outsourcing_ratio_is_set TINYINT(1) NOT NULL DEFAULT 0,
                outsourcing_start_date DATE NULL,
                outsourcing_end_date DATE NULL,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_project_labor_worker_month (project_id, labor_worker_id, month),
                KEY idx_project_labor_worker_month_lookup(project_id, month, is_deleted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

            // 파일: app/views/construction/tabs/partials/labor_data_loader.php
            // MySQL 5.6에는 조건부 컬럼 추가 문법이 없으므로 컬럼 목록을 확인한 뒤 보강합니다.
            $table = 'cpms_project_labor_worker_months';
            $cols = cpms_table_columns($pdo, $table);
            $colMap = array();
            foreach ($cols as $col) $colMap[(string)$col] = true;
            if (!isset($colMap['outsourcing_ratio'])) {
                $pdo->exec("ALTER TABLE cpms_project_labor_worker_months ADD COLUMN outsourcing_ratio TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER month");
            }
            if (!isset($colMap['outsourcing_ratio_is_set'])) {
                $pdo->exec("ALTER TABLE cpms_project_labor_worker_months ADD COLUMN outsourcing_ratio_is_set TINYINT(1) NOT NULL DEFAULT 0 AFTER outsourcing_ratio");
            }
            if (!isset($colMap['outsourcing_start_date'])) {
                $pdo->exec("ALTER TABLE cpms_project_labor_worker_months ADD COLUMN outsourcing_start_date DATE NULL AFTER outsourcing_ratio_is_set");
            }
            if (!isset($colMap['outsourcing_end_date'])) {
                $pdo->exec("ALTER TABLE cpms_project_labor_worker_months ADD COLUMN outsourcing_end_date DATE NULL AFTER outsourcing_start_date");
            }
            $ensured[$ensureKey] = true;
            return true;
        } catch (Exception $e) {
            $ensured[$ensureKey] = false;
            return false;
        }
    }
}

// 기존 이진 외주값을 월별 비율 도입 전 호환 비율(0%/100%)로 변환합니다.
if (!function_exists('cpms_labor_legacy_outsourcing_ratio')) {
    function cpms_labor_legacy_outsourcing_ratio($worker) {
        if (!is_array($worker)) return 0;
        if (array_key_exists('legacy_outsourcing_ratio', $worker) && $worker['legacy_outsourcing_ratio'] !== null && $worker['legacy_outsourcing_ratio'] !== '') {
            $legacyRatio = (int)$worker['legacy_outsourcing_ratio'];
            if ($legacyRatio < 0) $legacyRatio = 0;
            if ($legacyRatio > 100) $legacyRatio = 100;
            return $legacyRatio;
        }
        return (isset($worker['is_outsourcing']) && (int)$worker['is_outsourcing'] === 1) ? 100 : 0;
    }
}

// 프로젝트·근로자·적용 월 기준의 유효 외주비 비율을 불러옵니다.
if (!function_exists('cpms_load_project_labor_worker_month_ratio_map')) {
    function cpms_load_project_labor_worker_month_ratio_map($pdo, $projectId, $month, $projectWorkers) {
        $map = array();
        $projectId = (int)$projectId;
        $month = trim((string)$month);
        if (!is_array($projectWorkers)) $projectWorkers = array();

        // 월별 값이 없는 기존 데이터는 보존된 호환 기준값 또는 is_outsourcing 값으로 계산합니다.
        foreach ($projectWorkers as $worker) {
            $workerId = isset($worker['id']) ? (int)$worker['id'] : 0;
            if ($workerId <= 0) continue;
            $map[$workerId] = cpms_labor_legacy_outsourcing_ratio($worker);
        }

        if (!$pdo || $projectId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) return $map;
        if (!cpms_ensure_project_labor_worker_months_table($pdo)) return $map;
        try {
            $st = $pdo->prepare("SELECT labor_worker_id, outsourcing_ratio, outsourcing_ratio_is_set, outsourcing_start_date, outsourcing_end_date
                                 FROM cpms_project_labor_worker_months
                                 WHERE project_id = :pid
                                   AND month = :month
                                   AND is_deleted = 0");
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->bindValue(':month', $month);
            $st->execute();
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $workerId = isset($row['labor_worker_id']) ? (int)$row['labor_worker_id'] : 0;
                $isSet = isset($row['outsourcing_ratio_is_set']) && (int)$row['outsourcing_ratio_is_set'] === 1;
                if ($workerId <= 0 || !$isSet) continue;
                $ratio = isset($row['outsourcing_ratio']) ? (int)$row['outsourcing_ratio'] : 0;
                if ($ratio < 0) $ratio = 0;
                if ($ratio > 100) $ratio = 100;
                $map[$workerId] = $ratio;
                if (!isset($map['_settings'])) $map['_settings'] = array();
                $map['_settings'][$workerId] = array(
                    'outsourcing_start_date'=>isset($row['outsourcing_start_date']) ? (string)$row['outsourcing_start_date'] : '',
                    'outsourcing_end_date'=>isset($row['outsourcing_end_date']) ? (string)$row['outsourcing_end_date'] : ''
                );
            }
        } catch (Exception $e) {
            // 기존 호환값으로 안전하게 계속 표시합니다.
        }
        return $map;
    }
}

// 월별 비율 맵을 기존 프로젝트 근로자 배열에 안전하게 덧붙입니다.
if (!function_exists('cpms_apply_project_labor_worker_month_ratios')) {
    function cpms_apply_project_labor_worker_month_ratios($projectWorkers, $ratioMap) {
        if (!is_array($projectWorkers)) return array();
        if (!is_array($ratioMap)) $ratioMap = array();
        foreach ($projectWorkers as $index => $worker) {
            $workerId = isset($worker['id']) ? (int)$worker['id'] : 0;
            $ratio = ($workerId > 0 && isset($ratioMap[$workerId])) ? (int)$ratioMap[$workerId] : cpms_labor_legacy_outsourcing_ratio($worker);
            if ($ratio < 0) $ratio = 0;
            if ($ratio > 100) $ratio = 100;
            $projectWorkers[$index]['outsourcing_ratio'] = $ratio;
            $projectWorkers[$index]['labor_ratio'] = 100 - $ratio;
            $settings = isset($ratioMap['_settings']) && is_array($ratioMap['_settings']) && $workerId > 0 && isset($ratioMap['_settings'][$workerId]) ? $ratioMap['_settings'][$workerId] : array();
            $projectWorkers[$index]['outsourcing_start_date'] = isset($settings['outsourcing_start_date']) ? (string)$settings['outsourcing_start_date'] : '';
            $projectWorkers[$index]['outsourcing_end_date'] = isset($settings['outsourcing_end_date']) ? (string)$settings['outsourcing_end_date'] : '';
        }
        return $projectWorkers;
    }
}

// 서버 검증을 통과한 월별 외주비 비율을 월 연결 행에 저장합니다.
if (!function_exists('cpms_save_project_labor_worker_month_ratio')) {
    function cpms_save_project_labor_worker_month_ratio($pdo, $projectId, $laborWorkerId, $month, $outsourcingRatio, $outsourcingStartDate, $outsourcingEndDate) {
        $projectId = (int)$projectId;
        $laborWorkerId = (int)$laborWorkerId;
        $month = trim((string)$month);
        if (!is_numeric($outsourcingRatio)) return false;
        $outsourcingRatio = (int)$outsourcingRatio;
        if (!$pdo || $projectId <= 0 || $laborWorkerId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) return false;
        if ($outsourcingRatio < 0 || $outsourcingRatio > 100) return false;
        $outsourcingStartDate = trim((string)$outsourcingStartDate);
        $outsourcingEndDate = trim((string)$outsourcingEndDate);
        if (($outsourcingStartDate === '') !== ($outsourcingEndDate === '')) return false;
        if ($outsourcingStartDate !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $outsourcingStartDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $outsourcingEndDate)) return false;
            if (strpos($outsourcingStartDate, $month . '-') !== 0 || strpos($outsourcingEndDate, $month . '-') !== 0) return false;
            if ($outsourcingStartDate > $outsourcingEndDate) return false;
        }
        if (!cpms_ensure_project_labor_worker_months_table($pdo)) return false;
        try {
            $now = date('Y-m-d H:i:s');
            $st = $pdo->prepare("INSERT INTO cpms_project_labor_worker_months
                    (project_id, labor_worker_id, month, outsourcing_ratio, outsourcing_ratio_is_set, outsourcing_start_date, outsourcing_end_date, is_deleted, created_at, updated_at)
                    SELECT :pid, plw.id, :month, :ratio, 1, :start_date, :end_date, 0, :now, :now
                    FROM cpms_project_labor_workers plw
                    WHERE plw.id = :wid
                      AND plw.project_id = :pid_check
                      AND plw.is_deleted = 0
                    ON DUPLICATE KEY UPDATE outsourcing_ratio = VALUES(outsourcing_ratio),
                                            outsourcing_ratio_is_set = 1,
                                            outsourcing_start_date = VALUES(outsourcing_start_date),
                                            outsourcing_end_date = VALUES(outsourcing_end_date),
                                            is_deleted = 0,
                                            updated_at = VALUES(updated_at)");
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->bindValue(':pid_check', $projectId, PDO::PARAM_INT);
            $st->bindValue(':wid', $laborWorkerId, PDO::PARAM_INT);
            $st->bindValue(':month', $month);
            $st->bindValue(':ratio', $outsourcingRatio, PDO::PARAM_INT);
            $st->bindValue(':start_date', $outsourcingStartDate === '' ? null : $outsourcingStartDate, $outsourcingStartDate === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $st->bindValue(':end_date', $outsourcingEndDate === '' ? null : $outsourcingEndDate, $outsourcingEndDate === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $st->bindValue(':now', $now);
            $st->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

// 임금단가를 적용 월 기준으로 보존하여 이후 월의 인상분이 과거 월에 소급되지 않게 합니다.
if (!function_exists('cpms_ensure_project_labor_worker_wages_table')) {
    function cpms_ensure_project_labor_worker_wages_table($pdo) {
        if (!$pdo) return false;
        static $ensured = array();
        $key = cpms_labor_cache_key($pdo, 'project-labor-worker-wages-schema');
        if (isset($ensured[$key])) return $ensured[$key];
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_labor_worker_wages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                labor_worker_id INT UNSIGNED NOT NULL,
                effective_month CHAR(7) NOT NULL,
                daily_wage INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_project_labor_worker_wage (project_id, labor_worker_id, effective_month),
                KEY idx_project_labor_worker_wage_lookup(project_id, effective_month, labor_worker_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $ensured[$key] = true;
            return true;
        } catch (Exception $e) {
            $ensured[$key] = false;
            return false;
        }
    }
}

if (!function_exists('cpms_load_project_labor_worker_wage_map')) {
    function cpms_load_project_labor_worker_wage_map($pdo, $projectId, $month) {
        $map = array();
        $projectId = (int)$projectId;
        $month = trim((string)$month);
        if (!$pdo || $projectId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) return $map;
        if (!cpms_ensure_project_labor_worker_wages_table($pdo)) return $map;
        try {
            $st = $pdo->prepare("SELECT wage.labor_worker_id, wage.daily_wage
                                 FROM cpms_project_labor_worker_wages wage
                                 INNER JOIN (
                                     SELECT labor_worker_id, MAX(effective_month) AS effective_month
                                     FROM cpms_project_labor_worker_wages
                                     WHERE project_id = :pid_inner
                                       AND effective_month <= :month_inner
                                     GROUP BY labor_worker_id
                                 ) latest
                                   ON latest.labor_worker_id = wage.labor_worker_id
                                  AND latest.effective_month = wage.effective_month
                                 WHERE wage.project_id = :pid");
            $st->bindValue(':pid_inner', $projectId, PDO::PARAM_INT);
            $st->bindValue(':month_inner', $month);
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->execute();
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $workerId = isset($row['labor_worker_id']) ? (int)$row['labor_worker_id'] : 0;
                if ($workerId > 0) $map[$workerId] = max(0, (int)$row['daily_wage']);
            }
        } catch (Exception $e) {
            return array();
        }
        return $map;
    }
}

if (!function_exists('cpms_apply_project_labor_worker_month_wages')) {
    function cpms_apply_project_labor_worker_month_wages($projectWorkers, $wageMap) {
        if (!is_array($projectWorkers)) return array();
        if (!is_array($wageMap)) $wageMap = array();
        foreach ($projectWorkers as $index => $worker) {
            $workerId = isset($worker['id']) ? (int)$worker['id'] : 0;
            if ($workerId <= 0 || !isset($wageMap[$workerId])) continue;
            $wage = max(0, (int)$wageMap[$workerId]);
            $projectWorkers[$index]['daily_wage_snapshot'] = $wage;
            $projectWorkers[$index]['deposit_rate'] = $wage;
            $projectWorkers[$index]['monthly_wage_snapshot'] = $wage;
        }
        return $projectWorkers;
    }
}

if (!function_exists('cpms_save_project_labor_worker_month_wage')) {
    function cpms_save_project_labor_worker_month_wage($pdo, $projectId, $laborWorkerId, $month, $newWage, $previousWage) {
        $result = array('saved' => false, 'is_latest' => false);
        $projectId = (int)$projectId;
        $laborWorkerId = (int)$laborWorkerId;
        $month = trim((string)$month);
        $newWage = max(0, (int)$newWage);
        $previousWage = max(0, (int)$previousWage);
        if (!$pdo || $projectId <= 0 || $laborWorkerId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) return $result;
        if (!cpms_ensure_project_labor_worker_wages_table($pdo)) return $result;
        try {
            $stOwner = $pdo->prepare("SELECT id FROM cpms_project_labor_workers WHERE id = :wid AND project_id = :pid AND is_deleted = 0 LIMIT 1");
            $stOwner->bindValue(':wid', $laborWorkerId, PDO::PARAM_INT);
            $stOwner->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stOwner->execute();
            if (!(int)$stOwner->fetchColumn()) return $result;

            $stMax = $pdo->prepare("SELECT MAX(effective_month) FROM cpms_project_labor_worker_wages WHERE project_id = :pid AND labor_worker_id = :wid");
            $stMax->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stMax->bindValue(':wid', $laborWorkerId, PDO::PARAM_INT);
            $stMax->execute();
            $latestMonth = trim((string)$stMax->fetchColumn());
            $now = date('Y-m-d H:i:s');

            if ($latestMonth === '') {
                $stBase = $pdo->prepare("INSERT INTO cpms_project_labor_worker_wages
                                         (project_id, labor_worker_id, effective_month, daily_wage, created_at, updated_at)
                                         VALUES (:pid, :wid, '1970-01', :wage, :now, :now)
                                         ON DUPLICATE KEY UPDATE daily_wage = VALUES(daily_wage), updated_at = VALUES(updated_at)");
                $stBase->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $stBase->bindValue(':wid', $laborWorkerId, PDO::PARAM_INT);
                $stBase->bindValue(':wage', $previousWage, PDO::PARAM_INT);
                $stBase->bindValue(':now', $now);
                $stBase->execute();
                $latestMonth = '1970-01';
            }

            $stSave = $pdo->prepare("INSERT INTO cpms_project_labor_worker_wages
                                     (project_id, labor_worker_id, effective_month, daily_wage, created_at, updated_at)
                                     VALUES (:pid, :wid, :month, :wage, :now, :now)
                                     ON DUPLICATE KEY UPDATE daily_wage = VALUES(daily_wage), updated_at = VALUES(updated_at)");
            $stSave->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stSave->bindValue(':wid', $laborWorkerId, PDO::PARAM_INT);
            $stSave->bindValue(':month', $month);
            $stSave->bindValue(':wage', $newWage, PDO::PARAM_INT);
            $stSave->bindValue(':now', $now);
            $stSave->execute();
            $result['saved'] = true;
            $result['is_latest'] = ($latestMonth === '' || $month >= $latestMonth);
        } catch (Exception $e) {
            return array('saved' => false, 'is_latest' => false);
        }
        return $result;
    }
}

// 근로자 배열에서 월별 비율을 우선하고 없으면 기존 호환값을 사용합니다.
if (!function_exists('cpms_resolve_worker_outsourcing_ratio')) {
    function cpms_resolve_worker_outsourcing_ratio($worker) {
        if (!is_array($worker)) return 0;
        if (array_key_exists('outsourcing_ratio', $worker) && $worker['outsourcing_ratio'] !== null && $worker['outsourcing_ratio'] !== '') {
            $ratio = (int)$worker['outsourcing_ratio'];
        } else {
            $ratio = cpms_labor_legacy_outsourcing_ratio($worker);
        }
        if ($ratio < 0) $ratio = 0;
        if ($ratio > 100) $ratio = 100;
        return $ratio;
    }
}

// 공수·단가·외주비 비율로 전체/노무비/외주비 금액을 동일한 원 단위 기준으로 계산합니다.
if (!function_exists('cpms_labor_calculate_amounts')) {
    function cpms_labor_calculate_amounts($totalGongsu, $wageRate, $outsourcingRatio) {
        $totalGongsu = is_numeric($totalGongsu) ? (float)$totalGongsu : 0.0;
        $wageRate = is_numeric($wageRate) ? (float)$wageRate : 0.0;
        $outsourcingRatio = is_numeric($outsourcingRatio) ? (int)$outsourcingRatio : 0;
        if ($totalGongsu < 0) $totalGongsu = 0.0;
        if ($wageRate < 0) $wageRate = 0.0;
        if ($outsourcingRatio < 0) $outsourcingRatio = 0;
        if ($outsourcingRatio > 100) $outsourcingRatio = 100;

        // 파일: app/views/construction/tabs/partials/labor_data_loader.php
        // 전체 금액과 외주비를 원 단위로 먼저 확정하고, 노무비는 차감 계산하여 합계 오차를 막습니다.
        $totalAmount = round($totalGongsu * $wageRate);
        $outsourcingAmount = round($totalAmount * $outsourcingRatio / 100);
        $laborAmount = $totalAmount - $outsourcingAmount;
        return array(
            'total_amount' => (float)$totalAmount,
            'outsourcing_ratio' => $outsourcingRatio,
            'labor_ratio' => 100 - $outsourcingRatio,
            'outsourcing_amount' => (float)$outsourcingAmount,
            'labor_amount' => (float)$laborAmount,
        );
    }
}

// 근로자 한 명의 선택 월 총공수와 배분 금액을 공통 형식으로 반환합니다.
if (!function_exists('cpms_labor_calculate_worker_month_amounts')) {
    function cpms_labor_calculate_worker_month_amounts($worker, $gongsuMap, $selectedMonth) {
        $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
        $workerKey = cpms_normalize_worker_key($workerName);
        $dailyMap = ($workerKey !== '' && isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey])) ? $gongsuMap[$workerKey] : array();
        $periodStart = preg_match('/^\d{4}-\d{2}$/', (string)$selectedMonth) ? $selectedMonth . '-01' : '';
        $periodEnd = $periodStart !== '' ? date('Y-m-t', strtotime($periodStart)) : '';
        return cpms_labor_calculate_worker_period_amounts($worker, $dailyMap, $periodStart, $periodEnd);
    }
}

// 근로자 한 명의 일별 공수에 월별 외주비 적용기간을 반영합니다.
if (!function_exists('cpms_labor_calculate_worker_period_amounts')) {
    function cpms_labor_calculate_worker_period_amounts($worker, $dailyMap, $periodStart, $periodEnd) {
        if (!is_array($worker)) $worker = array();
        if (!is_array($dailyMap)) $dailyMap = array();
        $periodStart = trim((string)$periodStart);
        $periodEnd = trim((string)$periodEnd);
        $outsourcingStart = isset($worker['outsourcing_start_date']) ? trim((string)$worker['outsourcing_start_date']) : '';
        $outsourcingEnd = isset($worker['outsourcing_end_date']) ? trim((string)$worker['outsourcing_end_date']) : '';
        $hasOutsourcingRange = preg_match('/^\d{4}-\d{2}-\d{2}$/', $outsourcingStart)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $outsourcingEnd)
            && $outsourcingStart <= $outsourcingEnd;
        $totalGongsu = 0.0;
        $outsourcingGongsu = 0.0;
        $totalOutputDays = 0;
        $outsourcingOutputDays = 0;
        foreach ($dailyMap as $dateKey => $gongsuValue) {
            if (!is_numeric($gongsuValue)) continue;
            $dateKey = (string)$dateKey;
            if ($periodStart !== '' && $dateKey < $periodStart) continue;
            if ($periodEnd !== '' && $dateKey > $periodEnd) continue;
            $gongsu = (float)$gongsuValue;
            if ($gongsu <= 0) continue;
            $totalGongsu += $gongsu;
            $totalOutputDays++;
            if (!$hasOutsourcingRange || ($dateKey >= $outsourcingStart && $dateKey <= $outsourcingEnd)) {
                $outsourcingGongsu += $gongsu;
                $outsourcingOutputDays++;
            }
        }
        $wageRate = cpms_resolve_labor_wage_rate($worker);
        $outsourcingRatio = cpms_resolve_worker_outsourcing_ratio($worker);
        $isMonthlySalary = isset($worker['salary_allocation_mode']) && (int)$worker['salary_allocation_mode'] === 1;
        $billingUnits = $isMonthlySalary ? $totalOutputDays : $totalGongsu;
        $outsourcingBillingUnits = $isMonthlySalary ? $outsourcingOutputDays : $outsourcingGongsu;
        $totalAmount = round($billingUnits * $wageRate);
        $outsourcingAmount = round($outsourcingBillingUnits * $wageRate * $outsourcingRatio / 100);
        if ($outsourcingAmount > $totalAmount) $outsourcingAmount = $totalAmount;
        return array(
            'total_gongsu'=>$totalGongsu,
            'outsourcing_gongsu'=>$outsourcingGongsu,
            'output_days'=>$totalOutputDays,
            'billing_units'=>$billingUnits,
            'wage_rate'=>$wageRate,
            'total_amount'=>(float)$totalAmount,
            'outsourcing_ratio'=>$outsourcingRatio,
            'labor_ratio'=>100 - $outsourcingRatio,
            'outsourcing_amount'=>(float)$outsourcingAmount,
            'labor_amount'=>(float)($totalAmount - $outsourcingAmount),
            'outsourcing_start_date'=>$hasOutsourcingRange ? $outsourcingStart : '',
            'outsourcing_end_date'=>$hasOutsourcingRange ? $outsourcingEnd : ''
        );
    }
}

if (!function_exists('cpms_assign_project_labor_worker_month')) {
    function cpms_assign_project_labor_worker_month($pdo, $projectId, $laborWorkerId, $month) {
        $projectId = (int)$projectId;
        $laborWorkerId = (int)$laborWorkerId;
        $month = trim((string)$month);
        if (!$pdo || $projectId <= 0 || $laborWorkerId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) return false;
        if (!cpms_ensure_project_labor_worker_months_table($pdo)) return false;
        try {
            $now = date('Y-m-d H:i:s');
            $st = $pdo->prepare("INSERT INTO cpms_project_labor_worker_months
                    (project_id, labor_worker_id, month, is_deleted, created_at, updated_at)
                    VALUES (:pid, :wid, :month, 0, :now, :now)
                    ON DUPLICATE KEY UPDATE is_deleted = 0, updated_at = VALUES(updated_at)");
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->bindValue(':wid', $laborWorkerId, PDO::PARAM_INT);
            $st->bindValue(':month', $month);
            $st->bindValue(':now', $now);
            $st->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cpms_load_project_labor_worker_month_map')) {
    function cpms_load_project_labor_worker_month_map($pdo, $projectId, $month) {
        $map = array();
        $projectId = (int)$projectId;
        $month = trim((string)$month);
        if (!$pdo || $projectId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) return $map;
        if (!cpms_ensure_project_labor_worker_months_table($pdo)) return $map;
        try {
            $st = $pdo->prepare("SELECT labor_worker_id
                                 FROM cpms_project_labor_worker_months
                                 WHERE project_id = :pid
                                   AND month = :month
                                   AND is_deleted = 0");
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->bindValue(':month', $month);
            $st->execute();
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = isset($row['labor_worker_id']) ? (int)$row['labor_worker_id'] : 0;
                if ($id > 0) $map[$id] = true;
            }
        } catch (Exception $e) {
            $map = array();
        }
        return $map;
    }
}

// 인원작성에서 특정 월에 등록된 프로젝트 인원과 표시용 기본정보를 불러옵니다.
if (!function_exists('cpms_load_project_labor_workers_for_month')) {
    function cpms_load_project_labor_workers_for_month($pdo, $projectId, $month) {
        $rows = array();
        $projectId = (int)$projectId;
        $month = trim((string)$month);
        if (!$pdo || $projectId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) return $rows;
        if (!cpms_ensure_project_labor_workers_table($pdo) || !cpms_ensure_project_labor_worker_months_table($pdo)) return $rows;
        try {
            $st = $pdo->prepare("SELECT plw.*
                                 FROM cpms_project_labor_worker_months pwm
                                 INNER JOIN cpms_project_labor_workers plw
                                         ON plw.id = pwm.labor_worker_id
                                        AND plw.project_id = pwm.project_id
                                 WHERE pwm.project_id = :pid
                                   AND pwm.month = :month
                                   AND pwm.is_deleted = 0
                                   AND plw.is_deleted = 0
                                 ORDER BY COALESCE(NULLIF(plw.agency_name_snapshot, ''), NULLIF(plw.company_name, ''), '창명건설') ASC,
                                          COALESCE(NULLIF(plw.worker_name_snapshot, ''), plw.name) ASC,
                                          plw.id ASC");
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->bindValue(':month', $month);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = array();
            $rows = cpms_apply_project_labor_worker_month_wages($rows, cpms_load_project_labor_worker_wage_map($pdo, $projectId, $month));
        } catch (Exception $e) {
            $rows = array();
        }
        return $rows;
    }
}

if (!function_exists('cpms_ensure_labor_force_adjustments_table')) {
    function cpms_ensure_labor_force_adjustments_table($pdo) {
        if (!$pdo) return false;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_labor_force_adjustments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                month CHAR(7) NOT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                memo VARCHAR(255) NULL,
                updated_by INT NULL,
                updated_by_name VARCHAR(100) NULL,
                updated_by_email VARCHAR(190) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_labor_force_project_month(project_id, month),
                KEY idx_labor_force_month(project_id, month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cpms_labor_force_parse_amount')) {
    function cpms_labor_force_parse_amount($value) {
        $raw = trim((string)$value);
        if ($raw === '') return 0.0;
        $raw = str_replace(',', '', $raw);
        $raw = preg_replace('/[^0-9.\-]/', '', $raw);
        if ($raw === '' || !is_numeric($raw)) return 0.0;
        $amount = (float)$raw;
        if ($amount < 0) $amount = 0.0;
        return $amount;
    }
}

if (!function_exists('cpms_labor_force_load')) {
    function cpms_labor_force_load($pdo, $projectId, $month) {
        $empty = array('id' => 0, 'amount' => 0.0, 'memo' => '', 'updated_at' => '', 'updated_by_name' => '');
        $projectId = (int)$projectId;
        $month = trim((string)$month);
        if (!$pdo || $projectId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) return $empty;
        if (!cpms_ensure_labor_force_adjustments_table($pdo)) return $empty;
        try {
            $st = $pdo->prepare("SELECT id, amount, memo, updated_at, updated_by_name
                                 FROM cpms_labor_force_adjustments
                                 WHERE project_id = :pid AND month = :month
                                 LIMIT 1");
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->bindValue(':month', $month);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return $empty;
            return array(
                'id' => isset($row['id']) ? (int)$row['id'] : 0,
                'amount' => isset($row['amount']) ? (float)$row['amount'] : 0.0,
                'memo' => isset($row['memo']) ? (string)$row['memo'] : '',
                'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : '',
                'updated_by_name' => isset($row['updated_by_name']) ? (string)$row['updated_by_name'] : '',
            );
        } catch (Exception $e) {
            return $empty;
        }
    }
}

if (!function_exists('cpms_labor_force_amount')) {
    function cpms_labor_force_amount($pdo, $projectId, $month) {
        $row = cpms_labor_force_load($pdo, $projectId, $month);
        return isset($row['amount']) ? (float)$row['amount'] : 0.0;
    }
}

if (!function_exists('cpms_labor_force_amount_between')) {
    function cpms_labor_force_amount_between($pdo, $projectId, $startDate, $endDate) {
        $projectId = (int)$projectId;
        if (!$pdo || $projectId <= 0) return 0.0;
        if (!cpms_ensure_labor_force_adjustments_table($pdo)) return 0.0;
        try {
            $startObj = new DateTime(substr((string)$startDate, 0, 7) . '-01');
            $endObj = new DateTime(substr((string)$endDate, 0, 7) . '-01');
        } catch (Exception $e) {
            return 0.0;
        }
        if ($startObj > $endObj) return 0.0;

        $months = array();
        $cursor = clone $startObj;
        while ($cursor <= $endObj) {
            $months[] = $cursor->format('Y-m');
            $cursor->modify('+1 month');
        }
        if (count($months) <= 0) return 0.0;

        $placeholders = array();
        foreach ($months as $idx => $ym) {
            $placeholders[] = ':m' . $idx;
        }
        try {
            $sql = "SELECT COALESCE(SUM(amount), 0)
                    FROM cpms_labor_force_adjustments
                    WHERE project_id = :pid
                      AND month IN (" . implode(',', $placeholders) . ")";
            $st = $pdo->prepare($sql);
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            foreach ($months as $idx => $ym) {
                $st->bindValue(':m' . $idx, $ym);
            }
            $st->execute();
            return (float)$st->fetchColumn();
        } catch (Exception $e) {
            return 0.0;
        }
    }
}

if (!function_exists('cpms_labor_worker_payload_from_workforce')) {
    function cpms_labor_worker_payload_from_workforce($worker, $sourceType, $matchedStatus) {
        if (!is_array($worker)) $worker = array();
        $name = isset($worker['name']) ? trim((string)$worker['name']) : '';
        $agencyName = isset($worker['agency_name']) ? trim((string)$worker['agency_name']) : '';
        $jobType = isset($worker['job_type']) ? trim((string)$worker['job_type']) : '';
        $dailyWage = isset($worker['daily_wage']) ? (int)$worker['daily_wage'] : 0;
        if ($dailyWage < 0) $dailyWage = 0;

        return array(
            'worker_id' => isset($worker['id']) ? (int)$worker['id'] : 0,
            'name' => $name,
            'resident_no' => isset($worker['resident_no_plain']) ? trim((string)$worker['resident_no_plain']) : '',
            'phone' => isset($worker['phone']) ? trim((string)$worker['phone']) : '',
            'address' => isset($worker['address']) ? trim((string)$worker['address']) : '',
            'deposit_rate' => $dailyWage,
            'bank_account' => isset($worker['bank_account_plain']) ? trim((string)$worker['bank_account_plain']) : '',
            'bank_name' => isset($worker['bank_name']) ? trim((string)$worker['bank_name']) : '',
            'account_holder' => isset($worker['account_holder']) ? trim((string)$worker['account_holder']) : '',
            'company_name' => $agencyName,
            'worker_name_snapshot' => $name,
            'agency_name_snapshot' => $agencyName,
            'job_type_snapshot' => $jobType,
            'daily_wage_snapshot' => $dailyWage,
            'source_type' => trim((string)$sourceType) !== '' ? trim((string)$sourceType) : 'manual',
            'matched_status' => trim((string)$matchedStatus) !== '' ? trim((string)$matchedStatus) : 'manual',
        );
    }
}

if (!function_exists('cpms_labor_match_workforce_by_name')) {
    function cpms_labor_match_workforce_by_name($pdo, $name) {
        if (!$pdo || trim((string)$name) === '') {
            return array('status' => 'not_found', 'worker' => null, 'workers' => array());
        }
        if (!cpms_labor_load_workforce_services()) {
            return array('status' => 'not_found', 'worker' => null, 'workers' => array());
        }
        try {
            $repo = new WorkerRepository($pdo);
            return $repo->matchWorker($name, '', '');
        } catch (Exception $e) {
            return array('status' => 'not_found', 'worker' => null, 'workers' => array());
        }
    }
}

if (!function_exists('cpms_sync_project_labor_workers_from_attendance')) {
    function cpms_sync_project_labor_workers_from_attendance($pdo, $projectId, $attendanceWorkers) {
        if (!$pdo || $projectId <= 0) return;
        if (!cpms_ensure_project_labor_workers_table($pdo)) return;
        if (!is_array($attendanceWorkers) || count($attendanceWorkers) === 0) return;

        $existing = array();
        try {
            $st = $pdo->prepare("SELECT id, name, is_deleted FROM cpms_project_labor_workers WHERE project_id = :pid");
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll();
            foreach ($rows as $row) {
                $name = isset($row['name']) ? (string)$row['name'] : '';
                $key = cpms_normalize_worker_key($name);
                if ($key === '') continue;
                $existing[$key] = array(
                    'id' => (int)$row['id'],
                    'is_deleted' => (int)$row['is_deleted'],
                );
            }
        } catch (Exception $e) {
            $existing = array();
        }

        foreach ($attendanceWorkers as $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $key = cpms_normalize_worker_key($name);
            if ($key === '') continue;
            if (isset($existing[$key])) continue;
            try {
                $now = date('Y-m-d H:i:s');
                $match = cpms_labor_match_workforce_by_name($pdo, $name);
                $matchedStatus = isset($match['status']) ? (string)$match['status'] : 'not_found';
                $payload = array(
                    'worker_id' => 0,
                    'name' => $name,
                    'phone' => '',
                    'address' => '',
                    'deposit_rate' => 0,
                    'company_name' => '',
                    'worker_name_snapshot' => $name,
                    'agency_name_snapshot' => '',
                    'job_type_snapshot' => '',
                    'daily_wage_snapshot' => 0,
                    'source_type' => 'shiftee',
                    'matched_status' => $matchedStatus === 'duplicate' ? 'duplicate' : 'not_found',
                );
                if ($matchedStatus === 'matched' && isset($match['worker']) && is_array($match['worker'])) {
                    $payload = cpms_labor_worker_payload_from_workforce($match['worker'], 'shiftee', 'matched');
                    if (trim((string)$payload['name']) === '') $payload['name'] = $name;
                }
                $stIns = $pdo->prepare("INSERT INTO cpms_project_labor_workers
                                        (project_id, name, source, direct_member_id, worker_id,
                                         worker_name_snapshot, agency_name_snapshot, job_type_snapshot, daily_wage_snapshot,
                                         source_type, matched_status, phone, address, deposit_rate, company_name,
                                         is_deleted, created_at, updated_at)
                                        VALUES (:pid, :name, 'attendance', NULL, :worker_id,
                                         :worker_name_snapshot, :agency_name_snapshot, :job_type_snapshot, :daily_wage_snapshot,
                                         :source_type, :matched_status, :phone, :address, :deposit_rate, :company_name,
                                         0, :now, :now)");
                $stIns->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $stIns->bindValue(':name', $payload['name']);
                if ((int)$payload['worker_id'] > 0) $stIns->bindValue(':worker_id', (int)$payload['worker_id'], PDO::PARAM_INT);
                else $stIns->bindValue(':worker_id', null, PDO::PARAM_NULL);
                $stIns->bindValue(':worker_name_snapshot', $payload['worker_name_snapshot']);
                $stIns->bindValue(':agency_name_snapshot', $payload['agency_name_snapshot']);
                $stIns->bindValue(':job_type_snapshot', $payload['job_type_snapshot']);
                $stIns->bindValue(':daily_wage_snapshot', (int)$payload['daily_wage_snapshot'], PDO::PARAM_INT);
                $stIns->bindValue(':source_type', $payload['source_type']);
                $stIns->bindValue(':matched_status', $payload['matched_status']);
                $stIns->bindValue(':phone', $payload['phone']);
                $stIns->bindValue(':address', $payload['address']);
                $stIns->bindValue(':deposit_rate', (int)$payload['deposit_rate'], PDO::PARAM_INT);
                $stIns->bindValue(':company_name', $payload['company_name']);
                $stIns->bindValue(':now', $now);
                $stIns->execute();
            } catch (Exception $e) {
                // ignore insert errors
            }
        }
    }
}

if (!function_exists('cpms_cleanup_project_labor_workers')) {
    function cpms_cleanup_project_labor_workers($pdo, $projectId, $excludedWorkers) {
        if (!$pdo || $projectId <= 0) return;
        if (!cpms_ensure_project_labor_workers_table($pdo)) return;
        if (!is_array($excludedWorkers) || count($excludedWorkers) === 0) return;

        $nameMap = array();
        foreach ($excludedWorkers as $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $key = cpms_normalize_worker_key($name);
            if ($key === '') continue;
            if (!isset($nameMap[$key])) $nameMap[$key] = $name;
        }
        if (count($nameMap) === 0) return;

        $names = array_values($nameMap);
        $placeholders = array();
        foreach ($names as $idx => $nm) {
            $placeholders[] = ':name' . $idx;
        }
        $sql = "UPDATE cpms_project_labor_workers
                   SET is_deleted = 1, updated_at = :now
                 WHERE project_id = :pid
                   AND source = 'attendance'
                   AND is_deleted = 0
                   AND name IN (" . implode(',', $placeholders) . ")";
        try {
            $st = $pdo->prepare($sql);
            $st->bindValue(':now', date('Y-m-d H:i:s'));
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            foreach ($names as $idx => $nm) {
                $st->bindValue(':name' . $idx, $nm);
            }
            $st->execute(); // 장비기사 기존 기록 삭제(soft delete)
        } catch (Exception $e) {
            // ignore cleanup errors
        }
    }
}

if (!function_exists('cpms_build_project_worker_rows')) {
    function cpms_build_project_worker_rows($projectWorkers, $directTeamMembers, $pdo = null, $selectedMonth = '') {
        $rows = array();
        if ($pdo && preg_match('/^\d{4}-\d{2}$/', (string)$selectedMonth)) {
            $directTeamMembers = cpms_apply_direct_team_salary_allocations($pdo, $directTeamMembers, $selectedMonth);
        }
        $directMap = array();
        foreach ($directTeamMembers as $member) {
            $id = isset($member['id']) ? (int)$member['id'] : 0;
            if ($id > 0) {
                $directMap[$id] = $member;
            }
        }

        foreach ($projectWorkers as $worker) {
            // 인원작성 저장 기능: 프로젝트 저장값을 우선 보존할 수 있도록 base data 구성
            $data = array(
                'name' => (isset($worker['worker_name_snapshot']) && trim((string)$worker['worker_name_snapshot']) !== '') ? (string)$worker['worker_name_snapshot'] : (isset($worker['name']) ? (string)$worker['name'] : ''),
                'worker_id' => isset($worker['worker_id']) ? (int)$worker['worker_id'] : 0,
                'resident_no' => isset($worker['resident_no']) ? (string)$worker['resident_no'] : '',
                'phone' => isset($worker['phone']) ? (string)$worker['phone'] : '',
                'address' => isset($worker['address']) ? (string)$worker['address'] : '',
                'deposit_rate' => (isset($worker['daily_wage_snapshot']) && (int)$worker['daily_wage_snapshot'] > 0) ? (string)(int)$worker['daily_wage_snapshot'] : (isset($worker['deposit_rate']) ? (string)$worker['deposit_rate'] : '0'),
                'daily_wage' => (isset($worker['daily_wage_snapshot']) && (int)$worker['daily_wage_snapshot'] > 0) ? (string)(int)$worker['daily_wage_snapshot'] : (isset($worker['daily_wage']) ? (string)$worker['daily_wage'] : ''),
                'bank_account' => isset($worker['bank_account']) ? (string)$worker['bank_account'] : '',
                'bank_name' => isset($worker['bank_name']) ? (string)$worker['bank_name'] : '',
                'account_holder' => isset($worker['account_holder']) ? (string)$worker['account_holder'] : '',
                'company_name' => (isset($worker['agency_name_snapshot']) && trim((string)$worker['agency_name_snapshot']) !== '') ? (string)$worker['agency_name_snapshot'] : (isset($worker['company_name']) ? (string)$worker['company_name'] : ''),
                'remark' => isset($worker['remark']) ? (string)$worker['remark'] : '',
                'is_outsourcing' => (isset($worker['is_outsourcing']) && (int)$worker['is_outsourcing'] === 1) ? 1 : 0,
                'legacy_outsourcing_ratio' => array_key_exists('legacy_outsourcing_ratio', $worker) ? $worker['legacy_outsourcing_ratio'] : null,
                'outsourcing_ratio' => function_exists('cpms_resolve_worker_outsourcing_ratio') ? cpms_resolve_worker_outsourcing_ratio($worker) : ((isset($worker['is_outsourcing']) && (int)$worker['is_outsourcing'] === 1) ? 100 : 0),
                'outsourcing_start_date' => isset($worker['outsourcing_start_date']) ? (string)$worker['outsourcing_start_date'] : '',
                'outsourcing_end_date' => isset($worker['outsourcing_end_date']) ? (string)$worker['outsourcing_end_date'] : '',
                'job_type_snapshot' => isset($worker['job_type_snapshot']) ? (string)$worker['job_type_snapshot'] : '',
                'agency_name_snapshot' => isset($worker['agency_name_snapshot']) ? (string)$worker['agency_name_snapshot'] : '',
                'worker_name_snapshot' => isset($worker['worker_name_snapshot']) ? (string)$worker['worker_name_snapshot'] : '',
                'daily_wage_snapshot' => isset($worker['daily_wage_snapshot']) ? (int)$worker['daily_wage_snapshot'] : 0,
                'source_type' => isset($worker['source_type']) ? (string)$worker['source_type'] : (isset($worker['source']) ? (string)$worker['source'] : 'manual'),
                'matched_status' => isset($worker['matched_status']) ? (string)$worker['matched_status'] : 'manual',
            );
            $directId = isset($worker['direct_member_id']) ? (int)$worker['direct_member_id'] : 0;
            if ($directId > 0 && isset($directMap[$directId])) {
                $memberData = $directMap[$directId];
                if (!is_array($memberData)) $memberData = array();
                // 직영팀 기본값 사용
                $merged = $memberData;
                if (!isset($merged['name']) || trim((string)$merged['name']) === '') {
                    $merged['name'] = isset($data['name']) ? $data['name'] : '';
                }
                // 인원작성 저장 기능: 프로젝트 저장값으로 최종 덮어쓰기
                foreach ($data as $field => $fieldValue) {
                    if ($field === 'name') continue;
                    if (trim((string)$fieldValue) !== '') $merged[$field] = $fieldValue;
                }
                $merged['direct_member_id'] = $directId;
                if (isset($memberData['salary_allocation_mode']) && (int)$memberData['salary_allocation_mode'] === 1) {
                    $merged['salary_allocation_mode'] = 1;
                    $merged['monthly_salary'] = isset($memberData['monthly_salary']) ? (int)$memberData['monthly_salary'] : 0;
                    $merged['salary_total_output_days'] = isset($memberData['salary_total_output_days']) ? (int)$memberData['salary_total_output_days'] : 0;
                    $merged['salary_daily_rate'] = isset($memberData['salary_daily_rate']) ? (float)$memberData['salary_daily_rate'] : 0.0;
                    $merged['salary_project_days'] = isset($memberData['salary_project_days']) ? $memberData['salary_project_days'] : array();
                    $merged['deposit_rate'] = $merged['salary_daily_rate'];
                    $merged['daily_wage'] = $merged['salary_daily_rate'];
                    $merged['is_outsourcing'] = 0;
                    $merged['legacy_outsourcing_ratio'] = 0;
                    $merged['outsourcing_ratio'] = 0;
                    $merged['outsourcing_start_date'] = '';
                    $merged['outsourcing_end_date'] = '';
                }
                $data = $merged;
            }
            if (!isset($data['daily_wage'])) {
                $data['daily_wage'] = '';
            }            
            $rows[] = array(
                'id' => isset($worker['id']) ? (int)$worker['id'] : 0,
                'source' => isset($worker['source']) ? (string)$worker['source'] : '',
                'data' => $data,
            );
        }

        return $rows;
    }
}

if (!function_exists('cpms_build_worker_rows')) {
    function cpms_build_worker_rows($directTeamMembers, $attendanceWorkers) {
        $rows = array();
        $nameMap = array();

        foreach ($directTeamMembers as $member) {
            $name = isset($member['name']) ? (string)$member['name'] : '';
            $key = cpms_normalize_worker_key($name);
            if ($key !== '') $nameMap[$key] = true;
            $rows[] = array('source' => 'direct', 'data' => $member);
        }

        foreach ($attendanceWorkers as $name) {
            $key = cpms_normalize_worker_key($name);
            if ($key === '' || isset($nameMap[$key])) continue;
            $rows[] = array('source' => 'attendance', 'data' => array('name' => $name));
            $nameMap[$key] = true;
        }

        return $rows;
    }
}

if (!function_exists('cpms_build_timesheet_workers')) {
    function cpms_build_timesheet_workers($workerRows) {
        $workers = array();
        foreach ($workerRows as $row) {
            $data = isset($row['data']) && is_array($row['data']) ? $row['data'] : array();
            $workers[count($workers)] = array(
                'worker_id' => isset($row['id']) ? (int)$row['id'] : 0,
                'master_worker_id' => isset($data['worker_id']) ? (int)$data['worker_id'] : 0,
                'source' => isset($row['source']) ? (string)$row['source'] : '',
                'direct_member_id' => isset($data['direct_member_id']) ? (int)$data['direct_member_id'] : 0,
                'name' => isset($data['name']) ? (string)$data['name'] : '',
                'resident_no' => isset($data['resident_no']) ? (string)$data['resident_no'] : '',
                'phone' => isset($data['phone']) ? (string)$data['phone'] : '',
                'address' => isset($data['address']) ? (string)$data['address'] : '',
                'deposit_rate' => isset($data['deposit_rate']) ? (string)$data['deposit_rate'] : '',
                'daily_wage' => isset($data['daily_wage']) ? (string)$data['daily_wage'] : '',                
                'bank_account' => isset($data['bank_account']) ? (string)$data['bank_account'] : '',
                'bank_name' => isset($data['bank_name']) ? (string)$data['bank_name'] : '',
                'account_holder' => isset($data['account_holder']) ? (string)$data['account_holder'] : '',
                'company_name' => (isset($data['company_name']) && trim((string)$data['company_name']) !== '') ? (string)$data['company_name'] : '창명건설',
                'remark' => isset($data['remark']) ? (string)$data['remark'] : '',
                'is_outsourcing' => (isset($data['is_outsourcing']) && (int)$data['is_outsourcing'] === 1) ? 1 : 0,
                'legacy_outsourcing_ratio' => array_key_exists('legacy_outsourcing_ratio', $data) ? $data['legacy_outsourcing_ratio'] : null,
                'outsourcing_ratio' => function_exists('cpms_resolve_worker_outsourcing_ratio') ? cpms_resolve_worker_outsourcing_ratio($data) : ((isset($data['is_outsourcing']) && (int)$data['is_outsourcing'] === 1) ? 100 : 0),
                'labor_ratio' => function_exists('cpms_resolve_worker_outsourcing_ratio') ? (100 - cpms_resolve_worker_outsourcing_ratio($data)) : ((isset($data['is_outsourcing']) && (int)$data['is_outsourcing'] === 1) ? 0 : 100),
                'outsourcing_start_date' => isset($data['outsourcing_start_date']) ? (string)$data['outsourcing_start_date'] : '',
                'outsourcing_end_date' => isset($data['outsourcing_end_date']) ? (string)$data['outsourcing_end_date'] : '',
                'job_type_snapshot' => isset($data['job_type_snapshot']) ? (string)$data['job_type_snapshot'] : '',
                'agency_name_snapshot' => isset($data['agency_name_snapshot']) ? (string)$data['agency_name_snapshot'] : '',
                'worker_name_snapshot' => isset($data['worker_name_snapshot']) ? (string)$data['worker_name_snapshot'] : '',
                'daily_wage_snapshot' => isset($data['daily_wage_snapshot']) ? (int)$data['daily_wage_snapshot'] : 0,
                'source_type' => isset($data['source_type']) ? (string)$data['source_type'] : 'manual',
                'matched_status' => isset($data['matched_status']) ? (string)$data['matched_status'] : 'manual',
                'salary_allocation_mode' => isset($data['salary_allocation_mode']) ? (int)$data['salary_allocation_mode'] : 0,
                'monthly_salary' => isset($data['monthly_salary']) ? (int)$data['monthly_salary'] : 0,
                'salary_total_output_days' => isset($data['salary_total_output_days']) ? (int)$data['salary_total_output_days'] : 0,
                'salary_daily_rate' => isset($data['salary_daily_rate']) ? (float)$data['salary_daily_rate'] : 0.0,
                'salary_project_days' => isset($data['salary_project_days']) && is_array($data['salary_project_days']) ? $data['salary_project_days'] : array(),
                'month_assigned' => ((isset($row['month_assigned']) && (int)$row['month_assigned'] === 1) || (isset($data['month_assigned']) && (int)$data['month_assigned'] === 1)) ? 1 : 0,
            );
        }
        return $workers;
    }
}

if (!function_exists('cpms_labor_worker_row_sort_value')) {
    function cpms_labor_worker_row_sort_value($row, $field) {
        $data = isset($row['data']) && is_array($row['data']) ? $row['data'] : array();
        if ($field === 'company') {
            $companyName = isset($data['company_name']) ? trim((string)$data['company_name']) : '';
            return cpms_labor_sort_text($companyName === '' ? '창명건설' : $companyName);
        }
        if ($field === 'name') return cpms_labor_sort_text(isset($data['name']) ? $data['name'] : '');
        if ($field === 'allocation') {
            return function_exists('cpms_resolve_worker_outsourcing_ratio') ? (int)cpms_resolve_worker_outsourcing_ratio($data) : 0;
        }
        if ($field === 'phone') return cpms_labor_sort_text(isset($data['phone']) ? $data['phone'] : '');
        if ($field === 'address') return cpms_labor_sort_text(isset($data['address']) ? $data['address'] : '');
        if ($field === 'job_type') return cpms_labor_sort_text(isset($data['job_type_snapshot']) ? $data['job_type_snapshot'] : '');
        if ($field === 'wage') {
            return function_exists('cpms_resolve_labor_wage_rate') ? (float)cpms_resolve_labor_wage_rate($data) : 0.0;
        }
        if ($field === 'bank_account') return cpms_labor_sort_text(isset($data['bank_account']) ? $data['bank_account'] : '');
        if ($field === 'bank_name') return cpms_labor_sort_text(isset($data['bank_name']) ? $data['bank_name'] : '');
        if ($field === 'account_holder') return cpms_labor_sort_text(isset($data['account_holder']) ? $data['account_holder'] : '');
        if ($field === 'remark') return cpms_labor_sort_text(isset($data['remark']) ? $data['remark'] : '');
        return '';
    }
}

if (!function_exists('cpms_sort_labor_worker_rows')) {
    function cpms_sort_labor_worker_rows($rows, $sort, $direction = 'asc') {
        if (!is_array($rows)) return array();
        $allowed = array('company', 'name', 'allocation', 'phone', 'address', 'job_type', 'wage', 'bank_account', 'bank_name', 'account_holder', 'remark');
        if (!in_array($sort, $allowed, true)) $sort = 'company';
        $direction = ($direction === 'desc') ? 'desc' : 'asc';
        $numeric = array('allocation', 'wage');
        usort($rows, function($a, $b) use ($sort, $direction, $numeric) {
            $isNumeric = in_array($sort, $numeric, true);
            $av = cpms_labor_worker_row_sort_value($a, $sort);
            $bv = cpms_labor_worker_row_sort_value($b, $sort);
            if ($isNumeric) {
                $af = (float)$av;
                $bf = (float)$bv;
                if (abs($af - $bf) > 0.0001) {
                    $result = ($af < $bf) ? -1 : 1;
                    return $direction === 'desc' ? ($result * -1) : $result;
                }
            } else {
                if ($av === '' && $bv !== '') return 1;
                if ($av !== '' && $bv === '') return -1;
                if ($av !== $bv) {
                    $result = strcmp($av, $bv);
                    return $direction === 'desc' ? ($result * -1) : $result;
                }
            }

            foreach (array('company', 'name', 'job_type') as $secondary) {
                if ($secondary === $sort) continue;
                $as = cpms_labor_worker_row_sort_value($a, $secondary);
                $bs = cpms_labor_worker_row_sort_value($b, $secondary);
                if ($as === '' && $bs !== '') return 1;
                if ($as !== '' && $bs === '') return -1;
                if ($as !== $bs) return strcmp($as, $bs);
            }

            $ai = isset($a['id']) ? (int)$a['id'] : 0;
            $bi = isset($b['id']) ? (int)$b['id'] : 0;
            if ($ai === $bi) return 0;
            return ($ai < $bi) ? -1 : 1;
        });
        return $rows;
    }
}

if (!function_exists('cpms_labor_sort_text')) {
    function cpms_labor_sort_text($value) {
        $value = trim((string)$value);
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }
        return $value;
    }
}

if (!function_exists('cpms_labor_sort_worker_value')) {
    function cpms_labor_sort_worker_value($worker, $field, $gongsuMap = array(), $outputDaysMap = array(), $selectedMonth = '') {
        if (!is_array($worker)) return '';
        $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
        $workerKey = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($workerName) : cpms_labor_sort_text($workerName);
        if ($field === 'job_type') {
            return isset($worker['job_type_snapshot']) ? cpms_labor_sort_text($worker['job_type_snapshot']) : '';
        }
        if ($field === 'output_days') {
            return ($workerKey !== '' && isset($outputDaysMap[$workerKey])) ? (int)$outputDaysMap[$workerKey] : 0;
        }
        if ($field === 'total_gongsu') {
            $total = 0.0;
            $dailyMap = ($workerKey !== '' && isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey])) ? $gongsuMap[$workerKey] : array();
            foreach ($dailyMap as $dateKey => $gongsuValue) {
                if (!is_numeric($gongsuValue)) continue;
                if ($selectedMonth !== '' && strpos((string)$dateKey, (string)$selectedMonth) !== 0) continue;
                $total += (float)$gongsuValue;
            }
            return $total;
        }
        if ($field === 'wage_rate') {
            if (function_exists('cpms_resolve_labor_wage_rate')) {
                return (float)cpms_resolve_labor_wage_rate($worker);
            }
            return isset($worker['deposit_rate']) ? (float)str_replace(',', '', (string)$worker['deposit_rate']) : 0.0;
        }
        if ($field === 'company') {
            return isset($worker['company_name']) ? cpms_labor_sort_text($worker['company_name']) : '';
        }
        if ($field === 'outsourcing_ratio') {
            return function_exists('cpms_resolve_worker_outsourcing_ratio') ? cpms_resolve_worker_outsourcing_ratio($worker) : 0;
        }
        if ($field === 'labor_ratio') {
            $outsourcingRatio = function_exists('cpms_resolve_worker_outsourcing_ratio') ? cpms_resolve_worker_outsourcing_ratio($worker) : 0;
            return 100 - $outsourcingRatio;
        }
        if ($field === 'labor_amount' || $field === 'outsourcing_amount') {
            if (function_exists('cpms_labor_calculate_worker_month_amounts')) {
                $amounts = cpms_labor_calculate_worker_month_amounts($worker, $gongsuMap, $selectedMonth);
                return isset($amounts[$field]) ? (float)$amounts[$field] : 0.0;
            }
            return 0.0;
        }
        return isset($worker['name']) ? cpms_labor_sort_text($worker['name']) : '';
    }
}

if (!function_exists('cpms_sort_labor_workers')) {
    function cpms_sort_labor_workers($workers, $sort, $direction = 'asc', $gongsuMap = array(), $outputDaysMap = array(), $selectedMonth = '') {
        if (!is_array($workers)) return array();
        $sort = trim((string)$sort);
        $allowedSorts = array('name', 'job_type', 'output_days', 'total_gongsu', 'wage_rate', 'company', 'labor_ratio', 'outsourcing_ratio', 'labor_amount', 'outsourcing_amount');
        if (!in_array($sort, $allowedSorts, true)) $sort = 'job_type';
        $direction = (trim((string)$direction) === 'desc') ? 'desc' : 'asc';
        $numericSorts = array('output_days', 'total_gongsu', 'wage_rate', 'labor_ratio', 'outsourcing_ratio', 'labor_amount', 'outsourcing_amount');
        usort($workers, function($a, $b) use ($sort, $direction, $gongsuMap, $outputDaysMap, $selectedMonth, $numericSorts) {
            // 안전담당자는 선택한 정렬 방향과 관계없이 항상 마지막에 배치합니다.
            $aJobType = isset($a['job_type_snapshot']) ? trim((string)$a['job_type_snapshot']) : '';
            $bJobType = isset($b['job_type_snapshot']) ? trim((string)$b['job_type_snapshot']) : '';
            $aIsSafetyManager = ($aJobType !== '' && strpos($aJobType, '안전담당자') !== false);
            $bIsSafetyManager = ($bJobType !== '' && strpos($bJobType, '안전담당자') !== false);
            if ($aIsSafetyManager && !$bIsSafetyManager) return 1;
            if (!$aIsSafetyManager && $bIsSafetyManager) return -1;

            $isNumeric = in_array($sort, $numericSorts, true);
            $av = cpms_labor_sort_worker_value($a, $sort, $gongsuMap, $outputDaysMap, $selectedMonth);
            $bv = cpms_labor_sort_worker_value($b, $sort, $gongsuMap, $outputDaysMap, $selectedMonth);
            if ($isNumeric) {
                $af = (float)$av;
                $bf = (float)$bv;
                if (abs($af - $bf) > 0.0001) {
                    $result = ($af < $bf) ? -1 : 1;
                    return ($direction === 'desc') ? ($result * -1) : $result;
                }
            } else {
                if ($av === '' && $bv !== '') return 1;
                if ($av !== '' && $bv === '') return -1;
                if ($av !== $bv) {
                    $result = strcmp($av, $bv);
                    return ($direction === 'desc') ? ($result * -1) : $result;
                }
            }

            $secondaryFields = array('name', 'job_type', 'company');
            foreach ($secondaryFields as $secondary) {
                if ($secondary === $sort) continue;
                $as = cpms_labor_sort_worker_value($a, $secondary, $gongsuMap, $outputDaysMap, $selectedMonth);
                $bs = cpms_labor_sort_worker_value($b, $secondary, $gongsuMap, $outputDaysMap, $selectedMonth);
                if ($as === '' && $bs !== '') return 1;
                if ($as !== '' && $bs === '') return -1;
                if ($as !== $bs) return strcmp($as, $bs);
            }

            $ai = isset($a['worker_id']) ? (int)$a['worker_id'] : 0;
            $bi = isset($b['worker_id']) ? (int)$b['worker_id'] : 0;
            if ($ai === $bi) return 0;
            return ($ai < $bi) ? -1 : 1;
        });
        return $workers;
    }
}


// 최신 승인 완료 행을 공수표에 반영하고, 구버전 덮어쓰기 건의 old_value 복원은 app/helpers.php cpms_load_labor_overrides()에서 처리합니다.
