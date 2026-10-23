<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingMismatchException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadFileNotWritableException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\PadLostException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSyncLock;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\RecentSyncs;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Tests\Support\BuildsLinkCaches;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryCache;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryLockingProvider;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\IMemcache;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadSyncServiceTest extends TestCase {
	use BuildsLinkCaches;

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
	 * A row that does not match reaches the caller as it was thrown, for a
	 * sync and for its status alike: its code is the error mapper's to give,
	 * and a service that wrapped it would take the code away.
	 */
	public function testABindingErrorReachesTheCallerAsItIs(): void {
		$refused = new PadLostException('Etherpad has lost the pad of this file.');
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getName')->willReturn('Notes.pad');
		$file->method('getContent')->willReturn('frontmatter');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile([], '', 'pad-a', BindingService::ACCESS_PUBLIC, '', false, 3));
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping')->willThrowException($refused);
		$service = $this->buildService($padFileService, $userNodeResolver, $bindingService);

		foreach (['sync' => static fn () => $service->syncById('alice', 138, false), 'status' => static fn () => $service->syncStatusById('alice', 138)] as $case => $call) {
			try {
				$call();
				$this->fail($case . ': nothing thrown');
			} catch (PadLostException $e) {
				$this->assertSame($refused, $e, $case);
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
		$file->method('isUpdateable')->willReturn(true);
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

		$lockRetryService = $this->writeMockedLockRetry();
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
		$file->method('isUpdateable')->willReturn(true);
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

		$lockRetryService = $this->writeMockedLockRetry();
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
		$file->method('isUpdateable')->willReturn(true);
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
			->with($parsedPad->body())
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

		$lockRetryService = $this->writeMockedLockRetry();
		$lockRetryService->expects($this->once())
			->method('putContentWithSyncLockRetry')
			->with($file, 'updated-frontmatter')
			->willReturn(1);

		$cache = new InMemoryCache();
		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService, $etherpadClient, $lockRetryService, $externalPadExportFetcher, null, $this->recentSyncs($cache))
			->syncById('alice', 138, true);

		$this->assertSame(PadSyncService::STATUS_UPDATED, $result->status);
		$this->assertSame(138, $result->fileId);
		$this->assertSame('ext.remote', $result->padId);
		$this->assertTrue($result->external);
		$this->assertTrue($result->forced);
		$this->assertSame(5, $result->snapshotRev);
		$this->assertSame(1, $result->lockRetries);
		$this->assertSame(['138/120' => 60], $cache->ttls, 'a look at a pad on another server counts as one');
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
		$file->method('isUpdateable')->willReturn(true);
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
		$lockRetryService = $this->writeMockedLockRetry();
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
		$this->assertSame('new edit', $formatter->getSnapshotPartsFromBody($formatter->readPad((string)$written)->body())['text']);
	}

	/**
	 * A forced sync writes a pad behind the snapshot - a file a restore in
	 * 1.1.0-beta.1 left kept the old pad's revision count - but not one
	 * Etherpad made anew at revision 0 with other text than the file saved:
	 * written over the file, the saved content would be gone from it, and
	 * the open would no longer offer a new pad from it. Which pad counts as
	 * made anew is ManagedPadLifecycleTest's to say; here, that the sync
	 * asks with what Etherpad and the file hold.
	 */
	public function testAForcedSyncLeavesThePadEtherpadMadeAnewToTheFile(): void {
		$formatter = new PadFileService(new FixedClock());
		$cases = [
			'made anew on a visit, with the default text' => [12, 'Meeting notes', 0, "Welcome to Etherpad!\n", PadLostException::class],
			'behind the snapshot, written into' => [500, 'old text', 3, 'new edit', PadSyncService::STATUS_UPDATED],
			'a new file, nothing saved' => [-1, '', 0, "Welcome to Etherpad!\n", PadSyncService::STATUS_UPDATED],
		];
		foreach ($cases as $case => [$snapshotRev, $saved, $revisions, $padText, $expected]) {
			$content = $formatter->buildInitialDocument(138, 'pad-a', BindingService::ACCESS_PUBLIC);
			if ($snapshotRev >= 0) {
				$content = $formatter->withExportSnapshot($formatter->readPad($content), new PadSnapshot($saved, $saved === '' ? '' : '<p>' . $saved . '</p>', $snapshotRev));
			}
			$file = $this->createMock(File::class);
			$file->method('isUpdateable')->willReturn(true);
			$file->method('getName')->willReturn('Notes.pad');
			$file->method('getContent')->willReturn($content);
			$userNodeResolver = $this->createMock(UserNodeResolver::class);
			$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
			$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Notes.pad');
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->method('getRevisionsCount')->willReturn($revisions);
			$etherpadClient->method('getText')->willReturn($padText);
			$etherpadClient->method('getHTML')->willReturn('<p>' . trim($padText) . '</p>');
			$written = null;
			$lockRetryService = $this->writeMockedLockRetry();
			$lockRetryService->method('putContentWithSyncLockRetry')->willReturnCallback(static function (File $node, string $content) use (&$written): int {
				$written = $content;
				return 0;
			});

			try {
				$status = $this->buildService($formatter, $userNodeResolver, null, $etherpadClient, $lockRetryService)->syncById('alice', 138, true)->status;
			} catch (PadLostException $e) {
				$status = $e::class;
			}

			$this->assertSame($expected, $status, $case);
			$this->assertSame($expected === PadSyncService::STATUS_UPDATED, $written !== null, $case);
		}
	}

	/**
	 * A sync may write the file, so a reader is refused before the sync
	 * knows whether it would write - before its lock, the file and Etherpad,
	 * whether or not the sync is forced.
	 */
	public function testAReaderWhoMayNotChangeTheFileIsRefusedBeforeAnythingIsAsked(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(false);
		$file->method('getName')->willReturn('Notes.pad');
		$file->expects($this->never())->method('getContent');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Notes.pad');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method($this->anything());
		$lockRetryService = $this->writeMockedLockRetry();
		$lockRetryService->expects($this->never())->method('putContentWithSyncLockRetry');
		$syncLock = $this->createMock(PadSyncLock::class);
		$syncLock->expects($this->never())->method('hold');

		$service = $this->buildService(new PadFileService(new FixedClock()), $userNodeResolver, null, $etherpadClient, $lockRetryService, null, $syncLock);

		foreach ([false, true] as $force) {
			try {
				$service->syncById('bob', 138, $force);
				$this->fail('force=' . var_export($force, true) . ': synced.');
			} catch (PadFileNotWritableException) {
				$this->addToAssertionCount(1);
			}
		}
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
			$file->method('isUpdateable')->willReturn($case !== 'no .pad');
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

	/**
	 * A recovery that moves the row onto a new pad while the sync waits for
	 * the file's lock stops the sync's next write: the old pad's text would
	 * go over the file the recovery wrote, and file and row name two pads.
	 */
	public function testARowMovedWhileTheSyncWaitsStopsItsWrite(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getName')->willReturn('Test.pad');
		$file->method('getContent')->willReturn('frontmatter');
		$file->expects($this->once())->method('putContent')->willThrowException(new LockedException('locked'));
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: [],
			body: '',
			padId: 'g.ABC$pad',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
			snapshotRev: 4,
		));
		$padFileService->method('withExportSnapshot')->willReturn('updated');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(5);
		$etherpadClient->method('getText')->willReturn('text');
		$etherpadClient->method('getHTML')->willReturn('<p>text</p>');
		$checks = 0;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping')->willReturnCallback(static function () use (&$checks): void {
			// As the sync starts, before its first write, and after its wait.
			if (++$checks === 3) {
				throw new BindingMismatchException('Binding pad ID mismatch.');
			}
		});
		$lockRetryService = new PadFileLockRetryService(static function (int $delay): void {
		});

		$this->expectException(BindingMismatchException::class);
		$this->buildService($padFileService, $userNodeResolver, $bindingService, $etherpadClient, $lockRetryService)->syncById('alice', 138, false);
	}

	/**
	 * The closing sync of a page arrives while a periodic one runs, between
	 * its question to Etherpad and its write. Run beside it, the closing
	 * sync wrote revision 2 and the periodic one then put its revision 1
	 * over it. Held off, it answers locked here - in a request it waits for
	 * the other to let go, a single PHP process cannot show that
	 * (PadSyncLockTest does) - and the next sync writes revision 2.
	 */
	public function testASyncThatArrivesWhileAnotherRunsCannotWriteBetweenItsQuestionAndItsWrite(): void {
		$formatter = new PadFileService(new FixedClock());
		$content = $formatter->withExportSnapshot(
			$formatter->readPad($formatter->buildInitialDocument(138, 'pad-a', BindingService::ACCESS_PUBLIC)),
			new PadSnapshot('text 0', '<p>text 0</p>', 0),
		);
		$written = [];
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getName')->willReturn('Notes.pad');
		$file->method('getContent')->willReturnCallback(static function () use (&$content): string {
			return $content;
		});
		$file->method('putContent')->willReturnCallback(static function (string $next) use (&$content, &$written, $formatter): void {
			$content = $next;
			$written[] = $formatter->readPad($next)->snapshotRev;
		});
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Notes.pad');
		$revisions = 1;
		$typedOn = false;
		$closing = null;
		$service = null;
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function () use (&$revisions): int {
			return $revisions;
		});
		$etherpadClient->method('getText')->willReturnCallback(static function () use (&$revisions, &$typedOn, &$closing, &$service): string {
			$text = 'text ' . $revisions;
			if (!$typedOn) {
				$typedOn = true;
				$revisions = 2;
				$closing = $service->syncById('alice', 138, true);
			}
			return $text;
		});
		$etherpadClient->method('getHTML')->willReturn('<p>text</p>');
		$waits = 0;
		$syncLock = new PadSyncLock(new InMemoryLockingProvider(), $this->createMock(LoggerInterface::class), static function (int $delay) use (&$waits): void {
			$waits++;
		});
		$service = $this->buildService($formatter, $userNodeResolver, null, $etherpadClient, null, null, $syncLock);

		$periodic = $service->syncById('alice', 138, false);

		$this->assertSame(PadSyncService::STATUS_UPDATED, $periodic->status);
		$this->assertSame(PadSyncService::STATUS_LOCKED, $closing->status);
		$this->assertSame($waits, $closing->lockRetries);
		$this->assertGreaterThan(0, $waits);
		$this->assertSame([1], $written);

		$next = $service->syncById('alice', 138, false);

		$this->assertSame(PadSyncService::STATUS_UPDATED, $next->status);
		$this->assertSame([1, 2], $written);
		$this->assertSame('text 2', $formatter->getSnapshotPartsFromBody($formatter->readPad($content)->body())['text']);
	}

	/** Locked through every wait of the first read: nothing asked, nothing written. */
	public function testAFileLockedThroughTheFirstReadAnswersLockedWithItsWaits(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getName')->willReturn('Notes.pad');
		$file->expects($this->exactly(4))->method('getContent')->willThrowException(new LockedException('locked'));
		$file->expects($this->never())->method('putContent');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Notes.pad');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('getRevisionsCount');

		$cache = new InMemoryCache();
		$result = $this->buildService(null, $userNodeResolver, null, $etherpadClient, null, null, null, $this->recentSyncs($cache))->syncById('alice', 138, true);

		$this->assertSame(PadSyncService::STATUS_LOCKED, $result->status);
		$this->assertSame(3, $result->lockRetries);
		$this->assertTrue($result->retryable);
		$this->assertSame([], $cache->ttls, 'a file it could not read is not counted as looked at');
	}

	/**
	 * Viewers sync on timers of their own: a timed sync runs where it claims
	 * the file, and one after it within half the interval answers deferred,
	 * asking Etherpad nothing. Any other sync runs - one off the timer, as
	 * the API is called, or a forced one.
	 */
	public function testATimedSyncRunsOnlyWhereItClaimsTheFile(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->exactly(3))->method('getRevisionsCount')->willReturn(3);
		$etherpadClient->method('getText')->willReturn('typed');
		$etherpadClient->method('getHTML')->willReturn('<p>typed</p>');
		$cache = new InMemoryCache();
		$service = $this->pacedService($etherpadClient, $cache);

		$this->assertSame(PadSyncService::STATUS_UNCHANGED, $service->syncById('alice', 138, false, true)->status);
		$this->assertSame(['138/120' => 60], $cache->ttls, 'claimed for half the interval of 120 seconds');
		$deferred = $service->syncById('alice', 138, false, true);
		$this->assertSame(PadSyncService::STATUS_DEFERRED, $deferred->status);
		$this->assertSame([138, '', false, false], [$deferred->fileId, $deferred->padId, $deferred->external, $deferred->forced]);
		$this->assertSame(PadSyncService::STATUS_UNCHANGED, $service->syncById('alice', 138, false)->status, 'off the timer');
		$this->assertSame(PadSyncService::STATUS_UNCHANGED, $service->syncById('alice', 138, true, true)->status, 'forced');
	}

	/** A sync off the timer, or a forced one, that looked at the file holds the timed syncs off as a claim does. */
	public function testALookOffTheTimerHoldsTheTimedSyncsOff(): void {
		foreach (['off the timer' => false, 'forced' => true] as $case => $force) {
			$etherpadClient = $this->createMock(EtherpadClient::class);
			$etherpadClient->expects($this->once())->method('getRevisionsCount')->willReturn(3);
			$etherpadClient->method('getText')->willReturn('typed');
			$etherpadClient->method('getHTML')->willReturn('<p>typed</p>');
			$cache = new InMemoryCache();
			$service = $this->pacedService($etherpadClient, $cache);

			$this->assertSame(PadSyncService::STATUS_UNCHANGED, $service->syncById('alice', 138, $force)->status, $case);
			$this->assertSame(['138/120' => 60], $cache->ttls, $case);
			$this->assertSame(PadSyncService::STATUS_DEFERRED, $service->syncById('alice', 138, false, true)->status, $case);
		}
	}

	/** A timed sync notes nothing after its look: its claim, from its start, holds the others off. */
	public function testATimedSyncOnlyClaims(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);
		$cache = $this->createMock(IMemcache::class);
		$cache->expects($this->once())->method('add')->willReturn(true);
		$cache->expects($this->never())->method('set');

		$this->assertSame(PadSyncService::STATUS_UNCHANGED, $this->pacedService($etherpadClient, $cache)->syncById('alice', 138, false, true)->status);
	}

	/**
	 * A claim stands for a try: one whose look fails - Etherpad not
	 * answering, say - holds the timed syncs off for the rest of the half
	 * interval too, sparing Etherpad. A forced sync still runs.
	 */
	public function testAFailedTryHoldsTheTimedSyncsOffButNotAForcedOne(): void {
		$answers = 0;
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturnCallback(static function () use (&$answers): int {
			if (++$answers === 1) {
				throw new \RuntimeException('Etherpad did not answer');
			}
			return 3;
		});
		$etherpadClient->method('getText')->willReturn('typed');
		$etherpadClient->method('getHTML')->willReturn('<p>typed</p>');
		$service = $this->pacedService($etherpadClient, new InMemoryCache());

		try {
			$service->syncById('alice', 138, false, true);
			self::fail('The failure should reach the caller.');
		} catch (\RuntimeException $e) {
			$this->assertSame('Etherpad did not answer', $e->getMessage());
		}
		$this->assertSame(PadSyncService::STATUS_DEFERRED, $service->syncById('alice', 138, false, true)->status);
		$this->assertSame(PadSyncService::STATUS_UNCHANGED, $service->syncById('alice', 138, true)->status);
	}

	/**
	 * What may not be synced is refused before any claim: a timed sync of a
	 * file id someone else has open tells nothing of it.
	 */
	public function testARefusalComesBeforeTheClaim(): void {
		$formatter = new PadFileService(new FixedClock());
		foreach ([
			'no such file' => [null, NotFoundException::class],
			'no pad' => ['Notes.txt', NotAPadFileException::class],
			'a reader' => ['Notes.pad', PadFileNotWritableException::class],
		] as $case => [$name, $refusal]) {
			$userNodeResolver = $this->createMock(UserNodeResolver::class);
			if ($name === null) {
				$userNodeResolver->method('resolveUserFileNodeById')->willThrowException(new NotFoundException('gone'));
			} else {
				$file = $this->createMock(File::class);
				$file->method('isUpdateable')->willReturn($case !== 'a reader');
				$file->method('getName')->willReturn($name);
				$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
			}
			$cache = new InMemoryCache();
			$cache->set('138/120', '1');

			try {
				$this->buildService($formatter, $userNodeResolver, null, null, null, null, null, $this->recentSyncs($cache))->syncById('alice', 138, false, true);
				self::fail($case . ': should be refused');
			} catch (\Exception $e) {
				$this->assertInstanceOf($refusal, $e, $case);
			}
		}
	}

	/**
	 * A timed sync that claimed the file waits for the sync lock as any
	 * other does, and answers locked where it stays taken - a lock left by
	 * a request killed hard, say - with the line that says so. Its claim
	 * holds the next timed syncs off, so one at a time waits.
	 */
	public function testATimedSyncThatClaimedWaitsForTheLockLikeAnyOther(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getName')->willReturn('Notes.pad');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Notes.pad');
		$locks = new InMemoryLockingProvider();
		$locks->acquireLock('etherpad_nextcloud:sync:138', ILockingProvider::LOCK_EXCLUSIVE);
		$waits = 0;
		$syncLock = new PadSyncLock($locks, $this->createMock(LoggerInterface::class), static function (int $delay) use (&$waits): void {
			$waits++;
		});
		$service = $this->buildService(null, $userNodeResolver, null, null, null, null, $syncLock, $this->recentSyncs(new InMemoryCache()));

		$timed = $service->syncById('alice', 138, false, true);

		$this->assertSame(PadSyncService::STATUS_LOCKED, $timed->status);
		$this->assertTrue($timed->retryable);
		$this->assertGreaterThan(0, $waits);
		$waitsOfTheFirst = $waits;
		$this->assertSame(PadSyncService::STATUS_DEFERRED, $service->syncById('alice', 138, false, true)->status);
		$this->assertSame($waitsOfTheFirst, $waits, 'the next timed sync waits not at all');
	}

	/** A service for file 138, a protected pad whose snapshot holds revision 3, paced over $cache. */
	private function pacedService(EtherpadClient $etherpadClient, ICache $cache): PadSyncService {
		$formatter = new PadFileService(new FixedClock());
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getName')->willReturn('Notes.pad');
		$file->method('getContent')->willReturn($formatter->withExportSnapshot(
			$formatter->readPad($formatter->buildInitialDocument(138, 'pad-1', BindingService::ACCESS_PROTECTED)),
			new PadSnapshot('typed', '<p>typed</p>', 3),
		));
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Notes.pad');
		return $this->buildService($formatter, $userNodeResolver, null, $etherpadClient, null, null, null, $this->recentSyncs($cache));
	}

	/** Over $cache and an interval of 120 seconds. */
	private function recentSyncs(ICache $cache): RecentSyncs {
		$appConfig = $this->createMock(AppConfigService::class);
		$appConfig->method('getSyncIntervalSeconds')->willReturn(120);
		return new RecentSyncs($this->cacheFactoryFor($cache), $appConfig, $this->createMock(LoggerInterface::class));
	}

	private function buildService(
		?PadFileService $padFileService = null,
		?UserNodeResolver $userNodeResolver = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
		?PadFileLockRetryService $lockRetryService = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
		?PadSyncLock $syncLock = null,
		?RecentSyncs $recentSyncs = null,
	): PadSyncService {
		return new PadSyncService(
			$padFileService ?? $this->createMock(PadFileService::class),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$lockRetryService ?? new PadFileLockRetryService(static function (int $delay): void {
			}),
			$syncLock ?? new PadSyncLock(new InMemoryLockingProvider(), $this->createMock(LoggerInterface::class), static function (int $delay): void {
			}),
			$bindingService ?? $this->createMock(BindingService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			// Without a memory cache unless a test is about pacing: every sync runs.
			$recentSyncs ?? new RecentSyncs($this->noMemoryCache(), $this->createMock(AppConfigService::class), $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		);
	}

	/** Reads the file for real; the write is the test's to say. */
	private function writeMockedLockRetry(): PadFileLockRetryService&MockObject {
		return $this->getMockBuilder(PadFileLockRetryService::class)
			->setConstructorArgs([static function (int $delay): void {
			}])
			->onlyMethods(['putContentWithSyncLockRetry'])
			->getMock();
	}
}
