<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\CSPListener;
use OCP\EventDispatcher\Event;
use OCP\IConfig;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
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
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($app !== 'etherpad_nextcloud') {
					return $default;
				}

				return $key === 'etherpad_host' ? 'https://pad.jaggob.uber.space' : $default;
			}
		);

		$listener = new CSPListener($config);
		$event = new AddContentSecurityPolicyEvent();

		$listener->handle($event);

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
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('getAppValue');

		(new CSPListener($config))->handle(new Event());
	}

	/** The allowlist says whom the app fetches from, not what any page may frame. */
	public function testFramesOnlyTheOwnEtherpadWhateverTheAllowlist(): void {
		$this->assertSame(['https://pad.example.test'], $this->frameDomains([
			'etherpad_host' => 'https://pad.example.test/',
			'allow_external_pads' => 'yes',
			'external_pad_allowlist' => 'other.example.test',
		]));
	}

	public function testAddsNoPolicyWithoutAnEtherpadHost(): void {
		$this->assertSame([], $this->frameDomains(['allow_external_pads' => 'yes', 'external_pad_allowlist' => 'other.example.test']));
	}

	/**
	 * @param array<string, string> $values
	 * @return list<string>
	 */
	private function frameDomains(array $values): array {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $app === 'etherpad_nextcloud' ? ($values[$key] ?? $default) : $default,
		);
		$event = new AddContentSecurityPolicyEvent();

		(new CSPListener($config))->handle($event);

		$policies = $event->getPolicies();
		return $policies === [] ? [] : $policies[0]->getAllowedFrameDomains();
	}
}
