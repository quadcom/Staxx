# A VPN'd downloader (binhex-official-gluetun, binhex-qbittorrent — Binhex's Repository)

Members: binhex-official-gluetun, binhex-qbittorrent.
Every published host port and address below is exactly what the converted file holds — see each member's own compose.yaml.

## Wiring

`binhex-qbittorrent`'s network was switched from the default bridge network to `network_mode: "container:binhex-official-gluetun"` — sharing the VPN container's network stack entirely is the whole point of this pairing, and Community Applications templates never ship that wiring themselves since the VPN container's name is only known once it has actually been installed.

`binhex-qbittorrent`'s ports: block was turned into a commented `# ports:` note (the same shape the form writes for any service on a network mode that forbids one) — Compose refuses a live ports: entry alongside network_mode: "container:…", since the service has no interface of its own left to publish on.
