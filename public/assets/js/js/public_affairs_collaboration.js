(function(){
  // 공무 협업툴 전체화면 보드 앱: 모달, 칸반 이동, 상세패널, AJAX 저장을 담당한다.
  var appModal = document.getElementById('paCollabFullscreenModal');
  var cfg = window.paCollabConfig || {};
  var actionUrl = cfg.actionUrl || '?r=project/collaboration_action';
  var fileUrl = cfg.fileUrl || '?r=project/collaboration_file&id=';
  var hashValue = '#public-affairs-collaboration';
  var lastFocusedElement = null;
  var draggedTaskId = '';
  var dragSourceColumn = null;
  var dragSourceStatus = '';

  function getAppModal() {
    // 공무 협업툴 전체화면 모달: 기존 CPMS 본문 레이아웃에 갇히지 않도록 body 바로 아래로 이동한다.
    if (!appModal) appModal = document.getElementById('paCollabFullscreenModal');
    if (appModal && appModal.parentNode !== document.body) document.body.appendChild(appModal);
    return appModal;
  }

  function hasClass(el, className) {
    return el && (' ' + el.className + ' ').indexOf(' ' + className + ' ') > -1;
  }

  function addClass(el, className) {
    if (el && !hasClass(el, className)) el.className = el.className + ' ' + className;
  }

  function removeClass(el, className) {
    if (!el) return;
    el.className = (' ' + el.className + ' ').replace(' ' + className + ' ', ' ').replace(/^\s+|\s+$/g, '');
  }

  function bindEvent(target, eventName, handler) {
    if (target && target.addEventListener) target.addEventListener(eventName, handler, false);
    else if (target && target.attachEvent) target.attachEvent('on' + eventName, handler);
    else if (target) target['on' + eventName] = handler;
  }

  function closest(el, selector) {
    while (el && el !== document) {
      if (matches(el, selector)) return el;
      el = el.parentNode;
    }
    return null;
  }

  function matches(el, selector) {
    if (!el || el.nodeType !== 1) return false;
    var proto = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
    if (proto) return proto.call(el, selector);
    var nodes = (el.parentNode || document).querySelectorAll(selector);
    for (var i = 0; i < nodes.length; i++) if (nodes[i] === el) return true;
    return false;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
  }

  function formDataSet(fd, key, value) {
    if (fd.set) fd.set(key, value);
    else fd.append(key, value);
  }

  function showNotice(message, ok) {
    var box = document.getElementById('paCollabToast');
    if (!box) {
      box = document.createElement('div');
      box.id = 'paCollabToast';
      box.className = 'pa-toast';
      document.body.appendChild(box);
    }
    box.className = 'pa-toast ' + (ok ? 'is-ok' : 'is-error');
    box.innerHTML = escapeHtml(message || (ok ? '처리되었습니다.' : '처리에 실패했습니다.'));
    addClass(box, 'is-open');
    window.setTimeout(function(){ removeClass(box, 'is-open'); }, 2600);
  }

  function focusFirstControl() {
    var modal = getAppModal();
    if (!modal) return;
    var target = modal.querySelector('button, a, input, select, textarea');
    if (target && target.focus) target.focus();
  }

  function setHash() {
    if (window.location.hash === hashValue) return;
    if (window.history && window.history.pushState) window.history.pushState(null, '', hashValue);
    else window.location.hash = hashValue;
  }

  function clearHash() {
    if (window.location.hash !== hashValue) return;
    if (window.history && window.history.replaceState) window.history.replaceState(null, '', window.location.pathname + window.location.search);
    else window.location.hash = '';
  }

  function openAppModal(updateHash) {
    var modal = getAppModal();
    if (!modal) return;
    if (!hasClass(modal, 'is-open')) lastFocusedElement = document.activeElement;
    addClass(modal, 'is-open');
    modal.setAttribute('aria-hidden', 'false');
    modal.style.display = 'block';
    addClass(document.body, 'pa-collab-open');
    if (updateHash) setHash();
    focusFirstControl();
  }

  function closeAppModal(updateHash) {
    var modal = getAppModal();
    if (!modal) return;
    removeClass(modal, 'is-open');
    modal.setAttribute('aria-hidden', 'true');
    modal.style.display = '';
    removeClass(document.body, 'pa-collab-open');
    removeClass(modal, 'pa-collab-menu-open');
    if (updateHash) clearHash();
    if (lastFocusedElement && lastFocusedElement.focus) lastFocusedElement.focus();
  }

  function ajaxFormData(fd, callback) {
    formDataSet(fd, 'pa_ajax', '1');
    var hasCsrf = false;
    if (fd.get) hasCsrf = !!fd.get('_csrf');
    if (cfg.csrf && !hasCsrf) fd.append('_csrf', cfg.csrf);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', actionUrl, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function(){
      if (xhr.readyState !== 4) return;
      var json = null;
      try { json = JSON.parse(xhr.responseText); } catch (e) {}
      if (!json) json = {ok:false, message:'서버 응답을 읽을 수 없습니다.'};
      callback(json, xhr.status);
    };
    xhr.send(fd);
  }

  function actionRequest(action, taskId, data, callback) {
    var fd = new FormData();
    fd.append('_csrf', cfg.csrf || '');
    fd.append('action', action);
    if (taskId) fd.append('task_id', taskId);
    if (data) {
      for (var key in data) {
        if (data.hasOwnProperty(key)) fd.append(key, data[key]);
      }
    }
    ajaxFormData(fd, callback);
  }

  function getTaskIdFromHref(href) {
    var m = String(href || '').match(/[?&]task_id=([0-9]+)/);
    return m ? parseInt(m[1], 10) : 0;
  }

  function optionHtml(list, selected) {
    var html = '';
    list = list || [];
    for (var i = 0; i < list.length; i++) {
      var value = String(list[i]);
      html += '<option value="' + escapeHtml(value) + '"' + (String(selected) === value ? ' selected' : '') + '>' + escapeHtml(value) + '</option>';
    }
    return html;
  }

  function employeeOptions(selected, multiple) {
    var html = multiple ? '' : '<option value="">선택하세요</option>';
    var selectedMap = {};
    if (multiple && selected) {
      for (var s = 0; s < selected.length; s++) selectedMap[String(selected[s])] = true;
    }
    var employees = cfg.employees || [];
    for (var i = 0; i < employees.length; i++) {
      var e = employees[i] || {};
      var id = String(e.id || '');
      var label = (e.name || '-') + ' / ' + (e.department || '-') + ' / ' + (e.position || '-');
      var isSelected = multiple ? !!selectedMap[id] : String(selected || '') === id;
      html += '<option value="' + escapeHtml(id) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
    }
    return html;
  }

  function dueText(task) {
    if (!task || !task.due_date) return '-';
    return String(task.due_date) + (task.due_time ? ' ' + String(task.due_time) : '');
  }

  function fileSize(bytes) {
    bytes = parseInt(bytes || 0, 10);
    if (bytes >= 1048576) return Math.round(bytes / 104857.6) / 10 + 'MB';
    if (bytes >= 1024) return Math.round(bytes / 102.4) / 10 + 'KB';
    return bytes + 'B';
  }

  function findTemplate(templateId) {
    templateId = parseInt(templateId || 0, 10);
    var templates = cfg.templates || [];
    for (var i = 0; i < templates.length; i++) {
      if (parseInt(templates[i].id || 0, 10) === templateId) return templates[i];
    }
    return null;
  }

  function replaceTemplateVars(text) {
    var ctx = cfg.projectContext || {};
    return String(text || '')
      .replace(/\{\{project_name\}\}/g, ctx.project_name || '')
      .replace(/\{\{today\}\}/g, ctx.today || '')
      .replace(/\{\{client\}\}/g, ctx.client || '')
      .replace(/\{\{contractor\}\}/g, ctx.contractor || '');
  }

  function dateFromDueDays(days) {
    days = parseInt(days || 0, 10);
    if (days <= 0) return '';
    var d = new Date();
    d.setDate(d.getDate() + days);
    var yyyy = d.getFullYear();
    var mm = String(d.getMonth() + 1);
    var dd = String(d.getDate());
    if (mm.length < 2) mm = '0' + mm;
    if (dd.length < 2) dd = '0' + dd;
    return yyyy + '-' + mm + '-' + dd;
  }

  function checklistText(task) {
    var total = parseInt(task && task.checklist_total ? task.checklist_total : 0, 10);
    var done = parseInt(task && task.checklist_done ? task.checklist_done : 0, 10);
    if (total <= 0) return '';
    return '체크 ' + done + '/' + total;
  }

  function mentionHtml(text) {
    return escapeHtml(text || '').replace(/@([^\s@]+)/g, '<span class="pa-mention">@$1</span>');
  }

  function priorityClass(priority) {
    if (priority === '긴급') return 'pa-priority-urgent';
    if (priority === '높음') return 'pa-priority-high';
    if (priority === '낮음') return 'pa-priority-low';
    return 'pa-priority-normal';
  }

  function statusClass(status) {
    if (status === '완료') return 'pa-status-done';
    if (status === '반려') return 'pa-status-reject';
    if (status === '보류') return 'pa-status-hold';
    if (status === '결재대기') return 'pa-status-approval';
    if (status === '자료대기') return 'pa-status-wait';
    if (status === '진행중') return 'pa-status-progress';
    return 'pa-status-new';
  }

  function cardHtml(task, canEdit) {
    task = task || {};
    var taskId = task.id || task.task_id || 0;
    var taskNo = task.task_no || ('PA-' + taskId);
    var classes = 'pa-card';
    if (task.priority === '긴급') classes += ' is-urgent';
    if (parseInt(task.is_delayed || 0, 10)) classes += ' is-delayed';
    if (task.status === '완료') classes += ' is-done';
    var searchText = [taskNo, task.title, task.content, task.project_name, task.task_type, task.assignee_name, task.requester_name, (task.reference_names || []).join(' ')].join(' ');
    var html = '<div class="' + classes + '"' + (canEdit ? ' draggable="true"' : '') +
      ' data-pa-task-id="' + escapeHtml(taskId) + '"' +
      ' data-pa-task-no="' + escapeHtml(taskNo) + '"' +
      ' data-pa-status="' + escapeHtml(task.status || '') + '"' +
      ' data-pa-can-edit="' + (canEdit ? '1' : '0') + '"' +
      ' data-pa-search="' + escapeHtml(searchText) + '">';
    html += '<div class="pa-card-top"><span class="pa-no">' + escapeHtml(taskNo) + '</span><span class="pa-type">' + escapeHtml(task.task_type || '-') + '</span></div>';
    html += '<a class="pa-card-title" data-pa-detail-link href="?r=public_affairs_collab&task_id=' + escapeHtml(taskId) + '">' + escapeHtml(task.title || '-') + '</a>';
    html += '<div class="pa-card-meta"><div>' + escapeHtml(task.project_name || '-') + '</div><div>담당 ' + escapeHtml(task.assignee_name || '-') + ' · 요청 ' + escapeHtml(task.requester_name || '-') + '</div><div>마감 ' + escapeHtml(dueText(task)) + '</div></div>';
    html += '<div class="pa-badges"><span class="pa-badge ' + priorityClass(task.priority) + '">' + escapeHtml(task.priority || '-') + '</span>';
    if (parseInt(task.is_due_today || 0, 10)) html += '<span class="pa-badge pa-today">오늘 마감</span>';
    if (parseInt(task.is_delayed || 0, 10)) html += '<span class="pa-badge pa-delayed">지연</span>';
    if (task.contract_impact && task.contract_impact !== '없음') html += '<span class="pa-badge pa-impact">계약 ' + escapeHtml(task.contract_impact) + '</span>';
    if (task.schedule_impact && task.schedule_impact !== '없음') html += '<span class="pa-badge pa-impact">공기 ' + escapeHtml(task.schedule_impact) + '</span>';
    if (checklistText(task) !== '') html += '<span class="pa-badge pa-check-progress">' + escapeHtml(checklistText(task)) + '</span>';
    html += '<span class="pa-badge" data-pa-comment-count>댓글 ' + escapeHtml(task.comment_count || 0) + '</span><span class="pa-badge" data-pa-file-count>첨부 ' + escapeHtml(task.file_count || 0) + '</span></div>';
    if (canEdit) {
      html += '<form method="post" action="' + escapeHtml(actionUrl) + '" class="pa-card-select"><input type="hidden" name="_csrf" value="' + escapeHtml(cfg.csrf || '') + '"><input type="hidden" name="action" value="quick_update"><input type="hidden" name="task_id" value="' + escapeHtml(taskId) + '"><select name="status">' + optionHtml(cfg.statuses || [], task.status) + '</select><button type="submit">이동</button></form>';
    }
    html += '</div>';
    return html;
  }

  function refreshColumnCounts() {
    var columns = appModal ? appModal.querySelectorAll('[data-pa-drop-status]') : [];
    for (var i = 0; i < columns.length; i++) {
      var cards = columns[i].querySelectorAll('.pa-card:not(.is-sample)');
      var count = cards.length;
      var countEl = columns[i].querySelector('.pa-count');
      var wipEl = columns[i].querySelector('.pa-wip');
      if (countEl) countEl.innerHTML = count;
      if (wipEl) wipEl.innerHTML = 'WIP ' + count;
    }
  }

  function bindCardDrag(card) {
    if (!card || card.getAttribute('data-pa-can-edit') !== '1') return;
    bindEvent(card, 'dragstart', function(ev){
      draggedTaskId = card.getAttribute('data-pa-task-id');
      dragSourceColumn = closest(card, '[data-pa-drop-status]');
      dragSourceStatus = card.getAttribute('data-pa-status') || '';
      addClass(card, 'is-dragging');
      if (ev.dataTransfer) ev.dataTransfer.setData('text/plain', draggedTaskId);
    });
    bindEvent(card, 'dragend', function(){ removeClass(card, 'is-dragging'); });
  }

  function addOrUpdateCard(payload) {
    if (!payload || !payload.task) return;
    var task = payload.task;
    task.comment_count = task.comment_count || (payload.comments ? payload.comments.length : 0);
    task.file_count = task.file_count || (payload.files ? payload.files.length : 0);
    if (payload.checklists) {
      task.checklist_total = payload.checklists.length;
      task.checklist_done = 0;
      for (var p = 0; p < payload.checklists.length; p++) if (parseInt(payload.checklists[p].is_done || 0, 10)) task.checklist_done++;
    }
    var canEdit = payload.can_edit === undefined ? true : !!payload.can_edit;
    var oldCard = appModal ? appModal.querySelector('.pa-card[data-pa-task-id="' + task.id + '"]') : null;
    var column = appModal ? appModal.querySelector('[data-pa-drop-status="' + cssEscape(task.status || '') + '"] .pa-column-body') : null;
    if (!column) {
      if (oldCard) {
        oldCard.setAttribute('data-pa-status', task.status || '');
        var cc = oldCard.querySelector('[data-pa-comment-count]');
        var fc = oldCard.querySelector('[data-pa-file-count]');
        if (cc) cc.innerHTML = '댓글 ' + (task.comment_count || 0);
        if (fc) fc.innerHTML = '첨부 ' + (task.file_count || 0);
      }
      return;
    }
    var wrapper = document.createElement('div');
    wrapper.innerHTML = cardHtml(task, canEdit);
    var newCard = wrapper.firstChild;
    if (oldCard && oldCard.parentNode) oldCard.parentNode.removeChild(oldCard);
    column.appendChild(newCard);
    bindCardDrag(newCard);
    refreshColumnCounts();
    addClass(newCard, 'is-selected');
  }

  function cssEscape(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function renderDetail(payload) {
    if (!payload || !payload.task || !appModal) {
      renderMissingDetail('업무를 찾을 수 없습니다.');
      return;
    }
    var task = payload.task;
    var canEdit = payload.can_edit === undefined ? true : !!payload.can_edit;
    var comments = payload.comments || [];
    var files = payload.files || [];
    var history = payload.history || [];
    var checklists = payload.checklists || [];
    var checklistDone = 0;
    for (var cl = 0; cl < checklists.length; cl++) if (parseInt(checklists[cl].is_done || 0, 10)) checklistDone++;
    var old = appModal.querySelector('.pa-detail-panel');
    if (old && old.parentNode) old.parentNode.removeChild(old);
    var refs = task.reference_employee_ids || [];
    var disabled = canEdit ? '' : ' disabled';
    var readonly = canEdit ? '' : ' readonly';
    var html = '<aside class="pa-detail-panel" data-pa-detail-task-id="' + escapeHtml(task.id) + '">';
    html += '<div class="pa-detail-head"><div><div><span class="pa-no">' + escapeHtml(task.task_no || '-') + '</span> <span class="pa-badge ' + statusClass(task.status) + '">' + escapeHtml(task.status || '-') + '</span> <span class="pa-badge ' + priorityClass(task.priority) + '">' + escapeHtml(task.priority || '-') + '</span></div><div class="pa-title" style="font-size:21px;margin-top:8px;">' + escapeHtml(task.title || '-') + '</div></div><button type="button" class="pa-btn" data-pa-detail-close>닫기</button></div>';
    html += '<div class="pa-detail-body">';
    html += '<form method="post" action="' + escapeHtml(actionUrl) + '" class="pa-detail-grid" data-pa-ajax-form><input type="hidden" name="_csrf" value="' + escapeHtml(cfg.csrf || '') + '"><input type="hidden" name="action" value="update"><input type="hidden" name="task_id" value="' + escapeHtml(task.id) + '"><input type="hidden" name="reference_employee_ids_present" value="1">';
    html += '<div class="pa-panel-card"><div class="pa-panel-title">' + escapeHtml(task.task_no || '-') + ' 상세내용</div><div class="pa-form-grid">';
    html += '<div class="full"><input name="title" value="' + escapeHtml(task.title || '') + '"' + readonly + ' class="pa-field"></div>';
    html += '<div class="full"><textarea name="content" rows="8"' + readonly + ' class="pa-field" placeholder="상세내용">' + escapeHtml(task.content || '') + '</textarea></div>';
    html += '<div class="full"><input name="document_link" value="' + escapeHtml(task.document_link || '') + '"' + readonly + ' class="pa-field" placeholder="관련 문서 링크"></div></div>';
    if (canEdit) {
      html += '<div class="pa-detail-actions"><button type="submit" name="state_action" value="complete" class="pa-btn pa-btn-primary">완료 처리</button>';
      if ((cfg.statuses || []).indexOf('반려') !== -1) html += '<button type="submit" name="state_action" value="reject" class="pa-btn">반려 처리</button>';
      html += '<button type="submit" name="state_action" value="hold" class="pa-btn">보류 처리</button><button type="submit" class="pa-btn pa-btn-dark">변경 저장</button></div>';
    }
    html += '</div><div class="pa-panel-card"><div class="pa-panel-title">속성</div><div class="pa-prop">';
    html += '<div class="pa-prop-row"><b>업무번호</b><span>' + escapeHtml(task.task_no || '-') + '</span></div>';
    html += '<div class="pa-prop-row"><b>템플릿</b><span>' + escapeHtml(task.template_name || '-') + '</span></div>';
    html += '<div class="pa-prop-row"><b>체크리스트</b><span>' + escapeHtml(checklistDone) + ' / ' + escapeHtml(checklists.length) + '</span></div>';
    html += '<div class="pa-prop-row"><b>담당자</b><span><select name="assignee_employee_id"' + disabled + ' class="pa-field">' + employeeOptions(task.assignee_employee_id, false) + '</select></span></div>';
    html += '<div class="pa-prop-row"><b>요청자</b><span>' + escapeHtml(task.requester_name || '-') + '</span></div>';
    html += '<div class="pa-prop-row"><b>참조자</b><span><select name="reference_employee_ids[]" multiple' + disabled + ' class="pa-field" style="min-height:82px;">' + employeeOptions(refs, true) + '</select></span></div>';
    html += '<div class="pa-prop-row"><b>업무유형</b><span><select name="task_type"' + disabled + ' class="pa-field">' + optionHtml(cfg.taskTypes || [], task.task_type) + '</select></span></div>';
    html += '<div class="pa-prop-row"><b>현장명</b><span><input name="project_name" value="' + escapeHtml(task.project_name || '') + '"' + readonly + ' class="pa-field"></span></div>';
    html += '<div class="pa-prop-row"><b>상태</b><span><select name="status"' + disabled + ' class="pa-field">' + optionHtml(cfg.statuses || [], task.status) + '</select></span></div>';
    html += '<div class="pa-prop-row"><b>우선순위</b><span><select name="priority"' + disabled + ' class="pa-field">' + optionHtml(cfg.priorities || [], task.priority) + '</select></span></div>';
    html += '<div class="pa-prop-row"><b>시작일</b><span><input type="date" name="start_date" value="' + escapeHtml(task.start_date || '') + '"' + readonly + ' class="pa-field"></span></div>';
    html += '<div class="pa-prop-row"><b>마감일</b><span><input type="date" name="due_date" value="' + escapeHtml(task.due_date || '') + '"' + readonly + ' class="pa-field"></span></div>';
    html += '<div class="pa-prop-row"><b>마감시간</b><span><input type="time" name="due_time" value="' + escapeHtml(task.due_time || '') + '"' + readonly + ' class="pa-field"></span></div>';
    html += '<div class="pa-prop-row"><b>관련 금액</b><span><input name="related_amount" value="' + escapeHtml(task.related_amount || '') + '"' + readonly + ' class="pa-field"></span></div>';
    html += '<div class="pa-prop-row"><b>계약 영향</b><span><select name="contract_impact"' + disabled + ' class="pa-field">' + optionHtml(cfg.impactOptions || [], task.contract_impact || '없음') + '</select></span></div>';
    html += '<div class="pa-prop-row"><b>공기 영향</b><span><select name="schedule_impact"' + disabled + ' class="pa-field">' + optionHtml(cfg.impactOptions || [], task.schedule_impact || '없음') + '</select></span></div>';
    html += '<div class="pa-prop-row"><b>생성일시</b><span>' + escapeHtml(task.created_at || '-') + '</span></div><div class="pa-prop-row"><b>수정일시</b><span>' + escapeHtml(task.updated_at || '-') + '</span></div><div class="pa-prop-row"><b>완료일시</b><span>' + escapeHtml(task.completed_at || '-') + '</span></div>';
    html += '</div></div></form>';
    html += '<div class="pa-detail-grid" style="margin-top:14px;"><div><div class="pa-panel-card"><div class="pa-panel-title">' + escapeHtml(task.task_no || '-') + ' 체크리스트 <span class="pa-muted">' + escapeHtml(checklistDone) + ' / ' + escapeHtml(checklists.length) + '</span></div>';
    if (checklists.length === 0) html += '<div class="pa-muted">체크리스트가 없습니다. 필요하면 항목을 추가할 수 있습니다.</div>';
    html += '<div class="pa-checklist">';
    for (var ci = 0; ci < checklists.length; ci++) {
      var item = checklists[ci] || {};
      var nextDone = parseInt(item.is_done || 0, 10) ? '0' : '1';
      html += '<form method="post" action="' + escapeHtml(actionUrl) + '" class="pa-checklist-item" data-pa-ajax-form><input type="hidden" name="_csrf" value="' + escapeHtml(cfg.csrf || '') + '"><input type="hidden" name="action" value="checklist_toggle"><input type="hidden" name="task_id" value="' + escapeHtml(task.id) + '"><input type="hidden" name="checklist_id" value="' + escapeHtml(item.id || 0) + '"><input type="hidden" name="is_done" value="' + nextDone + '"><button type="submit" class="pa-check-toggle"' + (canEdit ? '' : ' disabled') + '>' + (parseInt(item.is_done || 0, 10) ? '✓' : '') + '</button><span class="' + (parseInt(item.is_done || 0, 10) ? 'is-done' : '') + '">' + escapeHtml(item.title || '-') + '</span></form>';
    }
    html += '</div>';
    if (canEdit) html += '<form method="post" action="' + escapeHtml(actionUrl) + '" class="pa-checklist-add" data-pa-ajax-form style="margin-top:10px;"><input type="hidden" name="_csrf" value="' + escapeHtml(cfg.csrf || '') + '"><input type="hidden" name="action" value="checklist_add"><input type="hidden" name="task_id" value="' + escapeHtml(task.id) + '"><input name="checklist_title" class="pa-field" placeholder="체크리스트 항목 추가"><button type="submit" class="pa-btn pa-btn-dark">추가</button></form>';
    html += '</div><div class="pa-panel-card" style="margin-top:12px;"><div class="pa-panel-title">' + escapeHtml(task.task_no || '-') + ' 댓글</div>';
    html += '<form method="post" action="' + escapeHtml(actionUrl) + '" data-pa-ajax-form><input type="hidden" name="_csrf" value="' + escapeHtml(cfg.csrf || '') + '"><input type="hidden" name="action" value="comment"><input type="hidden" name="task_id" value="' + escapeHtml(task.id) + '"><textarea name="comment" rows="3" class="pa-field" placeholder="@담당자 확인 부탁드립니다."></textarea><div style="display:flex;justify-content:flex-end;margin-top:8px;"><button type="submit" class="pa-btn pa-btn-primary">댓글 등록</button></div></form>';
    if (comments.length === 0) html += '<div class="pa-muted" style="margin-top:10px;">댓글이 없습니다.</div>';
    for (var c = 0; c < comments.length; c++) html += '<div class="pa-comment"><b>' + escapeHtml(comments[c].created_by_name || '-') + '</b> <span class="pa-muted">' + escapeHtml(comments[c].created_at || '') + '</span><div style="white-space:pre-wrap;margin-top:5px;">' + mentionHtml(comments[c].content || '') + '</div></div>';
    html += '</div><div class="pa-panel-card" style="margin-top:12px;"><div class="pa-panel-title">' + escapeHtml(task.task_no || '-') + ' 첨부파일</div>';
    if (files.length === 0) html += '<div class="pa-muted">첨부파일이 없습니다.</div>';
    for (var f = 0; f < files.length; f++) html += '<a class="pa-file" style="display:block;" href="' + escapeHtml(fileUrl + files[f].id) + '"><b>' + escapeHtml(files[f].original_name || 'file') + '</b><div class="pa-muted">' + escapeHtml(files[f].uploaded_by_name || '-') + ' · ' + escapeHtml(files[f].uploaded_at || '') + ' · ' + escapeHtml(fileSize(files[f].file_size || 0)) + '</div></a>';
    if (canEdit) html += '<form method="post" action="' + escapeHtml(actionUrl) + '" enctype="multipart/form-data" data-pa-ajax-form style="margin-top:10px;"><input type="hidden" name="_csrf" value="' + escapeHtml(cfg.csrf || '') + '"><input type="hidden" name="action" value="upload"><input type="hidden" name="task_id" value="' + escapeHtml(task.id) + '"><input type="file" name="attachments[]" multiple class="pa-field"><button type="submit" class="pa-btn pa-btn-dark" style="margin-top:8px;">첨부 등록</button></form>';
    html += '</div></div><div class="pa-panel-card"><div class="pa-panel-title">' + escapeHtml(task.task_no || '-') + ' 변경이력</div>';
    if (history.length === 0) html += '<div class="pa-muted">변경이력이 없습니다.</div>';
    for (var h = 0; h < history.length; h++) html += '<div class="pa-history"><b>' + escapeHtml(history[h].action || '-') + '</b><div class="pa-muted">' + escapeHtml(history[h].actor_name || '-') + ' · ' + escapeHtml(history[h].created_at || '') + '</div><div style="font-size:12px;margin-top:5px;word-break:break-all;">' + escapeHtml(history[h].old_value || '') + ' → ' + escapeHtml(history[h].new_value || '') + '</div></div>';
    html += '</div></div></div></aside>';
    appModal.querySelector('.pa-collab-shell').insertAdjacentHTML('beforeend', html);
    markSelectedCard(task.id);
  }

  function renderMissingDetail(message) {
    if (!appModal) return;
    var old = appModal.querySelector('.pa-detail-panel');
    if (old && old.parentNode) old.parentNode.removeChild(old);
    var html = '<aside class="pa-detail-panel"><div class="pa-detail-head"><div><div class="pa-title" style="font-size:21px;">공무 협업툴 업무 상세</div><div class="pa-muted">업무를 불러오지 못했습니다.</div></div><button type="button" class="pa-btn" data-pa-detail-close>닫기</button></div><div class="pa-detail-body"><div class="pa-panel-card"><div class="pa-panel-title">안내</div><p class="pa-muted">' + escapeHtml(message || '업무를 찾을 수 없습니다.') + '</p><div style="display:flex;gap:8px;flex-wrap:wrap;"><a class="pa-btn" href="?r=public_affairs_collab&safe=1">안전 모드</a><a class="pa-btn" href="?r=public_affairs_collab_trace" target="_blank" rel="noopener">trace</a><a class="pa-btn" href="?r=public_affairs_collab_debug" target="_blank" rel="noopener">debug</a></div></div></div></aside>';
    appModal.querySelector('.pa-collab-shell').insertAdjacentHTML('beforeend', html);
  }

  function markSelectedCard(taskId) {
    var cards = appModal ? appModal.querySelectorAll('.pa-card') : [];
    for (var i = 0; i < cards.length; i++) removeClass(cards[i], 'is-selected');
    var card = appModal ? appModal.querySelector('.pa-card[data-pa-task-id="' + taskId + '"]') : null;
    if (card) addClass(card, 'is-selected');
  }

  function loadDetail(taskId) {
    if (!taskId) return;
    actionRequest('detail', taskId, null, function(json){
      if (!json.ok) {
        showNotice(json.message, false);
        renderMissingDetail(json.message);
        return;
      }
      renderDetail(json);
    });
  }

  function transitionReason(targetStatus) {
    if (targetStatus !== '반려' && targetStatus !== '보류') return '';
    return window.prompt(targetStatus + ' 사유를 입력해주세요.', '') || '';
  }

  function prepareStatusReason(form) {
    var statusEl = form.elements ? form.elements['status'] : null;
    var stateEl = form.elements ? form.elements['state_action'] : null;
    var active = document.activeElement;
    if (active && active.form === form && active.name === 'state_action') stateEl = active;
    var target = statusEl ? statusEl.value : '';
    if (stateEl && stateEl.value === 'reject') target = '반려';
    if (stateEl && stateEl.value === 'hold') target = '보류';
    if (target === '반려' || target === '보류') {
      var reason = transitionReason(target);
      if (reason === '') return false;
      var reasonInput = form.querySelector('input[name="transition_reason"]');
      if (!reasonInput) {
        reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'transition_reason';
        form.appendChild(reasonInput);
      }
      reasonInput.value = reason;
    }
    return true;
  }

  function setFormValue(form, name, value) {
    if (!form || !form.elements || !form.elements[name]) return;
    var el = form.elements[name];
    if (el.length && el.tagName !== 'SELECT') {
      for (var i = 0; i < el.length; i++) el[i].value = value;
    } else {
      el.value = value;
    }
  }

  function applyTemplateToForm(selectEl) {
    var template = findTemplate(selectEl ? selectEl.value : 0);
    var form = closest(selectEl, 'form');
    if (!template || !form) return;
    setFormValue(form, 'task_type', template.task_type || '');
    setFormValue(form, 'title', replaceTemplateVars(template.default_title || ''));
    setFormValue(form, 'content', replaceTemplateVars(template.default_content || ''));
    setFormValue(form, 'status', template.default_status || '할 일');
    setFormValue(form, 'priority', template.default_priority || '보통');
    setFormValue(form, 'contract_impact', template.default_contract_impact || '없음');
    setFormValue(form, 'schedule_impact', template.default_schedule_impact || '없음');
    setFormValue(form, 'due_date', dateFromDueDays(template.default_due_days || 0));
  }

  function handleAjaxResult(json, form) {
    showNotice(json.message, !!json.ok);
    if (!json.ok) return;
    if (json.redirect_url && form && form.elements && form.elements['action'] && form.elements['action'].value === 'project_create') {
      window.location.href = json.redirect_url + hashValue;
      return;
    }
    if (json.redirect_url && form && form.elements && form.elements['action']) {
      var reloadActions = {'saved_view_create':1,'saved_view_delete':1,'template_toggle':1,'template_reset':1,'settings':1};
      if (reloadActions[form.elements['action'].value]) {
        window.location.href = json.redirect_url + hashValue;
        return;
      }
    }
    if (json.task) {
      addOrUpdateCard(json);
      renderDetail(json);
    }
    if (form && form.elements && form.elements['action'] && form.elements['action'].value === 'create') {
      var modal = document.getElementById('paCreateModal');
      removeClass(modal, 'is-open');
      form.reset();
    }
    if (form && form.elements && form.elements['action'] && form.elements['action'].value === 'project_convert' && json.redirect_url) {
      window.location.href = json.redirect_url + hashValue;
    }
  }

  function bindInitialCards() {
    var cards = appModal ? appModal.querySelectorAll('.pa-card[data-pa-task-id]') : [];
    for (var i = 0; i < cards.length; i++) bindCardDrag(cards[i]);
  }

  function bindDropColumns() {
    var columns = appModal ? appModal.querySelectorAll('[data-pa-drop-status]') : [];
    for (var k = 0; k < columns.length; k++) {
      bindEvent(columns[k], 'dragover', function(ev){
        ev.preventDefault();
        addClass(this, 'is-drop-ready');
      });
      bindEvent(columns[k], 'dragleave', function(){ removeClass(this, 'is-drop-ready'); });
      bindEvent(columns[k], 'drop', function(ev){
        ev.preventDefault();
        removeClass(this, 'is-drop-ready');
        var taskId = draggedTaskId;
        if (!taskId && ev.dataTransfer) taskId = ev.dataTransfer.getData('text/plain');
        var status = this.getAttribute('data-pa-drop-status');
        var card = appModal.querySelector('.pa-card[data-pa-task-id="' + taskId + '"]');
        if (!taskId || !status || !card) return;
        if (status === card.getAttribute('data-pa-status')) return;
        var reason = transitionReason(status);
        if ((status === '반려' || status === '보류') && reason === '') return;
        var originalColumn = dragSourceColumn || closest(card, '[data-pa-drop-status]');
        var originalBody = originalColumn ? originalColumn.querySelector('.pa-column-body') : null;
        var targetBody = this.querySelector('.pa-column-body');
        if (targetBody) targetBody.appendChild(card);
        refreshColumnCounts();
        addClass(card, 'is-moving');
        var data = {status: status};
        if (reason !== '') data.transition_reason = reason;
        actionRequest('quick_update', taskId, data, function(json){
          removeClass(card, 'is-moving');
          if (!json.ok) {
            if (originalBody) originalBody.appendChild(card);
            card.setAttribute('data-pa-status', dragSourceStatus);
            refreshColumnCounts();
            showNotice(json.message, false);
            return;
          }
          showNotice(json.message, true);
          if (json.task) addOrUpdateCard(json);
        });
      });
    }
  }

  function filterCurrentDom(keyword) {
    keyword = String(keyword || '').toLowerCase();
    var count = 0;
    var cards = appModal ? appModal.querySelectorAll('.pa-card[data-pa-search]') : [];
    for (var i = 0; i < cards.length; i++) {
      var hay = String(cards[i].getAttribute('data-pa-search') || '').toLowerCase();
      var matched = keyword === '' || hay.indexOf(keyword) !== -1;
      cards[i].style.display = matched ? '' : 'none';
      if (matched) count++;
    }
    var rows = appModal ? appModal.querySelectorAll('[data-pa-list-task-id][data-pa-search]') : [];
    for (var r = 0; r < rows.length; r++) {
      var rowHay = String(rows[r].getAttribute('data-pa-search') || '').toLowerCase();
      rows[r].style.display = (keyword === '' || rowHay.indexOf(keyword) !== -1) ? '' : 'none';
    }
    refreshColumnCounts();
    var empty = appModal ? appModal.querySelector('[data-pa-search-empty]') : null;
    if (!empty && appModal) {
      empty = document.createElement('div');
      empty.className = 'pa-empty pa-search-empty';
      empty.setAttribute('data-pa-search-empty', '1');
      empty.innerHTML = '검색 결과가 없습니다.';
      var board = appModal.querySelector('.pa-board-wrap');
      if (board) board.insertBefore(empty, board.firstChild);
    }
    if (empty) empty.style.display = keyword !== '' && count === 0 ? '' : 'none';
  }

  getAppModal();

  var appOpeners = document.querySelectorAll('[data-pa-collab-open]');
  for (var o = 0; o < appOpeners.length; o++) {
    appOpeners[o].onclick = function(ev){
      if (!getAppModal()) return true;
      if (ev && ev.preventDefault) ev.preventDefault();
      openAppModal(true);
      return false;
    };
  }

  var appClosers = document.querySelectorAll('[data-pa-collab-close]');
  for (var x = 0; x < appClosers.length; x++) {
    appClosers[x].onclick = function(){
      closeAppModal(true);
      return false;
    };
  }

  var menuToggle = document.querySelector('[data-pa-menu-toggle]');
  if (menuToggle && getAppModal()) {
    menuToggle.onclick = function(){
      var modal = getAppModal();
      if (hasClass(modal, 'pa-collab-menu-open')) removeClass(modal, 'pa-collab-menu-open');
      else addClass(modal, 'pa-collab-menu-open');
    };
  }

  bindEvent(document, 'keydown', function(ev){
    ev = ev || window.event;
    var key = ev.key || ev.keyCode;
    var createModal = document.getElementById('paCreateModal');
    var projectModal = document.getElementById('paProjectModal');
    if (key === 'Escape' || key === 27) {
      if (createModal && hasClass(createModal, 'is-open')) {
        removeClass(createModal, 'is-open');
        return;
      }
      if (projectModal && hasClass(projectModal, 'is-open')) {
        removeClass(projectModal, 'is-open');
        return;
      }
      var detailModal = getAppModal();
      var detail = detailModal ? detailModal.querySelector('.pa-detail-panel') : null;
      if (detail && detail.parentNode) {
        detail.parentNode.removeChild(detail);
        return;
      }
      var modal = getAppModal();
      if (modal && hasClass(modal, 'is-open')) closeAppModal(true);
    }
  });

  function syncAppModalWithLocation() {
    var modal = getAppModal();
    if (!modal) return;
    if (window.location.hash === hashValue) openAppModal(false);
    else if (hasClass(modal, 'is-open')) closeAppModal(false);
  }

  var startupModal = getAppModal();
  if (startupModal && (startupModal.getAttribute('data-pa-auto-open') === '1' || window.location.hash === hashValue)) {
    openAppModal(window.location.hash !== hashValue);
  }

  bindEvent(window, 'hashchange', syncAppModalWithLocation);
  bindEvent(window, 'popstate', syncAppModalWithLocation);

  bindEvent(document, 'click', function(ev){
    var target = ev.target || ev.srcElement;
    var appOpen = closest(target, '[data-pa-collab-open]');
    if (appOpen && getAppModal()) {
      openAppModal(true);
      ev.preventDefault();
      return false;
    }
    var createOpen = closest(target, '[data-pa-modal-open="create"]');
    if (createOpen) {
      var createModal = document.getElementById('paCreateModal');
      addClass(createModal, 'is-open');
      ev.preventDefault();
      return false;
    }
    var projectOpen = closest(target, '[data-pa-modal-open="project"]');
    if (projectOpen) {
      var projectModal = document.getElementById('paProjectModal');
      addClass(projectModal, 'is-open');
      ev.preventDefault();
      return false;
    }
    var createClose = closest(target, '[data-pa-modal-close="create"]');
    if (createClose) {
      removeClass(document.getElementById('paCreateModal'), 'is-open');
      ev.preventDefault();
      return false;
    }
    var projectClose = closest(target, '[data-pa-modal-close="project"]');
    if (projectClose) {
      removeClass(document.getElementById('paProjectModal'), 'is-open');
      ev.preventDefault();
      return false;
    }
    var detailClose = closest(target, '[data-pa-detail-close]');
    if (detailClose) {
      var panel = closest(detailClose, '.pa-detail-panel');
      if (panel && panel.parentNode) panel.parentNode.removeChild(panel);
      ev.preventDefault();
      return false;
    }
    if (closest(target, 'form') || closest(target, 'select') || closest(target, 'input') || closest(target, 'button')) return true;
    var detailLink = closest(target, '[data-pa-detail-link]');
    if (!detailLink && closest(target, '.pa-card')) detailLink = closest(target, '.pa-card').querySelector('[data-pa-detail-link]');
    if (detailLink && appModal && hasClass(appModal, 'is-open')) {
      var id = getTaskIdFromHref(detailLink.getAttribute('href'));
      if (!id) {
        var card = closest(detailLink, '[data-pa-task-id]');
        if (card) id = parseInt(card.getAttribute('data-pa-task-id'), 10);
      }
      if (id) {
        ev.preventDefault();
        loadDetail(id);
        return false;
      }
    }
    var listRow = closest(target, '[data-pa-list-task-id]');
    if (listRow && appModal && hasClass(appModal, 'is-open')) {
      var rowId = parseInt(listRow.getAttribute('data-pa-list-task-id'), 10);
      if (rowId) {
        ev.preventDefault();
        loadDetail(rowId);
        return false;
      }
    }
    var clear = closest(target, '[data-pa-search-clear]');
    if (clear) {
      var form = closest(clear, 'form');
      var input = form ? form.querySelector('input[name="keyword"]') : null;
      if (input) input.value = '';
      filterCurrentDom('');
      ev.preventDefault();
      return false;
    }
  });

  bindEvent(document, 'change', function(ev){
    var target = ev.target || ev.srcElement;
    if (closest(target, '[data-pa-template-select]')) {
      applyTemplateToForm(closest(target, '[data-pa-template-select]'));
    }
  });

  bindEvent(document, 'submit', function(ev){
    var form = ev.target || ev.srcElement;
    if (!form || !appModal || !hasClass(appModal, 'is-open')) return true;
    if (hasClass(form, 'pa-collab-header-search')) {
      var kw = form.querySelector('input[name="keyword"]');
      filterCurrentDom(kw ? kw.value : '');
      ev.preventDefault();
      return false;
    }
    if (String(form.getAttribute('action') || '').indexOf('project/collaboration_action') === -1) return true;
    if (!window.FormData) return true;
    if (!prepareStatusReason(form)) {
      ev.preventDefault();
      return false;
    }
    ev.preventDefault();
    addClass(form, 'is-saving');
    var fd = new FormData(form);
    var active = document.activeElement;
    if (active && active.form === form && active.name) fd.append(active.name, active.value);
    ajaxFormData(fd, function(json){
      removeClass(form, 'is-saving');
      handleAjaxResult(json, form);
    });
    return false;
  });

  bindInitialCards();
  bindDropColumns();
})();
