<?php
/*
 * 파일경로: app/views/approval/resubmit_helpers.php
 * 기능: 전자결재 수정 / 반려 후 재상신 공통 함수
 * PHP 5.6 호환
 */

if (!function_exists('approval_resubmit_supported_doc_type')) {
    function approval_resubmit_supported_doc_type($docType)
    {
        $docType = strtolower(trim((string)$docType));
        return in_array($docType, array('proposal', 'small_proposal', 'leave'), true);
    }
}

if (!function_exists('approval_resubmit_fetch_document')) {
    function approval_resubmit_fetch_document($pdo, $documentId, $forUpdate)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_documents')) {
            return null;
        }
        try {
            $sql = "SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1";
            if ($forUpdate) {
                $sql .= " FOR UPDATE";
            }
            $st = $pdo->prepare($sql);
            $st->execute(array(':id' => (int)$documentId));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('approval_resubmit_fetch_lines')) {
    function approval_resubmit_fetch_lines($pdo, $documentId, $forUpdate)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_lines')) {
            return array();
        }
        try {
            $sql = "SELECT * FROM cpms_approval_lines WHERE document_id=:id ORDER BY line_order ASC, id ASC";
            if ($forUpdate) {
                $sql .= " FOR UPDATE";
            }
            $st = $pdo->prepare($sql);
            $st->execute(array(':id' => (int)$documentId));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }
}

if (!function_exists('approval_resubmit_has_human_decision')) {
    function approval_resubmit_has_human_decision($pdo, $documentId)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_lines')) {
            return true;
        }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM cpms_approval_lines WHERE document_id=:id AND UPPER(COALESCE(line_status,'')) IN ('APPROVED','REJECTED')");
            $st->execute(array(':id' => (int)$documentId));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return true;
        }
    }
}

if (!function_exists('approval_resubmit_can_edit_before_first_decision')) {
    function approval_resubmit_can_edit_before_first_decision($pdo, $docRow, $user)
    {
        if (!is_array($docRow) || !isset($docRow['id'])) {
            return false;
        }
        if (!approval_resubmit_supported_doc_type(isset($docRow['doc_type']) ? $docRow['doc_type'] : '')) {
            return false;
        }
        $status = strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : '')));
        if ($status !== 'PENDING') {
            return false;
        }
        if (!approval_is_document_owner($pdo, $docRow, $user)) {
            return false;
        }
        return !approval_resubmit_has_human_decision($pdo, (int)$docRow['id']);
    }
}

if (!function_exists('approval_resubmit_can_resubmit')) {
    function approval_resubmit_can_resubmit($pdo, $docRow, $user)
    {
        if (!is_array($docRow) || !isset($docRow['id'])) {
            return false;
        }
        if (!approval_resubmit_supported_doc_type(isset($docRow['doc_type']) ? $docRow['doc_type'] : '')) {
            return false;
        }
        $status = strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : '')));
        if ($status !== 'REJECTED') {
            return false;
        }
        return approval_is_document_owner($pdo, $docRow, $user);
    }
}

if (!function_exists('approval_resubmit_fetch_files')) {
    function approval_resubmit_fetch_files($pdo, $documentId)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_files')) {
            return array();
        }
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_approval_files WHERE document_id=:id ORDER BY id ASC");
            $st->execute(array(':id' => (int)$documentId));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }
}

if (!function_exists('approval_resubmit_fetch_references')) {
    function approval_resubmit_fetch_references($pdo, $documentId)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_references')) {
            return array();
        }
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_approval_references WHERE document_id=:id ORDER BY id ASC");
            $st->execute(array(':id' => (int)$documentId));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }
}

if (!function_exists('approval_resubmit_find_child')) {
    function approval_resubmit_find_child($pdo, $sourceDocumentId)
    {
        if (!$pdo || (int)$sourceDocumentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_documents')) {
            return null;
        }
        try {
            /* 숫자 경계를 같이 확인해 12가 123 문서에 잘못 매칭되지 않게 합니다. */
            $sourceId = (int)$sourceDocumentId;
            $st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE content LIKE :needle_comma OR content LIKE :needle_end ORDER BY id DESC LIMIT 1");
            $st->execute(array(
                ':needle_comma' => '%"resubmit_source_id":' . $sourceId . ',%',
                ':needle_end' => '%"resubmit_source_id":' . $sourceId . '}%'
            ));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $content = approval_parse_content(isset($row['content']) ? $row['content'] : '');
            return (isset($content['resubmit_source_id']) && (int)$content['resubmit_source_id'] === $sourceId) ? $row : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('approval_resubmit_source_meta')) {
    function approval_resubmit_source_meta($sourceDoc)
    {
        $sourceId = is_array($sourceDoc) && isset($sourceDoc['id']) ? (int)$sourceDoc['id'] : 0;
        $content = is_array($sourceDoc) ? approval_parse_content(isset($sourceDoc['content']) ? $sourceDoc['content'] : '') : array();
        $rootId = isset($content['resubmit_root_id']) ? (int)$content['resubmit_root_id'] : 0;
        if ($rootId <= 0) {
            $rootId = $sourceId;
        }
        $revision = isset($content['resubmit_revision']) ? (int)$content['resubmit_revision'] : 1;
        if ($revision <= 0) {
            $revision = 1;
        }
        return array(
            'source_id' => $sourceId,
            'root_id' => $rootId,
            'source_revision' => $revision,
            'next_revision' => $revision + 1
        );
    }
}

if (!function_exists('approval_resubmit_source_team_leader_id')) {
    function approval_resubmit_source_team_leader_id($lines)
    {
        $teamRole = approval_normalize_compare_text(approval_ko('%ED%8C%80%EC%9E%A5'));
        if (!is_array($lines)) {
            return 0;
        }
        for ($i = 0; $i < count($lines); $i++) {
            $role = isset($lines[$i]['role_type']) ? $lines[$i]['role_type'] : (isset($lines[$i]['role']) ? $lines[$i]['role'] : '');
            if (approval_normalize_compare_text(approval_role_label($role)) === $teamRole) {
                return isset($lines[$i]['approver_id']) ? (int)$lines[$i]['approver_id'] : 0;
            }
        }
        return 0;
    }
}
