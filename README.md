# Review Mangler — Phase 1 (MVP)

Read `CLAUDE.md` first for the full product spec. This README is the "how to
actually run it" companion.

## What Phase 1 includes

Per `CLAUDE.md` §12's phased plan:

- Title search & verification for films (TMDB) and books (Google Books).
- A single review/write-up source: news, via NewsAPI (`app/Services/NewsClient.php`).
- The full classification pipeline (sentiment, meanness 1-5, constructive,
  **personal-attack flag**, content-warning tags) via the Claude API.
- The summary dashboard — aggregates only, by default.
- Deliberate, one-at-a-time review reveal, with content tags shown first.
- A "flag this classification" correction control on every review.
- Single/invite-only accounts (no public signup yet — see `bin/create_user.php`).
- Cron-driven ingestion + classification, designed around Hostinger's
  shared-hosting constraints (no persistent workers — see §9.2/§9.3 in
  CLAUDE.md).

**Not yet built** (later phases per CLAUDE.md §12): Reddit/YouTube sources,
IMDb/Letterboxd/Goodreads/Amazon scraping, digest emails, delegate access,
vacation mode, trend charts, self-serve signup. The database schema already
has room for most of this; the code intentionally doesn't build ahead of the
phase.

## Before you deploy: read this

`app/Services/NewsClient.php` has a comment at the top flagging that
NewsAPI's free tier is documented as being for local development, not a live
production app — confirm your plan's current terms at
[newsapi.org/pricing](https://newsapi.org/pricing) before pointing this at a
real Hostinger deployment. If it doesn't fit, `NewsClient` is the only file
that talks to the news provider, so swapping to GNews or another provider is
a one-file change.

## Setup on Hostinger

1. **Database.** In hPanel → Databases → MySQL Databases, create a database
   and user. Note the host (usually `localhost`), database name, username,
   password.
2. **Upload the code.** Upload this whole folder to your hosting account
   (File Manager, FTP, or `git clone` if you have SSH access on your plan).
   It does **not** need to be named `public_html` — see step 4.
3. **Import the schema.** In hPanel → Databases → phpMyAdmin, open your new
   database and run `db/schema.sql` (Import tab, or paste into the SQL tab).
4. **Point the document root at `public/`.** In hPanel → Websites → your
   domain → Advanced → set the document root to the `public/` folder inside
   where you uploaded the code, e.g.
   `/home/USER/domains/yourdomain.com/review-mangler/public`. This keeps
   everything else (app code, `.env`, database credentials) outside what a
   browser can ever request directly — the `.htaccess` files are a backup,
   not the primary defense.
5. **Create `.env`.** Copy `.env.example` to `.env` (same folder as this
   README, i.e. *outside* `public/`) and fill in every value. Get API keys
   from TMDB, NewsAPI, and Anthropic (see the comments in `.env.example` for
   where).
6. **Enable SSL.** hPanel → SSL → enable free SSL for your domain if it
   isn't already. The app forces secure session cookies in production mode.
7. **Create your first (invite-only) user.** If your plan has SSH access:
   ```
   php bin/create_user.php you@example.com 'a strong password'
   ```
   If you don't have SSH, most Hostinger plans still let you run a one-off
   PHP script via a browser — as a fallback, temporarily drop a tiny script
   in `public/` that requires `bin/create_user.php`'s logic, run it once,
   then delete it. (Worth getting SSH/Composer-capable hosting if you plan
   to keep iterating on this — see CLAUDE.md §11's open question on plan tier.)
8. **Set up the two cron jobs.** hPanel → Advanced → Cron Jobs:
   - Every 30 minutes: `php /home/USER/domains/yourdomain.com/review-mangler/cron/ingest.php`
   - Every 5–10 minutes: `php /home/USER/domains/yourdomain.com/review-mangler/cron/classify.php`

   (Adjust the path to wherever you actually uploaded the code.)
9. **Log in** at `https://yourdomain.com/login.php` and add your first title.

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
```

## Project layout

```
CLAUDE.md          the product spec — read this for the "why"
app/                PHP classes (no framework, no Composer dependency)
  Services/         one class per external integration + per pipeline stage
public/             the actual document root — pages + assets
cron/               the two scheduled scripts (§9.3)
db/schema.sql        full database schema
bin/create_user.php  CLI-only invite-a-user script
.env.example        copy to .env and fill in
```
