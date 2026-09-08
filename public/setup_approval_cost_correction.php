<?php
/**
 * 파일경로: public/setup_approval_cost_correction.php
 * 기능: 전자결재 비용 수정/누락 기능 설치/업데이트 (V7)
 *
 * V7 변경
 * - 노무비: 공수 원본이 아니라 공사 > 노무비 월별 인원 기준으로 전체 근로자 조회
 * - 노무비: 월별 등록 인원 + 해당 월 실제 출역 인원을 합쳐 누락 없이 표시
 * - 직원명부에 지정된 team_leader_id는 부서가 달라도 최우선 사용
 * - 안전/보건/품질 기존 부서그룹 예외 규칙은 그대로 유지
 * - 상단 4개 비용버튼 -> "비용 수정/누락 신청" 1개
 * - 작성화면을 기존 전자결재 종이문서 레이아웃으로 통합
 * - 업체 검색 자동완성 추가
 * - 상세/인쇄에서도 전용 종이문서 형식 유지
 * - 기존 최종승인 자동반영 로직 유지
 * PHP 5.6 호환
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/CostChangeService.php';
require_once __DIR__ . '/../app/services/ApprovalCostCorrectionService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;

if (!Auth::check()) { header('Location: ./?r=login'); exit; }
$role = method_exists('App\\Core\\Auth','userRole') ? (string)Auth::userRole() : '';
if (!(Auth::isMaster() || Auth::canManageEmployees() || $role === 'executive')) { http_response_code(403); echo '403 Forbidden'; exit; }

$pdo = Db::pdo();
$root = realpath(__DIR__ . '/..');
$paths = array(
    'index' => $root ? $root . '/app/views/approval/index.php' : '',
    'create' => $root ? $root . '/app/views/approval/create.php' : '',
    'proposal' => $root ? $root . '/app/views/approval/template_proposal.php' : '',
    'decide' => $root ? $root . '/app/views/approval/decide.php' : '',
    'detail' => $root ? $root . '/app/views/approval/detail.php' : '',
    'line_rules' => $root ? $root . '/app/views/approval/line_rules.php' : '',
    'service' => $root ? $root . '/app/services/ApprovalCostCorrectionService.php' : '',
    'screen' => $root ? $root . '/app/views/approval/cost_correction_create.php' : '',
    'template' => $root ? $root . '/app/views/approval/template_cost_correction.php' : '',
    'endpoint' => $root ? $root . '/public/approval_cost_correction.php' : ''
);
$results=array();

if (!function_exists('ecc_setup_h')) { function ecc_setup_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} }
if (!function_exists('ecc_setup_result')) { function ecc_setup_result(&$r,$n,$ok,$m){$r[]=array('name'=>(string)$n,'ok'=>$ok?1:0,'message'=>(string)$m);} }
if (!function_exists('ecc_setup_write')) {
function ecc_setup_write($path,$content){
    $tmp=dirname($path).'/.'.basename($path).'.costcorr_'.uniqid('',true).'.tmp';
    if(@file_put_contents($tmp,$content,LOCK_EX)===false){@unlink($tmp);return false;}
    if(@rename($tmp,$path))return true;
    $ok=@file_put_contents($path,$content,LOCK_EX)!==false;@unlink($tmp);return $ok;
}}
if (!function_exists('ecc_setup_backup')) {
function ecc_setup_backup($path){$b=$path.'.bak_cost_correction_'.date('Ymd_His');return @copy($path,$b)?$b:'';}
}
if (!function_exists('ecc_setup_apply_file_patch')) {
function ecc_setup_apply_file_patch($path,$label,$patcher,&$results){
    if(!is_file($path)){ecc_setup_result($results,$label,false,'기존 파일을 찾을 수 없습니다.');return;}
    $src=@file_get_contents($path);if(!is_string($src)){ecc_setup_result($results,$label,false,'기존 파일을 읽지 못했습니다.');return;}
    $changed=false;$message='';$new=call_user_func_array($patcher,array($src,&$changed,&$message));
    if($new===false){ecc_setup_result($results,$label,false,$message);return;}
    if(!$changed){ecc_setup_result($results,$label,true,$message);return;}
    $backup=ecc_setup_backup($path);if($backup===''){ecc_setup_result($results,$label,false,'백업에 실패하여 수정을 중단했습니다.');return;}
    if(!ecc_setup_write($path,$new)){@copy($backup,$path);ecc_setup_result($results,$label,false,'수정 저장에 실패하여 백업으로 복원했습니다.');return;}
    ecc_setup_result($results,$label,true,$message.' 백업: '.basename($backup));
}}

if (!function_exists('ecc_patch_index_v2')) {
function ecc_patch_index_v2($src,&$changed,&$message){
    $changed=false;$start='<!-- CPMS_APPROVAL_COST_CORRECTION_BUTTONS_START -->';$end='<!-- CPMS_APPROVAL_COST_CORRECTION_BUTTONS_END -->';
    $block='                '.$start."\n"
        .'                <a class="inline-flex items-center justify-center whitespace-nowrap shrink-0 min-w-max px-4 py-2 rounded-xl bg-white text-rose-700 font-extrabold" href="?r=approval_create&type=cost_correction">비용 수정/누락 신청</a>'."\n"
        .'                '.$end."\n";
    $sp=strpos($src,$start);$ep=strpos($src,$end);
    if($sp!==false&&$ep!==false&&$ep>$sp){
        $lineStart=strrpos(substr($src,0,$sp),"\n");$lineStart=$lineStart===false?0:$lineStart+1;
        $after=$ep+strlen($end);if(isset($src[$after])&&$src[$after]==="\r")$after++;if(isset($src[$after])&&$src[$after]==="\n")$after++;
        $old=substr($src,$lineStart,$after-$lineStart);
        if($old===$block){$message='비용 신청 버튼이 이미 1개로 정리되어 있습니다.';return $src;}
        $changed=true;$message='기존 비용 신청 버튼 4개를 1개로 정리했습니다.';return substr($src,0,$lineStart).$block.substr($src,$after);
    }
    $needle='            <div class="flex flex-wrap items-center justify-start xl:justify-end gap-3 shrink-0 max-w-none">'."\n";
    $pos=strpos($src,$needle);if($pos===false){$message='전자결재 홈 버튼 영역을 찾지 못했습니다.';return false;}
    $insert=$pos+strlen($needle);$changed=true;$message='비용 수정/누락 신청 버튼 1개를 추가했습니다.';return substr($src,0,$insert).$block.substr($src,$insert);
}}

if (!function_exists('ecc_patch_create_v2')) {
function ecc_patch_create_v2($src,&$changed,&$message){
    $changed=false;$messages=array();
    if(strpos($src,"'cost_correction'")===false){
        $old="\$allowedTypes = array('proposal', 'small_proposal', 'leave', 'unused_leave_notice', 'unused_leave_plan');";
        $new="\$allowedTypes = array('proposal', 'small_proposal', 'leave', 'unused_leave_notice', 'unused_leave_plan', 'cost_correction');";
        if(strpos($src,$old)===false){$message='create.php 문서종류 목록 위치를 찾지 못했습니다.';return false;}
        $src=str_replace($old,$new,$src);$changed=true;$messages[]='비용 전용 작성화면 종류를 등록했습니다.';
    }
    $marker='/* CPMS_APPROVAL_COST_CORRECTION_CREATE_SCREEN */';
    if(strpos($src,$marker)===false){
        $needle="if (!in_array(\$type, \$allowedTypes, true)) {\n    \$type = 'proposal';\n}\n";
        $pos=strpos($src,$needle);if($pos===false){$message='create.php 작성화면 연결 위치를 찾지 못했습니다.';return false;}
        $hook=$needle."\n".$marker."\nif (\$type === 'cost_correction') {\n    require_once __DIR__ . '/cost_correction_create.php';\n    return;\n}\n";
        $src=substr_replace($src,$hook,$pos,strlen($needle));$changed=true;$messages[]='기존 전자결재 레이아웃에 비용 신청화면을 연결했습니다.';
    }
    if(!$changed)$messages[]='비용 신청 작성화면이 이미 연결되어 있습니다.';$message=implode(' ',$messages);return $src;
}}

