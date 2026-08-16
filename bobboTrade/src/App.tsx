import { useEffect, useState } from "react";
import { DEFAULT_TICKER, getStockConfig } from "./config/stocks";
import {
  loadAnalystData,
  loadEnergyData,
  loadMarketData,
  loadNewsData,
  loadPortfolioConfig,
} from "./lib/dataLoader";
import type { AnalystData, EnergyData, MarketData, NewsData, PortfolioConfig } from "./types";
import NewsColumn from "./components/NewsColumn";
import ChartColumn from "./components/ChartColumn";
import AnalystConsensusCard from "./components/AnalystConsensusCard";
import TwoWeekMovementCard from "./components/TwoWeekMovementCard";
import AIInsightPlaceholder from "./components/AIInsightPlaceholder";
import PortfolioCard from "./components/PortfolioCard";
import EnergyIndicatorsCard from "./components/EnergyIndicatorsCard";
import "./App.css";

export default function App() {
  const ticker = DEFAULT_TICKER;
  const stock = getStockConfig(ticker);

  const [market, setMarket] = useState<MarketData | null>(null);
  const [energy, setEnergy] = useState<EnergyData | null>(null);
  const [news, setNews] = useState<NewsData | null>(null);
  const [analyst, setAnalyst] = useState<AnalystData | null>(null);
  const [portfolio, setPortfolio] = useState<PortfolioConfig | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      loadMarketData(ticker),
      loadEnergyData(ticker),
      loadNewsData(ticker),
      loadAnalystData(ticker),
      loadPortfolioConfig(),
    ]).then(([m, e, n, a, p]) => {
      if (cancelled) return;
      setMarket(m);
      setEnergy(e);
      setNews(n);
      setAnalyst(a);
      setPortfolio(p);
      setLoading(false);
    });
    return () => {
      cancelled = true;
    };
  }, [ticker]);

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
          <ChartColumn market={market} loading={loading} ticker={stock.ticker} />
        </section>

        <section className="col col-right">
          <AnalystConsensusCard analyst={analyst} loading={loading} />
          <TwoWeekMovementCard market={market} loading={loading} />
          <EnergyIndicatorsCard energy={energy} indicatorDefs={stock.energyIndicators} loading={loading} />
          <PortfolioCard market={market} portfolio={portfolio} />
          <AIInsightPlaceholder />
        </section>
      </main>
    </div>
  );
}
