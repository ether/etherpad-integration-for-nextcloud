#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Jacob Bühler
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Check that every file restating the app version agrees with appinfo/info.xml.

`appinfo/info.xml` is the single source of truth. `package.json` and both
version fields in `package-lock.json` are aligned by hand (#119), and the built
bundles take their version from `package.json` - so drift there ships
`js/*.license` files naming a version the app does not have.

Printing the version with `--print` makes this the one reader of info.xml, so
the release workflow and the tarball builder do not each carry their own.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent


def app_version(info_xml: pathlib.Path) -> str:
    """The <version> appinfo/info.xml declares."""
    match = re.search(r"<version>([^<]+)</version>", info_xml.read_text(encoding="utf-8"))
    if match is None:
        raise LookupError(f"could not read <version> from {info_xml}")

    return match.group(1).strip()


def restated_versions(root: pathlib.Path) -> dict[str, str]:
    """Where else the version is written down, by a name for the message."""
    package = json.loads((root / "package.json").read_text(encoding="utf-8"))
    lock = json.loads((root / "package-lock.json").read_text(encoding="utf-8"))

    return {
        "package.json": package.get("version", ""),
        "package-lock.json (.version)": lock.get("version", ""),
        'package-lock.json (.packages[""].version)': lock.get("packages", {}).get("", {}).get("version", ""),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=pathlib.Path, default=ROOT)
    parser.add_argument(
        "--print",
        action="store_true",
        dest="print_only",
        help="print the version from appinfo/info.xml and check nothing",
    )
    arguments = parser.parse_args()

    try:
        version = app_version(arguments.root / "appinfo" / "info.xml")
    except LookupError as unreadable:
        print(unreadable, file=sys.stderr)
        return 1

    if arguments.print_only:
        print(version)
        return 0

    restated = restated_versions(arguments.root)
    print(f"{'appinfo/info.xml':<42} {version}")
    for name, value in restated.items():
        print(f"{name:<42} {value}")

    disagreeing = {name: value for name, value in restated.items() if value != version}
    if disagreeing:
        print(
            f"Version mismatch. appinfo/info.xml is the source of truth ({version}); "
            f"align {', '.join(disagreeing)} to it.",
            file=sys.stderr,
        )
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
