<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';

if (!function_exists('approval_index_tab_class')) {
    function approval_index_tab_class($currentView, $targetView)
    {
        if ($currentView === $targetView) {
            return 'bg-white text-indigo-700 font-extrabold shadow';
        }
        return 'bg-white/15 text-indigo-50 hover:bg-white/25';
    }
}

if (!function_exists('approval_index_mask_email')) {
    function approval_index_mask_email($email)
    {
        $email = trim((string)$email);
        if ($email === '') {
            return urldecode('%EC%97%86%EC%9D%8C');
        }
        $parts = explode('@', $email, 2);
        $local = isset($parts[0]) ? $parts[0] : '';
        $domain = isset($parts[1]) ? $parts[1] : '';
        if ($local === '') {
            return urldecode('%EC%97%86%EC%9D%8C');
        }
        if (strlen($local) <= 2) {
            return substr($local, 0, 1) . '*@' . $domain;
        }
        return substr($local, 0, 2) . '***@' . $domain;
    }
}

if (!function_exists('approval_index_card_class')) {
    function approval_index_card_class($index)
    {
        $classes = array(
            'from-indigo-500 to-cyan-500',
            'from-rose-500 to-orange-500',
            'from-emerald-500 to-teal-500',
            'from-slate-600 to-slate-500'
        );
        return isset($classes[$index]) ? $classes[$index] : 'from-indigo-500 to-cyan-500';
    }
}

if (!function_exists('approval_index_created_desc_compare')) {
    function approval_index_created_desc_compare($left, $right)
    {
        $leftCreated = is_array($left) && isset($left['created_at']) ? (string)$left['created_at'] : '';
        $rightCreated = is_array($right) && isset($right['created_at']) ? (string)$right['created_at'] : '';
        if ($leftCreated !== $rightCreated) {
            return strcmp($rightCreated, $leftCreated);
        }
        $leftId = is_array($left) && isset($left['id']) ? (int)$left['id'] : 0;
        $rightId = is_array($right) && isset($right['id']) ? (int)$right['id'] : 0;
        if ($leftId === $rightId) {
            return 0;
        }
        return ($leftId < $rightId) ? 1 : -1;
    }
}

if (!function_exists('approval_index_leave_applicant_key')) {
    function approval_index_leave_applicant_key($row)
    {
        if (!is_array($row) || !isset($row['doc_type']) || strtolower(trim((string)$row['doc_type'])) !== 'leave') {
            return '';
        }
        $content = approval_parse_content(isset($row['content']) ? $row['content'] : '');
        $applicantName = isset($content['applicant_name']) ? trim((string)$content['applicant_name']) : '';
        if ($applicantName === '' && isset($row['title'])) {
            $title = trim((string)$row['title']);
            $separatorPosition = strrpos($title, ' - ');
            if ($separatorPosition !== false) {
                $applicantName = trim(substr($title, $separatorPosition + 3));
            }
        }
        if ($applicantName === '' && isset($row['created_by_name'])) {
            $applicantName = trim((string)$row['created_by_name']);
        }
        $nameKey = approval_employee_person_name_base($applicantName);
        if ($nameKey === '') {
            return '';
        }
        $birthDate = isset($content['birth_date']) ? trim((string)$content['birth_date']) : '';
        $department = isset($content['department']) ? approval_normalize_compare_text($content['department']) : '';
        return $nameKey . '|' . $birthDate . '|' . $department;
    }
}

if (!function_exists('approval_index_matches_completed_filters')) {
    function approval_index_matches_completed_filters($row, $docTypeFilter, $titleFilter, $authorFilter, $dateFromFilter, $dateToFilter, $queryFilter)
    {
        if (!is_array($row)) {
            return false;
        }
        $docType = isset($row['doc_type']) ? (string)$row['doc_type'] : '';
        $title = isset($row['title']) ? (string)$row['title'] : '';
        $author = isset($row['created_by_name']) ? (string)$row['created_by_name'] : '';
        $createdDate = isset($row['created_at']) ? substr((string)$row['created_at'], 0, 10) : '';
        if ($docTypeFilter !== '' && $docType !== $docTypeFilter) return false;
        if ($titleFilter !== '' && stripos($title, $titleFilter) === false) return false;
        if ($authorFilter !== '' && stripos($author, $authorFilter) === false) return false;
        if ($dateFromFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromFilter) && $createdDate < $dateFromFilter) return false;
        if ($dateToFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToFilter) && $createdDate > $dateToFilter) return false;
        if ($queryFilter !== '' && stripos($title, $queryFilter) === false && stripos($author, $queryFilter) === false && stripos($docType, $queryFilter) === false) return false;
        return true;
    }
}

$pdo = Db::pdo();
$u = \App\Core\Auth::user();
$currentEmployeeIdentity = approval_current_employee_identity($pdo, $u);
$uid = isset($currentEmployeeIdentity['id']) ? (int)$currentEmployeeIdentity['id'] : 0;
$userEmail = isset($currentEmployeeIdentity['email']) ? trim((string)$currentEmployeeIdentity['email']) : '';
$userName = isset($currentEmployeeIdentity['name']) ? trim((string)$currentEmployeeIdentity['name']) : '';
$userEmails = isset($currentEmployeeIdentity['emails']) && is_array($currentEmployeeIdentity['emails']) ? $currentEmployeeIdentity['emails'] : array($userEmail);
$userNames = isset($currentEmployeeIdentity['names']) && is_array($currentEmployeeIdentity['names']) ? $currentEmployeeIdentity['names'] : array($userName);
$isAdmin = approval_is_admin_user($u);
$debugApproval = isset($_GET['debug_approval']) && (string)$_GET['debug_approval'] === '1';

