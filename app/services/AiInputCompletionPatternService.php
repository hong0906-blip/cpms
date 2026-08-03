<?php
/**
 * Historical input-completion pattern analysis for forecast V2.
 * PHP 5.6 / MySQL 5.6 compatible. This service reads snapshots/events only.
 */
namespace App\Services;

use App\Core\Db;
use PDO;
use Exception;

require_once __DIR__ . '/CostChangeService.php';

class AiInputCompletionPatternService
{
    const TABLE_NAME = 'cpms_ai_input_completion_patterns';
    const SNAPSHOT_TABLE = 'cpms_ai_daily_snapshots';
    const EVENT_TABLE = 'cpms_cost_data_events';
    const CALCULATION_VERSION = 'INPUT_PATTERN_V2';
    const DEFAULT_GRACE_DAYS = 0;
    const DEFAULT_MIN_COMPLETION_RATE = 20;

    private static $tableCache = array();

    public static function pdo($pdo = null)
    {
        return $pdo ? $pdo : Db::pdo();
    }

    public static function businessToday()
    {
        return CostChangeService::businessToday();
    }

    public static function validYm($value)
    {
        return CostChangeService::validYm($value);
    }

    private static function connectionKey($pdo)
    {
        return is_object($pdo) ? spl_object_hash($pdo) : 'none';
    }

    public static function tableExists($pdo, $table)
    {
        if (!$pdo || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) return false;
        $key = self::connectionKey($pdo) . ':' . $table;
        if (array_key_exists($key, self::$tableCache)) return self::$tableCache[$key];
        try {
            $st = $pdo->prepare('SHOW TABLES LIKE :table_name');
            $ok = $st && $st->execute(array(':table_name' => $table)) && $st->fetchColumn() !== false;
            self::$tableCache[$key] = $ok;
            return $ok;
        } catch (Exception $e) {
            self::$tableCache[$key] = false;
            return false;
        }
    }

    public static function categories()
    {
        return array(
            'labor' => 'labor_amount',
            'outsourcing' => 'outsourcing_amount',
            'purchase' => 'purchase_amount',
            'material' => 'material_amount',
            'equipment' => 'equipment_amount',
            'other_expense' => 'other_expense_amount',
            'safety' => 'safety_amount',
            'health' => 'health_amount',
            'other' => 'other_amount'
        );
    }

