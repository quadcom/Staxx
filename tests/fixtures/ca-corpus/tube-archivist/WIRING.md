# Tube Archivist (TubeArchivist, TubeArchivist-ES, TubeArchivist-Redis — TubeArchivist's Official Repository)

Members: TubeArchivist, TubeArchivist-ES, TubeArchivist-Redis.
Every published host port and address below is exactly what the converted file holds — see each member's own compose.yaml.

## Wiring

`TA_HOST` shipped as the placeholder text "IP ADDRESSES" (the Apps window shows the same words) — set to the box's own address, 192.0.2.10.

`REDIS_CON` named the Redis member by its old hostname ("archivist-redis"), not its real container name ("TubeArchivist-Redis") — set to 192.0.2.10:6379, 6379 being TubeArchivist-Redis's own published host port.

`ES_URL` shipped as the placeholder "http://HOSTIPADDRESS:9200" — set to http://192.0.2.10:9200, 9200 being TubeArchivist-ES's own published host port.
