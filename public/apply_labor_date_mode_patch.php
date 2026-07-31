<?php
/**
 * 파일경로: C:\www\cpms\public\apply_labor_date_mode_patch.php
 *
 * 외주비 "날짜로 선택" 화면 패치 설치기
 * - PHP 5.6 호환
 * - app/views/construction/tabs/labor.php 자동 수정
 * - 백업 없이 labor.php에 바로 적용
 *
 * 사용 후 반드시 이 파일을 삭제하세요.
 */

header('Content-Type: text/html; charset=UTF-8');

$projectRoot = dirname(__DIR__);
$targetFile = $projectRoot . '/app/views/construction/tabs/labor.php';

function cpms_patch_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cpms_patch_fail($message)
{
    echo '<!doctype html><html lang="ko"><meta charset="utf-8"><body style="font-family:Arial,sans-serif;padding:30px">';
    echo '<h2 style="color:#b91c1c">패치 실패</h2>';
    echo '<pre style="white-space:pre-wrap;background:#fef2f2;border:1px solid #fecaca;padding:16px;border-radius:12px">' . cpms_patch_escape($message) . '</pre>';
    echo '</body></html>';
    exit;
}

if (!is_file($targetFile)) {
    cpms_patch_fail("labor.php 파일을 찾지 못했습니다.\n찾은 경로: " . $targetFile);
}

$content = @file_get_contents($targetFile);
if ($content === false || $content === '') {
    cpms_patch_fail('labor.php 파일을 읽지 못했습니다.');
}

// 줄바꿈을 통일하여 운영체제 차이 때문에 치환이 실패하지 않게 합니다.
$content = str_replace("\r\n", "\n", $content);
$content = str_replace("\r", "\n", $content);
$original = $content;
$replaced = array();

$oldVariables = <<<'OLD'
                            $outsourcingRatio = function_exists('cpms_resolve_worker_outsourcing_ratio') ? cpms_resolve_worker_outsourcing_ratio($member) : ((isset($member['is_outsourcing']) && (int)$member['is_outsourcing'] === 1) ? 100 : 0);
                            $laborRatio = 100 - $outsourcingRatio;
                            $allocationPreset = in_array($outsourcingRatio, array(0, 30, 40, 50, 100), true) ? (string)$outsourcingRatio : 'custom';
                            $outsourcingStartDate = isset($member['outsourcing_start_date']) ? trim((string)$member['outsourcing_start_date']) : '';
                            $outsourcingEndDate = isset($member['outsourcing_end_date']) ? trim((string)$member['outsourcing_end_date']) : '';
OLD;

$newVariables = <<<'NEW'
                            $outsourcingRatio = function_exists('cpms_resolve_worker_outsourcing_ratio') ? cpms_resolve_worker_outsourcing_ratio($member) : ((isset($member['is_outsourcing']) && (int)$member['is_outsourcing'] === 1) ? 100 : 0);
                            $laborRatio = 100 - $outsourcingRatio;
                            $outsourcingStartDate = isset($member['outsourcing_start_date']) ? trim((string)$member['outsourcing_start_date']) : '';
                            $outsourcingEndDate = isset($member['outsourcing_end_date']) ? trim((string)$member['outsourcing_end_date']) : '';
                            $hasOutsourcingDateRange = (
                                preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $outsourcingStartDate)
                                && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $outsourcingEndDate)
                                && $outsourcingStartDate <= $outsourcingEndDate
                            );
                            // 날짜로 선택은 선택 기간만 외주비 100%, 나머지는 노무비 100%입니다.
                            if ($outsourcingRatio === 100 && $hasOutsourcingDateRange) {
                                $allocationPreset = 'date';
                            } else {
                                $allocationPreset = in_array($outsourcingRatio, array(0, 30, 40, 50, 100), true) ? (string)$outsourcingRatio : 'custom';
                            }
NEW;

if (strpos($content, "$allocationPreset = 'date';") === false) {
    if (strpos($content, $oldVariables) === false) {
        cpms_patch_fail('비용 배분 변수 영역을 찾지 못했습니다. labor.php가 저장소 최신 버전과 다른지 확인해주세요.');
    }
    $content = str_replace($oldVariables, $newVariables, $content, $count);
    $replaced[] = '날짜 선택 상태 계산: ' . (int)$count . '건';
} else {
    $replaced[] = '날짜 선택 상태 계산: 이미 적용됨';
}

$oldOptions = <<<'OLD'
                                        <option value="100" <?php echo $allocationPreset === '100' ? 'selected' : ''; ?>>전액 외주비</option>
                                        <option value="custom" <?php echo $allocationPreset === 'custom' ? 'selected' : ''; ?>>직접 입력</option>
OLD;

$newOptions = <<<'NEW'
                                        <option value="100" <?php echo $allocationPreset === '100' ? 'selected' : ''; ?>>전액 외주비</option>
                                        <option value="date" <?php echo $allocationPreset === 'date' ? 'selected' : ''; ?>>날짜로 선택</option>
                                        <option value="custom" <?php echo $allocationPreset === 'custom' ? 'selected' : ''; ?>>직접 입력</option>
NEW;

if (strpos($content, '<option value="date"') === false) {
    if (strpos($content, $oldOptions) === false) {
        cpms_patch_fail('비용 배분 드롭다운 영역을 찾지 못했습니다.');
    }
    $content = str_replace($oldOptions, $newOptions, $content, $count);
    $replaced[] = '날짜로 선택 옵션: ' . (int)$count . '건';
} else {
    $replaced[] = '날짜로 선택 옵션: 이미 적용됨';
}

