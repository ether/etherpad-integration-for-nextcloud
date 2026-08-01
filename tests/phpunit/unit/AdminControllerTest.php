<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\AdminController;
use OCA\EtherpadNextcloud\Controller\AdminControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\AdminDebugModeRequiredException;
use OCA\EtherpadNextcloud\Exception\UnsupportedTestFaultException;
use OCA\EtherpadNextcloud\Service\AdminConsistencyCheckResponseBuilder;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\AdminSettingsValidator;
use OCA\EtherpadNextcloud\Service\AdminTestFaultService;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\CookieDomainDecision;
use OCA\EtherpadNextcloud\Service\HealthCheckItem;
use OCA\EtherpadNextcloud\Service\CookieDomainMessages;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\EtherpadHealthCheckService;
use OCA\EtherpadNextcloud\Service\HealthCheckResult;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCA\EtherpadNextcloud\Service\StoredAdminSettings;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminControllerTest extends TestCase {
	public function testSaveSettingsReturnsUnauthorizedWhenNoUserSession(): void {
		$response = $this->buildController(userSession: $this->userSession(null))->saveSettings();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse((bool)$response->getData()['ok']);
	}

	public function testSaveSettingsReturnsForbiddenForNonAdminUser(): void {
		$response = $this->buildController(groupManager: $this->adminGroup(false))->saveSettings();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse((bool)$response->getData()['ok']);
	}

	public function testSaveSettingsPersistsValidatedSettings(): void {
		$request = $this->request(['etherpad_host' => 'https://pad.example.test']);
		$stored = new StoredAdminSettings('old-key', '', true, false, '');
		$validated = $this->validatedSettings();

		$repository = $this->createMock(AdminSettingsRepository::class);
		$repository->method('getStoredSettings')->willReturn($stored);
		$repository->expects($this->once())->method('persist')->with($validated);
		$repository->method('hasApiKey')->willReturn(true);

		$validator = $this->createMock(AdminSettingsValidator::class);
		$validator->expects($this->once())
			->method('validateForSave')
			->with(['etherpad_host' => 'https://pad.example.test'], $stored)
			->willReturn($validated);

		$response = $this->buildController($request, validator: $validator, repository: $repository)->saveSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$response->getData()['ok']);
		$this->assertSame('1.3.0', $response->getData()['api_version']);
		$this->assertTrue((bool)$response->getData()['has_api_key']);
		// Recomputed from the saved values, in the connection test's shape, so
		// the page refreshes the verdict at the cookie domain field.
		$checks = $response->getData()['checks'];
		$this->assertCount(1, $checks);
		$this->assertSame('protected_pads', $checks[0]['id']);
		$this->assertSame(HealthCheckItem::STATUS_OK, $checks[0]['status']);
		$this->assertSame('etherpad_cookie_domain', $checks[0]['field']);
		$this->assertSame('.example.test', $checks[0]['detail']);
	}

	public function testHealthCheckReturnsApiAndPendingDeleteMetrics(): void {
		$request = $this->request(['etherpad_host' => 'https://pad.example.test']);
		$stored = new StoredAdminSettings('existing-key', '', true, false, '');
		$validated = $this->validatedSettings();

		$repository = $this->createMock(AdminSettingsRepository::class);
		$repository->method('getStoredSettings')->willReturn($stored);

		$validator = $this->createMock(AdminSettingsValidator::class);
		$validator->expects($this->once())
			->method('validateForHealthCheck')
			->with(['etherpad_host' => 'https://pad.example.test'], $stored)
			->willReturn($validated);

		$health = $this->createMock(EtherpadHealthCheckService::class);
		$health->expects($this->once())
			->method('check')
			->with($validated)
			->willReturn(new HealthCheckResult(
				'https://pad.example.test',
				'https://pad-api.internal',
				'1.3.0',
				72,
				123,
				'https://pad-api.internal/api/1.3.0/listAllPads',
				3,
				new CookieDomainDecision(
					'',
					CookieDomainDecision::STATUS_WARNING,
					CookieDomainDecision::REASON_NO_COMMON_PARENT,
					'cloud.example.test',
					'pad.unrelated.test',
					CookieDomainDecision::SOURCE_HOST_ONLY,
				),
			));

		$response = $this->buildController($request, validator: $validator, repository: $repository, healthCheck: $health)->healthCheck();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$data['ok']);
		$this->assertSame(72, $data['pad_count']);
		$this->assertSame(3, $data['pending_delete_count']);
		$this->assertArrayNotHasKey('trashed_without_file_count', $data);
		$this->assertSame('https://pad-api.internal/api/1.3.0/listAllPads', $data['target']);
		// A protected-pads problem is reported beside the result, not as a
		// failed connection test, and the controller renders its text.
		$this->assertFalse($data['protected_pads']['ok']);
		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $data['protected_pads']['status']);
		$this->assertSame(CookieDomainDecision::REASON_NO_COMMON_PARENT, $data['protected_pads']['reason']);
		$this->assertSame(CookieDomainDecision::SOURCE_HOST_ONLY, $data['protected_pads']['cookie_domain_source']);
		$this->assertStringContainsString('cloud.example.test', $data['protected_pads']['message']);
		$this->assertStringContainsString('pad.unrelated.test', $data['protected_pads']['message']);
	}

	public function testRetryPendingDeletesUsesConfiguredBatchSize(): void {
		$pendingDeletes = $this->createMock(PendingDeleteRetryService::class);
		$pendingDeletes->expects($this->once())
			->method('retry')
			->with(500)
			->willReturn([
				'attempted' => 1,
				'resolved' => 1,
				'failed' => 0,
				'remaining' => 0,
			]);

		$response = $this->buildController(pendingDeletes: $pendingDeletes)->retryPendingDeletes();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['attempted']);
	}

	public function testSetTestFaultRequiresDebugMode(): void {
		$testFaults = $this->createMock(AdminTestFaultService::class);
		$testFaults->method('setFault')->willThrowException(new AdminDebugModeRequiredException());

		$response = $this->buildController(testFaults: $testFaults)->setTestFault();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse((bool)$response->getData()['ok']);
	}

	public function testSetTestFaultRejectsUnsupportedFault(): void {
		$testFaults = $this->createMock(AdminTestFaultService::class);
		$testFaults->expects($this->once())
			->method('setFault')
			->with('unknown_fault')
			->willThrowException(new UnsupportedTestFaultException(['trash_read_lock']));

		$response = $this->buildController(
			$this->request(['fault' => 'unknown_fault']),
			testFaults: $testFaults,
		)->setTestFault();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse((bool)$response->getData()['ok']);
		$this->assertNotEmpty($response->getData()['supported_faults']);
	}

	public function testSetTestFaultPersistsSupportedFault(): void {
		$testFaults = $this->createMock(AdminTestFaultService::class);
		$testFaults->expects($this->once())
			->method('setFault')
			->with('trash_read_lock')
			->willReturn('trash_read_lock');

		$response = $this->buildController(
			$this->request(['fault' => 'trash_read_lock']),
			testFaults: $testFaults,
		)->setTestFault();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$response->getData()['ok']);
		$this->assertSame('trash_read_lock', $response->getData()['fault']);
	}

	private function buildController(
		?IRequest $request = null,
		?IUserSession $userSession = null,
		?IGroupManager $groupManager = null,
		?AdminSettingsValidator $validator = null,
		?AdminSettingsRepository $repository = null,
		?EtherpadHealthCheckService $healthCheck = null,
		?PendingDeleteRetryService $pendingDeletes = null,
		?ConsistencyCheckService $consistencyCheck = null,
		?AdminConsistencyCheckResponseBuilder $consistencyResponses = null,
		?AdminTestFaultService $testFaults = null,
	): AdminController {
		$l10n = $this->buildL10n();
		$logger = $this->createMock(LoggerInterface::class);
		return new AdminController(
			'etherpad_nextcloud',
			$request ?? $this->request([]),
			$userSession ?? $this->userSession('admin'),
			$groupManager ?? $this->adminGroup(true),
			$l10n,
			$validator ?? $this->createMock(AdminSettingsValidator::class),
			$repository ?? $this->createMock(AdminSettingsRepository::class),
			$healthCheck ?? $this->createMock(EtherpadHealthCheckService::class),
			$pendingDeletes ?? $this->createMock(PendingDeleteRetryService::class),
			$consistencyCheck ?? $this->createMock(ConsistencyCheckService::class),
			$consistencyResponses ?? new AdminConsistencyCheckResponseBuilder($l10n),
			$testFaults ?? $this->createMock(AdminTestFaultService::class),
			new AdminControllerErrorMapper($l10n, $logger),
			new CookieDomainPolicy(),
			new CookieDomainMessages($l10n),
			$this->urlGenerator(),
		);
	}

	private function urlGenerator(string $baseUrl = 'https://cloud.example.test'): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn($baseUrl);
		return $urlGenerator;
	}

	/** @param array<string,mixed> $payload */
	private function request(array $payload): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($payload);
		return $request;
	}

	private function userSession(?string $uid): IUserSession {
		$userSession = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$userSession->method('getUser')->willReturn(null);
			return $userSession;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession->method('getUser')->willReturn($user);
		return $userSession;
	}

	private function adminGroup(bool $isAdmin): IGroupManager {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		return $groupManager;
	}

	private function validatedSettings(): ValidatedAdminSettings {
		return new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad-api.internal',
			'.example.test',
			'new-api-key',
			'new-api-key',
			'1.3.0',
			120,
			true,
			true,
			'pad.example.test',
			'',
		);
	}

	private function buildL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				foreach ($parameters as $key => $value) {
					$text = str_replace('{' . $key . '}', (string)$value, $text);
				}
				return $text;
			}
		);
		return $l10n;
	}
}
