#!/bin/sh

set -eu

CLI_BIN="${GLITCHTIP_CLI_BIN:-glitchtip-cli}"
BUILD_DIR="${GLITCHTIP_BUILD_DIR:-./public/build}"

require_env() {
    VAR_NAME="$1"
    VALUE="$(printenv "$VAR_NAME" || true)"

    if [ -z "$VALUE" ]; then
        echo "Missing required environment variable: $VAR_NAME" >&2
        exit 1
    fi
}

require_env SENTRY_URL
require_env SENTRY_AUTH_TOKEN
require_env SENTRY_ORG
require_env SENTRY_PROJECT
require_env SENTRY_RELEASE

if [ ! -d "$BUILD_DIR" ]; then
    echo "Build directory not found: $BUILD_DIR" >&2
    echo "Run a production build first. Example: VITE_BUILD_SOURCEMAP=true npm run build" >&2
    exit 1
fi

echo "Injecting debug IDs into $BUILD_DIR"
"$CLI_BIN" sourcemaps inject "$BUILD_DIR"

echo "Uploading source maps to GlitchTip"
"$CLI_BIN" sourcemaps upload "$BUILD_DIR" \
    --release "$SENTRY_RELEASE" \
    --org "$SENTRY_ORG" \
    --project "$SENTRY_PROJECT"

echo "GlitchTip source map upload complete."
