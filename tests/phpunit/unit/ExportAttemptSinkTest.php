<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Http\ExportAttemptSink;
use PHPUnit\Framework\TestCase;

class ExportAttemptSinkTest extends TestCase {
	/** Up to the limit the body is kept whole; a chunk past it is refused, which stops cURL, and kept out. */
	public function testKeepsTheBodyUpToTheLimit(): void {
		$sink = new ExportAttemptSink(6);

		$this->assertSame(3, $sink->chunk(null, 'abc'));
		$this->assertSame(3, $sink->chunk(null, 'def'));
		$this->assertFalse($sink->tooLarge());
		$this->assertSame(0, $sink->chunk(null, 'g'));

		$this->assertTrue($sink->tooLarge());
		$this->assertSame('abcdef', $sink->body());
	}

	/** The Content-Type in whatever case a server writes it, and no other header in its place; every line is taken whole. */
	public function testReadsTheContentType(): void {
		$sink = new ExportAttemptSink(6);

		foreach (["HTTP/1.1 200 OK\r\n", "content-type:  text/plain; charset=utf-8\r\n", "Server: example\r\n", "\r\n"] as $line) {
			$this->assertSame(strlen($line), $sink->header(null, $line));
		}

		$this->assertSame('text/plain; charset=utf-8', $sink->contentType());
	}

	/** An interim answer with a type of its own, then a final one without. */
	public function testTakesNoContentTypeFromAnInterimAnswer(): void {
		$sink = new ExportAttemptSink(6);

		foreach (["HTTP/1.1 103 Early Hints\r\n", "Content-Type: text/plain\r\n", "\r\n", "HTTP/1.1 200 OK\r\n", "Server: example\r\n", "\r\n"] as $line) {
			$sink->header(null, $line);
		}

		$this->assertSame('', $sink->contentType());
	}
}
