<?php
/*
 * 파일경로: public/approval_edit_store.php
 * 기능: 전자결재 첫 결재 전 수정 / 반려문서 수정 후 재상신 저장
 * PHP 5.6 호환
 *
 * DB 컬럼 추가 없이 기존 전자결재 테이블을 그대로 사용합니다.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/views/approval/_common.php';
require_once __DIR__ . '/../app/views/approval/template_helpers.php';
require_once __DIR__ . '/../app/views/approval/notification_helpers.php';
require_once __DIR__ . '/../app/views/approval/line_rules.php';
require_once __DIR__ . '/../app/views/approval/resubmit_helpers.php';
require_once __DIR__ . '/../app/services/ApprovalDriveService.php';

use App\Core\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?r=approval_home');
    exit;
}
csrf_validate();

$pdo = Db::pdo();
$user = \App\Core\Auth::user();
if (!$pdo || !$user) {
    header('Location: index.php');
    exit;
}

if (!function_exists('approval_edit_store_money')) {
    function approval_edit_store_money($value)
    {
        $digits = preg_replace('/[^0-9]/', '', (string)$value);
        if ($digits === '') return '';
        $digits = ltrim($digits, '0');
        if ($digits === '') return '0';
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $digits);
    }
}

if (!function_exists('approval_edit_store_money_text_callback')) {
    function approval_edit_store_money_text_callback($matches)
    {
        return approval_edit_store_money(isset($matches[0]) ? $matches[0] : '');
    }
}

if (!function_exists('approval_edit_store_money_text')) {
    function approval_edit_store_money_text($value)
    {
        return preg_replace_callback('/[0-9]+(?:,[0-9]+)*/', 'approval_edit_store_money_text_callback', (string)$value);
    }
}

