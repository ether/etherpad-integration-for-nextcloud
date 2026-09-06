/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Let the work a test just started run to completion.
 *
 * Every awaited step in these suites resolves in a microtask, because
 * `fetch` and the modules around it are stubbed with settled promises - so
 * yielding to the macrotask queue once drains the whole chain, however
 * many links it has. Counting microtask turns instead pins a test to how
 * many `await`s the code happens to have today, and the next one added
 * silently runs the assertions before the work.
 *
 * It does not wait for real timers, so a step scheduled with setTimeout
 * needs its own advance.
 */
export const flushAsyncWork = async () => {
	await new Promise((resolve) => { setTimeout(resolve, 0) })
}
