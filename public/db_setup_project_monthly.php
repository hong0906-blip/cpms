<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/construction/partials/schedule_auto_progress_helper.php';
require_once __DIR__ . '/../app/views/construction/partials/equipment_gongsu_approval_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$dept = (string)Auth::userDepartment();
$role = (string)Auth::userRole();
$ok = Auth::isMaster() || $role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부';
if (!$ok) { http_response_code(403); echo '403 Forbidden'; exit; }

$pdo = Db::pdo();
$results = array();
function add_result(&$results, $kind, $target, $status, $message) {
    array_push($results, array(
        'kind' => $kind,
        'target' => $target,
        'status' => $status,
        'message' => $message
    ));
}
if (!$pdo) {
    add_result($results, 'SYSTEM', 'DB', '오류', 'DB 연결 실패');
} else {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_monthly_deductions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            ym VARCHAR(7) NOT NULL,
            deduction_name VARCHAR(190) NOT NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            memo TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_project_ym (project_id, ym)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        add_result($results, 'TABLE', 'cpms_project_monthly_deductions', '성공', '확인/생성 완료');
    } catch (Exception $e) {
        add_result($results, 'TABLE', 'cpms_project_monthly_deductions', '오류', '처리 오류: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_monthly_summary_remarks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            ym VARCHAR(7) NOT NULL,
            remark TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uk_project_monthly_summary_remark (project_id, ym),
            KEY idx_ym (ym)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        add_result($results, 'TABLE', 'cpms_project_monthly_summary_remarks', '성공', '확인/생성 완료');
    } catch (Exception $e) {
        add_result($results, 'TABLE', 'cpms_project_monthly_summary_remarks', '오류', '처리 오류: ' . $e->getMessage());
    }

    try {
        $st = $pdo->prepare("SHOW TABLES LIKE 'cpms_project_unit_prices'");
        $st->execute();
        $tb = $st->fetch();
        if (!is_array($tb)) {
            add_result($results, 'TABLE', 'cpms_project_unit_prices', '오류', '테이블 없음');
        } else {
            $col = $pdo->query("SHOW COLUMNS FROM cpms_project_unit_prices LIKE 'is_active'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE cpms_project_unit_prices ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
                add_result($results, 'COLUMN', 'cpms_project_unit_prices.is_active', '성공', '추가 완료');
            } else {
                add_result($results, 'COLUMN', 'cpms_project_unit_prices.is_active', '성공', '이미 존재');
            }

            $col = $pdo->query("SHOW COLUMNS FROM cpms_project_unit_prices LIKE 'updated_at'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE cpms_project_unit_prices ADD COLUMN updated_at DATETIME NULL');
                add_result($results, 'COLUMN', 'cpms_project_unit_prices.updated_at', '성공', '추가 완료');
            } else {
                add_result($results, 'COLUMN', 'cpms_project_unit_prices.updated_at', '성공', '이미 존재');
            }

            $unitPriceExtraColumns = array(
                array('expense_unit_price', 'DECIMAL(18,4) NULL'),
                array('amount', 'DECIMAL(18,4) NULL'),
                array('source_row', 'INT NULL'),
                array('import_order', 'INT NULL')
            );
            foreach ($unitPriceExtraColumns as $colDef) {
                $colName = $colDef[0];
                $colSql = $colDef[1];
                $col = $pdo->query("SHOW COLUMNS FROM cpms_project_unit_prices LIKE '" . $colName . "'")->fetch();
                if (!$col) {
                    $pdo->exec('ALTER TABLE cpms_project_unit_prices ADD COLUMN ' . $colName . ' ' . $colSql);
                    add_result($results, 'COLUMN', 'cpms_project_unit_prices.' . $colName, '성공', '추가 완료');
                } else {
                    add_result($results, 'COLUMN', 'cpms_project_unit_prices.' . $colName, '성공', '이미 존재');
                }
            }
        }
    } catch (Exception $e) {
        add_result($results, 'TABLE', 'cpms_project_unit_prices', '오류', '컬럼 확인/추가 오류: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_unit_price_change_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            unit_price_id INT NULL,
            change_type VARCHAR(30) NOT NULL,
            before_json TEXT NULL,
            after_json TEXT NULL,
            old_item_name VARCHAR(255) NULL,
            new_item_name VARCHAR(255) NULL,
            old_spec VARCHAR(255) NULL,
            new_spec VARCHAR(255) NULL,
            old_unit VARCHAR(50) NULL,
            new_unit VARCHAR(50) NULL,
            old_qty DECIMAL(18,4) NULL,
            new_qty DECIMAL(18,4) NULL,
            old_unit_price DECIMAL(18,4) NULL,
            new_unit_price DECIMAL(18,4) NULL,
            old_labor_unit_price DECIMAL(18,4) NULL,
            new_labor_unit_price DECIMAL(18,4) NULL,
            old_material_unit_price DECIMAL(18,4) NULL,
            new_material_unit_price DECIMAL(18,4) NULL,
            old_safety_unit_price DECIMAL(18,4) NULL,
            new_safety_unit_price DECIMAL(18,4) NULL,
            old_is_safety TINYINT(1) NULL,
            new_is_safety TINYINT(1) NULL,
            memo TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NULL,
            INDEX idx_project_id (project_id),
            INDEX idx_unit_price_id (unit_price_id),
            INDEX idx_change_type (change_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        add_result($results, 'TABLE', 'cpms_project_unit_price_change_logs', '성공', '확인/생성 완료');

        $col = $pdo->query("SHOW COLUMNS FROM cpms_project_unit_price_change_logs LIKE 'before_json'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE cpms_project_unit_price_change_logs ADD COLUMN before_json TEXT NULL AFTER change_type');
            add_result($results, 'COLUMN', 'cpms_project_unit_price_change_logs.before_json', '성공', '추가 완료');
        } else {
            add_result($results, 'COLUMN', 'cpms_project_unit_price_change_logs.before_json', '성공', '이미 존재');
        }

        $col = $pdo->query("SHOW COLUMNS FROM cpms_project_unit_price_change_logs LIKE 'after_json'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE cpms_project_unit_price_change_logs ADD COLUMN after_json TEXT NULL AFTER before_json');
            add_result($results, 'COLUMN', 'cpms_project_unit_price_change_logs.after_json', '성공', '추가 완료');
        } else {
            add_result($results, 'COLUMN', 'cpms_project_unit_price_change_logs.after_json', '성공', '이미 존재');
        }
    } catch (Exception $e) {
        add_result($results, 'TABLE', 'cpms_project_unit_price_change_logs', '오류', '처리 오류: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_contract_change_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            file_type VARCHAR(50) NULL,
            uploaded_by INT NULL,
            uploaded_at DATETIME NULL,
            applied_token VARCHAR(100) NULL,
            change_summary TEXT NULL,
            INDEX idx_project_id (project_id),
            INDEX idx_uploaded_at (uploaded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        add_result($results, 'TABLE', 'cpms_project_contract_change_files', '성공', '확인/생성 완료');

        $col = $pdo->query("SHOW COLUMNS FROM cpms_project_contract_change_files LIKE 'file_type'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE cpms_project_contract_change_files ADD COLUMN file_type VARCHAR(50) NULL AFTER stored_path');
            add_result($results, 'COLUMN', 'cpms_project_contract_change_files.file_type', '성공', '추가 완료');
        } else {
            add_result($results, 'COLUMN', 'cpms_project_contract_change_files.file_type', '성공', '이미 존재');
        }
    } catch (Exception $e) {
        add_result($results, 'TABLE', 'cpms_project_contract_change_files', '오류', '처리 오류: ' . $e->getMessage());
    }

    try {
        $st = $pdo->prepare("SHOW TABLES LIKE 'cpms_schedule_task_item_progress'");
        $st->execute();
        $tb = $st->fetch();
        if (!is_array($tb)) {
            add_result($results, 'TABLE', 'cpms_schedule_task_item_progress', '오류', '테이블 없음');
        } else {
            $col = $pdo->query("SHOW COLUMNS FROM cpms_schedule_task_item_progress LIKE 'work_date'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE cpms_schedule_task_item_progress ADD COLUMN work_date DATE NULL AFTER done_qty');
                add_result($results, 'COLUMN', 'cpms_schedule_task_item_progress.work_date', '성공', '추가 완료');
            } else {
                add_result($results, 'COLUMN', 'cpms_schedule_task_item_progress.work_date', '성공', '이미 존재');
            }
            try {
                $idxRows = $pdo->query("SHOW INDEX FROM cpms_schedule_task_item_progress")->fetchAll();
                $uniqueMap = array();
                if (is_array($idxRows)) {
                    foreach ($idxRows as $ix) {
                        if (!isset($ix['Key_name'])) continue;
                        $keyName = (string)$ix['Key_name'];
                        $nonUnique = isset($ix['Non_unique']) ? (int)$ix['Non_unique'] : 1;
                        if ($nonUnique !== 0) continue;
                        if (!isset($uniqueMap[$keyName])) $uniqueMap[$keyName] = array();
                        array_push($uniqueMap[$keyName], isset($ix['Column_name']) ? (string)$ix['Column_name'] : '');
                    }
                }
                $hasDateUnique = false;
                foreach ($uniqueMap as $keyCols) {
                    if (in_array('project_id', $keyCols, true) && in_array('task_id', $keyCols, true) && in_array('unit_price_id', $keyCols, true) && in_array('work_date', $keyCols, true)) {
                        $hasDateUnique = true;
                        break;
                    }
                }
                if ($hasDateUnique) {
                    add_result($results, 'INDEX', 'cpms_schedule_task_item_progress UNIQUE KEY', '성공', 'project_id, task_id, unit_price_id, work_date 포함 구조 확인됨');
                } else {
                    add_result($results, 'INDEX', 'cpms_schedule_task_item_progress UNIQUE KEY', '주의', '기존 UNIQUE KEY에 work_date가 없어 같은 공정/항목의 날짜별 진행수량 저장이 막힐 수 있습니다. 추후 버튼식 보정 기능으로 확장 가능하도록 점검 필요');
                }
            } catch (Exception $e) {
                add_result($results, 'INDEX', 'cpms_schedule_task_item_progress', '오류', '인덱스 점검 오류: ' . $e->getMessage());
            }            
        }
    } catch (Exception $e) {
        add_result($results, 'COLUMN', 'cpms_schedule_task_item_progress.work_date', '오류', '확인/추가 오류: ' . $e->getMessage());
    }

    try {
        cpms_schedule_auto_ensure_schema($pdo);
        add_result($results, 'TABLE', 'cpms_schedule_progress', '성공', '확인/생성 완료');
        add_result($results, 'TABLE', 'cpms_schedule_task_item_progress', '성공', '날짜별 항목완료수량 구조 확인/보정 완료');
        $scheduleChecks = array(
            array('cpms_schedule_progress', 'is_auto'),
            array('cpms_schedule_progress', 'is_manual'),
            array('cpms_schedule_task_item_progress', 'work_date'),
            array('cpms_schedule_task_item_progress', 'is_auto'),
            array('cpms_schedule_task_item_progress', 'is_manual')
        );
        foreach ($scheduleChecks as $check) {
            $tbl = $check[0];
            $colName = $check[1];
            $col = $pdo->query("SHOW COLUMNS FROM " . $tbl . " LIKE '" . $colName . "'")->fetch();
            if ($col) {
                add_result($results, 'COLUMN', $tbl . '.' . $colName, '성공', '추가 완료 또는 이미 존재');
            } else {
                add_result($results, 'COLUMN', $tbl . '.' . $colName, '오류', '컬럼 확인 실패');
            }
        }
    } catch (Exception $e) {
        add_result($results, 'TABLE', '공정표 자동 완료수량', '오류', '처리 오류: ' . $e->getMessage());
    }

    try {
        cpms_equipment_gongsu_ensure_schema($pdo);
        $eqTable = $pdo->prepare("SHOW TABLES LIKE 'cpms_equipment_gongsu_overrides'");
        $eqTable->execute();
        $eqTableExists = $eqTable->fetch() ? true : false;
        add_result($results, 'TABLE', 'cpms_equipment_gongsu_overrides', $eqTableExists ? '성공' : '오류', $eqTableExists ? '확인/생성 완료' : '테이블 확인 실패');
    } catch (Exception $e) {
        add_result($results, 'TABLE', 'cpms_equipment_gongsu_overrides', '오류', '처리 오류: ' . $e->getMessage());
    }
    try {
        $equipmentChecks = array('work_unit', 'base_rate_snapshot', 'amount', 'is_manual_unit');
        foreach ($equipmentChecks as $colName) {
            $col = $pdo->query("SHOW COLUMNS FROM cpms_equipment_usage LIKE '" . $colName . "'")->fetch();
            if ($col) {
                add_result($results, 'COLUMN', 'cpms_equipment_usage.' . $colName, '성공', '추가 완료 또는 이미 존재');
            } else {
                add_result($results, 'COLUMN', 'cpms_equipment_usage.' . $colName, '오류', '컬럼 확인 실패');
            }
        }
    } catch (Exception $e) {
        add_result($results, 'COLUMN', 'cpms_equipment_usage', '오류', '컬럼 확인 오류: ' . $e->getMessage());
    }

    try {
        $st = $pdo->prepare("SHOW TABLES LIKE 'cpms_material_items'");
        $st->execute();
        if ($st->fetch()) {
            $col = $pdo->query("SHOW COLUMNS FROM cpms_material_items LIKE 'category'")->fetch();
            add_result($results, 'COLUMN', 'cpms_material_items.category', $col ? '성공' : '오류', $col ? '자재비/구매품/기타경비/안전관리비 구분 컬럼 확인' : 'category 컬럼 없음');
        } else {
            add_result($results, 'TABLE', 'cpms_material_items', '주의', '공사 DB 설정에서 자재구입비 테이블을 먼저 생성하세요');
        }
    } catch (Exception $e) {
        add_result($results, 'COLUMN', 'cpms_material_items.category', '오류', '확인 오류: ' . $e->getMessage());
    }
}
?><!doctype html><html><head><meta charset="utf-8"><title>공무 DB 설치/확인</title><style>table{border-collapse:collapse;width:100%;max-width:1100px}th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#f5f5f5}</style></head><body><h2>공무 DB 설치/확인</h2><table><thead><tr><th>구분</th><th>대상</th><th>결과</th><th>메시지</th></tr></thead><tbody><?php foreach($results as $row){ ?><tr><td><?php echo h($row['kind']); ?></td><td><?php echo h($row['target']); ?></td><td><?php echo h($row['status']); ?></td><td><?php echo h($row['message']); ?></td></tr><?php } ?></tbody></table><p><a href="?r=공무&tab=monthly_summary">공무 화면으로 돌아가기</a></p></body></html>
