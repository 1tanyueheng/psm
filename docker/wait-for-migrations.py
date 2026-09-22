#!/usr/bin/env python3
"""
Release the queue workers once migrations have finished.

Why this exists
---------------
`supervisord`'s `priority` setting orders *initiation*, not completion. Setting
`priority = 15` on a migrate program and `priority = 20` on the queue workers
does not make the workers wait — supervisord forks them all, and the workers
reach `RUNNING` within milliseconds while `php artisan migrate` is still
opening a connection.

The queue worker's very first action is to poll the `cache` table for the
`illuminate:queue:restart` key, because `onOneServer()` locks and the restart
signal both live there. If that table does not exist yet the worker dies with:

    SQLSTATE[42P01]: relation "cache" does not exist
    WARN exited: queue-worker_00 (exit status 1; not expected)

and supervisord restarts it, forever, every few seconds. Both worker
processes crash-loop and fill the log, which makes it look as though the whole
deploy failed when in fact only the two workers are affected — the API is fine,
and the migration may well have succeeded a moment later.

The fix
-------
The workers are configured with `autostart = false`, so supervisord leaves them
`STOPPED`. This listener subscribes to `PROCESS_STATE_EXITED` and starts them
once the `migrate` program has exited, whatever the outcome.

Starting them even when migration *failed* is deliberate. A worker crash-loop
is far worse than a worker that starts and reports a missing table once, and
`autorestart = true` still covers the case where the database comes back
later. It also means a failed migration does not leave the queue silently dead.

Protocol notes
--------------
Supervisord's event protocol is easy to get wrong, so for the record:

- Each event is a header line ending in ``\\n``, followed by exactly `len`
  bytes of payload.
- The payload's field count is included in that `len`, so the payload ends with
  its own trailing newline. Reading `len` bytes is correct; reading `len + 1`
  blocks forever and the listener appears to hang with no output at all.
- An event listener must write `READY` once on startup, and a `RESULT` line
  after each processed event, or supervisord stops talking to it.
"""

import os
import subprocess
import sys

# Where to reach supervisord. Passed with `-s` on every supervisorctl call
# rather than relying on the tool's default, which is
# `unix:///run/supervisord.sock` — note `supervisord`, not `supervisor`. The
# socket this image actually creates is `/var/run/supervisor.sock` (see
# [unix_http_server] in docker/supervisord.conf). Left to its own default,
# supervisorctl fails with "no such file" and no worker ever starts.
#
# The default below matches [unix_http_server], and the environment variable
# is set on the listener in supervisord.conf so the two cannot drift apart.
SUPERVISOR_URL = os.environ.get(
    "SUPERVISOR_SERVER_URL", "unix:///var/run/supervisor.sock"
)


def emit(line):
    """Write a protocol line, flushed — supervisord reads this incrementally."""
    sys.stdout.write(str(line) + "\n")
    sys.stdout.flush()


def note(line):
    """
    Write a human-readable diagnostic to stderr.

    stderr, not stdout, and this distinction matters more than it looks.
    supervisord reads the line following each event and requires it to be
    exactly `RESULT <n>`. A stray log line on stdout makes supervisord report

        WARN gate: has entered the UNKNOWN state and will no longer receive
        events, this usually indicates the process violated the eventlistener
        protocol

    after which the listener is dead — silently, and with the workers never
    starting. stderr is streamed to the container log by supervisord's own
    `stderr_logfile = /dev/stderr`, so these messages still show up on Render
    exactly where they are needed.
    """
    sys.stderr.write("[gate] " + str(line) + "\n")
    sys.stderr.flush()


def parse_payload(payload):
    """Turn 'key:value key:value' into a dict."""
    fields = {}
    for part in payload.split():
        if ":" in part:
            key, value = part.split(":", 1)
            fields[key] = value
    return fields


def read_event(header):
    """
    Read one event payload.

    The header looks like:

        ver:3.0 server:supervisor serial:12 pool:psm-eventlistener \
            poolserial:12 eventname:PROCESS_STATE_EXITED len:74

    `len` counts every byte of the payload including its trailing newline.
    """
    length = 0

    for token in header.split():
        if token.startswith("len:"):
            try:
                length = int(token.split(":", 1)[1])
            except ValueError:
                length = 0

    payload = sys.stdin.read(length) if length else ""

    # The trailing newline is inside the counted length, so it arrives here
    # and has to be stripped rather than read as a separate line.
    if payload.endswith("\n"):
        payload = payload[:-1]

    return parse_payload(payload)


def main():
    # supervisord will not send anything until it sees this.
    emit("READY")

    # Set once the migrate program has been seen exiting, so the workers are
    # released exactly once even if other programs exit afterwards.
    released = False

    while True:
        header = sys.stdin.readline()

        if not header:
            break

        if "PROCESS_STATE_EXITED" not in header:
            continue

        fields = read_event(header)

        if fields.get("processname") != "migrate":
            # Unrelated exit (php-fpm, nginx, a worker). A RESULT is still
            # required — every event delivered must be acknowledged, or
            # supervisord stops sending them and reports the listener as
            # having entered an UNKNOWN state.
            emit("RESULT 2")
            continue

        # `expected:1` means the migration succeeded.
        succeeded = fields.get("expected") == "1"

        # IMPORTANT: RESULT must be written *before* anything else, and it
        # must be the only line written for this event. supervisord reads the
        # next line after an event expecting exactly `RESULT <n>`; anything
        # else and it logs "bad result line" and marks this listener UNKNOWN,
        # silently cutting it off. That is why the diagnostics below go to
        # stderr instead of stdout.
        emit("RESULT 2")

        if released:
            continue
        released = True

        note(
            "migrate finished ({}) - starting queue workers and scheduler".format(
                "succeeded" if succeeded else "FAILED, see the [migrate] output above"
            )
        )

        if not succeeded:
            note(
                "The API is unaffected. Check DB credentials and re-run "
                "migrations before relying on email delivery."
            )

        # Programs are named explicitly rather than as `psm:queue-worker:*`.
        # The glob looks tidier but is rejected with "ERROR (no such
        # process)" against a `numprocs` group — supervisorctl expands
        # process globs, not program globs.
        #
        # These names must match `numprocs` in docker/supervisord.conf
        # (numprocs = 2, process_name = %(program_name)s_%(process_num)02d).
        # Changing one without the other leaves a worker STOPPED forever.
        result = subprocess.run(
            [
                "supervisorctl",
                "-s",
                SUPERVISOR_URL,
                "start",
                "psm:scheduler",
                "psm:queue-worker_00",
                "psm:queue-worker_01",
            ],
            capture_output=True,
            text=True,
        )

        output = (result.stdout or result.stderr or "").strip()
        note("supervisorctl: " + output.replace("\n", " | "))


if __name__ == "__main__":
    main()
