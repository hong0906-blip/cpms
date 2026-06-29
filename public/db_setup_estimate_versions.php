<?php
/**
 * CPMS estimate version DB setup.
 * - PHP 5.6 compatible
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: text/html; charset=utf-8');

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }

$pdo = Db::pdo();
if (!$pdo) { echo '<h2 style="font-family:Arial">DB 연결 실패</h2>'; exit; }

function cpms_estimate_setup_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function cpms_estimate_setup_table_exists($pdo, $table) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $st->bindValue(':t', (string)$table);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}

function cpms_estimate_setup_column_exists($pdo, $table, $column) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $st->bindValue(':t', (string)$table);
        $st->bindValue(':c', (string)$column);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}

function cpms_estimate_setup_after_clause($pdo, $table, $column) {
    if (cpms_estimate_setup_column_exists($pdo, $table, $column)) {
        return " AFTER `" . $column . "`";
    }
    return "";
}

function cpms_estimate_setup_add_column($pdo, $table, $column, $sql, &$added) {
    if (!cpms_estimate_setup_table_exists($pdo, $table)) return;
    if (!cpms_estimate_setup_column_exists($pdo, $table, $column)) {
        $pdo->exec($sql);
        array_push($added, $table . '.' . $column);
    }
}

$msg = '';
$type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        $type = 'error';
        $msg = '보안 토큰이 유효하지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        try {
            if ($action === 'setup') {
                $added = array();

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_estimate_versions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    version_type VARCHAR(20) NOT NULL,
                    version_no INT NOT NULL DEFAULT 1,
                    title VARCHAR(255) NOT NULL,
                    description TEXT NULL,
                    original_file_name VARCHAR(255) DEFAULT '',
                    stored_file_path VARCHAR(500) DEFAULT '',
                    uploaded_by INT NULL,
                    uploaded_by_name VARCHAR(100) DEFAULT '',
                    uploaded_at DATETIME NULL,
                    applied_at DATETIME NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
                    item_count INT NOT NULL DEFAULT 0,
                    added_count INT NOT NULL DEFAULT 0,
                    changed_count INT NOT NULL DEFAULT 0,
                    removed_count INT NOT NULL DEFAULT 0,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    KEY idx_project (project_id),
                    KEY idx_project_type_no (project_id, version_type, version_no),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'estimate_version_id', "ALTER TABLE cpms_project_unit_prices ADD COLUMN estimate_version_id INT NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'project_id'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'trade_group', "ALTER TABLE cpms_project_unit_prices ADD COLUMN trade_group VARCHAR(255) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'estimate_version_id'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'sub_trade', "ALTER TABLE cpms_project_unit_prices ADD COLUMN sub_trade VARCHAR(255) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'trade_group'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'location_name', "ALTER TABLE cpms_project_unit_prices ADD COLUMN location_name VARCHAR(255) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'sub_trade'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'work_group', "ALTER TABLE cpms_project_unit_prices ADD COLUMN work_group VARCHAR(255) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'location_name'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'sub_work_group', "ALTER TABLE cpms_project_unit_prices ADD COLUMN sub_work_group VARCHAR(255) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'work_group'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'item_name', "ALTER TABLE cpms_project_unit_prices ADD COLUMN item_name VARCHAR(255) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'sub_work_group'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'original_item_name', "ALTER TABLE cpms_project_unit_prices ADD COLUMN original_item_name VARCHAR(255) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'item_name'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'spec', "ALTER TABLE cpms_project_unit_prices ADD COLUMN spec VARCHAR(255) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'original_item_name'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'unit', "ALTER TABLE cpms_project_unit_prices ADD COLUMN unit VARCHAR(50) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'spec'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'qty', "ALTER TABLE cpms_project_unit_prices ADD COLUMN qty DECIMAL(18,4) NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'unit'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'unit_price', "ALTER TABLE cpms_project_unit_prices ADD COLUMN unit_price DECIMAL(18,4) NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'qty'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'material_unit_price', "ALTER TABLE cpms_project_unit_prices ADD COLUMN material_unit_price DECIMAL(18,4) NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'unit_price'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'labor_unit_price', "ALTER TABLE cpms_project_unit_prices ADD COLUMN labor_unit_price DECIMAL(18,4) NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'material_unit_price'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'expense_unit_price', "ALTER TABLE cpms_project_unit_prices ADD COLUMN expense_unit_price DECIMAL(18,4) NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'labor_unit_price'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'amount', "ALTER TABLE cpms_project_unit_prices ADD COLUMN amount DECIMAL(18,4) NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'expense_unit_price'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'source_row', "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_row INT NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'amount'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'source_row_no', "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_row_no INT NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'source_row'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'source_sheet_name', "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_sheet_name VARCHAR(100) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'source_row_no'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'source_type', "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_type VARCHAR(20) DEFAULT 'ORIGINAL'" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'source_sheet_name'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'source_version_no', "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_version_no INT NULL" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'source_type'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'item_fingerprint', "ALTER TABLE cpms_project_unit_prices ADD COLUMN item_fingerprint CHAR(40) DEFAULT ''" . cpms_estimate_setup_after_clause($pdo, 'cpms_project_unit_prices', 'source_version_no'), $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'is_active', "ALTER TABLE cpms_project_unit_prices ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1", $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'is_current', "ALTER TABLE cpms_project_unit_prices ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1", $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_prices', 'updated_at', "ALTER TABLE cpms_project_unit_prices ADD COLUMN updated_at DATETIME NULL", $added);

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_unit_price_change_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    unit_price_id INT NULL,
                    change_type VARCHAR(30) NOT NULL,
                    before_json TEXT NULL,
                    after_json TEXT NULL,
                    item_name VARCHAR(255) NULL,
                    spec VARCHAR(255) NULL,
                    unit VARCHAR(50) NULL,
                    old_quantity DECIMAL(18,4) NULL,
                    new_quantity DECIMAL(18,4) NULL,
                    old_unit_price DECIMAL(18,4) NULL,
                    new_unit_price DECIMAL(18,4) NULL,
                    old_amount DECIMAL(18,4) NULL,
                    new_amount DECIMAL(18,4) NULL,
                    estimate_version_id INT NULL,
                    source_type VARCHAR(20) DEFAULT '',
                    source_version_no INT NULL,
                    match_priority VARCHAR(30) DEFAULT '',
                    created_by INT NULL,
                    created_at DATETIME NULL,
                    KEY idx_project (project_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_price_change_logs', 'old_amount', "ALTER TABLE cpms_project_unit_price_change_logs ADD COLUMN old_amount DECIMAL(18,4) NULL", $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_price_change_logs', 'new_amount', "ALTER TABLE cpms_project_unit_price_change_logs ADD COLUMN new_amount DECIMAL(18,4) NULL", $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_price_change_logs', 'estimate_version_id', "ALTER TABLE cpms_project_unit_price_change_logs ADD COLUMN estimate_version_id INT NULL", $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_price_change_logs', 'source_type', "ALTER TABLE cpms_project_unit_price_change_logs ADD COLUMN source_type VARCHAR(20) DEFAULT ''", $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_price_change_logs', 'source_version_no', "ALTER TABLE cpms_project_unit_price_change_logs ADD COLUMN source_version_no INT NULL", $added);
                cpms_estimate_setup_add_column($pdo, 'cpms_project_unit_price_change_logs', 'match_priority', "ALTER TABLE cpms_project_unit_price_change_logs ADD COLUMN match_priority VARCHAR(30) DEFAULT ''", $added);

                try { $pdo->exec("ALTER TABLE cpms_project_unit_prices ADD INDEX idx_estimate_version_id (estimate_version_id)"); } catch (Exception $eIdx1) {}
                try { $pdo->exec("ALTER TABLE cpms_project_unit_prices ADD INDEX idx_project_source (project_id, source_type, source_version_no)"); } catch (Exception $eIdx2) {}
                try { $pdo->exec("ALTER TABLE cpms_project_unit_prices ADD INDEX idx_item_fingerprint (item_fingerprint)"); } catch (Exception $eIdx3) {}

                $type = 'success';
                $msg = (count($added) === 0) ? '내역서 버전 DB 설정이 이미 적용되어 있습니다.' : ('내역서 버전 DB 설정 완료: ' . implode(', ', $added));
            } else {
                $type = 'error';
                $msg = '알 수 없는 요청입니다.';
            }
        } catch (Exception $e) {
            $type = 'error';
            $msg = '실행 실패: ' . $e->getMessage();
        }
    }
}

$versionReady = cpms_estimate_setup_table_exists($pdo, 'cpms_project_estimate_versions');
$unitReady = cpms_estimate_setup_table_exists($pdo, 'cpms_project_unit_prices') && cpms_estimate_setup_column_exists($pdo, 'cpms_project_unit_prices', 'item_fingerprint');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>내역서 버전 DB 설정</title>
<style>
body{font-family:Arial,'Noto Sans KR',sans-serif;background:#f6f7fb;margin:0;padding:24px;color:#111827}
.card{max-width:980px;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px}
.btn{padding:12px 14px;border:0;border-radius:12px;background:#111827;color:#fff;font-weight:800;cursor:pointer}
.msg{margin:12px 0;padding:12px;border-radius:12px;font-weight:800}
.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.muted{font-size:13px;color:#6b7280}
</style>
</head>
<body>
<div class="card">
    <h2 style="margin:0 0 8px;">내역서 버전 DB 설정</h2>
    <p class="muted">CPMS 표준내역서의 당초/변경계약/추가공사 버전 이력과 단가내역 확장 컬럼을 생성/확인합니다.</p>

    <?php if ($msg !== ''): ?>
        <div class="msg <?php echo ($type === 'success') ? 'ok' : 'err'; ?>"><?php echo cpms_estimate_setup_h($msg); ?></div>
    <?php endif; ?>

    <div class="msg <?php echo ($versionReady && $unitReady) ? 'ok' : 'err'; ?>">
        현재 상태: <?php echo ($versionReady && $unitReady) ? '필수 테이블/컬럼이 준비되어 있습니다.' : '설정 버튼 실행이 필요합니다.'; ?>
    </div>

    <form method="post">
        <input type="hidden" name="_csrf" value="<?php echo cpms_estimate_setup_h(csrf_token()); ?>">
        <input type="hidden" name="action" value="setup">
        <button type="submit" class="btn">내역서 버전 DB 생성/확인</button>
    </form>

    <p style="margin-top:16px;"><a href="<?php echo cpms_estimate_setup_h(base_url()); ?>/?r=공무">공무로 이동</a></p>
</div>
</body>
</html>
