#!/bin/bash
#
# Build ZIP script for Swish Migrate and Backup
# Creates a production-ready ZIP file excluding development files
#

set -e

# Get the directory where the script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(basename "$SCRIPT_DIR")"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"

# Get version from main plugin file (for display only)
VERSION=$(grep -m1 "Version:" "$SCRIPT_DIR/swish-migrate-and-backup.php" | sed 's/.*Version: *//' | tr -d '[:space:]')

# Output filename (matches folder name, no version)
OUTPUT_FILE="$PARENT_DIR/${PLUGIN_DIR}.zip"

echo "Building ZIP for $PLUGIN_DIR version $VERSION..."
echo "Output: $OUTPUT_FILE"

# Change to parent directory so zip paths are correct
cd "$PARENT_DIR"

# Remove old zip if exists
if [ -f "$OUTPUT_FILE" ]; then
    rm "$OUTPUT_FILE"
    echo "Removed existing ZIP file"
fi

# Create zip excluding unwanted files
zip -r "$OUTPUT_FILE" "$PLUGIN_DIR" \
    -x "$PLUGIN_DIR/node_modules/*" \
    -x "$PLUGIN_DIR/.git/*" \
    -x "$PLUGIN_DIR/.claude/*" \
    -x "$PLUGIN_DIR/.*" \
    -x "$PLUGIN_DIR/*/.*" \
    -x "$PLUGIN_DIR/*/*/.*" \
    -x "$PLUGIN_DIR/*/*/*/.*" \
    -x "$PLUGIN_DIR/*.log" \
    -x "$PLUGIN_DIR/build-zip.sh" \
    -x "$PLUGIN_DIR/package-lock.json" \
    -x "$PLUGIN_DIR/composer.lock" \
    -x "$PLUGIN_DIR/phpcs.xml" \
    -x "$PLUGIN_DIR/phpunit.xml" \
    -x "$PLUGIN_DIR/.phpcs.xml.dist" \
    -x "$PLUGIN_DIR/tests/*" \
    -x "$PLUGIN_DIR/src/js/*" \
    -x "$PLUGIN_DIR/src/css/*"

echo ""
echo "✓ ZIP created successfully: $OUTPUT_FILE"
echo "  Size: $(du -h "$OUTPUT_FILE" | cut -f1)"

# List contents for verification
echo ""
echo "ZIP contents (top-level):"
unzip -l "$OUTPUT_FILE" | head -30
