<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCA\EtherpadNextcloud\Template\PadTemplateProvider;
use OCP\Files\File;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadTemplateProviderTest extends TestCase {
	private const MIME = 'application/x-etherpad-nextcloud';

	/** Nextcloud asks every provider for every creator. */
	public function testIgnoresOtherMimetypes(): void {
		$this->assertSame([], $this->buildProvider([])->getCustomTemplates('text/markdown'));
	}

	/**
	 * The templates an admin uploaded, offered to everyone. Each carries its
	 * own content and access mode, so the provider decides neither.
	 */
	public function testOffersTheSharedTemplates(): void {
		$templates = $this->buildProvider([$this->templateFile('Meeting notes.pad')])
			->getCustomTemplates(self::MIME);

		$this->assertSame(
			['global:Meeting notes.pad'],
			array_map(static fn($t): string => (string)$t->jsonSerialize()['templateId'], $templates),
		);
	}

	/**
	 * A picker that fails to open would take every other app's templates with
	 * it, so this one failure is logged and skipped. The admin page reads the
	 * same list without that guard, where it stays visible.
	 */
	public function testKeepsThePickerOpenWhenTheTemplatesCannotBeListed(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('globalTemplates')->willThrowException(new \RuntimeException('appdata unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$provider = new PadTemplateProvider($storage, $this->urlGenerator(), $logger);

		$this->assertSame([], $provider->getCustomTemplates(self::MIME));
	}

	/**
	 * Nextcloud points the tile at /core/preview, which has nothing to render
	 * for a .pad – the picker would show its generic document icon.
	 */
	public function testTheTemplatesCarryThePadIcon(): void {
		$templates = $this->buildProvider([$this->templateFile('Meeting notes.pad')])
			->getCustomTemplates(self::MIME);

		$this->assertStringContainsString('etherpad-icon', (string)$templates[0]->jsonSerialize()['previewUrl']);
	}

	public function testResolvesATemplateIdToItsFile(): void {
		$file = $this->templateFile('Meeting notes.pad');
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('globalTemplate')->with('Meeting notes.pad')->willReturn($file);

		$provider = new PadTemplateProvider($storage, $this->urlGenerator(), $this->createMock(LoggerInterface::class));

		$this->assertSame($file, $provider->getCustomTemplate('global:Meeting notes.pad'));
	}

	/**
	 * @return iterable<string,array{0:string}>
	 */
	public static function unknownTemplateIdProvider(): iterable {
		yield 'not ours' => ['some-user-template'];
		yield 'empty' => [''];
		yield 'shared template that is gone' => ['global:gone.pad'];
	}

	#[DataProvider('unknownTemplateIdProvider')]
	public function testRejectsATemplateIdItCannotResolve(string $templateId): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('globalTemplate')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		(new PadTemplateProvider($storage, $this->urlGenerator(), $this->createMock(LoggerInterface::class)))->getCustomTemplate($templateId);
	}

	/** @param list<File> $templates */
	private function buildProvider(array $templates): PadTemplateProvider {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('globalTemplates')->willReturn($templates);
		return new PadTemplateProvider($storage, $this->urlGenerator(), $this->createMock(LoggerInterface::class));
	}

	private function urlGenerator(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturnCallback(
			static fn(string $app, string $file): string => '/apps/' . $app . '/img/' . $file
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn(string $path): string => 'https://cloud.example.test' . $path
		);
		return $urlGenerator;
	}

	/** Nextcloud's Template serialises the file, so it has to answer for real. */
	private function templateFile(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4711);
		$file->method('getName')->willReturn($name);
		$file->method('getEtag')->willReturn('etag');
		$file->method('getMTime')->willReturn(1_700_000_000);
		$file->method('getMimeType')->willReturn(self::MIME);
		$file->method('getSize')->willReturn(0);
		$file->method('getType')->willReturn('file');
		return $file;
	}
}
