<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

class AppConfigService {
	/** Whether a file deleted for good takes its pad along; `delete_on_trash` before. */
	public const DELETE_PAD_WITH_FILE = 'delete_pad_with_file';

	public function __construct(
		private IConfig $config,
		private TrustedEmbedOriginsNormalizer $trustedEmbedOriginsNormalizer,
		private IAppConfig $appConfig,
	) {
	}

	public function getSyncIntervalSeconds(): int {
		$raw = (int)$this->config->getAppValue(Application::APP_ID, 'sync_interval_seconds', '120');
		if ($raw < 5) {
			return 5;
		}
		if ($raw > 3600) {
			return 3600;
		}
		return $raw;
	}

	public function getTrustedEmbedOriginsRaw(): string {
		return (string)$this->config->getAppValue(Application::APP_ID, 'trusted_embed_origins', '');
	}

	/**
	 * Whether the pad of a file deleted for good is deleted (GoneFileSweep).
	 * On unless the admin switched it off. Read and written as a string
	 * through IAppConfig alone, so the value keeps one type
	 * (AdminSettingsRepository writes it the same way).
	 */
	public function isDeletePadWithFileEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::DELETE_PAD_WITH_FILE, 'yes') === 'yes';
	}

	/**
	 * The setting as a version whose trash deleted pads kept it, under
	 * `delete_on_trash`, taken over once and removed: switched off there,
	 * it is off here, unless the new one is set already.
	 */
	public function takeOverDeleteOnTrash(): void {
		$old = $this->appConfig->getValueString(Application::APP_ID, 'delete_on_trash', '');
		if ($old === '') {
			return;
		}
		if ($this->appConfig->getValueString(Application::APP_ID, self::DELETE_PAD_WITH_FILE, '') === '') {
			$this->appConfig->setValueString(Application::APP_ID, self::DELETE_PAD_WITH_FILE, $old);
		}
		$this->appConfig->deleteKey(Application::APP_ID, 'delete_on_trash');
	}

	/**
	 * The test fault 1.1.0-beta.1 let a debug instance set, gone with the
	 * faults themselves.
	 */
	public function dropTestFault(): void {
		$this->appConfig->deleteKey(Application::APP_ID, 'test_fault');
	}

	/**
	 * @return list<string>
	 */
	public function getTrustedEmbedOrigins(): array {
		return $this->trustedEmbedOriginsNormalizer->parse($this->getTrustedEmbedOriginsRaw());
	}

}
