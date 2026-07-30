#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

platforms=("$@")

if [ "${#platforms[@]}" -eq 0 ]; then
  platforms=(ubuntu-24.04 rocky-9 ubi-9)
fi

for platform in "${platforms[@]}"; do
  image="infraregister-test:${platform}"
  dockerfile="${repo_root}/docker/${platform}.Dockerfile"

  if [ ! -f "${dockerfile}" ]; then
    echo "Unknown platform: ${platform}" >&2
    exit 2
  fi

  docker build --pull -f "${dockerfile}" -t "${image}" "${repo_root}"
  docker run --rm \
    -v "${repo_root}:/app" \
    -v "infraregister-composer-cache-${platform}:/tmp/composer-cache" \
    -e COMPOSER_CACHE_DIR=/tmp/composer-cache \
    -w /app \
    "${image}" \
    sh -lc 'composer install --no-interaction --no-progress && composer quality'
done
