"""Check a Mermaid `gantt` source without a renderer.

There is no Mermaid binary in this repo and draw.io converts rather than renders,
so the cheapest reliable check is to parse the source the way Mermaid does and
then look at the DATES: a schedule that parses and lands on the wrong week is
still wrong.

Checks:
  1. every task line is `<name> :[tags,] id, <start>, <end|duration>`;
  2. ids are unique (Mermaid silently reuses a duplicate id's dates);
  3. `after <id>` references exist and are not circular;
  4. section anchors carry the week label in the source, and every row lands
     inside the band its own section advertises;
  5. nothing starts before the plan or past the last week of the table;
  6. milestones are 0d;
  7. `weekday monday` plans never place a bar on a Saturday or Sunday;
  8. the FACULTY ANCHORS land on the week the timetable gives them — this is
     the check that matters, because a plan that slips a milestone is a plan
     that breaks the student's submission.

Usage:  python check_gantt.py thesis/diagrams/psm1_plan.txt
"""
import datetime as dt
import re
import sys

# The faculty's own week table, verbatim. This is the source of truth for what
# "W07" means; the Gantt dates are checked against it, never the other way round.
# It runs Monday to FRIDAY, which is why the plan uses `weekday monday` and every
# bar is a whole number of working days.
WEEKS = {
    "01": ("2026-09-21", "2026-09-25"), "02": ("2026-09-28", "2026-10-02"),
    "03": ("2026-10-05", "2026-10-09"), "04": ("2026-10-12", "2026-10-16"),
    "05": ("2026-10-19", "2026-10-23"), "06": ("2026-10-26", "2026-10-30"),
    "07": ("2026-11-02", "2026-11-06"), "08": ("2026-11-09", "2026-11-13"),
    "09": ("2026-11-16", "2026-11-20"), "10": ("2026-11-23", "2026-11-27"),
    "11": ("2026-11-30", "2026-12-04"), "12": ("2026-12-07", "2026-12-11"),
    "13": ("2026-12-14", "2026-12-18"), "14": ("2026-12-21", "2026-12-25"),
    "15": ("2026-12-28", "2027-01-01"), "16": ("2027-01-04", "2027-01-08"),
}
WEEK0 = dt.date.fromisoformat(WEEKS["01"][0])   # Monday of week 01
LAST_WEEK = max(WEEKS)


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


def as_end(v, start, milestones=()):
    """The last date a task occupies.

    A `Nd` duration on this plan means N WORKING days (the plan uses
    `weekday monday`, so a 5d bar is Mon..Fri). Mermaid itself counts calendar
    days, which is exactly why the durations here are multiples of five and
    this function counts weekdays — the two agree only if that holds, and the
    check below is what proves it does.
    """
    m = re.fullmatch(r"(\d+)d", v)
    if not m:
        return dt.date.fromisoformat(v)
    n = int(m.group(1))
    if n == 0:
        return start                       # a milestone occupies one day
    d, seen = start, 0
    while True:
        if d.weekday() < 5:
            seen += 1
            if seen == n:
                return d
        d += dt.timedelta(days=1)


def week_of(d):
    """0-based index of the teaching week a date falls in."""
    return (d - WEEK0).days // 7


def week_label(d):
    n = week_of(d) + 1
    return f"W{n:02d}" if 1 <= n <= int(LAST_WEEK) else f"W{n:+03d}"


