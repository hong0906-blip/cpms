<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/unit_price_parser.php';
require_once __DIR__ . '/contract_change_helper.php';
require_once __DIR__ . '/../../services/PublicAffairsDriveService.php';

if (!function_exists('cpms_contract_upload_redirect')) {
function cpms_contract_upload_redirect($projectId, $type, $message) {
    flash_set($type, $message);
    if ((int)$projectId > 0) {
        header('Location: ?r=project/detail&id=' . (int)$projectId);
    } else {
        header('Location: ?r=공무');
    }
    exit;
}
}

if (!function_exists('cpms_contract_upload_column_exists')) {
function cpms_contract_upload_column_exists($pdo, $table, $column) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', $column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}
}

if (!function_exists('cpms_contract_upload_add_column_if_missing')) {
function cpms_contract_upload_add_column_if_missing($pdo, $table, $column, $sql) {
    if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') return false;
    if (cpms_contract_upload_column_exists($pdo, $table, $column)) return true;
    try {
        $pdo->exec($sql);
    } catch (Exception $e) {
        error_log('[contract_upload] schema add column failed: ' . $table . '.' . $column . ' / ' . $e->getMessage());
        return false;
    }
    return cpms_contract_upload_column_exists($pdo, $table, $column);
}
}

if (!function_exists('cpms_contract_upload_ensure_contract_versions_schema')) {
function cpms_contract_upload_ensure_contract_versions_schema($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_contract_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            version_type VARCHAR(30) NOT NULL DEFAULT '',
            version_no INT NOT NULL DEFAULT 0,
            title VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            is_current TINYINT(1) NOT NULL DEFAULT 0,
            original_name VARCHAR(255) DEFAULT '',
            stored_name VARCHAR(255) DEFAULT '',
            stored_path VARCHAR(500) DEFAULT '',
            uploaded_by INT NULL,
            uploaded_at DATETIME NULL,
            applied_at DATETIME NULL,
            change_summary TEXT NULL,
            KEY idx_project (project_id),
            KEY idx_project_current (project_id, is_current),
            KEY idx_project_type_no (project_id, version_type, version_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns = array(
            'project_id' => "ALTER TABLE cpms_contract_versions ADD COLUMN project_id INT NOT NULL DEFAULT 0",
            'version_type' => "ALTER TABLE cpms_contract_versions ADD COLUMN version_type VARCHAR(30) NOT NULL DEFAULT ''",
            'version_no' => "ALTER TABLE cpms_contract_versions ADD COLUMN version_no INT NOT NULL DEFAULT 0",
            'title' => "ALTER TABLE cpms_contract_versions ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT ''",
            'status' => "ALTER TABLE cpms_contract_versions ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'draft'",
            'is_current' => "ALTER TABLE cpms_contract_versions ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 0",
            'original_name' => "ALTER TABLE cpms_contract_versions ADD COLUMN original_name VARCHAR(255) DEFAULT ''",
            'stored_name' => "ALTER TABLE cpms_contract_versions ADD COLUMN stored_name VARCHAR(255) DEFAULT ''",
            'stored_path' => "ALTER TABLE cpms_contract_versions ADD COLUMN stored_path VARCHAR(500) DEFAULT ''",
            'uploaded_by' => "ALTER TABLE cpms_contract_versions ADD COLUMN uploaded_by INT NULL",
            'uploaded_at' => "ALTER TABLE cpms_contract_versions ADD COLUMN uploaded_at DATETIME NULL",
            'applied_at' => "ALTER TABLE cpms_contract_versions ADD COLUMN applied_at DATETIME NULL",
            'change_summary' => "ALTER TABLE cpms_contract_versions ADD COLUMN change_summary TEXT NULL"
        );
        foreach ($columns as $column => $sql) {
            cpms_contract_upload_add_column_if_missing($pdo, 'cpms_contract_versions', $column, $sql);
        }
        try { $pdo->exec("ALTER TABLE cpms_contract_versions ADD INDEX idx_project (project_id)"); } catch (Exception $eIdx1) {}
        try { $pdo->exec("ALTER TABLE cpms_contract_versions ADD INDEX idx_project_current (project_id, is_current)"); } catch (Exception $eIdx2) {}
        try { $pdo->exec("ALTER TABLE cpms_contract_versions ADD INDEX idx_project_type_no (project_id, version_type, version_no)"); } catch (Exception $eIdx3) {}
        return cpms_contract_change_table_exists($pdo, 'cpms_contract_versions');
    } catch (Exception $e) {
        error_log('[contract_upload] contract version schema failed: ' . $e->getMessage());
        return false;
    }
}
}

if (!function_exists('cpms_contract_upload_ensure_estimate_versions_schema')) {
function cpms_contract_upload_ensure_estimate_versions_schema($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_estimate_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            version_type VARCHAR(20) NOT NULL DEFAULT '',
            version_no INT NOT NULL DEFAULT 1,
            title VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT NULL,
            original_file_name VARCHAR(255) DEFAULT '',
            stored_file_path VARCHAR(500) DEFAULT '',
            uploaded_by INT NULL,
            uploaded_by_name VARCHAR(100) DEFAULT '',
            uploaded_at DATETIME NULL,
            applied_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
            item_count INT NOT NULL DEFAULT 0,
            added_count INT NOT NULL DEFAULT 0,
            changed_count INT NOT NULL DEFAULT 0,
            removed_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            KEY idx_project (project_id),
            KEY idx_project_type_no (project_id, version_type, version_no),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns = array(
            'project_id' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN project_id INT NOT NULL DEFAULT 0",
            'version_type' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN version_type VARCHAR(20) NOT NULL DEFAULT ''",
            'version_no' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN version_no INT NOT NULL DEFAULT 1",
            'title' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT ''",
            'description' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN description TEXT NULL",
            'original_file_name' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN original_file_name VARCHAR(255) DEFAULT ''",
            'stored_file_path' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN stored_file_path VARCHAR(500) DEFAULT ''",
            'uploaded_by' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN uploaded_by INT NULL",
            'uploaded_by_name' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN uploaded_by_name VARCHAR(100) DEFAULT ''",
            'uploaded_at' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN uploaded_at DATETIME NULL",
            'applied_at' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN applied_at DATETIME NULL",
            'status' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'DRAFT'",
            'item_count' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN item_count INT NOT NULL DEFAULT 0",
            'added_count' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN added_count INT NOT NULL DEFAULT 0",
            'changed_count' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN changed_count INT NOT NULL DEFAULT 0",
            'removed_count' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN removed_count INT NOT NULL DEFAULT 0",
            'created_at' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN created_at DATETIME NULL",
            'updated_at' => "ALTER TABLE cpms_project_estimate_versions ADD COLUMN updated_at DATETIME NULL"
        );
        foreach ($columns as $column => $sql) {
            cpms_contract_upload_add_column_if_missing($pdo, 'cpms_project_estimate_versions', $column, $sql);
        }
        try { $pdo->exec("ALTER TABLE cpms_project_estimate_versions ADD INDEX idx_project (project_id)"); } catch (Exception $eIdx1) {}
        try { $pdo->exec("ALTER TABLE cpms_project_estimate_versions ADD INDEX idx_project_type_no (project_id, version_type, version_no)"); } catch (Exception $eIdx2) {}
        try { $pdo->exec("ALTER TABLE cpms_project_estimate_versions ADD INDEX idx_status (status)"); } catch (Exception $eIdx3) {}
        return cpms_contract_change_table_exists($pdo, 'cpms_project_estimate_versions');
    } catch (Exception $e) {
        error_log('[contract_upload] estimate version schema failed: ' . $e->getMessage());
        return false;
    }
}
}

