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

export interface IntradayBar {
  time: number; // Unix seconds (UTC)
  open: number;
  high: number;
  low: number;
  close: number;
  volume: number;
}

export interface IntradayData {
  ticker: string;
  fetchedAt: string;
  source: string;
  interval: string;
  bars: IntradayBar[];
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
  fullText: string | null;
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
  // Prior monthly recommendation snapshots, newest first (up to 3
  // months back). Absent on older data files and the local mock's
  // earlier shape, so always guard before reading.
  history?: Array<{
    period: string; // "2026-08"
    buy: number;
    hold: number;
    sell: number;
    consensus: string;
  }>;
  priceTarget: {
    average: number | null;
    high: number | null;
    low: number | null;
  };
}

export type RSIZone = "overbought" | "neutral" | "oversold";

export interface RSIData {
  ticker: string;
  fetchedAt: string;
  source: string;
  period: number; // 14
  interval: string; // "1day"
  asOf: string | null;
  rsi: number; // 0-100
  zone: RSIZone;
}

export interface PortfolioConfig {
  shares: number | null;
  updatedAt: string | null;
}

export interface AIUsageSummary {
  month: string; // YYYY-MM
  callsThisMonth: number;
  inputTokens: number;
  outputTokens: number;
  estimatedCostUsd: number;
}

export interface InsightData {
  ticker: string;
  fetchedAt: string;
  text: string | null;
  status: "ok" | "paused_budget";
  usage: AIUsageSummary;
}
