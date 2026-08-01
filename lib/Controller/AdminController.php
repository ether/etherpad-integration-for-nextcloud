<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\AdminPermissionRequiredException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\AdminConsistencyCheckResponseBuilder;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\AdminSettingsValidator;
use OCA\EtherpadNextcloud\Service\AdminTestFaultService;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\CookieDomainDecision;
use OCA\EtherpadNextcloud\Service\CookieDomainMessages;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\EtherpadHealthCheckService;
use OCA\EtherpadNextcloud\Service\HealthCheckResult;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * @psalm-api
 */
class AdminController extends Controller {
	private const CONSISTENCY_SAMPLE_LIMIT = 25;
	private const PENDING_DELETE_RETRY_BATCH_SIZE = 500;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IL10N $l10n,
		private AdminSettingsValidator $settingsValidator,
		private AdminSettingsRepository $settingsRepository,
		private EtherpadHealthCheckService $healthCheckService,
		private PendingDeleteRetryService $pendingDeleteRetryService,
		private ConsistencyCheckService $consistencyCheckService,
		private AdminConsistencyCheckResponseBuilder $consistencyResponseBuilder,
		private AdminTestFaultService $testFaultService,
		private AdminControllerErrorMapper $errors,
		private CookieDomainPolicy $cookieDomainPolicy,
		private CookieDomainMessages $cookieDomainMessages,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	public function saveSettings(): DataResponse {
		return $this->errors->run(
			function (): ValidatedAdminSettings {
				$this->requireAdmin();
				$settings = $this->settingsValidator->validateForSave(
					$this->readJsonPayload(),
					$this->settingsRepository->getStoredSettings(),
				);
				$this->settingsRepository->persist($settings);
				return $settings;
			},
			fn(ValidatedAdminSettings $settings): DataResponse => new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Settings saved.'),
				'api_version' => $settings->etherpadApiVersion,
				'has_api_key' => $this->settingsRepository->hasApiKey(),
				// Recomputed from what was just saved, so the page does not
				// keep showing a warning about the settings it replaced.
				'protected_pads' => $this->protectedPadsPayload($settings),
			]),
			[
				'generic' => $this->l10n->t('Failed to save settings.'),
				'log_message' => 'Saving Etherpad settings failed',
			],
		);
	}

	public function healthCheck(): DataResponse {
		return $this->errors->run(
			function (): HealthCheckResult {
				$this->requireAdmin();
				$settings = $this->settingsValidator->validateForHealthCheck(
					$this->readJsonPayload(),
					$this->settingsRepository->getStoredSettings(),
				);
				return $this->healthCheckService->check($settings);
			},
			fn(HealthCheckResult $result): DataResponse => new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Etherpad connection test successful.'),
				'host' => $result->host,
				'api_host' => $result->apiHost,
				'api_version' => $result->apiVersion,
				'pad_count' => $result->padCount,
				'latency_ms' => $result->latencyMs,
				'target' => $result->target,
				'pending_delete_count' => $result->pendingDeleteCount,
				// Reported next to the connection result, not as part of it:
				// the API can be reachable while protected pads still cannot
				// work. null when protected pads are switched off.
				'protected_pads' => $this->describeCookieDomain($result->cookieDomain, $result->cookieDomainMessage),
			]),
			[
				'generic' => $this->l10n->t('Etherpad connection test failed.'),
				'log_message' => 'Etherpad health check failed',
			],
		);
	}

	public function retryPendingDeletes(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				return $this->pendingDeleteRetryService->retry(self::PENDING_DELETE_RETRY_BATCH_SIZE);
			},
			fn(array $result): DataResponse => new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Pending delete retry finished.'),
				'attempted' => $result['attempted'],
				'resolved' => $result['resolved'],
				'failed' => $result['failed'],
				'remaining' => $result['remaining'],
			]),
			[
				'generic' => $this->l10n->t('Pending delete retry failed.'),
				'log_message' => 'Pending delete retry failed',
			],
		);
	}

	public function consistencyCheck(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				return $this->consistencyCheckService->run(self::CONSISTENCY_SAMPLE_LIMIT);
			},
			fn(array $result): DataResponse => new DataResponse($this->consistencyResponseBuilder->build($result)),
			[
				'generic' => $this->l10n->t('Consistency check failed.'),
				'log_message' => 'Consistency check failed',
			],
		);
	}

	public function setTestFault(): DataResponse {
		return $this->errors->run(
			function (): string {
				$this->requireAdmin();
				$payload = $this->readJsonPayload();
				$fault = trim((string)($payload['fault'] ?? ''));
				return $this->testFaultService->setFault($fault);
			},
			fn(string $fault): DataResponse => new DataResponse([
				'ok' => true,
				'fault' => $fault,
				'message' => $fault === ''
					? $this->l10n->t('Test fault cleared.')
					: $this->l10n->t('Test fault set: {fault}', ['fault' => $fault]),
			]),
			[
				'generic' => $this->l10n->t('Failed to update test fault.'),
				'log_message' => 'Updating test fault failed',
			],
		);
	}

	/** @return array<string,mixed> */
	private function readJsonPayload(): array {
		return $this->request->getParams();
	}

	private function requireAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new UnauthorizedRequestException('Authentication required.');
		}
		if (!$this->groupManager->isAdmin($user->getUID())) {
			throw new AdminPermissionRequiredException('Admin permissions required.');
		}
	}

	/** @return array<string,mixed>|null */
	private function protectedPadsPayload(ValidatedAdminSettings $settings): ?array {
		if (!$settings->enableProtectedPads) {
			return null;
		}
		$decision = $this->cookieDomainPolicy->decide(
			$this->urlGenerator->getBaseUrl(),
			$settings->etherpadHost,
			$this->cookieDomainPolicy->storedValue($settings->etherpadCookieDomain, $settings->cookieDomainConfigured),
		);
		return $this->describeCookieDomain($decision, $this->cookieDomainMessages->describe($decision));
	}

	/** @return array<string,mixed>|null */
	private function describeCookieDomain(?CookieDomainDecision $decision, string $message): ?array {
		if ($decision === null) {
			return null;
		}
		return [
			'ok' => $decision->isOk(),
			'status' => $decision->status,
			'reason' => $decision->reason,
			'cookie_domain' => $decision->effectiveDomain,
			'cookie_domain_source' => $decision->source,
			'nextcloud_host' => $decision->nextcloudHost,
			'etherpad_host' => $decision->etherpadHost,
			'message' => $message,
		];
	}
}
