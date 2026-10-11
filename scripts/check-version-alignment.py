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
`--set <version>` makes it the one writer: scripts/prepare-release.sh bumps the
version through it.
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


# Semver as a release takes it: a -dev version is a working tree's, not a release's.
RELEASE_VERSION = re.compile(r"\d+\.\d+\.\d+(?:-(?!dev$)[0-9A-Za-z.-]+)?")


def replaced_once(text: str, old: str, new: str, start: int = 0) -> str:
    """`text` with the first `old` at or after `start` replaced by `new`."""
    at = text.find(old, start)
    if at < 0:
        raise LookupError(f"{old} not found")

    return text[:at] + new + text[at + len(old) :]


def set_version(root: pathlib.Path, version: str) -> None:
    """Write `version` wherever the app version stands, and change nothing else.

    Each file is edited in place, at the one value: a lockfile written back
    through a JSON dump has every line reformatted, which npm then keeps or
    undoes as it finds it. Each edited JSON file is read back and must equal
    the old one but for the version, and no file is written before all are.
    """
    if RELEASE_VERSION.fullmatch(version) is None:
        raise ValueError(f"{version} is no release version (X.Y.Z, or X.Y.Z-suffix other than -dev)")

    info_xml = root / "appinfo" / "info.xml"
    info_text = info_xml.read_text(encoding="utf-8")
    info_new = replaced_once(info_text, f"<version>{app_version(info_xml)}</version>", f"<version>{version}</version>")

    package_json = root / "package.json"
    package_text = package_json.read_text(encoding="utf-8")
    package = json.loads(package_text)
    package_new = replaced_once(package_text, f'"version": {json.dumps(package["version"])}', f'"version": {json.dumps(version)}')
    expected_package = dict(package, version=version)

    lock_json = root / "package-lock.json"
    lock_text = lock_json.read_text(encoding="utf-8")
    lock = json.loads(lock_text)
    lock_new = replaced_once(lock_text, f'"version": {json.dumps(lock["version"])}', f'"version": {json.dumps(version)}')
    lock_new = replaced_once(
        lock_new,
        f'"version": {json.dumps(lock["packages"][""]["version"])}',
        f'"version": {json.dumps(version)}',
        lock_new.index('"packages"'),
    )
    expected_lock = json.loads(lock_text)
    expected_lock["version"] = version
    expected_lock["packages"][""]["version"] = version

    if json.loads(package_new) != expected_package or json.loads(lock_new) != expected_lock:
        raise LookupError("the version is not where expected in package.json or package-lock.json; nothing written")

    info_xml.write_text(info_new, encoding="utf-8")
    package_json.write_text(package_new, encoding="utf-8")
    lock_json.write_text(lock_new, encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=pathlib.Path, default=ROOT)
    parser.add_argument(
        "--print",
        action="store_true",
        dest="print_only",
        help="print the version from appinfo/info.xml and check nothing",
    )
    parser.add_argument(
        "--set",
        metavar="VERSION",
        dest="new_version",
        help="write VERSION wherever the app version stands, then check",
    )
    arguments = parser.parse_args()

    if arguments.new_version is not None:
        try:
            set_version(arguments.root, arguments.new_version)
        except (LookupError, ValueError, KeyError) as refused:
            print(refused, file=sys.stderr)
            return 1

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
