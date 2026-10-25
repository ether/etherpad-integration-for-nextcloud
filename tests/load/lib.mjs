/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

// What the load scripts share: the target from the env file up.sh writes,
// requests to the app and to Etherpad's API, and the container stack's
// database and logs. The scripts measure a stack made for them alone - see
// README.md - and refuse no target, so point them at no other.

import { execFile, execFileSync, spawn } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { createInterface } from 'node:readline'
import { parseEnv, promisify } from 'node:util'

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

/**
 * A request to Nextcloud as the target's user; `path` is absolute or below
 * the base URL. With `cookies`, a Map, it keeps the cookies Nextcloud sets
 * and sends them back, as a browser tab does: without, every request
 * starts a PHP session of its own, and under load the stack spends its
 * time on those.
 */
export async function request(target, method, path, { form, headers = {}, cookies } = {}) {
	const started = performance.now()
	const res = await fetch(new URL(path, target.base + '/'), {
		method,
		headers: {
			Authorization: target.auth,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			...(form ? { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' } : {}),
			...(cookies?.size ? { Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; ') } : {}),
			...headers,
		},
		body: form ? new URLSearchParams(form) : undefined,
	})
	for (const cookie of cookies ? res.headers.getSetCookie() : []) {
		const [pair] = cookie.split(';')
		const at = pair.indexOf('=')
		cookies.set(pair.slice(0, at).trim(), pair.slice(at + 1).trim())
	}
	const text = await res.text()
	let body = {}
	try {
		body = JSON.parse(text)
	} catch {
		body = { text }
	}
	return { status: res.status, body, ms: performance.now() - started }
}

export const appApi = (target, method, endpoint, form, cookies) => request(target, method, `index.php/apps/etherpad_nextcloud/api/v1/${endpoint}`, { form, cookies })

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

/** A script of statements on the stack's database, stopping at the first error; its output. */
export function sqlScript(sql) {
	return execFileSync('docker', ['compose', '-p', project, ...composeFiles, 'exec', '-T', 'db', 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'nextcloud', '-d', 'nextcloud', '-At', '-f', '-'], { input: sql, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
}

// Loaded first: ALTER SYSTEM knows a module's settings only once it is.
const explainEverything = `load 'auto_explain';
alter system set session_preload_libraries = 'auto_explain';
alter system set auto_explain.log_min_duration = 0;
alter system set auto_explain.log_analyze = on;
alter system set auto_explain.log_buffers = on;
select pg_reload_conf();`
export const explainNothing = `load 'auto_explain';
alter system reset session_preload_libraries;
alter system reset auto_explain.log_min_duration;
alter system reset auto_explain.log_analyze;
alter system reset auto_explain.log_buffers;
select pg_reload_conf();`

/**
 * Runs `work` while Postgres explains every statement it finishes, with the
 * time and buffers of each step (auto_explain), and returns what `work`
 * returned with the plans of the statements on the app's table. A session
 * opened meanwhile takes it up, and Nextcloud opens one per request.
 * Explaining makes each statement slower, so time the work without it.
 *
 * ALTER SYSTEM outlives this process: left set, it has Postgres log every
 * statement of every request, and the stack crawls. So it is reset on
 * Ctrl-C and on a kill that lets the process end, and a run refuses to
 * start over one left set by a process that could not (README.md says how
 * to reset it by hand).
 */
export async function withPlans(work) {
	if (sqlValue('show session_preload_libraries').includes('auto_explain')) {
		throw new Error('Postgres still explains every statement, left so by a run that ended early; reset it as tests/load/README.md says.')
	}
	const reset = () => sqlScript(explainNothing)
	const onSignal = (signal) => {
		reset()
		process.exit(signal === 'SIGINT' ? 130 : 143)
	}
	process.once('SIGINT', onSignal)
	process.once('SIGTERM', onSignal)
	sqlScript(explainEverything)
	await new Promise((resolve) => setTimeout(resolve, 1000))
	const since = new Date()
	let result
	let until
	try {
		result = await work()
		until = new Date(Date.now() + 1000)
	} finally {
		reset()
		process.off('SIGINT', onSignal)
		process.off('SIGTERM', onSignal)
	}
	return { result, plans: await plansOnBindings(since, until) }
}

/**
 * What each container of the stack takes of a core right now, in percent,
 * as `docker stats` reports it: one sample of about two seconds. Not
 * blocking, so the viewers' timers keep running meanwhile.
 */
export async function cpuOfStack() {
	const { stdout } = await promisify(execFile)('docker', ['stats', '--no-stream', '--format', '{{.Name}}\t{{.CPUPerc}}'])
	return Object.fromEntries(stdout.split('\n').filter((line) => line.startsWith(`${project}-`))
		.map((line) => line.split('\t')).map(([name, cpu]) => [name.slice(project.length + 1).replace(/-\d+$/, ''), Number.parseFloat(cpu)]))
}

/** The plans Postgres logged between two instants for statements on ep_pad_bindings: each statement once, with its slowest plan. */
function plansOnBindings(since, until) {
	const logs = spawn('docker', ['compose', '-p', project, ...composeFiles, 'logs', '--no-color', '--no-log-prefix', '--since', since.toISOString(), '--until', until.toISOString(), 'db'], { stdio: ['ignore', 'pipe', 'inherit'] })
	const plans = []
	let current = null
	createInterface({ input: logs.stdout }).on('line', (line) => {
		const start = /duration: ([\d.]+) ms\s+plan:/.exec(line)
		if (start) {
			current = { ms: Number(start[1]), lines: [] }
			plans.push(current)
		} else if (current && /^\s/.test(line)) {
			current.lines.push(line.trim())
		} else {
			current = null
		}
	})
	return new Promise((resolve, reject) => {
		logs.on('error', reject)
		logs.on('close', () => {
			const byQuery = new Map()
			for (const plan of plans) {
				const query = plan.lines.find((l) => l.startsWith('Query Text:'))?.slice(12) ?? ''
				if (!query.includes('ep_pad_bindings')) {
					continue
				}
				const seen = byQuery.get(query)
				if (seen) {
					seen.times++
					if (plan.ms > seen.maxMs) {
						seen.maxMs = plan.ms
						seen.plan = plan.lines.filter((l) => !l.startsWith('Query Text:'))
					}
				} else {
					byQuery.set(query, { query, times: 1, maxMs: plan.ms, plan: plan.lines.filter((l) => !l.startsWith('Query Text:')) })
				}
			}
			resolve([...byQuery.values()])
		})
	})
}

/**
 * The functions of Etherpad's API the app called between two instants, by
 * name, from the log of the way it reaches them (Caddyfile.load). Read as
 * it comes: a busy minute logs more than a buffer holds.
 */
export function etherpadCalls(since, until) {
	const logs = spawn('docker', ['compose', '-p', project, ...composeFiles, 'logs', '--no-color', '--no-log-prefix', '--since', since.toISOString(), '--until', until.toISOString(), 'caddy'], { stdio: ['ignore', 'pipe', 'inherit'] })
	const calls = {}
	createInterface({ input: logs.stdout }).on('line', (line) => {
		// Access lines only: Caddy's error lines name the request too.
		const fn = line.includes('"logger":"http.log.access') ? /"uri":"\/api\/[\d.]+\/(\w+)/.exec(line)?.[1] : undefined
		if (fn) {
			calls[fn] = (calls[fn] ?? 0) + 1
		}
	})
	return new Promise((resolve, reject) => {
		logs.on('error', reject)
		logs.on('close', (code) => (code === 0 ? resolve(calls) : reject(new Error(`docker compose logs: ${code}`))))
	})
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
