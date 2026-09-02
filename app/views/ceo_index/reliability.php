<?php
/**
 * CEO Index > 입력 신뢰도.
 * 금액 학습 / 상세공수 작업학습 / 실제 입력시점 학습 / 실제 직접입력 지연을 분리해 보여준다.
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
            'lag_samples'=>0,
            'same_day_rate'=>null,
            'within_one_rate'=>null,
            'late_two_plus_rate'=>null,
            'lag_basis'=>'',
            'lag_origin'=>'',
            'lag_scope'=>'',
            'text'=>'자료 없음'
        );
        $decoded=array();
        if(isset($row['candidate_data'])&&is_string($row['candidate_data'])&&trim($row['candidate_data'])!==''){
            $decoded=\App\Services\AiCostForecastV2Service::decode($row['candidate_data']);
        }
        if(isset($decoded['work_pattern_month_count']))$meta['work']=(int)$decoded['work_pattern_month_count'];
        if(isset($decoded['work_occurrence_rate'])&&is_numeric($decoded['work_occurrence_rate']))$meta['work_rate']=(float)$decoded['work_occurrence_rate'];
        if(isset($decoded['operational_input_sample_count']))$meta['operational']=(int)$decoded['operational_input_sample_count'];
        if(isset($decoded['lag_sample_count']))$meta['lag_samples']=max(0,(int)$decoded['lag_sample_count']);
        if(isset($decoded['same_day_input_rate'])&&is_numeric($decoded['same_day_input_rate']))$meta['same_day_rate']=(float)$decoded['same_day_input_rate'];
        if(isset($decoded['within_one_business_day_rate'])&&is_numeric($decoded['within_one_business_day_rate']))$meta['within_one_rate']=(float)$decoded['within_one_business_day_rate'];
        if(isset($decoded['late_two_plus_input_rate'])&&is_numeric($decoded['late_two_plus_input_rate']))$meta['late_two_plus_rate']=(float)$decoded['late_two_plus_input_rate'];
        if(isset($decoded['input_lag_basis']))$meta['lag_basis']=(string)$decoded['input_lag_basis'];
        if(isset($decoded['input_lag_origin']))$meta['lag_origin']=(string)$decoded['input_lag_origin'];
        if(isset($decoded['input_lag_scope']))$meta['lag_scope']=(string)$decoded['input_lag_scope'];
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

if(!function_exists('cpms_ceo_reliability_completion_text')){
    function cpms_ceo_reliability_completion_text($row,$meta){
        if(isset($row['data_status'])&&(string)$row['data_status']==='WAITING_ACTIVITY')return '해당 없음';
        if((int)$meta['timing']<=0||!isset($row['expected_completion_rate'])||$row['expected_completion_rate']===null)return '측정자료 없음';
        return cpms_ceo_v2_rate($row['expected_completion_rate']);
    }
}

if(!function_exists('cpms_ceo_reliability_work_text')){
    function cpms_ceo_reliability_work_text($row,$meta){
        $cost=isset($row['cost_type'])?(string)$row['cost_type']:'';
        if($cost!=='labor')return '해당 없음';
        if($meta['work_rate']===null)return '측정자료 없음';
        return number_format((float)$meta['work_rate'],1).'%';
    }
}

if(!function_exists('cpms_ceo_reliability_lag_text')){
    function cpms_ceo_reliability_lag_text($row,$meta){
        if(isset($row['data_status'])&&(string)$row['data_status']==='WAITING_ACTIVITY'&&(int)$meta['lag_samples']<=0)return '해당 없음';
        if((int)$meta['lag_samples']<=0||!isset($row['average_input_lag_days'])||$row['average_input_lag_days']===null)return '측정자료 없음';
        $text=number_format((float)$row['average_input_lag_days'],1).'영업일';
        $fallback=strtoupper(trim((string)($meta['lag_scope']!==''?$meta['lag_scope']:(isset($row['fallback_level'])?$row['fallback_level']:''))));
        if($fallback==='PROJECT_ALL')$text.=' · 현장 전체 참고';
        else if($fallback==='COMPANY_CATEGORY')$text.=' · 회사 비용항목 참고';
        else if($fallback==='COMPANY_ALL')$text.=' · 회사 전체 참고';
        return $text;
    }
}

if(!function_exists('cpms_ceo_reliability_lag_sample_text')){
    function cpms_ceo_reliability_lag_sample_text($meta){
        $count=(int)$meta['lag_samples'];
        if($count<=0)return '측정자료 없음';
        if($count<3)return $count.'건 · 표본 부족';
        return $count.'건';
    }
}

if(!function_exists('cpms_ceo_reliability_late_rate_text')){
    function cpms_ceo_reliability_late_rate_text($row,$meta){
        if((int)$meta['lag_samples']<=0||$meta['late_two_plus_rate']===null){
            if(isset($row['data_status'])&&(string)$row['data_status']==='WAITING_ACTIVITY')return '해당 없음';
            return '측정자료 없음';
        }
        return number_format((float)$meta['late_two_plus_rate'],1).'%';
    }
}

if(!function_exists('cpms_ceo_reliability_correction_text')){
    function cpms_ceo_reliability_correction_text($row){
        if(isset($row['data_status'])&&(string)$row['data_status']==='WAITING_ACTIVITY'&&(!isset($row['event_count'])||(int)$row['event_count']<=0))return '해당 없음';
        if(!isset($row['correction_rate'])||$row['correction_rate']===null)return '측정자료 없음';
        return cpms_ceo_v2_rate($row['correction_rate']);
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
  <div class="ceo-v2-kpi"><small>최근 분석시각</small><strong style="font-size:15px"><?php echo h(isset($ceoSummary['last_calculated_at'])?$ceoSummary['last_calculated_at']:'자료 없음'); ?></strong></div>
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
   <thead><tr><th>현장</th><th>비용항목</th><th>입력완료율</th><th>작업발생률</th><th>분석 상태</th><th>학습자료</th><th>평균 입력지연</th><th>지연측정</th><th>2영업일 이상 지연</th><th>반복수정 비율</th><th>적용 기준</th></tr></thead>
   <tbody>
   <?php if(count($ceoRelCategories)===0): ?>
    <tr><td colspan="11">표시할 입력패턴 자료가 없습니다.</td></tr>
   <?php else:foreach($ceoRelCategories as $row): $ceoRelMeta=cpms_ceo_reliability_meta($row); ?>
    <tr>
     <td><?php echo h(cpms_ceo_project_name($ceoRelProjectMap,$row['project_id'])); ?></td>
     <td><?php echo h(isset($ceoCategoryLabels[$row['cost_type']])?$ceoCategoryLabels[$row['cost_type']]:$row['cost_type']); ?></td>
     <td><?php echo h(cpms_ceo_reliability_completion_text($row,$ceoRelMeta)); ?></td>
     <td><?php echo h(cpms_ceo_reliability_work_text($row,$ceoRelMeta)); ?></td>
     <td><?php echo h(cpms_ceo_reliability_state_text($row,$ceoRelMeta)); ?></td>
     <td><?php echo h($ceoRelMeta['text']); ?></td>
     <td><?php echo h(cpms_ceo_reliability_lag_text($row,$ceoRelMeta)); ?></td>
     <td><?php echo h(cpms_ceo_reliability_lag_sample_text($ceoRelMeta)); ?></td>
     <td><?php echo h(cpms_ceo_reliability_late_rate_text($row,$ceoRelMeta)); ?></td>
     <td><?php echo h(cpms_ceo_reliability_correction_text($row)); ?></td>
     <td><?php echo h(cpms_ceo_label_text($row['fallback_level'],false)); ?></td>
    </tr>
   <?php endforeach;endif; ?>
   </tbody>
  </table>
 </div>
 <div class="ceo-v2-note">
  입력지연은 <strong>실제로 등록된 건</strong>의 사용일·근무일과 시스템 저장일을 비교해서 계산합니다. 아무 입력도 없는 날짜 자체를 지연으로 만들지 않습니다.<br>
  같은 날 입력은 0영업일이며, 토요일·일요일과 CPMS 공휴일/대체공휴일은 지연 영업일에서 제외합니다. <strong>월 총액 강제입력·과거자료 이관·과거 복원입력의 등록시각·관리자/승인 보정·자동계산</strong>은 평균 입력지연에서 제외합니다.<br>
  동일한 지연값이 여러 현장에 보일 때는 마지막 <strong>적용 기준</strong>을 함께 확인하세요. 현장 자체 측정자료가 부족하면 회사 비용항목 등의 참고 패턴이 표시될 수 있습니다.
 </div>
</section>
