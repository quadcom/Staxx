/* Stack Manager — the value lists exactly as stacks.js held them before
 * PLAN_15 phase 1 moved them into the model. Copyright 2026, Stack Manager
 * contributors. GPL-2.0.
 *
 * NOT a second home for this data and never read by the plugin. It is a
 * photograph: extracted mechanically from stacks.js the moment before the
 * move, so the test that compares the rebuilt lists against it is comparing
 * them with something no one retyped. Editing this file to make a test pass
 * defeats the only thing it is for — if a value here is genuinely wrong, it
 * was wrong before the move too, and that is a separate change.
 */

'use strict';

var CHOICES = {
    'setting/restart': {
      hint: 'when to start it again',
      options: [
        ['no',             'no — leave it stopped'],
        ['always',         'always — start it again whenever it stops'],
        ['unless-stopped', 'unless-stopped — always, unless you stopped it'],
        ['on-failure',     'on-failure — only when it crashes']
      ]
    },
    'setting/network_mode': {
      hint: 'which network the container joins',
      options: [
        ['bridge', 'bridge — Docker’s own private network'],
        ['host',   'host — share the server’s network directly'],
        ['none',   'none — no network at all']
      ]
    },
    // Values are readTest()/writeTest()'s own words (compose-model.js), not
    // the compose keywords (CMD-SHELL/CMD/NONE) — the dropdown only ever
    // shows the plain-English label.
    'setting/healthcheck.test.mode': {
      hint: 'how the check itself is run',
      options: [
        ['shell', 'run a shell line'],
        ['cmd',   'run a program'],
        ['none',  'no check']
      ]
    },
    // Not reached by the binder+target key every other entry here uses — a
    // dependency's target carries its own name (depends_on.db, ...), so
    // boxHtml() looks this one up directly by binder alone. Compose's own
    // condition values, given plain-English labels the same way restart's are.
    'depends/condition': {
      hint: 'when the dependency counts as ready',
      options: [
        ['service_started',               'wait until it has started'],
        ['service_healthy',               'wait until it reports healthy'],
        ['service_completed_successfully', 'wait until it has finished OK']
      ]
    },
    'setting/pull_policy': {
      hint: 'when to check for a newer image',
      options: [
        ['always',  'always — check and pull every time it starts'],
        ['never',   'never — only use what is already on this server'],
        ['missing', 'missing — pull only if the image is not here yet'],
        ['build',   'build — build the image instead of pulling it']
      ]
    },
    'setting/stop_signal': {
      hint: 'which signal asks the container to stop',
      options: [
        ['SIGTERM', 'SIGTERM — the usual, polite request to stop'],
        ['SIGINT',  'SIGINT — the same as pressing Ctrl+C'],
        ['SIGKILL', 'SIGKILL — stop it at once, no cleanup'],
        ['SIGHUP',  'SIGHUP — the usual signal for "reload your settings"'],
        ['SIGQUIT', 'SIGQUIT — stop and dump core, for debugging'],
        ['SIGUSR1', 'SIGUSR1 — a signal the app defines the meaning of'],
        ['SIGUSR2', 'SIGUSR2 — a second signal the app defines the meaning of']
      ]
    },
    // host/none/shareable are the everyday set; "container:<name>" (join
    // another CONTAINER's namespace, not another service in this file) is a
    // swarm/legacy corner left out on purpose, matching PLAN.md's "everyday
    // enum set only" call. service: options are joined per call, below.
    'setting/ipc': {
      hint: 'which IPC namespace the container joins',
      options: [
        ['host',      'host — share the server’s own IPC namespace'],
        ['none',      'none — its own, empty IPC namespace'],
        ['shareable', 'shareable — its own, but open to other containers sharing it']
      ]
    },
    'setting/pid': {
      hint: 'which process namespace the container joins',
      options: [
        ['host', 'host — see and be seen by every process on the server']
      ]
    },
    'setting/logging.driver': {
      hint: 'where this container’s logs are sent',
      options: [
        ['json-file', 'json-file — Docker’s own default, kept as files on this server'],
        ['local',     'local — a more compact version of the same thing'],
        ['syslog',    'syslog — sent to the server’s syslog'],
        ['journald',  'journald — sent to systemd’s journal'],
        ['fluentd',   'fluentd — sent to a Fluentd log collector'],
        ['none',      'none — logs are discarded']
      ]
    },
    // A short-form port's protocol and a short-form volume's mode (see
    // splitPortShort()/splitPathShort() in compose-model.js): the value
    // carries its own separator, so the empty option writes the separator
    // away too rather than needing a special case for "nothing chosen".
    //
    // Two options, not three: '' and '/tcp' mean the same thing to Docker, and
    // offering both read as one option listed twice. A file that already spells
    // out '/tcp' keeps it — optionsHtml() puts any value it does not recognise
    // at the top of the list — so nothing is rewritten behind anyone's back.
    'port/proto': {
      hint: 'which protocol this port uses',
      options: [
        ['',     'tcp — the default'],
        ['/udp', 'udp']
      ]
    },
    'volume/mode': {
      hint: 'whether the container can write to this mount',
      options: [
        ['',    'read and write — the default'],
        [':ro', 'read-only'],
        [':rw', 'read and write, spelled out']
      ]
    },
    // A declaration's driver is folded onto the row's OWN value box (see
    // DECL_PRIMARY in compose-model.js), never a suffix on its target, so
    // choiceFor() below looks these up by declKind directly rather than by
    // the binder+target key every other entry here uses.
    'declared/networks.driver': {
      hint: 'how Docker implements this network',
      options: [
        ['bridge',  'bridge — Docker’s own private network, the usual choice'],
        ['host',    'host — share the server’s network directly'],
        ['none',    'none — no network at all'],
        ['macvlan', 'macvlan — gives the network its own address on the LAN'],
        ['ipvlan',  'ipvlan — a lighter-weight version of the same idea']
      ]
    },
    // 'local' is the only driver every Unraid server has; anything else
    // (nfs, cifs, a plugin's own driver) only exists if it was set up by
    // hand, so a value already in the file that is not on this list joins it
    // as it stands — the same rule restart's "on-failure:3" note describes.
    'declared/volumes.driver': {
      hint: 'what manages the storage behind this volume',
      options: [
        ['local', 'local — a folder Docker manages on this server, the usual choice']
      ]
    }
  };

