<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
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

		$result = $this->buildService($padFileService, $padPaths, $fileCreator, null, null, $bindingService, $etherpadClient, $bootstrap)
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
			->with('alice', '/Test.pad', 'g.ABC$pad', true);

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

	public function testCreateFromUrlBuildsExternalPadFileWithoutBinding(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->method('getId')->willReturn(321);
		$fileNode->expects($this->once())->method('putContent')->with('external-frontmatter');

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/External')->willReturn('/External.pad');
		$fileCreator = $this->createMock(PadFileCreator::class);
		$fileCreator->method('createUserFile')->with('alice', '/External.pad')->willReturn($fileNode);
		$rollbackService = $this->createMock(PadCreateRollbackService::class);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
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

		$result = $this->buildService($padFileService, $padPaths, $fileCreator, null, $rollbackService, $bindingService, $etherpadClient)
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

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
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

		$result = $this->buildService($padFileService, $padPaths, $fileCreator, null, $rollbackService, $bindingService, $etherpadClient)
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

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
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
			$etherpadClient,
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
			->with('alice', '/External.pad', true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->with('https://pad.remote.test/p/RemotePad')
			->willThrowException(new EtherpadClientException('Remote pad unavailable.'));

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->never())->method('buildInitialDocument');

		$this->expectException(EtherpadClientException::class);

		$this->buildService($padFileService, $padPaths, $fileCreator, null, $rollbackService, etherpadClient: $etherpadClient)
			->createFromUrl('alice', '/External', 'https://pad.remote.test/p/RemotePad');
	}

	public function testCreateFromTemplateProvisionsFreshPadWithResolvedBody(): void {
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Protokoll-Tpl.pad');
		$templateNode->method('getContent')->willReturn('tpl-content');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')
			->with('alice', 7)
			->willReturn($templateNode);

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->with('/Meetings/Protokoll 18.05.2026.pad')->willReturn('/Meetings/Protokoll 18.05.2026.pad');

		$newFile = $this->createMock(\OCP\Files\File::class);
		$newFile->method('getId')->willReturn(99);
		$newFile->expects($this->once())->method('putContent');
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

		$result = $this->buildService($padFileService, $padPaths, $fileCreator, $userNodeResolver, null, $bindingService, $etherpadClient, $bootstrap)
			->createFromTemplate('alice', '/Meetings/Protokoll {{date:next monday|d.m.Y}}.pad', 7, $user);

		$this->assertSame('/Meetings/Protokoll 18.05.2026.pad', $result['file']);
		$this->assertSame(99, $result['file_id']);
		$this->assertSame('p-fresh', $result['pad_id']);
		$this->assertSame(BindingService::ACCESS_PROTECTED, $result['access_mode']);
	}

	public function testCreateFromTemplateRefusesNonPadTemplate(): void {
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Notes.txt');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($templateNode);

		$this->expectException(\OCA\EtherpadNextcloud\Exception\NotAPadFileException::class);

		$this->buildService(userNodeResolver: $userNodeResolver)
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

		$this->buildService($padFileService, $padPaths, null, $userNodeResolver)
			->createFromTemplate('alice', '/Out.pad', 7, null);
	}

	public function testCreateFromTemplateReportsMissingPadIdSeparately(): void {
		// A template with malformed / empty frontmatter (no pad_id) is not the
		// same failure mode as an `ext.*` template — the user should see a
		// targeted message so they can fix the template instead of being told
		// the file is "external".
		$templateNode = $this->createMock(\OCP\Files\File::class);
		$templateNode->method('getName')->willReturn('Broken.pad');
		$templateNode->method('getContent')->willReturn('tpl');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($templateNode);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: [],
			body: '',
			padId: '',
			accessMode: '',
			padUrl: '',
			isExternal: false,
		));

		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeCreatePath')->willReturn('/Out.pad');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Template has no usable pad_id in its frontmatter.');

		$this->buildService($padFileService, $padPaths, null, $userNodeResolver)
			->createFromTemplate('alice', '/Out.pad', 7, null);
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
	): PadCreationService {
		if ($placeholderResolver === null) {
			$timeFactory = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
			$timeFactory->method('getTime')->willReturn(1778976000);
			$placeholderResolver = new \OCA\EtherpadNextcloud\Service\PadPlaceholderResolver($timeFactory);
		}
		$padFileService = $padFileService ?? $this->createMock(PadFileService::class);
		$etherpadClient = $etherpadClient ?? $this->createMock(EtherpadClient::class);
		// Build a real seeder from the test's mocked deps by default so the
		// createFromUrl tests can keep stubbing
		// EtherpadClient::normalizeAndFetchExternalPublicPadTextOrEmpty
		// directly without having to mock the seeder separately.
		$externalPadSeeder = $externalPadSeeder
			?? new \OCA\EtherpadNextcloud\Service\ExternalPadSeeder($padFileService, $etherpadClient);
		return new PadCreationService(
			$padFileService,
			$padPaths ?? $this->createMock(PathNormalizer::class),
			$fileCreator ?? $this->createMock(PadFileCreator::class),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$rollbackService ?? $this->createMock(PadCreateRollbackService::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$etherpadClient,
			$bootstrap ?? $this->createMock(PadBootstrapService::class),
			$placeholderResolver,
			$externalPadSeeder,
			$this->createMock(LoggerInterface::class),
		);
	}
}
