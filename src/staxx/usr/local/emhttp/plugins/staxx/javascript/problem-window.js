/* StaXX — the problem window.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_212: the floating window that explains what Docker Compose refused in
 * a file, in plain words, and offers to fix it. The same window carries the
 * form's circled "i" help (one section, "What this does").
 *
 * It is built like the report window in feedback.js: a popover that dims
 * nothing, dragged by its title bar, collapsible to the title bar. A popover
 * outside the open editor dialog is inert (the modal makes everything outside
 * itself unclickable), so it is moved into the newest open modal before it is
 * shown, exactly as feedback.js does.
 *
 * This file only draws. What a problem is, where its line is, what a fix does
 * to the editor and how the red line mark looks all belong to stacks.js, which
 * hands them in as callbacks:
 *
 *   StaxxProblems.show(problems, {
 *     index, onStep(i), onShowMe(p, i), onClose(),
 *     fixPreview(p, i) -> null | { label, oldLine, newLine, note },
 *     applyFix(p, i)   -> null | { message },      // null: refused, nothing changed
 *     nextProblem()    -> void                      // asked for after a fix
 *   });
 *   StaxxProblems.refresh(problems)   // a newer list arrived while open
 *   StaxxProblems.hide();  StaxxProblems.isOpen();
 *
 * A problem is { raw, entry: null | {title, means, fix, docs, autofix}, report }
 * or, for a help mark, { kind: 'help', title, description, docs }.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
(function () {
  'use strict';

  var DOCS_INDEX = 'https://docs.docker.com/reference/compose-file/';
  var UNKNOWN_MEANS = 'StaXX does not have a plain explanation for this message yet. Docker ' +
    'Compose’s own words are above, and the line it points at is marked in the file.';
  var UNKNOWN_FIX = 'Compare the marked line with Docker’s page on this setting.';
  var REPORT_SENT = 'StaXX has sent this message, without your file or any names in it, so an ' +
    'explanation can be written. You can turn this off in Settings, Integrations.';
  var REPORT_WAITING = 'StaXX will send this message, without your file or any names in it, when ' +
    'it can. You can turn this off in Settings, Integrations.';

  var hasPopover = typeof HTMLElement !== 'undefined' &&
    typeof HTMLElement.prototype.showPopover === 'function';

  var win = null, titleEl, iconEl, navBox, prevBtn, nextBtn, countEl, minBtn, body, foot;
  var list = [], idx = 0, opts = {}, view = 'problem', doneMessage = '';
  var open = false, minimised = false, placed = false;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function showPop(n) { if (hasPopover) { try { n.showPopover(); } catch (e) { /* already shown */ } } }
  function hidePop(n) { if (hasPopover) { try { n.hidePopover(); } catch (e) { /* already hidden */ } } }

  function pxPerRem() {
    return parseFloat(getComputedStyle(document.documentElement).fontSize) || 10;
  }

  // The newest open modal is the only place a popover can be reached from.
  function target() {
    var found = null;
    Array.prototype.forEach.call(document.querySelectorAll('dialog[open]'), function (d) {
      var modal = true;
      try { modal = d.matches(':modal'); } catch (e) { /* assume modal */ }
      if (modal) found = d;
    });
    return found || document.querySelector('.staxx-scaffold') || document.body;
  }

  function moveTo(left, top) {
    win.style.left = left + 'px';
    win.style.top = top + 'px';
  }

  function setMinimised(on) {
    if (on === minimised) return;
    minimised = on;
    win.classList.toggle('staxx-bugwin--min', on);
    minBtn.firstChild.className = on ? 'fa fa-chevron-down' : 'fa fa-chevron-up';
    minBtn.title = on ? 'Restore' : 'Collapse';
    minBtn.setAttribute('aria-label', minBtn.title);
  }

  function close() {
    open = false;
    hidePop(win);
    if (opts.onClose) opts.onClose();
  }

  // Drag by the title bar, never starting on a button; some of the window
  // always stays on screen so it can be dragged back.
  function enableDrag(bar) {
    var dx = 0, dy = 0, active = false;
    bar.addEventListener('pointerdown', function (event) {
      if (event.button !== 0 || event.target.closest('button')) return;
      var r = win.getBoundingClientRect();
      dx = event.clientX - r.left;
      dy = event.clientY - r.top;
      active = true;
      win.classList.add('staxx-bugwin--dragging');
      bar.setPointerCapture(event.pointerId);
    });
    bar.addEventListener('pointermove', function (event) {
      if (!active) return;
      var w = win.getBoundingClientRect().width;
      var left = Math.min(Math.max(event.clientX - dx, 0), window.innerWidth - 80);
      var top = Math.min(Math.max(event.clientY - dy, 0), window.innerHeight - 40);
      if (left + w < 80) left = 80 - w;
      moveTo(left, top);
    });
    function stop(event) {
      if (!active) return;
      active = false;
      win.classList.remove('staxx-bugwin--dragging');
      try { bar.releasePointerCapture(event.pointerId); } catch (e) { /* already released */ }
    }
    bar.addEventListener('pointerup', stop);
    bar.addEventListener('pointercancel', stop);
  }

  function iconBtn(cls, icon, title) {
    var b = el('button', 'staxx-bugwin-min ' + cls);
    b.type = 'button';
    b.title = title;
    b.setAttribute('aria-label', title);
    b.innerHTML = '<i class="fa ' + icon + '" aria-hidden="true"></i>';
    return b;
  }

  function build() {
    win = el('div', 'staxx-confirm unapi staxx-bugwin staxx-bugwin-float staxx-probwin');
    win.setAttribute('role', 'dialog');
    win.setAttribute('aria-labelledby', 'staxx-probwin-title');
    if (hasPopover) win.setAttribute('popover', 'manual');

    var head = el('div', 'staxx-confirm-head');
    var t = el('h3', 'staxx-confirm-title');
    t.id = 'staxx-probwin-title';
    iconEl = el('i', 'fa fa-wrench staxx-probwin-icon');
    iconEl.setAttribute('aria-hidden', 'true');
    titleEl = el('span');
    t.appendChild(iconEl);
    t.appendChild(titleEl);
    head.appendChild(t);

    var ctl = el('div', 'staxx-probwin-ctl');
    navBox = el('div', 'staxx-probwin-nav');
    prevBtn = el('button', 'staxx-probwin-step');
    prevBtn.type = 'button';
    prevBtn.title = 'Previous problem';
    prevBtn.setAttribute('aria-label', 'Previous problem');
    prevBtn.innerHTML = '<i class="fa fa-chevron-left" aria-hidden="true"></i>';
    nextBtn = el('button', 'staxx-probwin-step');
    nextBtn.type = 'button';
    nextBtn.title = 'Next problem';
    nextBtn.setAttribute('aria-label', 'Next problem');
    nextBtn.innerHTML = '<i class="fa fa-chevron-right" aria-hidden="true"></i>';
    countEl = el('span', 'staxx-probwin-count');
    navBox.appendChild(prevBtn);
    navBox.appendChild(countEl);
    navBox.appendChild(nextBtn);
    minBtn = iconBtn('staxx-probwin-btn', 'fa-chevron-up', 'Collapse');
    var closeBtn = iconBtn('staxx-probwin-btn', 'fa-times', 'Close');
    ctl.appendChild(navBox);
    ctl.appendChild(minBtn);
    ctl.appendChild(closeBtn);
    head.appendChild(ctl);

    body = el('div', 'staxx-confirm-body');
    foot = el('div', 'staxx-confirm-foot');
    win.appendChild(head);
    win.appendChild(body);
    win.appendChild(foot);

    prevBtn.addEventListener('click', function () { step(idx - 1); });
    nextBtn.addEventListener('click', function () { step(idx + 1); });
    minBtn.addEventListener('click', function () { setMinimised(!minimised); });
    closeBtn.addEventListener('click', close);
    enableDrag(head);
  }

  function step(i) {
    if (i < 0 || i >= list.length) return;
    idx = i;
    view = 'problem';
    render();
    if (opts.onStep) opts.onStep(i, list[i]);
  }

  /* ----------------------------------------------------------- content - */

  function section(heading, node) {
    body.appendChild(el('h4', 'staxx-probwin-heading', heading));
    body.appendChild(node);
  }

  // Plain text, except that the button's own name is bold, as it is on screen.
  function textWithButtonName(text) {
    var p = el('p', 'staxx-probwin-text');
    var at = text.indexOf('Fix it for me');
    if (at < 0) { p.textContent = text; return p; }
    p.appendChild(document.createTextNode(text.slice(0, at)));
    p.appendChild(el('strong', '', 'Fix it for me'));
    p.appendChild(document.createTextNode(text.slice(at + 13)));
    return p;
  }

  function docsLink(url, text) {
    var a = el('a', 'staxx-probwin-docs', text);
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    return a;
  }

  function button(label, cls, fn) {
    var b = el('button', 'staxx-btn ' + (cls || ''), label);
    b.type = 'button';
    b.addEventListener('click', fn);
    return b;
  }

  function footRow(linkNode, buttons) {
    foot.textContent = '';
    foot.appendChild(linkNode || el('span'));
    var row = el('div', 'staxx-buttons staxx-buttons--inline');
    buttons.forEach(function (b) { row.appendChild(b); });
    foot.appendChild(row);
  }

  function renderProblem(p) {
    if (p.kind === 'help') {
      section('What this does', el('p', 'staxx-probwin-text', p.description || ''));
      footRow(p.docs ? docsLink(p.docs, 'Docker’s page on this setting') : null, []);
      return;
    }
    var entry = p.entry;
    var raw = el('pre', 'staxx-probwin-raw', p.raw || '');
    section('Docker Compose said', raw);
    section('What this means', el('p', 'staxx-probwin-text', entry ? entry.means : UNKNOWN_MEANS));
    section('How to fix it', textWithButtonName(entry ? entry.fix : UNKNOWN_FIX));
    if (!entry && (p.report === 'sent' || p.report === 'waiting')) {
      body.appendChild(el('p', 'staxx-probwin-report', p.report === 'sent' ? REPORT_SENT : REPORT_WAITING));
    }

    var buttons = [button('Show me', '', function () { if (opts.onShowMe) opts.onShowMe(p, idx); })];
    if (entry && entry.autofix && opts.canFix && opts.canFix(p)) {
      buttons.push(button('Fix it for me', '', function () {
        var prev = opts.fixPreview ? opts.fixPreview(p, idx) : null;
        if (!prev) return;
        view = 'preview';
        render(prev);
      }));
    }
    footRow(docsLink(entry && entry.docs ? entry.docs : DOCS_INDEX, 'Docker’s page on this setting'), buttons);
  }

  function renderPreview(prev) {
    body.appendChild(el('p', 'staxx-probwin-label', prev.label));
    var diff = el('div', 'staxx-probwin-diff');
    diff.appendChild(el('div', 'staxx-probwin-diff-row staxx-probwin-diff-row--old', '- ' + prev.oldLine));
    diff.appendChild(el('div', 'staxx-probwin-diff-row staxx-probwin-diff-row--new', '+ ' + prev.newLine));
    body.appendChild(diff);
    body.appendChild(el('p', 'staxx-probwin-text', prev.note));
    footRow(null, [
      button('Cancel', '', function () { view = 'problem'; render(); }),
      button('Make this change', 'staxx-btn--primary', function () {
        var done = opts.applyFix ? opts.applyFix(list[idx], idx) : null;
        if (!done) { view = 'problem'; render(); return; }
        doneMessage = done.message;
        view = 'done';
        render();
      })
    ]);
  }

  function renderDone() {
    var note = el('p', 'staxx-probwin-done');
    note.innerHTML = '<i class="fa fa-check-circle" aria-hidden="true"></i> ';
    note.appendChild(document.createTextNode(doneMessage));
    body.appendChild(note);
    if (list.length) {
      footRow(null, [button('Next problem', 'staxx-btn--primary', function () {
        view = 'problem';
        idx = Math.min(idx, list.length - 1);
        render();
        if (opts.nextProblem) opts.nextProblem();
      })]);
    } else {
      body.appendChild(el('p', 'staxx-probwin-text', 'This file is ready. Docker Compose can read it now.'));
      footRow(null, [button('Close', '', close)]);
    }
  }

  function render(prev) {
    body.textContent = '';
    var many = list.length > 1;
    var help = list.length === 1 && list[0].kind === 'help';
    iconEl.className = 'fa ' + (help ? 'fa-info-circle' : 'fa-wrench') + ' staxx-probwin-icon' +
      (help ? ' staxx-probwin-icon--info' : '');
    if (help) titleEl.textContent = list[0].title || 'About this setting';
    else if (!list.length) titleEl.textContent = 'This file is ready';
    else titleEl.textContent = list.length === 1 ? 'This file needs one fix' : 'This file needs ' + list.length + ' fixes';
    navBox.hidden = !many;
    countEl.textContent = (idx + 1) + ' of ' + list.length;
    prevBtn.disabled = idx <= 0;
    nextBtn.disabled = idx >= list.length - 1;

    if (view === 'done') renderDone();
    else if (view === 'preview' && prev) renderPreview(prev);
    else if (list.length) renderProblem(list[idx]);
    else renderDone();
  }

  /* ------------------------------------------------------------ public - */

  function show(problems, options) {
    if (!win) build();
    list = problems || [];
    opts = options || {};
    idx = Math.max(0, Math.min(opts.index || 0, list.length - 1));
    view = 'problem';
    doneMessage = '';
    render();

    if (!placed) {
      placed = true;
      var w = Math.min(44 * pxPerRem(), window.innerWidth * 0.94);
      moveTo(Math.max(0, window.innerWidth - w - 40), 150);
    }
    var into = target();
    if (win.parentNode !== into) { hidePop(win); into.appendChild(win); }
    setMinimised(false);
    open = true;
    showPop(win);
  }

  // A newer list while the window is open: the counts and the current
  // problem follow it, but a fix being previewed or just made is left alone.
  function refresh(problems) {
    if (!open) return;
    list = problems || [];
    // Fixed some other way (typed over): nothing left to explain, unless the
    // "Changed line" note is what is on screen.
    if (!list.length && view !== 'done') { hide(); return; }
    idx = Math.max(0, Math.min(idx, list.length - 1));
    if (view === 'preview') view = 'problem';
    render();
  }

  function hide() {
    if (!win || !open) return;
    open = false;
    hidePop(win);
  }

  window.StaxxProblems = {
    show: show,
    refresh: refresh,
    hide: hide,
    isOpen: function () { return open; }
  };
})();
