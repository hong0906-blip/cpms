<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!function_exists('cpms_project_update_redirect_url')) {
function cpms_project_update_redirect_url($projectId) {
    $projectId = (int)$projectId;
    if ($projectId > 0) return '?r=project/detail&id=' . $projectId;
    return '?r=공무';
}
}

if (!function_exists('cpms_project_update_debug_context')) {
function cpms_project_update_debug_context($extra) {
    $token = isset($_POST['unit_price_update_token']) ? trim((string)$_POST['unit_price_update_token']) : '';
    $sessionHas = (isset($_SESSION['unit_price_update']) && is_array($_SESSION['unit_price_update']));
    $tokenFound = ($token !== '' && $sessionHas && isset($_SESSION['unit_price_update'][$token]));
    $tokenProjectId = null;
    if ($tokenFound && isset($_SESSION['unit_price_update'][$token]['project_id'])) {
        $tokenProjectId = (int)$_SESSION['unit_price_update'][$token]['project_id'];
    }
    $csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    $ctx = array(
        'REQUEST_METHOD' => isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '',
        'has_csrf' => ($csrf !== ''),
        'csrf_valid' => ($csrf !== '' && csrf_check($csrf)),
        'project_id' => isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0,
        'name_exists' => (isset($_POST['name']) && trim((string)$_POST['name']) !== ''),
        'main_manager_id' => isset($_POST['main_manager_id']) ? (int)$_POST['main_manager_id'] : 0,
        'unit_price_update_token_exists' => ($token !== ''),
        'session_has_unit_price_update' => $sessionHas,
        'token_found' => $tokenFound,
        'token_project_id' => $tokenProjectId,
        'current_project_id' => isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0
    );
    if (is_array($extra)) {
        foreach ($extra as $k => $v) $ctx[$k] = $v;
    }
    return $ctx;
}
}

if (!function_exists('cpms_project_update_fail')) {
function cpms_project_update_fail($message, $logMessage, $projectId, $pdo, $debugExtra) {
    if ($logMessage !== '') error_log($logMessage);
    if (is_object($pdo) && method_exists($pdo, 'inTransaction') && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Exception $e) {}
    }
    $debugMode = (isset($_GET['debug_project_update']) && (string)$_GET['debug_project_update'] === '1');
    if ($debugMode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'ok' => false,
            'message' => $message,
            'debug' => cpms_project_update_debug_context($debugExtra)
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_set('error', $message);
    header('Location: ' . cpms_project_update_redirect_url($projectId));
    exit;
}
}

if (!function_exists('cpms_project_update_column_exists')) {
function cpms_project_update_column_exists($pdo, $table, $column) {
    try {
        $sql = "SHOW COLUMNS FROM `" . $table . "` LIKE :column_name";
        $st = $pdo->prepare($sql);
        $st->bindValue(':column_name', (string)$column);
        $st->execute();
        return ($st->fetch() ? true : false);
    } catch (Exception $e) {
        error_log('[project_update] column check failed: ' . $table . '.' . $column . ' / ' . $e->getMessage());
        return false;
    }
}
}

if (!function_exists('cpms_project_update_row_key')) {
function cpms_project_update_row_key($row) {
    $item = isset($row['item_name']) ? trim((string)$row['item_name']) : '';
    $spec = isset($row['spec']) ? trim((string)$row['spec']) : '';
    $unit = isset($row['unit']) ? trim((string)$row['unit']) : '';
    return $item . '|' . $spec . '|' . $unit;
}
}

if (!function_exists('cpms_project_update_safe_json')) {
function cpms_project_update_safe_json($value) {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($json === false) $json = json_encode($value);
    if ($json === false) return '';
    return $json;
}
}

if (!function_exists('cpms_project_update_field')) {
function cpms_project_update_field($row, $key, $default) {
    return isset($row[$key]) ? $row[$key] : $default;
}
}

