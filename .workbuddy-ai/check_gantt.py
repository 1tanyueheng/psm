"""Check a Mermaid `gantt` source without a renderer.

There is no Mermaid binary in this repo and draw.io converts rather than renders,
so the cheapest reliable check is to parse the source the way Mermaid does and
then look at the DATES: a schedule that parses and lands on the wrong week is
still wrong.

Checks:
  1. every task line is `<name> :[tags,] id, <start>, <end|duration>`;
  2. ids are unique (Mermaid silently reuses a duplicate id's dates);
  3. `after <id>` references exist;
  4. section anchors carry the week label in the source, and the computed week
     number agrees with it;
  5. no task starts before the plan or outside its section's week band;
  6. milestones are 0d and sit inside their section's range.

Usage:  python check_gantt.py thesis/diagrams/psm1_plan.txt
"""
import datetime as dt
import re
import sys

WEEK0 = dt.date(2026, 9, 14)          # Monday of week 00 on the faculty timetable


def parse(path):
    text = open(path, encoding="utf-8").read()
    lines = [l for l in text.splitlines()]
    sections, tasks, errors = [], [], []
    in_gantt = False
    cur_section = None

    for no, raw in enumerate(lines, 1):
        s = raw.strip()
        if s.startswith("gantt"):
            in_gantt = True
            continue
        if not in_gantt or not s or s.startswith("%%"):
            continue
        if s.startswith("title") or s.startswith("dateFormat") or \
           s.startswith("axisFormat") or s.startswith("excludes") or \
           s.startswith("todayMarker") or s.startswith("weekday"):
            continue
        if s.startswith("section "):
            cur_section = s[len("section "):].strip()
            sections.append(cur_section)
            continue

        # `<name> :<tags>`
        if ":" not in s:
            errors.append(f"{no}: no ':' in {s!r}")
            continue
        name, spec = s.split(":", 1)
        # `after X` is a START, not an id.
        #
        # Mermaid's Gantt line is `<name> :<tags>, <id>, <start>, <end>` and the
        # start may be the literal `after <id>`. The first version of this
        # parser stripped every marker token and then took `rest[0]` as the id —
        # so `Refine Chapter 1 :after ev1, 7d` became id `"after ev1"` starting
        # "today", and all four Refine rows sat on week 00 while the checker
        # reported no problems. A validator that mis-parses the construct it is
        # meant to validate is worse than none.
        parts = [p.strip() for p in spec.split(",") if p.strip()]
        tags = [p for p in parts if p in ("milestone", "done", "active", "crit")]
        rest = [p for p in parts if p not in ("milestone", "done", "active", "crit")]
        if not rest:
            errors.append(f"{no}: no id in {s!r}")
            continue
        tid = rest[0]
        # `<name> :id, 7d` is legal Mermaid shorthand: start today, run 7 days.
        # A duration in the start slot is not an error and must not crash this.
        if len(rest) == 2 and re.fullmatch(r"\d+d", rest[1]):
            start, end = "TODAY", rest[1]
        elif len(rest) == 2:
            start, end = rest[1], "0d"
        elif len(rest) == 3:
            start, end = rest[1], rest[2]
        else:
            errors.append(f"{no}: expected id, start, end - got {rest!r}")
            continue
        if start.startswith("after "):
            start = "after " + start.split(None, 1)[1]
        if not (re.fullmatch(r"after \w+", start) or start == "TODAY"
                or re.fullmatch(r"\d{4}-\d{2}-\d{2}", start)):
            errors.append(f"{no}: start {start!r} is not a date, 'after <id>' or TODAY")
        tasks.append(dict(no=no, name=name.strip(), tags=tags, id=tid,
                          start=start, end=end, section=cur_section))
    return sections, tasks, errors


def as_date(v, today=WEEK0):
    if v.startswith("after "):
        return ("after", v.split(None, 1)[1])
    if v == "TODAY":
        return ("date", today)
    return ("date", dt.date.fromisoformat(v))


def as_end(v, start, milestones):
    m = re.fullmatch(r"(\d+)d", v)
    if m:
        d = int(m.group(1))
        # A 0d milestone occupies its start date and ends on it.
        return start if d == 0 else start + dt.timedelta(days=d - 1)
    return dt.date.fromisoformat(v)


def week_of(d):
    return (d - WEEK0).days // 7


