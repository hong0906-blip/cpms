<?php
use App\Core\Db;

$pdo = Db::pdo();
$selectedProjectId = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
$monthlyProjects = array();
$selectedProject = null;
$months = array();
$monthlyRevenue = array();
$rowsBySection = array('구매품'=>array(),'자재비'=>array(),'장비비'=>array(),'노무비'=>array(),'기타경비'=>array(),'안전관리비'=>array(),'공제분'=>array());
$errors = array();
$notices = array();
$deductionTableMissing = false;
$periodMissing = false;
$workDateFallbackUsed = false;

function monthly_zero_map($months) { $m = array(); foreach ($months as $ym) { $m[$ym] = 0; } return $m; }
function amount_fmt($n){ if ((float)$n == 0) { return '-'; } return number_format((float)$n); }
function row_total($row, $months){ $sum = 0; foreach($months as $ym){ $sum += isset($row['months'][$ym]) ? (float)$row['months'][$ym] : 0; } return $sum; }
function ym_valid($ym){ return preg_match('/^\\d{4}-\\d{2}$/', (string)$ym); }
function project_monthly_table_exists($pdo, $table) {
    try { $st = $pdo->prepare('SHOW TABLES LIKE :t'); $st->bindValue(':t', $table); $st->execute(); return is_array($st->fetch()); }
    catch (Exception $e) { return false; }
}
function project_monthly_column_exists($pdo, $table, $column) {
    try { $st = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :c'); $st->bindValue(':c', $column); $st->execute(); return is_array($st->fetch()); }
    catch (Exception $e) { return false; }
}

if ($pdo) {
    try {
        $st = $pdo->query('SELECT id,name,start_date,end_date,contract_amount FROM cpms_projects ORDER BY id DESC');
        $monthlyProjects = $st->fetchAll();
        if (!is_array($monthlyProjects)) { $monthlyProjects = array(); }
    } catch (Exception $e) {
        $errors[] = '프로젝트 목록을 불러오지 못했습니다. 오류: ' . $e->getMessage();
    }

    if ($selectedProjectId <= 0 && count($monthlyProjects) > 0) {
        $selectedProjectId = (int)$monthlyProjects[0]['id'];
    }
    foreach ($monthlyProjects as $pp) {
        if ((int)$pp['id'] === $selectedProjectId) { $selectedProject = $pp; break; }
    }

    if (is_array($selectedProject)) {
        $startDate = (string)$selectedProject['start_date'];
        $endDate = (string)$selectedProject['end_date'];
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $startDate) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $endDate)) {
            $s = substr($startDate, 0, 7) . '-01';
            $e = substr($endDate, 0, 7) . '-01';
            $cur = strtotime($s);
            $end = strtotime($e);
            while ($cur <= $end) {
                $months[] = date('Y-m', $cur);
                $cur = strtotime('+1 month', $cur);
            }
        } else {
            $periodMissing = true;
        }
    }
}

if (count($months) === 0) { $months[] = date('Y-m'); }
$monthlyRevenue = monthly_zero_map($months);

