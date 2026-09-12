<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\InvalidPadNameException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class PadCreationService {
	public function __construct(
		private PadFileService $padFileService,
		private PathNormalizer $padPaths,
		private PadFileCreator $padFileCreator,
		private UserNodeResolver $userNodeResolver,
		private PadCreateRollbackService $rollbackService,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private ManagedPadLifecycle $padLifecycle,
		private PadBootstrapService $padBootstrapService,
		private PadPlaceholderResolver $placeholderResolver,
		private ExternalPadSeeder $externalPadSeeder,
		private PadTypePolicy $padTypePolicy,
		private LoggerInterface $logger,
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
				$attempt->recordPath($path);
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);

				return $this->provisionPadForNewFile($attempt, $uid, $fileNode, $accessMode, $path);
			},
			function (PadCreateAttempt $attempt) use ($uid): void {
				$this->rollbackService->rollbackFailedCreate($uid, $attempt->path(), $attempt->padId(), $attempt->claim());
			},
			function (\Throwable $e, PadCreateAttempt $attempt) use ($path, $accessMode): ?array {
				if ($e instanceof BindingException) {
					return [
						'message' => 'Pad create hit existing binding',
						'context' => [
							'file' => $path,
							'accessMode' => $accessMode,
							'padId' => $attempt->padId(),
						],
					];
				}

				return null;
			},
			function (PadCreateAttempt $attempt) use ($path, $accessMode): array {
				return [
					'message' => 'Pad creation failed',
					'context' => [
						'file' => $path,
						'accessMode' => $accessMode,
						'padId' => $attempt->padId(),
					],
				];
			},
		);
	}

	/**
	 * @return array{file:string,file_id:int,parent_folder_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function createInParent(string $uid, int $parentFolderId, string $name, string $accessMode): array {
		$this->padTypePolicy->requireEnabled($accessMode);
		$fileName = $this->padPaths->normalizeCreateFileName($name);
		$parentFolder = $this->userNodeResolver->resolveUserFolderNodeById($uid, $parentFolderId);
		if (!$parentFolder->isCreatable()) {
			throw new PadParentFolderNotWritableException('Selected parent folder is not writable.');
		}

		return $this->withCreateRollback(
			function (PadCreateAttempt $attempt) use ($uid, $parentFolder, $parentFolderId, $fileName, $accessMode): array {
				$fileNode = $this->padFileCreator->createUserFileInFolder($parentFolder, $fileName);
				$created = $this->provisionPadForNewFile($attempt, $uid, $fileNode, $accessMode);

				// Rebuilt rather than appended: the response's key order is
				// part of what callers compare against.
				return [
					'file' => $created['file'],
					'file_id' => $created['file_id'],
					'parent_folder_id' => $parentFolderId,
					'pad_id' => $created['pad_id'],
					'access_mode' => $created['access_mode'],
					'pad_url' => $created['pad_url'],
				];
			},
			function (PadCreateAttempt $attempt) use ($uid): void {
				$this->rollbackService->rollbackFailedCreate($uid, $attempt->path(), $attempt->padId(), $attempt->claim());
			},
			function (\Throwable $e, PadCreateAttempt $attempt) use ($parentFolderId, $name, $accessMode): ?array {
				if ($e instanceof BindingException) {
					return [
						'message' => 'Pad creation by parent hit existing binding',
						'context' => [
							'parentFolderId' => $parentFolderId,
							'padName' => $name,
							'path' => $attempt->path(),
							'accessMode' => $accessMode,
							'padId' => $attempt->padId(),
						],
					];
				}

				return null;
			},
			function (PadCreateAttempt $attempt) use ($parentFolderId, $name, $accessMode): array {
				return [
					'message' => 'Pad creation by parent failed',
					'context' => [
						'parentFolderId' => $parentFolderId,
						'padName' => $name,
						'path' => $attempt->path(),
						'accessMode' => $accessMode,
						'padId' => $attempt->padId(),
					],
				];
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
				$attempt->recordPath($path);
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				$claim = $this->claimCreatedFile($attempt, $uid, $fileNode, $path);
				$fileId = $claim->fileId;

				$prepared = $this->externalPadSeeder->prepare($fileId, $padUrl);
				$this->writeCreatedFile($claim, $prepared['content']);
				$seeded = $prepared['result'];
				// Preserve the historical key ordering for the external-create
				// response: `file` is the first key so tests asserting via
				// `assertSame` keep matching after the refactor.
				$result = ['file' => $path] + $seeded;
				return $result;
			},
			function (PadCreateAttempt $attempt) use ($uid): void {
				$this->rollbackService->rollbackExternalCreate($uid, $attempt->path(), $attempt->claim());
			},
			// No pad of ours here — an external create links one that already
			// exists — so neither log closure has an attempt to read.
			function (\Throwable $e) use ($path, $padUrl): ?array {
				if ($e instanceof EtherpadClientException) {
					return [
						'message' => 'External pad URL validation failed',
						'context' => [
							'file' => $path,
							'padUrl' => $padUrl,
						],
					];
				}

				return null;
			},
			function () use ($path, $padUrl): array {
				return [
					'message' => 'External pad create failed',
					'context' => [
						'file' => $path,
						'padUrl' => $padUrl,
					],
				];
			},
		);
	}

	/**
	 * Materializes a new pad from an existing `.pad` source file. Reuses the
	 * same provisioning + binding pipeline as create(), but seeds the new
	 * pad with the template's body (placeholders resolved). The source can
	 * be any `.pad` in the requester's userspace — caller picks the file
	 * by id, so a custom frontend can decide what counts as a template
	 * (current folder, ancestor scan, a fixed picker, …).
	 *
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function createFromTemplate(string $uid, string $targetFile, int $templateFileId, ?IUser $user): array {
		$resolvedTargetFile = $this->placeholderResolver->applyForPath($targetFile, $user);
		$path = $this->padPaths->normalizeCreatePath($resolvedTargetFile);

		$templateNode = $this->userNodeResolver->resolveUserFileNodeById($uid, $templateFileId);

		return $this->withCreateRollback(
			function (PadCreateAttempt $attempt) use ($uid, $path, $templateNode, $user): array {
				$attempt->recordPath($path);
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				// API creates start empty; do not replace this baseline with a later read.
				$claim = $this->claimCreatedFile($attempt, $uid, $fileNode, $path);

				$result = $this->materializeTemplateInto($fileNode, $templateNode, $user, $claim);
				// Recorded for the log lines only: this pad belongs to
				// materializeTemplateInto(), which removes it itself before
				// rethrowing, so the rollback below is file-only.
				$attempt->recordPad($result['pad_id']);

				return [
					'file' => $path,
					'file_id' => $result['file_id'],
					'pad_id' => $result['pad_id'],
					'access_mode' => $result['access_mode'],
					'pad_url' => $result['pad_url'],
				];
			},
			function (PadCreateAttempt $attempt) use ($uid): void {
				// File only: materializeTemplateInto() has already deleted the
				// pad it provisioned before rethrowing.
				$this->rollbackService->rollbackCreatedFileOnly($uid, $attempt->path(), $attempt->claim());
			},
			function (\Throwable $e, PadCreateAttempt $attempt) use ($path): ?array {
				if ($e instanceof BindingException) {
					return [
						'message' => 'Pad create-from-template hit existing binding',
						'context' => [
							'file' => $path,
							'padId' => $attempt->padId(),
						],
					];
				}
				return null;
			},
			function (PadCreateAttempt $attempt) use ($path, $templateFileId): array {
				return [
					'message' => 'Pad create-from-template failed',
					'context' => [
						'file' => $path,
						'templateFileId' => $templateFileId,
						'padId' => $attempt->padId(),
					],
				];
			},
		);
	}

	/** The file and the row were both made here, so neither outlives the other. */
	private function unwindMaterializedPad(int $fileId, string $padId): void {
		$this->rollback()->removeMatchingBindingAndDiscard($fileId, $padId, 'template materialization');
	}

	/**
	 * Shared core of the template materialization pipeline: validate the
	 * template, resolve placeholders, provision a pad, seed it, write the
	 * target file, bind it. The target file must already exist.
	 *
	 * This method owns the Etherpad-side lifecycle of the pad it provisions.
	 * A caller wrapping it in an outer rollback must not also delete that
	 * pad - the outer wrapper's job is the Nextcloud file it created.
	 *
	 * @return array{file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function materializeTemplateInto(
		File $target,
		File $template,
		?IUser $user,
		?CreatedFileClaim $claim = null
	): array {
		// Keep the caller's baseline; only claim the current content if none was supplied.
		$claim ??= $this->claimTemplateTarget($target, $user);
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
		if (str_starts_with($pad->padId, 'ext.') || $pad->isExternal) {
			throw new \InvalidArgumentException('External pads cannot be used as a template.');
		}

		// A template keeps the access mode of the pad it was made from. If the
		// admin switched that type off, create the pad in the other enabled
		// mode rather than refusing — the template's content is the point.
		$accessMode = $this->padTypePolicy->resolveCreatableMode($pad->accessMode);

		$snapshot = $this->padFileService->getSnapshotPartsFromBody($pad->body);
		$resolvedText = $this->placeholderResolver->applyForContent($snapshot['text'], $user);
		$resolvedHtml = $this->placeholderResolver->applyForContent($snapshot['html'], $user);

		$fileId = $claim->fileId;

		$padId = $this->padBootstrapService->provisionPadId($accessMode);
		try {
			$this->padLifecycle->seed($padId, $resolvedText, $resolvedHtml, ['fileId' => $fileId]);
			$padUrl = $this->etherpadClient->buildPadUrl($padId);

			$content = $this->padFileService->buildInitialDocument(
				$fileId,
				$padId,
				$accessMode,
				snapshot: new PadSnapshot($resolvedText, $resolvedHtml, 0),
				padUrl: $padUrl,
			);
			$this->writeCreatedFile($claim, $content);
			$this->bindingService->createBinding($fileId, $padId, $accessMode);
		} catch (\Throwable $e) {
			$this->unwindMaterializedPad($fileId, $padId);
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
	 * Capture identity and copied template content before materialization.
	 * The listener shares this claim with materialization and recovery.
	 */
	public function claimTemplateTarget(File $target, ?IUser $user): CreatedFileClaim {
		$uid = $user?->getUID();
		if ($uid === null || $uid === '') {
			throw new \RuntimeException('Cannot materialize a template without a user to resolve the file by.');
		}

		return new CreatedFileClaim($uid, $this->requireFileId($target, $target->getName()), (string)$target->getContent());
	}

	/**
	 * Re-resolve by id and require the claimed content before writing.
	 *
	 * File::putContent() uses its remembered path, which may have been reused
	 * during provisioning. The content check and write are not atomic.
	 *
	 * @throws \OCP\Files\NotFoundException
	 * @throws PadFileChangedException
	 */
	private function writeCreatedFile(CreatedFileClaim $claim, string $content): void {
		$node = $this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId);
		if ((string)$node->getContent() !== $claim->expectedBefore) {
			throw new PadFileChangedException('The target .pad file changed while the pad was being provisioned.');
		}
		$node->putContent($content);
		// Record on the shared claim so recovery retains proof if binding fails next.
		$claim->writtenHash = hash('sha256', $content);
	}

	/**
	 * Resolve for recovery only if the original content or recorded write still matches.
	 * Return null if the file changed or cannot be resolved or read. This does not lock it.
	 */
	public function resolveUnchangedClaim(CreatedFileClaim $claim): ?File {
		try {
			$node = $this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId);
			$current = (string)$node->getContent();
			$isOurs = $current === $claim->expectedBefore
				|| ($claim->writtenHash !== null && hash_equals($claim->writtenHash, hash('sha256', $current)));
			if (!$isOurs) {
				return null;
			}
		} catch (\Throwable) {
			return null;
		}

		return $node;
	}

	/**
	 * Everything a blank create does once its file exists.
	 *
	 * `create()` and `createInParent()` differ only in how they reach that
	 * file — a path in the user's tree, or a name in a folder they had to
	 * resolve first. From here on they were the same twenty lines twice.
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
		$fileId = $claim->fileId;

		$padId = $this->padBootstrapService->provisionPadId($accessMode);
		$attempt->recordPad($padId);
		$padUrl = $this->etherpadClient->buildPadUrl($padId);

		$content = $this->padFileService->buildInitialDocument($fileId, $padId, $accessMode, padUrl: $padUrl);
		$this->writeCreatedFile($claim, $content);
		$this->bindingService->createBinding($fileId, $padId, $accessMode);

		return [
			'file' => $attempt->path(),
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
	 * before the throw. Written out four times it was already drifting.
	 *
	 * An empty `$path` means create-by-parent, which cannot know it before
	 * the file exists; deriving it can throw, hence after the claim.
	 */
	private function claimCreatedFile(PadCreateAttempt $attempt, string $uid, File $fileNode, string $path = ''): CreatedFileClaim {
		$fileId = $this->requireFileId($fileNode, $path !== '' ? $path : $fileNode->getName());
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
	private function requireFileId(File $fileNode, string $path): int {
		try {
			$fileId = (int)$fileNode->getId();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not read the ID of a freshly created .pad file', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'exception' => $e,
			]);
			throw new \RuntimeException('Could not resolve new file ID.', 0, $e);
		}
		if ($fileId > 0) {
			return $fileId;
		}
		$this->logger->warning('A freshly created .pad file reported no ID', [
			'app' => 'etherpad_nextcloud',
			'file' => $path,
		]);
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
				'exception' => $e,
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
	 * Runs one create attempt, owning the state the rollback needs.
	 *
	 * The attempt is made here and handed to every closure, so nothing has
	 * to be threaded through by reference and the two cannot disagree about
	 * what was created.
	 *
	 * @template T
	 * @param callable(PadCreateAttempt):T $action
	 * @param callable(PadCreateAttempt):void $rollback
	 * @param callable(\Throwable,PadCreateAttempt):?array{message:string,context:array<string,mixed>} $warningFor
	 * @param callable(PadCreateAttempt):array{message:string,context:array<string,mixed>} $errorFor
	 * @return T
	 */
	private function withCreateRollback(
		callable $action,
		callable $rollback,
		callable $warningFor,
		callable $errorFor,
	): mixed {
		$attempt = new PadCreateAttempt();
		try {
			return $action($attempt);
		} catch (\Throwable $e) {
			$warning = $warningFor($e, $attempt);
			if ($warning !== null) {
				$this->logger->warning($warning['message'], array_merge(
					['app' => 'etherpad_nextcloud'],
					$warning['context'],
					['exception' => $e],
				));
			} elseif (!($e instanceof PadFileAlreadyExistsException) && !($e instanceof InvalidPadNameException)) {
				$error = $errorFor($attempt);
				$this->logger->error($error['message'], array_merge(
					['app' => 'etherpad_nextcloud'],
					$error['context'],
					['exception' => $e],
				));
			}

			$rollback($attempt);
			throw $e;
		}
	}

	private function rollback(): ProvisionedPadRollback {
		return new ProvisionedPadRollback($this->bindingService, $this->padLifecycle, $this->logger);
	}
}
