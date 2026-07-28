#!/usr/bin/env bash
#
# Recurse through a directory, find all photo files, and list the paths of
# any whose EXIF Orientation is present and not equal to 1 (or "Top-left").
#
# Requires: ImageMagick (identify)
#   macOS:  brew install imagemagick
#
# Usage:
#   ./find_orientation.sh "/Volumes/CUNYTVMEDIA/archive_projects/Photos" output.txt

set -euo pipefail

ROOT_DIR="${1:-}"
OUTPUT_FILE="${2:-orientation_not_1.txt}"

if [[ -z "$ROOT_DIR" ]]; then
    echo "Usage: $0 <root_directory> [output.txt]"
    exit 1
fi

if [[ ! -d "$ROOT_DIR" ]]; then
    echo "Error: '$ROOT_DIR' is not a valid directory."
    exit 1
fi

if ! command -v identify &> /dev/null; then
    echo "Error: ImageMagick 'identify' not found. Install with: brew install imagemagick"
    exit 1
fi

# Clear/create output file
> "$OUTPUT_FILE"

total=0
matches=0

echo "Scanning '$ROOT_DIR' for photo files..."
echo ""

# Common photo extensions (case-insensitive)
while IFS= read -r -d '' file; do
    ((total++)) || true

    echo "[$total] Checking: $file"

    # -format "%[EXIF:Orientation]" prints the raw EXIF orientation value (1-8)
    # if present; empty string if not present.
    orientation=$(identify -format "%[EXIF:Orientation]" "$file" 2>/dev/null | head -n1 || true)

    if [[ -n "$orientation" && "$orientation" != "1" ]]; then
        echo "$file"$'\t'"(orientation=$orientation)" >> "$OUTPUT_FILE"
        echo "    -> [MATCH] orientation=$orientation"
        ((matches++)) || true
    fi

    # Periodic summary every 100 files so you can gauge progress on large archives
    if (( total % 100 == 0 )); then
        echo ""
        echo "  --- progress: $total scanned, $matches match(es) so far ---"
        echo ""
    fi
done < <(find "$ROOT_DIR" -type f \( \
        -iname "*.jpg"  -o -iname "*.jpeg" -o \
        -iname "*.tif"  -o -iname "*.tiff" -o \
        -iname "*.png"  -o -iname "*.heic" -o \
        -iname "*.heif" -o -iname "*.bmp"  -o \
        -iname "*.webp" -o -iname "*.gif" \
    \) -print0)

echo ""
echo "Scanned $total photo file(s)."
echo "Found $matches file(s) with orientation != 1."
echo "Results written to: $OUTPUT_FILE"