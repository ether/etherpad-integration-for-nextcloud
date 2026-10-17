#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Jacob Bühler
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Check that every file restating the app version agrees with appinfo/info.xml.

`appinfo/info.xml` is the single source of truth. `package.json` and both
version fields in `package-lock.json` restate it (#119), and the built bundles
take their version from `package.json` - so drift there ships `js/*.license`
files naming a version the app does not have.

`--print` gives the version info.xml declares to the release workflow.
`--set <version>` writes it in all four places without reformatting the
files: scripts/prepare-release.sh bumps the version through it.
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


# Semver 2.0.0, whose leading zeros and empty identifiers npm refuses, without
# the build metadata Nextcloud's app-info.xsd refuses. ASCII digits only, as
# Python's \d takes other scripts' digits too.
_NUMBER = r"(?:0|[1-9][0-9]*)"
_IDENTIFIER = r"(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)"
RELEASE_VERSION = re.compile(rf"{_NUMBER}\.{_NUMBER}\.{_NUMBER}(?:-{_IDENTIFIER}(?:\.{_IDENTIFIER})*)?")
# A dev marker anywhere in the pre-release is a working tree's version, not a
# release's; build-release-tarball.sh refuses the ones ending in -dev.
_DEV = re.compile(r"(?:^|[.-])dev(?:$|[.-])", re.IGNORECASE)


def precedence(version: str) -> tuple:
    """A key that orders versions as semver does: a pre-release below its
    release, numeric identifiers below others and by value, a longer set of
    identifiers above a shorter one it starts with."""
    core, _, pre = version.partition("-")
    numbers = tuple(int(part) for part in core.split("."))
    if pre == "":
        return (numbers, 1, ())
    return (numbers, 0, tuple((0, int(part), "") if part.isdigit() else (1, 0, part) for part in pre.split(".")))


def parsed(text: str, where: str) -> dict:
    """`text` as JSON, or a ValueError that names the file."""
    try:
        return json.loads(text)
    except json.JSONDecodeError as broken:
        raise ValueError(f"{where} is no JSON ({broken}); nothing written") from None


def field(data: dict, path: tuple[str, ...], where: str) -> str:
    """The value at `path`, or a LookupError that names the file and the field."""
    value = data
    for key in path:
        if not isinstance(value, dict) or key not in value:
            name = "".join('[""]' if key == "" else f".{key}" for key in path).lstrip(".")
            raise LookupError(f"{where} has no {name}; nothing written")
        value = value[key]
    return value


def replaced_once(text: str, old: str, new: str, where: str, start: int = 0) -> str:
    """`text` with the first `old` at or after `start` replaced by `new`."""
    at = text.find(old, start)
    if at < 0:
        raise LookupError(f"{old} not found in {where}; nothing written")

    return text[:at] + new + text[at + len(old) :]


def set_version(root: pathlib.Path, version: str) -> None:
    """Write `version` wherever the app version stands, and change nothing else.

    Each file is edited in place, at the one value: a lockfile written back
    through a JSON dump has every line reformatted, which npm then keeps or
    undoes as it finds it. Each edited JSON text is parsed and compared with
    the old one but for the version before any file is written. The files are
    then written one after another, so a failed write can leave some of them
    changed.
    """
    if RELEASE_VERSION.fullmatch(version) is None:
        raise ValueError(f"{version} is no release version: semver X.Y.Z or X.Y.Z-prerelease, no leading zeros")
    if _DEV.search(version.partition("-")[2]):
        raise ValueError(f"{version} is a development version, not a release")

    info_xml = root / "appinfo" / "info.xml"
    info_text = info_xml.read_text(encoding="utf-8")
    current = app_version(info_xml)
    # A release follows the one before it, so a lower version is a typo. The
    # same one again is a release being prepared anew; a dev version in
    # info.xml says nothing of the last release.
    if RELEASE_VERSION.fullmatch(current) and not _DEV.search(current.partition("-")[2]) and precedence(version) < precedence(current):
        raise ValueError(f"{version} is below {current}, the version appinfo/info.xml has")
    info_new = replaced_once(info_text, f"<version>{current}</version>", f"<version>{version}</version>", "appinfo/info.xml")

    package_json = root / "package.json"
    package_text = package_json.read_text(encoding="utf-8")
    package = parsed(package_text, "package.json")
    package_new = replaced_once(package_text, f'"version": {json.dumps(field(package, ("version",), "package.json"))}', f'"version": {json.dumps(version)}', "package.json")
    expected_package = dict(package, version=version)

    lock_json = root / "package-lock.json"
    lock_text = lock_json.read_text(encoding="utf-8")
    lock = parsed(lock_text, "package-lock.json")
    lock_new = replaced_once(lock_text, f'"version": {json.dumps(field(lock, ("version",), "package-lock.json"))}', f'"version": {json.dumps(version)}', "package-lock.json")
    lock_new = replaced_once(
        lock_new,
        f'"version": {json.dumps(field(lock, ("packages", "", "version"), "package-lock.json"))}',
        f'"version": {json.dumps(version)}',
        "package-lock.json",
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
        except (LookupError, ValueError) as refused:
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
