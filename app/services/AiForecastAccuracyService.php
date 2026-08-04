<?php
/** Append-only actual-close versions and forecast accuracy measurements. */
namespace App\Services;

use App\Core\Db;
use App\Core\Auth;
use PDO;
use Exception;

require_once __DIR__ . '/AiCostForecastV2Service.php';
require_once __DIR__ . '/AiCostDataGovernanceService.php';

class AiForecastAccuracyService
{
    const ACTUAL_TABLE = 'cpms_ai_cost_actual_closes';
    const ACCURACY_TABLE = 'cpms_ai_cost_forecast_accuracy';
    const SNAPSHOT_TABLE = 'cpms_ai_daily_snapshots';
    const CALCULATION_VERSION = 'FORECAST_ACCURACY_V1';

    private static function pdo($pdo){return $pdo?$pdo:Db::pdo();}
    private static function actor(){ $u=Auth::user(); return array('id'=>is_array($u)&&isset($u['id'])?(int)$u['id']:0,'name'=>is_array($u)&&isset($u['name'])?(string)$u['name']:''); }
    private static function encode($v){$j=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($j)?$j:null;}
    public static function tableExists($pdo,$table){if(!$pdo||!preg_match('/^[A-Za-z0-9_]+$/',(string)$table))return false;try{$st=$pdo->prepare('SHOW TABLES LIKE :table');return $st&&$st->execute(array(':table'=>$table))&&$st->fetchColumn()!==false;}catch(Exception $e){return false;}}

    public static function installOrUpdate($pdo=null)
    {
        $pdo=self::pdo($pdo);if(!$pdo)return array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        $actual="CREATE TABLE IF NOT EXISTS `".self::ACTUAL_TABLE."` (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          target_ym CHAR(7) NOT NULL,
          project_id INT UNSIGNED NOT NULL,
          cost_type VARCHAR(40) NOT NULL,
          actual_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          close_date DATE NOT NULL,
          source_snapshot_date DATE NULL,
          actual_version INT UNSIGNED NOT NULL,
          supersedes_actual_id BIGINT UNSIGNED NULL,
          change_reason VARCHAR(500) NULL,
          source_fingerprint CHAR(64) NOT NULL,
          data_origin_summary MEDIUMTEXT NULL,
          confirmed_by INT UNSIGNED NULL,
          confirmed_by_name VARCHAR(100) NULL,
          confirmed_at DATETIME NOT NULL,
          created_at DATETIME NOT NULL,
          UNIQUE KEY uk_ai_actual_version (target_ym,project_id,cost_type,actual_version),
          KEY idx_ai_actual_lookup (target_ym,project_id,cost_type,confirmed_at),
          KEY idx_ai_actual_source (source_fingerprint)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $accuracy="CREATE TABLE IF NOT EXISTS `".self::ACCURACY_TABLE."` (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          actual_id BIGINT UNSIGNED NOT NULL,
          forecast_result_id BIGINT UNSIGNED NULL,
          category_forecast_id BIGINT UNSIGNED NOT NULL,
          run_id BIGINT UNSIGNED NOT NULL,
          analysis_date DATE NOT NULL,
          target_ym CHAR(7) NOT NULL,
          project_id INT UNSIGNED NOT NULL,
          cost_type VARCHAR(40) NOT NULL,
          days_to_close INT NOT NULL,
          current_entered_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          expected_unentered_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          final_expected_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          expected_lower_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          expected_upper_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          actual_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          signed_error DECIMAL(18,2) NOT NULL DEFAULT 0,
          absolute_error DECIMAL(18,2) NOT NULL DEFAULT 0,
          absolute_percentage_error DECIMAL(12,6) NULL,
          error_direction VARCHAR(20) NOT NULL,
          in_expected_range TINYINT(1) NOT NULL DEFAULT 0,
          amount_pattern_month_count INT UNSIGNED NOT NULL DEFAULT 0,
          timing_pattern_month_count INT UNSIGNED NOT NULL DEFAULT 0,
          prediction_method VARCHAR(60) NOT NULL,
          data_origin_summary MEDIUMTEXT NULL,
          calculation_version VARCHAR(40) NOT NULL,
          created_at DATETIME NOT NULL,
          UNIQUE KEY uk_ai_accuracy_actual_forecast (actual_id,category_forecast_id),
          KEY idx_ai_accuracy_month (target_ym,analysis_date),
          KEY idx_ai_accuracy_project (project_id,target_ym),
          KEY idx_ai_accuracy_category (cost_type,target_ym),
          KEY idx_ai_accuracy_days (days_to_close,target_ym)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try{if($pdo->exec($actual)===false||$pdo->exec($accuracy)===false)return array('ok'=>false,'message'=>'예측 정확도 구조를 설치하지 못했습니다.');return array('ok'=>true,'message'=>'실제 마감 및 예측 정확도 구조 설치를 확인했습니다.');}catch(Exception $e){error_log('[AI Accuracy] install failed: '.$e->getMessage());return array('ok'=>false,'message'=>'예측 정확도 구조 설치 중 오류가 발생했습니다.');}
    }

