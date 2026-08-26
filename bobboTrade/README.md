# bobboTrade

Personal financial intelligence dashboard for a long-term concentrated
holding. Prototype covers **MPC** (Marathon Petroleum). Not a trading
app — no execution capability, just an early-warning/understanding
layer: price, refinery/energy conditions, primary-source news, and
analyst consensus in one view.

Live at `bobcooleyphoto.com/bobboTrade/`. Noindexed (`robots.txt`, meta
tag, `X-Robots-Tag` header) — accessible by direct URL only.

## Architecture

```
bobboTrade/
  src/                    React + TypeScript frontend (Vite)
    config/stocks/<TICKER>/config.json   per-ticker config: name, CIK,
                                          energy indicators
    components/           one component per dashboard module
    lib/                  data loading + formatting helpers
  data/fetch/              Python data pipeline (no live server)
    market.py               daily history + quote + 5min intraday → Twelve Data
    energy.py                crude/refinery/inventory data       → EIA
    analyst.py                analyst rating consensus            → Finnhub
    news.py                   SEC filings (Item-coded)             (no key)
    ai_insight.py              "why did it move" narrative          → Claude (Haiku 4.5)
    run_all.py                orchestrator, runs every module × ticker
    generate_mock.py          local-dev fallback data, never deployed
  public/data/<TICKER>/*.json   pipeline output frontend fetches at runtime
```

There is no live backend server — the deploy target is static FTP
hosting. Instead, `data/fetch/run_all.py` runs on a **GitHub Actions
schedule** (hourly baseline, every 5 min during NYSE hours for the
intraday chart), writes fresh JSON, and that same workflow run builds
the frontend and FTP-deploys both together. The frontend also polls its
own static JSON every 2 minutes while a tab is open. This is the
practical ceiling for "live" on a statically-hosted site without a live
backend — not true streaming, but close during market hours.

## Adding a new ticker

1. Create `src/config/stocks/<TICKER>/config.json` (copy MPC's as a
   template — ticker, name, CIK, energy indicators).
2. Register it in `src/config/stocks.ts`.
3. `run_all.py` auto-discovers every ticker under `src/config/stocks/`,
   so no pipeline changes are needed.

## Data sources

- **Market data (price/volume/quote, daily + 5min intraday)** —
  Twelve Data (`TWELVEDATA_API_KEY`), free tier. FMP was tried first
  and is a dead end for this project: its free tier turned out to
  whitelist only mega-cap tickers (MSFT/TSLA/XOM/CVX work, MPC/VLO/PSX
  all 402) across quote, history, and analyst endpoints alike.
