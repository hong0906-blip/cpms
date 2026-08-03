<?php
/** AI daily pipeline history. PHP 5.6 compatible. */
use App\Core\Auth;
use App\Core\Db;
use App\Services\AiDailyPipelineService;
require_once __DIR__ . '/../../services/AiDailyPipelineService.php';
if (!Auth::check() || !Auth::isDevelopmentDepartment()) { http_response_code(403); echo '접근 권한이 없습니다.'; return; }
$phPdo=null;try{$phPdo=Db::pdo();}catch(Exception $e){error_log('[AI Pipeline History] db unavailable');}
$phPage=isset($_GET['page'])?max(1,(int)$_GET['page']):1;$phPerPage=50;$phRows=$phPdo?AiDailyPipelineService::listRuns($phPdo,$phPage,$phPerPage):array();$phTotal=$phPdo?AiDailyPipelineService::countRuns($phPdo):0;$phPages=max(1,(int)ceil($phTotal/$phPerPage));
if (!function_exists('cpms_ai_pipeline_duration')) {
    function cpms_ai_pipeline_duration($startedAt, $finishedAt)
    {
        $started = strtotime((string)$startedAt);
        $finished = strtotime((string)$finishedAt);
        if ($started === false || $finished === false || $finished < $started) return '-';
        return number_format($finished - $started) . '초';
    }
}
?>
<style>.ph-wrap{max-width:1200px;margin:0 auto}.ph-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;margin-bottom:14px}.ph-scroll{overflow-x:auto}.ph-table{border-collapse:collapse;width:100%;min-width:900px}.ph-table th,.ph-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:13px}.ph-table th{background:#f8fafc}.ph-badge{border-radius:999px;padding:4px 8px;background:#e2e8f0;font-weight:700}.ph-SUCCESS{background:#dcfce7;color:#166534}.ph-PARTIAL{background:#ffedd5;color:#9a3412}.ph-FAILED{background:#fee2e2;color:#991b1b}.ph-actions{display:flex;gap:8px;flex-wrap:wrap}.ph-btn{display:inline-block;padding:9px 12px;border-radius:9px;background:#eef2ff;color:#3730a3;text-decoration:none;font-weight:700}.ph-steps{margin:8px 0 0;padding-left:18px}.ph-pages{display:flex;gap:12px;justify-content:center;margin-top:14px}</style>
<div class="ph-wrap"><section class="ph-card"><h2>AI 자동 분석 실행 이력</h2><p>각 실행의 단계별 성공·부분완료·실패 상태만 표시하며 SQL·DB 정보는 표시하지 않습니다.</p><div class="ph-actions"><a class="ph-btn" href="?r=admin%2Fai_pipeline_setup">파이프라인 설정</a><a class="ph-btn" href="?r=ceo_index">CEO Index</a></div></section>
<section class="ph-card"><div class="ph-scroll"><table class="ph-table"><thead><tr><th>시작시각</th><th>종료시각</th><th>소요시간</th><th>기준일</th><th>대상월</th><th>실행방식</th><th>상태</th><th>마지막 성공</th><th>실패 단계</th><th>단계 상세</th></tr></thead><tbody><?php if(count($phRows)===0): ?><tr><td colspan="10">저장된 실행 이력이 없습니다.</td></tr><?php else: foreach($phRows as $row): $steps=$phPdo?AiDailyPipelineService::stepsForRun($phPdo,$row['id']):array(); ?><tr><td><?php echo h($row['started_at']); ?></td><td><?php echo h(isset($row['finished_at'])?$row['finished_at']:'-'); ?></td><td><?php echo h(cpms_ai_pipeline_duration($row['started_at'],isset($row['finished_at'])?$row['finished_at']:'')); ?></td><td><?php echo h($row['run_date']); ?></td><td><?php echo h($row['target_ym']); ?></td><td><?php echo h($row['trigger_type']); ?></td><td><span class="ph-badge ph-<?php echo h($row['run_status']); ?>"><?php echo h($row['run_status']); ?></span></td><td><?php echo h($row['last_success_step']); ?></td><td><?php echo h($row['failed_step']); ?></td><td><details><summary>보기</summary><ol class="ph-steps"><?php foreach($steps as $step): ?><li><strong><?php echo h($step['step_name']); ?></strong> — <?php echo h($step['step_status']); ?> / <?php echo h(isset($step['started_at'])?$step['started_at']:'-'); ?> ~ <?php echo h(isset($step['finished_at'])?$step['finished_at']:'-'); ?> / <?php echo h($step['safe_message']); ?></li><?php endforeach; ?></ol></details></td></tr><?php endforeach; endif; ?></tbody></table></div><div class="ph-pages"><?php if($phPage>1): ?><a href="?r=admin%2Fai_pipeline_history&page=<?php echo $phPage-1; ?>">이전</a><?php endif; ?><strong><?php echo $phPage; ?> / <?php echo $phPages; ?></strong><?php if($phPage<$phPages): ?><a href="?r=admin%2Fai_pipeline_history&page=<?php echo $phPage+1; ?>">다음</a><?php endif; ?></div></section></div>
