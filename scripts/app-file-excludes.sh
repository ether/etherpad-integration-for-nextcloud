#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# What the app ships. Sourced by scripts/build-release-tarball.sh and by
# tests/e2e/docker/sync-app.sh, so the stack the e2e suite exercises is
# the same tree the release tarball carries — a new runtime directory
# only has to be remembered once.
#
# A deny-list on purpose: forgetting to exclude something puts a file in
# the app, which is visible. An allow-list fails the other way, by
# silently leaving a needed file out.

RSYNC_EXCLUDES=(
	--exclude='.git/'
	--exclude='.github/'
	--exclude='.gitignore'
	--exclude='.gitattributes'
	--exclude='.editorconfig'
	--exclude='node_modules/'
	--exclude='vendor/'
	--exclude='tests/'
	--exclude='src/'
	--exclude='scripts/'
	--exclude='.phpunit.cache/'
	--exclude='_copy_probe/'
	--exclude='.DS_Store'
	--exclude='._*'
	--exclude='ToDo.md'
	--exclude='*.zip'
	--exclude='package.json'
	--exclude='package-lock.json'
	--exclude='composer.json'
	--exclude='composer.lock'
	--exclude='phpunit.xml.dist'
	--exclude='phpcs.xml.dist'
	--exclude='vite.config.js'
	--exclude='vitest.config.js'
	--exclude='psalm.xml'
	--exclude='psalm-baseline.xml'
	--exclude='dist/'
	# Playwright e2e artefacts (gitignored, but rsync copies the working
	# tree regardless) — must never end up in the app tarball.
	--exclude='test-results/'
	--exclude='playwright-report/'
	--exclude='blob-report/'
	--exclude='.playwright/'
)
