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
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LifecycleServiceTest extends TestCase {
	public function testRestoreProvisioningRemovesTheGroupWhenItsPadCannotBeCreated(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$etherpadClient->expects($this->once())
			->method('createGroupPad')
			->willThrowException(new \RuntimeException('pad creation failed'));
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrst');

		$service = new LifecycleService(
			$this->createMock(BindingService::class),
			$this->createMock(PadFileService::class),
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
		);

		// provisionRestorePadId is private; reach it the way the restore path
		// does, without standing up the whole restore.
		$provision = new \ReflectionMethod($service, 'provisionRestorePadId');
		$this->expectException(\RuntimeException::class);
		$provision->invoke($service, BindingService::ACCESS_PROTECTED, 'nc-old');
	}

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
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$config,
			$logger,
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
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
		$parsedPad = new ParsedPadFile(
			frontmatter: [],
			body: '',
			padId: $padId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		);
		$padFileService->method('readPad')->with('doc-current')->willReturn($parsedPad);
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with($this->identicalTo($parsedPad), 'snapshot-text', '<p>snapshot-html</p>', 7)
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
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$config,
			$logger,
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
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
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$config,
			$logger,
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('binding_state_transition_conflict', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($padId, $result['pad_id']);
	}

	/**
	 * A binding only sits in `pending_delete` because its pad delete failed,
	 * so on restore the old pad is usually still there — and `markRestored`
	 * points the row at the new one, which was the last thing naming it. The
	 * retry job walks `pending_delete` rows and will never see it again, so
	 * for a protected pad a whole group and its sessions would be stranded.
	 */
	/** The restore's own provisioning leaks the same way a first open does. */
	public function testHandleRestoreRemovesAPublicPadWhoseCreationFailedHalfway(): void {
		$fileId = 86;
		$oldPadId = 'old-pad';
		$newPadId = 'r-old-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'file_id' => $fileId,
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_PENDING_DELETE,
		]);
		$bindingService->expects($this->never())->method('markRestored');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createPad')
			->with($newPadId)
			->willThrowException(new \RuntimeException('Connection timed out'));
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abc123def456');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');

		$service = new LifecycleService(
			$bindingService,
			$this->createMock(PadFileService::class),
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
		);

		$this->expectException(\RuntimeException::class);
		$service->handleRestore($file);
	}

	/**
	 * `markRestored` is the last step and can commit and still throw. By
	 * then the file already names the new pad, so row, file and pad agree —
	 * and the rollback would put the old content back and delete the pad
	 * the row now points at.
	 */
	public function testHandleRestoreDoesNotUndoARestoreThatLanded(): void {
		$fileId = 87;
		$oldPadId = 'old-pad';
		$newPadId = 'r-old-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'file_id' => $fileId,
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_PENDING_DELETE,
		]);
		$bindingService->method('markRestored')->willThrowException(new \RuntimeException('connection lost'));
		// The update landed even though its answer did not.
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(true);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [],
			body: 'body',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		);
		$padFileService->method('readPad')->with('doc-before')->willReturn($parsedPad);
		$padFileService->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn(['text' => 'plain text', 'html' => '']);
		$padFileService->method('withStateAndSnapshot')->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . $newPadId);
		$etherpadClient->expects($this->never())->method('deletePad');
		$etherpadClient->expects($this->never())->method('deleteGroup');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abc123def456');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willReturn('doc-before');
		// Written once, with the restored content — and not put back.
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
		);

		$this->expectException(LifecycleException::class);
		$service->handleRestore($file);
	}

	public function testHandleRestoreTakesTheSupersededGroupWithIt(): void {
		$fileId = 84;
		$oldPadId = 'g.OLDGROUPID12345$p-old';
		$newPadId = 'g.NEWGROUPID12345$restored-abc123def456';
		$newPadUrl = 'https://pad.example.test/p/' . rawurlencode($newPadId);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'file_id' => $fileId,
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'state' => BindingService::STATE_PENDING_DELETE,
		]);
		$bindingService->expects($this->once())->method('markRestored')->with($fileId, $newPadId);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [],
			body: 'body',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		);
		$padFileService->method('readPad')->with('doc-before')->willReturn($parsedPad);
		$padFileService->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn(['text' => 'plain text', 'html' => '']);
		$padFileService->method('withStateAndSnapshot')->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createGroup')->willReturn('g.NEWGROUPID12345');
		$etherpadClient->method('createGroupPad')->willReturn($newPadId);
		$etherpadClient->method('setText')->with($newPadId, 'plain text');
		$etherpadClient->method('buildPadUrl')->with($newPadId)->willReturn($newPadUrl);
		$etherpadClient->expects($this->once())->method('listPads')->with('g.OLDGROUPID12345')->willReturn([$oldPadId]);
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.OLDGROUPID12345');
		$etherpadClient->expects($this->never())->method('deletePad');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abc123def456');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willReturn('doc-before');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$logger,
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
		);

		$result = $service->handleRestore($file);
		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($oldPadId, $result['old_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * And it stays a success when that leftover cannot be removed: the
	 * restore is already recorded, and the user's file works either way.
	 */
	public function testHandleRestoreStillSucceedsWhenTheSupersededPadSurvives(): void {
		$fileId = 85;
		$oldPadId = 'old-pad';
		$newPadId = 'r-old-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'file_id' => $fileId,
			'pad_id' => $oldPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_PENDING_DELETE,
		]);
		$bindingService->expects($this->once())->method('markRestored')->with($fileId, $newPadId);

		$padFileService = $this->createMock(PadFileService::class);
		$parsedPad = new ParsedPadFile(
			frontmatter: [],
			body: 'body',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		);
		$padFileService->method('readPad')->with('doc-before')->willReturn($parsedPad);
		$padFileService->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn(['text' => 'plain text', 'html' => '']);
		$padFileService->method('withStateAndSnapshot')->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . $newPadId);
		$etherpadClient->method('deletePad')->willReturnCallback(function (string $padId) use ($oldPadId): void {
			if ($padId === $oldPadId) {
				throw new \RuntimeException('still down');
			}
		});

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abc123def456');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willReturn('doc-before');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
		);

		$result = $service->handleRestore($file);
		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
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
		$parsedPad = new ParsedPadFile(
			frontmatter: [],
			body: 'body',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		);
		$padFileService->method('readPad')->with('doc-before')->willReturn($parsedPad);
		$padFileService->expects($this->once())->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn(['text' => 'plain text', 'html' => '<p>html text</p>']);
		$padFileService->expects($this->once())
			->method('withStateAndSnapshot')
			->with(
				$this->identicalTo($parsedPad),
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
		// The pad the restore replaced: once markRestored points the row at
		// the new one, nothing names the old one again.
		$etherpadClient->expects($this->once())->method('deletePad')->with($oldPadId);

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
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$config,
			$logger,
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
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
		$parsedPad = new ParsedPadFile(
			frontmatter: [
				'pad_id' => $oldPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => 'trashed',
			],
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/' . rawurlencode($oldPadId),
			isExternal: false,
		);
		$padFileService->method('readPad')->with('doc-before')->willReturn($parsedPad);
		$padFileService->method('getSnapshotPartsFromBody')->with($parsedPad->body)->willReturn([
			'text' => 'plain text',
			'html' => '',
		]);
		$padFileService->expects($this->once())
			->method('withStateAndSnapshot')
			->with($this->identicalTo($parsedPad), BindingService::STATE_ACTIVE, 'plain text', $newPadId, null, $newPadUrl)
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
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
		))->handleRestore($file);

		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['old_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	/**
	 * `createBinding` can commit and still throw, and the write that would
	 * have made the file agree with the row never runs. A flag says no row
	 * while a row is there naming the pad about to be deleted — which is
	 * how a binding ends up pointing at a pad that is gone.
	 */
	public function testHandleRestoreWithoutBindingRemovesARowItsFailedWriteLeftBehind(): void {
		$fileId = 92;
		$oldPadId = 'old-public-pad';
		$newPadId = 'r-old-public-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(true);
		$bindingService->expects($this->once())->method('deleteByFileId')->with($fileId);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . $newPadId);
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$this->expectException(LifecycleException::class);
		$this->buildNoBindingRestoreService($bindingService, $etherpadClient, $oldPadId, $newPadId)->handleRestore($file);
	}

	/**
	 * The other way that insert fails: a concurrent recovery for the same
	 * file got there first — the unique constraint is the serialization
	 * point. That row is theirs; only our pad goes.
	 */
	public function testHandleRestoreWithoutBindingLeavesARivalRecoverysRow(): void {
		$fileId = 93;
		$oldPadId = 'old-public-pad';
		$newPadId = 'r-old-public-pad-abc123def456';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('unique constraint violation'));
		$bindingService->method('isBoundTo')->with($fileId, $newPadId)->willReturn(false);
		$bindingService->expects($this->never())->method('deleteByFileId');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/' . $newPadId);
		$etherpadClient->expects($this->once())->method('deletePad')->with($newPadId);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->method('getContent')->willReturn('doc-before');

		$this->expectException(LifecycleException::class);
		$this->buildNoBindingRestoreService($bindingService, $etherpadClient, $oldPadId, $newPadId)->handleRestore($file);
	}

	/** A file with no binding row, whose frontmatter names a public pad. */
	private function buildNoBindingRestoreService(
		BindingService $bindingService,
		EtherpadClient $etherpadClient,
		string $oldPadId,
		string $newPadId,
	): LifecycleService {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => $oldPadId, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => 'trashed'],
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/' . rawurlencode($oldPadId),
			isExternal: false,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'plain text', 'html' => '']);
		$padFileService->method('withStateAndSnapshot')->willReturn('doc-after');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abc123def456');

		return new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
		);
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
		$padFileService->method('readPad')->with('doc-before')->willReturn(new ParsedPadFile(
			frontmatter: $frontmatter,
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: $padUrl,
			isExternal: true,
		));
		$padFileService->expects($this->never())->method('getSnapshotPartsFromBody');
		$padFileService->expects($this->never())->method('withStateAndSnapshot');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createPad');
		$etherpadClient->expects($this->never())->method('setText');
		$etherpadClient->expects($this->never())->method('setHTML');
		$etherpadClient->expects($this->never())->method('deletePad');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('External.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = (new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
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
		$padFileService->method('readPad')->with('doc-before')->willReturn(new ParsedPadFile(
			frontmatter: $frontmatter,
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		));
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

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('External.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = (new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
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
		$padFileService->method('readPad')->with('doc-before')->willReturn(new ParsedPadFile(
			frontmatter: $frontmatter,
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: $padUrl,
			isExternal: true,
		));
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

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('External.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->never())->method('putContent');

		$result = (new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
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
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: [
				'pad_id' => $oldPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: 'https://pad.example.test/p/' . rawurlencode($oldPadId),
			isExternal: false,
		));
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
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
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
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => $oldPadId, 'access_mode' => BindingService::ACCESS_PUBLIC],
			body: '',
			padId: $oldPadId,
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		));
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
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$secureRandom,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
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
			new ManagedPadLifecycle($this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
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
			new ManagedPadLifecycle($this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PathNormalizer::class),
		);

		$this->expectException(NotAPadFileException::class);
		$service->recoverFromSnapshot($file);
	}

	// ------------------------------------------------------------------
	// Wrapper tests for trashByPath / restoreByPath / recoverByFileId.
	// These were previously in PadLifecycleOperationServiceTest and now
	// live here since the reshape logic moved into LifecycleService.
	// They verify that the public wrappers resolve the node, call the
	// underlying lifecycle step, and reshape the result into the public
	// shape expected by controllers.
	// ------------------------------------------------------------------

	public function testTrashByPathReshapesSkippedResult(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('Test.pad');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$etherpadClient = $this->createMock(EtherpadClient::class);

		// delete_on_trash disabled => skipped before any binding/etherpad work.
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'delete_on_trash') {
					return 'no';
				}
				return $default;
			}
		);
		$config->method('getSystemValueBool')->willReturn(false);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Test.pad')
			->willReturn($file);

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$config,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$userNodeResolver,
			new PathNormalizer(),
		);

		$result = $service->trashByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'delete_on_trash_disabled',
		], $result);
	}

	public function testTrashByPathReshapesTrashedResult(): void {
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
		$bindingService->expects($this->once())->method('deleteByFileId')->with($fileId);

		$padFileService = $this->createMock(PadFileService::class);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('deletePad')->with($padId);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Test.pad');
		$file->method('getContent')->willReturn('');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Test.pad')
			->willReturn($file);

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$userNodeResolver,
			new PathNormalizer(),
		);

		$result = $service->trashByPath('alice', '/Test.pad');

		$this->assertSame('/Test.pad', $result['file']);
		$this->assertSame(LifecycleService::RESULT_TRASHED, $result['status']);
		$this->assertIsInt($result['deleted_at']);
		$this->assertFalse($result['snapshot_persisted']);
		$this->assertFalse($result['delete_pending']);
		$this->assertArrayNotHasKey('reason', $result);
	}

	public function testTrashByPathRejectsEmptyPath(): void {
		$service = new LifecycleService(
			$this->createMock(BindingService::class),
			$this->createMock(PadFileService::class),
			$this->createMock(EtherpadClient::class),
			new ManagedPadLifecycle($this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(UserNodeResolver::class),
			new PathNormalizer(),
		);

		$this->expectException(\InvalidArgumentException::class);
		$service->trashByPath('alice', '   ');
	}

	public function testRestoreByPathReshapesSkippedResult(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('Notes.txt'); // not a .pad => skipped

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Test.pad')
			->willReturn($file);

		$service = new LifecycleService(
			$bindingService,
			$this->createMock(PadFileService::class),
			$this->createMock(EtherpadClient::class),
			new ManagedPadLifecycle($this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$userNodeResolver,
			new PathNormalizer(),
		);

		$result = $service->restoreByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'not_pad_file',
		], $result);
	}

	public function testRecoverByFileIdReshapesSkippedExternalPadResult(): void {
		$fileId = 51;

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('readPad')
			->with('doc')
			->willReturn(new ParsedPadFile(
				frontmatter: ['pad_id' => 'ext.abc', 'access_mode' => BindingService::ACCESS_PUBLIC],
				body: '',
				padId: 'ext.abc',
				accessMode: BindingService::ACCESS_PUBLIC,
				padUrl: '',
				isExternal: true,
			));

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Ext.pad');
		$file->method('getContent')->willReturn('doc');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeById')
			->with('alice', $fileId)
			->willReturn($file);

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$this->createMock(EtherpadClient::class),
			new ManagedPadLifecycle($this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
			$this->buildDeleteOnTrashEnabledConfig(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ISecureRandom::class),
			$userNodeResolver,
			new PathNormalizer(),
		);

		$result = $service->recoverByFileId('alice', $fileId);

		$this->assertSame([
			'file_id' => $fileId,
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'external_pad',
		], $result);
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
