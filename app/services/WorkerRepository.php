<?php
/**
 * C:\www\cpms\app\services\WorkerRepository.php
 * - 관리 > 인력관리 workers 테이블 CRUD와 매칭 규칙 담당
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/CryptoHelper.php';

if (!class_exists('WorkerRepository')) {
class WorkerRepository
{
    private $pdo;
    private $schemaReady = false;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureSchema()
    {
        if ($this->schemaReady) return true;
        if (!$this->pdo) return false;

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS workers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                import_no VARCHAR(50) NULL,
                name VARCHAR(100) NOT NULL,
                resident_no_enc TEXT NULL,
                resident_no_hash CHAR(64) NULL,
                birth_date DATE NULL,
                phone VARCHAR(50) NULL,
                phone_digits VARCHAR(30) NULL,
                address VARCHAR(255) NULL,
                job_type VARCHAR(100) NULL,
                agency_name VARCHAR(100) NULL,
                daily_wage INT DEFAULT 0,
                account_holder VARCHAR(100) NULL,
                bank_name VARCHAR(100) NULL,
                bank_account_enc TEXT NULL,
                bank_account_hash CHAR(64) NULL,
                memo TEXT NULL,
                source_type VARCHAR(30) DEFAULT 'manual',
                is_active TINYINT DEFAULT 1,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                deleted_at DATETIME NULL,
                KEY idx_workers_name(name),
                KEY idx_workers_phone_digits(phone_digits),
                KEY idx_workers_agency_name(agency_name),
                KEY idx_workers_job_type(job_type),
                KEY idx_workers_resident_hash(resident_no_hash),
                KEY idx_workers_active(is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS worker_import_batches (
                id INT AUTO_INCREMENT PRIMARY KEY,
                original_filename VARCHAR(255) NULL,
                stored_filename VARCHAR(255) NULL,
                total_rows INT DEFAULT 0,
                success_rows INT DEFAULT 0,
                update_rows INT DEFAULT 0,
                skip_rows INT DEFAULT 0,
                error_rows INT DEFAULT 0,
                uploaded_by INT NULL,
                uploaded_at DATETIME NOT NULL,
                KEY idx_worker_import_uploaded_at(uploaded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS worker_import_errors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                batch_id INT NOT NULL,
                row_no INT NOT NULL,
                error_message TEXT NULL,
                raw_json TEXT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_worker_import_errors_batch(batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->schemaReady = true;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function listWorkers($filters, $limit)
    {
        $this->ensureSchema();
        $rows = array();
        if (!$this->pdo) return $rows;

        $where = array();
        $params = array();

        $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
        if ($status === 'deleted') {
            $where[] = "deleted_at IS NOT NULL";
        } else {
            $where[] = "deleted_at IS NULL";
            if ($status === 'active') {
                $where[] = "is_active = 1";
            } else if ($status === 'inactive') {
                $where[] = "is_active = 0";
            }
        }

        $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
        if ($q !== '') {
            $where[] = "name LIKE :q";
            $params[':q'] = '%' . $q . '%';
        }

        $phone = isset($filters['phone']) ? trim((string)$filters['phone']) : '';
        if ($phone !== '') {
            $phoneDigitsFilter = CryptoHelper::normalizePhoneDigits($phone);
            if ($phoneDigitsFilter !== '') {
                $where[] = "(phone LIKE :phone OR phone_digits LIKE :phone_digits)";
                $params[':phone_digits'] = '%' . $phoneDigitsFilter . '%';
            } else {
                $where[] = "phone LIKE :phone";
            }
            $params[':phone'] = '%' . $phone . '%';
        }

        $jobType = isset($filters['job_type']) ? trim((string)$filters['job_type']) : '';
        if ($jobType !== '') {
            $where[] = "job_type LIKE :job_type";
            $params[':job_type'] = '%' . $jobType . '%';
        }

        $agencyName = isset($filters['agency_name']) ? trim((string)$filters['agency_name']) : '';
        if ($agencyName !== '') {
            $where[] = "agency_name LIKE :agency_name";
            $params[':agency_name'] = '%' . $agencyName . '%';
        }

        $sql = "SELECT * FROM workers";
        if (count($where) > 0) $sql .= " WHERE " . implode(" AND ", $where);
        $missingOrder = "(CASE WHEN deleted_at IS NULL THEN "
            . "(TRIM(COALESCE(name, '')) = '') + (TRIM(COALESCE(phone, '')) = '') + (resident_no_hash IS NULL OR resident_no_hash = '') + "
            . "(TRIM(COALESCE(job_type, '')) = '') + (TRIM(COALESCE(agency_name, '')) = '') + (daily_wage <= 0) + "
            . "(TRIM(COALESCE(bank_name, '')) = '') + (bank_account_hash IS NULL OR bank_account_hash = '') + (TRIM(COALESCE(account_holder, '')) = '') "
            . "ELSE 0 END)";
        $sql .= " ORDER BY deleted_at IS NOT NULL ASC, " . $missingOrder . " DESC, is_active DESC, name ASC, id DESC LIMIT " . (int)$limit;

        try {
            $st = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $st->bindValue($k, $v);
            }
            $st->execute();
            $dbRows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dbRows as $row) {
                $rows[] = $this->formatWorker($row, false);
            }
        } catch (Exception $e) {
            return array();
        }

        return $rows;
    }

    public function getById($id, $includeSensitive)
    {
        $this->ensureSchema();
        if (!$this->pdo || (int)$id <= 0) return null;
        try {
            $st = $this->pdo->prepare("SELECT * FROM workers WHERE id = :id LIMIT 1");
            $st->bindValue(':id', (int)$id, PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->formatWorker($row, (bool)$includeSensitive) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    public function save($data, $userId)
    {
        $this->ensureSchema();
        if (!$this->pdo) return 0;

        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $name = isset($data['name']) ? trim((string)$data['name']) : '';
        if ($name === '') return 0;

        $row = $this->normalizeForSave($data);
        $now = date('Y-m-d H:i:s');
        $userId = (int)$userId;

        if ($id > 0) {
            $sets = array(
                "import_no = :import_no",
                "name = :name",
                "birth_date = :birth_date",
                "phone = :phone",
                "phone_digits = :phone_digits",
                "address = :address",
                "job_type = :job_type",
                "agency_name = :agency_name",
                "daily_wage = :daily_wage",
                "account_holder = :account_holder",
                "bank_name = :bank_name",
                "memo = :memo",
                "source_type = :source_type",
                "is_active = :is_active",
                "updated_by = :updated_by",
                "updated_at = :updated_at"
            );
            $params = array(
                ':import_no' => $row['import_no'],
                ':name' => $row['name'],
                ':birth_date' => $row['birth_date'],
                ':phone' => $row['phone'],
                ':phone_digits' => $row['phone_digits'],
                ':address' => $row['address'],
                ':job_type' => $row['job_type'],
                ':agency_name' => $row['agency_name'],
                ':daily_wage' => $row['daily_wage'],
                ':account_holder' => $row['account_holder'],
                ':bank_name' => $row['bank_name'],
                ':memo' => $row['memo'],
                ':source_type' => $row['source_type'],
                ':is_active' => $row['is_active'],
                ':updated_by' => $userId > 0 ? $userId : null,
                ':updated_at' => $now,
                ':id' => $id
            );

            if ($row['resident_no'] !== '') {
                $sets[] = "resident_no_enc = :resident_no_enc";
                $sets[] = "resident_no_hash = :resident_no_hash";
                $params[':resident_no_enc'] = CryptoHelper::encrypt($row['resident_no']);
                $params[':resident_no_hash'] = CryptoHelper::hashSensitive($row['resident_no']);
            }
            if ($row['bank_account'] !== '') {
                $sets[] = "bank_account_enc = :bank_account_enc";
                $sets[] = "bank_account_hash = :bank_account_hash";
                $params[':bank_account_enc'] = CryptoHelper::encrypt($row['bank_account']);
                $params[':bank_account_hash'] = CryptoHelper::hashSensitive($row['bank_account']);
            }

            $sql = "UPDATE workers SET " . implode(", ", $sets) . " WHERE id = :id";
            $st = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $this->bindAuto($st, $k, $v);
            }
            $st->execute();
            return $id;
        }

        $sql = "INSERT INTO workers
            (import_no, name, resident_no_enc, resident_no_hash, birth_date, phone, phone_digits, address,
             job_type, agency_name, daily_wage, account_holder, bank_name, bank_account_enc, bank_account_hash,
             memo, source_type, is_active, created_by, updated_by, created_at, updated_at, deleted_at)
            VALUES
            (:import_no, :name, :resident_no_enc, :resident_no_hash, :birth_date, :phone, :phone_digits, :address,
             :job_type, :agency_name, :daily_wage, :account_holder, :bank_name, :bank_account_enc, :bank_account_hash,
             :memo, :source_type, :is_active, :created_by, :updated_by, :created_at, NULL, NULL)";

        $st = $this->pdo->prepare($sql);
        $params = array(
            ':import_no' => $row['import_no'],
            ':name' => $row['name'],
            ':resident_no_enc' => $row['resident_no'] !== '' ? CryptoHelper::encrypt($row['resident_no']) : null,
            ':resident_no_hash' => $row['resident_no'] !== '' ? CryptoHelper::hashSensitive($row['resident_no']) : null,
            ':birth_date' => $row['birth_date'],
            ':phone' => $row['phone'],
            ':phone_digits' => $row['phone_digits'],
            ':address' => $row['address'],
            ':job_type' => $row['job_type'],
            ':agency_name' => $row['agency_name'],
            ':daily_wage' => $row['daily_wage'],
            ':account_holder' => $row['account_holder'],
            ':bank_name' => $row['bank_name'],
            ':bank_account_enc' => $row['bank_account'] !== '' ? CryptoHelper::encrypt($row['bank_account']) : null,
            ':bank_account_hash' => $row['bank_account'] !== '' ? CryptoHelper::hashSensitive($row['bank_account']) : null,
            ':memo' => $row['memo'],
            ':source_type' => $row['source_type'],
            ':is_active' => $row['is_active'],
            ':created_by' => $userId > 0 ? $userId : null,
            ':updated_by' => null,
            ':created_at' => $now
        );
        foreach ($params as $k => $v) {
            $this->bindAuto($st, $k, $v);
        }
        $st->execute();
        return (int)$this->pdo->lastInsertId();
    }

    public function softDelete($id, $userId)
    {
        $this->ensureSchema();
        if (!$this->pdo || (int)$id <= 0) return false;
        try {
            $st = $this->pdo->prepare("UPDATE workers
                                       SET is_active = 0,
                                           updated_by = :updated_by,
                                           updated_at = :updated_at,
                                           deleted_at = :deleted_at
                                       WHERE id = :id");
            $now = date('Y-m-d H:i:s');
            $st->bindValue(':updated_by', (int)$userId > 0 ? (int)$userId : null, (int)$userId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(':updated_at', $now);
            $st->bindValue(':deleted_at', $now);
            $st->bindValue(':id', (int)$id, PDO::PARAM_INT);
            $st->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function updateProjectEditableFields($id, $agencyName, $dailyWage, $userId, $updateWage, $jobType, $memo)
    {
        $this->ensureSchema();
        $id = (int)$id;
        if (!$this->pdo || $id <= 0) return false;
        $agencyName = trim((string)$agencyName);
        $jobType = trim((string)$jobType);
        $memo = trim((string)$memo);
        $dailyWage = max(0, (int)$dailyWage);
        $sets = array(
            'agency_name = :agency_name',
            'job_type = :job_type',
            'memo = :memo',
            'updated_by = :updated_by',
            'updated_at = :updated_at'
        );
        if ($updateWage) $sets[] = 'daily_wage = :daily_wage';
        try {
            $st = $this->pdo->prepare("UPDATE workers SET " . implode(', ', $sets) . " WHERE id = :id AND deleted_at IS NULL");
            $st->bindValue(':agency_name', $agencyName === '' ? null : $agencyName, $agencyName === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $st->bindValue(':job_type', $jobType === '' ? null : $jobType, $jobType === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $st->bindValue(':memo', $memo === '' ? null : $memo, $memo === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            if ($updateWage) $st->bindValue(':daily_wage', $dailyWage, PDO::PARAM_INT);
            $st->bindValue(':updated_by', (int)$userId > 0 ? (int)$userId : null, (int)$userId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $st->bindValue(':updated_at', date('Y-m-d H:i:s'));
            $st->bindValue(':id', $id, PDO::PARAM_INT);
            $st->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function searchByName($keyword, $limit)
    {
        $this->ensureSchema();
        $keyword = trim((string)$keyword);
        if (!$this->pdo || $keyword === '') return array();

        $phoneDigits = CryptoHelper::normalizePhoneDigits($keyword);
        $sql = "SELECT * FROM workers
                WHERE deleted_at IS NULL
                  AND is_active = 1
                  AND (name LIKE :q OR phone LIKE :q";
        if ($phoneDigits !== '') {
            $sql .= " OR phone_digits LIKE :phone_digits";
        }
        $sql .= ")
                ORDER BY name ASC, id DESC
                LIMIT " . (int)$limit;
        try {
            $st = $this->pdo->prepare($sql);
            $st->bindValue(':q', '%' . $keyword . '%');
            if ($phoneDigits !== '') $st->bindValue(':phone_digits', '%' . $phoneDigits . '%');
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $out = array();
            foreach ($rows as $row) {
                $out[] = $this->formatWorker($row, false);
            }
            return $out;
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * 기존 현장 인원 중 아직 인력 마스터 ID가 없는 행을 안전하게 편입합니다.
     * 주민번호, 이름+연락처, 정확히 일치하는 단일 이름 순서로만 연결하며
     * 동명이인은 임의로 합치지 않습니다.
     */
    public function syncLegacyProjectWorkers($projectId, $userId, $limit)
    {
        $result = array('created' => 0, 'linked' => 0, 'duplicate' => 0, 'skipped' => 0);
        $this->ensureSchema();
        if (!$this->pdo) return $result;

        $projectId = (int)$projectId;
        $userId = (int)$userId;
        $limit = (int)$limit;
        if ($limit <= 0) $limit = 1000;
        if ($limit > 5000) $limit = 5000;

        try {
            $sql = "SELECT id, project_id, name, worker_name_snapshot, resident_no, phone, address,
                           job_type_snapshot, agency_name_snapshot, daily_wage_snapshot, deposit_rate,
                           bank_account, bank_name, account_holder, company_name
                    FROM cpms_project_labor_workers
                    WHERE is_deleted = 0
                      AND (worker_id IS NULL OR worker_id = 0)";
            if ($projectId > 0) $sql .= " AND project_id = :project_id";
            $sql .= " ORDER BY id ASC LIMIT " . $limit;
            $st = $this->pdo->prepare($sql);
            if ($projectId > 0) $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return $result;
        }

        foreach ($rows as $legacy) {
            $legacyId = isset($legacy['id']) ? (int)$legacy['id'] : 0;
            $name = isset($legacy['worker_name_snapshot']) ? trim((string)$legacy['worker_name_snapshot']) : '';
            if ($name === '' && isset($legacy['name'])) $name = trim((string)$legacy['name']);
            if ($legacyId <= 0 || $name === '') {
                $result['skipped']++;
                continue;
            }

            $residentNo = isset($legacy['resident_no']) ? trim((string)$legacy['resident_no']) : '';
            $phone = isset($legacy['phone']) ? trim((string)$legacy['phone']) : '';
            $match = $this->matchWorker($name, $phone, $residentNo);
            $status = isset($match['status']) ? (string)$match['status'] : 'not_found';
            if ($status === 'duplicate') {
                $result['duplicate']++;
                continue;
            }

            $masterId = 0;
            try {
                if ($status === 'matched' && isset($match['worker']['id'])) {
                    $masterId = (int)$match['worker']['id'];
                    $master = $this->getById($masterId, true);
                    if (is_array($master)) {
                        $merged = $this->mergeMissingLegacyFields($master, $legacy);
                        $this->save($merged, $userId);
                    }
                } else {
                    $dailyWage = isset($legacy['daily_wage_snapshot']) ? (int)$legacy['daily_wage_snapshot'] : 0;
                    if ($dailyWage <= 0 && isset($legacy['deposit_rate'])) $dailyWage = (int)$legacy['deposit_rate'];
                    $agencyName = isset($legacy['agency_name_snapshot']) ? trim((string)$legacy['agency_name_snapshot']) : '';
                    if ($agencyName === '' && isset($legacy['company_name'])) $agencyName = trim((string)$legacy['company_name']);
                    $masterId = $this->save(array(
                        'name' => $name,
                        'resident_no' => $residentNo,
                        'phone' => $phone,
                        'address' => isset($legacy['address']) ? $legacy['address'] : '',
                        'job_type' => isset($legacy['job_type_snapshot']) ? $legacy['job_type_snapshot'] : '',
                        'agency_name' => $agencyName,
                        'daily_wage' => $dailyWage,
                        'bank_name' => isset($legacy['bank_name']) ? $legacy['bank_name'] : '',
                        'bank_account' => isset($legacy['bank_account']) ? $legacy['bank_account'] : '',
                        'account_holder' => isset($legacy['account_holder']) ? $legacy['account_holder'] : '',
                        'source_type' => 'legacy_project',
                        'is_active' => 1,
                    ), $userId);
                    if ($masterId > 0) $result['created']++;
                }

                if ($masterId <= 0) {
                    $result['skipped']++;
                    continue;
                }

                $stLink = $this->pdo->prepare("UPDATE cpms_project_labor_workers
                                               SET worker_id = :worker_id,
                                                   source_type = 'legacy_project',
                                                   matched_status = 'matched',
                                                   updated_at = :updated_at
                                               WHERE id = :id
                                                 AND (worker_id IS NULL OR worker_id = 0)");
                $stLink->bindValue(':worker_id', $masterId, PDO::PARAM_INT);
                $stLink->bindValue(':updated_at', date('Y-m-d H:i:s'));
                $stLink->bindValue(':id', $legacyId, PDO::PARAM_INT);
                $stLink->execute();
                if ($stLink->rowCount() > 0) $result['linked']++;
            } catch (Exception $e) {
                $result['skipped']++;
            }
        }

        return $result;
    }

    public function findDuplicate($residentNoHash, $name, $phoneDigits, $excludeId)
    {
        $this->ensureSchema();
        if (!$this->pdo) return null;
        $excludeId = (int)$excludeId;

        try {
            if (trim((string)$residentNoHash) !== '') {
                $sql = "SELECT * FROM workers WHERE resident_no_hash = :hash AND deleted_at IS NULL";
                if ($excludeId > 0) $sql .= " AND id <> :exclude_id";
                $sql .= " ORDER BY id ASC LIMIT 1";
                $st = $this->pdo->prepare($sql);
                $st->bindValue(':hash', (string)$residentNoHash);
                if ($excludeId > 0) $st->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
                $st->execute();
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) return $row;
            }

            $name = trim((string)$name);
            $phoneDigits = trim((string)$phoneDigits);
            if ($name !== '' && $phoneDigits !== '') {
                $sql2 = "SELECT * FROM workers WHERE name = :name AND phone_digits = :phone_digits AND deleted_at IS NULL";
                if ($excludeId > 0) $sql2 .= " AND id <> :exclude_id";
                $sql2 .= " ORDER BY id ASC LIMIT 1";
                $st2 = $this->pdo->prepare($sql2);
                $st2->bindValue(':name', $name);
                $st2->bindValue(':phone_digits', $phoneDigits);
                if ($excludeId > 0) $st2->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
                $st2->execute();
                $row2 = $st2->fetch(PDO::FETCH_ASSOC);
                if ($row2) return $row2;
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    public function matchWorker($name, $phone, $residentNo)
    {
        $this->ensureSchema();
        $empty = array('status' => 'not_found', 'worker' => null, 'workers' => array(), 'message' => '인력관리 미등록');
        if (!$this->pdo) return $empty;

        $name = trim((string)$name);
        $phoneDigits = CryptoHelper::normalizePhoneDigits($phone);
        $residentHash = CryptoHelper::hashSensitive($residentNo);

        try {
            if ($residentHash !== null && $residentHash !== '') {
                $rows = $this->findRowsBySql("resident_no_hash = :hash", array(':hash' => $residentHash));
                return $this->matchResultFromRows($rows, '주민등록번호 해시값으로 매칭되었습니다.');
            }

            if ($name !== '' && $phoneDigits !== '') {
                $rows2 = $this->findRowsBySql("name = :name AND phone_digits = :phone_digits", array(':name' => $name, ':phone_digits' => $phoneDigits));
                if (count($rows2) > 0) {
                    return $this->matchResultFromRows($rows2, '이름과 연락처로 매칭되었습니다.');
                }
            }

            if ($name !== '') {
                $rows3 = $this->findRowsBySql("name = :name", array(':name' => $name));
                return $this->matchResultFromRows($rows3, count($rows3) === 1 ? '이름으로 자동 매칭되었습니다.' : '동명이인 확인 필요');
            }
        } catch (Exception $e) {
            return $empty;
        }

        return $empty;
    }

    public function formatWorker($row, $includeSensitive)
    {
        if (!is_array($row)) return array();

        $residentPlain = '';
        if (!empty($row['resident_no_enc'])) {
            $residentPlain = CryptoHelper::decrypt($row['resident_no_enc']);
        }
        $bankPlain = '';
        if (!empty($row['bank_account_enc'])) {
            $bankPlain = CryptoHelper::decrypt($row['bank_account_enc']);
        }

        $deletedAt = isset($row['deleted_at']) ? (string)$row['deleted_at'] : '';
        $status = $deletedAt !== '' ? 'deleted' : ((isset($row['is_active']) && (int)$row['is_active'] === 1) ? 'active' : 'inactive');

        $out = array(
            'id' => isset($row['id']) ? (int)$row['id'] : 0,
            'import_no' => isset($row['import_no']) ? (string)$row['import_no'] : '',
            'name' => isset($row['name']) ? (string)$row['name'] : '',
            'resident_no_masked' => CryptoHelper::maskResidentNo($residentPlain),
            'resident_no_front' => strlen(CryptoHelper::normalizeDigits($residentPlain)) >= 6 ? substr(CryptoHelper::normalizeDigits($residentPlain), 0, 6) : '',
            'birth_date' => isset($row['birth_date']) ? (string)$row['birth_date'] : '',
            'phone' => isset($row['phone']) ? (string)$row['phone'] : '',
            'phone_digits' => isset($row['phone_digits']) ? (string)$row['phone_digits'] : '',
            'address' => isset($row['address']) ? (string)$row['address'] : '',
            'job_type' => isset($row['job_type']) ? (string)$row['job_type'] : '',
            'agency_name' => isset($row['agency_name']) ? (string)$row['agency_name'] : '',
            'daily_wage' => isset($row['daily_wage']) ? (int)$row['daily_wage'] : 0,
            'account_holder' => isset($row['account_holder']) ? (string)$row['account_holder'] : '',
            'bank_name' => isset($row['bank_name']) ? (string)$row['bank_name'] : '',
            'bank_account_masked' => CryptoHelper::maskBankAccount($bankPlain),
            'memo' => isset($row['memo']) ? (string)$row['memo'] : '',
            'source_type' => isset($row['source_type']) ? (string)$row['source_type'] : 'manual',
            'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 1,
            'status' => $status,
            'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
            'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : '',
            'deleted_at' => $deletedAt,
        );

        if ($includeSensitive) {
            $out['resident_no_plain'] = $residentPlain;
            $out['bank_account_plain'] = $bankPlain;
        }

        return $out;
    }

    private function mergeMissingLegacyFields($master, $legacy)
    {
        $data = array(
            'id' => isset($master['id']) ? (int)$master['id'] : 0,
            'import_no' => isset($master['import_no']) ? $master['import_no'] : '',
            'name' => isset($master['name']) ? $master['name'] : '',
            'resident_no' => isset($master['resident_no_plain']) ? $master['resident_no_plain'] : '',
            'birth_date' => isset($master['birth_date']) ? $master['birth_date'] : '',
            'phone' => isset($master['phone']) ? $master['phone'] : '',
            'address' => isset($master['address']) ? $master['address'] : '',
            'job_type' => isset($master['job_type']) ? $master['job_type'] : '',
            'agency_name' => isset($master['agency_name']) ? $master['agency_name'] : '',
            'daily_wage' => isset($master['daily_wage']) ? (int)$master['daily_wage'] : 0,
            'account_holder' => isset($master['account_holder']) ? $master['account_holder'] : '',
            'bank_name' => isset($master['bank_name']) ? $master['bank_name'] : '',
            'bank_account' => isset($master['bank_account_plain']) ? $master['bank_account_plain'] : '',
            'memo' => isset($master['memo']) ? $master['memo'] : '',
            'source_type' => isset($master['source_type']) ? $master['source_type'] : 'legacy_project',
            'is_active' => isset($master['is_active']) ? (int)$master['is_active'] : 1,
        );

        if (trim((string)$data['resident_no']) === '' && isset($legacy['resident_no'])) $data['resident_no'] = $legacy['resident_no'];
        if (trim((string)$data['phone']) === '' && isset($legacy['phone'])) $data['phone'] = $legacy['phone'];
        if (trim((string)$data['address']) === '' && isset($legacy['address'])) $data['address'] = $legacy['address'];
        if (trim((string)$data['job_type']) === '' && isset($legacy['job_type_snapshot'])) $data['job_type'] = $legacy['job_type_snapshot'];
        if (trim((string)$data['agency_name']) === '') {
            $data['agency_name'] = isset($legacy['agency_name_snapshot']) ? $legacy['agency_name_snapshot'] : '';
            if (trim((string)$data['agency_name']) === '' && isset($legacy['company_name'])) $data['agency_name'] = $legacy['company_name'];
        }
        if ((int)$data['daily_wage'] <= 0) {
            $data['daily_wage'] = isset($legacy['daily_wage_snapshot']) ? (int)$legacy['daily_wage_snapshot'] : 0;
            if ((int)$data['daily_wage'] <= 0 && isset($legacy['deposit_rate'])) $data['daily_wage'] = (int)$legacy['deposit_rate'];
        }
        if (trim((string)$data['bank_name']) === '' && isset($legacy['bank_name'])) $data['bank_name'] = $legacy['bank_name'];
        if (trim((string)$data['bank_account']) === '' && isset($legacy['bank_account'])) $data['bank_account'] = $legacy['bank_account'];
        if (trim((string)$data['account_holder']) === '' && isset($legacy['account_holder'])) $data['account_holder'] = $legacy['account_holder'];
        return $data;
    }

    private function normalizeForSave($data)
    {
        $dailyWageRaw = isset($data['daily_wage']) ? trim((string)$data['daily_wage']) : '0';
        $dailyWageRaw = preg_replace('/[^0-9\-]/', '', $dailyWageRaw);
        $dailyWage = ($dailyWageRaw !== '' && is_numeric($dailyWageRaw)) ? (int)$dailyWageRaw : 0;
        if ($dailyWage < 0) $dailyWage = 0;

        $birthDate = isset($data['birth_date']) ? trim((string)$data['birth_date']) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) $birthDate = null;

        $phone = isset($data['phone']) ? trim((string)$data['phone']) : '';
        $phoneDigits = CryptoHelper::normalizePhoneDigits($phone);

        return array(
            'import_no' => $this->nullIfEmpty(isset($data['import_no']) ? $data['import_no'] : ''),
            'name' => trim((string)(isset($data['name']) ? $data['name'] : '')),
            'resident_no' => trim((string)(isset($data['resident_no']) ? $data['resident_no'] : '')),
            'birth_date' => $birthDate,
            'phone' => $this->nullIfEmpty($phone),
            'phone_digits' => $this->nullIfEmpty($phoneDigits),
            'address' => $this->nullIfEmpty(isset($data['address']) ? $data['address'] : ''),
            'job_type' => $this->nullIfEmpty(isset($data['job_type']) ? $data['job_type'] : ''),
            'agency_name' => $this->nullIfEmpty(isset($data['agency_name']) ? $data['agency_name'] : ''),
            'daily_wage' => $dailyWage,
            'account_holder' => $this->nullIfEmpty(isset($data['account_holder']) ? $data['account_holder'] : ''),
            'bank_name' => $this->nullIfEmpty(isset($data['bank_name']) ? $data['bank_name'] : ''),
            'bank_account' => trim((string)(isset($data['bank_account']) ? $data['bank_account'] : '')),
            'memo' => $this->nullIfEmpty(isset($data['memo']) ? $data['memo'] : ''),
            'source_type' => $this->nullIfEmpty(isset($data['source_type']) ? $data['source_type'] : 'manual'),
            'is_active' => (isset($data['is_active']) && (int)$data['is_active'] === 0) ? 0 : 1,
        );
    }

    private function nullIfEmpty($value)
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function bindAuto($st, $key, $value)
    {
        if ($value === null) {
            $st->bindValue($key, null, PDO::PARAM_NULL);
        } else if (is_int($value)) {
            $st->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $st->bindValue($key, $value);
        }
    }

    private function findRowsBySql($whereSql, $params)
    {
        $rows = array();
        $sql = "SELECT * FROM workers
                WHERE deleted_at IS NULL
                  AND is_active = 1
                  AND " . $whereSql . "
                ORDER BY id ASC
                LIMIT 20";
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->execute();
        $dbRows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dbRows as $row) {
            $rows[] = $this->formatWorker($row, false);
        }
        return $rows;
    }

    private function matchResultFromRows($rows, $matchedMessage)
    {
        if (!is_array($rows) || count($rows) === 0) {
            return array('status' => 'not_found', 'worker' => null, 'workers' => array(), 'message' => '인력관리 미등록');
        }
        if (count($rows) === 1) {
            return array('status' => 'matched', 'worker' => $rows[0], 'workers' => $rows, 'message' => $matchedMessage);
        }
        return array('status' => 'duplicate', 'worker' => null, 'workers' => $rows, 'message' => '동명이인 확인 필요');
    }
}
}
