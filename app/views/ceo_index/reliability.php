<?php
/**
 * CEO Index > 입력 신뢰도.
 * 금액 학습 / 상세공수 작업학습 / 실제 입력시점 학습을 분리해 보여준다.
 * PHP 5.6 compatible.
 */
$ceoRelContext=$ceoSectionPdo?\App\Services\AiCostForecastV2Service::latestContext($ceoSectionPdo,$ceoTargetYm):array('available'=>false);
$ceoRelCategories=!empty($ceoRelContext['available'])
    ?\App\Services\AiCostForecastV2Service::categoryRows($ceoSectionPdo,$ceoRelContext['analysis_date'],$ceoRelContext['target_ym'],0,$ceoRelContext['run_id'])
    :array();
$ceoCategoryLabels=\App\Services\AiCostForecastV2Service::categoryLabels();
$ceoRelIds=array();
foreach($ceoRelCategories as $row)$ceoRelIds[]=(int)$row['project_id'];
$ceoRelProjectMap=cpms_ceo_project_name_map($ceoSectionPdo,$ceoRelIds);

if(!function_exists('cpms_ceo_reliability_meta')){
    function cpms_ceo_reliability_meta($row){
        $meta=array(
            'amount'=>isset($row['amount_pattern_month_count'])?(int)$row['amount_pattern_month_count']:0,
            'timing'=>isset($row['timing_pattern_month_count'])?(int)$row['timing_pattern_month_count']:(isset($row['sample_count'])?(int)$row['sample_count']:0),
            'work'=>0,
            'work_rate'=>null,
            'operational'=>0,
            'text'=>'자료 없음'
        );
        $decoded=array();
        if(isset($row['candidate_data'])&&is_string($row['candidate_data'])&&trim($row['candidate_data'])!==''){
            $decoded=\App\Services\AiCostForecastV2Service::decode($row['candidate_data']);
        }
        if(isset($decoded['work_pattern_month_count']))$meta['work']=(int)$decoded['work_pattern_month_count'];
        if(isset($decoded['work_occurrence_rate'])&&is_numeric($decoded['work_occurrence_rate']))$meta['work_rate']=(float)$decoded['work_occurrence_rate'];
        if(isset($decoded['operational_input_sample_count']))$meta['operational']=(int)$decoded['operational_input_sample_count'];
        $parts=array();
        if($meta['amount']>0)$parts[]='금액 '.$meta['amount'].'개월';
        if($meta['work']>0)$parts[]='상세공수 '.$meta['work'].'개월';
        if($meta['timing']>0)$parts[]='입력시점 '.$meta['timing'].'개월';
        $meta['text']=count($parts)>0?implode(' · ',$parts):'실제 학습자료 없음';
        return $meta;
    }
}

if(!function_exists('cpms_ceo_reliability_state_text')){
    function cpms_ceo_reliability_state_text($row,$meta){
        $status=isset($row['data_status'])?(string)$row['data_status']:'';
        if($status==='WAITING_ACTIVITY')return '해당 없음';
        if(isset($row['forecast_confidence_score'])&&$row['forecast_confidence_score']!==null){
            return cpms_ceo_confidence_text($row['forecast_confidence_score'],isset($row['forecast_confidence_grade'])?$row['forecast_confidence_grade']:'');
        }
        if((int)$meta['work']>0&&((int)$meta['amount']>0||(int)$meta['timing']>0))return '부분 학습';
        if((int)$meta['amount']>0)return '금액자료만 있음';
        if((int)$meta['work']>0)return '상세공수자료 있음';
        if((int)$meta['timing']>0)return '입력시점 학습 중';
        return '자료 없음';
    }
}

