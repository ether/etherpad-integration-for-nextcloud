<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Service\AppAccess;
use OCA\EtherpadNextcloud\Service\ClaimedFileWriter;
use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCA\EtherpadNextcloud\Template\PadTemplateProvider;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\NotPermittedException;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Finishes a `.pad` file Nextcloud has just created from a template or from
 * the blank entry - see docs/templates.md for the flow. A blank file is
 * initialised here, so its first open does not fail and retry.
 *
 * @template-implements IEventListener<Event>
 */
class FileCreatedFromTemplateListener implements IEventListener {
	public function __construct(
		private PadCreationService $padCreationService,
		private PadBootstrapService $padBootstrapService,
		private PadTemplateStorage $templateStorage,
		private ExternalPadSeeder $externalPadSeeder,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AppAccess $appAccess,
		private ClaimedFileWriter $claimedFileWriter,
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
		if (!PadFileType::isPad($target->getName())) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			// Without a user the file cannot be found again by its id, so it
			// is emptied as handed over. A node whose id cannot be read is of
			// uncertain identity: written by its remembered path, it could
			// make a file or empty another, so it stays.
			$fileId = $this->idOrNull($target);
			if ($fileId === null) {
				$this->logger->warning('Template event fired without an active user; left the target as it is, as its id cannot be read.', [
					'app' => 'etherpad_nextcloud',
				]);
				return;
			}
			$this->logger->warning('Template event fired without an active user — resetting target to empty.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
			]);
			$this->resetTargetToEmpty($target, $fileId);
			return;
		}
		// Nextcloud has created the file already. It stays as it was given:
		// deleting it could take content written meanwhile, for good where
		// the delete skips the trash.
		if ($this->appAccess->excludesCurrentUser()) {
			throw new NotPermittedException('Pads are not enabled for this user.');
		}

		$template = $event->getTemplate();
		if (!$template instanceof File) {
			$this->initializeBlankPad($user->getUID(), $target, $this->idOrNull($target));
			return;
		}

		// The external tile's address arrives with the event, so the file is
		// complete before anyone opens it.
		if ($this->templateStorage->isExternalMarkerFile($template)) {
			$this->seedExternalPad($target, $user->getUID(), $event->getTemplateFields());
			return;
		}

		// Our own tiles carry no content: initialise straight to the pad type
		// instead of copying an empty marker over the new file.
		$templateAccessMode = $this->templateStorage->accessModeForTemplateFile($template);
		if ($templateAccessMode !== null) {
			$this->initializeBlankPad($user->getUID(), $target, $this->idOrNull($target), $templateAccessMode);
			return;
		}

		// Identity and content are captured before materialization, and
		// recovery uses the same claim. Both ids are read once, here: read
		// again while an error is handled, an id can throw, or name whatever
		// holds the path by then.
		try {
			$claim = $this->padCreationService->claimTemplateTarget($target, $user->getUID());
			$templateFileId = (int)$template->getId();
		} catch (\Throwable $e) {
			$this->logger->warning('Pad template create skipped: the target could not be identified.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
			return;
		}

		try {
			$this->padCreationService->materializeTemplateInto($template, $user, $claim);
		} catch (PadTypeDisabledException $e) {
			// Not a hiccup to retry around: the instance creates no pads at all
			// right now, which happens when the setting changes while the
			// picker is open. The blank fallback below would fail for the same
			// reason and leave an empty .pad nobody can open.
			$this->logger->warning('Pad template create refused: no pad type is enabled.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $claim->fileId,
				...SafeError::context($e),
			]);
			$this->claimedFileWriter->deleteIfUnchanged($claim);
			throw $e;
		} catch (PadFileChangedException $e) {
			// Do not blank content that the guarded write refused to overwrite.
			$this->logger->warning('Pad template create stopped: the target file changed while the pad was provisioned.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $claim->fileId,
				...SafeError::context($e),
			]);
			return;
		} catch (\Throwable $e) {
			$this->logger->error('Pad template materialization failed — falling back to blank-pad init.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $claim->fileId,
				'templateFileId' => $templateFileId,
				...SafeError::context($e),
			]);
			// Other failures do not imply unchanged content: check before resetting.
			$unchanged = $this->claimedFileWriter->resolveUnchanged($claim);
			if ($unchanged === null || !$this->resetTargetToEmpty($unchanged, $claim->fileId)) {
				return;
			}
			// Re-initialise as a blank pad; a disabled pad type travels on
			// from here as well. Only once the reset returned: otherwise the
			// init could make a pad and a row before the guard refused its
			// write on the template's copy.
			$this->initializeBlankPad($user->getUID(), $unchanged, $claim->fileId);
		}
	}

	/**
	 * $fileId is read before anything here can fail, as resetTargetToEmpty()
	 * says; null where it could not be read.
	 */
	private function initializeBlankPad(string $uid, File $target, ?int $fileId, ?string $preferredAccessMode = null): void {
		try {
			$this->padBootstrapService->initializeMissingFrontmatter($uid, $target, '', $preferredAccessMode);
		} catch (PadTypeDisabledException $e) {
			// Same as above, for Nextcloud's blank entry — and an instance
			// offering only external pads reaches this on every one of them,
			// so leaving the file behind would hand out empty .pad files.
			// Removed while it still reads as empty: one written since stays,
			// and so does one whose id could not be read.
			$this->logger->warning('Blank pad create refused: no pad type is enabled.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
			if ($fileId !== null) {
				$this->claimedFileWriter->deleteIfUnchanged(new CreatedFileClaim($uid, $fileId));
			}
			throw $e;
		} catch (\Throwable $e) {
			// The viewer's first open finds no frontmatter and initialises the
			// file by its id instead.
			$this->logger->warning('Could not initialise frontmatter for blank-template .pad — falling back to viewer init-retry path.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
		}
	}

	/**
	 * The document is written by the file's id and guarded, as the remote
	 * fetch can take seconds. A file that cannot be linked is removed while
	 * it is as the claim left it, rather than left behind: an empty `.pad`
	 * would become an ordinary local pad on first open, which is not what
	 * the user picked. One that cannot be claimed stays, as nothing tells it
	 * from a file written meanwhile. Nextcloud turns an exception into its
	 * own "Failed to create file from template" message and logs it.
	 *
	 * @see https://github.com/nextcloud/server/blob/v32.0.9/lib/private/Files/Template/TemplateManager.php
	 * @param array<string,mixed> $templateFields
	 */
	private function seedExternalPad(File $target, string $uid, array $templateFields): void {
		$claim = $this->padCreationService->claimTemplateTarget($target, $uid);
		try {
			$padUrl = $this->padUrlField($templateFields);
			if ($padUrl === '') {
				throw new \RuntimeException('No pad address was given for the external pad template.');
			}
			$prepared = $this->externalPadSeeder->prepare($claim->fileId, $padUrl);
			$this->claimedFileWriter->write($claim, $prepared['content']);
			$seeded = $prepared['result'];
			if (($seeded['snapshot_warning_code'] ?? '') === 'remote_export_unavailable') {
				// The pad is linked and opens; only its snapshot stays empty, as
				// the remote refused the export. This line is the admin's
				// record of it. Host and file id only: the URL, and the pad id
				// in it, give access to the pad.
				$this->logger->warning('Linked an external pad whose content could not be fetched; the snapshot stays empty.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $claim->fileId,
					'padHost' => parse_url((string)($seeded['pad_url'] ?? ''), PHP_URL_HOST) ?: '',
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not link a new .pad file to an external pad.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $claim->fileId,
				...SafeError::context($e),
			]);
			$this->claimedFileWriter->deleteIfUnchanged($claim);
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

	private function idOrNull(File $file): ?int {
		try {
			return (int)$file->getId();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Whether putContent('') returned without throwing; false does not say
	 * whether the write landed. `$fileId` is read by the caller
	 * before anything can fail rather than here: `Node::getId()` re-reads
	 * through the node's path — it can throw, and on a replaced path it
	 * answers for the wrong file.
	 */
	private function resetTargetToEmpty(File $target, int $fileId): bool {
		try {
			$target->putContent('');
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Could not reset target file content after rejected template.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
			return false;
		}
	}
}
