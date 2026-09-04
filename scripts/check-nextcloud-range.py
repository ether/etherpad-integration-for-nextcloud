#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# appinfo/info.xml declares which Nextcloud majors this app supports, and
# seven other files restate that range: the Psalm matrix analyses its upper
# bound, the e2e matrix runs stacks across it, three stack defaults pick the
# newest, composer.json pins the oldest, and the README tells a user what to
# install. This holds them to it, so widening the range stays a one-file
# change.
#
# Run from the repository root. Prints every mismatch, exits non-zero if
# there is one.

import re
import sys
from pathlib import Path


def read(path):
    return Path(path).read_text(encoding="utf-8")


def declared_range():
    m = re.search(
        r'<nextcloud\s+min-version="(\d+)"\s+max-version="(\d+)"\s*/>',
        read("appinfo/info.xml"),
    )
    if not m:
        sys.exit("could not read <nextcloud min-version max-version> from appinfo/info.xml")
    return int(m.group(1)), int(m.group(2))


def main():
    minimum, maximum = declared_range()
    if minimum > maximum:
        sys.exit(f"appinfo/info.xml declares min-version {minimum} above max-version {maximum}")
    majors = [str(v) for v in range(minimum, maximum + 1)]
    problems = []

    def want(path, pattern, expected, what):
        m = re.search(pattern, read(path))
        if m is None:
            problems.append(f"{path}: could not find {what}")
        elif m.group(1) != expected:
            problems.append(f"{path}: {what} is {m.group(1)!r}, expected {expected!r}")

    # The minimum is what gets installed and analysed by default.
    want("composer.json", r'"nextcloud/ocp":\s*"([^"]+)"', f"^{minimum}",
         "the nextcloud/ocp constraint")

    # The Psalm matrix names the upper bound explicitly; its lower bound is
    # whatever composer.lock pins, which the constraint above already covers.
    want(".github/workflows/psalm.yml", r"ocp:\s*\[([^\]]*)\]", f"'locked', '^{maximum}'",
         "the OCP matrix")

    # Both e2e matrices: a pull request runs the two ends, the nightly run
    # covers every major in the range.
    want(".github/workflows/e2e.yml", r"pull_request'\s*\n\s*&&\s*'\[([^\]]*)\]'",
         ", ".join(f'"{v}"' for v in (str(minimum), str(maximum))),
         "the pull-request Nextcloud list")
    want(".github/workflows/e2e.yml", r"\|\|\s*'\[([^\]]*)\]'\)\s*\}\}",
         ", ".join(f'"{v}"' for v in majors),
         "the nightly Nextcloud list")

    # The throwaway stack defaults to the newest supported major.
    want("tests/e2e/docker/up.sh", r'NC_VERSION="\$\{NC_VERSION:-(\d+)\}"', str(maximum),
         "the up.sh default")
    want("tests/e2e/docker/compose.yml", r"NC_VERSION:\s*\$\{NC_VERSION:-(\d+)\}", str(maximum),
         "the compose default")
    want("tests/e2e/docker/Dockerfile.nextcloud", r"ARG NC_VERSION=(\d+)", str(maximum),
         "the Dockerfile default")

    # What a reader is told to install.
    want("README.md", r"- Nextcloud `(\d+` to `\d+)`", f"{minimum}` to `{maximum}",
         "the supported range")

    if problems:
        print(f"appinfo/info.xml declares Nextcloud {minimum} to {maximum}; it is the source of truth.")
        for problem in problems:
            print(f"::error::{problem}")
        return 1

    print(f"Nextcloud {minimum} to {maximum}: info.xml, composer.json, both CI matrices, "
          "the three stack defaults and the README agree.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
