<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadOpenService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\RestoreService;
use OCA\EtherpadNextcloud\Service\SettleOutcome;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Tests\Support\SettlesOnOpen;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadOpenServiceTest extends TestCase {
	use SettlesOnOpen;

	public function testOpenByPathRejectsEmptyNormalizedPath(): void {
		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeViewerFilePath')
			->with(" \t")
			->willReturn('');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->never())->method('resolveUserFileNodeByPath');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildService($padPaths, $userNodeResolver)
			->openByPath('alice', 'Alice', " \t");
	}

	/**
	 * A share that grants no write permission must not hand out a way to
	 * edit. Nextcloud's own read-only share stops at the file; the pad
	 * lives on another host, where the session and the URL are the whole of
	 * the access — so withholding them is the only thing that makes "view
	 * only" mean anything there.
	 */
	public function testGivesAProtectedPadAsAReadOnlyViewWhenTheShareIsReadOnly(): void {
		$session = $this->createMock(PadSessionService::class);
		$session->expects($this->never())->method('createProtectedOpenContext');

		// Nor any address. The content arrives over the separate content
		// endpoint, so nothing here needs the pad server — asking it
		// anything would put that dependency back into the open.
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('buildPadUrl');
		$client->expects($this->never())->method('getReadOnlyPadUrl');

		$target = $this->openWith(
			BindingService::ACCESS_PROTECTED,
			updateable: false,
			padSessionService: $session,
			etherpadClient: $client,
		);

		$this->assertTrue($target->isReadOnlyView);
		$this->assertSame('', $target->url, 'a viewer must not be given a pad to open');
		$this->assertSame('', $target->cookieHeader, 'and no session to open it with');
		// The response ships this as `pad_url`. Withholding one address and
		// handing back the same one under another key would undo the whole
		// thing, and no client reads it today — which is why it would go
		// unnoticed.
		$this->assertSame('', $target->padUrl, 'nor the address under a second name');
	}

	/** With write permission, the same pad opens as it always did. */
	public function testGivesAProtectedPadAsAnEditorWhenTheShareAllowsWriting(): void {
		$session = $this->createMock(PadSessionService::class);
		$session->expects($this->once())
			->method('createProtectedOpenContext')
			->willReturn([
				'url' => 'https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1',
				'cookie' => [
					'name' => 'sessionID', 'value' => 's.x', 'expires' => 0, 'path' => '/',
					'domain' => '', 'secure' => true, 'http_only' => false, 'same_site' => 'lax',
				],
			]);

		$target = $this->openWith(
			BindingService::ACCESS_PROTECTED,
			updateable: true,
			padSessionService: $session,
		);

		$this->assertFalse($target->isReadOnlyView);
		$this->assertSame('https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1', $target->url);
	}

	/**
	 * A public pad has no session to withhold, so the editable URL is the
	 * whole of the access. Etherpad's own read-only view is the one thing
	 * that cannot be typed into.
	 */
	public function testGivesAPublicPadItsReadOnlyUrlWhenTheShareIsReadOnly(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('buildPadUrl')->willReturn('https://pad.example.test/p/pad-1');
		$client->expects($this->once())
			->method('getReadOnlyPadUrl')
			->with('pad-1')
			->willReturn('https://pad.example.test/p/r.readonlyid');

		$target = $this->openWith(
			BindingService::ACCESS_PUBLIC,
			updateable: false,
			etherpadClient: $client,
			padId: 'pad-1',
		);

		$this->assertSame('https://pad.example.test/p/r.readonlyid', $target->url);
	}

	/**
	 * A pad server that cannot say what the read-only URL is must not turn
	 * a view-only open into a failure — the writable users on the same pad
	 * would keep working, so the outage would present as "only the people
	 * who may not edit cannot open it".
	 */
	public function testFallsBackToTheReadOnlyViewWhenTheReadOnlyUrlCannotBeResolved(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('buildPadUrl')->willReturn('https://pad.example.test/p/pad-1');
		$client->method('getReadOnlyPadUrl')
			->willThrowException(new EtherpadClientException('Connection timed out'));

		$target = $this->openWith(
			BindingService::ACCESS_PUBLIC,
			updateable: false,
			etherpadClient: $client,
			padId: 'pad-1',
		);

		$this->assertTrue($target->isReadOnlyView);
		$this->assertSame('', $target->url, 'no pad to open');
		$this->assertSame('', $target->padUrl, 'and not under the other name either');
		$this->assertSame('', $target->cookieHeader, 'and no session');
	}

	/**
	 * A file whose row still waits once the open has tried to decide it
	 * opens on no pad: the open hands out neither a session nor an
	 * address, and lets the mapper say why.
	 */
	public function testAWaitingBindingReachesTheCallerAsItIs(): void {
		$waiting = new WaitingBindingException('Pad binding is not active.');
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('assertConsistentMapping')->willThrowException($waiting);
		$bindings->method('findByFileId')->willReturn(self::waitingRow(138, 'pad-1', BindingService::ACCESS_PUBLIC));
		$restores = $this->createMock(RestoreService::class);
		$restores->expects($this->once())->method('settleOpenedFile')->willReturn(SettleOutcome::Unanswered);
		$session = $this->createMock(PadSessionService::class);
		$session->expects($this->never())->method($this->anything());
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method($this->anything());

		$this->expectExceptionObject($waiting);

		$this->openWith(
			BindingService::ACCESS_PROTECTED,
			updateable: true,
			padSessionService: $session,
			etherpadClient: $client,
			bindingService: $bindings,
			restoreService: $restores,
		);
	}

	/**
	 * A file whose row waits is decided by the open, with the file it
	 * opened, and opens once the row has taken its pad back.
	 */
	public function testAWaitingRowTheOpenTakesBackOpens(): void {
		$bindings = $this->createMock(BindingService::class);
		$calls = 0;
		$bindings->expects($this->exactly(2))->method('assertConsistentMapping')->willReturnCallback(static function () use (&$calls): void {
			if (++$calls === 1) {
				throw new WaitingBindingException('Pad binding is not active.');
			}
		});
		$bindings->method('findByFileId')->willReturn(self::waitingRow(138, 'pad-1', BindingService::ACCESS_PUBLIC));
		$restores = $this->createMock(RestoreService::class);
		$restores->expects($this->once())
			->method('settleOpenedFile')
			->with($this->callback(static fn (File $file): bool => $file->getId() === 138), $this->anything(), $this->callback(static fn (ParsedPadFile $pad): bool => $pad->padId === 'pad-1'))
			->willReturn(SettleOutcome::Settled);
		$client = $this->createMock(EtherpadClient::class);
		$client->method('buildPadUrl')->willReturn('https://pad.example.test/p/pad-1');

		$target = $this->openWith(
			BindingService::ACCESS_PUBLIC,
			updateable: true,
			etherpadClient: $client,
			padId: 'pad-1',
			bindingService: $bindings,
			restoreService: $restores,
		);

		$this->assertSame('https://pad.example.test/p/pad-1', $target->url);
	}

	/** The rule for an external pad's metadata is ParsedPadFile::externalPadUrl()'s; the open holds it. */
	public function testAForeignPadWithBrokenMetadataIsTheLinksProblem(): void {
		$this->expectException(ExternalPadException::class);
		$this->openWith(BindingService::ACCESS_PROTECTED, updateable: true, padId: 'ext.remote', isExternal: true);
	}

	private function openWith(
		string $accessMode,
		bool $updateable,
		?PadSessionService $padSessionService = null,
		?EtherpadClient $etherpadClient = null,
		string $padId = 'g.ABCDEFGHIJKLMNOP$pad-1',
		?BindingService $bindingService = null,
		?RestoreService $restoreService = null,
		bool $isExternal = false,
	): \OCA\EtherpadNextcloud\Service\PadOpenTarget {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);
		$file->method('getContent')->willReturn('frontmatter');
		$file->method('isUpdateable')->willReturn($updateable);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => $padId, 'access_mode' => $accessMode],
			body: 'snapshot text',
			padId: $padId,
			accessMode: $accessMode,
			padUrl: '',
			isExternal: $isExternal,
			snapshotRev: -1,
		));

		$client = $etherpadClient ?? $this->createMock(EtherpadClient::class);
		$service = new PadOpenService(
			$padFileService,
			$this->createMock(PathNormalizer::class),
			$userNodeResolver,
			new PadFileLockRetryService(static function (int $delay): void {
			}),
			$this->settleOnOpen($bindingService ?? $this->createMock(BindingService::class), $restoreService),
			$client,
			$this->createMock(ExternalPadExportFetcher::class),
			$padSessionService ?? $this->createMock(PadSessionService::class),
			$this->createMock(LoggerInterface::class),
		);

		return $service->openById('alice', 'Alice', 138);
	}

	private function buildService(PathNormalizer $padPaths, UserNodeResolver $userNodeResolver): PadOpenService {
		$padFileService = $this->createMock(PadFileService::class);
		return new PadOpenService(
			$padFileService,
			$padPaths,
			$userNodeResolver,
			$this->createMock(PadFileLockRetryService::class),
			$this->settleOnOpen($this->createMock(BindingService::class)),
			$this->createMock(EtherpadClient::class),
			$this->createMock(ExternalPadExportFetcher::class),
			$this->createMock(PadSessionService::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
