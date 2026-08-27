/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, it, expect } from 'vitest'
import { classifyTrashEntry, STALE_AFTER_MS } from '../e2e/fixtures/sweep-filter.ts'

/**
 * The sweep deletes permanently, so its selection rule is worth pinning:
 * every name the specs actually generate has to be recognised, and
 * nothing else may be.
 */
const RUN_STARTED_AT = 1_700_000_000_000
const NOW = RUN_STARTED_AT + 60_000

const classify = (name, overrides = {}) =>
	classifyTrashEntry(name, { runStartedAt: RUN_STARTED_AT, now: NOW, ...overrides })

describe('trash sweep selection', () => {
	// Exactly the shapes tests/e2e/specs and fixtures build.
	it.each([
		'e2e-trash-restore-1700000000001.pad',
		'e2e-legacy-1700000000001.pad',
		'e2e-roundtrip-1700000000001.pad',
		'e2e-public-share-non-pad-1700000000001.txt',
		'e2e-public-share-non-pad-route-1700000000001.txt',
		// Folders — no extension. pad-move-rename leaves one per run.
		'e2e-move-folder-1700000000001',
		'e2e-tmpl-1700000000001',
		'e2e-tmpl-src-1700000000001',
	])('purges this run\'s own %s', (name) => {
		expect(classify(name)).toBe('ours')
	})

	it.each([
		// A person's file that happens to start the same way.
		'e2e-debug-1700000000001.pdf',
		'e2e-notes-1700000000001.docx',
		// Not our shape at all.
		'Invoice 2026.pad',
		'e2e-no-timestamp.pad',
		'e2e-short-17000.pad',
		'prefixed-e2e-run-1700000000001.pad',
	])('leaves %s alone', (name) => {
		expect(classify(name)).toBe('not-ours')
	})

	it('leaves a recent entry from another run alone', () => {
		// Created before this run started, far too recent to be a leftover:
		// another suite may be about to restore it.
		const name = `e2e-trash-restore-${RUN_STARTED_AT - 60_000}.pad`
		expect(classify(name)).toBe('foreign-run')
	})

	it('purges a leftover from a run that never finished', () => {
		const name = `e2e-trash-restore-${NOW - STALE_AFTER_MS - 1}.pad`
		expect(classify(name)).toBe('stale')
	})

	it('treats an entry created during this run as ours', () => {
		expect(classify(`e2e-trash-restore-${RUN_STARTED_AT}.pad`)).toBe('ours')
	})
})
