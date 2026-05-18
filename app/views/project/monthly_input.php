<?php
use App\Core\Db;

$pdo = Db::pdo();
$selectedProjectId = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
$monthlyProjects = array();
$selectedProject = null;
$months = array();
$monthlyRevenue = array();
$rowsBySection = array('구매품'=>array(),'자재비'=>array(),'장비비'=>array(),'노무비'=>array(),'기타경비'=>array(),'안전관리비'=>array(),'공제분'=>array());
$sectionTotals = array();
foreach ($rowsBySection as $k => $v) $sectionTotals[$k] = array();
$revenueNotice = '';

function monthly_zero_map($months) { $m = array(); foreach ($months as $ym) $m[$ym] = 0; return $m; }
function amount_fmt($n){ if ((float)$n == 0) return '-'; return number_format((float)$n); }

if ($pdo) {
  $st = $pdo->query("SELECT id,name,start_date,end_date,contract_amount FROM cpms_projects ORDER BY id DESC");
  $monthlyProjects = $st->fetchAll(); if (!is_array($monthlyProjects)) $monthlyProjects = array();
  if ($selectedProjectId <= 0 && count($monthlyProjects) > 0) $selectedProjectId = (int)$monthlyProjects[0]['id'];
  foreach ($monthlyProjects as $pp) { if ((int)$pp['id'] === $selectedProjectId) { $selectedProject = $pp; break; } }

  if (is_array($selectedProject)) {
    $s = substr((string)$selectedProject['start_date'],0,7).'-01';
    $e = substr((string)$selectedProject['end_date'],0,7).'-01';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$s) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$e)) {
      $cur = strtotime($s); $end = strtotime($e);
      while ($cur <= $end) { $months[] = date('Y-m',$cur); $cur = strtotime('+1 month',$cur); }
    }
    if (count($months) === 0) $months[] = date('Y-m');

    $monthlyRevenue = monthly_zero_map($months);
    $stProgress = $pdo->prepare("SELECT p.*,u.unit_price,u.total_unit_price,u.amount,u.qty,t.start_date AS task_start_date FROM cpms_schedule_task_item_progress p LEFT JOIN cpms_project_unit_prices u ON u.id=p.unit_price_id AND u.project_id=p.project_id LEFT JOIN cpms_schedule_tasks t ON t.id=p.task_id AND t.project_id=p.project_id WHERE p.project_id=:pid");
    $stProgress->bindValue(':pid',$selectedProjectId,\PDO::PARAM_INT); $stProgress->execute();
    $progressRows = $stProgress->fetchAll(); if (!is_array($progressRows)) $progressRows = array();
    foreach ($progressRows as $rr) {
      $dateBase = '';
      if (isset($rr['work_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$rr['work_date'])) $dateBase = (string)$rr['work_date'];
      if ($dateBase === '' && isset($rr['task_start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$rr['task_start_date'])) { $dateBase = (string)$rr['task_start_date']; $revenueNotice = '공정 항목별 완료일자 정보가 부족하여 공정 시작월 기준으로 매출을 집계했습니다.'; }
      if ($dateBase === '') continue;
      $ym = substr($dateBase,0,7); if (!isset($monthlyRevenue[$ym])) continue;
      $done = isset($rr['done_qty']) ? (float)$rr['done_qty'] : 0;
      $amount = 0;
      if (isset($rr['unit_price']) && $rr['unit_price'] !== null && $rr['unit_price'] !== '') $amount = $done * (float)$rr['unit_price'];
      else if (isset($rr['total_unit_price']) && $rr['total_unit_price'] !== null && $rr['total_unit_price'] !== '') $amount = $done * (float)$rr['total_unit_price'];
      else if (isset($rr['amount']) && isset($rr['qty']) && (float)$rr['qty'] > 0) $amount = ($done / (float)$rr['qty']) * (float)$rr['amount'];
      $monthlyRevenue[$ym] += $amount;
    }

    $stMat = $pdo->prepare("SELECT m.id,m.category,m.vendor_name,m.representative,m.phone,m.biz_no,m.remark,u.use_date,u.amount FROM cpms_material_items m INNER JOIN cpms_material_usage u ON u.material_id=m.id AND u.project_id=m.project_id WHERE m.project_id=:pid");
    $stMat->bindValue(':pid',$selectedProjectId,\PDO::PARAM_INT); $stMat->execute();
    $mat = $stMat->fetchAll(); if (!is_array($mat)) $mat = array();
    $map = array('구매품'=>'구매품','자재비'=>'자재비','기타경비'=>'기타경비','안전관리비'=>'안전관리비');
    $tmp = array();
    foreach ($mat as $r){ $cat = trim((string)$r['category']); if (!isset($map[$cat])) continue; $sec=$map[$cat]; $id='m'.(int)$r['id']; if(!isset($tmp[$sec.'_'.$id])) $tmp[$sec.'_'.$id]=array('section'=>$sec,'업체명'=>$r['vendor_name'],'내역'=>$r['remark']!==''?$r['remark']:$cat,'대표자명'=>$r['representative'],'전화번호'=>$r['phone'],'사업자등록번호'=>$r['biz_no'],'months'=>monthly_zero_map($months)); $ym=substr((string)$r['use_date'],0,7); if(isset($tmp[$sec.'_'.$id]['months'][$ym])) $tmp[$sec.'_'.$id]['months'][$ym]+=(float)$r['amount']; }
    foreach($tmp as $one){ $rowsBySection[$one['section']][]=$one; }

    $stEq = $pdo->prepare("SELECT e.id,e.vendor_name,e.spec,e.category,e.representative,e.phone,e.biz_no,u.use_date,u.amount FROM cpms_equipment_items e INNER JOIN cpms_equipment_usage u ON u.equipment_id=e.id AND u.project_id=e.project_id WHERE e.project_id=:pid");
    $stEq->bindValue(':pid',$selectedProjectId,\PDO::PARAM_INT); $stEq->execute(); $eq=$stEq->fetchAll(); if(!is_array($eq))$eq=array(); $tmpEq=array();
    foreach($eq as $r){ $id='e'.(int)$r['id']; if(!isset($tmpEq[$id])) $tmpEq[$id]=array('section'=>'장비비','업체명'=>$r['vendor_name'],'내역'=>$r['spec']!==''?$r['spec']:$r['category'],'대표자명'=>$r['representative'],'전화번호'=>$r['phone'],'사업자등록번호'=>$r['biz_no'],'months'=>monthly_zero_map($months)); $ym=substr((string)$r['use_date'],0,7); if(isset($tmpEq[$id]['months'][$ym])) $tmpEq[$id]['months'][$ym]+=(float)$r['amount']; }
    foreach($tmpEq as $one){ $rowsBySection['장비비'][]=$one; }

    $stDed = $pdo->prepare("SELECT id,ym,deduction_name,amount,memo FROM cpms_project_monthly_deductions WHERE project_id=:pid ORDER BY ym ASC,id ASC");
    $stDed->bindValue(':pid',$selectedProjectId,\PDO::PARAM_INT); $stDed->execute(); $dd=$stDed->fetchAll(); if(!is_array($dd))$dd=array();
    foreach($dd as $r){ $row=array('section'=>'공제분','업체명'=>'','내역'=>$r['deduction_name'],'대표자명'=>'','전화번호'=>'','사업자등록번호'=>'','months'=>monthly_zero_map($months),'id'=>(int)$r['id']); if(isset($row['months'][$r['ym']])) $row['months'][$r['ym']] = (float)$r['amount']; $rowsBySection['공제분'][]=$row; }
  }
}
?>
<div class="bg-white rounded-3xl border border-gray-100 p-5">
<h3 class="text-xl font-extrabold mb-3">월별 투입비 상세내역</h3>
<form method="get" class="flex gap-2 items-center mb-3">
<input type="hidden" name="r" value="공무"><input type="hidden" name="tab" value="monthly_input">
<select name="pid" class="px-3 py-2 border rounded-xl" onchange="this.form.submit()">
<?php foreach($monthlyProjects as $pp): ?><option value="<?php echo (int)$pp['id']; ?>" <?php echo ((int)$pp['id']===$selectedProjectId)?'selected':''; ?>><?php echo h($pp['name']); ?></option><?php endforeach; ?>
</select>
</form>
<?php if($selectedProject): ?><div class="text-sm mb-2">공사명: <?php echo h($selectedProject['name']); ?> / 계약기간: <?php echo h($selectedProject['start_date'].' ~ '.$selectedProject['end_date']); ?> / 계약금액: <?php echo number_format((float)$selectedProject['contract_amount']); ?> (VAT 제외)</div><?php endif; ?>
<?php if($revenueNotice!==''): ?><div class="text-xs text-amber-700 mb-2"><?php echo h($revenueNotice); ?></div><?php endif; ?>
<div class="overflow-auto"><table class="min-w-[1400px] w-full text-sm border"><thead><tr class="bg-[#d7aa8a]"><th class="border p-2">구분</th><th class="border p-2">업체명</th><th class="border p-2">내역</th><?php foreach($months as $ym): ?><th class="border p-2 text-right"><?php echo h(str_replace('-','.',$ym)); ?></th><?php endforeach; ?><th class="border p-2 text-right">합계</th></tr></thead><tbody>
<tr class="bg-amber-100 font-bold"><td class="border p-2">매출금액(공정표 기준)</td><td class="border"></td><td class="border"></td><?php $revTotal=0; foreach($months as $ym){$revTotal += isset($monthlyRevenue[$ym])?$monthlyRevenue[$ym]:0; ?><td class="border p-2 text-right"><?php echo amount_fmt(isset($monthlyRevenue[$ym])?$monthlyRevenue[$ym]:0); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($revTotal); ?></td></tr>
<?php $sections=array('구매품','자재비','장비비','노무비','기타경비','안전관리비','공제분'); foreach($sections as $sec): ?><tr class="bg-[#f2dfd0] font-bold"><td class="border p-2"><?php echo h($sec); ?></td><td class="border" colspan="<?php echo 2+count($months)+1; ?>"></td></tr><?php $sub=monthly_zero_map($months); if(isset($rowsBySection[$sec])) foreach($rowsBySection[$sec] as $row){ ?><tr><td class="border p-2"></td><td class="border p-2"><?php echo h($row['업체명']); ?></td><td class="border p-2"><?php echo h($row['내역']); ?><?php if($sec==='공제분' && isset($row['id'])){ ?> <a class="text-red-600 text-xs" href="?r=project/monthly_deduction_delete&id=<?php echo (int)$row['id']; ?>&pid=<?php echo (int)$selectedProjectId; ?>" onclick="return confirm('삭제할까요?');">삭제</a><?php } ?></td><?php $rowSum=0; foreach($months as $ym){ $v=isset($row['months'][$ym])?$row['months'][$ym]:0; $rowSum+=$v; $sub[$ym]+=$v; ?><td class="border p-2 text-right"><?php echo amount_fmt($v); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($rowSum); ?></td></tr><?php } $subSum=0; ?><tr class="bg-orange-100"><td class="border p-2 font-bold"><?php echo h($sec); ?> 소계</td><td class="border" colspan="2"></td><?php foreach($months as $ym){ $subSum += $sub[$ym]; $sectionTotals[$sec][$ym]=$sub[$ym]; ?><td class="border p-2 text-right font-bold"><?php echo amount_fmt($sub[$ym]); ?></td><?php } ?><td class="border p-2 text-right font-bold"><?php echo amount_fmt($subSum); ?></td></tr><?php endforeach; ?>
<?php $first=monthly_zero_map($months); $final=monthly_zero_map($months); $profit=monthly_zero_map($months); foreach($months as $ym){ $first[$ym]=(isset($sectionTotals['구매품'][$ym])?$sectionTotals['구매품'][$ym]:0)+(isset($sectionTotals['자재비'][$ym])?$sectionTotals['자재비'][$ym]:0)+(isset($sectionTotals['장비비'][$ym])?$sectionTotals['장비비'][$ym]:0)+(isset($sectionTotals['노무비'][$ym])?$sectionTotals['노무비'][$ym]:0)+(isset($sectionTotals['기타경비'][$ym])?$sectionTotals['기타경비'][$ym]:0); $final[$ym]=$first[$ym]+(isset($sectionTotals['안전관리비'][$ym])?$sectionTotals['안전관리비'][$ym]:0)+(isset($sectionTotals['공제분'][$ym])?$sectionTotals['공제분'][$ym]:0); $profit[$ym]=(isset($monthlyRevenue[$ym])?$monthlyRevenue[$ym]:0)-$final[$ym]; } ?>
<tr class="bg-amber-200 font-bold"><td class="border p-2">1차 합계</td><td class="border" colspan="2"></td><?php $t=0; foreach($months as $ym){$t+=$first[$ym]; ?><td class="border p-2 text-right"><?php echo amount_fmt($first[$ym]); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($t); ?></td></tr>
<tr class="bg-amber-200 font-bold"><td class="border p-2">최종 합계</td><td class="border" colspan="2"></td><?php $t=0; foreach($months as $ym){$t+=$final[$ym]; ?><td class="border p-2 text-right"><?php echo amount_fmt($final[$ym]); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($t); ?></td></tr>
<tr class="font-bold"><td class="border p-2">손익</td><td class="border" colspan="2"></td><?php $t=0; foreach($months as $ym){$t+=$profit[$ym]; ?><td class="border p-2 text-right <?php echo $profit[$ym] < 0 ? 'text-red-600':'text-blue-600'; ?>"><?php echo amount_fmt($profit[$ym]); ?></td><?php } ?><td class="border p-2 text-right <?php echo $t < 0 ? 'text-red-600':'text-blue-600'; ?>"><?php echo amount_fmt($t); ?></td></tr>
</tbody></table></div>
<?php if($selectedProjectId>0): ?><form method="post" action="?r=project/monthly_deduction_save" class="mt-4 grid grid-cols-1 md:grid-cols-6 gap-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$selectedProjectId; ?>"><input name="ym" placeholder="YYYY-MM" class="border rounded-xl px-3 py-2" required><input name="deduction_name" placeholder="공제항목" class="border rounded-xl px-3 py-2" required><input name="amount" placeholder="금액" class="border rounded-xl px-3 py-2" required><input name="memo" placeholder="메모" class="border rounded-xl px-3 py-2"><button class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold">공제분 저장</button></form><?php endif; ?>
</div>