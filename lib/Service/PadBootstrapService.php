<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

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

	/**
	 * Seeds a freshly provisioned pad with content. Tries setHTML first so
	 * formatting (headings, lists, bold/italic) survives, and falls back to
	 * plain-text setText if the HTML import fails or no HTML snapshot is
	 * available.
	 *
	 * We intentionally do NOT also call setText after a successful setHTML:
	 * Etherpad's `setText` replaces the pad content rather than appending, so
	 * doing both would wipe the formatted HTML we just imported. The minor
	 * downside — `getText` returns plain text Etherpad derived from the HTML
	 * rather than the exact string we put into the frontmatter snapshot — is
	 * acceptable in exchange for keeping the rich formatting.
	 */
	public function pushInitialSnapshot(string $padId, string $text, string $html): void {
		if (trim($html) !== '') {
			try {
				$this->etherpadClient->setHTML($padId, $html);
				return;
			} catch (\Throwable $htmlError) {
				$this->logger->warning('Initial HTML push failed, falling back to plain text.', [
					'app' => 'etherpad_nextcloud',
					'padId' => $padId,
					'exception' => $htmlError,
				]);
			}
		}
		$this->etherpadClient->setText($padId, $text);
	}

	public function provisionPadId(string $accessMode): string {
		if ($accessMode === BindingService::ACCESS_PUBLIC) {
			$padId = 'nc-' . $this->secureRandom->generate(24, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
			$this->padLifecycle->provisionPad($padId);
			return $padId;
		}

		if ($accessMode !== BindingService::ACCESS_PROTECTED) {
			throw new \InvalidArgumentException('Unsupported access mode for pad provisioning.');
		}

		return $this->padLifecycle->provisionGroupPad($this->buildProtectedPadName());
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
			throw new PadFileFormatException('Missing YAML frontmatter in .pad file.');
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
			$doc = $this->padFileService->buildInitialDocument($fileId, $padId, $accessMode, '', $padUrl);
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

	/**
	 * What to undo after a failed first init, decided by what the binding
	 * row says.
	 *
	 * A row naming this pad is not damage — it is the finished half of the
	 * job. The file is still empty, so the next open comes back through this
	 * method, finds the binding and writes the frontmatter it did not manage
	 * to write; the template listener says as much where it swallows this
	 * error. Removing anything there means giving up a working file for a
	 * best-effort remote call that can fail and leave the pad unreachable
	 * with the last reference to it already deleted. So: leave both.
	 *
	 * That covers the two ways `createBinding` fails without saying so — an
	 * insert that committed before the connection dropped, and an insert the
	 * unique constraint refused because a concurrent first-open won the
	 * race. Only the first leaves a row naming *this* pad. The other, and a
	 * failure before any row was written, leave the pad with nothing
	 * pointing at it, and that is the one to clean up.
	 */
	private function rollbackProvisionedPad(int $fileId, string $padId): void {
		try {
			if ($this->bindingService->isBoundTo($fileId, $padId)) {
				return;
			}
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not read the binding after frontmatter init failure; keeping its pad.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				'exception' => $cleanupError,
			]);
			return;
		}

		try {
			// Provisioned by this call, so the group needs no ownership
			// check — and must not depend on one, because nothing retries
			// this.
			$this->padLifecycle->discardProvisioned($padId);
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not cleanup Etherpad pad after frontmatter init failure.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				'exception' => $cleanupError,
			]);
		}
	}

	private function buildProtectedPadName(): string {
		return 'p-' . $this->secureRandom->generate(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
	}
}
