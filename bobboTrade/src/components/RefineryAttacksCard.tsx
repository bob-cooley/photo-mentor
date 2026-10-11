import { useEffect, useState } from "react";
import type { AttackAttribution, RefineryAttack, RefineryAttacksData } from "../types";
import { formatYmd } from "../lib/format";
import InfoPopup from "./InfoPopup";

const PREVIEW_ROWS = 8;
const RECENT_DAYS = 30;

const ATTRIBUTION_LABEL: Record<AttackAttribution, string> = {
  claimed: "claimed",
  reported: "reported",
  disputed: "disputed",
  unattributed: "",
};

function Nations({ event }: { event: RefineryAttack }) {
  const label = ATTRIBUTION_LABEL[event.attribution];
  return (
    <span className="attack-nations">
      <span className={event.attacker ? "" : "attack-unknown"}>{event.attacker ?? "Unattributed"}</span>
      <span className="attack-arrow" aria-label="attacked">
        &rarr;
      </span>
      <span>{event.attackedNation}</span>
      {label && <span className="attack-attribution">{label}</span>}
    </span>
  );
}

function Source({ event }: { event: RefineryAttack }) {
  const primary = event.sources[0];
  if (!primary) return <span>&mdash;</span>;
  const extra = event.sources.length - 1;
  return (
    <span className="attack-source">
      <a href={primary.url} target="_blank" rel="noopener noreferrer" title={primary.via ? `via ${primary.via}` : undefined}>
        {primary.name}
      </a>
      {extra > 0 && <span className="attack-source-more">+{extra}</span>}
    </span>
  );
}

function AttackTable({ events }: { events: RefineryAttack[] }) {
  return (
    <table className="attack-table">
      <thead>
        <tr>
          <th>Nations</th>
          <th>Date</th>
          <th>Facility</th>
          <th>Source</th>
        </tr>
      </thead>
      <tbody>
        {events.map((e) => (
          <tr key={e.id}>
            <td>
              <Nations event={e} />
            </td>
            <td className="attack-date">{formatYmd(e.date)}</td>
            <td>{e.facility}</td>
            <td>
              <Source event={e} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function AllAttacksModal({ events, onClose }: { events: RefineryAttack[]; onClose: () => void }) {
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [onClose]);

  return (
    <div className="article-modal-backdrop" onClick={onClose}>
      <div className="article-modal attack-modal" onClick={(e) => e.stopPropagation()}>
        <div className="article-modal-header">
          <div className="article-modal-meta">
            <span className="article-modal-source">Refinery attacks</span>
            <span className="news-dot">·</span>
            <span>{events.length} reported</span>
          </div>
          <button className="article-modal-close" onClick={onClose} aria-label="Close">
            &#10005;
          </button>
        </div>
        <div className="article-modal-body">
          <AttackTable events={events} />
          <div className="attack-all-sources">
            {events
              .filter((e) => e.sources.length > 1)
              .map((e) => (
                <p key={e.id}>
                  <strong>
                    {e.facility}, {formatYmd(e.date)}:
                  </strong>{" "}
                  {e.sources.map((s, i) => (
                    <span key={s.url}>
                      {i > 0 && ", "}
                      <a href={s.url} target="_blank" rel="noopener noreferrer">
                        {s.name}
                      </a>
                    </span>
                  ))}
                </p>
              ))}
          </div>
        </div>
      </div>
    </div>
  );
}

export default function RefineryAttacksCard({
  data,
  loading,
}: {
  data: RefineryAttacksData | null;
  loading: boolean;
}) {
  const [allOpen, setAllOpen] = useState(false);
  const events = data?.events ?? [];

  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - RECENT_DAYS);
  const cutoffYmd = cutoff.toISOString().slice(0, 10);
  const recent = events.filter((e) => e.date >= cutoffYmd);
  const latest = events[0];

  return (
    <div className="card attacks-card">
      <div className="card-title-row">
        <h2 className="card-title">Refinery Attacks</h2>
        <InfoPopup
          label="Refinery Attacks"
          whatIsThis="A running list of reported physical attacks on oil refineries anywhere in the world. Each row gives the nations involved (attacker, then the country attacked), the date, the facility, and a link to the news source. The attacker is shown only as the cited source reports it, and many attacks are never officially claimed, which is why each row is labeled claimed, reported, or disputed."
          rightNow={
            events.length === 0
              ? "No attacks are on the list right now."
              : recent.length === 0
                ? `No attacks were reported in the last ${RECENT_DAYS} days. The most recent on the list was ${latest.facility}, ${latest.attackedNation}, on ${formatYmd(latest.date)}.`
                : `${recent.length} attack${recent.length === 1 ? " was" : "s were"} reported in the last ${RECENT_DAYS} days. The most recent was ${latest.facility}, ${latest.attackedNation}, on ${formatYmd(latest.date)}.`
          }
          bottomLine="An attack takes a refinery's fuel-making capacity off the market while it is down, which can raise gasoline and diesel prices and the refining margin. One strike seldom matters on its own. A sustained pattern that keeps plants offline for weeks is what shows up in prices."
        />
      </div>

      {loading && <div className="skeleton" style={{ height: 160 }} />}
      {!loading && events.length === 0 && <p className="empty-state">No refinery attacks on the list.</p>}

      {!loading && events.length > 0 && (
        <>
          <AttackTable events={events.slice(0, PREVIEW_ROWS)} />
          {events.length > PREVIEW_ROWS && (
            <button className="dividend-history-btn" onClick={() => setAllOpen(true)}>
              Show all {events.length}
            </button>
          )}
        </>
      )}

      {allOpen && <AllAttacksModal events={events} onClose={() => setAllOpen(false)} />}
    </div>
  );
}
