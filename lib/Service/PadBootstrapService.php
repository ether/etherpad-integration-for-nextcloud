<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCP\Files\File;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class PadBootstrapService {
	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private ISecureRandom $secureRandom,
		private LoggerInterface $logger,
		private PadLegacyMigrationService $legacyMigrationService,
		private PadTypePolicy $padTypePolicy,
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
			$this->etherpadClient->createPad($padId);
			return $padId;
		}

		if ($accessMode !== BindingService::ACCESS_PROTECTED) {
			throw new \InvalidArgumentException('Unsupported access mode for pad provisioning.');
		}

		$groupId = $this->etherpadClient->createGroup();
		$padName = $this->buildProtectedPadName();
		return $this->etherpadClient->createGroupPad($groupId, $padName);
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
		$createdNewBinding = false;
		$createdNewPad = false;

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
			$this->bindingService->createBinding($fileId, $padId, $accessMode);
			$createdNewBinding = true;
			$createdNewPad = true;
		}

		try {
			$padUrl = $this->etherpadClient->buildPadUrl($padId);
			$doc = $this->padFileService->buildInitialDocument($fileId, $padId, $accessMode, '', $padUrl);
			$file->putContent($doc);
		} catch (\Throwable $e) {
			if ($createdNewBinding) {
				if ($createdNewPad) {
					try {
						$this->etherpadClient->deletePad($padId);
					} catch (\Throwable $cleanupError) {
						$this->logger->warning('Could not cleanup Etherpad pad after frontmatter init failure.', [
							'app' => 'etherpad_nextcloud',
							'fileId' => $fileId,
							'padId' => $padId,
							'exception' => $cleanupError,
						]);
					}
				}
				try {
					$this->bindingService->deleteByFileId($fileId);
				} catch (\Throwable $cleanupError) {
					$this->logger->warning('Could not cleanup binding after frontmatter init failure.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'padId' => $padId,
						'exception' => $cleanupError,
					]);
				}
			}
			throw $e;
		}
		return false;
	}

	private function buildProtectedPadName(): string {
		return 'p-' . $this->secureRandom->generate(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
	}
}
