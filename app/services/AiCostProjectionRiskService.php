<?php
/** Forecast V2 derived risk helpers. PHP 5.6 compatible. */
namespace App\Services;

use App\Core\Db;
use PDO;
use Exception;

class AiCostProjectionRiskService
{
    public static function clamp($value,$min,$max)
    {
        return max((float)$min,min((float)$max,(float)$value));
    }

    public static function overinputGrade($current,$forecast,$historicalMedian,$completionRate)
    {
        if($historicalMedian===null||!is_numeric($historicalMedian)||(float)$historicalMedian<=0)return 'INSUFFICIENT';
        $basis=max((float)$historicalMedian,1);$rate=(float)$forecast/$basis*100;
        if($rate>=160)return 'CRITICAL';if($rate>=130)return 'WARNING';if($rate>=115)return 'WATCH';
        if((float)$completionRate<35&&(float)$current/$basis*100>=90)return 'WATCH';
        return 'NORMAL';
    }

    public static function missingPossibilityGrade($completionRate,$progressRate,$eventCount,$averageLagDays)
    {
        if($completionRate===null||$progressRate===null)return 'INSUFFICIENT';
        $gap=(float)$progressRate-(float)$completionRate;$score=0;
        if($gap>=45)$score+=3;else if($gap>=25)$score+=2;else if($gap>=12)$score++;
        if((int)$eventCount===0&&(float)$progressRate>=35)$score+=2;
        if($averageLagDays!==null&&(float)$averageLagDays>=10)$score++;
        if($score>=4)return 'HIGH';if($score>=2)return 'MEDIUM';return 'LOW';
    }

    public static function contractRisk($cumulativeProjected,$contractAmount,$confidenceScore)
    {
        if($contractAmount===null||!is_numeric($contractAmount)||(float)$contractAmount<=0)return array('rate'=>null,'grade'=>'INSUFFICIENT','label'=>'계약 기준자료 부족');
        $rate=(float)$cumulativeProjected/(float)$contractAmount*100;
        if($confidenceScore===null)return array('rate'=>round($rate,3),'grade'=>'INSUFFICIENT','label'=>'계산 신뢰도 부족');
        if($rate>=100)return array('rate'=>round($rate,3),'grade'=>'HIGH','label'=>'계약금액 소진 가능성 높음');
        if($rate>=90)return array('rate'=>round($rate,3),'grade'=>'WARNING','label'=>'계약금액 소진 확인 필요');
        if($rate>=80)return array('rate'=>round($rate,3),'grade'=>'WATCH','label'=>'계약금액 대비 투입 흐름 확인');
        return array('rate'=>round($rate,3),'grade'=>'LOW','label'=>'현재 범위 내');
    }

    public static function anomalyTypes($category)
    {
        $types=array();
        if(isset($category['missing_possibility_grade'])&&$category['missing_possibility_grade']==='HIGH')$types[]='INPUT_BELOW_EXPECTED_CURVE';
        if(isset($category['overinput_grade'])&&in_array($category['overinput_grade'],array('WARNING','CRITICAL'),true))$types[]='INPUT_ABOVE_EXPECTED_CURVE';
        if(isset($category['late_bulk_rate'])&&$category['late_bulk_rate']!==null&&(float)$category['late_bulk_rate']>=35)$types[]='LATE_BULK_ENTRY';
        if(isset($category['correction_rate'])&&$category['correction_rate']!==null&&(float)$category['correction_rate']>=35)$types[]='REPEATED_LATE_CORRECTION';
        if(isset($category['month_move_rate'])&&$category['month_move_rate']!==null&&(float)$category['month_move_rate']>=15)$types[]='COMPLETION_RATE_DROP';
        return $types;
    }

    public static function summarizeProject($categories,$cumulativeProjected,$contractAmount,$confidenceScore)
    {
        $over=array('NORMAL'=>0,'WATCH'=>0,'WARNING'=>0,'CRITICAL'=>0,'INSUFFICIENT'=>0);
        $missing=array('LOW'=>0,'MEDIUM'=>0,'HIGH'=>0,'INSUFFICIENT'=>0);$types=array();
        foreach($categories as $row){$og=isset($row['overinput_grade'])?$row['overinput_grade']:'INSUFFICIENT';$mg=isset($row['missing_possibility_grade'])?$row['missing_possibility_grade']:'INSUFFICIENT';if(isset($over[$og]))$over[$og]++;if(isset($missing[$mg]))$missing[$mg]++;foreach(self::anomalyTypes($row) as $type)$types[$type]=true;}
        $overGrade=$over['CRITICAL']>0?'CRITICAL':($over['WARNING']>0?'WARNING':($over['WATCH']>0?'WATCH':($over['NORMAL']>0?'NORMAL':'INSUFFICIENT')));
        $missingGrade=$missing['HIGH']>0?'HIGH':($missing['MEDIUM']>0?'MEDIUM':($missing['LOW']>0?'LOW':'INSUFFICIENT'));
        return array('overinput_grade'=>$overGrade,'missing_possibility_grade'=>$missingGrade,'anomaly_types'=>array_keys($types),'contract_risk'=>self::contractRisk($cumulativeProjected,$contractAmount,$confidenceScore));
    }

    public static function analyzeLatest($pdo=null,$triggerType='SYSTEM')
    {
        $pdo=$pdo?$pdo:Db::pdo();
        if(!$pdo)return array('ok'=>false,'status'=>'FAILED','projects'=>0,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        try{
            $st=$pdo->prepare("SHOW TABLES LIKE :table");
            if(!$st||!$st->execute(array(':table'=>'cpms_ai_cost_forecast_results_v2'))||$st->fetchColumn()===false)return array('ok'=>false,'status'=>'FAILED','projects'=>0,'message'=>'V2 예측결과가 아직 없습니다.');
            $st=$pdo->query('SELECT analysis_date,target_ym FROM cpms_ai_cost_forecast_results_v2 ORDER BY analysis_date DESC,id DESC LIMIT 1');
            $context=$st?$st->fetch(PDO::FETCH_ASSOC):false;
            if(!is_array($context))return array('ok'=>false,'status'=>'FAILED','projects'=>0,'message'=>'V2 예측결과가 아직 없습니다.');
            $st=$pdo->prepare('SELECT COUNT(*) FROM cpms_ai_cost_forecast_results_v2 WHERE analysis_date=:date AND target_ym=:ym');
            $count=$st&&$st->execute(array(':date'=>$context['analysis_date'],':ym'=>$context['target_ym']))?(int)$st->fetchColumn():0;
            return array('ok'=>$count>0,'status'=>$count>0?'COMPLETED':'FAILED','projects'=>$count,'success'=>$count,'failed'=>0,'message'=>$count>0?'V2 예측에 저장된 과다투입·미입력 가능성 분석을 확인했습니다.':'V2 위험분석 결과가 없습니다.');
        }catch(Exception $e){error_log('[AI Projection Risk] status failed');return array('ok'=>false,'status'=>'FAILED','projects'=>0,'message'=>'V2 위험분석 상태를 확인하지 못했습니다.');}
    }
}
?>
