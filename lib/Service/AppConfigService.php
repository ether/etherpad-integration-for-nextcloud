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
	 * Whether a trash deletes the file's pad, once its content is in the
	 * file - now, or through the sweep. On unless the admin switched it off.
	 */
	public function isDeleteOnTrashEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'delete_on_trash', 'yes') === 'yes';
	}

	/** The last row the sweep's pass over every row reached; 0 to start over. */
	public function getGoneFileSweepCursor(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, 'gone_file_sweep_cursor'));
	}

	public function setGoneFileSweepCursor(int $rowId): void {
		$this->appConfig->setValueInt(Application::APP_ID, 'gone_file_sweep_cursor', $rowId);
	}

	/** The test fault a debug instance injects (TestFaults), or '' for none. */
	public function getTestFault(): string {
		return trim($this->config->getAppValue(Application::APP_ID, 'test_fault', ''));
	}

	/**
	 * @return list<string>
	 */
	public function getTrustedEmbedOrigins(): array {
		return $this->trustedEmbedOriginsNormalizer->parse($this->getTrustedEmbedOriginsRaw());
	}

}
