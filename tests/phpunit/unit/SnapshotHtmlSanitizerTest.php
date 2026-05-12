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
}