if (!function_exists('cpms_project_update_log_change')) {
function cpms_project_update_log_change($logSt, $projectId, $unitPriceId, $changeType, $before, $after) {
    if (!$logSt) return false;
    try {
        $logSt->execute(array(
            ':project_id' => (int)$projectId,
            ':unit_price_id' => (int)$unitPriceId,
            ':change_type' => (string)$changeType,
            ':before_json' => cpms_project_update_safe_json($before),
            ':after_json' => cpms_project_update_safe_json($after)
        ));
        return true;
    } catch (Exception $e) {
        error_log('[project_update] log insert failed: ' . $e->getMessage());
        return false;
    }
}
}

if (!function_exists('cpms_project_update_current_user_id')) {
function cpms_project_update_current_user_id() {
    $u = Auth::user();
    if (is_array($u) && isset($u['id'])) return (int)$u['id'];
    return 0;
}
}

if (!function_exists('cpms_project_update_insert_change_file')) {
function cpms_project_update_insert_change_file($pdo, $projectId, $pack, $token, $storedPath, $summary) {
    try {
        $originalName = isset($pack['file_name']) ? basename((string)$pack['file_name']) : 'unit_price_update.xlsx';
        $storedName = basename((string)$storedPath);
        $st = $pdo->prepare("INSERT INTO cpms_project_contract_change_files
            (project_id, original_name, stored_name, stored_path, file_type, uploaded_by, uploaded_at, applied_token, change_summary)
            VALUES(:project_id, :original_name, :stored_name, :stored_path, 'unit_price_update', :uploaded_by, NOW(), :applied_token, :change_summary)");
        $st->execute(array(
            ':project_id' => (int)$projectId,
            ':original_name' => $originalName,
            ':stored_name' => $storedName,
            ':stored_path' => (string)$storedPath,
            ':uploaded_by' => cpms_project_update_current_user_id(),
            ':applied_token' => (string)$token,
            ':change_summary' => cpms_project_update_safe_json($summary)
        ));
        return true;
    } catch (Exception $e) {
        error_log('[project_update] change file history insert failed: ' . $e->getMessage());
        return false;
    }
}
}

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }

$pdo = null;
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_project_update_fail('수정 실패: POST 방식이 아닙니다.', '[project_update] invalid method: ' . (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : ''), $projectId, $pdo, array('reason' => 'not_post'));
}

$csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($csrf)) {
    cpms_project_update_fail('수정 실패: 보안 토큰이 유효하지 않습니다.', '[project_update] csrf failed', $projectId, $pdo, array('reason' => 'csrf_failed'));
}

if ($projectId <= 0) {
    cpms_project_update_fail('수정 실패: 프로젝트 ID가 없습니다.', '[project_update] missing project_id', $projectId, $pdo, array('reason' => 'missing_project_id'));
}

$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
if ($name === '') {
    cpms_project_update_fail('수정 실패: 프로젝트명이 없습니다.', '[project_update] missing project name', $projectId, $pdo, array('reason' => 'missing_name'));
}

$mainManagerId = isset($_POST['main_manager_id']) ? (int)$_POST['main_manager_id'] : 0;
if ($mainManagerId <= 0) {
    cpms_project_update_fail('수정 실패: 공사 담당자가 없습니다.', '[project_update] missing main_manager_id', $projectId, $pdo, array('reason' => 'missing_main_manager_id'));
}

$client = isset($_POST['client']) ? trim((string)$_POST['client']) : '';
$contractor = isset($_POST['contractor']) ? trim((string)$_POST['contractor']) : '';
$location = isset($_POST['location']) ? trim((string)$_POST['location']) : '';
$start_date = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
$end_date = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$contract_amount = isset($_POST['contract_amount']) ? trim((string)$_POST['contract_amount']) : '';
$subManagerIds = isset($_POST['sub_manager_ids']) && is_array($_POST['sub_manager_ids']) ? $_POST['sub_manager_ids'] : array();
$updateToken = isset($_POST['unit_price_update_token']) ? trim((string)$_POST['unit_price_update_token']) : '';

