<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\AdminDebugModeRequiredException;
use OCA\EtherpadNextcloud\Exception\AdminHealthCheckException;
use OCA\EtherpadNextcloud\Exception\AdminPermissionRequiredException;
use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Exception\UnsupportedTestFaultException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class AdminControllerErrorMapper {
	public function __construct(
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): DataResponse $success
	 * @param array{generic?: string, log_message?: string} $options
	 */
	public function run(callable $action, callable $success, array $options = []): DataResponse {
		try {
			return $success($action());
		} catch (UnauthorizedRequestException) {
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Authentication required.'),
			], Http::STATUS_UNAUTHORIZED);
		} catch (AdminPermissionRequiredException) {
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Admin permissions required.'),
			], Http::STATUS_FORBIDDEN);
		} catch (AdminDebugModeRequiredException) {
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Test faults are available only when Nextcloud debug mode is enabled.'),
			], Http::STATUS_FORBIDDEN);
		} catch (AdminValidationException $e) {
			return new DataResponse([
				'ok' => false,
				'message' => $e->getMessage(),
				'field' => $e->getField(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			if ($e instanceof UnsupportedTestFaultException) {
				return new DataResponse([
					'ok' => false,
					'message' => $this->l10n->t('Unsupported test fault.'),
					'supported_faults' => $e->getSupportedFaults(),
				], Http::STATUS_BAD_REQUEST);
			}
			return new DataResponse([
				'ok' => false,
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (AdminHealthCheckException $e) {
			$payload = ['ok' => false, 'message' => $e->getMessage()];
			// Same shape the validator uses, so the page marks the field the
			// failure came from instead of reporting it only at the bottom.
			if ($e->getField() !== '') {
				$payload['field'] = $e->getField();
			}
			return new DataResponse($payload, Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $e) {
			$this->logger->error((string)($options['log_message'] ?? 'Admin request failed'), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return new DataResponse([
				'ok' => false,
				'message' => (string)($options['generic'] ?? $this->l10n->t('Request failed.')),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
