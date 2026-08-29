# Sharing a stack with somebody else

<!-- index: 70 | how Export makes a copy with your passwords, keys and paths taken out, what it refuses to send at all, and what the person on the other end has to fill in before it will run. -->

A stack is already a folder with an ordinary compose file in it, so sharing one *ought* to be as
easy as sending the file. The catch is that your file is full of things that belong to you and not
to them: your passwords, your API keys, the paths to your own folders, the address of your own
server.

**Export** is the way round that. It makes a copy of the stack with those things taken out, leaves
the original exactly as it was, and shows you everything it is about to do before it does any of it.

You will find it in a stack's own menu, next to the other things that produce a file rather than
change one.

## The short version

1. Open a stack's menu and choose **Export**.
2. Tick which files go, and which values get blanked. Sensible choices are already made for you.
3. Read the summary of what is about to leave.
4. Press Export. One file saves as itself; several arrive as a zip.

## The first screen: what goes, and what gets hidden

### The files

Every file sitting in the stack's folder is listed, sorted into three kinds.

| Kind | What happens |
|---|---|
| **Can be blanked** | The compose file, and a `.env` file if there is one. These are lists of names and values, so individual values can be taken out of them. The compose file always goes — it *is* the stack — and cannot be unticked. |
| **Must be read first** | Any other ordinary text file. There is no reliable way to blank one automatically, so it can only travel after you have looked at it. Ticking it opens its contents on the spot. |
| **Refused** | Keys, certificates, anything that is not text, anything too large, folders, and shortcuts to files elsewhere. These are listed with the reason, but there is no tick to argue with. |

A file the compose file actually depends on is marked as such, so you can see at a glance what the
other person will need. That mark tells you something; it never decides for you, and it never
promotes a refused file into an allowed one.

**On a file you have to read:** as its contents appear, lines that look like they carry something
private are marked for your attention — a line mentioning a password or a token, a long run of
random-looking characters, the opening line of a key file. This only ever says "look here". It will
never tell you a file is clean, because the moment a tool starts handing out clean bills of health,
people stop reading. The judgement is yours.

**Why keys are refused outright.** A private key is plain text, so the ordinary "is this a text
file?" test waves it straight through. Two checks catch it instead: what the file is called, and
what its first line says. The second is the one that matters, because it still catches a key that
somebody renamed to something innocent.

### The values

The second list is every setting in the compose file. Anything already marked as a secret is ticked
for you. Paths start unticked, because most of them are harmless — but they are tickable, because
whether a folder name says something about you is your call, not the tool's.

Tick a `.env` file and its entries join the list, all ticked, since a `.env` exists precisely to
hold the things that differ from one machine to the next. Where the compose file reads one of those
entries into a setting you have marked secret, that entry is shown as *certainly* secret rather
than merely assumed to be.

## The second screen: what is about to leave

Nothing is written until you have seen this. It lists the files going, the files staying behind and
why each one is staying, and every value that will be blanked.

It also tells you one thing it is doing on your behalf: the values you ticked are marked as secrets
**in your own stack too**, so the next export starts out correct and the screenshot mode knows to
hide them from now on. That happens automatically — the screen tells you rather than asking you,
and it only applies to compose settings, since a `.env` file has nowhere to record such a mark.

## What the other person receives

**A blanked value is replaced with `REPLACE-ME`.** It is never replaced with something that looks
plausible. A blanked port becomes `REPLACE-ME` too, and that file will not start until it is filled
in — which is the entire point. StaXX refuses to start any stack still holding one of those, and
tells the person which settings are still waiting. A recipe that quietly runs with somebody else's
leftover settings would be far worse than one that stops and says what it needs.

**A covering note rides inside the compose file itself**, as comments at the top: what it is, when
it was exported, which stack it came from, every value that was blanked, and every file that was
left behind and why. "Bring your own certificate" is something a person can act on; a stack that
mysteriously refuses to start is not. The note carries no machine name, no user name and no
addresses. Once nothing in the file still says `REPLACE-ME`, the note can be deleted.

It rides *inside* the file because the usual way to send one of these is to paste the text into a
chat or a forum post, and a paste carries nothing but the text. A separate note would be lost on
the one journey it exists for.

**Everything else about the file survives untouched.** Your comments, your blank lines, your
ordering, the whole shape of the file as you wrote it. The copy is your real file with holes
punched in it, not a file rebuilt from scratch — so what arrives is recognisably the thing you
wrote.

## How it arrives

**One file saves as itself.** When the compose file is all you are sending — the usual case — it
downloads as plain text, ready to paste anywhere. A zip holding a single text file is a nuisance to
open and impossible to paste.

**Several files arrive as a zip.** The server builds it from exactly the files and contents your
browser hands over. It is never told where your stack lives, so it cannot reach past what you chose
and pick up something you did not.

## What Export never does

- It never changes or removes the stack you are exporting, beyond marking the values you ticked as
  secrets.
- It never reaches into the stack's saved history of earlier versions.
- It never includes a file you did not tick.
- It never sends anything anywhere. It produces a file and hands it to you; where it goes next is
  entirely up to you.

## Going the other way

To use a stack somebody sent you, make a new stack and paste the text in. Fill in everything that
says `REPLACE-ME` — StaXX will not let it start until you do, and will tell you what is still
outstanding. Opening a zip somebody sent is not built yet.

---

Any word here you are not sure of is in the [glossary](../glossary.md).
