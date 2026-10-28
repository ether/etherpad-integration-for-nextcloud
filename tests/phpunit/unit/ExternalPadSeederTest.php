<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExternalPadSeederTest extends TestCase {
	private const PAD_URL = 'https://pad.remote.test/p/RemotePad';

	/**
	 * @return iterable<string,array{array<string,mixed>,array<string,string>}> what the remote export gives, and what that adds to the answer
	 */
	public static function remoteExports(): iterable {
		yield 'with its text' => [['text' => 'snapshot-body'], []];
		yield 'refused by the remote' => [['text' => '', 'snapshot_unavailable' => true], ['snapshot_warning_code' => 'remote_export_unavailable']];
	}

	/**
	 * The document for the file, and what the create answers.
	 *
	 * @param array<string,mixed> $export
	 * @param array<string,string> $warning
	 */
	#[DataProvider('remoteExports')]
	public function testPreparesTheDocumentAndTheAnswer(array $export, array $warning): void {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with(
				999,
				'ext.RemotePad',
				BindingService::ACCESS_PUBLIC,
				new PadSnapshot($export['text'], '', 0),
				self::PAD_URL,
				[
					'pad_origin' => 'https://pad.remote.test',
					'remote_pad_id' => 'RemotePad',
				],
			)
			->willReturn('seeded-frontmatter');
		$padFileService->expects($this->never())->method('withExportSnapshot');

		$this->assertSame([
			'content' => 'seeded-frontmatter',
			'result' => [
				'file_id' => 999,
				'pad_id' => 'ext.RemotePad',
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'pad_url' => self::PAD_URL,
			] + $warning,
		], (new ExternalPadSeeder($padFileService, $this->fetcherAnswering($export)))->prepare(999, self::PAD_URL));
	}

	public function testSeedWritesWhatPrepareBuiltIntoTheNodeItIsHanded(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->expects($this->once())->method('putContent')->with('seeded-frontmatter');
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->with(999)->willReturn('seeded-frontmatter');

		(new ExternalPadSeeder($padFileService, $this->fetcherAnswering(['text' => 'snapshot-body'])))
			->seed($fileNode, 999, self::PAD_URL);
	}

	/** @param array<string,mixed> $export */
	private function fetcherAnswering(array $export): ExternalPadExportFetcher {
		$fetcher = $this->createMock(ExternalPadExportFetcher::class);
		$fetcher->expects($this->once())
			->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->with(self::PAD_URL)
			->willReturn([
				'pad_url' => self::PAD_URL,
				'origin' => 'https://pad.remote.test',
				'pad_id' => 'RemotePad',
			] + $export);

		return $fetcher;
	}
}
