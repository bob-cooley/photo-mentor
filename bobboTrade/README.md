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
- **News** — merged from two sources: Finnhub `/company-news`
  (`FINNHUB_API_KEY`, free tier, real articles aggregated from actual
  publishers) and SEC EDGAR filings (no key required) as a factual
  regulatory-event supplement. Filing headlines/summaries are built
  from each 8-K's actual `items` field (SEC's own event-type taxonomy)
  rather than a generic "8-K filed" placeholder. An Investor Relations
  RSS feed was tried as a second source early on; dropped after
  confirming MPC's IR site (and IR sites generally) sits behind a
  Cloudflare bot challenge that blocks any scripted client outright.
  v1 of this module was SEC-only on the assumption that no free
  wire-service aggregation API existed — that survey missed Finnhub's
  own news endpoint, already in hand for analyst data.

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
2. **Structural**: one non-agentic call per hour (gated on
   `minute() == 0`, even though the rest of the pipeline runs every
   5 min during market hours) — never a loop, so there's a hard
   ceiling on call volume (≤720/month) regardless of any bug.
3. **A self-imposed circuit breaker** in `ai_insight.py`: tracks
   cumulative estimated spend for the current calendar month and
   refuses to call the API once `AI_MONTHLY_BUDGET_USD` (default
   $3.00) is reached, writing a `"paused_budget"` state instead of
   calling anyway. State persists across CI runs with no database —
   each run reads back the usage summary it deployed live last time
   (`public/data/<TICKER>/ai_usage.json`) rather than committing
   anything to git.

The dashboard shows month-to-date estimated cost and call count as a
small footer line under the insight text — real per-call cost is
roughly $0.001, so the display uses 4 decimal places rather than
rounding to a reassurance-defeating "$0.00".

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

Portfolio share count is never committed (public repo). Copy
`public/portfolio.example.json` to `public/portfolio.json` locally to
see a populated Portfolio module; otherwise it falls back to a
manual, non-persisted input field.

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
