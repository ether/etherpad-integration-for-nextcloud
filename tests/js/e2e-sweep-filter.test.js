/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, it, expect } from 'vitest'
import { classifyTrashEntry, runToken, STALE_AFTER_MS } from '../e2e/fixtures/sweep-filter.ts'

/**
 * The sweep deletes permanently, so its selection rule is worth pinning:
 * every name the specs actually generate has to be recognised, nothing
 * else may be, and a run must never claim another run's entries.
 */
const OURS = 'a1b2c3d4'
const THEIRS = '99887766'
const NOW = 1_700_000_060_000

const name = (label, id, ts, ext = '') => `e2e-${label}-${runToken(id)}-${ts}${ext}`
const classify = (entry, overrides = {}) =>
	classifyTrashEntry(entry, { runId: OURS, now: NOW, ...overrides })

describe('trash sweep selection', () => {
	// Exactly the shapes tests/e2e/specs and fixtures build.
	it.each([
		['trash-restore', '.pad'],
		['legacy', '.pad'],
		['roundtrip', '.pad'],
		['public-share-non-pad', '.txt'],
		['public-share-non-pad-route', '.txt'],
		// Folders — no extension. pad-move-rename leaves one per run.
		['move-folder', ''],
		['tmpl', ''],
	])('purges this run\'s own %s%s', (label, ext) => {
		expect(classify(name(label, OURS, 1_700_000_000_001, ext))).toBe('ours')
	})

	it.each([
		// A person's file that happens to start the same way.
		`e2e-debug-${runToken(OURS)}-1700000000001.pdf`,
		'e2e-notes-1700000000001.docx',
		'Invoice 2026.pad',
		'e2e-no-timestamp.pad',
		'e2e-short-17000.pad',
	])('leaves %s alone', (entry) => {
		expect(classify(entry)).toBe('not-ours')
	})

	describe('another run against the same instance', () => {
		// The case that matters: ownership must come from the id, never
		// from time. Whichever run started first, neither may claim the
		// other's entries.
		it('does not claim entries created before this run started', () => {
			expect(classify(name('trash-restore', THEIRS, NOW - 60_000, '.pad'))).toBe('foreign-run')
		})

		it('does not claim entries created after this run started', () => {
			expect(classify(name('trash-restore', THEIRS, NOW, '.pad'))).toBe('foreign-run')
		})

		it('does not claim entries whose timestamp is in the future', () => {
			expect(classify(name('trash-restore', THEIRS, NOW + 600_000, '.pad'))).toBe('foreign-run')
		})

		it('claims its own entries whenever they were created', () => {
			expect(classify(name('trash-restore', OURS, NOW - 10 * STALE_AFTER_MS, '.pad'))).toBe('ours')
		})
	})

	describe('abandoned leftovers', () => {
		it('purges another run\'s entry once no run could still be using it', () => {
			const old = NOW - STALE_AFTER_MS - 1
			expect(classify(name('trash-restore', THEIRS, old, '.pad'))).toBe('stale')
		})

		it('purges a pre-run-id leftover once it is old enough', () => {
			expect(classify(`e2e-trash-restore-${NOW - STALE_AFTER_MS - 1}.pad`)).toBe('stale')
		})

		it('leaves a recent pre-run-id entry alone', () => {
			expect(classify(`e2e-trash-restore-${NOW - 60_000}.pad`)).toBe('foreign-run')
		})
	})
})
