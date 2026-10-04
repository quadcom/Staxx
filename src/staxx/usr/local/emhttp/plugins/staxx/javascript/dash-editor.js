/* StaXX — the Dashboard tile editor (PLAN_183).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * Plain browser JavaScript, no libraries. Builds and drives the "Edit
 * dashboard tile" window and its icon picker, opened from Settings -> General
 * or from the tile's own header link (stacks.js hands #dashboard-editor to
 * open()). Kept out of stacks.js on purpose, same reasoning as manage.js and
 * the other satellite scripts: a bad edit here costs this window, not the
 * whole page's behaviour. It talks to the same POSTed, CSRF-gated endpoint as
 * stacks.js, through its own small copy of the same call() — nothing here
 * reaches into stacks.js's IIFE, since nothing there is exported.
 */

(function () {
  'use strict';

  var scaffold = document.querySelector('.staxx-scaffold');
  if (!scaffold) return;

  var ENDPOINT = scaffold.dataset.endpoint;
  var CSRF     = scaffold.dataset.csrf;

  function esc(s) {
    return String(s === undefined || s === null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // Same shape and the same reason as stacks.js's own call(): URLSearchParams
  // posts application/x-www-form-urlencoded, which Unraid's CSRF prepend
  // reads. A multipart body (FormData) simply hangs on this box.
  function call(action, fields) {
    var data = new URLSearchParams();
    data.append('csrf_token', CSRF);
    data.append('action', action);
    Object.keys(fields || {}).forEach(function (k) { data.append(k, fields[k]); });
    return fetch(ENDPOINT, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        try { return JSON.parse(text); }
        catch (e) { return { ok: false, error: 'The server did not return anything usable.' }; }
      })
      .catch(function (e) {
        return { ok: false, error: 'Could not reach ' + ENDPOINT + '\n\n' + (e && e.message ? e.message : e) };
      });
  }

  var GLYPHS = ['folder', 'folder-open', 'archive', 'cube', 'cubes', 'server',
                'database', 'film', 'music', 'download', 'shield', 'home'];

  var SOURCE_TABS = [
    { key: 'hernandito', label: 'Animated (hernandito)', collections: true },
    { key: 'ground7',    label: 'Animated (ground7)' },
    { key: 'selfhst',    label: 'selfh.st logos' },
    { key: 'logos',      label: 'Homarr apps' },
    { key: 'topics',     label: 'Topics', light: true },
    { key: 'upload',     label: 'Upload' },
    { key: 'plain',      label: 'Plain' }
  ];

  /* ---------------------------------------------------------------- state - */

  var state = null;          // the last dash_state reply
  var dirty = false;
  var selectedIdx = -1;      // index into state.layout.items, or -1
  var editorDlg = null, pickerDlg = null;
  var els = {};

  // Icon-picker's own working state, reset every time it opens.
  var picker = {
    tab: 'hernandito', collection: '', search: '',
    cache: {},          // "<tab>|<collection>" -> files[] from dash_icons
    files: [],          // the current tab/collection's files, filtered
    picked: null,       // { icon, url, name } once something is chosen
    uploadFile: null,   // a File object staged for Upload, before "Use this icon"
    forItem: null,      // the folder item this picker is choosing for
    onPicked: null      // set when pickIcon() opened the picker on its own: called with {icon, url}
  };

  // Auto-cross-fade timers, one per open folder square (canvas or preview),
  // keyed by a DOM id given to the element at render time. Cleared whenever
  // the canvas is redrawn, so a redraw never leaves a stray timer painting
  // an element that no longer exists.
  var autoTimers = {};

  function stopAutoTimers() {
    Object.keys(autoTimers).forEach(function (k) { clearInterval(autoTimers[k]); });
    autoTimers = {};
  }

  /* ------------------------------------------------------------- helpers - */

  function projectIcon(project) {
    var s = state.stacks[project];
    return s ? s.icon : '';
  }
  function projectName(project) {
    var s = state.stacks[project];
    return s ? s.name : project;
  }
  function projectState(project) {
    var s = state.stacks[project];
    if (!s) return 'gone';
    if (!s.total) return 'none';
    if (s.running >= s.total) return 'up';
    if (s.running > 0) return 'part';
    return 'down';
  }

  // One <img>/<i> for a stack's own icon, at a given pixel size — used on the
  // left list (22px), inside a loose-stack square, and inside a folder's
  // auto cross-fade.
  function stackIconHtml(project, px) {
    var icon = projectIcon(project);
    // A stack's icon is already an address (dash_state's stacks[].icon);
    // iconUrls is only for the folder pictures kept under config/icons/dash/.
    if (icon) {
      return '<img class="staxx-dash-icon-img" draggable="false" style="width:' + px + 'px;height:' + px + 'px" ' +
             'src="' + esc(icon) + '" alt="">';
    }
    return '<span class="staxx-dash-icon-blank" style="width:' + px + 'px;height:' + px + 'px"></span>';
  }

  function glyphIconHtml(glyph) {
    return '<i class="fa fa-' + esc(glyph) + ' staxx-dash-glyph"></i>';
  }

  // What a folder square (or its 96px preview) is made of, given its icon
  // choice, at whatever side length it is drawn at. Returns { bodyHtml,
  // fills, dark } — dark says whether the name needs the dark fade under it.
  function folderIconBody(item, side) {
    var icon = item.icon || 'auto';
    if (icon === 'auto') {
      var stacks = item.stacks || [];
      if (!stacks.length) return { bodyHtml: glyphIconHtml('folder'), dark: !!item.bg };
      // The cross-fade itself is wired up by wireAutoFade() once this markup
      // is in the document — two stacked layers, one lit at a time.
      var layers = stacks.map(function (p, i) {
        return '<span class="staxx-dash-auto-layer' + (i === 0 ? ' staxx-dash-auto-layer--on' : '') +
               '" data-auto-i="' + i + '">' + stackIconHtml(p, Math.round(side * 0.64)) + '</span>';
      }).join('');
      return { bodyHtml: '<span class="staxx-dash-auto">' + layers + '</span>', dark: !!item.bg, autoCount: stacks.length };
    }
    if (icon.indexOf('plain:') === 0) {
      return { bodyHtml: glyphIconHtml(icon.slice(6)), dark: !!item.bg };
    }
    // Anything else is a file name under config/icons/dash/, downloaded or
    // uploaded through the picker — a picture that fills the square.
    var url = state.iconUrls[icon];
    if (!url) return { bodyHtml: glyphIconHtml('folder'), dark: !!item.bg };
    return { bodyHtml: '<img class="staxx-dash-fill-img" draggable="false" src="' + esc(url) + '" alt="">', dark: true };
  }

  // The full inner markup of one square, folder or loose stack, at a given
  // side length — shared by the canvas, the drag ghost and the 96px icon
  // preview in the right panel, so all three always agree.
  function squareInnerHtml(item, side, opts) {
    opts = opts || {};
    var name, bodyHtml, dark = false, badge = '';
    var style = 'width:' + side + 'px;height:' + side + 'px;border-radius:' + (side * 22 / 96) + 'px;';
    if (item.type === 'folder') {
      name = item.name || '';
      var b = folderIconBody(item, side);
      bodyHtml = b.bodyHtml;
      dark = b.dark;
      if (item.bg) style += 'background:' + item.bg + ';';
      if ((item.stacks || []).length) {
        badge = '<span class="staxx-dash-badge">' + (item.stacks.length) + '</span>';
      }
    } else {
      name = projectName(item.stack);
      bodyHtml = '<span class="staxx-dash-loose-icon">' + stackIconHtml(item.stack, Math.round(side * 0.54)) + '</span>';
    }
    var fontPx = Math.max(10, side * 12 / 96);
    return '<div class="staxx-dash-square' + (dark ? ' staxx-dash-square--dark' : '') + '" style="' + style + '">' +
             badge +
             '<div class="staxx-dash-square-body">' + bodyHtml + '</div>' +
             '<div class="staxx-dash-square-name" title="' + esc(name) + '" style="font-size:' + fontPx + 'px">' + esc(name) + '</div>' +
           '</div>';
  }

  // Starts the cross-fade for every '.staxx-dash-auto' left in the document
  // (there may be more than one at once — the canvas square and, while a
  // folder is selected, its 96px preview). ~2s each layer, 0.6s simultaneous
  // in/out (both classes changing on the same tick does that — CSS carries
  // the transition), looping for as long as the window is open. Runs under
  // reduced motion too: this is a fade, not movement (PLAN_202).
  function wireAutoFade(root) {
    Array.prototype.forEach.call(root.querySelectorAll('.staxx-dash-auto'), function (auto, autoIdx) {
      var layers = Array.prototype.slice.call(auto.querySelectorAll('.staxx-dash-auto-layer'));
      if (layers.length < 2) return;
      var id = 'a' + autoIdx + '-' + Math.random().toString(36).slice(2);
      var on = 0;
      autoTimers[id] = setInterval(function () {
        layers[on].classList.remove('staxx-dash-auto-layer--on');
        on = (on + 1) % layers.length;
        layers[on].classList.add('staxx-dash-auto-layer--on');
      }, 2000);
    });
  }

  /* --------------------------------------------------------- left column - */

  function renderLeftList() {
    var byFolder = {};
    var order = [];
    Object.keys(state.stacks).forEach(function (project) {
      var s = state.stacks[project];
      var f = s.folder || '';
      if (!byFolder[f]) { byFolder[f] = []; order.push(f); }
      byFolder[f].push(project);
    });
    order.sort(function (a, b) {
      if (a === '') return 1;
      if (b === '') return -1;
      return a.localeCompare(b);
    });

    var placed = {};   // project -> folder name it sits in, or true for loose
    (state.layout.items || []).forEach(function (item) {
      if (item.type === 'stack') placed[item.stack] = true;
      if (item.type === 'folder') (item.stacks || []).forEach(function (p) { placed[p] = item.name; });
    });

    var html = order.map(function (f) {
      var rows = byFolder[f].map(function (project) {
        var on = placed[project];
        var onHtml = on
          ? '<span class="staxx-dash-onTile"><i class="fa fa-thumb-tack"></i> ' +
            (on === true ? 'On the tile' : esc(on)) + '</span>'
          : '';
        return '<div class="staxx-dash-listrow" data-dash-list-stack="' + esc(project) + '">' +
                 stackIconHtml(project, 22) +
                 '<span class="staxx-dash-listname">' + esc(projectName(project)) + '</span>' +
                 onHtml +
               '</div>';
      }).join('');
      return '<div class="staxx-dash-folderbar">' + esc(f || '(no folder)') + '</div>' + rows;
    }).join('');

    els.list.innerHTML = html;
  }

  /* -------------------------------------------------------------- canvas - */

  function cellSide() { return 564 / state.layout.cols; }
  function squareSide() { return cellSide() - 7; }

  function itemAt(c, r) {
    for (var i = 0; i < state.layout.items.length; i++) {
      var it = state.layout.items[i];
      if (it.c === c && it.r === r) return i;
    }
    return -1;
  }

  function colOccupied(c) { return state.layout.items.some(function (it) { return it.c === c; }); }
  function rowOccupied(r) { return state.layout.items.some(function (it) { return it.r === r; }); }

  function renderCanvas() {
    stopAutoTimers();
    var cols = state.layout.cols, rows = state.layout.rows;
    var cell = cellSide(), side = squareSide();
    var html = '<div class="staxx-dash-colxs">' + range(cols).map(function (c) {
      var occ = colOccupied(c);
      var min = cols <= 2;
      var cls = (!occ && !min) ? '' : ' staxx-dash-x--dim';
      var title = occ ? 'Move everything out of this column first'
                : (min ? 'This is the smallest the tile can be' : 'Remove this column');
      return '<button type="button" class="staxx-dash-x staxx-dash-x--col' + cls + '" style="left:' + (c * cell + side / 2) + 'px" ' +
             'title="' + esc(title) + '" data-dash-rmcol="' + c + '" ' + ((occ || min) ? 'disabled' : '') + '>&times;</button>';
    }).join('') + '</div>';

    html += '<div class="staxx-dash-rowxs">' + range(rows).map(function (r) {
      var occ = rowOccupied(r);
      var min = rows <= 1;
      var cls = (!occ && !min) ? '' : ' staxx-dash-x--dim';
      var title = occ ? 'Move everything out of this row first'
                : (min ? 'This is the smallest the tile can be' : 'Remove this row');
      return '<button type="button" class="staxx-dash-x staxx-dash-x--row' + cls + '" style="top:' + (r * cell + side / 2) + 'px" ' +
             'title="' + esc(title) + '" data-dash-rmrow="' + r + '" ' + ((occ || min) ? 'disabled' : '') + '>&times;</button>';
    }).join('') + '</div>';

    var cellsHtml = '';
    for (var r = 0; r < rows; r++) {
      for (var c = 0; c < cols; c++) {
        var idx = itemAt(c, r);
        var left = c * cell + (cell - side) / 2, top = r * cell + (cell - side) / 2;
        cellsHtml += '<div class="staxx-dash-cell" style="left:' + left + 'px;top:' + top + 'px;width:' + side + 'px;height:' + side + 'px;' +
                     'border-radius:' + (side * 22 / 96) + 'px" data-dash-cell-c="' + c + '" data-dash-cell-r="' + r + '">';
        if (idx !== -1) {
          var it = state.layout.items[idx];
          cellsHtml += '<div class="staxx-dash-piece' + (idx === selectedIdx ? ' staxx-dash-piece--sel' : '') +
                       '" data-dash-item="' + idx + '">' + squareInnerHtml(it, side) +
                       '<button type="button" class="staxx-dash-remove" data-dash-remove="' + idx + '" title="Remove"><i class="fa fa-times"></i></button>' +
                       '</div>';
        }
        cellsHtml += '</div>';
      }
    }

    els.canvas.innerHTML = html +
      '<div class="staxx-dash-cells" style="width:' + (cols * cell) + 'px;height:' + (rows * cell) + 'px">' + cellsHtml + '</div>';
    els.canvas.style.width = (cols * cell) + 'px';
    els.canvas.style.height = (rows * cell) + 'px';

    wireAutoFade(els.canvas);
  }

  function range(n) { var a = []; for (var i = 0; i < n; i++) a.push(i); return a; }

  /* --------------------------------------------------------- right panel - */

  var SWATCHES = ['#ffffff', '#e9e7e4', '#9e9e9e', '#111111', '#e68a00', '#2e7dd7', '#3c8f3c', '#c0392b', '#7e57c2'];

  function nextFolderName() {
    var max = 0;
    (state.layout.items || []).forEach(function (it) {
      if (it.type !== 'folder') return;
      var m = /^New folder (\d+)$/.exec(it.name || '');
      if (m) max = Math.max(max, parseInt(m[1], 10));
    });
    return 'New folder ' + (max + 1);
  }

  function firstFreeCell() {
    var cols = state.layout.cols, rows = state.layout.rows;
    for (var r = 0; r < rows; r++) {
      for (var c = 0; c < cols; c++) {
        if (itemAt(c, r) === -1) return { c: c, r: r };
      }
    }
    return null;
  }

  function newFolder() {
    var free = firstFreeCell();
    if (!free) {
      if (state.layout.rows >= 8) return;   // button is disabled by this point already
      state.layout.rows++;
      free = firstFreeCell();
      if (!free) return;
    }
    var id = 'f' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    state.layout.items.push({ type: 'folder', id: id, c: free.c, r: free.r, name: nextFolderName(), icon: 'auto', stacks: [] });
    selectedIdx = state.layout.items.length - 1;
    markDirty();
    renderAll();
  }

  function selectedItem() { return selectedIdx >= 0 ? state.layout.items[selectedIdx] : null; }

  function renderRightPanel() {
    var cols = state.layout.cols, rows = state.layout.rows;
    var colLast = colOccupied(cols - 1), rowLast = rowOccupied(rows - 1);
    var noRoom = !firstFreeCell() && rows >= 8;

    var html =
      '<div class="staxx-dash-field">' +
        '<span>Tile size</span>' +
        '<div class="staxx-dash-steprow"><label>Columns</label>' +
          '<div class="staxx-dash-stepper">' +
            '<button type="button" data-dash-step="cols" data-dash-dir="-1" ' + ((cols <= 2 || colLast) ? 'disabled' : '') + '>&minus;</button>' +
            '<span>' + cols + '</span>' +
            '<button type="button" data-dash-step="cols" data-dash-dir="1" ' + (cols >= 8 ? 'disabled' : '') + '>+</button>' +
          '</div></div>' +
        '<div class="staxx-dash-steprow"><label>Rows</label>' +
          '<div class="staxx-dash-stepper">' +
            '<button type="button" data-dash-step="rows" data-dash-dir="-1" ' + ((rows <= 1 || rowLast) ? 'disabled' : '') + '>&minus;</button>' +
            '<span>' + rows + '</span>' +
            '<button type="button" data-dash-step="rows" data-dash-dir="1" ' + (rows >= 8 ? 'disabled' : '') + '>+</button>' +
          '</div></div>' +
        '<span class="staxx-hint">Columns 2 to 8, rows 1 to 8. To remove a row or column in the middle, empty it and press its red &times; on the canvas edge.</span>' +
      '</div>' +
      '<button type="button" class="staxx-btn staxx-dash-newfolder" id="staxx-dash-newfolder" ' + (noRoom ? 'disabled' : '') + '>New folder</button>';

    var item = selectedItem();
    if (item) html += '<div class="staxx-dash-divider"></div>' + selectedPanelHtml(item);

    els.right.innerHTML = html;
    wireAutoFade(els.right);
  }

  function selectedPanelHtml(item) {
    if (item.type === 'stack') {
      return '<div class="staxx-dash-field">' +
        '<span>' + esc(projectName(item.stack)) + '</span>' +
        '<div class="staxx-dash-iconprev-row">' + squareInnerHtml(item, 96) + '</div>' +
        '<span class="staxx-hint">Drag it onto a folder to put it inside.</span>' +
      '</div>';
    }

    var auto = (item.icon || 'auto') === 'auto';
    var stacksHtml = (item.stacks || []).length
      ? item.stacks.map(function (p, i) {
          return '<div class="staxx-dash-folderstack" draggable="false" data-dash-fstack="' + i + '">' +
                   '<span class="staxx-dash-grip" data-dash-fgrip="' + i + '"><i class="fa fa-bars"></i></span>' +
                   stackIconHtml(p, 20) +
                   '<span class="staxx-dash-fname">' + esc(projectName(p)) + '</span>' +
                   '<button type="button" class="staxx-dash-remove staxx-dash-remove--inline" data-dash-funpin="' + i + '" title="Take it out of this folder"><i class="fa fa-times"></i></button>' +
                 '</div>';
        }).join('')
      : '<p class="staxx-hint">Drag stacks onto this folder from the list on the left.</p>';

    return '<div class="staxx-dash-field"><span class="staxx-dash-heading">Folder</span></div>' +
    '<div class="staxx-dash-field">' +
      '<span>Name</span>' +
      '<input type="text" class="staxx-dash-text" id="staxx-dash-fname" value="' + esc(item.name) + '">' +
    '</div>' +
    '<div class="staxx-dash-field">' +
      '<span>Icon</span>' +
      '<div class="staxx-dash-iconprev-row">' + squareInnerHtml(item, 96) + '</div>' +
      '<div class="staxx-dash-autorow">' +
        '<span>Auto</span>' +
        '<label class="staxx-switch"><input type="checkbox" id="staxx-dash-auto" ' + (auto ? 'checked' : '') + '>' +
        '<span class="staxx-switch-track"></span></label>' +
      '</div>' +
      '<span class="staxx-hint">' + (auto
        ? 'Shows each stack inside in turn, on a loop.'
        : 'Off: the folder shows the icon you choose.') + '</span>' +
      '<button type="button" class="staxx-btn staxx-dash-fullwidth" id="staxx-dash-chooseicon" ' + (auto ? 'disabled' : '') + '>Choose icon&hellip;</button>' +
    '</div>' +
    '<div class="staxx-dash-field">' +
      '<span>Background</span>' +
      '<div class="staxx-dash-swatches">' + SWATCHES.map(function (hex) {
        return '<button type="button" class="staxx-dash-swatch' + (item.bg === hex ? ' staxx-dash-swatch--on' : '') +
               '" style="background:' + hex + '" data-dash-bg="' + hex + '" title="' + hex + '"></button>';
      }).join('') +
      '<label class="staxx-dash-eyedrop" title="Pick a colour"><i class="fa fa-eyedropper"></i>' +
        '<input type="color" id="staxx-dash-eyedrop" value="' + esc(item.bg || '#e68a00') + '"></label>' +
      '</div>' +
      (item.bg ? '<button type="button" class="staxx-dash-removecolour" id="staxx-dash-removecolour"><i class="fa fa-times"></i> Remove colour</button>' : '') +
    '</div>' +
    '<div class="staxx-dash-field">' +
      '<span>Stacks in this folder</span>' +
      '<div class="staxx-dash-folderlist" id="staxx-dash-folderlist">' + stacksHtml + '</div>' +
    '</div>';
  }

  /* ------------------------------------------------------------- redraw - */

  function markDirty() { dirty = true; els.msg.textContent = ''; }

  function renderAll() {
    renderLeftList();
    renderCanvas();
    renderRightPanel();
  }

  /* ------------------------------------------------------- tile-size ops - */

  function stepCols(dir) {
    if (dir > 0) { if (state.layout.cols < 8) state.layout.cols++; }
    else { if (state.layout.cols > 2 && !colOccupied(state.layout.cols - 1)) state.layout.cols--; }
    markDirty(); renderAll();
  }
  function stepRows(dir) {
    if (dir > 0) { if (state.layout.rows < 8) state.layout.rows++; }
    else { if (state.layout.rows > 1 && !rowOccupied(state.layout.rows - 1)) state.layout.rows--; }
    markDirty(); renderAll();
  }
  function removeColumn(c) {
    if (colOccupied(c) || state.layout.cols <= 2) return;
    state.layout.items.forEach(function (it) { if (it.c > c) it.c--; });
    state.layout.cols--;
    markDirty(); renderAll();
  }
  function removeRow(r) {
    if (rowOccupied(r) || state.layout.rows <= 1) return;
    state.layout.items.forEach(function (it) { if (it.r > r) it.r--; });
    state.layout.rows--;
    markDirty(); renderAll();
  }

  /* -------------------------------------------------------------- drags - */
  // One shared drag driver for three sources — an existing canvas piece, a
  // left-list row, and (separately, see wireFolderListDrag) a folder's own
  // stack-order grip — all using pointer events so a real mouse drag works,
  // not just touch. A move under 5px on release counts as a click/select
  // instead of a drag, per the plan's own threshold.

  // onStart runs once when the drag really begins; it may return an undo function,
  // which runs on every exit (drop, cancel) before the drop handler re-renders.
  function startDrag(e, ghostHtml, onMove, onDrop, onClick, onStart) {
    if (e.button !== 0) return;   // primary button only
    // Stops the browser starting its own image drag, which fires pointercancel
    // and swallows the real pointerup so the drop only landed on the next click.
    e.preventDefault();
    var startX = e.clientX, startY = e.clientY, dragging = false;
    var ghost = null, undoStart = null;

    function move(ev) {
      var dx = ev.clientX - startX, dy = ev.clientY - startY;
      if (!dragging && Math.sqrt(dx * dx + dy * dy) >= 5) {
        dragging = true;
        ghost = document.createElement('div');
        ghost.className = 'staxx-dash-ghost';
        ghost.innerHTML = ghostHtml;
        // The modal dialog is in the top layer, so a ghost outside it is drawn underneath.
        (editorDlg && editorDlg.open ? editorDlg : (document.querySelector('.staxx-scaffold') || document.body)).appendChild(ghost);
        document.body.classList.add('staxx-dash-dragging');
        if (onStart) undoStart = onStart();
      }
      if (dragging) {
        ghost.style.left = ev.clientX + 'px';
        ghost.style.top = ev.clientY + 'px';
        onMove(ev.clientX, ev.clientY);
      }
    }
    function noNative(ev) { ev.preventDefault(); }
    function finish() {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      document.removeEventListener('pointercancel', cancel);
      document.removeEventListener('dragstart', noNative);
      if (ghost) ghost.remove();
      if (typeof undoStart === 'function') undoStart();
      document.body.classList.remove('staxx-dash-dragging');
    }
    function up(ev) {
      finish();
      if (dragging) onDrop(ev.clientX, ev.clientY);
      else if (onClick) onClick();
    }
    // A cancelled pointer is a cancelled drag: no drop, no click.
    function cancel() {
      finish();
      clearHi();
      Array.prototype.forEach.call(document.querySelectorAll('.staxx-dash-dropline'), function (el) { el.remove(); });
    }
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
    document.addEventListener('pointercancel', cancel);
    document.addEventListener('dragstart', noNative);
  }

  function cellFromPoint(x, y) {
    var r = els.canvas.getBoundingClientRect();
    var cell = cellSide();
    var c = Math.floor((x - r.left) / cell), row = Math.floor((y - r.top) / cell);
    if (c < 0 || row < 0 || c >= state.layout.cols || row >= state.layout.rows) return null;
    return { c: c, r: row };
  }

  function clearHi() {
    Array.prototype.forEach.call(els.canvas.querySelectorAll('.staxx-dash-cell--drop, .staxx-dash-cell--folderhi'), function (el) {
      el.classList.remove('staxx-dash-cell--drop', 'staxx-dash-cell--folderhi');
    });
  }
  function hiCell(cell, folder) {
    clearHi();
    if (!cell) return;
    var el = els.canvas.querySelector('[data-dash-cell-c="' + cell.c + '"][data-dash-cell-r="' + cell.r + '"]');
    if (el) el.classList.add(folder ? 'staxx-dash-cell--folderhi' : 'staxx-dash-cell--drop');
  }

  function wireCanvasDrag() {
    els.canvas.addEventListener('pointerdown', function (e) {
      var pieceEl = e.target.closest('[data-dash-item]');
      if (!pieceEl) {
        if (e.target.closest('.staxx-dash-cell') && !e.target.closest('.staxx-dash-piece')) {
          selectedIdx = -1; renderRightPanel(); renderCanvas();
        }
        return;
      }
      if (e.target.closest('.staxx-dash-remove')) return;   // handled by click delegate
      var idx = parseInt(pieceEl.dataset.dashItem, 10);
      var item = state.layout.items[idx];
      var side = squareSide();
      var ghostHtml = squareInnerHtml(item, side);

      startDrag(e, ghostHtml, function (x, y) {
        var cell = cellFromPoint(x, y);
        var overIdx = cell ? itemAt(cell.c, cell.r) : -1;
        var overFolder = overIdx !== -1 && state.layout.items[overIdx].type === 'folder' && item.type === 'stack' && overIdx !== idx;
        hiCell(cell, overFolder);
      }, function (x, y) {
        clearHi();
        var cell = cellFromPoint(x, y);
        if (!cell) return;
        var overIdx = itemAt(cell.c, cell.r);
        if (overIdx === idx) { selectedIdx = idx; renderAll(); return; }
        if (overIdx !== -1 && state.layout.items[overIdx].type === 'folder' && item.type === 'stack') {
          state.layout.items[overIdx].stacks.push(item.stack);
          state.layout.items.splice(idx, 1);
          selectedIdx = overIdx > idx ? overIdx - 1 : overIdx;
        } else if (overIdx !== -1) {
          // swap
          var other = state.layout.items[overIdx];
          var oc = other.c, or = other.r;
          other.c = item.c; other.r = item.r;
          item.c = oc; item.r = or;
          selectedIdx = idx;
        } else {
          item.c = cell.c; item.r = cell.r;
          selectedIdx = idx;
        }
        markDirty(); renderAll();
      }, function () {
        selectedIdx = idx;
        renderRightPanel();
        renderCanvas();
      }, function () {
        // The lifted square's own cell looks empty while the ghost carries it.
        pieceEl.classList.add('staxx-dash-piece--lifted');
        return function () { pieceEl.classList.remove('staxx-dash-piece--lifted'); };
      });
    });

    els.canvas.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-dash-remove]');
      if (rm) {
        var idx = parseInt(rm.dataset.dashRemove, 10);
        state.layout.items.splice(idx, 1);
        if (selectedIdx === idx) selectedIdx = -1;
        else if (selectedIdx > idx) selectedIdx--;
        markDirty(); renderAll();
        return;
      }
      var rc = e.target.closest('[data-dash-rmcol]');
      if (rc && !rc.disabled) { removeColumn(parseInt(rc.dataset.dashRmcol, 10)); return; }
      var rr = e.target.closest('[data-dash-rmrow]');
      if (rr && !rr.disabled) { removeRow(parseInt(rr.dataset.dashRmrow, 10)); return; }
    });
  }

  function wireListDrag() {
    els.list.addEventListener('pointerdown', function (e) {
      var rowEl = e.target.closest('[data-dash-list-stack]');
      if (!rowEl) return;
      var project = rowEl.dataset.dashListStack;
      var ghostHtml = '<div class="staxx-dash-card">' + stackIconHtml(project, 22) +
                       '<span>' + esc(projectName(project)) + '</span></div>';

      startDrag(e, ghostHtml, function (x, y) {
        var cell = cellFromPoint(x, y);
        var overIdx = cell ? itemAt(cell.c, cell.r) : -1;
        var overFolder = overIdx !== -1 && state.layout.items[overIdx].type === 'folder';
        hiCell(cell, overFolder);
      }, function (x, y) {
        clearHi();
        var cell = cellFromPoint(x, y);
        if (!cell) return;
        removeProjectFromTile(project);
        var overIdx = itemAt(cell.c, cell.r);
        if (overIdx !== -1 && state.layout.items[overIdx].type === 'folder') {
          state.layout.items[overIdx].stacks.push(project);
        } else if (overIdx === -1) {
          state.layout.items.push({ type: 'stack', c: cell.c, r: cell.r, stack: project });
        }
        markDirty(); renderAll();
      }, null);
    });
  }

  function removeProjectFromTile(project) {
    state.layout.items = state.layout.items.filter(function (it) { return !(it.type === 'stack' && it.stack === project); });
    state.layout.items.forEach(function (it) {
      if (it.type === 'folder') it.stacks = (it.stacks || []).filter(function (p) { return p !== project; });
    });
  }

  // Reordering the rows inside "Stacks in this folder" — grip-driven, same
  // pointer-drag driver, but the drop target is "before/after this row"
  // rather than a cell, shown as an orange line between two rows.
  function wireFolderListDrag() {
    els.right.addEventListener('pointerdown', function (e) {
      var grip = e.target.closest('[data-dash-fgrip]');
      if (!grip) return;
      var item = selectedItem();
      if (!item || item.type !== 'folder') return;
      var fromIdx = parseInt(grip.dataset.dashFgrip, 10);
      var list = document.getElementById('staxx-dash-folderlist');
      if (!list) return;
      var rows = Array.prototype.slice.call(list.querySelectorAll('[data-dash-fstack]'));

      startDrag(e, '<div class="staxx-dash-card">' + stackIconHtml(item.stacks[fromIdx], 20) +
        '<span>' + esc(projectName(item.stacks[fromIdx])) + '</span></div>', function (x, y) {
        Array.prototype.forEach.call(list.querySelectorAll('.staxx-dash-dropline'), function (el) { el.remove(); });
        var target = rows.filter(function (r) {
          var rect = r.getBoundingClientRect();
          return y >= rect.top && y <= rect.bottom;
        })[0];
        if (!target) return;
        var rect = target.getBoundingClientRect();
        var line = document.createElement('div');
        line.className = 'staxx-dash-dropline';
        target.parentNode.insertBefore(line, y < rect.top + rect.height / 2 ? target : target.nextSibling);
      }, function (x, y) {
        var target = rows.filter(function (r) {
          var rect = r.getBoundingClientRect();
          return y >= rect.top && y <= rect.bottom;
        })[0];
        var toIdx = target ? parseInt(target.dataset.dashFstack, 10) : item.stacks.length - 1;
        var moved = item.stacks.splice(fromIdx, 1)[0];
        if (toIdx > fromIdx) toIdx--;
        item.stacks.splice(toIdx, 0, moved);
        markDirty(); renderAll();
      }, null);
    });
  }

  /* --------------------------------------------------------------- save - */

  function doSave() {
    els.msg.textContent = 'Saving…';
    els.save.disabled = true; els.cancel.disabled = true;
    call('dash_save', { layout: JSON.stringify(state.layout) }).then(function (res) {
      els.save.disabled = false; els.cancel.disabled = false;
      if (!res.ok) {
        els.msg.textContent = res.error || 'Could not save the dashboard tile.';
        return;
      }
      state.layout = res.layout;
      dirty = false;
      editorDlg.close();
    });
  }

  function doClose() {
    stopAutoTimers();
    editorDlg.close();
  }

  /* -------------------------------------------------------------- picker - */

  function pickerCacheKey() { return picker.tab + '|' + (picker.collection || ''); }

  function setFromSet(id) {
    return (state.sets || []).filter(function (s) { return s.id === id || s.tab === id; })[0] || null;
  }

  function loadPickerTab() {
    var pane = els.pickerTools;
    picker.picked = null;
    els.pickerUse.disabled = true;
    els.pickerPreview.innerHTML = '';

    if (picker.tab === 'plain') {
      pane.innerHTML = '';
      els.pickerGrid.className = 'staxx-dash-picker-grid';
      els.pickerGrid.innerHTML = GLYPHS.map(function (g) {
        return '<button type="button" class="staxx-dash-pick-tile" data-dash-plain="' + g + '">' +
               '<i class="fa fa-' + g + ' staxx-dash-glyph"></i></button>';
      }).join('');
      els.pickerCredit.innerHTML = '';
      return;
    }
    if (picker.tab === 'upload') {
      pane.innerHTML = '';
      els.pickerGrid.className = 'staxx-dash-picker-grid staxx-dash-picker-grid--upload';
      els.pickerGrid.innerHTML =
        '<div class="staxx-dash-drop" id="staxx-dash-drop">' +
          '<p>Drop a picture here, or choose a file.</p>' +
          '<p class="staxx-hint">SVG, PNG or WebP. It fills the whole square, so a square picture works best.</p>' +
          '<input type="file" accept=".svg,.png,.webp" id="staxx-dash-upload-input">' +
        '</div>';
      els.pickerCredit.innerHTML = '';
      wireUpload();
      return;
    }

    var def = SOURCE_TABS.filter(function (t) { return t.key === picker.tab; })[0];
    var toolsHtml = '<input type="text" class="staxx-dash-text staxx-dash-picker-search" ' +
      'id="staxx-dash-picker-search" placeholder="Search, for example media, download, plex">';
    if (def.collections) {
      toolsHtml = '<select class="staxx-dash-select" id="staxx-dash-picker-collection"></select>' + toolsHtml;
    }
    pane.innerHTML = toolsHtml;
    fetchPickerFiles();
  }

  function fetchPickerFiles() {
    var key = pickerCacheKey();
    var reqTab = picker.tab;
    var reqCollection = picker.collection;
    if (picker.cache[key]) { renderPickerGrid(); return; }
    els.pickerGrid.innerHTML = '<p class="staxx-hint">Loading&hellip;</p>';
    call('dash_icons', picker.collection ? { set: picker.tab, collection: picker.collection } : { set: picker.tab })
      .then(function (res) {
        if (!res.ok) { els.pickerGrid.innerHTML = '<p class="staxx-hint">' + esc(res.error || 'Could not load icons.') + '</p>'; return; }
        if (res.collections && res.collections.length) {
          var sel = document.getElementById('staxx-dash-picker-collection');
          if (sel && !sel.options.length) {
            sel.innerHTML = res.collections.map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
            if (!picker.collection) picker.collection = res.collections[0];
            sel.value = picker.collection;
          }
        }
        picker.cache[key] = res.files || [];
        // The reply may have just chosen a collection: the grid now belongs to
        // that key, so fetch it. A reply for a tab/collection the user has
        // since left is cached but never drawn.
        if (pickerCacheKey() !== key) { if (picker.tab === reqTab && !reqCollection) fetchPickerFiles(); return; }
        renderPickerGrid();
      });
  }

  // The server sends keywords as one space-separated string; an array is accepted too.
  function keywordsMatch(kw, q) {
    if (typeof kw === 'string') return kw.toLowerCase().indexOf(q) !== -1;
    if (Array.isArray(kw)) return kw.some(function (k) { return String(k).toLowerCase().indexOf(q) !== -1; });
    return false;
  }

  function renderPickerGrid() {
    var files = picker.cache[pickerCacheKey()] || [];
    var q = (picker.search || '').toLowerCase();
    if (q) {
      files = files.filter(function (f) {
        return (f.name || '').toLowerCase().indexOf(q) !== -1 ||
               (f.file || '').toLowerCase().indexOf(q) !== -1 ||
               keywordsMatch(f.keywords, q);
      });
    }
    var def = SOURCE_TABS.filter(function (t) { return t.key === picker.tab; })[0];
    var thumbCls = (picker.tab === 'logos' || picker.tab === 'topics' || picker.tab === 'selfhst') ? ' staxx-dash-pick-thumb--62' : '';
    els.pickerGrid.className = 'staxx-dash-picker-grid' + (def.light ? ' staxx-dash-picker-grid--light' : '');
    els.pickerGrid.innerHTML = files.map(function (f) {
      return '<button type="button" class="staxx-dash-pick-tile' + (def.light ? ' staxx-dash-pick-tile--light' : '') +
             '" data-dash-pick-file="' + esc(f.file) + '" data-dash-pick-name="' + esc(f.name) + '">' +
             '<img class="staxx-dash-pick-thumb' + thumbCls + '" src="' + esc(f.thumb) + '" alt="" loading="lazy"></button>';
    }).join('');

    var setInfo = setFromSet(picker.tab);
    els.pickerCredit.innerHTML = setInfo
      ? '<a href="' + esc(setInfo.repo) + '" target="_blank" rel="noopener">' + esc(setInfo.credit) + '</a>' : '';
  }

  function wireUpload() {
    var drop = document.getElementById('staxx-dash-drop');
    var input = document.getElementById('staxx-dash-upload-input');
    if (!drop) return;
    function takeFile(file) {
      if (!file) return;
      picker.uploadFile = file;
      var reader = new FileReader();
      reader.onload = function () {
        els.pickerPreview.innerHTML = '<img src="' + reader.result + '" class="staxx-dash-pick-thumb" alt=""> <span>Upload: ' + esc(file.name) + '</span>';
        els.pickerUse.disabled = false;
      };
      reader.readAsDataURL(file);
    }
    drop.addEventListener('click', function (e) { if (e.target !== input) input.click(); });
    input.addEventListener('change', function () { takeFile(input.files[0]); });
    drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('staxx-dash-drop--over'); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('staxx-dash-drop--over'); });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      drop.classList.remove('staxx-dash-drop--over');
      takeFile(e.dataTransfer.files[0]);
    });
  }

  function openPicker(item, onPicked) {
    picker.forItem = item;
    picker.onPicked = onPicked || null;
    picker.tab = 'hernandito';
    picker.collection = '';
    picker.search = '';
    picker.cache = {};
    picker.uploadFile = null;
    // A Stacks-page folder icon is always a picture, and the Plain glyphs are
    // not files, so that tab is left out when the picker runs on its own.
    els.pickerTabs.innerHTML = SOURCE_TABS.filter(function (t) {
      return !(onPicked && t.key === 'plain');
    }).map(function (t, i) {
      return '<button type="button" class="staxx-tab' + (i === 0 ? ' staxx-tab--on' : '') + '" data-dash-picker-tab="' + t.key + '">' + esc(t.label) + '</button>';
    }).join('');
    loadPickerTab();
    pickerDlg.showModal();
  }

  function pickerTilePicked(el) {
    Array.prototype.forEach.call(els.pickerGrid.querySelectorAll('.staxx-dash-pick-tile--on'), function (t) { t.classList.remove('staxx-dash-pick-tile--on'); });
    el.classList.add('staxx-dash-pick-tile--on');
  }

  function commitPicker() {
    var item = picker.forItem;
    if (!item) { pickerDlg.close(); return; }
    if (picker.tab === 'plain') {
      item.icon = 'plain:' + picker.picked;
      finishIconPick(item);
      return;
    }
    if (picker.tab === 'upload') {
      if (!picker.uploadFile) return;
      var reader = new FileReader();
      reader.onload = function () {
        var b64 = String(reader.result).split(',')[1] || '';
        els.pickerUse.disabled = true;
        call('dash_icon_upload', { name: picker.uploadFile.name, data: b64 }).then(function (res) {
          els.pickerUse.disabled = false;
          if (!res.ok) { els.pickerPreview.innerHTML = '<span class="staxx-hint">' + esc(res.error || 'Could not upload that file.') + '</span>'; return; }
          item.icon = res.icon;
          state.iconUrls[res.icon] = res.url;
          finishIconPick(item);
        });
      };
      reader.readAsDataURL(picker.uploadFile);
      return;
    }
    if (!picker.picked) return;
    call('dash_icon_pick', { set: picker.tab, file: picker.picked }).then(function (res) {
      if (!res.ok) { els.pickerPreview.innerHTML = '<span class="staxx-hint">' + esc(res.error || 'Could not fetch that icon.') + '</span>'; return; }
      item.icon = res.icon;
      state.iconUrls[res.icon] = res.url;
      finishIconPick(item);
    });
  }

  function finishIconPick(item) {
    if (picker.onPicked) {
      var done = picker.onPicked;
      picker.onPicked = null;
      pickerDlg.close();
      done({ icon: item.icon, url: state.iconUrls[item.icon] || '' });
      return;
    }
    pickerDlg.close();
    markDirty();
    renderAll();
  }

  /* -------------------------------------------------------------- build - */

  function buildDialogsOnce() {
    if (editorDlg) return;

    editorDlg = document.createElement('dialog');
    editorDlg.className = 'staxx-dash-editor';
    editorDlg.setAttribute('aria-labelledby', 'staxx-dash-editor-title');
    editorDlg.innerHTML =
      '<div class="staxx-dash-head">' +
        '<h3 class="staxx-dash-title" id="staxx-dash-editor-title">Edit dashboard tile</h3>' +
        '<button type="button" class="staxx-dash-closebtn" id="staxx-dash-close"><i class="fa fa-times"></i> Close</button>' +
      '</div>' +
      '<div class="staxx-dash-body">' +
        '<div class="staxx-dash-col staxx-dash-col--left" id="staxx-dash-list"></div>' +
        '<div class="staxx-dash-col staxx-dash-col--mid">' +
          '<p class="staxx-dash-caption">The tile as it will look on the Dashboard</p>' +
          '<div class="staxx-dash-canvas" id="staxx-dash-canvas"></div>' +
        '</div>' +
        '<div class="staxx-dash-col staxx-dash-col--right" id="staxx-dash-right"></div>' +
      '</div>' +
      '<div class="staxx-dash-foot">' +
        '<p class="staxx-dash-msg" id="staxx-dash-msg"></p>' +
        '<div class="staxx-buttons staxx-buttons--inline">' +
          '<button type="button" class="staxx-btn" id="staxx-dash-cancel">Cancel</button>' +
          '<button type="button" class="staxx-btn" id="staxx-dash-save">Save</button>' +
        '</div>' +
      '</div>';
    (document.querySelector('.staxx-scaffold') || document.body).appendChild(editorDlg);

    els.list = editorDlg.querySelector('#staxx-dash-list');
    els.canvas = editorDlg.querySelector('#staxx-dash-canvas');
    els.right = editorDlg.querySelector('#staxx-dash-right');
    els.msg = editorDlg.querySelector('#staxx-dash-msg');
    els.save = editorDlg.querySelector('#staxx-dash-save');
    els.cancel = editorDlg.querySelector('#staxx-dash-cancel');

    editorDlg.querySelector('#staxx-dash-close').addEventListener('click', doClose);
    els.cancel.addEventListener('click', doClose);
    els.save.addEventListener('click', doSave);
    editorDlg.addEventListener('cancel', function (e) { e.preventDefault(); doClose(); });

    wireCanvasDrag();
    wireListDrag();
    wireFolderListDrag();

    els.right.addEventListener('click', function (e) {
      if (e.target.closest('#staxx-dash-newfolder')) { newFolder(); return; }
      if (e.target.closest('#staxx-dash-chooseicon')) { openPicker(selectedItem()); return; }
      var swatch = e.target.closest('[data-dash-bg]');
      if (swatch) {
        var it = selectedItem();
        if (it) { it.bg = swatch.dataset.dashBg; markDirty(); renderAll(); }
        return;
      }
      if (e.target.closest('#staxx-dash-removecolour')) {
        var it2 = selectedItem();
        if (it2) { delete it2.bg; markDirty(); renderAll(); }
        return;
      }
      var funpin = e.target.closest('[data-dash-funpin]');
      if (funpin) {
        var it3 = selectedItem();
        if (it3) { it3.stacks.splice(parseInt(funpin.dataset.dashFunpin, 10), 1); markDirty(); renderAll(); }
        return;
      }
      var step = e.target.closest('[data-dash-step]');
      if (step) {
        var dir = parseInt(step.dataset.dashDir, 10);
        if (step.dataset.dashStep === 'cols') stepCols(dir); else stepRows(dir);
        return;
      }
    });
    els.right.addEventListener('input', function (e) {
      if (e.target.id === 'staxx-dash-fname') {
        var it = selectedItem();
        if (it) { it.name = e.target.value; markDirty(); renderCanvas(); }
      }
    });
    els.right.addEventListener('change', function (e) {
      if (e.target.id === 'staxx-dash-auto') {
        var it = selectedItem();
        if (!it) return;
        it.icon = e.target.checked ? 'auto' : (it._prevIcon || 'plain:folder');
        if (!e.target.checked) it._prevIcon = it._prevIcon || it.icon;
        markDirty(); renderAll();
      }
      if (e.target.id === 'staxx-dash-eyedrop') {
        var it2 = selectedItem();
        if (it2) { it2.bg = e.target.value; markDirty(); renderAll(); }
      }
    });

    // ---- picker dialog ----
    pickerDlg = document.createElement('dialog');
    pickerDlg.className = 'staxx-dash-picker';
    pickerDlg.setAttribute('aria-labelledby', 'staxx-dash-picker-title');
    pickerDlg.innerHTML =
      '<div class="staxx-dash-head">' +
        '<h3 class="staxx-dash-title" id="staxx-dash-picker-title">Choose an icon</h3>' +
        '<button type="button" class="staxx-dash-closebtn" id="staxx-dash-picker-close"><i class="fa fa-times"></i> Close</button>' +
      '</div>' +
      '<div class="staxx-tabstrip staxx-dash-picker-tabs" id="staxx-dash-picker-tabs" role="tablist"></div>' +
      '<div class="staxx-dash-picker-tools" id="staxx-dash-picker-tools"></div>' +
      '<div class="staxx-dash-picker-grid" id="staxx-dash-picker-grid"></div>' +
      '<p class="staxx-dash-picker-credit" id="staxx-dash-picker-credit"></p>' +
      '<div class="staxx-dash-picker-foot">' +
        '<div class="staxx-dash-picker-preview" id="staxx-dash-picker-preview"></div>' +
        '<div class="staxx-buttons staxx-buttons--inline">' +
          '<button type="button" class="staxx-btn" id="staxx-dash-picker-cancel">Cancel</button>' +
          '<button type="button" class="staxx-btn" id="staxx-dash-picker-use"disabled>Use this icon</button>' +
        '</div>' +
      '</div>';
    (document.querySelector('.staxx-scaffold') || document.body).appendChild(pickerDlg);

    els.pickerTabs = pickerDlg.querySelector('#staxx-dash-picker-tabs');
    els.pickerTools = pickerDlg.querySelector('#staxx-dash-picker-tools');
    els.pickerGrid = pickerDlg.querySelector('#staxx-dash-picker-grid');
    els.pickerCredit = pickerDlg.querySelector('#staxx-dash-picker-credit');
    els.pickerPreview = pickerDlg.querySelector('#staxx-dash-picker-preview');
    els.pickerUse = pickerDlg.querySelector('#staxx-dash-picker-use');

    pickerDlg.querySelector('#staxx-dash-picker-close').addEventListener('click', function () { pickerDlg.close(); });
    pickerDlg.querySelector('#staxx-dash-picker-cancel').addEventListener('click', function () { pickerDlg.close(); });
    els.pickerUse.addEventListener('click', commitPicker);

    els.pickerTabs.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-dash-picker-tab]');
      if (!btn) return;
      Array.prototype.forEach.call(els.pickerTabs.querySelectorAll('.staxx-tab'), function (t) { t.classList.remove('staxx-tab--on'); });
      btn.classList.add('staxx-tab--on');
      picker.tab = btn.dataset.dashPickerTab;
      picker.search = '';
      loadPickerTab();
    });

    els.pickerTools.addEventListener('input', function (e) {
      if (e.target.id === 'staxx-dash-picker-search') { picker.search = e.target.value; renderPickerGrid(); }
    });
    els.pickerTools.addEventListener('change', function (e) {
      if (e.target.id === 'staxx-dash-picker-collection') { picker.collection = e.target.value; fetchPickerFiles(); }
    });

    els.pickerGrid.addEventListener('click', function (e) {
      var glyphTile = e.target.closest('[data-dash-plain]');
      if (glyphTile) {
        pickerTilePicked(glyphTile);
        picker.picked = glyphTile.dataset.dashPlain;
        els.pickerPreview.innerHTML = '<i class="fa fa-' + esc(picker.picked) + ' staxx-dash-glyph"></i> <span>Plain: ' + esc(picker.picked) + '</span>';
        els.pickerUse.disabled = false;
        return;
      }
      var tile = e.target.closest('[data-dash-pick-file]');
      if (tile) {
        pickerTilePicked(tile);
        picker.picked = tile.dataset.dashPickFile;
        var label = SOURCE_TABS.filter(function (t) { return t.key === picker.tab; })[0].label;
        els.pickerPreview.innerHTML = '<img src="' + tile.querySelector('img').src + '" class="staxx-dash-pick-thumb" alt="">' +
          '<span>' + esc(label) + ': ' + esc(tile.dataset.dashPickName) + '</span>';
        els.pickerUse.disabled = false;
      }
    });
  }

  /* --------------------------------------------------------------- open - */

  function open() {
    buildDialogsOnce();
    call('dash_state', {}).then(function (res) {
      if (!res.ok) {
        els.msg && (els.msg.textContent = res.error || 'Could not open the dashboard tile.');
      }
      state = res.ok ? res : { layout: { version: 1, cols: 5, rows: 3, items: [] }, iconUrls: {}, stacks: {}, sets: [] };
      if (!state.layout) state.layout = { version: 1, cols: 5, rows: 3, items: [] };
      state.iconUrls = state.iconUrls || {};
      state.stacks = state.stacks || {};
      state.sets = state.sets || [];
      dirty = false;
      selectedIdx = -1;
      renderAll();
      editorDlg.showModal();
    });
  }

  /* The picker on its own, for the Stacks page's folder icons. It needs the
   * icon sets' credit lines, which dash_state carries, so that is fetched
   * once. onPicked gets {icon, url} after the file is on the box. */
  function pickIcon(onPicked) {
    buildDialogsOnce();
    var show = function () { openPicker({}, onPicked); };
    if (state && state.sets && state.sets.length) { show(); return; }
    call('dash_state', {}).then(function (res) {
      state = res.ok ? res : { layout: { version: 1, cols: 5, rows: 3, items: [] }, iconUrls: {}, stacks: {}, sets: [] };
      state.iconUrls = state.iconUrls || {};
      state.stacks = state.stacks || {};
      state.sets = state.sets || [];
      show();
    });
  }

  window.staxxDashEditor = { open: open, pickIcon: pickIcon };
})();
