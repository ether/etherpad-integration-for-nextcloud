<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Re-stores an already-configured Etherpad API key with the `sensitive`
 * flag so existing installs get the redaction (in `occ config:list` and
 * support dumps) without the admin having to re-save the settings.
 *
 * New writes already set the flag via
 * {@see AdminSettingsRepository::persist()}; this only backfills the flag
 * onto the value installs stored before that change. Idempotent: writing
 * the same value with `sensitive: true` is a no-op once the flag is set.
 * @psalm-api
 */
class MarkApiKeySensitive implements IRepairStep {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getName(): string {
		return 'Mark the Etherpad API key as sensitive app config';
	}

	public function run(IOutput $output): void {
		$value = $this->appConfig->getValueString(Application::APP_ID, AdminSettingsRepository::API_KEY, '');
		if ($value === '') {
			$output->info('No Etherpad API key stored; nothing to mark sensitive.');
			return;
		}

		try {
			$this->appConfig->setValueString(
				Application::APP_ID,
				AdminSettingsRepository::API_KEY,
				$value,
				sensitive: true,
			);
		} catch (\Throwable $e) {
			// Rethrown without the cause on purpose. A repair step runs
			// outside anything of ours that catches, so its failure is
			// serialized by Nextcloud - and the frame that failed holds the
			// key as an argument, because setValueString takes a string.
			// What the chain said still fits in the message, which carries
			// no frames and loses the key on the way.
			throw new \RuntimeException(
				'Could not mark the Etherpad API key as sensitive: '
				. get_class($e) . ' - ' . SafeError::originOf($e, [$value]),
			);
		}
		$output->info('Marked the stored Etherpad API key as sensitive.');
	}
}
