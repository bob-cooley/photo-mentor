import type { VolumeData } from "../types";
import { formatCompactNumber } from "../lib/format";
import InfoPopup from "./InfoPopup";

const CLASS_META: Record<string, { label: string; className: string }> = {
  high: { label: "High", className: "volume-class-high" },
  normal: { label: "Normal", className: "volume-class-normal" },
  low: { label: "Low", className: "volume-class-low" },
};

function interpret(d: VolumeData, ticker: string): { rightNow: string; bottomLine: string } {
  const vol = formatCompactNumber(d.volume);
  const ratio = d.ratio.toFixed(1);

  if (d.classification === "high") {
    return {
      rightNow:
        `Today about ${vol} shares of ${ticker} changed hands — roughly ${ratio} times the usual daily amount. ` +
        `That's unusually heavy trading. It often means big news landed, or large investors are moving in or out in size.`,
      bottomLine:
        `Heavy volume on a down day is a stronger sell signal; heavy volume on an up day is a stronger buy signal. On its own, it's worth noting but not acting on.`,
    };
  }
  if (d.classification === "low") {
    return {
      rightNow:
        `Today about ${vol} shares of ${ticker} changed hands — only about ${ratio} times the usual daily amount. ` +
        `That's light trading, which usually just means a quiet day with no major news.`,
      bottomLine:
        `Not much to read into here — price moves on light volume tend to carry less weight.`,
    };
  }
  return {
    rightNow:
      `Today about ${vol} shares of ${ticker} changed hands — about ${ratio} times the usual daily amount, which is a normal day's trading.`,
    bottomLine: `Nothing unusual in the trading activity today.`,
  };
}

export default function VolumeCard({
  volume,
  loading,
  ticker,
}: {
  volume: VolumeData | null;
  loading: boolean;
  ticker: string;
}) {
  const meta = CLASS_META[volume?.classification ?? "normal"] ?? CLASS_META.normal;
  const explain = volume ? interpret(volume, ticker) : null;

  return (
    <div className="card volume-card">
      <div className="card-title-row">
        <h2 className="card-title">Trading Volume</h2>
        {explain && (
          <InfoPopup
            label="Trading Volume"
            whatIsThis="Volume is how many shares of the stock were bought and sold today. On its own it doesn't tell you which direction the stock is heading — but unusually high volume often means something significant is happening, like big news or institutions making large moves."
            rightNow={explain.rightNow}
            bottomLine={explain.bottomLine}
          />
        )}
      </div>

      {loading && <div className="skeleton" style={{ height: 96 }} />}
      {!loading && !volume && <p className="empty-state">No volume data available.</p>}
      {!loading && volume && (
        <div className="volume-body">
          <div className="volume-big">
            {formatCompactNumber(volume.volume)}
            <span className="volume-unit"> shares</span>
          </div>
          <div className="volume-sub">vs {formatCompactNumber(volume.avgVolume)} on an average day (20-day)</div>
          <div className="volume-ratio">{volume.ratio.toFixed(1)}× avg</div>
          <div className={`volume-class ${meta.className}`}>{meta.label}</div>
        </div>
      )}
    </div>
  );
}
