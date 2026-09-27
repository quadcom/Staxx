# General tab

<!-- index: 81 | where StaXX appears, installing apps, and switching container shells and file browsing on or off. -->

![The General tab in full: the six tabs across the top, then Show StaXX in with its two small pictures, Docker menu with its two, Installs from the Apps page, and the Container access box holding Container shells and Container files side by side, each with its dropdown](../images/guide/settings-general-tab.png)


| Setting | Choices | Default | What it does |
|---|---|---|---|
| Show StaXX in | A tab under the Docker menu / Its own button in the top bar | A tab under Docker | With StaXX as a tab, it appears under Docker ahead of Unraid's own container list and is the tab you land on. With it as a button, it gets its own place in the top bar. This choice does nothing while Docker menu, below, is set to replace. |
| Docker menu | Leave the Docker menu alone / Replace it with StaXX | Leave it alone | With this on, the Docker button at the top of every Unraid page is replaced by a StaXX button, and Unraid's own Docker pages disappear from the menu. With it off, the Docker button and its pages come back exactly as Unraid made them, and StaXX appears wherever Show StaXX in puts it. Your containers themselves are never touched either way; only the menu changes. This setting has no effect while Show StaXX in, above, is set to a tab. |
| Installs from the Apps page | Bring them into StaXX / Ask first / Leave them to Unraid | Bring them into StaXX | Bring them in turns each app you install from Unraid's Apps page into a stack; Ask first stops to ask each time; Leave them to Unraid keeps Unraid's own install route. Nothing already installed changes either way. See [adding an app](installing-an-app.md). |
| Container shells | Allow opening a shell / Do not allow shells | Allow opening a shell | With this on, you can open a command line inside a running container from its Manage tab. With it off, no container on this server can be opened that way from StaXX. |
| Container files | Allow browsing a container's files / Do not allow file browsing | Follows Container shells, until set on its own | With this on, you can browse, open and edit a running container's files from its Manage tab, and rename, delete or create folders there. With it off, no container on this server can have its files browsed that way from StaXX. Until you change this setting yourself, it follows Container shells: turning shells off also turns files off. Set it once yourself and it stops following shells from then on. |

Container shells and Container files sit side by side in one box, **Container access**. See
[the Manage tab](the-manage-tab.md) for where the shell and the file browser are used.

## Save refusals

| What it says | What to do |
|---|---|
| Settings were saved, but applying them failed | Reboot the server to finish applying the menu changes. |

## Terms used here

Any word you are not sure of is in the [glossary](../glossary.md).

Back to [the StaXX guide](README.md).
