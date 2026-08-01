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
	private const FIELD = 'etherpad_host';

	public function __construct(
		private IClientService $clientService,
		private IL10N $l10n,
	) {
	}

	public function check(string $baseUrl, string $apiHost): HealthCheckItem {
		$label = $this->l10n->t('Etherpad base URL reachable');
		// The validator rejects an empty base URL, so only the shared-address
		// case is skipped here. It claims no field: with no separate API URL
		// the API line already speaks for that input, and two verdicts cannot
		// share one slot.
		$trimmed = trim($baseUrl);
		if (rtrim($trimmed, '/') === rtrim(trim($apiHost), '/')) {
			return new HealthCheckItem('base_url', HealthCheckItem::STATUS_SKIPPED, $label, $this->l10n->t('Same as the API URL, already checked.'));
		}

		$client = $this->clientService->newClient();
		try {
			$response = $client->request('GET', $trimmed, [
				'timeout' => self::TIMEOUT_SECONDS,
				// Etherpad redirects / to /p/... on some setups, and a proxy may
				// redirect http to https. Following a couple is normal here.
				'allow_redirects' => ['max' => 3],
				'nextcloud' => ['allow_local_address' => true],
			]);
		} catch (\Throwable $e) {
			// The Nextcloud client throws on >= 400, so recover the real
			// response: a 404 still proves the host answers, which is all this
			// row claims. Only a throwable without one is a transport failure,
			// and then the original error is the useful one to report.
			try {
				$response = $client->getResponseFromThrowable($e);
			} catch (\Throwable) {
				return new HealthCheckItem(
					'base_url',
					HealthCheckItem::STATUS_WARNING,
					$label,
					$this->fill(
						$this->l10n->t('{url} did not answer: {error}. If Nextcloud cannot reach the public URL by design, ignore this — but check the URL for typos, because pad links in the browser use it.'),
						['url' => $trimmed, 'error' => $this->shorten($e->getMessage())],
					),
					self::FIELD,
				);
			}
		}
		$status = $response->getStatusCode();

		// A host that answers is not automatically a working base URL: point it
		// at the wrong path and every pad link built from it 404s.
		if ($status >= 400) {
			return new HealthCheckItem(
				'base_url',
				HealthCheckItem::STATUS_WARNING,
				$label,
				$this->fill($this->l10n->t('{url} answered with HTTP {status}.'), ['url' => $trimmed, 'status' => (string)$status]),
				self::FIELD,
			);
		}

		return new HealthCheckItem('base_url', HealthCheckItem::STATUS_OK, $label, $trimmed, self::FIELD);
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
