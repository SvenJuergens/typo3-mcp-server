#!/usr/bin/env bash

# Deploys the current git HEAD of this extension into a classic-mode (non-composer)
# TYPO3 installation and runs the extension setup (database schema, caches).
# Replaces the manual "build TER zip + upload in the extension manager" workflow.
#
# Usage:
#   Build/deploy-classic.sh /path/to/typo3-root [docker-container-name]
#
# Example (local docker test instance):
#   Build/deploy-classic.sh /Volumes/Workspace/typo3-nc/typo3_src-14.3.0 typo3_src-14.3.0
#
# The TYPO3 root must contain typo3conf/. If a container name is given, the
# bundled-library composer install and the TYPO3 CLI (extension:setup +
# cache:flush) run inside that container, so the host needs neither PHP nor
# composer. The bundled vendor dir survives between deploys, so repeated
# deploys are fast.

set -euo pipefail

TYPO3_ROOT=${1:?Usage: Build/deploy-classic.sh /path/to/typo3-root [docker-container-name]}
CONTAINER=${2:-}

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EXT_DIR="$TYPO3_ROOT/typo3conf/ext/mcp_server"

if [ ! -d "$TYPO3_ROOT/typo3conf" ]; then
    echo "Error: $TYPO3_ROOT does not look like a classic-mode TYPO3 root (no typo3conf/)" >&2
    exit 1
fi

echo "Deploying $(git -C "$REPO_ROOT" rev-parse --short HEAD) ($(git -C "$REPO_ROOT" branch --show-current)) to $EXT_DIR"

if ! git -C "$REPO_ROOT" diff --quiet HEAD -- Classes Configuration Resources ext_localconf.php ext_tables.php ext_tables.sql ext_conf_template.txt ext_emconf.php 2>/dev/null; then
    echo "WARNING: uncommitted changes in extension files are NOT deployed (git HEAD is exported)." >&2
fi

# Export only tracked files of HEAD (no .git, no .Build, no local cruft),
# then sync into the target while keeping the bundled vendor dir alive.
STAGE_DIR="$REPO_ROOT/.Build/deploy-stage"
rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR"
trap 'rm -rf "$STAGE_DIR"' EXIT
git -C "$REPO_ROOT" archive HEAD | tar -x -C "$STAGE_DIR"

mkdir -p "$EXT_DIR"
rsync -a --delete \
    --exclude 'Resources/Private/PHP/vendor/' \
    --exclude 'Resources/Private/PHP/composer.lock' \
    "$STAGE_DIR/" "$EXT_DIR/"

# Classic mode has no root composer autoloader: install the bundled libraries
# (same as Build/build-ter.sh does for the TER zip).
if [ -n "$CONTAINER" ]; then
    echo "Installing bundled libraries in container '$CONTAINER'..."
    docker exec -w /var/www/html/typo3conf/ext/mcp_server/Resources/Private/PHP "$CONTAINER" \
        composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --quiet
    docker exec -w /var/www/html/typo3conf/ext/mcp_server/Resources/Private/PHP "$CONTAINER" \
        rm -rf vendor/psr/log

    echo "Running extension:setup + cache:flush in container '$CONTAINER'..."
    docker exec "$CONTAINER" typo3/sysext/core/bin/typo3 extension:setup --extension=mcp_server
    docker exec "$CONTAINER" typo3/sysext/core/bin/typo3 cache:flush
    echo "Done. Extension deployed and set up."
else
    echo "Installing bundled libraries on the host..."
    (cd "$EXT_DIR/Resources/Private/PHP" \
        && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --quiet \
        && rm -rf vendor/psr/log)
    echo "Done. Now run in your TYPO3 root:"
    echo "  typo3/sysext/core/bin/typo3 extension:setup --extension=mcp_server && typo3/sysext/core/bin/typo3 cache:flush"
fi
