<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\EmbedResponseBuilder;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Maps embed-controller exceptions to embed-shaped TemplateResponses.
 *
 * Endpoints provide an `error_title` so the noviewer template can show a
 * context-appropriate heading (e.g. "Unable to open pad" vs. "Unable to create
 * pad"). Unhandled errors are logged and rendered with a generic message.
 */
class EmbedControllerErrorMapper {
	public function __construct(
		private EmbedResponseBuilder $responseBuilder,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): TemplateResponse $success
	 * @param string $errorTitle title rendered in the noviewer template on failure
	 * @param string|null $notFoundMessage context-specific message for `NotFoundException`; defaults to the open-pad wording
	 */
	public function runForTemplate(
		callable $action,
		callable $success,
		string $errorTitle,
		?string $notFoundMessage = null,
	): TemplateResponse {
		try {
			return $success($action());
		} catch (UnauthorizedRequestException) {
			return $this->errorTemplate($this->l10n->t('Authentication required.'), $errorTitle);
		} catch (ControllerBadRequestException $e) {
			return $this->errorTemplate(
				$e->getMessage() !== '' ? $e->getMessage() : $this->l10n->t('Invalid input.'),
				$errorTitle,
			);
		} catch (NotFoundException) {
			return $this->errorTemplate(
				$notFoundMessage ?? $this->l10n->t('Cannot open selected .pad file.'),
				$errorTitle,
			);
		} catch (NotAPadFileException $e) {
			return $this->errorTemplate(
				$e->getMessage() !== '' ? $e->getMessage() : $this->l10n->t('Selected file is not a .pad file.'),
				$errorTitle,
			);
		} catch (PadParentFolderNotWritableException) {
			return $this->errorTemplate(
				$this->l10n->t('Selected parent folder is not writable.'),
				$errorTitle,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Unhandled embed controller error', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return $this->errorTemplate($this->l10n->t('Could not open pad.'), $errorTitle);
		}
	}

	private function errorTemplate(string $error, string $title): TemplateResponse {
		return $this->responseBuilder->build('noviewer', [
			'error' => $error,
			'title' => $title,
		]);
	}
}
