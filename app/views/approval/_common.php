<?php
use App\Core\Db;

if (!function_exists('approval_ko')) {
    function approval_ko($encoded)
    {
        return urldecode($encoded);
    }
}

if (!function_exists('approval_status_badge')) {
    function approval_status_badge($status)
    {
        $status = strtoupper((string)$status);
        $map = array(
            'DRAFT' => 'bg-slate-100 text-slate-700 border-slate-300',
            'PENDING' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'WAITING' => 'bg-amber-50 text-amber-700 border-amber-200',
            'APPROVED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'COMPLETED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'REJECTED' => 'bg-rose-50 text-rose-700 border-rose-200',
            'CANCELLED' => 'bg-gray-100 text-gray-700 border-gray-300',
            'SKIPPED' => 'bg-slate-100 text-slate-700 border-slate-300',
            'DELEGATED' => 'bg-zinc-100 text-zinc-800 border-zinc-300',
            'REFERENCE' => 'bg-cyan-50 text-cyan-700 border-cyan-200'
        );
        return isset($map[$status]) ? $map[$status] : 'bg-gray-100 text-gray-700 border-gray-300';
    }
}

if (!function_exists('approval_status_label')) {
    function approval_status_label($status)
    {
        $status = strtoupper(trim((string)$status));
        $map = array(
            'DRAFT' => approval_ko('%EC%9E%84%EC%8B%9C%EC%A0%80%EC%9E%A5'),
            'PENDING' => approval_ko('%EC%A7%84%ED%96%89%EC%A4%91'),
            'WAITING' => approval_ko('%EB%8C%80%EA%B8%B0'),
            'APPROVED' => approval_ko('%EC%99%84%EB%A3%8C'),
            'COMPLETED' => approval_ko('%EC%99%84%EB%A3%8C'),
            'REJECTED' => approval_ko('%EB%B0%98%EB%A0%A4'),
            'CANCELLED' => approval_ko('%EC%9A%94%EC%B2%AD%EC%B7%A8%EC%86%8C'),
            'SKIPPED' => approval_ko('%EA%B1%B4%EB%84%88%EB%9C%80'),
            'DELEGATED' => approval_ko('%EC%A0%84%EA%B2%B0'),
            'REFERENCE' => approval_ko('%EC%B0%B8%EC%A1%B0')
        );
        return isset($map[$status]) ? $map[$status] : $status;
    }
}

if (!function_exists('approval_line_status_label')) {
    function approval_line_status_label($status)
    {
        return approval_status_label($status);
    }
}

if (!function_exists('approval_role_label')) {
    function approval_role_label($role)
    {
        $role = trim((string)$role);
        $lower = strtolower($role);
        $map = array(
            'site_manager' => approval_ko('%EC%86%8C%EC%9E%A5'),
            'sojang' => approval_ko('%EC%86%8C%EC%9E%A5'),
            'pm' => approval_ko('%EA%B3%B5%EC%82%AC%50%4D'),
            'gongmu' => approval_ko('%EA%B3%B5%EB%AC%B4'),
            'manage' => approval_ko('%EA%B4%80%EB%A6%AC'),
            'admin' => approval_ko('%EA%B4%80%EB%A6%AC'),
            'vp' => approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'),
            'ceo' => approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'),
            'team_lead' => approval_ko('%ED%8C%80%EC%9E%A5'),
            'leader' => approval_ko('%ED%8C%80%EC%9E%A5')
        );
        if (isset($map[$lower])) {
            return $map[$lower];
        }
        return $role === '' ? '-' : $role;
    }
}

if (!function_exists('approval_parse_content')) {
    function approval_parse_content($content)
    {
        $raw = trim((string)$content);
        if ($raw === '') {
            return array();
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
        return array('legacy_content' => $raw);
    }
}

if (!function_exists('approval_doc_label')) {
    function approval_doc_label($type)
    {
        $type = strtolower(trim((string)$type));
        if ($type === 'leave') {
            return approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84');
        }
        if ($type === 'proposal') {
            return approval_ko('%EA%B8%B0%EC%95%88%EC%84%9C');
        }
        if ($type === 'small_proposal') {
            return approval_ko('%31%30%EB%A7%8C%EC%9B%90%20%EC%9D%B4%ED%95%98%20%EC%86%8C%EC%95%A1%EA%B8%B0%EC%95%88%EC%84%9C');
        }
        if ($type === 'unused_leave_notice') {
            return approval_ko('%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EC%B4%89%EA%B5%AC%EC%84%9C');
        }
        if ($type === 'unused_leave_plan') {
            return approval_ko('%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B3%84%ED%9A%8D%EC%84%9C');
        }
        return $type === '' ? approval_ko('%EB%AC%B8%EC%84%9C') : (string)$type;
    }
}

if (!function_exists('approval_is_proposal_doc_type')) {
    function approval_is_proposal_doc_type($type)
    {
        $type = strtolower(trim((string)$type));
        return in_array($type, array('proposal', 'small_proposal'), true);
    }
}

if (!function_exists('approval_is_management_only_doc_type')) {
    function approval_is_management_only_doc_type($type)
    {
        $type = strtolower(trim((string)$type));
        return in_array($type, array('unused_leave_notice', 'unused_leave_plan'), true);
    }
}

if (!function_exists('approval_auto_delegate_target_doc_type')) {
    function approval_auto_delegate_target_doc_type($type)
    {
        $type = strtolower(trim((string)$type));
        return ($type === 'leave' || approval_is_proposal_doc_type($type));
    }
}

if (!function_exists('approval_normalize_compare_text')) {
    function approval_normalize_compare_text($value)
    {
        $value = trim((string)$value);
        $value = str_replace(array(' ', "\t", "\r", "\n", '-', '_', '/', '.', ',', "\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"), '', $value);
        $unicodeClean = @preg_replace('/[\p{Z}\p{C}]+/u', '', $value);
        if ($unicodeClean !== null) {
            $value = $unicodeClean;
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

if (!function_exists('approval_sql_normalize_compare_text')) {
    function approval_sql_normalize_compare_text($expression)
    {
        return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(" . $expression . "), ' ', ''), CHAR(9), ''), CHAR(13), ''), CHAR(10), ''), '-', ''), '_', ''), '/', ''), '.', ''), ',', ''), UNHEX('C2A0'), ''), UNHEX('E2808B'), ''), UNHEX('EFBBBF'), ''))";
    }
}

if (!function_exists('approval_employee_person_name_base')) {
    function approval_employee_person_name_base($value)
    {
        $name = approval_normalize_compare_text($value);
        if ($name === '') {
            return '';
        }
        $suffixes = array(
            '님', '대표이사', '전무이사', '상무이사', '사업부장', '현장소장',
            '부사장', '본부장', '센터장', '실장', '팀장', '소장',
            '부장', '차장', '과장', '대리', '주임', '사원',
            '수석', '책임', '선임', '매니저', '전무', '상무', '이사', '대표'
        );

        do {
            $removed = false;
            for ($i = 0; $i < count($suffixes); $i++) {
                $suffix = approval_normalize_compare_text($suffixes[$i]);
                $suffixLength = strlen($suffix);
                if ($suffixLength > 0 && strlen($name) >= $suffixLength && substr($name, -$suffixLength) === $suffix) {
                    $name = substr($name, 0, strlen($name) - $suffixLength);
                    $removed = true;
                    break;
                }
            }
        } while ($removed && $name !== '');

        if ($name === '') {
            return '';
        }
        if (function_exists('mb_strlen')) {
            $nameLength = @mb_strlen($name, 'UTF-8');
        } else {
            $nameLength = @preg_match_all('/./u', $name, $nameCharacters);
        }
        if ($nameLength === false || (int)$nameLength < 2) {
            return '';
        }
        $letterCount = @preg_match_all('/\p{L}/u', $name, $nameLetters);
        if ($letterCount === false || (int)$letterCount < 2) {
            return '';
        }
        return $name;
    }
}

