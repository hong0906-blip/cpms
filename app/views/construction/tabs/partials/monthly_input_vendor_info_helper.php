<?php
/**
 * 파일: C:\www\cpms\app\views\construction\tabs\partials\monthly_input_vendor_info_helper.php
 * 공사 > 투입비 상세 표의 업체별 거래처 정보
 * - 업체명별 대표자명 / 전화번호 / 사업자등록번호 조회
 * - 외주비, 자재구입비, 장비비에서 입력한 거래처 정보를 함께 사용
 * - PHP 5.6 호환
 */

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

if (!function_exists('cpms_monthly_input_vendor_merge')) {
function cpms_monthly_input_vendor_merge(&$map, $companyName, $representativeName, $contact, $businessNo) {
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

    $representativeName = trim((string)$representativeName);
    $contact = trim((string)$contact);
    $businessNo = trim((string)$businessNo);

    /* 먼저 읽은 최신 자료를 우선하고, 비어 있는 항목만 다른 공사 입력자료로 보충한다. */
    if ($map[$key]['representative_name'] === '' && $representativeName !== '') $map[$key]['representative_name'] = $representativeName;
    if ($map[$key]['contact'] === '' && $contact !== '') $map[$key]['contact'] = $contact;
    if ($map[$key]['business_no'] === '' && $businessNo !== '') $map[$key]['business_no'] = $businessNo;
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
        $sql = 'SELECT company_name,representative_name,contact,business_no FROM `' . $table . '` WHERE ' . implode(' AND ', $where)
             . cpms_monthly_input_vendor_order_sql($pdo, $table, array('expense_date','updated_at','created_at'));
        $st = $pdo->prepare($sql);
        $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            cpms_monthly_input_vendor_merge(
                $map,
                isset($row['company_name']) ? $row['company_name'] : '',
                isset($row['representative_name']) ? $row['representative_name'] : '',
                isset($row['contact']) ? $row['contact'] : '',
                isset($row['business_no']) ? $row['business_no'] : ''
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
        $sql = 'SELECT vendor_name,representative,phone,biz_no FROM `' . $table . '` WHERE ' . implode(' AND ', $where)
             . cpms_monthly_input_vendor_order_sql($pdo, $table, array('updated_at','created_at'));
        $st = $pdo->prepare($sql);
        $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            cpms_monthly_input_vendor_merge(
                $map,
                isset($row['vendor_name']) ? $row['vendor_name'] : '',
                isset($row['representative']) ? $row['representative'] : '',
                isset($row['phone']) ? $row['phone'] : '',
                isset($row['biz_no']) ? $row['biz_no'] : ''
            );
        }
    } catch (Exception $e) {
    }
}}

if (!function_exists('cpms_monthly_input_vendor_info_map')) {
function cpms_monthly_input_vendor_info_map($pdo, $projectId) {
    $map = array();
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0) return $map;

    /* 해당 업체를 직접 입력하는 화면 순서대로 최신 정보를 우선 사용한다. */
    cpms_monthly_input_vendor_load_outsourcing($pdo, $projectId, $map);
    cpms_monthly_input_vendor_load_item_table($pdo, $projectId, 'cpms_equipment_items', $map);
    cpms_monthly_input_vendor_load_item_table($pdo, $projectId, 'cpms_material_items', $map);

    return $map;
}}
