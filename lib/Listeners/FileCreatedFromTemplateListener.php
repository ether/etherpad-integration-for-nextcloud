<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadPlaceholderResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class FileCreatedFromTemplateListener implements IEventListener {
	public function __construct(
		private PadFileService $padFileService,
		private PadPlaceholderResolver $placeholderResolver,
		private PadBootstrapService $padBootstrapService,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof FileCreatedFromTemplateEvent)) {
			return;
		}
		$target = $event->getTarget();
		if (!$target instanceof File) {
			return;
		}
		if (!str_ends_with(strtolower($target->getName()), '.pad')) {
			return;
		}
		$template = $event->getTemplate();
		if (!$template instanceof File) {
			// Blank template (no source) — leave the file empty so the
			// regular missing-frontmatter initialize path handles it.
			return;
		}

		try {
			$this->materializeFromTemplate($target, $template);
		} catch (\Throwable $e) {
			$this->logger->error('Pad template materialization failed.', [
				'app' => 'etherpad_nextcloud',
				'targetFileId' => (int)$target->getId(),
				'templateFileId' => (int)$template->getId(),
				'exception' => $e,
			]);
		}
	}

	private function materializeFromTemplate(File $target, File $template): void {
		$user = $this->userSession->getUser();
		$content = (string)$template->getContent();
		if (trim($content) === '') {
			return;
		}

		$parsed = $this->padFileService->parsePadFile($content);
		$frontmatter = $parsed['frontmatter'];
		$meta = $this->padFileService->extractPadMetadata($frontmatter);
		$sourcePadId = (string)($meta['pad_id'] ?? '');
		if ($sourcePadId === '' || str_starts_with($sourcePadId, 'ext.')
			|| $this->padFileService->isExternalFrontmatter($frontmatter, $sourcePadId)) {
			// External / unparseable templates carry no usable body for a
			// managed-pad clone. Skip and let the empty target initialize
			// normally on first open.
			return;
		}

		$accessMode = (string)($meta['access_mode'] ?? BindingService::ACCESS_PROTECTED);
		if ($accessMode !== BindingService::ACCESS_PUBLIC && $accessMode !== BindingService::ACCESS_PROTECTED) {
			$accessMode = BindingService::ACCESS_PROTECTED;
		}

		$snapshot = $this->padFileService->getSnapshotPartsFromBody((string)$parsed['body']);
		$resolvedText = $this->placeholderResolver->apply($snapshot['text'], $user);
		$resolvedHtml = $this->placeholderResolver->apply($snapshot['html'], $user);

		$newPadId = $this->padBootstrapService->provisionPadId($accessMode);
		$padCreated = true;

		try {
			$this->padBootstrapService->pushInitialSnapshot($newPadId, $resolvedText, $resolvedHtml);

			$targetFileId = (int)$target->getId();
			$padUrl = $this->etherpadClient->buildPadUrl($newPadId);
			$initialDoc = $this->padFileService->buildInitialDocument(
				$targetFileId,
				$newPadId,
				$accessMode,
				$resolvedText,
				$padUrl,
			);
			$withSnapshot = $this->padFileService->withExportSnapshot(
				$initialDoc,
				$resolvedText,
				$resolvedHtml,
				0,
				true,
			);
			$target->putContent($withSnapshot);
			$this->bindingService->createBinding($targetFileId, $newPadId, $accessMode);
			$padCreated = false;
		} catch (\Throwable $e) {
			if ($padCreated) {
				try {
					$this->etherpadClient->deletePad($newPadId);
				} catch (\Throwable $cleanupError) {
					$this->logger->warning('Could not cleanup Etherpad pad after template materialization failed.', [
						'app' => 'etherpad_nextcloud',
						'padId' => $newPadId,
						'exception' => $cleanupError,
					]);
				}
			}
			throw $e;
		}
	}

}
