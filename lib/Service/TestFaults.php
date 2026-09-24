<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\IConfig;

/**
 * The faults a debug instance can be told to inject, for the end-to-end
 * tests of the lifecycle: one at a time, set through the admin API
 * (AdminTestFaultService) and asked for where each would strike. Nothing is
 * injected unless Nextcloud runs in debug mode.
 */
class TestFaults {
	public const TRASH_READ_LOCK = 'trash_read_lock';
	public const TRASH_WRITE_LOCK = 'trash_write_lock';
	public const TRASH_WRITE_FAIL = 'trash_write_fail';
	public const RESTORE_READ_LOCK = 'restore_read_lock';
	public const RESTORE_WRITE_LOCK = 'restore_write_lock';
	public const RESTORE_WRITE_FAIL = 'restore_write_fail';

	public function __construct(
		private IConfig $config,
		private AppConfigService $appConfig,
	) {
	}

	/** @return list<string> */
	public static function supported(): array {
		return [
			self::TRASH_READ_LOCK,
			self::TRASH_WRITE_LOCK,
			self::TRASH_WRITE_FAIL,
			self::RESTORE_READ_LOCK,
			self::RESTORE_WRITE_LOCK,
			self::RESTORE_WRITE_FAIL,
		];
	}

	/**
	 * Whether faults can be injected here at all: the one rule, for the
	 * admin API that sets them and for every place that asks for one.
	 */
	public function onDebugInstance(): bool {
		return $this->config->getSystemValueBool('debug', false);
	}

	public function isActive(string $fault): bool {
		if (!$this->onDebugInstance()) {
			return false;
		}
		$active = $this->appConfig->getTestFault();
		return $active !== '' && hash_equals($active, $fault);
	}
}
