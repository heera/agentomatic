# Spec: AI usage signals — enforceable "no AI training" across all channels

Status: **implemented, unreleased** — the plugin version is intentionally left at
1.1.0 (the last published release) and will be bumped to the next version at
publish time. Decisions 1–3 settled per §13: channels on by default; nothing
emitted when allowed; `noai` shipped, default off.
Post-build UX refinement: the opt-out channels are "opt-out only" — the header
and `tdmrep.json` publish ONLY when AI training is blocked (an open site
publishes neither; no `tdm-reservation: 0` file). The per-agent header
escalation was DROPPED — redundant with the robots.txt block list, unreliable
behind caches, and the source of "ON-but-inactive" confusion when training was
allowed. The UI hides the channel toggles entirely when training is allowed and
shows a one-line explanation instead.
Owner: Agentimus
Scope: extend the existing "Allow AI training" control so its decision is
published not just in robots.txt, but also as HTTP response headers and a
standardized `/.well-known/tdmrep.json` opt-out file.

---

## 1. Goal

Today the site's AI-training preference (`content_signal.ai_train`) is only
emitted in **robots.txt** — an advisory channel a bot can ignore. This feature
broadcasts the **same decision** through the channels robots.txt can't reach:

- the **W3C TDM Reservation Protocol** HTTP header (`tdm-reservation`), and
- the standardized opt-out file **`/.well-known/tdmrep.json`**,
- optionally the widely-honored `X-Robots-Tag: noai, noimageai` header.

This lets an admin retire a separate "AI opt-out / block AI crawlers" plugin —
Agentimus already owns the crawler list, robots.txt, and the `/.well-known/`
router, so this is additive, not a new surface area.

**Design principle: one decision, many outputs.** No new "do you want AI
training" toggle. The existing **Allow AI training** switch stays the single
source of truth; this feature only widens where that one choice is published.

## 2. Non-goals

- Not a per-bot *enforcement* layer. Truly *stopping* a bot (403) is already
  Guard's job (`block_agents` / the Activity → Block flow). These are **signals**
  (advisory), not blocks.
- Not per-page / per-post reservations in v1 (tdmrep supports per-path; we ship a
  single site-wide `/` entry and can extend later).
- Not touching the discovery docs (llms.txt, discovery.json, …) — those are
  "please read me" surfaces and must **never** carry a no-train signal.

## 3. Background — what exists today

| Piece | Where | Behaviour |
|---|---|---|
| `content_signal` = {search, ai_input, ai_train} | `Settings::defaults()` (ai_train default **false** = reserved) | drives the robots.txt `Content-Signal:` line |
| robots.txt `Content-Signal: … ai-train=no` | `Endpoints::content_signal_string()` + `robots_txt()` | advisory, site-wide |
| Per-bot block list (`blocked_trainers`) | `Endpoints::robots_txt()` | named `User-agent: X` + `Disallow: /` in robots.txt, applied whether training is allowed or not |
| Hard 403 (`block_agents`, `blocked_agents`) | `Guard` | real enforcement at generated endpoints (opt-in) |
| `/.well-known/*` router | `Discovery\WellKnown` | flat + nested allow-list, `send()`/`stream()`, clean-404 |
| `send_headers` hook | `Endpoints::link_headers()` (prio 99) | already the place we add response headers |

## 4. The decision → channel matrix (after this feature)

Effective reservation for a request:

```
reserved = (content_signal.ai_train === false)              // global stance
           OR (ai_header_per_agent AND UA ∈ blocked_trainers) // advanced, header only
```

| Channel | Per-bot? | Cache-safe? | Source of truth |
|---|---|---|---|
| robots.txt `Content-Signal` + named `Disallow` | ✅ (named list) | n/a (bot fetches directly) | `ai_train` + `blocked_trainers` — **unchanged** |
| HTTP `tdm-reservation` header | global by default; per-bot only if `ai_header_per_agent` | global ✅ / per-bot needs `Vary` ⚠️ | `ai_train` |
| `/.well-known/tdmrep.json` | ❌ site-wide only (standard has no per-bot dial) | ✅ | `ai_train` |
| Guard 403 | ✅ named | n/a | `blocked_agents` — **unchanged** |

### Per-bot in the header — the caching caveat (important)

A per-UA response header is **not reliable behind a full-page cache / CDN**: the
first cached copy (say, for a human) is replayed to every later visitor
regardless of UA, so a bot may receive a copy minted for someone else. Therefore:

- **Default:** the header carries the **global** stance only (same for everyone)
  → cache-safe.
- **Per-bot escalation** (`ai_header_per_agent`, advanced, default off): when on,
  also reserve for UAs in `blocked_trainers` **and emit `Vary: User-Agent`** so a
  shared cache won't serve a bot-specific header to humans. Documented as
  best-effort (and it costs cache efficiency).
- The robust per-bot channels remain **robots.txt** (the bot fetches it itself)
  and **Guard** (real 403). The header per-bot is a bonus, not the backbone.

