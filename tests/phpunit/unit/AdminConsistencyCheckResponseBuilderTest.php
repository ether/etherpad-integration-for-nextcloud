<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AdminConsistencyCheckResponseBuilder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AdminConsistencyCheckResponseBuilderTest extends TestCase {
	public function testBuildsSuccessfulResponse(): void {
		$response = $this->buildBuilder()->build($this->consistencyResult());

		$this->assertTrue((bool)$response['ok']);
		$this->assertSame('Consistency check successful. No issues found.', $response['message']);
		$this->assertSame(0, $response['vanished_file_count']);
		$this->assertArrayNotHasKey('binding_without_file_count', $response);
	}

	/** Only a vanished file is an issue: one seen deleted for good is on its way. */
	public function testBuildsIssueMessage(): void {
		$response = $this->buildBuilder()->build($this->consistencyResult([
			'vanished_file_count' => 2,
		]));

		$this->assertSame('Consistency check finished with issues.', $response['message']);
		$this->assertSame(2, $response['vanished_file_count']);
	}

	/** @param array<string,mixed> $overrides */
	private function consistencyResult(array $overrides = []): array {
		return $overrides + [
			'vanished_file_count' => 0,
			'samples' => ['vanished_files' => []],
		];
	}

	private function buildBuilder(): AdminConsistencyCheckResponseBuilder {
		return new AdminConsistencyCheckResponseBuilder($this->buildL10n());
	}

	private function buildL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				foreach ($parameters as $key => $value) {
					$text = str_replace('{' . $key . '}', (string)$value, $text);
				}
				return $text;
			}
		);
		return $l10n;
	}
}
