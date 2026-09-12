#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# appinfo/info.xml declares which PHP versions this app supports, and two
# workflows restate that range as literals: the PHPUnit floor job names the
# oldest, and both test matrices have to reach the newest. This holds them to
# it, so moving the range stays a one-file change - without it, raising
# min-version leaves the jobs testing a floor nobody declares any more, which
# is the gap the floor job was added to close.
#
# Run from the repository root. Prints every mismatch, exits non-zero if
# there is one.

import re
import sys
from pathlib import Path

PHPUNIT = ".github/workflows/phpunit.yml"
LINT = ".github/workflows/lint-php.yml"
FLOOR_JOB = "phpunit-floor"


def read(path):
    """The file with comments stripped, so a commented-out setting cannot
    answer for the active one."""
    out = []
    for line in Path(path).read_text(encoding="utf-8").splitlines():
        quote = None
        for i, ch in enumerate(line):
            if quote:
                if ch == quote:
                    quote = None
            elif ch in "'\"":
                quote = ch
            elif ch == "#":
                line = line[:i]
                break
        out.append(line.rstrip())
    return "\n".join(out)


def job(path, name):
    """One job's own block, so a setting cannot be read from its neighbour."""
    lines = read(path).splitlines()
    for i, line in enumerate(lines):
        if line.strip() == f"{name}:" and line.startswith("  ") and line[2] != " ":
            end = i + 1
            while end < len(lines) and not (
                lines[end].startswith("  ")
                and lines[end][2] != " "
                and lines[end].rstrip().endswith(":")
            ):
                end += 1
            return "\n".join(lines[i:end])
    return None


def declared_range():
    m = re.search(
        r'<php\s+min-version="(\d+\.\d+)"\s+max-version="(\d+\.\d+)"\s*/>',
        read("appinfo/info.xml"),
    )
    if not m:
        sys.exit("could not read <php min-version max-version> from appinfo/info.xml")
    return m.group(1), m.group(2)


def matrix(path):
    """The php-versions list of a workflow's test matrix."""
    found = re.findall(r"php-versions:\s*\[(?P<values>[^\]]*)\]", read(path))
    if len(found) != 1:
        return None
    return re.findall(r"['\"]([^'\"]+)['\"]", found[0])


def floor_job():
    """The version the dedicated floor job pins."""
    block = job(PHPUNIT, FLOOR_JOB)
    if block is None:
        return None
    found = re.findall(r"php-version:\s*['\"](\d+\.\d+)['\"]", block)
    return found[0] if len(found) == 1 else None


def main():
    floor, ceiling = declared_range()
    if tuple(map(int, floor.split("."))) > tuple(map(int, ceiling.split("."))):
        sys.exit(f"appinfo/info.xml declares min-version {floor} above max-version {ceiling}")

    problems = []
    for path in (PHPUNIT, LINT):
        versions = matrix(path)
        if versions is None:
            problems.append(f"{path}: no php-versions matrix found")
            continue
        if ceiling not in versions:
            problems.append(
                f"{path}: matrix {versions} does not run the declared "
                f"max-version {ceiling}"
            )

    pinned = floor_job()
    if pinned is None:
        problems.append(
            f"{PHPUNIT}: job {FLOOR_JOB} is missing, or does not pin exactly one "
            f"php-version"
        )
    elif pinned != floor:
        problems.append(
            f"{PHPUNIT}: floor job runs {pinned}, but appinfo/info.xml "
            f"declares min-version {floor}"
        )

    lint = matrix(LINT) or []
    if floor not in lint:
        problems.append(
            f"{LINT}: matrix {lint} does not run the declared min-version {floor}"
        )

    for problem in problems:
        print(f"::error::{problem}", file=sys.stderr)
    if problems:
        sys.exit(1)
    print(f"PHP range {floor}-{ceiling} is covered by both workflows.")


if __name__ == "__main__":
    main()
