/*
 * C:\www\cpms\public\assets\js\admin_workforce.js
 * - 관리 > 인력관리 삭제 확인
 */
(function () {
  var sensitiveCache = {};

  function closest(el, selector) {
    while (el && el.nodeType === 1) {
      if (el.matches && el.matches(selector)) return el;
      el = el.parentNode;
    }
    return null;
  }

  function sensitivePlainValue(worker, field) {
    if (!worker) return '';
    if (field === 'resident_no') return worker.resident_no_plain || '';
    if (field === 'bank_account') return worker.bank_account_plain || '';
    return '';
  }

  function setSensitiveState(button, display, visible, value) {
    if (!display || !button) return;
    display.textContent = value && String(value).replace(/^\s+|\s+$/g, '') !== '' ? value : '-';
    button.setAttribute('data-sensitive-visible', visible ? '1' : '0');
    button.textContent = visible ? '마스킹 생성' : '마스킹 해제';
    if (visible) {
      display.className = display.className + ' cpms-sensitive-visible';
    } else {
      display.className = display.className.replace(/\s*cpms-sensitive-visible/g, '');
    }
  }

  function fetchSensitiveWorker(workerId, onDone, onFail) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '?r=ajax/workforce_get&id=' + encodeURIComponent(workerId), true);
    xhr.withCredentials = true;
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status < 200 || xhr.status >= 300) {
        onFail('인력 정보를 불러오지 못했습니다.');
        return;
      }
      try {
        var data = JSON.parse(xhr.responseText || '{}');
        if (!data || !data.ok || !data.worker) {
          onFail(data && data.message ? data.message : '인력 정보를 불러오지 못했습니다.');
          return;
        }
        onDone(data.worker);
      } catch (e) {
        onFail('응답을 읽지 못했습니다.');
      }
    };
    xhr.send(null);
  }

  function toggleSensitive(button) {
    var cell = closest(button, '[data-sensitive-cell]');
    var display = cell ? cell.querySelector('[data-sensitive-display]') : null;
    if (!cell || !display) return;

    var visible = button.getAttribute('data-sensitive-visible') === '1';
    if (visible) {
      setSensitiveState(button, display, false, display.getAttribute('data-masked') || '-');
      return;
    }

    var workerId = button.getAttribute('data-worker-id') || '';
    var field = button.getAttribute('data-sensitive-field') || '';
    if (!workerId || !field) return;

    if (sensitiveCache[workerId]) {
      setSensitiveState(button, display, true, sensitivePlainValue(sensitiveCache[workerId], field));
      return;
    }

    button.disabled = true;
    button.textContent = '불러오는 중';
    fetchSensitiveWorker(workerId, function (worker) {
      sensitiveCache[workerId] = worker;
      button.disabled = false;
      setSensitiveState(button, display, true, sensitivePlainValue(worker, field));
    }, function (message) {
      button.disabled = false;
      button.textContent = '마스킹 해제';
      alert(message);
    });
  }

  document.addEventListener('submit', function (event) {
    var form = closest(event.target, '[data-workforce-delete-form]');
    if (!form) return;
    if (!window.confirm('해당 인력을 삭제 처리할까요?')) {
      event.preventDefault();
    }
  });

  document.addEventListener('change', function (event) {
    var all = closest(event.target, '[data-workforce-check-all]');
    if (!all) return;
    var checks = document.querySelectorAll('.workforce-row-check');
    for (var i = 0; i < checks.length; i++) {
      checks[i].checked = all.checked;
    }
  });

  document.addEventListener('click', function (event) {
    var sensitiveButton = closest(event.target, '[data-workforce-sensitive-toggle]');
    if (sensitiveButton) {
      event.preventDefault();
      toggleSensitive(sensitiveButton);
      return;
    }

    var btn = closest(event.target, '[data-workforce-bulk-delete]');
    if (!btn) return;
    event.preventDefault();

    var form = document.getElementById('workforceBulkDeleteForm');
    var checks = document.querySelectorAll('.workforce-row-check:checked');
    if (!form || checks.length === 0) {
      alert('삭제할 인력을 선택하세요.');
      return;
    }
    if (!window.confirm('선택한 인력을 삭제 처리할까요?')) return;

    var old = form.querySelectorAll('input[name="ids[]"]');
    for (var i = 0; i < old.length; i++) {
      old[i].parentNode.removeChild(old[i]);
    }
    for (var j = 0; j < checks.length; j++) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'ids[]';
      input.value = checks[j].value;
      form.appendChild(input);
    }
    form.submit();
  });
})();
