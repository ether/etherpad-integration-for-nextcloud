#!/usr/bin/env bash
#
# Build a Nextcloud-App-Store-conformant release tarball for the
# etherpad_nextcloud app.
#
# Usage:
#   scripts/build-release-tarball.sh [output-dir]
#
# The version is read from appinfo/info.xml so we don't drift. The
# resulting archive is named etherpad_nextcloud-<version>.tar.gz and
# has a top-level directory of "etherpad_nextcloud/" so an admin can
# extract it directly into <nextcloud-root>/apps/.
#
# macOS specifics:
#   - COPYFILE_DISABLE=1 prevents BSD tar from embedding extended-
#     attribute sidecars (the AppleDouble `._*` files) inside the
#     archive. Without it, the archive listed clean on macOS but
#     extracted with stray `._*` files on Linux.
#   - --no-mac-metadata (available on newer macOS tar) is set when
#     supported as a second line of defence.
#   - --no-xattrs drops extended attributes, which travel as pax
#     headers rather than as sidecar files. macOS puts
#     com.apple.provenance on everything it downloads, and neither of
#     the two above removes it; GNU tar warns about each one it cannot
#     read on extraction.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_DIR="${1:-$ROOT_DIR/dist}"

APP_ID="etherpad_nextcloud"
INFO_XML="$ROOT_DIR/appinfo/info.xml"
if [[ ! -f "$INFO_XML" ]]; then
	echo "ERROR: $INFO_XML not found" >&2
	exit 1
fi

# Read the <version> element from info.xml without depending on an XML
# parser. The expected line is:  <version>1.2.3-alpha.4</version>
VERSION="$(grep -oE '<version>[^<]+</version>' "$INFO_XML" | sed -E 's|</?version>||g' | head -n1)"
if [[ -z "$VERSION" ]]; then
	echo "ERROR: could not read <version> from $INFO_XML" >&2
	exit 1
fi

# Reject -dev versions — a dev marker means the working tree isn't
# tagged for release. Force the caller to bump info.xml first.
if [[ "$VERSION" == *-dev ]]; then
	echo "ERROR: appinfo/info.xml version is '$VERSION' (dev marker)." >&2
	echo "       Bump the version before building a release tarball." >&2
	exit 1
fi

STAGE_DIR="$(mktemp -d -t epnc-release-stage)"
trap 'rm -rf "$STAGE_DIR"' EXIT

APP_STAGE="$STAGE_DIR/$APP_ID"
mkdir -p "$APP_STAGE"

# What ends up in the tarball: everything Nextcloud needs at runtime
# (lib, appinfo, templates, l10n, css, js, img, docs, top-level
# README/LICENSE/CHANGELOG/THIRD_PARTY_NOTICES). Source / dev /
# tooling / personal artefacts are excluded.
# The shipped-tree definition lives next door; see that file for why.
source "$(dirname "${BASH_SOURCE[0]}")/app-file-excludes.sh"

rsync -a "${RSYNC_EXCLUDES[@]}" "$ROOT_DIR/" "$APP_STAGE/"

mkdir -p "$OUTPUT_DIR"
ARTIFACT="$OUTPUT_DIR/$APP_ID-$VERSION.tar.gz"

# Build the archive with macOS metadata-pollution defences enabled.
# The two flags are extensions that older `tar` builds do not know, so
# each is tried and dropped in turn down to the plain COPYFILE_DISABLE=1
# path. The checks below decide whether what came out is clean.
cd "$STAGE_DIR"
if ! COPYFILE_DISABLE=1 tar --no-mac-metadata --no-xattrs -czf "$ARTIFACT" "$APP_ID" 2>/dev/null; then
	if ! COPYFILE_DISABLE=1 tar --no-mac-metadata -czf "$ARTIFACT" "$APP_ID" 2>/dev/null; then
		COPYFILE_DISABLE=1 tar -czf "$ARTIFACT" "$APP_ID"
	fi
fi

# Verify: zero AppleDouble or .DS_Store entries in the published
# archive. Belt-and-braces — if the defences above ever silently
# drop, the build fails loudly instead of shipping a polluted
# release.
LEAKED="$(tar -tzf "$ARTIFACT" | grep -E '(^|/)\._|(^|/)\.DS_Store$' || true)"
if [[ -n "$LEAKED" ]]; then
	echo "ERROR: tarball contains macOS metadata files:" >&2
	echo "$LEAKED" >&2
	exit 1
fi

# Extended attributes carry no file of their own, so the listing above
# cannot see them. They are in the byte stream as pax headers.
#
# Counted rather than matched: `grep -q` stops at the first hit, which
# closes the pipe under the decompressor, and `pipefail` then reads that
# as a failed pipeline - so the test would say "clean" for exactly the
# archives it is meant to catch.
XATTR_HEADERS="$(gzip -dc "$ARTIFACT" | grep -ac -e 'LIBARCHIVE.xattr' -e 'SCHILY.xattr' || true)"
if [[ "$XATTR_HEADERS" != "0" ]]; then
	echo "ERROR: tarball carries extended attributes as pax headers ($XATTR_HEADERS)." >&2
	exit 1
fi

SIZE_HUMAN="$(du -h "$ARTIFACT" | awk '{print $1}')"
ENTRY_COUNT="$(tar -tzf "$ARTIFACT" | wc -l | tr -d ' ')"

echo "Built: $ARTIFACT"
echo "Size:  $SIZE_HUMAN"
echo "Files: $ENTRY_COUNT"
