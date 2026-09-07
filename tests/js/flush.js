/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { vi } from 'vitest'

/**
 * Let the work a test just started run to completion.
 *
 * Yielding once to the queue behind the microtasks drains a chain of any
 * depth, as long as every step settles in one - which holds here because
 * `fetch` and its neighbours are stubbed. A step behind a real delay
 * still needs its own advance.
 */
export const flushAsyncWork = async () => {
	if (vi.isFakeTimers()) {
		// A faked queue only moves when it is told to.
		await vi.advanceTimersByTimeAsync(0)
		return
	}
	await new Promise((resolve) => { setTimeout(resolve, 0) })
}
