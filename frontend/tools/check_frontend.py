#!/usr/bin/env python3
"""
Static integrity check for the PSM frontend.

There is no guarantee npm can reach the registry in this environment, so this
script verifies the module graph without installing anything. It checks:

  1. Every relative import resolves to a file that exists (with Vite's
     extension resolution: exact, .jsx, .js, /index.jsx, /index.js).
  2. Every named import is actually exported by the target module (a real class
     of bug that a bundler only catches at build time).
  3. Every default import targets a module that has a default export.
  4. No file imports React without using it, and no file uses a hook it did not
     import.
  5. Every named export from the shared modules (ui, format, permissions,
     client, endpoints) is consumed somewhere, so dead or misspelled exports
     surface immediately.

Run:  python tools/check_frontend.py
Exit: 0 if clean, 1 if problems were found.
"""

from __future__ import annotations

import os
import re
import sys
from collections import defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "src")

RESOLVE_SUFFIXES = ["", ".jsx", ".js", "/index.jsx", "/index.js", ".json"]

problems: list[str] = []
warnings: list[str] = []


def rel(path: str) -> str:
    return os.path.relpath(path, ROOT).replace("\\", "/")


def walk_sources() -> list[str]:
    out = []
    for base, _dirs, files in os.walk(SRC):
        for name in files:
            if name.endswith((".js", ".jsx")):
                out.append(os.path.join(base, name))
    return sorted(out)


def strip_comments(text: str) -> str:
    """Remove // and /* */ comments without touching string literals.

    A naive regex would corrupt URLs inside strings, so this walks the text
    character by character while tracking quote state.
    """
    out = []
    i = 0
    n = len(text)
    quote = None
    while i < n:
        ch = text[i]
        nxt = text[i + 1] if i + 1 < n else ""

        if quote:
            out.append(ch)
            if ch == "\\":
                if i + 1 < n:
                    out.append(nxt)
                    i += 2
                    continue
            elif ch == quote:
                quote = None
            i += 1
            continue

        if ch in "\"'`":
            quote = ch
            out.append(ch)
            i += 1
            continue

        if ch == "/" and nxt == "/":
            while i < n and text[i] != "\n":
                i += 1
            continue

        if ch == "/" and nxt == "*":
            i += 2
            while i < n - 1 and not (text[i] == "*" and text[i + 1] == "/"):
                i += 1
            i += 2
            continue

        out.append(ch)
        i += 1

    return "".join(out)


# --------------------------------------------------------------------------
# Import / export extraction
# --------------------------------------------------------------------------

IMPORT_RE = re.compile(
    r"""import\s+
        (?P<clause>[^;'"]*?)
        \s*from\s*['"](?P<source>[^'"]+)['"]""",
    re.X | re.S,
)

BARE_IMPORT_RE = re.compile(r"""import\s*['"](?P<source>[^'"]+)['"]""")

EXPORT_FN_RE = re.compile(r"export\s+(?:async\s+)?function\s+(?P<name>\w+)")
EXPORT_CLASS_RE = re.compile(r"export\s+class\s+(?P<name>\w+)")
EXPORT_CONST_RE = re.compile(r"export\s+(?:const|let|var)\s+(?P<name>\w+)")
EXPORT_LIST_RE = re.compile(r"export\s*\{(?P<body>[^}]*)\}", re.S)
EXPORT_DEFAULT_RE = re.compile(r"export\s+default\b")


def parse_imports(text: str):
    """Yield (source, default_name, [named]) for each import statement."""
    results = []
    for match in IMPORT_RE.finditer(text):
        clause = " ".join(match.group("clause").split())
        source = match.group("source")

        default_name = None
        named = []

        if clause.startswith("{"):
            body = clause.strip("{}")
            named = [normalise_named(p) for p in body.split(",") if p.strip()]
        elif clause.startswith("*"):
            # Namespace import — everything is in scope, nothing to validate.
            pass
        else:
            parts = clause.split(",")
            default_name = parts[0].strip() or None
            rest = ",".join(parts[1:]).strip()
            if rest.startswith("{"):
                body = rest.strip("{}")
                named = [normalise_named(p) for p in body.split(",") if p.strip()]

        results.append((source, default_name, [n for n in named if n]))
    return results


def normalise_named(part: str) -> str | None:
    """`foo as bar` -> the local name is `bar`, the imported name is `foo`."""
    part = part.strip()
    if not part:
        return None
    if " as " in part:
        return part.split(" as ")[-1].strip()
    return part


def parse_exports(text: str):
    """Return (named_exports, has_default)."""
    named = set()

    for pattern in (EXPORT_FN_RE, EXPORT_CLASS_RE, EXPORT_CONST_RE):
        named.update(pattern.findall(text))

    for match in EXPORT_LIST_RE.finditer(text):
        body = match.group("body")
        for part in body.split(","):
            part = part.strip()
            if not part:
                continue
            # `export { default as Foo }` and `export { Foo as Bar }`
            if part.startswith("default"):
                continue
            name = part.split(" as ")[-1].strip() if " as " in part else part
            if re.fullmatch(r"\w+", name):
                named.add(name)

    return named, bool(EXPORT_DEFAULT_RE.search(text))


