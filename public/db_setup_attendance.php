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
$warnings = array();
$repairReports = array();

function table_indexes($pdo, $table)
{
    $st = $pdo->query("SHOW INDEX FROM `" . str_replace('`', '``', $table) . "`");
    return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
}

function attendance_record_index_status($pdo)
{
    $ret = array(
        'has_uq_emp_date' => false,
        'bad_unique_indexes' => array()
    );
    $rows = table_indexes($pdo, 'cpms_attendance_records');
    $map = array();
    foreach ($rows as $r) {
        $key = isset($r['Key_name']) ? (string)$r['Key_name'] : '';
        if ($key === '') continue;
        if (!isset($map[$key])) {
            $map[$key] = array('unique' => ((int)$r['Non_unique'] === 0), 'cols' => array());
        }
        $map[$key]['cols'][(int)$r['Seq_in_index']] = (string)$r['Column_name'];
    }
    foreach ($map as $name => $info) {
        ksort($info['cols']);
        $cols = array_values($info['cols']);
        if ($info['unique'] && count($cols) === 2 && $cols[0] === 'employee_id' && $cols[1] === 'work_date') {
            $ret['has_uq_emp_date'] = true;
        }
        if ($info['unique'] && count($cols) === 1 && $cols[0] === 'employee_id') {
            $ret['bad_unique_indexes'][] = $name;
        }
    }
    return $ret;
}

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

