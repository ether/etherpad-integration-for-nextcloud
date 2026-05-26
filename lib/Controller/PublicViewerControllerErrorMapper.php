<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\NoShareFileSelectedException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\ShareFileNotInShareException;
use OCA\EtherpadNextcloud\Exception\ShareItemUnavailableException;
use OCA\EtherpadNextcloud\Exception\ShareReadForbiddenException;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use Psr\Log\LoggerInterface;

class PublicViewerControllerErrorMapper {
	public function __construct(
		private PublicShareUrlBuilder $shareUrlBuilder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): DataResponse $success
	 */
	public function runForData(callable $action, callable $success): DataResponse {
		try {
			return $success($action());
		} catch (\Throwable $e) {
			$this->logUnexpected($e);
			return new DataResponse(['message' => $this->messageFor($e)], $this->statusFor($e));
		}
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): RedirectResponse|TemplateResponse $success
	 */
	public function runForTemplate(callable $action, callable $success, string $token): RedirectResponse|TemplateResponse {
		try {
			return $success($action());
		} catch (\Throwable $e) {
			$this->logUnexpected($e);
			$response = new TemplateResponse(Application::APP_ID, 'noviewer', [
				'error' => $this->messageFor($e),
				'back_url' => $this->shareUrlBuilder->buildShareBaseUrl($token),
				'back_label' => 'Back to shared files',
			], 'blank');
			$response->setStatus($this->statusFor($e));
			return $response;
		}
	}

	private function statusFor(\Throwable $e): int {
		return match (true) {
			$e instanceof InvalidShareTokenException,
			$e instanceof ShareItemUnavailableException,
			$e instanceof ShareFileNotInShareException => Http::STATUS_NOT_FOUND,
			$e instanceof ShareReadForbiddenException => Http::STATUS_FORBIDDEN,
			$e instanceof InvalidShareFilePathException,
			$e instanceof NoShareFileSelectedException,
			$e instanceof NotAPadFileException,
			$e instanceof PadFileFormatException,
			$e instanceof BindingException,
			$e instanceof EtherpadClientException => Http::STATUS_BAD_REQUEST,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};
	}

	private function messageFor(\Throwable $e): string {
		if ($e instanceof PadFileFormatException) {
			if (str_contains($e->getMessage(), 'Missing YAML frontmatter')) {
				return 'The selected .pad file is missing required metadata.';
			}
			return 'The selected .pad file has an invalid format.';
		}
		if ($e instanceof BindingException) {
			if ($e instanceof MissingBindingException) {
				return 'The selected .pad file is a copied file without an active pad binding. Please open the original shared .pad file.';
			}
			return 'Pad binding is inconsistent. Please contact the share owner.';
		}
		if ($e instanceof EtherpadClientException) {
			return 'Etherpad is currently unavailable for this shared pad.';
		}
		if ($this->isExpectedPublicError($e)) {
			return $e->getMessage();
		}
		return 'Could not open pad.';
	}

	private function logUnexpected(\Throwable $e): void {
		if ($this->isExpectedPublicError($e)) {
			return;
		}

		$this->logger->error('Unhandled public viewer error', [
			'app' => Application::APP_ID,
			'exception' => $e,
		]);
	}

	private function isExpectedPublicError(\Throwable $e): bool {
		return $e instanceof InvalidShareTokenException
			|| $e instanceof ShareItemUnavailableException
			|| $e instanceof ShareFileNotInShareException
			|| $e instanceof ShareReadForbiddenException
			|| $e instanceof InvalidShareFilePathException
			|| $e instanceof NoShareFileSelectedException
			|| $e instanceof NotAPadFileException
			|| $e instanceof PadFileFormatException
			|| $e instanceof BindingException
			|| $e instanceof EtherpadClientException;
	}
}