    public static function createTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_input_completion_patterns (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . " analysis_date DATE NOT NULL,\n target_ym CHAR(7) NOT NULL,\n"
            . " scope_type VARCHAR(30) NOT NULL,\n project_id INT UNSIGNED NOT NULL DEFAULT 0,\n cost_type VARCHAR(40) NOT NULL,\n"
            . " progress_day INT UNSIGNED NOT NULL DEFAULT 0,\n progress_rate DECIMAL(8,3) NOT NULL DEFAULT 0,\n expected_completion_rate DECIMAL(8,3) NULL,\n"
            . " sample_month_count INT UNSIGNED NOT NULL DEFAULT 0,\n event_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " average_input_lag_days DECIMAL(8,3) NULL,\n completion_volatility DECIMAL(8,3) NULL,\n late_bulk_rate DECIMAL(8,3) NULL,\n correction_rate DECIMAL(8,3) NULL,\n month_move_rate DECIMAL(8,3) NULL,\n"
            . " fallback_level VARCHAR(40) NOT NULL,\n data_status VARCHAR(30) NOT NULL,\n calculation_version VARCHAR(40) NOT NULL,\n source_fingerprint CHAR(64) NOT NULL,\n"
            . " detail_data MEDIUMTEXT NULL,\n first_created_at DATETIME NOT NULL,\n last_calculated_at DATETIME NOT NULL,\n calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n created_at DATETIME NOT NULL,\n updated_at DATETIME NOT NULL,\n"
            . " UNIQUE KEY uk_ai_input_pattern (analysis_date,target_ym,scope_type,project_id,cost_type,progress_day),\n"
            . " KEY idx_ai_input_pattern_lookup (target_ym,project_id,cost_type,scope_type),\n"
            . " KEY idx_ai_input_pattern_source (source_fingerprint)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function requiredColumns()
    {
        return array('id','analysis_date','target_ym','scope_type','project_id','cost_type','progress_day','progress_rate','expected_completion_rate','sample_month_count','event_count','average_input_lag_days','completion_volatility','late_bulk_rate','correction_rate','month_move_rate','fallback_level','data_status','calculation_version','source_fingerprint','detail_data','first_created_at','last_calculated_at','calculation_count','created_at','updated_at');
    }

    public static function installOrUpdate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        try {
            if ($pdo->exec(self::createTableSql()) === false) return array('ok'=>false,'message'=>'입력패턴 테이블을 설치하지 못했습니다.');
            self::$tableCache = array();
            return array('ok'=>self::isInstalled($pdo),'message'=>self::isInstalled($pdo)?'입력패턴 테이블 설치를 확인했습니다.':'입력패턴 테이블 구조를 확인해주세요.');
        } catch (Exception $e) {
            error_log('[AI Input Pattern] install failed');
            return array('ok'=>false,'message'=>'입력패턴 테이블 설치를 확인하지 못했습니다.');
        }
    }

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo, self::TABLE_NAME)) return false;
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `' . self::TABLE_NAME . '`');
            if (!$st) return false;
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $found = array();
            foreach ($rows as $row) if (isset($row['Field'])) $found[(string)$row['Field']] = true;
            foreach (self::requiredColumns() as $column) if (!isset($found[$column])) return false;
            return true;
        } catch (Exception $e) { return false; }
    }

    public static function schemaStatus($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $status = array('db_available'=>(bool)$pdo,'installed'=>false,'row_count'=>0,'latest_analysis_date'=>'','min_completion_rate'=>self::DEFAULT_MIN_COMPLETION_RATE);
        if (!$pdo) return $status;
        $status['installed'] = self::isInstalled($pdo);
        $status['min_completion_rate'] = self::minCompletionRate($pdo);
        if (!$status['installed']) return $status;
        try {
            $st = $pdo->query('SELECT COUNT(*) AS row_count,MAX(analysis_date) AS latest_analysis_date FROM `' . self::TABLE_NAME . '`');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($row)) { $status['row_count']=(int)$row['row_count']; $status['latest_analysis_date']=(string)$row['latest_analysis_date']; }
        } catch (Exception $e) {}
        return $status;
    }

    private static function settingInt($pdo, $key, $default, $min, $max)
    {
        $value = CostChangeService::setting($pdo, $key);
        if ($value === '' || !is_numeric($value)) return $default;
        return max($min, min($max, (int)$value));
    }

    public static function graceDays($pdo = null)
    {
        /* Deprecated compatibility method. CPMS has no post-closing grace period. */
        return 0;
    }

    public static function minCompletionRate($pdo = null)
    {
        return self::settingInt(self::pdo($pdo), 'min_completion_rate_for_direct_projection', self::DEFAULT_MIN_COMPLETION_RATE, 5, 80);
    }

    public static function saveSettings($pdo, $graceDays, $minCompletionRate)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return false;
        $minCompletionRate=max(5,min(80,(int)$minCompletionRate));
        return CostChangeService::saveSetting($pdo,'min_completion_rate_for_direct_projection',$minCompletionRate);
    }

    public static function progress($snapshotDate, $targetYm, $costType)
    {
        $period = CostChangeService::periodForYm($costType, $targetYm);
        if (empty($period['start']) || empty($period['end'])) return array('day'=>0,'rate'=>0,'start'=>'','end'=>'');
        $start=strtotime($period['start']); $end=strtotime($period['end']); $date=strtotime($snapshotDate);
        if ($start===false||$end===false||$date===false) return array('day'=>0,'rate'=>0,'start'=>$period['start'],'end'=>$period['end']);
        $total=max(1,(int)floor(($end-$start)/86400)+1);
        $elapsed=(int)floor(($date-$start)/86400)+1;
        $elapsed=max(0,min($total,$elapsed));
        return array('day'=>$elapsed,'rate'=>round($elapsed/$total*100,3),'start'=>$period['start'],'end'=>$period['end']);
    }

    public static function median($values)
    {
        $clean=array(); foreach($values as $value) if(is_numeric($value)) $clean[]=(float)$value;
        if(count($clean)===0) return null; sort($clean,SORT_NUMERIC); $count=count($clean); $mid=(int)floor($count/2);
        return $count%2?round($clean[$mid],3):round(($clean[$mid-1]+$clean[$mid])/2,3);
    }

    public static function volatility($values)
    {
        $clean=array(); foreach($values as $value) if(is_numeric($value)) $clean[]=(float)$value;
        if(count($clean)<2) return null; $mean=array_sum($clean)/count($clean); $sum=0.0;
        foreach($clean as $value) $sum+=pow($value-$mean,2);
        return round(sqrt($sum/count($clean)),3);
    }

    private static function encode($value)
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($json)?$json:null;
    }

    public static function decode($value)
    {
        if(!is_string($value)||trim($value)==='') return array(); $data=json_decode($value,true); return is_array($data)?$data:array();
    }

    private static function latestContext($pdo)
    {
        $empty=array('available'=>false,'snapshot_date'=>'','target_ym'=>'');
        if(!self::tableExists($pdo,self::SNAPSHOT_TABLE)) return $empty;
        try { $st=$pdo->query('SELECT snapshot_date,target_ym FROM `' . self::SNAPSHOT_TABLE . '` ORDER BY snapshot_date DESC,id DESC LIMIT 1'); $row=$st?$st->fetch(PDO::FETCH_ASSOC):false; if(!is_array($row)) return $empty; return array('available'=>true,'snapshot_date'=>(string)$row['snapshot_date'],'target_ym'=>(string)$row['target_ym']); } catch(Exception $e){return $empty;}
    }

    public static function finalizedYm($ym,$today,$costType,$graceDays = 0)
    {
        $period=CostChangeService::periodForYm($costType,$ym); if(empty($period['end'])) return false;
        $end=strtotime($period['end']); $now=strtotime($today);
        return $end!==false&&$now!==false&&$now>$end;
    }

    private static function historyRows($pdo,$targetYm,$today,$graceDays)
    {
        if(!self::tableExists($pdo,self::SNAPSHOT_TABLE)) return array();
        $min=date('Y-m',strtotime($targetYm.'-01 -18 months'));
        $columns=array_values(self::categories());
        $sql='SELECT snapshot_date,target_ym,project_id,project_name_snapshot,'.implode(',',$columns).',monthly_input_amount FROM `' . self::SNAPSHOT_TABLE . '` WHERE target_ym>=:min_ym AND target_ym<:target_ym ORDER BY project_id,target_ym,snapshot_date';
        try { $st=$pdo->prepare($sql); if(!$st||!$st->execute(array(':min_ym'=>$min,':target_ym'=>$targetYm))) return array(); $rows=$st->fetchAll(PDO::FETCH_ASSOC); return is_array($rows)?$rows:array(); } catch(Exception $e){return array();}
    }

    private static function eventStats($pdo,$targetYm)
    {
        $result=array(); if(!self::tableExists($pdo,self::EVENT_TABLE)) return $result;
        $start=date('Y-m-d',strtotime($targetYm.'-01 -18 months')); $end=$targetYm.'-01';
        $sql="SELECT project_id,cost_type,COUNT(*) AS event_count,AVG(CASE WHEN actual_date IS NOT NULL THEN GREATEST(0,DATEDIFF(DATE(event_at),actual_date)) ELSE NULL END) AS average_lag,SUM(CASE WHEN event_action IN ('UPDATE','ADJUST') THEN 1 ELSE 0 END) AS correction_count,SUM(CASE WHEN source_type IN ('EXCEL','APPROVAL') AND actual_date IS NOT NULL AND DATEDIFF(DATE(event_at),actual_date)>=7 THEN 1 ELSE 0 END) AS bulk_count,SUM(CASE WHEN event_action='UPDATE' AND old_data LIKE '%settlement_ym%' AND new_data LIKE '%settlement_ym%' THEN 1 ELSE 0 END) AS move_count FROM `" . self::EVENT_TABLE . "` WHERE event_at>=:start_date AND event_at<:end_date AND event_action<>'DELETE' GROUP BY project_id,cost_type";
        try { $st=$pdo->prepare($sql); if(!$st||!$st->execute(array(':start_date'=>$start,':end_date'=>$end))) return $result; $rows=$st->fetchAll(PDO::FETCH_ASSOC); foreach($rows as $row){$project=(int)$row['project_id'];$cost=(string)$row['cost_type'];$count=max(0,(int)$row['event_count']);$result[$project.':'.$cost]=array('event_count'=>$count,'average_lag'=>$row['average_lag']===null?null:(float)$row['average_lag'],'correction_rate'=>$count?round((int)$row['correction_count']/$count*100,3):null,'bulk_rate'=>$count?round((int)$row['bulk_count']/$count*100,3):null,'move_rate'=>$count?round((int)$row['move_count']/$count*100,3):null);}} catch(Exception $e){}
        return $result;
    }

    private static function sampleSeries($rows,$today,$graceDays,$context)
    {
        $categories=self::categories(); $groups=array();
        foreach($rows as $row){$project=(int)$row['project_id'];$ym=(string)$row['target_ym'];foreach($categories as $cost=>$column){if(!self::finalizedYm($ym,$today,$cost))continue;$key=$project.':'.$ym.':'.$cost;if(!isset($groups[$key]))$groups[$key]=array();$groups[$key][]=$row;}}
        $samples=array();
        foreach($groups as $key=>$monthRows){$parts=explode(':',$key,3);$project=(int)$parts[0];$ym=$parts[1];$cost=$parts[2];$column=$categories[$cost];$period=CostChangeService::periodForYm($cost,$ym);$final=null;foreach($monthRows as $row)if(!empty($period['end'])&&(string)$row['snapshot_date']>=(string)$period['end'])$final=$row;if(!$final)continue;$finalAmount=isset($final[$column])?(float)$final[$column]:0.0;if($finalAmount<=0)continue;$currentProgress=self::progress($context['snapshot_date'],$context['target_ym'],$cost);$best=null;$bestDistance=9999;foreach($monthRows as $row){$p=self::progress($row['snapshot_date'],$ym,$cost);$distance=abs((float)$p['rate']-(float)$currentProgress['rate']);if($distance<$bestDistance){$bestDistance=$distance;$best=$row;}}if(!$best)continue;$partial=isset($best[$column])?(float)$best[$column]:0.0;$rate=max(0,min(100,$partial/$finalAmount*100));$samples[]=array('project_id'=>$project,'cost_type'=>$cost,'ym'=>$ym,'completion_rate'=>$rate,'final_amount'=>$finalAmount,'progress_rate'=>$currentProgress['rate'],'progress_day'=>$currentProgress['day']);}
        return $samples;
    }

    private static function aggregateSamples($samples,$scope,$projectId,$costType,$eventStats)
    {
        $rates=array();$months=array();$finals=array();$events=0;$lags=array();$bulk=array();$corrections=array();$moves=array();$seenEvent=array();
        foreach($samples as $sample){if($scope==='PROJECT_CATEGORY'&&((int)$sample['project_id']!==$projectId||$sample['cost_type']!==$costType))continue;if($scope==='PROJECT_ALL'&&(int)$sample['project_id']!==$projectId)continue;if($scope==='COMPANY_CATEGORY'&&$sample['cost_type']!==$costType)continue;$rates[]=$sample['completion_rate'];$months[$sample['ym']]=true;$finals[]=$sample['final_amount'];$key=(int)$sample['project_id'].':'.$sample['cost_type'];if(isset($eventStats[$key])&&!isset($seenEvent[$key])){$seenEvent[$key]=true;$row=$eventStats[$key];$events+=(int)$row['event_count'];if($row['average_lag']!==null)$lags[]=$row['average_lag'];if($row['bulk_rate']!==null)$bulk[]=$row['bulk_rate'];if($row['correction_rate']!==null)$corrections[]=$row['correction_rate'];if($row['move_rate']!==null)$moves[]=$row['move_rate'];}}
        $sampleMonths=array_keys($months);sort($sampleMonths,SORT_STRING);
        return array('expected_completion_rate'=>self::median($rates),'sample_month_count'=>count($months),'sample_months'=>$sampleMonths,'event_count'=>$events,'average_input_lag_days'=>self::median($lags),'completion_volatility'=>self::volatility($rates),'late_bulk_rate'=>self::median($bulk),'correction_rate'=>self::median($corrections),'month_move_rate'=>self::median($moves),'historical_final_median'=>self::median($finals));
    }

    private static function savePattern($pdo,$context,$scope,$projectId,$costType,$progress,$stats,$fingerprint)
    {
        $fallback=array('PROJECT_CATEGORY'=>'PROJECT_CATEGORY','PROJECT_ALL'=>'PROJECT_ALL','COMPANY_CATEGORY'=>'COMPANY_CATEGORY','COMPANY_ALL'=>'COMPANY_ALL');
        $status=$stats['expected_completion_rate']===null?'INSUFFICIENT':($stats['sample_month_count']>=3?'READY':'LIMITED');$now=date('Y-m-d H:i:s');
        $sql='INSERT INTO `' . self::TABLE_NAME . '` (analysis_date,target_ym,scope_type,project_id,cost_type,progress_day,progress_rate,expected_completion_rate,sample_month_count,event_count,average_input_lag_days,completion_volatility,late_bulk_rate,correction_rate,month_move_rate,fallback_level,data_status,calculation_version,source_fingerprint,detail_data,first_created_at,last_calculated_at,calculation_count,created_at,updated_at) VALUES (:date,:ym,:scope,:project,:cost,:day,:progress,:completion,:months,:events,:lag,:volatility,:bulk,:correction,:move,:fallback,:status,:version,:fingerprint,:detail,:first,:last,1,:created,:updated) ON DUPLICATE KEY UPDATE progress_rate=VALUES(progress_rate),expected_completion_rate=VALUES(expected_completion_rate),sample_month_count=VALUES(sample_month_count),event_count=VALUES(event_count),average_input_lag_days=VALUES(average_input_lag_days),completion_volatility=VALUES(completion_volatility),late_bulk_rate=VALUES(late_bulk_rate),correction_rate=VALUES(correction_rate),month_move_rate=VALUES(month_move_rate),fallback_level=VALUES(fallback_level),data_status=VALUES(data_status),calculation_version=VALUES(calculation_version),source_fingerprint=VALUES(source_fingerprint),detail_data=VALUES(detail_data),last_calculated_at=VALUES(last_calculated_at),calculation_count=calculation_count+1,updated_at=VALUES(updated_at)';
        $st=$pdo->prepare($sql);if(!$st)return false;
        return $st->execute(array(':date'=>$context['snapshot_date'],':ym'=>$context['target_ym'],':scope'=>$scope,':project'=>$projectId,':cost'=>$costType,':day'=>$progress['day'],':progress'=>$progress['rate'],':completion'=>$stats['expected_completion_rate'],':months'=>$stats['sample_month_count'],':events'=>$stats['event_count'],':lag'=>$stats['average_input_lag_days'],':volatility'=>$stats['completion_volatility'],':bulk'=>$stats['late_bulk_rate'],':correction'=>$stats['correction_rate'],':move'=>$stats['month_move_rate'],':fallback'=>$fallback[$scope],':status'=>$status,':version'=>self::CALCULATION_VERSION,':fingerprint'=>$fingerprint,':detail'=>self::encode(array('historical_final_median'=>$stats['historical_final_median'],'sample_months'=>$stats['sample_months'])),':first'=>$now,':last'=>$now,':created'=>$now,':updated'=>$now));
    }

    public static function calculateLatest($pdo = null,$triggerType = 'SYSTEM')
    {
        $pdo=self::pdo($pdo);$empty=array('ok'=>false,'status'=>'FAILED','projects'=>0,'patterns'=>0,'message'=>'입력패턴을 계산하지 못했습니다.');
        if(!$pdo){$empty['message']='DB 연결 상태를 확인할 수 없습니다.';return $empty;}if(!self::isInstalled($pdo)){$empty['message']='입력패턴 테이블을 먼저 설치해주세요.';return $empty;}
        $context=self::latestContext($pdo);if(empty($context['available'])){$empty['message']='입력패턴을 계산하려면 일일 스냅샷이 필요합니다.';return $empty;}
        $today=self::businessToday();$rows=self::historyRows($pdo,$context['target_ym'],$today,0);$samples=self::sampleSeries($rows,$today,0,$context);$events=self::eventStats($pdo,$context['target_ym']);
        $projects=array();foreach($samples as $sample)$projects[(int)$sample['project_id']]=true;$fingerprint=hash('sha256',self::encode(array('context'=>$context,'samples'=>$samples,'finalization'=>'PERIOD_END','version'=>self::CALCULATION_VERSION)));$saved=0;$failed=0;
        try{
            foreach(array_keys($projects) as $projectId){foreach(self::categories() as $cost=>$column){$progress=self::progress($context['snapshot_date'],$context['target_ym'],$cost);foreach(array('PROJECT_CATEGORY','PROJECT_ALL') as $scope){$scopeCost=$scope==='PROJECT_ALL'?'all':$cost;$stats=self::aggregateSamples($samples,$scope,$projectId,$cost,$events);if(self::savePattern($pdo,$context,$scope,$projectId,$scopeCost,$progress,$stats,$fingerprint))$saved++;else$failed++;}}}
            foreach(self::categories() as $cost=>$column){$progress=self::progress($context['snapshot_date'],$context['target_ym'],$cost);$stats=self::aggregateSamples($samples,'COMPANY_CATEGORY',0,$cost,$events);if(self::savePattern($pdo,$context,'COMPANY_CATEGORY',0,$cost,$progress,$stats,$fingerprint))$saved++;else$failed++;}
            $progress=self::progress($context['snapshot_date'],$context['target_ym'],'other');$stats=self::aggregateSamples($samples,'COMPANY_ALL',0,'all',$events);if(self::savePattern($pdo,$context,'COMPANY_ALL',0,'all',$progress,$stats,$fingerprint))$saved++;else$failed++;
        }catch(Exception $e){error_log('[AI Input Pattern] calculation failed');return $empty;}
        return array('ok'=>$saved>0,'status'=>$failed>0?'PARTIAL':'COMPLETED','projects'=>count($projects),'patterns'=>$saved,'message'=>$saved>0?'입력패턴 계산을 완료했습니다.':'학습 가능한 입력패턴 자료가 부족합니다.');
    }

    public static function loadBestPattern($pdo,$analysisDate,$targetYm,$projectId,$costType)
    {
        $pdo=self::pdo($pdo);if(!$pdo||!self::isInstalled($pdo))return array('available'=>false,'fallback_level'=>'COLD_START');
        $candidates=array(array('PROJECT_CATEGORY',$projectId,$costType,true),array('PROJECT_ALL',$projectId,'all',false),array('COMPANY_CATEGORY',0,$costType,false),array('COMPANY_ALL',0,'all',false));
        foreach($candidates as $candidate){try{$sql='SELECT * FROM `' . self::TABLE_NAME . '` WHERE analysis_date=:date AND target_ym=:ym AND scope_type=:scope AND project_id=:project AND cost_type=:cost ORDER BY progress_day DESC,id DESC LIMIT 1';$st=$pdo->prepare($sql);if(!$st||!$st->execute(array(':date'=>$analysisDate,':ym'=>$targetYm,':scope'=>$candidate[0],':project'=>$candidate[1],':cost'=>$candidate[2])))continue;$row=$st->fetch(PDO::FETCH_ASSOC);if(!is_array($row)||$row['expected_completion_rate']===null)continue;$row['available']=true;return $row;}catch(Exception $e){}}
        return array('available'=>false,'fallback_level'=>'COLD_START','expected_completion_rate'=>null,'sample_month_count'=>0,'event_count'=>0);
    }

    public static function learningState($monthCount)
    {
        $monthCount=max(0,(int)$monthCount);
        if($monthCount===0)return array('code'=>'COLD_START','label'=>'학습자료 없음','weight'=>0,'confidence_limit'=>'INSUFFICIENT');
        if($monthCount===1)return array('code'=>'INITIAL','label'=>'초기학습','weight'=>20,'confidence_limit'=>'LOW');
        if($monthCount===2)return array('code'=>'INITIAL_EXPANDED','label'=>'초기학습 확대','weight'=>40,'confidence_limit'=>'MEDIUM');
        return array('code'=>'NORMAL_LEARNING','label'=>'정상학습','weight'=>100,'confidence_limit'=>'HIGH');
    }

    public static function learningSummary($pdo,$analysisDate,$targetYm)
    {
        $empty=array('month_count'=>0,'months'=>array(),'first_ym'=>'','last_ym'=>'','state'=>self::learningState(0));
        $pdo=self::pdo($pdo);if(!$pdo||!self::isInstalled($pdo))return $empty;
        try{$st=$pdo->prepare('SELECT sample_month_count,detail_data FROM `' . self::TABLE_NAME . '` WHERE analysis_date=:date AND target_ym=:ym');if(!$st||!$st->execute(array(':date'=>$analysisDate,':ym'=>$targetYm)))return $empty;$months=array();$maxCount=0;foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$maxCount=max($maxCount,(int)$row['sample_month_count']);$detail=self::decode(isset($row['detail_data'])?$row['detail_data']:'');if(isset($detail['sample_months'])&&is_array($detail['sample_months']))foreach($detail['sample_months'] as $ym)if(self::validYm($ym))$months[(string)$ym]=true;} $list=array_keys($months);sort($list,SORT_STRING);$count=max($maxCount,count($list));return array('month_count'=>$count,'months'=>$list,'first_ym'=>count($list)?$list[0]:'','last_ym'=>count($list)?$list[count($list)-1]:'','state'=>self::learningState($count));}catch(Exception $e){return $empty;}
    }

    public static function listLatest($pdo,$filters,$page,$perPage)
    {
        $pdo=self::pdo($pdo);$page=max(1,(int)$page);$perPage=max(1,min(100,(int)$perPage));if(!$pdo||!self::isInstalled($pdo))return array();
        try{$date=isset($filters['analysis_date'])?(string)$filters['analysis_date']:'';if($date===''){$st=$pdo->query('SELECT MAX(analysis_date) FROM `' . self::TABLE_NAME . '`');$date=$st?(string)$st->fetchColumn():'';}$sql='SELECT * FROM `' . self::TABLE_NAME . '` WHERE analysis_date=:date ORDER BY scope_type,project_id,cost_type LIMIT :limit OFFSET :offset';$st=$pdo->prepare($sql);if(!$st)return array();$st->bindValue(':date',$date,PDO::PARAM_STR);$st->bindValue(':limit',$perPage,PDO::PARAM_INT);$st->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT);if(!$st->execute())return array();$rows=$st->fetchAll(PDO::FETCH_ASSOC);return is_array($rows)?$rows:array();}catch(Exception $e){return array();}
    }
}
?>
