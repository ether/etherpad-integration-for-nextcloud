<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\UnrecognisedPadContentException;
use OCA\EtherpadNextcloud\Exception\PadFileNotWritableException;
use OCP\Security\ISecureRandom;

class PadBootstrapService {
	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private ClaimedFileWriter $claimedFileWriter,
		private ManagedPadLifecycle $padLifecycle,
		private ISecureRandom $secureRandom,
		private PadLegacyMigrationService $legacyMigrationService,
		private PadTypePolicy $padTypePolicy,
		private UserNodeResolver $userNodeResolver,
		private ProvisionedPadRollback $provisionedPadRollback,
	) {
	}

	/** Make the pad a `.pad` file's first open needs; the names say so. */
	public function provisionPadId(string $accessMode): string {
		return $this->padLifecycle->provisionFor($accessMode, padId: $this->newPadId(...), groupPadName: $this->newGroupPadName(...));
	}

	/** The id a new public pad is created under. */
	public function newPadId(): string {
		return 'nc-' . $this->secureRandom->generate(24, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
	}

	/** The name a new protected pad carries in its group. */
	public function newGroupPadName(): string {
		return 'p-' . $this->secureRandom->generate(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
	}

	/**
	 * Bootstrap the YAML frontmatter for a `.pad` file that doesn't have it
	 * yet. Returns true if the file was a legacy Ownpad shortcut and we ran
	 * the migration path (callers may want to surface that as a distinct
	 * status to the frontend); false for the regular empty-file init.
	 *
	 * Either writes the file, so whoever may not change it - a reader of a
	 * read-only share - is refused before a pad, group or row is made: the
	 * write would fail after them, and leave them to the owner's next open.
	 * Asked of the file by its id, as the write finds it - a writable path
	 * to it first, as a reader by one path may be a writer by another. The
	 * init and the legacy migration both write through ClaimedFileWriter,
	 * which finds the file by its id again and holds it to what was read.
	 * $fileId is the one the caller read and checked.
	 *
	 * @throws PadFileNotWritableException
	 */
	public function initializeMissingFrontmatter(string $uid, int $fileId, string $existingContent, ?string $preferredAccessMode = null): bool {
		$legacyShortcut = $this->padFileService->parseLegacyOwnpadShortcut($existingContent);
		if (trim($existingContent) !== '' && $legacyShortcut === null) {
			// Not the same thing as "has no frontmatter yet": this file has
			// content that is neither. Saying so with the other type would
			// answer this refusal with the code that asks a client to call
			// exactly this endpoint.
			throw new UnrecognisedPadContentException('This .pad file holds content that is neither pad metadata nor a legacy shortcut.');
		}
		$writable = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		PadFileNotWritableException::unlessUpdateable($writable);
		if ($legacyShortcut !== null) {
			$this->legacyMigrationService->migrate(new CreatedFileClaim($uid, $fileId, $existingContent), $legacyShortcut);
			return true;
		}

		$binding = $this->bindingService->findByFileId($fileId);
		$createdNewPad = false;

		$padId = '';

		// createBinding sits inside the try, so a failed row reaches the
		// cleanup as a failed document does.
		try {
			if ($binding !== null) {
				$padId = $binding->padId;
				$accessMode = $binding->accessMode;
			} else {
				// No binding yet, so this provisions a brand-new pad rather than
				// re-initialising an existing one — the policy applies. Files that
				// already have a binding fall into the branch above and keep
				// working whatever the admin configured.
				//
				// Fall back rather than refuse: an empty `.pad` can arrive outside
				// the UI (WebDAV, another integration, or from before the setting
				// changed), and a hard requirement would leave it permanently
				// unopenable even when the other pad type is available.
				// A caller may know which type was asked for — the template picker
				// does. The policy still has the last word, so a type disabled
				// between choosing and creating falls back instead of failing.
				$accessMode = $this->padTypePolicy->resolveCreatableMode($preferredAccessMode ?? BindingService::ACCESS_PROTECTED);
				$padId = $this->provisionPadId($accessMode);
				// Ours from here, before the binding exists.
				$createdNewPad = true;
				$this->bindingService->createBinding($fileId, $padId, $accessMode);
			}

			$this->claimedFileWriter->writePadDocument(new CreatedFileClaim($uid, $fileId, $existingContent), $padId, $accessMode);
		} catch (\Throwable $e) {
			if ($createdNewPad) {
				$this->provisionedPadRollback->discardUnlessBoundToFile($fileId, $padId, 'first init');
			}
			throw $e;
		}
		return false;
	}
}
