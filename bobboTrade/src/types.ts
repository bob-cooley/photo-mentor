export interface EnergyIndicatorDef {
  id: string;
  label: string;
  eiaSeries?: string;
  derived?: boolean;
}

export interface StockConfig {
  ticker: string;
  name: string;
  sector: string;
  cik: string;
  irFeedUrl: string;
  dataSources: {
    market: string;
    energy: string;
    news: string;
    analyst: string;
  };
  energyIndicators: EnergyIndicatorDef[];
}

export interface PricePoint {
  time: string; // YYYY-MM-DD
  open: number;
  high: number;
  low: number;
  close: number;
  volume: number;
}

export interface MarketData {
  ticker: string;
  fetchedAt: string;
  source: string;
  quote: {
    price: number;
    change: number;
    changePercent: number;
    previousClose: number;
    marketCap?: number;
  };
  history: PricePoint[];
  twoWeekChangePercent: number;
}

export interface EnergyIndicatorValue {
  id: string;
  label: string;
  value: number | null;
  unit: string;
  asOf: string | null;
}

export interface EnergyData {
  ticker: string;
  fetchedAt: string;
  source: string;
  indicators: EnergyIndicatorValue[];
}

export interface NewsArticle {
  id: string;
  headline: string;
  summary: string;
  source: string;
  url: string;
  publishedAt: string;
  relevance: number; // 0-1
}

export interface NewsData {
  ticker: string;
  fetchedAt: string;
  articles: NewsArticle[];
}

export type AnalystRating = "BUY" | "HOLD" | "SELL";

export interface AnalystData {
  ticker: string;
  fetchedAt: string;
  source: string;
  consensus: AnalystRating;
  counts: {
    buy: number;
    hold: number;
    sell: number;
  };
  priceTarget: {
    average: number | null;
    high: number | null;
    low: number | null;
  };
}

export interface PortfolioConfig {
  shares: number;
  costBasis?: number;
}
