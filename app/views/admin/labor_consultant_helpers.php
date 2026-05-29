<?php

if (!function_exists('cpms_labor_consultant_template_type')) {
    function cpms_labor_consultant_template_type() {
        return 'labor_consultant';
    }
}

if (!function_exists('cpms_labor_consultant_current_ym')) {
    function cpms_labor_consultant_current_ym() {
        return date('Y-m');
    }
}

if (!function_exists('cpms_labor_consultant_normalize_project_filter')) {
    function cpms_labor_consultant_normalize_project_filter($projectId) {
        if ($projectId === 'all' || $projectId === '' || $projectId === null) return 'all';
        return (string)((int)$projectId);
    }
}

if (!function_exists('cpms_labor_consultant_normalize_ym')) {
    function cpms_labor_consultant_normalize_ym($ym) {
        $ym = trim((string)$ym);
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            return cpms_labor_consultant_current_ym();
        }
        return $ym;
    }
}

if (!function_exists('cpms_labor_consultant_days_in_month')) {
    function cpms_labor_consultant_days_in_month($ym) {
        $ym = cpms_labor_consultant_normalize_ym($ym);
        $ts = strtotime($ym . '-01');
        if ($ts === false) return 31;
        return (int)date('t', $ts);
    }
}

if (!function_exists('cpms_labor_consultant_month_range')) {
    function cpms_labor_consultant_month_range($ym) {
        $ym = cpms_labor_consultant_normalize_ym($ym);
        $start = $ym . '-01';
        $end = date('Y-m-t', strtotime($start));
        return array('start' => $start, 'end' => $end);
    }
}

if (!function_exists('cpms_labor_consultant_view_url')) {
    function cpms_labor_consultant_view_url($projectId, $ym, $section) {
        $projectId = cpms_labor_consultant_normalize_project_filter($projectId);
        $ym = cpms_labor_consultant_normalize_ym($ym);
        $section = trim((string)$section);
        if ($section === '') $section = 'consultant';
        return '?r=관리&tab=labor_calc&section=' . urlencode($section) . '&project_id=' . urlencode($projectId) . '&ym=' . urlencode($ym);
    }
}

if (!function_exists('cpms_labor_consultant_flash_redirect')) {
    function cpms_labor_consultant_flash_redirect($type, $message, $projectId, $ym, $section) {
        if (function_exists('flash_set')) {
            flash_set($type, $message);
        }
        header('Location: ' . cpms_labor_consultant_view_url($projectId, $ym, $section));
        exit;
    }
}

if (!function_exists('cpms_labor_consultant_can_access')) {
    function cpms_labor_consultant_can_access($pdo, $user) {
        if (\App\Core\Auth::isMaster()) return true;
        if (\App\Core\Auth::canManageEmployees()) return true;
        return cpms_is_management_department_user($pdo, $user);
    }
}

if (!function_exists('cpms_labor_consultant_table_exists')) {
    function cpms_labor_consultant_table_exists($pdo, $table) {
        if (!$pdo) return false;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
            $st->bindValue(':t', (string)$table);
            $st->execute();
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cpms_labor_consultant_index_exists')) {
    function cpms_labor_consultant_index_exists($pdo, $table, $indexName) {
        if (!$pdo) return false;
        try {
            $st = $pdo->prepare("SHOW INDEX FROM `" . $table . "` WHERE Key_name = :idx");
            $st->bindValue(':idx', (string)$indexName);
            $st->execute();
            return $st->fetch(PDO::FETCH_ASSOC) ? true : false;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cpms_labor_consultant_template_dir')) {
    function cpms_labor_consultant_template_dir() {
        return cpms_storage_root() . '/templates/labor';
    }
}

if (!function_exists('cpms_labor_consultant_template_history_dir')) {
    function cpms_labor_consultant_template_history_dir() {
        return cpms_labor_consultant_template_dir() . '/consultant';
    }
}

if (!function_exists('cpms_labor_consultant_template_public_path')) {
    function cpms_labor_consultant_template_public_path() {
        return 'storage/templates/labor/consultant';
    }
}

if (!function_exists('cpms_labor_consultant_resolve_stored_path')) {
    function cpms_labor_consultant_resolve_stored_path($storedPath) {
        $storedPath = trim((string)$storedPath);
        if ($storedPath === '') return '';
        if (preg_match('/^[A-Za-z]\:/', $storedPath)) return $storedPath;
        return dirname(dirname(dirname(__DIR__))) . '/' . ltrim(str_replace('\\', '/', $storedPath), '/');
    }
}

if (!function_exists('cpms_labor_consultant_ensure_template_table')) {
    function cpms_labor_consultant_ensure_template_table($pdo) {
        if (!$pdo) return false;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_labor_export_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_type VARCHAR(50) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                file_size INT NULL,
                uploaded_by INT NULL,
                uploaded_at DATETIME NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                INDEX idx_template_type (template_type),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if (!cpms_labor_consultant_index_exists($pdo, 'cpms_labor_export_templates', 'idx_template_type')) {
                $pdo->exec("ALTER TABLE cpms_labor_export_templates ADD INDEX idx_template_type (template_type)");
            }
            if (!cpms_labor_consultant_index_exists($pdo, 'cpms_labor_export_templates', 'idx_is_active')) {
                $pdo->exec("ALTER TABLE cpms_labor_export_templates ADD INDEX idx_is_active (is_active)");
            }
            return true;
        } catch (Exception $e) {
            error_log('[labor_consultant_setup] table ensure failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('cpms_labor_consultant_ensure_storage_dir')) {
    function cpms_labor_consultant_ensure_storage_dir() {
        $baseDir = cpms_labor_consultant_template_dir();
        $historyDir = cpms_labor_consultant_template_history_dir();
        if (!cpms_ensure_dir($baseDir)) return false;
        if (!cpms_ensure_dir($historyDir)) return false;
        return is_dir($historyDir) && is_writable($historyDir);
    }
}

if (!function_exists('cpms_labor_consultant_setup_status')) {
    function cpms_labor_consultant_setup_status($pdo, $autoCreate) {
        $rows = array();

        $tableOk = false;
        if ($autoCreate) {
            $tableOk = cpms_labor_consultant_ensure_template_table($pdo);
        } else {
            $tableOk = cpms_labor_consultant_table_exists($pdo, 'cpms_labor_export_templates');
        }
        $rows[count($rows)] = array(
            'kind' => 'TABLE',
            'target' => 'cpms_labor_export_templates',
            'status' => $tableOk ? '성공' : '오류',
            'message' => $tableOk ? '확인/생성 완료' : '테이블 확인 또는 생성에 실패했습니다.'
        );

        $folderTarget = 'storage/templates/labor';
        $folderOk = false;
        if ($autoCreate) {
            $folderOk = cpms_labor_consultant_ensure_storage_dir();
        } else {
            $folderOk = is_dir(cpms_labor_consultant_template_dir()) && is_dir(cpms_labor_consultant_template_history_dir()) && is_writable(cpms_labor_consultant_template_history_dir());
        }
        $rows[count($rows)] = array(
            'kind' => 'FOLDER',
            'target' => $folderTarget,
            'status' => $folderOk ? '성공' : '오류',
            'message' => $folderOk ? '생성/쓰기 가능' : '폴더 생성 또는 쓰기 권한을 확인해주세요.'
        );

        return $rows;
    }
}

if (!function_exists('cpms_labor_consultant_get_active_template')) {
    function cpms_labor_consultant_get_active_template($pdo) {
        if (!$pdo) return null;
        if (!cpms_labor_consultant_table_exists($pdo, 'cpms_labor_export_templates')) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_labor_export_templates WHERE template_type = :type AND is_active = 1 ORDER BY uploaded_at DESC, id DESC LIMIT 1");
            $st->bindValue(':type', cpms_labor_consultant_template_type());
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Exception $e) {
            error_log('[labor_consultant_template] active fetch failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('cpms_labor_consultant_list_projects')) {
    function cpms_labor_consultant_list_projects($pdo) {
        $rows = array();
        if (!$pdo) return $rows;
        try {
            $st = $pdo->query("SELECT * FROM cpms_projects ORDER BY name ASC, id ASC");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = array();
        } catch (Exception $e) {
            $rows = array();
        }
        return $rows;
    }
}

if (!function_exists('cpms_labor_consultant_find_project')) {
    function cpms_labor_consultant_find_project($pdo, $projectId) {
        if (!$pdo || $projectId <= 0) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id LIMIT 1");
            $st->bindValue(':id', (int)$projectId, PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('cpms_labor_consultant_project_label')) {
    function cpms_labor_consultant_project_label($projectId, $projects) {
        $projectId = cpms_labor_consultant_normalize_project_filter($projectId);
        if ($projectId === 'all') return '전체현장';
        $needle = (int)$projectId;
        if (is_array($projects)) {
            foreach ($projects as $row) {
                if ((int)(isset($row['id']) ? $row['id'] : 0) === $needle) {
                    return isset($row['name']) ? (string)$row['name'] : '현장';
                }
            }
        }
        return '현장';
    }
}

if (!function_exists('cpms_labor_consultant_project_manager_name')) {
    function cpms_labor_consultant_project_manager_name($pdo, $projectId) {
        $projectId = (int)$projectId;
        if (!$pdo || $projectId <= 0) return '';

        $employeeId = 0;
        try {
            if (cpms_labor_consultant_table_exists($pdo, 'cpms_construction_roles')) {
                $st = $pdo->prepare("SELECT * FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
                $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $st->execute();
                $roleRow = $st->fetch(PDO::FETCH_ASSOC);
                if ($roleRow && isset($roleRow['site_employee_id'])) {
                    $employeeId = (int)$roleRow['site_employee_id'];
                } else if ($roleRow && isset($roleRow['site_manager_id'])) {
                    $employeeId = (int)$roleRow['site_manager_id'];
                }
            }
        } catch (Exception $e) {
            $employeeId = 0;
        }

        if ($employeeId <= 0) {
            try {
                if (cpms_labor_consultant_table_exists($pdo, 'cpms_project_members')) {
                    $st = $pdo->prepare("SELECT employee_id FROM cpms_project_members WHERE project_id = :pid AND role = 'main' ORDER BY employee_id ASC LIMIT 1");
                    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
                    $st->execute();
                    $employeeId = (int)$st->fetchColumn();
                }
            } catch (Exception $e2) {
                $employeeId = 0;
            }
        }

        if ($employeeId <= 0) return '';

        try {
            $st = $pdo->prepare("SELECT name FROM employees WHERE id = :id LIMIT 1");
            $st->bindValue(':id', $employeeId, PDO::PARAM_INT);
            $st->execute();
            return trim((string)$st->fetchColumn());
        } catch (Exception $e3) {
            return '';
        }
    }
}

if (!function_exists('cpms_labor_consultant_direct_member_map')) {
    function cpms_labor_consultant_direct_member_map($directTeamMembers) {
        $map = array();
        if (!is_array($directTeamMembers)) return $map;
        foreach ($directTeamMembers as $member) {
            if (!is_array($member)) continue;
            $name = isset($member['name']) ? trim((string)$member['name']) : '';
            if ($name === '') continue;
            $key = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($name) : strtolower($name);
            if ($key === '') continue;
            $map[$key] = $member;
        }
        return $map;
    }
}

if (!function_exists('cpms_labor_consultant_worker_detail_value')) {
    function cpms_labor_consultant_worker_detail_value($worker, $directMap, $workerKey, $field, $default) {
        $value = '';
        if (is_array($worker) && isset($worker[$field])) {
            $value = trim((string)$worker[$field]);
        }
        if ($value === '' && is_array($directMap) && isset($directMap[$workerKey]) && is_array($directMap[$workerKey]) && isset($directMap[$workerKey][$field])) {
            $value = trim((string)$directMap[$workerKey][$field]);
        }
        if ($value === '') return $default;
        return $value;
    }
}

if (!function_exists('cpms_labor_consultant_load_project_month_rows')) {
    function cpms_labor_consultant_load_project_month_rows($pdo, $projectRow, $ym) {
        require_once __DIR__ . '/../construction/tabs/partials/labor_data_loader.php';

        $rows = array();
        if (!$pdo || !is_array($projectRow)) return $rows;

        $projectId = isset($projectRow['id']) ? (int)$projectRow['id'] : 0;
        $projectName = isset($projectRow['name']) ? trim((string)$projectRow['name']) : '';
        if ($projectId <= 0 || $projectName === '') return $rows;
        $projectManagerName = cpms_labor_consultant_project_manager_name($pdo, $projectId);

        $daysInMonth = cpms_labor_consultant_days_in_month($ym);
        $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
        $dataset = cpms_apply_labor_overrides_to_dataset(
            isset($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array(),
            isset($gongsuData['output_days']) ? $gongsuData['output_days'] : array(),
            isset($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array(),
            $projectId,
            $ym
        );

        $attendanceWorkers = array();
        if (isset($gongsuData['all_workers']) && is_array($gongsuData['all_workers']) && count($gongsuData['all_workers']) > 0) {
            $attendanceWorkers = $gongsuData['all_workers'];
        } else if (isset($gongsuData['workers']) && is_array($gongsuData['workers'])) {
            $attendanceWorkers = $gongsuData['workers'];
        }

        $excludedWorkers = isset($gongsuData['excluded_workers']) && is_array($gongsuData['excluded_workers']) ? $gongsuData['excluded_workers'] : array();
        cpms_cleanup_project_labor_workers($pdo, $projectId, $excludedWorkers);
        cpms_sync_project_labor_workers_from_attendance($pdo, $projectId, $attendanceWorkers);

        $projectWorkers = cpms_load_project_labor_workers($pdo, $projectId);
        $directTeamMembers = cpms_load_direct_team_members($pdo);
        $directMemberMap = cpms_labor_consultant_direct_member_map($directTeamMembers);
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directTeamMembers);
        $timesheetWorkers = cpms_build_timesheet_workers($workerRows);
        $roleMap = isset($gongsuData['role_map']) && is_array($gongsuData['role_map']) ? $gongsuData['role_map'] : array();
        $gongsuMap = isset($dataset['gongsu_map']) && is_array($dataset['gongsu_map']) ? $dataset['gongsu_map'] : array();

        foreach ($timesheetWorkers as $worker) {
            $workerName = isset($worker['name']) ? trim((string)$worker['name']) : '';
            if ($workerName === '') continue;

            $workerKey = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($workerName) : strtolower($workerName);
            $dailyMap = isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey]) ? $gongsuMap[$workerKey] : array();
            $days = array();
            $totalGongsu = 0.0;
            $outputDays = 0;

            $d = 1;
            while ($d <= $daysInMonth) {
                $dateKey = $ym . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
                $value = isset($dailyMap[$dateKey]) && is_numeric($dailyMap[$dateKey]) ? (float)$dailyMap[$dateKey] : 0.0;
                if ($value > 0) {
                    $days[$d] = round($value, 2);
                    $totalGongsu += (float)$days[$d];
                    $outputDays++;
                } else {
                    $days[$d] = '';
                }
                $d++;
            }

            if ($totalGongsu <= 0) continue;

            $wageRate = function_exists('cpms_resolve_labor_wage_rate') ? (float)cpms_resolve_labor_wage_rate($worker) : 0.0;
            $rows[count($rows)] = array(
                'project_id' => $projectId,
                'project_name' => $projectName,
                'project_start_date' => isset($projectRow['start_date']) ? (string)$projectRow['start_date'] : '',
                'project_end_date' => isset($projectRow['end_date']) ? (string)$projectRow['end_date'] : '',
                'project_manager_name' => $projectManagerName,
                'worker_name' => $workerName,
                'role' => isset($roleMap[$workerKey]) ? (string)$roleMap[$workerKey] : '',
                'phone' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'phone', ''),
                'address' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'address', ''),
                'resident_no' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'resident_no', ''),
                'account_holder' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'account_holder', ''),
                'bank_name' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'bank_name', ''),
                'bank_account' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'bank_account', ''),
                'company_name' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'company_name', '창명건설'),
                'subcontract_type' => '',
                'foreigner' => '',
                'wage_rate' => $wageRate,
                'work_days_count' => $outputDays,
                'output_days' => round($totalGongsu, 2),
                'total_gongsu' => round($totalGongsu, 2),
                'amount' => round($totalGongsu * $wageRate, 2),
                'days' => $days,
            );
        }

        return $rows;
    }
}

if (!function_exists('cpms_labor_consultant_load_view_data')) {
    function cpms_labor_consultant_load_view_data($pdo, $projectId, $ym) {
        $projectId = cpms_labor_consultant_normalize_project_filter($projectId);
        $ym = cpms_labor_consultant_normalize_ym($ym);
        $projects = cpms_labor_consultant_list_projects($pdo);
        $rows = array();
        $targets = array();
        $message = '';

        if ($projectId === 'all') {
            $targets = $projects;
        } else {
            $single = cpms_labor_consultant_find_project($pdo, (int)$projectId);
            if ($single) {
                $targets[count($targets)] = $single;
            }
        }

        foreach ($targets as $projectRow) {
            $projectRows = cpms_labor_consultant_load_project_month_rows($pdo, $projectRow, $ym);
            if (is_array($projectRows) && count($projectRows) > 0) {
                $rows = array_merge($rows, $projectRows);
            }
        }

        usort($rows, function($a, $b) {
            $p1 = isset($a['project_name']) ? (string)$a['project_name'] : '';
            $p2 = isset($b['project_name']) ? (string)$b['project_name'] : '';
            if ($p1 !== $p2) return strcmp($p1, $p2);
            $n1 = isset($a['worker_name']) ? (string)$a['worker_name'] : '';
            $n2 = isset($b['worker_name']) ? (string)$b['worker_name'] : '';
            return strcmp($n1, $n2);
        });

        if (count($rows) === 0) {
            $message = '선택한 기간에 노무비 데이터가 없습니다.';
        }

        return array(
            'project_id' => $projectId,
            'ym' => $ym,
            'days_in_month' => cpms_labor_consultant_days_in_month($ym),
            'projects' => $projects,
            'rows' => $rows,
            'message' => $message,
        );
    }
}

if (!function_exists('cpms_labor_consultant_safe_file_part')) {
    function cpms_labor_consultant_safe_file_part($value) {
        $value = trim((string)$value);
        if ($value === '') return '현장';
        $value = preg_replace('/[\\\\\/\:\*\?\"\<\>\|]+/u', '_', $value);
        $value = preg_replace('/\s+/u', '_', $value);
        $value = trim((string)$value, '_');
        return ($value === '') ? '현장' : $value;
    }
}

if (!function_exists('cpms_labor_consultant_download_name')) {
    function cpms_labor_consultant_download_name($projectLabel, $ym) {
        $safeProject = cpms_labor_consultant_safe_file_part($projectLabel);
        return '노무사확인용_노무비_' . $safeProject . '_' . cpms_labor_consultant_normalize_ym($ym) . '.xlsx';
    }
}

if (!function_exists('cpms_labor_consultant_render_message_page')) {
    function cpms_labor_consultant_render_message_page($message) {
        $message = trim((string)$message);
        if ($message === '') $message = '처리할 수 없습니다.';
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>노무사 확인용 엑셀</title>';
        echo '<style>body{font-family:Arial,Apple SD Gothic Neo,Malgun Gothic,sans-serif;padding:32px;background:#f8fafc;color:#111827}';
        echo '.box{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px}';
        echo 'a{display:inline-block;margin-top:16px;color:#2563eb;text-decoration:none;font-weight:700}</style></head><body>';
        echo '<div class="box"><h2 style="margin:0 0 12px 0;">노무사 확인용 엑셀</h2><div>' . h($message) . '</div>';
        echo '<a href="' . h(cpms_labor_consultant_view_url('all', cpms_labor_consultant_current_ym(), 'consultant')) . '">관리 화면으로 돌아가기</a></div>';
        echo '</body></html>';
        exit;
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_col_to_letter')) {
    function cpms_labor_consultant_xlsx_col_to_letter($index) {
        $index = (int)$index;
        if ($index <= 0) return '';
        $letters = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index = (int)(($index - 1) / 26);
        }
        return $letters;
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_ref_to_pos')) {
    function cpms_labor_consultant_xlsx_ref_to_pos($ref) {
        $ref = strtoupper(trim((string)$ref));
        if (!preg_match('/^([A-Z]+)([0-9]+)$/', $ref, $m)) return array(0, 0);
        $letters = $m[1];
        $row = (int)$m[2];
        $col = 0;
        $len = strlen($letters);
        $i = 0;
        while ($i < $len) {
            $col = ($col * 26) + (ord($letters[$i]) - 64);
            $i++;
        }
        return array($row, $col);
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_shared_strings')) {
    function cpms_labor_consultant_xlsx_shared_strings($zip) {
        $sharedStrings = array();
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') return $sharedStrings;
        $sx = @simplexml_load_string($xml);
        if (!$sx) return $sharedStrings;
        foreach ($sx->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } else if (isset($si->r)) {
                foreach ($si->r as $run) {
                    if (isset($run->t)) $text .= (string)$run->t;
                }
            }
            $sharedStrings[count($sharedStrings)] = $text;
        }
        return $sharedStrings;
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_first_sheet_path')) {
    function cpms_labor_consultant_xlsx_first_sheet_path($zip) {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $wb = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if (!$wb || !$rels || !isset($wb->sheets) || !isset($wb->sheets->sheet[0])) {
            return 'xl/worksheets/sheet1.xml';
        }

        $firstSheet = $wb->sheets->sheet[0];
        $attrs = $firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
        if ($rid === '') return 'xl/worksheets/sheet1.xml';

        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] !== $rid) continue;
            $target = (string)$rel['Target'];
            $target = ltrim(str_replace('\\', '/', $target), '/');
            if (strpos($target, 'xl/') === 0) return $target;
            return 'xl/' . $target;
        }

        return 'xl/worksheets/sheet1.xml';
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_cell_text')) {
    function cpms_labor_consultant_xlsx_cell_text($cellNode, $sharedStrings) {
        if (!$cellNode) return '';

        $t = $cellNode->getAttribute('t');
        if ($t === 's') {
            $vNodes = $cellNode->getElementsByTagName('v');
            if ($vNodes->length > 0) {
                $idx = (int)$vNodes->item(0)->nodeValue;
                return isset($sharedStrings[$idx]) ? (string)$sharedStrings[$idx] : '';
            }
            return '';
        }

        if ($t === 'inlineStr') {
            $isNodes = $cellNode->getElementsByTagName('is');
            if ($isNodes->length > 0) {
                return $isNodes->item(0)->textContent;
            }
        }

        $vNodes = $cellNode->getElementsByTagName('v');
        if ($vNodes->length > 0) {
            return (string)$vNodes->item(0)->nodeValue;
        }

        return $cellNode->textContent;
    }
}

if (!function_exists('cpms_labor_consultant_header_key')) {
    function cpms_labor_consultant_header_key($text) {
        $text = trim((string)$text);
        if ($text === '') return '';
        $text = str_replace(array("\r", "\n", "\t", ' '), '', $text);
        if (function_exists('mb_strtolower')) {
            $text = mb_strtolower($text, 'UTF-8');
        } else {
            $text = strtolower($text);
        }
        return $text;
    }
}

if (!function_exists('cpms_labor_consultant_header_match')) {
    function cpms_labor_consultant_header_match($headerKey) {
        if ($headerKey === '') return '';

        $map = array(
            '현장명' => 'project_name',
            '성명' => 'worker_name',
            '이름' => 'worker_name',
            '직종' => 'role',
            '역할' => 'role',
            '직무' => 'role',
            '단가' => 'wage_rate',
            '총공수' => 'total_gongsu',
            '공수합계' => 'total_gongsu',
            '공수' => 'total_gongsu',
            '지급액' => 'amount',
            '지급금액' => 'amount',
            '합계' => 'amount',
        );

        if (isset($map[$headerKey])) return $map[$headerKey];

        if (preg_match('/^([0-9]{1,2})일$/u', $headerKey, $m)) {
            $day = (int)$m[1];
            if ($day >= 1 && $day <= 31) return 'day_' . $day;
        }
        if (preg_match('/^[0-9]{1,2}$/', $headerKey)) {
            $day = (int)$headerKey;
            if ($day >= 1 && $day <= 31) return 'day_' . $day;
        }
        if (preg_match('/^([0-9]{1,2})\.0+$/', $headerKey, $m)) {
            $day = (int)$m[1];
            if ($day >= 1 && $day <= 31) return 'day_' . $day;
        }

        return '';
    }
}

if (!function_exists('cpms_labor_consultant_detect_headers')) {
    function cpms_labor_consultant_detect_headers($sheetDoc, $sharedStrings) {
        $xpath = new DOMXPath($sheetDoc);
        $rowNodes = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]');
        $best = array('row' => 0, 'score' => 0, 'columns' => array());

        if (!$rowNodes) return $best;

        foreach ($rowNodes as $rowNode) {
            $rowNum = (int)$rowNode->getAttribute('r');
            if ($rowNum <= 0 || $rowNum > 50) continue;

            $cols = array();
            $score = 0;
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) as $cellNode) {
                $ref = $cellNode->getAttribute('r');
                list(, $colIndex) = cpms_labor_consultant_xlsx_ref_to_pos($ref);
                if ($colIndex <= 0) continue;

                $text = cpms_labor_consultant_xlsx_cell_text($cellNode, $sharedStrings);
                $key = cpms_labor_consultant_header_key($text);
                $match = cpms_labor_consultant_header_match($key);
                if ($match === '') continue;

                $cols[$match] = $colIndex;
                $score++;
            }

            if (!isset($cols['worker_name'])) continue;
            if ($score > $best['score']) {
                $best = array('row' => $rowNum, 'score' => $score, 'columns' => $cols);
            }
        }

        return $best;
    }
}

