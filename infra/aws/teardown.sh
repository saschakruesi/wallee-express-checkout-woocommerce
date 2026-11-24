#!/usr/bin/env bash
# Deletes everything provision.sh created. Only touches resources tagged
# project=wallee-express-checkout or named wallee-ec*. Usage: teardown.sh [-y]
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$here/lib.sh"
if [ "${1:-}" != "-y" ]; then
  read -r -p "Delete the wallee-ec staging shop and all its data? [y/N] " ans
  [ "$ans" = "y" ] || exit 1
fi
ACCOUNT="$(aws sts get-caller-identity --query Account --output text)"

# CloudFront: disable, wait, delete (only a distribution named and tagged by us).
id="$(aws cloudfront list-distributions --query "DistributionList.Items[?Comment=='$NAME'].Id | [0]" --output text)"
if [ -n "$id" ] && [ "$id" != "None" ]; then
  arn="arn:aws:cloudfront::$ACCOUNT:distribution/$id"
  dist_tag="$(aws cloudfront list-tags-for-resource --resource "$arn" --query "Tags.Items[?Key=='project'].Value" --output text)"
  if [ "$dist_tag" = "$PROJECT_TAG" ]; then
    log "disabling CloudFront distribution $id"
    out="$(aws cloudfront get-distribution-config --id "$id")"
    etag="$(printf '%s' "$out" | jq -r .ETag)"
    if [ "$(printf '%s' "$out" | jq -r .DistributionConfig.Enabled)" = "true" ]; then
      aws cloudfront update-distribution --id "$id" --if-match "$etag" \
        --distribution-config "$(printf '%s' "$out" | jq -c '.DistributionConfig | .Enabled=false')" >/dev/null
    fi
    aws cloudfront wait distribution-deployed --id "$id"
    etag="$(aws cloudfront get-distribution-config --id "$id" --query ETag --output text)"
    aws cloudfront delete-distribution --id "$id" --if-match "$etag"
    log "distribution deleted"
  else
    echo "distribution $id is named $NAME but not tagged $PROJECT_TAG; refusing to continue" >&2; exit 1
  fi
fi

# EC2 instance
ids="$(aws ec2 describe-instances --filters Name=tag:Name,Values="$NAME" Name=tag:project,Values="$PROJECT_TAG" Name=instance-state-name,Values=pending,running,stopping,stopped --query 'Reservations[].Instances[].InstanceId' --output text)"
if [ -n "$ids" ]; then
  log "terminating $ids"
  # shellcheck disable=SC2086
  aws ec2 terminate-instances --instance-ids $ids >/dev/null
  # shellcheck disable=SC2086
  aws ec2 wait instance-terminated --instance-ids $ids
fi

# Elastic IP
for alloc in $(aws ec2 describe-addresses --filters Name=tag:Name,Values="$NAME" Name=tag:project,Values="$PROJECT_TAG" --query 'Addresses[].AllocationId' --output text); do
  log "releasing Elastic IP $alloc"
  aws ec2 release-address --allocation-id "$alloc"
done

# Security group
sg="$(aws ec2 describe-security-groups --filters Name=group-name,Values="$PFX-web" Name=tag:project,Values="$PROJECT_TAG" --query 'SecurityGroups[0].GroupId' --output text)"
if [ "$sg" != "None" ]; then
  log "deleting security group $sg"
  deleted=no
  for _ in $(seq 1 12); do
    if aws ec2 delete-security-group --group-id "$sg" 2>/dev/null; then deleted=yes; break; fi
    sleep 10
  done
  [ "$deleted" = yes ] || { echo "could not delete security group $sg; state file kept" >&2; exit 1; }
fi

# IAM
role="$PFX-instance"
if role_tag="$(aws iam get-role --role-name "$role" --query 'Role.Tags[?Key==`project`].Value' --output text 2>/tmp/wallee-ec-iam.err)"; then
  [ "$role_tag" = "$PROJECT_TAG" ] || { echo "role $role exists but is not tagged $PROJECT_TAG; refusing to continue" >&2; exit 1; }
elif grep -q NoSuchEntity /tmp/wallee-ec-iam.err; then
  role_tag=""
else
  cat /tmp/wallee-ec-iam.err >&2; exit 1
fi
if [ -n "$role_tag" ]; then
  log "deleting IAM role and instance profile"
  aws iam remove-role-from-instance-profile --instance-profile-name "$role" --role-name "$role" 2>/dev/null || true
  aws iam delete-instance-profile --instance-profile-name "$role" 2>/dev/null || true
  aws iam detach-role-policy --role-name "$role" --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore 2>/dev/null || true
  aws iam delete-role-policy --role-name "$role" --policy-name "$PFX-read-parameters" 2>/dev/null || true
  aws iam delete-role --role-name "$role"
fi

# Parameters under our own path
names="$(aws ssm get-parameters-by-path --path "$SSM_PATH" --query 'Parameters[].Name' --output text)"
for n in $names; do aws ssm delete-parameter --name "$n"; done

# Deploy key
kid="$(gh api "repos/$GH_REPO/keys" --jq '.[] | select(.title=="wallee-ec-staging") | .id' 2>/dev/null || true)"
[ -z "$kid" ] || gh repo deploy-key delete "$kid" --repo "$GH_REPO"

rm -f "$STATE_FILE"
log "teardown complete"