## 5. Detailed design

### 5.1 robots.txt — unchanged
No change. `Content-Signal` line + named `blocked_trainers` Disallow stay exactly
as they are.

### 5.2 HTTP headers — `Endpoints::ai_signal_headers()`

- Hook: `add_action( 'send_headers', array( $this, 'ai_signal_headers' ), 99 )`.
- Bail when: `is_admin()`, REST, or the path is one of **our own** read-me
  surfaces — `/llms.txt`, `/llms-full.txt`, `*.md`, `/.well-known/*`,
  `/robots.txt`, feeds. (Path check on `REQUEST_URI`, not conditional tags, since
  `send_headers` runs before the query is fully resolved.)
- Gated by `enable_ai_header`.
- Compute `reserved` (see §4). **Emit only when reserved** (web default = not
  reserved, so silence = allowed; avoids stamping every page when AI is allowed):
  - `tdm-reservation: 1`
  - `tdm-policy: <url>` if `tdm_policy_url` is set
  - `X-Robots-Tag: noai, noimageai` if `ai_noai_header` is on (append with
    `replace = false` so we never clobber an existing `X-Robots-Tag`)
  - if per-bot escalation contributed the reservation → also `Vary: User-Agent`
- Factor the decision into a **pure** method `tdmrep_state( $ua = null ) : array`
  returning `{ reserved:bool, policy:string, per_agent:bool }` so it's unit
  testable without `header()`.

### 5.3 `/.well-known/tdmrep.json` — `WellKnown` + new `Tdmrep` builder

- Add `'tdmrep.json'` to `WellKnown::routed_names()` (always routed; gated docs
  that produce nothing fall through to `maybe_clean_404()`, same pattern as
  `oauth-protected-resource` and the signer directory).
- New case in `WellKnown::route()`:
  ```php
  case 'tdmrep.json':
      $body = ( new \Agentimus\Tdmrep( $this->settings ) )->json();
      if ( '' !== $body ) {
          $this->send( $body, 'application/json', 'tdmrep.json' );
      }
      break;
  ```
- New class `inc/Tdmrep.php` (`Agentimus\Tdmrep`), pure/testable:
  - `json() : string` → `''` when `enable_tdmrep` is off, else
    ```json
    [ { "location": "/", "tdm-reservation": 1, "tdm-policy": "https://…" } ]
    ```
    `tdm-reservation` = `1` when `ai_train === false` else `0` (explicit 0/1 so the
    stance is auditable either way). `tdm-policy` included only when set.
  - Site-wide `/` only in v1.
- Reuse the existing `send()` emitter (gets `X-Content-Type-Options: nosniff`,
  `Access-Control-Allow-Origin: *`, `Cache-Control: public, max-age=3600`).
- **Rewrite flush:** `routed_names()` feeds `WellKnown::add_rules()`. Adding a name
  changes the rule set, so bump the activation/upgrade rewrite-flush (the plugin
  already flushes on activation — ensure an upgrade path re-flushes once, e.g. a
  stored-version compare in `Plugin`).

### 5.4 Optional HTML `<meta>` (deferred)
TDMRep also defines `<meta name="tdm-reservation" content="1">`. The header is
cleaner and cache-friendlier; skip the meta in v1 (note it as a future option for
static-HTML hosts that can't set headers).

## 6. Settings / schema changes (`Settings.php`)

`defaults()` — add:

```php
'enable_ai_header'   => true,   // emit tdm-reservation header (see §6 decision)
'enable_tdmrep'      => true,   // serve /.well-known/tdmrep.json
'ai_noai_header'     => false,  // also send X-Robots-Tag: noai,noimageai (best-effort)
'ai_header_per_agent'=> false,  // advanced: per-bot header escalation + Vary
'tdm_policy_url'     => '',      // optional URL to the owner's TDM policy
```

`sanitize()`:
- add `enable_ai_header`, `enable_tdmrep`, `ai_noai_header`,
  `ai_header_per_agent` to the boolean loop (`! empty( $input[$flag] )`).
- `tdm_policy_url` → `esc_url_raw()`.

> **Open decision (defaults):** the new channels are listed **on** above to honor
> "one decision, three outputs" — flipping *Allow AI training* off then
> immediately reserves in all three places. The conservative alternative (match
> `security_txt`/`signing`, default **off**, opt-in) avoids a new public file/
> header appearing silently on upgrade, at the cost of the single-switch promise.
> Recommendation: **on**, with a changelog note + the readiness nudge in §8.
> (`ai_noai_header` and `ai_header_per_agent` stay **off** either way — they're
> the non-standard / cache-costly extras.)

## 7. Admin UI (`resources/admin/components/SettingsForm.vue`)

Extend the existing **Allow AI training** card (no new top-level section):

- Under the current `Emitted in robots.txt as Content-Signal: …` preview, add
  the other live previews, each tied to the same switch:
  - `Also sent as header  tdm-reservation: 1` (shown when reserved)
  - `Published at  /.well-known/tdmrep.json`
- An **Advanced / "Where this is published"** disclosure with per-channel
  checkboxes: robots.txt (existing `enable_robots`), HTTP header
  (`enable_ai_header`), tdmrep.json (`enable_tdmrep`), and the extras
  (`ai_noai_header`, `ai_header_per_agent`), plus the `tdm_policy_url` field.
- A plain-English note next to tdmrep.json: *"This file is site-wide — it can't
  block individual bots. Per-bot blocks live in the crawler list (robots.txt) and
  in Guard for a hard 403."* (mirrors the agreed transparency.)

Bootstrap: new keys flow to the SPA automatically via `Settings::all()` →
`Admin::bootstrap_data()['settings']`; no bootstrap change needed.

## 8. Readiness check (`Readiness.php`)

Add `check_ai_usage_policy()` to `report()`:
- **pass** — `ai_train` reserved AND (`enable_ai_header` || `enable_tdmrep`) on:
  *"Your no-AI-training preference is published as enforceable signals (header +
  /.well-known/tdmrep.json), not just robots.txt."*
