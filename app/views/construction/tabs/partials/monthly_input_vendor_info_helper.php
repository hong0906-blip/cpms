<?php
/**
 * 파일: C:\www\cpms\app\views\construction\tabs\partials\monthly_input_vendor_info_helper.php
 * 공사 > 투입비 상세 상단 거래처 정보
 * - 대표자명 / 전화번호 / 사업자등록번호
 * - 모바일에서는 화면에서 숨김
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

if (!function_exists('cpms_monthly_input_vendor_info')) {
function cpms_monthly_input_vendor_info($pdo, $projectId) {
    $empty = array(
        'representative_name' => '',
        'contact' => '',
        'business_no' => '',
    );
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0) return $empty;
    if (!cpms_monthly_input_vendor_table_exists($pdo, 'cpms_outsourcing_costs')) return $empty;

    foreach (array('project_id', 'representative_name', 'contact', 'business_no', 'id') as $column) {
        if (!cpms_monthly_input_vendor_column_exists($pdo, 'cpms_outsourcing_costs', $column)) return $empty;
    }

    $where = array('project_id = :project_id');
    if (cpms_monthly_input_vendor_column_exists($pdo, 'cpms_outsourcing_costs', 'is_deleted')) {
        $where[] = '(is_deleted = 0 OR is_deleted IS NULL)';
    }
    $where[] = "(COALESCE(representative_name, '') <> '' OR COALESCE(contact, '') <> '' OR COALESCE(business_no, '') <> '')";

    $order = array();
    if (cpms_monthly_input_vendor_column_exists($pdo, 'cpms_outsourcing_costs', 'expense_date')) $order[] = 'expense_date DESC';
    $order[] = 'id DESC';

    try {
        $sql = 'SELECT representative_name, contact, business_no'
             . ' FROM cpms_outsourcing_costs'
             . ' WHERE ' . implode(' AND ', $where)
             . ' ORDER BY ' . implode(', ', $order)
             . ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $empty;
        return array(
            'representative_name' => isset($row['representative_name']) ? trim((string)$row['representative_name']) : '',
            'contact' => isset($row['contact']) ? trim((string)$row['contact']) : '',
            'business_no' => isset($row['business_no']) ? trim((string)$row['business_no']) : '',
        );
    } catch (Exception $e) {
        return $empty;
    }
}}

if (!function_exists('cpms_monthly_input_vendor_info_html')) {
function cpms_monthly_input_vendor_info_html($info) {
    $info = is_array($info) ? $info : array();
    $items = array(
        '대표자명' => isset($info['representative_name']) ? trim((string)$info['representative_name']) : '',
        '전화번호' => isset($info['contact']) ? trim((string)$info['contact']) : '',
        '사업자등록번호' => isset($info['business_no']) ? trim((string)$info['business_no']) : '',
    );

    $html = '<div class="cpms-monthly-input-vendor-info" aria-label="거래처 정보">';
    foreach ($items as $label => $value) {
        if ($value === '') $value = '-';
        $safeLabel = function_exists('h') ? h($label) : htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeValue = function_exists('h') ? h($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $html .= '<div class="cpms-monthly-input-vendor-item">'
              . '<span class="cpms-monthly-input-vendor-label">' . $safeLabel . '</span>'
              . '<span class="cpms-monthly-input-vendor-value" title="' . $safeValue . '">' . $safeValue . '</span>'
              . '</div>';
    }
    $html .= '</div>';
    return $html;
}}
