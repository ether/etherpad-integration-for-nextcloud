/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
// @vitest-environment node
import { execFileSync } from 'node:child_process'
import { cpSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

const repo = resolve(__dirname, '../..')
const files = ['appinfo/info.xml', 'package.json', 'package-lock.json']

/** The script's exit status, run on the copy. */
const setVersion = (root, version) => {
	try {
		execFileSync('python3', [join(repo, 'scripts/check-version-alignment.py'), '--root', root, '--set', version], { stdio: 'pipe' })
		return 0
	} catch (error) {
		return error.status
	}
}

const changedLines = (before, after) => before.split('\n')
	.map((line, i) => [line, after.split('\n')[i]])
	.filter(([was, is]) => was !== is)

describe('check-version-alignment.py --set', () => {
	let root
	const read = () => Object.fromEntries(files.map((file) => [file, readFileSync(join(root, file), 'utf8')]))

	beforeEach(() => {
		root = mkdtempSync(join(tmpdir(), 'epnc-version-'))
		mkdirSync(join(root, 'appinfo'))
		files.forEach((file) => cpSync(join(repo, file), join(root, file)))
	})

	afterEach(() => {
		rmSync(root, { recursive: true, force: true })
	})

	// A lockfile written back through a JSON dump is reformatted on every
	// line; only the version may change.
	it('writes the version in the four places and changes no other byte', () => {
		const before = read()

		expect(setVersion(root, '9.8.7-rc.1')).toBe(0)

		const after = read()
		const changed = files.flatMap((file) => changedLines(before[file], after[file]))
		expect(changed).toHaveLength(4)
		changed.forEach(([, is]) => expect(is).toContain('9.8.7-rc.1'))
		const old = JSON.parse(before['package.json']).version
		files.forEach((file) => expect(after[file].replaceAll('9.8.7-rc.1', old)).toBe(before[file]))
	})

	it('refuses a -dev version, and writes nothing', () => {
		const before = read()

		expect(setVersion(root, '9.8.7-dev')).toBe(1)
		expect(read()).toEqual(before)
	})

	// A lockfile whose root package names no version is not one this edits
	// right: refused before any file is written, info.xml included.
	it('writes nothing when a version is not where it is expected', () => {
		const lock = JSON.parse(readFileSync(join(root, 'package-lock.json'), 'utf8'))
		delete lock.packages[''].version
		writeFileSync(join(root, 'package-lock.json'), JSON.stringify(lock, null, '\t') + '\n')
		const before = read()

		expect(setVersion(root, '9.8.7')).toBe(1)
		expect(read()).toEqual(before)
	})
})
