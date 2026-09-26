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

	/**
	 * How long a pad lives on after its file went missing without being
	 * seen leaving Files (GoneFileSweep): seven days, unless set otherwise.
	 */
	public function getGoneFileGraceSeconds(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, 'gone_file_grace_seconds', 7 * 24 * 60 * 60));
	}

	/** More files missed without a mark since the brake's last release than this stops the grace deletions. */
	public function getGoneFileBrakeThreshold(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, 'gone_file_brake_threshold', 20));
	}

	/** When an admin last released the brake; files missed before then count no more. */
	public function getGoneFileBrakeReleasedAt(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'gone_file_brake_released_at');
	}

	/** Whether the last sweep found the brake on: so it warns once, and the health check can say so. */
	public function isGoneFileBrakeEngaged(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'gone_file_brake_engaged');
	}

	public function setGoneFileBrakeEngaged(bool $engaged): void {
		$this->appConfig->setValueBool(Application::APP_ID, 'gone_file_brake_engaged', $engaged);
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
