<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Settings;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private AppConfigService $appConfigService,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'admin-settings');
		Util::addScript(Application::APP_ID, 'etherpad_nextcloud-admin-settings');

		$etherpadHost = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_host', '');
		$etherpadApiHost = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_host', '');
		$cookieDomainConfigured = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_cookie_domain_configured', 'no') === 'yes';
		$storedCookieDomain = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_cookie_domain', '');
		$cookieDomain = $cookieDomainConfigured
			? $storedCookieDomain
			: $this->deriveCookieDomainFromKnownHosts($this->urlGenerator->getBaseUrl(), $etherpadHost);
		$apiVersion = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_version', EtherpadClient::DEFAULT_API_VERSION);
		$syncInterval = (int)$this->config->getAppValue(Application::APP_ID, 'sync_interval_seconds', '120');
		if ($syncInterval < 5) {
			$syncInterval = 5;
		}
		if ($syncInterval > 3600) {
			$syncInterval = 3600;
		}

		return new TemplateResponse(Application::APP_ID, 'admin-settings', [
			'etherpad_host' => $etherpadHost,
			'etherpad_api_host' => $etherpadApiHost,
			'etherpad_cookie_domain' => $cookieDomain,
			'etherpad_api_version' => $apiVersion,
			'sync_interval_seconds' => $syncInterval,
			'delete_on_trash' => (string)$this->config->getAppValue(Application::APP_ID, 'delete_on_trash', 'yes') === 'yes',
			'allow_external_pads' => (string)$this->config->getAppValue(Application::APP_ID, 'allow_external_pads', 'no') === 'yes',
			'external_pad_allowlist' => (string)$this->config->getAppValue(Application::APP_ID, 'external_pad_allowlist', ''),
			'trusted_embed_origins' => $this->appConfigService->getTrustedEmbedOriginsRaw(),
			'has_api_key' => (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_key', '') !== '',
			'save_settings_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.saveSettings'),
			'health_check_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.healthCheck'),
			'consistency_check_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.consistencyCheck'),
			'retry_pending_deletes_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.retryPendingDeletes'),
			'l10n' => [
				'section_title' => $this->l10n->t('Pads'),
				'intro' => $this->l10n->t('Configure the Etherpad server and external pad security policy for the Etherpad Nextcloud app.'),
				'etherpad_base_url' => $this->l10n->t('Etherpad Base URL'),
				'etherpad_api_url' => $this->l10n->t('Etherpad API URL (optional)'),
				'etherpad_api_url_hint' => $this->l10n->t('Optional internal URL for server-side API calls. Leave empty to use Etherpad Base URL.'),
				'etherpad_cookie_domain' => $this->l10n->t('Etherpad session cookie domain (optional)'),
				'etherpad_cookie_domain_hint' => $this->l10n->t('Auto-filled from the Nextcloud and Etherpad hosts when possible. Adjust it if your deployment uses a proxy path or a different trusted parent domain; leave empty for a host-only cookie.'),
				'etherpad_api_key' => $this->l10n->t('Etherpad API key'),
				'detected_api_version' => $this->l10n->t('Detected API version:'),
				'copy_interval' => $this->l10n->t('.pad file sync interval (seconds)'),
				'copy_interval_hint' => $this->l10n->t('Controls how often pad content is copied from Etherpad into the .pad file while the pad is open.'),
				'delete_on_trash' => $this->l10n->t('Delete linked Etherpad pad when .pad file is moved to trash'),
				'delete_on_trash_hint' => $this->l10n->t('If enabled, moving a .pad file to the trash also deletes the linked Etherpad pad.'),
				'allow_external_pads' => $this->l10n->t('Allow linking external public pads'),
				'external_allowlist' => $this->l10n->t('External host allowlist (optional)'),
				'external_allowlist_hint' => $this->l10n->t('Add trusted Etherpad hostnames or HTTPS origins. Leave empty only if all public HTTPS hosts should be trusted.'),
				'trusted_embed_origins' => $this->l10n->t('Trusted embed origins (optional)'),
				'trusted_embed_origins_hint' => $this->l10n->t('Absolute https origins allowed to embed the /embed/by-id and /embed/create-by-parent routes. Leave empty to disable external embedding.'),
				'save_button' => $this->l10n->t('Save settings'),
				'health_button' => $this->l10n->t('Health check'),
				'consistency_button' => $this->l10n->t('Consistency check'),
				'retry_pending_button' => $this->l10n->t('Retry pending deletes now'),
				'pending_delete_label' => $this->l10n->t('Pending Etherpad deletes'),
				'saving' => $this->l10n->t('Saving settings...'),
				'saved' => $this->l10n->t('Settings saved.'),
				'checking' => $this->l10n->t('Running health check...'),
				'consistency_running' => $this->l10n->t('Running consistency check...'),
				'health_ok' => $this->l10n->t('Health check successful.'),
				'consistency_ok' => $this->l10n->t('Consistency check successful.'),
				'request_failed' => $this->l10n->t('Request failed.'),
				'saving_failed' => $this->l10n->t('Failed to save settings.'),
				'health_failed' => $this->l10n->t('Health check failed.'),
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

	private function deriveCookieDomainFromKnownHosts(string $nextcloudUrl, string $etherpadUrl): string {
		$nextcloudHost = $this->extractHost($nextcloudUrl);
		$etherpadHost = $this->extractHost($etherpadUrl);
		if ($nextcloudHost === '' || $etherpadHost === '' || $nextcloudHost === $etherpadHost) {
			return '';
		}
		if ($this->isHostUnsuitableForDomainCookie($nextcloudHost) || $this->isHostUnsuitableForDomainCookie($etherpadHost)) {
			return '';
		}

		$nextcloudLabels = array_reverse(explode('.', $nextcloudHost));
		$etherpadLabels = array_reverse(explode('.', $etherpadHost));
		$common = [];
		$limit = min(count($nextcloudLabels), count($etherpadLabels));
		for ($i = 0; $i < $limit; $i++) {
			if ($nextcloudLabels[$i] !== $etherpadLabels[$i]) {
				break;
			}
			$common[] = $nextcloudLabels[$i];
		}

		$common = array_reverse($common);
		if (count($common) < 2 || $this->looksLikeTwoLabelPublicSuffix($common)) {
			return '';
		}

		return '.' . implode('.', $common);
	}

	private function extractHost(string $urlOrHost): string {
		$value = strtolower(trim($urlOrHost));
		if ($value === '') {
			return '';
		}
		$host = parse_url($value, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			$host = preg_replace('/:\d+$/', '', $value) ?? '';
		}
		$host = trim(strtolower($host), "[] \t\n\r\0\x0B.");
		return $this->isValidCookieHost($host) ? $host : '';
	}

	private function isValidCookieHost(string $host): bool {
		if ($host === '' || strlen($host) > 253) {
			return false;
		}
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return true;
		}
		foreach (explode('.', $host) as $label) {
			if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
				return false;
			}
		}
		return true;
	}

	private function isHostUnsuitableForDomainCookie(string $host): bool {
		return filter_var($host, FILTER_VALIDATE_IP) !== false
			|| $host === 'localhost'
			|| str_ends_with($host, '.localhost')
			|| !str_contains($host, '.');
	}

	/** @param list<string> $commonLabels */
	private function looksLikeTwoLabelPublicSuffix(array $commonLabels): bool {
		if (count($commonLabels) !== 2 || strlen($commonLabels[1]) !== 2) {
			return false;
		}
		return in_array($commonLabels[0], ['ac', 'co', 'com', 'edu', 'gov', 'net', 'org'], true);
	}
}
