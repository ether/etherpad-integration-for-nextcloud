<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\LegacyPadNotFoundException;
use OCA\EtherpadNextcloud\Exception\LegacyProtectedImportDisabledException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCA\EtherpadNextcloud\Util\DiagnosticText;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\PadId;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Lazy per-file migration of legacy Ownpad `.pad` files (an
 * `[InternetShortcut]` block instead of YAML frontmatter) into the current
 * binding-and-`.pad` model, called from `PadBootstrapService`. The branches
 * and what each leaves behind are in docs/legacy-ownpad-migration.md.
 *
 * The document goes in through ClaimedFileWriter, which checks the file
 * against the shortcut as the caller read it before writing.
 */
class PadLegacyMigrationService {
	public function __construct(
		private BindingService $bindingService,
		private LegacyImportPolicy $legacyImportPolicy,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private ExternalPadSeeder $externalPadSeeder,
		private UserNodeResolver $userNodeResolver,
		private LoggerInterface $logger,
		private ClaimedFileWriter $claimedFileWriter,
		private PadFileLockRetryService $lockRetry,
	) {
	}

	/**
	 * Whether the group a group pad names lists it; true for a pad that
	 * names no group. `g.<their-group>$invented` collides with no binding
	 * while naming a real group, and the session issued on open grants all
	 * of it. A group Etherpad does not know counts as one without the pad,
	 * so the answer does not tell whether the group is there; any other
	 * refusal or failure goes on. Fails closed: a refused migration is
	 * retried, a waved-through one is not.
	 */
	private function groupHolds(string $sourcePadId): bool {
		$groupId = PadId::groupIdOf($sourcePadId);
		if ($groupId === null) {
			return true;
		}
		try {
			return in_array($sourcePadId, $this->etherpadClient->listPads($groupId), true);
		} catch (EtherpadRefusedException $e) {
			if (EtherpadErrorClassifier::isGroupUnknown($e)) {
				return false;
			}
			throw $e;
		}
	}

	/**
	 * A row that binds the pad under other capitals: with Etherpad's
	 * lowerCasePadIds on, one of the two ids can reach the other's pad
	 * (PadId::isValid()). Not asked for a group pad: groupHolds() takes only
	 * an id its group lists, which the API acts on as it is.
	 */
	private function rowInOtherCase(string $sourcePadId): ?Binding {
		return PadId::groupIdOf($sourcePadId) === null
			? $this->bindingService->findByPadIdInOtherCase($sourcePadId)
			: null;
	}

	private function unlinkable(string $uid, int $fileId, string $sourceUrl, string $accessMode, ?string $collision, string $reason, string $why, ?int $boundFileId = null): LegacyPadNotFoundException {
		$context = [
			...$this->sameOriginContext($uid, $fileId, $sourceUrl, $accessMode, $collision),
			'reason' => $reason,
		];
		if ($boundFileId !== null) {
			$context['boundFileId'] = $boundFileId;
		}
		$this->logger->warning('Refused legacy Ownpad migration - the pad it names cannot be linked.', $context);
		return new LegacyPadNotFoundException($why);
	}

	/**
	 * The fields of every same-origin line (docs/legacy-ownpad-migration.md,
	 * "Audit log"); `collision` only where a row was looked up and decided -
	 * $collision is null otherwise, and the field left out.
	 *
	 * @return array<string,int|string>
	 */
	private function sameOriginContext(string $uid, int $fileId, string $sourceUrl, string $accessMode, ?string $collision): array {
		$context = [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'sourceHost' => DiagnosticText::hostOf($sourceUrl),
			'originBranch' => 'same',
			'accessMode' => $accessMode,
		];
		if ($collision !== null) {
			$context['collision'] = $collision;
		}
		$context['uid'] = $uid;
		return $context;
	}

	/**
	 * @throws LegacyProtectedImportDisabledException when the file names a
	 *   group pad this instance does not import
	 */
	private function refuseProtectedImportIfSwitchedOff(
		string $uid,
		int $fileId,
		string $sourceUrl,
		string $accessMode,
	): void {
		if ($accessMode !== BindingService::ACCESS_PROTECTED) {
			return;
		}
		if ($this->legacyImportPolicy->allowsProtectedImport()) {
			return;
		}
		$this->logger->warning('Refused legacy Ownpad migration - protected import is switched off.', $this->sameOriginContext($uid, $fileId, $sourceUrl, $accessMode, 'none'));
		throw new LegacyProtectedImportDisabledException('Importing protected Ownpad pads is switched off on this server.');
	}

