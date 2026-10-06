/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * What Nextcloud and its other apps logged in the browser during a run
 * that is not known yet, grouped, as Markdown: the CI job appends it to its
 * summary, so the noise a new release brings shows on the run's page
 * without failing a test or downloading a report. The known noise
 * (`KNOWN_ELSEWHERE` in fixtures/browser-noise-rules.mjs) is counted per
 * entry, folded away, so one that stops showing up can be struck.
 * fixtures/browser-noise.ts writes the record; this app's own errors fail
 * their tests and are not in it.
 *
 * Usage: node tests/e2e/browser-noise-summary.mjs [record]
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { KNOWN_ELSEWHERE, OUTPUT_DIR, recordIn } from './fixtures/browser-noise-rules.mjs'

/**
 * One line per kind of message, whatever run, user, share or file it met:
 * without the parts that change from one load to the next.
 *
 * @param {string} text
 */
const fold = (text) => text
	.replace(/\?v=[^\s)'"]+/g, '')
	.replace(/nonce-[A-Za-z0-9+/=]+/g, 'nonce-…')
	.replace(/uid: [^,}]+/g, 'uid: …')
	.replace(/e2e-[^\s/'")]*-r[0-9a-f]+-\d+[^\s/'")]*/g, 'e2e-…')
	.replace(/([?&](?:fileId|fileid|id)=)\d+/g, '$1…')
	.replace(/\/(remote|public)\.php\/dav\/files\/[^/\s)'"]+/g, '/$1.php/dav/files/…')
	.replace(/\/(s|public)\/[A-Za-z0-9]{10,}/g, '/$1/…')
	.trim()

/**
 * The message, by its first line - a stack below it varies with the
 * bundle - and where it came from, which the first line of a stack does
 * not say: two errors with the same words from different apps are two.
 *
 * @param {string} text
 * @param {string} where
 */
const normalise = (text, where) => {
	const first = text.split('\n', 1)[0]
	// A one-line console text ends in its location already.
	return fold(where !== '' && !first.endsWith(`(${where})`) ? `${first} (${where})` : first)
}

/**
 * A count with its noun, one or more.
 *
 * @param {number} n
 * @param {string} noun
 */
const count = (n, noun) => `${n} ${noun}${n === 1 ? '' : 's'}`

/**
 * Fit for a Markdown table cell.
 *
 * @param {string} text
 */
const cell = (text) => '`' + (text.length > 240 ? text.slice(0, 239) + '…' : text).replace(/`/g, '\'').replace(/\|/g, '\\|') + '`'

/**
 * The summary of a run's record, or of none.
 *
 * @param {string | null} record the record's content; null when there is
 *   none - global-setup.ts starts it, so the suite ran no test, or wrote
 *   its results somewhere else
 * @returns {string} Markdown
 */
export const summarise = (record) => {
	const out = ['### Browser noise of other software', '']
	if (record === null) {
		out.push('No record: the suite ran no test, or wrote its results somewhere else than `test-results/`.')
		return out.join('\n') + '\n'
	}
	if (record.trim() === '') {
		out.push('Nothing recorded: no test met any.')
		return out.join('\n') + '\n'
	}

	const groups = new Map()
	const tests = new Set()
	const known = new Map(KNOWN_ELSEWHERE.map((entry) => [entry.id, { times: 0, tests: new Set() }]))
	let times = 0
	let unreadable = 0
	for (const line of record.split('\n')) {
		if (line.trim() === '') {
			continue
		}
		let entry
		try {
			entry = JSON.parse(line)
		} catch {
			// A worker stopped hard can leave half a line. The summary says so
			// rather than failing a run the record was meant to inform.
			unreadable++
			continue
		}
		if (typeof entry.known === 'string') {
			const tally = known.get(entry.known) ?? { times: 0, tests: new Set() }
			tally.times++
			tally.tests.add(entry.test)
			known.set(entry.known, tally)
			continue
		}
		const message = normalise(String(entry.text ?? ''), String(entry.where ?? ''))
		const key = `${entry.kind}\u0000${message}`
		const group = groups.get(key) ?? { kind: entry.kind, message, times: 0, tests: new Set() }
		group.times++
		group.tests.add(entry.test)
		groups.set(key, group)
		tests.add(entry.test)
		times++
	}

	if (groups.size === 0) {
		out.push('Nothing new.')
	} else {
		out.push(
			`Not known yet: ${count(groups.size, 'kind')}, ${count(times, 'time')}, in ${count(tests.size, 'test')}. None of it fails a test: this app's own errors do, and are in the run log. Each test's report carries its own as \`browser-noise\`. Explain an entry and add it to the known list, or report it where it comes from.`,
			'',
			'| Times | Tests | Kind | Message |',
			'|---:|---:|---|---|',
			...[...groups.values()]
				.sort((a, b) => b.tests.size - a.tests.size || b.times - a.times)
				.map((group) => `| ${group.times} | ${group.tests.size} | ${group.kind} | ${cell(group.message)} |`),
		)
	}
	if (unreadable > 0) {
		out.push('', `${count(unreadable, 'line')} of the record could not be read.`)
	}

	const knownTimes = [...known.values()].reduce((sum, tally) => sum + tally.times, 0)
	out.push(
		'',
		'<details>',
		`<summary>Known and left out: ${count(knownTimes, 'time')}</summary>`,
		'',
		'An entry at 0 did not show up in this run; one that stays at 0 everywhere can be struck from `KNOWN_ELSEWHERE` in tests/e2e/fixtures/browser-noise-rules.mjs.',
		'',
		'| Times | Tests | Known |',
		'|---:|---:|---|',
		...[...known.entries()].map(([id, tally]) => `| ${tally.times} | ${tally.tests.size} | \`${id}\` |`),
		'',
		'</details>',
	)
	return out.join('\n') + '\n'
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
	const path = resolve(process.argv[2] ?? recordIn(OUTPUT_DIR))
	process.stdout.write(summarise(existsSync(path) ? readFileSync(path, 'utf8') : null))
}
