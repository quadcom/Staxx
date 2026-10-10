# Immich (immich-server, immich-machine-learning, immich-postgres, immich-redis — sgraaf's Repository)

Members: immich-server, immich-machine-learning, immich-postgres, immich-redis.
Every published host port and address below is exactly what the converted file holds — see each member's own compose.yaml.

## Wiring

No rewiring was needed. All four templates already address each other by real container name (immich-postgres, immich-redis, immich-machine-learning) over the shared external network "immich" that every one of them declares — that is genuine Docker DNS between containers on the same network, not a placeholder.

DB_PASSWORD (immich-server) and POSTGRES_PASSWORD (immich-postgres) both ship blank in the feed, with no Default to fall back to either — the Apps window would show both fields empty too, so nothing was filled in; a real install has to type the same password into both.
