<?php
/**
 * 파일경로: app/views/approval/cost_correction_create.php
 * 화면: 전자결재 > 비용 수정/누락 신청 작성
 * 기존 전자결재 레이아웃 안에서 종이문서 형식으로 표시합니다.
 * PHP 5.6 호환
 */
require_once __DIR__ . '/template_cost_correction.php';
require_once __DIR__ . '/../../services/ApprovalCostCorrectionService.php';

use App\Services\ApprovalCostCorrectionService;

if (!$pdo || !$u) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4">로그인 또는 DB 연결정보를 확인해주세요.</div>';
    return;
}

$lineResult = ApprovalCostCorrectionService::buildApprovalLines($pdo, $u);
$creator = isset($lineResult['creator']) && is_array($lineResult['creator']) ? $lineResult['creator'] : array();
$rawLines = isset($lineResult['lines']) && is_array($lineResult['lines']) ? $lineResult['lines'] : array();
$previewLines = array();
for ($i=0;$i<count($rawLines);$i++) {
    $emp = isset($rawLines[$i]['emp']) && is_array($rawLines[$i]['emp']) ? $rawLines[$i]['emp'] : array();
    $previewLines[] = array(
        'role_type' => isset($rawLines[$i]['role']) ? (string)$rawLines[$i]['role'] : '',
        'approver_id' => isset($emp['id']) ? (int)$emp['id'] : 0,
        'approver_name' => isset($emp['name']) ? (string)$emp['name'] : '',
        'approver_email' => isset($emp['email']) ? (string)$emp['email'] : '',
        'line_status' => 'PENDING'
    );
}

$lineError = empty($lineResult['ok'])
    ? (isset($lineResult['message']) ? (string)$lineResult['message'] : '결재선을 만들 수 없습니다.')
    : '';
$projects = ApprovalCostCorrectionService::projects($pdo);
$ceoPreview = ApprovalCostCorrectionService::ceoApprover($pdo);
$creatorName = isset($creator['name']) ? trim((string)$creator['name']) : approval_current_user_name($u);
$creatorEmail = isset($creator['email']) ? trim((string)$creator['email']) : approval_current_user_email($u);
$creatorDepartment = isset($creator['department']) ? trim((string)$creator['department']) : (isset($u['department']) ? trim((string)$u['department']) : '');

$init = array(
    'cpms_cost_correction' => '1',
    'correction_form_type' => 'labor',
    'correction_mode' => 'ADD',
    'project_id' => 0,
    'project_name' => '',
    'draft_date' => date('Y-m-d'),
    'draft_department' => $creatorDepartment,
    'drafter_name' => $creatorName,
    'writer_email' => $creatorEmail,
    'use_date' => date('Y-m-d'),
    'requested_gongsu' => '1.0',
    'cost_category' => '자재비',
    'quantity' => '1',
    'reason' => ''
);
$options = array(
    'projects' => $projects,
    'writer_email' => $creatorEmail,
    'ceo_preview' => is_array($ceoPreview) ? $ceoPreview : array()
);
?>
<div class="mb-4 flex items-center justify-between">
    <div class="flex gap-2">
        <a href="?r=approval_home" class="px-4 py-2 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800">뒤로가기</a>
        <a href="?r=approval_home" class="px-4 py-2 bg-white border-2 border-gray-400 rounded-xl font-bold text-gray-800">전자결재 목록</a>
    </div>
</div>

<?php if ($lineError !== '') { ?>
<div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800 font-bold">
    <?php echo h($lineError); ?>
</div>
<?php } ?>

