#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Etherpad contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Parse-lint every PHP file in the checkout.
#
# By subtraction, because a list of what to read has to be kept level
# with the tree and nothing complains when it is not. What matters most
# is what is easiest to leave off: templates/ and appinfo/routes.php are
# shipped and executed, and nothing else looks at them - psalm analyses
# lib alone, and phpunit does not run a template.
#
# What is subtracted is named rather than pathed, and pruned rather than
# filtered. A path anchored at the root misses a node_modules that turns
# up deeper in the tree, and filtering descends into what it is about to
# discard - most of the walk, and felt over a bind mount.
#
# Wider than the tarball, deliberately. A test is not shipped, but it is
# PHP this project runs, and a mistake that only the oldest PHP rejects
# is what a parse-lint against the floor is for.
#
# Run it against the lowest supported PHP before pushing, which is the
# version a local one is least likely to be:
#
#   docker run --rm -v "$PWD:/w" -w /w php:8.1-cli scripts/lint-php.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

list="$(mktemp "${TMPDIR:-/tmp}/lint-php.XXXXXX")"
trap 'rm -f "$list"' EXIT

php_files() {
	find . \
		\( -name vendor -o -name node_modules -o -name dist -o -name .git \) -prune \
		-o -type f -name '*.php' -print0
}

# On its own line rather than in a pipeline: under pipefail a find that
# stumbles on an unreadable directory would end the script, and the
# caller would read that as a file that did not parse.
if ! php_files > "$list"; then
	echo "Could not list PHP files under $ROOT_DIR" >&2
	exit 1
fi

# Counted off the same NUL-separated stream the lint reads, so the two
# cannot disagree about a name with a newline in it.
count=$(tr -cd '\0' < "$list" | wc -c | tr -d ' ')

# xargs given nothing does nothing and succeeds, so a moved script or a
# prune that grew would pass while reading not one file. The printed
# count does not catch that on its own: nobody reads a number in a green
# log.
if [ "$count" -eq 0 ]; then
	echo "No PHP files found - has this script moved, or a prune grown?" >&2
	exit 1
fi

echo "Parse-linting $count files with PHP $(php -r 'echo PHP_VERSION;')"

# Through sh rather than calling php directly: php -l exits 255 on a
# parse error, and xargs reads 255 as "stop now" - so a direct call
# names an arbitrary few of the broken files and never looks at the
# rest.
xargs -0 -n1 -P4 sh -c 'php -l "$1" > /dev/null || exit 1' _ < "$list"
