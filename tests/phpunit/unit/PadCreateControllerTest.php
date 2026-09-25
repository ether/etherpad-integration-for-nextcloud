<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PadControllerErrorMapper;
use OCA\EtherpadNextcloud\Controller\PadCreateController;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadCreateControllerTest extends TestCase {
	public function testCreateReturnsUnauthorizedWhenNoUserSession(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->create('/Test.pad');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('[de] Authentication required.', $response->getData()['message']);
	}

	public function testCreateByParentReturnsUnauthorizedWhenNoUserSession(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->createByParent(123, 'Test');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('[de] Authentication required.', $response->getData()['message']);
	}

	public function testCreateByParentRejectsInvalidParentFolderId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->createByParent(0, 'Test');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('[de] Invalid parentFolderId.', $response->getData()['message']);
	}

	public function testCreateByParentRejectsInvalidAccessMode(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->createByParent(12, 'Test', 'invalid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('[de] Invalid accessMode. Use public or protected.', $response->getData()['message']);
	}

	public function testCreateFromTemplateRejectsInvalidTemplateFileId(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$response = $this->buildController($this->createMock(IRequest::class), $userSession)->createFromTemplate('/New.pad', 0);

		$this->assertSame([Http::STATUS_BAD_REQUEST, '[de] Invalid file ID.'], [$response->getStatus(), $response->getData()['message']]);
	}

	private function buildController(
		IRequest $request,
		IUserSession $userSession,
		?PadCreationService $padCreationService = null,
	): PadCreateController {
		$logger = $this->createMock(LoggerInterface::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$appConfigService = $this->createMock(AppConfigService::class);
		$l10n = $this->createMock(\OCP\IL10N::class);
		// A translation that shows: a refusal reaches the client as it was
		// thrown, so it has to be translated there.
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => '[de] ' . $text);
		$padResponseService = new PadResponseService($urlGenerator, $appConfigService, $l10n);
		return new PadCreateController(
			'etherpad_nextcloud',
			$request,
			$userSession,
			$l10n,
			$padResponseService,
			new PadControllerErrorMapper($padResponseService, $l10n, new \OCA\EtherpadNextcloud\Service\EtherpadFailureLog($this->createMock(\OCP\ICacheFactory::class), $logger), $logger),
			$padCreationService ?? $this->createMock(PadCreationService::class),
		);
	}
}
