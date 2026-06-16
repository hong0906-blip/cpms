<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (!(\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole() === 'executive')) {
    http_response_code(403);
    exit('403');
}

$pdo = \App\Core\Db::pdo();
$results = array();

if (!function_exists('approval_setup_ko')) {
    function approval_setup_ko($encoded)
    {
        return urldecode($encoded);
    }
}

if (!function_exists('approval_setup_column_exists')) {
    function approval_setup_column_exists($pdo, $table, $column)
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
            $st->execute(array(':tbl' => $table, ':col' => $column));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_setup_index_exists')) {
    function approval_setup_index_exists($pdo, $table, $indexName)
    {
        try {
            $safeTable = str_replace('`', '', $table);
            $st = $pdo->query("SHOW INDEX FROM `" . $safeTable . "`");
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['Key_name']) && (string)$row['Key_name'] === (string)$indexName) {
                    return true;
                }
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_setup_add_column')) {
    function approval_setup_add_column($pdo, $table, $column, $alterSql, &$results)
    {
        try {
            if (approval_setup_column_exists($pdo, $table, $column)) {
                $results[] = array('type' => 'COLUMN', 'name' => $table . '.' . $column, 'ok' => 1, 'msg' => approval_setup_ko('%EC%9D%B4%EB%AF%B8%20%EC%A1%B4%EC%9E%AC'));
                return;
            }
            $pdo->exec($alterSql);
            $results[] = array('type' => 'COLUMN', 'name' => $table . '.' . $column, 'ok' => 1, 'msg' => approval_setup_ko('%EC%B6%94%EA%B0%80%20%EC%99%84%EB%A3%8C'));
        } catch (Exception $e) {
            $results[] = array('type' => 'COLUMN', 'name' => $table . '.' . $column, 'ok' => 0, 'msg' => $e->getMessage());
        }
    }
}

if (!function_exists('approval_setup_add_index')) {
    function approval_setup_add_index($pdo, $table, $indexName, $sql, &$results)
    {
        try {
            if (approval_setup_index_exists($pdo, $table, $indexName)) {
                $results[] = array('type' => 'INDEX', 'name' => $table . '.' . $indexName, 'ok' => 1, 'msg' => approval_setup_ko('%EC%9D%B4%EB%AF%B8%20%EC%A1%B4%EC%9E%AC'));
                return;
            }
            $pdo->exec($sql);
            $results[] = array('type' => 'INDEX', 'name' => $table . '.' . $indexName, 'ok' => 1, 'msg' => approval_setup_ko('%EC%B6%94%EA%B0%80%20%EC%99%84%EB%A3%8C'));
        } catch (Exception $e) {
            $results[] = array('type' => 'INDEX', 'name' => $table . '.' . $indexName, 'ok' => 0, 'msg' => $e->getMessage());
        }
    }
}

