<?php
/**
 * 파일경로: app/services/ApprovalCostCorrectionService.php
 * 기능: 전자결재 > 노무비/장비비/외주비/투입비 수정·누락 신청 공통 서비스
 * 기준: PHP 5.6 호환
 *
 * 핵심 원칙
 * - 기존 전자결재(cpms_approval_*)를 그대로 사용합니다.
 * - 기존 비용변경 서비스(CostChangeService)의 실제 반영 로직을 재사용합니다.
 * - 노무비는 기존 cpms_labor_gongsu_overrides 체계에 최종승인 값을 추가합니다.
 * - 노무비를 제외한 모든 비용은 통합 업체관리(cpms_vendors)에서 선택한 업체만 허용합니다.
 * - 최종승인된 문서를 취소해도 이미 반영된 원가 데이터는 되돌리지 않습니다.
 */

namespace App\Services;

use PDO;
use Exception;

require_once __DIR__ . '/CostChangeService.php';
require_once __DIR__ . '/VendorService.php';
require_once __DIR__ . '/ApprovalDriveService.php';

class ApprovalCostCorrectionService
{
    const MARKER_KEY = 'cpms_cost_correction';
    const MARKER_VALUE = '1';

    const FORM_LABOR = 'labor';
    const FORM_EQUIPMENT = 'equipment';
    const FORM_OUTSOURCING = 'outsourcing';
    const FORM_INPUT = 'input';

    const MODE_ADD = 'ADD';
    const MODE_MODIFY = 'MODIFY';

    // 이번 신청의 실제 변동금액이 100만원 이상이면 대표 결재를 추가합니다.
    const CEO_APPROVAL_THRESHOLD = 1000000;

    /**
     * 전자결재 양식 종류 목록
     */
    public static function formLabels()
    {
        return array(
            self::FORM_LABOR => '노무비 수정/누락 신청',
            self::FORM_EQUIPMENT => '장비비 수정/누락 신청',
            self::FORM_OUTSOURCING => '외주비 수정/누락 신청',
            self::FORM_INPUT => '자재비·안전관리비·기타경비·구매품 수정/누락 신청'
        );
    }

    public static function formLabel($formType)
    {
        $labels = self::formLabels();
        $formType = strtolower(trim((string)$formType));
        return isset($labels[$formType]) ? $labels[$formType] : '비용 수정/누락 신청';
    }

    public static function validFormType($formType)
    {
        $formType = strtolower(trim((string)$formType));
        $labels = self::formLabels();
        return isset($labels[$formType]);
    }

    public static function validInputCategories()
    {
        return array(
            '자재비' => true,
            '안전관리비' => true,
            '기타경비' => true,
            '구매품' => true
        );
    }

