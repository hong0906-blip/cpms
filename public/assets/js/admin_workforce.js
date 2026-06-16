/*
 * C:\www\cpms\public\assets\js\admin_workforce.js
 * - 관리 > 인력관리 삭제 확인
 */
(function () {
  function closest(el, selector) {
    while (el && el.nodeType === 1) {
      if (el.matches && el.matches(selector)) return el;
      el = el.parentNode;
    }
    return null;
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
