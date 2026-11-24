#!/bin/bash
# First-boot setup: Docker, Compose, git, AWS CLI. No secrets here.
set -euxo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y docker.io docker-compose-v2 git curl unzip
systemctl enable --now docker
snap install aws-cli --classic
ln -sf /snap/bin/aws /usr/local/bin/aws
mkdir -p /opt/wallee-ec
touch /opt/wallee-ec/.bootstrapped
