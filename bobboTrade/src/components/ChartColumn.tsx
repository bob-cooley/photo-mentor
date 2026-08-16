import { useEffect, useRef, useState } from "react";
import { createChart, ColorType, type IChartApi, type ISeriesApi } from "lightweight-charts";
import type { MarketData } from "../types";

type Timeframe = "1M" | "3M" | "6M" | "1Y" | "5Y";

const TIMEFRAME_DAYS: Record<Timeframe, number> = {
  "1M": 30,
  "3M": 90,
  "6M": 182,
  "1Y": 365,
  "5Y": 365 * 5,
};

export default function ChartColumn({
  market,
  loading,
  ticker,
}: {
  market: MarketData | null;
  loading: boolean;
  ticker: string;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const chartRef = useRef<IChartApi | null>(null);
  const seriesRef = useRef<ISeriesApi<"Candlestick"> | null>(null);
  const volumeSeriesRef = useRef<ISeriesApi<"Histogram"> | null>(null);
  const [timeframe, setTimeframe] = useState<Timeframe>("1Y");

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
    if (!market || !seriesRef.current || !volumeSeriesRef.current) return;
    const days = TIMEFRAME_DAYS[timeframe];
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
    chartRef.current?.timeScale().fitContent();
  }, [market, timeframe]);

  return (
    <div className="card chart-card">
      <div className="chart-header">
        <h2 className="card-title" style={{ margin: 0 }}>
          {ticker} Price
        </h2>
        <div className="timeframe-picker">
          {(Object.keys(TIMEFRAME_DAYS) as Timeframe[]).map((tf) => (
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
