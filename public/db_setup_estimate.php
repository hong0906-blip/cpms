<?php
/**
 * 견적관리 DB 설정
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/estimate/helpers.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: text/html; charset=utf-8');

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canAccessEstimate()) { http_response_code(403); echo '403 Forbidden'; exit; }

$pdo = Db::pdo();
if (!$pdo) { echo '<h2 style="font-family:Arial">DB 연결 실패</h2>'; exit; }

function estimate_setup_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function estimate_setup_column_exists($pdo, $table, $column) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
    $st->bindValue(':table_name', (string)$table);
    $st->bindValue(':column_name', (string)$column);
    $st->execute();
    return ((int)$st->fetchColumn() > 0);
}

function estimate_setup_add_column($pdo, $table, $column, $sql) {
    if (!estimate_setup_column_exists($pdo, $table, $column)) {
        $pdo->exec($sql);
        return true;
    }
    return false;
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
            if ($action === 'create_tables') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_estimate_price_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_name VARCHAR(255) DEFAULT '',
                    sub_project_name VARCHAR(255) DEFAULT '',
                    work_type VARCHAR(100) DEFAULT '',
                    item_name VARCHAR(255) NOT NULL,
                    spec VARCHAR(255) DEFAULT '',
                    unit VARCHAR(50) DEFAULT '',
                    client VARCHAR(255) DEFAULT '',
                    section_name VARCHAR(255) DEFAULT '',
                    contractor VARCHAR(255) DEFAULT '',
                    price_type VARCHAR(30) NOT NULL DEFAULT 'contract',
                    source_type VARCHAR(30) NOT NULL DEFAULT 'manual',
                    source_name VARCHAR(255) DEFAULT '',
                    contract_amount DECIMAL(18,4) NULL,
                    material_unit_price DECIMAL(18,4) NULL,
                    labor_unit_price DECIMAL(18,4) NULL,
                    expense_unit_price DECIMAL(18,4) NULL,
                    unit_price DECIMAL(18,4) NOT NULL DEFAULT 0,
                    contract_date DATE NULL,
                    bid_result VARCHAR(50) DEFAULT '',
                    reflect_yn TINYINT(1) NOT NULL DEFAULT 1,
                    created_by INT NULL,
                    created_by_name VARCHAR(100) DEFAULT '',
                    created_by_email VARCHAR(190) DEFAULT '',
                    created_at DATETIME NOT NULL,
                    remark TEXT NULL,
                    KEY idx_est_price_item (item_name(100), unit(20)),
                    KEY idx_est_price_exact (work_type(30), item_name(60), spec(50), unit(20)),
                    KEY idx_est_price_reflect (reflect_yn, price_type, contract_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                estimate_setup_add_column($pdo, 'cpms_estimate_price_history', 'project_name', "ALTER TABLE cpms_estimate_price_history ADD COLUMN project_name VARCHAR(255) DEFAULT '' AFTER id");
                estimate_setup_add_column($pdo, 'cpms_estimate_price_history', 'sub_project_name', "ALTER TABLE cpms_estimate_price_history ADD COLUMN sub_project_name VARCHAR(255) DEFAULT '' AFTER project_name");
                estimate_setup_add_column($pdo, 'cpms_estimate_price_history', 'contract_amount', "ALTER TABLE cpms_estimate_price_history ADD COLUMN contract_amount DECIMAL(18,4) NULL AFTER source_name");
                estimate_setup_add_column($pdo, 'cpms_estimate_price_history', 'material_unit_price', "ALTER TABLE cpms_estimate_price_history ADD COLUMN material_unit_price DECIMAL(18,4) NULL AFTER contract_amount");
                estimate_setup_add_column($pdo, 'cpms_estimate_price_history', 'labor_unit_price', "ALTER TABLE cpms_estimate_price_history ADD COLUMN labor_unit_price DECIMAL(18,4) NULL AFTER material_unit_price");
                estimate_setup_add_column($pdo, 'cpms_estimate_price_history', 'expense_unit_price', "ALTER TABLE cpms_estimate_price_history ADD COLUMN expense_unit_price DECIMAL(18,4) NULL AFTER labor_unit_price");

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_estimates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    estimate_date DATE NOT NULL,
                    project_name VARCHAR(255) NOT NULL,
                    client VARCHAR(255) DEFAULT '',
                    section_name VARCHAR(255) DEFAULT '',
                    contractor VARCHAR(255) DEFAULT '',
                    work_character VARCHAR(100) DEFAULT '',
                    work_kind VARCHAR(100) DEFAULT '',
                    include_indirect TINYINT(1) NOT NULL DEFAULT 0,
                    difficulty VARCHAR(50) DEFAULT '',
                    estimate_type VARCHAR(50) DEFAULT '',
                    remark TEXT NULL,
                    total_amount DECIMAL(18,4) NULL,
                    bid_result VARCHAR(50) DEFAULT '',
                    final_contract_amount DECIMAL(18,4) NULL,
                    created_by INT NULL,
                    created_by_name VARCHAR(100) DEFAULT '',
                    created_by_email VARCHAR(190) DEFAULT '',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NULL,
                    KEY idx_estimates_date (estimate_date),
                    KEY idx_estimates_project (project_name(100))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_estimate_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    estimate_id INT NOT NULL,
                    line_no INT NOT NULL DEFAULT 0,
                    work_type VARCHAR(100) DEFAULT '',
                    item_name VARCHAR(255) NOT NULL,
                    spec VARCHAR(255) DEFAULT '',
                    unit VARCHAR(50) DEFAULT '',
                    qty DECIMAL(18,4) NULL,
                    recommended_unit_price DECIMAL(18,4) NULL,
                    submitted_unit_price DECIMAL(18,4) NULL,
                    amount DECIMAL(18,4) NULL,
                    recommendation_json TEXT NULL,
                    reflect_yn TINYINT(1) NOT NULL DEFAULT 1,
                    remark VARCHAR(255) DEFAULT '',
                    created_at DATETIME NOT NULL,
                    KEY idx_estimate_items_estimate (estimate_id),
                    KEY idx_estimate_items_item (item_name(100), unit(20))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_estimate_bid_results (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    estimate_id INT NOT NULL,
                    bid_result VARCHAR(50) NOT NULL,
                    final_contract_amount DECIMAL(18,4) NULL,
                    failure_reason VARCHAR(255) DEFAULT '',
                    special_note TEXT NULL,
                    reflect_yn TINYINT(1) NOT NULL DEFAULT 1,
                    created_by INT NULL,
                    created_by_name VARCHAR(100) DEFAULT '',
                    created_by_email VARCHAR(190) DEFAULT '',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NULL,
                    KEY idx_bid_result_estimate (estimate_id),
                    KEY idx_bid_result_result (bid_result)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_estimate_bid_result_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    bid_result_id INT NOT NULL,
                    estimate_item_id INT NULL,
                    work_type VARCHAR(100) DEFAULT '',
                    item_name VARCHAR(255) NOT NULL,
                    spec VARCHAR(255) DEFAULT '',
                    unit VARCHAR(50) DEFAULT '',
                    qty DECIMAL(18,4) NULL,
                    program_recommended_unit_price DECIMAL(18,4) NULL,
                    submitted_unit_price DECIMAL(18,4) NULL,
                    final_contract_unit_price DECIMAL(18,4) NULL,
                    reflect_yn TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    KEY idx_bid_result_items_result (bid_result_id),
                    KEY idx_bid_result_items_item (item_name(100), unit(20))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_estimate_categories (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    category_code VARCHAR(20) DEFAULT '',
                    category_name VARCHAR(100) NOT NULL,
                    item_code VARCHAR(20) DEFAULT '',
                    parent_name VARCHAR(100) DEFAULT '',
                    item_name VARCHAR(255) NOT NULL,
                    item_note VARCHAR(255) DEFAULT '',
                    sort_order INT NOT NULL DEFAULT 0,
                    source_name VARCHAR(255) DEFAULT '',
                    created_at DATETIME NOT NULL,
                    UNIQUE KEY uk_estimate_category (category_name(40), item_code, parent_name(40), item_name(80)),
                    KEY idx_estimate_category_name (category_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $type = 'success';
                $msg = '견적관리 테이블 생성/확인 완료';
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

$ready = cpms_estimate_tables_ready($pdo);
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>견적관리 DB 설정</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f6f7fb;margin:0;padding:24px;color:#111827;}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;max-width:900px;}
    .btn{padding:12px 14px;border-radius:12px;border:0;background:#111827;color:#fff;cursor:pointer;font-weight:800;}
    .msg{margin:12px 0;padding:12px;border-radius:12px;font-weight:800;}
    .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;}
    .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
    .muted{color:#6b7280;font-size:13px;}
    code{background:#f3f4f6;padding:2px 6px;border-radius:8px;}
    a{color:#2563eb;font-weight:800;text-decoration:none;}
  </style>
</head>
<body>
<div class="card">
  <h2 style="margin:0 0 8px;">견적관리 DB 설정</h2>
  <p class="muted">견적서, 품목, 과거 단가, 입찰 결과 저장 테이블을 생성/확인합니다.</p>

  <?php if ($msg !== ''): ?>
    <div class="msg <?php echo ($type === 'success') ? 'ok' : 'err'; ?>"><?php echo estimate_setup_h($msg); ?></div>
  <?php endif; ?>

  <div class="msg <?php echo $ready ? 'ok' : 'err'; ?>">
    현재 상태: <?php echo $ready ? '필수 테이블이 준비되어 있습니다.' : '필수 테이블 생성이 필요합니다.'; ?>
  </div>

  <form method="post" style="margin:0 0 14px 0;">
    <input type="hidden" name="_csrf" value="<?php echo estimate_setup_h(csrf_token()); ?>">
    <input type="hidden" name="action" value="create_tables">
    <button type="submit" class="btn">견적관리 테이블 생성/확인</button>
  </form>

  <p>
    <a href="<?php echo estimate_setup_h(base_url()); ?>/?r=estimate_home">견적관리로 이동</a>
  </p>
  <p class="muted">운영 배포 후에는 보안을 위해 <code>public/db_setup_estimate.php</code> 접근을 제한하거나 삭제하는 것을 권장합니다.</p>
</div>
</body>
</html>
