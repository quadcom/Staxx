# Removing a stack

<!-- index: 67 | taking a stack off the list, what actually happens to it, and how to get it back. -->

Removing a stack takes it off the list. Its containers stop, and the whole folder is zipped up and
kept in the archive folder. Open it from the stack's own menu.

## The short version

1. Open the stack's own menu.
2. Choose **Remove stack**.
3. Read what the dialog says, then press **Remove and archive**.
4. StaXX stops the containers, zips the folder, and tells you where the zip landed.

## Step 1 · Remove stack

![A stack's row menu, laid out in two columns, with Remove stack at the bottom of the left column outlined](../images/guide/removing-a-stack-menu-item.png)

Open the stack's own menu and choose **Remove stack**, near the bottom, on its own below a
separator.

## Step 2 · The confirmation dialog

![The Remove dialog: containers are stopped and removed, nothing is deleted because the folder is zipped into the archives folder, appdata is untouched, and a list of what else goes into the zip, with Cancel and Remove and archive buttons](../images/guide/removing-a-stack-confirm.png)

1. Read what the dialog titled **Remove "\<name>"?** lists.
2. Press **Remove and archive**.

The dialog lists, in order:

- Its containers are stopped and removed.
- The whole folder is zipped up and kept in the archive folder, named after the stack and the time
  it was archived. Nothing is deleted.
- Appdata is not touched.
- Anything else going into the zip, besides the compose file itself — or, if the folder holds
  nothing more, a line saying so.
- Every folder and named volume the compose file uses: folders by their path on your server, named
  volumes by name marked *(a volume Docker manages)*, or **none**. None of these are moved or
  changed. A named volume is removed only by Docker's own clean-up, never by StaXX.

### Proxy and DNS entries

If StaXX made a proxy entry or a Pi-hole name for the stack, the dialog also asks what to do with
each. See [Proxy and DNS](proxy-and-dns.md).

![The Proxy and DNS part of the removal box: the proxy entry with Switch off ticked and Delete and Keep beside it, and the Pi-hole name with Delete ticked and Keep beside it](../images/guide/removing-a-stack-proxy-dns.png)

| For | Choices | Chosen unless you change it |
|---|---|---|
| The proxy entry | **Switch off**, **Delete** or **Keep** | **Switch off** |
| The Pi-hole name | **Delete** or **Keep** | **Delete** |

A switched-off proxy entry keeps its settings and can be switched back on in Nginx Proxy Manager.

## What happens

Removing a stack can take a little while. Once it is done:

- Every container the compose file made is stopped and removed.
- The whole folder — compose file and everything beside it — is packed into one zip, named after
  the stack and when it was removed, and written into the archive folder under your data store.
- The folder no longer appears on the stacks list.
- Appdata is not touched. Whatever the container wrote while it ran stays exactly where it was.

When another stack shares this one's running name, only the folder is archived and that stack's
containers are left running.

![The Removed dialog naming the zip the folder is now kept as, and saying that unzipping it back into the stacks folder puts the stack back](../images/guide/removing-a-stack-result.png)

The dialog then names the archive the stack was kept as. Press **Done** to close it.

## The archive list on Settings

![The Archived stacks box on the Storage tab: a scrolling list of zip files named after each stack and the time it was archived, with date and size on the right](../images/guide/removing-a-stack-archive-list.png)

Open Settings and check **Archived stacks** to see every stack you have removed: its file name,
when it was archived, and its size.

## Getting a stack back

Unzip the archive file into the stacks folder. The stack reappears on the list exactly as it was —
same compose file, same folder, same containers, ready to be started again.

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
