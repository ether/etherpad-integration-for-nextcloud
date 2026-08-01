<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\CookieDomainDecision;
use OCA\EtherpadNextcloud\Service\CookieDomainMessages;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\HealthCheckItem;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class CookieDomainMessagesTest extends TestCase {
	/**
	 * Inputs that drive the policy into each warning it can produce, so a new
	 * reason without a message shows up here rather than as a blank warning on
	 * the settings page.
	 *
	 * @return iterable<string,array{0:string,1:string,2:?string,3:list<string>}>
	 */
	public static function warningProvider(): iterable {
		yield 'no common parent' => [
			'https://cloud.example.org', 'https://pad.other.net', null,
			['cloud.example.org', 'pad.other.net'],
		];
		yield 'hosts cannot carry a domain cookie' => [
			'http://localhost:8080', 'http://127.0.0.1:9001', null,
			['localhost', '127.0.0.1'],
		];
		yield 'configured domain covers neither' => [
			'https://cloud.example.org', 'https://pad.example.org', '.example.orgs',
			['cloud.example.org', '.example.orgs', '.example.org'],
		];
		yield 'deliberate host-only across hosts' => [
			'https://cloud.example.org', 'https://pad.example.org', '',
			['cloud.example.org', '.example.org'],
		];
		yield 'shared parent may be a public suffix' => [
			'https://cloud.github.io', 'https://pad.github.io', null,
			['cloud.github.io', '.github.io'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('warningProvider')]
	public function testEveryWarningReasonProducesAUsableLine(
		string $nextcloudUrl,
		string $etherpadUrl,
		?string $configured,
		array $expectedFragments,
	): void {
		$decision = (new CookieDomainPolicy())->decide($nextcloudUrl, $etherpadUrl, $configured);
		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $decision->status, 'fixture should produce a warning');

		$line = $this->buildMessages()->asCheckItem($decision);

		$this->assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		$this->assertNotSame('', trim($line->detail), 'a warning with no text is invisible on the page');
		foreach ($expectedFragments as $fragment) {
			$this->assertStringContainsString($fragment, $line->detail);
		}
	}

	public function testPassingDecisionsShowTheDomainInUse(): void {
		$decision = (new CookieDomainPolicy())->decide('https://cloud.example.org', 'https://pad.example.org', null);

		$line = $this->buildMessages()->asCheckItem($decision);

		$this->assertSame(HealthCheckItem::STATUS_OK, $line->status);
		$this->assertSame('.example.org', $line->detail);
	}

	public function testSameHostSaysWhyNoDomainIsNeeded(): void {
		$decision = (new CookieDomainPolicy())->decide('https://pad.example.org', 'https://pad.example.org', null);

		$line = $this->buildMessages()->asCheckItem($decision);

		$this->assertSame(HealthCheckItem::STATUS_OK, $line->status);
		$this->assertNotSame('', trim($line->detail));
	}

	/**
	 * The provider above only reaches reasons the policy produces today. A
	 * reason added later without wording must still say something rather than
	 * render as a blank warning.
	 */
	public function testAnUnmappedReasonStillNamesTheHosts(): void {
		$decision = new CookieDomainDecision(
			'',
			CookieDomainDecision::STATUS_WARNING,
			'something_new',
			'cloud.example.org',
			'pad.other.net',
			CookieDomainDecision::SOURCE_HOST_ONLY,
		);

		$line = $this->buildMessages()->asCheckItem($decision);

		$this->assertSame(HealthCheckItem::STATUS_WARNING, $line->status);
		$this->assertStringContainsString('cloud.example.org', $line->detail);
		$this->assertStringContainsString('pad.other.net', $line->detail);
	}

	public function testNoDecisionIsSkippedRatherThanEmpty(): void {
		$line = $this->buildMessages()->asCheckItem(null);

		$this->assertSame(HealthCheckItem::STATUS_SKIPPED, $line->status);
		$this->assertNotSame('', trim($line->detail));
	}

	private function buildMessages(): CookieDomainMessages {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn(string $text): string => $text
		);
		return new CookieDomainMessages($l10n);
	}
}
