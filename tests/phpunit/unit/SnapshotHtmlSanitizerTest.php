<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class SnapshotHtmlSanitizerTest extends TestCase {
	public function testEmptyInputReturnsEmptyString(): void {
		$this->assertSame('', $this->sanitize(" \n "));
	}

	public function testPreservesBasicBlockAndInlineTags(): void {
		$this->assertSame(
			'<h1>Title</h1><p><strong>Bold</strong> <em>Italic</em> <u>Under</u></p>',
			$this->sanitize('<h1>Title</h1><p><strong>Bold</strong> <em>Italic</em> <u>Under</u></p>')
		);
	}

	public function testPreservesLists(): void {
		$this->assertSame(
			'<ol><li>One</li><li>Two<ul><li>Nested</li></ul></li></ol>',
			$this->sanitize('<ol start="1" class="number"><li>One</li><li>Two<ul><li>Nested</li></ul></li></ol>')
		);
	}

	public function testPreservesPreCodeAndBlockquote(): void {
		$this->assertSame(
			'<blockquote>Quote</blockquote><pre><code>$ echo test</code></pre>',
			$this->sanitize('<blockquote cite="x">Quote</blockquote><pre><code>$ echo test</code></pre>')
		);
	}

	public function testPreservesLineBreakAsVoidTag(): void {
		$this->assertSame('<p>A<br>B</p>', $this->sanitize('<p>A<br>B</p>'));
	}

	public function testDropsAllAttributes(): void {
		$this->assertSame(
			'<h1>Title</h1><p>Text</p>',
			$this->sanitize('<h1 style="color:red" onclick="alert(1)">Title</h1><p class="lead" data-x="1">Text</p>')
		);
	}

	public function testDropsUrlBearingAttributesFromFormattingTags(): void {
		$this->assertSame(
			'<p><strong>Bold</strong> <em>Italic</em></p>',
			$this->sanitize('<p><strong formaction="https://evil.test">Bold</strong> <em background="javascript:alert(1)">Italic</em></p>')
		);
	}

	public function testUnwrapsUnknownTagsButKeepsTextContent(): void {
		$this->assertSame(
			'<p>Before custom text after</p>',
			$this->sanitize('<p>Before <custom-element data-x="1">custom <span>text</span></custom-element> after</p>')
		);
	}

	public function testUnwrapsLinksButKeepsPlainText(): void {
		$this->assertSame(
			'<p>Read this link</p>',
			$this->sanitize('<p>Read <a href="javascript:alert(1)" onclick="alert(2)">this link</a></p>')
		);
	}

	public function testDropsScriptWithContent(): void {
		$this->assertSame('<p>Safe</p>', $this->sanitize('<p>Safe<script>alert(1)</script></p>'));
	}

	public function testDropsEmbeddedMediaWithContent(): void {
		$this->assertSame(
			'<p>Safe</p>',
			$this->sanitize('<p>Safe<iframe src="x">fallback</iframe><svg><text>svg text</text></svg><img src="x"></p>')
		);
	}

	public function testEscapesTextContent(): void {
		$this->assertSame(
			'<p>Tom &amp; Jerry &quot;quoted&quot;</p>',
			$this->sanitize('<p>Tom & Jerry "quoted"</p>')
		);
	}

	public function testEscapesEntityDecodedLessThanText(): void {
		$this->assertSame(
			'<p>1 &lt; 2</p>',
			$this->sanitize('<p>1 &lt; 2</p>')
		);
	}

	public function testMalformedHtmlIsParsedAndSanitized(): void {
		$this->assertSame(
			'<p><strong>Bold</strong></p>',
			$this->sanitize('<p><strong>Bold')
		);
	}

	public function testWholeDocumentInputUsesBodyChildren(): void {
		$this->assertSame(
			'<h2>Heading</h2><p>Body</p>',
			$this->sanitize('<!DOCTYPE HTML><html><body><h2>Heading</h2><p>Body</p></body></html>')
		);
	}

	public function testCommentsAndProcessingInstructionsAreDropped(): void {
		$this->assertSame('<p>Text</p>', $this->sanitize('<p>Text<!-- hidden --></p><?pi test?>'));
	}

	private function sanitize(string $html): string {
		return (new SnapshotHtmlSanitizer())->sanitize($html);
	}

	/**
	 * Etherpad's own HTML export autolinks URLs — `ExportHtml` emits
	 * `<a href=...>` — so dropping anchors turned every link in a pad into
	 * unclickable text.
	 */
	public function testKeepsLinksAndForcesASafeTarget(): void {
		$out = (new SnapshotHtmlSanitizer())->sanitize('<p>See <a href="https://example.test/a?b=1">this</a></p>');

		$this->assertSame(
			'<p>See <a href="https://example.test/a?b=1" target="_blank" rel="noopener noreferrer">this</a></p>',
			$out
		);
	}

	public function testKeepsMailtoLinks(): void {
		$out = (new SnapshotHtmlSanitizer())->sanitize('<a href="mailto:a@example.test">write</a>');

		$this->assertStringContainsString('href="mailto:a@example.test"', $out);
	}

	/**
	 * An allowlist, not a `javascript:` denylist. The pad server is not
	 * this app's, and the href travels from a document anyone with write
	 * access to the pad can shape.
	 *
	 * @param string $href
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unsafeHrefs')]
	public function testDropsTheLinkButKeepsItsTextForAnUnsafeTarget(string $href): void {
		$out = (new SnapshotHtmlSanitizer())->sanitize('<p><a href="' . $href . '">click</a></p>');

		$this->assertSame('<p>click</p>', $out, 'the text stays, the link does not');
		$this->assertStringNotContainsString('href', $out);
	}

	/** @return array<string,array{0:string}> */
	public static function unsafeHrefs(): array {
		return [
			'javascript' => ['javascript:alert(1)'],
			'javascript in mixed case' => ['JaVaScRiPt:alert(1)'],
			// Browsers ignore control characters while reading a scheme, so
			// this is `javascript:` to them and must be to us as well.
			'javascript split by a tab' => ["java	script:alert(1)"],
			'javascript with a leading newline' => ["\njavascript:alert(1)"],
			'data uri' => ['data:text/html,<script>alert(1)</script>'],
			'vbscript' => ['vbscript:msgbox(1)'],
			'relative path' => ['/settings/admin'],
			'protocol relative' => ['//evil.test/'],
			'empty' => [''],
		];
	}

	/**
	 * The scheme is read past control characters, but the address itself is
	 * not rewritten. Stripping them from the whole href turned
	 * `/files/Meeting notes.pdf` into `/files/Meetingnotes.pdf` — a
	 * different file, or none.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('hrefsThatMustSurviveIntact')]
	public function testKeepsTheAddressExactlyAsGiven(string $href): void {
		$out = (new SnapshotHtmlSanitizer())->sanitize('<a href="' . $href . '">x</a>');

		$this->assertStringContainsString('href="' . $href . '"', $out);
	}

	/** @return array<string,array{0:string}> */
	public static function hrefsThatMustSurviveIntact(): array {
		return [
			'a space in the path' => ['https://example.org/files/Meeting notes.pdf'],
			'a space in a query' => ['mailto:a@example.test?subject=Team meeting'],
			'an encoded space' => ['https://example.org/files/Meeting%20notes.pdf'],
			'a query and a fragment' => ['https://example.org/a?b=1&amp;c=2#d'],
		];
	}
}
