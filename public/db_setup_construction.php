<?php
/**
 * C:\www\cpms\public\db_setup_construction.php
 * - 공사(Construction) 섹션용 테이블/컬럼 생성(웹 클릭)
 * - PHP 5.6 호환
 *
 * 목적(공사 뼈대):
 * 1) 담당 지정(안전/품질/현장)
 * 2) 단가표(공정) 기반 템플릿 생성
 * 3) 공정표(간트) 저장
 * 4) 안전사고 등록/조회
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: text/html; charset=utf-8');

if (!Auth::check()) {
    echo "<h2>로그인이 필요합니다.</h2>";
    exit;
}

$role = Auth::userRole();
$dept = Auth::userDepartment();

// 관리 페이지 성격이라 임원/관리/공사만 접근 허용
if (!($role === 'executive' || $dept === '관리' || $dept === '공사')) {
    http_response_code(403);
    echo "<h2>403 Forbidden</h2>";
    echo "<p>접근 권한이 없습니다.</p>";
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    echo "<h2>DB 연결 실패</h2>";
    exit;
}

$run = isset($_POST['run']) ? (string)$_POST['run'] : '';
$msg = '';
$err = '';

function table_exists($pdo, $tableName) {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->bindValue(':t', $tableName);
        $st->execute();
        return $st->fetchColumn() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

function column_exists($pdo, $tableName, $columnName) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE :c");
        $st->bindValue(':c', $columnName);
        $st->execute();
        return $st->fetchColumn() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $run === '1') {
    try {
        $pdo->beginTransaction();

        // 1) 공사 담당 지정
        if (!table_exists($pdo, 'cpms_construction_roles')) {
            $sql = "CREATE TABLE cpms_construction_roles (
                        project_id INT NOT NULL,
                        site_employee_id INT NULL,
                        safety_employee_id INT NULL,
                        quality_employee_id INT NULL,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (project_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
            $pdo->exec($sql);
        }

        // 2) 공정 템플릿
        if (!table_exists($pdo, 'cpms_process_templates')) {
            $sql = "CREATE TABLE cpms_process_templates (
                        id INT NOT NULL AUTO_INCREMENT,
                        project_id INT NOT NULL,
                        process_name VARCHAR(255) NOT NULL,
                        sort_order INT NOT NULL DEFAULT 0,
                        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_project_id (project_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
            $pdo->exec($sql);
        }

        // 3) 공정표(간트) 태스크
        if (!table_exists($pdo, 'cpms_schedule_tasks')) {
            $sql = "CREATE TABLE cpms_schedule_tasks (
                        id INT NOT NULL AUTO_INCREMENT,
                        project_id INT NOT NULL,
                        name VARCHAR(255) NOT NULL,
                        start_date DATE NULL,
                        end_date DATE NULL,
                        progress INT NOT NULL DEFAULT 0,
                        sort_order INT NOT NULL DEFAULT 0,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_project_id (project_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
            $pdo->exec($sql);
        }

        // 4) 안전사고
        if (!table_exists($pdo, 'cpms_safety_incidents')) {
            $sql = "CREATE TABLE cpms_safety_incidents (
                        id INT NOT NULL AUTO_INCREMENT,
                        project_id INT NOT NULL,
                        title VARCHAR(255) NOT NULL,
                        description TEXT NULL,
                        occurred_at DATETIME NULL,
                        created_by_name VARCHAR(100) NOT NULL,
                        created_by_email VARCHAR(255) NULL,
                        status VARCHAR(20) NOT NULL DEFAULT '접수',
                        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_project_id (project_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
            $pdo->exec($sql);
        }

        // (선택) 단가표에 공정 컬럼을 추가하고 싶을 때를 대비한 예비 컬럼 (지금 로직은 item_name을 공정으로 사용)
        // - 이미 운영 데이터가 있어서 컬럼 추가가 부담이면 안 눌러도 됨
        if (table_exists($pdo, 'cpms_project_unit_prices') && !column_exists($pdo, 'cpms_project_unit_prices', 'process_name')) {
            $sql = "ALTER TABLE cpms_project_unit_prices ADD COLUMN process_name VARCHAR(255) NULL AFTER item_name";
            $pdo->exec($sql);
        }

        $pdo->commit();
        $msg = "✅ 공사 뼈대 테이블/컬럼 생성(업데이트) 완료";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = "❌ 오류: " . $e->getMessage();
    }
}

?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <title>CPMS 공사 DB 설정</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{font-family:Arial, Helvetica, sans-serif;background:#f6f7fb;margin:0;padding:24px}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;max-width:860px}
        .btn{display:inline-block;padding:10px 14px;border-radius:12px;border:1px solid #111;background:#111;color:#fff;font-weight:700;cursor:pointer}
        .muted{color:#6b7280;font-size:13px}
        .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px;border-radius:12px;margin:12px 0}
        .bad{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px;border-radius:12px;margin:12px 0}
        code{background:#f3f4f6;padding:2px 6px;border-radius:8px}
        ul{margin:8px 0 0 18px}
    </style>
</head>
<body>
<div class="card">
    <h2>공사(Construction) DB 설정</h2>
    <p class="muted">웹에서 버튼 클릭으로 테이블을 생성/업데이트합니다. (PHP 5.6 호환)</p>

    <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="bad"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <h3>생성/업데이트 내용</h3>
    <ul class="muted">
        <li><code>cpms_construction_roles</code> : 공사 담당(현장/안전/품질) 지정</li>
        <li><code>cpms_process_templates</code> : 공정 템플릿(단가표 기반)</li>
        <li><code>cpms_schedule_tasks</code> : 공정표(간트) 태스크</li>
        <li><code>cpms_safety_incidents</code> : 안전사고</li>
        <li>(예비) <code>cpms_project_unit_prices.process_name</code> 컬럼 추가</li>
    </ul>

    <form method="post" style="margin-top:16px">
        <input type="hidden" name="run" value="1">
        <button class="btn" type="submit">공사 뼈대 테이블 생성/업데이트 실행</button>
    </form>

    <p class="muted" style="margin-top:16px">
        완료 후, 메뉴에서 <b>공사</b>로 들어가서 <b>템플릿 생성 → 공정표 초안 생성</b> 순서로 테스트하세요.
    </p>
</div>
</body>
</html>
