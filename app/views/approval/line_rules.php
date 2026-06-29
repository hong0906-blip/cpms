<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/template_helpers.php';

if (!function_exists('approval_line_rules_text_has')) {
    function approval_line_rules_text_has($value, $words)
    {
        $value = approval_normalize_compare_text($value);
        $words = is_array($words) ? $words : array($words);
        for ($i = 0; $i < count($words); $i++) {
            $word = approval_normalize_compare_text($words[$i]);
            if ($word !== '' && strpos($value, $word) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('approval_line_rules_employee_select')) {
    function approval_line_rules_employee_select($pdo)
    {
        $roleColumn = approval_table_column_exists($pdo, 'employees', 'role') ? 'role' : "'' AS role";
        $positionColumn = approval_table_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
        $teamLeaderColumn = approval_table_column_exists($pdo, 'employees', 'is_team_leader') ? 'is_team_leader' : '0 AS is_team_leader';
        $teamLeaderIdColumn = approval_table_column_exists($pdo, 'employees', 'team_leader_id') ? 'team_leader_id' : '0 AS team_leader_id';
        $approvalLeadColumn = approval_table_column_exists($pdo, 'employees', 'approval_can_be_team_leader') ? 'approval_can_be_team_leader' : '0 AS approval_can_be_team_leader';
        return "id,name,email,department," . $positionColumn . "," . $roleColumn . "," . $teamLeaderColumn . "," . $teamLeaderIdColumn . "," . $approvalLeadColumn;
    }
}

if (!function_exists('approval_line_rules_fetch_employee')) {
    function approval_line_rules_fetch_employee($pdo, $id)
    {
        if (!$pdo || (int)$id <= 0 || !approval_table_exists($pdo, 'employees')) {
            return null;
        }
        try {
            $st = $pdo->prepare("SELECT " . approval_line_rules_employee_select($pdo) . " FROM employees WHERE id=:id AND is_active=1 LIMIT 1");
            $st->execute(array(':id' => (int)$id));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('approval_line_rules_current_employee')) {
    function approval_line_rules_current_employee($pdo, $user)
    {
        $employeeId = approval_current_employee_id($pdo, $user);
        $employee = approval_line_rules_fetch_employee($pdo, $employeeId);
        if (!is_array($employee)) {
            $employee = array(
                'id' => $employeeId,
                'name' => approval_current_user_name($user),
                'email' => approval_current_user_email($user)
            );
        }
        if (is_array($user)) {
            $keys = array('role', 'position', 'department');
            for ($i = 0; $i < count($keys); $i++) {
                if (!isset($employee[$keys[$i]]) && isset($user[$keys[$i]])) {
                    $employee[$keys[$i]] = $user[$keys[$i]];
                }
            }
        }
        return $employee;
    }
}

if (!function_exists('approval_line_rules_all_employees')) {
    function approval_line_rules_all_employees($pdo)
    {
        if (!$pdo || !approval_table_exists($pdo, 'employees')) {
            return array();
        }
        try {
            $st = $pdo->query("SELECT " . approval_line_rules_employee_select($pdo) . " FROM employees WHERE is_active=1 ORDER BY name ASC, id ASC");
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }
}

if (!function_exists('approval_line_rules_department_key')) {
    function approval_line_rules_department_key($department)
    {
        $dept = function_exists('approval_norm_dept') ? approval_norm_dept($department) : trim((string)$department);
        $norm = approval_normalize_compare_text($dept);
        $map = array(
            'gongmu' => approval_ko('%EA%B3%B5%EB%AC%B4'),
            'manage' => approval_ko('%EA%B4%80%EB%A6%AC'),
            'construction' => approval_ko('%EA%B3%B5%EC%82%AC'),
            'safety' => approval_ko('%EC%95%88%EC%A0%84'),
            'health' => approval_ko('%EB%B3%B4%EA%B1%B4'),
            'quality' => approval_ko('%ED%92%88%EC%A7%88')
        );
        foreach ($map as $key => $label) {
            if ($norm === approval_normalize_compare_text($label)) {
                return $key;
            }
        }
        return $norm;
    }
}

if (!function_exists('approval_line_rules_team_department_key')) {
    function approval_line_rules_team_department_key($department)
    {
        $key = approval_line_rules_department_key($department);
        if ($key === 'safety' || $key === 'health') {
            return 'quality';
        }
        return $key;
    }
}

if (!function_exists('approval_line_rules_employee_department_matches')) {
    function approval_line_rules_employee_department_matches($employee, $targetKey)
    {
        if (!is_array($employee)) {
            return false;
        }
        $dept = isset($employee['department']) ? $employee['department'] : '';
        return approval_line_rules_department_key($dept) === $targetKey;
    }
}

if (!function_exists('approval_line_rules_is_marked_team_leader')) {
    function approval_line_rules_is_marked_team_leader($employee)
    {
        return (is_array($employee) && isset($employee['is_team_leader']) && (int)$employee['is_team_leader'] === 1);
    }
}

if (!function_exists('approval_line_rules_is_team_leader_candidate')) {
    function approval_line_rules_is_team_leader_candidate($employee)
    {
        if (!is_array($employee)) {
            return false;
        }
        if (approval_line_rules_is_marked_team_leader($employee)) {
            return true;
        }
        if (isset($employee['approval_can_be_team_leader']) && (int)$employee['approval_can_be_team_leader'] === 1) {
            return true;
        }
        return approval_line_rules_text_has(isset($employee['position']) ? $employee['position'] : '', approval_ko('%ED%8C%80%EC%9E%A5'));
    }
}

if (!function_exists('approval_line_rules_requires_manual_team_leader')) {
    function approval_line_rules_requires_manual_team_leader($employee)
    {
        if (!is_array($employee) || approval_line_rules_is_marked_team_leader($employee) || approval_employee_is_executive($employee)) {
            return false;
        }
        return approval_line_rules_department_key(isset($employee['department']) ? $employee['department'] : '') === 'construction';
    }
}

if (!function_exists('approval_line_rules_team_leader_candidates')) {
    function approval_line_rules_team_leader_candidates($pdo, $targetKey, $creatorId)
    {
        $result = array();
        $seen = array();
        $employees = approval_line_rules_all_employees($pdo);
        $targetKey = trim((string)$targetKey);
        $creatorId = (int)$creatorId;
        for ($i = 0; $i < count($employees); $i++) {
            $employee = $employees[$i];
            $id = isset($employee['id']) ? (int)$employee['id'] : 0;
            if ($id <= 0 || $id === $creatorId || isset($seen[$id]) || !approval_line_rules_is_team_leader_candidate($employee)) {
                continue;
            }
            if (!approval_line_rules_employee_department_matches($employee, $targetKey)) {
                continue;
            }
            $seen[$id] = 1;
            $result[] = $employee;
        }
        return $result;
    }
}

if (!function_exists('approval_line_rules_find_team_leader')) {
    function approval_line_rules_find_team_leader($pdo, $creator)
    {
        $result = array('employee' => null, 'messages' => array());
        if (!$pdo || !is_array($creator)) {
            return $result;
        }
        $creatorId = isset($creator['id']) ? (int)$creator['id'] : 0;
        $teamLeaderId = isset($creator['team_leader_id']) ? (int)$creator['team_leader_id'] : 0;
        $creatorDeptKey = approval_line_rules_department_key(isset($creator['department']) ? $creator['department'] : '');
        $targetKey = approval_line_rules_team_department_key(isset($creator['department']) ? $creator['department'] : '');
        if ($teamLeaderId > 0 && $teamLeaderId !== $creatorId) {
            $selected = approval_line_rules_fetch_employee($pdo, $teamLeaderId);
            if ($selected && approval_line_rules_is_team_leader_candidate($selected) && approval_line_rules_employee_department_matches($selected, $targetKey)) {
                $result['employee'] = $selected;
                return $result;
            }
            if ($creatorDeptKey === 'construction') {
                $result['messages'][] = approval_ko('%EA%B3%B5%EC%82%AC%20%EC%9D%B8%EC%9B%90%EC%9D%80%20%EC%9E%91%EC%84%B1%20%ED%99%94%EB%A9%B4%EC%97%90%EC%84%9C%20%ED%98%84%EC%9E%A5%20%ED%8C%80%EC%9E%A5%EC%9D%84%20%EC%84%A0%ED%83%9D%ED%95%B4%20%EC%A3%BC%EC%84%B8%EC%9A%94.');
                return $result;
            }
        } else if ($creatorDeptKey === 'construction') {
            $result['messages'][] = approval_ko('%EA%B3%B5%EC%82%AC%20%EC%9D%B8%EC%9B%90%EC%9D%80%20%EC%9E%91%EC%84%B1%20%ED%99%94%EB%A9%B4%EC%97%90%EC%84%9C%20%ED%98%84%EC%9E%A5%20%ED%8C%80%EC%9E%A5%EC%9D%84%20%EC%84%A0%ED%83%9D%ED%95%B4%20%EC%A3%BC%EC%84%B8%EC%9A%94.');
            return $result;
        }

        $employees = approval_line_rules_all_employees($pdo);
        for ($pass = 1; $pass <= 3; $pass++) {
            for ($i = 0; $i < count($employees); $i++) {
                $employee = $employees[$i];
                $id = isset($employee['id']) ? (int)$employee['id'] : 0;
                if ($id <= 0 || $id === $creatorId || !approval_line_rules_employee_department_matches($employee, $targetKey)) {
                    continue;
                }
                if ($pass === 1 && approval_line_rules_is_marked_team_leader($employee)) {
                    $result['employee'] = $employee;
                    return $result;
                }
                if ($pass === 2 && isset($employee['approval_can_be_team_leader']) && (int)$employee['approval_can_be_team_leader'] === 1) {
                    $result['employee'] = $employee;
                    return $result;
                }
                if ($pass === 3 && approval_line_rules_text_has(isset($employee['position']) ? $employee['position'] : '', approval_ko('%ED%8C%80%EC%9E%A5'))) {
                    $result['employee'] = $employee;
                    return $result;
                }
            }
        }
        $result['messages'][] = approval_ko('%ED%8C%80%EC%9E%A5%20%EC%84%A4%EC%A0%95%EC%9D%B4%20%ED%95%84%EC%9A%94%ED%95%A9%EB%8B%88%EB%8B%A4.');
        return $result;
    }
}

if (!function_exists('approval_line_rules_setting_employee')) {
    function approval_line_rules_setting_employee($pdo, $keys)
    {
        if (!$pdo || !approval_table_exists($pdo, 'cpms_approval_settings')) {
            return null;
        }
        $keys = is_array($keys) ? $keys : array($keys);
        for ($i = 0; $i < count($keys); $i++) {
            try {
                $st = $pdo->prepare("SELECT setting_value FROM cpms_approval_settings WHERE setting_key=:k LIMIT 1");
                $st->execute(array(':k' => $keys[$i]));
                $value = trim((string)$st->fetchColumn());
                if ($value === '') {
                    continue;
                }
                if (is_numeric($value)) {
                    $employee = approval_line_rules_fetch_employee($pdo, (int)$value);
                    if ($employee) {
                        return $employee;
                    }
                }
            } catch (Exception $e) {
            }
        }
        return null;
    }
}

if (!function_exists('approval_line_rules_find_by_position')) {
    function approval_line_rules_find_by_position($pdo, $positions)
    {
        $employees = approval_line_rules_all_employees($pdo);
        $positions = is_array($positions) ? $positions : array($positions);
        for ($i = 0; $i < count($employees); $i++) {
            $pos = isset($employees[$i]['position']) ? trim((string)$employees[$i]['position']) : '';
            for ($j = 0; $j < count($positions); $j++) {
                if ($pos === $positions[$j]) {
                    return $employees[$i];
                }
            }
        }
        return null;
    }
}

if (!function_exists('approval_line_rules_find_ceo')) {
    function approval_line_rules_find_ceo($pdo)
    {
        $employee = approval_line_rules_setting_employee($pdo, array('approval_ceo_employee_id', 'ceo_employee_id', 'representative_employee_id'));
        if ($employee) {
            return $employee;
        }
        return approval_line_rules_find_by_position($pdo, array(approval_ko('%EB%8C%80%ED%91%9C'), approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC')));
    }
}

if (!function_exists('approval_line_rules_find_vp')) {
    function approval_line_rules_find_vp($pdo)
    {
        $employee = approval_line_rules_setting_employee($pdo, array('approval_vp_employee_id', 'vp_employee_id', 'vice_president_employee_id'));
        if ($employee) {
            return $employee;
        }
        return approval_line_rules_find_by_position($pdo, array(approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5')));
    }
}

if (!function_exists('approval_line_rules_find_construction_pm')) {
    function approval_line_rules_find_construction_pm($pdo)
    {
        $employee = approval_line_rules_setting_employee($pdo, array('approval_construction_pm_employee_id', 'construction_pm_employee_id', 'approval_park_pm_employee_id'));
        if ($employee) {
            return $employee;
        }
        $employees = approval_line_rules_all_employees($pdo);
        $park = approval_ko('%EB%B0%95%EC%9B%90%EB%8D%95');
        $sangmu = approval_ko('%EC%83%81%EB%AC%B4');
        for ($pass = 1; $pass <= 2; $pass++) {
            for ($i = 0; $i < count($employees); $i++) {
                $name = isset($employees[$i]['name']) ? trim((string)$employees[$i]['name']) : '';
                $pos = isset($employees[$i]['position']) ? trim((string)$employees[$i]['position']) : '';
                if ($name !== $park) {
                    continue;
                }
                if ($pass === 1 && $pos === $sangmu) {
                    return $employees[$i];
                }
                if ($pass === 2) {
                    return $employees[$i];
                }
            }
        }
        return null;
    }
}

if (!function_exists('approval_line_rules_employee_is_ceo')) {
    function approval_line_rules_employee_is_ceo($employee)
    {
        if (!is_array($employee)) {
            return false;
        }
        $blob = approval_employee_text_blob($employee);
        return approval_line_rules_text_has($blob, array(approval_ko('%EB%8C%80%ED%91%9C'), approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC'), 'ceo', 'president'));
    }
}

if (!function_exists('approval_line_rules_employee_on_leave')) {
    function approval_line_rules_employee_on_leave($pdo, $employee, $baseDate)
    {
        if (!$pdo || !is_array($employee)) {
            return false;
        }
        return is_array(approval_current_leave_info_for_employee($pdo, $employee, $baseDate));
    }
}

if (!function_exists('approval_line_rules_line_key')) {
    function approval_line_rules_line_key($employee)
    {
        if (!is_array($employee)) {
            return '';
        }
        if (isset($employee['id']) && (int)$employee['id'] > 0) {
            return 'id:' . (int)$employee['id'];
        }
        if (isset($employee['email']) && trim((string)$employee['email']) !== '') {
            return 'email:' . strtolower(trim((string)$employee['email']));
        }
        if (isset($employee['name']) && trim((string)$employee['name']) !== '') {
            return 'name:' . trim((string)$employee['name']);
        }
        return '';
    }
}

if (!function_exists('approval_line_rules_add_line')) {
    function approval_line_rules_add_line(&$lines, &$seen, $role, $employee, $options)
    {
        if (!is_array($employee)) {
            return false;
        }
        $key = approval_line_rules_line_key($employee);
        if ($key !== '' && isset($seen[$key])) {
            return false;
        }
        if ($key !== '') {
            $seen[$key] = 1;
        }
        $line = array(
            'role' => $role,
            'emp' => $employee,
            'delegated' => 0
        );
        $options = is_array($options) ? $options : array();
        foreach ($options as $k => $v) {
            $line[$k] = $v;
        }
        $lines[] = $line;
        return true;
    }
}

if (!function_exists('approval_line_rules_line_names')) {
    function approval_line_rules_line_names($lines)
    {
        $names = array();
        $lines = is_array($lines) ? $lines : array();
        for ($i = 0; $i < count($lines); $i++) {
            if (!isset($lines[$i]['emp']) || !is_array($lines[$i]['emp'])) {
                continue;
            }
            $name = isset($lines[$i]['emp']['name']) ? trim((string)$lines[$i]['emp']['name']) : '';
            if ($name === '') {
                $name = isset($lines[$i]['emp']['email']) ? trim((string)$lines[$i]['emp']['email']) : '';
            }
            if ($name !== '') {
                $names[] = approval_role_label(isset($lines[$i]['role']) ? $lines[$i]['role'] : '') . ' ' . $name;
            }
        }
        return implode(' -> ', $names);
    }
}

if (!function_exists('approval_line_rules_build')) {
    function approval_line_rules_build($pdo, $docType, $creatorEmployee, $contentData)
    {
        $docType = strtolower(trim((string)$docType));
        $creatorEmployee = is_array($creatorEmployee) ? $creatorEmployee : array();
        $contentData = is_array($contentData) ? $contentData : array();
        $baseDate = date('Y-m-d');
        $teamRole = approval_ko('%ED%8C%80%EC%9E%A5');
        $vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');
        $ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
        $pmRole = 'PM';
        $lines = array();
        $seen = array();
        $messages = array();
        $warnings = array();
        $forceCeoActual = false;

        $vp = approval_line_rules_find_vp($pdo);
        $ceo = approval_line_rules_find_ceo($pdo);
        $constructionPm = approval_line_rules_find_construction_pm($pdo);
        $isCeo = approval_line_rules_employee_is_ceo($creatorEmployee);
        $isVp = approval_employee_is_vp($creatorEmployee);
        $isExecutive = approval_employee_is_executive($creatorEmployee);
        $isTeamLeader = approval_line_rules_is_marked_team_leader($creatorEmployee);
        $deptKey = approval_line_rules_department_key(isset($creatorEmployee['department']) ? $creatorEmployee['department'] : '');

        if ($docType !== 'leave' && $docType !== 'proposal') {
            return array('lines' => $lines, 'messages' => $messages, 'warnings' => $warnings, 'vp' => $vp, 'ceo' => $ceo, 'construction_pm' => $constructionPm, 'team_lead' => null, 'force_ceo_actual' => 0);
        }

        if ($isCeo) {
            $target = $ceo ? $ceo : $creatorEmployee;
            approval_line_rules_add_line($lines, $seen, $ceoRole, $target, array('allow_self_approval' => 1, 'skip_auto_delegate' => 1));
            $messages[] = approval_ko('%EB%8C%80%ED%91%9C%EA%B0%80%20%EC%9E%91%EC%84%B1%ED%95%9C%20%EA%B2%B0%EC%9E%AC%EB%8A%94%20%EB%8C%80%ED%91%9C%20%EB%B3%B8%EC%9D%B8%20%EA%B2%B0%EC%9E%AC%EB%A1%9C%20%EC%83%9D%EC%84%B1%EB%90%A9%EB%8B%88%EB%8B%A4.');
            $forceCeoActual = true;
            return array('lines' => $lines, 'messages' => $messages, 'warnings' => $warnings, 'vp' => $vp, 'ceo' => $target, 'construction_pm' => $constructionPm, 'team_lead' => null, 'force_ceo_actual' => $forceCeoActual ? 1 : 0);
        }

        if ($isVp) {
            if ($ceo) {
                approval_line_rules_add_line($lines, $seen, $ceoRole, $ceo, array('skip_auto_delegate' => 1));
                $forceCeoActual = true;
            } else {
                $warnings[] = approval_ko('%EB%8C%80%ED%91%9C%20%EC%84%A4%EC%A0%95%EC%9D%B4%20%ED%95%84%EC%9A%94%ED%95%A9%EB%8B%88%EB%8B%A4.');
            }
            return array('lines' => $lines, 'messages' => $messages, 'warnings' => $warnings, 'vp' => $vp, 'ceo' => $ceo, 'construction_pm' => $constructionPm, 'team_lead' => null, 'force_ceo_actual' => $forceCeoActual ? 1 : 0);
        }

        if ($isExecutive) {
            $hadLeaveDelegation = false;
            if ($vp) {
                if (approval_line_rules_employee_on_leave($pdo, $vp, $baseDate)) {
                    approval_line_rules_add_line($lines, $seen, $vpRole, $vp, array('delegated' => 1, 'status' => 'DELEGATED', 'auto_reason' => approval_ko('%ED%9C%B4%EA%B0%80%EB%A1%9C%20%EC%9D%B8%ED%95%9C%20%EC%A0%84%EA%B2%B0%20%EC%B2%98%EB%A6%AC')));
                    $hadLeaveDelegation = true;
                } else {
                    approval_line_rules_add_line($lines, $seen, $vpRole, $vp, array());
                }
            } else {
                $warnings[] = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5%20%EC%84%A4%EC%A0%95%EC%9D%B4%20%ED%95%84%EC%9A%94%ED%95%A9%EB%8B%88%EB%8B%A4.');
            }
            if ($ceo) {
                if (approval_line_rules_employee_on_leave($pdo, $ceo, $baseDate)) {
                    approval_line_rules_add_line($lines, $seen, $ceoRole, $ceo, array('delegated' => 1, 'status' => 'DELEGATED', 'auto_reason' => approval_ko('%ED%9C%B4%EA%B0%80%EB%A1%9C%20%EC%9D%B8%ED%95%9C%20%EC%A0%84%EA%B2%B0%20%EC%B2%98%EB%A6%AC')));
                    $hadLeaveDelegation = true;
                } else {
                    approval_line_rules_add_line($lines, $seen, $ceoRole, $ceo, array());
                    $forceCeoActual = true;
                }
            } else {
                $warnings[] = approval_ko('%EB%8C%80%ED%91%9C%20%EC%84%A4%EC%A0%95%EC%9D%B4%20%ED%95%84%EC%9A%94%ED%95%A9%EB%8B%88%EB%8B%A4.');
            }
            if ($hadLeaveDelegation) {
                $messages[] = approval_ko('%ED%9C%B4%EA%B0%80%20%EC%83%81%ED%83%9C%20%EA%B2%B0%EC%9E%AC%EC%9E%90%EA%B0%80%20%EC%9E%88%EC%96%B4%20%EC%A0%84%EA%B2%B0%20%EC%B2%98%EB%A6%AC%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            }
            return array('lines' => $lines, 'messages' => $messages, 'warnings' => $warnings, 'vp' => $vp, 'ceo' => $ceo, 'construction_pm' => $constructionPm, 'team_lead' => null, 'force_ceo_actual' => $forceCeoActual ? 1 : 0);
        }

        $teamLead = null;
        if ($isTeamLeader) {
            $messages[] = approval_ko('%EC%9E%91%EC%84%B1%EC%9E%90%EA%B0%80%20%ED%8C%80%EC%9E%A5%EC%9C%BC%EB%A1%9C%20%EC%A7%80%EC%A0%95%EB%90%98%EC%96%B4%20%ED%8C%80%EC%9E%A5%20%EA%B2%B0%EC%9E%AC%20%EB%8B%A8%EA%B3%84%EB%A5%BC%20%EA%B1%B4%EB%84%88%EB%9B%B0%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        } else {
            $teamResult = approval_line_rules_find_team_leader($pdo, $creatorEmployee);
            $teamLead = isset($teamResult['employee']) ? $teamResult['employee'] : null;
            if ($teamLead) {
                approval_line_rules_add_line($lines, $seen, $teamRole, $teamLead, array());
            } else if (isset($teamResult['messages']) && is_array($teamResult['messages'])) {
                $warnings = array_merge($warnings, $teamResult['messages']);
            }
        }

        if ($deptKey === 'construction' || $deptKey === 'safety' || $deptKey === 'health' || $deptKey === 'quality') {
            if ($constructionPm) {
                approval_line_rules_add_line($lines, $seen, $pmRole, $constructionPm, array());
            } else {
                $warnings[] = approval_ko('%EB%B0%95%EC%9B%90%EB%8D%95%20%EC%83%81%EB%AC%B4%20%EC%84%A4%EC%A0%95%EC%9D%B4%20%ED%95%84%EC%9A%94%ED%95%A9%EB%8B%88%EB%8B%A4.');
            }
        }

        if ($vp) {
            if (approval_line_rules_employee_on_leave($pdo, $vp, $baseDate)) {
                if ($ceo) {
                    approval_line_rules_add_line($lines, $seen, $ceoRole, $ceo, array('force_actual' => 1));
                    $forceCeoActual = true;
                    $messages[] = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5%20%ED%9C%B4%EA%B0%80%20%EC%83%81%ED%83%9C%EB%A1%9C%20%EB%8C%80%ED%91%9C%20%EA%B2%B0%EC%9E%AC%EB%A1%9C%20%EB%8C%80%EC%B2%B4%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
                } else {
                    $warnings[] = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5%EC%9D%B4%20%ED%9C%B4%EA%B0%80%EC%9D%B4%EC%A7%80%EB%A7%8C%20%EB%8C%80%ED%91%9C%20%EC%84%A4%EC%A0%95%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
                }
            } else {
                approval_line_rules_add_line($lines, $seen, $vpRole, $vp, array());
            }
        } else {
            $warnings[] = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5%20%EC%84%A4%EC%A0%95%EC%9D%B4%20%ED%95%84%EC%9A%94%ED%95%A9%EB%8B%88%EB%8B%A4.');
        }

        if ($docType === 'proposal') {
            if ($ceo) {
                approval_line_rules_add_line($lines, $seen, $ceoRole, $ceo, array());
                $forceCeoActual = true;
            } else {
                $warnings[] = approval_ko('%EA%B8%B0%EC%95%88%EC%84%9C%20%EB%A7%88%EC%A7%80%EB%A7%89%20%EB%8C%80%ED%91%9C%20%EA%B2%B0%EC%9E%AC%EC%9E%90%20%EC%84%A4%EC%A0%95%EC%9D%B4%20%ED%95%84%EC%9A%94%ED%95%A9%EB%8B%88%EB%8B%A4.');
            }
        }

        return array('lines' => $lines, 'messages' => $messages, 'warnings' => $warnings, 'vp' => $vp, 'ceo' => $ceo, 'construction_pm' => $constructionPm, 'team_lead' => $teamLead, 'force_ceo_actual' => $forceCeoActual ? 1 : 0);
    }
}

if (!function_exists('approval_line_rules_to_template_lines')) {
    function approval_line_rules_to_template_lines($lines)
    {
        $result = array();
        $lines = is_array($lines) ? $lines : array();
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $emp = isset($line['emp']) && is_array($line['emp']) ? $line['emp'] : array();
            $status = isset($line['status']) ? $line['status'] : (isset($line['line_status']) ? $line['line_status'] : 'WAITING');
            $result[] = array(
                'line_order' => $i + 1,
                'role_type' => isset($line['role']) ? $line['role'] : (isset($line['role_type']) ? $line['role_type'] : ''),
                'approver_id' => isset($emp['id']) ? (int)$emp['id'] : (isset($line['approver_id']) ? (int)$line['approver_id'] : 0),
                'approver_name' => isset($emp['name']) ? $emp['name'] : (isset($line['approver_name']) ? $line['approver_name'] : ''),
                'approver_email' => isset($emp['email']) ? $emp['email'] : (isset($line['approver_email']) ? $line['approver_email'] : ''),
                'line_status' => $status,
                'is_delegated' => isset($line['delegated']) ? (int)$line['delegated'] : (isset($line['is_delegated']) ? (int)$line['is_delegated'] : 0),
                'reject_reason' => isset($line['auto_reason']) ? $line['auto_reason'] : (isset($line['reject_reason']) ? $line['reject_reason'] : '')
            );
        }
        return $result;
    }
}
