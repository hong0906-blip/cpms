<?php
// app/views/admin/partials/cpms2_management_preflight.php
$management=isset($exportView['management'])?$exportView['management']:null;
?>
<section>
    <h2>관리부 Migration 사전검사</h2>
    <form method="post" action="?r=admin%2Fcpms2_export">
        <input type="hidden" name="_csrf" value="<?php echo h($exportView['csrf']); ?>">
        <input type="hidden" name="action" value="management_preflight">
        <button type="submit" data-guide="admin-cpms2-management-preflight">근태·총관리비 검사</button>
    </form>
    <?php if ($management): ?>
    <div class="cpms2-project-trace">
        <dl>
        <?php foreach ($management['audit'] as $domain=>$audit): ?>
            <div><dt><?php echo h($domain); ?></dt><dd><?php echo h($audit['status']); ?> · Blocking <?php echo (int)$audit['blocking_count']; ?> / Warning <?php echo (int)$audit['warning_count']; ?></dd></div>
        <?php endforeach; ?>
            <div><dt>총 Blocking / Warning</dt><dd><?php echo (int)$management['blocking_count']; ?> / <?php echo (int)$management['warning_count']; ?></dd></div>
        </dl>
        <dl>
            <div><dt>출퇴근 기록 / 수정요청</dt><dd><?php echo isset($management['Attendance']['records']['total'])?(int)$management['Attendance']['records']['total']:'확인 불가'; ?> / <?php echo isset($management['Attendance']['requests']['total'])?(int)$management['Attendance']['requests']['total']:'확인 불가'; ?></dd></div>
            <div><dt>휴가 / 연차 조정</dt><dd><?php echo isset($management['Leave']['cpms_leave_records']['total'])?(int)$management['Leave']['cpms_leave_records']['total']:'확인 불가'; ?> / <?php echo isset($management['Leave']['cpms_leave_adjustments']['total'])?(int)$management['Leave']['cpms_leave_adjustments']['total']:'확인 불가'; ?></dd></div>
            <div><dt>연차 발생 / 차감 / 복구</dt><dd><?php echo isset($management['Leave']['cpms_leave_accrual_logs']['total'])?(int)$management['Leave']['cpms_leave_accrual_logs']['total']:'확인 불가'; ?> / <?php echo isset($management['Leave']['cpms_approval_leave_deductions']['total'])?(int)$management['Leave']['cpms_approval_leave_deductions']['total']:'확인 불가'; ?> / <?php echo isset($management['Leave']['restore_logs'])?(int)$management['Leave']['restore_logs']:'확인 불가'; ?></dd></div>
        </dl>
        <?php if (!empty($management['Overhead']['categories'])): ?>
        <h3>총관리비 인정액 Preview</h3>
        <dl>
            <?php foreach ($management['Overhead']['categories'] as $category=>$stats): ?>
            <div><dt><?php echo h($category); ?></dt><dd><?php echo (int)$stats['logical_months']; ?>개월 · <?php echo (int)$stats['record_count']; ?>건 · <?php echo h($stats['recognized_amount']); ?>원</dd></div>
            <?php endforeach; ?>
            <div><dt>COMPANY OVERHEAD GRAND TOTAL</dt><dd><?php echo h($management['Overhead']['grand_total']); ?>원<?php if (empty($management['Overhead']['total_is_final'])): ?> · 미확정<?php endif; ?></dd></div>
        </dl>
        <?php endif; ?>
        <?php if ($management['issues']): ?>
        <h3>확인할 항목</h3>
        <?php foreach ($management['issues'] as $issue): ?>
        <p class="cpms2-warning"><?php echo h($issue['domain'].' · '.$issue['severity'].' · '.$issue['code']); ?> · <?php echo (int)$issue['count']; ?>건<?php if ($issue['legacy_ids']): ?> · legacy ID <?php echo h(implode(', ',$issue['legacy_ids'])); ?><?php endif; ?></p>
        <?php endforeach; ?>
        <?php endif; ?>
        <details>
            <summary data-guide="admin-cpms2-management-results">진단 상세 · 전달용 결과</summary>
            <pre class="cpms2-management-result" tabindex="0"><?php echo h(json_encode($management,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></pre>
        </details>
    </div>
    <?php endif; ?>
</section>
