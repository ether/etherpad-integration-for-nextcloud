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
	/** The version of RegisterMimeType's repair of the .pad files that last finished in a run that found the mapping in place. */
	public const PAD_FILES_MIME_VERSION = 'pad_files_mime_version';
	/** When GoneFileSweep last warned of a pad waiting on Etherpad. */
	public const SWEEP_WARNED_AT = 'sweep_warned_at';

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

	public function getExternalPadAllowlistRaw(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'external_pad_allowlist', '');
	}

	/** As detected for the configured API host, '' before a save. */
	public function getStoredEtherpadApiVersion(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'etherpad_api_version', '');
	}

	/**
	 * Whether the pad of a file deleted for good is deleted (GoneFileSweep).
	 * On unless the admin switched it off. Read and written as a string
	 * through IAppConfig alone, so the value keeps one type
	 * (AdminSettingsRepository writes it the same way).
	 *
	 * Until the setting is taken over (takeOverDeleteOnTrash()), the one a
	 * version whose trash deleted pads kept stands: code of this version
	 * running before its migration - deployed without a version bump, say -
	 * keeps an admin's opt-out.
	 */
	public function isDeletePadWithFileEnabled(): bool {
		$value = $this->appConfig->getValueString(Application::APP_ID, self::DELETE_PAD_WITH_FILE, '');
		if ($value === '') {
			$value = $this->appConfig->getValueString(Application::APP_ID, 'delete_on_trash', 'yes');
		}
		return $value === 'yes';
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

	/** 0 where no version is recorded. */
	public function padFilesMimeVersion(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::PAD_FILES_MIME_VERSION, 0, true);
	}

	/** Lazy: only that repair reads it, not every request. */
	public function recordPadFilesMimeVersion(int $version): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::PAD_FILES_MIME_VERSION, $version, true);
	}

	public function forgetPadFilesMimeVersion(): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::PAD_FILES_MIME_VERSION);
	}

	/** 0 where the sweep never warned. */
	public function sweepWarnedAt(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::SWEEP_WARNED_AT, 0, true);
	}

	/** Lazy: only the sweep reads it, not every request. */
	public function recordSweepWarnedAt(int $time): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::SWEEP_WARNED_AT, $time, true);
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
