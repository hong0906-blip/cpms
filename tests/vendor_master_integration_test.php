<?php
/**
 * Integrated vendor master regression guards.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_vendor_master_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$service = file_get_contents($root . '/app/services/VendorService.php');
$admin = file_get_contents($root . '/app/views/admin/vendors.php');
$construction = file_get_contents($root . '/app/views/construction/tabs/vendors.php');
$materialSearch = file_get_contents($root . '/app/views/construction/material_vendor_search.php');
$equipmentSearch = file_get_contents($root . '/app/views/construction/equipment_vendor_search.php');
$materialView = file_get_contents($root . '/app/views/construction/tabs/materials.php');
$equipmentView = file_get_contents($root . '/app/views/construction/tabs/equipment.php');
$outsourcingView = file_get_contents($root . '/app/views/construction/tabs/outsourcing.php');
$safetyView = file_get_contents($root . '/app/views/safety/index.php');
$vendorImport = file_get_contents($root . '/app/views/admin/vendor_import.php');
$vendorDelete = file_get_contents($root . '/app/views/admin/vendor_delete.php');
$vendorSave = file_get_contents($root . '/app/views/vendor/save.php');
$materialSave = file_get_contents($root . '/app/views/construction/material_item_save.php');
$materialUpdate = file_get_contents($root . '/app/views/construction/material_usage_update.php');
$equipmentSave = file_get_contents($root . '/app/views/construction/equipment_item_save.php');
$equipmentExcelSave = file_get_contents($root . '/app/views/construction/equipment_excel_save.php');
$outsourcingSave = file_get_contents($root . '/app/views/construction/outsourcing_cost_save.php');
$safetySave = file_get_contents($root . '/app/views/safety/safety_cost_save.php');

cpms_vendor_master_guard(
    'vendor master has stable internal id and normalized unique business number',
    strpos($service, 'vendor_uid VARCHAR(32)') !== false
        && strpos($service, "'vendor_' . str_pad") !== false
        && strpos($service, 'UNIQUE KEY uk_vendor_business_no (business_no_key)') !== false
);
cpms_vendor_master_guard(
    'legacy sources are connected by vendor_id without rewriting transaction values',
    strpos($service, 'ADD COLUMN vendor_id INT UNSIGNED NULL') !== false
        && strpos($service, 'SET vendor_id=:vendor_id WHERE id=:id') !== false
        && strpos($service, 'UPDATE cpms_material_usage SET vendor_name') === false
        && strpos($service, 'UPDATE cpms_equipment_usage SET vendor_name') === false
);
cpms_vendor_master_guard(
    'similar names are not normalized or automatically merged',
    strpos($service, 'findExactNameRows') !== false
        && strpos($service, 'prepareLegacyAmbiguousNames') !== false
        && strpos($service, "str_replace(array('(주)'") === false
);
cpms_vendor_master_guard(
    'management exposes all requested fields and legacy confirmation',
    strpos($admin, '사업자등록번호') !== false
        && strpos($admin, '계좌번호') !== false
        && strpos($admin, '예금주') !== false
        && strpos($admin, '기존 업체 연결 확인') !== false
);
cpms_vendor_master_guard(
    'construction registration requires all eight fields while bank details stay hidden from its list',
    strpos($construction, 'required name="business_no"') !== false
        && strpos($construction, 'required name="name"') !== false
        && strpos($construction, 'required name="description"') !== false
        && strpos($construction, 'required name="representative"') !== false
        && strpos($construction, 'required name="phone"') !== false
        && strpos($construction, 'required name="bank_name"') !== false
        && strpos($construction, 'required name="account_number"') !== false
        && strpos($construction, 'required name="account_holder"') !== false
        && strpos($vendorSave, "'account_holder' => '예금주'") !== false
        && strpos($vendorSave, 'count($missingFields) > 0') !== false
        && substr_count($construction, '<th class="p-3 text-left">') === 5
);
cpms_vendor_master_guard(
    'both legacy autocomplete endpoints read the integrated master',
    strpos($materialSearch, 'VendorService::search') !== false
        && strpos($equipmentSearch, "require_once __DIR__ . '/material_vendor_search.php'") !== false
        && strpos($equipmentSearch, "\$vendorSearchPresetType = 'equipment'") !== false
);
cpms_vendor_master_guard(
    'all four autocomplete consumers submit vendor_id and show description',
    strpos($materialView, 'name="vendor_id"') !== false && strpos($materialView, "row.description ? ' · '") !== false
        && strpos($equipmentView, 'name="vendor_id"') !== false && strpos($equipmentView, "row.description ? ' · '") !== false
        && strpos($outsourcingView, 'name="vendor_id"') !== false && strpos($outsourcingView, "items[i].description?' · '") !== false
        && strpos($safetyView, 'name="vendor_id"') !== false && strpos($safetyView, "row.description ? ' · '") !== false
);
cpms_vendor_master_guard(
    'cost entry vendor names are readonly and must use an autocomplete selection',
    preg_match('/name="vendor_name"[^>]*readonly/', $materialView) === 1
        && preg_match('/name="vendor_name"[^>]*readonly/', $equipmentView) === 1
        && preg_match('/readonly name="company_name"/', $outsourcingView) === 1
        && strpos($safetyView, 'placeholder="자동검색에서 업체 선택" required readonly') !== false
        && strpos($materialSave, 'VendorService::selectedVendorId') !== false
        && strpos($materialUpdate, 'VendorService::selectedVendorId') !== false
        && strpos($equipmentSave, 'VendorService::selectedVendorId') !== false
        && strpos($outsourcingSave, 'VendorService::selectedVendorId') !== false
        && strpos($safetySave, 'VendorService::selectedVendorId') !== false
);
cpms_vendor_master_guard(
    'transaction amount and remark remain editable and are not overwritten by vendor selection',
    strpos($materialView, "formEl.elements['base_rate'].value=p.base_rate") === false
        && strpos($materialView, "formEl.elements['remark'].value=p.remark") === false
        && strpos($materialView, "formEl.elements['base_rate'].readOnly=false") !== false
        && strpos($equipmentView, "formEl.elements['base_rate'].value = p.base_rate") === false
        && strpos($equipmentView, "formEl.elements['remark'].value = p.remark") === false
        && strpos($equipmentView, "formEl.elements['base_rate'].readOnly = false") !== false
        && strpos($equipmentView, 'name="remark" class="px-3 py-3 border rounded-xl bg-white"') !== false
        && strpos($outsourcingView, 'name="amount"') !== false
        && strpos($outsourcingView, 'name="memo"') !== false
        && strpos($safetyView, 'name="amount"') !== false
        && strpos($safetyView, 'name="remark"') !== false
);
cpms_vendor_master_guard(
    'cost Excel and bulk imports only match existing vendors and never auto-create them',
    strpos($materialSave, 'VendorService::matchExistingVendorId') !== false
        && strpos($equipmentExcelSave, 'VendorService::matchExistingVendorId') !== false
        && strpos($materialSave, 'VendorService::resolveOrCreate') === false
        && strpos($equipmentExcelSave, 'VendorService::resolveOrCreate') === false
);
cpms_vendor_master_guard(
    'bank details are never returned from autocomplete',
    strpos($service, "'bank_name' =>") === false || strpos(substr($service, strpos($service, 'public static function search'), strpos($service, 'public static function listVendors') - strpos($service, 'public static function search')), "'bank_name' =>") === false
);
cpms_vendor_master_guard(
    'new code avoids PHP 7 null coalescing syntax',
    strpos($service, '??') === false
        && strpos($admin, '??') === false
        && strpos($construction, '??') === false
);
cpms_vendor_master_guard(
    'legacy cost categories are not imported as vendor descriptions',
    strpos($service, "'description' => self::clean(isset(\$data['description']) ? \$data['description'] : ''") !== false
        && strpos($service, "'description'=>'category'") === false
        && strpos($service, 'cleanupLegacyDescriptions') !== false
);
cpms_vendor_master_guard(
    'management vendor excel upload uses existing PHP 5.6 readers',
    strpos($admin, 'admin/vendor_import') !== false
        && strpos($vendorImport, 'SimpleXlsxReader::readFirstSheet') !== false
        && strpos($vendorImport, 'SimpleXlsReader::readFirstSheet') !== false
        && strpos($vendorImport, 'readWorksheets') !== false
        && strpos($vendorImport, "'거래처명'=>'name'") !== false
        && strpos($service, 'importVendorRows') !== false
);
cpms_vendor_master_guard(
    'vendor delete is soft and does not delete transactions',
    strpos($admin, 'admin/vendor_delete') !== false
        && strpos($vendorDelete, 'softDeleteVendor') !== false
        && strpos($service, 'SET is_active=0,business_no_key=NULL') !== false
        && strpos($service, 'DELETE FROM cpms_vendors') === false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " integrated vendor master guards\n";
