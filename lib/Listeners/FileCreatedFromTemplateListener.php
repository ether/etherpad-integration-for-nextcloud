<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Hooks into Nextcloud's native "+ New pad" template flow. The event fires
 * after NC has placed the new file on disk. Two cases:
 *
 * 1. **Blank template** — `$event->getTemplate()` is null. NC has dropped
 *    an empty `.pad` file. We initialise its frontmatter immediately via
 *    `PadBootstrapService::initializeMissingFrontmatter` so the very first
 *    `/open*` call after the picker succeeds. Without this step the viewer
 *    would log two 4xx network errors before its init-retry path finally
 *    runs `/initialize-by-id` — visible noise for anyone with dev tools
 *    open even though the pad eventually loads correctly.
 *
 * 2. **Source-template** — the heavy lifting (parse → resolve placeholders
 *    → provision pad → seed snapshot → rewrite file with fresh frontmatter
 *    → create binding) goes through `PadCreationService::materializeTemplateInto`,
 *    shared with the custom-frontend API entry point.
 *
 * On any skip / failure inside the source-template branch the target is
 * reset to empty *and* re-initialised so a fresh blank pad still opens
 * cleanly on the first call.
 */
class FileCreatedFromTemplateListener implements IEventListener {
	public function __construct(
		private PadCreationService $padCreationService,
		private PadBootstrapService $padBootstrapService,
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

		$user = $this->userSession->getUser();
		if ($user === null) {
			$this->logger->warning('Template event fired without an active user — leaving target empty.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$target->getId(),
			]);
			$this->resetTargetToEmpty($target);
			return;
		}

		$template = $event->getTemplate();
		if (!$template instanceof File) {
			// Blank-template case: initialise frontmatter now so /open
			// doesn't 4xx on the first call.
			$this->initializeBlankPad($user->getUID(), $target);
			return;
		}

		try {
			$this->padCreationService->materializeTemplateInto($target, $template, $user);
		} catch (\Throwable $e) {
			$this->logger->error('Pad template materialization failed — falling back to blank-pad init.', [
				'app' => 'etherpad_nextcloud',
				'targetFileId' => (int)$target->getId(),
				'templateFileId' => (int)$template->getId(),
				'exception' => $e,
			]);
			$this->resetTargetToEmpty($target);
			// Re-initialise after the wipe so the user still gets a clean,
			// openable pad even though the template path failed.
			$this->initializeBlankPad($user->getUID(), $target);
		}
	}

	private function initializeBlankPad(string $uid, File $target): void {
		try {
			$this->padBootstrapService->initializeMissingFrontmatter($uid, $target, '');
		} catch (\Throwable $e) {
			// Worst case the existing viewer retry path catches a missing-
			// frontmatter state and runs initialize-by-id — same behaviour
			// as before this listener was extended, plus a logged warning.
			$this->logger->warning('Could not initialise frontmatter for blank-template .pad — falling back to viewer init-retry path.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$target->getId(),
				'exception' => $e,
			]);
		}
	}

	private function resetTargetToEmpty(File $target): void {
		try {
			$target->putContent('');
		} catch (\Throwable $e) {
			$this->logger->warning('Could not reset target file content after rejected template.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$target->getId(),
				'exception' => $e,
			]);
		}
	}
}