if (!function_exists('cpms_labor_consultant_offset_formula_text_rows')) {
    function cpms_labor_consultant_offset_formula_text_rows($formula, $offset) {
        $offset = (int)$offset;
        if ($offset === 0 || $formula === '') return $formula;

        return preg_replace_callback('/(^|[^A-Za-z0-9_])(\$?[A-Z]{1,3})(\$?)([0-9]{1,7})(?![A-Za-z0-9_])/i', function($m) use ($offset) {
            $prefix = isset($m[1]) ? $m[1] : '';
            $col = isset($m[2]) ? $m[2] : '';
            $rowDollar = isset($m[3]) ? $m[3] : '';
            $row = isset($m[4]) ? (int)$m[4] : 0;
            if ($rowDollar !== '$' && $row > 0) $row += $offset;
            return $prefix . $col . $rowDollar . $row;
        }, (string)$formula);
    }
}

if (!function_exists('cpms_labor_consultant_formula_sheet_prefix')) {
    function cpms_labor_consultant_formula_sheet_prefix($sheetName) {
        $sheetName = str_replace("'", "''", (string)$sheetName);
        return "'" . $sheetName . "'!";
    }
}

if (!function_exists('cpms_labor_consultant_replace_formula_sheet_name')) {
    function cpms_labor_consultant_replace_formula_sheet_name($formula, $oldName, $newName) {
        $oldName = (string)$oldName;
        $newName = (string)$newName;
        if ($formula === '' || $oldName === '' || $newName === '' || $oldName === $newName) return $formula;

        $oldQuoted = cpms_labor_consultant_formula_sheet_prefix($oldName);
        $newQuoted = cpms_labor_consultant_formula_sheet_prefix($newName);
        $formula = str_replace($oldQuoted, $newQuoted, (string)$formula);
        $formula = str_replace($oldName . '!', $newQuoted, (string)$formula);
        return $formula;
    }
}

if (!function_exists('cpms_labor_consultant_update_sheet_formula_sheet_name')) {
    function cpms_labor_consultant_update_sheet_formula_sheet_name($sheetDoc, $oldName, $newName) {
        $oldName = (string)$oldName;
        $newName = (string)$newName;
        if ($oldName === '' || $newName === '' || $oldName === $newName) return;
        $xpath = new DOMXPath($sheetDoc);
        $formulaNodes = $xpath->query('//*[local-name()="f"]');
        foreach ($formulaNodes as $formulaNode) {
            if ($formulaNode->textContent === '') continue;
            $formulaNode->nodeValue = cpms_labor_consultant_replace_formula_sheet_name($formulaNode->textContent, $oldName, $newName);
        }
    }
}

if (!function_exists('cpms_labor_consultant_reindex_row_node')) {
    function cpms_labor_consultant_reindex_row_node($rowNode, $newRowNum) {
        $newRowNum = (int)$newRowNum;
        $oldRowNum = (int)$rowNode->getAttribute('r');
        $offset = $newRowNum - $oldRowNum;
        $rowNode->setAttribute('r', (string)$newRowNum);
        foreach ($rowNode->childNodes as $child) {
            if (!($child instanceof DOMElement)) continue;
            if ($child->localName !== 'c') continue;
            $ref = $child->getAttribute('r');
            list(, $colIndex) = cpms_labor_consultant_xlsx_ref_to_pos($ref);
            if ($colIndex <= 0) continue;
            $child->setAttribute('r', cpms_labor_consultant_xlsx_col_to_letter($colIndex) . $newRowNum);
            foreach ($child->childNodes as $formulaNode) {
                if (!($formulaNode instanceof DOMElement)) continue;
                if ($formulaNode->localName !== 'f') continue;
                if ($formulaNode->textContent !== '') {
                    $formulaNode->nodeValue = cpms_labor_consultant_offset_formula_text_rows($formulaNode->textContent, $offset);
                    if ($formulaNode->hasAttribute('t') && $formulaNode->getAttribute('t') === 'shared') {
                        $formulaNode->removeAttribute('t');
                        $formulaNode->removeAttribute('si');
                        $formulaNode->removeAttribute('ref');
                    }
                }
            }
        }
    }
}

