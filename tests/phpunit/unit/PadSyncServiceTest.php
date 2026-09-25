<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadSyncServiceTest extends TestCase {
	public function testSyncStatusReturnsUnavailableForExternalPads(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('frontmatter')->willReturn(new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'ext.remote',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
			body: '',
			padId: 'ext.remote',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/remote',
			isExternal: true,
			snapshotRev: -1,
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('assertConsistentMapping');

		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService)
			->syncStatusById('alice', 138);

		$this->assertSame(PadSyncService::STATUS_UNAVAILABLE, $result->status);
		$this->assertNull($result->inSync);
		$this->assertSame('external_no_revision', $result->reason);
	}

	/**
	 * A row that still waits reaches the caller as it was thrown, for a sync
	 * and for its status alike: its code is the error mapper's to give, and
	 * a service that wrapped it would take the code away.
	 */
	public function testAWaitingBindingReachesTheCallerAsItIs(): void {
		$waiting = new WaitingBindingException('Pad binding is not active.');
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Notes.pad');
		$file->method('getContent')->willReturn('frontmatter');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile([], '', 'pad-a', BindingService::ACCESS_PUBLIC, '', false, 3));
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping')->willThrowException($waiting);
		$service = $this->buildService($padFileService, $userNodeResolver, $bindingService);

		foreach (['sync' => static fn () => $service->syncById('alice', 138, false), 'status' => static fn () => $service->syncStatusById('alice', 138)] as $case => $call) {
			try {
				$call();
				$this->fail($case . ': nothing thrown');
			} catch (WaitingBindingException $e) {
				$this->assertSame($waiting, $e, $case);
			}
		}
	}

	public function testSyncStatusReportsOutOfSyncInternalPad(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'g.ABC$pad',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
			body: '',
			padId: 'g.ABC$pad',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
			snapshotRev: 3,
		);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($parsedPad);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'g.ABC$pad', BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('getRevisionsCount')
			->with('g.ABC$pad')
			->willReturn(5);

		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService, $etherpadClient)
			->syncStatusById('alice', 138);

		$this->assertSame(PadSyncService::STATUS_OUT_OF_SYNC, $result->status);
		$this->assertFalse($result->inSync);
		$this->assertSame(3, $result->snapshotRev);
		$this->assertSame(5, $result->currentRev);
	}

	/**
	 * An external pad written before both sections were always present keeps
	 * its section-less body until something rewrites it. A body that was
	 * ambiguous read back short, so it no longer matches the remote text and
	 * the next sync rewrites it into the current shape - a body that read
	 * back correctly is left alone rather than churned.
	 */
	public function testAnAmbiguousLegacyExternalSnapshotIsRewrittenOnTheNextSync(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Remote.pad');
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Remote.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: ['pad_id' => 'ext.remote', 'access_mode' => BindingService::ACCESS_PUBLIC],
			body: "[TEXT]\nhallo\n[HTML-BEGIN]\nwelt\n[HTML-END]",
			padId: 'ext.remote',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/remote',
			isExternal: true,
			snapshotRev: 4,
		);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($parsedPad);
		// The legacy body read back short - the markers took part of the text.
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'hallo', 'html' => '']);
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with($this->identicalTo($parsedPad), new PadSnapshot("hallo\n[HTML-BEGIN]\nwelt\n[HTML-END]", '', 5))
			->willReturn('rewritten');

		$externalPadExportFetcher = $this->createMock(ExternalPadExportFetcher::class);
		$externalPadExportFetcher->method('normalizeAndFetchExternalPublicPadText')
			->willReturn([
				'origin' => 'https://pad.example.test',
				'pad_id' => 'remote',
				'pad_url' => 'https://pad.example.test/p/remote',
				'text' => "hallo\n[HTML-BEGIN]\nwelt\n[HTML-END]",
			]);

		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->expects($this->once())
			->method('putContentWithSyncLockRetry')
			->with($file, 'rewritten')
			->willReturn(1);

		$result = $this->buildService(
			$padFileService,
			$userNodeResolver,
			$this->createMock(BindingService::class),
			$this->createMock(EtherpadClient::class),
			$lockRetryService,
			$externalPadExportFetcher,
		)->syncById('alice', 138, true);

		$this->assertSame('updated', $result->status);
	}

	/** The other half of that: a legacy body that reads back whole is left alone. */
	public function testAnUnambiguousLegacyExternalSnapshotIsLeftAlone(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Remote.pad');
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Remote.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: ['pad_id' => 'ext.remote', 'access_mode' => BindingService::ACCESS_PUBLIC],
			body: "[TEXT]\nremote text",
			padId: 'ext.remote',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/remote',
			isExternal: true,
			snapshotRev: 4,
		);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($parsedPad);
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'remote text', 'html' => '']);
		$padFileService->expects($this->never())->method('withExportSnapshot');

		$externalPadExportFetcher = $this->createMock(ExternalPadExportFetcher::class);
		$externalPadExportFetcher->method('normalizeAndFetchExternalPublicPadText')
			->willReturn([
				'origin' => 'https://pad.example.test',
				'pad_id' => 'remote',
				'pad_url' => 'https://pad.example.test/p/remote',
				'text' => 'remote text',
			]);

		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->expects($this->never())->method('putContentWithSyncLockRetry');

		$result = $this->buildService(
			$padFileService,
			$userNodeResolver,
			$this->createMock(BindingService::class),
			$this->createMock(EtherpadClient::class),
			$lockRetryService,
			$externalPadExportFetcher,
		)->syncById('alice', 138, true);

		$this->assertSame('unchanged', $result->status);
	}

	public function testSyncExternalPadStoresOnlyTextSnapshot(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Remote.pad');
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Remote.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'ext.remote',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
			body: '',
			padId: 'ext.remote',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/remote',
			isExternal: true,
			snapshotRev: 4,
		);
		$padFileService->method('readPad')->with('frontmatter')->willReturn($parsedPad);
		$padFileService->expects($this->once())
			->method('getSnapshotPartsFromBody')
			->with($parsedPad->body)
			->willReturn(['text' => 'previous text', 'html' => '']);
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with($this->identicalTo($parsedPad), new PadSnapshot("remote text\nfrom export", '', 5))
			->willReturn('updated-frontmatter');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('assertConsistentMapping');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$externalPadExportFetcher = $this->createMock(ExternalPadExportFetcher::class);
		$externalPadExportFetcher->expects($this->once())
			->method('normalizeAndFetchExternalPublicPadText')
			->with('https://pad.example.test/p/remote')
			->willReturn([
				'origin' => 'https://pad.example.test',
				'pad_id' => 'remote',
				'pad_url' => 'https://pad.example.test/p/remote',
				'text' => "remote text\nfrom export",
			]);

		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->expects($this->once())
			->method('putContentWithSyncLockRetry')
			->with($file, 'updated-frontmatter')
			->willReturn(1);

		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService, $etherpadClient, $lockRetryService, $externalPadExportFetcher)
			->syncById('alice', 138, true);

		$this->assertSame(PadSyncService::STATUS_UPDATED, $result->status);
		$this->assertSame(138, $result->fileId);
		$this->assertSame('ext.remote', $result->padId);
		$this->assertTrue($result->external);
		$this->assertTrue($result->forced);
		$this->assertSame(5, $result->snapshotRev);
		$this->assertSame(1, $result->lockRetries);
	}

	/**
	 * A restore from the snapshot points the file at a new pad, whose
	 * revisions start again from nothing. Carried over, the old pad's count
	 * had the regular sync take the new pad's edits for ones the file
	 * already held, until a forced sync.
	 */
	public function testTheFirstSyncAfterARestoreFromTheSnapshotFetchesTheNewPad(): void {
		$formatter = new PadFileService(new FixedClock());
		$trashed = $formatter->readPad($formatter->withExportSnapshot(
			$formatter->readPad($formatter->buildInitialDocument(138, 'old-pad', BindingService::ACCESS_PUBLIC)),
			new PadSnapshot('old text', '', 500),
		));
		$restored = $formatter->withRestoredSnapshot($trashed, 'old text', '', 'r-new-pad', 'https://pad.example.test/p/r-new-pad');

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Notes.pad');
		$file->method('getContent')->willReturn($restored);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Notes.pad');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->with('r-new-pad')->willReturn(3);
		$etherpadClient->expects($this->once())->method('getText')->with('r-new-pad')->willReturn('new edit');
		$etherpadClient->method('getHTML')->willReturn('<p>new edit</p>');

		$written = null;
		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->expects($this->once())
			->method('putContentWithSyncLockRetry')
			->willReturnCallback(static function (File $node, string $content) use (&$written): int {
				$written = $content;
				return 0;
			});

		$result = $this->buildService($formatter, $userNodeResolver, null, $etherpadClient, $lockRetryService)
			->syncById('alice', 138, false);

		$this->assertSame(PadSyncService::STATUS_UPDATED, $result->status);
		$this->assertSame(3, $formatter->readPad((string)$written)->snapshotRev);
		$this->assertSame('new edit', $formatter->getSnapshotPartsFromBody($formatter->readPad((string)$written)->body)['text']);
	}

	/**
	 * An external .pad without a link is the link's problem, not Etherpad
	 * failing; a file that is no .pad is refused as such.
	 */
	public function testSyncRefusesWhatIsNoPadToSyncForWhatItIs(): void {
		foreach ([
			'no link' => ['Remote.pad', ExternalPadException::class],
			'no .pad' => ['Notes.txt', NotAPadFileException::class],
		] as $case => [$name, $expected]) {
			$file = $this->createMock(File::class);
			$file->method('getName')->willReturn($name);
			$file->method('getContent')->willReturn('frontmatter');
			$userNodeResolver = $this->createMock(UserNodeResolver::class);
			$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
			$userNodeResolver->method('toUserAbsolutePath')->willReturn('/' . $name);
			$padFileService = $this->createMock(PadFileService::class);
			$padFileService->method('readPad')->willReturn(new ParsedPadFile(['pad_id' => 'ext.remote'], '', 'ext.remote', BindingService::ACCESS_PUBLIC, '', true, -1));

			try {
				$this->buildService($padFileService, $userNodeResolver)->syncById('alice', 138, false);
				$this->fail($case . ': synced.');
			} catch (\Throwable $e) {
				$this->assertSame($expected, $e::class, $case);
			}
		}
	}

	private function buildService(
		?PadFileService $padFileService = null,
		?UserNodeResolver $userNodeResolver = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
		?PadFileLockRetryService $lockRetryService = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
	): PadSyncService {
		return new PadSyncService(
			$padFileService ?? $this->createMock(PadFileService::class),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$lockRetryService ?? $this->createMock(PadFileLockRetryService::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
