<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\PadLostException;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\PublicLinkOpener;
use OCA\EtherpadNextcloud\Service\PublicLinkVisitors;
use OCA\EtherpadNextcloud\Service\PublicPadOpenService;
use PHPUnit\Framework\TestCase;

class PublicPadOpenServiceTest extends TestCase {
	public function testProtectedReadOnlyHandsOutNoPadAddress(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('buildPadUrl');
		$etherpad->expects($this->never())->method('getReadOnlyPadUrl');

		$result = $this->buildService(etherpadClient: $etherpad)->open($this->pad('g.group$pad', BindingService::ACCESS_PROTECTED, false, ''), true, 'token');

		$this->assertSame('', $result->url);
		$this->assertTrue($result->isReadOnlyView);
		$this->assertSame('', $result->cookieHeader());
	}

	/**
	 * A visitor opens as who PublicLinkVisitors says, with the name it
	 * gives - none - and the author their session keeps; the author the
	 * open used is handed back to be kept.
	 */
	public function testProtectedWritableCreatesASessionForTheVisitor(): void {
		$opener = new PublicLinkOpener('public-share:token:0123456789abcdef0123456789abcdef', '', 'a.kept');
		$sessions = $this->createMock(PadSessionService::class);
		$sessions->expects($this->once())
			->method('createProtectedOpenContext')
			->with('public-share:token:0123456789abcdef0123456789abcdef', '', 'g.group$pad', self::shareTtl(), 'a.kept')
			->willReturn(['url' => 'https://pad.example/p/g.group$pad', 'cookie' => ['name' => 'sessionID'], 'authorId' => 'a.made']);
		$sessions->expects($this->once())
			->method('buildSetCookieHeader')
			->with(['name' => 'sessionID'])
			->willReturn('sessionID=abc; Path=/');
		$visitors = $this->visitorsFor($opener);
		$visitors->expects($this->once())->method('rememberAuthor')->with('token', $opener, 'a.made');

		$result = $this->buildService(padSessionService: $sessions, visitors: $visitors)->open($this->pad('g.group$pad', BindingService::ACCESS_PROTECTED, false, ''), false, 'token');

		$this->assertSame('https://pad.example/p/g.group$pad', $result->url);
		$this->assertSame('sessionID=abc; Path=/', $result->cookieHeader());
		$this->assertFalse($result->isReadOnlyView);
	}

	/** Past the link's count for the hour, a visitor opens as the link, under its own name. */
	public function testProtectedWritableOpensAsTheLinkPastItsCount(): void {
		$sessions = $this->createMock(PadSessionService::class);
		$sessions->expects($this->once())
			->method('createProtectedOpenContext')
			->with('public-share:token', 'Public share', 'g.group$pad', self::shareTtl(), '')
			->willReturn(['url' => 'https://pad.example/p/g.group$pad', 'cookie' => ['name' => 'sessionID'], 'authorId' => 'a.link']);

		$this->buildService(padSessionService: $sessions)->open($this->pad('g.group$pad', BindingService::ACCESS_PROTECTED, false, ''), false, 'token');
	}

	/**
	 * Only a writable link to a protected pad opens as a visitor: a
	 * read-only link or a public pad counts none.
	 */
	public function testOnlyAWritableProtectedOpenAsksWhoTheVisitorIs(): void {
		$opens = [
			'read-only, protected' => [$this->pad('g.group$pad', BindingService::ACCESS_PROTECTED, false, ''), true],
			'read-only, public' => [$this->pad('public-pad', BindingService::ACCESS_PUBLIC, false, ''), true],
			'writable, public' => [$this->pad('public-pad', BindingService::ACCESS_PUBLIC, false, ''), false],
		];
		foreach ($opens as $case => [$pad, $readOnly]) {
			$visitors = $this->createMock(PublicLinkVisitors::class);
			$visitors->expects($this->never())->method('openerFor');
			$this->buildService(visitors: $visitors)->open($pad, $readOnly, 'token');
			$this->addToAssertionCount(1);
		}
	}

	public function testExternalPublicPadReturnsNormalizedUrl(): void {
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->expects($this->once())
			->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://remote.example/p/Test')
			->willReturn(['pad_url' => 'https://remote.example/p/Test']);

		$result = $this->buildService(externalPadExportFetcher: $fetcher)->open($this->pad('ext.abc', BindingService::ACCESS_PUBLIC, true, 'https://remote.example/p/Test'), true, 'token');

		$this->assertSame('https://remote.example/p/Test', $result->url);
		$this->assertSame('https://remote.example/p/Test', $result->originalPadUrl);
	}

	/** The rule for an external pad's metadata is ParsedPadFile::externalPadUrl()'s; the open holds it. */
	public function testExternalPadWithoutUrlIsRejected(): void {
		$this->expectException(ExternalPadException::class);
		$this->expectExceptionMessage('External pad URL metadata is missing or invalid.');

		$this->buildService()->open($this->pad('ext.abc', BindingService::ACCESS_PUBLIC, true, ''), false, 'token');
	}

