#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Jacob Bühler
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Print one released version's section of CHANGELOG.md.

The release workflow turns a tag into release notes with this, so a tag whose
version has no section - or an empty one - fails the release instead of
publishing a release that says nothing.

    scripts/changelog-section.py 1.1.0-beta.1
"""

from __future__ import annotations

import argparse
import pathlib
import re
import sys


def section(changelog: str, version: str) -> str:
    """The lines under `## <version>`, up to the next section."""
    heading = re.compile(r"^##\s+" + re.escape(version) + r"(?:\s|$)")
    lines = changelog.split("\n")

    for index, line in enumerate(lines):
        if not heading.match(line):
            continue
        body: list[str] = []
        for following in lines[index + 1 :]:
            if following.startswith("## "):
                break
            body.append(following)
        return "\n".join(body).strip("\n")

    raise LookupError(f"CHANGELOG.md has no section for {version}.")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("version", help="the released version, without a leading v")
    parser.add_argument(
        "--changelog",
        type=pathlib.Path,
        default=pathlib.Path(__file__).resolve().parent.parent / "CHANGELOG.md",
    )
    arguments = parser.parse_args()

    try:
        body = section(arguments.changelog.read_text(encoding="utf-8"), arguments.version)
    except LookupError as missing:
        print(missing, file=sys.stderr)
        return 1

    # Category headings are not content: a section left as "### Fixed" with
    # nothing under it would otherwise be published as the release notes.
    if not any(
        line.strip() != "" and not line.lstrip().startswith("#")
        for line in body.split("\n")
    ):
        print(f"CHANGELOG.md section for {arguments.version} has no entries.", file=sys.stderr)
        return 1

    print(body)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
