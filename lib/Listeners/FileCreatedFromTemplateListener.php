<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCA\EtherpadNextcloud\Template\PadTemplateProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Finishes a `.pad` file Nextcloud has just created from a template or from
 * the blank entry — see docs/templates.md for the flow.
 *
 * The blank case is initialised here rather than left to the viewer's
 * init-retry path: without it the first `/open*` call 4xxes twice before the
 * pad loads, which is visible noise for anyone with dev tools open.
 *
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class FileCreatedFromTemplateListener implements IEventListener {
	public function __construct(
		private PadCreationService $padCreationService,
		private PadBootstrapService $padBootstrapService,
		private PadTemplateStorage $templateStorage,
		private ExternalPadSeeder $externalPadSeeder,
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
			$this->logger->warning('Template event fired without an active user — resetting target to empty.', [
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

		// The external tile's address arrives with the event, so the file is
		// complete before anyone opens it.
		if ($this->templateStorage->isExternalMarkerFile($template)) {
			$this->seedExternalPad($target, $event->getTemplateFields());
			return;
		}

		// Our own tiles carry no content: initialise straight to the pad type
		// instead of copying an empty marker over the new file.
		$templateAccessMode = $this->templateStorage->accessModeForTemplateFile($template);
		if ($templateAccessMode !== '') {
			$this->initializeBlankPad($user->getUID(), $target, $templateAccessMode);
			return;
		}

		try {
			$this->padCreationService->materializeTemplateInto($target, $template, $user);
		} catch (PadTypeDisabledException $e) {
			// Not a hiccup to retry around: the instance creates no pads at all
			// right now, which happens when the setting changes while the
			// picker is open. The blank fallback below would fail for the same
			// reason and leave an empty .pad nobody can open.
			$this->logger->warning('Pad template create refused: no pad type is enabled.', [
				'app' => 'etherpad_nextcloud',
				'targetFileId' => (int)$target->getId(),
				'exception' => $e,
			]);
			$this->deleteTarget($target);
			throw $e;
		} catch (PadFileChangedException $e) {
			// Somebody else wrote into this file while the pad was being
			// provisioned. The blank fallback below would empty exactly the
			// content that check just refused to overwrite, so this failure
			// ends here without touching the file at all.
			$this->logger->warning('Pad template create stopped: the target file changed while the pad was provisioned.', [
				'app' => 'etherpad_nextcloud',
				'targetFileId' => (int)$target->getId(),
				'exception' => $e,
			]);
			return;
		} catch (\Throwable $e) {
			$this->logger->error('Pad template materialization failed — falling back to blank-pad init.', [
				'app' => 'etherpad_nextcloud',
				'targetFileId' => (int)$target->getId(),
				'templateFileId' => (int)$template->getId(),
				'exception' => $e,
			]);
			$this->resetTargetToEmpty($target);
			// Re-initialise after the wipe so the user still gets a clean,
			// openable pad even though the template path failed. A disabled
			// pad type travels on from here as well.
			$this->initializeBlankPad($user->getUID(), $target);
		}
	}

	private function initializeBlankPad(string $uid, File $target, ?string $preferredAccessMode = null): void {
		try {
			$this->padBootstrapService->initializeMissingFrontmatter($uid, $target, '', $preferredAccessMode);
		} catch (PadTypeDisabledException $e) {
			// Same as above, for Nextcloud's blank entry — and an instance
			// offering only external pads reaches this on every one of them,
			// so leaving the file behind would hand out empty .pad files.
			$this->logger->warning('Blank pad create refused: no pad type is enabled.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$target->getId(),
				'exception' => $e,
			]);
			$this->deleteTarget($target);
			throw $e;
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

	/**
	 * A file that cannot be linked is removed rather than left behind: an empty
	 * `.pad` would become an ordinary local pad on first open, which is not
	 * what the user picked. Nextcloud turns the exception into its own "could
	 * not create from template" message and logs the reason.
	 *
	 * @param array<string,mixed> $templateFields
	 */
	private function seedExternalPad(File $target, array $templateFields): void {
		$fileId = (int)$target->getId();
		try {
			$padUrl = $this->padUrlField($templateFields);
			if ($padUrl === '') {
				throw new \RuntimeException('No pad address was given for the external pad template.');
			}
			if ($fileId <= 0) {
				throw new \RuntimeException('Could not resolve new file ID.');
			}
			$seeded = $this->externalPadSeeder->seed($target, $fileId, $padUrl);
			if (($seeded['snapshot_warning_code'] ?? '') === 'remote_export_unavailable') {
				// The pad is linked and opens; only its stored snapshot stays
				// empty because the remote refused the export. The picker has
				// no place to show that, and the viewer says so on open, so
				// this is the record an admin can act on.
				// Host only. A public pad's URL *is* its access link — logging
				// it would hand out the pad to anyone who reads the log, and
				// the same goes for the pad id in it. The file id identifies
				// the file for anyone who may see it anyway.
				$this->logger->warning('Linked an external pad whose content could not be fetched; the snapshot stays empty.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'padHost' => parse_url((string)($seeded['pad_url'] ?? ''), PHP_URL_HOST) ?: '',
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not link a new .pad file to an external pad.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'exception' => $e,
			]);
			$this->deleteTarget($target);
			throw $e;
		}
	}

	/**
	 * The picker submits one entry per field, each an object of the properties
	 * its type carries — for a text field, its content.
	 *
	 * @param array<string,mixed> $templateFields
	 */
	private function padUrlField(array $templateFields): string {
		$field = $templateFields[PadTemplateProvider::FIELD_PAD_URL] ?? null;
		if (is_array($field)) {
			return trim((string)($field['content'] ?? ''));
		}
		return is_string($field) ? trim($field) : '';
	}

	private function deleteTarget(File $target): void {
		try {
			$target->delete();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove the .pad file of a failed template create.', [
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