- **Energy/refinery indicators** — EIA public API (`EIA_API_KEY`,
  free, self-serve at [eia.gov/opendata](https://www.eia.gov/opendata/)).
  **Known limitation:** EIA's edge (Akamai) 403-blocks GitHub Actions'
  runner IP ranges specifically — confirmed via repeated identical
  requests that succeed from a residential IP and fail every time from
  CI. Not fixable from the request itself (retries, UA, full
  browser-shaped headers all made no difference); the code is correct
  and works if run from an unblocked network. Shipped without live
  energy data for now — see `data/fetch/energy.py`'s docstring. A
  Cloudflare Worker relay (this domain's already on Cloudflare) is the
  most promising fix if revisited.
- **Analyst consensus** — Finnhub (`FINNHUB_API_KEY`), free tier
  (recommendation trends only; price target is paid-tier on Finnhub,
  so that field is always null). Twelve Data gates its own
  `/recommendations` and `/price_target` to paid plans.
- **News** ("Market News" card) — merged from three sources: Finnhub
  `/company-news` (`FINNHUB_API_KEY`, free tier, ticker-scoped), CNBC's
  public Energy topic RSS (no key, not ticker-scoped), and SEC EDGAR
  filings (no key required) as a factual regulatory-event supplement.
  The Finnhub feed alone quietly narrowed this module's actual goal —
  "articles that relate to why the market is for the day, and why they
  influence the stock behaviour" — to only stories that mention MPC by
  name, which misses the crude-price/OPEC/geopolitical stories that
  actually move a refiner's stock without ever saying "Marathon
  Petroleum." `fetch_energy_sector_news()` closes that gap.
  Both news sources are filtered to an allowlist of Tier-1 sources
  (Reuters, Bloomberg, Financial Times, Wall Street Journal, Associated
  Press — the build spec's explicit "ONLY use highly reliable sources"
  rule, which excludes Yahoo Finance, Motley Fool, and generic
  aggregators by name — plus CNBC and MarketWatch, added despite
  not being named in the spec since both are staff-reported newsrooms
  with no subscription-newsletter funnel biasing article framing, unlike
  Benzinga/SeekingAlpha which stayed excluded). Finnhub's raw feed mixes
  Tier-1 wire content with exactly the sources the spec excludes, so
  every item is checked against an allowlist (`is_tier_1_source()` in
  `news.py`) before being kept — an unrecognized source is dropped by
  default, not assumed acceptable. Both sources also tag real Reuters/AP
  wire stories syndicated onto another outlet's domain (Yahoo
  especially, but any outlet can run a wire dispatch) with that domain's
  name, so `detect_wire_partner()` does a one-shot best-effort fetch of
  every Tier-1 candidate (capped at 10/run) and checks for Yahoo's own
  `yContentPartner` metadata or the classic wire dateline ("(Reuters)
  -") to credit the real primary source instead of the hosting outlet.
  In practice Reuters/Bloomberg/FT/WSJ/AP essentially never appear for
  MPC specifically even after that check (verified via a diagnostic log
  Finnhub prints on every run), so ticker-scoped news mostly leans on
  CNBC/MarketWatch when available; the energy-sector feed fills in the
  rest most days. SEC filings are capped at
  `SEC_MAX_ARTICLES` (3) — a much lower limit than the card's overall
  cap — so the feed doesn't pad itself out with filings from months or
  years ago just because Tier-1 news is thin that day; a short,
  genuinely-recent list beats a long stale one. Filing headlines/summaries
  are built from each 8-K's actual `items` field (SEC's own event-type
  taxonomy) rather than a generic "8-K filed" placeholder. An Investor
  Relations RSS feed was tried as a second source early on; dropped after
  confirming MPC's IR site (and IR sites generally) sits behind a
  Cloudflare bot challenge that blocks any scripted client outright.

If a required key is missing, that module renders its empty state
rather than fake numbers — never silently substitutes mock data in a
real deploy.

## AI insight ("Why MPC Moved")

The one module in the build spec that was always deferred as a future
AI reasoning layer. Implemented as a single Claude API call (Haiku
4.5, `ANTHROPIC_API_KEY`) fed only data this pipeline already fetched
that same run — today's price move, recent closes, energy indicators,
recent SEC filings — no separate extraction stage, since the inputs
are already small structured JSON, not large unstructured text that
would need summarizing first. The system prompt explicitly forbids
buy/sell/hold language; it explains, it doesn't advise, and stays
grounded — if the data doesn't clearly explain the move, it says so
rather than inventing a reason.

**Cost control, layered:**
1. **The Anthropic Console spend cap is the real backstop** — set a
   hard monthly limit at console.anthropic.com → Settings → Billing.
   Nothing below substitutes for this.
2. **Structural**: one non-agentic call per hour (gated by comparing
   the current hour against the last successful call's hour, not
   wall-clock `minute() == 0` — the cron fires at `:00` but job
   startup overhead means the script itself usually runs a bit past
   that), even though the rest of the pipeline runs every 5 min during
   market hours — never a loop, so there's a hard ceiling on call
   volume (≤720/month) regardless of any bug.
3. **A self-imposed circuit breaker** in `ai_insight.py`: tracks
   cumulative estimated spend for the current calendar month and
   refuses to call the API once `AI_MONTHLY_BUDGET_USD` (default
   $3.00) is reached, writing a `"paused_budget"` state instead of
   calling anyway. State (call count, token counts, estimated cost)
   persists as a small git-committed file
   (`data/fetch/state/ai_usage_<TICKER>.json`, committed by the
   "Commit AI usage state" step in `deploy.yml` with `[skip ci]` so it
   doesn't re-trigger the workflow) rather than a database. Two earlier
   approaches — reading it back from the live site over HTTP, and a
   direct-to-origin bypass around Cloudflare — both failed for real
   reasons specific to this host (Bot Fight Mode's JS challenge can't
   be passed by a script; Pair's plain-HTTP vhost for this account
   doesn't serve the real site). This data isn't sensitive the way the
   portfolio share count is, so tracking it as an ordinary git file
   sidesteps all of that — no network call, no credentials, just a
   file already sitting in the checkout the job is running from.

Usage isn't shown on the main dashboard — clicking the "bobboTrade"
title in the header opens a small popup with month-to-date estimated
cost, budget, call count, and when it last updated.

## Access control

This is a private 2-person tool (portfolio share count, extracted
article text), not a public app, but it's deployed to a real public URL
with no built-in host-level access control. `public/gate.php` is a PHP
front controller that every request under `/bobboTrade/` is routed
through (see the `RewriteRule` in `public/.htaccess`) — it gates the app
shell, the JS/CSS bundle, and the static JSON data underneath it, not
just an HTML landing page. Unauthenticated requests get a custom login
page (password field with a show/hide toggle, an animated background
chart); a correct password sets a 90-day session cookie. It also serves
a small JSON API (`GET`/`POST /bobboTrade/api/portfolio`, see
`handle_portfolio_api()` in `gate.php`) for the portfolio share count —
see "Portfolio persistence" below.

This replaced an earlier HTTP Basic Auth version specifically because
Basic Auth's browser-native dialog can't be restyled and has no page
content behind it (the server returns 401 before sending anything) —
neither the show/hide toggle nor a background animation is possible
with it. To change the login password: `htpasswd -nbBC 12 <user>
<new-password>` and paste the resulting hash into `PASSWORD_HASH` in
`gate.php`. (`ai_insight.py` used to log into the site to read back its
own prior usage and needed a matching secret kept in sync with this
password — that approach was abandoned, see "AI insight" above, so
there's no longer a second place to update.)

## Portfolio persistence

Share count is entered once and persists server-side
(`public/portfolio-data.json`, written by `gate.php`'s `/api/portfolio`
endpoint) so both household members see the same value from any
device — not the old per-browser-only fallback. Real financial data, so
this file is never committed to git (same pattern as `ai_usage.json`/
`insight.json`: pipeline- or runtime-written, gitignored, survives
deploys since the FTP sync only pushes/updates files present locally
and never deletes extras). An "Edit" link next to the share count
updates it in place for buys/sells.

## Local development

```bash
cd bobboTrade
npm install
python3 -m venv data/fetch/.venv
source data/fetch/.venv/bin/activate
pip install -r data/fetch/requirements.txt
python3 data/fetch/generate_mock.py MPC   # or run_all.py with real keys set
npm run dev
```

The Portfolio module's `/api/portfolio` endpoint is PHP-backed (see
"Portfolio persistence" above), so it 404s under `npm run dev` (no PHP
server locally) — the module just shows its empty "enter a share
count" state, same as any other missing data file in local dev.

## Deployment

GitHub Actions (`.github/workflows/deploy.yml`, `deploy-bobbotrade`
job): on push to `main`, on the schedules above, or manually via
`workflow_dispatch`. Builds the frontend, runs the data pipeline, and
FTP-deploys `dist/` to `public_html/bobcooleyphoto/bobboTrade/`.

Required repository secrets: `FTP_HOST`, `FTP_USER`, `FTP_PASS`
(already configured for this repo), plus `TWELVEDATA_API_KEY`,
`EIA_API_KEY`, `FINNHUB_API_KEY`, and `ANTHROPIC_API_KEY` for live
data.

## Not yet implemented

- Live energy/refinery data — see the EIA limitation above.
