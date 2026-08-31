# Sharing a stack with somebody else

<!-- index: 70 | how Export makes a copy with your passwords, keys and paths taken out, what it refuses to send at all, what rides along with the stack, and what the person on the other end has to fill in before it will run. -->

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
4. Press Export. One file saves as itself; several arrive as a single `.staxx` file.

## The first screen: what goes, and what gets hidden

### The files

Every file sitting in the stack's folder is listed, sorted into three kinds.

| Kind | What happens |
|---|---|
| **Can be blanked** | The compose file, and a `.env` file if there is one. These are lists of names and values, so individual values can be taken out of them. The compose file always goes — it *is* the stack — and cannot be unticked. |
| **Must be read first** | Any other ordinary text file. There is no reliable way to blank one automatically, so it can only travel after you have looked at it. Ticking it opens its contents on the spot. |
| **The stack's picture** | If your stack has an icon of its own, it comes too, ticked already, because your compose file names it. It is the one thing here that is not a file sitting in the folder in plain sight — see [below](#about-the-picture). Untick it if you would rather it stayed. |
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

**Several files arrive as a single `.staxx` file.** It is an ordinary zip underneath — rename it to
`.zip` and it opens like any other, because the point of the format is that it can be looked inside,
not that it is sealed. The server builds it from exactly the files and contents your browser hands
over, with one deliberate exception — the picture, below. Everything else is chosen on your screen,
so nothing can be picked up that you did not tick. Nothing in StaXX opens a `.staxx` file for you
yet, so today it is a way of handing a stack over, not something StaXX can read back in.

## About the picture {#about-the-picture}

A stack can keep its own icon, and StaXX stores it in a hidden folder tucked inside the stack —
out of the way, so it is not one more file to scroll past every time you open the stack. Your
compose file names it, so it belongs with the stack, and it travels.

That folder is the one place the export reaches into on its own, and it takes **exactly one thing
out of it: the picture your compose file names.** Nothing else in there can ever be exported, and
that is worth knowing, because of what else lives there.

**The same folder holds every earlier version of your compose file** — StaXX keeps a copy before
each save, so you can go back. Those copies are your file as it was *before* anything was blanked,
which means they hold your real passwords and keys in plain text. Sending them would quietly undo
the entire point of exporting. So they never travel, and the rule is not "the folder, apart from
the history" — it is "the picture, and nothing else", so there is nothing to forget.

**It also holds StaXX's own notes about your machine** — which versions you have saved, which
image versions you have run. None of that is true on somebody else's server, so none of it goes
either. Their StaXX starts its own history from their first save.

Because the picture is chosen by the server rather than by your browser, there is no way to ask for
anything else in that folder — not by editing a request, not by a crafted file name. The only
question the server will answer about that folder is "what picture does this compose file name?"

## What Export never does

- It never changes or removes the stack you are exporting, beyond marking the values you ticked as
  secrets.
- It never reaches into the stack's saved history of earlier versions — the one thing it takes from
  that hidden folder is the picture your compose file names.
- It never includes a file you did not tick.
- It never sends anything anywhere. It produces a file and hands it to you; where it goes next is
  entirely up to you.

## Going the other way: opening one

**Drag the `.staxx` file onto the stack list and drop it.** That is the whole of it. Drop it on a
folder and it lands in that folder; drop it on a stack, and it joins the folder that stack is in;
drop it anywhere else and it lands at the top level. The folder it would land in is outlined while
you drag, so you can see where it is going before you let go.

Nothing is written when you drop it. You get a preview first: what the bundle holds, where it will
land, what it will be called — which you can change — and the covering note the person who exported
it sent along, listing every value you need to fill in. Only when you press Import does the stack
appear, and it appears stopped, with its `REPLACE-ME` values still waiting for you.

If the name you pick is already taken, StaXX says so and lets you pick another. It never merges
into a stack you already have, and it never overwrites one.

A bundle from somebody else is treated as exactly that — a file from outside. StaXX checks every
part of it before unpacking anything, and refuses the whole bundle, with a sentence saying why, if
anything in it is not one of the few shapes a bundle is allowed to hold. Nothing it contains can
reach outside the new stack's own folder, and nothing it claims about the stack's past is believed.

If you would rather do it by hand, that still works: make a new stack and paste the compose text in.

---

Any word here you are not sure of is in the [glossary](../glossary.md).