var BOOL_CHOICES = {
    'privileged': {
      hint: 'how much access to the host this container gets',
      options: [['true', 'true — full access to the host'],
                ['false', 'false — normal, isolated']]
    },
    'read_only': {
      hint: 'whether the container can write to its own filesystem',
      options: [['true', 'true — read-only filesystem'],
                ['false', 'false — normal, writable filesystem']]
    },
    'init': {
      hint: 'whether a tiny init process runs as PID 1',
      options: [['true', 'true — runs one, to clean up stray processes'],
                ['false', 'false — the container’s own process is PID 1']]
    },
    'tty': {
      hint: 'whether the container gets a terminal',
      options: [['true', 'true — allocates one, as if run interactively'],
                ['false', 'false — no terminal']]
    },
    'stdin_open': {
      hint: 'whether standard input stays open',
      options: [['true', 'true — keeps it open, as if run interactively'],
                ['false', 'false — closes it at once']]
    },
    'healthcheck.disable': {
      hint: 'whether the health check above is switched off',
      options: [['true', 'true — disabled, even though one is written above'],
                ['false', 'false — runs as written above']]
    },
    'depends.required': {
      hint: 'whether this dependency must succeed for the service to start',
      options: [['true', 'true — must start successfully, or this service will not start'],
                ['false', 'false — allowed to fail without stopping this service']]
    },
    'declared.external': {
      hint: 'whether this already exists outside the file',
      options: [['true', 'true — already exists; this file only refers to it'],
                ['false', 'false — created by this file']]
    }
  };

