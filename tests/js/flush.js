/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { vi } from 'vitest'

/**
 * Let the work a test just started run to completion.
 *
 * Every awaited step in these suites resolves in a microtask, because
 * `fetch` and the modules around it are stubbed with settled promises - so
 * yielding to the queue behind them once drains the whole chain, however
 * many links it has. Counting microtask turns instead pins a test to how
 * many `await`s the code happens to have today, and the next one added
 * silently runs the assertions before the work.
 *
 * It waits for nothing that is scheduled: a step behind a real delay still
 * needs its own advance.
 */
export const flushAsyncWork = async () => {
	if (vi.isFakeTimers()) {
		// A faked macrotask queue only moves when it is told to, so ask for
		// the same turn rather than a real one that would never come.
		await vi.advanceTimersByTimeAsync(0)
		return
	}
	await new Promise((resolve) => { setTimeout(resolve, 0) })
}
