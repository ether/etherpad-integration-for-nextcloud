<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Util\PadId;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Lazy per-file migration of legacy Ownpad `.pad` files (those holding an
 * `[InternetShortcut]` block instead of YAML frontmatter) into the current
 * binding-and-`.pad` model. Called from `PadBootstrapService` when the
 * detection in `PadFileService::parseLegacyOwnpadShortcut` matches.
 *
 * Three branches, all silent in the happy path:
 *
 * 1. **Cross-origin** — the source URL points at a different Etherpad
 *    server than the one we manage. We can't apply protected-mode auth to
 *    a server we don't control, so we route through the existing external
 *    pad shape: write `ext.*` frontmatter, no binding row. Public-only.
 *
 * 2. **Same-origin, no collision** — the source pad-id is not yet bound
 *    to any NC file. We write fresh YAML frontmatter referencing the
 *    same pad-id and create a binding row. The access mode is derived
 *    from the pad-id format (g.X$Y → protected, anything else → public).
 *
 * 3. **Same-origin, collision** — another NC file already owns the
 *    binding for this pad-id. If the requesting user can read that
 *    original file we write frontmatter only (no new binding) and the
 *    file behaves like a copy-of-a-pad; the existing copy-handling in
 *    the open flow takes over. If the user has no access we throw
 *    `LegacyPadCollisionException` and the `.pad` file is left
 *    untouched — subsequent opens see the same legacy state.
 */
class PadLegacyMigrationService {
	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private ExternalPadSeeder $externalPadSeeder,
		private UserNodeResolver $userNodeResolver,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A group pad this file names has to be a group pad that is there.
	 *
	 * The pad id in a legacy `.pad` is written by whoever wrote the file,
	 * and for a group pad it decides which Etherpad group a session is
	 * later minted for. Binding someone else's *existing* pad is already
	 * refused: `pad_id` is unique, so it collides with their binding and
	 * comes out as a collision the access check handles. Inventing a suffix
	 * walked around that — `g.<their-group>$anything` collides with nothing
	 * while naming their group, and the session issued on open grants
	 * access to everything in it.
	 *
	 * Asking whether the pad is in the group keeps the honest case intact:
	 * a real Ownpad pad is in its group, which is why the file names it. A
	 * made-up one is not.
	 *
	 * Fails closed. A refused migration is retried on the next open; a
	 * migration waved through on a failed read cannot be taken back.
	 */
	private function assertGroupPadExists(string $sourcePadId): void {
		$groupId = PadId::groupIdOf($sourcePadId);
		if ($groupId === null) {
			return;
		}

		if (!in_array($sourcePadId, $this->etherpadClient->listPads($groupId), true)) {
			throw new PadFileFormatException('The legacy .pad file names a pad that does not exist in its Etherpad group.');
		}
	}

	/**
	 * Migrate the legacy `.pad` file in place.
	 *
	 * @param array{url:string,pad_id:string} $legacyShortcut output of
	 *   `PadFileService::parseLegacyOwnpadShortcut`
	 *
	 * @throws LegacyPadCollisionException when the pad is already bound to
	 *   a file the requesting user has no access to.
	 */
	public function migrate(string $uid, File $file, array $legacyShortcut): void {
		$fileId = (int)$file->getId();
		$sourceUrl = $legacyShortcut['url'];
		$sourcePadId = $legacyShortcut['pad_id'];

		$configuredOrigin = $this->etherpadClient->getConfiguredOrigin();
		$sourceOrigin = $this->etherpadClient->normalizeOrigin($sourceUrl);
		$isSameOrigin = $configuredOrigin !== '' && $configuredOrigin === $sourceOrigin;

		if (!$isSameOrigin) {
			$this->externalPadSeeder->seed($file, $fileId, $sourceUrl);
			$this->logger->info('Migrated legacy Ownpad .pad as external public pad.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceUrl' => $sourceUrl,
				'originBranch' => 'cross',
				'uid' => $uid,
			]);
			return;
		}

		$this->assertGroupPadExists($sourcePadId);

