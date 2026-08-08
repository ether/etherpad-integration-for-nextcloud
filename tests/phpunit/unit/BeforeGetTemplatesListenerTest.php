<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\BeforeGetTemplatesListener;
use OCA\EtherpadNextcloud\Template\PadTemplateProvider;
use OCP\Files\File;
use OCP\Files\Template\BeforeGetTemplatesEvent;
use OCP\Files\Template\Template;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Other apps answer this event for every template in it, not only their own,
 * and overwrite the fields of each — which is why the tile has to claim its
 * field back here.
 */
class BeforeGetTemplatesListenerTest extends TestCase {
	public function testRestoresTheFieldOfTheExternalTile(): void {
		$template = new Template(PadTemplateProvider::class, PadTemplateProvider::EXTERNAL_TEMPLATE_ID, $this->file());
		$template->setFields([]);

		$this->buildListener()->handle(new BeforeGetTemplatesEvent([$template], true));

		$fields = $template->jsonSerialize()['fields'] ?? [];
		$this->assertCount(1, $fields);
		$this->assertSame(PadTemplateProvider::FIELD_PAD_URL, $fields[0]['index']);
	}

	/** Someone else's template is none of our business. */
	public function testLeavesEveryOtherTemplateAlone(): void {
		$template = new Template('OCA\\Richdocuments\\Template', 'some-other-template', $this->file());
		$template->setFields([]);

		$this->buildListener()->handle(new BeforeGetTemplatesEvent([$template], true));

		$this->assertSame([], $template->jsonSerialize()['fields'] ?? []);
	}

	/**
	 * Nextcloud keeps every provider's templates in one map keyed by the id, so
	 * an id alone says nothing about who owns a template. Ours carry the app id
	 * and would be hard to hit by accident, but the listener rewrites fields —
	 * doing that to another app's template would be our bug, not theirs, so it
	 * checks the provider as well.
	 */
	public function testLeavesAnotherProvidersTemplateOfTheSameIdAlone(): void {
		$template = new Template('OCA\\SomeOtherApp\\Template', PadTemplateProvider::EXTERNAL_TEMPLATE_ID, $this->file());
		$template->setFields([]);

		$this->buildListener()->handle(new BeforeGetTemplatesEvent([$template], true));

		$this->assertSame([], $template->jsonSerialize()['fields'] ?? []);
	}

	private function buildListener(): BeforeGetTemplatesListener {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new BeforeGetTemplatesListener($l10n);
	}

	private function file(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4711);
		$file->method('getName')->willReturn('Public pad from URL.pad');
		$file->method('getEtag')->willReturn('etag');
		$file->method('getMTime')->willReturn(1_700_000_000);
		$file->method('getMimeType')->willReturn('application/x-etherpad-nextcloud');
		$file->method('getSize')->willReturn(0);
		$file->method('getType')->willReturn('file');
		return $file;
	}
}
