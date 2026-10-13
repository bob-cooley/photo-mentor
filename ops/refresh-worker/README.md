# Refresh worker

Triggers the bobboTrade data refresh on time. See `worker.js` for why.

## Setup (one time)

1. GitHub: create a fine-grained personal access token
   - Settings > Developer settings > Personal access tokens > Fine-grained tokens > Generate new token
   - Repository access: Only select repositories > `photo-mentor`
   - Repository permissions: **Actions: Read and write** (Metadata read-only is added automatically)
   - Expiration: the maximum offered. Put the renewal date in your calendar.
2. Cloudflare: Workers & Pages > Create > Worker. Name it `bobbotrade-refresh`.
   Deploy it, then Edit code, paste `worker.js`, and Deploy again.
3. Worker > Settings > Variables and Secrets > Add: type **Secret**,
   name `GITHUB_TOKEN`, value = the token from step 1.
4. Worker > Settings > Triggers > Cron Triggers > Add: `*/5 13-21 * * 1-5`
   (UTC; covers the US market session in both EDT and EST).

## Renewing the token

Repeat step 1, then replace the `GITHUB_TOKEN` secret value in step 3.
If the token expires the worker just logs failures and the dashboard falls
back to GitHub's own (unreliable) schedule.