function ensure_geofence_table($pdo, &$logs)
{
    $sql = "CREATE TABLE IF NOT EXISTS cpms_attendance_geofences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        location_type VARCHAR(30) NOT NULL DEFAULT 'office',
        project_id INT NULL,
        project_name VARCHAR(255) NULL,
        lat DECIMAL(10,7) NOT NULL,
        lng DECIMAL(10,7) NOT NULL,
        radius_m INT NOT NULL DEFAULT 50,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        INDEX idx_is_active(is_active),
        INDEX idx_project_id(project_id)
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
        'attendance_geofence_enabled' => '0',
        'attendance_geofence_name' => '',
        'attendance_geofence_lat' => '',
        'attendance_geofence_lng' => '',
        'attendance_geofence_radius_m' => '50',
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

            $idxStatus = attendance_record_index_status($pdo);
            if (!$idxStatus['has_uq_emp_date']) {
                safe_exec($pdo, "ALTER TABLE cpms_attendance_records ADD UNIQUE KEY uq_emp_date(employee_id, work_date)", $logs);
            }
            if (!empty($idxStatus['bad_unique_indexes'])) {
                $warnings[] = '잘못된 인덱스가 있습니다. employee_id 단독 UNIQUE가 있으면 날짜별 출퇴근 기록이 막힙니다.';
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
            ensure_geofence_table($pdo, $logs);
            seed_settings($pdo, $logs);
        }
        if ($action === 'drop_bad_unique_emp') {
            if (!table_exists($pdo, 'cpms_attendance_records')) {
                throw new Exception('cpms_attendance_records 테이블이 없습니다.');
            }
            $idxStatus = attendance_record_index_status($pdo);
            if (empty($idxStatus['bad_unique_indexes'])) {
                $logs[] = 'employee_id 단독 UNIQUE 인덱스가 없습니다.';
            } else {
                foreach ($idxStatus['bad_unique_indexes'] as $idxName) {
                    safe_exec($pdo, "ALTER TABLE cpms_attendance_records DROP INDEX `" . str_replace('`', '``', $idxName) . "`", $logs);
                }
                $logs[] = 'employee_id 단독 UNIQUE 인덱스를 제거했습니다.';
            }
            $idxStatus2 = attendance_record_index_status($pdo);
            if (!$idxStatus2['has_uq_emp_date']) {
                safe_exec($pdo, "ALTER TABLE cpms_attendance_records ADD UNIQUE KEY uq_emp_date(employee_id, work_date)", $logs);
            }
            $success = true;
            $logs[] = '처리 완료: drop_bad_unique_emp';
        }
        if ($action === 'repair_mismatch_records') {
            $stMis = $pdo->query("SELECT * FROM cpms_attendance_records WHERE (check_in IS NOT NULL AND check_in <> '' AND DATE(check_in) <> work_date) OR (check_out IS NOT NULL AND check_out <> '' AND DATE(check_out) <> work_date) ORDER BY id ASC");
            $misRows = $stMis ? $stMis->fetchAll(PDO::FETCH_ASSOC) : array();
            $moved = 0;
            foreach ($misRows as $mr) {
                $ciDate = isset($mr['check_in']) ? substr((string)$mr['check_in'], 0, 10) : '';
                $coDate = isset($mr['check_out']) ? substr((string)$mr['check_out'], 0, 10) : '';
                $targetDate = '';
                if ($ciDate !== '' && $coDate !== '' && $ciDate === $coDate) {
                    $targetDate = $ciDate;
                }
                if ($targetDate === '' || $targetDate === (string)$mr['work_date']) {
                    $repairReports[] = '복구 불가(id='.(int)$mr['id'].'): 출근/퇴근 날짜가 일치하지 않거나 이동 대상 날짜를 확정할 수 없습니다.';
                    continue;
                }
                $stDup = $pdo->prepare("SELECT id FROM cpms_attendance_records WHERE employee_id=:e AND work_date=:d AND id<>:id LIMIT 1");
                $stDup->execute(array(':e'=>(int)$mr['employee_id'], ':d'=>$targetDate, ':id'=>(int)$mr['id']));
                $dupId = (int)$stDup->fetchColumn();
                if ($dupId > 0) {
                    $repairReports[] = '복구 불가(id='.(int)$mr['id'].'): 해당 직원의 '.$targetDate.' 기록이 이미 존재합니다.';
                    continue;
                }
                $stUp = $pdo->prepare("UPDATE cpms_attendance_records SET work_date=:d, updated_at=:u WHERE id=:id");
                $stUp->execute(array(':d'=>$targetDate, ':u'=>date('Y-m-d H:i:s'), ':id'=>(int)$mr['id']));
                $moved++;
                $repairReports[] = '복구 완료(id='.(int)$mr['id'].'): work_date를 '.$targetDate.'로 이동했습니다.';
            }
            $success = true;
            $logs[] = '날짜 불일치 기록 점검/복구 완료: 이동 '.$moved.'건';
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

$indexStatusView = array('has_uq_emp_date' => false, 'bad_unique_indexes' => array());
if ($pdo && table_exists($pdo, 'cpms_attendance_records')) {
    try {
        $indexStatusView = attendance_record_index_status($pdo);
        if (!empty($indexStatusView['bad_unique_indexes'])) {
            $warnings[] = '잘못된 인덱스가 있습니다. employee_id 단독 UNIQUE가 있으면 날짜별 출퇴근 기록이 막힙니다.';
        }
    } catch (Exception $e) {
        $warnings[] = '인덱스 점검 중 오류: ' . $e->getMessage();
    }
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

    <?php if (!empty($warnings)): ?>
    <div style="background:#fff7ed;border:1px solid #ea580c;color:#9a3412;padding:10px 12px;border-radius:8px;margin-bottom:12px;">
      <strong>경고</strong>
      <ul style="margin:8px 0 0 18px; padding:0;">
        <?php foreach ($warnings as $w): ?>
          <li><?php echo h($w); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:10px 12px;margin-bottom:12px;">
    <div style="font-weight:bold;margin-bottom:6px;">cpms_attendance_records 인덱스 상태</div>
    <div style="font-size:13px;line-height:1.6;">
      employee_id + work_date UNIQUE(uq_emp_date): <?php echo $indexStatusView['has_uq_emp_date'] ? '있음' : '없음'; ?><br>
      employee_id 단독 UNIQUE: <?php echo empty($indexStatusView['bad_unique_indexes']) ? '없음' : h(implode(', ', $indexStatusView['bad_unique_indexes'])); ?>
    </div>
    <?php if (!empty($indexStatusView['bad_unique_indexes'])): ?>
      <form method="post" style="margin-top:10px;">
        <button type="submit" name="action" value="drop_bad_unique_emp" style="padding:8px 12px;border:1px solid #b91c1c;border-radius:8px;background:#dc2626;color:#fff;cursor:pointer;">employee_id 단독 UNIQUE 제거</button>
      </form>
    <?php endif; ?>
  </div>

  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
    <form method="post" style="margin:0;"><button type="submit" name="action" value="records" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">records 생성/확인</button></form>
    <form method="post" style="margin:0;"><button type="submit" name="action" value="requests" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">requests 생성/확인</button></form>
    <form method="post" style="margin:0;"><button type="submit" name="action" value="leave" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">leave 생성/확인</button></form>
    <form method="post" style="margin:0;"><button type="submit" name="action" value="settings" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">settings 생성/확인</button></form>
    <form method="post" style="margin:0;"><button type="submit" name="action" value="all" style="padding:8px 12px;border:1px solid #15803d;border-radius:8px;background:#16a34a;color:#fff;cursor:pointer;">all 생성/확인</button></form>
    <form method="post" style="margin:0;"><button type="submit" name="action" value="repair_mismatch_records" style="padding:8px 12px;border:1px solid #1d4ed8;border-radius:8px;background:#2563eb;color:#fff;cursor:pointer;">날짜 불일치 기록 점검/복구</button></form>    
  </div>

  <div style="margin-bottom:12px;"><a href="?r=관리&amp;tab=attendance" style="color:#1d4ed8;">관리 화면으로 돌아가기</a></div>

  <h3 style="font-size:16px; margin:16px 0 8px 0;">처리 로그</h3>
  <?php if (!empty($repairReports)): ?>
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#fff;margin-bottom:12px;">
      <div style="font-weight:bold;margin-bottom:6px;">날짜 불일치 복구 결과</div>
      <ul style="margin:0 0 0 18px;padding:0;">
        <?php foreach ($repairReports as $line): ?>
          <li style="margin:4px 0;"><?php echo h($line); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>  
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
