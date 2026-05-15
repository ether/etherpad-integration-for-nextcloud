<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OC\Security\CSRF\CsrfToken;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\EtherpadNextcloud\Controller\EmbedController;
use OCA\EtherpadNextcloud\Controller\EmbedControllerErrorMapper;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\EmbedResponseBuilder;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmbedControllerTest extends TestCase {
	public function testShowByIdUsesInjectedCsrfTokenAndTrustedOriginsInTemplateData(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Example.pad');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeById')
			->with('alice', 42)
			->willReturn($file);

		$controller = $this->buildController($userNodeResolver);

		$response = $controller->showById(42);
		$params = $response->getParams();

		$this->assertSame('embed', $response->getTemplateName());
		$this->assertSame('blank', $response->getRenderAs());
		$this->assertSame('csrf-token-value', $params['requesttoken']);
		$this->assertSame(['https://portal.example.test'], $params['trusted_embed_origins']);
		$this->assertSame(42, $params['file_id']);
		$this->assertSame(['/open-by-id', '/initialize/__FILE_ID__'], [
			$params['open_by_id_url'],
			$params['initialize_by_id_url_template'],
		]);
	}

	public function testShowByIdReturnsNoviewerOnInvalidFileId(): void {
		$controller = $this->buildController($this->createMock(UserNodeResolver::class));

		$response = $controller->showById('not-a-number');
		$params = $response->getParams();

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Invalid file ID.', $params['error']);
		$this->assertSame('Unable to open pad', $params['title']);
	}

	public function testShowByIdReturnsNoviewerWhenFileIsNotPad(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('NotAPad.txt');
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willReturn($file);

		$controller = $this->buildController($resolver);

		$response = $controller->showById(42);
		$params = $response->getParams();

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Selected file is not a .pad file.', $params['error']);
		$this->assertSame('Unable to open pad', $params['title']);
	}

	public function testShowByIdReturnsNoviewerWhenFileCannotBeResolved(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')
			->willThrowException(new NotFoundException());

		$controller = $this->buildController($resolver);

		$response = $controller->showById(42);
		$params = $response->getParams();

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Cannot open selected .pad file.', $params['error']);
		$this->assertSame('Unable to open pad', $params['title']);
	}

	public function testShowByIdReturnsNoviewerWhenNotLoggedIn(): void {
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$controller = $this->buildController($userNodeResolver, anonymous: true);

		$response = $controller->showById(42);
		$params = $response->getParams();

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Authentication required.', $params['error']);
	}

	public function testCreateByParentRejectsInvalidParentFolderId(): void {
		$controller = $this->buildController($this->createMock(UserNodeResolver::class));

		$response = $controller->createByParent(0);
		$params = $response->getParams();

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Invalid parent folder ID.', $params['error']);
		$this->assertSame('Unable to create pad', $params['title']);
	}

	public function testCreateByParentReturnsNoviewerForNonWritableParent(): void {
		$folder = $this->createMock(\OCP\Files\Folder::class);
		$folder->method('isCreatable')->willReturn(false);

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFolderNodeById')->willReturn($folder);

		$controller = $this->buildController($resolver);

		$response = $controller->createByParent(99);
		$params = $response->getParams();

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Selected parent folder is not writable.', $params['error']);
		$this->assertSame('Unable to create pad', $params['title']);
	}

	public function testCreateByParentReturnsParentFolderErrorWhenResolverThrowsNotFound(): void {
		// Regression: createByParent must surface "Cannot resolve selected parent folder."
		// rather than the open-pad default when the parent folder cannot be resolved.
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFolderNodeById')
			->willThrowException(new NotFoundException());

		$controller = $this->buildController($resolver);

		$response = $controller->createByParent(99);
		$params = $response->getParams();

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Cannot resolve selected parent folder.', $params['error']);
		$this->assertSame('Unable to create pad', $params['title']);
	}

	public function testCreateByParentBuildsEmbedCreateTemplate(): void {
		$folder = $this->createMock(\OCP\Files\Folder::class);
		$folder->method('isCreatable')->willReturn(true);

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFolderNodeById')->with('alice', 99)->willReturn($folder);

		$controller = $this->buildController($resolver);

		$response = $controller->createByParent(99);
		$params = $response->getParams();

		$this->assertSame('embed-create', $response->getTemplateName());
		$this->assertSame(99, $params['parent_folder_id']);
		$this->assertSame('csrf-token-value', $params['requesttoken']);
	}

	private function buildController(UserNodeResolver $userNodeResolver, bool $anonymous = false): EmbedController {
		$request = $this->createMock(IRequest::class);

		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, [
			'getUser' => $anonymous ? null : $user,
		]);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnMap([
			['etherpad_nextcloud.pad.openById', [], '/open-by-id'],
			['etherpad_nextcloud.pad.initializeById', ['fileId' => '__FILE_ID__'], '/initialize/__FILE_ID__'],
			['etherpad_nextcloud.pad.recoverByFileId', ['fileId' => '__FILE_ID__'], '/recover/__FILE_ID__'],
			['etherpad_nextcloud.pad.findOriginalByFileId', ['fileId' => '__FILE_ID__'], '/find-original/__FILE_ID__'],
			['etherpad_nextcloud.pad.createByParent', [], '/create-by-parent'],
		]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->method('getTrustedEmbedOrigins')->willReturn(['https://portal.example.test']);

		$responseBuilder = new EmbedResponseBuilder(
			new CsrfTokenManager(new CsrfToken('csrf-token-value')),
			$appConfigService,
		);
		$errorMapper = new EmbedControllerErrorMapper(
			$responseBuilder,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		return new EmbedController(
			'etherpad_nextcloud',
			$request,
			$userSession,
			$urlGenerator,
			$l10n,
			$responseBuilder,
			$userNodeResolver,
			$errorMapper,
		);
	}
}
