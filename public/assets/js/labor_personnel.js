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
    if (modal) modal.classList.remove('hidden');
    setTimeout(function () {
      var input = $('workforceSearchInput');
      if (input) input.focus();
    }, 0);
  }

  function closeModal() {
    var modal = $('workforceSearchModal');
    if (modal) modal.classList.add('hidden');
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
      html += ''
        + '<button type="button" class="w-full text-left rounded-2xl border border-gray-200 p-4 hover:bg-emerald-50" data-workforce-select="' + escapeHtml(w.id) + '">'
        + '<div class="font-extrabold text-gray-900">' + escapeHtml(w.name) + '</div>'
        + '<div class="mt-1 text-xs text-gray-600">'
        + escapeHtml(w.phone || '-') + ' · ' + escapeHtml(w.job_type || '-') + ' · ' + escapeHtml(w.agency_name || '-')
        + ' · ' + formatMoney(w.daily_wage) + '원'
        + '</div>'
        + '</button>';
    }
    box.innerHTML = html;
  }

  function search() {
    var input = $('workforceSearchInput');
    var q = input ? input.value.replace(/^\s+|\s+$/g, '') : '';
    var box = $('workforceSearchResults');
    if (!q) {
      if (box) box.innerHTML = '<div class="rounded-2xl border border-gray-200 p-4 text-gray-500">이름을 입력하세요.</div>';
      return;
    }
    if (box) box.innerHTML = '<div class="rounded-2xl border border-gray-200 p-4 text-gray-500">검색 중...</div>';

    fetch('?r=ajax/workforce_search&q=' + encodeURIComponent(q), {
      credentials: 'same-origin'
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) throw new Error(data && data.message ? data.message : '검색 실패');
        renderResults(data.items || []);
      })
      .catch(function (err) {
        if (box) box.innerHTML = '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700">' + escapeHtml(err.message || '검색 실패') + '</div>';
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
    if (target && target.closest && target.closest('[data-workforce-modal-open]')) {
      event.preventDefault();
      openModal();
      return;
    }
    if (target && target.closest && target.closest('[data-workforce-modal-close]')) {
      event.preventDefault();
      closeModal();
      return;
    }
    if (target && target.id === 'workforceSearchButton') {
      event.preventDefault();
      search();
      return;
    }
    var selectBtn = target && target.closest ? target.closest('[data-workforce-select]') : null;
    if (selectBtn) {
      var id = selectBtn.getAttribute('data-workforce-select') || '';
      var hidden = $('workforceAddWorkerId');
      var form = $('workforceAddForm');
      if (!id || !hidden || !form) return;
      hidden.value = id;
      form.submit();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeModal();
    if (event.key === 'Enter' && event.target && event.target.id === 'workforceSearchInput') {
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
