<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class EtherpadClientTest extends TestCase {
	public function testBuildPadUrlUsesConfiguredHost(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'etherpad_host') {
					return 'https://pad.example.test';
				}
				return $default;
			}
		);

		$client = $this->client($config);
		$this->assertSame(
			'https://pad.example.test/p/g.group%24pad-name',
			$client->buildPadUrl('g.group$pad-name')
		);
	}

	public function testGetConfiguredOriginNormalizesScheme(): void {
		$client = $this->client($this->configWithHost('HTTPS://Pad.Example.Test/'));
		$this->assertSame('https://pad.example.test', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginOmitsDefaultPorts(): void {
		$client = $this->client($this->configWithHost('https://pad.example.test:443'));
		$this->assertSame('https://pad.example.test', $client->getConfiguredOrigin());

		$client = $this->client($this->configWithHost('http://pad.example.test:80'));
		$this->assertSame('http://pad.example.test', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginKeepsNonDefaultPort(): void {
		$client = $this->client($this->configWithHost('https://pad.example.test:9001'));
		$this->assertSame('https://pad.example.test:9001', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginAllowsHttp(): void {
		// Unlike `parsePublicPadUrl`, the configured-origin accessor must not
		// enforce https — admins may legitimately run Etherpad on http behind
		// a private network.
		$client = $this->client($this->configWithHost('http://pad.internal.lan'));
		$this->assertSame('http://pad.internal.lan', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginReturnsEmptyWhenUnconfigured(): void {
		$client = $this->client($this->configWithHost(''));
		$this->assertSame('', $client->getConfiguredOrigin());
	}

	public function testApiCallReturnsDataOnSuccess(): void {
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"groupID":"g.abc123"}}')
		);
		$this->assertSame('g.abc123', $client->createGroup());
	}

	public function testApiCallSendsApiKeyFromSettingsRepository(): void {
		// Regression guard for #105: the apikey must come from
		// AdminSettingsRepository::getApiKey() (the decrypting IAppConfig path),
		// not from IConfig. Capture the outgoing request and assert it.
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"groupID":"g.abc123"}}'),
			$captured,
		);

		$client->createGroup();

		$this->assertNotNull($captured);
		// createGroup is a POST: apikey travels in the form-urlencoded body.
		$this->assertStringContainsString('apikey=stored-key', (string)$captured['options']['body']);
	}

	/**
	 * The session listing is a POST like every other authenticated call. As
	 * a GET the apikey would travel in the URL and from there into proxy and
	 * access logs.
	 */
	public function testListSessionsOfAuthorSendsTheKeyInTheBodyNotTheUrl(): void {
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"s.one":{"groupID":"g.aaa","authorID":"a.x","validUntil":99}}}'),
			$captured,
		);

		$sessions = $client->listSessionsOfAuthor('a.x');

		$this->assertSame('POST', $captured['method']);
		$this->assertStringNotContainsString('apikey', $captured['url']);
		$this->assertArrayNotHasKey('query', $captured['options']);
		$this->assertStringContainsString('apikey=stored-key', (string)$captured['options']['body']);
		$this->assertStringContainsString('authorID=a.x', (string)$captured['options']['body']);
		$this->assertSame(['s.one' => ['groupID' => 'g.aaa', 'validUntil' => 99]], $sessions);
	}

	public function testListSessionsOfAuthorSkipsEntriesWithoutAGroupOrExpiry(): void {
		$client = $this->clientWithResponse($this->response(200, json_encode([
			'code' => 0,
			'data' => [
				's.ok' => ['groupID' => 'g.aaa', 'validUntil' => 42],
				's.nogroup' => ['groupID' => '', 'validUntil' => 42],
				's.noexpiry' => ['groupID' => 'g.bbb', 'validUntil' => 0],
				's.notanarray' => 'nonsense',
			],
		])));

		$this->assertSame(['s.ok' => ['groupID' => 'g.aaa', 'validUntil' => 42]], $client->listSessionsOfAuthor('a.x'));
	}

	public function testListPadsReadsAValidPadList(): void {
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":0,"data":{"padIDs":["g.aaa$one","g.aaa$two"]}}')
		);
		$this->assertSame(['g.aaa$one', 'g.aaa$two'], $client->listPads('g.aaa'));
	}

	/** Measured against Etherpad: an empty group answers with an empty list. */
	public function testListPadsReadsAnEmptyGroup(): void {
		$client = $this->clientWithResponse($this->response(200, '{"code":0,"data":{"padIDs":[]}}'));
		$this->assertSame([], $client->listPads('g.aaa'));
	}

	/**
	 * Not `[]`, because of what the caller does with `[]`: it reads it as
	 * "this group holds nothing, so removing it takes nothing with it". An
	 * answer that does not contain a list is not an empty group, and
	 * shrugging it off would be a licence to delete. Saying so drops the
	 * caller back to deleting the pad alone.
	 *
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('provideUnreadablePadLists')]
	public function testListPadsRefusesAnAnswerItCannotRead(string $payload): void {
		$client = $this->clientWithResponse($this->response(200, $payload));
		$this->expectException(EtherpadClientException::class);
		$client->listPads('g.aaa');
	}

	/** @return array<string,array{string}> */
	public static function provideUnreadablePadLists(): array {
		return [
			'no padIDs field' => ['{"code":0,"data":{}}'],
			'null data' => ['{"code":0,"data":null}'],
			'padIDs is a string' => ['{"code":0,"data":{"padIDs":"g.aaa$one"}}'],
			'padIDs is an object' => ['{"code":0,"data":{"padIDs":{"a":"g.aaa$one"}}}'],
			'a member is not a string' => ['{"code":0,"data":{"padIDs":["g.aaa$one",42]}}'],
			'a member is null' => ['{"code":0,"data":{"padIDs":[null]}}'],
		];
	}

	public function testApiCallThrowsOnNonZeroApiCode(): void {
		$client = $this->clientWithResponse(
			$this->response(200, '{"code":1,"message":"groupID does not exist"}')
		);
		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Etherpad API error (createGroup): groupID does not exist');
		$client->createGroup();
	}

	public function testApiCallThrowsOnInvalidJson(): void {
		$client = $this->clientWithResponse($this->response(200, '<html>nope</html>'));
		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Invalid JSON response from Etherpad API.');
		$client->createGroup();
	}

	public function testApiCallSurfacesHttpErrorStatusAsCause(): void {
		// e.g. a wrong apikey makes Etherpad answer 401 — the failure mode the
		// #105 regression produced. apiCall() wraps every sendRequest failure
		// as "request failed: <method>" and preserves the HTTP error as the
		// cause (exactly what the server log showed during #105).
		$client = $this->clientWithResponse($this->response(401, 'Unauthorized'));

		try {
			$client->createGroup();
			$this->fail('Expected EtherpadClientException.');
		} catch (EtherpadClientException $e) {
			$this->assertSame('Etherpad API request failed: createGroup', $e->getMessage());
			$this->assertInstanceOf(EtherpadClientException::class, $e->getPrevious());
			$this->assertStringContainsString('Etherpad API HTTP error (401)', $e->getPrevious()->getMessage());
		}
	}

	public function testDetectApiVersionUsesGetAgainstApiEndpointWithoutBody(): void {
		$captured = null;
		$client = $this->clientWithResponse(
			$this->response(200, '{"currentVersion":"1.3.0"}'),
			$captured,
		);

		$this->assertSame('1.3.0', $client->detectApiVersion('https://pad.example.test/'));

		$this->assertNotNull($captured);
		$this->assertSame('GET', $captured['method']);
		$this->assertSame('https://pad.example.test/api', $captured['url']);
		// Detection is a plain GET: no request body, redirects disabled.
		$this->assertArrayNotHasKey('body', $captured['options']);
		$this->assertSame(['max' => 0], $captured['options']['allow_redirects']);
		$this->assertSame(['allow_local_address' => true], $captured['options']['nextcloud']);
	}

	public function testApiCallWrapsTransportFailure(): void {
		// request() throws and the throwable carries no response, so
		// getResponseFromThrowable() rethrows -> wrapped as a request failure.
		$http = $this->createMock(IClient::class);
		$http->method('request')->willThrowException(new \RuntimeException('connection refused'));
		$http->method('getResponseFromThrowable')->willThrowException(new \RuntimeException('connection refused'));
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);

		$client = new EtherpadClient($this->configForApi(), $this->repositoryWithKey('stored-key'), $service);

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Etherpad API request failed: createGroup');
		$client->createGroup();
	}

	/**
	 * Construct the client for the URL-helper tests with a never-called HTTP
	 * client (these tests do not make requests).
	 */
	private function client(IConfig $config): EtherpadClient {
		return new EtherpadClient(
			$config,
			$this->createMock(AdminSettingsRepository::class),
			$this->createMock(IClientService::class),
		);
	}

	/**
	 * Build a client whose single HTTP call returns the given response.
	 * Optionally captures the outgoing [method, url, options].
	 *
	 * @param array{method:string,url:string,options:array<string,mixed>}|null $captured
	 */
	private function clientWithResponse(IResponse $response, ?array &$captured = null): EtherpadClient {
		$http = $this->createMock(IClient::class);
		$http->method('request')->willReturnCallback(
			static function (string $method, string $url, array $options) use ($response, &$captured): IResponse {
				$captured = ['method' => $method, 'url' => $url, 'options' => $options];
				return $response;
			}
		);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);

		return new EtherpadClient($this->configForApi(), $this->repositoryWithKey('stored-key'), $service);
	}

	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	private function repositoryWithKey(string $key): AdminSettingsRepository {
		$repository = $this->createMock(AdminSettingsRepository::class);
		$repository->method('getApiKey')->willReturn($key);
		return $repository;
	}

	private function configForApi(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName !== 'etherpad_nextcloud') {
					return $default;
				}
				return match ($key) {
					'etherpad_host', 'etherpad_api_host' => 'https://pad.example.test',
					'etherpad_api_version' => '1.2.15',
					default => $default,
				};
			}
		);
		return $config;
	}

	private function configWithHost(string $host): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use ($host): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'etherpad_host') {
					return $host;
				}
				return $default;
			}
		);
		return $config;
	}
}
