#!/usr/bin/env bash
# Explicit preprocessing only; never run during installation.
set -Eeuo pipefail
[[ $# == 3 ]] || { echo "Usage: $0 regional.osm.pbf west,south,east,north output.osm" >&2; exit 1; }
command -v osmium >/dev/null || { echo 'Install osmium-tool first' >&2; exit 1; }
[[ -f "$1" && ! -e "$3" && "$3" == *.osm ]] || { echo 'Input must exist and output must be a new .osm file' >&2; exit 1; }
tmp_dir="$(mktemp -d)"
trap 'rm -f "$tmp_dir/clipped.osm.pbf" "$tmp_dir/roads.osm.pbf"; rmdir "$tmp_dir"' EXIT
echo 'Clipping complete ways…'
osmium extract --strategy=complete_ways --bbox="$2" "$1" --output="$tmp_dir/clipped.osm.pbf"
echo 'Keeping driving roads and their nodes (including control tags)…'
osmium tags-filter "$tmp_dir/clipped.osm.pbf" 'w/highway=motorway,trunk,primary,secondary,tertiary,unclassified,residential,living_street,service' --output="$tmp_dir/roads.osm.pbf"
osmium cat "$tmp_dir/roads.osm.pbf" --output="$3"
echo "Prepared $3. Queue explicitly with bin/import.php; coverage must match the clipping box."
