/*
 * C:\www\cpms\public\assets\js\labor_personnel.js
 * - 공사 > 노무비 > 인원작성 인력관리 검색/선택
 * - 비용 배분의 "날짜로 선택" 모드 처리
 * - PHP 5.6 기반 화면과 호환되는 순수 JavaScript
 */
(function () {
  function $(id) {
    return document.getElementById(id);
  }

  function openModal() {
    var modal = $('workforceSearchModal');
    if (modal) {
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
    }
  }

  function closeModal() {
    var modal = $('workforceSearchModal');
    if (modal) {
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
    }
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatMoney(value) {
    var n = parseInt(value || 0, 10);
    if (isNaN(n)) n = 0;
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function renderResults(items) {
    var box = $('workforceSearchResults');
    if (!box) return;
    if (!items || !items.length) {
      box.innerHTML = '<div class="rounded-2xl border border-gray-200 p-4 text-gray-500">검색 결과가 없습니다.</div>';
      return;
    }
    var html = '';
    for (var i = 0; i < items.length; i++) {
      var w = items[i] || {};
      html += '<label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-gray-200 p-4 hover:bg-emerald-50">'
        + '<input type="checkbox" class="mt-1 workforce-duplicate-check" value="' + escapeHtml(w.id) + '">'
        + '<span class="min-w-0 flex-1">'
        + '<span class="block font-extrabold text-gray-900">' + escapeHtml(w.name) + '</span>'
        + '<span class="mt-1 block text-xs text-gray-600">인력사 ' + escapeHtml(w.agency_name || '-')
        + ' · 연락처 ' + escapeHtml(w.phone || '-')
        + ' · 주민번호 앞자리 ' + escapeHtml(w.resident_no_front || '-')
        + ' · ' + formatMoney(w.daily_wage) + '원</span>'
        + '</span></label>';
    }
    box.innerHTML = html;
  }

  function submitWorker(workerId) {
    var hidden = $('workforceAddWorkerId');
    var form = $('workforceAddForm');
    if (!workerId || !hidden || !form) return;
    hidden.value = workerId;
    form.submit();
  }

  function search() {
    var input = $('workforceQuickSearchInput');
    var q = input ? input.value.replace(/^\s+|\s+$/g, '') : '';
    var status = $('workforceQuickSearchStatus');
    if (!q) {
      if (status) status.textContent = '이름을 입력하세요.';
      return;
    }
    if (status) status.textContent = '인력관리에서 검색 중...';

    fetch('?r=ajax/workforce_search&q=' + encodeURIComponent(q), {
      credentials: 'same-origin'
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) throw new Error(data && data.message ? data.message : '검색 실패');
        var items = data.items || [];
        var exact = [];
        for (var i = 0; i < items.length; i++) {
          if (trim(items[i] && items[i].name) === q) exact.push(items[i]);
        }
        if (exact.length === 1) {
          if (status) status.textContent = exact[0].name + ' 인원을 추가합니다.';
          submitWorker(exact[0].id);
          return;
        }
        var candidates = exact.length > 1 ? exact : items;
        if (!candidates.length) {
          if (status) status.textContent = '등록된 인력이 없습니다. 옆의 인원 등록 탭에서 먼저 등록하세요.';
          return;
        }
        if (status) status.textContent = exact.length > 1 ? '동명이인이 있습니다. 확인 후 한 명을 선택하세요.' : '검색 결과에서 추가할 한 명을 선택하세요.';
        renderResults(candidates);
        openModal();
      })
      .catch(function (err) {
        if (status) status.textContent = err.message || '검색 실패';
      });
  }

  function trim(value) {
    return String(value || '').replace(/^\s+|\s+$/g, '');
  }

  function findDateBox(box, startInput, endInput) {
    if (!box || !startInput || !endInput) return null;

    var current = startInput.parentNode;
    while (current && current !== box) {
      if (current.querySelector && current.querySelector('[data-allocation-end-date]')) {
        if (current.classList && current.classList.contains('rounded-xl')) return current;
      }
      current = current.parentNode;
    }

    current = startInput.parentNode;
    while (current && current !== box) {
      if (current.querySelector && current.querySelector('[data-allocation-end-date]')) return current;
      current = current.parentNode;
    }

    return null;
  }

  function ensureDateOption(preset) {
    if (!preset || preset.querySelector('option[value="date"]')) return;

    var option = document.createElement('option');
    option.value = 'date';
    option.textContent = '날짜로 선택';

    var customOption = preset.querySelector('option[value="custom"]');
    if (customOption) preset.insertBefore(option, customOption);
    else preset.appendChild(option);
  }

  function setHiddenRatio(box, ratio) {
    var hidden = box ? box.querySelector('[data-allocation-ratio]') : null;
    var customInput = box ? box.querySelector('[data-allocation-custom-input]') : null;
    if (hidden) hidden.value = String(ratio);
    if (customInput) customInput.value = String(ratio);
  }

  function setSummary(box, text) {
    var summary = box ? box.querySelector('[data-allocation-summary]') : null;
    if (summary) summary.textContent = text;
  }

  function setDateBoxVisible(dateBox, visible) {
    if (!dateBox || !dateBox.classList) return;
    if (visible) dateBox.classList.remove('hidden');
    else dateBox.classList.add('hidden');
  }

  function clearDateInputs(startInput, endInput) {
    if (startInput) startInput.value = '';
    if (endInput) endInput.value = '';
  }

  function syncAllocationDateMode(box, isInitialLoad) {
    if (!box) return;

    var preset = box.querySelector('[data-allocation-preset]');
    var hidden = box.querySelector('[data-allocation-ratio]');
    var customBox = box.querySelector('[data-allocation-custom]');
    var startInput = box.querySelector('[data-allocation-start-date]');
    var endInput = box.querySelector('[data-allocation-end-date]');
    var dateBox = findDateBox(box, startInput, endInput);

    if (!preset) return;
    ensureDateOption(preset);

    var ratio = hidden ? parseInt(hidden.value, 10) : 0;
    if (isNaN(ratio)) ratio = 0;
    var hasDateRange = trim(startInput ? startInput.value : '') !== ''
      && trim(endInput ? endInput.value : '') !== '';

    if (isInitialLoad && ratio === 100 && hasDateRange) {
      preset.value = 'date';
    }

    var isDateMode = preset.value === 'date';
    setDateBoxVisible(dateBox, isDateMode);

    if (isDateMode) {
      setHiddenRatio(box, 100);
      if (customBox && customBox.classList) customBox.classList.add('hidden');
      setSummary(box, '선택한 날짜만 외주비 100% / 나머지 날짜는 노무비 100%');
      return;
    }

    if (!isInitialLoad) clearDateInputs(startInput, endInput);
  }

  function initAllocationDateMode() {
    var boxes = document.querySelectorAll('[data-labor-allocation]');
    for (var i = 0; i < boxes.length; i++) {
      (function (box) {
        var preset = box.querySelector('[data-allocation-preset]');
        var startInput = box.querySelector('[data-allocation-start-date]');
        var endInput = box.querySelector('[data-allocation-end-date]');
        var form = box.closest ? box.closest('form') : null;

        syncAllocationDateMode(box, true);

        if (preset) {
          preset.addEventListener('change', function () {
            window.setTimeout(function () {
              syncAllocationDateMode(box, false);
              if (preset.value === 'date' && startInput) startInput.focus();
            }, 0);
          });
        }

        if (form && !form.getAttribute('data-allocation-date-validation')) {
          form.setAttribute('data-allocation-date-validation', '1');
          form.addEventListener('submit', function (event) {
            var formBoxes = form.querySelectorAll('[data-labor-allocation]');
            for (var j = 0; j < formBoxes.length; j++) {
              var formBox = formBoxes[j];
              var formPreset = formBox.querySelector('[data-allocation-preset]');
              var formStart = formBox.querySelector('[data-allocation-start-date]');
              var formEnd = formBox.querySelector('[data-allocation-end-date]');

              if (formPreset && formPreset.value === 'date') {
                setHiddenRatio(formBox, 100);
                if (!formStart || trim(formStart.value) === '' || !formEnd || trim(formEnd.value) === '') {
                  event.preventDefault();
                  alert('날짜로 선택한 인원은 시작일과 종료일을 모두 입력해 주세요.');
                  if (formStart && trim(formStart.value) === '') formStart.focus();
                  else if (formEnd) formEnd.focus();
                  return false;
                }
              } else {
                clearDateInputs(formStart, formEnd);
              }
            }
            return true;
          });
        }
      })(boxes[i]);
    }
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (target && target.closest && target.closest('[data-workforce-modal-close]')) {
      event.preventDefault();
      closeModal();
      return;
    }
    if (target && target.id === 'workforceQuickSearchButton') {
      event.preventDefault();
      search();
      return;
    }
    if (target && target.id === 'workforceDuplicateAddButton') {
      event.preventDefault();
      var checked = document.querySelector('.workforce-duplicate-check:checked');
      if (!checked) {
        alert('추가할 인원을 선택하세요.');
        return;
      }
      submitWorker(checked.value);
    }
  });

  document.addEventListener('change', function (event) {
    var target = event.target;
    if (!target || !target.classList || !target.classList.contains('workforce-duplicate-check') || !target.checked) return;
    var checks = document.querySelectorAll('.workforce-duplicate-check');
    for (var i = 0; i < checks.length; i++) {
      if (checks[i] !== target) checks[i].checked = false;
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeModal();
    if (event.key === 'Enter' && event.target && event.target.id === 'workforceQuickSearchInput') {
      event.preventDefault();
      search();
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllocationDateMode);
  } else {
    initAllocationDateMode();
  }
})();
