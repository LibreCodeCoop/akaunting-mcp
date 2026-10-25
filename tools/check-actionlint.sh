#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
# Verify upstream's published archive checksum before executing it.
version=1.7.12
expected=8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT HUP INT TERM
url="https://github.com/rhysd/actionlint/releases/download/v${version}/actionlint_${version}_linux_amd64.tar.gz"
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 "$url" --output "$tmp/actionlint.tar.gz"
printf '%s  %s\n' "$expected" "$tmp/actionlint.tar.gz" | sha256sum -c - >/dev/null
tar -xzf "$tmp/actionlint.tar.gz" -C "$tmp" actionlint
"$tmp/actionlint" .github/workflows/*.yml
