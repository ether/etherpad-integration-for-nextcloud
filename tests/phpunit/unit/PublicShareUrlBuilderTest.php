<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCA\EtherpadNextcloud\Tests\Support\StandsInForTheShareRoute;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PublicShareUrlBuilderTest extends TestCase {
	use StandsInForTheShareRoute;

	public function testBuildShareBaseUrlTakesNextcloudsRoute(): void {
		$this->assertSame('/nc/index.php/s/share-token', $this->buildBuilder('/nc/index.php')->buildShareBaseUrl('share-token'));
	}

	/** The router encodes the token: passed encoded, it would be twice. */
	public function testBuildShareBaseUrlHandsTheTokenOnAsItIs(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects($this->once())->method('linkToRoute')
			->with('files_sharing.sharecontroller.showShare', ['token' => 'a b'])
			->willReturn('/s/a%20b');

		$this->assertSame('/s/a%20b', (new PublicShareUrlBuilder($urlGenerator, new PathNormalizer()))->buildShareBaseUrl('a b'));
	}

	public function testBuildShareRedirectUrlKeepsTheRoutesPrefix(): void {
		$builder = $this->buildBuilder('/index.php');

		$this->assertSame('/index.php/s/share-token?dir=%2F', $builder->buildShareRedirectUrl('share-token', ''));
		$this->assertSame('/index.php/s/share-token?path=%2FSub&files=A.pad', $builder->buildShareRedirectUrl('share-token', '/Sub/A.pad'));
	}

	/** The caller builds the root's link then; this one would go unused. */
	public function testAFileTheLinkCannotNameBuildsNoLink(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects($this->never())->method('linkToRoute');

		$this->expectException(NotAPadFileException::class);

		(new PublicShareUrlBuilder($urlGenerator, new PathNormalizer()))->buildShareRedirectUrl('share-token', '/Text.txt');
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

	private function buildBuilder(string $prefix = ''): PublicShareUrlBuilder {
		return new PublicShareUrlBuilder($this->shareRoute($prefix), new PathNormalizer());
	}
}
