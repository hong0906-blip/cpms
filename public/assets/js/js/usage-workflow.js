(function () {
  'use strict';

  function routeFromForm(form) {
    var action = form.getAttribute('action') || window.location.href;
    var match = action.match(/[?&]r=([^&#]+)/);
    var route = match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
    if (!route) {
      match = window.location.search.match(/[?&]r=([^&#]+)/);
      route = match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : 'current';
    }
    return route;
  }

  function safeKey(value) {
    return String(value || '').toLowerCase().replace(/[^a-z0-9_:\/-]/g, '_').substring(0, 190);
  }

  function inputStep(target) {
    var name = String(target && target.name ? target.name : '').toLowerCase();
    if (/project(_id)?|\bpid\b/.test(name)) return 'PROJECT_SELECT';
    if (/cost_type|category|material_id|equipment_id|item_id/.test(name)) return 'COST_TYPE_SELECT';
    if (/amount|price|rate|quantity|gongsu|cost/.test(name)) return 'AMOUNT_INPUT';
    return 'FORM_INPUT';
  }

  function postEvent(form, eventType, useBeacon, stepName) {
    var token = form.querySelector('input[name="_csrf"]');
    if (!token || !token.value) return;
    var route = routeFromForm(form);
    if (route === 'usage_analytics/event') return;
    var actionName = 'submit';
    var step = safeKey(stepName || eventType || '').toUpperCase();
    var featureKey = safeKey(route + ':' + actionName);
    if (!featureKey) return;
    var data = new FormData();
    data.append('_csrf', token.value);
    data.append('event_type', eventType);
    data.append('feature_key', featureKey);
    data.append('workflow_id', form._cpmsWorkflowId || 'wf_' + new Date().getTime());
    data.append('route_name', route);
    data.append('action_name', step || actionName);
    data.append('feature_name', route + ' ' + actionName);
    data.append('result_code', step);
    if (useBeacon && navigator.sendBeacon) {
      navigator.sendBeacon('?r=usage_analytics/event', data);
      return;
    }
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '?r=usage_analytics/event', true);
      xhr.send(data);
    } catch (ignore) {}
  }

  function activate(form, index) {
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if (!form.querySelector('input[name="_csrf"]')) return;
    form._cpmsWorkflowId = 'wf_' + new Date().getTime() + '_' + index;
    form._cpmsDirty = false;
    form._cpmsSubmitted = false;
    form._cpmsInputSteps = {};
    form.addEventListener('input', function (event) {
      var target = event.target;
      if (!target || target.type === 'hidden' || target.type === 'submit' || target.type === 'button') return;
      form._cpmsDirty = true;
      var step = inputStep(target);
      if (form._cpmsInputSteps[step]) return;
      form._cpmsInputSteps[step] = true;
      postEvent(form, 'FORM_INPUT', false, step);
    });
    form.addEventListener('change', function (event) {
      var target = event.target;
      if (!target || target.type === 'hidden') return;
      form._cpmsDirty = true;
      var step = inputStep(target);
      if (form._cpmsInputSteps[step]) return;
      form._cpmsInputSteps[step] = true;
      postEvent(form, 'FORM_INPUT', false, step);
    });
    form.addEventListener('submit', function () {
      form._cpmsSubmitted = true;
    });
  }

  function initialize() {
    var forms = document.getElementsByTagName('form');
    var tracked = [];
    var i;
    for (i = 0; i < forms.length; i += 1) {
      activate(forms[i], i);
      tracked.push(forms[i]);
    }
    window.addEventListener('beforeunload', function () {
      var j;
      for (j = 0; j < tracked.length; j += 1) {
        if (tracked[j]._cpmsDirty && !tracked[j]._cpmsSubmitted) postEvent(tracked[j], 'EXIT_WITHOUT_SAVE', true, 'EXIT_WITHOUT_SAVE');
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
}());