$txt = array(
    'page_active' => urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%A7%84%ED%96%89%EB%AC%B8%EC%84%9C'),
    'page_cancelled' => urldecode('%EC%B7%A8%EC%86%8C%EB%AC%B8%EC%84%9C'),
    'page_completed' => urldecode('%EC%99%84%EB%A3%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C'),
    'desc_active' => '',
    'desc_cancelled' => urldecode('%EC%B7%A8%EC%86%8C%EB%90%9C%20%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EB%AC%B8%EC%84%9C%EB%A7%8C%20%EB%AA%A8%EC%95%84%20%ED%99%95%EC%9D%B8%ED%95%98%EA%B3%A0%20%ED%95%84%EC%9A%94%20%EC%8B%9C%20%EC%82%AD%EC%A0%9C%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'),
    'desc_completed' => urldecode('%EC%99%84%EB%A3%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EC%A2%85%EB%A5%98%2F%EC%A0%9C%EB%AA%A9%2F%EC%9E%91%EC%84%B1%EC%9E%90%2F%EC%9E%91%EC%84%B1%EC%9D%BC%EC%9E%90%20%EA%B8%B0%EC%A4%80%EC%9C%BC%EB%A1%9C%20%EA%B2%80%EC%83%89%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'),
    'db_setup' => urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20DB%20%EC%84%A4%EC%B9%98%2F%ED%99%95%EC%9D%B8'),
    'db_desc' => urldecode('%EB%AC%B8%EC%84%9C%2C%20%EA%B2%B0%EC%9E%AC%EC%84%A0%2C%20%EC%B2%A8%EB%B6%80%2C%20%EC%95%8C%EB%A6%BC%20%ED%85%8C%EC%9D%B4%EB%B8%94%EC%9D%84%20%EC%A0%90%EA%B2%80%ED%95%A9%EB%8B%88%EB%8B%A4.'),
    'create_proposal' => urldecode('%EA%B8%B0%EC%95%88%EC%84%9C%20%EC%9E%91%EC%84%B1'),
    'create_small_proposal' => urldecode('%EC%86%8C%EC%95%A1%EA%B8%B0%EC%95%88%EC%84%9C%20%EC%9E%91%EC%84%B1'),
    'create_leave' => urldecode('%ED%9C%B4%EA%B0%80%EA%B3%84%20%EC%9E%91%EC%84%B1'),
    'create_unused_leave_notice' => urldecode('%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EC%B4%89%EA%B5%AC%EC%84%9C'),
    'create_unused_leave_plan' => urldecode('%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B3%84%ED%9A%8D%EC%84%9C'),
    'view_active' => urldecode('%EC%A7%84%ED%96%89%EB%AC%B8%EC%84%9C%20%EB%B3%B4%EA%B8%B0'),
    'view_cancelled' => urldecode('%EC%B7%A8%EC%86%8C%EB%AC%B8%EC%84%9C%20%EB%B3%B4%EA%B8%B0'),
    'view_completed' => urldecode('%EC%99%84%EB%A3%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C%20%EB%B3%B4%EA%B8%B0'),
    'filter_doc_type' => urldecode('%EB%AC%B8%EC%84%9C%20%EC%A2%85%EB%A5%98'),
    'filter_all' => urldecode('%EC%A0%84%EC%B2%B4'),
    'filter_title' => urldecode('%EC%A0%9C%EB%AA%A9%20%EA%B2%80%EC%83%89'),
    'filter_author' => urldecode('%EC%9E%91%EC%84%B1%EC%9E%90%20%EA%B2%80%EC%83%89'),
    'filter_date_from' => urldecode('%EC%9E%91%EC%84%B1%EC%9D%BC%EC%9E%90%20%EC%8B%9C%EC%9E%91'),
    'filter_date_to' => urldecode('%EC%9E%91%EC%84%B1%EC%9D%BC%EC%9E%90%20%EC%A2%85%EB%A3%8C'),
    'filter_q' => urldecode('%ED%86%B5%ED%95%A9%20%EA%B2%80%EC%83%89%EC%96%B4'),
    'search' => urldecode('%EA%B2%80%EC%83%89'),
    'reset' => urldecode('%EC%B4%88%EA%B8%B0%ED%99%94'),
    'card_received' => urldecode('%EB%B0%9B%EC%9D%80%20%EA%B2%B0%EC%9E%AC'),
    'card_requested' => urldecode('%EB%82%98%EC%9D%98%20%EC%9A%94%EC%B2%AD'),
    'card_progress' => urldecode('%EC%A7%84%ED%96%89%EC%A4%91'),
    'card_rejected' => urldecode('%EB%B0%98%EB%A0%A4'),
    'card_cancelled' => urldecode('%EC%B7%A8%EC%86%8C%EB%AC%B8%EC%84%9C'),
    'card_my_cancelled' => urldecode('%EB%82%B4%EA%B0%80%20%EC%B7%A8%EC%86%8C%ED%95%9C%20%EB%AC%B8%EC%84%9C'),
    'card_completed' => urldecode('%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C'),
    'card_my_completed' => urldecode('%EB%82%B4%EA%B0%80%20%EC%9E%91%EC%84%B1%ED%95%9C%20%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C'),
    'card_my_approved' => urldecode('%EB%82%B4%EA%B0%80%20%EA%B2%B0%EC%9E%AC%ED%95%9C%20%EC%99%84%EB%A3%8C%EB%AC%B8%EC%84%9C'),
    'error_load' => urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EB%AA%A9%EB%A1%9D%EC%9D%84%20%EB%B6%88%EB%9F%AC%EC%98%A4%EB%8A%94%20%EC%A4%91%20%EC%98%A4%EB%A5%98%EA%B0%80%20%EB%B0%9C%EC%83%9D%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EA%B4%80%EB%A6%AC%EC%9E%90%EC%97%90%EA%B2%8C%20%EB%AC%B8%EC%9D%98%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'),
    'table_type' => urldecode('%EC%A2%85%EB%A5%98'),
    'table_title' => urldecode('%EC%A0%9C%EB%AA%A9'),
    'table_author' => urldecode('%EC%9E%91%EC%84%B1%EC%9E%90'),
    'table_created_at' => urldecode('%EC%9E%91%EC%84%B1%EC%9D%BC%EC%8B%9C'),
    'table_completed_at' => urldecode('%EC%99%84%EB%A3%8C%EC%9D%BC%EC%8B%9C'),
    'table_current_step' => urldecode('%ED%98%84%EC%9E%AC%20%EA%B2%B0%EC%9E%AC'),
    'table_my_step' => urldecode('%EB%82%B4%20%EA%B2%B0%EC%9E%AC%EC%84%A0'),
    'table_status' => urldecode('%EC%83%81%ED%83%9C'),
    'table_actions' => urldecode('%EC%95%A1%EC%85%98'),
    'detail' => urldecode('%EC%83%81%EC%84%B8%EB%B3%B4%EA%B8%B0'),
    'cancel' => urldecode('%EC%9A%94%EC%B2%AD%EC%B7%A8%EC%86%8C'),
    'delete' => urldecode('%EC%82%AD%EC%A0%9C'),
    'confirm_cancel' => urldecode('%EC%9D%B4%20%EB%AC%B8%EC%84%9C%20%EC%9A%94%EC%B2%AD%EC%9D%84%20%EC%B7%A8%EC%86%8C%ED%95%A0%EA%B9%8C%EC%9A%94%3F'),
    'confirm_delete' => urldecode('%EC%9D%B4%20%EC%B7%A8%EC%86%8C%20%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EC%82%AD%EC%A0%9C%ED%95%A0%EA%B9%8C%EC%9A%94%3F'),
    'empty_active' => urldecode('%EC%A7%84%ED%96%89%EC%A4%91%EC%9D%B8%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'),
    'empty_cancelled' => urldecode('%EC%B7%A8%EC%86%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'),
    'empty_completed' => urldecode('%EC%99%84%EB%A3%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')
);

