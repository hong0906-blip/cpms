<?php
require_once __DIR__ . '/template_helpers.php';
require_once __DIR__ . '/../attendance/common.php';
require_once __DIR__ . '/../admin/leave_management_helpers.php';

if (!function_exists('approval_parse_date')) {
    function approval_parse_date($v)
    {
        $v = trim((string)$v);
        if ($v === '') return '';
        $ts = strtotime($v);
        if (!$ts) return '';
        return date('Y-m-d', $ts);
    }
}
if (!function_exists('approval_determine_leave_bucket_by_hire_date')) {
    function approval_determine_leave_bucket_by_hire_date($hireDate, $baseDate)
    {
        if (!$hireDate || !$baseDate) return '';
        return cpms_leave_determine_bucket_by_dates($hireDate, $baseDate);
    }
}
if (!function_exists('approval_get_google_holiday_cache_between')) {
    function approval_get_google_holiday_cache_between($pdo, $startDate, $endDate)
    {
        $rows = array();
        try {
            $st = $pdo->prepare("SELECT holiday_date, holiday_name FROM cpms_holiday_cache WHERE is_active=1 AND source='GOOGLE_CALENDAR' AND holiday_date BETWEEN :s AND :e");
            $st->execute(array(':s'=>$startDate, ':e'=>$endDate));
            foreach ($st->fetchAll() as $r) $rows[(string)$r['holiday_date']] = (string)$r['holiday_name'];
        } catch (Exception $e) {}
        return $rows;
    }
}
if (!function_exists('approval_count_business_leave_days')) {
    function approval_count_business_leave_days($pdo, $startDate, $endDate)
    {
        $s = approval_parse_date($startDate);
        $e = approval_parse_date($endDate);
        if ($s === '' || $e === '') return 0.0;
        if (strtotime($e) < strtotime($s)) return 0.0;
        $holidays = approval_get_google_holiday_cache_between($pdo, $s, $e);
        $cnt = 0.0;
        $cur = strtotime($s);
        $end = strtotime($e);
        while ($cur <= $end) {
            $d = date('Y-m-d', $cur);
            $w = (int)date('N', $cur);
            if ($w !== 6 && $w !== 7 && !isset($holidays[$d])) $cnt += 1.0;
            $cur = strtotime('+1 day', $cur);
        }
        return (float)$cnt;
    }
}
if (!function_exists('approval_deduct_leave_balance_on_final_approval')) {
    function approval_deduct_leave_balance_on_final_approval($pdo, $documentId)
    {
        $ret = array('ok'=>1,'deducted'=>0,'message'=>'');
        $st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
        $st->execute(array(':id'=>(int)$documentId));
        $doc = $st->fetch();
        if (!$doc || $doc['doc_type'] !== 'leave' || $doc['doc_status'] !== 'APPROVED') return $ret;
        $dup = $pdo->prepare("SELECT id FROM cpms_approval_leave_deductions WHERE document_id=:d LIMIT 1");
        $dup->execute(array(':d'=>(int)$documentId));
        if ($dup->fetchColumn()) { $ret['message']='already_deducted'; return $ret; }

        $empSt = $pdo->prepare("SELECT id,hire_date,leave_monthly_balance,leave_annual_balance FROM employees WHERE id=:id LIMIT 1");
        $empSt->execute(array(':id'=>(int)$doc['created_by_id']));
        $emp = $empSt->fetch();
        if (!$emp || trim((string)$emp['hire_date'])==='') {
            $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,actor_name,action_type,action_note,created_at) VALUES (:d,'SYSTEM','LEAVE_DEDUCT_SKIP','입사일이 없어 자동 차감하지 못했습니다.',NOW())")->execute(array(':d'=>(int)$documentId));
            $ret['message'] = 'hire_date_missing';
            return $ret;
        }
        $content = approval_parse_content($doc['content']);
        $rt = trim((string)(isset($content['request_type']) ? $content['request_type'] : ''));
        $baseDate = approval_parse_date(isset($content['leave_start_date'])?$content['leave_start_date']:'');
        if ($baseDate === '') $baseDate = approval_parse_date($doc['created_at']);
        if ($baseDate === '') $baseDate = date('Y-m-d');
        cpms_leave_apply_employee_accruals_until($pdo, (int)$doc['created_by_id'], date('Y-m-d'));
        cpms_leave_normalize_employee_balances($pdo, (int)$doc['created_by_id']);
        $empSt = $pdo->prepare("SELECT id,hire_date,leave_monthly_balance,leave_annual_balance FROM employees WHERE id=:id LIMIT 1");
        $empSt->execute(array(':id'=>(int)$doc['created_by_id']));
        $emp = $empSt->fetch();
        if (!$emp) return $ret;
        $bucket = approval_determine_leave_bucket_by_hire_date($emp['hire_date'], $baseDate);
        if ($bucket === '') return $ret;

        $deduct = 0.0;
        if ($rt === '연차' || $rt === '월차') {
            $deduct = approval_count_business_leave_days($pdo, isset($content['leave_start_date'])?$content['leave_start_date']:'', isset($content['leave_end_date'])?$content['leave_end_date']:'');
        } elseif ($rt === '반차 오전' || $rt === '반차 오후') {
            $deduct = 0.5;
        } else {
            $ret['message'] = 'not_target';
            return $ret;
        }
        $deduct = cpms_leave_normalize_half_step($deduct);
        $targetColumn = ($bucket === 'MONTHLY') ? 'leave_monthly_balance' : 'leave_annual_balance';
        $before = isset($emp[$targetColumn]) && $emp[$targetColumn] !== null ? cpms_leave_normalize_half_step($emp[$targetColumn]) : 0.0;
        $after = cpms_leave_normalize_half_step($before - $deduct);
        $up = $pdo->prepare("UPDATE employees SET {$targetColumn}=:v WHERE id=:id");
        $up->execute(array(':v'=>$after, ':id'=>(int)$emp['id']));
        $note = ($bucket === 'MONTHLY' ? '1년 미만 직원이므로 월차에서 차감되었습니다.' : '1년 이상 직원이므로 연차에서 차감되었습니다.');
        $ins = $pdo->prepare("INSERT INTO cpms_approval_leave_deductions (document_id,employee_id,leave_type,leave_bucket,target_column,deduct_amount,balance_before,balance_after,deducted_at,created_at,note) VALUES (:d,:e,:t,:b,:c,:a,:bf,:af,NOW(),NOW(),:n)");
        $ins->execute(array(':d'=>(int)$documentId, ':e'=>(int)$emp['id'], ':t'=>$rt, ':b'=>$bucket, ':c'=>$targetColumn, ':a'=>$deduct, ':bf'=>$before, ':af'=>$after, ':n'=>$note));
        $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,actor_name,action_type,action_note,created_at) VALUES (:d,'SYSTEM','LEAVE_DEDUCT',:n,NOW())")->execute(array(':d'=>(int)$documentId,':n'=>$note));
        $ret['deducted'] = 1;
        $ret['message'] = $note;
        return $ret;
    }
}

