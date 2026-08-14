<?php
/**
 * 직영팀 인원을 기존 현장별 단가를 유지한 일용직으로 전환합니다.
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/WorkerRepository.php';

if (!class_exists('DirectTeamConversionService')) {
class DirectTeamConversionService
{
    private $pdo;
    private $workerRepository;
    private $schemaReady = false;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->workerRepository = new WorkerRepository($pdo);
    }

    public function prepareSchema()
    {
        $this->schemaReady = $this->workerRepository->ensureSchema();
        return $this->schemaReady;
    }

    public function convertAndDelete($memberId, $userId)
    {
        $memberId = (int)$memberId;
        $userId = (int)$userId;
        if (!$this->pdo || $memberId <= 0) {
            throw new Exception('삭제 대상이 올바르지 않습니다.');
        }

        if (!$this->schemaReady) {
            if ($this->pdo->inTransaction()) {
                throw new Exception('일용직 전환 준비가 완료되지 않았습니다. 트랜잭션 시작 전에 prepareSchema()을 실행해 주세요.');
            }
            if (!$this->prepareSchema()) {
                throw new Exception('일용직 인력관리 테이블을 준비하지 못했습니다.');
            }
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();

        try {
            $stMember = $this->pdo->prepare("SELECT * FROM direct_team_members WHERE id = :id LIMIT 1 FOR UPDATE");
            $stMember->bindValue(':id', $memberId, PDO::PARAM_INT);
            $stMember->execute();
            $member = $stMember->fetch(PDO::FETCH_ASSOC);
            if (!$member) throw new Exception('삭제할 직영팀 인원을 찾을 수 없습니다.');

            $laborRows = $this->loadLaborRows($memberId);
            $fallbackWorkerId = count($laborRows) > 0
                ? $this->resolveDailyWorkerId($member, $laborRows, $userId)
                : 0;
            $convertedRows = 0;

            foreach ($laborRows as $laborRow) {
                $rowId = isset($laborRow['id']) ? (int)$laborRow['id'] : 0;
                if ($rowId <= 0) continue;

                $rowWorkerId = isset($laborRow['worker_id']) ? (int)$laborRow['worker_id'] : 0;
                if (!$this->isUsableWorker($rowWorkerId, isset($member['name']) ? $member['name'] : '')) {
                    $rowWorkerId = $fallbackWorkerId;
                }
                $rate = $this->resolvePreservedRate($laborRow, $member);
                $this->convertLaborRow($laborRow, $member, $rowWorkerId, $rate);
                $convertedRows++;
            }

            $stDelete = $this->pdo->prepare("DELETE FROM direct_team_members WHERE id = :id");
            $stDelete->bindValue(':id', $memberId, PDO::PARAM_INT);
            $stDelete->execute();
            if ($stDelete->rowCount() !== 1) {
                throw new Exception('직영팀 명부에서 삭제하지 못했습니다.');
            }

            if ($ownsTransaction) $this->pdo->commit();

            return array(
                'member_id' => $memberId,
                'member_name' => isset($member['name']) ? (string)$member['name'] : '',
                'photo_path' => isset($member['photo_path']) ? (string)$member['photo_path'] : '',
                'worker_id' => $fallbackWorkerId,
                'converted_rows' => $convertedRows,
            );
        } catch (Exception $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function loadLaborRows($memberId)
    {
        if (!$this->tableExists('cpms_project_labor_workers')
            || !$this->columnExists('cpms_project_labor_workers', 'direct_member_id')) {
            return array();
        }

        $st = $this->pdo->prepare("SELECT * FROM cpms_project_labor_workers
                                   WHERE direct_member_id = :member_id
                                   ORDER BY id ASC
                                   FOR UPDATE");
        $st->bindValue(':member_id', (int)$memberId, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    private function resolveDailyWorkerId($member, $laborRows, $userId)
    {
        $memberName = isset($member['name']) ? trim((string)$member['name']) : '';
        foreach ($laborRows as $laborRow) {
            $workerId = isset($laborRow['worker_id']) ? (int)$laborRow['worker_id'] : 0;
            if ($this->isUsableWorker($workerId, $memberName)) {
                $this->fillMissingMasterFields($workerId, $member, $laborRows, $userId);
                return $workerId;
            }
        }

        $residentNo = isset($member['resident_no']) ? trim((string)$member['resident_no']) : '';
        $phone = isset($member['phone']) ? trim((string)$member['phone']) : '';
        $match = $this->workerRepository->matchWorker($memberName, $phone, $residentNo);
        if (isset($match['status']) && $match['status'] === 'matched'
            && isset($match['worker']['id']) && (int)$match['worker']['id'] > 0) {
            $matchedWorkerId = (int)$match['worker']['id'];
            $this->fillMissingMasterFields($matchedWorkerId, $member, $laborRows, $userId);
            return $matchedWorkerId;
        }

        $sampleRow = count($laborRows) > 0 && is_array($laborRows[0]) ? $laborRows[0] : array();
        $rate = isset($member['deposit_rate']) ? (int)$member['deposit_rate'] : 0;
        if ($rate <= 0 && isset($member['daily_wage'])) $rate = (int)$member['daily_wage'];
        if ($rate <= 0) $rate = $this->resolvePreservedRate($sampleRow, $member);
        $agencyName = isset($sampleRow['agency_name_snapshot']) ? trim((string)$sampleRow['agency_name_snapshot']) : '';
        if ($agencyName === '' && isset($sampleRow['company_name'])) $agencyName = trim((string)$sampleRow['company_name']);

        return (int)$this->workerRepository->save(array(
            'name' => $memberName,
            'resident_no' => $residentNo,
            'phone' => $phone,
            'address' => isset($member['address']) ? (string)$member['address'] : '',
            'job_type' => '일용직',
            'agency_name' => $agencyName,
            'daily_wage' => $rate,
            'bank_name' => isset($member['bank_name']) ? (string)$member['bank_name'] : '',
            'bank_account' => isset($member['bank_account']) ? (string)$member['bank_account'] : '',
            'account_holder' => isset($member['account_holder']) ? (string)$member['account_holder'] : '',
            'memo' => '직영팀에서 일용직으로 전환',
            'source_type' => 'direct_team_converted',
            'is_active' => 1,
        ), $userId);
    }

    private function fillMissingMasterFields($workerId, $member, $laborRows, $userId)
    {
        $master = $this->workerRepository->getById((int)$workerId, true);
        if (!$master || !is_array($master)) return;
        $sampleRow = count($laborRows) > 0 && is_array($laborRows[0]) ? $laborRows[0] : array();
        $rate = isset($master['daily_wage']) ? (int)$master['daily_wage'] : 0;
        if ($rate <= 0) {
            $memberRate = isset($member['deposit_rate']) ? (int)$member['deposit_rate'] : 0;
            if ($memberRate <= 0 && isset($member['daily_wage'])) $memberRate = (int)$member['daily_wage'];
            $rate = $memberRate > 0 ? $memberRate : $this->resolvePreservedRate($sampleRow, $member);
        }
        $agencyName = isset($master['agency_name']) ? trim((string)$master['agency_name']) : '';
        if ($agencyName === '' && isset($sampleRow['agency_name_snapshot'])) $agencyName = trim((string)$sampleRow['agency_name_snapshot']);
        if ($agencyName === '' && isset($sampleRow['company_name'])) $agencyName = trim((string)$sampleRow['company_name']);

        $residentNo = isset($master['resident_no_plain']) ? trim((string)$master['resident_no_plain']) : '';
        if ($residentNo === '' && isset($member['resident_no'])) $residentNo = trim((string)$member['resident_no']);
        $bankAccount = isset($master['bank_account_plain']) ? trim((string)$master['bank_account_plain']) : '';
        if ($bankAccount === '' && isset($member['bank_account'])) $bankAccount = trim((string)$member['bank_account']);

        $this->workerRepository->save(array(
            'id' => (int)$workerId,
            'import_no' => isset($master['import_no']) ? $master['import_no'] : '',
            'name' => isset($master['name']) ? $master['name'] : (isset($member['name']) ? $member['name'] : ''),
            'resident_no' => $residentNo,
            'birth_date' => isset($master['birth_date']) ? $master['birth_date'] : '',
            'phone' => isset($master['phone']) && trim((string)$master['phone']) !== '' ? $master['phone'] : (isset($member['phone']) ? $member['phone'] : ''),
            'address' => isset($master['address']) && trim((string)$master['address']) !== '' ? $master['address'] : (isset($member['address']) ? $member['address'] : ''),
            'job_type' => isset($master['job_type']) && trim((string)$master['job_type']) !== '' ? $master['job_type'] : '일용직',
            'agency_name' => $agencyName,
            'daily_wage' => $rate,
            'account_holder' => isset($master['account_holder']) && trim((string)$master['account_holder']) !== '' ? $master['account_holder'] : (isset($member['account_holder']) ? $member['account_holder'] : ''),
            'bank_name' => isset($master['bank_name']) && trim((string)$master['bank_name']) !== '' ? $master['bank_name'] : (isset($member['bank_name']) ? $member['bank_name'] : ''),
            'bank_account' => $bankAccount,
            'memo' => isset($master['memo']) ? $master['memo'] : '',
            'source_type' => isset($master['source_type']) ? $master['source_type'] : 'direct_team_converted',
            'is_active' => 1,
        ), $userId);
    }

    private function isUsableWorker($workerId, $memberName)
    {
        $workerId = (int)$workerId;
        if ($workerId <= 0) return false;
        $worker = $this->workerRepository->getById($workerId, false);
        if (!$worker || !is_array($worker)) return false;
        if (!isset($worker['status']) || (string)$worker['status'] !== 'active') return false;
        $workerName = isset($worker['name']) ? trim((string)$worker['name']) : '';
        $memberName = trim((string)$memberName);
        return $memberName === '' || $workerName === '' || $workerName === $memberName;
    }

    private function resolvePreservedRate($laborRow, $member)
    {
        $rate = isset($laborRow['daily_wage_snapshot']) ? (int)$laborRow['daily_wage_snapshot'] : 0;
        if ($rate <= 0 && isset($laborRow['deposit_rate'])) $rate = (int)$laborRow['deposit_rate'];
        if ($rate <= 0 && isset($member['deposit_rate'])) $rate = (int)$member['deposit_rate'];
        if ($rate <= 0 && isset($member['daily_wage'])) $rate = (int)$member['daily_wage'];
        return $rate > 0 ? $rate : 0;
    }

    private function convertLaborRow($laborRow, $member, $workerId, $rate)
    {
        $columns = $this->tableColumns('cpms_project_labor_workers');
        $sets = array('direct_member_id = NULL');
        $params = array(':id' => (int)$laborRow['id']);

        if (isset($columns['source'])) $sets[] = (int)$workerId > 0 ? "source = 'workforce'" : "source = 'manual'";
        if (isset($columns['worker_id'])) {
            $sets[] = 'worker_id = :worker_id';
            $params[':worker_id'] = (int)$workerId > 0 ? (int)$workerId : null;
        }
        if (isset($columns['source_type'])) $sets[] = "source_type = 'direct_team_converted'";
        if (isset($columns['matched_status'])) $sets[] = (int)$workerId > 0 ? "matched_status = 'matched'" : "matched_status = 'manual'";
        if (isset($columns['worker_name_snapshot'])) {
            $sets[] = 'worker_name_snapshot = :worker_name_snapshot';
            $params[':worker_name_snapshot'] = isset($member['name']) ? trim((string)$member['name']) : '';
        }
        if (isset($columns['daily_wage_snapshot'])) {
            $sets[] = 'daily_wage_snapshot = :daily_wage_snapshot';
            $params[':daily_wage_snapshot'] = (int)$rate;
        }
        if (isset($columns['deposit_rate'])) {
            $sets[] = 'deposit_rate = :deposit_rate';
            $params[':deposit_rate'] = (int)$rate;
        }
        if (isset($columns['job_type_snapshot'])) {
            $sets[] = "job_type_snapshot = CASE WHEN job_type_snapshot IS NULL OR TRIM(job_type_snapshot) = '' THEN '일용직' ELSE job_type_snapshot END";
        }

        $this->appendMissingValue($sets, $params, $columns, 'resident_no', isset($member['resident_no']) ? $member['resident_no'] : '');
        $this->appendMissingValue($sets, $params, $columns, 'phone', isset($member['phone']) ? $member['phone'] : '');
        $this->appendMissingValue($sets, $params, $columns, 'address', isset($member['address']) ? $member['address'] : '');
        $this->appendMissingValue($sets, $params, $columns, 'bank_account', isset($member['bank_account']) ? $member['bank_account'] : '');
        $this->appendMissingValue($sets, $params, $columns, 'bank_name', isset($member['bank_name']) ? $member['bank_name'] : '');
        $this->appendMissingValue($sets, $params, $columns, 'account_holder', isset($member['account_holder']) ? $member['account_holder'] : '');

        if (isset($columns['updated_at'])) {
            $sets[] = 'updated_at = :updated_at';
            $params[':updated_at'] = date('Y-m-d H:i:s');
        }

        $st = $this->pdo->prepare("UPDATE cpms_project_labor_workers SET " . implode(', ', $sets) . " WHERE id = :id");
        foreach ($params as $key => $value) $this->bindAuto($st, $key, $value);
        $st->execute();
    }

    private function appendMissingValue(&$sets, &$params, $columns, $column, $value)
    {
        $value = trim((string)$value);
        if ($value === '' || !isset($columns[$column])) return;
        $param = ':' . $column;
        $sets[] = $column . " = CASE WHEN " . $column . " IS NULL OR TRIM(" . $column . ") = '' THEN " . $param . " ELSE " . $column . " END";
        $params[$param] = $value;
    }

    private function tableExists($table)
    {
        try {
            $st = $this->pdo->prepare('SHOW TABLES LIKE :table_name');
            $st->bindValue(':table_name', (string)$table);
            $st->execute();
            return (bool)$st->fetch(PDO::FETCH_NUM);
        } catch (Exception $e) {
            throw new Exception('노무비 연결 테이블을 확인하지 못했습니다: ' . $e->getMessage());
        }
    }

    private function columnExists($table, $column)
    {
        $columns = $this->tableColumns($table);
        return isset($columns[(string)$column]);
    }

    private function tableColumns($table)
    {
        static $cache = array();
        $table = (string)$table;
        if (isset($cache[$table])) return $cache[$table];
        $columns = array();
        try {
            $st = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['Field'])) $columns[(string)$row['Field']] = true;
            }
        } catch (Exception $e) {
            throw new Exception('노무비 연결 컬럼을 확인하지 못했습니다: ' . $e->getMessage());
        }
        $cache[$table] = $columns;
        return $columns;
    }

    private function bindAuto($st, $key, $value)
    {
        if ($value === null) $st->bindValue($key, null, PDO::PARAM_NULL);
        else if (is_int($value)) $st->bindValue($key, $value, PDO::PARAM_INT);
        else $st->bindValue($key, $value);
    }
}
}