$view = isset($_GET['view']) ? trim((string)$_GET['view']) : 'active';
if (isset($_GET['show_cancelled']) && (string)$_GET['show_cancelled'] === '1') {
    $view = 'cancelled';
}
if (!in_array($view, array('active', 'cancelled', 'completed'), true)) {
    $view = 'active';
}
$isCompletedAllViewer = ($view === 'completed' && approval_can_view_all_completed_documents($pdo, $u));
$isActiveAllViewer = ($view === 'active' && approval_can_view_all_active_documents($pdo, $u));

$docTypeFilter = isset($_GET['doc_type']) ? trim((string)$_GET['doc_type']) : '';
$titleFilter = isset($_GET['title']) ? trim((string)$_GET['title']) : '';
$authorFilter = isset($_GET['author']) ? trim((string)$_GET['author']) : '';
$dateFromFilter = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateToFilter = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$queryFilter = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$pageTitle = $txt['page_active'];
$pageDesc = $txt['desc_active'];
$emptyMessage = $txt['empty_active'];
if ($view === 'cancelled') {
    $pageTitle = $txt['page_cancelled'];
    $pageDesc = $txt['desc_cancelled'];
    $emptyMessage = $txt['empty_cancelled'];
} else if ($view === 'completed') {
    $pageTitle = $txt['page_completed'];
    $pageDesc = $txt['desc_completed'];
    $emptyMessage = $txt['empty_completed'];
}

$rows = array();
$countCards = array();
$fatalMessage = '';
$debugInfo = array(
    'view' => $view,
    'uid' => $uid,
    'user_email' => approval_index_mask_email($userEmail),
    'user_name' => $userName,
    'is_admin' => $isAdmin ? 'Y' : 'N',
    'params_keys' => array(),
    'sql_status' => 'not_run',
    'sql_error' => '',
    'owner_fallback_count' => 0,
    'owner_cluster_count' => 0
);

$docHasCreatedByEmail = false;
$referenceTableExists = false;
if ($pdo) {
    $docHasCreatedByEmail = approval_table_column_exists($pdo, 'cpms_approval_documents', 'created_by_email');
    $referenceTableExists = approval_table_exists($pdo, 'cpms_approval_references');
}

