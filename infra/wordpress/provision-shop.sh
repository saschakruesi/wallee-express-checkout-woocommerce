#!/usr/bin/env bash
# Runs ON the instance (from the cloned repo): installs and seeds the shop with WP-CLI.
# Safe to re-run. Secrets are read from /opt/wallee-ec/.env and never printed.
set -euo pipefail
cd "$(dirname "$0")/.."   # infra/

WALLEE_TAG=3.4.11
# WooCommerce pinned to the version tested on staging. The compose image starts a fresh shop on
# WordPress 6.x (11.1+ would need WordPress 7.0); the staging shop's core has since been updated
# in place to WordPress 7.1.5 (core lives in the wp volume), which is what the plugin is tested with.
WOOCOMMERCE_VERSION=11.0.1
dc() { docker compose --progress quiet --env-file /opt/wallee-ec/.env "$@"; }
wp() { dc --profile tools run --rm -T wpcli wp "$@"; }

dc up -d db wordpress
echo "waiting for wp-config.php"
for _ in $(seq 1 60); do
  dc exec -T wordpress test -f /var/www/html/wp-config.php && break
  sleep 3
done

if ! wp core is-installed 2>/dev/null; then
  # The password is read from the container environment, not from the command line.
  dc --profile tools run --rm -T wpcli sh -c 'wp core install --url="$SHOP_URL" \
    --title="wallee Express Checkout Test Shop" --admin_user=ecadmin \
    --admin_password="$WP_ADMIN_PASSWORD" --admin_email=admin@example.com --skip-email'
fi

wp rewrite structure '/%postname%/' >/dev/null
wp rewrite flush >/dev/null

wp plugin is-installed woocommerce || wp plugin install woocommerce --version="$WOOCOMMERCE_VERSION"
wp plugin is-active woocommerce || wp plugin activate woocommerce

if ! wp plugin is-installed woo-wallee; then
  tmp="$(mktemp -d)"
  curl -fsSL "https://github.com/wallee-payment/woocommerce/archive/refs/tags/${WALLEE_TAG}.tar.gz" | tar -xz -C "$tmp"
  cid="$(dc ps -q wordpress)"
  docker cp "$tmp/woocommerce-${WALLEE_TAG}" "$cid:/var/www/html/wp-content/plugins/woo-wallee"
  docker exec "$cid" chown -R www-data:www-data /var/www/html/wp-content/plugins/woo-wallee
  rm -rf "$tmp"
fi
wp plugin is-active woo-wallee || wp plugin activate woo-wallee

wp theme is-installed storefront || wp theme install storefront
wp theme activate storefront

# Staging-only must-use plugins (hardening; client address behind CloudFront). Copied, so re-run
# this script (or just this step) after changing them.
dc --profile tools run --rm -T wpcli sh -c \
  'mkdir -p /var/www/html/wp-content/mu-plugins && cp /seed/mu-plugins/*.php /var/www/html/wp-content/mu-plugins/'

wp option update admin_email admin@example.com >/dev/null
wp eval-file /seed/seed-shop.php
wp rewrite flush >/dev/null
echo "shop provisioned"
