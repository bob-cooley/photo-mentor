import mpcConfig from "./stocks/MPC/config.json";
import copConfig from "./stocks/COP/config.json";
import type { StockConfig } from "../types";

// Registry of tracked tickers. Add a new stocks/<TICKER>/config.json and
// list it here to bring a new stock onto the dashboard.
export const STOCKS: Record<string, StockConfig> = {
  MPC: mpcConfig as StockConfig,
  COP: copConfig as StockConfig,
};

export const DEFAULT_TICKER = "MPC";

export function getStockConfig(ticker: string): StockConfig {
  const config = STOCKS[ticker];
  if (!config) {
    throw new Error(`No configuration found for ticker "${ticker}"`);
  }
  return config;
}
