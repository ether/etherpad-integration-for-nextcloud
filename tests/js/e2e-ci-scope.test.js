/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, it, expect } from 'vitest'
import { execFileSync } from 'node:child_process'
import { readFileSync, readdirSync } from 'node:fs'
import { RUNTIME_PATHS, SLOW_PATHS, changedPaths, decide, touches } from '../e2e/ci-scope.mjs'
import { summary, timesOf } from '../e2e/slowest-tests.mjs'

const push = {
	event: 'pull_request',
	action: 'synchronize',
	pushedFiles: ['docs/architecture.md'],
	previousConclusion: 'success',
	prFiles: ['docs/architecture.md'],
}

describe('whether the suite runs', () => {
	it('skips only a push of nothing the suite runs on, after a run that passed', () => {
		expect(decide(push).run).toBe(false)
		expect(decide({ ...push, pushedFiles: [] }).run).toBe(false)
		expect(decide({ ...push, pushedFiles: ['tests/phpunit/unit/AnyTest.php'] }).run).toBe(false)
	})

	it.each([
		['the app', { pushedFiles: ['lib/Service/PadFileService.php'] }],
		['the suite', { pushedFiles: ['tests/e2e/specs/pad-lost.spec.ts'] }],
		['the workflow', { pushedFiles: ['.github/workflows/e2e.yml'] }],
		['a force push, or a comparison that failed', { pushedFiles: null }],
		// A cancelled run is what a push right after one of code leaves.
		['a run before that was cancelled', { previousConclusion: 'cancelled' }],
		['a run before that failed', { previousConclusion: 'failure' }],
		['no run before, or a lookup that failed', { previousConclusion: null }],
		['a pull request opened', { action: 'opened' }],
		['a pull request marked ready', { action: 'ready_for_review' }],
	])('runs for %s', (_, change) => {
		expect(decide({ ...push, ...change }).run).toBe(true)
	})
})

describe('whether the slow cases run', () => {
	it('leaves them out of a pull request that touches nothing they hold', () => {
		const scope = decide({ ...push, pushedFiles: ['src/viewer.js'], prFiles: ['src/viewer.js', 'lib/Controller/EmbedController.php'] })
		expect(scope).toMatchObject({ run: true, slow: false })
	})

	it.each(SLOW_PATHS.map((glob) => [glob.replace('**', 'any.txt').replace('*', 'Any')]))('takes them for %s', (file) => {
		expect(decide({ ...push, prFiles: [file] }).slow).toBe(true)
	})

	it('goes by the whole pull request, not the push', () => {
		const scope = decide({ ...push, pushedFiles: ['src/viewer.js'], prFiles: ['src/viewer.js', 'lib/Service/SessionDeletes.php'] })
		expect(scope.slow).toBe(true)
	})

	it('takes them when the pull request cannot be told', () => {
		expect(decide({ ...push, prFiles: null }).slow).toBe(true)
	})

	it.each(['schedule', 'workflow_dispatch'])('takes everything when started by %s', (event) => {
		expect(decide({ event })).toMatchObject({ run: true, slow: true })
	})

	it('says why, so a run\'s log explains itself', () => {
		expect(decide({ ...push, previousConclusion: 'cancelled' }).why).toContain('cancelled')
		expect(decide({ ...push, action: 'opened', prFiles: ['src/a.js'] }).why).toContain('without the slow cases')
	})
})

describe('the paths', () => {
	it('count both names of a file renamed', () => {
		const pushedFiles = changedPaths([{ filename: 'tests/phpunit/Moved.php', previous_filename: 'lib/Service/Moved.php' }, { filename: 'docs/a.md' }])
		expect(pushedFiles).toEqual(['tests/phpunit/Moved.php', 'lib/Service/Moved.php', 'docs/a.md'])
		expect(decide({ ...push, pushedFiles }).run).toBe(true)
	})

	it('match a star within a folder, and two stars across them', () => {
		expect(touches(['lib/Service/GroupSessionRevoker.php'], ['lib/Service/*SessionRevoker.php'])).toBe(true)
		expect(touches(['lib/Service/Sub/GroupSessionRevoker.php'], ['lib/Service/*SessionRevoker.php'])).toBe(false)
		expect(touches(['tests/e2e/docker/a/b.sh'], ['tests/e2e/docker/**'])).toBe(true)
		expect(touches(['lib/Service/SessionDeletesXphp'], ['lib/Service/SessionDeletes.php'])).toBe(false)
	})

	it('are the ones e2e.yml starts on', () => {
		const workflow = readFileSync('.github/workflows/e2e.yml', 'utf8')
		const block = workflow.slice(workflow.indexOf('paths:'), workflow.indexOf('workflow_dispatch:'))
		expect([...block.matchAll(/^\s+- '([^']+)'$/gm)].map((match) => match[1])).toEqual(RUNTIME_PATHS)
	})

	// A file renamed would otherwise take the cases out in silence.
	it.each(SLOW_PATHS)('%s names a file there is', (glob) => {
		const files = execFileSync('git', ['ls-files'], { encoding: 'utf8' }).split('\n')
		expect(touches(files, [glob])).toBe(true)
	})

	it('hold the cases tagged slow, and nothing else is', () => {
		const tagged = readdirSync('tests/e2e/specs').filter((spec) => readFileSync(`tests/e2e/specs/${spec}`, 'utf8').includes('@slow'))
		expect(tagged).toEqual(['pad-gone-for-good.spec.ts'])
		expect(touches([`tests/e2e/specs/${tagged[0]}`], SLOW_PATHS)).toBe(true)
	})
})

describe('the slowest tests of a run', () => {
	const report = {
		suites: [{
			title: 'a.spec.ts',
			specs: [{ title: 'quick', tests: [{ results: [{ duration: 500 }] }] }],
			suites: [{
				title: 'a group',
				specs: [{ title: 'retried', tests: [{ results: [{ duration: 2000 }, { duration: 1500 }] }] }],
			}],
		}],
	}

	it('counts every attempt, the longest first, with where it sits', () => {
		expect(timesOf(report)).toEqual([
			{ title: 'a.spec.ts › a group › retried', seconds: 3.5 },
			{ title: 'a.spec.ts › quick', seconds: 0.5 },
		])
	})

	it('reads as a table, with its total', () => {
		const text = summary(report)
		expect(text).toContain('of 2, 4 s in all')
		expect(text).toContain('| 3.5 | a.spec.ts › a group › retried |')
	})

	it('keeps a title from breaking the table', () => {
		expect(summary({ suites: [{ title: 'a', specs: [{ title: 'x | y', tests: [] }] }] })).toContain('x \\| y')
	})

	it('takes an empty report', () => {
		expect(timesOf({})).toEqual([])
	})
})
