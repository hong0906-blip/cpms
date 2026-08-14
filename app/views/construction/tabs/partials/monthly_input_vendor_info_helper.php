<?php
/**
 * 파일: C:\www\cpms\app\views\construction\tabs\partials\monthly_input_vendor_info_helper.php
 * 공사 > 투입비 상세 표의 업체별 거래처 정보
 * - 업체명별 대표자명 / 전화번호 / 사업자등록번호 조회
 * - 통합 업체 마스터를 우선하고 외주비, 자재구입비, 장비비 입력자료로 빈 항목 보충
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../../../services/VendorService.php';

if (!function_exists('cpms_monthly_input_vendor_table_exists')) {
function cpms_monthly_input_vendor_table_exists($pdo, $table) {
    if (!$pdo || trim((string)$table) === '') return false;
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $st->bindValue(':table_name', (string)$table, PDO::PARAM_STR);
        $st->execute();
        return $st->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_monthly_input_vendor_column_exists')) {
function cpms_monthly_input_vendor_column_exists($pdo, $table, $column) {
    if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') return false;
    try {
        $safeTable = str_replace('`', '', (string)$table);
        $st = $pdo->prepare('SHOW COLUMNS FROM `' . $safeTable . '` LIKE :column_name');
        $st->bindValue(':column_name', (string)$column, PDO::PARAM_STR);
        $st->execute();
        return $st->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_monthly_input_vendor_key')) {
function cpms_monthly_input_vendor_key($companyName) {
    $companyName = trim((string)$companyName);
    if ($companyName === '') return '';
    $companyName = preg_replace('/\s+/u', '', $companyName);
    return function_exists('mb_strtolower')
        ? mb_strtolower($companyName, 'UTF-8')
        : strtolower($companyName);
}}

if (!function_exists('cpms_monthly_input_labor_vendor_key')) {
function cpms_monthly_input_labor_vendor_key($companyName) {
    $companyName = cpms_monthly_input_vendor_key($companyName);
    if ($companyName === '') return '';
    $companyName = preg_replace('/^(?:주식회사|\(주\)|㈜)+/u', '', $companyName);
    return trim((string)$companyName);
}}

if (!function_exists('cpms_monthly_input_vendor_load_labor_aliases')) {
function cpms_monthly_input_vendor_load_labor_aliases($pdo, &$map, $includeBankInfo = false) {
    if (!cpms_monthly_input_vendor_table_exists($pdo, 'cpms_vendors')) return;
    $candidates = array();
    try {
        $st = $pdo->query("SELECT name,representative,phone,business_no,bank_name,account_number,account_holder FROM cpms_vendors WHERE is_active=1 ORDER BY id ASC");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            $aliasKey = cpms_monthly_input_labor_vendor_key(isset($row['name']) ? $row['name'] : '');
            if ($aliasKey === '') continue;
            if (!isset($candidates[$aliasKey])) $candidates[$aliasKey] = array();
            $candidate = array(
                'company_name' => isset($row['name']) ? trim((string)$row['name']) : '',
                'representative_name' => isset($row['representative']) ? trim((string)$row['representative']) : '',
                'contact' => isset($row['phone']) ? trim((string)$row['phone']) : '',
                'business_no' => isset($row['business_no']) ? trim((string)$row['business_no']) : ''
            );
            if ($includeBankInfo) {
                $candidate['bank_name'] = isset($row['bank_name']) ? trim((string)$row['bank_name']) : '';
                $candidate['account_number'] = isset($row['account_number']) ? trim((string)$row['account_number']) : '';
                $candidate['account_holder'] = isset($row['account_holder']) ? trim((string)$row['account_holder']) : '';
            }
            $candidates[$aliasKey][] = $candidate;
        }
    } catch (Exception $e) {
        return;
    }

    foreach ($candidates as $aliasKey => $rows) {
        /* 법인표기 제거 후 후보가 둘 이상이면 다른 업체일 수 있으므로 자동 연결하지 않는다. */
        if (!is_array($rows) || count($rows) !== 1) continue;
        $map['__labor_corp__' . $aliasKey] = $rows[0];
    }
}}

