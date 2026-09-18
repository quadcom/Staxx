# Choosing how a container updates

<!-- index: 15 | letting one container update itself, or wait for you, and whether it is mentioned in update messages — set on its own page or from its row. -->

Every container can have its own answer to two questions: does StaXX install a newer version for
it on its own, and which of StaXX's update messages does it show up in? Left alone, both follow your
server-wide setting in [Settings](settings.md#updates-tab). Set them here to make one container
behave differently from the rest.

Change both from a container's own page in the editor, or straight from its row on
[the stacks page](the-stack-list.md) without opening anything.

## The short version

1. Open a stack and its container, or press the row's own menu — either way you reach the same two
   choices.
2. Set **When** to **Manual** to keep pressing **Update** yourself, or **Automatic** to let StaXX
   install a newer version on its own.
3. With **Automatic**, choose **Immediate** to install the moment an update is found, or **Delayed**
   to wait for the server's own delay and quiet hours.
4. In the **Notifications** box, leave all three switches alone to follow the server, or flip any
   one of them to decide all three for this container yourself.

## From a container's own page

![The Updates box in the editor, showing the When row set to Automatic with Immediate and Delayed underneath it, and beneath it a separate Notifications box with three switches — New image, Image installed and Installation failed — each shown as an orange tick or a red cross, and a note reading Default = Installation failed](../images/guide/update-policy-editor.png)

Open the stack, then the container inside it, and find the **Updates** box below its container
settings.

| Row | Choices | What it does |
|---|---|---|
| When | **Default**, **Manual**, **Automatic** | **Default** leaves this container following your server-wide setting. **Manual** means you press **Update** yourself. **Automatic** lets StaXX install a newer version without being asked. |
| Immediate / Delayed | shown only when When is **Automatic** | **Immediate** installs the moment an update is found. **Delayed** waits for the server's own delay, set in [Settings](settings.md#updates-tab), and for the quiet hours where you have them switched on. |

A short note beside the **When** row states what is actually going to happen right now, in plain
words — for example "Waits 24 hours, then installs in the quiet hours." Press the bold words inside
the note to jump straight to the server setting it names.

![The Updates section with Automatic chosen and the note beside the When row outlined, reading Waits 24 hours, then installs in the quiet hours](../images/guide/update-policy-when-note.png)

Below the **Updates** box sits a **Notifications** box, showing the same three switches as your
server-wide notification settings — **New image**, **Image installed** and **Installation failed** —
each on (an orange tick) or off (a red cross). They start out matching your server. Leave all three
alone and this container follows the server, however you change it later. Flip any one of them and
all three become this container's own from then on. A note beneath the switches reads "Default = …",
naming whichever of the server's own switches are on.

![The Notifications switches in the row menu, with the Default line beneath them outlined](../images/guide/update-policy-notifications-note.png)

This box sets only the one container whose page you have open. To set a whole stack at once, use its
row menu instead.

## From the row menu

![A stack's row menu open with an Updates row and three Notifications switches near the top, each shown as an orange tick or a red cross](../images/guide/update-policy-menu.png)

Open a container's own menu, or a stack's menu, from [the row menu](the-stack-list.md#the-row-menu).
Both carry the same **Updates** row and the same three **Notifications** switches, working the same
way as in the editor.

- Opening a container's menu sets that container alone.
- Opening a stack's menu sets every container inside it at once, and says so on the label —
  "Updates — all 6", for example. A stack holding only one container just says "Updates".
- Choose an option in a stack's menu to set every container in the stack to it, even where they did
  not already match.

A choice made this way takes effect straight away. Nothing needs saving.

## Containers this cannot change

**Pinned to one exact build.** The rows still show, but pressing them does nothing until you unpin
the container, using the link at the top of the box. See
[pinning](recovery-and-redundancy.md) for what it means to pin a container.

**Built on this server, from your own recipe.** These rows work as normal, but **Automatic** means
something slightly different: StaXX watches the image the recipe is built from, and rebuilds this
container when that image moves.

## The self-update mark

![A stack row carrying a small green circular-arrows mark beside its other marks under the name](../images/guide/update-policy-row-mark.png)

A green circular-arrows mark under a container's name on [the stacks page](the-stack-list.md) shows
that it will install an update on its own the moment one qualifies, whether that came from its own
setting or from following the server's. A pinned container never carries this mark.

Set **When** and **Notifications** the way you want them, and the choice applies straight away —
watch for the mark on the row to confirm a container will update itself.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
