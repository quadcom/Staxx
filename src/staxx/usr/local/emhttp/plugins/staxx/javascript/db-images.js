/* StaXX — the lookup for well-known database images, matched against the
 * environment settings each one uses for a username, a password and a
 * database name.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * PLAN_70 stage 5 (10.2): guessing a secret from a setting's *name* is
 * explicitly refused elsewhere in this project, so the only honest way to
 * recognise "this is the password box" for a handful of very common images
 * is to say so outright, for images checked one at a time against their own
 * documentation.
 *
 * THE TABLE ITSELF now lives in data/db-images.json — the one copy the
 * server (include/CrossLinks.php) and this file both read, so the two can no
 * longer disagree. In the browser it arrives on the page, in the
 * `.staxx-scaffold` element's `data-db-images` attribute, the same handoff
 * StacksPage.php already uses for the CSRF token and the folder list. Under
 * Node — tests/db_images.js — it is required directly from disk. Either way
 * a missing or unreadable table throws rather than quietly matching nothing,
 * because that failure looks exactly like a working system that never
 * matches anything.
 */

(function () {
  'use strict';

  function loadImages() {
    if (typeof window !== 'undefined') {
      var el = document.querySelector('.staxx-scaffold');
      var raw = el && el.dataset ? el.dataset.dbImages : undefined;
      if (!raw) {
        throw new Error('The database credentials table did not arrive with the page — reload it. ' +
          'If this keeps happening, the plugin\'s data/db-images.json file is missing or unreadable on the server.');
      }
      var parsed;
      try { parsed = JSON.parse(raw); } catch (e) {
        throw new Error('The database credentials table sent with the page is not valid JSON: ' + e.message);
      }
      if (!parsed || !Array.isArray(parsed.images)) {
        throw new Error('The database credentials table sent with the page is missing its image list.');
      }
      return parsed.images;
    }

    // Node — required directly for tests/db_images.js, using the exact bytes
    // the server and browser both read.
    var fs = require('fs');
    var path = require('path');
    var file = path.join(__dirname, '..', 'data', 'db-images.json');
    var text;
    try { text = fs.readFileSync(file, 'utf8'); } catch (e) {
      throw new Error('The database credentials table could not be read from ' + file + ': ' + e.message);
    }
    var data = JSON.parse(text);
    if (!data || !Array.isArray(data.images)) {
      throw new Error('The database credentials table at ' + file + ' is missing its image list.');
    }
    return data.images;
  }

  var IMAGES = loadImages();

  /* =====================================================================
   * Lookup — turns an image reference as written in a compose file into
   * the bare "name" or "namespace/name" form the table above is keyed on.
   * ===================================================================== */

  // A leading path segment is a registry host, not a Docker Hub namespace,
  // when it looks like one: it has a dot or a port, or is "localhost".
  // Anything else — "library", "linuxserver", "bitnami" — is a namespace.
  function looksLikeRegistry(segment) {
    return segment === 'localhost' || segment.indexOf('.') >= 0 || segment.indexOf(':') >= 0;
  }

  function normaliseRef(ref) {
    if (typeof ref !== 'string' || !ref) return '';

    // Digest pin: "name@sha256:...". Whatever is after "@" is never part of
    // the name, so it is dropped whole rather than parsed.
    var at = ref.indexOf('@');
    var withoutDigest = at >= 0 ? ref.slice(0, at) : ref;

    var segments = withoutDigest.split('/');
    if (segments.length > 1 && looksLikeRegistry(segments[0])) {
      segments = segments.slice(1);
    }

    // The tag, if any, is only ever on the last segment, and only after the
    // last colon in it — a registry port was already stripped above, so a
    // colon reaching here is always a tag separator.
    var last = segments[segments.length - 1];
    var colon = last.lastIndexOf(':');
    if (colon >= 0) segments[segments.length - 1] = last.slice(0, colon);

    // "library/x" is Docker Hub's implicit namespace for official images —
    // collapse it to match how every entry above names them.
    if (segments.length === 2 && segments[0] === 'library') {
      segments = segments.slice(1);
    }

    return segments.join('/');
  }

  function lookupImage(ref) {
    var name = normaliseRef(ref);
    if (!name) return null;
    for (var i = 0; i < IMAGES.length; i++) {
      if (IMAGES[i].images.indexOf(name) >= 0) return IMAGES[i];
    }
    return null;
  }

  var API = {
    images: IMAGES,
    normaliseRef: normaliseRef,
    lookupImage: lookupImage
  };

  if (typeof window !== 'undefined') window.StaxxDbImages = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
