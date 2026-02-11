<?php
/**
 * 공사 DB 설정 화면
 * - 공사 기본 테이블 + 원가/공정 입력 테이블 생성
 * - PHP 5.6 호환
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole(); $dept = Auth::userDepartment();
if (!($role === 'executive' || $dept === '공사' || $dept === '관리' || $dept === '관리부')) { http_response_code(403); echo '403'; exit; }
$pdo = Db::pdo(); if (!$pdo) { echo 'DB 연결 실패'; exit; }

function table_exists2($pdo, $table) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $st->bindValue(':t', $table); $st->execute(); return ((int)$st->fetchColumn() > 0);
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
                $msg = '공사 기본 테이블 생성/확인 완료';
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
            }
        } catch (Exception $e) { $err = $e->getMessage(); }
    }
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>공사 DB 설정</title><style>body{font-family:Arial;background:#f6f7fb;padding:24px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;max-width:900px}.btn{padding:10px 14px;border-radius:10px;border:1px solid #111;background:#111;color:#fff;font-weight:700}.ok{background:#ecfdf5;padding:10px}.bad{background:#fef2f2;padding:10px}.row{display:flex;gap:10px;flex-wrap:wrap}</style></head><body>
<div class="card"><h2>공사 DB 설정</h2>
<?php if ($msg!==''): ?><div class="ok"><?php echo h($msg); ?></div><?php endif; ?>
<?php if ($err!==''): ?><div class="bad"><?php echo h($err); ?></div><?php endif; ?>
<div class="row">
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="base"><button class="btn" type="submit">1) 공사 기본 테이블 생성/확인</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="cost_progress"><button class="btn" type="submit">2) 원가/공정 입력 테이블 생성/확인</button></form>
</div>
</div></body></html>