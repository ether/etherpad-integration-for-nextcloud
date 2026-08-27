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
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
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
		$padId = '';
		$createdFileId = 0;

		return $this->withCreateRollback(
			function () use ($uid, $path, $accessMode, &$padId, &$createdFileId): array {
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				$fileId = $this->requireFileId($fileNode);
				$createdFileId = $fileId;
				$padId = $this->padBootstrapService->provisionPadId($accessMode);
				$padUrl = $this->etherpadClient->buildPadUrl($padId);

				$content = $this->padFileService->buildInitialDocument(
					$fileId,
					$padId,
					$accessMode,
					'',
					$padUrl
				);
				$fileNode->putContent($content);

				$this->bindingService->createBinding($fileId, $padId, $accessMode);

				return [
					'file' => $path,
					'file_id' => $fileId,
					'pad_id' => $padId,
					'access_mode' => $accessMode,
					'pad_url' => $padUrl,
				];
			},
			function () use ($uid, $path, &$padId, &$createdFileId): void {
				$this->rollbackService->rollbackFailedCreate($uid, $path, $padId, $createdFileId);
			},
			function (\Throwable $e) use ($path, $accessMode, &$padId): ?array {
				if ($e instanceof BindingException) {
					return [
						'message' => 'Pad create hit existing binding',
						'context' => [
							'file' => $path,
							'accessMode' => $accessMode,
							'padId' => $padId,
						],
					];
				}

				return null;
			},
			function () use ($path, $accessMode, &$padId): array {
				return [
					'message' => 'Pad creation failed',
					'context' => [
						'file' => $path,
						'accessMode' => $accessMode,
						'padId' => $padId,
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

		$padId = '';
		$createdFileId = 0;
		$path = '';

		return $this->withCreateRollback(
			function () use ($uid, $parentFolder, $parentFolderId, $fileName, $accessMode, &$path, &$padId, &$createdFileId): array {
				$fileNode = $this->padFileCreator->createUserFileInFolder($parentFolder, $fileName);
				// Id first: toUserAbsolutePath throws for a node outside the
				// user's file tree, and a rollback triggered by that throw
				// would otherwise have neither an id to delete by nor a path
				// to name in the log.
				$fileId = $this->requireFileId($fileNode);
				$createdFileId = $fileId;
				$path = $this->userNodeResolver->toUserAbsolutePath($uid, $fileNode);

				$padId = $this->padBootstrapService->provisionPadId($accessMode);
				$padUrl = $this->etherpadClient->buildPadUrl($padId);
				$content = $this->padFileService->buildInitialDocument(
					$fileId,
					$padId,
					$accessMode,
					'',
					$padUrl
				);
				$fileNode->putContent($content);
				$this->bindingService->createBinding($fileId, $padId, $accessMode);

				return [
					'file' => $path,
					'file_id' => $fileId,
					'parent_folder_id' => $parentFolderId,
					'pad_id' => $padId,
					'access_mode' => $accessMode,
					'pad_url' => $padUrl,
				];
			},
			function () use ($uid, &$path, &$padId, &$createdFileId): void {
				$this->rollbackService->rollbackFailedCreate($uid, $path, $padId, $createdFileId);
			},
			function (\Throwable $e) use ($parentFolderId, $name, $accessMode, &$path, &$padId): ?array {
				if ($e instanceof BindingException) {
					return [
						'message' => 'Pad creation by parent hit existing binding',
						'context' => [
							'parentFolderId' => $parentFolderId,
							'padName' => $name,
							'path' => $path,
							'accessMode' => $accessMode,
							'padId' => $padId,
						],
					];
				}

				return null;
			},
			function () use ($parentFolderId, $name, $accessMode, &$path, &$padId): array {
				return [
					'message' => 'Pad creation by parent failed',
					'context' => [
						'parentFolderId' => $parentFolderId,
						'padName' => $name,
						'path' => $path,
						'accessMode' => $accessMode,
						'padId' => $padId,
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
		$createdFileId = 0;

		return $this->withCreateRollback(
			function () use ($uid, $path, $padUrl, &$createdFileId) {
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				$fileId = $this->requireFileId($fileNode);
				$createdFileId = $fileId;

				$seeded = $this->externalPadSeeder->seed($fileNode, $fileId, $padUrl);
				// Preserve the historical key ordering for the external-create
				// response: `file` is the first key so tests asserting via
				// `assertSame` keep matching after the refactor.
				$result = ['file' => $path] + $seeded;
				return $result;
			},
			function () use ($uid, $path, &$createdFileId): void {
				$this->rollbackService->rollbackExternalCreate($uid, $path, $createdFileId);
			},
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

		$padId = '';
		$createdFileId = 0;

		return $this->withCreateRollback(
			function () use ($uid, $path, $templateNode, $user, &$padId, &$createdFileId): array {
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				// Recorded before the template is materialised: everything
				// after this can fail, and rollback needs to know which file
				// to remove.
				$createdFileId = $this->requireFileId($fileNode);

				// The pad id has to reach $padId while the pad is being set up,
				// not after: if the binding write fails, the document is
				// already in the file, and a rollback that does not know the
				// id cannot tell that file apart from a stranger's.
				$result = $this->materializeTemplateInto(
					$fileNode,
					$templateNode,
					$user,
					static function (string $provisioned) use (&$padId): void {
						$padId = $provisioned;
					},
				);

				return [
					'file' => $path,
					'file_id' => $result['file_id'],
					'pad_id' => $result['pad_id'],
					'access_mode' => $result['access_mode'],
					'pad_url' => $result['pad_url'],
				];
			},
			function () use ($uid, $path, &$padId, &$createdFileId): void {
				// File only: materializeTemplateInto() has already deleted the
				// pad it provisioned before rethrowing.
				$this->rollbackService->rollbackCreatedFileOnly($uid, $path, $padId, $createdFileId);
			},
			function (\Throwable $e) use ($path, &$padId): ?array {
				if ($e instanceof BindingException) {
					return [
						'message' => 'Pad create-from-template hit existing binding',
						'context' => [
							'file' => $path,
							'padId' => $padId,
						],
					];
				}
				return null;
			},
			function () use ($path, $templateFileId, &$padId): array {
				return [
					'message' => 'Pad create-from-template failed',
					'context' => [
						'file' => $path,
						'templateFileId' => $templateFileId,
						'padId' => $padId,
					],
				];
			},
		);
	}

	/**
	 * Shared core of the template materialization pipeline. Validates the
	 * template, resolves placeholders, provisions a fresh pad, seeds its
	 * content, writes the target file, and creates the binding. The target
	 * file must already exist on disk (the callers either create it via
	 * `PadFileCreator` or receive it pre-populated from NC's native template
	 * copy flow).
	 *
	 * On any failure between provisioning and binding, the freshly created
	 * Etherpad pad is best-effort deleted before rethrowing.
	 *
	 * Pad-lifecycle ownership: this method **owns the Etherpad-side lifecycle**
	 * of any pad it provisions — callers that wrap the call in an outer
	 * rollback (e.g. `withCreateRollback`) must NOT also try to delete the
	 * pad in their rollback path. The outer wrapper's job is limited to the
	 * Nextcloud file it created; the pad is already cleaned up internally if
	 * we throw out of here.
	 *
	 * @return array{file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	/**
	 * @param null|callable(string):void $onPadProvisioned called as soon as the
	 *        pad exists, so an outer rollback knows about it even when a later
	 *        step throws
	 */
	public function materializeTemplateInto(File $target, File $template, ?IUser $user, ?callable $onPadProvisioned = null): array {
		if (!str_ends_with(strtolower($template->getName()), '.pad')) {
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

		$fileId = (int)$target->getId();
		if ($fileId <= 0) {
			throw new \RuntimeException('Could not resolve target file ID.');
		}

		$padId = $this->padBootstrapService->provisionPadId($accessMode);
		if ($onPadProvisioned !== null) {
			$onPadProvisioned($padId);
		}
		try {
			$this->padBootstrapService->pushInitialSnapshot($padId, $resolvedText, $resolvedHtml);
			$padUrl = $this->etherpadClient->buildPadUrl($padId);

			$content = $this->padFileService->buildInitialDocument(
				$fileId,
				$padId,
				$accessMode,
				$resolvedText,
				$padUrl,
			);
			$content = $this->padFileService->withExportSnapshot(
				$content,
				$resolvedText,
				$resolvedHtml,
				0,
				true,
			);
			$target->putContent($content);
			$this->bindingService->createBinding($fileId, $padId, $accessMode);
		} catch (\Throwable $e) {
			try {
				$this->etherpadClient->deletePad($padId);
			} catch (\Throwable $cleanupError) {
				$this->logger->warning('Could not cleanup Etherpad pad after template materialization failure.', [
					'app' => 'etherpad_nextcloud',
					'padId' => $padId,
					'exception' => $cleanupError,
				]);
			}
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
	 * @template T
	 * @param callable():T $action
	 * @param callable():void $rollback
	 * @param callable(\Throwable):?array{message:string,context:array<string,mixed>} $warningFor
	 * @param callable():array{message:string,context:array<string,mixed>} $errorFor
	 * @return T
	 */
	/**
	 * The id of the file we just created, or an exception.
	 *
	 * The file is left where it is. Nextcloud has no create-if-absent, so a
	 * node handed back by newFile() may be a file somebody else made moments
	 * earlier — and without an id there is nothing to check that against.
	 * The same rule holds here as in the rollback: unclear origin, no
	 * deletion. It is logged so the leftover can be found.
	 */
	private function requireFileId(File $fileNode): int {
		$fileId = (int)$fileNode->getId();
		if ($fileId > 0) {
			return $fileId;
		}
		$this->logger->warning('Created a .pad file whose ID could not be read; leaving it in place', [
			'app' => 'etherpad_nextcloud',
		]);
		throw new \RuntimeException('Could not resolve new file ID.');
	}

	private function withCreateRollback(
		callable $action,
		callable $rollback,
		callable $warningFor,
		callable $errorFor,
	): mixed {
		try {
			return $action();
		} catch (\Throwable $e) {
			$warning = $warningFor($e);
			if ($warning !== null) {
				$this->logger->warning($warning['message'], array_merge(
					['app' => 'etherpad_nextcloud'],
					$warning['context'],
					['exception' => $e],
				));
			} elseif (!($e instanceof PadFileAlreadyExistsException)) {
				$error = $errorFor();
				$this->logger->error($error['message'], array_merge(
					['app' => 'etherpad_nextcloud'],
					$error['context'],
					['exception' => $e],
				));
			}

			$rollback();
			throw $e;
		}
	}
}
