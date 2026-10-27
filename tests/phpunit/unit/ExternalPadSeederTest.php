<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class ExternalPadSeederTest extends TestCase {
	public function testSeedWritesExtFrontmatterAndSnapshotIntoFile(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->expects($this->once())
			->method('putContent')
			->with('seeded-frontmatter');

		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->expects($this->once())
			->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->with('https://pad.remote.test/p/RemotePad')
			->willReturn([
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
				'origin' => 'https://pad.remote.test',
				'pad_id' => 'RemotePad',
				'text' => 'snapshot-body',
			]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with(
				999,
				'ext.RemotePad',
				BindingService::ACCESS_PUBLIC,
				new PadSnapshot('snapshot-body', '', 0),
				'https://pad.remote.test/p/RemotePad',
				[
					'pad_origin' => 'https://pad.remote.test',
					'remote_pad_id' => 'RemotePad',
				],
			)
			->willReturn('seeded-frontmatter');
		$padFileService->expects($this->never())->method('withExportSnapshot');

		(new ExternalPadSeeder($padFileService, $fetcher))
			->seed($fileNode, 999, 'https://pad.remote.test/p/RemotePad');
	}

	/** The document for the file, and what the create answers. */
	public function testPreparesTheDocumentAndTheAnswer(): void {
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->willReturn([
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
				'origin' => 'https://pad.remote.test',
				'pad_id' => 'RemotePad',
				'text' => 'snapshot-body',
			]);
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('seeded-frontmatter');

		$this->assertSame([
			'content' => 'seeded-frontmatter',
			'result' => [
				'file_id' => 999,
				'pad_id' => 'ext.RemotePad',
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
			],
		], (new ExternalPadSeeder($padFileService, $fetcher))->prepare(999, 'https://pad.remote.test/p/RemotePad'));
	}

	public function testPrepareSurfacesSnapshotUnavailableWarningCode(): void {
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
		$padFileService->method('buildInitialDocument')->willReturn('doc');

		$prepared = (new ExternalPadSeeder($padFileService, $fetcher))
			->prepare(1, 'https://pad.remote.test/p/RemotePad');

		$this->assertSame('remote_export_unavailable', $prepared['result']['snapshot_warning_code']);
	}
}
