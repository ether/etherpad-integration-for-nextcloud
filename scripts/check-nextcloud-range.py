#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# appinfo/info.xml declares which Nextcloud versions this app supports, and
# eight other files restate that range: the Psalm matrix analyses its upper
# bound, the e2e matrix runs stacks across it, three stack defaults pick the
# newest, composer.json pins the oldest, the README tells a user what to
# install, and the e2e stack's README, up.sh and compose header tell a
# developer what to run. This holds them to it, so moving the range stays a
# one-file change.
#
# The floor may name a patch. Nextcloud compares a requirement at whatever
# precision it is written, so "31.0.9" is refused on 31.0.8 where a bare
# "31" is not - and the majors to iterate come from its first segment.
#
# Run from the repository root. Prints every mismatch, exits non-zero if
# there is one.

import re
import sys
from pathlib import Path


def read(path):
    return Path(path).read_text(encoding="utf-8")


def declared_range():
    """The floor as declared, the major it belongs to, and the ceiling."""
    m = re.search(
        r'<nextcloud\s+min-version="(\d+(?:\.\d+){0,2})"\s+max-version="(\d+)"\s*/>',
        read("appinfo/info.xml"),
    )
    if not m:
        sys.exit("could not read <nextcloud min-version max-version> from appinfo/info.xml")
    floor = m.group(1)
    return floor, int(floor.split(".")[0]), int(m.group(2))


def main():
    floor, minimum, maximum = declared_range()
    if minimum > maximum:
        sys.exit(f"appinfo/info.xml declares min-version {floor} above max-version {maximum}")
    # The floor stands in for its own major, so the lower end of every list
    # is the exact version the declaration admits rather than whatever the
    # moving major tag resolves to today.
    majors = [floor] + [str(v) for v in range(minimum + 1, maximum + 1)]
    # Both ends of a single-major range are the same version, and asking for
    # the bare major alongside the floor would put back the moving tag.
    ends = majors if len(majors) == 1 else [floor, str(maximum)]
    problems = []

    def want(path, pattern, expected, what):
        try:
            text = read(path)
        except OSError:
            problems.append(f"{path}: file not found")
            return
        m = re.search(pattern, text)
        if m is None:
            problems.append(f"{path}: could not find {what}")
        elif m.group(1) != expected:
            problems.append(f"{path}: {what} is {m.group(1)!r}, expected {expected!r}")

    # The minimum is what gets installed and analysed by default.
    want("composer.json", r'"nextcloud/ocp":\s*"([^"]+)"', f"^{floor}",
         "the nextcloud/ocp constraint")

    # The constraint is open above, so it alone does not keep the analysed
    # stubs at the floor: a routine update moves the lock to a newer patch,
    # and the Psalm job documented as "the declared minimum" then accepts an
    # API the declared minimum does not have.
    want("composer.lock", r'"name":\s*"nextcloud/ocp",\s*\n\s*"version":\s*"v([^"]+)"',
         floor, "the nextcloud/ocp version in the lock")

    # The Psalm matrix names the upper bound explicitly; its lower bound is
    # whatever composer.lock pins, which the constraint above already covers.
    want(".github/workflows/psalm.yml", r"ocp:\s*\[([^\]]*)\]", f"'locked', '^{maximum}'",
         "the OCP matrix")

    # Both e2e matrices: a pull request runs the two ends, the nightly run
    # covers every major in the range.
    want(".github/workflows/e2e.yml", r"pull_request'\s*\n\s*&&\s*'\[([^\]]*)\]'",
         ", ".join(f'"{v}"' for v in ends),
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
    want("README.md", r"- Nextcloud `([\d.]+` to `\d+)`", f"{floor}` to `{maximum}",
         "the supported range")

    # And what a developer is told to run. These drifted twice while this
    # check watched the four files above and not these.
    want("tests/e2e/docker/README.md", r"declares — ([\d.]+ and \d+) — and the",
         f"{floor} and {maximum}", "the range named in the e2e README")
    if len(majors) > 1:
        # The block there recommends a major CI does not gate on, so it must
        # name one of those rather than an end.
        want("tests/e2e/docker/README.md",
             r"```bash\nNC_VERSION=([\d.]+) tests/e2e/docker/up\.sh\n```",
             majors[1], "the e2e README's middle-major example")
    want("tests/e2e/docker/README.md",
         r"up\.sh\s+# NC_VERSION=([\d.|]+, default \d+)",
         f"{'|'.join(majors)}, default {maximum}",
         "the e2e README's NC_VERSION list and default")
    want("tests/e2e/docker/up.sh", r"#\s+NC_VERSION=([\d.]+) tests/e2e/docker/up\.sh",
         floor, "the up.sh usage example")
    want("tests/e2e/docker/compose.yml", r"# tested against \(([\d.]+ to \d+) —",
         f"{floor} to {maximum}", "the range named in the compose header")

    if problems:
        print(f"appinfo/info.xml declares Nextcloud {floor} to {maximum}; it is the source of truth.")
        for problem in problems:
            print(f"::error::{problem}")
        return 1

    print(f"Nextcloud {floor} to {maximum}: info.xml, composer.json, both CI matrices, "
          "the three stack defaults, the README and the e2e stack's own docs agree.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
