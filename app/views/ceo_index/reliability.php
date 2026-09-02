<?php
/**
 * CEO Index > 입력 신뢰도.
 *
 * 사용자 화면은 현장별 접기/펼치기로 단순화한다.
 * 입력완료율/작업발생률/학습자료 같은 AI 내부값은 기본표에서 숨기고 '분석 근거'에서만 확인한다.
 * 입력지연/반복수정 등 평가성 지표는 반드시 해당 현장+비용항목의 실제 자료만 표시한다.
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
            'work'=>0,'work_rate'=>null,'operational'=>0,
            'lag_samples'=>0,'same_day_rate'=>null,'within_one_rate'=>null,'late_two_plus_rate'=>null,
            'lag_basis'=>'','lag_origin'=>'','lag_scope'=>'','text'=>'자료 없음'
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
        $meta['text']=count($parts)>0?implode(' · ',$parts):'학습자료 없음';
        return $meta;
    }
}

if(!function_exists('cpms_ceo_reliability_state_text')){
    function cpms_ceo_reliability_state_text($row,$meta){
        $status=isset($row['data_status'])?(string)$row['data_status']:'';
        if($status==='WAITING_ACTIVITY'&&(int)$meta['lag_samples']<=0)return '해당 없음';
        if((int)$meta['lag_samples']<=0){
            if((int)$meta['amount']>0||(int)$meta['work']>0||(int)$meta['timing']>0)return '측정자료 없음';
            return '자료 없음';
        }
        $late=$meta['late_two_plus_rate'];
        $correction=isset($row['correction_rate'])&&$row['correction_rate']!==null?(float)$row['correction_rate']:null;
        if(($late!==null&&(float)$late>=35)||($correction!==null&&(float)$correction>=35))return '확인 필요';
        if((int)$meta['lag_samples']<3)return '표본 부족';
        return '정상';
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
        if((int)$meta['lag_samples']<=0||!isset($row['average_input_lag_days'])||$row['average_input_lag_days']===null)return '측정자료 없음';
        return number_format((float)$row['average_input_lag_days'],1).'영업일';
    }
}
if(!function_exists('cpms_ceo_reliability_lag_sample_text')){
    function cpms_ceo_reliability_lag_sample_text($meta){
        $count=(int)$meta['lag_samples'];
        if($count<=0)return '측정자료 없음';
        if($count<3)return $count.'건 · 표본 부족';
        return number_format($count).'건';
    }
}
if(!function_exists('cpms_ceo_reliability_late_rate_text')){
    function cpms_ceo_reliability_late_rate_text($row,$meta){
        if((int)$meta['lag_samples']<=0||$meta['late_two_plus_rate']===null)return '측정자료 없음';
        return number_format((float)$meta['late_two_plus_rate'],1).'%';
    }
}
if(!function_exists('cpms_ceo_reliability_correction_text')){
    function cpms_ceo_reliability_correction_text($row,$meta){
        if((int)$meta['lag_samples']<=0)return '측정자료 없음';
        if(!isset($row['correction_rate'])||$row['correction_rate']===null)return '측정자료 없음';
        return cpms_ceo_v2_rate($row['correction_rate']);
    }
}
if(!function_exists('cpms_ceo_reliability_basis_text')){
    function cpms_ceo_reliability_basis_text($row,$meta){
        if((int)$meta['lag_samples']>0||(!empty($meta['lag_scope'])&&$meta['lag_scope']==='PROJECT_CATEGORY'))return '현장 자체';
        return '측정자료 없음';
    }
}
if(!function_exists('cpms_ceo_reliability_status_class')){
    function cpms_ceo_reliability_status_class($text){
        if($text==='정상')return 'is-ok';
        if($text==='확인 필요')return 'is-warn';
        if($text==='표본 부족')return 'is-info';
        return 'is-muted';
    }
}

/* 현장별 그룹 생성 */
$ceoRelGroups=array();
foreach($ceoRelCategories as $row){
    $pid=(int)$row['project_id'];
    if(!isset($ceoRelGroups[$pid]))$ceoRelGroups[$pid]=array('name'=>cpms_ceo_project_name($ceoRelProjectMap,$pid),'rows'=>array(),'measurable'=>0,'need_check'=>0,'has_current'=>0);
    $meta=cpms_ceo_reliability_meta($row);
    $state=cpms_ceo_reliability_state_text($row,$meta);
    if((int)$meta['lag_samples']>0)$ceoRelGroups[$pid]['measurable']++;
    if($state==='확인 필요')$ceoRelGroups[$pid]['need_check']++;
    if(isset($row['current_input_amount'])&&(float)$row['current_input_amount']>0)$ceoRelGroups[$pid]['has_current']=1;
    $ceoRelGroups[$pid]['rows'][]=array('row'=>$row,'meta'=>$meta,'state'=>$state);
}
$ceoRelProjectCount=count($ceoRelGroups);
$ceoRelMeasurableProjects=0;
$ceoRelCheckProjects=0;
foreach($ceoRelGroups as $group){
    if((int)$group['measurable']>0)$ceoRelMeasurableProjects++;
    if((int)$group['need_check']>0)$ceoRelCheckProjects++;
}
?>
<style>
.ceo-rel-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 14px}.ceo-rel-btn{border:1px solid #cbd5e1;background:#fff;border-radius:9px;padding:7px 11px;font-size:13px;cursor:pointer}.ceo-rel-project{border:1px solid #dbe3ed;border-radius:12px;background:#fff;margin:10px 0;overflow:hidden}.ceo-rel-project summary{list-style:none;cursor:pointer;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f8fafc}.ceo-rel-project summary::-webkit-details-marker{display:none}.ceo-rel-title{font-weight:700;min-width:0}.ceo-rel-badges{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.ceo-rel-badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:12px;background:#e2e8f0;color:#334155}.ceo-rel-badge.warn{background:#fef3c7;color:#92400e}.ceo-rel-badge.ok{background:#dcfce7;color:#166534}.ceo-rel-badge.muted{background:#f1f5f9;color:#64748b}.ceo-rel-inner{padding:0 12px 12px;overflow-x:auto}.ceo-rel-table{width:100%;border-collapse:collapse;min-width:880px}.ceo-rel-table th,.ceo-rel-table td{border-bottom:1px solid #e5e7eb;padding:10px 9px;text-align:left;vertical-align:top;font-size:13px}.ceo-rel-table th{background:#fff;color:#475569;font-weight:700}.ceo-rel-state{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700}.ceo-rel-state.is-ok{background:#dcfce7;color:#166534}.ceo-rel-state.is-warn{background:#fee2e2;color:#991b1b}.ceo-rel-state.is-info{background:#dbeafe;color:#1d4ed8}.ceo-rel-state.is-muted{background:#f1f5f9;color:#64748b}.ceo-rel-evidence summary{cursor:pointer;color:#2563eb;font-size:12px}.ceo-rel-evidence-box{margin-top:7px;padding:8px 10px;border-radius:8px;background:#f8fafc;line-height:1.7;white-space:nowrap}.ceo-rel-arrow{font-size:13px;color:#64748b}.ceo-rel-project[open] .ceo-rel-arrow{transform:rotate(180deg)}
</style>
<section class="ceo-v2-card">
 <h3>입력 신뢰도</h3>
 <div class="ceo-v2-grid">
  <div class="ceo-v2-kpi"><small>분석 현장</small><strong><?php echo h(number_format($ceoRelProjectCount).'개'); ?></strong></div>
  <div class="ceo-v2-kpi"><small>지연 측정 가능 현장</small><strong><?php echo h(number_format($ceoRelMeasurableProjects).'개'); ?></strong></div>
  <div class="ceo-v2-kpi"><small>확인 필요 현장</small><strong><?php echo h(number_format($ceoRelCheckProjects).'개'); ?></strong></div>
  <div class="ceo-v2-kpi"><small>최근 분석시각</small><strong style="font-size:15px"><?php echo h(isset($ceoSummary['last_calculated_at'])?$ceoSummary['last_calculated_at']:'자료 없음'); ?></strong></div>
 </div>
</section>

<section class="ceo-v2-card">
 <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
  <h3 style="margin:0">현장별 입력 현황</h3>
  <div class="ceo-rel-toolbar"><button type="button" class="ceo-rel-btn" onclick="cpmsCeoRelToggle(true)">전체 펼치기</button><button type="button" class="ceo-rel-btn" onclick="cpmsCeoRelToggle(false)">전체 접기</button></div>
 </div>
 <?php if(count($ceoRelGroups)===0): ?>
  <div class="ceo-v2-note">표시할 입력자료가 없습니다.</div>
 <?php else:foreach($ceoRelGroups as $pid=>$group): ?>
  <details class="ceo-rel-project">
   <summary>
    <span class="ceo-rel-title"><?php echo h($group['name']); ?></span>
    <span class="ceo-rel-badges">
     <?php if(empty($group['has_current'])): ?><span class="ceo-rel-badge muted">이번 달 실제 입력 없음</span><?php endif; ?>
     <span class="ceo-rel-badge <?php echo (int)$group['measurable']>0?'ok':'muted'; ?>">측정 가능 <?php echo h((string)(int)$group['measurable']); ?>개</span>
     <?php if((int)$group['need_check']>0): ?><span class="ceo-rel-badge warn">확인 필요 <?php echo h((string)(int)$group['need_check']); ?>개</span><?php endif; ?>
     <span class="ceo-rel-arrow">▼</span>
    </span>
   </summary>
   <div class="ceo-rel-inner">
    <table class="ceo-rel-table">
     <thead><tr><th>비용항목</th><th>분석 상태</th><th>평균 입력지연</th><th>지연측정</th><th>2영업일 이상</th><th>반복수정</th><th>자료 기준</th><th>분석 근거</th></tr></thead>
     <tbody>
     <?php foreach($group['rows'] as $item): $row=$item['row'];$meta=$item['meta'];$state=$item['state']; ?>
      <tr>
       <td><?php echo h(isset($ceoCategoryLabels[$row['cost_type']])?$ceoCategoryLabels[$row['cost_type']]:$row['cost_type']); ?></td>
       <td><span class="ceo-rel-state <?php echo h(cpms_ceo_reliability_status_class($state)); ?>"><?php echo h($state); ?></span></td>
       <td><?php echo h(cpms_ceo_reliability_lag_text($row,$meta)); ?></td>
       <td><?php echo h(cpms_ceo_reliability_lag_sample_text($meta)); ?></td>
       <td><?php echo h(cpms_ceo_reliability_late_rate_text($row,$meta)); ?></td>
       <td><?php echo h(cpms_ceo_reliability_correction_text($row,$meta)); ?></td>
       <td><?php echo h(cpms_ceo_reliability_basis_text($row,$meta)); ?></td>
       <td>
        <details class="ceo-rel-evidence"><summary>보기</summary><div class="ceo-rel-evidence-box">
         입력완료율: <?php echo h(cpms_ceo_reliability_completion_text($row,$meta)); ?><br>
         작업발생률: <?php echo h(cpms_ceo_reliability_work_text($row,$meta)); ?><br>
         학습자료: <?php echo h($meta['text']); ?><br>
         예측 적용기준: <?php echo h(cpms_ceo_label_text(isset($row['fallback_level'])?$row['fallback_level']:'',false)); ?>
        </div></details>
       </td>
      </tr>
     <?php endforeach; ?>
     </tbody>
    </table>
   </div>
  </details>
 <?php endforeach;endif; ?>
 <div class="ceo-v2-note">
  입력지연은 실제로 등록된 건의 사용일·근무일과 시스템 저장일을 영업일 기준으로 비교합니다. 입력이 없던 날짜 자체는 지연으로 계산하지 않습니다.<br>
  토요일·일요일과 CPMS 공휴일/대체공휴일은 제외하며, 월 총액 강제입력·과거자료 이관·과거 복원입력의 등록시각·관리자/승인 보정·자동계산은 지연측정에서 제외합니다.
 </div>
</section>
<script>
function cpmsCeoRelToggle(opened){
 var nodes=document.querySelectorAll('.ceo-rel-project');
 for(var i=0;i<nodes.length;i++)nodes[i].open=!!opened;
}
</script>
