/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * What Nextcloud and its other apps logged in the browser during a run
 * that is not known yet, grouped, as Markdown: the CI job appends it to its
 * summary, so the noise a new release brings shows on the run's page
 * without failing a test or downloading a report. The known noise
 * (`KNOWN_ELSEWHERE` in fixtures/browser-noise.ts) is only counted.
 * fixtures/browser-noise.ts writes the record; this app's own errors fail
 * their tests and are not in it.
 *
 * Usage: node tests/e2e/browser-noise-summary.mjs [record]
 */
import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const record = resolve(process.argv[2] ?? resolve(here, '../../test-results/browser-noise.jsonl'))

/**
 * One line per kind of message, whatever run, user or file it met: the
 * first line only (a stack below it varies with the bundle), without the
 * parts that change from one load to the next.
 */
const normalise = (text) => text.split('\n', 1)[0]
	.replace(/\?v=[^\s)'"]+/g, '')
	.replace(/nonce-[A-Za-z0-9+/=]+/g, 'nonce-…')
	.replace(/uid: [^,}]+/g, 'uid: …')
	.replace(/e2e-[^\s/'")]*-r[0-9a-f]+-\d+[^\s/'")]*/g, 'e2e-…')
	.replace(/\/(s|public)\/[A-Za-z0-9]{10,}/g, '/$1/…')
	.trim()

/** A count with its noun, one or more. */
const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

/** Fit for a Markdown table cell. */
const cell = (text) => '`' + (text.length > 240 ? text.slice(0, 239) + '…' : text).replace(/`/g, '\'').replace(/\|/g, '\\|') + '`'

const out = ['### Browser noise of other software', '']
if (!existsSync(record)) {
	out.push('Nothing recorded: no test met any.')
} else {
	const groups = new Map()
	const tests = new Set()
	let times = 0
	let known = 0
	for (const line of readFileSync(record, 'utf8').split('\n')) {
		if (line.trim() === '') {
			continue
		}
		const entry = JSON.parse(line)
		if (entry.known === true) {
			known++
			continue
		}
		const { test, kind, text } = entry
		const key = `${kind}\u0000${normalise(text)}`
		const group = groups.get(key) ?? { kind, message: normalise(text), times: 0, tests: new Set() }
		group.times++
		group.tests.add(test)
		groups.set(key, group)
		tests.add(test)
		times++
	}
	const knownLine = `Known and left out: ${count(known, 'time')} (\`KNOWN_ELSEWHERE\` in tests/e2e/fixtures/browser-noise.ts).`
	if (groups.size === 0) {
		out.push('Nothing new.', '', knownLine)
	} else {
		out.push(
			`Not known yet: ${count(groups.size, 'kind')}, ${count(times, 'time')}, in ${count(tests.size, 'test')}. None of it fails a test: this app's own errors do, and are in the run log. Each test's report carries its own as \`browser-noise\`. Explain an entry and add it to the known list, or report it where it comes from.`,
			'',
			'| Times | Tests | Kind | Message |',
			'|---:|---:|---|---|',
			...[...groups.values()]
				.sort((a, b) => b.tests.size - a.tests.size || b.times - a.times)
				.map((group) => `| ${group.times} | ${group.tests.size} | ${group.kind} | ${cell(group.message)} |`),
			'',
			knownLine,
		)
	}
}
process.stdout.write(out.join('\n') + '\n')