if ($pdo) {
    $params = array();
    $where = array();

    if ($view === 'cancelled') {
        $where[] = "UPPER(COALESCE(d.doc_status, '')) = 'CANCELLED'";
    } else if ($view === 'completed') {
        $where[] = "UPPER(COALESCE(d.doc_status, '')) IN ('APPROVED', 'COMPLETED')";
    } else {
        $where[] = "UPPER(COALESCE(d.doc_status, '')) NOT IN ('CANCELLED', 'APPROVED', 'COMPLETED')";
    }

    $ownerParts = array();
    $lineParts = array();
    $refParts = array();

    if ($uid > 0) {
        $ownerParts[] = "d.created_by_id = :owner_uid";
        $lineParts[] = "x.approver_id = :line_uid";
        $refParts[] = "r.employee_id = :ref_uid";
        $params[':owner_uid'] = $uid;
        $params[':line_uid'] = $uid;
        $params[':ref_uid'] = $uid;
    }
    for ($userNameIndex = 0; $userNameIndex < count($userNames); $userNameIndex++) {
        $identityName = trim((string)$userNames[$userNameIndex]);
        if ($identityName === '') {
            continue;
        }
        $ownerNameParam = ':owner_name_' . $userNameIndex;
        $ownerNormalizedNameParam = ':owner_name_normalized_' . $userNameIndex;
        $lineNameParam = ':line_name_' . $userNameIndex;
        $refNameParam = ':ref_name_' . $userNameIndex;
        $ownerParts[] = "d.created_by_name = " . $ownerNameParam;
        $ownerParts[] = approval_sql_normalize_compare_text("COALESCE(d.created_by_name, '')") . " = " . $ownerNormalizedNameParam;
        $lineParts[] = "x.approver_name = " . $lineNameParam;
        $refParts[] = "r.employee_name = " . $refNameParam;
        $params[$ownerNameParam] = $identityName;
        $params[$ownerNormalizedNameParam] = approval_normalize_compare_text($identityName);
        $params[$lineNameParam] = $identityName;
        $params[$refNameParam] = $identityName;

        $encodedUserName = json_encode($identityName);
        if ($encodedUserName !== false && $encodedUserName !== null) {
            $contentNameFields = array('writer_name', 'drafter_name', 'applicant_name', 'sender_name', 'creator_name', 'created_by_name');
            for ($contentNameIndex = 0; $contentNameIndex < count($contentNameFields); $contentNameIndex++) {
                $contentNameField = $contentNameFields[$contentNameIndex];
                $contentNameParam = ':owner_content_name_' . $userNameIndex . '_' . $contentNameIndex;
                $ownerParts[] = "LOCATE(" . $contentNameParam . ", COALESCE(d.content, '')) > 0";
                $params[$contentNameParam] = '"' . $contentNameField . '":' . $encodedUserName;
            }
        }
    }
    for ($userEmailIndex = 0; $userEmailIndex < count($userEmails); $userEmailIndex++) {
        $identityEmail = strtolower(trim((string)$userEmails[$userEmailIndex]));
        if ($identityEmail === '') {
            continue;
        }
        $lineEmailParam = ':line_email_' . $userEmailIndex;
        $refEmailParam = ':ref_email_' . $userEmailIndex;
        $lineParts[] = "LOWER(TRIM(x.approver_email)) = " . $lineEmailParam;
        $refParts[] = "LOWER(TRIM(r.employee_email)) = " . $refEmailParam;
        $params[$lineEmailParam] = $identityEmail;
        $params[$refEmailParam] = $identityEmail;
        if ($docHasCreatedByEmail) {
            $ownerEmailParam = ':owner_email_' . $userEmailIndex;
            $ownerParts[] = "LOWER(TRIM(d.created_by_email)) = " . $ownerEmailParam;
            $params[$ownerEmailParam] = $identityEmail;
        }

        $contentEmailFields = array('writer_email', 'applicant_email', 'sender_email', 'creator_email', 'created_by_email');
        for ($contentEmailIndex = 0; $contentEmailIndex < count($contentEmailFields); $contentEmailIndex++) {
            $contentEmailField = $contentEmailFields[$contentEmailIndex];
            $contentEmailParam = ':owner_content_email_' . $userEmailIndex . '_' . $contentEmailIndex;
            $ownerParts[] = "LOCATE(" . $contentEmailParam . ", LOWER(COALESCE(d.content, ''))) > 0";
            $params[$contentEmailParam] = '"' . $contentEmailField . '":"' . $identityEmail . '"';
        }
    }

    $relatedParts = array();
    if ($isCompletedAllViewer) {
        $relatedParts[] = '1 = 1';
    }
    if ($isActiveAllViewer) {
        $relatedParts[] = "UPPER(COALESCE(d.doc_status, '')) IN ('PENDING', 'DRAFT')";
    }
    if (count($ownerParts) > 0) {
        $relatedParts[] = '(' . implode(' OR ', $ownerParts) . ')';
    }
    if (count($lineParts) > 0) {
        $relatedParts[] = "EXISTS (SELECT 1 FROM cpms_approval_lines x WHERE x.document_id = d.id AND (" . implode(' OR ', $lineParts) . "))";
    }
    if ($referenceTableExists && count($refParts) > 0) {
        $relatedParts[] = "EXISTS (SELECT 1 FROM cpms_approval_references r WHERE r.document_id = d.id AND (" . implode(' OR ', $refParts) . "))";
    }
    if (count($relatedParts) > 0) {
        $where[] = '(' . implode(' OR ', $relatedParts) . ')';
    } else {
        $where[] = '1 = 0';
    }

    if ($view === 'completed') {
        if ($docTypeFilter !== '') {
            $where[] = "d.doc_type = :doc_type";
            $params[':doc_type'] = $docTypeFilter;
        }
        if ($titleFilter !== '') {
            $where[] = "d.title LIKE :title";
            $params[':title'] = '%' . $titleFilter . '%';
        }
        if ($authorFilter !== '') {
            $where[] = "d.created_by_name LIKE :author";
            $params[':author'] = '%' . $authorFilter . '%';
        }
        if ($dateFromFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromFilter)) {
            $where[] = "DATE(d.created_at) >= :date_from";
            $params[':date_from'] = $dateFromFilter;
        }
        if ($dateToFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToFilter)) {
            $where[] = "DATE(d.created_at) <= :date_to";
            $params[':date_to'] = $dateToFilter;
        }
        if ($queryFilter !== '') {
            $where[] = "(d.title LIKE :q OR d.created_by_name LIKE :q OR d.doc_type LIKE :q)";
            $params[':q'] = '%' . $queryFilter . '%';
        }
    }

    $myLineSelect = "NULL";
    $myLineWhere = array();
    if ($uid > 0) {
        $myLineWhere[] = "my.approver_id = :my_uid";
        $params[':my_uid'] = $uid;
    }
    for ($myEmailIndex = 0; $myEmailIndex < count($userEmails); $myEmailIndex++) {
        $myEmail = strtolower(trim((string)$userEmails[$myEmailIndex]));
        if ($myEmail === '') continue;
        $myEmailParam = ':my_email_' . $myEmailIndex;
        $myLineWhere[] = "LOWER(TRIM(my.approver_email)) = " . $myEmailParam;
        $params[$myEmailParam] = $myEmail;
    }
    for ($myNameIndex = 0; $myNameIndex < count($userNames); $myNameIndex++) {
        $myName = trim((string)$userNames[$myNameIndex]);
        if ($myName === '') continue;
        $myNameParam = ':my_name_' . $myNameIndex;
        $myLineWhere[] = "my.approver_name = " . $myNameParam;
        $params[$myNameParam] = $myName;
    }
    if (count($myLineWhere) > 0) {
        $myLineSelect = "(SELECT my.line_status
                            FROM cpms_approval_lines my
                           WHERE my.document_id = d.id
                             AND (" . implode(' OR ', $myLineWhere) . ")
                           ORDER BY my.line_order ASC
                           LIMIT 1)";
    }

    $sql = "SELECT d.*,
                   " . $myLineSelect . " AS my_line_status,
                   (SELECT cur.role_type
                      FROM cpms_approval_lines cur
                     WHERE cur.document_id = d.id
                       AND cur.line_status = 'PENDING'
                     ORDER BY cur.line_order ASC
                     LIMIT 1) AS current_role
              FROM cpms_approval_documents d";
    if (count($where) > 0) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    if ($view === 'completed') {
        $sql .= " ORDER BY COALESCE(d.updated_at, d.created_at) DESC, d.id DESC";
    } else {
        $sql .= " ORDER BY d.created_at DESC, d.id DESC";
    }

    $debugInfo['params_keys'] = array_keys($params);

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = array();
        }
        $debugInfo['sql_status'] = 'success';
    } catch (Exception $e) {
        error_log('[approval_index] SQL error: ' . $e->getMessage());
        $fatalMessage = $txt['error_load'];
        $rows = array();
        $debugInfo['sql_status'] = 'failed';
        $debugInfo['sql_error'] = substr($e->getMessage(), 0, 300);
    }
}

