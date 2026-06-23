# Spec: "Is AI sending me visitors?" — AI referral tracking

Status: **implemented, unreleased** (version held at 1.1.0 until publish).
Decisions settled: folded under the existing `enable_activity` flag (no new
toggle); server-side only (no beacon in v1); per-page paths tracked. Dashboard
mirrors the existing "Endpoint activity → Top clients/By endpoint" structure
(overview card + a `.ar-wd-cols` pair) rather than a cramped in-card 2-column.
Owner: Agentimus
Scope: count real human visits that arrive **from** AI assistants (ChatGPT,
Perplexity, Gemini, Copilot, Claude, …) and surface them on the dashboard — the
mirror image of the existing agent-activity log, which counts bots **taking**
content.

---

## 1. Goal

Today Agentimus shows who **reads/takes** your content (bots hitting the
discovery/llms endpoints — `Activity\Recorder`). It says nothing about whether
that AI exposure **brings you readers**. This feature answers the other half:

> "How many real people landed on my site from an AI answer this week — and on
> which pages?"

It replaces the reason an admin would reach for Google Analytics / a referral
plugin *just* to see AI traffic, and it does so the Agentimus way: **first-party,
aggregate-only, no IP, no external calls** (the existing privacy promise holds).

## 2. Non-goals

- Not general web analytics (no pageview tracking, sessions, funnels, IPs).
- Not per-visitor data. We store **daily aggregate counts only** — there is no
  row that represents one person.
- Not Google "AI Overviews" attribution in v1: those arrive with a plain
  `google.com` referrer, indistinguishable from normal search, so counting them
  would be guesswork. Excluded to avoid false positives (revisit if Google adds a
  distinguishing marker).

## 3. How it differs from the existing Activity log

| | Existing agent-activity log | This feature |
|---|---|---|
| Event | a **bot** fetches an endpoint | a **human** lands on a content page |
| Trigger | the serve-paths call `Recorder::record()` | a front-end content view with an AI referrer |
| Keyed by | endpoint + classified agent + UA | AI **source** + landing **path** |
| Stored as | one row per hit (`agentimus_agent_hits`) | **daily aggregate** counts (new table) |
| Identity | the "taking" side | the "giving back" side |

They're complementary, so this lives in `inc/Activity/` beside the rest, but in
its own table and its own recorder — it must NOT pollute `byAgent`/`byEndpoint`/
`threats`, which are about bots.

## 4. Detection

A visit counts as AI-referred when **either** signal matches a known AI source:

1. **Referrer host** — `HTTP_REFERER`'s host (e.g. `chatgpt.com`,
   `www.perplexity.ai`, `gemini.google.com`).
2. **UTM tag** — `utm_source` on the URL (e.g. ChatGPT appends
   `?utm_source=chatgpt.com`). Catches the case where the referrer is stripped.

Seed source map (domain **and** utm value → canonical label), filterable via
`agentimus_ai_referral_sources`:

| match | label |
|---|---|
| chatgpt.com, chat.openai.com | ChatGPT |
| perplexity.ai (+ www) | Perplexity |
| gemini.google.com, bard.google.com | Gemini |
| copilot.microsoft.com | Copilot |
| claude.ai | Claude |
| you.com | You.com |
| poe.com | Poe |

The matcher is a **pure function** `Referrals::source_for( $referer, $utm ) :
?string` so it unit-tests without WordPress or a DB.

## 5. Recording — `inc/Activity/Referrals.php`

- Hook: `add_action( 'template_redirect', [...], 30 )` (front-end, after our own
  redirects; before the template renders).
- Bail fast (no DB touch) when **any** of:
  - `is_admin()`, REST, AJAX, not a `GET`, `is_feed()`, `is_404()`,
  - the path is one of our own surfaces (`/llms.txt`, `*.md`, `/.well-known/*`,
    `robots.txt`),
  - the request is a known **bot** (`Classifier::classify($ua)` is a crawler — we
    only want humans),
  - `enable_referrals` is off,
  - `source_for()` returns null (the overwhelmingly common case → cheap no-op),
  - self-traffic: a logged-in admin, mirroring `Recorder`'s
    `agentimus_activity_skip_self` filter.
- On a match → **increment** the aggregate row for `(today UTC, source, path)`:
  - `path` = request path only, **query string stripped** (so no UTM/PII is
    stored), capped to 190 chars.
  - `INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1` — one tiny write, only for
    genuinely AI-referred visits (a small fraction of traffic).

Because the write happens **only** when an AI referrer is present, normal traffic
pays just two `$_SERVER` reads — negligible.

## 6. Storage — daily aggregate table

New table `{prefix}agentimus_ai_referrals` (own version option
`agentimus_referrals_db_version`, installed via the `Activity\Table` dbDelta
pattern):

```
id      bigint unsigned AUTO_INCREMENT PRIMARY KEY
day     date          NOT NULL
source  varchar(40)   NOT NULL DEFAULT ''
path    varchar(190)  NOT NULL DEFAULT ''
hits    int unsigned  NOT NULL DEFAULT 0
UNIQUE KEY uniq (day, source, path(150))   -- powers the upsert; prefix keeps it under index limits
KEY day (day)
```

