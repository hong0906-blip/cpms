<?php
require_once __DIR__ . '/template_helpers.php';
require_once __DIR__ . '/../attendance/common.php';

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
        return attendance_is_under_one_year($hireDate, $baseDate) ? 'MONTHLY' : 'ANNUAL';
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
        $targetColumn = ($bucket === 'MONTHLY') ? 'leave_monthly_balance' : 'leave_annual_balance';
        $before = isset($emp[$targetColumn]) && $emp[$targetColumn] !== null ? (float)$emp[$targetColumn] : 0.0;
        $after = $before - $deduct;
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