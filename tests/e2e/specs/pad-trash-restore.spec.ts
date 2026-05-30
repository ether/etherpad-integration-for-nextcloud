/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	closeViewer,
	gotoFiles,
	createPublicPad,
	expectEtherpadViewerMounted,
	openPadFromFileList,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav, findTrashbinEntry, restoreFromTrashViaDav } from '../fixtures/dav'

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
 * Server-side lifecycle (binding teardown, deferred delete, Etherpad-
 * side cleanup) is already covered end-to-end by the bash specs in
 * `tests/integration/e2e-lifecycle-*.sh`. This spec is the UI-side
 * smoke check: create → trash → restore → reopen.
 */
test.describe('pad trash + restore', () => {
	const padName = uniquePadName('trash-restore')

	test.afterAll(async () => {
		// Belt-and-braces: if the restore path failed mid-test, the file
		// may still be in trash and our regular DELETE would 404. Either
		// way nothing else needs cleaning here.
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
