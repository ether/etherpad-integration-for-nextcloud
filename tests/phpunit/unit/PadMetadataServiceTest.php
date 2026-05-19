<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadPathService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
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
		));

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://pad.example.test/p/External')
			->willReturn(['pad_url' => 'https://pad.example.test/p/External']);

		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, lockRetryService: $lockRetryService, etherpadClient: $etherpadClient)
			->metaById('alice', 138);

		$this->assertTrue($result->isPad);
		$this->assertTrue($result->isPadMime);
		$this->assertSame(138, $result->fileId);
		$this->assertSame('External.pad', $result->name);
		$this->assertSame('/External.pad', $result->path);
		$this->assertSame(BindingService::ACCESS_PUBLIC, $result->accessMode);
		$this->assertTrue($result->isExternal);
		$this->assertSame('ext.remote', $result->padId);
		$this->assertSame('https://pad.example.test/p/External', $result->padUrl);
		$this->assertSame('https://pad.example.test/p/External', $result->publicOpenUrl);
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
		));

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('g.ABC$pad')->willReturn('https://pad.example.test/p/g.ABC$pad');

		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, etherpadClient: $etherpadClient)
			->resolve('alice', 138);

		$this->assertTrue($result->isPad);
		$this->assertTrue($result->isPadMime);
		$this->assertSame(138, $result->fileId);
		$this->assertSame('/Public.pad', $result->path);
		$this->assertSame(BindingService::ACCESS_PUBLIC, $result->accessMode);
		$this->assertFalse($result->isExternal);
		$this->assertSame('https://pad.example.test/p/g.ABC$pad', $result->publicOpenUrl);
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
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByPadId')
			->with('original-pad', BindingService::STATE_ACTIVE)
			->willReturn(['file_id' => 42, 'pad_id' => 'original-pad', 'state' => BindingService::STATE_ACTIVE]);

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
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')->willReturn(['file_id' => 9999, 'pad_id' => 'original-pad']);

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
		));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')->willReturn(['file_id' => 707, 'pad_id' => 'self-pad']);

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 707);

		$this->assertFalse($result->found);
	}

	private function buildPadNode(int $fileId, string $name, string $content): File {
		return $this->createConfiguredMock(File::class, [
			'getId' => $fileId,
			'getName' => $name,
			'getContent' => $content,
		]);
	}

	private function buildService(
		?PadFileService $padFileService = null,
		?PadPathService $padPaths = null,
		?UserNodeResolver $userNodeResolver = null,
		?PadFileLockRetryService $lockRetryService = null,
		?EtherpadClient $etherpadClient = null,
		?BindingService $bindingService = null,
	): PadMetadataService {
		return new PadMetadataService(
			$padFileService ?? $this->createMock(PadFileService::class),
			$padPaths ?? $this->createMock(PadPathService::class),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$lockRetryService ?? $this->createMock(PadFileLockRetryService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
