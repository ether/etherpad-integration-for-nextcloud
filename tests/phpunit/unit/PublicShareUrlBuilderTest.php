<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\IURLGenerator;
use OCP\Share\Exceptions\ShareNotFound;
use PHPUnit\Framework\TestCase;

class PublicShareUrlBuilderTest extends TestCase {
	/** Nextcloud's route, which knows whether the link needs index.php. */
	public function testBuildShareBaseUrlTakesNextcloudsRoute(): void {
		$this->assertSame('/nc/index.php/s/share-token', $this->buildBuilder('/nc/index.php')->buildShareBaseUrl('share-token'));
	}

	/** The redirect builds on that link, index.php and all. */
	public function testBuildShareRedirectUrlKeepsTheFrontController(): void {
		$builder = $this->buildBuilder('/index.php');

		$this->assertSame('/index.php/s/share-token?dir=%2F', $builder->buildShareRedirectUrl('share-token', ''));
		$this->assertSame('/index.php/s/share-token?path=%2FSub&files=A.pad', $builder->buildShareRedirectUrl('share-token', '/Sub/A.pad'));
	}

	/** A redirect to '' would lead back to the address it came from. */
	public function testBuildShareBaseUrlRefusesWithoutTheShareRoute(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('');

		$this->expectException(ShareNotFound::class);

		(new PublicShareUrlBuilder($urlGenerator, new PathNormalizer()))->buildShareBaseUrl('share-token');
	}

	public function testBuildShareRedirectUrlDefaultsToRootDirectory(): void {
		$this->assertSame('/s/share-token?dir=%2F', $this->buildBuilder()->buildShareRedirectUrl('share-token', ''));
	}

	public function testBuildShareRedirectUrlBuildsNestedPadSelection(): void {
		$this->assertSame(
			'/s/share-token?path=%2FFolder%2FSub&files=Shared.pad',
			$this->buildBuilder()->buildShareRedirectUrl('share-token', '/Folder/Sub/Shared.pad')
		);
	}

	public function testBuildShareRedirectUrlRejectsNonPadFile(): void {
		$this->expectException(NotAPadFileException::class);
		$this->expectExceptionMessage('The selected file is not a .pad document.');

		$this->buildBuilder()->buildShareRedirectUrl('share-token', '/Folder/Text.txt');
	}

	public function testBuildShareRedirectUrlRejectsInvalidPath(): void {
		$this->expectException(InvalidShareFilePathException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildBuilder()->buildShareRedirectUrl('share-token', '../Shared.pad');
	}

	public function testBuildShareRedirectUrlRejectsNonStringFileParam(): void {
		$this->expectException(InvalidShareFilePathException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildBuilder()->buildShareRedirectUrl('share-token', 123);
	}

	public function testBuildShareRedirectUrlPreservesInvalidPathPreviousException(): void {
		try {
			$this->buildBuilder()->buildShareRedirectUrl('share-token', '../Shared.pad');
			$this->fail('Expected invalid share file path exception.');
		} catch (InvalidShareFilePathException $e) {
			$this->assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
		}
	}

	/** As Nextcloud's router answers: the prefix it puts before /s/<token>. */
	private function buildBuilder(string $prefix = ''): PublicShareUrlBuilder {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $arguments): string => $route === 'files_sharing.sharecontroller.showShare'
				? $prefix . '/s/' . rawurlencode((string)$arguments['token'])
				: '',
		);
		return new PublicShareUrlBuilder($urlGenerator, new PathNormalizer());
	}
}
