<?php
/**
 * 공사 > 노무비 > 공수/인원작성 공통 데이터 로더
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

if (!function_exists('cpms_table_exists_labor')) {
    function cpms_table_exists_labor($pdo, $table) {
        try {
            $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($dbName === '') return false;
            $sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl";
            $st = $pdo->prepare($sql);
            $st->bindValue(':db', $dbName);
            $st->bindValue(':tbl', $table);
            $st->execute();
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cpms_table_columns')) {
    function cpms_table_columns($pdo, $table) {
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
            return $cols;
        } catch (Exception $e) {
            return array();
        }
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

if (!function_exists('cpms_find_gongsu_table')) {
    function cpms_find_gongsu_table($pdo) {
        $dbName = '';
        try {
            $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        } catch (Exception $e) {
            $dbName = '';
        }
        if ($dbName === '') return array();

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
            return array('table' => $table, 'columns' => $map);
        }
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
            'gongsu_map' => array(),
            'gongsu_unit' => array(),
            'output_days' => array(),
        );

        if (!$attendancePdo || $projectName === '' || $selectedMonth === '') return $result;

        // 테이블 존재 확인 (attendance, sites)
        if (!cpms_table_exists_labor($attendancePdo, 'sites') || !cpms_table_exists_labor($attendancePdo, 'attendance')) {
            return $result;
        }

        // 1) 프로젝트명과 같은 현장(site) 찾기
        $siteId = 0;
        try {
            $st = $attendancePdo->prepare("SELECT id FROM sites WHERE name = :name AND active = 1 ORDER BY id DESC LIMIT 1");
            $st->bindValue(':name', $projectName);
            $st->execute();
            $siteId = (int)$st->fetchColumn();
        } catch (Exception $e) {
            $siteId = 0;
        }
        if ($siteId <= 0) return $result;

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

        // 3) 출근기록 조회 (이름 목록 + 공수 자동 계산)
        $rows = array();
        try {
            $sql = "SELECT name, start_time_phone, stop_time_phone, total_minutes, status
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
        $outputDaysSet = array();     // key => [Y-m-d => true]
        $sumGongsu = array();         // key => float sum

        foreach ($rows as $row) {
            $name = isset($row['name']) ? trim((string)$row['name']) : '';
            if ($name === '') continue;

            $key = cpms_normalize_worker_key($name);
            if ($key === '') continue;

            // 3-1) 인원작성 탭용 이름 목록은 "기록이 존재하면" 일단 포함
            if (!isset($workers[$key])) $workers[$key] = $name;

            $startPhone = isset($row['start_time_phone']) ? (string)$row['start_time_phone'] : '';
            $stopPhone  = isset($row['stop_time_phone']) ? (string)$row['stop_time_phone'] : '';
            if ($startPhone === '') continue;

            // 날짜키(Y-m-d)
            $startTs = strtotime($startPhone);
            if ($startTs === false) continue;
            $dateKey = date('Y-m-d', $startTs);
            if (strpos($dateKey, $selectedMonth) !== 0) continue;

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

        // 4) 출력일수(=done인 날짜 수) 및 공수단위(평균공수) 구성
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
        $result['gongsu_map'] = $gongsuMap;
        $result['gongsu_unit'] = $gongsuUnit;
        $result['output_days'] = $outputDays;

        return $result;
    }
}

if (!function_exists('cpms_load_gongsu_data')) {
    function cpms_load_gongsu_data($pdo, $projectName, $selectedMonth) {
        $result = array(
            'workers' => array(),
            'gongsu_map' => array(),
            'gongsu_unit' => array(),
            'output_days' => array(),
        );

        $projectName = trim((string)$projectName);
        if ($projectName === '' || $selectedMonth === '') return $result;
        if (!$pdo) $pdo = null;

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
                } else {
                    // (현장명=프로젝트명) 기준으로 attendance 기록을 읽어서 공수/인원작성 자동매핑
                    return cpms_load_gongsu_data_from_attendance_records($attendancePdo, $projectName, $selectedMonth);
                }
            }
        }
        if (count($info) === 0 || !$pdo) return $result;

        $table = $info['table'];
        $cols = $info['columns'];

        $sql = "SELECT `" . $cols['site'] . "` AS site_name,
                       `" . $cols['name'] . "` AS worker_name,
                       `" . $cols['date'] . "` AS work_date,
                       `" . $cols['gongsu'] . "` AS gongsu_value";
        if (isset($cols['printed'])) {
            $sql .= ", `" . $cols['printed'] . "` AS printed_value";
        }
        $sql .= " FROM `" . $table . "` WHERE `" . $cols['site'] . "` = :site AND `" . $cols['date'] . "` LIKE :month";

        try {
            $st = $pdo->prepare($sql);
            $st->bindValue(':site', $projectName);
            $st->bindValue(':month', $selectedMonth . '%');
            $st->execute();
            $rows = $st->fetchAll();
        } catch (Exception $e) {
            $rows = array();
        }

        $workers = array();
        $gongsuMap = array();
        $gongsuUnit = array();
        $outputDays = array();

        foreach ($rows as $row) {
            $workerName = isset($row['worker_name']) ? trim((string)$row['worker_name']) : '';
            if ($workerName === '') continue;
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
            if (!isset($gongsuMap[$key])) $gongsuMap[$key] = array();

            $gongsuMap[$key][$workDate] = $gongsuValue;

            if (!isset($outputDays[$key])) $outputDays[$key] = 0;
            $outputDays[$key] += 1;

            if (!isset($gongsuUnit[$key]) && $gongsuValue > 0) {
                $gongsuUnit[$key] = $gongsuValue;
            }
        }

        $result['workers'] = array_values($workers);
        $result['gongsu_map'] = $gongsuMap;
        $result['gongsu_unit'] = $gongsuUnit;
        $result['output_days'] = $outputDays;

        return $result;
    }
}

if (!function_exists('cpms_load_direct_team_members')) {
    function cpms_load_direct_team_members($pdo) {
        $members = array();
        if (!$pdo) return $members;
        try {
            if (cpms_table_exists_labor($pdo, 'direct_team_members')) {
                $st = $pdo->prepare("SELECT * FROM direct_team_members ORDER BY id ASC");
                $st->execute();
                $members = $st->fetchAll();
            }
        } catch (Exception $e) {
            $members = array();
        }
        return $members;
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
            $workers[] = array(
                'name' => isset($data['name']) ? (string)$data['name'] : '',
                'resident_no' => isset($data['resident_no']) ? (string)$data['resident_no'] : '',
                'deposit_rate' => isset($data['deposit_rate']) ? (string)$data['deposit_rate'] : '',
                'bank_account' => isset($data['bank_account']) ? (string)$data['bank_account'] : '',
                'bank_name' => isset($data['bank_name']) ? (string)$data['bank_name'] : '',
                'account_holder' => isset($data['account_holder']) ? (string)$data['account_holder'] : '',
                'company_name' => '창명건설',
            );
        }
        return $workers;
    }
}
