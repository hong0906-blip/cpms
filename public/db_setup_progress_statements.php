<?php
/**
 * C:\www\cpms\public\db_setup_progress_statements.php
 * 기성내역서 제출·검토 테이블의 웹 설치·점검 페이지(기존 데이터 삭제/변경 없음).
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: text/html; charset=utf-8');
if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::isMaster()) { http_response_code(403); echo '403 Forbidden'; exit; }
$pdo = Db::pdo();
if (!$pdo) { echo '<h2>DB 연결 실패</h2>'; exit; }

if (!function_exists('cpms_ps_setup_h')) {
function cpms_ps_setup_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('cpms_ps_setup_columns')) {
function cpms_ps_setup_columns($pdo, $table) {
    $result = array();
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) $result[(string)$row['Field']] = true;
    } catch (Exception $e) {}
    return $result;
}}
if (!function_exists('cpms_ps_setup_index_exists')) {
function cpms_ps_setup_index_exists($pdo, $table, $indexName) {
    try {
        $st = $pdo->prepare("SHOW INDEX FROM `" . str_replace('`', '``', $table) . "` WHERE Key_name = :index_name");
        $st->execute(array(':index_name' => (string)$indexName));
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return false; }
}}

$definitions = array(
    'cpms_progress_statements' => array(
        'create' => "CREATE TABLE IF NOT EXISTS cpms_progress_statements (
            id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, target_year SMALLINT NOT NULL,
            target_month TINYINT NOT NULL, progress_round INT NOT NULL, title VARCHAR(200) NOT NULL,
            submit_message TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', latest_file_id INT NULL,
            submitted_by INT NULL, submitted_by_name VARCHAR(100) NOT NULL DEFAULT '', submitted_by_email VARCHAR(190) NOT NULL DEFAULT '',
            submitted_at DATETIME NOT NULL, reviewed_by INT NULL, reviewed_by_name VARCHAR(100) NULL,
            reviewed_by_email VARCHAR(190) NULL, reviewed_at DATETIME NULL, reject_reason TEXT NULL, approved_at DATETIME NULL,
            drive_upload_status VARCHAR(30) NOT NULL DEFAULT 'not_started', drive_file_id VARCHAR(128) NULL,
            drive_file_name VARCHAR(255) NULL, drive_web_view_link TEXT NULL, drive_uploaded_at DATETIME NULL,
            drive_error_message TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_project_period_round (project_id,target_year,target_month,progress_round),
            KEY idx_status (status), KEY idx_submitted_at (submitted_at), KEY idx_drive_status (drive_upload_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'columns' => array(
            'id'=>"INT AUTO_INCREMENT PRIMARY KEY",'project_id'=>"INT NOT NULL",'target_year'=>"SMALLINT NOT NULL",'target_month'=>"TINYINT NOT NULL",
            'progress_round'=>"INT NOT NULL",'title'=>"VARCHAR(200) NOT NULL DEFAULT ''",'submit_message'=>"TEXT NULL",'status'=>"VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'latest_file_id'=>"INT NULL",'submitted_by'=>"INT NULL",'submitted_by_name'=>"VARCHAR(100) NOT NULL DEFAULT ''",'submitted_by_email'=>"VARCHAR(190) NOT NULL DEFAULT ''",
            'submitted_at'=>"DATETIME NULL",'reviewed_by'=>"INT NULL",'reviewed_by_name'=>"VARCHAR(100) NULL",'reviewed_by_email'=>"VARCHAR(190) NULL",
            'reviewed_at'=>"DATETIME NULL",'reject_reason'=>"TEXT NULL",'approved_at'=>"DATETIME NULL",'drive_upload_status'=>"VARCHAR(30) NOT NULL DEFAULT 'not_started'",
            'drive_file_id'=>"VARCHAR(128) NULL",'drive_file_name'=>"VARCHAR(255) NULL",'drive_web_view_link'=>"TEXT NULL",'drive_uploaded_at'=>"DATETIME NULL",
            'drive_error_message'=>"TEXT NULL",'created_at'=>"DATETIME NULL",'updated_at'=>"DATETIME NULL"
        )
    ),
    'cpms_progress_statement_files' => array(
        'create' => "CREATE TABLE IF NOT EXISTS cpms_progress_statement_files (
            id INT AUTO_INCREMENT PRIMARY KEY, statement_id INT NOT NULL, version_no INT NOT NULL,
            original_file_name VARCHAR(255) NOT NULL, server_file_name VARCHAR(255) NOT NULL,
            server_file_path VARCHAR(500) NOT NULL, file_size BIGINT NOT NULL DEFAULT 0, mime_type VARCHAR(190) NOT NULL DEFAULT '',
            uploaded_by INT NULL, uploaded_by_name VARCHAR(100) NOT NULL DEFAULT '', uploaded_by_email VARCHAR(190) NOT NULL DEFAULT '',
            submission_type VARCHAR(30) NOT NULL, uploaded_at DATETIME NOT NULL,
            UNIQUE KEY uniq_statement_version (statement_id,version_no), KEY idx_statement (statement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'columns' => array(
            'id'=>"INT AUTO_INCREMENT PRIMARY KEY",'statement_id'=>"INT NOT NULL",'version_no'=>"INT NOT NULL",'original_file_name'=>"VARCHAR(255) NOT NULL DEFAULT ''",
            'server_file_name'=>"VARCHAR(255) NOT NULL DEFAULT ''",'server_file_path'=>"VARCHAR(500) NOT NULL DEFAULT ''",'file_size'=>"BIGINT NOT NULL DEFAULT 0",
            'mime_type'=>"VARCHAR(190) NOT NULL DEFAULT ''",'uploaded_by'=>"INT NULL",'uploaded_by_name'=>"VARCHAR(100) NOT NULL DEFAULT ''",
            'uploaded_by_email'=>"VARCHAR(190) NOT NULL DEFAULT ''",'submission_type'=>"VARCHAR(30) NOT NULL DEFAULT 'initial'",'uploaded_at'=>"DATETIME NULL"
        )
    ),
    'cpms_progress_statement_comments' => array(
        'create' => "CREATE TABLE IF NOT EXISTS cpms_progress_statement_comments (
            id INT AUTO_INCREMENT PRIMARY KEY, statement_id INT NOT NULL, author_employee_id INT NULL,
            author_name VARCHAR(100) NOT NULL DEFAULT '', author_email VARCHAR(190) NOT NULL DEFAULT '',
            comment_text TEXT NOT NULL, created_at DATETIME NOT NULL, KEY idx_statement_created (statement_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'columns' => array('id'=>"INT AUTO_INCREMENT PRIMARY KEY",'statement_id'=>"INT NOT NULL",'author_employee_id'=>"INT NULL",
            'author_name'=>"VARCHAR(100) NOT NULL DEFAULT ''",'author_email'=>"VARCHAR(190) NOT NULL DEFAULT ''",'comment_text'=>"TEXT NULL",'created_at'=>"DATETIME NULL")
    ),
    'cpms_progress_statement_histories' => array(
        'create' => "CREATE TABLE IF NOT EXISTS cpms_progress_statement_histories (
            id INT AUTO_INCREMENT PRIMARY KEY, statement_id INT NOT NULL, event_type VARCHAR(40) NOT NULL,
            old_status VARCHAR(20) NULL, new_status VARCHAR(20) NULL, actor_employee_id INT NULL,
            actor_name VARCHAR(100) NOT NULL DEFAULT '', actor_email VARCHAR(190) NOT NULL DEFAULT '',
            description TEXT NULL, created_at DATETIME NOT NULL, KEY idx_statement_created (statement_id,created_at),
            KEY idx_event_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'columns' => array('id'=>"INT AUTO_INCREMENT PRIMARY KEY",'statement_id'=>"INT NOT NULL",'event_type'=>"VARCHAR(40) NOT NULL DEFAULT ''",
            'old_status'=>"VARCHAR(20) NULL",'new_status'=>"VARCHAR(20) NULL",'actor_employee_id'=>"INT NULL",'actor_name'=>"VARCHAR(100) NOT NULL DEFAULT ''",
            'actor_email'=>"VARCHAR(190) NOT NULL DEFAULT ''",'description'=>"TEXT NULL",'created_at'=>"DATETIME NULL")
    )
);
$indexDefinitions = array(
    'cpms_progress_statements' => array(
        'uniq_project_period_round'=>"ALTER TABLE cpms_progress_statements ADD UNIQUE KEY uniq_project_period_round (project_id,target_year,target_month,progress_round)",
        'idx_status'=>"ALTER TABLE cpms_progress_statements ADD KEY idx_status (status)",
        'idx_submitted_at'=>"ALTER TABLE cpms_progress_statements ADD KEY idx_submitted_at (submitted_at)",
        'idx_drive_status'=>"ALTER TABLE cpms_progress_statements ADD KEY idx_drive_status (drive_upload_status)"
    ),
    'cpms_progress_statement_files' => array(
        'uniq_statement_version'=>"ALTER TABLE cpms_progress_statement_files ADD UNIQUE KEY uniq_statement_version (statement_id,version_no)",
        'idx_statement'=>"ALTER TABLE cpms_progress_statement_files ADD KEY idx_statement (statement_id)"
    ),
    'cpms_progress_statement_comments' => array(
        'idx_statement_created'=>"ALTER TABLE cpms_progress_statement_comments ADD KEY idx_statement_created (statement_id,created_at)"
    ),
    'cpms_progress_statement_histories' => array(
        'idx_statement_created'=>"ALTER TABLE cpms_progress_statement_histories ADD KEY idx_statement_created (statement_id,created_at)",
        'idx_event_type'=>"ALTER TABLE cpms_progress_statement_histories ADD KEY idx_event_type (event_type)"
    )
);
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        $message = '보안 토큰이 유효하지 않습니다.'; $messageType = 'error';
    } else {
        try {
            foreach ($definitions as $table => $definition) {
                $pdo->exec($definition['create']);
                $columns = cpms_ps_setup_columns($pdo, $table);
                foreach ($definition['columns'] as $column => $sqlType) {
                    if (!isset($columns[$column])) $pdo->exec("ALTER TABLE `" . $table . "` ADD COLUMN `" . $column . "` " . $sqlType);
                }
                if (isset($indexDefinitions[$table])) {
                    foreach ($indexDefinitions[$table] as $indexName => $indexSql) {
                        if (!cpms_ps_setup_index_exists($pdo, $table, $indexName)) $pdo->exec($indexSql);
                    }
                }
            }
            $message = '설치·보정이 완료되었습니다. 기존 데이터는 변경하거나 삭제하지 않았습니다.'; $messageType = 'ok';
        } catch (Exception $e) {
            $message = '설치·보정 실패: ' . $e->getMessage(); $messageType = 'error';
        }
    }
}
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>기성내역서 DB 설치·점검</title>
<style>body{font-family:Arial,sans-serif;background:#f6f7fb;margin:0;padding:24px;color:#111827}.card{max-width:1050px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:22px}.ok{color:#047857}.bad{color:#b91c1c}.msg{padding:12px;border-radius:12px;margin:12px 0}.msg.ok{background:#ecfdf5}.msg.error{background:#fef2f2}.btn{border:0;border-radius:12px;background:#111827;color:#fff;font-weight:700;padding:12px 16px;cursor:pointer}table{width:100%;border-collapse:collapse;margin:16px 0}th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;vertical-align:top}.cols{font-size:12px;line-height:1.7}</style>
</head><body><div class="card"><h1>기성내역서 DB 설치·점검</h1>
<p>반복 실행해도 안전하며 DROP, 기존 컬럼 변경, 데이터 삭제를 수행하지 않습니다.</p>
<?php if ($message !== ''): ?><div class="msg <?php echo cpms_ps_setup_h($messageType); ?>"><?php echo cpms_ps_setup_h($message); ?></div><?php endif; ?>
<table><thead><tr><th>테이블</th><th>존재</th><th>필수 컬럼</th></tr></thead><tbody>
<?php foreach ($definitions as $table => $definition): $columns = cpms_ps_setup_columns($pdo, $table); ?>
<tr><td><code><?php echo cpms_ps_setup_h($table); ?></code></td><td class="<?php echo count($columns) ? 'ok' : 'bad'; ?>"><?php echo count($columns) ? '있음' : '없음'; ?></td>
<td class="cols"><?php foreach ($definition['columns'] as $column => $unused): ?><span class="<?php echo isset($columns[$column]) ? 'ok' : 'bad'; ?>"><?php echo cpms_ps_setup_h($column); ?>: <?php echo isset($columns[$column]) ? '정상' : '누락'; ?></span><br><?php endforeach; ?>
<?php if(isset($indexDefinitions[$table])):?><?php foreach($indexDefinitions[$table] as $indexName=>$unusedSql):?><span class="<?php echo cpms_ps_setup_index_exists($pdo,$table,$indexName)?'ok':'bad'; ?>">INDEX <?php echo cpms_ps_setup_h($indexName); ?>: <?php echo cpms_ps_setup_index_exists($pdo,$table,$indexName)?'정상':'누락'; ?></span><br><?php endforeach;?><?php endif;?></td></tr>
<?php endforeach; ?></tbody></table>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo cpms_ps_setup_h(csrf_token()); ?>"><button class="btn" type="submit">설치·보정 실행</button></form>
<p><a href="<?php echo cpms_ps_setup_h(base_url()); ?>/?r=공무&tab=progress_statement_review">기성내역서 검토로 이동</a></p>
</div></body></html>
