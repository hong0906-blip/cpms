<?php
/**
 * 공사 > 노무비 > 공수 표 (화면/다운로드 공용)
 * - PHP 5.6 호환
 *
 * 필요 변수:
 * - $projectRow (array)
 * - $siteName (string)
 * - $periodStart (string, YYYY-MM-DD)
 * - $periodEnd (string, YYYY-MM-DD)
 * - $selectedMonth (string, YYYY-MM)
 */
?>

<div class="overflow-x-auto">
    <table class="min-w-[1200px] w-full border border-gray-200 text-xs">
        <tbody>
        <tr class="bg-gray-100">
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">현장명</th>
            <td class="border border-gray-200 px-2 py-2" colspan="6"><?php echo h($projectRow['name']); ?></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">공사기간</th>
            <td class="border border-gray-200 px-2 py-2" colspan="6"><?php echo h($projectRow['start_date']); ?> ~ <?php echo h($projectRow['end_date']); ?></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">책임자</th>
            <td class="border border-gray-200 px-2 py-2" colspan="4"><?php echo h($siteName !== '' ? $siteName : '미지정'); ?></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">작성자</th>
            <td class="border border-gray-200 px-2 py-2" colspan="4"><?php echo h($siteName !== '' ? $siteName : '미지정'); ?></td>
        </tr>
        <tr class="bg-gray-100">
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">사업개시번호</th>
            <td class="border border-gray-200 px-2 py-2" colspan="6"></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">출력기간</th>
            <td class="border border-gray-200 px-2 py-2" colspan="6"><?php echo h($periodStart); ?> ~ <?php echo h($periodEnd); ?></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">출력월</th>
            <td class="border border-gray-200 px-2 py-2" colspan="8"><?php echo h($selectedMonth); ?></td>
        </tr>
        </tbody>
    </table>

    <table class="min-w-[1200px] w-full border border-gray-200 text-[11px] mt-3">
        <thead>
        <tr class="bg-gray-200 text-gray-800">
            <th class="border border-gray-200 px-2 py-2" rowspan="2">연번</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">출력월</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">성명</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">주민등록번호</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">외국인</th>
            <th class="border border-gray-200 px-2 py-2 text-center" colspan="31">출력일수</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">출력일수 합계</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">임금단가</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">지급총액</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">갑근세</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">주민세</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">건강보험</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">국민연금</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">고용보험</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">공제합계</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">차감지급액</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">영수인/예금주</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">은행명</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">계좌번호</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">인력사업체명</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">하도급 구분</th>
        </tr>
        <tr class="bg-gray-200 text-gray-800">
            <?php for ($d = 1; $d <= 31; $d++): ?>
                <th class="border border-gray-200 px-1 py-1"><?php echo (int)$d; ?></th>
            <?php endfor; ?>
        </tr>
        </thead>
        <tbody>
        <?php for ($i = 1; $i <= 10; $i++): ?>
            <tr class="<?php echo ($i % 2 === 0) ? 'bg-gray-50' : 'bg-white'; ?>">
                <td class="border border-gray-200 px-2 py-2 text-center"><?php echo (int)$i; ?></td>
                <td class="border border-gray-200 px-2 py-2 text-center"><?php echo h(substr($selectedMonth, 5, 2)); ?>월</td>
                <td class="border border-gray-200 px-2 py-2"></td>
                <td class="border border-gray-200 px-2 py-2"></td>
                <td class="border border-gray-200 px-2 py-2 text-center"></td>
                <?php for ($d = 1; $d <= 31; $d++): ?>
                    <td class="border border-gray-200 px-1 py-1 text-center"></td>
                <?php endfor; ?>
                <td class="border border-gray-200 px-2 py-2 text-center">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                <td class="border border-gray-200 px-2 py-2"></td>
                <td class="border border-gray-200 px-2 py-2"></td>
                <td class="border border-gray-200 px-2 py-2"></td>
                <td class="border border-gray-200 px-2 py-2"></td>
                <td class="border border-gray-200 px-2 py-2"></td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>
</div>