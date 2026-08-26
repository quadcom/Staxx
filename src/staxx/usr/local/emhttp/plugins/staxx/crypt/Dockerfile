# StaXX's own cryptography container — recipe only, built on the machine that
# runs it, never fetched pre-built. Copyright 2026, StaXX contributors.
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License version 2,
# as published by the Free Software Foundation.
#
# Why this exists at all: PHP on Unraid cannot produce an argon2 hash by any
# means — both routes were measured, not assumed (PLAN_74). Neither can the
# browser, under this project's own rule against client-side libraries. A
# small, readable, throwaway container is the only place left to do it.
#
# What this container is NOT: it has no network when it runs, no volumes, no
# ports, and no service listening on anything. It reads one password on its
# command's input stream and prints one hash. Nothing here ever touches a
# stack, a compose file, or the internet.
#
# CONFIRMED on the real Unraid server, 2026-08-26, with a throwaway probe
# image (built, tested, and removed): `apk add --no-cache argon2
# apache2-utils` on alpine:3.20 is correct, `docker create --network none
# --read-only` works with no writable filesystem needed by any of the three
# tools, and all four hash formats (bcrypt, SHA-512 crypt, SHA-256 crypt,
# argon2id) read their password correctly from standard input. Still,
# staxx_crypt_selftest() (include/Crypt.php) is what actually decides which
# formats get offered — it proves each one by checking its own output on
# every real build, not by trusting that a package install succeeded.
FROM alpine:3.20
RUN apk add --no-cache argon2 apache2-utils
# mkpasswd (SHA-512 / SHA-256 crypt) comes from busybox, already in the base
# image — nothing extra to install for it.
#
# The container idles on `tail -f /dev/null` rather than running a service:
# there is nothing to listen on, so there is nothing to reach. StaXX runs each
# hashing command inside the container it already has, handing the password
# over on that command's input stream.
CMD ["tail", "-f", "/dev/null"]
