/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * What a pull request's e2e run has to do, to spare the shared runners what
 * tells nothing new: whether the suite runs at all for this push, and whether
 * it takes the cases tagged `@slow`. Whatever cannot be told counts as "yes":
 * a run too many costs minutes, a run too few hides a failure. The nightly run
 * takes everything.
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

/** What the `@slow` cases hold: deleting for good, revoking sessions, the jobs, and how the suite runs. */
export const SLOW_PATHS = [
	'lib/AppInfo/Application.php',
	'lib/BackgroundJob/Revoke*.php',
	'lib/BackgroundJob/ResumingJob.php',
	'lib/BackgroundJob/SessionSweepJob.php',
	'lib/Listeners/GoneFilesListener.php',
	'lib/Listeners/RevokeSessions*.php',
	'lib/Service/*SessionRevoker.php',
	'lib/Service/BindingService.php',
	'lib/Service/EtherpadClient.php',
	'lib/Service/GoneFileSweep.php',
	'lib/Service/ManagedPadLifecycle.php',
	'lib/Service/PadSessionService.php',
	'lib/Service/ProtectedPads*.php',
	'lib/Service/RunBudget.php',
	'lib/Service/SessionDeletes.php',
	'lib/Util/EtherpadErrorClassifier.php',
	'lib/Util/PadId.php',
	'appinfo/**',
	'tests/e2e/specs/pad-gone-for-good.spec.ts',
	'tests/e2e/fixtures/**',
	'tests/e2e/docker/**',
	'tests/e2e/playwright.config.ts',
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
 * @param {?string[]} [scope.prFiles] what the whole pull request changes; null when that cannot be told
 * @returns {{run: boolean, slow: boolean, why: string}}
 */
export const decide = ({ event, action, pushedFiles = null, previousConclusion = null, prFiles = null }) => {
	if (event !== 'pull_request') {
		return { run: true, slow: true, why: `Started by ${event}` }
	}
	// The whole pull request, not the push: the next push may touch something
	// else, and the cases still have to hold what the pull request changed.
	const slow = prFiles === null || touches(prFiles, SLOW_PATHS)
	const slowWhy = slow ? 'with the slow cases' : 'without the slow cases: this pull request touches nothing they hold'
	if (action !== 'synchronize') {
		return { run: true, slow, why: `The pull request was ${action}; ${slowWhy}` }
	}
	if (pushedFiles === null) {
		return { run: true, slow, why: `What this push changed cannot be told; ${slowWhy}` }
	}
	if (touches(pushedFiles, RUNTIME_PATHS)) {
		return { run: true, slow, why: `This push changes what the suite runs on; ${slowWhy}` }
	}
	// The new run has cancelled one still going, so without this a push of
	// tests right after one of code would leave that code untested.
	if (previousConclusion !== 'success') {
		return { run: true, slow, why: `The run before ended as ${previousConclusion ?? 'none'}, so nothing vouches for this commit; ${slowWhy}` }
	}
	return { run: false, slow, why: 'This push changes nothing the suite runs on, and the run before passed' }
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

/** The merge GitHub made: its first parent is the base it merges onto. Both names of a file renamed. */
const prFilesOf = () => execFileSync('git', ['diff', '--name-only', '--no-renames', 'HEAD^1', 'HEAD'], { encoding: 'utf8' }).split('\n').filter(Boolean)

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
	const { run, slow, why } = decide({
		event,
		action,
		pushedFiles: synchronize ? attempt(() => pushedFilesOf(repository, before, after)) : null,
		previousConclusion: synchronize ? attempt(() => previousConclusionOf(repository, before)) : null,
		prFiles: event === 'pull_request' ? attempt(prFilesOf) : null,
	})
	console.log(`${why}: run=${run}, slow=${slow}.`)
	appendFileSync(output, `run=${run}\nslow=${slow}\n`)
}
