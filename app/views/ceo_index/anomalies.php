<?php
/**
 * CEO Index > 위험·이상징후.
 * 실제 위험 신호와 단순 학습부족을 분리해 표시한다.
 * PHP 5.6 compatible.
 */
$ceoAnContext=$ceoSectionPdo?\App\Services\AiCostForecastV2Service::latestContext($ceoSectionPdo,$ceoTargetYm):array('available'=>false);
$ceoAnRows=!empty($ceoAnContext['available'])
    ?\App\Services\AiCostForecastV2Service::categoryRows($ceoSectionPdo,$ceoAnContext['analysis_date'],$ceoAnContext['target_ym'],0,$ceoAnContext['run_id'])
    :array();
$ceoCategoryLabels=\App\Services\AiCostForecastV2Service::categoryLabels();
$ceoAnIds=array();
foreach($ceoAnRows as $row)$ceoAnIds[]=(int)$row['project_id'];
$ceoAnProjectMap=cpms_ceo_project_name_map($ceoSectionPdo,$ceoAnIds);

if(!function_exists('cpms_ceo_anomaly_learning_meta')){
    function cpms_ceo_anomaly_learning_meta($row){
        $meta=array(
            'amount'=>isset($row['amount_pattern_month_count'])?(int)$row['amount_pattern_month_count']:0,
            'timing'=>isset($row['timing_pattern_month_count'])?(int)$row['timing_pattern_month_count']:(isset($row['sample_count'])?(int)$row['sample_count']:0),
            'work'=>0
        );
        if(isset($row['candidate_data'])&&is_string($row['candidate_data'])&&trim($row['candidate_data'])!==''){
            $decoded=\App\Services\AiCostForecastV2Service::decode($row['candidate_data']);
            if(isset($decoded['work_pattern_month_count']))$meta['work']=(int)$decoded['work_pattern_month_count'];
        }
        return $meta;
    }
}

if(!function_exists('cpms_ceo_anomaly_grade_text')){
    function cpms_ceo_anomaly_grade_text($grade,$type,$row,$meta){
        $grade=strtoupper(trim((string)$grade));
        $status=isset($row['data_status'])?(string)$row['data_status']:'';
        if($status==='WAITING_ACTIVITY')return '해당 없음';
        if($grade!=='INSUFFICIENT')return cpms_ceo_label_text($grade,false);
        if($type==='missing'){
            if((int)$meta['timing']<=0&&((int)$meta['amount']>0||(int)$meta['work']>0))return '입력시점 학습 중';
            return '입력시점 자료 부족';
        }
        if($type==='over'){
            if((int)$meta['amount']<=0)return '금액자료 부족';
            return '금액학습 중';
        }
        return '자료 부족';
    }
}

