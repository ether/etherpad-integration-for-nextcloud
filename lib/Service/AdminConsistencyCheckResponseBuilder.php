<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\IL10N;

class AdminConsistencyCheckResponseBuilder {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	public function build(array $result): array {
		$issues = (int)$result['binding_without_file_count'];
		$brakeEngaged = (bool)$result['gone_file_brake_engaged'];
		$message = match (true) {
			$brakeEngaged => $this->l10n->t('Many .pad files went missing at once without passing a trash. Their pads are kept until the brake is released.'),
			$issues > 0 => $this->l10n->t('Consistency check finished with issues.'),
			default => $this->l10n->t('Consistency check successful. No issues found.'),
		};

		return [
			'ok' => true,
			'message' => $message,
			'binding_without_file_count' => $issues,
			'missing_file_count' => (int)$result['missing_file_count'],
			'gone_file_brake_engaged' => $brakeEngaged,
			'samples' => $result['samples'],
		];
	}
}
