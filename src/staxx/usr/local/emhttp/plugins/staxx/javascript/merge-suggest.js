/* StaXX — turning the merge wizard's step 5 answers into real file lines.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * PLAN_155's sixth step ("Suggestions") never feeds back into
 * buildMergedText() — it starts from the merged text steps 3-4 already
 * settled (mergeState.built.text) and layers depends_on, healthcheck and
 * x-unraid.update on top of it, using compose-model.js's own writers (the
 * same ones the editor's form uses — see that file's addNested/writeTest)
 * so the editor and this wizard can never write two different shapes for
 * the same field.
 *
 * The one rule this module exists to enforce: a waiting service may only
 * be given `condition: service_healthy` when its target actually HAS a
 * health check — already covered (the file's own healthcheck, or the
 * image's declared one) or genuinely written by this same call — never
 * merely "the target has `on` ticked", since a health row can be ticked on
 * with nothing decided yet (source 'later' writes nothing into the file).
 * Every apply() call
 * re-derives both blocks from the caller's current answers in one pass, so
 * there is no stale state to reconcile: the wizard always calls this
 * against the same pristine `built.text`, never against a previous
 * apply() result, which is also what makes a second call with the same
 * answers a true no-op (compose-model's writers refuse to add a key that
 * is already there) rather than something this file has to guard itself.
 *
 * Same dual shape as merge-write.js: `window.StaxxMergeSuggest` in the
 * browser, `module.exports` under Node.
 */

