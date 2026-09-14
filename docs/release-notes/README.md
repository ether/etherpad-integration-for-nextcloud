<!--
SPDX-FileCopyrightText: 2026 Jacob Bühler
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Release notes

One file per released version, named after it exactly as `appinfo/info.xml`
spells it - `1.1.0-beta.1.md`, no leading `v`. `.github/workflows/release.yml`
publishes the file as the GitHub release body when a matching tag is pushed.

This is where a release gets the text its readers see: prose, grouped the way
that release's story wants, without issue numbers. It is deliberately not the
`CHANGELOG.md` entry, which is terse, grouped by Added/Changed/Fixed, and
carries the issue references the repository needs.

Where no file exists for a version, the workflow falls back to that version's
`CHANGELOG.md` section, so a release still says something.

Write no licence header in these files. They are published verbatim, so one
would head the release page and every notification mail; a leading HTML comment
is stripped, but a visible `SPDX-License-Identifier:` line - the style most of
`docs/` uses - would be published as written.

A file that holds nothing but headers or headings fails the release rather than
publishing an empty body.
