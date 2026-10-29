<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadFileNotWritableException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\UnrecognisedPadContentException;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ClaimedFileWriter;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadLegacyMigrationService;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCA\EtherpadNextcloud\Service\ProvisionedPadRollback;
use OCP\Files\File;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadBootstrapServiceTest extends TestCase {
	public function testInitializeMissingFrontmatterCreatesProtectedPadAndWritesDocument(): void {
		$fileId = 42;
		$padId = 'g.testgroup$p-abcdefghijklmnopqrst';
		$padUrl = 'https://pad.example.test/p/g.testgroup%24p-abcdefghijklmnopqrst';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with($fileId, $padId, BindingService::ACCESS_PROTECTED);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('parseLegacyOwnpadShortcut')
			->with('')
			->willReturn(null);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with($fileId, $padId, BindingService::ACCESS_PROTECTED, null, $padUrl)
			->willReturn('doc-content');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createGroup')
			->willReturn('g.testgroup');
		$etherpadClient->expects($this->once())
			->method('createGroupPad')
			->with('g.testgroup', 'p-abcdefghijklmnopqrst')
			->willReturn($padId);
		$etherpadClient->expects($this->once())
			->method('buildPadUrl')
			->with($padId)
			->willReturn($padUrl);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->expects($this->once())
			->method('generate')
			->with(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abcdefghijklmnopqrst');

		$file = $this->writableFile();
		$file->expects($this->once())->method('putContent')->with('doc-content');

		$service = $this->bootstrap($bindingService, $padFileService, $etherpadClient, secureRandom: $secureRandom, file: $file);
		$service->initializeMissingFrontmatter('alice', $fileId, '');
	}

	/**
	 * createBinding failed, and a row may be there all the same. One naming
	 * this pad committed before the answer got back: it keeps the pad, and
	 * the next open writes the file. One naming another pad is a concurrent
	 * first open's: it stays, and this call's own group goes without asking
	 * Etherpad what the group holds.
	 *
	 * @return iterable<string,array{bool}>
	 */
	public static function rowsAFailedBindingWriteLeaves(): iterable {
		yield 'a row naming this pad' => [true];
		yield "a row naming a concurrent open's pad" => [false];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('rowsAFailedBindingWriteLeaves')]
	public function testAFailedBindingWriteKeepsThePadOnlyIfTheRowNamesIt(bool $namesThisPad): void {
		$fileId = 99;
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abcdefghijklmnopqrst';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->once())->method('createBinding')->willThrowException(new \RuntimeException('binding write failed'));
		$bindingService->expects($this->once())->method('isBoundTo')->with($fileId, $padId)->willReturn($namesThisPad);
		$bindingService->expects($this->never())->method('deleteActiveBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);
		$padFileService->expects($this->never())->method('buildInitialDocument');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$etherpadClient->expects($this->once())->method('createGroupPad')->willReturn($padId);
		$etherpadClient->expects($this->never())->method('listPads');
		$etherpadClient->expects($namesThisPad ? $this->never() : $this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$etherpadClient->expects($this->never())->method('deletePad');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrst');

		$file = $this->writableFile();
		$file->expects($this->never())->method('putContent');

		$this->expectExceptionMessage('binding write failed');
		$this->bootstrap($bindingService, $padFileService, $etherpadClient, secureRandom: $secureRandom, file: $file)
			->initializeMissingFrontmatter('alice', $fileId, '');
	}

	/**
	 * The branch the cleanup guard exists to protect. An existing binding
	 * means the pad was not created here, so a write that fails must leave
	 * it alone — a wrong guard would destroy a live protected pad and its
	 * group over a transient file lock.
	 */
	public function testInitializeMissingFrontmatterLeavesAnExistingPadAloneWhenTheWriteFails(): void {
		$fileId = 77;
		$padId = 'g.ABCDEFGHIJKLMNOP$p-existing';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(new Binding(fileId: $fileId, padId: $padId, accessMode: BindingService::ACCESS_PROTECTED, state: BindingService::STATE_ACTIVE));
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);
		$padFileService->method('buildInitialDocument')->willReturn('doc-that-fails-to-write');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		$etherpadClient->expects($this->never())->method('deletePad');
		$etherpadClient->expects($this->never())->method('deleteGroup');

		$file = $this->writableFile();
		$file->method('putContent')->willThrowException(new \RuntimeException('file is locked'));

		$service = $this->bootstrap($bindingService, $padFileService, $etherpadClient, file: $file);

		$this->expectException(\RuntimeException::class);
		$service->initializeMissingFrontmatter('alice', $fileId, '');
	}

	/**
	 * The write failed with the binding already in place, so row and pad
	 * agree and only the file is unwritten — the state the next open knows
	 * how to finish. The template listener says so where it swallows this
	 * error and leaves the retry to the viewer.
	 */
	public function testInitializeMissingFrontmatterKeepsAConsistentPairWhenTheWriteFails(): void {
		$fileId = 99;
		// Shaped like the group id `createGroup` answers with.
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abcdefghijklmnopqrst';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with($fileId, $padId, BindingService::ACCESS_PROTECTED);
		// The cleanup asks whether the row that is now there names this pad.
		$bindingService->expects($this->once())->method('isBoundTo')->with($fileId, $padId)->willReturn(true);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('parseLegacyOwnpadShortcut')
			->willReturn(null);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->willReturn('doc-that-fails-to-write');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$etherpadClient->expects($this->once())->method('createGroupPad')->willReturn($padId);
		$etherpadClient->expects($this->once())->method('buildPadUrl')->with($padId)->willReturn('https://pad.example.test/p/' . rawurlencode($padId));
		// Nothing is taken back: the binding names this pad, so the pair is
		// consistent and the next open finishes the job.
		$etherpadClient->expects($this->never())->method('deleteGroup');
		$etherpadClient->expects($this->never())->method('deletePad');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->expects($this->once())
			->method('generate')
			->with(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abcdefghijklmnopqrst');

		$file = $this->writableFile();
		$file->expects($this->once())
			->method('putContent')
			->with('doc-that-fails-to-write')
			->willThrowException(new \RuntimeException('write failed'));

		$service = $this->bootstrap($bindingService, $padFileService, $etherpadClient, secureRandom: $secureRandom, file: $file);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('write failed');
		$service->initializeMissingFrontmatter('alice', $fileId, '');
	}

	public function testBlankInitialisationIsRefusedWhenNoPadTypeIsEnabled(): void {
		// Without a binding this provisions a brand-new pad, so the policy
		// applies — otherwise an empty .pad plus /initialize would be a way
		// around it.
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createGroup');
		$etherpadClient->expects($this->never())->method('createGroupPad');
		$etherpadClient->expects($this->never())->method('createPad');

		$file = $this->writableFile();
		$file->expects($this->never())->method('putContent');

		$service = $this->bootstrap($bindingService, $padFileService, $etherpadClient, policy: $this->buildPadTypePolicy(false, false), file: $file);

		$this->expectException(\OCA\EtherpadNextcloud\Exception\PadTypeDisabledException::class);

		$service->initializeMissingFrontmatter('alice', 4321, '');
	}

	public function testBlankInitialisationFallsBackToPublicWhenProtectedPadsAreDisabled(): void {
		// A .pad can arrive outside the UI (WebDAV, another integration). If
		// protected pads are off but public ones are allowed, the file must
		// still become openable instead of 403-ing forever.
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with(4321, 'nc-abcdefghijklmnopqrstuvwx', BindingService::ACCESS_PUBLIC);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);
		$padFileService->method('buildInitialDocument')->willReturn('doc-content');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createGroupPad');
		$etherpadClient->expects($this->once())->method('createPad')->with('nc-abcdefghijklmnopqrstuvwx');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/nc-abcdefghijklmnopqrstuvwx');

		$secureRandom = $this->createMock(ISecureRandom::class);
		// A public pad is reachable by anyone holding its id, so the length
		// of that id is the whole of its protection.
		$secureRandom->expects($this->once())
			->method('generate')
			->with(24, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abcdefghijklmnopqrstuvwx');

		$file = $this->writableFile();
		$file->expects($this->once())->method('putContent')->with('doc-content');

		$service = $this->bootstrap($bindingService, $padFileService, $etherpadClient, secureRandom: $secureRandom, policy: $this->buildPadTypePolicy(false, true), file: $file);

		$service->initializeMissingFrontmatter('alice', 4321, '');
	}

	/**
	 * The names a new pad gets: what the first open, and a template, make it
	 * under - and what a new file's create asks for, made in Etherpad.
	 */
	public function testNamesANewPad(): void {
		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturnCallback(
			static fn (int $length, string $characters): string => $characters === ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS ? str_repeat('x', $length) : '?'
		);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad')->with('nc-' . str_repeat('x', 24));
		$etherpadClient->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$etherpadClient->expects($this->once())->method('createGroupPad')->with('g.ABCDEFGHIJKLMNOP', 'p-' . str_repeat('x', 20))->willReturn('g.ABCDEFGHIJKLMNOP$p-' . str_repeat('x', 20));
		$service = $this->bootstrap(etherpad: $etherpadClient, secureRandom: $secureRandom);

		$this->assertSame('nc-' . str_repeat('x', 24), $service->newPadId());
		$this->assertSame('p-' . str_repeat('x', 20), $service->newGroupPadName());
		$this->assertSame('nc-' . str_repeat('x', 24), $service->provisionPadId(BindingService::ACCESS_PUBLIC));
		$this->assertSame('g.ABCDEFGHIJKLMNOP$p-' . str_repeat('x', 20), $service->provisionPadId(BindingService::ACCESS_PROTECTED));
	}

	public function testExistingBindingStillInitialisesWhenProtectedPadsAreDisabled(): void {
		// The pad already exists; the setting governs creation only, so this
		// file must keep opening.
		$fileId = 4321;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(new Binding(fileId: $fileId, padId: 'g.group$existing', accessMode: BindingService::ACCESS_PROTECTED, state: BindingService::STATE_ACTIVE));
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);
		// Written as what the binding says it is, not as what may be created now.
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with($fileId, 'g.group$existing', BindingService::ACCESS_PROTECTED)
			->willReturn('doc-content');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createGroupPad');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/existing');

		$file = $this->writableFile();
		$file->expects($this->once())->method('putContent')->with('doc-content');

		$service = $this->bootstrap($bindingService, $padFileService, $etherpadClient, policy: $this->buildPadTypePolicy(false), file: $file);

		$service->initializeMissingFrontmatter('alice', $fileId, '');
	}

	public function testLegacyMigrationStillRunsWhenProtectedPadsAreDisabled(): void {
		// Legacy Ownpad files are existing content, not new pads.
		$legacy = ['url' => 'https://pad.example.test/p/public-pad', 'pad_id' => 'public-pad'];

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())->method('parseLegacyOwnpadShortcut')->willReturn($legacy);

		$file = $this->writableFile();

		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$migration->expects($this->once())->method('migrate')->with(new \OCA\EtherpadNextcloud\Service\CreatedFileClaim('alice', 4321, "[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n"), $legacy);

		$service = $this->bootstrap($bindingService, $padFileService, migration: $migration, policy: $this->buildPadTypePolicy(false), file: $file);

		self::assertTrue($service->initializeMissingFrontmatter(
			'alice',
			4321,
			"[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n"
		));
	}

	/**
	 * A row is written after the pad it names, never before: a row naming a
	 * pad that was never made is a file nothing can open again.
	 */
	public function testWritesNoBindingWhenTheProvisioningFails(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createPad')->willThrowException(new \RuntimeException('Connection timed out'));

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrstuvwx');

		$file = $this->writableFile();

		$service = $this->bootstrap($bindingService, $padFileService, $etherpadClient, secureRandom: $secureRandom, policy: $this->buildPadTypePolicy(false), file: $file);

		$this->expectException(\RuntimeException::class);
		$service->initializeMissingFrontmatter('alice', 99, '');
	}

	/** A `.pad` file the user may change, holding $content as the write's guard reads it. */
	private function writableFile(string $content = ''): File&\PHPUnit\Framework\MockObject\MockObject {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getContent')->willReturn($content);
		return $file;
	}

	/**
	 * Initialising writes the file, whether it is empty or a legacy
	 * shortcut: a reader of a read-only share is refused before a pad, group
	 * or row is made, which the failing write would otherwise leave behind.
	 *
	 * @return iterable<string,array{string,?array{url:string,pad_id:string}}>
	 */
	public static function contentAReaderCannotInitialise(): iterable {
		yield 'an empty .pad' => ['', null];
		yield 'a legacy shortcut' => ["[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n", ['url' => 'https://pad.example.test/p/public-pad', 'pad_id' => 'public-pad']];
	}

	/** @param ?array{url:string,pad_id:string} $legacy */
	#[\PHPUnit\Framework\Attributes\DataProvider('contentAReaderCannotInitialise')]
	public function testAReaderOfTheFileIsRefusedBeforeAnythingIsMade(string $content, ?array $legacy): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');
		$bindingService->expects($this->never())->method('createBinding');
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn($legacy);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method($this->anything());
		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$migration->expects($this->never())->method('migrate');
		// The file by its id answers no, whatever a path the request named
		// would answer.
		$byId = $this->createMock(File::class);
		$byId->method('isUpdateable')->willReturn(false);
		$byId->expects($this->never())->method('putContent');
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->with('bob', 4711)->willReturn($byId);
		$service = $this->bootstrap($bindingService, $padFileService, $etherpadClient, migration: $migration, nodes: $resolver);

		$this->expectException(PadFileNotWritableException::class);
		$service->initializeMissingFrontmatter('bob', 4711, $content);
	}

	/**
	 * The migration is asked once the file by its id may be written, and
	 * is handed the claim on that id with the shortcut as it was read.
	 */
	public function testALegacyShortcutIsMigratedOnceTheFileByItsIdMayBeWritten(): void {
		$legacy = ['url' => 'https://pad.example.test/p/public-pad', 'pad_id' => 'public-pad'];
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn($legacy);
		$writable = $this->writableFile();
		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$migration->expects($this->once())->method('migrate')->with(
			$this->callback(static fn (\OCA\EtherpadNextcloud\Service\CreatedFileClaim $claim): bool => $claim->uid === 'bob' && $claim->fileId === 4711
				&& $claim->holds("[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n") && $claim->writtenHash === null),
			$legacy,
		);
		$bindingService = $this->createMock(BindingService::class);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$service = $this->bootstrap($bindingService, $padFileService, $etherpadClient, migration: $migration, file: $writable);

		$this->assertTrue($service->initializeMissingFrontmatter('bob', 4711, "[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n"));
	}

	private function buildPadTypePolicy(bool $protectedEnabled, bool $publicEnabled = true): \OCA\EtherpadNextcloud\Service\PadTypePolicy {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				\OCA\EtherpadNextcloud\Service\PadTypePolicy::SETTING_PROTECTED => $protectedEnabled ? 'yes' : 'no',
				\OCA\EtherpadNextcloud\Service\PadTypePolicy::SETTING_PUBLIC => $publicEnabled ? 'yes' : 'no',
				default => $default,
			}
		);
		return new \OCA\EtherpadNextcloud\Service\PadTypePolicy($config);
	}

	/**
	 * The service as the container builds it, from what the test hands over
	 * and mocks for the rest. Its writer and rollback get the service's own
	 * collaborators. The resolver finds $file unless the test hands over a
	 * resolver of its own.
	 */
	private function bootstrap(
		?BindingService $bindings = null,
		?PadFileService $padFiles = null,
		?EtherpadClient $etherpad = null,
		?ISecureRandom $secureRandom = null,
		?PadLegacyMigrationService $migration = null,
		?PadTypePolicy $policy = null,
		?File $file = null,
		?UserNodeResolver $nodes = null,
	): PadBootstrapService {
		$bindings ??= $this->createMock(BindingService::class);
		$padFiles ??= $this->createMock(PadFileService::class);
		$etherpad ??= $this->createMock(EtherpadClient::class);
		$nodes ??= $this->resolverReturning($file);
		$padLifecycle = new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class));

		return new PadBootstrapService(
			$bindings,
			$padFiles,
			new ClaimedFileWriter($nodes, $padFiles, $etherpad, $this->createMock(LoggerInterface::class)),
			$padLifecycle,
			$secureRandom ?? $this->createMock(ISecureRandom::class),
			$migration ?? $this->createMock(PadLegacyMigrationService::class),
			$policy ?? $this->buildPadTypePolicy(true),
			$nodes,
			new ProvisionedPadRollback($bindings, $padLifecycle, $this->createMock(LoggerInterface::class)),
		);
	}

	/** Stub the node resolved at write time, which may differ from the input node. */
	private function resolverReturning(?File $file): UserNodeResolver {
		$file ??= $this->writableFile();
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willReturn($file);

		return $resolver;
	}

	/**
	 * A file of only whitespace counts as empty, and the guard compares with
	 * what was read, not with an empty string.
	 */
	public function testWritesOverAFileOfWhitespaceAsItWasRead(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('doc-content');

		$byId = $this->writableFile("\n");
		$byId->expects($this->once())->method('putContent')->with('doc-content');

		$this->assertFalse($this->bootstrap($bindingService, $padFileService, file: $byId)->initializeMissingFrontmatter('alice', 4242, "\n"));
	}

	/**
	 * The guard compares with what the caller read: by the write, the
	 * claimed id may find another writer's content, which a read made then
	 * would take for the baseline and write over.
	 */
	public function testRefusesToWriteWhenTheFileChangedWhileThePadWasProvisioned(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('doc-content');

		$stranger = $this->writableFile('somebody else\'s notes');
		$stranger->expects($this->never())->method('putContent');

		$this->expectException(\OCA\EtherpadNextcloud\Exception\PadFileChangedException::class);
		$this->bootstrap($bindingService, $padFileService, file: $stranger)->initializeMissingFrontmatter('alice', 4242, '');
	}

	/**
	 * A file holding something else entirely — a renamed text file, say —
	 * cannot be initialised, and saying so with `MissingFrontmatterException`
	 * would answer this refusal with the very code that asks a client to
	 * call this endpoint. Said to a reader too: what the file holds is
	 * asked before whether they may change it.
	 */
	public function testRefusesContentThatIsNeitherFrontmatterNorALegacyShortcut(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(false);
		$file->expects($this->never())->method('putContent');

		$this->expectException(UnrecognisedPadContentException::class);
		$this->bootstrap(file: $file)->initializeMissingFrontmatter('alice', 4321, 'just some notes, not a pad');
	}

	/** And it is not the type the initialize flow treats as "carry on". */
	public function testThatRefusalIsNotTheCarryOnType(): void {
		$this->assertFalse(
			is_a(UnrecognisedPadContentException::class, MissingFrontmatterException::class, true),
			'PadInitializationService catches MissingFrontmatterException to continue into the bootstrap',
		);
	}

}
