import type { EnergyData, EnergyIndicatorDef } from "../types";
import { formatEnergyValue } from "../lib/format";
import InfoPopup from "./InfoPopup";

// Plain-language read of current conditions, built from whichever of the
// crude-price / refinery-utilization indicators are present.
function interpret(energy: EnergyData): string {
  const valueOf = (id: string) => energy.indicators.find((i) => i.id === id)?.value ?? null;
  const crude = valueOf("brent") ?? valueOf("wti");
  const utilization = valueOf("refinery_utilization");

  const parts: string[] = [];
  if (crude != null) {
    const level = crude < 70 ? "relatively cheap" : crude > 90 ? "expensive" : "middling";
    parts.push(`crude oil is around $${crude.toFixed(0)}/barrel (${level})`);
  }
  if (utilization != null) {
    const pace =
      utilization >= 90 ? "running hard" : utilization >= 85 ? "at a normal pace" : "running below normal";
    parts.push(`US refineries are ${pace}, at ${utilization.toFixed(0)}% of capacity`);
  }

  if (parts.length === 0) {
    return "Live energy-market prices aren't available right now. When they load, this will read the balance between crude costs and refined-product demand.";
  }

  let outlook: string;
  if (crude != null && crude < 75 && utilization != null && utilization >= 88) {
    outlook = "That combination — cheaper crude and busy refineries — is favorable for refining profits.";
  } else if ((crude != null && crude > 90) || (utilization != null && utilization < 83)) {
    outlook =
      "That leans unfavorable for refining profits — either crude is pricey or refineries are running light.";
  } else {
    outlook =
      "Conditions are roughly middle-of-the-road for refining profits — no strong tailwind or headwind from these numbers.";
  }

  const lead = parts.join(", and ");
  return `${lead.charAt(0).toUpperCase()}${lead.slice(1)}. ${outlook}`;
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
  return (
    <div className="card">
      <div className="card-title-row">
        <h2 className="card-title">Refinery &amp; Energy</h2>
        {energy && (
          <InfoPopup
            label="Refinery & Energy Prices"
            whatIsThis="These are key energy market prices that directly affect refinery profits. When crude is cheap and refined products are expensive, refineries make more money."
            rightNow={interpret(energy)}
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
