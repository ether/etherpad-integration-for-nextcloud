<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PublicShareRedirectController;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\PublicShareController;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PublicShareRedirectControllerTest extends TestCase {
	public function testItHandsOnToTheSharePageWithTheFileNamed(): void {
		$this->assertSame('/s/share-token?path=%2FSub&files=A.pad', $this->buildController('/Sub/A.pad')->showPad('share-token')->getRedirectURL());
		$this->assertSame('/s/share-token?dir=%2F', $this->buildController('')->showPad('share-token')->getRedirectURL());
	}

	/**
	 * Nextcloud checks the share of a PublicShareController before the
	 * method runs, and answers 404 for a share with a password nobody has
	 * entered in this session yet. The share page the redirect goes to asks
	 * for it, and checks the token too.
	 */
	public function testItLeavesTheShareAndItsPasswordToTheSharePage(): void {
		$this->assertFalse(is_subclass_of(PublicShareRedirectController::class, PublicShareController::class));
		$attributes = array_map(
			static fn (\ReflectionAttribute $attribute): string => $attribute->getName(),
			(new \ReflectionMethod(PublicShareRedirectController::class, 'showPad'))->getAttributes(),
		);
		$this->assertContains(\OCP\AppFramework\Http\Attribute\PublicPage::class, $attributes);
		// A followed link carries no request token.
		$this->assertContains(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class, $attributes);
	}

	/**
	 * Nothing here looks at the share, so it cannot tell a file the link
	 * names wrongly from a link that is dead. The share page can: a file it
	 * cannot name leads to the share's root, and a dead token ends there on
	 * "Share not found".
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('aFileTheLinkCannotName')]
	public function testAFileTheLinkCannotNameLeadsToTheSharesRoot(string $file): void {
		$this->assertSame('/s/share-token', $this->buildController($file)->showPad('share-token')->getRedirectURL());
	}

	/** @return array<string, array{string}> */
	public static function aFileTheLinkCannotName(): array {
		return [
			'no .pad' => ['/Notes.txt'],
			'out of the share' => ['/../secret.pad'],
		];
	}

	private function buildController(string $file): PublicShareRedirectController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'file' ? $file : $default
		);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getWebroot')->willReturn('');
		return new PublicShareRedirectController('etherpad_nextcloud', $request, new PublicShareUrlBuilder($urlGenerator, new PathNormalizer()));
	}
}