$ownerFallbackNeeded = (($view === 'active' && !$isActiveAllViewer) || ($view === 'completed' && !$isCompletedAllViewer) || $view === 'cancelled');
if ($pdo && $ownerFallbackNeeded && $debugInfo['sql_status'] === 'success') {
    try {
        if ($view === 'cancelled') {
            $fallbackStatusWhere = "UPPER(COALESCE(d.doc_status, '')) = 'CANCELLED'";
        } else if ($view === 'completed') {
            $fallbackStatusWhere = "UPPER(COALESCE(d.doc_status, '')) IN ('APPROVED', 'COMPLETED')";
        } else {
            $fallbackStatusWhere = "UPPER(COALESCE(d.doc_status, '')) NOT IN ('CANCELLED', 'APPROVED', 'COMPLETED')";
        }
        $fallbackSql = "SELECT d.*,
                               NULL AS my_line_status,
                               (SELECT cur.role_type
                                  FROM cpms_approval_lines cur
                                 WHERE cur.document_id = d.id
                                   AND cur.line_status = 'PENDING'
                                 ORDER BY cur.line_order ASC
                                 LIMIT 1) AS current_role
                          FROM cpms_approval_documents d
                         WHERE " . $fallbackStatusWhere . "
                         ORDER BY d.created_at DESC, d.id DESC";
        $fallbackSt = $pdo->query($fallbackSql);
        $fallbackRows = $fallbackSt ? $fallbackSt->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($fallbackRows)) {
            $fallbackRows = array();
        }
        if ($view === 'completed') {
            $filteredFallbackRows = array();
            for ($filterFallbackIndex = 0; $filterFallbackIndex < count($fallbackRows); $filterFallbackIndex++) {
                if (approval_index_matches_completed_filters($fallbackRows[$filterFallbackIndex], $docTypeFilter, $titleFilter, $authorFilter, $dateFromFilter, $dateToFilter, $queryFilter)) {
                    $filteredFallbackRows[] = $fallbackRows[$filterFallbackIndex];
                }
            }
            $fallbackRows = $filteredFallbackRows;
        }

        $visibleDocumentIds = array();
        for ($visibleIndex = 0; $visibleIndex < count($rows); $visibleIndex++) {
            if (isset($rows[$visibleIndex]['id'])) {
                $visibleDocumentIds[(int)$rows[$visibleIndex]['id']] = 1;
            }
        }

        $ownedLeaveApplicantKeys = array();
        for ($ownedVisibleIndex = 0; $ownedVisibleIndex < count($rows); $ownedVisibleIndex++) {
            if (!approval_is_document_owner($pdo, $rows[$ownedVisibleIndex], $u)) {
                continue;
            }
            $ownedVisibleKey = approval_index_leave_applicant_key($rows[$ownedVisibleIndex]);
            if ($ownedVisibleKey !== '') {
                $ownedLeaveApplicantKeys[$ownedVisibleKey] = 1;
            }
        }
        for ($ownedFallbackIndex = 0; $ownedFallbackIndex < count($fallbackRows); $ownedFallbackIndex++) {
            if (!approval_is_document_owner($pdo, $fallbackRows[$ownedFallbackIndex], $u)) {
                continue;
            }
            $ownedFallbackKey = approval_index_leave_applicant_key($fallbackRows[$ownedFallbackIndex]);
            if ($ownedFallbackKey !== '') {
                $ownedLeaveApplicantKeys[$ownedFallbackKey] = 1;
            }
        }

        for ($fallbackIndex = 0; $fallbackIndex < count($fallbackRows); $fallbackIndex++) {
            $fallbackRow = $fallbackRows[$fallbackIndex];
            $fallbackId = isset($fallbackRow['id']) ? (int)$fallbackRow['id'] : 0;
            if ($fallbackId <= 0 || isset($visibleDocumentIds[$fallbackId])) {
                continue;
            }
            $isDirectOwner = approval_is_document_owner($pdo, $fallbackRow, $u);
            $fallbackApplicantKey = approval_index_leave_applicant_key($fallbackRow);
            $isOwnedLeaveCluster = (!$isDirectOwner && $fallbackApplicantKey !== '' && isset($ownedLeaveApplicantKeys[$fallbackApplicantKey]));
            if ($isDirectOwner || $isOwnedLeaveCluster) {
                $rows[] = $fallbackRow;
                $visibleDocumentIds[$fallbackId] = 1;
                $debugInfo['owner_fallback_count']++;
                if ($isOwnedLeaveCluster) {
                    $debugInfo['owner_cluster_count']++;
                }
            }
        }
        if ($debugInfo['owner_fallback_count'] > 0) {
            usort($rows, 'approval_index_created_desc_compare');
        }
    } catch (Exception $e) {
        error_log('[approval_index] owner fallback error: ' . $e->getMessage());
    }
}

