<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
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
 * Unlike the API host, this URL gets no local-address exemption. That
 * exemption is what would turn an admin-supplied address into a probe into
 * the server's own network. Losing it costs little: a blocked address is
 * reported as unverified rather than as broken, since an internal or
 * split-DNS deployment may still serve it to users. Redirects are
 * disabled on top — Nextcloud validates redirect targets too while the local
 * protection is on, but this check has no use for the target, so not
 * following one is simply less surface.
 *
 * The protection is Nextcloud's, so an instance running with
 * `allow_local_remote_servers=true` disables it for every request including
 * this one.
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

	public function check(string $baseUrl): HealthCheckItem {
		$label = $this->l10n->t('Etherpad base URL reachable');
		// Checked even when it matches the API URL. The API call may well
		// succeed against a loopback address, which says nothing about whether
		// a browser can open a pad there — skipping would hide exactly the
		// case the strict policy exists for.
		$trimmed = trim($baseUrl);

		$client = $this->clientService->newClient();
		try {
			$response = $client->request('GET', $trimmed, [
				'timeout' => self::TIMEOUT_SECONDS,
				// Not followed, matching EtherpadClient: a 3xx already proves
				// something is listening, so the target is of no use here.
				'allow_redirects' => ['max' => 0],
				// Only the status code is wanted, so the body is never
				// buffered: a hostile host could otherwise send as much as it
				// manages within the timeout.
				'stream' => true,
			]);
		} catch (LocalServerException $e) {
			// Blocked before anything left the server, so this says nothing
			// about the address itself: on an internal or split-DNS
			// deployment the users' browsers may well reach it. Only the
			// generic "did not answer" would be wrong here — it would send the
			// admin hunting a network fault that does not exist.
			return new HealthCheckItem(
				'base_url',
				HealthCheckItem::STATUS_WARNING,
				$label,
				$this->fill(
					$this->l10n->t('{url} was not contacted: Nextcloud blocks requests into its own network. Check that your users\' browsers can reach this address.'),
					['url' => $trimmed],
				),
				self::FIELD,
			);
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
		// at the wrong path and every pad link built from it 404s. A 3xx is
		// fine — Etherpad redirects / on some setups.
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
