<?php
// app/views/admin/cpms2_export.php
if (!isset($exportView)) { http_response_code(404); return; }
$report=$exportView['preflight']; $package=$exportView['package'];
$renderAccounts=function($accounts) {
    $labels=array('vendors'=>'업체','workers'=>'근로자','direct_team'=>'직영팀','employees'=>'직원');
    foreach ($accounts['counts'] as $entity=>$count) {
        echo '<p>'.h($labels[$entity]).' 계좌번호 '.(int)$count['source'].'건 중 '.(int)$count['verified'].'건 확인 / '.(int)$count['failed'].'건 실패</p>';
        if (isset($count['missing_number'])) echo '<p>계좌번호 미등록 '.(int)$count['missing_number'].'건 · 그중 은행명/예금주만 있음 '.(int)$count['partial_information'].'건 (Non-blocking)</p>';
        if ($entity==='workers' && isset($count['decrypted'])) {
            echo '<p>암호화 직접 복호화 '.(int)$count['decrypted'].'건 · 과거 노무 Snapshot 복구 '.(int)$count['snapshot_recovered'].'건 · 복구 실패 '.(int)$count['recovery_failed'].'건 · Snapshot Conflict '.(int)$count['snapshot_conflict'].'건</p>';
            echo '<p>복호화 실패 '.(int)$count['decrypt_failed'].'건 · Hash 불일치 '.(int)$count['hash_mismatch'].'건 · 복구 Source 유실 '.(int)$count['recovery_source_missing'].'건</p>';
        }
    }
    if (isset($accounts['payroll'])) {
        $payroll=$accounts['payroll'];
        echo '<p>직원 Payroll Source: '.($payroll['source_found']?'발견':'미발견').' · 상태: '.h($payroll['status']).'</p>';
        if ($payroll['selected_month']!=='') echo '<p>직원 급여 Version 발견 · 기준월 '.h($payroll['selected_month']).' · 직원 Row '.(int)$payroll['employee_rows'].'건 · 계좌번호 등록 '.(int)$payroll['account_rows'].'건 · Mapping 성공 '.(int)$payroll['mapping_success'].'건 / 실패 '.(int)$payroll['mapping_failed'].'건</p>';
    }
    foreach ($accounts['failures'] as $failure) echo '<p class="cpms2-warning">'.h($labels[$failure['entity']]).' #'.h($failure['legacy_id']).' · '.h($failure['name']).' · 오류코드: '.h($failure['code']).'</p>';
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
  <dl>
  <?php foreach ($report['counts'] as $entity=>$count): ?><div><dt><?php echo h($entity); ?></dt><dd><?php echo number_format($count); ?>건</dd></div><?php endforeach; ?>
  </dl>
  <p>거래명세서 <?php echo (int)$report['expected_file_count']; ?>건 · 누락 <?php echo (int)$report['missing_file_count']; ?>건</p>
  <?php if (isset($report['accounts'])): $renderAccounts($report['accounts']); ?>
  <?php if (!$report['can_export']): ?><p class="cpms2-error" role="alert">계좌 사전검사를 통과하지 못해 Export를 진행할 수 없습니다.</p><?php endif; ?>
  <?php endif; ?>
  <?php foreach ($report['warnings'] as $warning): ?><p class="cpms2-warning">Warning: <?php echo h($warning); ?></p><?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($package): $summary=$package['summary']; ?>
<section>
  <h2>생성 완료</h2>
  <?php if (!empty($summary['account_preflight'])) $renderAccounts($summary['account_preflight']); ?>
  <p>SHA-256: <?php echo h($package['sha256']); ?></p>
  <p>ZIP <?php echo number_format($package['size']); ?> bytes · 거래명세서 <?php echo (int)$summary['exported_file_count']; ?>개 · 누락 <?php echo (int)$summary['missing_file_count']; ?>건 · 중복 제거 <?php echo (int)$summary['deduplicated_file_count']; ?>건</p>
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
