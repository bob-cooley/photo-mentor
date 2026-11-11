# photo-mentor

Static sites and small apps deployed to bobcooleyphoto.com by GitHub Actions over FTP.

## Deploys: encrypted FTP only

Every upload to the host must be encrypted (FTPS). Plain FTP sends the password in clear text.

- `SamKirkland/FTP-Deploy-Action` steps: set `protocol: ftps` in the `with:` block.
- `curl` uploads to `ftp://`: always pass `--ssl-reqd`.
- New deploy jobs must also declare `needs: ftps-guard` (add the guard job if it is a new workflow file).

`ops/check-ftps.py` enforces this. The `ftps-guard` job in `.github/workflows/deploy.yml` and
`election.yml` runs it before any deploy, and the deploy stops if a plain-FTP upload is found.
Run it locally with `python3 ops/check-ftps.py`.
