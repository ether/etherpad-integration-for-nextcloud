<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\CSPListener;
use OCP\IConfig;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use PHPUnit\Framework\TestCase;

class CSPListenerTest extends TestCase {
	public function testHandleAllowsConfiguredExternalHostsInFrameSrc(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($app !== 'etherpad_nextcloud') {
					return $default;
				}

				return match ($key) {
					'etherpad_host' => 'https://pad.jaggob.uber.space',
					'allow_external_pads' => 'yes',
					'external_pad_allowlist' => "pad.portal.fzs.de\nhttps://etherpad.example.org:8443",
					default => $default,
				};
			}
		);

		$listener = new CSPListener($config);
		$event = new AddContentSecurityPolicyEvent();

		$listener->handle($event);

		$policies = $event->getPolicies();
		$this->assertCount(1, $policies);
		$this->assertSame([
			'https://pad.jaggob.uber.space',
			'https://pad.portal.fzs.de',
			'https://etherpad.example.org:8443',
		], $policies[0]->getAllowedFrameDomains());
	}

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

	public function testHandleNeverEmitsBlanketHttpsWhenExternalAllowlistIsEmpty(): void {
		// Regression for #102: external pads enabled + empty allowlist must NOT
		// relax frame-src to `https:` globally. Only the concrete
		// configured Etherpad host is allowed to be framed.
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($app !== 'etherpad_nextcloud') {
					return $default;
				}

				return match ($key) {
					'etherpad_host' => 'https://pad.jaggob.uber.space',
					'allow_external_pads' => 'yes',
					'external_pad_allowlist' => '',
					default => $default,
				};
			}
		);

		$listener = new CSPListener($config);
		$event = new AddContentSecurityPolicyEvent();

		$listener->handle($event);

		$policies = $event->getPolicies();
		$this->assertCount(1, $policies);
		$this->assertSame(
			['https://pad.jaggob.uber.space'],
			$policies[0]->getAllowedFrameDomains()
		);
		$this->assertNotContains('https:', $policies[0]->getAllowedFrameDomains());
	}

	public function testHandleEmitsNoPolicyWhenOnlyExternalEmptyAllowlistAndNoLocalHost(): void {
		// With no local Etherpad host and an empty external allowlist there is
		// nothing concrete to allow, so the listener must add no policy at all
		// (rather than a blanket `https:`).
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($app !== 'etherpad_nextcloud') {
					return $default;
				}

				return match ($key) {
					'etherpad_host' => '',
					'allow_external_pads' => 'yes',
					'external_pad_allowlist' => '',
					default => $default,
				};
			}
		);

		$listener = new CSPListener($config);
		$event = new AddContentSecurityPolicyEvent();

		$listener->handle($event);

		$this->assertSame([], $event->getPolicies());
	}
}
