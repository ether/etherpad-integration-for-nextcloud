#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Jacob Bühler
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Print the release body for one version.

`docs/release-notes/<version>.md` is where a release gets the prose its readers
see, and it wins where it exists. Where it does not, that version's
`CHANGELOG.md` section is used, so a release still says something.

Whichever it is, it has to say something: a file holding only an SPDX header,
or a section left as `### Fixed` with nothing under it, fails the release
rather than publishing an empty body.

    scripts/release-notes.py 1.1.0-beta.1
"""

from __future__ import annotations

import argparse
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent


def changelog_section(changelog: str, version: str) -> str:
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


def says_something(body: str) -> bool:
    """Whether anything here is content rather than structure.

    Markdown headings and an HTML comment - which is how the SPDX header is
    written in this repository's docs - are not release notes on their own.
    """
    without_comments = re.sub(r"<!--.*?-->", "", body, flags=re.DOTALL)

    return any(
        line.strip() != "" and not line.lstrip().startswith("#")
        for line in without_comments.split("\n")
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("version", help="the released version, without a leading v")
    parser.add_argument("--root", type=pathlib.Path, default=ROOT)
    arguments = parser.parse_args()

    written = arguments.root / "docs" / "release-notes" / f"{arguments.version}.md"
    if written.is_file():
        # Published verbatim, so a licence header the author added out of
        # habit would head the release page and every notification mail.
        body = re.sub(
            r"\A\s*<!--.*?-->\s*", "", written.read_text(encoding="utf-8"), flags=re.DOTALL
        ).strip("\n")
        source = str(written.relative_to(arguments.root))
    else:
        try:
            body = changelog_section(
                (arguments.root / "CHANGELOG.md").read_text(encoding="utf-8"),
                arguments.version,
            )
        except LookupError as missing:
            print(f"{missing} No docs/release-notes/{arguments.version}.md either.", file=sys.stderr)
            return 1
        source = "CHANGELOG.md"

    if not says_something(body):
        print(f"The release notes taken from {source} hold no entries.", file=sys.stderr)
        return 1

    print(f"Release notes from {source}", file=sys.stderr)
    print(body)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
