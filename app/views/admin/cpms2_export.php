<?php
// app/views/admin/cpms2_export.php
if (!isset($exportView)) { http_response_code(404); return; }
$report=$exportView['preflight']; $package=$exportView['package'];
$renderAccounts=function($accounts) {
    $labels=array('vendors'=>'업체','workers'=>'근로자','direct_team'=>'직영팀','employees'=>'직원');
    foreach ($accounts['counts'] as $entity=>$count) {
        echo '<p>'.h($labels[$entity]).' 계좌번호 '.(int)$count['source'].'건 중 '.(int)$count['verified'].'건 확인 / '.(int)$count['failed'].'건 실패</p>';
        if (isset($count['missing_number'])) echo '<p>계좌번호 미등록 '.(int)$count['missing_number'].'건 · 그중 은행명/예금주만 있음 '.(int)$count['partial_information'].'건 (Non-blocking)</p>';
        if ($entity==='workers' && isset($count['legacy_residue'])) echo '<p>일반 미등록 '.(int)($count['missing_number']-$count['legacy_residue']).'건 · 암호화 흔적만 존재 / 실제 계좌 미등록 '.(int)$count['legacy_residue'].'건 (Non-blocking)</p>';
        if ($entity==='workers' && isset($count['decrypted'])) {
            echo '<p>암호화 직접 복호화 '.(int)$count['decrypted'].'건 · 과거 노무 Snapshot 복구 '.(int)$count['snapshot_recovered'].'건 · 복구 실패 '.(int)$count['recovery_failed'].'건 · Snapshot Conflict '.(int)$count['snapshot_conflict'].'건</p>';
            echo '<p>복호화 실패 '.(int)$count['decrypt_failed'].'건 · Hash 불일치 '.(int)$count['hash_mismatch'].'건 · 복구 Source 유실 '.(int)$count['recovery_source_missing'].'건</p>';
        }
    }
    if (isset($accounts['payroll'])) {
        $payroll=$accounts['payroll'];
        echo '<p>직원 Payroll Source: '.($payroll['source_found']?'발견':'미발견').' · 상태: '.h($payroll['status']).'</p>';
        if (!empty($payroll['latest_month'])) echo '<p>최신 Payroll Version: '.h($payroll['latest_month']).' / 직원 '.(int)$payroll['latest_employee_rows'].'명 / 계좌 '.(int)$payroll['latest_account_rows'].'건</p>';
        if ($payroll['selected_month']!=='') echo '<p>계좌 Migration Source: '.h($payroll['selected_month']).' / 직원 '.(int)$payroll['employee_rows'].'명 / 계좌 '.(int)$payroll['account_rows'].'건 · Mapping 성공 '.(int)$payroll['mapping_success'].'건 / 실패 '.(int)$payroll['mapping_failed'].'건</p>';
    }
    foreach ($accounts['failures'] as $failure) echo '<p class="cpms2-warning">'.h($labels[$failure['entity']]).' #'.h($failure['legacy_id']).' · '.h($failure['name']).' · 오류코드: '.h($failure['code']).'</p>';
};
$renderMigration=function($data) {
    foreach (array('safety_costs'=>'안전관리비','completed_approvals'=>'이전 완료문서') as $key=>$label) {
        if (empty($data[$key])) continue;
        echo '<h3>'.h($label).'</h3><dl>';
        $labels=array('store_found'=>'JSON 저장소 발견','json_total'=>'JSON 전체','json_active'=>'활성','json_excluded'=>'제외','bulk'=>'공사 Excel 일괄입력','other'=>'일반입력','db_candidates'=>'DB 후보','deduplicated'=>'검증된 중복 제외','possible_duplicates'=>'중복 가능성(보존)','db_only'=>'DB 추가','final_count'=>'이관 건수','amount'=>'이관 금액','pdf_metadata'=>'PDF 메타데이터','pdf_available'=>'PDF 확인','pdf_missing'=>'PDF 누락','pdf_deduplicated'=>'PDF 중복','completed_documents'=>'완료 문서','approved'=>'승인완료','completed'=>'처리완료','not_generated'=>'PDF 미생성(제외)','unavailable'=>'PDF 복구 실패','local_cache'=>'기존 Cache','drive_download'=>'Drive 읽기','pdf_bytes'=>'PDF Bytes');
        foreach ($data[$key] as $field=>$value) echo '<div><dt>'.h(isset($labels[$field])?$labels[$field]:$field).'</dt><dd>'.h(is_bool($value)?($value?'발견':'미발견'):$value).'</dd></div>';
        echo '</dl>';
    }
};
$renderClosure=function($data) {
    if (empty($data['referenced_master_closure'])) return;
    echo '<h3>참조 Master 사전검사</h3><dl>';
    foreach (array('projects'=>'프로젝트','material_items'=>'자재','equipment_items'=>'장비','workers'=>'근로자','direct_team'=>'직영팀','vendors'=>'업체','material_usages'=>'명세서 참조 사용내역') as $key=>$label) {
        if (!isset($data['referenced_master_closure'][$key])) continue;
        $row=$data['referenced_master_closure'][$key];
        echo '<div><dt>'.h($label).'</dt><dd>일반 '.(int)$row['normal'].' / 참조 복구 '.(int)$row['reference_recovered'].' / 물리 누락 '.(int)$row['physically_missing'].'</dd></div>';
        if (isset($row['snapshot_only_labor_workers'])) echo '<div><dt>노무 Snapshot 근로자</dt><dd>'.(int)$row['snapshot_only_labor_workers'].'</dd></div>';
    }
    if (isset($data['referenced_master_closure']['labor_vendor'])) foreach (array('legacy_vendor_id'=>'업체 PK 확인','unique_business_identity'=>'고유 사업자 확인','snapshot_only'=>'업체 Snapshot 보존','ambiguous'=>'업체 식별 모호') as $key=>$label) echo '<div><dt>'.h($label).'</dt><dd>'.(int)$data['referenced_master_closure']['labor_vendor'][$key].'</dd></div>';
    echo '</dl>';
    if (!empty($data['referenced_master_closure']['historical_project_recovery'])) {
        echo '<h3>삭제 프로젝트 Snapshot 복구</h3>';
        echo '<p>복구 성공 '.(int)$data['referenced_master_closure']['projects']['historical_snapshot_recovered'].'건</p>';
        $sources=array('ai_daily_snapshot'=>'AI Daily Snapshot','cost_data_event'=>'Cost Data Event');
        foreach ($data['referenced_master_closure']['historical_project_recovery'] as $recovery) {
            echo '<p>legacy #'.h($recovery['legacy_id']).' · 물리 Master: 없음 · 프로젝트명: '.($recovery['name_confirmed']?'확인':'미확인').' · 상태: '.($recovery['status_confirmed']?'확인':'미확인');
            if (isset($sources[$recovery['recovery_source']])) echo ' · Source: '.h($sources[$recovery['recovery_source']]);
            if (!$recovery['recovered']) echo ' · '.($recovery['code']==='legacy_project_snapshot_ambiguous'?'프로젝트명 Snapshot 충돌':'프로젝트명 Snapshot 없음').' · Export 차단';
            echo '</p>';
        }
    }
};
$renderProjectTraces=function($traces) {
    $sources=array('cpms_material_items'=>'자재 품목','cpms_material_usage'=>'자재 사용내역','cpms_equipment_items'=>'장비 품목','cpms_equipment_usage'=>'장비 사용내역','cpms_material_statement_files'=>'거래명세서','cpms_outsourcing_costs'=>'외주비','cpms_progress_billings'=>'기성');
    $fields=array('project_id'=>'프로젝트 ID','vendor_name'=>'업체명','company_name'=>'업체명','category'=>'분류','item_name'=>'품목명','equipment_name'=>'장비명','spec'=>'규격','remark'=>'비고','base_rate'=>'기준단가','use_date'=>'사용일','amount'=>'금액','memo'=>'메모','material_id'=>'자재 ID','equipment_id'=>'장비 ID','work_unit'=>'사용량','original_name'=>'원본 파일명','ym'=>'기준월','uploaded_at'=>'업로드일','expense_date'=>'비용일','content'=>'내용','round_label'=>'회차','progress_date'=>'기성일','requested_amount'=>'청구액','recognized_amount'=>'인정액');
    $first=true;
    foreach ($traces as $trace) {
        echo '<div class="cpms2-project-trace"><h3>삭제 프로젝트 추적정보 · legacy #'.h($trace['legacy_id']).'</h3>';
        echo '<p class="cpms2-warning">프로젝트명 미확인 · Export 차단</p><dl>';
        foreach (array('first_use_date'=>'최초 사용일','last_use_date'=>'최종 사용일') as $key=>$label) echo '<div><dt>'.h($label).'</dt><dd>'.h($trace[$key]===null?'미확인':$trace[$key]).'</dd></div>';
        foreach ($sources as $key=>$label) echo '<div><dt>'.h($label).' 건수</dt><dd>'.($trace['counts'][$key]===null?'조회 Source 없음':number_format($trace['counts'][$key]).'건').'</dd></div>';
        echo '<div><dt>기성 존재 여부</dt><dd>'.($trace['progress_billing_exists']===null?'미확인':($trace['progress_billing_exists']?'있음':'없음')).'</dd></div></dl>';
        $vendors=array(); $vendorTruncated=false;
        foreach ($trace['lists'] as $key=>$list) if (substr($key,-8)==='_vendors') { $vendors=array_unique(array_merge($vendors,$list['values'])); $vendorTruncated=$vendorTruncated || $list['truncated']; }
        if (count($vendors)>20) $vendorTruncated=true;
        echo '<p><strong>주요 업체명</strong>: '.h($vendors?implode(' · ',array_slice($vendors,0,20)):'확인된 값 없음').($vendorTruncated?' · 일부 표시':'').'</p>';
        foreach (array('cpms_material_items_names'=>'주요 자재명/규격','cpms_equipment_items_names'=>'주요 장비명/규격','statement_names'=>'거래명세서 원본 파일명') as $key=>$label) {
            $list=isset($trace['lists'][$key])?$trace['lists'][$key]:array('values'=>array(),'truncated'=>false);
            echo '<p><strong>'.h($label).'</strong>: '.h($list['values']?implode(' · ',$list['values']):'확인된 값 없음').($list['truncated']?' · 일부 표시':'').'</p>';
        }
        echo '<details><summary'.($first?' data-guide="admin-cpms2-project-trace"':'').'>상세보기 ('.(int)$trace['detail_count'].'건 / 최대 100건)</summary>';
        $first=false;
        foreach ($trace['details'] as $table=>$rows) {
            if (!$rows) continue;
            echo '<h4>'.h($sources[$table]).' · '.count($rows).' / '.(int)$trace['counts'][$table].'건</h4>';
            foreach ($rows as $row) {
                echo '<dl class="cpms2-trace-row">';
                foreach ($row as $key=>$value) if ($value!==null && $value!=='') echo '<div><dt>'.h($fields[$key]).'</dt><dd>'.h($value).'</dd></div>';
                echo '</dl>';
            }
        }
        echo '</details></div>';
    }
};
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CPMS2 데이터 Export</title>
<link rel="stylesheet" href="<?php echo h(asset_url('assets/css/cpms2-export.css')); ?>">
<link rel="stylesheet" href="<?php echo h(asset_url('assets/css/guide-tour.css')); ?>">
<script defer src="<?php echo h(asset_url('assets/js/guide-tour.js')); ?>"></script>
</head><body class="cpms2-export-page">
<main class="cpms2-export">
<header>
  <h1>CPMS2 데이터 Export</h1>
  <div class="cpms2-actions"><a href="?r=관리">관리</a><button id="cpmsGuideTourStart" type="button">도움말</button></div>
