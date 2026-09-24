<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Trashed .pad files, for the trash and for the sweep that finishes it: the
 * file, and a formatter that writes a fresh snapshot into it.
 */
trait TrashedPadFiles {
	/** The snapshot revision the formatter reads a trashed file at; trashedFile() sets it. */
	private int $trashedSnapshotRev = -1;

	/** A formatter that reads 'doc-before' and writes a fresh snapshot into it as 'doc-after'. */
	private function buildSnapshotWritingPadFileService(): PadFileService&MockObject {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturnCallback(fn (): ParsedPadFile => new ParsedPadFile(
			frontmatter: [],
			body: 'body',
			padId: 'pad',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: $this->trashedSnapshotRev,
		));
		$padFileService->method('withExportSnapshot')->willReturn('doc-after');
		return $padFileService;
	}

	/** A .pad in its owner's trash, holding 'doc-before'; the formatter reads it at $snapshotRev. */
	private function trashedFile(int $fileId, int $snapshotRev = -1): File&MockObject {
		$this->trashedSnapshotRev = $snapshotRev;
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Trashed.pad.d100');
		$file->method('getContent')->willReturn('doc-before');
		return $file;
	}
}
