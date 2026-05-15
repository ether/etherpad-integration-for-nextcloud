<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\BindingStateConflictException;
use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LifecycleServiceTest extends TestCase {
	public function testHandleTrashSkipsNonPadFiles(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$config = $this->buildDeleteOnTrashEnabledConfig();
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(12);
		$file->method('getName')->willReturn('Notes.txt');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$config,
			$logger,
			$secureRandom
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('not_pad_file', $result['reason']);
		$this->assertSame(12, $result['file_id']);
	}

	public function testHandleTrashMarksPendingDeleteWhenEtherpadDeleteFails(): void {
		$fileId = 21;
		$padId = 'pad-abc';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn([
				'file_id' => $fileId,
				'pad_id' => $padId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_ACTIVE,
			]);
		$bindingService->expects($this->once())
			->method('markPendingDelete')
			->with(
				$fileId,
				$this->callback(static fn ($deletedAt): bool => is_int($deletedAt) && $deletedAt > 0)
			);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->never())->method('parsePadFile');
		$padFileService->expects($this->never())->method('isExternalFrontmatter');
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with('doc-current', 'snapshot-text', '<p>snapshot-html</p>', 7)
			->willReturn('doc-trash-updated');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('getText')->with($padId)->willReturn('snapshot-text');
		$etherpadClient->expects($this->once())->method('getHTML')->with($padId)->willReturn('<p>snapshot-html</p>');
		$etherpadClient->expects($this->once())->method('getRevisionsCount')->with($padId)->willReturn(7);
		$etherpadClient->expects($this->once())
			->method('deletePad')
			->with($padId)
			->willThrowException(new \RuntimeException('temporary failure'));

		$config = $this->buildDeleteOnTrashEnabledConfig();
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Pad.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-current');
		$file->expects($this->once())->method('putContent')->with('doc-trash-updated');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$config,
			$logger,
			$secureRandom
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_TRASHED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($padId, $result['pad_id']);
		$this->assertTrue($result['snapshot_persisted']);
		$this->assertTrue($result['delete_pending']);
	}

	public function testHandleTrashReturnsSkippedOnStateTransitionConflict(): void {
		$fileId = 55;
		$padId = 'pad-race';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn([
				'file_id' => $fileId,
				'pad_id' => $padId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_ACTIVE,
			]);
		$bindingService->expects($this->once())
			->method('markPendingDelete')
			->willThrowException(new BindingStateConflictException('race'));

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->never())->method('withExportSnapshot');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('deletePad')
			->with($padId)
			->willThrowException(new \RuntimeException('temporary failure'));

		$config = $this->buildDeleteOnTrashEnabledConfig();
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Pad.pad');
		$file->expects($this->once())->method('getContent')->willReturn('');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$config,
			$logger,
			$secureRandom
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('binding_state_transition_conflict', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($padId, $result['pad_id']);
	}

	public function testHandleRestoreFallsBackToTextWhenHtmlRestoreFails(): void {
		$fileId = 83;
		$oldPadId = 'old-pad';
		$newPadId = 'r-old-pad-abc123def456';
		$newPadUrl = 'https://pad.example.test/p/' . rawurlencode($newPadId);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn([
				'file_id' => $fileId,
				'pad_id' => $oldPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_PENDING_DELETE,
			]);
		$bindingService->expects($this->once())
			->method('markRestored')
			->with($fileId, $newPadId);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())->method('getTextSnapshotForRestore')->with('doc-before')->willReturn('plain text');
		$padFileService->expects($this->once())->method('getHtmlSnapshotForRestore')->with('doc-before')->willReturn('<p>html text</p>');
		$padFileService->expects($this->once())
			->method('withStateAndSnapshot')
			->with(
				'doc-before',
				BindingService::STATE_ACTIVE,
				'plain text',
				$newPadId,
				null,
				$newPadUrl
			)
			->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->once())
			->method('setHTML')
			->with($newPadId, '<p>html text</p>')
			->willThrowException(new \RuntimeException('setHTML unsupported'));
		$etherpadClient->expects($this->once())->method('setText')->with($newPadId, 'plain text');
		$etherpadClient->expects($this->once())->method('buildPadUrl')->with($newPadId)->willReturn($newPadUrl);
		$etherpadClient->expects($this->never())->method('deletePad');

		$config = $this->buildDeleteOnTrashEnabledConfig();
		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->expects($this->once())
			->method('generate')
			->with(12, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abc123def456');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$config,
			$logger,
			$secureRandom
		);

		$result = $service->handleRestore($file);
		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['old_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	public function testHandleRestoreWithoutBindingRecreatesManagedPublicPad(): void {
		$fileId = 91;
		$oldPadId = 'old-public-pad';
		$newPadId = 'r-old-public-pad-abc123def456';
		$newPadUrl = 'https://pad.example.test/p/' . rawurlencode($newPadId);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with($fileId, $newPadId, BindingService::ACCESS_PUBLIC);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('doc-before')->willReturn([
			'frontmatter' => [
				'pad_id' => $oldPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => 'trashed',
			],
			'body' => '',
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.example.test/p/' . rawurlencode($oldPadId),
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'plain text',
			'html' => '',
		]);
		$padFileService->expects($this->once())
			->method('withStateAndSnapshot')
			->with('doc-before', BindingService::STATE_ACTIVE, 'plain text', $newPadId, null, $newPadUrl)
			->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->once())->method('setText')->with($newPadId, 'plain text');
		$etherpadClient->expects($this->once())->method('buildPadUrl')->with($newPadId)->willReturn($newPadUrl);
		$etherpadClient->expects($this->never())->method('deletePad');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->expects($this->once())
			->method('generate')
			->with(12, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abc123def456');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = (new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
		))->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['old_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	public function testHandleRestoreWithoutBindingSkipsExternalPadFile(): void {
		$fileId = 92;
		$oldPadId = 'ext.old';
		$origin = 'https://pad.remote.test';
		$remotePadId = 'RemotePad';
		$padUrl = $origin . '/p/' . $remotePadId;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$frontmatter = [
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => 'trashed',
			'pad_url' => $padUrl,
			'pad_origin' => $origin,
			'remote_pad_id' => $remotePadId,
		];
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('doc-before')->willReturn([
			'frontmatter' => $frontmatter,
			'body' => '',
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => $padUrl,
		]);
		$padFileService->method('isExternalFrontmatter')->with($frontmatter, $oldPadId)->willReturn(true);
		$padFileService->expects($this->never())->method('getSnapshotPartsFromBody');
		$padFileService->expects($this->never())->method('withStateAndSnapshot');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('setText');
		$etherpadClient->expects($this->never())->method('setHTML');
		$etherpadClient->expects($this->never())->method('deletePad');
		$etherpadClient->expects($this->never())->method('normalizeAndValidateExternalPublicPadUrl');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('External.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = (new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
		))->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('external_pad', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['pad_id']);
	}

	public function testHandleRestoreWithoutBindingSkipsExtPrefixWithIncompleteMetadata(): void {
		$fileId = 93;
		$oldPadId = 'ext.incomplete';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$frontmatter = [
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => 'trashed',
			'pad_url' => '',
		];
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('doc-before')->willReturn([
			'frontmatter' => $frontmatter,
			'body' => '',
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => '',
		]);
		$padFileService->method('isExternalFrontmatter')->with($frontmatter, $oldPadId)->willReturn(false);
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'external snapshot',
			'html' => '',
		]);
		$padFileService->expects($this->never())->method('withStateAndSnapshot');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('setText');
		$etherpadClient->expects($this->never())->method('setHTML');
		$etherpadClient->expects($this->never())->method('deletePad');
		$etherpadClient->expects($this->never())->method('normalizeAndValidateExternalPublicPadUrl');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('External.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = (new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
		))->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('external_pad', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['pad_id']);
	}

	public function testHandleRestoreWithoutBindingSkipsDisallowedExternalUrl(): void {
		$fileId = 94;
		$oldPadId = 'ext.disallowed';
		$origin = 'https://pad.remote.test';
		$remotePadId = 'RemotePad';
		$padUrl = $origin . '/p/' . $remotePadId;

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$frontmatter = [
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => 'trashed',
			'pad_url' => $padUrl,
			'pad_origin' => $origin,
			'remote_pad_id' => $remotePadId,
		];
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('doc-before')->willReturn([
			'frontmatter' => $frontmatter,
			'body' => '',
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => $padUrl,
		]);
		$padFileService->method('isExternalFrontmatter')->with($frontmatter, $oldPadId)->willReturn(true);
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'external snapshot',
			'html' => '',
		]);
		$padFileService->expects($this->never())->method('withStateAndSnapshot');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('setText');
		$etherpadClient->expects($this->never())->method('setHTML');
		$etherpadClient->expects($this->never())->method('deletePad');
		$etherpadClient->expects($this->never())->method('normalizeAndValidateExternalPublicPadUrl');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('External.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = (new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
		))->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('external_pad', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['pad_id']);
	}

	public function testRecoverFromSnapshotProvisionsFreshPadWhenBindingMissing(): void {
		$fileId = 701;
		$oldPadId = 'orphaned-pad';
		$newPadId = 'r-orphaned-pad-recover123abc';
		$newPadUrl = 'https://pad.example.test/p/' . rawurlencode($newPadId);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with($fileId, $newPadId, BindingService::ACCESS_PUBLIC);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn([
			'frontmatter' => [
				'pad_id' => $oldPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
			'body' => '',
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.example.test/p/' . rawurlencode($oldPadId),
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'recovered content',
			'html' => '',
		]);
		$padFileService->method('withStateAndSnapshot')->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->once())->method('setText')->with($newPadId, 'recovered content');
		$etherpadClient->method('buildPadUrl')->with($newPadId)->willReturn($newPadUrl);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('recover123abc');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Imported.pad');
		$file->method('getContent')->willReturn('doc-before');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$result = (new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
		))->recoverFromSnapshot($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		// Critical security property: the recovered pad ID is a fresh one,
		// never the pad_id parroted back from the frontmatter.
		$this->assertNotSame($oldPadId, $result['new_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	public function testRecoverFromSnapshotRollsBackWhenBindingRaceLosesToConcurrentRequest(): void {
		// Two parallel recoveries both pass the findByFileId pre-check.
		// The loser's createBinding hits the unique constraint and throws,
		// and we must NOT proceed to overwrite the file (which by now
		// belongs to the winner's recovery). The provisioned pad and any
		// partially created binding row are cleaned up.
		$fileId = 711;
		$oldPadId = 'orphaned-pad';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->willThrowException(new BindingException('duplicate key on file_id'));
		// File content must not be overwritten if we lose the race.
		$bindingService->expects($this->never())->method('deleteByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn([
			'frontmatter' => ['pad_id' => $oldPadId, 'access_mode' => BindingService::ACCESS_PUBLIC],
			'body' => '',
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => '',
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'content', 'html' => '']);
		$padFileService->method('withStateAndSnapshot')->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad');
		$etherpadClient->expects($this->once())->method('setText');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		// Loser must clean up its freshly provisioned pad.
		$etherpadClient->expects($this->once())->method('deletePad');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('race12345abc');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Orphan.pad');
		$file->method('getContent')->willReturn('doc-before');
		// Critical: we never touch the file when we lose the binding race.
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		(new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
		))->recoverFromSnapshot($file);
	}

	public function testRecoverFromSnapshotRefusesWhenBindingAlreadyExists(): void {
		$fileId = 702;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'pad_id' => 'already-linked',
			'state' => BindingService::STATE_ACTIVE,
			'access_mode' => BindingService::ACCESS_PUBLIC,
		]);
		$bindingService->expects($this->never())->method('createBinding');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Linked.pad');
		$file->expects($this->never())->method('putContent');

		$service = new LifecycleService(
			$bindingService,
			$this->createMock(PadFileService::class),
			$this->createMock(EtherpadClient::class),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
		);

		$this->expectException(PadAlreadyHasBindingException::class);
		$service->recoverFromSnapshot($file);
	}

	public function testRecoverFromSnapshotRejectsNonPadFile(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(703);
		$file->method('getName')->willReturn('Notes.txt');

		$service = new LifecycleService(
			$bindingService,
			$this->createMock(PadFileService::class),
			$this->createMock(EtherpadClient::class),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
		);

		$this->expectException(NotAPadFileException::class);
		$service->recoverFromSnapshot($file);
	}

	private function buildDeleteOnTrashEnabledConfig(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'delete_on_trash') {
					return 'yes';
				}
				if ($appName === 'etherpad_nextcloud' && $key === 'test_fault') {
					return '';
				}
				return $default;
			}
		);
		$config->method('getSystemValueBool')->willReturn(false);
		return $config;
	}
}
