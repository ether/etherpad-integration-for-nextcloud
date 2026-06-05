<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Migration\RegisterMimeType;
use PHPUnit\Framework\TestCase;

class RegisterMimeTypeTest extends TestCase {
	private const MIME = 'application/x-etherpad-nextcloud';

	public function testMergeAddsPadMappingWithoutClobberingExistingEntries(): void {
		$current = [
			'txt' => ['text/plain'],
			'md' => ['text/markdown'],
		];
		$new = ['pad' => [self::MIME]];

		$merged = RegisterMimeType::mergeMimeMappings($current, $new);

		// Existing entries are preserved …
		$this->assertSame(['text/plain'], $merged['txt']);
		$this->assertSame(['text/markdown'], $merged['md']);
		// … and the pad mapping is added.
		$this->assertSame([self::MIME], $merged['pad']);
	}

	public function testMergeIsIdempotent(): void {
		$current = ['txt' => ['text/plain']];
		$new = ['pad' => [self::MIME]];

		$once = RegisterMimeType::mergeMimeMappings($current, $new);
		$twice = RegisterMimeType::mergeMimeMappings($once, $new);

		$this->assertSame($once, $twice);
		$this->assertSame([self::MIME], $twice['pad']);
	}

	public function testMergeOverridesAStalePadMapping(): void {
		// A previously-wrong value for our own key is corrected, not duplicated.
		$current = ['pad' => ['application/octet-stream']];
		$new = ['pad' => [self::MIME]];

		$merged = RegisterMimeType::mergeMimeMappings($current, $new);

		$this->assertSame([self::MIME], $merged['pad']);
	}
}
