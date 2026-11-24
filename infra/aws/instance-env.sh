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
# Read every value first so a failed or empty SSM read aborts before .env is touched.
db_password="$(get db-password)"
db_root_password="$(get db-root-password)"
origin_secret="$(get origin-secret)"
wp_admin_password="$(get wp-admin-password)"
for v in "$db_password" "$db_root_password" "$origin_secret" "$wp_admin_password"; do
  [ -n "$v" ] && [ "$v" != "None" ] || { rm -f "$tmp"; echo "empty parameter value, .env left unchanged" >&2; exit 1; }
done
{
  echo "SHOP_URL=$shop_url"
  echo "DB_PASSWORD=$db_password"
  echo "DB_ROOT_PASSWORD=$db_root_password"
  echo "ORIGIN_SECRET=$origin_secret"
  echo "WP_ADMIN_PASSWORD=$wp_admin_password"
} > "$tmp"
mv "$tmp" /opt/wallee-ec/.env
chmod 600 /opt/wallee-ec/.env
echo ".env written"
