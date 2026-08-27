/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, it, expect } from 'vitest'
import {
	buildFixtureName,
	isRunId,
	normaliseRunId,
	FIXTURE_EXTENSIONS,
} from '../e2e/fixtures/fixture-name.ts'
import { classifyTrashEntry } from '../e2e/fixtures/sweep-filter.ts'

/**
 * The sweep deletes permanently, so the rule is worth pinning — and the
 * only way that means anything is to feed it names the production builder
 * produced. A test that rebuilds the format itself pins nothing: both
 * halves could drift together and stay green.
 */
const OURS = 'a1b2c3d4'
const THEIRS = '99887766'

const classify = (entry, runId = OURS) => classifyTrashEntry(entry, { runId })

describe('fixture names round-trip through the sweep', () => {
	// The labels and extensions tests/e2e/specs actually use. The template
	// spec is the subtle one: uniqueName('tmpl') has no extension, but the
	// spec then writes it as Templates/<name>.pad, so the trash entry does.
	it.each([
		['trash-restore', 'pad'],
		['legacy', 'pad'],
		['roundtrip', 'pad'],
		['tmpl', 'pad'],
		['public-share-non-pad', 'txt'],
		['public-share-non-pad-route', 'txt'],
		// Folders carry no extension: pad-move-rename leaves one per run.
		['move-folder', undefined],
	])('recognises %s.%s as ours', (label, extension) => {
		const name = buildFixtureName(label, { runId: OURS, extension })
		expect(classify(name)).toBe('ours')
	})

	it('accepts every extension it advertises', () => {
		for (const extension of FIXTURE_EXTENSIONS) {
			expect(classify(buildFixtureName('probe', { runId: OURS, extension }))).toBe('ours')
		}
	})

	it('refuses to build a name the sweep could not recognise', () => {
		expect(() => buildFixtureName('probe', { runId: OURS, extension: 'md' })).toThrow()
		expect(() => buildFixtureName('Probe Mixed', { runId: OURS })).toThrow()
		expect(() => buildFixtureName('trailing-', { runId: OURS })).toThrow()
	})
})

describe('run ids', () => {
	it('keeps an id that is already in the expected shape', () => {
		expect(normaliseRunId(OURS)).toBe(OURS)
	})

	// The values someone would plausibly wire up in CI. Used verbatim they
	// would build names the matcher cannot classify, and the sweep would
	// fail by silently purging nothing.
	it.each(['12345678901', 'github42', 'A1B2C3D4', 'run/42', ''])('folds %j into the expected shape', (raw) => {
		const folded = normaliseRunId(raw)
		expect(isRunId(folded)).toBe(true)
		expect(classify(buildFixtureName('probe', { runId: raw }), folded)).toBe('ours')
	})

	it('gives different ids to different runs', () => {
		expect(normaliseRunId('github-run-1')).not.toBe(normaliseRunId('github-run-2'))
	})
})

describe('what the sweep must not touch', () => {
	it('leaves another run\'s fixtures alone whenever they were created', () => {
		const theirs = buildFixtureName('trash-restore', { runId: THEIRS, extension: 'pad' })
		expect(classify(theirs)).toBe('foreign-run')
		// Ownership comes from the id alone: age must not enter into it.
		const ancient = buildFixtureName('trash-restore', { runId: THEIRS, extension: 'pad', now: 1_000_000_000_000 })
		expect(classify(ancient)).toBe('foreign-run')
	})

	it('claims its own fixture however old it is', () => {
		const old = buildFixtureName('trash-restore', { runId: OURS, extension: 'pad', now: 1_000_000_000_000 })
		expect(classify(old)).toBe('ours')
	})

	it.each([
		// A person's files that happen to start the same way.
		'e2e-notes-1600000000000.txt',
		'e2e-report-1600000000000.pad',
	])('only reports %s, never purges it', (entry) => {
		expect(classify(entry)).toBe('legacy')
	})

	it.each([
		'e2e-debug-ra1b2c3d4-1700000000001.pdf',
		'Invoice 2026.pad',
		'e2e-no-timestamp.pad',
		'notes.txt',
	])('does not recognise %s at all', (entry) => {
		expect(classify(entry)).toBe('not-ours')
	})
})
