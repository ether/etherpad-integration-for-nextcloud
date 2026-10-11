/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '../fixtures/browser-noise'
import {
	deleteViaDav,
	getFileViaDav,
	padApiPost,
	propfindFileId,
	putFileViaDav,
} from '../fixtures/dav'
import { uniqueName } from '../fixtures/nextcloud'

/**
 * Content round-trip through the snapshot -> new-pad push that both the
 * restore and the "create new pad from this file" recovery flows rely on
 * (`ManagedPadLifecycle::seed` -> `EtherpadClient::setText/setHTML`).
 *
 * This is the deterministic, credential-free way to prove "the .pad
 * content is correctly copied into the freshly provisioned pad" that a
 * delete/restore raises: we don't need the Etherpad API key or to type
 * into the editor iframe, because the plugin itself owns both the push
 * (recover endpoint) and the read-back (sync endpoint).
 *
 *   1. PUT an orphan .pad carrying a KNOWN marker snapshot (we control
 *      the bytes; the pad_id is fresh + unbound, so this is the
 *      "no matching pad" recovery branch).
 *   2. POST recover-from-snapshot -> the plugin provisions a new pad and
 *      pushes the marker into it via setText/setHTML.
 *   3. POST sync -> the plugin pulls the *new pad's actual content* back
 *      into the .pad file. If the push in step 2 had failed, the new pad
 *      would be empty and sync would wipe the marker.
 *   4. GET the .pad -> the marker is still there, proving the content
 *      really landed in the new pad.
 *
 * API calls use the app password over BasicAuth; no browser page is
 * needed.
 */
// The frontmatter file_id must be a valid positive int that matches the
// node's real id. We only learn that id after the file exists, so we PUT
// a placeholder, read the id, then rewrite the frontmatter with it.
const buildOrphanPad = (fileId: number, padId: string, marker: string): string => [
	'---',
	'format: "etherpad-nextcloud/1"',
	`file_id: ${fileId}`,
	`pad_id: "${padId}"`,
	'access_mode: "public"',
	'state: "active"',
	'deleted_at: null',
	'created_at: "2026-01-01T00:00:00+00:00"',
	'updated_at: "2026-01-01T00:00:00+00:00"',
	'snapshot_rev: 1',
	`pad_url: "https://pad.example.invalid/p/${padId}"`,
	'---',
	'[TEXT]',
	marker,
	'',
	'[HTML-BEGIN]',
	`<!DOCTYPE HTML><html><body>${marker}<br></body></html>`,
	'[HTML-END]',
	'',
].join('\n')

/** An orphan .pad with the marker, put in place and given its file id: what a recovery starts from. */
const putOrphanPad = async (padName: string, padId: string, marker: string): Promise<number> => {
	await putFileViaDav(padName, buildOrphanPad(1, padId, marker))
	const fileId = await propfindFileId(padName)
	expect(fileId).toBeGreaterThan(0)
	await putFileViaDav(padName, buildOrphanPad(fileId, padId, marker))
	return fileId
}

test.describe('snapshot -> pad content round-trip (recover + sync)', () => {
	const padName = uniqueName('roundtrip', 'pad')
	const marker = `roundtrip-marker-${Date.now()}-Zürich-✓`
	const freshPadId = `e2e-roundtrip-${Date.now()}`

	test.afterAll(async () => {
		await deleteViaDav(padName).catch(() => {})
	})

	test('recovery pushes the .pad snapshot into a new pad and sync reads it back', async () => {
		const fileId = await putOrphanPad(padName, freshPadId, marker)

		// Recover: provision a new pad and push the stored snapshot into it.
		const recover = await padApiPost(`pads/recover-from-snapshot/${fileId}`)
		expect(recover.status, `recover HTTP ${recover.status}`).toBeGreaterThanOrEqual(200)
		expect(recover.status).toBeLessThan(300)
		const recovered = recover.body as { status?: string, new_pad_id?: string }
		expect(recovered.status).toBe('restored')
		expect(recovered.new_pad_id, 'recover should report the new pad id').toBeTruthy()
		expect(recovered.new_pad_id).not.toBe(freshPadId)

		// Sync: pull the new pad's actual content back into the .pad file.
		const sync = await padApiPost(`pads/sync/${fileId}`)
		expect(sync.status, `sync HTTP ${sync.status}`).toBeGreaterThanOrEqual(200)
		expect(sync.status).toBeLessThan(300)

		// The marker survived the full snapshot -> new pad -> sync chain, so
		// the content genuinely reached the freshly provisioned pad.
		const after = await getFileViaDav(padName)
		expect(after).toContain(marker)
		// And the file now points at the new pad, not the crafted orphan id.
		expect(after).toContain(recovered.new_pad_id as string)
		expect(after).not.toContain(`pad_id: "${freshPadId}"`)
	})
})

/**
 * The sync lock against Nextcloud's own locking provider: syncs sent at
 * once leave it free and the file whole. That they run one after the other
 * the unit tests show; a sync may wait in vain on a slow runner and answer
 * locked, which is timing, not a failure. A pad of its own, so the case
 * runs - and is retried - alone.
 */
test.describe('syncs of one file sent at once', () => {
	const padName = uniqueName('sync-turns', 'pad')
	const marker = `sync-turns-marker-${Date.now()}`
	let fileId = 0

	test.beforeAll(async () => {
		fileId = await putOrphanPad(padName, `e2e-sync-turns-${Date.now()}`, marker)
		const recover = await padApiPost(`pads/recover-from-snapshot/${fileId}`)
		expect((recover.body as { status?: string }).status).toBe('restored')
	})

	test.afterAll(async () => {
		await deleteViaDav(padName).catch(() => {})
	})

	test('forced syncs sent at once leave the lock free and the file whole', async () => {
		const syncs = await Promise.all([1, 2, 3, 4].map(() => padApiPost(`pads/sync/${fileId}?force=1`)))

		const statuses = syncs.map((sync) => (sync.body as { status?: string }).status)
		for (const sync of syncs) {
			expect(sync.status).toBe(200)
		}
		for (const status of statuses) {
			expect(['updated', 'unchanged', 'locked']).toContain(status)
		}
		expect(statuses.filter((status) => status !== 'locked').length).toBeGreaterThan(0)
		// Sent once all four answered, so none holds the lock, and after one
		// of them wrote the pad or found it written: nothing is left to write.
		const after = await padApiPost(`pads/sync/${fileId}?force=1`)
		expect((after.body as { status?: string }).status).toBe('unchanged')
		expect(await getFileViaDav(padName)).toContain(marker)
	})
})
