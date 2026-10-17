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

/** The script's exit status and what it said on stderr, run on the copy. */
const run = (root, version, flag = '--set') => {
	try {
		execFileSync('python3', [join(repo, 'scripts/check-version-alignment.py'), '--root', root, flag, version], { stdio: 'pipe' })
		return { status: 0, stderr: '' }
	} catch (error) {
		return { status: error.status, stderr: String(error.stderr) }
	}
}
const setVersion = (root, version) => run(root, version).status

const changedLines = (before, after) => {
	const afterLines = after.split('\n')
	return before.split('\n')
		.map((line, i) => [line, afterLines[i]])
		.filter(([was, is]) => was !== is)
}

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

	it.each(['9.8.7-dev', '9.8.7-rc-dev', '9.8.7-dev.1', '9.8.7-DEV'])('refuses the development version %s, and writes nothing', (version) => {
		const before = read()

		expect(setVersion(root, version)).toBe(1)
		expect(read()).toEqual(before)
	})

	it.each(['9.8.7-.', '09.8.7', '9.8.7-01', '9.8.7\u0661'])('refuses %s, which is no semver, and writes nothing', (version) => {
		const before = read()

		expect(setVersion(root, version)).toBe(1)
		expect(read()).toEqual(before)
	})

	it('checks a version by the same rules, and writes nothing', () => {
		const before = read()

		expect(run(root, 'v9.8.7', '--check').status).toBe(1)
		expect(run(root, '0.0.1', '--check').status).toBe(1)
		expect(run(root, '9.8.7', '--check').status).toBe(0)
		expect(read()).toEqual(before)
	})

	it('refuses a version below the one info.xml has, and writes nothing', () => {
		const before = read()

		expect(setVersion(root, '0.0.1')).toBe(1)
		expect(read()).toEqual(before)
	})

	// Numeric identifiers compare by value, and a pre-release ranks below
	// its release.
	it.each([
		['9.8.7-beta.10', '9.8.7-beta.2'],
		['9.8.7', '9.8.7-rc.1'],
	])('after %s refuses %s, and writes nothing', (first, second) => {
		expect(setVersion(root, first)).toBe(0)
		const before = read()

		expect(setVersion(root, second)).toBe(1)
		expect(read()).toEqual(before)
	})

	it.each([
		['9.8.7-beta.2', '9.8.7-beta.10'],
		['9.8.7-rc.1', '9.8.7'],
		['9.8.7', '9.8.7'],
	])('after %s takes %s', (first, second) => {
		expect(setVersion(root, first)).toBe(0)
		expect(setVersion(root, second)).toBe(0)
		expect(JSON.parse(read()['package.json']).version).toBe(second)
	})

	it('takes a version below a dev version info.xml has', () => {
		const infoXml = join(root, 'appinfo/info.xml')
		writeFileSync(infoXml, readFileSync(infoXml, 'utf8').replace(/<version>[^<]+<\/version>/, '<version>99.0.0-dev</version>'))

		expect(setVersion(root, '9.8.7')).toBe(0)
	})

	// A lockfile whose root package names no version is not one this edits
	// right: refused before any file is written, info.xml included.
	it('writes nothing when a version is not where it is expected', () => {
		const lock = JSON.parse(readFileSync(join(root, 'package-lock.json'), 'utf8'))
		delete lock.packages[''].version
		writeFileSync(join(root, 'package-lock.json'), JSON.stringify(lock, null, '\t') + '\n')
		const before = read()

		const { status, stderr } = run(root, '9.8.7')
		expect(status).toBe(1)
		expect(stderr).toContain('package-lock.json has no packages[""].version')
		expect(read()).toEqual(before)
	})
})
