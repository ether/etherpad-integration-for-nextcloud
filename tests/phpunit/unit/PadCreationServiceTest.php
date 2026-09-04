<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadFileCreator;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadCreationServiceTest extends TestCase {
	public function testCreateBuildsPadFileAndBinding(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(123);
		$fileNode->expects($this->once())->method('putContent')->with('frontmatter');

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
			->with(123, 'g.ABC$pad', BindingService::ACCESS_PROTECTED, '', 'https://pad.example.test/p/g.ABC$pad')
			->willReturn('frontmatter');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with(123, 'g.ABC$pad', BindingService::ACCESS_PROTECTED);

		$result = $this->buildService($padFileService, $padPaths, $fileCreator, $this->resolverFor($fileNode), null, $bindingService, $etherpadClient, $bootstrap)
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
		$rollbackService = $this->createMock(PadCreateRollbackService::class);
		$rollbackService->expects($this->once())
			->method('rollbackFailedCreate')
			->with('alice', '/Test.pad', 'g.ABC$pad', $this->callback(
				// Check identity, baseline and write evidence, not just the claim's type.
				static fn (CreatedFileClaim $claim): bool => $claim->uid === 'alice'
					&& $claim->fileId === 123
					&& $claim->expectedBefore === ''
					&& $claim->writtenHash !== null
			));

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->willReturn('g.ABC$pad');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/g.ABC$pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('frontmatter');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('createBinding')->willThrowException(new BindingException('Duplicate binding.'));

		$this->expectException(BindingException::class);

		$this->buildService($padFileService, $padPaths, $fileCreator, null, $rollbackService, $bindingService, $etherpadClient, $bootstrap)
			->create('alice', '/Test', BindingService::ACCESS_PROTECTED);
	}

	public function testCreateInParentRejectsNonCreatableFolder(): void {
		$parent = $this->createMock(Folder::class);
		$parent->method('isCreatable')->willReturn(false);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreateFileName')->with('Test')->willReturn('Test.pad');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFolderNodeById')->with('alice', 99)->willReturn($parent);

		$this->expectException(PadParentFolderNotWritableException::class);

		$this->buildService(padPaths: $padPaths, userNodeResolver: $userNodeResolver)
			->createInParent('alice', 99, 'Test', BindingService::ACCESS_PUBLIC);
	}

	/**
	 * `toUserAbsolutePath()` throws when the node cannot be mapped into the
	 * user's tree, and by then `newFile()` has already made the file. The
	 * claim has to exist before that call, or the rollback is handed
	 * nothing and the empty `.pad` stays behind.
	 */
	public function testClaimsTheFileBeforeResolvingItsPath(): void {
		$parent = $this->createMock(Folder::class);
		$parent->method('isCreatable')->willReturn(true);

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

		$rollbackService = $this->createMock(PadCreateRollbackService::class);
		$rollbackService->expects($this->once())
			->method('rollbackFailedCreate')
			->with('alice', $this->anything(), '', $this->callback(
				static fn (?CreatedFileClaim $claim): bool => $claim !== null && $claim->fileId === 4242
			));

		$this->expectException(\OCP\Files\NotFoundException::class);

		$this->buildService(
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			userNodeResolver: $userNodeResolver,
			rollbackService: $rollbackService,
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
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Template.pad');
		$templateNode->method('getContent')->willReturn('tpl');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($templateNode);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'nc-abc'],
			body: '',
			padId: 'nc-abc',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
		));

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

	public function testTemplateMaterialisationUsesTheRedirectedModeEndToEnd(): void {
		// The template says "public", but the admin only offers protected —
		// the pad and its binding must both end up protected.
		$template = $this->createMock(\OCP\Files\File::class);
		$template->method('getName')->willReturn('Template.pad');
		$template->method('getContent')->willReturn('tpl');

		$target = $this->createMock(\OCP\Files\File::class);
		$target->method('getId')->willReturn(4321);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'nc-source'],
			body: 'body',
			padId: 'nc-source',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'hello', 'html' => '<p>hello</p>']);
		$padFileService->method('buildInitialDocument')
			->with(4321, 'g.grp$pad', BindingService::ACCESS_PROTECTED, 'hello', 'https://pad.example.test/p/x')
			->willReturn('doc');
		$padFileService->method('withExportSnapshot')->willReturn('doc-with-snapshot');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects(self::once())
			->method('provisionPadId')
			->with(BindingService::ACCESS_PROTECTED)
			->willReturn('g.grp$pad');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');

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
		)->materializeTemplateInto($target, $template, $this->materializeUser());

		self::assertSame(BindingService::ACCESS_PROTECTED, $result['access_mode']);
	}

	public function testCreateFromUrlBuildsExternalPadFileWithoutBinding(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(321);
		$fileNode->expects($this->once())->method('putContent')->with('external-frontmatter');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/External')->willReturn('/External.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->with('alice', '/External.pad')->willReturn($fileNode);
		$rollbackService = $this->createMock(PadCreateRollbackService::class);

		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->with('https://pad.remote.test/p/RemotePad')
			->willReturn([
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
				'origin' => 'https://pad.remote.test',
				'pad_id' => 'RemotePad',
				'text' => "Initial snapshot\nfrom remote pad",
			]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with(
				321,
				'ext.RemotePad',
				BindingService::ACCESS_PUBLIC,
				'',
				'https://pad.remote.test/p/RemotePad',
				[
					'pad_origin' => 'https://pad.remote.test',
					'remote_pad_id' => 'RemotePad',
				]
			)
			->willReturn('external-empty-frontmatter');
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with('external-empty-frontmatter', "Initial snapshot\nfrom remote pad", '', 0, false)
			->willReturn('external-frontmatter');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('createBinding');

		$result = $this->buildService(
			$padFileService, $padPaths, $fileCreator, $this->resolverFor($fileNode), $rollbackService, $bindingService, externalPadExportFetcher: $fetcher)
			->createFromUrl('alice', '/External', 'https://pad.remote.test/p/RemotePad');

		$this->assertSame([
			'file' => '/External.pad',
			'file_id' => 321,
			'pad_id' => 'ext.RemotePad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.remote.test/p/RemotePad',
		], $result);
	}

	public function testCreateFromUrlBuildsExternalPadFileWithoutInitialSnapshot(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(321);
		$fileNode->expects($this->once())->method('putContent')->with('external-frontmatter');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/External')->willReturn('/External.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->with('alice', '/External.pad')->willReturn($fileNode);
		$rollbackService = $this->createMock(PadCreateRollbackService::class);

		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->with('https://pad.remote.test/p/RemotePad')
			->willReturn([
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
				'origin' => 'https://pad.remote.test',
				'pad_id' => 'RemotePad',
				'text' => '',
			]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('external-empty-frontmatter');
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with('external-empty-frontmatter', '', '', 0, false)
			->willReturn('external-frontmatter');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('createBinding');

		$result = $this->buildService(
			$padFileService, $padPaths, $fileCreator, $this->resolverFor($fileNode), $rollbackService, $bindingService, externalPadExportFetcher: $fetcher)
			->createFromUrl('alice', '/External', 'https://pad.remote.test/p/RemotePad');

		$this->assertSame([
			'file' => '/External.pad',
			'file_id' => 321,
			'pad_id' => 'ext.RemotePad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.remote.test/p/RemotePad',
		], $result);
	}

	public function testCreateFromUrlSurfacesSnapshotWarningCodeWhenRemoteExportUnavailable(): void {
		// Regression: a remote Etherpad whose /export endpoint 404s used to
		// produce a silent empty-snapshot file. The frontend now needs a
		// stable code to surface a toast to the user.
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(322);
		$fileNode->method('putContent')->willReturnSelf();

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/External.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->willReturn($fileNode);

		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->willReturn([
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
				'origin' => 'https://pad.remote.test',
				'pad_id' => 'RemotePad',
				'text' => '',
				'snapshot_unavailable' => true,
			]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('frontmatter');
		$padFileService->method('withExportSnapshot')->willReturn('frontmatter');

		$result = $this->buildService(
			$padFileService,
			$padPaths,
			$fileCreator,
			null,
			$this->createMock(PadCreateRollbackService::class),
			$this->createMock(BindingService::class),
			externalPadExportFetcher: $fetcher,
		)->createFromUrl('alice', '/External', 'https://pad.remote.test/p/RemotePad');

		$this->assertSame('remote_export_unavailable', $result['snapshot_warning_code']);
	}

	public function testCreateFromUrlRollsBackWhenInitialSnapshotFetchFails(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(321);
		$fileNode->expects($this->never())->method('putContent');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/External')->willReturn('/External.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->expects($this->once())->method('createUserFile')->with('alice', '/External.pad')->willReturn($fileNode);
		$rollbackService = $this->createMock(PadCreateRollbackService::class);
		$rollbackService->expects($this->once())
			->method('rollbackExternalCreate')
			->with('alice', '/External.pad', $this->isInstanceOf(CreatedFileClaim::class));

		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->with('https://pad.remote.test/p/RemotePad')
			->willThrowException(new EtherpadClientException('Remote pad unavailable.'));

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->never())->method('buildInitialDocument');

		$this->expectException(EtherpadClientException::class);

		$this->buildService($padFileService, $padPaths, $fileCreator, null, $rollbackService, externalPadExportFetcher: $fetcher)
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
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn([
			'text' => 'Datum: {{date:next monday|d.m.Y}}',
			'html' => '',
		]);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with(99, 'p-fresh', BindingService::ACCESS_PROTECTED, 'Datum: 18.05.2026', $this->isType('string'))
			->willReturn('doc');
		$padFileService->method('withExportSnapshot')->willReturn('doc-with-snapshot');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('provisionPadId')
			->with(BindingService::ACCESS_PROTECTED)
			->willReturn('p-fresh');
		$bootstrap->expects($this->once())
			->method('pushInitialSnapshot')
			->with('p-fresh', 'Datum: 18.05.2026', '');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('createBinding')
			->with(99, 'p-fresh', BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/p-fresh');

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getDisplayName')->willReturn('Alice');
		$user->method('getUID')->willReturn('alice');

		$result = $this->buildService(
			$padFileService, $padPaths, $fileCreator, $userNodeResolver, null, $bindingService, $etherpadClient, $bootstrap)
			->createFromTemplate('alice', '/Meetings/Protokoll {{date:next monday|d.m.Y}}.pad', 7, $user);

		$this->assertSame('/Meetings/Protokoll 18.05.2026.pad', $result['file']);
		$this->assertSame(99, $result['file_id']);
		$this->assertSame('p-fresh', $result['pad_id']);
		$this->assertSame(BindingService::ACCESS_PROTECTED, $result['access_mode']);
	}

	/**
	 * Everything after the file is created can still fail, and the rollback
	 * then has to be told which file to remove. The template flow used to
	 * signal that with a boolean; carrying the id is what makes cleanup
	 * follow the file rather than its name.
	 */
	public function testCreateFromTemplateRollsBackWithTheCreatedFileId(): void {
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Tpl.pad');
		$templateNode->method('getContent')->willReturn('tpl-content');

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

		$rollbackService = $this->createMock(PadCreateRollbackService::class);
		$rollbackService->expects($this->once())
			->method('rollbackCreatedFileOnly')
			->with('alice', '/FromTpl.pad', $this->isInstanceOf(CreatedFileClaim::class));

		$this->expectException(\RuntimeException::class);

		$this->buildService(
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			padFileService: $padFileService,
			rollbackService: $rollbackService,
			userNodeResolver: $userNodeResolver,
		)->createFromTemplate('alice', '/FromTpl.pad', 7, null);
	}

	/**
	 * The case the earlier template test missed: the document is written and
	 * the pad exists, and only then the binding fails. The rollback has to
	 * know the pad id by then — otherwise it sees a file with content it
	 * cannot attribute and leaves a broken .pad behind.
	 */
	public function testCreateFromTemplateRollsBackWithThePadIdWhenTheBindingFails(): void {
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Tpl.pad');
		$templateNode->method('getContent')->willReturn('tpl-content');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($templateNode);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/FromTpl.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.tpl$pad', 'access_mode' => BindingService::ACCESS_PUBLIC],
			body: 'body',
			padId: 'g.tpl$pad',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'text', 'html' => '']);
		$padFileService->method('buildInitialDocument')->willReturn('doc');
		$padFileService->method('withExportSnapshot')->willReturn('doc');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->willReturn('p-fresh');

		// Written, then the binding fails.
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('binding failed'));

		$rollbackService = $this->createMock(PadCreateRollbackService::class);
		$rollbackService->expects($this->once())
			->method('rollbackCreatedFileOnly')
			->with('alice', '/FromTpl.pad', $this->isInstanceOf(CreatedFileClaim::class));
		$rollbackService->expects($this->never())->method('rollbackFailedCreate');

		$this->expectException(\RuntimeException::class);

		$this->buildService(
			padFileService: $padFileService,
			padPaths: $padPaths,
			fileCreator: $this->fileCreatorReturningId(4242),
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
			bootstrap: $bootstrap,
			rollbackService: $rollbackService,
		)->createFromTemplate('alice', '/FromTpl.pad', 7, null);
	}

	/**
	 * With the real rollback service, not a mock: materializeTemplateInto()
	 * deletes the pad it provisioned before rethrowing, so the outer rollback
	 * must not delete it again. A second deletePad() costs a round trip and
	 * logs a cleanup warning about a pad that is already gone.
	 */
	public function testTemplateBindingFailureDeletesThePadExactlyOnce(): void {
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Tpl.pad');
		$templateNode->method('getContent')->willReturn('tpl-content');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($templateNode);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/FromTpl.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'g.tpl$pad', 'access_mode' => BindingService::ACCESS_PUBLIC],
			body: 'body',
			padId: 'g.tpl$pad',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'text', 'html' => '']);
		$padFileService->method('buildInitialDocument')->willReturn('doc');
		$padFileService->method('withExportSnapshot')->willReturn('doc');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->willReturn('p-fresh');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('binding failed'));

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('deletePad')->with('p-fresh');

		$rollbackService = new PadCreateRollbackService(
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$this->createMock(UserNodeResolver::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$this->expectException(\RuntimeException::class);

		$this->buildService(
			padFileService: $padFileService,
			padPaths: $padPaths,
			fileCreator: $this->fileCreatorReturningId(4242),
			userNodeResolver: $userNodeResolver,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
			bootstrap: $bootstrap,
			rollbackService: $rollbackService,
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
		$existing->expects($this->never())->method('putContent');
		// And it must survive the rollback that the refusal triggers: a file
		// that was already a pad is not this create's to clean up.
		$existing->expects($this->never())->method('delete');

		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->willReturn($existing);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/Notes.pad');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->with(4242)->willReturn(['pad_id' => 'g.someone$pad']);
		$bindingService->expects($this->never())->method('createBinding');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('provisionPadId');

		$this->expectException(\OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException::class);

		$this->buildService(
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			bindingService: $bindingService,
			bootstrap: $bootstrap,
			rollbackService: new PadCreateRollbackService(
				new ManagedPadLifecycle($this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
				$this->createMock(UserNodeResolver::class),
				$this->createMock(\Psr\Log\LoggerInterface::class),
			),
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

	/**
	 * `createBinding` is the last step of a materialization and can commit
	 * and still throw. Both callers give up on the file — the create flow
	 * deletes it, the template listener wipes it and re-initialises — so a
	 * surviving row would send that re-initialisation at a pad this cleanup
	 * has just deleted.
	 */
	public function testMaterializeRemovesARowItsFailedBindingWriteLeftBehind(): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$pad';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('connection lost'));
		$bindingService->method('isBoundTo')->with(4321, $padId)->willReturn(true);
		$bindingService->expects(self::once())->method('deleteByFileId')->with(4321);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		$etherpadClient->expects(self::once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');

		$this->expectException(\RuntimeException::class);
		$this->buildMaterializeService($bindingService, $etherpadClient, $padId)
			->materializeTemplateInto($this->buildMaterializeTarget(), $this->buildMaterializeTemplate(), $this->materializeUser());
	}

	/** A row naming a different pad belongs to the request that won the file. */
	public function testMaterializeLeavesARivalRequestsRow(): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$pad';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('createBinding')->willThrowException(new \RuntimeException('unique constraint violation'));
		$bindingService->method('isBoundTo')->with(4321, $padId)->willReturn(false);
		$bindingService->expects(self::never())->method('deleteByFileId');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->willReturn('https://pad.example.test/p/x');
		$etherpadClient->expects(self::once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');

		$this->expectException(\RuntimeException::class);
		$this->buildMaterializeService($bindingService, $etherpadClient, $padId)
			->materializeTemplateInto($this->buildMaterializeTarget(), $this->buildMaterializeTemplate(), $this->materializeUser());
	}

	private function buildMaterializeTemplate(): \OCP\Files\File {
		$template = $this->createMock(\OCP\Files\File::class);
		$template->method('getName')->willReturn('Template.pad');
		$template->method('getContent')->willReturn('tpl');
		return $template;
	}

	private function materializeUser(): \OCP\IUser {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');

		return $user;
	}

	private function buildMaterializeTarget(): \OCP\Files\File {
		$target = $this->createMock(\OCP\Files\File::class);
		$target->method('getId')->willReturn(4321);
		return $target;
	}

	private function buildMaterializeService(
		BindingService $bindingService,
		EtherpadClient $etherpadClient,
		string $padId,
	): PadCreationService {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => 'nc-source'],
			body: 'body',
			padId: 'nc-source',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
		));
		$padFileService->method('getSnapshotPartsFromBody')->willReturn(['text' => 'hello', 'html' => '']);
		$padFileService->method('buildInitialDocument')->willReturn('doc');
		$padFileService->method('withExportSnapshot')->willReturn('doc-with-snapshot');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->willReturn($padId);

		return $this->buildService(
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
			bootstrap: $bootstrap,
			padTypePolicy: $this->buildPadTypePolicy(true, false),
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
			rollbackService: new PadCreateRollbackService(
				new ManagedPadLifecycle($this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
				$this->createMock(UserNodeResolver::class),
				$this->createMock(\Psr\Log\LoggerInterface::class),
			),
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
		));

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/Out.pad');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('External pads cannot be used as a template.');

		$this->buildService($padFileService, $padPaths, $this->fileCreatorReturningId(11), $userNodeResolver)
			->createFromTemplate('alice', '/Out.pad', 7, null);
	}

	public function testWritesToTheFileTheIdResolvesToNotTheCreatedNode(): void {
		$createdNode = $this->createMock(\OCP\Files\File::class);
		$createdNode->method('getId')->willReturn(4242);
		$createdNode->method('getSize')->willReturn(0);
		$createdNode->expects($this->never())->method('putContent');

		$stillOurs = $this->createMock(\OCP\Files\File::class);
		$stillOurs->expects($this->once())->method('putContent')->with('frontmatter');

		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->willReturn($createdNode);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/Notes.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('frontmatter');

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->method('provisionPadId')->willReturn('p-fresh');

		$this->buildService(
			padFileService: $padFileService,
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			userNodeResolver: $this->resolverFor($stillOurs, 'alice', 4242),
			bootstrap: $bootstrap,
		)->create('alice', '/Notes.pad', BindingService::ACCESS_PUBLIC);
	}

	/** A binding failure after writing must still allow recovery from that write. */
	public function testRecognisesOurOwnWrittenDocumentAsStillOurs(): void {
		$claim = new CreatedFileClaim('alice', 4242);
		$claim->writtenHash = hash('sha256', 'our document');

		$node = $this->createMock(\OCP\Files\File::class);
		$node->method('getContent')->willReturn('our document');

		$service = $this->buildService(userNodeResolver: $this->resolverFor($node, 'alice', 4242));

		$this->assertSame($node, $service->resolveUnchangedClaim($claim));
	}

	public function testDoesNotRecogniseAStrangersContent(): void {
		$claim = new CreatedFileClaim('alice', 4242);
		$claim->writtenHash = hash('sha256', 'our document');

		$node = $this->createMock(\OCP\Files\File::class);
		$node->method('getContent')->willReturn('somebody else\'s notes');

		$service = $this->buildService(userNodeResolver: $this->resolverFor($node, 'alice', 4242));

		$this->assertNull($service->resolveUnchangedClaim($claim));
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

		$rollbackService = new PadCreateRollbackService(
			new ManagedPadLifecycle($this->createMock(EtherpadClient::class), $this->createMock(LoggerInterface::class)),
			$resolver,
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$this->expectException(\RuntimeException::class);

		$this->buildService(
			padPaths: $padPaths,
			fileCreator: $fileCreator,
			userNodeResolver: $resolver,
			rollbackService: $rollbackService,
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
		?PadCreateRollbackService $rollbackService = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
		?PadBootstrapService $bootstrap = null,
		?\OCA\EtherpadNextcloud\Service\PadPlaceholderResolver $placeholderResolver = null,
		?\OCA\EtherpadNextcloud\Service\ExternalPadSeeder $externalPadSeeder = null,
		?ExternalPadExportFetcher $externalPadExportFetcher = null,
		?\OCA\EtherpadNextcloud\Service\PadTypePolicy $padTypePolicy = null,
	): PadCreationService {
		if ($placeholderResolver === null) {
			$timeFactory = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
			$timeFactory->method('getTime')->willReturn(1778976000);
			$placeholderResolver = new \OCA\EtherpadNextcloud\Service\PadPlaceholderResolver($timeFactory);
		}
		$padFileService = $padFileService ?? $this->createMock(PadFileService::class);
		$etherpadClient = $etherpadClient ?? $this->createMock(EtherpadClient::class);
		$externalPadExportFetcher = $externalPadExportFetcher ?? $this->createMock(ExternalPadExportFetcher::class);
		// Build a real seeder from the test's mocked deps by default so the
		// createFromUrl tests can keep stubbing
		// ExternalPadExportFetcher::normalizeAndFetchExternalPublicPadTextOrEmpty
		// directly without having to mock the seeder separately.
		$externalPadSeeder = $externalPadSeeder
			?? new \OCA\EtherpadNextcloud\Service\ExternalPadSeeder($padFileService, $externalPadExportFetcher);
		return new PadCreationService(
			$padFileService,
			$padPaths ?? $this->createMock(PathNormalizer::class),
			$fileCreator ?? $this->createMock(PadFileCreator::class),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$rollbackService ?? $this->createMock(PadCreateRollbackService::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$etherpadClient,
			new ManagedPadLifecycle($etherpadClient, $this->createMock(LoggerInterface::class)),
			$bootstrap ?? $this->createMock(PadBootstrapService::class),
			$placeholderResolver,
			$externalPadSeeder,
			$padTypePolicy ?? $this->buildPadTypePolicy(true, true),
			$this->createMock(LoggerInterface::class),
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
