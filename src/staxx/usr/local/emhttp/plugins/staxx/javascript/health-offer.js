/* StaXX — deciding whether to offer a health check, and if so which one.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * PLAN_108 stage 3: a health check is only ever offered when there is a real
 * question to ask ("did the database answer a query?"), never a question that
 * can only ever say yes ("is the port open?"). This file is the pure chooser
 * — it takes facts already gathered elsewhere (the image's own declared
 * check, the compose file's existing one, the matched db-images.json entry,
 * the trial result) and picks the best source in the plan's fixed order. It
 * runs nothing itself and touches no container; that is what the caller (and
 * the trial step) is for.
 *
 * Same dual shape as db-images.js: a plain browser global under
 * `window.StaxxHealthOffer`, and a `module.exports` so tests/health_offer.js
 * can `require()` it directly under Node.
 */

(function () {
  'use strict';

  // A '$' or '${' in the recipe's shell text names a container-side
  // environment variable — e.g. "$$POSTGRES_PASSWORD" reaches the shell as
  // "$POSTGRES_PASSWORD" once compose has undoubled it. Offering a recipe
  // whose variable is not actually set in this service's environment would
  // hand the container a command that can only ever fail, which is exactly
  // the "looks broken forever" trap the plan warns about — so every name is
  // pulled out and checked against the service's own environment first.
  //
  // A reference written with a shell fallback — "$${VAR:-default}" — is not
  // one of those: the container's own shell supplies the default when the
  // variable is unset, so the recipe still works with nothing filled in.
  // That form is stripped out before collecting names, from the inside out,
  // so a default's own text can itself reference a further-defaulted name
  // (postgres's recipe nests one this way) without either being reported as
  // required.
  var DEFAULTED_RE = /\$\$\{[A-Za-z_][A-Za-z0-9_]*:-[^{}]*\}/g;
  var VAR_RE = /\$\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/g;

  function stripDefaultedRefs(text) {
    var prev;
    do {
      prev = text;
      text = text.replace(DEFAULTED_RE, '');
    } while (text !== prev);
    return text;
  }

  function referencedVars(test) {
    var names = [];
    (test || []).forEach(function (part) {
      if (typeof part !== 'string') return;
      var m;
      var stripped = stripDefaultedRefs(part);
      VAR_RE.lastIndex = 0;
      while ((m = VAR_RE.exec(stripped))) names.push(m[1]);
    });
    return names;
  }

  function envHasAll(names, env) {
    env = env || {};
    for (var i = 0; i < names.length; i++) {
      var v = env[names[i]];
      if (v === undefined || v === null || v === '') return false;
    }
    return true;
  }

  function noOffer(reason) {
    return { offer: null, source: null, claim: null, reason: reason };
  }

  function webPingOffer(port, tools) {
    var test, claim;
    if (tools && tools.curl) {
      test = ['CMD-SHELL', 'curl -fsS http://localhost:' + port + '/ -o /dev/null'];
    } else if (tools && tools.wget) {
      test = ['CMD-SHELL', 'wget -q -O /dev/null http://localhost:' + port + '/'];
    } else {
      return null;
    }
    claim = 'this answers its web page, and nothing more — it does not confirm anything behind it';
    return {
      offer: { test: test, interval: '30s', timeout: '5s', retries: 3, start_period: '30s' },
      source: 'web-ping',
      claim: claim,
      reason: null
    };
  }

  /* facts: { image, ownCheck, fileCheck, dbEntry, env, published, webPort, tools } */
  function chooseHealthCheck(facts) {
    facts = facts || {};

    if (facts.fileCheck) return noOffer('already-in-file');
    if (facts.ownCheck) return noOffer('image-checks-itself');

    var entry = facts.dbEntry;
    if (entry && entry.healthcheck) {
      var hc = entry.healthcheck;
      var needed = referencedVars(hc.test);
      if (!envHasAll(needed, facts.env)) return noOffer('recipe-needs-a-value');
      return {
        offer: {
          test: hc.test.slice(),
          interval: hc.interval,
          timeout: hc.timeout,
          retries: hc.retries,
          start_period: hc.start_period
        },
        source: 'known-image',
        claim: hc.claim,
        reason: null
      };
    }

    // Source 2 (PLAN_108): a check found in the project's own published
    // compose example. A refusal here must never fall through to some OTHER
    // way of offering the same candidate — only on to the web ping, exactly
    // as though nothing had been published at all.
    if (facts.published) {
      if (facts.tools === null || facts.tools === undefined) return noOffer('needs-a-trial');
      var accepted = acceptPublishedCheck(facts.published.test, facts.tools);
      if (accepted.accepted) {
        return {
          offer: {
            test: facts.published.test.slice(),
            interval: facts.published.interval,
            timeout: facts.published.timeout,
            retries: facts.published.retries,
            start_period: facts.published.start_period
          },
          source: 'published',
          claim: publishedClaim(facts.published.test),
          reason: null
        };
      }
      // Refused by the narrow door — carry on as though there had been no
      // published check, never offer the refused shape some other way.
    }

    if (facts.webPort > 0) {
      if (facts.tools === null || facts.tools === undefined) return noOffer('needs-a-trial');
      var ping = webPingOffer(facts.webPort, facts.tools);
      if (ping) return ping;
    }

    return noOffer('nothing-to-ask');
  }

  /* =====================================================================
   * acceptPublishedCheck — PLAN_108 stage 7's narrow door. A command found
   * in somebody else's published compose example is accepted only if it
   * already matches a shape StaXX writes itself: a plain curl/wget of a
   * local URL, or a call to a client tool named by one of our own recipes.
   * Everything else is discarded unread — no attempt to sanitise or repair
   * it, and nothing about it is logged, because the whole point is that a
   * shape we don't recognise gets no trust at all rather than a best effort.
   * ===================================================================== */

  // Matches exactly the two forms this file itself builds in webPingOffer,
  // loosened only enough to accept the tag/query differences another
  // author's own file might have. Anchored start-to-end, so anything with
  // extra shell syntax tacked on either end — a ";", a "|", a redirection
  // that isn't the "-o"/"-O /dev/null" these two forms use — simply fails to
  // match and falls through to the rejection at the bottom, rather than
  // needing its own metacharacter check.
  var CURL_LOCAL_RE = /^curl\s+-fsS\s+https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/\S*\s+-o\s+\/dev\/null$/;
  var WGET_LOCAL_RE = /^wget\s+-q\s+-O\s+\/dev\/null\s+https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/\S*$/;

  // A command's first *meaningful* word — skipping any "NAME=value" env
  // assignments a recipe puts in front of it (postgres's does), and reduced
  // to a bare basename (mssql's recipe names a full path) — is what actually
  // identifies which client tool runs. That is the one thing this function
  // trusts about a candidate command; nothing after it is inspected.
  function toolName(text) {
    var words = String(text).trim().split(/\s+/);
    var i = 0;
    while (i < words.length && /^[A-Za-z_][A-Za-z0-9_]*=/.test(words[i])) i++;
    var word = words[i] || '';
    var slash = word.lastIndexOf('/');
    return slash >= 0 ? word.slice(slash + 1) : word;
  }

  function knownClientTools() {
    var db = typeof module !== 'undefined' && module.exports ? require('./db-images.js') :
      (typeof window !== 'undefined' ? window.StaxxDbImages : null);
    var tools = {};
    (db ? db.images : []).forEach(function (entry) {
      if (!entry.healthcheck || !Array.isArray(entry.healthcheck.test)) return;
      // test[1] is either argv[0] of a CMD array or the whole CMD-SHELL
      // string — either way toolName() finds the client tool inside it.
      var name = toolName(entry.healthcheck.test[1]);
      if (name) tools[name] = true;
    });
    return tools;
  }

  // What a published check actually proves, worked out from its shape rather
  // than asserted — the same which-claim-is-being-made rule PLAN_107 applies
  // to the row itself. Only ever called on a candidate acceptPublishedCheck()
  // has already accepted, so exactly one of the two shapes always matches.
  function publishedClaim(test) {
    var text = test.slice(1).join(' ').trim();
    if (CURL_LOCAL_RE.test(text) || WGET_LOCAL_RE.test(text)) {
      return "the project's own published example answers its web page, and nothing more — it does not confirm anything behind it";
    }
    return "the project's own published example uses a real client tool to ask this app something real";
  }

  function acceptPublishedCheck(test, tools) {
    tools = tools || {};
    if (!Array.isArray(test) || test.length < 2) return { accepted: false, why: 'shape-not-recognised' };
    if (test[0] !== 'CMD' && test[0] !== 'CMD-SHELL') return { accepted: false, why: 'shape-not-recognised' };

    var text = test.slice(1).join(' ').trim();
    if (!text) return { accepted: false, why: 'shape-not-recognised' };

    if (CURL_LOCAL_RE.test(text)) {
      return tools.curl ? { accepted: true } : { accepted: false, why: 'shape-not-recognised' };
    }
    if (WGET_LOCAL_RE.test(text)) {
      return tools.wget ? { accepted: true } : { accepted: false, why: 'shape-not-recognised' };
    }

    // The client-tool branch trusts only that the named tool runs — it must
    // therefore refuse anything that could run a SECOND thing alongside it,
    // or feed the first with a substitution, before it ever looks at which
    // word is first. Without this a candidate like
    // '["CMD-SHELL", "redis-cli -a x ; rm -rf /"]' would pass — toolName()
    // finds "redis-cli", stops looking, and the semicolon's payload rides
    // along unread into somebody's compose file. Reject on sight; never
    // strip and retry. Our OWN recipes legitimately use '|' (redis pipes its
    // reply into grep) and '$$' (several read an environment variable), but
    // this function is only ever asked about somebody ELSE's published file,
    // where none of that is trusted — so the rule stays strict here even
    // though it would reject shapes this project's own table produces.
    if (/[;&|`<>#\n]|\$\(|\$\{/.test(text)) return { accepted: false, why: 'shape-not-recognised' };

    var name = toolName(text);
    var known = knownClientTools();
    if (known[name] && tools[name]) return { accepted: true };

    return { accepted: false, why: 'shape-not-recognised' };
  }

  var API = {
    chooseHealthCheck: chooseHealthCheck,
    acceptPublishedCheck: acceptPublishedCheck
  };

  if (typeof window !== 'undefined') window.StaxxHealthOffer = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