    public static function isInstalled($pdo=null){$pdo=self::pdo($pdo);return self::tableExists($pdo,self::ACTUAL_TABLE)&&self::tableExists($pdo,self::ACCURACY_TABLE);}
    public static function schemaStatus($pdo=null){$pdo=self::pdo($pdo);$r=array('db_available'=>(bool)$pdo,'installed'=>self::isInstalled($pdo),'actual_count'=>0,'accuracy_count'=>0);if(!$pdo||!$r['installed'])return $r;try{$st=$pdo->query('SELECT COUNT(*) FROM `'.self::ACTUAL_TABLE.'`');if($st)$r['actual_count']=(int)$st->fetchColumn();$st=$pdo->query('SELECT COUNT(*) FROM `'.self::ACCURACY_TABLE.'`');if($st)$r['accuracy_count']=(int)$st->fetchColumn();}catch(Exception $e){}return $r;}

    public static function closeDate($targetYm,$costType)
    {
        if(!preg_match('/^\d{4}-\d{2}$/',(string)$targetYm))return '';
        if((string)$costType==='labor')return date('Y-m-t',strtotime($targetYm.'-01'));
        return $targetYm.'-25';
    }

    public static function metrics($forecast,$low,$high,$actual)
    {
        $forecast=(float)$forecast;$low=(float)$low;$high=(float)$high;$actual=(float)$actual;
        $signed=$forecast-$actual;$absolute=abs($signed);
        return array(
            'signed_error'=>round($signed,2),
            'absolute_error'=>round($absolute,2),
            'absolute_percentage_error'=>$actual==0.0?null:round($absolute/abs($actual),6),
            'error_direction'=>$signed>0?'OVER':($signed<0?'UNDER':'EXACT'),
            'in_expected_range'=>$actual>=$low&&$actual<=$high?1:0
        );
    }

    private static function latestActual($pdo,$ym,$project,$cost)
    {
        $st=$pdo->prepare('SELECT * FROM `'.self::ACTUAL_TABLE.'` WHERE target_ym=:ym AND project_id=:project AND cost_type=:cost ORDER BY actual_version DESC,id DESC LIMIT 1');
        if(!$st||!$st->execute(array(':ym'=>$ym,':project'=>(int)$project,':cost'=>$cost)))return array();$r=$st->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:array();
    }