$tables = array(
    'cpms_approval_documents' => "CREATE TABLE IF NOT EXISTS cpms_approval_documents (id INT AUTO_INCREMENT PRIMARY KEY, doc_type VARCHAR(20) NULL, title VARCHAR(255) NULL, content MEDIUMTEXT NULL, doc_status VARCHAR(20) NULL, current_step_order INT DEFAULT 1, created_by_id INT NULL, created_by_name VARCHAR(100) NULL, created_by_email VARCHAR(190) NULL, project_id INT NULL, completed_pdf_storage_type VARCHAR(30) NULL, completed_pdf_drive_file_id VARCHAR(128) NULL, completed_pdf_drive_folder_id VARCHAR(128) NULL, completed_pdf_drive_web_view_link TEXT NULL, completed_pdf_drive_web_content_link TEXT NULL, completed_pdf_name VARCHAR(255) NULL, completed_pdf_mime_type VARCHAR(190) NULL, completed_pdf_size BIGINT NULL, completed_pdf_uploaded_at DATETIME NULL, completed_pdf_upload_status VARCHAR(30) NULL, completed_pdf_upload_error TEXT NULL, delegate_level VARCHAR(20) NULL, reject_reason TEXT NULL, rejected_step VARCHAR(50) NULL, created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_approval_lines' => "CREATE TABLE IF NOT EXISTS cpms_approval_lines (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NULL, line_order INT NULL, role_type VARCHAR(50) NULL, approver_id INT NULL, approver_name VARCHAR(100) NULL, approver_email VARCHAR(190) NULL, line_status VARCHAR(20) NULL, acted_at DATETIME NULL, sign_path VARCHAR(255) NULL, reject_reason TEXT NULL, is_delegated TINYINT(1) NOT NULL DEFAULT 0, delegated_by_role VARCHAR(50) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_approval_references' => "CREATE TABLE IF NOT EXISTS cpms_approval_references (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, employee_id INT NOT NULL, employee_name VARCHAR(100) NULL, employee_email VARCHAR(190) NULL, employee_department VARCHAR(100) NULL, created_at DATETIME NULL, INDEX idx_document_id (document_id), INDEX idx_employee_id (employee_id), INDEX idx_employee_email (employee_email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_approval_files' => "CREATE TABLE IF NOT EXISTS cpms_approval_files (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NULL, original_name VARCHAR(255) NULL, saved_name VARCHAR(255) NULL, file_path VARCHAR(255) NULL, file_label VARCHAR(100) NULL, file_type VARCHAR(50) NULL, storage_type VARCHAR(30) NULL, drive_name VARCHAR(255) NULL, drive_file_id VARCHAR(128) NULL, drive_folder_id VARCHAR(128) NULL, drive_web_view_link TEXT NULL, drive_web_content_link TEXT NULL, mime_type VARCHAR(190) NULL, file_size BIGINT NULL, uploaded_by VARCHAR(190) NULL, uploaded_at DATETIME NULL, upload_status VARCHAR(30) NULL, drive_upload_error TEXT NULL, created_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_approval_logs' => "CREATE TABLE IF NOT EXISTS cpms_approval_logs (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NULL, line_id INT NULL, actor_id INT NULL, actor_name VARCHAR(100) NULL, actor_email VARCHAR(190) NULL, action_type VARCHAR(30) NULL, action_note TEXT NULL, created_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_approval_notifications' => "CREATE TABLE IF NOT EXISTS cpms_approval_notifications (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NULL, event_type VARCHAR(40) NULL, receiver_employee_id INT NULL, receiver_name VARCHAR(100) NULL, receiver_email VARCHAR(190) NULL, message_text TEXT NULL, dm_space_name VARCHAR(255) NULL, send_status VARCHAR(20) NULL, sent_at DATETIME NULL, error_message TEXT NULL, created_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_google_chat_notifications' => "CREATE TABLE IF NOT EXISTS cpms_google_chat_notifications (id INT AUTO_INCREMENT PRIMARY KEY, source_type VARCHAR(50) NOT NULL, source_id INT NULL, event_type VARCHAR(50) NULL, receiver_employee_id INT NULL, receiver_name VARCHAR(100) NULL, receiver_email VARCHAR(190) NULL, dm_space_name VARCHAR(255) NULL, message_text TEXT NULL, send_status VARCHAR(20) NULL, error_message TEXT NULL, sent_at DATETIME NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_approval_settings' => "CREATE TABLE IF NOT EXISTS cpms_approval_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NULL, setting_value TEXT NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_holiday_cache' => "CREATE TABLE IF NOT EXISTS cpms_holiday_cache (id INT AUTO_INCREMENT PRIMARY KEY, holiday_date DATE NOT NULL, holiday_name VARCHAR(190) NULL, source VARCHAR(40) NOT NULL DEFAULT 'GOOGLE_CALENDAR', source_calendar_id VARCHAR(190) NULL, source_event_id VARCHAR(190) NULL, year_no INT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, synced_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uniq_holiday_date_source (holiday_date, source)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_approval_leave_deductions' => "CREATE TABLE IF NOT EXISTS cpms_approval_leave_deductions (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, employee_id INT NOT NULL, leave_type VARCHAR(50) NULL, leave_bucket VARCHAR(20) NULL, target_column VARCHAR(50) NULL, deduct_amount DECIMAL(6,2) NOT NULL DEFAULT 0, balance_before DECIMAL(6,2) NULL, balance_after DECIMAL(6,2) NULL, deducted_at DATETIME NULL, created_at DATETIME NULL, note TEXT NULL, UNIQUE KEY uniq_document_id (document_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_leave_accrual_logs' => "CREATE TABLE IF NOT EXISTS cpms_leave_accrual_logs (id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, leave_type VARCHAR(20) NOT NULL, accrual_date DATE NOT NULL, accrual_year INT NOT NULL, accrual_month INT NULL, amount DECIMAL(6,2) NOT NULL DEFAULT 0, reason VARCHAR(255) NULL, created_at DATETIME NULL, UNIQUE KEY uniq_employee_type_date (employee_id, leave_type, accrual_date), INDEX idx_employee_year (employee_id, accrual_year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cpms_leave_adjustments' => "CREATE TABLE IF NOT EXISTS cpms_leave_adjustments (id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, target_year INT NOT NULL, adjust_type VARCHAR(20) NOT NULL, amount DECIMAL(6,2) NOT NULL DEFAULT 0, reason VARCHAR(255) NULL, created_by INT NULL, created_at DATETIME NULL, INDEX idx_employee_year (employee_id, target_year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = array('type' => 'TABLE', 'name' => $name, 'ok' => 1, 'msg' => approval_setup_ko('%ED%99%95%EC%9D%B8%2F%EC%83%9D%EC%84%B1%20%EC%99%84%EB%A3%8C'));
    } catch (Exception $e) {
        $results[] = array('type' => 'TABLE', 'name' => $name, 'ok' => 0, 'msg' => $e->getMessage());
    }
}

$columns = array(
    'cpms_approval_documents' => array(
        'created_by_email' => "ALTER TABLE cpms_approval_documents ADD COLUMN created_by_email VARCHAR(190) NULL",
        'project_id' => "ALTER TABLE cpms_approval_documents ADD COLUMN project_id INT NULL",
        'completed_pdf_storage_type' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_storage_type VARCHAR(30) NULL",
        'completed_pdf_drive_file_id' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_drive_file_id VARCHAR(128) NULL",
        'completed_pdf_drive_folder_id' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_drive_folder_id VARCHAR(128) NULL",
        'completed_pdf_drive_web_view_link' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_drive_web_view_link TEXT NULL",
        'completed_pdf_drive_web_content_link' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_drive_web_content_link TEXT NULL",
        'completed_pdf_name' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_name VARCHAR(255) NULL",
        'completed_pdf_mime_type' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_mime_type VARCHAR(190) NULL",
        'completed_pdf_size' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_size BIGINT NULL",
        'completed_pdf_uploaded_at' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_uploaded_at DATETIME NULL",
        'completed_pdf_upload_status' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_upload_status VARCHAR(30) NULL",
        'completed_pdf_upload_error' => "ALTER TABLE cpms_approval_documents ADD COLUMN completed_pdf_upload_error TEXT NULL",
        'delegate_level' => "ALTER TABLE cpms_approval_documents ADD COLUMN delegate_level VARCHAR(20) NULL",
        'reject_reason' => "ALTER TABLE cpms_approval_documents ADD COLUMN reject_reason TEXT NULL",
        'rejected_step' => "ALTER TABLE cpms_approval_documents ADD COLUMN rejected_step VARCHAR(50) NULL",
        'updated_at' => "ALTER TABLE cpms_approval_documents ADD COLUMN updated_at DATETIME NULL"
    ),
    'cpms_approval_lines' => array(
        'approver_email' => "ALTER TABLE cpms_approval_lines ADD COLUMN approver_email VARCHAR(190) NULL",
        'acted_at' => "ALTER TABLE cpms_approval_lines ADD COLUMN acted_at DATETIME NULL",
        'sign_path' => "ALTER TABLE cpms_approval_lines ADD COLUMN sign_path VARCHAR(255) NULL",
        'reject_reason' => "ALTER TABLE cpms_approval_lines ADD COLUMN reject_reason TEXT NULL",
        'is_delegated' => "ALTER TABLE cpms_approval_lines ADD COLUMN is_delegated TINYINT(1) NOT NULL DEFAULT 0",
        'delegated_by_role' => "ALTER TABLE cpms_approval_lines ADD COLUMN delegated_by_role VARCHAR(50) NULL"
    ),
    'cpms_approval_files' => array(
        'file_label' => "ALTER TABLE cpms_approval_files ADD COLUMN file_label VARCHAR(100) NULL",
        'file_type' => "ALTER TABLE cpms_approval_files ADD COLUMN file_type VARCHAR(50) NULL",
        'storage_type' => "ALTER TABLE cpms_approval_files ADD COLUMN storage_type VARCHAR(30) NULL",
        'drive_name' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_name VARCHAR(255) NULL",
        'drive_file_id' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_file_id VARCHAR(128) NULL",
        'drive_folder_id' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_folder_id VARCHAR(128) NULL",
        'drive_web_view_link' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_web_view_link TEXT NULL",
        'drive_web_content_link' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_web_content_link TEXT NULL",
        'mime_type' => "ALTER TABLE cpms_approval_files ADD COLUMN mime_type VARCHAR(190) NULL",
        'file_size' => "ALTER TABLE cpms_approval_files ADD COLUMN file_size BIGINT NULL",
        'uploaded_by' => "ALTER TABLE cpms_approval_files ADD COLUMN uploaded_by VARCHAR(190) NULL",
        'uploaded_at' => "ALTER TABLE cpms_approval_files ADD COLUMN uploaded_at DATETIME NULL",
        'upload_status' => "ALTER TABLE cpms_approval_files ADD COLUMN upload_status VARCHAR(30) NULL",
        'drive_upload_error' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_upload_error TEXT NULL"
    ),
    'cpms_approval_leave_deductions' => array(
        'leave_bucket' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN leave_bucket VARCHAR(20) NULL",
        'target_column' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN target_column VARCHAR(50) NULL",
        'deduct_amount' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN deduct_amount DECIMAL(6,2) NOT NULL DEFAULT 0",
        'balance_before' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN balance_before DECIMAL(6,2) NULL",
        'balance_after' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN balance_after DECIMAL(6,2) NULL",
        'deducted_at' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN deducted_at DATETIME NULL",
        'created_at' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN created_at DATETIME NULL",
        'note' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN note TEXT NULL"
    ),
    'employees' => array(
        'hire_date' => "ALTER TABLE employees ADD COLUMN hire_date DATE NULL",
        'resign_date' => "ALTER TABLE employees ADD COLUMN resign_date DATE NULL",
        'leave_monthly_balance' => "ALTER TABLE employees ADD COLUMN leave_monthly_balance DECIMAL(6,2) NULL",
        'leave_annual_balance' => "ALTER TABLE employees ADD COLUMN leave_annual_balance DECIMAL(6,2) NULL",
        'leave_half_balance' => "ALTER TABLE employees ADD COLUMN leave_half_balance DECIMAL(6,2) NULL",
        'monthly_regular_wage' => "ALTER TABLE employees ADD COLUMN monthly_regular_wage DECIMAL(15,2) NULL"
    ),
    'cpms_leave_adjustments' => array(
        'target_year' => "ALTER TABLE cpms_leave_adjustments ADD COLUMN target_year INT NULL",
        'adjust_type' => "ALTER TABLE cpms_leave_adjustments ADD COLUMN adjust_type VARCHAR(20) NULL",
        'amount' => "ALTER TABLE cpms_leave_adjustments ADD COLUMN amount DECIMAL(6,2) NOT NULL DEFAULT 0",
        'reason' => "ALTER TABLE cpms_leave_adjustments ADD COLUMN reason VARCHAR(255) NULL",
        'created_by' => "ALTER TABLE cpms_leave_adjustments ADD COLUMN created_by INT NULL",
        'created_at' => "ALTER TABLE cpms_leave_adjustments ADD COLUMN created_at DATETIME NULL"
    )
);
foreach ($columns as $table => $defs) {
    foreach ($defs as $column => $alterSql) {
        approval_setup_add_column($pdo, $table, $column, $alterSql, $results);
    }
}

approval_setup_add_index($pdo, 'cpms_approval_settings', 'uniq_setting_key', "ALTER TABLE cpms_approval_settings ADD UNIQUE KEY uniq_setting_key (setting_key)", $results);
approval_setup_add_index($pdo, 'cpms_approval_references', 'idx_document_id', "ALTER TABLE cpms_approval_references ADD INDEX idx_document_id (document_id)", $results);
approval_setup_add_index($pdo, 'cpms_approval_references', 'idx_employee_id', "ALTER TABLE cpms_approval_references ADD INDEX idx_employee_id (employee_id)", $results);
approval_setup_add_index($pdo, 'cpms_approval_references', 'idx_employee_email', "ALTER TABLE cpms_approval_references ADD INDEX idx_employee_email (employee_email)", $results);
approval_setup_add_index($pdo, 'cpms_approval_leave_deductions', 'uniq_document_id', "ALTER TABLE cpms_approval_leave_deductions ADD UNIQUE KEY uniq_document_id (document_id)", $results);
approval_setup_add_index($pdo, 'cpms_approval_leave_deductions', 'idx_employee_id', "ALTER TABLE cpms_approval_leave_deductions ADD INDEX idx_employee_id (employee_id)", $results);
approval_setup_add_index($pdo, 'cpms_approval_leave_deductions', 'idx_leave_bucket', "ALTER TABLE cpms_approval_leave_deductions ADD INDEX idx_leave_bucket (leave_bucket)", $results);
approval_setup_add_index($pdo, 'cpms_leave_accrual_logs', 'uniq_employee_type_date', "ALTER TABLE cpms_leave_accrual_logs ADD UNIQUE KEY uniq_employee_type_date (employee_id, leave_type, accrual_date)", $results);
approval_setup_add_index($pdo, 'cpms_leave_accrual_logs', 'idx_employee_year', "ALTER TABLE cpms_leave_accrual_logs ADD INDEX idx_employee_year (employee_id, accrual_year)", $results);
approval_setup_add_index($pdo, 'cpms_leave_adjustments', 'idx_employee_year', "ALTER TABLE cpms_leave_adjustments ADD INDEX idx_employee_year (employee_id, target_year)", $results);

try {
    $defaults = array(
        'google_chat_dm_enabled' => '1',
        'google_chat_enabled' => '1',
        'cpms_base_url' => '',
        'google_chat_service_account_json_path' => '/www/cpms/storage/secrets/google-chat-service-account.json',
        'google_chat_project_id' => 'cpms-approval-chat-bot',
        'google_chat_bot_email' => 'cpms-chat-bot@cpms-approval-chat-bot.iam.gserviceaccount.com',
        'google_chat_oauth_scope' => 'https://www.googleapis.com/auth/chat.bot',
        'google_chat_impersonation_user' => '',
        'google_chat_public_base_url' => 'https://cmbuild.kr/cpms/public/',
        'google_chat_dm_auto_create_enabled' => '1',
        'google_holiday_calendar_enabled' => '0',
        'google_holiday_calendar_id' => 'ko.south_korea#holiday@group.v.calendar.google.com',
        'google_holiday_calendar_api_key' => '',
        'google_holiday_sync_years' => '2'
    );
    foreach ($defaults as $k => $v) {
        $pdo->prepare("INSERT IGNORE INTO cpms_approval_settings (setting_key,setting_value,updated_at) VALUES (:k,:v,NOW())")
            ->execute(array(':k' => $k, ':v' => $v));
    }
    $results[] = array('type' => 'SETTING', 'name' => 'cpms_approval_settings.defaults', 'ok' => 1, 'msg' => approval_setup_ko('%ED%99%95%EC%9D%B8%2F%EC%83%9D%EC%84%B1%20%EC%99%84%EB%A3%8C'));
} catch (Exception $e) {
    $results[] = array('type' => 'SETTING', 'name' => 'cpms_approval_settings.defaults', 'ok' => 0, 'msg' => $e->getMessage());
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo h(approval_setup_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20DB%20%EC%84%A4%EC%B9%98%2F%ED%99%95%EC%9D%B8')); ?></title>
</head>
<body>
<h2><?php echo h(approval_setup_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20DB%20%EC%84%A4%EC%B9%98%2F%ED%99%95%EC%9D%B8')); ?></h2>
<p><?php echo h(approval_setup_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%ED%85%8C%EC%9D%B4%EB%B8%94%2C%20%EC%B0%B8%EC%A1%B0%EC%9E%90%2C%20%EC%A0%84%EA%B2%B0%20%EC%BB%AC%EB%9F%BC%EC%9D%84%20%ED%99%95%EC%9D%B8%ED%95%A9%EB%8B%88%EB%8B%A4.')); ?></p>
<table border="1" cellpadding="6" cellspacing="0">
    <tr>
        <th><?php echo h(approval_setup_ko('%EA%B5%AC%EB%B6%84')); ?></th>
        <th><?php echo h(approval_setup_ko('%EB%8C%80%EC%83%81')); ?></th>
        <th><?php echo h(approval_setup_ko('%EA%B2%B0%EA%B3%BC')); ?></th>
        <th><?php echo h(approval_setup_ko('%EB%A9%94%EC%8B%9C%EC%A7%80')); ?></th>
    </tr>
    <?php for ($i = 0; $i < count($results); $i++) { ?>
        <tr>
            <td><?php echo h($results[$i]['type']); ?></td>
            <td><?php echo h($results[$i]['name']); ?></td>
            <td><?php echo $results[$i]['ok'] ? h(approval_setup_ko('%EC%84%B1%EA%B3%B5')) : h(approval_setup_ko('%EC%8B%A4%ED%8C%A8')); ?></td>
            <td><?php echo h($results[$i]['msg']); ?></td>
        </tr>
    <?php } ?>
</table>
<p><a href="?r=approval_home"><?php echo h(approval_setup_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%EB%A1%9C%20%EC%9D%B4%EB%8F%99')); ?></a></p>
</body>
</html>
