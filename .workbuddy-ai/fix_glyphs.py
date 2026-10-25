import io
p = "write_chapter2_v4.py"
s = io.open(p, encoding="utf-8").read()
bad_tick = "\u00e2\u0153\u201d"
bad_cross = "\u00e2\u0153\u2013"
print("bad tick present:", bad_tick in s, "| bad cross present:", bad_cross in s)
s = s.replace(bad_tick, "\u2714").replace(bad_cross, "\u2716")
io.open(p, "w", encoding="utf-8").write(s)
s2 = io.open(p, encoding="utf-8").read()
print("after: tick count", s2.count("\u2714"), "| cross count", s2.count("\u2716"))
print("any mojibake left:", "\u00e2\u0153" in s2)
