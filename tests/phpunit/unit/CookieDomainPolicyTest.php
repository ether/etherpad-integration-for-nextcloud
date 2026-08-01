<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\CookieDomainDecision;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use PHPUnit\Framework\TestCase;

class CookieDomainPolicyTest extends TestCase {
	private CookieDomainPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new CookieDomainPolicy();
	}

	public function testSharedParentDomainYieldsThatDomain(): void {
		$decision = $this->policy->decide('https://cloud.example.org', 'https://pad.example.org', null);

		$this->assertSame('.example.org', $decision->effectiveDomain);
		$this->assertTrue($decision->isOk());
		$this->assertSame(CookieDomainDecision::REASON_COMMON_PARENT, $decision->reason);
		$this->assertSame(CookieDomainDecision::SOURCE_DERIVED, $decision->source);
	}

	public function testUnrelatedDomainsWarnAndNameBothHosts(): void {
		$decision = $this->policy->decide('https://cloud.example.org', 'https://pad.other.net', null);

		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $decision->status);
		$this->assertSame(CookieDomainDecision::REASON_NO_COMMON_PARENT, $decision->reason);
		$this->assertSame('cloud.example.org', $decision->nextcloudHost);
		$this->assertSame('pad.other.net', $decision->etherpadHost);
		// No domain is better than one the browser would reject anyway.
		$this->assertSame('', $decision->effectiveDomain);
	}

	/**
	 * The old runtime derived from the Etherpad host alone and would have used
	 * `.example.org` here. Both work, but the shared parent is narrower.
	 */
	public function testNestedHostsNarrowToTheSharedParent(): void {
		$decision = $this->policy->decide('https://cloud.pad.example.org', 'https://pad.example.org', null);

		$this->assertSame('.pad.example.org', $decision->effectiveDomain);
		$this->assertTrue($decision->isOk());
	}

	public function testIdenticalHostsUseAHostOnlyCookie(): void {
		$decision = $this->policy->decide('https://pad.example.org', 'https://pad.example.org/', null);

		$this->assertSame('', $decision->effectiveDomain);
		$this->assertTrue($decision->isOk());
		$this->assertSame(CookieDomainDecision::REASON_SAME_HOST, $decision->reason);
		$this->assertSame(CookieDomainDecision::SOURCE_HOST_ONLY, $decision->source);
	}

	/**
	 * Identical hosts are checked before cookie capability, so a local
	 * single-host install is not warned about a domain it never needs.
	 */
	public function testIdenticalLocalhostAndIpHostsAreAccepted(): void {
		foreach (['http://localhost:8080', 'http://192.168.1.10'] as $url) {
			$decision = $this->policy->decide($url, $url, null);
			$this->assertTrue($decision->isOk(), $url);
			$this->assertSame(CookieDomainDecision::REASON_SAME_HOST, $decision->reason, $url);
		}
	}

	public function testDifferentLocalhostOrIpHostsCannotShareACookie(): void {
		$decision = $this->policy->decide('http://localhost:8080', 'http://127.0.0.1:9001', null);

		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $decision->status);
		$this->assertSame(CookieDomainDecision::REASON_HOST_NOT_COOKIE_CAPABLE, $decision->reason);
	}

	public function testConfiguredDomainMustCoverBothHosts(): void {
		$ok = $this->policy->decide('https://cloud.example.org', 'https://pad.example.org', '.example.org');
		$this->assertTrue($ok->isOk());
		$this->assertSame('.example.org', $ok->effectiveDomain);
		$this->assertSame(CookieDomainDecision::SOURCE_CONFIGURED, $ok->source);

		$bad = $this->policy->decide('https://cloud.example.org', 'https://pad.other.net', '.example.org');
		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $bad->status);
		$this->assertSame(CookieDomainDecision::REASON_CONFIGURED_DOMAIN_MISMATCH, $bad->reason);
	}

	/** A suffix match must not cross a label boundary. */
	public function testLookalikeDomainDoesNotCount(): void {
		$decision = $this->policy->decide('https://cloud.example.org', 'https://pad.evil-example.org', 'example.org');

		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $decision->status);
		$this->assertSame(CookieDomainDecision::REASON_CONFIGURED_DOMAIN_MISMATCH, $decision->reason);
	}

	public function testConfiguredDomainIsNormalisedToALeadingDot(): void {
		$decision = $this->policy->decide('https://cloud.example.org', 'https://pad.example.org', 'EXAMPLE.ORG');

		$this->assertSame('.example.org', $decision->effectiveDomain);
		$this->assertTrue($decision->isOk());
	}

	/**
	 * null means "never configured, derive one"; an empty string means the
	 * admin deliberately asked for a host-only cookie. Only the first can
	 * produce a domain.
	 */
	public function testNullAndEmptyStringAreNotTheSame(): void {
		$derived = $this->policy->decide('https://cloud.example.org', 'https://pad.example.org', null);
		$hostOnly = $this->policy->decide('https://cloud.example.org', 'https://pad.example.org', '');

		$this->assertSame('.example.org', $derived->effectiveDomain);
		$this->assertTrue($derived->isOk());

		$this->assertSame('', $hostOnly->effectiveDomain);
		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $hostOnly->status);
		$this->assertSame(CookieDomainDecision::REASON_HOST_ONLY_ACROSS_HOSTS, $hostOnly->reason);
	}

	public function testHostOnlyIsFineWhenTheHostsMatch(): void {
		$decision = $this->policy->decide('https://pad.example.org', 'https://pad.example.org', '');

		$this->assertTrue($decision->isOk());
		$this->assertSame(CookieDomainDecision::REASON_SAME_HOST, $decision->reason);
	}

	/** @return list<array{string,string}> */
	public static function publicSuffixProvider(): array {
		return [
			['https://cloud.example.co.uk', 'https://pad.other.co.uk'],
			['https://cloud.github.io', 'https://pad.github.io'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('publicSuffixProvider')]
	public function testSharedPublicSuffixIsFlaggedRatherThanTrusted(string $nextcloud, string $etherpad): void {
		$decision = $this->policy->decide($nextcloud, $etherpad, null);

		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $decision->status);
		$this->assertSame(CookieDomainDecision::REASON_MAY_BE_PUBLIC_SUFFIX, $decision->reason);
	}

	public function testMissingConfigurationIsUnknownRatherThanAWarning(): void {
		foreach ([['', 'https://pad.example.org'], ['https://cloud.example.org', '']] as [$nextcloud, $etherpad]) {
			$decision = $this->policy->decide($nextcloud, $etherpad, null);
			$this->assertSame(CookieDomainDecision::STATUS_UNKNOWN, $decision->status);
			$this->assertSame(CookieDomainDecision::REASON_NOT_CONFIGURED, $decision->reason);
		}
	}

	/** A host that is set but unparseable is a different problem from an unset one. */
	public function testUnparseableHostIsReportedAsInvalidRatherThanUnset(): void {
		$decision = $this->policy->decide('https://bad_host/', 'https://pad.example.org', null);

		$this->assertSame(CookieDomainDecision::STATUS_UNKNOWN, $decision->status);
		$this->assertSame(CookieDomainDecision::REASON_INVALID_HOST, $decision->reason);
	}

	/**
	 * Covering both hosts is not sufficient for an explicitly configured
	 * domain either — the browser rejects a public suffix regardless.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('configuredPublicSuffixProvider')]
	public function testConfiguredPublicSuffixIsFlaggedToo(string $nextcloud, string $etherpad, string $configured): void {
		$decision = $this->policy->decide($nextcloud, $etherpad, $configured);

		$this->assertSame(CookieDomainDecision::STATUS_WARNING, $decision->status);
		$this->assertSame(CookieDomainDecision::REASON_MAY_BE_PUBLIC_SUFFIX, $decision->reason);
	}

	/** @return list<array{string,string,string}> */
	public static function configuredPublicSuffixProvider(): array {
		return [
			['https://cloud.co.uk', 'https://pad.co.uk', '.co.uk'],
			['https://cloud.github.io', 'https://pad.github.io', '.github.io'],
		];
	}

	/**
	 * Identical hosts short-circuit before the configured value is read. That
	 * is deliberate: a host-only cookie always reaches the single host, and no
	 * `Domain=` is needed to get there.
	 */
	public function testIdenticalHostsIgnoreAConfiguredDomain(): void {
		$decision = $this->policy->decide('https://pad.example.org', 'https://pad.example.org', '.example.org');

		$this->assertSame('', $decision->effectiveDomain);
		$this->assertTrue($decision->isOk());
		$this->assertSame(CookieDomainDecision::REASON_SAME_HOST, $decision->reason);
		$this->assertSame(CookieDomainDecision::SOURCE_HOST_ONLY, $decision->source);
	}

	public function testBareHostnamesWorkAsWellAsUrls(): void {
		$decision = $this->policy->decide('cloud.example.org', 'pad.example.org', null);

		$this->assertSame('.example.org', $decision->effectiveDomain);
		$this->assertTrue($decision->isOk());
	}

	public function testResolveReturnsTheDomainOfTheSameDecision(): void {
		$this->assertSame(
			$this->policy->decide('https://cloud.example.org', 'https://pad.example.org', null)->effectiveDomain,
			$this->policy->resolve('https://cloud.example.org', 'https://pad.example.org', null),
		);
	}
}
