<?php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location: ?r=login'); exit; }
$pdo=Db::pdo(); $msgs=array();
try {
$pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_monthly_deductions (
id INT AUTO_INCREMENT PRIMARY KEY,
project_id INT NOT NULL,
ym CHAR(7) NOT NULL,
deduction_name VARCHAR(120) NOT NULL,
amount DECIMAL(15,2) NOT NULL DEFAULT 0,
memo VARCHAR(255) NULL,
created_at DATETIME NULL,
updated_at DATETIME NULL,
INDEX idx_project_ym (project_id, ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$msgs[]='cpms_project_monthly_deductions 확인/생성 완료';
$col=$pdo->query("SHOW COLUMNS FROM cpms_schedule_task_item_progress LIKE 'work_date'")->fetch();
if(!$col){ $pdo->exec("ALTER TABLE cpms_schedule_task_item_progress ADD COLUMN work_date DATE NULL AFTER done_qty"); $msgs[]='cpms_schedule_task_item_progress.work_date 추가 완료'; }
else { $msgs[]='cpms_schedule_task_item_progress.work_date 이미 존재'; }
} catch (Exception $e) { $msgs[]='오류: '.$e->getMessage(); }
?><!doctype html><html><head><meta charset="utf-8"><title>공무 DB 설치/확인</title></head><body><h2>공무 DB 설치/확인</h2><ul><?php foreach($msgs as $m){ ?><li><?php echo h($m); ?></li><?php } ?></ul><p><a href="?r=공무&tab=monthly_input">공무 화면으로 돌아가기</a></p></body></html>