<?php
/*
 * 파일경로: app/views/approval/leave_identity_helpers.php
 * 기능: 휴가계에서 생년월일 대신 임직원 사번(employee_no)을 표시하기 위한 전용 헬퍼
 * PHP 5.6 호환
 */

if (!function_exists('approval_leave_resolve_employee_no')) {
    function approval_leave_resolve_employee_no($data)
    {
        $data = is_array($data) ? $data : array();

        if (isset($data['employee_no']) && trim((string)$data['employee_no']) !== '') {
            return trim((string)$data['employee_no']);
        }

        $email = '';
        if (isset($data['applicant_email']) && trim((string)$data['applicant_email']) !== '') {
            $email = trim((string)$data['applicant_email']);
        } else if (isset($data['writer_email']) && trim((string)$data['writer_email']) !== '') {
            $email = trim((string)$data['writer_email']);
        }
        $name = isset($data['applicant_name']) ? trim((string)$data['applicant_name']) : '';

        if (!class_exists('\\App\\Core\\Db')) {
            return '';
        }

        try {
            $pdo = \App\Core\Db::pdo();
            if (!$pdo) {
                return '';
            }

            if (function_exists('approval_table_column_exists') && !approval_table_column_exists($pdo, 'employees', 'employee_no')) {
                return '';
            }

            if ($email !== '') {
                $st = $pdo->prepare("SELECT employee_no FROM employees WHERE is_active=1 AND LOWER(TRIM(email))=LOWER(TRIM(:email)) LIMIT 1");
                $st->execute(array(':email' => $email));
                $value = trim((string)$st->fetchColumn());
                if ($value !== '') {
                    return $value;
                }
            }

            if ($name !== '') {
                $st = $pdo->prepare("SELECT employee_no FROM employees WHERE is_active=1 AND name=:name LIMIT 1");
                $st->execute(array(':name' => $name));
                $value = trim((string)$st->fetchColumn());
                if ($value !== '') {
                    return $value;
                }
            }
        } catch (Exception $e) {
            return '';
        }

        return '';
    }
}