if (!function_exists('cpms_labor_consultant_shift_sheet_rows')) {
    function cpms_labor_consultant_shift_sheet_rows($sheetDoc, $afterRow, $offset) {
        $afterRow = (int)$afterRow;
        $offset = (int)$offset;
        if ($offset <= 0) return;

        $xpath = new DOMXPath($sheetDoc);
        $rowNodes = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]');
        $rows = array();
        foreach ($rowNodes as $rowNode) {
            $rowNum = (int)$rowNode->getAttribute('r');
            if ($rowNum > $afterRow) {
                $rows[count($rows)] = $rowNode;
            }
        }

        usort($rows, function($a, $b) {
            $r1 = (int)$a->getAttribute('r');
            $r2 = (int)$b->getAttribute('r');
            if ($r1 === $r2) return 0;
            return ($r1 > $r2) ? -1 : 1;
        });

        foreach ($rows as $rowNode) {
            $rowNum = (int)$rowNode->getAttribute('r');
            cpms_labor_consultant_reindex_row_node($rowNode, $rowNum + $offset);
        }
    }
}

if (!function_exists('cpms_labor_consultant_shift_range_ref')) {
    function cpms_labor_consultant_shift_range_ref($ref, $afterRow, $offset) {
        $parts = explode(':', (string)$ref);
        $updated = array();
        foreach ($parts as $part) {
            if (!preg_match('/^([A-Z]+)([0-9]+)$/', strtoupper((string)$part), $m)) {
                $updated[count($updated)] = $part;
                continue;
            }
            $col = $m[1];
            $row = (int)$m[2];
            if ($row > $afterRow) $row += $offset;
            $updated[count($updated)] = $col . $row;
        }
        return implode(':', $updated);
    }
}

