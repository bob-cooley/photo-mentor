import type { EnergyData, EnergyIndicatorDef } from "../types";
import { formatEnergyValue } from "../lib/format";
import InfoPopup from "./InfoPopup";

// Plain-language read of current conditions, built from whichever of the
// crude-price / refinery-utilization indicators are present.
function interpret(energy: EnergyData): { rightNow: string; bottomLine: string } {
  const valueOf = (id: string) => energy.indicators.find((i) => i.id === id)?.value ?? null;
  const crude = valueOf("brent") ?? valueOf("wti");
  const utilization = valueOf("refinery_utilization");

  if (crude == null && utilization == null) {
    return {
      rightNow: "The live energy prices haven't loaded yet.",
      bottomLine:
        "Once they load, this will tell you plainly whether conditions favor MPC's profits or work against them.",
    };
  }

  const sentences: string[] = [];
  if (crude != null) {
    const desc =
      crude < 70 ? "cheap right now" : crude > 90 ? "expensive right now" : "around its normal price";
    sentences.push(
      `Crude oil — the raw material refineries buy — is ${desc}, at about $${crude.toFixed(0)} a barrel.`
    );
  }
  if (utilization != null) {
    const pct = `${utilization.toFixed(0)}% of what they could produce`;
    const desc =
      utilization >= 90
        ? `running near full capacity, at ${pct} — usually a sign of strong demand for fuel`
        : utilization >= 85
          ? `running at a normal pace, at ${pct}`
          : `running lighter than usual, at ${pct} — which often points to softer demand for fuel`;
    sentences.push(`Refineries across the country are ${desc}.`);
  }

  let bottomLine: string;
  if (crude != null && crude < 75 && utilization != null && utilization >= 88) {
    bottomLine = "Cheap oil plus busy refineries is a favorable mix for MPC's profits.";
  } else if ((crude != null && crude > 90) || (utilization != null && utilization < 83)) {
    bottomLine =
      "An unfavorable mix for MPC's profits — either the raw material costs too much or demand for fuel is soft.";
  } else {
    bottomLine =
      "Conditions are middle-of-the-road for MPC's profits right now — nothing helping or hurting much.";
  }

  return { rightNow: sentences.join(" "), bottomLine };
}

export default function EnergyIndicatorsCard({
  energy,
  indicatorDefs,
  loading,
}: {
  energy: EnergyData | null;
  indicatorDefs: EnergyIndicatorDef[];
  loading: boolean;
}) {
  const explain = energy ? interpret(energy) : null;

  return (
    <div className="card">
      <div className="card-title-row">
        <h2 className="card-title">Refinery &amp; Energy</h2>
        {explain && (
          <InfoPopup
            label="Refinery & Energy Prices"
            whatIsThis="These are key energy market prices that directly affect refinery profits. When crude is cheap and refined products are expensive, refineries make more money."
            rightNow={explain.rightNow}
            bottomLine={explain.bottomLine}
          />
        )}
      </div>
      {loading && <div className="skeleton" style={{ height: 120 }} />}
      {!loading && !energy && <p className="empty-state">No energy data available.</p>}
      {!loading && energy && (
        <div className="energy-list">
          {indicatorDefs.map((def) => {
            const value = energy.indicators.find((i) => i.id === def.id);
            return (
              <div key={def.id} className="energy-row">
                <span className="energy-label">{def.label}</span>
                <span className="energy-value">
                  {value?.value != null ? formatEnergyValue(value.value, value.unit) : "—"}
                </span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
