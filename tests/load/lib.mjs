/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

// What the load scripts share: the target from the env file up.sh writes,
// requests to the app and to Etherpad's API, and the container stack's
// database and logs. The scripts measure a stack made for them alone - see
// README.md - and refuse no target, so point them at no other.

import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { parseEnv } from 'node:util'

export const repo = new URL('../../', import.meta.url).pathname
const composeFiles = ['-f', `${repo}tests/e2e/docker/compose.yml`, '-f', `${repo}tests/load/compose.load.yml`]
// The stack's own project, not the e2e suite's: the scripts fill it with
// pads that suite does not expect.
export const project = process.env.COMPOSE_PROJECT_NAME || 'epnc-load'

export function loadTarget(envFile = process.env.E2E_ENV_FILE || `${repo}tests/e2e/.env.e2e.docker`) {
	const env = parseEnv(readFileSync(envFile, 'utf8'))
	return {
		base: env.E2E_BASE_URL.replace(/\/+$/, ''),
		user: env.E2E_USER,
		auth: 'Basic ' + Buffer.from(`${env.E2E_USER}:${env.E2E_APP_PASSWORD}`).toString('base64'),
		etherpadUrl: env.E2E_ETHERPAD_URL.replace(/\/+$/, ''),
		etherpadKey: env.E2E_ETHERPAD_API_KEY,
	}
}

/** A request to Nextcloud as the target's user; `path` is absolute or below the base URL. */
export async function request(target, method, path, { form, headers = {} } = {}) {
	const started = performance.now()
	const res = await fetch(new URL(path, target.base + '/'), {
		method,
		headers: {
			Authorization: target.auth,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			...(form ? { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' } : {}),
			...headers,
		},
		body: form ? new URLSearchParams(form) : undefined,
	})
	const text = await res.text()
	let body = {}
	try {
		body = JSON.parse(text)
	} catch {
		body = { text }
	}
	return { status: res.status, body, ms: performance.now() - started }
}

export const appApi = (target, method, endpoint, form) => request(target, method, `index.php/apps/etherpad_nextcloud/api/v1/${endpoint}`, { form })

/** A call of Etherpad's HTTP API; throws where Etherpad answers with an error. */
export async function etherpad(target, fn, params = {}) {
	const res = await fetch(`${target.etherpadUrl}/api/1.2.15/${fn}`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
		body: new URLSearchParams({ apikey: target.etherpadKey, ...params }),
	})
	const data = await res.json()
	if (data.code !== 0) {
		throw new Error(`${fn}: ${data.message}`)
	}
	return data.data
}

export function compose(...args) {
	return execFileSync('docker', ['compose', '-p', project, ...composeFiles, ...args], { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 })
}

/** One value from the stack's database. */
export function sqlValue(sql) {
	return compose('exec', '-T', 'db', 'psql', '-U', 'nextcloud', '-d', 'nextcloud', '-At', '-c', sql).trim()
}

/**
 * The API functions Etherpad logged as called between two instants, by
 * name. Etherpad logs each call of its HTTP API, the app's and the
 * scripts' alike, so a caller leaves out the functions it calls itself.
 */
export function etherpadCalls(since, until) {
	const log = compose('logs', '--no-color', '--no-log-prefix', '--since', since.toISOString(), '--until', until.toISOString(), 'etherpad')
	const calls = {}
	for (const match of log.matchAll(/REQUEST, v[\d.]+:(\w+)/g)) {
		calls[match[1]] = (calls[match[1]] ?? 0) + 1
	}
	return calls
}

/** Runs `work` on each item, at most `limit` at a time, in order of the items. */
export async function eachLimited(items, limit, work) {
	const results = new Array(items.length)
	let next = 0
	await Promise.all(Array.from({ length: Math.min(limit, items.length) }, async () => {
		while (next < items.length) {
			const index = next++
			results[index] = await work(items[index], index)
		}
	}))
	return results
}

/** The median, 95th percentile and maximum of durations in milliseconds, rounded. */
export function spread(durations) {
	if (durations.length === 0) {
		return { n: 0 }
	}
	const sorted = [...durations].sort((a, b) => a - b)
	const at = (p) => sorted[Math.min(sorted.length - 1, Math.ceil(p * sorted.length) - 1)]
	return { n: sorted.length, p50: Math.round(at(0.5)), p95: Math.round(at(0.95)), max: Math.round(sorted[sorted.length - 1]) }
}
