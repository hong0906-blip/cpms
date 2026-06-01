<?php
/**
 * 견적관리 메인 화면
 * - PHP 5.6 호환
 */

use App\Core\Db;

require_once __DIR__ . '/helpers.php';

cpms_estimate_require_access(false);

$pdo = Db::pdo();
if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>';
    return;
}

if (!cpms_estimate_tables_ready($pdo)) {
    echo '<div class="bg-white/80 border border-amber-200 rounded-3xl p-6 shadow-sm">';
    echo '<div class="text-sm text-amber-700 font-bold">초기 설정 필요</div>';
    echo '<h2 class="text-2xl font-extrabold text-gray-900 mt-1">견적관리 DB 테이블이 아직 준비되지 않았습니다.</h2>';
    echo '<p class="text-gray-600 mt-2">아래 설정 화면에서 테이블 생성/확인을 먼저 실행해주세요.</p>';
    echo '<a class="inline-block mt-4 px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold" href="' . h(base_url()) . '/?r=db_setup_estimate">DB 설정 열기</a>';
    echo '</div>';
    return;
}

$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'write';
$tabs = array(
    'write' => '견적서 작성',
    'search' => '단가 검색',
    'history' => '견적 이력',
    'bid_result' => '입찰 결과 등록',
);
if (!isset($tabs[$tab])) $tab = 'write';

$today = date('Y-m-d');
$estimates = cpms_estimate_get_estimates($pdo, 200);
$workCharacterOptions = cpms_estimate_category_options($pdo, '공사성격');
$workDifficultyOptions = cpms_estimate_category_options($pdo, '공사난이도');
$workKindOptions = cpms_estimate_category_options($pdo, '공사종류');
$estimateTypeOptions = cpms_estimate_category_options($pdo, '견적성격');
$workTypeOptions = cpms_estimate_category_options($pdo, '공종');

function cpms_estimate_tab_url($tab)
{
    return '?r=estimate_home&tab=' . urlencode($tab);
}

function cpms_estimate_result_badge($result)
{
    $result = trim((string)$result);
    if ($result === '성공') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    if ($result === '성공-저가주의') return 'bg-yellow-50 text-yellow-800 border-yellow-200';
    if (strpos($result, '실패') === 0) return 'bg-red-50 text-red-700 border-red-200';
    if ($result === '보류') return 'bg-slate-50 text-slate-700 border-slate-200';
    return 'bg-gray-50 text-gray-700 border-gray-200';
}
?>

<div class="mb-6">
  <div class="text-sm text-gray-500">공무팀 전용</div>
  <h2 class="text-2xl font-extrabold text-gray-900">견적관리</h2>
  <div class="text-sm text-gray-500 mt-1">과거 견적/계약 단가를 기반으로 추천단가, 최저/중앙/평균/최고 단가와 입찰 결과를 관리합니다.</div>
</div>

<div class="mb-6 rounded-3xl border border-gray-100 bg-white/80 p-3 shadow-sm">
  <?php foreach ($tabs as $key => $label): ?>
    <?php $active = ($key === $tab); ?>
    <a href="<?php echo h(cpms_estimate_tab_url($key)); ?>"
       class="inline-flex items-center gap-2 m-1 px-4 py-2 rounded-2xl border font-extrabold <?php echo $active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'; ?>">
      <?php echo h($label); ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'write'): ?>
