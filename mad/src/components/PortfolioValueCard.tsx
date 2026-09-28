import type { MarketData, PortfolioConfig } from "../types";
import { formatCurrency } from "../lib/format";

// Two greys, alternated by holding order. Label text color is chosen per
// shade so it stays readable on both.
const SLICE_STYLES = [
  { fill: "#9aa0a8", text: "#111418" },
  { fill: "#535963", text: "#f2f3f5" },
];

const CX = 50;
const CY = 50;
const R = 38;

function polar(angle: number, radius: number) {
  return [CX + radius * Math.sin(angle), CY - radius * Math.cos(angle)];
}

export default function PortfolioValueCard({
  tickers,
  quotes,
  portfolios,
}: {
  tickers: string[];
  quotes: Record<string, MarketData | null>;
  portfolios: Record<string, PortfolioConfig | null>;
}) {
  const holdings = tickers.map((t, i) => {
    const shares = portfolios[t]?.shares ?? null;
    const price = quotes[t]?.quote.price ?? null;
    const value = shares != null && price != null ? shares * price : null;
    return { ticker: t, value, style: SLICE_STYLES[i % SLICE_STYLES.length] };
  });

  const total = holdings.reduce((sum, h) => sum + (h.value ?? 0), 0);
  const hasData = total > 0;

  let cursor = 0;
  const slices = holdings
    .filter((h) => h.value != null && h.value > 0)
    .map((h) => {
      const fraction = (h.value as number) / total;
      const start = cursor * 2 * Math.PI;
      cursor += fraction;
      const end = cursor * 2 * Math.PI;
      const mid = (start + end) / 2;
      const [x1, y1] = polar(start, R);
      const [x2, y2] = polar(end, R);
      // Leader line: starts just inside the slice edge, angles outward,
      // then runs a short horizontal to the percentage label.
      const [ax, ay] = polar(mid, R - 3);
      const [bx, by] = polar(mid, R + 7);
      const right = bx >= CX;
      const cx3 = bx + (right ? 4 : -4);
      const leader = `${ax},${ay} ${bx},${by} ${cx3},${by}`;
      return {
        ...h,
        fraction,
        path: `M ${CX} ${CY} L ${x1} ${y1} A ${R} ${R} 0 ${fraction > 0.5 ? 1 : 0} 1 ${x2} ${y2} Z`,
        leader,
        tx: cx3 + (right ? 2 : -2),
        ty: by,
        anchor: (right ? "start" : "end") as "start" | "end",
        dotX: ax,
        dotY: ay,
      };
    });

  return (
    <div className="card portfolio-value-card">
      <h2 className="card-title">Portfolio Value</h2>
      {!hasData && <p className="empty-state">Enter share counts to see portfolio value.</p>}
      {hasData && (
        <div className="portfolio-value-body">
          <div className="portfolio-value-pie">
            <svg viewBox="-24 0 148 100" role="img" aria-label="Share of portfolio value by stock">
              {slices.map((s) =>
                s.fraction === 1 ? (
                  <circle key={s.ticker} cx={CX} cy={CY} r={R} fill={s.style.fill} />
                ) : (
                  <path key={s.ticker} d={s.path} fill={s.style.fill} stroke="#1a1e24" strokeWidth="1" />
                ),
              )}
              {slices.map((s) => (
                <g key={`${s.ticker}-label`}>
                  <polyline points={s.leader} fill="none" stroke="#a8acb1" strokeWidth="0.8" />
                  <circle cx={s.dotX} cy={s.dotY} r="1.2" fill="#a8acb1" />
                  <text x={s.tx} y={s.ty} textAnchor={s.anchor} dominantBaseline="central" fill="#bec2c7" fontSize="9" fontWeight="600">
                    {Math.round(s.fraction * 100)}%
                  </text>
                </g>
              ))}
            </svg>
          </div>
          <div className="portfolio-value-list">
            {holdings.map((h) => (
              <div className="portfolio-value-row" key={h.ticker}>
                <span className="portfolio-value-name">
                  <span className="portfolio-value-swatch" style={{ background: h.style.fill }} />
                  {h.ticker}
                </span>
                <strong>{h.value != null ? formatCurrency(h.value, 0) : "n/a"}</strong>
              </div>
            ))}
            <div className="portfolio-value-row portfolio-value-total">
              <span>Total</span>
              <strong>{formatCurrency(total, 0)}</strong>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