if(!function_exists('cpms_ceo_anomaly_recommendation')){
    function cpms_ceo_anomaly_recommendation($row,$meta){
        $over=isset($row['overinput_grade'])?strtoupper((string)$row['overinput_grade']):'INSUFFICIENT';
        $missing=isset($row['missing_possibility_grade'])?strtoupper((string)$row['missing_possibility_grade']):'INSUFFICIENT';
        $bulk=isset($row['late_bulk_rate'])&&is_numeric($row['late_bulk_rate'])?(float)$row['late_bulk_rate']:0.0;
        $correction=isset($row['correction_rate'])&&is_numeric($row['correction_rate'])?(float)$row['correction_rate']:0.0;
        $move=isset($row['month_move_rate'])&&is_numeric($row['month_move_rate'])?(float)$row['month_move_rate']:0.0;
        if($over==='CRITICAL'||$over==='WARNING')return '예상금액과 현장 실제 투입내역을 우선 확인';
        if($missing==='HIGH'||$missing==='MEDIUM')return '현재 입력누락 또는 늦은 입력 여부 확인';
        if($bulk>=35)return '월말 일괄입력 여부와 원본 자료 확인';
        if($correction>=35)return '반복 수정 원인과 최종 확정자료 확인';
        if($move>=15)return '귀속월 변경 이력 확인';
        if((int)$meta['timing']<=0&&((int)$meta['amount']>0||(int)$meta['work']>0))return '입력시점 학습이 쌓이면 미입력 판단이 개선됩니다.';
        return '평소 입력시점과 비용항목 자료 확인 권장';
    }
}
?>
<section class="ceo-v2-card">
 <h3>위험·이상징후</h3>
 <p>비용 급증·미입력 가능성·늦은 일괄입력·반복수정처럼 <strong>실제 확인할 신호가 있는 항목만</strong> 우선 표시합니다. 단순히 학습자료가 적다는 이유만으로 이상징후 목록에 올리지 않습니다.</p>
 <div class="ceo-v2-scroll">
  <table class="ceo-v2-table">
   <thead><tr><th>현장</th><th>비용항목</th><th>과다투입 가능성</th><th>미입력 가능성</th><th>늦은 일괄입력</th><th>반복 수정</th><th>귀속월 변경</th><th>확인 권장</th></tr></thead>
   <tbody>
   <?php
   $shown=0;
   foreach($ceoAnRows as $row):
       $status=isset($row['data_status'])?(string)$row['data_status']:'';
       $current=isset($row['current_input_amount'])?(float)$row['current_input_amount']:0.0;
       $forecast=isset($row['final_forecast_amount'])?(float)$row['final_forecast_amount']:0.0;
       if($status==='WAITING_ACTIVITY'&&$current==0.0&&$forecast==0.0)continue;

       $over=isset($row['overinput_grade'])?strtoupper((string)$row['overinput_grade']):'INSUFFICIENT';
       $missing=isset($row['missing_possibility_grade'])?strtoupper((string)$row['missing_possibility_grade']):'INSUFFICIENT';
       $bulk=isset($row['late_bulk_rate'])&&is_numeric($row['late_bulk_rate'])?(float)$row['late_bulk_rate']:0.0;
       $correction=isset($row['correction_rate'])&&is_numeric($row['correction_rate'])?(float)$row['correction_rate']:0.0;
       $move=isset($row['month_move_rate'])&&is_numeric($row['month_move_rate'])?(float)$row['month_move_rate']:0.0;
       $hasSignal=in_array($over,array('WATCH','WARNING','CRITICAL'),true)
           ||in_array($missing,array('MEDIUM','HIGH'),true)
           ||$bulk>=35.0||$correction>=35.0||$move>=15.0;
       if(!$hasSignal)continue;
       $shown++;
       $ceoAnMeta=cpms_ceo_anomaly_learning_meta($row);
   ?>
    <tr>
     <td><?php echo h(cpms_ceo_project_name($ceoAnProjectMap,$row['project_id'])); ?></td>
     <td><?php echo h(isset($ceoCategoryLabels[$row['cost_type']])?$ceoCategoryLabels[$row['cost_type']]:$row['cost_type']); ?></td>
     <td><span class="ceo-v2-badge"><?php echo h(cpms_ceo_anomaly_grade_text($over,'over',$row,$ceoAnMeta)); ?></span></td>
     <td><span class="ceo-v2-badge"><?php echo h(cpms_ceo_anomaly_grade_text($missing,'missing',$row,$ceoAnMeta)); ?></span></td>
     <td><?php echo h(cpms_ceo_v2_rate($row['late_bulk_rate'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_rate($row['correction_rate'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_rate($row['month_move_rate'])); ?></td>
     <td><?php echo h(cpms_ceo_anomaly_recommendation($row,$ceoAnMeta)); ?></td>
    </tr>
   <?php endforeach;if($shown===0): ?>
    <tr><td colspan="8">현재 표시할 우선 이상징후가 없습니다.</td></tr>
   <?php endif; ?>
   </tbody>
  </table>
 </div>
 <div class="ceo-v2-note">
  자료 부족 자체는 위험으로 간주하지 않고 <strong>입력 신뢰도</strong> 탭에서 금액·상세공수·입력시점 학습현황으로 확인합니다. 이 화면은 실제 이상 가능성이 있는 항목의 확인 순서를 정하기 위한 참고 화면입니다.
 </div>
</section>
