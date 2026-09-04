<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Service\PadFileService;
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
			->with($fileId, $padId, BindingService::ACCESS_PROTECTED, '', $padUrl)
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

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('getId')->willReturn($fileId);
		$file->expects($this->once())->method('putContent')->with('doc-content');

		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$service = new PadBootstrapService($bindingService, $padFileService, $etherpadClient, new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)), $secureRandom, $logger, $migration, $this->buildPadTypePolicy(true), $this->resolverReturning($file));
		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	public function testInitializeMissingFrontmatterDelegatesLegacyShortcutToMigrationService(): void {
		$legacy = [
			'url' => 'https://pad.example.test/p/public-pad',
			'pad_id' => 'public-pad',
		];

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('parseLegacyOwnpadShortcut')
			->willReturn($legacy);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);

		$file = $this->createMock(File::class);

		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$migration->expects($this->once())
			->method('migrate')
			->with('alice', $file, $legacy);

		$service = new PadBootstrapService($bindingService, $padFileService, $etherpadClient, new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)), $secureRandom, $logger, $migration, $this->buildPadTypePolicy(true), $this->resolverReturning($file));

		$this->assertTrue(
			$service->initializeMissingFrontmatter('alice', $file, "[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n")
		);
	}

	/**
	 * The failure that used to produce an orphan rather than an error: the
	 * pad existed, the binding write failed, and the cleanup began only
	 * after that write — so nothing ever pointed at the pad and nothing ever
	 * removed it.
	 */
	public function testInitializeMissingFrontmatterRemovesThePadWhenTheBindingCannotBeWritten(): void {
		$fileId = 99;
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abcdefghijklmnopqrst';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->willThrowException(new \RuntimeException('binding write failed'));
		// Nothing to remove: it was never written.
		$bindingService->expects($this->never())->method('deleteByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);
		$padFileService->expects($this->never())->method('buildInitialDocument');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$etherpadClient->expects($this->once())->method('createGroupPad')->willReturn($padId);
		// The group is only removed once Etherpad confirms it holds nothing
		// but this pad.
		$etherpadClient->method('listPads')->with('g.ABCDEFGHIJKLMNOP')->willReturn([$padId]);
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrst');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->expects($this->never())->method('putContent');

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$secureRandom,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(true),
			$this->resolverReturning($file),
		);

		$this->expectException(\RuntimeException::class);
		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	/**
	 * A group with no pad in it is invisible to everything afterwards, so the
	 * failure that leaves one has to clean up after itself. Both provisioning
	 * paths — this one and the restore — go through the same method now, so
	 * this covers both.
	 */
	public function testProvisionRemovesTheGroupWhenItsPadCannotBeCreated(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$etherpadClient->expects($this->once())
			->method('createGroupPad')
			->willThrowException(new \RuntimeException('pad creation failed'));
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrst');

		$service = new PadBootstrapService(
			$this->createMock(BindingService::class),
			$this->createMock(PadFileService::class),
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$secureRandom,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(true),
			$this->resolverReturning(null),
		);

		$this->expectException(\RuntimeException::class);
		$service->provisionPadId(BindingService::ACCESS_PROTECTED);
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
		$bindingService->method('findByFileId')->with($fileId)->willReturn([
			'pad_id' => $padId,
			'access_mode' => BindingService::ACCESS_PROTECTED,
		]);
		$bindingService->expects($this->never())->method('createBinding');
		$bindingService->expects($this->never())->method('deleteByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);
		$padFileService->method('buildInitialDocument')->willReturn('doc-that-fails-to-write');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		$etherpadClient->expects($this->never())->method('deletePad');
		$etherpadClient->expects($this->never())->method('deleteGroup');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('putContent')->willThrowException(new \RuntimeException('file is locked'));

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(true),
			$this->resolverReturning($file),
		);

		$this->expectException(\RuntimeException::class);
		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	/**
	 * The insert committed and the connection dropped before the answer got
	 * back, so the row is there while the call reports failure. Row and pad
	 * agree, and the file is merely still empty — the next open finds the
	 * binding and writes the frontmatter. Tearing that down would trade a
	 * recoverable file for a best-effort remote call that can fail and leave
	 * the pad unreachable with its last reference already gone.
	 */
	public function testKeepsABindingRowThatOutlivedItsFailedWrite(): void {
		$fileId = 99;
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abcdefghijklmnopqrst';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->once())
			->method('createBinding')
			->willThrowException(new \RuntimeException('binding write failed'));
		// The insert landed even though the call reported failure.
		$bindingService->expects($this->once())->method('isBoundTo')->with($fileId, $padId)->willReturn(true);
		$bindingService->expects($this->never())->method('deleteByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$etherpadClient->method('createGroupPad')->willReturn($padId);
		$etherpadClient->expects($this->never())->method('deleteGroup');
		$etherpadClient->expects($this->never())->method('deletePad');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrst');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$secureRandom,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(true),
			$this->resolverReturning($file),
		);

		$this->expectException(\RuntimeException::class);
		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	/**
	 * The other way `createBinding` fails: a concurrent first-open of the
	 * same file inserted first and the unique constraint refused ours. The
	 * row is theirs, and taking it away would leave their pad with nothing
	 * pointing at it. Our own pad still goes.
	 */
	public function testLeavesABindingRowAConcurrentOpenWon(): void {
		$fileId = 99;
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abcdefghijklmnopqrst';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('unique constraint violation'));
		// A row is there, but it names the winner's pad, not ours.
		$bindingService->method('isBoundTo')->with($fileId, $padId)->willReturn(false);
		$bindingService->expects($this->never())->method('deleteByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$etherpadClient->method('createGroupPad')->willReturn($padId);
		// This call made the group, so it needs no ownership check to take
		// it back — and must not wait on one, since nothing retries this.
		$etherpadClient->expects($this->never())->method('listPads');
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrst');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$secureRandom,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(true),
			$this->resolverReturning($file),
		);

		$this->expectException(\RuntimeException::class);
		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	/**
	 * The write failed with the binding already in place, so row and pad
	 * agree and only the file is unwritten — the state the next open knows
	 * how to finish. The template listener says so where it swallows this
	 * error and leaves the retry to the viewer.
	 */
	public function testInitializeMissingFrontmatterKeepsAConsistentPairWhenTheWriteFails(): void {
		$fileId = 99;
		// Shaped like the real thing: `g.` plus the sixteen characters
		// `createGroup` answers with. Nothing enforces that length — the one
		// rule, PadId, only asks for `g.<something>$<something>` — but a
		// fixture that looks like Etherpad's output keeps the test honest
		// about what it stands in for.
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
		$bindingService->expects($this->never())->method('deleteByFileId');

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

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('getId')->willReturn($fileId);
		$file->expects($this->once())
			->method('putContent')
			->with('doc-that-fails-to-write')
			->willThrowException(new \RuntimeException('write failed'));

		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$service = new PadBootstrapService($bindingService, $padFileService, $etherpadClient, new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)), $secureRandom, $logger, $migration, $this->buildPadTypePolicy(true), $this->resolverReturning($file));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('write failed');
		$service->initializeMissingFrontmatter('alice', $file, '');
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

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4321);
		$file->expects($this->never())->method('putContent');

		$service = $this->buildService($bindingService, $padFileService, $etherpadClient, $this->buildPadTypePolicy(false, false));

		$this->expectException(\OCA\EtherpadNextcloud\Exception\PadTypeDisabledException::class);

		$service->initializeMissingFrontmatter('alice', $file, '');
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
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrstuvwx');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4321);
		$file->expects($this->once())->method('putContent')->with('doc-content');

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$secureRandom,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(false, true),
			$this->resolverReturning($file),
		);

		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	private function buildService(
		BindingService $bindingService,
		PadFileService $padFileService,
		EtherpadClient $etherpadClient,
		\OCA\EtherpadNextcloud\Service\PadTypePolicy $policy,
		?File $file = null,
	): PadBootstrapService {
		return new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$policy,
			$this->resolverReturning($file ?? $this->createMock(File::class)),
		);
	}

	public function testExistingBindingStillInitialisesWhenProtectedPadsAreDisabled(): void {
		// The pad already exists; the setting governs creation only, so this
		// file must keep opening.
		$fileId = 4321;
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn([
			'pad_id' => 'g.group$existing',
			'access_mode' => BindingService::ACCESS_PROTECTED,
		]);
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->willReturn('doc-content');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('createGroupPad');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/existing');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->expects($this->once())->method('putContent')->with('doc-content');

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(false),
			$this->resolverReturning($file),
		);

		$service->initializeMissingFrontmatter('alice', $file, '');
	}

	public function testLegacyMigrationStillRunsWhenProtectedPadsAreDisabled(): void {
		// Legacy Ownpad files are existing content, not new pads.
		$legacy = ['url' => 'https://pad.example.test/p/public-pad', 'pad_id' => 'public-pad'];

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn($legacy);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4321);

		$migration = $this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class);
		$migration->expects($this->once())->method('migrate')->with('alice', $file, $legacy);

		$service = new PadBootstrapService(
			$this->createMock(BindingService::class),
			$padFileService,
			$this->createMock(EtherpadClient::class),
			new ManagedPadLifecycle($this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			$migration,
			$this->buildPadTypePolicy(false),
			$this->resolverReturning($file),
		);

		self::assertTrue($service->initializeMissingFrontmatter(
			'alice',
			$file,
			"[InternetShortcut]\nURL=https://pad.example.test/p/public-pad\n"
		));
	}

	/**
	 * A `createPad` that times out on the way back may still have made the
	 * pad, and the caller never learns the id — `provisionPadId` throws
	 * before returning it, so no rollback can name it. The public path had
	 * no cleanup of its own; the protected one has had it since the group
	 * was pulled into provisioning.
	 */
	public function testRemovesAPublicPadWhoseCreationFailedHalfway(): void {
		$fileId = 99;
		$padId = 'nc-abcdefghijklmnopqrstuvwx';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with($fileId)->willReturn(null);
		$bindingService->expects($this->never())->method('createBinding');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parseLegacyOwnpadShortcut')->willReturn(null);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('createPad')
			->with($padId)
			->willThrowException(new \RuntimeException('Connection timed out'));
		$etherpadClient->expects($this->once())->method('deletePad')->with($padId);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrstuvwx');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$secureRandom,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(false),
			$this->resolverReturning($file),
		);

		$this->expectException(\RuntimeException::class);
		$service->initializeMissingFrontmatter('alice', $file, '');
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

	/** Stub the node resolved at write time, which may differ from the input node. */
	private function resolverReturning(?File $file): UserNodeResolver {
		$file ??= $this->createMock(File::class);
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willReturn($file);

		return $resolver;
	}

	public function testRefusesToWriteWhenTheFileChangedWhileThePadWasProvisioned(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn(null);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('doc-content');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/nc-x');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('abcdefghijklmnopqrstuvwx');

		$claimed = $this->createMock(File::class);
		$claimed->method('getId')->willReturn(4242);
		$claimed->expects($this->never())->method('putContent');

		// A fresh node for the claimed id, now containing another writer's content.
		$stranger = $this->createMock(File::class);
		$stranger->method('getContent')->willReturn('somebody else\'s notes');
		$stranger->expects($this->never())->method('putContent');

		$service = new PadBootstrapService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$secureRandom,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\EtherpadNextcloud\Service\PadLegacyMigrationService::class),
			$this->buildPadTypePolicy(true),
			$this->resolverReturning($stranger),
		);

		$this->expectException(\OCA\EtherpadNextcloud\Exception\PadFileChangedException::class);
		$service->initializeMissingFrontmatter('alice', $claimed, '');
	}
}