$contractAmountVal = null;
if ($contract_amount !== '') {
    $clean = preg_replace('/[^0-9]/', '', $contract_amount);
    if ($clean !== '') $contractAmountVal = (int)$clean;
}
$startVal = ($start_date !== '') ? $start_date : null;
$endVal = ($end_date !== '') ? $end_date : null;

$pdo = Db::pdo();
if (!$pdo) {
    cpms_project_update_fail('수정 실패: DB 연결 실패', '[project_update] DB connection failed', $projectId, $pdo, array('reason' => 'db_connection_failed'));
}

try {
    $stExists = $pdo->prepare('SELECT id FROM cpms_projects WHERE id = :id LIMIT 1');
    $stExists->execute(array(':id' => $projectId));
    if (!$stExists->fetch()) {
        cpms_project_update_fail('수정 실패: 프로젝트를 찾을 수 없습니다.', '[project_update] project not found: ' . $projectId, $projectId, $pdo, array('reason' => 'project_not_found'));
    }

    $pack = null;
    if ($updateToken !== '') {
        if (!isset($_SESSION['unit_price_update']) || !is_array($_SESSION['unit_price_update']) || !isset($_SESSION['unit_price_update'][$updateToken])) {
            cpms_project_update_fail('수정 실패: 변경 단가내역 미리보기 정보가 만료되었습니다. 다시 미리보기를 실행해주세요.', '[project_update] token expired: ' . $updateToken, $projectId, $pdo, array('reason' => 'token_not_found'));
        }

        $pack = $_SESSION['unit_price_update'][$updateToken];
        $createdAt = isset($pack['created_at']) ? (int)$pack['created_at'] : 0;
        if ($createdAt <= 0 || (time() - $createdAt) > 7200) {
            unset($_SESSION['unit_price_update'][$updateToken]);
            cpms_project_update_fail('수정 실패: 변경 단가내역 미리보기 정보가 만료되었습니다. 다시 미리보기를 실행해주세요.', '[project_update] token expired: ' . $updateToken, $projectId, $pdo, array('reason' => 'token_expired'));
        }

        if (!isset($pack['project_id']) || (int)$pack['project_id'] !== $projectId) {
            cpms_project_update_fail('수정 실패: 단가 변경 토큰의 프로젝트가 현재 프로젝트와 다릅니다.', '[project_update] token project mismatch: token_project_id=' . (isset($pack['project_id']) ? (int)$pack['project_id'] : 0) . ', current_project_id=' . $projectId, $projectId, $pdo, array('reason' => 'token_project_mismatch'));
        }

        $requiredUnitColumns = array('item_name', 'spec', 'unit', 'qty', 'unit_price', 'labor_unit_price', 'material_unit_price', 'safety_unit_price', 'is_safety', 'remark', 'is_active', 'updated_at');
        foreach ($requiredUnitColumns as $colName) {
            if (!cpms_project_update_column_exists($pdo, 'cpms_project_unit_prices', $colName)) {
                cpms_project_update_fail('수정 실패: DB 컬럼 없음(cpms_project_unit_prices.' . $colName . '). 공무 DB 설치/확인을 먼저 실행해주세요.', '[project_update] DB column missing: cpms_project_unit_prices.' . $colName, $projectId, $pdo, array('reason' => 'missing_db_column', 'missing_column' => 'cpms_project_unit_prices.' . $colName));
            }
        }

        if (!cpms_project_update_column_exists($pdo, 'cpms_project_unit_price_change_logs', 'before_json')) {
            cpms_project_update_fail('수정 실패: DB 컬럼 없음(cpms_project_unit_price_change_logs.before_json). 공무 DB 설치/확인을 먼저 실행해주세요.', '[project_update] DB column missing: cpms_project_unit_price_change_logs.before_json', $projectId, $pdo, array('reason' => 'missing_db_column', 'missing_column' => 'cpms_project_unit_price_change_logs.before_json'));
        }
        if (!cpms_project_update_column_exists($pdo, 'cpms_project_unit_price_change_logs', 'after_json')) {
            cpms_project_update_fail('수정 실패: DB 컬럼 없음(cpms_project_unit_price_change_logs.after_json). 공무 DB 설치/확인을 먼저 실행해주세요.', '[project_update] DB column missing: cpms_project_unit_price_change_logs.after_json', $projectId, $pdo, array('reason' => 'missing_db_column', 'missing_column' => 'cpms_project_unit_price_change_logs.after_json'));
        }
    }

    $pdo->beginTransaction();

    $stProject = $pdo->prepare("UPDATE cpms_projects
        SET name=:name, client=:client, contractor=:contractor, location=:loc, start_date=:sd, end_date=:ed, contract_amount=:ca, status=:st
        WHERE id=:id");
    $stProject->execute(array(
        ':name' => $name,
        ':client' => $client,
        ':contractor' => $contractor,
        ':loc' => $location,
        ':sd' => $startVal,
        ':ed' => $endVal,
        ':ca' => $contractAmountVal,
        ':st' => $status,
        ':id' => $projectId
    ));

    $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid")->execute(array(':pid' => $projectId));
    $stMem = $pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, :role)");
    $stMem->execute(array(':pid' => $projectId, ':eid' => $mainManagerId, ':role' => 'main'));
    $seenMembers = array($mainManagerId => 1);
    foreach ($subManagerIds as $sid) {
        $eid = (int)$sid;
        if ($eid <= 0 || isset($seenMembers[$eid])) continue;
        $seenMembers[$eid] = 1;
        $stMem->execute(array(':pid' => $projectId, ':eid' => $eid, ':role' => 'sub'));
    }

    $summary = array('updated' => 0, 'inserted' => 0, 'deactivated' => 0, 'reactivated' => 0, 'log_failed' => 0);
    $finalStoredPath = '';

    if ($updateToken !== '' && is_array($pack)) {
        $rows = isset($pack['rows']) && is_array($pack['rows']) ? $pack['rows'] : array();
        $oldRows = array();
        $stOld = $pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id=:pid");
        $stOld->execute(array(':pid' => $projectId));
        $oldFetch = $stOld->fetchAll();
        if (is_array($oldFetch)) {
            foreach ($oldFetch as $r) array_push($oldRows, $r);
        }

        $oldMap = array();
        foreach ($oldRows as $r) $oldMap[cpms_project_update_row_key($r)] = $r;

        $seenKeys = array();
        $upSt = $pdo->prepare("UPDATE cpms_project_unit_prices
            SET item_name=:item_name, spec=:spec, unit=:unit, qty=:qty, unit_price=:unit_price,
                labor_unit_price=:labor_unit_price, material_unit_price=:material_unit_price,
                safety_unit_price=:safety_unit_price, is_safety=:is_safety, remark=:remark,
                is_active=1, updated_at=NOW()
            WHERE id=:id AND project_id=:pid");
        $inSt = $pdo->prepare("INSERT INTO cpms_project_unit_prices
            (project_id, item_name, spec, unit, qty, unit_price, labor_unit_price, material_unit_price, safety_unit_price, is_safety, remark, is_active, updated_at)
            VALUES(:pid, :item_name, :spec, :unit, :qty, :unit_price, :labor_unit_price, :material_unit_price, :safety_unit_price, :is_safety, :remark, 1, NOW())");

        $logSt = null;
        try {
            $logSt = $pdo->prepare("INSERT INTO cpms_project_unit_price_change_logs
                (project_id, unit_price_id, change_type, before_json, after_json, created_at)
                VALUES(:project_id, :unit_price_id, :change_type, :before_json, :after_json, NOW())");
        } catch (Exception $eLogPrepare) {
            error_log('[project_update] log prepare failed: ' . $eLogPrepare->getMessage());
            $summary['log_failed']++;
        }

        foreach ($rows as $nr) {
            if (!is_array($nr)) continue;
            $k = cpms_project_update_row_key($nr);
            if ($k === '||') continue;
            $seenKeys[$k] = 1;
            $params = array(
                ':pid' => $projectId,
                ':item_name' => cpms_project_update_field($nr, 'item_name', ''),
                ':spec' => cpms_project_update_field($nr, 'spec', ''),
                ':unit' => cpms_project_update_field($nr, 'unit', ''),
                ':qty' => cpms_project_update_field($nr, 'qty', null),
                ':unit_price' => cpms_project_update_field($nr, 'unit_price', null),
                ':labor_unit_price' => cpms_project_update_field($nr, 'labor_unit_price', null),
                ':material_unit_price' => cpms_project_update_field($nr, 'material_unit_price', null),
                ':safety_unit_price' => cpms_project_update_field($nr, 'safety_unit_price', null),
                ':is_safety' => (int)cpms_project_update_field($nr, 'is_safety', 0),
                ':remark' => cpms_project_update_field($nr, 'remark', '')
            );

            if (isset($oldMap[$k])) {
                $or = $oldMap[$k];
                $wasInactive = (isset($or['is_active']) && (int)$or['is_active'] === 0);
                $params[':id'] = (int)$or['id'];
                $upSt->execute($params);
                if ($wasInactive) $summary['reactivated']++;
                else $summary['updated']++;
                if (!cpms_project_update_log_change($logSt, $projectId, (int)$or['id'], ($wasInactive ? 'REACTIVATED' : 'UPDATED'), $or, $nr)) $summary['log_failed']++;
            } else {
                $inSt->execute($params);
                $newId = (int)$pdo->lastInsertId();
                $summary['inserted']++;
                if (!cpms_project_update_log_change($logSt, $projectId, $newId, 'INSERTED', array(), $nr)) $summary['log_failed']++;
            }
        }

        $deactivateSt = $pdo->prepare("UPDATE cpms_project_unit_prices SET is_active=0, updated_at=NOW() WHERE id=:id AND project_id=:pid");
        foreach ($oldRows as $or) {
            $k = cpms_project_update_row_key($or);
            if (isset($seenKeys[$k])) continue;
            if (isset($or['is_active']) && (int)$or['is_active'] === 0) continue;
            $deactivateSt->execute(array(':id' => (int)$or['id'], ':pid' => $projectId));
            $summary['deactivated']++;
            if (!cpms_project_update_log_change($logSt, $projectId, (int)$or['id'], 'DEACTIVATED', $or, array())) $summary['log_failed']++;
        }

        if (isset($pack['stored_path']) && is_file($pack['stored_path'])) {
            $storedPath = (string)$pack['stored_path'];
            $changeDir = dirname($storedPath);
            $baseName = isset($pack['file_name']) ? basename((string)$pack['file_name']) : 'original.xlsx';
            $finalName = 'change_' . date('Ymd_His') . '_' . $baseName;
            $finalPath = $changeDir . '/' . $finalName;
            if (@rename($storedPath, $finalPath)) {
                $finalStoredPath = $finalPath;
            } else {
                $finalStoredPath = $storedPath;
                error_log('[project_update] change file rename failed: ' . $storedPath . ' -> ' . $finalPath);
            }
            cpms_project_update_insert_change_file($pdo, $projectId, $pack, $updateToken, $finalStoredPath, $summary);
        } else {
            error_log('[project_update] change file not found for history: token=' . $updateToken);
        }
    }

    $pdo->commit();

    if ($updateToken !== '' && isset($_SESSION['unit_price_update'][$updateToken])) {
        unset($_SESSION['unit_price_update'][$updateToken]);
    }

    flash_set('success', '프로젝트가 수정되었습니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
} catch (Exception $e) {
    error_log('[project_update] SQL error: ' . $e->getMessage());
    cpms_project_update_fail('수정 실패: ' . $e->getMessage(), '', $projectId, $pdo, array('reason' => 'sql_error'));
}
