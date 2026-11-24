#!/usr/bin/env bash
# Creates the staging shop on AWS (billable). Idempotent: safe to re-run.
# Prerequisite: the branch `prototype` (including infra/) is pushed to GitHub,
# because the instance clones it.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$here/lib.sh"
REMOTE="$INFRA_DIR/bin/remote"
TAGS_KV="Key=project,Value=$PROJECT_TAG"
ACCOUNT="$(aws sts get-caller-identity --query Account --output text)"

# ---------------------------------------------------------------- IAM -------
ensure_iam() {
  local role="$PFX-instance"
  if ! aws iam get-role --role-name "$role" >/dev/null 2>&1; then
    log "creating IAM role $role"
    aws iam create-role --role-name "$role" --tags "$TAGS_KV" --assume-role-policy-document \
      '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"ec2.amazonaws.com"},"Action":"sts:AssumeRole"}]}' >/dev/null
  fi
  aws iam attach-role-policy --role-name "$role" --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
  aws iam put-role-policy --role-name "$role" --policy-name "$PFX-read-parameters" --policy-document \
    "{\"Version\":\"2012-10-17\",\"Statement\":[{\"Effect\":\"Allow\",\"Action\":[\"ssm:GetParameter\",\"ssm:GetParameters\"],\"Resource\":\"arn:aws:ssm:$AWS_REGION:$ACCOUNT:parameter$SSM_PATH/*\"}]}"
  if ! aws iam get-instance-profile --instance-profile-name "$role" >/dev/null 2>&1; then
    log "creating instance profile $role"
    aws iam create-instance-profile --instance-profile-name "$role" --tags "$TAGS_KV" >/dev/null
    aws iam wait instance-profile-exists --instance-profile-name "$role"
  fi
  if [ "$(aws iam get-instance-profile --instance-profile-name "$role" --query 'length(InstanceProfile.Roles)' --output text)" = "0" ]; then
    aws iam add-role-to-instance-profile --instance-profile-name "$role" --role-name "$role"
  fi
}

# ---------------------------------------------------------------- secrets ----
ensure_secret() {
  local name="$1"
  if aws ssm get-parameter --name "$SSM_PATH/$name" >/dev/null 2>&1; then return 0; fi
  log "generating secret $name"
  aws ssm put-parameter --name "$SSM_PATH/$name" --type SecureString \
    --value "$(openssl rand -hex 24)" --tags "$TAGS_KV" >/dev/null
}

# ---------------------------------------------------------------- network ---
ensure_security_group() {
  local vpc sg pl
  vpc="$(aws ec2 describe-vpcs --filters Name=isDefault,Values=true --query 'Vpcs[0].VpcId' --output text)"
  pl="$(aws ec2 describe-managed-prefix-lists --filters Name=prefix-list-name,Values=com.amazonaws.global.cloudfront.origin-facing --query 'PrefixLists[0].PrefixListId' --output text)"
  sg="$(aws ec2 describe-security-groups --filters Name=group-name,Values="$PFX-web" Name=vpc-id,Values="$vpc" --query 'SecurityGroups[0].GroupId' --output text)"
  if [ "$sg" = "None" ]; then
    log "creating security group"
    sg="$(aws ec2 create-security-group --group-name "$PFX-web" --vpc-id "$vpc" \
      --description "wallee-ec staging: HTTP from CloudFront only" \
      --tag-specifications "ResourceType=security-group,Tags=[{Key=Name,Value=$PFX-web},{$TAGS_KV}]" \
      --query GroupId --output text)"
  fi
  local err
  if ! err="$(aws ec2 authorize-security-group-ingress --group-id "$sg" \
    --ip-permissions "IpProtocol=tcp,FromPort=80,ToPort=80,PrefixListIds=[{PrefixListId=$pl,Description=CloudFront}]" 2>&1)"; then
    case "$err" in *InvalidPermission.Duplicate*) ;; *) echo "$err" >&2; exit 1 ;; esac
  fi
  state_set vpc_id "$vpc"
  state_set security_group_id "$sg"
}

ensure_eip() {
  local alloc
  alloc="$(aws ec2 describe-addresses --filters Name=tag:Name,Values="$NAME" Name=tag:project,Values="$PROJECT_TAG" --query 'Addresses[0].AllocationId' --output text)"
  if [ "$alloc" = "None" ]; then
    log "allocating Elastic IP"
    alloc="$(aws ec2 allocate-address --domain vpc \
      --tag-specifications "ResourceType=elastic-ip,Tags=[{Key=Name,Value=$NAME},{$TAGS_KV}]" --query AllocationId --output text)"
  fi
  state_set allocation_id "$alloc"
  state_set elastic_ip "$(aws ec2 describe-addresses --allocation-ids "$alloc" --query 'Addresses[0].PublicIp' --output text)"
}

