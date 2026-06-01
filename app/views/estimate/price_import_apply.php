<?php
/**
 * 과거 단가 업로드 적용 저장
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/helpers.php';

use App\Core\Db;

cpms_estimate_require_access(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}

$token = isset($_POST['token']) ? (string)$_POST['token'] : '';
if ($token === '' || !isset($_SESSION['estimate_price_import'][$token]) || !is_array($_SESSION['estimate_price_import'][$token])) {
    flash_set('error', '미리보기 데이터가 만료되었습니다.');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}

$pack = $_SESSION['estimate_price_import'][$token];
$rows = isset($pack['rows']) && is_array($pack['rows']) ? $pack['rows'] : array();
$categories = isset($pack['categories']) && is_array($pack['categories']) ? $pack['categories'] : array();
$sourceName = isset($pack['source_name']) ? (string)$pack['source_name'] : '';

$pdo = Db::pdo();
if (!$pdo || !cpms_estimate_tables_ready($pdo)) {
    flash_set('error', '견적관리 DB 설정이 필요합니다.');
    header('Location: ?r=estimate_home&tab=search');
    exit;
}

$user = cpms_estimate_user_meta();

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO cpms_estimate_price_history
        (project_name, sub_project_name, work_type, item_name, spec, unit, client, section_name, contractor, price_type, source_type, source_name, contract_amount, material_unit_price, labor_unit_price, expense_unit_price, unit_price, contract_date, bid_result, reflect_yn, created_by, created_by_name, created_by_email, created_at, remark)
        VALUES
        (:project_name, :sub_project_name, :work_type, :item_name, :spec, :unit, :client, :section_name, :contractor, :price_type, 'excel', :source_name, :contract_amount, :material_unit_price, :labor_unit_price, :expense_unit_price, :unit_price, :contract_date, :bid_result, 1, :created_by, :created_by_name, :created_by_email, :created_at, :remark)");

    $categorySt = $pdo->prepare("INSERT INTO cpms_estimate_categories
        (category_code, category_name, item_code, parent_name, item_name, item_note, sort_order, source_name, created_at)
        VALUES
        (:category_code, :category_name, :item_code, :parent_name, :item_name, :item_note, :sort_order, :source_name, :created_at)
        ON DUPLICATE KEY UPDATE item_note=VALUES(item_note), sort_order=VALUES(sort_order), source_name=VALUES(source_name)");

    $categoryCount = 0;
    foreach ($categories as $cat) {
        if (!is_array($cat)) continue;
        $categoryName = isset($cat['category_name']) ? trim((string)$cat['category_name']) : '';
        $itemName = isset($cat['item_name']) ? trim((string)$cat['item_name']) : '';
        if ($categoryName === '' || $itemName === '') continue;
        $categorySt->bindValue(':category_code', isset($cat['category_code']) ? (string)$cat['category_code'] : '');
        $categorySt->bindValue(':category_name', $categoryName);
        $categorySt->bindValue(':item_code', isset($cat['item_code']) ? (string)$cat['item_code'] : '');
        $categorySt->bindValue(':parent_name', isset($cat['parent_name']) ? (string)$cat['parent_name'] : '');
        $categorySt->bindValue(':item_name', $itemName);
        $categorySt->bindValue(':item_note', isset($cat['item_note']) ? (string)$cat['item_note'] : '');
        $categorySt->bindValue(':sort_order', isset($cat['sort_order']) ? (int)$cat['sort_order'] : 0, PDO::PARAM_INT);
        $categorySt->bindValue(':source_name', isset($cat['source_name']) ? (string)$cat['source_name'] : $sourceName);
        $categorySt->bindValue(':created_at', date('Y-m-d H:i:s'));
        $categorySt->execute();
        $categoryCount++;
    }

    $count = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $itemName = isset($row['item_name']) ? trim((string)$row['item_name']) : '';
        $unit = isset($row['unit']) ? trim((string)$row['unit']) : '';
        $price = isset($row['unit_price']) ? (float)$row['unit_price'] : 0.0;
        if ($itemName === '' || $unit === '' || $price <= 0) continue;

        $st->bindValue(':project_name', isset($row['project_name']) ? (string)$row['project_name'] : '');
        $st->bindValue(':sub_project_name', isset($row['sub_project_name']) ? (string)$row['sub_project_name'] : '');
        $st->bindValue(':work_type', isset($row['work_type']) ? (string)$row['work_type'] : '');
        $st->bindValue(':item_name', $itemName);
        $st->bindValue(':spec', isset($row['spec']) ? (string)$row['spec'] : '');
        $st->bindValue(':unit', $unit);
        $st->bindValue(':client', isset($row['client']) ? (string)$row['client'] : '');
        $st->bindValue(':section_name', isset($row['section_name']) ? (string)$row['section_name'] : '');
        $st->bindValue(':contractor', isset($row['contractor']) ? (string)$row['contractor'] : '');
        $st->bindValue(':price_type', isset($row['price_type']) ? (string)$row['price_type'] : 'contract');
        $st->bindValue(':source_name', $sourceName);
        if (isset($row['contract_amount']) && $row['contract_amount'] !== null) $st->bindValue(':contract_amount', $row['contract_amount']);
        else $st->bindValue(':contract_amount', null, PDO::PARAM_NULL);
        if (isset($row['material_unit_price']) && $row['material_unit_price'] !== null) $st->bindValue(':material_unit_price', $row['material_unit_price']);
        else $st->bindValue(':material_unit_price', null, PDO::PARAM_NULL);
        if (isset($row['labor_unit_price']) && $row['labor_unit_price'] !== null) $st->bindValue(':labor_unit_price', $row['labor_unit_price']);
        else $st->bindValue(':labor_unit_price', null, PDO::PARAM_NULL);
        if (isset($row['expense_unit_price']) && $row['expense_unit_price'] !== null) $st->bindValue(':expense_unit_price', $row['expense_unit_price']);
        else $st->bindValue(':expense_unit_price', null, PDO::PARAM_NULL);
        $st->bindValue(':unit_price', $price);
        if (isset($row['contract_date']) && $row['contract_date']) $st->bindValue(':contract_date', $row['contract_date']);
        else $st->bindValue(':contract_date', null, PDO::PARAM_NULL);
        $st->bindValue(':bid_result', isset($row['bid_result']) ? (string)$row['bid_result'] : '');
        $st->bindValue(':created_by', (int)$user['id'], PDO::PARAM_INT);
        $st->bindValue(':created_by_name', $user['name']);
        $st->bindValue(':created_by_email', $user['email']);
        $st->bindValue(':created_at', date('Y-m-d H:i:s'));
        $st->bindValue(':remark', isset($row['remark']) ? (string)$row['remark'] : '');
        $st->execute();
        $count++;
    }

    $pdo->commit();
    unset($_SESSION['estimate_price_import'][$token]);
    flash_set('success', '과거 단가가 저장되었습니다. (저장된 행: ' . $count . ', 분류항목: ' . $categoryCount . ')');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '과거 단가 저장 실패: ' . $e->getMessage());
}

header('Location: ?r=estimate_home&tab=search');
exit;
