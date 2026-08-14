<?php
/**
 * CPMS integrated vendor master.
 *
 * Existing transaction/vendor strings are retained as snapshots. Only the
 * nullable vendor_id reference is added to legacy DB rows, and safety-cost
 * JSON records receive the same reference without changing transaction data.
 *
 * PHP 5.6 compatible.
 */

namespace App\Services;

use Exception;
use PDO;

class VendorService
{
    private static $schemaState = array();
    private static $syncState = array();
    private static $vendorCache = array();
    private static $ambiguousNames = array();

    private static function pdoKey($pdo)
    {
        return function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'default';
    }

    public static function normalizeBusinessNo($value)
    {
        return preg_replace('/[^0-9]/', '', trim((string)$value));
    }

    private static function clean($value, $maxLength)
    {
        $value = trim((string)$value);
        if ($maxLength > 0) {
            if (function_exists('mb_substr')) return mb_substr($value, 0, $maxLength, 'UTF-8');
            return substr($value, 0, $maxLength);
        }
        return $value;
    }

    private static function nameKey($value)
    {
        $value = trim((string)$value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function tableExists($pdo, $table)
    {
        try {
            $st = $pdo->prepare('SHOW TABLES LIKE :table_name');
            $st->bindValue(':table_name', (string)$table);
            $st->execute();
            return $st->fetch(PDO::FETCH_NUM) ? true : false;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function columnMap($pdo, $table)
    {
        $columns = array();
        if (!self::tableExists($pdo, $table)) return $columns;
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['Field'])) $columns[(string)$row['Field']] = true;
            }
        } catch (Exception $e) {
            return array();
        }
        return $columns;
    }