if ($pdo && is_array($selectedProject)) {
    $hasWorkDate = project_monthly_column_exists($pdo, 'cpms_schedule_task_item_progress', 'work_date');
    $hasTotalUnitPrice = project_monthly_column_exists($pdo, 'cpms_project_unit_prices', 'total_unit_price');
    $hasAmount = project_monthly_column_exists($pdo, 'cpms_project_unit_prices', 'amount');

    try {
        $extraSelect = '';
        if ($hasTotalUnitPrice) { $extraSelect .= ',u.total_unit_price'; }
        if ($hasAmount) { $extraSelect .= ',u.amount'; }
        $selectWorkDate = $hasWorkDate ? 'p.work_date,' : '';

        $sqlProgress = 'SELECT p.done_qty,' . $selectWorkDate . 'u.unit_price,u.qty' . $extraSelect . ',t.start_date AS task_start_date '
            . 'FROM cpms_schedule_task_item_progress p '
            . 'LEFT JOIN cpms_project_unit_prices u ON u.id=p.unit_price_id AND u.project_id=p.project_id '
            . 'LEFT JOIN cpms_schedule_tasks t ON t.id=p.task_id AND t.project_id=p.project_id '
            . 'WHERE p.project_id=:pid';
        $stProgress = $pdo->prepare($sqlProgress);
        $stProgress->bindValue(':pid', $selectedProjectId, \PDO::PARAM_INT);
        $stProgress->execute();
        $progressRows = $stProgress->fetchAll();
        if (!is_array($progressRows)) { $progressRows = array(); }

        foreach ($progressRows as $rr) {
            $dateBase = '';
            if ($hasWorkDate && isset($rr['work_date']) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)$rr['work_date'])) { $dateBase = (string)$rr['work_date']; }
            if ($dateBase === '' && isset($rr['task_start_date']) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)$rr['task_start_date'])) {
                $dateBase = (string)$rr['task_start_date'];
                $workDateFallbackUsed = true;
            }
            if ($dateBase === '') { continue; }
            $ym = substr($dateBase, 0, 7);
            if (!isset($monthlyRevenue[$ym])) { continue; }
            $done = isset($rr['done_qty']) ? (float)$rr['done_qty'] : 0;
            $amount = 0;
            if (isset($rr['unit_price']) && $rr['unit_price'] !== null && $rr['unit_price'] !== '') { $amount = $done * (float)$rr['unit_price']; }
            else if ($hasTotalUnitPrice && isset($rr['total_unit_price']) && $rr['total_unit_price'] !== null && $rr['total_unit_price'] !== '') { $amount = $done * (float)$rr['total_unit_price']; }
            else if ($hasAmount && isset($rr['amount']) && isset($rr['qty']) && (float)$rr['qty'] > 0) { $amount = ($done / (float)$rr['qty']) * (float)$rr['amount']; }
            $monthlyRevenue[$ym] += $amount;
        }
    } catch (Exception $e) {
        $errors[] = '공정표 매출 데이터를 불러오지 못했습니다. 오류: ' . $e->getMessage();
    }

    try {
        $stMat = $pdo->prepare('SELECT m.id,m.category,m.vendor_name,m.representative,m.phone,m.biz_no,m.remark,u.use_date,u.amount FROM cpms_material_items m INNER JOIN cpms_material_usage u ON u.material_id=m.id AND u.project_id=m.project_id WHERE m.project_id=:pid');
        $stMat->bindValue(':pid', $selectedProjectId, \PDO::PARAM_INT);
        $stMat->execute();
        $mat = $stMat->fetchAll();
        if (!is_array($mat)) { $mat = array(); }
        $map = array('구매품'=>'구매품','자재비'=>'자재비','기타경비'=>'기타경비','안전관리비'=>'안전관리비');
        $tmp = array();
        foreach ($mat as $r) {
            $cat = trim((string)$r['category']);
            if (!isset($map[$cat])) { continue; }
            $sec = $map[$cat];
            $id = 'm' . (int)$r['id'];
            if (!isset($tmp[$sec . '_' . $id])) {
                $tmp[$sec . '_' . $id] = array('section'=>$sec,'업체명'=>$r['vendor_name'],'내역'=>($r['remark'] !== '' ? $r['remark'] : $cat),'months'=>monthly_zero_map($months));
            }
            $ym = substr((string)$r['use_date'], 0, 7);
            if (isset($tmp[$sec . '_' . $id]['months'][$ym])) { $tmp[$sec . '_' . $id]['months'][$ym] += (float)$r['amount']; }
        }
        foreach ($tmp as $one) { $rowsBySection[$one['section']][] = $one; }
    } catch (Exception $e) { $errors[] = '자재구입비 데이터를 불러오지 못했습니다. 오류: ' . $e->getMessage(); }

    try {
        $stEq = $pdo->prepare('SELECT e.id,e.vendor_name,e.spec,e.category,u.use_date,u.amount FROM cpms_equipment_items e INNER JOIN cpms_equipment_usage u ON u.equipment_id=e.id AND u.project_id=e.project_id WHERE e.project_id=:pid');
        $stEq->bindValue(':pid', $selectedProjectId, \PDO::PARAM_INT);
        $stEq->execute();
        $eq = $stEq->fetchAll();
        if (!is_array($eq)) { $eq = array(); }
        $tmpEq = array();
        foreach ($eq as $r) {
            $id = 'e' . (int)$r['id'];
            if (!isset($tmpEq[$id])) { $tmpEq[$id] = array('section'=>'장비비','업체명'=>$r['vendor_name'],'내역'=>($r['spec'] !== '' ? $r['spec'] : $r['category']),'months'=>monthly_zero_map($months)); }
            $ym = substr((string)$r['use_date'], 0, 7);
            if (isset($tmpEq[$id]['months'][$ym])) { $tmpEq[$id]['months'][$ym] += (float)$r['amount']; }
        }
        foreach ($tmpEq as $one) { $rowsBySection['장비비'][] = $one; }
    } catch (Exception $e) { $errors[] = '장비비 데이터를 불러오지 못했습니다. 오류: ' . $e->getMessage(); }

    if (count($rowsBySection['노무비']) === 0) {
        $rowsBySection['노무비'][] = array('section'=>'노무비','업체명'=>'','내역'=>'노무비 데이터 없음','months'=>monthly_zero_map($months));
    }

    try {
        if (project_monthly_table_exists($pdo, 'cpms_project_monthly_deductions')) {
            $stDed = $pdo->prepare('SELECT id,ym,deduction_name,amount,memo FROM cpms_project_monthly_deductions WHERE project_id=:pid ORDER BY ym ASC,id ASC');
            $stDed->bindValue(':pid', $selectedProjectId, \PDO::PARAM_INT);
            $stDed->execute();
            $dd = $stDed->fetchAll();
            if (!is_array($dd)) { $dd = array(); }
            foreach ($dd as $r) {
                $row = array('section'=>'공제분','업체명'=>'','내역'=>$r['deduction_name'],'memo'=>$r['memo'],'months'=>monthly_zero_map($months),'id'=>(int)$r['id']);
                if (isset($row['months'][$r['ym']])) { $row['months'][$r['ym']] = (float)$r['amount']; }
                $rowsBySection['공제분'][] = $row;
            }
        } else {
            $deductionTableMissing = true;
        }
    } catch (Exception $e) {
        $deductionTableMissing = true;
        $errors[] = '공제분 데이터를 불러오지 못했습니다. 오류: ' . $e->getMessage();
    }
}

