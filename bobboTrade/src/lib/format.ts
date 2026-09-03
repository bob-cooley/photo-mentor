export function formatCurrency(value: number, digits = 2): string {
  return value.toLocaleString("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  });
}

export function formatPercent(value: number, digits = 2): string {
  const sign = value > 0 ? "+" : "";
  return `${sign}${value.toFixed(digits)}%`;
}

export function formatEnergyValue(value: number, unit: string): string {
  switch (unit) {
    case "usd_per_barrel":
      return `$${value.toFixed(2)} per barrel`;
    case "percent":
      return `${value.toFixed(1)}%`;
    case "million_barrels":
      return `${value.toFixed(1)} million barrels`;
    default:
      return `${value.toLocaleString()} ${unit}`;
  }
}

export function formatCompactNumber(value: number): string {
  return new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 1 }).format(value);
}

export function formatDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
}

// A bare calendar date ("2026-09-10") formatted as local time. Using
// `new Date("2026-09-10")` would parse it as UTC midnight and render as
// the day before in any US timezone — fine for a fuzzy "2d ago" but not
// for an exact dividend payment date.
export function formatYmd(ymd: string): string {
  const [y, m, d] = ymd.split("-").map(Number);
  if (!y || !m || !d) return ymd;
  return new Date(y, m - 1, d).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
}

export function timeAgo(iso: string): string {
  const d = new Date(iso).getTime();
  if (Number.isNaN(d)) return "";
  const diffMs = Date.now() - d;
  const hours = Math.floor(diffMs / (1000 * 60 * 60));
  if (hours < 1) return "just now";
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `${days}d ago`;
  return formatDate(iso);
}
