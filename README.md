# Review Mangler — Phase 1 + 2 + 3

Read `CLAUDE.md` first for the full product spec. This README is the "how to
actually run it" companion.

## What's built so far

**Phase 1:**
- Title search & verification for films (TMDB) and books (Google Books).
- News as a review/write-up source, via NewsAPI (`app/Services/NewsClient.php`).
- The full classification pipeline (sentiment, meanness 1-5, constructive,
  **personal-attack flag**, content-warning tags) via the Claude API.
- The summary dashboard — aggregates only, by default.
- Deliberate, one-at-a-time review reveal, with content tags shown first.
- A "flag this classification" correction control on every review.
- Single/invite-only accounts (no public signup yet — see `bin/create_user.php`).
- Cron-driven ingestion + classification, designed around Hostinger's
  shared-hosting constraints (no persistent workers — see §9.2/§9.3 in
  CLAUDE.md).

**Phase 2 adds:**
- Reddit as a second source (`app/Services/RedditClient.php`) — mentions/
  discussion threads.
- YouTube as a third source (`app/Services/YoutubeClient.php`) — matching
  videos *and* their top comments, scored as separate items so one cruel
  comment doesn't hide inside an otherwise fine video's score.
- `app/Services/SourceRegistry.php` — the one place new sources get
  registered and linked to tracked titles; existing Phase 1 titles pick up
  Reddit/YouTube automatically the next time they're checked.
- Email digests (`app/Services/NotificationService.php`, `cron/digest.php`,
  `app/Services/SmtpMailer.php`) — per-title setting on the title page, off
  by default, weekly or on-new-activity cadence, never includes raw review
  text, and fully skipped for any account with `vacation_mode` on.

**Phase 3 adds:**
- Letterboxd as a fourth source (`app/Services/LetterboxdClient.php`), the
  first and — per the robots.txt check below — only Tier B (scraped) source.
  Film-only; resolves a film via its TMDB id (`https://letterboxd.com/tmdb/{id}/`
  redirects to the canonical page) rather than fuzzy text search, and stores
  the star rating in `reviews.native_rating`.
- `app/Services/RobotsChecker.php` — fetches and caches each domain's
  robots.txt and checks every request against it before it's made. Every
  Tier B fetcher goes through this.
- New `SCRAPER_CONTACT` env var (required) — a real contact method sent in
  Letterboxd's User-Agent, per the spec's scraping-ethics section.
- **Read this before assuming it works out of the box:** the HTML parsing in
  `LetterboxdClient.php` was built from live inspection, not tested
  end-to-end against a running server (see CLAUDE.md §13's Phase 3 entry for
  why, and what to check first if reviews come back empty).

**Not built, and not going to be** (checked against each site's live
robots.txt on 2026-08-18 — see CLAUDE.md §13): IMDb and Amazon disallow
generic crawlers essentially site-wide; Goodreads (whose review API retired
in 2020 anyway) explicitly disallows `/book/reviews/`, `/review/show`, and
`/search`. Scraping any of them would violate the same robots.txt rule
`RobotsChecker.php` exists to enforce. Revisit if any of the three change
their robots.txt, or if a licensed data deal ever makes sense.

