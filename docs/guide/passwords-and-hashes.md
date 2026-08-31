# Making a password, and its scrambled form

<!-- index: 42 | the Password button in a stack's editor: making a password or a passphrase, turning one into the scrambled form some apps ask for, and why a dollar sign is written twice in a compose file. -->

Most apps you install want a password, and some want a *scrambled* version of one instead. Making
either somewhere else and pasting it in means it has been through your clipboard, another window and
possibly a website. The **Password** button on the top row of a stack's editor does both jobs
without the value leaving the page.

You need a stack open to reach it. The panel it opens has two halves: the password itself at the
top, and its scrambled form underneath.

## The short version

1. Open the stack and press **Password**.
2. Click into the box on the form you want filled — the panel fills whichever box you were last in.
3. Press **Fill** to put the password straight into that box, or **Copy** to take it away with you.
4. If the app wants the scrambled form instead, choose a format, press **Hash**, then use that
   half's own **Fill** or **Copy**.

## The password itself

Two kinds, chosen with the pair of buttons at the top.

| Kind | What you get | What you can change |
|---|---|---|
| Characters | A jumble — `k7#mQ-vp2Rz` | How long, and whether to include capitals, digits and punctuation |
| Words | A passphrase — `harbour-cedar-lantern-quiet` | How many words, and what goes between them |

A new one appears every time you change any of those, and there is a **Regenerate** button for when
you simply want a different one. Underneath sits a strength reading — how hard the password would be
to guess. If you type your own over the top, the reading stays but is labelled an estimate, because
there is no recipe behind a typed password to measure.

You can type or paste your own password into that box at any time. Everything below works the same
way on it.

### One character it never uses

Under the character options you will see this:

> The dollar sign is left out on purpose — in a compose file it is where Compose starts reading the
> name of a variable.

That is the whole reason. [Why a dollar sign is special](#why-a-dollar-sign-is-special) explains
what would otherwise happen. Nothing else is held back — capitals, digits and punctuation are all
there if you tick them.

## The scrambled form

Some apps will not take a password at all. They want it put through a one-way scramble first, so
that what is written in your compose file cannot be turned back into the password. **Hash** is the
name for that scramble, and it is the lower half of the panel.

Four formats are offered, and **only ones this server has actually proved it can produce** appear in
the list:

| Format | Where you will meet it |
|---|---|
| bcrypt | Very common — web apps, and anything using an Apache-style password file |
| SHA-512 crypt | Linux system accounts, and apps that borrow that format |
| SHA-256 crypt | The same, in a shorter form |
| argon2id | Newer apps that have chosen the current recommendation, such as Vaultwarden |

Choose one, press **Hash**, and the scrambled value appears in the box below it. A format may be
marked *lighter check* — that means this server could prove it produces the right shape, but could
not prove it round-trips, so it is offered with that said out loud rather than hidden.

### It needs a small container, once

The scrambling is done by a tiny container StaXX builds on your server the first time you ask for a
hash. If it has not been built yet, that half of the panel offers to build it and says what that
involves. It fetches a base image and a few packages, and it touches nothing else about your stacks.
The password half works whether or not you ever build it.

Because that container starts and stops for a single request, a hash takes a second or two. The panel
says so while you wait, rather than looking stuck.

## Fill and Copy, and why each half has its own

The password and its scrambled form usually go to two different places — the scrambled form into the
app's settings, the password into wherever you keep passwords. So each half has its own pair of
buttons rather than one being a step on the way to the other.

| Button | What it does |
|---|---|
| Fill | Puts that value into whichever box on the form you were last in |
| Copy | Puts it on your clipboard |

**Fill asks first if the box already holds something.** It names the box and shows you what is in it,
because a working password quietly replaced is a broken app and a lost credential.

Fill goes through the ordinary editing path, so it lands in the stack's history and **Undo** takes it
back like any other change.

## Why a dollar sign is special

This is the one thing worth understanding, because it explains three messages you will see.

In a compose file, a dollar sign does not mean a dollar sign. It is where Compose starts reading the
*name of a variable* — a value it expects to find defined somewhere else. So a password of
`pa$$word` is read as `pa` followed by two variables that probably do not exist, and the app receives
`pa`. Nothing warns you. The app simply says the password is wrong.

**Quoting it does not help.** That is most people's first guess, and it is wrong: the substitution
happens after the file has been read, so quote marks make no difference at all.

The way to say "I mean a real dollar sign" is to write it **twice**. `pa$$$$word` in the file
delivers `pa$$word` to the container. It looks odd, and it is correct.

### Where this bites hardest

**Every scrambled password starts with a dollar sign and holds four or five more.** That is just how
the formats are written — `$argon2id$v=19$...`, `$2y$12$...`. Paste one straight into a compose file
and almost all of it is eaten before the app ever sees it.

So StaXX writes each one twice for you, and tells you it has:

- **Fill** always writes the compose-file form. For an ordinary password with no dollar sign in it,
  that changes nothing at all.
- **Copy** shows a short message first, whenever there is a dollar sign in what you are copying. It
  says what is about to go on your clipboard and why. Press **I understand** and it copies the
  doubled form; close it any other way and nothing is copied.
- **Under the scrambled value**, once you have made one, a line explains that a hash always contains
  dollar signs and that both buttons write each one twice.

The doubled form is left showing in the box on the form afterwards, on purpose. It is genuinely what
the file has to contain, and hiding that would make the file and the form disagree.

### If you are pasting somewhere else

The doubling is only right for a compose file. If you are pasting into a file of environment
variables, or into an app's own settings screen, **a single dollar sign is what belongs there**. The
box in the panel still holds that plain version — select it and copy it by hand.

### A hash that is already in your file

If a stack already has a scrambled password written with single dollar signs — pasted in from
somewhere else, or from before StaXX did this for you — the note under that box says so, and offers a
button: **Write each dollar sign twice**. One press fixes it, and **Undo** takes it back.

This does not need a new password. The scrambled value is of the right password already; it was only
written in a way Compose was eating.

Docker's own reference for this is
[interpolation in a compose file](https://docs.docker.com/reference/compose-file/interpolation/), if
you want the primary source.

## What this never does

- **Nothing is sent anywhere.** Passwords and passphrases are made in your browser. The scrambling
  happens in a container on your own server, which is built with no network access at all.
- **It never stores the password.** It lives in that box and nowhere else, and closing the panel
  blanks both boxes. If you want to keep it, copy it somewhere before you close it.
- **It never turns a scrambled value back into a password.** That is the entire point of scrambling
  one. If you lose the password, you make a new one and hash it again.
- **It never changes a box you did not choose.** Fill only ever writes to the box you were last in,
  and names it before it writes.
- **It never guesses which format your app wants.** That is on the app's own documentation.

## Left out, for now

Honest gaps rather than oversights:

- Checking a password against a hash you already have. The tool that makes argon2id hashes has no
  way to check one, so this could only be offered for some formats and not others.
- Remembering a password between sessions, or keeping a list of them. StaXX is not a password
  manager, and pretending otherwise would be the wrong place to keep one.
- Warning about a dollar sign typed straight into a box on the form. Only the panel's own buttons
  know they are handling a password — a dollar sign typed anywhere else is often a variable somebody
  meant.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).
