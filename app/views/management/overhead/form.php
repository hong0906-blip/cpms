<?php
$activeCategory = $overheadSection;
$activeMeta = isset($categories[$activeCategory]) ? $categories[$activeCategory] : null;
$formItem = is_array($editItem) ? $editItem : array();
$formYear = cpms_overhead_view_val($formItem, 'year', (string)$filters['year']);
$formMonth = cpms_overhead_view_val($formItem, 'month', ($filters['month'] > 0 ? sprintf('%02d', (int)$filters['month']) : date('m')));
$isOverheadEditing = is_array($editItem);
?>
<div class="bg-white border border-gray-200 rounded-2xl p-4">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div class="font-extrabold text-gray-900"><?php echo h($activeMeta['label']); ?> 관리</div>
    <?php if ($canEditCompanyOverhead): ?>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="px-4 py-3 rounded-xl bg-emerald-700 text-white font-extrabold" data-modal-open="overheadEtcForm">등록</button>
        <?php if ($isOverheadEditing): ?>
          <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=<?php echo urlencode($activeCategory); ?>" class="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold">수정 취소</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 text-slate-600 text-sm font-bold">조회 전용</div>
    <?php endif; ?>
  </div>
  <?php if (!$canEditCompanyOverhead): ?>
    <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">조회 권한만 있어 등록/수정/삭제는 사용할 수 없습니다.</div>
  <?php endif; ?>
</div>

<?php if ($canEditCompanyOverhead): ?>
<div id="modal-overheadEtcForm" class="fixed inset-0 z-50 <?php echo $isOverheadEditing ? '' : 'hidden'; ?>">
  <div class="absolute inset-0 bg-black/40" data-modal-close="overheadEtcForm"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-6xl bg-white rounded-3xl p-6" style="max-height:90vh;overflow-y:auto;position:relative;">
      <?php if ($isOverheadEditing): ?>
        <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=<?php echo urlencode($activeCategory); ?>" class="absolute right-4 top-4 px-3 py-1 border rounded-xl">닫기</a>
      <?php else: ?>
        <button type="button" class="absolute right-4 top-4 px-3 py-1 border rounded-xl" data-modal-close="overheadEtcForm">닫기</button>
      <?php endif; ?>
<div class="space-y-4">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <div>
      <div class="font-extrabold text-gray-900"><?php echo h($activeMeta['label']); ?> 관리</div>
    </div>
    <?php if (is_array($editItem)): ?>
      <a href="?r=<?php echo urlencode('관리'); ?>&tab=company_overhead&oh=<?php echo urlencode($activeCategory); ?>" class="px-3 py-2 rounded-xl border border-gray-300 text-sm font-bold">수정 취소</a>
    <?php endif; ?>
  </div>

  <?php if (!$canEditCompanyOverhead): ?>
    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 font-bold">조회 권한만 있어 등록/수정/삭제는 사용할 수 없습니다.</div>
  <?php else: ?>
    <form method="post" action="?r=management/overhead_save" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="category" value="<?php echo h($activeCategory); ?>">
      <input type="hidden" name="id" value="<?php echo h(cpms_overhead_view_val($formItem, 'id', '')); ?>">
      <input type="hidden" name="original_year" value="<?php echo h(cpms_overhead_view_val($formItem, 'year', '')); ?>">
      <input type="hidden" name="original_month" value="<?php echo h(cpms_overhead_view_val($formItem, 'month', '')); ?>">

      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">기준연도</span>
          <input type="number" name="year" min="2000" max="2100" value="<?php echo h($formYear); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" required>
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">기준월</span>
          <select name="month" class="w-full px-3 py-3 rounded-xl border border-gray-300" required>
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ((int)$formMonth === $m) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $m); ?></option>
            <?php endfor; ?>
          </select>
        </label>
        <label class="block text-sm font-bold text-gray-700 md:col-span-2">
          <span class="block mb-2">항목명</span>
          <input type="text" name="title" value="<?php echo h(cpms_overhead_view_val($formItem, 'title', '')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" required>
        </label>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <?php if ($activeCategory !== 'payroll'): ?>
          <label class="block text-sm font-bold text-gray-700">
            <span class="block mb-2">금액</span>
            <input type="text" name="amount" value="<?php echo h(cpms_overhead_view_val($formItem, 'amount', '')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" placeholder="1,000,000">
          </label>
        <?php endif; ?>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">발생일/사용일</span>
          <input type="date" name="occurred_at" value="<?php echo h(cpms_overhead_view_val($formItem, 'occurred_at', '')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">지급일/결제일</span>
          <input type="date" name="paid_at" value="<?php echo h(cpms_overhead_view_val($formItem, 'paid_at', '')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">지급방법</span>
          <input type="text" name="payment_method" value="<?php echo h(cpms_overhead_view_val($formItem, 'payment_method', '')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300" placeholder="계좌이체">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">지급처/가맹점</span>
          <input type="text" name="vendor" value="<?php echo h(cpms_overhead_view_val($formItem, 'vendor', '')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">직원/담당자/사용자</span>
          <input type="text" name="employee_name" value="<?php echo h(cpms_overhead_view_val($formItem, 'employee_name', '')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
        </label>
      </div>

      <?php $fieldDefs = cpms_company_overhead_category_fields($activeCategory); ?>
      <?php if (count($fieldDefs) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <?php foreach ($fieldDefs as $field): ?>
            <?php $key = $field['key']; ?>
            <?php if ($field['type'] === 'checkbox'): ?>
              <label class="flex items-center gap-2 text-sm font-bold text-gray-700 px-3 py-3 rounded-xl border border-gray-300">
                <input type="hidden" name="<?php echo h($key); ?>" value="0">
                <input type="checkbox" name="<?php echo h($key); ?>" value="1" <?php echo (cpms_overhead_view_val($formItem, $key, '') ? 'checked' : ''); ?>>
                <?php echo h($field['label']); ?>
              </label>
            <?php else: ?>
              <label class="block text-sm font-bold text-gray-700">
                <span class="block mb-2"><?php echo h($field['label']); ?></span>
                <input type="text" name="<?php echo h($key); ?>" value="<?php echo h(cpms_overhead_view_val($formItem, $key, '')); ?>" class="w-full px-3 py-3 rounded-xl border border-gray-300">
              </label>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">첨부파일</span>
          <input type="file" name="attachment" class="w-full px-3 py-3 rounded-xl border border-gray-300 bg-white">
        </label>
        <label class="block text-sm font-bold text-gray-700">
          <span class="block mb-2">메모</span>
          <textarea name="memo" rows="3" class="w-full px-3 py-3 rounded-xl border border-gray-300"><?php echo h(cpms_overhead_view_val($formItem, 'memo', '')); ?></textarea>
        </label>
      </div>

      <button type="submit" class="px-5 py-3 rounded-xl bg-emerald-700 text-white font-extrabold"><?php echo is_array($editItem) ? '수정 저장' : '등록'; ?></button>
    </form>
  <?php endif; ?>
</div>
    </div>
  </div>
</div>
<?php endif; ?>
