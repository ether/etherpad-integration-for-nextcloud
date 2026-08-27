/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * The one place that knows what a fixture is called. Names are built here
 * and recognised here, so the producer and the trash sweep cannot drift
 * apart — a name this module builds is a name it will match.
 *
 * Free of imports so it can be unit-tested without a target instance.
 */

/** Extensions a fixture may carry. A name is only ours if it stays inside this set. */
export const FIXTURE_EXTENSIONS = ['pad', 'txt'] as const

export type FixtureExtension = (typeof FIXTURE_EXTENSIONS)[number]

const LABEL = /^[a-z0-9]+(?:-[a-z0-9]+)*$/
const RUN_ID = /^[0-9a-f]{8}$/

/** `e2e-<label>-r<runid>-<timestamp>[.ext]` */
const FIXTURE_NAME = new RegExp(
	`^e2e-[a-z0-9-]+-r([0-9a-f]{8})-(\\d{13})(?:\\.(?:${FIXTURE_EXTENSIONS.join('|')}))?$`,
)

/**
 * The shape used before run ids, with the same extensions — recognised so
 * it can be reported, never acted on. Anything outside that set was never
 * ours to begin with.
 */
const LEGACY_FIXTURE_NAME = new RegExp(
	`^e2e-[a-z0-9-]+-\\d{13}(?:\\.(?:${FIXTURE_EXTENSIONS.join('|')}))?$`,
)

export const isRunId = (value: string): boolean => RUN_ID.test(value)

/**
 * Fold an arbitrary value into the run-id shape the names and the matcher
 * agree on. An id supplied from outside — `E2E_RUN_ID=$GITHUB_RUN_ID`, say —
 * would otherwise produce names the sweep cannot recognise, and it would
 * fail by quietly purging nothing.
 */
export const normaliseRunId = (raw: string): string => {
	const trimmed = raw.trim().toLowerCase()
	if (isRunId(trimmed)) {
		return trimmed
	}
	// FNV-1a. Only has to spread ids across concurrent runs, not resist anything.
	let hash = 0x811c9dc5
	for (let i = 0; i < trimmed.length; i++) {
		hash ^= trimmed.charCodeAt(i)
		hash = Math.imul(hash, 0x01000193)
	}
	return (hash >>> 0).toString(16).padStart(8, '0')
}

/**
 * Build the name for a file or folder a spec creates. Throws on a label
 * or extension the matcher could not recognise afterwards: a fixture the
 * sweep cannot see would leak forever, and failing here is the only way
 * that becomes visible.
 */
export const buildFixtureName = (
	label: string,
	options: { runId: string, extension?: FixtureExtension, now?: number },
): string => {
	if (!LABEL.test(label)) {
		throw new Error(`Fixture label "${label}" must be lowercase words joined by single dashes.`)
	}
	const extension = options.extension === undefined ? '' : `.${options.extension}`
	if (options.extension !== undefined && !FIXTURE_EXTENSIONS.includes(options.extension)) {
		throw new Error(`Fixture extension "${options.extension}" is not one the trash sweep recognises.`)
	}
	return `e2e-${label}-r${normaliseRunId(options.runId)}-${options.now ?? Date.now()}${extension}`
}

/** The run that created this name, or null if it is not a fixture name of ours. */
export const runIdOf = (originalName: string): string | null => {
	const match = FIXTURE_NAME.exec(originalName)
	return match === null ? null : match[1]
}

/** A fixture name from before run ids existed. Reported, never purged. */
export const isLegacyFixtureName = (originalName: string): boolean =>
	runIdOf(originalName) === null && LEGACY_FIXTURE_NAME.test(originalName)
