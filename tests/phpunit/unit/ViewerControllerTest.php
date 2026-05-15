<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\ViewerController;
use OCA\EtherpadNextcloud\Controller\ViewerControllerErrorMapper;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ViewerControllerTest extends TestCase {
	public function testShowPadResolvesFileByPathViaUserNodeResolver(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Folder/Test.pad')
			->willReturn($file);

		$controller = $this->buildController($userNodeResolver);

		$response = $controller->showPad('/Folder/Test.pad');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/apps/files/files/138?dir=%2FFolder&editing=false&openfile=true', $response->getRedirectURL());
	}

	public function testShowPadReturnsErrorWhenResolverCannotFindPath(): void {
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Missing.pad')
			->willThrowException(new NotFoundException('missing'));

		$controller = $this->buildController($userNodeResolver);

		$response = $controller->showPad('/Missing.pad');

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Cannot open selected .pad file.', $response->getParams()['error']);
	}

	public function testShowPadRedirectsToFilesIndexWhenFileIsEmpty(): void {
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->never())->method('resolveUserFileNodeByPath');

		$controller = $this->buildController($userNodeResolver);

		$response = $controller->showPad('');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/apps/files', $response->getRedirectURL());
	}

	public function testShowPadReturnsErrorWhenPathNormalizationFails(): void {
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->never())->method('resolveUserFileNodeByPath');

		$controller = $this->buildController($userNodeResolver);

		$response = $controller->showPad('/Apps/../secret.pad');

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Invalid file path.', $response->getParams()['error']);
	}

	public function testShowPadReturnsErrorWhenNotLoggedIn(): void {
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$controller = $this->buildController($userNodeResolver, anonymous: true);

		$response = $controller->showPad('/Folder/Test.pad');

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Authentication required.', $response->getParams()['error']);
	}

	public function testShowPadByIdResolvesFileFromUserAbsolutePath(): void {
		$file = $this->createMock(File::class);
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 7)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Folder/Pad.pad');

		$controller = $this->buildController($userNodeResolver);

		$response = $controller->showPadById(7);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/apps/files/files/7?dir=%2FFolder&editing=false&openfile=true', $response->getRedirectURL());
	}

	public function testShowPadByIdReturnsFileIdErrorWhenResolverThrowsNotFound(): void {
		// Regression: showPadById must surface "Cannot resolve file path for file ID."
		// rather than the open-pad default when the file ID cannot be resolved.
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')
			->willThrowException(new NotFoundException());

		$controller = $this->buildController($resolver);

		$response = $controller->showPadById(7);

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Cannot resolve file path for file ID.', $response->getParams()['error']);
	}

	public function testShowPadByIdRejectsInvalidFileId(): void {
		$controller = $this->buildController($this->createMock(UserNodeResolver::class));

		$response = $controller->showPadById('not-numeric');

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Invalid file ID.', $response->getParams()['error']);
	}

	private function buildController(UserNodeResolver $userNodeResolver, bool $anonymous = false): ViewerController {
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '']);
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, [
			'getUser' => $anonymous ? null : $user,
		]);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('files.view.index')->willReturn('/apps/files');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$errorMapper = new ViewerControllerErrorMapper(
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		return new ViewerController(
			'etherpad_nextcloud',
			$request,
			$urlGenerator,
			$userSession,
			$l10n,
			new PathNormalizer(),
			$userNodeResolver,
			$errorMapper,
		);
	}
}