if (!function_exists('cpms_contract_upload_ensure_unit_price_version_schema')) {
function cpms_contract_upload_ensure_unit_price_version_schema($pdo) {
    if (!$pdo || !cpms_contract_change_table_exists($pdo, 'cpms_project_unit_prices')) return false;
    $columns = array(
        'estimate_version_id' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN estimate_version_id INT NULL",
        'contract_version_id' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN contract_version_id INT NULL",
        'version_type' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN version_type VARCHAR(30) NOT NULL DEFAULT 'current'",
        'version_no' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN version_no INT NULL",
        'additional_work_id' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN additional_work_id INT NULL",
        'trade_group' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN trade_group VARCHAR(255) DEFAULT ''",
        'sub_trade' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN sub_trade VARCHAR(255) DEFAULT ''",
        'location_name' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN location_name VARCHAR(255) DEFAULT ''",
        'work_group' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN work_group VARCHAR(255) DEFAULT ''",
        'sub_work_group' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN sub_work_group VARCHAR(255) DEFAULT ''",
        'original_item_name' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN original_item_name VARCHAR(255) DEFAULT ''",
        'expense_unit_price' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN expense_unit_price DECIMAL(18,4) NULL",
        'amount' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN amount DECIMAL(18,4) NULL",
        'source_row' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_row INT NULL",
        'source_row_no' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_row_no INT NULL",
        'source_sheet_name' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_sheet_name VARCHAR(100) DEFAULT ''",
        'source_type' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_type VARCHAR(20) DEFAULT 'ORIGINAL'",
        'source_version_no' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN source_version_no INT NULL",
        'item_fingerprint' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN item_fingerprint CHAR(40) DEFAULT ''",
        'import_order' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN import_order INT NULL",
        'is_active' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        'is_current' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1",
        'updated_at' => "ALTER TABLE cpms_project_unit_prices ADD COLUMN updated_at DATETIME NULL"
    );
    foreach ($columns as $column => $sql) {
        cpms_contract_upload_add_column_if_missing($pdo, 'cpms_project_unit_prices', $column, $sql);
    }
    try { $pdo->exec("ALTER TABLE cpms_project_unit_prices ADD INDEX idx_estimate_version_id (estimate_version_id)"); } catch (Exception $eIdx1) {}
    try { $pdo->exec("ALTER TABLE cpms_project_unit_prices ADD INDEX idx_project_source (project_id, source_type, source_version_no)"); } catch (Exception $eIdx2) {}
    try { $pdo->exec("ALTER TABLE cpms_project_unit_prices ADD INDEX idx_item_fingerprint (item_fingerprint)"); } catch (Exception $eIdx3) {}
    return true;
}
}

if (!function_exists('cpms_contract_upload_ensure_upload_schema')) {
function cpms_contract_upload_ensure_upload_schema($pdo) {
    if (!cpms_contract_upload_ensure_contract_versions_schema($pdo)) {
        throw new Exception('cpms_contract_versions 테이블을 준비하지 못했습니다.');
    }
    if (!cpms_contract_upload_ensure_estimate_versions_schema($pdo)) {
        throw new Exception('cpms_project_estimate_versions 테이블을 준비하지 못했습니다.');
    }
    if (!cpms_contract_upload_ensure_unit_price_version_schema($pdo)) {
        throw new Exception('cpms_project_unit_prices 테이블을 준비하지 못했습니다.');
    }
}
}

if (!function_exists('cpms_contract_upload_current_user_id')) {
function cpms_contract_upload_current_user_id() {
    $user = Auth::user();
    if (is_array($user) && isset($user['id'])) return (int)$user['id'];
    return 0;
}
}

if (!function_exists('cpms_contract_upload_current_user_name')) {
function cpms_contract_upload_current_user_name() {
    $name = Auth::userName();
    if ($name !== null && trim((string)$name) !== '') return (string)$name;
    $email = Auth::userEmail();
    return $email !== null ? (string)$email : '';
}
}

if (!function_exists('cpms_contract_upload_source_type')) {
function cpms_contract_upload_source_type($versionType) {
    if ($versionType === 'change') return 'CHANGE';
    if ($versionType === 'extra' || $versionType === 'additional') return 'EXTRA';
    return 'ORIGINAL';
}
}

if (!function_exists('cpms_contract_upload_estimate_next_version_no')) {
function cpms_contract_upload_estimate_next_version_no($pdo, $projectId, $sourceType) {
    if (!$pdo || !cpms_contract_change_table_exists($pdo, 'cpms_project_estimate_versions')) {
        return ($sourceType === 'ORIGINAL') ? 1 : 1;
    }
    try {
        $st = $pdo->prepare("SELECT COALESCE(MAX(version_no), 0) FROM cpms_project_estimate_versions WHERE project_id = :pid AND version_type = :type");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':type', (string)$sourceType);
        $st->execute();
        $maxNo = (int)$st->fetchColumn();
        if ($sourceType === 'ORIGINAL') return ($maxNo > 0) ? $maxNo + 1 : 1;
        return $maxNo + 1;
    } catch (Exception $e) {
        return 1;
    }
}
}

if (!function_exists('cpms_contract_upload_create_estimate_version')) {
function cpms_contract_upload_create_estimate_version($pdo, $projectId, $sourceType, $versionNo, $title, $description, $originalName, $storedName, $storedPath, $summary, $itemCount) {
    if (!$pdo || !cpms_contract_change_table_exists($pdo, 'cpms_project_estimate_versions')) return 0;
    if (!is_array($summary)) $summary = array();
    try {
        $now = date('Y-m-d H:i:s');
        $st = $pdo->prepare("INSERT INTO cpms_project_estimate_versions
            (project_id, version_type, version_no, title, description, original_file_name, stored_file_path, uploaded_by, uploaded_by_name, uploaded_at, applied_at, status, item_count, added_count, changed_count, removed_count, created_at, updated_at)
            VALUES
            (:project_id, :version_type, :version_no, :title, :description, :original_file_name, :stored_file_path, :uploaded_by, :uploaded_by_name, :uploaded_at, :applied_at, 'APPLIED', :item_count, :added_count, :changed_count, :removed_count, :created_at, :updated_at)");
        $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':version_type', (string)$sourceType);
        $st->bindValue(':version_no', (int)$versionNo, PDO::PARAM_INT);
        $st->bindValue(':title', (string)$title);
        $st->bindValue(':description', (string)$description);
        $st->bindValue(':original_file_name', (string)$originalName);
        $st->bindValue(':stored_file_path', (string)$storedPath);
        $userId = cpms_contract_upload_current_user_id();
        if ($userId > 0) $st->bindValue(':uploaded_by', $userId, PDO::PARAM_INT);
        else $st->bindValue(':uploaded_by', null, PDO::PARAM_NULL);
        $st->bindValue(':uploaded_by_name', cpms_contract_upload_current_user_name());
        $st->bindValue(':uploaded_at', $now);
        $st->bindValue(':applied_at', $now);
        $st->bindValue(':item_count', (int)$itemCount, PDO::PARAM_INT);
        $st->bindValue(':added_count', isset($summary['inserted']) ? (int)$summary['inserted'] : (isset($summary['added']) ? (int)$summary['added'] : 0), PDO::PARAM_INT);
        $st->bindValue(':changed_count', isset($summary['changed']) ? (int)$summary['changed'] : (isset($summary['updated']) ? (int)$summary['updated'] : 0), PDO::PARAM_INT);
        $st->bindValue(':removed_count', isset($summary['deactivated']) ? (int)$summary['deactivated'] : (isset($summary['removed']) ? (int)$summary['removed'] : 0), PDO::PARAM_INT);
        $st->bindValue(':created_at', $now);
        $st->bindValue(':updated_at', $now);
        $st->execute();
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('[contract_upload] estimate version insert failed: ' . $e->getMessage());
        return 0;
    }
}
}

if (!function_exists('cpms_contract_upload_row_key')) {
function cpms_contract_upload_row_key($row) {
    return cpms_contract_change_row_key($row);
}
}

if (!function_exists('cpms_contract_upload_file_type')) {
function cpms_contract_upload_file_type($uploadMode) {
    if ($uploadMode === 'unit_price_update') return 'unit_price_update';
    if ($uploadMode === 'unit_price_original') return 'unit_price_original';
    if ($uploadMode === 'unit_price_extra') return 'unit_price_extra';
    return 'contract_only';
}
}

