# Do not commit a hand-written lock file here.

`package-lock.json` is deliberately absent from the scaffold. Generate it
once on a machine with working npm:

```sh
cd frontend
npm install
```

That produces a real lock file — 390+ entries with `resolved` URLs and
`integrity` hashes — which should then be committed. From that point on,
`npm ci` in the Docker build is reproducible and pinned.

## Why there is no lock file yet

The scaffold was authored in an environment where npm could not write into
this directory. `npm install` failed with `EBADF: bad file descriptor` and
`--package-lock-only` failed with `ENOENT` on this exact path, while the
surrounding tooling could write files normally. Rather than commit a
hand-written file with the correct *shape* but no dependency entries — which
is worse than nothing, because `npm ci` would accept it and then install
nothing — the file is simply left out.

## How the build copes

The `assets` stage of `../Dockerfile` checks whether the lock file is present
*and* carries at least one resolved package:

```sh
grep -q '"node_modules/' package-lock.json
```

- **Matches** → `npm ci`, reproducible build from the pinned tree.
- **No match, or file absent** → `npm install`, resolving the ranges in
  `package.json`.

The `node_modules/` prefix only appears once npm has written real entries, so
this distinguishes a genuine lock file from a stub. Worst case the image
resolves whatever the ranges allow at build time, which is what would happen
anyway without a lock file. Nothing breaks; the build is just less
deterministic until the real lock file is committed.

## Verifying

`tools/make_lockfile.py --check` reports whether an existing lock file agrees
with `package.json`, which is the check `npm ci` performs before it installs.
