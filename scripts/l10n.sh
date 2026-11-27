#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Etherpad contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Gettext workflow around the official Nextcloud translationtool, so the
# app follows the same l10n conventions as every Nextcloud app.
#
#   scripts/l10n.sh pot      re-extract translationfiles/templates/etherpad_nextcloud.pot
#                            from t()/n() calls in lib/, templates/, src/ and appinfo/info.xml
#   scripts/l10n.sh convert  rebuild l10n/*.json + l10n/*.js from translationfiles/<lang>/*.po
#   scripts/l10n.sh check    both of the above, then fail if the tree changed
#
# translatewiki.net reads the .pot and writes the .po files. Nothing in
# l10n/ is edited by hand any more; see docs/i18n.md.
#
# Needs php (8.1+) and xgettext (GNU gettext 0.18.3+). PHP=/path/to/php
# overrides the interpreter.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

APP_ID=etherpad_nextcloud
POT="translationfiles/templates/$APP_ID.pot"
PHP="${PHP:-php}"

# Pinned to a nextcloud/docker-ci commit. Bump both together; the checksum
# is of translations/translationtool/translationtool.phar at that commit.
TOOL_COMMIT=6ad4e1aff855f8b3d08dc261f96610787ddb2bcb
TOOL_SHA256=667ca0a3d7ca8858f66f72007792d5bbab867dba6b1bb458d95ec7a74f17385f
TOOL_URL="https://raw.githubusercontent.com/nextcloud/docker-ci/$TOOL_COMMIT/translations/translationtool/translationtool.phar"
TOOL_DIR="${XDG_CACHE_HOME:-$HOME/.cache}/$APP_ID"
TOOL="$TOOL_DIR/translationtool-$TOOL_COMMIT.phar"

# sha256sum on Linux, shasum on macOS.
verify_sha256() {
	if command -v sha256sum >/dev/null; then
		echo "$TOOL_SHA256  $1" | sha256sum --check --status
	else
		echo "$TOOL_SHA256  $1" | shasum -a 256 --check --status
	fi
}

fetch_tool() {
	if [[ -f "$TOOL" ]] && verify_sha256 "$TOOL"; then
		return
	fi
	mkdir -p "$TOOL_DIR"
	curl -fsSL "$TOOL_URL" -o "$TOOL.tmp"
	if ! verify_sha256 "$TOOL.tmp"; then
		rm -f "$TOOL.tmp"
		echo "translationtool.phar checksum mismatch" >&2
		exit 1
	fi
	mv "$TOOL.tmp" "$TOOL"
}

make_pot() {
	fetch_tool
	"$PHP" "$TOOL" create-pot-files >/dev/null
	# xgettext writes absolute source paths. Make them relative so the
	# template is the same on every machine. (sed -i differs between GNU
	# and BSD, so write through a temporary file.)
	sed "s#$ROOT_DIR/##g" "$POT" >"$POT.tmp" && mv "$POT.tmp" "$POT"
}

convert() {
	fetch_tool
	# A locale whose last translation went away must lose its catalog too.
	rm -f l10n/*.js l10n/*.json
	"$PHP" "$TOOL" convert-po-files >/dev/null
}

check() {
	make_pot
	for po in translationfiles/*/"$APP_ID".po; do
		msgfmt --check-format --output-file=/dev/null "$po"
	done
	convert
	# The creation date changes on every extraction and is not a change.
	if ! git diff --quiet -I '^"POT-Creation-Date: ' -- translationfiles l10n \
		|| [[ -n "$(git ls-files --others --exclude-standard -- translationfiles l10n)" ]]; then
		echo >&2
		echo "translationfiles/ or l10n/ is out of date. Run scripts/l10n.sh pot" >&2
		echo "and scripts/l10n.sh convert, and commit the result." >&2
		git status --short -- translationfiles l10n >&2
		git diff -I '^"POT-Creation-Date: ' -- translationfiles l10n | head -n 200 >&2 || true
		exit 1
	fi
}

case "${1:-}" in
	pot) make_pot ;;
	convert) convert ;;
	check) check ;;
	*)
		echo "usage: $0 pot|convert|check" >&2
		exit 2
		;;
esac
