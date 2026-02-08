<?php
/**
 * C:\www\cpms\app\views\admin\direct_team_save.php
 * - 직영팀 명부 저장(추가/수정/삭제)
 * - PHP 5.6 호환
 */

use App\Core\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo 'Invalid request';
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=관리&tab=direct_team');
    exit;
}

$pdo = Db::pdo();
if ($pdo === null) {
    flash_set('error', 'DB 연결 오류');
    header('Location: ?r=관리&tab=direct_team');
    exit;
}

function cpms_table_exists_local($pdo, $table) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $sql = "SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :db
                  AND TABLE_NAME = :tbl";
        $st = $pdo->prepare($sql);
        $st->bindValue(':db', $dbName);
        $st->bindValue(':tbl', $table);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) {
        return false;
    }
}

// 테이블 없으면 (직영팀 테이블 생성 버튼을 안 눌렀더라도) 자동 생성
if (!cpms_table_exists_local($pdo, 'direct_team_members')) {
    try {
        $pdo->exec("CREATE TABLE direct_team_members (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            note VARCHAR(120) NULL,
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
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    } catch (\Exception $e) {
        flash_set('error', '직영팀 명부 테이블 생성 실패: ' . $e->getMessage());
        header('Location: ?r=관리&tab=direct_team');
        exit;
    }
}

function cpms_column_exists_local($pdo, $table, $column) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $sql = "SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :db
                  AND TABLE_NAME = :tbl
                  AND COLUMN_NAME = :col";
        $st = $pdo->prepare($sql);
        $st->bindValue(':db', $dbName);
        $st->bindValue(':tbl', $table);
        $st->bindValue(':col', $column);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) {
        return false;
    }
}

function cpms_ensure_direct_team_columns($pdo) {
    $columns = array(
        "resident_no VARCHAR(30) NULL",
        "phone VARCHAR(30) NULL",
        "address VARCHAR(255) NULL",
        "deposit_rate INT NOT NULL DEFAULT 0",
        "bank_account VARCHAR(50) NULL",
        "bank_name VARCHAR(50) NULL",
        "account_holder VARCHAR(50) NULL"
    );
    foreach ($columns as $definition) {
        $parts = explode(' ', $definition, 2);
        $column = $parts[0];
        if (!cpms_column_exists_local($pdo, 'direct_team_members', $column)) {
            try {
                $pdo->exec("ALTER TABLE direct_team_members ADD COLUMN " . $definition);
            } catch (\Exception $e) {
                // ignore schema update failures to avoid blocking saves
            }
        }
    }
}

cpms_ensure_direct_team_columns($pdo);

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

if ($action === 'save') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
    $residentNo = isset($_POST['resident_no']) ? trim((string)$_POST['resident_no']) : '';
    $phone = isset($_POST['phone']) ? trim((string)$_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim((string)$_POST['address']) : '';
    $depositRate = isset($_POST['deposit_rate']) ? (int)preg_replace('/[^0-9]/', '', (string)$_POST['deposit_rate']) : 0;
    $bankAccount = isset($_POST['bank_account']) ? trim((string)$_POST['bank_account']) : '';
    $bankName = isset($_POST['bank_name']) ? trim((string)$_POST['bank_name']) : '';
    $accountHolder = isset($_POST['account_holder']) ? trim((string)$_POST['account_holder']) : '';
    $note = isset($_POST['note']) ? trim((string)$_POST['note']) : '';
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

    if ($name === '') {
        flash_set('error', '이름은 필수입니다.');
        header('Location: ?r=관리&tab=direct_team');
        exit;
    }

    try {
        $now = date('Y-m-d H:i:s');
        if ($id > 0) {
            $st = $pdo->prepare("UPDATE direct_team_members
                                 SET name = :name,
                                    resident_no = :resident_no,
                                    phone = :phone,
                                    address = :address,
                                    deposit_rate = :deposit_rate,
                                    bank_account = :bank_account,
                                    bank_name = :bank_name,
                                    account_holder = :account_holder,
                                    note = :note,
                                    is_active = :active,
                                    updated_at = :now
                                WHERE id = :id");
            $st->bindValue(':id', $id, PDO::PARAM_INT);
            $st->bindValue(':name', $name);
            $st->bindValue(':resident_no', $residentNo);
            $st->bindValue(':phone', $phone);
            $st->bindValue(':address', $address);
            $st->bindValue(':deposit_rate', $depositRate, PDO::PARAM_INT);
            $st->bindValue(':bank_account', $bankAccount);
            $st->bindValue(':bank_name', $bankName);
            $st->bindValue(':account_holder', $accountHolder);
            $st->bindValue(':note', $note);
            $st->bindValue(':active', $isActive, PDO::PARAM_INT);
            $st->bindValue(':now', $now);
            $st->execute();
            flash_set('success', '직영팀 인력을 수정했습니다.');
        } else {
            $st = $pdo->prepare("INSERT INTO direct_team_members
                                (name, resident_no, phone, address, deposit_rate, bank_account, bank_name, account_holder, note, daily_wage, is_active, created_at, updated_at)
                                VALUES (:name, :resident_no, :phone, :address, :deposit_rate, :bank_account, :bank_name, :account_holder, :note, 0, :active, :now, :now)");
            $st->bindValue(':name', $name);
            $st->bindValue(':resident_no', $residentNo);
            $st->bindValue(':phone', $phone);
            $st->bindValue(':address', $address);
            $st->bindValue(':deposit_rate', $depositRate, PDO::PARAM_INT);
            $st->bindValue(':bank_account', $bankAccount);
            $st->bindValue(':bank_name', $bankName);
            $st->bindValue(':account_holder', $accountHolder);
            $st->bindValue(':note', $note);
            $st->bindValue(':active', $isActive, PDO::PARAM_INT);
            $st->bindValue(':now', $now);
            $st->execute();
            flash_set('success', '직영팀 인력을 추가했습니다.');
        }
    } catch (\Exception $e) {
        flash_set('error', '저장 실패: ' . $e->getMessage());
    }

    header('Location: ?r=관리&tab=direct_team');
    exit;
}

if ($action === 'delete') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        flash_set('error', '삭제 대상이 올바르지 않습니다.');
        header('Location: ?r=관리&tab=direct_team');
        exit;
    }

    try {
        $st = $pdo->prepare("DELETE FROM direct_team_members WHERE id = :id");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
        flash_set('success', '삭제했습니다.');
    } catch (\Exception $e) {
        flash_set('error', '삭제 실패: ' . $e->getMessage());
    }

    header('Location: ?r=관리&tab=direct_team');
    exit;
}

flash_set('error', '알 수 없는 요청입니다.');
header('Location: ?r=관리&tab=direct_team');
exit;
