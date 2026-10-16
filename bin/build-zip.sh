#!/usr/bin/env bash
# Builds sebo-call-tracking.zip with the plugin in a sebo-call-tracking/ folder, as WordPress installs it.
set -euo pipefail
cd "$(dirname "$0")/.."
out="$PWD/sebo-call-tracking.zip"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir "$tmp/sebo-call-tracking"
rsync -a --exclude .git --exclude .github --exclude tests --exclude bin --exclude '*.zip' --exclude .DS_Store ./ "$tmp/sebo-call-tracking/"
rm -f "$out"
(cd "$tmp" && zip -qr "$out" sebo-call-tracking)
echo "$out"
