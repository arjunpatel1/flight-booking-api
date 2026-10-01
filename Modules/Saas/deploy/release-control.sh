#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT=""
BRANCH=""
APPLY=0
ALLOWED_BRANCHES="${SAAS_DEPLOYMENT_ALLOWED_BRANCHES:-main,staging}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --repo-root) REPO_ROOT="${2:-}"; shift 2 ;;
    --branch) BRANCH="${2:-}"; shift 2 ;;
    --apply) APPLY=1; shift ;;
    -h|--help) echo "Usage: $0 --repo-root /srv/nexdine --branch main [--apply]"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

[[ -n "$REPO_ROOT" && -d "$REPO_ROOT/.git" ]] || { echo "Configured repository root is invalid." >&2; exit 1; }
[[ "$BRANCH" =~ ^[a-zA-Z0-9._/-]+$ ]] || { echo "Invalid branch." >&2; exit 1; }

allowed=0
IFS=',' read -ra branch_list <<< "$ALLOWED_BRANCHES"
for candidate in "${branch_list[@]}"; do
  [[ "$BRANCH" == "${candidate//[[:space:]]/}" ]] && allowed=1
done
[[ "$allowed" -eq 1 ]] || { echo "Branch is not in the server allow-list." >&2; exit 1; }

cd "$REPO_ROOT"
current_branch="$(git branch --show-current)"
current_revision="$(git rev-parse HEAD)"
echo "current_branch=${current_branch}"
echo "current_revision=${current_revision}"
echo "requested_branch=${BRANCH}"

if [[ "$APPLY" -eq 0 ]]; then
  echo "mode=preview"
  exit 0
fi

[[ -z "$(git status --porcelain --untracked-files=no)" ]] || {
  echo "Deployment refused: tracked server changes must be reconciled first." >&2
  exit 1
}

exec 9>"${TMPDIR:-/tmp}/nexdine-deploy.lock"
flock -n 9 || { echo "Another deployment is already running." >&2; exit 1; }

git fetch --prune origin "$BRANCH"
git checkout "$BRANCH"
git merge --ff-only "origin/$BRANCH"

if [[ -n "${SAAS_POST_DEPLOY_SCRIPT:-}" ]]; then
  [[ -x "$SAAS_POST_DEPLOY_SCRIPT" ]] || { echo "Configured post-deploy script is not executable." >&2; exit 1; }
  "$SAAS_POST_DEPLOY_SCRIPT"
fi

echo "new_revision=$(git rev-parse HEAD)"
echo "status=completed"
