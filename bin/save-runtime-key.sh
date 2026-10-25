#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Enter an OpenAI restricted tunnel runtime API key without writing it to shell history.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
umask 077
mkdir -p secrets
chmod 700 secrets
target=secrets/openai_tunnel_runtime_api_key
if [[ -e "$target" ]]; then
    printf 'A chave de execucao ja existe. Nao foi substituida.\n' >&2
    exit 1
fi
if [[ ! -t 0 ]]; then
    printf 'Execute este script num terminal SSH interativo (ssh -t).\n' >&2
    exit 1
fi
printf 'Cole sua chave OpenAI restrita a Tunnels Read + Use (entrada oculta): ' >&2
IFS= read -r -s key
printf '\n' >&2
if [[ "$key" != sk-* || ${#key} -lt 30 ]]; then
    unset key
    printf 'Formato de chave invalido. Nada foi gravado.\n' >&2
    exit 1
fi
tmp="$(mktemp secrets/.openai-runtime-XXXXXXXX)"
trap 'rm -f "$tmp"' EXIT
printf '%s\n' "$key" > "$tmp"
unset key
chmod 600 "$tmp"
mv "$tmp" "$target"
trap - EXIT
printf 'Chave instalada com permissao 0600; conteudo nao exibido.\n'
