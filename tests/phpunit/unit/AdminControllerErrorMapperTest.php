<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\AdminControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\AdminDebugModeRequiredException;
use OCA\EtherpadNextcloud\Exception\AdminHealthCheckException;
use OCA\EtherpadNextcloud\Exception\AdminPermissionRequiredException;
use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Exception\UnsupportedTestFaultException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminControllerErrorMapperTest extends TestCase {
	public function testMapsUnauthorized(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new UnauthorizedRequestException(),
			static fn(array $data): DataResponse => new DataResponse($data),
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse((bool)$response->getData()['ok']);
	}

	public function testMapsAdminPermissionRequired(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new AdminPermissionRequiredException(),
			static fn(array $data): DataResponse => new DataResponse($data),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Admin permissions required.', $response->getData()['message']);
	}

	public function testMapsDebugModeRequired(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new AdminDebugModeRequiredException(),
			static fn(array $data): DataResponse => new DataResponse($data),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Test faults are available only when Nextcloud debug mode is enabled.', $response->getData()['message']);
	}

	public function testMapsValidationWithField(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new AdminValidationException('etherpad_host', 'Invalid host.'),
			static fn(array $data): DataResponse => new DataResponse($data),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('etherpad_host', $response->getData()['field']);
	}

	public function testMapsUnsupportedTestFaultWithSupportedValues(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new UnsupportedTestFaultException(['after_file_delete', 'after_pad_delete']),
			static fn(array $data): DataResponse => new DataResponse($data),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Unsupported test fault.', $response->getData()['message']);
		$this->assertSame(['after_file_delete', 'after_pad_delete'], $response->getData()['supported_faults']);
	}

	public function testMapsHealthCheckExceptionToBadGateway(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new AdminHealthCheckException(
				'Etherpad connection test failed: bad key',
				0,
				null,
				'etherpad_api_key',
			),
			static fn(array $data): DataResponse => new DataResponse($data),
		);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('Etherpad connection test failed: bad key', $response->getData()['message']);
		// Same shape validation errors use, so the page marks the input.
		$this->assertSame('etherpad_api_key', $response->getData()['field']);
	}

	public function testHealthCheckExceptionWithoutAFieldOmitsIt(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new AdminHealthCheckException('Etherpad connection test failed: odd'),
			static fn(array $data): DataResponse => new DataResponse($data),
		);

		$this->assertArrayNotHasKey('field', $response->getData());
	}

	public function testLogsGenericFailures(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Admin failed');

		$response = $this->buildMapper($logger)->run(
			static fn(): array => throw new \RuntimeException('boom'),
			static fn(array $data): DataResponse => new DataResponse($data),
			['generic' => 'Failed.', 'log_message' => 'Admin failed'],
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed.', $response->getData()['message']);
	}

	private function buildMapper(?LoggerInterface $logger = null): AdminControllerErrorMapper {
		return new AdminControllerErrorMapper($this->buildL10n(), $logger ?? $this->createMock(LoggerInterface::class));
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
