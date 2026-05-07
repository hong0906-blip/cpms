<?php
/**
 * 출퇴근 DB 설정
 * - 출퇴근 DB 설정 500 방지
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!(Auth::isMaster() || Auth::canManageEmployees())) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$pdo = Db::pdo();
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$logs = array();
$errors = array();
$success = false;

function table_exists($pdo, $table)
{
    $st = $pdo->prepare('SHOW TABLES LIKE :table');
    $st->execute(array(':table' => $table));
    return (bool)$st->fetchColumn();
}

function column_exists($pdo, $table, $column)
{
    $sql = "SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE :column";
    $st = $pdo->prepare($sql);
    $st->execute(array(':column' => $column));
    return (bool)$st->fetchColumn();
}

function safe_exec($pdo, $sql, &$logs)
{
    $pdo->exec($sql);
    $logs[] = '실행 성공: ' . $sql;
}

function ensure_settings_table($pdo, &$logs)
{
    // settings 테이블 보장 후 seed
    $sql = "CREATE TABLE IF NOT EXISTS cpms_attendance_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
    safe_exec($pdo, $sql, $logs);
}

function seed_settings($pdo, &$logs)
{
    $defaults = array(
        'standard_weekly_hours' => '40',
        'max_weekly_hours' => '52',
        'daily_break_deduct_minutes' => '120',
        'under_one_year_monthly_leave' => '1',
        'half_day_amount' => '0.5',
        'leave_rule_after_one_year' => 'half_year',
        'week_start' => 'monday',
    );
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare('REPLACE INTO cpms_attendance_settings(setting_key, setting_value, updated_at) VALUES(:k, :v, :u)');

    foreach ($defaults as $k => $v) {
        $st->execute(array(':k' => $k, ':v' => $v, ':u' => $now));
        $logs[] = '설정 seed 적용: ' . $k;
    }
}

try {
    if (!$pdo) {
        throw new Exception('DB 연결 실패');
    }

    if ($action !== '') {
        // 어떤 버튼이든 settings 테이블은 먼저 조용히 보장
        ensure_settings_table($pdo, $logs);

        if ($action === 'records' || $action === 'all') {
            safe_exec($pdo, "CREATE TABLE IF NOT EXISTS cpms_attendance_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                work_date DATE NOT NULL,
                check_in DATETIME NULL,
                check_out DATETIME NULL,
                status VARCHAR(30) NOT NULL DEFAULT '출근전',
                raw_minutes INT NOT NULL DEFAULT 0,
                work_minutes INT NOT NULL DEFAULT 0,
                memo VARCHAR(255) DEFAULT '',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_emp_date(employee_id, work_date),
                INDEX idx_work_date(work_date),
                INDEX idx_employee_id(employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8", $logs);

            // 중복 컬럼 확인 후 추가
            if (!column_exists($pdo, 'cpms_attendance_records', 'raw_minutes')) {
                safe_exec($pdo, "ALTER TABLE cpms_attendance_records ADD COLUMN raw_minutes INT NOT NULL DEFAULT 0", $logs);
            } else {
                $logs[] = 'raw_minutes 컬럼 이미 존재';
            }
        }

        if ($action === 'requests' || $action === 'all') {
            safe_exec($pdo, "CREATE TABLE IF NOT EXISTS cpms_attendance_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                request_date DATE NOT NULL,
                request_type VARCHAR(30) NOT NULL,
                requested_check_in DATETIME NULL,
                requested_check_out DATETIME NULL,
                reason TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                reviewed_by INT NULL,
                reviewed_at DATETIME NULL,
                reject_reason TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_employee_id(employee_id),
                INDEX idx_request_date(request_date),
                INDEX idx_status(status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8", $logs);
        }

        if ($action === 'leave' || $action === 'all') {
            safe_exec($pdo, "CREATE TABLE IF NOT EXISTS cpms_leave_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                leave_date DATE NOT NULL,
                leave_type VARCHAR(30) NOT NULL,
                leave_amount DECIMAL(4,2) NOT NULL DEFAULT 1.0,
                reason VARCHAR(255) DEFAULT '',
                created_by INT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_employee_id(employee_id),
                INDEX idx_leave_date(leave_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8", $logs);

            safe_exec($pdo, "CREATE TABLE IF NOT EXISTS cpms_leave_adjustments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                leave_type VARCHAR(30) NOT NULL,
                amount DECIMAL(6,2) NOT NULL,
                reason VARCHAR(255) DEFAULT '',
                created_by INT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_employee_id(employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8", $logs);
        }

        if ($action === 'settings' || $action === 'all') {
            ensure_settings_table($pdo, $logs);
            seed_settings($pdo, $logs);
        }

        if (in_array($action, array('records', 'requests', 'leave', 'settings', 'all'), true)) {
            $success = true;
            $logs[] = '처리 완료: ' . $action;
        }
    }
} catch (PDOException $e) {
    $errors[] = 'PDO 오류: ' . $e->getMessage();
} catch (Exception $e) {
    $errors[] = '오류: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>출퇴근 DB 설정</title>
</head>
<body style="font-family:Arial, sans-serif; margin:20px; color:#111;">
  <h2 style="margin:0 0 12px 0;">출퇴근 DB 설정</h2>
  <div style="font-size:13px; color:#555; margin-bottom:6px;"><strong>파일:</strong> <?php echo h(__FILE__); ?></div>
  <div style="font-size:13px; color:#555; margin-bottom:12px;"><strong>action:</strong> <?php echo h($action === '' ? '(none)' : $action); ?></div>

  <?php if (!empty($errors)): ?>
    <div style="background:#fee2e2;border:1px solid #dc2626;color:#991b1b;padding:10px 12px;border-radius:8px;margin-bottom:12px;">
      <strong>오류 발생</strong>
      <ul style="margin:8px 0 0 18px; padding:0;">
        <?php foreach ($errors as $e): ?>
          <li><?php echo h($e); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php elseif ($success): ?>
    <div style="background:#dcfce7;border:1px solid #16a34a;color:#166534;padding:10px 12px;border-radius:8px;margin-bottom:12px;">
      처리 성공
    </div>
  <?php endif; ?>

  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
    <form method="post" style="margin:0;"><button type="submit" name="action" value="records" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">records 생성/확인</button></form>
    <form method="post" style="margin:0;"><button type="submit" name="action" value="requests" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">requests 생성/확인</button></form>
    <form method="post" style="margin:0;"><button type="submit" name="action" value="leave" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">leave 생성/확인</button></form>
    <form method="post" style="margin:0;"><button type="submit" name="action" value="settings" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">settings 생성/확인</button></form>
    <form method="post" style="margin:0;"><button type="submit" name="action" value="all" style="padding:8px 12px;border:1px solid #15803d;border-radius:8px;background:#16a34a;color:#fff;cursor:pointer;">all 생성/확인</button></form>
  </div>

  <div style="margin-bottom:12px;"><a href="?r=관리&amp;tab=attendance" style="color:#1d4ed8;">관리 화면으로 돌아가기</a></div>

  <h3 style="font-size:16px; margin:16px 0 8px 0;">처리 로그</h3>
  <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#fafafa;">
    <?php if (empty($logs)): ?>
      <div style="color:#666;">아직 실행 로그가 없습니다.</div>
    <?php else: ?>
      <ul style="margin:0 0 0 18px;padding:0;">
        <?php foreach ($logs as $line): ?>
          <li style="margin:4px 0;"><?php echo h($line); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</body>
</html>