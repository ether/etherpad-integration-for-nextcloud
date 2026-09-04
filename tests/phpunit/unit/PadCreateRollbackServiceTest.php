<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\EtherpadClient;
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
		$stillOurs = $this->createMock(File::class);
		$stillOurs->expects($this->once())->method('delete');

		$this->buildService(userNodeResolver: $this->resolverFinding($stillOurs))
			->rollbackFailedCreate('alice', '/Created.pad', '', $this->createdNode());
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
			->rollbackFailedCreate('alice', '/Created.pad', '', $this->createdNode());
	}

	/**
	 * No id, no proof of which file this is. A stray empty `.pad` is a
	 * mess; deleting the wrong file is a loss.
	 */
	public function testLeavesTheFileAloneWhenItsIdCannotBeRead(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willThrowException(new \RuntimeException('no cache entry'));
		$node->expects($this->never())->method('delete');

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->never())->method('resolveUserFileNodeById');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('no usable id'), $this->anything());

		$this->buildService(logger: $logger, userNodeResolver: $resolver)
			->rollbackFailedCreate('alice', '/Created.pad', '', $node);
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
			->rollbackFailedCreate('alice', '/Created.pad', '', $this->createdNode());
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
		$resolved = $this->createMock(File::class);
		$resolved->method('delete')->willThrowException(new \RuntimeException('nope'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Could not cleanup failed .pad file create'),
				$this->callback(static fn (array $c): bool => $c['file'] === '/Created.pad' && $c['uid'] === 'alice'),
			);

		$this->buildService(logger: $logger, userNodeResolver: $this->resolverFinding($resolved))
			->rollbackFailedCreate('alice', '/Created.pad', '', $this->createdNode());
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
		$resolved = $this->createMock(File::class);
		$resolved->expects($this->once())->method('delete');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad, userNodeResolver: $this->resolverFinding($resolved))
			->rollbackCreatedFileOnly('alice', '/Created.pad', $this->createdNode());
	}

	/**
	 * An external create links a pad that already exists elsewhere, so the
	 * file goes and nothing is deleted on the Etherpad side.
	 */
	public function testExternalRollbackDeletesTheFileButNoPad(): void {
		$resolved = $this->createMock(File::class);
		$resolved->expects($this->once())->method('delete');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad, userNodeResolver: $this->resolverFinding($resolved))
			->rollbackExternalCreate('alice', '/Created.pad', $this->createdNode());
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

	/** A node the create returned, carrying an id but nothing else. */
	private function createdNode(int $fileId = 4711): File {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($fileId);
		$node->expects($this->never())->method('delete');

		return $node;
	}
}
