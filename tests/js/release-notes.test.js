/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
// @vitest-environment node
import { execFileSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

const repo = resolve(__dirname, '../..')

/** The script's exit status and the body it printed, run on a root of its own. */
const notes = (root, version) => {
	try {
		const body = execFileSync('python3', [join(repo, 'scripts/release-notes.py'), '--root', root, version], { stdio: 'pipe', encoding: 'utf8' })
		return { status: 0, body }
	} catch (error) {
		return { status: error.status, body: '' }
	}
}

describe('release-notes.py', () => {
	let root
	const write = (path, text) => {
		mkdirSync(dirname(join(root, path)), { recursive: true })
		writeFileSync(join(root, path), text)
	}

	beforeEach(() => {
		root = mkdtempSync(join(tmpdir(), 'epnc-notes-'))
	})

	afterEach(() => {
		rmSync(root, { recursive: true, force: true })
	})

	it('refuses release notes without a CHANGELOG section for the version', () => {
		write('CHANGELOG.md', '# Changelog\n\n## 1.0.0 - 2026-01-01\n\n- Old.\n')
		write('docs/release-notes/2.0.0.md', 'Prose.\n')

		expect(notes(root, '2.0.0').status).toBe(1)
	})

	it('refuses a CHANGELOG section that holds only a heading', () => {
		write('CHANGELOG.md', '# Changelog\n\n## 2.0.0 - 2026-01-01\n\n### Fixed\n\n## 1.0.0 - 2026-01-01\n\n- Old.\n')
		write('docs/release-notes/2.0.0.md', 'Prose.\n')

		expect(notes(root, '2.0.0').status).toBe(1)
	})

	it('publishes the CHANGELOG section where no release notes exist', () => {
		write('CHANGELOG.md', '# Changelog\n\n## 2.0.0 - 2026-01-01\n\n- New.\n\n## 1.0.0 - 2026-01-01\n\n- Old.\n')

		expect(notes(root, '2.0.0')).toEqual({ status: 0, body: '- New.\n' })
	})

	it('publishes the release notes where both exist', () => {
		write('CHANGELOG.md', '# Changelog\n\n## 2.0.0 - 2026-01-01\n\n- New.\n')
		write('docs/release-notes/2.0.0.md', 'Prose.\n')

		expect(notes(root, '2.0.0')).toEqual({ status: 0, body: 'Prose.\n' })
	})
})
