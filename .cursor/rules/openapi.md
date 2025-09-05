Agent Rules: OpenAPI Automation

Scope
- Process one endpoint at a time for Store or Admin API
- Read and update openapi/progress.json atomically

Steps per endpoint
1. Ensure .env exists (run `npm --prefix openapi run bootstrap:env` if missing)
2. Generate + validate using node openapi/scripts/generate.js <mode>
3. If api-types/* changed, create branch feat/openapi-<mode>/<slug> and push
4. Update progress.json with status and branch; skip if no changes

Safety
- Never run concurrent writers; acquire a simple lock by checking status=in_progress
- Only open PR if both Symfony validator and Redocly lint pass

Resumption
- Always pick the next endpoint with status=pending
- If local run fails, revert to pending; CI will process it


