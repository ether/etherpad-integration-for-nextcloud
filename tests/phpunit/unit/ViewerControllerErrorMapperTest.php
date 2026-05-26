<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\ViewerControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ViewerControllerErrorMapperTest extends TestCase {
	public function testRunReturnsRedirectResponseFromSuccessHandler(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): string => '/target',
			static fn (string $url): RedirectResponse => new RedirectResponse($url),
		);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/target', $response->getRedirectURL());
	}

	public function testRunMapsUnauthorizedToNoviewerTemplate(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): never => throw new UnauthorizedRequestException(),
			static fn ($value) => $this->fail('unreachable'),
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Authentication required.', $response->getParams()['error']);
	}

	public function testRunMapsControllerBadRequestWithExceptionMessage(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): never => throw new ControllerBadRequestException('Invalid file path.'),
			static fn ($value) => $this->fail('unreachable'),
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('Invalid file path.', $response->getParams()['error']);
	}

	public function testRunMapsNotFoundException(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): never => throw new NotFoundException(),
			static fn ($value) => $this->fail('unreachable'),
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('Cannot open selected .pad file.', $response->getParams()['error']);
	}

	public function testRunUsesEndpointSpecificNotFoundMessageWhenProvided(): void {
		$response = $this->buildMapper()->runForTemplate(
			static fn (): never => throw new NotFoundException(),
			static fn ($value) => $this->fail('unreachable'),
			notFoundMessage: 'Cannot resolve file path for file ID.',
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('Cannot resolve file path for file ID.', $response->getParams()['error']);
	}

	public function testRunLogsUnhandledExceptionAndReturnsGenericMessage(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('Unhandled viewer controller error', $this->callback(static fn (array $context): bool => ($context['exception'] ?? null) instanceof \RuntimeException));

		$response = $this->buildMapper($logger)->runForTemplate(
			static fn (): never => throw new \RuntimeException('Internal pipe burst'),
			static fn ($value) => $this->fail('unreachable'),
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('Could not open pad.', $response->getParams()['error']);
	}

	private function buildMapper(?LoggerInterface $logger = null): ViewerControllerErrorMapper {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new ViewerControllerErrorMapper(
			$l10n,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
