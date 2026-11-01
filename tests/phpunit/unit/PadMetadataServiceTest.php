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

		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver)->resolve('alice', 138);

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
		$file = $this->createConfiguredMock(File::class, [
			'getId' => 138,
			'getName' => 'Public.pad',
			'getMimeType' => 'application/x-etherpad-nextcloud',
			'getContent' => 'frontmatter',
			'isUpdateable' => true,
		]);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Public.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('frontmatter')->willReturn(new ParsedPadFile(
			frontmatter: [
				'pad_id' => 'g.ABC$pad',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
			body: '',
			padId: 'g.ABC$pad',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('g.ABC$pad')->willReturn('https://pad.example.test/p/g.ABC$pad');

		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, etherpadClient: $etherpadClient, bindingService: $this->boundTo(138, 'g.ABC$pad', BindingService::ACCESS_PUBLIC))
			->resolve('alice', 138);

		$this->assertTrue($result->isPad);
		$this->assertTrue($result->isPadMime);
		$this->assertSame(138, $result->fileId);
		$this->assertSame('/Public.pad', $result->path);
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
		$file = $this->createConfiguredMock(File::class, [
			'getId' => 138,
			'getName' => 'Public.pad',
			'getMimeType' => 'application/x-etherpad-nextcloud',
			'getContent' => 'frontmatter',
			'isUpdateable' => false,
		]);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Public.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.ABC$pad', 'access_mode' => BindingService::ACCESS_PUBLIC],
			body: '',
			padId: 'g.ABC$pad',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('buildPadUrl');
		$etherpadClient->method('getReadOnlyPadUrl')->with('g.ABC$pad')
			->willReturn('https://pad.example.test/p/r.readonlyid');

		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, etherpadClient: $etherpadClient, bindingService: $this->boundTo(138, 'g.ABC$pad', BindingService::ACCESS_PUBLIC))
			->resolve('alice', 138);

		$this->assertSame('https://pad.example.test/p/r.readonlyid', $result->publicOpenUrl());
	}

	/**
	 * A failed read-only lookup must not fall back to the editable address.
	 * That is the moment the withholding matters most: the call meant to
	 * avoid handing it over is the one that just failed.
	 */
	public function testResolveGivesNoUrlWhenTheReadOnlyLookupFails(): void {
		$file = $this->createConfiguredMock(File::class, [
			'getId' => 138,
			'getName' => 'Public.pad',
			'getMimeType' => 'application/x-etherpad-nextcloud',
			'getContent' => 'frontmatter',
			'isUpdateable' => false,
		]);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->willReturn('/Public.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.ABC$pad', 'access_mode' => BindingService::ACCESS_PUBLIC],
			body: '',
			padId: 'g.ABC$pad',
			accessMode: BindingService::ACCESS_PUBLIC,
			// The editable address as stored in the file — the one that must
			// not survive as the answer.
			padUrl: 'https://pad.example.test/p/g.ABC$pad',
			isExternal: false,
			snapshotRev: -1,
		));

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getReadOnlyPadUrl')
			->willThrowException(new \OCA\EtherpadNextcloud\Exception\EtherpadClientException('unavailable'));

		// `meta`, not `resolve`: the response ships pad_url from here, and
		// that is the field the editable address would survive in.
		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, etherpadClient: $etherpadClient, bindingService: $this->boundTo(138, 'g.ABC$pad', BindingService::ACCESS_PUBLIC))
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
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with(138)->willReturn(null);

		$result = $this->namingAPublicPad($updateable, $etherpadClient, $bindingService)->resolve('alice', 138);

		$this->assertTrue($result->isPad);
		$this->assertSame('', $result->accessMode);
		$this->assertFalse($result->isExternal);
		$this->assertSame('', $result->publicOpenUrl());
	}

	public function testMetaOfAFileWithNoRowNamesNoPad(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);

		$meta = $this->namingAPublicPad(true, $this->createMock(EtherpadClient::class), $bindingService)->metaById('alice', 138);

		$this->assertSame('', $meta->padId);
		$this->assertSame('', $meta->padUrl());
		$this->assertSame('', $meta->accessMode);
	}

	/** A row that cannot be read gives no answer, not the file's. */
	public function testARowThatCannotBeReadAnswersNoPad(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('buildPadUrl');
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willThrowException(new \RuntimeException('database went away'));

		$this->assertSame('', $this->namingAPublicPad(true, $etherpadClient, $bindingService)->resolve('alice', 138)->publicOpenUrl());
	}

	/** The row's pad, and its mode: a file left naming the pad its row has replaced reports the new one. */
	public function testAFileReportsThePadItsRowNames(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('g.NEW$pad')->willReturn('https://pad.example.test/p/g.NEW$pad');

		$meta = $this->namingAPublicPad(true, $etherpadClient, $this->boundTo(138, 'g.NEW$pad', BindingService::ACCESS_PUBLIC))->metaById('alice', 138);

		$this->assertSame('g.NEW$pad', $meta->padId);
		$this->assertSame('https://pad.example.test/p/g.NEW$pad', $meta->publicOpenUrl());
		$this->assertSame('https://pad.example.test/p/g.NEW$pad', $meta->padUrl());
	}

	/**
	 * A file saying public about a pad its row has as protected gets no
	 * address, nor does Etherpad get asked for a read-only one. Nor does the
	 * address the file stores stand in for another pad's.
	 */
	public function testARowNamingAProtectedPadGivesNoAddress(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('getReadOnlyPadUrl');

		$meta = $this->namingAPublicPad(false, $etherpadClient, $this->boundTo(138, 'g.NEW$pad', BindingService::ACCESS_PROTECTED))->metaById('alice', 138);

		$this->assertSame(BindingService::ACCESS_PROTECTED, $meta->accessMode);
		$this->assertSame('', $meta->publicOpenUrl());
		$this->assertSame('', $meta->padUrl());
	}

	/** What the file stores stays where it names the row's pad. */
	public function testAProtectedPadKeepsTheAddressItsFileStores(): void {
		$meta = $this->namingAPublicPad(true, $this->createMock(EtherpadClient::class), $this->boundTo(138, 'g.OLD$pad', BindingService::ACCESS_PROTECTED))->metaById('alice', 138);

		$this->assertSame('https://pad.example.test/p/g.OLD$pad', $meta->padUrl());
	}

	public function testAnExternalPadHasNoRowToAsk(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndValidateExternalPublicPadUrl')->willReturn(['pad_url' => 'https://other.example.test/p/remote']);

		$result = $this->namingAPublicPad(true, $this->createMock(EtherpadClient::class), $bindingService, isExternal: true, fetcher: $fetcher)->resolve('alice', 138);

		$this->assertTrue($result->isExternal);
		$this->assertSame('https://other.example.test/p/remote', $result->publicOpenUrl());
	}

	private function namingAPublicPad(bool $updateable, EtherpadClient $etherpadClient, BindingService $bindingService, bool $isExternal = false, ?ExternalPadExportFetcher $fetcher = null): PadMetadataService {
		$file = $this->buildPadNode(138, 'Named.pad', 'frontmatter', $updateable);
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Named.pad');
		$lockRetryService = $this->createMock(PadFileLockRetryService::class);
		$lockRetryService->method('readContentWithOpenLockRetry')->willReturn('frontmatter');
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.OLD$pad', 'access_mode' => BindingService::ACCESS_PUBLIC],
			body: '',
			padId: 'g.OLD$pad',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/g.OLD$pad',
			isExternal: $isExternal,
			snapshotRev: -1,
		));

		return $this->buildService($padFileService, userNodeResolver: $userNodeResolver, lockRetryService: $lockRetryService, etherpadClient: $etherpadClient, externalPadExportFetcher: $fetcher, bindingService: $bindingService);
	}

	private function boundTo(int $fileId, string $padId, string $accessMode): BindingService {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(new Binding($fileId, $padId, $accessMode, BindingService::STATE_ACTIVE));
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
			// What the share granted. This endpoint hands out an address, and
			// for a public pad the address is the access.
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
	): PadMetadataService {
		return new PadMetadataService(
			$padFileService ?? $this->createMock(PadFileService::class),
			$padPaths ?? $this->createMock(PathNormalizer::class),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$lockRetryService ?? $this->createMock(PadFileLockRetryService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
