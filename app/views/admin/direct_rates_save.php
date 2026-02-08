<?php
/**
 * C:\www\cpms\app\views\admin\direct_rates_save.php
 * - 직영팀(내부 인력) 일급 저장(POST)
 * - 직영팀 명부는 direct_team_members 테이블로 분리
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Db;
use App\Core\Auth;

if (!Auth::canManageEmployees()) {
    flash_set('error', '접근 권한이 없습니다. (임원/관리 전용)');
    header('Location: ?r=관리&tab=direct_rates');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?r=관리&tab=direct_rates');
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=관리&tab=direct_rates');
    exit;
}

$pdo = Db::pdo();
if ($pdo === null) {
    flash_set('error', 'DB 연결이 안 되어 저장할 수 없습니다.');
    header('Location: ?r=관리&tab=direct_rates');
    exit;
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

function cpms_table_exists_local($pdo, $table) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $sql = "SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl";
        $st = $pdo->prepare($sql);
        $st->bindValue(':db', $dbName);
        $st->bindValue(':tbl', $table);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) {
        return false;
    }
}

// 과거 UI(action=ensure_table) 호환을 위해 둘 다 처리
if ($action === 'ensure_table' || $action === 'create_direct_rates_table') {
    try {
        if (cpms_table_exists_local($pdo, 'direct_team_members')) {
            flash_set('success', '이미 직영팀 명부(direct_team_members) 테이블이 존재합니다.');
        } else {
            // MySQL 5.6 호환 (utf8mb4 가능하지만 환경에 따라 utf8만 있을 수 있어 utf8 사용)
            $pdo->exec("CREATE TABLE direct_team_members (
              id INT NOT NULL AUTO_INCREMENT,
              name VARCHAR(50) NOT NULL,
              note VARCHAR(100) NULL,
              resident_no VARCHAR(30) NULL,
              phone VARCHAR(30) NULL,
              address VARCHAR(255) NULL,
              deposit_rate INT NOT NULL DEFAULT 0,
              bank_account VARCHAR(50) NULL,
              bank_name VARCHAR(50) NULL,
              account_holder VARCHAR(50) NULL,
              daily_wage INT NOT NULL DEFAULT 0,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            flash_set('success', '직영팀 명부(direct_team_members) 테이블을 생성했습니다.');
        }
    } catch (\Exception $e) {
        flash_set('error', '테이블 생성 실패: ' . $e->getMessage());
    }
    header('Location: ?r=관리&tab=direct_rates');
    exit;
}

if ($action === 'save_rate') {
    // member_id(신규) / employee_id(과거) 둘 다 허용
    $memberId = 0;
    if (isset($_POST['member_id'])) $memberId = (int)$_POST['member_id'];
    if ($memberId <= 0 && isset($_POST['employee_id'])) $memberId = (int)$_POST['employee_id'];
    $dailyWage = isset($_POST['daily_wage']) ? (int)preg_replace('/[^0-9]/', '', (string)$_POST['daily_wage']) : 0;

    if ($memberId <= 0) {
        flash_set('error', '직원 정보가 올바르지 않습니다.');
        header('Location: ?r=관리&tab=direct_rates');
        exit;
    }
    if ($dailyWage <= 0) {
        flash_set('error', '일급은 0보다 커야 합니다.');
        header('Location: ?r=관리&tab=direct_rates');
        exit;
    }

    try {
        if (!cpms_table_exists_local($pdo, 'direct_team_members')) {
            flash_set('error', '직영팀 명부(direct_team_members) 테이블이 없습니다. 먼저 테이블을 생성하세요.');
            header('Location: ?r=관리&tab=direct_rates');
            exit;
        }

        // 직영팀 명부에 일급 저장
        $sql = "UPDATE direct_team_members
                SET daily_wage = :wage, updated_at = NOW()
                WHERE id = :id";
        $st = $pdo->prepare($sql);
        $st->bindValue(':wage', $dailyWage, \PDO::PARAM_INT);
        $st->bindValue(':id', $memberId, \PDO::PARAM_INT);
        $st->execute();

        flash_set('success', '직영팀 일급을 저장했습니다.');
    } catch (\Exception $e) {
        flash_set('error', '저장 실패: ' . $e->getMessage());
    }
    header('Location: ?r=관리&tab=direct_rates');
    exit;
}

flash_set('error', '알 수 없는 요청입니다.');
header('Location: ?r=관리&tab=direct_rates');
exit;
