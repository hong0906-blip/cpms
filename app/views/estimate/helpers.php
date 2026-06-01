<?php
/**
 * 견적관리 공통 함수
 * - PHP 5.6 호환
 */

use App\Core\Auth;

if (!function_exists('cpms_estimate_json_exit')) {
function cpms_estimate_json_exit($ok, $message, $data, $status)
{
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    if (!is_array($data)) $data = array();
    $data['ok'] = $ok ? 1 : 0;
    $data['message'] = (string)$message;
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}}

if (!function_exists('cpms_estimate_require_access')) {
function cpms_estimate_require_access($json)
{
    if (!Auth::check()) {
        if ($json) cpms_estimate_json_exit(false, '로그인이 필요합니다.', array(), 401);
        header('Location: ?r=login');
        exit;
    }
    if (!Auth::canAccessEstimate()) {
        if ($json) cpms_estimate_json_exit(false, '접근 권한이 없습니다.', array(), 403);
        http_response_code(403);
        echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다.</div>';
        exit;
    }
}}

if (!function_exists('cpms_estimate_required_tables')) {
function cpms_estimate_required_tables()
{
    return array(
        'cpms_estimate_price_history',
        'cpms_estimates',
        'cpms_estimate_items',
        'cpms_estimate_bid_results',
        'cpms_estimate_bid_result_items',
        'cpms_estimate_categories',
    );
}}

