<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Files\File;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class PadCreationService {
	public function __construct(
		private PadFileService $padFileService,
		private PathNormalizer $padPaths,
		private PadFileCreator $padFileCreator,
		private UserNodeResolver $userNodeResolver,
		private BindingService $bindingService,
		private ClaimedFileWriter $claimedFileWriter,
		private ManagedPadLifecycle $padLifecycle,
		private PadBootstrapService $padBootstrapService,
		private PadPlaceholderResolver $placeholderResolver,
		private ExternalPadSeeder $externalPadSeeder,
		private PadTypePolicy $padTypePolicy,
		private LoggerInterface $logger,
		private ProvisionedPadRollback $provisionedPadRollback,
	) {
	}

	/**
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function create(string $uid, string $file, string $accessMode): array {
		$this->padTypePolicy->requireEnabled($accessMode);
		$path = $this->padPaths->normalizeCreatePath($file);

		return $this->withCreateRollback(
			function (PadCreateAttempt $attempt) use ($uid, $path, $accessMode): array {
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);

				return $this->provisionPadForNewFile($attempt, $uid, $fileNode, $accessMode, $path);
			},
		);
	}

	/**
	 * @return array{file:string,file_id:int,parent_folder_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function createInParent(string $uid, int $parentFolderId, string $name, string $accessMode): array {
		$this->padTypePolicy->requireEnabled($accessMode);
		$fileName = $this->padPaths->normalizeCreateFileName($name);
		// Whether it takes a new file, the creator asks (createUserFileInFolder()).
		$parentFolder = $this->userNodeResolver->resolveUserFolderNodeById($uid, $parentFolderId);

		return $this->withCreateRollback(
			function (PadCreateAttempt $attempt) use ($uid, $parentFolder, $parentFolderId, $fileName, $accessMode): array {
				$fileNode = $this->padFileCreator->createUserFileInFolder($parentFolder, $fileName);
				$created = $this->provisionPadForNewFile($attempt, $uid, $fileNode, $accessMode);

				// The folder's id right after the file's: the response's key
				// order is part of what callers compare against.
				return ['file' => $created['file'], 'file_id' => $created['file_id'], 'parent_folder_id' => $parentFolderId] + $created;
			},
		);
	}

	/**
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string,snapshot_warning_code?:string}
	 */
	public function createFromUrl(string $uid, string $file, string $padUrl): array {
		$path = $this->padPaths->normalizeCreatePath($file);

		return $this->withCreateRollback(
			function (PadCreateAttempt $attempt) use ($uid, $path, $padUrl) {
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				$claim = $this->claimCreatedFile($attempt, $uid, $fileNode, $path);
				$prepared = $this->externalPadSeeder->prepare($claim->fileId, $padUrl);
				$this->claimedFileWriter->write($claim, $prepared['content']);

				return ['file' => $path] + $prepared['result'];
			},
		);
	}

	/**
	 * A new pad from a managed `.pad` file the user can read, with its
	 * body's placeholders resolved. The source is picked by id, so the
	 * caller decides what counts as a template; an external pad or an
	 * empty file is refused.
	 *
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function createFromTemplate(string $uid, string $targetFile, int $templateFileId, ?IUser $user): array {
		$path = $this->padPaths->normalizeCreatePath($this->placeholderResolver->applyForPath($targetFile, $user));

		$templateNode = $this->userNodeResolver->resolveUserFileNodeById($uid, $templateFileId);

		return $this->withCreateRollback(
			function (PadCreateAttempt $attempt) use ($uid, $path, $templateNode, $user): array {
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				$claim = $this->claimCreatedFile($attempt, $uid, $fileNode, $path);

				return ['file' => $path] + $this->materializeTemplateInto($templateNode, $user, $claim);
			},
		);
	}

	/**
	 * Shared core of the template materialization pipeline: validate the
	 * template, resolve placeholders, provision a pad, seed it, then take
	 * the blank create's step (writeAndBind()) on the file the caller
	 * claimed.
	 *
	 * A failure takes down the pad it provisioned where writeAndBind()
	 * does; the file is the caller's.
	 *
	 * @return array{file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function materializeTemplateInto(File $template, ?IUser $user, CreatedFileClaim $claim): array {
		if (!PadFileType::isPad($template->getName())) {
			throw new NotAPadFileException('Template is not a .pad file.');
		}
		$templateContent = (string)$template->getContent();
		if (trim($templateContent) === '') {
			throw new \InvalidArgumentException('Template is empty.');
		}

		// readPad() has already rejected a missing pad id and an access mode
		// that is neither public nor protected, so what is left to check here
		// is what only a template cares about.
		$pad = $this->padFileService->readPad($templateContent);
		if ($pad->namesAnExternalPad()) {
			throw new \InvalidArgumentException('External pads cannot be used as a template.');
		}

		// A template keeps the access mode of the pad it was made from. If the
		// admin switched that type off, create the pad in the other enabled
		// mode rather than refusing — the template's content is the point.
		$accessMode = $this->padTypePolicy->resolveCreatableMode($pad->accessMode);

		$snapshot = $this->padFileService->getSnapshotPartsFromBody($pad->body());
		$resolvedText = $this->placeholderResolver->applyForContent($snapshot['text'], $user);
		$resolvedHtml = $this->placeholderResolver->applyForContent($snapshot['html'], $user);

		// The template's content is in the file from the start, so the file
		// records the pad's revisions, as a restore does: they make it hold
		// saved content should Etherpad lose the pad before its first sync,
		// not only its text (ManagedPadLifecycle::howLost()).
		[$padId, $revisions] = $this->padLifecycle->provisionSeeded(
			$accessMode,
			$this->padBootstrapService->newPadId(...),
			$this->padBootstrapService->newGroupPadName(...),
			$resolvedText,
			$resolvedHtml,
			['fileId' => $claim->fileId, 'operation' => 'template materialization'],
		);
		return $this->writeAndBind(
			$claim,
			$padId,
			$accessMode,
			'template materialization',
			// Without an answer from Etherpad the file starts at 0: a
			// snapshot's revision is never negative.
			new PadSnapshot($resolvedText, $resolvedHtml, max(0, $revisions)),
		);
	}

	/**
	 * The claim on a file Nextcloud made from a template or the external
	 * tile: its identity and the content copied into it, before anything is
	 * written. The listener shares it with the materialization or the
	 * external tile's write, and with their recovery. A read that gives no
	 * string counts as empty, which lets the claim write over or remove
	 * only a file that reads as empty.
	 */
	public function claimTemplateTarget(File $target, string $uid): CreatedFileClaim {
		if ($uid === '') {
			throw new \RuntimeException('Cannot claim a template target without a user to resolve the file by.');
		}

		return new CreatedFileClaim($uid, $this->requireFileId($target), (string)$target->getContent());
	}

	/**
	 * Everything a blank create does once its file exists.
	 *
	 * `create()` and `createInParent()` differ only in how they reach that
	 * file — a path in the user's tree, or a name in a folder they had to
	 * resolve first.
	 *
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	private function provisionPadForNewFile(
		PadCreateAttempt $attempt,
		string $uid,
		File $fileNode,
		string $accessMode,
		string $path = ''
	): array {
		$claim = $this->claimCreatedFile($attempt, $uid, $fileNode, $path);

		$padId = $this->padBootstrapService->provisionPadId($accessMode);

		return ['file' => $attempt->path()] + $this->writeAndBind($claim, $padId, $accessMode, 'create');
	}

	/**
	 * The step the blank create and a template take once they have a pad.
	 * The document is written before the row, so a failed write leaves no
	 * row to take back. A failure takes the pad down here: before the
	 * binding write without asking the binding table, as no row can name
	 * the pad yet; once the write began with a row naming it, as
	 * createBinding can commit and still throw. The create's rollback takes
	 * only the file, so a step that can fail, added between the pad's
	 * provisioning and this one or after it, would have to take the pad
	 * down itself.
	 *
	 * @return array{file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	private function writeAndBind(CreatedFileClaim $claim, string $padId, string $accessMode, string $operation, ?PadSnapshot $snapshot = null): array {
		$fileId = $claim->fileId;
		try {
			$padUrl = $this->claimedFileWriter->writePadDocument($claim, $padId, $accessMode, $snapshot);
		} catch (\Throwable $e) {
			$this->padLifecycle->discardProvisioned($padId, ['fileId' => $fileId, 'operation' => $operation]);
			throw $e;
		}
		try {
			$this->bindingService->createBinding($fileId, $padId, $accessMode);
		} catch (\Throwable $e) {
			$this->provisionedPadRollback->removeMatchingBindingAndDiscard($fileId, $padId, $operation);
			throw $e;
		}

		return [
			'file_id' => $fileId,
			'pad_id' => $padId,
			'access_mode' => $accessMode,
			'pad_url' => $padUrl,
		];
	}

	/**
	 * Take ownership of the file this attempt just made — or refuse it.
	 *
	 * One method because the order is the point: the id before anything
	 * that can throw, the ownership check before any write, the disown
	 * before the throw.
	 *
	 * An empty `$path` means create-by-parent, which cannot know it before
	 * the file exists; deriving it can throw, hence after the claim.
	 */
	private function claimCreatedFile(PadCreateAttempt $attempt, string $uid, File $fileNode, string $path): CreatedFileClaim {
		$fileId = $this->requireFileId($fileNode);
		$claim = $attempt->claimFile($uid, $fileId);

		if ($path === '') {
			$path = $this->userNodeResolver->toUserAbsolutePath($uid, $fileNode);
		}
		$attempt->recordPath($path);

		if ($this->isNotOurs($fileNode, $fileId, $path)) {
			$attempt->disownFile();
			throw new PadFileAlreadyExistsException('Target .pad file already exists.');
		}

		return $claim;
	}

	/**
	 * The id of the file we just created, or an exception. getId() can throw
	 * on a node that is not in the cache yet, which is the same failure as
	 * returning 0 and is reported the same way.
	 *
	 * @throws \RuntimeException
	 */
	private function requireFileId(File $fileNode): int {
		// Not logged here: whoever called the create reports the failure.
		try {
			$fileId = (int)$fileNode->getId();
		} catch (\Throwable $e) {
			throw new \RuntimeException('Could not resolve new file ID.', 0, $e);
		}
		if ($fileId > 0) {
			return $fileId;
		}
		throw new \RuntimeException('Could not resolve new file ID.');
	}

	/**
	 * Did this create get handed a file that was already somebody's?
	 *
	 * newFile() is not a create-if-absent — it calls View::touch(), which
	 * succeeds on a file that is already there — so a stale cache entry can
	 * make an existing file look absent. Writing would destroy the user's
	 * document, and the create would only notice afterwards, when the
	 * binding write hit the row that was already there.
	 *
	 * Two signals say the file predates this request: it already holds
	 * content, or it already has a binding. A size that cannot be read is
	 * treated the same way — unknown is not ours.
	 *
	 * Deliberately a question, not an action, and asked here rather than in
	 * PadFileCreator: by this point the caller has recorded the node as the
	 * one to clean up, and it has to let go of that record before the create
	 * aborts. Asking inside the creator would mean throwing before the node
	 * was ever handed over, leaving a file nobody can clean up.
	 */
	private function isNotOurs(File $fileNode, int $fileId, string $path): bool {
		try {
			$size = (int)$fileNode->getSize();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not read the size of a freshly created .pad file; treating it as not ours', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				...SafeError::context($e),
			]);
			return true;
		}
		if ($size > 0) {
			$this->logger->warning('Refusing to write over a file that already has content', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'fileId' => $fileId,
			]);
			return true;
		}

		if ($this->bindingService->findByFileId($fileId) === null) {
			return false;
		}
		$this->logger->warning('Refusing to write over a file that is already a pad', [
			'app' => 'etherpad_nextcloud',
			'file' => $path,
			'fileId' => $fileId,
		]);
		return true;
	}

	/**
	 * Runs one create attempt, owning the state its rollback needs: the
	 * file it claimed, which goes when the create fails, while it is as the
	 * claim left it (ClaimedFileWriter::deleteIfUnchanged()). Without a
	 * claim the file stays. A create's pad goes where it was made
	 * (writeAndBind()). What went wrong is not logged here: it reaches the
	 * error mapper, which reports it once (ApiErrorLog).
	 *
	 * @template T
	 * @param callable(PadCreateAttempt):T $action
	 * @return T
	 */
	private function withCreateRollback(callable $action): mixed {
		$attempt = new PadCreateAttempt();
		try {
			return $action($attempt);
		} catch (\Throwable $e) {
			$claim = $attempt->claim();
			if ($claim !== null) {
				$this->claimedFileWriter->deleteIfUnchanged($claim, ['file' => $attempt->path()]);
			}
			throw $e;
		}
	}
}
