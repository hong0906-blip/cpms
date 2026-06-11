<?php
/**
 * 공사 DB 설정 화면
 * - 공사 기본 테이블 + 원가/공정 입력 테이블 생성
 * - PHP 5.6 호환
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/construction/partials/schedule_auto_progress_helper.php';
require_once __DIR__ . '/../app/views/construction/partials/equipment_gongsu_approval_helper.php';
require_once __DIR__ . '/../app/views/construction/partials/master_dedupe_helper.php';
require_once __DIR__ . '/../app/views/construction/partials/material_statement_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!(Auth::canManageConstruction() || Auth::canManageEmployees())) { http_response_code(403); echo '403'; exit; }
$pdo = Db::pdo(); if (!$pdo) { echo 'DB 연결 실패'; exit; }

function table_exists2($pdo, $table) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $st->bindValue(':t', $table); $st->execute(); return ((int)$st->fetchColumn() > 0);
}
function column_exists2($pdo, $table, $column) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :c");
        $st->bindValue(':c', $column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}
function add_db_check(&$checks, $kind, $target, $status, $message) {
    array_push($checks, array('kind'=>$kind, 'target'=>$target, 'status'=>$status, 'message'=>$message));
}
function index_columns2($pdo, $table, $indexName) {
    $cols = array();
    try {
        $st = $pdo->prepare("SHOW INDEX FROM `" . $table . "` WHERE Key_name = :idx");
        $st->bindValue(':idx', $indexName);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return $cols;
        usort($rows, function($a, $b){
            $aa = isset($a['Seq_in_index']) ? (int)$a['Seq_in_index'] : 0;
            $bb = isset($b['Seq_in_index']) ? (int)$b['Seq_in_index'] : 0;
            if ($aa === $bb) return 0;
            return ($aa < $bb) ? -1 : 1;
        });
        foreach ($rows as $row) {
            $cols[count($cols)] = isset($row['Column_name']) ? (string)$row['Column_name'] : '';
        }
    } catch (Exception $e) {
        $cols = array();
    }
    return $cols;
}
function has_exact_index2($pdo, $table, $columns) {
    if (!$pdo || !is_array($columns) || count($columns) === 0) return false;
    try {
        $st = $pdo->query("SHOW INDEX FROM `" . $table . "`");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($rows)) return false;
        $byIndex = array();
        foreach ($rows as $row) {
            $idx = isset($row['Key_name']) ? (string)$row['Key_name'] : '';
            if ($idx === '') continue;
            if (!isset($byIndex[$idx])) $byIndex[$idx] = array();
            $seq = isset($row['Seq_in_index']) ? (int)$row['Seq_in_index'] : 0;
            $byIndex[$idx][$seq] = isset($row['Column_name']) ? (string)$row['Column_name'] : '';
        }
        foreach ($byIndex as $colMap) {
            ksort($colMap);
            $idxCols = array_values($colMap);
            if ($idxCols === $columns) return true;
        }
    } catch (Exception $e) {
        return false;
    }
    return false;
}
function ensure_usage_unique_index2($pdo, $table, $legacyIndexName, $newIndexName, $secondColumn) {
    if (!$pdo || !table_exists2($pdo, $table)) return '테이블 없음';
    if (has_exact_index2($pdo, $table, array('project_id', $secondColumn, 'use_date'))) {
        return '복합 UNIQUE 확인 완료';
    }
    try {
        if (cpms_equipment_gongsu_index_exists($pdo, $table, $legacyIndexName)) {
            $pdo->exec("ALTER TABLE `" . $table . "` DROP INDEX `" . $legacyIndexName . "`");
        }
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE `" . $table . "` ADD UNIQUE KEY `" . $newIndexName . "` (project_id, `" . $secondColumn . "`, use_date)");
        return '복합 UNIQUE 추가 완료';
    } catch (Exception $e) {
        return '복합 UNIQUE 추가 실패: ' . $e->getMessage();
    }
}
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { $err = '보안 토큰 오류'; }
    else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        try {
            if ($action === 'base') {
                if (!table_exists2($pdo, 'cpms_construction_roles')) {
                    $pdo->exec("CREATE TABLE cpms_construction_roles (project_id INT PRIMARY KEY, site_employee_id INT NULL, safety_employee_id INT NULL, quality_employee_id INT NULL, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8");
                }
                if (!table_exists2($pdo, 'cpms_process_templates')) {
                    $pdo->exec("CREATE TABLE cpms_process_templates (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, process_name VARCHAR(255) NOT NULL, sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_project_id (project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
                }
                if (!table_exists2($pdo, 'cpms_schedule_tasks')) {
                    $pdo->exec("CREATE TABLE cpms_schedule_tasks (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, parent_id INT NULL, name VARCHAR(255) NOT NULL, start_date DATE NULL, end_date DATE NULL, progress INT NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_project_id (project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
                }
                if (!table_exists2($pdo, 'cpms_safety_incidents')) {
                    $pdo->exec("CREATE TABLE cpms_safety_incidents (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, title VARCHAR(255) NOT NULL, description TEXT NULL, occurred_at DATETIME NULL, created_by_name VARCHAR(100) NOT NULL, created_by_email VARCHAR(255) NULL, status VARCHAR(20) NOT NULL DEFAULT '접수', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_project_id (project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
                }
                cpms_ensure_labor_override_table($pdo);
                cpms_schedule_auto_ensure_schema($pdo);
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_google_chat_notifications (id INT AUTO_INCREMENT PRIMARY KEY, source_type VARCHAR(50) NOT NULL, source_id INT NULL, event_type VARCHAR(50) NULL, receiver_employee_id INT NULL, receiver_name VARCHAR(100) NULL, receiver_email VARCHAR(190) NULL, dm_space_name VARCHAR(255) NULL, message_text TEXT NULL, send_status VARCHAR(20) NULL, error_message TEXT NULL, sent_at DATETIME NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8");
                $msg = '공사 기본 테이블 및 공정표 자동 완료수량(오늘 포함)/공수 승인/Google Chat 알림 테이블 생성/확인 완료';
            } else if ($action === 'cost_progress') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_daily_work_qty (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    unit_price_id INT NOT NULL,
                    work_date DATE NOT NULL,
                    done_qty DECIMAL(18,4) NULL,
                    memo VARCHAR(255) DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_project_unit_day (project_id, unit_price_id, work_date),
                    KEY idx_project_date (project_id, work_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_daily_cost_entries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    cost_date DATE NOT NULL,
                    cost_type VARCHAR(30) NOT NULL,
                    amount DECIMAL(18,2) NOT NULL,
                    memo VARCHAR(255) DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_project_date (project_id, cost_date), KEY idx_cost_type (cost_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_monthly_recognized (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    ym VARCHAR(7) NOT NULL,
                    recognized_cum_amount DECIMAL(18,2) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_project_ym (project_id, ym)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $msg = '원가/공정 입력 테이블 생성/확인 완료';

            } else if ($action === 'work_items') {
                // 작업내용 레이어 추가
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_work_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    description TEXT NULL,
                    sort_order INT NOT NULL DEFAULT 0,
                    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    -- 구버전 MySQL 호환: CURRENT_TIMESTAMP는 테이블당 1개만 사용
                    updated_at TIMESTAMP NULL DEFAULT NULL,
                    KEY idx_project_id (project_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // 기존 테이블 보정 (MySQL 5.6/구버전 호환)
                if (table_exists2($pdo, 'cpms_work_items')) {
                    $createdCol = null; $updatedCol = null;
                    try {
                        $stCreated = $pdo->query("SHOW COLUMNS FROM cpms_work_items LIKE 'created_at'");
                        $createdCol = ($stCreated) ? $stCreated->fetch(PDO::FETCH_ASSOC) : null;
                    } catch (Exception $eCreated) { $createdCol = null; }
                    try {
                        $stUpdated = $pdo->query("SHOW COLUMNS FROM cpms_work_items LIKE 'updated_at'");
                        $updatedCol = ($stUpdated) ? $stUpdated->fetch(PDO::FETCH_ASSOC) : null;
                    } catch (Exception $eUpdated) { $updatedCol = null; }

                    // created_at/updated_at 상태 확인
                    $createdDefault = '';
                    if ($createdCol && isset($createdCol['Default'])) {
                        $createdDefault = strtoupper((string)$createdCol['Default']);
                    }
                    // created_at은 CURRENT_TIMESTAMP 유지가 목표이며, 여기서는 상태만 확인한다.
                    // (기존 데이터/환경 안전성 때문에 created_at ALTER는 수행하지 않음)
                    if ($createdDefault === 'CURRENT_TIMESTAMP') { /* noop */ }
                    $needFixUpdatedAt = false;
                    if (!$updatedCol) {
                        $needFixUpdatedAt = true;
                    } else {
                        $updatedDefault = isset($updatedCol['Default']) ? strtoupper((string)$updatedCol['Default']) : '';
                        $updatedExtra = isset($updatedCol['Extra']) ? strtoupper((string)$updatedCol['Extra']) : '';
                        if ($updatedDefault === 'CURRENT_TIMESTAMP' || strpos($updatedExtra, 'ON UPDATE CURRENT_TIMESTAMP') !== false) {
                            $needFixUpdatedAt = true;
                        }
                    }

                    if ($needFixUpdatedAt) {
                        if (!$updatedCol) {
                            $pdo->exec("ALTER TABLE cpms_work_items ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL");
                        } else {
                            $pdo->exec("ALTER TABLE cpms_work_items MODIFY COLUMN updated_at TIMESTAMP NULL DEFAULT NULL");
                        }
                    }
                }

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_work_item_lines (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    work_id INT NOT NULL,
                    unit_price_id INT NOT NULL,
                    planned_qty DECIMAL(18,4) NULL,
                    note VARCHAR(255) NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_work_unit (work_id, unit_price_id),
                    KEY idx_work_id (work_id),
                    KEY idx_unit_price_id (unit_price_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $hasWorkId = false;
                try {
                    $stCol = $pdo->query("SHOW COLUMNS FROM cpms_schedule_tasks LIKE 'work_id'");
                    $hasWorkId = ($stCol && $stCol->fetch()) ? true : false;
                } catch (Exception $eCol) { $hasWorkId = false; }
                if (!$hasWorkId) {
                    $hasParentId = false;
                    try {
                        $stParent = $pdo->query("SHOW COLUMNS FROM cpms_schedule_tasks LIKE 'parent_id'");
                        $hasParentId = ($stParent && $stParent->fetch()) ? true : false;
                    } catch (Exception $eParent) { $hasParentId = false; }
                    if ($hasParentId) {
                        $pdo->exec("ALTER TABLE cpms_schedule_tasks ADD COLUMN work_id INT NULL AFTER parent_id");
                    } else {
                        $pdo->exec("ALTER TABLE cpms_schedule_tasks ADD COLUMN work_id INT NULL");
                    }
                }
                try {
                    $stIdx = $pdo->query("SHOW INDEX FROM cpms_schedule_tasks WHERE Key_name = 'idx_work_id'");
                    $hasIdx = ($stIdx && $stIdx->fetch()) ? true : false;
                    if (!$hasIdx) {
                        $pdo->exec("ALTER TABLE cpms_schedule_tasks ADD INDEX idx_work_id (work_id)");
                    }
                } catch (Exception $eIdx) {}

                $msg = '작업 테이블 생성/확인 완료';

            } else if ($action === 'task_item_progress') {
                cpms_schedule_auto_ensure_schema($pdo);
                $msg = '공정표 날짜별/항목별 자동 완료수량(오늘 포함) 테이블 및 is_auto/is_manual 컬럼 생성/확인 완료';

            } else if ($action === 'equipment') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_equipment_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    category VARCHAR(50) NOT NULL,
                    vendor_name VARCHAR(100) NOT NULL,
                    spec VARCHAR(100) DEFAULT '',
                    representative VARCHAR(50) DEFAULT '',
                    phone VARCHAR(30) DEFAULT '',
                    biz_no VARCHAR(30) DEFAULT '',
                    base_rate DECIMAL(18,2) NOT NULL DEFAULT 0,
                    remark VARCHAR(255) DEFAULT '',
                    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    -- 구버전 MySQL 호환: CURRENT_TIMESTAMP는 테이블당 1개만 사용
                    updated_at TIMESTAMP NULL DEFAULT NULL,
                    KEY idx_project_id (project_id),
                    KEY idx_category (category)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_equipment_usage (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    equipment_id INT NOT NULL,
                    use_date DATE NOT NULL,
                    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                    memo VARCHAR(255) DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_equipment_day (equipment_id, use_date),
                    KEY idx_project_date (project_id, use_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                cpms_equipment_gongsu_ensure_schema($pdo);
                $uniqueMsg = ensure_usage_unique_index2($pdo, 'cpms_equipment_usage', 'uniq_equipment_day', 'uniq_project_equipment_day', 'equipment_id');
                $msg = '장비 입력 테이블 및 장비공수(work_unit/base_rate_snapshot/is_manual_unit) 승인 테이블 생성/확인 완료 / ' . $uniqueMsg;
                } else if ($action === 'equipment_vendor_presets') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_equipment_vendor_presets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    vendor_name VARCHAR(100) NOT NULL UNIQUE,
                    category VARCHAR(50) DEFAULT '',
                    representative VARCHAR(50) DEFAULT '',
                    phone VARCHAR(30) DEFAULT '',
                    biz_no VARCHAR(30) DEFAULT '',
                    base_rate DECIMAL(18,2) NOT NULL DEFAULT 0,
                    remark VARCHAR(255) DEFAULT '',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $msg = '장비 업체 프리셋 테이블 생성/확인 완료';                
                } else if ($action === 'materials_purchase' || $action === 'materials') {
                // 자재구입비(장비 방식 복제)
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_material_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    category VARCHAR(50) NOT NULL,
                    vendor_name VARCHAR(100) NOT NULL,
                    spec VARCHAR(100) DEFAULT '',
                    representative VARCHAR(50) DEFAULT '',
                    phone VARCHAR(30) DEFAULT '',
                    biz_no VARCHAR(30) DEFAULT '',
                    base_rate DECIMAL(18,2) NOT NULL DEFAULT 0,
                    remark VARCHAR(255) DEFAULT '',
                    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT NULL,
                    KEY idx_project_id (project_id),
                    KEY idx_category (category)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_material_usage (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    material_id INT NOT NULL,
                    use_date DATE NOT NULL,
                    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                    memo VARCHAR(255) DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_material_day (material_id, use_date),
                    KEY idx_project_date (project_id, use_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                cpms_material_statement_ensure_schema($pdo);
                $uniqueMsg = ensure_usage_unique_index2($pdo, 'cpms_material_usage', 'uniq_material_day', 'uniq_project_material_day', 'material_id');
                $msg = '자재구입비/거래명세표 파일 테이블 생성/확인 완료. 구분은 자재비/구매품/기타경비/안전관리비만 사용합니다. / ' . $uniqueMsg;
                } else if ($action === 'material_vendor_presets') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_material_vendor_presets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    vendor_name VARCHAR(100) NOT NULL UNIQUE,
                    category VARCHAR(50) DEFAULT '',
                    representative VARCHAR(50) DEFAULT '',
                    phone VARCHAR(30) DEFAULT '',
                    biz_no VARCHAR(30) DEFAULT '',
                    base_rate DECIMAL(18,2) NOT NULL DEFAULT 0,
                    remark VARCHAR(255) DEFAULT '',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $msg = '자재 업체 프리셋 테이블 생성/확인 완료';                                
                } else if ($action === 'material_dedupe_apply') {
                $applyResult = cpms_material_dedupe_apply($pdo);
                $msg = '자재 중복 병합 실행 완료. ' . (isset($applyResult['message']) ? $applyResult['message'] : '');
                } else if ($action === 'equipment_dedupe_apply') {
                $applyResult = cpms_equipment_dedupe_apply($pdo);
                $msg = '장비 중복 병합 실행 완료. ' . (isset($applyResult['message']) ? $applyResult['message'] : '');
                }
        } catch (Exception $e) { $err = $e->getMessage(); }
    }
}
$materialDuplicateScan = cpms_material_duplicate_groups($pdo);
$equipmentDuplicateScan = cpms_equipment_duplicate_groups($pdo);
$checks = array();
if ($pdo) {
    $tableChecks = array('cpms_schedule_progress', 'cpms_schedule_task_item_progress', 'cpms_equipment_gongsu_overrides', 'cpms_material_statement_files');
    foreach ($tableChecks as $tbl) {
        add_db_check($checks, 'TABLE', $tbl, table_exists2($pdo, $tbl) ? '성공' : '주의', table_exists2($pdo, $tbl) ? '존재' : '아직 생성되지 않음');
    }
    $columnChecks = array(
        array('cpms_schedule_progress', 'is_auto'),
        array('cpms_schedule_progress', 'is_manual'),
        array('cpms_schedule_task_item_progress', 'work_date'),
        array('cpms_schedule_task_item_progress', 'is_auto'),
        array('cpms_schedule_task_item_progress', 'is_manual'),
        array('cpms_equipment_usage', 'work_unit'),
        array('cpms_equipment_usage', 'base_rate_snapshot'),
        array('cpms_equipment_usage', 'amount'),
        array('cpms_equipment_usage', 'is_manual_unit'),
        array('cpms_material_items', 'category'),
        array('cpms_material_statement_files', 'project_id'),
        array('cpms_material_statement_files', 'material_id'),
        array('cpms_material_statement_files', 'material_usage_id'),
        array('cpms_material_statement_files', 'stored_path'),
        array('cpms_material_statement_files', 'is_deleted')
    );
    foreach ($columnChecks as $cc) {
        $target = $cc[0] . '.' . $cc[1];
        add_db_check($checks, 'COLUMN', $target, column_exists2($pdo, $cc[0], $cc[1]) ? '성공' : '주의', column_exists2($pdo, $cc[0], $cc[1]) ? '존재' : '버튼 실행 필요');
    }
    add_db_check($checks, 'UNIQUE', 'cpms_material_usage(project_id, material_id, use_date)', has_exact_index2($pdo, 'cpms_material_usage', array('project_id', 'material_id', 'use_date')) ? '성공' : '주의', has_exact_index2($pdo, 'cpms_material_usage', array('project_id', 'material_id', 'use_date')) ? '복합 UNIQUE 확인 완료' : '자재구입비 테이블 생성/확인 버튼 재실행 또는 중복 정리 필요');
    add_db_check($checks, 'UNIQUE', 'cpms_equipment_usage(project_id, equipment_id, use_date)', has_exact_index2($pdo, 'cpms_equipment_usage', array('project_id', 'equipment_id', 'use_date')) ? '성공' : '주의', has_exact_index2($pdo, 'cpms_equipment_usage', array('project_id', 'equipment_id', 'use_date')) ? '복합 UNIQUE 확인 완료' : '장비 입력 테이블 생성/확인 버튼 재실행 또는 중복 정리 필요');
    add_db_check($checks, 'INDEX', 'cpms_material_statement_files(material_usage_id)', cpms_material_statement_index_exists($pdo, 'idx_material_usage_id') ? '성공' : '주의', cpms_material_statement_index_exists($pdo, 'idx_material_usage_id') ? '존재' : '자재구입비 테이블 생성/확인 버튼 실행 필요');
    add_db_check($checks, 'DEDUPE', '자재구입비 중복 업체 점검', '확인', '중복 그룹 ' . (int)$materialDuplicateScan['summary']['group_count'] . '개 / 자동 병합 가능 ' . (int)$materialDuplicateScan['summary']['mergeable_count'] . '개 / 충돌 ' . (int)$materialDuplicateScan['summary']['conflict_count'] . '개');
    add_db_check($checks, 'DEDUPE', '장비 중복 그룹 점검', '확인', '중복 그룹 ' . (int)$equipmentDuplicateScan['summary']['group_count'] . '개 / 자동 병합 가능 ' . (int)$equipmentDuplicateScan['summary']['mergeable_count'] . '개 / 충돌 ' . (int)$equipmentDuplicateScan['summary']['conflict_count'] . '개');
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>공사 DB 설정</title><style>body{font-family:Arial;background:#f6f7fb;padding:24px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;max-width:900px}.btn{padding:10px 14px;border-radius:10px;border:1px solid #111;background:#111;color:#fff;font-weight:700}.ok{background:#ecfdf5;padding:10px}.bad{background:#fef2f2;padding:10px}.row{display:flex;gap:10px;flex-wrap:wrap}</style></head><body>
<div class="card"><h2>공사 DB 설정</h2>
<?php if ($msg!==''): ?><div class="ok"><?php echo h($msg); ?></div><?php endif; ?>
<?php if ($err!==''): ?><div class="bad"><?php echo h($err); ?></div><?php endif; ?>
<div class="row">
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="base"><button class="btn" type="submit">1) 공사 기본 테이블 생성/확인</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="cost_progress"><button class="btn" type="submit">2) 원가/공정 입력 테이블 생성/확인</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="equipment"><button class="btn" type="submit">3) 장비 입력 테이블 생성/확인</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="materials_purchase"><button class="btn" type="submit">4) 자재구입비 테이블 생성/확인</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="work_items"><button class="btn" type="submit">5) 작업 테이블 생성/확인</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="task_item_progress"><button class="btn" type="submit">6) 공정표 항목완료수량 테이블 생성/확인</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="equipment_vendor_presets"><button class="btn" type="submit">7) 장비 업체 프리셋 테이블 생성/확인</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="material_vendor_presets"><button class="btn" type="submit">8) 자재 업체 프리셋 테이블 생성/확인</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="material_dedupe_apply"><button class="btn" type="submit">9) 자재 중복 병합 실행</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="equipment_dedupe_apply"><button class="btn" type="submit">10) 장비 중복 병합 실행</button></form>
</div>
<h3>주요 확인 항목</h3>
<table style="border-collapse:collapse;width:100%;max-width:900px;margin-top:10px">
<thead><tr><th style="border:1px solid #ddd;padding:6px;text-align:left">구분</th><th style="border:1px solid #ddd;padding:6px;text-align:left">대상</th><th style="border:1px solid #ddd;padding:6px;text-align:left">결과</th><th style="border:1px solid #ddd;padding:6px;text-align:left">메시지</th></tr></thead>
<tbody>
<?php foreach ($checks as $c): ?>
<tr><td style="border:1px solid #ddd;padding:6px"><?php echo h($c['kind']); ?></td><td style="border:1px solid #ddd;padding:6px"><?php echo h($c['target']); ?></td><td style="border:1px solid #ddd;padding:6px"><?php echo h($c['status']); ?></td><td style="border:1px solid #ddd;padding:6px"><?php echo h($c['message']); ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<h3 style="margin-top:22px">자재구입비 중복 업체 점검</h3>
<div style="margin-bottom:10px">중복 그룹 <?php echo (int)$materialDuplicateScan['summary']['group_count']; ?>개 / 자동 병합 가능 <?php echo (int)$materialDuplicateScan['summary']['mergeable_count']; ?>개 / 충돌 <?php echo (int)$materialDuplicateScan['summary']['conflict_count']; ?>개</div>
<table style="border-collapse:collapse;width:100%;max-width:900px">
<thead><tr><th style="border:1px solid #ddd;padding:6px;text-align:left">프로젝트</th><th style="border:1px solid #ddd;padding:6px;text-align:left">구분</th><th style="border:1px solid #ddd;padding:6px;text-align:left">업체명</th><th style="border:1px solid #ddd;padding:6px;text-align:left">공급가액</th><th style="border:1px solid #ddd;padding:6px;text-align:left">대표ID</th><th style="border:1px solid #ddd;padding:6px;text-align:left">중복ID</th><th style="border:1px solid #ddd;padding:6px;text-align:left">결과</th></tr></thead>
<tbody>
<?php if (count($materialDuplicateScan['groups']) === 0): ?>
<tr><td colspan="7" style="border:1px solid #ddd;padding:6px">중복 그룹이 없습니다.</td></tr>
<?php else: ?>
<?php foreach ($materialDuplicateScan['groups'] as $group): ?>
<tr>
<td style="border:1px solid #ddd;padding:6px"><?php echo (int)$group['project_id']; ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo h($group['category']); ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo h($group['vendor_name']); ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo h(number_format((float)$group['base_rate'], 0)); ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo (int)$group['main_id']; ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo h(implode(', ', $group['duplicate_ids'])); ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo isset($group['mergeable']) && $group['mergeable'] ? '자동 병합 가능' : '충돌'; ?><?php if (!empty($group['conflicts'])): ?> / <?php echo h(isset($group['conflicts'][0]['reason']) ? $group['conflicts'][0]['reason'] : 'conflict'); ?><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
<h3 style="margin-top:22px">장비 중복 그룹 점검</h3>
<div style="margin-bottom:10px">중복 그룹 <?php echo (int)$equipmentDuplicateScan['summary']['group_count']; ?>개 / 자동 병합 가능 <?php echo (int)$equipmentDuplicateScan['summary']['mergeable_count']; ?>개 / 충돌 <?php echo (int)$equipmentDuplicateScan['summary']['conflict_count']; ?>개</div>
<table style="border-collapse:collapse;width:100%;max-width:900px">
<thead><tr><th style="border:1px solid #ddd;padding:6px;text-align:left">프로젝트</th><th style="border:1px solid #ddd;padding:6px;text-align:left">구분</th><th style="border:1px solid #ddd;padding:6px;text-align:left">업체명</th><th style="border:1px solid #ddd;padding:6px;text-align:left">규격</th><th style="border:1px solid #ddd;padding:6px;text-align:left">기본단가</th><th style="border:1px solid #ddd;padding:6px;text-align:left">대표ID</th><th style="border:1px solid #ddd;padding:6px;text-align:left">중복ID</th><th style="border:1px solid #ddd;padding:6px;text-align:left">결과</th></tr></thead>
<tbody>
<?php if (count($equipmentDuplicateScan['groups']) === 0): ?>
<tr><td colspan="8" style="border:1px solid #ddd;padding:6px">중복 그룹이 없습니다.</td></tr>
<?php else: ?>
<?php foreach ($equipmentDuplicateScan['groups'] as $group): ?>
<tr>
<td style="border:1px solid #ddd;padding:6px"><?php echo (int)$group['project_id']; ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo h($group['category']); ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo h($group['vendor_name']); ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo h($group['spec']); ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo h(number_format((float)$group['base_rate'], 0)); ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo (int)$group['main_id']; ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo h(implode(', ', $group['duplicate_ids'])); ?></td>
<td style="border:1px solid #ddd;padding:6px"><?php echo isset($group['mergeable']) && $group['mergeable'] ? '자동 병합 가능' : '충돌'; ?><?php if (!empty($group['conflicts'])): ?> / <?php echo h(isset($group['conflicts'][0]['reason']) ? $group['conflicts'][0]['reason'] : 'conflict'); ?><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div></body></html>