- **warn** — `ai_train` reserved but both new channels off: *"You ask bots not to
  train in robots.txt, but that's advisory only. Turn on the response header and
  tdmrep.json to back it with standardized signals."* → `nav()` CTA to the card.
- **pass (informational)** — `ai_train` allowed: *"AI training is allowed; no
  reservation is published."*

## 9. Edge cases & precedence

- **Static `robots.txt` at web root** overrides the virtual one (existing
  readiness warn). The header + tdmrep are **independent** of robots.txt, so the
  no-train stance survives even when a static robots.txt shadows ours — a genuine
  win to call out in the readiness/help copy.
- **Discovery / llms surfaces excluded** — never marked reserved (§5.2 bail list);
  they invite reading.
- **Signed docs** (`signed_surfaces()`) untouched.
- **HEAD** requests: headers still emitted (good).
- **Non-public site** (`blog_public=0`): orthogonal; reservation may still be
  declared. Leave as-is.
- **CDN/page cache + per-bot header**: see §4 caveat (`Vary: User-Agent`).

## 10. Tests (`tests/`, pure-logic style — stub WP funcs in bootstrap)

- `TdmrepTest` — `json()`: off → `''`; reserved → `tdm-reservation:1`; allowed →
  `0`; policy present/absent; shape is an array with `location:"/"`.
- `AiSignalHeadersTest` — `tdmrep_state()`: global reserved/allowed; per-agent on
  with UA in/out of `blocked_trainers`; per-agent off ignores UA.
- `SettingsDefaultsTest` (extend) — new keys present + correct types;
  `sanitize()` coerces the new booleans and `esc_url_raw`s `tdm_policy_url`.
- `ReadinessTest` (or extend) — the pass/warn branches of
  `check_ai_usage_policy()`.
- `WellKnownDocsTest` (extend) — `tdmrep.json` is in `routed_names()` and routes;
  off → clean 404.

## 11. Files touched

| File | Change |
|---|---|
| `inc/Settings.php` | new default keys + sanitize |
| `inc/Endpoints.php` | `send_headers` → `ai_signal_headers()` + pure `tdmrep_state()` |
| `inc/Tdmrep.php` | **new** — `json()` builder |
| `inc/Discovery/WellKnown.php` | route `tdmrep.json` |
| `inc/Plugin.php` | one-time rewrite re-flush on version bump (if not already) |
| `inc/Readiness.php` | `check_ai_usage_policy()` |
| `resources/admin/components/SettingsForm.vue` | previews, advanced channel toggles, policy URL, tdmrep note |
| `tests/*` | as §10 |
| `readme.txt` | changelog + FAQ entry |

Build note: PHP changes need no build; the Vue change needs
`cd plugins/agentimus && npm run build`. Rewrite rules must be flushed once on
upgrade for `tdmrep.json` to route.

## 12. Standards references

- W3C TDM Reservation Protocol (TDMRep) — `tdm-reservation` / `tdm-policy` HTTP
  headers + `/.well-known/tdmrep.json` (array of `{location, tdm-reservation,
  tdm-policy}`; reservation `1` = reserved, `0` = not).
- content-signals.org — the robots.txt `Content-Signal` line (already shipped).
- `X-Robots-Tag: noai, noimageai` — non-standard, honored by some platforms;
  strictly best-effort, hence opt-in.

## 13. Open decisions (for sign-off)

1. **Default on vs opt-in** for `enable_ai_header` / `enable_tdmrep` (see §6).
   Recommendation: **on** (fulfils the one-switch promise) + changelog note.
2. **Header when allowed:** emit nothing (recommended) vs explicit
   `tdm-reservation: 0`. Recommendation: emit nothing on pages; `tdmrep.json`
   still states 0/1 explicitly.
3. Ship `ai_noai_header` (non-standard `noai`) in v1, or hold? Recommendation:
   include, default **off**.
