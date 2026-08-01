<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Settings;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\CookieDomainDecision;
use OCA\EtherpadNextcloud\Service\CookieDomainMessages;
use OCA\EtherpadNextcloud\Service\CookieDomainPolicy;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * @psalm-api
 */
class AdminSettings implements ISettings {
	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private AppConfigService $appConfigService,
		private AdminSettingsRepository $settingsRepository,
		private CookieDomainPolicy $cookieDomainPolicy,
		private CookieDomainMessages $cookieDomainMessages,
		private PadTypePolicy $padTypePolicy,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'admin-settings');
		Util::addScript(Application::APP_ID, 'etherpad_nextcloud-admin-settings');

		$etherpadHost = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_host', '');
		$etherpadApiHost = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_host', '');
		$cookieDomainConfigured = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_cookie_domain_configured', 'no') === 'yes';
		$storedCookieDomain = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_cookie_domain', '');
		$configuredCookieDomain = $this->cookieDomainPolicy->storedValue($storedCookieDomain, $cookieDomainConfigured);
		$decision = $this->cookieDomainPolicy->decide($this->urlGenerator->getBaseUrl(), $etherpadHost, $configuredCookieDomain);
		// The field shows the saved value, falling back to the derived one when
		// nothing was saved. That is not always what gets sent: identical hosts
		// use a host-only cookie and ignore any saved domain.
		$cookieDomain = $configuredCookieDomain ?? $decision->effectiveDomain;
		$apiVersion = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_version', EtherpadClient::DEFAULT_API_VERSION);
		$syncInterval = (int)$this->config->getAppValue(Application::APP_ID, 'sync_interval_seconds', '120');
		if ($syncInterval < 5) {
			$syncInterval = 5;
		}
		if ($syncInterval > 3600) {
			$syncInterval = 3600;
		}

		// Only relevant when protected pads are actually offered — an instance
		// that only serves public pads needs no session cookie at all.
		$protectedPadsEnabled = $this->padTypePolicy->isEnabled(BindingService::ACCESS_PROTECTED);
		$cookieWarning = ($protectedPadsEnabled && $decision->status === CookieDomainDecision::STATUS_WARNING)
			? $this->cookieDomainMessages->describe($decision)
			: '';

		return new TemplateResponse(Application::APP_ID, 'admin-settings', [
			'cookie_domain_warning' => $cookieWarning,
			'etherpad_host' => $etherpadHost,
			'etherpad_api_host' => $etherpadApiHost,
			'etherpad_cookie_domain' => $cookieDomain,
			'etherpad_api_version' => $apiVersion,
			'sync_interval_seconds' => $syncInterval,
			'enable_protected_pads' => (string)$this->config->getAppValue(Application::APP_ID, PadTypePolicy::SETTING_PROTECTED, 'yes') === 'yes',
			'enable_public_pads' => (string)$this->config->getAppValue(Application::APP_ID, PadTypePolicy::SETTING_PUBLIC, 'yes') === 'yes',
			'delete_on_trash' => (string)$this->config->getAppValue(Application::APP_ID, 'delete_on_trash', 'yes') === 'yes',
			'allow_external_pads' => (string)$this->config->getAppValue(Application::APP_ID, 'allow_external_pads', 'no') === 'yes',
			'external_pad_allowlist' => (string)$this->config->getAppValue(Application::APP_ID, 'external_pad_allowlist', ''),
			'trusted_embed_origins' => $this->appConfigService->getTrustedEmbedOriginsRaw(),
			'has_api_key' => $this->settingsRepository->hasApiKey(),
			'save_settings_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.saveSettings'),
			'health_check_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.healthCheck'),
			'consistency_check_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.consistencyCheck'),
			'retry_pending_deletes_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.retryPendingDeletes'),
			'l10n' => [
				'section_title' => $this->l10n->t('Pads'),
				'intro' => $this->l10n->t('Configure the Etherpad server and external pad security policy for the Etherpad Nextcloud app.'),
				'section_server' => $this->l10n->t('Etherpad server'),
				'section_pad_types' => $this->l10n->t('Pad types and behaviour'),
				'section_external' => $this->l10n->t('External pads and embedding'),
				'section_diagnostics' => $this->l10n->t('Diagnostics'),
				'section_connection_hint' => $this->l10n->t('Checks that Nextcloud reaches the Etherpad API with the values above, saved or not.'),
				'section_consistency_hint' => $this->l10n->t('Looks for pad links whose .pad file no longer exists.'),
				'etherpad_base_url' => $this->l10n->t('Etherpad Base URL'),
				'etherpad_api_url' => $this->l10n->t('Etherpad API URL (optional)'),
				'etherpad_api_url_hint' => $this->l10n->t('Optional internal URL for server-side API calls. Leave empty to use Etherpad Base URL.'),
				'etherpad_cookie_domain' => $this->l10n->t('Etherpad session cookie domain'),
				'etherpad_cookie_domain_hint' => $this->l10n->t('Required for protected pads unless Nextcloud and Etherpad run on the same host: the session cookie must be valid for both. Auto-filled from the two hosts when they share a parent domain. Leave empty for a host-only cookie.'),
				'etherpad_api_key' => $this->l10n->t('Etherpad API key'),
				'detected_api_version' => $this->l10n->t('Detected API version:'),
				'copy_interval' => $this->l10n->t('.pad file sync interval (seconds)'),
				'copy_interval_hint' => $this->l10n->t('Controls how often pad content is copied from Etherpad into the .pad file while the pad is open.'),
				'enable_protected_pads' => $this->l10n->t('Protected pads'),
				'enable_protected_pads_hint' => $this->l10n->t('Only people who can open the .pad file in Nextcloud can open the pad. Created as Etherpad group pads, which require a session issued by Nextcloud.'),
				'enable_public_pads' => $this->l10n->t('Public pads'),
				'enable_public_pads_hint' => $this->l10n->t('Anyone with the pad link can open it, without a Nextcloud account. Created as ordinary Etherpad pads.'),
				'pad_types_none_hint' => $this->l10n->t('With both types switched off, no new pads can be created. Existing pads keep working.'),
				'delete_on_trash' => $this->l10n->t('Delete linked Etherpad pad when .pad file is moved to trash'),
				'delete_on_trash_hint' => $this->l10n->t('If enabled, moving a .pad file to the trash also deletes the linked Etherpad pad.'),
				'allow_external_pads' => $this->l10n->t('Allow linking external public pads'),
				'external_allowlist' => $this->l10n->t('External Etherpad host allowlist (optional)'),
				'external_allowlist_hint' => $this->l10n->t('Add trusted Etherpad hostnames or HTTPS origins. Leave empty only if all public HTTPS hosts should be trusted.'),
				'trusted_embed_origins' => $this->l10n->t('Trusted embed origins (optional)'),
				'trusted_embed_origins_hint' => $this->l10n->t('Absolute https origins allowed to embed the /embed/by-id and /embed/create-by-parent routes. Leave empty to disable external embedding.'),
				'save_button' => $this->l10n->t('Save settings'),
				'health_button' => $this->l10n->t('Test Etherpad connection'),
				'consistency_button' => $this->l10n->t('Consistency check'),
				'retry_pending_button' => $this->l10n->t('Retry pending deletes now'),
				'pending_delete_label' => $this->l10n->t('Pending Etherpad deletes'),
				'saving' => $this->l10n->t('Saving settings...'),
				'saved' => $this->l10n->t('Settings saved.'),
				'checking' => $this->l10n->t('Testing Etherpad connection...'),
				'consistency_running' => $this->l10n->t('Running consistency check...'),
				'health_ok' => $this->l10n->t('Etherpad connection test successful.'),
				'consistency_ok' => $this->l10n->t('Consistency check successful.'),
				'request_failed' => $this->l10n->t('Request failed.'),
				'saving_failed' => $this->l10n->t('Failed to save settings.'),
				'health_failed' => $this->l10n->t('Etherpad connection test failed.'),
				'consistency_failed' => $this->l10n->t('Consistency check failed.'),
				'retry_failed' => $this->l10n->t('Pending delete retry failed.'),
			],
		]);
	}

	public function getSection(): string {
		return 'etherpad_nextcloud_pads';
	}

	public function getPriority(): int {
		return 10;
	}
}
