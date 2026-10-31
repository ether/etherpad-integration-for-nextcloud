/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Whether a pull request's push has to run the e2e suite, to spare the shared
 * runners what tells nothing new. Whatever cannot be told counts as "yes": a
 * run too many costs minutes, a run too few hides a failure.
 *
 * Usage, from e2e.yml: node tests/e2e/ci-scope.mjs
 */
import { execFileSync } from 'node:child_process'
import { appendFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

/** The `paths` of e2e.yml's pull_request trigger; a test holds the two together. */
export const RUNTIME_PATHS = [
	'lib/**',
	'src/**',
	'js/**',
	'templates/**',
	'css/**',
	'appinfo/**',
	'tests/e2e/**',
	'scripts/app-file-excludes.sh',
	'package.json',
	'package-lock.json',
	'.github/workflows/e2e.yml',
]

/** Most files GitHub's comparison lists: past it, it says nothing of the rest. */
const COMPARE_FILE_LIMIT = 300

/** @param {string} glob */
const asPattern = (glob) => new RegExp(`^${glob
	.replace(/[.+^${}()|[\]\\]/g, '\\$&')
	.replace(/\*\*/g, '\0')
	.replace(/\*/g, '[^/]*')
	.replace(/\0/g, '.*')}$`)

/**
 * @param {string[]} files
 * @param {string[]} globs
 */
export const touches = (files, globs) => {
	const patterns = globs.map(asPattern)
	return files.some((file) => patterns.some((pattern) => pattern.test(file)))
}

/**
 * @param {object} scope
 * @param {string} scope.event what started the workflow
 * @param {string} [scope.action] what happened to the pull request
 * @param {?string[]} [scope.pushedFiles] what this push changed; null when that cannot be told
 * @param {?string} [scope.previousConclusion] how the e2e run of the commit before ended; null without one
 * @returns {{run: boolean, why: string}}
 */
export const decide = ({ event, action, pushedFiles = null, previousConclusion = null }) => {
	if (event !== 'pull_request') {
		return { run: true, why: `Started by ${event}` }
	}
	if (action !== 'synchronize') {
		return { run: true, why: `The pull request was ${action}` }
	}
	if (pushedFiles === null) {
		return { run: true, why: 'What this push changed cannot be told' }
	}
	if (touches(pushedFiles, RUNTIME_PATHS)) {
		return { run: true, why: 'This push changes what the suite runs on' }
	}
	// The new run has cancelled one still going, so without this a push of
	// tests right after one of code would leave that code untested.
	if (previousConclusion !== 'success') {
		return { run: true, why: `The run before ended as ${previousConclusion ?? 'none'}, so nothing vouches for this commit` }
	}
	return { run: false, why: 'This push changes nothing the suite runs on, and the run before passed' }
}

/**
 * Both names of a file renamed: moved out of what the suite runs on, it still
 * changes what the suite runs on.
 *
 * @param {{ filename: string, previous_filename?: string }[]} files
 * @returns {string[]}
 */
export const changedPaths = (files) => files.flatMap((file) => file.previous_filename ? [file.filename, file.previous_filename] : [file.filename])

/** @param {string} path */
const gh = (path) => JSON.parse(execFileSync('gh', ['api', path], { encoding: 'utf8' }))

/**
 * What the push changed: null for a force push, which compares as no
 * descendant, or a list cut short.
 *
 * @param {string} repository
 * @param {string} before
 * @param {string} after
 */
const pushedFilesOf = (repository, before, after) => {
	const comparison = gh(`repos/${repository}/compare/${before}...${after}`)
	const files = comparison.files ?? []
	return comparison.status === 'ahead' && files.length < COMPARE_FILE_LIMIT ? changedPaths(files) : null
}

/**
 * @param {string} repository
 * @param {string} before
 * @returns {?string}
 */
const previousConclusionOf = (repository, before) =>
	gh(`repos/${repository}/actions/workflows/e2e.yml/runs?head_sha=${before}&event=pull_request&per_page=1`).workflow_runs?.[0]?.conclusion ?? null

/**
 * @template T
 * @param {() => T} answer
 * @returns {?T}
 */
const attempt = (answer) => {
	try {
		return answer()
	} catch {
		return null
	}
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
	const { EVENT: event = '', ACTION: action, REPOSITORY: repository = '', BEFORE: before = '', AFTER: after = '', GITHUB_OUTPUT: output = '' } = process.env
	const synchronize = event === 'pull_request' && action === 'synchronize'
	const { run, why } = decide({
		event,
		action,
		pushedFiles: synchronize ? attempt(() => pushedFilesOf(repository, before, after)) : null,
		previousConclusion: synchronize ? attempt(() => previousConclusionOf(repository, before)) : null,
	})
	console.log(`${why}: run=${run}.`)
	appendFileSync(output, `run=${run}\n`)
}