if ($workDateFallbackUsed) { $notices[] = 'work_date가 없거나 비어 있으면 공정 시작일 기준으로 임시 집계했습니다.'; }

$labels = array('구매품'=>'1. 구매품','자재비'=>'2. 자재비','장비비'=>'3. 장비비','노무비'=>'4. 노무비','기타경비'=>'5. 기타경비','안전관리비'=>'6. 안전관리비','공제분'=>'7. 공제분');
$sumBySection = array();
foreach ($labels as $k=>$v) { $sumBySection[$k] = monthly_zero_map($months); }
foreach ($rowsBySection as $sec=>$rows) {
    foreach ($rows as $row) {
        foreach ($months as $ym) { $sumBySection[$sec][$ym] += isset($row['months'][$ym]) ? (float)$row['months'][$ym] : 0; }
    }
}
$subtotal1 = monthly_zero_map($months);
$finalTotal = monthly_zero_map($months);
$profit = monthly_zero_map($months);
foreach ($months as $ym) {
    $subtotal1[$ym] = $sumBySection['구매품'][$ym] + $sumBySection['자재비'][$ym] + $sumBySection['장비비'][$ym] + $sumBySection['노무비'][$ym] + $sumBySection['기타경비'][$ym];
    $finalTotal[$ym] = $subtotal1[$ym] + $sumBySection['안전관리비'][$ym] + $sumBySection['공제분'][$ym];
    $profit[$ym] = (isset($monthlyRevenue[$ym]) ? (float)$monthlyRevenue[$ym] : 0) - $finalTotal[$ym];
}
?>
<div class="bg-white rounded-3xl border border-gray-100 p-5">
<h3 class="text-xl font-extrabold mb-3">월별 투입비 상세내역</h3>
<?php if (count($errors)>0): ?><div class="mb-3 rounded-xl border border-red-200 bg-red-50 text-red-800 p-3 text-sm"><?php foreach($errors as $em): ?><div><?php echo h($em); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($deductionTableMissing): ?><div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 p-3 text-sm">공제분 테이블이 없습니다. 공무 DB 설치/확인을 실행해주세요.</div><?php endif; ?>
<?php foreach($notices as $nt): ?><div class="mb-2 text-xs text-amber-700"><?php echo h($nt); ?></div><?php endforeach; ?>

