#!/usr/bin/env bash
# Shared settings and helpers for the staging scripts. Source this file.
export AWS_PAGER="" AWS_PROFILE=default AWS_REGION=eu-north-1 AWS_DEFAULT_REGION=eu-north-1

PROJECT_TAG=wallee-express-checkout
PFX=wallee-ec
NAME=wallee-ec-staging
SSM_PATH=/wallee-express-checkout
GH_REPO=saschakruesi/wallee-express-checkout-woocommerce
BRANCH=prototype
INFRA_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_FILE="$INFRA_DIR/.state.json"

log() { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*" >&2; }

state_get() {
  [ -f "$STATE_FILE" ] || return 0
  jq -r --arg k "$1" '.[$k] // empty' "$STATE_FILE"
}

state_set() {
  local tmp
  [ -f "$STATE_FILE" ] || echo '{}' > "$STATE_FILE"
  tmp="$(mktemp)"
  jq --arg k "$1" --arg v "$2" '.[$k] = $v' "$STATE_FILE" > "$tmp" && mv "$tmp" "$STATE_FILE"
}