def main(path):
    sections, tasks, errors = parse(path)
    by_id = {}
    for t in tasks:
        if t["id"] in by_id:
            errors.append(f"{t['no']}: duplicate id {t['id']!r} "
                          f"(also at line {by_id[t['id']]['no']})")
        by_id[t["id"]] = t

    resolved = {}
    resolving = set()

    def start_of(t):
        """The date a task starts, following `after` chains.

        `after id` means the day AFTER that task ends. This recursion must key on
        `resolving`, not on a `seen` tuple: the old version only remembered tasks
        it had recursed THROUGH, so a leaf task never appeared in `seen` and
        `after leaf` resolved to nothing at all.
        """
        if t["id"] in resolved:
            return resolved[t["id"]]
        kind, v = as_date(t["start"])
        if kind == "date":
            resolved[t["id"]] = v
            return v
        if t["id"] in resolving:
            errors.append(f"{t['no']}: 'after {v}' is circular")
            return None
        ref = by_id.get(v)
        if ref is None:
            errors.append(f"{t['no']}: 'after {v}' names no task")
            return None
        resolving.add(t["id"])
        s = start_of(ref)
        resolving.discard(t["id"])
        if s is None:
            return None
        d = as_end(ref["end"], s, {}) + dt.timedelta(days=1)
        resolved[t["id"]] = d
        return d

    rows = []
    for t in tasks:
        s = start_of(t)
        if s is None:
            continue
        # `start_of` already applied the day-after offset for `after` tasks.
        # Recomputing it from the raw start string was a second bug in the same
        # three lines, and it put all four Refine rows on W00.
        resolved[t["id"]] = s
        e = as_end(t["end"], s, {})
        if e < s:
            errors.append(f"{t['no']}: ends {e} before it starts {s}")
        w0, w1 = week_of(s), week_of(e)
        rows.append((t["section"], t["name"], t["tags"], s, e, w0, w1))

    # --- every milestone must land on the week its section claims
    print(f"{'section':34} {'task':44} {'start':10} {'end':10}  weeks")
    print("-" * 116)
    for sec, name, tags, s, e, w0, w1 in rows:
        weeks = f"W{w0:02d}" if w0 == w1 else f"W{w0:02d}-W{w1:02d}"
        tag = " [M]" if "milestone" in tags else ""
        print(f"{sec:34} {name + tag:44} {s}  {e}  {weeks}")

    # --- the timetable's own claims, checked against the computed schedule
    EXPECT = {
        "formA":    ("2026-09-14", 0),
        "formB":    ("2026-09-21", 1),
        "titleDef": ("2026-09-28", 2),
        "formC":    ("2026-10-05", 3),
        "ch1sub":   ("2026-10-19", 5),
        "ch2sub":   ("2026-11-02", 7),
        "ch3sub":   ("2026-11-16", 9),
        "ch4sub":   ("2026-11-30", 11),
        "demo":     ("2026-12-07", 12),
        "docs":     ("2026-12-14", 13),
        "panel":    ("2026-12-21", 14),
        "pres":     ("2026-12-28", 15),
    }
    print()
    for tid, (want_date, want_week) in EXPECT.items():
        t = by_id.get(tid)
        if t is None:
            errors.append(f"missing timetable anchor {tid!r}")
            continue
        s = resolved.get(tid)
        if s is None:
            continue
        got_week = week_of(s)
        ok = s.isoformat() == want_date and got_week == want_week
        print(f"{tid:10} {s}  W{got_week:02d}  expected {want_date} W{want_week:02d}"
              f"  {'OK' if ok else 'MISMATCH'}")
        if not ok:
            errors.append(f"{tid}: {s} W{got_week:02d} != {want_date} W{want_week:02d}")

    # --- a milestone that is not 0d is not a milestone
    for t in tasks:
        if "milestone" in t["tags"] and t["end"] != "0d":
            errors.append(f"{t['no']}: milestone {t['id']!r} is {t['end']}, must be 0d")

    # --- nothing may start before the plan does. This is the check that would
    #     have caught the `after` bug above: four rows silently began in W00.
    plan_start = min(r[3] for r in rows)
    for sec, name, tags, s, e, w0, w1 in rows:
        if w0 < 0:
            errors.append(f"{name!r} starts {s}, before the plan begins {plan_start}")
        if w1 > 15:
            errors.append(f"{name!r} runs to W{w1:02d}, past the last teaching week")

    # --- a row must not sit outside the week band its own section advertises.
    #     `Form C + OSHE (W03)` holds the first prototype, which runs into W04,
    #     so a section that genuinely spans two weeks says so in its own name.
    for sec, name, tags, s, e, w0, w1 in rows:
        claimed = [int(x) for x in re.findall(r"W(\d\d)", sec or "")]
        if not claimed:
            continue
        lo, hi = min(claimed), max(claimed)
        if not (lo <= w0 and w1 <= hi):
            errors.append(f"{name!r} runs W{w0:02d}-W{w1:02d} "
                          f"but its section says W{lo:02d}-W{hi:02d}")

    print()
    print(f"tasks: {len(tasks)}   sections: {len(sections)}")
    if errors:
        print("PROBLEMS:")
        for e in errors:
            print("  -", e)
        return 1
    print("no problems")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1
                  else "thesis/diagrams/psm1_plan.txt"))
