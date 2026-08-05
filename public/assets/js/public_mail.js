/*
 * 파일 경로: C:\www\cpms\public\assets\js\public_mail.js
 * 네이버 메일 화면 동작 - PHP 5.6 서버 호환용 순수 JavaScript
 *
 * 중요: 직원 브라우저에서는 주기적인 메일 동기화를 실행하지 않습니다.
 * 자동수집은 public/cron/naver_mail_sync.php를 외부 예약서비스가 호출합니다.
 */
(function () {
    'use strict';

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

    function bindWorkflowNames() {
        var form=document.querySelector('[data-workflow-form]'); if(!form)return;
        var ps=form.querySelector('[data-project-select]'),pn=form.querySelector('[data-project-name]'),as=form.querySelector('[data-assignee-select]'),an=form.querySelector('[data-assignee-name]');
        function update(select,hidden){if(!select||!hidden)return;var option=select.options[select.selectedIndex];hidden.value=option?(option.getAttribute('data-name')||''):'';}
        if(ps)ps.addEventListener('change',function(){update(ps,pn);}); if(as)as.addEventListener('change',function(){update(as,an);}); form.addEventListener('submit',function(){update(ps,pn);update(as,an);});
    }

    function bindTaskModal() {
        var modal=document.querySelector('[data-task-modal]'),opener=document.querySelector('[data-task-modal-open]'); if(!modal||!opener)return;
        function open(){modal.hidden=false;document.body.classList.add('pm-modal-open');} function close(){modal.hidden=true;document.body.classList.remove('pm-modal-open');}
        opener.addEventListener('click',open); var closers=modal.querySelectorAll('[data-task-modal-close]'),i; for(i=0;i<closers.length;i++)closers[i].addEventListener('click',close);
        document.addEventListener('keydown',function(e){if(e.keyCode===27&&!modal.hidden)close();});
    }

    function init() {
        if(!page())return;
        bindAttachmentDownloads(); bindDriveSaveButtons(); bindSyncButtons(); bindConnectionTest(); bindFullImport(); bindStatusRefresh(); bindRunAutomation(); bindCopyCron(); bindWorkflowNames(); bindTaskModal();
        if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons();
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
}());
