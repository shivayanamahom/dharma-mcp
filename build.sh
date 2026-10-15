#!/bin/bash
set -e

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="$PROJECT_DIR/dist"
STAGE_DIR="$PROJECT_DIR/stage"

VERSION="0.1.0"
ZIP_NAME="com_dharmashop_mcp-$VERSION.zip"

rm -rf "$DIST_DIR" "$STAGE_DIR"
mkdir -p "$DIST_DIR" "$STAGE_DIR"

echo "Building com_dharmashop_mcp v$VERSION..."

cp -R "$PROJECT_DIR/com_dharmashop_mcp/"* "$STAGE_DIR/"

# Remove unnecessary files
find "$STAGE_DIR" -name ".DS_Store" -delete

cd "$STAGE_DIR"
zip -r "$DIST_DIR/$ZIP_NAME" . -x "*.git*"

cd "$PROJECT_DIR"
rm -rf "$STAGE_DIR"

echo "Build complete: $DIST_DIR/$ZIP_NAME"