if (!function_exists('approval_employee_identity_matches')) {
    function approval_employee_identity_matches($employee, $id, $email, $name)
    {
        if (!is_array($employee)) {
            return false;
        }
        if ((int)$id > 0 && isset($employee['id']) && (int)$employee['id'] === (int)$id) {
            return true;
        }
        if (trim((string)$email) !== '' && isset($employee['email']) && strtolower(trim((string)$employee['email'])) === strtolower(trim((string)$email))) {
            return true;
        }
        if (trim((string)$name) !== '' && isset($employee['name']) && trim((string)$employee['name']) === trim((string)$name)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('approval_line_matches_employee')) {
    function approval_line_matches_employee($line, $employee)
    {
        if (!is_array($line) || !is_array($employee)) {
            return false;
        }
        $id = isset($line['approver_id']) ? (int)$line['approver_id'] : (isset($line['emp']['id']) ? (int)$line['emp']['id'] : 0);
        $email = isset($line['approver_email']) ? (string)$line['approver_email'] : (isset($line['emp']['email']) ? (string)$line['emp']['email'] : '');
        $name = isset($line['approver_name']) ? (string)$line['approver_name'] : (isset($line['emp']['name']) ? (string)$line['emp']['name'] : '');
        return approval_employee_identity_matches($employee, $id, $email, $name);
    }
}

if (!function_exists('approval_role_is_vp')) {
    function approval_role_is_vp($role)
    {
        $roleNorm = approval_normalize_compare_text(approval_role_label($role));
        $vpNorm = approval_normalize_compare_text(approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'));
        return ($roleNorm === 'vp' || $roleNorm === $vpNorm);
    }
}

if (!function_exists('approval_role_is_ceo')) {
    function approval_role_is_ceo($role)
    {
        $roleNorm = approval_normalize_compare_text(approval_role_label($role));
        $ceoNorm = approval_normalize_compare_text(approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'));
        $ceoShortNorm = approval_normalize_compare_text(approval_ko('%EB%8C%80%ED%91%9C'));
        return ($roleNorm === 'ceo' || $roleNorm === $ceoNorm || $roleNorm === $ceoShortNorm);
    }
}

if (!function_exists('approval_role_is_team_or_pm')) {
    function approval_role_is_team_or_pm($role)
    {
        $roleRawNorm = approval_normalize_compare_text($role);
        $roleNorm = approval_normalize_compare_text(approval_role_label($role));
        $teamNorm = approval_normalize_compare_text(approval_ko('%ED%8C%80%EC%9E%A5'));
        $constructionPmNorm = approval_normalize_compare_text(approval_ko('%EA%B3%B5%EC%82%AC%50%4D'));
        return ($roleRawNorm === 'pm' || $roleNorm === 'pm' || $roleRawNorm === $constructionPmNorm || $roleNorm === $constructionPmNorm || $roleNorm === $teamNorm);
    }
}

if (!function_exists('approval_employee_text_blob')) {
    function approval_employee_text_blob($employee)
    {
        if (!is_array($employee)) {
            return '';
        }
        $parts = array();
        $keys = array('name', 'position', 'role', 'department');
        for ($i = 0; $i < count($keys); $i++) {
            if (isset($employee[$keys[$i]]) && trim((string)$employee[$keys[$i]]) !== '') {
                $parts[] = (string)$employee[$keys[$i]];
            }
        }
        return approval_normalize_compare_text(implode(' ', $parts));
    }
}

if (!function_exists('approval_employee_is_vp')) {
    function approval_employee_is_vp($employee)
    {
        $blob = approval_employee_text_blob($employee);
        if ($blob === '') {
            return false;
        }
        $words = array(
            approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'),
            'vicepresident',
            'vicepres',
            'vp'
        );
        for ($i = 0; $i < count($words); $i++) {
            $w = approval_normalize_compare_text($words[$i]);
            if ($w !== '' && strpos($blob, $w) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('approval_employee_is_executive')) {
    function approval_employee_is_executive($employee)
    {
        $blob = approval_employee_text_blob($employee);
        if ($blob === '') {
            return false;
        }
        $words = array(
            'executive',
            'ceo',
            'president',
            'vp',
            'vicepresident',
            approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'),
            approval_ko('%EB%8C%80%ED%91%9C'),
            approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5'),
            approval_ko('%EC%83%81%EB%AC%B4'),
            approval_ko('%EC%A0%84%EB%AC%B4'),
            approval_ko('%EC%9D%B4%EC%82%AC')
        );
        for ($i = 0; $i < count($words); $i++) {
            $w = approval_normalize_compare_text($words[$i]);
            if ($w !== '' && strpos($blob, $w) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('approval_employee_is_ceo')) {
    function approval_employee_is_ceo($employee)
    {
        $blob = approval_employee_text_blob($employee);
        if ($blob === '') {
            return false;
        }
        $words = array(
            approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'),
            approval_ko('%EB%8C%80%ED%91%9C%EB%8B%98'),
            approval_ko('%EB%8C%80%ED%91%9C'),
            'ceo',
            'chiefexecutiveofficer'
        );
        for ($i = 0; $i < count($words); $i++) {
            $w = approval_normalize_compare_text($words[$i]);
            if ($w !== '' && strpos($blob, $w) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('approval_leave_normalize_date')) {
    function approval_leave_normalize_date($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '';
        }
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('approval_leave_status_label_from_type')) {
    function approval_leave_status_label_from_type($requestType)
    {
        $requestType = trim((string)$requestType);
        $norm = approval_normalize_compare_text($requestType);
        $halfNorm = approval_normalize_compare_text(approval_ko('%EB%B0%98%EC%B0%A8'));
        $morningNorm = approval_normalize_compare_text(approval_ko('%EC%98%A4%EC%A0%84'));
        $afternoonNorm = approval_normalize_compare_text(approval_ko('%EC%98%A4%ED%9B%84'));
        if ($norm !== '' && $halfNorm !== '' && strpos($norm, $halfNorm) !== false) {
            if ($morningNorm !== '' && strpos($norm, $morningNorm) !== false) {
                return approval_ko('%EC%98%A4%EC%A0%84%EB%B0%98%EC%B0%A8');
            }
            if ($afternoonNorm !== '' && strpos($norm, $afternoonNorm) !== false) {
                return approval_ko('%EC%98%A4%ED%9B%84%EB%B0%98%EC%B0%A8');
            }
            return approval_ko('%EB%B0%98%EC%B0%A8');
        }
        return approval_ko('%ED%9C%B4%EA%B0%80%EC%A4%91');
    }
}

if (!function_exists('approval_leave_half_day_period_from_type')) {
    function approval_leave_half_day_period_from_type($requestType)
    {
        $norm = approval_normalize_compare_text($requestType);
        $halfNorm = approval_normalize_compare_text(approval_ko('%EB%B0%98%EC%B0%A8'));
        if ($norm === '' || $halfNorm === '' || strpos($norm, $halfNorm) === false) {
            return '';
        }

        $morningNorm = approval_normalize_compare_text(approval_ko('%EC%98%A4%EC%A0%84'));
        if ($morningNorm !== '' && strpos($norm, $morningNorm) !== false) {
            return 'morning';
        }

        $afternoonNorm = approval_normalize_compare_text(approval_ko('%EC%98%A4%ED%9B%84'));
        if ($afternoonNorm !== '' && strpos($norm, $afternoonNorm) !== false) {
            return 'afternoon';
        }

        return '';
    }
}

if (!function_exists('approval_leave_is_active_for_current_time')) {
    function approval_leave_is_active_for_current_time($requestType, $leaveDate, $currentDateTime)
    {
        $halfDayPeriod = approval_leave_half_day_period_from_type($requestType);
        if ($halfDayPeriod === '') {
            return true;
        }

        $leaveDate = approval_leave_normalize_date($leaveDate);
        $currentTs = strtotime(trim((string)$currentDateTime));
        if ($leaveDate === '' || $currentTs === false) {
            return true;
        }

        $currentDate = date('Y-m-d', $currentTs);
        if ($leaveDate !== $currentDate) {
            return true;
        }

        $currentTime = date('H:i:s', $currentTs);
        // Morning and afternoon half-day status switches at noon.
        if ($halfDayPeriod === 'morning') {
            return strcmp($currentTime, '12:00:00') < 0;
        }
        if ($halfDayPeriod === 'afternoon') {
            return strcmp($currentTime, '12:00:00') >= 0;
        }

        return true;
    }
}

if (!function_exists('approval_leave_type_label_from_content')) {
    function approval_leave_type_label_from_content($content)
    {
        if (!is_array($content)) {
            return '';
        }
        $requestType = isset($content['request_type']) ? trim((string)$content['request_type']) : '';
        $requestTypeEtc = isset($content['request_type_etc']) ? trim((string)$content['request_type_etc']) : '';
        if ($requestType === approval_ko('%EA%B8%B0%ED%83%80') && $requestTypeEtc !== '') {
            return $requestTypeEtc;
        }
        return $requestType;
    }
}

if (!function_exists('approval_current_leave_info_from_index')) {
    function approval_current_leave_info_from_index($index, $employee)
    {
        if (!is_array($index) || !is_array($employee)) {
            return null;
        }
        $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
        if ($employeeId > 0 && isset($index['by_id']) && isset($index['by_id'][$employeeId])) {
            return $index['by_id'][$employeeId];
        }
        $email = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
        if ($email !== '' && isset($index['by_email']) && isset($index['by_email'][$email])) {
            return $index['by_email'][$email];
        }
        $name = isset($employee['name']) ? trim((string)$employee['name']) : '';
        if ($name !== '' && isset($index['by_name']) && isset($index['by_name'][$name])) {
            return $index['by_name'][$name];
        }
        return null;
    }
}

if (!function_exists('approval_current_leave_index')) {
    function approval_current_leave_index($pdo, $baseDate)
    {
        static $cache = array();
        $empty = array('by_id' => array(), 'by_email' => array(), 'by_name' => array(), 'people' => array());
        if (!$pdo) {
            return $empty;
        }
        $baseDate = approval_leave_normalize_date($baseDate);
        if ($baseDate === '') {
            $baseDate = date('Y-m-d');
        }
        $cacheKey = (function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'nopdo') . ':' . $baseDate;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $cache[$cacheKey] = $empty;
        if (!approval_table_exists($pdo, 'cpms_approval_documents')) {
            return $cache[$cacheKey];
        }

        $employeeExists = approval_table_exists($pdo, 'employees');
        $hasCreatedByEmail = approval_table_column_exists($pdo, 'cpms_approval_documents', 'created_by_email');
        $createdByEmailSelect = $hasCreatedByEmail ? 'd.created_by_email' : "'' AS created_by_email";
        $employeeSelect = "'' AS employee_name, '' AS employee_email, '' AS employee_department, '' AS employee_position, '' AS employee_role";
        $joinSql = '';
        if ($employeeExists) {
            $employeeNameSelect = approval_table_column_exists($pdo, 'employees', 'name') ? 'e.name' : "''";
            $employeeEmailSelect = approval_table_column_exists($pdo, 'employees', 'email') ? 'e.email' : "''";
            $employeeDepartmentSelect = approval_table_column_exists($pdo, 'employees', 'department') ? 'e.department' : "''";
            $employeePositionSelect = approval_table_column_exists($pdo, 'employees', 'position') ? 'e.position' : "''";
            $employeeRoleSelect = approval_table_column_exists($pdo, 'employees', 'role') ? 'e.role' : "''";
            $employeeSelect = $employeeNameSelect . " AS employee_name, " . $employeeEmailSelect . " AS employee_email, " . $employeeDepartmentSelect . " AS employee_department, " . $employeePositionSelect . " AS employee_position, " . $employeeRoleSelect . " AS employee_role";
            $joinSql = ' LEFT JOIN employees e ON e.id = d.created_by_id';
        }

        try {
            $sql = "SELECT d.id, d.created_by_id, d.created_by_name, " . $createdByEmailSelect . ", d.content, d.created_at, d.updated_at, " . $employeeSelect . "
                    FROM cpms_approval_documents d" . $joinSql . "
                    WHERE d.doc_type='leave'
                      AND UPPER(COALESCE(d.doc_status,'')) IN ('APPROVED','COMPLETED')
                    ORDER BY d.updated_at DESC, d.id DESC";
            $st = $pdo->query($sql);
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        } catch (Exception $e) {
            $rows = array();
        }
        if (!is_array($rows)) {
            $rows = array();
        }

        $baseTs = strtotime($baseDate . ' 00:00:00');
        if ($baseTs === false) {
            return $cache[$cacheKey];
        }

        $seen = array();
        $currentDateTime = date('Y-m-d H:i:s');
        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $content = approval_parse_content(isset($row['content']) ? $row['content'] : '');
            $start = approval_leave_normalize_date(isset($content['leave_start_date']) ? $content['leave_start_date'] : '');
            $end = approval_leave_normalize_date(isset($content['leave_end_date']) ? $content['leave_end_date'] : '');
            if ($start === '' || $end === '') {
                continue;
            }
            $startTs = strtotime($start . ' 00:00:00');
            $endTs = strtotime($end . ' 00:00:00');
            if ($startTs === false || $endTs === false || $endTs < $startTs) {
                continue;
            }
            if ($baseTs < $startTs || $baseTs > $endTs) {
                continue;
            }

            $employeeId = isset($row['created_by_id']) ? (int)$row['created_by_id'] : 0;
            $name = isset($row['employee_name']) && trim((string)$row['employee_name']) !== '' ? trim((string)$row['employee_name']) : '';
            if ($name === '' && isset($row['created_by_name'])) {
                $name = trim((string)$row['created_by_name']);
            }
            if ($name === '' && isset($content['applicant_name'])) {
                $name = trim((string)$content['applicant_name']);
            }
            $email = isset($row['employee_email']) && trim((string)$row['employee_email']) !== '' ? trim((string)$row['employee_email']) : '';
            if ($email === '' && isset($row['created_by_email'])) {
                $email = trim((string)$row['created_by_email']);
            }
            if ($email === '' && isset($content['applicant_email'])) {
                $email = trim((string)$content['applicant_email']);
            }
            if ($email === '' && isset($content['writer_email'])) {
                $email = trim((string)$content['writer_email']);
            }
            $department = isset($row['employee_department']) && trim((string)$row['employee_department']) !== '' ? trim((string)$row['employee_department']) : '';
            if ($department === '' && isset($content['department'])) {
                $department = trim((string)$content['department']);
            }
            $position = isset($row['employee_position']) && trim((string)$row['employee_position']) !== '' ? trim((string)$row['employee_position']) : '';
            if ($position === '' && isset($content['position'])) {
                $position = trim((string)$content['position']);
            }
            $role = isset($row['employee_role']) ? trim((string)$row['employee_role']) : '';
            $typeLabel = approval_leave_type_label_from_content($content);
            if (!approval_leave_is_active_for_current_time($typeLabel, $baseDate, $currentDateTime)) {
                continue;
            }
            $statusLabel = approval_leave_status_label_from_type($typeLabel);
            $dedupeKey = $employeeId > 0 ? 'id:' . $employeeId : ($email !== '' ? 'email:' . strtolower($email) : 'name:' . $name);
            if ($dedupeKey === 'name:' || isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = 1;

            $info = array(
                'employee_id' => $employeeId,
                'name' => $name !== '' ? $name : '-',
                'email' => $email,
                'department' => $department,
                'position' => $position,
                'role' => $role,
                'start_date' => $start,
                'end_date' => $end,
                'period' => $start . ' ~ ' . $end,
                'type_label' => $typeLabel,
                'status_label' => $statusLabel,
                'document_id' => isset($row['id']) ? (int)$row['id'] : 0
            );

            $cache[$cacheKey]['people'][count($cache[$cacheKey]['people'])] = $info;
            if ($employeeId > 0) {
                $cache[$cacheKey]['by_id'][$employeeId] = $info;
            }
            if ($email !== '') {
                $cache[$cacheKey]['by_email'][strtolower($email)] = $info;
            }
            if ($name !== '') {
                $cache[$cacheKey]['by_name'][$name] = $info;
            }
        }

        return $cache[$cacheKey];
    }
}

if (!function_exists('approval_current_leave_people')) {
    function approval_current_leave_people($pdo, $baseDate)
    {
        $index = approval_current_leave_index($pdo, $baseDate);
        return isset($index['people']) && is_array($index['people']) ? $index['people'] : array();
    }
}

if (!function_exists('approval_current_leave_info_for_employee')) {
    function approval_current_leave_info_for_employee($pdo, $employee, $baseDate)
    {
        if (!is_array($employee)) {
            $employee = array('id' => (int)$employee);
        }
        $index = approval_current_leave_index($pdo, $baseDate);
        return approval_current_leave_info_from_index($index, $employee);
    }
}

if (!function_exists('approval_is_employee_on_leave')) {
    function approval_is_employee_on_leave($pdo, $employeeId, $baseDate)
    {
        return is_array(approval_current_leave_info_for_employee($pdo, array('id' => (int)$employeeId), $baseDate));
    }
}

if (!function_exists('approval_auto_delegate_reason_label')) {
    function approval_auto_delegate_reason_label($reasonCode)
    {
        $reasonCode = trim((string)$reasonCode);
        $map = array(
            'higher_position' => approval_ko('%EC%83%81%EC%9C%84%20%EC%A7%81%EA%B8%89%20%EA%B8%B0%EC%95%88%EC%9C%BC%EB%A1%9C%20%EC%9D%B8%ED%95%9C%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'on_leave' => approval_ko('%ED%9C%B4%EA%B0%80%EC%9E%90%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'self' => approval_ko('%EB%B3%B8%EC%9D%B8%20%EA%B2%B0%EC%9E%AC%EB%8B%A8%EA%B3%84%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'previous_step' => approval_ko('%EC%9D%B4%EC%A0%84%20%EB%8B%A8%EA%B3%84%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'vp_leave_ceo_proxy' => approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5%20%ED%9C%B4%EA%B0%80%EB%A1%9C%20%EB%8C%80%ED%91%9C%20%EB%8C%80%EB%A6%AC%20%EA%B2%B0%EC%9E%AC'),
            'leave_ceo_default' => approval_ko('%ED%9C%B4%EA%B0%80%EA%B3%84%20%EB%8C%80%ED%91%9C%20%EB%8B%A8%EA%B3%84%20%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0'),
            'auto' => approval_ko('%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0')
        );
        return isset($map[$reasonCode]) ? $map[$reasonCode] : approval_ko('%EC%9E%90%EB%8F%99%20%EC%A0%84%EA%B2%B0');
    }
}

if (!function_exists('approval_auto_delegate_note')) {
    function approval_auto_delegate_note($line, $reason)
    {
        $role = '';
        $name = '';
        if (is_array($line)) {
            $role = isset($line['role_type']) ? (string)$line['role_type'] : (isset($line['role']) ? (string)$line['role'] : '');
            $name = isset($line['approver_name']) ? (string)$line['approver_name'] : (isset($line['emp']['name']) ? (string)$line['emp']['name'] : '');
        }
        $label = approval_role_label($role);
        $parts = array();
        if ($label !== '-' && $label !== '') {
            $parts[] = $label;
        }
        if (trim($name) !== '') {
            $parts[] = trim($name);
        }
        $prefix = count($parts) > 0 ? implode(' / ', $parts) . ' - ' : '';
        return $prefix . trim((string)$reason);
    }
}

if (!function_exists('approval_line_auto_note')) {
    function approval_line_auto_note($line)
    {
        if (!is_array($line) || !isset($line['reject_reason'])) {
            return '';
        }
        return trim((string)$line['reject_reason']);
    }
}

if (!function_exists('approval_line_is_delegated_status')) {
    function approval_line_is_delegated_status($line)
    {
        if (!is_array($line)) {
            return false;
        }
        $status = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
        return ($status === 'DELEGATED' || (isset($line['is_delegated']) && (int)$line['is_delegated'] === 1));
    }
}

if (!function_exists('approval_insert_auto_delegate_log')) {
    function approval_insert_auto_delegate_log($pdo, $documentId, $lineId, $line, $reason, $actor)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_logs')) {
            return;
        }
        $actorId = 0;
        $actorName = approval_ko('%EC%8B%9C%EC%8A%A4%ED%85%9C');
        $actorEmail = '';
        if (is_array($actor)) {
            if (isset($actor['id'])) {
                $actorId = (int)$actor['id'];
            }
            if (isset($actor['name']) && trim((string)$actor['name']) !== '') {
                $actorName = trim((string)$actor['name']);
            }
            if (isset($actor['email'])) {
                $actorEmail = trim((string)$actor['email']);
            }
        }
        $note = approval_auto_delegate_note($line, $reason);
        try {
            $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:l,:a,:n,:e,'DELEGATED',:m,NOW())")
                ->execute(array(':d' => (int)$documentId, ':l' => ((int)$lineId > 0 ? (int)$lineId : null), ':a' => $actorId, ':n' => $actorName, ':e' => $actorEmail, ':m' => $note));
        } catch (Exception $e) {
        }
    }
}

if (!function_exists('approval_mark_line_delegated')) {
    function approval_mark_line_delegated($pdo, $documentId, $line, $reason, $actor, $delegatedByRole)
    {
        if (!$pdo || !is_array($line) || !isset($line['id']) || (int)$line['id'] <= 0) {
            return;
        }
        $lineId = (int)$line['id'];
        $oldStatus = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
        $sets = array("line_status='DELEGATED'");
        $params = array(':id' => $lineId);
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'acted_at')) {
            $sets[] = 'acted_at=NOW()';
        }
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
            $sets[] = 'is_delegated=1';
        }
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'reject_reason')) {
            $sets[] = 'reject_reason=:reason_note';
            $params[':reason_note'] = trim((string)$reason);
        }
        if ($delegatedByRole !== null && approval_table_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role')) {
            $sets[] = 'delegated_by_role=:delegated_by_role';
            $params[':delegated_by_role'] = $delegatedByRole;
        }
        try {
            $pdo->prepare("UPDATE cpms_approval_lines SET " . implode(',', $sets) . " WHERE id=:id")->execute($params);
            if ($oldStatus !== 'DELEGATED') {
                approval_insert_auto_delegate_log($pdo, $documentId, $lineId, $line, $reason, $actor);
            }
        } catch (Exception $e) {
        }
    }
}

if (!function_exists('approval_document_requires_ceo_for_vp_leave')) {
    function approval_document_requires_ceo_for_vp_leave($pdo, $docType, $lines, $baseDate)
    {
        if (!approval_auto_delegate_target_doc_type($docType) || !is_array($lines)) {
            return false;
        }
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $role = isset($line['role_type']) ? (string)$line['role_type'] : (isset($line['role']) ? (string)$line['role'] : '');
            if (!approval_role_is_vp($role)) {
                continue;
            }
            $status = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
            if (in_array($status, array('APPROVED', 'REJECTED'), true)) {
                continue;
            }
            $employeeId = isset($line['approver_id']) ? (int)$line['approver_id'] : (isset($line['emp']['id']) ? (int)$line['emp']['id'] : 0);
            if (approval_is_employee_on_leave($pdo, $employeeId, $baseDate)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('approval_find_ceo_employee_for_proxy')) {
    function approval_find_ceo_employee_for_proxy($pdo)
    {
        if (!$pdo || !approval_table_exists($pdo, 'employees')) {
            return null;
        }
        if (function_exists('approval_line_rules_find_ceo')) {
            try {
                $ceo = approval_line_rules_find_ceo($pdo);
                if (is_array($ceo) && isset($ceo['id']) && (int)$ceo['id'] > 0) {
                    return $ceo;
                }
            } catch (Exception $e) {
            }
        }

        $roleColumn = approval_table_column_exists($pdo, 'employees', 'role') ? 'role' : "'' AS role";
        $positionColumn = approval_table_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
        $departmentColumn = approval_table_column_exists($pdo, 'employees', 'department') ? 'department' : "'' AS department";
        $select = "id,name,email," . $departmentColumn . "," . $positionColumn . "," . $roleColumn;

        if (approval_table_exists($pdo, 'cpms_approval_settings')) {
            $keys = array('approval_ceo_employee_id', 'ceo_employee_id', 'representative_employee_id');
            for ($i = 0; $i < count($keys); $i++) {
                try {
                    $st = $pdo->prepare("SELECT setting_value FROM cpms_approval_settings WHERE setting_key=:k LIMIT 1");
                    $st->execute(array(':k' => $keys[$i]));
                    $value = trim((string)$st->fetchColumn());
                    if ($value === '' || !is_numeric($value)) {
                        continue;
                    }
                    $empSt = $pdo->prepare("SELECT " . $select . " FROM employees WHERE id=:id AND is_active=1 LIMIT 1");
                    $empSt->execute(array(':id' => (int)$value));
                    $emp = $empSt->fetch(PDO::FETCH_ASSOC);
                    if ($emp) {
                        return $emp;
                    }
                } catch (Exception $e) {
                }
            }
        }

        try {
            $ceoWord = approval_ko('%EB%8C%80%ED%91%9C');
            $conditions = array('name LIKE :ceo_word_name');
            $params = array(':ceo_word_name' => '%' . $ceoWord . '%');
            if (approval_table_column_exists($pdo, 'employees', 'position')) {
                $conditions[] = 'position LIKE :ceo_word_position';
                $params[':ceo_word_position'] = '%' . $ceoWord . '%';
            }
            if (approval_table_column_exists($pdo, 'employees', 'role')) {
                $conditions[] = 'role LIKE :ceo_word_role';
                $conditions[] = "LOWER(role) LIKE '%ceo%'";
                $conditions[] = "LOWER(role) LIKE '%president%'";
                $params[':ceo_word_role'] = '%' . $ceoWord . '%';
            }
            $sql = "SELECT " . $select . " FROM employees WHERE is_active=1 AND (" . implode(' OR ', $conditions) . ") ORDER BY id ASC LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('approval_insert_ceo_line_for_vp_leave')) {
    function approval_insert_ceo_line_for_vp_leave($pdo, $documentId, $lineOrder)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_lines')) {
            return false;
        }
        $ceo = approval_find_ceo_employee_for_proxy($pdo);
        if (!is_array($ceo) || !isset($ceo['id']) || (int)$ceo['id'] <= 0) {
            return false;
        }
        $ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
        $cols = array('document_id', 'line_order', 'role_type', 'approver_id', 'approver_name', 'approver_email', 'line_status');
        $marks = array(':document_id', ':line_order', ':role_type', ':approver_id', ':approver_name', ':approver_email', ':line_status');
        $params = array(
            ':document_id' => (int)$documentId,
            ':line_order' => ((int)$lineOrder > 0 ? (int)$lineOrder : 0) + 1,
            ':role_type' => $ceoRole,
            ':approver_id' => (int)$ceo['id'],
            ':approver_name' => isset($ceo['name']) ? (string)$ceo['name'] : '',
            ':approver_email' => isset($ceo['email']) ? (string)$ceo['email'] : '',
            ':line_status' => 'WAITING'
        );
        if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
            $cols[] = 'is_delegated';
            $marks[] = ':is_delegated';
            $params[':is_delegated'] = 0;
        }
        try {
            $pdo->prepare("INSERT INTO cpms_approval_lines (" . implode(',', $cols) . ") VALUES (" . implode(',', $marks) . ")")->execute($params);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_force_ceo_waiting_for_vp_leave')) {
    function approval_force_ceo_waiting_for_vp_leave($pdo, $documentId, $lines)
    {
        if (!$pdo || (int)$documentId <= 0 || !is_array($lines)) {
            return;
        }
        $hasCeoLine = false;
        $maxOrder = 0;
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (isset($line['line_order']) && (int)$line['line_order'] > $maxOrder) {
                $maxOrder = (int)$line['line_order'];
            }
            if (!isset($line['id']) || !approval_role_is_ceo(isset($line['role_type']) ? $line['role_type'] : '')) {
                continue;
            }
            $hasCeoLine = true;
            $status = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
            if (in_array($status, array('APPROVED', 'REJECTED'), true)) {
                continue;
            }
            $sets = array("line_status='WAITING'");
            $params = array(':id' => (int)$line['id']);
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
                $sets[] = 'is_delegated=0';
            }
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role')) {
                $sets[] = 'delegated_by_role=NULL';
            }
            try {
                $pdo->prepare("UPDATE cpms_approval_lines SET " . implode(',', $sets) . " WHERE id=:id")->execute($params);
            } catch (Exception $e) {
            }
        }
        if (!$hasCeoLine) {
            approval_insert_ceo_line_for_vp_leave($pdo, $documentId, $maxOrder);
        }
    }
}

if (!function_exists('approval_move_to_next_pending_line')) {
    function approval_move_to_next_pending_line($pdo, $docRow, $documentId, $actor)
    {
        $result = array('doc_status' => 'APPROVED', 'step' => 0, 'next_line' => null);
        if (!$pdo || (int)$documentId <= 0) {
            return $result;
        }
        $docType = isset($docRow['doc_type']) ? (string)$docRow['doc_type'] : '';
        $docContent = approval_parse_content(isset($docRow['content']) ? $docRow['content'] : '');
        $forceCeoActualFromContent = (is_array($docContent) && isset($docContent['approval_force_ceo_actual']) && (int)$docContent['approval_force_ceo_actual'] === 1);
        $baseDate = date('Y-m-d');
        $ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
        $vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:d ORDER BY line_order ASC");
            $st->execute(array(':d' => (int)$documentId));
            $lines = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($lines)) {
                $lines = array();
            }
        } catch (Exception $e) {
            $lines = array();
        }
        $limit = count($lines);
        $forceCeo = approval_document_requires_ceo_for_vp_leave($pdo, $docType, $lines, $baseDate);
        if ($forceCeo) {
            approval_force_ceo_waiting_for_vp_leave($pdo, $documentId, $lines);
            try {
                $st = $pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:d ORDER BY line_order ASC");
                $st->execute(array(':d' => (int)$documentId));
                $lines = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($lines)) {
                    $lines = array();
                }
            } catch (Exception $e) {
                $lines = array();
            }
            $limit = count($lines);
        }
        for ($i = 0; $i < count($lines) && $i < $limit; $i++) {
            $line = $lines[$i];
            $status = isset($line['line_status']) ? strtoupper(trim((string)$line['line_status'])) : '';
            if ($status !== 'WAITING') {
                continue;
            }
            $role = isset($line['role_type']) ? (string)$line['role_type'] : '';
            $employeeId = isset($line['approver_id']) ? (int)$line['approver_id'] : 0;
            if (approval_auto_delegate_target_doc_type($docType) && approval_role_is_vp($role) && approval_is_employee_on_leave($pdo, $employeeId, $baseDate)) {
                approval_mark_line_delegated($pdo, $documentId, $line, approval_auto_delegate_reason_label('vp_leave_ceo_proxy'), $actor, $ceoRole);
                continue;
            }
            if ($docType === 'leave' && approval_role_is_ceo($role) && !$forceCeo && !$forceCeoActualFromContent) {
                approval_mark_line_delegated($pdo, $documentId, $line, approval_auto_delegate_reason_label('leave_ceo_default'), $actor, $vpRole);
                continue;
            }
            if (approval_line_is_delegated_status($line)) {
                $note = approval_line_auto_note($line);
                if ($note === '') {
                    $note = approval_auto_delegate_reason_label('auto');
                }
                approval_mark_line_delegated($pdo, $documentId, $line, $note, $actor, isset($line['delegated_by_role']) ? $line['delegated_by_role'] : null);
                continue;
            }
            if (approval_auto_delegate_target_doc_type($docType) && approval_is_employee_on_leave($pdo, $employeeId, $baseDate)) {
                approval_mark_line_delegated($pdo, $documentId, $line, approval_auto_delegate_reason_label('on_leave'), $actor, null);
                continue;
            }
            $sets = array("line_status='PENDING'");
            $params = array(':id' => (int)$line['id']);
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
                $sets[] = 'is_delegated=0';
            }
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role')) {
                $sets[] = 'delegated_by_role=NULL';
            }
            $pdo->prepare("UPDATE cpms_approval_lines SET " . implode(',', $sets) . " WHERE id=:id")->execute($params);
            $line['line_status'] = 'PENDING';
            if (approval_table_column_exists($pdo, 'cpms_approval_lines', 'is_delegated')) {
                $line['is_delegated'] = 0;
            }
            $result['doc_status'] = 'PENDING';
            $result['step'] = isset($line['line_order']) ? (int)$line['line_order'] : 0;
            $result['next_line'] = $line;
            return $result;
        }
        if (count($lines) > 0) {
            $last = $lines[count($lines) - 1];
            $result['step'] = isset($last['line_order']) ? (int)$last['line_order'] : 0;
        }
        return $result;
    }
}

if (!function_exists('approval_current_user_email')) {
    function approval_current_user_email($user)
    {
        if (!is_array($user) || !isset($user['email'])) {
            return '';
        }
        return trim((string)$user['email']);
    }
}

if (!function_exists('approval_current_user_name')) {
    function approval_current_user_name($user)
    {
        if (!is_array($user) || !isset($user['name'])) {
            return '';
        }
        return trim((string)$user['name']);
    }
}

if (!function_exists('approval_table_exists')) {
    function approval_table_exists($pdo, $table)
    {
        if (!$pdo || trim((string)$table) === '') {
            return false;
        }
        try {
            $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($db === '') {
                return false;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl");
            $st->execute(array(':db' => $db, ':tbl' => $table));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_table_column_exists')) {
    function approval_table_column_exists($pdo, $table, $column)
    {
        if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') {
            return false;
        }
        try {
            $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($db === '') {
                return false;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
            $st->execute(array(':db' => $db, ':tbl' => $table, ':col' => $column));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_current_employee_id')) {
    function approval_current_employee_id($pdo, $user)
    {
        if (is_array($user) && isset($user['employee_id']) && (int)$user['employee_id'] > 0) {
            return (int)$user['employee_id'];
        }

        $email = approval_current_user_email($user);
        if ($pdo && $email !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM employees WHERE LOWER(TRIM(email))=LOWER(TRIM(:email)) LIMIT 1");
                $st->execute(array(':email' => $email));
                $id = (int)$st->fetchColumn();
                if ($id > 0) {
                    return $id;
                }
            } catch (Exception $e) {
            }
        }

        $name = approval_current_user_name($user);
        if ($pdo && $name !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM employees WHERE " . approval_sql_normalize_compare_text('name') . "=:name LIMIT 1");
                $st->execute(array(':name' => approval_normalize_compare_text($name)));
                $id = (int)$st->fetchColumn();
                if ($id > 0) {
                    return $id;
                }
            } catch (Exception $e) {
            }
        }

        if (is_array($user) && isset($user['id']) && (int)$user['id'] > 0) {
            return (int)$user['id'];
        }

        return 0;
    }
}

if (!function_exists('approval_current_employee_identity')) {
    function approval_current_employee_identity($pdo, $user)
    {
        $rawName = approval_current_user_name($user);
        $rawEmail = approval_current_user_email($user);
        $rawId = is_array($user) && isset($user['id']) ? (int)$user['id'] : 0;
        $cacheKey = ($pdo && is_object($pdo) ? spl_object_hash($pdo) : 'no-pdo') . '|' . $rawId . '|' . strtolower($rawEmail) . '|' . $rawName;
        static $cache = array();
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $identity = array(
            'id' => approval_current_employee_id($pdo, $user),
            'name' => $rawName,
            'email' => $rawEmail,
            'names' => array(),
            'emails' => array()
        );

        $lineIdentityNames = array();
        $lineIdentityEmails = array();
        if ($pdo) {
            try {
                $row = null;
                if ((int)$identity['id'] > 0) {
                    $st = $pdo->prepare("SELECT id,name,email FROM employees WHERE id=:id LIMIT 1");
                    $st->execute(array(':id' => (int)$identity['id']));
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                }
                if (!is_array($row) && $rawEmail !== '') {
                    $st = $pdo->prepare("SELECT id,name,email FROM employees WHERE LOWER(TRIM(email))=LOWER(TRIM(:email)) LIMIT 1");
                    $st->execute(array(':email' => $rawEmail));
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                }
                if (!is_array($row) && $rawName !== '') {
                    $st = $pdo->prepare("SELECT id,name,email FROM employees WHERE " . approval_sql_normalize_compare_text('name') . "=:name LIMIT 1");
                    $st->execute(array(':name' => approval_normalize_compare_text($rawName)));
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                }
                if (is_array($row)) {
                    $identity['id'] = isset($row['id']) ? (int)$row['id'] : (int)$identity['id'];
                    if (isset($row['name']) && trim((string)$row['name']) !== '') {
                        $identity['name'] = trim((string)$row['name']);
                    }
                    if (isset($row['email']) && trim((string)$row['email']) !== '') {
                        $identity['email'] = trim((string)$row['email']);
                    }
                }
            } catch (Exception $e) {
            }

            try {
                $lineIdentityParts = array();
                $lineIdentityParams = array();
                if ((int)$identity['id'] > 0) {
                    $lineIdentityParts[] = 'approver_id=:identity_line_id';
                    $lineIdentityParams[':identity_line_id'] = (int)$identity['id'];
                }
                $identityEmailCandidates = array($rawEmail, isset($identity['email']) ? $identity['email'] : '');
                $seenIdentityEmails = array();
                for ($i = 0; $i < count($identityEmailCandidates); $i++) {
                    $identityLineEmail = strtolower(trim((string)$identityEmailCandidates[$i]));
                    if ($identityLineEmail === '' || in_array($identityLineEmail, $seenIdentityEmails, true)) {
                        continue;
                    }
                    $seenIdentityEmails[] = $identityLineEmail;
                    $identityLineEmailParam = ':identity_line_email_' . $i;
                    $lineIdentityParts[] = 'LOWER(TRIM(approver_email))=' . $identityLineEmailParam;
                    $lineIdentityParams[$identityLineEmailParam] = $identityLineEmail;
                }
                if (count($lineIdentityParts) > 0) {
                    $lineIdentitySql = "SELECT approver_id,approver_name,approver_email
                                          FROM cpms_approval_lines
                                         WHERE " . implode(' OR ', $lineIdentityParts) . "
                                         ORDER BY id DESC
                                         LIMIT 20";
                    $lineIdentitySt = $pdo->prepare($lineIdentitySql);
                    $lineIdentitySt->execute($lineIdentityParams);
                    $lineIdentityRows = $lineIdentitySt->fetchAll(PDO::FETCH_ASSOC);
                    if (!is_array($lineIdentityRows)) {
                        $lineIdentityRows = array();
                    }
                    for ($i = 0; $i < count($lineIdentityRows); $i++) {
                        $lineIdentityRow = $lineIdentityRows[$i];
                        if ((int)$identity['id'] <= 0 && isset($lineIdentityRow['approver_id']) && (int)$lineIdentityRow['approver_id'] > 0) {
                            $identity['id'] = (int)$lineIdentityRow['approver_id'];
                        }
                        if (isset($lineIdentityRow['approver_name']) && trim((string)$lineIdentityRow['approver_name']) !== '') {
                            $lineIdentityNames[] = trim((string)$lineIdentityRow['approver_name']);
                        }
                        if (isset($lineIdentityRow['approver_email']) && trim((string)$lineIdentityRow['approver_email']) !== '') {
                            $lineIdentityEmails[] = trim((string)$lineIdentityRow['approver_email']);
                        }
                    }
                }
            } catch (Exception $e) {
            }
        }

        $nameCandidates = array_merge(array($rawName, isset($identity['name']) ? $identity['name'] : ''), $lineIdentityNames);
        $expandedNameCandidates = array();
        for ($i = 0; $i < count($nameCandidates); $i++) {
            $originalCandidate = trim((string)$nameCandidates[$i]);
            if ($originalCandidate !== '') {
                $expandedNameCandidates[] = $originalCandidate;
                $baseCandidate = approval_employee_person_name_base($originalCandidate);
                if ($baseCandidate !== '' && approval_normalize_compare_text($originalCandidate) !== $baseCandidate) {
                    $expandedNameCandidates[] = $baseCandidate;
                }
            }
        }
        for ($i = 0; $i < count($expandedNameCandidates); $i++) {
            $candidate = trim((string)$expandedNameCandidates[$i]);
            $candidateKey = approval_normalize_compare_text($candidate);
            if ($candidate === '' || $candidateKey === '') {
                continue;
            }
            $exists = false;
            for ($j = 0; $j < count($identity['names']); $j++) {
                if (approval_normalize_compare_text($identity['names'][$j]) === $candidateKey) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $identity['names'][] = $candidate;
            }
        }

        $emailCandidates = array_merge(array($rawEmail, isset($identity['email']) ? $identity['email'] : ''), $lineIdentityEmails);
        for ($i = 0; $i < count($emailCandidates); $i++) {
            $candidate = strtolower(trim((string)$emailCandidates[$i]));
            if ($candidate !== '' && !in_array($candidate, $identity['emails'], true)) {
                $identity['emails'][] = $candidate;
            }
        }

        $cache[$cacheKey] = $identity;
        return $identity;
    }
}

if (!function_exists('approval_is_document_owner')) {
    function approval_is_document_owner($pdo, $docRow, $user)
    {
        if (!is_array($docRow) || !is_array($user)) {
            return false;
        }

        $identity = approval_current_employee_identity($pdo, $user);
        $uid = approval_current_employee_id($pdo, $user);
        $userName = isset($identity['name']) ? trim((string)$identity['name']) : '';
        $userEmail = isset($identity['email']) ? trim((string)$identity['email']) : '';
        $userNames = array($userName, approval_current_user_name($user));
        $userEmails = array($userEmail, approval_current_user_email($user));

        if ($uid > 0 && isset($docRow['created_by_id']) && (int)$docRow['created_by_id'] === (int)$uid) {
            return true;
        }

        $documentEmails = array();
        if (isset($docRow['created_by_email']) && is_scalar($docRow['created_by_email'])) {
            $documentEmails[] = $docRow['created_by_email'];
        }
        $content = approval_parse_content(isset($docRow['content']) ? $docRow['content'] : '');
        if (!is_array($content)) {
            $content = array();
        }
        $emailFields = array('writer_email', 'applicant_email', 'sender_email', 'creator_email', 'created_by_email');
        for ($i = 0; $i < count($emailFields); $i++) {
            $emailField = $emailFields[$i];
            if (isset($content[$emailField]) && is_scalar($content[$emailField])) {
                $documentEmails[] = $content[$emailField];
            }
        }
        for ($i = 0; $i < count($documentEmails); $i++) {
            $documentEmail = strtolower(trim((string)$documentEmails[$i]));
            if ($documentEmail === '') {
                continue;
            }
            for ($j = 0; $j < count($userEmails); $j++) {
                $identityEmail = strtolower(trim((string)$userEmails[$j]));
                if ($identityEmail !== '' && $documentEmail === $identityEmail) {
                    return true;
                }
            }
        }

        $identityNameKeys = array();
        for ($i = 0; $i < count($userNames); $i++) {
            $identityNameKey = approval_employee_person_name_base($userNames[$i]);
            if ($identityNameKey !== '' && !in_array($identityNameKey, $identityNameKeys, true)) {
                $identityNameKeys[] = $identityNameKey;
            }
        }
        if (count($identityNameKeys) === 0) {
            return false;
        }

        $documentNames = array();
        if (isset($docRow['created_by_name']) && is_scalar($docRow['created_by_name'])) {
            $documentNames[] = $docRow['created_by_name'];
        }
        $nameFields = array('writer_name', 'drafter_name', 'applicant_name', 'sender_name', 'creator_name', 'created_by_name');
        for ($i = 0; $i < count($nameFields); $i++) {
            $nameField = $nameFields[$i];
            if (isset($content[$nameField]) && is_scalar($content[$nameField])) {
                $documentNames[] = $content[$nameField];
            }
        }

        $docType = isset($docRow['doc_type']) ? strtolower(trim((string)$docRow['doc_type'])) : '';
        $hasLeaveApplicantName = isset($content['applicant_name']) && is_scalar($content['applicant_name']) && trim((string)$content['applicant_name']) !== '';
        if ($docType === 'leave' && !$hasLeaveApplicantName && isset($docRow['title']) && is_scalar($docRow['title'])) {
            $leaveTitle = trim((string)$docRow['title']);
            $titleMatches = array();
            if ($leaveTitle !== '' && @preg_match('/-\s*([^-]+)\s*$/u', $leaveTitle, $titleMatches) === 1 && isset($titleMatches[1])) {
                $documentNames[] = trim((string)$titleMatches[1]);
            }
        }

        for ($i = 0; $i < count($documentNames); $i++) {
            $documentNameKey = approval_employee_person_name_base($documentNames[$i]);
            if ($documentNameKey === '') {
                continue;
            }
            for ($j = 0; $j < count($identityNameKeys); $j++) {
                if ($documentNameKey === $identityNameKeys[$j]) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('approval_is_line_approver')) {
    function approval_is_line_approver($pdo, $documentId, $user)
    {
        if (!$pdo || (int)$documentId <= 0) {
            return false;
        }
        $identity = approval_current_employee_identity($pdo, $user);
        $uid = isset($identity['id']) ? (int)$identity['id'] : 0;
        $userEmail = isset($identity['email']) ? trim((string)$identity['email']) : '';
        $userName = isset($identity['name']) ? trim((string)$identity['name']) : '';
        $parts = array();
        $params = array(':document_id' => (int)$documentId);
        if ($uid > 0) {
            $parts[] = 'approver_id = :uid';
            $params[':uid'] = $uid;
        }
        if ($userEmail !== '') {
            $parts[] = 'LOWER(TRIM(approver_email)) = LOWER(TRIM(:email))';
            $params[':email'] = $userEmail;
        }
        if ($userName !== '') {
            $parts[] = 'approver_name = :name';
            $params[':name'] = $userName;
        }
        if (count($parts) === 0) {
            return false;
        }
        try {
            $sql = "SELECT COUNT(*) FROM cpms_approval_lines WHERE document_id=:document_id AND (" . implode(' OR ', $parts) . ")";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_is_document_reference')) {
    function approval_is_document_reference($pdo, $documentId, $user)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_references')) {
            return false;
        }
        $identity = approval_current_employee_identity($pdo, $user);
        $uid = isset($identity['id']) ? (int)$identity['id'] : 0;
        $userEmail = isset($identity['email']) ? trim((string)$identity['email']) : '';
        $userName = isset($identity['name']) ? trim((string)$identity['name']) : '';
        $parts = array();
        $params = array(':document_id' => (int)$documentId);
        if ($uid > 0) {
            $parts[] = 'employee_id = :uid';
            $params[':uid'] = $uid;
        }
        if ($userEmail !== '') {
            $parts[] = 'LOWER(TRIM(employee_email)) = LOWER(TRIM(:email))';
            $params[':email'] = $userEmail;
        }
        if ($userName !== '') {
            $parts[] = 'employee_name = :name';
            $params[':name'] = $userName;
        }
        if (count($parts) === 0) {
            return false;
        }
        try {
            $sql = "SELECT COUNT(*) FROM cpms_approval_references WHERE document_id=:document_id AND (" . implode(' OR ', $parts) . ")";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_is_master_user')) {
    function approval_is_master_user()
    {
        return \App\Core\Auth::isMaster();
    }
}

if (!function_exists('approval_is_admin_user')) {
    function approval_is_admin_user($user)
    {
        return \App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole() === 'executive';
    }
}

if (!function_exists('approval_is_management_department_value')) {
    function approval_is_management_department_value($dept)
    {
        $dept = trim((string)$dept);
        if ($dept === '관리' || $dept === '관리부' || $dept === '관리팀') {
            return true;
        }
        return false;
    }
}

if (!function_exists('approval_is_management_department_user')) {
    function approval_is_management_department_user($pdo, $user)
    {
        if (\App\Core\Auth::isMaster()) {
            return true;
        }
        if (is_array($user) && isset($user['department']) && approval_is_management_department_value($user['department'])) {
            return true;
        }
        if (!$pdo || !is_array($user)) {
            return false;
        }
        try {
            $parts = array();
            $params = array();
            if (isset($user['id']) && (int)$user['id'] > 0) {
                $parts[count($parts)] = 'id=:id';
                $params[':id'] = (int)$user['id'];
            }
            if (isset($user['email']) && trim((string)$user['email']) !== '') {
                $parts[count($parts)] = 'LOWER(TRIM(email))=LOWER(TRIM(:email))';
                $params[':email'] = trim((string)$user['email']);
            }
            if (isset($user['name']) && trim((string)$user['name']) !== '') {
                $parts[count($parts)] = 'name=:name';
                $params[':name'] = trim((string)$user['name']);
            }
            if (count($parts) === 0) {
                return false;
            }
            $sql = "SELECT department FROM employees WHERE " . implode(' OR ', $parts) . " LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $dept = $st->fetchColumn();
            return approval_is_management_department_value($dept);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_is_development_department_value')) {
    function approval_is_development_department_value($dept)
    {
        $dept = trim((string)$dept);
        if ($dept === '개발' || $dept === '개발부' || $dept === '개발팀') {
            return true;
        }
        return false;
    }
}

if (!function_exists('approval_is_development_department_user')) {
    function approval_is_development_department_user($pdo, $user)
    {
        if (is_array($user) && isset($user['department']) && approval_is_development_department_value($user['department'])) {
            return true;
        }
        if (!$pdo || !is_array($user) || !approval_table_exists($pdo, 'employees') || !approval_table_column_exists($pdo, 'employees', 'department')) {
            return false;
        }
        try {
            $parts = array();
            $params = array();
            $employeeId = approval_current_employee_id($pdo, $user);
            $email = approval_current_user_email($user);
            $name = approval_current_user_name($user);

            if ($employeeId > 0) {
                $parts[] = 'id=:id';
                $params[':id'] = $employeeId;
            }
            if ($email !== '') {
                $parts[] = 'LOWER(TRIM(email))=LOWER(TRIM(:email))';
                $params[':email'] = $email;
            }
            if ($name !== '') {
                $parts[] = 'name=:name';
                $params[':name'] = $name;
            }
            if (count($parts) === 0) {
                return false;
            }

            $sql = "SELECT department FROM employees WHERE " . implode(' OR ', $parts) . " LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return approval_is_development_department_value($st->fetchColumn());
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_is_ceo_user')) {
    function approval_is_ceo_user($pdo, $user)
    {
        if (!is_array($user)) {
            return false;
        }

        $employee = array(
            'id' => isset($user['id']) ? (int)$user['id'] : 0,
            'employee_id' => isset($user['employee_id']) ? (int)$user['employee_id'] : 0,
            'name' => isset($user['name']) ? (string)$user['name'] : '',
            'email' => isset($user['email']) ? (string)$user['email'] : '',
            'role' => isset($user['role']) ? (string)$user['role'] : '',
            'position' => isset($user['position']) ? (string)$user['position'] : '',
            'department' => isset($user['department']) ? (string)$user['department'] : ''
        );
        if (approval_employee_is_ceo($employee)) {
            return true;
        }

        if (!$pdo || !approval_table_exists($pdo, 'employees')) {
            return false;
        }

        try {
            $parts = array();
            $params = array();
            $employeeId = approval_current_employee_id($pdo, $user);
            $email = approval_current_user_email($user);
            $name = approval_current_user_name($user);

            if ($employeeId > 0) {
                $parts[] = 'id=:id';
                $params[':id'] = $employeeId;
            }
            if ($email !== '') {
                $parts[] = 'LOWER(TRIM(email))=LOWER(TRIM(:email))';
                $params[':email'] = $email;
            }
            if ($name !== '') {
                $parts[] = 'name=:name';
                $params[':name'] = $name;
            }
            if (count($parts) === 0) {
                return false;
            }

            $positionSelect = approval_table_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
            $roleSelect = approval_table_column_exists($pdo, 'employees', 'role') ? 'role' : "'' AS role";
            $departmentSelect = approval_table_column_exists($pdo, 'employees', 'department') ? 'department' : "'' AS department";
            $sql = "SELECT id, name, email, " . $departmentSelect . ", " . $positionSelect . ", " . $roleSelect . " FROM employees WHERE " . implode(' OR ', $parts) . " LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return approval_employee_is_ceo($row);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_can_view_all_completed_documents')) {
    function approval_can_view_all_completed_documents($pdo, $user)
    {
        return approval_is_management_department_user($pdo, $user) || approval_is_ceo_user($pdo, $user);
    }
}

if (!function_exists('approval_user_can_cancel_approved_leave')) {
    function approval_user_can_cancel_approved_leave($pdo, $user)
    {
        if (!is_array($user)) {
            return false;
        }

        if (approval_is_management_department_user($pdo, $user) || approval_is_development_department_user($pdo, $user)) {
            return true;
        }

        $employee = array(
            'name' => isset($user['name']) ? (string)$user['name'] : '',
            'email' => isset($user['email']) ? (string)$user['email'] : '',
            'position' => isset($user['position']) ? (string)$user['position'] : '',
            'role' => isset($user['role']) ? (string)$user['role'] : '',
            'department' => isset($user['department']) ? (string)$user['department'] : ''
        );
        if (approval_employee_is_vp($employee) || approval_employee_is_ceo($employee)) {
            return true;
        }

        if (!$pdo || !approval_table_exists($pdo, 'employees')) {
            return false;
        }

        try {
            $parts = array();
            $params = array();
            $employeeId = approval_current_employee_id($pdo, $user);
            $email = approval_current_user_email($user);
            $name = approval_current_user_name($user);

            if ($employeeId > 0) {
                $parts[] = 'id=:id';
                $params[':id'] = $employeeId;
            }
            if ($email !== '') {
                $parts[] = 'LOWER(TRIM(email))=LOWER(TRIM(:email))';
                $params[':email'] = $email;
            }
            if ($name !== '') {
                $parts[] = 'name=:name';
                $params[':name'] = $name;
            }
            if (count($parts) === 0) {
                return false;
            }

            $positionSelect = approval_table_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
            $roleSelect = approval_table_column_exists($pdo, 'employees', 'role') ? 'role' : "'' AS role";
            $departmentSelect = approval_table_column_exists($pdo, 'employees', 'department') ? 'department' : "'' AS department";
            $sql = "SELECT id, name, email, " . $departmentSelect . ", " . $positionSelect . ", " . $roleSelect . " FROM employees WHERE " . implode(' OR ', $parts) . " LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $rowDepartment = is_array($row) && isset($row['department']) ? $row['department'] : '';
            return approval_employee_is_vp($row)
                || approval_employee_is_ceo($row)
                || approval_is_management_department_value($rowDepartment)
                || approval_is_development_department_value($rowDepartment);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_can_cancel_approved_leave')) {
    function approval_can_cancel_approved_leave($pdo, $docRow, $user)
    {
        if (!is_array($docRow)) {
            return false;
        }
        $docType = strtolower(trim((string)(isset($docRow['doc_type']) ? $docRow['doc_type'] : '')));
        $status = strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : '')));
        if ($docType !== 'leave' || !in_array($status, array('APPROVED', 'COMPLETED'), true)) {
            return false;
        }
        return approval_user_can_cancel_approved_leave($pdo, $user);
    }
}

if (!function_exists('approval_can_view_all_active_documents')) {
    function approval_can_view_all_active_documents($pdo, $user)
    {
        return approval_is_development_department_user($pdo, $user);
    }
}

if (!function_exists('approval_can_view_document')) {
    function approval_can_view_document($pdo, $docRow, $user)
    {
        if (!is_array($docRow) || !isset($docRow['id'])) {
            return false;
        }
        $status = strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : '')));
        if (approval_can_cancel_approved_leave($pdo, $docRow, $user)) {
            return true;
        }
        if ($status === 'CANCELLED'
            && strtolower(trim((string)(isset($docRow['doc_type']) ? $docRow['doc_type'] : ''))) === 'leave'
            && approval_user_can_cancel_approved_leave($pdo, $user)) {
            return true;
        }
        if (!in_array($status, array('CANCELLED', 'APPROVED', 'COMPLETED'), true) && approval_can_view_all_active_documents($pdo, $user)) {
            return true;
        }
        if (approval_is_management_only_doc_type(isset($docRow['doc_type']) ? $docRow['doc_type'] : '')) {
            if (approval_is_management_department_user($pdo, $user)) {
                return true;
            }
            return (in_array($status, array('APPROVED', 'COMPLETED'), true) && approval_is_ceo_user($pdo, $user));
        }
        if (approval_is_master_user()) {
            return true;
        }
        if (in_array($status, array('APPROVED', 'COMPLETED'), true) && approval_can_view_all_completed_documents($pdo, $user)) {
            return true;
        }
        if (approval_is_document_owner($pdo, $docRow, $user)) {
            return true;
        }
        $documentId = (int)$docRow['id'];
        if (approval_is_line_approver($pdo, $documentId, $user)) {
            return true;
        }
        if (approval_is_document_reference($pdo, $documentId, $user)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('approval_can_cancel_document')) {
    function approval_can_cancel_document($docRow)
    {
        if (!is_array($docRow) || !isset($docRow['doc_status'])) {
            return false;
        }
        $status = strtoupper(trim((string)$docRow['doc_status']));
        return in_array($status, array('PENDING', 'DRAFT'), true);
    }
}

if (!function_exists('approval_can_delete_document')) {
    function approval_can_delete_document($pdo, $docRow, $user)
    {
        if (!is_array($docRow)) {
            return false;
        }
        $status = strtoupper(trim((string)(isset($docRow['doc_status']) ? $docRow['doc_status'] : '')));
        if ($status !== 'CANCELLED') {
            return false;
        }
        if (isset($docRow['id']) && approval_table_exists($pdo, 'cpms_approval_logs')) {
            try {
                $cancelLog = $pdo->prepare("SELECT COUNT(*) FROM cpms_approval_logs WHERE document_id=:id AND action_type='APPROVED_LEAVE_CANCEL'");
                $cancelLog->execute(array(':id' => (int)$docRow['id']));
                if ((int)$cancelLog->fetchColumn() > 0) {
                    return false;
                }
            } catch (Exception $e) {
                return false;
            }
        }
        if (approval_is_document_owner($pdo, $docRow, $user)) {
            return true;
        }
        return approval_is_master_user();
    }
}

if (!function_exists('approval_fetch_references')) {
    function approval_fetch_references($pdo, $documentId)
    {
        if (!$pdo || (int)$documentId <= 0 || !approval_table_exists($pdo, 'cpms_approval_references')) {
            return array();
        }
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_approval_references WHERE document_id=:id ORDER BY employee_name ASC, id ASC");
            $st->execute(array(':id' => (int)$documentId));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }
}

if (!function_exists('approval_document_title_by_view')) {
    function approval_document_title_by_view($view)
    {
        if ($view === 'cancelled') {
            return approval_ko('%EC%B7%A8%EC%86%8C%EB%AC%B8%EC%84%9C');
        }
        if ($view === 'rejected') {
            return approval_ko('%EB%B0%98%EB%A0%A4%EB%AC%B8%EC%84%9C%ED%95%A8');
        }
        if ($view === 'completed') {
            return approval_ko('%EC%99%84%EB%A3%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C');
        }
        return approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%A7%84%ED%96%89%EB%AC%B8%EC%84%9C');
    }
}

if (!function_exists('approval_document_empty_message')) {
    function approval_document_empty_message($view)
    {
        if ($view === 'cancelled') {
            return approval_ko('%EC%B7%A8%EC%86%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        }
        if ($view === 'rejected') {
            return approval_ko('%EB%B0%98%EB%A0%A4%EB%90%9C%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        }
        if ($view === 'completed') {
            return approval_ko('%EC%99%84%EB%A3%8C%EB%90%9C%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        }
        return approval_ko('%EC%A7%84%ED%96%89%EC%A4%91%EC%9D%B8%20%EB%AC%B8%EC%84%9C%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
    }
}