# ---------------------------------------------------------------- instance --
ensure_instance() {
  local iid ami subnet sg
  iid="$(aws ec2 describe-instances \
    --filters Name=tag:Name,Values="$NAME" Name=tag:project,Values="$PROJECT_TAG" Name=instance-state-name,Values=pending,running,stopping,stopped \
    --query 'Reservations[].Instances[].InstanceId' --output text | awk '{print $1}')"
  if [ -z "$iid" ]; then
    ami="$(aws ssm get-parameter --name /aws/service/canonical/ubuntu/server/24.04/stable/current/arm64/hvm/ebs-gp3/ami-id --query Parameter.Value --output text)"
    sg="$(state_get security_group_id)"
    subnet="$(aws ec2 describe-subnets --filters Name=vpc-id,Values="$(state_get vpc_id)" Name=default-for-az,Values=true --query 'Subnets[0].SubnetId' --output text)"
    log "launching t4g.small from $ami"
    for attempt in 1 2 3 4 5 6; do
      if iid="$(aws ec2 run-instances --image-id "$ami" --instance-type t4g.small --subnet-id "$subnet" \
          --security-group-ids "$sg" --iam-instance-profile Name="$PFX-instance" \
          --metadata-options HttpTokens=required,HttpEndpoint=enabled \
          --block-device-mappings 'DeviceName=/dev/sda1,Ebs={VolumeSize=20,VolumeType=gp3,Encrypted=true,DeleteOnTermination=true}' \
          --user-data "file://$here/user-data.sh" \
          --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=$NAME},{$TAGS_KV}]" \
                               "ResourceType=volume,Tags=[{Key=Name,Value=$NAME},{$TAGS_KV}]" \
          --query 'Instances[0].InstanceId' --output text 2>/tmp/wallee-ec-run.err)"; then break; fi
      grep -q 'Invalid IAM Instance Profile' /tmp/wallee-ec-run.err || { cat /tmp/wallee-ec-run.err >&2; exit 1; }
      log "instance profile not yet visible, retrying ($attempt)"; sleep 10
    done
    [ -n "$iid" ] || { echo "instance launch failed" >&2; exit 1; }
  fi
  state_set instance_id "$iid"
  aws ec2 wait instance-running --instance-ids "$iid"
  aws ec2 associate-address --allocation-id "$(state_get allocation_id)" --instance-id "$iid" --allow-reassociation >/dev/null
  log "waiting for SSM agent"
  for _ in $(seq 1 60); do
    [ "$(aws ssm describe-instance-information --filters Key=InstanceIds,Values="$iid" --query 'InstanceInformationList[0].PingStatus' --output text)" = "Online" ] && break
    sleep 10
  done
  log "waiting for first-boot setup (Docker)"
  "$REMOTE" 'cloud-init status --wait >/dev/null 2>&1; test -f /opt/wallee-ec/.bootstrapped && docker --version'
}

# ---------------------------------------------------------------- CloudFront -
ensure_distribution() {
  local ip dns id cfg out policy_cache policy_origin secret
  ip="$(state_get elastic_ip)"
  dns="ec2-${ip//./-}.$AWS_REGION.compute.amazonaws.com"
  id="$(aws cloudfront list-distributions --query "DistributionList.Items[?Comment=='$NAME'].Id | [0]" --output text)"
  if [ "$id" = "None" ] || [ -z "$id" ]; then
    log "creating CloudFront distribution (origin $dns)"
    policy_cache="$(aws cloudfront list-cache-policies --type managed --query "CachePolicyList.Items[?CachePolicy.CachePolicyConfig.Name=='Managed-CachingDisabled'].CachePolicy.Id | [0]" --output text)"
    # AllViewerAndCloudFrontHeaders: AllViewer plus CloudFront-Forwarded-Proto, which WordPress uses to detect HTTPS.
    policy_origin="$(aws cloudfront list-origin-request-policies --type managed --query "OriginRequestPolicyList.Items[?OriginRequestPolicy.OriginRequestPolicyConfig.Name=='Managed-AllViewerAndCloudFrontHeaders-2022-06'].OriginRequestPolicy.Id | [0]" --output text)"
    secret="$(aws ssm get-parameter --name "$SSM_PATH/origin-secret" --with-decryption --query Parameter.Value --output text)"
    cfg="$(jq -n --arg name "$NAME" --arg dns "$dns" --arg secret "$secret" --arg cp "$policy_cache" --arg op "$policy_origin" --arg tag "$PROJECT_TAG" '{
      DistributionConfig: {
        CallerReference: $name, Comment: $name, Enabled: true, PriceClass: "PriceClass_100", HttpVersion: "http2",
        Origins: {Quantity: 1, Items: [{
          Id: "ec2", DomainName: $dns,
          CustomHeaders: {Quantity: 1, Items: [{HeaderName: "X-Origin-Secret", HeaderValue: $secret}]},
          CustomOriginConfig: {HTTPPort: 80, HTTPSPort: 443, OriginProtocolPolicy: "http-only", OriginReadTimeout: 60}
        }]},
        DefaultCacheBehavior: {
          TargetOriginId: "ec2", ViewerProtocolPolicy: "redirect-to-https", Compress: true,
          AllowedMethods: {Quantity: 7, Items: ["GET","HEAD","OPTIONS","PUT","POST","PATCH","DELETE"],
                           CachedMethods: {Quantity: 2, Items: ["GET","HEAD"]}},
          CachePolicyId: $cp, OriginRequestPolicyId: $op
        }
      },
      Tags: {Items: [{Key: "project", Value: $tag}, {Key: "Name", Value: $name}]}
    }')"
    out="$(aws cloudfront create-distribution-with-tags --distribution-config-with-tags "$cfg")"
    id="$(printf '%s' "$out" | jq -r .Distribution.Id)"
  fi
  state_set distribution_id "$id"
  state_set shop_url "https://$(aws cloudfront get-distribution --id "$id" --query Distribution.DomainName --output text)"
}

