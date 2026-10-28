<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Service\Binding;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ClaimedFileWriter;
use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadFileCreator;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ProvisionedPadRollback;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadCreationServiceTest extends TestCase {
	public function testCreateBuildsPadFileAndBinding(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(123);
		// Written by the claimed id, not through the node the create made.
		$fileNode->expects($this->never())->method('putContent');
		$written = $this->createMock(File::class);
		$written->method('getContent')->willReturn('');
		$written->expects($this->once())->method('putContent')->with('frontmatter');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/Test')->willReturn('/Test.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->with('alice', '/Test.pad')->willReturn($fileNode);

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->with(BindingService::ACCESS_PROTECTED)->willReturn('g.ABC$pad');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('g.ABC$pad')->willReturn('https://pad.example.test/p/g.ABC$pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with(123, 'g.ABC$pad', BindingService::ACCESS_PROTECTED, null, 'https://pad.example.test/p/g.ABC$pad')
			->willReturn('frontmatter');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with(123, 'g.ABC$pad', BindingService::ACCESS_PROTECTED);

		$result = $this->buildService($padFileService, $padPaths, $fileCreator, $this->resolverFor($written, 'alice', 123), null, $bindingService, $etherpadClient, $bootstrap)
			->create('alice', '/Test', BindingService::ACCESS_PROTECTED);

		$this->assertSame([
			'file' => '/Test.pad',
			'file_id' => 123,
			'pad_id' => 'g.ABC$pad',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'pad_url' => 'https://pad.example.test/p/g.ABC$pad',
		], $result);
	}

	public function testCreateRollsBackWhenBindingFails(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(123);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/Test')->willReturn('/Test.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->with('alice', '/Test.pad')->willReturn($fileNode);
		$expectedRemoval = [$this->callback(
			// Check identity, baseline and write evidence, not just the claim's type.
			static fn (CreatedFileClaim $claim): bool => $claim->uid === 'alice'
				&& $claim->fileId === 123
				&& $claim->expectedBefore() === ''
				&& $claim->writtenHash !== null
		), ['file' => '/Test.pad']];

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->willReturn('g.ABC$pad');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/g.ABC$pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('frontmatter');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('createBinding')->willThrowException(new BindingException('Duplicate binding.'));
		// The binding write began, so the row is asked about, and goes with the pad.
		$bindingService->method('isBoundTo')->with(123, 'g.ABC$pad')->willReturn(true);
		$bindingService->expects($this->once())->method('deleteActiveBinding')->with(123, 'g.ABC$pad')->willReturn(true);
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.ABC');

		$this->expectException(BindingException::class);

		$this->buildService($padFileService, $padPaths, $fileCreator, null, $expectedRemoval, $bindingService, $etherpadClient, $bootstrap)
			->create('alice', '/Test', BindingService::ACCESS_PROTECTED);
	}

	/** Failing before its row, the pad goes unasked; one Etherpad keeps is named with its file. */
	public function testABlankCreatesPadThatFailsBeforeItsRowGoesWithoutAskingTheBindingTable(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(123);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/Test')->willReturn('/Test.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->with('alice', '/Test.pad')->willReturn($fileNode);

		$expectedRemoval = [$this->callback(static fn (CreatedFileClaim $claim): bool => $claim->fileId === 123), ['file' => '/Test.pad']];

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->willReturn('g.ABC$pad');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/g.ABC$pad');
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.ABC')->willThrowException(new \RuntimeException('etherpad down'));

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('createBinding');
		$bindingService->expects($this->never())->method('isBoundTo');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')
			->willThrowException(new \RuntimeException('cannot build the document'));

		$padLifecycleLogger = $this->createMock(LoggerInterface::class);
		$padLifecycleLogger->expects($this->once())->method('warning')->with(
			'Could not remove a new Etherpad pad; it is left over.',
			$this->callback(static fn (array $context): bool => $context['fileId'] === 123 && $context['padId'] === 'g.ABC$pad' && $context['operation'] === 'create'),
		);

		$this->expectExceptionMessage('cannot build the document');

		$this->buildService($padFileService, $padPaths, $fileCreator, null, $expectedRemoval, $bindingService, $etherpadClient, $bootstrap, padLifecycleLogger: $padLifecycleLogger)
			->create('alice', '/Test', BindingService::ACCESS_PROTECTED);
	}

	/**
	 * A create that fails takes back what it made and hands the failure on
	 * as it is: the error mapper reports it once, at the level it deserves.
	 */
	public function testACreateLeavesItsFailureToTheErrorMapper(): void {
		foreach ([
			'Etherpad failing' => new EtherpadClientException('Etherpad API request failed: createGroupPad'),
			'anything else' => new \RuntimeException('cannot build the document'),
		] as $case => $failure) {
			$fileNode = $this->createMock(File::class);
			$fileNode->method('getId')->willReturn(123);
			$padPaths = $this->createMock(PathNormalizer::class);
			$padPaths->method('normalizeCreatePath')->willReturn('/Test.pad');
			$fileCreator = $this->createMock(PadFileCreator::class);
			$fileCreator->method('createUserFile')->willReturn($fileNode);
			$bootstrap = $this->createMock(PadBootstrapService::class);
			$bootstrap->method('provisionPadId')->willThrowException($failure);
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->never())->method($this->anything());

			try {
				$this->buildService(padPaths: $padPaths, fileCreator: $fileCreator, expectedRemoval: [$this->isInstanceOf(CreatedFileClaim::class), ['file' => '/Test.pad']], bootstrap: $bootstrap, logger: $logger)
					->create('alice', '/Test', BindingService::ACCESS_PROTECTED);
				$this->fail($case . ': the create went through.');
			} catch (\Throwable $e) {
				$this->assertSame($failure, $e, $case);
			}
		}
	}

	/**
	 * Whether the folder takes a new file, the creator asks, for every
	 * caller; its refusal is the answer, before a pad is provisioned.
	 */
	public function testCreateInParentHandsOnTheCreatorsRefusal(): void {
		$parent = $this->createMock(Folder::class);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreateFileName')->with('Test')->willReturn('Test.pad');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFolderNodeById')->with('alice', 99)->willReturn($parent);
		$refusal = new PadParentFolderNotWritableException('The folder takes no new file.');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->expects($this->once())->method('createUserFileInFolder')->with($parent, 'Test.pad')->willThrowException($refusal);
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('provisionPadId');

		try {
			$this->buildService(padPaths: $padPaths, fileCreator: $fileCreator, userNodeResolver: $userNodeResolver, bootstrap: $bootstrap)
				->createInParent('alice', 99, 'Test', BindingService::ACCESS_PUBLIC);
			$this->fail('The create went through.');
		} catch (PadParentFolderNotWritableException $e) {
			$this->assertSame($refusal, $e);
		}
	}

	public function testCreateInParentAnswersWithItsFolderAfterTheFile(): void {
		$parent = $this->createMock(Folder::class);
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(123);
		$written = $this->createMock(File::class);
		$written->method('getContent')->willReturn('');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreateFileName')->with('Test')->willReturn('Test.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFileInFolder')->with($parent, 'Test.pad')->willReturn($fileNode);
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFolderNodeById')->with('alice', 99)->willReturn($parent);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $fileNode)->willReturn('/Folder/Test.pad');
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 123)->willReturn($written);
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->with(BindingService::ACCESS_PUBLIC)->willReturn('nc-pad');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('nc-pad')->willReturn('https://pad.example.test/p/nc-pad');

		$this->assertSame([
			'file' => '/Folder/Test.pad',
			'file_id' => 123,
			'parent_folder_id' => 99,
			'pad_id' => 'nc-pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.example.test/p/nc-pad',
		], $this->buildService(padPaths: $padPaths, fileCreator: $fileCreator, userNodeResolver: $userNodeResolver, etherpadClient: $etherpadClient, bootstrap: $bootstrap)
			->createInParent('alice', 99, 'Test', BindingService::ACCESS_PUBLIC));
	}

	/**
	 * `toUserAbsolutePath()` throws when the node cannot be mapped into the
	 * user's tree, and by then `newFile()` has already made the file. The
	 * claim has to exist before that call, or the rollback is handed
	 * nothing and the empty `.pad` stays behind.
	 */
	public function testClaimsTheFileBeforeResolvingItsPath(): void {
		$parent = $this->createMock(Folder::class);

		$fileNode = $this->createMock(\OCP\Files\File::class);
		$fileNode->method('getId')->willReturn(4242);

		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFileInFolder')->willReturn($fileNode);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreateFileName')->willReturn('Test.pad');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFolderNodeById')->willReturn($parent);
		$userNodeResolver->method('toUserAbsolutePath')
			->willThrowException(new \OCP\Files\NotFoundException('outside the user tree'));

		$this->expectException(\OCP\Files\NotFoundException::class);

		$this->buildService(
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			userNodeResolver: $userNodeResolver,
			expectedRemoval: [$this->callback(static fn (CreatedFileClaim $claim): bool => $claim->fileId === 4242), ['file' => '']],
		)->createInParent('alice', 99, 'Test', BindingService::ACCESS_PUBLIC);
	}

	public function testCreateRefusesAPadTypeTheAdminSwitchedOff(): void {
		// The guard sits before any file is touched, so a rejected create
		// leaves nothing behind to roll back.
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->expects(self::never())->method('createUserFile');

		$this->expectException(PadTypeDisabledException::class);

		$this->buildService(
			fileCreator: $fileCreator,
			padTypePolicy: $this->buildPadTypePolicy(true, false),
		)->create('alice', '/Test.pad', BindingService::ACCESS_PUBLIC);
	}

	public function testCreateInParentRefusesAPadTypeTheAdminSwitchedOff(): void {
		$this->expectException(PadTypeDisabledException::class);

		$this->buildService(padTypePolicy: $this->buildPadTypePolicy(false, true))
			->createInParent('alice', 99, 'Test', BindingService::ACCESS_PROTECTED);
	}

	/**
	 * The template flow creates the file before it validates the template,
	 * so even the refusal tests need a node that reports an id — the create
	 * refuses to continue without one.
	 */
	private function fileCreatorReturningId(int $fileId): PadFileCreator {
		$fileNode = $this->createMock(\OCP\Files\File::class);
		$fileNode->method('getId')->willReturn($fileId);

		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->willReturn($fileNode);
		return $fileCreator;
	}

	public function testTemplateCreationIsRefusedWhenNoPadTypeIsEnabled(): void {
		// Proves the template path consults the policy at all; which mode it
		// falls back to is covered by PadTypePolicyTest.
		$templateNode = $this->buildMaterializeTemplate();

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($templateNode);

		$padFileService = $this->templateFormat();

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/Out.pad');

		$this->expectException(PadTypeDisabledException::class);

		$this->buildService(
			$padFileService,
			$padPaths,
			$this->fileCreatorReturningId(11),
			$userNodeResolver,
			padTypePolicy: $this->buildPadTypePolicy(false, false),
		)->createFromTemplate('alice', '/Out.pad', 7, null);
	}

	/** @return iterable<string,array{?int,int}> the seeded pad's revisions as Etherpad gives them, and as the file records them */
	public static function revisionsAfterSeeding(): iterable {
		yield 'as Etherpad counts them' => [2, 2];
		yield 'Etherpad does not say' => [null, 0];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('revisionsAfterSeeding')]
	public function testTemplateMaterialisationUsesTheRedirectedModeEndToEnd(?int $revisions, int $recorded): void {
		// The template says "public", but the admin only offers protected —
		// the pad and its binding must both end up protected.
		$template = $this->buildMaterializeTemplate();

		$padFileService = $this->templateFormat(BindingService::ACCESS_PUBLIC, html: '<p>hello</p>');
		$padFileService->method('buildInitialDocument')
			->with(4321, 'g.grp$pad', BindingService::ACCESS_PROTECTED, new PadSnapshot('hello', '<p>hello</p>', $recorded), 'https://pad.example.test/p/x')
			->willReturn('doc-with-snapshot');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('newGroupPadName')->willReturn('p-new');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		// A protected pad, in a group made for it, under the name a new pad gets.
		$etherpadClient->method('createGroup')->willReturn('g.grp');
		$etherpadClient->expects(self::once())->method('createGroupPad')->with('g.grp', 'p-new')->willReturn('g.grp$pad');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		// The template's formatting is what reaches the pad, not its text.
		$etherpadClient->expects($this->once())->method('setHTML')->with('g.grp$pad', '<p>hello</p>');
		$etherpadClient->expects($this->never())->method('setText');
		if ($revisions === null) {
			$etherpadClient->method('getRevisionsCount')->willThrowException(new EtherpadClientException('Etherpad API request failed'));
		} else {
			$etherpadClient->method('getRevisionsCount')->with('g.grp$pad')->willReturn($revisions);
		}

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects(self::once())
			->method('createBinding')
			->with(4321, 'g.grp$pad', BindingService::ACCESS_PROTECTED);

		$result = $this->buildService(
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
			bootstrap: $bootstrap,
			padTypePolicy: $this->buildPadTypePolicy(true, false),
		)->materializeTemplateInto($template, $this->materializeUser(), new CreatedFileClaim('alice', 4321));

		self::assertSame(BindingService::ACCESS_PROTECTED, $result['access_mode']);
	}

	/**
	 * Seeding fails: provisionSeeded() takes the new pad down itself, before
	 * the file is written or the binding table is asked.
	 */
	public function testATemplatesPadThatCannotBeSeededGoes(): void {
		$template = $this->buildMaterializeTemplate();

		$written = $this->createMock(\OCP\Files\File::class);
		$written->expects($this->never())->method('putContent');
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willReturn($written);

		$padFileService = $this->templateFormat(html: '<p>hello</p>');

		$bootstrap = $this->createMock(PadBootstrapService::class);

		$refused = new EtherpadClientException('Etherpad API request failed: setText');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('createGroupPad')->willReturn('g.AbCdEfGh12345678$p-new');
		$etherpadClient->method('setHTML')->willThrowException(new EtherpadClientException('Etherpad API request failed: setHTML'));
		$etherpadClient->method('setText')->willThrowException($refused);
		$etherpadClient->expects($this->once())->method('deleteGroup')->with('g.AbCdEfGh12345678');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('createBinding');
		$bindingService->expects($this->never())->method('isBoundTo');

		try {
			$this->buildService(
				padFileService: $padFileService,
				userNodeResolver: $resolver,
				bindingService: $bindingService,
				etherpadClient: $etherpadClient,
				bootstrap: $bootstrap,
			)->materializeTemplateInto($template, $this->materializeUser(), new CreatedFileClaim('alice', 4321));
			$this->fail('the seed\'s failure goes on');
		} catch (EtherpadClientException $e) {
			$this->assertSame($refused, $e);
		}
	}

	public function testATemplatesPadThatCannotBeSeededOrRemovedIsNamedWithItsFile(): void {
		$template = $this->buildMaterializeTemplate();

		$padFileService = $this->templateFormat(BindingService::ACCESS_PUBLIC);
		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('newPadId')->willReturn('nc-new');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('setText')->willThrowException(new EtherpadClientException('Etherpad API request failed: setText'));
		$etherpadClient->method('deletePad')->willThrowException(new EtherpadClientException('Etherpad API request failed: deletePad'));
		$padLifecycleLogger = $this->createMock(LoggerInterface::class);
		$padLifecycleLogger->expects($this->once())
			->method('warning')
			->with('Could not remove a new Etherpad pad; it is left over.', $this->callback(
				static fn (array $context): bool => ($context['fileId'] ?? null) === 4321
					&& ($context['padId'] ?? null) === 'nc-new'
					&& ($context['operation'] ?? null) === 'template materialization'
			));

		$this->expectException(EtherpadClientException::class);

		$this->buildService(
			padFileService: $padFileService,
			etherpadClient: $etherpadClient,
			bootstrap: $bootstrap,
			padLifecycleLogger: $padLifecycleLogger,
		)->materializeTemplateInto($template, $this->materializeUser(), new CreatedFileClaim('alice', 4321));
	}

	/**
	 * @return iterable<string,array{array<string,mixed>,array<string,string>}> what the remote export gives, and what that adds to the answer
	 */
	public static function remoteExports(): iterable {
		yield 'with its text' => [['text' => "Initial snapshot\nfrom remote pad"], []];
		yield 'refused by the remote' => [['text' => '', 'snapshot_unavailable' => true], ['snapshot_warning_code' => 'remote_export_unavailable']];
	}

	/**
	 * @param array<string,mixed> $export
	 * @param array<string,string> $warning
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('remoteExports')]
	public function testCreateFromUrlBuildsExternalPadFileWithoutBinding(array $export, array $warning): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getContent')->willReturn('');
		$fileNode->method('getId')->willReturn(321);
		$fileNode->expects($this->once())->method('putContent')->with('external-frontmatter');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/External')->willReturn('/External.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->with('alice', '/External.pad')->willReturn($fileNode);
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->with('https://pad.remote.test/p/RemotePad')
			->willReturn([
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
				'origin' => 'https://pad.remote.test',
				'pad_id' => 'RemotePad',
			] + $export);

		$padFileService = $this->createMock(PadFileService::class);
		// The document's other fields are ExternalPadSeeder's.
		$padFileService->expects($this->once())->method('buildInitialDocument')->with(321)->willReturn('external-frontmatter');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('createBinding');

		$result = $this->buildService(
			$padFileService, $padPaths, $fileCreator, $this->resolverFor($fileNode), null, $bindingService, externalPadExportFetcher: $fetcher)
			->createFromUrl('alice', '/External', 'https://pad.remote.test/p/RemotePad');

		$this->assertSame([
			'file' => '/External.pad',
			'file_id' => 321,
			'pad_id' => 'ext.RemotePad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.remote.test/p/RemotePad',
		] + $warning, $result);
	}

	public function testCreateFromUrlRollsBackWhenInitialSnapshotFetchFails(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(321);
		$fileNode->expects($this->never())->method('putContent');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/External')->willReturn('/External.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->expects($this->once())->method('createUserFile')->with('alice', '/External.pad')->willReturn($fileNode);

		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->with('https://pad.remote.test/p/RemotePad')
			->willThrowException(new EtherpadClientException('Remote pad unavailable.'));

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->never())->method('buildInitialDocument');

		$this->expectException(EtherpadClientException::class);

		$this->buildService($padFileService, $padPaths, $fileCreator, null, [$this->isInstanceOf(CreatedFileClaim::class), ['file' => '/External.pad']], externalPadExportFetcher: $fetcher)
			->createFromUrl('alice', '/External', 'https://pad.remote.test/p/RemotePad');
	}

	public function testCreateFromTemplateProvisionsFreshPadWithResolvedBody(): void {
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Protokoll-Tpl.pad');
		$templateNode->method('getContent')->willReturn('tpl-content');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/Meetings/Protokoll 18.05.2026.pad')->willReturn('/Meetings/Protokoll 18.05.2026.pad');

		$newFile = $this->createMock(\OCP\Files\File::class);
		$newFile->method('getId')->willReturn(99);
		// Writing must use the re-resolved target, not the node returned by create.
		$newFile->expects($this->never())->method('putContent');

		$written = $this->createMock(\OCP\Files\File::class);
		$written->method('getContent')->willReturn('');
		$written->expects($this->once())->method('putContent');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturnCallback(
			fn (string $uid, int $fileId): \OCP\Files\File => match ([$uid, $fileId]) {
				['alice', 7] => $templateNode,
				['alice', 99] => $written,
				default => throw new \OCP\Files\NotFoundException('unexpected lookup'),
			}
		);
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->with('alice', '/Meetings/Protokoll 18.05.2026.pad')->willReturn($newFile);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->with('tpl-content')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.tpl$pad', 'access_mode' => BindingService::ACCESS_PROTECTED],
			body: 'body',
			padId: 'g.tpl$pad',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'Datum: {{date:next monday|d.m.Y}}',
			'html' => '',
		]);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with(99, 'g.AbCdEfGh12345678$p-fresh', BindingService::ACCESS_PROTECTED, new PadSnapshot('Datum: 18.05.2026', '', 0), $this->isType('string'))
			->willReturn('doc');
		$padFileService->expects($this->never())->method('withExportSnapshot');

		$bootstrap = $this->createMock(PadBootstrapService::class);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with(99, 'g.AbCdEfGh12345678$p-fresh', BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createGroupPad')->willReturn('g.AbCdEfGh12345678$p-fresh');
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/g.AbCdEfGh12345678$p-fresh');
		// No HTML in this template, so seeding is the plain-text call.
		$etherpadClient->expects($this->once())->method('setText')->with('g.AbCdEfGh12345678$p-fresh', 'Datum: 18.05.2026');

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getDisplayName')->willReturn('Alice');
		$user->method('getUID')->willReturn('alice');

		$result = $this->buildService(
			$padFileService, $padPaths, $fileCreator, $userNodeResolver, null, $bindingService, $etherpadClient, $bootstrap)
			->createFromTemplate('alice', '/Meetings/Protokoll {{date:next monday|d.m.Y}}.pad', 7, $user);

		$this->assertSame([
			'file' => '/Meetings/Protokoll 18.05.2026.pad',
			'file_id' => 99,
			'pad_id' => 'g.AbCdEfGh12345678$p-fresh',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'pad_url' => 'https://pad.example.test/p/g.AbCdEfGh12345678$p-fresh',
		], $result);
	}

	/** Everything after the file is created can still fail; the rollback follows the file's id, not its name. */
	public function testCreateFromTemplateRollsBackWithTheCreatedFileId(): void {
		$templateNode = $this->buildMaterializeTemplate();

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($templateNode);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/FromTpl.pad');

		$newFile = $this->createMock(\OCP\Files\File::class);
		$newFile->method('getId')->willReturn(4242);
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->willReturn($newFile);

		// Fails after the file exists, which is the case that matters.
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willThrowException(new \RuntimeException('template unreadable'));

		$this->expectException(\RuntimeException::class);

		$this->buildService(
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			padFileService: $padFileService,
			expectedRemoval: [$this->callback(static fn (CreatedFileClaim $claim): bool => $claim->fileId === 4242), ['file' => '/FromTpl.pad']],
			userNodeResolver: $userNodeResolver,
		)->createFromTemplate('alice', '/FromTpl.pad', 7, null);
	}

	/**
	 * Written, then the binding fails. The claimed id finds the empty file
	 * the create made, so the write lands and the binding write is reached.
	 */
	public function testCreateFromTemplateRollsBackOnlyTheFileWhenTheBindingFails(): void {
		$templateNode = $this->buildMaterializeTemplate();

		$written = $this->createMock(\OCP\Files\File::class);
		$written->method('getContent')->willReturn('');
		$written->expects($this->once())->method('putContent')->with('doc');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturnCallback(
			fn (string $uid, int $fileId): \OCP\Files\File => match ([$uid, $fileId]) {
				['alice', 7] => $templateNode,
				['alice', 4242] => $written,
			}
		);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/FromTpl.pad');

		$padFileService = $this->templateFormat(BindingService::ACCESS_PUBLIC, text: 'text');
		$padFileService->method('buildInitialDocument')->willReturn('doc');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('newPadId')->willReturn('nc-fresh');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())->method('createBinding')->willThrowException(new \RuntimeException('binding failed'));

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('deletePad')->with('nc-fresh');

		$this->expectException(\RuntimeException::class);

		$this->buildService(
			padFileService: $padFileService,
			padPaths: $padPaths,
			fileCreator: $this->fileCreatorReturningId(4242),
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
			bootstrap: $bootstrap,
			expectedRemoval: [$this->callback(static fn (CreatedFileClaim $claim): bool => $claim->writtenHash !== null), ['file' => '/FromTpl.pad']],
		)->createFromTemplate('alice', '/FromTpl.pad', 7, null);
	}

	/**
	 * newFile() is not a create-if-absent, and a stale cache entry can make
	 * an existing file look absent and empty — at which point the create
	 * would write its frontmatter over the user's pad and only notice when
	 * the binding row turned out to be taken, with the document already
	 * destroyed. A binding on the id says the file was somebody's pad before
	 * this request touched it.
	 */
	public function testRefusesToWriteOverAFileThatIsAlreadyAPad(): void {
		$existing = $this->createMock(\OCP\Files\File::class);
		$existing->method('getId')->willReturn(4242);
		$existing->method('getSize')->willReturn(0);
		$existing->method('getContent')->willReturn('');
		$existing->expects($this->never())->method('putContent');
		// And it must survive the rollback that the refusal triggers: a file
		// that was already a pad is not this create's to clean up. Found by
		// its id, it is as the create was handed it, so only the disown
		// keeps it.
		$existing->expects($this->never())->method('delete');

		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->willReturn($existing);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/Notes.pad');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with(4242)->willReturn(new Binding(fileId: 4242, padId: 'g.someone$pad', accessMode: BindingService::ACCESS_PROTECTED, state: BindingService::STATE_ACTIVE));
		$bindingService->expects($this->never())->method('createBinding');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('provisionPadId');

		$this->expectException(\OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException::class);

		$this->buildService(
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			userNodeResolver: $this->resolverFor($existing, 'alice', 4242),
			bindingService: $bindingService,
			bootstrap: $bootstrap,
		)->create('alice', '/Notes.pad', BindingService::ACCESS_PUBLIC);
	}

	/**
	 * The other signal that a file predates this request. Checked here and
	 * not in PadFileCreator: the creator would have to throw before handing
	 * the node over, and then nobody could clean it up.
	 */
	public function testRefusesToWriteOverAFileThatAlreadyHasContent(): void {
		$existing = $this->createMock(\OCP\Files\File::class);
		$existing->method('getId')->willReturn(4242);
		$existing->method('getSize')->willReturn(4096);
		$existing->expects($this->never())->method('putContent');
		$existing->expects($this->never())->method('delete');

		$this->expectException(\OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException::class);
		$this->buildServiceRefusing($existing)->create('alice', '/Notes.pad', BindingService::ACCESS_PUBLIC);
	}

	/** A size that cannot be read is unknown, and unknown is not ours. */
	public function testRefusesAFileWhoseSizeCannotBeRead(): void {
		$existing = $this->createMock(\OCP\Files\File::class);
		$existing->method('getId')->willReturn(4242);
		$existing->method('getSize')->willThrowException(new \RuntimeException('storage hiccup'));
		$existing->expects($this->never())->method('putContent');
		$existing->expects($this->never())->method('delete');

		$this->expectException(\OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException::class);
		$this->buildServiceRefusing($existing)->create('alice', '/Notes.pad', BindingService::ACCESS_PUBLIC);
	}

	/** A row the binding write committed before it threw goes too: it would name a pad that is gone. */
	public function testMaterializeRemovesARowItsFailedBindingWriteLeftBehind(): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$pad';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->with(4321, $padId)->willReturn(true);
		$bindingService->expects(self::once())->method('deleteActiveBinding')->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		$etherpadClient->expects(self::once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');

		$this->expectException(\RuntimeException::class);
		$this->buildMaterializeService($bindingService, $etherpadClient, $padId)
			->materializeTemplateInto($this->buildMaterializeTemplate(), $this->materializeUser(), new CreatedFileClaim('alice', 4321));
	}

	/** Written meanwhile, the file is refused before its row: the pad goes unasked, and one Etherpad keeps is named with its file. */
	public function testATemplatesPadWhoseFileCannotBeWrittenGoesWithoutAskingTheBindingTable(): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$pad';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects(self::never())->method('isBoundTo');
		$bindingService->expects(self::never())->method('createBinding');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		$etherpadClient->expects(self::once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP')->willThrowException(new \RuntimeException('etherpad down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Could not remove a new Etherpad pad; it is left over.',
			$this->callback(static fn (array $context): bool => $context['fileId'] === 4321 && $context['padId'] === $padId && $context['operation'] === 'template materialization'),
		);

		$changed = $this->createMock(File::class);
		$changed->method('getContent')->willReturn('written meanwhile');
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willReturn($changed);

		$this->expectException(PadFileChangedException::class);
		$this->buildMaterializeService($bindingService, $etherpadClient, $padId, $resolver, $logger)
			->materializeTemplateInto($this->buildMaterializeTemplate(), $this->materializeUser(), new CreatedFileClaim('alice', 4321));
	}

	/**
	 * What Nextcloud copied into the file is the claim's baseline; a read
	 * that gives no string counts as empty.
	 */
	public function testClaimsATemplateTargetWithTheContentCopiedIntoIt(): void {
		$copied = $this->createMock(File::class);
		$copied->method('getId')->willReturn(4321);
		$copied->method('getContent')->willReturn('template bytes');
		$unread = $this->createMock(File::class);
		$unread->method('getId')->willReturn(4321);
		$unread->method('getContent')->willReturn(null);
		$service = $this->buildService();

		$claim = $service->claimTemplateTarget($copied, 'alice');

		$this->assertSame(['alice', 4321, 'template bytes'], [$claim->uid, $claim->fileId, $claim->expectedBefore()]);
		$this->assertSame('', $service->claimTemplateTarget($unread, 'alice')->expectedBefore());
		$this->expectExceptionObject(new \RuntimeException('Cannot claim a template target without a user to resolve the file by.'));
		$service->claimTemplateTarget($copied, '');
	}

	private function buildMaterializeTemplate(): \OCP\Files\File {
		$template = $this->createMock(\OCP\Files\File::class);
		$template->method('getName')->willReturn('Template.pad');
		$template->method('getContent')->willReturn('tpl');
		return $template;
	}

	/** The format of a template pad in $accessMode, its body holding $text and $html. */
	private function templateFormat(string $accessMode = BindingService::ACCESS_PROTECTED, string $text = 'hello', string $html = ''): PadFileService&\PHPUnit\Framework\MockObject\MockObject {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'nc-source'],
			body: 'body',
			padId: 'nc-source',
			accessMode: $accessMode,
			padUrl: '',
			isExternal: false,
			snapshotRev: -1,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => $text, 'html' => $html]);
		$padFileService->expects($this->never())->method('withExportSnapshot');

		return $padFileService;
	}

	private function materializeUser(): \OCP\IUser {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');

		return $user;
	}

	private function buildMaterializeService(
		BindingService $bindingService,
		EtherpadClient $etherpadClient,
		string $padId,
		?UserNodeResolver $userNodeResolver = null,
		?LoggerInterface $padLifecycleLogger = null,
	): PadCreationService {
		$padFileService = $this->templateFormat();
		$padFileService->method('buildInitialDocument')->willReturn('doc');

		// A protected pad, in a group made for it.
		$etherpadClient->method('createGroupPad')->willReturn($padId);

		return $this->buildService(
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
			padTypePolicy: $this->buildPadTypePolicy(true, false),
			userNodeResolver: $userNodeResolver,
			padLifecycleLogger: $padLifecycleLogger,
		);
	}

	private function buildServiceRefusing(\OCP\Files\File $node): PadCreationService {
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->willReturn($node);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/Notes.pad');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('provisionPadId');

		return $this->buildService(
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			bootstrap: $bootstrap,
		);
	}

	public function testCreateFromTemplateRefusesNonPadTemplate(): void {
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Notes.txt');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($templateNode);

		$this->expectException(\OCA\EtherpadNextcloud\Exception\NotAPadFileException::class);

		$this->buildService(fileCreator: $this->fileCreatorReturningId(11), userNodeResolver: $userNodeResolver)
			->createFromTemplate('alice', '/Out.pad', 7, null);
	}

	public function testCreateFromTemplateRefusesExternalTemplate(): void {
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Remote.pad');
		$templateNode->method('getContent')->willReturn('tpl');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($templateNode);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'ext.remote'],
			body: '',
			padId: 'ext.remote',
			accessMode: '',
			padUrl: '',
			isExternal: true,
			snapshotRev: -1,
		));

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/Out.pad');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('External pads cannot be used as a template.');

		$this->buildService($padFileService, $padPaths, $this->fileCreatorReturningId(11), $userNodeResolver)
			->createFromTemplate('alice', '/Out.pad', 7, null);
	}

	/** Exercise create and rollback together when the initial getId() fails. */
	public function testACreateThatNeverGotAnIdCleansUpNothing(): void {
		$node = $this->createMock(\OCP\Files\File::class);
		$node->method('getId')->willThrowException(new \RuntimeException('no cache entry'));

		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->willReturn($node);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/Notes.pad');

		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->never())->method('resolveUserFileNodeById');

		$this->expectException(\RuntimeException::class);

		$this->buildService(
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			userNodeResolver: $resolver,
		)->create('alice', '/Notes.pad', BindingService::ACCESS_PUBLIC);
	}

	/** Stub the write target and optionally constrain the lookup identity. */
	private function resolverFor(\OCP\Files\File $node, ?string $uid = null, ?int $fileId = null): UserNodeResolver {
		$resolver = $this->createMock(UserNodeResolver::class);
		$expectation = $resolver->method('resolveUserFileNodeById');
		if ($uid !== null && $fileId !== null) {
			$expectation->with($uid, $fileId);
		}
		$expectation->willReturn($node);

		return $resolver;
	}

	private function buildService(
		?PadFileService $padFileService = null,
		?PathNormalizer $padPaths = null,
		?PadFileCreator $fileCreator = null,
		?UserNodeResolver $userNodeResolver = null,
		?array $expectedRemoval = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
		?PadBootstrapService $bootstrap = null,
		?\OCA\EtherpadNextcloud\Service\PadPlaceholderResolver $placeholderResolver = null,
		?\OCA\EtherpadNextcloud\Service\ExternalPadSeeder $externalPadSeeder = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
		?\OCA\EtherpadNextcloud\Service\PadTypePolicy $padTypePolicy = null,
		?LoggerInterface $logger = null,
		?LoggerInterface $padLifecycleLogger = null,
	): PadCreationService {
		if ($placeholderResolver === null) {
			$placeholderResolver = new \OCA\EtherpadNextcloud\Service\PadPlaceholderResolver(new FixedClock(1778976000));
		}
		$padFileService = $padFileService ?? $this->createMock(PadFileService::class);
		$etherpadClient = $etherpadClient ?? $this->createMock(EtherpadClient::class);
		$bindingService ??= $this->createMock(BindingService::class);
		$padLifecycle = new ManagedPadLifecycle($etherpadClient, $padLifecycleLogger ?? $this->createMock(LoggerInterface::class));
		$externalPadExportFetcher = $externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class);
		// Build a real seeder from the test's mocked deps by default so the
		// createFromUrl tests can keep stubbing
		// ExternalPadExportFetcher::normalizeAndFetchExternalPublicPadTextOrEmpty
		// directly without having to mock the seeder separately.
		$externalPadSeeder = $externalPadSeeder
			?? new \OCA\EtherpadNextcloud\Service\ExternalPadSeeder($padFileService, $externalPadExportFetcher);
		if ($userNodeResolver === null) {
			// What a create finds by its file's id: the empty file it made.
			$empty = $this->createMock(File::class);
			$empty->method('getContent')->willReturn('');
			$userNodeResolver = $this->createMock(UserNodeResolver::class);
			$userNodeResolver->method('resolveUserFileNodeById')->willReturn($empty);
		}
		$claimedFileWriter = new ClaimedFileWriter($userNodeResolver, $padFileService, $etherpadClient, $this->createMock(LoggerInterface::class));
		if ($expectedRemoval !== null) {
			// The real writer but for the removal: what a failed create hands it is the point.
			$claimedFileWriter = $this->getMockBuilder(ClaimedFileWriter::class)
				->setConstructorArgs([$userNodeResolver, $padFileService, $etherpadClient, $this->createMock(LoggerInterface::class)])
				->onlyMethods(['deleteIfUnchanged'])
				->getMock();
			$claimedFileWriter->expects($this->once())->method('deleteIfUnchanged')->with(...$expectedRemoval);
		}
		return new PadCreationService(
			$padFileService,
			$padPaths ?? $this->createMock(PathNormalizer::class),
			$fileCreator ?? $this->createMock(PadFileCreator::class),
			$userNodeResolver,
			$bindingService,
			$claimedFileWriter,
			$padLifecycle,
			$bootstrap ?? $this->createMock(PadBootstrapService::class),
			$placeholderResolver,
			$externalPadSeeder,
			$padTypePolicy ?? $this->buildPadTypePolicy(true, true),
			$logger ?? $this->createMock(LoggerInterface::class),
			new ProvisionedPadRollback($bindingService, $padLifecycle, $this->createMock(LoggerInterface::class)),
		);
	}

	/** Both pad types enabled unless a test says otherwise — the shipped default. */
	private function buildPadTypePolicy(bool $protectedEnabled, bool $publicEnabled): \OCA\EtherpadNextcloud\Service\PadTypePolicy {
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
}
