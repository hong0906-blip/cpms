<?php
/**
 * C:\www\cpms\app\services\WorkerImportService.php
 * - 근로자 엑셀 import 미리보기/저장 흐름 담당
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/WorkerRepository.php';
require_once __DIR__ . '/ExcelWorkerImporter.php';
require_once __DIR__ . '/CryptoHelper.php';

if (!class_exists('WorkerImportService')) {
class WorkerImportService
{
    private $pdo;
    private $repo;
    private $importer;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->repo = new WorkerRepository($pdo);
        $this->importer = new ExcelWorkerImporter();
    }

    public function preview($filePath, $mapping, $defaultAgencyName)
    {
        $result = array(
            'error' => '',
            'sheet_name' => '',
            'header' => array(),
            'summary' => array(
                'total_rows' => 0,
                'importable_rows' => 0,
                'no_name_rows' => 0,
                'duplicate_rows' => 0,
                'error_rows' => 0,
            ),
            'rows' => array(),
        );

        $this->repo->ensureSchema();
        $parsed = $this->importer->parse($filePath, $mapping, $defaultAgencyName);
        if (!is_array($parsed) || (isset($parsed['error']) && $parsed['error'] !== '')) {
            $result['error'] = is_array($parsed) && isset($parsed['error']) ? (string)$parsed['error'] : '엑셀을 읽지 못했습니다.';
            return $result;
        }

        $result['sheet_name'] = isset($parsed['sheet_name']) ? (string)$parsed['sheet_name'] : '';
        $result['header'] = isset($parsed['header']) ? $parsed['header'] : array();
        $result['summary']['total_rows'] = isset($parsed['raw_total_rows']) ? (int)$parsed['raw_total_rows'] : 0;

        $seen = array();
        $rows = isset($parsed['rows']) && is_array($parsed['rows']) ? $parsed['rows'] : array();
        foreach ($rows as $parsedRow) {
            $rowNo = isset($parsedRow['row_no']) ? (int)$parsedRow['row_no'] : 0;
            $data = isset($parsedRow['data']) && is_array($parsedRow['data']) ? $parsedRow['data'] : array();
            $errors = array();
            $warnings = array();
            $statusType = 'new';
            $status = '신규 등록 가능';
            $saveable = 1;
            $duplicateWorker = null;

            $name = isset($data['name']) ? trim((string)$data['name']) : '';
            if ($name === '') {
                $statusType = 'skip';
                $status = '성명 없음 - 제외';
                $saveable = 0;
                $result['summary']['no_name_rows']++;
            }

            if ($saveable) {
                $residentHash = CryptoHelper::hashSensitive(isset($data['resident_no']) ? $data['resident_no'] : '');
                $phoneDigits = isset($data['phone_digits']) ? (string)$data['phone_digits'] : '';
                $dupKey = $this->duplicateKey($residentHash, $name, $phoneDigits);
                if ($dupKey !== '' && isset($seen[$dupKey])) {
                    $statusType = 'duplicate';
                    $status = '미리보기 안에서 중복 의심';
                    $saveable = 1;
                    $warnings[] = '같은 파일 안에서 중복된 인력일 수 있습니다.';
                    $result['summary']['duplicate_rows']++;
                } else if ($dupKey !== '') {
                    $seen[$dupKey] = true;
                }

                $duplicateWorker = $this->repo->findDuplicate($residentHash, $name, $phoneDigits, 0);
                if (is_array($duplicateWorker)) {
                    $statusType = 'update';
                    $status = '기존 인력 업데이트 후보';
                    $result['summary']['duplicate_rows']++;
                }
            }

            if (count($errors) > 0) {
                $statusType = 'error';
                $status = '오류 - ' . implode(' / ', $errors);
                $saveable = 0;
                $result['summary']['error_rows']++;
            }

            if ($saveable) {
                $result['summary']['importable_rows']++;
            }

            $result['rows'][] = array(
                'row_no' => $rowNo,
                'data' => $data,
                'status_type' => $statusType,
                'status' => $status,
                'saveable' => $saveable,
                'errors' => $errors,
                'warnings' => $warnings,
                'duplicate_worker_id' => is_array($duplicateWorker) && isset($duplicateWorker['id']) ? (int)$duplicateWorker['id'] : 0,
            );
        }

        return $result;
    }

    public function process($filePath, $mapping, $defaultAgencyName, $updateDuplicate, $userId, $originalFilename, $storedFilename)
    {
        $this->repo->ensureSchema();
        $preview = $this->preview($filePath, $mapping, $defaultAgencyName);
        $summary = isset($preview['summary']) && is_array($preview['summary']) ? $preview['summary'] : array();
        $totalRows = isset($summary['total_rows']) ? (int)$summary['total_rows'] : 0;
        $successRows = 0;
        $updateRows = 0;
        $skipRows = 0;
        $errorRows = 0;

        if (isset($preview['error']) && $preview['error'] !== '') {
            return array('ok' => 0, 'message' => $preview['error']);
        }

        if (!$this->pdo) {
            return array('ok' => 0, 'message' => 'DB 연결 실패');
        }

        $now = date('Y-m-d H:i:s');
        try {
            $this->pdo->beginTransaction();

            $stBatch = $this->pdo->prepare("INSERT INTO worker_import_batches
                (original_filename, stored_filename, total_rows, success_rows, update_rows, skip_rows, error_rows, uploaded_by, uploaded_at)
                VALUES
                (:original_filename, :stored_filename, :total_rows, 0, 0, 0, 0, :uploaded_by, :uploaded_at)");
            $stBatch->bindValue(':original_filename', (string)$originalFilename);
            $stBatch->bindValue(':stored_filename', (string)$storedFilename);
            $stBatch->bindValue(':total_rows', (int)$totalRows, PDO::PARAM_INT);
            if ((int)$userId > 0) $stBatch->bindValue(':uploaded_by', (int)$userId, PDO::PARAM_INT);
            else $stBatch->bindValue(':uploaded_by', null, PDO::PARAM_NULL);
            $stBatch->bindValue(':uploaded_at', $now);
            $stBatch->execute();
            $batchId = (int)$this->pdo->lastInsertId();

            $rows = isset($preview['rows']) && is_array($preview['rows']) ? $preview['rows'] : array();
            foreach ($rows as $row) {
                $rowNo = isset($row['row_no']) ? (int)$row['row_no'] : 0;
                $data = isset($row['data']) && is_array($row['data']) ? $row['data'] : array();
                $statusType = isset($row['status_type']) ? (string)$row['status_type'] : '';
                $saveable = isset($row['saveable']) ? (int)$row['saveable'] : 0;

                if (!$saveable || $statusType === 'skip') {
                    $skipRows++;
                    continue;
                }

                if ($statusType === 'error') {
                    $errorRows++;
                    $this->insertError($batchId, $rowNo, isset($row['status']) ? (string)$row['status'] : '오류', $data);
                    continue;
                }

                $residentHash = CryptoHelper::hashSensitive(isset($data['resident_no']) ? $data['resident_no'] : '');
                $duplicate = $this->repo->findDuplicate(
                    $residentHash,
                    isset($data['name']) ? $data['name'] : '',
                    isset($data['phone_digits']) ? $data['phone_digits'] : '',
                    0
                );

                if (is_array($duplicate) && isset($duplicate['id'])) {
                    if (!$updateDuplicate) {
                        $skipRows++;
                        continue;
                    }
                    $data['id'] = (int)$duplicate['id'];
                    $savedId = $this->repo->save($data, (int)$userId);
                    if ($savedId > 0) $updateRows++;
                    else {
                        $errorRows++;
                        $this->insertError($batchId, $rowNo, '기존 인력 업데이트 실패', $data);
                    }
                    continue;
                }

                $savedId2 = $this->repo->save($data, (int)$userId);
                if ($savedId2 > 0) {
                    $successRows++;
                } else {
                    $errorRows++;
                    $this->insertError($batchId, $rowNo, '신규 인력 저장 실패', $data);
                }
            }

            $stUpdate = $this->pdo->prepare("UPDATE worker_import_batches
                                            SET success_rows = :success_rows,
                                                update_rows = :update_rows,
                                                skip_rows = :skip_rows,
                                                error_rows = :error_rows
                                            WHERE id = :id");
            $stUpdate->bindValue(':success_rows', (int)$successRows, PDO::PARAM_INT);
            $stUpdate->bindValue(':update_rows', (int)$updateRows, PDO::PARAM_INT);
            $stUpdate->bindValue(':skip_rows', (int)$skipRows, PDO::PARAM_INT);
            $stUpdate->bindValue(':error_rows', (int)$errorRows, PDO::PARAM_INT);
            $stUpdate->bindValue(':id', (int)$batchId, PDO::PARAM_INT);
            $stUpdate->execute();

            $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo && $this->pdo->inTransaction()) $this->pdo->rollBack();
            return array('ok' => 0, 'message' => '엑셀 import 실패: ' . $e->getMessage());
        }

        return array(
            'ok' => 1,
            'batch_id' => $batchId,
            'success_rows' => $successRows,
            'update_rows' => $updateRows,
            'skip_rows' => $skipRows,
            'error_rows' => $errorRows,
        );
    }

    private function duplicateKey($residentHash, $name, $phoneDigits)
    {
        $residentHash = trim((string)$residentHash);
        if ($residentHash !== '') return 'resident:' . $residentHash;
        $name = trim((string)$name);
        $phoneDigits = trim((string)$phoneDigits);
        if ($name !== '' && $phoneDigits !== '') return 'name_phone:' . $name . ':' . $phoneDigits;
        return '';
    }

    private function insertError($batchId, $rowNo, $message, $raw)
    {
        try {
            $options = 0;
            if (defined('JSON_UNESCAPED_UNICODE')) $options = JSON_UNESCAPED_UNICODE;
            $rawJson = json_encode($raw, $options);
            $st = $this->pdo->prepare("INSERT INTO worker_import_errors
                (batch_id, row_no, error_message, raw_json, created_at)
                VALUES (:batch_id, :row_no, :error_message, :raw_json, :created_at)");
            $st->bindValue(':batch_id', (int)$batchId, PDO::PARAM_INT);
            $st->bindValue(':row_no', (int)$rowNo, PDO::PARAM_INT);
            $st->bindValue(':error_message', (string)$message);
            $st->bindValue(':raw_json', (string)$rawJson);
            $st->bindValue(':created_at', date('Y-m-d H:i:s'));
            $st->execute();
        } catch (Exception $e) {
            // 오류 로그 저장 실패가 전체 import를 막지 않게 둔다.
        }
    }
}
}
