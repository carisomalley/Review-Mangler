# Review Mangler — Project Spec

## 1. What this is and why it exists

Review Mangler is a web app that lets a creator (initially a filmmaker, but built to
work for authors too) track what critics, reviewers, and the internet at large are
saying about their book or film — without having to personally wade through
comment sections and review threads to find it out.

The seed use case: a filmmaker made an autobiographical documentary about a
physical disfigurement and their emotional connection to horror movie monsters.
They want the constructive criticism — it makes their work better — but reading
cruel, personal reviews (especially ones that fixate on their appearance rather
than the film) is genuinely harmful to them. Today, the only way to get the
signal is to expose themselves to all of the noise, including the cruelty. This
tool exists to break that trade-off.

**Design philosophy, stated plainly, because it should shape every decision below:**

- Default to *summary*, not exposure. The user should never be surprised by the
  content of a review — they choose, deliberately, when to see one.
- Constructive signal is the product. Sentiment, meanness, and "is this actually
  useful feedback" are three different axes, and conflating them defeats the
  purpose — a review can be negative *and* constructive, or positive *and* a
  backhanded personal jab.
- This is not a vanity-metrics or review-bombing dashboard. No leaderboards, no
  "your rating vs. other creators," nothing that turns feedback into a
  competition or a dopamine loop. Refreshing for new reviews should feel calm,
  not compulsive.
- The tool is being built for creators broadly (multi-tenant), but every one of
  them may be in the same position as the seed user: someone whose art is bound
  up with something personal and vulnerable. Assume that by default for every
  user, not just the one we have in mind today.

## 2. Core user story

> As a creator, I search for my book or film once, the tool confirms it's the
> right title against a real metadata source, and from then on it periodically
> finds reviews and write-ups of it wherever they exist — social review sites,
> news, blogs — and gives me a quick, honest read on how it's landing: mostly
> positive or negative, how constructive the criticism is, and how mean people
> are being. I can come back anytime to see what's changed. I never see the
> actual text of a review unless I choose to open it, one at a time, on my own
> terms.

Secondary stories worth designing for from day one (see §4 for why):

- As a creator, I want to know if a spike of reviews is happening *right now*
  (e.g. opening weekend) without being forced to read any of them.
- As a creator, I want to hand a curated, cruelty-filtered digest of the
  criticism to my editor/producer/publicist without them also having to see
  everything.
- As a creator, I want to mute a source or a specific outlet I already know is
  bad-faith, without losing their reviews from the aggregate count.
- As a creator, I want to feel confident the tool isn't hiding real problems
  from me just because they're unpleasant to read — I want to trust the
  summary, not just be protected from the raw text.

## 3. Non-goals (explicit, to keep scope sane)

- Not a general social-listening or brand-monitoring tool.
- Not a review-response or PR tool (no drafting replies, no outreach).
- Not trying to be comprehensive/real-time like a media-monitoring enterprise
  product — periodic, good-enough coverage beats fragile completeness.
- Not a public-facing site — reviews and scores are private to the account
  that added the title, not published anywhere.

---

## 4. Angles worth designing in from the start

These weren't in the original ask but follow directly from the stated purpose,
and are much cheaper to design for now than to retrofit later.

**Personal attack vs. criticism-of-the-work.** "Meanness" and "is this about
me vs. about my work" are not the same axis. A review can be harshly negative
about pacing (fine, even useful) or it can make cruel personal remarks about
the creator's appearance, body, voice, background, etc. (the exact thing this
tool exists to shield against). The classification pipeline should tag reviews
with a distinct **personal-attack flag**, separate from the meanness score, and
the dashboard should be able to say "3 reviews contained personal remarks about
you, not your work" without the user having to read any of them to find that
out. For the seed user specifically, this is arguably the single most
important field in the whole system — general negativity is not what's
harming them, targeted cruelty about their body is.

