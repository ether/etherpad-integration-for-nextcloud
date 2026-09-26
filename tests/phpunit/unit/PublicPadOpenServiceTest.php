<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\PadLostException;
use OCA\EtherpadNextcloud\Service\PadPresence;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
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
		$this->assertSame('', $result->cookieHeader);
	}

	public function testProtectedWritableCreatesPublicShareSession(): void {
		$sessions = $this->createMock(PadSessionService::class);
		$sessions->expects($this->once())
			->method('createProtectedOpenContext')
			->with('public-share:token', 'Public share', 'g.group$pad', self::shareTtl())
			->willReturn(['url' => 'https://pad.example/p/g.group$pad', 'cookie' => ['name' => 'sessionID']]);
		$sessions->expects($this->once())
			->method('buildSetCookieHeader')
			->with(['name' => 'sessionID'])
			->willReturn('sessionID=abc; Path=/');

		$result = $this->buildService(padSessionService: $sessions)->open($this->pad('g.group$pad', BindingService::ACCESS_PROTECTED, false, ''), false, 'token');

		$this->assertSame('https://pad.example/p/g.group$pad', $result->url);
		$this->assertSame('sessionID=abc; Path=/', $result->cookieHeader);
		$this->assertFalse($result->isReadOnlyView);
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
		$this->assertSame('', $result->cookieHeader);
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
		$this->assertSame('', $result->cookieHeader);
	}

	/**
	 * Through a share that may write, a pad Etherpad has lost is refused:
	 * only the file's owner can make a new one. A reader is not asked.
	 */
	public function testAPadEtherpadHasLostIsNotOpenedForWriting(): void {
		foreach ([BindingService::ACCESS_PROTECTED, BindingService::ACCESS_PUBLIC] as $accessMode) {
			$lifecycle = $this->createMock(ManagedPadLifecycle::class);
			$lifecycle->expects($this->once())->method('howLost')->with('pad-1', $accessMode, 5)->willReturn(PadPresence::Behind);
			$sessions = $this->createMock(PadSessionService::class);
			$sessions->expects($this->never())->method('createProtectedOpenContext');
			try {
				$this->buildService(padSessionService: $sessions, padLifecycle: $lifecycle)->open($this->pad('pad-1', $accessMode, false, '', 5), false, 'token');
				$this->fail($accessMode . ': opened');
			} catch (PadLostException) {
				$this->addToAssertionCount(1);
			}
		}

		$lifecycle = $this->createMock(ManagedPadLifecycle::class);
		$lifecycle->expects($this->never())->method('howLost');
		$this->buildService(padLifecycle: $lifecycle)->open($this->pad('pad-1', BindingService::ACCESS_PROTECTED, false, '', 5), true, 'token');
		$this->buildService(padLifecycle: $lifecycle)->open($this->pad('pad-1', BindingService::ACCESS_PUBLIC, false, '', 5), true, 'token');
	}

	private function buildService(
		?EtherpadClient $etherpadClient = null,
		?PadSessionService $padSessionService = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
		?ManagedPadLifecycle $padLifecycle = null,
	): PublicPadOpenService {
		return new PublicPadOpenService(
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$padLifecycle ?? $this->createMock(ManagedPadLifecycle::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			$padSessionService ?? $this->createMock(PadSessionService::class),
		);
	}

	/**
	 * Nothing revokes a share session, so its length is the whole exposure
	 * of a withdrawn share. Both numbers are pinned, not just their order:
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

	private function pad(string $padId, string $accessMode, bool $isExternal = false, string $padUrl = '', int $snapshotRev = -1): ParsedPadFile {
		return new ParsedPadFile([], '', $padId, $accessMode, $padUrl, $isExternal, $snapshotRev);
	}
}