if (!function_exists('cpms_monthly_input_vendor_merge')) {
function cpms_monthly_input_vendor_merge(&$map, $companyName, $representativeName, $contact, $businessNo, $bankName = '', $accountNumber = '', $accountHolder = '', $includeBankInfo = false) {
    $companyName = trim((string)$companyName);
    $key = cpms_monthly_input_vendor_key($companyName);
    if ($key === '') return;

    if (!isset($map[$key])) {
        $map[$key] = array(
            'company_name' => $companyName,
            'representative_name' => '',
            'contact' => '',
            'business_no' => '',
        );
    }

    if ($includeBankInfo) {
        if (!isset($map[$key]['bank_name'])) $map[$key]['bank_name'] = '';
        if (!isset($map[$key]['account_number'])) $map[$key]['account_number'] = '';
        if (!isset($map[$key]['account_holder'])) $map[$key]['account_holder'] = '';
    }

    $representativeName = trim((string)$representativeName);
    $contact = trim((string)$contact);
    $businessNo = trim((string)$businessNo);
    $bankName = trim((string)$bankName);
    $accountNumber = trim((string)$accountNumber);
    $accountHolder = trim((string)$accountHolder);

    /* 먼저 읽은 업체 마스터를 우선하고, 비어 있는 항목만 기존 공사 입력자료로 보충한다. */
    if ($map[$key]['representative_name'] === '' && $representativeName !== '') $map[$key]['representative_name'] = $representativeName;
    if ($map[$key]['contact'] === '' && $contact !== '') $map[$key]['contact'] = $contact;
    if ($map[$key]['business_no'] === '' && $businessNo !== '') $map[$key]['business_no'] = $businessNo;
    if ($includeBankInfo && $map[$key]['bank_name'] === '' && $bankName !== '') $map[$key]['bank_name'] = $bankName;
    if ($includeBankInfo && $map[$key]['account_number'] === '' && $accountNumber !== '') $map[$key]['account_number'] = $accountNumber;
    if ($includeBankInfo && $map[$key]['account_holder'] === '' && $accountHolder !== '') $map[$key]['account_holder'] = $accountHolder;
}}

if (!function_exists('cpms_monthly_input_vendor_load_master')) {
function cpms_monthly_input_vendor_load_master($pdo, &$map, $includeBankInfo = false) {
    if (!cpms_monthly_input_vendor_table_exists($pdo, 'cpms_vendors')) return;
    $candidates = array();
    try {
        $st = $pdo->query("SELECT name,representative,phone,business_no,bank_name,account_number,account_holder FROM cpms_vendors WHERE is_active=1 ORDER BY id ASC");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            $name = isset($row['name']) ? trim((string)$row['name']) : '';
            $key = cpms_monthly_input_vendor_key($name);
            if ($key === '') continue;
            if (!isset($candidates[$key])) $candidates[$key] = array();
            $candidates[$key][] = $row;
        }
    } catch (Exception $e) {
        return;
    }

    foreach ($candidates as $key => $rows) {
        /* 정확한 업체명도 활성 업체가 둘 이상이면 어느 업체인지 임의로 선택하지 않는다. */
        if (!is_array($rows) || count($rows) !== 1) continue;
        $row = $rows[0];
        cpms_monthly_input_vendor_merge(
            $map,
            isset($row['name']) ? $row['name'] : '',
            isset($row['representative']) ? $row['representative'] : '',
            isset($row['phone']) ? $row['phone'] : '',
            isset($row['business_no']) ? $row['business_no'] : '',
            isset($row['bank_name']) ? $row['bank_name'] : '',
            isset($row['account_number']) ? $row['account_number'] : '',
            isset($row['account_holder']) ? $row['account_holder'] : '',
            $includeBankInfo
        );
    }
}}

if (!function_exists('cpms_monthly_input_vendor_order_sql')) {
function cpms_monthly_input_vendor_order_sql($pdo, $table, $dateCandidates) {
    $order = array();
    foreach ((array)$dateCandidates as $column) {
        if (cpms_monthly_input_vendor_column_exists($pdo, $table, $column)) $order[] = '`' . $column . '` DESC';
    }
    if (cpms_monthly_input_vendor_column_exists($pdo, $table, 'id')) $order[] = '`id` DESC';
    return count($order) > 0 ? ' ORDER BY ' . implode(', ', $order) : '';
}}

