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
                                          IR feed URL, energy indicators
    components/           one component per dashboard module
    lib/                  data loading + formatting helpers
  data/fetch/              Python data pipeline (no live server)
    market.py              price/volume history + quote  → FMP
    energy.py               crude/refinery/inventory data → EIA
    analyst.py              analyst rating consensus       → FMP
    news.py                 SEC filings + IR press releases (no key)
    run_all.py              orchestrator, runs every module × ticker
    generate_mock.py        local-dev fallback data, never deployed
  public/data/<TICKER>/*.json   pipeline output frontend fetches at runtime
```

There is no live backend server — the deploy target is static FTP
hosting. Instead, `data/fetch/run_all.py` runs on an **hourly GitHub
Actions schedule**, writes fresh JSON, and that same workflow run
builds the frontend and FTP-deploys both together. The frontend just
fetches static JSON at load time.

## Adding a new ticker

1. Create `src/config/stocks/<TICKER>/config.json` (copy MPC's as a
   template — ticker, name, CIK, IR RSS URL, energy indicators).
2. Register it in `src/config/stocks.ts`.
3. `run_all.py` auto-discovers every ticker under `src/config/stocks/`,
   so no pipeline changes are needed.

## Data sources

- **Market data (price/volume/quote)** — Financial Modeling Prep
  (`FMP_API_KEY`).
- **Energy/refinery indicators** — EIA public API (`EIA_API_KEY`,
  free, self-serve at [eia.gov/opendata](https://www.eia.gov/opendata/)).
- **Analyst consensus** — Financial Modeling Prep (same key as market
  data).
- **News** — SEC EDGAR filings + the ticker's Investor Relations RSS
  feed only. No key required. Deliberately excludes wire-service
  aggregation (Reuters/Bloomberg/AP/etc.) since none offer a free
  public API — see the build spec's news requirements.

Until `FMP_API_KEY` and `EIA_API_KEY` exist as repository secrets, the
market/energy/analyst modules render their empty state rather than
fake numbers; news still populates since it needs no key.

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
job): on push to `main`, on an hourly schedule, or manually via
`workflow_dispatch`. Builds the frontend, runs the data pipeline, and
FTP-deploys `dist/` to `public_html/bobcooleyphoto/bobboTrade/`.

Required repository secrets: `FTP_HOST`, `FTP_USER`, `FTP_PASS`
(already configured for this repo), plus `FMP_API_KEY` and
`EIA_API_KEY` for live data.

## Not yet implemented

The "Why MPC Moved" module is an architectural placeholder only — the
planned AI reasoning layer (data collection → local extraction →
Claude reasoning) is intentionally deferred per the build spec.
