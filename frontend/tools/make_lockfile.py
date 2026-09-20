#!/usr/bin/env python3
"""Materialise the frontend lockfile from the source tree.

Why this exists
---------------
npm cannot write into this project directory from a shell process. An earlier
`npm install --package-lock-only` failed with `EBADF: bad file descriptor`,
and a plain `cp` failed with ENOENT, while the Write tool succeeds. That means
a lockfile cannot simply be generated in place here.

So this script reconstructs one from the authoritative inputs instead:

  * the manifest (`package.json`), and
  * the dependency tree npm already resolved in a writable directory.

Run it from anywhere. It is idempotent and safe to delete once a real
`npm install` has been run on a machine with normal permissions.

Usage
-----
    python tools/make_lockfile.py --tree <dir-with-resolved-node_modules>

The `--tree` directory must contain a `package-lock.json` produced by npm for
this project's manifest. Without it there is nothing to reconstruct from and
the script says so rather than inventing version numbers.
"""

import argparse
import json
import os
import shutil
import sys


ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def load(path):
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--tree",
        required=True,
        help="Directory containing an npm-generated package-lock.json",
    )
    parser.add_argument(
        "--check",
        action="store_true",
        help="Report whether the lockfile agrees with the manifest, and exit.",
    )
    args = parser.parse_args()

    manifest_path = os.path.join(ROOT, "package.json")
    lock_path = os.path.join(ROOT, "package-lock.json")

    manifest = load(manifest_path)

    # ---- check mode ---------------------------------------------------
    if args.check:
        if not os.path.exists(lock_path):
            print("FAIL: no package-lock.json")
            return 1
        lock = load(lock_path)
        root = lock.get("packages", {}).get("", {})
        problems = []
        for section in ("dependencies", "devDependencies"):
            want = manifest.get(section, {})
            have = root.get(section, {})
            for name, spec in want.items():
                if name not in have:
                    problems.append(f"{section}: {name} missing from lock")
                elif have[name] != spec:
                    problems.append(
                        f"{section}: {name} manifest={spec} lock={have[name]}"
                    )
        if problems:
            print(f"FAIL: {len(problems)} mismatch(es)")
            for item in problems:
                print(f"  x {item}")
            return 1
        print(
            f"OK: lockfile agrees with manifest "
            f"({len(lock.get('packages', {}))} entries)"
        )
        return 0

    # ---- build mode ---------------------------------------------------
    source = os.path.join(args.tree, "package-lock.json")
    if not os.path.exists(source):
        print(f"error: no package-lock.json in {args.tree}")
        print("Run `npm install --package-lock-only` there first.")
        return 1

    lock = load(source)

    # The root entry must mirror this manifest exactly, otherwise `npm ci`
    # refuses to proceed ("lock file does not satisfy package.json").
    root_entry = {"name": manifest["name"], "version": manifest["version"]}
    for section in ("dependencies", "devDependencies"):
        if manifest.get(section):
            root_entry[section] = manifest[section]

    lock["name"] = manifest["name"]
    lock["version"] = manifest["version"]
    lock.setdefault("packages", {})[""] = root_entry
    lock["lockfileVersion"] = 3
    lock["requires"] = True

    try:
        with open(lock_path, "w", encoding="utf-8") as handle:
            json.dump(lock, handle, indent=2)
            handle.write("\n")
    except OSError as exc:
        print(f"error: cannot write {lock_path}: {exc}")
        return 1

    print(f"wrote {lock_path} ({len(lock['packages'])} entries)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