	/**
	 * Migrate the legacy `.pad` file in place.
	 *
	 * @param CreatedFileClaim $claim the file, and the shortcut as it was read
	 * @param array{url:string,pad_id:string} $legacyShortcut output of
	 *   `PadFileService::parseLegacyOwnpadShortcut`
	 *
	 * @throws LegacyPadCollisionException when the pad is already bound to
	 *   a file the requesting user has no access to.
	 * @throws LegacyProtectedImportDisabledException when the file names a
	 *   group pad and this instance does not import those.
	 * @throws LegacyPadNotFoundException when the file names a pad it cannot
	 *   be linked to.
	 * @throws \OCA\EtherpadNextcloud\Exception\PadFileChangedException
	 *   when the file no longer holds the shortcut as it was read.
	 */
	public function migrate(CreatedFileClaim $claim, array $legacyShortcut): void {
		$uid = $claim->uid;
		$fileId = $claim->fileId;
		$sourceUrl = $legacyShortcut['url'];
		$sourcePadId = $legacyShortcut['pad_id'];

		if (!$this->isSameOrigin($sourceUrl)) {
			$this->claimedFileWriter->write($claim, $this->externalPadSeeder->prepare($fileId, $sourceUrl)['content']);
			$this->logger->info('Migrated legacy Ownpad .pad as external public pad.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceHost' => DiagnosticText::hostOf($sourceUrl),
				'originBranch' => 'cross',
				'uid' => $uid,
			]);
			return;
		}

		$accessMode = $this->padFileService->inferAccessModeFromPadId($sourcePadId);
		// Before any row is looked up, on every way below - a copy and a
		// finish too: an id Etherpad's API rewrites would reach another
		// file's pad past the collision rule. The document is built now so
		// the format refuses an id it cannot hold before a row is made; and
		// invalid UTF-8 and NUL must not reach PostgreSQL, which refuses them.
		$document = PadId::isValid($sourcePadId) ? $this->managedDocument($fileId, $sourcePadId, $accessMode) : null;
		if ($document === null) {
			throw $this->unlinkable($uid, $fileId, $sourceUrl, $accessMode, null, 'invalid_id', 'The legacy .pad file names a pad id that cannot be linked.');
		}
		$existingBinding = $this->bindingService->findByPadId($sourcePadId, BindingService::STATE_ACTIVE);

