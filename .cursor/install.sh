#!/usr/bin/env bash
# Cloud agent: PHP deps + Docker for Selenium 2.x (see .github/workflows/tests.yml).
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root"

if ! command -v docker >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  sudo apt-get update -qq
  sudo apt-get install -y --no-install-recommends ca-certificates curl
  if ! command -v docker >/dev/null 2>&1; then
    sudo apt-get install -y --no-install-recommends docker.io docker-compose-plugin \
      || sudo apt-get install -y --no-install-recommends docker.io docker-compose-v2
  fi
fi

docker compose version >/dev/null 2>&1 || docker-compose version >/dev/null 2>&1

composer install --no-interaction --prefer-dist
