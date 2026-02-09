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

if (!function_exists('cpms_create_pdo_from_config')) {
    function cpms_create_pdo_from_config($cfgFile) {
        if ($cfgFile === '' || !file_exists($cfgFile)) return null;
        $cfg = require $cfgFile;
        if (!is_array($cfg)) return null;

        $host = isset($cfg['host']) ? $cfg['host'] : '127.0.0.1';
        $port = isset($cfg['port']) ? (int)$cfg['port'] : 3306;
        $db   = isset($cfg['dbname']) ? $cfg['dbname'] : '';
        $user = isset($cfg['user']) ? $cfg['user'] : '';
        $pass = isset($cfg['pass']) ? $cfg['pass'] : '';
        $ch   = isset($cfg['charset']) ? $cfg['charset'] : 'utf8mb4';

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

if (!function_exists('cpms_load_attendance_pdo')) {
    function cpms_load_attendance_pdo() {
        static $attendancePdo = false;
        static $resolved = false;
        if ($resolved) return ($attendancePdo instanceof PDO) ? $attendancePdo : null;
        $resolved = true;

        $roots = array();
        $cpmsRoot = realpath(__DIR__ . '/../../../../..');
        if ($cpmsRoot) {
            $baseRoot = dirname($cpmsRoot);
            $roots[] = $baseRoot . '/attendance';
        }
        $roots[] = '/www/attendance';

        $configFiles = array(
            'app/config/database.php',
            'app/config/db.php',
            'config/database.php',
            'db.php',
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
            $attendancePdo = cpms_load_attendance_pdo();
            if ($attendancePdo) {
                $info = cpms_find_gongsu_table($attendancePdo);
                if (count($info) > 0) {
                    $pdo = $attendancePdo;
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