<?php
$summary = isset($representativeSummary) && is_array($representativeSummary) ? $representativeSummary : array();
$period = isset($summary['period']) && is_array($summary['period']) ? $summary['period'] : array();
$totals = isset($summary['totals']) && is_array($summary['totals']) ? $summary['totals'] : array();
$projects = isset($summary['projects']) && is_array($summary['projects']) ? $summary['projects'] : array();
$isDevelopmentAccess = isset($isDevelopmentAccess) ? (bool)$isDevelopmentAccess : false;

if (!function_exists('cpms_representative_view_money')) {
function cpms_representative_view_money($amount) { return number_format((float)$amount, 0) . '원'; }
}
if (!function_exists('cpms_representative_view_basis')) {
function cpms_representative_view_basis($basis) {
    if ($basis === 'confirmed') return '확정매출 기준';
    if ($basis === 'expected') return '예상매출 포함';
    if ($basis === 'mixed') return '확정·예상 혼합';
    return '매출 미등록';
}}
if (!function_exists('cpms_representative_view_managers')) {
function cpms_representative_view_managers($assignments, $role) {
    $names = array();
    foreach (is_array($assignments) ? $assignments : array() as $assignment) {
        if (!isset($assignment['member_role']) || (string)$assignment['member_role'] !== $role) continue;
        $name = isset($assignment['employee_name']) ? trim((string)$assignment['employee_name']) : '';
        if ($name !== '') $names[] = $name;
    }
    return count($names) > 0 ? implode(', ', array_unique($names)) : '-';
}}
$preset = isset($period['preset']) ? (string)$period['preset'] : 'month';
?>
<style>
  .rep-page{max-width:1440px;margin:0 auto;color:#0f172a}.rep-hero{position:relative;overflow:hidden;border-radius:28px;padding:24px;background:linear-gradient(135deg,#0f172a 0%,#172554 55%,#0c4a6e 100%);color:#fff;box-shadow:0 22px 55px rgba(15,23,42,.18)}
  .rep-hero:after{content:"";position:absolute;width:260px;height:260px;right:-100px;top:-120px;border-radius:50%;background:rgba(56,189,248,.18)}.rep-filter{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px;position:relative;z-index:1}.rep-filter a,.rep-filter button{min-height:42px;padding:10px 14px;border-radius:999px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.09);color:#e2e8f0;font-weight:800;font-size:13px}.rep-filter .is-active{background:#fff;color:#0f172a}.rep-custom{display:none;width:100%;grid-template-columns:1fr 1fr auto;gap:8px;margin-top:4px}.rep-custom.is-open{display:grid}.rep-custom input{min-width:0;border:0;border-radius:14px;padding:11px;color:#0f172a}
  .rep-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px}.rep-metric{min-width:0;border:1px solid #e2e8f0;border-radius:22px;padding:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05)}.rep-metric__label{font-size:12px;font-weight:800;color:#64748b}.rep-metric__value{margin-top:8px;font-size:clamp(19px,2vw,28px);line-height:1.15;font-weight:950;letter-spacing:-.04em;overflow-wrap:anywhere}.rep-metric--profit{background:linear-gradient(135deg,#eff6ff,#ecfeff);border-color:#bae6fd}.rep-metric--loss{background:linear-gradient(135deg,#fff1f2,#fff7ed);border-color:#fecdd3}.rep-loss{color:#dc2626}.rep-good{color:#0369a1}
  .rep-section-head{display:flex;align-items:end;justify-content:space-between;gap:12px;margin:26px 2px 12px}.rep-projects{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.rep-project{position:relative;display:flex;flex-direction:column;min-width:0;border:1px solid #e2e8f0;border-radius:24px;padding:18px;background:#fff;box-shadow:0 8px 25px rgba(15,23,42,.05);cursor:pointer;transition:.18s ease}.rep-project:hover{transform:translateY(-2px);box-shadow:0 16px 36px rgba(15,23,42,.1)}.rep-project.is-extra{display:none}.rep-projects.show-all .rep-project.is-extra{display:flex}.rep-badge{display:inline-flex;align-items:center;min-height:30px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900}.rep-badge--danger{background:#fee2e2;color:#b91c1c}.rep-badge--warning{background:#ffedd5;color:#c2410c}.rep-badge--normal{background:#dcfce7;color:#047857}.rep-badge--muted{background:#e2e8f0;color:#475569}.rep-project__name{margin-top:12px;font-size:19px;line-height:1.3;font-weight:950;overflow-wrap:anywhere}.rep-project__meta{margin-top:5px;color:#64748b;font-size:12px}.rep-project__profit{margin-top:16px;font-size:22px;font-weight:950;overflow-wrap:anywhere}.rep-project__grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:14px}.rep-project__item{min-width:0;border-radius:14px;background:#f8fafc;padding:10px}.rep-project__item span{display:block;font-size:11px;color:#64748b;font-weight:700}.rep-project__item strong{display:block;margin-top:4px;font-size:13px;overflow-wrap:anywhere}.rep-project__foot{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:15px;padding-top:13px;border-top:1px solid #f1f5f9;font-size:12px;font-weight:800}.rep-more{width:100%;min-height:50px;margin-top:14px;border:1px solid #cbd5e1;border-radius:17px;background:#fff;font-weight:900;color:#334155}
  .rep-modal{position:fixed;inset:0;z-index:100;display:none;align-items:flex-end;justify-content:center;background:rgba(15,23,42,.62);padding:18px}.rep-modal.is-open{display:flex}.rep-modal__sheet{width:min(820px,100%);max-height:90vh;overflow:auto;border-radius:28px 28px 20px 20px;background:#fff;box-shadow:0 30px 90px rgba(0,0,0,.3)}.rep-modal__head{position:sticky;top:0;z-index:2;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px;background:rgba(255,255,255,.96);border-bottom:1px solid #e2e8f0}.rep-modal__body{padding:18px}.rep-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.rep-detail-box{min-width:0;border-radius:16px;background:#f8fafc;padding:12px}.rep-detail-box span{font-size:11px;color:#64748b}.rep-detail-box strong{display:block;margin-top:4px;overflow-wrap:anywhere}.rep-person{border:1px solid #e2e8f0;border-radius:18px;padding:14px;margin-top:10px}.rep-person-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;font-size:12px}.rep-person-grid div{border-radius:12px;background:#f8fafc;padding:9px}.rep-empty{text-align:center;padding:28px;color:#64748b}.rep-close{width:42px;height:42px;border-radius:14px;background:#f1f5f9;font-size:22px;font-weight:900}
  @media(max-width:1100px){.rep-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.rep-projects{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:767px){.rep-page{margin:-2px}.rep-hero{border-radius:22px;padding:20px 16px}.rep-hero h2{font-size:25px!important}.rep-filter{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.rep-filter a,.rep-filter button{text-align:center}.rep-custom{grid-column:1/-1;grid-template-columns:1fr}.rep-metrics{grid-template-columns:1fr 1fr;gap:9px}.rep-metric{border-radius:18px;padding:14px}.rep-metric__value{font-size:18px}.rep-projects{grid-template-columns:1fr}.rep-project{border-radius:20px;padding:16px}.rep-project__profit{font-size:21px}.rep-modal{padding:0;align-items:flex-end}.rep-modal__sheet{max-height:92vh;border-radius:24px 24px 0 0;padding-bottom:calc(12px + env(safe-area-inset-bottom))}}
  @media(max-width:374px){.rep-metrics{grid-template-columns:1fr}.rep-project__grid,.rep-detail-grid{grid-template-columns:1fr}}
</style>

<div class="rep-page">
  <section class="rep-hero">
    <div class="relative z-[1]">
      <div class="flex items-center gap-2 text-sky-200 text-xs font-extrabold tracking-wide"><i data-lucide="briefcase-business" class="w-4 h-4"></i> 대표 의사결정 브리핑</div>
      <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
        <div><h2 class="text-3xl font-black tracking-tight">회사 전체 손익과 위험현장</h2><p class="mt-2 text-sm text-slate-300">선택 기간의 실제 투입비와 현장 귀속 관리비를 함께 반영했습니다.</p></div>
        <?php if ($isDevelopmentAccess): ?><span class="rounded-full bg-amber-300/15 border border-amber-200/30 px-3 py-1 text-xs font-extrabold text-amber-100">개발 테스트 접근</span><?php endif; ?>
      </div>
      <div class="rep-filter">
        <?php $periodLinks=array('month'=>'이번 달','last_month'=>'지난달','year'=>'올해 누계','all'=>'전체기간'); foreach($periodLinks as $key=>$label): ?>
          <a class="<?php echo $preset===$key?'is-active':''; ?>" href="?r=representative_management&amp;period=<?php echo h($key); ?>"><?php echo h($label); ?></a>
        <?php endforeach; ?>
        <button type="button" id="repCustomToggle" class="<?php echo $preset==='custom'?'is-active':''; ?>">직접 기간 선택</button>
        <form class="rep-custom <?php echo $preset==='custom'?'is-open':''; ?>" id="repCustomForm" method="get">
          <input type="hidden" name="r" value="representative_management"><input type="hidden" name="period" value="custom">
          <input type="date" name="start_date" value="<?php echo h(isset($period['start_date'])?$period['start_date']:''); ?>" aria-label="시작일">
          <input type="date" name="end_date" value="<?php echo h(isset($period['end_date'])?$period['end_date']:''); ?>" aria-label="종료일">
          <button type="submit">조회</button>
        </form>
      </div>
    </div>
  </section>

  <?php $metricRows=array(
    array('총 적용매출','sales',''),array('현장 직접투입비','direct_cost',''),array('현장 귀속 관리비','site_overhead',''),array('회사 공통관리비','common_overhead',''),
    array('최종 총투입원가','final_cost',''),array('예상 순이익','net_profit',((float)(isset($totals['net_profit'])?$totals['net_profit']:0)<0?'rep-metric--loss':'rep-metric--profit')),array('총 원가율','cost_rate','rate'),array('총 투입목표 금액','target_amount','')
  ); ?>
  <section class="rep-metrics" aria-label="회사 핵심 손익">
    <?php foreach($metricRows as $metric): $value=isset($totals[$metric[1]])?$totals[$metric[1]]:0; ?>
      <article class="rep-metric <?php echo h($metric[2]==='rate'?'':$metric[2]); ?>">
        <div class="rep-metric__label"><?php echo h($metric[0]); ?></div>
        <div class="rep-metric__value <?php echo $metric[1]==='net_profit'?((float)$value<0?'rep-loss':'rep-good'):''; ?>"><?php echo $metric[2]==='rate'?h(number_format((float)$value,1).'%'):h(cpms_representative_view_money($value)); ?></div>
      </article>
    <?php endforeach; ?>
  </section>

  <div class="rep-section-head"><div><h3 class="text-2xl font-black">위험현장 우선순위</h3><p class="mt-1 text-sm text-slate-500">손실·원가율·투입목표 기준으로 위험한 현장부터 표시합니다.</p></div><span class="text-xs font-bold text-slate-500">총 <?php echo count($projects); ?>개 현장</span></div>
  <section class="rep-projects" id="repProjectGrid">
    <?php foreach($projects as $index=>$project): $risk=isset($project['risk'])?$project['risk']:array('label'=>'정상','class'=>'normal'); $loss=(float)(isset($project['actual_profit'])?$project['actual_profit']:0)<0; ?>
      <article class="rep-project <?php echo $index>=5?'is-extra':''; ?>" tabindex="0" role="button" data-project-id="<?php echo (int)$project['id']; ?>" aria-label="<?php echo h($project['name']); ?> 상세보기">
        <div class="flex items-center justify-between gap-2"><span class="rep-badge rep-badge--<?php echo h($risk['class']); ?>"><?php echo h($risk['label']); ?></span><span class="text-xs font-bold text-slate-500"><?php echo h(isset($project['status'])?$project['status']:'-'); ?></span></div>
        <h4 class="rep-project__name"><?php echo h($project['name']); ?></h4>
        <div class="rep-project__meta">담당 <?php echo h(cpms_representative_view_managers(isset($project['assignments'])?$project['assignments']:array(),'main')); ?> · 부담당 <?php echo h(cpms_representative_view_managers(isset($project['assignments'])?$project['assignments']:array(),'sub')); ?></div>
        <div class="rep-project__profit <?php echo $loss?'rep-loss':'rep-good'; ?>"><?php echo $loss?'예상손실 ':'예상이익 '; ?><?php echo h(cpms_representative_view_money($project['actual_profit'])); ?></div>
        <div class="rep-project__grid">
          <div class="rep-project__item"><span>적용매출</span><strong><?php echo h(cpms_representative_view_money($project['sales'])); ?></strong></div>
          <div class="rep-project__item"><span>직접투입비</span><strong><?php echo h(cpms_representative_view_money($project['input_cost'])); ?></strong></div>
          <div class="rep-project__item"><span>급여 배분</span><strong><?php echo h(cpms_representative_view_money($project['payroll_allocated'])); ?></strong></div>
          <div class="rep-project__item"><span>카드 배분</span><strong><?php echo h(cpms_representative_view_money($project['card_allocated'])); ?></strong></div>
          <div class="rep-project__item"><span>현장 귀속 관리비</span><strong><?php echo h(cpms_representative_view_money($project['site_overhead'])); ?></strong></div>
          <div class="rep-project__item"><span>실제 원가율</span><strong class="<?php echo (float)$project['actual_cost_rate']>=100?'rep-loss':''; ?>"><?php echo h($project['actual_cost_rate_label']); ?></strong></div>
        </div>
        <div class="rep-project__foot"><span><?php echo h(cpms_representative_view_basis(isset($project['basis'])?$project['basis']:'')); ?></span><span class="<?php echo !empty($project['target_exceeded'])?'text-orange-600':'text-slate-500'; ?>"><?php echo !empty($project['target_exceeded'])?'투입목표 초과':'목표 범위'; ?> ›</span></div>
      </article>
    <?php endforeach; ?>
  </section>
  <?php if(count($projects)>5): ?><button type="button" class="rep-more" id="repShowAll">전체 현장 보기 (<?php echo count($projects); ?>)</button><?php endif; ?>
</div>

<div class="rep-modal" id="repDetailModal" aria-hidden="true"><div class="rep-modal__sheet" role="dialog" aria-modal="true" aria-labelledby="repDetailTitle"><div class="rep-modal__head"><div><div class="text-xs font-bold text-sky-700">현장 실제 손익 상세</div><h3 id="repDetailTitle" class="text-xl font-black mt-1">불러오는 중</h3></div><button type="button" class="rep-close" id="repDetailClose" aria-label="닫기">×</button></div><div class="rep-modal__body" id="repDetailBody"><div class="rep-empty">상세내역을 불러오는 중입니다.</div></div></div></div>

<script>
(function(){
  var customToggle=document.getElementById('repCustomToggle'),customForm=document.getElementById('repCustomForm');
  if(customToggle&&customForm) customToggle.onclick=function(){customForm.classList.toggle('is-open');};
  var showAll=document.getElementById('repShowAll'),grid=document.getElementById('repProjectGrid');
  if(showAll&&grid) showAll.onclick=function(){grid.classList.add('show-all');showAll.style.display='none';};
  var modal=document.getElementById('repDetailModal'),body=document.getElementById('repDetailBody'),title=document.getElementById('repDetailTitle'),close=document.getElementById('repDetailClose');
  function esc(v){var d=document.createElement('div');d.textContent=v===null||typeof v==='undefined'?'':String(v);return d.innerHTML;}
  function money(v){return Number(v||0).toLocaleString('ko-KR')+'원';}
  function managers(rows,role){var names=[],seen={};rows=rows||[];for(var i=0;i<rows.length;i++){if(rows[i].member_role!==role)continue;var name=rows[i].employee_name||'';if(name&&!seen[name]){seen[name]=true;names.push(name);}}return names.length?names.join(', '):'-';}
  function closeModal(){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');}
  if(close) close.onclick=closeModal;if(modal) modal.addEventListener('click',function(e){if(e.target===modal)closeModal();});
  function render(p){
    title.textContent=p.name||'현장 상세';
    var direct=[['노무비',p.labor],['외주비',p.outsourcing],['장비비',p.equipment],['자재비',p.material_cost],['구매품',p.purchase_cost],['기타경비',p.other_cost],['안전관리비',p.safety_cost],['공제분',p.deduction]];
    var contractPeriod=(p.start_date||'-')+' ~ '+(p.end_date||'-');
    var basic=[['계약기간',contractPeriod],['진행상태',p.status||'-'],['담당자',managers(p.assignments,'main')],['부담당자',managers(p.assignments,'sub')],['확정매출',money(p.confirmed_sales)],['예상매출',money(p.expected_sales)],['적용매출',money(p.sales)],['직접투입비',money(p.input_cost)],['현장 귀속 관리비',money(p.site_overhead)],['실제 예상손익',money(p.actual_profit)],['실제 원가율',Number(p.actual_cost_rate||0).toFixed(1)+'%'],['투입목표 금액',money(p.target_amount)],['매출 기준',p.basis_label||'-']];
    var html='<div class="rep-detail-grid">'+basic.map(function(x){return '<div class="rep-detail-box"><span>'+esc(x[0])+'</span><strong>'+esc(x[1])+'</strong></div>';}).join('')+'</div>';
    html+='<h4 class="mt-6 text-lg font-black">직접투입비 상세</h4><div class="rep-detail-grid mt-3">'+direct.map(function(x){return '<div class="rep-detail-box"><span>'+esc(x[0])+'</span><strong>'+esc(money(x[1]))+'</strong></div>';}).join('')+'</div>';
    html+='<h4 class="mt-6 text-lg font-black">현장 귀속 관리비 근거</h4>';
    if(!p.people||!p.people.length){html+='<div class="rep-empty">이 기간에 배분된 담당자 급여·카드 비용이 없습니다.</div>';}
    else p.people.forEach(function(person){html+='<div class="rep-person"><div class="font-black">'+esc(person.name)+' <span class="text-sm text-slate-500">/ '+esc(person.role)+'</span></div><div class="mt-1 text-xs text-slate-500">유효 담당 현장 '+esc(person.valid_project_count)+'개</div><div class="rep-person-grid"><div>급여 원본<br><strong>'+esc(money(person.payroll_original))+'</strong></div><div>이 현장 급여 반영<br><strong>'+esc(money(person.payroll_allocated))+'</strong></div><div>카드 원본<br><strong>'+esc(money(person.card_original))+'</strong></div><div>이 현장 카드 반영<br><strong>'+esc(money(person.card_allocated))+'</strong></div></div></div>';});
    body.innerHTML=html;
  }
  function openProject(id){modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');title.textContent='불러오는 중';body.innerHTML='<div class="rep-empty">상세내역을 불러오는 중입니다.</div>';var xhr=new XMLHttpRequest();xhr.open('GET','?r=representative_management/project_detail&project_id='+encodeURIComponent(id)+'&period=<?php echo h($preset); ?>&start_date=<?php echo h(isset($period['start_date'])?$period['start_date']:''); ?>&end_date=<?php echo h(isset($period['end_date'])?$period['end_date']:''); ?>',true);xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;if(xhr.status!==200){body.innerHTML='<div class="rep-empty rep-loss">상세내역을 불러오지 못했습니다.</div>';return;}try{var data=JSON.parse(xhr.responseText);if(!data.ok)throw new Error();render(data.project);}catch(e){body.innerHTML='<div class="rep-empty rep-loss">상세 응답을 확인하지 못했습니다.</div>';}};xhr.send();}
  var cards=document.querySelectorAll('.rep-project[data-project-id]');for(var i=0;i<cards.length;i++){cards[i].onclick=function(){openProject(this.getAttribute('data-project-id'));};cards[i].onkeydown=function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();openProject(this.getAttribute('data-project-id'));}};}
})();
</script>
