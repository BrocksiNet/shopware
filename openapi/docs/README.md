OpenAPI Automation for Shopware 6

Overview
This folder contains automation for improving and validating OpenAPI schemas for Store and Admin APIs.

Key pieces
- Tools: @shopware/api-gen for load/generate + Symfony validator + Redocly lint
- Runner: processes one endpoint at a time, records progress, and opens branches
- Progress: openapi/progress.json tracks per-endpoint status and last processed

Commands
- Bootstrap env: npm --prefix openapi run bootstrap:env
- Run next Store endpoint locally: node openapi/scripts/runner.js store brocksinet
- Run next Admin endpoint locally: node openapi/scripts/runner.js admin brocksinet

Branch/PR conventions
- Branch: feat/openapi-{store|admin}/<endpoint-slug>
- Commit: OpenAPI({Store|Admin}): refine <endpoint>

CI
- Workflow openapi-runner.yml can run per-endpoint in fork (REMOTE=origin)
- .github/workflows/php.yml validates schemas (Symfony + Redocly)


