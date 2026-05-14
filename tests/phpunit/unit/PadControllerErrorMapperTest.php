<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PadControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadControllerErrorMapperTest extends TestCase {
	public function testRunMapsInvalidArgumentWithConfiguredMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \InvalidArgumentException('raw'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['invalid_argument' => 'Invalid file path.'],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file path.', $response->getData()['message']);
	}

	public function testRunMapsUnauthorizedRequest(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new UnauthorizedRequestException('Authentication required.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Authentication required.', $response->getData()['message']);
	}

	public function testRunMapsControllerBadRequestWithoutOverridingMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new ControllerBadRequestException('Invalid file ID.'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['invalid_argument' => 'Invalid file path.'],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file ID.', $response->getData()['message']);
	}

	public function testRunMapsInvalidArgumentWithDefaultMessageWhenMessagesAreEmpty(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \InvalidArgumentException(" \t\n"),
			static fn(array $result): DataResponse => new DataResponse($result),
			['invalid_argument' => ''],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid input.', $response->getData()['message']);
	}

	public function testRunMapsNotFoundWithConfiguredMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new NotFoundException('missing'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['not_found' => 'Cannot resolve selected .pad file.'],
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Cannot resolve selected .pad file.', $response->getData()['message']);
	}

	public function testRunMapsLockedExceptionAsRetryable(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new LockedException('locked'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('Pad file is temporarily locked. Please retry.', $response->getData()['message']);
		$this->assertTrue($response->getData()['retryable']);
	}

	public function testRunMapsBindingExceptionWithConfiguredConflictMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new BindingException('duplicate'),
			static fn(array $result): DataResponse => new DataResponse($result),
			[
				'binding_message' => 'A file with this name already exists.',
				'binding_status' => Http::STATUS_CONFLICT,
			],
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('A file with this name already exists.', $response->getData()['message']);
	}

	public function testRunMapsPadFileAlreadyExists(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new PadFileAlreadyExistsException('exists'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('A file with this name already exists.', $response->getData()['message']);
	}

	public function testRunMapsMissingBindingWithRecoveryCode(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new MissingBindingException('no binding'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_binding', $response->getData()['code']);
	}

	public function testRunMapsPadAlreadyHasBinding(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new PadAlreadyHasBindingException('already linked'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('This .pad file is already linked to a pad.', $response->getData()['message']);
	}

	public function testRunMapsParentFolderNotWritable(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new PadParentFolderNotWritableException('not writable'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Selected parent folder is not writable.', $response->getData()['message']);
	}

	public function testRunMapsPadFileFormatException(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new PadFileFormatException('Invalid .pad file.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid .pad file.', $response->getData()['message']);
	}

	public function testRunMapsEtherpadClientException(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new EtherpadClientException('Etherpad rejected request.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Etherpad rejected request.', $response->getData()['message']);
	}

	public function testRunAllowsThrowableOverride(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \RuntimeException('custom'),
			static fn(array $result): DataResponse => new DataResponse($result),
			[
				'map_throwable' => static fn(\Throwable $e): ?DataResponse => $e->getMessage() === 'custom'
					? new DataResponse(['message' => 'Mapped custom failure.'], Http::STATUS_FORBIDDEN)
					: null,
			],
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Mapped custom failure.', $response->getData()['message']);
	}

	public function testRunCallsCustomThrowableLogger(): void {
		$logged = false;

		$response = $this->buildMapper()->run(
			static fn(): array => throw new \RuntimeException('Detailed failure.'),
			static fn(array $result): DataResponse => new DataResponse($result),
			[
				'generic' => 'Pad open failed.',
				'on_throwable' => static function (\Throwable $e) use (&$logged): void {
					$logged = $e->getMessage() === 'Detailed failure.';
				},
			],
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertTrue($logged);
	}

	public function testRunUsesDefaultLoggerForGenericFailures(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'Unhandled pad controller error',
				$this->callback(static fn(array $context): bool => ($context['app'] ?? '') === 'etherpad_nextcloud'
					&& ($context['exception'] ?? null) instanceof \RuntimeException)
			);

		$response = $this->buildMapper($logger)->run(
			static fn(): array => throw new \RuntimeException('Detailed failure.'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['generic' => 'Pad open failed.'],
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Pad open failed.', $response->getData()['message']);
	}

	public function testRunMapsRuntimeExceptionToGenericMessageByDefault(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \RuntimeException('Detailed failure.'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['generic' => 'Pad open failed.'],
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Pad open failed.', $response->getData()['message']);
	}

	private function buildMapper(?LoggerInterface $logger = null): PadControllerErrorMapper {
		return new PadControllerErrorMapper(
			new PadResponseService(
				$this->createMock(IURLGenerator::class),
				$this->createMock(AppConfigService::class),
			),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
