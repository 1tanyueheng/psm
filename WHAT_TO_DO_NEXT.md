# WHAT TO DO NEXT

A plain-English guide. No jargon. Follow it top to bottom.

If something goes wrong, the exact error message is the useful part — copy it
and ask.

---

## The big picture

You have three things:

| Thing | What it is | Where it lives |
|---|---|---|
| **The code** | Your PSM system — the website itself | This folder, on your computer |
| **Docker** | A tool that runs the website | Not installed yet |
| **Render** | A website that runs your system so others can use it | You haven't set it up yet |

Right now the code exists but has **never actually run**. Nobody has seen it
work yet. That's what we're fixing.

Think of it like a car you just built. It looks complete. But the engine has
never been started. Docker is how we start the engine.

---

## Step 1 — Install Docker Desktop

1. Go to **https://www.docker.com/products/docker-desktop/**
2. Click **Download for Windows**
3. Run the installer. Click Next/OK through everything. Accept the defaults.
4. **Restart your computer** when it asks.

After restarting, you should see a whale icon 🐳 in the bottom-right of your
screen (near the clock). That icon means Docker is running.

**If you don't see the whale**, open Docker Desktop from the Start menu and
wait a minute or two. It takes a while to start the first time.

### How to check it worked

Open the program called **Git Bash** (or **Terminal**) and type this, then
press Enter:

```
docker --version
```

If you see something like `Docker version 27.0.3`, you're good.

If you see `command not found`, Docker isn't installed properly — restart your
computer first, then try again.

---

## Step 2 — Wake up the system

This is the moment the engine starts.

Open **Git Bash**. Type these two lines, pressing Enter after each:

```
cd "/c/Users/yuehe/Documents/AIProject/psm-system"
```

```
docker compose up --build
```

**This will take 5–15 minutes the first time.** It's downloading and building
things. Lots of text will scroll past. That's normal.

**Do not close the window.** Leave it running.

### When it finishes

You'll see a message about the database being ready. Now open your web browser
and go to:

**http://localhost:8000**

You should see your PSM system. 🎉

### If it says an error instead

Copy the **last 20 lines** of red text and ask me. Don't worry about fixing it
yourself.

---

## Step 3 — Create the test data

The system is running but empty — no students, no projects, nothing.

Open a **second** Git Bash window (leave the first one running!). Type:

```
cd "/c/Users/yuehe/Documents/AIProject/psm-system"
```

```
docker compose exec app php artisan migrate --seed
```

This creates all the database tables and fills them with example data so you
can click around and see it working.

### Then log in

Go back to **http://localhost:8000** and sign in with any of these:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@psm.test` | `password` |
| Coordinator | `coordinator@psm.test` | `password` |
| Supervisor | `supervisor@psm.test` | `password` |
| Examiner | `examiner@psm.test` | `password` |
| Student | `student@psm.test` | `password` |

Every account uses the same password: `password`

Click around. Log in as each role — the menu and the whole dashboard change
depending on who you are. That's one of the main features. This is your chance
to see what you built.

---

## Step 4 — Show the free bonus feature

Your public leaderboard — the "Pixel-It award" page — works **without logging
in**. Go to:

**http://localhost:8000/leaderboard**

If this works, that's your demo-ready feature. Anyone can see it. No password
needed.

---

## Step 5 — Put it online (only when you're ready)

Once Steps 1–4 all work, you can put this on the internet so other people can
use it. That's what **Render** is for.

**Don't do this until localhost works first.** There's no point pushing
something broken to the internet.

When you're ready:

1. Put your code on **GitHub** (a website that stores code)
2. Make a free account at **https://render.com**
3. Create a new **Web Service** and point it at your GitHub repo
4. Render will ask for some settings — copy them from
   **`docs/DEPLOYMENT.md`**, section 5

### Two things to know before you go live

Both are explained properly in `docs/DEPLOYMENT.md`, but in short:

**1. Uploaded files will disappear.** The server's disk gets wiped every time
you redeploy. Your students upload their thesis files — those would be lost.
The fix is to store them somewhere permanent (Cloudflare R2, Amazon S3). Don't
skip this.

**2. Emails won't send.** Sending email needs a service (Resend, Postmark,
Mailgun). Without one, the reminder emails and notifications do nothing — and
that's one of your eight required modules.

Also worth knowing: **do not put the backend on Vercel.** Vercel is great for
the React frontend, but it cannot run PHP/Laravel. Render is the right place
for the backend. `docs/DEPLOYMENT.md` explains why.

---

## Stop the system when you're done

Go to the first Git Bash window (the one running lots of text) and press:

**Ctrl + C**

That shuts it down. To start it again later, just repeat Step 2 — but it'll be
much faster the second time.

---

## Quick answers

**"Do I need Docker at all?"**
For running it like a real website, yes. It's the standard way and it's what
Render uses.

**"Why does it take so long the first time?"**
It's downloading a whole mini-computer (Linux) plus PHP, MySQL, and Node. Later
runs take seconds.

**"Something broke. What do I do?"**
Copy the last 20 lines of the error and ask me. Error messages are the most
useful thing you can give me.

**"Can I skip Docker and just open it in my browser?"**
No — this is a full web application with a database. It needs a server running.
Docker provides that.

---

## Checklist

Tick these off as you go:

- [ ] Docker Desktop installed and showing the whale icon
- [ ] `docker --version` prints a version number
- [ ] `docker compose up --build` finished without errors
- [ ] http://localhost:8000 loads in my browser
- [ ] `migrate --seed` ran successfully
- [ ] I can log in as admin
- [ ] I can log in as student (different screen!)
- [ ] http://localhost:8000/leaderboard works without logging in
- [ ] I know how to stop it (Ctrl + C)

Once all nine are ticked, the system works. Everything after that is about
sharing it, not fixing it.
