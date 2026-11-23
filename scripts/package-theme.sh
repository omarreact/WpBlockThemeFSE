#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$ROOT_DIR/build/package"
DIST_DIR="$ROOT_DIR/dist"
THEME_DIR="$BUILD_DIR/pin-universal-fse"
ZIP_FILE="$DIST_DIR/pin-universal-fse.zip"

rm -rf "$BUILD_DIR" "$DIST_DIR"
mkdir -p "$THEME_DIR" "$DIST_DIR"

required_files=(
  "README.txt"
  "functions.php"
  "screenshot.png"
  "style.css"
  "theme.json"
)

required_dirs=(
  "inc"
  "parts"
  "patterns"
  "plugin"
  "styles"
  "templates"
)

for item in "${required_files[@]}"; do
  test -f "$ROOT_DIR/$item"
  cp "$ROOT_DIR/$item" "$THEME_DIR/$item"
done

for item in "${required_dirs[@]}"; do
  test -d "$ROOT_DIR/$item"
  cp -R "$ROOT_DIR/$item" "$THEME_DIR/$item"
done

test -f "$THEME_DIR/plugin/pin-site-importer/pin-site-importer.php"
test -f "$THEME_DIR/inc/companion-plugin-installer.php"

(
  cd "$BUILD_DIR"
  zip -qr "$ZIP_FILE" pin-universal-fse
)

echo "$ZIP_FILE"
