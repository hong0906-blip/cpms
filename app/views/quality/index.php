<?php
/**
 * quality/index.php
 * - QualityManagement.tsx 느낌(요청 리스트 + 첨부 + 체크리스트)
 */
require_once __DIR__ . '/../partials/SafetyChecklist.php';

$requests = array(
    array('title' => '자재 반입검수 기록(시멘트) 확인', 'status' => '대기', 'date' => '2024-01-20'),
    array('title' => '콘크리트 타설 품질 확인서 업로드', 'status' => '진행', 'date' => '2024-01-19'),
    array('title' => '균열 점검 사진 첨부', 'status' => '완료', 'date' => '2024-01-18'),
);

$checkItems = array(
    '자재 반입 검수 기록 확인',
    '시험성적서/납품서류 확인',
    '시공 품질 사진 기록',
    '하자/균열 체크 및 조치',
);
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <div class="text-sm text-gray-500">품질</div>
        <h2 class="text-2xl font-extrabold text-gray-900">품질 관리</h2>
    </div>
    <button type="button"
            class="px-5 py-3 rounded-2xl bg-gradient-to-r from-purple-500 to-indigo-500 text-white font-extrabold shadow-lg hover:shadow-xl transition"
            data-modal-open="qualityAdd">
        <span class="inline-flex items-center gap-2">
            <i data-lucide="plus" class="w-5 h-5"></i> 요청 추가
        </span>
    </button>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-extrabold text-gray-900">요청 목록(샘플)</h3>
            <div class="p-3 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-2xl shadow-lg shadow-purple-500/30">
                <i data-lucide="award" class="w-5 h-5 text-white"></i>
            </div>
        </div>

        <div class="space-y-3">
            <?php foreach ($requests as $r): ?>
                <?php
                $st = $r['status'];
                $badge = ($st === '완료') ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                       : (($st === '진행') ? 'bg-blue-50 text-blue-700 border-blue-100'
                       : 'bg-yellow-50 text-yellow-700 border-yellow-100');
                ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-extrabold text-gray-900 truncate"><?php echo h($r['title']); ?></div>
                            <div class="text-xs text-gray-500 mt-1"><?php echo h($r['date']); ?></div>
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($st); ?></span>
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <button type="button" class="px-3 py-2 rounded-2xl bg-white border border-gray-200 text-sm font-bold" data-toast="uploadQuality">
                            <i data-lucide="upload" class="w-4 h-4 inline"></i> 첨부
                        </button>
                        <button type="button" class="px-3 py-2 rounded-2xl bg-white border border-gray-200 text-sm font-bold" data-toast="downloadQuality">
                            <i data-lucide="download" class="w-4 h-4 inline"></i> 다운로드
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-4 text-xs text-gray-500">※ 파일 업/다운로드는 다음 단계(DB/파일)에서 연동합니다.</div>
    </div>

    <?php render_checklist('품질 체크리스트', $checkItems); ?>
</div>

<!-- 요청 추가 모달(샘플) -->
<div id="modal-qualityAdd" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-modal-close="qualityAdd"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-xl font-extrabold text-gray-900">품질 요청 추가(샘플)</h3>
                <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="qualityAdd">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <input class="px-4 py-3 rounded-2xl border border-gray-200 w-full" placeholder="요청 제목">
                <textarea class="px-4 py-3 rounded-2xl border border-gray-200 w-full" rows="4" placeholder="내용"></textarea>
                <button type="button" class="w-full py-3 rounded-2xl bg-gradient-to-r from-purple-500 to-indigo-500 text-white font-extrabold"
                        data-toast="saveQuality">
                    저장(샘플)
                </button>
            </div>
        </div>
    </div>
</div>