$oldDateBox = <<<'OLD'
                                    <div class="mt-2 rounded-xl border border-blue-100 bg-blue-50 p-2">
                                        <div class="text-xs font-extrabold text-blue-900">외주비 적용기간</div>
                                        <div class="mt-1 grid grid-cols-2 gap-2">
                                            <label class="text-[11px] font-bold text-gray-600">시작일
                                                <input type="date" name="workers[<?php echo $workerId; ?>][outsourcing_start_date]" min="<?php echo h($periodStart); ?>" max="<?php echo h($periodEnd); ?>" value="<?php echo h($outsourcingStartDate); ?>" class="mt-1 w-full rounded-lg border border-blue-200 bg-white px-2 py-1 text-xs" data-allocation-start-date>
                                            </label>
                                            <label class="text-[11px] font-bold text-gray-600">종료일
                                                <input type="date" name="workers[<?php echo $workerId; ?>][outsourcing_end_date]" min="<?php echo h($periodStart); ?>" max="<?php echo h($periodEnd); ?>" value="<?php echo h($outsourcingEndDate); ?>" class="mt-1 w-full rounded-lg border border-blue-200 bg-white px-2 py-1 text-xs" data-allocation-end-date>
                                            </label>
                                        </div>
                                        <div class="mt-1 text-[11px] text-blue-800">비워두면 선택 월 전체에 비율을 적용합니다.</div>
                                    </div>
OLD;

$newDateBox = <<<'NEW'
                                    <div class="mt-2 rounded-xl border border-blue-100 bg-blue-50 p-2 <?php echo $allocationPreset === 'date' ? '' : 'hidden'; ?>" data-allocation-date-box>
                                        <div class="text-xs font-extrabold text-blue-900">날짜 선택</div>
                                        <div class="mt-1 grid grid-cols-2 gap-2">
                                            <label class="text-[11px] font-bold text-gray-600">시작일
                                                <input type="date" name="workers[<?php echo $workerId; ?>][outsourcing_start_date]" min="<?php echo h($periodStart); ?>" max="<?php echo h($periodEnd); ?>" value="<?php echo h($outsourcingStartDate); ?>" class="mt-1 w-full rounded-lg border border-blue-200 bg-white px-2 py-1 text-xs" data-allocation-start-date>
                                            </label>
                                            <label class="text-[11px] font-bold text-gray-600">종료일
                                                <input type="date" name="workers[<?php echo $workerId; ?>][outsourcing_end_date]" min="<?php echo h($periodStart); ?>" max="<?php echo h($periodEnd); ?>" value="<?php echo h($outsourcingEndDate); ?>" class="mt-1 w-full rounded-lg border border-blue-200 bg-white px-2 py-1 text-xs" data-allocation-end-date>
                                            </label>
                                        </div>
                                        <div class="mt-1 text-[11px] text-blue-800">선택한 기간만 외주비로 처리하고, 나머지 날짜는 노무비로 처리합니다.</div>
                                    </div>
NEW;

if (strpos($content, 'data-allocation-date-box') === false) {
    if (strpos($content, $oldDateBox) === false) {
        cpms_patch_fail('기존 외주비 적용기간 입력 영역을 찾지 못했습니다.');
    }
    $content = str_replace($oldDateBox, $newDateBox, $content, $count);
    $replaced[] = '날짜 입력 표시 방식: ' . (int)$count . '건';
} else {
    $replaced[] = '날짜 입력 표시 방식: 이미 적용됨';
}

$oldScript = <<<'OLD'
        <script defer src="<?php echo h(asset_url('assets/js/labor_personnel.js')); ?>"></script>
OLD;
$newScript = <<<'NEW'
        <script defer src="<?php echo h(asset_url('assets/js/labor_personnel.js') . '?v=20260731-date-mode-2'); ?>"></script>
NEW;

if (strpos($content, '?v=20260731-date-mode-2') === false) {
    if (strpos($content, $oldScript) === false) {
        cpms_patch_fail('labor_personnel.js 불러오기 영역을 찾지 못했습니다.');
    }
    $content = str_replace($oldScript, $newScript, $content, $count);
    $replaced[] = '브라우저 캐시 방지: ' . (int)$count . '건';
} else {
    $replaced[] = '브라우저 캐시 방지: 이미 적용됨';
}

if ($content === $original) {
    $message = "변경할 내용이 없습니다. 이미 패치가 적용된 상태입니다.";
} else {
    if (@file_put_contents($targetFile, $content, LOCK_EX) === false) {
        cpms_patch_fail("labor.php 저장에 실패했습니다.\n파일 쓰기 권한을 확인해 주세요: " . $targetFile);
    }
    $message = "labor.php 패치를 완료했습니다.\n백업 파일은 생성하지 않았습니다.";
}

echo '<!doctype html><html lang="ko"><meta charset="utf-8"><body style="font-family:Arial,sans-serif;padding:30px;background:#f8fafc">';
echo '<div style="max-width:850px;margin:auto;background:#fff;border:1px solid #d1fae5;border-radius:18px;padding:24px">';
echo '<h2 style="margin-top:0;color:#047857">외주비 날짜 선택 패치 완료</h2>';
echo '<pre style="white-space:pre-wrap;background:#ecfdf5;border:1px solid #a7f3d0;padding:16px;border-radius:12px">' . cpms_patch_escape($message) . '</pre>';
echo '<ul>';
foreach ($replaced as $row) echo '<li>' . cpms_patch_escape($row) . '</li>';
echo '</ul>';
echo '<p><strong>이제 이 설치 파일을 서버에서 삭제하고, 화면에서 Ctrl+F5를 눌러주세요.</strong></p>';
echo '</div></body></html>';
