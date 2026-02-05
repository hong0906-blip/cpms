<?php
/**
 * C:\www\cpms\public\db_setup_project.php
 * - 공무(프로젝트) 섹션용 테이블/기본설정 생성(웹 클릭)
 * - PHP 5.6 호환
 *
 * ✅ 이번 추가:
 * - 이슈 테이블(cpms_project_issues) 생성 버튼 추가
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

function h2($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function execSql($pdo, $sql) { $pdo->exec($sql); }

$msg = ''; $type = '';

/**
 * ✅ 컬럼 존재 여부 확인 (구버전 MySQL 대응)
 */
function column_exists($pdo, $table, $column) {
    $sql = "SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c";
    $st = $pdo->prepare($sql);
    $st->bindValue(':t', (string)$table);
    $st->bindValue(':c', (string)$column);
    $st->execute();
    return ((int)$st->fetchColumn() > 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        $type = 'error';
        $msg = '보안 토큰이 유효하지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        try {

            if ($action === 'create_tables') {

                // cpms_projects (시공사/계약금액 포함)
                execSql($pdo, "CREATE TABLE IF NOT EXISTS cpms_projects (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    client VARCHAR(255) DEFAULT '',
                    contractor VARCHAR(255) DEFAULT '',
                    location VARCHAR(255) DEFAULT '',
                    start_date DATE NULL,
                    end_date DATE NULL,
                    contract_amount BIGINT NULL,
                    status VARCHAR(50) DEFAULT '계약중',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                execSql($pdo, "CREATE TABLE IF NOT EXISTS cpms_project_members (
                    project_id INT NOT NULL,
                    employee_id INT NOT NULL,
                    role VARCHAR(10) NOT NULL,
                    PRIMARY KEY (project_id, employee_id, role),
                    KEY idx_project_role (project_id, role)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                execSql($pdo, "CREATE TABLE IF NOT EXISTS cpms_project_unit_prices (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    item_name VARCHAR(255) NOT NULL,
                    spec VARCHAR(255) DEFAULT '',
                    unit VARCHAR(50) DEFAULT '',
                    qty DECIMAL(18,4) NULL,
                    unit_price DECIMAL(18,2) NULL,
                    remark VARCHAR(255) DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_project (project_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                execSql($pdo, "CREATE TABLE IF NOT EXISTS cpms_unit_price_header_map (
                    system_field VARCHAR(50) NOT NULL PRIMARY KEY,
                    excel_headers VARCHAR(255) NOT NULL,
                    is_required TINYINT(1) NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $type = 'ok';
                $msg = '테이블 생성/확인 완료';

            } else if ($action === 'update_columns') {

                // 운영 DB: cpms_projects에 시공사/계약금액 없으면 추가
                $added = array();

                if (!column_exists($pdo, 'cpms_projects', 'contractor')) {
                    execSql($pdo, "ALTER TABLE cpms_projects ADD COLUMN contractor VARCHAR(255) DEFAULT '' AFTER client");
                    $added[] = 'contractor(시공사)';
                }

                if (!column_exists($pdo, 'cpms_projects', 'contract_amount')) {
                    execSql($pdo, "ALTER TABLE cpms_projects ADD COLUMN contract_amount BIGINT NULL AFTER end_date");
                    $added[] = 'contract_amount(계약금액)';
                }

                // status 기본값이 옛날 값이면 그대로 둬도 되지만, 신규 생성 시 기본값은 계약중으로 이미 설정됨.

                $type = 'ok';
                $msg = (count($added) === 0) ? '컬럼 업데이트: 이미 적용되어 있습니다.' : ('컬럼 업데이트 완료: ' . implode(', ', $added));

            } else if ($action === 'create_issues') {

                /**
                 * ✅ 이슈 테이블
                 * - 등록자/시간 자동 저장
                 * - 상태: 처리중/처리완료
                 * - 구버전 MySQL 대응: CURRENT_TIMESTAMP는 1개(created_at)만 사용
                 */
                execSql($pdo, "CREATE TABLE IF NOT EXISTS cpms_project_issues (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    reason VARCHAR(255) NOT NULL,
                    created_by_name VARCHAR(100) NOT NULL,
                    created_by_email VARCHAR(255) DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    status VARCHAR(20) NOT NULL DEFAULT '처리중',
                    KEY idx_project (project_id),
                    KEY idx_status (status),
                    KEY idx_created_by (created_by_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $type = 'ok';
                $msg = '이슈 테이블 생성/확인 완료';

            } else if ($action === 'seed_mapping') {

                $rows = array(
                    array('item_name',  '품명|자재명|자재', 1),
                    array('spec',       '규격|형식|사양', 0),
                    array('unit',       '단위|UOM', 0),
                    array('qty',        '수량|물량', 0),
                    array('unit_price', '단가|금액|단가(원)|단가(₩)', 1),
                    array('remark',     '비고|메모|설명', 0),
                );

                $st = $pdo->prepare("INSERT INTO cpms_unit_price_header_map(system_field, excel_headers, is_required)
                                     VALUES(:sf, :eh, :req)
                                     ON DUPLICATE KEY UPDATE excel_headers=VALUES(excel_headers), is_required=VALUES(is_required)");
                foreach ($rows as $r) {
                    $st->bindValue(':sf', $r[0]);
                    $st->bindValue(':eh', $r[1]);
                    $st->bindValue(':req', (int)$r[2], \PDO::PARAM_INT);
                    $st->execute();
                }

                $type = 'ok';
                $msg = '기본 헤더 매핑 저장 완료';

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
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>공무 DB 설정</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f6f7fb; margin:0; padding:24px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px; max-width:980px; }
        .row { display:flex; gap:12px; flex-wrap:wrap; }
        .btn { padding:12px 14px; border-radius:12px; border:0; cursor:pointer; font-weight:700; }
        .btn-primary { background:#2563eb; color:#fff; }
        .btn-secondary { background:#111827; color:#fff; }
        .btn-warn { background:#f59e0b; color:#111827; }
        .btn-emerald { background:#10b981; color:#fff; }
        .msg-ok { margin:12px 0; padding:10px 12px; border-radius:12px; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
        .msg-err { margin:12px 0; padding:10px 12px; border-radius:12px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        code { background:#f3f4f6; padding:2px 6px; border-radius:8px; }
        a { color:#2563eb; font-weight:700; text-decoration:none; }
        .muted { color:#6b7280; font-size:13px; margin-top:6px; }
    </style>
</head>
<body>
<div class="card">
    <h2>공무(프로젝트) DB 설정</h2>
    <p>아래 버튼으로 공무 섹션에 필요한 테이블/매핑을 생성/업데이트합니다.</p>

    <?php if ($msg !== ''): ?>
        <div class="<?php echo ($type === 'ok') ? 'msg-ok' : 'msg-err'; ?>">
            <?php echo h2($msg); ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?php echo h2(csrf_token()); ?>">
            <input type="hidden" name="action" value="create_tables">
            <button class="btn btn-primary" type="submit">1) 테이블 생성/확인</button>
        </form>

        <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?php echo h2(csrf_token()); ?>">
            <input type="hidden" name="action" value="update_columns">
            <button class="btn btn-warn" type="submit">1-1) 컬럼 업데이트(시공사/계약금액)</button>
        </form>

        <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?php echo h2(csrf_token()); ?>">
            <input type="hidden" name="action" value="create_issues">
            <button class="btn btn-emerald" type="submit">1-2) 이슈 테이블 생성/확인</button>
        </form>

        <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?php echo h2(csrf_token()); ?>">
            <input type="hidden" name="action" value="seed_mapping">
            <button class="btn btn-secondary" type="submit">2) 기본 헤더 매핑 저장</button>
        </form>
    </div>

    <div class="muted">
        * 이미 테이블 생성이 끝났다면, 이슈 기능을 위해 <b>1-2) 이슈 테이블 생성/확인</b>을 한 번 눌러주세요.
    </div>

    <hr style="border:none;border-top:1px solid #e5e7eb; margin:16px 0;">

    <p>
        공무 메뉴로 이동: <a href="<?php echo h2(base_url()); ?>/?r=공무">공무(프로젝트)</a><br>
        대시보드: <a href="<?php echo h2(base_url()); ?>/?r=대시보드">대시보드</a>
    </p>

    <p style="color:#6b7280; font-size:13px;">
        * 운영 배포 후에는 보안을 위해 이 파일(<code>public/db_setup_project.php</code>) 삭제를 권장합니다.
    </p>
</div>
</body>
</html>