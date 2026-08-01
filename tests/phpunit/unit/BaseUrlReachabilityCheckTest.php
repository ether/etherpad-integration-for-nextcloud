<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BaseUrlReachabilityCheck;
use OCA\EtherpadNextcloud\Service\HealthCheckItem;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class BaseUrlReachabilityCheckTest extends TestCase {
	public function testReachableBaseUrlPasses(): void {
		$item = $this->buildCheck($this->respondingWith(200))->check('https://pad.example.test');

		$this->assertSame(HealthCheckItem::STATUS_OK, $item->status);
		$this->assertSame('https://pad.example.test', $item->detail);
	}

	/**
	 * A host that answers is not automatically a working base URL: point it
	 * at the wrong path and every pad link built from it 404s, while the
	 * summary would still report that everything passed.
	 */
	public function testClientErrorsWarnRatherThanCountAsReachable(): void {
		foreach ([401, 403, 404] as $status) {
			$item = $this->buildCheck($this->respondingWith($status))->check('https://pad.example.test/wrong');
			$this->assertSame(HealthCheckItem::STATUS_WARNING, $item->status, (string)$status);
			$this->assertStringContainsString((string)$status, $item->detail, (string)$status);
		}
	}

	/**
	 * The base URL is admin-supplied and points at a host we do not control,
	 * so this must not become an SSRF probe: no local-address exemption, no
	 * redirect following, a body that is never buffered, and a timeout short
	 * enough not to hold the settings page.
	 */
	public function testRequestsWithoutRedirectsOrLocalAddressAccess(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('request')
			->with(
				'GET',
				'https://pad.example.test',
				$this->callback(static function (array $options): bool {
					return $options['timeout'] === 5
						&& $options['allow_redirects']['max'] === 0
						&& $options['stream'] === true
						&& !isset($options['nextcloud']['allow_local_address']);
				}),
			)
			->willReturn($response);

		$this->buildCheck($client)->check('https://pad.example.test');
	}

	/**
	 * Never an error: Nextcloud may be unable to reach the public URL by
	 * design, so this must not cry wolf on a correct instance.
	 */
	public function testUnreachableBaseUrlWarnsAndNamesTheUrl(): void {
		// A genuine transport failure: no response to recover either.
		$client = $this->createMock(IClient::class);
		$client->method('request')->willThrowException(new \RuntimeException('Could not resolve host: pad.example.spacer'));
		$client->method('getResponseFromThrowable')->willThrowException(new \RuntimeException('no response'));

		$item = $this->buildCheck($client)->check('https://pad.example.spacer');

		$this->assertSame(HealthCheckItem::STATUS_WARNING, $item->status);
		$this->assertStringContainsString('pad.example.spacer', $item->detail);
		$this->assertStringContainsString('Could not resolve host', $item->detail);
	}

	/** The client returning a response directly must work just the same. */
	public function testDirectlyReturnedErrorResponseIsAlsoUnderstood(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(404);
		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturn($response);

		$item = $this->buildCheck($client)->check('https://pad.example.test');

		$this->assertSame(HealthCheckItem::STATUS_WARNING, $item->status);
	}

	/** A redirect proves the host answers; we just do not follow it. */
	public function testRedirectCountsAsReachable(): void {
		$item = $this->buildCheck($this->respondingWith(302))->check('https://pad.example.test');

		$this->assertSame(HealthCheckItem::STATUS_OK, $item->status);
	}

	public function testServerErrorWarns(): void {
		$item = $this->buildCheck($this->respondingWith(502))->check('https://pad.example.test');

		$this->assertSame(HealthCheckItem::STATUS_WARNING, $item->status);
		$this->assertStringContainsString('502', $item->detail);
	}

	/**
	 * Skipping when it matches the API URL would hide the very case the
	 * strict policy exists for: the API call may succeed against a loopback
	 * address that no browser can use.
	 */
	public function testIsCheckedEvenWhenItMatchesTheApiUrl(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('request')
			->willThrowException(new LocalServerException('Host violates local access rules'));

		$item = $this->buildCheck($client)->check('https://localhost:9001');

		$this->assertSame(HealthCheckItem::STATUS_WARNING, $item->status);
	}

	/**
	 * Blocked before anything left the server, so it must not be reported as
	 * a network fault — nor as unusable, since an internal or split-DNS
	 * deployment may still serve it to users.
	 */
	public function testALocalAddressSaysItWasNotContactedRatherThanUnreachable(): void {
		$client = $this->createMock(IClient::class);
		$client->method('request')->willThrowException(new LocalServerException('Host violates local access rules'));

		$item = $this->buildCheck($client)->check('https://192.168.1.5:9001');

		$this->assertSame(HealthCheckItem::STATUS_WARNING, $item->status);
		$this->assertStringContainsString('192.168.1.5', $item->detail);
		$this->assertStringContainsString('was not contacted', $item->detail);
		$this->assertStringNotContainsString('did not answer', $item->detail);
		// Not a verdict on whether users can reach it.
		$this->assertStringContainsString('browsers can reach', $item->detail);
	}


	/**
	 * Nextcloud's HTTP client throws on >= 400 and hands the real response
	 * back through getResponseFromThrowable(), so both shapes have to behave
	 * the same here.
	 */
	private function respondingWith(int $status): IClient {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$client = $this->createMock(IClient::class);
		if ($status >= 400) {
			$client->method('request')->willThrowException(new \RuntimeException('HTTP error (' . $status . ')'));
			$client->method('getResponseFromThrowable')->willReturn($response);
		} else {
			$client->method('request')->willReturn($response);
		}
		return $client;
	}

	private function buildCheck(IClient $client): BaseUrlReachabilityCheck {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		return new BaseUrlReachabilityCheck($clientService, $this->buildL10n());
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