if (!function_exists('approval_edit_store_employee')) {
    function approval_edit_store_employee($pdo, $id)
    {
        if (!$pdo || (int)$id <= 0) return null;
        try {
            $columns = approval_table_columns($pdo, 'employees', false);
            $role = isset($columns['role']) ? 'role' : "'' AS role";
            $position = isset($columns['position']) ? 'position' : "'' AS position";
            $department = isset($columns['department']) ? 'department' : "'' AS department";
            $teamLeaderId = isset($columns['team_leader_id']) ? 'team_leader_id' : '0 AS team_leader_id';
            $isTeamLeader = isset($columns['is_team_leader']) ? 'is_team_leader' : '0 AS is_team_leader';
            $approvalLead = isset($columns['approval_can_be_team_leader']) ? 'approval_can_be_team_leader' : '0 AS approval_can_be_team_leader';
            $approvalGongmu = isset($columns['approval_can_be_gongmu_approver']) ? 'approval_can_be_gongmu_approver' : '0 AS approval_can_be_gongmu_approver';
            $approvalManage = isset($columns['approval_can_be_manage_approver']) ? 'approval_can_be_manage_approver' : '0 AS approval_can_be_manage_approver';
            $sql = "SELECT id,name,email," . $department . "," . $position . "," . $role . "," . $teamLeaderId . "," . $isTeamLeader . "," . $approvalLead . "," . $approvalGongmu . "," . $approvalManage . " FROM employees WHERE id=:id AND is_active=1 LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute(array(':id' => (int)$id));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('approval_edit_store_project_root')) {
    function approval_edit_store_project_root()
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('approval_edit_store_uploaded_files')) {
    function approval_edit_store_uploaded_files($fieldName)
    {
        if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) return array();
        $field = $_FILES[$fieldName];
        if (!isset($field['name']) || !is_array($field['name'])) return array($field);
        $files = array();
        for ($i = 0; $i < count($field['name']); $i++) {
            $files[] = array(
                'name' => isset($field['name'][$i]) ? $field['name'][$i] : '',
                'type' => isset($field['type'][$i]) ? $field['type'][$i] : '',
                'tmp_name' => isset($field['tmp_name'][$i]) ? $field['tmp_name'][$i] : '',
                'error' => isset($field['error'][$i]) ? $field['error'][$i] : UPLOAD_ERR_NO_FILE,
                'size' => isset($field['size'][$i]) ? $field['size'][$i] : 0
            );
        }
        return $files;
    }
}

if (!function_exists('approval_edit_store_lines_have_decision')) {
    function approval_edit_store_lines_have_decision($lines)
    {
        if (!is_array($lines)) return true;
        for ($i = 0; $i < count($lines); $i++) {
            $status = isset($lines[$i]['line_status']) ? strtoupper(trim((string)$lines[$i]['line_status'])) : '';
            if ($status === 'APPROVED' || $status === 'REJECTED') return true;
        }
        return false;
    }
}

if (!function_exists('approval_edit_store_auto_role_rank')) {
    function approval_edit_store_auto_role_rank($role)
    {
        $roleRawNorm = approval_normalize_compare_text($role);
        $roleNorm = approval_normalize_compare_text(approval_role_label($role));
        $manageNorm = approval_normalize_compare_text(approval_ko('%EA%B4%80%EB%A6%AC'));
        $gongmuNorm = approval_normalize_compare_text(approval_ko('%EA%B3%B5%EB%AC%B4'));
        $teamNorm = approval_normalize_compare_text(approval_ko('%ED%8C%80%EC%9E%A5'));
        $siteNorm = approval_normalize_compare_text(approval_ko('%EC%86%8C%EC%9E%A5'));
        $constructionPmNorm = approval_normalize_compare_text(approval_ko('%EA%B3%B5%EC%82%AC%50%4D'));
        if ($roleNorm === $manageNorm) return 1;
        if ($roleNorm === $gongmuNorm) return 2;
        if ($roleNorm === $teamNorm || $roleNorm === $siteNorm) return 3;
        if ($roleRawNorm === 'pm' || $roleNorm === 'pm' || $roleRawNorm === $constructionPmNorm || $roleNorm === $constructionPmNorm) return 4;
        if (approval_role_is_vp($role)) return 5;
        if (approval_role_is_ceo($role)) return 6;
        return 0;
    }
}

if (!function_exists('approval_edit_store_mark_delegated')) {
    function approval_edit_store_mark_delegated(&$line, $reason, $delegatedByRole)
    {
        if (!is_array($line)) return;
        $line['status'] = 'DELEGATED';
        $line['delegated'] = 1;
        $line['auto_reason'] = trim((string)$reason);
        $line['acted_at'] = date('Y-m-d H:i:s');
        if ($delegatedByRole !== null) $line['delegated_by_role'] = $delegatedByRole;
    }
}

if (!function_exists('approval_edit_store_force_actual_waiting')) {
    function approval_edit_store_force_actual_waiting(&$line)
    {
        if (!is_array($line)) return;
        $status = isset($line['status']) ? strtoupper(trim((string)$line['status'])) : '';
        if ($status === 'APPROVED' || $status === 'REJECTED') return;
        $line['delegated'] = 0;
        if ($status === 'DELEGATED') $line['status'] = 'WAITING';
        if (isset($line['auto_reason'])) unset($line['auto_reason']);
        if (isset($line['delegated_by_role'])) unset($line['delegated_by_role']);
    }
}

if (!function_exists('approval_edit_store_apply_auto_delegation')) {
    function approval_edit_store_apply_auto_delegation($pdo, $docType, &$lines, $creator)
    {
        if (!approval_auto_delegate_target_doc_type($docType) || !is_array($lines)) return;
        $creatorId = isset($creator['id']) ? (int)$creator['id'] : 0;
        $creatorEmail = isset($creator['email']) ? trim((string)$creator['email']) : '';
        $creatorName = isset($creator['name']) ? trim((string)$creator['name']) : '';
        $creatorIndex = -1;
        $forceCeoActual = approval_employee_is_vp($creator);
        $vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');
        $ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
        $baseDate = date('Y-m-d');

        for ($i = 0; $i < count($lines); $i++) {
            if (!empty($lines[$i]['force_actual'])) {
                $forceCeoActual = true;
            }
        }

        for ($i = 0; $i < count($lines); $i++) {
            if (isset($lines[$i]['emp']) && approval_employee_identity_matches($lines[$i]['emp'], $creatorId, $creatorEmail, $creatorName)) {
                $creatorIndex = $i;
                break;
            }
        }
        if ($creatorIndex >= 0) {
            $creatorRole = isset($lines[$creatorIndex]['role']) ? $lines[$creatorIndex]['role'] : '';
            $creatorRank = approval_edit_store_auto_role_rank($creatorRole);
            for ($i = 0; $i < count($lines); $i++) {
                $rank = approval_edit_store_auto_role_rank(isset($lines[$i]['role']) ? $lines[$i]['role'] : '');
                if (($creatorRank > 0 && $rank > 0 && $rank < $creatorRank) || ($creatorRank <= 0 && $i < $creatorIndex)) {
                    approval_edit_store_mark_delegated($lines[$i], approval_auto_delegate_reason_label('previous_step'), null);
                }
            }
            if (empty($lines[$creatorIndex]['allow_self_approval'])) {
                approval_edit_store_mark_delegated($lines[$creatorIndex], approval_auto_delegate_reason_label('self'), null);
            }
        }

        if ($docType === 'leave' && approval_employee_is_executive($creator)) {
            for ($i = 0; $i < count($lines); $i++) {
                $role = isset($lines[$i]['role']) ? $lines[$i]['role'] : '';
                $status = isset($lines[$i]['status']) ? strtoupper((string)$lines[$i]['status']) : '';
                if ($status !== 'DELEGATED' && approval_role_is_team_or_pm($role)) {
                    approval_edit_store_mark_delegated($lines[$i], approval_auto_delegate_reason_label('higher_position'), null);
                }
            }
        }

        for ($i = 0; $i < count($lines); $i++) {
            $role = isset($lines[$i]['role']) ? $lines[$i]['role'] : '';
            $empId = isset($lines[$i]['emp']['id']) ? (int)$lines[$i]['emp']['id'] : 0;
            $status = isset($lines[$i]['status']) ? strtoupper((string)$lines[$i]['status']) : '';
            if ($status === 'DELEGATED' || !empty($lines[$i]['skip_auto_delegate'])) continue;
            if (approval_role_is_vp($role) && approval_is_employee_on_leave($pdo, $empId, $baseDate)) {
                approval_edit_store_mark_delegated($lines[$i], approval_auto_delegate_reason_label('vp_leave_ceo_proxy'), $ceoRole);
                $forceCeoActual = true;
            }
        }

        if ($forceCeoActual) {
            for ($i = 0; $i < count($lines); $i++) {
                $role = isset($lines[$i]['role']) ? $lines[$i]['role'] : '';
                if (approval_role_is_ceo($role)) {
                    approval_edit_store_force_actual_waiting($lines[$i]);
                }
            }
        }

        for ($i = 0; $i < count($lines); $i++) {
            $role = isset($lines[$i]['role']) ? $lines[$i]['role'] : '';
            $empId = isset($lines[$i]['emp']['id']) ? (int)$lines[$i]['emp']['id'] : 0;
            $status = isset($lines[$i]['status']) ? strtoupper((string)$lines[$i]['status']) : '';
            if ($status === 'DELEGATED' || !empty($lines[$i]['skip_auto_delegate'])) continue;
            if (approval_is_employee_on_leave($pdo, $empId, $baseDate)) {
                approval_edit_store_mark_delegated($lines[$i], approval_auto_delegate_reason_label('on_leave'), null);
                continue;
            }
            if ($docType === 'leave' && approval_role_is_ceo($role) && !$forceCeoActual) {
                approval_edit_store_mark_delegated($lines[$i], approval_auto_delegate_reason_label('leave_ceo_default'), $vpRole);
            }
        }
    }
}

if (!function_exists('approval_edit_store_build_content')) {
    function approval_edit_store_build_content($pdo, $docType, $oldContent, $creator, &$title, &$lines, $rebuildLines)
    {
        $oldContent = is_array($oldContent) ? $oldContent : array();
        $title = '';
        $lines = array();
        $creatorName = isset($creator['name']) ? trim((string)$creator['name']) : '';
        $creatorEmail = isset($creator['email']) ? trim((string)$creator['email']) : '';
        $creatorDepartment = isset($creator['department']) ? trim((string)$creator['department']) : '';
        $creatorPosition = isset($creator['position']) ? trim((string)$creator['position']) : '';

        if ($docType === 'leave') {
            $start = isset($_POST['leave_start_date']) ? trim((string)$_POST['leave_start_date']) : '';
            $end = isset($_POST['leave_end_date']) ? trim((string)$_POST['leave_end_date']) : '';
            if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
                throw new Exception('휴가 기간을 다시 확인해 주세요.');
            }
            $days = isset($_POST['leave_days']) ? trim((string)$_POST['leave_days']) : '';
            if ($days === '' || (float)$days <= 0) $days = (string)(floor((strtotime($end) - strtotime($start)) / 86400) + 1);
            $ceo = approval_line_rules_find_ceo($pdo);
            $newContent = array(
                'request_type' => isset($_POST['request_type']) ? trim((string)$_POST['request_type']) : '',
                'request_type_etc' => isset($_POST['request_type_etc']) ? trim((string)$_POST['request_type_etc']) : '',
                'department' => $creatorDepartment,
                'position' => $creatorPosition,
                'applicant_name' => $creatorName,
                'birth_date' => isset($_POST['birth_date']) ? trim((string)$_POST['birth_date']) : (isset($oldContent['birth_date']) ? $oldContent['birth_date'] : ''),
                'leave_start_date' => $start,
                'leave_end_date' => $end,
                'leave_days' => $days,
                'leave_reason' => isset($_POST['leave_reason']) ? trim((string)$_POST['leave_reason']) : '',
                'request_date' => isset($_POST['request_date']) ? trim((string)$_POST['request_date']) : date('Y-m-d'),
                'applicant_sign_name' => $creatorName,
                'applicant_email' => $creatorEmail,
                'writer_email' => $creatorEmail,
                'ceo_name' => is_array($ceo) && isset($ceo['name']) ? $ceo['name'] : '',
                'delegate_level' => 'none'
            );
            $content = array_merge($oldContent, $newContent);
            $title = approval_doc_label('leave') . ' - ' . $creatorName;
            if (!$rebuildLines) {
                return $content;
            }
            $ruleResult = approval_line_rules_build($pdo, $docType, $creator, $content);
            if (approval_line_rules_requires_manual_team_leader($creator) && (!isset($ruleResult['team_lead']) || !is_array($ruleResult['team_lead']))) {
                throw new Exception('현장 팀장을 선택해 주세요.');
            }
            $lines = isset($ruleResult['lines']) && is_array($ruleResult['lines']) ? $ruleResult['lines'] : array();
            $content['approval_line_messages'] = isset($ruleResult['messages']) && is_array($ruleResult['messages']) ? $ruleResult['messages'] : array();
            $content['approval_line_warnings'] = isset($ruleResult['warnings']) && is_array($ruleResult['warnings']) ? $ruleResult['warnings'] : array();
            $content['approval_force_ceo_actual'] = isset($ruleResult['force_ceo_actual']) ? (int)$ruleResult['force_ceo_actual'] : 0;
            $content['approval_line_preview'] = approval_line_rules_line_names($lines);
            return $content;
        }

        $newContent = array(
            'draft_date' => isset($_POST['draft_date']) ? trim((string)$_POST['draft_date']) : '',
            'effective_date' => isset($_POST['effective_date']) ? trim((string)$_POST['effective_date']) : '',
            'draft_department' => $creatorDepartment,
            'drafter_name' => $creatorName,
            'draft_type' => isset($_POST['draft_type']) ? trim((string)$_POST['draft_type']) : '',
            'title' => isset($_POST['title']) ? trim((string)$_POST['title']) : '',
            'headline' => isset($_POST['headline']) ? trim((string)$_POST['headline']) : '',
            'intro_text' => isset($_POST['intro_text']) ? trim((string)$_POST['intro_text']) : '',
            'reason' => isset($_POST['reason']) ? trim((string)$_POST['reason']) : '',
            'company_name' => isset($_POST['company_name']) ? trim((string)$_POST['company_name']) : '',
            'contract_amount' => approval_edit_store_money(isset($_POST['contract_amount']) ? $_POST['contract_amount'] : ''),
            'advance_amount' => approval_edit_store_money(isset($_POST['advance_amount']) ? $_POST['advance_amount'] : ''),
            'special_note' => isset($_POST['special_note']) ? trim((string)$_POST['special_note']) : '',
            'payment_request_date' => isset($_POST['payment_request_date']) ? trim((string)$_POST['payment_request_date']) : '',
            'budget_status' => approval_edit_store_money_text(isset($_POST['budget_status']) ? $_POST['budget_status'] : ''),
            'writer_name' => $creatorName,
            'writer_email' => $creatorEmail,
            'delegate_level' => 'none'
        );
        $content = array_merge($oldContent, $newContent);
        $title = $content['title'] !== '' ? $content['title'] : approval_doc_label($docType);
        if (!$rebuildLines) {
            return $content;
        }
        $ruleResult = approval_line_rules_build($pdo, $docType, $creator, $content);
        if (approval_line_rules_requires_manual_team_leader_for_doc($creator, $docType) && (!isset($ruleResult['team_lead']) || !is_array($ruleResult['team_lead']))) {
            throw new Exception('현장 팀장을 선택해 주세요.');
        }
        $lines = isset($ruleResult['lines']) && is_array($ruleResult['lines']) ? $ruleResult['lines'] : array();
        $content['approval_line_messages'] = isset($ruleResult['messages']) && is_array($ruleResult['messages']) ? $ruleResult['messages'] : array();
        $content['approval_line_warnings'] = isset($ruleResult['warnings']) && is_array($ruleResult['warnings']) ? $ruleResult['warnings'] : array();
        $content['approval_force_ceo_actual'] = isset($ruleResult['force_ceo_actual']) ? (int)$ruleResult['force_ceo_actual'] : 0;
        $content['approval_line_preview'] = approval_line_rules_line_names($lines);
        return $content;
    }
}

