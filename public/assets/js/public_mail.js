/*
 * 파일 경로: C:\www\cpms\public\assets\js\public_mail.js
 * 네이버 메일 화면 동작 - PHP 5.6 서버 호환용 순수 JavaScript
 *
 * 중요: 직원 브라우저에서는 주기적인 메일 동기화를 실행하지 않습니다.
 * 자동수집은 public/cron/naver_mail_sync.php를 외부 예약서비스가 호출합니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.6
 */
(function () {
    'use strict';
    window.CPMS_PUBLIC_MAIL_VERSION='1.7.6';

    function page() { return document.querySelector('[data-public-mail-page]'); }
    function csrf() { var el=page(); return el ? (el.getAttribute('data-csrf-token')||'') : ''; }
    function escapeHtml(v) { var d=document.createElement('div'); d.appendChild(document.createTextNode(String(v||''))); return d.innerHTML; }
    function encodeForm(data) { var parts=[],k; for(k in data) if(Object.prototype.hasOwnProperty.call(data,k)) parts.push(encodeURIComponent(k)+'='+encodeURIComponent(data[k])); return parts.join('&'); }

    function showLoading(message) {
        hideLoading();
        var overlay=document.createElement('div'); overlay.className='pm-loading-overlay';
        overlay.innerHTML='<div class="pm-loading-box"><div class="pm-spinner"></div><strong>'+escapeHtml(message||'처리 중입니다.')+'</strong><p>잠시만 기다려 주세요.</p></div>';
        document.body.appendChild(overlay); return overlay;
    }
    function hideLoading() {
        var selectors=['.pm-loading-overlay','.loading-overlay','#loadingOverlay','#globalLoadingOverlay','[data-loading-overlay]','[data-global-loading]'];
        var i,j,nodes;
        for(i=0;i<selectors.length;i++){nodes=document.querySelectorAll(selectors[i]);for(j=0;j<nodes.length;j++){if(nodes[j]&&nodes[j].parentNode)nodes[j].parentNode.removeChild(nodes[j]);}}
        document.documentElement.classList.remove('is-loading');
        document.body.classList.remove('is-loading','loading');
    }

    function postJson(data, callback) {
        var xhr=new XMLHttpRequest(); xhr.open('POST','public_mail_action.php',true);
        xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
        xhr.onreadystatechange=function(){ if(xhr.readyState!==4)return; var result; try{result=JSON.parse(xhr.responseText);}catch(e){result={ok:false,message:'서버 응답을 확인할 수 없습니다.'};} callback(result,xhr.status); };
        xhr.send(encodeForm(data));
    }

    function bindAttachmentDownloads() {
        document.addEventListener('click',function(event){
            var target=event.target;
            while(target&&target!==document&&!(target.getAttribute&&target.getAttribute('data-mail-attachment-download')!==null)) target=target.parentNode;
            if(!target||target===document)return;
            event.preventDefault(); event.stopPropagation(); if(event.stopImmediatePropagation) event.stopImmediatePropagation();
            hideLoading();
            if((target.getAttribute('target')||'')==='_blank'){
                var opened=window.open(target.href,'_blank','noopener,noreferrer');
                if(opened) try{opened.opener=null;}catch(ignore){}
                return;
            }
            var frame=document.querySelector('iframe[name="pmMailDownloadFrame"]');
            if(!frame){ frame=document.createElement('iframe'); frame.name='pmMailDownloadFrame'; frame.className='pm-download-frame'; frame.setAttribute('aria-hidden','true'); document.body.appendChild(frame); }
            frame.src=target.href;
        },true);
    }

    function showDriveProgress(message) {
        hideDriveProgress();
        var box=document.createElement('div'); box.className='pm-drive-progress'; box.setAttribute('data-drive-progress','1');
        box.innerHTML='<strong>Google Drive 저장 중</strong><span>'+escapeHtml(message||'첨부파일을 서버에 보관하지 않고 Drive로 전송하고 있습니다.')+'</span>';
        document.body.appendChild(box); return box;
    }
    function hideDriveProgress(){var box=document.querySelector('[data-drive-progress]');if(box&&box.parentNode)box.parentNode.removeChild(box);}

    function bindDriveSaveButtons(){
        document.addEventListener('click',function(event){
            var button=event.target;
            while(button&&button!==document&&!(button.getAttribute&&button.getAttribute('data-save-attachment-drive')!==null))button=button.parentNode;
            if(!button||button===document)return;
            event.preventDefault();
            if(button.classList.contains('is-busy'))return;
            var projectId=button.getAttribute('data-project-id')||'';
            if(projectId===''&&!window.confirm('관련 현장이 지정되지 않았습니다. Google Drive의 네이버메일/미분류 폴더에 저장할까요?'))return;
            button.classList.add('is-busy'); button.setAttribute('disabled','disabled');
            showDriveProgress('대용량 파일은 시간이 걸릴 수 있지만 CPMS 서버 디스크에는 저장하지 않습니다.');
            postJson({action:'save_attachment_drive',csrf_token:csrf(),response_type:'json',message_key:button.getAttribute('data-message-key')||'',part_id:button.getAttribute('data-part-id')||'',project_id:projectId},function(result){
                button.classList.remove('is-busy'); button.removeAttribute('disabled'); hideDriveProgress();
                if(!result||!result.ok){alert(result&&result.message?result.message:'Google Drive 저장에 실패했습니다.');return;}
                alert(result.message||'Google Drive에 저장했습니다.'); window.location.reload();
            });
        },true);
    }

    function bindSyncButtons() {
        var buttons=document.querySelectorAll('[data-sync-mail]'),i;
        for(i=0;i<buttons.length;i++) buttons[i].addEventListener('click',function(){
            var mode=this.getAttribute('data-sync-mail')==='initial'?'sync_initial':'sync_new'; showLoading(mode==='sync_initial'?'메일을 가져오는 중입니다.':'새 메일을 확인하는 중입니다.');
            postJson({action:mode,csrf_token:csrf(),response_type:'json'},function(result){ hideLoading(); alert(result&&result.message?result.message:'메일 동기화 결과를 확인할 수 없습니다.'); if(result&&result.ok) window.location.reload(); });
        });
    }

    function bindConnectionTest() {
        var button=document.querySelector('[data-test-connection]'); if(!button)return;
        button.addEventListener('click',function(){
            var form=button.closest('form'),username=form.querySelector('[name="username"]'),password=form.querySelector('[name="password"]'); showLoading('네이버 메일 연결을 확인하는 중입니다.');
            postJson({action:'test_connection',csrf_token:csrf(),username:username?username.value:'',password:password?password.value:'',response_type:'json'},function(result){ hideLoading(); if(result&&result.ok) alert((result.message||'연결이 정상입니다.')+'\n메일함 '+(result.mailbox_count||0)+'개 / 받은메일함 '+(result.mail_count||0)+'건'); else alert(result&&result.message?result.message:'연결 확인에 실패했습니다.'); });
        });
    }

    function fullImportAction(action) {
        var map={start:'start_full_import',pause:'pause_full_import',resume:'resume_full_import',cancel:'cancel_full_import'};
        if(!map[action])return;
        if(action==='cancel'&&!window.confirm('전체 메일 가져오기를 취소할까요? 이미 가져온 메일은 유지됩니다.'))return;
        showLoading(action==='start'?'전체 메일함을 준비하는 중입니다.':'작업 상태를 변경하는 중입니다.');
        postJson({action:map[action],csrf_token:csrf(),response_type:'json'},function(result){
            hideLoading(); if(!result||!result.ok){alert(result&&result.message?result.message:'요청 처리에 실패했습니다.');return;}
            if(action==='start'||action==='resume') alert('전체메일 작업을 등록했습니다. 이제 외부 자동동기화가 백그라운드에서 계속 처리합니다. 이 화면을 닫아도 됩니다.');
            window.location.reload();
        });
    }

    function bindFullImport() {
        var buttons=document.querySelectorAll('[data-full-import]'),i;
        for(i=0;i<buttons.length;i++) buttons[i].addEventListener('click',function(){fullImportAction(this.getAttribute('data-full-import'));});
    }

    function metadataRepairAction(action) {
        var map={start:'start_metadata_repair',run_once:'run_metadata_repair_once',pause:'pause_metadata_repair',resume:'resume_metadata_repair',cancel:'cancel_metadata_repair'};
        if(!map[action])return;
        if(action==='start'&&!window.confirm('깨진 메일 전체 복구를 시작할까요? 버튼은 한 번만 누르면 되고 외부 자동동기화가 끝까지 처리합니다.'))return;
        if(action==='cancel'&&!window.confirm('깨진 메일 복구를 취소할까요? 이미 복구된 메일은 유지됩니다.'))return;
        showLoading(action==='start'?'복구 대상을 확인해 작업을 등록하는 중입니다.':(action==='run_once'?'깨진 메일 복구를 지금 한 번 실행하는 중입니다.':'복구 작업 상태를 변경하는 중입니다.'));
        postJson({action:map[action],csrf_token:csrf(),response_type:'json'},function(result){
            hideLoading();
            if(!result||!result.ok){alert(result&&result.message?result.message:'복구 작업 요청에 실패했습니다.');return;}
            alert(result.message||'복구 작업 상태를 변경했습니다.');
            if(result.state)updateStatusView(result.state);else window.location.reload();
        });
    }

    function bindMetadataRepair() {
        var buttons=document.querySelectorAll('[data-metadata-repair]'),i;
        for(i=0;i<buttons.length;i++)buttons[i].addEventListener('click',function(){metadataRepairAction(this.getAttribute('data-metadata-repair'));});
    }

    function updateStatusView(state) {
        state=state||{}; var full=state.full_import||{};
        var total=parseInt(full.total_count,10)||0,processed=parseInt(full.processed_count,10)||0,remaining=parseInt(full.remaining_count,10)||0,percent=total>0?Math.floor(processed*100/total):0;
        var card=document.querySelector('.pm-import-card');
        if(card){
            card.setAttribute('data-import-active',full.active?'1':'0'); card.setAttribute('data-import-paused',full.paused?'1':'0');
            var bar=card.querySelector('.pm-progress-track span'),strong=card.querySelector('.pm-progress-label strong'),label=card.querySelector('.pm-progress-label span'),message=card.querySelector('[data-import-message]');
            if(bar)bar.style.width=Math.min(100,percent)+'%'; if(strong)strong.textContent=Math.min(100,percent)+'%'; if(label)label.textContent=processed.toLocaleString()+' / '+total.toLocaleString()+'건'; if(message)message.textContent=full.last_message||'';
            var remainingNode=card.querySelector('[data-import-remaining]'); if(remainingNode)remainingNode.textContent=remaining.toLocaleString()+'건';
            var statusNode=card.querySelector('[data-import-status]'); if(statusNode)statusNode.textContent=full.active?(full.paused?'일시중지':'가져오는 중'):(full.cancelled?'취소됨':(total>0&&remaining===0?'완료':'대기'));
        }
        var repair=state.metadata_repair||{};
        var repairTotal=parseInt(repair.total_count,10)||0,repairTargets=parseInt(repair.target_count,10)||0,repairProcessed=parseInt(repair.processed_count,10)||0,repairRemaining=parseInt(repair.remaining_count,10)||0,repairRepaired=parseInt(repair.repaired_count,10)||0,repairFailed=parseInt(repair.failed_count,10)||0;
        var repairPercent=repairTotal>0?Math.floor(repairProcessed*100/repairTotal):0;
        var repairCard=document.querySelector('.pm-repair-card');
        if(repairCard){
            repairCard.setAttribute('data-repair-active',repair.active?'1':'0');repairCard.setAttribute('data-repair-paused',repair.paused?'1':'0');
            var repairBar=repairCard.querySelector('[data-repair-progress-bar]'),repairPercentNode=repairCard.querySelector('[data-repair-progress-percent]'),repairLabel=repairCard.querySelector('[data-repair-progress-label]');
            if(repairBar)repairBar.style.width=Math.min(100,repairPercent)+'%';
            if(repairPercentNode)repairPercentNode.textContent=Math.min(100,repairPercent)+'%';
            if(repairLabel)repairLabel.textContent=repairProcessed.toLocaleString()+' / '+repairTotal.toLocaleString()+'건 확인';
            var repairStatus=repairCard.querySelector('[data-repair-status]');if(repairStatus)repairStatus.textContent=repair.active?(repair.paused?'일시중지':'복구 중'):(repair.cancelled?'취소됨':(repairTotal>0&&repairRemaining===0?'완료':'대기'));
            var repairDot=repairCard.querySelector('[data-repair-status-dot]');if(repairDot){repairDot.textContent=repair.active?(repair.paused?'일시중지':'진행 중'):(repair.cancelled?'취소됨':(repairTotal>0&&repairRemaining===0?'완료':'대기'));if(repair.active)repairDot.classList.add('is-on');else repairDot.classList.remove('is-on');}
            var repairTargetsNode=repairCard.querySelector('[data-repair-targets]');if(repairTargetsNode)repairTargetsNode.textContent=repairTargets.toLocaleString()+'건';
            var repairRemainingNode=repairCard.querySelector('[data-repair-remaining]');if(repairRemainingNode)repairRemainingNode.textContent=repairRemaining.toLocaleString()+'건';
            var repairRepairedNode=repairCard.querySelector('[data-repair-repaired]');if(repairRepairedNode)repairRepairedNode.textContent=repairRepaired.toLocaleString()+'건';
            var repairFailedNode=repairCard.querySelector('[data-repair-failed]');if(repairFailedNode)repairFailedNode.textContent=repairFailed.toLocaleString()+'건';
            var repairLastRun=repairCard.querySelector('[data-repair-last-run]');if(repairLastRun)repairLastRun.textContent=repair.last_run_at||'아직 없음';
            var repairLastProcessed=repairCard.querySelector('[data-repair-last-processed]');if(repairLastProcessed)repairLastProcessed.textContent=(parseInt(repair.last_run_processed_count,10)||0).toLocaleString()+'건';
            var repairLastPing=repairCard.querySelector('[data-repair-last-ping]');if(repairLastPing)repairLastPing.textContent=repair.last_http_ping_at||'아직 없음';
            var repairHttpStatus=repairCard.querySelector('[data-repair-http-status]');if(repairHttpStatus)repairHttpStatus.textContent=repair.last_http_status||'아직 없음';
            var repairLockStatus=repairCard.querySelector('[data-repair-lock-status]');if(repairLockStatus)repairLockStatus.textContent=repair.lock_is_active?'실행 중':'해제';
            var repairWarning=repairCard.querySelector('[data-repair-cron-warning]');
            if(repairWarning){var pingTime=repair.last_http_ping_at?Date.parse(String(repair.last_http_ping_at).replace(' ','T')):0;var stale=!!repair.active&&(!pingTime||((new Date().getTime()-pingTime)>180000));repairWarning.hidden=!stale;}
            var repairMessage=repairCard.querySelector('[data-repair-message]');if(repairMessage)repairMessage.textContent=repair.last_message||'아직 복구 작업을 시작하지 않았습니다.';
        }
        var cronAt=document.querySelector('[data-cron-last-at]'); if(cronAt)cronAt.textContent=state.last_cron_at||'아직 없음';
        var cronResult=document.querySelector('[data-cron-last-result]'); if(cronResult)cronResult.textContent=state.last_cron_result||'아직 없음';
        var cronStatus=document.querySelector('[data-cron-status]'); if(cronStatus)cronStatus.textContent=state.last_cron_status==='success'?'정상':(state.last_cron_status==='error'?'오류':(state.last_cron_status==='running'?'실행 중':'등록 대기'));
    }

    function bindStatusRefresh() {
        var button=document.querySelector('[data-refresh-sync-status]'); if(!button)return;
        button.addEventListener('click',function(){
            if(button.classList.contains('is-busy'))return;
            button.classList.add('is-busy'); button.setAttribute('disabled','disabled');
            postJson({action:'get_sync_status',csrf_token:csrf(),response_type:'json'},function(result){
                button.classList.remove('is-busy'); button.removeAttribute('disabled');
                if(!result||!result.ok){alert(result&&result.message?result.message:'상태 확인에 실패했습니다.');return;}
                updateStatusView(result.state||{});
            });
        });
    }

    /* 설정 화면에서 진행 중일 때 상태 JSON만 읽습니다. 메일 수집이나 IMAP 접속은 실행하지 않습니다. */
    function bindRepairStatusPolling() {
        var settingsPage=document.querySelector('[data-public-mail-settings]');
        var repairCard=document.querySelector('.pm-repair-card');
        if(!settingsPage||!repairCard)return;
        window.setInterval(function(){
            if(repairCard.getAttribute('data-repair-active')!=='1'||document.hidden)return;
            postJson({action:'get_sync_status',csrf_token:csrf(),response_type:'json'},function(result){if(result&&result.ok)updateStatusView(result.state||{});});
        },15000);
    }

    function bindRunAutomation() {
        var button=document.querySelector('[data-run-automation]'); if(!button)return;
        button.addEventListener('click',function(){ showLoading('자동동기화를 한 번 실행하는 중입니다.'); postJson({action:'automation_tick',csrf_token:csrf(),response_type:'json',limit:20},function(result){hideLoading();alert(result&&result.message?result.message:'자동동기화 결과를 확인할 수 없습니다.');if(result&&result.ok)window.location.reload();}); });
    }

    function copyValue(input, successMessage) {
        if(!input)return;
        input.focus(); input.select();
        if(navigator.clipboard&&navigator.clipboard.writeText) navigator.clipboard.writeText(input.value).then(function(){alert(successMessage);});
        else { try{document.execCommand('copy');alert(successMessage);}catch(e){alert('값을 선택했습니다. Ctrl+C로 복사하세요.');} }
    }

    function bindCopyCron() {
        var urlButton=document.querySelector('[data-copy-cron-url]'),urlInput=document.querySelector('[data-cron-url]');
        if(urlButton&&urlInput)urlButton.addEventListener('click',function(){copyValue(urlInput,'자동동기화 주소를 복사했습니다.');});
        var keyButton=document.querySelector('[data-copy-cron-key]'),keyInput=document.querySelector('[data-cron-key]');
        if(keyButton&&keyInput)keyButton.addEventListener('click',function(){copyValue(keyInput,'자동동기화 비밀키를 복사했습니다.');});
        var headerButton=document.querySelector('[data-copy-cron-header]'),headerInput=document.querySelector('[data-cron-header]');
        if(headerButton&&headerInput)headerButton.addEventListener('click',function(){copyValue(headerInput,'요청 헤더 이름을 복사했습니다.');});
    }


    function fallbackInlineImages(container) {
        if(!container)return;
        var nodes=container.querySelectorAll('img[data-pm-inline-part]'),i;
        for(i=0;i<nodes.length;i++){
            var fallback=nodes[i].getAttribute('data-pm-inline-src')||'';
            if(fallback!=='')nodes[i].setAttribute('src',fallback);
            nodes[i].removeAttribute('data-pm-inline-part');
        }
    }

    function loadInlineImageBundle(container) {
        if(!container)return;
        var nodes=container.querySelectorAll('img[data-pm-inline-part]'),parts=[],seen={},i;
        if(!nodes.length)return;
        for(i=0;i<nodes.length;i++){
            var part=nodes[i].getAttribute('data-pm-inline-part')||'';
            if(part!==''&&!seen[part]){seen[part]=true;parts.push(part);}
        }
        if(!parts.length)return;
        var fragment=container.querySelector('[data-detail-fragment]')||container;
        var messageKey=fragment.getAttribute('data-message-key')||container.getAttribute('data-message-key')||'';
        if(messageKey===''){fallbackInlineImages(container);return;}
        var xhr=new XMLHttpRequest();
        xhr.open('GET','public_mail_action.php?action=inline_image_bundle&message_key='+encodeURIComponent(messageKey)+'&part_ids='+encodeURIComponent(parts.join(',')),true);
        xhr.setRequestHeader('X-Requested-With','XMLHttpRequest'); xhr.timeout=15000;
        xhr.onreadystatechange=function(){
            if(xhr.readyState!==4)return;
            if(xhr.status>=200&&xhr.status<300){
                var result=null; try{result=JSON.parse(xhr.responseText);}catch(e){result=null;}
                if(result&&result.ok&&result.items){
                    var images=container.querySelectorAll('img[data-pm-inline-part]'),j;
                    for(j=0;j<images.length;j++){
                        var id=images[j].getAttribute('data-pm-inline-part')||'';
                        if(result.items[id]){images[j].setAttribute('src',result.items[id]);images[j].removeAttribute('data-pm-inline-part');}
                        else {var fb=images[j].getAttribute('data-pm-inline-src')||'';if(fb!=='')images[j].setAttribute('src',fb);images[j].removeAttribute('data-pm-inline-part');}
                    }
                    return;
                }
            }
            fallbackInlineImages(container);
        };
        xhr.ontimeout=function(){fallbackInlineImages(container);};
        xhr.onerror=function(){fallbackInlineImages(container);};
        xhr.send(null);
    }

    function prepareMailImages(container) {
        if(!container)return;
        var images=container.querySelectorAll('.pm-message-body img'),i;
        for(i=0;i<images.length;i++){
            images[i].setAttribute('loading','lazy');
            images[i].setAttribute('decoding','async');
            if(images[i].getAttribute('data-pm-image-bound')==='1')continue;
            images[i].setAttribute('data-pm-image-bound','1');
            images[i].addEventListener('error',function(){this.style.display='none';});
        }
        loadInlineImageBundle(container);
    }

    function loadMailDetail(container) {
        if(!container)return;
        var messageKey=container.getAttribute('data-message-key')||'';
        if(messageKey==='')return;
        container.setAttribute('data-loading','1');
        container.innerHTML='<div class="pm-detail-local-loading"><div class="pm-spinner"></div><strong>메일 본문을 불러오는 중입니다.</strong><span>현재 화면의 다른 기능은 계속 사용할 수 있습니다.</span></div>';
        var xhr=new XMLHttpRequest();
        xhr.open('GET','public_mail_action.php?action=detail_fragment&message_key='+encodeURIComponent(messageKey)+'&_='+new Date().getTime(),true);
        xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
        xhr.timeout=12000;
        xhr.onreadystatechange=function(){
            if(xhr.readyState!==4)return;
            container.removeAttribute('data-loading');
            if(xhr.status>=200&&xhr.status<300){
                container.innerHTML=xhr.responseText;
                container.setAttribute('data-cache-ready','1');
                prepareMailImages(container);
                if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons();
            }else{
                container.innerHTML=xhr.responseText||'<div class="pm-detail-load-error"><strong>메일 본문을 불러오지 못했습니다.</strong><p>네이버 연결이 지연되고 있습니다.</p><button type="button" class="pm-btn pm-btn-light" data-retry-mail-detail>다시 시도</button></div>';
            }
        };
        xhr.ontimeout=function(){
            container.removeAttribute('data-loading');
            container.innerHTML='<div class="pm-detail-load-error"><strong>메일 원문을 불러오는 데 시간이 걸리고 있습니다.</strong><p>화면 전체는 멈추지 않았습니다. 잠시 후 다시 시도하세요.</p><button type="button" class="pm-btn pm-btn-light" data-retry-mail-detail>다시 시도</button></div>';
        };
        xhr.send(null);
    }

    function bindDetailBodyActions(){
        document.addEventListener('click',function(event){
            var retry=event.target;
            while(retry&&retry!==document&&!(retry.getAttribute&&retry.getAttribute('data-retry-mail-detail')!==null))retry=retry.parentNode;
            if(retry&&retry!==document){
                event.preventDefault();
                loadMailDetail(document.querySelector('[data-mail-detail-content]'));
                return;
            }


            var rebuild=event.target;
            while(rebuild&&rebuild!==document&&!(rebuild.getAttribute&&rebuild.getAttribute('data-rebuild-body-cache')!==null))rebuild=rebuild.parentNode;
            if(rebuild&&rebuild!==document){
                event.preventDefault();
                if(rebuild.classList.contains('is-busy'))return;
                rebuild.classList.add('is-busy'); rebuild.setAttribute('disabled','disabled');
                postJson({action:'rebuild_body_cache',csrf_token:csrf(),response_type:'json',message_key:rebuild.getAttribute('data-message-key')||''},function(result){
                    rebuild.classList.remove('is-busy'); rebuild.removeAttribute('disabled');
                    if(!result||!result.ok){alert(result&&result.message?result.message:'메일 원문을 다시 읽지 못했습니다.');return;}
                    loadMailDetail(document.querySelector('[data-mail-detail-content]'));
                });
            }
        },true);
    }

    function initDetailBody(root){
        var scope=root||document;
        var container=scope.querySelector?scope.querySelector('[data-mail-detail-content]'):null;
        if(container&&container.getAttribute('data-cache-ready')!=='1')loadMailDetail(container);
        else prepareMailImages(container);
    }

    function bindWorkflowNames(root) {
        var scope=root||document;
        var form=scope.querySelector?scope.querySelector('[data-workflow-form]'):null; if(!form)return;
        if(form.getAttribute('data-pm-bound')==='1')return;
        form.setAttribute('data-pm-bound','1');
        var ps=form.querySelector('[data-project-select]'),pn=form.querySelector('[data-project-name]'),as=form.querySelector('[data-assignee-select]'),an=form.querySelector('[data-assignee-name]');
        function update(select,hidden){if(!select||!hidden)return;var option=select.options[select.selectedIndex];hidden.value=option?(option.getAttribute('data-name')||''):'';}
        if(ps)ps.addEventListener('change',function(){update(ps,pn);}); if(as)as.addEventListener('change',function(){update(as,an);}); form.addEventListener('submit',function(){update(ps,pn);update(as,an);});
    }

    function bindTaskModal(root) {
        var scope=root||document;
        var modal=scope.querySelector?scope.querySelector('[data-task-modal]'):null,opener=scope.querySelector?scope.querySelector('[data-task-modal-open]'):null; if(!modal||!opener)return;
        if(opener.getAttribute('data-pm-bound')==='1')return;
        opener.setAttribute('data-pm-bound','1');
        function open(){modal.hidden=false;document.body.classList.add('pm-modal-open');} function close(){modal.hidden=true;document.body.classList.remove('pm-modal-open');}
        opener.addEventListener('click',open); var closers=modal.querySelectorAll('[data-task-modal-close]'),i; for(i=0;i<closers.length;i++)closers[i].addEventListener('click',close);
        document.addEventListener('keydown',function(e){if(e.keyCode===27&&!modal.hidden)close();});
    }

    function detailHost(){return document.querySelector('[data-mail-detail-host]');}
    function readerModal(){return document.querySelector('[data-mail-reader-modal]');}

    function setSelectedRow(messageKey){
        var rows=document.querySelectorAll('[data-mail-open]'),i;
        for(i=0;i<rows.length;i++){
            if((rows[i].getAttribute('data-message-key')||'')===messageKey)rows[i].classList.add('is-selected');
            else rows[i].classList.remove('is-selected');
        }
    }

    function updateMessageUrl(messageKey,replace){
        if(!window.history||!window.history.pushState)return;
        var url=window.location.href.split('#')[0],parts=url.split('?'),base=parts[0],params={},query=parts.length>1?parts.slice(1).join('?'):'';
        if(query!==''){
            var pairs=query.split('&'),i,p,key,value;
            for(i=0;i<pairs.length;i++){
                if(pairs[i]==='')continue;
                p=pairs[i].split('=');
                key=decodeURIComponent(p.shift()||'');
                value=decodeURIComponent(p.join('=')||'');
                if(key!==''&&key!=='message'&&key!=='uid')params[key]=value;
            }
        }
        if(messageKey)params.message=messageKey;
        var out=[],name;
        for(name in params){
            if(Object.prototype.hasOwnProperty.call(params,name))out.push(encodeURIComponent(name)+'='+encodeURIComponent(params[name]));
        }
        var next=base+(out.length?'?'+out.join('&'):'');
        if(replace)window.history.replaceState({message:messageKey||''},'',next);
        else window.history.pushState({message:messageKey||''},'',next);
    }

    function openReaderModal(){
        var modal=readerModal();
        if(!modal)return;
        modal.hidden=false;
        modal.setAttribute('aria-hidden','false');
        document.body.classList.add('pm-mail-reader-open');
    }

    function closeReaderModal(pushState){
        var modal=readerModal(),host=detailHost();
        if(modal){
            modal.hidden=true;
            modal.setAttribute('aria-hidden','true');
        }
        document.body.classList.remove('pm-mail-reader-open');
        setSelectedRow('');
        if(host){
            host.innerHTML='<div class="pm-detail-panel pm-detail-panel-loading"><div class="pm-detail-local-loading"><div class="pm-spinner"></div><strong>메일 정보를 여는 중입니다.</strong><span>메일 목록은 그대로 유지됩니다.</span></div></div>';
        }
        if(pushState)updateMessageUrl('',false);
    }

    function initDetailPanel(root){
        initDetailBody(root);
        bindWorkflowNames(root);
        bindTaskModal(root);
        if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons();
    }

    function loadDetailPanel(messageKey,pushState){
        var host=detailHost();
        if(!host||messageKey==='')return;
        setSelectedRow(messageKey);
        openReaderModal();
        host.innerHTML='<div class="pm-detail-panel pm-detail-panel-loading"><div class="pm-detail-local-loading"><div class="pm-spinner"></div><strong>메일 정보를 여는 중입니다.</strong><span>메일 목록과 검색조건은 그대로 유지됩니다.</span></div></div>';
        if(pushState)updateMessageUrl(messageKey,false);
        var xhr=new XMLHttpRequest();
        xhr.open('GET','public_mail_action.php?action=detail_panel&message_key='+encodeURIComponent(messageKey)+'&_='+new Date().getTime(),true);
        xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
        xhr.timeout=15000;
        xhr.onreadystatechange=function(){
            if(xhr.readyState!==4)return;
            if(xhr.status>=200&&xhr.status<300){
                host.innerHTML=xhr.responseText;
                initDetailPanel(host);
            }else{
                host.innerHTML=xhr.responseText||'<div class="pm-detail-load-error"><strong>메일 정보를 열지 못했습니다.</strong><p>잠시 후 다시 선택해 주세요.</p><button type="button" class="pm-btn pm-btn-light" data-retry-mail-panel data-message-key="'+escapeHtml(messageKey)+'">다시 시도</button></div>';
            }
        };
        xhr.ontimeout=function(){
            host.innerHTML='<div class="pm-detail-load-error"><strong>메일 정보 조회가 지연되고 있습니다.</strong><p>메일 목록은 계속 사용할 수 있습니다.</p><button type="button" class="pm-btn pm-btn-light" data-retry-mail-panel data-message-key="'+escapeHtml(messageKey)+'">다시 시도</button></div>';
        };
        xhr.onerror=function(){
            host.innerHTML='<div class="pm-detail-load-error"><strong>메일 정보를 열지 못했습니다.</strong><p>네트워크 연결을 확인해 주세요.</p><button type="button" class="pm-btn pm-btn-light" data-retry-mail-panel data-message-key="'+escapeHtml(messageKey)+'">다시 시도</button></div>';
        };
        xhr.send(null);
    }

    function currentMessageFromUrl(){
        var match=window.location.search.match(/[?&]message=([^&]+)/);
        return match&&match[1]?decodeURIComponent(match[1]):'';
    }

    function bindMailNavigation(){
        document.addEventListener('click',function(event){
            var target=event.target;
            while(target&&target!==document&&!(target.getAttribute&&target.getAttribute('data-mail-open')!==null))target=target.parentNode;
            if(target&&target!==document){
                if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||event.button===1)return;
                event.preventDefault();
                event.stopPropagation();
                hideLoading();
                loadDetailPanel(target.getAttribute('data-message-key')||'',true);
                return;
            }

            target=event.target;
            while(target&&target!==document&&!(target.getAttribute&&target.getAttribute('data-retry-mail-panel')!==null))target=target.parentNode;
            if(target&&target!==document){
                event.preventDefault();
                loadDetailPanel(target.getAttribute('data-message-key')||'',false);
                return;
            }

            target=event.target;
            while(target&&target!==document&&!(target.getAttribute&&(
                target.getAttribute('data-mail-detail-close')!==null||
                target.getAttribute('data-mail-reader-close')!==null
            )))target=target.parentNode;
            if(target&&target!==document){
                event.preventDefault();
                closeReaderModal(true);
            }
        },true);

        document.addEventListener('keydown',function(event){
            if(event.keyCode!==27)return;
            var taskModal=document.querySelector('[data-task-modal]:not([hidden])');
            if(taskModal)return;
            var modal=readerModal();
            if(modal&&!modal.hidden)closeReaderModal(true);
        });

        window.addEventListener('popstate',function(){
            var messageKey=currentMessageFromUrl();
            if(messageKey)loadDetailPanel(messageKey,false);
            else closeReaderModal(false);
        });
    }

    function openInitialMessage(){
        var root=page();
        if(!root)return;
        var messageKey=root.getAttribute('data-selected-message-key')||currentMessageFromUrl();
        if(messageKey)loadDetailPanel(messageKey,false);
    }

    function init() {
        if(!page())return;
        bindAttachmentDownloads(); bindDriveSaveButtons(); bindSyncButtons(); bindConnectionTest(); bindFullImport(); bindMetadataRepair(); bindStatusRefresh(); bindRepairStatusPolling(); bindRunAutomation(); bindCopyCron(); bindDetailBodyActions(); bindMailNavigation(); openInitialMessage();
        if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons();
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
}());
