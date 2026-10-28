<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PadCreateAttempt;
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\EtherpadNextcloud\Service\ProvisionedPadRollback;

class PadCreateRollbackServiceTest extends TestCase {
	public function testTouchesNothingWhenNoFileWasCreated(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->never())->method('resolveUserFileNodeById');

		$this->buildService(etherpad: $etherpad, userNodeResolver: $resolver)
			->rollbackFailedCreate('alice', $this->attempt('', null, path: '/Existing.pad'));
	}

	public function testDeletesTheEmptyFileResolvedFromTheCapturedId(): void {
		$stillOurs = $this->untouchedFile();
		$stillOurs->expects($this->once())->method('delete');

		$this->buildService(userNodeResolver: $this->resolverFinding($stillOurs))
			->rollbackFailedCreate('alice', $this->attempt('', new CreatedFileClaim('alice', 4711)));
	}

	public function testDeletesNothingWhenTheCreatedFileIsNoLongerThere(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willThrowException(new NotFoundException('gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->buildService(logger: $logger, userNodeResolver: $resolver)
			->rollbackFailedCreate('alice', $this->attempt('', new CreatedFileClaim('alice', 4711)));
	}

	/**
	 * A create that never wrote has no hash to compare, so content in the
	 * file is somebody else's by definition. Deleting it would take work
	 * this create never did.
	 */
	public function testLeavesContentInAFileThisCreateNeverWroteTo(): void {
		$foreign = $this->fileHolding('notes somebody else put here');
		$foreign->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('content this create never wrote'), $this->anything());

		$this->buildService(logger: $logger, userNodeResolver: $this->resolverFinding($foreign))
			->rollbackFailedCreate('alice', $this->attempt('', new CreatedFileClaim('alice', 4711)));
	}

	/** Matching file_id values do not prove that this attempt wrote the document. */
	public function testLeavesADocumentAnotherCreateWroteIntoTheSameFile(): void {
		$rivals = $this->fileHolding("---\nfile_id: 4711\npad_id: nc-rival\n---\n");
		$rivals->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('not what this create wrote'), $this->anything());

		$this->buildService(logger: $logger, userNodeResolver: $this->resolverFinding($rivals))
			->rollbackFailedCreate('alice', $this->attempt('nc-own', $this->claimThatWrote("---\nfile_id: 4711\npad_id: nc-own\n---\n")));
	}

	public function testDeletesAFileHoldingWhatThisCreateWrote(): void {
		$ours = $this->fileHolding('our document');
		$ours->expects($this->once())->method('delete');

		$this->buildService(userNodeResolver: $this->resolverFinding($ours))
			->rollbackFailedCreate('alice', $this->attempt('', $this->claimThatWrote('our document')));
	}

	/** A read failure must not delete the file or mask the original create error. */
	public function testLeavesTheFileAloneWhenItsContentCannotBeRead(): void {
		$locked = $this->createMock(File::class);
		$locked->method('getSize')->willReturn(120);
		$locked->method('getContent')->willThrowException(new \OCP\Lock\LockedException('/Created.pad'));
		$locked->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not be read'), $this->anything());

		$this->buildService(logger: $logger, userNodeResolver: $this->resolverFinding($locked))
			->rollbackFailedCreate('alice', $this->attempt('', $this->claimThatWrote('our document')));
	}

	/** Reads as empty despite a recorded write: kept with a warning. */
	public function testLeavesAFileEmptiedAfterThisCreateWroteIt(): void {
		$emptied = $this->untouchedFile();
		$emptied->expects($this->never())->method('delete');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Left a .pad file in place: it reads as empty, though this create wrote it', ['app' => 'etherpad_nextcloud', 'file' => '/Created.pad']);

		$this->buildService(logger: $logger, userNodeResolver: $this->resolverFinding($emptied))
			->rollbackFailedCreate('alice', $this->attempt('', $this->claimThatWrote('our document')));
	}

	public function testSwallowsAFailedLookup(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willThrowException(new \RuntimeException('storage down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('Could not look up'), $this->anything());

		$this->buildService(logger: $logger, userNodeResolver: $resolver)
			->rollbackFailedCreate('alice', $this->attempt('', new CreatedFileClaim('alice', 4711)));
	}

	private function claimThatWrote(string $content): CreatedFileClaim {
		$claim = new CreatedFileClaim('alice', 4711);
		$claim->writtenHash = hash('sha256', $content);

		return $claim;
	}

	private function fileHolding(string $content): File {
		$node = $this->createMock(File::class);
		$node->method('getSize')->willReturn(strlen($content));
		$node->method('getContent')->willReturn($content);

		return $node;
	}

	/** A cleanup failure must not replace the error that caused the rollback. */
	public function testReportsButSwallowsADeleteFailure(): void {
		$resolved = $this->untouchedFile();
		$resolved->method('delete')->willThrowException(new \RuntimeException('nope'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Could not cleanup failed .pad file create'),
				$this->callback(static fn (array $c): bool => $c['file'] === '/Created.pad' && $c['uid'] === 'alice'),
			);

		$this->buildService(logger: $logger, userNodeResolver: $this->resolverFinding($resolved))
			->rollbackFailedCreate('alice', $this->attempt('', new CreatedFileClaim('alice', 4711)));
	}

	/** The file goes, and a pad the attempt names stays. */
	public function testFileOnlyRollbackLeavesThePadAlone(): void {
		$resolved = $this->untouchedFile();
		$resolved->expects($this->once())->method('delete');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad, userNodeResolver: $this->resolverFinding($resolved))
			->rollbackCreatedFileOnly('alice', $this->attempt('nc-abc', new CreatedFileClaim('alice', 4711), 4711));
	}

	/**
	 * The pad goes by the rule every create's pad goes by
	 * (ProvisionedPadRollback::discardCreated()), handed what the attempt
	 * made: its pad, the file its binding write began for, and its file.
	 */
	public function testHandsThePadToTheRuleOfEveryCreate(): void {
		$calls = [];
		$rollback = $this->createMock(ProvisionedPadRollback::class);
		$rollback->method('discardCreated')->willReturnCallback(static function (PadCreateAttempt $attempt, ?int $fileId, string $operation) use (&$calls): void {
			$calls[] = [$attempt->padId(), $attempt->bindingAttemptFileId(), $fileId, $operation];
		});
		$service = new PadCreateRollbackService($this->resolverFinding($this->untouchedFile()), $this->createMock(LoggerInterface::class), $rollback);

		$service->rollbackFailedCreate('alice', $this->attempt('nc-abc', new CreatedFileClaim('alice', 4711), 4711));
		$service->rollbackFailedCreate('alice', $this->attempt('nc-abc', new CreatedFileClaim('alice', 4711)));
		$service->rollbackFailedCreate('alice', $this->attempt('nc-abc', null));
		$service->rollbackFailedCreate('alice', $this->attempt('', new CreatedFileClaim('alice', 4711)));

		$this->assertSame([
			['nc-abc', 4711, 4711, 'create'],
			['nc-abc', null, 4711, 'create'],
			['nc-abc', null, null, 'create'],
		], $calls);
	}

	private function buildService(
		?BindingService $bindingService = null,
		?EtherpadClient $etherpad = null,
		?LoggerInterface $logger = null,
		?UserNodeResolver $userNodeResolver = null,
	): PadCreateRollbackService {
		$logger ??= $this->createMock(LoggerInterface::class);
		return new PadCreateRollbackService(
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$logger,
			new ProvisionedPadRollback($bindingService ?? $this->createMock(BindingService::class), new ManagedPadLifecycle($etherpad ?? $this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)), $logger),
		);
	}

	/**
	 * What a create made so far: its path, the file it claimed and what it
	 * wrote there, its pad, and the file its binding write began for.
	 */
	private function attempt(string $padId, ?CreatedFileClaim $claim, ?int $bindingAttemptFileId = null, string $path = '/Created.pad'): PadCreateAttempt {
		$attempt = new PadCreateAttempt();
		$attempt->recordPath($path);
		if ($claim !== null) {
			$attempt->claimFile($claim->uid, $claim->fileId)->writtenHash = $claim->writtenHash;
		}
		$attempt->recordPad($padId);
		if ($bindingAttemptFileId !== null) {
			$attempt->recordBindingAttempt($bindingAttemptFileId);
		}
		return $attempt;
	}

	/** Pin the lookup identity and return the node used for cleanup. */
	private function resolverFinding(File $node, int $fileId = 4711): UserNodeResolver {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->with('alice', $fileId)->willReturn($node);

		return $resolver;
	}

	private function untouchedFile(): File {
		$node = $this->createMock(File::class);
		$node->method('getSize')->willReturn(0);

		return $node;
	}
}
