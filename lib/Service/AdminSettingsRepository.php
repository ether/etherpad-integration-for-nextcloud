<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

class AdminSettingsRepository {
	public const API_KEY = 'etherpad_api_key';

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
	) {
	}

	public function getStoredSettings(): StoredAdminSettings {
		return new StoredAdminSettings(
			trim($this->appConfig->getValueString(Application::APP_ID, self::API_KEY, '')),
			(string)$this->config->getAppValue(Application::APP_ID, 'etherpad_cookie_domain', ''),
			(string)$this->config->getAppValue(Application::APP_ID, 'delete_on_trash', 'yes') === 'yes',
			(string)$this->config->getAppValue(Application::APP_ID, 'allow_external_pads', 'no') === 'yes',
			(string)$this->config->getAppValue(Application::APP_ID, 'trusted_embed_origins', ''),
		);
	}

	public function persist(ValidatedAdminSettings $settings): void {
		$this->config->setAppValue(Application::APP_ID, 'etherpad_host', $settings->etherpadHost);
		$this->config->setAppValue(Application::APP_ID, 'etherpad_api_host', $settings->etherpadApiHost);
		$this->config->setAppValue(Application::APP_ID, 'etherpad_cookie_domain', $settings->etherpadCookieDomain);
		$this->config->setAppValue(Application::APP_ID, 'etherpad_cookie_domain_configured', 'yes');
		if ($settings->etherpadApiKey !== null) {
			// Store the API key with the sensitive flag so it is redacted in
			// `occ config:list` and support dumps. IAppConfig and IConfig
			// share the same underlying app-config storage, so the other
			// reads/writes here are unaffected.
			$this->appConfig->setValueString(Application::APP_ID, self::API_KEY, $settings->etherpadApiKey, sensitive: true);
		}
		$this->config->setAppValue(Application::APP_ID, 'etherpad_api_version', $settings->etherpadApiVersion);
		$this->config->setAppValue(Application::APP_ID, 'sync_interval_seconds', (string)$settings->syncIntervalSeconds);
		$this->config->setAppValue(Application::APP_ID, 'delete_on_trash', $settings->deleteOnTrash ? 'yes' : 'no');
		$this->config->setAppValue(Application::APP_ID, 'allow_external_pads', $settings->allowExternalPads ? 'yes' : 'no');
		$this->config->setAppValue(Application::APP_ID, 'external_pad_allowlist', $settings->externalPadAllowlist);
		$this->config->setAppValue(Application::APP_ID, 'trusted_embed_origins', $settings->trustedEmbedOrigins);
	}

	public function hasApiKey(): bool {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::API_KEY, '')) !== '';
	}
}