		if ($existingBinding === null) {
			// Unbound pads only; a bound one is the collision rule's
			// question below.
			$this->refuseProtectedImportIfSwitchedOff($uid, $fileId, $sourceUrl, $accessMode);
			// After the switched-off refusal: asking first would answer three
			// ways and make this an existence oracle on a privileged API.
			if (!$this->groupHolds($sourcePadId)) {
				throw $this->unlinkable($uid, $fileId, $sourceUrl, $accessMode, 'none', 'not_in_group', 'The legacy .pad file names a pad its Etherpad group does not hold.');
			}
			$otherCase = $this->rowInOtherCase($sourcePadId);
			if ($otherCase !== null) {
				throw $this->unlinkable($uid, $fileId, $sourceUrl, $accessMode, 'none', 'case_variant', 'The legacy .pad file names a pad bound under other capitals.', $otherCase->fileId);
			}
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
				$this->logger->info('Legacy Ownpad migration lost a race for the pad-id; reclassifying as collision.', $this->sameOriginContext($uid, $fileId, $sourceUrl, $accessMode, null));
				// Fall through to the collision-with-access handling below.
			}
			if ($existingBinding === null) {
				$context = $this->sameOriginContext($uid, $fileId, $sourceUrl, $accessMode, 'none');
				$this->writeOrTakeBackTheRow($claim, $document, $sourcePadId, $context);
				$this->logger->info('Migrated legacy Ownpad .pad as managed pad (re-bind).', $context);
				return;
			}
		}

		$boundFileId = $existingBinding->fileId;
		if ($boundFileId === $fileId) {
			// Self-collision: an earlier migration attempt for this file
			// succeeded in creating the binding but failed before the file
			// write - or this one's insert landed and still threw. Just finish
			// the file-side work; the binding already covers us.
			$context = $this->sameOriginContext($uid, $fileId, $sourceUrl, $accessMode, 'self');
			$this->writeOrTakeBackTheRow($claim, $document, $sourcePadId, $context);
			$this->logger->info('Migrated legacy Ownpad .pad — finishing partially-completed prior migration.', $context);
			return;
		}

		try {
			$this->userNodeResolver->resolveUserFileNodeById($uid, $boundFileId);
		} catch (NotFoundException) {
			$this->logger->warning('Refused legacy Ownpad migration — pad already bound to a file the user cannot read.', [
				...$this->sameOriginContext($uid, $fileId, $sourceUrl, $accessMode, 'no_access'),
				'boundFileId' => $boundFileId,
			]);
			throw new LegacyPadCollisionException(
				'This pad is already linked to another file you do not have access to.',
			);
		}

		// Collision-with-access: write managed frontmatter but do NOT create
		// a second binding row. The file becomes a "copy of a pad" in our
		// model and the existing copy-handling flow takes over.
		$this->claimedFileWriter->write($claim, $document);
		$this->logger->info('Migrated legacy Ownpad .pad as copy of an already-bound pad.', [
			...$this->sameOriginContext($uid, $fileId, $sourceUrl, $accessMode, 'with_access'),
			'boundFileId' => $boundFileId,
		]);
	}

	/**
	 * The document written over the shortcut the row was made for - by
	 * this migration, or by an earlier one of the same file. A refused
	 * write takes the row back where the file names another pad by now:
	 * the row would keep the next open from binding that one. Elsewhere it
	 * stays - a file naming this pad, or an emptied one, is bound by it on
	 * its next open, and one naming none, or that cannot be read, may have
	 * nothing else. Only the row: the pad was there before, and is never
	 * the migration's to take down.
	 *
	 * @param array<string,int|string> $context the same-origin fields
	 */
	private function writeOrTakeBackTheRow(CreatedFileClaim $claim, string $document, string $padId, array $context): void {
		try {
			$this->claimedFileWriter->write($claim, $document);
		} catch (PadFileChangedException $e) {
			$this->takeBackTheRowIfTheFileNamesAnotherPad($claim, $padId, $context);
			throw $e;
		}
	}

	/**
	 * Read under its lock, after a short wait for one to pass, as
	 * RestoreService::fileNames() reads: what a file holds while another
	 * write runs is no answer.
	 *
	 * @param array<string,int|string> $context
	 */
	private function takeBackTheRowIfTheFileNamesAnotherPad(CreatedFileClaim $claim, string $padId, array $context): void {
		try {
			$content = $this->lockRetry->readContentWithOpenLockRetry($this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId));
		} catch (\Throwable $e) {
			$this->logger->warning('Kept the row of a legacy Ownpad migration whose write was refused: the file cannot be read.', [
				...$context,
				...SafeError::context($e),
			]);
			return;
		}
		if (!$this->namesAnotherPad($content, $padId)) {
			return;
		}
		try {
			if ($this->bindingService->deleteActiveBinding($claim->fileId, $padId)) {
				$this->logger->info('Took back the row of a legacy Ownpad migration whose write was refused: the file names another pad by now.', $context);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not take back the row of a legacy Ownpad migration whose write was refused.', [
				...$context,
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * Whether $content names a pad other than $padId: a document naming
	 * another, or a shortcut to another pad or server. Content naming none
	 * - an emptied file - or that does not parse does not.
	 */
	private function namesAnotherPad(string $content, string $padId): bool {
		$shortcut = $this->padFileService->parseLegacyOwnpadShortcut($content);
		if ($shortcut !== null) {
			return $shortcut['pad_id'] !== $padId || !$this->isSameOrigin($shortcut['url']);
		}
		try {
			return $this->padFileService->readPad($content)->padId !== $padId;
		} catch (\Throwable) {
			return false;
		}
	}

	/** Whether $url is on the Etherpad server this instance is set up for. */
	private function isSameOrigin(string $url): bool {
		$configuredOrigin = $this->etherpadClient->getConfiguredOrigin();
		return $configuredOrigin !== '' && $configuredOrigin === $this->etherpadClient->normalizeOrigin($url);
	}

	/** The managed `.pad` document naming the pad, or null where the format cannot hold its id. */
	private function managedDocument(int $fileId, string $padId, string $accessMode): ?string {
		try {
			return $this->padFileService->buildInitialDocument(
				$fileId,
				$padId,
				$accessMode,
				padUrl: $this->etherpadClient->buildPadUrl($padId),
			);
		} catch (PadFileFormatException) {
			return null;
		}
	}
}
