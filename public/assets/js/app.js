// public/assets/js/app.js
// - 기존: Sidebar collapse
// - 추가: pages.js 자동 로드(레이아웃 파일 수정 없이)

(function () {
  function refreshIcons() {
    if (window.lucide) {
      try { lucide.createIcons(); } catch (e) {}
    }
  }

  var loadingTimer = null;
  var loadingSafetyTimer = null;
  var loadingCount = 0;
  var loadingNextKey = 'cpms_global_loading_next';
  var loadingNextTtl = 15000;
  var loadingSafetyTtl = 30000;
  var navigationDelayMs = 20;
  var pageStartedWithLoading = false;
  var startupLoadingFinished = false;

  function nowMs() {
    return (new Date()).getTime();
  }

  function readNextPageLoading() {
    try {
      if (!window.sessionStorage) return false;
      var raw = sessionStorage.getItem(loadingNextKey);
      var startedAt = raw ? parseInt(raw, 10) : 0;
      if (!startedAt || nowMs() - startedAt > loadingNextTtl) {
        sessionStorage.removeItem(loadingNextKey);
        return false;
      }
      return true;
    } catch (e) {
      return false;
    }
  }

  pageStartedWithLoading = readNextPageLoading();

  function loadingElement() {
    return document.getElementById('cpmsGlobalLoading');
  }

  function showGlobalLoadingNow() {
    var el = loadingElement();
    if (!el) return;
    el.className = el.className.replace(/\bis-visible\b/g, '').trim() + ' is-visible';
    el.setAttribute('aria-hidden', 'false');
    if (document.body) {
      document.body.className = document.body.className.replace(/\bcpms-loading-active\b/g, '').trim() + ' cpms-loading-active';
    }
    if (loadingSafetyTimer) window.clearTimeout(loadingSafetyTimer);
    loadingSafetyTimer = window.setTimeout(function () {
      loadingSafetyTimer = null;
      clearNextPageLoading();
      hideGlobalLoading(true);
    }, loadingSafetyTtl);
  }

  function showGlobalLoading(delayMs) {
    loadingCount++;
    if (loadingTimer) window.clearTimeout(loadingTimer);
    loadingTimer = window.setTimeout(function () {
      showGlobalLoadingNow();
    }, typeof delayMs === 'number' ? delayMs : 160);
  }

  function hideGlobalLoading(force) {
    if (force) loadingCount = 0;
    else if (loadingCount > 0) loadingCount--;
    if (loadingCount > 0) return;
    if (loadingTimer) {
      window.clearTimeout(loadingTimer);
      loadingTimer = null;
    }
    if (loadingSafetyTimer) {
      window.clearTimeout(loadingSafetyTimer);
      loadingSafetyTimer = null;
    }
    var el = loadingElement();
    if (el) {
      el.className = el.className.replace(/\bis-visible\b/g, '').trim();
      el.setAttribute('aria-hidden', 'true');
    }
    if (document.body) {
      document.body.className = document.body.className.replace(/\bcpms-loading-active\b/g, '').trim();
    }
  }

  function resetSubmittingForms() {
    if (!document.querySelectorAll) return;
    var forms = document.querySelectorAll('form[data-cpms-loading-submitting="1"]');
    for (var i = 0; i < forms.length; i++) {
      forms[i].removeAttribute('data-cpms-loading-submitting');
    }
    var disabledSubmitters = document.querySelectorAll('[data-cpms-loading-disabled="1"]');
    for (var j = 0; j < disabledSubmitters.length; j++) {
      disabledSubmitters[j].disabled = false;
      disabledSubmitters[j].removeAttribute('data-cpms-loading-disabled');
    }
  }

  function disableFormSubmitters(form) {
    if (!form || !form.querySelectorAll) return;
    var controls = form.querySelectorAll('button, input');
    for (var i = 0; i < controls.length; i++) {
      var control = controls[i];
      var tagName = control.tagName || '';
      var type = (control.getAttribute('type') || '').toLowerCase();
      var isSubmitter = (tagName === 'BUTTON' && (type === '' || type === 'submit'))
        || (tagName === 'INPUT' && (type === 'submit' || type === 'image'));
      if (!isSubmitter || control.disabled) continue;
      control.setAttribute('data-cpms-loading-disabled', '1');
      control.disabled = true;
    }
  }

  function requestUrlText(input) {
    if (!input) return '';
    if (typeof input === 'string') return input;
    if (input.url) return String(input.url);
    if (input.href) return String(input.href);
    return String(input);
  }

  function shouldSkipLoadingUrl(urlText) {
    var url = String(urlText || '').replace(/&amp;/gi, '&');
    if (url === '') return true;
    if (url.indexOf('r=ping') !== -1) return true;
    if (/[?&]r=tasks\/files_download(?:[&#]|$)/i.test(url)) return true;
    if (/[?&]r=tasks\/file(?:[&#]|$)/i.test(url) && /[?&]download=1(?:[&#]|$)/i.test(url)) return true;
    if (/[?&]r=tasks\/deferred_sync(?:[&#]|$)/i.test(url)) return true;
    if (/^(javascript:|mailto:|tel:)/i.test(url)) return true;
    return false;
  }

  function markNextPageLoading() {
    try {
      if (window.sessionStorage) sessionStorage.setItem(loadingNextKey, String(nowMs()));
    } catch (e) {}
  }

  function clearNextPageLoading() {
    try {
      if (window.sessionStorage) sessionStorage.removeItem(loadingNextKey);
    } catch (e) {}
  }

  window.cpmsShowLoading = function () { showGlobalLoadingNow(); };
  window.cpmsHideLoading = function () { hideGlobalLoading(true); };

  function findFormSubmitter(target) {
    while (target && target !== document) {
      if (target.tagName === 'BUTTON' || target.tagName === 'INPUT') {
        var type = (target.getAttribute('type') || '').toLowerCase();
        if (target.tagName === 'BUTTON') {
          if (type === '' || type === 'submit') return target;
        } else if (type === 'submit' || type === 'image') {
          return target;
        }
      }
      target = target.parentNode;
    }
    return null;
  }

  function submitFormAfterLoading(form) {
    var submitter = form._cpmsSubmitter || null;
    if (submitter && submitter.name && !submitter.disabled) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = submitter.name;
      hidden.value = submitter.value || '';
      form.appendChild(hidden);
    }
    window.setTimeout(function () {
      if (window.CPMSMoneyInput && typeof window.CPMSMoneyInput.stripForm === 'function') {
        window.CPMSMoneyInput.stripForm(form);
      }
      if (window.HTMLFormElement && window.HTMLFormElement.prototype && window.HTMLFormElement.prototype.submit) {
        window.HTMLFormElement.prototype.submit.call(form);
      } else {
        form.submit();
      }
    }, navigationDelayMs);
  }

  if (document.addEventListener) {
    document.addEventListener('click', function (event) {
      var target = event.target;
      var submitter = findFormSubmitter(target);
      if (submitter && submitter.form) {
        submitter.form._cpmsSubmitter = submitter;
      }
      var link = null;
      while (target && target !== document) {
        if (target.tagName === 'A') {
          link = target;
          break;
        }
        target = target.parentNode;
      }
      if (!link) return;
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      if (link.getAttribute('data-modal-open') || link.getAttribute('data-modal-close') || link.getAttribute('data-cpms-no-loading') === '1') return;
      if (link.target && link.target !== '_self') return;
      if (link.getAttribute('download') !== null) return;
      var href = link.getAttribute('href') || '';
      if (href === '' || href === '#' || href.charAt(0) === '#') return;
      if (shouldSkipLoadingUrl(href)) return;
      event.preventDefault();
      markNextPageLoading();
      showGlobalLoadingNow();
      window.setTimeout(function () {
        window.location.href = link.href;
      }, navigationDelayMs);
    }, false);

    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || form.getAttribute('data-cpms-no-loading') === '1') return;
      if (event.defaultPrevented) return;
      if (form.target && form.target !== '_self') return;
      if (shouldSkipLoadingUrl(form.getAttribute('action') || '')) return;
      if (form.getAttribute('data-cpms-loading-submitting') === '1') {
        event.preventDefault();
        return;
      }
      event.preventDefault();
      form.setAttribute('data-cpms-loading-submitting', '1');
      markNextPageLoading();
      showGlobalLoadingNow();
      submitFormAfterLoading(form);
      disableFormSubmitters(form);
    }, false);
  }

  function finishInitialLoading() {
    resetSubmittingForms();
    if (startupLoadingFinished) return;
    startupLoadingFinished = true;
    window.setTimeout(function () {
      clearNextPageLoading();
      pageStartedWithLoading = false;
      hideGlobalLoading(true);
    }, pageStartedWithLoading ? 40 : 0);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', finishInitialLoading);
  } else {
    finishInitialLoading();
  }

  if (window.addEventListener) {
    window.addEventListener('pageshow', function () {
      resetSubmittingForms();
      clearNextPageLoading();
      pageStartedWithLoading = false;
      hideGlobalLoading(true);
    });
    window.addEventListener('load', function () {
      finishInitialLoading();
    });
  }

  if (window.fetch) {
    var originalFetch = window.fetch;
    window.fetch = function () {
      var url = arguments.length > 0 ? requestUrlText(arguments[0]) : '';
      var useLoading = !shouldSkipLoadingUrl(url);
      if (useLoading) showGlobalLoading(180);
      return originalFetch.apply(this, arguments).then(function (response) {
        if (useLoading) hideGlobalLoading(false);
        return response;
      }, function (error) {
        if (useLoading) hideGlobalLoading(false);
        throw error;
      });
    };
  }

  if (window.XMLHttpRequest) {
    var originalOpen = window.XMLHttpRequest.prototype.open;
    var originalSend = window.XMLHttpRequest.prototype.send;
    window.XMLHttpRequest.prototype.open = function (method, url) {
      this._cpmsLoadingUrl = requestUrlText(url);
      return originalOpen.apply(this, arguments);
    };
    window.XMLHttpRequest.prototype.send = function () {
      var xhr = this;
      var useLoading = !shouldSkipLoadingUrl(xhr._cpmsLoadingUrl);
      if (useLoading) {
        showGlobalLoading(180);
        var done = false;
        var cleanup = function () {
          if (done) return;
          done = true;
          hideGlobalLoading(false);
        };
        if (xhr.addEventListener) {
          xhr.addEventListener('loadend', cleanup);
          xhr.addEventListener('error', cleanup);
          xhr.addEventListener('abort', cleanup);
        } else {
          var oldReady = xhr.onreadystatechange;
          xhr.onreadystatechange = function () {
            if (oldReady) oldReady.apply(xhr, arguments);
            if (xhr.readyState === 4) cleanup();
          };
        }
      }
      return originalSend.apply(this, arguments);
    };
  }

  // pages.js 자동 로드 (현재 스크립트 경로에서 같은 폴더의 pages.js 로드)
  (function loadPagesJs() {
    try {
      var cur = document.currentScript && document.currentScript.src ? document.currentScript.src : '';
      if (!cur) return;
      // .../assets/js/app.js -> .../assets/js/
      var base = cur.replace(/app\.js(\?.*)?$/i, '');
      var s = document.createElement('script');
      s.src = base + 'pages.js';
      s.defer = true;
      document.head.appendChild(s);
    } catch (e) {}
  })();

  // Sidebar collapse
  var sidebar = document.getElementById('cpmsSidebar');
  var toggle = document.getElementById('sidebarToggle');

  function setCollapsed(collapsed) {
    if (!sidebar) return;

    sidebar.className = sidebar.className.replace(/\bw-72\b/g, '').replace(/\bw-20\b/g, '').trim();
    sidebar.className += collapsed ? ' w-20' : ' w-72';

    sidebar.setAttribute('data-collapsed', collapsed ? '1' : '0');

    if (toggle) {
      toggle.innerHTML = collapsed
        ? '<i data-lucide="chevron-right" class="w-4 h-4 text-gray-600"></i>'
        : '<i data-lucide="chevron-left" class="w-4 h-4 text-gray-600"></i>';
    }

    localStorage.setItem('cpms_sidebar_collapsed', collapsed ? '1' : '0');
    refreshIcons();
  }

  if (sidebar && toggle) {
    var saved = localStorage.getItem('cpms_sidebar_collapsed') === '1';
    setCollapsed(saved);

    toggle.addEventListener('click', function () {
      var isCollapsed = sidebar.getAttribute('data-collapsed') === '1';
      setCollapsed(!isCollapsed);
    });
  }

  refreshIcons();
})();