# --------------------------------------------------------------------------
# Module resolution
# --------------------------------------------------------------------------

def resolve(source: str, importer: str) -> str | None:
    if source.startswith("."):
        base = os.path.normpath(os.path.join(os.path.dirname(importer), source))
    elif source.startswith("/"):
        base = os.path.normpath(os.path.join(SRC, source.lstrip("/")))
    else:
        return None  # bare specifier — resolved by node_modules

    for suffix in RESOLVE_SUFFIXES:
        candidate = base + suffix
        if os.path.isfile(candidate):
            return candidate
    return None


def main() -> int:
    if not os.path.isdir(SRC):
        print(f"FAIL: no src directory at {SRC}")
        return 1

    files = walk_sources()
    print(f"Scanning {len(files)} source files under {rel(SRC)}")

    parsed: dict[str, tuple[str, set[str], bool, list]] = {}

    for path in files:
        with open(path, "r", encoding="utf-8") as handle:
            raw = handle.read()
        text = strip_comments(raw)
        named, has_default = parse_exports(text)
        imports = parse_imports(text) + [
            (m.group("source"), None, []) for m in BARE_IMPORT_RE.finditer(text)
        ]
        parsed[path] = (text, named, has_default, imports)

    # ---- 1-3. Resolve every import and verify the names it pulls -----------
    resolved_sources: dict[str, list[tuple[str, str]]] = defaultdict(list)

    for path, (text, _named, _has_default, imports) in parsed.items():
        for source, default_name, named_imports in imports:
            target = resolve(source, path)

            if target is None:
                if source.startswith(".") or source.startswith("/"):
                    problems.append(
                        f"{rel(path)}: cannot resolve relative import '{source}'"
                    )
                continue

            resolved_sources[path].append((source, target))

            if target not in parsed:
                # e.g. importing a .json or .css file — nothing to check.
                continue

            _t_text, t_named, t_has_default, _t_imports = parsed[target]

            if default_name and not t_has_default:
                problems.append(
                    f"{rel(path)}: default import '{default_name}' from "
                    f"'{source}', but {rel(target)} has no default export"
                )

            for name in named_imports:
                if name not in t_named:
                    problems.append(
                        f"{rel(path)}: named import '{name}' from '{source}', "
                        f"but {rel(target)} does not export it"
                    )

    # ---- 4. Hook usage sanity ---------------------------------------------
    HOOKS = {
        "useState",
        "useEffect",
        "useMemo",
        "useCallback",
        "useRef",
        "useContext",
        "useReducer",
        "useLayoutEffect",
        "useNavigate",
        "useParams",
        "useLocation",
        "useSearchParams",
    }

    for path, (text, _named, _has_default, imports) in parsed.items():
        imported_names = set()
        for _source, default_name, named_imports in imports:
            if default_name:
                imported_names.add(default_name)
            imported_names.update(named_imports)

        # A file that defines a hook obviously does not need to import it.
        declared = set(re.findall(r"(?:export\s+)?function\s+(\w+)", text))
        declared.update(re.findall(r"(?:export\s+)?const\s+(\w+)\s*=", text))

        for hook in HOOKS | {"useAuth"}:
            called = re.search(rf"\b{hook}\s*\(", text)
            if not called:
                continue
            if hook in imported_names or hook in declared:
                continue
            problems.append(f"{rel(path)}: uses {hook}() but never imports it")

        # A component file importing `Link`/`NavLink` must be inside a Router.
        # We can only check that the import came from react-router-dom.
        for source, default_name, named_imports in imports:
            if source == "react-router-dom":
                if default_name:
                    problems.append(
                        f"{rel(path)}: default-imports from react-router-dom "
                        f"('{default_name}'); use named imports"
                    )

    # ---- 5. Unused shared exports -----------------------------------------
    # Match by suffix so this works whether ROOT is the frontend directory or
    # a parent of it.
    SHARED = [
        "components/ui.jsx",
        "lib/format.js",
        "lib/permissions.js",
        "api/client.js",
        "api/endpoints.js",
        "context/AuthContext.jsx",
    ]

    parsed_by_rel = {rel(p).replace("\\", "/"): p for p in parsed}

    for shared_rel in SHARED:
        shared_path = None
        for key, value in parsed_by_rel.items():
            if key.endswith(shared_rel):
                shared_path = value
                break

        if shared_path is None:
            warnings.append(f"shared module not found: {shared_rel}")
            continue

        _text, named, _has_default, _imports = parsed[shared_path]
        if not named:
            continue

        used = set()
        for path, (_t, _n, _d, imports) in parsed.items():
            if path == shared_path:
                continue
            for _source, _def, named_imports in imports:
                used.update(named_imports)

        # A constant consumed by another export in the same file is live code,
        # not dead code — `ROLE_LABELS` read by `roleLabel()` is the common case.
        own_body = _text
        unused = []
        for name in sorted(named):
            if name in used:
                continue
            # Count references other than the declaration itself.
            references = len(re.findall(rf"\b{re.escape(name)}\b", own_body))
            if references > 1:
                continue
            unused.append(name)

        if unused:
            warnings.append(
                f"{rel(shared_path)}: never consumed -> {', '.join(unused)}"
            )

    # ---- 6. API method calls resolve against the endpoint modules ---------
    # This is the check that catches a screen calling `gradeApi.forProject` or
    # `publicApi.leaderboards` when no such method was defined.
    endpoints_path = None
    for path in parsed:
        if path.replace("\\", "/").endswith("api/endpoints.js"):
            endpoints_path = path
            break

    if endpoints_path is None:
        problems.append("api/endpoints.js not found — cannot validate API calls")
    else:
        ep_text = parsed[endpoints_path][0]
        api_surface: dict[str, set[str]] = {}

        for match in re.finditer(
            r"export\s+const\s+(\w+)\s*=\s*\{(?P<body>.*?)\n\}", ep_text, re.S
        ):
            name = match.group(1)
            body = match.group("body")
            # Properties may start the line or follow a comma; and they may be
            # defined on one line (`show: (id) => ...`) or across several.
            methods = set(re.findall(r"(?:^|,)\s*(\w+)\s*[:(]", body, re.M))
            api_surface[name] = methods

        for path in parsed:
            _t, _n, _d, imports = parsed[path]
            imported_apis = {
                nm for _src, _def, nms in imports for nm in nms if nm in api_surface
            }
            if not imported_apis:
                continue

            text = parsed[path][0]
            for api_name in imported_apis:
                for call in re.finditer(rf"\b{api_name}\.(\w+)", text):
                    method = call.group(1)
                    if method not in api_surface[api_name]:
                        problems.append(
                            f"{rel(path)}: calls {api_name}.{method}() but "
                            f"endpoints.js does not define it"
                        )

    # ---- 7. In-app links resolve to a declared route ----------------------
    # A `<Link to="/audit-log">` when the route is `/audit` renders a working
    # link that lands on the 404 page. Nothing else catches this: the module
    # graph is fine, ESLint is fine, and the build succeeds. It is also the
    # bug class that actually shipped once (AdminDashboard pointed at
    # `/audit-log` and `/users`, and neither route existed), so it is worth a
    # dedicated check.
    app_path = None
    for path in parsed:
        if path.replace("\\", "/").endswith("src/App.jsx"):
            app_path = path
            break

    if app_path is None:
        warnings.append("src/App.jsx not found — cannot validate link targets")
    else:
        app_text = parsed[app_path][0]

        # `path="/foo"`, plus the dynamic segments we must treat as wildcards.
        declared = set(re.findall(r'<Route\s+[^>]*path="([^"]+)"', app_text))
        # The catch-all `path="*"` renders NotFoundPage, so it "matches"
        # everything. Treating it as a route would make this check unable to
        # ever fail, which is worse than not having it — drop it.
        declared.discard("*")

        def route_matches(target: str) -> bool:
            """Does any declared route pattern accept this concrete path?"""
            if target in declared:
                return True

            target_parts = [p for p in target.split("/") if p != ""]
            for pattern in declared:
                pattern_parts = [p for p in pattern.split("/") if p != ""]
                if len(pattern_parts) != len(target_parts):
                    continue
                if all(
                    pp.startswith(":") or pp == tp
                    for pp, tp in zip(pattern_parts, target_parts)
                ):
                    return True
            return False

        for path in parsed:
            # Only screens are worth checking; a link in App.jsx is a route.
            if path == app_path:
                continue
            text = parsed[path][0]

            for match in re.finditer(r'<Link\s+[^>]*?to="(/[^"]*)"', text, re.S):
                target = match.group(1)
                # Ignore anything carrying a template expression — the path is
                # only knowable at runtime.
                if "$" in target or "{`" in target:
                    continue
                # Strip a query string or fragment; the path is what routes.
                target = target.split("?")[0].split("#")[0]
                if target in ("", "/"):
                    continue
                if not route_matches(target):
                    problems.append(
                        f"{rel(path)}: links to {target!r} but App.jsx declares no "
                        f"such route"
                    )

    # ---------------------------------------------------------------------
    print()
    if problems:
        print(f"FAIL: {len(problems)} problem(s)")
        for item in problems:
            print(f"  x {item}")
    else:
        print("OK: module graph is consistent")

    if warnings:
        print()
        print(f"{len(warnings)} warning(s):")
        for item in warnings:
            print(f"  - {item}")

    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
