<?php
// app/views/admin/cpms2_export.php
if (!isset($exportView)) { http_response_code(404); return; }
$report=$exportView['preflight']; $package=$exportView['package'];
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
  <?php if (isset($report['accounts'])): $labels=array('vendors'=>'업체','workers'=>'근로자','direct_team'=>'직영팀','employees'=>'직원'); ?>
  <?php foreach ($report['accounts']['counts'] as $entity=>$count): ?><p><?php echo h($labels[$entity]); ?> 계좌번호 <?php echo (int)$count['source']; ?>건 중 <?php echo (int)$count['verified']; ?>건 확인 / <?php echo (int)$count['failed']; ?>건 실패</p><?php endforeach; ?>
  <?php if (!$report['can_export']): ?><p class="cpms2-error" role="alert">계좌 사전검사를 통과하지 못해 Export를 진행할 수 없습니다.</p><?php endif; ?>
  <?php foreach ($report['accounts']['failures'] as $failure): ?><p class="cpms2-warning"><?php echo h($labels[$failure['entity']]); ?> #<?php echo h($failure['legacy_id']); ?> · <?php echo h($failure['name']); ?> · 오류코드: <?php echo h($failure['code']); ?></p><?php endforeach; ?>
  <?php endif; ?>
  <?php foreach ($report['warnings'] as $warning): ?><p class="cpms2-warning">Warning: <?php echo h($warning); ?></p><?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($package): $summary=$package['summary']; ?>
<section>
  <h2>생성 완료</h2>
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