</header>
<?php if ($exportView['error']!==''): ?><div role="alert" class="cpms2-error"><?php echo h($exportView['error']); ?></div><?php endif; ?>
<section>
  <div class="cpms2-actions">
    <form method="post"><input type="hidden" name="_csrf" value="<?php echo h($exportView['csrf']); ?>"><input type="hidden" name="action" value="preflight">
      <button data-guide="admin-cpms2-preflight" class="cpms2-secondary" type="submit">사전검사</button></form>
    <form method="post"><input type="hidden" name="_csrf" value="<?php echo h($exportView['csrf']); ?>"><input type="hidden" name="action" value="generate">
      <button data-guide="admin-cpms2-generate" type="submit"<?php echo !$report || empty($report['can_export'])?' disabled':''; ?>>전체 Export ZIP 생성</button></form>
  </div><p id="cpms2-export-status" role="status" aria-live="polite"></p>
</section>
<?php if ($report): ?>
<section>
  <h2>사전검사 결과</h2>
  <?php $renderMigration($report); ?>
  <?php $renderClosure($report); ?>
  <?php if (!empty($report['referenced_master_closure']['deleted_project_traces'])) $renderProjectTraces($report['referenced_master_closure']['deleted_project_traces']); ?>
  <dl>
  <?php foreach ($report['counts'] as $entity=>$count): ?><div><dt><?php echo h($entity); ?></dt><dd><?php echo number_format($count); ?>건</dd></div><?php endforeach; ?>
  </dl>
  <p>거래명세서 <?php echo (int)$report['expected_file_count']; ?>건 · 누락 <?php echo (int)$report['missing_file_count']; ?>건</p>
  <?php if (isset($report['accounts'])): $renderAccounts($report['accounts']); ?>
  <?php if (!$report['can_export']): ?><p class="cpms2-error" role="alert">사전검사를 통과하지 못해 Export를 진행할 수 없습니다.</p><?php endif; ?>
  <?php endif; ?>
  <?php foreach (isset($report['failures'])?$report['failures']:array() as $failure): if (isset($failure['entity']) && !in_array($failure['code'],array('legacy_referenced_master_physically_missing','legacy_labor_snapshot_insufficient','legacy_project_snapshot_unavailable','legacy_project_snapshot_ambiguous'))) continue; ?><p class="cpms2-error"><?php echo h(isset($failure['entity'])?$failure['entity']:'문서'); ?> #<?php echo h($failure['legacy_id']); ?> · <?php echo h($failure['code']); ?></p><?php endforeach; ?>
  <?php foreach ($report['warnings'] as $warning): ?><p class="cpms2-warning">Warning: <?php echo h($warning); ?></p><?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($package): $summary=$package['summary']; ?>
