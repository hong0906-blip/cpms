<?php if ($overheadSection !== 'summary'): ?>
  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="font-extrabold text-gray-900 mb-3"><?php echo h($categories[$overheadSection]['label']); ?> 목록</div>
<?php endif; ?>

  <div class="cpms-responsive-table-wrap">
    <table class="cpms-responsive-table text-sm">
      <thead>
        <tr>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">연월</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">구분</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">항목</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">직원/담당자</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">지급처</th>
          <th class="text-right p-3 border-b border-gray-200 bg-gray-50">금액</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">첨부</th>
          <th class="text-left p-3 border-b border-gray-200 bg-gray-50">관리</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($items) === 0): ?>
          <tr><td colspan="8" class="p-4 text-center text-gray-500">등록된 총관리비가 없습니다.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $row): ?>
          <?php
            $rowCategory = isset($row['category']) ? (string)$row['category'] : '';
            $rowYear = isset($row['year']) ? (string)$row['year'] : '';
            $rowMonth = isset($row['month']) ? (string)$row['month'] : '';
          ?>
          <tr>
            <td class="p-3 border-b border-gray-100"><?php echo h($rowYear . '/' . $rowMonth); ?></td>
            <td class="p-3 border-b border-gray-100"><?php echo h(isset($row['category_name']) ? $row['category_name'] : $rowCategory); ?></td>
            <td class="p-3 border-b border-gray-100 text-left" data-wrap="1">
              <div class="font-bold text-gray-900"><?php echo h(isset($row['title']) ? $row['title'] : ''); ?></div>
              <?php if (!empty($row['memo'])): ?><div class="text-xs text-gray-500 mt-1"><?php echo h($row['memo']); ?></div><?php endif; ?>
            </td>
            <td class="p-3 border-b border-gray-100"><?php echo h(isset($row['employee_name']) ? $row['employee_name'] : ''); ?></td>
            <td class="p-3 border-b border-gray-100"><?php echo h(isset($row['vendor']) ? $row['vendor'] : ''); ?></td>
            <td class="p-3 border-b border-gray-100 text-right font-extrabold"><?php echo h(cpms_overhead_view_money(isset($row['amount']) ? $row['amount'] : 0)); ?></td>
            <td class="p-3 border-b border-gray-100">
              <?php if (!empty($row['drive_web_view_link'])): ?>
                <a href="<?php echo h($row['drive_web_view_link']); ?>" target="_blank" rel="noopener" class="text-emerald-700 font-bold">보기</a>
              <?php elseif (!empty($row['original_name'])): ?>
                <span class="text-xs text-amber-700 font-bold">업로드 확인 필요</span>
              <?php else: ?>
                <span class="text-gray-400">-</span>
              <?php endif; ?>
            </td>
            <td class="p-3 border-b border-gray-100">
              <?php if ($canEditCompanyOverhead): ?>
                <div class="flex flex-wrap gap-2">
                  <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=<?php echo urlencode($rowCategory); ?>&edit=<?php echo urlencode(isset($row['id']) ? $row['id'] : ''); ?>&edit_year=<?php echo urlencode($rowYear); ?>&edit_month=<?php echo urlencode($rowMonth); ?>" class="px-3 py-2 rounded-lg border border-gray-300 font-bold">수정</a>
                  <form method="post" action="?r=management/overhead_delete" onsubmit="return confirm('삭제하시겠습니까?');">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="category" value="<?php echo h($rowCategory); ?>">
                    <input type="hidden" name="id" value="<?php echo h(isset($row['id']) ? $row['id'] : ''); ?>">
                    <input type="hidden" name="year" value="<?php echo h($rowYear); ?>">
                    <input type="hidden" name="month" value="<?php echo h($rowMonth); ?>">
                    <button type="submit" class="px-3 py-2 rounded-lg border border-red-200 text-red-700 font-bold">삭제</button>
                  </form>
                </div>
              <?php else: ?>
                <span class="text-gray-400">조회</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($overheadSection !== 'summary'): ?>
  </div>
<?php endif; ?>