if (!function_exists('ecc_patch_proposal_v2')) {
function ecc_patch_proposal_v2($src,&$changed,&$message){
    $changed=false;$messages=array();
    $requireMarker='/* CPMS_APPROVAL_COST_CORRECTION_TEMPLATE_REQUIRE */';
    if(strpos($src,$requireMarker)===false){
        $needle="require_once __DIR__ . '/../../services/ApprovalDriveService.php';\n";
        $pos=strpos($src,$needle);if($pos===false){$message='template_proposal.php 상단 연결 위치를 찾지 못했습니다.';return false;}
        $rep=$needle.$requireMarker."\nrequire_once __DIR__ . '/template_cost_correction.php';\n";
        $src=substr_replace($src,$rep,$pos,strlen($needle));$changed=true;$messages[]='비용 전용 문서 템플릿을 연결했습니다.';
    }
    $hookMarker='/* CPMS_APPROVAL_COST_CORRECTION_TEMPLATE_HOOK */';
    if(strpos($src,$hookMarker)===false){
        $needle="function render_approval_proposal_document(\$data, \$lines, \$mode, \$files, \$approvalOptions)\n{\n";
        $pos=strpos($src,$needle);if($pos===false){$message='template_proposal.php 렌더 함수 위치를 찾지 못했습니다.';return false;}
        $rep=$needle.'    '.$hookMarker."\n    if (function_exists('approval_cost_correction_is_document') && approval_cost_correction_is_document(\$data)) {\n        render_approval_cost_correction_document(\$data, \$lines, \$mode, \$files, \$approvalOptions);\n        return;\n    }\n";
        $src=substr_replace($src,$rep,$pos,strlen($needle));$changed=true;$messages[]='상세/인쇄 화면도 비용 전용 종이양식으로 연결했습니다.';
    }
    if(!$changed)$messages[]='비용 전용 상세/인쇄 템플릿이 이미 연결되어 있습니다.';$message=implode(' ',$messages);return $src;
}}

