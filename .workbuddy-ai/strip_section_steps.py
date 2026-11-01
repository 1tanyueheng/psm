"""Remove section-band steps from the sequence scripts that still declare them.

`seqkit` dropped section bands at the user's request ("remove the line and the
explanation in the sequence thanks"), but four of the six scripts still contain
`{"kind": "section", ...}` steps. Those steps have no `a`/`b` keys, so the kit
fails on them with `KeyError: 'a'` — these four diagrams have therefore been
unbuildable since the bands were removed, and only `seq_login` and
`seq_milestone` could be regenerated.

Removing the steps is exactly what the user asked for: the messages carry their
own order, and a numbered band header above a group of messages is the "1. Open
submitted work ..." furniture they objected to.

Run once. It rewrites the four files in place and prints what it removed.
"""
import io
import re

FILES = ["seq_evaluation", "seq_report", "seq_title", "seq_notification"]

for name in FILES:
    path = f".workbuddy-ai/{name}.py"
    text = io.open(path, encoding="utf-8").read()

    removed = 0

    def drop(m):
        global removed
        removed += 1
        return ""

    # A section step is a single dict on its own line (possibly spanning two),
    # always ending with a closing brace and a comma.
    text = re.sub(
        r'[ \t]*\{"kind": "section",[^{}]*\},\n',
        drop,
        text,
    )

    # Collapse the blank line left behind where a block was removed.
    text = re.sub(r"\n{3,}", "\n\n", text)

    io.open(path, "w", encoding="utf-8").write(text)
    print(f"{name}: removed {removed} section step(s)")