if (!function_exists('cpms_monthly_input_vendor_load_outsourcing')) {
function cpms_monthly_input_vendor_load_outsourcing($pdo, $projectId, &$map) {
    $table = 'cpms_outsourcing_costs';
    if (!cpms_monthly_input_vendor_table_exists($pdo, $table)) return;
    foreach (array('project_id','company_name','representative_name','contact','business_no') as $column) {
        if (!cpms_monthly_input_vendor_column_exists($pdo, $table, $column)) return;
    }
    $where = array('project_id=:project_id', "COALESCE(company_name,'')<>''");
    if (cpms_monthly_input_vendor_column_exists($pdo, $table, 'is_deleted')) $where[] = '(is_deleted=0 OR is_deleted IS NULL)';
    try {
        $vendorIdSelect = cpms_monthly_input_vendor_column_exists($pdo, $table, 'vendor_id') ? 'vendor_id,' : '0 AS vendor_id,';
        $sql = 'SELECT ' . $vendorIdSelect . 'company_name,representative_name,contact,business_no FROM `' . $table . '` WHERE ' . implode(' AND ', $where)
             . cpms_monthly_input_vendor_order_sql($pdo, $table, array('expense_date','updated_at','created_at'));
        $st = $pdo->prepare($sql);
        $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        $rows = \App\Services\VendorService::applyCurrentVendorRows($pdo, $rows, 'company_name', 'representative_name', 'contact', 'business_no');
        foreach ($rows as $row) {
            cpms_monthly_input_vendor_merge(
                $map,
                isset($row['company_name']) ? $row['company_name'] : '',
                isset($row['representative_name']) ? $row['representative_name'] : '',
                isset($row['contact']) ? $row['contact'] : '',
                isset($row['business_no']) ? $row['business_no'] : '',
                '',
                '',
                '',
                false
            );
        }
    } catch (Exception $e) {
    }
}}

if (!function_exists('cpms_monthly_input_vendor_load_item_table')) {
function cpms_monthly_input_vendor_load_item_table($pdo, $projectId, $table, &$map) {
    if (!cpms_monthly_input_vendor_table_exists($pdo, $table)) return;
    foreach (array('project_id','vendor_name','representative','phone','biz_no') as $column) {
        if (!cpms_monthly_input_vendor_column_exists($pdo, $table, $column)) return;
    }
    $where = array('project_id=:project_id', "COALESCE(vendor_name,'')<>''");
    if (cpms_monthly_input_vendor_column_exists($pdo, $table, 'is_deleted')) $where[] = '(is_deleted=0 OR is_deleted IS NULL)';
    try {
        $vendorIdSelect = cpms_monthly_input_vendor_column_exists($pdo, $table, 'vendor_id') ? 'vendor_id,' : '0 AS vendor_id,';
        $sql = 'SELECT ' . $vendorIdSelect . 'vendor_name,representative,phone,biz_no FROM `' . $table . '` WHERE ' . implode(' AND ', $where)
             . cpms_monthly_input_vendor_order_sql($pdo, $table, array('updated_at','created_at'));
        $st = $pdo->prepare($sql);
        $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        $rows = \App\Services\VendorService::applyCurrentVendorRows($pdo, $rows, 'vendor_name', 'representative', 'phone', 'biz_no');
        foreach ($rows as $row) {
            cpms_monthly_input_vendor_merge(
                $map,
                isset($row['vendor_name']) ? $row['vendor_name'] : '',
                isset($row['representative']) ? $row['representative'] : '',
                isset($row['phone']) ? $row['phone'] : '',
                isset($row['biz_no']) ? $row['biz_no'] : '',
                '',
                '',
                '',
                false
            );
        }
    } catch (Exception $e) {
    }
}}

if (!function_exists('cpms_monthly_input_vendor_info_map')) {
function cpms_monthly_input_vendor_info_map($pdo, $projectId, $includeBankInfo = false) {
    $map = array();
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0) return $map;
    \App\Services\VendorService::bootstrap($pdo, true);

    /* 통합 업체 마스터가 기준이며 기존 거래자료는 마스터의 빈 항목만 보충한다. */
    $includeBankInfo = (bool)$includeBankInfo;
    cpms_monthly_input_vendor_load_master($pdo, $map, $includeBankInfo);
    cpms_monthly_input_vendor_load_outsourcing($pdo, $projectId, $map);
    cpms_monthly_input_vendor_load_item_table($pdo, $projectId, 'cpms_equipment_items', $map);
    cpms_monthly_input_vendor_load_item_table($pdo, $projectId, 'cpms_material_items', $map);
    cpms_monthly_input_vendor_load_labor_aliases($pdo, $map, $includeBankInfo);

    return $map;
}}