# ---------------------------------------------------------------- GitHub -----
ensure_repo_on_instance() {
  local pub keyfile existing id key
  pub="$("$REMOTE" 'mkdir -p /root/.ssh && chmod 700 /root/.ssh; [ -f /root/.ssh/wallee-ec-deploy ] || ssh-keygen -q -t ed25519 -N "" -C wallee-ec-staging -f /root/.ssh/wallee-ec-deploy; cat /root/.ssh/wallee-ec-deploy.pub')"
  existing="$(gh api "repos/$GH_REPO/keys" --jq '.[] | select(.title=="wallee-ec-staging") | "\(.id) \(.key)"' || true)"
  id="${existing%% *}"; key="${existing#* }"
  if [ -n "$existing" ] && [ "$key" != "$(printf '%s' "$pub" | cut -d' ' -f1,2)" ]; then
    log "replacing stale deploy key"
    gh repo deploy-key delete "$id" --repo "$GH_REPO"; existing=""
  fi
  if [ -z "$existing" ]; then
    keyfile="$(mktemp)"; printf '%s\n' "$pub" > "$keyfile"
    gh repo deploy-key add "$keyfile" --repo "$GH_REPO" --title wallee-ec-staging
    rm -f "$keyfile"
  fi
  log "cloning / updating repository on the instance"
  for _ in 1 2 3; do
    if "$REMOTE" "export GIT_SSH_COMMAND='ssh -i /root/.ssh/wallee-ec-deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new'
      if [ -d /opt/wallee-ec/repo/.git ]; then cd /opt/wallee-ec/repo && git pull --ff-only
      else git clone --branch $BRANCH git@github.com:$GH_REPO.git /opt/wallee-ec/repo && cd /opt/wallee-ec/repo && git config core.sshCommand \"\$GIT_SSH_COMMAND\"; fi"; then return 0; fi
    sleep 8
  done
  echo "could not clone the repository (is $BRANCH pushed?)" >&2; exit 1
}

# ---------------------------------------------------------------- main -------
git -C "$INFRA_DIR/.." ls-remote --exit-code --heads origin "$BRANCH" >/dev/null || { echo "push branch $BRANCH first" >&2; exit 1; }
ensure_iam
for s in db-password db-root-password origin-secret wp-admin-password; do ensure_secret "$s"; done
ensure_security_group
ensure_eip
ensure_instance
ensure_distribution
SHOP_URL="$(state_get shop_url)"
ensure_repo_on_instance
log "writing .env and starting containers"
"$REMOTE" "bash /opt/wallee-ec/repo/infra/aws/instance-env.sh '$SHOP_URL' && cd /opt/wallee-ec/repo/infra && docker compose --env-file /opt/wallee-ec/.env up -d"
log "provisioning WordPress / WooCommerce (several minutes)"
"$REMOTE" "bash /opt/wallee-ec/repo/infra/wordpress/provision-shop.sh"
log "waiting for CloudFront deployment (several minutes)"
aws cloudfront wait distribution-deployed --id "$(state_get distribution_id)"
log "done: $SHOP_URL"
echo "$SHOP_URL"
