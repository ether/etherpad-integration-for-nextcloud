<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\LifecycleResult;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\RestoreService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class LifecycleServiceTest extends TestCase {
	/**
	 * A restore, and a recovery the API asked for by file id, come back as
	 * the restore answered them - the recovery after the file id - restored,
	 * or skipped with its reason.
	 */
	public function testTheWaysInHandOutWhatTheRestoreAnswers(): void {
		$cases = [
			'restored' => [LifecycleResult::restored('pad-old', 'pad-new'), LifecycleResult::restored('pad-old', 'pad-recovered')],
			'skipped' => [['status' => LifecycleResult::SKIPPED, 'reason' => 'not_pad_file'], ['status' => LifecycleResult::SKIPPED, 'reason' => 'external_pad']],
		];
		foreach ($cases as $case => [$restored, $recovered]) {
			$file = $this->createMock(File::class);
			$restores = $this->createMock(RestoreService::class);
			$restores->method('restore')->with($file)->willReturn($restored);
			$restores->method('recoverFromSnapshot')->with($file)->willReturn($recovered);
			$nodes = $this->createMock(UserNodeResolver::class);
			$nodes->method('resolveUserFileNodeById')->with('alice', 42)->willReturn($file);
			$service = new LifecycleService($nodes, $restores);

			$this->assertSame($restored, $service->handleRestore($file), $case);
			$this->assertSame(['file_id' => 42, ...$recovered], $service->recoverByFileId('alice', 42), $case);
		}
	}
}
