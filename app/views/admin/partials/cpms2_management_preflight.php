<?php
// app/views/admin/partials/cpms2_management_preflight.php
$management=isset($exportView['management'])?$exportView['management']:null;
$overheadPackage=isset($exportView['overhead_package'])?$exportView['overhead_package']:null;
$overheadReady=$management && isset($management['audit']['Overhead']) && (int)$management['audit']['Overhead']['blocking_count']===0 && !empty($management['Overhead']['total_is_final']);
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
        <?php if (isset($management['Attendance']['records']['reversed_diagnostic'])): $reversed=$management['Attendance']['records']['reversed_diagnostic']; ?>
        <h3>역전 출퇴근 정밀진단</h3>
        <dl>
            <div><dt>역전 기록</dt><dd><?php echo (int)$reversed['total']; ?>건<?php if ($reversed['truncated']): ?> · 상세 최대 100건<?php endif; ?></dd></div>
            <?php foreach ($reversed['classifications'] as $classification=>$count): ?>
            <div><dt><?php echo h($classification); ?></dt><dd><?php echo (int)$count; ?>건</dd></div>
            <?php endforeach; ?>
            <?php if (isset($management['Attendance']['records']['missing_checkout_preview'])): $missing=$management['Attendance']['records']['missing_checkout_preview']; ?>
            <div><dt>과거 미퇴근 / 당일 진행 중 / 미래 기록</dt><dd><?php echo (int)$missing['past_missing_checkout']; ?> / <?php echo (int)$missing['today_in_progress']; ?> / <?php echo (int)$missing['future_records']; ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php endif; ?>
        <?php if (isset($management['Leave']['cpms_leave_accrual_logs']['orphan_diagnostic'])): $orphan=$management['Leave']['cpms_leave_accrual_logs']['orphan_diagnostic']; ?>
        <h3>연차 발생 Orphan 정밀진단</h3>
        <dl><div><dt>직원 ID / 발생 Row</dt><dd><?php echo (int)$orphan['employee_count']; ?> / <?php echo (int)$orphan['row_count']; ?><?php if ($orphan['truncated']): ?> · 상세 최대 100개 ID<?php endif; ?></dd></div></dl>
        <?php endif; ?>
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
<section>
    <h2>총관리비 전용 Export</h2>
    <p>직원·현장·근태·연차 등 Base Migration 데이터는 포함하지 않고 회사 총관리비 원장만 생성합니다.</p>
    <form method="post" action="?r=admin%2Fcpms2_export">
        <input type="hidden" name="_csrf" value="<?php echo h($exportView['csrf']); ?>">
        <input type="hidden" name="action" value="generate_overhead">
        <button type="submit" data-guide="admin-cpms2-overhead-generate"<?php echo $overheadReady?'':' disabled'; ?>>총관리비 전용 ZIP 생성</button>
    </form>
    <?php if ($management && !$overheadReady): ?><p class="cpms2-error">Overhead Blocking이 0건이고 인정 총액이 확정된 경우에만 생성할 수 있습니다.</p><?php endif; ?>
    <?php if ($overheadPackage): ?>
    <div class="cpms2-project-trace">
        <h3>총관리비 ZIP 생성 완료</h3>
        <dl>
            <div><dt>파일명</dt><dd><?php echo h($overheadPackage['filename']); ?></dd></div>
            <div><dt>크기</dt><dd><?php echo number_format($overheadPackage['size']); ?> bytes</dd></div>
            <div><dt>SHA-256</dt><dd><?php echo h($overheadPackage['sha256']); ?></dd></div>
            <div><dt>총관리비 총액</dt><dd><?php echo h($overheadPackage['summary']['grand_total']); ?>원</dd></div>
        </dl>
        <form method="post" action="?r=admin%2Fcpms2_export">
            <input type="hidden" name="_csrf" value="<?php echo h($exportView['csrf']); ?>">
            <input type="hidden" name="action" value="download_overhead">
            <input type="hidden" name="package" value="<?php echo h($overheadPackage['id']); ?>">
            <button type="submit" data-guide="admin-cpms2-overhead-download">총관리비 ZIP 다운로드</button>
        </form>
    </div>
    <?php endif; ?>
</section>
