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
use PHPUnit\Framework\MockObject\MockObject;
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
	 * One vanished file's pad, on the admin's word: its row marked, the
	 * answer carrying the list as it is now. A file no longer vanished
	 * changes nothing and says so; with deleting off nothing is marked.
	 */
	public function testDeleteVanishedTakesTheOneFileNamed(): void {
		$cases = [
			'marked' => [true, true, 1, 'The pad of this vanished file is marked for deletion. It is deleted within minutes, or at once with "Check pending pads".'],
			'no longer vanished' => [true, false, 0, 'This file is no longer vanished; nothing was changed.'],
			'deleting off' => [false, null, 0, 'Deleting pads is switched off, so no pad was marked for deletion.'],
		];
		foreach ($cases as $case => [$deleting, $stillVanished, $marked, $message]) {
			$consistency = $this->vanishedList(2);
			$consistency->expects($stillVanished === null ? $this->never() : $this->once())->method('markVanishedFile')->with(42)->willReturn((bool)$stillVanished);
			$appConfig = $this->createMock(AppConfigService::class);
			$appConfig->method('isDeletePadWithFileEnabled')->willReturn($deleting);

			$data = $this->buildController(request: $this->request(['fileId' => '42']), consistencyCheck: $consistency, appConfig: $appConfig)->deleteVanished()->getData();

			$this->assertSame([$message, $marked, 2, [['file_id' => 7]]], [$data['message'], $data['marked'], $data['vanished_file_count'], $data['samples']['vanished_files']], $case);
		}
	}

	/**
	 * All of them only on a route of their own, and only a list of as many
	 * as the admin confirmed: one that has changed since is not taken, and
	 * the answer carries it to be confirmed again.
	 */
	public function testDeleteAllVanishedTakesOnlyTheCountConfirmed(): void {
		$consistency = $this->vanishedList(0);
		$consistency->expects($this->once())->method('markVanished')->with($this->callback(static fn (RunBudget $budget): bool => !$budget->exhausted()), 30)->willReturn(30);

		$data = $this->buildController(request: $this->request(['expected' => '30']), consistencyCheck: $consistency)->deleteAllVanished()->getData();

		$this->assertSame(['The pads of the vanished files are marked for deletion. They are deleted within minutes, or at once with "Check pending pads".', 30, 0], [$data['message'], $data['marked'], $data['vanished_file_count']]);

		$changed = $this->vanishedList(30000);
		$changed->method('markVanished')->willReturn(null);

		$data = $this->buildController(request: $this->request(['expected' => '30']), consistencyCheck: $changed)->deleteAllVanished()->getData();

		$this->assertSame(['The list of vanished files has changed since it was shown, so no pad was marked for deletion. Check it again.', 0, 30000], [$data['message'], $data['marked'], $data['vanished_file_count']]);
	}

	/**
	 * What deleting them all came to, said as it is: nothing left to mark
	 * only when none is left; the rest for another run, also when the
	 * budget ran out before the first mark; deleting switched off. A
	 * failure says that some may be marked already: a chunk that fails
	 * leaves the marks before it standing.
	 */
	public function testDeleteAllVanishedSaysWhatItDidNot(): void {
		$cases = [
			'none left' => [true, 0, 0, 'No vanished file was left to mark for deletion.'],
			'more than a run' => [true, 500, 7, 'Not every vanished file could be marked in one go. Run it again for the rest.'],
			'budget spent before the first mark' => [true, 0, 1, 'Not every vanished file could be marked in one go. Run it again for the rest.'],
			'deleting off' => [false, 0, 12, 'Deleting pads is switched off, so no pad was marked for deletion.'],
		];
		foreach ($cases as $case => [$deleting, $marked, $left, $message]) {
			$consistency = $this->vanishedList($left);
			$consistency->expects($deleting ? $this->once() : $this->never())->method('markVanished')->willReturn($marked);
			$appConfig = $this->createMock(AppConfigService::class);
			$appConfig->method('isDeletePadWithFileEnabled')->willReturn($deleting);

			$data = $this->buildController(request: $this->request(['expected' => '12']), consistencyCheck: $consistency, appConfig: $appConfig)->deleteAllVanished()->getData();

			$this->assertSame([$message, $marked], [$data['message'], $data['marked']], $case);
		}

		$failing = $this->createMock(ConsistencyCheckService::class);
		$failing->method('markVanished')->willThrowException(new \RuntimeException('database went away'));
		$response = $this->buildController(request: $this->request(['expected' => '12']), consistencyCheck: $failing)->deleteAllVanished();
		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, 'Could not delete the pads of all vanished files. Some may be marked for deletion already: check the list again.'], [$response->getStatus(), $response->getData()['message']]);
	}

	/**
	 * A public pad forgotten, its row gone and the pad left in Etherpad; a
	 * protected one is refused, and a file no longer vanished is left.
	 */
	public function testForgetVanishedSaysWhatCameOfIt(): void {
		$cases = [
			ConsistencyCheckService::FORGOTTEN => [true, 'The pad stays in Etherpad, and the app no longer looks after it.'],
			ConsistencyCheckService::PROTECTED_PAD => [false, 'Only a public pad can be forgotten. Delete a protected one instead.'],
			ConsistencyCheckService::NOT_VANISHED => [false, 'This file is no longer vanished; nothing was changed.'],
		];
		foreach ($cases as $outcome => [$forgotten, $message]) {
			$consistency = $this->vanishedList(1);
			$consistency->method('forgetVanished')->with(42)->willReturn($outcome);

			$data = $this->buildController(request: $this->request(['fileId' => '42']), consistencyCheck: $consistency)->forgetVanished()->getData();

			$this->assertSame([$message, $forgotten, 1], [$data['message'], $data['forgotten'], $data['vanished_file_count']], $outcome);
		}
	}

	/**
	 * Each action names what it takes, or is refused before anything is
	 * marked: none sent, or one that is no positive whole number - which
	 * those are is PositiveIntParam's, tested there.
	 */
	public function testTheActionsRefuseWhatNamesNothing(): void {
		$consistency = $this->createMock(ConsistencyCheckService::class);
		$consistency->expects($this->never())->method('markVanished');
		$consistency->expects($this->never())->method('markVanishedFile');
		$consistency->expects($this->never())->method('forgetVanished');

		$refusals = [
			'delete one, none sent' => ['deleteVanished', [], 'Invalid file ID.'],
			'delete one, no number' => ['deleteVanished', ['fileId' => '7x'], 'Invalid file ID.'],
			'forget, none sent' => ['forgetVanished', [], 'Invalid file ID.'],
			'delete all, no count' => ['deleteAllVanished', [], 'Invalid count.'],
		];
		foreach ($refusals as $case => [$action, $payload, $message]) {
			$response = $this->buildController(request: $this->request($payload), consistencyCheck: $consistency)->$action();
			$this->assertSame([Http::STATUS_BAD_REQUEST, $message], [$response->getStatus(), $response->getData()['message']], $case);
		}
	}

	/** Only an admin deletes or forgets pads here. */
	public function testTheActionsRefuseNonAdmins(): void {
		$consistency = $this->createMock(ConsistencyCheckService::class);
		$consistency->expects($this->never())->method('markVanished');
		$consistency->expects($this->never())->method('markVanishedFile');
		$consistency->expects($this->never())->method('forgetVanished');
		$request = $this->request(['fileId' => '42', 'expected' => '3']);

		foreach (['deleteVanished', 'deleteAllVanished', 'forgetVanished'] as $action) {
			$response = $this->buildController(request: $request, groupManager: $this->adminGroup(false), consistencyCheck: $consistency)->$action();
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $action);
		}
	}

	/** A consistency check answering with $left vanished files, one of them listed. */
	private function vanishedList(int $left): ConsistencyCheckService&MockObject {
		$consistency = $this->createMock(ConsistencyCheckService::class);
		$consistency->method('run')->willReturn(['vanished_file_count' => $left, 'samples' => ['vanished_files' => [['file_id' => 7]]]]);
		return $consistency;
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