if (!function_exists('ecc_patch_detail_v2')) {
function ecc_patch_detail_v2($src,&$changed,&$message){
    $changed=false;
    $marker='/* CPMS_APPROVAL_COST_CORRECTION_DETAIL_GUARD */';
    if(strpos($src,$marker)!==false){$message='비용 신청문서의 일반 기안서 수정버튼 차단이 이미 적용되어 있습니다.';return $src;}
    $needle="\$canResubmitRejected = approval_resubmit_can_resubmit(\$pdo, \$d, \$u) && !\$resubmittedChild;\n";
    $pos=strpos($src,$needle);if($pos===false){$message='detail.php 수정/재상신 권한 위치를 찾지 못했습니다.';return false;}
    $hook=$needle.$marker."\nif (is_array(\$content) && isset(\$content['cpms_cost_correction']) && (string)\$content['cpms_cost_correction'] === '1') {\n    \$canEditBeforeFirstDecision = false;\n    \$canResubmitRejected = false;\n}\n";
    $src=substr_replace($src,$hook,$pos,strlen($needle));$changed=true;$message='비용 신청문서가 일반 기안서 수정화면으로 잘못 열리지 않도록 보호했습니다.';return $src;
}}

if (!function_exists('ecc_patch_team_leader_v3')) {
function ecc_patch_team_leader_v3($src,&$changed,&$message){
    $changed=false;
    $marker='/* CPMS_TEAM_LEADER_DIRECT_ASSIGNMENT_PRIORITY_V3 */';
    if(strpos($src,$marker)!==false){$message='직원명부에 직접 지정된 팀장 우선 규칙이 이미 적용되어 있습니다.';return $src;}
    $old="            if (\$selected && !approval_employee_is_executive(\$selected) && approval_line_rules_employee_department_matches_any(\$selected, \$targetKeys)) {\n                \$result['employee'] = \$selected;\n                return \$result;\n            }\n";
    $new="            ".$marker."\n            // 직원명부에서 team_leader_id를 직접 지정한 경우 현재 부서가 달라도 그 팀장을 최우선 사용합니다.\n            // 단, 퇴사/비활성 직원은 fetch 단계에서 제외되고 임원은 팀장으로 사용하지 않습니다.\n            if (\$selected && !approval_employee_is_executive(\$selected)) {\n                \$result['employee'] = \$selected;\n                return \$result;\n            }\n";
    $pos=strpos($src,$old);if($pos===false){$message='line_rules.php의 지정 팀장 확인 위치를 찾지 못했습니다.';return false;}
    $src=substr_replace($src,$new,$pos,strlen($old));$changed=true;$message='직원명부에 직접 지정된 팀장을 부서와 관계없이 최우선 사용하도록 변경했습니다.';return $src;
}}

