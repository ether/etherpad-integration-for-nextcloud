<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RestoreFromTrashListener;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleResult;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Tests\Support\WatchesTheWholeLogger;
use OCA\EtherpadNextcloud\Tests\Support\WiresTheLifecycle;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RestoreFromTrashListenerTest extends TestCase {
	use WatchesTheWholeLogger;
	use WiresTheLifecycle;

	private function restoreEventFor(File $file): Event {
		return new class($file) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		};
	}

	public function testTypedRestoreEventRestoresTargetFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Notes.pad');
		$file->method('getId')->willReturn(42);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($file)
			->willReturn(['status' => LifecycleResult::RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->createMock(IUserSession::class),
			$this->createMock(UserNodeResolver::class),
			$this->createMock(LoggerInterface::class),
		);

		$listener->handle(new class($file) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}

	public function testLegacyRestoreHookResolvesFilePathInUserFolder(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/G - Jacobs Test Gruppe/Neues Pad 9.pad')
			->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($file)
			->willReturn(['status' => LifecycleResult::RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$userSession,
			$resolver,
			$this->createMock(LoggerInterface::class),
		);

		$listener->handleLegacyHook([
			'filePath' => '/G - Jacobs Test Gruppe/Neues Pad 9.pad',
			'trashPath' => 'files_trashbin/files/Neues Pad 9.pad.d1777397341',
		]);
	}

	/**
	 * The hook's filePath is already relative to the user's files root, so
	 * it is resolved as given. A folder that happens to be called `files`
	 * is a folder like any other: cutting that segment off would answer
	 * with a different file of the same name one level up, and the
	 * lifecycle would then write to it.
	 */
	public function testAFolderNamedFilesIsResolvedAsTheFolderItIs(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/files/Notes.pad')
			->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($file)
			->willReturn(['status' => LifecycleResult::RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$userSession,
			$resolver,
			$this->createMock(LoggerInterface::class),
		);

		$listener->handleLegacyHook(['filePath' => '/files/Notes.pad']);
	}

	/**
	 * The test above pins what the listener asks for; this one pins what
	 * the answer is. A real resolver over a user folder holding both
	 * `files/Notes.pad` and a `Notes.pad` at the root, so a prefix cut
	 * anywhere between the hook and the lookup - in the listener or in the
	 * resolver - hands the lifecycle the wrong file, and this sees which.
	 */
	public function testARestoreThroughAFolderNamedFilesReachesThatFileAndNotItsNamesake(): void {
		$inFolder = $this->createMock(File::class);
		$inFolder->method('getId')->willReturn(7);
		$atRoot = $this->createMock(File::class);
		$atRoot->method('getId')->willReturn(8);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturnCallback(
			static fn(string $path): File => match ($path) {
				'files/Notes.pad' => $inFolder,
				'Notes.pad' => $atRoot,
				default => throw new NotFoundException($path),
			},
		);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($this->identicalTo($inFolder))
			->willReturn(['status' => LifecycleResult::RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->sessionFor('alice'),
			new UserNodeResolver($rootFolder),
			$this->createMock(LoggerInterface::class),
		);

		$listener->handleLegacyHook(['filePath' => '/files/Notes.pad']);
	}

	/**
	 * The hook fires for every restored item, folders included, and only a
	 * pad is this app's business. Anything else is left before a lookup is
	 * made - and without a word, because a restored folder is not a
	 * restore that went wrong.
	 */
	public function testANameThatIsNotAPadIsLeftAloneBeforeAnyLookup(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->never())->method('resolveUserFileNodeByPath');

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->never())->method('handleRestore');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->sessionFor('alice'),
			$resolver,
			$logger,
		);

		$listener->handleLegacyHook(['filePath' => '/Projects']);
		$listener->handleLegacyHook(['filePath' => '/Projects/notes.txt']);
	}

	/**
	 * Every way the hook path can pass over a pad says why, once, and keeps
	 * the failure inside: this runs in a hook slot, which lets nothing out
	 * (TrashbinHookHandler says why).
	 *
	 * @return iterable<string, array{\Closure(self): (UserNodeResolver&\PHPUnit\Framework\MockObject\MockObject), ?string, string}>
	 */
	public static function passedOverPadProvider(): iterable {
		yield 'no session' => [
			static fn(self $t) => $t->createMock(UserNodeResolver::class),
			null,
			'no user session to resolve the restored path against',
		];
		yield 'not found, or not a file' => [
			static function (self $t) {
				$r = $t->createMock(UserNodeResolver::class);
				$r->method('resolveUserFileNodeByPath')->willThrowException(new NotFoundException('Path does not reference a file.'));
				return $r;
			},
			'alice',
			'the restored path does not resolve to a file',
		];
		yield 'the lookup itself failed' => [
			static function (self $t) {
				$r = $t->createMock(UserNodeResolver::class);
				$r->method('resolveUserFileNodeByPath')->willThrowException(new \RuntimeException('storage unavailable'));
				return $r;
			},
			'alice',
			'the restored path could not be resolved',
		];
	}

	/** @param \Closure(self): UserNodeResolver $resolver */
	#[\PHPUnit\Framework\Attributes\DataProvider('passedOverPadProvider')]
	public function testAPadThatCannotBeTakenIsPassedOverWithItsReason(\Closure $resolver, ?string $uid, string $reason): void {
		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->never())->method('handleRestore');

		$logger = $this->createMock(LoggerInterface::class);
		$this->closeEveryLevelExcept($logger, 'warning');
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->anything(),
				$this->callback(function (array $context) use ($reason): bool {
					$this->assertSame($reason, $context['reason']);
					$this->assertSame('/Notes.pad', $context['filePath']);
					return true;
				}),
			);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$uid === null ? $this->noSession() : $this->sessionFor($uid),
			$resolver($this),
			$logger,
		);

		// No expectException: nothing may leave this frame.
		$listener->handleLegacyHook(['filePath' => '/Notes.pad']);
	}

	/** @return iterable<string, array{string}> */
	public static function restoreEntryProvider(): iterable {
		yield 'the typed event' => ['event'];
		yield 'the trashbin hook' => ['hook'];
	}

	/**
	 * Whatever the pad's restore throws - an error too, which Nextcloud 31
	 * does not catch around a hook - is reported and goes no further, by
	 * either way in: Nextcloud has restored the file before both, and a
	 * failure thrown on would only keep it from restoring the file's
	 * versions, or tell the user a restore failed that did not.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('restoreEntryProvider')]
	public function testNothingThePadsRestoreThrowsLeavesTheListener(string $entry): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('Notes.pad');
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeByPath')->willReturn($file);
		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())->method('handleRestore')->willThrowException(new \TypeError('a pad step gone wrong'));

		$logger = $this->createMock(LoggerInterface::class);
		$this->closeEveryLevelExcept($logger, 'error');
		$logger->expects($this->once())
			->method('error')
			->with(
				'Could not restore the pad of a file back from the trash. The file itself is restored.',
				$this->callback(function (array $context) use ($entry): bool {
					$this->assertSame(42, $context['fileId']);
					$this->assertSame($entry, $context['via']);
					$this->assertSame(\TypeError::class, $context['error']);
					return true;
				}),
			);

		$listener = new RestoreFromTrashListener($lifecycleService, $this->sessionFor('alice'), $resolver, $logger);

		// No expectException: nothing may leave the listener.
		if ($entry === 'hook') {
			$listener->handleLegacyHook(['filePath' => '/Notes.pad']);
		} else {
			$listener->handle($this->restoreEventFor($file));
		}
	}

	/**
	 * Only a .pad is this app's business, and its name says so before any
	 * lookup: another restored file costs nothing, not even on Nextcloud 31,
	 * where its id cannot be read yet. A name that cannot be read lets the
	 * node through, to be looked up by its path as before.
	 */
	public function testTheEventPassesOverAnotherFileByItsName(): void {
		$photo = $this->createMock(File::class);
		$photo->method('getName')->willReturn('Holiday.jpg');
		$photo->method('getId')->willThrowException(new NotFoundException('not resolvable yet'));
		$photo->expects($this->never())->method('getPath');
		$nameless = $this->createMock(File::class);
		$nameless->method('getName')->willThrowException(new NotFoundException('no name yet'));
		$nameless->method('getId')->willThrowException(new NotFoundException('not resolvable yet'));
		$nameless->method('getPath')->willReturn('/alice/files/Notes.pad');

		$resolved = $this->createMock(File::class);
		$resolved->method('getId')->willReturn(7);
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->once())->method('resolveUserFileNodeByPath')->with('alice', 'Notes.pad')->willReturn($resolved);
		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())->method('handleRestore')->with($resolved)->willReturn(LifecycleResult::restored('pad-a', 'pad-a'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		$listener = new RestoreFromTrashListener($lifecycleService, $this->createMock(IUserSession::class), $resolver, $logger);
		$listener->handle($this->restoreEventFor($photo));
		$listener->handle($this->restoreEventFor($nameless));
	}

	/**
	 * A core restore comes by the hook first and by the event after it. When
	 * the hook pass fails on something that passes - a database connection
	 * dropped - the event pass is a second try and restores the pad: the one
	 * error line names the hook, and the row ends up active. One line, since
	 * the restore reports nothing of its own; the listener is what reports.
	 *
	 * Real services rather than mocks: whether there is anything left to
	 * try again depends on what the failed pass leaves behind.
	 */
	public function testTheEventTriesAgainWhatTheHookCouldNotDo(): void {
		$tries = 0;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(new Binding(fileId: 4712, padId: 'old-pad', accessMode: BindingService::ACCESS_PUBLIC, state: BindingService::STATE_PENDING_DELETE));
		$bindingService->expects($this->exactly(2))
			->method('transition')
			->with(4712, 'old-pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_ACTIVE)
			->willReturnCallback(static function () use (&$tries): bool {
				return ++$tries === 1 ? throw new \RuntimeException('connection lost') : true;
			});
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(3);
		$padFiles = $this->createMock(PadFileService::class);
		$padFiles->method('readPad')->willReturn(new ParsedPadFile([], 'body', 'old-pad', BindingService::ACCESS_PUBLIC, '', false, 2));
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4712);
		$file->method('getName')->willReturn('Notes.pad');
		$file->method('getContent')->willReturn('doc');
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeByPath')->with('alice', '/Notes.pad')->willReturn($file);

		$logger = $this->createMock(LoggerInterface::class);
		$this->closeEveryLevelExcept($logger, 'error');
		$logger->expects($this->once())
			->method('error')
			->with(
				'Could not restore the pad of a file back from the trash. The file itself is restored.',
				$this->callback(function (array $context): bool {
					$this->assertSame('hook', $context['via']);
					$this->assertStringContainsString('connection lost', $context['error_origin']);
					return true;
				}),
			);

		$listener = new RestoreFromTrashListener(
			$this->lifecycleService(bindings: $bindingService, etherpad: $etherpadClient, padFiles: $padFiles, logger: $logger),
			$this->sessionFor('alice'),
			$resolver,
			$logger,
		);

		$listener->handleLegacyHook(['filePath' => '/Notes.pad']);
		$listener->handle($this->restoreEventFor($file));
	}

	/**
	 * A node whose id cannot be read would throw on handleRestore's first
	 * line, and be reported as a failed restore of its pad - on the event,
	 * where Nextcloud 31 hands over such a node, for every .pad restored.
	 * Both ways in hold the node they hand on to the same standard, so both
	 * are asserted: the check is shared today, and a way in that stopped
	 * going through it would otherwise go unnoticed.
	 *
	 * Passed over, and the skip carries what went wrong rather than a bare
	 * reason, so a stale node can be told from a storage outage.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('restoreEntryProvider')]
	public function testAnUnreadableNodeIsPassedOverWithItsCause(string $entry): void {
		$stillUnreadable = $this->createMock(File::class);
		$stillUnreadable->method('getId')->willThrowException(new NotFoundException('node went stale'));

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeByPath')->willReturn($stillUnreadable);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->never())->method('handleRestore');

		$logger = $this->createMock(LoggerInterface::class);
		$this->closeEveryLevelExcept($logger, 'warning');
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->anything(),
				$this->callback(function (array $context): bool {
					$this->assertSame('the node at the restored path has no readable id', $context['reason']);
					$this->assertSame(NotFoundException::class, $context['error']);
					$this->assertStringContainsString('node went stale', $context['error_message']);
					return true;
				}),
			);

		$listener = new RestoreFromTrashListener($lifecycleService, $this->sessionFor('alice'), $resolver, $logger);

		if ($entry === 'hook') {
			$listener->handleLegacyHook(['filePath' => '/Notes.pad']);
			return;
		}
		$unreadable = $this->createMock(File::class);
		$unreadable->method('getId')->willThrowException(new NotFoundException('not resolvable yet'));
		$unreadable->method('getName')->willReturn('Notes.pad');
		$unreadable->method('getPath')->willReturn('/alice/files/Notes.pad');
		$listener->handle($this->restoreEventFor($unreadable));
	}

	/**
	 * Nextcloud 31 hands the event a node that is not resolvable yet, so
	 * reading its id throws. That exception used to escape the listener and
	 * abort the restore itself — a .pad could not be brought back from the
	 * trash at all, through the web UI, WebDAV or `occ trashbin:restore`.
	 * The owner is taken from the path, which says whose file it is,
	 * rather than from the session, which never has to be asked.
	 */
	public function testUnresolvableEventTargetIsLookedUpByPath(): void {
		$unresolvable = $this->createMock(File::class);
		$unresolvable->method('getId')->willThrowException(new NotFoundException());
		$unresolvable->method('getName')->willReturn('notes.pad');
		$unresolvable->method('getPath')->willReturn('/alice/files/notes.pad');

		$resolved = $this->createMock(File::class);
		$resolved->method('getId')->willReturn(42);

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', 'notes.pad')
			->willReturn($resolved);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->never())->method('getUser');

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($resolved)
			->willReturn(['status' => LifecycleResult::RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$userSession,
			$resolver,
			$this->createMock(LoggerInterface::class),
		);

		$listener->handle(new class($unresolvable) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}

	/**
	 * A node can go stale between being handed over and the lifecycle
	 * failing, so the error path must survive an id it can no longer read.
	 * Logging that threw a second time used to replace the exception being
	 * reported, which is why the Nextcloud 31 failure showed up in the log
	 * as a bare NotFoundException with nothing about its cause.
	 *
	 * Surviving is half of it. Since the flow stopped reporting its own
	 * failures this listener is the only one left that can, so the entry
	 * has to be there too - an id that cannot be read leaves it out rather
	 * than standing in for it.
	 */
	public function testALifecycleErrorIsReportedWhenTheIdCannotBeRead(): void {
		$reads = 0;
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Notes.pad');
		$file->method('getId')->willReturnCallback(function () use (&$reads): int {
			$reads++;
			if ($reads === 1) {
				return 42;
			}
			throw new NotFoundException();
		});

		$boom = new \RuntimeException('lifecycle exploded');
		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('handleRestore')->willThrowException($boom);

		$logger = $this->createMock(LoggerInterface::class);
		$this->closeEveryLevelExcept($logger, 'error');
		$logger->expects($this->once())
			->method('error')
			->with(
				$this->anything(),
				$this->callback(function (array $context) use ($boom): bool {
					$this->assertNull($context['fileId'], 'an id that cannot be read is absent, not invented');
					$this->assertSame(\RuntimeException::class, $context['error']);
					$this->assertStringContainsString($boom->getMessage(), $context['error_message']);
					return true;
				}),
			);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->createMock(IUserSession::class),
			$this->createMock(UserNodeResolver::class),
			$logger,
		);

		$listener->handle(new class($file) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}

	/** A path that is not /<user>/files/... is skipped, and says so. */
	public function testUnexpectedPathShapeIsSkippedWithAReason(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willThrowException(new NotFoundException());
		$node->method('getName')->willReturn('else.pad');
		$node->method('getPath')->willReturn('/somewhere/else.pad');

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->never())->method('resolveUserFileNodeByPath');

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->never())->method('handleRestore');

		$logger = $this->createMock(LoggerInterface::class);
		$this->closeEveryLevelExcept($logger, 'warning');
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->anything(),
				$this->callback(function (array $context): bool {
					$this->assertSame('the restored node\'s path is not /<user>/files/<path>', $context['reason']);
					// The path as the node gave it, so the entry shows what did not fit.
					$this->assertSame('/somewhere/else.pad', $context['filePath']);
					return true;
				}),
			);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->createMock(IUserSession::class),
			$resolver,
			$logger,
		);

		$listener->handle(new class($node) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}

	private function sessionFor(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	private function noSession(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		return $session;
	}
}
