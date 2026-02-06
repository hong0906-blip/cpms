<?php
/**
 * 공수 입력 저장 / 삭제 (테스트용)
 * - labor_calc.php에서 호출
 * - MySQL 5.6 / PHP 5.6 기준
 */

// C:\www\cpms\public\index.php 에서 이 파일을 require 하므로 bootstrap을 다시 로드해도 안전합니다.
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Db;

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결이 안 되어 저장할 수 없습니다.');
    header('Location: ?r=관리&tab=labor_calc');
    exit;
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

// ------------------------------------------------------------
// 테이블 자동 생성 (웹에서 실행)
// - phpMyAdmin 없이도 버튼으로 CREATE TABLE 실행할 수 있게 추가
// - 주의: CREATE TABLE IF NOT EXISTS 이므로 기존 테이블은 덮어쓰지 않음
// ------------------------------------------------------------
if ($action === 'ensure_tables') {
    $csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($csrf)) {
        flash_set('error', 'CSRF 토큰이 올바르지 않습니다.');
        header('Location: ?r=관리&tab=labor_calc');
        exit;
    }

    try {
        $sqlAll = "/* =========================
   CPMS - 노무비 계산용 테이블 생성 (MySQL 5.6)
   - labor_entries
   - direct_team_rates
   - cpms_projects는 공무 DB 설정에서 생성
   ========================= */\n\n"

        // ✅ A안: DATETIME DEFAULT CURRENT_TIMESTAMP 제거 (MySQL 5.6 호환)
        . "CREATE TABLE IF NOT EXISTS `labor_entries` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `work_date` DATE NOT NULL,
  `worker_type` VARCHAR(20) NOT NULL DEFAULT 'direct',
  `employee_id` INT NULL,
  `worker_name` VARCHAR(80) NOT NULL,
  `company_name` VARCHAR(120) NULL,
  `daily_wage` INT NULL,
  `man_days` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `memo` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_labor_entries_pid_date` (`project_id`, `work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;\n\n"

        . "CREATE TABLE IF NOT EXISTS `direct_team_rates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `daily_wage` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_direct_team_rates_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;\n\n"

. "/* NOTE: 프로젝트 테이블(cpms_projects)은 공무 섹션 DB 설정에서 생성됩니다. */\n";

        // 멀티쿼리 실행 (MySQL/PDO 드라이버에 따라 분리 실행이 더 안전)
        // 여기서는 안정적으로 세 문장으로 쪼개 실행
        $parts = array();
        foreach (explode(";\n\n", $sqlAll) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || strpos($chunk, 'CREATE TABLE') === false) continue;
            $parts[] = $chunk . ";";
        }

        foreach ($parts as $q) {
            $pdo->exec($q);
        }

        flash_set('success', '노무비 계산용 테이블 2개(labor_entries, direct_team_rates)를 생성했습니다.');
    } catch (Exception $e) {
        flash_set('error', '테이블 생성 실패: ' . $e->getMessage());
    }

    header('Location: ?r=관리&tab=labor_calc');
    exit;
}

// ---------------------------------------------
// 아래는 기존 add/delete 로직
// ---------------------------------------------
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$month     = isset($_POST['month']) ? preg_replace('/[^0-9\-]/', '', $_POST['month']) : date('Y-m');

$redirect = 'Location: ?r=관리&tab=labor_calc&project_id=' . $projectId . '&month=' . urlencode($month);

// CSRF
$csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($csrf)) {
    flash_set('error', 'CSRF 토큰이 올바르지 않습니다.');
    header($redirect);
    exit;
}

$tableName = 'labor_entries';

// 테이블 존재 확인
$sql = "SELECT 1
          FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :t
         LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute(array(':t' => $tableName));
if (!$st->fetchColumn()) {
    flash_set('error', 'labor_entries 테이블이 없습니다. 먼저 상단 안내의 [웹에서 자동 생성] 버튼 또는 SQL로 테이블을 만드세요.');
    header($redirect);
    exit;
}

if ($action === 'add') {
    $workDate    = isset($_POST['work_date']) ? (string)$_POST['work_date'] : '';
    $workerType  = isset($_POST['worker_type']) ? (string)$_POST['worker_type'] : 'direct';
    $employeeId  = isset($_POST['employee_id']) && $_POST['employee_id'] !== '' ? (int)$_POST['employee_id'] : null;
    $workerName  = isset($_POST['worker_name']) ? trim((string)$_POST['worker_name']) : '';
    $companyName = isset($_POST['company_name']) ? trim((string)$_POST['company_name']) : null;
    $dailyWage   = isset($_POST['daily_wage']) && $_POST['daily_wage'] !== '' ? (int)$_POST['daily_wage'] : null;
    $manDays     = isset($_POST['man_days']) ? (float)$_POST['man_days'] : 1.0;

    // labor_calc.php 폼에서는 name="note"로 넘어오므로 memo로 매핑
    $memo        = isset($_POST['note']) ? trim((string)$_POST['note']) : null;

    if ($projectId <= 0) {
        flash_set('error', '프로젝트를 선택하세요.');
        header($redirect);
        exit;
    }
    if (!$workDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
        flash_set('error', '작업일자 형식이 올바르지 않습니다.');
        header($redirect);
        exit;
    }
    if ($workerName === '') {
        flash_set('error', '성명을 입력하세요.');
        header($redirect);
        exit;
    }

    try {
        $sql = "INSERT INTO labor_entries
                (project_id, work_date, worker_type, employee_id, worker_name, company_name, daily_wage, man_days, memo, created_at)
                VALUES
                (:pid, :wd, :wt, :eid, :wn, :cn, :dw, :md, :memo, NOW())";
        $st = $pdo->prepare($sql);
        $st->execute(array(
            ':pid'  => $projectId,
            ':wd'   => $workDate,
            ':wt'   => $workerType,
            ':eid'  => $employeeId,
            ':wn'   => $workerName,
            ':cn'   => ($companyName === '' ? null : $companyName),
            ':dw'   => $dailyWage,
            ':md'   => $manDays,
            ':memo' => ($memo === '' ? null : $memo),
        ));

        flash_set('success', '저장 완료');
    } catch (Exception $e) {
        flash_set('error', '저장 실패: ' . $e->getMessage());
    }

    header($redirect);
    exit;
}

if ($action === 'delete') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($id <= 0) {
        flash_set('error', '삭제할 ID가 올바르지 않습니다.');
        header($redirect);
        exit;
    }

    try {
        $st = $pdo->prepare("DELETE FROM labor_entries WHERE id = :id LIMIT 1");
        $st->execute(array(':id' => $id));
        flash_set('success', '삭제 완료');
    } catch (Exception $e) {
        flash_set('error', '삭제 실패: ' . $e->getMessage());
    }

    header($redirect);
    exit;
}

flash_set('error', '알 수 없는 요청입니다.');
header($redirect);
exit;
