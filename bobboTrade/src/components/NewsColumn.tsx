import { useState } from "react";
import type { NewsArticle, NewsData } from "../types";
import { timeAgo } from "../lib/format";
import ArticleModal from "./ArticleModal";

export default function NewsColumn({ news, loading }: { news: NewsData | null; loading: boolean }) {
  const [selected, setSelected] = useState<NewsArticle | null>(null);

  return (
    <div className="card news-card">
      <h2 className="card-title">Market News</h2>
      {loading && (
        <div className="news-list">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="news-item">
              <div className="skeleton" style={{ height: 18, width: "90%", marginBottom: 8 }} />
              <div className="skeleton" style={{ height: 13, width: "100%", marginBottom: 6 }} />
              <div className="skeleton" style={{ height: 11, width: "40%" }} />
            </div>
          ))}
        </div>
      )}
      {!loading && (!news || news.articles.length === 0) && (
        <p className="empty-state">No recent news right now.</p>
      )}
      {!loading && news && news.articles.length > 0 && (
        <div className="news-list">
          {news.articles.map((article) =>
            article.fullText ? (
              <button key={article.id} className="news-item news-item-button" onClick={() => setSelected(article)}>
                <div className="news-headline">{article.headline}</div>
                {article.summary && <div className="news-summary">{article.summary}</div>}
                <div className="news-meta">
                  <span className="news-source">{article.source}</span>
                  <span className="news-dot">·</span>
                  <span>{timeAgo(article.publishedAt)}</span>
                </div>
              </button>
            ) : (
              <a key={article.id} className="news-item" href={article.url} target="_blank" rel="noreferrer">
                <div className="news-headline">{article.headline}</div>
                {article.summary && <div className="news-summary">{article.summary}</div>}
                <div className="news-meta">
                  <span className="news-source">{article.source}</span>
                  <span className="news-dot">·</span>
                  <span>{timeAgo(article.publishedAt)}</span>
                </div>
              </a>
            )
          )}
        </div>
      )}
      {selected && <ArticleModal article={selected} onClose={() => setSelected(null)} />}
    </div>
  );
}
