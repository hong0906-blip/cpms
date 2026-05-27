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
            $st = $pdo->query("SELECT id, name FROM cpms_projects ORDER BY name ASC, id ASC");
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
            $st = $pdo->prepare("SELECT id, name FROM cpms_projects WHERE id = :id LIMIT 1");
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

if (!function_exists('cpms_labor_consultant_load_project_month_rows')) {
    function cpms_labor_consultant_load_project_month_rows($pdo, $projectRow, $ym) {
        require_once __DIR__ . '/../construction/tabs/partials/labor_data_loader.php';

        $rows = array();
        if (!$pdo || !is_array($projectRow)) return $rows;

        $projectId = isset($projectRow['id']) ? (int)$projectRow['id'] : 0;
        $projectName = isset($projectRow['name']) ? trim((string)$projectRow['name']) : '';
        if ($projectId <= 0 || $projectName === '') return $rows;

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
                'worker_name' => $workerName,
                'role' => isset($roleMap[$workerKey]) ? (string)$roleMap[$workerKey] : '',
                'wage_rate' => $wageRate,
                'output_days' => $outputDays,
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

if (!function_exists('cpms_labor_consultant_reindex_row_node')) {
    function cpms_labor_consultant_reindex_row_node($rowNode, $newRowNum) {
        $newRowNum = (int)$newRowNum;
        $rowNode->setAttribute('r', (string)$newRowNum);
        foreach ($rowNode->childNodes as $child) {
            if (!($child instanceof DOMElement)) continue;
            if ($child->localName !== 'c') continue;
            $ref = $child->getAttribute('r');
            list(, $colIndex) = cpms_labor_consultant_xlsx_ref_to_pos($ref);
            if ($colIndex <= 0) continue;
            $child->setAttribute('r', cpms_labor_consultant_xlsx_col_to_letter($colIndex) . $newRowNum);
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
