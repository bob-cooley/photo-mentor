import { useEffect } from "react";
import type { NewsArticle } from "../types";
import { formatDate } from "../lib/format";

export default function ArticleModal({ article, onClose }: { article: NewsArticle; onClose: () => void }) {
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [onClose]);

  const paragraphs = (article.fullText || article.summary || "").split(/\n\s*\n/).filter(Boolean);

  return (
    <div className="article-modal-backdrop" onClick={onClose}>
      <div className="article-modal" onClick={(e) => e.stopPropagation()}>
        <div className="article-modal-header">
          <div className="article-modal-meta">
            <span className="article-modal-source">{article.source}</span>
            <span className="news-dot">·</span>
            <span>{formatDate(article.publishedAt)}</span>
          </div>
          <button className="article-modal-close" onClick={onClose} aria-label="Close">
            &#10005;
          </button>
        </div>
        <h2 className="article-modal-headline">{article.headline}</h2>
        <div className="article-modal-body">
          {paragraphs.map((p, i) => (
            <p key={i}>{p}</p>
          ))}
        </div>
        <a className="article-modal-source-link" href={article.url} target="_blank" rel="noopener noreferrer">
          Read the full story at {article.source} &rarr;
        </a>
      </div>
    </div>
  );
}
