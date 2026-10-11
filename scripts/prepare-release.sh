#!/usr/bin/env bash
#
# Prepares a release in the working tree: the version wherever it stands and
# the bundles built for it, after the release notes and before the version
# check the tag workflow refuses on.
# It commits, pushes and tags nothing - the release goes through a pull
# request and a merge (docs/release-process.md).
#
# Usage:
#   scripts/prepare-release.sh <version>
#
# Needs python3, rsync, network for npm ci, and the Node release.yml builds
# with (engines.node in package.json).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
	echo "Usage: scripts/prepare-release.sh <version>" >&2
	exit 2
fi

for cmd in python3 rsync npm; do
	if ! command -v "$cmd" >/dev/null 2>&1; then
		echo "Missing required command: $cmd" >&2
		exit 2
	fi
done

# The notes are written before this runs; refused here, nothing is changed yet.
echo "[1/4] Release notes"
python3 "$ROOT_DIR/scripts/release-notes.py" "$VERSION" >/dev/null

echo "[2/4] Version $VERSION"
python3 "$ROOT_DIR/scripts/check-version-alignment.py" --set "$VERSION"

echo "[3/4] Bundles"
# Built in a copy of the tree with a node_modules of its own. A node_modules
# linked in from another checkout, as in a git worktree, writes that
# checkout's path into the sourcemaps, and release.yml's check of js/ fails.
# npm ci installs what the lockfile pins, without running packages' install
# scripts, and --engine-strict refuses a Node release.yml would not build
# with.
BUILD_DIR="$(mktemp -d "${TMPDIR:-/tmp}/epnc-release-build.XXXXXX")"
trap 'rm -rf "$BUILD_DIR"' EXIT
rsync -a --exclude=/.git --exclude=/node_modules --exclude=/vendor --exclude=/dist "$ROOT_DIR/" "$BUILD_DIR/"
(cd "$BUILD_DIR" && npm ci --ignore-scripts --engine-strict && npm run build)
rsync -a --delete "$BUILD_DIR/js/" "$ROOT_DIR/js/"

echo "[4/4] The versions, as the tag workflow checks them"
python3 "$ROOT_DIR/scripts/check-version-alignment.py"

echo
git -C "$ROOT_DIR" status --short
echo "-> Review the changes, then commit them in the release pull request."