if ($view === 'cancelled') {
    $mineCancelled = 0;
    for ($i = 0; $i < count($rows); $i++) {
        if (approval_is_document_owner($pdo, $rows[$i], $u)) {
            $mineCancelled++;
        }
    }
    $countCards = array(
        array('label' => $txt['card_cancelled'], 'count' => count($rows)),
        array('label' => $txt['card_my_cancelled'], 'count' => $mineCancelled)
    );
} else if ($view === 'completed') {
    $mineCompleted = 0;
    $approvedByMe = 0;
    for ($i = 0; $i < count($rows); $i++) {
        if (approval_is_document_owner($pdo, $rows[$i], $u)) {
            $mineCompleted++;
        }
        if (isset($rows[$i]['my_line_status']) && trim((string)$rows[$i]['my_line_status']) !== '') {
            $approvedByMe++;
        }
    }
    $countCards = array(
        array('label' => $txt['card_completed'], 'count' => count($rows)),
        array('label' => $txt['card_my_completed'], 'count' => $mineCompleted),
        array('label' => $txt['card_my_approved'], 'count' => $approvedByMe)
    );
} else {
    $recv = 0;
    $mine = 0;
    $prog = 0;
    $rej = 0;
    for ($i = 0; $i < count($rows); $i++) {
        if (approval_is_document_owner($pdo, $rows[$i], $u)) {
            $mine++;
        }
        if (isset($rows[$i]['my_line_status']) && trim((string)$rows[$i]['my_line_status']) !== '') {
            $recv++;
        }
        $status = strtoupper(trim((string)(isset($rows[$i]['doc_status']) ? $rows[$i]['doc_status'] : '')));
        if ($status === 'PENDING' || $status === 'DRAFT') {
            $prog++;
        }
        if ($status === 'REJECTED') {
            $rej++;
        }
    }
    $countCards = array(
        array('label' => $txt['card_received'], 'count' => $recv),
        array('label' => $txt['card_requested'], 'count' => $mine),
        array('label' => $txt['card_progress'], 'count' => $prog),
        array('label' => $txt['card_rejected'], 'count' => $rej)
    );
}

$approvalPageSize = 7;
$approvalTotalRows = count($rows);
$approvalTotalPages = 1;
$approvalPage = isset($_GET['approval_page']) ? (int)$_GET['approval_page'] : 1;
$approvalUsePagination = ($view === 'completed');
if ($approvalUsePagination) {
    $approvalTotalPages = max(1, (int)ceil($approvalTotalRows / $approvalPageSize));
    if ($approvalPage < 1) $approvalPage = 1;
    if ($approvalPage > $approvalTotalPages) $approvalPage = $approvalTotalPages;
    $rows = array_slice($rows, ($approvalPage - 1) * $approvalPageSize, $approvalPageSize);
} else {
    $approvalPage = 1;
}
$mobileRows = $rows;
$approvalPageParams = $_GET;
if (!is_array($approvalPageParams)) $approvalPageParams = array();
$approvalPageParams['r'] = 'approval_home';
$approvalPageParams['view'] = $view;

