<?php
// app/services/Cpms2ManagementMigrationPreflightService.php
require_once __DIR__.'/Cpms2ReadOnlySource.php';
require_once __DIR__.'/Cpms2AttendanceExportService.php';
require_once __DIR__.'/Cpms2OverheadExportService.php';
class Cpms2ManagementMigrationPreflightService
{
    private $source; private $root; private $storage;
    public function __construct($source,$root,$storage) { $this->source=$source; $this->root=$root; $this->storage=$storage; }
    public function inspect($cutoff=null)
    {
        $support=new Cpms2ManagementPreflightSupport();
        $report=array('diagnostic_only'=>true,'checked_at'=>date('Y-m-d H:i:s'),'Attendance'=>array(),'Leave'=>array(),'Overhead'=>array());
        try { $attendance=(new Cpms2AttendanceExportService($this->source,$support))->inspect(); $report=array_merge($report,$attendance); }
        catch (Exception $e) { $support->issue('Attendance','ATTENDANCE_SOURCE_READ_FAILED','BLOCKING'); $support->issue('Leave','LEAVE_SOURCE_READ_FAILED','BLOCKING'); }
        try { $report['Overhead']=(new Cpms2OverheadExportService($this->source,$this->root,$this->storage,$support,$cutoff))->inspect(); }
        catch (Exception $e) { $support->issue('Overhead','OVERHEAD_SOURCE_READ_FAILED','BLOCKING'); }
        $report['issues']=array_values($support->issues); $report['audit']=array(); $report['blocking_count']=0; $report['warning_count']=0;
        foreach (array('Attendance','Leave','Overhead') as $domain) {
            $b=0; $w=0; foreach ($report['issues'] as $issue) if ($issue['domain']===$domain) { if ($issue['severity']==='BLOCKING') $b++; else $w++; }
            $report['audit'][$domain]=array('status'=>$b?'BLOCKING':($w?'WARNING':'READY'),'blocking_count'=>$b,'warning_count'=>$w);
            $report['blocking_count']+=$b; $report['warning_count']+=$w;
        }
        if ($report['audit']['Overhead']['status']==='BLOCKING' && isset($report['Overhead']['total_is_final'])) $report['Overhead']['total_is_final']=false;
        // This report is not stored in a source file or attached to the legacy ZIP/preflight.
        return $report;
    }
}
