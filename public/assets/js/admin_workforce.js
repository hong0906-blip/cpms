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

  function editModal() { return document.getElementById('workforceEditModal'); }
  function editForm() { return document.getElementById('workforceEditForm'); }
  function editField(name) {
    var form = editForm();
    return form ? form.querySelector('[name="' + name + '"]') : null;
  }
  function setEditField(name, value) {
    var field = editField(name);
    if (field) field.value = value === null || typeof value === 'undefined' ? '' : value;
  }
  function showEditError(message) {
    var box = document.getElementById('workforceEditError');
    if (!box) return;
    box.textContent = message || '';
    if (message) box.classList.remove('hidden');
    else box.classList.add('hidden');
  }
  function closeEditModal() {
    var modal = editModal();
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    showEditError('');
  }
  function fillEditForm(worker) {
    setEditField('id', worker.id);
    setEditField('import_no', worker.import_no);
    setEditField('birth_date', worker.birth_date);
    setEditField('is_active', worker.is_active);
    setEditField('source_type', worker.source_type || 'manual');
    setEditField('name', worker.name);
    setEditField('phone', worker.phone);
    setEditField('resident_no', worker.resident_no_plain);
    setEditField('job_type', worker.job_type);
    setEditField('agency_name', worker.agency_name);
    setEditField('daily_wage', worker.daily_wage);
    setEditField('bank_name', worker.bank_name);
    setEditField('bank_account', worker.bank_account_plain);
    setEditField('account_holder', worker.account_holder);
    setEditField('address', worker.address);
    setEditField('memo', worker.memo);
  }

  function trimValue(value) {
    return String(value === null || typeof value === 'undefined' ? '' : value).replace(/^\s+|\s+$/g, '');
  }

  function digitsOnly(value) {
    return String(value || '').replace(/[^0-9]/g, '');
  }

  function validateEditForm() {
    var form = editForm();
    var relaxed = form && form.getAttribute('data-workforce-relaxed') === '1';
    var requiredFields = relaxed ? [
      ['name', '이름']
    ] : [
      ['name', '이름'],
      ['phone', '연락처'],
      ['resident_no', '주민번호'],
      ['job_type', '구분/직종'],
      ['agency_name', '인력사 업체명'],
      ['daily_wage', '임금단가'],
      ['bank_name', '은행명'],
      ['bank_account', '계좌번호'],
      ['account_holder', '예금주']
    ];
    var missing = [];
    var firstInvalid = null;
    for (var i = 0; i < requiredFields.length; i++) {
      var field = editField(requiredFields[i][0]);
      if (!field || trimValue(field.value) === '') {
        missing.push(requiredFields[i][1]);
        if (!firstInvalid && field) firstInvalid = field;
      }
    }
    if (missing.length > 0) {
      showEditError('저장할 수 없습니다. 다음 필수항목을 입력하세요: ' + missing.join(', '));
      if (firstInvalid) firstInvalid.focus();
      return false;
    }

    var residentField = editField('resident_no');
    var residentDigits = residentField ? digitsOnly(residentField.value) : '';
    if ((!relaxed && residentDigits.length !== 13) || (relaxed && residentDigits !== '' && residentDigits.length !== 13)) {
      showEditError('저장할 수 없습니다. 주민번호 13자리를 확인하세요.');
      if (residentField) residentField.focus();
      return false;
    }

    var phoneField = editField('phone');
    var phoneDigits = phoneField ? digitsOnly(phoneField.value) : '';
    if ((!relaxed && phoneDigits.length < 9) || (relaxed && phoneDigits !== '' && phoneDigits.length < 9)) {
      showEditError('저장할 수 없습니다. 연락처를 확인하세요.');
      if (phoneField) phoneField.focus();
      return false;
    }

    var wageField = editField('daily_wage');
    var wageText = wageField ? trimValue(wageField.value) : '';
    var wage = /^\d+$/.test(wageText) ? parseInt(wageText, 10) : 0;
    var invalidWage = relaxed
      ? (wageText !== '' && (!/^\d+$/.test(wageText) || wage < 0))
      : (!wage || wage <= 0);
    if (invalidWage) {
      showEditError(relaxed ? '저장할 수 없습니다. 임금단가는 0 이상의 정수로 입력하세요.' : '저장할 수 없습니다. 임금단가는 0보다 큰 금액으로 입력하세요.');
      if (wageField) wageField.focus();
      return false;
    }
    showEditError('');
    return true;
  }

  function openEditModal(workerId) {
    var modal = editModal();
    if (!modal || !workerId) return;
    showEditError('인력 정보를 불러오는 중입니다.');
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    fetchSensitiveWorker(workerId, function (worker) {
      sensitiveCache[workerId] = worker;
      fillEditForm(worker);
      showEditError('');
      var nameInput = editField('name');
      if (nameInput) nameInput.focus();
    }, function (message) {
      showEditError(message);
    });
  }

  function saveEditModal(event) {
    var form = editForm();
    if (!form) return false;
    if (event && event.preventDefault) event.preventDefault();
    if (!validateEditForm()) return false;
    showEditError('저장 중입니다.');
    var submit = form.querySelector('[data-workforce-edit-submit]');
    if (submit) submit.disabled = true;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.withCredentials = true;
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (submit) submit.disabled = false;
      try {
        var data = JSON.parse(xhr.responseText || '{}');
        if (xhr.status < 200 || xhr.status >= 300 || !data || !data.ok) {
          showEditError(data && data.message ? data.message : '저장하지 못했습니다.');
          return;
        }
        closeEditModal();
        window.location.reload();
      } catch (e) {
        showEditError('저장 응답을 읽지 못했습니다.');
      }
    };
    xhr.send(new FormData(form));
    return true;
  }

  document.addEventListener('submit', function (event) {
    if (event.target && event.target.id === 'workforceEditForm') {
      saveEditModal(event);
      return;
    }
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
    var editSubmit = closest(event.target, '[data-workforce-edit-submit]');
    if (editSubmit) {
      event.preventDefault();
      saveEditModal(event);
      return;
    }
    var editClose = closest(event.target, '[data-workforce-edit-close]');
    if (editClose) {
      event.preventDefault();
      closeEditModal();
      return;
    }
    var editButton = closest(event.target, '[data-workforce-edit]');
    if (editButton) {
      event.preventDefault();
      openEditModal(editButton.getAttribute('data-worker-id') || '');
      return;
    }
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

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeEditModal();
  });
})();
