<?php
// app/services/Cpms2ExportDiagnostic.php
class Cpms2ExportFailure extends RuntimeException
{
    public $phase;
    public function __construct($phase,$code)
    {
        $phases=array('employees','vendors','workers','direct_team','projects','labor','material','equipment','subcontract','billing','statement_files','summary','zip','safety','completed_approvals');
        $phases[]='attendance';
        $this->phase=in_array($phase,$phases,true)?$phase:'summary'; parent::__construct($code);
    }
    public static function safe($phase,$error)
    {
        if ($error instanceof self) return $error;
        $codes=array('WORKER_ACCOUNT_DECRYPT_FAILED','WORKER_ACCOUNT_HASH_MISMATCH','WORKER_ACCOUNT_MISSING','VENDORS_ACCOUNT_MISSING','DIRECT_TEAM_ACCOUNT_MISSING','EMPLOYEE_ACCOUNT_MISSING','EMPLOYEE_PAYROLL_READ_FAILED','EMPLOYEE_PAYROLL_UNMAPPED','EMPLOYEE_PAYROLL_CONFLICT','ACCOUNT_PREFLIGHT_FAILED','PRIVATE_STORAGE_REQUIRED','ZIP_EXTENSION_REQUIRED','ATTENDANCE_SOURCE_REQUIRED','PREFLIGHT_REQUIRED','EXPORT_ALREADY_RUNNING','PACKAGE_ACCESS_DENIED','PACKAGE_INTEGRITY_FAILED');
        $codes=array_merge($codes,array('WORKER_ACCOUNT_RECOVERY_SOURCE_MISSING','WORKER_ACCOUNT_SNAPSHOT_CONFLICT','SAFETY_STORE_UNREADABLE','SAFETY_STORE_INVALID','SAFETY_ROW_INVALID','SAFETY_AMOUNT_INVALID','SAFETY_FILE_PATH_INVALID','SAFETY_PDF_INVALID','COMPLETED_APPROVAL_PDF_UNAVAILABLE','COMPLETED_APPROVAL_PDF_INVALID'));
        $codes=array_merge($codes,array('legacy_referenced_master_physically_missing','legacy_labor_snapshot_insufficient','legacy_project_snapshot_unavailable','legacy_project_snapshot_ambiguous','legacy_exclusion_scope_changed','legacy_exclusion_amount_invalid'));
        $codes=array_merge($codes,array('LEGACY_ATTENDANCE_DATE_INVALID','LEGACY_ATTENDANCE_ROW_INVALID','LEGACY_ATTENDANCE_SOURCE_SCHEMA_NOT_READY','LEGACY_ATTENDANCE_EMPLOYEE_UNMAPPED','LEGACY_ATTENDANCE_DUPLICATE_DATE','LEGACY_ATTENDANCE_STATUS_UNKNOWN','LEGACY_ATTENDANCE_REQUEST_INVALID','LEGACY_LEAVE_DIRECT_SCOPE_CHANGED','LEGACY_LEAVE_DOCUMENT_CONFLICT','LEGACY_LEAVE_TYPE_INVALID','LEGACY_LEAVE_AMOUNT_INVALID','ORPHAN_LEAVE_EXCLUSION_SCOPE_CHANGED'));
        $code=in_array($error->getMessage(),$codes,true)?$error->getMessage():($error instanceof PDOException?'SOURCE_QUERY_FAILED':'EXPORT_STAGE_FAILED');
        return new self($phase,$code);
    }
    public static function exclusionSection($policy)
    {
        $diagnostic=$policy->diagnostics(); $summary=$policy->summary();
        $diagnostic['material_excluded_amount']=$summary['company_amounts']['material'];
        $diagnostic['equipment_excluded_amount']=$summary['company_amounts']['equipment'];
        $diagnostic['total_excluded_amount']=Cpms2MigrationDecimal::add($diagnostic['material_excluded_amount'],$diagnostic['equipment_excluded_amount']);
        return $diagnostic;
    }
}