    public static function jsonEncode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = json_encode($value);
        }
        return is_string($json) ? $json : '{}';
    }

    public static function jsonDecode($value)
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return array();
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    public static function isCorrectionContent($content)
    {
        $content = self::jsonDecode($content);
        return isset($content[self::MARKER_KEY])
            && (string)$content[self::MARKER_KEY] === self::MARKER_VALUE;
    }

    private static function validDate($value)
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
        $parts = explode('-', $value);
        if (count($parts) !== 3) return '';
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) return '';
        return $value;
    }

    private static function money($value)
    {
        $raw = str_replace(array(',', ' ', "\t", '원'), '', trim((string)$value));
        $raw = preg_replace('/[^0-9.\-]/', '', $raw);
        if ($raw === '' || !is_numeric($raw)) return 0.0;
        return (float)$raw;
    }

    private static function numeric($value, $default)
    {
        $raw = str_replace(',', '', trim((string)$value));
        if ($raw === '' || !is_numeric($raw)) return (float)$default;
        return (float)$raw;
    }

    private static function safeText($value, $maxLength)
    {
        $value = trim((string)$value);
        if ($maxLength <= 0) return $value;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    }

    /**
     * 노무비 대표 결재 기준 계산에 사용할 선택월 임금단가를 기존 노무비 화면과 같은 기준으로 구합니다.
     */
    private static function laborWageRateFromWorker($worker)
    {
        if (!is_array($worker)) return 0.0;

        if (function_exists('cpms_resolve_labor_wage_rate')) {
            try {
                $rate = (float)cpms_resolve_labor_wage_rate($worker);
                if ($rate > 0) return $rate;
            } catch (Exception $e) {
            }
        }

        $keys = array('salary_daily_rate', 'deposit_rate', 'daily_wage', 'wage_rate', 'wage', 'unit_price');
        for ($i = 0; $i < count($keys); $i++) {
            if (!isset($worker[$keys[$i]])) continue;
            $rate = self::money($worker[$keys[$i]]);
            if ($rate > 0) return $rate;
        }

        if (isset($worker['data']) && is_array($worker['data'])) {
            for ($i = 0; $i < count($keys); $i++) {
                if (!isset($worker['data'][$keys[$i]])) continue;
                $rate = self::money($worker['data'][$keys[$i]]);
                if ($rate > 0) return $rate;
            }
        }
        return 0.0;
    }

    private static function tableExists($pdo, $table)
    {
        if (!$pdo || trim((string)$table) === '') return false;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:tbl");
            $st->execute(array(':tbl' => (string)$table));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }

    private static function tableColumns($pdo, $table)
    {
        $result = array();
        if (!$pdo || trim((string)$table) === '') return $result;
        try {
            $st = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', (string)$table) . "`");
            if (!$st) return $result;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['Field'])) $result[(string)$row['Field']] = true;
            }
        } catch (Exception $e) {
            return array();
        }
        return $result;
    }

    /**
     * 사용 가능한 프로젝트 목록
     */
    public static function projects($pdo)
    {
        if (!$pdo || !self::tableExists($pdo, 'cpms_projects')) return array();
        try {
            $sql = "SELECT id,name FROM cpms_projects";
            $columns = self::tableColumns($pdo, 'cpms_projects');
            if (isset($columns['is_deleted'])) {
                $sql .= " WHERE COALESCE(is_deleted,0)=0";
            }
            $sql .= " ORDER BY name ASC,id ASC";
            $st = $pdo->query($sql);
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function project($pdo, $projectId)
    {
        $projectId = (int)$projectId;
        if (!$pdo || $projectId <= 0 || !self::tableExists($pdo, 'cpms_projects')) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id=:id LIMIT 1");
            $st->execute(array(':id' => $projectId));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 통합 업체관리 목록. 직접입력은 허용하지 않습니다.
     */
    public static function vendors($pdo)
    {
        if (!$pdo) return array();
        try {
            VendorService::bootstrap($pdo, true);
            $rows = VendorService::listVendors($pdo, '', 1000);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * 업체 검색 자동완성용 목록
     * - 관리 > 업체관리에 등록된 활성 업체만 반환
     * - 화면에서 업체명을 직접 저장하지 않고 vendor_id 선택을 강제하기 위해 사용
     */
    public static function searchVendors($pdo, $query, $limit)
    {
        if (!$pdo) return array();
        $query = trim((string)$query);
        $limit = (int)$limit;
        if ($limit <= 0 || $limit > 50) $limit = 20;
        try {
            VendorService::bootstrap($pdo, true);
            $rows = VendorService::listVendors($pdo, $query, $limit);
            if (!is_array($rows)) return array();
            $result = array();
            for ($i = 0; $i < count($rows); $i++) {
                $row = $rows[$i];
                if (isset($row['is_active']) && (int)$row['is_active'] !== 1) continue;
                $result[] = array(
                    'id' => isset($row['id']) ? (int)$row['id'] : 0,
                    'name' => isset($row['name']) ? trim((string)$row['name']) : '',
                    'business_no' => isset($row['business_no']) ? trim((string)$row['business_no']) : '',
                    'representative' => isset($row['representative']) ? trim((string)$row['representative']) : ''
                );
            }
            return $result;
        } catch (Exception $e) {
            return array();
        }
    }

    public static function vendor($pdo, $vendorId)
    {
        $vendorId = (int)$vendorId;
        if (!$pdo || $vendorId <= 0) return null;
        try {
            VendorService::bootstrap($pdo, true);
            $row = VendorService::getById($pdo, $vendorId);
            if (!is_array($row)) return null;
            if (isset($row['is_active']) && (int)$row['is_active'] !== 1) return null;
            return $row;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 전자결재 고정 결재선
     * 기본: 팀장 -> 관리 -> 공사PM -> 부사장
     * 작성자가 팀장: 관리 -> 공사PM -> 부사장
     * 신청 변동금액 100만원 이상: 마지막에 대표 추가
     */
    public static function buildApprovalLines($pdo, $user, $includeCeo = false)
    {
        $result = array('ok' => false, 'creator' => null, 'lines' => array(), 'message' => '');
        $lineRules = dirname(__DIR__) . '/views/approval/line_rules.php';
        if (is_file($lineRules)) require_once $lineRules;

        $requiredFunctions = array(
            'approval_line_rules_current_employee',
            'approval_line_rules_is_team_leader_candidate',
            'approval_line_rules_find_team_leader',
            'approval_line_rules_find_manage_approver',
            'approval_line_rules_find_construction_pm',
            'approval_line_rules_find_vp'
        );
        if ($includeCeo) $requiredFunctions[] = 'approval_line_rules_find_ceo';
        for ($i = 0; $i < count($requiredFunctions); $i++) {
            if (!function_exists($requiredFunctions[$i])) {
                $result['message'] = '전자결재 결재선 기능을 불러오지 못했습니다.';
                return $result;
            }
        }

        $creator = approval_line_rules_current_employee($pdo, $user);
        if (!is_array($creator) || !isset($creator['id']) || (int)$creator['id'] <= 0) {
            $result['message'] = '작성자 직원정보를 확인할 수 없습니다.';
            return $result;
        }
        $result['creator'] = $creator;

        $lines = array();
        $isTeamLeader = approval_line_rules_is_team_leader_candidate($creator);
        if (!$isTeamLeader) {
            $teamResult = approval_line_rules_find_team_leader($pdo, $creator);
            $team = is_array($teamResult) && isset($teamResult['employee']) ? $teamResult['employee'] : null;
            if (!is_array($team) || !isset($team['id']) || (int)$team['id'] <= 0) {
                $result['message'] = '팀장 결재자를 찾을 수 없습니다. 직원명부의 팀장 설정을 확인해주세요.';
                return $result;
            }
            $lines[] = array('role' => '팀장', 'emp' => $team);
        }

        $manage = approval_line_rules_find_manage_approver($pdo);
        if (!is_array($manage) || !isset($manage['id']) || (int)$manage['id'] <= 0) {
            $result['message'] = '관리 결재자를 찾을 수 없습니다. 전자결재 설정을 확인해주세요.';
            return $result;
        }
        $lines[] = array('role' => '관리', 'emp' => $manage);

        $pm = approval_line_rules_find_construction_pm($pdo);
        if (!is_array($pm) || !isset($pm['id']) || (int)$pm['id'] <= 0) {
            $result['message'] = '공사PM 결재자를 찾을 수 없습니다. 전자결재 설정을 확인해주세요.';
            return $result;
        }
        $lines[] = array('role' => 'PM', 'emp' => $pm);

        $vp = approval_line_rules_find_vp($pdo);
        if (!is_array($vp) || !isset($vp['id']) || (int)$vp['id'] <= 0) {
            $result['message'] = '부사장 결재자를 찾을 수 없습니다. 전자결재 설정을 확인해주세요.';
            return $result;
        }
        $lines[] = array('role' => '부사장', 'emp' => $vp);

        if ($includeCeo) {
            $ceo = function_exists('approval_line_rules_find_ceo')
                ? approval_line_rules_find_ceo($pdo)
                : null;
            if (!is_array($ceo) || !isset($ceo['id']) || (int)$ceo['id'] <= 0) {
                $result['message'] = '100만원 이상 비용 신청에 필요한 대표 결재자를 찾을 수 없습니다. 전자결재 대표 설정을 확인해주세요.';
                return $result;
            }
            $lines[] = array('role' => '대표', 'emp' => $ceo);
        }

        $result['ok'] = true;
        $result['lines'] = $lines;
        return $result;
    }

    public static function ceoApprover($pdo)
    {
        $lineRules = dirname(__DIR__) . '/views/approval/line_rules.php';
        if (is_file($lineRules)) require_once $lineRules;
        if (!function_exists('approval_line_rules_find_ceo')) return null;
        try {
            $ceo = approval_line_rules_find_ceo($pdo);
            return (is_array($ceo) && isset($ceo['id']) && (int)$ceo['id'] > 0) ? $ceo : null;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function approvalLineText($lines)
    {
        $parts = array();
        if (!is_array($lines)) return '';
        for ($i = 0; $i < count($lines); $i++) {
            $role = isset($lines[$i]['role']) ? trim((string)$lines[$i]['role']) : '';
            $emp = isset($lines[$i]['emp']) && is_array($lines[$i]['emp']) ? $lines[$i]['emp'] : array();
            $name = isset($emp['name']) ? trim((string)$emp['name']) : '';
            if ($role !== '' && $name !== '') $parts[] = $role . ' ' . $name;
        }
        return implode(' -> ', $parts);
    }


    /**
     * 비용 수정/누락 문서의 첨부파일 목록
     */
    public static function approvalFilesForDocument($pdo, $documentId)
    {
        $documentId = (int)$documentId;
        if (!$pdo || $documentId <= 0 || !self::tableExists($pdo, 'cpms_approval_files')) return array();
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_approval_files WHERE document_id=:id ORDER BY id ASC");
            $st->execute(array(':id' => $documentId));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    private static function findResubmitChild($pdo, $sourceDocumentId)
    {
        $sourceDocumentId = (int)$sourceDocumentId;
        if (!$pdo || $sourceDocumentId <= 0 || !self::tableExists($pdo, 'cpms_approval_documents')) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE content LIKE :needle_comma OR content LIKE :needle_end ORDER BY id DESC LIMIT 10");
            $st->execute(array(
                ':needle_comma' => '%\"resubmit_source_id\":' . $sourceDocumentId . ',%',
                ':needle_end' => '%\"resubmit_source_id\":' . $sourceDocumentId . '}%'
            ));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) return null;
            for ($i = 0; $i < count($rows); $i++) {
                $content = self::jsonDecode(isset($rows[$i]['content']) ? $rows[$i]['content'] : '');
                if (isset($content['resubmit_source_id']) && (int)$content['resubmit_source_id'] === $sourceDocumentId) {
                    return $rows[$i];
                }
            }
        } catch (Exception $e) {
        }
        return null;
    }

    /**
     * 반려된 비용 수정/누락 문서를 재상신할 수 있는지 검증하고 원문 내용을 반환합니다.
     */
    public static function resubmitSource($pdo, $user, $documentId)
    {
        $documentId = (int)$documentId;
        if (!$pdo || $documentId <= 0) throw new Exception('재상신할 문서를 확인할 수 없습니다.');

        $st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
        $st->execute(array(':id' => $documentId));
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($doc)) throw new Exception('재상신할 문서를 찾을 수 없습니다.');

        $content = self::jsonDecode(isset($doc['content']) ? $doc['content'] : '');
        if (!self::isCorrectionContent($content)) throw new Exception('비용 수정/누락 신청 문서만 이 화면에서 재상신할 수 있습니다.');

        $status = strtoupper(trim((string)(isset($doc['doc_status']) ? $doc['doc_status'] : '')));
        if ($status !== 'REJECTED') throw new Exception('반려된 비용 수정/누락 신청 문서만 수정 후 재상신할 수 있습니다.');

        $lineResult = self::buildApprovalLines($pdo, $user, false);
        if (empty($lineResult['ok']) || !isset($lineResult['creator']) || !is_array($lineResult['creator'])) {
            throw new Exception(isset($lineResult['message']) ? $lineResult['message'] : '현재 작성자 정보를 확인할 수 없습니다.');
        }
        $creator = $lineResult['creator'];
        $creatorId = isset($creator['id']) ? (int)$creator['id'] : 0;
        $creatorEmail = isset($creator['email']) ? strtolower(trim((string)$creator['email'])) : '';
        $docCreatorId = isset($doc['created_by_id']) ? (int)$doc['created_by_id'] : 0;
        $docCreatorEmail = isset($doc['created_by_email']) ? strtolower(trim((string)$doc['created_by_email'])) : '';
        $isOwner = ($creatorId > 0 && $docCreatorId > 0 && $creatorId === $docCreatorId)
            || ($creatorEmail !== '' && $docCreatorEmail !== '' && $creatorEmail === $docCreatorEmail);
        if (!$isOwner) throw new Exception('본인이 작성한 반려 문서만 수정 후 재상신할 수 있습니다.');

        $child = self::findResubmitChild($pdo, $documentId);
        if (is_array($child) && isset($child['id']) && (int)$child['id'] > 0) {
            throw new Exception('이미 재상신된 문서가 있습니다. 재상신 문서 #' . (int)$child['id'] . '를 확인해주세요.');
        }

        $sourceRevision = isset($content['resubmit_revision']) ? (int)$content['resubmit_revision'] : 0;
        if ($sourceRevision <= 0) $sourceRevision = 1;
        $rootId = isset($content['resubmit_root_id']) ? (int)$content['resubmit_root_id'] : 0;
        if ($rootId <= 0) $rootId = $documentId;

        return array(
            'ok' => true,
            'document' => $doc,
            'content' => $content,
            'files' => self::approvalFilesForDocument($pdo, $documentId),
            'source_id' => $documentId,
            'root_id' => $rootId,
            'source_revision' => $sourceRevision,
            'next_revision' => $sourceRevision + 1,
            'reject_reason' => isset($doc['reject_reason']) ? trim((string)$doc['reject_reason']) : '',
            'rejected_step' => isset($doc['rejected_step']) ? trim((string)$doc['rejected_step']) : ''
        );
    }

    private static function uploadedFileCount($files)
    {
        if (!is_array($files) || !isset($files['name'])) return 0;
        $names = is_array($files['name']) ? $files['name'] : array($files['name']);
        $errors = isset($files['error']) ? (is_array($files['error']) ? $files['error'] : array($files['error'])) : array();
        $count = 0;
        for ($i = 0; $i < count($names); $i++) {
            $name = trim((string)$names[$i]);
            $error = isset($errors[$i]) ? (int)$errors[$i] : UPLOAD_ERR_NO_FILE;
            if ($name !== '' && $error !== UPLOAD_ERR_NO_FILE) $count++;
        }
        return $count;
    }

    /**
     * 재상신 시 기존 증빙자료를 새 문서에 안전하게 이어줍니다.
     * - 로컬 파일이 남아 있으면 새 문서 전용 사본을 생성합니다.
     * - Drive 업로드가 완료되어 로컬 파일이 이미 정리된 경우 Drive 참조를 그대로 이어갑니다.
     * - 원문서와 재상신문서가 같은 로컬 파일을 공유하지 않도록 해 삭제 시 상호 영향을 막습니다.
     */
    private static function copyApprovalFiles($pdo, $sourceDocumentId, $targetDocumentId, &$copiedPaths)
    {
        if (!is_array($copiedPaths)) $copiedPaths = array();
        $rows = self::approvalFilesForDocument($pdo, $sourceDocumentId);
        if (count($rows) === 0) return 0;
        $columns = self::tableColumns($pdo, 'cpms_approval_files');
        if (count($columns) === 0) return 0;

        $repoRoot = realpath(dirname(dirname(__DIR__)));
        if ($repoRoot === false) $repoRoot = dirname(dirname(__DIR__));
        $approvalRoot = function_exists('cpms_drive_storage_root')
            ? rtrim((string)cpms_drive_storage_root(), '/\\') . '/approvals'
            : rtrim((string)$repoRoot, '/\\') . '/storage/approvals';
        $targetDir = $approvalRoot . '/' . date('Y') . '/' . (int)$targetDocumentId;

        $copied = 0;
        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $oldPath = isset($row['file_path']) ? trim((string)$row['file_path']) : '';
            if ($oldPath !== '') {
                $oldAbsolute = $oldPath;
                $isAbsolute = (substr($oldPath, 0, 1) === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $oldPath));
                if (!$isAbsolute) {
                    $oldAbsolute = rtrim((string)$repoRoot, '/\\') . '/' . ltrim(str_replace('\\', '/', $oldPath), '/');
                }

                if (is_file($oldAbsolute)) {
                    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                        throw new Exception('재상신 증빙자료 저장폴더를 만들 수 없습니다.');
                    }
                    $extension = strtolower(pathinfo(isset($row['saved_name']) ? (string)$row['saved_name'] : '', PATHINFO_EXTENSION));
                    if ($extension === '') $extension = strtolower(pathinfo(isset($row['original_name']) ? (string)$row['original_name'] : '', PATHINFO_EXTENSION));
                    $savedName = 'carry_' . (isset($row['id']) ? (int)$row['id'] : $i) . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999);
                    if ($extension !== '') $savedName .= '.' . $extension;
                    $newAbsolute = $targetDir . '/' . $savedName;
                    if (!@copy($oldAbsolute, $newAbsolute)) {
                        throw new Exception('기존 증빙자료를 재상신 문서용으로 복사하지 못했습니다.');
                    }
                    $copiedPaths[] = $newAbsolute;
                    $row['saved_name'] = $savedName;
                    $row['file_path'] = self::relativeToRepoRoot($newAbsolute);
                    if (isset($row['file_size'])) $row['file_size'] = (int)@filesize($newAbsolute);
                } else {
                    $uploadStatus = isset($row['upload_status']) ? strtolower(trim((string)$row['upload_status'])) : '';
                    $driveFileId = isset($row['drive_file_id']) ? trim((string)$row['drive_file_id']) : '';
                    if ($uploadStatus === 'uploaded' && $driveFileId !== '') {
                        if (isset($row['file_path'])) $row['file_path'] = '';
                        if (isset($row['saved_name'])) $row['saved_name'] = '';
                    } else {
                        throw new Exception('기존 증빙자료 원본파일을 찾을 수 없습니다. 새 증빙자료를 첨부하거나 원문서를 확인해주세요.');
                    }
                }
            }

            $fields = array('document_id');
            $marks = array(':document_id');
            $params = array(':document_id' => (int)$targetDocumentId);
            $paramNo = 0;
            foreach ($row as $key => $value) {
                if ($key === 'id' || $key === 'document_id' || !isset($columns[$key])) continue;
                $safeKey = str_replace('`', '', (string)$key);
                $param = ':v' . $paramNo;
                $paramNo++;
                $fields[] = '`' . $safeKey . '`';
                $marks[] = $param;
                $params[$param] = $value;
            }
            $sql = "INSERT INTO cpms_approval_files (" . implode(',', $fields) . ") VALUES (" . implode(',', $marks) . ")";
            $pdo->prepare($sql)->execute($params);
            $copied++;
        }
        return $copied;
    }

    /**
     * 프로젝트에 등록된 노무 인원 목록
     */
    public static function laborWorkers($pdo, $projectId)
    {
        $projectId = (int)$projectId;
        $project = self::project($pdo, $projectId);
        if (!$project) return array();
        $loader = dirname(__DIR__) . '/views/construction/tabs/partials/labor_data_loader.php';
        if (is_file($loader)) require_once $loader;
        if (!function_exists('cpms_load_project_labor_workers')) return array();

        $rows = cpms_load_project_labor_workers($pdo, $projectId);
        $map = array();
        if (is_array($rows)) {
            for ($i = 0; $i < count($rows); $i++) {
                $name = '';
                if (isset($rows[$i]['worker_name_snapshot']) && trim((string)$rows[$i]['worker_name_snapshot']) !== '') {
                    $name = trim((string)$rows[$i]['worker_name_snapshot']);
                } elseif (isset($rows[$i]['name'])) {
                    $name = trim((string)$rows[$i]['name']);
                }
                if ($name !== '') $map[$name] = $name;
            }
        }
        ksort($map);
        return array_values($map);
    }

    /**
     * 선택한 수정 신청 날짜가 속한 월에 공사 > 노무비 화면에서 사용하는 월별 인원 기준으로 반환합니다.
     *
     * 중요:
     * - 인원 목록 기준과 공수 기준을 분리합니다.
     * - 인원 목록: 프로젝트 월별 등록 인원 + 해당 월 실제 출역 인원
     * - 현재 공수: 출퇴근/공수 원본 + 승인 완료된 공수 오버라이드
     * - 인력사 업체명은 필터 조건으로 사용하지 않습니다.
     */
    public static function laborWorkersForMonth($pdo, $projectId, $workDate)
    {
        $projectId = (int)$projectId;
        $workDate = self::validDate($workDate);
        $project = self::project($pdo, $projectId);
        if (!$project || $workDate === '') return array();

        $loader = dirname(__DIR__) . '/views/construction/tabs/partials/labor_data_loader.php';
        if (is_file($loader)) require_once $loader;

        $required = array(
            'cpms_load_gongsu_data',
            'cpms_load_project_labor_workers',
            'cpms_build_project_worker_rows',
            'cpms_build_timesheet_workers'
        );
        for ($ri = 0; $ri < count($required); $ri++) {
            if (!function_exists($required[$ri])) return array();
        }

        $month = substr($workDate, 0, 7);
        $projectName = isset($project['name']) ? trim((string)$project['name']) : '';
        if ($projectName === '') return array();

        /*
         * 1) 공수 원본을 먼저 읽습니다.
         *    여기서 workers만 곧바로 인원 목록으로 쓰지 않습니다.
         */
        $dataset = cpms_load_gongsu_data($pdo, $projectName, $month);
        if (!is_array($dataset)) $dataset = array();

        $attendanceWorkers = isset($dataset['all_workers']) && is_array($dataset['all_workers'])
            ? $dataset['all_workers']
            : (isset($dataset['workers']) && is_array($dataset['workers']) ? $dataset['workers'] : array());
        $monthAttendanceWorkers = isset($dataset['workers']) && is_array($dataset['workers']) ? $dataset['workers'] : array();
        $excludedWorkers = isset($dataset['excluded_workers']) && is_array($dataset['excluded_workers']) ? $dataset['excluded_workers'] : array();
        $gongsuMap = isset($dataset['gongsu_map']) && is_array($dataset['gongsu_map']) ? $dataset['gongsu_map'] : array();
        $outputDays = isset($dataset['output_days']) && is_array($dataset['output_days']) ? $dataset['output_days'] : array();
        $gongsuUnit = isset($dataset['gongsu_unit']) && is_array($dataset['gongsu_unit']) ? $dataset['gongsu_unit'] : array();

        /* 기존 승인 완료된 공수 변경값까지 현재 공수에 반영합니다. */
        if (function_exists('cpms_apply_labor_overrides_to_dataset')) {
            $overridden = cpms_apply_labor_overrides_to_dataset($gongsuMap, $outputDays, $gongsuUnit, $projectId, $month);
            if (is_array($overridden)) {
                if (isset($overridden['gongsu_map']) && is_array($overridden['gongsu_map'])) $gongsuMap = $overridden['gongsu_map'];
                if (isset($overridden['output_days']) && is_array($overridden['output_days'])) $outputDays = $overridden['output_days'];
                if (isset($overridden['gongsu_unit']) && is_array($overridden['gongsu_unit'])) $gongsuUnit = $overridden['gongsu_unit'];
            }
        }

        /*
         * 2) 실제 노무비 화면과 같은 방향으로 프로젝트 인원을 정리합니다.
         *    공사 노무비 화면도 출역자를 프로젝트 인원으로 동기화한 뒤 월별 인원을 구성합니다.
         */
        if (function_exists('cpms_cleanup_project_labor_workers')) {
            try { cpms_cleanup_project_labor_workers($pdo, $projectId, $excludedWorkers); } catch (Exception $e) {}
        }
        if (function_exists('cpms_sync_project_labor_workers_from_attendance')) {
            try { cpms_sync_project_labor_workers_from_attendance($pdo, $projectId, $attendanceWorkers); } catch (Exception $e) {}
        }

        $directTeamMembers = function_exists('cpms_load_direct_team_members')
            ? cpms_load_direct_team_members($pdo)
            : array();
        if (!is_array($directTeamMembers)) $directTeamMembers = array();

        $projectWorkers = cpms_load_project_labor_workers($pdo, $projectId);
        if (!is_array($projectWorkers)) $projectWorkers = array();

        if (function_exists('cpms_load_project_labor_worker_month_ratio_map') && function_exists('cpms_apply_project_labor_worker_month_ratios')) {
            try {
                $ratioMap = cpms_load_project_labor_worker_month_ratio_map($pdo, $projectId, $month, $projectWorkers);
                $ratioApplied = cpms_apply_project_labor_worker_month_ratios($projectWorkers, is_array($ratioMap) ? $ratioMap : array());
                if (is_array($ratioApplied)) $projectWorkers = $ratioApplied;
            } catch (Exception $e) {}
        }
        if (function_exists('cpms_load_project_labor_worker_wage_map') && function_exists('cpms_apply_project_labor_worker_month_wages')) {
            try {
                $wageMap = cpms_load_project_labor_worker_wage_map($pdo, $projectId, $month);
                $wageApplied = cpms_apply_project_labor_worker_month_wages($projectWorkers, is_array($wageMap) ? $wageMap : array());
                if (is_array($wageApplied)) $projectWorkers = $wageApplied;
            } catch (Exception $e) {}
        }

        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directTeamMembers, $pdo, $month);
        if (!is_array($workerRows)) $workerRows = array();

        /* 인원작성에서 해당 월에 직접 배정한 사람 표시 여부를 노무비 화면과 동일하게 붙입니다. */
        $monthAssignmentMap = function_exists('cpms_load_project_labor_worker_month_map')
            ? cpms_load_project_labor_worker_month_map($pdo, $projectId, $month)
            : array();
        if (!is_array($monthAssignmentMap)) $monthAssignmentMap = array();

        if (count($monthAssignmentMap) > 0) {
            for ($wi = 0; $wi < count($workerRows); $wi++) {
                $laborWorkerId = isset($workerRows[$wi]['id']) ? (int)$workerRows[$wi]['id'] : 0;
                $isMonthAssigned = ($laborWorkerId > 0 && isset($monthAssignmentMap[$laborWorkerId])) ? 1 : 0;
                $workerRows[$wi]['month_assigned'] = $isMonthAssigned;
                if (isset($workerRows[$wi]['data']) && is_array($workerRows[$wi]['data'])) {
                    $workerRows[$wi]['data']['month_assigned'] = $isMonthAssigned;
                }
            }
        }

        $timesheetWorkers = cpms_build_timesheet_workers($workerRows);
        if (!is_array($timesheetWorkers)) $timesheetWorkers = array();

        // 선택월 임금단가도 이름키로 미리 보관하여 출역자 보강 목록까지 같은 단가 기준을 사용합니다.
        $wageByKey = array();
        for ($wi = 0; $wi < count($timesheetWorkers); $wi++) {
            $wageWorker = $timesheetWorkers[$wi];
            if (!is_array($wageWorker)) continue;
            $wageName = isset($wageWorker['name']) ? trim((string)$wageWorker['name']) : '';
            if ($wageName === '') continue;
            $wageKey = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($wageName) : strtolower($wageName);
            if ($wageKey === '') continue;
            $wageByKey[$wageKey] = self::laborWageRateFromWorker($wageWorker);
        }

        $map = array();

        /*
         * 3) 노무비 화면의 월별 표시 기준:
         *    - 해당 월 실제 출역일수가 1일 이상이거나
         *    - 인원작성에서 해당 월에 직접 배정된 사람
         */
        for ($ti = 0; $ti < count($timesheetWorkers); $ti++) {
            $worker = $timesheetWorkers[$ti];
            if (!is_array($worker)) continue;
            $name = isset($worker['name']) ? trim((string)$worker['name']) : '';
            if ($name === '') continue;
            $key = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($name) : strtolower($name);
            if ($key === '') continue;

            $workerOutputDays = isset($outputDays[$key]) ? (int)$outputDays[$key] : 0;
            $isMonthAssigned = isset($worker['month_assigned']) && (int)$worker['month_assigned'] === 1;
            if ($workerOutputDays <= 0 && !$isMonthAssigned) continue;

            $current = 0.0;
            if (isset($gongsuMap[$key]) && is_array($gongsuMap[$key]) && isset($gongsuMap[$key][$workDate])) {
                $current = (float)$gongsuMap[$key][$workDate];
            }
            $map[$key] = array(
                'worker_name' => $name,
                'worker_key' => $key,
                'current_gongsu' => $current,
                'wage_rate' => isset($wageByKey[$key]) ? (float)$wageByKey[$key] : self::laborWageRateFromWorker($worker)
            );
        }

        /*
         * 4) 방어용 보강:
         *    월별 인원작성 테이블에 직접 저장된 사람은 화면 조합 함수의 구조가 바뀌더라도 빠지지 않게 합칩니다.
         */
        if (function_exists('cpms_load_project_labor_workers_for_month')) {
            try {
                $monthlyRows = cpms_load_project_labor_workers_for_month($pdo, $projectId, $month);
                if (is_array($monthlyRows)) {
                    for ($mi = 0; $mi < count($monthlyRows); $mi++) {
                        $monthlyRow = $monthlyRows[$mi];
                        if (!is_array($monthlyRow)) continue;
                        $name = '';
                        if (isset($monthlyRow['worker_name_snapshot']) && trim((string)$monthlyRow['worker_name_snapshot']) !== '') {
                            $name = trim((string)$monthlyRow['worker_name_snapshot']);
                        } elseif (isset($monthlyRow['name']) && trim((string)$monthlyRow['name']) !== '') {
                            $name = trim((string)$monthlyRow['name']);
                        } elseif (isset($monthlyRow['worker_name'])) {
                            $name = trim((string)$monthlyRow['worker_name']);
                        }
                        if ($name === '') continue;
                        $key = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($name) : strtolower($name);
                        if ($key === '' || isset($map[$key])) continue;
                        $current = 0.0;
                        if (isset($gongsuMap[$key]) && is_array($gongsuMap[$key]) && isset($gongsuMap[$key][$workDate])) {
                            $current = (float)$gongsuMap[$key][$workDate];
                        }
                        $monthlyWage = self::laborWageRateFromWorker($monthlyRow);
                        if ($monthlyWage <= 0 && isset($wageByKey[$key])) $monthlyWage = (float)$wageByKey[$key];
                        $map[$key] = array(
                            'worker_name' => $name,
                            'worker_key' => $key,
                            'current_gongsu' => $current,
                            'wage_rate' => $monthlyWage
                        );
                    }
                }
            } catch (Exception $e) {}
        }

        /* 실제 해당 월 출역자는 프로젝트 인원 동기화 실패가 있어도 마지막으로 보강합니다. */
        for ($ai = 0; $ai < count($monthAttendanceWorkers); $ai++) {
            if (!is_scalar($monthAttendanceWorkers[$ai])) continue;
            $name = trim((string)$monthAttendanceWorkers[$ai]);
            if ($name === '') continue;
            $key = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($name) : strtolower($name);
            if ($key === '' || isset($map[$key])) continue;
            $current = 0.0;
            if (isset($gongsuMap[$key]) && is_array($gongsuMap[$key]) && isset($gongsuMap[$key][$workDate])) {
                $current = (float)$gongsuMap[$key][$workDate];
            }
            $map[$key] = array(
                'worker_name' => $name,
                'worker_key' => $key,
                'current_gongsu' => $current,
                'wage_rate' => isset($wageByKey[$key]) ? (float)$wageByKey[$key] : 0.0
            );
        }

        $rows = array_values($map);
        usort($rows, array(__CLASS__, 'sortLaborWorkerRows'));
        return $rows;
    }

    public static function sortLaborWorkerRows($a, $b)
    {
        $an = is_array($a) && isset($a['worker_name']) ? (string)$a['worker_name'] : '';
        $bn = is_array($b) && isset($b['worker_name']) ? (string)$b['worker_name'] : '';
        return strcmp($an, $bn);
    }

    /**
     * 특정 근로자/날짜의 현재 공수. 기존 승인 오버라이드까지 반영한 값을 반환합니다.
     */
    public static function currentLaborGongsu($pdo, $projectId, $workerName, $workDate)
    {
        $project = self::project($pdo, $projectId);
        $workerName = trim((string)$workerName);
        $workDate = self::validDate($workDate);
        if (!$project || $workerName === '' || $workDate === '') return 0.0;

        $loader = dirname(__DIR__) . '/views/construction/tabs/partials/labor_data_loader.php';
        if (is_file($loader)) require_once $loader;
        if (!function_exists('cpms_load_gongsu_data')) return 0.0;

        $month = substr($workDate, 0, 7);
        $projectName = isset($project['name']) ? (string)$project['name'] : '';
        $dataset = cpms_load_gongsu_data($pdo, $projectName, $month);
        $gongsuMap = isset($dataset['gongsu_map']) && is_array($dataset['gongsu_map']) ? $dataset['gongsu_map'] : array();
        $outputDays = isset($dataset['output_days']) && is_array($dataset['output_days']) ? $dataset['output_days'] : array();
        $gongsuUnit = isset($dataset['gongsu_unit']) && is_array($dataset['gongsu_unit']) ? $dataset['gongsu_unit'] : array();

        if (function_exists('cpms_apply_labor_overrides_to_dataset')) {
            $overridden = cpms_apply_labor_overrides_to_dataset($gongsuMap, $outputDays, $gongsuUnit, (int)$projectId, $month);
            if (is_array($overridden) && isset($overridden['gongsu_map']) && is_array($overridden['gongsu_map'])) {
                $gongsuMap = $overridden['gongsu_map'];
            }
        }

        $workerKey = function_exists('cpms_normalize_worker_key')
            ? cpms_normalize_worker_key($workerName)
            : strtolower(trim($workerName));
        if ($workerKey !== '' && isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey]) && isset($gongsuMap[$workerKey][$workDate])) {
            return (float)$gongsuMap[$workerKey][$workDate];
        }
        return 0.0;
    }

    /**
     * 수정모드에서 선택할 기존 자료 목록
     */
    public static function existingTargets($pdo, $formType, $projectId, $category, $useDate = '')
    {
        $formType = strtolower(trim((string)$formType));
        $projectId = (int)$projectId;
        $category = trim((string)$category);
        $useDate = self::validDate($useDate);
        if (!$pdo || $projectId <= 0) return array();

        $rows = array();
        if ($formType === self::FORM_EQUIPMENT) {
            $rows = self::equipmentTargets($pdo, $projectId);
        } elseif ($formType === self::FORM_OUTSOURCING) {
            $rows = self::outsourcingTargets($pdo, $projectId);
        } elseif ($formType === self::FORM_INPUT) {
            if ($category === '안전관리비') {
                $rows = self::safetyTargets($pdo, $projectId);
            } else {
                $categories = self::validInputCategories();
                if (!isset($categories[$category])) return array();
                $rows = self::materialTargets($pdo, $projectId, $category);
            }
        }

        if ($useDate === '' || count($rows) === 0) return $rows;
        return self::filterTargetsByCorrectionDate($pdo, $rows, $formType, $category, $useDate);
    }

    /**
     * 기존자료 수정 목록은 사용자가 선택한 '수정 신청 날짜'의 마감기간 자료만 보여줍니다.
     * - 외주비: 달력 월(1일~말일)
     * - 장비비/자재비/안전관리비/기타경비/구매품: 전월 26일~당월 25일
     * 기존 월이동 승인 이력이 있으면 실제 적용 마감월을 우선 사용합니다.
     */
    private static function filterTargetsByCorrectionDate($pdo, $rows, $formType, $category, $useDate)
    {
        $selectedYm = self::settlementYmFor($formType, $category, $useDate);
        if ($selectedYm === '') return array();

        $result = array();
        $targetType = self::targetTypeFor($formType, $category);
        $costType = $targetType;
        if ($targetType === 'material') $costType = 'material';
        if ($targetType === 'equipment') $costType = 'equipment';
        if ($targetType === 'outsourcing') $costType = 'outsourcing';
        if ($targetType === 'safety') $costType = 'safety';

        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (!is_array($row)) continue;
            $rowDate = self::validDate(isset($row['use_date']) ? $row['use_date'] : '');
            if ($rowDate === '') continue;

            $rowYm = '';
            $rowTargetId = isset($row['target_id']) ? trim((string)$row['target_id']) : '';
            if ($pdo && $rowTargetId !== '' && method_exists('\App\Services\CostChangeService', 'effectiveSettlementYm')) {
                try {
                    $rowYm = CostChangeService::effectiveSettlementYm($pdo, $targetType, $rowTargetId, $costType, $rowDate);
                } catch (Exception $e) {
                    $rowYm = '';
                }
            }
            if ($rowYm === '') $rowYm = self::settlementYmFor($formType, $category, $rowDate);
            if ($rowYm === $selectedYm) $result[] = $row;
        }
        return $result;
    }

    private static function materialTargets($pdo, $projectId, $category)
    {
        if (!self::tableExists($pdo, 'cpms_material_usage') || !self::tableExists($pdo, 'cpms_material_items')) return array();
        $itemCols = self::tableColumns($pdo, 'cpms_material_items');
        $vendorSelect = isset($itemCols['vendor_id']) ? 'i.vendor_id' : '0 AS vendor_id';
        try {
            $sql = "SELECT u.id AS target_id,u.use_date,u.amount,u.memo,i.id AS master_id,i.category,i.vendor_name,i.spec,i.base_rate," . $vendorSelect . "
                    FROM cpms_material_usage u
                    INNER JOIN cpms_material_items i ON i.id=u.material_id AND i.project_id=u.project_id
                    WHERE u.project_id=:pid AND i.category=:category AND COALESCE(i.is_deleted,0)=0
                    ORDER BY u.use_date DESC,u.id DESC LIMIT 500";
            $st = $pdo->prepare($sql);
            $st->execute(array(':pid' => $projectId, ':category' => $category));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $result = array();
            for ($i = 0; $i < count($rows); $i++) {
                $result[] = array(
                    'target_id' => (string)$rows[$i]['target_id'],
                    'use_date' => isset($rows[$i]['use_date']) ? (string)$rows[$i]['use_date'] : '',
                    'vendor_id' => isset($rows[$i]['vendor_id']) ? (int)$rows[$i]['vendor_id'] : 0,
                    'vendor_name' => isset($rows[$i]['vendor_name']) ? (string)$rows[$i]['vendor_name'] : '',
                    'item_name' => isset($rows[$i]['spec']) ? (string)$rows[$i]['spec'] : '',
                    'quantity' => 1,
                    'unit_price' => isset($rows[$i]['base_rate']) ? (float)$rows[$i]['base_rate'] : (float)$rows[$i]['amount'],
                    'amount' => isset($rows[$i]['amount']) ? (float)$rows[$i]['amount'] : 0,
                    'memo' => isset($rows[$i]['memo']) ? (string)$rows[$i]['memo'] : '',
                    'category' => isset($rows[$i]['category']) ? (string)$rows[$i]['category'] : $category
                );
            }
            return $result;
        } catch (Exception $e) {
            return array();
        }
    }

    private static function equipmentTargets($pdo, $projectId)
    {
        if (!self::tableExists($pdo, 'cpms_equipment_usage') || !self::tableExists($pdo, 'cpms_equipment_items')) return array();
        $itemCols = self::tableColumns($pdo, 'cpms_equipment_items');
        $vendorSelect = isset($itemCols['vendor_id']) ? 'i.vendor_id' : '0 AS vendor_id';
        try {
            $sql = "SELECT u.id AS target_id,u.use_date,u.work_unit,u.base_rate_snapshot,u.amount,u.memo,i.id AS master_id,i.category,i.vendor_name,i.spec," . $vendorSelect . "
                    FROM cpms_equipment_usage u
                    INNER JOIN cpms_equipment_items i ON i.id=u.equipment_id AND i.project_id=u.project_id
                    WHERE u.project_id=:pid AND COALESCE(i.is_deleted,0)=0
                    ORDER BY u.use_date DESC,u.id DESC LIMIT 500";
            $st = $pdo->prepare($sql);
            $st->execute(array(':pid' => $projectId));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $result = array();
            for ($i = 0; $i < count($rows); $i++) {
                $result[] = array(
                    'target_id' => (string)$rows[$i]['target_id'],
                    'use_date' => isset($rows[$i]['use_date']) ? (string)$rows[$i]['use_date'] : '',
                    'vendor_id' => isset($rows[$i]['vendor_id']) ? (int)$rows[$i]['vendor_id'] : 0,
                    'vendor_name' => isset($rows[$i]['vendor_name']) ? (string)$rows[$i]['vendor_name'] : '',
                    'item_name' => isset($rows[$i]['spec']) ? (string)$rows[$i]['spec'] : '',
                    'quantity' => isset($rows[$i]['work_unit']) ? (float)$rows[$i]['work_unit'] : 1,
                    'unit_price' => isset($rows[$i]['base_rate_snapshot']) ? (float)$rows[$i]['base_rate_snapshot'] : 0,
                    'amount' => isset($rows[$i]['amount']) ? (float)$rows[$i]['amount'] : 0,
                    'memo' => isset($rows[$i]['memo']) ? (string)$rows[$i]['memo'] : '',
                    'category' => isset($rows[$i]['category']) ? (string)$rows[$i]['category'] : '장비비'
                );
            }
            return $result;
        } catch (Exception $e) {
            return array();
        }
    }

    private static function outsourcingTargets($pdo, $projectId)
    {
        if (!self::tableExists($pdo, 'cpms_outsourcing_costs')) return array();
        $cols = self::tableColumns($pdo, 'cpms_outsourcing_costs');
        $vendorSelect = isset($cols['vendor_id']) ? 'vendor_id' : '0 AS vendor_id';
        try {
            $sql = "SELECT id AS target_id,expense_date AS use_date,company_name AS vendor_name,amount,memo," . $vendorSelect . "
                    FROM cpms_outsourcing_costs
                    WHERE project_id=:pid";
            if (isset($cols['is_deleted'])) $sql .= " AND COALESCE(is_deleted,0)=0";
            $sql .= " ORDER BY expense_date DESC,id DESC LIMIT 500";
            $st = $pdo->prepare($sql);
            $st->execute(array(':pid' => $projectId));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $result = array();
            for ($i = 0; $i < count($rows); $i++) {
                $result[] = array(
                    'target_id' => (string)$rows[$i]['target_id'],
                    'use_date' => isset($rows[$i]['use_date']) ? (string)$rows[$i]['use_date'] : '',
                    'vendor_id' => isset($rows[$i]['vendor_id']) ? (int)$rows[$i]['vendor_id'] : 0,
                    'vendor_name' => isset($rows[$i]['vendor_name']) ? (string)$rows[$i]['vendor_name'] : '',
                    'item_name' => isset($rows[$i]['memo']) ? (string)$rows[$i]['memo'] : '',
                    'quantity' => 1,
                    'unit_price' => isset($rows[$i]['amount']) ? (float)$rows[$i]['amount'] : 0,
                    'amount' => isset($rows[$i]['amount']) ? (float)$rows[$i]['amount'] : 0,
                    'memo' => isset($rows[$i]['memo']) ? (string)$rows[$i]['memo'] : '',
                    'category' => '외주비'
                );
            }
            return $result;
        } catch (Exception $e) {
            return array();
        }
    }

    private static function safetyTargets($pdo, $projectId)
    {
        $helper = dirname(__DIR__) . '/views/safety/safety_cost_helper.php';
        if (is_file($helper)) require_once $helper;
        if (!function_exists('cpms_safety_cost_project_items')) return array();
        try {
            $rows = cpms_safety_cost_project_items($projectId);
            if (!is_array($rows)) return array();
            $result = array();
            for ($i = 0; $i < count($rows); $i++) {
                $row = $rows[$i];
                $id = isset($row['id']) ? trim((string)$row['id']) : '';
                if ($id === '') continue;
                $amount = function_exists('cpms_safety_cost_row_amount')
                    ? (float)cpms_safety_cost_row_amount($row)
                    : (isset($row['amount']) ? (float)$row['amount'] : 0);
                $itemName = isset($row['item_name']) && trim((string)$row['item_name']) !== ''
                    ? trim((string)$row['item_name'])
                    : (isset($row['use_content']) ? trim((string)$row['use_content']) : '');
                $result[] = array(
                    'target_id' => $id,
                    'use_date' => isset($row['use_date']) ? (string)$row['use_date'] : '',
                    'vendor_id' => isset($row['vendor_id']) ? (int)$row['vendor_id'] : 0,
                    'vendor_name' => isset($row['vendor_name']) ? (string)$row['vendor_name'] : '',
                    'item_name' => $itemName,
                    'quantity' => isset($row['quantity']) ? (float)$row['quantity'] : 1,
                    'unit_price' => isset($row['unit_price']) ? (float)$row['unit_price'] : $amount,
                    'amount' => $amount,
                    'memo' => isset($row['remark']) ? (string)$row['remark'] : '',
                    'category' => '안전관리비'
                );
            }
            usort($result, array(__CLASS__, 'targetDateDescCompare'));
            return $result;
        } catch (Exception $e) {
            return array();
        }
    }

    public static function targetDateDescCompare($a, $b)
    {
        $ad = is_array($a) && isset($a['use_date']) ? (string)$a['use_date'] : '';
        $bd = is_array($b) && isset($b['use_date']) ? (string)$b['use_date'] : '';
        if ($ad === $bd) return 0;
        return strcmp($bd, $ad);
    }

    private static function targetTypeFor($formType, $category)
    {
        if ($formType === self::FORM_EQUIPMENT) return 'equipment';
        if ($formType === self::FORM_OUTSOURCING) return 'outsourcing';
        if ($formType === self::FORM_INPUT && $category === '안전관리비') return 'safety';
        if ($formType === self::FORM_INPUT) return 'material';
        return '';
    }

    private static function settlementYmFor($formType, $category, $useDate)
    {
        $targetType = self::targetTypeFor($formType, $category);
        if ($formType === self::FORM_LABOR) return substr((string)$useDate, 0, 7);
        $costType = $targetType;
        if ($targetType === 'material') $costType = 'material';
        if ($targetType === 'equipment') $costType = 'equipment';
        if ($targetType === 'outsourcing') $costType = 'outsourcing';
        if ($targetType === 'safety') $costType = 'safety';
        try {
            return CostChangeService::settlementYm($costType, $useDate);
        } catch (Exception $e) {
            return substr((string)$useDate, 0, 7);
        }
    }

    private static function targetFromList($pdo, $formType, $projectId, $category, $targetId, $useDate = '')
    {
        $targetId = trim((string)$targetId);
        if ($targetId === '') return null;
        $rows = self::existingTargets($pdo, $formType, $projectId, $category, $useDate);
        for ($i = 0; $i < count($rows); $i++) {
            if (isset($rows[$i]['target_id']) && (string)$rows[$i]['target_id'] === $targetId) return $rows[$i];
        }
        return null;
    }

    /**
     * 신청 데이터 검증 및 전자결재 문서 저장
     */
    public static function createDocument($pdo, $user, $input, $files)
    {
        if (!$pdo) throw new Exception('DB 연결을 확인할 수 없습니다.');
        if (!is_array($input)) $input = array();

        $resubmitSourceId = isset($input['resubmit_source_id']) ? (int)$input['resubmit_source_id'] : 0;
        $resubmitInfo = null;
        if ($resubmitSourceId > 0) {
            $resubmitInfo = self::resubmitSource($pdo, $user, $resubmitSourceId);
        }

        $formType = isset($input['form_type']) ? strtolower(trim((string)$input['form_type'])) : '';
        if (!self::validFormType($formType)) throw new Exception('신청 양식을 확인해주세요.');

        $mode = isset($input['request_mode']) ? strtoupper(trim((string)$input['request_mode'])) : '';
        if ($mode !== self::MODE_ADD && $mode !== self::MODE_MODIFY) throw new Exception('누락자료 추가 또는 기존자료 수정을 선택해주세요.');

        $projectId = isset($input['project_id']) ? (int)$input['project_id'] : 0;
        $project = self::project($pdo, $projectId);
        if (!$project) throw new Exception('현장을 선택해주세요.');
        $projectName = isset($project['name']) ? trim((string)$project['name']) : '';

        $reason = self::safeText(isset($input['reason']) ? $input['reason'] : '', 2000);
        if ($reason === '') throw new Exception('수정/누락 사유를 입력해주세요.');

        $useDate = self::validDate(isset($input['use_date']) ? $input['use_date'] : '');
        if ($useDate === '') throw new Exception('수정 신청 날짜를 선택해주세요.');

        // 작성자와 기본 결재선을 먼저 확인합니다. 대표 결재 여부는 신청 변동금액 계산 후 서버에서 다시 확정합니다.
        $lineResult = self::buildApprovalLines($pdo, $user, false);
        if (empty($lineResult['ok'])) throw new Exception(isset($lineResult['message']) ? $lineResult['message'] : '결재선을 만들 수 없습니다.');
        $creator = $lineResult['creator'];
        $lines = $lineResult['lines'];

        $department = isset($creator['department']) ? trim((string)$creator['department']) : '';
        $creatorName = isset($creator['name']) ? trim((string)$creator['name']) : '';
        $creatorEmail = isset($creator['email']) ? trim((string)$creator['email']) : '';
        $creatorId = isset($creator['id']) ? (int)$creator['id'] : 0;

        $content = array(
            self::MARKER_KEY => self::MARKER_VALUE,
            'correction_form_type' => $formType,
            'correction_form_label' => self::formLabel($formType),
            'correction_mode' => $mode,
            'correction_mode_label' => $mode === self::MODE_ADD ? '누락자료 추가' : '기존자료 수정',
            'project_id' => $projectId,
            'project_name' => $projectName,
            'draft_date' => date('Y-m-d'),
            'effective_date' => $useDate,
            'draft_department' => $department,
            'drafter_name' => $creatorName,
            'writer_email' => $creatorEmail,
            'draft_type' => '기타',
            'reason' => $reason,
            'use_date' => $useDate,
            'auto_apply_status' => 'PENDING',
            'auto_applied_at' => '',
            'auto_applied_target_type' => '',
            'auto_applied_target_id' => '',
            'approval_line_text' => self::approvalLineText($lines)
        );

        if (is_array($resubmitInfo)) {
            $content['resubmit_source_id'] = (int)$resubmitInfo['source_id'];
            $content['resubmit_root_id'] = (int)$resubmitInfo['root_id'];
            $content['resubmit_revision'] = (int)$resubmitInfo['next_revision'];
            $content['resubmit_source_reject_reason'] = isset($resubmitInfo['reject_reason']) ? (string)$resubmitInfo['reject_reason'] : '';
            $content['resubmit_source_rejected_step'] = isset($resubmitInfo['rejected_step']) ? (string)$resubmitInfo['rejected_step'] : '';
        }

        $titleDetail = '';

        if ($formType === self::FORM_LABOR) {
            $selectedIndexes = isset($input['labor_selected']) && is_array($input['labor_selected']) ? $input['labor_selected'] : array();
            $postedWorkerNames = isset($input['labor_worker_name']) && is_array($input['labor_worker_name']) ? $input['labor_worker_name'] : array();
            $postedRequested = isset($input['labor_requested_gongsu']) && is_array($input['labor_requested_gongsu']) ? $input['labor_requested_gongsu'] : array();
            if (count($selectedIndexes) === 0) throw new Exception('수정/누락할 근로자를 한 명 이상 선택해주세요.');
            if (count($selectedIndexes) > 100) throw new Exception('한 번에 신청할 수 있는 근로자는 최대 100명입니다.');

            $monthRows = self::laborWorkersForMonth($pdo, $projectId, $useDate);
            $monthMap = array();
            for ($mi = 0; $mi < count($monthRows); $mi++) {
                $mn = isset($monthRows[$mi]['worker_name']) ? trim((string)$monthRows[$mi]['worker_name']) : '';
                if ($mn !== '') $monthMap[$mn] = $monthRows[$mi];
            }
            if (count($monthMap) === 0) throw new Exception(substr($useDate, 0, 7) . ' 해당 현장의 노무 인원을 찾을 수 없습니다.');

            $allowedGongsu = array(0.5, 1.0, 1.1, 1.2, 1.3, 1.4, 1.5, 2.0);
            $laborChanges = array();
            $seenIndexes = array();
            for ($si = 0; $si < count($selectedIndexes); $si++) {
                $idx = (int)$selectedIndexes[$si];
                if ($idx < 0 || isset($seenIndexes[$idx])) continue;
                $seenIndexes[$idx] = 1;
                $workerName = isset($postedWorkerNames[$idx]) ? self::safeText($postedWorkerNames[$idx], 100) : '';
                if ($workerName === '' || !isset($monthMap[$workerName])) {
                    throw new Exception('선택한 수정 신청 날짜의 월 근로자 정보가 변경되었습니다. 화면을 새로고침한 뒤 다시 선택해주세요.');
                }
                $requestedGongsu = self::numeric(isset($postedRequested[$idx]) ? $postedRequested[$idx] : '', -1);
                $allowed = false;
                for ($gi = 0; $gi < count($allowedGongsu); $gi++) {
                    if (abs($requestedGongsu - $allowedGongsu[$gi]) < 0.0001) { $allowed = true; break; }
                }
                if (!$allowed) throw new Exception($workerName . ' 변경 공수는 0.5, 1.0, 1.1, 1.2, 1.3, 1.4, 1.5, 2.0 중에서 선택해주세요.');

                $currentGongsu = self::currentLaborGongsu($pdo, $projectId, $workerName, $useDate);
                if (abs($currentGongsu - $requestedGongsu) < 0.0001) {
                    throw new Exception($workerName . '의 현재 공수와 변경 공수가 같습니다. 변경 공수를 다시 선택해주세요.');
                }
                $wageRate = isset($monthMap[$workerName]['wage_rate']) ? (float)$monthMap[$workerName]['wage_rate'] : 0.0;
                $workerChangeAmount = round(abs($requestedGongsu - $currentGongsu) * $wageRate);
                $laborChanges[] = array(
                    'worker_name' => $workerName,
                    'worker_key' => function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($workerName) : strtolower($workerName),
                    'current_gongsu' => $currentGongsu,
                    'requested_gongsu' => $requestedGongsu,
                    'wage_rate' => $wageRate,
                    'change_amount' => $workerChangeAmount
                );
            }
            if (count($laborChanges) === 0) throw new Exception('수정/누락할 근로자를 한 명 이상 선택해주세요.');

            $workerNames = array();
            $laborChangeAmount = 0.0;
            for ($li = 0; $li < count($laborChanges); $li++) {
                $workerNames[] = $laborChanges[$li]['worker_name'];
                $laborChangeAmount += isset($laborChanges[$li]['change_amount']) ? (float)$laborChanges[$li]['change_amount'] : 0.0;
            }
            $content['labor_changes'] = $laborChanges;
            $content['change_amount'] = round($laborChangeAmount);
            $content['labor_change_count'] = count($laborChanges);
            $content['worker_names_text'] = implode(', ', $workerNames);
            // V2 단건 문서와의 하위 호환을 위해 첫 번째 근로자 값도 함께 보관합니다.
            $content['worker_name'] = $laborChanges[0]['worker_name'];
            $content['current_gongsu'] = $laborChanges[0]['current_gongsu'];
            $content['requested_gongsu'] = $laborChanges[0]['requested_gongsu'];
            $content['settlement_ym'] = substr($useDate, 0, 7);
            $content['company_name'] = '-';
            $content['contract_amount'] = '';
            $content['advance_amount'] = '';
            $content['special_note'] = self::buildProposalSpecialNote($content, $reason);
            if (count($laborChanges) === 1) {
                $titleDetail = $laborChanges[0]['worker_name'] . ' / ' . $useDate . ' / ' . self::gongsuText($laborChanges[0]['current_gongsu']) . '→' . self::gongsuText($laborChanges[0]['requested_gongsu']);
            } else {
                $titleDetail = count($laborChanges) . '명 / ' . $useDate;
            }
        } else {
            $vendorId = isset($input['vendor_id']) ? (int)$input['vendor_id'] : 0;
            $vendor = self::vendor($pdo, $vendorId);
            if (!$vendor) throw new Exception('업체관리에서 등록된 업체를 선택해주세요. 업체 직접입력은 허용하지 않습니다.');
            $vendorName = isset($vendor['name']) ? trim((string)$vendor['name']) : '';
            if ($vendorName === '') throw new Exception('선택한 업체명을 확인할 수 없습니다.');

            $category = '';
            if ($formType === self::FORM_EQUIPMENT) $category = '장비비';
            if ($formType === self::FORM_OUTSOURCING) $category = '외주비';
            if ($formType === self::FORM_INPUT) {
                $category = isset($input['category']) ? trim((string)$input['category']) : '';
                $categories = self::validInputCategories();
                if (!isset($categories[$category])) throw new Exception('비용구분을 선택해주세요.');
            }

            $targetType = self::targetTypeFor($formType, $category);
            $targetId = isset($input['target_id']) ? trim((string)$input['target_id']) : '';
            $oldTarget = null;
            if ($mode === self::MODE_MODIFY) {
                if ($targetId === '') throw new Exception('수정할 기존 자료를 선택해주세요.');
                $oldTarget = self::targetFromList($pdo, $formType, $projectId, $category, $targetId, $useDate);
                if (!is_array($oldTarget)) throw new Exception('선택한 수정 신청 날짜의 마감기간에서 수정할 기존 자료를 찾을 수 없습니다.');
            } else {
                $targetId = '';
            }

            $itemName = self::safeText(isset($input['item_name']) ? $input['item_name'] : '', 255);
            if ($itemName === '') {
                if ($formType === self::FORM_OUTSOURCING) throw new Exception('외주 내용을 입력해주세요.');
                if ($formType === self::FORM_EQUIPMENT) throw new Exception('장비명/규격을 입력해주세요.');
                throw new Exception('품목/내용을 입력해주세요.');
            }

            $quantity = self::numeric(isset($input['quantity']) ? $input['quantity'] : 1, 1);
            if ($quantity <= 0) $quantity = 1;
            $unitPrice = self::money(isset($input['unit_price']) ? $input['unit_price'] : 0);
            $amount = self::money(isset($input['amount']) ? $input['amount'] : 0);
            if ($amount <= 0 && $unitPrice > 0 && $quantity > 0) $amount = round($unitPrice * $quantity);
            if ($amount <= 0) throw new Exception('금액을 입력해주세요.');
            if ($unitPrice <= 0) $unitPrice = $quantity > 0 ? ($amount / $quantity) : $amount;

            $memo = self::safeText(isset($input['memo']) ? $input['memo'] : '', 2000);
            // 외주비 원본 테이블은 별도 '외주내용' 컬럼 없이 memo를 사용하므로
            // 화면의 외주 내용을 실제 공사자료에도 반드시 남깁니다.
            if ($formType === self::FORM_OUTSOURCING) {
                if ($memo === '') {
                    $memo = $itemName;
                } elseif ($memo !== $itemName && strpos($memo, $itemName) === false) {
                    $memo = $itemName . ' / ' . $memo;
                }
            }
            $settlementYm = self::settlementYmFor($formType, $category, $useDate);
            $oldAmount = is_array($oldTarget) && isset($oldTarget['amount']) ? (float)$oldTarget['amount'] : 0.0;
            $content['change_amount'] = round($mode === self::MODE_MODIFY ? abs($amount - $oldAmount) : abs($amount));
            $content['old_amount'] = $oldAmount;
            $content['new_amount'] = $amount;

            $content['vendor_id'] = $vendorId;
            $content['vendor_name'] = $vendorName;
            $content['company_name'] = $vendorName;
            $content['cost_category'] = $category;
            $content['target_type'] = $targetType;
            $content['target_id'] = $targetId;
            $content['item_name'] = $itemName;
            $content['quantity'] = $quantity;
            $content['unit_price'] = $unitPrice;
            $content['amount'] = $amount;
            $content['memo'] = $memo;
            $content['settlement_ym'] = $settlementYm;
            $content['contract_amount'] = (string)round($amount);
            $content['advance_amount'] = '';
            $content['old_target'] = is_array($oldTarget) ? $oldTarget : array();
            $content['special_note'] = self::buildProposalSpecialNote($content, $reason);
            $titleDetail = $vendorName . ' / ' . $useDate . ' / ' . number_format($amount) . '원';
        }

        // 서버에서 변동금액을 다시 확정하여 대표 결재 여부를 결정합니다.
        $changeAmount = isset($content['change_amount']) ? (float)$content['change_amount'] : 0.0;
        if ($changeAmount < 0) $changeAmount = abs($changeAmount);
        $content['change_amount'] = round($changeAmount);
        $requiresCeo = ($changeAmount >= self::CEO_APPROVAL_THRESHOLD);
        if ($requiresCeo) {
            $ceoLineResult = self::buildApprovalLines($pdo, $user, true);
            if (empty($ceoLineResult['ok'])) {
                throw new Exception(isset($ceoLineResult['message']) ? $ceoLineResult['message'] : '대표 결재선을 만들 수 없습니다.');
            }
            $lines = $ceoLineResult['lines'];
        }
        $content['ceo_approval_required'] = $requiresCeo ? 1 : 0;
        $content['ceo_approval_threshold'] = self::CEO_APPROVAL_THRESHOLD;
        $content['approval_line_text'] = self::approvalLineText($lines);
        $content['special_note'] = self::buildProposalSpecialNote($content, $reason);

        $title = '[' . self::formLabel($formType) . '] ' . $projectName;
        if ($titleDetail !== '') $title .= ' - ' . $titleDetail;
        $content['title'] = $title;
        $content['headline'] = self::formLabel($formType);
        $content['intro_text'] = '아래 수정/누락 내용을 확인하시어 결재하여 주시기 바랍니다. 최종 승인 시 공사 원가자료에 자동 반영됩니다.';

        $sourceEvidenceCount = is_array($resubmitInfo) && isset($resubmitInfo['files']) && is_array($resubmitInfo['files']) ? count($resubmitInfo['files']) : 0;
        $newEvidenceCount = self::uploadedFileCount($files);
        if (is_array($resubmitInfo)) {
            if (($sourceEvidenceCount + $newEvidenceCount) <= 0) {
                throw new Exception('수정 후 재상신에는 증빙자료가 반드시 1개 이상 필요합니다. 증빙자료를 첨부해주세요.');
            }
            if (($sourceEvidenceCount + $newEvidenceCount) > 20) {
                throw new Exception('기존 증빙자료와 새 증빙자료를 합쳐 최대 20개까지 첨부할 수 있습니다.');
            }
        }

        $savedPaths = array();
        $copiedEvidencePaths = array();
        $pdo->beginTransaction();
        try {
            if (is_array($resubmitInfo)) {
                $lockSt = $pdo->prepare("SELECT id,doc_status FROM cpms_approval_documents WHERE id=:id LIMIT 1 FOR UPDATE");
                $lockSt->execute(array(':id' => (int)$resubmitInfo['source_id']));
                $lockedSource = $lockSt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($lockedSource) || strtoupper(trim((string)$lockedSource['doc_status'])) !== 'REJECTED') {
                    throw new Exception('재상신 원문서 상태가 변경되었습니다. 반려 문서를 다시 확인해주세요.');
                }
                $existingChild = self::findResubmitChild($pdo, (int)$resubmitInfo['source_id']);
                if (is_array($existingChild) && isset($existingChild['id']) && (int)$existingChild['id'] > 0) {
                    throw new Exception('이미 재상신된 문서가 있습니다. 재상신 문서 #' . (int)$existingChild['id'] . '를 확인해주세요.');
                }
            }
            $docId = self::insertApprovalDocument($pdo, $creatorId, $creatorName, $creatorEmail, $projectId, $title, $content);
            if ($docId <= 0) throw new Exception('전자결재 문서를 저장하지 못했습니다.');
            self::insertApprovalLines($pdo, $docId, $lines);
            if (is_array($resubmitInfo)) {
                self::insertApprovalLog($pdo, $docId, null, $creatorId, $creatorName, $creatorEmail, 'RESUBMIT', '반려 문서 #' . (int)$resubmitInfo['source_id'] . ' 수정 후 재상신');
                $copiedEvidenceCount = self::copyApprovalFiles($pdo, (int)$resubmitInfo['source_id'], $docId, $copiedEvidencePaths);
                if ($copiedEvidenceCount !== $sourceEvidenceCount) {
                    throw new Exception('기존 증빙자료를 재상신 문서로 연결하지 못했습니다.');
                }
            } else {
                self::insertApprovalLog($pdo, $docId, null, $creatorId, $creatorName, $creatorEmail, 'CREATE', self::formLabel($formType) . ' 작성');
            }
            $savedPaths = self::saveApprovalFiles($pdo, $docId, $files, $creatorName, $formType, $projectId);
            self::queueFirstApprovalNotification($pdo, $docId, $title, $creatorName, $lines);
            $pdo->commit();
            return array('ok' => true, 'document_id' => $docId, 'title' => $title);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            for ($i = 0; $i < count($savedPaths); $i++) {
                if (is_file($savedPaths[$i])) @unlink($savedPaths[$i]);
            }
            for ($i = 0; $i < count($copiedEvidencePaths); $i++) {
                if (is_file($copiedEvidencePaths[$i])) @unlink($copiedEvidencePaths[$i]);
            }
            throw $e;
        }
    }

    private static function buildProposalSpecialNote($content, $reason)
    {
        $lines = array();
        $lines[] = '※ 비용 수정/누락 전용 전자결재';
        $lines[] = '현장: ' . (isset($content['project_name']) ? $content['project_name'] : '');
        $lines[] = '처리구분: ' . (isset($content['correction_mode_label']) ? $content['correction_mode_label'] : '');
        $lines[] = '수정 신청 날짜: ' . (isset($content['use_date']) ? $content['use_date'] : '');
        if (isset($content['labor_changes']) && is_array($content['labor_changes']) && count($content['labor_changes']) > 0) {
            $lines[] = '근로자: 총 ' . count($content['labor_changes']) . '명';
            for ($lc = 0; $lc < count($content['labor_changes']); $lc++) {
                $change = $content['labor_changes'][$lc];
                $lines[] = '- ' . (isset($change['worker_name']) ? $change['worker_name'] : '') . ': ' . self::gongsuText(isset($change['current_gongsu']) ? $change['current_gongsu'] : 0) . ' → ' . self::gongsuText(isset($change['requested_gongsu']) ? $change['requested_gongsu'] : 0);
            }
        } elseif (isset($content['worker_name'])) {
            $lines[] = '근로자: ' . $content['worker_name'];
            $lines[] = '공수: ' . self::gongsuText(isset($content['current_gongsu']) ? $content['current_gongsu'] : 0) . ' → ' . self::gongsuText(isset($content['requested_gongsu']) ? $content['requested_gongsu'] : 0);
        } else {
            $lines[] = '비용구분: ' . (isset($content['cost_category']) ? $content['cost_category'] : '');
            $lines[] = '업체: ' . (isset($content['vendor_name']) ? $content['vendor_name'] : '');
            $lines[] = '내용: ' . (isset($content['item_name']) ? $content['item_name'] : '');
            $lines[] = '수량/공수: ' . (isset($content['quantity']) ? $content['quantity'] : '');
            $lines[] = '단가: ' . number_format(isset($content['unit_price']) ? (float)$content['unit_price'] : 0) . '원';
            $lines[] = '금액: ' . number_format(isset($content['amount']) ? (float)$content['amount'] : 0) . '원';
        }
        $lines[] = '마감월: ' . (isset($content['settlement_ym']) ? $content['settlement_ym'] : '');
        $lines[] = '사유: ' . $reason;
        $lines[] = '최종 승인 시 해당 공사자료에 자동 반영됩니다.';
        return implode("\n", $lines);
    }

    private static function gongsuText($value)
    {
        $value = (float)$value;
        if (abs($value - round($value)) < 0.0001) return number_format($value, 1, '.', '');
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function insertApprovalDocument($pdo, $creatorId, $creatorName, $creatorEmail, $projectId, $title, $content)
    {
        $columns = self::tableColumns($pdo, 'cpms_approval_documents');
        if (count($columns) === 0) throw new Exception('전자결재 DB가 설치되어 있지 않습니다. 기존 전자결재 DB 설치/확인 페이지를 먼저 실행해주세요.');

        $fieldNames = array('doc_type', 'title', 'content', 'doc_status', 'current_step_order', 'created_by_id', 'created_by_name');
        $marks = array(':doc_type', ':title', ':content', ':doc_status', ':current_step_order', ':created_by_id', ':created_by_name');
        $params = array(
            ':doc_type' => 'proposal',
            ':title' => $title,
            ':content' => self::jsonEncode($content),
            ':doc_status' => 'PENDING',
            ':current_step_order' => 1,
            ':created_by_id' => $creatorId,
            ':created_by_name' => $creatorName
        );
        if (isset($columns['created_by_email'])) {
            $fieldNames[] = 'created_by_email'; $marks[] = ':created_by_email'; $params[':created_by_email'] = $creatorEmail;
        }
        if (isset($columns['project_id'])) {
            $fieldNames[] = 'project_id'; $marks[] = ':project_id'; $params[':project_id'] = $projectId;
        }
        if (isset($columns['created_at'])) {
            $fieldNames[] = 'created_at'; $marks[] = 'NOW()';
        }
        if (isset($columns['updated_at'])) {
            $fieldNames[] = 'updated_at'; $marks[] = 'NOW()';
        }
        $sql = "INSERT INTO cpms_approval_documents (" . implode(',', $fieldNames) . ") VALUES (" . implode(',', $marks) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$pdo->lastInsertId();
    }

    private static function insertApprovalLines($pdo, $docId, $lines)
    {
        if (!is_array($lines) || count($lines) === 0) throw new Exception('결재선이 비어 있습니다.');
        $columns = self::tableColumns($pdo, 'cpms_approval_lines');
        $hasEmail = isset($columns['approver_email']);
        $hasDelegated = isset($columns['is_delegated']);
        for ($i = 0; $i < count($lines); $i++) {
            $emp = isset($lines[$i]['emp']) && is_array($lines[$i]['emp']) ? $lines[$i]['emp'] : array();
            $role = isset($lines[$i]['role']) ? (string)$lines[$i]['role'] : '';
            $fields = array('document_id', 'line_order', 'role_type', 'approver_id', 'approver_name', 'line_status');
            $marks = array(':document_id', ':line_order', ':role_type', ':approver_id', ':approver_name', ':line_status');
            $params = array(
                ':document_id' => (int)$docId,
                ':line_order' => $i + 1,
                ':role_type' => $role,
                ':approver_id' => isset($emp['id']) ? (int)$emp['id'] : null,
                ':approver_name' => isset($emp['name']) ? (string)$emp['name'] : '',
                ':line_status' => 'PENDING'
            );
            if ($hasEmail) {
                $fields[] = 'approver_email'; $marks[] = ':approver_email'; $params[':approver_email'] = isset($emp['email']) ? (string)$emp['email'] : '';
            }
            if ($hasDelegated) {
                $fields[] = 'is_delegated'; $marks[] = ':is_delegated'; $params[':is_delegated'] = 0;
            }
            $sql = "INSERT INTO cpms_approval_lines (" . implode(',', $fields) . ") VALUES (" . implode(',', $marks) . ")";
            $pdo->prepare($sql)->execute($params);
        }
    }

    private static function insertApprovalLog($pdo, $docId, $lineId, $actorId, $actorName, $actorEmail, $actionType, $note)
    {
        if (!self::tableExists($pdo, 'cpms_approval_logs')) return;
        try {
            $st = $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at)
                                 VALUES (:d,:l,:a,:n,:e,:t,:m,NOW())");
            $st->execute(array(
                ':d' => (int)$docId,
                ':l' => $lineId === null ? null : (int)$lineId,
                ':a' => (int)$actorId > 0 ? (int)$actorId : null,
                ':n' => (string)$actorName,
                ':e' => (string)$actorEmail,
                ':t' => (string)$actionType,
                ':m' => (string)$note
            ));
        } catch (Exception $e) {
            // 이력 저장 실패가 본 처리 자체를 막지는 않습니다.
        }
    }

    private static function queueFirstApprovalNotification($pdo, $docId, $title, $creatorName, $lines)
    {
        if (!function_exists('approval_queue_notification') || !function_exists('approval_build_request_message')) return;
        if (!is_array($lines) || count($lines) === 0) return;
        $emp = isset($lines[0]['emp']) && is_array($lines[0]['emp']) ? $lines[0]['emp'] : array();
        $receiverId = isset($emp['id']) ? (int)$emp['id'] : 0;
        if ($receiverId <= 0) return;
        try {
            $msg = approval_build_request_message('proposal', $title, $creatorName);
            approval_queue_notification($pdo, $docId, 'REQUEST', $receiverId, $msg);
        } catch (Exception $e) {
        }
    }

    /**
     * 첨부파일을 기존 전자결재 첨부 저장체계에 저장합니다.
     */
    private static function saveApprovalFiles($pdo, $docId, $files, $creatorName, $formType, $projectId)
    {
        $savedPaths = array();
        if (!is_array($files) || !isset($files['name'])) return $savedPaths;

        $names = is_array($files['name']) ? $files['name'] : array($files['name']);
        $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
        $errors = is_array($files['error']) ? $files['error'] : array($files['error']);
        $sizes = is_array($files['size']) ? $files['size'] : array($files['size']);
        $types = isset($files['type']) ? (is_array($files['type']) ? $files['type'] : array($files['type'])) : array();

        if (count($names) > 20) throw new Exception('증빙자료는 한 신청에 최대 20개까지 첨부할 수 있습니다.');

        $allowed = array(
            'pdf'=>true,'xls'=>true,'xlsx'=>true,'xlsm'=>true,'csv'=>true,
            'jpg'=>true,'jpeg'=>true,'png'=>true,'gif'=>true,'webp'=>true,'heic'=>true,'heif'=>true,
            'hwp'=>true,'hwpx'=>true,'doc'=>true,'docx'=>true,'ppt'=>true,'pptx'=>true,'txt'=>true
        );
        $approvalRoot = function_exists('cpms_drive_storage_root')
            ? rtrim((string)cpms_drive_storage_root(), '/\\') . '/approvals'
            : dirname(dirname(__DIR__)) . '/storage/approvals';
        if (!is_dir($approvalRoot) && !@mkdir($approvalRoot, 0775, true) && !is_dir($approvalRoot)) {
            throw new Exception('전자결재 첨부파일 저장폴더를 만들 수 없습니다.');
        }
        $dir = $approvalRoot . '/' . date('Y') . '/' . (int)$docId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('전자결재 증빙자료 저장폴더를 만들 수 없습니다.');
        }

        for ($i = 0; $i < count($names); $i++) {
            $error = isset($errors[$i]) ? (int)$errors[$i] : UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if ($error !== UPLOAD_ERR_OK) throw new Exception('증빙자료 업로드 중 오류가 발생했습니다.');

            $original = basename(str_replace('\\', '/', isset($names[$i]) ? (string)$names[$i] : ''));
            if ($original === '' || strlen($original) > 255) throw new Exception('증빙자료 파일명이 올바르지 않습니다.');
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if ($ext === '' || !isset($allowed[$ext])) throw new Exception('허용되지 않은 증빙자료 형식입니다: ' . $original);
            $size = isset($sizes[$i]) ? (int)$sizes[$i] : 0;
            if ($size <= 0 || $size > 20 * 1024 * 1024) throw new Exception('증빙자료는 파일당 20MB 이하만 첨부할 수 있습니다.');
            $tmp = isset($tmpNames[$i]) ? (string)$tmpNames[$i] : '';
            if ($tmp === '' || !is_file($tmp) || !is_uploaded_file($tmp)) throw new Exception('정상적인 증빙자료 업로드가 아닙니다.');

            $savedName = 'cost_correction_' . date('Ymd_His') . '_' . substr(sha1(uniqid((string)mt_rand(), true)), 0, 12) . '.' . $ext;
            $dest = $dir . '/' . $savedName;
            if (!@move_uploaded_file($tmp, $dest)) throw new Exception('증빙자료 저장에 실패했습니다.');
            $savedPaths[] = $dest;

            $relative = self::relativeToRepoRoot($dest);
            $mime = isset($types[$i]) ? trim((string)$types[$i]) : '';
            if ($mime === '' && function_exists('mime_content_type')) $mime = (string)@mime_content_type($dest);
            $pending = function_exists('cpms_approval_drive_pending_record')
                ? cpms_approval_drive_pending_record($original, $relative, $mime, $size, array('name' => $creatorName))
                : array('storage_type'=>'local','upload_status'=>'pending','mime_type'=>$mime,'size'=>$size,'uploaded_by'=>$creatorName,'uploaded_at'=>date('Y-m-d H:i:s'));
            $row = array_merge($pending, array(
                'document_id' => (int)$docId,
                'original_name' => $original,
                'saved_name' => $savedName,
                'file_path' => $relative,
                'file_label' => '증빙자료',
                'file_type' => 'cost_correction_evidence',
                'document_type' => self::formLabel($formType),
                'project_id' => (int)$projectId
            ));
            if (function_exists('cpms_approval_drive_save_file_row')) {
                $saved = cpms_approval_drive_save_file_row($pdo, $row);
                if (empty($saved['ok'])) throw new Exception('증빙자료 정보 저장에 실패했습니다.');
            } else {
                $st = $pdo->prepare("INSERT INTO cpms_approval_files (document_id,original_name,saved_name,file_path,file_label,file_type,created_at)
                                     VALUES (:d,:o,:s,:p,'증빙자료','cost_correction_evidence',NOW())");
                $st->execute(array(':d'=>$docId,':o'=>$original,':s'=>$savedName,':p'=>$relative));
            }
        }
        return $savedPaths;
    }

    private static function relativeToRepoRoot($path)
    {
        $root = realpath(dirname(dirname(__DIR__)));
        $real = realpath($path);
        if ($root !== false && $real !== false) {
            $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
            $realNorm = str_replace('\\', '/', $real);
            if (strpos($realNorm, $rootNorm) === 0) return substr($realNorm, strlen($rootNorm));
        }
        return str_replace('\\', '/', (string)$path);
    }

    /**
     * 최종승인 후 실제 공사자료에 자동 반영.
     * approval/decide.php 최종승인 직후 호출됩니다.
     */
    public static function applyApprovedDocument($pdo, $documentId, $user)
    {
        $documentId = (int)$documentId;
        if (!$pdo || $documentId <= 0) return array('ok'=>false,'skipped'=>true,'message'=>'전자결재 문서번호가 올바르지 않습니다.');

        try {
            $st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
            $st->execute(array(':id' => $documentId));
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($doc)) return array('ok'=>false,'skipped'=>true,'message'=>'전자결재 문서를 찾을 수 없습니다.');
            $content = self::jsonDecode(isset($doc['content']) ? $doc['content'] : '');
            if (!self::isCorrectionContent($content)) return array('ok'=>true,'skipped'=>true,'message'=>'일반 전자결재 문서입니다.');
            if (strtoupper(isset($doc['doc_status']) ? (string)$doc['doc_status'] : '') !== 'APPROVED') {
                return array('ok'=>false,'skipped'=>true,'message'=>'최종승인 문서가 아닙니다.');
            }

            if (self::approvalLogExists($pdo, $documentId, 'AUTO_APPLY')) {
                return array('ok'=>true,'skipped'=>true,'message'=>'이미 공사자료에 반영된 문서입니다.');
            }

            $formType = isset($content['correction_form_type']) ? strtolower(trim((string)$content['correction_form_type'])) : '';
            if (!self::validFormType($formType)) throw new Exception('자동 반영할 신청 양식을 확인할 수 없습니다.');

            $pdo->beginTransaction();
            if ($formType === self::FORM_LABOR) {
                $applyResult = self::applyLaborCorrection($pdo, $documentId, $doc, $content, $user);
            } else {
                $applyResult = self::applyCostCorrection($pdo, $documentId, $doc, $content, $user);
            }

            $content['auto_apply_status'] = 'APPLIED';
            $content['auto_applied_at'] = date('Y-m-d H:i:s');
            $content['auto_applied_target_type'] = isset($applyResult['target_type']) ? (string)$applyResult['target_type'] : '';
            $content['auto_applied_target_id'] = isset($applyResult['target_id']) ? (string)$applyResult['target_id'] : '';
            $content['auto_apply_message'] = '최종승인 후 공사자료 자동 반영 완료';
            $up = $pdo->prepare("UPDATE cpms_approval_documents SET content=:content,updated_at=NOW() WHERE id=:id");
            $up->execute(array(':content'=>self::jsonEncode($content),':id'=>$documentId));

            $actor = self::userIdentity($user);
            self::insertApprovalLog(
                $pdo,
                $documentId,
                null,
                isset($actor['id']) ? (int)$actor['id'] : 0,
                isset($actor['name']) ? (string)$actor['name'] : '',
                isset($actor['email']) ? (string)$actor['email'] : '',
                'AUTO_APPLY',
                '최종승인 후 공사자료 자동 반영: ' . (isset($applyResult['target_type']) ? $applyResult['target_type'] : '') . ' #' . (isset($applyResult['target_id']) ? $applyResult['target_id'] : '')
            );
            $pdo->commit();
            return array('ok'=>true,'skipped'=>false,'message'=>'공사자료에 자동 반영했습니다.','result'=>$applyResult);
        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
            self::markApplyFailed($pdo, $documentId, $e->getMessage(), $user);
            error_log('[ApprovalCostCorrectionService] auto apply failed document_id=' . $documentId . ' / ' . $e->getMessage());
            return array('ok'=>false,'skipped'=>false,'message'=>$e->getMessage());
        }
    }

    private static function approvalLogExists($pdo, $documentId, $actionType)
    {
        if (!self::tableExists($pdo, 'cpms_approval_logs')) return false;
        try {
            $st = $pdo->prepare("SELECT id FROM cpms_approval_logs WHERE document_id=:d AND action_type=:t ORDER BY id DESC LIMIT 1");
            $st->execute(array(':d'=>(int)$documentId,':t'=>(string)$actionType));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }

    private static function userIdentity($user)
    {
        $id = 0; $name = ''; $email = '';
        if (is_array($user)) {
            if (isset($user['id'])) $id = (int)$user['id'];
            if (isset($user['name'])) $name = trim((string)$user['name']);
            if (isset($user['email'])) $email = trim((string)$user['email']);
        }
        if (class_exists('App\\Core\\Auth')) {
            if ($name === '' && method_exists('App\\Core\\Auth', 'userName')) $name = (string)\App\Core\Auth::userName();
            if ($email === '' && method_exists('App\\Core\\Auth', 'userEmail')) $email = (string)\App\Core\Auth::userEmail();
        }
        return array('id'=>$id,'name'=>$name,'email'=>$email);
    }

    private static function markApplyFailed($pdo, $documentId, $message, $user)
    {
        if (!$pdo || (int)$documentId <= 0) return;
        try {
            $st = $pdo->prepare("SELECT content FROM cpms_approval_documents WHERE id=:id LIMIT 1");
            $st->execute(array(':id'=>(int)$documentId));
            $content = self::jsonDecode($st->fetchColumn());
            if (!self::isCorrectionContent($content)) return;
            $content['auto_apply_status'] = 'FAILED';
            $content['auto_apply_error'] = self::safeText($message, 1000);
            $content['auto_apply_failed_at'] = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE cpms_approval_documents SET content=:c,updated_at=NOW() WHERE id=:id")
                ->execute(array(':c'=>self::jsonEncode($content),':id'=>(int)$documentId));
            $actor = self::userIdentity($user);
            self::insertApprovalLog($pdo,(int)$documentId,null,(int)$actor['id'],(string)$actor['name'],(string)$actor['email'],'AUTO_APPLY_FAILED',self::safeText($message,2000));
        } catch (Exception $ignore) {
        }
    }

    private static function applyLaborCorrection($pdo, $documentId, $doc, $content, $user)
    {
        $projectId = isset($content['project_id']) ? (int)$content['project_id'] : 0;
        $workDate = self::validDate(isset($content['use_date']) ? $content['use_date'] : '');
        if ($projectId <= 0 || $workDate === '') {
            throw new Exception('노무비 자동 반영 데이터가 올바르지 않습니다.');
        }

        $changes = isset($content['labor_changes']) && is_array($content['labor_changes']) ? $content['labor_changes'] : array();
        if (count($changes) === 0) {
            // V2에서 이미 작성된 단건 문서도 계속 자동 반영할 수 있도록 지원합니다.
            $legacyWorker = isset($content['worker_name']) ? trim((string)$content['worker_name']) : '';
            $legacyRequested = isset($content['requested_gongsu']) ? (float)$content['requested_gongsu'] : -1;
            if ($legacyWorker !== '' && $legacyRequested >= 0) {
                $changes[] = array('worker_name'=>$legacyWorker,'requested_gongsu'=>$legacyRequested);
            }
        }
        if (count($changes) === 0) throw new Exception('자동 반영할 노무비 근로자 정보가 없습니다.');

        if (function_exists('cpms_ensure_labor_override_table')) {
            if (!cpms_ensure_labor_override_table($pdo)) throw new Exception('노무비 공수 변경 테이블을 확인할 수 없습니다.');
        }
        if (!self::tableExists($pdo, 'cpms_labor_gongsu_overrides')) {
            throw new Exception('노무비 공수 변경 테이블이 없습니다. 기존 노무비 수정승인 기능을 먼저 설치/확인해주세요.');
        }

        $month = substr($workDate, 0, 7);
        $columns = self::tableColumns($pdo, 'cpms_labor_gongsu_overrides');
        $batchToken = 'APPROVAL-' . (int)$documentId;
        $targetIds = array();
        $appliedRows = array();

        for ($ci = 0; $ci < count($changes); $ci++) {
            $workerName = isset($changes[$ci]['worker_name']) ? trim((string)$changes[$ci]['worker_name']) : '';
            $requested = isset($changes[$ci]['requested_gongsu']) ? (float)$changes[$ci]['requested_gongsu'] : -1;
            if ($workerName === '' || $requested < 0) throw new Exception('노무비 자동 반영 근로자 데이터가 올바르지 않습니다.');

            $current = self::currentLaborGongsu($pdo, $projectId, $workerName, $workDate);
            $values = array(
                'project_id' => $projectId,
                'month' => $month,
                'batch_token' => $batchToken,
                'request_scope' => 'electronic_approval',
                'worker_key' => function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($workerName) : strtolower($workerName),
                'worker_name' => $workerName,
                'work_date' => $workDate,
                'old_value' => $current,
                'new_value' => $requested,
                'is_deleted_entry' => 0,
                'reason' => isset($content['reason']) ? (string)$content['reason'] : '',
                'status' => 'applied',
                'requested_by' => isset($doc['created_by_id']) ? (int)$doc['created_by_id'] : null,
                'requested_by_email' => isset($doc['created_by_email']) ? (string)$doc['created_by_email'] : '',
                'requested_by_name' => isset($doc['created_by_name']) ? (string)$doc['created_by_name'] : '',
                'approval_stage' => 'final',
                'approval_required_level' => 4,
                'current_approver_employee_id' => null,
                'current_approver_name' => '',
                'current_approver_email' => '',
                'approved_by' => isset($user['id']) ? (int)$user['id'] : null,
                'approved_at' => date('Y-m-d H:i:s'),
                'final_approved_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            );

            $fields = array(); $marks = array(); $params = array(); $idx = 0;
            foreach ($values as $column => $value) {
                if (!isset($columns[$column])) continue;
                $param = ':p' . $ci . '_' . $idx;
                $fields[] = '`' . $column . '`';
                $marks[] = $param;
                $params[$param] = $value;
                $idx++;
            }
            if (count($fields) < 8) throw new Exception('노무비 공수 변경 테이블 구조를 확인할 수 없습니다.');
            $sql = "INSERT INTO cpms_labor_gongsu_overrides (" . implode(',', $fields) . ") VALUES (" . implode(',', $marks) . ")";
            $pdo->prepare($sql)->execute($params);
            $targetId = (string)$pdo->lastInsertId();
            if ($targetId === '0' || $targetId === '') throw new Exception($workerName . ' 노무비 공수 자동 반영에 실패했습니다.');
            $targetIds[] = $targetId;
            $appliedRows[] = array('worker_name'=>$workerName,'target_id'=>$targetId,'old_value'=>$current,'new_value'=>$requested);
        }

        return array(
            'target_type'=>'labor_gongsu_override',
            'target_id'=>implode(',', $targetIds),
            'target_ids'=>$targetIds,
            'worker_count'=>count($appliedRows),
            'rows'=>$appliedRows
        );
    }

    private static function applyCostCorrection($pdo, $documentId, $doc, $content, $user)
    {
        if (!CostChangeService::isInstalled($pdo)) {
            throw new Exception('기존 비용변경 승인 기능이 설치되어 있지 않습니다. 관리자용 비용변경 설치/확인 화면을 먼저 실행해주세요.');
        }

        $formType = isset($content['correction_form_type']) ? (string)$content['correction_form_type'] : '';
        $category = isset($content['cost_category']) ? (string)$content['cost_category'] : '';
        $targetType = isset($content['target_type']) ? (string)$content['target_type'] : self::targetTypeFor($formType, $category);
        $mode = isset($content['correction_mode']) ? strtoupper((string)$content['correction_mode']) : self::MODE_ADD;
        $projectId = isset($content['project_id']) ? (int)$content['project_id'] : 0;
        $targetId = isset($content['target_id']) ? trim((string)$content['target_id']) : '';
        $vendorId = isset($content['vendor_id']) ? (int)$content['vendor_id'] : 0;
        $vendor = self::vendor($pdo, $vendorId);
        if (!$vendor) throw new Exception('승인문서에 연결된 업체가 업체관리에서 확인되지 않습니다.');
        $vendorName = isset($vendor['name']) ? trim((string)$vendor['name']) : '';

        $requestedData = array(
            'target_type' => $targetType,
            'cost_type' => $targetType,
            'project_id' => $projectId,
            'category' => $category,
            'use_date' => isset($content['use_date']) ? (string)$content['use_date'] : '',
            'vendor_name' => $vendorName,
            'item_name' => isset($content['item_name']) ? (string)$content['item_name'] : '',
            'quantity' => isset($content['quantity']) ? (float)$content['quantity'] : 1,
            'unit_price' => isset($content['unit_price']) ? (float)$content['unit_price'] : 0,
            'amount' => isset($content['amount']) ? (float)$content['amount'] : 0,
            'memo' => isset($content['memo']) ? (string)$content['memo'] : '',
            'settlement_ym' => isset($content['settlement_ym']) ? (string)$content['settlement_ym'] : ''
        );
        if ($mode === self::MODE_MODIFY && isset($content['old_target']) && is_array($content['old_target']) && isset($content['old_target']['master_id'])) {
            $requestedData['master_id'] = (int)$content['old_target']['master_id'];
        }

        $syntheticRequest = array(
            'id' => 0,
            'project_id' => $projectId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_type' => $mode === self::MODE_MODIFY ? CostChangeService::REQUEST_MODIFY : CostChangeService::REQUEST_ADD,
            'requested_data' => self::jsonEncode($requestedData),
            'old_data' => isset($content['old_target']) ? self::jsonEncode($content['old_target']) : '{}',
            'reason' => isset($content['reason']) ? (string)$content['reason'] : '',
            'requester_employee_id' => isset($doc['created_by_id']) ? (int)$doc['created_by_id'] : 0,
            'requester_name' => isset($doc['created_by_name']) ? (string)$doc['created_by_name'] : '',
            'requester_email' => isset($doc['created_by_email']) ? (string)$doc['created_by_email'] : ''
        );

        $applyResult = CostChangeService::applyRequest($pdo, $syntheticRequest);
        if (!is_array($applyResult) || empty($applyResult['ok'])) throw new Exception('비용자료 자동 반영에 실패했습니다.');
        $appliedTargetId = isset($applyResult['target_id']) ? trim((string)$applyResult['target_id']) : '';
        if ($appliedTargetId === '') throw new Exception('반영된 비용자료 번호를 확인할 수 없습니다.');

        self::attachVendorToAppliedRecord($pdo, $targetType, $appliedTargetId, $vendorId);
        return array('target_type'=>$targetType,'target_id'=>$appliedTargetId,'vendor_id'=>$vendorId,'settlement_ym'=>isset($applyResult['settlement_ym']) ? $applyResult['settlement_ym'] : '');
    }

    private static function attachVendorToAppliedRecord($pdo, $targetType, $targetId, $vendorId)
    {
        $vendorId = (int)$vendorId;
        if ($vendorId <= 0 || trim((string)$targetId) === '') return;
        try {
            if ($targetType === 'material') {
                $st = $pdo->prepare("SELECT material_id FROM cpms_material_usage WHERE id=:id LIMIT 1");
                $st->execute(array(':id'=>(int)$targetId));
                $masterId = (int)$st->fetchColumn();
                if ($masterId > 0) VendorService::attachDbRecord($pdo, 'cpms_material_items', $masterId, $vendorId);
            } elseif ($targetType === 'equipment') {
                $st = $pdo->prepare("SELECT equipment_id FROM cpms_equipment_usage WHERE id=:id LIMIT 1");
                $st->execute(array(':id'=>(int)$targetId));
                $masterId = (int)$st->fetchColumn();
                if ($masterId > 0) VendorService::attachDbRecord($pdo, 'cpms_equipment_items', $masterId, $vendorId);
            } elseif ($targetType === 'outsourcing') {
                VendorService::attachDbRecord($pdo, 'cpms_outsourcing_costs', (int)$targetId, $vendorId);
            }
            // 안전관리비는 JSON 기반 안전관리비 원본에 업체명이 저장됩니다.
            // 선택한 vendor_id는 전자결재 원문에 함께 보존되어 추적 가능합니다.
        } catch (Exception $e) {
            throw new Exception('비용자료는 반영했지만 업체 연결 중 오류가 발생했습니다: ' . $e->getMessage());
        }
    }
}
