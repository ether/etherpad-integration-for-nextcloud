<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PadPlaceholderResolver;
use OCP\IConfig;
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
		// At midnight UTC, Los Angeles is still on the day before: relative
		// dates must take UTC whatever the server's timezone.
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

	/** One segment of a name; a name that leaves nothing gives way to the UID, which keeps its leading dots. */
	#[\PHPUnit\Framework\Attributes\DataProvider('displayNamesInAPath')]
	public function testApplyForPathKeepsADisplayNameToOneSegment(string $name, string $expected, string $uid = 'user1'): void {
		$this->assertSame($expected, $this->resolver()->applyForPath('{{user}}', $this->user($name, $uid)));
	}

	/** @return array<string,array{0:string,1:string,2?:string}> */
	public static function displayNamesInAPath(): array {
		return [
			'dots behind a separator' => ['/..', 'user1'],
			'dots between spaces' => [' .. ', 'user1'],
			'dots behind a vertical tab' => ["\v..", 'user1'],
			'dots and spaces in turn' => ['. . Anna', 'Anna'],
			'dots before a name' => ['..Anna', 'Anna'],
			'a dot after a name' => ['Anna.', 'Anna.'],
			'a separator at the end' => ['Anna/', 'Anna'],
			'a tab between words' => ["Anna\tMüller", 'Anna Müller'],
			'a control character' => ["An\x01na", 'An na'],
			'the delete character' => ["An\x7Fna", 'An na'],
			'a backslash' => ['Anna\\Müller', 'Anna Müller'],
			'two separators in a row' => ['Anna//Müller', 'Anna Müller'],
			'dots alone, a UID with a dot' => ['/..', '.bob', '.bob'],
		];
	}

	/** The fallback is what keeps a folder or a name in the path. */
	public function testApplyForPathKeepsTheSegmentOfAnEmptiedName(): void {
		$this->assertSame('user1/notes', $this->resolver()->applyForPath('{{user}}/notes', $this->user('/..')));
		$this->assertSame('user1.pad', $this->resolver()->applyForPath('{{user}}.pad', $this->user('/..')));
	}

	/** What the admin forbids in a name, for Windows clients for example, becomes a space as a separator does, before the trims. */
	#[\PHPUnit\Framework\Attributes\DataProvider('namesWithForbiddenCharacters')]
	public function testApplyForPathReplacesWhatTheAdminForbids(string $name, string $expected, string $key = 'forbidden_filename_characters'): void {
		$resolver = $this->resolver([$key => [':', '?', '*', 7]]);
		$this->assertSame($expected, $resolver->applyForPath('{{user}}', $this->user($name)));
	}

	/** @return array<string,array{0:string,1:string,2?:string}> */
	public static function namesWithForbiddenCharacters(): array {
		return [
			'a colon' => ['Anna:Müller', 'Anna Müller'],
			'a run of them' => ['Anna::Müller', 'Anna Müller'],
			'one at the end' => ['Anna?', 'Anna'],
			'one before a dot' => ['?.', 'user1'],
			'one before two dots' => ['?..', 'user1'],
			'one before a hidden name' => ['?.hidden', 'hidden'],
			'under the older key' => ['Anna*Müller', 'Anna Müller', 'forbidden_chars'],
			'a number listed, which is no character' => ['Anna7', 'Anna7'],
		];
	}

	/** A UID keeps its leading dots, as another user may have the same UID without them. */
	#[\PHPUnit\Framework\Attributes\DataProvider('uidsInAPath')]
	public function testApplyForPathKeepsAUidApartFromAnother(string $uid, string $expected): void {
		$this->assertSame($expected, $this->resolver()->applyForPath('{{user.uid}}', $this->user('Anna', $uid)));
	}

	/** @return array<string,array{string,string}> */
	public static function uidsInAPath(): array {
		return [
			'a leading dot' => ['.anna', '.anna'],
			'dots alone, which Nextcloud takes as a login' => ['...', '...'],
			'two dots, which it does not' => ['..', ''],
			'one dot' => ['.', ''],
			'a separator' => ['an/na', 'an na'],
		];
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

	/** @param array<string,mixed> $system Nextcloud's system config */
	private function resolver(array $system = []): PadPlaceholderResolver {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => $system[$key] ?? $default,
		);
		return new PadPlaceholderResolver(new FixedClock(self::FIXED_NOW), $config);
	}

	private function user(string $displayName, string $uid = 'user1'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}
