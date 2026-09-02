<?php
/**
 * CEO Index > 투입비 예측 화면.
 * 과거 월별 실제금액 / 상세공수 작업발생 / 실제 입력시점 학습을 구분해 표시한다.
 * PHP 5.6 compatible.
 */
$ceoForecastContext=$ceoSectionPdo?\App\Services\AiCostForecastV2Service::latestContext($ceoSectionPdo,$ceoTargetYm):array('available'=>false);
$ceoForecastPage=isset($_GET['page'])?max(1,(int)$_GET['page']):1;
$ceoIncludeZero=!empty($_GET['include_zero']);
$ceoForecastFilters=array('include_zero'=>$ceoIncludeZero);
$ceoForecastTotal=$ceoSectionPdo?\App\Services\AiCostForecastV2Service::countResults($ceoSectionPdo,$ceoForecastContext,$ceoForecastFilters):0;
$ceoForecastPages=max(1,(int)ceil($ceoForecastTotal/50));
if($ceoForecastPage>$ceoForecastPages)$ceoForecastPage=$ceoForecastPages;
$ceoForecastRows=$ceoSectionPdo?\App\Services\AiCostForecastV2Service::listResults($ceoSectionPdo,$ceoForecastContext,$ceoForecastFilters,$ceoForecastPage,50):array();
$ceoDetailProject=isset($_GET['project_id'])?(int)$_GET['project_id']:0;
$ceoCategoryRows=$ceoDetailProject>0&&$ceoSectionPdo&&!empty($ceoForecastContext['available'])
    ?\App\Services\AiCostForecastV2Service::categoryRows($ceoSectionPdo,$ceoForecastContext['analysis_date'],$ceoForecastContext['target_ym'],$ceoDetailProject,$ceoForecastContext['run_id'])
    :array();
$ceoCategoryLabels=\App\Services\AiCostForecastV2Service::categoryLabels();

if(!function_exists('cpms_ceo_forecast_method_text')){
    function cpms_ceo_forecast_method_text($value){
        $value=trim((string)$value);
        $map=array(
            'MIXED'=>'복합 예측 적용',
            'COMPLETION_AND_HISTORICAL'=>'입력패턴 + 과거실적',
            'COMPLETION_PATTERN'=>'입력완료율 예측',
            'LABOR_WORK_PATTERN'=>'상세공수 작업패턴 예측',
            'PERIOD_PROGRESS_BASELINE'=>'현재 진행률 기준 예측',
            'HISTORICAL_WITH_CURRENT'=>'현재입력 + 현장 과거실적',
            'HISTORICAL_MEDIAN'=>'현장 과거실적 예측',
            'RECENT_PACE'=>'최근 입력속도 예측',
            'CURRENT_BASELINE'=>'현재 입력 기준',
            'NO_CATEGORY_ACTIVITY'=>'해당 항목 이번달 활동 없음',
            'NO_PROJECT_ACTIVITY'=>'이번달 활동 없음 · 합계 제외',
            'COLD_START'=>'예측자료 부족'
        );
        return isset($map[$value])?$map[$value]:cpms_ceo_label_text($value,false);
    }
}

if(!function_exists('cpms_ceo_forecast_risk_text')){
    function cpms_ceo_forecast_risk_text($grade,$type,$row,$meta){
        $grade=strtoupper(trim((string)$grade));
        $status=isset($row['data_status'])?(string)$row['data_status']:'';
        if($status==='WAITING_ACTIVITY')return '해당 없음';
        if($grade!=='INSUFFICIENT')return cpms_ceo_label_text($grade,false);
        if($type==='missing'){
            if((int)$meta['timing']<=0&&((int)$meta['amount']>0||(int)$meta['work']>0))return '입력시점 학습 중';
            return '입력시점 자료 부족';
        }
        if($type==='over'){
            if((int)$meta['amount']>0)return '금액학습 중';
            return '금액자료 부족';
        }
        return '자료 부족';
    }
}

