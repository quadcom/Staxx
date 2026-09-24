# Nextcloud + MariaDB (nextcloud, mariadb — linuxserver's Repository)

Members: nextcloud, mariadb.
Every published host port and address below is exactly what the converted file holds — see each member's own compose.yaml.

## Wiring

Neither template's environment names the other: linuxserver's Nextcloud image configures its database through its own first-run web wizard, not an env var, so there is nothing here for a generator to point at the other container — a real install types MariaDB's container name and the MYSQL_* values into that wizard by hand once both containers are running.

Both templates ship several Path/Variable settings blank; ca-convert.js's own default-filling (the same fallback the Apps window's fields use) supplied:

  - nextcloud: The path "Appdata" had no value; used /mnt/user/appdata/nextcloud/config as a placeholder — check it before starting the stack.

  - nextcloud: The path "Path: /data" had no value; used /mnt/user/appdata/nextcloud/data as a placeholder — check it before starting the stack.

  - mariadb: The path "Appdata" had no value; used /mnt/user/appdata/mariadb/config as a placeholder — check it before starting the stack.
