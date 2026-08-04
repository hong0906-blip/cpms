<?php
/* 이 파일은 CEO Index 본문에서 가장 먼저 실행된다. 이후 모든 탭의 표시 헬퍼를 여기서 보장한다. */
if(!function_exists('cpms_ceo_label_text')){
    function cpms_ceo_label_text($code,$developer){
        $labels=array(
            'INSUFFICIENT'=>'분석자료 부족','COLD_START'=>'학습자료 없음','INITIAL'=>'초기학습','INITIAL_EXPANDED'=>'초기학습 확대','NORMAL_LEARNING'=>'정상학습',
            'BASIC_FORECAST'=>'기본 예측 적용','COMPLETION_PATTERN'=>'입력패턴 예측','COMPLETION_AND_PACE'=>'입력패턴·최근 흐름 예측','COMPLETION_AND_HISTORICAL'=>'입력패턴·과거자료 예측',
            'HISTORICAL_MEDIAN'=>'과거 중간값 예측','RECENT_PACE'=>'최근 입력흐름 예측','COMPANY_CATEGORY_FALLBACK'=>'회사 비용항목 자료 참고',
            'PROJECT_CATEGORY'=>'현장 비용항목 자료','PROJECT_ALL'=>'현장 전체 자료','COMPANY_CATEGORY'=>'회사 비용항목 자료 참고','COMPANY_ALL'=>'회사 전체 자료 참고',
            'MIXED'=>'복합 예측 적용','READY'=>'분석 준비','LIMITED'=>'자료 제한적','NORMAL'=>'정상','WATCH'=>'관심','WARNING'=>'주의','CRITICAL'=>'위험',
            'LOW'=>'낮음','MEDIUM'=>'보통','HIGH'=>'높음','VERY_LOW'=>'낮음','SUCCESS'=>'정상 완료','COMPLETED'=>'정상 완료','PARTIAL'=>'일부 완료',
            'FAILED'=>'실행 실패','SKIPPED'=>'실행 생략','RUNNING'=>'실행 중','PENDING'=>'실행 대기','CACHED'=>'저장 결과 사용','NOT_RUN'=>'실행 전','MISSING'=>'자료 없음',
            'ANSWERED'=>'답변 완료','NOT_AVAILABLE'=>'확인 불가','REFUSED'=>'답변 제한'
        );
        $labels['AMOUNT_ONLY']='과거 금액자료만 반영';
        $labels['SAME_PROJECT_TYPE_MEDIAN']='같은 현장유형 자료 참고';
        $labels['LIVE_EMPLOYEE_INPUT']='실제 직원 입력';
        $labels['HISTORICAL_MIGRATION']='과거 이관 자료';
        $labels['UNKNOWN_REVIEW']='자료 확인 필요';
        $code=trim((string)$code);if($code==='')return '';$known=isset($labels[$code]);$label=$known?$labels[$code]:$code;
        if(!$developer&&!$known&&preg_match('/^[A-Z0-9_]+$/',$code))return '확인 필요';
        return $developer&&$label!==$code?$label.' ('.$code.')':$label;
    }
}
if(!function_exists('cpms_ceo_confidence_text')){
    function cpms_ceo_confidence_text($score,$grade){
        $hasScore=$score!==null&&$score!==''&&is_numeric($score);$grade=trim((string)$grade);
        if($grade==='INSUFFICIENT'||(!$hasScore&&$grade===''))return '산정 불가';
        $label=cpms_ceo_label_text($grade,false);
        return $label!==''?$label:'산정 불가';
    }
}
if(!function_exists('cpms_ceo_project_name_map')){
    function cpms_ceo_project_name_map($pdo,$projectIds){
        $map=array();if(!$pdo||!is_array($projectIds))return $map;$ids=array();foreach($projectIds as $id){$id=(int)$id;if($id>0)$ids[$id]=$id;}
        if(count($ids)===0)return $map;$holders=array();$params=array();$index=0;foreach($ids as $id){$key=':p'.$index++;$holders[]=$key;$params[$key]=$id;}
        try{$st=$pdo->prepare('SELECT id,name FROM cpms_projects WHERE id IN ('.implode(',',$holders).')');if(!$st||!$st->execute($params))return $map;$rows=$st->fetchAll(PDO::FETCH_ASSOC);if(!is_array($rows))return $map;foreach($rows as $row)$map[(int)$row['id']]=trim((string)$row['name'])!==''?(string)$row['name']:'현장정보 확인 필요';}catch(Exception $e){return array();}
        return $map;
    }
}
if(!function_exists('cpms_ceo_project_name')){
    function cpms_ceo_project_name($map,$projectId){$projectId=(int)$projectId;return isset($map[$projectId])&&trim((string)$map[$projectId])!==''?(string)$map[$projectId]:'현장정보 확인 필요';}
}
if(!function_exists('cpms_ceo_learning_period')){
    function cpms_ceo_learning_period($summary){
        if(!is_array($summary)||empty($summary['month_count']))return '학습자료 없음';$first=isset($summary['first_ym'])?(string)$summary['first_ym']:'';$last=isset($summary['last_ym'])?(string)$summary['last_ym']:'';
        $firstLabel=preg_match('/^(\d{4})-(\d{2})$/',$first,$firstMatch)?$firstMatch[1].'년 '.(int)$firstMatch[2].'월':'';$lastLabel=preg_match('/^(\d{4})-(\d{2})$/',$last,$lastMatch)?$lastMatch[1].'년 '.(int)$lastMatch[2].'월':'';
        if($firstLabel!==''&&$first===$last)return $firstLabel;if($firstLabel!==''&&$lastLabel!=='')return $firstLabel.' ~ '.$lastLabel;return (int)$summary['month_count'].'개월';
    }
}

