/* StaXX — the bug button.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_213: a small round button in the corner of every StaXX screen that
 * opens a window for sending a report to the project's feedback board, under
 * the person's own board account. Pictures can be pasted straight into the
 * report. The server half (connect, upload, send) is include/Feedback.php; the
 * board's token never reaches this file.
 *
 * Two windows, both popovers that dim nothing: a small connect window that a
 * click outside dismisses, and the report form, which floats, drags by its
 * title bar, minimises to the top middle and is closed only by its own buttons.
 *
 * Why everything moves: the editor and Settings are modal <dialog>s, and a
 * modal makes everything outside itself unclickable, even something drawn
 * above it. A popover shown from inside the open modal is clickable and still
 * sits where it is placed on screen, so the button and the form follow the
 * newest open modal and return to the page when none is left.
 *
 * Same shape as leftovers.js: it reads only the page's own data-csrf and
 * data-endpoint scaffold and never touches a stacks.js global.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
(function () {
  'use strict';

  var MAX_BYTES = 5 * 1024 * 1024;   // the server refuses a picture over this
  var SETTINGS_ID = 'staxx-feedback-settings';
  var LOGO = '/plugins/staxx/images/staxx.png';

  function scaffold() { return document.querySelector('.staxx-scaffold'); }

  // URLSearchParams only: a multipart POST (FormData) hangs on this box.
  function call(action, fields) {
    var el = scaffold();
    if (!el) return Promise.resolve({ ok: false, error: 'The page is not ready yet.' });

    var data = new URLSearchParams();
    data.append('csrf_token', el.dataset.csrf || '');
    data.append('action', action);
    Object.keys(fields || {}).forEach(function (key) { data.append(key, fields[key]); });

    return fetch(el.dataset.endpoint, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'The request failed.' }; });
  }

  /* ---------------------------------------------------- page-error record - */

  // What this page has run into since it loaded, for the "Errors this page has
  // run into" detail: script errors, unhandled rejections, and every reply from
  // StaXX's own endpoint that said ok:false. The last 50, with local times.
  var pageErrors = [];

  function recordError(message) {
    try {
      var d = new Date();
      function two(n) { return (n < 10 ? '0' : '') + n; }
      var line = String(message == null ? '' : message).replace(/\s+/g, ' ').slice(0, 500);
      pageErrors.push(two(d.getHours()) + ':' + two(d.getMinutes()) + ':' + two(d.getSeconds()) + '  ' + line);
      if (pageErrors.length > 50) pageErrors.shift();
    } catch (e) { /* recording must never raise */ }
  }

  window.addEventListener('error', function (event) {
    var where = event.filename ? ' (' + event.filename.split('/').pop() + ':' + event.lineno + ')' : '';
    recordError((event.message || 'Script error') + where);
  });
  window.addEventListener('unhandledrejection', function (event) {
    var why = event.reason;
    recordError('Unhandled rejection: ' + (why && why.message ? why.message : why));
  });

  function sameAddress(a, b) {
    try { return new URL(a, location.href).href === new URL(b, location.href).href; } catch (e) { return false; }
  }

  // The page's own requests pass through untouched; a clone of the reply is
  // read on the side. Every failure of the wrapper itself is swallowed.
  if (typeof window.fetch === 'function') {
    var realFetch = window.fetch;
    window.fetch = function (input, init) {
      var action = '';
      try {
        var root = scaffold();
        var url = typeof input === 'string' ? input : (input && input.url);
        var body = init && init.body;
        if (root && root.dataset.endpoint && url && body instanceof URLSearchParams &&
            sameAddress(url, root.dataset.endpoint)) {
          action = body.get('action') || '';
          if (action.indexOf('feedback-') === 0) action = '';
        }
      } catch (e) { action = ''; }
      var p = realFetch.apply(this, arguments);
      if (!action) return p;
      return p.then(function (r) {
        try {
          r.clone().json().then(function (j) {
            if (j && j.ok === false) recordError(action + ': ' + (j.error || 'The request failed.'));
          }).catch(function () { /* not JSON */ });
        } catch (e) { /* body already taken */ }
        return r;
      });
    };
  }

  var ESCAPE_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESCAPE_MAP[c]; });
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  /* ------------------------------------------------------ corner button - */

  var hasPopover = typeof HTMLElement !== 'undefined' &&
    typeof HTMLElement.prototype.showPopover === 'function';

  var host = el('div', 'unapi staxx-bug-host');
  var bugBtn = el('button', 'staxx-bug-btn');
  bugBtn.type = 'button';
  bugBtn.title = 'Report a bug or suggest an idea';
  bugBtn.setAttribute('aria-label', 'Report a bug or suggest an idea');
  bugBtn.innerHTML = '<i class="fa fa-comment-o" aria-hidden="true"></i>';
  host.appendChild(bugBtn);
  if (hasPopover) host.setAttribute('popover', 'manual');
  else host.classList.add('staxx-bug-host--fixed');

  // The popover calls throw when the element is already in the asked state.
  function showPop(n) { if (hasPopover) { try { n.showPopover(); } catch (e) { /* already shown */ } } }
  function hidePop(n) { if (hasPopover) { try { n.hidePopover(); } catch (e) { /* already hidden */ } } }
  function isShown(n) {
    if (!hasPopover) return true;
    try { return n.matches(':popover-open'); } catch (e) { return false; }
  }

  // Open modal dialogs, oldest first; the newest is the one the person can reach.
  var modals = [];
  var formOpen = false;
  var previewOpen = false;
  var previewWin = null;

  function isModal(d) {
    try { return d.matches(':modal'); } catch (e) { return true; }
  }

  function trackDialog(d) {
    if (d.tagName === 'DIALOG' && d.open && isModal(d) && modals.indexOf(d) < 0) modals.push(d);
  }

  function newestModal() {
    modals = modals.filter(function (d) { return d.open && document.documentElement.contains(d); });
    return modals[modals.length - 1] || null;
  }

  // A popover outside the newest modal is inert, so each one is moved into it
  // and shown again. Only when the parent is wrong: moving fires the observer.
  function relocate(n, target, quiet) {
    if (n.parentNode !== target) {
      hidePop(n);
      if (quiet) n.classList.add('staxx-bugwin--moving');   // no fade or glide for a mere move
      target.appendChild(n);
      showPop(n);
      if (quiet) setTimeout(function () { n.classList.remove('staxx-bugwin--moving'); }, 80);
    } else if (!isShown(n)) {
      showPop(n);
    }
  }

  function place() {
    var target = newestModal() || scaffold();
    if (!target) return;
    relocate(host, target, false);
    if (formOpen) relocate(formWin, target, true);
    if (previewOpen) relocate(previewWin, target, true);
  }

  function screenName() {
    var d = newestModal();
    var t = null;
    if (d) {
      // The report windows can sit inside the dialog; their own titles do not count.
      Array.prototype.some.call(d.querySelectorAll('h1,h2,h3,[class*="title"]'), function (c) {
        if (c.closest('.staxx-bugwin')) return false;
        t = c;
        return true;
      });
    }
    var text = t ? t.textContent.trim().slice(0, 80) : '';
    return text || 'Stacks list';
  }

  function pxPerRem() {
    return parseFloat(getComputedStyle(document.documentElement).fontSize) || 10;
  }

  // Shared title bar: the StaXX logo and the window's name.
  function head(titleId, text) {
    var h = el('div', 'staxx-confirm-head');
    var t = el('h3', 'staxx-confirm-title');
    t.id = titleId;
    var logo = el('img', 'staxx-bugwin-logo');
    logo.src = LOGO;
    logo.alt = '';
    var label = el('span', '', text);
    t.appendChild(logo);
    t.appendChild(label);
    h.appendChild(t);
    return { box: h, label: label };
  }

  function popoverWindow(cls, mode, titleId) {
    var w = el('div', 'staxx-confirm unapi staxx-bugwin ' + cls);
    w.setAttribute('role', 'dialog');
    w.setAttribute('aria-labelledby', titleId);
    if (hasPopover) w.setAttribute('popover', mode);
    return w;
  }

  /* ------------------------------------------------------- connect window - */

  var INTRO = 'Reports are sent under your own feedback-board account, signed in with Google or ' +
    'GitHub. Connect once and StaXX remembers it.';
  var RECONNECT = 'The connection to the feedback board has ended. Connect again to send this report; ' +
    'what you typed is kept.';

  var connectWin = null, connView = {}, connMsg, connFoot, connectBtn, userCodeEl, openBtn, gotEl, reportBtn;
  var openUrl = '';
  var connectedName = '';
  var pollTimer = 0;
  var pollExpires = 0;
  var pollInterval = 5;

  function stopPoll() { clearTimeout(pollTimer); pollTimer = 0; }

  function setConnMsg(text) {
    connMsg.textContent = text || '';
    connFoot.hidden = !text;
  }

  function connectView(name) {
    Object.keys(connView).forEach(function (k) { connView[k].hidden = k !== name; });
    if (name !== 'code') stopPoll();
  }

  function showStart(intro) {
    connView.start.firstChild.textContent = intro || INTRO;
    connectBtn.disabled = false;
    connectView('start');
  }

  function showDone() {
    gotEl.lastChild.textContent = connectedName ? 'You’re connected as ' + connectedName + '.' : 'You’re connected.';
    connectView('done');
  }

  function showCode(r) {
    openUrl = r.verificationUriComplete || '';
    userCodeEl.textContent = r.userCode || '';
    pollInterval = Number(r.interval) || 5;
    pollExpires = r.expiresAt ? Date.parse(r.expiresAt) : 0;
    connectView('code');
    pollLater();
  }

  function connectOpen() {
    if (!hasPopover || !connectWin) return false;
    try { return connectWin.matches(':popover-open'); } catch (e) { return false; }
  }

  function pollLater() {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(pollNow, Math.max(2, pollInterval) * 1000);
  }

  function codeEnded(text) {
    showStart(INTRO);
    setConnMsg(text);
  }

  function pollNow() {
    if (!connectOpen()) return;
    if (pollExpires && Date.now() > pollExpires) {
      codeEnded('The code ran out. Press Connect for a new one.');
      return;
    }
    call('feedback-poll').then(function (r) {
      if (!connectOpen()) return;
      if (!r.ok) { setConnMsg(r.error || 'The request failed.'); pollLater(); return; }
      if (r.interval) pollInterval = Number(r.interval) || pollInterval;
      if (r.status === 'connected') {
        connectedName = r.name || '';
        setConnMsg('');
        showDone();
        refreshSettings();
      } else if (r.status === 'denied') {
        codeEnded('You pressed Deny, or this account cannot post. Nothing was connected.');
      } else if (r.status === 'expired') {
        codeEnded('The code ran out. Press Connect for a new one.');
      } else {
        pollLater();
      }
    });
  }

  function startConnect() {
    connectBtn.disabled = true;
    setConnMsg('');
    call('feedback-connect').then(function (r) {
      if (!r.ok) {
        connectBtn.disabled = false;
        setConnMsg(r.error || 'The request failed.');
        return;
      }
      showCode(r);
    });
  }

  function buildConnect() {
    var h = head('staxx-bugwin-connect-title', 'Connect to the feedback board');
    connectWin = popoverWindow('staxx-bugwin-connectwin', 'auto', 'staxx-bugwin-connect-title');
    var body = el('div', 'staxx-confirm-body');

    // Start: what connecting means, and the button.
    var vStart = el('div', 'staxx-bugwin-view staxx-bugwin-view--connect');
    vStart.appendChild(el('p', 'staxx-bugwin-intro', INTRO));
    connectBtn = el('button', 'staxx-btn staxx-btn--primary', 'Connect');
    connectBtn.type = 'button';
    vStart.appendChild(connectBtn);

    // Code: the board shows the same code for the person to check.
    var vCode = el('div', 'staxx-bugwin-view staxx-bugwin-view--connect');
    vCode.appendChild(el('p', 'staxx-bugwin-intro', 'Open the board, sign in, and check it shows this code:'));
    userCodeEl = el('div', 'staxx-bugwin-code');
    vCode.appendChild(userCodeEl);
    openBtn = el('button', 'staxx-btn staxx-btn--primary', 'Open the board');
    openBtn.type = 'button';
    openBtn.insertAdjacentHTML('beforeend', '<i class="fa fa-external-link staxx-bugwin-btn-icon" aria-hidden="true"></i>');
    vCode.appendChild(openBtn);
    var wait = el('p', 'staxx-bugwin-wait');
    wait.innerHTML = '<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i>';
    wait.appendChild(document.createTextNode('Waiting for you to allow StaXX…'));
    vCode.appendChild(wait);

    // Done.
    var vDone = el('div', 'staxx-bugwin-view staxx-bugwin-view--connect');
    gotEl = el('p', 'staxx-bugwin-gotit');
    gotEl.innerHTML = '<i class="fa fa-check-circle staxx-bugwin-tick" aria-hidden="true"></i>';
    gotEl.appendChild(document.createTextNode(''));
    vDone.appendChild(gotEl);
    vDone.appendChild(el('p', 'staxx-bugwin-intro', 'Reports you send from StaXX now go under your account.'));
    reportBtn = el('button', 'staxx-btn staxx-btn--primary', 'Write your report');
    reportBtn.type = 'button';
    vDone.appendChild(reportBtn);

    connView = { start: vStart, code: vCode, done: vDone };
    body.appendChild(vStart);
    body.appendChild(vCode);
    body.appendChild(vDone);

    connFoot = el('div', 'staxx-confirm-foot');
    connMsg = el('p', 'staxx-confirm-msg');
    connMsg.setAttribute('role', 'status');
    connMsg.setAttribute('aria-live', 'polite');
    connFoot.appendChild(connMsg);
    connFoot.hidden = true;

    connectWin.appendChild(h.box);
    connectWin.appendChild(body);
    connectWin.appendChild(connFoot);
    (scaffold() || document.body).appendChild(connectWin);

    connectBtn.addEventListener('click', startConnect);
    openBtn.addEventListener('click', function () {
      if (openUrl) window.open(openUrl, '_blank', 'noopener');
    });
    reportBtn.addEventListener('click', function () {
      hidePop(connectWin);
      openForm();
    });
    // A click outside or Escape closes it; the code stops being checked.
    connectWin.addEventListener('toggle', function (event) {
      if (event.newState === 'closed') stopPoll();
    });
  }

  function applyStatus(r, intro) {
    if (!connectOpen()) return;
    if (!r.ok) {
      showStart(intro);
      setConnMsg(r.error || 'The request failed.');
    } else if (r.connected) {
      connectedName = r.name || '';
      showDone();
    } else if (r.pending && r.pending.userCode) {
      showStart(intro);
      showCode(r.pending);
    } else {
      showStart(intro);
    }
    refreshSettings();
  }

  // `status` is a reply the caller already has; without one it is fetched.
  function openConnect(intro, status) {
    if (!connectWin) buildConnect();
    // Inside the newest modal, or the modal would leave it unclickable.
    var target = newestModal() || scaffold() || document.body;
    if (connectWin.parentNode !== target) { hidePop(connectWin); target.appendChild(connectWin); }
    setConnMsg('');
    showStart(intro);
    showPop(connectWin);
    if (status) applyStatus(status, intro);
    else call('feedback-status').then(function (r) { applyStatus(r, intro); });
  }

  /* --------------------------------------------------------- report form - */

  var screenAtPress = 'Stacks list';
  var busy = false;
  var minimised = false;
  var savedPos = null;
  var placed = false;

  var formWin, titleEl, minBtn, formView, sentView, titleIn, editor, asEl;
  var thanksEl, sentUrlEl, copyTitle, copyBody, copyFoot;
  var formMsg, rowBtns, cancelBtn, sendBtn, askBox, keepBtn, discardBtn;
  var detailsBox, detailsList, attachNote, sentFilesBox, sentFilesList;
  var details = null;      // { items: [{key, label, file, segments, on}] } while loaded
  var detailsToken = 0;    // a reply for an older opening is ignored

  // The kinds of report. Each label is the exact name of the board section the
  // server sends it to; only a bug report carries the detail files.
  var KINDS = [
    { id: 'bug', label: 'Bug Report', icon: 'fa-bug', title: 'Report a problem', heading: 'What happened' },
    { id: 'feature', label: 'Feature Requests', icon: 'fa-lightbulb-o', title: 'Suggest a feature',
      heading: 'What would you like StaXX to do?' },
    { id: 'improvement', label: 'Improvements', icon: 'fa-level-up', title: 'Suggest an improvement',
      heading: 'What could work better, and how?' }
  ];
  var kind = KINDS[0];
  var kindBtns = [], bodyHeadEl;

  function kindTitle() { return sentView && !sentView.hidden ? 'Report sent' : kind.title; }

  // Shows the chosen kind everywhere it is worded: switch, window title, body
  // heading and the details box.
  function applyKind() {
    kindBtns.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.kind === kind.id ? 'true' : 'false'); });
    titleEl.textContent = kindTitle();
    bodyHeadEl.textContent = kind.heading;
    editor.setAttribute('aria-label', kind.heading);
    syncDetailsBox();
    refreshReview();   // only a bug report carries files, so only it can be blocked
  }

  function setKind(id) {
    kind = KINDS.filter(function (k) { return k.id === id; })[0] || KINDS[0];
    applyKind();
  }

  // Ticks live on the items, so returning to Bug Report shows them as they were.
  function syncDetailsBox() {
    if (detailsBox) detailsBox.hidden = !(kind.id === 'bug' && details && details.items.length);
  }

  function setMsg(text) { formMsg.textContent = text || ''; }

  function hasContent() {
    return !!(titleIn.value.trim() || editor.textContent.trim() || editor.querySelector('img'));
  }

  function formStep(name) {
    var sent = name === 'sent';
    formView.hidden = sent;
    sentView.hidden = !sent;
    sendBtn.hidden = sent;
    cancelBtn.textContent = sent ? 'Close' : 'Cancel';
    titleEl.textContent = kindTitle();
  }

  function moveTo(left, top) {
    formWin.style.left = left + 'px';
    formWin.style.top = top + 'px';
  }

  function setMinimised(on) {
    var icon = minBtn.firstChild;
    if (on === minimised) return;
    minimised = on;
    formWin.classList.toggle('staxx-bugwin--min', on);
    icon.className = on ? 'fa fa-chevron-down' : 'fa fa-chevron-up';
    minBtn.title = on ? 'Restore' : 'Minimise';
    minBtn.setAttribute('aria-label', minBtn.title);
    if (on) {
      var r = formWin.getBoundingClientRect();
      savedPos = { left: r.left, top: r.top };
      // Centred from the minimised width, not the width at the moment of pressing.
      moveTo(Math.max(0, (window.innerWidth - 32 * pxPerRem()) / 2), 27);
    } else if (savedPos) {
      moveTo(savedPos.left, savedPos.top);
    }
  }

  function closeForm() {
    formOpen = false;
    setMinimised(false);
    askBox.hidden = true;
    rowBtns.hidden = false;
    if (!sentView.hidden) formStep('form');
    setKind('bug');   // the next report starts fresh, on Bug Report
    setMsg('');
    hidePop(formWin);
  }

  function askDiscard(on) {
    askBox.hidden = !on;
    rowBtns.hidden = on;
    if (on) keepBtn.focus();
  }

  function onCancel() {
    if (busy) return;
    if (!sentView.hidden || !hasContent()) { closeForm(); return; }
    askDiscard(true);
  }

  function discard() {
    titleIn.value = '';
    editor.innerHTML = '';
    clearDetails();
    closeForm();
  }

  // Drag by the title bar, never starting on a button; some of the window
  // always stays on screen so it can be dragged back.
  function enableDrag(bar) {
    var dx = 0, dy = 0, active = false;
    bar.addEventListener('pointerdown', function (event) {
      if (event.button !== 0 || event.target.closest('button')) return;
      var r = formWin.getBoundingClientRect();
      dx = event.clientX - r.left;
      dy = event.clientY - r.top;
      active = true;
      formWin.classList.add('staxx-bugwin--dragging');
      bar.setPointerCapture(event.pointerId);
    });
    bar.addEventListener('pointermove', function (event) {
      if (!active) return;
      var w = formWin.getBoundingClientRect().width;
      var left = Math.min(Math.max(event.clientX - dx, 0), window.innerWidth - 80);
      var top = Math.min(Math.max(event.clientY - dy, 0), window.innerHeight - 40);
      if (left + w < 80) left = 80 - w;
      moveTo(left, top);
    });
    function stop(event) {
      if (!active) return;
      active = false;
      formWin.classList.remove('staxx-bugwin--dragging');
      try { bar.releasePointerCapture(event.pointerId); } catch (e) { /* already released */ }
    }
    bar.addEventListener('pointerup', stop);
    bar.addEventListener('pointercancel', stop);
  }

  // Built once and reused, so what was typed survives a close.
  function buildForm() {
    var h = head('staxx-bugwin-title', KINDS[0].title);
    titleEl = h.label;
    formWin = popoverWindow('staxx-bugwin-float', 'manual', 'staxx-bugwin-title');

    minBtn = el('button', 'staxx-bugwin-min');
    minBtn.type = 'button';
    minBtn.title = 'Minimise';
    minBtn.setAttribute('aria-label', 'Minimise');
    minBtn.innerHTML = '<i class="fa fa-chevron-up" aria-hidden="true"></i>';
    h.box.appendChild(minBtn);

    var body = el('div', 'staxx-confirm-body');

    formView = el('div', 'staxx-bugwin-view');
    // Reuses the editor's Form/Split/Compose switch look.
    var kinds = el('div', 'staxx-views');
    kinds.setAttribute('role', 'group');
    kinds.setAttribute('aria-label', 'Kind of report');
    KINDS.forEach(function (k) {
      var b = el('button', 'staxx-viewbtn');
      b.type = 'button';
      b.dataset.kind = k.id;
      b.innerHTML = '<i class="fa ' + k.icon + '" aria-hidden="true"></i>';
      b.appendChild(document.createTextNode(k.label));
      b.addEventListener('click', function () { setKind(k.id); });
      kinds.appendChild(b);
      kindBtns.push(b);
    });
    var kindsRow = el('div', 'staxx-bugwin-kinds');
    kindsRow.appendChild(kinds);
    formView.appendChild(kindsRow);
    var tl = el('label', 'staxx-bugwin-label', 'Title');
    titleIn = el('input', 'staxx-bugwin-input');
    titleIn.type = 'text';
    titleIn.maxLength = 200;
    tl.appendChild(titleIn);
    var bl = el('div', 'staxx-bugwin-label', KINDS[0].heading);
    bodyHeadEl = bl;
    editor = el('div', 'staxx-bugwin-editor');
    editor.contentEditable = 'true';
    editor.setAttribute('role', 'textbox');
    editor.setAttribute('aria-multiline', 'true');
    editor.setAttribute('aria-label', KINDS[0].heading);
    asEl = el('p', 'staxx-bugwin-as');
    formView.appendChild(tl);
    formView.appendChild(bl);
    formView.appendChild(editor);

    // Diagnostic details: drawn only once the server has answered.
    detailsBox = el('fieldset', 'staxx-bugwin-details');
    detailsBox.hidden = true;
    detailsBox.appendChild(el('legend', 'staxx-bugwin-legend', 'Include details that help fix this'));
    detailsList = el('div', 'staxx-bugwin-detaillist');
    detailsBox.appendChild(detailsList);
    var lock = el('p', 'staxx-bugwin-lock');
    lock.innerHTML = '<i class="fa fa-lock" aria-hidden="true"></i>';
    lock.appendChild(document.createTextNode('Passwords, keys, email addresses and home network addresses are ' +
      'replaced before anything is sent. These files are kept private: only the StaXX team can see them, ' +
      'never the public board.'));
    detailsBox.appendChild(lock);
    formView.appendChild(detailsBox);
    formView.appendChild(asEl);

    sentView = el('div', 'staxx-bugwin-view staxx-bugwin-view--done');
    sentView.hidden = true;
    thanksEl = el('p', 'staxx-bugwin-thanks');
    var line = el('p', 'staxx-bugwin-sentline');
    line.innerHTML = '<i class="fa fa-check-circle staxx-bugwin-tick" aria-hidden="true"></i>';
    line.appendChild(document.createTextNode('Your report was sent to the feedback board. You can view it here:'));
    sentUrlEl = el('a', 'staxx-bugwin-seelink');
    sentUrlEl.target = '_blank';
    sentUrlEl.rel = 'noopener';
    // Unraid's leaving-the-site question draws under this top-layer form, and the
    // board is StaXX's own, so open it directly as "Open the board" does.
    sentUrlEl.addEventListener('click', function (event) {
      var href = sentUrlEl.getAttribute('href');
      if (!href || href === '#') return;
      event.preventDefault();
      event.stopPropagation();
      window.open(sentUrlEl.href, '_blank', 'noopener');
    });
    var was =el('div', 'staxx-bugwin-label staxx-bugwin-wastitle', 'What was sent');
    var copy = el('div', 'staxx-bugwin-copy');
    copyTitle = el('p', 'staxx-bugwin-copy-title');
    copyBody = el('div', 'staxx-bugwin-copy-body');
    copyFoot = el('p', 'staxx-bugwin-copy-foot');
    copy.appendChild(copyTitle);
    copy.appendChild(copyBody);
    copy.appendChild(copyFoot);
    sentView.appendChild(thanksEl);
    sentView.appendChild(line);
    sentView.appendChild(sentUrlEl);
    attachNote = el('p', 'staxx-bugwin-attachnote');
    attachNote.hidden = true;
    sentView.appendChild(attachNote);
    sentView.appendChild(was);
    sentView.appendChild(copy);
    sentFilesBox = el('div', 'staxx-bugwin-sentfiles');
    sentFilesBox.hidden = true;
    var filesHead = el('p', 'staxx-bugwin-sentfiles-head');
    filesHead.innerHTML = '<i class="fa fa-lock staxx-bugwin-tick" aria-hidden="true"></i>';
    filesHead.appendChild(document.createTextNode('Private files sent with it'));
    sentFilesList = el('div', 'staxx-bugwin-sentfiles-list');
    sentFilesBox.appendChild(filesHead);
    sentFilesBox.appendChild(sentFilesList);
    sentFilesBox.appendChild(el('p', 'staxx-bugwin-sentfiles-foot',
      'Only you and the StaXX team can see these. They are deleted after 90 days.'));
    sentView.appendChild(sentFilesBox);

    body.appendChild(formView);
    body.appendChild(sentView);

    var foot = el('div', 'staxx-confirm-foot');
    formMsg = el('p', 'staxx-confirm-msg');
    formMsg.setAttribute('role', 'status');
    formMsg.setAttribute('aria-live', 'polite');
    rowBtns = el('div', 'staxx-buttons staxx-buttons--inline');
    cancelBtn = el('button', 'staxx-btn', 'Cancel');
    sendBtn = el('button', 'staxx-btn staxx-btn--primary', 'Send');
    cancelBtn.type = sendBtn.type = 'button';
    rowBtns.appendChild(cancelBtn);
    rowBtns.appendChild(sendBtn);
    askBox = el('div', 'staxx-buttons staxx-buttons--inline');
    askBox.hidden = true;
    askBox.appendChild(el('span', 'staxx-bugwin-ask', 'Throw this report away? What you typed will be lost.'));
    keepBtn = el('button', 'staxx-btn', 'Keep writing');
    discardBtn = el('button', 'staxx-btn staxx-btn--danger', 'Discard report');
    keepBtn.type = discardBtn.type = 'button';
    askBox.appendChild(keepBtn);
    askBox.appendChild(discardBtn);
    foot.appendChild(formMsg);
    foot.appendChild(rowBtns);
    foot.appendChild(askBox);

    formWin.appendChild(h.box);
    formWin.appendChild(body);
    formWin.appendChild(foot);
    (scaffold() || document.body).appendChild(formWin);

    enableDrag(h.box);
    minBtn.addEventListener('click', function () { setMinimised(!minimised); });
    cancelBtn.addEventListener('click', onCancel);
    keepBtn.addEventListener('click', function () { askDiscard(false); });
    discardBtn.addEventListener('click', discard);
    sendBtn.addEventListener('click', send);
    // The page under the form is meant to be used, and Escape must not close
    // a modal the person is only typing over. Keys stop here too: inside the
    // editor its own shortcuts would otherwise act on what is typed.
    formWin.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') event.preventDefault();
      event.stopPropagation();
    });

    editor.addEventListener('paste', onPaste);
    editor.addEventListener('drop', onDrop);
    editor.addEventListener('dragover', function (event) { event.preventDefault(); });
  }

  // opts.title pre-fills the title (from a failed Import's button).
  function openForm(opts) {
    if (!formWin) buildForm();
    asEl.textContent = connectedName ? 'Sending as ' + connectedName : '';
    if (formOpen) {
      if (minimised) setMinimised(false);
      if (opts && opts.title) titleIn.value = opts.title;
      return;
    }
    screenAtPress = screenName();
    if (!placed) {
      placed = true;
      var w = Math.min(96 * pxPerRem(), window.innerWidth * 0.94);
      moveTo(Math.max(0, window.innerWidth - w - 40), 150);
    }
    formOpen = true;
    var target = newestModal() || scaffold() || document.body;
    if (formWin.parentNode !== target) { hidePop(formWin); target.appendChild(formWin); }
    showPop(formWin);
    if (opts && opts.title) titleIn.value = opts.title;
    titleIn.focus();
    loadDetails();
  }

  // The corner button: connect first if need be, then the form.
  // opts ({title, importLog}) is optional; the corner button's click passes an
  // event, which carries neither. The import item is offered and ticked on
  // every report anyway, so importLog needs nothing further.
  function onBugClick(opts) {
    if (!opts || opts.type !== undefined || opts.target !== undefined) opts = null;
    if (formOpen) { openForm(opts); return; }
    call('feedback-status').then(function (r) {
      if (r.ok && r.connected) {
        connectedName = r.name || '';
        openForm(opts);
        refreshSettings();
      } else {
        openConnect(INTRO, r);
      }
    });
  }

  /* ------------------------------------------------------------- details - */

  // Each detail is a list of segments: plain text {t}, or a replacement
  // {tag, kind, orig}. That list is the whole state; the preview draws it and
  // Send uploads it as it stands.
  function itemText(it) {
    return it.segments.map(function (s) { return s.tag != null ? s.tag : s.t; }).join('');
  }

  // The editor sets #stack=<folder>%2F<name>; only when it is the newest modal.
  function currentStack() {
    var d = newestModal();
    if (!d || !d.matches('dialog.staxx-modal')) return '';
    try { return new URLSearchParams(location.hash.replace(/^#/, '')).get('stack') || ''; } catch (e) { return ''; }
  }

  function clearDetails() {
    details = null;
    detailsToken++;
    if (detailsBox) { detailsBox.hidden = true; detailsList.textContent = ''; }
    if (previewWin) hidePop(previewWin);
    refreshReview();
  }

  // A "maybe" value (a number that might be an address) still hidden in the file.
  function hasMaybe(it) {
    return it.segments.some(function (s) { return s.kind === 'maybe'; });
  }

  // Ticked, still holding a hidden maybe value, and not yet approved by the person.
  // Approval lives on the item object, which a details reload replaces, so it
  // lasts for one form only.
  function needsReview(it) { return it.on && hasMaybe(it) && !it.approved; }

  var REVIEW_MSG = 'Preview the files marked Check this file and approve them before sending.';

  function reviewBlocked() {
    return !!(details && kind.id === 'bug' && details.items.some(needsReview));
  }

  // Redraws every row's status and the Send block from the items as they stand.
  function refreshReview() {
    if (details) {
      details.items.forEach(function (it) {
        var st = it.statusEl;
        if (!st) return;
        st.textContent = '';
        st.className = '';
        if (it.approved && hasMaybe(it)) {
          st.className = 'staxx-bugwin-checked';
          st.innerHTML = '<i class="fa fa-check" aria-hidden="true"></i>';
          st.appendChild(document.createTextNode('Checked'));
        } else if (hasMaybe(it) && !it.approved) {
          st.className = 'staxx-bugwin-review';
          st.textContent = 'Check this file';
        }
      });
    }
    if (!sendBtn) return;
    var blocked = reviewBlocked();
    sendBtn.disabled = busy || blocked;
    if (blocked) setMsg(REVIEW_MSG);
    else if (formMsg.textContent === REVIEW_MSG) setMsg('');
  }

  function renderDetails() {
    detailsList.textContent = '';
    syncDetailsBox();
    if (!details || !details.items.length) { refreshReview(); return; }
    details.items.forEach(function (it) {
      var row = el('div', 'staxx-bugwin-detailrow');
      var lab = el('label', 'staxx-bugwin-check');
      var box = el('input');
      box.type = 'checkbox';
      box.checked = it.on;
      box.addEventListener('change', function () { it.on = box.checked; refreshReview(); });
      lab.appendChild(box);
      lab.appendChild(el('span', '', it.label));
      it.statusEl = el('span');
      lab.appendChild(it.statusEl);
      var view = el('button', 'staxx-bugwin-link', 'Preview');
      view.type = 'button';
      view.addEventListener('click', function () { openPreview(it); });
      row.appendChild(lab);
      row.appendChild(view);
      detailsList.appendChild(row);
    });
    refreshReview();
  }

  function loadDetails() {
    var token = ++detailsToken;
    details = null;
    detailsBox.hidden = true;
    refreshReview();
    call('feedback-details', {
      stack: currentStack(),
      errors: pageErrors.join('\n'),
      browser: navigator.userAgent
    }).then(function (r) {
      if (token !== detailsToken || !r.ok || !r.items) return;   // a failure only hides the list
      r.items.forEach(function (it) { it.on = true; });
      details = { items: r.items };
      renderDetails();
    });
  }

  /* ------------------------------------------------------------- preview - */

  // Compose's own words; hiding one would make the file unreadable.
  var KEYWORDS = ('services version name include networks volumes configs secrets image container_name ' +
    'environment env_file ports expose restart depends_on healthcheck command entrypoint build labels ' +
    'deploy x-unraid user working_dir hostname domainname devices cap_add cap_drop privileged network_mode ' +
    'dns dns_search extra_hosts logging pid ipc tmpfs sysctls ulimits security_opt stop_signal ' +
    'stop_grace_period tty stdin_open init platform pull_policy profiles runtime shm_size mem_limit ' +
    'cpus cpu_shares links external_links read_only group_add userns_mode cgroup_parent scale ' +
    'attach develop driver driver_opts ipam external file content test interval timeout retries ' +
    'start_period condition source target type bind volume tmpfs_size aliases ipv4_address ' +
    'ipv6_address gateway subnet ip_range limits reservations resources replicas placement ' +
    'update_config rollback_config restart_policy mode protocol published host_ip').split(' ');
  var OWN_NAMES = ['services', 'networks', 'volumes', 'configs', 'secrets'];
  var HIDE_NEED = 'Select the text you want to hide first, then press Hide this.';

  var pApproveBtn;
  var pWin, pTitle, pText, pHideBtn, pMsg, pRow, pAsk, pAskText, pAskA, pAskB;
  var pMsgTimer = 0;
  var pAskAction = null;

  function setPMsg(text, icon, ms) {
    clearTimeout(pMsgTimer);
    pMsg.textContent = '';
    if (text && icon) pMsg.innerHTML = '<i class="fa ' + icon + ' staxx-bugwin-pmsgicon" aria-hidden="true"></i>';
    pMsg.appendChild(document.createTextNode(text || ''));
    if (text && ms) pMsgTimer = setTimeout(function () { setPMsg(''); }, ms);
  }

  function showAsk(text, labelA, labelB, onB) {
    setPMsg('');
    pAskText.textContent = text;
    pAskA.textContent = labelA;
    pAskB.textContent = labelB;
    pAskAction = onB;
    pAsk.hidden = false;
    pRow.hidden = true;
    pAskA.focus();
  }

  function endAsk() {
    pAskAction = null;
    pAsk.hidden = true;
    pRow.hidden = false;
  }

  function shownItem() { return pText && pText.staxxItem; }

  function renderPreview(fresh) {
    var keep = pText.scrollTop;
    pText.textContent = '';
    shownItem().segments.forEach(function (s) {
      if (s.tag == null) { pText.appendChild(document.createTextNode(s.t)); return; }
      var m = el('span', 'staxx-bugwin-tag' + (s.tag === fresh ? ' staxx-bugwin-tag--new' : ''), s.tag);
      m.setAttribute('data-tag', s.tag);
      m.title = 'Click to send this as written';
      pText.appendChild(m);
    });
    pText.scrollTop = keep;
    // Hides and unhides both end here, so this keeps the Approve button and the
    // form's rows and Send state in step with the text.
    pApproveBtn.hidden = !hasMaybe(shownItem());
    refreshReview();
  }

  // Every place the text sits as plain text, in every item.
  function occurrences(s) {
    var found = [];
    details.items.forEach(function (it) {
      var flat = itemText(it);
      var at = 0;
      it.segments.forEach(function (seg) {
        if (seg.tag == null) {
          var i = 0, j;
          while ((j = seg.t.indexOf(s, i)) >= 0) {
            found.push({ item: it, flat: flat, start: at + j, end: at + j + s.length });
            i = j + s.length;
          }
        }
        at += (seg.tag != null ? seg.tag : seg.t).length;
      });
    });
    return found;
  }

  function indentOf(line) { return /^\s*/.exec(line)[0].length; }

  // The key one level up from a line, or ''.
  function parentKey(lines, n, indent, dashed) {
    for (var i = n - 1; i >= 0; i--) {
      var ln = lines[i];
      if (!ln.trim() || /^\s*#/.test(ln)) continue;
      var ind = indentOf(ln);
      if (ind < indent || (dashed && ind === indent)) {
        var m = /^\s*(?:-\s+)?([^:\s][^:]*):/.exec(ln);
        return { name: m ? m[1].trim() : '', indent: ind };
      }
    }
    return { name: '', indent: -1 };
  }

  // 'refuse', 'ask' or '' for hiding this text.
  function judge(s) {
    var verdict = '';
    var keyword = KEYWORDS.indexOf(s) >= 0;
    occurrences(s).forEach(function (o) {
      if (verdict === 'refuse') return;
      var flat = o.flat;
      var ls = o.start ? flat.lastIndexOf('\n', o.start - 1) + 1 : 0;
      var le = flat.indexOf('\n', o.end);
      if (le < 0) le = flat.length;
      var before = flat.slice(ls, o.start);
      var after = flat.slice(o.end, le);
      var line = flat.slice(ls, le);

      if (/^\s*(-\s+)?$/.test(before) && /^(=|:(\s|$))/.test(after)) {
        var dashed = /-\s+$/.test(before);
        if (after.charAt(0) === '=') { verdict = 'refuse'; return; }   // KEY=value: a variable name
        var lines = flat.slice(0, ls).split('\n');
        var par = parentKey(lines, lines.length, indentOf(before), dashed);
        if (par.name === 'environment') { verdict = 'refuse'; return; }
        var own = par.indent === 0 && OWN_NAMES.indexOf(par.name) >= 0;
        if (!own && keyword) { verdict = 'refuse'; return; }
      }
      var img = /^\s*image:\s*/.exec(line);
      if (img && o.start - ls >= img[0].length) verdict = 'ask';
      if (o.item.file === 'last-start-or-update.log' && /Error/.test(line)) verdict = 'ask';
      if (o.item.file === 'versions.txt' && /\d+\.\d+/.test(s)) verdict = 'ask';
    });
    return verdict;
  }

  function nextHiddenNumber() {
    var top = 0;
    details.items.forEach(function (it) {
      it.segments.forEach(function (s) {
        var m = s.kind === 'hidden' && /^<hidden (\d+)>$/.exec(s.tag);
        if (m) top = Math.max(top, Number(m[1]));
      });
    });
    return top + 1;
  }

  function hideEverywhere(s) {
    var tag = null;
    details.items.forEach(function (it) {
      it.segments.forEach(function (g) { if (g.kind === 'hidden' && g.orig === s) tag = g.tag; });
    });
    if (!tag) tag = '<hidden ' + nextHiddenNumber() + '>';
    details.items.forEach(function (it) {
      var out = [];
      it.segments.forEach(function (g) {
        if (g.tag != null) { out.push(g); return; }
        var i = 0, j;
        while ((j = g.t.indexOf(s, i)) >= 0) {
          if (j > i) out.push({ t: g.t.slice(i, j) });
          out.push({ tag: tag, kind: 'hidden', orig: s });
          i = j + s.length;
        }
        if (i < g.t.length) out.push({ t: g.t.slice(i) });
      });
      it.segments = out;
    });
    renderPreview(tag);
  }

  // Every place sharing the tag goes back to plain text, in every item.
  function showEverywhere(tag) {
    details.items.forEach(function (it) {
      var out = [];
      it.segments.forEach(function (g) {
        var seg = g.tag === tag ? { t: g.orig } : g;
        var last = out[out.length - 1];
        if (seg.tag == null && last && last.tag == null) last.t += seg.t;
        else out.push(seg.tag == null ? { t: seg.t } : seg);
      });
      it.segments = out;
    });
    renderPreview();
  }

  function selectedText() {
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount || sel.isCollapsed) return '';
    var range = sel.getRangeAt(0);
    if (!pText.contains(range.commonAncestorContainer)) return '';
    var tags = pText.querySelectorAll('.staxx-bugwin-tag');
    for (var i = 0; i < tags.length; i++) {
      if (range.intersectsNode(tags[i])) return null;   // part of it is already hidden
    }
    return sel.toString().trim();
  }

  function onHide() {
    endAsk();
    var s = selectedText();
    if (s === null) { setPMsg('Select only text that is not already hidden.', '', 4000); return; }
    if (!s) { setPMsg(HIDE_NEED, '', 4000); return; }
    var verdict = judge(s);
    if (verdict === 'refuse') {
      setPMsg('“' + s + '” is a setting name, not a value. It holds nothing private, and the file cannot be ' +
        'read without it, so it stays.', 'fa-ban', 5000);
      return;
    }
    window.getSelection().removeAllRanges();
    if (verdict === 'ask') {
      showAsk('Hiding this makes the problem much harder to find. Hide it anyway?', 'Keep it', 'Hide anyway',
        function () { hideEverywhere(s); });
      return;
    }
    setPMsg('');
    hideEverywhere(s);
  }

  var KIND_WORDS = {
    secret: 'a password or key',
    email: 'an email address',
    home: 'an address on your home network',
    address: 'an address',
    mac: 'a hardware address',
    host: 'the name of a computer on your home network',
    maybe: 'a number that may be a network address or a version number'
  };

  function onTagClick(event) {
    var m = event.target.closest && event.target.closest('.staxx-bugwin-tag');
    if (!m || !details) return;
    var tag = m.getAttribute('data-tag');
    var kind = '';
    details.items.forEach(function (it) {
      it.segments.forEach(function (g) { if (g.tag === tag) kind = g.kind; });
    });
    endAsk();
    setPMsg('');
    if (kind === 'hidden') { showEverywhere(tag); return; }
    showAsk('This looks like ' + (KIND_WORDS[kind] || 'something private') + '. Send it as it is?',
      'Keep it hidden', 'Send it as it is', function () { showEverywhere(tag); });
  }

  function buildPreview() {
    var h = head('staxx-bugwin-preview-title', '');
    pTitle = h.label;
    pWin = popoverWindow('staxx-bugwin-preview', 'auto', 'staxx-bugwin-preview-title');
    previewWin = pWin;
    var body = el('div', 'staxx-confirm-body');

    var bar = el('div', 'staxx-bugwin-pbar');
    var words = el('div', 'staxx-bugwin-pwords');
    var l1 = el('p', 'staxx-bugwin-pline');
    l1.appendChild(document.createTextNode('This is exactly what will be sent. To hide something, select it and press '));
    l1.appendChild(el('b', '', 'Hide this'));
    l1.appendChild(document.createTextNode('.'));
    var l2 = el('p', 'staxx-bugwin-pline');
    l2.appendChild(document.createTextNode('To show a '));
    l2.appendChild(el('span', 'staxx-bugwin-tag staxx-bugwin-tag--key', 'highlighted'));
    l2.appendChild(document.createTextNode(' part again, click it.'));
    words.appendChild(l1);
    words.appendChild(l2);
    pHideBtn = el('button', 'staxx-bugwin-hide');
    pHideBtn.type = 'button';
    pHideBtn.innerHTML = '<i class="fa fa-eye-slash" aria-hidden="true"></i>';
    pHideBtn.appendChild(document.createTextNode('Hide this'));
    bar.appendChild(words);
    bar.appendChild(pHideBtn);

    pText = el('pre', 'staxx-bugwin-ptext');
    body.appendChild(bar);
    body.appendChild(pText);

    var foot = el('div', 'staxx-confirm-foot');
    pMsg = el('p', 'staxx-confirm-msg');
    pMsg.setAttribute('role', 'status');
    pMsg.setAttribute('aria-live', 'polite');
    pRow = el('div', 'staxx-buttons staxx-buttons--inline');
    var close = el('button', 'staxx-btn', 'Close');
    close.type = 'button';
    pRow.appendChild(close);
    pApproveBtn = el('button', 'staxx-btn staxx-btn--primary', 'Approve');
    pApproveBtn.type = 'button';
    pApproveBtn.hidden = true;
    pRow.appendChild(pApproveBtn);
    pAsk = el('div', 'staxx-buttons staxx-buttons--inline');
    pAsk.hidden = true;
    pAskText = el('span', 'staxx-bugwin-ask');
    pAskA = el('button', 'staxx-btn');
    pAskB = el('button', 'staxx-btn staxx-btn--danger');
    pAskA.type = pAskB.type = 'button';
    pAsk.appendChild(pAskText);
    pAsk.appendChild(pAskA);
    pAsk.appendChild(pAskB);
    foot.appendChild(pMsg);
    foot.appendChild(pRow);
    foot.appendChild(pAsk);

    pWin.appendChild(h.box);
    pWin.appendChild(body);
    pWin.appendChild(foot);
    (scaffold() || document.body).appendChild(pWin);

    // Pressing the button must not clear the text selected in the block.
    pHideBtn.addEventListener('mousedown', function (event) { event.preventDefault(); });
    pHideBtn.addEventListener('click', onHide);
    pText.addEventListener('click', onTagClick);
    close.addEventListener('click', function () { hidePop(pWin); });
    pApproveBtn.addEventListener('click', function () {
      var it = shownItem();
      if (it) it.approved = true;
      previewOpen = false; // the toggle event is async; refreshReview's DOM change would re-show it first
      hidePop(pWin);
      refreshReview();
    });
    pAskA.addEventListener('click', endAsk);
    pAskB.addEventListener('click', function () {
      var go = pAskAction;
      endAsk();
      if (go) go();
    });
    pWin.addEventListener('keydown', function (event) { event.stopPropagation(); });
    // A click outside or Escape closes it; the form stays as it was.
    pWin.addEventListener('toggle', function (event) {
      if (event.newState !== 'closed') return;
      previewOpen = false;
      endAsk();
      setPMsg('');
    });
  }

  function openPreview(it) {
    if (!pWin) buildPreview();
    pText.staxxItem = it;
    pTitle.textContent = it.label;
    endAsk();
    setPMsg('');
    renderPreview();
    var target = newestModal() || scaffold() || document.body;
    if (pWin.parentNode !== target) { hidePop(pWin); target.appendChild(pWin); }
    previewOpen = true;
    showPop(pWin);
    pText.scrollTop = 0;
  }

  /* ------------------------------------------------------------ pictures - */

  function insertAtCaret(node) {
    var sel = window.getSelection();
    var range = sel && sel.rangeCount ? sel.getRangeAt(0) : null;
    if (range && editor.contains(range.commonAncestorContainer)) {
      range.deleteContents();
      range.insertNode(node);
      range.setStartAfter(node);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
    } else {
      editor.appendChild(node);
    }
  }

  function decodedBytes(dataUrl) {
    var b64 = dataUrl.slice(dataUrl.indexOf(',') + 1);
    return Math.floor(b64.length * 3 / 4) - (b64.slice(-2) === '==' ? 2 : b64.slice(-1) === '=' ? 1 : 0);
  }

  function redrawAsJpeg(dataUrl) {
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () {
        var c = document.createElement('canvas');
        c.width = img.naturalWidth;
        c.height = img.naturalHeight;
        var g = c.getContext('2d');
        g.fillStyle = '#fff';   // JPEG has no transparency
        g.fillRect(0, 0, c.width, c.height);
        g.drawImage(img, 0, 0);
        resolve(c.toDataURL('image/jpeg', 0.85));
      };
      img.onerror = function () { resolve(dataUrl); };
      img.src = dataUrl;
    });
  }

  function addPicture(file) {
    var reader = new FileReader();
    reader.onload = function () {
      var url = String(reader.result);
      var okType = /^data:image\/(png|jpeg|gif|webp);/.test(url);
      var step = (decodedBytes(url) > MAX_BYTES || !okType) ? redrawAsJpeg(url) : Promise.resolve(url);
      step.then(function (final) {
        if (decodedBytes(final) > MAX_BYTES) {
          setMsg('That picture is over 5 MB even after shrinking it. Take a smaller screenshot and paste that instead.');
          return;
        }
        setMsg('');
        var img = el('img', 'staxx-bugwin-shot');
        // On a display scaled above 100% a screenshot holds more pixels than the area it showed, so drawn
        // pixel-for-pixel it came out larger than what was copied. The uploaded file stays full resolution.
        img.onload = function () {
          var ratio = window.devicePixelRatio || 1;
          if (ratio > 1) img.style.width = Math.round(img.naturalWidth / ratio) + 'px';
        };
        img.src = final;
        img.alt = 'screenshot';
        insertAtCaret(img);
      });
    };
    reader.readAsDataURL(file);
  }

  function firstImage(list) {
    for (var i = 0; list && i < list.length; i++) {
      if (/^image\//.test(list[i].type)) return list[i];
    }
    return null;
  }

  function onPaste(event) {
    var cd = event.clipboardData;
    if (!cd) return;
    event.preventDefault();
    var file = firstImage(cd.files);
    if (file) { addPicture(file); return; }
    var text = cd.getData('text/plain');
    if (text) document.execCommand('insertText', false, text);
  }

  // A dropped image is treated like a pasted one; anything else is ignored.
  function onDrop(event) {
    event.preventDefault();
    var file = event.dataTransfer && firstImage(event.dataTransfer.files);
    if (file) addPicture(file);
  }

  /* ---------------------------------------------------------------- send - */

  // The box's contents as text parts and {img} parts, in order.
  function collect(root, parts) {
    function lineBreak() {
      var last = parts[parts.length - 1];
      if (parts.length && last !== '\n') parts.push('\n');
    }
    Array.prototype.forEach.call(root.childNodes, function (n) {
      if (n.nodeType === 3) {
        parts.push(n.nodeValue.replace(/ /g, ' '));
      } else if (n.nodeType === 1) {
        var tag = n.tagName;
        if (tag === 'BR') parts.push('\n');
        else if (tag === 'IMG') { lineBreak(); parts.push({ img: n }); parts.push('\n'); }
        else if (tag === 'DIV' || tag === 'P') { lineBreak(); collect(n, parts); lineBreak(); }
        else collect(n, parts);
      }
    });
  }

  function setBusy(on) {
    busy = on;
    sendBtn.disabled = on || reviewBlocked();
    cancelBtn.disabled = on;
    titleIn.disabled = on;
    editor.contentEditable = on ? 'false' : 'true';
    Array.prototype.forEach.call(detailsList.querySelectorAll('input'), function (b) { b.disabled = on; });
  }

  function uploadPicture(img) {
    var m = /^data:([^;]+);base64,(.*)$/.exec(img.src);
    if (!m) return Promise.resolve({ ok: false, error: 'One of the pictures could not be read.' });
    return call('feedback-upload', { data: m[2], type: m[1] });
  }

  function fileSize(n) {
    if (n < 1024) return n + ' bytes';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  // The sent view: a copy of the report as it was written, and the footer the
  // server added to the card.
  function showSent(title, parts, r, attach) {
    var sentFiles = (attach && attach.sent) || [];
    sentFilesBox.hidden = !sentFiles.length;
    sentFilesList.textContent = '';
    sentFiles.forEach(function (f) {
      var row = el('p', 'staxx-bugwin-sentfile');
      row.innerHTML = '<i class="fa fa-file-text-o" aria-hidden="true"></i>';
      row.appendChild(el('span', 'staxx-bugwin-sentfile-name', f.name));
      row.appendChild(el('span', 'staxx-bugwin-sentfile-size', fileSize(f.size)));
      sentFilesList.appendChild(row);
    });
    attachNote.hidden = !(attach && attach.failed);
    if (attach && attach.failed) {
      attachNote.textContent = 'The report was sent, but ' + attach.failed + ' of the files could not be attached: ' +
        attach.first;
    }
    var first = (connectedName.trim().split(/\s+/)[0]) || '';
    thanksEl.textContent = first ? 'Thanks, ' + first + ', for helping make StaXX even better!'
                                 : 'Thanks for helping make StaXX even better!';
    var url = r.url || '';
    sentUrlEl.href = url || '#';
    sentUrlEl.textContent = url;
    sentUrlEl.insertAdjacentHTML('beforeend', '<i class="fa fa-external-link" aria-hidden="true"></i>');
    copyTitle.textContent = title;
    copyBody.textContent = '';
    parts.forEach(function (p) {
      copyBody.appendChild(typeof p === 'string' ? document.createTextNode(p) : p.img.cloneNode(true));
    });
    copyFoot.textContent = r.footer || '';
    formStep('sent');
  }

  // One file at a time, after the card exists. A failure never undoes the card;
  // after a reconnect request the rest count as failed, since they cannot go.
  function attachAll(id, picked) {
    var res = { failed: 0, first: '', reconnect: false, sent: [] };
    var chain = Promise.resolve();
    picked.forEach(function (p) {
      chain = chain.then(function () {
        if (res.reconnect) { res.failed++; return null; }
        return call('feedback-attach', { post: id, file: p.file, text: p.text }).then(function (r) {
          if (r.ok) { res.sent.push({ name: r.filename, size: r.size }); return; }
          res.failed++;
          if (!res.first) res.first = r.error || 'The file could not be attached.';
          if (r.reconnect) res.reconnect = true;
        });
      });
    });
    return chain.then(function () { return res; });
  }

  function send() {
    if (reviewBlocked()) { setMsg(REVIEW_MSG); return; }   // the button is disabled too; this is the backstop
    var title = titleIn.value.trim();
    if (!title) { setMsg('Give the report a title first.'); titleIn.focus(); return; }

    var parts = [];
    var sendKind = kind.id;
    collect(editor, parts);
    setBusy(true);
    setMsg('Sending…');

    // Sequential: each picture becomes its attachment line, then the card goes.
    var out = [];
    var chain = Promise.resolve({ ok: true });
    parts.forEach(function (p) {
      chain = chain.then(function (prev) {
        if (!prev.ok) return prev;
        if (typeof p === 'string') { out.push(p); return prev; }
        return uploadPicture(p.img).then(function (r) {
          if (r.ok) out.push('![screenshot](attachment:' + r.key + ')');
          return r;
        });
      });
    });

    // What each ticked detail says right now, taken before anything is sent.
    // Only a bug report carries files, even if boxes were ticked on the way.
    var picked = (details && sendKind === 'bug' ?details.items : []).filter(function (it) { return it.on; })
      .map(function (it) { return { file: it.file, text: itemText(it) }; });

    chain.then(function (r) {
      if (!r.ok) return r;
      var content = out.join('').replace(/\n{3,}/g, '\n\n').trim();
      return call('feedback-send', { title: title, content: content || title, screen: screenAtPress, kind: sendKind });
    }).then(function (r) {
      if (!r.ok) return [r, null];
      // The card stands whatever happens to the files.
      return attachAll(r.id, picked).then(function (a) { return [r, a]; });
    }).then(function (pair) {
      var r = pair[0], attach = pair[1];
      setBusy(false);
      if (r.ok) {
        showSent(title, parts, r, attach);
        titleIn.value = '';
        editor.innerHTML = '';
        clearDetails();
        setMsg('');
        if (attach.reconnect) {
          openConnect(RECONNECT);
          if (attach.first) setConnMsg(attach.first);
          refreshSettings();
        }
        return;
      }
      if (r.reconnect) {
        // The form and what was typed stay; the connect window opens over it.
        setMsg('');
        openConnect(RECONNECT);
        if (r.error) setConnMsg(r.error);
        refreshSettings();
        return;
      }
      setMsg(r.error || 'The report could not be sent.');
    });
  }

  /* ----------------------------------------------- Settings → Integrations - */

  function settingsBox() { return document.getElementById(SETTINGS_ID); }

  function refreshSettings() {
    var box = settingsBox();
    if (!box) return;
    call('feedback-status').then(function (r) {
      box = settingsBox();
      if (!box) return;
      if (r.ok && r.connected) {
        box.innerHTML = '<span class="staxx-feedback-settings-text">Feedback board: connected as ' +
          esc(r.name || 'your account') + '</span> ' +
          '<button type="button" class="staxx-btn" data-fb="disconnect">Disconnect</button>';
      } else {
        box.innerHTML = '<span class="staxx-feedback-settings-text">Feedback board: not connected</span> ' +
          '<button type="button" class="staxx-btn" data-fb="connect">Connect</button>';
      }
    });
  }

  // The Integrations pane is drawn by script each time Settings opens, so the
  // line is added to it then, the way leftovers.js adds its own section.
  function loadSettingsLine() {
    var pane = document.querySelector('[data-pane="registries"]');
    if (!pane || settingsBox()) return;
    var div = el('div', 'staxx-field staxx-feedback-settings');
    div.id = SETTINGS_ID;
    pane.appendChild(div);
    refreshSettings();
  }

  document.addEventListener('click', function (event) {
    var t = event.target;
    if (!t || !t.closest) return;
    var b = t.closest('#' + SETTINGS_ID + ' [data-fb]');
    if (b) {
      if (b.getAttribute('data-fb') === 'connect') openConnect(INTRO);
      else {
        b.disabled = true;
        call('feedback-disconnect').then(refreshSettings);
      }
      return;
    }
    if (t.closest('[data-tab="registries"]')) refreshSettings();
  });

  /* ---------------------------------------------------------------- start - */

  function start() {
    var root = scaffold();
    if (!root) return;
    bugBtn.addEventListener('click', onBugClick);
    root.appendChild(host);
    showPop(host);

    if (typeof MutationObserver !== 'undefined') {
      new MutationObserver(function (records) {
        records.forEach(function (rec) {
          if (rec.type === 'attributes' && rec.target.tagName === 'DIALOG') trackDialog(rec.target);
        });
        place();
      }).observe(document.documentElement,
        { subtree: true, attributes: true, attributeFilter: ['open'], childList: true });

      var body = document.getElementById('staxx-settings-body');
      if (body) new MutationObserver(loadSettingsLine).observe(body, { childList: true });
    }
    loadSettingsLine();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();

  window.staxxFeedback = { open: onBugClick };
})();
