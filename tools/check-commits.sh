#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Audit every non-merge commit in a pull request, not the PR title alone.
set -eu

if [ "$#" -ne 3 ] || { [ "$1" != "--dco" ] && [ "$1" != "--conventional" ]; }; then
  echo 'Usage: check-commits.sh --dco|--conventional BASE_REF HEAD_REF' >&2
  exit 2
fi

mode=$1
base=$(git merge-base "$2" "$3")
failed=0

for commit in $(git rev-list --no-merges "$base..$3"); do
  subject=$(git show -s --format=%s "$commit")
  author=$(git show -s --format='%an <%ae>' "$commit")
  message=$(git show -s --format=%B "$commit")

  if [ "$mode" = "--conventional" ]; then
    if ! printf '%s\n' "$subject" | grep -Eq '^(feat|fix|docs|test|refactor|perf|build|ci|chore|revert|style)(\([a-zA-Z0-9_.-]+\))?(!)?: .{3,}$'; then
      printf 'Commit does not follow Conventional Commits: %s\n' "$commit" >&2
      failed=1
    fi
  elif ! printf '%s\n' "$message" | grep -Fxq "Signed-off-by: $author"; then
    printf 'Commit is missing matching DCO Signed-off-by trailer: %s\n' "$commit" >&2
    failed=1
  fi
done

if [ "$failed" -ne 0 ]; then
  exit 1
fi
printf 'Commit policy passed: %s\n' "$mode"