if (!function_exists('cpms_estimate_table_exists')) {
function cpms_estimate_table_exists($pdo, $table)
{
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $st->bindValue(':table_name', (string)$table);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_estimate_column_exists')) {
function cpms_estimate_column_exists($pdo, $table, $column)
{
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
        $st->bindValue(':table_name', (string)$table);
        $st->bindValue(':column_name', (string)$column);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_estimate_tables_ready')) {
function cpms_estimate_tables_ready($pdo)
{
    if (!$pdo) return false;
    foreach (cpms_estimate_required_tables() as $table) {
        if (!cpms_estimate_table_exists($pdo, $table)) return false;
    }
    $priceColumns = array('project_name', 'sub_project_name', 'contract_amount', 'material_unit_price', 'labor_unit_price', 'expense_unit_price');
    foreach ($priceColumns as $column) {
        if (!cpms_estimate_column_exists($pdo, 'cpms_estimate_price_history', $column)) return false;
    }
    return true;
}}

if (!function_exists('cpms_estimate_parse_number')) {
function cpms_estimate_parse_number($value)
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $value = str_replace(array(',', ' ', "\t", "\r", "\n"), '', $value);
    $value = preg_replace('/[^0-9\.\-]/', '', $value);
    if ($value === '' || $value === '-' || $value === '.' || $value === '-.') return null;
    if (!is_numeric($value)) return null;
    return (float)$value;
}}

if (!function_exists('cpms_estimate_format_money')) {
function cpms_estimate_format_money($value)
{
    if ($value === null || $value === '') return '';
    if (!is_numeric((string)$value)) return h((string)$value);
    return number_format(round((float)$value), 0);
}}

if (!function_exists('cpms_estimate_format_qty')) {
function cpms_estimate_format_qty($value)
{
    if ($value === null || $value === '') return '';
    if (!is_numeric((string)$value)) return h((string)$value);
    $s = number_format((float)$value, 4, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}}

if (!function_exists('cpms_estimate_post_string')) {
function cpms_estimate_post_string($key, $default)
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : (string)$default;
}}

if (!function_exists('cpms_estimate_request_string')) {
function cpms_estimate_request_string($key, $default)
{
    return isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : (string)$default;
}}

if (!function_exists('cpms_estimate_user_meta')) {
function cpms_estimate_user_meta()
{
    $user = Auth::user();
    return array(
        'id' => (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0,
        'name' => (is_array($user) && isset($user['name'])) ? (string)$user['name'] : '',
        'email' => (is_array($user) && isset($user['email'])) ? (string)$user['email'] : '',
    );
}}

if (!function_exists('cpms_estimate_category_options')) {
function cpms_estimate_category_options($pdo, $categoryName)
{
    $rows = array();
    if (!$pdo || !cpms_estimate_table_exists($pdo, 'cpms_estimate_categories')) return $rows;
    try {
        $st = $pdo->prepare("SELECT item_name, parent_name, item_note FROM cpms_estimate_categories WHERE category_name = :category_name ORDER BY sort_order ASC, id ASC");
        $st->bindValue(':category_name', (string)$categoryName);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_estimate_confidence')) {
function cpms_estimate_confidence($count)
{
    $count = (int)$count;
    if ($count >= 5) return '높음';
    if ($count >= 2) return '보통';
    if ($count === 1) return '낮음';
    return '직접 입력 필요';
}}

if (!function_exists('cpms_estimate_stats')) {
function cpms_estimate_stats($values)
{
    $clean = array();
    $sum = 0.0;
    foreach ((array)$values as $value) {
        $num = (float)$value;
        if ($num < 0) $num = 0.0;
        $clean[] = $num;
        $sum += $num;
    }
    $count = count($clean);
    if ($count === 0) {
        return array(
            'min' => null,
            'median' => null,
            'avg' => null,
            'max' => null,
        );
    }
    sort($clean, SORT_NUMERIC);
    $middle = (int)floor($count / 2);
    if ($count % 2 === 1) {
        $median = $clean[$middle];
    } else {
        $median = ($clean[$middle - 1] + $clean[$middle]) / 2;
    }
    return array(
        'min' => $clean[0],
        'median' => $median,
        'avg' => ($sum / $count),
        'max' => $clean[$count - 1],
    );
}}

if (!function_exists('cpms_estimate_match_condition')) {
function cpms_estimate_match_condition($params, $level)
{
    $workType = isset($params['work_type']) ? trim((string)$params['work_type']) : '';
    $itemName = isset($params['item_name']) ? trim((string)$params['item_name']) : '';
    $spec = isset($params['spec']) ? trim((string)$params['spec']) : '';
    $unit = isset($params['unit']) ? trim((string)$params['unit']) : '';

    if ($itemName === '' || $unit === '') return null;

    $where = array("reflect_yn = 1", "unit_price > 0", "TRIM(item_name) = :item_name", "TRIM(unit) = :unit");
    $bind = array(':item_name' => $itemName, ':unit' => $unit);
    $label = '3순위: 품명 + 단위';

    if ((int)$level === 1) {
        if ($workType === '' || $spec === '') return null;
        $where[] = "TRIM(work_type) = :work_type";
        $where[] = "TRIM(spec) = :spec";
        $bind[':work_type'] = $workType;
        $bind[':spec'] = $spec;
        $label = '1순위: 공종 + 품명 + 규격 + 단위';
    } else if ((int)$level === 2) {
        if ($workType === '') return null;
        $where[] = "TRIM(work_type) = :work_type";
        $bind[':work_type'] = $workType;
        $label = '2순위: 공종 + 품명 + 단위';
    }

    return array('where' => $where, 'bind' => $bind, 'label' => $label);
}}

if (!function_exists('cpms_estimate_price_rows_by_level')) {
function cpms_estimate_price_rows_by_level($pdo, $params, $level, $limit)
{
    $cond = cpms_estimate_match_condition($params, $level);
    if ($cond === null) return array('rows' => array(), 'label' => '');

    $sql = "SELECT id, project_name, sub_project_name, work_type, item_name, spec, unit, client, section_name, contractor, price_type,
                   material_unit_price, labor_unit_price, expense_unit_price, unit_price, contract_date, source_name, bid_result, remark, created_at
              FROM cpms_estimate_price_history
             WHERE " . implode(' AND ', $cond['where']) . "
             ORDER BY CASE WHEN contract_date IS NULL THEN 1 ELSE 0 END, contract_date DESC, id DESC
             LIMIT " . (int)$limit;
    $st = $pdo->prepare($sql);
    foreach ($cond['bind'] as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) $rows = array();
    return array('rows' => $rows, 'label' => $cond['label']);
}}

if (!function_exists('cpms_estimate_recommend')) {
function cpms_estimate_recommend($pdo, $params)
{
    $empty = array(
        'count' => 0,
        'min_price' => null,
        'median_price' => null,
        'avg_price' => null,
        'max_price' => null,
        'recent_contract_price' => null,
        'recommended_price' => null,
        'confidence' => '직접 입력 필요',
        'match_level' => 0,
        'match_label' => '일치 데이터 없음',
        'material_min_price' => null,
        'material_median_price' => null,
        'material_avg_price' => null,
        'material_max_price' => null,
        'recommended_material_price' => null,
        'recent_contract_material_price' => null,
        'labor_min_price' => null,
        'labor_median_price' => null,
        'labor_avg_price' => null,
        'labor_max_price' => null,
        'recommended_labor_price' => null,
        'recent_contract_labor_price' => null,
        'expense_min_price' => null,
        'expense_median_price' => null,
        'expense_avg_price' => null,
        'expense_max_price' => null,
        'recommended_expense_price' => null,
        'recent_contract_expense_price' => null,
        'rows' => array(),
    );

    if (!$pdo || !cpms_estimate_table_exists($pdo, 'cpms_estimate_price_history')) return $empty;

    $matchedRows = array();
    $matchedLabel = '';
    $matchedLevel = 0;
    for ($level = 1; $level <= 3; $level++) {
        $pack = cpms_estimate_price_rows_by_level($pdo, $params, $level, 1000);
        if (isset($pack['rows']) && count($pack['rows']) > 0) {
            $matchedRows = $pack['rows'];
            $matchedLabel = isset($pack['label']) ? (string)$pack['label'] : '';
            $matchedLevel = $level;
            break;
        }
    }

    if (count($matchedRows) === 0) return $empty;

    $prices = array();
    $materialPrices = array();
    $laborPrices = array();
    $expensePrices = array();
    $recentContractPrice = null;
    $recentContractMaterialPrice = null;
    $recentContractLaborPrice = null;
    $recentContractExpensePrice = null;

    foreach ($matchedRows as $row) {
        $price = isset($row['unit_price']) ? (float)$row['unit_price'] : 0.0;
        if ($price <= 0) continue;
        $material = isset($row['material_unit_price']) ? (float)$row['material_unit_price'] : 0.0;
        $labor = isset($row['labor_unit_price']) ? (float)$row['labor_unit_price'] : 0.0;
        $expense = isset($row['expense_unit_price']) ? (float)$row['expense_unit_price'] : 0.0;
        if ($material < 0) $material = 0.0;
        if ($labor < 0) $labor = 0.0;
        if ($expense < 0) $expense = 0.0;

        $prices[] = $price;
        $materialPrices[] = $material;
        $laborPrices[] = $labor;
        $expensePrices[] = $expense;

        if ($recentContractPrice === null && isset($row['price_type']) && (string)$row['price_type'] === 'contract') {
            $recentContractPrice = $price;
            $recentContractMaterialPrice = $material;
            $recentContractLaborPrice = $labor;
            $recentContractExpensePrice = $expense;
        }
    }

    $validCount = count($prices);
    if ($validCount === 0) return $empty;

    $totalStats = cpms_estimate_stats($prices);
    $materialStats = cpms_estimate_stats($materialPrices);
    $laborStats = cpms_estimate_stats($laborPrices);
    $expenseStats = cpms_estimate_stats($expensePrices);

    return array(
        'count' => $validCount,
        'min_price' => $totalStats['min'],
        'median_price' => $totalStats['median'],
        'avg_price' => $totalStats['avg'],
        'max_price' => $totalStats['max'],
        'recent_contract_price' => $recentContractPrice,
        'recommended_price' => $totalStats['median'],
        'confidence' => cpms_estimate_confidence($validCount),
        'match_level' => $matchedLevel,
        'match_label' => $matchedLabel,
        'material_min_price' => $materialStats['min'],
        'material_median_price' => $materialStats['median'],
        'material_avg_price' => $materialStats['avg'],
        'material_max_price' => $materialStats['max'],
        'recommended_material_price' => $materialStats['median'],
        'recent_contract_material_price' => $recentContractMaterialPrice,
        'labor_min_price' => $laborStats['min'],
        'labor_median_price' => $laborStats['median'],
        'labor_avg_price' => $laborStats['avg'],
        'labor_max_price' => $laborStats['max'],
        'recommended_labor_price' => $laborStats['median'],
        'recent_contract_labor_price' => $recentContractLaborPrice,
        'expense_min_price' => $expenseStats['min'],
        'expense_median_price' => $expenseStats['median'],
        'expense_avg_price' => $expenseStats['avg'],
        'expense_max_price' => $expenseStats['max'],
        'recommended_expense_price' => $expenseStats['median'],
        'recent_contract_expense_price' => $recentContractExpensePrice,
        'rows' => array_slice($matchedRows, 0, 30),
    );
}}

if (!function_exists('cpms_estimate_search_history')) {
function cpms_estimate_search_history($pdo, $filters, $limit)
{
    if (!$pdo || !cpms_estimate_table_exists($pdo, 'cpms_estimate_price_history')) return array();

    $where = array('1=1');
    $bind = array();
    $likeFields = array(
        'work_type' => 'work_type',
        'item_name' => 'item_name',
        'spec' => 'spec',
        'unit' => 'unit',
        'client' => 'client',
        'section_name' => 'section_name',
        'contractor' => 'contractor',
    );
    foreach ($likeFields as $key => $col) {
        $v = isset($filters[$key]) ? trim((string)$filters[$key]) : '';
        if ($v !== '') {
            $where[] = $col . ' LIKE :' . $key;
            $bind[':' . $key] = '%' . $v . '%';
        }
    }
    $priceType = isset($filters['price_type']) ? trim((string)$filters['price_type']) : '';
    if ($priceType !== '') {
        $where[] = 'price_type = :price_type';
        $bind[':price_type'] = $priceType;
    }

    $sql = "SELECT *
              FROM cpms_estimate_price_history
             WHERE " . implode(' AND ', $where) . "
             ORDER BY id DESC
             LIMIT " . (int)$limit;
    $st = $pdo->prepare($sql);
    foreach ($bind as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_estimate_get_estimates')) {
function cpms_estimate_get_estimates($pdo, $limit)
{
    if (!$pdo || !cpms_estimate_table_exists($pdo, 'cpms_estimates')) return array();
    $st = $pdo->query("SELECT * FROM cpms_estimates ORDER BY id DESC LIMIT " . (int)$limit);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_estimate_get_estimate')) {
function cpms_estimate_get_estimate($pdo, $estimateId)
{
    if (!$pdo || (int)$estimateId <= 0 || !cpms_estimate_table_exists($pdo, 'cpms_estimates')) return null;
    $st = $pdo->prepare("SELECT * FROM cpms_estimates WHERE id = :id LIMIT 1");
    $st->bindValue(':id', (int)$estimateId, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}}

if (!function_exists('cpms_estimate_get_items')) {
function cpms_estimate_get_items($pdo, $estimateId)
{
    if (!$pdo || (int)$estimateId <= 0 || !cpms_estimate_table_exists($pdo, 'cpms_estimate_items')) return array();
    $st = $pdo->prepare("SELECT * FROM cpms_estimate_items WHERE estimate_id = :id ORDER BY line_no ASC, id ASC");
    $st->bindValue(':id', (int)$estimateId, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_estimate_bid_result_options')) {
function cpms_estimate_bid_result_options()
{
    return array(
        '보류',
        '성공',
        '성공-저가주의',
        '실패-고가추정',
        '실패-조건불리',
        '실패-사유불명',
    );
}}

if (!function_exists('cpms_estimate_price_type_label')) {
function cpms_estimate_price_type_label($value)
{
    $value = (string)$value;
    if ($value === 'contract') return '계약단가';
    if ($value === 'estimate') return '견적단가(실패)';
    if ($value === 'submitted') return '제출단가';
    return $value;
}}

if (!function_exists('cpms_estimate_excel_serial_date')) {
function cpms_estimate_excel_serial_date($value)
{
    $value = trim((string)$value);
    if ($value === '') return null;
    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value)) {
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
    if (preg_match('/^\d{4}\.\d{1,2}\.\d{1,2}$/', $value)) {
        $ts = strtotime(str_replace('.', '-', $value));
        return $ts ? date('Y-m-d', $ts) : null;
    }
    if (is_numeric($value) && (float)$value > 25000) {
        return gmdate('Y-m-d', ((int)$value - 25569) * 86400);
    }
    return null;
}}

if (!function_exists('cpms_estimate_recommendation_brief')) {
function cpms_estimate_recommendation_brief($json)
{
    $data = @json_decode((string)$json, true);
    if (!is_array($data)) return '';
    $parts = array();
    if (isset($data['match_label'])) $parts[] = (string)$data['match_label'];
    if (isset($data['count'])) $parts[] = ((int)$data['count']) . '건';
    if (isset($data['confidence'])) $parts[] = '신뢰도 ' . (string)$data['confidence'];
    if (isset($data['min_price'])) $parts[] = '최저 ' . cpms_estimate_format_money($data['min_price']);
    if (isset($data['median_price'])) $parts[] = '중앙 ' . cpms_estimate_format_money($data['median_price']);
    if (isset($data['avg_price'])) $parts[] = '평균 ' . cpms_estimate_format_money($data['avg_price']);
    if (isset($data['max_price'])) $parts[] = '최고 ' . cpms_estimate_format_money($data['max_price']);
    if (isset($data['recommended_material_price']) || isset($data['recommended_labor_price']) || isset($data['recommended_expense_price'])) {
        $parts[] = '재/노/경 ' . cpms_estimate_format_money(isset($data['recommended_material_price']) ? $data['recommended_material_price'] : null) . ' / ' . cpms_estimate_format_money(isset($data['recommended_labor_price']) ? $data['recommended_labor_price'] : null) . ' / ' . cpms_estimate_format_money(isset($data['recommended_expense_price']) ? $data['recommended_expense_price'] : null);
    }
    return implode(' / ', $parts);
}}
