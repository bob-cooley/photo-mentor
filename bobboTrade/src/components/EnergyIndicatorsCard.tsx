import type { EnergyData, EnergyIndicatorDef } from "../types";
import { formatEnergyValue } from "../lib/format";

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
      <h2 className="card-title">Refinery &amp; Energy</h2>
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
