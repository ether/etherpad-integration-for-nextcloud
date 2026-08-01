<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Http\Client\IClientService;
use OCP\IL10N;

/**
 * Checks whether the browser-facing Etherpad base URL answers at all.
 *
 * Nothing else contacts this URL — the API calls go to the API host — so a
 * typo here stays invisible until a user opens a pad and lands nowhere.
 *
 * The result is never an error. Nextcloud legitimately may not reach the
 * public URL: split-horizon DNS, an egress firewall, or a proxy that only
 * serves outside traffic. Reporting that as a failure would cry wolf on
 * correctly configured instances, so an unreachable base URL is a warning
 * that says as much.
 *
 * @psalm-api
 */
class BaseUrlReachabilityCheck {
	private const TIMEOUT_SECONDS = 5;

	public function __construct(
		private IClientService $clientService,
		private IL10N $l10n,
	) {
	}

	public function check(string $baseUrl, string $apiHost): HealthCheckItem {
		$label = $this->l10n->t('Etherpad base URL reachable');
		$trimmed = trim($baseUrl);
		if ($trimmed === '') {
			return new HealthCheckItem('base_url', HealthCheckItem::STATUS_SKIPPED, $label, $this->l10n->t('No base URL configured.'));
		}
		if (rtrim($trimmed, '/') === rtrim(trim($apiHost), '/')) {
			return new HealthCheckItem('base_url', HealthCheckItem::STATUS_SKIPPED, $label, $this->l10n->t('Same as the API URL, already covered above.'));
		}

		try {
			$response = $this->clientService->newClient()->request('GET', $trimmed, [
				'timeout' => self::TIMEOUT_SECONDS,
				// Etherpad redirects / to /p/... on some setups, and a proxy may
				// redirect http to https. Following a couple is normal here.
				'allow_redirects' => ['max' => 3],
				'nextcloud' => ['allow_local_address' => true],
			]);
			$status = $response->getStatusCode();
		} catch (\Throwable $e) {
			return new HealthCheckItem(
				'base_url',
				HealthCheckItem::STATUS_WARNING,
				$label,
				$this->fill(
					$this->l10n->t('{url} did not answer: {error}. If Nextcloud cannot reach the public URL by design, ignore this — but check the URL for typos, because pad links in the browser use it.'),
					['url' => $trimmed, 'error' => $this->shorten($e->getMessage())],
				),
			);
		}

		// Anything the server answers means the host exists and serves. Even
		// 401/403/404 tell us the name resolves and something is listening,
		// which is what this row is about.
		if ($status >= 500) {
			return new HealthCheckItem(
				'base_url',
				HealthCheckItem::STATUS_WARNING,
				$label,
				$this->fill($this->l10n->t('{url} answered with HTTP {status}.'), ['url' => $trimmed, 'status' => (string)$status]),
			);
		}

		return new HealthCheckItem('base_url', HealthCheckItem::STATUS_OK, $label, $trimmed);
	}

	private function shorten(string $message): string {
		$message = trim($message);
		return strlen($message) > 160 ? substr($message, 0, 160) . '…' : $message;
	}

	/** @param array<string,string> $parameters */
	private function fill(string $text, array $parameters): string {
		foreach ($parameters as $key => $value) {
			$text = str_replace('{' . $key . '}', $value, $text);
		}
		return $text;
	}
}