if (!function_exists('approval_restore_leave_balance_on_approved_cancellation')) {
    function approval_restore_leave_balance_on_approved_cancellation($pdo, $documentId, $actor)
    {
        $ret = array('ok' => 1, 'restored' => 0, 'message' => '');
        $documentId = (int)$documentId;
        if (!$pdo || $documentId <= 0) {
            return array('ok' => 0, 'restored' => 0, 'message' => 'invalid_document');
        }

        $docSt = $pdo->prepare("SELECT id,doc_type,doc_status FROM cpms_approval_documents WHERE id=:id LIMIT 1 FOR UPDATE");
        $docSt->execute(array(':id' => $documentId));
        $doc = $docSt->fetch(PDO::FETCH_ASSOC);
        $docType = $doc && isset($doc['doc_type']) ? strtolower(trim((string)$doc['doc_type'])) : '';
        $docStatus = $doc && isset($doc['doc_status']) ? strtoupper(trim((string)$doc['doc_status'])) : '';
        if (!$doc || $docType !== 'leave' || !in_array($docStatus, array('APPROVED', 'COMPLETED'), true)) {
            return array('ok' => 0, 'restored' => 0, 'message' => 'invalid_status');
        }

        if (approval_table_exists($pdo, 'cpms_approval_logs')) {
            $alreadySt = $pdo->prepare("SELECT COUNT(*) FROM cpms_approval_logs WHERE document_id=:id AND action_type='LEAVE_RESTORE'");
            $alreadySt->execute(array(':id' => $documentId));
            if ((int)$alreadySt->fetchColumn() > 0) {
                $ret['message'] = 'already_restored';
                return $ret;
            }
        }

        if (!approval_table_exists($pdo, 'cpms_approval_leave_deductions')) {
            $ret['message'] = approval_ko('%EC%9E%90%EB%8F%99%20%EC%B0%A8%EA%B0%90%20%EA%B8%B0%EB%A1%9D%EC%9D%B4%20%EC%97%86%EC%96%B4%20%EB%B3%B5%EA%B5%AC%ED%95%A0%20%ED%9C%B4%EA%B0%80%20%EC%9E%94%EC%95%A1%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            return $ret;
        }

        $deductSt = $pdo->prepare("SELECT * FROM cpms_approval_leave_deductions WHERE document_id=:id LIMIT 1 FOR UPDATE");
        $deductSt->execute(array(':id' => $documentId));
        $deduction = $deductSt->fetch(PDO::FETCH_ASSOC);
        if (!$deduction) {
            $ret['message'] = approval_ko('%EC%9E%90%EB%8F%99%20%EC%B0%A8%EA%B0%90%20%EA%B8%B0%EB%A1%9D%EC%9D%B4%20%EC%97%86%EC%96%B4%20%EB%B3%B5%EA%B5%AC%ED%95%A0%20%ED%9C%B4%EA%B0%80%20%EC%9E%94%EC%95%A1%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            return $ret;
        }

        $employeeId = isset($deduction['employee_id']) ? (int)$deduction['employee_id'] : 0;
        $targetColumn = isset($deduction['target_column']) ? trim((string)$deduction['target_column']) : '';
        $leaveBucket = isset($deduction['leave_bucket']) ? strtoupper(trim((string)$deduction['leave_bucket'])) : '';
        if (!in_array($targetColumn, array('leave_monthly_balance', 'leave_annual_balance'), true)) {
            if ($leaveBucket === 'MONTHLY') {
                $targetColumn = 'leave_monthly_balance';
            } else if ($leaveBucket === 'ANNUAL') {
                $targetColumn = 'leave_annual_balance';
            }
        }
        if ($employeeId <= 0 || !in_array($targetColumn, array('leave_monthly_balance', 'leave_annual_balance'), true)) {
            return array('ok' => 0, 'restored' => 0, 'message' => 'invalid_deduction');
        }

        $amount = isset($deduction['deduct_amount']) ? cpms_leave_normalize_half_step($deduction['deduct_amount']) : 0.0;
        if ($amount <= 0) {
            $ret['message'] = approval_ko('%EB%B3%B5%EA%B5%AC%ED%95%A0%20%ED%9C%B4%EA%B0%80%20%EC%B0%A8%EA%B0%90%EB%9F%89%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            return $ret;
        }

        $employeeSt = $pdo->prepare("SELECT id," . $targetColumn . " FROM employees WHERE id=:id LIMIT 1 FOR UPDATE");
        $employeeSt->execute(array(':id' => $employeeId));
        $employee = $employeeSt->fetch(PDO::FETCH_ASSOC);
        if (!$employee) {
            return array('ok' => 0, 'restored' => 0, 'message' => 'employee_not_found');
        }

        $before = isset($employee[$targetColumn]) && $employee[$targetColumn] !== null
            ? cpms_leave_normalize_half_step($employee[$targetColumn])
            : 0.0;
        $after = cpms_leave_normalize_half_step($before + $amount);
        $updateSt = $pdo->prepare("UPDATE employees SET " . $targetColumn . "=:balance WHERE id=:id");
        $updateSt->execute(array(':balance' => $after, ':id' => $employeeId));

        $columnLabel = $targetColumn === 'leave_monthly_balance'
            ? approval_ko('%EC%9B%94%EC%B0%A8')
            : approval_ko('%EC%97%B0%EC%B0%A8');
        $note = approval_ko('%EC%8A%B9%EC%9D%B8%20%EC%B7%A8%EC%86%8C%EB%A1%9C%20%EC%B0%A8%EA%B0%90%EB%90%9C%20%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EB%B3%B5%EA%B5%AC%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.')
            . ' ' . $columnLabel . ' '
            . cpms_leave_format_decimal($amount) . approval_ko('%EC%9D%BC')
            . ' (' . cpms_leave_format_decimal($before) . ' -> ' . cpms_leave_format_decimal($after) . ')';

        $actorId = is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0;
        $actorName = is_array($actor) && isset($actor['name']) ? trim((string)$actor['name']) : '';
        $actorEmail = is_array($actor) && isset($actor['email']) ? trim((string)$actor['email']) : '';
        $logSt = $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:a,:n,:e,'LEAVE_RESTORE',:note,NOW())");
        $logSt->execute(array(
            ':d' => $documentId,
            ':a' => $actorId,
            ':n' => $actorName,
            ':e' => $actorEmail,
            ':note' => $note
        ));

        $ret['restored'] = 1;
        $ret['message'] = $note;
        return $ret;
    }
}
