<?php
// bin/export-cpms2-cost-reconciliation.php
// PHP 5.6. Export only final cost rows; never import or initialize source data.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
date_default_timezone_set('Asia/Seoul');
$options=getopt('',array('through-month:','output:','help'));
if(isset($options['help'])){echo "php bin/export-cpms2-cost-reconciliation.php --through-month=2026-09 --output=PRIVATE_CANONICAL.json\n";exit;}
if(!isset($options['through-month'],$options['output'])){fwrite(STDERR,"through-month and output are required.\n");exit(1);}
require_once dirname(__DIR__).'/app/core/Db.php';
require_once dirname(__DIR__).'/app/services/Cpms2CostReconciliationExportService.php';
try{
    $through=Cpms2CostReconciliationExportService::throughMonth($options['through-month']);$pdo=\App\Core\Db::pdo();if(!$pdo)throw new RuntimeException('CPMS1 source connection unavailable.');
    $attendancePdo=cpms_load_attendance_pdo();$attendance=$attendancePdo?new Cpms2ReadOnlySource($attendancePdo):null;
    if($attendancePdo && $attendancePdo!==$pdo)$attendancePdo->beginTransaction();
    $pdo->beginTransaction();
    $data=(new Cpms2CostReconciliationExportService(new Cpms2ReadOnlySource($pdo),$attendance,dirname(__DIR__).'/storage'))->collect($through);$pdo->rollBack();
    if($attendancePdo && $attendancePdo!==$pdo)$attendancePdo->rollBack();
    $output=$options['output'];$parent=realpath(dirname($output));$documentRoot=realpath(dirname(__DIR__).'/public');
    if(!$parent || !$documentRoot || strpos(strtolower(str_replace('\\','/',$parent)).'/',strtolower(str_replace('\\','/',$documentRoot)).'/')===0 || file_exists($output) || is_link($output))throw new RuntimeException('Output must be a new private file outside the document root.');
    $bytes=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);if($bytes===false)throw new RuntimeException('Canonical JSON encoding failed.');
    $handle=fopen($output,'xb');if(!$handle)throw new RuntimeException('Canonical output unavailable.');try{if(fwrite($handle,$bytes)!==strlen($bytes))throw new RuntimeException('Canonical output incomplete.');}finally{fclose($handle);}
    @chmod($output,0600);echo 'ROWS '.count($data['rows'])."\nSHA256 ".hash('sha256',$bytes)."\nMONTHLY TOTAL ".$data['summary']['company']['monthly_total']."\n";
}catch(Exception $e){if(isset($pdo) && $pdo && $pdo->inTransaction())$pdo->rollBack();if(isset($attendancePdo) && $attendancePdo && $attendancePdo->inTransaction())$attendancePdo->rollBack();fwrite(STDERR,($e instanceof RuntimeException && !$e instanceof PDOException?$e->getMessage():'CPMS1 canonical export failed; source was not modified.')."\n");exit(1);}