    public static function captureClosedActuals($pdo=null,$asOfDate='',$reason='정상 마감 집계')
    {
        $pdo=self::pdo($pdo);$asOf=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$asOfDate)?$asOfDate:date('Y-m-d');
        $out=array('ok'=>false,'inserted'=>0,'unchanged'=>0,'skipped'=>0,'message'=>'실제 마감금액을 확정하지 못했습니다.');
        if(!$pdo||!self::isInstalled($pdo)||!self::tableExists($pdo,self::SNAPSHOT_TABLE)){$out['message']='실제 마감 구조와 일일 스냅샷을 먼저 확인해주세요.';return $out;}
        $categories=AiCostForecastV2Service::categories();$actor=self::actor();
        try{
            $q=$pdo->prepare('SELECT target_ym,project_id,MAX(snapshot_date) AS source_date FROM `'.self::SNAPSHOT_TABLE.'` WHERE snapshot_date<=:as_of GROUP BY target_ym,project_id ORDER BY target_ym,project_id');
            if(!$q||!$q->execute(array(':as_of'=>$asOf)))return $out;
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $scope){
                $ym=(string)$scope['target_ym'];$project=(int)$scope['project_id'];$sourceDate=(string)$scope['source_date'];
                $cols=array_values($categories);$st=$pdo->prepare('SELECT '.implode(',',$cols).' FROM `'.self::SNAPSHOT_TABLE.'` WHERE target_ym=:ym AND project_id=:project AND snapshot_date=:date ORDER BY id DESC LIMIT 1');
                if(!$st||!$st->execute(array(':ym'=>$ym,':project'=>$project,':date'=>$sourceDate))){$out['skipped']++;continue;}$snapshot=$st->fetch(PDO::FETCH_ASSOC);if(!is_array($snapshot)){$out['skipped']++;continue;}
                foreach($categories as $cost=>$column){
                    $close=self::closeDate($ym,$cost);if($close===''||$close>$asOf||$sourceDate<$close){$out['skipped']++;continue;}
                    $amount=isset($snapshot[$column])?(float)$snapshot[$column]:0.0;
                    $origins=AiCostDataGovernanceService::originSummary($pdo,$project,$ym,$cost);
                    $fingerprint=hash('sha256',self::encode(array('ym'=>$ym,'project'=>$project,'cost'=>$cost,'amount'=>round($amount,2),'source_date'=>$sourceDate,'origins'=>$origins)));
                    $latest=self::latestActual($pdo,$ym,$project,$cost);
                    if(!empty($latest)&&isset($latest['source_fingerprint'])&&hash_equals((string)$latest['source_fingerprint'],$fingerprint)){$out['unchanged']++;continue;}
                    $version=empty($latest)?1:(int)$latest['actual_version']+1;$supersedes=empty($latest)?null:(int)$latest['id'];
                    $sql='INSERT INTO `'.self::ACTUAL_TABLE.'` (target_ym,project_id,cost_type,actual_amount,close_date,source_snapshot_date,actual_version,supersedes_actual_id,change_reason,source_fingerprint,data_origin_summary,confirmed_by,confirmed_by_name,confirmed_at,created_at) VALUES (:ym,:project,:cost,:amount,:close,:source_date,:version,:supersedes,:reason,:fingerprint,:origins,:actor,:actor_name,:confirmed,:created)';
                    $save=$pdo->prepare($sql);$now=date('Y-m-d H:i:s');if($save&&$save->execute(array(':ym'=>$ym,':project'=>$project,':cost'=>$cost,':amount'=>$amount,':close'=>$close,':source_date'=>$sourceDate,':version'=>$version,':supersedes'=>$supersedes,':reason'=>trim((string)$reason),':fingerprint'=>$fingerprint,':origins'=>self::encode($origins),':actor'=>$actor['id']>0?$actor['id']:null,':actor_name'=>$actor['name'],':confirmed'=>$now,':created'=>$now)))$out['inserted']++;else $out['skipped']++;
                }
            }
            $out['ok']=true;$out['message']='실제 마감금액 버전 확정을 완료했습니다.';return $out;
        }catch(Exception $e){error_log('[AI Accuracy] actual capture failed: '.$e->getMessage());return $out;}
    }

    public static function evaluate($pdo=null)
    {
        $pdo=self::pdo($pdo);$out=array('ok'=>false,'inserted'=>0,'skipped'=>0,'message'=>'예측 정확도를 계산하지 못했습니다.');if(!$pdo||!self::isInstalled($pdo)||!AiCostForecastV2Service::isInstalled($pdo))return $out;
        try{
            $sql='SELECT a.* FROM `'.self::ACTUAL_TABLE.'` a INNER JOIN (SELECT target_ym,project_id,cost_type,MAX(actual_version) AS latest_version FROM `'.self::ACTUAL_TABLE.'` GROUP BY target_ym,project_id,cost_type) x ON x.target_ym=a.target_ym AND x.project_id=a.project_id AND x.cost_type=a.cost_type AND x.latest_version=a.actual_version';
            $st=$pdo->query($sql);if(!$st)return $out;
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $actual){
                $forecastSql='SELECT c.*,r.id AS forecast_result_id,r.source_fingerprint FROM `'.AiCostForecastV2Service::CATEGORY_TABLE.'` c LEFT JOIN `'.AiCostForecastV2Service::RESULT_TABLE.'` r ON r.run_id=c.run_id AND r.project_id=c.project_id WHERE c.target_ym=:ym AND c.project_id=:project AND c.cost_type=:cost AND c.analysis_date<=:close_date ORDER BY c.analysis_date,c.id';
                $find=$pdo->prepare($forecastSql);if(!$find||!$find->execute(array(':ym'=>$actual['target_ym'],':project'=>(int)$actual['project_id'],':cost'=>$actual['cost_type'],':close_date'=>$actual['close_date']))){$out['skipped']++;continue;}
                foreach($find->fetchAll(PDO::FETCH_ASSOC) as $forecast){
                    $check=$pdo->prepare('SELECT id FROM `'.self::ACCURACY_TABLE.'` WHERE actual_id=:actual AND category_forecast_id=:forecast LIMIT 1');if(!$check||!$check->execute(array(':actual'=>(int)$actual['id'],':forecast'=>(int)$forecast['id']))){$out['skipped']++;continue;}if($check->fetchColumn()!==false){$out['skipped']++;continue;}
                    $m=self::metrics($forecast['final_forecast_amount'],$forecast['forecast_low_amount'],$forecast['forecast_high_amount'],$actual['actual_amount']);$days=(int)floor((strtotime($actual['close_date'])-strtotime($forecast['analysis_date']))/86400);
                    $candidate=AiCostForecastV2Service::decode(isset($forecast['candidate_data'])?$forecast['candidate_data']:'');$amountMonths=isset($candidate['amount_pattern_month_count'])?(int)$candidate['amount_pattern_month_count']:(isset($forecast['amount_pattern_month_count'])?(int)$forecast['amount_pattern_month_count']:0);$timingMonths=isset($candidate['timing_pattern_month_count'])?(int)$candidate['timing_pattern_month_count']:(isset($forecast['sample_count'])?(int)$forecast['sample_count']:0);
                    $insert='INSERT INTO `'.self::ACCURACY_TABLE.'` (actual_id,forecast_result_id,category_forecast_id,run_id,analysis_date,target_ym,project_id,cost_type,days_to_close,current_entered_amount,expected_unentered_amount,final_expected_amount,expected_lower_amount,expected_upper_amount,actual_amount,signed_error,absolute_error,absolute_percentage_error,error_direction,in_expected_range,amount_pattern_month_count,timing_pattern_month_count,prediction_method,data_origin_summary,calculation_version,created_at) VALUES (:actual,:result,:category,:run,:analysis,:ym,:project,:cost,:days,:current,:unentered,:forecast,:low,:high,:actual_amount,:signed,:absolute,:ape,:direction,:in_range,:amount_months,:timing_months,:method,:origins,:version,:created)';
                    $save=$pdo->prepare($insert);if($save&&$save->execute(array(':actual'=>(int)$actual['id'],':result'=>$forecast['forecast_result_id']===null?null:(int)$forecast['forecast_result_id'],':category'=>(int)$forecast['id'],':run'=>(int)$forecast['run_id'],':analysis'=>$forecast['analysis_date'],':ym'=>$forecast['target_ym'],':project'=>(int)$forecast['project_id'],':cost'=>$forecast['cost_type'],':days'=>$days,':current'=>$forecast['current_input_amount'],':unentered'=>$forecast['expected_unentered_amount'],':forecast'=>$forecast['final_forecast_amount'],':low'=>$forecast['forecast_low_amount'],':high'=>$forecast['forecast_high_amount'],':actual_amount'=>$actual['actual_amount'],':signed'=>$m['signed_error'],':absolute'=>$m['absolute_error'],':ape'=>$m['absolute_percentage_error'],':direction'=>$m['error_direction'],':in_range'=>$m['in_expected_range'],':amount_months'=>$amountMonths,':timing_months'=>$timingMonths,':method'=>$forecast['forecast_method'],':origins'=>$actual['data_origin_summary'],':version'=>self::CALCULATION_VERSION,':created'=>date('Y-m-d H:i:s'))))$out['inserted']++;else $out['skipped']++;
                }
            }
            $out['ok']=true;$out['message']='과거 예측과 실제 마감금액의 정확도 계산을 완료했습니다.';return $out;
        }catch(Exception $e){error_log('[AI Accuracy] evaluation failed: '.$e->getMessage());return $out;}
    }

    public static function summary($pdo=null,$filters=array())
    {
        $pdo=self::pdo($pdo);$empty=array('available'=>false,'count'=>0,'mean_absolute_error'=>null,'wape'=>null,'range_hit_rate'=>null,'over_rate'=>null,'under_rate'=>null);if(!$pdo||!self::isInstalled($pdo))return $empty;
        try{$where=' WHERE 1=1';$params=array();if(isset($filters['target_ym'])&&preg_match('/^\d{4}-\d{2}$/',(string)$filters['target_ym'])){$where.=' AND f.target_ym=:ym';$params[':ym']=$filters['target_ym'];}if(isset($filters['project_id'])&&(int)$filters['project_id']>0){$where.=' AND f.project_id=:project';$params[':project']=(int)$filters['project_id'];}if(isset($filters['cost_type'])&&preg_match('/^[a-z_]+$/',(string)$filters['cost_type'])){$where.=' AND f.cost_type=:cost';$params[':cost']=$filters['cost_type'];}$latest=' INNER JOIN `'.self::ACTUAL_TABLE.'` ac ON ac.id=f.actual_id INNER JOIN (SELECT target_ym,project_id,cost_type,MAX(actual_version) AS latest_version FROM `'.self::ACTUAL_TABLE.'` GROUP BY target_ym,project_id,cost_type) x ON x.target_ym=ac.target_ym AND x.project_id=ac.project_id AND x.cost_type=ac.cost_type AND x.latest_version=ac.actual_version';$sql='SELECT COUNT(*) AS cnt,AVG(f.absolute_error) AS mae,SUM(f.absolute_error)/NULLIF(SUM(ABS(f.actual_amount)),0) AS wape,AVG(f.in_expected_range)*100 AS hit_rate,SUM(CASE WHEN f.error_direction=\'OVER\' THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0)*100 AS over_rate,SUM(CASE WHEN f.error_direction=\'UNDER\' THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0)*100 AS under_rate FROM `'.self::ACCURACY_TABLE.'` f'.$latest.$where;$st=$pdo->prepare($sql);if(!$st||!$st->execute($params))return $empty;$r=$st->fetch(PDO::FETCH_ASSOC);if(!is_array($r)||(int)$r['cnt']<=0)return $empty;return array('available'=>true,'count'=>(int)$r['cnt'],'mean_absolute_error'=>$r['mae']===null?null:(float)$r['mae'],'wape'=>$r['wape']===null?null:(float)$r['wape'],'range_hit_rate'=>$r['hit_rate']===null?null:(float)$r['hit_rate'],'over_rate'=>$r['over_rate']===null?null:(float)$r['over_rate'],'under_rate'=>$r['under_rate']===null?null:(float)$r['under_rate']);}catch(Exception $e){return $empty;}
    }

    public static function historicalPerformance($pdo,$projectId,$costType)
    {
        $pdo=self::pdo($pdo);$empty=array('available'=>false,'sample_count'=>0,'wape'=>null,'range_hit_rate'=>null);if(!$pdo||!self::isInstalled($pdo))return $empty;
        try{$sql='SELECT COUNT(*) AS cnt,SUM(f.absolute_error)/NULLIF(SUM(ABS(f.actual_amount)),0) AS wape,AVG(f.in_expected_range)*100 AS hit_rate FROM `'.self::ACCURACY_TABLE.'` f INNER JOIN `'.self::ACTUAL_TABLE.'` ac ON ac.id=f.actual_id INNER JOIN (SELECT target_ym,project_id,cost_type,MAX(actual_version) AS latest_version FROM `'.self::ACTUAL_TABLE.'` GROUP BY target_ym,project_id,cost_type) x ON x.target_ym=ac.target_ym AND x.project_id=ac.project_id AND x.cost_type=ac.cost_type AND x.latest_version=ac.actual_version WHERE f.project_id=:project AND f.cost_type=:cost';$st=$pdo->prepare($sql);if(!$st||!$st->execute(array(':project'=>(int)$projectId,':cost'=>$costType)))return $empty;$r=$st->fetch(PDO::FETCH_ASSOC);if(!is_array($r)||(int)$r['cnt']<3)return $empty;return array('available'=>true,'sample_count'=>(int)$r['cnt'],'wape'=>$r['wape']===null?null:(float)$r['wape'],'range_hit_rate'=>$r['hit_rate']===null?null:(float)$r['hit_rate']);}catch(Exception $e){return $empty;}
    }

    private static function breakdownRows($pdo,$selectLabel,$joinSql,$whereSql,$groupSql,$params)
    {
        $metricSql='COUNT(*) AS sample_count,AVG(a.absolute_error) AS mean_absolute_error,SUM(a.absolute_error)/NULLIF(SUM(ABS(a.actual_amount)),0) AS wape,AVG(a.in_expected_range)*100 AS range_hit_rate,SUM(CASE WHEN a.error_direction=\'OVER\' THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0)*100 AS over_rate,SUM(CASE WHEN a.error_direction=\'UNDER\' THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0)*100 AS under_rate';
        try{$latest=' INNER JOIN `'.self::ACTUAL_TABLE.'` ac ON ac.id=a.actual_id INNER JOIN (SELECT target_ym,project_id,cost_type,MAX(actual_version) AS latest_version FROM `'.self::ACTUAL_TABLE.'` GROUP BY target_ym,project_id,cost_type) x ON x.target_ym=ac.target_ym AND x.project_id=ac.project_id AND x.cost_type=ac.cost_type AND x.latest_version=ac.actual_version ';$sql='SELECT '.$selectLabel.','.$metricSql.' FROM `'.self::ACCURACY_TABLE.'` a'.$latest.$joinSql.$whereSql.' GROUP BY '.$groupSql.' ORDER BY sample_count DESC LIMIT 100';$st=$pdo->prepare($sql);if(!$st||!$st->execute($params))return array();$rows=$st->fetchAll(PDO::FETCH_ASSOC);return is_array($rows)?$rows:array();}catch(Exception $e){return array();}
    }

    public static function performanceBreakdowns($pdo=null,$filters=array())
    {
        $pdo=self::pdo($pdo);$empty=array('projects'=>array(),'cost_types'=>array(),'close_windows'=>array());if(!$pdo||!self::isInstalled($pdo))return $empty;
        $where=' WHERE 1=1';$params=array();if(isset($filters['target_ym'])&&preg_match('/^\d{4}-\d{2}$/',(string)$filters['target_ym'])){$where.=' AND a.target_ym=:ym';$params[':ym']=$filters['target_ym'];}
        $projects=self::breakdownRows($pdo,'a.project_id AS group_key,COALESCE(NULLIF(TRIM(p.name),\'\'),\'현장정보 확인 필요\') AS group_label','LEFT JOIN cpms_projects p ON p.id=a.project_id ',$where,'a.project_id,p.name',$params);
        $costs=self::breakdownRows($pdo,'a.cost_type AS group_key,a.cost_type AS group_label','',$where,'a.cost_type',$params);
        $windowSelect='CASE WHEN a.days_to_close>=15 THEN \'EARLY\' WHEN a.days_to_close>=6 THEN \'MIDDLE\' ELSE \'LATE\' END AS group_key,CASE WHEN a.days_to_close>=15 THEN \'마감 15일 이상 전\' WHEN a.days_to_close>=6 THEN \'마감 6~14일 전\' ELSE \'마감 0~5일 전\' END AS group_label';
        $windowGroup='group_key,group_label';
        $windows=self::breakdownRows($pdo,$windowSelect,'',$where,$windowGroup,$params);
        return array('projects'=>$projects,'cost_types'=>$costs,'close_windows'=>$windows);
    }

    public static function listAccuracy($pdo=null,$filters=array(),$page=1,$perPage=50)
    {
        $pdo=self::pdo($pdo);if(!$pdo||!self::isInstalled($pdo))return array();$page=max(1,(int)$page);$perPage=max(1,min(100,(int)$perPage));
        try{$where=' WHERE 1=1';$params=array();if(isset($filters['target_ym'])&&preg_match('/^\d{4}-\d{2}$/',(string)$filters['target_ym'])){$where.=' AND a.target_ym=:ym';$params[':ym']=$filters['target_ym'];}$latest=' INNER JOIN `'.self::ACTUAL_TABLE.'` ac ON ac.id=a.actual_id INNER JOIN (SELECT target_ym,project_id,cost_type,MAX(actual_version) AS latest_version FROM `'.self::ACTUAL_TABLE.'` GROUP BY target_ym,project_id,cost_type) x ON x.target_ym=ac.target_ym AND x.project_id=ac.project_id AND x.cost_type=ac.cost_type AND x.latest_version=ac.actual_version';$sql='SELECT a.*,p.name AS project_name FROM `'.self::ACCURACY_TABLE.'` a'.$latest.' LEFT JOIN cpms_projects p ON p.id=a.project_id'.$where.' ORDER BY a.target_ym DESC,a.analysis_date DESC,a.id DESC LIMIT :limit OFFSET :offset';$st=$pdo->prepare($sql);if(!$st)return array();foreach($params as $k=>$v)$st->bindValue($k,$v);$st->bindValue(':limit',$perPage,PDO::PARAM_INT);$st->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT);if(!$st->execute())return array();$r=$st->fetchAll(PDO::FETCH_ASSOC);return is_array($r)?$r:array();}catch(Exception $e){return array();}
    }
}
?>