if (!function_exists('ecc_patch_decide_v2')) {
function ecc_patch_decide_v2($src,&$changed,&$message){
    $changed=false;$messages=array();
    $requireMarker='/* CPMS_APPROVAL_COST_CORRECTION_REQUIRE */';
    if(strpos($src,$requireMarker)===false){
        $needle="require_once __DIR__ . '/../../services/ApprovalPdfService.php';\n";$pos=strpos($src,$needle);if($pos===false){$message='decide.php 서비스 연결 위치를 찾지 못했습니다.';return false;}
        $src=substr_replace($src,$needle.$requireMarker."\nrequire_once __DIR__ . '/../../services/ApprovalCostCorrectionService.php';\n",$pos,strlen($needle));$changed=true;$messages[]='자동반영 서비스를 연결했습니다.';
    }
    $hookMarker='/* CPMS_APPROVAL_COST_CORRECTION_FINAL_HOOK */';
    if(strpos($src,$hookMarker)===false){
        $needle="    \$pdo->commit();\n";$pos=strpos($src,$needle);if($pos===false){$message='decide.php 최종 commit 위치를 찾지 못했습니다.';return false;}
        $hook="    ".$hookMarker."\n    if (\$action === 'approve' && isset(\$docStatus) && \$docStatus === 'APPROVED' && class_exists('App\\\\Services\\\\ApprovalCostCorrectionService')) {\n        try {\n            \$costCorrectionApply = \\App\\Services\\ApprovalCostCorrectionService::applyApprovedDocument(\$pdo, \$id, \$u);\n            if (is_array(\$costCorrectionApply) && empty(\$costCorrectionApply['ok']) && empty(\$costCorrectionApply['skipped'])) {\n                if (function_exists('flash_set')) flash_set('danger', '결재는 승인되었지만 공사자료 자동 반영에 실패했습니다: ' . (isset(\$costCorrectionApply['message']) ? \$costCorrectionApply['message'] : '확인 필요'));\n            }\n        } catch (Exception \$costCorrectionException) {\n            error_log('[approval_decide][cost_correction] ' . \$costCorrectionException->getMessage());\n        }\n    }\n";
        $src=substr($src,0,$pos+strlen($needle)).$hook.substr($src,$pos+strlen($needle));$changed=true;$messages[]='최종승인 자동반영 훅을 연결했습니다.';
    }
    if(!$changed)$messages[]='기존 최종승인 자동반영 연결을 그대로 사용합니다.';$message=implode(' ',$messages);return $src;
}}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $token=isset($_POST['_csrf'])?(string)$_POST['_csrf']:'';
    if(!csrf_check($token)){ecc_setup_result($results,'보안 확인',false,'보안 토큰이 만료되었습니다.');}
    else if($root===false){ecc_setup_result($results,'CPMS 루트',false,'CPMS 루트를 확인하지 못했습니다.');}
    else {
        foreach(array('service'=>'자동반영 서비스','screen'=>'종이양식 작성화면','template'=>'종이양식 템플릿','endpoint'=>'저장/검색 처리파일') as $k=>$label) ecc_setup_result($results,$label,is_file($paths[$k]),is_file($paths[$k])?'확인 완료':'파일이 없습니다: '.$paths[$k]);
        ecc_setup_apply_file_patch($paths['index'],'전자결재 신청 버튼','ecc_patch_index_v2',$results);
        ecc_setup_apply_file_patch($paths['create'],'전자결재 작성화면','ecc_patch_create_v2',$results);
        ecc_setup_apply_file_patch($paths['proposal'],'전자결재 상세/인쇄 양식','ecc_patch_proposal_v2',$results);
        ecc_setup_apply_file_patch($paths['detail'],'비용 문서 수정화면 보호','ecc_patch_detail_v2',$results);
        ecc_setup_apply_file_patch($paths['line_rules'],'직원명부 지정 팀장 우선','ecc_patch_team_leader_v3',$results);
        ecc_setup_apply_file_patch($paths['decide'],'최종승인 자동반영','ecc_patch_decide_v2',$results);
    }
}

