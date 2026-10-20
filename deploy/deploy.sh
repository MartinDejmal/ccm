#!/usr/bin/env bash
set -Eeuo pipefail
# Usage: deploy.sh <tag-or-commit> </absolute/path/ccm.env> [release-directory]
# The runtime env file is never sourced as shell code or copied into the image.
ref=${1:?Specify a release tag or commit}
env_file=$(realpath "${2:?Specify an existing environment file}")
migration_env_file=$(realpath "${CCM_MIGRATION_ENV_FILE:-$env_file}")
release_root=${3:-/opt/ccm/releases}
[[ -f "$env_file" && -f "$migration_env_file" ]] || { echo 'Environment file missing' >&2; exit 1; }
for tool in git docker flock; do command -v "$tool" >/dev/null; done
docker compose version >/dev/null
mkdir -p "$release_root"
exec 9>"$release_root/.deploy.lock"
flock -n 9 || { echo 'Deployment already running' >&2; exit 1; }
repo="$release_root/repository"
repository_url=${CCM_REPOSITORY_URL:-https://github.com/MartinDejmal/ccm.git}
if [[ ! -d "$repo/.git" ]]; then git clone --no-checkout "$repository_url" "$repo"; fi
git -C "$repo" fetch --tags origin
sha=$(git -C "$repo" rev-parse --verify "${ref}^{commit}")
release="$release_root/$sha"
if [[ ! -d "$release" ]]; then git -C "$repo" worktree add --detach "$release" "$sha"; fi
export CCM_ENV_FILE="$env_file" CCM_IMAGE="ccm:$sha"
cd "$release"
compose=(docker compose --project-name ccm --file compose.yaml)
"${compose[@]}" build
# Explicit DB connectivity/readiness before any schema changes.
"${compose[@]}" run --rm --no-deps app php artisan ccm:check-db >/dev/null
if docker inspect ccm-app-1 >/dev/null 2>&1; then
  [[ "${CCM_BACKUP_CONFIRMED:-0}" == 1 ]] || { echo 'Existing installation: verify a recent backup, then set CCM_BACKUP_CONFIRMED=1.' >&2; exit 1; }
  docker exec ccm-app-1 php artisan down --retry=60
fi
trap 'echo "Deployment failed. Check database migrations and container state before resuming traffic; no automatic DB rollback was attempted." >&2' ERR
# Initial schema only: additive migrations. For later releases take a verified DB
# backup first; MariaDB DDL is not transactionally rolled back on migration failure.
CCM_ENV_FILE="$migration_env_file" "${compose[@]}" run --rm --no-deps app php artisan migrate --force
"${compose[@]}" up -d --no-build --wait --wait-timeout 120
"${compose[@]}" exec -T app php artisan up
printf '%s\n' "$sha" > "$release_root/current-release"
echo "CCM deployed: $sha"