<form method="post" action="<?php echo h(base_url()); ?>/?r=estimate/estimate_save" id="estimateWriteForm" class="space-y-6">
  <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">

  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
      <div class="font-extrabold text-gray-900">공사 기본 정보</div>
      <div class="text-xs text-gray-500 mt-1">추천 버튼은 품목의 공종/품명/규격/단위를 기준으로 과거 단가를 조회합니다.</div>
    </div>
    <div class="p-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">견적일자</div>
        <input type="date" name="estimate_date" value="<?php echo h($today); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
      </div>
      <div class="xl:col-span-2">
        <div class="text-sm font-bold text-gray-700 mb-1">공사명 *</div>
        <input name="project_name" required class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">발주처</div>
        <input name="client" data-estimate-basic="client" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">공구</div>
        <input name="section_name" data-estimate-basic="section_name" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">원청사</div>
        <input name="contractor" data-estimate-basic="contractor" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none">
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">공사성격</div>
        <select name="work_character" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
          <option value="">선택</option>
          <?php foreach ($workCharacterOptions as $opt): ?><option value="<?php echo h($opt['item_name']); ?>"><?php echo h($opt['item_name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">공사종류</div>
        <select name="work_kind" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
          <option value="">선택</option>
          <?php foreach ($workKindOptions as $opt): ?><option value="<?php echo h($opt['item_name']); ?>"><?php echo h($opt['item_name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">간접공사비 포함</div>
        <select name="include_indirect" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
          <option value="0">미포함</option>
          <option value="1">포함</option>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">공사난이도</div>
        <select name="difficulty" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
          <option value="">선택</option>
          <?php foreach ($workDifficultyOptions as $opt): ?><option value="<?php echo h($opt['item_name']); ?>"><?php echo h($opt['item_name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">견적성격</div>
        <select name="estimate_type" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white outline-none">
          <option value="">선택</option>
          <?php foreach ($estimateTypeOptions as $opt): ?><option value="<?php echo h($opt['item_name']); ?>"><?php echo h($opt['item_name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="md:col-span-2 xl:col-span-4">
        <div class="text-sm font-bold text-gray-700 mb-1">비고</div>
        <textarea name="remark" rows="3" class="w-full px-4 py-3 rounded-2xl border border-gray-200 outline-none"></textarea>
      </div>
    </div>
  </div>

  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
      <div class="font-extrabold text-gray-900">품목 검색 후 자동 추가</div>
      <div class="text-xs text-gray-500 mt-1">과거 계약/실패 견적 단가에서 품목을 검색하고 체크하면 아래 견적 품목에 자동으로 들어갑니다.</div>
    </div>
    <div class="p-6">
      <div class="grid grid-cols-1 md:grid-cols-4 xl:grid-cols-7 gap-3">
        <input id="estimateItemSearchKeyword" placeholder="품명/규격 검색" class="px-4 py-3 rounded-2xl border border-gray-200">
        <select id="estimateItemSearchWorkType" class="px-4 py-3 rounded-2xl border border-gray-200 bg-white">
          <option value="">공종 전체</option>
          <?php foreach ($workTypeOptions as $opt): ?>
            <option value="<?php echo h($opt['item_name']); ?>"><?php echo h(($opt['parent_name'] !== '' ? $opt['parent_name'] . ' / ' : '') . $opt['item_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <select id="estimateItemSearchWorkCharacter" class="px-4 py-3 rounded-2xl border border-gray-200 bg-white">
          <option value="">공사성격 전체</option>
          <?php foreach ($workCharacterOptions as $opt): ?>
            <option value="<?php echo h($opt['item_name']); ?>"><?php echo h($opt['item_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <select id="estimateItemSearchDifficulty" class="px-4 py-3 rounded-2xl border border-gray-200 bg-white">
          <option value="">공사난이도 전체</option>
          <?php foreach ($workDifficultyOptions as $opt): ?>
            <option value="<?php echo h($opt['item_name']); ?>"><?php echo h($opt['item_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <input id="estimateItemSearchUnit" placeholder="단위" class="px-4 py-3 rounded-2xl border border-gray-200">
        <button type="button" id="estimateItemSearchBtn" class="px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold">품목 검색</button>
        <button type="button" id="estimateItemAddSelectedBtn" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">체크 품목 추가</button>
      </div>
      <div id="estimateItemSearchStatus" class="text-sm text-gray-500 mt-3">검색어를 입력하거나 공종을 선택한 뒤 검색하세요.</div>
      <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white mt-4 hidden" id="estimateItemSearchWrap">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr><th class="px-3 py-2">선택</th><th class="px-3 py-2">공종</th><th class="px-3 py-2">품명</th><th class="px-3 py-2">규격</th><th class="px-3 py-2">단위</th><th class="px-3 py-2 text-right">재료비</th><th class="px-3 py-2 text-right">노무비</th><th class="px-3 py-2 text-right">경비</th><th class="px-3 py-2 text-right">추천합계</th><th class="px-3 py-2">신뢰도</th><th class="px-3 py-2">근거</th></tr>
          </thead>
          <tbody id="estimateItemSearchTbody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
      <div>
        <div class="font-extrabold text-gray-900">견적 품목</div>
        <div class="text-xs text-gray-500 mt-1">추천단가는 중앙단가 기준이며, 제출단가는 담당자가 직접 조정할 수 있습니다.</div>
      </div>
      <button type="button" id="estimateAddRow" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">품목 추가</button>
    </div>
    <div class="p-6">
      <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
        <table class="min-w-full text-sm" id="estimateItemsTable">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr class="text-left text-gray-600">
              <th class="px-3 py-2 font-extrabold">공종</th>
              <th class="px-3 py-2 font-extrabold">품명</th>
              <th class="px-3 py-2 font-extrabold">규격</th>
              <th class="px-3 py-2 font-extrabold">단위</th>
              <th class="px-3 py-2 font-extrabold">수량</th>
              <th class="px-3 py-2 font-extrabold">추천 재료비</th>
              <th class="px-3 py-2 font-extrabold">추천 노무비</th>
              <th class="px-3 py-2 font-extrabold">추천 경비</th>
              <th class="px-3 py-2 font-extrabold">추천단가</th>
              <th class="px-3 py-2 font-extrabold">제출단가</th>
              <th class="px-3 py-2 font-extrabold">금액</th>
              <th class="px-3 py-2 font-extrabold">비고</th>
              <th class="px-3 py-2 font-extrabold">추천</th>
            </tr>
          </thead>
          <tbody>
            <?php for ($i = 0; $i < 5; $i++): ?>
            <tr class="border-b border-gray-100" data-estimate-row>
              <td class="px-2 py-2"><input name="items[<?php echo $i; ?>][work_type]" data-item-field="work_type" class="w-32 px-3 py-2 border rounded-xl"></td>
              <td class="px-2 py-2"><input name="items[<?php echo $i; ?>][item_name]" data-item-field="item_name" class="w-48 px-3 py-2 border rounded-xl"></td>
              <td class="px-2 py-2"><input name="items[<?php echo $i; ?>][spec]" data-item-field="spec" class="w-40 px-3 py-2 border rounded-xl"></td>
              <td class="px-2 py-2"><input name="items[<?php echo $i; ?>][unit]" data-item-field="unit" class="w-24 px-3 py-2 border rounded-xl"></td>
              <td class="px-2 py-2"><input name="items[<?php echo $i; ?>][qty]" data-item-field="qty" class="w-24 px-3 py-2 border rounded-xl text-right" data-estimate-calc></td>
              <td class="px-2 py-2"><input data-item-field="recommended_material_price" class="w-28 px-3 py-2 border rounded-xl text-right bg-gray-50" readonly></td>
              <td class="px-2 py-2"><input data-item-field="recommended_labor_price" class="w-28 px-3 py-2 border rounded-xl text-right bg-gray-50" readonly></td>
              <td class="px-2 py-2"><input data-item-field="recommended_expense_price" class="w-28 px-3 py-2 border rounded-xl text-right bg-gray-50" readonly></td>
              <td class="px-2 py-2"><input name="items[<?php echo $i; ?>][recommended_unit_price]" data-item-field="recommended_unit_price" class="w-32 px-3 py-2 border rounded-xl text-right" data-estimate-calc></td>
              <td class="px-2 py-2"><input name="items[<?php echo $i; ?>][submitted_unit_price]" data-item-field="submitted_unit_price" class="w-32 px-3 py-2 border rounded-xl text-right" data-estimate-calc></td>
              <td class="px-2 py-2"><input name="items[<?php echo $i; ?>][amount]" data-item-field="amount" class="w-36 px-3 py-2 border rounded-xl text-right bg-gray-50" readonly></td>
              <td class="px-2 py-2"><input name="items[<?php echo $i; ?>][remark]" data-item-field="remark" class="w-40 px-3 py-2 border rounded-xl"></td>
              <td class="px-2 py-2">
                <button type="button" class="px-3 py-2 rounded-xl bg-blue-600 text-white font-bold" data-estimate-recommend>추천</button>
                <div class="mt-1 text-xs text-gray-500 w-56" data-recommend-summary></div>
                <details class="mt-1 text-xs text-gray-600 w-72 hidden" data-recommend-evidence>
                  <summary class="cursor-pointer font-bold text-blue-700">추천 근거 보기</summary>
                  <div class="mt-1 space-y-1" data-recommend-evidence-body></div>
                </details>
              </td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>

      <div class="mt-4 flex items-center justify-end gap-3">
        <div class="text-sm text-gray-500">합계 금액</div>
        <div id="estimateTotalAmount" class="text-2xl font-extrabold text-gray-900">0</div>
      </div>
    </div>
  </div>

  <div class="flex justify-end">
    <button type="submit" class="px-7 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-extrabold shadow-lg">견적 이력 저장</button>
  </div>
</form>

<?php elseif ($tab === 'search'): ?>
<?php
  $filters = array(
      'work_type' => isset($_GET['work_type']) ? trim((string)$_GET['work_type']) : '',
      'item_name' => isset($_GET['item_name']) ? trim((string)$_GET['item_name']) : '',
      'spec' => isset($_GET['spec']) ? trim((string)$_GET['spec']) : '',
      'unit' => isset($_GET['unit']) ? trim((string)$_GET['unit']) : '',
      'client' => isset($_GET['client']) ? trim((string)$_GET['client']) : '',
      'section_name' => isset($_GET['section_name']) ? trim((string)$_GET['section_name']) : '',
      'contractor' => isset($_GET['contractor']) ? trim((string)$_GET['contractor']) : '',
      'price_type' => isset($_GET['price_type']) ? trim((string)$_GET['price_type']) : '',
  );
  $rows = cpms_estimate_search_history($pdo, $filters, 300);
  $recommend = ($filters['item_name'] !== '' && $filters['unit'] !== '') ? cpms_estimate_recommend($pdo, $filters) : null;
?>
<div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
  <div class="xl:col-span-2 bg-white/80 rounded-3xl shadow border border-gray-100 p-6">
    <div class="font-extrabold text-gray-900 mb-4">단가 검색</div>
    <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3">
      <input type="hidden" name="r" value="estimate_home">
      <input type="hidden" name="tab" value="search">
      <input name="work_type" value="<?php echo h($filters['work_type']); ?>" placeholder="공종" class="px-4 py-3 rounded-2xl border">
      <input name="item_name" value="<?php echo h($filters['item_name']); ?>" placeholder="품명" class="px-4 py-3 rounded-2xl border">
      <input name="spec" value="<?php echo h($filters['spec']); ?>" placeholder="규격" class="px-4 py-3 rounded-2xl border">
      <input name="unit" value="<?php echo h($filters['unit']); ?>" placeholder="단위" class="px-4 py-3 rounded-2xl border">
      <input name="client" value="<?php echo h($filters['client']); ?>" placeholder="발주처" class="px-4 py-3 rounded-2xl border">
      <input name="section_name" value="<?php echo h($filters['section_name']); ?>" placeholder="공구" class="px-4 py-3 rounded-2xl border">
      <input name="contractor" value="<?php echo h($filters['contractor']); ?>" placeholder="원청사" class="px-4 py-3 rounded-2xl border">
      <select name="price_type" class="px-4 py-3 rounded-2xl border bg-white">
        <option value="">전체 단가</option>
        <option value="contract" <?php echo $filters['price_type'] === 'contract' ? 'selected' : ''; ?>>계약단가</option>
        <option value="estimate" <?php echo $filters['price_type'] === 'estimate' ? 'selected' : ''; ?>>견적단가</option>
        <option value="submitted" <?php echo $filters['price_type'] === 'submitted' ? 'selected' : ''; ?>>제출단가</option>
      </select>
      <div class="md:col-span-4 flex justify-end gap-2">
        <a href="?r=estimate_home&tab=search" class="px-4 py-3 rounded-2xl border font-extrabold">초기화</a>
        <button class="px-6 py-3 rounded-2xl bg-blue-600 text-white font-extrabold">검색</button>
      </div>
    </form>
  </div>

  <div class="bg-white/80 rounded-3xl shadow border border-gray-100 p-6">
    <div class="font-extrabold text-gray-900 mb-4">과거 단가 엑셀 업로드</div>
    <form method="post" enctype="multipart/form-data" action="<?php echo h(base_url()); ?>/?r=estimate/price_import_preview" class="space-y-3">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input name="source_name" placeholder="출처명(현장/파일명)" class="w-full px-4 py-3 rounded-2xl border">
      <select name="price_type" class="w-full px-4 py-3 rounded-2xl border bg-white">
        <option value="contract">계약단가</option>
        <option value="estimate">견적단가</option>
        <option value="submitted">제출단가</option>
      </select>
      <input type="file" name="xlsx" accept=".xlsx" required class="w-full px-4 py-3 rounded-2xl border bg-white">
      <button class="w-full px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">업로드 미리보기</button>
    </form>
    <div class="text-xs text-gray-500 mt-3">권장 헤더: 공종, 품명, 규격, 단위, 단가, 발주처, 공구, 원청사, 계약일, 비고</div>
  </div>
</div>

<?php if ($recommend): ?>
<div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3 mb-6">
  <div class="rounded-2xl bg-white border p-4"><div class="text-xs text-gray-500">최저</div><div class="font-extrabold"><?php echo cpms_estimate_format_money($recommend['min_price']); ?></div></div>
  <div class="rounded-2xl bg-white border p-4"><div class="text-xs text-gray-500">중앙</div><div class="font-extrabold"><?php echo cpms_estimate_format_money($recommend['median_price']); ?></div></div>
  <div class="rounded-2xl bg-white border p-4"><div class="text-xs text-gray-500">평균</div><div class="font-extrabold"><?php echo cpms_estimate_format_money($recommend['avg_price']); ?></div></div>
  <div class="rounded-2xl bg-white border p-4"><div class="text-xs text-gray-500">최고</div><div class="font-extrabold"><?php echo cpms_estimate_format_money($recommend['max_price']); ?></div></div>
  <div class="rounded-2xl bg-white border p-4"><div class="text-xs text-gray-500">최근 계약</div><div class="font-extrabold"><?php echo cpms_estimate_format_money($recommend['recent_contract_price']); ?></div></div>
  <div class="rounded-2xl bg-white border p-4"><div class="text-xs text-gray-500">추천</div><div class="font-extrabold text-blue-700"><?php echo cpms_estimate_format_money($recommend['recommended_price']); ?></div></div>
  <div class="rounded-2xl bg-white border p-4"><div class="text-xs text-gray-500">건수</div><div class="font-extrabold"><?php echo (int)$recommend['count']; ?></div></div>
  <div class="rounded-2xl bg-white border p-4"><div class="text-xs text-gray-500">신뢰도</div><div class="font-extrabold"><?php echo h($recommend['confidence']); ?></div></div>
</div>
<?php endif; ?>

<div class="bg-white/80 rounded-3xl shadow border border-gray-100 overflow-hidden">
  <div class="px-6 py-4 border-b border-gray-100 flex justify-between">
    <div class="font-extrabold text-gray-900">과거 단가 목록</div>
    <div class="text-xs text-gray-500">총 <?php echo count($rows); ?>건</div>
  </div>
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50 text-gray-600"><tr><th class="px-3 py-2">구분</th><th class="px-3 py-2">공종</th><th class="px-3 py-2">품명</th><th class="px-3 py-2">규격</th><th class="px-3 py-2">단위</th><th class="px-3 py-2 text-right">단가</th><th class="px-3 py-2">발주처</th><th class="px-3 py-2">공구</th><th class="px-3 py-2">원청사</th><th class="px-3 py-2">계약일</th><th class="px-3 py-2">반영</th></tr></thead>
      <tbody class="divide-y">
        <?php if (count($rows) === 0): ?>
          <tr><td colspan="11" class="px-4 py-8 text-center text-gray-500">조회된 단가가 없습니다.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td class="px-3 py-2"><?php echo h(cpms_estimate_price_type_label($r['price_type'])); ?></td>
            <td class="px-3 py-2"><?php echo h($r['work_type']); ?></td>
            <td class="px-3 py-2 font-bold"><?php echo h($r['item_name']); ?></td>
            <td class="px-3 py-2"><?php echo h($r['spec']); ?></td>
            <td class="px-3 py-2"><?php echo h($r['unit']); ?></td>
            <td class="px-3 py-2 text-right font-extrabold"><?php echo cpms_estimate_format_money($r['unit_price']); ?></td>
            <td class="px-3 py-2"><?php echo h($r['client']); ?></td>
            <td class="px-3 py-2"><?php echo h($r['section_name']); ?></td>
            <td class="px-3 py-2"><?php echo h($r['contractor']); ?></td>
            <td class="px-3 py-2"><?php echo h($r['contract_date']); ?></td>
            <td class="px-3 py-2"><?php echo ((int)$r['reflect_yn'] === 1) ? '반영' : '제외'; ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'history'): ?>
<?php
  $viewEstimateId = isset($_GET['estimate_id']) ? (int)$_GET['estimate_id'] : 0;
  $viewEstimate = $viewEstimateId > 0 ? cpms_estimate_get_estimate($pdo, $viewEstimateId) : null;
  $viewItems = $viewEstimate ? cpms_estimate_get_items($pdo, $viewEstimateId) : array();
?>
<div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
  <div class="xl:col-span-1 bg-white/80 rounded-3xl shadow border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b font-extrabold">견적 이력</div>
    <div class="divide-y">
      <?php if (count($estimates) === 0): ?>
        <div class="p-6 text-sm text-gray-500">저장된 견적서가 없습니다.</div>
      <?php else: ?>
        <?php foreach ($estimates as $estimate): ?>
          <a href="?r=estimate_home&tab=history&estimate_id=<?php echo (int)$estimate['id']; ?>" class="block p-4 hover:bg-gray-50 <?php echo ((int)$estimate['id'] === $viewEstimateId) ? 'bg-blue-50' : ''; ?>">
            <div class="font-extrabold text-gray-900"><?php echo h($estimate['project_name']); ?></div>
            <div class="text-xs text-gray-500 mt-1"><?php echo h($estimate['estimate_date']); ?> / <?php echo h($estimate['client']); ?></div>
            <div class="text-sm font-bold text-gray-700 mt-2"><?php echo cpms_estimate_format_money($estimate['total_amount']); ?>원</div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="xl:col-span-2 bg-white/80 rounded-3xl shadow border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b flex items-center justify-between">
      <div class="font-extrabold">상세</div>
      <?php if ($viewEstimate): ?>
        <div class="flex gap-2">
          <a href="?r=estimate/export_xlsx&id=<?php echo (int)$viewEstimate['id']; ?>" class="px-4 py-2 rounded-2xl bg-emerald-600 text-white font-extrabold">엑셀 다운로드</a>
          <a href="?r=estimate_home&tab=bid_result&estimate_id=<?php echo (int)$viewEstimate['id']; ?>" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">입찰 결과 등록</a>
        </div>
      <?php endif; ?>
    </div>
    <?php if (!$viewEstimate): ?>
      <div class="p-8 text-center text-gray-500">좌측에서 견적서를 선택하세요.</div>
    <?php else: ?>
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-5 text-sm">
          <div class="rounded-2xl border p-4"><div class="text-gray-500">공사명</div><div class="font-extrabold"><?php echo h($viewEstimate['project_name']); ?></div></div>
          <div class="rounded-2xl border p-4"><div class="text-gray-500">발주처</div><div class="font-extrabold"><?php echo h($viewEstimate['client']); ?></div></div>
          <div class="rounded-2xl border p-4"><div class="text-gray-500">합계</div><div class="font-extrabold"><?php echo cpms_estimate_format_money($viewEstimate['total_amount']); ?>원</div></div>
        </div>
        <div class="overflow-x-auto rounded-2xl border">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50"><tr><th class="px-3 py-2">공종</th><th class="px-3 py-2">품명</th><th class="px-3 py-2">규격</th><th class="px-3 py-2">단위</th><th class="px-3 py-2 text-right">수량</th><th class="px-3 py-2 text-right">추천단가</th><th class="px-3 py-2 text-right">제출단가</th><th class="px-3 py-2 text-right">금액</th><th class="px-3 py-2">추천근거</th></tr></thead>
            <tbody class="divide-y">
              <?php foreach ($viewItems as $item): ?>
              <tr>
                <td class="px-3 py-2"><?php echo h($item['work_type']); ?></td>
                <td class="px-3 py-2 font-bold"><?php echo h($item['item_name']); ?></td>
                <td class="px-3 py-2"><?php echo h($item['spec']); ?></td>
                <td class="px-3 py-2"><?php echo h($item['unit']); ?></td>
                <td class="px-3 py-2 text-right"><?php echo cpms_estimate_format_qty($item['qty']); ?></td>
                <td class="px-3 py-2 text-right"><?php echo cpms_estimate_format_money($item['recommended_unit_price']); ?></td>
                <td class="px-3 py-2 text-right"><?php echo cpms_estimate_format_money($item['submitted_unit_price']); ?></td>
                <td class="px-3 py-2 text-right font-bold"><?php echo cpms_estimate_format_money($item['amount']); ?></td>
                <td class="px-3 py-2 text-xs text-gray-600"><?php echo h(cpms_estimate_recommendation_brief(isset($item['recommendation_json']) ? $item['recommendation_json'] : '')); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'bid_result'): ?>
<?php
  $selectedEstimateId = isset($_GET['estimate_id']) ? (int)$_GET['estimate_id'] : 0;
  $selectedEstimate = $selectedEstimateId > 0 ? cpms_estimate_get_estimate($pdo, $selectedEstimateId) : null;
  $selectedItems = $selectedEstimate ? cpms_estimate_get_items($pdo, $selectedEstimateId) : array();
  $bidOptions = cpms_estimate_bid_result_options();
?>
<div class="bg-white/80 rounded-3xl shadow border border-gray-100 p-6 mb-6">
  <div class="font-extrabold text-gray-900 mb-3">견적서 선택</div>
  <select class="w-full px-4 py-3 rounded-2xl border bg-white" onchange="if(this.value){location.href='?r=estimate_home&tab=bid_result&estimate_id='+this.value;}">
    <option value="">선택하세요</option>
    <?php foreach ($estimates as $estimate): ?>
      <option value="<?php echo (int)$estimate['id']; ?>" <?php echo ((int)$estimate['id'] === $selectedEstimateId) ? 'selected' : ''; ?>>
        <?php echo h($estimate['estimate_date'] . ' / ' . $estimate['project_name'] . ' / ' . $estimate['client']); ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>

<?php if ($selectedEstimate): ?>
<form method="post" action="<?php echo h(base_url()); ?>/?r=estimate/bid_result_save" class="space-y-6">
  <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
  <input type="hidden" name="estimate_id" value="<?php echo (int)$selectedEstimateId; ?>">
  <div class="bg-white/80 rounded-3xl shadow border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b">
      <div class="font-extrabold text-gray-900"><?php echo h($selectedEstimate['project_name']); ?></div>
      <div class="text-sm text-gray-500 mt-1">프로그램 추천단가와 실제 제출단가를 분리하여 저장합니다.</div>
    </div>
    <div class="p-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">입찰 결과</div>
        <select name="bid_result" class="w-full px-4 py-3 rounded-2xl border bg-white">
          <?php foreach ($bidOptions as $option): ?><option value="<?php echo h($option); ?>"><?php echo h($option); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">최종 계약금액</div>
        <input name="final_contract_amount" class="w-full px-4 py-3 rounded-2xl border text-right">
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">다음 추천 반영 여부</div>
        <select name="reflect_yn" class="w-full px-4 py-3 rounded-2xl border bg-white">
          <option value="1">다음 추천에 반영</option>
          <option value="0">다음 추천에서 제외</option>
        </select>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-700 mb-1">실패 사유</div>
        <input name="failure_reason" class="w-full px-4 py-3 rounded-2xl border">
      </div>
      <div class="md:col-span-2">
        <div class="text-sm font-bold text-gray-700 mb-1">특이사항</div>
        <input name="special_note" class="w-full px-4 py-3 rounded-2xl border">
      </div>
    </div>
  </div>

  <div class="bg-white/80 rounded-3xl shadow border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b font-extrabold">품목별 결과</div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50"><tr><th class="px-3 py-2">품명</th><th class="px-3 py-2">규격</th><th class="px-3 py-2">단위</th><th class="px-3 py-2 text-right">수량</th><th class="px-3 py-2 text-right">추천단가</th><th class="px-3 py-2 text-right">제출단가</th><th class="px-3 py-2 text-right">최종 계약단가</th></tr></thead>
        <tbody class="divide-y">
          <?php foreach ($selectedItems as $item): ?>
          <tr>
            <td class="px-3 py-2 font-bold"><?php echo h($item['item_name']); ?><input type="hidden" name="items[<?php echo (int)$item['id']; ?>][estimate_item_id]" value="<?php echo (int)$item['id']; ?>"></td>
            <td class="px-3 py-2"><?php echo h($item['spec']); ?></td>
            <td class="px-3 py-2"><?php echo h($item['unit']); ?></td>
            <td class="px-3 py-2 text-right"><?php echo cpms_estimate_format_qty($item['qty']); ?></td>
            <td class="px-3 py-2 text-right"><?php echo cpms_estimate_format_money($item['recommended_unit_price']); ?></td>
            <td class="px-3 py-2 text-right"><?php echo cpms_estimate_format_money($item['submitted_unit_price']); ?></td>
            <td class="px-3 py-2 text-right"><input name="items[<?php echo (int)$item['id']; ?>][final_contract_unit_price]" class="w-36 px-3 py-2 rounded-xl border text-right"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="flex justify-end"><button class="px-7 py-3 rounded-2xl bg-blue-600 text-white font-extrabold shadow">입찰 결과 저장</button></div>
</form>
<?php endif; ?>
<?php endif; ?>

<script>
(function(){
  function parseNumber(value) {
    value = (value || '').toString().replace(/,/g, '').replace(/\s/g, '');
    value = value.replace(/[^0-9.\-]/g, '');
    var n = parseFloat(value);
    return isNaN(n) ? 0 : n;
  }
  function formatNumber(value) {
    var n = parseFloat(value);
    if (isNaN(n)) return '';
    return Math.round(n).toLocaleString();
  }
  function field(row, key) {
    return row.querySelector('[data-item-field="' + key + '"]');
  }
  function setValue(row, key, value) {
    var el = field(row, key);
    if (el) el.value = value || '';
  }
  function evidenceText(row) {
    var price = formatNumber(row.unit_price);
    var date = row.contract_date || row.created_at || '';
    var type = row.price_type === 'contract' ? '계약' : (row.price_type === 'estimate' ? '견적실패' : (row.price_type || ''));
    var site = [row.project_name || row.source_name || '', row.client || '', row.section_name || '', row.contractor || ''].filter(function(v){ return !!v; }).join(' / ');
    return '[' + type + '] ' + price + '원' + (date ? ' / ' + date : '') + (site ? ' / ' + site : '');
  }
  function populateEvidence(row, recommendation) {
    var details = row.querySelector('[data-recommend-evidence]');
    var body = row.querySelector('[data-recommend-evidence-body]');
    if (!details || !body) return;
    body.innerHTML = '';
    var rows = recommendation && recommendation.rows ? recommendation.rows : [];
    if (!rows.length) {
      details.classList.add('hidden');
      return;
    }
    details.classList.remove('hidden');
    var max = Math.min(rows.length, 8);
    for (var i = 0; i < max; i++) {
      var div = document.createElement('div');
      div.className = 'rounded-xl bg-gray-50 border border-gray-100 px-2 py-1';
      div.textContent = evidenceText(rows[i]);
      body.appendChild(div);
    }
  }
  function calcRow(row) {
    var qtyEl = field(row, 'qty');
    var submittedEl = field(row, 'submitted_unit_price');
    var amountEl = field(row, 'amount');
    if (!qtyEl || !submittedEl || !amountEl) return;
    var amount = parseNumber(qtyEl.value) * parseNumber(submittedEl.value);
    amountEl.value = amount > 0 ? formatNumber(amount) : '';
    calcTotal();
  }
  function calcTotal() {
    var total = 0;
    var rows = document.querySelectorAll('[data-estimate-row]');
    for (var i = 0; i < rows.length; i++) {
      var amountEl = field(rows[i], 'amount');
      if (amountEl) total += parseNumber(amountEl.value);
    }
    var totalEl = document.getElementById('estimateTotalAmount');
    if (totalEl) totalEl.textContent = formatNumber(total);
  }
  function rowHtml(i) {
    return '<tr class="border-b border-gray-100" data-estimate-row>' +
      '<td class="px-2 py-2"><input name="items['+i+'][work_type]" data-item-field="work_type" class="w-32 px-3 py-2 border rounded-xl"></td>' +
      '<td class="px-2 py-2"><input name="items['+i+'][item_name]" data-item-field="item_name" class="w-48 px-3 py-2 border rounded-xl"></td>' +
      '<td class="px-2 py-2"><input name="items['+i+'][spec]" data-item-field="spec" class="w-40 px-3 py-2 border rounded-xl"></td>' +
      '<td class="px-2 py-2"><input name="items['+i+'][unit]" data-item-field="unit" class="w-24 px-3 py-2 border rounded-xl"></td>' +
      '<td class="px-2 py-2"><input name="items['+i+'][qty]" data-item-field="qty" class="w-24 px-3 py-2 border rounded-xl text-right" data-estimate-calc></td>' +
      '<td class="px-2 py-2"><input name="items['+i+'][recommended_unit_price]" data-item-field="recommended_unit_price" class="w-32 px-3 py-2 border rounded-xl text-right" data-estimate-calc></td>' +
      '<td class="px-2 py-2"><input name="items['+i+'][submitted_unit_price]" data-item-field="submitted_unit_price" class="w-32 px-3 py-2 border rounded-xl text-right" data-estimate-calc></td>' +
      '<td class="px-2 py-2"><input name="items['+i+'][amount]" data-item-field="amount" class="w-36 px-3 py-2 border rounded-xl text-right bg-gray-50" readonly></td>' +
      '<td class="px-2 py-2"><input name="items['+i+'][remark]" data-item-field="remark" class="w-40 px-3 py-2 border rounded-xl"></td>' +
      '<td class="px-2 py-2"><button type="button" class="px-3 py-2 rounded-xl bg-blue-600 text-white font-bold" data-estimate-recommend>추천</button><div class="mt-1 text-xs text-gray-500 w-56" data-recommend-summary></div><details class="mt-1 text-xs text-gray-600 w-72 hidden" data-recommend-evidence><summary class="cursor-pointer font-bold text-blue-700">추천 근거 보기</summary><div class="mt-1 space-y-1" data-recommend-evidence-body></div></details></td>' +
    '</tr>';
  }
  function getRows() {
    return document.querySelectorAll('[data-estimate-row]');
  }
  function addRow() {
    var tbody = document.querySelector('#estimateItemsTable tbody');
    if (!tbody) return null;
    var i = tbody.querySelectorAll('[data-estimate-row]').length;
    var box = document.createElement('tbody');
    box.innerHTML = rowHtml(i);
    var row = box.firstChild;
    tbody.appendChild(row);
    return row;
  }
  function findEmptyRow() {
    var rows = getRows();
    for (var i = 0; i < rows.length; i++) {
      var itemName = field(rows[i], 'item_name');
      if (itemName && !itemName.value) return rows[i];
    }
    return addRow();
  }
  function fillRowFromSearchItem(row, item) {
    if (!row || !item) return;
    var r = item.recommendation || {};
    setValue(row, 'work_type', item.work_type || '');
    setValue(row, 'item_name', item.item_name || '');
    setValue(row, 'spec', item.spec || '');
    setValue(row, 'unit', item.unit || '');
    if (r.recommended_price) {
      setValue(row, 'recommended_unit_price', formatNumber(r.recommended_price));
      setValue(row, 'submitted_unit_price', formatNumber(r.recommended_price));
    }
    var summary = row.querySelector('[data-recommend-summary]');
    if (summary && r.count) {
      summary.textContent = (r.match_label || '') + ' / ' + r.count + '건 / 신뢰도 ' + (r.confidence || '') + ' / 추천 ' + formatNumber(r.recommended_price);
    }
    populateEvidence(row, r);
    calcRow(row);
  }
  var addBtn = document.getElementById('estimateAddRow');
  if (addBtn) {
    addBtn.addEventListener('click', function(){
      addRow();
    });
  }
  var searchItems = [];
  var searchBtn = document.getElementById('estimateItemSearchBtn');
  var addSelectedBtn = document.getElementById('estimateItemAddSelectedBtn');
  function renderSearchResults(items) {
    searchItems = items || [];
    var wrap = document.getElementById('estimateItemSearchWrap');
    var tbody = document.getElementById('estimateItemSearchTbody');
    var status = document.getElementById('estimateItemSearchStatus');
    if (!wrap || !tbody || !status) return;
    tbody.innerHTML = '';
    if (!searchItems.length) {
      wrap.classList.add('hidden');
      status.textContent = '검색 결과가 없습니다.';
      return;
    }
    wrap.classList.remove('hidden');
    status.textContent = '검색 결과 ' + searchItems.length + '건. 필요한 품목을 체크한 뒤 추가하세요.';
    for (var i = 0; i < searchItems.length; i++) {
      var item = searchItems[i] || {};
      var rec = item.recommendation || {};
      var tr = document.createElement('tr');
      tr.className = 'border-b border-gray-100';
      function td(text, cls) {
        var cell = document.createElement('td');
        cell.className = cls || 'px-3 py-2';
        cell.textContent = text || '';
        tr.appendChild(cell);
      }
      var checkTd = document.createElement('td');
      checkTd.className = 'px-3 py-2';
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = i;
      cb.setAttribute('data-item-search-check', '1');
      checkTd.appendChild(cb);
      tr.appendChild(checkTd);
      td(item.work_type || '');
      td(item.item_name || '', 'px-3 py-2 font-bold');
      td(item.spec || '');
      td(item.unit || '');
      td(formatNumber(rec.recommended_price), 'px-3 py-2 text-right font-extrabold text-blue-700');
      td(rec.confidence || '');
      td((rec.match_label || '') + ' / ' + (rec.count || 0) + '건 / 최저 ' + formatNumber(rec.min_price) + ' / 중앙 ' + formatNumber(rec.median_price) + ' / 평균 ' + formatNumber(rec.avg_price) + ' / 최고 ' + formatNumber(rec.max_price), 'px-3 py-2 text-xs text-gray-600');
      tbody.appendChild(tr);
    }
  }
  if (searchBtn) {
    searchBtn.addEventListener('click', function(){
      var status = document.getElementById('estimateItemSearchStatus');
      if (status) status.textContent = '품목 검색 중...';
      var qs = [];
      function add(k, id) {
        var el = document.getElementById(id);
        if (el && el.value) qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(el.value));
      }
      add('q', 'estimateItemSearchKeyword');
      add('work_type', 'estimateItemSearchWorkType');
      add('work_character', 'estimateItemSearchWorkCharacter');
      add('difficulty', 'estimateItemSearchDifficulty');
      add('unit', 'estimateItemSearchUnit');
      var clientEl = document.querySelector('[data-estimate-basic="client"]');
      var sectionEl = document.querySelector('[data-estimate-basic="section_name"]');
      var contractorEl = document.querySelector('[data-estimate-basic="contractor"]');
      if (clientEl && clientEl.value) qs.push('client=' + encodeURIComponent(clientEl.value));
      if (sectionEl && sectionEl.value) qs.push('section_name=' + encodeURIComponent(sectionEl.value));
      if (contractorEl && contractorEl.value) qs.push('contractor=' + encodeURIComponent(contractorEl.value));
      fetch('?r=estimate/item_search&' + qs.join('&'), { credentials: 'same-origin' })
        .then(function(res){ return res.json(); })
        .then(function(json){
          if (!json || !json.ok) {
            if (status) status.textContent = (json && json.message) ? json.message : '검색 실패';
            return;
          }
          renderSearchResults(json.items || []);
        })
        .catch(function(){ if (status) status.textContent = '검색 중 오류가 발생했습니다.'; });
    });
  }
  if (addSelectedBtn) {
    addSelectedBtn.addEventListener('click', function(){
      var checks = document.querySelectorAll('[data-item-search-check]:checked');
      if (!checks.length) {
        alert('추가할 품목을 체크해주세요.');
        return;
      }
      for (var i = 0; i < checks.length; i++) {
        var idx = parseInt(checks[i].value, 10);
        if (!isNaN(idx) && searchItems[idx]) fillRowFromSearchItem(findEmptyRow(), searchItems[idx]);
      }
    });
  }
  document.addEventListener('input', function(e){
    if (e.target && e.target.getAttribute('data-estimate-calc') !== null) {
      var row = e.target.closest ? e.target.closest('[data-estimate-row]') : null;
      if (row) calcRow(row);
    }
  });
  document.addEventListener('click', function(e){
    var btn = e.target && e.target.getAttribute('data-estimate-recommend') !== null ? e.target : null;
    if (!btn) return;
    var row = btn.closest ? btn.closest('[data-estimate-row]') : null;
    if (!row) return;
    var summary = row.querySelector('[data-recommend-summary]');
    var itemNameEl = field(row, 'item_name');
    var unitEl = field(row, 'unit');
    if (!itemNameEl || !unitEl || !itemNameEl.value || !unitEl.value) {
      if (summary) summary.textContent = '품명과 단위를 먼저 입력해주세요.';
      return;
    }
    if (summary) summary.textContent = '추천단가 조회 중...';
    var params = {
      work_type: field(row, 'work_type') ? field(row, 'work_type').value : '',
      item_name: itemNameEl.value,
      spec: field(row, 'spec') ? field(row, 'spec').value : '',
      unit: unitEl.value,
      client: (document.querySelector('[data-estimate-basic="client"]') || {}).value || '',
      section_name: (document.querySelector('[data-estimate-basic="section_name"]') || {}).value || '',
      contractor: (document.querySelector('[data-estimate-basic="contractor"]') || {}).value || ''
    };
    var qs = [];
    for (var k in params) if (params.hasOwnProperty(k)) qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
    fetch('?r=estimate/price_recommend&' + qs.join('&'), { credentials: 'same-origin' })
      .then(function(res){ return res.json(); })
      .then(function(json){
        if (!json || !json.ok || !json.recommendation) {
          if (summary) summary.textContent = (json && json.message) ? json.message : '추천 결과가 없습니다.';
          return;
        }
        var r = json.recommendation;
        if (!r.count || !r.recommended_price) {
          if (summary) summary.textContent = '과거 단가 없음: 직접 입력 필요';
          return;
        }
        var recEl = field(row, 'recommended_unit_price');
        var subEl = field(row, 'submitted_unit_price');
        if (recEl) recEl.value = formatNumber(r.recommended_price);
        if (subEl && !subEl.value) subEl.value = formatNumber(r.recommended_price);
        calcRow(row);
        if (summary) summary.textContent = r.match_label + ' / ' + r.count + '건 / 신뢰도 ' + r.confidence + ' / 최저 ' + formatNumber(r.min_price) + ' / 중앙 ' + formatNumber(r.median_price) + ' / 평균 ' + formatNumber(r.avg_price) + ' / 최고 ' + formatNumber(r.max_price) + ' / 최근계약 ' + formatNumber(r.recent_contract_price);
        populateEvidence(row, r);
      })
      .catch(function(){
        if (summary) summary.textContent = '추천 조회 중 오류가 발생했습니다.';
      });
  });
})();
</script>
<script>
(function(){
  function parseNumber(value) {
    value = (value || '').toString().replace(/,/g, '').replace(/\s/g, '');
    value = value.replace(/[^0-9.\-]/g, '');
    var n = parseFloat(value);
    return isNaN(n) ? 0 : n;
  }
  function formatNumber(value) {
    var n = parseFloat(value);
    if (isNaN(n)) return '';
    return Math.round(n).toLocaleString();
  }
  function field(row, key) {
    return row ? row.querySelector('[data-item-field="' + key + '"]') : null;
  }
  function setValue(row, key, value) {
    var el = field(row, key);
    if (el) el.value = value || '';
  }
  function setBreakdown(row, recommendation) {
    recommendation = recommendation || {};
    setValue(row, 'recommended_material_price', recommendation.recommended_material_price ? formatNumber(recommendation.recommended_material_price) : '');
    setValue(row, 'recommended_labor_price', recommendation.recommended_labor_price ? formatNumber(recommendation.recommended_labor_price) : '');
    setValue(row, 'recommended_expense_price', recommendation.recommended_expense_price ? formatNumber(recommendation.recommended_expense_price) : '');
  }
  function evidenceText(row) {
    var type = row.price_type === 'contract' ? '계약' : (row.price_type === 'estimate' ? '견적실패' : (row.price_type || ''));
    var site = [row.project_name || row.source_name || '', row.client || '', row.section_name || '', row.contractor || ''].filter(function(v){ return !!v; }).join(' / ');
    return '[' + type + '] 재료비 ' + formatNumber(row.material_unit_price) + ' / 노무비 ' + formatNumber(row.labor_unit_price) + ' / 경비 ' + formatNumber(row.expense_unit_price) + ' / 합계 ' + formatNumber(row.unit_price) + (row.contract_date ? ' / ' + row.contract_date : '') + (site ? ' / ' + site : '');
  }
  function populateEvidence(row, recommendation) {
    var details = row.querySelector('[data-recommend-evidence]');
    var body = row.querySelector('[data-recommend-evidence-body]');
    if (!details || !body) return;
    body.innerHTML = '';
    var rows = recommendation && recommendation.rows ? recommendation.rows : [];
    if (!rows.length) {
      details.classList.add('hidden');
      return;
    }
    details.classList.remove('hidden');
    for (var i = 0; i < Math.min(rows.length, 8); i++) {
      var div = document.createElement('div');
      div.className = 'rounded-xl bg-gray-50 border border-gray-100 px-2 py-1';
      div.textContent = evidenceText(rows[i]);
      body.appendChild(div);
    }
  }
  function summaryText(r) {
    if (!r || !r.count) return '';
    return (r.match_label || '') + ' / ' + r.count + '건 / 신뢰도 ' + (r.confidence || '') + ' / 재료비 ' + formatNumber(r.recommended_material_price) + ' / 노무비 ' + formatNumber(r.recommended_labor_price) + ' / 경비 ' + formatNumber(r.recommended_expense_price) + ' / 합계 ' + formatNumber(r.recommended_price);
  }
  function applyRecommendationToRow(row, recommendation) {
    if (!row || !recommendation) return;
    setBreakdown(row, recommendation);
    if (field(row, 'recommended_unit_price') && recommendation.recommended_price) {
      field(row, 'recommended_unit_price').value = formatNumber(recommendation.recommended_price);
    }
    if (field(row, 'submitted_unit_price') && !field(row, 'submitted_unit_price').value && recommendation.recommended_price) {
      field(row, 'submitted_unit_price').value = formatNumber(recommendation.recommended_price);
    }
    var summary = row.querySelector('[data-recommend-summary]');
    if (summary) summary.textContent = summaryText(recommendation);
    populateEvidence(row, recommendation);
  }
  function rows() {
    return document.querySelectorAll('[data-estimate-row]');
  }
  function matchCachedItem(row, items) {
    var workType = field(row, 'work_type') ? field(row, 'work_type').value : '';
    var itemName = field(row, 'item_name') ? field(row, 'item_name').value : '';
    var spec = field(row, 'spec') ? field(row, 'spec').value : '';
    var unit = field(row, 'unit') ? field(row, 'unit').value : '';
    for (var i = 0; i < items.length; i++) {
      var item = items[i] || {};
      if ((item.work_type || '') === workType && (item.item_name || '') === itemName && (item.spec || '') === spec && (item.unit || '') === unit) {
        return item;
      }
    }
    return null;
  }
  function renderSearchResults(items) {
    var wrap = document.getElementById('estimateItemSearchWrap');
    var tbody = document.getElementById('estimateItemSearchTbody');
    var status = document.getElementById('estimateItemSearchStatus');
    if (!wrap || !tbody || !status) return;
    tbody.innerHTML = '';
    if (!items || !items.length) {
      wrap.classList.add('hidden');
      status.textContent = '검색 결과가 없습니다.';
      return;
    }
    wrap.classList.remove('hidden');
    status.textContent = '검색 결과 ' + items.length + '건입니다. 필요한 품목을 체크해서 추가하세요.';
    for (var i = 0; i < items.length; i++) {
      var item = items[i] || {};
      var rec = item.recommendation || {};
      var tr = document.createElement('tr');
      tr.className = 'border-b border-gray-100';
      function td(text, cls) {
        var cell = document.createElement('td');
        cell.className = cls || 'px-3 py-2';
        cell.textContent = text || '';
        tr.appendChild(cell);
      }
      var checkTd = document.createElement('td');
      checkTd.className = 'px-3 py-2';
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = i;
      cb.setAttribute('data-item-search-check', '1');
      checkTd.appendChild(cb);
      tr.appendChild(checkTd);
      td(item.work_type || '');
      td(item.item_name || '', 'px-3 py-2 font-bold');
      td(item.spec || '');
      td(item.unit || '');
      td(formatNumber(rec.recommended_material_price), 'px-3 py-2 text-right');
      td(formatNumber(rec.recommended_labor_price), 'px-3 py-2 text-right');
      td(formatNumber(rec.recommended_expense_price), 'px-3 py-2 text-right');
      td(formatNumber(rec.recommended_price), 'px-3 py-2 text-right font-extrabold text-blue-700');
      td(rec.confidence || '');
      td((rec.match_label || '') + ' / ' + (rec.count || 0) + '건 / 최저 ' + formatNumber(rec.min_price) + ' / 중앙 ' + formatNumber(rec.median_price) + ' / 평균 ' + formatNumber(rec.avg_price) + ' / 최고 ' + formatNumber(rec.max_price), 'px-3 py-2 text-xs text-gray-600');
      tbody.appendChild(tr);
    }
  }

  var originalFetch = window.fetch;
  window.fetch = function(input, init) {
    var url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
    return originalFetch.apply(window, arguments).then(function(response){
      if (url.indexOf('?r=estimate/item_search') !== -1) {
        response.clone().json().then(function(json){
          if (json && json.ok && json.items) {
            window.__estimateSearchItems = json.items;
            setTimeout(function(){ renderSearchResults(json.items); }, 0);
          }
        })["catch"](function(){});
      }
      if (url.indexOf('?r=estimate/price_recommend') !== -1) {
        response.clone().json().then(function(json){
          if (json && json.ok && json.recommendation && window.__estimateActiveRow) {
            applyRecommendationToRow(window.__estimateActiveRow, json.recommendation);
          }
        })["catch"](function(){});
      }
      return response;
    });
  };

  document.addEventListener('click', function(e){
    var btn = e.target && e.target.getAttribute('data-estimate-recommend') !== null ? e.target : null;
    if (btn && btn.closest) {
      window.__estimateActiveRow = btn.closest('[data-estimate-row]');
    }
  }, true);

  var addSelectedBtn = document.getElementById('estimateItemAddSelectedBtn');
  if (addSelectedBtn) {
    addSelectedBtn.addEventListener('click', function(){
      setTimeout(function(){
        var items = window.__estimateSearchItems || [];
        var list = rows();
        for (var i = 0; i < list.length; i++) {
          if (field(list[i], 'recommended_material_price') && !field(list[i], 'recommended_material_price').value) {
            var item = matchCachedItem(list[i], items);
            if (item && item.recommendation) applyRecommendationToRow(list[i], item.recommendation);
          }
        }
      }, 0);
    });
  }
})();
</script>