**Content-warning granularity before reveal.** When a user does choose to
reveal a review, give them one more layer of choice: a content flag on that
specific review (e.g. "contains remarks about appearance/disability," "contains
profanity/insults," "spoilers") shown *before* the text is shown, not just a
blanket reveal button. Think of it as a nutrition label for the one review
they're about to open.

**A deliberate "reveal," not a hover or accidental click.** Individual reviews
should require an explicit, unambiguous action to open (a click plus a short
confirmation on flagged content, not a hover-to-preview or an expand-on-scroll).
Cheap to build, meaningfully reduces the chance of accidental exposure.

**Pacing controls / anti-doomscroll.** Let the user set how often they're
willing to be told new reviews exist (e.g. weekly digest vs. on-demand only),
and consider a soft rate limit or gentle friction on manually refreshing more
than, say, once a day — the goal is a tool that respects the fact that
checking-for-bad-news can itself become a compulsive, harmful habit.

**Delegate / support-person access.** Let the creator optionally invite a
trusted second person (publicist, editor, therapist-adjacent support person,
friend) with permission to view and even pre-screen reviews before the creator
sees the summary. This turns "someone I trust reads it first" from a manual
workaround into a real feature, and is a natural way to serve users who want a
human buffer, not just an algorithmic one.

**Source muting vs. exclusion.** Muting a known bad-faith outlet or reviewer
should hide their reviews from the default view but keep them in the data —
otherwise the aggregate sentiment number becomes misleading. Show "N reviews
muted" as a transparent count rather than silently dropping them.

**Confidence / coverage indicators.** Because coverage is scraped and
best-effort, the dashboard should always be honest about what it *doesn't*
know — e.g. "Amazon reviews last successfully checked 6 days ago (site
appears to be blocking automated access)" rather than silently presenting a
partial picture as complete. This matters a lot for user trust in the summary
numbers.

**Correction / feedback loop on misclassification.** Automated sentiment and
meanness scoring will sometimes get it wrong (call a harsh-but-fair critique
"mean," or miss a personal attack phrased politely). Let the user flag "this
classification feels off" on any review; log the correction. This both
improves trust and gives a dataset to refine the scoring rubric over time.

**Trend over time, not just a snapshot.** A simple line/area chart of
sentiment and volume over time per title (see the dataviz skill when this gets
built) turns "is this getting better or worse" into something visible at a
glance, which matters more emotionally than any single-moment score.

**Data retention and sensitivity of the account itself.** The account holder's
disability/appearance/health context may come up in how they configure content
flags (e.g. a saved preference like "flag remarks about disfigurement"). That
preference itself is sensitive personal data about the user and should be
treated with the same care as health information — encrypted at rest where
practical, never exposed in logs, never used for anything but the user's own
filtering.

**Scraping ethics and legal exposure, named explicitly.** IMDb, Letterboxd,
Amazon, and Goodreads (API retired in 2020) have no sanctioned public API for
review data. Per your steer, the spec below designs around scraping those
sources, but this needs to be an explicit, visible risk in the product, not a
buried implementation detail — see §7.4.

---

## 5. Users, accounts, and data isolation

Multi-tenant from the start:

- Any creator can register their own account (email + password, or
  email-link/passwordless — recommend passwordless to start, less to build and
  more accessible).
- Each account owns its own set of tracked titles, sources, reviews, settings,
  and delegate relationships. Strict row-level isolation by `user_id` on every
  table that holds user data — no cross-account queries, ever, even for admin
  tooling (build a separate, explicitly-scoped admin path instead).
