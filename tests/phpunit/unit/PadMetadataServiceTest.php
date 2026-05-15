<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadPathService;
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
		$padFileService->method('parsePadFile')->with('frontmatter')->willReturn([
			'frontmatter' => [
				'pad_id' => 'ext.remote',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'ext.remote',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.example.test/p/External',
		]);
		$padFileService->method('isExternalFrontmatter')->with($this->isType('array'), 'ext.remote')->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://pad.example.test/p/External')
			->willReturn(['pad_url' => 'https://pad.example.test/p/External']);

		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, lockRetryService: $lockRetryService, etherpadClient: $etherpadClient)
			->metaById('alice', 138);

		$this->assertSame([
			'is_pad' => true,
			'is_pad_mime' => true,
			'file_id' => 138,
			'name' => 'External.pad',
			'path' => '/External.pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'is_external' => true,
			'pad_id' => 'ext.remote',
			'pad_url' => 'https://pad.example.test/p/External',
			'public_open_url' => 'https://pad.example.test/p/External',
		], $result);
	}

	public function testResolveReturnsFalseWhenFileIdIsMissing(): void {
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')
			->with('alice', 404)
			->willThrowException(new NotFoundException('missing'));

		$result = $this->buildService(userNodeResolver: $userNodeResolver)
			->resolve('alice', 404);

		$this->assertSame(['is_pad' => false, 'file_id' => 404], $result);
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
		$padFileService->method('parsePadFile')->with('frontmatter')->willReturn([
			'frontmatter' => [
				'pad_id' => 'g.ABC$pad',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'g.ABC$pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => '',
		]);
		$padFileService->method('isExternalFrontmatter')->with($this->isType('array'), 'g.ABC$pad')->willReturn(false);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('g.ABC$pad')->willReturn('https://pad.example.test/p/g.ABC$pad');

		$result = $this->buildService($padFileService, userNodeResolver: $userNodeResolver, etherpadClient: $etherpadClient)
			->resolve('alice', 138);

		$this->assertSame([
			'is_pad' => true,
			'is_pad_mime' => true,
			'file_id' => 138,
			'path' => '/Public.pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'is_external' => false,
			'public_open_url' => 'https://pad.example.test/p/g.ABC$pad',
		], $result);
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
		$padFileService->method('parsePadFile')->willReturn(['frontmatter' => ['pad_id' => 'original-pad']]);
		$padFileService->method('extractPadMetadata')->willReturn(['pad_id' => 'original-pad']);

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

		$this->assertSame(['found' => true, 'file_id' => 42, 'path' => '/Folder/Original.pad'], $result);
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
		$padFileService->method('parsePadFile')->willReturn(['frontmatter' => ['pad_id' => 'original-pad']]);
		$padFileService->method('extractPadMetadata')->willReturn(['pad_id' => 'original-pad']);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')->willReturn(['file_id' => 9999, 'pad_id' => 'original-pad']);

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 702);

		$this->assertSame(['found' => false], $result);
	}

	public function testFindOriginalForCopyMissesWhenNoBindingForPadId(): void {
		$orphan = $this->buildPadNode(703, 'Copy.pad', "---\npad_id: gone-pad\n---\n");
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 703)->willReturn($orphan);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn(['frontmatter' => ['pad_id' => 'gone-pad']]);
		$padFileService->method('extractPadMetadata')->willReturn(['pad_id' => 'gone-pad']);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')->willReturn(null);

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 703);

		$this->assertSame(['found' => false], $result);
	}

	public function testFindOriginalForCopyMissesForExternalPadId(): void {
		// ext.* pad IDs never have a managed binding, and we should not even
		// look them up.
		$orphan = $this->buildPadNode(704, 'Copy.pad', "---\npad_id: ext.remote\n---\n");
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 704)->willReturn($orphan);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn(['frontmatter' => ['pad_id' => 'ext.remote']]);
		$padFileService->method('extractPadMetadata')->willReturn(['pad_id' => 'ext.remote']);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByPadId');

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 704);

		$this->assertSame(['found' => false], $result);
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

		$this->assertSame(['found' => false], $result);
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

		$this->assertSame(['found' => false], $result);
	}

	public function testFindOriginalForCopyMissesWhenBindingPointsAtSameFile(): void {
		// Pathological case: the orphan's frontmatter pad_id is somehow
		// already bound to the orphan itself. Returning "found: true" here
		// would offer the user a button that loops back to the broken file.
		$orphan = $this->buildPadNode(707, 'Self.pad', "---\npad_id: self-pad\n---\n");
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 707)->willReturn($orphan);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn(['frontmatter' => ['pad_id' => 'self-pad']]);
		$padFileService->method('extractPadMetadata')->willReturn(['pad_id' => 'self-pad']);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByPadId')->willReturn(['file_id' => 707, 'pad_id' => 'self-pad']);

		$result = $this->buildService(
			padFileService: $padFileService,
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
		)->findOriginalForCopy('alice', 707);

		$this->assertSame(['found' => false], $result);
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
