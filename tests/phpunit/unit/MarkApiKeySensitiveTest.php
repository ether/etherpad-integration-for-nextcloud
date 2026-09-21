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

	/**
	 * A repair step runs outside anything of ours that catches, so what it
	 * throws is serialized by Nextcloud with every frame - and the frame
	 * that failed is setValueString, which takes the key as a string.
	 * Registering a method would not reach it: the frame belongs to
	 * Nextcloud. Cutting the chain is what keeps the key out, so the cut
	 * is what the test holds on to.
	 */
	public function testAFailedWriteIsRethrownWithoutTheFrameThatHeldTheKey(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('GEHEIM-KEY-4711');
		$cause = new \RuntimeException('write failed for GEHEIM-KEY-4711');
		$appConfig->method('setValueString')->willThrowException($cause);

		try {
			(new MarkApiKeySensitive($appConfig))->run($this->createMock(IOutput::class));
			$this->fail('the failed write was swallowed');
		} catch (\RuntimeException $rethrown) {
			$this->assertNull($rethrown->getPrevious());
			$this->assertStringNotContainsString('GEHEIM-KEY-4711', $rethrown->getMessage());
			// Still worth reading: which failure it was, without its wording.
			$this->assertStringContainsString('RuntimeException', $rethrown->getMessage());
		}
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
