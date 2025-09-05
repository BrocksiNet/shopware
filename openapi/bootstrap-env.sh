#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

STOREFRONT_ID=$(bin/console sales-channel:list --output json | jq -r '.[] | select(.name == "Storefront") | .id')
OPENAPI_ACCESS_KEY=$(mysql -u root -h 127.0.0.1 shopware -se "SELECT access_key FROM sales_channel WHERE id = UNHEX(\"${STOREFRONT_ID}\")";)

cat > .env <<EOF
OPENAPI_JSON_URL=http://localhost:8000
SHOPWARE_ADMIN_USERNAME=admin
SHOPWARE_ADMIN_PASSWORD=shopware
OPENAPI_ACCESS_KEY=${OPENAPI_ACCESS_KEY}
EOF

echo ".env prepared with OPENAPI access key"