$indexOk=is_file($paths['index'])&&strpos((string)@file_get_contents($paths['index']),'?r=approval_create&type=cost_correction')!==false;
$createOk=is_file($paths['create'])&&strpos((string)@file_get_contents($paths['create']),'CPMS_APPROVAL_COST_CORRECTION_CREATE_SCREEN')!==false;
$proposalOk=is_file($paths['proposal'])&&strpos((string)@file_get_contents($paths['proposal']),'CPMS_APPROVAL_COST_CORRECTION_TEMPLATE_HOOK')!==false;
$detailOk=is_file($paths['detail'])&&strpos((string)@file_get_contents($paths['detail']),'CPMS_APPROVAL_COST_CORRECTION_DETAIL_GUARD')!==false;
$decideOk=is_file($paths['decide'])&&strpos((string)@file_get_contents($paths['decide']),'CPMS_APPROVAL_COST_CORRECTION_FINAL_HOOK')!==false;
$teamLeaderOk=is_file($paths['line_rules'])&&strpos((string)@file_get_contents($paths['line_rules']),'CPMS_TEAM_LEADER_DIRECT_ASSIGNMENT_PRIORITY_V3')!==false;
$costReady=false;$laborReady=false;$approvalReady=false;
if($pdo){
    try{$st=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cpms_approval_documents'");$approvalReady=((int)$st->fetchColumn()>0);}catch(Exception $e){}
    try{$costReady=CostChangeService::isInstalled($pdo);}catch(Exception $e){}
    try{$st=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cpms_labor_gongsu_overrides'");$laborReady=((int)$st->fetchColumn()>0);}catch(Exception $e){}
}
$installed=$indexOk&&$createOk&&$proposalOk&&$detailOk&&$decideOk&&$teamLeaderOk&&is_file($paths['service'])&&is_file($paths['screen'])&&is_file($paths['template'])&&is_file($paths['endpoint']);
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>비용 수정/누락 전자결재 V7</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f4f7fb;font-family:"Malgun Gothic",Arial,sans-serif;color:#172033}.wrap{max-width:1080px;margin:0 auto;padding:32px 18px}.box{background:#fff;border:1px solid #dfe7f1;border-radius:20px;padding:24px;margin-bottom:18px}.ok{background:#effcf4;border-color:#a7efc1;color:#246b45}.bad{background:#fff2f2;border-color:#ffcaca;color:#b42338}.row{padding:14px 0;border-bottom:1px solid #edf1f5}.row:last-child{border-bottom:0}.name{font-weight:900}.msg{margin-top:5px}.btn{border:0;border-radius:12px;padding:12px 18px;background:#4338ca;color:#fff;font-weight:900;cursor:pointer}.status{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #edf1f5}.good{color:#26734d;font-weight:900}.no{color:#b42338;font-weight:900}h1{margin-top:0}</style></head><body><div class="wrap">
<div class="box"><h1>비용 수정/누락 전자결재 V7</h1><p>노무비 공수 선택에 1.1~1.4를 추가하고, 신청 변동금액이 100만원 이상이면 부사장 뒤에 대표 결재를 자동 추가합니다. 화면 미리보기와 서버 검증이 같은 기준을 사용합니다.</p>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo ecc_setup_h(csrf_token()); ?>"><button class="btn" type="submit">설치/업데이트 실행</button></form></div>
<?php if(count($results)>0){?><div class="box"><h2>설치 결과</h2><?php for($i=0;$i<count($results);$i++){?><div class="row <?php echo $results[$i]['ok']?'ok':'bad';?>"><div class="name"><?php echo ecc_setup_h($results[$i]['name']);?></div><div class="msg"><?php echo ecc_setup_h($results[$i]['message']);?></div></div><?php }?></div><?php }?>
<div class="box"><h2>사전 확인</h2>
<?php $checks=array('전자결재 DB'=>$approvalReady,'기존 비용변경 승인 기능'=>$costReady,'노무비 공수 변경 기능'=>$laborReady,'비용 버튼 1개'=>$indexOk,'기존 전자결재 작성화면 연결'=>$createOk,'상세/인쇄 종이양식 연결'=>$proposalOk,'비용문서 수정화면 보호'=>$detailOk,'최종승인 자동반영'=>$decideOk,'직원명부 지정 팀장 우선'=>$teamLeaderOk,'노무비 월별 다중선택 서비스'=>method_exists('App\Services\ApprovalCostCorrectionService','laborWorkersForMonth'),'업체 검색/저장 처리파일'=>is_file($paths['endpoint'])); foreach($checks as $n=>$ok){?><div class="status"><span><?php echo ecc_setup_h($n);?></span><span class="<?php echo $ok?'good':'no';?>"><?php echo $ok?'정상':'확인 필요';?></span></div><?php }?>
</div>
<div class="box <?php echo $installed?'ok':'bad';?>"><b><?php echo $installed?'V7 연결 완료':'아직 연결이 완료되지 않았습니다.';?></b><?php if($installed){?><div style="margin-top:12px"><a href="?r=approval_home">전자결재로 이동</a> · <a href="?r=approval_create&type=cost_correction">비용 신청화면 확인</a></div><?php }?></div>
</div></body></html>
