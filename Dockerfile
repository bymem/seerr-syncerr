FROM php:8.3-cli-alpine

RUN apk add --no-cache curl tzdata shadow su-exec

WORKDIR /app

# Baked in at build time by the GitHub Actions workflow (git tag, or
# main-<shortsha> for untagged builds) — a plain `docker build .` with no
# --build-arg falls back to "dev" so local builds still work.
ARG APP_VERSION=dev
ENV APP_VERSION=$APP_VERSION

COPY public/ /app/public/
COPY src/ /app/src/
COPY templates/ /app/templates/
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV PUID=1000
ENV PGID=1000
ENV TZ=UTC
ENV PORT=8070

VOLUME ["/config"]
EXPOSE 8070

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f "http://127.0.0.1:${PORT}/healthz" || exit 1

ENTRYPOINT ["/entrypoint.sh"]
