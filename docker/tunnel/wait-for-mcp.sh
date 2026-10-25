#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Docker's restart policy does not honor Compose startup dependencies after a
# host reboot. The tunnel-client may otherwise perform OAuth discovery before
# "nginx" exists in Docker DNS, permanently disabling its first poll.
set -eu

wait_seconds=${MCP_DEPENDENCY_WAIT_SECONDS:-120}
case "$wait_seconds" in
    ''|*[!0-9]*)
        echo "Invalid MCP dependency wait timeout" >&2
        exit 2
        ;;
esac
# Normalize leading zeroes before arithmetic; POSIX shells may otherwise
# interpret 08 or 09 as invalid octal literals. Bound operational waits.
while [ "${wait_seconds#0}" != "$wait_seconds" ]; do
    wait_seconds=${wait_seconds#0}
done
wait_seconds=${wait_seconds:-0}
if [ "${#wait_seconds}" -gt 4 ] || [ "$wait_seconds" -gt 3600 ]; then
    echo "Invalid MCP dependency wait timeout" >&2
    exit 2
fi
end_at=$(($(date +%s) + wait_seconds))

while :; do
    if getent hosts nginx >/dev/null 2>&1 \
        && wget -q -T 3 -t 1 -O /dev/null \
            http://nginx:8080/.well-known/oauth-protected-resource/mcp; then
        break
    fi
    if [ "$(date +%s)" -ge "$end_at" ]; then
        echo "MCP local dependency not ready; restarting tunnel after bounded wait" >&2
        exit 1
    fi
    sleep 2
done

exec tunnel-client run "$@"
