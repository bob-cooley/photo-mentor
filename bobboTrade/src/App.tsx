import { useEffect, useState } from "react";
import { DEFAULT_TICKER, getStockConfig } from "./config/stocks";
import {
  loadAnalystData,
  loadEnergyData,
  loadInsightData,
  loadIntradayData,
  loadMarketData,
  loadNewsData,
  loadPortfolioConfig,
  savePortfolioConfig,
} from "./lib/dataLoader";
import type {
  AnalystData,
  EnergyData,
  InsightData,
  IntradayData,
  MarketData,
  NewsData,
  PortfolioConfig,
} from "./types";
import NewsColumn from "./components/NewsColumn";
import ChartColumn from "./components/ChartColumn";
import AnalystConsensusCard from "./components/AnalystConsensusCard";
import TwoWeekMovementCard from "./components/TwoWeekMovementCard";
import InsightCard from "./components/InsightCard";
import PortfolioCard from "./components/PortfolioCard";
import EnergyIndicatorsCard from "./components/EnergyIndicatorsCard";
import "./App.css";

// The data pipeline itself only refreshes every 5-60 min (see
// data/fetch/), so polling the static JSON more often than that just
// re-fetches the same file — this cadence keeps an open tab reasonably
// current without hammering the host for no reason.
const REFRESH_INTERVAL_MS = 2 * 60 * 1000;

export default function App() {
  const ticker = DEFAULT_TICKER;
  const stock = getStockConfig(ticker);

  const [market, setMarket] = useState<MarketData | null>(null);
  const [intraday, setIntraday] = useState<IntradayData | null>(null);
  const [energy, setEnergy] = useState<EnergyData | null>(null);
  const [news, setNews] = useState<NewsData | null>(null);
  const [analyst, setAnalyst] = useState<AnalystData | null>(null);
  const [insight, setInsight] = useState<InsightData | null>(null);
  const [portfolio, setPortfolio] = useState<PortfolioConfig | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    const load = () => {
      Promise.all([
        loadMarketData(ticker),
        loadIntradayData(ticker),
        loadEnergyData(ticker),
        loadNewsData(ticker),
        loadAnalystData(ticker),
        loadInsightData(ticker),
        loadPortfolioConfig(),
      ]).then(([m, i, e, n, a, ins, p]) => {
        if (cancelled) return;
        setMarket(m);
        setIntraday(i);
        setEnergy(e);
        setNews(n);
        setAnalyst(a);
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
    const result = await savePortfolioConfig(shares);
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
        <div className="app-header-right">
          <span className="app-stock-name">{stock.name}</span>
          {market && (
            <span className={`app-quote ${market.quote.change >= 0 ? "up" : "down"}`}>
              ${market.quote.price.toFixed(2)}
              <span className="app-quote-change">
                {market.quote.change >= 0 ? "+" : ""}
                {market.quote.change.toFixed(2)} ({market.quote.changePercent.toFixed(2)}%)
              </span>
            </span>
          )}
        </div>
      </header>

      <main className="dashboard">
        <section className="col col-news">
          <NewsColumn news={news} loading={loading} />
        </section>

        <section className="col col-chart">
          <ChartColumn market={market} intraday={intraday} loading={loading} ticker={stock.ticker} />
        </section>

        <section className="col col-right">
          <AnalystConsensusCard analyst={analyst} loading={loading} />
          <TwoWeekMovementCard market={market} loading={loading} />
          <EnergyIndicatorsCard energy={energy} indicatorDefs={stock.energyIndicators} loading={loading} />
          <PortfolioCard market={market} portfolio={portfolio} onSaveShares={handleSaveShares} />
          <InsightCard insight={insight} ticker={stock.ticker} market={market} />
        </section>
      </main>
    </div>
  );
}