$ceoSummaryConfidenceScore=isset($ceoSummary['confidence_score'])?$ceoSummary['confidence_score']:null;
$ceoSummaryConfidenceGrade=isset($ceoSummary['confidence_grade'])?$ceoSummary['confidence_grade']:'';
$ceoSummaryConfidenceText='산정 불가';
if($ceoSummaryConfidenceGrade!=='INSUFFICIENT'&&$ceoSummaryConfidenceScore!==null&&$ceoSummaryConfidenceScore!==''&&is_numeric($ceoSummaryConfidenceScore)){
    $ceoSummaryConfidenceText=cpms_ceo_label_text($ceoSummaryConfidenceGrade,false);
}elseif($ceoSummaryConfidenceGrade!==''&&$ceoSummaryConfidenceGrade!=='INSUFFICIENT'){
    $ceoSummaryConfidenceText=cpms_ceo_label_text($ceoSummaryConfidenceGrade,false);
}
?>
<section class="ceo-v2-card"><div class="ceo-v2-grid">
 <div class="ceo-v2-kpi"><small>CEO Index</small><strong><?php echo h(cpms_ceo_v2_score(isset($ceoIndexV2['ceo_index_score'])?$ceoIndexV2['ceo_index_score']:null)); ?></strong><span class="ceo-v2-badge"><?php echo h(isset($ceoIndexV2['ceo_index_grade'])?\App\Services\AiCeoIndexService::gradeLabel($ceoIndexV2['ceo_index_grade']):'분석 전'); ?></span></div>
 <div class="ceo-v2-kpi"><small>현재 입력 투입비</small><strong><?php echo h(cpms_ceo_v2_money(isset($ceoSummary['current_input_total'])?$ceoSummary['current_input_total']:null)); ?></strong></div>
 <div class="ceo-v2-kpi"><small>최종 예상 투입비</small><strong><?php echo h(cpms_ceo_v2_money(isset($ceoSummary['final_forecast_total'])?$ceoSummary['final_forecast_total']:null)); ?></strong><small><?php echo h(cpms_ceo_v2_money(isset($ceoSummary['forecast_low_total'])?$ceoSummary['forecast_low_total']:null)); ?> ~ <?php echo h(cpms_ceo_v2_money(isset($ceoSummary['forecast_high_total'])?$ceoSummary['forecast_high_total']:null)); ?></small></div>
 <div class="ceo-v2-kpi"><small>예측 신뢰도</small><strong style="font-size:16px"><?php echo h($ceoSummaryConfidenceText); ?></strong><small><?php echo h(isset($ceoLearning['state']['label'])?$ceoLearning['state']['label']:'학습자료 없음'); ?></small></div>
 <div class="ceo-v2-kpi"><small>예상 입력완료율</small><strong><?php echo h(cpms_ceo_v2_rate(isset($ceoSummary['expected_completion_rate'])?$ceoSummary['expected_completion_rate']:null)); ?></strong></div>
 <div class="ceo-v2-kpi"><small>미입력 예상금액</small><strong><?php echo h(cpms_ceo_v2_money(isset($ceoSummary['expected_unentered_total'])?$ceoSummary['expected_unentered_total']:null)); ?></strong></div>
 <div class="ceo-v2-kpi"><small>긴급 확인 현장</small><strong><?php echo isset($ceoIndexV2['critical_count'])?h((int)$ceoIndexV2['critical_count'].'개'):'-'; ?></strong></div>
 <div class="ceo-v2-kpi"><small>대상 월 · 최근 분석</small><strong><?php echo h($ceoTargetYm); ?></strong><small><?php echo h(isset($ceoSummary['last_calculated_at'])&&$ceoSummary['last_calculated_at']!==''?$ceoSummary['last_calculated_at']:'최신 분석 없음'); ?></small></div>
</div></section>