$ceoRelAmountMonths=isset($ceoLearning['amount_month_count'])?(int)$ceoLearning['amount_month_count']:0;
$ceoRelWorkMonths=isset($ceoLearning['work_month_count'])?(int)$ceoLearning['work_month_count']:0;
$ceoRelTimingMonths=isset($ceoLearning['timing_month_count'])?(int)$ceoLearning['timing_month_count']:(isset($ceoLearning['month_count'])?(int)$ceoLearning['month_count']:0);
?>
<section class="ceo-v2-card">
 <h3>입력 신뢰도</h3>
 <div class="ceo-v2-grid">
  <div class="ceo-v2-kpi"><small>회사 예상 입력완료율</small><strong><?php echo h(cpms_ceo_v2_rate(isset($ceoSummary['expected_completion_rate'])?$ceoSummary['expected_completion_rate']:null)); ?></strong></div>
  <div class="ceo-v2-kpi"><small>회사 분석 신뢰도</small><strong><?php echo h(cpms_ceo_confidence_text(isset($ceoSummary['confidence_score'])?$ceoSummary['confidence_score']:null,isset($ceoSummary['confidence_grade'])?$ceoSummary['confidence_grade']:'')); ?></strong></div>
  <div class="ceo-v2-kpi"><small>금액 학습</small><strong><?php echo h($ceoRelAmountMonths.'개월'); ?></strong></div>
  <div class="ceo-v2-kpi"><small>상세공수 작업학습</small><strong><?php echo h($ceoRelWorkMonths.'개월'); ?></strong></div>
  <div class="ceo-v2-kpi"><small>입력시점 학습</small><strong><?php echo h($ceoRelTimingMonths.'개월'); ?></strong></div>
  <div class="ceo-v2-kpi"><small>최근 분석시각</small><strong style="font-size:15px"><?php echo h(isset($ceoSummary['last_calculated_at'])?$ceoSummary['last_calculated_at']:'-'); ?></strong></div>
 </div>
 <div class="ceo-v2-note">
  <strong>금액 학습</strong>은 월 최종 투입비를, <strong>상세공수 작업학습</strong>은 인원별 실제 근무일·공수 분포를, <strong>입력시점 학습</strong>은 당시 시스템에 얼마까지 입력되어 있었는지를 뜻합니다.<br>
  월 총액 강제입력은 금액 학습에만 사용합니다. 과거 인원과 날짜별 공수를 직접 복원한 자료는 상세공수 작업학습에도 사용하지만, 오늘의 등록시간을 과거 입력지연으로 사용하지 않습니다.
 </div>
</section>

<section class="ceo-v2-card">
 <h3>비용항목 입력패턴</h3>
 <div class="ceo-v2-scroll">
  <table class="ceo-v2-table">
   <thead><tr><th>현장</th><th>비용항목</th><th>입력완료율</th><th>작업발생률</th><th>분석 상태</th><th>학습자료</th><th>평균 등록지연</th><th>일괄입력 비율</th><th>반복수정 비율</th><th>적용 기준</th></tr></thead>
   <tbody>
   <?php if(count($ceoRelCategories)===0): ?>
    <tr><td colspan="10">표시할 입력패턴 자료가 없습니다.</td></tr>
   <?php else:foreach($ceoRelCategories as $row): $ceoRelMeta=cpms_ceo_reliability_meta($row); ?>
    <tr>
     <td><?php echo h(cpms_ceo_project_name($ceoRelProjectMap,$row['project_id'])); ?></td>
     <td><?php echo h(isset($ceoCategoryLabels[$row['cost_type']])?$ceoCategoryLabels[$row['cost_type']]:$row['cost_type']); ?></td>
     <td><?php echo h((int)$ceoRelMeta['timing']>0?cpms_ceo_v2_rate($row['expected_completion_rate']):'-'); ?></td>
     <td><?php echo h($ceoRelMeta['work_rate']===null?'-':number_format((float)$ceoRelMeta['work_rate'],1).'%'); ?></td>
     <td><?php echo h(cpms_ceo_reliability_state_text($row,$ceoRelMeta)); ?></td>
     <td><?php echo h($ceoRelMeta['text']); ?></td>
     <td><?php echo h($row['average_input_lag_days']===null?'-':number_format((float)$row['average_input_lag_days'],1).'일'); ?></td>
     <td><?php echo h(cpms_ceo_v2_rate($row['late_bulk_rate'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_rate($row['correction_rate'])); ?></td>
     <td><?php echo h(cpms_ceo_label_text($row['fallback_level'],false)); ?></td>
    </tr>
   <?php endforeach;endif; ?>
   </tbody>
  </table>
 </div>
 <div class="ceo-v2-note">
  입력완료율이 -이더라도 금액 또는 상세공수 학습이 있으면 예측 자체가 모두 불가능하다는 뜻은 아닙니다. 입력완료율은 <strong>실제 당시 일일 스냅샷이 있는 월</strong>에서만 계산합니다.<br>
  노무비의 과거 상세공수는 별도로 <strong>작업발생률</strong>에 반영되므로, 7월 이전 과거 공수를 나중에 직접 복원해도 예측자료로 사용할 수 있습니다.
 </div>
</section>
