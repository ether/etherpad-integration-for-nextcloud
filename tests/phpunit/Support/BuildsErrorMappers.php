<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\Controller\PadControllerErrorMapper;
use OCA\EtherpadNextcloud\Controller\PublicViewerControllerErrorMapper;
use OCA\EtherpadNextcloud\Service\ApiErrorLog;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCP\ICacheFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The two error mappers as the container builds them, in one place: a
 * change to their constructors is made here, not in every controller test.
 * Their ApiErrorLog has no cache, so every Etherpad failure is a warning -
 * one call, one line.
 */
trait BuildsErrorMappers {
	private function padErrorMapper(PadResponseService $responses, IL10N $l10n, ?LoggerInterface $logger = null): PadControllerErrorMapper {
		return new PadControllerErrorMapper($responses, $l10n, $this->apiErrorLog($logger));
	}

	private function publicErrorMapper(PublicShareUrlBuilder $urls, PadResponseService $responses, IL10N $l10n, ?LoggerInterface $logger = null): PublicViewerControllerErrorMapper {
		return new PublicViewerControllerErrorMapper($urls, $responses, $l10n, $this->apiErrorLog($logger));
	}

	private function apiErrorLog(?LoggerInterface $logger = null): ApiErrorLog {
		return new ApiErrorLog($this->createMock(ICacheFactory::class), $logger ?? $this->createMock(LoggerInterface::class));
	}
}