if (!function_exists('cpms_contract_upload_store_history')) {
function cpms_contract_upload_store_history($pdo, $projectId, $uploadMode, $originalName, $storedName, $storedPath, $summary, $driveRecord = null) {
    $fileType = cpms_contract_upload_file_type($uploadMode);
    if (!is_array($driveRecord)) {
        $driveRecord = array(
            'storage_type' => 'local',
            'document_type' => $fileType,
            'section' => 'public_affairs',
            'upload_status' => 'local',
            'drive_upload_error' => '',
            'local_backup_path' => (string)$storedPath
        );
    }
    if (function_exists('cpms_public_affairs_drive_insert_history_record')) {
        return cpms_public_affairs_drive_insert_history_record($pdo, $projectId, $fileType, $originalName, $storedName, $storedPath, $summary, $driveRecord, cpms_contract_upload_current_user_id());
    }
    return array('ok' => false, 'id' => 0, 'message' => 'Public affairs Drive service is not available.');
}
}

if (!function_exists('cpms_contract_upload_next_version_no')) {
function cpms_contract_upload_next_version_no($pdo, $projectId, $versionType) {
    if (!$pdo || !cpms_contract_change_table_exists($pdo, 'cpms_contract_versions')) return 0;
    if ($versionType === 'original') return 1;
    try {
        $st = $pdo->prepare("SELECT COALESCE(MAX(version_no), 0) FROM cpms_contract_versions WHERE project_id = :pid AND version_type = :type");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':type', (string)$versionType);
        $st->execute();
        return ((int)$st->fetchColumn()) + 1;
    } catch (Exception $e) {
        return 1;
    }
}
}

if (!function_exists('cpms_contract_upload_version_title')) {
function cpms_contract_upload_version_title($versionType, $versionNo, $additionalTitle) {
    if ($versionType === 'original') return '당초 내역서';
    if ($versionType === 'extra' || $versionType === 'additional') {
        $title = trim((string)$additionalTitle);
        return '추가공사 - ' . ($title !== '' ? $title : ('추가공사 ' . (int)$versionNo));
    }
    return '변경계약 ' . (int)$versionNo . '차';
}
}

if (!function_exists('cpms_contract_upload_create_version')) {
function cpms_contract_upload_create_version($pdo, $projectId, $versionType, $versionNo, $title, $originalName, $storedName, $storedPath, $summary) {
    if (!$pdo || !cpms_contract_change_table_exists($pdo, 'cpms_contract_versions')) return 0;
    try {
        if ($versionType === 'original' || $versionType === 'change') {
            $stClear = $pdo->prepare("UPDATE cpms_contract_versions SET is_current = 0 WHERE project_id = :pid");
            $stClear->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            $stClear->execute();
        }

        $now = date('Y-m-d H:i:s');
        $st = $pdo->prepare("INSERT INTO cpms_contract_versions
            (project_id, version_type, version_no, title, status, is_current, original_name, stored_name, stored_path, uploaded_by, uploaded_at, applied_at, change_summary)
            VALUES
            (:project_id, :version_type, :version_no, :title, 'applied', :is_current, :original_name, :stored_name, :stored_path, :uploaded_by, :uploaded_at, :applied_at, :change_summary)");
        $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':version_type', (string)$versionType);
        $st->bindValue(':version_no', (int)$versionNo, PDO::PARAM_INT);
        $st->bindValue(':title', (string)$title);
        $st->bindValue(':is_current', ($versionType === 'original' || $versionType === 'change') ? 1 : 0, PDO::PARAM_INT);
        $st->bindValue(':original_name', (string)$originalName);
        $st->bindValue(':stored_name', (string)$storedName);
        $st->bindValue(':stored_path', (string)$storedPath);
        $userId = cpms_contract_upload_current_user_id();
        if ($userId > 0) $st->bindValue(':uploaded_by', $userId, PDO::PARAM_INT);
        else $st->bindValue(':uploaded_by', null, PDO::PARAM_NULL);
        $st->bindValue(':uploaded_at', $now);
        $st->bindValue(':applied_at', $now);
        $st->bindValue(':change_summary', json_encode($summary, JSON_UNESCAPED_UNICODE));
        $st->execute();
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        return 0;
    }
}
}

