<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\AdminController;
use OCA\EtherpadNextcloud\Controller\AdminControllerErrorMapper;
use OCA\EtherpadNextcloud\Service\AdminConsistencyCheckResponseBuilder;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\AdminSettingsValidator;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\CookieDomainDecision;
use OCA\EtherpadNextcloud\Service\HealthCheckItem;
use OCA\EtherpadNextcloud\Service\CookieDomainMessages;
use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\PadTemplateAdminService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\EtherpadHealthCheckService;
use OCA\EtherpadNextcloud\Service\GoneFileSweep;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Service\HealthCheckResult;
use OCA\EtherpadNextcloud\Service\StoredAdminSettings;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
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
				123,
				'https://pad-api.internal/api/1.3.0/checkToken',
				3,
				'3.3.3',
				new CookieDomainDecision(
					'.example.tests',
					CookieDomainDecision::STATUS_WARNING,
					CookieDomainDecision::REASON_CONFIGURED_DOMAIN_MISMATCH,
					'cloud.example.test',
					'pad.example.test',
					CookieDomainDecision::SOURCE_CONFIGURED,
					'.example.test',
				),
				// The service's own lines; the controller appends the
				// protected-pads one from the decision above.
				[new HealthCheckItem('api', HealthCheckItem::STATUS_OK, 'Etherpad API reachable', '', 'etherpad_api_host')],
			));

		$response = $this->buildController($request, validator: $validator, repository: $repository, healthCheck: $health)->healthCheck();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$data['ok']);
		$this->assertSame(3, $data['pending_delete_count']);
		// The release the open path is going by, machine-readable, because
		// it can differ from whatever this check just probed.
		$this->assertSame('3.3.3', $data['session_cookie_release']);
		$this->assertArrayNotHasKey('trashed_without_file_count', $data);
		$this->assertSame('https://pad-api.internal/api/1.3.0/checkToken', $data['target']);
		// A protected-pads problem is reported beside the result, not as a
		// failed connection test, and the controller renders its text.
		$this->assertFalse($data['protected_pads']['ok']);
		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $data['protected_pads']['status']);
		$this->assertSame(CookieDomainDecision::REASON_CONFIGURED_DOMAIN_MISMATCH, $data['protected_pads']['reason']);
		$this->assertSame(CookieDomainDecision::SOURCE_CONFIGURED, $data['protected_pads']['cookie_domain_source']);

		// The controller appends the protected-pads line itself, so summary
		// and list describe the same outcome — the service returns neither.
		$line = end($data['checks']);
		$this->assertSame('protected_pads', $line['id']);
		$this->assertSame(HealthCheckItem::STATUS_WARNING, $line['status']);
		$this->assertStringContainsString('cloud.example.test', $line['detail']);
		$this->assertStringContainsString('.example.test would cover both', $line['detail']);
		$this->assertStringContainsString('need attention', $data['message']);
	}

	/**
	 * The admin's check runs the sweep of files gone for good at once, the
	 * grace not waited out, within one run's budget, and says how many are
	 * left.
	 */
	public function testSettlePendingRunsTheSweepAtOnce(): void {
		$sweep = $this->createMock(GoneFileSweep::class);
		$sweep->expects($this->once())->method('run')->with($this->callback(
			static fn (RunBudget $budget): bool => $budget->callTimeout() === EtherpadClient::REQUEST_TIMEOUT_SECONDS,
		), true)->willReturn(['checked' => 2, 'deleted' => 1]);
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('countPendingDeletes')->willReturn(3);

		$response = $this->buildController(goneFileSweep: $sweep, bindings: $bindings)->settlePending();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $response->getData()['checked']);
		$this->assertSame(1, $response->getData()['settled']);
		$this->assertSame(3, $response->getData()['pending_delete_count']);
	}

	/**
	 * With deleting off the check deletes nothing; the answer says why, so
	 * the count of pending deletes does not stand unexplained.
	 */
	public function testSettlePendingSaysWhenDeletingIsOff(): void {
		$sweep = $this->createMock(GoneFileSweep::class);
		$sweep->method('run')->willReturn(['checked' => 0, 'deleted' => 0]);
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('countPendingDeletes')->willReturn(3412);
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('isDeletePadWithFileEnabled')->willReturn(false);

		$response = $this->buildController(goneFileSweep: $sweep, bindings: $bindings, appConfig: $appConfig)->settlePending();

		$this->assertSame('Deleting pads is switched off, so no pad was deleted. The files deleted for good wait for it to be switched on.', $response->getData()['message']);
		$this->assertSame(3412, $response->getData()['pending_delete_count']);
	}

	/**
	 * On the admin's word the vanished files' rows are marked, within one
	 * run's budget, for the sweep; the answer says how many, how many are
	 * left, and how many pads now wait to go.
	 */
	public function testDeleteVanishedMarksTheirRows(): void {
		$consistency = $this->createMock(ConsistencyCheckService::class);
		$consistency->expects($this->once())->method('markVanished')->with($this->callback(
			static fn (RunBudget $budget): bool => !$budget->exhausted(),
		))->willReturn(4);
		$consistency->method('countVanished')->willReturn(0);
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('countPendingDeletes')->willReturn(6);

		$response = $this->buildController(consistencyCheck: $consistency, bindings: $bindings)->deleteVanished();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ok' => true, 'message' => 'The pads of the vanished files are marked for deletion. They are deleted within minutes, or at once with "Check pending pads".', 'marked' => 4, 'vanished_file_count' => 0, 'pending_delete_count' => 6], $response->getData());
	}

	/**
	 * With deleting off a mark would only wait for it to be switched on:
	 * nothing is marked, and the answer says so. Rows left after the budget
	 * ask for another run.
	 */
	public function testDeleteVanishedSaysWhatItDidNot(): void {
		$consistency = $this->createMock(ConsistencyCheckService::class);
		$consistency->expects($this->never())->method('markVanished');
		$consistency->method('countVanished')->willReturn(12);
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('isDeletePadWithFileEnabled')->willReturn(false);

		$off = $this->buildController(consistencyCheck: $consistency, appConfig: $appConfig)->deleteVanished()->getData();

		$this->assertSame('Deleting pads is switched off, so no pad was marked for deletion.', $off['message']);
		$this->assertSame([0, 12], [$off['marked'], $off['vanished_file_count']]);
		$consistency->expects($this->never())->method('markVanishedFile');
		$offOne = $this->buildController(request: $this->request(['fileId' => '42']), consistencyCheck: $consistency, appConfig: $appConfig)->deleteVanished()->getData();
		$this->assertSame(['Deleting pads is switched off, so no pad was marked for deletion.', 0], [$offOne['message'], $offOne['marked']]);

		$partly = $this->createMock(ConsistencyCheckService::class);
		$partly->method('markVanished')->willReturn(500);
		$partly->method('countVanished')->willReturn(1);

		$this->assertSame('Not every vanished file could be marked in one go. Run it again for the rest.', $this->buildController(consistencyCheck: $partly)->deleteVanished()->getData()['message']);
	}

	/**
	 * One vanished file's pad deleted: its row marked, the rest left. A file
	 * no longer vanished - marked meanwhile, back - changes nothing, and the
	 * answer says so.
	 */
	public function testDeleteVanishedTakesOneFileWhenNamed(): void {
		$cases = [
			[true, 1, 'The pad of this vanished file is marked for deletion. It is deleted within minutes, or at once with "Check pending pads".'],
			[false, 0, 'This file is no longer vanished; nothing was changed.'],
		];
		foreach ($cases as [$stillVanished, $marked, $message]) {
			$consistency = $this->createMock(ConsistencyCheckService::class);
			$consistency->expects($this->never())->method('markVanished');
			$consistency->expects($this->once())->method('markVanishedFile')->with(42)->willReturn($stillVanished);
			$consistency->method('countVanished')->willReturn(3);

			$data = $this->buildController(request: $this->request(['fileId' => '42']), consistencyCheck: $consistency)->deleteVanished()->getData();

			$this->assertSame([$message, $marked, 3], [$data['message'], $data['marked'], $data['vanished_file_count']]);
		}
	}

	/**
	 * One vanished file's row removed, its pad left in Etherpad; a file no
	 * longer vanished is left as it is. A request naming no file, or no
	 * number, is refused.
	 */
	public function testForgetVanishedRemovesOneRowAndLeavesThePad(): void {
		$consistency = $this->createMock(ConsistencyCheckService::class);
		$consistency->method('forgetVanished')->willReturnMap([[42, 'g.abc$pad'], [43, null]]);
		$consistency->method('countVanished')->willReturn(2);

		$forgotten = $this->buildController(request: $this->request(['fileId' => '42']), consistencyCheck: $consistency)->forgetVanished()->getData();
		$left = $this->buildController(request: $this->request(['fileId' => '43']), consistencyCheck: $consistency)->forgetVanished()->getData();

		$this->assertSame(['ok' => true, 'message' => 'The pad stays in Etherpad, and the app no longer looks after it.', 'forgotten' => true, 'vanished_file_count' => 2], $forgotten);
		$this->assertSame(['This file is no longer vanished; nothing was changed.', false], [$left['message'], $left['forgotten']]);
		foreach ([[], ['fileId' => '7x'], ['fileId' => '0']] as $payload) {
			$response = $this->buildController(request: $this->request($payload), consistencyCheck: $consistency)->forgetVanished();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), json_encode($payload));
			$this->assertSame('Invalid file ID.', $response->getData()['message']);
		}
	}

	/**
	 * Only a request without `fileId` takes every vanished file: an empty
	 * one is a client that meant one file and sent no number, refused
	 * before anything is marked.
	 */
	public function testDeleteVanishedRefusesAnEmptyFileId(): void {
		$consistency = $this->createMock(ConsistencyCheckService::class);
		$consistency->expects($this->never())->method('markVanished');
		$consistency->expects($this->never())->method('markVanishedFile');

		$response = $this->buildController(request: $this->request(['fileId' => '']), consistencyCheck: $consistency)->deleteVanished();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file ID.', $response->getData()['message']);
	}

	/** Only an admin deletes pads here. */
	public function testDeleteVanishedRefusesNonAdmins(): void {
		$consistency = $this->createMock(ConsistencyCheckService::class);
		$consistency->expects($this->never())->method('markVanished');

		$consistency->expects($this->never())->method('forgetVanished');

		$response = $this->buildController(groupManager: $this->adminGroup(false), consistencyCheck: $consistency)->deleteVanished();
		$forget = $this->buildController(request: $this->request(['fileId' => '42']), groupManager: $this->adminGroup(false), consistencyCheck: $consistency)->forgetVanished();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $forget->getStatus());
	}

	public function testListsPadTemplates(): void {
		$templates = $this->createMock(PadTemplateAdminService::class);
		$templates->method('list')->willReturn([['name' => 'Meeting notes.pad', 'size' => 10, 'modified' => 1]]);

		$response = $this->buildController(padTemplateAdmin: $templates)->listPadTemplates();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Meeting notes.pad', $response->getData()['templates'][0]['name']);
	}

	public function testUploadsAPadTemplate(): void {
		$request = $this->request(['name' => 'Meeting notes.pad', 'content' => "---\npad_id: \"nc-abc\"\n---\n"]);
		$templates = $this->createMock(PadTemplateAdminService::class);
		$templates->expects($this->once())
			->method('add')
			->with('Meeting notes.pad', "---\npad_id: \"nc-abc\"\n---\n")
			->willReturn(['name' => 'Meeting notes.pad', 'size' => 10, 'modified' => 1]);

		$response = $this->buildController($request, padTemplateAdmin: $templates)->uploadPadTemplate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$response->getData()['ok']);
	}

	/** The message has to name the field so the page can mark it. */
	public function testReportsAnInvalidTemplateAsAValidationError(): void {
		$templates = $this->createMock(PadTemplateAdminService::class);
		$templates->method('add')->willThrowException(
			new AdminValidationException('template', 'A template must be a .pad file.')
		);

		$response = $this->buildController($this->request(['name' => 'notes.txt', 'content' => 'x']), padTemplateAdmin: $templates)
			->uploadPadTemplate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('A template must be a .pad file.', $response->getData()['message']);
	}

	public function testDeletesAPadTemplate(): void {
		$templates = $this->createMock(PadTemplateAdminService::class);
		$templates->expects($this->once())->method('delete')->with('gone.pad');

		$response = $this->buildController($this->request(['name' => 'gone.pad']), padTemplateAdmin: $templates)
			->deletePadTemplate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('gone.pad', $response->getData()['name']);
	}

	public function testTemplateEndpointsRefuseNonAdmins(): void {
		$controller = $this->buildController(groupManager: $this->adminGroup(false));

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->listPadTemplates()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->uploadPadTemplate()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->deletePadTemplate()->getStatus());
	}

	private function buildController(
		?IRequest $request = null,
		?IUserSession $userSession = null,
		?IGroupManager $groupManager = null,
		?AdminSettingsValidator $validator = null,
		?AdminSettingsRepository $repository = null,
		?EtherpadHealthCheckService $healthCheck = null,
		?ConsistencyCheckService $consistencyCheck = null,
		?AdminConsistencyCheckResponseBuilder $consistencyResponses = null,
		?PadTemplateAdminService $padTemplateAdmin = null,
		?GoneFileSweep $goneFileSweep = null,
		?FixedClock $clock = null,
		?BindingService $bindings = null,
		?AppConfigService $appConfig = null,
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
			$consistencyCheck ?? $this->createMock(ConsistencyCheckService::class),
			$consistencyResponses ?? new AdminConsistencyCheckResponseBuilder($l10n),
			new AdminControllerErrorMapper($l10n, $logger),
			new CookieDomainPolicy(),
			new CookieDomainMessages($l10n),
			$this->urlGenerator(),
			$padTemplateAdmin ?? $this->createMock(PadTemplateAdminService::class),
			$goneFileSweep ?? $this->createMock(GoneFileSweep::class),
			$clock ?? new FixedClock(),
			$bindings ?? $this->createMock(BindingService::class),
			$appConfig ?? $this->deletingOn(),
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
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $payload[$key] ?? $default);
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

	private function deletingOn(): AppConfigService {
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('isDeletePadWithFileEnabled')->willReturn(true);
		return $appConfig;
	}
}
