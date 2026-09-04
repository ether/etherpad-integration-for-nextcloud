<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadCreateRollbackServiceTest extends TestCase {
	public function testTouchesNothingWhenNoFileWasCreated(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad)
			->rollbackFailedCreate('alice', '/Existing.pad', '', null);
	}

	/**
	 * By id, never through the node the create returned. `File::delete()`
	 * unlinks its remembered path, and Etherpad provisioning lasts long
	 * enough for that path to hold somebody else's file by the time the
	 * rollback runs.
	 */
	public function testDeletesTheFileTheIdResolvesToNow(): void {
		$stillOurs = $this->untouchedFile();
		$stillOurs->expects($this->once())->method('delete');

		$this->buildService(userNodeResolver: $this->resolverFinding($stillOurs))
			->rollbackFailedCreate('alice', '/Created.pad', '', new CreatedFileClaim('alice', 4711));
	}

	/**
	 * The substitution this exists to prevent: our file was moved away and
	 * another one took the name. The id finds nothing, so nothing is
	 * deleted — and the stranger's file survives.
	 */
	public function testDeletesNothingWhenTheCreatedFileIsNoLongerThere(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willThrowException(new NotFoundException('gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->buildService(logger: $logger, userNodeResolver: $resolver)
			->rollbackFailedCreate('alice', '/Created.pad', '', new CreatedFileClaim('alice', 4711));
	}

	/**
	 * The create never read an id it could trust, so nothing here names a
	 * file. Asking the old node for one now would answer for whatever holds
	 * that path by now — which is the substitution this exists to prevent.
	 */
	public function testLooksUpNothingWhenTheCreateNeverGotAnId(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->never())->method('resolveUserFileNodeById');

		$this->buildService(userNodeResolver: $resolver)
			->rollbackFailedCreate('alice', '/Created.pad', '', null);
	}

	/**
	 * The id is not proof of authorship: `newFile()` can hand back a file
	 * another writer created a moment earlier, and that writer may have
	 * filled it while this request was away at Etherpad.
	 */
	public function testLeavesAFileSomebodyElseWroteInto(): void {
		$foreign = $this->fileHolding('someone else\'s notes');
		$foreign->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('not what this create wrote'), $this->anything());

		$this->buildService(logger: $logger, userNodeResolver: $this->resolverFinding($foreign))
			->rollbackFailedCreate('alice', '/Created.pad', '', $this->claimThatWrote('our document'));
	}

	/**
	 * A rival create can finish into the same file. Its document carries the
	 * same `file_id` — the id belongs to the file, not to the attempt — so
	 * only the bytes this attempt wrote settle it.
	 */
	public function testLeavesADocumentAnotherCreateWroteIntoTheSameFile(): void {
		$rivals = $this->fileHolding("---\nfile_id: 4711\npad_id: nc-rival\n---\n");
		$rivals->expects($this->never())->method('delete');

		$this->buildService(userNodeResolver: $this->resolverFinding($rivals))
			->rollbackFailedCreate('alice', '/Created.pad', 'nc-own', $this->claimThatWrote("---\nfile_id: 4711\npad_id: nc-own\n---\n"));
	}

	/** Exactly the bytes this create wrote, so this one is ours to remove. */
	public function testDeletesAFileHoldingWhatThisCreateWrote(): void {
		$ours = $this->fileHolding('our document');
		$ours->expects($this->once())->method('delete');

		$this->buildService(userNodeResolver: $this->resolverFinding($ours))
			->rollbackFailedCreate('alice', '/Created.pad', '', $this->claimThatWrote('our document'));
	}

	/**
	 * Both give up rather than delete. They are the conservative half of the
	 * rule, and an exception escaping either would turn a best-effort
	 * cleanup into a failure that hides the original create error.
	 */
	public function testLeavesTheFileAloneWhenItsContentCannotBeRead(): void {
		$locked = $this->createMock(File::class);
		$locked->method('getSize')->willReturn(120);
		$locked->method('getContent')->willThrowException(new \OCP\Lock\LockedException('/Created.pad'));
		$locked->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not be read'), $this->anything());

		$this->buildService(logger: $logger, userNodeResolver: $this->resolverFinding($locked))
			->rollbackFailedCreate('alice', '/Created.pad', '', $this->claimThatWrote('our document'));
	}

	public function testSwallowsAFailedLookup(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willThrowException(new \RuntimeException('storage down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('Could not look up'), $this->anything());

		$this->buildService(logger: $logger, userNodeResolver: $resolver)
			->rollbackFailedCreate('alice', '/Created.pad', '', new CreatedFileClaim('alice', 4711));
	}

	/** A claim whose write succeeded, carrying proof of those exact bytes. */
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

	/**
	 * A create that failed before writing anything still leaves a file. It
	 * has to go, or the name stays blocked and the user's retry is refused
	 * by the pre-check with no way to see why.
	 */
	public function testDeletesAFileNothingWasWrittenTo(): void {
		$empty = $this->createMock(File::class);
		$empty->method('getSize')->willReturn(0);
		$empty->expects($this->once())->method('delete');

		$this->buildService(userNodeResolver: $this->resolverFinding($empty))
			->rollbackFailedCreate('alice', '/Created.pad', '', new CreatedFileClaim('alice', 4711));
	}

	public function testDeletesTheProvisionedPad(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())->method('deletePad')->with('nc-abcdef0123456789');

		$this->buildService(etherpad: $etherpad)
			->rollbackFailedCreate('alice', '/Created.pad', 'nc-abcdef0123456789', null);
	}

	/**
	 * A protected pad is removed by its group — and without asking Etherpad
	 * whose group it is. A rollback only ever holds a pad its own request
	 * provisioned, and nothing retries it: making the delete wait on a read
	 * would mean one timed-out read strands a group and its sessions for
	 * good, on a group nothing else has ever seen.
	 */
	public function testTakesTheGroupOfAProvisionedProtectedPadWithoutAsking(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('listPads');
		$etherpad->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad)
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABCDEFGHIJKLMNOP$pad', null);
	}

	public function testLeavesEtherpadAloneWithoutAPadId(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad)
			->rollbackFailedCreate('alice', '/Created.pad', '', null);
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
			->rollbackFailedCreate('alice', '/Created.pad', '', new CreatedFileClaim('alice', 4711));
	}

	public function testReportsButSwallowsAPadDeleteFailure(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('deletePad')->willThrowException(new \RuntimeException('etherpad down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Could not cleanup failed Etherpad create'), $this->anything());

		$this->buildService(etherpad: $etherpad, logger: $logger)
			->rollbackFailedCreate('alice', '/Created.pad', 'nc-abcdef0123456789', null);
	}

	/**
	 * For the flows that clean up their own pad. Routed through the
	 * file-only method rather than relying on an empty pad id to skip the
	 * pad step, so a future pad-side step cannot leak into it.
	 */
	public function testFileOnlyRollbackLeavesThePadAlone(): void {
		$resolved = $this->untouchedFile();
		$resolved->expects($this->once())->method('delete');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad, userNodeResolver: $this->resolverFinding($resolved))
			->rollbackCreatedFileOnly('alice', '/Created.pad', new CreatedFileClaim('alice', 4711));
	}

	/**
	 * An external create links a pad that already exists elsewhere, so the
	 * file goes and nothing is deleted on the Etherpad side.
	 */
	public function testExternalRollbackDeletesTheFileButNoPad(): void {
		$resolved = $this->untouchedFile();
		$resolved->expects($this->once())->method('delete');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad, userNodeResolver: $this->resolverFinding($resolved))
			->rollbackExternalCreate('alice', '/Created.pad', new CreatedFileClaim('alice', 4711));
	}

	private function buildService(
		?EtherpadClient $etherpad = null,
		?LoggerInterface $logger = null,
		?UserNodeResolver $userNodeResolver = null,
	): PadCreateRollbackService {
		return new PadCreateRollbackService(
			new ManagedPadLifecycle($etherpad ?? $this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * The file the id resolves to now — which is what the service deletes,
	 * so a test that stubs only the create's own node proves nothing.
	 */
	private function resolverFinding(File $node, int $fileId = 4711): UserNodeResolver {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->with('alice', $fileId)->willReturn($node);

		return $resolver;
	}

	/** An untouched file: still empty, so still ours to remove. */
	private function untouchedFile(): File {
		$node = $this->createMock(File::class);
		$node->method('getSize')->willReturn(0);

		return $node;
	}
}
