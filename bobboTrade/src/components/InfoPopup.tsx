import { useEffect, useState, type ReactNode } from "react";

// Small inline ⓘ button that opens a centered explainer modal. Up to
// three labelled sections: a general "what is this" (same every time), a
// value-specific "what does this mean right now", and a one-line
// "bottom line" takeaway — the last two derived from the current
// reading by the caller. Closes on backdrop click, the X button, or
// Escape. Modal styling mirrors .article-modal in App.css.
export default function InfoPopup({
  label,
  whatIsThis,
  rightNow,
  bottomLine,
}: {
  label: string;
  whatIsThis: ReactNode;
  rightNow: ReactNode;
  bottomLine?: ReactNode;
}) {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!open) return;
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [open]);

  return (
    <>
      <button
        type="button"
        className="info-popup-trigger"
        aria-label={`About ${label}`}
        onClick={() => setOpen(true)}
      >
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
        >
          <circle cx="12" cy="12" r="10" />
          <line x1="12" y1="16" x2="12" y2="12" />
          <line x1="12" y1="8" x2="12.01" y2="8" />
        </svg>
      </button>
      {open && (
        <div className="info-popup-backdrop" onClick={() => setOpen(false)}>
          <div
            className="info-popup"
            role="dialog"
            aria-modal="true"
            aria-label={label}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="info-popup-header">
              <h3 className="info-popup-title">{label}</h3>
              <button className="info-popup-close" onClick={() => setOpen(false)} aria-label="Close">
                &#10005;
              </button>
            </div>
            <div className="info-popup-section">
              <div className="info-popup-section-label">What is this?</div>
              <p>{whatIsThis}</p>
            </div>
            <div className="info-popup-section">
              <div className="info-popup-section-label">What does this mean right now?</div>
              <p>{rightNow}</p>
            </div>
            {bottomLine != null && bottomLine !== "" && (
              <div className="info-popup-section">
                <div className="info-popup-section-label">Bottom line</div>
                <p>{bottomLine}</p>
              </div>
            )}
          </div>
        </div>
      )}
    </>
  );
}