if(!function_exists('cpms_ceo_forecast_learning_meta')){
    function cpms_ceo_forecast_learning_meta($row){
        $meta=array('amount'=>0,'timing'=>0,'work'=>0,'work_rate'=>null,'lag_samples'=>0,'text'=>'자료 없음');
        if(!is_array($row))return $meta;
        $meta['amount']=isset($row['amount_pattern_month_count'])?(int)$row['amount_pattern_month_count']:0;
        $meta['timing']=isset($row['timing_pattern_month_count'])?(int)$row['timing_pattern_month_count']:(isset($row['sample_count'])?(int)$row['sample_count']:0);
        $decoded=array();
        if(isset($row['candidate_data'])&&is_string($row['candidate_data'])&&trim($row['candidate_data'])!==''){
            $decoded=\App\Services\AiCostForecastV2Service::decode($row['candidate_data']);
        }
        if(isset($decoded['work_pattern_month_count']))$meta['work']=(int)$decoded['work_pattern_month_count'];
        if(isset($decoded['work_occurrence_rate'])&&is_numeric($decoded['work_occurrence_rate']))$meta['work_rate']=(float)$decoded['work_occurrence_rate'];
        if(isset($decoded['lag_sample_count']))$meta['lag_samples']=max(0,(int)$decoded['lag_sample_count']);
        $parts=array();
        if($meta['amount']>0)$parts[]='금액 '.$meta['amount'].'개월';
        if($meta['work']>0)$parts[]='상세공수 '.$meta['work'].'개월';
        if($meta['timing']>0)$parts[]='입력시점 '.$meta['timing'].'개월';
        $meta['text']=count($parts)>0?implode(' · ',$parts):'실제 학습자료 없음';
        return $meta;
    }
}


if(!function_exists('cpms_ceo_forecast_completion_text')){
    function cpms_ceo_forecast_completion_text($row,$meta){
        if(isset($row['data_status'])&&(string)$row['data_status']==='WAITING_ACTIVITY')return '해당 없음';
        if((int)$meta['timing']<=0||!isset($row['expected_completion_rate'])||$row['expected_completion_rate']===null)return '측정자료 없음';
        return cpms_ceo_v2_rate($row['expected_completion_rate']);
    }
}

if(!function_exists('cpms_ceo_forecast_work_text')){
    function cpms_ceo_forecast_work_text($row,$meta){
        if(!isset($row['cost_type'])||(string)$row['cost_type']!=='labor')return '해당 없음';
        if($meta['work_rate']===null)return '측정자료 없음';
        return number_format((float)$meta['work_rate'],1).'%';
    }
}

if(!function_exists('cpms_ceo_forecast_lag_text')){
    function cpms_ceo_forecast_lag_text($row,$meta){
        if(isset($row['data_status'])&&(string)$row['data_status']==='WAITING_ACTIVITY'&&(int)$meta['lag_samples']<=0)return '해당 없음';
        if((int)$meta['lag_samples']<=0||!isset($row['average_input_lag_days'])||$row['average_input_lag_days']===null)return '측정자료 없음';
        return number_format((float)$row['average_input_lag_days'],1).'영업일 · '.(int)$meta['lag_samples'].'건';
    }
}

