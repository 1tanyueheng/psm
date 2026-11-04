"""Prove the scope endpoint over HTTP, including the refusals.

The endpoint is the whole point of this change: before it, a coordinator's scope
could only be set in the request that created them. These are the cases that
matter, and the ones where being wrong grants access:

  1. admin sets programme scopes                      -> 200, rows written
  2. the list replaces rather than merges             -> 200, old rows gone
  3. a non-coordinator is refused                     -> 422
  4. more than one head row is refused                -> 422
  5. a coordinator cannot widen their own scope       -> 403
  6. an empty list clears the scope                   -> 200, no rows, fail-open

Run with Docker up. Restores the seeded state at the end.
"""
import json
import subprocess
import sys
import urllib.error
import urllib.request

BASE = "http://localhost:8000/api"


def call(method, path, token=None, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, method=method)
    req.add_header("Accept", "application/json")
    if data:
        req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", f"Bearer {token}")

    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode())


def login(email, password="password"):
    status, payload = call("POST", "/auth/login", body={"email": email, "password": password})
    assert status == 200, (status, payload)
    return payload["data"]["token"]


def main():
    admin = login("admin@psm.test")
    coordinator = login("coordinator@psm.test")

    status, payload = call("GET", "/users?per_page=100", admin)
    users = payload["data"]

    coord = next(u for u in users if u["role"] == "coordinator")
    student = next(u for u in users if u["role"] == "student")
    results = []

    def check(label, ok, detail=""):
        results.append((label, ok, detail))
        print(f"  {'PASS' if ok else 'FAIL'}  {label}" + (f"  -- {detail}" if detail else ""))

    # --- 1. set programme scopes -----------------------------------------
    status, payload = call("PUT", f"/users/{coord['id']}/scopes", admin,
                           {"scopes": [{"program": "BIP"}, {"program": "BIS"}]})
    check("admin sets two programmes", status == 200, f"HTTP {status}")

    status, payload = call("GET", f"/users/{coord['id']}", admin)
    programs = sorted(s["program"] for s in payload["data"]["coordinator_scopes"])
    check("both rows written", programs == ["BIP", "BIS"], str(programs))
    check("response no longer carries a batch field",
          all("batch" not in s for s in payload["data"]["coordinator_scopes"]))

    # --- 2. replace, not merge -------------------------------------------
    status, payload = call("PUT", f"/users/{coord['id']}/scopes", admin,
                           {"scopes": [{"program": "BIT"}]})
    status, payload = call("GET", f"/users/{coord['id']}", admin)
    programs = [s["program"] for s in payload["data"]["coordinator_scopes"]]
    check("the list replaces the old rows", programs == ["BIT"], str(programs))

    # --- 3. a non-coordinator --------------------------------------------
    status, payload = call("PUT", f"/users/{student['id']}/scopes", admin,
                           {"scopes": [{"program": "BIP"}]})
    check("a student has no scope to set", status == 422, f"HTTP {status}")

    # --- 4. two heads ------------------------------------------------------
    status, payload = call("PUT", f"/users/{coord['id']}/scopes", admin,
                           {"scopes": [{"is_primary": True}, {"is_primary": True}]})
    check("two head rows are refused", status == 422, f"HTTP {status}")

    # --- 5. self escalation ------------------------------------------------
    status, payload = call("PUT", f"/users/{coord['id']}/scopes", coordinator,
                           {"scopes": [{"is_primary": True}]})
    check("a coordinator cannot widen their own scope", status == 403, f"HTTP {status}")

    # --- 6. empty clears ---------------------------------------------------
    status, payload = call("PUT", f"/users/{coord['id']}/scopes", admin, {"scopes": []})
    status, payload = call("GET", f"/users/{coord['id']}", admin)
    check("an empty list clears the scope (fail-open)",
          payload["data"]["coordinator_scopes"] == [],
          str(payload["data"]["coordinator_scopes"]))

    # --- restore the seeded head scope ------------------------------------
    call("PUT", f"/users/{coord['id']}/scopes", admin, {"scopes": [{"is_primary": True}]})
    status, payload = call("GET", f"/users/{coord['id']}", admin)
    restored = payload["data"]["coordinator_scopes"]
    check("restored to the seeded head scope",
          len(restored) == 1 and restored[0]["is_primary"] is True, str(restored))

    failed = [r for r in results if not r[1]]
    print()
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