(function () {
  'use strict';

  var CM = (typeof window !== 'undefined' && window.StaxxYaml) ||
           (typeof module !== 'undefined' && require('./compose-model.js'));
  // PLAN_155 C15 — the same "a rewired connection must land on a shared
  // network" rule the address-rewire pass applies, reused here for the
  // depends_on pairs this step draws. merge-write.js loads first on the
  // real page (StacksPage.php's own script order), so window.StaxxMergeWrite
  // is already there by the time this file runs.
  var MW = (typeof window !== 'undefined' && window.StaxxMergeWrite) ||
           (typeof module !== 'undefined' && require('./merge-write.js'));

  function servicesMapOf(doc) {
    var svc = doc.root && doc.root.kind === 'map' ? doc.root.pairs['services'] : null;
    return svc && svc.value && svc.value.kind === 'map' ? svc.value : null;
  }

  function serviceOwnMap(doc, name) {
    var svcs = servicesMapOf(doc);
    var pair = svcs ? svcs.pairs[name] : null;
    return pair && pair.value && pair.value.kind === 'map' ? pair.value : null;
  }

  function svcExists(doc, name) {
    var svcs = servicesMapOf(doc);
    return !!(svcs && svcs.pairs[name]);
  }

  function serviceHasHealthcheck(doc, name) {
    var svc = serviceOwnMap(doc, name);
    return !!(svc && svc.pairs['healthcheck']);
  }

  // depends_on.<to> already existing (any shape, including short-form
  // depends_on: [a, b], where 'depends_on' itself is not a map) is left
  // alone — addNested already refuses a non-map level or an existing key,
  // so this is only here to short-circuit before spending a parse on it.
  function dependsOnAlready(doc, from, to) {
    var svc = serviceOwnMap(doc, from);
    var dep = svc ? svc.pairs['depends_on'] : null;
    if (!dep || !dep.value || dep.value.kind !== 'map') return dep ? true : false;
    return !!dep.value.pairs[to];
  }

  /**
   * Writes one service's health.test/interval/timeout/retries into the
   * document — but never for a row marked `covered` (the source file
   * already carries a healthcheck, or the image declares its own; either
   * way there is nothing to add — see stacks.js's own mergeSuggestSyncHealth
   * and the async image-facts lookup in mergeEnterStep5). `test` is the
   * Docker-shaped array (`['CMD-SHELL', 'curl ...']` or `['CMD', ...]` or
   * `['NONE']`) the same shape health-offer.js and
   * staxx_parse_image_healthcheck() both use, so a hand-written check can
   * be handed straight through with no translation step of its own. Only
   * `source: 'own'` ever reaches here — 'later' ("StaXX will work one out
   * once the stack is running") writes nothing now, on purpose (PLAN_155:
   * "No known-image shortcut writes a check into the file at this step").
   */
  function writeHealthcheck(doc, service, h) {
    if (!h || h.covered || !h.on || h.source !== 'own') return;
    if (!svcExists(doc, service) || serviceHasHealthcheck(doc, service)) return;
    var test = h.test || [];
    var mode = test[0] === 'NONE' ? 'none' : (test[0] === 'CMD-SHELL' ? 'shell' : (test[0] === 'CMD' ? 'cmd' : null));
    if (!mode) return;   // nothing typed yet (the form was opened but left blank) — write nothing rather than guess
    var command = test.slice(1).join(' ');
    if (!CM.writeTest(doc, null, service, mode, command)) return;

    ['interval', 'timeout', 'retries'].forEach(function (key) {
      var value = h[key];
      if (value === undefined || value === null || value === '') return;
      CM.addNested(doc, null, service, ['healthcheck', key], value);
    });
  }

  // service_healthy only when the TARGET is getting a real check — either
  // one it already has (`covered`: the source file's own healthcheck, or
  // the image's declared one — Docker runs either unasked) or one this
  // same call is actually writing (source 'own' with a real test typed
  // in). A health row ticked on with nothing decided yet (source 'later')
  // is exactly the case that must still downgrade to service_started,
  // since nothing lands in the file for compose to watch.
  function targetIsHealthy(health, to) {
    var h = health[to];
    if (h && h.covered) return true;
    return !!(h && h.on && h.source === 'own' && h.test && h.test.length);
  }

  function writeDependsOn(doc, from, to, condition) {
    if (!svcExists(doc, from) || !svcExists(doc, to)) return;
    if (dependsOnAlready(doc, from, to)) return;
    CM.addNested(doc, null, from, ['depends_on', to, 'condition'], condition);
    // PLAN_155 C15 — a depends_on line is one more way this file expects
    // to reach `to` by name; if the two are not already on a shared
    // network, join `from` onto one of `to`'s. Never removes anything and
    // is a no-op when `from` has no networks: to join onto (network_mode).
    if (MW && MW.joinNetworkIfNeeded) MW.joinNetworkIfNeeded(doc, from, to);
  }

  // addRootNested refuses outright when the file has no root x-unraid:
  // block yet (it has nowhere to put a fresh child) — a merged file only
  // carries one when a source's own stack-level metadata was picked on
  // step 2, so this is the common case, not the exception. Unlike the
  // editor's own "fill in details" flow this never scaffolds placeholder
  // comments for every OTHER field: a bare "x-unraid:" is enough to give
  // addRootNested somewhere to write the one real value this step has.
  function ensureRootXUnraid(doc) {
    if (doc.root && doc.root.kind === 'map' && doc.root.pairs['x-unraid']) return;
    var services = doc.root && doc.root.kind === 'map' ? doc.root.pairs['services'] : null;
    var at = services ? services.start : doc.lines.length;
    CM.splice(doc, at, 0, ['x-unraid:', '']);
  }

  // The three events the settings panel's own row shows, in the fixed
  // order they are always written — 'New image', 'Image installed',
  // 'Installation failed' (PLAN_155's step 6 ruling: the row starts at the
  // server's own answers, and touching any one switch writes all three, so
  // there is no partial or "say nothing" state per key any more — that
  // only ever existed for a container already on disk, which needs a
  // Default state to hand an event back to the server; a stack this wizard
  // is writing was never set, so it has nothing to fall back to).
  var NOTIFY_KEYS = ['found', 'installed', 'failed'];

  /**
   * mode 'manual'/'auto' write themselves; 'default' means "say nothing,
   * inherit the global setting" and writes no mode key at all. 'auto' only
   * ever carries a delay key when immediate is true (delay: 0 — installed
   * as soon as it is found); left off otherwise so the stack falls back to
   * the server's own delay length rather than this wizard inventing one.
   *
   * `notify` is `{touched, found, installed, failed}`. `touched` false
   * (or the whole `notify` object missing) means the row was left exactly
   * as the server answered it — nothing is written, even though the
   * caller still passes the server's own current found/installed/failed
   * readings alongside it. `touched` true means the person flipped at
   * least one switch, so the file has to say exactly what the row now
   * shows — all three keys are written, in order, as real booleans. There
   * is no longer a middle state where only the changed key is written: the
   * OLD single boolean `notify` and PLAN_154's null-per-key partial object
   * are both things this wizard reads on a file it did not write itself,
   * never something it writes again. A block with nothing to say at all
   * writes no x-unraid.update: {} placeholder — there is nothing to hang
   * one on.
   */
  function writeUpdateBlock(doc, u) {
    u = u || {};
    // `bare` (emitScalar's third style) is what keeps a real boolean or
    // number from being force-quoted — needsQuoting() deliberately quotes
    // a bare "true"/"false" everywhere else in this file, because most
    // callers write literal env-style text where that word must stay a
    // string. mode/manual/auto need no such thing: neither word trips
    // needsQuoting(), so they are written as ordinary plain scalars.
    var fields = [];
    if (u.mode === 'manual') {
      fields.push(['mode', 'manual', false]);
    } else if (u.mode === 'auto') {
      fields.push(['mode', 'auto', false]);
      if (u.immediate) fields.push(['delay', 0, true]);
    }
    var notify = u.notify || {};
    var writeNotify = !!notify.touched;
    if (!fields.length && !writeNotify) return;

    ensureRootXUnraid(doc);
    var xu = doc.root.pairs['x-unraid'].value;
    if (!xu || !xu.pairs || !xu.pairs['update']) {
      CM.addRootNested(doc, null, ['x-unraid', 'update'], null);
    }
    fields.forEach(function (f) {
      CM.addRootNested(doc, null, ['x-unraid', 'update', f[0]], f[1], f[2]);
    });
    if (writeNotify) {
      // `update` may still have no children at all here (fields was empty —
      // notify is the only thing this call is writing), in which case its
      // own value has not become a map yet; addRootNested's own ensurePath
      // handles that the same way it handles a brand-new 'update' itself.
      var upd = doc.root.pairs['x-unraid'].value.pairs['update'].value;
      // A pre-existing `notify` — the old boolean spelling, carried into
      // the merged text from a source stack — is left exactly alone:
      // addRootNested refuses to add a key that is already there, the same
      // "never overwrite, only add" rule every writer in this file follows.
      if (!upd || !upd.pairs || !upd.pairs['notify'] || upd.pairs['notify'].value.kind !== 'map') {
        CM.addRootNested(doc, null, ['x-unraid', 'update', 'notify'], null);
      }
      NOTIFY_KEYS.forEach(function (k) {
        CM.addRootNested(doc, null, ['x-unraid', 'update', 'notify', k], !!notify[k], true);
      });
    }
  }

  // `before` is always a genuine in-order subsequence of `after`: every
  // writer above only ever inserts lines (CM.splice's `remove` argument is
  // always 0 here), never removes or reorders one. A plain two-pointer scan
  // is therefore exact — not merely a good-enough diff — and far cheaper
  // than an LCS table for a file that can run to a few hundred lines.
  function addedLines(before, after) {
    var added = [];
    var i = 0;
    for (var j = 0; j < after.length; j++) {
      if (i < before.length && after[j] === before[i]) i++;
      else added.push(j);
    }
    return added;
  }

  /**
   * suggest = {
   *   deps:   [{from, to}, ...],
   *   health: { <service>: {covered} | {on, source, test, interval, timeout, retries} },
   *   update: {mode, immediate, notify: {touched, found, installed, failed}}
   * }
   * Returns {text, added} — added is every 0-based line index apply()
   * itself inserted, for the right-hand pane's green highlight.
   */
  function apply(text, suggest) {
    suggest = suggest || {};
    var doc = CM.parse(text || '');
    var before = doc.lines.slice();
    var health = suggest.health || {};

    Object.keys(health).forEach(function (service) {
      writeHealthcheck(doc, service, health[service]);
    });

    (suggest.deps || []).forEach(function (d) {
      if (!d || !d.from || !d.to) return;
      writeDependsOn(doc, d.from, d.to, targetIsHealthy(health, d.to) ? 'service_healthy' : 'service_started');
    });

    writeUpdateBlock(doc, suggest.update);

    return { text: CM.serialise(doc), added: addedLines(before, doc.lines) };
  }

  var API = { apply: apply };

  if (typeof window !== 'undefined') window.StaxxMergeSuggest = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