<?php if (count($monthlyProjects) === 0): ?>
<div class="text-sm text-gray-700">등록된 프로젝트가 없습니다. [프로젝트 관리] 탭에서 신규 프로젝트를 먼저 생성해주세요.</div>
<?php else: ?>
<form method="get" class="mb-4 flex flex-wrap items-center gap-2 bg-amber-50 border border-amber-200 rounded-2xl p-3">
<input type="hidden" name="r" value="공무"><input type="hidden" name="tab" value="monthly_input">
<div class="font-bold text-base">프로젝트 선택 :</div>
<select name="pid" class="px-3 py-2 border rounded-xl min-w-[260px]"><?php foreach($monthlyProjects as $pp): ?><option value="<?php echo (int)$pp['id']; ?>" <?php echo ((int)$pp['id']===$selectedProjectId)?'selected':''; ?>><?php echo h($pp['name']); ?></option><?php endforeach; ?></select>
<button type="submit" class="px-4 py-2 rounded-xl bg-amber-700 text-white">조회</button>
</form>

<?php if($selectedProject): ?>
<div class="text-sm mb-3 space-y-1">
<div><span class="font-semibold">공사명 :</span> <?php echo h($selectedProject['name']); ?></div>
<div><span class="font-semibold">계약기간 :</span> <?php echo h($selectedProject['start_date']); ?> ~ <?php echo h($selectedProject['end_date']); ?></div>
<div><span class="font-semibold">계약금액 :</span> <?php echo number_format((float)$selectedProject['contract_amount']); ?> <span class="text-xs text-gray-600">VAT 제외</span></div>
</div>
<?php endif; ?>

<?php if ($periodMissing): ?><div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 p-3 text-sm">프로젝트 계약기간이 없어 월별 투입비를 계산할 수 없습니다. 프로젝트 관리 탭에서 공사 시작일/종료일을 입력해주세요.</div><?php endif; ?>

