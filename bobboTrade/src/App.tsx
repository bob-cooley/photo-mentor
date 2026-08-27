import { useEffect, useState } from "react";
import { DEFAULT_TICKER, STOCKS, getStockConfig } from "./config/stocks";
import {
  loadAnalystData,
  loadInsightData,
  loadIntradayData,
  loadMarketData,
  loadNewsData,
  loadPortfolioConfig,
  loadRSIData,
  savePortfolioConfig,
} from "./lib/dataLoader";
import type {
  AnalystData,
  InsightData,
  IntradayData,
  MarketData,
  NewsData,
  PortfolioConfig,
  RSIData,
} from "./types";
import NewsColumn from "./components/NewsColumn";
import ChartColumn from "./components/ChartColumn";
import RSICard from "./components/RSICard";
import AnalystConsensusCard from "./components/AnalystConsensusCard";
import TwoWeekMovementCard from "./components/TwoWeekMovementCard";
import InsightCard from "./components/InsightCard";
import PortfolioCard from "./components/PortfolioCard";
import "./App.css";

// The data pipeline itself only refreshes every 5-60 min (see
// data/fetch/), so polling the static JSON more often than that just
// re-fetches the same file — this cadence keeps an open tab reasonably
// current without hammering the host for no reason.
const REFRESH_INTERVAL_MS = 2 * 60 * 1000;

const TICKERS = Object.keys(STOCKS);

export default function App() {
  const [ticker, setTicker] = useState(DEFAULT_TICKER);
  const stock = getStockConfig(ticker);

  // Quotes for every tracked ticker, independent of which one is active —
  // this drives the always-visible header price for both MPC and COP so
  // switching tabs doesn't need a fetch to show the other one's price.
  const [quotes, setQuotes] = useState<Record<string, MarketData | null>>({});
  const [intraday, setIntraday] = useState<IntradayData | null>(null);
  const [news, setNews] = useState<NewsData | null>(null);
  const [analyst, setAnalyst] = useState<AnalystData | null>(null);
  const [rsi, setRsi] = useState<RSIData | null>(null);
  const [insight, setInsight] = useState<InsightData | null>(null);
  const [portfolio, setPortfolio] = useState<PortfolioConfig | null>(null);
  const [loading, setLoading] = useState(true);

  const market = quotes[ticker] ?? null;

  useEffect(() => {
    let cancelled = false;

    const load = () => {
      Promise.all(TICKERS.map((t) => loadMarketData(t))).then((results) => {
        if (cancelled) return;
        const next: Record<string, MarketData | null> = {};
        TICKERS.forEach((t, i) => {
          next[t] = results[i];
        });
        setQuotes(next);
      });
    };

    load();
    const intervalId = window.setInterval(load, REFRESH_INTERVAL_MS);
    return () => {
      cancelled = true;
      window.clearInterval(intervalId);
    };
  }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);

    const load = () => {
      Promise.all([
        loadIntradayData(ticker),
        loadNewsData(ticker),
        loadAnalystData(ticker),
        loadRSIData(ticker),
        loadInsightData(ticker),
        loadPortfolioConfig(ticker),
      ]).then(([i, n, a, r, ins, p]) => {
        if (cancelled) return;
        setIntraday(i);
        setNews(n);
        setAnalyst(a);
        setRsi(r);
        setInsight(ins);
        setPortfolio(p);
        setLoading(false);
      });
    };

    load();
    const intervalId = window.setInterval(load, REFRESH_INTERVAL_MS);
    return () => {
      cancelled = true;
      window.clearInterval(intervalId);
    };
  }, [ticker]);

  async function handleSaveShares(shares: number | null): Promise<boolean> {
    const result = await savePortfolioConfig(ticker, shares);
    if (result === null) return false;
    setPortfolio(result);
    return true;
  }

  return (
    <div className="app">
      <header className="app-header">
        <div className="app-header-left">
          <span className="app-title">bobboTrade</span>
          <span className="app-ticker">{stock.ticker}</span>
        </div>
        <div className="ticker-switcher">
          {TICKERS.map((t) => {
            const config = getStockConfig(t);
            const quote = quotes[t];
            return (
              <button
                key={t}
                className={`ticker-toggle ${t === ticker ? "active" : ""}`}
                onClick={() => setTicker(t)}
              >
                <span className="app-stock-name">{config.name}</span>
                {quote && (
                  <span className={`app-quote ${quote.quote.change >= 0 ? "up" : "down"}`}>
                    ${quote.quote.price.toFixed(2)}
                    <span className="app-quote-change">
                      {quote.quote.change >= 0 ? "+" : ""}
                      {quote.quote.change.toFixed(2)} ({quote.quote.changePercent.toFixed(2)}%)
                    </span>
                  </span>
                )}
              </button>
            );
          })}
        </div>
      </header>

      <main className="dashboard">
        <section className="col col-news">
          <NewsColumn news={news} loading={loading} />
        </section>

        <section className="col col-chart">
          <ChartColumn market={market} intraday={intraday} loading={loading} ticker={stock.ticker} />
          <RSICard rsi={rsi} loading={loading} ticker={stock.ticker} />
        </section>

        <section className="col col-right">
          <AnalystConsensusCard analyst={analyst} loading={loading} />
          <PortfolioCard market={market} portfolio={portfolio} onSaveShares={handleSaveShares} />
          <TwoWeekMovementCard market={market} loading={loading} />
          <InsightCard insight={insight} ticker={stock.ticker} market={market} />
        </section>
      </main>
    </div>
  );
}