?>
<div class="cpms-approval-page space-y-5">
    <?php if ($fatalMessage !== '') { ?>
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4"><?php echo h($fatalMessage); ?></div>
    <?php } ?>

    <?php if ($debugApproval) { ?>
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-900 rounded-2xl p-4 text-sm">
            <div><strong>debug_approval</strong></div>
            <div>view: <?php echo h($debugInfo['view']); ?></div>
            <div>uid: <?php echo (int)$debugInfo['uid']; ?></div>
            <div>userEmail: <?php echo h($debugInfo['user_email']); ?></div>
            <div>userName: <?php echo h($debugInfo['user_name']); ?></div>
            <div>isAdmin: <?php echo h($debugInfo['is_admin']); ?></div>
            <div>params: <?php echo h(implode(', ', $debugInfo['params_keys'])); ?></div>
            <div>sql: <?php echo h($debugInfo['sql_status']); ?></div>
            <div>ownerFallback: <?php echo (int)$debugInfo['owner_fallback_count']; ?></div>
            <div>ownerCluster: <?php echo (int)$debugInfo['owner_cluster_count']; ?></div>
            <?php if ($debugInfo['sql_error'] !== '') { ?>
                <div>error: <?php echo h($debugInfo['sql_error']); ?></div>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="bg-gradient-to-r from-indigo-600 to-cyan-500 rounded-3xl p-7 text-white shadow-xl">
        <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-6">
            <div class="min-w-0">
                <h2 class="text-3xl font-extrabold"><?php echo h($pageTitle); ?></h2>
                <?php if (trim((string)$pageDesc) !== ''): ?>
                    <p class="mt-2 text-indigo-100"><?php echo h($pageDesc); ?></p>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap items-center justify-start xl:justify-end gap-3 shrink-0 max-w-none">
                <a class="cpms-mobile-hide inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl bg-white text-indigo-700" href="?r=approval_create&type=proposal"><?php echo h($txt['create_proposal']); ?></a>
                <a class="cpms-mobile-hide inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl bg-white text-violet-700" href="?r=approval_create&type=small_proposal"><?php echo h($txt['create_small_proposal']); ?></a>
                <a class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl bg-white text-cyan-700" href="?r=approval_create&type=leave"><?php echo h($txt['create_leave']); ?></a>
                <?php if (approval_is_management_department_user($pdo, $u)) { ?>
                    <a class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl bg-white text-emerald-700" href="?r=approval_create&type=unused_leave_notice"><?php echo h($txt['create_unused_leave_notice']); ?></a>
                    <a class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl bg-white text-amber-700" href="?r=approval_create&type=unused_leave_plan"><?php echo h($txt['create_unused_leave_plan']); ?></a>
                <?php } ?>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 mt-5">
            <a href="?r=approval_home&view=active" class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl transition <?php echo approval_index_tab_class($view, 'active'); ?>"><?php echo h($txt['view_active']); ?></a>
            <a href="?r=approval_home&view=cancelled" class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl transition <?php echo approval_index_tab_class($view, 'cancelled'); ?>"><?php echo h($txt['view_cancelled']); ?></a>
            <a href="?r=approval_home&view=completed" class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl transition <?php echo approval_index_tab_class($view, 'completed'); ?>"><?php echo h($txt['view_completed']); ?></a>
        </div>
    </div>

    <?php if ($view === 'completed') { ?>
        <form method="get" action="" class="bg-white rounded-3xl border p-5">
            <input type="hidden" name="r" value="approval_home">
            <input type="hidden" name="view" value="completed">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1"><?php echo h($txt['filter_doc_type']); ?></div>
                    <select name="doc_type" class="w-full border rounded-xl px-3 py-2">
                        <option value=""><?php echo h($txt['filter_all']); ?></option>
                        <option value="proposal" <?php echo ($docTypeFilter === 'proposal') ? 'selected' : ''; ?>><?php echo h($txt['create_proposal']); ?></option>
                        <option value="small_proposal" <?php echo ($docTypeFilter === 'small_proposal') ? 'selected' : ''; ?>><?php echo h($txt['create_small_proposal']); ?></option>
                        <option value="leave" <?php echo ($docTypeFilter === 'leave') ? 'selected' : ''; ?>><?php echo h($txt['create_leave']); ?></option>
                        <option value="unused_leave_notice" <?php echo ($docTypeFilter === 'unused_leave_notice') ? 'selected' : ''; ?>><?php echo h($txt['create_unused_leave_notice']); ?></option>
                        <option value="unused_leave_plan" <?php echo ($docTypeFilter === 'unused_leave_plan') ? 'selected' : ''; ?>><?php echo h($txt['create_unused_leave_plan']); ?></option>
                    </select>
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1"><?php echo h($txt['filter_title']); ?></div>
                    <input type="text" name="title" value="<?php echo h($titleFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1"><?php echo h($txt['filter_author']); ?></div>
                    <input type="text" name="author" value="<?php echo h($authorFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1"><?php echo h($txt['filter_date_from']); ?></div>
                    <input type="date" name="date_from" value="<?php echo h($dateFromFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1"><?php echo h($txt['filter_date_to']); ?></div>
                    <input type="date" name="date_to" value="<?php echo h($dateToFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
                <div>
                    <div class="text-sm font-bold text-gray-700 mb-1"><?php echo h($txt['filter_q']); ?></div>
                    <input type="text" name="q" value="<?php echo h($queryFilter); ?>" class="w-full border rounded-xl px-3 py-2">
                </div>
            </div>
            <div class="flex flex-wrap gap-2 mt-4">
                <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold"><?php echo h($txt['search']); ?></button>
                <a href="?r=approval_home&view=completed" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-900 font-bold"><?php echo h($txt['reset']); ?></a>
            </div>
        </form>
    <?php } ?>

    <?php if (count($countCards) > 0) { ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <?php for ($i = 0; $i < count($countCards); $i++) { ?>
                <div class="rounded-3xl p-[1px] bg-gradient-to-br <?php echo approval_index_card_class($i); ?>">
                    <div class="rounded-[calc(1.5rem-1px)] bg-white p-5 h-full">
                        <div class="text-sm font-semibold text-gray-500"><?php echo h($countCards[$i]['label']); ?></div>
                        <div class="mt-2 text-3xl font-extrabold text-gray-900"><?php echo number_format((int)$countCards[$i]['count']); ?></div>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="bg-white rounded-3xl border overflow-hidden">
        <div class="px-5 py-4 border-b bg-gray-50">
            <div class="text-lg font-extrabold text-gray-900"><?php echo h($pageTitle); ?></div>
        </div>

        <?php if (count($rows) === 0) { ?>
            <div class="p-8 text-center text-gray-500"><?php echo h($emptyMessage); ?></div>
        <?php } else { ?>
            <div class="cpms-approval-mobile-list">
                <?php if (count($mobileRows) === 0) { ?>
                    <div class="p-5 text-center text-gray-500"><?php echo h($emptyMessage); ?></div>
                <?php } else { ?>
                    <div class="p-3 space-y-3">
                        <?php for ($mi = 0; $mi < count($mobileRows); $mi++) {
                            $row = $mobileRows[$mi];
                            $myLineStatus = isset($row['my_line_status']) ? trim((string)$row['my_line_status']) : '';
                            $docStatus = isset($row['doc_status']) ? trim((string)$row['doc_status']) : '';
                            $currentRole = isset($row['current_role']) ? trim((string)$row['current_role']) : '';
                            $mobileDocType = isset($row['doc_type']) ? trim((string)$row['doc_type']) : '';
                            $canMobileDecide = ($mobileDocType === 'leave' && strtoupper($docStatus) === 'PENDING' && strtoupper($myLineStatus) === 'PENDING');
                        ?>
                            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-xs font-extrabold text-cyan-700"><?php echo h(approval_doc_label(isset($row['doc_type']) ? $row['doc_type'] : '')); ?></div>
                                        <div class="mt-1 text-base font-extrabold text-gray-900 leading-6"><?php echo h(isset($row['title']) ? $row['title'] : ''); ?></div>
                                        <div class="mt-2 text-xs text-gray-500">
                                            <?php echo h(isset($row['created_by_name']) ? $row['created_by_name'] : ''); ?>
                                            · <?php echo h(isset($row['created_at']) ? $row['created_at'] : ''); ?>
                                        </div>
                                    </div>
                                    <span class="shrink-0 inline-flex items-center px-3 py-1 rounded-full border text-xs font-bold <?php echo h(approval_status_badge($docStatus)); ?>">
                                        <?php echo h(approval_status_label($docStatus)); ?>
                                    </span>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-gray-600">
                                    <div class="rounded-xl bg-gray-50 p-2">
                                        <span class="block text-gray-400">현재 결재</span>
                                        <b><?php echo h($currentRole === '' ? '-' : approval_role_label($currentRole)); ?></b>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-2">
                                        <span class="block text-gray-400">내 결재선</span>
                                        <b><?php echo h($myLineStatus === '' ? '-' : approval_line_status_label($myLineStatus)); ?></b>
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <a href="?r=approval_detail&id=<?php echo (int)$row['id']; ?>" class="flex-1 inline-flex items-center justify-center px-3 py-3 rounded-xl bg-indigo-50 text-indigo-700 font-extrabold"><?php echo h($txt['detail']); ?></a>
                                    <?php if ($canMobileDecide) { ?>
                                        <form method="post" action="?r=approval_decide" class="flex-1">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="w-full px-3 py-3 rounded-xl bg-emerald-600 text-white font-extrabold">승인</button>
                                        </form>
                                    <?php } ?>
                                </div>
                                <?php if ($canMobileDecide) { ?>
                                    <form method="post" action="?r=approval_decide" class="mt-2 flex gap-2">
                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="text" name="reject_reason" required class="min-w-0 flex-1 rounded-xl border border-gray-200 px-3 py-3" placeholder="반려 사유">
                                        <button type="submit" class="px-4 py-3 rounded-xl bg-rose-600 text-white font-extrabold">반려</button>
                                    </form>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>

            <div class="cpms-approval-table overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left"><?php echo h($txt['table_type']); ?></th>
                            <th class="px-4 py-3 text-left"><?php echo h($txt['table_title']); ?></th>
                            <th class="px-4 py-3 text-left"><?php echo h($txt['table_author']); ?></th>
                            <th class="px-4 py-3 text-left"><?php echo h($txt['table_created_at']); ?></th>
                            <?php if ($view === 'completed') { ?>
                                <th class="px-4 py-3 text-left"><?php echo h($txt['table_completed_at']); ?></th>
                            <?php } else { ?>
                                <th class="px-4 py-3 text-left"><?php echo h($txt['table_current_step']); ?></th>
                            <?php } ?>
                            <th class="px-4 py-3 text-left"><?php echo h($txt['table_my_step']); ?></th>
                            <th class="px-4 py-3 text-left"><?php echo h($txt['table_status']); ?></th>
                            <th class="px-4 py-3 text-left"><?php echo h($txt['table_actions']); ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php for ($i = 0; $i < count($rows); $i++) {
                            $row = $rows[$i];
                            $currentRole = isset($row['current_role']) ? trim((string)$row['current_role']) : '';
                            $myLineStatus = isset($row['my_line_status']) ? trim((string)$row['my_line_status']) : '';
                            $completedAt = isset($row['updated_at']) ? (string)$row['updated_at'] : '';
                        ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap"><?php echo h(approval_doc_label(isset($row['doc_type']) ? $row['doc_type'] : '')); ?></td>
                                <td class="px-4 py-3 min-w-[280px]">
                                    <div class="font-semibold text-gray-900"><?php echo h(isset($row['title']) ? $row['title'] : ''); ?></div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap"><?php echo h(isset($row['created_by_name']) ? $row['created_by_name'] : ''); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap"><?php echo h(isset($row['created_at']) ? $row['created_at'] : ''); ?></td>
                                <?php if ($view === 'completed') { ?>
                                    <td class="px-4 py-3 whitespace-nowrap"><?php echo h($completedAt); ?></td>
                                <?php } else { ?>
                                    <td class="px-4 py-3 whitespace-nowrap"><?php echo h($currentRole === '' ? '-' : approval_role_label($currentRole)); ?></td>
                                <?php } ?>
                                <td class="px-4 py-3 whitespace-nowrap"><?php echo h($myLineStatus === '' ? '-' : approval_line_status_label($myLineStatus)); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-bold <?php echo h(approval_status_badge(isset($row['doc_status']) ? $row['doc_status'] : '')); ?>">
                                        <?php echo h(approval_status_label(isset($row['doc_status']) ? $row['doc_status'] : '')); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex flex-wrap gap-2">
                                        <a href="?r=approval_detail&id=<?php echo (int)$row['id']; ?>" class="inline-flex items-center px-3 py-2 rounded-xl bg-indigo-50 text-indigo-700 font-bold"><?php echo h($txt['detail']); ?></a>
                                        <?php if (approval_is_document_owner($pdo, $row, $u) && approval_can_cancel_document($row)) { ?>
                                            <form method="post" action="?r=approval_cancel" onsubmit="return confirm('<?php echo h($txt['confirm_cancel']); ?>');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="inline-flex items-center px-3 py-2 rounded-xl bg-rose-50 text-rose-700 font-bold"><?php echo h($txt['cancel']); ?></button>
                                            </form>
                                        <?php } ?>
                                        <?php if (approval_can_delete_document($pdo, $row, $u)) { ?>
                                            <form method="post" action="?r=approval_delete" onsubmit="return confirm('<?php echo h($txt['confirm_delete']); ?>');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="inline-flex items-center px-3 py-2 rounded-xl bg-gray-100 text-gray-800 font-bold"><?php echo h($txt['delete']); ?></button>
                                            </form>
                                        <?php } ?>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if ($approvalUsePagination && $approvalTotalPages > 1) { ?>
                <nav class="px-4 py-5 border-t border-gray-100 bg-gray-50 flex flex-wrap items-center justify-center gap-2" aria-label="전자결재 문서 페이지">
                    <?php for ($approvalPageNumber = 1; $approvalPageNumber <= $approvalTotalPages; $approvalPageNumber++) {
                        $approvalPageParams['approval_page'] = $approvalPageNumber;
                        $approvalPageUrl = '?' . http_build_query($approvalPageParams, '', '&');
                        $approvalPageClass = ($approvalPageNumber === $approvalPage)
                            ? 'bg-indigo-600 border-indigo-600 text-white shadow-sm'
                            : 'bg-white border-gray-200 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700';
                    ?>
                        <a href="<?php echo h($approvalPageUrl); ?>"
                           class="inline-flex min-w-[38px] h-10 items-center justify-center rounded-xl border px-3 text-sm font-extrabold <?php echo h($approvalPageClass); ?>"
                           <?php echo ($approvalPageNumber === $approvalPage) ? 'aria-current="page"' : ''; ?>><?php echo $approvalPageNumber; ?></a>
                    <?php } ?>
                </nav>
            <?php } ?>
        <?php } ?>
    </div>
</div>
