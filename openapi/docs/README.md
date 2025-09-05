OpenAPI Automation for Shopware 6

Overview
This folder contains automation for improving and validating OpenAPI schemas for Store and Admin APIs.

Key pieces
- Tools: @shopware/api-gen for load/generate + Symfony validator + Redocly lint
- Comparator: code vs loaded schema diff for a single endpoint
- Runner: processes one endpoint at a time, records progress, and opens branches
- Progress: openapi/progress.json tracks per-endpoint status and last processed

Commands
- Bootstrap env: npm --prefix openapi run bootstrap:env
- Run next Store endpoint locally: node openapi/scripts/runner.js store brocksinet
- Run next Admin endpoint locally: node openapi/scripts/runner.js admin brocksinet
- Compare code vs loaded schema (Store): npm --prefix openapi run diff:endpoint:store -- "/cms/{id}"
- Compare code vs loaded schema (Admin): npm --prefix openapi run diff:endpoint:admin -- "/_info/config"

Branch/PR conventions
- Branch: feat/openapi-{store|admin}/<endpoint-slug>
- Commit: OpenAPI({Store|Admin}): refine <endpoint>

CI
- Workflow openapi-runner.yml can run per-endpoint in fork (REMOTE=origin)
- .github/workflows/php.yml validates schemas (Symfony + Redocly)

Schema locations
- Code-defined path files:
  - Store API: src/Core/Framework/Api/ApiDefinition/Generator/Schema/StoreApi/paths/*.json (e.g. cms.json)
  - Admin API: src/Core/Framework/Api/ApiDefinition/Generator/Schema/AdminApi/paths/*.json
- Controller-discovered paths (Admin): PHP controllers under `src/**` with `#[Route(...)]` for `/api/**`
- Generated (loaded) schemas by api-gen:
  - api-types/storeApiSchema.json
  - api-types/adminApiSchema.json

Comparator behavior
- Normalizes path template names (treats /cms/{id} same as /cms/{pageId})
- Accepts Store paths with or without leading /store-api prefix
- Treats `x-parameter-group: productListingCriteria` as providing expanded query params
- Exits non-zero when differences are detected (parameters, requestBody, responses)

Endpoint collection
- Store: uses Symfony router (`bin/console debug:router`), falls back to code-defined `StoreApi/paths/*.json`
- Admin: scans PHP `#[Route]` attributes under `src/**` to collect `/api/**` endpoints, falls back to router/code paths


