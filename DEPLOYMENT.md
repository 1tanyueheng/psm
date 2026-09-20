# DEPLOYMENT

This file is a pointer. The real deployment guide lives at
**[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)**.

Kept as a stub so that anyone looking for a root-level deployment file finds
their way to the authoritative one instead of a second, drifting copy.

## The answer in one table

| Layer | Where it goes | Cost |
|---|---|---|
| Frontend (React) | **Vercel** — best-in-class static hosting | Free |
| Backend (Laravel) | **Render**, from the existing `Dockerfile` | Free, or $7/mo |
| Database | **Neon** (PostgreSQL) or Render MySQL | Free |
| Uploaded files | Cloudflare R2 / S3 — **not** the container disk | Free tier |
| Email | Resend / Postmark / Mailgun | Free tier |

## The one thing to remember

**The backend must not go on Vercel.** Not because Vercel cannot run PHP — it
can, through Docker and FrankenPHP — but because it runs code as short-lived
serverless functions, and this application needs:

1. a disk that still has the file tomorrow (student thesis uploads), and
2. a process that is always awake (the deadline reminder scheduler).

Vercel is excellent for the frontend half. Render is the right home for the
backend half. `docs/DEPLOYMENT.md` section 1 has the full reasoning, with the
specific lines of code that require each.

## Where to go

- **`docs/DEPLOYMENT.md`** — the full guide: options, database, env vars,
  pre-flight checklist
- **`WHAT_TO_DO_NEXT.md`** — plain English, getting it running locally first