var CAP_OPTIONS = [
    ['CHOWN',              'CHOWN — change file ownership'],
    ['DAC_OVERRIDE',       'DAC_OVERRIDE — bypass file read/write/execute checks'],
    ['DAC_READ_SEARCH',    'DAC_READ_SEARCH — bypass file read and directory search checks'],
    ['FOWNER',             'FOWNER — bypass checks that usually require owning the file'],
    ['FSETID',             'FSETID — keep the setuid/setgid bits when a file changes'],
    ['KILL',               'KILL — send signals to any process'],
    ['SETGID',             'SETGID — change a process’s group ID'],
    ['SETUID',             'SETUID — change a process’s user ID'],
    ['SETPCAP',            'SETPCAP — grant or remove permissions on other processes'],
    ['LINUX_IMMUTABLE',    'LINUX_IMMUTABLE — set the immutable and append-only file flags'],
    ['NET_BIND_SERVICE',   'NET_BIND_SERVICE — bind to a port below 1024'],
    ['NET_BROADCAST',      'NET_BROADCAST — send and receive network broadcasts'],
    ['NET_ADMIN',          'NET_ADMIN — manage networking'],
    ['NET_RAW',            'NET_RAW — use raw and packet sockets'],
    ['IPC_LOCK',           'IPC_LOCK — lock memory so it is never swapped out'],
    ['IPC_OWNER',          'IPC_OWNER — bypass shared memory and message queue checks'],
    ['SYS_MODULE',         'SYS_MODULE — load and unload kernel modules'],
    ['SYS_RAWIO',          'SYS_RAWIO — read and write raw devices directly'],
    ['SYS_CHROOT',         'SYS_CHROOT — change the apparent root directory'],
    ['SYS_PTRACE',         'SYS_PTRACE — trace and control other processes'],
    ['SYS_PACCT',          'SYS_PACCT — switch process accounting on and off'],
    ['SYS_ADMIN',          'SYS_ADMIN — wide-ranging administrative access'],
    ['SYS_BOOT',           'SYS_BOOT — reboot the server'],
    ['SYS_NICE',           'SYS_NICE — raise process priority above normal'],
    ['SYS_RESOURCE',       'SYS_RESOURCE — override resource limits'],
    ['SYS_TIME',           'SYS_TIME — set the system clock'],
    ['SYS_TTY_CONFIG',     'SYS_TTY_CONFIG — reconfigure virtual terminals'],
    ['MKNOD',              'MKNOD — create device, pipe and other special files'],
    ['LEASE',              'LEASE — take out leases on files'],
    ['AUDIT_WRITE',        'AUDIT_WRITE — write to the kernel’s audit log'],
    ['AUDIT_CONTROL',      'AUDIT_CONTROL — configure kernel auditing'],
    ['SETFCAP',            'SETFCAP — set capabilities on files'],
    ['MAC_OVERRIDE',       'MAC_OVERRIDE — bypass mandatory access control (SELinux/AppArmor)'],
    ['MAC_ADMIN',          'MAC_ADMIN — configure mandatory access control'],
    ['SYSLOG',             'SYSLOG — read the kernel’s log buffer'],
    ['WAKE_ALARM',         'WAKE_ALARM — wake the server from suspend'],
    ['BLOCK_SUSPEND',      'BLOCK_SUSPEND — stop the server from suspending'],
    ['AUDIT_READ',         'AUDIT_READ — read the kernel’s audit log'],
    ['PERFMON',            'PERFMON — use performance monitoring tools'],
    ['BPF',                'BPF — load BPF programs'],
    ['CHECKPOINT_RESTORE', 'CHECKPOINT_RESTORE — checkpoint and restore processes']
  ];

module.exports = { CHOICES: CHOICES, BOOL_CHOICES: BOOL_CHOICES, CAP_OPTIONS: CAP_OPTIONS };
