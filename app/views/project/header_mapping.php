<?php
/**
 * C:\www\cpms\app\views\project\header_mapping.php
 * - 단가표 엑셀 헤더 매핑 설정 화면
 *   (엑셀 헤더가 바뀌어도 여기서 수정하면 계속 읽힘)
 *
 * PHP 5.6 호환
 */

use App\Core\Auth;
use App\Core\Db;

$pdo = Db::pdo();
if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>';
    return;
}

// 권한: 임원 또는 공무/관리
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. (임원/공무/관리 전용)</div>';
    return;
}

// 현재 매핑 조회
$rows = array();
try {
    $st = $pdo->query("SELECT system_field, excel_headers, is_required, updated_at FROM cpms_unit_price_header_map ORDER BY system_field");
    $rows = $st->fetchAll();
} catch (Exception $e) {
    $rows = array();
}

// 표시용 라벨
$labels = array(
    'item_name' => '품명',
    'spec' => '규격',
    'unit' => '단위',
    'qty' => '수량',
    'unit_price' => '합계단가',
    'labor_unit_price' => '노무단가',
    'material_unit_price' => '자재단가',
    'safety_unit_price' => '안전단가',
    'remark' => '비고',
);

$flash = flash_get();
?>

<div class="flex items-start justify-between gap-3 mb-6">
    <div>
        <div class="text-sm text-gray-500">설정</div>
        <h2 class="text-2xl font-extrabold text-gray-900">단가표 헤더 매핑</h2>
        <div class="text-sm text-gray-600 mt-1">
            엑셀 1행(헤더)의 이름이 바뀌면 여기서 매핑을 수정하면 됩니다. (동의어는 <b>|</b> 로 구분)
        </div>
    </div>

    <div class="flex items-center gap-2">
        <a href="<?php echo h(base_url()); ?>/?r=공무"
           class="px-4 py-2 rounded-2xl bg-gray-100 text-gray-900 font-bold hover:bg-gray-200 transition">
            ← 공무로
        </a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<div class="bg-white rounded-2xl border border-gray-200 p-5">
    <form method="post" action="<?php echo h(base_url()); ?>/?r=project/header_mapping_save">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">

        <div class="overflow-auto rounded-2xl border border-gray-200">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="p-3 text-left font-extrabold">시스템 항목</th>
                    <th class="p-3 text-left font-extrabold">엑셀 헤더(동의어 | 구분)</th>
                    <th class="p-3 text-center font-extrabold">필수</th>
                    <th class="p-3 text-left font-extrabold">수정일</th>
                </tr>
                </thead>
                <tbody class="bg-white">
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td class="p-4 text-gray-500" colspan="4">
                            매핑이 없습니다. 먼저 <b>DB 설정</b>에서 “기본 헤더 매핑 저장”을 실행하세요.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $r): ?>
                    <?php
                        $sf = (string)$r['system_field'];
                        $label = isset($labels[$sf]) ? $labels[$sf] : $sf;
                    ?>
                    <tr class="border-t border-gray-100">
                        <td class="p-3 font-bold text-gray-900">
                            <?php echo h($label); ?>
                            <div class="text-xs text-gray-500 mt-1"><?php echo h($sf); ?></div>
                        </td>
                        <td class="p-3">
                            <input name="excel_headers[<?php echo h($sf); ?>]"
                                   value="<?php echo h($r['excel_headers']); ?>"
                                   class="w-full px-3 py-2 rounded-2xl border border-gray-200"
                                   placeholder="예) 단가|금액|단가(원)">
                            <div class="text-xs text-gray-500 mt-1">예: 단가|금액|단가(원)</div>
                        </td>
                        <td class="p-3 text-center">
                            <input type="checkbox" name="is_required[<?php echo h($sf); ?>]" value="1"
                                   <?php echo ((int)$r['is_required'] === 1) ? 'checked' : ''; ?>>
                        </td>
                        <td class="p-3 text-gray-600"><?php echo h($r['updated_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end">
            <button type="submit"
                    class="px-5 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-extrabold shadow-lg hover:shadow-xl transition">
                저장
            </button>
        </div>
    </form>

    <div class="text-xs text-gray-500 mt-4">
        * “필수”로 체크된 항목의 헤더가 엑셀에 없으면 업로드가 실패합니다.<br>
        * 현재 기본값은 품명(item_name), 단가(unit_price)를 필수로 권장합니다.
    </div>
</div>