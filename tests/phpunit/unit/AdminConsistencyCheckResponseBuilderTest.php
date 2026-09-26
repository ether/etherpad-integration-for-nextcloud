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
		$this->assertSame(0, $response['binding_without_file_count']);
	}

	public function testBuildsIssueMessage(): void {
		$response = $this->buildBuilder()->build($this->consistencyResult([
			'binding_without_file_count' => 2,
		]));

		$this->assertSame('Consistency check finished with issues.', $response['message']);
		$this->assertSame(2, $response['binding_without_file_count']);
	}

	/** The brake is what an admin has to act on, so it is what the message names. */
	public function testNamesTheBrakeWhenItHolds(): void {
		$response = $this->buildBuilder()->build($this->consistencyResult([
			'binding_without_file_count' => 30,
			'missing_file_count' => 25,
			'gone_file_brake_engaged' => true,
		]));

		$this->assertSame('Many .pad files went missing at once without passing a trash. Their pads are kept until the brake is released.', $response['message']);
		$this->assertSame(25, $response['missing_file_count']);
		$this->assertTrue($response['gone_file_brake_engaged']);
	}

	/** @param array<string,mixed> $overrides */
	private function consistencyResult(array $overrides = []): array {
		return $overrides + [
			'binding_without_file_count' => 0,
			'missing_file_count' => 0,
			'gone_file_brake_engaged' => false,
			'samples' => ['bindings_without_file' => []],
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