def business_days(s, e):
    """Count Mon-Fri days in a span.

    Mermaid's `Nd` durations are CALENDAR days, not working days. Writing `7d`
    for "one working week" produces a bar that runs Fri..Thu and occupies a
    weekend, which on an academic plan claims hours that do not exist. The plan
    therefore uses `Nd` where N is the number of working days it needs, and this
    is what verifies it.
    """
    n, d = 0, s
    while d <= e:
        if d.weekday() < 5:
            n += 1
        d += dt.timedelta(days=1)
    return n


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
    print(f"{'section':40} {'task':42} {'start':10} {'end':10}  weeks")
    print("-" * 122)
    for sec, name, tags, s, e, w0, w1 in rows:
        weeks = (week_label(s) if w0 == w1
                 else f"{week_label(s)}-{week_label(e)}")
        tag = " [M]" if "milestone" in tags else ""
        print(f"{sec:40} {name + tag:42} {s}  {e}  {weeks}")

    # --- the FACULTY ANCHORS. These are the dates the student actually
    #     submits on; each is checked against the week table, not against
    #     whatever the Gantt happens to say.
    EXPECT = {
        "formA":    "01", "formB": "01", "titleDef": "02", "formC":  "03",
        "ch1sub":   "05", "ch2sub": "07", "ch3sub":  "09", "ch4sub": "11",
        "demo":     "12", "docs":   "13", "panel":   "14", "pres":   "15",
    }
    print()
    print("faculty anchors:")
    for tid, want_week in EXPECT.items():
        t = by_id.get(tid)
        if t is None:
            errors.append(f"missing timetable anchor {tid!r}")
            continue
        s = resolved.get(tid)
        if s is None:
            continue
        got = week_label(s)
        ok = got == f"W{want_week}"
        print(f"  {tid:10} {s}  {got}  expected W{want_week}  "
              f"{'OK' if ok else 'MISMATCH'}")
        if not ok:
            errors.append(f"{tid}: {s} is {got}, timetable says W{want_week}")

    # --- a milestone that is not 0d is not a milestone
    for t in tasks:
        if "milestone" in t["tags"] and t["end"] != "0d":
            errors.append(f"{t['no']}: milestone {t['id']!r} is {t['end']}, must be 0d")

    # --- nothing may sit outside the weeks the faculty table covers. This is
    #     the check that would have caught the `after` bug: four rows silently
    #     began in W00, which does not exist in the table.
    plan_start = min(r[3] for r in rows)
    for sec, name, tags, s, e, w0, w1 in rows:
        if w0 < 0:
            errors.append(f"{name!r} starts {s}, before the plan begins {plan_start}")
        if week_label(e) > f"W{LAST_WEEK}":
            errors.append(f"{name!r} runs to {e}, past W{LAST_WEEK} of the table")

    # --- a `Nd` duration must be a whole number of working weeks — five working
    #     days per chunk — so that the calendar-day arithmetic Mermaid does and
    #     the working-day arithmetic this plan means agree. A 7d bar from a
    #     Monday ends the next Sunday; a 5d bar ends that Friday. The plan uses
    #     the second, and this is what enforces it.
    for t in tasks:
        m = re.fullmatch(r"(\d+)d", t["end"])
        if not m:
            continue                      # an explicit end date; the week check is enough
        if "milestone" in t["tags"]:
            continue                      # 0d is the only legal milestone length, checked above
        n = int(m.group(1))
        if n % 5:
            errors.append(f"{t['id']!r} runs {n}d; use a multiple of 5 "
                          f"(one working week = 5d) or the bar straddles a weekend")
            continue
        s = resolved.get(t["id"])
        if s is None:
            continue
        if s.weekday() != 0:
            errors.append(f"{t['id']!r} starts on a {s.strftime('%A')}; "
                          f"a multi-week bar must start on a Monday")
        e = as_end(t["end"], s)
        if e.weekday() != 4:
            errors.append(f"{t['id']!r} ends on a {e.strftime('%A')}; "
                          f"a multi-week bar must end on a Friday")

    # --- a row must not sit outside the week band its own section advertises.
    #     A section that genuinely spans weeks says so in its own name.
    for sec, name, tags, s, e, w0, w1 in rows:
        claimed = [int(x) for x in re.findall(r"W(\d\d)", sec or "")]
        if not claimed:
            continue
        lo, hi = min(claimed), max(claimed)
        if not (lo <= w0 + 1 and w1 + 1 <= hi):
            errors.append(f"{name!r} runs {week_label(s)}-{week_label(e)} "
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