if (!function_exists('cpms_contract_upload_build_data')) {
function cpms_contract_upload_build_data($row, $columns, $extra) {
    if (!is_array($extra)) $extra = array();
    $source = array(
        'estimate_version_id' => isset($extra['estimate_version_id']) ? (int)$extra['estimate_version_id'] : null,
        'contract_version_id' => isset($extra['contract_version_id']) ? (int)$extra['contract_version_id'] : null,
        'version_type' => isset($extra['version_type']) ? (string)$extra['version_type'] : 'current',
        'version_no' => isset($extra['version_no']) ? (int)$extra['version_no'] : null,
        'additional_work_id' => isset($extra['additional_work_id']) ? (int)$extra['additional_work_id'] : null,
        'trade_group' => isset($row['trade_group']) ? trim((string)$row['trade_group']) : '',
        'sub_trade' => isset($row['sub_trade']) ? trim((string)$row['sub_trade']) : '',
        'location_name' => isset($row['location_name']) ? trim((string)$row['location_name']) : '',
        'work_group' => isset($row['work_group']) ? trim((string)$row['work_group']) : (isset($row['trade_group']) ? trim((string)$row['trade_group']) : ''),
        'sub_work_group' => isset($row['sub_work_group']) ? trim((string)$row['sub_work_group']) : (isset($row['sub_trade']) ? trim((string)$row['sub_trade']) : ''),
        'item_name' => isset($row['item_name']) ? trim((string)$row['item_name']) : '',
        'original_item_name' => isset($row['original_item_name']) ? trim((string)$row['original_item_name']) : (isset($row['item_name']) ? trim((string)$row['item_name']) : ''),
        'spec' => isset($row['spec']) ? trim((string)$row['spec']) : '',
        'unit' => isset($row['unit']) ? trim((string)$row['unit']) : '',
        'qty' => isset($row['qty']) ? $row['qty'] : null,
        'unit_price' => isset($row['unit_price']) ? $row['unit_price'] : null,
        'labor_unit_price' => isset($row['labor_unit_price']) ? $row['labor_unit_price'] : null,
        'material_unit_price' => isset($row['material_unit_price']) ? $row['material_unit_price'] : null,
        'expense_unit_price' => isset($row['expense_unit_price']) ? $row['expense_unit_price'] : null,
        'amount' => isset($row['amount']) ? $row['amount'] : null,
        'source_row' => isset($row['source_row']) ? (int)$row['source_row'] : null,
        'source_row_no' => isset($row['source_row_no']) ? (int)$row['source_row_no'] : (isset($row['source_row']) ? (int)$row['source_row'] : null),
        'source_sheet_name' => isset($row['source_sheet_name']) ? trim((string)$row['source_sheet_name']) : '',
        'source_type' => isset($extra['source_type']) ? (string)$extra['source_type'] : cpms_contract_upload_source_type(isset($extra['version_type']) ? (string)$extra['version_type'] : 'original'),
        'source_version_no' => isset($extra['source_version_no']) ? (int)$extra['source_version_no'] : (isset($extra['version_no']) ? (int)$extra['version_no'] : null),
        'item_fingerprint' => isset($row['item_fingerprint']) ? trim((string)$row['item_fingerprint']) : cpms_contract_change_item_fingerprint($row),
        'import_order' => isset($row['import_order']) ? (int)$row['import_order'] : null,
        'is_safety' => isset($row['is_safety']) ? (int)$row['is_safety'] : 0,
        'remark' => isset($row['remark']) ? trim((string)$row['remark']) : ''
    );
    $partsTotal = 0.0;
    foreach (array('material_unit_price', 'labor_unit_price', 'expense_unit_price') as $partColumn) {
        if (isset($source[$partColumn]) && is_numeric((string)$source[$partColumn])) {
            $partsTotal += (float)$source[$partColumn];
        }
    }
    if ((!isset($source['unit_price']) || $source['unit_price'] === null || $source['unit_price'] === '' || (is_numeric((string)$source['unit_price']) && abs((float)$source['unit_price']) < 0.0001)) && abs($partsTotal) > 0.0001) {
        $source['unit_price'] = $partsTotal;
    }
    $data = array();
    foreach ($source as $column => $value) {
        if (isset($columns[$column])) $data[$column] = $value;
    }
    if (isset($columns['is_active'])) $data['is_active'] = 1;
    if (isset($columns['is_current'])) $data['is_current'] = 1;
    if (isset($columns['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');
    return $data;
}
}

if (!function_exists('cpms_contract_upload_unit_price_available_columns')) {
function cpms_contract_upload_unit_price_available_columns($pdo) {
    $availableColumns = array();
    foreach (array('estimate_version_id', 'contract_version_id', 'version_type', 'version_no', 'additional_work_id', 'trade_group', 'sub_trade', 'location_name', 'work_group', 'sub_work_group', 'item_name', 'original_item_name', 'spec', 'unit', 'qty', 'unit_price', 'labor_unit_price', 'material_unit_price', 'expense_unit_price', 'amount', 'source_row', 'source_row_no', 'source_sheet_name', 'source_type', 'source_version_no', 'item_fingerprint', 'import_order', 'is_safety', 'remark', 'is_active', 'is_current', 'updated_at') as $column) {
        if (cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', $column)) {
            $availableColumns[$column] = true;
        }
    }
    return $availableColumns;
}
}

if (!function_exists('cpms_contract_upload_update_row')) {
function cpms_contract_upload_update_row($pdo, $projectId, $rowId, $data) {
    $sets = array();
    $params = array(':id' => (int)$rowId, ':project_id' => (int)$projectId);
    foreach ($data as $column => $value) {
        array_push($sets, '`' . $column . '` = :' . $column);
        $params[':' . $column] = $value;
    }
    $sql = "UPDATE cpms_project_unit_prices SET " . implode(', ', $sets) . " WHERE id = :id AND project_id = :project_id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
}
}

if (!function_exists('cpms_contract_upload_insert_row')) {
function cpms_contract_upload_insert_row($pdo, $projectId, $data) {
    $columns = array('project_id');
    $holders = array(':project_id');
    $params = array(':project_id' => (int)$projectId);
    foreach ($data as $column => $value) {
        array_push($columns, '`' . $column . '`');
        array_push($holders, ':' . $column);
        $params[':' . $column] = $value;
    }
    $sql = "INSERT INTO cpms_project_unit_prices (" . implode(',', $columns) . ") VALUES (" . implode(',', $holders) . ")";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$pdo->lastInsertId();
}
}

if (!function_exists('cpms_contract_upload_snapshot_current_row')) {
function cpms_contract_upload_snapshot_current_row($pdo, $projectId, $row, $columns) {
    if (!is_array($row) || !isset($columns['is_current'])) return 0;
    $data = array();
    foreach ($columns as $column => $exists) {
        if ($column === 'updated_at') continue;
        if (array_key_exists($column, $row)) $data[$column] = $row[$column];
    }
    $data['is_current'] = 0;
    if (isset($columns['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_contract_upload_insert_row($pdo, $projectId, $data);
}
}

if (!function_exists('cpms_contract_upload_update_planned_qty')) {
function cpms_contract_upload_update_planned_qty($pdo, $unitPriceId, $oldQty, $newQty) {
    if (!is_numeric((string)$newQty)) return;
    $params = array(':uid' => (int)$unitPriceId, ':new_qty' => (float)$newQty);
    $where = "unit_price_id = :uid AND planned_qty IS NULL";
    if (is_numeric((string)$oldQty)) {
        $where = "unit_price_id = :uid AND (planned_qty IS NULL OR ABS(planned_qty - :old_qty) < 0.0001)";
        $params[':old_qty'] = (float)$oldQty;
    }
    $sql = "UPDATE cpms_work_item_lines SET planned_qty = :new_qty WHERE " . $where;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
    } catch (Exception $e) {
        error_log('[contract_upload] planned_qty update failed: ' . $e->getMessage());
    }
}
}

if (!function_exists('cpms_contract_upload_store_change_logs')) {
function cpms_contract_upload_store_change_logs($pdo, $projectId, $contractItemId, $oldRow, $newRow, $badges) {
    if (!$pdo || !is_array($badges) || count($badges) === 0) return;
    if (!cpms_contract_change_table_exists($pdo, 'cpms_contract_change_logs')) return;

    try {
        $hasOldAmount = cpms_contract_upload_column_exists($pdo, 'cpms_contract_change_logs', 'old_amount');
        $hasNewAmount = cpms_contract_upload_column_exists($pdo, 'cpms_contract_change_logs', 'new_amount');
        $hasEstimateVersionId = cpms_contract_upload_column_exists($pdo, 'cpms_contract_change_logs', 'estimate_version_id');
        $hasSourceType = cpms_contract_upload_column_exists($pdo, 'cpms_contract_change_logs', 'source_type');
        $hasSourceVersionNo = cpms_contract_upload_column_exists($pdo, 'cpms_contract_change_logs', 'source_version_no');
        foreach ($badges as $badge) {
            if (!is_array($badge)) continue;
            $type = isset($badge['type']) ? (string)$badge['type'] : '';
            if ($type === '') continue;
            $rowForText = is_array($newRow) ? $newRow : (is_array($oldRow) ? $oldRow : array());
            $oldQty = is_array($oldRow) && isset($oldRow['qty']) ? $oldRow['qty'] : null;
            $newQty = is_array($newRow) && isset($newRow['qty']) ? $newRow['qty'] : null;
            $oldUnitPrice = is_array($oldRow) ? cpms_contract_change_unit_price_value($oldRow) : null;
            $newUnitPrice = is_array($newRow) ? cpms_contract_change_unit_price_value($newRow) : null;
            $oldAmount = is_array($oldRow) && isset($oldRow['amount']) ? $oldRow['amount'] : null;
            $newAmount = is_array($newRow) && isset($newRow['amount']) ? $newRow['amount'] : null;
            $createdBy = cpms_contract_upload_current_user_id();

            $columns = array('project_id', 'contract_item_id', 'change_type', 'item_name', 'spec', 'unit', 'old_quantity', 'new_quantity', 'old_unit_price', 'new_unit_price', 'created_by', 'created_at');
            $holders = array(':project_id', ':contract_item_id', ':change_type', ':item_name', ':spec', ':unit', ':old_quantity', ':new_quantity', ':old_unit_price', ':new_unit_price', ':created_by', ':created_at');
            if ($hasOldAmount) { array_push($columns, 'old_amount'); array_push($holders, ':old_amount'); }
            if ($hasNewAmount) { array_push($columns, 'new_amount'); array_push($holders, ':new_amount'); }
            if ($hasEstimateVersionId) { array_push($columns, 'estimate_version_id'); array_push($holders, ':estimate_version_id'); }
            if ($hasSourceType) { array_push($columns, 'source_type'); array_push($holders, ':source_type'); }
            if ($hasSourceVersionNo) { array_push($columns, 'source_version_no'); array_push($holders, ':source_version_no'); }

            $st = $pdo->prepare("INSERT INTO cpms_contract_change_logs (" . implode(',', $columns) . ") VALUES (" . implode(',', $holders) . ")");
            $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':contract_item_id', (int)$contractItemId > 0 ? (int)$contractItemId : null, (int)$contractItemId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(':change_type', $type);
            $st->bindValue(':item_name', isset($rowForText['item_name']) ? (string)$rowForText['item_name'] : null);
            $st->bindValue(':spec', isset($rowForText['spec']) ? (string)$rowForText['spec'] : null);
            $st->bindValue(':unit', isset($rowForText['unit']) ? (string)$rowForText['unit'] : null);
            $st->bindValue(':old_quantity', $oldQty !== null ? $oldQty : null, $oldQty !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':new_quantity', $newQty !== null ? $newQty : null, $newQty !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':old_unit_price', $oldUnitPrice !== null ? $oldUnitPrice : null, $oldUnitPrice !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':new_unit_price', $newUnitPrice !== null ? $newUnitPrice : null, $newUnitPrice !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':created_by', $createdBy > 0 ? $createdBy : null, $createdBy > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(':created_at', date('Y-m-d H:i:s'));
            if ($hasOldAmount) $st->bindValue(':old_amount', $oldAmount !== null ? $oldAmount : null, $oldAmount !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            if ($hasNewAmount) $st->bindValue(':new_amount', $newAmount !== null ? $newAmount : null, $newAmount !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            if ($hasEstimateVersionId) $st->bindValue(':estimate_version_id', isset($rowForText['estimate_version_id']) ? (int)$rowForText['estimate_version_id'] : null, isset($rowForText['estimate_version_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
            if ($hasSourceType) $st->bindValue(':source_type', isset($rowForText['source_type']) ? (string)$rowForText['source_type'] : null);
            if ($hasSourceVersionNo) $st->bindValue(':source_version_no', isset($rowForText['source_version_no']) ? (int)$rowForText['source_version_no'] : null, isset($rowForText['source_version_no']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->execute();
        }
    } catch (Exception $e) {
        error_log('[contract_upload] change log insert failed: ' . $e->getMessage());
    }
}
}

if (!function_exists('cpms_contract_upload_apply_unit_price_update')) {
function cpms_contract_upload_apply_unit_price_update($pdo, $projectId, $rows, $versionInfo) {
    if (!is_array($versionInfo)) $versionInfo = array();
    $summary = array(
        'updated' => 0,
        'inserted' => 0,
        'deactivated' => 0,
        'kept' => 0,
        'changed' => 0,
        'unit_price_changed' => 0,
        'amount_changed' => 0,
        'quantity_increased' => 0,
        'quantity_decreased' => 0,
        'historical_snapshots' => 0
    );

    $requiredColumns = array('item_name', 'spec', 'unit', 'qty', 'unit_price');
    $availableColumns = cpms_contract_upload_unit_price_available_columns($pdo);
    foreach ($requiredColumns as $column) {
        if (!isset($availableColumns[$column])) {
            throw new Exception('cpms_project_unit_prices.' . $column . ' 컬럼이 없습니다.');
        }
    }

    $stOld = $pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id = :pid ORDER BY id ASC");
    $stOld->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stOld->execute();
    $existingRows = $stOld->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($existingRows)) $existingRows = array();

    $activeRows = array();
    foreach ($existingRows as $row) {
        if (isset($availableColumns['is_active']) && isset($row['is_active']) && (int)$row['is_active'] === 0) continue;
        if (isset($availableColumns['is_current']) && isset($row['is_current']) && (int)$row['is_current'] === 0) continue;
        array_push($activeRows, $row);
    }

    $matchData = cpms_contract_change_build_match_maps($activeRows);

    $usedIndexes = array();

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $key = cpms_contract_change_match_key_for_fields($row, array('item_name', 'spec', 'unit'));
        if (function_exists('cpms_contract_change_row_key_empty') && cpms_contract_change_row_key_empty($key)) continue;

        $match = cpms_contract_change_pick_match($matchData, $row, $usedIndexes);
        $matchIndex = isset($match['index']) ? (int)$match['index'] : -1;

        $data = cpms_contract_upload_build_data($row, $availableColumns, $versionInfo);

        if ($matchIndex >= 0 && isset($activeRows[$matchIndex])) {
            $usedIndexes[$matchIndex] = 1;
            $oldRow = $activeRows[$matchIndex];
            if (isset($availableColumns['is_safety'])) {
                $data['is_safety'] = isset($oldRow['is_safety']) ? (int)$oldRow['is_safety'] : 0;
                $row['is_safety'] = $data['is_safety'];
            }
            $badges = array();
            $oldUnitPrice = cpms_contract_change_unit_price_value($oldRow);
            $newUnitPrice = cpms_contract_change_unit_price_value($row);
            if (!cpms_contract_change_number_same($oldUnitPrice, $newUnitPrice)) {
                $summary['unit_price_changed']++;
                array_push($badges, cpms_contract_change_badge('UNIT_PRICE_CHANGED', '단가 변경', $oldUnitPrice, $newUnitPrice));
            }
            $oldQty = isset($oldRow['qty']) ? $oldRow['qty'] : null;
            $newQty = isset($row['qty']) ? $row['qty'] : null;
            if (!cpms_contract_change_number_same($oldQty, $newQty)) {
                $oldQtyNum = cpms_contract_change_number($oldQty);
                $newQtyNum = cpms_contract_change_number($newQty);
                if ($newQtyNum > $oldQtyNum) {
                    $summary['quantity_increased']++;
                    array_push($badges, cpms_contract_change_badge('QUANTITY_INCREASED', '수량 증가', $oldQty, $newQty));
                } else {
                    $summary['quantity_decreased']++;
                    array_push($badges, cpms_contract_change_badge('QUANTITY_DECREASED', '수량 감소', $oldQty, $newQty));
                }
            }
            $oldAmount = isset($oldRow['amount']) ? $oldRow['amount'] : null;
            $newAmount = isset($row['amount']) ? $row['amount'] : null;
            if (!cpms_contract_change_number_same($oldAmount, $newAmount)) {
                $summary['amount_changed']++;
                array_push($badges, cpms_contract_change_badge('AMOUNT_CHANGED', '금액 변경', $oldAmount, $newAmount));
            }
            if (isset($versionInfo['version_type']) && $versionInfo['version_type'] === 'change') {
                $snapshotId = cpms_contract_upload_snapshot_current_row($pdo, $projectId, $oldRow, $availableColumns);
                if ($snapshotId > 0) $summary['historical_snapshots']++;
            }
            cpms_contract_upload_update_planned_qty($pdo, (int)$activeRows[$matchIndex]['id'], isset($activeRows[$matchIndex]['qty']) ? $activeRows[$matchIndex]['qty'] : null, isset($data['qty']) ? $data['qty'] : null);
            cpms_contract_upload_update_row($pdo, $projectId, (int)$activeRows[$matchIndex]['id'], $data);
            cpms_contract_upload_store_change_logs($pdo, $projectId, (int)$activeRows[$matchIndex]['id'], $oldRow, $row, $badges);
            $summary['updated']++;
            if (count($badges) > 0) $summary['changed']++;
            else $summary['kept']++;
        } else {
            $newId = cpms_contract_upload_insert_row($pdo, $projectId, $data);
            cpms_contract_upload_store_change_logs($pdo, $projectId, $newId, null, $row, array(cpms_contract_change_badge('ADDED', '추가항목', null, null)));
            $summary['inserted']++;
        }
    }

    if (isset($availableColumns['is_active'])) {
        $deactivateSet = "is_active = 0";
        if (isset($availableColumns['is_current'])) $deactivateSet .= ", is_current = 0";
        if (isset($availableColumns['updated_at'])) $deactivateSet .= ", updated_at = :updated_at";
        $stDeactivate = $pdo->prepare("UPDATE cpms_project_unit_prices SET " . $deactivateSet . " WHERE id = :id AND project_id = :pid");
        foreach ($activeRows as $index => $row) {
            if (isset($usedIndexes[$index])) continue;
            if (isset($availableColumns['updated_at'])) $stDeactivate->bindValue(':updated_at', date('Y-m-d H:i:s'));
            $stDeactivate->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
            $stDeactivate->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stDeactivate->execute();
            cpms_contract_upload_store_change_logs($pdo, $projectId, (int)$row['id'], $row, null, array(cpms_contract_change_badge('DELETED_SUSPECTED', '삭제 의심', null, null)));
            $summary['deactivated']++;
        }
    }

    return $summary;
}
}

if (!function_exists('cpms_contract_upload_deactivate_current_rows')) {
function cpms_contract_upload_deactivate_current_rows($pdo, $projectId, $availableColumns) {
    if (!$pdo || (int)$projectId <= 0 || !is_array($availableColumns)) return 0;
    if (!isset($availableColumns['is_active']) && !isset($availableColumns['is_current'])) return 0;
    $sets = array();
    if (isset($availableColumns['is_active'])) array_push($sets, "is_active = 0");
    if (isset($availableColumns['is_current'])) array_push($sets, "is_current = 0");
    if (isset($availableColumns['updated_at'])) array_push($sets, "updated_at = :updated_at");
    $sql = "UPDATE cpms_project_unit_prices SET " . implode(', ', $sets) . " WHERE project_id = :pid";
    if (isset($availableColumns['is_active'])) $sql .= " AND (is_active = 1 OR is_active IS NULL)";
    if (isset($availableColumns['is_current'])) $sql .= " AND (is_current = 1 OR is_current IS NULL)";
    $st = $pdo->prepare($sql);
    $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    if (isset($availableColumns['updated_at'])) $st->bindValue(':updated_at', date('Y-m-d H:i:s'));
    $st->execute();
    return (int)$st->rowCount();
}
}

if (!function_exists('cpms_contract_upload_apply_unit_price_replace')) {
function cpms_contract_upload_apply_unit_price_replace($pdo, $projectId, $rows, $versionInfo, $deactivateBeforeInsert) {
    if (!is_array($versionInfo)) $versionInfo = array();
    $summary = array(
        'updated' => 0,
        'inserted' => 0,
        'deactivated' => 0,
        'kept' => 0,
        'changed' => 0,
        'unit_price_changed' => 0,
        'amount_changed' => 0,
        'quantity_increased' => 0,
        'quantity_decreased' => 0,
        'historical_snapshots' => 0
    );

    $requiredColumns = array('item_name', 'spec', 'unit', 'qty', 'unit_price');
    $availableColumns = cpms_contract_upload_unit_price_available_columns($pdo);
    foreach ($requiredColumns as $column) {
        if (!isset($availableColumns[$column])) {
            throw new Exception('cpms_project_unit_prices.' . $column . ' 컬럼이 없습니다.');
        }
    }

    if ($deactivateBeforeInsert) {
        $summary['deactivated'] = cpms_contract_upload_deactivate_current_rows($pdo, $projectId, $availableColumns);
    }

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $item = isset($row['item_name']) ? trim((string)$row['item_name']) : '';
        if ($item === '') continue;
        $data = cpms_contract_upload_build_data($row, $availableColumns, $versionInfo);
        cpms_contract_upload_insert_row($pdo, $projectId, $data);
        $summary['inserted']++;
    }

    return $summary;
}
}

if (!function_exists('cpms_contract_upload_attach_version_ids')) {
function cpms_contract_upload_attach_version_ids($pdo, $projectId, $versionType, $sourceType, $versionNo, $contractVersionId, $estimateVersionId) {
    if (!$pdo || (int)$projectId <= 0) return;
    $sets = array();
    if ((int)$contractVersionId > 0 && cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'contract_version_id')) {
        array_push($sets, "contract_version_id = :contract_version_id");
    }
    if ((int)$estimateVersionId > 0 && cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'estimate_version_id')) {
        array_push($sets, "estimate_version_id = :estimate_version_id");
    }
    if (count($sets) === 0) return;
    $sql = "UPDATE cpms_project_unit_prices SET " . implode(', ', $sets) . " WHERE project_id = :pid";
    if (cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'version_type')) $sql .= " AND version_type = :version_type";
    if (cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'version_no')) $sql .= " AND version_no = :version_no";
    if (cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'source_type')) $sql .= " AND source_type = :source_type";
    if (cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'source_version_no')) $sql .= " AND source_version_no = :source_version_no";
    if (cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'is_current')) $sql .= " AND (is_current = 1 OR is_current IS NULL)";
    $st = $pdo->prepare($sql);
    $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    if ((int)$contractVersionId > 0) $st->bindValue(':contract_version_id', (int)$contractVersionId, PDO::PARAM_INT);
    if ((int)$estimateVersionId > 0) $st->bindValue(':estimate_version_id', (int)$estimateVersionId, PDO::PARAM_INT);
    if (strpos($sql, ':version_type') !== false) $st->bindValue(':version_type', (string)$versionType);
    if (strpos($sql, ':version_no') !== false) $st->bindValue(':version_no', (int)$versionNo, PDO::PARAM_INT);
    if (strpos($sql, ':source_type') !== false) $st->bindValue(':source_type', (string)$sourceType);
    if (strpos($sql, ':source_version_no') !== false) $st->bindValue(':source_version_no', (int)$versionNo, PDO::PARAM_INT);
    $st->execute();
}
}

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($csrf)) {
    cpms_contract_upload_redirect(0, 'error', '보안 토큰이 유효하지 않습니다.');
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$uploadMode = isset($_POST['upload_mode']) ? trim((string)$_POST['upload_mode']) : 'contract_only';
if ($uploadMode !== 'unit_price_update' && $uploadMode !== 'unit_price_original' && $uploadMode !== 'unit_price_extra') $uploadMode = 'contract_only';

if ($projectId <= 0) {
    cpms_contract_upload_redirect(0, 'error', '잘못된 프로젝트 ID입니다.');
}

$pdo = Db::pdo();
if (!$pdo) {
    cpms_contract_upload_redirect($projectId, 'error', 'DB 연결에 실패했습니다.');
}

try {
    $stProject = $pdo->prepare("SELECT id FROM cpms_projects WHERE id = :id LIMIT 1");
    $stProject->bindValue(':id', $projectId, PDO::PARAM_INT);
    $stProject->execute();
    if (!$stProject->fetch()) {
        cpms_contract_upload_redirect(0, 'error', '프로젝트를 찾을 수 없습니다.');
    }
} catch (Exception $e) {
    cpms_contract_upload_redirect(0, 'error', '프로젝트 확인에 실패했습니다.');
}

if ($uploadMode === 'unit_price_update' || $uploadMode === 'unit_price_original' || $uploadMode === 'unit_price_extra') {
    try {
        cpms_contract_upload_ensure_upload_schema($pdo);
    } catch (Exception $e) {
        cpms_contract_upload_redirect($projectId, 'error', '내역서 DB 자동 보정 실패: ' . $e->getMessage());
    }
}

$previewToken = isset($_POST['preview_token']) ? trim((string)$_POST['preview_token']) : '';
if (($uploadMode === 'unit_price_update' || $uploadMode === 'unit_price_original' || $uploadMode === 'unit_price_extra') && $previewToken !== '') {
    if (!isset($_SESSION['unit_price_update'][$previewToken]) || !is_array($_SESSION['unit_price_update'][$previewToken])) {
        cpms_contract_upload_redirect($projectId, 'error', '미리보기 데이터가 만료되었습니다.');
    }
    $pack = $_SESSION['unit_price_update'][$previewToken];
    $packProjectId = isset($pack['project_id']) ? (int)$pack['project_id'] : 0;
    if ($packProjectId !== $projectId) {
        cpms_contract_upload_redirect($projectId, 'error', '미리보기 프로젝트 정보가 일치하지 않습니다.');
    }
    $rows = isset($pack['rows']) && is_array($pack['rows']) ? $pack['rows'] : array();
    if (count($rows) === 0) {
        cpms_contract_upload_redirect($projectId, 'error', '적용할 단가내역 데이터가 없습니다.');
    }
    $storedPath = isset($pack['stored_path']) ? (string)$pack['stored_path'] : '';
    $storedName = isset($pack['stored_name']) ? (string)$pack['stored_name'] : basename($storedPath);
    $originalName = isset($pack['file_name']) ? (string)$pack['file_name'] : $storedName;
    $additionalTitle = isset($pack['additional_work_title']) ? trim((string)$pack['additional_work_title']) : '';
    $manualVersionNo = isset($pack['version_no']) ? (int)$pack['version_no'] : 0;

    try {
        $pdo->beginTransaction();
        $versionType = 'change';
        $sourceType = 'CHANGE';
        if ($uploadMode === 'unit_price_original') {
            $versionType = 'original';
            $sourceType = 'ORIGINAL';
        } else if ($uploadMode === 'unit_price_extra') {
            $versionType = 'extra';
            $sourceType = 'EXTRA';
        }
        if ($manualVersionNo > 0) {
            $versionNo = $manualVersionNo;
        } else if ($sourceType === 'ORIGINAL') {
            $versionNo = cpms_contract_upload_estimate_next_version_no($pdo, $projectId, 'ORIGINAL');
        } else {
            $versionNo = cpms_contract_upload_estimate_next_version_no($pdo, $projectId, $sourceType);
        }

        $additionalWorkId = 0;
        if ($uploadMode === 'unit_price_extra' && cpms_contract_change_table_exists($pdo, 'cpms_contract_additional_works')) {
            $nowForAdditional = date('Y-m-d H:i:s');
            $stAdditional = $pdo->prepare("INSERT INTO cpms_contract_additional_works
                (project_id, title, occurred_on, request_ref, remark, status, attachment_original_name, attachment_stored_name, attachment_stored_path, created_by, created_at, updated_at)
                VALUES
                (:project_id, :title, NULL, '', '', '계약 반영 완료', :attachment_original_name, :attachment_stored_name, :attachment_stored_path, :created_by, :created_at, :updated_at)");
            $stAdditional->bindValue(':project_id', $projectId, PDO::PARAM_INT);
            $stAdditional->bindValue(':title', $additionalTitle !== '' ? $additionalTitle : ('추가공사 ' . (int)$versionNo));
            $stAdditional->bindValue(':attachment_original_name', $originalName);
            $stAdditional->bindValue(':attachment_stored_name', $storedName);
            $stAdditional->bindValue(':attachment_stored_path', $storedPath);
            $userIdForAdditional = cpms_contract_upload_current_user_id();
            if ($userIdForAdditional > 0) $stAdditional->bindValue(':created_by', $userIdForAdditional, PDO::PARAM_INT);
            else $stAdditional->bindValue(':created_by', null, PDO::PARAM_NULL);
            $stAdditional->bindValue(':created_at', $nowForAdditional);
            $stAdditional->bindValue(':updated_at', $nowForAdditional);
            $stAdditional->execute();
            $additionalWorkId = (int)$pdo->lastInsertId();
        }

        $versionInfo = array(
            'contract_version_id' => 0,
            'estimate_version_id' => 0,
            'version_type' => $versionType,
            'version_no' => $versionNo,
            'additional_work_id' => $additionalWorkId,
            'source_type' => $sourceType,
            'source_version_no' => $versionNo
        );

        if ($uploadMode === 'unit_price_update') {
            $summary = cpms_contract_upload_apply_unit_price_update($pdo, $projectId, $rows, $versionInfo);
        } else if ($uploadMode === 'unit_price_original') {
            $summary = cpms_contract_upload_apply_unit_price_replace($pdo, $projectId, $rows, $versionInfo, true);
        } else {
            $summary = cpms_contract_upload_apply_unit_price_replace($pdo, $projectId, $rows, $versionInfo, false);
        }

        $versionTitle = cpms_contract_upload_version_title($versionType, $versionNo, $additionalTitle);
        $versionId = cpms_contract_upload_create_version($pdo, $projectId, $versionType, $versionNo, $versionTitle, $originalName, $storedName, $storedPath, $summary);
        $estimateVersionId = cpms_contract_upload_create_estimate_version($pdo, $projectId, $sourceType, $versionNo, $versionTitle, isset($pack['description']) ? (string)$pack['description'] : '', $originalName, $storedName, $storedPath, $summary, count($rows));
        if ($versionId <= 0) {
            throw new Exception('계약 버전 이력을 저장하지 못했습니다.');
        }
        if ($estimateVersionId <= 0) {
            throw new Exception('내역서 버전 이력을 저장하지 못했습니다.');
        }
        cpms_contract_upload_attach_version_ids($pdo, $projectId, $versionType, $sourceType, $versionNo, $versionId, $estimateVersionId);
        $pdo->commit();
        $driveDocumentType = cpms_contract_upload_file_type($uploadMode);
        $driveUpload = cpms_public_affairs_drive_upload_local_file($pdo, $projectId, $storedPath, $originalName, $driveDocumentType, date('Y-m'), date('Y-m-d'), array('date' => date('Y-m-d')), Auth::user());
        $driveRecord = (is_array($driveUpload) && isset($driveUpload['record']) && is_array($driveUpload['record'])) ? $driveUpload['record'] : array();
        $historySave = cpms_contract_upload_store_history($pdo, $projectId, $uploadMode, $originalName, $storedName, $storedPath, $summary, $driveRecord);
        if (!empty($driveUpload['ok']) && empty($historySave['ok']) && isset($driveRecord['drive_file_id']) && trim((string)$driveRecord['drive_file_id']) !== '') {
            cpms_drive_delete_file((string)$driveRecord['drive_file_id'], array(
                'section' => 'public_affairs',
                'project_id' => $projectId,
                'document_type' => isset($driveRecord['document_type']) ? $driveRecord['document_type'] : $driveDocumentType,
                'original_name' => $originalName,
                'target_folder_id' => isset($driveRecord['drive_folder_id']) ? $driveRecord['drive_folder_id'] : '',
                'message' => isset($historySave['message']) ? $historySave['message'] : 'Contract history save failed after Drive upload.'
            ));
            $driveUpload['ok'] = false;
            $driveUpload['message'] = isset($historySave['message']) ? $historySave['message'] : 'Contract history save failed after Drive upload.';
        }
        if (!empty($driveUpload['ok']) && !empty($historySave['ok']) && $versionId > 0) {
            cpms_public_affairs_drive_apply_record_to_row($pdo, 'cpms_contract_versions', $versionId, $driveRecord, cpms_contract_upload_current_user_id(), array(
                'section' => 'public_affairs',
                'project_id' => $projectId,
                'skip_delete_on_failure' => true
            ));
        }
        if (!empty($driveUpload['ok']) && !empty($historySave['ok']) && $estimateVersionId > 0) {
            cpms_public_affairs_drive_apply_record_to_row($pdo, 'cpms_project_estimate_versions', $estimateVersionId, $driveRecord, cpms_contract_upload_current_user_id(), array(
                'section' => 'public_affairs',
                'project_id' => $projectId,
                'skip_delete_on_failure' => true
            ));
        }
        $successMessage = $versionTitle . ' 내역서가 적용되었습니다. 변경 ' . (int)$summary['updated'] . '건 / 신규 ' . (int)$summary['inserted'] . '건 / 이력 전환 ' . (int)$summary['deactivated'] . '건';
        unset($_SESSION['unit_price_update'][$previewToken]);
        cpms_contract_upload_redirect(
            $projectId,
            'success',
            cpms_public_affairs_drive_flash_message($successMessage, $driveUpload)
        );
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cpms_contract_upload_redirect($projectId, 'error', '변경 단가내역서 적용 실패: ' . $e->getMessage());
    }
}

if ($uploadMode === 'unit_price_update' || $uploadMode === 'unit_price_original' || $uploadMode === 'unit_price_extra') {
    cpms_contract_upload_redirect($projectId, 'error', '내역서는 먼저 미리보기를 확인한 뒤 적용해야 합니다.');
}

if (!isset($_FILES['contract_file']) || !is_array($_FILES['contract_file'])) {
    cpms_contract_upload_redirect($projectId, 'error', '업로드할 파일이 없습니다.');
}

$file = $_FILES['contract_file'];
$errorCode = isset($file['error']) ? (int)$file['error'] : 999;
$tmpFile = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
$originalName = isset($file['name']) ? (string)$file['name'] : '';
$size = isset($file['size']) ? (int)$file['size'] : 0;

if ($errorCode !== UPLOAD_ERR_OK || $tmpFile === '' || !is_uploaded_file($tmpFile)) {
    cpms_contract_upload_redirect($projectId, 'error', '파일 업로드에 실패했습니다.');
}

$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedContractExt = array('pdf', 'hwp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png');
if ($uploadMode === 'unit_price_update' || $uploadMode === 'unit_price_original') {
    if ($ext !== 'xlsx') {
        cpms_contract_upload_redirect($projectId, 'error', '내역서는 xlsx 파일만 업로드할 수 있습니다.');
    }
} else {
    if ($ext === '' || !in_array($ext, $allowedContractExt, true)) {
        cpms_contract_upload_redirect($projectId, 'error', '허용되지 않는 파일 형식입니다.');
    }
}

$maxBytes = 30 * 1024 * 1024;
if ($size <= 0 || $size > $maxBytes) {
    cpms_contract_upload_redirect($projectId, 'error', '파일 용량이 올바르지 않습니다. (최대 30MB)');
}

$cpmsRoot = dirname(dirname(dirname(__DIR__)));
$baseDir = $cpmsRoot . '/storage/contracts/' . $projectId;
if ($uploadMode === 'unit_price_update') {
    $targetDir = $baseDir . '/changes';
} else if ($uploadMode === 'unit_price_original') {
    $targetDir = $baseDir . '/versions';
} else {
    $targetDir = $baseDir;
}
if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);
if (!is_dir($targetDir)) {
    cpms_contract_upload_redirect($projectId, 'error', '업로드 폴더를 생성할 수 없습니다.');
}

$random = bin2hex(openssl_random_pseudo_bytes(8));
if ($uploadMode === 'unit_price_update') {
    $prefix = 'unit_price_update_';
} else if ($uploadMode === 'unit_price_original') {
    $prefix = 'unit_price_original_';
} else {
    $prefix = 'contract_';
}
$storedName = $prefix . date('Ymd_His') . '_' . $random . '.' . $ext;
$storedPath = $targetDir . '/' . $storedName;

if (!@move_uploaded_file($tmpFile, $storedPath)) {
    cpms_contract_upload_redirect($projectId, 'error', '파일 저장에 실패했습니다.');
}

if ($uploadMode === 'contract_only') {
    $metaFile = $baseDir . '/meta.json';
    if (is_file($metaFile)) {
        $oldJson = @file_get_contents($metaFile);
        $oldMeta = @json_decode($oldJson, true);
        if (is_array($oldMeta) && isset($oldMeta['stored_name'])) {
            $oldStored = basename((string)$oldMeta['stored_name']);
            $oldPath = $baseDir . '/' . $oldStored;
        }
        @unlink($metaFile);
    }

    if ($targetDir !== $baseDir) {
        $storedPath = $baseDir . '/' . $storedName;
        @rename($targetDir . '/' . $storedName, $storedPath);
    }

    $driveUpload = cpms_public_affairs_drive_upload_local_file($pdo, $projectId, $storedPath, $originalName, 'contract_only', date('Y-m'), date('Y-m-d'), array('date' => date('Y-m-d')), Auth::user());
    $driveRecord = (is_array($driveUpload) && isset($driveUpload['record']) && is_array($driveUpload['record'])) ? $driveUpload['record'] : array();

    $meta = array(
        'project_id' => $projectId,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => Auth::userEmail()
    );
    if (is_array($driveRecord)) {
        foreach (array('storage_type', 'drive_file_id', 'drive_folder_id', 'drive_web_view_link', 'drive_web_content_link', 'drive_name', 'mime_type', 'file_size', 'document_type', 'document_year', 'document_month', 'drive_year_folder_id', 'drive_month_folder_id', 'upload_status', 'drive_upload_error') as $metaKey) {
            if ($metaKey === 'drive_name' && isset($driveRecord['stored_name'])) $meta[$metaKey] = $driveRecord['stored_name'];
            else if ($metaKey === 'file_size' && isset($driveRecord['size'])) $meta[$metaKey] = $driveRecord['size'];
            else if (isset($driveRecord[$metaKey])) $meta[$metaKey] = $driveRecord[$metaKey];
        }
    }
    $metaSaved = (@file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false);
    if (!$metaSaved && !empty($driveUpload['ok']) && isset($driveRecord['drive_file_id']) && trim((string)$driveRecord['drive_file_id']) !== '') {
        cpms_drive_delete_file((string)$driveRecord['drive_file_id'], array(
            'section' => 'public_affairs',
            'project_id' => $projectId,
            'document_type' => isset($driveRecord['document_type']) ? $driveRecord['document_type'] : 'contract',
            'original_name' => $originalName,
            'target_folder_id' => isset($driveRecord['drive_folder_id']) ? $driveRecord['drive_folder_id'] : '',
            'message' => 'Contract meta.json save failed after Drive upload.'
        ));
        $driveUpload['ok'] = false;
    }
    cpms_contract_upload_store_history($pdo, $projectId, $uploadMode, $originalName, $storedName, $storedPath, array('message' => 'stored'), $driveRecord);
    cpms_contract_upload_redirect($projectId, 'success', cpms_public_affairs_drive_flash_message('계약서 파일이 업로드되었습니다.', $driveUpload));
}

try {
    $parsed = cpms_project_parse_unit_price_xlsx($pdo, $storedPath);
    if (!is_array($parsed) || empty($parsed['ok'])) {
        throw new Exception(isset($parsed['message']) ? $parsed['message'] : '엑셀 파싱에 실패했습니다.');
    }

    $rows = isset($parsed['rows']) && is_array($parsed['rows']) ? $parsed['rows'] : array();
    if (count($rows) === 0) {
        throw new Exception('적용할 단가내역 데이터가 없습니다.');
    }

    if ($uploadMode !== 'unit_price_original') {
        throw new Exception('지원하지 않는 내역서 업로드 방식입니다.');
    }

    $currentCount = 0;
    try {
        $hasIsActiveForCount = cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'is_active');
        $hasIsCurrentForCount = cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'is_current');
        $countSql = "SELECT COUNT(*) FROM cpms_project_unit_prices WHERE project_id = :pid";
        if ($hasIsActiveForCount) $countSql .= " AND (is_active = 1 OR is_active IS NULL)";
        if ($hasIsCurrentForCount) $countSql .= " AND (is_current = 1 OR is_current IS NULL)";
        $stCount = $pdo->prepare($countSql);
        $stCount->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stCount->execute();
        $currentCount = (int)$stCount->fetchColumn();
    } catch (Exception $e) {
        $currentCount = 0;
    }
    if ($currentCount > 0) {
        throw new Exception('이미 현재 적용 내역서가 있습니다. 당초 내역서를 덮어쓰지 말고 변경계약 미리보기를 사용해주세요.');
    }

    $pdo->beginTransaction();
    $versionNo = 0;
    $versionInfo = array(
        'contract_version_id' => 0,
        'version_type' => 'original',
        'version_no' => $versionNo,
        'additional_work_id' => 0
    );
    $summary = cpms_contract_upload_apply_unit_price_update($pdo, $projectId, $rows, $versionInfo);
    $versionTitle = cpms_contract_upload_version_title('original', $versionNo, '');
    $versionId = cpms_contract_upload_create_version($pdo, $projectId, 'original', $versionNo, $versionTitle, $originalName, $storedName, $storedPath, $summary);
    if ($versionId > 0 && cpms_contract_upload_column_exists($pdo, 'cpms_project_unit_prices', 'contract_version_id')) {
        $stVersionRows = $pdo->prepare("UPDATE cpms_project_unit_prices SET contract_version_id = :vid WHERE project_id = :pid AND version_type = 'original' AND is_current = 1");
        $stVersionRows->bindValue(':vid', $versionId, PDO::PARAM_INT);
        $stVersionRows->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stVersionRows->execute();
    }
    $pdo->commit();

    $driveUpload = cpms_public_affairs_drive_upload_local_file($pdo, $projectId, $storedPath, $originalName, 'unit_price_original', date('Y-m'), date('Y-m-d'), array('date' => date('Y-m-d')), Auth::user());
    $driveRecord = (is_array($driveUpload) && isset($driveUpload['record']) && is_array($driveUpload['record'])) ? $driveUpload['record'] : array();
    $historySave = cpms_contract_upload_store_history($pdo, $projectId, $uploadMode, $originalName, $storedName, $storedPath, $summary, $driveRecord);
    if (!empty($driveUpload['ok']) && empty($historySave['ok']) && isset($driveRecord['drive_file_id']) && trim((string)$driveRecord['drive_file_id']) !== '') {
        cpms_drive_delete_file((string)$driveRecord['drive_file_id'], array(
            'section' => 'public_affairs',
            'project_id' => $projectId,
            'document_type' => isset($driveRecord['document_type']) ? $driveRecord['document_type'] : 'unit_price_original',
            'original_name' => $originalName,
            'target_folder_id' => isset($driveRecord['drive_folder_id']) ? $driveRecord['drive_folder_id'] : '',
            'message' => isset($historySave['message']) ? $historySave['message'] : 'Contract history save failed after Drive upload.'
        ));
        $driveUpload['ok'] = false;
        $driveUpload['message'] = isset($historySave['message']) ? $historySave['message'] : 'Contract history save failed after Drive upload.';
    }
    if (!empty($driveUpload['ok']) && !empty($historySave['ok']) && $versionId > 0) {
        cpms_public_affairs_drive_apply_record_to_row($pdo, 'cpms_contract_versions', $versionId, $driveRecord, cpms_contract_upload_current_user_id(), array(
            'section' => 'public_affairs',
            'project_id' => $projectId,
            'skip_delete_on_failure' => true
        ));
    }
    $successMessage = '당초 내역서가 현재 적용 내역서로 저장되었습니다. 신규 ' . (int)$summary['inserted'] . '건';

    cpms_contract_upload_redirect(
        $projectId,
        'success',
        cpms_public_affairs_drive_flash_message($successMessage, $driveUpload)
    );
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cpms_contract_upload_redirect($projectId, 'error', '내역서 적용 실패: ' . $e->getMessage());
}