if (!function_exists('cpms_labor_consultant_shift_merged_cells')) {
    function cpms_labor_consultant_shift_merged_cells($sheetDoc, $afterRow, $offset) {
        if ($offset <= 0) return;
        $xpath = new DOMXPath($sheetDoc);
        $mergeNodes = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="mergeCells"]/*[local-name()="mergeCell"]');
        foreach ($mergeNodes as $mergeNode) {
            $ref = $mergeNode->getAttribute('ref');
            if ($ref === '') continue;
            $mergeNode->setAttribute('ref', cpms_labor_consultant_shift_range_ref($ref, $afterRow, $offset));
        }
    }
}

if (!function_exists('cpms_labor_consultant_shift_formula_text_rows')) {
    function cpms_labor_consultant_shift_formula_text_rows($formula, $afterRow, $offset) {
        $afterRow = (int)$afterRow;
        $offset = (int)$offset;
        if ($offset <= 0 || $formula === '') return $formula;

        return preg_replace_callback('/(^|[^A-Za-z0-9_])(\$?[A-Z]{1,3})(\$?)([0-9]{1,7})(?![A-Za-z0-9_])/i', function($m) use ($afterRow, $offset) {
            $prefix = isset($m[1]) ? $m[1] : '';
            $col = isset($m[2]) ? $m[2] : '';
            $rowDollar = isset($m[3]) ? $m[3] : '';
            $row = isset($m[4]) ? (int)$m[4] : 0;
            if ($row > $afterRow) $row += $offset;
            return $prefix . $col . $rowDollar . $row;
        }, (string)$formula);
    }
}

if (!function_exists('cpms_labor_consultant_shift_sheet_formula_refs')) {
    function cpms_labor_consultant_shift_sheet_formula_refs($sheetDoc, $afterRow, $offset) {
        $afterRow = (int)$afterRow;
        $offset = (int)$offset;
        if ($offset <= 0) return;

        $xpath = new DOMXPath($sheetDoc);
        $formulaNodes = $xpath->query('//*[local-name()="f"]');
        foreach ($formulaNodes as $formulaNode) {
            if ($formulaNode->hasAttribute('ref')) {
                $formulaNode->setAttribute('ref', cpms_labor_consultant_shift_range_ref($formulaNode->getAttribute('ref'), $afterRow, $offset));
            }
            if ($formulaNode->textContent !== '') {
                $formulaNode->nodeValue = cpms_labor_consultant_shift_formula_text_rows($formulaNode->textContent, $afterRow, $offset);
            }
        }
    }
}

if (!function_exists('cpms_labor_consultant_update_dimension')) {
    function cpms_labor_consultant_update_dimension($sheetDoc) {
        $xpath = new DOMXPath($sheetDoc);
        $rowNodes = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]');
        $maxRow = 1;
        $maxCol = 1;

        foreach ($rowNodes as $rowNode) {
            $rowNum = (int)$rowNode->getAttribute('r');
            if ($rowNum > $maxRow) $maxRow = $rowNum;
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) as $cellNode) {
                $ref = $cellNode->getAttribute('r');
                list(, $colIndex) = cpms_labor_consultant_xlsx_ref_to_pos($ref);
                if ($colIndex > $maxCol) $maxCol = $colIndex;
            }
        }

        $dimensionNodes = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="dimension"]');
        if ($dimensionNodes->length > 0) {
            $dimensionNodes->item(0)->setAttribute('ref', 'A1:' . cpms_labor_consultant_xlsx_col_to_letter($maxCol) . $maxRow);
        }
    }
}

if (!function_exists('cpms_labor_consultant_find_row_node')) {
    function cpms_labor_consultant_find_row_node($sheetDoc, $rowNum) {
        $xpath = new DOMXPath($sheetDoc);
        $nodeList = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"][@r="' . (int)$rowNum . '"]');
        if ($nodeList && $nodeList->length > 0) {
            return $nodeList->item(0);
        }
        return null;
    }
}

if (!function_exists('cpms_labor_consultant_prepare_target_rows')) {
    function cpms_labor_consultant_prepare_target_rows($sheetDoc, $headerRow, $dataCount) {
        $dataCount = (int)$dataCount;
        if ($dataCount <= 0) return array();

        $xpath = new DOMXPath($sheetDoc);
        $sheetDataList = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]');
        if (!$sheetDataList || $sheetDataList->length < 1) return array();
        $sheetData = $sheetDataList->item(0);

        $rowNodes = $xpath->query('./*[local-name()="row"]', $sheetData);
        $sampleRowNode = null;
        $sampleRowNum = (int)$headerRow + 1;

        foreach ($rowNodes as $rowNode) {
            $rowNum = (int)$rowNode->getAttribute('r');
            if ($rowNum >= $sampleRowNum) {
                $sampleRowNode = $rowNode;
                $sampleRowNum = $rowNum;
                break;
            }
        }

        if (!$sampleRowNode) {
            $headerRowNode = cpms_labor_consultant_find_row_node($sheetDoc, $headerRow);
            if (!$headerRowNode) return array();
            $sampleRowNode = $headerRowNode->cloneNode(true);
            cpms_labor_consultant_reindex_row_node($sampleRowNode, $headerRow + 1);
            if ($headerRowNode->nextSibling) {
                $sheetData->insertBefore($sampleRowNode, $headerRowNode->nextSibling);
            } else {
                $sheetData->appendChild($sampleRowNode);
            }
            $sampleRowNum = $headerRow + 1;
        }

        $offset = $dataCount - 1;
        cpms_labor_consultant_shift_sheet_rows($sheetDoc, $sampleRowNum, $offset);
        cpms_labor_consultant_shift_merged_cells($sheetDoc, $sampleRowNum, $offset);

        $targetRows = array();
        $targetRows[count($targetRows)] = $sampleRowNode;
        $insertAfter = $sampleRowNode;
        $i = 1;
        while ($i < $dataCount) {
            $clone = $sampleRowNode->cloneNode(true);
            cpms_labor_consultant_reindex_row_node($clone, $sampleRowNum + $i);
            if ($insertAfter->nextSibling) {
                $sheetData->insertBefore($clone, $insertAfter->nextSibling);
            } else {
                $sheetData->appendChild($clone);
            }
            $targetRows[count($targetRows)] = $clone;
            $insertAfter = $clone;
            $i++;
        }

        cpms_labor_consultant_update_dimension($sheetDoc);
        return $targetRows;
    }
}

if (!function_exists('cpms_labor_consultant_clear_cell_value')) {
    function cpms_labor_consultant_clear_cell_value($cellNode) {
        $remove = array();
        foreach ($cellNode->childNodes as $child) {
            if (!($child instanceof DOMElement)) continue;
            if ($child->localName === 'f') continue;
            $remove[count($remove)] = $child;
        }
        foreach ($remove as $node) {
            $cellNode->removeChild($node);
        }
        if ($cellNode->getAttribute('t') === 'inlineStr' || $cellNode->getAttribute('t') === 's') {
            $cellNode->removeAttribute('t');
        }
    }
}

if (!function_exists('cpms_labor_consultant_find_or_create_cell')) {
    function cpms_labor_consultant_find_or_create_cell($sheetDoc, $rowNode, $colIndex) {
        $colIndex = (int)$colIndex;
        if ($colIndex <= 0) return null;

        $targetRef = cpms_labor_consultant_xlsx_col_to_letter($colIndex) . $rowNode->getAttribute('r');
        $insertBefore = null;

        foreach ($rowNode->childNodes as $child) {
            if (!($child instanceof DOMElement)) continue;
            if ($child->localName !== 'c') continue;
            $ref = $child->getAttribute('r');
            list(, $existingCol) = cpms_labor_consultant_xlsx_ref_to_pos($ref);
            if ($existingCol === $colIndex) return $child;
            if ($existingCol > $colIndex) {
                $insertBefore = $child;
                break;
            }
        }

        $cellNode = $sheetDoc->createElement('c');
        $cellNode->setAttribute('r', $targetRef);

        if ($insertBefore) {
            $rowNode->insertBefore($cellNode, $insertBefore);
        } else {
            $rowNode->appendChild($cellNode);
        }

        return $cellNode;
    }
}

if (!function_exists('cpms_labor_consultant_set_cell_value')) {
    function cpms_labor_consultant_set_cell_value($sheetDoc, $rowNode, $colIndex, $value, $isNumeric) {
        $cellNode = cpms_labor_consultant_find_or_create_cell($sheetDoc, $rowNode, $colIndex);
        if (!$cellNode) return;

        $hasFormula = false;
        foreach ($cellNode->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'f') {
                $hasFormula = true;
                break;
            }
        }
        if ($hasFormula) {
            cpms_labor_consultant_clear_cell_value($cellNode);
            return;
        }

        $remove = array();
        foreach ($cellNode->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $remove[count($remove)] = $child;
            }
        }
        foreach ($remove as $childNode) {
            $cellNode->removeChild($childNode);
        }
        $cellNode->removeAttribute('t');

        if ($value === '' || $value === null) {
            return;
        }

        if ($isNumeric) {
            $vNode = $sheetDoc->createElement('v');
            $vNode->appendChild($sheetDoc->createTextNode((string)$value));
            $cellNode->appendChild($vNode);
            return;
        }

        $cellNode->setAttribute('t', 'inlineStr');
        $isNode = $sheetDoc->createElement('is');
        $tNode = $sheetDoc->createElement('t');
        $text = (string)$value;
        if (trim($text) !== $text) {
            $tNode->setAttribute('xml:space', 'preserve');
        }
        $tNode->appendChild($sheetDoc->createTextNode($text));
        $isNode->appendChild($tNode);
        $cellNode->appendChild($isNode);
    }
}

if (!function_exists('cpms_labor_consultant_fill_sheet_rows')) {
    function cpms_labor_consultant_fill_sheet_rows($sheetDoc, $headers, $dataRows) {
        $headerRow = isset($headers['row']) ? (int)$headers['row'] : 0;
        $columns = isset($headers['columns']) && is_array($headers['columns']) ? $headers['columns'] : array();
        if ($headerRow <= 0 || count($columns) === 0) return false;

        $targetRows = cpms_labor_consultant_prepare_target_rows($sheetDoc, $headerRow, count($dataRows));
        if (count($targetRows) !== count($dataRows)) return false;

        foreach ($targetRows as $idx => $rowNode) {
            $rowData = isset($dataRows[$idx]) ? $dataRows[$idx] : array();

            foreach ($rowNode->childNodes as $child) {
                if (!($child instanceof DOMElement)) continue;
                if ($child->localName !== 'c') continue;
                $ref = $child->getAttribute('r');
                list(, $colIndex) = cpms_labor_consultant_xlsx_ref_to_pos($ref);
                if ($colIndex <= 0) continue;

                $mapped = false;
                foreach ($columns as $columnKey => $columnIndex) {
                    if ((int)$columnIndex === $colIndex) {
                        $mapped = true;
                        break;
                    }
                }

                if ($mapped || $child->getElementsByTagName('f')->length === 0) {
                    cpms_labor_consultant_clear_cell_value($child);
                }
            }

            if (isset($columns['project_name'])) {
                cpms_labor_consultant_set_cell_value($sheetDoc, $rowNode, $columns['project_name'], isset($rowData['project_name']) ? $rowData['project_name'] : '', false);
            }
            if (isset($columns['worker_name'])) {
                cpms_labor_consultant_set_cell_value($sheetDoc, $rowNode, $columns['worker_name'], isset($rowData['worker_name']) ? $rowData['worker_name'] : '', false);
            }
            if (isset($columns['role'])) {
                cpms_labor_consultant_set_cell_value($sheetDoc, $rowNode, $columns['role'], isset($rowData['role']) ? $rowData['role'] : '', false);
            }
            if (isset($columns['wage_rate'])) {
                cpms_labor_consultant_set_cell_value($sheetDoc, $rowNode, $columns['wage_rate'], isset($rowData['wage_rate']) ? $rowData['wage_rate'] : '', true);
            }
            if (isset($columns['total_gongsu'])) {
                cpms_labor_consultant_set_cell_value($sheetDoc, $rowNode, $columns['total_gongsu'], isset($rowData['total_gongsu']) ? $rowData['total_gongsu'] : '', true);
            }
            if (isset($columns['amount'])) {
                cpms_labor_consultant_set_cell_value($sheetDoc, $rowNode, $columns['amount'], isset($rowData['amount']) ? $rowData['amount'] : '', true);
            }

            if (isset($rowData['days']) && is_array($rowData['days'])) {
                foreach ($rowData['days'] as $day => $value) {
                    $dayKey = 'day_' . (int)$day;
                    if (!isset($columns[$dayKey])) continue;
                    cpms_labor_consultant_set_cell_value($sheetDoc, $rowNode, $columns[$dayKey], $value, ($value !== ''));
                }
            }
        }

        cpms_labor_consultant_update_dimension($sheetDoc);
        return true;
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_sheet_list')) {
    function cpms_labor_consultant_xlsx_sheet_list($zip) {
        $sheets = array();
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) return $sheets;

        $wb = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if (!$wb || !$rels || !isset($wb->sheets)) return $sheets;

        $targets = array();
        foreach ($rels->Relationship as $rel) {
            $rid = (string)$rel['Id'];
            $target = (string)$rel['Target'];
            if ($rid === '' || $target === '') continue;
            $target = str_replace('\\', '/', $target);
            $target = ltrim($target, '/');
            if (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . $target;
            }
            $targets[$rid] = $target;
        }

        $idx = 0;
        foreach ($wb->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
            $name = (string)$sheet['name'];
            if ($rid === '' || !isset($targets[$rid])) {
                $idx++;
                continue;
            }
            $sheets[count($sheets)] = array(
                'name' => $name,
                'path' => $targets[$rid],
                'rid' => $rid,
                'index' => $idx
            );
            $idx++;
        }

        return $sheets;
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_find_sheet')) {
    function cpms_labor_consultant_xlsx_find_sheet($zip, $preferredName) {
        $sheets = cpms_labor_consultant_xlsx_sheet_list($zip);
        $fallback = null;
        foreach ($sheets as $sheet) {
            if (!$fallback) $fallback = $sheet;
            if ((string)$sheet['name'] === (string)$preferredName) return $sheet;
        }
        if ($fallback) return $fallback;
        return array('name' => '', 'path' => 'xl/worksheets/sheet1.xml', 'rid' => '', 'index' => 0);
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_set_active_sheet')) {
    function cpms_labor_consultant_xlsx_set_active_sheet($zip, $sheetIndex) {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false || $workbookXml === '') return;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        if (!@$doc->loadXML($workbookXml)) return;

        $sheetIndex = (int)$sheetIndex;
        $xpath = new DOMXPath($doc);
        $views = $xpath->query('//*[local-name()="workbookView"]');
        if ($views && $views->length > 0) {
            $views->item(0)->setAttribute('activeTab', (string)$sheetIndex);
            $views->item(0)->setAttribute('firstSheet', (string)$sheetIndex);
        }

        $idx = 0;
        $sheetNodes = $xpath->query('//*[local-name()="sheets"]/*[local-name()="sheet"]');
        foreach ($sheetNodes as $sheetNode) {
            if ($idx === $sheetIndex && $sheetNode->hasAttribute('state')) {
                $sheetNode->removeAttribute('state');
            }
            $idx++;
        }

        $zip->addFromString('xl/workbook.xml', $doc->saveXML());
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_force_recalc')) {
    function cpms_labor_consultant_xlsx_force_recalc($zip) {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false || $workbookXml === '') return;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        if (!@$doc->loadXML($workbookXml)) return;

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('/*[local-name()="workbook"]/*[local-name()="calcPr"]');
        if ($nodes && $nodes->length > 0) {
            $calcPr = $nodes->item(0);
        } else {
            $workbookNodes = $xpath->query('/*[local-name()="workbook"]');
            if (!$workbookNodes || $workbookNodes->length < 1) return;
            $root = $workbookNodes->item(0);
            $ns = $root->namespaceURI ? $root->namespaceURI : 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $calcPr = $doc->createElementNS($ns, 'calcPr');
            $root->appendChild($calcPr);
        }

        $calcPr->setAttribute('calcMode', 'auto');
        $calcPr->setAttribute('calcOnSave', '1');
        $calcPr->setAttribute('calcId', '0');
        $calcPr->setAttribute('fullCalcOnLoad', '1');
        $calcPr->setAttribute('forceFullCalc', '1');
        $calcPr->setAttribute('calcCompleted', '0');
        $zip->addFromString('xl/workbook.xml', $doc->saveXML());
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_clear_formula_cached_values')) {
    function cpms_labor_consultant_xlsx_clear_formula_cached_values($zip) {
        $fileCount = $zip->numFiles;
        $i = 0;
        while ($i < $fileCount) {
            $stat = $zip->statIndex($i);
            $name = $stat && isset($stat['name']) ? (string)$stat['name'] : '';
            if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', $name)) {
                $xml = $zip->getFromName($name);
                if ($xml !== false && $xml !== '') {
                    $doc = new DOMDocument('1.0', 'UTF-8');
                    $doc->preserveWhiteSpace = true;
                    $doc->formatOutput = false;
                    if (@$doc->loadXML($xml)) {
                        $xpath = new DOMXPath($doc);
                        $formulaCells = $xpath->query('//*[local-name()="c"][*[local-name()="f"]]');
                        if ($formulaCells) {
                            foreach ($formulaCells as $cell) {
                                $remove = array();
                                foreach ($cell->childNodes as $child) {
                                    if ($child instanceof DOMElement && $child->localName === 'v') {
                                        $remove[count($remove)] = $child;
                                    }
                                }
                                foreach ($remove as $node) {
                                    $cell->removeChild($node);
                                }
                            }
                            $zip->addFromString($name, $doc->saveXML());
                        }
                    }
                }
            }
            $i++;
        }
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_cell_text_at')) {
    function cpms_labor_consultant_xlsx_cell_text_at($sheetDoc, $sharedStrings, $ref) {
        $ref = strtoupper(trim((string)$ref));
        if ($ref === '') return '';
        $xpath = new DOMXPath($sheetDoc);
        $nodes = $xpath->query('//*[local-name()="c"][@r="' . $ref . '"]');
        if (!$nodes || $nodes->length < 1) return '';
        return cpms_labor_consultant_xlsx_cell_text($nodes->item(0), $sharedStrings);
    }
}

if (!function_exists('cpms_labor_consultant_text_contains_any')) {
    function cpms_labor_consultant_text_contains_any($text, $needles) {
        $key = cpms_labor_consultant_header_key($text);
        foreach ($needles as $needle) {
            $needleKey = cpms_labor_consultant_header_key($needle);
            if ($needleKey === '') continue;
            if (function_exists('mb_strpos')) {
                if (mb_strpos($key, $needleKey, 0, 'UTF-8') !== false) return true;
            } else {
                if (strpos($key, $needleKey) !== false) return true;
            }
        }
        return false;
    }
}

if (!function_exists('cpms_labor_consultant_header_day_number')) {
    function cpms_labor_consultant_header_day_number($text) {
        $key = cpms_labor_consultant_header_key($text);
        if ($key === '') return 0;
        if (preg_match('/^([0-9]{1,2})일$/u', $key, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/^[0-9]{1,2}$/', $key)) {
            return (int)$key;
        }
        if (preg_match('/^([0-9]{1,2})\.0+$/', $key, $m)) {
            return (int)$m[1];
        }
        return 0;
    }
}

if (!function_exists('cpms_labor_consultant_row_cell_texts')) {
    function cpms_labor_consultant_row_cell_texts($sheetDoc, $sharedStrings, $rowNum) {
        $cells = array();
        $xpath = new DOMXPath($sheetDoc);
        $nodes = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"][@r="' . (int)$rowNum . '"]/*[local-name()="c"]');
        foreach ($nodes as $cellNode) {
            $ref = $cellNode->getAttribute('r');
            list(, $colIndex) = cpms_labor_consultant_xlsx_ref_to_pos($ref);
            if ($colIndex <= 0) continue;
            $cells[$colIndex] = cpms_labor_consultant_xlsx_cell_text($cellNode, $sharedStrings);
        }
        return $cells;
    }
}

if (!function_exists('cpms_labor_consultant_find_header_col')) {
    function cpms_labor_consultant_find_header_col($cells, $needles) {
        foreach ($cells as $colIndex => $text) {
            if (cpms_labor_consultant_text_contains_any($text, $needles)) {
                return (int)$colIndex;
            }
        }
        return 0;
    }
}

if (!function_exists('cpms_labor_consultant_detect_day_columns')) {
    function cpms_labor_consultant_detect_day_columns($cells, $startDay, $endDay) {
        $days = array();
        foreach ($cells as $colIndex => $text) {
            $day = cpms_labor_consultant_header_day_number($text);
            if ($day >= $startDay && $day <= $endDay) {
                $days[$day] = (int)$colIndex;
            }
        }
        return $days;
    }
}

if (!function_exists('cpms_labor_consultant_default_two_row_columns')) {
    function cpms_labor_consultant_default_two_row_columns() {
        return array(
            'serialCol' => 1,
            'outputMonthCol' => 2,
            'workTypeCol' => 2,
            'nameCol' => 4,
            'phoneCol' => 4,
            'residentNoCol' => 5,
            'addressCol' => 5,
            'foreignerCol' => 6,
            'totalWorkCol' => 23,
            'dailyRateCol' => 24,
            'grossAmountCol' => 25,
            'earnedTaxCol' => 27,
            'residentTaxCol' => 27,
            'healthInsuranceCol' => 28,
            'pensionCol' => 28,
            'employmentInsuranceCol' => 29,
            'deductionTotalCol' => 29,
            'netAmountCol' => 30,
            'accountHolderCol' => 31,
            'bankNameCol' => 32,
            'bankAccountCol' => 33,
            'companyCol' => 34,
            'subcontractTypeCol' => 35
        );
    }
}

if (!function_exists('cpms_labor_consultant_default_top_day_columns')) {
    function cpms_labor_consultant_default_top_day_columns() {
        $days = array();
        $d = 1;
        while ($d <= 15) {
            $days[$d] = 6 + $d;
            $d++;
        }
        return $days;
    }
}

if (!function_exists('cpms_labor_consultant_default_bottom_day_columns')) {
    function cpms_labor_consultant_default_bottom_day_columns() {
        $days = array();
        $d = 16;
        while ($d <= 31) {
            $days[$d] = $d - 9;
            $d++;
        }
        return $days;
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_find_named_sheet')) {
    function cpms_labor_consultant_xlsx_find_named_sheet($zip, $sheetName) {
        $sheets = cpms_labor_consultant_xlsx_sheet_list($zip);
        foreach ($sheets as $sheet) {
            if ((string)(isset($sheet['name']) ? $sheet['name'] : '') === (string)$sheetName) {
                return $sheet;
            }
        }
        return null;
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_find_export_base_sheet')) {
    function cpms_labor_consultant_xlsx_find_export_base_sheet($zip) {
        $sheet = cpms_labor_consultant_xlsx_find_named_sheet($zip, '현장명');
        if ($sheet) return $sheet;
        $sheet = cpms_labor_consultant_xlsx_find_named_sheet($zip, '통합');
        if ($sheet) return $sheet;
        $sheets = cpms_labor_consultant_xlsx_sheet_list($zip);
        return count($sheets) > 0 ? $sheets[0] : null;
    }
}

if (!function_exists('cpms_labor_consultant_sheet_name')) {
    function cpms_labor_consultant_sheet_name($name) {
        $name = trim((string)$name);
        if ($name === '') $name = '현장';
        $name = preg_replace('/[\[\]\:\*\?\/\\\\]/u', '_', $name);
        $name = str_replace("'", '', $name);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($name, 'UTF-8') > 31) $name = mb_substr($name, 0, 31, 'UTF-8');
        } else {
            if (strlen($name) > 31) $name = substr($name, 0, 31);
        }
        if (trim($name) === '') $name = '현장';
        return $name;
    }
}

if (!function_exists('cpms_labor_consultant_sheet_name_key')) {
    function cpms_labor_consultant_sheet_name_key($name) {
        $name = (string)$name;
        if (function_exists('mb_strtolower')) return mb_strtolower($name, 'UTF-8');
        return strtolower($name);
    }
}

if (!function_exists('cpms_labor_consultant_unique_sheet_name')) {
    function cpms_labor_consultant_unique_sheet_name($name, &$used) {
        $base = cpms_labor_consultant_sheet_name($name);
        $candidate = $base;
        $idx = 2;
        while (isset($used[cpms_labor_consultant_sheet_name_key($candidate)])) {
            $suffix = '(' . $idx . ')';
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                $limit = 31 - mb_strlen($suffix, 'UTF-8');
                $candidate = mb_substr($base, 0, $limit, 'UTF-8') . $suffix;
            } else {
                $candidate = substr($base, 0, 31 - strlen($suffix)) . $suffix;
            }
            $idx++;
        }
        $used[cpms_labor_consultant_sheet_name_key($candidate)] = true;
        return $candidate;
    }
}

if (!function_exists('cpms_labor_consultant_sheet_rels_path')) {
    function cpms_labor_consultant_sheet_rels_path($sheetPath) {
        $sheetPath = str_replace('\\', '/', (string)$sheetPath);
        return dirname($sheetPath) . '/_rels/' . basename($sheetPath) . '.rels';
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_next_sheet_path')) {
    function cpms_labor_consultant_xlsx_next_sheet_path($zip) {
        static $reserved = array();
        $hash = is_object($zip) ? spl_object_hash($zip) : 'default';
        if (!isset($reserved[$hash])) $reserved[$hash] = array();
        $idx = 1;
        while ($zip->locateName('xl/worksheets/sheet' . $idx . '.xml') !== false || isset($reserved[$hash]['xl/worksheets/sheet' . $idx . '.xml'])) {
            $idx++;
        }
        $path = 'xl/worksheets/sheet' . $idx . '.xml';
        $reserved[$hash][$path] = true;
        return $path;
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_add_content_type_override')) {
    function cpms_labor_consultant_xlsx_add_content_type_override($zip, $partName, $contentType) {
        $xml = $zip->getFromName('[Content_Types].xml');
        if ($xml === false || $xml === '') return;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        if (!@$doc->loadXML($xml)) return;

        $partName = '/' . ltrim((string)$partName, '/');
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('/*[local-name()="Types"]/*[local-name()="Override"][@PartName="' . $partName . '"]');
        if ($nodes && $nodes->length > 0) return;

        $rootNodes = $xpath->query('/*[local-name()="Types"]');
        if (!$rootNodes || $rootNodes->length < 1) return;
        $root = $rootNodes->item(0);
        $ns = $root->namespaceURI ? $root->namespaceURI : 'http://schemas.openxmlformats.org/package/2006/content-types';
        $node = $doc->createElementNS($ns, 'Override');
        $node->setAttribute('PartName', $partName);
        $node->setAttribute('ContentType', $contentType);
        $root->appendChild($node);
        $zip->addFromString('[Content_Types].xml', $doc->saveXML());
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_apply_worksheet_content_types')) {
    function cpms_labor_consultant_xlsx_apply_worksheet_content_types($zip, $sheetPaths) {
        if (!is_array($sheetPaths) || count($sheetPaths) < 1) return;
        $xml = $zip->getFromName('[Content_Types].xml');
        if ($xml === false || $xml === '') return;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        if (!@$doc->loadXML($xml)) return;

        $xpath = new DOMXPath($doc);
        $rootNodes = $xpath->query('/*[local-name()="Types"]');
        if (!$rootNodes || $rootNodes->length < 1) return;
        $root = $rootNodes->item(0);
        $ns = $root->namespaceURI ? $root->namespaceURI : 'http://schemas.openxmlformats.org/package/2006/content-types';

        $existing = array();
        $nodes = $xpath->query('/*[local-name()="Types"]/*[local-name()="Override"]');
        foreach ($nodes as $node) {
            $existing[(string)$node->getAttribute('PartName')] = true;
        }

        foreach ($sheetPaths as $path) {
            $path = '/' . ltrim((string)$path, '/');
            if ($path === '/' || isset($existing[$path])) continue;
            $node = $doc->createElementNS($ns, 'Override');
            $node->setAttribute('PartName', $path);
            $node->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');
            $root->appendChild($node);
            $existing[$path] = true;
        }

        $zip->addFromString('[Content_Types].xml', $doc->saveXML());
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_clone_sheet')) {
    function cpms_labor_consultant_xlsx_clone_sheet($zip, $baseSheet, $newName) {
        $basePath = isset($baseSheet['path']) ? (string)$baseSheet['path'] : '';
        $baseXml = $zip->getFromName($basePath);
        if ($basePath === '' || $baseXml === false || $baseXml === '') {
            error_log('[labor_consultant_export] clone base xml missing: ' . $basePath);
            return null;
        }

        $newPath = cpms_labor_consultant_xlsx_next_sheet_path($zip);
        if (!$zip->addFromString($newPath, $baseXml)) {
            error_log('[labor_consultant_export] clone add sheet failed: ' . $newPath);
            return null;
        }

        $baseRels = cpms_labor_consultant_sheet_rels_path($basePath);
        $newRels = cpms_labor_consultant_sheet_rels_path($newPath);
        $relsXml = $zip->getFromName($baseRels);
        if ($relsXml !== false && $relsXml !== '') {
            $zip->addFromString($newRels, $relsXml);
        }
        cpms_labor_consultant_xlsx_add_content_type_override($zip, $newPath, 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');

        return array('name' => cpms_labor_consultant_sheet_name($newName), 'path' => $newPath, 'rid' => '', 'index' => 0);
    }
}

if (!function_exists('cpms_labor_consultant_xlsx_apply_output_sheets')) {
    function cpms_labor_consultant_xlsx_apply_output_sheets($zip, $outputSheets, $activePath) {
        if (!is_array($outputSheets) || count($outputSheets) < 1) return;

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) return;

        $wbDoc = new DOMDocument('1.0', 'UTF-8');
        $wbDoc->preserveWhiteSpace = true;
        $wbDoc->formatOutput = false;
        $relsDoc = new DOMDocument('1.0', 'UTF-8');
        $relsDoc->preserveWhiteSpace = true;
        $relsDoc->formatOutput = false;
        if (!@$wbDoc->loadXML($workbookXml) || !@$relsDoc->loadXML($relsXml)) return;

        $relsXpath = new DOMXPath($relsDoc);
        $pathByRid = array();
        $ridByPath = array();
        $maxRid = 0;
        $relNodes = $relsXpath->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"]');
        foreach ($relNodes as $relNode) {
            $rid = $relNode->getAttribute('Id');
            $target = str_replace('\\', '/', $relNode->getAttribute('Target'));
            if ($rid === '' || $target === '') continue;
            $target = ltrim($target, '/');
            if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
            $pathByRid[$rid] = $target;
            $ridByPath[$target] = $rid;
            if (preg_match('/^rId([0-9]+)$/', $rid, $m) && (int)$m[1] > $maxRid) {
                $maxRid = (int)$m[1];
            }
        }

        $outputByPath = array();
        foreach ($outputSheets as $item) {
            if (!is_array($item)) continue;
            $path = isset($item['path']) ? (string)$item['path'] : '';
            if ($path === '') continue;
            $outputByPath[$path] = isset($item['name']) ? (string)$item['name'] : '현장';
        }

        $wbXpath = new DOMXPath($wbDoc);
        $sheetsNodes = $wbXpath->query('/*[local-name()="workbook"]/*[local-name()="sheets"]');
        if (!$sheetsNodes || $sheetsNodes->length < 1) return;
        $sheetsNode = $sheetsNodes->item(0);
        $sheetNodes = $wbXpath->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"]');
        $maxSheetId = 0;
        foreach ($sheetNodes as $sheetNode) {
            $sid = (int)$sheetNode->getAttribute('sheetId');
            if ($sid > $maxSheetId) $maxSheetId = $sid;
        }

        $relsRootNodes = $relsXpath->query('/*[local-name()="Relationships"]');
        if (!$relsRootNodes || $relsRootNodes->length < 1) return;
        $relsRoot = $relsRootNodes->item(0);
        $relsNs = $relsRoot->namespaceURI ? $relsRoot->namespaceURI : 'http://schemas.openxmlformats.org/package/2006/relationships';
        $wbRootNodes = $wbXpath->query('/*[local-name()="workbook"]');
        $wbNs = ($wbRootNodes && $wbRootNodes->length > 0 && $wbRootNodes->item(0)->namespaceURI) ? $wbRootNodes->item(0)->namespaceURI : 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        foreach ($outputByPath as $path => $displayName) {
            if (isset($ridByPath[$path])) continue;
            $maxRid++;
            $maxSheetId++;
            $newRid = 'rId' . $maxRid;

            $relNode = $relsDoc->createElementNS($relsNs, 'Relationship');
            $relNode->setAttribute('Id', $newRid);
            $relNode->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet');
            $relNode->setAttribute('Target', preg_replace('#^xl/#', '', $path));
            $relsRoot->appendChild($relNode);

            $sheetNode = $wbDoc->createElementNS($wbNs, 'sheet');
            $sheetNode->setAttribute('name', cpms_labor_consultant_sheet_name($displayName));
            $sheetNode->setAttribute('sheetId', (string)$maxSheetId);
            $sheetNode->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', $newRid);
            $sheetsNode->appendChild($sheetNode);

            $pathByRid[$newRid] = $path;
            $ridByPath[$path] = $newRid;
        }

        $sheetNodes = $wbXpath->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"]');
        $used = array();
        foreach ($sheetNodes as $sheetNode) {
            $attrs = $sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
            $path = isset($pathByRid[$rid]) ? $pathByRid[$rid] : '';
            if ($path !== '' && isset($outputByPath[$path])) continue;
            $used[cpms_labor_consultant_sheet_name_key((string)$sheetNode->getAttribute('name'))] = true;
        }

        $activeIndex = 0;
        $idx = 0;
        foreach ($sheetNodes as $sheetNode) {
            $attrs = $sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
            $path = isset($pathByRid[$rid]) ? $pathByRid[$rid] : '';
            if ($path !== '' && isset($outputByPath[$path])) {
                $sheetNode->setAttribute('name', cpms_labor_consultant_unique_sheet_name($outputByPath[$path], $used));
                if ($sheetNode->hasAttribute('state')) $sheetNode->removeAttribute('state');
                if ($path === $activePath) $activeIndex = $idx;
            } else {
                $sheetNode->setAttribute('state', 'hidden');
            }
            $idx++;
        }

        $views = $wbXpath->query('//*[local-name()="workbookView"]');
        if ($views && $views->length > 0) {
            $views->item(0)->setAttribute('activeTab', (string)$activeIndex);
            $views->item(0)->setAttribute('firstSheet', (string)$activeIndex);
        }

        $zip->addFromString('xl/workbook.xml', $wbDoc->saveXML());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $relsDoc->saveXML());
    }
}

if (!function_exists('cpms_labor_consultant_group_rows_by_project')) {
    function cpms_labor_consultant_group_rows_by_project($dataRows, $fallbackLabel) {
        $groups = array();
        $order = array();
        if (!is_array($dataRows)) return $groups;
        foreach ($dataRows as $row) {
            if (!is_array($row)) continue;
            $pid = isset($row['project_id']) ? (string)((int)$row['project_id']) : '';
            $pname = isset($row['project_name']) ? trim((string)$row['project_name']) : '';
            $key = $pid !== '' && $pid !== '0' ? 'id:' . $pid : 'name:' . $pname;
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'project_id' => $pid,
                    'project_name' => $pname !== '' ? $pname : (string)$fallbackLabel,
                    'rows' => array()
                );
                $order[count($order)] = $key;
            }
            $groups[$key]['rows'][count($groups[$key]['rows'])] = $row;
        }

        $out = array();
        foreach ($order as $key) {
            $out[count($out)] = $groups[$key];
        }
        return $out;
    }
}

if (!function_exists('cpms_labor_consultant_detect_two_row_block')) {
    function cpms_labor_consultant_detect_two_row_block($sheetDoc, $sharedStrings, $sheetName) {
        $layout = array(
            'ok' => false,
            'mode' => '',
            'sheet_name' => (string)$sheetName,
            'headerTopRow' => 5,
            'headerBottomRow' => 6,
            'dataStartRow' => 7,
            'blockRows' => 2,
            'message' => '',
            'columns' => cpms_labor_consultant_default_two_row_columns(),
            'topDays' => cpms_labor_consultant_default_top_day_columns(),
            'bottomDays' => cpms_labor_consultant_default_bottom_day_columns()
        );

        $topCells = cpms_labor_consultant_row_cell_texts($sheetDoc, $sharedStrings, 5);
        $bottomCells = cpms_labor_consultant_row_cell_texts($sheetDoc, $sharedStrings, 6);

        $nameCol = cpms_labor_consultant_find_header_col($topCells, array('성명', '이름'));
        $residentNoCol = cpms_labor_consultant_find_header_col($topCells, array('주민등록번호', '주민 등록번호', '주민'));
        $phoneCol = cpms_labor_consultant_find_header_col($bottomCells, array('핸드폰번호', '핸드폰', '휴대폰', '전화'));
        $addressCol = cpms_labor_consultant_find_header_col($bottomCells, array('주소'));

        if ($nameCol > 0) $layout['columns']['nameCol'] = $nameCol;
        if ($residentNoCol > 0) $layout['columns']['residentNoCol'] = $residentNoCol;
        if ($phoneCol > 0) $layout['columns']['phoneCol'] = $phoneCol;
        if ($addressCol > 0) $layout['columns']['addressCol'] = $addressCol;

        $col = cpms_labor_consultant_find_header_col($topCells, array('출력월'));
        if ($col > 0) $layout['columns']['outputMonthCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($bottomCells, array('공종'));
        if ($col > 0) $layout['columns']['workTypeCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('외국인'));
        if ($col > 0) $layout['columns']['foreignerCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('출력일수', '총출력일수'));
        if ($col > 0) $layout['columns']['totalWorkCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('임금단가', '임 금 단 가'));
        if ($col > 0) $layout['columns']['dailyRateCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('지급총액', '지 급 총 액'));
        if ($col > 0) $layout['columns']['grossAmountCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('차감지급액', '차감 지급액'));
        if ($col > 0) $layout['columns']['netAmountCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('영수인', '예금주'));
        if ($col > 0) $layout['columns']['accountHolderCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('은행명'));
        if ($col > 0) $layout['columns']['bankNameCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('계좌번호'));
        if ($col > 0) $layout['columns']['bankAccountCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('인력사업체명', '팀명'));
        if ($col > 0) $layout['columns']['companyCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('하도급'));
        if ($col > 0) $layout['columns']['subcontractTypeCol'] = $col;

        $col = cpms_labor_consultant_find_header_col($topCells, array('갑근세'));
        if ($col > 0) $layout['columns']['earnedTaxCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($bottomCells, array('주민세'));
        if ($col > 0) $layout['columns']['residentTaxCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('건강보험'));
        if ($col > 0) $layout['columns']['healthInsuranceCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($bottomCells, array('국민연금'));
        if ($col > 0) $layout['columns']['pensionCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($topCells, array('고용보험'));
        if ($col > 0) $layout['columns']['employmentInsuranceCol'] = $col;
        $col = cpms_labor_consultant_find_header_col($bottomCells, array('공제소계', '공제 소계'));
        if ($col > 0) $layout['columns']['deductionTotalCol'] = $col;

        $topDayCols = cpms_labor_consultant_detect_day_columns($topCells, 1, 15);
        $bottomDayCols = cpms_labor_consultant_detect_day_columns($bottomCells, 16, 31);
        if (count($topDayCols) > 0) $layout['topDays'] = $topDayCols;
        if (count($bottomDayCols) > 0) $layout['bottomDays'] = $bottomDayCols;

        $ok = true;
        if ($nameCol <= 0) $ok = false;
        if ($residentNoCol <= 0) $ok = false;
        if ($phoneCol <= 0) $ok = false;
        if ($addressCol <= 0) $ok = false;
        if (!isset($layout['topDays'][1]) || !isset($layout['topDays'][15])) $ok = false;
        if (!isset($layout['bottomDays'][16]) || !isset($layout['bottomDays'][31])) $ok = false;

        if ($ok) {
            $layout['ok'] = true;
            $layout['mode'] = 'two_row_worker_block';
            $layout['message'] = '자동 헤더 감지 성공';
        } else {
            $layout['ok'] = true;
            $layout['mode'] = 'two_row_worker_block_fixed_fallback';
            $layout['message'] = '자동 헤더 감지 일부 실패, 고정 양식 위치로 처리';
            $layout['columns'] = cpms_labor_consultant_default_two_row_columns();
            $layout['topDays'] = cpms_labor_consultant_default_top_day_columns();
            $layout['bottomDays'] = cpms_labor_consultant_default_bottom_day_columns();
        }

        return $layout;
    }
}

if (!function_exists('cpms_labor_consultant_clear_cell_all')) {
    function cpms_labor_consultant_clear_cell_all($cellNode) {
        $remove = array();
        foreach ($cellNode->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $remove[count($remove)] = $child;
            }
        }
        foreach ($remove as $node) {
            $cellNode->removeChild($node);
        }
        $cellNode->removeAttribute('t');
    }
}

if (!function_exists('cpms_labor_consultant_clear_row_values_in_range')) {
    function cpms_labor_consultant_clear_row_values_in_range($rowNode, $startCol, $endCol) {
        foreach ($rowNode->childNodes as $child) {
            if (!($child instanceof DOMElement)) continue;
            if ($child->localName !== 'c') continue;
            list(, $colIndex) = cpms_labor_consultant_xlsx_ref_to_pos($child->getAttribute('r'));
            if ($colIndex >= $startCol && $colIndex <= $endCol) {
                cpms_labor_consultant_clear_cell_value($child);
            }
        }
    }
}

if (!function_exists('cpms_labor_consultant_prepare_two_row_blocks')) {
    function cpms_labor_consultant_prepare_two_row_blocks($sheetDoc, $dataStartRow, $blockCount) {
        $blockCount = (int)$blockCount;
        $dataStartRow = (int)$dataStartRow;
        if ($blockCount <= 0 || $dataStartRow <= 0) return array();

        $topRow = cpms_labor_consultant_find_row_node($sheetDoc, $dataStartRow);
        $bottomRow = cpms_labor_consultant_find_row_node($sheetDoc, $dataStartRow + 1);
        if (!$topRow || !$bottomRow) return array();

        $existingBlocks = array();
        $i = 0;
        while ($i < $blockCount) {
            $rowTopNum = $dataStartRow + ($i * 2);
            $rowBottomNum = $rowTopNum + 1;
            $existingTop = cpms_labor_consultant_find_row_node($sheetDoc, $rowTopNum);
            $existingBottom = cpms_labor_consultant_find_row_node($sheetDoc, $rowBottomNum);
            if (!$existingTop || !$existingBottom) break;
            $existingBlocks[count($existingBlocks)] = array('top' => $existingTop, 'bottom' => $existingBottom);
            $i++;
        }
        if (count($existingBlocks) === $blockCount) {
            return $existingBlocks;
        }

        $templateTop = $topRow->cloneNode(true);
        $templateBottom = $bottomRow->cloneNode(true);

        $xpath = new DOMXPath($sheetDoc);
        $sheetDataList = $xpath->query('//*[local-name()="worksheet"]/*[local-name()="sheetData"]');
        if (!$sheetDataList || $sheetDataList->length < 1) return array();
        $sheetData = $sheetDataList->item(0);

        $extraRows = ($blockCount - 1) * 2;
        if ($extraRows > 0) {
            cpms_labor_consultant_shift_sheet_rows($sheetDoc, $dataStartRow + 1, $extraRows);
            cpms_labor_consultant_shift_merged_cells($sheetDoc, $dataStartRow + 1, $extraRows);
        }

        $blocks = array();
        $blocks[count($blocks)] = array('top' => $topRow, 'bottom' => $bottomRow);
        $insertAfter = $bottomRow;

        $i = 1;
        while ($i < $blockCount) {
            $rowTopNum = $dataStartRow + ($i * 2);
            $rowBottomNum = $rowTopNum + 1;
            $cloneTop = $templateTop->cloneNode(true);
            $cloneBottom = $templateBottom->cloneNode(true);
            cpms_labor_consultant_reindex_row_node($cloneTop, $rowTopNum);
            cpms_labor_consultant_reindex_row_node($cloneBottom, $rowBottomNum);

            if ($insertAfter->nextSibling) {
                $sheetData->insertBefore($cloneTop, $insertAfter->nextSibling);
            } else {
                $sheetData->appendChild($cloneTop);
            }
            $insertAfter = $cloneTop;

            if ($insertAfter->nextSibling) {
                $sheetData->insertBefore($cloneBottom, $insertAfter->nextSibling);
            } else {
                $sheetData->appendChild($cloneBottom);
            }
            $insertAfter = $cloneBottom;

            $blocks[count($blocks)] = array('top' => $cloneTop, 'bottom' => $cloneBottom);
            $i++;
        }

        cpms_labor_consultant_update_dimension($sheetDoc);
        return $blocks;
    }
}

if (!function_exists('cpms_labor_consultant_number_value')) {
    function cpms_labor_consultant_number_value($rowData, $key) {
        if (!isset($rowData[$key]) || $rowData[$key] === '') return 0;
        return is_numeric($rowData[$key]) ? (float)$rowData[$key] : 0;
    }
}

if (!function_exists('cpms_labor_consultant_text_value')) {
    function cpms_labor_consultant_text_value($rowData, $key) {
        return isset($rowData[$key]) ? (string)$rowData[$key] : '';
    }
}

if (!function_exists('cpms_labor_consultant_fill_two_row_blocks')) {
    function cpms_labor_consultant_fill_two_row_blocks($sheetDoc, $layout, $dataRows, $ym) {
        if (!isset($layout['ok']) || !$layout['ok']) return false;
        $blocks = cpms_labor_consultant_prepare_two_row_blocks($sheetDoc, $layout['dataStartRow'], count($dataRows));
        if (count($blocks) !== count($dataRows)) return false;

        $cols = isset($layout['columns']) ? $layout['columns'] : array();
        if (!is_array($cols)) $cols = array();
        $cols = array_merge(cpms_labor_consultant_default_two_row_columns(), $cols);
        $topDays = isset($layout['topDays']) ? $layout['topDays'] : array();
        $bottomDays = isset($layout['bottomDays']) ? $layout['bottomDays'] : array();
        if (!is_array($topDays)) $topDays = array();
        if (!is_array($bottomDays)) $bottomDays = array();
        if (!isset($topDays[1]) || !isset($topDays[15])) {
            $topDays = cpms_labor_consultant_default_top_day_columns();
        }
        if (!isset($bottomDays[16]) || !isset($bottomDays[31])) {
            $bottomDays = cpms_labor_consultant_default_bottom_day_columns();
        }
        $daysInMonth = cpms_labor_consultant_days_in_month($ym);

        $outputMonthValue = (int)substr(cpms_labor_consultant_normalize_ym($ym), 5, 2);

        foreach ($blocks as $idx => $block) {
            $rowData = isset($dataRows[$idx]) ? $dataRows[$idx] : array();
            $topRow = isset($block['top']) ? $block['top'] : null;
            $bottomRow = isset($block['bottom']) ? $block['bottom'] : null;
            if (!$topRow || !$bottomRow) return false;

            cpms_labor_consultant_clear_row_values_in_range($topRow, 1, 35);
            cpms_labor_consultant_clear_row_values_in_range($bottomRow, 1, 35);

            $serial = $idx + 1;
            $workerName = cpms_labor_consultant_text_value($rowData, 'worker_name');
            $role = cpms_labor_consultant_text_value($rowData, 'role');
            $amount = cpms_labor_consultant_number_value($rowData, 'amount');
            $wageRate = cpms_labor_consultant_number_value($rowData, 'wage_rate');
            $outputDays = cpms_labor_consultant_number_value($rowData, 'output_days');
            $netAmount = $amount;
            $deductionTotal = 0;

            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['serialCol'], $serial, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['outputMonthCol'], $outputMonthValue, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['nameCol'], $workerName, false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['residentNoCol'], cpms_labor_consultant_text_value($rowData, 'resident_no'), false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['foreignerCol'], cpms_labor_consultant_text_value($rowData, 'foreigner'), false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['totalWorkCol'], $outputDays, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['dailyRateCol'], $wageRate, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['grossAmountCol'], $amount, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['earnedTaxCol'], 0, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['healthInsuranceCol'], 0, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['employmentInsuranceCol'], 0, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['netAmountCol'], $netAmount, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['accountHolderCol'], cpms_labor_consultant_text_value($rowData, 'account_holder') !== '' ? cpms_labor_consultant_text_value($rowData, 'account_holder') : $workerName, false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['bankNameCol'], cpms_labor_consultant_text_value($rowData, 'bank_name'), false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['bankAccountCol'], cpms_labor_consultant_text_value($rowData, 'bank_account'), false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['companyCol'], cpms_labor_consultant_text_value($rowData, 'company_name'), false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $cols['subcontractTypeCol'], cpms_labor_consultant_text_value($rowData, 'subcontract_type'), false);

            cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $cols['workTypeCol'], $role, false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $cols['phoneCol'], cpms_labor_consultant_text_value($rowData, 'phone'), false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $cols['addressCol'], cpms_labor_consultant_text_value($rowData, 'address'), false);
            cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $cols['residentTaxCol'], 0, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $cols['pensionCol'], 0, true);
            cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $cols['deductionTotalCol'], $deductionTotal, true);

            $days = isset($rowData['days']) && is_array($rowData['days']) ? $rowData['days'] : array();
            $day = 1;
            while ($day <= 31) {
                if ($day <= $daysInMonth) {
                    $value = isset($days[$day]) ? $days[$day] : '';
                } else {
                    $value = '';
                }

                if ($day <= 15 && isset($topDays[$day])) {
                    cpms_labor_consultant_set_cell_value($sheetDoc, $topRow, $topDays[$day], $value, ($value !== ''));
                } else if ($day >= 16 && isset($bottomDays[$day])) {
                    cpms_labor_consultant_set_cell_value($sheetDoc, $bottomRow, $bottomDays[$day], $value, ($value !== ''));
                }
                $day++;
            }
        }

        cpms_labor_consultant_update_dimension($sheetDoc);
        return true;
    }
}

if (!function_exists('cpms_labor_consultant_set_cell_ref_value')) {
    function cpms_labor_consultant_set_cell_ref_value($sheetDoc, $ref, $value, $isNumeric) {
        list($rowNum, $colIndex) = cpms_labor_consultant_xlsx_ref_to_pos($ref);
        if ($rowNum <= 0 || $colIndex <= 0) return false;
        $rowNode = cpms_labor_consultant_find_row_node($sheetDoc, $rowNum);
        if (!$rowNode) return false;
        cpms_labor_consultant_set_cell_value($sheetDoc, $rowNode, $colIndex, $value, $isNumeric);
        return true;
    }
}

if (!function_exists('cpms_labor_consultant_month_period_label')) {
    function cpms_labor_consultant_month_period_label($ym) {
        $range = cpms_labor_consultant_month_range($ym);
        return $range['start'] . ' ~ ' . $range['end'];
    }
}

if (!function_exists('cpms_labor_consultant_project_period_label')) {
    function cpms_labor_consultant_project_period_label($rowData, $fallback) {
        $start = isset($rowData['project_start_date']) ? trim((string)$rowData['project_start_date']) : '';
        $end = isset($rowData['project_end_date']) ? trim((string)$rowData['project_end_date']) : '';
        if ($start !== '' && $end !== '') return $start . ' ~ ' . $end;
        if ($start !== '') return $start . ' ~';
        if ($end !== '') return '~ ' . $end;
        return $fallback;
    }
}

if (!function_exists('cpms_labor_consultant_fill_summary_cells')) {
    function cpms_labor_consultant_fill_summary_cells($sheetDoc, $sharedStrings, $dataRows, $ym, $options) {
        if (!is_array($options)) $options = array();
        $firstRow = is_array($dataRows) && count($dataRows) > 0 && is_array($dataRows[0]) ? $dataRows[0] : array();
        $projectLabel = isset($options['project_label']) ? trim((string)$options['project_label']) : '';
        if ($projectLabel === '' && isset($firstRow['project_name'])) $projectLabel = trim((string)$firstRow['project_name']);

        $managerName = isset($options['project_manager_name']) ? trim((string)$options['project_manager_name']) : '';
        if ($managerName === '' && isset($firstRow['project_manager_name'])) $managerName = trim((string)$firstRow['project_manager_name']);

        $monthRange = cpms_labor_consultant_month_range($ym);
        $monthPeriod = cpms_labor_consultant_month_period_label($ym);
        $projectPeriod = cpms_labor_consultant_project_period_label($firstRow, $monthPeriod);

        if ($projectLabel !== '') {
            cpms_labor_consultant_set_cell_ref_value($sheetDoc, 'C1', $projectLabel, false);
        }

        cpms_labor_consultant_set_cell_ref_value($sheetDoc, 'M1', $projectPeriod, false);
        cpms_labor_consultant_set_cell_ref_value($sheetDoc, 'AE3', $monthRange['start'], false);
        cpms_labor_consultant_set_cell_ref_value($sheetDoc, 'AE4', $monthRange['end'], false);

        if ($managerName !== '') {
            $ac1 = cpms_labor_consultant_xlsx_cell_text_at($sheetDoc, $sharedStrings, 'AC1');
            $ac2 = cpms_labor_consultant_xlsx_cell_text_at($sheetDoc, $sharedStrings, 'AC2');
            cpms_labor_consultant_set_cell_ref_value($sheetDoc, 'AC1', $managerName, false);
            cpms_labor_consultant_set_cell_ref_value($sheetDoc, 'AC2', $managerName, false);
            if (cpms_labor_consultant_text_contains_any($ac1, array('책임자'))) {
                cpms_labor_consultant_set_cell_ref_value($sheetDoc, 'AE1', $managerName, false);
            }
            if (cpms_labor_consultant_text_contains_any($ac2, array('작성자'))) {
                cpms_labor_consultant_set_cell_ref_value($sheetDoc, 'AE2', $managerName, false);
            }
        }
    }
}

if (!function_exists('cpms_labor_export_remove_calc_chain')) {
    function cpms_labor_export_remove_calc_chain($xlsxPath) {
        if (!class_exists('ZipArchive') || !is_file($xlsxPath)) return false;
        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) return false;

        if ($zip->locateName('xl/calcChain.xml') !== false) {
            $zip->deleteName('xl/calcChain.xml');
        }

        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml !== false && $relsXml !== '') {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = true;
            $doc->formatOutput = false;
            if (@$doc->loadXML($relsXml)) {
                $xpath = new DOMXPath($doc);
                $nodes = $xpath->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"]');
                $remove = array();
                foreach ($nodes as $node) {
                    $type = $node->getAttribute('Type');
                    $target = $node->getAttribute('Target');
                    if (strpos($type, '/calcChain') !== false || strpos($target, 'calcChain.xml') !== false) {
                        $remove[count($remove)] = $node;
                    }
                }
                foreach ($remove as $node) {
                    $node->parentNode->removeChild($node);
                }
                $zip->addFromString('xl/_rels/workbook.xml.rels', $doc->saveXML());
            }
        }

        $typesXml = $zip->getFromName('[Content_Types].xml');
        if ($typesXml !== false && $typesXml !== '') {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = true;
            $doc->formatOutput = false;
            if (@$doc->loadXML($typesXml)) {
                $xpath = new DOMXPath($doc);
                $nodes = $xpath->query('/*[local-name()="Types"]/*[local-name()="Override"]');
                $remove = array();
                foreach ($nodes as $node) {
                    if ($node->getAttribute('PartName') === '/xl/calcChain.xml') {
                        $remove[count($remove)] = $node;
                    }
                }
                foreach ($remove as $node) {
                    $node->parentNode->removeChild($node);
                }
                $zip->addFromString('[Content_Types].xml', $doc->saveXML());
            }
        }

        $zip->close();
        return true;
    }
}

if (!function_exists('cpms_labor_consultant_debug_export_detection')) {
    function cpms_labor_consultant_debug_export_detection($templatePath, $dataRows) {
        $result = array(
            'ok' => false,
            'message' => '',
            'sheet' => '',
            'layout' => array(),
            'labor_count' => is_array($dataRows) ? count($dataRows) : 0,
            'first_labor_row' => is_array($dataRows) && count($dataRows) > 0 ? $dataRows[0] : array()
        );

        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string') || !class_exists('DOMDocument')) {
            $result['message'] = '엑셀 생성 라이브러리를 찾을 수 없습니다.';
            return $result;
        }
        if (!is_file($templatePath)) {
            $result['message'] = '등록된 노무사 확인용 양식이 없습니다.';
            return $result;
        }

        $zip = new ZipArchive();
        if ($zip->open($templatePath) !== true) {
            $result['message'] = '엑셀 양식을 읽을 수 없습니다.';
            return $result;
        }

        $sheet = cpms_labor_consultant_xlsx_find_sheet($zip, '통합');
        $result['sheet'] = isset($sheet['name']) ? $sheet['name'] : '';
        $sheetPath = isset($sheet['path']) ? $sheet['path'] : '';
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false || $sheetXml === '') {
            $zip->close();
            $result['message'] = '엑셀 양식을 읽을 수 없습니다.';
            return $result;
        }

        $sheetDoc = new DOMDocument('1.0', 'UTF-8');
        $sheetDoc->preserveWhiteSpace = true;
        $sheetDoc->formatOutput = false;
        if (!@$sheetDoc->loadXML($sheetXml)) {
            $zip->close();
            $result['message'] = '엑셀 양식을 읽을 수 없습니다.';
            return $result;
        }

        $sharedStrings = cpms_labor_consultant_xlsx_shared_strings($zip);
        $layout = cpms_labor_consultant_detect_two_row_block($sheetDoc, $sharedStrings, isset($sheet['name']) ? $sheet['name'] : '');
        $zip->close();

        $result['layout'] = $layout;
        if (isset($layout['ok']) && $layout['ok']) {
            $result['ok'] = true;
        } else {
            $result['message'] = '노무사 확인용 엑셀 양식 구조를 인식하지 못했습니다. 통합 시트의 5행/6행 헤더를 확인해주세요.';
        }
        return $result;
    }
}

if (!function_exists('cpms_labor_consultant_render_debug_page')) {
    function cpms_labor_consultant_render_debug_page($debug) {
        $layout = isset($debug['layout']) && is_array($debug['layout']) ? $debug['layout'] : array();
        $cols = isset($layout['columns']) && is_array($layout['columns']) ? $layout['columns'] : array();
        $topDays = isset($layout['topDays']) && is_array($layout['topDays']) ? $layout['topDays'] : array();
        $bottomDays = isset($layout['bottomDays']) && is_array($layout['bottomDays']) ? $layout['bottomDays'] : array();

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>labor export debug</title>';
        echo '<style>body{font-family:Consolas,monospace;padding:24px;line-height:1.55}pre{background:#f5f5f5;padding:12px;white-space:pre-wrap}</style>';
        echo '</head><body>';
        echo '<h1>labor consultant export debug</h1>';
        if (isset($debug['message']) && $debug['message'] !== '') {
            echo '<p>' . htmlspecialchars((string)$debug['message'], ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '<pre>';
        echo 'template sheet: ' . htmlspecialchars(isset($debug['sheet']) ? (string)$debug['sheet'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'mode: ' . htmlspecialchars(isset($layout['mode']) ? (string)$layout['mode'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'headerTopRow: ' . htmlspecialchars(isset($layout['headerTopRow']) ? (string)$layout['headerTopRow'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'headerBottomRow: ' . htmlspecialchars(isset($layout['headerBottomRow']) ? (string)$layout['headerBottomRow'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'dataStartRow: ' . htmlspecialchars(isset($layout['dataStartRow']) ? (string)$layout['dataStartRow'] : '', ENT_QUOTES, 'UTF-8') . "\n\n";
        echo 'day 1 col: ' . (isset($topDays[1]) ? cpms_labor_consultant_xlsx_col_to_letter($topDays[1]) : '') . "\n";
        echo 'day 15 col: ' . (isset($topDays[15]) ? cpms_labor_consultant_xlsx_col_to_letter($topDays[15]) : '') . "\n";
        echo 'day 16 col: ' . (isset($bottomDays[16]) ? cpms_labor_consultant_xlsx_col_to_letter($bottomDays[16]) : '') . "\n";
        echo 'day 31 col: ' . (isset($bottomDays[31]) ? cpms_labor_consultant_xlsx_col_to_letter($bottomDays[31]) : '') . "\n\n";
        echo 'nameCol: ' . (isset($cols['nameCol']) ? cpms_labor_consultant_xlsx_col_to_letter($cols['nameCol']) : '') . "\n";
        echo 'phoneCol: ' . (isset($cols['phoneCol']) ? cpms_labor_consultant_xlsx_col_to_letter($cols['phoneCol']) : '') . "\n";
        echo 'residentNoCol: ' . (isset($cols['residentNoCol']) ? cpms_labor_consultant_xlsx_col_to_letter($cols['residentNoCol']) : '') . "\n";
        echo 'addressCol: ' . (isset($cols['addressCol']) ? cpms_labor_consultant_xlsx_col_to_letter($cols['addressCol']) : '') . "\n";
        echo 'totalWorkCol: ' . (isset($cols['totalWorkCol']) ? cpms_labor_consultant_xlsx_col_to_letter($cols['totalWorkCol']) : '') . "\n";
        echo 'dailyRateCol: ' . (isset($cols['dailyRateCol']) ? cpms_labor_consultant_xlsx_col_to_letter($cols['dailyRateCol']) : '') . "\n";
        echo 'grossAmountCol: ' . (isset($cols['grossAmountCol']) ? cpms_labor_consultant_xlsx_col_to_letter($cols['grossAmountCol']) : '') . "\n";
        echo 'netAmountCol: ' . (isset($cols['netAmountCol']) ? cpms_labor_consultant_xlsx_col_to_letter($cols['netAmountCol']) : '') . "\n\n";
        echo 'laborRows count: ' . htmlspecialchars(isset($debug['labor_count']) ? (string)$debug['labor_count'] : '0', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'first labor row sample:' . "\n";
        echo htmlspecialchars(print_r(isset($debug['first_labor_row']) ? $debug['first_labor_row'] : array(), true), ENT_QUOTES, 'UTF-8');
        echo '</pre></body></html>';
        exit;
    }
}

if (!function_exists('cpms_labor_consultant_create_export_file')) {
    function cpms_labor_consultant_create_export_file($templatePath, $dataRows) {
        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string') || !class_exists('DOMDocument')) {
            error_log('[labor_consultant_export] library missing');
            return array('ok' => false, 'message' => '엑셀 생성 라이브러리를 찾을 수 없습니다.');
        }
        if (!is_file($templatePath)) {
            error_log('[labor_consultant_export] template missing');
            return array('ok' => false, 'message' => '등록된 노무사 확인용 양식이 없습니다.');
        }
        if (!is_array($dataRows) || count($dataRows) < 1) {
            error_log('[labor_consultant_export] no data');
            return array('ok' => false, 'message' => '선택한 기간에 노무비 데이터가 없습니다.');
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'cpms_labor_');
        if ($tmpBase === false) {
            error_log('[labor_consultant_export] temp file create failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }
        $tmpPath = $tmpBase . '.xlsx';
        @unlink($tmpPath);
        if (!@copy($templatePath, $tmpPath)) {
            @unlink($tmpBase);
            error_log('[labor_consultant_export] template copy failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }
        @unlink($tmpBase);

        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            @unlink($tmpPath);
            error_log('[labor_consultant_export] template open failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }

        $sheetPath = cpms_labor_consultant_xlsx_first_sheet_path($zip);
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false || $sheetXml === '') {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] sheet xml missing');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }

        $sheetDoc = new DOMDocument('1.0', 'UTF-8');
        $sheetDoc->preserveWhiteSpace = true;
        $sheetDoc->formatOutput = false;
        if (!@$sheetDoc->loadXML($sheetXml)) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] sheet load failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }

        $sharedStrings = cpms_labor_consultant_xlsx_shared_strings($zip);
        $headers = cpms_labor_consultant_detect_headers($sheetDoc, $sharedStrings);
        if (!isset($headers['columns']['worker_name'])) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] header detection failed');
            return array('ok' => false, 'message' => '엑셀 양식에서 성명/이름 헤더를 찾지 못했습니다. 양식을 확인해주세요.');
        }

        if (!cpms_labor_consultant_fill_sheet_rows($sheetDoc, $headers, $dataRows)) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] fill failed');
            return array('ok' => false, 'message' => '엑셀 양식에서 필수 헤더를 찾지 못했습니다.');
        }

        if (!$zip->addFromString($sheetPath, $sheetDoc->saveXML())) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] sheet write failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }

        $zip->close();
        return array('ok' => true, 'path' => $tmpPath);
    }
}

if (!function_exists('cpms_labor_consultant_create_export_file_v2')) {
    function cpms_labor_consultant_create_export_file_v2($templatePath, $dataRows, $options) {
        if (function_exists('cpms_labor_consultant_create_export_file_v3')) {
            return cpms_labor_consultant_create_export_file_v3($templatePath, $dataRows, $options);
        }
        if (!is_array($options)) $options = array();
        $ym = isset($options['ym']) ? cpms_labor_consultant_normalize_ym($options['ym']) : cpms_labor_consultant_current_ym();

        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string') || !class_exists('DOMDocument')) {
            error_log('[labor_consultant_export] library missing');
            return array('ok' => false, 'message' => '엑셀 생성 라이브러리를 찾을 수 없습니다.');
        }
        if (!is_file($templatePath)) {
            error_log('[labor_consultant_export] template missing');
            return array('ok' => false, 'message' => '등록된 노무사 확인용 양식이 없습니다.');
        }
        if (!is_array($dataRows) || count($dataRows) < 1) {
            error_log('[labor_consultant_export] no data');
            return array('ok' => false, 'message' => '선택한 현장/기간에 노무비 데이터가 없습니다.');
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'cpms_labor_');
        if ($tmpBase === false) {
            error_log('[labor_consultant_export] temp file create failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }
        $tmpPath = $tmpBase . '.xlsx';
        @unlink($tmpPath);
        if (!@copy($templatePath, $tmpPath)) {
            @unlink($tmpBase);
            error_log('[labor_consultant_export] template copy failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }
        @unlink($tmpBase);

        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            @unlink($tmpPath);
            error_log('[labor_consultant_export] template open failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }

        $sheet = cpms_labor_consultant_xlsx_find_sheet($zip, '통합');
        $sheetPath = isset($sheet['path']) ? (string)$sheet['path'] : '';
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false || $sheetXml === '') {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] sheet xml missing');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }

        $sheetDoc = new DOMDocument('1.0', 'UTF-8');
        $sheetDoc->preserveWhiteSpace = true;
        $sheetDoc->formatOutput = false;
        if (!@$sheetDoc->loadXML($sheetXml)) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] sheet load failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }

        $sharedStrings = cpms_labor_consultant_xlsx_shared_strings($zip);
        $layout = cpms_labor_consultant_detect_two_row_block($sheetDoc, $sharedStrings, isset($sheet['name']) ? $sheet['name'] : '');
        if (!isset($layout['ok']) || !$layout['ok']) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] header detection failed');
            return array('ok' => false, 'message' => '노무사 확인용 엑셀 양식 구조를 인식하지 못했습니다. 통합 시트의 5행/6행 헤더를 확인해주세요.');
        }

        if (!cpms_labor_consultant_fill_two_row_blocks($sheetDoc, $layout, $dataRows, $ym)) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] fill failed');
            return array('ok' => false, 'message' => '노무사 확인용 엑셀 양식 구조를 인식하지 못했습니다. 통합 시트의 5행/6행 헤더를 확인해주세요.');
        }

        $firstNameCol = isset($layout['columns']['nameCol']) ? (int)$layout['columns']['nameCol'] : 4;
        $firstNameRef = cpms_labor_consultant_xlsx_col_to_letter($firstNameCol) . '7';
        $firstName = cpms_labor_consultant_xlsx_cell_text_at($sheetDoc, array(), $firstNameRef);
        if (trim((string)$firstName) === '') {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] first data row empty');
            return array('ok' => false, 'message' => '선택한 현장/기간에 노무비 데이터가 없습니다.');
        }

        if (!$zip->addFromString($sheetPath, $sheetDoc->saveXML())) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] sheet write failed');
            return array('ok' => false, 'message' => '엑셀 양식을 읽을 수 없습니다.');
        }

        cpms_labor_consultant_xlsx_set_active_sheet($zip, isset($sheet['index']) ? (int)$sheet['index'] : 0);
        $zip->close();
        cpms_labor_export_remove_calc_chain($tmpPath);

        return array('ok' => true, 'path' => $tmpPath);
    }
}

if (!function_exists('cpms_labor_consultant_debug_export_detection_v2')) {
    function cpms_labor_consultant_debug_export_detection_v2($templatePath, $dataRows, $options) {
        if (!is_array($options)) $options = array();
        $projectId = isset($options['project_id']) ? (string)$options['project_id'] : '';
        $ym = isset($options['ym']) ? cpms_labor_consultant_normalize_ym($options['ym']) : cpms_labor_consultant_current_ym();
        $result = array(
            'ok' => false,
            'message' => '',
            'project_id' => $projectId,
            'ym' => $ym,
            'template_path' => (string)$templatePath,
            'template_exists' => is_file($templatePath) ? 'true' : 'false',
            'selected_sheet_name' => '',
            'sheet_path' => '',
            'layout_ok' => 'false',
            'layout_message' => '',
            'layout' => array(),
            'labor_count' => is_array($dataRows) ? count($dataRows) : 0,
            'first_labor_row' => is_array($dataRows) && count($dataRows) > 0 ? $dataRows[0] : array(),
            'fill_result' => 'not_run',
            'firstNameRef' => '',
            'firstNameAfterFill' => ''
        );

        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string') || !class_exists('DOMDocument')) {
            $result['message'] = '엑셀 생성 라이브러리를 찾을 수 없습니다.';
            return $result;
        }
        if (!is_file($templatePath)) {
            $result['message'] = '등록된 양식 파일이 없습니다.';
            return $result;
        }

        $zip = new ZipArchive();
        if ($zip->open($templatePath) !== true) {
            $result['message'] = '양식 파일을 ZipArchive로 열 수 없습니다.';
            return $result;
        }

        $sheet = cpms_labor_consultant_xlsx_find_export_base_sheet($zip);
        if (!$sheet) {
            $zip->close();
            $result['message'] = '현장명 또는 통합 시트를 찾지 못했습니다.';
            return $result;
        }

        $result['selected_sheet_name'] = isset($sheet['name']) ? (string)$sheet['name'] : '';
        $result['sheet_path'] = isset($sheet['path']) ? (string)$sheet['path'] : '';

        $sheetXml = $zip->getFromName($result['sheet_path']);
        if ($sheetXml === false || $sheetXml === '') {
            $zip->close();
            $result['message'] = '기준 시트 XML을 읽을 수 없습니다.';
            return $result;
        }

        $sheetDoc = new DOMDocument('1.0', 'UTF-8');
        $sheetDoc->preserveWhiteSpace = true;
        $sheetDoc->formatOutput = false;
        if (!@$sheetDoc->loadXML($sheetXml)) {
            $zip->close();
            $result['message'] = '기준 시트 XML을 읽을 수 없습니다.';
            return $result;
        }

        $sharedStrings = cpms_labor_consultant_xlsx_shared_strings($zip);
        $layout = cpms_labor_consultant_detect_two_row_block($sheetDoc, $sharedStrings, $result['selected_sheet_name']);
        $result['layout'] = $layout;
        $result['layout_ok'] = (isset($layout['ok']) && $layout['ok']) ? 'true' : 'false';
        $result['layout_message'] = isset($layout['message']) ? (string)$layout['message'] : '';

        $cols = isset($layout['columns']) && is_array($layout['columns']) ? array_merge(cpms_labor_consultant_default_two_row_columns(), $layout['columns']) : cpms_labor_consultant_default_two_row_columns();
        $firstNameCol = isset($cols['nameCol']) ? (int)$cols['nameCol'] : 4;
        $result['firstNameRef'] = cpms_labor_consultant_xlsx_col_to_letter($firstNameCol) . '7';

        if (isset($layout['ok']) && $layout['ok'] && is_array($dataRows) && count($dataRows) > 0) {
            try {
                $fillOk = cpms_labor_consultant_fill_two_row_blocks($sheetDoc, $layout, $dataRows, $ym);
                $result['fill_result'] = $fillOk ? 'success' : 'failed';
                $result['firstNameAfterFill'] = cpms_labor_consultant_xlsx_cell_text_at($sheetDoc, array(), $result['firstNameRef']);
            } catch (Exception $e) {
                $result['fill_result'] = 'exception: ' . $e->getMessage();
            }
        } else if (!is_array($dataRows) || count($dataRows) < 1) {
            $result['fill_result'] = 'not_run: no labor rows';
        } else {
            $result['fill_result'] = 'not_run: layout failed';
        }

        $zip->close();
        if ($result['message'] === '') {
            $result['message'] = $result['layout_message'];
        }
        $result['ok'] = true;
        return $result;
    }
}

if (!function_exists('cpms_labor_consultant_render_debug_page_v2')) {
    function cpms_labor_consultant_render_debug_page_v2($debug) {
        $layout = isset($debug['layout']) && is_array($debug['layout']) ? $debug['layout'] : array();
        $cols = isset($layout['columns']) && is_array($layout['columns']) ? array_merge(cpms_labor_consultant_default_two_row_columns(), $layout['columns']) : cpms_labor_consultant_default_two_row_columns();
        $topDays = isset($layout['topDays']) && is_array($layout['topDays']) ? $layout['topDays'] : cpms_labor_consultant_default_top_day_columns();
        $bottomDays = isset($layout['bottomDays']) && is_array($layout['bottomDays']) ? $layout['bottomDays'] : cpms_labor_consultant_default_bottom_day_columns();
        if (!isset($topDays[1]) || !isset($topDays[15])) $topDays = cpms_labor_consultant_default_top_day_columns();
        if (!isset($bottomDays[16]) || !isset($bottomDays[31])) $bottomDays = cpms_labor_consultant_default_bottom_day_columns();

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>labor export debug</title>';
        echo '<style>body{font-family:Consolas,monospace;padding:24px;line-height:1.55}pre{background:#f5f5f5;padding:12px;white-space:pre-wrap}</style>';
        echo '</head><body>';
        echo '<h1>labor consultant export debug</h1><pre>';
        echo 'project_id: ' . htmlspecialchars(isset($debug['project_id']) ? (string)$debug['project_id'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'ym: ' . htmlspecialchars(isset($debug['ym']) ? (string)$debug['ym'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'template_path: ' . htmlspecialchars(isset($debug['template_path']) ? (string)$debug['template_path'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'template_exists: ' . htmlspecialchars(isset($debug['template_exists']) ? (string)$debug['template_exists'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'selected_sheet_name: ' . htmlspecialchars(isset($debug['selected_sheet_name']) ? (string)$debug['selected_sheet_name'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'sheet_path: ' . htmlspecialchars(isset($debug['sheet_path']) ? (string)$debug['sheet_path'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'layout_ok: ' . htmlspecialchars(isset($debug['layout_ok']) ? (string)$debug['layout_ok'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'layout_message: ' . htmlspecialchars(isset($debug['layout_message']) ? (string)$debug['layout_message'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'laborRows count: ' . htmlspecialchars(isset($debug['labor_count']) ? (string)$debug['labor_count'] : '0', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'first labor row sample:' . "\n";
        echo htmlspecialchars(print_r(isset($debug['first_labor_row']) ? $debug['first_labor_row'] : array(), true), ENT_QUOTES, 'UTF-8') . "\n";
        echo 'dataStartRow: ' . htmlspecialchars(isset($layout['dataStartRow']) ? (string)$layout['dataStartRow'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'blockRows: ' . htmlspecialchars(isset($layout['blockRows']) ? (string)$layout['blockRows'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'nameCol: ' . (isset($cols['nameCol']) ? cpms_labor_consultant_xlsx_col_to_letter($cols['nameCol']) : '') . "\n";
        echo 'day 1 col: ' . (isset($topDays[1]) ? cpms_labor_consultant_xlsx_col_to_letter($topDays[1]) : '') . "\n";
        echo 'day 15 col: ' . (isset($topDays[15]) ? cpms_labor_consultant_xlsx_col_to_letter($topDays[15]) : '') . "\n";
        echo 'day 16 col: ' . (isset($bottomDays[16]) ? cpms_labor_consultant_xlsx_col_to_letter($bottomDays[16]) : '') . "\n";
        echo 'day 31 col: ' . (isset($bottomDays[31]) ? cpms_labor_consultant_xlsx_col_to_letter($bottomDays[31]) : '') . "\n";
        echo 'fill_result: ' . htmlspecialchars(isset($debug['fill_result']) ? (string)$debug['fill_result'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'firstNameRef: ' . htmlspecialchars(isset($debug['firstNameRef']) ? (string)$debug['firstNameRef'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        echo 'firstNameAfterFill: ' . htmlspecialchars(isset($debug['firstNameAfterFill']) ? (string)$debug['firstNameAfterFill'] : '', ENT_QUOTES, 'UTF-8') . "\n";
        if (isset($debug['message']) && $debug['message'] !== '') {
            echo 'message: ' . htmlspecialchars((string)$debug['message'], ENT_QUOTES, 'UTF-8') . "\n";
        }
        echo '</pre></body></html>';
        exit;
    }
}

if (!function_exists('cpms_labor_consultant_validate_export_template_v3')) {
    function cpms_labor_consultant_validate_export_template_v3($templatePath, $dataRows, $options) {
        if (!is_array($options)) $options = array();

        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string') || !class_exists('DOMDocument')) {
            error_log('[labor_consultant_export] library missing');
            return array('ok' => false, 'message' => '엑셀 생성 라이브러리를 찾을 수 없습니다.');
        }
        if (!is_file($templatePath)) {
            error_log('[labor_consultant_export] template missing');
            return array('ok' => false, 'message' => '등록된 양식 파일이 없습니다.');
        }
        if (!is_array($dataRows) || count($dataRows) < 1) {
            error_log('[labor_consultant_export] no data');
            return array('ok' => false, 'message' => '선택한 현장/기간에 노무비 데이터가 없습니다.');
        }

        $firstWorkerName = isset($dataRows[0]['worker_name']) ? trim((string)$dataRows[0]['worker_name']) : '';
        if ($firstWorkerName === '') {
            error_log('[labor_consultant_export] first worker name empty');
            return array('ok' => false, 'message' => '첫 번째 데이터 행 성명칸이 비어 있습니다.');
        }

        $zip = new ZipArchive();
        if ($zip->open($templatePath) !== true) {
            error_log('[labor_consultant_export] template open failed');
            return array('ok' => false, 'message' => '양식 파일을 ZipArchive로 열 수 없습니다.');
        }

        $baseSheet = cpms_labor_consultant_xlsx_find_export_base_sheet($zip);
        if (!$baseSheet) {
            $zip->close();
            error_log('[labor_consultant_export] base sheet missing');
            return array('ok' => false, 'message' => '현장명 또는 통합 시트를 찾지 못했습니다.');
        }

        $baseSheetPath = isset($baseSheet['path']) ? (string)$baseSheet['path'] : '';
        $baseSheetXml = $zip->getFromName($baseSheetPath);
        if ($baseSheetXml === false || $baseSheetXml === '') {
            $zip->close();
            error_log('[labor_consultant_export] base sheet xml missing');
            return array('ok' => false, 'message' => '현장명 시트 XML을 읽을 수 없습니다.');
        }

        $sheetDoc = new DOMDocument('1.0', 'UTF-8');
        $sheetDoc->preserveWhiteSpace = true;
        $sheetDoc->formatOutput = false;
        if (!@$sheetDoc->loadXML($baseSheetXml)) {
            $zip->close();
            error_log('[labor_consultant_export] sheet load failed');
            return array('ok' => false, 'message' => '현장명 시트 XML을 읽을 수 없습니다.');
        }

        $sharedStrings = cpms_labor_consultant_xlsx_shared_strings($zip);
        $layout = cpms_labor_consultant_detect_two_row_block($sheetDoc, $sharedStrings, isset($baseSheet['name']) ? $baseSheet['name'] : '');
        $zip->close();

        if (!isset($layout['ok']) || !$layout['ok']) {
            error_log('[labor_consultant_export] layout detection failed');
            return array('ok' => false, 'message' => '노무사 확인용 양식 구조를 인식하지 못했습니다.');
        }

        return array('ok' => true, 'message' => 'OK');
    }
}

if (!function_exists('cpms_labor_consultant_create_export_file_v3')) {
    function cpms_labor_consultant_create_export_file_v3($templatePath, $dataRows, $options) {
        if (!is_array($options)) $options = array();
        $ym = isset($options['ym']) ? cpms_labor_consultant_normalize_ym($options['ym']) : cpms_labor_consultant_current_ym();

        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string') || !class_exists('DOMDocument')) {
            error_log('[labor_consultant_export] library missing');
            return array('ok' => false, 'message' => '엑셀 생성 라이브러리를 찾을 수 없습니다.');
        }
        if (!is_file($templatePath)) {
            error_log('[labor_consultant_export] template missing');
            return array('ok' => false, 'message' => '등록된 양식 파일이 없습니다.');
        }
        if (!is_array($dataRows) || count($dataRows) < 1) {
            error_log('[labor_consultant_export] no data');
            return array('ok' => false, 'message' => '선택한 현장/기간에 노무비 데이터가 없습니다.');
        }

        $firstWorkerName = isset($dataRows[0]['worker_name']) ? trim((string)$dataRows[0]['worker_name']) : '';
        if ($firstWorkerName === '') {
            error_log('[labor_consultant_export] first worker name empty');
            return array('ok' => false, 'message' => '첫 번째 데이터 행 성명칸이 비어 있습니다.');
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'cpms_labor_');
        if ($tmpBase === false) {
            error_log('[labor_consultant_export] temp file create failed');
            return array('ok' => false, 'message' => '양식 파일을 복사할 수 없습니다.');
        }
        $tmpPath = $tmpBase . '.xlsx';
        @unlink($tmpPath);
        if (!@copy($templatePath, $tmpPath)) {
            @unlink($tmpBase);
            error_log('[labor_consultant_export] template copy failed');
            return array('ok' => false, 'message' => '양식 파일을 복사할 수 없습니다.');
        }
        @unlink($tmpBase);

        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            @unlink($tmpPath);
            error_log('[labor_consultant_export] template open failed');
            return array('ok' => false, 'message' => '양식 파일을 ZipArchive로 열 수 없습니다.');
        }

        $baseSheet = cpms_labor_consultant_xlsx_find_export_base_sheet($zip);
        if (!$baseSheet) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] base sheet missing');
            return array('ok' => false, 'message' => '현장명 또는 통합 시트를 찾지 못했습니다.');
        }

        $baseSheetPath = isset($baseSheet['path']) ? (string)$baseSheet['path'] : '';
        $baseSheetXml = $zip->getFromName($baseSheetPath);
        if ($baseSheetXml === false || $baseSheetXml === '') {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] base sheet xml missing');
            return array('ok' => false, 'message' => '현장명 시트 XML을 읽을 수 없습니다.');
        }

        $sharedStrings = cpms_labor_consultant_xlsx_shared_strings($zip);
        $fallbackLabel = isset($options['project_label']) ? trim((string)$options['project_label']) : '';
        $groups = cpms_labor_consultant_group_rows_by_project($dataRows, $fallbackLabel);
        if (count($groups) < 1) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] grouped rows empty');
            return array('ok' => false, 'message' => '선택한 현장/기간에 노무비 데이터가 없습니다.');
        }

        $outputSheets = array();
        $activeOutputPath = '';
        $idx = 0;
        foreach ($groups as $group) {
            $groupRows = isset($group['rows']) && is_array($group['rows']) ? $group['rows'] : array();
            if (count($groupRows) < 1) continue;

            $groupName = isset($group['project_name']) ? trim((string)$group['project_name']) : '';
            if ($groupName === '') $groupName = $fallbackLabel !== '' ? $fallbackLabel : '현장';

            if ($idx === 0) {
                $sheet = $baseSheet;
            } else {
                $newSheetPath = cpms_labor_consultant_xlsx_next_sheet_path($zip);
                $baseRels = cpms_labor_consultant_sheet_rels_path($baseSheetPath);
                $newRels = cpms_labor_consultant_sheet_rels_path($newSheetPath);
                $relsXml = $zip->getFromName($baseRels);
                if ($relsXml !== false && $relsXml !== '') {
                    $zip->addFromString($newRels, $relsXml);
                }
                cpms_labor_consultant_xlsx_add_content_type_override($zip, $newSheetPath, 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');
                $sheet = array('name' => cpms_labor_consultant_sheet_name($groupName), 'path' => $newSheetPath, 'rid' => '', 'index' => 0);
            }

            $sheetPath = isset($sheet['path']) ? (string)$sheet['path'] : '';
            $sheetDoc = new DOMDocument('1.0', 'UTF-8');
            $sheetDoc->preserveWhiteSpace = true;
            $sheetDoc->formatOutput = false;
            if (!@$sheetDoc->loadXML($baseSheetXml)) {
                $zip->close();
                @unlink($tmpPath);
                error_log('[labor_consultant_export] sheet load failed');
                return array('ok' => false, 'message' => '현장명 시트 XML을 읽을 수 없습니다.');
            }

            $layout = cpms_labor_consultant_detect_two_row_block($sheetDoc, $sharedStrings, isset($baseSheet['name']) ? $baseSheet['name'] : '');
            if (!isset($layout['ok']) || !$layout['ok']) {
                $zip->close();
                @unlink($tmpPath);
                error_log('[labor_consultant_export] layout detection failed');
                return array('ok' => false, 'message' => '노무사 확인용 양식 구조를 인식하지 못했습니다.');
            }

            try {
                $filled = cpms_labor_consultant_fill_two_row_blocks($sheetDoc, $layout, $groupRows, $ym);
            } catch (Exception $e) {
                $zip->close();
                @unlink($tmpPath);
                error_log('[labor_consultant_export] fill exception: ' . $e->getMessage());
                return array('ok' => false, 'message' => '근로자 데이터 입력 중 오류가 발생했습니다: ' . $e->getMessage());
            }
            if (!$filled) {
                $zip->close();
                @unlink($tmpPath);
                error_log('[labor_consultant_export] fill failed');
                return array('ok' => false, 'message' => '근로자 데이터 입력 행을 만들 수 없습니다.');
            }

            $groupOptions = $options;
            $groupOptions['project_label'] = $groupName;
            cpms_labor_consultant_fill_summary_cells($sheetDoc, $sharedStrings, $groupRows, $ym, $groupOptions);
            cpms_labor_consultant_update_sheet_formula_sheet_name($sheetDoc, isset($baseSheet['name']) ? (string)$baseSheet['name'] : '', $groupName);

            if (!$zip->addFromString($sheetPath, $sheetDoc->saveXML())) {
                $zip->close();
                @unlink($tmpPath);
                error_log('[labor_consultant_export] sheet write failed');
                return array('ok' => false, 'message' => '생성된 엑셀 파일을 저장하지 못했습니다.');
            }

            $outputSheets[count($outputSheets)] = array('path' => $sheetPath, 'name' => $groupName);
            if ($activeOutputPath === '') $activeOutputPath = $sheetPath;
            $idx++;
        }

        if (count($outputSheets) < 1) {
            $zip->close();
            @unlink($tmpPath);
            error_log('[labor_consultant_export] no output sheets');
            return array('ok' => false, 'message' => '선택한 현장/기간에 노무비 데이터가 없습니다.');
        }

        $sheetPaths = array();
        foreach ($outputSheets as $outputSheet) {
            if (is_array($outputSheet) && isset($outputSheet['path'])) {
                $sheetPaths[count($sheetPaths)] = (string)$outputSheet['path'];
            }
        }
        cpms_labor_consultant_xlsx_apply_worksheet_content_types($zip, $sheetPaths);
        cpms_labor_consultant_xlsx_apply_output_sheets($zip, $outputSheets, $activeOutputPath);
        cpms_labor_consultant_xlsx_force_recalc($zip);
        cpms_labor_consultant_xlsx_clear_formula_cached_values($zip);
        if ($zip->close() === false) {
            @unlink($tmpPath);
            error_log('[labor_consultant_export] zip close failed');
            return array('ok' => false, 'message' => '생성된 엑셀 파일을 저장하지 못했습니다.');
        }
        cpms_labor_export_remove_calc_chain($tmpPath);

        if (!is_file($tmpPath) || filesize($tmpPath) < 1000) {
            @unlink($tmpPath);
            error_log('[labor_consultant_export] generated file empty');
            return array('ok' => false, 'message' => '생성된 엑셀 파일이 비어 있습니다.');
        }

        return array('ok' => true, 'path' => $tmpPath);
    }
}
