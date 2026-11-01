/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * The tests a CI job spent the most on, as Markdown, whether the run was green
 * or not: what to look at before making the suite faster, and how the jobs
 * differ. Reads the report of Playwright's JSON reporter.
 *
 * Usage: node tests/e2e/slowest-tests.mjs [report]
 */
import { existsSync, readFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

const COUNT = 10

/**
 * The parts of Playwright's JSON report this reads.
 *
 * @typedef {{ title?: string, specs?: { title: string, tests?: { results?: { duration?: number }[] }[] }[], suites?: Suite[] }} Suite
 */

/**
 * Every test of the report with its time over all attempts, the longest first.
 *
 * @param {{ suites?: Suite[] }} report
 * @returns {{ title: string, seconds: number }[]}
 */
export const timesOf = (report) => {
	/** @type {{ title: string, seconds: number }[]} */
	const times = []
	/**
	 * @param {Suite} suite
	 * @param {string[]} path
	 */
	const walk = (suite, path) => {
		const here = suite.title ? [...path, suite.title] : path
		for (const spec of suite.specs ?? []) {
			const seconds = (spec.tests ?? []).flatMap((test) => test.results ?? []).reduce((sum, result) => sum + (result.duration ?? 0), 0) / 1000
			times.push({ title: [...here, spec.title].join(' › '), seconds })
		}
		for (const inner of suite.suites ?? []) {
			walk(inner, here)
		}
	}
	for (const suite of report.suites ?? []) {
		walk(suite, [])
	}
	return times.sort((a, b) => b.seconds - a.seconds)
}

/** @param {{ suites?: Suite[] }} report */
export const summary = (report) => {
	const times = timesOf(report)
	const total = times.reduce((sum, { seconds }) => sum + seconds, 0)
	return [
		`### Slowest tests of ${times.length}, ${Math.round(total)} s in all`,
		'',
		'| Seconds | Test |',
		'|---|---|',
		...times.slice(0, COUNT).map(({ title, seconds }) => `| ${seconds.toFixed(1)} | ${title.replaceAll('|', '\\|')} |`),
	].join('\n')
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
	const path = process.argv[2] ?? 'e2e-results.json'
	if (existsSync(path)) {
		console.log(summary(JSON.parse(readFileSync(path, 'utf8'))))
	}
}
