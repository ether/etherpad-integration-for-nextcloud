/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '../fixtures/browser-noise'
import { E2E } from '../fixtures/env'
import {
	closeViewer,
	gotoFiles,
	createPublicPad,
	expectEtherpadViewerMounted,
	openPadFromFileList,
	typeInEtherpad,
	uniquePadName,
} from '../fixtures/nextcloud'
import { createPadAtPath, deleteViaDav, findTrashbinEntry, purgeTrashbinEntry, restoreFromTrashViaDav } from '../fixtures/dav'
import { etherpadApiPost, padIdOfPadUrl } from '../fixtures/etherpad'

/**
 * Trash → restore round-trip. The meaningful behaviour to assert here
 * is that **opening a restored .pad still works cleanly** — i.e. our
 * binding survives the round-trip and the viewer mounts on first open.
 *
 * The trash and restore steps go through WebDAV deliberately. NC's
 * trash UI (kebab → "Delete" menuitem) is fragile to drive headlessly:
 * the Trash view virtualizes rows, the row-action menu mixes
 * "Löschdatum festlegen" with "Löschen", and the labels keep shifting
 * across NC releases. WebDAV `DELETE` is exactly the request the NC UI
 * button fires under the hood, so we cover the same lifecycle path
 * without coupling the spec to that DOM.
 *
 * What the trash and a delete for good do to the pad, Etherpad side
 * included, is covered by `pad-gone-for-good.spec.ts`, and a pad Etherpad
 * lost by `pad-lost.spec.ts`. This spec is the UI-side smoke check:
 * create → trash → restore → reopen.
 */
test.describe('pad trash + restore', () => {
	const padName = uniquePadName('trash-restore')

	test.afterAll(async () => {
		// Belt-and-braces: if the restore path failed mid-test, the file
		// may still be in trash and our regular DELETE would 404. Either
		// way the trash entry this leaves behind is swept by the global
		// teardown, not here.
		await deleteViaDav(padName)
	})

	test('reopens cleanly after a trash + restore round-trip', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)
		await expectEtherpadViewerMounted(page)
		await closeViewer(page)

		// NC's WebDAV DELETE moves the file to trash for normal user
		// accounts; same code path as the UI button.
		await deleteViaDav(padName)

		const trashEntry = await findTrashbinEntry(padName)
		expect(trashEntry, `Expected ${padName} to land in trash`).not.toBeNull()

		await restoreFromTrashViaDav(padName)

		await gotoFiles(page)
		await openPadFromFileList(page, padName)
		await expectEtherpadViewerMounted(page)
	})
})

/**
 * A protected pad loses its sessions when its file goes to the trash
 * (docs/deleting-pads.md): whoever still has it open writes no more from
 * their next change on. Etherpad refuses the change; whether it tells the
 * writer is Etherpad's, and Etherpad 2 does not.
 */
test.describe('an editor open while its file goes to the trash', () => {
	test.skip(E2E.etherpadApi === null, 'Needs E2E_ETHERPAD_URL and E2E_ETHERPAD_API_KEY; only the container stack has them.')

	test('a protected pad takes no more changes from it once its file is in the trash', async ({ page }) => {
		const name = uniquePadName('trash-open-editor')
		const pad = await createPadAtPath(`/${name}`, 'protected')
		const padId = padIdOfPadUrl(pad.padUrl)
		const padText = async (): Promise<string> => (await etherpadApiPost<{ text: string }>('getText', { padID: padId })).text
		try {
			await page.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/?file=${encodeURIComponent('/' + name)}`)
			await expectEtherpadViewerMounted(page)
			const typed = Date.now()
			await typeInEtherpad(page, 'typed before the trash ')
			await expect.poll(padText, { message: 'typing should reach the pad', timeout: 10_000 }).toContain('typed before the trash')
			const roundTrip = Date.now() - typed

			await deleteViaDav(name)
			await typeInEtherpad(page, 'typed after the trash ')
			// Nothing comes that could be waited for: the change gets several
			// times what the first one needed to arrive, three seconds at least.
			await page.waitForTimeout(Math.max(3_000, 3 * roundTrip))
			expect(await padText(), 'without its session, the change should be refused').not.toContain('typed after the trash')
		} finally {
			// Still in Files if the test stopped before the trash.
			await deleteViaDav(name).catch(() => {})
			const entry = await findTrashbinEntry(name)
			if (entry !== null) {
				await purgeTrashbinEntry(entry)
			}
		}
	})
})