if (!function_exists('approval_edit_store_clone_file_row')) {
    function approval_edit_store_clone_file_row($pdo, $sourceDocumentId, $sourceFileId, $targetDocumentId)
    {
        if (!$pdo || (int)$sourceFileId <= 0 || (int)$targetDocumentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_files')) return false;
        $st = $pdo->prepare("SELECT * FROM cpms_approval_files WHERE id=:fid AND document_id=:did LIMIT 1");
        $st->execute(array(':fid' => (int)$sourceFileId, ':did' => (int)$sourceDocumentId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        /*
         * 원본 반려문서와 재상신 문서가 같은 로컬 파일을 공유하면,
         * 나중에 한쪽 문서를 삭제할 때 다른 문서의 파일까지 없어질 수 있습니다.
         * 로컬 파일이 남아 있다면 재상신 문서 전용 사본을 만들어 경로를 분리합니다.
         */
        $oldRelativePath = isset($row['file_path']) ? trim((string)$row['file_path']) : '';
        if ($oldRelativePath !== '') {
            $root = approval_edit_store_project_root();
            $oldAbsolutePath = rtrim($root, '/\\') . '/' . ltrim(str_replace('\\', '/', $oldRelativePath), '/');
            if (is_file($oldAbsolutePath)) {
                $base = rtrim($root, '/\\') . '/storage/approvals/' . (int)$targetDocumentId . '/files';
                if (!is_dir($base)) @mkdir($base, 0777, true);
                if (!is_dir($base)) return false;

                $extension = strtolower(pathinfo(isset($row['saved_name']) ? $row['saved_name'] : '', PATHINFO_EXTENSION));
                if ($extension === '') {
                    $extension = strtolower(pathinfo(isset($row['original_name']) ? $row['original_name'] : '', PATHINFO_EXTENSION));
                }
                $newSavedName = 'carry_' . (int)$sourceFileId . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999);
                if ($extension !== '') $newSavedName .= '.' . $extension;
                $newAbsolutePath = $base . '/' . $newSavedName;
                if (!@copy($oldAbsolutePath, $newAbsolutePath)) return false;

                $row['saved_name'] = $newSavedName;
                $row['file_path'] = 'storage/approvals/' . (int)$targetDocumentId . '/files/' . $newSavedName;
                if (isset($row['file_size'])) $row['file_size'] = is_file($newAbsolutePath) ? (int)@filesize($newAbsolutePath) : $row['file_size'];
            } else {
                $uploadStatus = isset($row['upload_status']) ? strtolower(trim((string)$row['upload_status'])) : '';
                $driveFileId = isset($row['drive_file_id']) ? trim((string)$row['drive_file_id']) : '';
                if ($uploadStatus === 'uploaded' && $driveFileId !== '') {
                    /* Drive 원본이 있으므로 존재하지 않는 로컬 경로는 새 문서에 넘기지 않습니다. */
                    $row['file_path'] = '';
                    $row['saved_name'] = '';
                } else {
                    return false;
                }
            }
        }

        $columns = approval_table_columns($pdo, 'cpms_approval_files', false);
        $insertColumns = array();
        $marks = array();
        $params = array();
        foreach ($row as $column => $value) {
            if ($column === 'id' || !isset($columns[$column])) continue;
            $param = ':c_' . $column;
            $insertColumns[] = '`' . str_replace('`', '', $column) . '`';
            $marks[] = $param;
            $params[$param] = ($column === 'document_id') ? (int)$targetDocumentId : $value;
        }
        if (count($insertColumns) === 0) return false;
        $sql = "INSERT INTO cpms_approval_files (" . implode(',', $insertColumns) . ") VALUES (" . implode(',', $marks) . ")";
        $pdo->prepare($sql)->execute($params);
        return true;
    }
}

if (!function_exists('approval_edit_store_remove_files')) {
    function approval_edit_store_remove_files($pdo, $documentId, $fileIds)
    {
        if (!$pdo || !is_array($fileIds) || !approval_table_exists($pdo, 'cpms_approval_files')) return;
        $root = approval_edit_store_project_root();
        for ($i = 0; $i < count($fileIds); $i++) {
            $fileId = (int)$fileIds[$i];
            if ($fileId <= 0) continue;
            $st = $pdo->prepare("SELECT id,file_path FROM cpms_approval_files WHERE id=:fid AND document_id=:did LIMIT 1");
            $st->execute(array(':fid' => $fileId, ':did' => (int)$documentId));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            $filePath = isset($row['file_path']) ? trim((string)$row['file_path']) : '';
            $pdo->prepare("DELETE FROM cpms_approval_files WHERE id=:id AND document_id=:did")->execute(array(':id' => $fileId, ':did' => (int)$documentId));
            if ($filePath !== '') {
                $countSt = $pdo->prepare("SELECT COUNT(*) FROM cpms_approval_files WHERE file_path=:path");
                $countSt->execute(array(':path' => $filePath));
                if ((int)$countSt->fetchColumn() === 0) {
                    $absolute = rtrim($root, '/\\') . '/' . ltrim(str_replace('\\', '/', $filePath), '/');
                    if (is_file($absolute)) @unlink($absolute);
                }
            }
        }
    }
}

if (!function_exists('approval_edit_store_upload_new_files')) {
    function approval_edit_store_upload_new_files($pdo, $documentId, $docType, $user)
    {
        $warnings = array();
        if (!approval_is_proposal_doc_type($docType)) return $warnings;
        $allow = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'xls', 'xlsx');
        $labels = array(
            'order_doc' => array(approval_ko('%EB%B0%9C%EC%A3%BC%EC%84%9C'), 'order_doc_file'),
            'business_license' => array(approval_ko('%EC%82%AC%EC%97%85%EC%9E%90%EB%93%B1%EB%A1%9D%EC%A6%9D'), 'business_license_file'),
            'etc' => array(approval_ko('%EA%B8%B0%ED%83%80'), 'etc_file')
        );
        $root = approval_edit_store_project_root();
        $base = rtrim($root, '/\\') . '/storage/approvals/' . (int)$documentId . '/files';
        if (!is_dir($base)) @mkdir($base, 0777, true);
        $driveReady = cpms_approval_drive_ensure_file_columns($pdo);
        foreach ($labels as $fileType => $meta) {
            $files = approval_edit_store_uploaded_files($meta[1]);
            for ($i = 0; $i < count($files); $i++) {
                $file = $files[$i];
                $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
                $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
                if ($error === UPLOAD_ERR_NO_FILE && $tmp === '') continue;
                if ($error !== UPLOAD_ERR_OK) {
                    $warnings[] = $meta[0] . ' 업로드 오류';
                    continue;
                }
                $original = isset($file['name']) ? (string)$file['name'] : '';
                $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                if (!in_array($ext, $allow, true)) {
                    $warnings[] = $original . ' 허용되지 않은 파일 형식';
                    continue;
                }
                $saved = $fileType . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $ext;
                $dest = $base . '/' . $saved;
                if (!@move_uploaded_file($tmp, $dest)) {
                    $warnings[] = $original . ' 저장 실패';
                    continue;
                }
                $rel = 'storage/approvals/' . (int)$documentId . '/files/' . $saved;
                $mime = cpms_drive_detect_mime_type($dest);
                $size = is_file($dest) ? (int)@filesize($dest) : 0;
                $driveRecord = $driveReady
                    ? cpms_approval_drive_pending_record($original, $rel, $mime, $size, $user)
                    : cpms_approval_drive_failed_record($original, $rel, $mime, $size, $user, 'Drive columns are not ready.');
                $fileRow = array_merge($driveRecord, array(
                    'document_id' => (int)$documentId,
                    'original_name' => $original,
                    'saved_name' => $saved,
                    'file_path' => $rel,
                    'file_label' => $meta[0],
                    'file_type' => $fileType
                ));
                $save = cpms_approval_drive_save_file_row($pdo, $fileRow);
                if (empty($save['ok'])) $warnings[] = $original . ' 파일정보 저장 실패';
            }
        }
        return $warnings;
    }
}

if (!function_exists('approval_edit_store_add_posted_references')) {
    function approval_edit_store_add_posted_references($pdo, $documentId, $creatorId, $lines)
    {
        if (!$pdo || !approval_table_exists($pdo, 'cpms_approval_references')) return;
        $posted = isset($_POST['reference_employee_ids']) && is_array($_POST['reference_employee_ids']) ? $_POST['reference_employee_ids'] : array();
        if (count($posted) === 0) return;

        $lineEmployeeIds = array();
        if (is_array($lines)) {
            for ($i = 0; $i < count($lines); $i++) {
                if (isset($lines[$i]['approver_id']) && (int)$lines[$i]['approver_id'] > 0) {
                    $lineEmployeeIds[] = (int)$lines[$i]['approver_id'];
                }
            }
        }

        $existingIds = array();
        $existingRefs = approval_resubmit_fetch_references($pdo, $documentId);
        for ($i = 0; $i < count($existingRefs); $i++) {
            $rid = isset($existingRefs[$i]['employee_id']) ? (int)$existingRefs[$i]['employee_id'] : 0;
            if ($rid > 0) $existingIds[$rid] = 1;
        }

        for ($i = 0; $i < count($posted); $i++) {
            $rid = (int)$posted[$i];
            if ($rid <= 0 || $rid === (int)$creatorId || in_array($rid, $lineEmployeeIds, true) || isset($existingIds[$rid])) continue;
            $emp = approval_edit_store_employee($pdo, $rid);
            if (!$emp) continue;
            $existingIds[$rid] = 1;
            $pdo->prepare("INSERT INTO cpms_approval_references (document_id,employee_id,employee_name,employee_email,employee_department,created_at) VALUES (:d,:eid,:en,:ee,:ed,NOW())")
                ->execute(array(
                    ':d' => (int)$documentId,
                    ':eid' => $rid,
                    ':en' => isset($emp['name']) ? $emp['name'] : '',
                    ':ee' => isset($emp['email']) ? $emp['email'] : '',
                    ':ed' => isset($emp['department']) ? $emp['department'] : null
                ));
        }
    }
}

if (!function_exists('approval_edit_store_copy_references')) {
    function approval_edit_store_copy_references($pdo, $sourceDocumentId, $targetDocumentId, $creatorId, $lineEmployeeIds)
    {
        if (!$pdo || !approval_table_exists($pdo, 'cpms_approval_references')) return;
        $existingIds = array();
        $sourceRefs = approval_resubmit_fetch_references($pdo, $sourceDocumentId);
        for ($i = 0; $i < count($sourceRefs); $i++) {
            $rid = isset($sourceRefs[$i]['employee_id']) ? (int)$sourceRefs[$i]['employee_id'] : 0;
            if ($rid <= 0 || $rid === (int)$creatorId || in_array($rid, $lineEmployeeIds, true) || isset($existingIds[$rid])) continue;
            $existingIds[$rid] = 1;
            $pdo->prepare("INSERT INTO cpms_approval_references (document_id,employee_id,employee_name,employee_email,employee_department,created_at) VALUES (:d,:eid,:en,:ee,:ed,NOW())")
                ->execute(array(
                    ':d' => (int)$targetDocumentId,
                    ':eid' => $rid,
                    ':en' => isset($sourceRefs[$i]['employee_name']) ? $sourceRefs[$i]['employee_name'] : '',
                    ':ee' => isset($sourceRefs[$i]['employee_email']) ? $sourceRefs[$i]['employee_email'] : '',
                    ':ed' => isset($sourceRefs[$i]['employee_department']) ? $sourceRefs[$i]['employee_department'] : null
                ));
        }
        $posted = isset($_POST['reference_employee_ids']) && is_array($_POST['reference_employee_ids']) ? $_POST['reference_employee_ids'] : array();
        for ($i = 0; $i < count($posted); $i++) {
            $rid = (int)$posted[$i];
            if ($rid <= 0 || $rid === (int)$creatorId || in_array($rid, $lineEmployeeIds, true) || isset($existingIds[$rid])) continue;
            $emp = approval_edit_store_employee($pdo, $rid);
            if (!$emp) continue;
            $existingIds[$rid] = 1;
            $pdo->prepare("INSERT INTO cpms_approval_references (document_id,employee_id,employee_name,employee_email,employee_department,created_at) VALUES (:d,:eid,:en,:ee,:ed,NOW())")
                ->execute(array(':d' => (int)$targetDocumentId, ':eid' => $rid, ':en' => $emp['name'], ':ee' => $emp['email'], ':ed' => isset($emp['department']) ? $emp['department'] : null));
        }
    }
}

$mode = isset($_POST['approval_edit_mode']) ? trim((string)$_POST['approval_edit_mode']) : '';
$sourceDocumentId = isset($_POST['source_document_id']) ? (int)$_POST['source_document_id'] : 0;
if (!in_array($mode, array('edit', 'resubmit'), true) || $sourceDocumentId <= 0) {
    flash_set('danger', '잘못된 전자결재 수정 요청입니다.');
    header('Location: index.php?r=approval_home');
    exit;
}

$creatorIdentity = approval_current_employee_identity($pdo, $user);
$creatorId = approval_current_employee_id($pdo, $user);
$creatorName = isset($creatorIdentity['name']) ? trim((string)$creatorIdentity['name']) : approval_current_user_name($user);
$creatorEmail = isset($creatorIdentity['email']) ? trim((string)$creatorIdentity['email']) : approval_current_user_email($user);
$creator = approval_edit_store_employee($pdo, $creatorId);
if (!is_array($creator)) {
    $creator = array(
        'id' => $creatorId,
        'name' => $creatorName,
        'email' => $creatorEmail,
        'department' => isset($user['department']) ? $user['department'] : '',
        'position' => isset($user['position']) ? $user['position'] : '',
        'role' => isset($user['role']) ? $user['role'] : ''
    );
}

/* 재상신 시 기존 팀장을 기본값으로 사용하고, 화면에서 다시 선택한 값이 있으면 우선합니다. */
$sourceLinesBeforeLock = approval_resubmit_fetch_lines($pdo, $sourceDocumentId, false);
$selectedTeamLeaderId = isset($_POST['construction_team_leader_id']) ? (int)$_POST['construction_team_leader_id'] : 0;
if ($selectedTeamLeaderId <= 0) $selectedTeamLeaderId = approval_resubmit_source_team_leader_id($sourceLinesBeforeLock);
if ($selectedTeamLeaderId > 0) $creator['team_leader_id'] = $selectedTeamLeaderId;

$uploadWarnings = array();
try {
    $pdo->beginTransaction();
    $sourceDoc = approval_resubmit_fetch_document($pdo, $sourceDocumentId, true);
    if (!$sourceDoc) throw new Exception('원본 문서를 찾을 수 없습니다.');
    if (!approval_is_document_owner($pdo, $sourceDoc, $user)) throw new Exception('본인이 작성한 문서만 수정할 수 있습니다.');

    $docType = isset($sourceDoc['doc_type']) ? strtolower(trim((string)$sourceDoc['doc_type'])) : '';
    if (!approval_resubmit_supported_doc_type($docType)) throw new Exception('이 문서 종류는 수정/재상신할 수 없습니다.');
    $postedDocType = isset($_POST['doc_type']) ? strtolower(trim((string)$_POST['doc_type'])) : '';
    if ($postedDocType !== $docType) throw new Exception('문서 종류가 일치하지 않습니다.');

    $sourceContent = approval_parse_content(isset($sourceDoc['content']) ? $sourceDoc['content'] : '');
    $title = '';
    $newLines = array();
    $contentData = approval_edit_store_build_content($pdo, $docType, $sourceContent, $creator, $title, $newLines, $mode === 'resubmit');
    $projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : (isset($sourceDoc['project_id']) ? (int)$sourceDoc['project_id'] : 0);
    if ($projectId > 0) $contentData['project_id'] = $projectId;

    if ($mode === 'edit') {
        $status = strtoupper(trim((string)(isset($sourceDoc['doc_status']) ? $sourceDoc['doc_status'] : '')));
        $lockedLines = approval_resubmit_fetch_lines($pdo, $sourceDocumentId, true);
        if ($status !== 'PENDING' || approval_edit_store_lines_have_decision($lockedLines)) {
            throw new Exception('이미 승인 또는 반려가 진행되어 더 이상 직접 수정할 수 없습니다. 반려 후 재상신해 주세요.');
        }

        $sets = array('title=:title', 'content=:content', 'updated_at=NOW()');
        $params = array(':title' => $title, ':content' => json_encode($contentData), ':id' => $sourceDocumentId);
        if (approval_table_column_exists($pdo, 'cpms_approval_documents', 'project_id')) {
            $sets[] = 'project_id=:project_id';
            $params[':project_id'] = $projectId > 0 ? $projectId : null;
        }
        $pdo->prepare("UPDATE cpms_approval_documents SET " . implode(',', $sets) . " WHERE id=:id")->execute($params);

        if (approval_table_exists($pdo, 'cpms_approval_logs')) {
            $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:a,:n,:e,'EDIT',:note,NOW())")
                ->execute(array(':d' => $sourceDocumentId, ':a' => $creatorId, ':n' => $creatorName, ':e' => $creatorEmail, ':note' => '첫 결재 전 작성자가 문서 내용을 수정했습니다.'));
        }

        $removeIds = isset($_POST['remove_existing_file_ids']) && is_array($_POST['remove_existing_file_ids']) ? $_POST['remove_existing_file_ids'] : array();
        approval_edit_store_remove_files($pdo, $sourceDocumentId, $removeIds);
        $uploadWarnings = approval_edit_store_upload_new_files($pdo, $sourceDocumentId, $docType, $user);
        approval_edit_store_add_posted_references($pdo, $sourceDocumentId, $creatorId, $lockedLines);

        $pdo->commit();
        if (count($uploadWarnings) > 0) flash_set('danger', implode(', ', $uploadWarnings));
        else flash_set('success', '전자결재 내용을 수정했습니다. 첫 결재자 대기 상태는 그대로 유지됩니다.');
        header('Location: index.php?r=approval_detail&id=' . $sourceDocumentId);
        exit;
    }

    $sourceStatus = strtoupper(trim((string)(isset($sourceDoc['doc_status']) ? $sourceDoc['doc_status'] : '')));
    if ($sourceStatus !== 'REJECTED') throw new Exception('반려된 문서만 수정 후 재상신할 수 있습니다.');
    $existingChild = approval_resubmit_find_child($pdo, $sourceDocumentId);
    if ($existingChild && isset($existingChild['id'])) {
        $pdo->rollBack();
        header('Location: index.php?r=approval_detail&id=' . (int)$existingChild['id']);
        exit;
    }

    $meta = approval_resubmit_source_meta($sourceDoc);
    $contentData['resubmit_source_id'] = $sourceDocumentId;
    $contentData['resubmit_root_id'] = $meta['root_id'];
    $contentData['resubmit_revision'] = $meta['next_revision'];
    $contentData['resubmitted_at'] = date('Y-m-d H:i:s');

    approval_edit_store_apply_auto_delegation($pdo, $docType, $newLines, $creator);

    $docColumns = array('doc_type', 'title', 'content', 'doc_status', 'current_step_order', 'created_by_id', 'created_by_name', 'created_at', 'updated_at');
    $docValues = array(':doc_type', ':title', ':content', "'PENDING'", '1', ':uid', ':uname', 'NOW()', 'NOW()');
    $docParams = array(':doc_type' => $docType, ':title' => $title, ':content' => json_encode($contentData), ':uid' => $creatorId, ':uname' => $creatorName);
    if (approval_table_column_exists($pdo, 'cpms_approval_documents', 'created_by_email')) {
        $docColumns[] = 'created_by_email';
        $docValues[] = ':uemail';
        $docParams[':uemail'] = $creatorEmail;
    }
    if (approval_table_column_exists($pdo, 'cpms_approval_documents', 'delegate_level')) {
        $docColumns[] = 'delegate_level';
        $docValues[] = "'none'";
    }
    if (approval_table_column_exists($pdo, 'cpms_approval_documents', 'project_id')) {
        $docColumns[] = 'project_id';
        $docValues[] = ':project_id';
        $docParams[':project_id'] = $projectId > 0 ? $projectId : null;
    }
    $pdo->prepare("INSERT INTO cpms_approval_documents (" . implode(',', $docColumns) . ") VALUES (" . implode(',', $docValues) . ")")->execute($docParams);
    $newDocumentId = (int)$pdo->lastInsertId();

    $prepared = array();
    for ($i = 0; $i < count($newLines); $i++) {
        $line = $newLines[$i];
        if (!isset($line['emp']) || !is_array($line['emp'])) continue;
        $emp = $line['emp'];
        $isDelegated = !empty($line['delegated']) || (isset($line['status']) && strtoupper((string)$line['status']) === 'DELEGATED');
        $allowSelf = !empty($line['allow_self_approval']);
        $isSelf = false;
        if (!$isDelegated && !$allowSelf) {
            if ($creatorId > 0 && isset($emp['id']) && (int)$emp['id'] === $creatorId) $isSelf = true;
            else if ($creatorEmail !== '' && isset($emp['email']) && strtolower(trim((string)$emp['email'])) === strtolower($creatorEmail)) $isSelf = true;
        }
        $status = isset($line['status']) ? strtoupper(trim((string)$line['status'])) : ($isDelegated ? 'DELEGATED' : ($isSelf ? 'SKIPPED' : 'WAITING'));
        $prepared[] = array(
            'order' => count($prepared) + 1,
            'role' => isset($line['role']) ? $line['role'] : '',
            'emp' => $emp,
            'status' => $status,
            'delegated' => $isDelegated ? 1 : 0,
            'delegated_by_role' => isset($line['delegated_by_role']) ? $line['delegated_by_role'] : null,
            'acted_at' => isset($line['acted_at']) ? $line['acted_at'] : null,
            'sign_path' => isset($line['sign_path']) ? $line['sign_path'] : null,
            'auto_reason' => isset($line['auto_reason']) ? $line['auto_reason'] : null
        );
    }

    $firstPendingIndex = -1;
    for ($i = 0; $i < count($prepared); $i++) {
        if ($prepared[$i]['status'] === 'WAITING') {
            $firstPendingIndex = $i;
            $prepared[$i]['status'] = 'PENDING';
            break;
        }
    }

    $lineEmployeeIds = array();
    for ($i = 0; $i < count($prepared); $i++) {
        $emp = $prepared[$i]['emp'];
        $lineEmployeeIds[] = isset($emp['id']) ? (int)$emp['id'] : 0;
        $cols = array('document_id', 'line_order', 'role_type', 'approver_id', 'approver_name', 'approver_email', 'line_status');
        $marks = array(':d', ':o', ':role', ':aid', ':aname', ':aemail', ':status');
        $params = array(
            ':d' => $newDocumentId,
            ':o' => $prepared[$i]['order'],
            ':role' => $prepared[$i]['role'],
            ':aid' => isset($emp['id']) ? (int)$emp['id'] : 0,
            ':aname' => isset($emp['name']) ? $emp['name'] : '',
            ':aemail' => isset($emp['email']) ? $emp['email'] : '',
            ':status' => $prepared[$i]['status']
        );
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'acted_at') && $prepared[$i]['acted_at']) {
            $cols[] = 'acted_at'; $marks[] = ':acted_at'; $params[':acted_at'] = $prepared[$i]['acted_at'];
        }
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'sign_path') && $prepared[$i]['sign_path']) {
            $cols[] = 'sign_path'; $marks[] = ':sign_path'; $params[':sign_path'] = $prepared[$i]['sign_path'];
        }
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
            $cols[] = 'is_delegated'; $marks[] = ':is_delegated'; $params[':is_delegated'] = $prepared[$i]['delegated'];
        }
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role')) {
            $cols[] = 'delegated_by_role'; $marks[] = ':delegated_by_role'; $params[':delegated_by_role'] = $prepared[$i]['delegated_by_role'];
        }
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'reject_reason') && trim((string)$prepared[$i]['auto_reason']) !== '') {
            $cols[] = 'reject_reason'; $marks[] = ':reason'; $params[':reason'] = trim((string)$prepared[$i]['auto_reason']);
        }
        $pdo->prepare("INSERT INTO cpms_approval_lines (" . implode(',', $cols) . ") VALUES (" . implode(',', $marks) . ")")->execute($params);
        $insertedLineId = (int)$pdo->lastInsertId();
        if (approval_table_exists($pdo, 'cpms_approval_logs') && in_array($prepared[$i]['status'], array('SKIPPED', 'DELEGATED', 'APPROVED'), true)) {
            $logLine = array(
                'role_type' => $prepared[$i]['role'],
                'approver_name' => isset($emp['name']) ? $emp['name'] : '',
                'approver_email' => isset($emp['email']) ? $emp['email'] : ''
            );
            $autoNote = trim((string)$prepared[$i]['auto_reason']) !== ''
                ? approval_auto_delegate_note($logLine, $prepared[$i]['auto_reason'])
                : approval_auto_delegate_note($logLine, approval_line_status_label($prepared[$i]['status']));
            $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:l,:a,:n,:e,:type,:note,NOW())")
                ->execute(array(':d' => $newDocumentId, ':l' => $insertedLineId, ':a' => $creatorId, ':n' => $creatorName, ':e' => $creatorEmail, ':type' => $prepared[$i]['status'], ':note' => $autoNote));
        }
    }

    $currentStep = 1;
    if ($firstPendingIndex >= 0) {
        $currentStep = (int)$prepared[$firstPendingIndex]['order'];
        $pdo->prepare("UPDATE cpms_approval_documents SET current_step_order=:step WHERE id=:id")->execute(array(':step' => $currentStep, ':id' => $newDocumentId));
    } else {
        $pdo->prepare("UPDATE cpms_approval_documents SET doc_status='APPROVED', updated_at=NOW() WHERE id=:id")->execute(array(':id' => $newDocumentId));
    }

    if (approval_table_exists($pdo, 'cpms_approval_logs')) {
        $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:a,:n,:e,'RESUBMIT',:note,NOW())")
            ->execute(array(
                ':d' => $newDocumentId,
                ':a' => $creatorId,
                ':n' => $creatorName,
                ':e' => $creatorEmail,
                ':note' => '반려 문서 #' . $sourceDocumentId . '에서 수정 후 ' . $meta['next_revision'] . '차 재상신'
            ));
    }

    approval_edit_store_copy_references($pdo, $sourceDocumentId, $newDocumentId, $creatorId, $lineEmployeeIds);

    $copyFileIds = isset($_POST['copy_source_file_ids']) && is_array($_POST['copy_source_file_ids']) ? $_POST['copy_source_file_ids'] : array();
    $carryFileFailed = false;
    for ($i = 0; $i < count($copyFileIds); $i++) {
        if (!approval_edit_store_clone_file_row($pdo, $sourceDocumentId, (int)$copyFileIds[$i], $newDocumentId)) {
            $carryFileFailed = true;
        }
    }
    $uploadWarnings = approval_edit_store_upload_new_files($pdo, $newDocumentId, $docType, $user);
    if ($carryFileFailed) {
        $uploadWarnings[] = '기존 첨부파일 일부를 재상신 문서에 포함하지 못했습니다. 원본 반려문서의 첨부파일은 그대로 보존되어 있습니다.';
    }

    if ($firstPendingIndex >= 0) {
        try {
            $firstEmp = $prepared[$firstPendingIndex]['emp'];
            $msg = approval_build_request_message($docType, $title, $creatorName);
            approval_queue_notification($pdo, $newDocumentId, 'REQUEST', isset($firstEmp['id']) ? (int)$firstEmp['id'] : 0, $msg);
        } catch (Exception $notifyException) {
        }
    }

    $pdo->commit();
    if (count($uploadWarnings) > 0) flash_set('danger', implode(', ', $uploadWarnings));
    else flash_set('success', '반려문서를 수정하여 새 전자결재로 재상신했습니다. 결재선은 처음부터 다시 시작합니다.');
    header('Location: index.php?r=approval_detail&id=' . $newDocumentId);
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[approval_edit_store] ' . $e->getMessage());
    flash_set('danger', $e->getMessage());
    $backParam = $mode === 'resubmit' ? 'resubmit_id' : 'edit_id';
    header('Location: index.php?r=approval_create&type=' . rawurlencode(isset($_POST['doc_type']) ? $_POST['doc_type'] : 'proposal') . '&' . $backParam . '=' . $sourceDocumentId);
    exit;
}
