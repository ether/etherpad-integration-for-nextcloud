<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Migration\MarkApiKeySensitive;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class MarkApiKeySensitiveTest extends TestCase {
	public function testReStoresExistingKeyAsSensitive(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('stored-key');

		$written = null;
		$appConfig->expects($this->once())
			->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value, bool $lazy, bool $sensitive) use (&$written): bool {
					$written = compact('app', 'key', 'value', 'lazy', 'sensitive');
					return true;
				}
			);

		(new MarkApiKeySensitive($appConfig))->run($this->createMock(IOutput::class));

		$this->assertSame('etherpad_nextcloud', $written['app']);
		$this->assertSame('etherpad_api_key', $written['key']);
		$this->assertSame('stored-key', $written['value']);
		$this->assertTrue($written['sensitive']);
	}

	public function testDoesNothingWhenNoKeyStored(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->expects($this->never())->method('setValueString');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		(new MarkApiKeySensitive($appConfig))->run($output);
	}
}
