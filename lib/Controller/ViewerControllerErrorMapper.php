<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Maps viewer-controller exceptions to noviewer-shaped TemplateResponses.
 *
 * The viewer controller's normal success outcome is a RedirectResponse into
 * the Nextcloud files viewer; only error paths surface as TemplateResponse.
 */
class ViewerControllerErrorMapper {
	public function __construct(
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): RedirectResponse|TemplateResponse $success
	 * @param string|null $notFoundMessage context-specific message for `NotFoundException`; defaults to the open-pad wording
	 */
	public function runForTemplate(
		callable $action,
		callable $success,
		?string $notFoundMessage = null,
	): RedirectResponse|TemplateResponse {
		try {
			return $success($action());
		} catch (UnauthorizedRequestException) {
			return $this->errorTemplate($this->l10n->t('Authentication required.'));
		} catch (ControllerBadRequestException $e) {
			return $this->errorTemplate(
				$e->getMessage() !== '' ? $e->getMessage() : $this->l10n->t('Invalid input.')
			);
		} catch (NotFoundException) {
			return $this->errorTemplate(
				$notFoundMessage ?? $this->l10n->t('Cannot open selected .pad file.')
			);
		} catch (\Throwable $e) {
			$this->logger->error('Unhandled viewer controller error', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return $this->errorTemplate($this->l10n->t('Could not open pad.'));
		}
	}

	private function errorTemplate(string $error): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'noviewer', ['error' => $error], 'blank');
	}
}
