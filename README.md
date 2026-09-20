# PSM Management System

Final Year Project (PSM) lifecycle management platform for a university faculty
— from supervisor assignment through milestone delivery, rubric marking,
moderation, archiving, and a public award display.

Stack: **React 18 (Vite, JavaScript)** · **Laravel 11 (PHP 8.2)** · **MySQL 8** · **Docker on Render**

---

## Modules

| # | Module | Primary stakeholders | Key screens |
|---|--------|---------------------|-------------|
| 1 | Authentication & RBAC | Everyone | `pages/auth/*` |
| 2 | User & Profile Management | Admin, Coordinator | `pages/assignments`, `pages/account/ProfilePage` |
| 3 | Project & Milestone Management | Student, Supervisor, Coordinator | `pages/projects/*`, `pages/milestones/*` |
| 4 | Evaluation & Rubric Grading | Supervisor, Examiner, Coordinator | `pages/evaluations/*` |
| 5 | Reporting & Analytics | Coordinator, Admin | `pages/reports`, `pages/coordinator` |
| 6 | Notification & Reminder | Everyone | `pages/account/NotificationPage` |
| 7 | Archive & Audit Log | Admin, Coordinator | `pages/archive/*` |
| 8 | Public Leaderboard | Public (no login) | `pages/public/PublicLeaderboardPage` |

---

## Layout

```
psm-system/
├── backend/            Laravel 11 API
│   ├── app/            Models, controllers, services, policies, enums
│   ├── database/       Migrations and seeders
│   ├── routes/api.php  The API contract
│   └── tools/          Static verification scripts
├── frontend/           React SPA
│   ├── src/api/        The whole API surface, one file per concern
│   ├── src/components/ Design system, layout shell, route guards
│   ├── src/lib/        Permissions and formatting helpers
│   ├── src/pages/      One folder per module
│   └── tools/          Module-graph and API-contract checker
├── docker/             nginx, php.ini, supervisord, entrypoint
├── docs/               Architecture, ERD, modules, API, setup
├── Dockerfile          3-stage build
└── docker-compose.yml  app + db + mailpit (+ optional tools)
```

---

## Quick start

```bash
cp backend/.env.example backend/.env
docker compose up --build
```

Then open <http://localhost:8080>. The public award board is at
<http://localhost:8080/leaderboard> — no login required.

For the native (non-Docker) path, the demo accounts, seeding options, and
deployment notes, see **[`docs/SETUP.md`](docs/SETUP.md)**.

### Demo accounts

Every seeded account uses the password `password`. Each role has one — see the
table in `docs/SETUP.md`, or use the one-tap buttons on the sign-in screen.

---

## Documentation

| Document | What it covers |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | System shape, layers, the milestone state machine, the assessment engine, frontend structure |
| [`docs/ERD.md`](docs/ERD.md) | All 37 tables by module, plus the invariants worth preserving |
| [`docs/MODULES.md`](docs/MODULES.md) | Requirement → built → decisions, per module, then the cross-module rules |
| [`docs/API.md`](docs/API.md) | Every endpoint, the response envelope, worked examples |
| [`docs/SETUP.md`](docs/SETUP.md) | Docker and native setup, seeding, tests, troubleshooting |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Where each piece can be hosted, why Laravel cannot run on Vercel, the database choice, and what to set up beyond the code |
| [`WHAT_TO_DO_NEXT.md`](WHAT_TO_DO_NEXT.md) | Getting it running for the first time, written for someone who has not used Docker before |

---

## Verification

Two Python scripts check what a linter cannot. Neither needs PHP, Docker, or
`node_modules`.

```bash
python backend/tools/check_seeders.py    # schema ↔ model ↔ seeder ↔ service ↔ enum
python frontend/tools/check_frontend.py  # module graph + API contract
```

The frontend checker verifies that every relative import resolves, that every
named import is actually exported, and that every `projectApi.foo()` style call
made from a page is a method `api/endpoints.js` really defines. Both scripts
found real bugs during development — see the notes in `docs/SETUP.md`.

With a PHP runtime available, also run:

```bash
php backend/tools/audit_rubric_weights.php   # rubric weights total 100
php artisan test
```

---

## Design notes worth knowing before you change anything

- **Historical records are frozen, never recomputed.** Rubric snapshots,
  published leaderboards, and archived projects all store the state that was
  true when they were created. Editing a template must not rewrite last
  semester's marks.
- **Every milestone status change goes through one validated method.** That is
  what makes the audit trail evidence rather than a log.
- **Only the public leaderboard is unauthenticated**, and it reads frozen
  `leaderboard_entries` columns only — never a live join back to project or
  student data.
- **Seeders drive the real services**, so a demo cannot display a state the
  running application could not have produced.
