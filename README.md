# wallee Express Checkout

WooCommerce add-on that shows wallet buttons (wallee Express Checkout) on the cart page and on product pages. It has no credentials of its own and works on top of the wallee main plugin.

Version 1.0.0. It has been built and tested on a staging shop in wallee's TEST environment with the Bogus Pay test wallet only; see "Known limits" in `plugin/wallee-express-checkout/readme.txt` before using it in production.

Verified on staging so far (owner's runs with Bogus Pay, orders #25 to #28): cart purchases end on the order-received page; the order goes Pending payment -> On hold (AUTHORIZED webhook) -> Processing (FULFILL); the payment method is resolved to the main plugin's gateway (`wallee_7`); the main plugin's transaction record is linked; addresses and e-mail come from the wallet; the failure path sets the order to Failed; the SDK shim lets the main plugin's webhooks process these transactions. Not yet verified end to end: refunds, completion and void (e2e cases 9, 10), reloads (8, 18), main plugin deactivated (14), session renewal (17) and the background link when the browser never returns (19). See `docs/testing/e2e-checklist.md`.

## Security

Version 0.1.0 has known security weaknesses; 1.0.0 fixes them. See the "Security" section and the changelog in `plugin/wallee-express-checkout/readme.txt`, and "Amendment 3" in `docs/superpowers/specs/2026-11-24-design-amendment-1.md` for the behaviour. The default environment is Test: the add-on shows a permanent admin warning while it runs in Test with buttons switched on.

## Layout

| Path | Content |
|---|---|
| `plugin/wallee-express-checkout/` | The plugin (`src/`, `assets/`, `compat/`, `readme.txt`) |
| `tests/` | PHPUnit tests (no WordPress needed) |
| `bin/test`, `bin/lint` | Run tests and lint in Docker |
| `infra/aws/` | Staging provisioning: `provision.sh`, `teardown.sh`, `user-data.sh`, `instance-env.sh`, `lib.sh` |
| `infra/bin/` | `deploy`, `wp`, `remote`: operate the staging instance |
| `infra/wordpress/` | Shop provisioning (`provision-shop.sh`), seed data (`seed-shop.php`), staging-only must-use plugins (`mu-plugins/`: hardening, client address behind CloudFront) |
| `infra/docker-compose.yml` | WordPress, MariaDB and WP-CLI on the instance |
| `docs/` | Design, amendment, spike findings, test checklist (see below) |
| `.github/workflows/ci.yml` | CI: PHPUnit, PHPCS, PHP lint on PHP 7.4 and 8.2 |

Requirements of the plugin: PHP 7.4+, WordPress 6.0+, WooCommerce 8.0+, wallee main plugin (tested with 3.4.11).

## Tests and lint

Docker is the only requirement.

- `bin/test [phpunit args]`: PHPUnit on PHP 8.2.
- `bin/lint`: `php -l` on PHP 7.4 and PHPCS (WordPress standard).

## Staging

A public HTTPS shop on AWS (region `eu-north-1`): one EC2 instance running Docker Compose (WordPress, MariaDB), behind a CloudFront distribution whose default `*.cloudfront.net` host is the shop address. The instance accepts HTTP only from CloudFront; there is no SSH, access is through SSM. Everything is tagged `project=wallee-express-checkout`. The plugin folder is bind-mounted into WordPress.

Commands (need the AWS CLI, profile `default`, `jq`; the instance ID is read from `infra/.state.json`, which is not committed):

- `infra/bin/deploy`: `git pull` of the current branch on the instance.
- `infra/bin/wp <args>`: WP-CLI in the shop, for example `infra/bin/wp plugin list`.
- `infra/bin/remote "<command>"`: run a shell command on the instance through SSM.

Secrets: SSM Parameter Store (SecureString) under `/wallee-express-checkout/`: `db-password`, `db-root-password`, `origin-secret`, `wp-admin-password`. The instance writes them to `/opt/wallee-ec/.env` (mode 600) at provisioning. The wallee application user key is entered in the WordPress admin by hand and is stored by the main plugin, not in this repository.

Tear down: `infra/aws/teardown.sh` (asks for confirmation; `-y` skips it) removes the CloudFront distribution, instance, Elastic IP, security group, IAM role and the SSM parameters, touching only resources tagged for this project. It has never been executed.

## Documents

- Design: `docs/superpowers/specs/2026-11-24-wallee-express-checkout-design.md`
- Amendment 1 (the flow as built; overrides the design where they differ): `docs/superpowers/specs/2026-11-24-design-amendment-1.md`
- Spike findings (real API behaviour): `docs/reference/spike-findings.md`
- End-to-end checklist: `docs/testing/e2e-checklist.md`
- Plugin readme: `plugin/wallee-express-checkout/readme.txt`
