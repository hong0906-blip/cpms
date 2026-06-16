/*
 * C:\www\cpms\public\assets\js\labor_personnel.js
 * - 공사 > 노무비 > 인원작성 인력관리 검색/선택
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
      .replace(/"/g, '&quot;')
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
})();