	public function testInternalReadOnlyUsesEtherpadReadOnlyUrl(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('getReadOnlyPadUrl')
			->with('public-pad')
			->willReturn('https://pad.example/p/r.public-pad');

		$result = $this->buildService(etherpadClient: $etherpad)->open($this->pad('public-pad', BindingService::ACCESS_PUBLIC, false, ''), true, 'token');

		$this->assertSame('https://pad.example/p/r.public-pad', $result->url);
		$this->assertSame('', $result->cookieHeader());
	}

	public function testInternalWritableUsesPublicPadUrlWithoutSession(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('buildPadUrl')
			->with('public-pad')
			->willReturn('https://pad.example/p/public-pad');

		$sessions = $this->createMock(PadSessionService::class);
		$sessions->expects($this->never())->method('createProtectedOpenContext');

		$result = $this->buildService(etherpadClient: $etherpad, padSessionService: $sessions)->open($this->pad('public-pad', BindingService::ACCESS_PUBLIC, false, ''), false, 'token');

		$this->assertSame('https://pad.example/p/public-pad', $result->url);
		$this->assertSame('', $result->cookieHeader());
	}

	/**
	 * Through a share that may write, a pad Etherpad has lost is refused:
	 * a new one takes a signed-in user with write access. A reader is not
	 * asked.
	 */
	public function testAPadEtherpadHasLostIsNotOpenedForWriting(): void {
		foreach ([BindingService::ACCESS_PROTECTED, BindingService::ACCESS_PUBLIC] as $accessMode) {
			$lifecycle = $this->createMock(ManagedPadLifecycle::class);
			$lifecycle->expects($this->once())->method('isKnownLost')->with('pad-1', $accessMode, $this->callback(static fn (ParsedPadFile $pad): bool => $pad->snapshotRev === 5 && $pad->savedText() === 'Saved in the file'))->willReturn(true);
			$sessions = $this->createMock(PadSessionService::class);
			$sessions->expects($this->never())->method('createProtectedOpenContext');
			try {
				$this->buildService(padSessionService: $sessions, padLifecycle: $lifecycle)->open($this->pad('pad-1', $accessMode, false, '', 5, 'Saved in the file'), false, 'token');
				$this->fail($accessMode . ': opened');
			} catch (PadLostException) {
				$this->addToAssertionCount(1);
			}
		}

		$lifecycle = $this->createMock(ManagedPadLifecycle::class);
		$lifecycle->expects($this->never())->method('isKnownLost');
		$this->buildService(padLifecycle: $lifecycle)->open($this->pad('pad-1', BindingService::ACCESS_PROTECTED, false, '', 5), true, 'token');
		$this->buildService(padLifecycle: $lifecycle)->open($this->pad('pad-1', BindingService::ACCESS_PUBLIC, false, '', 5), true, 'token');
	}

	private function buildService(
		?EtherpadClient $etherpadClient = null,
		?PadSessionService $padSessionService = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
		?ManagedPadLifecycle $padLifecycle = null,
		?PublicLinkVisitors $visitors = null,
	): PublicPadOpenService {
		return new PublicPadOpenService(
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$padLifecycle ?? $this->createMock(ManagedPadLifecycle::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			$padSessionService ?? $this->createMock(PadSessionService::class),
			$visitors ?? $this->visitorsFor(new PublicLinkOpener('public-share:token', PublicLinkVisitors::LINK_AUTHOR_NAME, '')),
		);
	}

	/** @return PublicLinkVisitors&\PHPUnit\Framework\MockObject\MockObject */
	private function visitorsFor(PublicLinkOpener $opener): PublicLinkVisitors {
		$visitors = $this->createMock(PublicLinkVisitors::class);
		$visitors->method('openerFor')->willReturn($opener);
		return $visitors;
	}

	/**
	 * Withdrawing a share revokes no session it issued, so its lifetime
	 * bounds the write access left after it. Both numbers are pinned, not
	 * just their order:
	 * "shorter than the other" is satisfied by values far too long.
	 */
	public function testTheSessionLifetimesAreWhatWasChosen(): void {
		$this->assertSame(10800, self::shareTtl());
		$this->assertSame(21600, PadSessionService::SESSION_TTL_SECONDS);
		$this->assertLessThan(PadSessionService::SESSION_TTL_SECONDS, self::shareTtl());
	}

	private static function shareTtl(): int {
		$ttl = (new \ReflectionClass(PublicPadOpenService::class))
			->getConstant('PUBLIC_SHARE_SESSION_TTL_SECONDS');
		self::assertIsInt($ttl);
		return $ttl;
	}

	private function pad(string $padId, string $accessMode, bool $isExternal = false, string $padUrl = '', int $snapshotRev = -1, string $savedText = ''): ParsedPadFile {
		return new ParsedPadFile([], $savedText, $padId, $accessMode, $padUrl, $isExternal, $snapshotRev, $savedText);
	}
}