**Not yet built** (later phases per CLAUDE.md §12): delegate access, a UI
for vacation mode (the column exists and digests already respect it —
there's just no toggle in the app yet), trend charts, self-serve signup.

## Before you deploy: read this

Two source clients have a caveat comment worth reading before you rely on
them in production — API terms for both have shifted before and are worth
re-checking against current docs, not just this comment:

- `app/Services/NewsClient.php` — NewsAPI's free tier is documented as being
  for local development, not a live production app. Confirm your plan at
  [newsapi.org/pricing](https://newsapi.org/pricing).
- `app/Services/RedditClient.php` — Reddit's Data API terms/pricing changed
  materially in 2023. Re-check [reddit.com/wiki/api](https://www.reddit.com/wiki/api/)
  before relying on this for anything beyond low-volume personal use.

Each source lives behind `SourceRegistry`, so swapping or dropping a
provider is a small, contained change — it never touches the ingestion,
classification, or dashboard code.

`app/Services/LetterboxdClient.php` has its own, longer caveat: unlike the
three API-based clients above, it's a scraper, so it's fragile by nature —
read the class doc comment before relying on it.

Also test digest deliverability early (CLAUDE.md §9.2) — the built-in SMTP
client works with Hostinger's mailbox SMTP settings, but if digests land in
spam, a transactional provider (Resend, Postmark) may serve you better than
sending from shared-hosting IP space. That would mean rewriting
`SmtpMailer::send()`'s body, not anything else.

## Setup on Hostinger

1. **Database.** In hPanel → Databases → MySQL Databases, create a database
   and user. Note the host (usually `localhost`), database name, username,
   password.
2. **Upload the code directly into `public_html`.** On Hostinger's
   shared/business plans, `public_html` is the site's root and **cannot be
   changed to point at a subfolder** — there is no document-root setting on
   these plans (confirmed against Hostinger's own docs). So this repo's
   contents go straight into `public_html` itself:
   ```
   public_html/
   ├── app/
   ├── bin/
   ├── cron/
   ├── db/
   ├── public/
   │   ├── index.php
   │   ├── login.php
   │   └── ...
   ├── .env
   └── .htaccess
   ```
   Nothing about the folder layout changes from what's in this repo — the
   root `.htaccess` (see step 4) is what makes `public_html` behave as if it
   were pointed at `public/`, without moving a single file.
3. **Import the schema.**
   - **Fresh install:** In hPanel → Databases → phpMyAdmin, open your new
     database and run `db/schema.sql` (Import tab, or paste into the SQL tab).
     It already includes everything through Phase 2.
   - **Already ran Phase 1's schema against a live database?** Run
     `db/migrations/002_phase2.sql` instead — it's the incremental diff, safe
     to run once against your existing tables.
4. **The root `.htaccess` does the routing — nothing to configure.** If your
   plan *does* expose a document-root setting, point it at `public/` and
   skip this step (that's simpler and is what this README originally
   recommended). On Hostinger shared/business plans, where that setting
   doesn't exist, the `.htaccess` already committed at the repo root handles
   it: it rewrites any request that isn't a real file/directory sitting in
   `public_html` (so not `app/`, `db/`, `bin/`, `cron/`, or `.env`) into
   `public/`. `https://yourdomain.com/login.php` ends up serving
   `public/login.php` with no application code changes — every page already
   uses root-relative URLs (`/login.php`, `/assets/style.css`, ...) and
   `__DIR__`-relative `require`s, which work identically either way.
   `app/`, `db/`, `bin/`, `cron/`, and `.env` stay exactly as protected as
   they'd be with a real `public/` document root — see the comments at the
   top of `.htaccess` for exactly how.
5. **Create `.env`.** Copy `.env.example` to `.env` (same folder as this
   README, i.e. *outside* `public/`) and fill in every value — TMDB, NewsAPI,
   Reddit, YouTube, Claude, and SMTP. See the comments in `.env.example` for
   where to get each key.
6. **Enable SSL.** hPanel → SSL → enable free SSL for your domain if it
   isn't already. The app forces secure session cookies in production mode.
7. **Create your first (invite-only) user.**
   - **If your plan has SSH access** (hPanel → Advanced → SSH Access — check
     whether it's enabled): SSH in, `cd` to where you uploaded the code, and run
     ```
     php bin/create_user.php you@example.com 'a strong password'
     ```
   - **If it doesn't** (common on entry-level shared plans): set a long
     random value for `SETUP_TOKEN` in `.env`, then visit
     `https://yourdomain.com/setup_admin.php`, enter that token plus the
     email/password you want, and submit. **Immediately delete
     `public/setup_admin.php`** afterward (File Manager, or `git rm` +
     redeploy) — it's a one-time-use file, not something to leave live.
     (Worth getting SSH/Composer-capable hosting if you plan to keep
     iterating on this — see CLAUDE.md §11's open question on plan tier.)
8. **Set up the three cron jobs.** First, get the exact absolute path: SSH in,
   `cd` to wherever `cron/` lives (on the fixed-docroot shared/business plans
   from step 2, that's `public_html` itself), then run `pwd` and copy what it
   prints. Then in hPanel → Advanced → Cron Jobs, create three jobs using that
   path in place of `/home/USER/domains/yourdomain.com/public_html` below:
   - Every 30 minutes: `php /home/USER/domains/yourdomain.com/public_html/cron/ingest.php`
   - Every 5–10 minutes: `php /home/USER/domains/yourdomain.com/public_html/cron/classify.php`
   - Once a day: `php /home/USER/domains/yourdomain.com/public_html/cron/digest.php`

   If a job's log (hPanel shows recent output per job) comes back empty or
   with a "command not found"-style error, run `which php` over SSH and use
   that full path (e.g. `/usr/bin/php`) instead of the bare `php` above —
   cron's environment doesn't always have the same `$PATH` as an interactive
   SSH shell.
9. **Log in** at `https://yourdomain.com/login.php` and add your first title.

### If routing doesn't work (blank page, 403, or 404 at your domain)

- **403 Forbidden everywhere, including `/login.php`:** the deny-all block
  in `.htaccess` is firing but the rewrite above it isn't — almost always
  means `mod_rewrite` isn't enabled or `AllowOverride` doesn't permit
  `RewriteRule`/`RewriteCond` in `.htaccess` on your plan. This would be
  unusual for Hostinger (their own WordPress hosting depends on the exact
  same mechanism), but if it happens, ask Hostinger support to confirm
  `mod_rewrite` + `AllowOverride All` for your account, rather than working
  around it in application code.
- **404 at the domain root, but `/login.php` etc. work fine:** double-check
  the `RewriteRule ^$ public/index.php [L]` line is present — that's the one
  rule that isn't covered by the general catch-all.
- **You can browse to `https://yourdomain.com/public/login.php` directly
  but not `https://yourdomain.com/login.php`:** the rewrite isn't firing at
  all (you're hitting the real path directly); re-check that `.htaccess`
  actually deployed to `public_html`'s root, not somewhere else.

## Local development

Requires PHP 8.1+ with the `pdo_mysql` and `curl` extensions (both standard),
and a local MySQL/MariaDB instance.

```
cp .env.example .env   # set APP_ENV=local, and local DB/API credentials
mysql -u root -p your_db < db/schema.sql
php bin/create_user.php you@example.com 'a strong password'
php -S localhost:8000 -t public
```

Run the cron scripts manually while developing:
```
php cron/ingest.php
php cron/classify.php
php cron/digest.php
```

## Project layout

```
CLAUDE.md              the product spec — read this for the "why"
app/                    PHP classes (no framework, no Composer dependency)
  Services/             one class per external integration + per pipeline stage
    SourceRegistry.php  the list of sources (which title types each applies to) and how to fetch each
    RobotsChecker.php   fetches/caches robots.txt, used by every Tier B (scraped) source
public/                 pages + assets — the document root if your plan allows setting
                        one, otherwise routed here from public_html/ by .htaccess
cron/                   the three scheduled scripts (§9.3)
db/schema.sql           full database schema (fresh installs)
db/migrations/          incremental changes for already-deployed installs
bin/create_user.php     CLI-only invite-a-user script (preferred — needs SSH)
public/setup_admin.php  one-time browser fallback if you don't have SSH — delete after use
.env.example            copy to .env and fill in
```
