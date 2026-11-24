#!/usr/bin/env bash
# Runs ON the instance: writes /opt/wallee-ec/.env from SSM Parameter Store.
# Usage: instance-env.sh <shop-url>
set -euo pipefail
export AWS_DEFAULT_REGION=eu-north-1
export PATH="$PATH:/snap/bin"
shop_url="${1:?shop url required}"
get() { aws ssm get-parameter --name "/wallee-express-checkout/$1" --with-decryption --query Parameter.Value --output text; }
umask 077
mkdir -p /opt/wallee-ec
tmp="$(mktemp /opt/wallee-ec/.env.XXXXXX)"
{
  echo "SHOP_URL=$shop_url"
  echo "DB_PASSWORD=$(get db-password)"
  echo "DB_ROOT_PASSWORD=$(get db-root-password)"
  echo "ORIGIN_SECRET=$(get origin-secret)"
  echo "WP_ADMIN_PASSWORD=$(get wp-admin-password)"
} > "$tmp"
mv "$tmp" /opt/wallee-ec/.env
chmod 600 /opt/wallee-ec/.env
echo ".env written"
