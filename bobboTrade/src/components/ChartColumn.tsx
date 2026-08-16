import { useEffect, useRef, useState } from "react";
import { createChart, ColorType, type IChartApi, type ISeriesApi, type UTCTimestamp } from "lightweight-charts";
import type { IntradayData, MarketData } from "../types";

type Timeframe = "1D" | "1W" | "1M" | "3M" | "6M" | "1Y" | "5Y";

const TIMEFRAMES: Timeframe[] = ["1D", "1W", "1M", "3M", "6M", "1Y", "5Y"];
const INTRADAY_TIMEFRAMES = new Set<Timeframe>(["1D", "1W"]);
const DAILY_TIMEFRAME_DAYS: Partial<Record<Timeframe, number>> = {
  "1M": 30,
  "3M": 90,
  "6M": 182,
  "1Y": 365,
  "5Y": 365 * 5,
};

// 5-minute bars during a ~6.5hr NYSE session, times over to cover 1W
// even on a short/holiday-adjacent week.
const ONE_WEEK_BAR_COUNT = 78 * 5;

function nyLocalDateKey(unixSeconds: number): string {
  return new Date(unixSeconds * 1000).toLocaleDateString("en-CA", { timeZone: "America/New_York" });
}

export default function ChartColumn({
  market,
  intraday,
  loading,
  ticker,
}: {
  market: MarketData | null;
  intraday: IntradayData | null;
  loading: boolean;
  ticker: string;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const chartRef = useRef<IChartApi | null>(null);
  const seriesRef = useRef<ISeriesApi<"Candlestick"> | null>(null);
  const volumeSeriesRef = useRef<ISeriesApi<"Histogram"> | null>(null);
  const [timeframe, setTimeframe] = useState<Timeframe>("1D");

  useEffect(() => {
    if (!containerRef.current) return;

    const chart = createChart(containerRef.current, {
      layout: {
        background: { type: ColorType.Solid, color: "transparent" },
        textColor: "#9a9fa6",
        fontFamily: "Inter, -apple-system, sans-serif",
      },
      grid: {
        vertLines: { color: "rgba(255,255,255,0.04)" },
        horzLines: { color: "rgba(255,255,255,0.04)" },
      },
      rightPriceScale: { borderColor: "rgba(255,255,255,0.08)" },
      timeScale: { borderColor: "rgba(255,255,255,0.08)" },
      crosshair: { mode: 0 },
      autoSize: true,
    });

    const series = chart.addCandlestickSeries({
      upColor: "#34c759",
      downColor: "#ff453a",
      borderVisible: false,
      wickUpColor: "#34c759",
      wickDownColor: "#ff453a",
    });

    const volumeSeries = chart.addHistogramSeries({
      priceFormat: { type: "volume" },
      priceScaleId: "volume",
      color: "rgba(120,140,180,0.35)",
    });
    chart.priceScale("volume").applyOptions({
      scaleMargins: { top: 0.82, bottom: 0 },
    });

    chartRef.current = chart;
    seriesRef.current = series;
    volumeSeriesRef.current = volumeSeries;

    return () => {
      chart.remove();
      chartRef.current = null;
    };
  }, []);

  useEffect(() => {
    if (!seriesRef.current || !volumeSeriesRef.current) return;

    if (INTRADAY_TIMEFRAMES.has(timeframe)) {
      if (!intraday || intraday.bars.length === 0) return;
      const bars = intraday.bars;
      const sliced =
        timeframe === "1D"
          ? (() => {
              const latestDay = nyLocalDateKey(bars[bars.length - 1].time);
              return bars.filter((b) => nyLocalDateKey(b.time) === latestDay);
            })()
          : bars.slice(-ONE_WEEK_BAR_COUNT);

      seriesRef.current.setData(
        sliced.map((b) => ({
          time: b.time as UTCTimestamp,
          open: b.open,
          high: b.high,
          low: b.low,
          close: b.close,
        })),
      );
      volumeSeriesRef.current.setData(
        sliced.map((b) => ({
          time: b.time as UTCTimestamp,
          value: b.volume,
          color: b.close >= b.open ? "rgba(52,199,89,0.35)" : "rgba(255,69,58,0.35)",
        })),
      );
    } else {
      if (!market) return;
      const days = DAILY_TIMEFRAME_DAYS[timeframe] ?? 365;
      const sliced = market.history.slice(-days);

      seriesRef.current.setData(
        sliced.map((p) => ({ time: p.time, open: p.open, high: p.high, low: p.low, close: p.close })),
      );
      volumeSeriesRef.current.setData(
        sliced.map((p) => ({
          time: p.time,
          value: p.volume,
          color: p.close >= p.open ? "rgba(52,199,89,0.35)" : "rgba(255,69,58,0.35)",
        })),
      );
    }
    chartRef.current?.timeScale().fitContent();
  }, [market, intraday, timeframe]);

  return (
    <div className="card chart-card">
      <div className="chart-header">
        <h2 className="card-title" style={{ margin: 0 }}>
          {ticker} Price
        </h2>
        <div className="timeframe-picker">
          {TIMEFRAMES.map((tf) => (
            <button
              key={tf}
              className={`timeframe-btn ${timeframe === tf ? "active" : ""}`}
              onClick={() => setTimeframe(tf)}
            >
              {tf}
            </button>
          ))}
        </div>
      </div>
      <div className="chart-container" ref={containerRef}>
        {loading && <div className="skeleton" style={{ position: "absolute", inset: 0 }} />}
      </div>
    </div>
  );
}
