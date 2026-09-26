<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\LeavingPadsListener;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\GoneFileSweep;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What leaving Files marks: a `.pad` file's row, every row under a folder,
 * every row of a user deleted. What a delete marked is handed on for its
 * pads once the delete is done. Nothing it does may stop the trash or the
 * delete it hears of.
 */
class LeavingPadsListenerTest extends TestCase {
	public function testMarksWhatLeavesFiles(): void {
		$pad = $this->file(7, 'Notes.pad');
		$text = $this->file(8, 'Notes.txt');
		$folder = $this->folder(9);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$home = $this->folder(3);

		$cases = [
			'a .pad file to the trash' => [$this->trashEvent($pad), [['file', 7]]],
			'another file to the trash' => [$this->trashEvent($text), []],
			'a folder to the trash' => [$this->trashEvent($folder), [['under', 9, false]]],
			'a .pad file deleted' => [new BeforeNodeDeletedEvent($pad), [['file', 7]]],
			'a folder deleted' => [new BeforeNodeDeletedEvent($folder), [['under', 9, false]]],
			'a user deleted' => [new BeforeUserDeletedEvent($user), [['under', 3, true]]],
			'anything else' => [new Event(), []],
		];
		foreach ($cases as $case => [$event, $expected]) {
			$marks = [];
			$bindings = $this->createMock(BindingService::class);
			$bindings->method('markTrashed')->willReturnCallback(static function (int $fileId) use (&$marks): void {
				$marks[] = ['file', $fileId];
			});
			$bindings->method('markTrashedUnder')->willReturnCallback(static function (int $folderId, bool $wholeStorage = false) use (&$marks): array {
				$marks[] = ['under', $folderId, $wholeStorage];
				return [];
			});
			$rootFolder = $this->createMock(IRootFolder::class);
			$rootFolder->method('getUserFolder')->with('alice')->willReturn($home);

			(new LeavingPadsListener($bindings, $this->createMock(GoneFileSweep::class), $rootFolder, $this->createMock(LoggerInterface::class)))->handle($event);

			$this->assertSame($expected, $marks, $case);
		}
	}

	/**
	 * Once a delete is done, the files it marked go to the sweep's deletion
	 * of files deleted past the trash, each once - a delete that failed
	 * before it leaves its files there, too - and are forgotten. A move
	 * to the trash and a user deleted hand nothing on: the first keeps its
	 * files, the second may take thousands.
	 */
	public function testHandsOnWhatADeleteMarkedOnceItIsDone(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('markTrashedUnder')->willReturnCallback(static fn (int $folderId): array => $folderId === 9 ? [21, 22] : [31]);
		$handedOn = [];
		$sweep = $this->createMock(GoneFileSweep::class);
		$sweep->method('discardDeleted')->willReturnCallback(static function (array $fileIds) use (&$handedOn): void {
			$handedOn[] = $fileIds;
		});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($this->folder(3));
		$listener = new LeavingPadsListener($bindings, $sweep, $rootFolder, $this->createMock(LoggerInterface::class));
		$pad = $this->file(7, 'Notes.pad');

		$listener->handle($this->trashEvent($this->folder(5)));
		$listener->handle(new BeforeUserDeletedEvent($user));
		$listener->handle(new BeforeNodeDeletedEvent($pad));
		$listener->handle(new BeforeNodeDeletedEvent($pad));
		$listener->handle(new BeforeNodeDeletedEvent($this->folder(9)));
		$listener->handle(new NodeDeletedEvent($pad));
		$listener->handle(new NodeDeletedEvent($pad));

		$this->assertSame([[7, 21, 22], []], $handedOn);
	}

	/** A mark that fails is a warning; the trash goes ahead, and the sweep finds the file later. */
	public function testAMarkThatFailsNeverStopsTheTrash(): void {
		$bindings = $this->createMock(BindingService::class);
		$bindings->method('markTrashed')->willThrowException(new \RuntimeException('the database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not mark the pads of files leaving Files; the sweep finds them later.', $this->anything());

		(new LeavingPadsListener($bindings, $this->createMock(GoneFileSweep::class), $this->createMock(IRootFolder::class), $logger))->handle($this->trashEvent($this->file(7, 'Notes.pad')));
	}

	private function file(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	private function folder(int $id): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		return $folder;
	}

	/** The trash app's MoveToTrashEvent, which carries its node under getNode(). */
	private function trashEvent(object $node): Event {
		return new class($node) extends Event {
			public function __construct(private object $node) {
			}

			public function getNode(): object {
				return $this->node;
			}
		};
	}
}
