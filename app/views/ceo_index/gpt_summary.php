<?php
/**
 * 파일: app/views/ceo_index/gpt_summary.php
 * 화면: CEO Index > GPT 요약
 *
 * 최신 정상 투입비 예측과 동일한 원본으로 생성된 GPT 요약만 표시한다.
 * 같은 날짜에 예측을 재실행한 경우 예전 요약 숫자가 남아 대표 화면과 달라지는 것을 차단한다.
 * PHP 5.6 호환.
 */
$ceoBrief=$ceoSectionPdo&&$ceoV2Ready?\App\Services\AiExecutiveBriefService::latestV2Brief($ceoSectionPdo,$ceoTargetYm):array();
$ceoBriefStale=false;
$ceoBriefSource=array();

if(!empty($ceoBrief)){
    $ceoBriefSource=\App\Services\AiExecutiveBriefService::decodeData(isset($ceoBrief['source_summary_data'])?$ceoBrief['source_summary_data']:'');
    $sourceAnalysisDate=isset($ceoBriefSource['analysis_date'])?(string)$ceoBriefSource['analysis_date']:'';
    $sourceTargetYm=isset($ceoBriefSource['target_ym'])?(string)$ceoBriefSource['target_ym']:'';
    $sourceFinalForecast=null;
    $sourceCurrentInput=null;

    if(isset($ceoBriefSource['company_metrics'])&&is_array($ceoBriefSource['company_metrics'])){
        foreach($ceoBriefSource['company_metrics'] as $metric){
            if(!is_array($metric)||!isset($metric['metric_id']))continue;
            $metricId=(string)$metric['metric_id'];
            $rawValue=array_key_exists('raw_value',$metric)?$metric['raw_value']:(array_key_exists('value',$metric)?$metric['value']:null);
            if($metricId==='company.final_forecast_total'&&is_numeric($rawValue))$sourceFinalForecast=(float)$rawValue;
            if($metricId==='company.current_input_total'&&is_numeric($rawValue))$sourceCurrentInput=(float)$rawValue;
        }
    }

    $currentAnalysisDate=isset($ceoSummary['analysis_date'])?(string)$ceoSummary['analysis_date']:'';
    $currentTargetYm=isset($ceoSummary['target_ym'])?(string)$ceoSummary['target_ym']:'';
    $currentFinalForecast=isset($ceoSummary['final_forecast_total'])&&is_numeric($ceoSummary['final_forecast_total'])?(float)$ceoSummary['final_forecast_total']:null;
    $currentInput=isset($ceoSummary['current_input_total'])&&is_numeric($ceoSummary['current_input_total'])?(float)$ceoSummary['current_input_total']:null;

    if($sourceAnalysisDate===''||$sourceTargetYm===''||$sourceAnalysisDate!==$currentAnalysisDate||$sourceTargetYm!==$currentTargetYm){
        $ceoBriefStale=true;
    }
    if(!$ceoBriefStale&&($sourceFinalForecast===null||$currentFinalForecast===null||abs($sourceFinalForecast-$currentFinalForecast)>1.0)){
        $ceoBriefStale=true;
    }
    if(!$ceoBriefStale&&($sourceCurrentInput===null||$currentInput===null||abs($sourceCurrentInput-$currentInput)>1.0)){
        $ceoBriefStale=true;
    }
}

/* 오래된 GPT 요약은 화면에 숫자/문장을 노출하지 않는다. */
if($ceoBriefStale)$ceoBrief=array();

