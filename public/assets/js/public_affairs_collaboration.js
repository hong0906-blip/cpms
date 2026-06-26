(function(){
  // 공무 협업툴 전체화면 앱 모달: 공무 탭 클릭 시 CPMS 화면 위에 독립 보드를 연다.
  var appModal = document.getElementById('paCollabFullscreenModal');
  var lastFocusedElement = null;
  var hashValue = '#public-affairs-collaboration';

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

  function focusFirstControl() {
    if (!appModal) return;
    var target = appModal.querySelector('button, a, input, select, textarea');
    if (target && target.focus) target.focus();
  }

  function setHash() {
    if (window.location.hash === hashValue) return;
    if (window.history && window.history.pushState) {
      window.history.pushState(null, '', hashValue);
    } else {
      window.location.hash = hashValue;
    }
  }

  function clearHash() {
    if (window.location.hash !== hashValue) return;
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
    } else {
      window.location.hash = '';
    }
  }

  function openAppModal(updateHash) {
    if (!appModal) return;
    if (!hasClass(appModal, 'is-open')) lastFocusedElement = document.activeElement;
    addClass(appModal, 'is-open');
    appModal.setAttribute('aria-hidden', 'false');
    addClass(document.body, 'pa-collab-open');
    if (updateHash) setHash();
    focusFirstControl();
  }

  function closeAppModal(updateHash) {
    if (!appModal) return;
    removeClass(appModal, 'is-open');
    appModal.setAttribute('aria-hidden', 'true');
    removeClass(document.body, 'pa-collab-open');
    removeClass(appModal, 'pa-collab-menu-open');
    if (updateHash) clearHash();
    if (lastFocusedElement && lastFocusedElement.focus) lastFocusedElement.focus();
  }

  var appOpeners = document.querySelectorAll('[data-pa-collab-open]');
  for (var o = 0; o < appOpeners.length; o++) {
    appOpeners[o].onclick = function(ev){
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
  if (menuToggle && appModal) {
    menuToggle.onclick = function(){
      if (hasClass(appModal, 'pa-collab-menu-open')) removeClass(appModal, 'pa-collab-menu-open');
      else addClass(appModal, 'pa-collab-menu-open');
    };
  }

  function syncAppModalWithLocation() {
    if (!appModal) return;
    if (window.location.hash === hashValue) openAppModal(false);
    else if (hasClass(appModal, 'is-open')) closeAppModal(false);
  }

  if (appModal && (appModal.getAttribute('data-pa-auto-open') === '1' || window.location.hash === hashValue)) {
    openAppModal(window.location.hash !== hashValue);
  }

  bindEvent(window, 'hashchange', syncAppModalWithLocation);
  bindEvent(window, 'popstate', syncAppModalWithLocation);

  var modal = document.getElementById('paCreateModal');
  var openers = document.querySelectorAll('[data-pa-modal-open="create"]');
  for (var i = 0; i < openers.length; i++) {
    openers[i].onclick = function(){ if (modal) addClass(modal, 'is-open'); };
  }
  var closers = document.querySelectorAll('[data-pa-modal-close="create"]');
  for (var j = 0; j < closers.length; j++) {
    closers[j].onclick = function(){ if (modal) removeClass(modal, 'is-open'); };
  }
  bindEvent(document, 'keydown', function(ev){
    ev = ev || window.event;
    var key = ev.key || ev.keyCode;
    if (key === 'Escape' || key === 27) {
      if (modal && hasClass(modal, 'is-open')) {
        removeClass(modal, 'is-open');
        return;
      }
      if (appModal && hasClass(appModal, 'is-open')) closeAppModal(true);
    }
  });
  var draggedTaskId = '';
  var cards = document.querySelectorAll('[data-pa-task-id]');
  for (var c = 0; c < cards.length; c++) {
    cards[c].addEventListener('dragstart', function(ev){
      draggedTaskId = this.getAttribute('data-pa-task-id');
      if (ev.dataTransfer) ev.dataTransfer.setData('text/plain', draggedTaskId);
    });
  }
  var columns = document.querySelectorAll('[data-pa-drop-status]');
  for (var k = 0; k < columns.length; k++) {
    columns[k].addEventListener('dragover', function(ev){ ev.preventDefault(); });
    columns[k].addEventListener('drop', function(ev){
      ev.preventDefault();
      var taskId = draggedTaskId;
      if (!taskId && ev.dataTransfer) taskId = ev.dataTransfer.getData('text/plain');
      var status = this.getAttribute('data-pa-drop-status');
      var form = document.getElementById('paStatusMoveForm');
      if (!taskId || !status || !form) return;
      form.elements['task_id'].value = taskId;
      form.elements['status'].value = status;
      form.submit();
    });
  }
})();
