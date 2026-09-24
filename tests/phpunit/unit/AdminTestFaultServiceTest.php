<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminDebugModeRequiredException;
use OCA\EtherpadNextcloud\Exception\UnsupportedTestFaultException;
use OCA\EtherpadNextcloud\Service\AdminTestFaultService;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\TestFaults;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AdminTestFaultServiceTest extends TestCase {
	public function testSetFaultRequiresDebugMode(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn(false);
		$config->expects($this->never())->method('setAppValue');

		$this->expectException(AdminDebugModeRequiredException::class);

		$this->service($config)->setFault('trash_read_lock');
	}

	public function testSetFaultRejectsUnsupportedFault(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn(true);
		$config->expects($this->never())->method('setAppValue');

		try {
			$this->service($config)->setFault('unknown_fault');
			$this->fail('Expected unsupported test fault exception.');
		} catch (UnsupportedTestFaultException $e) {
			$this->assertContains('trash_read_lock', $e->getSupportedFaults());
		}
	}

	public function testSetFaultPersistsSupportedFault(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn(true);
		$config->expects($this->once())
			->method('setAppValue')
			->with('etherpad_nextcloud', 'test_fault', 'trash_read_lock');

		$result = $this->service($config)->setFault('trash_read_lock');

		$this->assertSame('trash_read_lock', $result);
	}

	public function testSetFaultAllowsClearingFault(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn(true);
		$config->expects($this->once())
			->method('setAppValue')
			->with('etherpad_nextcloud', 'test_fault', '');

		$result = $this->service($config)->setFault('');

		$this->assertSame('', $result);
	}

	/** Debug mode is TestFaults' to tell, as for every fault it is asked about. */
	private function service(IConfig $config): AdminTestFaultService {
		return new AdminTestFaultService($config, new TestFaults($config, $this->createMock(AppConfigService::class)));
	}
}