<form id="costCorrectionApprovalForm" method="post" action="approval_cost_correction.php" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
    <?php render_approval_cost_correction_document($init, $previewLines, 'edit', array(), $options); ?>

    <div class="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-900">
        최종 결재 승인 후 해당 현장의 노무비/장비비/외주비/자재·안전·기타비용에 자동 반영됩니다. 신청 변동금액이 100만원 이상이면 대표 결재가 자동으로 추가됩니다.
    </div>
    <div class="mt-5 flex justify-end gap-3">
        <a href="?r=approval_home" class="px-6 py-3 rounded-xl bg-gray-200 text-gray-800 font-extrabold">취소</a>
        <button type="submit" class="px-6 py-3 rounded-xl bg-indigo-600 text-white font-extrabold"<?php echo $lineError !== '' ? ' disabled style="opacity:.45;cursor:not-allowed"' : ''; ?>>전자결재 보내기</button>
    </div>
</form>

<script>
(function(){
'use strict';
var form=document.getElementById('costCorrectionApprovalForm');
var project=document.getElementById('cc_project_id');
var projectPreview=document.getElementById('cc_project_name_preview');
var formTypeRadios=document.querySelectorAll('input[name="form_type"]');
var modeRadios=document.querySelectorAll('input[name="request_mode"]');
var laborFields=document.getElementById('cc_labor_fields');
var costFields=document.getElementById('cc_cost_fields');
var categoryRow=document.getElementById('cc_category_row');
var category=document.getElementById('cc_category');
var modifyWrap=document.getElementById('cc_modify_wrap');
var target=document.getElementById('cc_target_id');
var targetNote=document.getElementById('cc_target_note');
var laborDate=document.getElementById('cc_labor_date');
var workerSearch=document.getElementById('cc_worker_search');
var workerSuggestions=document.getElementById('cc_worker_suggestions');
var laborRows=document.getElementById('cc_labor_worker_rows');
var laborCount=document.getElementById('cc_labor_count');
var costDate=document.getElementById('cc_cost_date');
var vendorId=document.getElementById('cc_vendor_id');
var vendorSearch=document.getElementById('cc_vendor_search');
var vendorSuggestions=document.getElementById('cc_vendor_suggestions');
var itemLabel=document.getElementById('cc_item_label');
var itemName=document.getElementById('cc_item_name');
var quantityRow=document.getElementById('cc_quantity_row');
var quantityLabel=document.getElementById('cc_quantity_label');
var quantity=document.getElementById('cc_quantity');
var unitPrice=document.getElementById('cc_unit_price');
var amount=document.getElementById('cc_amount');
var memo=document.getElementById('cc_memo');
var changeAmountNotice=document.getElementById('cc_change_amount_notice');
var ceoCells=document.querySelectorAll('.cc-ceo-approval-cell');
var CEO_THRESHOLD=1000000;
var targetRows={};
var vendorTimer=null;
var vendorRequestSerial=0;
var laborRequestSerial=0;
var laborWorkerPool=[];
var selectedLaborWorkers={};

function xhr(url,done){
    var x=new XMLHttpRequest();
    x.open('GET',url,true);
    x.onreadystatechange=function(){
        if(x.readyState!==4)return;
        if(x.status>=200&&x.status<300){try{done(JSON.parse(x.responseText));}catch(e){done({ok:false});}}
        else done({ok:false});
    };
    x.send();
}
function selectedFormType(){for(var i=0;i<formTypeRadios.length;i++)if(formTypeRadios[i].checked)return formTypeRadios[i].value;return 'labor';}
function selectedMode(){for(var i=0;i<modeRadios.length;i++)if(modeRadios[i].checked)return modeRadios[i].value;return 'ADD';}
function toggle(el,show){if(!el)return;if(show)el.classList.remove('cc-hidden');else el.classList.add('cc-hidden');}
function formatMoney(v){var r=String(v||'').replace(/[^0-9]/g,'');return r?r.replace(/\B(?=(\d{3})+(?!\d))/g,','):'';}
function targetLabel(row){var a=[];if(row.use_date)a.push(row.use_date);if(row.vendor_name)a.push(row.vendor_name);if(row.item_name)a.push(row.item_name);if(row.amount!==undefined)a.push(Number(row.amount||0).toLocaleString('ko-KR')+'원');return a.join(' / ');}
function setProjectPreview(){if(!project||!projectPreview)return;var opt=project.options[project.selectedIndex];projectPreview.textContent=(opt&&project.value)?opt.text:'선택 전';}
function applyMode(){toggle(modifyWrap,selectedMode()==='MODIFY'&&selectedFormType()!=='labor');loadTargets();}

function applyFormType(){
    var t=selectedFormType();
    toggle(laborFields,t==='labor');
    toggle(costFields,t!=='labor');
    toggle(categoryRow,t==='input');
    if(itemLabel){
        if(t==='equipment')itemLabel.innerHTML='장비명/규격';
        else if(t==='outsourcing')itemLabel.innerHTML='외주 내용';
        else itemLabel.innerHTML='품목/내용';
    }
    toggle(quantityRow,t!=='outsourcing');
    if(quantityLabel)quantityLabel.textContent=(t==='equipment'?'수량/장비공수':'수량');
    if(t==='labor'){
        if(vendorId)vendorId.value='';
        if(vendorSearch)vendorSearch.value='';
        loadWorkers();
    }
    applyMode();
}
function closeWorkerSuggestions(){
    if(!workerSuggestions)return;
    workerSuggestions.style.display='none';
    workerSuggestions.innerHTML='';
}
function updateLaborCount(){
    if(!laborCount)return;
    var selected=0;
    for(var key in selectedLaborWorkers)if(selectedLaborWorkers.hasOwnProperty(key))selected++;
    laborCount.textContent='전체 '+laborWorkerPool.length+'명 · 선택 '+selected+'명';
}
function renderLaborEmpty(text){
    if(!laborRows)return;
    laborRows.innerHTML='<tr><td colspan="4" class="cc-labor-status">'+text+'</td></tr>';
}
function clearLaborSelection(text){
    selectedLaborWorkers={};
    if(workerSearch)workerSearch.value='';
    closeWorkerSuggestions();
    renderLaborEmpty(text||'근로자를 검색해 추가해주세요.');
    updateLaborCount();
}
function requestedSelect(index,current){
    var select=document.createElement('select');
    select.name='labor_requested_gongsu['+index+']';
    select.className='cc-select cc-labor-requested';
    select.setAttribute('data-current',String(current));
    var values=['0.5','1.0','1.1','1.2','1.3','1.4','1.5','2.0'];
    var currentText=Number(current||0).toFixed(1);
    var selected=false;
    for(var i=0;i<values.length;i++){
        var o=document.createElement('option');o.value=values[i];o.textContent=values[i]+' 공수';
        if(values[i]===currentText){o.selected=true;selected=true;}
        select.appendChild(o);
    }
    if(!selected){
        for(var j=0;j<select.options.length;j++)if(select.options[j].value==='1.0')select.options[j].selected=true;
    }
    select.addEventListener('change',updateApprovalThreshold);
    return select;
}
function renderSelectedWorkers(){
    if(!laborRows)return;
    laborRows.innerHTML='';
    var indexes=[];
    for(var key in selectedLaborWorkers)if(selectedLaborWorkers.hasOwnProperty(key))indexes.push(parseInt(key,10));
    indexes.sort(function(a,b){return a-b;});
    if(!indexes.length){
        renderLaborEmpty(laborWorkerPool.length?'위의 근로자 선택에서 이름을 검색해 추가해주세요.':'현장과 수정 신청 날짜를 선택하면 해당 월 근로자를 불러옵니다.');
        updateLaborCount();
        return;
    }
    for(var p=0;p<indexes.length;p++){
        (function(index){
            var row=laborWorkerPool[index]||{};
            var name=String(row.worker_name||'');
            var current=Number(row.current_gongsu||0);
            var tr=document.createElement('tr');
            tr.setAttribute('data-worker-index',String(index));

            var tdName=document.createElement('td');
            tdName.className='cc-worker-name';
            tdName.textContent=name;
            var selected=document.createElement('input');
            selected.type='checkbox';
            selected.name='labor_selected[]';
            selected.value=String(index);
            selected.className='cc-labor-select';
            selected.checked=true;
            selected.style.display='none';
            var hidden=document.createElement('input');
            hidden.type='hidden';
            hidden.name='labor_worker_name['+index+']';
            hidden.value=name;
            tdName.appendChild(selected);
            tdName.appendChild(hidden);
            tr.appendChild(tdName);

            var tdCurrent=document.createElement('td');
            tdCurrent.textContent=current.toFixed(1)+' 공수';
            tdCurrent.setAttribute('data-current',String(current));
            tr.appendChild(tdCurrent);

            var tdNew=document.createElement('td');
            tdNew.appendChild(requestedSelect(index,current));
            tr.appendChild(tdNew);

            var tdRemove=document.createElement('td');
            var remove=document.createElement('button');
            remove.type='button';
            remove.className='cc-remove-worker';
            remove.textContent='삭제';
            remove.onclick=function(){delete selectedLaborWorkers[index];renderSelectedWorkers();updateApprovalThreshold();if(workerSearch){workerSearch.value='';workerSearch.focus();}showWorkerSuggestions();};
            tdRemove.appendChild(remove);
            tr.appendChild(tdRemove);
            laborRows.appendChild(tr);
        })(indexes[p]);
    }
    updateLaborCount();
}
function addLaborWorker(index){
    index=parseInt(index,10);
    if(isNaN(index)||!laborWorkerPool[index])return;
    if(selectedLaborWorkers[index]){
        if(workerSearch){workerSearch.value='';workerSearch.focus();}
        closeWorkerSuggestions();
        return;
    }
    selectedLaborWorkers[index]=true;
    renderSelectedWorkers();
    updateApprovalThreshold();
    if(workerSearch){workerSearch.value='';workerSearch.focus();}
    closeWorkerSuggestions();
}
function showWorkerSuggestions(){
    if(!workerSearch||!workerSuggestions||selectedFormType()!=='labor')return;
    workerSuggestions.innerHTML='';
    if(!project||!project.value||!laborDate||!laborDate.value){closeWorkerSuggestions();return;}
    if(!laborWorkerPool.length){closeWorkerSuggestions();return;}
    var q=workerSearch.value.replace(/^\s+|\s+$/g,'').toLowerCase();
    var matches=[];
    for(var i=0;i<laborWorkerPool.length;i++){
        if(selectedLaborWorkers[i])continue;
        var name=String(laborWorkerPool[i].worker_name||'');
        if(q!==''&&name.toLowerCase().indexOf(q)===-1)continue;
        matches.push(i);
    }
    if(!matches.length){
        var empty=document.createElement('div');
        empty.className='cc-suggestion';
        empty.textContent=q===''?'추가할 수 있는 근로자가 없습니다.':'검색 결과가 없습니다.';
        workerSuggestions.appendChild(empty);
        workerSuggestions.style.display='block';
        return;
    }
    var limit=Math.min(matches.length,50);
    for(var m=0;m<limit;m++)(function(index){
        var row=laborWorkerPool[index]||{};
        var b=document.createElement('button');
        b.type='button';
        b.className='cc-suggestion';
        var name=document.createElement('span');
        name.textContent=String(row.worker_name||'');
        b.appendChild(name);
        var meta=document.createElement('span');
        meta.className='cc-worker-option-meta';
        meta.textContent='현재 '+Number(row.current_gongsu||0).toFixed(1)+' 공수';
        b.appendChild(meta);
        b.onclick=function(){addLaborWorker(index);};
        workerSuggestions.appendChild(b);
    })(matches[m]);
    if(matches.length>limit){
        var more=document.createElement('div');
        more.className='cc-suggestion';
        more.textContent='검색 결과가 많습니다. 이름을 더 입력해주세요. (총 '+matches.length+'명)';
        workerSuggestions.appendChild(more);
    }
    workerSuggestions.style.display='block';
}
function rawMoney(v){var r=String(v||'').replace(/[^0-9.\-]/g,'');return r&&isFinite(Number(r))?Number(r):0;}
function calculateChangeAmount(){
    var t=selectedFormType();
    var total=0;
    if(t==='labor'){
        for(var key in selectedLaborWorkers){
            if(!Object.prototype.hasOwnProperty.call(selectedLaborWorkers,key))continue;
            var index=parseInt(key,10);
            var row=laborWorkerPool[index]||{};
            var current=Number(row.current_gongsu||0);
            var wage=Number(row.wage_rate||0);
            var select=laborRows?laborRows.querySelector('select[name="labor_requested_gongsu['+index+']"]'):null;
            var next=select?Number(select.value||0):current;
            total+=Math.abs(next-current)*Math.max(0,wage);
        }
    }else{
        var nextAmount=rawMoney(amount?amount.value:0);
        if(selectedMode()==='MODIFY'){
            var original=targetRows[String(target&&target.value?target.value:'')]||{};
            total=Math.abs(nextAmount-Number(original.amount||0));
        }else{
            total=Math.abs(nextAmount);
        }
    }
    return Math.round(total);
}
function updateApprovalThreshold(){
    var changeAmount=calculateChangeAmount();
    var required=changeAmount>=CEO_THRESHOLD;
    for(var i=0;i<ceoCells.length;i++){
        if(required)ceoCells[i].classList.remove('cc-hidden');
        else ceoCells[i].classList.add('cc-hidden');
    }
    if(changeAmountNotice){
        changeAmountNotice.classList.toggle('cc-ceo-required',required);
        changeAmountNotice.textContent='현재 신청 변동금액: '+changeAmount.toLocaleString('ko-KR')+'원 · '+(required?'100만원 이상으로 대표 결재가 추가됩니다.':'100만원 미만으로 부사장까지 결재합니다.');
    }
}

function loadWorkers(){
    if(!laborRows||!project||!laborDate||selectedFormType()!=='labor')return;
    var pid=project.value||'';var day=laborDate.value||'';
    laborWorkerPool=[];
    selectedLaborWorkers={};
    closeWorkerSuggestions();
    updateApprovalThreshold();
    if(!pid){renderLaborEmpty('현장을 먼저 선택해주세요.');updateLaborCount();return;}
    if(!day){renderLaborEmpty('수정 신청 날짜를 선택해주세요.');updateLaborCount();return;}
    renderLaborEmpty('해당 월 근로자를 불러오는 중...');
    updateLaborCount();
    var serial=++laborRequestSerial;
    xhr('approval_cost_correction.php?ajax=workers&project_id='+encodeURIComponent(pid)+'&work_date='+encodeURIComponent(day),function(r){
        if(serial!==laborRequestSerial)return;
        laborWorkerPool=(r&&r.ok&&r.rows)?r.rows:[];
        selectedLaborWorkers={};
        if(!laborWorkerPool.length){renderLaborEmpty(day.substring(0,7)+' 해당 현장의 노무비 인원이 없습니다.');updateLaborCount();return;}
        renderLaborEmpty('위의 근로자 선택에서 이름을 검색해 추가해주세요.');
        updateLaborCount();
        updateApprovalThreshold();
        if(workerSearch&&document.activeElement===workerSearch)showWorkerSuggestions();
    });
}
function loadTargets(){
    if(!target)return;
    targetRows={};
    target.innerHTML='<option value="">원본자료를 선택해주세요</option>';
    if(targetNote)targetNote.textContent='현장, 비용구분, 수정 신청 날짜를 선택하면 해당 마감기간의 기존자료를 불러옵니다.';
    var t=selectedFormType();
    if(selectedMode()!=='MODIFY'||t==='labor')return;
    if(!project||!project.value){if(targetNote)targetNote.textContent='현장을 먼저 선택해주세요.';return;}
    if(!costDate||!costDate.value){if(targetNote)targetNote.textContent='수정 신청 날짜를 먼저 선택해주세요.';return;}
    var c=(t==='input'&&category)?category.value:'';
    xhr('approval_cost_correction.php?ajax=targets&project_id='+encodeURIComponent(project.value)+'&type='+encodeURIComponent(t)+'&category='+encodeURIComponent(c)+'&use_date='+encodeURIComponent(costDate.value),function(r){
        if(!r||!r.ok||!r.rows)return;
        for(var i=0;i<r.rows.length;i++){
            var row=r.rows[i],id=String(row.target_id||'');
            if(!id)continue;
            targetRows[id]=row;
            var o=document.createElement('option');
            o.value=id;
            o.textContent=targetLabel(row);
            target.appendChild(o);
        }
        if(targetNote)targetNote.textContent=r.rows.length?'선택한 수정 신청 날짜의 마감기간 자료입니다. 수정할 원본자료를 선택해주세요.':'선택한 수정 신청 날짜의 마감기간에 기존자료가 없습니다.';
        updateApprovalThreshold();
    });
}
function fillTarget(){
    if(!target)return;var row=targetRows[String(target.value||'')];if(!row)return;
    if(costDate)costDate.value=row.use_date||'';
    if(vendorId)vendorId.value=row.vendor_id?String(row.vendor_id):'';
    if(vendorSearch)vendorSearch.value=row.vendor_name||'';
    if(itemName)itemName.value=row.item_name||'';
    if(quantity)quantity.value=row.quantity!==undefined?row.quantity:1;
    if(unitPrice)unitPrice.value=formatMoney(row.unit_price||'');
    if(amount)amount.value=formatMoney(row.amount||'');
    if(memo)memo.value=row.memo||'';
    if(targetNote)targetNote.textContent='선택한 원본: '+targetLabel(row);
    updateApprovalThreshold();
}
function closeVendorSuggestions(){if(vendorSuggestions){vendorSuggestions.style.display='none';vendorSuggestions.innerHTML='';}}
function vendorText(row){var s=row.name||'';if(row.business_no)s+=' / '+row.business_no;if(row.representative)s+=' / '+row.representative;return s;}
function searchVendors(){
    if(!vendorSearch||!vendorSuggestions||selectedFormType()==='labor')return;
    var q=vendorSearch.value.replace(/^\s+|\s+$/g,'');
    if(vendorId)vendorId.value='';
    if(q===''){closeVendorSuggestions();return;}
    var serial=++vendorRequestSerial;
    xhr('approval_cost_correction.php?ajax=vendors&q='+encodeURIComponent(q),function(r){
        if(serial!==vendorRequestSerial)return;
        vendorSuggestions.innerHTML='';
        if(!r||!r.ok||!r.rows||!r.rows.length){var e=document.createElement('div');e.className='cc-suggestion';e.textContent='검색 결과 없음 - 업체관리에서 먼저 등록해주세요.';vendorSuggestions.appendChild(e);vendorSuggestions.style.display='block';return;}
        for(var i=0;i<r.rows.length;i++)(function(row){var b=document.createElement('button');b.type='button';b.className='cc-suggestion';b.textContent=vendorText(row);b.onclick=function(){vendorSearch.value=row.name||'';vendorId.value=String(row.id||'');closeVendorSuggestions();};vendorSuggestions.appendChild(b);})(r.rows[i]);
        vendorSuggestions.style.display='block';
    });
}
if(vendorSearch){
    vendorSearch.addEventListener('input',function(){if(vendorId)vendorId.value='';if(vendorTimer)clearTimeout(vendorTimer);vendorTimer=setTimeout(searchVendors,180);});
    vendorSearch.addEventListener('focus',function(){if(vendorSearch.value)searchVendors();});
}
document.addEventListener('click',function(e){
    if(vendorSuggestions&&vendorSearch&&e.target!==vendorSearch&&!vendorSuggestions.contains(e.target))closeVendorSuggestions();
    if(workerSuggestions&&workerSearch&&e.target!==workerSearch&&!workerSuggestions.contains(e.target))closeWorkerSuggestions();
});
var money=document.querySelectorAll('.js-cc-money');for(var m=0;m<money.length;m++){money[m].value=formatMoney(money[m].value);money[m].addEventListener('input',function(){this.value=formatMoney(this.value);updateApprovalThreshold();});}
if(project)project.addEventListener('change',function(){setProjectPreview();loadWorkers();loadTargets();});
if(laborDate)laborDate.addEventListener('change',loadWorkers);
if(costDate)costDate.addEventListener('change',loadTargets);
if(workerSearch){
    workerSearch.addEventListener('input',showWorkerSuggestions);
    workerSearch.addEventListener('focus',showWorkerSuggestions);
    workerSearch.addEventListener('keydown',function(e){
        if((e.key==='Enter'||e.keyCode===13)&&workerSuggestions&&workerSuggestions.style.display==='block'){
            var first=workerSuggestions.querySelector('button.cc-suggestion');
            if(first){e.preventDefault();first.click();}
        }
    });
}
if(category)category.addEventListener('change',loadTargets);
if(target)target.addEventListener('change',fillTarget);
for(var i=0;i<formTypeRadios.length;i++)formTypeRadios[i].addEventListener('change',function(){applyFormType();updateApprovalThreshold();});
for(var j=0;j<modeRadios.length;j++)modeRadios[j].addEventListener('change',function(){applyMode();updateApprovalThreshold();});
if(form)form.addEventListener('submit',function(e){
    var t=selectedFormType();
    if(!project||!project.value){alert('현장을 선택해주세요.');e.preventDefault();return;}
    if(t==='labor'){
        if(!laborDate||!laborDate.value){alert('수정 신청 날짜를 선택해주세요.');e.preventDefault();return;}
        var checked=laborRows?laborRows.querySelectorAll('.cc-labor-select:checked'):[];
        if(!checked.length){alert('수정/누락할 근로자를 한 명 이상 선택해주세요.');e.preventDefault();return;}
        for(var c=0;c<checked.length;c++){
            var tr=checked[c].parentNode.parentNode;var select=tr.querySelector('.cc-labor-requested');var cur=select?Number(select.getAttribute('data-current')||0):0;var next=select?Number(select.value||0):0;
            if(Math.abs(cur-next)<0.0001){var nameCell=tr.querySelector('.cc-worker-name');alert((nameCell?nameCell.textContent:'선택한 근로자')+'의 현재 공수와 변경 공수가 같습니다.');if(select)select.focus();e.preventDefault();return;}
        }
    }else{
        if(!vendorId||!vendorId.value){alert('업체명을 검색한 뒤 등록된 업체를 목록에서 선택해주세요.');if(vendorSearch)vendorSearch.focus();e.preventDefault();return;}
        if(!costDate||!costDate.value){alert('수정 신청 날짜를 선택해주세요.');e.preventDefault();return;}
        if(!itemName||!itemName.value.replace(/^\s+|\s+$/g,'')){alert(t==='equipment'?'장비명/규격을 입력해주세요.':(t==='outsourcing'?'외주 내용을 입력해주세요.':'품목/내용을 입력해주세요.'));e.preventDefault();return;}
        if(!amount||!String(amount.value||'').replace(/[^0-9]/g,'')){alert('금액을 입력해주세요.');e.preventDefault();return;}
        if(selectedMode()==='MODIFY'&&(!target||!target.value)){alert('수정할 기존자료를 선택해주세요.');e.preventDefault();return;}
    }
});
setProjectPreview();applyMode();applyFormType();updateApprovalThreshold();
})();
</script>
