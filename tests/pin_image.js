/* StaXX — tests for pinning an image to a digest.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 *   node tests/pin_image.js
 *
 * No framework, no npm, no network — the same shape as stash_guard.js: one
 * line per case, and a non-zero exit if anything fails.
 *
 * pinnedImageRef() is the one place that turns an image reference plus a
 * digest into a pinned reference, kept in compose-model.js specifically
 * because that file is requireable from node — the front end that will
 * actually call it is not. This file proves the rule holds, including the
 * one case (a digest with no tag) that broke the first draft: a digest
 * itself contains a colon, so the tag-separator search has to work on the
 * part before "@", not the whole string, or it finds the colon inside the
 * old digest instead of a real tag separator.
 */

'use strict';

var Y = require('../src/staxx/usr/local/emhttp/plugins/staxx/javascript/compose-model.js');

var check = require('./lib/check.js'), ok = check.ok;

// 32 hex characters — the minimum this project's digest shape accepts.
var GOOD_DIGEST = 'sha256:' + 'abcdef0123456789abcdef0123456789';

console.log('\n1. Ordinary references — appended or replaced correctly');

(function () {
  var r = Y.pinnedImageRef('jellyfin/jellyfin:latest', GOOD_DIGEST);
  ok('plain repo:tag gets the digest appended, tag kept',
     r.ok === true && r.ref === 'jellyfin/jellyfin:latest@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('jellyfin/jellyfin', GOOD_DIGEST);
  ok('no tag at all — digest still appended',
     r.ok === true && r.ref === 'jellyfin/jellyfin@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('myhost:5000/app/thing:1.2', GOOD_DIGEST);
  ok('a registry port is not mistaken for the tag separator',
     r.ok === true && r.ref === 'myhost:5000/app/thing:1.2@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('ghcr.io/app/thing:${TAG}', GOOD_DIGEST);
  ok('a variable tag is left untouched and the digest still appended',
     r.ok === true && r.ref === 'ghcr.io/app/thing:${TAG}@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('${IMAGE}', GOOD_DIGEST);
  ok('a reference that is entirely a variable is refused',
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('ghcr.io/${OWNER}/app:1.0', GOOD_DIGEST);
  ok('a variable inside the repository part is refused',
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('jellyfin/jellyfin:latest@sha256:1111111111111111111111111111111111111111111111111111111111111111', GOOD_DIGEST);
  ok('a reference already pinned gets its digest replaced, tag still present',
     r.ok === true && r.ref === 'jellyfin/jellyfin:latest@' + GOOD_DIGEST, JSON.stringify(r));
})();

(function () {
  var r = Y.pinnedImageRef('jellyfin/jellyfin@sha256:1111111111111111111111111111111111111111111111111111111111111111', GOOD_DIGEST);
  ok('a pinned reference with no tag gets its digest replaced cleanly',
     r.ok === true && r.ref === 'jellyfin/jellyfin@' + GOOD_DIGEST, JSON.stringify(r));
})();

console.log('\n2. Digest shape — every refusal');

[
  ['empty string', ''],
  ['just the algorithm', 'sha256:'],
  ['too-short hex', 'sha256:abcd'],
  ['non-hex characters', 'sha256:zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'],
  ['a shell metacharacter', 'sha256:abcdef0123456789abcdef0123456789; rm -rf /'],
  ['a newline embedded in it', 'sha256:abcdef0123456789abcdef0123456789\nghi']
].forEach(function (c) {
  var r = Y.pinnedImageRef('jellyfin/jellyfin:latest', c[1]);
  ok('digest refused — ' + c[0],
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

console.log('\n3. Reference shape — empty and non-string inputs');

['', '   '].forEach(function (v) {
  var r = Y.pinnedImageRef(v, GOOD_DIGEST);
  ok('empty/whitespace reference refused: ' + JSON.stringify(v),
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

[null, undefined, 42, {}].forEach(function (v) {
  var r = Y.pinnedImageRef(v, GOOD_DIGEST);
  ok('non-string reference refused, not thrown: ' + JSON.stringify(v),
     r && r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

[null, undefined, 42, {}].forEach(function (v) {
  var r = Y.pinnedImageRef('jellyfin/jellyfin:latest', v);
  ok('non-string digest refused, not thrown: ' + JSON.stringify(v),
     r && r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

console.log('\n4. Releasing a pin — unpinnedImageRef()');

(function () {
  var r = Y.unpinnedImageRef('jellyfin/jellyfin:latest@' + GOOD_DIGEST);
  ok('plain pinned repo:tag has the fingerprint removed',
     r.ok === true && r.ref === 'jellyfin/jellyfin:latest', JSON.stringify(r));
})();

(function () {
  var r = Y.unpinnedImageRef('jellyfin/jellyfin@' + GOOD_DIGEST);
  ok('pinned reference with no tag has the fingerprint removed',
     r.ok === true && r.ref === 'jellyfin/jellyfin', JSON.stringify(r));
})();

(function () {
  var r = Y.unpinnedImageRef('myhost:5000/app/thing:1.2@' + GOOD_DIGEST);
  ok('a registry port survives release untouched',
     r.ok === true && r.ref === 'myhost:5000/app/thing:1.2', JSON.stringify(r));
})();

(function () {
  var r = Y.unpinnedImageRef('ghcr.io/app/thing:${TAG}@' + GOOD_DIGEST);
  ok('a variable tag is released successfully — no lookup is needed to release',
     r.ok === true && r.ref === 'ghcr.io/app/thing:${TAG}', JSON.stringify(r));
})();

// Refusals.

['', '   '].forEach(function (v) {
  var r = Y.unpinnedImageRef(v);
  ok('empty/whitespace reference refused: ' + JSON.stringify(v),
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

[null, undefined, 42, {}].forEach(function (v) {
  var r = Y.unpinnedImageRef(v);
  ok('non-string reference refused, not thrown: ' + JSON.stringify(v),
     r && r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
});

(function () {
  var r = Y.unpinnedImageRef('jellyfin/jellyfin:latest');
  ok('a reference with no "@" is refused, not silently returned unchanged',
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
})();

(function () {
  var r = Y.unpinnedImageRef('@' + GOOD_DIGEST);
  ok('a reference that is only the fingerprint is refused — release would leave no image',
     r.ok === false && typeof r.why === 'string' && r.why.length > 0, JSON.stringify(r));
})();

console.log('\n5. Round trip — pin then release gives back the original');

[
  'jellyfin/jellyfin:latest',
  'myhost:5000/app/thing:1.2',
  'jellyfin/jellyfin',
  'ghcr.io/app/thing:${TAG}'
].forEach(function (original) {
  var pinned = Y.pinnedImageRef(original, GOOD_DIGEST);
  var released = pinned.ok ? Y.unpinnedImageRef(pinned.ref) : { ok: false };
  ok('round trip preserves the original exactly: ' + original,
     pinned.ok === true && released.ok === true && released.ref === original,
     JSON.stringify({ pinned: pinned, released: released }));
});

(function () {
  var OTHER_DIGEST = 'sha256:' + '1111111111111111111111111111111111111111111111111111111111111111'.slice(0, 32);
  var original = 'jellyfin/jellyfin:latest';
  var firstPin = Y.pinnedImageRef(original, GOOD_DIGEST);
  var released = Y.unpinnedImageRef(firstPin.ref);
  var repinnedFromReleased = Y.pinnedImageRef(released.ref, OTHER_DIGEST);
  var pinnedDirectly = Y.pinnedImageRef(original, OTHER_DIGEST);
  ok('release then re-pin to a different fingerprint matches pinning the original directly',
     repinnedFromReleased.ok === true && pinnedDirectly.ok === true &&
     repinnedFromReleased.ref === pinnedDirectly.ref,
     JSON.stringify({ repinnedFromReleased: repinnedFromReleased, pinnedDirectly: pinnedDirectly }));
})();

/* The type guard, given teeth of its own.
 *
 * Every other non-string case here — null, a number, a plain object — turns
 * into text containing no "@", so the "not pinned" rule refuses it anyway
 * and the suite passes whether the type guard exists or not. It cannot tell
 * the two apart. An object that stringifies to something pinned-looking is
 * the one input where only the type guard stands between a caller and a
 * confident wrong answer, so it is the only case that really proves it. */
(function () {
  var sixtyFour = new Array(65).join('0');

  var looksPinned = { toString: function () { return 'ghcr.io/app/thing:latest@sha256:' + sixtyFour; } };
  var released = Y.unpinnedImageRef(looksPinned);
  ok('an object that stringifies to a pinned reference is refused, not released',
     released.ok === false, JSON.stringify(released));

  var looksPlain = { toString: function () { return 'ghcr.io/app/thing:latest'; } };
  var pinned = Y.pinnedImageRef(looksPlain, 'sha256:' + sixtyFour);
  ok('an object that stringifies to a plain reference is refused, not pinned',
     pinned.ok === false, JSON.stringify(pinned));
})();

console.log('\n6. pinNoteText() — the "was" note the Pinned choice adds beside the image line');

(function () {
  var r = Y.pinNoteText('', 'nginx:1.25.3');
  ok('no existing note — just "was <old ref>"', r === 'was nginx:1.25.3', r);
})();

(function () {
  var r = Y.pinNoteText('do not change without asking ops', 'nginx:1.25.3');
  ok('an existing note is kept, the "was" note joined after it — never overwritten',
     r === 'do not change without asking ops — was nginx:1.25.3', r);
})();

(function () {
  var r = Y.pinNoteText(undefined, 'nginx:1.25.3');
  ok('a missing note (undefined) behaves the same as an empty one',
     r === 'was nginx:1.25.3', r);
})();

console.log('\n7. Round trip — the whole edit "Pinned" makes to a real document');

// Mirrors stacks.js's own pinServiceToDigest(): find the image field, pin
// it, rebuild the form (a longer "@sha256:…" value can shift where a
// trailing comment sits), then add the "was" note beside whatever comment
// is there already — proven here directly against compose-model.js, since
// that is the one file requireable from node; stacks.js is browser-only.
function findField(form, service, target) {
  for (var i = 0; i < form.fields.length; i++) {
    var f = form.fields[i];
    if (f.service === service && f.target === target) return f;
  }
  return null;
}

(function () {
  var text =
    '# a stack with an anchor and a hand-written note on the image line\n' +
    'x-common: &common\n' +
    '  restart: unless-stopped\n' +
    'services:\n' +
    '  web:\n' +
    '    <<: *common\n' +
    '    image: nginx:1.25.3   # do not touch — ops pin\n' +
    '    ports:\n' +
    '      - "8080:80"\n';

  var doc = Y.parse(text);
  var form = Y.buildForm(doc);
  var field = findField(form, 'web', 'image');
  var image = field.parts.value.value;

  var pinned = Y.pinnedImageRef(image, GOOD_DIGEST);
  ok('the image line pins correctly', pinned.ok === true, JSON.stringify(pinned));
  ok('setValue() writes the pinned reference', Y.setValue(doc, form, field.id, pinned.ref));

  var freshForm = Y.buildForm(doc);
  var freshField = null;
  for (var i = 0; i < freshForm.fields.length; i++) {
    if (freshForm.fields[i].id === field.id) { freshField = freshForm.fields[i]; break; }
  }
  ok('the same field id is found again after the rewrite', !!freshField);

  var note = Y.pinNoteText(freshField.note, image);
  ok('the existing hand-written note is kept and joined',
     note === 'do not touch — ops pin — was nginx:1.25.3', note);
  ok('setComment() writes the combined note',
     Y.setComment(doc, freshForm, freshField.id, note, !!freshField.secret, !!freshField.required));

  var out = Y.serialise(doc);
  ok('the anchor/alias survive untouched',
     out.indexOf('&common') !== -1 && out.indexOf('<<: *common') !== -1, out);
  ok('a sibling line (ports) is untouched',
     out.indexOf('- "8080:80"') !== -1, out);
  ok('the pinned image line carries the digest, the old value, and the ' +
     'original note, all on one line',
     out.indexOf('image: nginx:1.25.3@' + GOOD_DIGEST +
                  '   # do not touch — ops pin — was nginx:1.25.3') !== -1,
     out);

  // Releasing it — unpinnedImageRef() only ever drops the pin itself; the
  // note (now describing history rather than the present) is left exactly
  // as this rewrite made it, matching CLAUDE.md rule 2: fix or change
  // something and say so, never edit it silently as a side effect of an
  // unrelated action.
  var released = Y.unpinnedImageRef(pinned.ref);
  ok('the release mirrors it back to the plain reference',
     released.ok === true && released.ref === 'nginx:1.25.3', JSON.stringify(released));
})();

console.log('\n8. Choosing the file — an override wins only when it sets its own image:');

// Mirrors findImageOwner()'s own decision in stacks.js: parse whatever
// override text sits beside the main file, look for THIS service's own
// image field, and treat it as the owner only when it actually holds a
// value — an override that touches the service for some other reason
// (a port, an environment variable) must not be mistaken for one that
// pins the image, or a pin meant for the main file would silently vanish
// into an override line that was never written.
(function () {
  var overrideNoImage = 'services:\n  web:\n    ports:\n      - "9090:80"\n';
  var form = Y.buildForm(Y.parse(overrideNoImage));
  var field = findField(form, 'web', 'image');
  var setsImage = !!(field && field.parts.value && String(field.parts.value.value).trim() !== '');
  ok('an override that never mentions the image is read as NOT setting it',
     !setsImage, JSON.stringify(field && field.parts.value));
})();

(function () {
  var overrideWithImage = '# pin the sidecar build here, not in the main file\n' +
    'services:\n  web:\n    image: nginx:1.25.3\n';
  var form = Y.buildForm(Y.parse(overrideWithImage));
  var field = findField(form, 'web', 'image');
  var setsImage = !!(field && field.parts.value && String(field.parts.value.value).trim() !== '');
  ok('an override that names its own image: is read as setting it',
     setsImage && field.parts.value.value === 'nginx:1.25.3', JSON.stringify(field && field.parts.value));
})();

(function () {
  // The pin itself lands in the override exactly as it would in the main
  // file — same helpers, same "was" note, same untouched sibling comment —
  // proving the override is not a second-class target once it does own
  // the image.
  var overrideText = '# this stack’s own override\n' +
    'services:\n  web:\n    image: nginx:1.25.3   # do not touch\n' +
    '    environment:\n      - LOG_LEVEL=debug\n';

  var doc = Y.parse(overrideText);
  var form = Y.buildForm(doc);
  var field = findField(form, 'web', 'image');
  var image = field.parts.value.value;

  var pinned = Y.pinnedImageRef(image, GOOD_DIGEST);
  ok('the override\'s own image line pins correctly', pinned.ok === true, JSON.stringify(pinned));
  ok('setValue() writes it into the override', Y.setValue(doc, form, field.id, pinned.ref));

  var freshForm = Y.buildForm(doc);
  var freshField = null;
  for (var i = 0; i < freshForm.fields.length; i++) {
    if (freshForm.fields[i].id === field.id) { freshField = freshForm.fields[i]; break; }
  }
  var note = Y.pinNoteText(freshField.note, image);
  ok('setComment() writes the "was" note into the override',
     Y.setComment(doc, freshForm, freshField.id, note, !!freshField.secret, !!freshField.required));

  var out = Y.serialise(doc);
  ok('the override\'s own top-of-file comment survives',
     out.indexOf('# this stack’s own override') !== -1, out);
  ok('the sibling environment line in the override is untouched',
     out.indexOf('LOG_LEVEL=debug') !== -1, out);
  ok('the override\'s image line now carries the digest, the old value and the note',
     out.indexOf('image: nginx:1.25.3@' + GOOD_DIGEST + '   # do not touch — was nginx:1.25.3') !== -1,
     out);
})();

console.log('\n9. unpinNoteText() — releasing removes only the "was …" fragment a pin added');

(function () {
  var r = Y.unpinNoteText('was nginx:alpine');
  ok('a note that is only "was <ref>" is removed entirely', r === '', JSON.stringify(r));
})();

(function () {
  var r = Y.unpinNoteText('do not touch — ops pin — was nginx:alpine');
  ok('an author\'s own note beside the "was" fragment is kept, the fragment (and its joiner) removed',
     r === 'do not touch — ops pin', r);
})();

(function () {
  var r = Y.unpinNoteText('');
  ok('an empty note stays empty', r === '', JSON.stringify(r));
})();

(function () {
  var r = Y.unpinNoteText('a plain note with no pin in it');
  ok('a note that never carried a "was" fragment is left exactly as it stands',
     r === 'a plain note with no pin in it', r);
})();

console.log('\n10. pinnedRefFromNote() — the tag it was pinned from, for the release picker');

(function () {
  var r = Y.pinnedRefFromNote('was nginx:alpine');
  ok('the bare "was <ref>" note gives back the ref', r === 'nginx:alpine', r);
})();

(function () {
  var r = Y.pinnedRefFromNote('ops pin — was nginx:alpine');
  ok('the ref is found past an author\'s own note too', r === 'nginx:alpine', r);
})();

(function () {
  var r = Y.pinnedRefFromNote('a plain note with no pin in it');
  ok('a note with no "was" fragment gives back nothing', r === '', JSON.stringify(r));
})();

console.log('\n11. Round trip — releasing removes the note "Pinned" added, and nothing else');

(function () {
  // Note alone — the ordinary case: a Pinned choice with no comment
  // already on the line.
  var text = 'services:\n  web:\n    image: nginx:alpine@' + GOOD_DIGEST + '   # was nginx:alpine\n';
  var doc = Y.parse(text);
  var form = Y.buildForm(doc);
  var field = findField(form, 'web', 'image');

  ok('the pinned image line releases to the picked tag',
     Y.setValue(doc, form, field.id, 'nginx:mainline'));

  var freshForm = Y.buildForm(doc);
  var freshField = null;
  for (var i = 0; i < freshForm.fields.length; i++) {
    if (freshForm.fields[i].id === field.id) { freshField = freshForm.fields[i]; break; }
  }
  var stripped = Y.unpinNoteText(freshField.note);
  ok('the note is recognised as ours alone and reduces to nothing',
     stripped === '', JSON.stringify(freshField.note));
  ok('setComment() clears it, leaving no bare "#" behind',
     Y.setComment(doc, freshForm, freshField.id, stripped, !!freshField.secret, !!freshField.required));

  var out = Y.serialise(doc);
  ok('the released line carries the new tag and no comment at all',
     out.indexOf('image: nginx:mainline\n') !== -1 && out.indexOf('#') === -1, out);
})();

(function () {
  // Note beside an author's own comment — the pin joined onto existing
  // text, so releasing must take back only its own half of it.
  var text = 'services:\n  web:\n    image: nginx:alpine@' + GOOD_DIGEST +
             '   # do not touch — ops pin — was nginx:alpine\n';
  var doc = Y.parse(text);
  var form = Y.buildForm(doc);
  var field = findField(form, 'web', 'image');

  ok('the pinned image line releases to the picked tag',
     Y.setValue(doc, form, field.id, 'nginx:mainline'));

  var freshForm = Y.buildForm(doc);
  var freshField = null;
  for (var i = 0; i < freshForm.fields.length; i++) {
    if (freshForm.fields[i].id === field.id) { freshField = freshForm.fields[i]; break; }
  }
  var stripped = Y.unpinNoteText(freshField.note);
  ok('the author\'s own comment survives, the "was" fragment alone comes off',
     stripped === 'do not touch — ops pin', JSON.stringify(freshField.note));
  ok('setComment() writes the shortened note back',
     Y.setComment(doc, freshForm, freshField.id, stripped, !!freshField.secret, !!freshField.required));

  var out = Y.serialise(doc);
  ok('the released line carries the new tag and the author\'s own comment, nothing of the pin\'s',
     out.indexOf('image: nginx:mainline   # do not touch — ops pin') !== -1 &&
     out.indexOf('was nginx:alpine') === -1,
     out);
})();

check.done();
