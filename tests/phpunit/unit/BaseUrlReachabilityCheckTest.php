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
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class BaseUrlReachabilityCheckTest extends TestCase {
	public function testReachableBaseUrlPasses(): void {
		$item = $this->buildCheck($this->respondingWith(200))->check('https://pad.example.test', 'http://localhost:9001');

		$this->assertSame(HealthCheckItem::STATUS_OK, $item->status);
		$this->assertSame('https://pad.example.test', $item->detail);
	}

	/**
	 * Any answer proves the name resolves and something listens, which is all
	 * this row claims. Etherpad may well require auth at the root.
	 */
	public function testAuthOrNotFoundStillCountsAsReachable(): void {
		foreach ([401, 403, 404] as $status) {
			$item = $this->buildCheck($this->respondingWith($status))->check('https://pad.example.test', 'http://localhost:9001');
			$this->assertSame(HealthCheckItem::STATUS_OK, $item->status, (string)$status);
		}
	}

	/**
	 * Never an error: Nextcloud may be unable to reach the public URL by
	 * design, so this must not cry wolf on a correct instance.
	 */
	public function testUnreachableBaseUrlWarnsAndNamesTheUrl(): void {
		$client = $this->createMock(IClient::class);
		$client->method('request')->willThrowException(new \RuntimeException('Could not resolve host: pad.example.spacer'));

		$item = $this->buildCheck($client)->check('https://pad.example.spacer', 'http://localhost:9001');

		$this->assertSame(HealthCheckItem::STATUS_WARNING, $item->status);
		$this->assertStringContainsString('pad.example.spacer', $item->detail);
		$this->assertStringContainsString('Could not resolve host', $item->detail);
	}

	public function testServerErrorWarns(): void {
		$item = $this->buildCheck($this->respondingWith(502))->check('https://pad.example.test', 'http://localhost:9001');

		$this->assertSame(HealthCheckItem::STATUS_WARNING, $item->status);
		$this->assertStringContainsString('502', $item->detail);
	}

	public function testIdenticalApiUrlIsSkippedRatherThanRequestedTwice(): void {
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->never())->method('newClient');

		$item = (new BaseUrlReachabilityCheck($clientService, $this->buildL10n()))
			->check('https://pad.example.test/', 'https://pad.example.test');

		$this->assertSame(HealthCheckItem::STATUS_SKIPPED, $item->status);
	}

	public function testMissingBaseUrlIsSkipped(): void {
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->never())->method('newClient');

		$item = (new BaseUrlReachabilityCheck($clientService, $this->buildL10n()))->check('  ', 'http://localhost:9001');

		$this->assertSame(HealthCheckItem::STATUS_SKIPPED, $item->status);
	}

	private function respondingWith(int $status): IClient {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturn($response);
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
