import type { NewsData } from "../types";
import { timeAgo } from "../lib/format";

export default function NewsColumn({ news, loading }: { news: NewsData | null; loading: boolean }) {
  return (
    <div className="card news-card">
      <h2 className="card-title">Company News</h2>
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
          {news.articles.map((article) => (
            <a key={article.id} className="news-item" href={article.url} target="_blank" rel="noreferrer">
              <div className="news-headline">{article.headline}</div>
              {article.summary && <div className="news-summary">{article.summary}</div>}
              <div className="news-meta">
                <span className="news-source">{article.source}</span>
                <span className="news-dot">·</span>
                <span>{timeAgo(article.publishedAt)}</span>
              </div>
            </a>
          ))}
        </div>
      )}
    </div>
  );
}
