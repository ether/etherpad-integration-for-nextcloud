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
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCA\EtherpadNextcloud\Util\DiagnosticText;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\PadId;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
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
	 * A shortcut cannot replace the file's bound pad, even while deletion is
	 * pending. Refusal preserves the shortcut instead of writing a document
	 * that opening would reject as a mismatch.
	 */
	private function overARowForAnotherPad(string $uid, int $fileId, string $sourceUrl, string $sourcePadId, bool $sameOrigin): LegacyPadNotFoundException {
		$context = $sameOrigin
			? $this->sameOriginContext($uid, $fileId, $sourceUrl, $this->padFileService->inferAccessModeFromPadId($sourcePadId), null)
			: $this->crossOriginContext($uid, $fileId, $sourceUrl);
		$this->logger->warning('Refused legacy Ownpad migration - the file has a row for another pad.', [...$context, 'reason' => 'own_row']);
		return new LegacyPadNotFoundException('The legacy .pad file names another pad than the row of its file.');
	}

	/**
	 * The fields of every cross-origin line.
	 *
	 * @return array<string,int|string>
	 */
	private function crossOriginContext(string $uid, int $fileId, string $sourceUrl): array {
		return [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'sourceHost' => DiagnosticText::hostOf($sourceUrl),
			'originBranch' => 'cross',
			'uid' => $uid,
		];
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

		$sameOrigin = $this->isSameOrigin($sourceUrl);
		$ownRow = $this->bindingService->padOfFile($fileId);
		if ($ownRow !== null && (!$sameOrigin || $ownRow->padId !== $sourcePadId)) {
			throw $this->overARowForAnotherPad($uid, $fileId, $sourceUrl, $sourcePadId, $sameOrigin);
		}

		if (!$sameOrigin) {
			$this->claimedFileWriter->write($claim, $this->externalPadSeeder->prepare($fileId, $sourceUrl)['content']);
			$this->logger->info('Migrated legacy Ownpad .pad as external public pad.', $this->crossOriginContext($uid, $fileId, $sourceUrl));
			return;
		}

		$accessMode = $this->padFileService->inferAccessModeFromPadId($sourcePadId);
		// Checked before any row is looked up by this id, on every path below,
		// a copy and a finish too. An id Etherpad's API rewrites would reach
		// another file's pad past the collision rule, and PostgreSQL refuses
		// invalid UTF-8 and NUL. The document is built here so the format
		// refuses an id it cannot hold before a row is made.
		$document = PadId::isValid($sourcePadId) ? $this->managedDocument($fileId, $sourcePadId, $accessMode) : null;
		if ($document === null) {
			throw $this->unlinkable($uid, $fileId, $sourceUrl, $accessMode, null, 'invalid_id', 'The legacy .pad file names a pad id that cannot be linked.');
		}
		// The file's own row finishes its migration, waiting to go or not.
		$existingBinding = $ownRow ?? $this->bindingService->findByPadId($sourcePadId, BindingService::STATE_ACTIVE);

		if ($existingBinding === null) {
			// With no active row here, an id of the app's form may be another
			// installation's: a shortcut, Ownpad's or written by anyone else,
			// does not make the pad this file's. Ownpad's own names have no
			// such form, so its files pass. In any capitals, which Etherpad's
			// lowerCasePadIds may take to a pad of that form.
			// https://github.com/otetard/ownpad/blob/v0.13.2/lib/Service/OwnpadService.php create()
			if (PadId::isAppPublicId(mb_strtolower($sourcePadId))) {
				throw $this->unlinkable($uid, $fileId, $sourceUrl, $accessMode, 'none', 'own_name', 'The legacy .pad file names a pad with the id of a pad this app makes.');
			}
			// Only where no active row names the pad; one that does is the
			// collision rule's question below.
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
			// The pad id is unique across all states, so a failed insert may
			// be a collision with a concurrent migration or a row seen deleted
			// for good. Read it again without a state filter and apply the
			// normal access check.
			//
			// The row comes first: managed frontmatter with no row for its pad
			// id is a state copy recovery cannot help with.
			try {
				$this->bindingService->createBinding($fileId, $sourcePadId, $accessMode);
			} catch (BindingException $e) {
				$existingBinding = $this->bindingService->findByPadId($sourcePadId);
				if ($existingBinding === null) {
					// No row holds it, so the insert failed for another reason.
					throw $e;
				}
				$this->logger->info('A row already holds the pad-id of a legacy Ownpad migration; reclassifying as collision.', $this->sameOriginContext($uid, $fileId, $sourceUrl, $accessMode, null));
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
			// An earlier attempt made this file's row but did not finish its
			// document, or this insert committed before throwing. Only the
			// document is completed here; assertConsistentMapping() makes a
			// row seen deleted for good active when the file is opened.
			$context = $this->sameOriginContext($uid, $fileId, $sourceUrl, $accessMode, 'self');
			$this->writeOrTakeBackTheRow($claim, $document, $sourcePadId, $context);
			$this->logger->info('Migrated legacy Ownpad .pad — finishing partially-completed prior migration.', $context);
			return;
		}

		// A file gone for good, its row still waiting, cannot be read either.
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

		// Readable, so the deletion it was seen in did not happen. Active
		// again, as an open makes it, or the copy could not find its original.
		if ($existingBinding->state === BindingService::STATE_PENDING_DELETE) {
			$this->bindingService->transition($boundFileId, $sourcePadId, BindingService::STATE_PENDING_DELETE, BindingService::STATE_ACTIVE);
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
	 * this migration, or by an earlier one of the same file. A write
	 * refused - the file changed, or another write held its lock - takes
	 * the row back where the file names another pad by now: the row would
	 * keep the next open from taking the pad the file names. Elsewhere it
	 * stays - a file naming this pad, or an emptied one, is bound by it on
	 * its next open, and for one that does not parse, or cannot be looked
	 * up or read, the row may be all that names its pad. A row seen deleted
	 * for good stays too. Only the row: the pad was there before, and is
	 * never the migration's to take down.
	 *
	 * @param array<string,int|string> $context the same-origin fields
	 */
	private function writeOrTakeBackTheRow(CreatedFileClaim $claim, string $document, string $padId, array $context): void {
		try {
			$this->claimedFileWriter->write($claim, $document);
		} catch (PadFileChangedException|LockedException $e) {
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
			$this->logger->warning('Kept the row of a legacy Ownpad migration whose write was refused: the file could not be looked up or read.', [
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
	 * another, or a shortcut to another pad or server. The document first,
	 * as an open reads it: its text may quote a shortcut. An empty file, and
	 * content that does not parse, do not.
	 */
	private function namesAnotherPad(string $content, string $padId): bool {
		try {
			return $this->padFileService->readPad($content)->padId !== $padId;
		} catch (MissingFrontmatterException) {
			// No document: perhaps a shortcut.
		} catch (\Throwable) {
			return false;
		}
		$shortcut = $this->padFileService->parseLegacyOwnpadShortcut($content);
		return $shortcut !== null && ($shortcut['pad_id'] !== $padId || !$this->isSameOrigin($shortcut['url']));
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
