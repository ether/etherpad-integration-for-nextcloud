<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadMetadataServiceTest extends TestCase {
	public function testMetaByIdReturnsExternalPublicPadMetadata(): void {
		$file = $this->createConfiguredMock(File::class, [
			'getId' => 138,
			'getName' => 'External.pad',
			'getMimeType' => 'application/x-etherpad-nextcloud',
		]);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/External.pad');
		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->method('readContentWithOpenLockRetry')->with($file)->willReturn('frontmatter');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('frontmatter')->willReturn(new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'ext.remote',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
			body: '',
			padId: 'ext.remote',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/External',
			isExternal: true,
			snapshotRev: -1,
		));

		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://pad.example.test/p/External')
			->willReturn(['pad_url' => 'https://pad.example.test/p/External']);

		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, lockRetryService: $lockRetryService, externalPadExportFetcher: $fetcher)
			->metaById('alice', 138);

		$this->assertTrue($result->isPad);
		$this->assertTrue($result->isPadMime);
		$this->assertSame(138, $result->fileId);
		$this->assertSame('External.pad', $result->name);
		$this->assertSame('/External.pad', $result->path);
		$this->assertSame(BindingService::ACCESS_PUBLIC, $result->accessMode);
		$this->assertTrue($result->isExternal);
		$this->assertSame('ext.remote', $result->padId);
		$this->assertSame('https://pad.example.test/p/External', $result->padUrl());
		$this->assertSame('https://pad.example.test/p/External', $result->publicOpenUrl());
	}

	/** A file the format refuses still answers as a pad, with nothing of a pad it cannot name. */
	public function testResolveAnswersAFileItCannotReadWithoutItsPad(): void {
		$file = $this->createConfiguredMock(File::class, [
			'getId' => 138,
			'getName' => 'Broken.pad',
			'getMimeType' => 'application/x-etherpad-nextcloud',
			'getContent' => 'not a pad',
		]);
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Broken.pad');
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willThrowException(new \OCA\EtherpadNextcloud\Exception\PadFileFormatException('Invalid state in frontmatter.'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('The file gave no complete pad metadata.', $this->anything());

		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, logger: $logger)->resolve('alice', 138);

		$this->assertTrue($result->isPad);
		$this->assertSame(['', false, ''], [$result->accessMode, $result->isExternal, $result->publicOpenUrl()]);
	}

	public function testResolveReturnsFalseWhenFileIdIsMissing(): void {
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')
			->with('alice', 404)
			->willThrowException(new NotFoundException('missing'));

		$result = $this->buildService(userNodeResolver: $userNodeResolver)
			->resolve('alice', 404);

		$this->assertFalse($result->isPad);
		$this->assertSame(404, $result->fileId);
	}

	public function testResolveReturnsPublicOpenUrlForInternalPublicPad(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('g.ABC$pad')->willReturn('https://pad.example.test/p/g.ABC$pad');

		$result = $this->namingAPublicPad(true, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.ABC$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE)))
			->resolve('alice', 138);

		$this->assertTrue($result->isPad);
		$this->assertTrue($result->isPadMime);
		$this->assertSame(138, $result->fileId);
		$this->assertSame('/Named.pad', $result->path);
		$this->assertSame(BindingService::ACCESS_PUBLIC, $result->accessMode);
		$this->assertFalse($result->isExternal);
		$this->assertSame('https://pad.example.test/p/g.ABC$pad', $result->publicOpenUrl());
	}

	/**
	 * This endpoint hands out an address, and for a public pad the address
	 * is the whole of the access — so it has to ask the same question the
	 * open path asks, or the rule holds for one endpoint and is claimed of
	 * both.
	 */
	public function testResolveGivesAReadOnlyShareTheReadOnlyUrl(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('buildPadUrl');
		$etherpadClient->method('getReadOnlyPadUrl')->with('g.ABC$pad')
			->willReturn('https://pad.example.test/p/r.readonlyid');

		$result = $this->namingAPublicPad(false, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.ABC$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE)))
			->resolve('alice', 138);

		$this->assertSame('https://pad.example.test/p/r.readonlyid', $result->publicOpenUrl());
	}

	/**
	 * A failed read-only lookup must not fall back to the editable address.
	 * That is the moment the withholding matters most: the call meant to
	 * avoid handing it over is the one that just failed.
	 */
	public function testResolveGivesNoUrlWhenTheReadOnlyLookupFails(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('buildPadUrl');
		$etherpadClient->method('getReadOnlyPadUrl')
			->willThrowException(new \OCA\EtherpadNextcloud\Exception\EtherpadClientException('unavailable'));

		// `meta`, not `resolve`: the response ships pad_url from here, and
		// that is the field the editable address would survive in.
		$result = $this->namingAPublicPad(false, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.ABC$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE)))
			->metaById('alice', 138);

		$this->assertSame('', $result->publicOpenUrl(), 'no read-only address to give');
		$this->assertSame('', $result->padUrl(), 'and the editable one must not survive as the answer');
	}

	/**
	 * A copy names the original's pad, and a hand-written file any pad: with
	 * no row the file has none, whether it may be written or only read, and
	 * Etherpad is not asked about the one it names.
	 *
	 * @return iterable<string, array{bool}>
	 */
	public static function writable(): iterable {
		yield 'a file that may be written' => [true];
		yield 'a file that may only be read' => [false];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('writable')]
	public function testAFileWithNoRowAnswersNoPad(bool $updateable): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('buildPadUrl');
		$etherpadClient->expects($this->never())->method('getReadOnlyPadUrl');

		$result = $this->namingAPublicPad($updateable, $etherpadClient, $this->boundTo(138, null))->resolve('alice', 138);

		$this->assertTrue($result->isPad);
		$this->assertSame(['', false, ''], [$result->accessMode, $result->isExternal, $result->publicOpenUrl()]);
	}

	public function testMetaOfAFileWithNoRowNamesNoPad(): void {
		$meta = $this->namingAPublicPad(true, $this->createMock(EtherpadClient::class), $this->boundTo(138, null))->metaById('alice', 138);

		$this->assertSame(['', '', ''], [$meta->padId, $meta->padUrl(), $meta->accessMode]);
	}

	/** Not an answer that the file has no pad: a client would act on that. */
	public function testARowThatCannotBeReadStopsTheAnswer(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('buildPadUrl');
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('padOfFile')->willThrowException(new \RuntimeException('database went away'));

		$this->expectExceptionMessage('database went away');
		$this->namingAPublicPad(true, $etherpadClient, $bindingService)->resolve('alice', 138);
	}

	/** A file left naming the pad its row has replaced reports the new one, and its address. */
	public function testAWriterGetsThePadTheRowNames(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('g.NEW$pad')->willReturn('https://pad.example.test/p/g.NEW$pad');

		$meta = $this->namingAPublicPad(true, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.NEW$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE)))->metaById('alice', 138);

		$this->assertSame(['g.NEW$pad', BindingService::ACCESS_PUBLIC], [$meta->padId, $meta->accessMode]);
		$this->assertSame('https://pad.example.test/p/g.NEW$pad', $meta->publicOpenUrl());
		$this->assertSame('https://pad.example.test/p/g.NEW$pad', $meta->padUrl());
	}

	/** A public pad's id edits it, and the file may not even name it: a reader gets the read-only address of the row's pad, and no id. */
	public function testAReaderOfAPublicPadGetsItsReadOnlyAddressAndNoId(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('buildPadUrl');
		$etherpadClient->method('getReadOnlyPadUrl')->with('g.NEW$pad')->willReturn('https://pad.example.test/p/r.readonly');

		$meta = $this->namingAPublicPad(false, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.NEW$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE)))->metaById('alice', 138);

		$this->assertSame('', $meta->padId);
		$this->assertSame('https://pad.example.test/p/r.readonly', $meta->publicOpenUrl());
		$this->assertSame('https://pad.example.test/p/r.readonly', $meta->padUrl());
	}

	/**
	 * A file saying public about a pad its row has as protected: the row's
	 * mode. A reader gets no address, as the open gives none, and Etherpad is
	 * not asked for one.
	 */
	public function testAReaderOfAProtectedPadGetsNoAddress(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('buildPadUrl');
		$etherpadClient->expects($this->never())->method('getReadOnlyPadUrl');

		$meta = $this->namingAPublicPad(false, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.OLD$pad', BindingService::ACCESS_PROTECTED, BindingService::STATE_ACTIVE)))->metaById('alice', 138);

		$this->assertSame(['g.OLD$pad', BindingService::ACCESS_PROTECTED, '', ''], [$meta->padId, $meta->accessMode, $meta->padUrl(), $meta->publicOpenUrl()]);
	}

	/** The address is the row's pad's, whatever the file stores; no public address for a pad that has none. */
	public function testAWriterOfAProtectedPadGetsTheRowsAddress(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('g.OLD$pad')->willReturn('https://pad.example.test/p/from-the-row');

		$meta = $this->namingAPublicPad(true, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.OLD$pad', BindingService::ACCESS_PROTECTED, BindingService::STATE_ACTIVE)))->metaById('alice', 138);

		$this->assertSame('https://pad.example.test/p/from-the-row', $meta->padUrl());
		$this->assertSame('', $meta->publicOpenUrl());
	}

	/** Seen deleted for good, yet here: the row still says which pad it is, as the open finds. */
	public function testARowWaitingToGoStillAnswers(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/g.OLD$pad');

		$meta = $this->namingAPublicPad(true, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.OLD$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_PENDING_DELETE)))->metaById('alice', 138);

		$this->assertSame('g.OLD$pad', $meta->padId);
	}

	/** An address that cannot be built is none, and the row's other fields stand. The log says which file. */
	public function testAnAddressThatCannotBeBuiltLeavesItEmpty(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willThrowException(new \RuntimeException('no host'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Could not build the pad address for metadata.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === 138 && $context['error_message'] === 'no host'),
		);

		$meta = $this->namingAPublicPad(true, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.OLD$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE)), logger: $logger)->metaById('alice', 138);

		$this->assertSame(['g.OLD$pad', '', ''], [$meta->padId, $meta->padUrl(), $meta->publicOpenUrl()]);
	}

	/** Not knowing whether someone may write is reading: a public pad's id does not go. */
	public function testAPermissionThatCannotBeReadCountsAsReading(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);
		$file->method('getName')->willReturn('Named.pad');
		$file->method('isUpdateable')->willThrowException(new \RuntimeException('storage gone'));
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Named.pad');

		$meta = $this->buildService(userNodeResolver: $userNodeResolver, bindingService: $this->boundTo(138, new Binding(138, 'g.OLD$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE)))
			->metaById('alice', 138);

		$this->assertSame('', $meta->padId);
	}

	/** The row settles it, without a read of a file that may be locked or broken. */
	public function testARowAnswersWithoutReadingTheFile(): void {
		$file = $this->createConfiguredMock(File::class, ['getId' => 138, 'getName' => 'Named.pad', 'isUpdateable' => true]);
		$file->expects($this->never())->method('getContent');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Named.pad');
		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->expects($this->never())->method('readContentWithOpenLockRetry');
		$service = $this->buildService(userNodeResolver: $userNodeResolver, lockRetryService: $lockRetryService, bindingService: $this->boundTo(138, new Binding(138, 'g.OLD$pad', BindingService::ACCESS_PROTECTED, BindingService::STATE_ACTIVE)));

		$this->assertSame(BindingService::ACCESS_PROTECTED, $service->resolve('alice', 138)->accessMode);
		$this->assertSame(BindingService::ACCESS_PROTECTED, $service->metaById('alice', 138)->accessMode);
	}

	public function testAnExternalPadWithNoRowReadsAsItsFileHasIt(): void {
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndValidateExternalPublicPadUrl')->with('https://pad.example.test/p/g.OLD$pad')->willReturn(['pad_url' => 'https://other.example.test/p/remote']);

		$meta = $this->namingAPublicPad(true, $this->createMock(EtherpadClient::class), $this->boundTo(138, null), isExternal: true, fetcher: $fetcher)->metaById('alice', 138);

		$this->assertTrue($meta->isExternal);
		$this->assertSame('g.OLD$pad', $meta->padId);
		$this->assertSame('https://other.example.test/p/remote', $meta->publicOpenUrl());
		$this->assertSame('https://other.example.test/p/remote', $meta->padUrl());
	}

	public function testResolveOfAnExternalPadSaysSo(): void {
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndValidateExternalPublicPadUrl')->willReturn(['pad_url' => 'https://other.example.test/p/remote']);

		$result = $this->namingAPublicPad(true, $this->createMock(EtherpadClient::class), $this->boundTo(138, null), isExternal: true, fetcher: $fetcher)->resolve('alice', 138);

		$this->assertTrue($result->isExternal);
		$this->assertSame('https://other.example.test/p/remote', $result->publicOpenUrl());
	}

	/** Refused, it is no address: the file's own does not stand in. */
	public function testAnExternalAddressThatFailsTheCheckIsNoAddress(): void {
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndValidateExternalPublicPadUrl')->willThrowException(new \RuntimeException('host not allowed'));

		$meta = $this->namingAPublicPad(true, $this->createMock(EtherpadClient::class), $this->boundTo(138, null), isExternal: true, fetcher: $fetcher)->metaById('alice', 138);

		$this->assertSame(['', ''], [$meta->padUrl(), $meta->publicOpenUrl()]);
		$this->assertSame(['g.OLD$pad', BindingService::ACCESS_PUBLIC, true], [$meta->padId, $meta->accessMode, $meta->isExternal]);
	}

	/** A protected pad on another server has no address the app could give, and none is checked. */
	public function testAnExternalProtectedPadHasNoAddress(): void {
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->expects($this->never())->method('normalizeAndValidateExternalPublicPadUrl');

		$meta = $this->namingAPublicPad(true, $this->createMock(EtherpadClient::class), $this->boundTo(138, null), isExternal: true, fetcher: $fetcher, accessMode: BindingService::ACCESS_PROTECTED)->metaById('alice', 138);

		$this->assertTrue($meta->isExternal);
		$this->assertSame(BindingService::ACCESS_PROTECTED, $meta->accessMode);
		$this->assertSame(['', ''], [$meta->padUrl(), $meta->publicOpenUrl()]);
	}

	/** Meta waits for a lock, and one that stays is the caller's to hear, not an answer without the pad. */
	public function testMetaOfALockedFileWithNoRowStops(): void {
		$file = $this->buildPadNode(138, 'Named.pad', 'frontmatter');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Named.pad');
		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->method('readContentWithOpenLockRetry')->willThrowException(new LockedException('/Named.pad'));

		$this->expectException(LockedException::class);
		$this->buildService(userNodeResolver: $userNodeResolver, lockRetryService: $lockRetryService, bindingService: $this->boundTo(138, null))->metaById('alice', 138);
	}

	/** Resolve does not wait for a lock: it answers without what the file would say, and logs that. */
	public function testResolveOfALockedFileWithNoRowAnswersWithoutItsPad(): void {
		$file = $this->createConfiguredMock(File::class, ['getId' => 138, 'getName' => 'Named.pad']);
		$file->method('getContent')->willThrowException(new LockedException('/Named.pad'));
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Named.pad');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'The file gave no complete pad metadata.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === 138 && $context['error'] === LockedException::class),
		);
		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->expects($this->never())->method('readContentWithOpenLockRetry');

		$result = $this->buildService(userNodeResolver: $userNodeResolver, lockRetryService: $lockRetryService, bindingService: $this->boundTo(138, null), logger: $logger)->resolve('alice', 138);

		$this->assertTrue($result->isPad);
		$this->assertSame(['', false, ''], [$result->accessMode, $result->isExternal, $result->publicOpenUrl()]);
	}

	/** An `ext.` id alone is no pad on another server: the open refuses it too. */
	public function testAnExtIdWithoutExternalMetadataIsNoPad(): void {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(frontmatter: [], body: '', padId: 'ext.abc', accessMode: BindingService::ACCESS_PUBLIC, padUrl: 'https://other.example.test/p/abc', isExternal: false, snapshotRev: -1));
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndValidateExternalPublicPadUrl')->willReturn(['pad_url' => 'https://other.example.test/p/abc']);
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($this->buildPadNode(138, 'Named.pad', 'frontmatter'));
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Named.pad');

		$meta = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, externalPadExportFetcher: $fetcher, bindingService: $this->boundTo(138, null))->metaById('alice', 138);

		$this->assertSame(['', false, ''], [$meta->padId, $meta->isExternal, $meta->padUrl()]);
	}

	/** Not only an exception: whatever stops the read, the file still answers as a pad. */
	public function testAnErrorReadingTheFileStillAnswers(): void {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willThrowException(new \Error('bad type'));
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($this->buildPadNode(138, 'Named.pad', 'frontmatter'));
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Named.pad');

		$meta = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, bindingService: $this->boundTo(138, null))->metaById('alice', 138);

		$this->assertSame(['', ''], [$meta->padId, $meta->padUrl()]);
	}

	/** Not only an exception: whatever stops the address, the row's other fields stand. */
	public function testAnErrorBuildingTheAddressStillAnswers(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willThrowException(new \Error('bad type'));

		$meta = $this->namingAPublicPad(true, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.OLD$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE)))->metaById('alice', 138);

		$this->assertSame(['g.OLD$pad', ''], [$meta->padId, $meta->padUrl()]);
	}

	/** Text that says external does not outrun a row. */
	public function testARowWinsOverTextThatSaysExternal(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/g.ROW$pad');

		$meta = $this->namingAPublicPad(true, $etherpadClient, $this->boundTo(138, new Binding(138, 'g.ROW$pad', BindingService::ACCESS_PUBLIC, BindingService::STATE_ACTIVE)), isExternal: true)->metaById('alice', 138);

		$this->assertFalse($meta->isExternal);
		$this->assertSame('g.ROW$pad', $meta->padId);
	}

	private function namingAPublicPad(bool $updateable, EtherpadClient $etherpadClient, BindingService $bindingService, bool $isExternal = false, ?ExternalPadExportFetcher $fetcher = null, ?LoggerInterface $logger = null, string $accessMode = BindingService::ACCESS_PUBLIC): PadMetadataService {
		$file = $this->buildPadNode(138, 'Named.pad', 'frontmatter', $updateable);
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Named.pad');
		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->method('readContentWithOpenLockRetry')->willReturn('frontmatter');
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.OLD$pad', 'access_mode' => $accessMode],
			body: '',
			padId: 'g.OLD$pad',
			accessMode: $accessMode,
			padUrl: 'https://pad.example.test/p/g.OLD$pad',
			isExternal: $isExternal,
			snapshotRev: -1,
		));

		return $this->buildService($padFileService, userNodeResolver: $userNodeResolver, lockRetryService: $lockRetryService, etherpadClient: $etherpadClient, externalPadExportFetcher: $fetcher, bindingService: $bindingService, logger: $logger);
	}

	private function boundTo(int $fileId, ?Binding $row): BindingService {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('padOfFile')->with($fileId)->willReturn($row);
		return $bindingService;
	}

	public function testFindOriginalForCopyReturnsFoundWhenBoundFileIsReadableByRequester(): void {
		$orphan = $this->buildPadNode(701, 'Copy.pad', "---\npad_id: original-pad\naccess_mode: protected\n---\n");
		$originalNode = $this->createMock(File::class);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturnMap([
			['alice', 701, $orphan],
			['alice', 42, $originalNode],
		]);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $originalNode)->willReturn('/Folder/Original.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'original-pad'],
			body: '',
			padId: 'original-pad',
			accessMode: '',
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByPadId')
			->with('original-pad', BindingService::STATE_ACTIVE)
			->willReturn(new Binding(fileId: 42, padId: 'original-pad', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 701);

		$this->assertTrue($result->found);
		$this->assertSame(42, $result->fileId);
		$this->assertSame('/Folder/Original.pad', $result->path);
	}

	public function testFindOriginalForCopyMissesWhenBoundFileNotInRequestersUserspace(): void {
		// Critical: even though a binding row exists for the frontmatter
		// pad_id, the requester does not own / cannot read the bound file
		// (e.g. it lives in another user's account). The response must be
		// indistinguishable from the "no binding at all" case so we don't
		// leak that the pad_id is in use elsewhere.
		$orphan = $this->buildPadNode(702, 'Copy.pad', "---\npad_id: original-pad\n---\n");

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturnCallback(
			static function (string $uid, int $fileId) use ($orphan): File {
				if ($fileId === 702) {
					return $orphan;
				}
				throw new NotFoundException('not in alice userspace');
			}
		);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'original-pad'],
			body: '',
			padId: 'original-pad',
			accessMode: '',
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')->willReturn(new Binding(fileId: 9999, padId: 'original-pad', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 702);

		$this->assertFalse($result->found);
	}

	public function testFindOriginalForCopyMissesWhenNoBindingForPadId(): void {
		$orphan = $this->buildPadNode(703, 'Copy.pad', "---\npad_id: gone-pad\n---\n");
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 703)->willReturn($orphan);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'gone-pad'],
			body: '',
			padId: 'gone-pad',
			accessMode: '',
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')->willReturn(null);

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 703);

		$this->assertFalse($result->found);
	}

	public function testFindOriginalForCopyMissesForExternalPadId(): void {
		// ext.* pad IDs never have a managed binding, and we should not even
		// look them up.
		$orphan = $this->buildPadNode(704, 'Copy.pad', "---\npad_id: ext.remote\n---\n");
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 704)->willReturn($orphan);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'ext.remote'],
			body: '',
			padId: 'ext.remote',
			accessMode: '',
			padUrl: '',
			isExternal: true,
			snapshotRev: -1,
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByPadId');

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 704);

		$this->assertFalse($result->found);
	}

	public function testFindOriginalForCopyMissesForNonPadFile(): void {
		$file = $this->createConfiguredMock(File::class, [
			'getId' => 705,
			'getName' => 'Notes.txt',
		]);
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 705)->willReturn($file);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByPadId');

		$result = $this->buildService(
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 705);

		$this->assertFalse($result->found);
	}

	public function testFindOriginalForCopyMissesWhenOrphanResolverThrows(): void {
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willThrowException(new NotFoundException('gone'));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByPadId');

		$result = $this->buildService(
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 706);

		$this->assertFalse($result->found);
	}

	public function testFindOriginalForCopyMissesWhenBindingPointsAtSameFile(): void {
		// Pathological case: the orphan's frontmatter pad_id is somehow
		// already bound to the orphan itself. Returning "found: true" here
		// would offer the user a button that loops back to the broken file.
		$orphan = $this->buildPadNode(707, 'Self.pad', "---\npad_id: self-pad\n---\n");
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 707)->willReturn($orphan);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'self-pad'],
			body: '',
			padId: 'self-pad',
			accessMode: '',
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')->willReturn(new Binding(fileId: 707, padId: 'self-pad', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_ACTIVE));

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 707);

		$this->assertFalse($result->found);
	}

	private function buildPadNode(int $fileId, string $name, string $content, bool $updateable = true): File {
		return $this->createConfiguredMock(File::class, [
			'getId' => $fileId,
			'getName' => $name,
			'getContent' => $content,
			'getMimeType' => 'application/x-etherpad-nextcloud',
			// Whether the file may be written. This endpoint hands out an
			// address, and for a public pad the address is the access.
			'isUpdateable' => $updateable,
		]);
	}

	private function buildService(
		?PadFileService $padFileService = null,
		?PathNormalizer $padPaths = null,
		?UserNodeResolver $userNodeResolver = null,
		?PadFileLockRetryService $lockRetryService = null,
		?EtherpadClient $etherpadClient = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
		?BindingService $bindingService = null,
		?LoggerInterface $logger = null,
	): PadMetadataService {
		return new PadMetadataService(
			$padFileService ?? $this->createMock(PadFileService::class),
			$padPaths ?? $this->createMock(PathNormalizer::class),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$lockRetryService ?? $this->createMock(PadFileLockRetryService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