Aggregate-by-design: the smallest unit is a day, so there is **no per-visit
record** — privacy-safe by construction, and tiny (only days × sources × cited
pages).

## 7. Retention & cleanup

- Reuse the existing window: `agentimus_activity_retention_days`
  (default 30). Prune referral rows older than the window in the **same** daily
  cron (`agentimus_prune_activity`) — add a `Referrals::prune()` call alongside
  `Repository::prune()`.
- `uninstall.php`: drop the new table + its version option (next to the existing
  `agentimus_agent_hits` teardown).

## 8. Read API + dashboard

- Add a `referrals` key to `Repository::stats()` (the Dashboard already fetches
  `GET /activity`, so no new request):
  ```php
  'referrals' => Referrals::summary( $window ),   // { enabled, totals:{window,today}, bySource:[{label,hits}], topPages:[{path,hits}], daily:[{date,hits}] }
  ```
- New Dashboard card **"Traffic from AI"** (App.vue, dashboard tab), shown when
  `enable_referrals` is on:
  - headline: visits from AI in the last N days (+ today),
  - **by source** list (ChatGPT 42, Perplexity 18, …),
  - **top landing pages** AI sends people to,
  - a small daily sparkline (reuse the existing activity chart component if cheap;
    otherwise omit in v1).
- Empty state (no AI referrals yet): a one-liner — *"No AI-referred visits
  recorded yet. When someone arrives from ChatGPT, Perplexity, etc., it'll show
  here."* — so the card never looks broken.

## 9. Settings

- `enable_referrals` — default **true** (privacy-safe, part of the activity
  story). Add to the boolean loop in `Settings::sanitize()` and to `defaults()`.
  Surface it as a toggle near "Agent activity log" with a one-line privacy note.
- Source list stays **filter-only** in v1 (`agentimus_ai_referral_sources`) — no
  UI, to avoid clutter; revisit if users ask to add custom sources.

## 10. Privacy (load-bearing — keep the promise)

- Stored: `day`, `source`, `path`, `count`. **Never** an IP, UA, user id, or
  query string. No row maps to a person.
- No outbound requests; detection reads only headers already on the request.
- "External services: none" in readme.txt stays true. This is a real
  differentiator vs. analytics tools — call it out in the UI ("counted on your
  own site, no IP, nothing sent anywhere").

## 11. Known limitations (state them honestly in the UI/readme)

- **Full-page cache / CDN:** a cached page is served without running PHP, so those
  views aren't counted → undercount on cached sites. (See §13 for the optional
  beacon.)
- **Referrer stripped:** some browsers / privacy settings drop the referrer; the
  UTM check recovers ChatGPT-style links but not all.
- **Google AI Overviews** not attributable (see §2).
- So the number is a **floor** ("at least N"), not a precise analytics figure —
  word the UI accordingly ("AI-referred visits we could attribute").

## 12. Tests (`tests/`, pure-logic style)

- `ReferralSourceTest` — `source_for()`: each known referrer host → label; each
  `utm_source` → label; unknown / empty → null; case-insensitivity; subdomain
  handling.
- Path sanitization: query string stripped, length capped.
- `Referrals::summary()` shape (with a stubbed DB layer or extracted pure
  shaping).
- Bot skip: a Classifier-identified crawler UA is ignored even with an AI
  referrer.

## 13. Open decisions

1. **Optional client beacon for cached sites?** Server-side is the v1 default
   (no JS, on-brand). For cache-heavy sites, an opt-in tiny first-party beacon
   (`enable_referral_beacon`, default off) could POST `(source, path)` to a public
   `POST /activity/referral` that re-validates the source server-side. Trade-offs:
   breaks the "no front-end JS" stance (hence opt-in), and a public counter needs
   light abuse-hardening (validate referrer, accept only known sources, it's a
   vanity count with no security impact). **Recommendation: ship server-side only
   in v1; add the beacon later only if users hit the cache gap.**
2. **`enable_referrals` default on vs off** — recommend **on** (privacy-safe; it's
   the payoff of the activity feature). Off would hide a flagship insight.
3. **Per-page detail** — store `path` (recommended; "which pages AI cites" is the
   best part) vs source-only. Cardinality is naturally small (only cited pages),
   so keep paths.

## 14. Files

| File | Change |
|---|---|
| `inc/Activity/Referrals.php` | **new** — matcher (`source_for`), recorder, table install, `summary()`, `prune()` |
| `inc/Activity/Module.php` | install the table; call `Referrals::prune()` in the prune cron |
| `inc/Activity/Repository.php` | add `referrals` to `stats()` |
| `inc/Settings.php` | `enable_referrals` default + sanitize |
| `resources/admin/App.vue` | "Traffic from AI" dashboard card + empty state |
| `uninstall.php` | drop the referrals table + version option |
| `tests/ReferralSourceTest.php` | **new** |
| `readme.txt` | FAQ ("Does it show traffic AI sends me?") + privacy note; changelog under `= Unreleased =` |

Build note: PHP needs no build; the dashboard card needs
`cd plugins/agentimus && npm run build`. No version bump (held at 1.1.0 until the
user publishes).
