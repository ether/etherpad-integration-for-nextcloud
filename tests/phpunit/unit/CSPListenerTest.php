<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\CSPListener;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCP\EventDispatcher\Event;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CSPListenerTest extends TestCase {
	/**
	 * The listener answers every AddContentSecurityPolicyEvent on the server,
	 * so a directive it touches is narrowed for the whole instance. Several
	 * directives are fallbacks for others — `worker-src` falls back through
	 * `child-src` — so naming a single host in one of them can leave that host
	 * as the only permitted source for something else entirely. Only
	 * `frame-src` may ever be populated here, whatever methods the policy
	 * class grows.
	 */
	public function testHandlePopulatesNothingButFrameSrc(): void {
		$event = $this->handled(['etherpad_host' => 'https://pad.jaggob.uber.space']);

		$policy = $event->getPolicies()[0];
		$populated = [];
		foreach ((new \ReflectionObject($policy))->getProperties() as $property) {
			$value = $property->getValue($policy);
			if ($value !== null && $value !== [] && $value !== false) {
				$populated[] = $property->getName();
			}
		}
		sort($populated);

		$this->assertSame(['frameDomains'], $populated);
	}

	public function testLeavesEveryOtherEventAlone(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('getConfiguredOrigin');

		(new CSPListener($client))->handle(new Event());
	}

	/** The allowlist says whom the app fetches from, not what any page may frame. */
	#[DataProvider('allowlists')]
	public function testFramesOnlyTheOwnEtherpadWhateverTheAllowlist(string $allowlist): void {
		$policies = $this->handled([
			'etherpad_host' => 'https://pad.example.test/',
			'allow_external_pads' => 'yes',
			'external_pad_allowlist' => $allowlist,
		])->getPolicies();

		$this->assertCount(1, $policies);
		$this->assertSame(['https://pad.example.test'], $policies[0]->getAllowedFrameDomains());
	}

	/** @return array<string,array{string}> */
	public static function allowlists(): array {
		return [
			'a host on it' => ['other.example.test'],
			'an empty one, which trusts every host for a fetch' => [''],
		];
	}

	/** A source with a path matches that path alone, and the path goes into the header as written. */
	#[DataProvider('hostsWithAPath')]
	public function testFramesTheOriginOfAHostWithAPath(string $host): void {
		$policies = $this->handled(['etherpad_host' => $host])->getPolicies();

		$this->assertSame(['https://pad.example.test'], $policies[0]->getAllowedFrameDomains());
	}

	/** @return array<string,array{string}> */
	public static function hostsWithAPath(): array {
		return [
			'an Etherpad under a path' => ['https://pad.example.test/base'],
			'a path that would end the directive' => ['https://pad.example.test/a; script-src *'],
		];
	}

	public function testAddsNoPolicyWithoutAnEtherpadHost(): void {
		$this->assertSame([], $this->handled(['allow_external_pads' => 'yes', 'external_pad_allowlist' => 'other.example.test'])->getPolicies());
	}

	/** Nothing to frame, and nothing that may go into the header. */
	#[DataProvider('hostsWithNoOrigin')]
	public function testAddsNoPolicyForAHostWithNoOrigin(string $host): void {
		$this->assertSame([], $this->handled(['etherpad_host' => $host])->getPolicies());
	}

	/** @return array<string,array{string}> */
	public static function hostsWithNoOrigin(): array {
		return [
			'no scheme' => ['pad.example.test'],
			'a host that would end the directive' => ['https://pad.example.test;script-src'],
			'a host with a second source' => ['https://pad.example.test other.example.test'],
			'a host with a comma' => ['https://pad.example.test,other.example.test'],
		];
	}

	/** @param array<string,string> $values */
	private function handled(array $values): AddContentSecurityPolicyEvent {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $app === 'etherpad_nextcloud' ? ($values[$key] ?? $default) : $default,
		);
		$event = new AddContentSecurityPolicyEvent();

		(new CSPListener(new EtherpadClient($config, $this->createMock(AdminSettingsRepository::class), $this->createMock(IClientService::class))))->handle($event);

		return $event;
	}
}
