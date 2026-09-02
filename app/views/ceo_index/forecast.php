<?php
/**
 * CEO Index > 투입비 예측 화면.
 * 과거 월별 실제 금액 학습과 입력시점 학습을 구분해 표시한다.
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
$ceoAmountMonthCount=(int)$ceoAmountLearning['month_count'];
$ceoTimingMonthCount=(int)$ceoTimingLearning['month_count'];
if($ceoAmountMonthCount>0&&$ceoTimingMonthCount>=3)$ceoForecastBasisText='현장 과거 실제금액 + 입력시점 패턴 반영';
else if($ceoAmountMonthCount>0)$ceoForecastBasisText='현장 과거 실제금액 중심 예측';
else if($ceoTimingMonthCount>=3)$ceoForecastBasisText='입력시점 패턴 중심 예측';
else $ceoForecastBasisText='현재 입력 + 기본/유사현장 참고';
?>
<section class="ceo-v2-card">
 <h3>투입비 예측</h3>
 <p>현재 입력금액, 해당 현장의 과거 월별 실제 마감금액, 신뢰 가능한 입력시점 패턴을 함께 사용해 월말 최종 투입비를 예상합니다.</p>
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
     <td><?php echo h(cpms_ceo_label_text($row['forecast_method'],false)); ?></td>
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
   <small>입력시점 학습</small>
   <strong><?php echo h($ceoTimingMonthCount>0?cpms_ceo_learning_period($ceoTimingLearning):'입력시점 자료 없음'); ?></strong>
  </div>
  <div class="ceo-v2-kpi">
   <small>현재 예측 기준</small>
   <strong><?php echo h($ceoForecastBasisText); ?></strong>
  </div>
 </div>
 <div class="ceo-v2-note">
  기존 CPMS에 이미 입력된 과거 월별 최종 투입비는 즉시 <strong>금액 학습</strong>에 사용합니다.<br>
  2026년 7월 이전에 이관·강제입력한 자료는 최종 금액만 사용하며, 입력일·수정일을 과거 입력패턴으로 간주하지 않습니다.<br>
  입력시점 표본이 3개월 미만이거나 예상 입력완료율이 너무 낮을 때는 <strong>현재금액 ÷ 입력완료율</strong> 방식의 직접 확대 예측을 사용하지 않습니다.
 </div>
</section>

<?php if($ceoDetailProject>0): ?>
<section class="ceo-v2-card">
 <h3>비용항목별 예측</h3>
 <div class="ceo-v2-scroll">
  <table class="ceo-v2-table">
   <thead><tr><th>비용항목</th><th>현재</th><th>입력완료율</th><th>미입력 예상</th><th>최종 예상</th><th>범위</th><th>분석 신뢰도</th><th>평균 입력지연</th><th>가능성</th><th>적용 기준</th></tr></thead>
   <tbody>
   <?php foreach($ceoCategoryRows as $row): ?>
    <tr>
     <td><?php echo h(isset($ceoCategoryLabels[$row['cost_type']])?$ceoCategoryLabels[$row['cost_type']]:$row['cost_type']); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['current_input_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_rate($row['expected_completion_rate'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['expected_unentered_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['final_forecast_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_v2_money($row['forecast_low_amount'])); ?> ~ <?php echo h(cpms_ceo_v2_money($row['forecast_high_amount'])); ?></td>
     <td><?php echo h(cpms_ceo_confidence_text($row['forecast_confidence_score'],$row['forecast_confidence_grade'])); ?></td>
     <td><?php echo h($row['average_input_lag_days']===null?'-':number_format((float)$row['average_input_lag_days'],1).'일'); ?></td>
     <td>과다 <?php echo h(cpms_ceo_label_text($row['overinput_grade'],false)); ?> · 미입력 <?php echo h(cpms_ceo_label_text($row['missing_possibility_grade'],false)); ?></td>
     <td><?php echo h(cpms_ceo_label_text($row['fallback_level'],false)); ?></td>
    </tr>
   <?php endforeach; ?>
   </tbody>
  </table>
 </div>
 <div class="ceo-v2-note">
  현장 자체의 과거 실제금액을 우선 사용합니다. 회사 전체의 절대 금액 중앙값을 개별 현장 예상금액으로 그대로 적용하지 않습니다. 현장 자체 금액자료가 없을 때만 유사현장·기본예측·신뢰 가능한 입력시점 패턴을 보조자료로 사용합니다.
 </div>
</section>
<?php endif; ?>