$ceoAmountLearning=array(
    'month_count'=>isset($ceoLearning['amount_month_count'])?(int)$ceoLearning['amount_month_count']:0,
    'first_ym'=>isset($ceoLearning['amount_first_ym'])?(string)$ceoLearning['amount_first_ym']:'',
    'last_ym'=>isset($ceoLearning['amount_last_ym'])?(string)$ceoLearning['amount_last_ym']:''
);
$ceoTimingLearning=array(
    'month_count'=>isset($ceoLearning['timing_month_count'])?(int)$ceoLearning['timing_month_count']:(isset($ceoLearning['month_count'])?(int)$ceoLearning['month_count']:0),
    'first_ym'=>isset($ceoLearning['timing_first_ym'])?(string)$ceoLearning['timing_first_ym']:(isset($ceoLearning['first_ym'])?(string)$ceoLearning['first_ym']:''),
    'last_ym'=>isset($ceoLearning['timing_last_ym'])?(string)$ceoLearning['timing_last_ym']:(isset($ceoLearning['last_ym'])?(string)$ceoLearning['last_ym']:'')
);
$ceoWorkLearning=array(
    'month_count'=>isset($ceoLearning['work_month_count'])?(int)$ceoLearning['work_month_count']:0,
    'first_ym'=>isset($ceoLearning['work_first_ym'])?(string)$ceoLearning['work_first_ym']:'',
    'last_ym'=>isset($ceoLearning['work_last_ym'])?(string)$ceoLearning['work_last_ym']:''
);
$ceoAmountMonthCount=(int)$ceoAmountLearning['month_count'];
$ceoTimingMonthCount=(int)$ceoTimingLearning['month_count'];
$ceoWorkMonthCount=(int)$ceoWorkLearning['month_count'];
if($ceoAmountMonthCount>0&&$ceoTimingMonthCount>=3)$ceoForecastBasisText='과거 실제금액 + 실제 입력시점 패턴 반영';
else if($ceoAmountMonthCount>0&&$ceoWorkMonthCount>0)$ceoForecastBasisText='과거 실제금액 + 상세공수 작업패턴 반영';
else if($ceoAmountMonthCount>0)$ceoForecastBasisText='현장 과거 실제금액 중심 예측';
else if($ceoTimingMonthCount>=3)$ceoForecastBasisText='입력시점 패턴 중심 예측';
else $ceoForecastBasisText='현재 입력 + 기본/유사현장 참고';
?>
<section class="ceo-v2-card">
 <h3>투입비 예측</h3>
 <p>현재 입력금액, 해당 현장의 과거 월별 실제 마감금액, 날짜별 상세공수의 작업발생 패턴, 실제 일일 입력시점 패턴을 서로 구분해 월말 최종 투입비를 예상합니다.</p>
 <form method="get" class="ceo-v2-filter">
  <input type="hidden" name="r" value="ceo_index">
  <input type="hidden" name="tab" value="forecast">
  <input type="hidden" name="target_ym" value="<?php echo h($ceoTargetYm); ?>">
  <label><input type="checkbox" name="include_zero" value="1"<?php echo $ceoIncludeZero?' checked':''; ?> onchange="this.form.submit()"> 0원 현장 포함</label>
 </form>
 <div class="ceo-v2-scroll">
  <table class="ceo-v2-table">
   <thead><tr><th>현장</th><th>현재 입력</th><th>입력완료율</th><th>미입력 예상</th><th>최종 예상</th><th>예상범위</th><th>분석 신뢰도</th><th>예측 방식</th><th>상세보기</th></tr></thead>
   <tbody>
   <?php if(count($ceoForecastRows)===0): ?>
    <tr><td colspan="9">표시할 투입비 예측결과가 없습니다.</td></tr>
   <?php else:foreach($ceoForecastRows as $row): ?>
    <tr>
     <td><?php echo h(trim((string)$row['project_name_snapshot'])!==''?$row['project_name_snapshot']:'현장정보 확인 필요'); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['current_input_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_rate($row['expected_completion_rate'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['expected_unentered_amount'])); ?></td>
     <td><strong><?php echo h(cpms_ceo_v2_money($row['final_forecast_amount'])); ?></strong></td>
     <td><?php echo h(cpms_ceo_v2_money($row['forecast_low_amount'])); ?> ~ <?php echo h(cpms_ceo_v2_money($row['forecast_high_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_confidence_text($row['forecast_confidence_score'],$row['forecast_confidence_grade'])); ?></td>
     <td><?php echo h(cpms_ceo_forecast_method_text($row['forecast_method'])); ?></td>
     <td><a href="<?php echo h(cpms_ceo_v2_url('forecast',$ceoTargetYm,array('project_id'=>(int)$row['project_id'],'include_zero'=>$ceoIncludeZero?1:0))); ?>">항목별 보기</a></td>
    </tr>
   <?php endforeach;endif; ?>
   </tbody>
  </table>
 </div>
 <?php if($ceoForecastPages>1): ?>
  <div class="ceo-v2-actions" style="margin-top:10px">
   <?php if($ceoForecastPage>1): ?><a class="ceo-v2-btn secondary" href="<?php echo h(cpms_ceo_v2_url('forecast',$ceoTargetYm,array('page'=>$ceoForecastPage-1,'include_zero'=>$ceoIncludeZero?1:0))); ?>">이전</a><?php endif; ?>
   <span><?php echo h($ceoForecastPage.' / '.$ceoForecastPages); ?></span>
   <?php if($ceoForecastPage<$ceoForecastPages): ?><a class="ceo-v2-btn secondary" href="<?php echo h(cpms_ceo_v2_url('forecast',$ceoTargetYm,array('page'=>$ceoForecastPage+1,'include_zero'=>$ceoIncludeZero?1:0))); ?>">다음</a><?php endif; ?>
  </div>
 <?php endif; ?>
</section>

<section class="ceo-v2-card">
 <h3>학습 상태</h3>
 <div class="ceo-v2-grid">
  <div class="ceo-v2-kpi">
   <small>월별 금액 학습</small>
   <strong><?php echo h($ceoAmountMonthCount>0?cpms_ceo_learning_period($ceoAmountLearning):'금액 학습자료 없음'); ?></strong>
  </div>
  <div class="ceo-v2-kpi">
   <small>금액 마감자료</small>
   <strong><?php echo h($ceoAmountMonthCount>0?'최종 실제금액 '.$ceoAmountMonthCount.'개월':'최종 실제금액 없음'); ?></strong>
  </div>
  <div class="ceo-v2-kpi">
   <small>상세공수 작업학습</small>
   <strong><?php echo h($ceoWorkMonthCount>0?cpms_ceo_learning_period($ceoWorkLearning):'상세공수 자료 없음'); ?></strong>
  </div>
  <div class="ceo-v2-kpi">
   <small>입력시점 학습</small>
   <strong><?php echo h($ceoTimingMonthCount>0?cpms_ceo_learning_period($ceoTimingLearning):'입력시점 자료 없음'); ?></strong>
  </div>
  <div class="ceo-v2-kpi">
   <small>현재 예측 기준</small>
   <strong><?php echo h($ceoForecastBasisText); ?></strong>
  </div>
 </div>
 <div class="ceo-v2-note">
  기존 CPMS의 과거 월별 최종 투입비는 즉시 <strong>금액 학습</strong>에 사용합니다.<br>
  월 총액만 넣은 <strong>강제입력</strong>은 금액 학습에만 사용합니다. 인원별로 1일 1공수, 3일 2공수처럼 과거 공수를 직접 복원한 자료는 <strong>실제 근무일 기준 작업발생 패턴</strong>에도 사용합니다.<br>
  과거 상세자료를 오늘 입력했더라도 오늘의 등록시각을 과거 입력지연으로 보지 않습니다. 실제 입력시점 학습은 그 당시 저장된 일일 스냅샷으로만 계산합니다.<br>
  입력시점 표본이 3개월 미만이거나 예상 입력완료율이 너무 낮을 때는 <strong>현재금액 ÷ 입력완료율</strong> 방식의 직접 확대 예측을 사용하지 않습니다.
 </div>
</section>

<?php if($ceoDetailProject>0): ?>
<section class="ceo-v2-card">
 <h3>비용항목별 예측</h3>
 <div class="ceo-v2-scroll">
  <table class="ceo-v2-table">
   <thead><tr><th>비용항목</th><th>현재</th><th>입력완료율</th><th>작업발생 패턴</th><th>미입력 예상</th><th>최종 예상</th><th>범위</th><th>분석 신뢰도</th><th>학습자료</th><th>평균 입력지연(영업일)</th><th>가능성</th><th>적용 기준</th></tr></thead>
   <tbody>
   <?php foreach($ceoCategoryRows as $row): $ceoRowLearning=cpms_ceo_forecast_learning_meta($row); ?>
    <tr>
     <td><?php echo h(isset($ceoCategoryLabels[$row['cost_type']])?$ceoCategoryLabels[$row['cost_type']]:$row['cost_type']); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['current_input_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_forecast_completion_text($row,$ceoRowLearning)); ?></td>
     <td><?php echo h(cpms_ceo_forecast_work_text($row,$ceoRowLearning)); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['expected_unentered_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['final_forecast_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['forecast_low_amount'])); ?> ~ <?php echo h(cpms_ceo_v2_money($row['forecast_high_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_confidence_text($row['forecast_confidence_score'],$row['forecast_confidence_grade'])); ?></td>
     <td><?php echo h($ceoRowLearning['text']); ?></td>
     <td><?php echo h(cpms_ceo_forecast_lag_text($row,$ceoRowLearning)); ?></td>
     <td>과다 <?php echo h(cpms_ceo_forecast_risk_text($row['overinput_grade'],'over',$row,$ceoRowLearning)); ?> · 미입력 <?php echo h(cpms_ceo_forecast_risk_text($row['missing_possibility_grade'],'missing',$row,$ceoRowLearning)); ?></td>
     <td><?php echo h(cpms_ceo_label_text($row['fallback_level'],false)); ?></td>
    </tr>
   <?php endforeach; ?>
   </tbody>
  </table>
 </div>
 <div class="ceo-v2-note">
  현장 자체의 과거 실제금액을 우선 사용하되, <strong>현재 0원이고 해당 비용항목에 이번 달 활동도 없으면 과거금액을 최종예상에 자동으로 넣지 않습니다.</strong><br>
  이 경우 과거금액은 예상범위의 상한 참고값으로만 남습니다. 입찰 진행중·계약중·종료 현장도 이번 달 실제 활동이 없으면 회사 최종예상 합계에서 자동 제외됩니다.<br>
  노무비의 <strong>작업발생 패턴</strong>은 인원별 날짜 공수에서 계산하며, <strong>입력완료율</strong>은 실제 그 당시의 일일 스냅샷에서 계산하므로 서로 다른 값입니다.<br>
  평균 입력지연은 실제 직접입력 건의 사용일·근무일과 저장일을 비교한 <strong>영업일 기준</strong>이며, 입력이 없는 날짜 자체는 지연건으로 만들지 않습니다.
 </div>
</section>
<?php endif; ?>