- **Delegate access**: an account can invite another registered user (or an
  email-invited guest with a scoped, revocable token) as a delegate on a
  specific title, with a permission level: `view-summary-only`,
  `view-and-reveal`, or `pre-screen` (delegate reviews individual entries and
  can annotate/hide before the owner's summary reflects them).
- No public profiles, no cross-account discovery, no leaderboards.

---

## 6. Core domain model

Plain-language description of the entities (the actual schema lives in §9):

- **Title** — a book or film the user is tracking. Verified against an
  external metadata source (see §7.1) and stored with its canonical ID from
  that source, so re-fetching and dedup are reliable even if the user typed
  the title differently than the source's exact name.
- **Tracked Title** — the join between a user and a Title, holding
  per-user settings (refresh cadence, muted sources, content-flag
  preferences, delegate list).
- **Source** — a place reviews come from (IMDb, Letterboxd, Goodreads,
  Amazon, a news outlet, a blog, etc.), with metadata about how it's fetched
  (API vs. scrape), its current health/block status, and rate-limit rules.
- **Review** — one individual piece of feedback: raw text (stored, but
  access-controlled — see §8), source, author (if available), a link back to
  the original, original rating if the source has one (stars, thumbs, etc.),
  and fetch metadata (when found, when last verified still present).
- **Classification** — the scored output for a Review: sentiment
  (positive/negative/mixed), meanness scale, constructive flag,
  personal-attack flag, content-warning tags, and the model/rubric version
  that produced it (so re-scoring after a rubric change is traceable).
- **Digest / Notification** — a scheduled or on-demand summary sent to the
  user (email, in-app) reflecting new activity since their last visit.
- **Correction** — a user's flag that a Classification looks wrong, with
  optional note, feeding future rubric tuning.

---

## 7. Functional requirements

### 7.1 Title search & verification

- User enters a title (and optionally author/director or year, to
  disambiguate).
- The app queries metadata sources with real, sanctioned APIs to confirm the
  match before anything else happens:
  - **Films**: TMDB (The Movie Database) API as primary — free, well
    documented, generous rate limits. OMDb API as a fallback/cross-check.
  - **Books**: Google Books API and/or Open Library API — both free, no
    scraping needed for basic metadata.
- Show the user a short disambiguation step if multiple matches come back
  (poster/cover, year, creator name) so they confirm the right one before
  tracking starts.
- Store the canonical external ID(s) (TMDB id, OMDb imdbID, Open Library work
  id, etc.) on the Title record — this is what downstream source-matching
  keys off, not the free-text title, to avoid duplicate/fragmented tracking.

### 7.2 Review ingestion

Two tiers, because the sources named span both:

**Tier A — sanctioned APIs (prefer these wherever the content type allows):**
- News & blogs: NewsAPI or GNews for news; RSS/Atom polling for known blogs
  and outlets (cheap, robust, no ToS issue at all); optionally a general web
  search API (e.g. Brave Search API or Bing Web Search API) to *discover* new
  blog/news mentions by title + creator name, which then get fetched as
  normal pages.
- Reddit: official Reddit API for mentions/discussion threads.
- YouTube: YouTube Data API for video reviews and their top comments, where
  relevant (video essays / reaction videos are a real source of both
  criticism and personal commentary for a documentary).

**Tier B — scraping (for IMDb, Letterboxd, Goodreads, Amazon reviews),
explicitly acknowledged as the higher-risk path per your steer:**
- Build a source-specific fetcher per site, isolated behind a common
  interface so one breaking doesn't take down the others.
- Respect `robots.txt` per domain; identify with a clear, honest User-Agent
  string including a contact method; enforce a conservative per-domain rate
  limit and randomized polite delay between requests.
- Treat every scraped source as **degradable**: track a health status
  (`ok`, `degraded`, `blocked`) per source per domain, back off automatically
  on repeated failures/CAPTCHAs, and surface that status to the user (§4,
  "confidence indicators") instead of failing silently.
- Cache aggressively — re-fetching a page that hasn't changed wastes both
  your rate-limit budget and their goodwill.
- Because this tier can break at any time (site redesigns, active
  anti-scraping measures, legal takedown requests), design the rest of the
  system so it degrades gracefully if a Tier B source goes offline entirely —
  it should reduce coverage, not break the product.
- **Do not treat this as "solved" by the spec.** Flag it in the README/roadmap
  as the area most likely to need ongoing maintenance and the one place where
  a ToS or legal review by the user is genuinely warranted before shipping to
  other creators, not just self-use.

**Scheduling:** ingestion runs as a scheduled job (see §9.3 for how this works
on Hostinger specifically), on a per-title cadence the user can set
(default: weekly), plus an on-demand "check now" the user can trigger
manually (rate-limited per §4 to avoid hammering sources or becoming a
doomscroll trigger).

**Dedup:** match on source + canonical review URL where available; fall back
to a fuzzy match on (source, author, first N characters of text) to catch
re-fetches of the same review without a stable URL.

### 7.3 Classification pipeline

Use an LLM (Claude API) as the scoring engine rather than a hand-rolled
sentiment model — this task (distinguishing "harsh but constructive" from
"cruel and personal," catching a polite-sounding personal attack) needs
real language understanding, not a bag-of-words classifier.

For each new Review, produce and store:

- **Sentiment**: positive / mixed / negative.
- **Meanness scale**: e.g. 1–5, calibrated with a written rubric (see below)
  so it's stable across the whole corpus, not just "vibes" per call.
- **Constructive flag**: does this review contain actionable, specific
  feedback about the work (yes / no / partially), independent of tone.
- **Personal-attack flag**: does this review target the creator personally
  (appearance, identity, character) rather than critique the work — this is
  the single field the seed use case cares about most; treat it as
  first-class, not a sub-case of "meanness."
- **Content-warning tags**: short controlled vocabulary (e.g.
  `appearance/body`, `disability`, `profanity`, `spoilers`) so the pre-reveal
  warning (§4) can be specific.
- **Original rating**, normalized to a 0–1 or 0–100 scale alongside the
  source's native rating, so cross-source aggregate scores are comparable.
- **Rubric/model version** used, so re-classification after rubric tuning is
  auditable and reviews can be selectively re-scored rather than requiring a
  full re-run.

Write the actual scoring rubric as a versioned prompt/config asset (not
hardcoded ad hoc in application code) so it can be iterated on independently
of the app, and so the Correction feedback loop (§4) has something concrete
to tune.

Batch classification calls where possible (cost and rate-limit efficiency);
this pipeline runs asynchronously from ingestion, not inline with the user's
request.

### 7.4 Dashboard (the core screen)

Default view, per tracked title, shows **only aggregates**, never raw text:

- Overall sentiment split (e.g. a simple stacked bar: % positive / mixed /
  negative).
- Average meanness score, with a note on how many reviews contributed.
- Constructive-vs-not split.
- Personal-attack count, called out distinctly and visually separate from
  general meanness — this is the number the seed user most needs to be able
  to see without fear (a *low* number here is reassuring in a way "average
  sentiment" isn't).
- Aggregate/normalized rating alongside native platform ratings (stars,
  thumbs, etc.) shown per source.
- Trend over time (sparkline or small chart) since the title started being
  tracked.
- Per-source breakdown (how many reviews from IMDb vs. Letterboxd vs. news
  vs. blogs, etc.), with source health/coverage confidence noted per §4.
- A muted-count indicator if any sources/reviewers are muted.

From this screen, the user can drill into a **filtered, still-anonymized
list** (e.g. "show me the constructive ones," "show me the ones flagged
personal-attack" — the latter mostly useful for a delegate pre-screening, not
for the creator themselves) before ever opening individual text.

### 7.5 Individual review reveal

- List view shows metadata only: source, date, native rating, sentiment
  badge, meanness badge, constructive badge, content-warning tags. No text.
- Revealing one review is a deliberate click; if it carries a content-warning
  tag, show that tag and require one more explicit confirmation before the
  text renders.
- Once revealed, the review stays revealed for that user's session (no need
  to re-confirm every time they scroll back to something they already chose
  to read).
- A "flag this classification" control sits next to every revealed (and
  every listed) review, feeding §7.3's Correction loop.

### 7.6 Notifications & digests

- Configurable per title: `off`, `weekly digest`, `on new activity` (batched,
  not one email per review).
- Digest content mirrors the dashboard aggregates (never raw text in an
  email) — sentiment shift, new personal-attack count if any, "N new reviews
  since last visit."
- Global "pause everything" control (vacation mode) — no notifications, no
  auto-fetch, until the user turns it back on. Respect this completely; this
  is a wellbeing feature, not a nice-to-have.

### 7.7 Settings

Per user, at minimum:
- Default reveal/content-warning preferences (which tags require the extra
  confirmation step — some users may want *all* flagged content gated, others
  only specific tags).
- Muted sources/reviewers (title-level or account-wide).
- Refresh cadence and manual-refresh rate limit.
- Delegate management (invite/revoke, permission level per §5).
- Notification preferences (§7.6) and vacation mode.

---

## 8. Access control & data handling

- Session-based auth (or passwordless magic-link) with proper hashing
  (bcrypt/argon2 if password-based), CSRF tokens on all state-changing
  requests, and HTTPS enforced everywhere (Hostinger provides free SSL —
  turn it on, no excuse not to).
- Raw review text is stored but access-gated at the application layer, not
  just hidden by the UI — the API/backend should never return review text in
  a summary-only request, so there's no "view source" leak.
- API keys/secrets (TMDB, Claude, NewsAPI, etc.) stored server-side only,
  never exposed to the client, ideally in environment config outside the web
  root.
- Rate-limit login attempts and manual refresh actions to blunt both
  credential-stuffing and accidental self-inflicted doomscroll loops.
- Content-flag preferences and any notes a user adds are treated as
  sensitive personal data (§4) — encrypted at rest where the hosting tier
  allows it, excluded from any logging, never included in error reports sent
  to the developer.

---

## 9. Technical architecture

### 9.1 Stack

Keep it simple, matching the "simple web-based app" ask and Hostinger's
shared/business hosting reality:

- **PHP 8.x**, plain/lightweight structure rather than a heavy framework —
  a small MVC-ish layout (`/public`, `/app/controllers`, `/app/models`,
  `/app/services`, `/app/views`) with PDO for MySQL access. Composer is fine
  for a handful of focused libraries (HTTP client, HTML parsing for
  scrapers, a mailer) but avoid pulling in a full framework's worth of
  scaffolding for a project this scoped.
- **MySQL/MariaDB** (whatever Hostinger provisions) for all persistent data.
- **Cron-driven background work** (see 9.3) rather than a persistent worker
  process — Hostinger shared/business plans don't support long-running
  daemons or queues like Redis/RabbitMQ.
- Server-rendered PHP views (or PHP + a light sprinkle of JS/fetch for the
  reveal interactions and charts) — no need for a JS framework/SPA build
  pipeline for something this size.

### 9.2 Hosting on Hostinger — specific constraints to design around

- **No persistent processes.** All scraping/classification/notification work
  must run as short-lived scripts triggered by Hostinger's cron job manager
  (hPanel → Cron Jobs), not as a queue worker that stays running.
- **PHP execution time limits** apply per request/cron run. Structure
  ingestion and classification as small, resumable batches (e.g. "process up
  to 25 pending reviews per cron tick") rather than one big job — use a
  `status` column (`pending` / `processing` / `done` / `failed`) on
  Reviews/Classifications as a lightweight queue implemented in MySQL, polled
  by cron every few minutes.
- **Outbound HTTP** to third-party APIs and scrape targets works fine from
  shared hosting, but confirm Hostinger's plan-specific outbound
  rate/connection limits before assuming unlimited concurrency; keep scraper
  concurrency low and sequential-ish per domain regardless (§7.2 politeness
  rules apply anyway).
- **Email**: use Hostinger's SMTP for digest emails, or a transactional email
  API (e.g. Resend, Postmark) if deliverability from shared-hosting SMTP
  becomes a problem — worth a quick test before committing.
- **File storage**: no unusual media storage needs here (no user uploads of
  substance), so the default hosting disk is fine.
- **Secrets**: store API keys in a `.env`-style file outside `/public`, loaded
  via a small config loader — don't rely on the hosting panel's environment
  variable UI alone, since not all shared-hosting tiers expose one cleanly.

### 9.3 Background job design (cron-based)

Three cron-triggered scripts, each idempotent and safe to overlap-protect
(use a simple DB lock/flag so a slow run doesn't double up if the next tick
fires before it finishes):

1. **Ingestion tick** (e.g. every 15–30 min): for each Tracked Title whose
   cadence says it's due, fetch new content from due Sources, respecting
   per-domain rate limits; write new Reviews as `pending` classification.
2. **Classification tick** (e.g. every 5–10 min): pull a batch of `pending`
   Reviews, call the Claude API with the versioned rubric, write
   Classifications, mark `done`.
3. **Digest tick** (daily, or per-user cadence): compile and send any
   digests due, respecting vacation mode and per-title notification settings.

### 9.4 Data model (illustrative — refine during implementation)

```
users(id, email, auth fields, vacation_mode, created_at)
titles(id, type[book|film], canonical_source, canonical_id, display_name,
       creator_name, year, poster_url, created_at)
tracked_titles(id, user_id, title_id, refresh_cadence, created_at)
sources(id, domain, type[api|scrape], health_status, last_checked_at)
tracked_title_sources(tracked_title_id, source_id, muted_bool)
reviews(id, title_id, source_id, external_url, author, native_rating,
        raw_text, fetched_at, dedup_key)
classifications(id, review_id, sentiment, meanness_score, constructive,
                 personal_attack, content_tags[json], rubric_version,
                 classified_at)
corrections(id, classification_id, user_id, note, created_at)
delegates(id, tracked_title_id, delegate_user_id_or_email, permission_level,
          invited_at, accepted_at)
notifications(id, user_id, tracked_title_id, type, sent_at, payload_summary)
job_queue_status columns live on reviews/classifications rather than a
separate table, per the cron-batch approach in 9.3.
```

### 9.5 External integrations summary

| Purpose | Recommended source | Notes |
|---|---|---|
| Film metadata/verification | TMDB API | Free, well-documented |
| Film metadata cross-check | OMDb API | Also yields IMDb id for scraper targeting |
| Book metadata/verification | Google Books API / Open Library API | Free, no scraping needed |
| News articles | NewsAPI or GNews | Free tiers are limited; budget for paid tier if coverage needs grow |
| Blogs | RSS/Atom polling + web search API for discovery | Robust, low risk |
| Reddit mentions | Reddit API | Official, free within limits |
| Video reviews/comments | YouTube Data API | Official |
| IMDb reviews | Scrape (Tier B) | No public API; high fragility |
| Letterboxd reviews | Scrape (Tier B) | No public API |
| Goodreads reviews | Scrape (Tier B) | API retired 2020 |
| Amazon reviews | Scrape (Tier B) | No public API; strict anti-bot measures, highest risk of the four |
| Sentiment/meanness/constructive classification | Claude API | Rubric-driven, versioned prompts |
| Transactional email | Hostinger SMTP or Resend/Postmark | Test deliverability early |

---

## 10. Non-functional requirements

- **Simplicity first.** A creator should be able to add a title and
  understand their dashboard in under a minute, no onboarding tour required.
- **Mobile-friendly** — creators will check this from their phone; the
  dashboard and reveal flow need to work well on a small screen, including
  the confirmation-before-reveal step.
- **Honest, not falsely reassuring.** Coverage gaps and classification
  uncertainty should be visible (§4), not smoothed over — trust is the whole
  value proposition.
- **Cost awareness.** LLM classification and paid API tiers (news search,
  etc.) are the main ongoing cost centers; batch calls, cache aggressively,
  and make refresh cadence a real lever on cost, not just a UX nicety.
- **Accessibility.** Standard semantic HTML, sufficient color contrast
  (careful with meanness-scale color coding — don't rely on red/green alone),
  keyboard-navigable reveal controls.
- **Graceful degradation.** Any single source going down (especially Tier B)
  should reduce coverage, never break the dashboard or crash a cron tick.

---

## 11. Open questions / risks to revisit with the user before/while building

- **Legal/ToS review of scraping IMDb, Letterboxd, Goodreads, and especially
  Amazon** — this spec designs around it per your steer, but a real ToS/legal
  gut-check is warranted before this goes in front of other creators, not
  just self-use. Worth revisiting once the MVP is otherwise working.
- **LLM classification cost model at scale** — fine for a handful of titles;
  needs a real budget estimate once user count and review volume are known.
- **Whether multi-tenant means self-serve signup from day one, or an
  invite-only beta** — the spec supports full self-serve, but starting
  invite-only for the first few creators (including the seed user) is a
  reasonable, lower-risk way to launch and iterate on the classification
  rubric before opening it up.
- **Hostinger plan tier** — cron frequency, outbound connection limits, and
  Composer/SSH availability vary by plan (shared vs. business vs. VPS);
  confirm the specific plan before finalizing the job-scheduling design in
  §9.3, since a VPS plan would remove several of the constraints in §9.2
  entirely. **Resolved for the shared/business tier (2026-08-18):** these
  plans fix the site root at `public_html` with no document-root setting at
  all — see §13's build log and the root `.htaccess` for how the app adapts
  without moving files or weakening the app/db/bin/cron/.env protection.

---

## 12. Suggested phased build order

1. **MVP**: single Tier A source (e.g. RSS/news) + TMDB verification +
   basic classification pipeline + summary dashboard + manual reveal, single
   invite-only user (the seed filmmaker). Prove the reveal/summary UX and
   the classification rubric before adding sources.
2. **Phase 2**: add remaining Tier A sources (Reddit, YouTube), add the
   personal-attack flag and content-warning tags explicitly, add the
   Correction feedback loop, add digest emails.
3. **Phase 3**: add Tier B scraped sources one at a time (start with
   whichever has the least aggressive anti-bot posture), with health/coverage
   indicators live from the start.
4. **Phase 4**: delegate access, vacation mode, self-serve multi-tenant
   signup, trend charts.

---

## 13. Build log

**Phase 1 (2026-08-18):** Built as specified, plus the personal-attack flag,
content-warning tags, and correction feedback loop were pulled forward from
Phase 2's list and shipped immediately — they're too central to the seed use
case to leave out of the first cut. Delivered as 33 files. PHP-lint clean;
not run against a live MySQL instance or real API keys (no MySQL server
available in the build sandbox).

**Phase 2 (2026-08-18):** Added Reddit (`RedditClient.php`, app-only OAuth,
post/thread-level mentions) and YouTube (`YoutubeClient.php`, videos *plus*
up to 5 top comments per video as separate scored items) as sources, behind
a new `SourceRegistry` that both title-tracking and ingestion now go
through — adding a future source (including a Tier B scraper in Phase 3)
should mean touching only `SourceRegistry` and one new client class. Added
digest emails: a per-title, opt-in (`off` by default) notification cadence
setting, a dependency-free `SmtpMailer` (AUTH LOGIN over STARTTLS, matching
Hostinger's mailbox SMTP), and a third cron script. Digests already respect
`users.vacation_mode`, even though the toggle for it isn't in the UI until
Phase 4 — the column existed since Phase 1's schema, so honoring it now cost
nothing. `db/migrations/002_phase2.sql` covers anyone who already deployed
Phase 1's schema; `db/schema.sql` itself was updated in place for fresh
installs. Same caveats as Phase 1: lint-clean, not run live.

**Deployment fix (2026-08-18):** During actual Hostinger deployment, the
user found their plan's hPanel has no document-root setting at all — the
site root is fixed at `public_html`, contradicting §9.2's assumption that
it could be pointed at `public/`. Rather than moving application files into
`public_html` directly (which would put `app/`, `db/`, `.env` etc. at the
same level a browser can request, undermining the whole point of having a
separate `public/`), fixed it with a `mod_rewrite` block added to the root
`.htaccess`: any request that isn't a real file/directory at the
`public_html` level gets internally rewritten into `public/`, so
`https://domain/login.php` serves `public/login.php` with the URL bar never
showing `/public/`. Zero application code changed — every page already used
root-relative URLs and `__DIR__`-relative requires, which work identically
under this scheme. The original deny-all block in `.htaccess` is untouched;
the rewrite doesn't apply to `app/`, `db/`, `bin/`, `cron/`, or `.env`
because they're real directories/files at that level, so they still fall
straight through to the same deny-all protection as before.
