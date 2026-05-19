<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\PublicPadContextService;
use OCA\EtherpadNextcloud\Service\PublicPadOpenService;
use OCA\EtherpadNextcloud\Service\PublicPadOpenTarget;
use OCA\EtherpadNextcloud\Service\PublicShareResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Constants;
use OCP\Files\File;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

class PublicPadContextServiceTest extends TestCase {
	public function testResolveBuildsPublicPadContextFromCachedShare(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('frontmatter');

		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getNode')->willReturn($file);

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->never())->method('getShareByToken');
		$shareResolver = new PublicShareResolver($shareManager, new PathNormalizer());

		$frontmatter = [
			'pad_id' => 'g.group$pad',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'pad_url' => '',
		];
		$padFiles = $this->createMock(PadFileService::class);
		$padFiles->expects($this->once())->method('readPad')->with('frontmatter')->willReturn(new ParsedPadFile(
			frontmatter: $frontmatter,
			body: '',
			padId: 'g.group$pad',
			accessMode: BindingService::ACCESS_PROTECTED,
			padUrl: '',
			isExternal: false,
		));

		$bindings = $this->createMock(BindingService::class);
		$bindings->expects($this->once())
			->method('assertConsistentMapping')
			->with(42, 'g.group$pad', BindingService::ACCESS_PROTECTED);

		$openService = $this->createMock(PublicPadOpenService::class);
		$openService->expects($this->once())
			->method('open')
			->with('g.group$pad', BindingService::ACCESS_PROTECTED, true, 'token', false, 'frontmatter', '')
			->willReturn(new PublicPadOpenTarget('', '', '', true, 'Snapshot text', '<p>Snapshot</p>'));

		$context = (new PublicPadContextService($shareResolver, $padFiles, $bindings, $openService))->resolve('token', '', $share);

		$this->assertSame('Shared.pad', $context->title);
		$this->assertSame('', $context->url);
		$this->assertFalse($context->isExternal);
		$this->assertTrue($context->isReadOnlySnapshot);
		$this->assertSame('Snapshot text', $context->snapshotText);
		$this->assertSame('<p>Snapshot</p>', $context->snapshotHtml);
	}
}