		$accessMode = $this->padFileService->inferAccessModeFromPadId($sourcePadId);
		$existingBinding = $this->bindingService->findByPadId($sourcePadId, BindingService::STATE_ACTIVE);

		if ($existingBinding === null) {
			// Create the binding first, then write the file. If the binding
			// fails (e.g. a concurrent migration claimed the same pad-id
			// between findByPadId and createBinding), we re-classify as a
			// collision and fall through to the access check — the .pad
			// file is left untouched so the next open retries cleanly.
			//
			// Doing it in the other order would leave a half-migrated state:
			// the file would have managed frontmatter pointing at a pad-id
			// with no binding row, and the existing copy-recovery flow can't
			// help (it needs *some* binding for that pad-id to exist).
			try {
				$this->bindingService->createBinding($fileId, $sourcePadId, $accessMode);
			} catch (BindingException $e) {
				$existingBinding = $this->bindingService->findByPadId($sourcePadId, BindingService::STATE_ACTIVE);
				if ($existingBinding === null) {
					// Race lost AND no winner — surface the original failure
					// so the open retry sees a clear "could not initialize".
					throw $e;
				}
				$this->logger->info('Legacy Ownpad migration lost a race for the pad-id; reclassifying as collision.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'padId' => $sourcePadId,
					'uid' => $uid,
				]);
				// Fall through to the collision-with-access handling below.
			}
			if ($existingBinding === null) {
				$this->writeManagedFrontmatter($file, $fileId, $sourcePadId, $accessMode);
				$this->logger->info('Migrated legacy Ownpad .pad as managed pad (re-bind).', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'sourceUrl' => $sourceUrl,
					'originBranch' => 'same',
					'accessMode' => $accessMode,
					'padId' => $sourcePadId,
					'collision' => 'none',
					'uid' => $uid,
				]);
				return;
			}
		}

		$boundFileId = (int)$existingBinding['file_id'];
		if ($boundFileId === $fileId) {
			// Self-collision: an earlier migration attempt for this file
			// succeeded in creating the binding but failed before the file
			// write. Just finish the file-side work; the binding already
			// covers us.
			$this->writeManagedFrontmatter($file, $fileId, $sourcePadId, $accessMode);
			$this->logger->info('Migrated legacy Ownpad .pad — finishing partially-completed prior migration.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceUrl' => $sourceUrl,
				'originBranch' => 'same',
				'accessMode' => $accessMode,
				'padId' => $sourcePadId,
				'collision' => 'self',
				'uid' => $uid,
			]);
			return;
		}

		try {
			$this->userNodeResolver->resolveUserFileNodeById($uid, $boundFileId);
		} catch (NotFoundException) {
			$this->logger->warning('Refused legacy Ownpad migration — pad already bound to a file the user cannot read.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceUrl' => $sourceUrl,
				'padId' => $sourcePadId,
				'boundFileId' => $boundFileId,
				'collision' => 'no_access',
				'uid' => $uid,
			]);
			throw new LegacyPadCollisionException(
				'This pad is already linked to another file you do not have access to.',
			);
		}

		// Collision-with-access: write managed frontmatter but do NOT create
		// a second binding row. The file becomes a "copy of a pad" in our
		// model and the existing copy-handling flow takes over.
		$this->writeManagedFrontmatter($file, $fileId, $sourcePadId, $accessMode);
		$this->logger->info('Migrated legacy Ownpad .pad as copy of an already-bound pad.', [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'sourceUrl' => $sourceUrl,
			'originBranch' => 'same',
			'accessMode' => $accessMode,
			'padId' => $sourcePadId,
			'collision' => 'with_access',
			'boundFileId' => $boundFileId,
			'uid' => $uid,
		]);
	}

	private function writeManagedFrontmatter(File $file, int $fileId, string $padId, string $accessMode): void {
		$padUrl = $this->etherpadClient->buildPadUrl($padId);
		$content = $this->padFileService->buildInitialDocument(
			$fileId,
			$padId,
			$accessMode,
			padUrl: $padUrl,
		);
		$file->putContent($content);
	}
}
