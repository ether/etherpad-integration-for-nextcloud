<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PadPlaceholderResolver;
use OCP\IUser;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

class PadPlaceholderResolverTest extends TestCase {
	private const FIXED_NOW = 1778976000; // 2026-05-17 (Sunday) 00:00 UTC

	public function testReplacesDateWithDefaultFormat(): void {
		$result = $this->resolver()->applyForContent('Heute ist {{date}}.', $this->user('Jacob Bühler'));
		$this->assertSame('Heute ist 2026-05-17.', $result);
	}

	public function testReplacesDateWithCustomFormat(): void {
		$result = $this->resolver()->applyForContent('Stempel: {{date|d.m.Y}}', $this->user('Jacob Bühler'));
		$this->assertSame('Stempel: 17.05.2026', $result);
	}

	public function testReplacesDateRelativeNextMonday(): void {
		$result = $this->resolver()->applyForContent('Sitzung {{date:next monday|d.m.Y}}', $this->user('Jacob Bühler'));
		// 2026-05-17 is Sunday → next monday is 2026-05-18
		$this->assertSame('Sitzung 18.05.2026', $result);
	}

	public function testReplacesDateOffset(): void {
		$result = $this->resolver()->applyForContent('In sieben Tagen: {{date:+7 days}}', $this->user('Jacob Bühler'));
		$this->assertSame('In sieben Tagen: 2026-05-24', $result);
	}

	public function testReplacesUserDisplayName(): void {
		$result = $this->resolver()->applyForContent('Autor: {{user}}', $this->user('Jacob Bühler'));
		$this->assertSame('Autor: Jacob Bühler', $result);
	}

	public function testReplacesUserUid(): void {
		$result = $this->resolver()->applyForContent('UID: {{user.uid}}', $this->user('Jacob Bühler', 'jaggob'));
		$this->assertSame('UID: jaggob', $result);
	}

	public function testLeavesUnknownDirectivesAsLiteral(): void {
		$result = $this->resolver()->applyForContent('Weather: {{forecast}}', $this->user('x'));
		$this->assertSame('Weather: {{forecast}}', $result);
	}

	public function testLeavesUnknownUserPropertyAsLiteral(): void {
		$result = $this->resolver()->applyForContent('Whoami: {{user.phone}}', $this->user('x'));
		$this->assertSame('Whoami: {{user.phone}}', $result);
	}

	public function testLeavesUnparseableDateExpressionAsLiteral(): void {
		$result = $this->resolver()->applyForContent('Datum: {{date:complete-nonsense-xyz}}', $this->user('x'));
		$this->assertSame('Datum: {{date:complete-nonsense-xyz}}', $result);
	}

	public function testReplacesMultiplePlaceholdersInOneLine(): void {
		$result = $this->resolver()->applyForContent(
			'# Protokoll {{date:next monday|d.m.Y}} ({{user}})',
			$this->user('Jacob Bühler'),
		);
		$this->assertSame('# Protokoll 18.05.2026 (Jacob Bühler)', $result);
	}

	public function testTolerantToWhitespaceInsideBraces(): void {
		$result = $this->resolver()->applyForContent('{{ date | Y }}', $this->user('x'));
		$this->assertSame('2026', $result);
	}

	public function testRelativeDatesAreDeterministicUnderWesternTimezone(): void {
		// Regression: strtotime/date previously depended on
		// date_default_timezone_get(), so "today" and "next monday" drifted on
		// hosts west of UTC. Resolver now pins to UTC internally.
		$previous = date_default_timezone_get();
		date_default_timezone_set('America/Los_Angeles');
		try {
			$result = $this->resolver()->applyForContent(
				'{{date}} | {{date:next monday|d.m.Y}} | {{date:+7 days}}',
				$this->user('x'),
			);
			$this->assertSame('2026-05-17 | 18.05.2026 | 2026-05-24', $result);
		} finally {
			date_default_timezone_set($previous);
		}
	}

	public function testApplyForPathStripsSeparatorsFromDisplayName(): void {
		// Defense-in-depth: a display name containing "/" or ".." must not
		// expand into a path-traversal payload when interpolated into a
		// user-supplied target file path.
		$result = $this->resolver()->applyForPath('{{user}}', $this->user('../etc/passwd'));
		$this->assertSame('etc passwd', $result);
	}

	public function testLeavesDotedDateDirectiveAsLiteral(): void {
		// `date.something` is not a real property — the date directive only
		// supports `:arg` and `|format`. Silently rendering today's date
		// would mask the typo, so we keep the original literal.
		$result = $this->resolver()->applyForContent('Stempel: {{date.foo}}', $this->user('x'));
		$this->assertSame('Stempel: {{date.foo}}', $result);
	}

	public function testApplyForContentPreservesSlashesInDisplayName(): void {
		// In body / snapshot context the display name must reach the user
		// verbatim — stripping `/` from "AC/DC" would silently mangle visible
		// document content. Only `applyForPath` sanitizes.
		$result = $this->resolver()->applyForContent('Band: {{user}}', $this->user('AC/DC'));
		$this->assertSame('Band: AC/DC', $result);
	}

	public function testNoUserSilentlySkipsUserDirectives(): void {
		// Public-share / anonymous context: user is null. Leave the directive
		// alone rather than render the empty string.
		$result = $this->resolver()->applyForContent('Autor: {{user}}, Datum: {{date}}', null);
		$this->assertSame('Autor: {{user}}, Datum: 2026-05-17', $result);
	}

	private function resolver(): PadPlaceholderResolver {
		return new PadPlaceholderResolver(new FixedClock(self::FIXED_NOW));
	}

	private function user(string $displayName, string $uid = 'user1'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}
