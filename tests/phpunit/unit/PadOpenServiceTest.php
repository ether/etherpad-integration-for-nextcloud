<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadOpenService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\SnapshotExtractor;
use OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadOpenServiceTest extends TestCase {
	public function testOpenByPathRejectsEmptyNormalizedPath(): void {
		$padPaths = $this->createMock(PathNormalizer::class);
		$padPaths->method('normalizeViewerFilePath')
			->with(" \t")
			->willReturn('');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->never())->method('resolveUserFileNodeByPath');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildService($padPaths, $userNodeResolver)
			->openByPath('alice', 'Alice', " \t");
	}

	/**
	 * A share that grants no write permission must not hand out a way to
	 * edit. Nextcloud's own read-only share stops at the file; the pad
	 * lives on another host, where the session and the URL are the whole of
	 * the access — so withholding them is the only thing that makes "view
	 * only" mean anything there.
	 */
	public function testGivesAProtectedPadAsASnapshotWhenTheShareIsReadOnly(): void {
		$session = $this->createMock(PadSessionService::class);
		$session->expects($this->never())->method('createProtectedOpenContext');

		$target = $this->openWith(
			BindingService::ACCESS_PROTECTED,
			updateable: false,
			padSessionService: $session,
		);

		$this->assertTrue($target->isReadOnlySnapshot);
		$this->assertSame('', $target->url, 'a viewer must not be given a pad to open');
		$this->assertSame('', $target->cookieHeader, 'and no session to open it with');
		// The response ships this as `pad_url`. Withholding one address and
		// handing back the same one under another key would undo the whole
		// thing, and no client reads it today — which is why it would go
		// unnoticed.
		$this->assertSame('', $target->padUrl, 'nor the address under a second name');
		$this->assertSame('snapshot text', $target->snapshotText);
	}

	/** With write permission, the same pad opens as it always did. */
	public function testGivesAProtectedPadAsAnEditorWhenTheShareAllowsWriting(): void {
		$session = $this->createMock(PadSessionService::class);
		$session->expects($this->once())
			->method('createProtectedOpenContext')
			->willReturn([
				'url' => 'https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1',
				'cookie' => [
					'name' => 'sessionID', 'value' => 's.x', 'expires' => 0, 'path' => '/',
					'domain' => '', 'secure' => true, 'http_only' => false, 'same_site' => 'lax',
				],
			]);

		$target = $this->openWith(
			BindingService::ACCESS_PROTECTED,
			updateable: true,
			padSessionService: $session,
		);

		$this->assertFalse($target->isReadOnlySnapshot);
		$this->assertSame('https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1', $target->url);
	}

	/**
	 * A public pad has no session to withhold, so the editable URL is the
	 * whole of the access. Etherpad's own read-only view is the one thing
	 * that cannot be typed into.
	 */
	public function testGivesAPublicPadItsReadOnlyUrlWhenTheShareIsReadOnly(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('buildPadUrl')->willReturn('https://pad.example.test/p/pad-1');
		$client->expects($this->once())
			->method('getReadOnlyPadUrl')
			->with('pad-1')
			->willReturn('https://pad.example.test/p/r.readonlyid');

		$target = $this->openWith(
			BindingService::ACCESS_PUBLIC,
			updateable: false,
			etherpadClient: $client,
			padId: 'pad-1',
		);

		$this->assertSame('https://pad.example.test/p/r.readonlyid', $target->url);
	}

	private function openWith(
		string $accessMode,
		bool $updateable,
		?PadSessionService $padSessionService = null,
		?EtherpadClient $etherpadClient = null,
		string $padId = 'g.ABCDEFGHIJKLMNOP$pad-1',
	): \OCA\EtherpadNextcloud\Service\PadOpenTarget {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);
		$file->method('getContent')->willReturn('frontmatter');
		$file->method('isUpdateable')->willReturn($updateable);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('getTextSnapshotForRestore')->willReturn('snapshot text');
		$padFileService->method('getHtmlSnapshotForRestore')->willReturn('<p>snapshot</p>');
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: ['pad_id' => $padId, 'access_mode' => $accessMode],
			body: 'snapshot text',
			padId: $padId,
			accessMode: $accessMode,
			padUrl: '',
			isExternal: false,
		));

		$client = $etherpadClient ?? $this->createMock(EtherpadClient::class);
		$service = new PadOpenService(
			$padFileService,
			$this->createMock(PathNormalizer::class),
			$userNodeResolver,
			new PadFileLockRetryService(static function (int $delay): void {
			}),
			$this->createMock(BindingService::class),
			$client,
			$this->createMock(ExternalPadExportFetcher::class),
			$padSessionService ?? $this->createMock(PadSessionService::class),
			new SnapshotExtractor($padFileService, new SnapshotHtmlSanitizer()),
			$this->createMock(LoggerInterface::class),
		);

		return $service->openById('alice', 'Alice', 138);
	}

	private function buildService(PathNormalizer $padPaths, UserNodeResolver $userNodeResolver): PadOpenService {
		$padFileService = $this->createMock(PadFileService::class);
		return new PadOpenService(
			$padFileService,
			$padPaths,
			$userNodeResolver,
			$this->createMock(PadFileLockRetryService::class),
			$this->createMock(BindingService::class),
			$this->createMock(EtherpadClient::class),
			$this->createMock(ExternalPadExportFetcher::class),
			$this->createMock(PadSessionService::class),
			new SnapshotExtractor($padFileService, new SnapshotHtmlSanitizer()),
			$this->createMock(LoggerInterface::class),
		);
	}
}
