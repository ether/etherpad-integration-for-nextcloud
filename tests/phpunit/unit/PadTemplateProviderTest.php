<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCA\EtherpadNextcloud\Template\PadTemplateProvider;
use OCP\Files\File;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadTemplateProviderTest extends TestCase {
	private const MIME = 'application/x-etherpad-nextcloud';

	/**
	 * The public tile only earns its place while both types are on: with one
	 * switched off the blank entry already produces the only type on offer.
	 * The external tile is not a pad type at all — it follows
	 * allow_external_pads alone.
	 *
	 * @return iterable<string,array{0:bool,1:bool,2:bool,3:list<string>}>
	 */
	public static function tileProvider(): iterable {
		yield 'both types, external off' => [true, true, false, ['pad-public']];
		yield 'both types, external on' => [true, true, true, ['pad-public', 'pad-external']];
		yield 'protected only' => [true, false, false, []];
		yield 'public only' => [false, true, false, []];
		yield 'public only, external on' => [false, true, true, ['pad-external']];
	}

	#[DataProvider('tileProvider')]
	public function testOffersTheTilesTheSettingsAllow(bool $protected, bool $public, bool $external, array $expected): void {
		$templates = $this->buildProvider($protected, $public, $external)->getCustomTemplates(self::MIME);

		$this->assertSame($expected, array_map(
			static fn($t): string => (string)$t->jsonSerialize()['templateId'],
			$templates,
		));
	}

	/** Nextcloud asks every provider for every creator. */
	public function testIgnoresOtherMimetypes(): void {
		$this->assertSame([], $this->buildProvider()->getCustomTemplates('text/markdown'));
	}

	/** The tiles would otherwise show the generic document icon. */
	public function testEveryTileCarriesAPreviewUrl(): void {
		$templates = $this->buildProvider(external: true)->getCustomTemplates(self::MIME);

		foreach ($templates as $template) {
			$this->assertStringContainsString('etherpad-icon', (string)$template->jsonSerialize()['previewUrl']);
		}
	}

	/**
	 * Nextcloud's picker collects the field and hands it to the create event,
	 * which is what keeps a file from ever existing without its pad.
	 */
	public function testTheExternalTileAsksForThePadAddress(): void {
		$templates = $this->buildProvider(external: true)->getCustomTemplates(self::MIME);
		$fields = $templates[1]->jsonSerialize()['fields'] ?? [];

		// Template serialises its fields, so what the picker receives is the
		// plain array — the same thing it renders the input from.
		$this->assertCount(1, $fields);
		$this->assertSame(PadTemplateProvider::FIELD_PAD_URL, $fields[0]['index']);
		$this->assertSame('rich-text', $fields[0]['type']);
	}

	/**
	 * Nextcloud points a tile at /core/preview, which has nothing to render for
	 * a .pad — the picker would show its generic document icon.
	 */
	public function testTheSharedTemplatesCarryThePadIconToo(): void {
		$storage = $this->storage();
		$storage->method('globalTemplates')->willReturn([$this->markerFile('Meeting notes.pad')]);

		$templates = $this->providerWith($storage, true, true, false)->getCustomTemplates(self::MIME);

		$this->assertStringContainsString('etherpad-icon', (string)$templates[1]->jsonSerialize()['previewUrl']);
	}

	/** Only the type tile carries fields; the public one has nothing to ask. */
	public function testThePublicTileAsksForNothing(): void {
		$templates = $this->buildProvider()->getCustomTemplates(self::MIME);

		$this->assertSame([], $templates[0]->jsonSerialize()['fields'] ?? []);
	}

	/**
	 * A picker missing one tile is better than a picker that fails to open,
	 * so a marker that cannot be resolved is logged and skipped.
	 */
	public function testSkipsATileWhoseMarkerCannotBeResolved(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('publicMarker')->willThrowException(new \RuntimeException('appdata unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$provider = new PadTemplateProvider(
			$storage,
			$this->policy(true, true),
			$this->config(false),
			$this->l10n(),
			$this->urlGenerator(),
			$logger,
		);

		$this->assertSame([], $provider->getCustomTemplates(self::MIME));
	}

	/**
	 * An admin-uploaded template is offered alongside the tiles. Its content
	 * becomes the pad's and it carries its own access mode, so the provider
	 * decides neither.
	 */
	public function testOffersAdminUploadedTemplatesAlongsideTheTiles(): void {
		$storage = $this->storage();
		$storage->method('globalTemplates')->willReturn([$this->markerFile('Meeting notes.pad')]);

		$ids = array_map(
			static fn($t): string => (string)$t->jsonSerialize()['templateId'],
			$this->providerWith($storage, true, true, false)->getCustomTemplates(self::MIME),
		);

		$this->assertSame(['pad-public', 'global:Meeting notes.pad'], $ids);
	}

	/** The picker must open even when the shared templates cannot be read. */
	public function testKeepsTheTilesWhenTheSharedTemplatesCannotBeListed(): void {
		$storage = $this->storage();
		$storage->method('globalTemplates')->willThrowException(new \RuntimeException('appdata unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$provider = new PadTemplateProvider($storage, $this->policy(true, true), $this->config(false), $this->l10n(), $this->urlGenerator(), $logger);

		$this->assertCount(1, $provider->getCustomTemplates(self::MIME));
	}

	public function testResolvesEachTemplateIdToItsFile(): void {
		$publicMarker = $this->markerFile(PadTemplateStorage::PUBLIC_TILE_NAME);
		$externalMarker = $this->markerFile(PadTemplateStorage::EXTERNAL_TILE_NAME);
		$global = $this->markerFile('Meeting notes.pad');

		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('publicMarker')->willReturn($publicMarker);
		$storage->method('externalMarker')->willReturn($externalMarker);
		$storage->method('globalTemplate')->with('Meeting notes.pad')->willReturn($global);

		$provider = $this->providerWith($storage, true, true, true);

		$this->assertSame($publicMarker, $provider->getCustomTemplate('pad-public'));
		$this->assertSame($externalMarker, $provider->getCustomTemplate('pad-external'));
		$this->assertSame($global, $provider->getCustomTemplate('global:Meeting notes.pad'));
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
		$this->providerWith($storage, true, true, true)->getCustomTemplate($templateId);
	}

	private function buildProvider(bool $protected = true, bool $public = true, bool $external = false): PadTemplateProvider {
		return $this->providerWith($this->storage(), $protected, $public, $external);
	}

	private function providerWith(PadTemplateStorage $storage, bool $protected, bool $public, bool $external): PadTemplateProvider {
		return new PadTemplateProvider(
			$storage,
			$this->policy($protected, $public),
			$this->config($external),
			$this->l10n(),
			$this->urlGenerator(),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function storage(): PadTemplateStorage {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('publicMarker')->willReturn($this->markerFile(PadTemplateStorage::PUBLIC_TILE_NAME));
		$storage->method('externalMarker')->willReturn($this->markerFile(PadTemplateStorage::EXTERNAL_TILE_NAME));
		return $storage;
	}

	/** Nextcloud's Template serialises the file, so it has to answer for real. */
	private function markerFile(string $name): File {
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

	/** External pads are governed by allow_external_pads, not by a pad type. */
	private function config(bool $allowExternal): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn(string $app, string $key, string $default = ''): string
				=> $key === 'allow_external_pads' ? ($allowExternal ? 'yes' : 'no') : $default
		);
		return $config;
	}

	private function policy(bool $protected, bool $public): PadTypePolicy {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($protected, $public): string {
				if ($key === PadTypePolicy::SETTING_PROTECTED) {
					return $protected ? 'yes' : 'no';
				}
				if ($key === PadTypePolicy::SETTING_PUBLIC) {
					return $public ? 'yes' : 'no';
				}
				return $default;
			}
		);
		return new PadTypePolicy($config);
	}

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return $l10n;
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
}