$ceoBriefRisks=\App\Services\AiExecutiveBriefService::decodeData(isset($ceoBrief['top_risks_data'])?$ceoBrief['top_risks_data']:'');
$ceoBriefChecks=\App\Services\AiExecutiveBriefService::decodeData(isset($ceoBrief['check_today_data'])?$ceoBrief['check_today_data']:'');
$ceoBriefPositive=\App\Services\AiExecutiveBriefService::decodeData(isset($ceoBrief['positive_signals_data'])?$ceoBrief['positive_signals_data']:'');
$ceoBriefLimits=\App\Services\AiExecutiveBriefService::decodeData(isset($ceoBrief['data_limitations_data'])?$ceoBrief['data_limitations_data']:'');
$ceoBriefEvidence=\App\Services\AiExecutiveBriefService::briefEvidenceCards($ceoBrief);
$ceoBriefProjectIds=array();
foreach($ceoBriefRisks as $row)if(is_array($row)&&isset($row['project_id']))$ceoBriefProjectIds[]=(int)$row['project_id'];
$ceoBriefProjectMap=cpms_ceo_project_name_map($ceoSectionPdo,$ceoBriefProjectIds);
?>
<section class="ceo-v2-card">
 <h3>GPT 요약</h3>
 <?php if(!$ceoV2Ready): ?>
  <div class="ceo-v2-note">신규 투입비 예측이 아직 실행되지 않았습니다.<br>자동 파이프라인 또는 개발부서 수동 분석을 먼저 실행해주세요.</div>
 <?php elseif($ceoBriefStale): ?>
  <div class="ceo-v2-note">저장된 GPT 요약이 현재 최신 투입비 예측 결과와 달라 표시하지 않았습니다.<br>아래 <strong>최신 투입비 자료로 GPT 요약 생성</strong>을 눌러 현재 결과로 다시 생성해주세요.</div>
 <?php elseif(empty($ceoBrief)): ?>
  <div class="ceo-v2-note"><?php echo h($ceoSummary['analysis_date']); ?> 투입비 예측자료의 GPT 요약이 아직 생성되지 않았습니다.</div>
 <?php else: ?>
  <small><?php echo h($ceoBrief['generated_at']); ?> · 원본 분석일 <?php echo h($ceoBrief['analysis_date']); ?></small>
  <h3><?php echo h($ceoBrief['headline']); ?></h3>
  <p><?php echo nl2br(h($ceoBrief['executive_summary'])); ?></p>
  <?php if(count($ceoBriefEvidence)): ?>
   <div class="ceo-v2-grid">
    <?php foreach($ceoBriefEvidence as $card): ?>
     <div class="ceo-v2-kpi"><small><?php echo h(isset($card['label'])?$card['label']:'근거'); ?></small><strong><?php echo h(isset($card['display_value'])&&$card['display_value']!==''?$card['display_value']:'-'); ?></strong></div>
    <?php endforeach; ?>
   </div>
  <?php endif; ?>
  <div class="ceo-v2-grid">
   <div class="ceo-v2-kpi"><small>주요 위험현장</small><ul class="ceo-v2-list"><?php foreach($ceoBriefRisks as $row): ?><li><?php echo h(cpms_ceo_project_name($ceoBriefProjectMap,isset($row['project_id'])?$row['project_id']:0)); ?> · <?php echo h(isset($row['title'])?$row['title']:'확인 필요'); ?></li><?php endforeach; ?></ul></div>
   <div class="ceo-v2-kpi"><small>오늘 확인할 내용</small><ul class="ceo-v2-list"><?php foreach($ceoBriefChecks as $row): ?><li><?php echo h($row); ?></li><?php endforeach; ?></ul></div>
   <div class="ceo-v2-kpi"><small>긍정 신호</small><ul class="ceo-v2-list"><?php foreach($ceoBriefPositive as $row): ?><li><?php echo h($row); ?></li><?php endforeach; ?></ul></div>
   <div class="ceo-v2-kpi"><small>자료 한계</small><ul class="ceo-v2-list"><?php foreach($ceoBriefLimits as $row): ?><li><?php echo h($row); ?></li><?php endforeach; ?></ul></div>
  </div>
 <?php endif; ?>
 <?php if($ceoSectionDeveloper&&$ceoV2Ready): ?>
  <form method="post" class="ceo-v2-actions">
   <input type="hidden" name="_csrf" value="<?php echo h($ceoCsrf); ?>">
   <input type="hidden" name="action" value="generate_summary">
   <input type="hidden" name="force" value="1">
   <button class="ceo-v2-btn">최신 투입비 자료로 GPT 요약 생성</button>
  </form>
 <?php endif; ?>
 <div class="ceo-v2-note">GPT는 현재 최신 정상 투입비 예측과 일치하는 자료만 설명하며, 수치는 PHP 근거 카드에서 표시합니다.</div>
</section>
