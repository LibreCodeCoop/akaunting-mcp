# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
FROM php:8.5-fpm-alpine@sha256:75bbd8240bc8a991b0f8ddb2365f11937f93199972e3c9f7e32c63c7241f546b
RUN apk add --no-cache libcurl libsodium \
    && apk add --no-cache --virtual .mcp-build-deps curl-dev libsodium-dev \
    && docker-php-ext-install curl sodium \
    && apk del .mcp-build-deps
WORKDIR /app
COPY --chown=10001:10001 src/ /app/src/
COPY --chown=10001:10001 public/mcp.php public/oauth.php /app/public/
# The interactive key installer and catalog generator run outside production.
# Keep only the audited revocation command as an operational runtime entrypoint.
COPY --chown=10001:10001 bin/revoke-user.php /app/bin/revoke-user.php
RUN mkdir -p /app/var && chown -R 10001:10001 /app/var \
    && printf 'expose_php=Off\n' > /usr/local/etc/php/conf.d/security.ini
USER 10001:10001
CMD ["php-fpm", "-F"]
