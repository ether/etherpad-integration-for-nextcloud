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
		$message = $issues > 0
			? $this->l10n->t('Consistency check finished with issues.')
			: $this->l10n->t('Consistency check successful. No issues found.');

		return [
			'ok' => true,
			'message' => $message,
			'binding_without_file_count' => $issues,
			'samples' => $result['samples'],
		];
	}
}
