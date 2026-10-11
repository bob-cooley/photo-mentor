import type { MarketData, PortfolioConfig } from "../types";

export type MovementDirection = "up" | "down" | "";

// Whole-portfolio value and its 2-week direction. The direction uses the
// same definition the 2-Week Movement card applies per stock: the price
// two weeks ago is implied by today's price and the stock's two-week
// percent change, and each holding's earlier value is shares x that price.
// Shared by the header callout and the Portfolio Value card so the two can
// never disagree.
export function portfolioTotals(
  tickers: string[],
  quotes: Record<string, MarketData | null>,
  portfolios: Record<string, PortfolioConfig | null>,
): { total: number; direction: MovementDirection } {
  let total = 0;
  let thenTotal = 0;
  let anyMovement = false;

  for (const t of tickers) {
    const shares = portfolios[t]?.shares ?? null;
    const price = quotes[t]?.quote.price ?? null;
    if (shares == null || price == null) continue;

    const now = shares * price;
    total += now;

    const pct = quotes[t]?.twoWeekChangePercent ?? null;
    if (pct != null) {
      thenTotal += now / (1 + pct / 100);
      anyMovement = true;
    } else {
      thenTotal += now;
    }
  }

  const direction: MovementDirection = !anyMovement ? "" : total >= thenTotal ? "up" : "down";
  return { total, direction };
}
