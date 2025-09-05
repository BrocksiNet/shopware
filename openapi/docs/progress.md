# OpenAPI Automation – Progress & TODOs

## Current state
- Scripts relocated under `openapi/scripts/`:
  - `collect-endpoints.js` – discovers routes (Store/Admin), with safe fallback
  - `generate.js` – runs `@shopware/api-gen` load/generate, Symfony validator, Redocly lint
  - `diff-endpoint.js` – compares code-defined path JSON vs loaded `api-types/*Schema.json`
  - `runner.js` – processes one endpoint, updates `openapi/progress.json`, creates/pushes a branch
- Local tooling in `openapi/`:
  - `package.json` with scripts: `bootstrap:env`, `collect:*`, `generate:*`, `run:*`, `lint`, `format`
  - `bootstrap-env.sh` writes `.env` matching CI (derives `OPENAPI_ACCESS_KEY`)
  - `biome.json` for JS linting, and npm scripts to run Biome
  - `config.json`, `progress.json` for configuration and durable progress
  - Docs: `openapi/docs/README.md` (overview) and this `progress.md`
- Cursor agent rule: `.cursor/rules/openapi.md` (behavior & safety rails)
- CI:
  - `.github/workflows/php.yml` now validates Store/Admin schemas via Symfony validator in addition to Redocly lint
  - New workflow `.github/workflows/openapi-runner.yml` to run the per-endpoint runner in the fork
- Cleanup:
  - `openapi/node_modules` ignored; legacy `scripts/openapi/*` removed

## Pilot result
- Local pilot executed for one Store endpoint (`/store-api/context`):
  - Schema generated and validated (Symfony + Redocly)
  - Branch pushed to fork: `feat/openapi-store/store-api-context`
  - Review and upstream PR can be created in the fork: https://github.com/BrocksiNet/shopware

Comparison findings (so far)
- `/cms/{id}` (Store): initially flagged due to params expanded by `x-parameter-group`. Comparator now accounts for `productListingCriteria`; no diff remains.

## How to run locally
1) Install tools and prepare env
- `npm --prefix openapi i`
- `npm --prefix openapi run bootstrap:env`
2) Process next Store endpoint
- `node openapi/scripts/runner.js store brocksinet`
3) Lint scripts
- `npm --prefix openapi run lint`
4) Compare a single endpoint
- Store: `npm --prefix openapi run diff:endpoint:store -- "/cms/{id}"`
- Admin: `npm --prefix openapi run diff:endpoint:admin -- "/_info/config"`

Endpoint collection
- Store: router-first, fallback to code-defined `StoreApi/paths/*.json`
- Admin: scans PHP controllers (`#[Route]`) to collect `/api/**`, fallback to router/code paths

## How to run in fork CI
- Workflow: Actions → “OpenAPI runner (per-endpoint)”
  - Branch: `feat/openapi-automation/setup`
  - Input `mode`: `store` (or `admin`)
  - Runner will push a branch in the fork when there’s a valid schema diff

## Open TODOs (next)
- Add Admin pass priority for missing endpoints:
  - Compare `/api/**` router endpoints vs `adminApiSchema.json` to derive `pending` set
- Add batch support: `runner.js --batch N` to process N endpoints per run
- Add explicit endpoint selection: `runner.js --endpoint <path>`
- CI: add step to run Biome for `openapi/scripts` (optional non-blocking or blocking)
- Improve route discovery robustness and remove fallback once CI router JSON is guaranteed
- Add simple file lock to prevent concurrent writes when multiple runners are used
- Auto-open PRs in fork after branch push (e.g., via `gh` CLI) – optional
- Enhance comparator output with a structured diff of params/requestBody/responses for easier fixes

## Nice-to-haves (later)
- Missing endpoint detector and generator report (human-friendly diff summary for PR body)
- Redocly config file instead of CLI flags
- Extend docs with conventions for endpoint-specific overrides and review checklist

## Known caveats
- `openapi/progress.json` is updated locally/CI but not committed to endpoint branches (intentional)
- The runner assumes a reachable instance at `OPENAPI_JSON_URL` (CI prepares it; locally ensure server is up)

## Quick references
- Scripts: `openapi/scripts/*`
- Configs: `openapi/config.json`, `openapi/progress.json`, `openapi/biome.json`
- Docs: `openapi/docs/README.md`, `openapi/docs/progress.md`
- CI workflows: `.github/workflows/php.yml`, `.github/workflows/openapi-runner.yml`
- Fork repo: https://github.com/BrocksiNet/shopware
