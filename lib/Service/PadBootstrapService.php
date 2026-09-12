<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\UnrecognisedPadContentException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCP\Files\File;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class PadBootstrapService {
	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private ManagedPadLifecycle $padLifecycle,
		private ISecureRandom $secureRandom,
		private LoggerInterface $logger,
		private PadLegacyMigrationService $legacyMigrationService,
		private PadTypePolicy $padTypePolicy,
		private UserNodeResolver $userNodeResolver,
	) {
	}

	/** Make the pad a `.pad` file's first open needs; the names say so. */
	public function provisionPadId(string $accessMode): string {
		return $this->padLifecycle->provisionFor(
			$accessMode,
			padId: fn (): string => 'nc-' . $this->secureRandom->generate(24, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS),
			groupPadName: fn (): string => $this->buildProtectedPadName(),
		);
	}

	/**
	 * Bootstrap the YAML frontmatter for a `.pad` file that doesn't have it
	 * yet. Returns true if the file was a legacy Ownpad shortcut and we ran
	 * the migration path (callers may want to surface that as a distinct
	 * status to the frontend); false for the regular empty-file init.
	 */
	public function initializeMissingFrontmatter(string $uid, File $file, string $existingContent, ?string $preferredAccessMode = null): bool {
		$fileId = (int)$file->getId();
		$existingContentTrimmed = trim($existingContent);
		$isEmptyFile = $existingContentTrimmed === '';
		$legacyShortcut = $this->padFileService->parseLegacyOwnpadShortcut($existingContent);
		if (!$isEmptyFile && $legacyShortcut === null) {
			// Not the same thing as "has no frontmatter yet": this file has
			// content that is neither. Saying so with the other type would
			// answer this refusal with the code that asks a client to call
			// exactly this endpoint.
			throw new UnrecognisedPadContentException('This .pad file holds content that is neither pad metadata nor a legacy shortcut.');
		}
		if ($legacyShortcut !== null) {
			$this->legacyMigrationService->migrate($uid, $file, $legacyShortcut);
			return true;
		}

		$binding = $this->bindingService->findByFileId($fileId);
		$createdNewPad = false;

		$padId = '';

		// The provisioning is inside the try, not before it. The cleanup used
		// to start only after the binding had been written, so a binding that
		// failed left the pad behind with nothing pointing at it — the one
		// failure in this method that produced an orphan rather than an error.
		try {
			if ($binding !== null) {
				$padId = (string)$binding['pad_id'];
				$accessMode = (string)$binding['access_mode'];
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

			$padUrl = $this->etherpadClient->buildPadUrl($padId);
			$doc = $this->padFileService->buildInitialDocument($fileId, $padId, $accessMode, padUrl: $padUrl);
			// Re-resolve after provisioning: the original File still writes to its remembered path.
			$this->writeInitialDocument($uid, $fileId, $existingContent, $doc);
		} catch (\Throwable $e) {
			if ($createdNewPad) {
				$this->rollbackProvisionedPad($fileId, $padId);
			}
			throw $e;
		}
		return false;
	}

	/**
	 * @throws PadFileChangedException when the content differs from what the caller read
	 */
	private function writeInitialDocument(string $uid, int $fileId, string $expectedBefore, string $doc): void {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		if ((string)$node->getContent() !== $expectedBefore) {
			throw new PadFileChangedException('The .pad file changed while its pad was being provisioned.');
		}
		$node->putContent($doc);
	}

	private function rollbackProvisionedPad(int $fileId, string $padId): void {
		$this->unwind()->keepingWhatTheRowClaims($fileId, $padId, 'first init');
	}

	private function buildProtectedPadName(): string {
		return 'p-' . $this->secureRandom->generate(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
	}

	private function unwind(): PadMaterialisationUnwind {
		return new PadMaterialisationUnwind($this->bindingService, $this->padLifecycle, $this->logger);
	}
}