<div class="overflow-x-auto">
<table class="min-w-[1600px] w-full text-sm border">
<thead><tr class="bg-[#d7aa8a]"><th class="border p-2">구분</th><th class="border p-2">업체명</th><th class="border p-2">내역</th><?php foreach($months as $ym): ?><th class="border p-2 text-right"><?php echo h(str_replace('-', '.', $ym)); ?></th><?php endforeach; ?><th class="border p-2 text-right">합계</th></tr></thead>
<tbody>
<tr class="bg-amber-100 font-bold"><td class="border p-2">매출금액(공정표 기준)</td><td class="border p-2"></td><td class="border p-2"></td><?php $revSum=0; foreach($months as $ym){ $v=(float)$monthlyRevenue[$ym]; $revSum+=$v; ?><td class="border p-2 text-right"><?php echo amount_fmt($v); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($revSum); ?></td></tr>
<?php foreach($labels as $sec=>$title): ?>
<tr class="bg-[#f2dfcf] font-semibold"><td class="border p-2"><?php echo h($title); ?></td><td class="border p-2"></td><td class="border p-2"></td><?php foreach($months as $ym): ?><td class="border p-2"></td><?php endforeach; ?><td class="border p-2"></td></tr>
<?php if (count($rowsBySection[$sec]) === 0): ?>
<tr><td class="border p-2"></td><td class="border p-2 text-gray-500" colspan="2">데이터 없음</td><?php foreach($months as $ym): ?><td class="border p-2 text-right">-</td><?php endforeach; ?><td class="border p-2 text-right">-</td></tr>
<?php else: foreach($rowsBySection[$sec] as $row): ?>
<tr><td class="border p-2"></td><td class="border p-2"><?php echo h(isset($row['업체명'])?$row['업체명']:''); ?></td><td class="border p-2"><?php echo h(isset($row['내역'])?$row['내역']:''); ?></td><?php foreach($months as $ym): $v = isset($row['months'][$ym]) ? (float)$row['months'][$ym] : 0; ?><td class="border p-2 text-right"><?php echo amount_fmt($v); ?></td><?php endforeach; ?><td class="border p-2 text-right"><?php echo amount_fmt(row_total($row,$months)); ?></td></tr>
<?php endforeach; endif; ?>
<tr class="bg-amber-50 font-semibold"><td class="border p-2"><?php echo h($title); ?> 소계</td><td class="border p-2"></td><td class="border p-2"></td><?php $secSum=0; foreach($months as $ym){ $secSum += $sumBySection[$sec][$ym]; ?><td class="border p-2 text-right"><?php echo amount_fmt($sumBySection[$sec][$ym]); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($secSum); ?></td></tr>
<?php endforeach; ?>
<tr class="bg-yellow-100 font-bold"><td class="border p-2">1차 합계</td><td class="border p-2"></td><td class="border p-2"></td><?php $s1=0; foreach($months as $ym){ $s1 += $subtotal1[$ym]; ?><td class="border p-2 text-right"><?php echo amount_fmt($subtotal1[$ym]); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($s1); ?></td></tr>
<tr class="bg-orange-100 font-bold"><td class="border p-2">최종 합계</td><td class="border p-2"></td><td class="border p-2"></td><?php $sf=0; foreach($months as $ym){ $sf += $finalTotal[$ym]; ?><td class="border p-2 text-right"><?php echo amount_fmt($finalTotal[$ym]); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($sf); ?></td></tr>
<tr class="font-bold"><td class="border p-2">손익</td><td class="border p-2"></td><td class="border p-2"></td><?php $sp=0; foreach($months as $ym){ $sp += $profit[$ym]; $cls=$profit[$ym]<0?'text-red-600':'text-blue-700'; ?><td class="border p-2 text-right <?php echo $cls; ?>"><?php echo amount_fmt($profit[$ym]); ?></td><?php } $clsAll=$sp<0?'text-red-600':'text-blue-700'; ?><td class="border p-2 text-right <?php echo $clsAll; ?>"><?php echo amount_fmt($sp); ?></td></tr>
</tbody></table>
</div>

<div class="mt-4 p-3 border rounded-xl bg-gray-50">
<div class="font-semibold mb-2">공제분 입력</div>
<form method="post" action="?r=project/monthly_deduction_save" class="flex flex-wrap gap-2 items-center">
<input type="hidden" name="project_id" value="<?php echo (int)$selectedProjectId; ?>">
<input type="text" name="ym" placeholder="월(YYYY-MM)" class="px-2 py-1 border rounded w-32">
<input type="text" name="deduction_name" placeholder="공제항목" class="px-2 py-1 border rounded w-40">
<input type="text" name="amount" placeholder="금액" class="px-2 py-1 border rounded w-32 text-right">
<input type="text" name="memo" placeholder="메모" class="px-2 py-1 border rounded w-56">
<button type="submit" class="px-3 py-1 rounded bg-amber-700 text-white">공제분 저장</button>
</form>
<?php if (count($rowsBySection['공제분'])>0): ?><div class="mt-2 text-sm space-y-1"><?php foreach($rowsBySection['공제분'] as $d): ?>
<div><?php echo h($d['내역']); ?> / <?php echo amount_fmt(row_total($d,$months)); ?>
<?php if (isset($d['id'])): ?><a class="text-red-600 ml-2" href="?r=project/monthly_deduction_delete&id=<?php echo (int)$d['id']; ?>&project_id=<?php echo (int)$selectedProjectId; ?>">삭제</a><?php endif; ?></div>
<?php endforeach; ?></div><?php endif; ?>
</div>

<?php endif; ?>
</div>