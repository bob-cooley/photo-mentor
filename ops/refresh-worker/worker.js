// Cloudflare Worker: tells GitHub to run the bobboTrade data refresh every
// 5 minutes during US market hours.
//
// Why this exists: the workflow's own `*/5` schedule is best-effort on
// GitHub's side and was observed running about once during an entire
// trading session, which left the price showing yesterday's close.
// Cloudflare's cron triggers fire on time, so this calls the workflow
// through GitHub's API instead.
//
// Setup: see README.md in this folder. Needs one secret named GITHUB_TOKEN
// (a fine-grained token with Actions: Read and write on this repo only).
//
// There is intentionally no fetch() handler: nothing on the public
// internet can trigger this worker, only its cron schedule.

const DISPATCH_URL =
  "https://api.github.com/repos/bob-cooley/photo-mentor/actions/workflows/deploy.yml/dispatches";

export default {
  async scheduled(_event, env, ctx) {
    ctx.waitUntil(dispatch(env));
  },
};

async function dispatch(env) {
  const res = await fetch(DISPATCH_URL, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${env.GITHUB_TOKEN}`,
      Accept: "application/vnd.github+json",
      "X-GitHub-Api-Version": "2022-11-28",
      "User-Agent": "bobboTrade-refresh-worker",
      "Content-Type": "application/json",
    },
    // data_only: run just the bobboTrade job, skip every other deploy.
    body: JSON.stringify({ ref: "main", inputs: { data_only: "true" } }),
  });

  // GitHub answers 204 No Content on success. Anything else shows up in
  // the worker's logs (Cloudflare dashboard > the worker > Logs).
  if (res.status !== 204) {
    console.error(`GitHub dispatch failed: ${res.status} ${await res.text()}`);
  }
}