    private static function ensureReferenceColumn($pdo, $table)
    {
        if (!self::tableExists($pdo, $table)) return;
        $columns = self::columnMap($pdo, $table);
        if (!isset($columns['vendor_id'])) {
            try {
                $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN vendor_id INT UNSIGNED NULL');
            } catch (Exception $e) {
                error_log('[VendorMaster] vendor_id column ' . $table . ': ' . $e->getMessage());
                return;
            }
        }
        try {
            $st = $pdo->query("SHOW INDEX FROM `" . $table . "` WHERE Key_name = 'idx_vendor_id'");
            if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec('ALTER TABLE `' . $table . '` ADD INDEX idx_vendor_id (vendor_id)');
            }
        } catch (Exception $e) {
            // The reference remains usable even when the optional index cannot be added.
        }
    }

    private static function cleanupLegacyDescriptions($pdo)
    {
        try {
            $sources = array('material_preset','equipment_preset','material_item','equipment_item','outsourcing','safety','material','equipment');
            $sourceMarks = array();
            $params = array();
            foreach ($sources as $index => $source) {
                $mark = ':cleanup_source_' . $index;
                $sourceMarks[] = $mark;
                $params[$mark] = $source;
            }
            $sql = 'UPDATE cpms_vendors SET description=NULL WHERE is_active=1'
                 . ' AND created_source IN (' . implode(',', $sourceMarks) . ')'
                 . " AND COALESCE(updated_by_name,'')='' AND COALESCE(updated_by_email,'')=''"
                 . " AND COALESCE(TRIM(description),'')<>''";
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } catch (Exception $e) {
            error_log('[VendorMaster] legacy description cleanup: ' . $e->getMessage());
        }
    }

    public static function ensureSchema($pdo)
    {
        if (!$pdo) return false;
        $key = self::pdoKey($pdo);
        if (isset(self::$schemaState[$key])) return self::$schemaState[$key];

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_vendors (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vendor_uid VARCHAR(32) NULL,
                business_no VARCHAR(30) NULL,
                business_no_key VARCHAR(30) NULL,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(255) NULL,
                representative VARCHAR(100) NULL,
                phone VARCHAR(50) NULL,
                bank_name VARCHAR(100) NULL,
                account_number VARCHAR(100) NULL,
                account_holder VARCHAR(100) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_source VARCHAR(30) NOT NULL DEFAULT 'manual',
                created_by_name VARCHAR(100) NULL,
                created_by_email VARCHAR(190) NULL,
                created_at DATETIME NOT NULL,
                updated_by_name VARCHAR(100) NULL,
                updated_by_email VARCHAR(190) NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_vendor_uid (vendor_uid),
                UNIQUE KEY uk_vendor_business_no (business_no_key),
                KEY idx_vendor_name (name),
                KEY idx_vendor_active_name (is_active, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::cleanupLegacyDescriptions($pdo);

            foreach (array(
                'cpms_material_vendor_presets',
                'cpms_equipment_vendor_presets',
                'cpms_material_items',
                'cpms_equipment_items',
                'cpms_outsourcing_costs'
            ) as $table) {
                self::ensureReferenceColumn($pdo, $table);
            }
            self::$schemaState[$key] = true;
            return true;
        } catch (Exception $e) {
            error_log('[VendorMaster] schema: ' . $e->getMessage());
            self::$schemaState[$key] = false;
            return false;
        }
    }

    public static function bootstrap($pdo, $syncLegacy)
    {
        $result = array('ok' => false, 'created' => 0, 'linked' => 0, 'unresolved' => 0);
        if (!self::ensureSchema($pdo)) return $result;
        $result['ok'] = true;
        if ($syncLegacy) {
            $sync = self::syncLegacy($pdo);
            foreach (array('created', 'linked', 'unresolved') as $key) {
                if (isset($sync[$key])) $result[$key] = (int)$sync[$key];
            }
        }
        return $result;
    }

    public static function hasVendorReference($pdo, $table)
    {
        $allowed = array('cpms_material_vendor_presets','cpms_equipment_vendor_presets','cpms_material_items','cpms_equipment_items','cpms_outsourcing_costs');
        if (!$pdo || !in_array($table, $allowed, true)) return false;
        $columns = self::columnMap($pdo, $table);
        return isset($columns['vendor_id']);
    }

    private static function findByBusinessKey($pdo, $businessKey, $excludeId)
    {
        if ($businessKey === '') return null;
        $sql = 'SELECT * FROM cpms_vendors WHERE business_no_key = :business_no_key AND is_active = 1';
        if ((int)$excludeId > 0) $sql .= ' AND id <> :exclude_id';
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->bindValue(':business_no_key', $businessKey);
        if ((int)$excludeId > 0) $st->bindValue(':exclude_id', (int)$excludeId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function findExactNameRows($pdo, $name)
    {
        if ($name === '') return array();
        $st = $pdo->prepare('SELECT * FROM cpms_vendors WHERE name = :name AND is_active = 1 ORDER BY id ASC');
        $st->bindValue(':name', $name);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    public static function getById($pdo, $vendorId)
    {
        $vendorId = (int)$vendorId;
        if (!$pdo || $vendorId <= 0 || !self::ensureSchema($pdo)) return null;
        $cacheKey = self::pdoKey($pdo) . ':' . $vendorId;
        if (array_key_exists($cacheKey, self::$vendorCache)) return self::$vendorCache[$cacheKey];
        $st = $pdo->prepare('SELECT * FROM cpms_vendors WHERE id = :id AND is_active = 1 LIMIT 1');
        $st->bindValue(':id', $vendorId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        self::$vendorCache[$cacheKey] = is_array($row) ? $row : null;
        return self::$vendorCache[$cacheKey];
    }

    private static function getByIdIncludingInactive($pdo, $vendorId)
    {
        $vendorId = (int)$vendorId;
        if (!$pdo || $vendorId <= 0 || !self::ensureSchema($pdo)) return null;
        $cacheKey = self::pdoKey($pdo) . ':any:' . $vendorId;
        if (array_key_exists($cacheKey, self::$vendorCache)) return self::$vendorCache[$cacheKey];
        $st = $pdo->prepare('SELECT * FROM cpms_vendors WHERE id = :id LIMIT 1');
        $st->bindValue(':id', $vendorId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        self::$vendorCache[$cacheKey] = is_array($row) ? $row : null;
        return self::$vendorCache[$cacheKey];
    }

    private static function vendorInput($data)
    {
        return array(
            'business_no' => self::clean(isset($data['business_no']) ? $data['business_no'] : (isset($data['biz_no']) ? $data['biz_no'] : ''), 30),
            'name' => self::clean(isset($data['name']) ? $data['name'] : (isset($data['vendor_name']) ? $data['vendor_name'] : (isset($data['company_name']) ? $data['company_name'] : '')), 120),
            'description' => self::clean(isset($data['description']) ? $data['description'] : '', 255),
            'representative' => self::clean(isset($data['representative']) ? $data['representative'] : (isset($data['representative_name']) ? $data['representative_name'] : ''), 100),
            'phone' => self::clean(isset($data['phone']) ? $data['phone'] : (isset($data['contact']) ? $data['contact'] : ''), 50),
            'bank_name' => self::clean(isset($data['bank_name']) ? $data['bank_name'] : '', 100),
            'account_number' => self::clean(isset($data['account_number']) ? $data['account_number'] : '', 100),
            'account_holder' => self::clean(isset($data['account_holder']) ? $data['account_holder'] : '', 100)
        );
    }

    private static function insertVendor($pdo, $input, $source, $actor)
    {
        $now = date('Y-m-d H:i:s');
        $businessKey = self::normalizeBusinessNo($input['business_no']);
        $st = $pdo->prepare("INSERT INTO cpms_vendors
            (vendor_uid,business_no,business_no_key,name,description,representative,phone,bank_name,account_number,account_holder,is_active,created_source,created_by_name,created_by_email,created_at,updated_by_name,updated_by_email,updated_at)
            VALUES
            (NULL,:business_no,:business_no_key,:name,:description,:representative,:phone,:bank_name,:account_number,:account_holder,1,:created_source,:created_by_name,:created_by_email,:created_at,:updated_by_name,:updated_by_email,:updated_at)");
        $st->bindValue(':business_no', $input['business_no'] !== '' ? $input['business_no'] : null, $input['business_no'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':business_no_key', $businessKey !== '' ? $businessKey : null, $businessKey !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':name', $input['name']);
        $st->bindValue(':description', $input['description'] !== '' ? $input['description'] : null, $input['description'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':representative', $input['representative'] !== '' ? $input['representative'] : null, $input['representative'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':phone', $input['phone'] !== '' ? $input['phone'] : null, $input['phone'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':bank_name', $input['bank_name'] !== '' ? $input['bank_name'] : null, $input['bank_name'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':account_number', $input['account_number'] !== '' ? $input['account_number'] : null, $input['account_number'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':account_holder', $input['account_holder'] !== '' ? $input['account_holder'] : null, $input['account_holder'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':created_source', self::clean($source, 30));
        $st->bindValue(':created_by_name', isset($actor['name']) && trim((string)$actor['name']) !== '' ? (string)$actor['name'] : null);
        $st->bindValue(':created_by_email', isset($actor['email']) && trim((string)$actor['email']) !== '' ? (string)$actor['email'] : null);
        $st->bindValue(':created_at', $now);
        $st->bindValue(':updated_by_name', isset($actor['name']) && trim((string)$actor['name']) !== '' ? (string)$actor['name'] : null);
        $st->bindValue(':updated_by_email', isset($actor['email']) && trim((string)$actor['email']) !== '' ? (string)$actor['email'] : null);
        $st->bindValue(':updated_at', $now);
        $st->execute();
        $id = (int)$pdo->lastInsertId();
        if ($id > 0) {
            $uid = 'vendor_' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
            $up = $pdo->prepare('UPDATE cpms_vendors SET vendor_uid = :uid WHERE id = :id AND vendor_uid IS NULL');
            $up->execute(array(':uid' => $uid, ':id' => $id));
        }
        self::$vendorCache = array();
        return $id;
    }

    private static function fillMissing($pdo, $vendorId, $input)
    {
        $vendor = self::getById($pdo, $vendorId);
        if (!is_array($vendor)) return;
        $set = array();
        $params = array(':id' => (int)$vendorId, ':updated_at' => date('Y-m-d H:i:s'));
        foreach (array('business_no', 'description', 'representative', 'phone') as $field) {
            $current = isset($vendor[$field]) ? trim((string)$vendor[$field]) : '';
            $incoming = isset($input[$field]) ? trim((string)$input[$field]) : '';
            if ($current === '' && $incoming !== '') {
                if ($field === 'business_no') {
                    $businessKey = self::normalizeBusinessNo($incoming);
                    if ($businessKey === '' || self::findByBusinessKey($pdo, $businessKey, $vendorId) !== null) continue;
                }
                $set[] = $field . ' = :' . $field;
                $params[':' . $field] = $incoming;
                if ($field === 'business_no') {
                    $set[] = 'business_no_key = :business_no_key';
                    $params[':business_no_key'] = $businessKey;
                }
            }
        }
        if (count($set) === 0) return;
        $set[] = 'updated_at = :updated_at';
        $st = $pdo->prepare('UPDATE cpms_vendors SET ' . implode(', ', $set) . ' WHERE id = :id');
        $st->execute($params);
        self::$vendorCache = array();
    }

    /**
     * Resolve a selected/exact legacy vendor. Similar names are never merged.
     */
    public static function resolveOrCreate($pdo, $vendorId, $data, $source)
    {
        if (!self::ensureSchema($pdo)) return 0;
        $vendorId = (int)$vendorId;
        $input = self::vendorInput($data);
        if ($vendorId > 0 && self::getById($pdo, $vendorId)) {
            self::fillMissing($pdo, $vendorId, $input);
            return $vendorId;
        }
        if ($input['name'] === '') return 0;

        $businessKey = self::normalizeBusinessNo($input['business_no']);
        if ($businessKey !== '') {
            $businessVendor = self::findByBusinessKey($pdo, $businessKey, 0);
            if (is_array($businessVendor)) {
                self::fillMissing($pdo, (int)$businessVendor['id'], $input);
                return (int)$businessVendor['id'];
            }
        }

        $nameRows = self::findExactNameRows($pdo, $input['name']);
        if ($businessKey !== '') {
            $emptyBusinessRows = array();
            foreach ($nameRows as $row) {
                if (trim((string)(isset($row['business_no_key']) ? $row['business_no_key'] : '')) === '') $emptyBusinessRows[] = $row;
            }
            if (count($nameRows) === 1 && count($emptyBusinessRows) === 1) {
                $id = (int)$emptyBusinessRows[0]['id'];
                self::fillMissing($pdo, $id, $input);
                return $id;
            }
            // Same exact name with a different confirmed business number is a separate vendor.
            return self::insertVendor($pdo, $input, $source, array());
        }

        if (count($nameRows) === 1) {
            if (isset(self::$ambiguousNames[self::nameKey($input['name'])])) return 0;
            $id = (int)$nameRows[0]['id'];
            self::fillMissing($pdo, $id, $input);
            return $id;
        }
        if (count($nameRows) > 1) return 0;
        if (isset(self::$ambiguousNames[self::nameKey($input['name'])])) return 0;
        return self::insertVendor($pdo, $input, $source, array());
    }

    /**
     * Validate a vendor selected by autocomplete. A typed name alone is never
     * accepted, and this method never creates or updates a vendor.
     */
    public static function selectedVendorId($pdo, $vendorId, $submittedName)
    {
        if (!self::ensureSchema($pdo)) return 0;
        $vendorId = (int)$vendorId;
        if ($vendorId <= 0) return 0;
        $vendor = self::getById($pdo, $vendorId);
        if (!is_array($vendor)) return 0;
        $currentName = isset($vendor['name']) ? trim((string)$vendor['name']) : '';
        $submittedName = trim((string)$submittedName);
        if ($currentName === '' || $submittedName === '' || $currentName !== $submittedName) return 0;
        return $vendorId;
    }

    /**
     * Match an Excel/bulk row to an existing active vendor without creating it.
     * Business number is authoritative; without it only one exact name matches.
     */
    public static function matchExistingVendorId($pdo, $vendorId, $data)
    {
        if (!self::ensureSchema($pdo)) return 0;
        $input = self::vendorInput($data);
        $selectedId = self::selectedVendorId($pdo, $vendorId, $input['name']);
        if ($selectedId > 0) return $selectedId;
        if ($input['name'] === '') return 0;

        $businessKey = self::normalizeBusinessNo($input['business_no']);
        if ($businessKey !== '') {
            $businessVendor = self::findByBusinessKey($pdo, $businessKey, 0);
            if (is_array($businessVendor)) return (int)$businessVendor['id'];
        }

        $nameRows = self::findExactNameRows($pdo, $input['name']);
        if (count($nameRows) !== 1) return 0;
        if (isset(self::$ambiguousNames[self::nameKey($input['name'])])) return 0;
        if ($businessKey !== '') {
            $existingBusinessKey = isset($nameRows[0]['business_no_key']) ? trim((string)$nameRows[0]['business_no_key']) : '';
            if ($existingBusinessKey !== '' && $existingBusinessKey !== $businessKey) return 0;
        }
        return (int)$nameRows[0]['id'];
    }

    public static function saveVendor($pdo, $vendorId, $data, $actor, $source)
    {
        $result = array('ok' => false, 'id' => 0, 'duplicate_id' => 0, 'message' => '업체정보를 저장하지 못했습니다.');
        if (!self::ensureSchema($pdo)) {
            $result['message'] = '업체 마스터 테이블을 준비할 수 없습니다.';
            return $result;
        }
        $input = self::vendorInput($data);
        if ($input['name'] === '') {
            $result['message'] = '업체명을 입력해주세요.';
            return $result;
        }
        $vendorId = (int)$vendorId;
        $businessKey = self::normalizeBusinessNo($input['business_no']);
        if ($businessKey !== '') {
            $duplicate = self::findByBusinessKey($pdo, $businessKey, $vendorId);
            if (is_array($duplicate)) {
                $result['duplicate_id'] = (int)$duplicate['id'];
                $result['message'] = '동일한 사업자등록번호의 업체가 이미 등록되어 있습니다.';
                return $result;
            }
        }

        try {
            if ($vendorId <= 0) {
                $vendorId = self::insertVendor($pdo, $input, $source, $actor);
            } else {
                if (!self::getById($pdo, $vendorId)) {
                    $result['message'] = '수정할 업체를 찾을 수 없습니다.';
                    return $result;
                }
                $now = date('Y-m-d H:i:s');
                $st = $pdo->prepare("UPDATE cpms_vendors SET
                    business_no=:business_no,business_no_key=:business_no_key,name=:name,description=:description,
                    representative=:representative,phone=:phone,bank_name=:bank_name,account_number=:account_number,
                    account_holder=:account_holder,updated_by_name=:updated_by_name,updated_by_email=:updated_by_email,updated_at=:updated_at
                    WHERE id=:id AND is_active=1");
                $values = array(
                    ':business_no' => $input['business_no'] !== '' ? $input['business_no'] : null,
                    ':business_no_key' => $businessKey !== '' ? $businessKey : null,
                    ':name' => $input['name'],
                    ':description' => $input['description'] !== '' ? $input['description'] : null,
                    ':representative' => $input['representative'] !== '' ? $input['representative'] : null,
                    ':phone' => $input['phone'] !== '' ? $input['phone'] : null,
                    ':bank_name' => $input['bank_name'] !== '' ? $input['bank_name'] : null,
                    ':account_number' => $input['account_number'] !== '' ? $input['account_number'] : null,
                    ':account_holder' => $input['account_holder'] !== '' ? $input['account_holder'] : null,
                    ':updated_by_name' => isset($actor['name']) ? (string)$actor['name'] : null,
                    ':updated_by_email' => isset($actor['email']) ? (string)$actor['email'] : null,
                    ':updated_at' => $now,
                    ':id' => $vendorId
                );
                $st->execute($values);
                self::$vendorCache = array();
            }
            $result['ok'] = $vendorId > 0;
            $result['id'] = $vendorId;
            $result['message'] = $result['ok'] ? '업체정보를 저장했습니다.' : '업체정보를 저장하지 못했습니다.';
        } catch (Exception $e) {
            $result['message'] = '업체정보 저장 실패: ' . $e->getMessage();
        }
        return $result;
    }

    public static function importVendorRows($pdo, $rows, $actor)
    {
        $summary = array('ok'=>false,'created'=>0,'updated'=>0,'skipped'=>0,'errors'=>array());
        if (!self::ensureSchema($pdo) || !is_array($rows)) return $summary;
        $rowNumber = 1;
        foreach ($rows as $data) {
            $rowNumber++;
            if (!is_array($data)) {
                $summary['skipped']++;
                continue;
            }
            $input = self::vendorInput($data);
            if ($input['name'] === '') {
                $summary['skipped']++;
                $summary['errors'][] = $rowNumber . '행: 업체명이 없어 건너뛰었습니다.';
                continue;
            }
            $vendorId = 0;
            $businessKey = self::normalizeBusinessNo($input['business_no']);
            if ($businessKey !== '') {
                $existing = self::findByBusinessKey($pdo, $businessKey, 0);
                if (is_array($existing)) $vendorId = (int)$existing['id'];
                if ($vendorId <= 0) {
                    $nameRows = self::findExactNameRows($pdo, $input['name']);
                    if (count($nameRows) === 1 && trim((string)(isset($nameRows[0]['business_no_key']) ? $nameRows[0]['business_no_key'] : '')) === '') {
                        $vendorId = (int)$nameRows[0]['id'];
                    }
                }
            } else {
                $nameRows = self::findExactNameRows($pdo, $input['name']);
                if (count($nameRows) === 1) {
                    $vendorId = (int)$nameRows[0]['id'];
                } else if (count($nameRows) > 1) {
                    $summary['skipped']++;
                    $summary['errors'][] = $rowNumber . '행: 같은 업체명의 마스터가 여러 개 있어 자동 갱신하지 않았습니다.';
                    continue;
                }
            }

            if ($vendorId > 0) {
                $existing = self::getById($pdo, $vendorId);
                if (is_array($existing)) {
                    foreach (array('business_no','description','representative','phone','bank_name','account_number','account_holder') as $field) {
                        if ($input[$field] === '' && isset($existing[$field])) $input[$field] = (string)$existing[$field];
                    }
                }
            }
            $saved = self::saveVendor($pdo, $vendorId, $input, $actor, 'excel');
            if (!empty($saved['ok'])) {
                if ($vendorId > 0) $summary['updated']++;
                else $summary['created']++;
            } else {
                $summary['skipped']++;
                $summary['errors'][] = $rowNumber . '행: ' . (isset($saved['message']) ? $saved['message'] : '저장 실패');
            }
        }
        $summary['ok'] = true;
        if (count($summary['errors']) > 10) $summary['errors'] = array_slice($summary['errors'], 0, 10);
        return $summary;
    }

    public static function softDeleteVendor($pdo, $vendorId, $actor)
    {
        $result = array('ok'=>false,'message'=>'삭제할 업체를 찾을 수 없습니다.');
        $vendorId = (int)$vendorId;
        if ($vendorId <= 0 || !self::ensureSchema($pdo) || !self::getById($pdo, $vendorId)) return $result;
        try {
            $st = $pdo->prepare("UPDATE cpms_vendors SET is_active=0,business_no_key=NULL,updated_by_name=:updated_by_name,updated_by_email=:updated_by_email,updated_at=:updated_at WHERE id=:id AND is_active=1");
            $st->execute(array(
                ':updated_by_name'=>isset($actor['name']) ? (string)$actor['name'] : null,
                ':updated_by_email'=>isset($actor['email']) ? (string)$actor['email'] : null,
                ':updated_at'=>date('Y-m-d H:i:s'),
                ':id'=>$vendorId
            ));
            self::$vendorCache = array();
            $result['ok'] = $st->rowCount() > 0;
            $result['message'] = $result['ok']
                ? '업체를 삭제했습니다. 기존 거래 및 첨부자료는 삭제하지 않았습니다.'
                : '삭제할 업체를 찾을 수 없습니다.';
        } catch (Exception $e) {
            $result['message'] = '업체 삭제에 실패했습니다: ' . $e->getMessage();
        }
        return $result;
    }

    private static function presetSearchMeta($pdo, $presetType, $vendorId)
    {
        $empty = array('category'=>'', 'base_rate'=>'', 'remark'=>'');
        $table = $presetType === 'equipment' ? 'cpms_equipment_vendor_presets' : 'cpms_material_vendor_presets';
        $columns = self::columnMap($pdo, $table);
        if (!isset($columns['vendor_id']) || (int)$vendorId <= 0) return $empty;
        try {
            $sql = 'SELECT category,base_rate,remark FROM `' . $table . '` WHERE vendor_id=:vendor_id ORDER BY updated_at DESC,id DESC LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->bindValue(':vendor_id', (int)$vendorId, PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return $empty;
            return array(
                'category'=>isset($row['category']) ? (string)$row['category'] : '',
                'base_rate'=>isset($row['base_rate']) ? (string)$row['base_rate'] : '',
                'remark'=>isset($row['remark']) ? (string)$row['remark'] : ''
            );
        } catch (Exception $e) {
            return $empty;
        }
    }

    public static function search($pdo, $query, $limit, $presetType = 'material')
    {
        $rows = array();
        if (!self::ensureSchema($pdo)) return $rows;
        $query = trim((string)$query);
        if ($query === '') return $rows;
        $limit = (int)$limit;
        if ($limit < 1) $limit = 20;
        if ($limit > 100) $limit = 100;
        $sql = "SELECT id,vendor_uid,business_no,name,description,representative,phone
                FROM cpms_vendors
                WHERE is_active=1 AND (name LIKE :q_name OR business_no LIKE :q_business OR description LIKE :q_description)
                ORDER BY name ASC,id ASC LIMIT " . $limit;
        $st = $pdo->prepare($sql);
        $st->bindValue(':q_name', '%' . $query . '%');
        $st->bindValue(':q_business', '%' . $query . '%');
        $st->bindValue(':q_description', '%' . $query . '%');
        $st->execute();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $preset = self::presetSearchMeta($pdo, $presetType, (int)$row['id']);
            $description = isset($row['description']) ? (string)$row['description'] : '';
            $rows[] = array(
                'vendor_id' => (int)$row['id'],
                'vendor_uid' => isset($row['vendor_uid']) ? (string)$row['vendor_uid'] : '',
                'vendor_name' => isset($row['name']) ? (string)$row['name'] : '',
                'description' => $description,
                'category' => trim((string)$preset['category']) !== '' ? (string)$preset['category'] : $description,
                'representative' => isset($row['representative']) ? (string)$row['representative'] : '',
                'phone' => isset($row['phone']) ? (string)$row['phone'] : '',
                'biz_no' => isset($row['business_no']) ? (string)$row['business_no'] : '',
                'base_rate' => (string)$preset['base_rate'],
                'remark' => trim((string)$preset['remark']) !== '' ? (string)$preset['remark'] : $description
            );
        }
        return $rows;
    }

    public static function listVendors($pdo, $query, $limit)
    {
        if (!self::ensureSchema($pdo)) return array();
        $limit = (int)$limit;
        if ($limit < 1) $limit = 300;
        if ($limit > 1000) $limit = 1000;
        $query = trim((string)$query);
        $sql = 'SELECT * FROM cpms_vendors WHERE is_active=1';
        if ($query !== '') {
            $sql .= ' AND (name LIKE :q_name OR business_no LIKE :q_business OR description LIKE :q_description OR representative LIKE :q_representative OR phone LIKE :q_phone)';
        }
        $sql .= ' ORDER BY name ASC,id ASC LIMIT ' . $limit;
        $st = $pdo->prepare($sql);
        if ($query !== '') {
            $st->bindValue(':q_name', '%' . $query . '%');
            $st->bindValue(':q_business', '%' . $query . '%');
            $st->bindValue(':q_description', '%' . $query . '%');
            $st->bindValue(':q_representative', '%' . $query . '%');
            $st->bindValue(':q_phone', '%' . $query . '%');
        }
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private static function syncDbSource($pdo, $source, $definition, &$summary)
    {
        $table = $definition['table'];
        $columns = self::columnMap($pdo, $table);
        if (!isset($columns['id']) || !isset($columns[$definition['name']])) return;
        $hasReference = isset($columns['vendor_id']);

        $select = array('id', $definition['name'] . ' AS source_name');
        if ($hasReference) $select[] = 'vendor_id';
        foreach (array('business', 'description', 'representative', 'phone') as $field) {
            $column = isset($definition[$field]) ? $definition[$field] : '';
            if ($column !== '' && isset($columns[$column])) $select[] = $column . ' AS source_' . $field;
        }
        $sql = 'SELECT ' . implode(',', $select) . ' FROM `' . $table . '` WHERE ';
        if ($hasReference) $sql .= '(vendor_id IS NULL OR vendor_id=0) AND ';
        $sql .= 'COALESCE(`' . $definition['name'] . "`,'')<>'' ORDER BY id ASC";
        try {
            $vendorCountBefore = self::vendorCount($pdo);
            $st = $pdo->query($sql);
            $up = $hasReference ? $pdo->prepare('UPDATE `' . $table . '` SET vendor_id=:vendor_id WHERE id=:id AND (vendor_id IS NULL OR vendor_id=0)') : null;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $data = array(
                    'name' => isset($row['source_name']) ? $row['source_name'] : '',
                    'business_no' => isset($row['source_business']) ? $row['source_business'] : '',
                    'description' => isset($row['source_description']) ? $row['source_description'] : '',
                    'representative' => isset($row['source_representative']) ? $row['source_representative'] : '',
                    'phone' => isset($row['source_phone']) ? $row['source_phone'] : ''
                );
                $vendorId = self::resolveOrCreate($pdo, 0, $data, $source);
                if ($vendorId > 0 && $up) {
                    $up->execute(array(':vendor_id' => $vendorId, ':id' => (int)$row['id']));
                    $summary['linked']++;
                } else if ($vendorId <= 0) {
                    $summary['unresolved']++;
                }
            }
            $vendorCountAfter = self::vendorCount($pdo);
            if ($vendorCountAfter > $vendorCountBefore) $summary['created'] += ($vendorCountAfter - $vendorCountBefore);
        } catch (Exception $e) {
            error_log('[VendorMaster] legacy source ' . $source . ': ' . $e->getMessage());
        }
    }

    private static function vendorCount($pdo)
    {
        try {
            return (int)$pdo->query('SELECT COUNT(*) FROM cpms_vendors')->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function safetyStorePath()
    {
        if (!function_exists('cpms_storage_root')) return '';
        return rtrim((string)cpms_storage_root(), '/\\') . '/safety_costs/usage.json';
    }

    private static function readSafetyStore()
    {
        $path = self::safetyStorePath();
        if ($path === '' || !is_file($path)) return array('items' => array());
        if (function_exists('cpms_read_json_file')) return cpms_read_json_file($path, array('items' => array()));
        $decoded = json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) ? $decoded : array('items' => array());
    }

    private static function writeSafetyStore($store)
    {
        $path = self::safetyStorePath();
        if ($path === '') return false;
        if (function_exists('cpms_write_json_file')) return cpms_write_json_file($path, $store);
        return false;
    }

    private static function syncSafetyStore($pdo, &$summary)
    {
        $store = self::readSafetyStore();
        if (!isset($store['items']) || !is_array($store['items'])) return;
        $vendorCountBefore = self::vendorCount($pdo);
        $changed = false;
        foreach ($store['items'] as $index => $row) {
            if (!is_array($row)) continue;
            if (isset($row['vendor_id']) && (int)$row['vendor_id'] > 0) continue;
            $name = isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '';
            if ($name === '') continue;
            $data = array(
                'name' => $name,
                'business_no' => isset($row['biz_no']) ? $row['biz_no'] : '',
                'description' => '',
                'representative' => isset($row['representative']) ? $row['representative'] : '',
                'phone' => isset($row['phone']) ? $row['phone'] : ''
            );
            $vendorId = self::resolveOrCreate($pdo, 0, $data, 'safety');
            if ($vendorId > 0) {
                $store['items'][$index]['vendor_id'] = $vendorId;
                $summary['linked']++;
                $changed = true;
            } else {
                $summary['unresolved']++;
            }
        }
        if ($changed && !self::writeSafetyStore($store)) {
            error_log('[VendorMaster] safety legacy link metadata could not be saved.');
        }
        $vendorCountAfter = self::vendorCount($pdo);
        if ($vendorCountAfter > $vendorCountBefore) $summary['created'] += ($vendorCountAfter - $vendorCountBefore);
    }

    public static function syncLegacy($pdo)
    {
        $summary = array('ok' => false, 'created' => 0, 'linked' => 0, 'unresolved' => 0);
        if (!self::ensureSchema($pdo)) return $summary;
        $pdoKey = self::pdoKey($pdo);
        if (isset(self::$syncState[$pdoKey])) return self::$syncState[$pdoKey];

        $sources = array(
            'material_preset' => array('table'=>'cpms_material_vendor_presets','name'=>'vendor_name','business'=>'biz_no','representative'=>'representative','phone'=>'phone'),
            'equipment_preset' => array('table'=>'cpms_equipment_vendor_presets','name'=>'vendor_name','business'=>'biz_no','representative'=>'representative','phone'=>'phone'),
            'material_item' => array('table'=>'cpms_material_items','name'=>'vendor_name','business'=>'biz_no','representative'=>'representative','phone'=>'phone'),
            'equipment_item' => array('table'=>'cpms_equipment_items','name'=>'vendor_name','business'=>'biz_no','representative'=>'representative','phone'=>'phone'),
            'outsourcing' => array('table'=>'cpms_outsourcing_costs','name'=>'company_name','business'=>'business_no','representative'=>'representative_name','phone'=>'contact')
        );
        self::prepareLegacyAmbiguousNames($pdo, $sources);
        foreach ($sources as $source => $definition) self::syncDbSource($pdo, $source, $definition, $summary);
        self::syncSafetyStore($pdo, $summary);
        $summary['ok'] = true;
        self::$syncState[$pdoKey] = $summary;
        return $summary;
    }

    private static function prepareLegacyAmbiguousNames($pdo, $sources)
    {
        $businessByName = array();
        foreach ($sources as $definition) {
            $columns = self::columnMap($pdo, $definition['table']);
            if (!isset($columns[$definition['name']]) || !isset($definition['business']) || !isset($columns[$definition['business']])) continue;
            try {
                $sql = 'SELECT `' . $definition['name'] . '` AS source_name,`' . $definition['business'] . '` AS source_business FROM `' . $definition['table'] . '` WHERE COALESCE(`' . $definition['name'] . "`,'')<>'' AND COALESCE(`" . $definition['business'] . "`,'')<>''";
                $st = $pdo->query($sql);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $nameKey = self::nameKey(isset($row['source_name']) ? $row['source_name'] : '');
                    $businessKey = self::normalizeBusinessNo(isset($row['source_business']) ? $row['source_business'] : '');
                    if ($nameKey === '' || $businessKey === '') continue;
                    if (!isset($businessByName[$nameKey])) $businessByName[$nameKey] = array();
                    $businessByName[$nameKey][$businessKey] = true;
                }
            } catch (Exception $e) {}
        }
        $store = self::readSafetyStore();
        if (isset($store['items']) && is_array($store['items'])) {
            foreach ($store['items'] as $row) {
                if (!is_array($row)) continue;
                $nameKey = self::nameKey(isset($row['vendor_name']) ? $row['vendor_name'] : '');
                $businessKey = self::normalizeBusinessNo(isset($row['biz_no']) ? $row['biz_no'] : '');
                if ($nameKey === '' || $businessKey === '') continue;
                if (!isset($businessByName[$nameKey])) $businessByName[$nameKey] = array();
                $businessByName[$nameKey][$businessKey] = true;
            }
        }
        self::$ambiguousNames = array();
        foreach ($businessByName as $nameKey => $businessKeys) {
            if (count($businessKeys) > 1) self::$ambiguousNames[$nameKey] = true;
        }
    }

    public static function attachDbRecord($pdo, $table, $recordId, $vendorId)
    {
        $allowed = array('cpms_material_vendor_presets','cpms_equipment_vendor_presets','cpms_material_items','cpms_equipment_items','cpms_outsourcing_costs');
        if (!in_array($table, $allowed, true) || (int)$recordId <= 0 || (int)$vendorId <= 0) return false;
        self::ensureReferenceColumn($pdo, $table);
        try {
            $st = $pdo->prepare('UPDATE `' . $table . '` SET vendor_id=:vendor_id WHERE id=:id');
            return $st->execute(array(':vendor_id'=>(int)$vendorId, ':id'=>(int)$recordId));
        } catch (Exception $e) {
            return false;
        }
    }

    public static function applyCurrentVendor($pdo, $row, $nameField, $representativeField, $phoneField, $businessField)
    {
        if (!is_array($row) || !isset($row['vendor_id']) || (int)$row['vendor_id'] <= 0) return $row;
        $vendor = self::getByIdIncludingInactive($pdo, (int)$row['vendor_id']);
        if (!is_array($vendor)) return $row;
        if ($nameField !== '') $row[$nameField] = isset($vendor['name']) ? (string)$vendor['name'] : (isset($row[$nameField]) ? $row[$nameField] : '');
        if ($representativeField !== '' && trim((string)(isset($vendor['representative']) ? $vendor['representative'] : '')) !== '') $row[$representativeField] = (string)$vendor['representative'];
        if ($phoneField !== '' && trim((string)(isset($vendor['phone']) ? $vendor['phone'] : '')) !== '') $row[$phoneField] = (string)$vendor['phone'];
        if ($businessField !== '' && trim((string)(isset($vendor['business_no']) ? $vendor['business_no'] : '')) !== '') $row[$businessField] = (string)$vendor['business_no'];
        $row['vendor_description'] = isset($vendor['description']) ? (string)$vendor['description'] : '';
        return $row;
    }

    public static function applyCurrentVendorRows($pdo, $rows, $nameField, $representativeField, $phoneField, $businessField)
    {
        if (!is_array($rows)) return array();
        foreach ($rows as $index => $row) {
            $rows[$index] = self::applyCurrentVendor($pdo, $row, $nameField, $representativeField, $phoneField, $businessField);
        }
        return $rows;
    }

    private static function unresolvedDbGroups($pdo, $source, $definition, &$groups)
    {
        $columns = self::columnMap($pdo, $definition['table']);
        if (!isset($columns['vendor_id']) || !isset($columns[$definition['name']])) return;
        $businessColumn = isset($definition['business']) && isset($columns[$definition['business']]) ? $definition['business'] : '';
        $selectBusiness = $businessColumn !== '' ? ',`' . $businessColumn . '` AS source_business' : ",'' AS source_business";
        $sql = 'SELECT `' . $definition['name'] . '` AS source_name' . $selectBusiness . ',COUNT(*) AS source_count FROM `' . $definition['table'] . '` WHERE (vendor_id IS NULL OR vendor_id=0) AND COALESCE(`' . $definition['name'] . "`,'')<>'' GROUP BY `" . $definition['name'] . '`';
        if ($businessColumn !== '') $sql .= ',`' . $businessColumn . '`';
        try {
            $st = $pdo->query($sql);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $groups[] = self::legacyGroupRow($pdo, $source, (string)$row['source_name'], isset($row['source_business']) ? (string)$row['source_business'] : '', (int)$row['source_count']);
            }
        } catch (Exception $e) {}
    }

    private static function legacyGroupRow($pdo, $source, $name, $businessNo, $count)
    {
        $suggestedId = 0;
        $businessKey = self::normalizeBusinessNo($businessNo);
        if ($businessKey !== '') {
            $vendor = self::findByBusinessKey($pdo, $businessKey, 0);
            if (is_array($vendor)) $suggestedId = (int)$vendor['id'];
        }
        if ($suggestedId <= 0) {
            $nameRows = self::findExactNameRows($pdo, $name);
            if (count($nameRows) === 1) $suggestedId = (int)$nameRows[0]['id'];
        }
        $token = base64_encode(json_encode(array('source'=>$source,'name'=>$name,'business_no'=>$businessNo)));
        return array('source'=>$source,'name'=>$name,'business_no'=>$businessNo,'count'=>$count,'suggested_id'=>$suggestedId,'token'=>$token);
    }

    public static function legacyGroups($pdo)
    {
        $groups = array();
        if (!self::ensureSchema($pdo)) return $groups;
        $sources = array(
            'material_preset'=>array('table'=>'cpms_material_vendor_presets','name'=>'vendor_name','business'=>'biz_no'),
            'equipment_preset'=>array('table'=>'cpms_equipment_vendor_presets','name'=>'vendor_name','business'=>'biz_no'),
            'material_item'=>array('table'=>'cpms_material_items','name'=>'vendor_name','business'=>'biz_no'),
            'equipment_item'=>array('table'=>'cpms_equipment_items','name'=>'vendor_name','business'=>'biz_no'),
            'outsourcing'=>array('table'=>'cpms_outsourcing_costs','name'=>'company_name','business'=>'business_no')
        );
        foreach ($sources as $source => $definition) self::unresolvedDbGroups($pdo, $source, $definition, $groups);

        $store = self::readSafetyStore();
        $safetyGroups = array();
        if (isset($store['items']) && is_array($store['items'])) {
            foreach ($store['items'] as $row) {
                if (!is_array($row) || (isset($row['vendor_id']) && (int)$row['vendor_id'] > 0)) continue;
                $name = isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '';
                if ($name === '') continue;
                $businessNo = isset($row['biz_no']) ? trim((string)$row['biz_no']) : '';
                $key = $name . '|' . $businessNo;
                if (!isset($safetyGroups[$key])) $safetyGroups[$key] = array('name'=>$name,'business_no'=>$businessNo,'count'=>0);
                $safetyGroups[$key]['count']++;
            }
        }
        foreach ($safetyGroups as $row) $groups[] = self::legacyGroupRow($pdo, 'safety', $row['name'], $row['business_no'], $row['count']);
        return $groups;
    }

    public static function linkLegacyToken($pdo, $token, $vendorId)
    {
        $vendorId = (int)$vendorId;
        if ($vendorId <= 0 || !self::getById($pdo, $vendorId)) return 0;
        $decoded = base64_decode((string)$token, true);
        $payload = $decoded !== false ? json_decode($decoded, true) : null;
        if (!is_array($payload)) return 0;
        $source = isset($payload['source']) ? (string)$payload['source'] : '';
        $name = isset($payload['name']) ? trim((string)$payload['name']) : '';
        $businessNo = isset($payload['business_no']) ? trim((string)$payload['business_no']) : '';
        if ($name === '') return 0;

        $sources = array(
            'material_preset'=>array('table'=>'cpms_material_vendor_presets','name'=>'vendor_name','business'=>'biz_no'),
            'equipment_preset'=>array('table'=>'cpms_equipment_vendor_presets','name'=>'vendor_name','business'=>'biz_no'),
            'material_item'=>array('table'=>'cpms_material_items','name'=>'vendor_name','business'=>'biz_no'),
            'equipment_item'=>array('table'=>'cpms_equipment_items','name'=>'vendor_name','business'=>'biz_no'),
            'outsourcing'=>array('table'=>'cpms_outsourcing_costs','name'=>'company_name','business'=>'business_no')
        );
        if (isset($sources[$source])) {
            $definition = $sources[$source];
            $columns = self::columnMap($pdo, $definition['table']);
            if (!isset($columns['vendor_id'])) return 0;
            $sql = 'UPDATE `' . $definition['table'] . '` SET vendor_id=:vendor_id WHERE (vendor_id IS NULL OR vendor_id=0) AND `' . $definition['name'] . '`=:source_name';
            $params = array(':vendor_id'=>$vendorId, ':source_name'=>$name);
            if ($businessNo !== '' && isset($columns[$definition['business']])) {
                $sql .= ' AND COALESCE(`' . $definition['business'] . "`,'')=:business_no";
                $params[':business_no'] = $businessNo;
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return (int)$st->rowCount();
        }

        if ($source === 'safety') {
            $store = self::readSafetyStore();
            $count = 0;
            if (!isset($store['items']) || !is_array($store['items'])) return 0;
            foreach ($store['items'] as $index => $row) {
                if (!is_array($row) || (isset($row['vendor_id']) && (int)$row['vendor_id'] > 0)) continue;
                if (trim((string)(isset($row['vendor_name']) ? $row['vendor_name'] : '')) !== $name) continue;
                if ($businessNo !== '' && trim((string)(isset($row['biz_no']) ? $row['biz_no'] : '')) !== $businessNo) continue;
                $store['items'][$index]['vendor_id'] = $vendorId;
                $count++;
            }
            if ($count > 0 && !self::writeSafetyStore($store)) return 0;
            return $count;
        }
        return 0;
    }
}
