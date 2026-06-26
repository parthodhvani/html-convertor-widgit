#!/usr/bin/env bash
#
# Build a distributable, installable plugin ZIP.
#
# Produces dist/html-to-elementor-fidelity-importer.zip containing the plugin
# with Composer + Chromium-service dependencies installed and dev cruft removed.

set -euo pipefail

PLUGIN_SLUG="html-to-elementor-fidelity-importer"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
STAGE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
DIST_DIR="${ROOT_DIR}/dist"

echo "==> Cleaning previous build"
rm -rf "${BUILD_DIR}" "${DIST_DIR}"
mkdir -p "${STAGE_DIR}" "${DIST_DIR}"

echo "==> Installing PHP production dependencies"
( cd "${ROOT_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction )

echo "==> Installing Chromium service dependencies"
( cd "${ROOT_DIR}/chromium-service" && npm install --omit=dev )

echo "==> Staging plugin files"
rsync -a --delete \
  --exclude ".git" \
  --exclude "build" \
  --exclude "dist" \
  --exclude "node_modules" \
  --exclude "tests" \
  --exclude ".github" \
  "${ROOT_DIR}/" "${STAGE_DIR}/"

# Keep chromium-service node_modules (runtime dependency), drop root dev modules.
rm -rf "${STAGE_DIR}/node_modules"

echo "==> Creating ZIP"
( cd "${BUILD_DIR}" && zip -rq "${DIST_DIR}/${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}" )

echo "==> Done: ${DIST_DIR}/${PLUGIN_SLUG}.zip"
