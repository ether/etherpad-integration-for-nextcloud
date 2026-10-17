#!/usr/bin/env bash
#
# Prepares a release in the working tree: the version wherever it stands and
# the bundles built for it, once the CHANGELOG section and the release notes
# are written. It commits, pushes and tags nothing - the release goes through
# a pull request and a merge (docs/release-process.md).
#
# Usage:
#   scripts/prepare-release.sh <version>
#
# Needs python3, git, rsync, network for npm ci, and the Node release.yml
# builds with (engines.node in package.json).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
	echo "Usage: scripts/prepare-release.sh <version>" >&2
	exit 2
fi

for cmd in python3 git rsync npm; do
	if ! command -v "$cmd" >/dev/null 2>&1; then
		echo "Missing required command: $cmd" >&2
		exit 2
	fi
done

# Refused here, nothing is changed yet.
echo "[1/3] Changelog, release notes and the working tree"
python3 "$ROOT_DIR/scripts/release-notes.py" "$VERSION" >/dev/null
# js/ is built from what the release pull request commits. The two texts may
# still be uncommitted; anything else would go into the bundles unreviewed.
dirty="$(git -C "$ROOT_DIR" status --porcelain --untracked-files=all | grep -vE '^.. (CHANGELOG\.md|docs/release-notes/)' || true)"
if [[ -n "$dirty" ]]; then
	echo "Uncommitted changes besides CHANGELOG.md and docs/release-notes/:" >&2
	echo "$dirty" >&2
	exit 1
fi
if [[ -n "$(git -C "$ROOT_DIR" tag -l "v$VERSION")" ]] \
	|| git -C "$ROOT_DIR" ls-remote --exit-code --tags origin "refs/tags/v$VERSION" >/dev/null 2>&1; then
	echo "v$VERSION is tagged already: that release is out." >&2
	exit 1
fi

echo "[2/3] Version $VERSION and bundles, in a copy"
# Built in a copy of the tree with a node_modules of its own. A node_modules
# linked in from another checkout, as in a git worktree, writes that
# checkout's path into the sourcemaps, and release.yml's check of js/ fails.
# The copy holds the files git knows and does not ignore - not the reports,
# caches and test credentials beside them. The version is set and the
# bundles are built there first, so that a version refused, a Node release.yml
# would not build with, or no network for npm ci leaves the working tree as
# it was. npm ci installs what the lockfile pins, without running packages'
# install scripts, and --engine-strict refuses that Node.
BUILD_DIR="$(mktemp -d "${TMPDIR:-/tmp}/epnc-release-build.XXXXXX")"
trap 'rm -rf "$BUILD_DIR"' EXIT
git -C "$ROOT_DIR" ls-files -z --cached --others --exclude-standard \
	| rsync -a --from0 --files-from=- "$ROOT_DIR/" "$BUILD_DIR/"
python3 "$ROOT_DIR/scripts/check-version-alignment.py" --root "$BUILD_DIR" --set "$VERSION" >/dev/null
(cd "$BUILD_DIR" && npm ci --ignore-scripts --engine-strict && npm run build)

echo "[3/3] Version $VERSION and bundles, in the working tree"
python3 "$ROOT_DIR/scripts/check-version-alignment.py" --set "$VERSION"
rsync -a --delete "$BUILD_DIR/js/" "$ROOT_DIR/js/"

echo
git -C "$ROOT_DIR" status --short
echo "-> Review the changes, then commit them in the release pull request."
