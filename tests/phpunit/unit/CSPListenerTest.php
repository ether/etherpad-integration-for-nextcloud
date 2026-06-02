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
		$this->assertSame([
			'https://pad.jaggob.uber.space',
			'https://pad.portal.fzs.de',
			'https://etherpad.example.org:8443',
		], $policies[0]->getAllowedChildSrcDomains());
	}

	public function testHandleNeverEmitsBlanketHttpsWhenExternalAllowlistIsEmpty(): void {
		// Regression for #102: external pads enabled + empty allowlist must NOT
		// relax frame-src/child-src to `https:` globally. Only the concrete
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
		$this->assertSame(
			['https://pad.jaggob.uber.space'],
			$policies[0]->getAllowedChildSrcDomains()
		);
		$this->assertNotContains('https:', $policies[0]->getAllowedFrameDomains());
		$this->assertNotContains('https:', $policies[0]->getAllowedChildSrcDomains());
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