<section>
  <h2>생성 완료</h2>
  <?php $renderMigration($summary); ?>
  <?php $renderClosure($summary); ?>
  <?php if (!empty($summary['account_preflight'])) $renderAccounts($summary['account_preflight']); ?>
  <p>SHA-256: <?php echo h($package['sha256']); ?></p>
  <p>ZIP <?php echo number_format($package['size']); ?> bytes · 전체 Package 파일 <?php echo (int)$summary['exported_file_count']; ?>개 · 누락 <?php echo (int)$summary['missing_file_count']; ?>건 · 중복 제거 <?php echo (int)$summary['deduplicated_file_count']; ?>건</p>
  <dl>
  <?php foreach ($summary['record_counts'] as $entity=>$count): ?><div><dt><?php echo h($entity); ?></dt><dd><?php echo number_format($count); ?>건</dd></div><?php endforeach; ?>
  </dl>
  <?php foreach ($summary['warnings'] as $warning): ?><p class="cpms2-warning">Warning: <?php echo h($warning); ?></p><?php endforeach; ?>
  <form method="post"><input type="hidden" name="_csrf" value="<?php echo h($exportView['csrf']); ?>"><input type="hidden" name="action" value="download"><input type="hidden" name="package" value="<?php echo h($package['id']); ?>">
    <button data-guide="admin-cpms2-download" type="submit">ZIP 다운로드</button></form>
</section>
<?php endif; ?>
</main>
<script>
// Release buttons only on navigation; hidden actions remain part of the request.
Array.prototype.forEach.call(document.querySelectorAll('form'), function(form) {
  if (form.querySelector('[name="action"]').value === 'download') return;
  form.addEventListener('submit', function(event) {
    if (form.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }
    form.setAttribute('aria-busy', 'true');
    document.getElementById('cpms2-export-status').textContent = '처리 중입니다. 완료될 때까지 이 화면을 유지하세요.';
  });
});
</script>
</body></html>
