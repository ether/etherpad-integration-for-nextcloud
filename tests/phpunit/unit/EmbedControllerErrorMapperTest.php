<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OC\Security\CSRF\CsrfToken;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\EtherpadNextcloud\Controller\EmbedControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\EmbedResponseBuilder;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmbedControllerErrorMapperTest extends TestCase {
	public function testRunReturnsSuccessTemplateWhenActionSucceeds(): void {
		$mapper = $this->buildMapper();

		$response = $mapper->runForTemplate(
			static fn (): string => 'payload',
			static fn (string $value): TemplateResponse => new TemplateResponse('etherpad_nextcloud', 'embed', ['value' => $value], 'blank'),
			errorTitle: 'Unable to open pad',
		);

		$this->assertSame('embed', $response->getTemplateName());
		$this->assertSame('payload', $response->getParams()['value']);
	}

	public function testRunMapsUnauthorizedToNoviewer(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): never => throw new UnauthorizedRequestException(),
			static fn ($value) => $this->fail('success handler must not run on error'),
			errorTitle: 'Unable to open pad',
		);

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Authentication required.', $response->getParams()['error']);
		$this->assertSame('Unable to open pad', $response->getParams()['title']);
	}

	public function testRunMapsControllerBadRequestUsingException(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): never => throw new ControllerBadRequestException('Invalid file ID.'),
			static fn ($value) => $this->fail('unreachable'),
			errorTitle: 'Unable to open pad',
		);

		$this->assertSame('Invalid file ID.', $response->getParams()['error']);
	}

	public function testRunMapsNotFoundExceptionToNoviewer(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): never => throw new NotFoundException(),
			static fn ($value) => $this->fail('unreachable'),
			errorTitle: 'Unable to open pad',
		);

		$this->assertSame('Cannot open selected .pad file.', $response->getParams()['error']);
	}

	public function testRunMapsNotAPadFile(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): never => throw new NotAPadFileException('Selected file is not a .pad file.'),
			static fn ($value) => $this->fail('unreachable'),
			errorTitle: 'Unable to open pad',
		);

		$this->assertSame('Selected file is not a .pad file.', $response->getParams()['error']);
	}

	public function testRunMapsParentFolderNotWritable(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): never => throw new PadParentFolderNotWritableException(),
			static fn ($value) => $this->fail('unreachable'),
			errorTitle: 'Unable to create pad',
		);

		$this->assertSame('Selected parent folder is not writable.', $response->getParams()['error']);
		$this->assertSame('Unable to create pad', $response->getParams()['title']);
	}

	public function testRunLogsUnhandledExceptionAndReturnsGenericMessage(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('Unhandled embed controller error', $this->callback(static fn (array $context): bool => ($context['exception'] ?? null) instanceof \RuntimeException));

		$response = $this->buildMapper($logger)->runForTemplate(
			static fn (): never => throw new \RuntimeException('Internal pipe burst'),
			static fn ($value) => $this->fail('unreachable'),
			errorTitle: 'Unable to open pad',
		);

		$this->assertSame('Unable to open pad.', $response->getParams()['error']);
	}

	private function buildMapper(?LoggerInterface $logger = null): EmbedControllerErrorMapper {
		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->method('getTrustedEmbedOrigins')->willReturn([]);
		$responseBuilder = new EmbedResponseBuilder(
			new CsrfTokenManager(new CsrfToken('csrf-token-value')),
			$appConfigService,
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new EmbedControllerErrorMapper(
			$responseBuilder,
			$l10n,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
